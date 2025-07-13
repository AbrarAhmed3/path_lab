<?php
ob_start();
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'admin_header.php';
include 'db.php';

// ─── ONE-TIME: Ensure this column exists:
// ALTER TABLE test_assignments ADD COLUMN sort_order INT NOT NULL DEFAULT 0;

// Fetch patients
$patients = $conn->query("SELECT patient_id, name FROM patients");

// Fetch doctors
$doctors = $conn->query("SELECT doctor_id, name FROM doctors");

$patient_id = intval($_GET['patient_id'] ?? 0);
$billing_id = intval($_GET['billing_id'] ?? 0);

// Only validate if both are selected
if ($patient_id > 0 && $billing_id > 0) {
    $check_sql = "SELECT COUNT(*) AS cnt FROM billing WHERE billing_id = ? AND patient_id = ?";
    $stmt = $conn->prepare($check_sql);
    $stmt->bind_param("ii", $billing_id, $patient_id);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    if ($count === 0) {
        header("Location: assign_test.php?patient_id=$patient_id");
        exit();
    }
}

// Group tests by category (including Uncategorized)
$testGroups = [];
$rs = $conn->query("
  SELECT 
      t.test_id, 
      t.name           AS test_name,
      COALESCE(c.category_name, 'Uncategorized') AS category_name,
      COALESCE(ct.sort_order,0) AS sort_order
  FROM tests t
  LEFT JOIN category_tests ct 
    ON t.test_id     = ct.test_id
  LEFT JOIN test_categories c 
    ON ct.category_id = c.category_id
  ORDER BY 
    c.category_name,
    ct.sort_order,
    t.name
");

while ($r = $rs->fetch_assoc()) {
    $testGroups[$r['category_name']][] = $r;
}

$assigned_tests  = [];
$current_billing = null;
$bstatus         = null;

// Load billing info + assigned tests in sort_order
if ($billing_id) {
    // Billing row
    $stmt = $conn->prepare("
        SELECT b.*, p.name AS patient_name 
          FROM billing b 
          JOIN patients p ON b.patient_id = p.patient_id 
         WHERE b.billing_id = ?
    ");
    $stmt->bind_param("i", $billing_id);
    $stmt->execute();
    $current_billing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $bstatus = $current_billing['bstatus'] ?? 'pending';

    // Assigned tests ordered by sort_order, with profile info
    $tests_stmt = $conn->prepare("
  SELECT
    ta.test_id,
    t.name           AS test_name,
    ta.assigned_via_profile,
    COALESCE(c.category_name,'') AS category_name,
    ct.sort_order    AS default_order
  FROM test_assignments ta
  JOIN tests t  
    ON t.test_id = ta.test_id
  LEFT JOIN test_categories c 
    ON ta.category_id = c.category_id
  LEFT JOIN category_tests ct 
    ON ct.category_id = ta.category_id
   AND ct.test_id     = ta.test_id
  WHERE ta.billing_id = ?
  ORDER BY 
    -- first by your “master” category_tests order,
    ct.sort_order     ASC,
    -- then by any manual re-ordering in test_assignments
    ta.sort_order     ASC
");

    $tests_stmt->bind_param("i", $billing_id);
    $tests_stmt->execute();
    $res = $tests_stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $assigned_tests[] = $row;
    }
    $tests_stmt->close();
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $patient_id       = intval($_POST['patient_id']);
    $billing_id       = intval($_POST['billing_id'] ?? 0);
    $action           = $_POST['action'] ?? '';
    $referred_by      = is_numeric($_POST['referred_by'] ?? '') ? intval($_POST['referred_by']) : null;
    $individual_tests = $_POST['tests_individual'] ?? [];
    $profile_tests    = $_POST['tests_profile']    ?? [];
    $category_ids     = $_POST['category_ids']     ?? [];

    // 1) Handle reordering
    if ($action === 'reorder_tests' || isset($_POST['test_order'])) {
        $ids = array_filter(explode(',', $_POST['test_order'] ?? ''));
        $upd = $conn->prepare("
            UPDATE test_assignments
               SET sort_order = ?
             WHERE billing_id = ? AND test_id = ?
        ");
        $order = 1;
        foreach ($ids as $tid) {
            $upd->bind_param("iii", $order, $billing_id, $tid);
            $upd->execute();
            $order++;
        }
        $upd->close();
        header("Location: assign_test.php?patient_id=$patient_id&billing_id=$billing_id");
        exit();
    }

// 2) New visit logic
if ($action === 'new_visit') {
    // Insert billing
    if ($referred_by !== null) {
        $stmt = $conn->prepare("
            INSERT INTO billing
              (patient_id, total_amount, paid_amount, balance_amount, bstatus, visit_note, referred_by)
            VALUES (?, 0, 0, 0, 'pending', 'New visit', ?)
        ");
        $stmt->bind_param("ii", $patient_id, $referred_by);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO billing
              (patient_id, total_amount, paid_amount, balance_amount, bstatus, visit_note, referred_by)
            VALUES (?, 0, 0, 0, 'pending', 'New visit', NULL)
        ");
        $stmt->bind_param("i", $patient_id);
    }
    $stmt->execute();
    $billing_id = $stmt->insert_id;
    $stmt->close();

    // **Redirect to load the new visit and show its status properly**
    header("Location: assign_test.php?patient_id={$patient_id}&billing_id={$billing_id}");
    exit();
}


    // 3) Assign tests with duplicate-prevention + sort_order
    if ($action === 'assign_tests') {
        // Fetch existing test_ids
        $existing = [];
        $exStmt = $conn->prepare("
          SELECT test_id
            FROM test_assignments
           WHERE billing_id = ?
        ");
        $exStmt->bind_param("i", $billing_id);
        $exStmt->execute();
        $exRes = $exStmt->get_result();
        while ($r = $exRes->fetch_assoc()) {
            $existing[] = (int)$r['test_id'];
        }
        $exStmt->close();

        // Combine UI order
        $all = [];
        foreach ($individual_tests as $tid) {
            $all[] = ['test_id' => (int)$tid, 'profile' => 0];
        }
        foreach ($profile_tests as $tid) {
            $all[] = ['test_id' => (int)$tid, 'profile' => 1];
        }

        // Filter out duplicates
        $to_insert = array_filter($all, fn($t) => !in_array($t['test_id'], $existing, true));

        if (empty($to_insert)) {
            // nothing new to insert
            header("Location: assign_test.php?patient_id=$patient_id&billing_id=$billing_id&duplicate=1");
            exit();
        }

        // Fetch current max sort_order
        $mxStmt = $conn->prepare("
            SELECT COALESCE(MAX(sort_order),0) AS max_sort
              FROM test_assignments
             WHERE billing_id = ?
        ");
        $mxStmt->bind_param("i", $billing_id);
        $mxStmt->execute();
        $mxRes = $mxStmt->get_result();
        $mxRow = $mxRes->fetch_assoc();
        $mxStmt->close();
        $order = ((int)$mxRow['max_sort']) + 1;

        // Insert each new test
        foreach ($to_insert as $test) {
            $tid = $test['test_id'];
            $via = $test['profile'];

            if ($via === 1) {
                // profile-based: find category
                $assignedCat = null;
                foreach ($category_ids as $c) {
                    $chk = $conn->prepare("
                      SELECT 1
                        FROM category_tests
                       WHERE category_id = ? AND test_id = ?
                    ");
                    $chk->bind_param("ii", $c, $tid);
                    $chk->execute();
                    $chk->store_result();
                    if ($chk->num_rows) {
                        $assignedCat = $c;
                        $chk->close();
                        break;
                    }
                    $chk->close();
                }
                $ins = $conn->prepare("
                    INSERT INTO test_assignments
                      (patient_id, billing_id, test_id, assigned_via_profile, category_id, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $ins->bind_param(
                    "iiiiii",
                    $patient_id,
                    $billing_id,
                    $tid,
                    $via,
                    $assignedCat,
                    $order
                );
            } else {
                // individual
                $ins = $conn->prepare("
                    INSERT INTO test_assignments
                      (patient_id, billing_id, test_id, assigned_via_profile, sort_order)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $ins->bind_param(
                    "iiiii",
                    $patient_id,
                    $billing_id,
                    $tid,
                    $via,
                    $order
                );
            }

            $ins->execute();
            $ins->close();
            $order++;
        }

        // Update referred_by
        if ($referred_by !== null) {
            $u = $conn->prepare("UPDATE billing SET referred_by = ? WHERE billing_id = ?");
            $u->bind_param("ii", $referred_by, $billing_id);
        } else {
            $u = $conn->prepare("UPDATE billing SET referred_by = NULL WHERE billing_id = ?");
            $u->bind_param("i", $billing_id);
        }
        $u->execute();
        $u->close();

        // Mark assigned
        $u2 = $conn->prepare("UPDATE billing SET bstatus = 'assigned' WHERE billing_id = ?");
        $u2->bind_param("i", $billing_id);
        $u2->execute();
        $u2->close();

        header("Location: assign_test.php?patient_id=$patient_id&billing_id=$billing_id&assigned=success");
        exit();
    }
}
?>



<!DOCTYPE html>
<html>

<head>
    <title>Assign Tests to Patient</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css" />

    <style>
        body {
            background-color: #f4f7fc;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
        }

        .card-header {
            background: linear-gradient(90deg, #28a745, #218838);
            padding: 1rem 1.25rem;
        }

        .card-header h4 {
            font-weight: 600;
            margin-bottom: 0;
        }

        .form-group label {
            font-weight: 600;
        }

        .form-control:focus,
        .select2-selection:focus {
            box-shadow: none;
            border-color: #007bff;
        }

        .select2-container--default .select2-selection--single,
        .select2-container--default .select2-selection--multiple {
            border-radius: 6px;
            padding: 6px 0;
        }

        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background-color: #007bff;
            color: white;
        }

        .btn-primary {
            background-color: #007bff;
            border: none;
            font-weight: 600;
        }

        .btn-primary:hover {
            background-color: #0056b3;
        }

        .btn-success {
            background-color: #28a745;
            border: none;
            font-weight: 600;
        }

        .btn-success:hover {
            background-color: #218838;
        }

        .btn-secondary {
            background-color: #6c757d;
            border: none;
        }

        .btn-secondary:hover {
            background-color: #5a6268;
        }

        .badge-draft,
        .badge-open,
        .badge-printed {
            padding: 0.5em 0.8em;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .badge-draft {
            background-color: #ffc107;
            color: #212529;
        }

        .badge-open {
            background-color: #28a745;
            color: white;
        }

        .badge-printed {
            background-color: #17a2b8;
            color: white;
        }

        ul.assigned-tests {
            padding-left: 18px;
            margin-top: 0.5rem;
            margin-bottom: 1.2rem;
        }

        ul.assigned-tests li {
            font-weight: 500;
            margin-bottom: 3px;
        }

        hr {
            margin: 1.5rem 0;
        }

        .form-check-label.badge {
            display: inline-block;
            margin: 4px 6px 4px 0;
            font-size: 0.95rem;
            border-radius: 20px;
        }

        /* make the whole list-item show a grab-hand */
        #assigned-tests .list-group-item,
        #assigned-tests-list li {
            cursor: grab;
        }

        /* while the user is actively clicking/dragging */
        #assigned-tests .list-group-item:active,
        #assigned-tests-list li:active {
            cursor: grabbing;
        }
    </style>
</head>

<body>

    <div class="container mt-5">
        <div class="card">
            <div class="card-header bg-success text-white">
                <h4 class="mb-0">🧪 Assign Tests to Patient</h4>
            </div>
            <div class="card-body">

                <form method="GET" class="form-row align-items-end">
                    <div class="form-group col-md-5">
                        <label>Select Patient</label>
                        <select name="patient_id" class="form-control js-select-patient" onchange="this.form.submit()" required>
                            <option value="">-- Select Patient --</option>
                            <?php while ($p = $patients->fetch_assoc()): ?>
                                <option value="<?= $p['patient_id'] ?>" <?= ($patient_id == $p['patient_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['name']) ?> (ID: <?= $p['patient_id'] ?>)
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <?php if ($patient_id): ?>
                        <div class="form-group col-md-5">
                            <label>Select Visit</label>
                            <select name="billing_id" class="form-control" onchange="this.form.submit()">
                                <option value="">-- Select Visit --</option>
                                <?php
                                $visits = $conn->query("SELECT billing_id, billing_date, bstatus FROM billing WHERE patient_id = $patient_id ORDER BY billing_date DESC");
                                while ($v = $visits->fetch_assoc()):
                                    $badge = match ($v['bstatus']) {
                                        'pending' => '🟡',
                                        'assigned' => '🧪',
                                        'paid' => '💳',
                                        default => '❓'
                                    };


                                ?>
                                    <option value="<?= $v['billing_id'] ?>" <?= ($billing_id == $v['billing_id']) ? 'selected' : '' ?>>
                                        <?= $badge ?> Visit <?= $v['billing_id'] ?> - <?= date('d-m-Y', strtotime($v['billing_date'])) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                </form>

                <hr>

                <?php if ($patient_id && !$billing_id): ?>
                    <form method="POST">
                        <input type="hidden" name="patient_id" value="<?= $patient_id ?>">
                        <div class="form-group">
                            <label>Referred By</label>
                            <select name="referred_by" class="form-control js-select-doctor" required>
                                <option value="">-- Select Doctor --</option>
                                <?php mysqli_data_seek($doctors, 0);
                                while ($d = $doctors->fetch_assoc()): ?>
                                    <option value="<?= $d['doctor_id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <button type="submit" name="action" value="new_visit" class="btn btn-primary">➕ Start New Visit</button>
                    </form>
                <?php elseif ($billing_id && $current_billing): ?>
                    <h5>
                        Status:
                        <span class="badge <?= $bstatus === 'pending' ? 'badge-draft' : ($bstatus === 'assigned' ? 'badge-open' : 'badge-printed') ?>">
                            <?= ucfirst($bstatus) ?>
                        </span>

                    </h5>

                    <h6 class="mt-4"><i class="fas fa-arrows-alt"></i>Manage Tests</h6>
                    <div class="row">
                        <!-- Left: reorder -->
                        <div class="col-lg-6">
                            <?php if (in_array($bstatus, ['pending', 'assigned'])): ?>
                                <form method="POST">
                                    <input type="hidden" name="action" value="assign_tests">
                                    <input type="hidden" name="patient_id" value="<?= $patient_id ?>">
                                    <input type="hidden" name="billing_id" value="<?= $billing_id ?>">

                                    <div class="form-group">
                                        <label>Referred By</label>
                                        <select name="referred_by" class="form-control js-select-doctor" required>
                                            <option value="">-- Select Doctor --</option>
                                            <?php mysqli_data_seek($doctors, 0);
                                            while ($d = $doctors->fetch_assoc()): ?>
                                                <option value="<?= $d['doctor_id'] ?>"
                                                    <?= $current_billing['referred_by'] == $d['doctor_id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($d['name']) ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label>Select Test Profiles (Categories)</label>
                                        <select name="category_ids[]" id="category_select"
                                            class="form-control js-select-category" multiple>
                                            <?php
                                            $cats = $conn->query("SELECT * FROM test_categories");
                                            while ($c = $cats->fetch_assoc()):
                                            ?>
                                                <option value="<?= $c['category_id'] ?>">
                                                    <?= htmlspecialchars($c['category_name']) ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>

                                    <div id="category_test_list" class="mb-4">
                                        <!-- AJAX-loaded profile tests… -->
                                    </div>

                                    <?php
                                    // Before your <select>, build a simple array of assigned IDs:
                                    $assignedIds = array_map(
                                        fn($r) => (int)$r['test_id'],
                                        $assigned_tests
                                    );
                                    ?>

                                    <div class="form-group">
                                        <label>Add More Tests</label>
                                        <select name="tests_individual[]" class="form-control js-select-tests" multiple>
                                            <?php foreach ($testGroups as $category => $tests): ?>
                                                <optgroup label="<?= htmlspecialchars($category) ?>">
                                                    <?php foreach ($tests as $t):
                                                        $tid     = (int)$t['test_id'];
                                                        $disabled = in_array($tid, $assignedIds, true) ? 'disabled style="color:#ccc;"' : '';
                                                    ?>
                                                        <option value="<?= $tid ?>" <?= $disabled ?>>
                                                            <?= htmlspecialchars($t['test_name']) ?>
                                                            <?php if ($disabled): ?>(already assigned)<?php endif; ?>
                                                        </option>
                                                    <?php endforeach; ?>

                                                </optgroup>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>


                                    <div class="text-right">
                                        <button type="submit" class="btn btn-success">
                                            <i class="fas fa-plus mr-1"></i> Assign Tests
                                        </button>
                                        <a href="billing.php?patient_id=<?= $patient_id ?>&billing_id=<?= $billing_id ?>"
                                            class="btn btn-secondary ml-2">
                                            <i class="fas fa-credit-card mr-1"></i> Go to Billing
                                        </a>
                                    </div>
                                </form>
                            <?php else: ?>
                                <form method="POST">
                                    <input type="hidden" name="patient_id" value="<?= $patient_id ?>">
                                    <button type="submit" name="action" value="new_visit" class="btn btn-primary">
                                        ➕ Start Fresh Visit
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <!-- Right: assign more -->
                        <?php
                        // 1) After loading your assigned tests into $assigned_tests (each row having
                        //    ['test_id'], ['test_name'], ['category_name'], ['sort_order'], etc),
                        //    regroup them by category:

                        $assigned_by_cat = [];
                        foreach ($assigned_tests as $row) {
                            $cat = $row['category_name'] ?: 'Individual tests';
                            $assigned_by_cat[$cat][] = $row;
                        }
                        ?>

                        <div class="col-lg-6 mb-4">
                            <form id="reorderForm" method="POST">
                                <input type="hidden" name="action" value="reorder_tests">
                                <input type="hidden" name="patient_id" value="<?= $patient_id ?>">
                                <input type="hidden" name="billing_id" value="<?= $billing_id ?>">
                                <input type="hidden" name="test_order" id="test_order" value="">

                                <div class="card">
                                    <div class="card-header bg-info text-white">
                                        <i class="fas fa-list-ol mr-2"></i>
                                        Drag to Reorder Tests
                                    </div>
                                    <ul
                                        id="assigned-tests"
                                        class="list-group list-group-flush connectedSortable"
                                        style="max-height:300px; overflow-y:auto;">
                                        <?php if (empty($assigned_by_cat)): ?>
                                            <li class="list-group-item text-center text-muted">
                                                No tests assigned.
                                            </li>
                                        <?php else: ?>
                                            <?php foreach ($assigned_by_cat as $category => $tests): ?>
                                                <!-- Category Header (not draggable) -->
                                                <li class="list-group-item bg-light font-weight-bold category-header">
                                                    <?= htmlspecialchars($category) ?>
                                                </li>

                                                <!-- Individual tests (draggable) -->
                                                <?php foreach ($tests as $row): ?>
                                                    <li
                                                        class="list-group-item test-item d-flex align-items-center"
                                                        data-test-id="<?= $row['test_id'] ?>">
                                                        <span class="drag-handle mr-3 text-muted">
                                                            <i class="fas fa-grip-vertical"></i>
                                                        </span>
                                                        <span class="flex-grow-1">
                                                            <?= htmlspecialchars($row['test_name']) ?>
                                                        </span>
                                                    </li>
                                                <?php endforeach; ?>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </ul>
                                </div><!-- /.card -->

                                <div class="text-right mt-2">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save mr-1"></i> Save Test Order
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>

    <script>
        $(document).ready(function() {
            $('.js-select-patient').select2({
                placeholder: "-- Select Patient --"
            });
            $('.js-select-tests').select2({
                placeholder: "Select tests to assign",
                width: '100%',
                dropdownAutoWidth: true
            });
            $('.js-select-doctor').select2({
                placeholder: "Select referring doctor",
                width: '100%'
            });

            $('.js-select-tests').on('select2:open', function() {
                setTimeout(() => {
                    $('.select2-results__group').off('mousedown').on('mousedown', function(e) {
                        e.preventDefault();
                        let categoryLabel = $(this).text().trim();
                        let selectEl = $('.js-select-tests');
                        let selected = selectEl.val() || [];

                        selectEl.find('optgroup[label="' + categoryLabel + '"] option').each(function() {
                            let val = $(this).val();
                            if (!selected.includes(val)) {
                                selected.push(val);
                            }
                        });

                        selectEl.val(selected).trigger('change');
                    });
                }, 0);
            });
        });
    </script>
    <script>
        $(function() {
            // Only make the .test-item rows draggable
            $('#assigned-tests').sortable({
                items: '.test-item',
                placeholder: 'ui-state-highlight'
            }).disableSelection();


            // Serialize the new order before submitting
            $('#reorderForm').on('submit', function() {
                const order = $('#assigned-tests .test-item')
                    .map((i, el) => $(el).data('test-id'))
                    .get()
                    .join(',');
                $('#test_order').val(order);
            });
        });
    </script>

    <script>
        $(document).ready(function() {
            $('#category_select').select2({
                placeholder: "Select profiles/categories"
            });

            $('.js-select-tests').select2({
                placeholder: "Select individual tests",
                width: '100%'
            });

            $('#patient_id').on('change', function() {
                const patientId = $(this).val();

                // Clear and reload billing options
                $('#billing_id').html('<option value="">Select Bill</option>');

                if (patientId) {
                    $.ajax({
                        url: 'fetch_billing_ids.php',
                        type: 'POST',
                        data: {
                            patient_id: patientId
                        },
                        success: function(data) {
                            $('#billing_id').html(data);
                        }
                    });
                }

                // Optional: Clear any previously loaded test lists
                $('#category_test_list').html('');
                $('#individual_test_list').html('');
            });


            $('#category_select').on('change', function() {
                let selectedCategories = $(this).val();
                $.ajax({
                    url: 'fetch_tests_by_category_ids.php',
                    method: 'POST',
                    data: {
                        category_ids: selectedCategories
                    },
                    success: function(response) {
                        $('#category_test_list').html(response.html);

                        const hiddenTests = response.test_ids.map(String);

                        $('.js-select-tests option').each(function() {
                            if (hiddenTests.includes($(this).val())) {
                                $(this).prop('disabled', true).hide();
                            } else {
                                $(this).prop('disabled', false).show();
                            }
                        });

                        $('.js-select-tests').select2(); // refresh

                        if ($('.js-select-tests option:visible').length === 0) {
                            $('#category_test_list').append("<div class='alert alert-warning mt-3'>All tests in the selected categories are already assigned or unavailable.</div>");
                        }
                    }
                });
            });

        });
    </script>


    <?php if (isset($_GET['assigned']) && $_GET['assigned'] === 'success'): ?>
        <script>
            Swal.fire({
                icon: 'success',
                title: 'Tests Assigned Successfully!',
                showConfirmButton: false,
                timer: 1800
            });
        </script>
    <?php endif; ?>

</body>

</html>

<?php
include 'admin_footer.php';
ob_end_flush();
?>
<?php
ob_start();
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'admin_header.php';
include 'db.php';

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

// Group tests by category (including unmapped/uncategorized)
$testGroups = [];

$rs = $conn->query("
    SELECT 
        t.test_id, 
        t.name AS test_name, 
        COALESCE(c.category_name, 'Uncategorized') AS category_name
    FROM tests t
    LEFT JOIN category_tests ct ON t.test_id = ct.test_id
    LEFT JOIN test_categories c ON ct.category_id = c.category_id
    ORDER BY category_name, test_name
");

while ($r = $rs->fetch_assoc()) {
    $testGroups[$r['category_name']][] = $r;
}


$assigned_tests = [];
$current_billing = null;
$bstatus = null;

// Load billing info
if ($billing_id) {
    $stmt = $conn->prepare("SELECT billing.*, p.name FROM billing JOIN patients p ON billing.patient_id = p.patient_id WHERE billing.billing_id = ?");
    $stmt->bind_param("i", $billing_id);
    $stmt->execute();
    $current_billing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $bstatus = $current_billing['bstatus'] ?? 'pending';

    $tests_stmt = $conn->prepare("SELECT ta.test_id, t.name FROM test_assignments ta JOIN tests t ON t.test_id = ta.test_id WHERE ta.billing_id = ?");
    $tests_stmt->bind_param("i", $billing_id);
    $tests_stmt->execute();
    $results = $tests_stmt->get_result();
    while ($row = $results->fetch_assoc()) {
        $assigned_tests[$row['test_id']] = $row['name'];
    }
    $tests_stmt->close();
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $category_ids   = $_POST['category_ids']   ?? [];
$individual     = $_POST['tests_individual'] ?? [];
$profile_tests  = $_POST['tests_profile']  ?? [];


    $patient_id = $_POST['patient_id'];
    $billing_id = $_POST['billing_id'] ?? null;
    $selected_tests = $_POST['tests'] ?? [];
    $referred_by = $_POST['referred_by'] ?? null;
    $action = $_POST['action'];

    if ($action === 'new_visit') {
        $stmt = $conn->prepare("INSERT INTO billing (patient_id, total_amount, paid_amount, balance_amount, bstatus, visit_note, referred_by) VALUES (?, 0, 0, 0, 'pending', 'New visit', ?)");
        $stmt->bind_param("ii", $patient_id, $referred_by);
        $stmt->execute();
        $billing_id = $stmt->insert_id;
    }

    if ($action === 'assign_tests') {

        $individual_tests = $_POST['tests_individual'] ?? [];
        $profile_tests    = $_POST['tests_profile'] ?? [];

        $all_tests = [];

        foreach ($individual_tests as $test_id) {
            $all_tests[] = ['test_id' => $test_id, 'profile' => 0];
        }
        foreach ($profile_tests as $test_id) {
            $all_tests[] = ['test_id' => $test_id, 'profile' => 1];
        }

        // Remove already assigned test_ids
        $already_assigned_ids = array_keys($assigned_tests);
        $filtered_tests = array_filter($all_tests, fn($t) => !in_array($t['test_id'], $already_assigned_ids));

        foreach ($filtered_tests as $test) {
    if ($test['profile'] == 1) {
        // figure out which selected category this test belongs to
        $assignedCat = null;
        foreach ($category_ids as $cat) {
            $chk = $conn->prepare(
              "SELECT 1 FROM category_tests 
                 WHERE category_id = ? AND test_id = ?"
            );
            $chk->bind_param("ii", $cat, $test['test_id']);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows) {
                $assignedCat = $cat;
                $chk->close();
                break;
            }
            $chk->close();
        }

        $stmt = $conn->prepare(
          "INSERT INTO test_assignments 
             (patient_id,billing_id,test_id,assigned_via_profile,category_id) 
           VALUES (?,?,?,?,?)"
        );
        $stmt->bind_param("iiiii",
          $patient_id,
          $billing_id,
          $test['test_id'],
          $test['profile'],
          $assignedCat
        );
    } else {
        // individual tests still go in without a category
        $stmt = $conn->prepare(
          "INSERT INTO test_assignments 
             (patient_id,billing_id,test_id,assigned_via_profile) 
           VALUES (?,?,?,?)"
        );
        $stmt->bind_param("iiii",
          $patient_id,
          $billing_id,
          $test['test_id'],
          $test['profile']
        );
    }
    $stmt->execute();
    $stmt->close();
}




        // Only allow assignment if billing status is still pending
        $check = $conn->prepare("SELECT bstatus FROM billing WHERE billing_id = ?");
        $check->bind_param("i", $billing_id);
        $check->execute();
        $check->bind_result($check_status);
        $check->fetch();
        $check->close();

        if ($check_status === 'paid') {
            die("Test assignment not allowed. Billing is already marked as 'paid'.");
        }




        // Update referred doctor
        $stmt = $conn->prepare("UPDATE billing SET referred_by = ? WHERE billing_id = ?");
        $stmt->bind_param("ii", $referred_by, $billing_id);
        $stmt->execute();

        // Update status to assigned
        $stmt = $conn->prepare("UPDATE billing SET bstatus = 'assigned' WHERE billing_id = ?");
        $stmt->bind_param("i", $billing_id);
        $stmt->execute();
    }

    header("Location: assign_test.php?patient_id=$patient_id&billing_id=$billing_id&assigned=success");
    exit;
}
?>


<!DOCTYPE html>
<html>

<head>
    <title>Assign Tests to Patient</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
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

                    <?php if (!empty($assigned_tests)): ?>
                        <h6 class="mt-3">✅ Already Assigned Tests <small class="text-muted">(<?= date('d-m-Y', strtotime($current_billing['billing_date'])) ?>)</small>:</h6>
                        <ul class="assigned-tests">
                            <?php foreach ($assigned_tests as $name): ?>
                                <li><span class="badge badge-success mr-2">✔</span> <?= htmlspecialchars($name) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if (in_array($bstatus, ['pending', 'assigned'])): ?>
                        <form method="POST">
                            <input type="hidden" name="patient_id" value="<?= $patient_id ?>">
                            <input type="hidden" name="billing_id" value="<?= $billing_id ?>">
                            <input type="hidden" name="action" value="assign_tests">

                            <div class="form-group">
                                <label>Referred By</label>
                                <select name="referred_by" class="form-control js-select-doctor" required>
                                    <option value="">-- Select Doctor --</option>
                                    <?php mysqli_data_seek($doctors, 0);
                                    while ($d = $doctors->fetch_assoc()): ?>
                                        <option value="<?= $d['doctor_id'] ?>" <?= ($current_billing['referred_by'] == $d['doctor_id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($d['name']) ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Select Test Profiles (Categories)</label>
                                <select name="category_ids[]" id="category_select" class="form-control js-select-category" multiple>

                                    <?php
                                    $categories = $conn->query("SELECT * FROM test_categories");
                                    while ($cat = $categories->fetch_assoc()) {
                                        echo "<option value='{$cat['category_id']}'>{$cat['category_name']}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div id="category_test_list" class="mb-4">
                                <!-- AJAX-loaded profile tests will appear here -->
                            </div>




                            <div class="form-group">
                                <label>Add More Tests</label>
                                <select name="tests_individual[]" class="form-control js-select-tests" multiple>
                                    <?php foreach ($testGroups as $category => $tests): ?>
                                        <optgroup label="<?= htmlspecialchars($category) ?>">
                                            <?php foreach ($tests as $test): ?>
                                                <?php if (!isset($assigned_tests[$test['test_id']])): ?>
                                                    <option value="<?= $test['test_id'] ?>"><?= $test['test_name'] ?></option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="text-right">
                                <button type="submit" class="btn btn-success">➕ Assign Tests</button>
                                <a href="billing.php?patient_id=<?= $patient_id ?>&billing_id=<?= $billing_id ?>" class="btn btn-secondary">💳 Go to Billing</a>
                            </div>
                        </form>
                    <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="patient_id" value="<?= $patient_id ?>">
                            <button type="submit" name="action" value="new_visit" class="btn btn-primary">➕ Start Fresh Visit</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
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
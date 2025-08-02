<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'admin_header.php';
include 'db.php';

// 1) Read incoming IDs
$patient_id = isset($_REQUEST['patient_id']) && is_numeric($_REQUEST['patient_id'])
    ? intval($_REQUEST['patient_id']) : 0;
$billing_id = isset($_REQUEST['billing_id']) && is_numeric($_REQUEST['billing_id'])
    ? intval($_REQUEST['billing_id']) : 0;

// 2) If only billing_id was passed, look up its patient_id
if ($billing_id > 0 && $patient_id === 0) {
    $stmt = $conn->prepare("SELECT patient_id FROM billing WHERE billing_id = ?");
    $stmt->bind_param("i", $billing_id);
    $stmt->execute();
    $stmt->bind_result($pid);
    if ($stmt->fetch()) {
        $patient_id = (int)$pid;
    }
    $stmt->close();
}

// validate mismatch
if( $patient_id > 0 && $billing_id > 0 ) {
    $stmt = $conn->prepare("
      SELECT 1 
        FROM billing 
       WHERE billing_id = ? 
         AND patient_id = ?
      LIMIT 1
    ");
    $stmt->bind_param("ii", $billing_id, $patient_id);
    $stmt->execute();
    $stmt->store_result();

    if( $stmt->num_rows === 0 ) {
        // clear it instead of redirect
        $billing_id = 0;
    }
    $stmt->close();
}

// 3) Fetch the patient’s name for preloading Select2
$selectedPatientName = '';
if ($patient_id > 0) {
    $stmt = $conn->prepare("SELECT name FROM patients WHERE patient_id = ?");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $stmt->bind_result($selectedPatientName);
    $stmt->fetch();
    $stmt->close();
}

// 4) Fetch report statuses
$fstatus = $gstatus = null;
if ($billing_id > 0) {
    $stmt = $conn->prepare("SELECT fstatus, gstatus FROM billing WHERE billing_id = ?");
    $stmt->bind_param("i", $billing_id);
    $stmt->execute();
    $stmt->bind_result($fstatus, $gstatus);
    $stmt->fetch();
    $stmt->close();
}
$is_finalized = ($fstatus === 'finalized');
$is_generated = ($gstatus === 'generated');


// 4) Fetch assigned tests for this billing
$tests_by_dept = [];
$tests_count_by_dept = [];

if ($billing_id > 0) {
    $stmt = $conn->prepare("
        SELECT ta.test_id, t.name AS test_name, t.description, d.department_name
        FROM test_assignments ta
        JOIN tests t ON ta.test_id = t.test_id
        JOIN departments d ON t.department_id = d.department_id
        WHERE ta.billing_id = ?
        ORDER BY d.department_name, t.name
    ");
    $stmt->bind_param("i", $billing_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $dept = $row['department_name'];
        $tests_by_dept[$dept][] = $row;
        if (!isset($tests_count_by_dept[$dept])) $tests_count_by_dept[$dept] = 0;
        $tests_count_by_dept[$dept]++;
    }
    $stmt->close();

    // Fetch selected test_ids
    $desc_checked = [];
    $sel = $conn->prepare("
        SELECT test_id FROM report_test_description_selection
        WHERE billing_id = ? AND show_description = 1
    ");
    $sel->bind_param("i", $billing_id);
    $sel->execute();
    $rs = $sel->get_result();
    while ($r = $rs->fetch_assoc()) $desc_checked[] = $r['test_id'];
    $sel->close();
}




// 5) Fetch lab doctors + prefill arrays
$lab_doctors = $conn->query("SELECT doctor_id, name FROM doctors WHERE is_lab_doctor = 1 ORDER BY name ASC");
$prefill_doctors = $prefill_treating = [];
if ($billing_id > 0) {
    $stmt = $conn->prepare("
      SELECT doctor_id, is_treating_doctor
        FROM report_lab_doctors
       WHERE billing_id = ?
    ");
    $stmt->bind_param("i", $billing_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $prefill_doctors[]    = (int)$r['doctor_id'];
        if ($r['is_treating_doctor']) {
            $prefill_treating[] = (int)$r['doctor_id'];
        }
    }
    $stmt->close();
}

// 6) Handle POST (save doctors, machines, statuses)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_generated) {
    $patient_id   = intval($_POST['patient_id']);
    $billing_id   = intval($_POST['billing_id']);
    $doctor_ids   = $_POST['doctor_ids']           ?? [];
    $treating_ids = $_POST['treating_doctor_ids'] ?? [];
    $machine_info = $_POST['machine_info']         ?? [];

    // clear old entries
    $conn->query("DELETE FROM report_lab_doctors  WHERE billing_id = $billing_id");
    $conn->query("DELETE FROM report_machine_info WHERE billing_id = $billing_id");

    // insert doctors
    $stmtD = $conn->prepare("
      INSERT INTO report_lab_doctors
        (billing_id, doctor_id, is_treating_doctor)
      VALUES (?,?,?)
    ");
    foreach ($doctor_ids as $did) {
        $did     = intval($did);
        $isTreat = in_array($did, $treating_ids) ? 1 : 0;
        $stmtD->bind_param("iii", $billing_id, $did, $isTreat);
        $stmtD->execute();
    }
    $stmtD->close();

    // insert machines
    $allFilled = true;
    $stmtM = $conn->prepare("
      INSERT INTO report_machine_info
        (billing_id, department_name, machine_name)
      VALUES (?,?,?)
    ");
    foreach ($machine_info as $dept => $mname) {
        $mname = trim($mname);
        if ($mname === '') {
            $allFilled = false;
            continue;
        }
        $stmtM->bind_param("iss", $billing_id, $dept, $mname);
        $stmtM->execute();
    }
    $stmtM->close();

 if (!$is_generated) {
    // Save which tests to show description
    $show_desc_tests = $_POST['show_desc_tests'] ?? [];
    $show_desc_tests = array_map('intval', $show_desc_tests);

    // Clear previous selections for this billing (use prepared statement)
    $stmtDel = $conn->prepare("DELETE FROM report_test_description_selection WHERE billing_id = ?");
    $stmtDel->bind_param("i", $billing_id);
    $stmtDel->execute();
    $stmtDel->close();

    // Insert new checked ones
    if (count($show_desc_tests)) {
        $stmtDesc = $conn->prepare("INSERT INTO report_test_description_selection (billing_id, test_id, show_description) VALUES (?, ?, 1)");
        foreach ($show_desc_tests as $tid) {
            $stmtDesc->bind_param("ii", $billing_id, $tid);
            $stmtDesc->execute();
        }
        $stmtDesc->close();
    }
}





    // update finalization status
    $newF = (count($doctor_ids) && $allFilled)
          ? 'finalized'
          : 'not_finalized';
    $stmtS = $conn->prepare("UPDATE billing SET fstatus = ?,finalized_on = NOW() WHERE billing_id = ?");
    $stmtS->bind_param("si", $newF, $billing_id);
    $stmtS->execute();
    $stmtS->close();

    // if finalized and not yet generated, mark ready
    if ($newF === 'finalized' && $gstatus === 'not_ready') {
        $conn->query("UPDATE billing SET gstatus = 'ready' WHERE billing_id = $billing_id");
    }

    // SweetAlert confirmation + redirect
    echo "<!DOCTYPE html><html><head>
            <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
          </head><body>
            <script>
              Swal.fire({
                icon: 'success',
                title: 'Saved Successfully!',
                text: 'Redirecting to report...',
                timer: 2000,
                showConfirmButton: false
              }).then(() => {
                window.location.href='generate_report.php?patient_id={$patient_id}&billing_id={$billing_id}';
              });
            </script>
          </body></html>";
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>Finalize Lab Report</title>
  <link rel="stylesheet"
        href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <link
    href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css"
    rel="stylesheet" />
  <style>
    .select2-container { z-index: 9999; }
  </style>
</head>
<body>
  <div class="container mt-5">
    <div class="card">
      <div class="card-header bg-primary text-white">
        <h4 class="mb-0">Finalize Report: Doctor &amp; Machine Info</h4>
      </div>
      <div class="card-body">

        <!-- Patient & Billing Selection -->
        <form id="selection-form" method="GET" class="form-inline mb-4">
          <label class="mr-2">Select Patient:</label>
          <select
            name="patient_id"
            id="patient-select"
            class="form-control mr-3"
            style="width:300px"
          >
            <?php if ($patient_id && $selectedPatientName): ?>
              <option value="<?= $patient_id ?>" selected>
                <?= htmlspecialchars($selectedPatientName) ?> (ID: <?= $patient_id ?>)
              </option>
            <?php endif; ?>
          </select>

          <label class="mr-2">Billing ID:</label>
          <select name="billing_id" id="billing-select" class="form-control mr-3">
            <option value="">📅 -- Select Billing --</option>
            <?php if ($patient_id):
              $stmt = $conn->prepare("
                SELECT billing_id, billing_date, fstatus, gstatus
                  FROM billing
                 WHERE patient_id = ?
                 ORDER BY billing_date DESC
              ");
              $stmt->bind_param("i", $patient_id);
              $stmt->execute();
              $billings = $stmt->get_result();
              while ($b = $billings->fetch_assoc()):
                $icons  = $b['fstatus']==='finalized' ? '✅ ' : '⚠️ ';
                $icons .= $b['gstatus']==='generated' ? '🔒 '
                         : ($b['gstatus']==='ready' ? '🔓 ' : '');
            ?>
              <option
                value="<?= $b['billing_id'] ?>"
                <?= $b['billing_id']==$billing_id?'selected':'' ?>
              >
                <?= $icons ?>Visit #<?= $b['billing_id'] ?> – 
                <?= date('d M Y',strtotime($b['billing_date'])) ?>
              </option>
            <?php
              endwhile;
              $stmt->close();
            endif; ?>
          </select>
        </form>

        <?php if ($billing_id): ?>
          <div class="mb-3">
            <strong>Report Status:</strong><br>
            <span class="badge <?= $is_finalized?'badge-success':'badge-warning' ?>">
              ✅ Finalization: <?= $fstatus==='finalized'?'Finalized':'Not Finalized' ?>
            </span>
            <span class="badge <?= $is_generated?'badge-dark':'badge-secondary' ?>">
              📄 Report: <?= $gstatus==='generated'
                ? 'Generated (Locked)'
                : ucfirst($gstatus?:'Not Ready') ?>
            </span>
          </div>

          <form method="POST">
            <input type="hidden" name="patient_id" value="<?= $patient_id ?>">
            <input type="hidden" name="billing_id" value="<?= $billing_id ?>">

            <div class="form-group">
              <label><strong>Select Lab Doctors</strong></label><br>
              <?php while ($doc = $lab_doctors->fetch_assoc()): ?>
                <div class="form-check form-check-inline mb-2">
                  <input
                    class="form-check-input"
                    type="checkbox"
                    name="doctor_ids[]"
                    id="doc_<?= $doc['doctor_id'] ?>"
                    value="<?= $doc['doctor_id'] ?>"
                    <?= in_array($doc['doctor_id'],$prefill_doctors)?'checked':'' ?>
                    <?= $is_generated?'disabled':'' ?>
                  >
                  <label class="form-check-label mr-3"
                         for="doc_<?= $doc['doctor_id'] ?>">
                    <?= htmlspecialchars($doc['name']) ?>
                  </label>
                  <label class="ml-2">
                    <input
                      type="checkbox"
                      name="treating_doctor_ids[]"
                      value="<?= $doc['doctor_id'] ?>"
                      <?= in_array($doc['doctor_id'],$prefill_treating)?'checked':'' ?>
                      <?= $is_generated?'disabled':'' ?>
                    >
                    <small class="text-muted">Treated</small>
                  </label>
                </div>
              <?php endwhile; ?>
            </div>

            <div class="form-group">
              <label><strong>Machines Used Per Department</strong></label>
              <div id="machinesSection" class="mt-2"></div>
            </div>


<?php if ($billing_id && count($tests_by_dept)): ?>
  <div class="form-group">
    <label><strong>Select Tests to Show Description (Departments with less than 5 tests)</strong></label>
    <?php foreach($tests_by_dept as $dept => $tests): ?>
      <?php if ($tests_count_by_dept[$dept] < 5): ?>
        <div class="card mb-2">
          <div class="card-header p-2 bg-light">
            <strong><?= htmlspecialchars($dept) ?></strong>
            <small class="text-muted">(<?= $tests_count_by_dept[$dept] ?> test<?= $tests_count_by_dept[$dept] == 1 ? '' : 's' ?>)</small>
          </div>
          <div class="card-body py-2 px-3">
            <?php foreach($tests as $test): ?>
              <div class="form-check mb-1">
                <input class="form-check-input"
                       type="checkbox"
                       name="show_desc_tests[]"
                       id="desc_test_<?= $test['test_id'] ?>"
                       value="<?= $test['test_id'] ?>"
                       <?= in_array($test['test_id'], $desc_checked) ? 'checked' : '' ?>
                       <?= $is_generated ? 'disabled' : '' ?>>
                <label class="form-check-label" for="desc_test_<?= $test['test_id'] ?>">
                  <?= htmlspecialchars($test['test_name']) ?>
                  <?php if($test['description']): ?>
                    <small class="text-muted"> — <?= htmlspecialchars($test['description']) ?></small>
                  <?php endif; ?>
                </label>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>




            <div class="text-right">
              <?php if (!$is_generated): ?>
                <button type="submit" class="btn btn-success">
                  💾 Save &amp; Finalize
                </button>
              <?php endif; ?>
              <a href="admin_dashboard.php" class="btn btn-secondary">Cancel</a>
            </div>
          </form>
        <?php endif; ?>

      </div>
    </div>
  </div>

  <!-- JS Dependencies -->
  <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
  <script
    src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js">
  </script>

  <script>
    const isGenerated = <?= $is_generated ? 'true' : 'false' ?>;

    function loadMachines(billingId) {
      if (!billingId) {
        $('#machinesSection')
          .html('<p class="text-muted">No departments.</p>');
        return;
      }
      $.getJSON('fetch_departments_for_billing.php', { billing_id: billingId })
        .done(resp => {
          const $sec = $('#machinesSection').empty();
          if (!resp.departments.length) {
            return $sec.append(
              '<p class="text-muted">No tests found for this visit.</p>'
            );
          }
          resp.departments.forEach(d => {
            const safe = $('<div>').text(d.machine_name||'').html();
            $sec.append(`
              <div class="form-group row mb-2">
                <label class="col-sm-3 col-form-label">
                  <strong>${d.department_name}</strong>
                </label>
                <div class="col-sm-9">
                  <input type="text"
                         name="machine_info[${d.department_name}]"
                         class="form-control"
                         placeholder="Enter machine"
                         value="${safe}">
                </div>
              </div>`);
          });
          if (isGenerated) {
            $sec.find('input').prop('readonly', true);
          }
        })
        .fail(() => {
          $('#machinesSection')
            .html('<p class="text-danger">Error loading departments.</p>');
        });
    }

    $(function(){
      // init Select2
      $('#patient-select').select2({
        placeholder: '-- Search Patient --',
        allowClear: true,
        ajax: {
          url: 'fetch_patients.php',
          dataType: 'json',
          delay: 250,
          data: params => ({ q: params.term }),
          processResults: data => ({ results: data.patients }),
          cache: true
        },
        minimumInputLength: 1
      });

      // when patient selected
      $('#patient-select').on('select2:select', () => {
        $('#selection-form').submit();
      });
      // when patient cleared
      $('#patient-select').on('select2:clear', () => {
        $('#billing-select').val('');
        $('#selection-form').submit();
      });

      // when billing changes
      $('#billing-select').on('change', () => {
        $('#selection-form').submit();
      });

      // on-load, if billing already set, fetch machines
      const initialBilling = $('#billing-select').val();
      if (initialBilling) {
        loadMachines(initialBilling);
      }
    });
  </script>

  <?php include 'admin_footer.php'; ?>
</body>
</html>



<!-- 1. first step : 
CREATE TABLE report_test_description_selection (
  billing_id INT,
  test_id INT,
  show_description TINYINT(1) DEFAULT 0,
  PRIMARY KEY (billing_id, test_id)
); -->


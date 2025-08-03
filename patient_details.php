<?php
ob_start();
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'db.php';
include 'admin_header.php';

$search = trim($_GET['search'] ?? '');
$patient = null;
$bills = [];
$test_history = [];

if ($search) {
    // Try search by ID first, then by name
    if (is_numeric($search)) {
        $stmt = $conn->prepare("SELECT * FROM patients WHERE patient_id = ?");
        $stmt->bind_param("i", $search);
    } else {
        $stmt = $conn->prepare("SELECT * FROM patients WHERE name LIKE ?");
        $like = "%$search%";
        $stmt->bind_param("s", $like);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $patient = $result->fetch_assoc();
    $stmt->close();

    if ($patient) {
        $pid = $patient['patient_id'];
        // All visits/bills
        $bills = $conn->query("SELECT * FROM billing WHERE patient_id = $pid ORDER BY billing_date DESC")->fetch_all(MYSQLI_ASSOC);

        // All tests ever done, with results if available
        $test_history = $conn->query("
            SELECT t.name AS test_name, ta.billing_id, b.billing_date, tr.result_value, tr.result_date
            FROM test_assignments ta
            JOIN tests t ON ta.test_id = t.test_id
            JOIN billing b ON ta.billing_id = b.billing_id
            LEFT JOIN test_results tr ON ta.assignment_id = tr.assignment_id
            WHERE b.patient_id = $pid
            ORDER BY b.billing_date DESC
        ")->fetch_all(MYSQLI_ASSOC);
    }
}

// Lab/clinic settings (for header/footer)
$lab_settings = $conn->query("SELECT * FROM lab_settings WHERE id = 1")->fetch_assoc();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Patient Details</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
      body { background: #f7fafc; }
      .colon-label td:first-child { white-space:nowrap; font-weight:bold; color:#222;}
      .colon-label td:nth-child(2) { width: 16px; text-align:right;}
      .table-sm th, .table-sm td { font-size:14px; }
      .info-box {background: #f3f8fe; border-radius: 7px; padding: 18px 22px 10px 22px; margin-bottom: 28px;}
      .card { box-shadow:0 2px 12px rgba(50,65,115,.08);}
      .badge-status {font-size:13px; font-weight:600; letter-spacing:.4px;}
      .badge-pending {background:#ffc107;color:#000;}
      .badge-assigned {background:#007bff;}
      .badge-paid {background:#28a745;}
      .badge-printed {background:#17a2b8;}
      .badge-secondary {background:#e0e0e0;color:#333;}

      /* -------- Enhanced Test Summary -------- */
      .test-summary-card {
          background: #f9fcff;
          border-radius: 12px;
          padding: 26px 22px 18px 32px;
          box-shadow: 0 2px 12px rgba(0, 80, 150, 0.07);
          margin-bottom: 30px;
          border: 1.5px solid #e5ecfa;
      }
      .test-summary-list {
          column-count: 2;
          column-gap: 42px;
          padding-left: 16px;
          margin-top: 8px;
      }
      @media (max-width: 900px) {
          .test-summary-list { column-count: 1; }
          .test-summary-card { padding: 18px 10px; }
      }
      .summary-key {
          color: #0176d0; font-weight: 500; letter-spacing:0.4px;
      }
      .summary-label {
          font-size: 15px;
          color: #444;
          font-weight: 500;
          width: 168px;
          display: inline-block;
      }
      .summary-value {
          font-weight: 700;
          color: #212121;
      }
      .summary-icon {
          font-size: 23px;
          vertical-align: middle;
          margin-right: 7px;
      }
      .badge-testcount {
          background: #f1f7ff;
          color: #2454ac;
          border-radius: 9px;
          font-size: 12px;
          font-weight:600;
          padding: 2px 9px;
          margin-left: 5px;
          letter-spacing: .4px;
      }
    </style>
</head>
<body>
<div class="container mt-4 mb-5">
    <div class="card">
        <div class="card-header bg-primary text-white d-flex align-items-center" style="min-height:54px;">
            <h4 class="mb-0" style="font-weight:600;">🔎 Patient Details</h4>
        </div>
        <div class="card-body">
            <form class="form-inline mb-3" method="GET" autocomplete="off">
                <input type="text" name="search" class="form-control mr-2" placeholder="Enter Patient Name or ID" value="<?= htmlspecialchars($search) ?>" required style="min-width:220px;">
                <button class="btn btn-primary">Search</button>
            </form>

            <?php if ($patient): ?>
                <!-- PATIENT INFO BOX -->
                <div class="info-box">
                  <table style="width:100%;">
                    <tr>
                      <!-- LEFT BLOCK -->
                      <td style="width:57%; vertical-align:top;">
                        <table class="colon-label" style="font-size:15px;">
                          <tr>
                            <td>Patient Name</td><td>:</td><td><?= htmlspecialchars($patient['name']) ?></td>
                          </tr>
                          <tr>
                            <td>Sex / Age</td><td>:</td><td><?= ucfirst($patient['gender']) ?> / <?= $patient['age'] ?></td>
                          </tr>
                          <tr>
                            <td>Mobile</td><td>:</td><td><?= htmlspecialchars($patient['contact'] ?? '-') ?></td>
                          </tr>
                          <tr>
                            <td style="vertical-align:top;">Address</td>
                            <td style="vertical-align:top;">:</td>
                            <td><?= nl2br(htmlspecialchars($patient['address'] ?? '-')) ?></td>
                          </tr>
                          <tr>
                            <td>Registered On</td><td>:</td>
                            <td>
                              <?php
                                echo (isset($patient['registered_at']) && $patient['registered_at'])
                                  ? date('d-m-Y', strtotime($patient['registered_at']))
                                  : '-';
                              ?>
                            </td>
                          </tr>
                        </table>
                      </td>
                      <!-- RIGHT BLOCK -->
                      <td style="vertical-align:top; text-align:left;">
                        <table class="colon-label" style="font-size:15px;">
                          <tr>
                            <td>Patient ID</td><td>:</td><td><?= $patient['patient_id'] ?></td>
                          </tr>
                          <tr>
                            <td>Visits</td><td>:</td><td><?= count($bills) ?></td>
                          </tr>
                        </table>
                      </td>
                    </tr>
                  </table>
                </div>

                <!-- BILL HISTORY -->
                <div class="mb-4">
                  <h5 class="text-primary" style="font-weight:600;"><span style="font-size:22px;">🗒️</span> Visit / Bill History</h5>
                  <div class="table-responsive">
                  <table class="table table-sm table-bordered bg-white">
                    <thead class="thead-light">
                      <tr>
                        <th>Bill No</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Discount</th>
                        <th>Paid</th>
                        <th>Due</th>
                        <th>Referred By</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach($bills as $b):
                        $doc = '-';
                        if (!empty($b['referred_by'])) {
                          $docRow = $conn->query("SELECT name FROM doctors WHERE doctor_id = {$b['referred_by']}")->fetch_assoc();
                          $doc = $docRow ? $docRow['name'] : '-';
                        }
                        $badge = 'badge-secondary';
                        if($b['bstatus']=='pending') $badge='badge-pending';
                        elseif($b['bstatus']=='assigned') $badge='badge-assigned';
                        elseif($b['bstatus']=='paid') $badge='badge-paid';
                        elseif($b['bstatus']=='printed') $badge='badge-printed';
                      ?>
                      <tr>
                        <td><?= 'HDC_' . $b['billing_id'] ?></td>
                        <td><?= date('d-m-Y', strtotime($b['billing_date'])) ?></td>
                        <td>
                          <span class="badge badge-status <?= $badge ?>">
                            <?= ucfirst($b['bstatus']) ?>
                          </span>
                        </td>
                        <td>₹<?= number_format($b['discount'],2) ?></td>
                        <td>₹<?= number_format($b['paid_amount'],2) ?></td>
                        <td><?= $b['balance_amount'] > 0 ? '₹'.number_format($b['balance_amount'],2) : '<span class="text-success font-weight-bold">All Paid</span>' ?></td>
                        <td><?= htmlspecialchars($doc) ?></td>
                      </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                  </div>
                </div>

                <!-- TEST SUMMARY ANALYTICS -->
                <?php
                // --- Smart summary block
                $total_tests = count($test_history);
                $unique_tests = [];
                $test_count = [];
                $last_test = null;
                $first_test = null;

                foreach ($test_history as $row) {
                    $unique_tests[$row['test_name']] = true;
                    $test_count[$row['test_name']] = ($test_count[$row['test_name']] ?? 0) + 1;
                    $d = $row['billing_date'];
                    if (!$last_test || $d > $last_test['date']) $last_test = ['date' => $d, 'name' => $row['test_name']];
                    if (!$first_test || $d < $first_test['date']) $first_test = ['date' => $d, 'name' => $row['test_name']];
                }

                $most_freq_test = '-';
                if ($test_count) {
                    arsort($test_count);
                    $top = array_keys($test_count)[0];
                    $most_freq_test = $top . ' (' . $test_count[$top] . ' time' . ($test_count[$top]>1?'s':'') . ')';
                }
                ?>
                <div class="test-summary-card">
                    <div class="row">
                        <!-- Left: Key stats -->
                        <div class="col-md-6 mb-2">
                            <h5 class="summary-key" style="margin-bottom:16px;">
                                <span class="summary-icon">📊</span> Test Summary
                            </h5>
                            <div>
                                <span class="summary-label">Total Tests Done</span>
                                <span class="summary-value"><?= $total_tests ?></span>
                            </div>
                            <div>
                                <span class="summary-label">Unique Tests Done</span>
                                <span class="summary-value"><?= count($unique_tests) ?></span>
                            </div>
                            <div>
                                <span class="summary-label">First Test Date</span>
                                <span class="summary-value">
                                    <?= $first_test ? date('d-m-Y', strtotime($first_test['date'])) : '-' ?>
                                    <?php if ($first_test): ?>
                                        <span class="text-muted" style="font-size:12.5px;">(<?= htmlspecialchars($first_test['name']) ?>)</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div>
                                <span class="summary-label">Last Test Taken</span>
                                <span class="summary-value">
                                    <?= $last_test ? date('d-m-Y', strtotime($last_test['date'])) : '-' ?>
                                    <?php if ($last_test): ?>
                                        <span class="text-muted" style="font-size:12.5px;">(<?= htmlspecialchars($last_test['name']) ?>)</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div>
                                <span class="summary-label">Most Frequent Test</span>
                                <span class="summary-value">
                                    <?= $most_freq_test !== '-' ? '<span class="text-primary">' . htmlspecialchars($most_freq_test) . '</span>' : '-' ?>
                                </span>
                            </div>
                        </div>
                        <!-- Right: Unique tests list -->
                        <div class="col-md-6 mb-2">
                            <h5 class="summary-key" style="margin-bottom:16px;">
                                <span class="summary-icon">🧾</span> All Unique Tests Ever Done:
                            </h5>
                            <ul class="test-summary-list">
                                <?php foreach(array_keys($unique_tests) as $tname): ?>
                                  <li style="margin-bottom:3px;">
                                    <?= htmlspecialchars($tname) ?>
                                    <span class="badge-testcount"><?= $test_count[$tname] ?> time<?= $test_count[$tname]>1?'s':'' ?></span>
                                  </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            <?php elseif ($search): ?>
                <div class="alert alert-danger">No patient found for "<b><?= htmlspecialchars($search) ?></b>".</div>
            <?php endif; ?>
        </div>
        <div class="card-footer text-center text-muted" style="font-size:13px;">
            <strong><?= htmlspecialchars($lab_settings['lab_name']) ?></strong> | <?= htmlspecialchars($lab_settings['address_line1'] . ' ' . $lab_settings['address_line2']) ?>, <?= htmlspecialchars($lab_settings['city']) ?>, <?= htmlspecialchars($lab_settings['state']) ?> - <?= htmlspecialchars($lab_settings['pincode']) ?> | Ph: <?= htmlspecialchars($lab_settings['phone']) ?>
        </div>
    </div>
</div>
</body>
</html>
<?php ob_end_flush(); ?>

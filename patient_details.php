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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
      body { background: #f6fbff; }
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
      /* --- Ultra-polished Summary Card --- */
      .test-summary-glassy {
        background: linear-gradient(120deg,rgba(249,253,255,0.98) 80%, #e5f2ff 100%);
        border-radius: 22px;
        box-shadow: 0 8px 44px #a9d6ff38, 0 1.5px 0 #b4cffe1a;
        border: 1.6px solid #e0f0fe;
        padding: 44px 48px 30px 48px;
        margin-bottom: 40px;
        max-width: 670px;
        margin-left: auto;
        margin-right: auto;
        backdrop-filter: blur(1px);
        transition: box-shadow .16s;
        position:relative;
      }
      @media (max-width:800px){ .test-summary-glassy{padding:24px 8px 16px 8px;} }
      .ts-main-head {
        font-size: 1.7rem;
        font-weight: 900;
        color: #1d8dfa;
        letter-spacing: .01em;
        margin-bottom: 35px;
        display: flex;
        align-items: center;
      }
      .ts-main-head .ts-icon {
        font-size: 2.5rem;
        margin-right: 15px;
        filter: drop-shadow(0 2px 8px #bbe1ff60);
      }
      .ts-summary-row {
        display: flex;
        flex-wrap: wrap;
        gap: 0 36px;
        justify-content: space-between;
        margin-bottom: 24px;
      }
      @media (max-width:650px){
        .ts-summary-row{flex-direction:column;gap:18px;}
      }
      .ts-summary-block {
        min-width: 170px;
        flex: 1 1 200px;
        margin-bottom: 0;
        margin-right:10px;
        margin-top:10px;
        padding: 0;
        display: flex;
        align-items: center;
      }
      .ts-summary-icon {
        font-size: 1.7rem;
        color: #4bace9;
        margin-right: 16px;
        min-width:32px;
        opacity:.95;
        transition:color .18s;
      }
      .ts-summary-label {
        font-size: 1.04rem;
        color: #3786e6;
        font-weight: 700;
        letter-spacing: .03em;
        margin-bottom: 2px;
      }
      .ts-summary-value {
        font-size: 2.1rem;
        font-weight: 900;
        color: #11202e;
        letter-spacing: .01em;
        line-height:1.05;
        margin-bottom: 0;
        margin-top:0;
      }
      .ts-summary-details {
        display:flex; flex-direction:column; align-items:flex-start; margin-left:6px;
      }
      .ts-summary-note {
        font-size: .98rem;
        color: #8ca0be;
        font-weight: 500;
        margin-left: 1px;
        margin-top:0;
      }
      .ts-summary-mostfreq-label {
        font-size: 1.09rem;
        color: #3172d4;
        font-weight: 700;
        margin-right:10px;
      }
      .ts-summary-mostfreq {
        background: linear-gradient(90deg, #e2efff 65%, #d9eaff 100%);
        color: #1d6af1;
        font-weight: 800;
        font-size: 1.08rem;
        border-radius: 15px;
        padding: 8px 24px;
        box-shadow: 0 2px 12px #b0d7ff21;
        display: inline-block;
        margin-top: 0;
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
                            <td>Patient ID</td><td>: </td><td><?= $patient['patient_id'] ?></td>
                          </tr>
                          <tr>
                            <td>Visits</td><td>: </td><td><?= count($bills) ?></td>
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

                <!-- TEST SUMMARY: ultra-modern, glassy, single card -->
                <?php
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
                <div class="test-summary-glassy">
                  <div class="ts-main-head"><span class="ts-icon"><img src="https://img.icons8.com/color/48/000000/combo-chart--v2.png" width="40" style="margin-right:2px;margin-top:-2px"></span>Test Summary</div>
                  <div class="ts-summary-row">
                    <div class="ts-summary-block">
                      <span class="ts-summary-icon"><i class="fa-solid fa-vial-circle-check"></i></span>
                      <div class="ts-summary-details">
                        <span class="ts-summary-label">Total Tests Done</span>
                        <span class="ts-summary-value"><?= $total_tests ?></span>
                      </div>
                    </div>
                    <div class="ts-summary-block">
                      <span class="ts-summary-icon"><i class="fa-solid fa-flask"></i></span>
                      <div class="ts-summary-details">
                        <span class="ts-summary-label">Unique Tests Done</span>
                        <span class="ts-summary-value"><?= count($unique_tests) ?></span>
                      </div>
                    </div>
                  </div>
                  <div class="ts-summary-row">
                    <div class="ts-summary-block">
                      <span class="ts-summary-icon"><i class="fa-solid fa-calendar-plus"></i></span>
                      <div class="ts-summary-details">
                        <span class="ts-summary-label">First Test Date</span>
                        <span class="ts-summary-value" style="font-size:1.3rem;"><?= $first_test ? date('d-m-Y', strtotime($first_test['date'])) : '-' ?></span>
                        <?php if ($first_test): ?>
                          <span class="ts-summary-note">(<?= htmlspecialchars($first_test['name']) ?>)</span>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="ts-summary-block">
                      <span class="ts-summary-icon"><i class="fa-solid fa-calendar-check"></i></span>
                      <div class="ts-summary-details">
                        <span class="ts-summary-label">Last Test Taken</span>
                        <span class="ts-summary-value" style="font-size:1.3rem;"><?= $last_test ? date('d-m-Y', strtotime($last_test['date'])) : '-' ?></span>
                        <?php if ($last_test): ?>
                          <span class="ts-summary-note">(<?= htmlspecialchars($last_test['name']) ?>)</span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                  <div class="mt-4 d-flex align-items-center flex-wrap">
                    <span class="ts-summary-mostfreq-label">Most Frequent Test</span>
                    <?php if($most_freq_test !== '-'): ?>
                      <span class="ts-summary-mostfreq"><?= htmlspecialchars($most_freq_test) ?></span>
                    <?php else: ?>
                      <span class="ts-summary-mostfreq">-</span>
                    <?php endif; ?>
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

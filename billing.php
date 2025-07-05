<?php
ob_start();
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'db.php';
include 'admin_header.php';

$patient_id = $_GET['patient_id'] ?? null;
$billing_id = $_GET['billing_id'] ?? null;

$patient = null;
$tests_array = [];
$total_amount = 0;
$active_billing = null;

if ($patient_id) {
    $stmt = $conn->prepare("SELECT * FROM patients WHERE patient_id = ?");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $patient = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($billing_id) {
        $stmt = $conn->prepare("SELECT * FROM billing WHERE billing_id = ?");
        $stmt->bind_param("i", $billing_id);
        $stmt->execute();
        $active_billing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } else {
        // use bstatus, not status
        $stmt = $conn->prepare("
  SELECT * 
    FROM billing 
   WHERE patient_id = ? 
     AND bstatus = 'pending' 
ORDER BY billing_id DESC 
   LIMIT 1
");

        $stmt->bind_param("i", $patient_id);
        $stmt->execute();
        $active_billing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($active_billing) $billing_id = $active_billing['billing_id'];
    }

    if ($active_billing) {

        if ($active_billing && $billing_id) {
            // 1. Recalculate subtotal from test_assignments
            $recalculated_total = 0;
            $stmt = $conn->prepare("SELECT SUM(t.price) as total FROM test_assignments ta JOIN tests t ON ta.test_id = t.test_id WHERE ta.billing_id = ?");
            $stmt->bind_param("i", $billing_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $recalculated_total = $row['total'] ?? 0;
            $stmt->close();

            // 2. Recalculate balance
            $discount = floatval($active_billing['discount']);
            $paid = floatval($active_billing['paid_amount']);
            $net = max($recalculated_total - $discount, 0);
            $balance = max($net - $paid, 0);
            $bstatus = ($balance <= 0) ? 'paid' : 'assigned';

            // 3. Update DB if total/balance differs from stored
            if (
                abs($recalculated_total - floatval($active_billing['total_amount'])) > 0.01 ||
                abs($balance - floatval($active_billing['balance_amount'])) > 0.01 ||
                $bstatus !== $active_billing['bstatus']
            ) {
                $stmt = $conn->prepare("UPDATE billing SET total_amount=?, balance_amount=?, bstatus=? WHERE billing_id=?");
                $stmt->bind_param("ddsi", $recalculated_total, $balance, $bstatus, $billing_id);
                $stmt->execute();
                $stmt->close();

                // Refresh active_billing with latest
                $stmt = $conn->prepare("SELECT * FROM billing WHERE billing_id = ?");
                $stmt->bind_param("i", $billing_id);
                $stmt->execute();
                $active_billing = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }

            // 4. Update total_amount to match this calculated one
            $total_amount = $recalculated_total;
        }

        // For Lab Copy: individual test names + category
        $tests_array = [];
        $stmt = $conn->prepare("
  SELECT 
    t.name       AS test_name,
    t.price      AS price,
    COALESCE(tc.category_name,'Uncategorized') AS category_name
  FROM test_assignments ta
  JOIN tests t ON ta.test_id = t.test_id
  LEFT JOIN test_categories tc ON ta.category_id = tc.category_id
  WHERE ta.billing_id = ?
  ORDER BY tc.category_name, t.name
");

        $stmt->bind_param("i", $billing_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $tests_array[] = $row;
        }
        $stmt->close();


        // 2. Get grouped totals by category (for invoice)
        $stmt = $conn->prepare("
        SELECT 
            COALESCE(c.category_name, 'Uncategorized') AS category_name, 
            SUM(t.price) AS total_price
        FROM test_assignments ta
        JOIN tests t ON ta.test_id = t.test_id
        LEFT JOIN test_categories c ON t.category_id = c.category_id
        WHERE ta.billing_id = ?
        GROUP BY c.category_id
    ");
        $stmt->bind_param("i", $billing_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $category_totals = [];
        while ($row = $result->fetch_assoc()) {
            $category_totals[] = $row;
        }
        $stmt->close();

        // Calculate total amount from category_totals
        $total_amount = 0;
        foreach ($category_totals as $row) {
            $total_amount += $row['total_price'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Recalculate total from test_assignments
    $recalculated_total = 0;
    $stmt = $conn->prepare("SELECT t.price FROM test_assignments ta JOIN tests t ON ta.test_id = t.test_id WHERE ta.billing_id = ?");
    $stmt->bind_param("i", $billing_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $recalculated_total += $row['price'];
    }
    $stmt->close();

    if (isset($_POST['generate_bill'])) {
        $mode     = $_POST['discount_type'] ?? 'amount';
        $entered  = floatval($_POST['discount']);
        $subtotal = $recalculated_total;

        if ($mode === 'percent') {
            // clamp to 0–100%
            $p = min(max($entered, 0), 100);
            $discount = ($p / 100) * $subtotal;
        } else {
            $discount = max($entered, 0);
        }

        $paid = floatval($_POST['paid_amount']);
        $net = max($recalculated_total - $discount, 0);
        $balance = max($net - $paid, 0);
        $bstatus = ($balance <= 0) ? 'paid' : 'assigned';

        $stmt = $conn->prepare("UPDATE billing SET total_amount=?, discount=?, paid_amount=?, balance_amount=?, bstatus=? WHERE billing_id=?");
        $stmt->bind_param("ddddsi", $net, $discount, $paid, $balance, $bstatus, $billing_id);

        if ($stmt->execute()) {
            // --- Handle Doctor Commission Insertion ---
            $patientRow = $conn->query("SELECT referred_by FROM billing WHERE billing_id = $billing_id")->fetch_assoc();

            if ($patientRow && !empty($patientRow['referred_by'])) {
                $doctor_id = $patientRow['referred_by'];

                // Reuse commission_base (already calculated above)
                $commission_percent = 10; // default
                $rateRow = $conn->query("SELECT commission_percent FROM doctors WHERE doctor_id = $doctor_id")->fetch_assoc();
                if ($rateRow) {
                    $commission_percent = $rateRow['commission_percent'];
                }
                $commission_amount = ($commission_percent / 100) * $recalculated_total;

                // Insert doctor commission
                $stmt3 = $conn->prepare("INSERT INTO doctor_commissions (doctor_id, billing_id, commission_amount) VALUES (?, ?, ?)");
                $stmt3->bind_param("iid", $doctor_id, $billing_id, $commission_amount);
                $stmt3->execute();
                $stmt3->close();
            }

            header("Location: billing.php?patient_id=$patient_id&billing_id=$billing_id&toast=1");
            exit();
        }
    }

    if (isset($_POST['pay_due'])) {
        $payment = floatval($_POST['due_amount']);

        // 🔁 Re-fetch billing fresh from DB
        $stmt = $conn->prepare("SELECT discount, paid_amount FROM billing WHERE billing_id = ?");
        $stmt->bind_param("i", $billing_id);
        $stmt->execute();
        $billingData = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $new_paid = $billingData['paid_amount'] + $payment;
        $net = max($recalculated_total - $billingData['discount'], 0);
        $balance = max($net - $new_paid, 0);
        $bstatus = ($balance <= 0) ? 'paid' : 'assigned';

        $stmt = $conn->prepare("UPDATE billing SET total_amount=?, paid_amount=?, balance_amount=?, bstatus=? WHERE billing_id=?");
        $stmt->bind_param("dddsi", $recalculated_total, $new_paid, $balance, $bstatus, $billing_id);

        if ($stmt->execute()) {
            header("Location: billing.php?patient_id=$patient_id&billing_id=$billing_id&toast=1");
            exit();
        }
    }
}


if (isset($_GET['mark_printed']) && $billing_id && $active_billing['balance_amount'] <= 0) {
    $conn->query("UPDATE billing SET status = 'printed' WHERE billing_id = $billing_id");
    header("Location: billing.php?patient_id=$patient_id&billing_id=$billing_id&toast=1");
    exit();
}

$lab_settings = $conn->query("SELECT * FROM lab_settings WHERE id = 1")->fetch_assoc();
?>

<!DOCTYPE html>
<html>

<head>
    <title>Billing</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link
  href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css"
  rel="stylesheet"
/>

    <style>
        .badge-draft {
            background: #ffc107;
            color: #000;
        }

        .badge-open {
            background: #28a745;
        }

        .badge-printed {
            background: #17a2b8;
        }
    </style>
</head>

<body>
    <div class="container mt-4">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0">🧾 Patient Billing</h4>
            </div>
            <div class="card-body">
                <form class="form-inline mb-4" method="GET">
  <label class="mr-2 font-weight-bold">Select Patient</label>
  <select
      name="patient_id"
      id="patient_select"
      class="form-control mr-2"
  >
    <option></option><!-- allowClear needs an empty option -->
    <?php
    $patients = $conn->query("SELECT patient_id, name FROM patients ORDER BY name ASC");
    while ($p = $patients->fetch_assoc()):
    ?>
      <option
        value="<?= $p['patient_id'] ?>"
        <?= ($patient_id == $p['patient_id']) ? 'selected' : '' ?>
      >
        <?= htmlspecialchars($p['name']) ?> (ID: <?= $p['patient_id'] ?>)
      </option>
    <?php endwhile; ?>
  </select>
</form>



                <?php if ($patient): ?>
  <div class="form-group mb-3">
    <label><strong>📜 All Bills</strong></label>
    <select onchange="window.location.href=this.value" class="form-control">
      <option value="">-- Select Bill --</option>
      <?php
        // pull in bstatus instead of the unused status field
        $bills = $conn->query("
          SELECT billing_id,
                 billing_date,
                 bstatus
            FROM billing
           WHERE patient_id = $patient_id
        ORDER BY billing_date DESC
        ");

        while ($b = $bills->fetch_assoc()):
          // pick an emoji per bstatus
          $icon = match ($b['bstatus']) {
            'pending'  => '🟡',
            'assigned' => '🧪',
            'paid'     => '✅',
            'printed'  => '🖨️',
            default    => '❓',
          };
      ?>
        <option
          value="billing.php?patient_id=<?= $patient_id ?>&billing_id=<?= $b['billing_id'] ?>"
          <?= ($billing_id == $b['billing_id']) ? 'selected' : '' ?>
        >
          <?= $icon ?> Bill #<?= $b['billing_id'] ?> (<?= date('d-m-Y', strtotime($b['billing_date'])) ?>)
        </option>
      <?php endwhile; ?>
    </select>
  </div>
<?php endif; ?>


                <?php if ($active_billing): ?>
                    <div class="invoice-summary p-3 bg-white border rounded" id="print-area">
                        <h5><?= htmlspecialchars($patient['name']) ?>
                            <?php
                            $bstatus = $active_billing['bstatus'] ?? 'pending';
                            $badgeClass = match ($bstatus) {
                                'pending' => 'badge-warning',
                                'assigned' => 'badge-info',
                                'paid' => 'badge-success',
                                default => 'badge-secondary'
                            };
                            ?>
                            <span class="badge <?= $badgeClass ?>"><?= ucfirst($bstatus) ?></span>

                        </h5>
                        <p><strong>Patient ID:</strong> <?= $patient['patient_id'] ?> | <strong>Bill ID:</strong> <?= $active_billing['billing_id'] ?></p>

                        <?php if ($bstatus !== 'paid'): ?>
                            <div class="alert alert-warning">
                                ⚠️ Bill not fully paid. Report finalization will be locked until dues are cleared.
                            </div>
                        <?php endif; ?>


                        <?php if (!empty($tests_array)): ?>
                            <table class="table table-bordered">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Test</th>
                                        <th class="text-right">Price (₹)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // first, group them in PHP

                                    // group them in PHP
                                    $grouped = [];
                                    foreach ($tests_array as $t) {
                                        $grouped[$t['category_name']][] = $t;
                                    }
                                    ?>

                                    <?php foreach ($grouped as $category => $tests): ?>

                                        <?php if ($category !== 'Uncategorized'): ?>
                                            <?php $catTotal = array_sum(array_column($tests, 'price')); ?>
                                            <tr class="bg-light font-weight-bold">
                                                <td><?= htmlspecialchars($category) ?></td>
                                                <td class="text-right">₹ <?= number_format($catTotal, 2) ?></td>
                                            </tr>
                                        <?php endif; ?>

                                        <?php foreach ($tests as $t): ?>
                                            <tr>
                                                <td style="padding-left: <?= $category === 'Uncategorized' ? '0' : '20px' ?>">
                                                    <?= $category === 'Uncategorized' ? '' : '— ' ?>
                                                    <?= htmlspecialchars($t['test_name']) ?>
                                                </td>
                                                <td class="text-right">₹ <?= number_format($t['price'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>

                                    <?php endforeach; ?>

                                    <tr class="bg-light">
                                        <td><strong>Subtotal</strong></td>
                                        <td class="text-right" id="subtotal_display">₹ <?= number_format($total_amount, 2) ?></td>
                                    </tr>

                                    <?php
                                    // calculate discount percentage
                                    $discAmt = $active_billing['discount'];
                                    $discPct = $total_amount > 0
                                        ? round(($discAmt / $total_amount) * 100, 1)
                                        : 0;
                                    ?>
                                    <tr class="table-warning">
                                        <td><strong>Discount</strong></td>
                                        <td id="discount_display" style="
      padding:4px 0;
      color:red;
      display:flex;
      justify-content:flex-end;
      align-items:center;
    ">
                                            <!-- discounted amount -->
                                            <span>– ₹ <?= number_format($active_billing['discount'], 2) ?></span>
                                            <!-- percentage tag, pushed to the right of the amount -->
                                            <small style="margin-left:8px; font-size:85%;">(<?= $discPct ?>%)</small>
                                        </td>
                                    </tr>

                                    <tr class="table-info font-weight-bold">
                                        <td>Net Payable</td>
                                        <td id="net_display" class="text-right">₹ <?= number_format($total_amount - $active_billing['discount'], 2) ?></td>
                                    </tr>
                                    <tr>
                                        <td>Paid</td>
                                        <td id="paid_display" class="text-right">₹ <?= number_format($active_billing['paid_amount'], 2) ?></td>
                                    </tr>
                                    <tr>
                                        <td>Due</td>
                                        <td id="due_display" class="text-right text-danger">
                                            <?= $active_billing['balance_amount'] <= 0 ? '<span class="text-success">All Paid</span>' : '₹ ' . number_format($active_billing['balance_amount'], 2) ?>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                            <?php if ($active_billing['bstatus'] !== 'paid' && $active_billing['paid_amount'] <= 0): ?>
                                <form method="POST" class="mb-2">
                                    <input type="hidden" name="generate_bill" value="1">

                                    <div class="form-row">
                                        <!-- Discount Type -->
                                        <div class="form-group col-md-4">
                                            <label>Discount Type</label>
                                            <select name="discount_type" id="discount_type" class="form-control">
                                                <option value="amount" selected>Amount (₹)</option>
                                                <option value="percent">Percentage (%)</option>
                                            </select>
                                        </div>

                                        <!-- Discount Value -->
                                        <div class="form-group col-md-4">
                                            <label id="discount_label">Discount (₹)</label>
                                            <input
                                                type="number"
                                                name="discount"
                                                id="discount"
                                                class="form-control"
                                                step="0.01"
                                                value="<?= number_format($active_billing['discount'], 2) ?>">
                                        </div>

                                        <!-- Paid Amount -->
                                        <div class="form-group col-md-4">
                                            <label>Paid Amount (₹)</label>
                                            <input
                                                type="number"
                                                name="paid_amount"
                                                id="paid_amount"
                                                class="form-control"
                                                step="0.01"
                                                required>
                                        </div>
                                    </div>

                                    <button class="btn btn-success">💰 Finalize Bill</button>
                                </form>
                            <?php endif; ?>


                            <?php if ($active_billing['paid_amount'] > 0 && $active_billing['balance_amount'] > 0): ?>
  <form method="POST" class="form-inline">
    <input type="hidden" name="pay_due" value="1">
    <input type="number" name="due_amount" class="form-control mr-2" step="0.01" required placeholder="Pay Due">
    <button class="btn btn-warning">💳 Pay Due</button>
  </form>
<?php endif; ?>


                            <div class="mt-3">
                                <button onclick="printInvoice()" class="btn btn-info mr-2">🖨️ Print Invoice</button>
                                <button onclick="printLabCopy()" class="btn btn-secondary">🧾 Lab Copy</button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Print Templates -->
    <!-- Enhanced Invoice Print Template -->
    <div id="invoice-print" style="display:none; font-family:'Segoe UI', Tahoma, sans-serif; font-size:13px;">
        <div style="border:1px solid #999; padding:16px; max-width:750px; margin:auto;">

            <!-- Header -->
            <div style="text-align:center; border-bottom:1px solid #ccc; padding-bottom:8px; margin-bottom:10px;">
                <h2 style="margin:0; font-size:22px;"><?= strtoupper($lab_settings['lab_name']) ?></h2>
                <p style="margin:0; font-size:12px; line-height:1.4;">
                    <?= $lab_settings['address_line1'] . ' ' . $lab_settings['address_line2'] ?>,
                    <?= $lab_settings['city'] ?>, <?= $lab_settings['state'] ?> - <?= $lab_settings['pincode'] ?><br>
                    Phone: <?= $lab_settings['phone'] ?> | Email: <?= $lab_settings['email'] ?>
                </p>
                <h4 style="margin-top:10px;">Customer Copy</h4>
            </div>

            <!-- Patient + Bill Info -->
            <table style="width:100%; margin-bottom:10px;">
                <tr>
                    <td style="width:50%; vertical-align:top;">
                        <strong>Patient Name:</strong> <?= htmlspecialchars($patient['name']) ?><br>
                        <strong>Patient ID:</strong> <?= $patient['patient_id'] ?><br>
                        <strong>Sex / Age:</strong> <?= ucfirst($patient['gender']) ?> / <?= $patient['age'] ?>
                    </td>
                    <td style="text-align:right; vertical-align:top;">
                        <strong>Bill ID:</strong> <?= 'HDC_' . $active_billing['billing_id'] ?><br>
                        <strong>Billing Date:</strong> <?= date('d-m-Y', strtotime($active_billing['billing_date'])) ?>
                    </td>
                </tr>
            </table>

            <!-- Test Charges Table -->
            <?php
            // Build two structures: one for real categories, one for uncategorized tests
            $grouped     = [];
            $uncategorized = [];
            $subtotal    = 0;

            foreach ($tests_array as $t) {
                $subtotal += $t['price'];

                if ($t['category_name'] !== 'Uncategorized') {
                    $grouped[$t['category_name']] =
                        ($grouped[$t['category_name']] ?? 0) + $t['price'];
                } else {
                    $uncategorized[] = $t;
                }
            }
            ?>

            <table style="width:100%; border-collapse:collapse; margin-bottom:20px;">
                <thead>
                    <tr style="background:#f4f4f4; border:1px solid #ccc;">
                        <th style="text-align:left; padding:6px; border:1px solid #ccc;">Test</th>
                        <th style="text-align:right; padding:6px; border:1px solid #ccc;">Amount (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- 1) One row per real category -->
                    <?php foreach ($grouped as $category => $amount): ?>
                        <tr>
                            <td style="padding:6px; border:1px solid #ccc; font-weight:bold;">
                                <?= htmlspecialchars($category) ?>
                            </td>
                            <td style="padding:6px; text-align:right; border:1px solid #ccc;">
                                ₹ <?= number_format($amount, 2) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <!-- 2) Then each uncategorized test on its own -->
                    <?php foreach ($uncategorized as $t): ?>
                        <tr>
                            <td style="padding:6px; border:1px solid #ccc; padding-left:20px;">
                                <?= htmlspecialchars($t['test_name']) ?>
                            </td>
                            <td style="padding:6px; text-align:right; border:1px solid #ccc;">
                                ₹ <?= number_format($t['price'], 2) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Billing Summary Box (uses the same $subtotal) -->
            <div style="width:280px; float:right; border:1px solid #ccc; padding:10px; font-size:13px;">
                <table style="width:100%; border-collapse:collapse;">
                    <tr>
                        <td style="padding:4px 0;">Subtotal</td>
                        <td style="text-align:right;">₹ <?= number_format($subtotal, 2) ?></td>
                    </tr>
                    <?php
                    // calculate discount percentage
                    $discAmt = $active_billing['discount'];
                    $discPct = $total_amount > 0
                        ? round(($discAmt / $total_amount) * 100, 1)
                        : 0;
                    ?>
                    <tr class="table-warning">
                        <td><strong>Discount</strong></td>
                        <td style="
      padding:4px 0;
      color:red;
      display:flex;
      justify-content:flex-end;
      align-items:center;
    ">
                            <!-- discounted amount -->
                            <span>– ₹ <?= number_format($active_billing['discount'], 2) ?></span>
                            <!-- percentage tag, pushed to the right of the amount -->
                            <small style="margin-left:8px; font-size:85%;">(<?= $discPct ?>%)</small>
                        </td>
                    </tr>


                    <tr style="border-top:1px dashed #ccc;">
                        <td style="padding:6px 0;"><strong>Net Payable</strong></td>
                        <td style="text-align:right;"><strong>
                                ₹ <?= number_format($subtotal - $active_billing['discount'], 2) ?>
                            </strong></td>
                    </tr>
                    <tr>
                        <td style="padding:4px 0;">Paid</td>
                        <td style="text-align:right;">₹ <?= number_format($active_billing['paid_amount'], 2) ?></td>
                    </tr>
                    <tr>
                        <td style="padding:4px 0;">Due</td>
                        <td style="text-align:right; color:<?= $active_billing['balance_amount'] > 0 ? 'red' : 'green' ?>">
                            <?= $active_billing['balance_amount'] <= 0 ? 'All Paid' : '₹ ' . number_format($active_billing['balance_amount'], 2) ?>
                        </td>
                    </tr>
                </table>
            </div>
            <div style="clear:both;"></div>

            <!-- Footer -->
      
        </div>
                        <!-- Existing thank-you line -->
    <p style="text-align:center; font-size:11px; margin:0;">
      Thank you for choosing <strong><?= htmlspecialchars($lab_settings['lab_name']) ?></strong>. We value your trust.
    </p>

    <!-- 👇 Your new footer note 👇 -->
    <div style="text-align:center; font-size:10px; margin-top:8px; color:#555;">
      <em>Please keep this invoice for your records. For any questions, call <?= htmlspecialchars($lab_settings['phone']) ?>.</em>
    </div>
    </div>


<!-- Lab Copy -->
<div id="lab-copy" style="display:none; font-size:12px; font-family:'Segoe UI', Tahoma, sans-serif;">

  <!-- Header -->
  <div style="text-align:center; margin-bottom:10px;">
    <h2 style="margin:0;"><?= strtoupper($lab_settings['lab_name']) ?></h2>
    <div style="font-size:10px; line-height:1.2;">
      <?= htmlspecialchars($lab_settings['address_line1'] . ' ' . $lab_settings['address_line2']) ?>,
      <?= htmlspecialchars($lab_settings['city']) ?>, <?= htmlspecialchars($lab_settings['state']) ?>
      - <?= htmlspecialchars($lab_settings['pincode']) ?><br>
      Phone: <?= htmlspecialchars($lab_settings['phone']) ?> |
      Email: <?= htmlspecialchars($lab_settings['email']) ?>
    </div>
    <hr style="margin:8px 0; border-top:1px solid #000;">
    <div style="font-weight:bold; margin-bottom:8px;">🧪 LAB COPY</div>
  </div>

  <!-- Patient + Bill Info -->
  <table style="width:100%; margin-bottom:12px; font-size:12px;">
    <tr>
      <td><strong>Name:</strong> <?= htmlspecialchars($patient['name']) ?></td>
      <td style="text-align:right;"><strong>Bill ID:</strong> <?= 'HDC_' . $active_billing['billing_id'] ?></td>
    </tr>
    <tr>
      <td><strong>Age/Sex:</strong> <?= htmlspecialchars($patient['age'].' / '.ucfirst($patient['gender'])) ?></td>
      <td style="text-align:right;"><strong>Date:</strong> <?= date('d-m-Y',strtotime($active_billing['billing_date'])) ?></td>
    </tr>
  </table>

  <?php
    // group tests by category
    $grouped = [];
    foreach ($tests_array as $t) {
      $grouped[$t['category_name']][] = $t['test_name'];
    }
  ?>

  <?php foreach ($grouped as $category => $tests): ?>
    <!-- Category Heading -->
    <h5 style="margin:12px 0 4px;"><?= htmlspecialchars($category) ?></h5>

    <!-- Test / Result Table -->
    <table 
      style="
        width:100%;
        border-collapse:collapse;
        margin-bottom:16px;
        font-size:12px;
      "
      border="1"
      cellpadding="4"
      cellspacing="0"
    >
      <thead>
        <tr style="background:#f0f0f0;">
          <th style="width:70%; text-align:left;">Test Name</th>
          <th style="width:30%; text-align:left;">Result</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tests as $name): ?>
          <tr>
            <td><?= htmlspecialchars($name) ?></td>
            <td style="height:32px;"></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Machine Used Line -->
    <div style="font-size:12px; margin-bottom:8px;">
      <strong>Machine Used:</strong> _____________________________
    </div>
  <?php endforeach; ?>

  <!-- Signature -->
  <div style="text-align:right; margin-top:20px; font-size:12px;">
    <em>Authorized Signature: _____________________________</em>
  </div>
</div>




            <script>
                document.getElementById('discount_type')
                    .addEventListener('change', function() {
                        const lbl = document.getElementById('discount_label');
                        const inp = document.getElementById('discount');
                        if (this.value === 'percent') {
                            lbl.textContent = 'Discount (%)';
                            inp.setAttribute('max', 100);
                        } else {
                            lbl.textContent = 'Discount (₹)';
                            inp.removeAttribute('max');
                        }
                    });
            </script>
<!-- Discount Calculation -->
<script>
document.addEventListener('DOMContentLoaded',function(){
  const subtotal   = parseFloat('<?= $total_amount ?>');
  const dtEl       = document.getElementById('discount_type');
  const discEl     = document.getElementById('discount');
  const paidEl     = document.getElementById('paid_amount');
  const subDisp    = document.getElementById('subtotal_display');
  const discDisp   = document.getElementById('discount_display');
  const netDisp    = document.getElementById('net_display');
  const paidDisp   = document.getElementById('paid_display');
  const dueDisp    = document.getElementById('due_display');

  function fmt(x){ return '₹ '+ x.toFixed(2) }
  function update() {
    let entered = parseFloat(discEl.value)||0;
    let discAmt = dtEl.value==='percent'
      ? Math.min(Math.max(entered,0),100)/100 * subtotal
      : Math.min(Math.max(entered,0),subtotal);
    let paidAmt = Math.min(Math.max(parseFloat(paidEl.value)||0,0),subtotal-discAmt);
    let net     = subtotal - discAmt;
    let due     = net - paidAmt;

    subDisp.textContent  = fmt(subtotal);
    discDisp.textContent = '– '+ fmt(discAmt)
                         + (dtEl.value==='percent'
                              ? ' ('+((discAmt/subtotal*100)||0).toFixed(1)+'%)'
                              : '');
    netDisp.textContent  = fmt(net);
    paidDisp.textContent = fmt(paidAmt);
    dueDisp.textContent  = due<=0 ? 'All Paid' : fmt(due);
  }

  dtEl.addEventListener('change', update);
  discEl.addEventListener('input',  update);
  paidEl.addEventListener('input',  update);
  update();
});
</script>


            <script>
                function printInvoice() {
                    const content = document.getElementById('invoice-print').innerHTML;
                    const win = window.open('', '_blank');
                    win.document.write('<html><head><title>Invoice</title></head><body>' + content + '</body></html>');
                    win.document.close();
                    win.focus();
                    win.print();
                    win.close();
                }

                function printLabCopy() {
                    const content = document.getElementById('lab-copy').innerHTML;
                    const win = window.open('', '_blank');
                    win.document.write('<html><head><title>Lab Copy</title></head><body>' + content + '</body></html>');
                    win.document.close();
                    win.focus();
                    win.print();
                    win.close();
                }
            </script>

            <script>
  $(document).ready(function(){
    $('#patient_select')
      .select2({
        placeholder: 'Search patient…',
        allowClear: true,
        width: 'style'   // match the original .form-control width
      })
      // submit the form as soon as a patient is picked
      .on('select2:select', function(){
        this.form.submit();
      });
  });
</script>


<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>



            <?php if (isset($_GET['toast']) && $_GET['toast'] == '1'): ?>
                <script>
                    Swal.fire({
                        icon: 'success',
                        toast: true,
                        title: 'Billing updated!',
                        position: 'top-end',
                        timer: 3000,
                        showConfirmButton: false
                    });
                </script>
            <?php endif; ?>
</body>

</html>
<?php ob_end_flush(); ?>
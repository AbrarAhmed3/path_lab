<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'admin_header.php';
include 'db.php';

$patient_id = $_GET['patient_id'] ?? null;
$billing_id = $_GET['billing_id'] ?? null;

$patient = null;
$results_by_department = [];

// ─────────────────────────────────────────────────
// Helper: render any test or component‐specific ref‐range
// ─────────────────────────────────────────────────
function render_reference_range_html($test_id, $patient, $value = null, $component_label = null)
{
    global $conn;
    // 1) Build the SQL
    $sql = "
      SELECT *
      FROM test_ranges
      WHERE test_id = ?
        AND (gender = ? OR gender = 'Any')
        AND (age_min IS NULL OR age_min <= ?)
        AND (age_max IS NULL OR age_max >= ?)
        AND (
          (gestation_min IS NULL AND gestation_max IS NULL)
          OR (? BETWEEN gestation_min AND gestation_max)
        )
    ";
    if ($component_label !== null) {
        $sql .= " AND range_type = 'component' AND condition_label = ? ";
    }
    $sql .= "
      ORDER BY FIELD(range_type,'label','component','age_gender','gender','age','simple'),
               gestation_min DESC,
               age_min       DESC
    ";

    // 2) Prepare, bind & execute
    $stmt = $conn->prepare($sql);
    if ($component_label !== null) {
        $stmt->bind_param(
            'isiiis',
            $test_id,
            $patient['gender'],
            $patient['age'],
            $patient['age'],
            $patient['gestational_weeks'],
            $component_label
        );
    } else {
        $stmt->bind_param(
            'isiii',
            $test_id,
            $patient['gender'],
            $patient['age'],
            $patient['age'],
            $patient['gestational_weeks']
        );
    }
    $stmt->execute();

    // 3) **Fetch all rows into $ranges** before looping
    $ranges = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // 4) Iterate and build your HTML
    $html = '';
    foreach ($ranges as $r) {
        $low   = $r['value_low'];
        $high  = $r['value_high'];
        $unit  = htmlspecialchars($r['unit']);
        $type  = $r['range_type'];
        $cond  = htmlspecialchars($r['condition_label'] ?? '');
        $flag  = htmlspecialchars($r['flag_label']     ?? '');

        // component‐only
        if ($component_label !== null && $type === 'component') {
            if ($low === null && $high === null)       $html = '-';
            elseif ($low === null)                     $html = "&lt; {$high}";
            elseif ($high === null)                    $html = "&gt; {$low}";
            elseif ($low == $high)                     $html = "{$low}";
            else                                       $html = "{$low} - {$high}";
            $html .= "";
            break;
        }

        // standalone test (labels first)
        if ($component_label === null && in_array($type, ['label','age_gender','gender','age','simple'])) {
            if ($type === 'label') {
                $rangeText = "{$low}-{$high}";
                $html = "{$cond}: {$rangeText}";
                // if ($flag) $html .= " ({$flag})";
            } else {
                if ($low === null && $high === null)       $html = '-';
                elseif ($low === null)                     $html = "&lt; {$high}";
                elseif ($high === null)                    $html = "&gt; {$low}";
                elseif ($low == $high)                     $html = "{$low}";
                else                                       $html = "{$low} - {$high}";
                $html .= "";
            }
            break;
        }

        // **YOUR NEW BLOCK**: gender+phase + label + age_gender + simple
        if ($component_label === null && in_array($type, ['label','age_gender','gender','age','simple'])) {
            if ($type === 'label') {
                $rangeText = "{$low}-{$high}";
                $html = "{$cond}: {$rangeText}";
                // if ($flag) $html .= " ({$flag})";
            } else {
                if ($low === null && $high === null)       $html = '-';
                elseif ($low === null)                     $html = "&lt; {$high}";
                elseif ($high === null)                    $html = "&gt; {$low}";
                elseif ($low == $high)                     $html = "{$low}";
                else                                       $html = "{$low} - {$high}";
                $html .= "";
            }
            break;
        }
    }

    return $html ?: 'N/A';
}




$booking_on = $report_generated_on = null;
if ($billing_id) {

    $lab_doctors = []; // holds all lab doctors
    $treated_indexes = []; // index of doctors who are treating

    $stmt = $conn->prepare("
    SELECT d.name, d.qualification, d.specialization, d.reg_no, r.is_treating_doctor
    FROM report_lab_doctors r
    JOIN doctors d ON r.doctor_id = d.doctor_id
    WHERE r.billing_id = ?
");
    $stmt->bind_param("i", $billing_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $lab_doctors[] = $row;
    }
    $stmt->close();

    // Prepared statement for booking date
    $stmt = $conn->prepare("SELECT MIN(assigned_date) AS booking_on FROM test_assignments WHERE billing_id = ?");
    $stmt->bind_param("i", $billing_id);
    $stmt->execute();
    $booking_on = $stmt->get_result()->fetch_assoc()['booking_on'] ?? null;
    $stmt->close();

    // Prepared statement for report generated date
    // Pull the date we actually finalized the report
$stmt = $conn->prepare("
    SELECT finalized_on
    FROM billing
    WHERE billing_id = ?
");
$stmt->bind_param("i", $billing_id);
$stmt->execute();
$report_generated_on = $stmt->get_result()->fetch_assoc()['finalized_on'] ?? null;
$stmt->close();

}

$report_delivery = date("Y-m-d");

if ($patient_id && $billing_id) {
    // Get patient
    $stmt1 = $conn->prepare("SELECT * FROM patients WHERE patient_id = ?");
    $stmt1->bind_param("i", $patient_id);
    $stmt1->execute();
    $patient = $stmt1->get_result()->fetch_assoc();
    $stmt1->close();

    // Get machine info
    $machine_info_map = [];
    $stmt2 = $conn->prepare("SELECT department_name, machine_name FROM report_machine_info WHERE billing_id = ?");
    $stmt2->bind_param("i", $billing_id);
    $stmt2->execute();
    $result2 = $stmt2->get_result();
    while ($row = $result2->fetch_assoc()) {
        $machine_info_map[$row['department_name']] = $row['machine_name'];
    }
    $stmt2->close();

    // Get tests and results by department
    $stmt3 = $conn->prepare("
        SELECT 
            d.department_name, t.test_id, t.name AS test_name, t.unit, t.method,
            tr.result_value, ta.assignment_id
        FROM test_results tr
        JOIN test_assignments ta ON tr.assignment_id = ta.assignment_id
        JOIN tests t ON ta.test_id = t.test_id
        LEFT JOIN test_categories c ON t.category_id = c.category_id
        LEFT JOIN departments d ON c.department_id = d.department_id
        WHERE ta.patient_id = ? AND ta.billing_id = ?
        ORDER BY d.department_name, t.name
    ");
    $stmt3->bind_param("ii", $patient_id, $billing_id);
    $stmt3->execute();
    $res3 = $stmt3->get_result();
    while ($row = $res3->fetch_assoc()) {
        $dept = $row['department_name'] ?? 'General';
        $results_by_department[$dept][] = $row;
    }
    $stmt3->close();
}






function formatRange($low, $high)
{
    if ($low === null && $high === null) return "N/A";
    if ($low === null) return "Up to $high";
    if ($high === null) return "Above $low";
    return $low == $high ? "$low" : "$low - $high";
}
?>
<!DOCTYPE html>
<html>

<head>
    <title>Patient Report</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/qrcode/build/qrcode.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        body {
            background-color: #f4f7fa;
            font-family: 'Segoe UI', sans-serif;
        }

        .report-container {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            height: 1123px;
            /* Full A4 height */
            padding: 144px 20px 96px 20px;
            /* 1.5 inch top, 1 inch bottom */
            background: #fff;
            border: 1px solid #ccc;
            margin: 0 auto 0px auto;
            border-radius: 6px;
            position: relative;
            box-sizing: border-box;
            page-break-inside: avoid;
        }

        .report-body-content {
            flex-grow: 1;
        }

        .custom-footer {
            page-break-inside: avoid;
        }



        .info-table td {
            font-size: 14px;
            padding: 4px 8px;
        }

        .test-table th,
        .test-table td {
            font-size: 14px;
            text-align: center;
            vertical-align: middle;
        }

        .signature {
            margin-top: 50px;
            text-align: right;
        }

        .signature img {
            height: 60px;
        }

        .footer-note {
            text-align: center;
            font-size: 12px;
            margin-top: 20px;
            border-top: 1px dashed #999;
            padding-top: 10px;
        }

        .arrow-up {
            color: red;
            font-weight: 600;
        }

        .arrow-down {
            color: blue;
            font-weight: 600;
        }

        .result-value {
            color: #222;
            font-weight: 500;
            font-size: 14px;
        }

        .method-note {
            font-size: 13px;
            color: #555;
            display: block;
        }

        .barcode-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 10px auto;
            height: 70px;
            overflow: hidden;
        }

        .barcode-wrapper svg {
            max-width: 100%;
            max-height: 100%;
            height: auto !important;
            width: auto !important;
        }

        .watermark {
            position: absolute;
            top: 35%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 60px;
            color: rgba(0, 0, 0, 0.05);
            z-index: 0;
            white-space: nowrap;
            pointer-events: none;
        }

        .custom-footer p {
            font-size: 14px;
            line-height: 1.5;
        }

        .custom-footer canvas {
            margin-bottom: 5px;
        }

        @media print {
            .no-print {
                display: none !important;
            }

            html,
            body {
                margin: 0;
                padding: 0;
                font-size: 12px;
                line-height: 1.4;
            }

            .report-container {
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                height: 1123px;
                padding: 144px 20px 96px 20px;
                background: #fff;
                border: 1px solid #ccc;
                border-radius: 6px;
                margin: 0 auto 24px auto;
                /* Add space between pages */
                box-sizing: border-box;
                page-break-inside: avoid;
            }

        }

        .report-container {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            height: 1123px;
            /* A4 height at 96dpi */
            padding: 144px 20px 96px 20px;
            /* 1.5 inch top, 1 inch bottom */
            background: #fff;
            border: 1px solid #ccc;
            border-radius: 6px;
            margin: 0 auto 0px auto;
            box-sizing: border-box;
            page-break-inside: avoid;
        }

        .custom-footer {
            page-break-inside: avoid;
        }

        .test-table th,
        .test-table td {
            font-size: 13px;
            text-align: left;
            vertical-align: middle;
            border: none !important;
            padding: 8px 10px;
        }

        .test-table thead tr {
            border-bottom: 1px solid #999 !important;
        }

        canvas[id^="qr-code-"] {
            display: inline-block;
            margin-top: 10px;
            margin-bottom: 10px;
        }

        @page {
            size: A4;
            margin: 0;
        }


        @media print {
            .print-footer {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                padding: 10px 20px;
                background: white;
                border-top: 1px solid #ccc;
                font-size: 12px;
                page-break-inside: avoid;
            }

            body {
                margin-bottom: 180px;
                /* leave space for footer */
            }
        }

        .report-chunk {
            page-break-after: always;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Header and Filters -->
        <div class="no-print mt-4 mb-3 text-right">
            <form method="GET" class="form-inline mb-3">
                <label class="mr-2">Patient:</label>
                <select name="patient_id" class="form-control mr-2" onchange="this.form.submit()">
                    <option value="">-- Select --</option>
                    <?php
                    $pList = $conn->query("SELECT patient_id, name FROM patients");
                    while ($p = $pList->fetch_assoc()) {
                        $sel = ($p['patient_id'] == $patient_id) ? 'selected' : '';
                        echo "<option value='{$p['patient_id']}' $sel>{$p['name']} (ID: {$p['patient_id']})</option>";
                    }
                    ?>
                </select>
                <?php if ($patient_id): ?>
                    <label class="ml-3 mr-2">Visit:</label>
                    <select name="billing_id" class="form-control" onchange="this.form.submit()">
                        <option value="">-- Select Visit --</option>
                        <?php
                        $visits = $conn->prepare("SELECT billing_id, billing_date FROM billing WHERE patient_id = ? ORDER BY billing_date DESC");
                        $visits->bind_param("i", $patient_id);
                        $visits->execute();
                        $result = $visits->get_result();
                        while ($v = $result->fetch_assoc()) {
                            $s = ($v['billing_id'] == $billing_id) ? 'selected' : '';
                            echo "<option value='{$v['billing_id']}' $s>Visit #{$v['billing_id']} - " . date('d M Y', strtotime($v['billing_date'])) . "</option>";
                        }
                        $visits->close();
                        ?>
                    </select>
                <?php endif; ?>
            </form>
            <?php if ($patient && $billing_id): ?>
                <?php
                // Fetch fstatus and gstatus for display
                $fstatus = $gstatus = null;
                $status_stmt = $conn->prepare("SELECT fstatus, gstatus, referred_by FROM billing WHERE billing_id = ?");
                $status_stmt->bind_param("i", $billing_id);
                $status_stmt->execute();
                $status_stmt->bind_result($fstatus, $gstatus,$referred_by_id);
                $status_stmt->fetch();
                $status_stmt->close();
                

                // Lookup the referring doctor’s name
$referrerName = 'N/A';
if (! empty($referred_by_id)) {
  $d = $conn->prepare("SELECT name FROM doctors WHERE doctor_id = ?");
  $d->bind_param("i", $referred_by_id);
  $d->execute();
  $dname = $d->get_result()->fetch_assoc()['name'] ?? null;
  $d->close();
  if ($dname) {
    $referrerName = htmlspecialchars($dname);
  }
}
?>

                <div class="mb-3">
                    <span class="badge badge-dark">📄 Report Status: <?= ucfirst($gstatus ?? 'Not Ready') ?></span>
                    <span class="badge badge-success">✅ Finalization: <?= ucfirst($fstatus ?? 'Not Finalized') ?></span>
                </div>
                <?php if ($gstatus === 'generated'): ?>
                    <button class="btn btn-danger ml-2" onclick="unlockReport()">🔓 Unlock Report</button>
                <?php endif; ?>

                <button class="btn btn-info" onclick="downloadPDF()">⬇ Download PDF</button>
                <button class="btn btn-primary ml-2" onclick="printReport()">🖨 Print</button>
            <?php endif; ?>
        </div>

        <div id="print-area">
            <?php if ($patient && $billing_id): ?>
                <?php foreach ($results_by_department as $dept => $tests): ?>
                    <?php
                    $test_chunks = array_chunk($tests, 10);
                    $total_chunks = count($test_chunks);
                    ?>
                    <?php foreach ($test_chunks as $chunk_index => $test_group): ?>
                        <div class="report-container">
                            <div class="watermark">Hemo Diagnostic Centre & Polyclinic</div>

                            <!-- Header Info -->
                            <div class="row mb-4 align-items-center" style="flex-wrap: nowrap;">
                                <div class="col-md-4 pr-2" style="font-size: 13px;">
                                    <strong>Patient Name:</strong> <?= $patient['name'] ?><br>
                                    <strong>Sex / Age:</strong> <?= $patient['gender'] ?>/<?= $patient['age'] ?><br>
                                    <?php if ($patient['gender'] === 'Female' && $patient['is_pregnant']): ?>
                                        <strong>Pregnancy Status:</strong> Pregnant (<?= $patient['gestational_weeks'] ?> weeks)<br>
                                    <?php endif; ?>
                                    <strong>Referred By:</strong> <?= $referrerName ?><br>
                                    <strong>Bill No:</strong> <?= 'HDC_' . $billing_id ?>

                                </div>

                                <div class="col-md-4 text-center px-1">
                                    <div class="barcode-wrapper">
                                        <svg class="barcode"
                                            jsbarcode-value="Bill: <?= $billing_id ?> | <?= $report_delivery ?>"
                                            jsbarcode-format="CODE128"
                                            jsbarcode-width="1.5"
                                            jsbarcode-height="40"
                                            jsbarcode-fontSize="10">
                                        </svg>
                                    </div>
                                </div>

                                <div class="col-md-4 text-right pl-2" style="font-size: 13px;">
                                    <div><strong>Booking On:</strong> <?= date('d-m-Y', strtotime($booking_on)) ?></div>
                                    
                                    <div><strong>Generated On:</strong>
  <?= $report_generated_on 
        ? date('d-m-Y', strtotime($report_generated_on))
        : '' 
     ?>
</div>



                                    <div><strong>Report Delivery:</strong> <?= date('d-m-Y', strtotime($report_delivery)) ?></div>
                                </div>
                            </div>

                            <!-- Test Table -->
                            <div class="report-body-content">
                                <h5 class="text-center text-uppercase mb-3"><?= $dept ?> Department</h5>
                                <table class="table test-table" style="border: none;">
                                    <thead>
                                        <tr style="border-bottom: 1px solid #999;">
                                            <th style="font-weight: 600; text-align: left;">INVESTIGATION</th>
                                            <th></th>
                                            <th style="font-weight: 600; text-align: left;">RESULT</th>
                                            <th style="font-weight: 600; text-align: left;">UNIT</th>
                                            <th style="font-weight: 600; text-align: left;">REFERENCE RANGE</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $current_category = null;
                                        
                                        foreach ($test_group as $t):
                                            $icon = '';
                                            $ref_display = 'N/A';
                                            $val = $t['result_value'];
                                            $method = $t['method'] ? "<span class='method-note'>Method: " . htmlspecialchars($t['method']) . "</span>" : '';

                                            // Fetch category name
                                            $categoryStmt = $conn->prepare("SELECT category_name FROM test_categories WHERE category_id = (SELECT category_id FROM tests WHERE test_id = ?)");
                                            $categoryStmt->bind_param("i", $t['test_id']);
                                            $categoryStmt->execute();
                                            $catRes = $categoryStmt->get_result();
                                            $category_name = $catRes->fetch_assoc()['category_name'] ?? 'General';
                                            $categoryStmt->close();

                                            if ($current_category !== $category_name):
                                                $current_category = $category_name;
                                        ?>
                                                <tr>
                                                    <td colspan="5" style="font-weight:bold; text-decoration: underline; padding-top: 12px;"><?= htmlspecialchars($category_name) ?></td>
                                                </tr>
                                            <?php endif;


                                            // Fetch any sub‐tests/components for this assignment
                                            $compStmt = $conn->prepare("
    SELECT component_label, `value`, evaluation_label
    FROM test_result_components
    WHERE assignment_id = ?
");
                                            $compStmt->bind_param("i", $t['assignment_id']);
                                            $compStmt->execute();
                                            $components = $compStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                                            $compStmt->close();

                                            if (count($components) > 0) {
                                                // 3a) Panel heading row
                                                echo "<tr class='panel-row'>
            <td colspan='5'><strong>"
                                                    . htmlspecialchars($t['test_name'])
                                                    . "</strong></td>
          </tr>";

                                                // 3b) Each component row
                                                foreach ($components as $c) {
                                                    $ref_display = render_reference_range_html(
                                                        $t['test_id'],
                                                        $patient,
                                                        $c['value'],
                                                        $c['component_label']
                                                    );
                                                    $val = htmlspecialchars($c['value']);
                                                    if (!empty($c['evaluation_label'])) {
                                                        $val .= " ({$c['evaluation_label']})";
                                                    }
                                                    echo "<tr>
                <td>&nbsp;&nbsp;&nbsp;– " . htmlspecialchars($c['component_label']) . "</td>
                <td>:</td>
                <td>{$val}</td>
                <td>" . htmlspecialchars($t['unit']) . "</td>
                <td>{$ref_display}</td>
              </tr>";
                                                }

                                                // Skip the rest of this loop and move to the next test
                                                continue;
                                            }
                                            // Fetch reference range
                                            // Replace your existing reference-range prepare() with this:
                                            $display_val = htmlspecialchars($t['result_value']);
                                            $icon        = '';
                                            // 1) Fetch *all* matching ranges, label rows first
                                            $stmt = $conn->prepare("
    SELECT range_type, condition_label,
           value_low, value_high, unit, flag_label
    FROM test_ranges
    WHERE test_id = ?
      AND (gender = ? OR gender = 'Any')
      AND (age_min IS NULL OR age_min <= ?)
      AND (age_max IS NULL OR age_max >= ?)
      AND (
          (gestation_min IS NULL AND gestation_max IS NULL)
          OR (? BETWEEN gestation_min AND gestation_max)
      )
    ORDER BY FIELD(range_type,'label','age_gender','gender','age','simple'),
             gestation_min DESC,
             age_min       DESC
  ");
                                            $stmt->bind_param(
                                                "isiii",
                                                $t['test_id'],
                                                $patient['gender'],
                                                $patient['age'],
                                                $patient['age'],
                                                $patient['gestational_weeks']
                                            );
                                            $stmt->execute();
                                            $ranges = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                                            $stmt->close();

                                            // 2) Build the display string
                                            // assume $val is numeric result, e.g.:
                                            $val = $t['result_value'];
                                            $ref_display = '';

                                            // first handle label‐type ranges that actually contain this value
                                            foreach ($ranges as $r) {
                                                if ($r['range_type'] !== 'label') continue;

                                                $low  = $r['value_low'];
                                                $high = $r['value_high'];

                                                // check if $val is in this bracket
                                                $matches = true;
                                                if ($low !== null  && $val <  $low)  $matches = false;
                                                if ($high !== null && $val >  $high) $matches = false;

                                                if ($matches) {
                                                    $ref_display .=
                                                        htmlspecialchars($r['condition_label']) . ': ' .
                                                        formatRange($low, $high) . ' (' .
                                                        htmlspecialchars($r['flag_label']) . ')';
                                                    // if you only ever want one label, break here
                                                    break;
                                                }
                                            }

                                            // if no label matched, fall back to the first non‐label
                                            if ($ref_display === '') {
                                                foreach ($ranges as $r) {
                                                    if ($r['range_type'] === 'label') continue;
                                                    $ref_display =
                                                        formatRange($r['value_low'], $r['value_high']) . ' ' .
                                                        htmlspecialchars($r['unit']);
                                                    break;
                                                }
                                            }

                                            // final fallback
                                            if ($ref_display === '') {
                                                $ref_display = 'N/A';
                                            }

                                            ?>

                                            <?php
    // simple (standalone) test
    $display_val = htmlspecialchars($t['result_value']);
    $icon        = '';
    $ref_display = render_reference_range_html(
        $t['test_id'],
        $patient,
        $t['result_value']
    );
?>
<tr>
    <td style="width:30%;font-weight:500;"><?= htmlspecialchars($t['test_name']) ?><?= $method ?></td>
    <td style="width:2%;font-weight:600">:</td>
    <td style="width:18%;"><?= $display_val ?> <?= $icon ?></td>
    <td style="width:15%;"><?= htmlspecialchars($t['unit']) ?></td>
    <td style="width:35%;"><?= $ref_display ?></td>
</tr>


                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Machine info (only on last chunk) -->
                            <?php if ($chunk_index === $total_chunks - 1 && !empty($machine_info_map[$dept])): ?>
                                <div class="mb-3 pl-2" style="text-align: left;">
                                    <strong>Instruments:</strong> <?= htmlspecialchars($machine_info_map[$dept]) ?>
                                </div>
                            <?php endif; ?>

                            <!-- Footer note (only on last chunk) -->

                            <div class="print-footer">
                                <?php
                                $doctor = null;
                                $qrText = "Patient: {$patient['name']} | ID: {$patient['patient_id']} | Bill: {$billing_id} | Report: " . date('d-m-Y', strtotime($report_generated_on));
                                if (!empty($patient['referred_by'])) {
                                    $stmt = $conn->prepare("SELECT name, qualification, specialization, reg_no FROM doctors WHERE doctor_id = ?");
                                    $stmt->bind_param("i", $patient['referred_by']);
                                    $stmt->execute();
                                    $doctor = $stmt->get_result()->fetch_assoc();
                                    $stmt->close();

                                    if ($doctor) {
                                        $qrText .= " | Referred By: {$doctor['name']}";
                                    }
                                }
                                ?>
                                <div class="footer-note row text-center align-items-top">
                                    <div class="col-md-3 text-center">
                                        <canvas id="qr-code-<?= $billing_id . '-' . md5($dept) ?>"></canvas>
                                    </div>
                                    <div class="col-md-3 text-left">
                                        <img src="uploads/signature.png" alt="Signature" style="max-height: 50px;"><br>
                                        <strong>Dr. Tirthankar Sarkar</strong><br>
                                        MBBS, MD (Path)<br>
                                        Consultant Pathologist<br>
                                        Reg.No. 64265
                                    </div>
                                    <div class="col-md-3 text-left">
                                        <?php if (!empty($lab_doctors[0])):
                                            $doc1 = $lab_doctors[0]; ?>
                                            <?php if ($doc1['is_treating_doctor']): ?>
                                                <img src="uploads/signature.png" alt="Signature" style="max-height: 50px;"><br>
                                            <?php endif; ?>
                                            <strong><?= htmlspecialchars($doc1['name']) ?></strong><br>
                                            <?= htmlspecialchars($doc1['qualification']) ?><br>
                                            <?= htmlspecialchars($doc1['specialization']) ?><br>
                                            Reg.No. <?= htmlspecialchars($doc1['reg_no']) ?>
                                        <?php else: ?>
                                            <em>No Lab Doctor</em>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-3 text-left">
                                        <?php if (!empty($lab_doctors[1])):
                                            $doc2 = $lab_doctors[1]; ?>
                                            <?php if ($doc2['is_treating_doctor']): ?>
                                                <img src="uploads/signature.png" alt="Signature" style="max-height: 50px;"><br>
                                            <?php endif; ?>
                                            <strong><?= htmlspecialchars($doc2['name']) ?></strong><br>
                                            <?= htmlspecialchars($doc2['qualification']) ?><br>
                                            <?= htmlspecialchars($doc2['specialization']) ?><br>
                                            Reg.No. <?= htmlspecialchars($doc2['reg_no']) ?>
                                        <?php else: ?>
                                            <em>No Lab Doctor</em>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>

            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/qrcode/build/qrcode.min.js"></script>
    <script>
        document.querySelectorAll("canvas[id^='qr-code-']").forEach(canvas => {
            const idParts = canvas.id.split('-');
            const baseId = idParts[0];
            const billingId = <?= json_encode($billing_id) ?>;
            const patientName = <?= json_encode($patient['name']) ?>;
            const patientId = <?= json_encode($patient['patient_id']) ?>;
            const reportDate = <?= json_encode(date('d-m-Y', strtotime($report_generated_on))) ?>;
            const doctorName = <?= json_encode($doctor['name'] ?? '') ?>;

            const qrText = `Patient: ${patientName} | ID: ${patientId} | Bill: ${billingId} | Report: ${reportDate}${doctorName ? ' | Referred By: ' + doctorName : ''}`;

            QRCode.toCanvas(canvas, qrText, {
                width: 80,
                margin: 1
            });
        });
    </script>


    <script>
        document.querySelectorAll(".barcode").forEach(svg => JsBarcode(svg).init());

        function downloadPDF() {
            const el = document.getElementById('print-area');
            html2pdf().set({
                margin: 0.5,
                filename: 'Patient_Report_<?= $patient_id ?>_Visit_<?= $billing_id ?>.pdf',
                image: {
                    type: 'jpeg',
                    quality: 1
                },
                html2canvas: {
                    scale: 2,
                    useCORS: true,
                    logging: true
                },
                jsPDF: {
                    unit: 'px',
                    format: [794, 1123],
                    orientation: 'portrait'
                },
                pagebreak: {
                    mode: ['css', 'legacy'],
                    avoid: ['.custom-footer']
                }
            }).from(el).save().then(() => {
                markReportAsFinished(<?= $billing_id ?>);
            });
        }

        function printReport() {
            const el = document.getElementById('print-area');
            html2pdf().set({
                margin: 0,
                filename: 'Patient_Report_<?= $patient_id ?>_Visit_<?= $billing_id ?>.pdf',
                image: {
                    type: 'jpeg',
                    quality: 1
                },
                html2canvas: {
                    scale: 2,
                    useCORS: true,
                    logging: true
                },
                jsPDF: {
                    unit: 'px',
                    format: [794, 1123],
                    orientation: 'portrait'
                },
                pagebreak: {
                    mode: ['css', 'legacy'],
                    avoid: ['.custom-footer']
                }
            }).from(el).toPdf().get('pdf').then(pdf => {
                const blobURL = URL.createObjectURL(pdf.output('blob'));
                const iframe = document.createElement('iframe');
                iframe.style.display = 'none';
                iframe.src = blobURL;
                document.body.appendChild(iframe);
                iframe.onload = () => {
                    setTimeout(() => {
                        iframe.contentWindow.print();
                        markReportAsFinished(<?= $billing_id ?>);
                    }, 500);
                };
            });
        }

        function markReportAsFinished(billingId) {
            fetch('mark_report_finished.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'billing_id=' + encodeURIComponent(billingId)
                })
                .then(response => response.text())
                .then(data => {
                    console.log('Status update:', data);
                })
                .catch(error => {
                    console.error('Status update failed:', error);
                });
        }
        // Function to unlock report for editing
        function unlockReport() {
            Swal.fire({
                title: 'Unlock Report?',
                text: "Do you want to unlock this finalized report for editing?",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, Unlock',
                cancelButtonText: 'Cancel',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('unlock_report_status.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: 'billing_id=' + encodeURIComponent(<?= $billing_id ?>)
                        })
                        .then(res => res.text())
                        .then(response => {
                            if (response.trim() === 'success') {
                                Swal.fire({
                                    title: 'Unlocked!',
                                    text: 'Report is now editable.',
                                    icon: 'success',
                                    timer: 2000,
                                    showConfirmButton: false
                                }).then(() => {
                                    window.location.reload();
                                });
                            } else {
                                Swal.fire('Error', 'Failed to unlock report.', 'error');
                            }
                        })
                        .catch(() => {
                            Swal.fire('Error', 'Something went wrong. Try again.', 'error');
                        });
                }
            });
        }
    </script>

    <?php include 'admin_footer.php'; ?>
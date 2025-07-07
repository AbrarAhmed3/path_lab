<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'admin_header.php';
include 'db.php';

$patient_id = isset($_GET['patient_id']) ? intval($_GET['patient_id']) : null;
$billing_id = isset($_GET['billing_id']) ? intval($_GET['billing_id']) : null;


// ─────────────────────────────────────────────────
// Payment-Due Banner
// ─────────────────────────────────────────────────
if ($billing_id) {
    $stmt = $conn->prepare("
        SELECT bstatus
          FROM billing
         WHERE billing_id = ?
         LIMIT 1
    ");
    $stmt->bind_param("i", $billing_id);
    $stmt->execute();
    $brow = $stmt->get_result()->fetch_assoc() ?? [];
    $stmt->close();

    if (strtolower($brow['bstatus'] ?? '') !== 'paid') {
        echo "
          <div class='alert alert-warning d-flex align-items-center my-4' role='alert'>
            <i class='fas fa-exclamation-triangle mr-2'></i>
            <div>
              <strong>Payment Due:</strong> Please pay the bill to generate the report.
            </div>
          </div>
        ";
        // If you want to block rendering the rest of the report, uncomment the next line:
        // exit;
    }
}


$patient = null;
$results_by_department = [];

/**
 * Helper: render the single reference range bracket for a test:
 * - treats NULL bounds as unbounded (< high or > low)
 * - always wraps the range in [ … ]
 * - omits any condition_label or component_label
 */
/**
 * Helper: format a low/high pair into “[ x – y ]” (or “< y” / “> x”).
 */
function format_bracket($low, $high)
{
    if ($low === null && $high === null) $inner = '-';
    elseif ($low === null)                   $inner = "< {$high}";
    elseif ($high === null)                  $inner = "> {$low}";
    elseif ($low == $high)                   $inner = "{$low}";
    else                                      $inner = "{$low} - {$high}";
    return "[ {$inner} ]";
}

/**
 * Render the reference-range HTML for a given test, patient, and value.
 * Always falls back to a static lookup if the value is out of bounds
 * or no matching “in-range” row exists.
 */
function render_reference_range_html($test_id, $patient, $value = null, $component_label = null)
{
    global $conn;

    // 0) Non-numeric results → no bracket
    if ($value !== null && !is_numeric($value)) {
        return '';
    }

    // 1) Pregnancy-specific range (female & pregnant, ignore component_label)
    if (
        $component_label === null &&
        !empty($patient['gestational_weeks']) &&
        strtolower($patient['gender']) === 'female'
    ) {
        $pq = $conn->prepare("
            SELECT value_low, value_high
              FROM test_ranges
             WHERE test_id    = ?
               AND range_type = 'pregnancy'
               AND ? BETWEEN gestation_min AND gestation_max
             ORDER BY gestation_min DESC
             LIMIT 1
        ");
        $pq->bind_param('id', $test_id, $patient['gestational_weeks']);
        $pq->execute();
        if ($r = $pq->get_result()->fetch_assoc()) {
            $pq->close();
            return format_bracket($r['value_low'], $r['value_high']);
        }
        $pq->close();
    }

    // 2) In-range lookup (component or general)
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
           AND (
               (value_low  IS NULL OR ? >= value_low)
            AND (value_high IS NULL OR ? <= value_high)
           )
    ";
    if ($component_label !== null) {
        $sql .= " AND range_type = 'component' AND condition_label = ? ";
    }
    $sql .= "
         ORDER BY
           FIELD(range_type,'label','component','age_gender','gender','age','simple'),
           gestation_min DESC,
           age_min       DESC
         LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if ($component_label !== null) {
        $stmt->bind_param(
            'isiiidds',
            $test_id,
            $patient['gender'],
            $patient['age'],
            $patient['age'],
            $patient['gestational_weeks'],
            $value,
            $value,
            $component_label
        );
    } else {
        $stmt->bind_param(
            'isiiidd',
            $test_id,
            $patient['gender'],
            $patient['age'],
            $patient['age'],
            $patient['gestational_weeks'],
            $value,
            $value
        );
    }
    $stmt->execute();
    if ($r = $stmt->get_result()->fetch_assoc()) {
        $stmt->close();
        return format_bracket($r['value_low'], $r['value_high']);
    }
    $stmt->close();

    // 3) Static fallback: first matching range without value filters
    $fbSql = "
        SELECT value_low, value_high
          FROM test_ranges
         WHERE test_id = ?
           AND (gender = ? OR gender = 'Any')
           AND (age_min IS NULL OR age_min <= ?)
           AND (age_max IS NULL OR age_max >= ?)
           AND (
               (gestation_min IS NULL AND gestation_max IS NULL)
            OR (? BETWEEN gestation_min AND gestation_max)
           )
           " . ($component_label !== null
        ? "AND range_type = 'component' AND condition_label = ?"
        : ""
    ) . "
         ORDER BY
           FIELD(range_type,'component','label','age_gender','gender','simple'),
           gestation_min DESC,
           age_min       DESC
         LIMIT 1
    ";

    $fb = $conn->prepare($fbSql);
    if ($component_label !== null) {
        $fb->bind_param(
            'isiiis',
            $test_id,
            $patient['gender'],
            $patient['age'],
            $patient['age'],
            $patient['gestational_weeks'],
            $component_label
        );
    } else {
        $fb->bind_param(
            'isiii',
            $test_id,
            $patient['gender'],
            $patient['age'],
            $patient['age'],
            $patient['gestational_weeks']
        );
    }
    $fb->execute();
    if ($f = $fb->get_result()->fetch_assoc()) {
        $fb->close();
        return format_bracket($f['value_low'], $f['value_high']);
    }
    $fb->close();

    // 4) No range found
    return '';
}


// ─────────────────────────────────────────────────
// Fetch metadata & booking/report dates
// ─────────────────────────────────────────────────
$booking_on = $report_generated_on = null;
if ($billing_id) {
    // Lab doctors
    $stmt = $conn->prepare("
        SELECT d.name, d.qualification, d.specialization, d.reg_no, r.is_treating_doctor
          FROM report_lab_doctors r
          JOIN doctors d ON r.doctor_id = d.doctor_id
         WHERE r.billing_id = ?
    ");
    $stmt->bind_param("i", $billing_id);
    $stmt->execute();
    $labdoc_res = $stmt->get_result();
    $lab_doctors = [];
    while ($row = $labdoc_res->fetch_assoc()) {
        $lab_doctors[] = $row;
    }
    $stmt->close();

    // Booking date
    $stmt = $conn->prepare("
        SELECT MIN(assigned_date) AS booking_on
          FROM test_assignments
         WHERE billing_id = ?
    ");
    $stmt->bind_param("i", $billing_id);
    $stmt->execute();
    $book_res   = $stmt->get_result();
    $booking_on = $book_res->fetch_assoc()['booking_on'] ?? null;
    $stmt->close();

    // Finalized date
    $stmt = $conn->prepare("
        SELECT finalized_on
          FROM billing
         WHERE billing_id = ?
    ");
    $stmt->bind_param("i", $billing_id);
    $stmt->execute();
    $rep_res             = $stmt->get_result();
    $report_generated_on = $rep_res->fetch_assoc()['finalized_on'] ?? null;
    $stmt->close();
}

// ─────────────────────────────────────────────────
// Patient & machine info + main results query
// ─────────────────────────────────────────────────
$report_delivery = date("Y-m-d");

if ($patient_id && $billing_id) {
    // Patient details
    $stmt1 = $conn->prepare("SELECT * FROM patients WHERE patient_id = ?");
    $stmt1->bind_param("i", $patient_id);
    $stmt1->execute();
    $p_res   = $stmt1->get_result();
    $patient = $p_res->fetch_assoc();
    $stmt1->close();

    // Machine info
    $stmt2 = $conn->prepare("
        SELECT department_name, machine_name
          FROM report_machine_info
         WHERE billing_id = ?
    ");
    $stmt2->bind_param("i", $billing_id);
    $stmt2->execute();
    $m_res            = $stmt2->get_result();
    $machine_info_map = [];
    while ($row = $m_res->fetch_assoc()) {
        $machine_info_map[$row['department_name']] = $row['machine_name'];
    }
    $stmt2->close();

    // Tests + results + unit
    $stmt3 = $conn->prepare("
        SELECT
          ta.assignment_id,
          tc.category_name,
          t.test_id,
          t.name            AS test_name,
          tr.result_value,
          (
            SELECT tr2.unit
              FROM test_ranges tr2
             WHERE tr2.test_id = t.test_id
               AND (tr2.gender = ? OR tr2.gender = 'Any')
               AND (tr2.age_min IS NULL OR tr2.age_min <= ?)
               AND (tr2.age_max IS NULL OR tr2.age_max >= ?)
               AND (
                 (tr2.gestation_min IS NULL AND tr2.gestation_max IS NULL)
                 OR (? BETWEEN tr2.gestation_min AND tr2.gestation_max)
               )
             ORDER BY
               (tr2.gender    <> 'Any') DESC,
               (tr2.age_min   IS NOT NULL) DESC,
               (tr2.gestation_min IS NOT NULL) DESC
             LIMIT 1
          ) AS unit,
          t.method,
          d.department_name
        FROM test_assignments AS ta
JOIN tests                AS t   ON ta.test_id      = t.test_id
LEFT JOIN test_results    AS tr  ON tr.assignment_id = ta.assignment_id
LEFT JOIN departments     AS d   ON t.department_id  = d.department_id
LEFT JOIN test_categories AS tc  ON ta.category_id  = tc.category_id
        WHERE ta.patient_id = ?
          AND ta.billing_id = ?
        ORDER BY d.department_name, t.name
    ");
    $stmt3->bind_param(
        "siiiii",
        $patient['gender'],
        $patient['age'],
        $patient['age'],
        $patient['gestational_weeks'],
        $patient_id,
        $billing_id
    );
    $stmt3->execute();
    $res3 = $stmt3->get_result();
    while ($row = $res3->fetch_assoc()) {
        $results_by_department[$row['department_name']][] = $row;
    }
    $stmt3->close();
}

// … right after your code that populates $results_by_department …

// Look for a “Widal Slide Agglutination Test” in Serology
$hasWidal = false;
if (isset($results_by_department['Serology'])) {
    foreach ($results_by_department['Serology'] as $t) {
        if (
            isset($t['category_name'])
            && $t['category_name'] === 'Widal Slide Agglutination Test'
        ) {
            $hasWidal = true;
            break;
        }
    }
}


// ─────────────────────────────────────────────────
// Utility: format simple ranges (unused by helper)
// ─────────────────────────────────────────────────
function formatRange($low, $high)
{
    if ($low === null && $high === null) return "N/A";
    if ($low === null)                    return "Up to $high";
    if ($high === null)                   return "Above $low";
    return $low == $high ? "$low" : "$low - $high";
}
?>

<!--
  ... your existing HTML rendering (print-area, tables, signatures, etc.) goes here ...
-->


<!DOCTYPE html>
<html>

<head>
    <title>Patient Report</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/qrcode/build/qrcode.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="report.css">
</head>

<body>
    <div class="container">
        <!-- Header and Filters -->
        <?php
        // ─── At the top of generate_report.php, before any HTML ───
        $selectedPatientText = '';
        if (!empty($_GET['patient_id'])) {
            $pid = (int)$_GET['patient_id'];
            $stmt = $conn->prepare("SELECT name FROM patients WHERE patient_id = ?");
            $stmt->bind_param("i", $pid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                $selectedPatientText = $row['name'] . " (ID: {$pid})";
            }
        }
        ?>


        <!-- ─── Your no-print block ─── -->
        <div class="no-print mt-4 mb-3 text-right">
            <form method="GET" class="form-inline mb-3" id="report-filter-form">
                <label class="mr-2">Patient:</label>

                <!-- Single search box with datalist -->
                <input
                    type="text"
                    id="patientSearch"
                    class="form-control mr-2"
                    placeholder="Type name or ID…"
                    list="patientList"
                    autocomplete="off"
                    style="min-width:250px;"
                    value="<?= htmlspecialchars($selectedPatientText) ?>" />
                <datalist id="patientList">
                    <?php
                    $pList = $conn->query("SELECT patient_id, name FROM patients ORDER BY name");
                    while ($p = $pList->fetch_assoc()) {
                        // option value is “Name (ID: 123)”, data-id holds the actual ID
                        $disp = htmlspecialchars($p['name'] . " (ID: {$p['patient_id']})");
                        echo "<option data-id='{$p['patient_id']}' value='{$disp}'></option>";
                    }
                    ?>
                </datalist>

                <!-- hidden field to carry the numeric patient_id -->
                <input
                    type="hidden"
                    id="patient_id"
                    name="patient_id"
                    value="<?= htmlspecialchars($_GET['patient_id'] ?? '') ?>" />

                <?php if (!empty($_GET['patient_id'])): ?>
                    <label class="ml-3 mr-2">Visit:</label>
                    <select name="billing_id" class="form-control" onchange="this.form.submit()">
                        <option value="">-- Select Visit --</option>
                        <?php
                        $visits = $conn->prepare("
          SELECT billing_id, billing_date
            FROM billing
           WHERE patient_id = ?
        ORDER BY billing_date DESC
        ");
                        $visits->bind_param("i", $_GET['patient_id']);
                        $visits->execute();
                        $result = $visits->get_result();
                        while ($v = $result->fetch_assoc()) {
                            $sel = ($v['billing_id'] == ($_GET['billing_id'] ?? '')) ? ' selected' : '';
                            $label = "Visit #{$v['billing_id']} – " . date('d M Y', strtotime($v['billing_date']));
                            echo "<option value='{$v['billing_id']}'{$sel}>{$label}</option>";
                        }
                        $visits->close();
                        ?>
                    </select>
                <?php endif; ?>
            </form>

            <?php if (!empty($patient) && !empty($billing_id)): ?>
                <?php
                // Fetch statuses & referring doctor...
                $status_stmt = $conn->prepare("
      SELECT fstatus, gstatus, referred_by
        FROM billing
       WHERE billing_id = ?
    ");
                $status_stmt->bind_param("i", $billing_id);
                $status_stmt->execute();
                $status_stmt->bind_result($fstatus, $gstatus, $referred_by_id);
                $status_stmt->fetch();
                $status_stmt->close();

                $referrerName = 'N/A';
                if ($referred_by_id) {
                    $d = $conn->prepare("SELECT name FROM doctors WHERE doctor_id = ?");
                    $d->bind_param("i", $referred_by_id);
                    $d->execute();
                    $tmp = $d->get_result()->fetch_assoc();
                    $d->close();
                    if (!empty($tmp['name'])) {
                        $referrerName = htmlspecialchars($tmp['name']);
                    }
                }
                ?>
                <div class="mb-3">
                    <span class="badge badge-dark">
                        📄 Report Status: <?= ucfirst($gstatus ?? 'Not Ready') ?>
                    </span>
                    <span class="badge badge-success">
                        ✅ Finalization: <?= ucfirst($fstatus ?? 'Not Finalized') ?>
                    </span>
                </div>
                <?php if ($gstatus === 'generated'): ?>
                    <button class="btn btn-danger ml-2" onclick="unlockReport()">🔓 Unlock Report</button>
                <?php endif; ?>

                <button class="btn btn-info" onclick="downloadPDF()">⬇ Download PDF</button>
                <button class="btn btn-primary ml-2" onclick="printReport()">🖨 Print</button>
            <?php endif; ?>
        </div>

        <script>
            // When you pick a suggestion from the datalist...
            document.getElementById('patientSearch').addEventListener('input', function() {
                const val = this.value;
                const opts = document.getElementById('patientList').options;
                for (let i = 0; i < opts.length; i++) {
                    if (opts[i].value === val) {
                        // set hidden ID, then submit
                        document.getElementById('patient_id').value = opts[i].dataset.id;
                        document.getElementById('report-filter-form').submit();
                        break;
                    }
                }
            });
        </script>

        <div id="print-area">
            <?php if ($patient && $billing_id): ?>
                <!-- widal test start -->
                <?php if ($hasWidal): ?>
                    <div class="report-container report-chunk">
                        <div class="watermark">HDCP</div>

                        <!-- ─── HEADER IDENTICAL TO OTHER DEPTS ─── -->
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
                                        jsbarcode-fontSize="10"></svg>
                                </div>
                            </div>
                            <div class="col-md-4 text-right pl-2" style="font-size: 13px;">
                                <div><strong>Booking On:</strong> <?= date('d-m-Y', strtotime($booking_on)) ?></div>
                                <div><strong>Generated On:</strong>
                                    <?= $report_generated_on ? date('d-m-Y', strtotime($report_generated_on)) : '' ?>
                                </div>
                                <div><strong>Report Delivery:</strong> <?= date('d-m-Y', strtotime($report_delivery)) ?></div>
                            </div>
                        </div>
                        <div>
                            <hr style="border-top: 1px solid black; width: 100%;">
                            <h5 class="text-center text-uppercase">SEROLOGY Department</h5>
                            <h6 class="text-center">WIDAL SLIDE AGGLUTINATION TEST</h6>

                        </div>

                        <!-- ─── DEPARTMENT TITLE + WIDAL TABLE ─── -->

                        <table class="table test-table" style="width:100%;border:none;">
                            <thead>
                                <tr>
                                    <th>ANTIGEN</th>
                                    <th>1:20</th>
                                    <th>1:40</th>
                                    <th>1:80</th>
                                    <th>1:160</th>
                                    <th>1:320</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // build your $widal array exactly as before…
                                $widal = [];
                                $stmt = $conn->prepare("
            SELECT t.name AS antigen,
                   tr.component_label,
                   tr.`value`
              FROM test_assignments ta
              JOIN tests t            ON ta.test_id      = t.test_id
              JOIN test_categories tc  ON ta.category_id  = tc.category_id
              JOIN test_result_components tr
                ON tr.assignment_id = ta.assignment_id
             WHERE ta.billing_id = ?
               AND tc.category_name = 'Widal Slide Agglutination Test'
             ORDER BY t.name, tr.id
          ");
                                $stmt->bind_param("i", $billing_id);
                                $stmt->execute();
                                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                                $stmt->close();
                                foreach ($rows as $r) {
                                    $widal[$r['antigen']][$r['component_label']] = $r['value'];
                                }
                                foreach ($widal as $antigen => $d):
                                ?>
                                    <tr>
                                        <td><?= htmlspecialchars($antigen) ?></td>
                                        <td> <?= htmlspecialchars($d['1:20']  ?? '-') ?></td>
                                        <td> <?= htmlspecialchars($d['1:40']  ?? '-') ?></td>
                                        <td> <?= htmlspecialchars($d['1:80']  ?? '-') ?></td>
                                        <td> <?= htmlspecialchars($d['1:160'] ?? '-') ?></td>
                                        <td> <?= htmlspecialchars($d['1:320'] ?? '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <p style="margin-top:20px;"><strong>NOTE :</strong> More than 1:80 dilution is significant.</p>
                        <p><strong>Reference Index :</strong><br>Agglutination is Seen = (+)<br>
                            Agglutination is Not Seen = (-)</p>

                        <!-- ─── OPTIONAL INSTRUMENT INFO ─── -->
                        <?php if (!empty($machine_info_map['Serology'])): ?>
                            <div class="mb-3 pl-2"><strong>Instruments:</strong>
                                <?= htmlspecialchars($machine_info_map['Serology']) ?>
                            </div>
                        <?php endif; ?>

                        <!-- ─── FOOTER SIGNATURES + QR ─── -->
                        <div class="print-footer">
                            <div class="footer-note row text-center align-items-top">
                                <div class="col-md-3 text-center">
                                    <canvas id="qr-code-<?= $billing_id ?>-serology"></canvas>
                                </div>

                                <div class="col-md-3 text-left">
                                    <img src="uploads/signature2.png" alt="Signature" style="max-height:50px;"><br>
                                    <strong>SABINA YEASMIN</strong><br>
                                    Medical Lab Technician
                                </div>

                                <?php
                                // —— Fetch dynamic signatures for lab and treating doctors ——
                                // ─── Fetch the selected doctors ───
                                // 1) Fetch all selected doctors for this report
                                $stmt = $conn->prepare("
                            SELECT rld.doctor_id,
                            rld.is_treating_doctor,
                             d.name,
                             d.qualification,
                             d.reg_no,
                             d.signature
                            FROM report_lab_doctors rld
                            JOIN doctors d ON d.doctor_id = rld.doctor_id
                            WHERE rld.billing_id = ?
                             ORDER BY rld.is_treating_doctor DESC, d.name ASC
                            ");
                                $stmt->bind_param("i", $billing_id);
                                $stmt->execute();
                                $res = $stmt->get_result();

                                $treated    = [];
                                $nonTreated = [];
                                while ($row = $res->fetch_assoc()) {
                                    if ((int)$row['is_treating_doctor'] === 1) {
                                        $treated[] = $row;
                                    } else {
                                        $nonTreated[] = $row;
                                    }
                                }
                                $stmt->close();

                                // 2) Decide which two slots get which doctor
                                $doc1 = null;
                                $doc2 = null;

                                if (count($treated) >= 2) {
                                    // Scenario 5: both treated → show first two treated
                                    $doc1 = $treated[0];
                                    $doc2 = $treated[1];
                                } elseif (count($treated) === 1) {
                                    // One was flagged “treated”
                                    $t = $treated[0];
                                    if (count($nonTreated) >= 1) {
                                        // Scenario 3 or 4: one treated + one not
                                        $doc1 = $nonTreated[0];
                                        $doc2 = $t;
                                    } else {
                                        // Scenario 2: only one doctor, and treated
                                        $doc1 = $t;
                                    }
                                } else {
                                    // No one treated
                                    if (count($nonTreated) >= 2) {
                                        // Scenario 6: two selected, neither treated
                                        $doc1 = $nonTreated[0];
                                        $doc2 = $nonTreated[1];
                                    } elseif (count($nonTreated) === 1) {
                                        // One selected, not treated → show details only
                                        $doc1 = $nonTreated[0];
                                    }
                                    // else Scenario 1: none selected → both null
                                }
                                ?>
                                <div class="col-md-3 text-left">
                                    <?php if ($doc1): ?>
                                        <?php if (!empty($doc1['signature']) && $doc1['is_treating_doctor']): ?>
                                            <img src="uploads/signatures/<?= htmlspecialchars($doc1['signature']) ?>"
                                                style="max-height:50px;display:block;margin-bottom:5px;">
                                        <?php endif; ?>
                                        <strong><?= htmlspecialchars($doc1['name']) ?></strong><br>
                                        <?= htmlspecialchars($doc1['qualification']) ?><br>
                                        Reg. No. <?= htmlspecialchars($doc1['reg_no']) ?>
                                    <?php endif; ?>
                                </div>

                                <div class="col-md-3 text-left">
                                    <?php if ($doc2): ?>
                                        <?php if (!empty($doc2['signature']) && $doc2['is_treating_doctor']): ?>
                                            <img src="uploads/signatures/<?= htmlspecialchars($doc2['signature']) ?>"
                                                style="max-height:50px;display:block;margin-bottom:5px;">
                                        <?php endif; ?>
                                        <strong><?= htmlspecialchars($doc2['name']) ?></strong><br>
                                        <?= htmlspecialchars($doc2['qualification']) ?><br>
                                        Reg. No. <?= htmlspecialchars($doc2['reg_no']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- widal -->

                <?php foreach ($results_by_department as $dept => $tests): ?>

                    <?php
                    // ─── Serology special case: drop Widal category, skip if empty ───
                    if ($dept === 'Serology') {
                        $tests = array_filter($tests, function ($t) {
                            return !(
                                isset($t['category_name']) &&
                                $t['category_name'] === 'Widal Slide Agglutination Test'
                            );
                        });
                        if (empty($tests)) {
                            continue;
                        }
                    }

                    // ─── Partition into profile‐categories vs uncategorized ───
                    $categories = [];
                    $otherTests = [];
                    foreach ($tests as $t) {
                        if (!empty($t['category_name'])) {
                            $categories[$t['category_name']][] = $t;
                        } else {
                            $otherTests[] = $t;
                        }
                    }
                    ?>

                    <!-- ─── One page per profile category ─── -->
                    <?php foreach ($categories as $catName => $catTests): ?>
                        <div class="report-container report-chunk">
                            <div class="watermark">HDCP</div>

                            <!-- Header Info -->
                            <div class="row mb-4 align-items-center" style="flex-wrap: nowrap;">
                                <div class="col-md-4 pr-2" style="font-size: 13px;">
                                    <strong>Patient Name:</strong> <?= htmlspecialchars($patient['name']) ?><br>
                                    <strong>Sex / Age:</strong> <?= htmlspecialchars($patient['gender']) ?>/<?= htmlspecialchars($patient['age']) ?><br>
                                    <?php if ($patient['gender'] === 'Female' && $patient['is_pregnant']): ?>
                                        <strong>Pregnancy Status:</strong> Pregnant (<?= htmlspecialchars($patient['gestational_weeks']) ?> weeks)<br>
                                    <?php endif; ?>
                                    <strong>Referred By:</strong> <?= htmlspecialchars($referrerName) ?><br>
                                    <strong>Bill No:</strong> <?= 'HDC_' . htmlspecialchars($billing_id) ?>
                                </div>
                                <div class="col-md-4 text-center px-1">
                                    <div class="barcode-wrapper">
                                        <svg class="barcode"
                                            jsbarcode-value="Bill: <?= htmlspecialchars($billing_id) ?> | <?= htmlspecialchars($report_delivery) ?>"
                                            jsbarcode-format="CODE128"
                                            jsbarcode-width="1.5"
                                            jsbarcode-height="40"
                                            jsbarcode-fontSize="10"></svg>
                                    </div>
                                </div>
                                <div class="col-md-4 text-right pl-2" style="font-size: 13px;">
                                    <div>
                                        <strong>Patient Id:</strong> <?= 'HPI_' . htmlspecialchars($patient_id) ?><br>
                                        <strong>Booking On:</strong> <?= date('d-m-Y', strtotime($booking_on)) ?>
                                    </div>
                                    <div>
                                        <strong>Generated On:</strong>
                                        <?= $report_generated_on ? date('d-m-Y', strtotime($report_generated_on)) : '' ?>
                                    </div>
                                    <div>
                                        <strong>Report Delivery:</strong> <?= date('d-m-Y', strtotime($report_delivery)) ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Test Table -->
                            <div class="report-body-content">
                                <h5 class="category-heading text-uppercase" style="background:#f5f5f5;padding:8px;font-weight:600; text-align:center;">
                                    <?= htmlspecialchars($dept) ?> Department
                                </h5>
                                <h6 class="text-center mb-3">Report on <?= htmlspecialchars($catName) ?></h6>

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
                                        <?php foreach ($catTests as $t): ?>
                                            <?php
                                            // 1) Fetch any sub-components
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
                                                // Panel header row
                                                echo "<tr class='panel-row'><td colspan='5'><strong>"
                                                    . htmlspecialchars($t['test_name'])
                                                    . "</strong></td></tr>";

                                                // Each sub-component, indented
                                                foreach ($components as $c) {
                                                    $ref_display = render_reference_range_html(
                                                        $t['test_id'],
                                                        $patient,
                                                        $c['value'],
                                                        $c['component_label']
                                                    );
                                                    $val = htmlspecialchars($c['value']);
                                                    if (!empty($c['evaluation_label'])) {
                                                        $val .= " (" . htmlspecialchars($c['evaluation_label']) . ")";
                                                    }
                                                    echo "<tr>
                    <td style=\"padding-left:2rem;\">"
                                                        . htmlspecialchars($c['component_label'])
                                                        . "</td>
                    <td>:</td>
                    <td>{$val}</td>
                    <td>" . htmlspecialchars($t['unit']) . "</td>
                    <td>{$ref_display}</td>
                  </tr>";
                                                }
                                                // Skip rendering this test as a standalone row
                                                continue;
                                            }

                                            // 2) Standalone test (no indent)
                                            $val = $t['result_value'];
                                            $display_val = htmlspecialchars($val);

                                            // Arrow-up/down logic
                                            $stmtRef = $conn->prepare("
      SELECT value_low, value_high
        FROM test_ranges
       WHERE test_id = ?
         AND (gender = ? OR gender = 'Any')
         AND (age_min IS NULL OR age_min <= ?)
         AND (age_max IS NULL OR age_max >= ?)
         AND (
             (gestation_min IS NULL AND gestation_max IS NULL)
          OR (? BETWEEN gestation_min AND gestation_max)
         )
       ORDER BY
         FIELD(range_type,'label','component','age_gender','gender','age','simple'),
         gestation_min DESC,
         age_min       DESC
       LIMIT 1
    ");
                                            $stmtRef->bind_param(
                                                "isiii",
                                                $t['test_id'],
                                                $patient['gender'],
                                                $patient['age'],
                                                $patient['age'],
                                                $patient['gestational_weeks']
                                            );
                                            $stmtRef->execute();
                                            $rr = $stmtRef->get_result()->fetch_assoc() ?: ['value_low' => null, 'value_high' => null];
                                            $stmtRef->close();

                                            if (is_numeric($val)) {
                                                if ($rr['value_high'] !== null && $val > $rr['value_high']) {
                                                    $display_val = "<strong>{$display_val} <i class='fas fa-arrow-up'></i></strong>";
                                                } elseif ($rr['value_low'] !== null && $val < $rr['value_low']) {
                                                    $display_val = "<strong>{$display_val} <i class='fas fa-arrow-down'></i></strong>";
                                                }
                                            }

                                            $ref_display = render_reference_range_html(
                                                $t['test_id'],
                                                $patient,
                                                $val
                                            );
                                            ?>
                                            <tr>
                                                <td style="font-weight:500;">
                                                    <?= htmlspecialchars($t['test_name']) ?>
                                                    <?= $t['method']
                                                        ? "<span class='method-note'>Method: " . htmlspecialchars($t['method']) . "</span>"
                                                        : '' ?>
                                                </td>
                                                <td style="font-weight:600;">:</td>
                                                <td><?= $display_val ?></td>
                                                <td><?= htmlspecialchars($t['unit']) ?></td>
                                                <td><?= $ref_display ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>

                                </table>
                            </div>

                            <!-- Machine info -->
                            <?php if (!empty($machine_info_map[$dept])): ?>
                                <div class="mb-3 pl-2" style="text-align: left;">
                                    <strong>Instruments:</strong> <?= htmlspecialchars($machine_info_map[$dept]) ?>
                                </div>
                            <?php endif; ?>

                            <!-- Footer & signatures -->
                            <?php
                            $stmt = $conn->prepare("
                SELECT rld.doctor_id,
                       rld.is_treating_doctor,
                       d.name,
                       d.qualification,
                       d.reg_no,
                       d.signature
                  FROM report_lab_doctors rld
                  JOIN doctors d ON d.doctor_id = rld.doctor_id
                 WHERE rld.billing_id = ?
              ORDER BY rld.is_treating_doctor DESC, d.name ASC
            ");
                            $stmt->bind_param("i", $billing_id);
                            $stmt->execute();
                            $res = $stmt->get_result();
                            $treated    = [];
                            $nonTreated = [];
                            while ($row = $res->fetch_assoc()) {
                                if ((int)$row['is_treating_doctor'] === 1) {
                                    $treated[] = $row;
                                } else {
                                    $nonTreated[] = $row;
                                }
                            }
                            $stmt->close();

                            $doc1 = $doc2 = null;
                            if (count($treated) >= 2) {
                                $doc1 = $treated[0];
                                $doc2 = $treated[1];
                            } elseif (count($treated) === 1) {
                                $t1 = $treated[0];
                                if (count($nonTreated) >= 1) {
                                    $doc1 = $nonTreated[0];
                                    $doc2 = $t1;
                                } else {
                                    $doc1 = $t1;
                                }
                            } else {
                                if (count($nonTreated) >= 2) {
                                    $doc1 = $nonTreated[0];
                                    $doc2 = $nonTreated[1];
                                } elseif (count($nonTreated) === 1) {
                                    $doc1 = $nonTreated[0];
                                }
                            }
                            ?>

                            <div class="print-footer">
                                <?php
                                $qrText = "Patient: {$patient['name']} | ID: {$patient['patient_id']} | Bill: {$billing_id} | Report: "
                                    . date('d-m-Y', strtotime($report_generated_on));
                                if (!empty($patient['referred_by'])) {
                                    $stmt2 = $conn->prepare("SELECT name FROM doctors WHERE doctor_id = ?");
                                    $stmt2->bind_param("i", $patient['referred_by']);
                                    $stmt2->execute();
                                    $ref = $stmt2->get_result()->fetch_assoc();
                                    $stmt2->close();
                                    if ($ref) {
                                        $qrText .= " | Referred By: {$ref['name']}";
                                    }
                                }
                                ?>
                                <div class="footer-note row text-center align-items-top">
                                    <div class="col-md-3 text-center">
                                        <canvas id="qr-code-<?= htmlspecialchars($billing_id . '-' . md5($dept)) ?>"></canvas>
                                    </div>
                                    <div class="col-md-3 text-left">
                                        <img src="uploads/signature2.png" alt="Signature" style="max-height: 50px;"><br>
                                        <strong>SABINA YEASMIN</strong><br>
                                        Medical Lab Technician<br>
                                    </div>
                                    <div class="col-md-3 text-left">
                                        <?php if ($doc1): ?>
                                            <?php if (!empty($doc1['signature']) && (int)$doc1['is_treating_doctor'] === 1): ?>
                                                <img
                                                    src="uploads/signatures/<?= htmlspecialchars($doc1['signature']) ?>"
                                                    alt="Signature of Dr. <?= htmlspecialchars($doc1['name']) ?>"
                                                    style="max-height:50px; margin-bottom:5px; display:block;">
                                            <?php endif; ?>
                                            <strong><?= htmlspecialchars($doc1['name']) ?></strong><br>
                                            <?= htmlspecialchars($doc1['qualification']) ?><br>
                                            Reg. No. <?= htmlspecialchars($doc1['reg_no']) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-3 text-left">
                                        <?php if ($doc2): ?>
                                            <?php if (!empty($doc2['signature']) && (int)$doc2['is_treating_doctor'] === 1): ?>
                                                <img
                                                    src="uploads/signatures/<?= htmlspecialchars($doc2['signature']) ?>"
                                                    alt="Signature of Dr. <?= htmlspecialchars($doc2['name']) ?>"
                                                    style="max-height:50px; margin-bottom:5px; display:block;">
                                            <?php endif; ?>
                                            <strong><?= htmlspecialchars($doc2['name']) ?></strong><br>
                                            <?= htmlspecialchars($doc2['qualification']) ?><br>
                                            Reg. No. <?= htmlspecialchars($doc2['reg_no']) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                        </div><!-- /.report-container -->
                    <?php endforeach; ?>

                    <!-- ─── Finally: uncategorized tests on their own page (if any) ─── -->
                    <?php if (!empty($otherTests)): ?>
    <?php
    // ─── 1) Build flat list of tests + component‐rows ───
    $rows = [];
    foreach ($otherTests as $t) {
        // Parent test row
        $rows[] = ['type'=>'test', 'data'=>$t];

        // Fetch any component‐rows
        $compStmt = $conn->prepare("
            SELECT component_label, `value`, evaluation_label
              FROM test_result_components
             WHERE assignment_id = ?
        ");
        $compStmt->bind_param("i", $t['assignment_id']);
        $compStmt->execute();
        $components = $compStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $compStmt->close();

        if (!empty($components)) {
            foreach ($components as $c) {
                // Carry along unit & test_id for ref‐range & unit display
                $c['unit']    = $t['unit'];
                $c['test_id'] = $t['test_id'];
                $rows[] = ['type'=>'component', 'data'=>$c];
            }
        }
    }

    // ─── 2) Chunk into pages of max 10 rows each ───
    $maxRowsPerPage = 10;
    $pageChunks     = array_chunk($rows, $maxRowsPerPage);
    ?>

    <?php foreach ($pageChunks as $pageIndex => $chunk): ?>
        <div class="report-container report-chunk">
            <div class="watermark">HDCP</div>

            <!-- Header Info -->
            <div class="row mb-4 align-items-center" style="flex-wrap: nowrap;">
                <div class="col-md-4 pr-2" style="font-size: 13px;">
                    <strong>Patient Name:</strong> <?= htmlspecialchars($patient['name']) ?><br>
                    <strong>Sex / Age:</strong> <?= htmlspecialchars($patient['gender']) ?>/<?= htmlspecialchars($patient['age']) ?><br>
                    <?php if ($patient['gender'] === 'Female' && $patient['is_pregnant']): ?>
                        <strong>Pregnancy Status:</strong> Pregnant (<?= htmlspecialchars($patient['gestational_weeks']) ?> weeks)<br>
                    <?php endif; ?>
                    <strong>Referred By:</strong> <?= htmlspecialchars($referrerName) ?><br>
                    <strong>Bill No:</strong> <?= 'HDC_' . htmlspecialchars($billing_id) ?>
                </div>
                <div class="col-md-4 text-center px-1">
                    <div class="barcode-wrapper">
                        <svg class="barcode"
                             jsbarcode-value="Bill: <?= htmlspecialchars($billing_id) ?> | <?= htmlspecialchars($report_delivery) ?>"
                             jsbarcode-format="CODE128"
                             jsbarcode-width="1.5"
                             jsbarcode-height="40"
                             jsbarcode-fontSize="10"></svg>
                    </div>
                </div>
                <div class="col-md-4 text-right pl-2" style="font-size: 13px;">
                    <div>
                        <strong>Patient Id:</strong> <?= 'HPI_' . htmlspecialchars($patient_id) ?><br>
                        <strong>Booking On:</strong> <?= date('d-m-Y', strtotime($booking_on)) ?>
                    </div>
                    <div>
                        <strong>Generated On:</strong>
                        <?= $report_generated_on ? date('d-m-Y', strtotime($report_generated_on)) : '' ?>
                    </div>
                    <div>
                        <strong>Report Delivery:</strong> <?= date('d-m-Y', strtotime($report_delivery)) ?>
                    </div>
                </div>
            </div>

            <!-- Other Tests Table -->
            <div class="report-body-content">
                <h5 class="text-center text-uppercase mb-3"style="background:#f5f5f5;padding:8px;font-weight:600;"><?= htmlspecialchars($dept) ?> Department</h5>
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
                        <?php foreach ($chunk as $row): ?>
                            <?php if ($row['type'] === 'test'): 
                                $t = $row['data'];
                                // Standalone test value & ref‐range
                                $val         = $t['result_value'];
                                $display_val = htmlspecialchars($val);

                                // check high/low arrows
                                $rrStmt = $conn->prepare("
                                    SELECT value_low, value_high
                                      FROM test_ranges
                                     WHERE test_id = ?
                                       AND (gender = ? OR gender = 'Any')
                                       AND (age_min IS NULL OR age_min <= ?)
                                       AND (age_max IS NULL OR age_max >= ?)
                                       AND (
                                            (gestation_min IS NULL AND gestation_max IS NULL)
                                            OR (? BETWEEN gestation_min AND gestation_max)
                                       )
                                     ORDER BY
                                       FIELD(range_type,'label','component','age_gender','gender','age','simple'),
                                       gestation_min DESC,
                                       age_min       DESC
                                     LIMIT 1
                                ");
                                $rrStmt->bind_param(
                                    "isiii",
                                    $t['test_id'],
                                    $patient['gender'],
                                    $patient['age'],
                                    $patient['age'],
                                    $patient['gestational_weeks']
                                );
                                $rrStmt->execute();
                                $rr = $rrStmt->get_result()->fetch_assoc() 
                                      ?: ['value_low'=>null,'value_high'=>null];
                                $rrStmt->close();

                                if (is_numeric($val)) {
                                    if ($rr['value_high'] !== null && $val > $rr['value_high']) {
                                        $display_val = "<strong>{$display_val} <i class='fas fa-arrow-up'></i></strong>";
                                    } elseif ($rr['value_low'] !== null && $val < $rr['value_low']) {
                                        $display_val = "<strong>{$display_val} <i class='fas fa-arrow-down'></i></strong>";
                                    }
                                }

                                $ref_display = render_reference_range_html($t['test_id'], $patient, $val);
                            ?>
                                <tr>
                                    <td style="padding-left:2rem;font-weight:500;">
                                        <strong><?= htmlspecialchars($t['test_name']) ?></strong>
                                        <?= $t['method']
                                            ? "<span class='method-note'>Method: " . htmlspecialchars($t['method']) . "</span>"
                                            : '' ?>
                                    </td>
                                    <td style="font-weight:600;">:</td>
                                    <td><?= $display_val ?></td>
                                    <td><?= htmlspecialchars($t['unit']) ?></td>
                                    <td><?= $ref_display ?></td>
                                </tr>
                            <?php else: 
                                $c = $row['data'];
                                $val = htmlspecialchars($c['value']);
                                if (!empty($c['evaluation_label'])) {
                                    $val .= " (" . htmlspecialchars($c['evaluation_label']) . ")";
                                }
                                $ref_display = render_reference_range_html(
                                    $c['test_id'], $patient, $c['value'], $c['component_label']
                                );
                            ?>
                                <tr>
                                    <td style="padding-left:4rem;"><?= htmlspecialchars($c['component_label']) ?></td>
                                    <td>:</td>
                                    <td><?= $val ?></td>
                                    <td><?= htmlspecialchars($c['unit']) ?></td>
                                    <td><?= $ref_display ?></td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Machine info -->
            <?php if (!empty($machine_info_map[$dept])): ?>
                <div class="mb-3 pl-2" style="text-align: left;">
                    <strong>Instruments:</strong> <?= htmlspecialchars($machine_info_map[$dept]) ?>
                </div>
            <?php endif; ?>

            <!-- Footer & signatures -->
            <?php
            // Fetch and decide doc1/doc2 as before
            $stmt = $conn->prepare("
                SELECT rld.doctor_id,
                       rld.is_treating_doctor,
                       d.name,
                       d.qualification,
                       d.reg_no,
                       d.signature
                  FROM report_lab_doctors rld
                  JOIN doctors d ON d.doctor_id = rld.doctor_id
                 WHERE rld.billing_id = ?
                 ORDER BY rld.is_treating_doctor DESC, d.name ASC
            ");
            $stmt->bind_param("i", $billing_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $treated = []; $nonTreated = [];
            while ($row = $res->fetch_assoc()) {
                if ((int)$row['is_treating_doctor'] === 1) {
                    $treated[] = $row;
                } else {
                    $nonTreated[] = $row;
                }
            }
            $stmt->close();

            // Decide doc1/doc2 slots
            $doc1 = $doc2 = null;
            if (count($treated) >= 2) {
                $doc1 = $treated[0]; $doc2 = $treated[1];
            } elseif (count($treated) === 1) {
                $doc1 = $nonTreated[0] ?? null;
                $doc2 = $treated[0];
            } else {
                if (count($nonTreated) >= 2) {
                    $doc1 = $nonTreated[0]; $doc2 = $nonTreated[1];
                } elseif (count($nonTreated) === 1) {
                    $doc1 = $nonTreated[0];
                }
            }
            ?>
            <div class="print-footer">
                <?php
                // Build QR text
                $qrText = "Patient: {$patient['name']} | ID: {$patient['patient_id']} | Bill: {$billing_id} | Report: "
                        . date('d-m-Y', strtotime($report_generated_on));
                if (!empty($patient['referred_by'])) {
                    $stmt = $conn->prepare("
                        SELECT name FROM doctors WHERE doctor_id = ?
                    ");
                    $stmt->bind_param("i", $patient['referred_by']);
                    $stmt->execute();
                    $refDoc = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($refDoc) {
                        $qrText .= " | Referred By: {$refDoc['name']}";
                    }
                }
                ?>
                <div class="footer-note row text-center align-items-top">
                    <div class="col-md-3 text-center">
                        <canvas id="qr-code-<?= $billing_id . '-' . md5($dept) ?>"></canvas>
                    </div>
                    <div class="col-md-3 text-left">
                        <img src="uploads/signature2.png" alt="Signature" style="max-height:50px;"><br>
                        <strong>SABINA YEASMIN</strong><br>
                        Medical Lab Technician<br>
                    </div>
                    <div class="col-md-3 text-left">
                        <?php if ($doc1): ?>
                            <?php if (!empty($doc1['signature']) && (int)$doc1['is_treating_doctor'] === 1): ?>
                                <img src="uploads/signatures/<?= htmlspecialchars($doc1['signature']) ?>"
                                     alt="Signature of Dr. <?= htmlspecialchars($doc1['name']) ?>"
                                     style="max-height:50px;margin-bottom:5px;display:block;">
                            <?php endif; ?>
                            <strong><?= htmlspecialchars($doc1['name']) ?></strong><br>
                            <?= htmlspecialchars($doc1['qualification']) ?><br>
                            Reg. No. <?= htmlspecialchars($doc1['reg_no']) ?>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-3 text-left">
                        <?php if ($doc2): ?>
                            <?php if (!empty($doc2['signature']) && (int)$doc2['is_treating_doctor'] === 1): ?>
                                <img src="uploads/signatures/<?= htmlspecialchars($doc2['signature']) ?>"
                                     alt="Signature of Dr. <?= htmlspecialchars($doc2['name']) ?>"
                                     style="max-height:50px;margin-bottom:5px;display:block;">
                            <?php endif; ?>
                            <strong><?= htmlspecialchars($doc2['name']) ?></strong><br>
                            <?= htmlspecialchars($doc2['qualification']) ?><br>
                            Reg. No. <?= htmlspecialchars($doc2['reg_no']) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>


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
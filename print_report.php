<?php
// print_report.php

// 0) Composer + session + DB
require_once __DIR__ . '/vendor/autoload.php';

use Mpdf\Mpdf;

session_start();
if (!isset($_SESSION['admin_logged_in'])) {
  header('Location: admin_login.php');
  exit;
}
include_once __DIR__ . '/db.php';

// 1) Read inputs
$patient_id = (int) ($_GET['patient_id'] ?? 0);
$billing_id = (int) ($_GET['billing_id'] ?? 0);
if (!$patient_id || !$billing_id) {
  echo "Missing patient or billing ID.";
  exit;
}

// ─────────────────────────────────────────────────
// 2) Copy *all* your generate_report.php data logic here
//    (queries for $patient, $referrerName, $booking_on, $report_generated_on,
//     $results_by_department, $hasWidal, $machine_info_map, signatures, etc.)
// ─────────────────────────────────────────────────

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
  if ($low === null && $high === null)
    $inner = '-';
  elseif ($low === null)
    $inner = "< {$high}";
  elseif ($high === null)
    $inner = "> {$low}";
  elseif ($low == $high)
    $inner = "{$low}";
  else
    $inner = "{$low} - {$high}";
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
  $book_res = $stmt->get_result();
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
  $rep_res = $stmt->get_result();
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
  $p_res = $stmt1->get_result();
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
  $m_res = $stmt2->get_result();
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
        ORDER BY d.department_name, ta.sort_order ASC
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
  if ($low === null && $high === null)
    return "N/A";
  if ($low === null)
    return "Up to $high";
  if ($high === null)
    return "Above $low";
  return $low == $high ? "$low" : "$low - $high";
}

// ─────────────────────────────────────────────────
// 3) Instantiate mPDF & set a **repeating header**
// ─────────────────────────────────────────────────
$mpdf = new \Mpdf\Mpdf([
  'mode' => 'utf-8',
  'format' => 'A4',
  'margin_top' => 64,  // enough to clear the header
  'margin_header' => 47,  // reserve 40mm for your patient block + barcode
  'margin_footer' => 19,
  'margin_left' => 8,
  'margin_right' => 8,
  'margin_bottom' => 38,
]);

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



// Build the HTML for your patient‐info + barcode block:
$barcodeText = "ID:{$patient_id}"
  . "|BN:{$billing_id}";
$headerHtml = '
<table width="100%" style="font-family:Courier,Helvetica,Times-Roman;font-size:10pt;border:none">
  <tr>
    <td width="42%" valign="top">
      Patient Name :<strong> ' . htmlspecialchars($patient['name']) . '</strong><br>
      Sex / Age : ' . htmlspecialchars($patient['gender']) . '/' . htmlspecialchars($patient['age']) . '<br>'
  . (
    $patient['gender'] === 'Female' && $patient['is_pregnant']
    ? 'Pregnancy Status: Pregnant (' . $patient['gestational_weeks'] . ' wks)<br>'
    : ''
  ) . '
      Referred By : ' . htmlspecialchars($referrerName) . '<br>
      Bill No :<strong> HDC_' . $billing_id . '</strong>
      </td>
<td width="25%" align="center" valign="middle">
      <barcode
        code="' . $barcodeText . '"
        type="C128B"
        size="1"
        height="1"
        text="0"
        style="width:20mm; height:10mm;"
      />
      <div style="font-size:8pt; margin-top:4pt;">
        Hemo Diagnostic Centre & Polyclinic
      </div>
    </td>
    <td width="33%" valign="top" align="right">
      Patient Id:<strong> HPI_' . $patient_id . '</strong><br>
      Booking On: ' . date('d-m-Y', strtotime($booking_on)) . '<br>
      Generated On: ' . ($report_generated_on ? date('d-m-Y', strtotime($report_generated_on)) : '-') . '<br>
      Report Delivery: ' . date('d-m-Y', strtotime($report_delivery)) . '
    </td>
  </tr>
</table>';

$mpdf->SetHTMLHeader($headerHtml);

// ─── Build repeating footer ─────────────────────────────────────
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
$treated = [];
$nonTreated = [];
while ($row = $res->fetch_assoc()) {
  if ((int) $row['is_treating_doctor'] === 1) {
    $treated[] = $row;
  } else {
    $nonTreated[] = $row;
  }
}
$stmt->close();

// Decide doc1/doc2 slots
$doc1 = $doc2 = null;
if (count($treated) >= 2) {
  $doc1 = $treated[0];
  $doc2 = $treated[1];
} elseif (count($treated) === 1) {
  $doc1 = $nonTreated[0] ?? null;
  $doc2 = $treated[0];
} else {
  if (count($nonTreated) >= 2) {
    $doc1 = $nonTreated[0];
    $doc2 = $nonTreated[1];
  } elseif (count($nonTreated) === 1) {
    $doc1 = $nonTreated[0];
  }
}

// … after you’ve determined $doc1 and $doc2 …
// … after you’ve determined $doc1 and $doc2 …

$qrText = "Patient: " . htmlspecialchars($patient['name'])
        . " | ID: {$patient_id}"
        . " | Bill: {$billing_id}"
        . " | Report: " . date('d-m-Y', strtotime($report_generated_on));

$footerHtml = '
<table width="100%" cellpadding="4" cellspacing="0"
       style="margin-top:8pt;
              font-family:Courier,Helvetica,Times-Roman;
              font-size:10pt;
              page-break-inside:avoid;
              border-collapse:collapse;">
  <tr>
    <!-- QR code -->
    <td width="25%" align="center" valign="top">
      <barcode
        code="'. $qrText .'"
        type="QR"
        size="0.9"
        text="0"
        disableborder="1"
        style="width:20mm; height:20mm;"
      />
    </td>

    <!-- Lab Technician -->
    <td width="25%" align="center" valign="top">
      <img src="uploads/signature2.png" style="max-height:40px;"><br>
      <strong>SABINA YEASMIN</strong><br>
      Medical Lab Technician
    </td>';

// ─── Doctor #1 slot ─────────────────────────────
if ($doc1) {
    $footerHtml .= '
    <td width="25%" align="center" valign="top">';
    // only show signature image if treating doctor
    if ((int)$doc1['is_treating_doctor'] === 1 && $doc1['signature']) {
        $footerHtml .= '
      <img src="uploads/signatures/'.htmlspecialchars($doc1['signature']).'"
           style="max-height:40px; display:block; margin:0 auto 4px;"><br>';
    }
    else{
       $footerHtml .= '
      <img src="uploads/nontreated.png"
           style="max-height:40px; display:block; margin:0 auto 4px;"><br>';
    }
    $footerHtml .= '
      <strong>'.htmlspecialchars(strtoupper($doc1['name'])).'</strong><br>
      '.htmlspecialchars($doc1['qualification']).'<br>
      Reg. No. '.htmlspecialchars($doc1['reg_no']).'
    </td>';
} else {
    // empty cell if no doctor 1
    $footerHtml .= '
    <td width="25%"></td>';
}

// ─── Doctor #2 slot ─────────────────────────────
if ($doc2) {
    $footerHtml .= '
    <td width="25%" align="center" valign="top">';
    if ((int)$doc2['is_treating_doctor'] === 1 && $doc2['signature']) {
        $footerHtml .= '
      <img src="uploads/signatures/'.htmlspecialchars($doc2['signature']).'"
           style="max-height:40px; display:block; margin:0 auto 4px;"><br>';
    }
    else{
       $footerHtml .= '
      <img src="uploads/nontreated.png"
           style="max-height:40px; display:block; margin:0 auto 4px;"><br>';
    }
    $footerHtml .= '
      <strong>'.htmlspecialchars(strtoupper($doc2['name'])).'</strong><br>
      '.htmlspecialchars($doc2['qualification']).'<br>
      Reg. No. '.htmlspecialchars($doc2['reg_no']).'
    </td>';
} else {
    // empty cell if no doctor 2
    $footerHtml .= '
    <td width="25%"></td>';
}

$footerHtml .= '
  </tr>
</table>';

$mpdf->SetHTMLFooter($footerHtml);




// ─────────────────────────────────────────────────
// 4) Buffer *only* the contents of your <div id="print-area">…</div>
// ─────────────────────────────────────────────────
ob_start();
?>

<div id="print-area">
  <!-- Paste exactly what you had in generate_report.php between this div -->
  <?php if ($patient && $billing_id): ?>
    <!-- widal test start -->
    <?php if ($hasWidal): ?>
      <div class="report-container report-chunk " style="page-break-after: always;">

        <!-- ─── HEADER IDENTICAL TO OTHER DEPTS ─── -->
        <!-- Header Info -->
        <?php
        // ─── Department header strip ─────────────────────────
        echo '
    <div style="
        width:100%;
        background:#f5f5f5;
        padding:3px 0;
        font-family:Calibri,Arial,sans-serif;
        font-size:14pt;
        font-weight:600;
        text-align:center;
        margin-bottom:3pt;
    ">
      DEPARTMENT OF SEROLOGY
    </div>';

        // ─── “Report on …” subtitle ───────────────────────────
        echo '
    <div style="
        width:100%;
        font-family:Arial,sans-serif;
        font-size:12pt;
        text-align:center;
        margin-bottom:12pt;
    ">
      WIDAL SLIDE AGGLUTINATION TEST
    </div>';
        ?>

        <!-- ─── DEPARTMENT TITLE + WIDAL TABLE ─── -->
        <table width="100%" cellpadding="4" cellspacing="0" style="
      border-collapse: collapse;
      font-family: Arial, sans-serif;
      font-size: 10pt;
      margin-top: 8pt;
      margin-bottom: 12pt;
    ">
          <thead>
            <tr style="background: #f0f0f0;">
              <th style="border: 1px solid #ccc; text-align: left; width:30%;">Antigen</th>
              <th style="border: 1px solid #ccc; text-align: center; width:14%;">1:20</th>
              <th style="border: 1px solid #ccc; text-align: center; width:14%;">1:40</th>
              <th style="border: 1px solid #ccc; text-align: center; width:14%;">1:80</th>
              <th style="border: 1px solid #ccc; text-align: center; width:14%;">1:160</th>
              <th style="border: 1px solid #ccc; text-align: center; width:14%;">1:320</th>
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
       ORDER BY ta.sort_order ASC, tr.id
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
                <td style="border: 1px solid #ddd; padding: 4px;">
                  <?= htmlspecialchars($antigen) ?>
                </td>
                <td style="border: 1px solid #ddd; text-align: center;">
                  <?= htmlspecialchars($d['1:20'] ?? '-') ?>
                </td>
                <td style="border: 1px solid #ddd; text-align: center;">
                  <?= htmlspecialchars($d['1:40'] ?? '-') ?>
                </td>
                <td style="border: 1px solid #ddd; text-align: center;">
                  <?= htmlspecialchars($d['1:80'] ?? '-') ?>
                </td>
                <td style="border: 1px solid #ddd; text-align: center;">
                  <?= htmlspecialchars($d['1:160'] ?? '-') ?>
                </td>
                <td style="border: 1px solid #ddd; text-align: center;">
                  <?= htmlspecialchars($d['1:320'] ?? '-') ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>


        <p class="mb-3 pl-2" style="margin-top:20px; font-size: 12px; margin-bottom:0px"><strong>NOTE :</strong> More than
          1:80 dilution is significant.<br><br><strong>Reference Index :</strong><br>Agglutination is Seen = (+)<br>
          Agglutination is Not Seen = (-)<br><br><strong>Impression :</strong> Rising titre in subsequent weeks are
          considered as affirmative.<br><br><strong>Remarks :</strong>Widal test becomes positive in the 2nd Week of typhoid
          fever and and even later - a rising titre would be diagnostic. But blood culture is more confirmative.</p>

        <!-- ─── OPTIONAL INSTRUMENT INFO ─── -->
        <?php if (!empty($machine_info_map['Serology'])): ?>
          <div class="mb-3 pl-2 machine_info_text" style="font-size: 12px;"><strong>Instruments:</strong>
            <?= htmlspecialchars($machine_info_map['Serology']) ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- widal end -->

    <?php foreach ($results_by_department as $dept => $tests): ?>

      <?php
      // ─── Serology special case: drop Widal category ───
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

      // ─── Partition into profile categories vs uncategorized ───
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

      <?php foreach ($categories as $catName => $catTests): ?>

        <?php
        // 1) Build flat list of tests + component rows
        $rows = [];
        foreach ($catTests as $t) {
          // ─── fetch components exactly as you already do ───
          $compStmt = $conn->prepare("
        SELECT component_label, `value`, evaluation_label
          FROM test_result_components
         WHERE assignment_id = ?
    ");
          $compStmt->bind_param("i", $t['assignment_id']);
          $compStmt->execute();
          $components = $compStmt->get_result()->fetch_all(MYSQLI_ASSOC);
          $compStmt->close();

          // ─── record whether this parent has components ───
          $hasComponents = !empty($components);
          $rows[] = [
            'type' => 'test',
            'data' => $t,
            'has_components' => $hasComponents
          ];

          // ─── then push each component as before ───
          if ($hasComponents) {
            foreach ($components as $c) {
              $c['unit'] = $t['unit'];
              $c['test_id'] = $t['test_id'];
              $rows[] = ['type' => 'component', 'data' => $c];
            }
          }
        }


        // 2) Chunk into pages of max 23 rows
        $maxRowsPerPage = 23;
        $pageChunks = array_chunk($rows, $maxRowsPerPage);
        ?>

        <?php foreach ($pageChunks as $pageIndex => $chunk): ?>

          <?php
          // 3) Detect if this entire chunk is text-only (no numeric results)
          $allText = true;
          foreach ($chunk as $row) {
            if ($row['type'] === 'test' && is_numeric($row['data']['result_value'])) {
              $allText = false;
              break;
            }
          }
          ?>
          <div class="report-container report-chunk" style="page-break-after: always;">
            <div class="report-body-content">
              <?php
              // ─── Department header strip ─────────────────────────
              echo '
    <div style="
        width:100%;
        background:#f5f5f5;
        padding:3px 0;
        font-family:Calibri;
        text-transform: uppercase;
        font-size:14pt;
        font-weight:700;
        text-align:center;
        margin-bottom:1pt;
    ">
      Department of ' . htmlspecialchars($dept) . '
    </div>';

              // ─── “Report on …” subtitle ───────────────────────────
              echo '
    <div style="
        width:100%;
        font-family:Calibri;
        font-size:11pt;
        text-align:center;
        margin-bottom:6pt;
    ">
      Report on ' . htmlspecialchars($catName ?? '') . '
    </div>';

              // ─── Now your existing table loop, e.g. ────────────
              ?>
              <table width="100%" cellpadding="3" cellspacing="0" style="
      border-collapse: collapse;
      font-family: Arial, sans-serif;
      font-size: 10pt;
    ">
                <thead>
                  <tr style="
       border-bottom:1px solid #999;
       font-family: 'Arial Black', Gadget, sans-serif;
       font-weight: 700;
     ">
                    <th style="font-size:9pt; text-align:left; width:39%;">INVESTIGATION</th>
                    <th style="width:5%;"></th>
                    <th style="font-size:9pt; text-align:left; width:24%;">RESULT</th>
                    <th style="font-size:9pt; text-align:left; width:14%;">UNIT</th>
                    <th style="font-size:9pt; text-align:left; width:18%;">REFERENCE RANGE</th>
                  </tr>
                </thead>

                <tbody>
                  <?php foreach ($chunk as $row): ?>

                    <?php if ($row['type'] === 'test'):
                      $t = $row['data'];
                      $hasComponents = $row['has_components'] ?? false;
                      $showValues = !$hasComponents;
                      $val = $t['result_value'];
                      $display_val = $showValues ? htmlspecialchars($val) : '';

                      if ($showValues) {
                        // ─── Arrow logic ───
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
                   FIELD(range_type,'label','component','age','gender','simple'),
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
                        $rr = $rrStmt->get_result()->fetch_assoc() ?: ['value_low' => null, 'value_high' => null];
                        $rrStmt->close();

                        if (is_numeric($val)) {
                          if ($rr['value_high'] !== null && $val > $rr['value_high']) {
                            $display_val = "<strong>{$display_val}</strong> ⬆";
                          } elseif ($rr['value_low'] !== null && $val < $rr['value_low']) {
                            $display_val = "<strong>{$display_val}</strong> ⬇";
                          }
                        }

                        $ref_display = render_reference_range_html($t['test_id'], $patient, $val);
                      } else {
                        $ref_display = '';
                      }
                      ?>
                      <tr>
                        <td style="font-weight:500; vertical-align: top;">
                          <div style="font-size: 10pt;">
                            <?= htmlspecialchars($t['test_name']) ?>
                          </div>
                          <?php if (!empty($t['method'])): ?>
                            <div style="font-weight:normal; font-size:8pt; margin-top:2px;">
                              Method: <?= htmlspecialchars($t['method']) ?>
                            </div>
                          <?php endif; ?>
                        </td>

                        <td style="text-align:left; font-weight:600;">
                          <?= ($showValues && !$allText && is_numeric($val)) ? ':' : '' ?>
                        </td>
                        <td style="text-align:left; font-weight:600;"><?= $display_val ?></td>
                        <?php if (!$allText): ?>
                          <td style="text-align:left;"><?= $showValues ? htmlspecialchars($t['unit']) : '' ?></td>
                          <td style="text-align:left;"><?= $showValues ? $ref_display : '' ?></td>
                        <?php endif; ?>
                      </tr>

                    <?php else:
                      // ─── component row ───
                      $c = $row['data'];
                      $val = htmlspecialchars($c['value']);
                      // if (! empty($c['evaluation_label'])) {
                      //     $val .= " (" . htmlspecialchars($c['evaluation_label']) . ")";
                      // }
                      $ref_display = render_reference_range_html(
                        $c['test_id'],
                        $patient,
                        $c['value'],
                        $c['component_label']
                      );
                      ?>
                      <tr>
                        <td style="padding-left:2rem; font-size: 9pt;">
                          <?= htmlspecialchars($c['component_label']) ?>
                        </td>
                        <td style="text-align:left; font-weight:600;">:</td>
                        <td style="text-align:left; font-weight:600;"><?= $val ?></td>
                        <?php if (!$allText): ?>
                          <td style="text-align:left;"><?= htmlspecialchars($c['unit']) ?></td>
                          <td style="text-align:left;"><?= $ref_display ?></td>
                        <?php endif; ?>
                      </tr>
                    <?php endif; ?>

                  <?php endforeach; ?>



                </tbody>
              </table>
            </div>

            <!-- Machine info -->
            <?php
            // ─── Optional instrument info ─────────────────────────
            if (!empty($machine_info_map[$dept])) {
              echo '<div style="
              font-family:Arial,sans-serif;
              font-size:9pt;
              margin-top:6pt;
              ">
              <strong>Instrument:</strong> '
                . htmlspecialchars($machine_info_map[$dept]) .
                '</div>';
            }
            ?>

          </div><!-- /.report-container -->
        <?php endforeach; // end pageChunks 
              ?>

      <?php endforeach; // end categories 
          ?>

      <!-- ─── Finally: uncategorized tests on their own page (if any) ─── -->
      <?php if (!empty($otherTests)): ?>
        <?php
        // ─── 1) Build flat list of tests + component‐rows ───
        $rows = [];
        $seenAssignments = [];

        foreach ($otherTests as $t) {
          if (in_array($t['assignment_id'], $seenAssignments)) {
            continue;
          }
          $seenAssignments[] = $t['assignment_id'];

          // fetch components
          $compStmt = $conn->prepare("
            SELECT component_label, `value`, evaluation_label
              FROM test_result_components
             WHERE assignment_id = ?
        ");
          $compStmt->bind_param("i", $t['assignment_id']);
          $compStmt->execute();
          $components = $compStmt->get_result()->fetch_all(MYSQLI_ASSOC);
          $compStmt->close();

          $hasComponents = !empty($components);

          // push the test row once
          $rows[] = [
            'type' => 'test',
            'data' => $t,
            'has_components' => $hasComponents
          ];

          // then push each component
          if ($hasComponents) {
            foreach ($components as $c) {
              $c['unit'] = $t['unit'];
              $c['test_id'] = $t['test_id'];
              $rows[] = [
                'type' => 'component',
                'data' => $c
              ];
            }
          }
        }

        // ─── 2) Chunk into pages ───
        $maxRowsPerPage = 18;
        $pageChunks = array_chunk($rows, $maxRowsPerPage);
        ?>

        <?php foreach ($pageChunks as $pageIndex => $chunk): ?>
          <div class="report-container report-chunk" style="page-break-after: always;">
            <!-- header omitted for brevity… -->

            <?php
            // ─── 0) Before rendering this chunk, see if any row has a numeric result ───
            $allText = true;
            foreach ($chunk as $row) {
              // grab the value field depending on type
              $val = $row['type'] === 'component'
                ? $row['data']['value']
                : $row['data']['result_value'];
              if (is_numeric($val)) {
                $allText = false;
                break;
              }
            }
            ?>


            <div class="report-body-content">
              <?php
              // ─── Department header strip ─────────────────────────
              echo '
    <div style="
        width:100%;
        background:#f5f5f5;
        padding:3px 0;
        font-family:Calibri;
        text-transform: uppercase;
        font-size:14pt;
        font-weight:600;
        text-align:center;
        margin-bottom:2pt;
    ">
      Department of ' . htmlspecialchars($dept) . '
    </div>';

              // ─── Now your existing table loop, e.g. ─────────────
      
              ?>
              <table width="100%" cellpadding="3" cellspacing="0" style="
      border-collapse: collapse;
      font-family: Arial, sans-serif;
      font-size: 10pt;
    ">
                <thead>
                  <tr style="
       border-bottom:1px solid #999;
       font-family: 'Arial Black', Gadget, sans-serif;
       font-weight: 700;
     ">
                    <!-- Investigation col -->
                    <th style="font-size:9pt; text-align:left; width:39%;">INVESTIGATION</th>
                    <th style="width:5%;"></th>
                    <th style="font-size:9pt; text-align:left; width:24%;">RESULT</th>
                    <!-- only show these when there’s at least one numeric value -->
                    <?php if (!$allText): ?>
                      <th style="font-size:9pt; text-align:left; width:14%;">UNIT</th>
                      <th style="font-size:9pt; text-align:left; width:18%;">REFERENCE RANGE</th>
                    <?php endif; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($chunk as $row): ?>
                    <?php if ($row['type'] === 'test'):
                      $t = $row['data'];
                      $hasComponents = $row['has_components'] ?? false;
                      $showValues = !$hasComponents;

                      // prepare display_val + ref_display
                      $val = $t['result_value'];
                      $display_val = htmlspecialchars($val);
                      if ($showValues) {
                        // fetch range & decorate arrows…
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
                        $rr = $rrStmt->get_result()->fetch_assoc() ?: ['value_low' => null, 'value_high' => null];
                        $rrStmt->close();

                        if (is_numeric($val)) {
                          if ($rr['value_high'] !== null && $val > $rr['value_high']) {
                            $display_val = "<strong>{$display_val}</strong> ⬆";
                          } elseif ($rr['value_low'] !== null && $val < $rr['value_low']) {
                            $display_val = "<strong>{$display_val}</strong> ⬇";
                          }
                        }

                        $ref_display = render_reference_range_html($t['test_id'], $patient, $val);
                      } else {
                        $display_val = '';
                        $ref_display = '';
                      }
                      ?>
                      <tr>
                        <td style="font-weight:500; vertical-align: top;">
                          <div style="font-size:10pt;">
                            <?= htmlspecialchars($t['test_name']) ?>
                          </div>
                          <?php if (!empty($t['method'])): ?>
                            <div style="font-weight:normal; font-size:8pt; margin-top:2px;">
                              Method: <?= htmlspecialchars($t['method']) ?>
                            </div>
                          <?php endif; ?>
                        </td>


                        <!-- only show the colon when we have a value -->
                        <?php if ($showValues): ?>
                          <td style="text-align:left; font-weight:600;">:</td>
                        <?php else: ?>
                          <td></td>
                        <?php endif; ?>

                        <td style="text-align:left; font-weight:600;"><?= $display_val ?></td>
                        <td style="text-align:left;"><?= $showValues ? htmlspecialchars($t['unit']) : '' ?></td>
                        <td style="text-align:left;"><?= $ref_display ?></td>
                      </tr>
                    <?php else:
                      $c = $row['data'];
                      $val = htmlspecialchars($c['value']);
                      // if (!empty($c['evaluation_label'])) {
                      //     $val .= " (" . htmlspecialchars($c['evaluation_label']) . ")";
                      // }
                      $ref_display = render_reference_range_html(
                        $c['test_id'],
                        $patient,
                        $c['value'],
                        $c['component_label']
                      );
                      ?>
                      <tr>
                        <td style="padding-left:3rem; font-size:9pt;"><?= htmlspecialchars($c['component_label']) ?></td>
                        <td style="text-align:left; font-weight:600;">:</td>
                        <td style="text-align:left; font-weight:600;"><?= $val ?></td>
                        <td style="text-align:left;"><?= htmlspecialchars($c['unit']) ?></td>
                        <td style="text-align:left;"><?= $ref_display ?></td>
                      </tr>
                    <?php endif; ?>
                  <?php endforeach; ?>


                </tbody>
              </table>
            </div>

            <!-- Machine info -->
            <?php
            // ─── Optional instrument info ─────────────────────────
            if (!empty($machine_info_map[$dept])) {
              echo '<div style="
              font-family:Arial,sans-serif;
              font-size:9pt;
              margin-top:6pt;
              ">
              <strong>Instrument:</strong> '
                . htmlspecialchars($machine_info_map[$dept]) .
                '</div>';
            }
            ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

</div>
<?php
$html = ob_get_clean();

// 5) Write & output PDF
$mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);
$dest = isset($_GET['download'])
  ? \Mpdf\Output\Destination::DOWNLOAD
  : \Mpdf\Output\Destination::INLINE;

$mpdf->Output("Patient_report_ID_{$patient_id}_BN_{$billing_id}.pdf", $dest);
exit;

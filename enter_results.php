<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'admin_header.php';
include 'db.php';

$selected_patient_id = $_GET['patient_id'] ?? null;
$selected_billing_id = $_GET['billing_id'] ?? null;
$billing_status = null;

$patients = $conn->query("SELECT DISTINCT p.patient_id, p.name FROM patients p JOIN test_assignments ta ON p.patient_id = ta.patient_id ORDER BY p.name");

$billing_status = null;
$rstatus = 'not_started';
$gstatus = 'not_ready';

if ($selected_billing_id) {
    $stmt = $conn->prepare("SELECT bstatus, rstatus, gstatus FROM billing WHERE billing_id = ?");
    $stmt->bind_param("i", $selected_billing_id);
    $stmt->execute();
    $stmt->bind_result($billing_status, $rstatus, $gstatus);
    $stmt->fetch();
    $stmt->close();
}

$progress_percent = 0;

if ($selected_billing_id) {
    $stmt = $conn->prepare("
    SELECT
      COUNT(*) AS total_tests,
      SUM(
        CASE
          WHEN tr.result_value IS NOT NULL AND TRIM(tr.result_value) <> '' THEN 1
          WHEN EXISTS (
            SELECT 1
            FROM test_result_components tc
            WHERE tc.assignment_id = ta.assignment_id
              AND TRIM(tc.value) <> ''
          ) THEN 1
          ELSE 0
        END
      ) AS filled_tests
    FROM test_assignments ta
    LEFT JOIN test_results tr ON ta.assignment_id = tr.assignment_id
    WHERE ta.billing_id = ?
");

    $stmt->bind_param("i", $selected_billing_id);
    $stmt->execute();
    $stmt->bind_result($total_tests, $filled_tests);
    $stmt->fetch();
    $stmt->close();

    if ($total_tests > 0) {
        $progress_percent = round(($filled_tests / $total_tests) * 100);
    }
}




if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_results'])) {
    // Step 1: Check if report is finalized
    $statusStmt = $conn->prepare("SELECT gstatus FROM billing WHERE billing_id = ?");
    $statusStmt->bind_param("i", $selected_billing_id);
    $statusStmt->execute();
    $statusRow = $statusStmt->get_result()->fetch_assoc();
    $statusStmt->close();

    if ($statusRow && $statusRow['gstatus'] === 'generated') {
        echo "<script>
            alert('❌ This report has been finalized and cannot be edited.');
            window.location.href = 'enter_results.php?patient_id={$selected_patient_id}&billing_id={$selected_billing_id}';
        </script>";
        exit;
    }

    // Step 2: Loop through each assignment and save/update results
    foreach ($_POST['results'] as $assignment_id => $data) {
        $value = isset($data['result_value']) ? trim($data['result_value']) : '';
        $remarks = isset($data['remarks']) ? trim($data['remarks']) : '';
        $evaluation = null;

        $infoStmt = $conn->prepare("
            SELECT ta.test_id, p.gender, p.age, p.is_pregnant, p.gestational_weeks
            FROM test_assignments ta
            JOIN patients p ON ta.patient_id = p.patient_id
            WHERE ta.assignment_id = ?
        ");
        $infoStmt->bind_param("i", $assignment_id);
        $infoStmt->execute();
        $testData = $infoStmt->get_result()->fetch_assoc();
        $infoStmt->close();

        $test_id = $testData['test_id'];
        $gender = $testData['gender'];
        $age = $testData['age'];
        $is_pregnant = (int)$testData['is_pregnant'];
        $gest_weeks = $is_pregnant ? (int)$testData['gestational_weeks'] : null;

        // --- Handle Component-Based Results ---
        if (isset($data['components']) && is_array($data['components'])) {
            foreach ($data['components'] as $component_label => $comp_value) {
                $comp_value = trim($comp_value);
                $comp_eval = null;

                $rangeStmt = $conn->prepare("
                    SELECT * FROM test_ranges
                    WHERE test_id = ? AND range_type = 'component' AND condition_label = ?
                      AND (gender = ? OR gender = 'Any')
                      AND (age_min IS NULL OR age_min <= ?)
                      AND (age_max IS NULL OR age_max >= ?)
                ");
                $rangeStmt->bind_param("issii", $test_id, $component_label, $gender, $age, $age);
                $rangeStmt->execute();
                $rangeResult = $rangeStmt->get_result();
                $rangeStmt->close();

                while ($r = $rangeResult->fetch_assoc()) {
                    if (is_numeric($comp_value)) {
                        if ($comp_value < $r['value_low']) $comp_eval = 'Low';
                        elseif ($comp_value > $r['value_high']) $comp_eval = 'High';
                        else $comp_eval = 'Normal';
                    }
                }

                // Save or update component result
                $checkComp = $conn->prepare("SELECT id FROM test_result_components WHERE assignment_id = ? AND component_label = ?");
                $checkComp->bind_param("is", $assignment_id, $component_label);
                $checkComp->execute();
                $checkComp->store_result();

                if ($checkComp->num_rows > 0) {
                    $updateComp = $conn->prepare("UPDATE test_result_components SET value = ?, evaluation_label = ? WHERE assignment_id = ? AND component_label = ?");
                    $updateComp->bind_param("ssis", $comp_value, $comp_eval, $assignment_id, $component_label);
                    $updateComp->execute();
                    $updateComp->close();
                } else {
                    $insertComp = $conn->prepare("INSERT INTO test_result_components (assignment_id, component_label, value, evaluation_label) VALUES (?, ?, ?, ?)");
                    $insertComp->bind_param("isss", $assignment_id, $component_label, $comp_value, $comp_eval);
                    $insertComp->execute();
                    $insertComp->close();
                }

                $checkComp->close();
            }
        }

        // --- Handle Main Result Value ---
        if (is_numeric($value)) {
            $rangeStmt = $conn->prepare("
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
                ORDER BY range_type = 'label' DESC, gestation_min DESC, age_min DESC
            ");
            $rangeStmt->bind_param("isiii", $test_id, $gender, $age, $age, $gest_weeks);
            $rangeStmt->execute();
            $rangeResult = $rangeStmt->get_result();
            $rangeStmt->close();

            while ($r = $rangeResult->fetch_assoc()) {
                if ($r['range_type'] === 'label') {
                    if ($value >= $r['value_low'] && (is_null($r['value_high']) || $value <= $r['value_high'])) {
                        $evaluation = "{$r['condition_label']} - {$r['flag_label']}";
                        break;
                    }
                } else {
                    if ($value < $r['value_low']) $evaluation = 'Low';
                    elseif ($value > $r['value_high']) $evaluation = 'High';
                    else $evaluation = 'Normal';
                    break;
                }
            }
        }

        // --- Insert/Update Result Entry ---
        $checkStmt = $conn->prepare("SELECT result_id FROM test_results WHERE assignment_id = ?");
        $checkStmt->bind_param("i", $assignment_id);
        $checkStmt->execute();
        $checkStmt->store_result();

        if ($checkStmt->num_rows > 0) {
            $updateStmt = $conn->prepare("UPDATE test_results SET result_value = ?, remarks = ?, evaluation_label = ? WHERE assignment_id = ?");
            $updateStmt->bind_param("sssi", $value, $remarks, $evaluation, $assignment_id);
            $updateStmt->execute();
            $updateStmt->close();
        } elseif (!empty($value)) {
            $insertStmt = $conn->prepare("INSERT INTO test_results (assignment_id, result_value, remarks, evaluation_label) VALUES (?, ?, ?, ?)");
            $insertStmt->bind_param("isss", $assignment_id, $value, $remarks, $evaluation);
            $insertStmt->execute();
            $insertStmt->close();
        }

        $checkStmt->close();
    }

    // Step 3: Update rstatus based on completion
    $check_all_stmt = $conn->prepare("
    SELECT
      COUNT(*) AS total_tests,
      SUM(
        CASE
          WHEN tr.result_value IS NOT NULL AND TRIM(tr.result_value) <> '' THEN 1
          WHEN EXISTS (
            SELECT 1
            FROM test_result_components tc
            WHERE tc.assignment_id = ta.assignment_id
              AND TRIM(tc.value) <> ''
          ) THEN 1
          ELSE 0
        END
      ) AS filled_tests
    FROM test_assignments ta
    LEFT JOIN test_results tr ON ta.assignment_id = tr.assignment_id
    WHERE ta.billing_id = ?
");

    $check_all_stmt->bind_param("i", $selected_billing_id);
    $check_all_stmt->execute();
    $counts = $check_all_stmt->get_result()->fetch_assoc();
    $check_all_stmt->close();

    $rstatus = 'not_started';
    if ($counts['filled_tests'] > 0 && $counts['filled_tests'] < $counts['total_tests']) {
        $rstatus = 'partial';
    } elseif ($counts['filled_tests'] == $counts['total_tests']) {
        $rstatus = 'complete';
    }

    $update_status_stmt = $conn->prepare("UPDATE billing SET rstatus = ? WHERE billing_id = ?");
    $update_status_stmt->bind_param("si", $rstatus, $selected_billing_id);
    $update_status_stmt->execute();
    $update_status_stmt->close();

    // Step 4: Show success message
    echo <<<JS
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    Swal.fire({
        icon: 'success',
        title: '✅ Results saved successfully!',
        showConfirmButton: false,
        timer: 2000
    }).then(() => {
        window.location.href = 'enter_results.php?patient_id={$selected_patient_id}&billing_id={$selected_billing_id}';
    });
    </script>
    JS;
    exit;
}

$visits = [];
if ($selected_patient_id) {
    $v = $conn->prepare("SELECT billing_id, billing_date FROM billing WHERE patient_id = ? ORDER BY billing_date DESC");
    $v->bind_param("i", $selected_patient_id);
    $v->execute();
    $visits = $v->get_result();
}

$tests_to_fill = [];
if ($selected_patient_id && $selected_billing_id) {
    $stmt = $conn->prepare("
        SELECT 
            ta.assignment_id, ta.test_id,
            t.name AS test_name,
            tr.result_value, tr.remarks,
            p.age, p.gender, p.is_pregnant, p.gestational_weeks
        FROM test_assignments ta
        JOIN tests t ON ta.test_id = t.test_id
        LEFT JOIN test_results tr ON ta.assignment_id = tr.assignment_id
        JOIN patients p ON ta.patient_id = p.patient_id
        WHERE ta.patient_id = ? AND ta.billing_id = ?
        ORDER BY t.name
    ");
    $stmt->bind_param("ii", $selected_patient_id, $selected_billing_id);
    $stmt->execute();
    $tests_to_fill = $stmt->get_result();
}
?>
<!DOCTYPE html>
<html>

<head>
    <title>Enter Test Results</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        body {
            background-color: #f8f9fa;
        }

        .form-section {
            background: white;
            padding: 20px;
            border-radius: 5px;
        }

        .form-header {
            margin-bottom: 30px;
        }

        .table td input {
            width: 100%;
        }

        .select2-container .select2-selection--single {
            height: 38px;
            padding: 6px 12px;
        }
    </style>
</head>

<body>
    <div class="container mt-5">
        <div class="form-section shadow">
            <h4 class="form-header"><i class="fas fa-vials"></i> Enter Test Results</h4>

            <form method="GET" class="form-inline mb-4">
                <label class="mr-2"><strong>Select Patient</strong></label>
                <select name="patient_id" id="patient-select" class="form-control mr-3" required onchange="this.form.submit()">
                    <option value="">-- Select Patient --</option>
                    <?php while ($p = $patients->fetch_assoc()) { ?>
                        <option value="<?= $p['patient_id'] ?>" <?= ($selected_patient_id == $p['patient_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['name']) ?> (ID: <?= $p['patient_id'] ?>)
                        </option>
                    <?php } ?>
                </select>

                <?php if ($visits && $visits->num_rows > 0): ?>
                    <label class="mr-2"><strong>Visit</strong></label>
                    <select name="billing_id" class="form-control" onchange="this.form.submit()" required>
                        <option value="">-- Select Visit --</option>
                        <?php while ($v = $visits->fetch_assoc()) { ?>
                            <option value="<?= $v['billing_id'] ?>" <?= ($selected_billing_id == $v['billing_id']) ? 'selected' : '' ?>>
                                Visit ID: <?= $v['billing_id'] ?> (<?= date('d-M-Y', strtotime($v['billing_date'])) ?>)
                            </option>
                        <?php } ?>
                    </select>
                <?php endif; ?>
            </form>

            <?php
            // Map badge colors
            $r_badge = match ($rstatus) {
                'complete'     => 'badge-success',
                'partial'      => 'badge-warning',
                'not_started'  => 'badge-secondary',
                default        => 'badge-light'
            };

            $g_badge = match ($gstatus) {
                'generated'    => 'badge-danger',
                'ready'        => 'badge-info',
                'not_ready'    => 'badge-secondary',
                default        => 'badge-light'
            };
            ?>
            <div class="mb-3">
                <span class="badge <?= $r_badge ?>">🧪 Results: <?= ucfirst($rstatus) ?></span>
                <span class="badge <?= $g_badge ?>">📄 Report: <?= ucfirst(str_replace('_', ' ', $gstatus)) ?></span>
            </div>
            <?php if ($selected_billing_id && $total_tests > 0): ?>
                <div class="mb-4">
                    <label><strong>Result Entry Progress</strong></label>
                    <div class="progress" style="height: 25px;">
                        <div class="progress-bar <?= $progress_percent == 100 ? 'bg-success' : 'bg-info' ?>"
                            role="progressbar"
                            style="width: <?= $progress_percent ?>%;"
                            aria-valuenow="<?= $progress_percent ?>"
                            aria-valuemin="0"
                            aria-valuemax="100">
                            <?= $progress_percent ?>%
                        </div>
                    </div>
                </div>
            <?php endif; ?>



            <?php if ($selected_billing_id && $selected_patient_id): ?>
                <?php if ($gstatus === 'generated'): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-lock"></i> This report is marked as <strong>generated</strong>. Results are read-only.
                    </div>
                <?php endif; ?>

                <?php if ($tests_to_fill instanceof mysqli_result && $tests_to_fill->num_rows > 0): ?>
                    <form method="POST">
                        <input type="hidden" name="save_results" value="1">
                        <table class="table table-bordered">
                            <thead class="thead-dark">
                                <tr>
                                    <th>Test Name</th>
                                    <th>Reference Range</th>
                                    <th>Result Value</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $tests_to_fill->fetch_assoc()) {
                                    $assignment_id = $row['assignment_id'];
                                    $test_id = $row['test_id'];
                                    $value = $row['result_value'];
                                    $ref_display = 'N/A';
                                    $highlight = '';

                                    $rangeStmt = $conn->prepare("
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
                                ORDER BY range_type = 'label' DESC, gestation_min DESC, age_min DESC
                            ");
                                    $rangeStmt->bind_param("isiii", $test_id, $row['gender'], $row['age'], $row['age'], $row['gestational_weeks']);
                                    $rangeStmt->execute();
                                    $rangeResult = $rangeStmt->get_result();

                                    if (is_numeric($value)) {
                                        while ($r = $rangeResult->fetch_assoc()) {
                                            if ($r['range_type'] === 'label') {
                                                if ($value >= $r['value_low'] && (is_null($r['value_high']) || $value <= $r['value_high'])) {
                                                    // Improved range display formatting

                                                    if (!is_null($r['value_low']) && !is_null($r['value_high'])) {
                                                        $ref_display = htmlspecialchars("{$r['value_low']} - {$r['value_high']} {$r['unit']} ({$r['condition_label']} - {$r['flag_label']})");
                                                    } elseif (is_null($r['value_low']) && !is_null($r['value_high'])) {
                                                        $ref_display = htmlspecialchars("< {$r['value_high']} {$r['unit']} ({$r['condition_label']} - {$r['flag_label']})");
                                                    } elseif (!is_null($r['value_low']) && is_null($r['value_high'])) {
                                                        $ref_display = htmlspecialchars("> {$r['value_low']} {$r['unit']} ({$r['condition_label']} - {$r['flag_label']})");
                                                    } else {
                                                        $ref_display = htmlspecialchars("{$r['unit']} ({$r['condition_label']} - {$r['flag_label']})");
                                                    }



                                                    if (str_contains(strtolower($r['flag_label']), 'high')) $highlight = "style='color:red; font-weight:bold;'";
                                                    elseif (str_contains(strtolower($r['flag_label']), 'low')) $highlight = "style='color:blue; font-weight:bold;'";
                                                    else $highlight = "style='color:green; font-weight:bold;'";
                                                    break;
                                                }
                                            } else {
                                                $ref_display = "{$r['value_low']} - {$r['value_high']} {$r['unit']}";
                                                if ($value < $r['value_low']) $highlight = "style='color:blue; font-weight:bold;'";
                                                elseif ($value > $r['value_high']) $highlight = "style='color:red; font-weight:bold;'";
                                                else $highlight = "style='color:green; font-weight:bold;'";
                                                break;
                                            }
                                        }
                                    } else {
                                        // fall back to the first 'simple' or 'label' range
                                        $r = $rangeResult->fetch_assoc();
                                        if ($r['range_type'] === 'label') {
                                            $ref_display = "{$r['value_low']} - {$r['value_high']} {$r['unit']} ({$r['condition_label']} - {$r['flag_label']})";
                                        } else {
                                            $ref_display = "{$r['value_low']} - {$r['value_high']} {$r['unit']}";
                                        }
                                    }

                                    $rangeStmt->close();
                                ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['test_name']) ?></td>
                                        <td><?= $ref_display ?></td>
                                        <td><?php
                                            $compStmt = $conn->prepare("SELECT * FROM test_ranges WHERE test_id = ? AND range_type = 'component'");
                                            $compStmt->bind_param("i", $test_id);
                                            $compStmt->execute();
                                            $compRanges = $compStmt->get_result();
                                            $isComponentTest = ($compRanges->num_rows > 0);
                                            $compStmt->close();
                                            ?>

                                            <?php if (!$isComponentTest): ?>
                                                <input type="text" name="results[<?= $assignment_id ?>][result_value]" class="form-control" value="<?= htmlspecialchars($value ?? '') ?>" <?= $highlight ?> <?= ($billing_status === 'finished') ? 'readonly' : '' ?>>
                                            <?php else: ?>
                                                <span class="text-muted">Component-based input</span>
                                            <?php endif; ?>
                                            <?php
                                            if ($isComponentTest):
                                                // Fetch existing component values (if any)
                                                $existingCompsStmt = $conn->prepare("SELECT component_label, value FROM test_result_components WHERE assignment_id = ?");
                                                $existingCompsStmt->bind_param("i", $assignment_id);
                                                $existingCompsStmt->execute();
                                                $existingValuesResult = $existingCompsStmt->get_result();

                                                $existing_values = [];
                                                while ($ev = $existingValuesResult->fetch_assoc()) {
                                                    $existing_values[$ev['component_label']] = $ev['value'];
                                                }
                                                $existingCompsStmt->close();

                                                // Re-run range fetch to render inputs
                                                $compStmt = $conn->prepare("SELECT * FROM test_ranges WHERE test_id = ? AND range_type = 'component'");
                                                $compStmt->bind_param("i", $test_id);
                                                $compStmt->execute();
                                                $compRanges = $compStmt->get_result();

                                                while ($comp = $compRanges->fetch_assoc()):
                                                    $label = htmlspecialchars($comp['condition_label']);
                                                    $unit = htmlspecialchars($comp['unit']);
                                                    $existing_val = $existing_values[$label] ?? '';
                                            ?>
                                                    <div class="mb-1">
                                                        <label><strong><?= $label ?> (<?= $comp['value_low'] ?> - <?= $comp['value_high'] ?> <?= $unit ?>)</strong></label>
                                                        <input type="text" name="results[<?= $assignment_id ?>][components][<?= $label ?>]" class="form-control" value="<?= htmlspecialchars($existing_val) ?>" <?= ($billing_status === 'finished') ? 'readonly' : '' ?>>
                                                    </div>
                                            <?php endwhile;
                                                $compStmt->close();
                                            endif; ?>

                                        </td>

                                        <?php
                                        ?>


                                        <td><input type="text" name="results[<?= $assignment_id ?>][remarks]" class="form-control" value="<?= htmlspecialchars($row['remarks'] ?? '') ?>" <?= ($billing_status === 'finished') ? 'readonly' : '' ?>></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>

                        <?php if ($billing_status !== 'finished'): ?>
                            <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Save Results</button>
                        <?php endif; ?>
                    </form>
                <?php else: ?>
                    <div class="alert alert-warning">No test assignments found for this visit.</div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#patient-select').select2({
                placeholder: "Select a patient",
                allowClear: true,
                width: 'resolve'
            });
        });
        $('#patient-select').on('select2:clear', function() {
            window.location.href = 'enter_results.php';
        });
    </script>
</body>

</html>
<?php include 'admin_footer.php'; ?>
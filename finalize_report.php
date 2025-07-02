<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'admin_header.php';
include 'db.php';

$patient_id = isset($_GET['patient_id']) && is_numeric($_GET['patient_id']) ? intval($_GET['patient_id']) : 0;
$billing_id = isset($_GET['billing_id']) && is_numeric($_GET['billing_id']) ? intval($_GET['billing_id']) : 0;

$patients = $conn->query("SELECT patient_id, name FROM patients ORDER BY name ASC");

// Billing list
$billings = [];
if ($patient_id > 0) {
    $billings = $conn->query("SELECT billing_id, billing_date FROM billing WHERE patient_id = $patient_id ORDER BY billing_date DESC");
}

// Fetch billing fstatus & gstatus
$fstatus = $gstatus = null;
if ($billing_id > 0) {
    $q = $conn->prepare("SELECT fstatus, gstatus FROM billing WHERE billing_id = ?");
    $q->bind_param("i", $billing_id);
    $q->execute();
    $q->bind_result($fstatus, $gstatus);
    $q->fetch();
    $q->close();
}
$is_finalized = ($fstatus === 'finalized');
$is_generated = ($gstatus === 'generated');

// Fetch lab doctors
$lab_doctors = $conn->query("SELECT doctor_id, name FROM doctors WHERE is_lab_doctor = 1 ORDER BY name ASC");

// Prefilled doctors
$prefill_doctors = [];
$prefill_treating_doctors = [];
if ($billing_id > 0) {
    $res = $conn->query("SELECT doctor_id, is_treating_doctor FROM report_lab_doctors WHERE billing_id = $billing_id");
    while ($row = $res->fetch_assoc()) {
        $prefill_doctors[] = $row['doctor_id'];
        if ($row['is_treating_doctor']) {
            $prefill_treating_doctors[] = $row['doctor_id'];
        }
    }
}

// Fetch test departments
$departments = [];
if ($billing_id > 0) {
    $dept_sql = "
        SELECT DISTINCT d.department_name
        FROM test_assignments ta
        JOIN tests t ON ta.test_id = t.test_id
        LEFT JOIN test_categories c ON t.category_id = c.category_id
        LEFT JOIN departments d ON c.department_id = d.department_id
        WHERE ta.billing_id = $billing_id AND d.department_name IS NOT NULL
        ORDER BY d.department_name
    ";
    $departments = $conn->query($dept_sql);
}

// Machine info
$machine_map = [];
$res2 = $conn->query("SELECT department_name, machine_name FROM report_machine_info WHERE billing_id = $billing_id");
while ($r2 = $res2->fetch_assoc()) {
    $machine_map[$r2['department_name']] = $r2['machine_name'];
}

// Handle submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_generated) {
    $doctor_ids = $_POST['doctor_ids'] ?? [];
    $treating_doctor_ids = $_POST['treating_doctor_ids'] ?? [];
    $machine_info = $_POST['machine_info'] ?? [];
    $patient_id = intval($_POST['patient_id']);
    $billing_id = intval($_POST['billing_id']);

    // Delete old entries
    $conn->query("DELETE FROM report_lab_doctors WHERE billing_id = $billing_id");
    $conn->query("DELETE FROM report_machine_info WHERE billing_id = $billing_id");

    // Insert doctors
    foreach ($doctor_ids as $doc_id) {
        $doc_id = intval($doc_id);
        $is_treating = in_array($doc_id, $treating_doctor_ids) ? 1 : 0;
        $stmt = $conn->prepare("INSERT INTO report_lab_doctors (billing_id, doctor_id, is_treating_doctor) VALUES (?, ?, ?)");
        $stmt->bind_param("iii", $billing_id, $doc_id, $is_treating);
        $stmt->execute();
    }

    // Insert machines
    $allMachinesFilled = true;
    foreach ($machine_info as $dept_name => $machine_name) {
        $machine_name = trim($machine_name);
        if (!empty($machine_name)) {
            $stmt = $conn->prepare("INSERT INTO report_machine_info (billing_id, department_name, machine_name) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $billing_id, $dept_name, $machine_name);
            $stmt->execute();
        } else {
            $allMachinesFilled = false;
        }
    }

    // Update fstatus and optionally gstatus
    $hasDoctor = count($doctor_ids) > 0;
    $newFstatus = ($hasDoctor && $allMachinesFilled) ? 'finalized' : 'not_finalized';

    $statusStmt = $conn->prepare("UPDATE billing SET fstatus = ? WHERE billing_id = ?");
    $statusStmt->bind_param("si", $newFstatus, $billing_id);
    $statusStmt->execute();

    if ($newFstatus === 'finalized' && $gstatus === 'not_ready') {
        $conn->query("UPDATE billing SET gstatus = 'ready' WHERE billing_id = $billing_id");
    }

    echo "
    <!DOCTYPE html>
    <html>
    <head><script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script></head>
    <body>
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
    </body>
    </html>";
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Finalize Lab Report</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
<div class="container mt-5">
    <div class="card">
        <div class="card-header bg-primary text-white">
            <h4 class="mb-0">Finalize Report: Doctor & Machine Info</h4>
        </div>
        <div class="card-body">
            <form method="GET" class="form-inline mb-4" id="selection-form">
                <label class="mr-2">Select Patient:</label>
                <select name="patient_id" class="form-control mr-3" id="patient-select">
                    <option value="">-- Select --</option>
                    <?php while ($p = $patients->fetch_assoc()): ?>
                        <option value="<?= $p['patient_id'] ?>" <?= $p['patient_id'] == $patient_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['name']) ?> (ID: <?= $p['patient_id'] ?>)
                        </option>
                    <?php endwhile; ?>
                </select>

                <?php if ($patient_id > 0): ?>
                    <label class="mr-2">Billing ID:</label>
                    <select name="billing_id" class="form-control mr-3" id="billing-select">
                        <option value="">-- Select Billing --</option>
                        <?php while ($b = $billings->fetch_assoc()): ?>
                            <option value="<?= $b['billing_id'] ?>" <?= $b['billing_id'] == $billing_id ? 'selected' : '' ?>>
                                Visit #<?= $b['billing_id'] ?> - <?= date('d M Y', strtotime($b['billing_date'])) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                <?php endif; ?>
            </form>

            <?php if ($billing_id > 0): ?>
                <!-- Status Display -->
                <div class="mb-3">
                    <strong>Report Status:</strong><br>
                    <span class="badge <?= $is_finalized ? 'badge-success' : 'badge-warning' ?>">
                        ✅ Finalization: <?= $fstatus === 'finalized' ? 'Finalized' : 'Not Finalized' ?>
                    </span>
                    
                    <span class="badge <?= $is_generated ? 'badge-dark' : 'badge-secondary' ?>">
                        📄 Report: <?= $gstatus === 'generated' ? 'Generated (Locked)' : ucfirst($gstatus ?? 'Not Ready') ?>
                    </span>
                    
                </div>

                <form method="POST">
                    <input type="hidden" name="patient_id" value="<?= $patient_id ?>">
                    <input type="hidden" name="billing_id" value="<?= $billing_id ?>">

                    <!-- Doctors -->
                    <div class="form-group">
                        <label><strong>Select Lab Doctors</strong></label><br>
                        <?php while ($doc = $lab_doctors->fetch_assoc()): ?>
                            <div class="form-check form-check-inline mb-2">
                                <input class="form-check-input" type="checkbox" name="doctor_ids[]"
                                    id="doc_<?= $doc['doctor_id'] ?>" value="<?= $doc['doctor_id'] ?>"
                                    <?= in_array($doc['doctor_id'], $prefill_doctors) ? 'checked' : '' ?>
                                    <?= ($is_generated) ? 'disabled' : '' ?>>
                                <label class="form-check-label mr-3" for="doc_<?= $doc['doctor_id'] ?>">
                                    <?= $doc['name'] ?>
                                </label>
                                <label class="ml-2">
                                    <input type="checkbox" name="treating_doctor_ids[]" value="<?= $doc['doctor_id'] ?>"
                                        <?= in_array($doc['doctor_id'], $prefill_treating_doctors) ? 'checked' : '' ?>
                                        <?= ($is_generated) ? 'disabled' : '' ?>>
                                    <small class="text-muted">Treated</small>
                                </label>
                            </div>
                        <?php endwhile; ?>
                    </div>

                    <!-- Machines -->
                    <div class="form-group">
                        <label><strong>Machines Used Per Department</strong></label>
                        <?php if ($departments && $departments instanceof mysqli_result): ?>
                            <?php while ($dept = $departments->fetch_assoc()):
                                $dept_name = $dept['department_name'];
                                $value = htmlspecialchars($machine_map[$dept_name] ?? '');
                            ?>
                                <div class="form-inline mb-2">
                                    <label class="mr-2" style="min-width:160px;"><strong><?= $dept_name ?>:</strong></label>
                                    <input type="text" name="machine_info[<?= $dept_name ?>]" class="form-control w-50"
                                        value="<?= $value ?>" placeholder="Enter machine used"
                                        <?= ($is_generated) ? 'readonly' : '' ?>>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <p class="text-muted">No departments found.</p>
                        <?php endif; ?>
                    </div>

                    <div class="text-right">
                        <?php if (!$is_generated): ?>
                            <button type="submit" class="btn btn-success">💾 Save & Finalize</button>
                        <?php endif; ?>
                        <a href="admin_dashboard.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const patientSelect = document.getElementById("patient-select");
    const billingSelect = document.getElementById("billing-select");
    const form = document.getElementById("selection-form");

    if (patientSelect) {
        patientSelect.addEventListener("change", function () {
            if (billingSelect) billingSelect.selectedIndex = 0;
            form.submit();
        });
    }
    if (billingSelect) {
        billingSelect.addEventListener("change", function () {
            form.submit();
        });
    }
});
</script>

<?php include 'admin_footer.php'; ?>
</body>
</html>

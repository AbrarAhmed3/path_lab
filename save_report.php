<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'db.php';

// Collect POST data
$patient_id = isset($_POST['patient_id']) ? intval($_POST['patient_id']) : 0;
$billing_id = isset($_POST['billing_id']) ? intval($_POST['billing_id']) : 0;
$doctor_ids = $_POST['doctor_ids'] ?? [];
$machine_info = $_POST['machine_info'] ?? [];

// Validation
if ($patient_id === 0 || $billing_id === 0 || empty($doctor_ids)) {
    echo "<script>alert('All fields are required.'); window.history.back();</script>";
    exit();
}

// Remove previous entries
$conn->query("DELETE FROM report_lab_doctors WHERE billing_id = $billing_id");
$conn->query("DELETE FROM report_machine_info WHERE billing_id = $billing_id");

// Save doctors
foreach ($doctor_ids as $doc_id) {
    $doc_id = intval($doc_id);
    $stmt = $conn->prepare("INSERT INTO report_lab_doctors (billing_id, doctor_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $billing_id, $doc_id);
    $stmt->execute();
}

// Save machines per department
foreach ($machine_info as $dept_name => $machine_name) {
    $machine_name = trim($machine_name);
    if (!empty($machine_name)) {
        $stmt = $conn->prepare("INSERT INTO report_machine_info (billing_id, department_name, machine_name) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $billing_id, $dept_name, $machine_name);
        $stmt->execute();
    }
}

// Success message and redirect
echo "
<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
<script>
    Swal.fire({
        icon: 'success',
        title: 'Report Finalized',
        text: 'Redirecting...',
        timer: 1800,
        showConfirmButton: false
    }).then(() => {
        window.location.href = 'generate_report.php?patient_id={$patient_id}&billing_id={$billing_id}';
    });
</script>";
exit();
?>

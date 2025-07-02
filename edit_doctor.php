<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'admin_header.php';
include 'db.php';

$doctor_id = $_GET['id'] ?? null;
$doctor = null;

if (!$doctor_id) {
    echo "<script>alert('Invalid doctor ID!'); window.location.href='view_doctors.php';</script>";
    exit();
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = $_POST['name'];
    $qualification = $_POST['qualification'];
    $contact = $_POST['contact'];
    $specialization = $_POST['specialization'];
    $reg_no = $_POST['reg_no'];
    $commission = $_POST['commission_percent'];

    $stmt = $conn->prepare("UPDATE doctors SET name = ?, qualification = ?, contact = ?, specialization = ?, reg_no = ?, commission_percent = ? WHERE doctor_id = ?");
    $stmt->bind_param("ssssssd", $name, $qualification, $contact, $specialization, $reg_no, $commission, $doctor_id);
    
    if ($stmt->execute()) {
    echo "
    <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    <script>
    Swal.fire({
        icon: 'success',
        title: 'Updated!',
        text: 'Doctor details updated successfully.',
        timer: 1800,
        showConfirmButton: false
    }).then(() => {
        window.location.href='view_doctors.php';
    });
    </script>";
    exit();
}
 else {
        echo "Error: " . $stmt->error;
    }
}

// Fetch doctor info to pre-fill form
$stmt = $conn->prepare("SELECT * FROM doctors WHERE doctor_id = ?");
$stmt->bind_param("i", $doctor_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 1) {
    $doctor = $result->fetch_assoc();
} else {
    echo "<script>alert('Doctor not found!'); window.location.href='view_doctors.php';</script>";
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Doctor</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
<div class="container mt-5">
    <h3 class="mb-4">✏️ Edit Doctor Details</h3>

    <form method="POST">
        <div class="form-group">
            <label>Doctor Name</label>
            <input type="text" name="name" value="<?= htmlspecialchars($doctor['name']) ?>" class="form-control" required>
        </div>
        <div class="form-group">
            <label>Qualification</label>
            <input type="text" name="qualification" value="<?= htmlspecialchars($doctor['qualification']) ?>" class="form-control" required>
        </div>
        <div class="form-group">
            <label>Contact</label>
            <input type="text" name="contact" value="<?= htmlspecialchars($doctor['contact']) ?>" class="form-control" required>
        </div>
        <div class="form-group">
            <label>Specialization</label>
            <input type="text" name="specialization" value="<?= htmlspecialchars($doctor['specialization']) ?>" class="form-control" required>
        </div>
        <div class="form-group">
            <label>Registration Number</label>
            <input type="text" name="reg_no" value="<?= htmlspecialchars($doctor['reg_no']) ?>" class="form-control" required>
        </div>
        <div class="form-group">
            <label>Commission Percentage (%)</label>
            <input type="number" name="commission_percent" step="0.01" value="<?= number_format($doctor['commission_percent'], 2) ?>" class="form-control" required>
        </div>

        <button type="submit" class="btn btn-success">💾 Update</button>
        <a href="view_doctors.php" class="btn btn-secondary">↩️ Cancel</a>
    </form>
</div>
</body>
</html>

<?php include 'admin_footer.php'; ?>

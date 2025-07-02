<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'admin_header.php';
include 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = $_POST['name'];
    $qualification = $_POST['qualification'];
    $contact = $_POST['contact'];
    $specialization = $_POST['specialization'];
    $reg_no = $_POST['reg_no'];
    $commission = $_POST['commission_percent'];

    $stmt = $conn->prepare("INSERT INTO doctors (name, qualification, contact, specialization, reg_no, commission_percent, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
    $stmt->bind_param("sssssd", $name, $qualification, $contact, $specialization, $reg_no, $commission);

    if ($stmt->execute()) {
        echo "
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        <script>
        Swal.fire({
            icon: 'success',
            title: 'Doctor Added!',
            text: 'Doctor added successfully.',
            timer: 1800,
            showConfirmButton: false
        }).then(() => {
            window.location.href='view_doctors.php';
        });
        </script>";
        exit;
    } else {
        echo "Error: " . $stmt->error;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Add Doctor</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
<div class="container mt-5">
    <h3 class="mb-4">🩺 Add New Doctor</h3>
    <form method="POST">
        <div class="form-group">
            <label>Doctor Name</label>
            <input type="text" name="name" class="form-control" required placeholder="Enter doctor's name">
        </div>
        <div class="form-group">
            <label>Qualification</label>
            <input type="text" name="qualification" class="form-control" required placeholder="Enter qualification (e.g., MBBS, MD)">
        </div>
        <div class="form-group">
            <label>Contact</label>
            <input type="text" name="contact" class="form-control" required placeholder="Enter contact number">
        </div>
        <div class="form-group">
            <label>Specialization</label>
            <input type="text" name="specialization" class="form-control" required placeholder="Enter specialization">
        </div>
        <div class="form-group">
            <label>Registration Number</label>
            <input type="text" name="reg_no" class="form-control" required placeholder="Enter registration number">
        </div>
        <div class="form-group">
            <label>Commission Percentage (%)</label>
            <input type="number" name="commission_percent" class="form-control" step="0.01" value="10.00" required placeholder="Enter commission percent">
        </div>
        <button type="submit" class="btn btn-success">➕ Add Doctor</button>
        <a href="admin_dashboard.php" class="btn btn-secondary">🏠 Back</a>
    </form>
</div>
</body>
</html>
<?php include 'admin_footer.php'; ?>

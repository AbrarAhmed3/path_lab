<?php
session_start();
include 'db.php';

$success = '';
$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $confirm = $_POST['confirm_password'];
    $role = $_POST['role'] ?? ''; // admin or superadmin
    $profile_photo = '';

    if ($password !== $confirm) {
        $_SESSION['error'] = "Passwords do not match!";
        header("Location: register_admin.php");
        exit();
    }

    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();

    if ($existing) {
        $_SESSION['error'] = "Username already exists!";
        header("Location: register_admin.php");
        exit();
    }

    // Upload photo if exists
    if (!empty($_FILES['profile_photo']['name'])) {
        $ext = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
        $newName = "admin_" . time() . "." . $ext;
        $uploadDir = "uploads/profile_pics/";
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $fullPath = $uploadDir . $newName;
        if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $fullPath)) {
            $profile_photo = $fullPath;
        } else {
            $_SESSION['error'] = "Failed to upload image.";
            header("Location: register_admin.php");
            exit();
        }
    }

    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $conn->prepare("INSERT INTO users (username, password, profile_photo, role) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $username, $hashedPassword, $profile_photo, $role);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = "Admin registered successfully!";
    } else {
        $_SESSION['error'] = "Error: " . $stmt->error;
    }

    header("Location: register_admin.php");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Register Admin</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<div class="container mt-5" style="max-width: 500px;">
    <h3>👨‍💼 Admin Registration</h3>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-group">
            <label>👤 Username</label>
            <input type="text" name="username" class="form-control" required>
        </div>

        <div class="form-group">
            <label>🔒 Password</label>
            <input type="password" name="password" class="form-control" required>
        </div>

        <div class="form-group">
            <label>🔁 Confirm Password</label>
            <input type="password" name="confirm_password" class="form-control" required>
        </div>

        <div class="form-group">
            <label>🛡️ Role</label>
            <select name="role" class="form-control" required>
                <option value="">-- Select Role --</option>
                <option value="admin">Admin</option>
                <option value="lab_technician">lab_technician</option>
                <option value="doctor">Doctor</option>
            </select>
        </div>

        <div class="form-group">
            <label>🖼️ Profile Photo (Optional)</label>
            <input type="file" name="profile_photo" class="form-control-file">
        </div>

        <button type="submit" class="btn btn-primary">Register Admin</button>
        <a href="admin_login.php" class="btn btn-secondary">Back to Login</a>
    </form>
</div>

<?php if (isset($_SESSION['success'])): ?>
<script>
Swal.fire({
    icon: 'success',
    title: 'Success',
    text: '<?= $_SESSION['success'] ?>',
    confirmButtonColor: '#3085d6'
});
</script>
<?php unset($_SESSION['success']); endif; ?>

<?php if (isset($_SESSION['error'])): ?>
<script>
Swal.fire({
    icon: 'error',
    title: 'Error',
    text: '<?= $_SESSION['error'] ?>',
    confirmButtonColor: '#d33'
});
</script>
<?php unset($_SESSION['error']); endif; ?>

</body>
</html>

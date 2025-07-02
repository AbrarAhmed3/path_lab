<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['admin_id'])) {
    header('Location: admin_login.php');
    exit();
}
include 'admin_header.php';
include 'db.php';

$admin_id = $_SESSION['admin_id'];
$result = $conn->query("SELECT * FROM users WHERE user_id = $admin_id");
$admin = $result ? $result->fetch_assoc() : null;

if (!$admin) {
    echo "<div class='alert alert-danger'>Admin not found.</div>";
    include 'admin_footer.php';
    exit();
}

$successMsg = '';
$errorMsg = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $new_username = trim($_POST['username']);
    $photoPath = $admin['profile_photo'];

    // Check if username already exists (excluding self)
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");
    $stmt->bind_param("si", $new_username, $admin_id);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $errorMsg = "❌ Username already taken.";
    }

    // Handle profile photo upload if username is OK
    if (!$errorMsg && !empty($_FILES['profile_photo']['name'])) {
        $ext = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
        $newName = "admin_" . time() . "." . $ext;
        $uploadDir = "uploads/profile_pics/";
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $fullPath = $uploadDir . $newName;
        if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $fullPath)) {
            $photoPath = $fullPath;
        } else {
            $errorMsg = "❌ Failed to upload image.";
        }
    }

    // Perform update
    if (!$errorMsg) {
        $stmt = $conn->prepare("UPDATE users SET username = ?, profile_photo = ? WHERE user_id = ?");
        $stmt->bind_param("ssi", $new_username, $photoPath, $admin_id);
        if ($stmt->execute()) {
            $successMsg = "✅ Profile updated successfully.";
            $_SESSION['admin_username'] = $new_username;
            $admin['username'] = $new_username;
            $admin['profile_photo'] = $photoPath;
        } else {
            $errorMsg = "❌ Update failed: " . $stmt->error;
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Profile Settings</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
<div class="container mt-4">
    <h3>👤 Profile Settings</h3>

    <?php if ($successMsg): ?>
        <div class="alert alert-success"><?= $successMsg ?></div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
        <div class="alert alert-danger"><?= $errorMsg ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($admin['username']) ?>" required>
        </div>

        <div class="form-group">
            <label>Profile Photo</label><br>
            <?php if (!empty($admin['profile_photo']) && file_exists($admin['profile_photo'])): ?>
                <img src="<?= $admin['profile_photo'] ?>" width="120" height="120" class="img-thumbnail mb-2">
            <?php else: ?>
                <div class="mb-2">No profile photo uploaded.</div>
            <?php endif; ?>
            <input type="file" name="profile_photo" class="form-control-file" accept="image/*">
        </div>

        <button type="submit" class="btn btn-primary">💾 Save Changes</button>
        <a href="admin_dashboard.php" class="btn btn-secondary">⬅️ Back</a>
    </form>
</div>

<?php include 'admin_footer.php'; ?>
</body>
</html>

<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'db.php';

$id = $_GET['id'] ?? null;
if ($id) {
    $stmt = $conn->prepare("UPDATE doctors SET is_active = 0 WHERE doctor_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
}
header("Location: view_doctors.php");
exit();
?>

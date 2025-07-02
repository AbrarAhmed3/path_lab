<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'db.php';

$test_id = $_GET['id'] ?? null;

if ($test_id) {
    // Delete from test_ranges first
    $rangeStmt = $conn->prepare("DELETE FROM test_ranges WHERE test_id = ?");
    if ($rangeStmt) {
        $rangeStmt->bind_param("i", $test_id);
        $rangeStmt->execute();
        $rangeStmt->close();
    }

    // Then delete the test itself
    $stmt = $conn->prepare("DELETE FROM tests WHERE test_id = ?");
    $stmt->bind_param("i", $test_id);
    $stmt->execute();

    // Log activity (optional)
    $admin_id = $_SESSION['admin_id'] ?? null;
    if ($admin_id) {
        $action = "Permanently deleted test ID $test_id";
        $logStmt = $conn->prepare("INSERT INTO activity_log (admin_id, action) VALUES (?, ?)");
        $logStmt->bind_param("is", $admin_id, $action);
        $logStmt->execute();
    }
}

header("Location: restore_tests.php?deleted=1");
exit();

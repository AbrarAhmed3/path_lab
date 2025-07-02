<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'db.php';

$test_id = $_GET['id'] ?? null;

if ($test_id) {
    // Restore the test
    $stmt = $conn->prepare("UPDATE tests SET deleted_at = NULL WHERE test_id = ?");
    $stmt->bind_param("i", $test_id);
    $stmt->execute();

    // Restore its reference ranges
    $rangeStmt = $conn->prepare("UPDATE test_ranges SET deleted_at = NULL WHERE test_id = ?");
    $rangeStmt->bind_param("i", $test_id);
    $rangeStmt->execute();

    // Log activity (optional)
    $admin_id = $_SESSION['admin_id'] ?? null;
    if ($admin_id) {
        $action = "Restored test ID $test_id";
        $logStmt = $conn->prepare("INSERT INTO activity_log (admin_id, action) VALUES (?, ?)");
        $logStmt->bind_param("is", $admin_id, $action);
        $logStmt->execute();
    }
}

header("Location: view_tests.php");
exit();

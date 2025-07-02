<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'db.php';

$test_id = $_GET['id'] ?? null;

if ($test_id) {
    // Soft delete the test
    $stmt = $conn->prepare("UPDATE tests SET deleted_at = NOW() WHERE test_id = ?");
    $stmt->bind_param("i", $test_id);
    if ($stmt->execute()) {

        // Soft delete associated test_ranges (if deleted_at exists in that table)
        $rangeDelete = $conn->prepare("UPDATE test_ranges SET deleted_at = NOW() WHERE test_id = ?");
        $rangeDelete->bind_param("i", $test_id);
        $rangeDelete->execute();
        $rangeDelete->close();

        // Log admin action
        $admin_id = $_SESSION['admin_id'] ?? null;
        if ($admin_id) {
            $action = "Soft-deleted test ID $test_id and associated reference ranges";
            $logStmt = $conn->prepare("INSERT INTO activity_log (admin_id, action) VALUES (?, ?)");
            $logStmt->bind_param("is", $admin_id, $action);
            $logStmt->execute();
            $logStmt->close();
        }
    }
    $stmt->close();
}

header("Location: view_tests.php?deleted=1");
exit();

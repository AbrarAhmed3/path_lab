<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo "Unauthorized";
    exit();
}

include 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['billing_id'])) {
    $billing_id = intval($_POST['billing_id']);

    $stmt = $conn->prepare("UPDATE billing SET gstatus = 'ready' WHERE billing_id = ?");
    $stmt->bind_param("i", $billing_id);
    if ($stmt->execute()) {
        echo "success";
    } else {
        echo "error";
    }
    $stmt->close();
    exit();
}
echo "invalid";

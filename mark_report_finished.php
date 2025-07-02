<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo "Unauthorized";
    exit;
}

include 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $billing_id = isset($_POST['billing_id']) ? intval($_POST['billing_id']) : 0;

    if ($billing_id > 0) {
        $stmt = $conn->prepare("UPDATE billing SET status = 'finished' WHERE billing_id = ?");
        $stmt->bind_param("i", $billing_id);
        $stmt->execute();
        $stmt->close();
        echo "success";
    } else {
        echo "invalid";
    }
}
?>

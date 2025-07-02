<?php
include 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['billing_id'])) {
    $billing_id = intval($_POST['billing_id']);

    // Only update if not already generated
    $stmt = $conn->prepare("UPDATE billing SET gstatus = 'generated' WHERE billing_id = ? AND gstatus != 'generated'");
    $stmt->bind_param("i", $billing_id);
    if ($stmt->execute()) {
        echo "Gstatus updated to 'generated'";
    } else {
        echo "Failed to update";
    }
    $stmt->close();
} else {
    echo "Invalid request";
}

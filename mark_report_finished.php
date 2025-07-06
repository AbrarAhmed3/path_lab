<?php
include 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['billing_id'])) {
    $billing_id = intval($_POST['billing_id']);

    // 1) Fetch current billing status
    $stmt = $conn->prepare("
        SELECT bstatus
          FROM billing
         WHERE billing_id = ?
         LIMIT 1
    ");
    $stmt->bind_param("i", $billing_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $billing = $result->fetch_assoc();
    $stmt->close();

    if (!$billing) {
        echo "Error: Billing record not found.";
        exit;
    }

    // 2) Only proceed if bstatus = 'paid'
    if (strtolower($billing['bstatus']) !== 'paid') {
        echo "Cannot generate report: billing status is '{$billing['bstatus']}'. Please mark it as PAID first.";
        exit;
    }

    // 3) Only update gstatus if it isn't already 'generated'
    $stmt = $conn->prepare("
        UPDATE billing
           SET gstatus = 'generated'
         WHERE billing_id = ?
           AND gstatus != 'generated'
    ");
    $stmt->bind_param("i", $billing_id);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo "Gstatus updated to 'generated'.";
        } else {
            echo "Gstatus was already 'generated'.";
        }
    } else {
        echo "Failed to update gstatus.";
    }

    $stmt->close();

} else {
    echo "Invalid request.";
}

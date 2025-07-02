<?php
include 'db.php';
$patient_id = intval($_GET['patient_id']);
$res = $conn->query("SELECT billing_id FROM billing WHERE patient_id = $patient_id ORDER BY billing_id DESC");
while ($row = $res->fetch_assoc()) {
    echo "<option value='{$row['billing_id']}'>Billing #{$row['billing_id']}</option>";
}
?>

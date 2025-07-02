<?php
include 'db.php';
header('Content-Type: application/json');
$q = trim($_GET['q'] ?? '');
if ($q === '') {
    echo json_encode([]);
    exit;
}
$ql = '%'.$q.'%';
$stmt = $conn->prepare("
    SELECT patient_id, name
    FROM patients
    WHERE name LIKE ? OR CAST(patient_id AS CHAR) LIKE ?
    ORDER BY name ASC
    LIMIT 10
");
$stmt->bind_param("ss", $ql, $ql);
$stmt->execute();
echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));

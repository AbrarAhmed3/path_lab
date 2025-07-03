<?php
include 'db.php';
header('Content-Type: application/json');

// term typed by user
$q = trim($_GET['q'] ?? '');
if ($q === '') {
    echo json_encode(['patients'=>[]]);
    exit;
}

// search by name or exact ID, limit to 20
$stmt = $conn->prepare(
  "SELECT patient_id, name
     FROM patients
    WHERE name LIKE CONCAT('%',?,'%')
       OR patient_id = ?
    ORDER BY name
    LIMIT 20"
);
$stmt->bind_param("si", $q, $q);
$stmt->execute();
$res = $stmt->get_result();

$out = ['patients'=>[]];
while ($r = $res->fetch_assoc()) {
  $out['patients'][] = [
    'id'   => $r['patient_id'],
    'text' => "{$r['name']} (ID: {$r['patient_id']})"
  ];
}
echo json_encode($out);

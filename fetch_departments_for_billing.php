<?php
include 'db.php';
header('Content-Type: application/json');

$billing_id = isset($_GET['billing_id']) ? (int)$_GET['billing_id'] : 0;
if (!$billing_id) {
    echo json_encode(['departments' => []]);
    exit;
}

// 1. grab every department that has at least one test in this billing
$sql = "
  SELECT DISTINCT d.department_id, d.department_name
    FROM test_assignments ta
    JOIN tests t ON ta.test_id = t.test_id
    JOIN departments d ON t.department_id = d.department_id
   WHERE ta.billing_id = ?
   ORDER BY d.department_name
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i',$billing_id);
$stmt->execute();
$res = $stmt->get_result();

$departments = [];
while($row = $res->fetch_assoc()){
    $deptName = $row['department_name'];

    // 2. look up any existing machine_name for this billing+department
    $stmt2 = $conn->prepare("
      SELECT machine_name
        FROM report_machine_info
       WHERE billing_id = ?
         AND department_name = ?
      LIMIT 1
    ");
    $stmt2->bind_param('is',$billing_id,$deptName);
    $stmt2->execute();
    $r2 = $stmt2->get_result();
    $machine = $r2->fetch_assoc()['machine_name'] ?? '';

    $departments[] = [
      'department_id'   => (int)$row['department_id'],
      'department_name' => $deptName,
      'machine_name'    => $machine
    ];
}

echo json_encode(['departments'=>$departments]);

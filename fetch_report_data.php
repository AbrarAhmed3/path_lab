<?php
include 'db.php';

$billing_id = intval($_GET['billing_id']);

// Get all lab doctors
$doctors = $conn->query("SELECT doctor_id, name FROM doctors WHERE is_lab_doctor = 1 ORDER BY name ASC");

// Get selected doctor_ids
$selected_doctors = [];
$res = $conn->query("SELECT doctor_id FROM report_lab_doctors WHERE billing_id = $billing_id");
while ($r = $res->fetch_assoc()) $selected_doctors[] = $r['doctor_id'];

// Doctor checkboxes
$doctors_html = "";
while ($doc = $doctors->fetch_assoc()) {
    $checked = in_array($doc['doctor_id'], $selected_doctors) ? 'checked' : '';
    $doctors_html .= "<label class='mr-3'><input type='checkbox' name='doctor_ids[]' value='{$doc['doctor_id']}' $checked> {$doc['name']}</label>";
}

// Get departments that have tests assigned in this billing
$dept_query = "
    SELECT DISTINCT d.department_name
    FROM assigned_tests at
    JOIN tests t ON at.test_id = t.test_id
    JOIN test_categories tc ON t.category_id = tc.category_id
    JOIN departments d ON tc.department_id = d.department_id
    WHERE at.billing_id = $billing_id
";

$departments = $conn->query($dept_query);

// Get previously saved machine info
$machine_map = [];
$res2 = $conn->query("SELECT department_name, machine_name FROM report_machine_info WHERE billing_id = $billing_id");
while ($r2 = $res2->fetch_assoc()) {
    $machine_map[$r2['department_name']] = $r2['machine_name'];
}

// Build input fields per department
$machines_html = "";
while ($dept = $departments->fetch_assoc()) {
    $dept_name = $dept['department_name'];
    $val = htmlspecialchars($machine_map[$dept_name] ?? '');
    $machines_html .= "
        <div class='form-inline mb-2'>
            <label class='mr-2' style='min-width:150px'>{$dept_name}:</label>
            <input type='text' name='machine_info[{$dept_name}]' class='form-control w-50' value='{$val}' placeholder='Enter machine used'>
        </div>";
}

echo json_encode([
    "doctors_html" => $doctors_html,
    "machines_html" => $machines_html
]);
?>

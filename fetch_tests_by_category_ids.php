<?php
include 'db.php';

$category_ids = $_POST['category_ids'] ?? [];
$response = ['html' => '', 'test_ids' => []];

if (!empty($category_ids)) {
    $in = implode(',', array_map('intval', $category_ids)); // sanitize input
$sql = "
    SELECT t.test_id, t.name
    FROM category_tests ct
    JOIN tests t ON ct.test_id = t.test_id
    WHERE ct.category_id IN ($in)
";
    $result = $conn->query($sql);

    ob_start();
    echo "<div class='mb-3'><strong>Tests from Selected Profiles:</strong></div>";
    while ($row = $result->fetch_assoc()) {
        $response['test_ids'][] = $row['test_id'];
        echo "<div class='form-check'>
            <input class='form-check-input' type='checkbox' name='tests_profile[]' value='{$row['test_id']}' id='profile_test_{$row['test_id']}' checked>
            <label class='form-check-label badge badge-info px-2 py-1' for='profile_test_{$row['test_id']}'>{$row['name']}</label>
        </div>";
    }

    $response['html'] = ob_get_clean();
} else {
    $response['html'] = "<p>No category selected.</p>";
}

header('Content-Type: application/json');
echo json_encode($response);
?>

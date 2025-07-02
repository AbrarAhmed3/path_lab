<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'admin_header.php';
include 'db.php';

$test_id = $_GET['id'] ?? null;

// Fetch categories with department name
$categories = $conn->query("
    SELECT tc.category_id, tc.category_name, d.department_name 
    FROM test_categories tc
    LEFT JOIN departments d ON tc.department_id = d.department_id
    ORDER BY tc.category_name ASC
");

// Fetch test data
$stmt = $conn->prepare("SELECT * FROM tests WHERE test_id = ?");
$stmt->bind_param("i", $test_id);
$stmt->execute();
$test = $stmt->get_result()->fetch_assoc();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = $_POST['name'];
    $unit = $_POST['unit'];
    $method = $_POST['method'];
    $ref_range = $_POST['ref_range']; // Optional
    $category_id = $_POST['category_id'];
    $price = $_POST['price'];

    $stmt = $conn->prepare("UPDATE tests SET name = ?, unit = ?, method = ?, ref_range = ?, category_id = ?, price = ? WHERE test_id = ?");
    $stmt->bind_param("ssssidi", $name, $unit, $method, $ref_range, $category_id, $price, $test_id);

    if ($stmt->execute()) {
        echo "
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        <script>
            Swal.fire({
                icon: 'success',
                title: 'Test Updated!',
                timer: 1500,
                showConfirmButton: false
            }).then(() => {
                window.location.href = 'view_tests.php';
            });
        </script>";
        exit;
    } else {
        echo "<div class='alert alert-danger'>Error: " . $stmt->error . "</div>";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Test</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
<div class="container mt-5">
    <h3 class="mb-4">✏️ Edit Test</h3>
    <form method="POST">
        <div class="form-group">
            <label>Test Name</label>
            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($test['name']) ?>" required>
        </div>

        <div class="form-group">
            <label>Unit</label>
            <input type="text" name="unit" class="form-control" value="<?= htmlspecialchars($test['unit']) ?>" required>
        </div>

        <div class="form-group">
            <label>Method</label>
            <input type="text" name="method" class="form-control" value="<?= htmlspecialchars($test['method']) ?>" required>
        </div>

        <div class="form-group">
            <label>Reference Range (optional)</label>
            <input type="text" name="ref_range" class="form-control" value="<?= htmlspecialchars($test['ref_range']) ?>">
            <small class="text-muted">Optional: Just for display. Use detailed logic in "Manage Ranges".</small>
        </div>

        <div class="form-group">
            <label>Price (₹)</label>
            <input type="number" step="0.01" name="price" class="form-control" value="<?= htmlspecialchars($test['price']) ?>" required>
        </div>

        <div class="form-group">
            <label>Category</label>
            <select name="category_id" class="form-control" required>
                <?php while ($cat = $categories->fetch_assoc()): ?>
                    <option value="<?= $cat['category_id'] ?>" <?= $cat['category_id'] == $test['category_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cat['category_name']) ?> 
                        (<?= htmlspecialchars($cat['department_name'] ?? 'No Department') ?>)
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <button type="submit" class="btn btn-success">💾 Update</button>
        <a href="view_tests.php" class="btn btn-secondary">↩️ Cancel</a>
    </form>

    <hr>
    <div class="mt-3">
        <h5>📏 Define or Edit Advanced Reference Ranges</h5>
        <a href="test_ranges.php?test_id=<?= $test_id ?>" class="btn btn-info">📊 Manage Reference Ranges</a>
        <small class="form-text text-muted mt-2">Define ranges by gender, age, pregnancy, label, etc.</small>
    </div>
</div>
</body>
</html>

<?php include 'admin_footer.php'; ?>

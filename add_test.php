<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'admin_header.php';
include 'db.php';

// Fetch categories and related departments
$categories = $conn->query("
    SELECT tc.category_id, tc.category_name, d.department_name 
    FROM test_categories tc
    LEFT JOIN departments d ON tc.department_id = d.department_id
    ORDER BY tc.category_name ASC
");

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = $_POST['name'];
    $unit = $_POST['unit'];
    $method = $_POST['method'];
    $ref_range = $_POST['ref_range'] ?? '';
    $category_id = $_POST['category_id'];
    $price = $_POST['price'];

    $stmt = $conn->prepare("INSERT INTO tests (name, unit, method, ref_range, category_id, price) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssdi", $name, $unit, $method, $ref_range, $category_id, $price);

    if ($stmt->execute()) {
        $test_id = $stmt->insert_id;
        echo "
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        <script>
            Swal.fire({
                icon: 'success',
                title: 'Test Added!',
                text: 'Redirecting to define detailed ranges...',
                timer: 1800,
                showConfirmButton: false
            }).then(() => {
                window.location.href = 'test_ranges.php?test_id=$test_id';
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
    <title>Add New Test</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
<div class="container mt-5">
    <h3 class="mb-4">🧪 Add New Test</h3>
    <form method="POST">
        <div class="form-group">
            <label>Test Name</label>
            <input type="text" name="name" class="form-control" required placeholder="Enter test name">
        </div>

        <div class="form-group">
            <label>Unit</label>
            <input type="text" name="unit" class="form-control" required placeholder="e.g. mg/dL, %">
        </div>

        <div class="form-group">
            <label>Method</label>
            <input type="text" name="method" class="form-control" required placeholder="e.g. CLIA, ELISA, ISE, etc.">
        </div>

        <div class="form-group">
            <label>Reference Range (optional)</label>
            <input type="text" name="ref_range" class="form-control" placeholder="e.g. 70 - 110 mg/dL">
            <small class="form-text text-muted">For display only. Define actual ranges in next step.</small>
        </div>

        <div class="form-group">
            <label>Price (₹)</label>
            <input type="number" step="0.01" min="0" name="price" class="form-control" required placeholder="Enter test price">
        </div>

        <div class="form-group">
            <label>Test Category</label>
            <select name="category_id" class="form-control" required>
                <option value="">-- Select Category --</option>
                <?php while ($c = $categories->fetch_assoc()): ?>
                    <option value="<?= $c['category_id'] ?>">
                        <?= htmlspecialchars($c['category_name']) ?> 
                        (<?= htmlspecialchars($c['department_name'] ?? 'No Department') ?>)
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <button type="submit" class="btn btn-primary">➕ Add Test</button>
        <a href="view_tests.php" class="btn btn-secondary">📋 View All Tests</a>
    </form>
</div>
</body>
</html>

<?php include 'admin_footer.php'; ?>

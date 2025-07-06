<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'admin_header.php';
include 'db.php';

// Fetch departments
$departments = $conn->query("SELECT * FROM departments ORDER BY department_name ASC");

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = $_POST['name'];
    $unit = $_POST['unit'];
    $method = $_POST['method'];
    $ref_range = $_POST['ref_range'] ?? '';
    $department_id = $_POST['department_id'];
    $price = $_POST['price'];

    $stmt = $conn->prepare("INSERT INTO tests (name, unit, method, ref_range, department_id, price) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssdi", $name, $unit, $method, $ref_range, $department_id, $price);

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
            <input type="text" name="unit" class="form-control" placeholder="e.g. mg/dL, %">
        </div>

        <div class="form-group">
            <label>Method</label>
            <input type="text" name="method" class="form-control"  placeholder="e.g. CLIA, ELISA, ISE, etc.">
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
            <label>Department</label>
            <select name="department_id" class="form-control" required>
                <option value="">-- Select Department --</option>
                <?php while ($d = $departments->fetch_assoc()): ?>
                    <option value="<?= $d['department_id'] ?>">
                        <?= htmlspecialchars($d['department_name']) ?>
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

<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'admin_header.php';
include 'db.php';

// Fetch all tests
$tests = $conn->query("
    SELECT 
        t.test_id, t.name AS test_name, t.unit, t.ref_range, t.price, 
        c.category_id, c.category_name, d.department_id, d.department_name,
        (SELECT COUNT(*) FROM test_ranges tr WHERE tr.test_id = t.test_id) AS has_ranges
    FROM tests t 
    LEFT JOIN test_categories c ON t.category_id = c.category_id 
    LEFT JOIN departments d ON c.department_id = d.department_id
    WHERE t.deleted_at IS NULL 
    ORDER BY t.test_id DESC
");

// For filters
$departments = $conn->query("SELECT * FROM departments ORDER BY department_name");
$categories = $conn->query("SELECT * FROM test_categories ORDER BY category_name");
?>

<!DOCTYPE html>
<html>
<head>
    <title>All Tests</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .btn-manage-range { font-size: 13px; padding: 2px 8px; }
        .filter-select { width: 200px; margin-right: 15px; }
        .dataTables_filter { float: right !important; }
    </style>
</head>
<body>
<div class="container mt-5">
    <h3 class="mb-4">🧾 All Tests</h3>

    <div class="mb-3 d-flex flex-wrap align-items-center">
        <a href="add_test.php" class="btn btn-success mr-2">➕ Add New Test</a>
        <a href="restore_tests.php" class="btn btn-warning mr-3">🗑️ View Deleted Tests</a>

        <!-- Filters -->
        <select id="departmentFilter" class="form-control filter-select">
            <option value="">🔍 Filter by Department</option>
            <?php while ($d = $departments->fetch_assoc()): ?>
                <option value="<?= htmlspecialchars($d['department_name']) ?>"><?= htmlspecialchars($d['department_name']) ?></option>
            <?php endwhile; ?>
        </select>

        <select id="categoryFilter" class="form-control filter-select">
            <option value="">🔍 Filter by Category</option>
            <?php while ($c = $categories->fetch_assoc()): ?>
                <option value="<?= htmlspecialchars($c['category_name']) ?>"><?= htmlspecialchars($c['category_name']) ?></option>
            <?php endwhile; ?>
        </select>
    </div>

    <table id="testsTable" class="table table-bordered table-sm table-hover">
        <thead class="thead-dark">
            <tr>
                <th>#</th>
                <th>Test Name</th>
                <th>Unit</th>
                <th>Ref. Range</th>
                <th>Price (₹)</th>
                <th>Category</th>
                <th>Department</th>
                <th>Ranges</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($tests->num_rows > 0): $i = 1; ?>
            <?php while ($row = $tests->fetch_assoc()): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= htmlspecialchars($row['test_name']) ?></td>
                    <td><?= htmlspecialchars($row['unit']) ?></td>
                    <td><?= htmlspecialchars($row['ref_range']) ?></td>
                    <td><?= number_format($row['price'], 2) ?></td>
                    <td><?= htmlspecialchars($row['category_name']) ?></td>
                    <td><?= htmlspecialchars($row['department_name']) ?></td>
                    <td class="text-center">
                        <?php if ($row['has_ranges'] > 0): ?>
                            <span class="badge badge-success">✅</span>
                            <a href="test_ranges.php?test_id=<?= $row['test_id'] ?>" class="btn btn-sm btn-outline-primary btn-manage-range">🛠 Manage</a>
                        <?php else: ?>
                            <span class="badge badge-secondary">❌</span>
                            <a href="test_ranges.php?test_id=<?= $row['test_id'] ?>" class="btn btn-sm btn-outline-secondary btn-manage-range">➕ Add</a>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="edit_test.php?id=<?= $row['test_id'] ?>" class="btn btn-sm btn-primary">✏️ Edit</a>
                        <button onclick="confirmDelete(<?= $row['test_id'] ?>)" class="btn btn-sm btn-danger">🗑️ Delete</button>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr><td colspan="9">No tests found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

<script>
$(document).ready(function () {
    const table = $('#testsTable').DataTable({
        pageLength: 10,
        lengthMenu: [10, 20, 50, 100],
        ordering: true
    });

    // Filter by department
    $('#departmentFilter').on('change', function () {
        table.column(6).search(this.value).draw();
    });

    // Filter by category
    $('#categoryFilter').on('change', function () {
        table.column(5).search(this.value).draw();
    });
});

function confirmDelete(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: "This will move the test to trash.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'delete_test.php?id=' + id;
        }
    });
}

<?php if (isset($_GET['deleted']) && $_GET['deleted'] == 1): ?>
Swal.fire({
    icon: 'success',
    title: 'Test Deleted',
    text: 'The test has been moved to trash.',
    timer: 1500,
    showConfirmButton: false
});
<?php endif; ?>
</script>
</body>
</html>

<?php include 'admin_footer.php'; ?>

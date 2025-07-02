<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'db.php';

// Fetch all departments
$departments = $conn->query("SELECT department_id, department_name FROM departments ORDER BY department_name ASC");

// Handle Add or Edit
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $category_name = trim($_POST['category_name']);
    $department_id = $_POST['department_id'] ?? null;
    $category_id = $_POST['category_id'] ?? null;

    if ($category_id) {
        $stmt = $conn->prepare("UPDATE test_categories SET category_name = ?, department_id = ? WHERE category_id = ?");
        $stmt->bind_param("sii", $category_name, $department_id, $category_id);
        $stmt->execute();
    } else {
        $stmt = $conn->prepare("INSERT INTO test_categories (category_name, department_id) VALUES (?, ?)");
        $stmt->bind_param("si", $category_name, $department_id);
        $stmt->execute();
    }

    header("Location: test_category_manager.php");
    exit();
}

// Handle delete
if (isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];
    $stmt = $conn->prepare("DELETE FROM test_categories WHERE category_id = ?");
    $stmt->bind_param("i", $delete_id);
    $stmt->execute();
    header("Location: test_category_manager.php");
    exit();
}

include 'admin_header.php';

// Edit mode
$edit = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $edit = $conn->query("SELECT * FROM test_categories WHERE category_id = $edit_id")->fetch_assoc();
}

$categories = $conn->query("
    SELECT tc.*, d.department_name 
    FROM test_categories tc 
    LEFT JOIN departments d ON tc.department_id = d.department_id 
    ORDER BY tc.category_name ASC
");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Test Categories</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<div class="container mt-5">
    <h3>📁 Test Category Manager</h3>

    <form method="POST" class="mb-4">
        <input type="hidden" name="category_id" value="<?= $edit['category_id'] ?? '' ?>">
        <div class="form-group">
            <label>Category Name</label>
            <input type="text" name="category_name" class="form-control" required value="<?= htmlspecialchars($edit['category_name'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label>Department</label>
            <select name="department_id" class="form-control" required>
                <option value="">-- Select Department --</option>
                <?php while ($d = $departments->fetch_assoc()): ?>
                    <option value="<?= $d['department_id'] ?>" <?= ($edit['department_id'] ?? '') == $d['department_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($d['department_name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <button type="submit" class="btn btn-primary"><?= $edit ? '✏️ Update' : '➕ Add' ?> Category</button>
        <?php if ($edit): ?>
            <a href="test_category_manager.php" class="btn btn-secondary">❌ Cancel</a>
        <?php endif; ?>
    </form>

    <table class="table table-bordered">
        <thead class="thead-dark">
        <tr>
            <th>#</th>
            <th>Category Name</th>
            <th>Department</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php $i = 1; while ($row = $categories->fetch_assoc()): ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($row['category_name']) ?></td>
                <td><?= htmlspecialchars($row['department_name'] ?? '—') ?></td>
                <td>
                    <a href="test_category_manager.php?edit_id=<?= $row['category_id'] ?>" class="btn btn-sm btn-warning">✏️ Edit</a>
                    <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?= $row['category_id'] ?>)">🗑️ Delete</button>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>

<script>
function confirmDelete(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: "This will permanently delete the category!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'test_category_manager.php?delete_id=' + id;
        }
    });
}
</script>
</body>
</html>

<?php include 'admin_footer.php'; ?>

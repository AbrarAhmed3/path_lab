<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'db.php';

// Handle Add or Edit Category
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $category_name = trim($_POST['category_name']);
    $category_id = $_POST['category_id'] ?? null;
    $selected_tests = isset($_POST['test_ids']) && is_array($_POST['test_ids']) ? $_POST['test_ids'] : [];

    if ($category_id) {
        $stmt = $conn->prepare("UPDATE test_categories SET category_name = ? WHERE category_id = ?");
        $stmt->bind_param("si", $category_name, $category_id);
        $stmt->execute();
        $conn->query("DELETE FROM category_tests WHERE category_id = $category_id");
    } else {
        $stmt = $conn->prepare("INSERT INTO test_categories (category_name) VALUES (?)");
        $stmt->bind_param("s", $category_name);
        $stmt->execute();
        $category_id = $stmt->insert_id;
    }

    if (!empty($selected_tests)) {
        $insertStmt = $conn->prepare("INSERT INTO category_tests (category_id, test_id) VALUES (?, ?)");
        foreach ($selected_tests as $test_id) {
            $insertStmt->bind_param("ii", $category_id, $test_id);
            $insertStmt->execute();
        }
    }

    header("Location: test_category_manager.php");
    exit();
}

// Handle delete
if (isset($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];
    $conn->query("DELETE FROM test_categories WHERE category_id = $delete_id");
    $conn->query("DELETE FROM category_tests WHERE category_id = $delete_id");
    header("Location: test_category_manager.php");
    exit();
}

// Edit mode
$edit = null;
$assigned_tests = [];
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $edit = $conn->query("SELECT * FROM test_categories WHERE category_id = $edit_id")->fetch_assoc();

    $res = $conn->query("SELECT test_id FROM category_tests WHERE category_id = $edit_id");
    while ($row = $res->fetch_assoc()) {
        $assigned_tests[] = $row['test_id'];
    }
}

// Fetch categories and tests
$categories = $conn->query("SELECT * FROM test_categories ORDER BY category_name ASC");
$all_tests = $conn->query("SELECT test_id, name FROM tests WHERE deleted_at IS NULL ORDER BY name");

include 'admin_header.php';
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Test Categories</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <style>
        .select2-container--default .select2-selection--multiple {
            min-height: 38px;
            border: 1px solid #ced4da;
        }
    </style>
</head>
<body>
<div class="container mt-5">
    <h3 class="mb-4">📁 Test Category Manager</h3>

    <form method="POST" class="mb-5">
        <input type="hidden" name="category_id" value="<?= $edit['category_id'] ?? '' ?>">

        <div class="form-group">
            <label>Category Name</label>
            <input type="text" name="category_name" class="form-control" required value="<?= htmlspecialchars($edit['category_name'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label>Select Tests</label>
            <select name="test_ids[]" class="form-control select2" multiple="multiple" required>
                <?php if ($all_tests->num_rows > 0): ?>
                    <?php while ($test = $all_tests->fetch_assoc()): ?>
                        <option value="<?= $test['test_id'] ?>" <?= in_array($test['test_id'], $assigned_tests) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($test['name']) ?>
                        </option>
                    <?php endwhile; ?>
                <?php endif; ?>
            </select>
        </div>

        <button type="submit" class="btn btn-primary"><?= $edit ? '✏️ Update' : '➕ Add' ?> Category</button>
        <?php if ($edit): ?>
            <a href="test_category_manager.php" class="btn btn-secondary">❌ Cancel</a>
        <?php endif; ?>
    </form>

    <table id="categoryTable" class="table table-bordered table-striped table-hover">
        <thead class="thead-dark">
        <tr>
            <th>#</th>
            <th>Category Name</th>
            <th>Tests Assigned</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php $i = 1; while ($row = $categories->fetch_assoc()): ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($row['category_name']) ?></td>
                <td>
                    <?php
                    $res = $conn->query("
                        SELECT name FROM tests 
                        WHERE test_id IN (SELECT test_id FROM category_tests WHERE category_id = {$row['category_id']}) 
                        AND deleted_at IS NULL 
                        ORDER BY name
                    ");
                    $names = [];
                    while ($r = $res->fetch_assoc()) {
                        $names[] = $r['name'];
                    }
                    echo $names ? implode(', ', $names) : '<span class="text-muted">None</span>';
                    ?>
                </td>
                <td>
                    <a href="test_category_manager.php?edit_id=<?php echo htmlspecialchars($row['category_id']); ?>" class="btn btn-sm btn-warning">✏️ Edit</a>
                    <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?php echo htmlspecialchars($row['category_id']); ?>)">🗑️ Delete</button>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

<script>
$(document).ready(function () {
    $('.select2').select2({
        placeholder: "Search & select multiple tests",
        width: '100%'
    });

    $('#categoryTable').DataTable({
        pageLength: 10,
        lengthMenu: [10, 20, 50],
        order: [[1, 'asc']],
        responsive: true
    });
});

function confirmDelete(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: "This will permanently delete the category and its assigned tests.",
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

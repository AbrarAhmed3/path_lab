<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'db.php';

// Handle Add/Edit
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $department_name = trim($_POST['department_name']);
    $department_id = $_POST['department_id'] ?? null;

    if ($department_id) {
        $stmt = $conn->prepare("UPDATE departments SET department_name = ? WHERE department_id = ?");
        $stmt->bind_param("si", $department_name, $department_id);
        $stmt->execute();
    } else {
        $stmt = $conn->prepare("INSERT INTO departments (department_name) VALUES (?)");
        $stmt->bind_param("s", $department_name);
        $stmt->execute();
    }

    header("Location: manage_departments.php");
    exit();
}

// Handle Delete (Soft)
if (isset($_GET['delete_id'])) {
    $id = (int)$_GET['delete_id'];
    $conn->query("UPDATE departments SET deleted_at = NOW() WHERE department_id = $id");
    header("Location: manage_departments.php?deleted=1");
    exit();
}

// Handle Restore
if (isset($_GET['restore_id'])) {
    $id = (int)$_GET['restore_id'];
    $conn->query("UPDATE departments SET deleted_at = NULL WHERE department_id = $id");
    header("Location: manage_departments.php?restored=1");
    exit();
}

// Edit Mode
$edit = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $edit = $conn->query("SELECT * FROM departments WHERE department_id = $edit_id")->fetch_assoc();
}

// Fetch all
$departments = $conn->query("SELECT * FROM departments WHERE deleted_at IS NULL ORDER BY department_name ASC");
$deleted_departments = $conn->query("SELECT * FROM departments WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC");

include 'admin_header.php';
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Departments</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<div class="container mt-5">
    <h3 class="mb-4">🏢 Department Manager</h3>

    <form method="POST" class="mb-4">
        <input type="hidden" name="department_id" value="<?= $edit['department_id'] ?? '' ?>">
        <div class="form-group">
            <label>Department Name</label>
            <input type="text" name="department_name" class="form-control" required value="<?= htmlspecialchars($edit['department_name'] ?? '') ?>">
        </div>
        <button type="submit" class="btn btn-primary"><?= $edit ? '✏️ Update' : '➕ Add' ?> Department</button>
        <?php if ($edit): ?>
            <a href="manage_departments.php" class="btn btn-secondary">❌ Cancel</a>
        <?php endif; ?>
    </form>

    <h5 class="mb-3">📋 Active Departments</h5>
    <table class="table table-bordered table-striped table-hover" id="activeTable">
        <thead class="thead-dark">
        <tr>
            <th>#</th>
            <th>Department Name</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php $i = 1; while ($row = $departments->fetch_assoc()): ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($row['department_name']) ?></td>
                <td>
                    <a href="manage_departments.php?edit_id=<?= $row['department_id'] ?>" class="btn btn-sm btn-warning">✏️ Edit</a>
                    <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?= $row['department_id'] ?>)">🗑️ Delete</button>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>

    <h5 class="mt-5 mb-3">🗑️ Deleted Departments</h5>
    <table class="table table-bordered table-hover table-sm" id="deletedTable">
        <thead class="thead-light">
        <tr>
            <th>#</th>
            <th>Department Name</th>
            <th>Deleted At</th>
            <th>Restore</th>
        </tr>
        </thead>
        <tbody>
        <?php $i = 1; while ($row = $deleted_departments->fetch_assoc()): ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($row['department_name']) ?></td>
                <td><?= $row['deleted_at'] ?></td>
                <td>
                    <a href="manage_departments.php?restore_id=<?= $row['department_id'] ?>" class="btn btn-sm btn-success">♻️ Restore</a>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>

<!-- JS Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script>
    $(document).ready(function () {
        $('#activeTable').DataTable();
        $('#deletedTable').DataTable();
    });

    function confirmDelete(id) {
        Swal.fire({
            title: 'Are you sure?',
            text: "This will move the department to trash!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'manage_departments.php?delete_id=' + id;
            }
        });
    }

    <?php if (isset($_GET['deleted'])): ?>
    Swal.fire({
        icon: 'success',
        title: 'Deleted!',
        text: 'Department moved to trash.',
        timer: 1500,
        showConfirmButton: false
    });
    <?php endif; ?>

    <?php if (isset($_GET['restored'])): ?>
    Swal.fire({
        icon: 'success',
        title: 'Restored!',
        text: 'Department restored successfully.',
        timer: 1500,
        showConfirmButton: false
    });
    <?php endif; ?>
</script>
</body>
</html>

<?php include 'admin_footer.php'; ?>

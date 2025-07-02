<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'admin_header.php';
include 'db.php';

$deleted = $conn->query("SELECT test_id, name FROM tests WHERE deleted_at IS NOT NULL ORDER BY name");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Restore Deleted Tests</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<div class="container mt-4">
    <h3>♻️ Restore Deleted Tests</h3>
    <table class="table table-bordered">
        <thead>
            <tr><th>Test Name</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php if ($deleted->num_rows > 0): ?>
            <?php while ($t = $deleted->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($t['name']) ?></td>
                    <td>
                        <a href="restore_test.php?id=<?= $t['test_id'] ?>" class="btn btn-sm btn-success">✅ Restore</a>
                        <button onclick="confirmPermanentDelete(<?= $t['test_id'] ?>)" class="btn btn-sm btn-danger">🗑️ Delete Permanently</button>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr><td colspan="2">No deleted tests to restore.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <a href="view_tests.php" class="btn btn-secondary">⬅️ Back</a>
</div>

<?php if (isset($_GET['deleted']) && $_GET['deleted'] == 1): ?>
<script>
Swal.fire({
    icon: 'success',
    title: 'Deleted Permanently',
    text: 'The test has been permanently removed!',
    timer: 1500,
    showConfirmButton: false
});
</script>
<?php endif; ?>

<script>
function confirmPermanentDelete(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: "This will permanently delete the test!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete permanently!'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'delete_permanent.php?id=' + id;
        }
    });
}
</script>

</body>
</html>

<?php include 'admin_footer.php'; ?>

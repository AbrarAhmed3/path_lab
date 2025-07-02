<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}

include 'admin_header.php';
include 'db.php';
?>

<!DOCTYPE html>
<html>
<head>
    <title>View Doctors</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        .action-btns a {
            margin-right: 5px;
        }
    </style>
</head>
<body>
<div class="container mt-5">
    <h3 class="mb-4">👨‍⚕️ Registered Doctors</h3>

    <a href="doctor_add.php" class="btn btn-primary mb-3">➕ Add New Doctor</a>

    <?php
    $show_inactive = isset($_GET['show_inactive']) && $_GET['show_inactive'] == 1;
    $query = $show_inactive 
        ? "SELECT * FROM doctors WHERE is_active = 0 ORDER BY name" 
        : "SELECT * FROM doctors WHERE is_active = 1 ORDER BY name";
    $res = $conn->query($query);
    ?>

    <a href="view_doctors.php?<?= $show_inactive ? '' : 'show_inactive=1' ?>" class="btn btn-outline-secondary mb-3">
        <?= $show_inactive ? '👨‍⚕️ Show Active Doctors' : '🗂 Show Inactive Doctors' ?>
    </a>

    <table class="table table-bordered table-striped">
        <thead class="thead-dark">
            <tr>
                <th>Doctor ID</th>
                <th>Name</th>
                <th>Qualification</th>
                <th>Reg. No</th>
                <th>Contact</th>
                <th>Specialization</th>
                <th>Commission (%)</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($res->num_rows > 0): ?>
                <?php while ($row = $res->fetch_assoc()): ?>
                <tr>
                    <td><?= $row['doctor_id'] ?></td>
                    <td><?= htmlspecialchars($row['name']) ?></td>
                    <td><?= htmlspecialchars($row['qualification']) ?></td>
                    <td><?= htmlspecialchars($row['reg_no']) ?></td>
                    <td><?= htmlspecialchars($row['contact']) ?></td>
                    <td><?= htmlspecialchars($row['specialization']) ?></td>
                    <td><?= number_format($row['commission_percent'], 2) ?>%</td>
                    <td class="action-btns">
                        <?php if (!$show_inactive): ?>
                            <a href="edit_doctor.php?id=<?= $row['doctor_id'] ?>" class="btn btn-sm btn-warning">✏️ Edit</a>
                            <a href="#" class="btn btn-sm btn-danger delete-btn" data-id="<?= $row['doctor_id'] ?>">🗑️ Delete</a>
                        <?php else: ?>
                            <a href="#" class="btn btn-sm btn-success restore-btn" data-id="<?= $row['doctor_id'] ?>">↩️ Restore</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="8" class="text-center">No doctors found.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.querySelectorAll('.delete-btn').forEach(btn => {
    btn.addEventListener('click', function (e) {
        e.preventDefault();
        const id = this.getAttribute('data-id');

        Swal.fire({
            title: 'Are you sure?',
            text: "Doctor will be deactivated.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, deactivate!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `delete_doctor.php?id=${id}`;
            }
        });
    });
});

document.querySelectorAll('.restore-btn').forEach(btn => {
    btn.addEventListener('click', function (e) {
        e.preventDefault();
        const id = this.getAttribute('data-id');

        Swal.fire({
            title: 'Restore this doctor?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, restore',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `restore_doctor.php?id=${id}`;
            }
        });
    });
});
</script>

</body>
</html>

<?php include 'admin_footer.php'; ?>

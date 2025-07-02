<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'admin_header.php';
include 'db.php';

$sql = "SELECT a.assignment_id, p.name AS patient_name, t.name AS test_name, a.assigned_date
        FROM test_assignments a
        LEFT JOIN results r ON a.assignment_id = r.assignment_id
        JOIN patients p ON a.patient_id = p.patient_id
        JOIN tests t ON a.test_id = t.test_id
        WHERE r.assignment_id IS NULL
        ORDER BY a.assigned_date DESC";

$pending_tests = $conn->query($sql);
?>

<div class="container mt-4">
    <h3>🔔 Pending Lab Test Notifications</h3>
    <?php if ($pending_tests->num_rows > 0): ?>
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>Patient Name</th>
                    <th>Test Name</th>
                    <th>Assigned Date</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $pending_tests->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['patient_name']) ?></td>
                        <td><?= htmlspecialchars($row['test_name']) ?></td>
                        <td><?= $row['assigned_date'] ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="alert alert-info">✅ No pending lab tests!</div>
    <?php endif; ?>
</div>

<?php include 'admin_footer.php'; ?>

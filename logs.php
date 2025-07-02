<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
?>

<?php
include 'admin_header.php';
include 'db.php';

// Fetch actual logs from a table named `activity_logs`
$logs_result = $conn->query("SELECT activity, created_at FROM activity_logs ORDER BY created_at DESC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>System Logs</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        .log-box {
            margin-top: 40px;
        }
    </style>
</head>
<body>
<div class="container log-box">
    <h4 class="mb-4">🕵️ System Logs</h4>
    <table class="table table-striped">
        <thead>
            <tr><th>Activity</th><th>Timestamp</th></tr>
        </thead>
        <tbody>
            <?php while ($log = $logs_result->fetch_assoc()): ?>
                <tr>
                    <td><?= $log['activity'] ?></td>
                    <td><?= $log['created_at'] ?></td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
    <a href="admin_dashboard.php" class="btn btn-secondary">⬅ Back to Dashboard</a>
</div>
</body>
</html>
<?php include 'admin_footer.php'; ?>

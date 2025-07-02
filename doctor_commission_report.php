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
    <title>Doctor Commission Report</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
</head>
<body>
<div class="container mt-5">
    <h3 class="mb-4">💼 Doctor Commission Report</h3>

    <table id="commissionTable" class="table table-bordered table-striped">
        <thead class="thead-dark">
            <tr>
                <th>Doctor Name</th>
                <th>Total Referred Patients</th>
                <th>Total Commissions</th>
                <th>Total Paid (₹)</th>
                <th>Total Pending (₹)</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $query = "
            SELECT 
                d.doctor_id,
                d.name AS doctor_name,
                COUNT(DISTINCT b.billing_id) AS total_patients,
                IFNULL(SUM(dc.commission_amount), 0) AS total_commission,
                IFNULL(SUM(CASE WHEN dc.is_paid = 1 THEN dc.commission_amount ELSE 0 END), 0) AS total_paid,
                IFNULL(SUM(CASE WHEN dc.is_paid = 0 THEN dc.commission_amount ELSE 0 END), 0) AS total_pending
            FROM doctors d
            LEFT JOIN doctor_commissions dc ON dc.doctor_id = d.doctor_id
            LEFT JOIN billing b ON b.billing_id = dc.billing_id
            GROUP BY d.doctor_id
        ";

        $result = $conn->query($query);
        while ($row = $result->fetch_assoc()):
        ?>
            <tr>
                <td><?= htmlspecialchars($row['doctor_name']) ?></td>
                <td><?= $row['total_patients'] ?></td>
                <td>₹ <?= number_format($row['total_commission'], 2) ?></td>
                <td class="text-success">₹ <?= number_format($row['total_paid'], 2) ?></td>
                <td class="text-danger">₹ <?= number_format($row['total_pending'], 2) ?></td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>

<!-- JS Libraries -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script>
$(document).ready(function () {
    $('#commissionTable').DataTable({
        "pageLength": 10,
        "lengthMenu": [5, 10, 25, 50],
        "order": [[ 0, "asc" ]],
        dom: 'Bfrtip',
        buttons: ['copy', 'csv', 'excel', 'pdf', 'print']
    });
});
</script>

</body>
</html>

<?php include 'admin_footer.php'; ?>

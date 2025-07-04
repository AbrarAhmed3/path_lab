<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'admin_header.php';
include 'db.php';

// Fetch all test assignments for visits whose rstatus is not 'complete'
$sql = "
    SELECT
      a.assignment_id,
      a.patient_id,
      a.billing_id,
      p.name AS patient_name,
      t.name AS test_name,
      a.assigned_date
    FROM test_assignments a
    JOIN billing        b ON a.billing_id = b.billing_id
    JOIN patients       p ON a.patient_id = p.patient_id
    JOIN tests          t ON a.test_id    = t.test_id
    WHERE b.rstatus <> 'complete'
    ORDER BY a.assigned_date DESC
";
$pending_tests = $conn->query($sql);
?>
<!-- DataTables CSS -->
<link
  rel="stylesheet"
  href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap4.min.css"
/>

<div class="container mt-4">
  <h3>🔔 Pending Lab Test Notifications</h3>

  <?php if ($pending_tests && $pending_tests->num_rows > 0): ?>
    <table
      id="pending-tests-table"
      class="table table-bordered table-striped"
      style="width:100%"
    >
      <thead class="thead-light">
        <tr>
          <th>Patient Name</th>
          <th>Test Name</th>
          <th>Assigned Date</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php while ($row = $pending_tests->fetch_assoc()):
          $assignedTs = strtotime($row['assigned_date']);
          $ageHours   = (time() - $assignedTs) / 3600;
          $rowClass   = $ageHours > 48 ? 'table-warning' : '';
        ?>
          <tr class="<?= $rowClass ?>">
            <td><?= htmlspecialchars($row['patient_name']) ?></td>
            <td><?= htmlspecialchars($row['test_name']) ?></td>
            <td>
              <?= date('d M Y, H:i', $assignedTs) ?>
              <?php if ($ageHours > 48): ?>
                <span class="badge badge-warning">>48 h</span>
              <?php endif; ?>
            </td>
            <td>
              <a
                href="enter_results.php?assignment_id=<?= $row['assignment_id'] ?>&patient_id=<?= $row['patient_id'] ?>&billing_id=<?= $row['billing_id'] ?>"
                class="btn btn-sm btn-primary"
              >
                Enter Result
              </a>
            </td>
          </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  <?php else: ?>
    <div class="alert alert-info">✅ No pending lab tests!</div>
  <?php endif; ?>
</div>

<!-- jQuery is included via admin_header.php -->
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap4.min.js"></script>
<script>
  $(document).ready(function() {
    $('#pending-tests-table').DataTable({
      order: [[2, 'desc']],
      pageLength: 10,
      lengthMenu: [[10, 25, 50], [10, 25, 50]]
    });

    // Auto-refresh every 5 minutes
    setTimeout(() => location.reload(), 300000);
  });
</script>

<?php include 'admin_footer.php'; ?>

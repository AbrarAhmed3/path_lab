<?php
// saved_reports.php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit;
}

// 1) Bootstrap your admin layout
include 'admin_header.php';
include 'db.php';

// 2) Pull filter values (defaults to last 7 days)
$start = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
$end   = $_GET['end_date']   ?? date('Y-m-d');

// 3) Fetch only generated reports in the date range
$stmt = $conn->prepare("
    SELECT b.billing_id, b.patient_id, b.finalized_on, p.name
      FROM billing b
      JOIN patients p ON b.patient_id = p.patient_id
     WHERE b.gstatus = 'generated'
       AND DATE(b.finalized_on) BETWEEN ? AND ?
     ORDER BY b.finalized_on DESC
");
$stmt->bind_param('ss', $start, $end);
$stmt->execute();
$reports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<div class="container mt-4">
  <h2>Saved Reports</h2>

  <!-- Date‐range filter -->
  <form method="GET" class="form-inline mb-4">
    <label class="mr-2">From:
      <input
        type="date" name="start_date"
        class="form-control ml-2"
        value="<?= htmlspecialchars($start) ?>"
      >
    </label>
    <label class="ml-4 mr-2">To:
      <input
        type="date" name="end_date"
        class="form-control ml-2"
        value="<?= htmlspecialchars($end) ?>"
      >
    </label>
    <button class="btn btn-primary ml-4">Filter</button>
  </form>

  <table id="reportsTable" class="table table-striped table-bordered">
    <thead>
      <tr>
        <th>Patient Name</th>
        <th>Patient ID</th>
        <th>Bill No</th>
        <th>Generated On</th>
        <th style="width:120px">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($reports as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['name']) ?></td>
          <td><?= 'HPI_' . htmlspecialchars($r['patient_id']) ?></td>
          <td><?= 'HDC_' . htmlspecialchars($r['billing_id']) ?></td>
          <td><?= date('d-m-Y', strtotime($r['finalized_on'])) ?></td>
          <td class="text-center">
            <!-- Download -->
            <a
              href="print_report.php?patient_id=<?= $r['patient_id'] ?>&billing_id=<?= $r['billing_id'] ?>&download=1"
              class="btn btn-sm btn-success"
              title="Download PDF"
            >⬇</a>
            <!-- Print -->
            <button
              class="btn btn-sm btn-primary ml-1"
              title="Print PDF"
              onclick="window.open(
                'print_report.php?patient_id=<?= $r['patient_id'] ?>&billing_id=<?= $r['billing_id'] ?>&pdf=1',
                '_blank'
              )"
            >🖨</button>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- 4) DataTables scripts: place after your content, before admin_footer -->
<link
  rel="stylesheet"
  href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css"
/>
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
  $(function(){
    $('#reportsTable').DataTable({
      order: [[3, 'desc']],
      columnDefs: [{ orderable: false, targets: 4 }]
    });
  });
</script>

<?php
// 5) Close out with your admin footer
include 'admin_footer.php';

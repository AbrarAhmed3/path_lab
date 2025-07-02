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

// Handle date filter
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$where = '';
if ($start_date && $end_date) {
    $where = "WHERE b.billing_date BETWEEN '$start_date' AND '$end_date'";
}

// Fetch billing report
$result = $conn->query("SELECT b.billing_id, p.name, b.billing_date, b.total_amount, b.paid_amount FROM billing b JOIN patients p ON b.patient_id = p.patient_id $where ORDER BY b.billing_date DESC");

// Log exports
if (isset($_GET['export']) && in_array($_GET['export'], ['excel', 'pdf'])) {
    $export_type = strtoupper($_GET['export']);
    $stmt = $conn->prepare("INSERT INTO activity_logs (activity) VALUES (?)");
    $activity = "Billing report exported as $export_type" . ($start_date && $end_date ? " (From $start_date to $end_date)" : "");
    $stmt->bind_param("s", $activity);
    $stmt->execute();
    $stmt->close();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Export Billing Reports</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <style>
        @media print {
            body * { visibility: hidden !important; }
            #report-area, #report-area * {
                visibility: visible !important;
                color: black !important;
            }
            #report-area {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
            }
        }
    </style>
</head>
<body>
<div class="container mt-5">
    <h4 class="mb-4">📤 Export Billing Report</h4>

    <form method="GET" class="form-inline mb-3">
        <label class="mr-2">From:</label>
        <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" class="form-control mr-2">
        <label class="mr-2">To:</label>
        <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" class="form-control mr-2">
        <button type="submit" class="btn btn-primary">Filter</button>
    </form>

    <div class="mb-3">
        <button onclick="handleExport('excel')" class="btn btn-success">📥 Export to Excel</button>
        <button onclick="handleExport('pdf')" class="btn btn-danger ml-2">🖨️ Export to PDF</button>
        <button onclick="window.print()" class="btn btn-info ml-2">🖨️ Print</button>
    </div>

    <div id="report-area">
        <table class="table table-bordered" id="billing-table">
            <thead>
                <tr>
                    <th>Invoice ID</th>
                    <th>Patient Name</th>
                    <th>Date</th>
                    <th>Total</th>
                    <th>Paid</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row['billing_id'] ?></td>
                        <td><?= $row['name'] ?></td>
                        <td><?= $row['billing_date'] ?></td>
                        <td>₹ <?= number_format($row['total_amount'], 2) ?></td>
                        <td>₹ <?= number_format($row['paid_amount'], 2) ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <a href="admin_dashboard.php" class="btn btn-secondary">⬅ Back to Dashboard</a>
</div>

<script>
function handleExport(type) {
    const url = new URL(window.location.href);
    url.searchParams.set('export', type);
    window.history.pushState({}, '', url);
    if (type === 'excel') exportToExcel('billing-table');
    else exportToPDF();
}

function exportToExcel(tableId) {
    const table = document.getElementById(tableId);
    const wb = XLSX.utils.table_to_book(table, { sheet: "Billing Report" });
    XLSX.writeFile(wb, "Billing_Report.xlsx");
}

async function exportToPDF() {
    const { jsPDF } = window.jspdf;
    const reportArea = document.getElementById("report-area");
    const canvas = await html2canvas(reportArea);
    const imgData = canvas.toDataURL("image/png");
    const pdf = new jsPDF("p", "mm", "a4");
    const imgProps = pdf.getImageProperties(imgData);
    const pdfWidth = pdf.internal.pageSize.getWidth();
    const pdfHeight = (imgProps.height * pdfWidth) / imgProps.width;
    pdf.addImage(imgData, "PNG", 0, 0, pdfWidth, pdfHeight);
    pdf.save("Billing_Report.pdf");
}
</script>
</body>
</html>
<?php include 'admin_footer.php'; ?>
<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'db.php';

$from_date = $_GET['from'] ?? date('Y-m-01');
$to_date = $_GET['to'] ?? date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_paid'])) {
    $commission_id = intval($_POST['commission_id']);
    $stmt = $conn->prepare("UPDATE doctor_commissions SET is_paid = 1, paid_on = CURDATE() WHERE id = ?");
    $stmt->bind_param("i", $commission_id);
    $stmt->execute();
    $stmt->close();

    $_SESSION['mark_paid_success'] = true;
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . '?' . http_build_query($_GET));
    exit();
}

include 'admin_header.php';

$stmt = $conn->prepare("SELECT SUM(paid_amount) AS total_income FROM billing WHERE billing_date BETWEEN ? AND ?");
$stmt->bind_param("ss", $from_date, $to_date);
$stmt->execute();
$income = $stmt->get_result()->fetch_assoc()['total_income'] ?? 0;
$stmt->close();

$stmt = $conn->prepare("SELECT SUM(commission_amount) AS total_expense FROM doctor_commissions dc JOIN billing b ON dc.billing_id = b.billing_id WHERE b.billing_date BETWEEN ? AND ?");
$stmt->bind_param("ss", $from_date, $to_date);
$stmt->execute();
$expense = $stmt->get_result()->fetch_assoc()['total_expense'] ?? 0;
$stmt->close();

$net = $income - $expense;

$pie_income = $conn->query("SELECT SUM(paid_amount) as total_income FROM billing WHERE paid_amount > 0")->fetch_assoc()['total_income'] ?? 0;
$pie_commission = $conn->query("SELECT SUM(commission_amount) as total_commission FROM doctor_commissions WHERE is_paid = 1")->fetch_assoc()['total_commission'] ?? 0;

$line_data = [];
$line_stmt = $conn->prepare("SELECT DATE(billing_date) as date_only, SUM(paid_amount) as daily_income FROM billing WHERE billing_date BETWEEN ? AND ? GROUP BY date_only ORDER BY date_only");
$line_stmt->bind_param("ss", $from_date, $to_date);
$line_stmt->execute();
$result = $line_stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $line_data[] = ['date' => $row['date_only'], 'amount' => (float)$row['daily_income']];
}
$line_stmt->close();
$line_dates = json_encode(array_column($line_data, 'date'));
$line_amounts = json_encode(array_column($line_data, 'amount'));
?>

<!DOCTYPE html>
<html>
<head>
    <title>Cashbook</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <style>
        .dataTables_wrapper .dataTables_filter { float: right; }
        .dataTables_wrapper .dataTables_paginate { float: right; }
        .nav-tabs .nav-link.active {
            background-color: #343a40;
            color: white;
            border-color: #343a40;
        }
        .nav-tabs .nav-link {
            color: #343a40;
        }
    </style>
</head>
<body>
<?php if (isset($_SESSION['mark_paid_success'])): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            Swal.fire('Success', 'Commission marked as paid!', 'success');
        });
    </script>
    <?php unset($_SESSION['mark_paid_success']); ?>
<?php endif; ?>
<div class="container mt-4">
    <div class="card mb-3">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">📒 Cashbook Summary</h5>
            <form class="form-inline" method="GET">
                <label class="text-white mr-2">From:</label>
                <input type="date" name="from" value="<?= $from_date ?>" class="form-control mr-2">
                <label class="text-white mr-2">To:</label>
                <input type="date" name="to" value="<?= $to_date ?>" class="form-control mr-2">
                <button class="btn btn-warning btn-sm">📅 Filter</button>
            </form>
        </div>
        <div class="card-body">
            <div class="row text-center">
                <div class="col-md-4 mb-3">
                    <div class="p-3 bg-success text-white rounded shadow-sm">
                        <h5>Total Income</h5>
                        <h3>₹ <?= number_format($income, 2) ?></h3>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="p-3 bg-danger text-white rounded shadow-sm">
                        <h5>Doctor Commission</h5>
                        <h3>₹ <?= number_format($expense, 2) ?></h3>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="p-3 bg-info text-white rounded shadow-sm">
                        <h5>Net Cash</h5>
                        <h3>₹ <?= number_format($net, 2) ?></h3>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div id="cashPieChart"></div>
        </div>
        <div class="col-md-6">
            <div id="lineIncomeChart"></div>
        </div>
    </div>

    <ul class="nav nav-tabs mt-4" id="cashbookTabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" id="income-tab" data-toggle="tab" href="#income" role="tab">💰 Income</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="expense-tab" data-toggle="tab" href="#expense" role="tab">💸 Expense</a>
        </li>
    </ul>

    <div class="tab-content border p-3" id="cashbookTabsContent">
        <div class="tab-pane fade show active" id="income" role="tabpanel">
            <h5>💰 Patient Payments</h5>
            <table id="incomeTable" class="table table-bordered table-sm display responsive nowrap">
                <thead class="thead-light">
                <tr>
                    <th>Bill ID</th>
                    <th>Patient</th>
                    <th>Date</th>
                    <th>Paid Amount</th>
                </tr>
                </thead>
                <tbody>
                <?php
                $stmt = $conn->prepare("SELECT b.billing_id, b.billing_date, b.paid_amount, p.name FROM billing b JOIN patients p ON b.patient_id = p.patient_id WHERE b.billing_date BETWEEN ? AND ? AND b.paid_amount > 0 ORDER BY b.billing_date DESC");
                $stmt->bind_param("ss", $from_date, $to_date);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()):
                ?>
                <tr>
                    <td>#<?= $row['billing_id'] ?></td>
                    <td><?= htmlspecialchars($row['name']) ?></td>
                    <td><?= date('d-m-Y', strtotime($row['billing_date'])) ?></td>
                    <td>₹ <?= number_format($row['paid_amount'], 2) ?></td>
                </tr>
                <?php endwhile; $stmt->close(); ?>
                </tbody>
            </table>
        </div>

        <div class="tab-pane fade" id="expense" role="tabpanel">
            <h5>💸 Doctor Commissions</h5>
            <table id="expenseTable" class="table table-bordered table-sm display responsive nowrap">
                <thead class="thead-light">
                <tr>
                    <th>Doctor</th>
                    <th>Commission</th>
                    <th>Bill ID</th>
                    <th>Bill Date</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                <?php
                $stmt = $conn->prepare("SELECT dc.id, d.name AS doctor_name, dc.commission_amount, dc.is_paid, b.billing_id, b.billing_date FROM doctor_commissions dc JOIN doctors d ON dc.doctor_id = d.doctor_id JOIN billing b ON dc.billing_id = b.billing_id WHERE b.billing_date BETWEEN ? AND ? ORDER BY b.billing_date DESC");
                $stmt->bind_param("ss", $from_date, $to_date);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()):
                ?>
                <tr>
                    <td><?= htmlspecialchars($row['doctor_name']) ?></td>
                    <td>₹ <?= number_format($row['commission_amount'], 2) ?></td>
                    <td>#<?= $row['billing_id'] ?></td>
                    <td><?= date('d-m-Y', strtotime($row['billing_date'])) ?></td>
                    <td>
                        <?php if ($row['is_paid']): ?>
                            <span class="badge badge-success">Paid</span>
                        <?php else: ?>
                            <form method="POST" class="d-inline mark-paid-form">
                                <input type="hidden" name="commission_id" value="<?= $row['id'] ?>">
                                <input type="hidden" name="mark_paid" value="1">
                                <button type="submit" class="btn btn-sm btn-outline-danger">Mark Paid</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; $stmt->close(); ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>

<script>
$(document).ready(function() {
    $('#incomeTable, #expenseTable').DataTable({
        responsive: true,
        pageLength: 10,
        dom: 'Bfrtip',
        buttons: ['excelHtml5']
    });

    document.querySelectorAll('.mark-paid-form').forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            Swal.fire({
                title: 'Mark as Paid?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes',
            }).then((result) => {
                if (result.isConfirmed) form.submit();
            });
        });
    });

    new ApexCharts(document.querySelector("#cashPieChart"), {
        chart: { type: 'donut' },
        series: [<?= $pie_income ?>, <?= $pie_commission ?>],
        labels: ['Income', 'Doctor Commission'],
        colors: ['#28a745', '#dc3545'],
        legend: { position: 'bottom' },
        tooltip: {
            y: {
                formatter: function (val) {
                    return "₹ " + val.toLocaleString();
                }
            }
        }
    }).render();

    new ApexCharts(document.querySelector("#lineIncomeChart"), {
        chart: { type: 'line' },
        series: [{
            name: 'Daily Income',
            data: <?= $line_amounts ?>
        }],
        xaxis: {
            categories: <?= $line_dates ?>,
            labels: { rotate: -45 }
        },
        colors: ['#007bff'],
        stroke: { curve: 'smooth' },
        tooltip: {
            y: {
                formatter: function (val) {
                    return "₹ " + val.toLocaleString();
                }
            }
        }
    }).render();
});
</script>
<?php include 'admin_footer.php'; ?>
</body>
</html>

<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}

include 'db.php';

$total_patients = $conn->query("SELECT COUNT(*) FROM patients")->fetch_row()[0];
$total_tests = $conn->query("SELECT COUNT(*) FROM tests")->fetch_row()[0];
$total_bills = $conn->query("SELECT COUNT(*) FROM billing")->fetch_row()[0];
$total_revenue = $conn->query("SELECT SUM(paid_amount) FROM billing")->fetch_row()[0] ?? 0;

$pending_reports = $conn->query("
    SELECT b.billing_id, b.patient_id, p.name, p.contact 
    FROM billing b 
    JOIN patients p ON b.patient_id = p.patient_id 
    WHERE b.fstatus != 'finalized' AND b.rstatus = 'complete'
    LIMIT 100
");


$pending_bills = $conn->query("
    SELECT b.billing_id, b.patient_id, p.name, b.total_amount, b.paid_amount 
    FROM billing b 
    JOIN patients p ON b.patient_id = p.patient_id 
    WHERE b.balance_amount != 0 
    LIMIT 100
");


$pending_tests = $conn->query("
    SELECT a.assignment_id, a.patient_id, a.billing_id, p.name, t.name as test_name
    FROM test_assignments a
    LEFT JOIN test_results r ON a.assignment_id = r.assignment_id
    JOIN patients p ON a.patient_id = p.patient_id
    JOIN tests t ON a.test_id = t.test_id
    WHERE r.assignment_id IS NULL
    LIMIT 100
");



$pending_reports_count = $conn->query("SELECT COUNT(*) FROM billing WHERE fstatus = 'finalized' and rstatus = 'complete'")->fetch_row()[0];

$pending_bills_count = $conn->query("
    SELECT COUNT(*) FROM billing WHERE balance_amount != 0
")->fetch_row()[0];

$pending_tests_count = $conn->query("SELECT COUNT(*) FROM test_assignments a LEFT JOIN test_results r ON a.assignment_id = r.assignment_id WHERE r.assignment_id IS NULL")->fetch_row()[0];

$currentPage = basename(__FILE__);
include 'admin_header.php';
?>

<!-- Google Fonts & Bootstrap -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">

<style>
    body {
        font-family: 'Inter', sans-serif;
        background: #f0f2f5;
        color: #333;
    }

    .card-stat {
        border-radius: 1rem;
        padding: 24px;
        background: linear-gradient(135deg, #ffffff, #f9fafb);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        text-align: center;
        transition: all 0.3s ease;
    }

    .card-stat:hover {
        transform: translateY(-6px);
        box-shadow: 0 10px 24px rgba(0, 0, 0, 0.12);
    }

    .card-stat i {
        font-size: 2.5rem;
        margin-bottom: 12px;
        color: #4a90e2;
    }

    .card-stat h4 {
        font-size: 1.2rem;
        font-weight: 600;
        margin-bottom: 6px;
    }

    .card-stat p {
        font-size: 1.8rem;
        font-weight: bold;
        color: #222;
    }

    .table-section {
        background: white;
        border-radius: 1rem;
        padding: 20px;
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.06);
    }

    .table-section h5 {
        font-weight: 700;
        font-size: 1.2rem;
        margin-bottom: 1rem;
    }

    .badge-counter {
        background: #dc3545;
        color: white;
        font-size: 0.75rem;
        padding: 4px 10px;
        border-radius: 1rem;
        margin-left: 8px;
    }

    .dataTables_wrapper .dataTables_filter input {
        border-radius: 8px;
        border: 1px solid #ccc;
        padding: 6px 12px;
    }

    .btn-sm {
        font-size: 0.85rem;
        padding: 6px 16px;
        border-radius: 30px;
    }

    .btn-outline-primary:hover,
    .btn-outline-warning:hover,
    .btn-outline-secondary:hover {
        transform: scale(1.05);
    }
</style>
<title>Diagnoxis / Dashboard</title>

<!-- DASHBOARD CARDS -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-sm-6">
        <div class="card-stat">
            <i class="fas fa-users"></i>
            <h4>Total Patients</h4>
            <p><?= $total_patients ?></p>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card-stat">
            <i class="fas fa-vials"></i>
            <h4>Total Tests</h4>
            <p><?= $total_tests ?></p>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card-stat">
            <i class="fas fa-file-invoice-dollar"></i>
            <h4>Total Invoices</h4>
            <p><?= $total_bills ?></p>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card-stat">
            <i class="fas fa-coins"></i>
            <h4>Total Revenue</h4>
            <p>₹ <?= number_format($total_revenue, 2) ?></p>
        </div>
    </div>
</div>

<!-- PENDING TABLES -->
<div class="row g-4">
    <div class="col-md-4">
        <div class="table-section">
            <h5><i class="fas fa-hourglass-half"></i> Pending Reports <span class="badge-counter"><?= $pending_reports_count ?></span></h5>
            <table id="pendingReportsTable" class="display nowrap" style="width:100%">
                <thead>
                    <tr>
                        <th>Patient</th>
                        <th>Contact</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($r = $pending_reports->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['name']) ?></td>
                            <td><?= htmlspecialchars($r['contact']) ?></td>
                            <td>
                                <a href="finalize_report.php?patient_id=<?= $r['patient_id'] ?>&billing_id=<?= $r['billing_id'] ?>"
                                    class="btn btn-outline-primary btn-sm">
                                    Finalize
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>

                </tbody>
            </table>
        </div>
    </div>
    <div class="col-md-4">
        <div class="table-section">
            <h5><i class="fas fa-money-check-alt"></i> Pending Bills <span class="badge-counter"><?= $pending_bills_count ?></span></h5>
            <table id="pendingBillsTable" class="display nowrap" style="width:100%">
                <thead>
                    <tr>
                        <th>Patient</th>
                        <th>Amount</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($b = $pending_bills->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($b['name']) ?></td>
                            <td>₹ <?= number_format($b['total_amount'] - $b['paid_amount'], 2) ?></td>
                            <td>
                                <a href="billing.php?patient_id=<?= $b['patient_id'] ?>&billing_id=<?= $b['billing_id'] ?>"
                                    class="btn btn-outline-warning btn-sm">
                                    Pay
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>

                </tbody>
            </table>
        </div>
    </div>
    <div class="col-md-4">
        <div class="table-section">
            <h5><i class="fas fa-vial"></i> Pending Tests <span class="badge-counter"><?= $pending_tests_count ?></span></h5>
            <table id="pendingTestsTable" class="display nowrap" style="width:100%">
                <thead>
                    <tr>
                        <th>Patient</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($t = $pending_tests->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($t['name']) ?></td>
                            <td>
                                <a href="enter_results.php?patient_id=<?= $t['patient_id'] ?>&billing_id=<?= $t['billing_id'] ?>"
                                    class="btn btn-outline-secondary btn-sm">Update</a>
                            </td>
                        </tr>
                    <?php endwhile; ?>

                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script>
    $(document).ready(function() {
        const config = {
            responsive: true,
            pageLength: 5,
            lengthChange: false,
            pagingType: "simple_numbers",
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search...",
                paginate: {
                    previous: "<i class='fas fa-chevron-left'></i>",
                    next: "<i class='fas fa-chevron-right'></i>"
                }
            },
            drawCallback: function(settings) {
                const api = this.api();
                const pagination = $(this).closest('.dataTables_wrapper').find('.dataTables_paginate');
                const pages = api.page.info().pages;
                const current = api.page.info().page + 1;

                if (pages > 3) {
                    pagination.find('span a').hide();
                    pagination.find('span a').filter(function() {
                        const pageNum = parseInt($(this).text());
                        return pageNum === 1 || pageNum === pages || Math.abs(current - pageNum) < 2;
                    }).show();
                }
            }
        };

        $('#pendingReportsTable').DataTable(config);
        $('#pendingBillsTable').DataTable(config);
        $('#pendingTestsTable').DataTable(config);
    });
</script>

<?php include 'admin_footer.php'; ?>
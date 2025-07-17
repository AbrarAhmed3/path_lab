<?php
if (!isset($conn)) {
    include 'db.php';
}
$pending_sql = "SELECT COUNT(*) AS total FROM test_assignments a 
                LEFT JOIN results r ON a.assignment_id = r.assignment_id 
                WHERE r.assignment_id IS NULL";
$pending_result = $conn->query($pending_sql);
$pending_count = ($pending_result && $pending_result->num_rows > 0) ? $pending_result->fetch_assoc()['total'] : 0;
?>
<!DOCTYPE html>
<html>

<head>
    <link rel="icon" type="image/x-icon" href="assets/diagnoxis/favicon.ico">



    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>


    <style>
        html,
        body {
            height: 100%;
            margin: 0;
            overflow: hidden;
            /* Prevent double scrollbars */
        }

        body {
            display: flex;
            flex-direction: row;
        }

        .sidebar {
            width: 250px;
            background-color: #343a40;
            color: white;
            padding-top: 20px;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: #6c757d #343a40;
        }

        .sidebar::-webkit-scrollbar {
            width: 6px;
        }

        .sidebar::-webkit-scrollbar-thumb {
            background-color: #6c757d;
            border-radius: 3px;
        }

        .sidebar a {
            display: block;
            padding: 10px 20px;
            color: white;
            text-decoration: none;
        }

        .sidebar a:hover {
            background-color: #495057;
        }

        .main-content {
            margin-left: 250px;
            padding: 20px;
            width: calc(100% - 250px);
            height: 100vh;
            overflow-y: auto;
            /* ✅ Scrolls independently */
        }

        .topbar {
            background-color: #f8f9fa;
            padding: 10px 20px;
            border-bottom: 1px solid #dee2e6;
        }

        .dropdown-toggle {
            cursor: pointer;
        }

        .topbar i {
            transition: color 0.2s;
        }

        .topbar i:hover {
            color: #007bff;
        }
        .sidebar a,
    .sidebar button {
    margin-bottom: 0.5rem;
    }

    </style>


</head>

<body>

    <?php
    $currentPage = basename($_SERVER['PHP_SELF']);
    ?>

<div class="sidebar">
    <!-- Logo -->
    <div class="text-center px-3">
        <div class="d-flex align-items-center justify-content-center mb-1">
            <img src="assets/diagnoxis/logo/abranex_logo_icon.png" alt="Diagnoxis" style="height: 45px; margin-right: 1px;">
            <h4 class="mb-0 text-white">Diagnoxis</h4>
        </div>
        <div class="text-right pr-1" style="font-size: 12px; color: #adb5bd;">by abranex</div>
    </div>
    <hr style="background:white">

    <!-- Dashboard -->
    <a href="admin_dashboard.php" class="<?= ($currentPage == 'admin_dashboard.php') ? 'bg-primary text-white' : '' ?>">
        📈 Dashboard
    </a>

    <!-- Patients -->
    <a href="add_patient.php" class="<?= ($currentPage == 'add_patient.php') ? 'bg-primary text-white' : '' ?>">
        ➕ Register Patient
    </a>

    <!-- Assignments -->
    <a href="assign_test.php" class="<?= ($currentPage == 'assign_test.php') ? 'bg-primary text-white' : '' ?>">
        📌 Assign Test
    </a>

    <!-- Results & Billing -->
        <a href="billing.php" class="<?= ($currentPage == 'billing.php') ? 'bg-primary text-white' : '' ?>">
        💳 Billing
    </a>
    <a href="enter_results.php" class="<?= ($currentPage == 'enter_results.php') ? 'bg-primary text-white' : '' ?>">
        📝 Enter Results
    </a>

    <!-- Reports -->
    <button class="btn btn-sm btn-secondary btn-block text-left" data-toggle="collapse" data-target="#reportMenu">
        📤 Reports
    </button>
    <div id="reportMenu" class="collapse <?= in_array($currentPage, [
            'finalize_report.php','generate_report.php','saved_reports.php'
        ]) ? 'show' : '' ?>">
        <a href="finalize_report.php" class="<?= ($currentPage == 'finalize_report.php') ? 'bg-primary text-white' : '' ?>">
            ✔️ Finalize Report
        </a>
        <a href="generate_report.php" class="<?= ($currentPage == 'generate_report.php') ? 'bg-primary text-white' : '' ?>">
            🧾 Generate Report
        </a>
                <a href="saved_reports.php" class="<?= ($currentPage == 'saved_reports.php') ? 'bg-primary text-white' : '' ?>">
            🗂️  Saved Reports
        </a>
    </div>

    <!-- Cashbook -->
    <a href="cashbook.php" class="<?= ($currentPage == 'cashbook.php') ? 'bg-primary text-white' : '' ?>">
        📒 Cashbook
    </a>
        <!-- Test Management -->
    <button class="btn btn-sm btn-secondary btn-block text-left" data-toggle="collapse" data-target="#testMenu">
        🧪 Test Management
    </button>
    <div id="testMenu" class="collapse <?= in_array($currentPage, [
            'add_test.php','view_tests.php',
            'test_category_manager.php','manage_departments.php'
        ]) ? 'show' : '' ?>">
        <a href="add_test.php" class="<?= ($currentPage == 'add_test.php') ? 'bg-primary text-white' : '' ?>">
            ➕ Add Test
        </a>
        <a href="view_tests.php" class="<?= ($currentPage == 'view_tests.php') ? 'bg-primary text-white' : '' ?>">
            📋 View Tests
        </a>
        <a href="test_category_manager.php" class="<?= ($currentPage == 'test_category_manager.php') ? 'bg-primary text-white' : '' ?>">
            📂 Categories
        </a>
            <a href="manage_departments.php" class="<?= ($currentPage == 'manage_departments.php') ? 'bg-primary text-white' : '' ?>">
        🏭 Departments
    </a>
    </div>

    <!-- Doctor Management -->
    <button class="btn btn-sm btn-secondary btn-block text-left" data-toggle="collapse" data-target="#doctorMenu">
        👨‍⚕️ Doctor Management
    </button>
    <div id="doctorMenu" class="collapse <?= in_array($currentPage, [
            'doctor_add.php','view_doctors.php','doctor_commission_report.php'
        ]) ? 'show' : '' ?>">
        <a href="doctor_add.php" class="<?= ($currentPage == 'doctor_add.php') ? 'bg-primary text-white' : '' ?>">
            ➕ Add Doctor
        </a>
        <a href="view_doctors.php" class="<?= ($currentPage == 'view_doctors.php') ? 'bg-primary text-white' : '' ?>">
            📋 View Doctors
        </a>
        <a href="doctor_commission_report.php" class="<?= ($currentPage == 'doctor_commission_report.php') ? 'bg-primary text-white' : '' ?>">
            💰 Commissions
        </a>
    </div>

    <!-- Utilities -->
    <a href="notifications.php" class="<?= ($currentPage == 'notifications.php') ? 'bg-primary text-white' : '' ?>">
        🔔 Notifications
    </a>
    <a href="lab_settings.php" class="<?= ($currentPage == 'lab_settings.php') ? 'bg-primary text-white' : '' ?>">
        ⚙️ Lab Settings
    </a>

    <!-- Profile & Logout -->
    <hr style="background:white">
    <a href="profile_settings.php" class="<?= ($currentPage == 'profile_settings.php') ? 'bg-primary text-white' : '' ?>">
        👤 Profile
    </a>
    <a href="change_password.php" class="<?= ($currentPage == 'change_password.php') ? 'bg-primary text-white' : '' ?>">
        🔑 Change Password
    </a>
    <a href="logout.php" class="<?= ($currentPage == 'logout.php') ? 'bg-primary text-white' : '' ?>">
        🔓 Logout
    </a>
</div>


<div class="main-content">

  <!-- Topbar -->
  <?php
    // Count any test assignments whose billing.rstatus isn’t ‘complete’
    $notiSql = "
      SELECT COUNT(*) AS cnt
        FROM test_assignments a
        JOIN billing        b ON a.billing_id = b.billing_id
       WHERE b.rstatus <> 'complete'
    ";
    $res = $conn->query($notiSql);
    $notiCount = $res->fetch_assoc()['cnt'] ?? 0;
  ?>
  <div class="topbar d-flex justify-content-end align-items-center">
      <!-- Notification Bell -->
      <div class="mr-3 position-relative">
          <a href="notifications.php" class="text-dark">
              <i class="fas fa-bell fa-lg"></i>
              <?php if ($notiCount > 0): ?>
                  <span class="badge badge-danger position-absolute" style="top:-5px; right:-8px;">
                    <?= $notiCount ?>
                  </span>
              <?php endif; ?>
          </a>
      </div>

      <!-- Profile Dropdown -->
      <div class="dropdown">
          <a
            class="dropdown-toggle text-dark d-flex align-items-center"
            href="#"
            id="userDropdown"
            data-toggle="dropdown"
            aria-haspopup="true"
            aria-expanded="false"
          >
              <?php
                $photo = $_SESSION['profile_photo'] ?? '';
                if ($photo && file_exists($photo)) {
                  echo '<img src="' . htmlspecialchars($photo) . '" class="rounded-circle mr-2" width="35" height="35">';
                } else {
                  $initial = strtoupper($_SESSION['admin_username'][0] ?? 'A');
                  echo "<div class=\"rounded-circle bg-primary text-white d-flex justify-content-center align-items-center mr-2\" style=\"width:35px;height:35px;font-size:16px;\">{$initial}</div>";
                }
              ?>
              <span><?= htmlspecialchars($_SESSION['admin_username']) ?></span>
          </a>
          <div class="dropdown-menu dropdown-menu-right" aria-labelledby="userDropdown">
              <a class="dropdown-item" href="profile_settings.php">
                <i class="fas fa-user-cog mr-1"></i> Profile Settings
              </a>
              <a class="dropdown-item" href="change_password.php">
                <i class="fas fa-key mr-1"></i> Change Password
              </a>
              <div class="dropdown-divider"></div>
              <a class="dropdown-item" href="logout.php">
                <i class="fas fa-sign-out-alt mr-1"></i> Logout
              </a>
          </div>
      </div>
  </div>
  <!-- …rest of your page content… -->

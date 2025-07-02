<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}

include 'admin_header.php';
include 'db.php';

// Fetch current lab settings (assuming only one row with id = 1)
$result = $conn->query("SELECT * FROM lab_settings WHERE id = 1");
$settings = $result->fetch_assoc();

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $lab_name = $_POST['lab_name'];
    $address1 = $_POST['address_line1'];
    $address2 = $_POST['address_line2'];
    $city     = $_POST['city'];
    $state    = $_POST['state'];
    $pincode  = $_POST['pincode'];
    $phone    = $_POST['phone'];
    $mobile   = $_POST['mobile'];
    $email    = $_POST['email'];
    $website  = $_POST['website'];
    $gst      = $_POST['gst_no'];
    $pan      = $_POST['pan_no'];
    $dl1      = $_POST['dl_no_1'];
    $dl2      = $_POST['dl_no_2'];
    $deals_in = $_POST['deals_in'];
    $fy_start = $_POST['financial_year_start'];
    $fy_end   = $_POST['financial_year_end'];

    // Handle logo upload
    $logo_path = $settings['logo_path'];
    if (!empty($_FILES['logo']['name'])) {
        $target_dir = "uploads/";
        if (!is_dir($target_dir)) mkdir($target_dir);
        $filename = 'logo_' . time() . '_' . basename($_FILES['logo']['name']);
        $target_file = $target_dir . $filename;

        if (move_uploaded_file($_FILES['logo']['tmp_name'], $target_file)) {
            $logo_path = $target_file;
        }
    }

    $stmt = $conn->prepare("UPDATE lab_settings SET lab_name=?, address_line1=?, address_line2=?, city=?, state=?, pincode=?, phone=?, mobile=?, email=?, website=?, gst_no=?, pan_no=?, dl_no_1=?, dl_no_2=?, deals_in=?, financial_year_start=?, financial_year_end=?, logo_path=? WHERE id=1");

    $stmt->bind_param("ssssssssssssssssss",
        $lab_name, $address1, $address2, $city, $state, $pincode,
        $phone, $mobile, $email, $website, $gst, $pan, $dl1, $dl2,
        $deals_in, $fy_start, $fy_end, $logo_path
    );

    if ($stmt->execute()) {
        echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        <script>
        Swal.fire({
            icon: 'success',
            title: 'Lab Settings Updated!',
            timer: 1800,
            showConfirmButton: false
        }).then(() => {
            window.location.href='lab_settings.php';
        });
        </script>";
        exit;
    } else {
        echo "<div class='alert alert-danger'>Error: " . $stmt->error . "</div>";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Lab Settings</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
<div class="container mt-4 mb-5">
    <h3 class="mb-4">⚙️ Lab Settings</h3>

    <!-- Logo upload section on top -->
    <div class="mb-4">
        <h5>🖼️ Current Logo:</h5>
        <?php if (!empty($settings['logo_path']) && file_exists($settings['logo_path'])): ?>
            <img src="<?= $settings['logo_path'] ?>" alt="Lab Logo" style="max-height: 100px;">
        <?php else: ?>
            <p class="text-muted">No logo uploaded yet.</p>
        <?php endif; ?>
    </div>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-group">
            <label>Upload New Logo</label>
            <input type="file" name="logo" class="form-control-file">
        </div>

        <div class="form-group">
            <label>Lab Name</label>
            <input type="text" name="lab_name" value="<?= htmlspecialchars($settings['lab_name']) ?>" class="form-control" required>
        </div>
        <div class="form-group">
            <label>Address Line 1</label>
            <input type="text" name="address_line1" value="<?= $settings['address_line1'] ?>" class="form-control">
        </div>
        <div class="form-group">
            <label>Address Line 2</label>
            <input type="text" name="address_line2" value="<?= $settings['address_line2'] ?>" class="form-control">
        </div>
        <div class="form-row">
            <div class="form-group col-md-4">
                <label>City</label>
                <input type="text" name="city" value="<?= $settings['city'] ?>" class="form-control">
            </div>
            <div class="form-group col-md-4">
                <label>State</label>
                <input type="text" name="state" value="<?= $settings['state'] ?>" class="form-control">
            </div>
            <div class="form-group col-md-4">
                <label>Pincode</label>
                <input type="text" name="pincode" value="<?= $settings['pincode'] ?>" class="form-control">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Phone</label>
                <input type="text" name="phone" value="<?= $settings['phone'] ?>" class="form-control">
            </div>
            <div class="form-group col-md-6">
                <label>Mobile</label>
                <input type="text" name="mobile" value="<?= $settings['mobile'] ?>" class="form-control">
            </div>
        </div>
        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" value="<?= $settings['email'] ?>" class="form-control">
        </div>
        <div class="form-group">
            <label>Website</label>
            <input type="text" name="website" value="<?= $settings['website'] ?>" class="form-control">
        </div>
        <div class="form-row">
            <div class="form-group col-md-6">
                <label>GST No.</label>
                <input type="text" name="gst_no" value="<?= $settings['gst_no'] ?>" class="form-control">
            </div>
            <div class="form-group col-md-6">
                <label>PAN No.</label>
                <input type="text" name="pan_no" value="<?= $settings['pan_no'] ?>" class="form-control">
            </div>
        </div>
        <div class="form-group">
            <label>Drug License No. 1</label>
            <input type="text" name="dl_no_1" value="<?= $settings['dl_no_1'] ?>" class="form-control">
        </div>
        <div class="form-group">
            <label>Drug License No. 2</label>
            <input type="text" name="dl_no_2" value="<?= $settings['dl_no_2'] ?>" class="form-control">
        </div>
        <div class="form-group">
            <label>Deals In</label>
            <textarea name="deals_in" rows="3" class="form-control"><?= $settings['deals_in'] ?></textarea>
        </div>
        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Financial Year Start</label>
                <input type="date" name="financial_year_start" value="<?= $settings['financial_year_start'] ?>" class="form-control">
            </div>
            <div class="form-group col-md-6">
                <label>Financial Year End</label>
                <input type="date" name="financial_year_end" value="<?= $settings['financial_year_end'] ?>" class="form-control">
            </div>
        </div>

        <button type="submit" class="btn btn-success">💾 Save Settings</button>
        <a href="admin_dashboard.php" class="btn btn-secondary">↩️ Cancel</a>
    </form>
</div>
</body>
</html>

<?php include 'admin_footer.php'; ?>

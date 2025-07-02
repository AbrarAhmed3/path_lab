<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'admin_header.php';
include 'db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name        = trim($_POST['name']);
    $age         = intval($_POST['age']);
    $gender      = $_POST['gender'];
    $contact     = trim($_POST['contact']);
    $address     = trim($_POST['address']);

    $is_pregnant = isset($_POST['is_pregnant']) ? intval($_POST['is_pregnant']) : 0;
    $gestational_weeks = $is_pregnant && !empty($_POST['gestational_weeks']) ? intval($_POST['gestational_weeks']) : null;

    if (!preg_match('/^\d{10}$/', $contact)) {
        echo "<script>alert('Contact number must be exactly 10 digits.'); window.history.back();</script>";
        exit();
    }

    $check = $conn->prepare("SELECT patient_id FROM patients WHERE contact = ?");
    $check->bind_param("s", $contact);
    $check->execute();
    $check->bind_result($existing_patient_id);
    $check->fetch();
    $check->close();

    if ($existing_patient_id) {
        $billing_check = $conn->prepare("SELECT billing_id FROM billing WHERE patient_id = ? AND status = 'draft' LIMIT 1");
        $billing_check->bind_param("i", $existing_patient_id);
        $billing_check->execute();
        $billing_check->bind_result($existing_billing_id);
        $billing_check->fetch();
        $billing_check->close();

        if (!$existing_billing_id) {
            $visit_note = 'New visit - returning patient';
            $billing_stmt = $conn->prepare("INSERT INTO billing (patient_id, total_amount, paid_amount, balance_amount, status, visit_note) VALUES (?, 0, 0, 0, 'draft', ?)");
            $billing_stmt->bind_param("is", $existing_patient_id, $visit_note);
            $billing_stmt->execute();
            $existing_billing_id = $conn->insert_id;
        }

        echo "
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        <script>
        Swal.fire({
            icon: 'info',
            title: 'Patient Already Exists!',
            text: 'Redirecting to Assign Tests...',
            timer: 1800,
            showConfirmButton: false
        }).then(() => {
            window.location.href='assign_test.php?patient_id=$existing_patient_id&billing_id=$existing_billing_id';
        });
        </script>";
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO patients (name, age, gender, contact, address,  is_pregnant, gestational_weeks) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sisssii", $name, $age, $gender, $contact, $address, $is_pregnant, $gestational_weeks);

    if ($stmt->execute()) {
        $new_patient_id = $conn->insert_id;
        $visit_note = 'Initial visit after registration';
        $billing_stmt = $conn->prepare("INSERT INTO billing (patient_id, total_amount, paid_amount, balance_amount, status, visit_note) VALUES (?, 0, 0, 0, 'draft', ?)");
        $billing_stmt->bind_param("is", $new_patient_id, $visit_note);
        $billing_stmt->execute();
        $new_billing_id = $conn->insert_id;

        echo "
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        <script>
        Swal.fire({
            icon: 'success',
            title: 'Patient Registered!',
            text: 'Redirecting to Assign Tests...',
            timer: 1800,
            showConfirmButton: false
        }).then(() => {
            window.location.href='assign_test.php?patient_id=$new_patient_id&billing_id=$new_billing_id';
        });
        </script>";
        exit;
    } else {
        echo "Database Error: " . $stmt->error;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Register New Patient</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body { background-color: #f4f7fc; }
        .card { border: none; border-radius: 8px; box-shadow: 0 0 15px rgba(0,0,0,0.05); }
        .form-control:focus { border-color: #007bff; box-shadow: none; }
        .form-group label { font-weight: 500; }
        .btn-primary { background-color: #007bff; border: none; }
        .btn-primary:hover { background-color: #0056b3; }
        .error-msg { color: red; font-size: 13px; display: none; }
    </style>
</head>
<body>
<div class="container mt-5">
    <div class="card">
        <div class="card-header bg-primary text-white">
            <h4 class="mb-0">🩺 Register New Patient</h4>
        </div>
        <div class="card-body">
            <form method="POST" id="patientForm" novalidate>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required>
                        <div class="error-msg">Name is required</div>
                    </div>
                    <div class="form-group col-md-3">
                        <label>Age <span class="text-danger">*</span></label>
                        <input type="number" name="age" class="form-control" min="1" required>
                        <div class="error-msg">Valid age is required</div>
                    </div>
                    <div class="form-group col-md-3">
                        <label>Gender <span class="text-danger">*</span></label>
                        <select name="gender" id="gender" class="form-control" required onchange="togglePregnancyFields()">
                            <option value="">--Select--</option>
                            <option>Male</option>
                            <option>Female</option>
                            <option>Other</option>
                        </select>
                        <div class="error-msg">Gender is required</div>
                    </div>
                </div>

                <div id="pregnancySection" style="display:none;">
                    <div class="form-row">
                        <div class="form-group col-md-3">
                            <label>Is Pregnant?</label>
                            <select name="is_pregnant" id="is_pregnant" class="form-control" onchange="toggleGestationalInput()">
                                <option value="0">No</option>
                                <option value="1">Yes</option>
                            </select>
                        </div>
                        <div class="form-group col-md-3" id="gestationalWeeksField" style="display:none;">
                            <label>Gestational Age (weeks)</label>
                            <input type="number" name="gestational_weeks" class="form-control" min="1" max="42">
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Contact Number <span class="text-danger">*</span></label>
                        <input type="text" name="contact" class="form-control" required pattern="\d{10}" maxlength="10" title="Enter exactly 10 digit mobile number">
                        <div class="error-msg">Contact must be exactly 10 digits</div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Address (optional)</label>
                    <textarea name="address" class="form-control" rows="3"></textarea>
                </div>

                <div class="text-right">
                    <button type="submit" class="btn btn-primary">➕ Register Patient</button>
                    <a href="admin_dashboard.php" class="btn btn-secondary">🏠 Back to Dashboard</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function togglePregnancyFields() {
    const gender = document.getElementById('gender').value;
    document.getElementById('pregnancySection').style.display = (gender === 'Female') ? 'block' : 'none';
}

function toggleGestationalInput() {
    const isPregnant = document.getElementById('is_pregnant').value;
    document.getElementById('gestationalWeeksField').style.display = (isPregnant === '1') ? 'block' : 'none';
}

document.getElementById("patientForm").addEventListener("submit", function(e) {
    let valid = true;
    const form = e.target;
    const fields = ["name", "age", "gender", "contact", "referred_by"];
    fields.forEach(name => {
        const input = form.elements[name];
        const errorDiv = input.parentElement.querySelector(".error-msg");
        if (!input.value.trim() || (name === "contact" && !/^\d{10}$/.test(input.value))) {
            errorDiv.style.display = "block";
            valid = false;
        } else {
            errorDiv.style.display = "none";
        }
    });
    if (!valid) e.preventDefault();
});
</script>

</body>
</html>

<?php include 'admin_footer.php'; ?>

<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'admin_header.php';
include 'db.php';

$test_id = isset($_GET['test_id']) ? intval($_GET['test_id']) : 0;

// 🔐 Validate test existence and not deleted
$test = $conn->query("
    SELECT t.*, c.category_name, d.department_name
    FROM tests t
    LEFT JOIN test_categories c ON t.category_id = c.category_id
    LEFT JOIN departments d ON c.department_id = d.department_id
    WHERE t.test_id = $test_id AND t.deleted_at IS NULL
")->fetch_assoc();

if (!$test) {
    echo "<script>alert('Test not found or deleted.'); window.location.href = 'view_tests.php';</script>";
    exit();
}

// ✅ Handle new reference range addition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_range'])) {
    $range_type = $_POST['range_type'];
    $condition_label = trim($_POST['condition_label']);
    $gender = $_POST['gender'];
    $age_min = $_POST['age_min'] !== '' ? intval($_POST['age_min']) : NULL;
    $age_max = $_POST['age_max'] !== '' ? intval($_POST['age_max']) : NULL;
    $gest_min = $_POST['gestation_min'] !== '' ? intval($_POST['gestation_min']) : NULL;
    $gest_max = $_POST['gestation_max'] !== '' ? intval($_POST['gestation_max']) : NULL;
    $value_low = $_POST['value_low'] !== '' ? $_POST['value_low'] : NULL;
    $value_high = $_POST['value_high'] !== '' ? $_POST['value_high'] : NULL;
    $unit = trim($_POST['unit']);
    $flag_label = trim($_POST['flag_label']);
    $notes = trim($_POST['notes']);

    $stmt = $conn->prepare("INSERT INTO test_ranges 
        (test_id, range_type, condition_label, gender, age_min, age_max, gestation_min, gestation_max, value_low, value_high, unit, flag_label, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssiiiisssss", $test_id, $range_type, $condition_label, $gender, $age_min, $age_max, $gest_min, $gest_max, $value_low, $value_high, $unit, $flag_label, $notes);
    $stmt->execute();
}

// 🗑 Handle range deletion
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    $conn->query("DELETE FROM test_ranges WHERE id = $delete_id");
}

$ranges = $conn->query("SELECT * FROM test_ranges WHERE test_id = $test_id ORDER BY id DESC");
?>

<script>
function handleRangeTypeChange(type) {
    const low = document.getElementById('value_low');
    const high = document.getElementById('value_high');

    if (type === 'text') {
        low.type = 'text';
        high.type = 'text';
        low.placeholder = 'e.g. Negative';
        high.placeholder = 'Optional';
    } else {
        low.type = 'number';
        high.type = 'number';
        low.placeholder = 'e.g. 70';
        high.placeholder = 'e.g. 110';
    }
}
</script>

<div class="container mt-4">
    <h3 class="mb-2">🧪 Define Reference Ranges for Test: 
        <span class="text-primary"><?= htmlspecialchars($test['name']) ?></span>
    </h3>
    <p class="mb-4 text-muted">
        <strong>Category:</strong> <?= htmlspecialchars($test['category_name'] ?? '-') ?> | 
        <strong>Department:</strong> <?= htmlspecialchars($test['department_name'] ?? '-') ?>
    </p>

    <form method="POST" class="border p-4 bg-light mb-4">
        <h5 class="mb-3">➕ Add New Reference Range</h5>
        <div class="row">
            <div class="col-md-4">
                <label><b>Range Type</b></label>
                <select name="range_type" class="form-control" onchange="handleRangeTypeChange(this.value)" required>
                    <option value="simple">Simple (basic low-high)</option>
                    <option value="gender">Gender-based</option>
                    <option value="age">Age-based</option>
                    <option value="pregnancy">Pregnancy-based</option>
                    <option value="label">Risk Label Category</option>
                    <option value="text">Text-only</option>
                    <option value="component">Sub-test (e.g., DC)</option>
                </select>
                <small class="text-muted">Choose how this range will be interpreted.</small>
            </div>
            <div class="col-md-4">
                <label><b>Condition Label</b></label>
                <input type="text" name="condition_label" class="form-control" placeholder="e.g., Female, 4-6 weeks" required>
            </div>
            <div class="col-md-4">
                <label><b>Gender</b></label>
                <select name="gender" class="form-control">
                    <option value="Any">Any</option>
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                </select>
            </div>
        </div>

        <div class="row mt-3">
            <div class="col-md-2">
                <label><b>Age Min</b></label>
                <input type="number" name="age_min" class="form-control" placeholder="e.g. 0">
            </div>
            <div class="col-md-2">
                <label><b>Age Max</b></label>
                <input type="number" name="age_max" class="form-control" placeholder="e.g. 50">
            </div>
            <div class="col-md-2">
                <label><b>Gest. Weeks Min</b></label>
                <input type="number" name="gestation_min" class="form-control" placeholder="e.g. 4">
            </div>
            <div class="col-md-2">
                <label><b>Gest. Weeks Max</b></label>
                <input type="number" name="gestation_max" class="form-control" placeholder="e.g. 6">
            </div>
            <div class="col-md-2">
                <label><b>Value Low</b></label>
                <input id="value_low" name="value_low" type="number" step="any" class="form-control" placeholder="e.g. 70">
            </div>
            <div class="col-md-2">
                <label><b>Value High</b></label>
                <input id="value_high" name="value_high" type="number" step="any" class="form-control" placeholder="e.g. 110">
            </div>
        </div>

        <div class="row mt-3">
            <div class="col-md-3">
                <label><b>Unit</b></label>
                <input type="text" name="unit" class="form-control" placeholder="e.g. mg/dL, IU/L">
            </div>
            <div class="col-md-3">
                <label><b>Flag Label</b></label>
                <input type="text" name="flag_label" class="form-control" placeholder="e.g. Desirable, High Risk">
            </div>
            <div class="col-md-6">
                <label><b>Notes</b></label>
                <textarea name="notes" class="form-control" placeholder="Any additional info or conditions" rows="2"></textarea>
            </div>
        </div>

        <button name="add_range" class="btn btn-primary mt-3">💾 Save Range</button>
        <a href="view_tests.php" class="btn btn-secondary mt-3">🔙 Back to Test List</a>
    </form>

    <h5>📋 Existing Ranges for <span class="text-info"><?= htmlspecialchars($test['name']) ?></span></h5>
    <table class="table table-bordered table-sm table-hover">
        <thead class="thead-light">
            <tr>
                <th>Label</th>
                <th>Type</th>
                <th>Gender</th>
                <th>Age</th>
                <th>Gestation</th>
                <th>Range</th>
                <th>Unit</th>
                <th>Flag</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php while($row = $ranges->fetch_assoc()): ?>
            <tr>
                <td><?= htmlspecialchars($row['condition_label']) ?></td>
                <td><?= htmlspecialchars($row['range_type']) ?></td>
                <td><?= $row['gender'] ?></td>
                <td><?= $row['age_min'] ?? '-' ?> - <?= $row['age_max'] ?? '-' ?></td>
                <td><?= $row['gestation_min'] ?? '-' ?> - <?= $row['gestation_max'] ?? '-' ?></td>
                <td><?= $row['value_low'] ?? '-' ?> - <?= $row['value_high'] ?? '-' ?></td>
                <td><?= htmlspecialchars($row['unit']) ?></td>
                <td><?= htmlspecialchars($row['flag_label']) ?></td>
                <td>
                    <a href="?test_id=<?= $test_id ?>&delete_id=<?= $row['id'] ?>" 
                       onclick="return confirm('Are you sure you want to delete this range?')" 
                       class="btn btn-danger btn-sm">🗑 Delete</a>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>

<?php include 'admin_footer.php'; ?>

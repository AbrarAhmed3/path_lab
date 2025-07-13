<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit();
}
include 'db.php';

// ─── Handle Create / Update ───
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category_name  = trim($_POST['category_name']);
    $category_id    = $_POST['category_id'] ?? null;
    $selected_tests = $_POST['test_ids'] ?? [];  // exact drag‐drop order

    if ($category_id) {
        // Update category name
        $upd = $conn->prepare("
            UPDATE test_categories
               SET category_name = ?
             WHERE category_id   = ?
        ");
        $upd->bind_param('si', $category_name, $category_id);
        $upd->execute();

        // Clear old links
        $conn->query("DELETE FROM category_tests WHERE category_id = $category_id");
    } else {
        // Create new category
        $ins = $conn->prepare("
            INSERT INTO test_categories (category_name)
            VALUES (?)
        ");
        $ins->bind_param('s', $category_name);
        $ins->execute();
        $category_id = $ins->insert_id;
    }

    // Re‐insert links in exactly the received order, setting sort_order
    if (!empty($selected_tests)) {
        $link = $conn->prepare("
            INSERT INTO category_tests (category_id, test_id, sort_order)
            VALUES (?, ?, ?)
        ");
        $order = 1;
        foreach ($selected_tests as $tid) {
            // bind the variable $order (not an expression)
            $link->bind_param('iii', $category_id, $tid, $order);
            $link->execute();
            $order++;
        }
    }

    header('Location: test_category_manager.php');
    exit;
}

// ─── Handle Delete ───
if (isset($_GET['delete_id'])) {
    $did = (int)$_GET['delete_id'];
    $conn->query("DELETE FROM test_categories WHERE category_id = $did");
    $conn->query("DELETE FROM category_tests  WHERE category_id = $did");
    header('Location: test_category_manager.php');
    exit;
}

// ─── Prepare for Edit (or New) ───
$edit             = null;
$assigned_tests   = [];
$unassigned_tests = [];

if (isset($_GET['edit_id'])) {
    $eid  = (int)$_GET['edit_id'];
    $edit = $conn
      ->query("SELECT * FROM test_categories WHERE category_id = $eid")
      ->fetch_assoc();

    // 1) Assigned tests in sort_order
    $ast = $conn->prepare("
        SELECT t.test_id, t.name
          FROM category_tests ct
          JOIN tests t ON ct.test_id = t.test_id
         WHERE ct.category_id = ?
           AND t.deleted_at IS NULL
         ORDER BY ct.sort_order ASC
    ");
    $ast->bind_param('i', $eid);
    $ast->execute();
    $assigned_tests = $ast->get_result()->fetch_all(MYSQLI_ASSOC);
    $ast->close();

    // 2) Unassigned tests
    $ust = $conn->prepare("
        SELECT t.test_id, t.name
          FROM tests t
     LEFT JOIN category_tests ct
            ON ct.test_id     = t.test_id
           AND ct.category_id = ?
         WHERE ct.id IS NULL
           AND t.deleted_at IS NULL
         ORDER BY t.name ASC
    ");
    $ust->bind_param('i', $eid);
    $ust->execute();
    $unassigned_tests = $ust->get_result()->fetch_all(MYSQLI_ASSOC);
    $ust->close();

} else {
    // New category
    $all = $conn->query("
        SELECT test_id, name
          FROM tests
         WHERE deleted_at IS NULL
         ORDER BY name ASC
    ");
    $unassigned_tests = $all->fetch_all(MYSQLI_ASSOC);
}

// ─── Load all categories for listing ───
$cats = $conn->query("
    SELECT *
      FROM test_categories
  ORDER BY category_name ASC
");

include 'admin_header.php';
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Manage Test Categories</title>
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
  <style>
    .list-container {
      height: 300px;
      overflow-y: auto;
      border: 1px solid #ced4da;
      border-radius: 4px;
      padding: 0;
    }
    .list-container li { cursor: pointer; }
    #assigned-tests li { cursor: move; }
    table.dataTable td {
      white-space: normal !important;
      word-break: break-word !important;
    }
  </style>
</head>
<body>
<div class="container mt-5">
  <h3>📁 Test Category Manager</h3>

  <!-- Add / Edit Form -->
  <form id="categoryForm" method="POST" class="mb-5">
    <input type="hidden" name="category_id" value="<?= htmlspecialchars($edit['category_id'] ?? '') ?>">

    <div class="form-group">
      <label>Category Name</label>
      <input type="text"
             name="category_name"
             class="form-control"
             required
             value="<?= htmlspecialchars($edit['category_name'] ?? '') ?>">
    </div>

    <div class="row">
      <div class="col-md-6">
        <label>Available Tests <small>(click to assign)</small></label>
        <ul id="available-tests" class="list-group list-container">
          <?php foreach ($unassigned_tests as $t): ?>
            <li class="list-group-item" data-test-id="<?= $t['test_id'] ?>">
              <?= htmlspecialchars($t['name']) ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="col-md-6">
        <label>Assigned Tests <small>(drag to reorder, click “×” to remove)</small></label>
        <ul id="assigned-tests" class="list-group list-container">
          <?php foreach ($assigned_tests as $t): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center"
                data-test-id="<?= $t['test_id'] ?>">
              <?= htmlspecialchars($t['name']) ?>
              <button type="button" class="btn btn-sm btn-outline-danger remove-assigned">×</button>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

    <button type="submit" class="btn btn-primary mt-3">
      <?= $edit ? '✏️ Update' : '➕ Add' ?> Category
    </button>
    <?php if ($edit): ?>
      <a href="test_category_manager.php" class="btn btn-secondary mt-3">Cancel</a>
    <?php endif; ?>
  </form>

  <!-- Categories Table -->
  <table id="categoryTable" class="table table-striped table-bordered">
    <thead>
      <tr>
        <th>#</th>
        <th>Category Name</th>
        <th>Tests Assigned</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php $i = 1; while ($row = $cats->fetch_assoc()): ?>
        <tr>
          <td><?= $i++ ?></td>
          <td><?= htmlspecialchars($row['category_name']) ?></td>
          <td>
            <?php
              $stmt = $conn->prepare("
                SELECT t.name
                  FROM category_tests ct
                  JOIN tests t ON ct.test_id = t.test_id
                 WHERE ct.category_id = ?
                   AND t.deleted_at   IS NULL
                 ORDER BY ct.sort_order ASC
              ");
              $stmt->bind_param('i', $row['category_id']);
              $stmt->execute();
              $names = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'name');
              echo $names ? implode(', ', $names) : '<span class="text-muted">None</span>';
              $stmt->close();
            ?>
          </td>
          <td>
            <a href="?edit_id=<?= $row['category_id'] ?>" class="btn btn-sm btn-warning">Edit</a>
            <button class="btn btn-sm btn-danger"
                    onclick="confirmDelete(<?= $row['category_id'] ?>)">
              Delete
            </button>
          </td>
        </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>

<!-- ─── Scripts ─── -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(function(){
  // Make assigned list sortable
  $("#assigned-tests").sortable({ placeholder: "ui-state-highlight" }).disableSelection();

  // Assign on click
  $("#available-tests").on("click", "li", function(){
    var $li = $(this);
    $("#assigned-tests").append(
      $("<li>")
        .addClass("list-group-item d-flex justify-content-between align-items-center")
        .attr("data-test-id", $li.data("test-id"))
        .text($li.text())
        .append('<button type="button" class="btn btn-sm btn-outline-danger remove-assigned">×</button>')
    );
    $li.remove();
  });

  // Remove assignment
  $("#assigned-tests").on("click", ".remove-assigned", function(){
    var $li = $(this).closest("li"),
        txt = $li.text().slice(0, -1);
    $("#available-tests").append(
      $("<li>")
        .addClass("list-group-item")
        .attr("data-test-id", $li.data("test-id"))
        .text(txt)
    );
    $li.remove();
  });

  // On submit, serialize assigned order
  $("#categoryForm").on("submit", function(){
    $('input[name="test_ids[]"]').remove();
    $("#assigned-tests li").each(function(){
      $("<input>")
        .attr({ type:"hidden", name:"test_ids[]" })
        .val($(this).data("test-id"))
        .appendTo("#categoryForm");
    });
  });

  // DataTable
  $('#categoryTable').DataTable({ pageLength: 10 });
});

function confirmDelete(id) {
  Swal.fire({
    title: 'Are you sure?',
    text: "This will delete the category and its test links!",
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Yes, delete'
  }).then(res => {
    if (res.isConfirmed) {
      window.location.href = '?delete_id=' + id;
    }
  });
}
</script>
</body>
</html>
<?php include 'admin_footer.php'; ?>

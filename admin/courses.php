<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('dean', 'secretary');

$pageTitle = 'Manage Courses';
$action = $_GET['action'] ?? 'list';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    $code  = trim($_POST['course_code'] ?? '');
    $name  = trim($_POST['course_name'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $dept  = trim($_POST['department'] ?? '');
    $units = (int)($_POST['units'] ?? 3);

    if (empty($code) || empty($name)) {
        $error = 'Course code and name are required.';
    } else {
        $chk = $pdo->prepare("SELECT id FROM courses WHERE course_code=?");
        $chk->execute([$code]);
        if ($chk->fetch()) {
            $error = 'Course code already exists.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO courses (course_code, course_name, description, department, units, created_by) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$code, $name, $desc, $dept, $units, $_SESSION['user_id']]);
            flashMessage('success', 'Course created successfully.');
            redirect(BASE_URL . '/admin/courses.php');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit') {
    $id    = (int)$_POST['id'];
    $name  = trim($_POST['course_name'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $dept  = trim($_POST['department'] ?? '');
    $units = (int)($_POST['units'] ?? 3);

    $stmt = $pdo->prepare("UPDATE courses SET course_name=?, description=?, department=?, units=? WHERE id=?");
    $stmt->execute([$name, $desc, $dept, $units, $id]);
    flashMessage('success', 'Course updated.');
    redirect(BASE_URL . '/admin/courses.php');
}

if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $pdo->prepare("UPDATE courses SET is_active = NOT is_active WHERE id=?")->execute([(int)$_GET['toggle']]);
    flashMessage('success', 'Course status updated.');
    redirect(BASE_URL . '/admin/courses.php');
}

$editCourse = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM courses WHERE id=?");
    $stmt->execute([(int)$_GET['id']]);
    $editCourse = $stmt->fetch();
}

$courses = $pdo->query("SELECT c.*, u.first_name, u.last_name, (SELECT COUNT(*) FROM sections s WHERE s.course_id=c.id AND s.is_active=1) as section_count FROM courses c LEFT JOIN users u ON c.created_by=u.id ORDER BY c.course_code")->fetchAll();

include '../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-book"></i> Manage Courses</h1>
    <a href="?action=create" class="btn btn-primary"><i class="fas fa-plus"></i> Add Course</a>
</div>

<?php if ($action === 'create' || $action === 'edit'): ?>
<div class="card mb-4">
    <div class="card-header">
        <h3><?= $action === 'edit' ? 'Edit Course' : 'Create New Course' ?></h3>
        <a href="<?= BASE_URL ?>/admin/courses.php" class="btn btn-sm btn-secondary">Back</a>
    </div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger"><?= sanitize($error) ?></div><?php endif; ?>
        <form method="POST">
            <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= $editCourse['id'] ?>"><?php endif; ?>
            <div class="form-row">
                <div class="form-group col-3">
                    <label>Course Code *</label>
                    <input type="text" name="course_code" class="form-control"
                        value="<?= sanitize($editCourse['course_code'] ?? $_POST['course_code'] ?? '') ?>"
                        <?= $action === 'edit' ? 'readonly' : 'required' ?>>
                </div>
                <div class="form-group col-6">
                    <label>Course Name *</label>
                    <input type="text" name="course_name" class="form-control"
                        value="<?= sanitize($editCourse['course_name'] ?? $_POST['course_name'] ?? '') ?>" required>
                </div>
                <div class="form-group col-3">
                    <label>Units</label>
                    <input type="number" name="units" class="form-control" min="1" max="9"
                        value="<?= (int)($editCourse['units'] ?? $_POST['units'] ?? 3) ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group col-6">
                    <label>Department</label>
                    <input type="text" name="department" class="form-control"
                        value="<?= sanitize($editCourse['department'] ?? $_POST['department'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" class="form-control" rows="3"><?= sanitize($editCourse['description'] ?? $_POST['description'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <table class="table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Course Name</th>
                    <th>Department</th>
                    <th>Units</th>
                    <th>Sections</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($courses as $c): ?>
                <tr>
                    <td><strong><?= sanitize($c['course_code']) ?></strong></td>
                    <td><?= sanitize($c['course_name']) ?></td>
                    <td><?= sanitize($c['department'] ?? '-') ?></td>
                    <td><?= $c['units'] ?></td>
                    <td><span class="badge badge-info"><?= $c['section_count'] ?></span></td>
                    <td><span class="badge <?= $c['is_active'] ? 'badge-success' : 'badge-danger' ?>"><?= $c['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td>
                        <a href="?action=edit&id=<?= $c['id'] ?>" class="btn btn-sm btn-info"><i class="fas fa-edit"></i></a>
                        <a href="?toggle=<?= $c['id'] ?>" class="btn btn-sm <?= $c['is_active'] ? 'btn-warning' : 'btn-success' ?>"
                           onclick="return confirm('Toggle course status?')">
                            <i class="fas fa-<?= $c['is_active'] ? 'ban' : 'check' ?>"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($courses)): ?>
                <tr><td colspan="7" class="text-center text-muted">No courses yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

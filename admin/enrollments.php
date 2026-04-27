<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('dean', 'secretary');

$pageTitle = 'Student Enrollments';

// Enroll student
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'enroll') {
    $sectionId = (int)$_POST['section_id'];
    $studentId = (int)$_POST['student_id'];

    // Check max students
    $section = $pdo->prepare("SELECT s.max_students, COUNT(e.id) as enrolled FROM sections s LEFT JOIN enrollments e ON e.section_id=s.id AND e.status='enrolled' WHERE s.id=? GROUP BY s.id");
    $section->execute([$sectionId]);
    $sec = $section->fetch();

    if ($sec && $sec['enrolled'] >= $sec['max_students']) {
        flashMessage('danger', 'Section is already full.');
    } else {
        $chk = $pdo->prepare("SELECT id FROM enrollments WHERE section_id=? AND student_id=?");
        $chk->execute([$sectionId, $studentId]);
        if ($chk->fetch()) {
            flashMessage('warning', 'Student already enrolled in this section.');
        } else {
            $pdo->prepare("INSERT INTO enrollments (section_id, student_id) VALUES (?,?)")->execute([$sectionId, $studentId]);
            flashMessage('success', 'Student enrolled successfully.');
        }
    }
    redirect(BASE_URL . '/admin/enrollments.php');
}

// Drop enrollment
if (isset($_GET['drop']) && is_numeric($_GET['drop'])) {
    $pdo->prepare("UPDATE enrollments SET status='dropped' WHERE id=?")->execute([(int)$_GET['drop']]);
    flashMessage('success', 'Student dropped from section.');
    redirect(BASE_URL . '/admin/enrollments.php');
}

$sections  = $pdo->query("SELECT s.*, c.course_code, c.course_name FROM sections s JOIN courses c ON s.course_id=c.id WHERE s.is_active=1 ORDER BY c.course_code, s.section_name")->fetchAll();
$students  = $pdo->query("SELECT * FROM users WHERE role='student' AND is_active=1 ORDER BY last_name, first_name")->fetchAll();

$filterSection = (int)($_GET['section_id'] ?? 0);
if ($filterSection) {
    $enrollments = $pdo->prepare("SELECT e.*, u.first_name, u.last_name, u.username FROM enrollments e JOIN users u ON e.student_id=u.id WHERE e.section_id=? ORDER BY u.last_name");
    $enrollments->execute([$filterSection]);
    $enrollments = $enrollments->fetchAll();
} else {
    $enrollments = $pdo->query("SELECT e.*, u.first_name, u.last_name, u.username, s.section_name, c.course_code FROM enrollments e JOIN users u ON e.student_id=u.id JOIN sections s ON e.section_id=s.id JOIN courses c ON s.course_id=c.id ORDER BY c.course_code, s.section_name, u.last_name")->fetchAll();
}

include '../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-user-graduate"></i> Student Enrollments</h1>
</div>

<div class="card mb-4">
    <div class="card-header"><h3><i class="fas fa-plus-circle"></i> Enroll Student</h3></div>
    <div class="card-body">
        <form method="POST" class="form-row align-items-end">
            <input type="hidden" name="action" value="enroll">
            <div class="form-group col-5">
                <label>Section</label>
                <select name="section_id" class="form-control" required>
                    <option value="">Select Section</option>
                    <?php foreach ($sections as $s): ?>
                    <option value="<?= $s['id'] ?>"><?= sanitize($s['course_code'] . ' - ' . $s['section_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group col-5">
                <label>Student</label>
                <select name="student_id" class="form-control" required>
                    <option value="">Select Student</option>
                    <?php foreach ($students as $st): ?>
                    <option value="<?= $st['id'] ?>"><?= sanitize($st['last_name'] . ', ' . $st['first_name'] . ' (' . $st['username'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group col-2">
                <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-user-plus"></i> Enroll</button>
            </div>
        </form>
    </div>
</div>

<!-- Filter by section -->
<div class="card mb-3">
    <div class="card-body filter-bar">
        <form method="GET" class="filter-form">
            <select name="section_id" class="form-control">
                <option value="">All Sections</option>
                <?php foreach ($sections as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $filterSection == $s['id'] ? 'selected' : '' ?>>
                    <?= sanitize($s['course_code'] . ' - ' . $s['section_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
            <a href="?" class="btn btn-secondary">All</a>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table">
            <thead>
                <tr>
                    <?php if (!$filterSection): ?><th>Section</th><?php endif; ?>
                    <th>Student Name</th>
                    <th>Username</th>
                    <th>Status</th>
                    <th>Enrolled Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($enrollments as $e): ?>
                <tr>
                    <?php if (!$filterSection): ?><td><?= sanitize(($e['course_code'] ?? '') . ' - ' . ($e['section_name'] ?? '')) ?></td><?php endif; ?>
                    <td><?= sanitize($e['last_name'] . ', ' . $e['first_name']) ?></td>
                    <td><?= sanitize($e['username']) ?></td>
                    <td><span class="badge <?= $e['status'] === 'enrolled' ? 'badge-success' : 'badge-danger' ?>"><?= ucfirst($e['status']) ?></span></td>
                    <td><?= date('M d, Y', strtotime($e['enrolled_at'])) ?></td>
                    <td>
                        <?php if ($e['status'] === 'enrolled'): ?>
                        <a href="?drop=<?= $e['id'] ?>" class="btn btn-sm btn-danger"
                           onclick="return confirm('Drop this student?')">
                            <i class="fas fa-user-minus"></i> Drop
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($enrollments)): ?>
                <tr><td colspan="6" class="text-center text-muted">No enrollments found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

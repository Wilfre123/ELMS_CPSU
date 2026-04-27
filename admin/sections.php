<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('dean', 'secretary');

$pageTitle = 'Class Sections';
$action = $_GET['action'] ?? 'list';
$error = '';

// Get courses and faculty for dropdowns
$coursesList  = $pdo->query("SELECT id, course_code, course_name FROM courses WHERE is_active=1 ORDER BY course_code")->fetchAll();
$facultyList  = $pdo->query("SELECT id, first_name, last_name, department FROM users WHERE role='faculty' AND is_active=1 ORDER BY last_name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['create', 'edit'])) {
    $courseId    = (int)$_POST['course_id'];
    $sectionName = trim($_POST['section_name'] ?? '');
    $facultyId   = (int)$_POST['faculty_id'];
    $semester    = trim($_POST['semester'] ?? '');
    $schoolYear  = trim($_POST['school_year'] ?? '');
    $schedule    = '';
    $days  = trim($_POST['schedule_days'] ?? '');
    $start = trim($_POST['schedule_start'] ?? '');
    $end   = trim($_POST['schedule_end'] ?? '');
    if ($days && $start && $end) $schedule = $days . ' ' . $start . ' - ' . $end;
    elseif ($days && $start)     $schedule = $days . ' ' . $start;
    elseif ($days)               $schedule = $days;
    $maxStudents = (int)($_POST['max_students'] ?? 40);

    if (!$courseId || empty($sectionName) || !$facultyId) {
        $error = 'Course, section name, and faculty are required.';
    } else {
        if ($action === 'create') {
            $stmt = $pdo->prepare("INSERT INTO sections (course_id, section_name, faculty_id, semester, school_year, schedule, max_students) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$courseId, $sectionName, $facultyId, $semester, $schoolYear, $schedule, $maxStudents]);
            flashMessage('success', 'Section created successfully.');
        } else {
            $id = (int)$_POST['id'];
            $stmt = $pdo->prepare("UPDATE sections SET course_id=?, section_name=?, faculty_id=?, semester=?, school_year=?, schedule=?, max_students=? WHERE id=?");
            $stmt->execute([$courseId, $sectionName, $facultyId, $semester, $schoolYear, $schedule, $maxStudents, $id]);
            flashMessage('success', 'Section updated.');
        }
        redirect(BASE_URL . '/admin/sections.php');
    }
}

if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $pdo->prepare("UPDATE sections SET is_active = NOT is_active WHERE id=?")->execute([(int)$_GET['toggle']]);
    flashMessage('success', 'Section status updated.');
    redirect(BASE_URL . '/admin/sections.php');
}

$editSection = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM sections WHERE id=?");
    $stmt->execute([(int)$_GET['id']]);
    $editSection = $stmt->fetch();
}

$sections = $pdo->query("SELECT s.*, c.course_code, c.course_name, u.first_name, u.last_name,
    (SELECT COUNT(*) FROM enrollments e WHERE e.section_id=s.id AND e.status='enrolled') as enrolled_count
    FROM sections s
    JOIN courses c ON s.course_id=c.id
    JOIN users u ON s.faculty_id=u.id
    ORDER BY c.course_code, s.section_name")->fetchAll();

include '../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-chalkboard-teacher"></i> Class Sections</h1>
    <a href="?action=create" class="btn btn-primary"><i class="fas fa-plus"></i> Add Section</a>
</div>

<?php if (in_array($action, ['create', 'edit'])): ?>
<div class="card mb-4">
    <div class="card-header">
        <h3><?= $action === 'edit' ? 'Edit Section' : 'Create New Section' ?></h3>
        <a href="<?= BASE_URL ?>/admin/sections.php" class="btn btn-sm btn-secondary">Back</a>
    </div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger"><?= sanitize($error) ?></div><?php endif; ?>
        <form method="POST">
            <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= $editSection['id'] ?>"><?php endif; ?>
            <div class="form-row">
                <div class="form-group col-5">
                    <label>Course *</label>
                    <select name="course_id" class="form-control" required>
                        <option value="">Select Course</option>
                        <?php foreach ($coursesList as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= ($editSection['course_id'] ?? $_POST['course_id'] ?? '') == $c['id'] ? 'selected' : '' ?>>
                            <?= sanitize($c['course_code'] . ' - ' . $c['course_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-3">
                    <label>Section Name *</label>
                    <input type="text" name="section_name" class="form-control" placeholder="e.g. BSCS-3A"
                        value="<?= sanitize($editSection['section_name'] ?? $_POST['section_name'] ?? '') ?>" required>
                </div>
                <div class="form-group col-4">
                    <label>Faculty *</label>
                    <select name="faculty_id" class="form-control" required>
                        <option value="">Select Faculty</option>
                        <?php foreach ($facultyList as $f): ?>
                        <option value="<?= $f['id'] ?>" <?= ($editSection['faculty_id'] ?? $_POST['faculty_id'] ?? '') == $f['id'] ? 'selected' : '' ?>>
                            <?= sanitize($f['last_name'] . ', ' . $f['first_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group col-3">
                    <label>Semester</label>
                    <select name="semester" class="form-control">
                        <option value="">Select</option>
                        <option value="1st Semester" <?= ($editSection['semester'] ?? '') === '1st Semester' ? 'selected' : '' ?>>1st Semester</option>
                        <option value="2nd Semester" <?= ($editSection['semester'] ?? '') === '2nd Semester' ? 'selected' : '' ?>>2nd Semester</option>
                        <option value="Summer" <?= ($editSection['semester'] ?? '') === 'Summer' ? 'selected' : '' ?>>Summer</option>
                    </select>
                </div>
                <div class="form-group col-3">
                    <label>School Year</label>
                    <input type="text" name="school_year" class="form-control" placeholder="e.g. 2025-2026"
                        value="<?= sanitize($editSection['school_year'] ?? '') ?>">
                </div>
                <div class="form-group col-3">
                    <label>Max Students</label>
                    <input type="number" name="max_students" class="form-control" min="1"
                        value="<?= (int)($editSection['max_students'] ?? 40) ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group col-4">
                    <label>Days</label>
                    <select name="schedule_days" class="form-control">
                        <option value="">Select Days</option>
                        <?php
                        $dayOptions = ['MWF'=>'MWF (Mon, Wed, Fri)', 'TTh'=>'TTh (Tue, Thu)', 'MW'=>'MW (Mon, Wed)', 'TThS'=>'TThS (Tue, Thu, Sat)', 'Sat'=>'Saturday', 'Sun'=>'Sunday', 'Daily'=>'Daily (Mon-Fri)'];
                        $savedDays = '';
                        $savedTime = '';
                        if (!empty($editSection['schedule'])) {
                            $parts = explode(' ', $editSection['schedule'], 2);
                            $savedDays = $parts[0] ?? '';
                            $savedTime = $parts[1] ?? '';
                        }
                        foreach ($dayOptions as $val => $label):
                        ?>
                        <option value="<?= $val ?>" <?= $savedDays === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-4">
                    <label>Start Time</label>
                    <select name="schedule_start" class="form-control">
                        <option value="">Select Time</option>
                        <?php
                        $times = ['7:00 AM','7:30 AM','8:00 AM','8:30 AM','9:00 AM','9:30 AM','10:00 AM','10:30 AM','11:00 AM','11:30 AM','12:00 PM','12:30 PM','1:00 PM','1:30 PM','2:00 PM','2:30 PM','3:00 PM','3:30 PM','4:00 PM','4:30 PM','5:00 PM','5:30 PM','6:00 PM','6:30 PM','7:00 PM','7:30 PM','8:00 PM'];
                        // Parse saved start time
                        $savedStart = ''; $savedEnd = '';
                        if ($savedTime && strpos($savedTime, '-') !== false) {
                            [$savedStart, $savedEnd] = explode('-', $savedTime, 2);
                            $savedStart = trim($savedStart); $savedEnd = trim($savedEnd);
                        }
                        foreach ($times as $t):
                        ?>
                        <option value="<?= $t ?>" <?= $savedStart === $t ? 'selected' : '' ?>><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-4">
                    <label>End Time</label>
                    <select name="schedule_end" class="form-control">
                        <option value="">Select Time</option>
                        <?php foreach ($times as $t): ?>
                        <option value="<?= $t ?>" <?= $savedEnd === $t ? 'selected' : '' ?>><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
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
                    <th>Course</th>
                    <th>Section</th>
                    <th>Faculty</th>
                    <th>Schedule</th>
                    <th>Semester</th>
                    <th>Enrolled</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sections as $s): ?>
                <tr>
                    <td><strong><?= sanitize($s['course_code']) ?></strong><br><small><?= sanitize($s['course_name']) ?></small></td>
                    <td><?= sanitize($s['section_name']) ?></td>
                    <td><?= sanitize($s['last_name'] . ', ' . $s['first_name']) ?></td>
                    <td><?= sanitize($s['schedule'] ?? '-') ?></td>
                    <td><?= sanitize($s['semester'] ?? '-') ?></td>
                    <td><?= $s['enrolled_count'] ?>/<?= $s['max_students'] ?></td>
                    <td><span class="badge <?= $s['is_active'] ? 'badge-success' : 'badge-danger' ?>"><?= $s['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td>
                        <a href="?action=edit&id=<?= $s['id'] ?>" class="btn btn-sm btn-info"><i class="fas fa-edit"></i></a>
                        <a href="?toggle=<?= $s['id'] ?>" class="btn btn-sm <?= $s['is_active'] ? 'btn-warning' : 'btn-success' ?>"
                           onclick="return confirm('Toggle section?')">
                            <i class="fas fa-<?= $s['is_active'] ? 'ban' : 'check' ?>"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($sections)): ?>
                <tr><td colspan="9" class="text-center text-muted">No sections yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty');

$sectionId = (int)($_GET['section_id'] ?? 0);
if (!$sectionId) redirect(BASE_URL . '/faculty/sections.php');

$secStmt = $pdo->prepare("SELECT s.*, c.course_code, c.course_name FROM sections s JOIN courses c ON s.course_id=c.id WHERE s.id=? AND s.faculty_id=?");
$secStmt->execute([$sectionId, $_SESSION['user_id']]);
$section = $secStmt->fetch();
if (!$section) redirect(BASE_URL . '/faculty/sections.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['create', 'edit'])) {
        $title        = trim($_POST['title'] ?? '');
        $description  = trim($_POST['description'] ?? '');
        $instructions = trim($_POST['instructions'] ?? '');
        $dueDateRaw   = trim($_POST['due_date'] ?? '');
        $dueDate      = $dueDateRaw ? date('Y-m-d H:i:s', strtotime($dueDateRaw)) : null;
        $allowLate    = isset($_POST['allow_late']) ? 1 : 0;
        $latePenalty  = min(100, max(0, (float)($_POST['late_penalty'] ?? 0)));
        $maxLateDays  = max(0, (int)($_POST['max_late_days'] ?? 0));
        $maxScore     = max(1, (float)($_POST['max_score'] ?? 100));
        $passingScore = min(100, max(0, (float)($_POST['passing_score'] ?? 60)));
        $maxSubs      = max(1, (int)($_POST['max_submissions'] ?? 1));
        $allowedTypes = preg_replace('/[^a-zA-Z0-9,]/', '', $_POST['allowed_file_types'] ?? 'pdf,doc,docx,txt,zip');
        $maxFileMB    = max(1, min(500, (int)($_POST['max_file_size_mb'] ?? 50)));

        if (!$title) {
            flashMessage('danger', 'Title is required.');
            redirect(BASE_URL . '/faculty/assignments.php?section_id=' . $sectionId);
        }

        if ($action === 'create') {
            $pdo->prepare("INSERT INTO assignments (section_id,title,description,instructions,due_date,allow_late,late_penalty,max_late_days,max_score,passing_score,max_submissions,allowed_file_types,max_file_size,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$sectionId, $title, $description, $instructions, $dueDate, $allowLate, $latePenalty, $maxLateDays, $maxScore, $passingScore, $maxSubs, $allowedTypes, $maxFileMB * 1024 * 1024, $_SESSION['user_id']]);
            flashMessage('success', "Assignment \"$title\" created.");
        } else {
            $aid = (int)($_POST['assignment_id'] ?? 0);
            $pdo->prepare("UPDATE assignments SET title=?,description=?,instructions=?,due_date=?,allow_late=?,late_penalty=?,max_late_days=?,max_score=?,passing_score=?,max_submissions=?,allowed_file_types=?,max_file_size=? WHERE id=? AND section_id=?")
                ->execute([$title, $description, $instructions, $dueDate, $allowLate, $latePenalty, $maxLateDays, $maxScore, $passingScore, $maxSubs, $allowedTypes, $maxFileMB * 1024 * 1024, $aid, $sectionId]);
            flashMessage('success', 'Assignment updated.');
        }
        redirect(BASE_URL . '/faculty/assignments.php?section_id=' . $sectionId);
    }

    if ($action === 'toggle_publish') {
        $aid = (int)($_POST['assignment_id'] ?? 0);
        $pdo->prepare("UPDATE assignments SET is_published = NOT is_published WHERE id=? AND section_id=?")->execute([$aid, $sectionId]);
        redirect(BASE_URL . '/faculty/assignments.php?section_id=' . $sectionId);
    }

    if ($action === 'delete') {
        $aid = (int)($_POST['assignment_id'] ?? 0);
        $filesQ = $pdo->prepare("SELECT sf.file_path FROM submission_files sf JOIN assignment_submissions sub ON sf.submission_id=sub.id WHERE sub.assignment_id=?");
        $filesQ->execute([$aid]);
        foreach ($filesQ->fetchAll() as $f) { if (file_exists($f['file_path'])) @unlink($f['file_path']); }
        $pdo->prepare("DELETE FROM assignments WHERE id=? AND section_id=?")->execute([$aid, $sectionId]);
        flashMessage('success', 'Assignment deleted.');
        redirect(BASE_URL . '/faculty/assignments.php?section_id=' . $sectionId);
    }
}

// Edit mode
$editAssign = null;
if (isset($_GET['edit'])) {
    $es = $pdo->prepare("SELECT * FROM assignments WHERE id=? AND section_id=?");
    $es->execute([(int)$_GET['edit'], $sectionId]);
    $editAssign = $es->fetch();
}

// Assignment list
$aList = $pdo->prepare("SELECT a.*,
    (SELECT COUNT(DISTINCT student_id) FROM assignment_submissions WHERE assignment_id=a.id) as sub_count,
    (SELECT COUNT(DISTINCT student_id) FROM assignment_submissions WHERE assignment_id=a.id AND status='graded') as graded_count,
    (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id=a.id AND plagiarism_flag=1) as plagiarism_count
    FROM assignments a WHERE section_id=? ORDER BY ISNULL(due_date), due_date, created_at");
$aList->execute([$sectionId]);
$aList = $aList->fetchAll();

$now = date('Y-m-d H:i:s');
$pageTitle = 'Assignments – ' . $section['course_code'];
include '../includes/header.php';
?>

<div class="page-header">
    <div>
        <a href="<?= BASE_URL ?>/faculty/section_view.php?id=<?= $sectionId ?>&tab=assignments" class="btn btn-sm btn-secondary mb-2">
            <i class="fas fa-arrow-left"></i> Back to Section
        </a>
        <h1><i class="fas fa-tasks"></i> Assignments</h1>
        <p class="text-muted"><?= sanitize($section['course_code']) ?> – <?= sanitize($section['course_name']) ?></p>
    </div>
    <button class="btn btn-primary" onclick="toggleForm('create-form')">
        <i class="fas fa-plus"></i> New Assignment
    </button>
</div>

<?= getFlash() ?>

<!-- Create Form -->
<div id="create-form" style="display:none" class="card mb-4">
    <div class="card-header"><h3><i class="fas fa-plus-circle"></i> Create New Assignment</h3></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <?php include __DIR__ . '/assignment_form_fields.php'; ?>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Create Assignment</button>
            <button type="button" class="btn btn-secondary" onclick="toggleForm('create-form')">Cancel</button>
        </form>
    </div>
</div>

<?php if ($editAssign): ?>
<!-- Edit Form -->
<div class="card mb-4" style="border-color:var(--primary)">
    <div class="card-header" style="background:var(--primary);color:white"><h3><i class="fas fa-edit"></i> Edit: <?= sanitize($editAssign['title']) ?></h3></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="assignment_id" value="<?= $editAssign['id'] ?>">
            <?php include __DIR__ . '/assignment_form_fields.php'; ?>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
            <a href="<?= BASE_URL ?>/faculty/assignments.php?section_id=<?= $sectionId ?>" class="btn btn-secondary">Cancel</a>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Assignments List -->
<?php if (empty($aList)): ?>
<div class="card"><div class="card-body text-center text-muted py-5">
    <i class="fas fa-tasks fa-3x mb-3"></i><p>No assignments yet. Click "New Assignment" to create one.</p>
</div></div>
<?php else: ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-list"></i> All Assignments (<?= count($aList) ?>)</h3></div>
    <div class="card-body p-0">
        <table class="table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Due Date</th>
                    <th>Max Score</th>
                    <th>Submissions</th>
                    <th>Graded</th>
                    <th>Plagiarism</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($aList as $a): ?>
                <?php
                    $isPast = $a['due_date'] && $now > $a['due_date'];
                    $dueCls = $isPast ? 'text-danger' : 'text-success';
                ?>
                <tr>
                    <td>
                        <strong><?= sanitize($a['title']) ?></strong>
                        <?php if ($a['description']): ?>
                        <br><small class="text-muted"><?= sanitize(mb_substr($a['description'], 0, 60)) ?>...</small>
                        <?php endif; ?>
                        <?php if ($a['allow_late']): ?>
                        <br><span class="badge" style="background:#fef3c7;color:#d97706;font-size:10px"><i class="fas fa-clock"></i> Late OK<?= $a['late_penalty'] > 0 ? " (-{$a['late_penalty']}%/day)" : '' ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($a['due_date']): ?>
                        <span class="<?= $dueCls ?>"><i class="fas fa-calendar"></i> <?= date('M j, Y', strtotime($a['due_date'])) ?></span>
                        <br><small class="text-muted"><?= date('g:i A', strtotime($a['due_date'])) ?></small>
                        <?php else: ?><span class="text-muted">No deadline</span><?php endif; ?>
                    </td>
                    <td><?= number_format($a['max_score'], 0) ?><br><small class="text-muted">Pass: <?= $a['passing_score'] ?>%</small></td>
                    <td>
                        <span class="stat-num" style="font-size:18px"><?= $a['sub_count'] ?></span>
                        <br><small class="text-muted"><?= $a['max_submissions'] > 1 ? "Up to {$a['max_submissions']} tries" : '1 attempt' ?></small>
                    </td>
                    <td>
                        <?php if ($a['graded_count'] > 0): ?>
                        <span class="result-correct"><i class="fas fa-check-circle"></i> <?= $a['graded_count'] ?></span>
                        <?php else: ?><span class="text-muted">0</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($a['plagiarism_count'] > 0): ?>
                        <span style="color:#dc2626;font-weight:700"><i class="fas fa-exclamation-triangle"></i> <?= $a['plagiarism_count'] ?> flagged</span>
                        <?php else: ?><span class="text-muted">–</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($a['is_published']): ?>
                        <span class="badge badge-success">Published</span>
                        <?php else: ?>
                        <span class="badge badge-warning">Draft</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;flex-direction:column;gap:4px;min-width:120px">
                            <a href="<?= BASE_URL ?>/faculty/assignment_view.php?assignment_id=<?= $a['id'] ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-users"></i> View Submissions
                            </a>
                            <a href="?section_id=<?= $sectionId ?>&edit=<?= $a['id'] ?>" class="btn btn-sm btn-info">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="toggle_publish">
                                <input type="hidden" name="assignment_id" value="<?= $a['id'] ?>">
                                <button type="submit" class="btn btn-sm <?= $a['is_published'] ? 'btn-warning' : 'btn-success' ?>" style="width:100%">
                                    <i class="fas fa-<?= $a['is_published'] ? 'eye-slash' : 'eye' ?>"></i> <?= $a['is_published'] ? 'Unpublish' : 'Publish' ?>
                                </button>
                            </form>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this assignment and all submissions?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="assignment_id" value="<?= $a['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger" style="width:100%"><i class="fas fa-trash"></i> Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
function toggleForm(id) {
    const el = document.getElementById(id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
</script>

<?php include '../includes/footer.php'; ?>

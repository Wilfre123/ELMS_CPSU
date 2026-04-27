<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty');

$sectionId = (int)($_GET['section_id'] ?? 0);
$sec = $pdo->prepare("SELECT s.*, c.course_code, c.course_name FROM sections s JOIN courses c ON s.course_id=c.id WHERE s.id=? AND s.faculty_id=?");
$sec->execute([$sectionId, $_SESSION['user_id']]);
$section = $sec->fetch();
if (!$section) redirect(BASE_URL . '/faculty/sections.php');

$pageTitle = 'Exams & Quizzes – ' . $section['course_code'];
$action = $_GET['action'] ?? 'list';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['post_action'] ?? '';

    if ($postAction === 'create_exam') {
        $title      = trim($_POST['title'] ?? '');
        $desc       = trim($_POST['description'] ?? '');
        $examType   = $_POST['exam_type'] ?? 'quiz';
        $timeLimit  = (int)($_POST['time_limit'] ?? 0) ?: null;
        $maxAttempts= max(1, (int)($_POST['max_attempts'] ?? 1));
        $passing    = (float)($_POST['passing_score'] ?? 60);
        $randQ      = isset($_POST['randomize_questions']) ? 1 : 0;
        $randO      = isset($_POST['randomize_options']) ? 1 : 0;
        $showRes    = isset($_POST['show_results']) ? 1 : 0;
        $startDt    = $_POST['start_datetime'] ? date('Y-m-d H:i:s', strtotime($_POST['start_datetime'])) : null;
        $endDt      = $_POST['end_datetime']   ? date('Y-m-d H:i:s', strtotime($_POST['end_datetime']))   : null;

        if (empty($title)) { $error = 'Title is required.'; }
        else {
            $pdo->prepare("INSERT INTO exams (section_id, title, description, exam_type, time_limit, max_attempts, passing_score, randomize_questions, randomize_options, show_results, start_datetime, end_datetime, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$sectionId, $title, $desc, $examType, $timeLimit, $maxAttempts, $passing, $randQ, $randO, $showRes, $startDt, $endDt, $_SESSION['user_id']]);
            $newId = $pdo->lastInsertId();
            flashMessage('success', 'Exam created. Now add questions.');
            redirect(BASE_URL . '/faculty/exam_builder.php?id=' . $newId);
        }
    }

    if ($postAction === 'update_exam') {
        $id         = (int)$_POST['exam_id'];
        $title      = trim($_POST['title'] ?? '');
        $desc       = trim($_POST['description'] ?? '');
        $examType   = $_POST['exam_type'] ?? 'quiz';
        $timeLimit  = (int)($_POST['time_limit'] ?? 0) ?: null;
        $maxAttempts= max(1, (int)($_POST['max_attempts'] ?? 1));
        $passing    = (float)($_POST['passing_score'] ?? 60);
        $randQ      = isset($_POST['randomize_questions']) ? 1 : 0;
        $randO      = isset($_POST['randomize_options']) ? 1 : 0;
        $showRes    = isset($_POST['show_results']) ? 1 : 0;
        $startDt    = $_POST['start_datetime'] ? date('Y-m-d H:i:s', strtotime($_POST['start_datetime'])) : null;
        $endDt      = $_POST['end_datetime']   ? date('Y-m-d H:i:s', strtotime($_POST['end_datetime']))   : null;
        $pdo->prepare("UPDATE exams SET title=?,description=?,exam_type=?,time_limit=?,max_attempts=?,passing_score=?,randomize_questions=?,randomize_options=?,show_results=?,start_datetime=?,end_datetime=? WHERE id=? AND section_id=?")
            ->execute([$title,$desc,$examType,$timeLimit,$maxAttempts,$passing,$randQ,$randO,$showRes,$startDt,$endDt,$id,$sectionId]);
        flashMessage('success', 'Exam updated.');
        redirect(BASE_URL . '/faculty/exams.php?section_id=' . $sectionId);
    }
}

if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $eid = (int)$_GET['toggle'];
    $qcount = $pdo->prepare("SELECT COUNT(*) FROM exam_questions WHERE exam_id=?");
    $qcount->execute([$eid]);
    if ($qcount->fetchColumn() == 0) {
        // Redirect to builder so faculty can add questions from the bank
        flashMessage('warning', 'You need to add questions to this exam first. Use the <strong>Build</strong> button to pick questions from your question bank, then publish.');
        redirect(BASE_URL . '/faculty/exam_builder.php?id=' . $eid);
    } else {
        $pdo->prepare("UPDATE exams SET is_published = NOT is_published WHERE id=? AND section_id=?")->execute([$eid, $sectionId]);
        flashMessage('success', 'Exam status updated.');
        redirect(BASE_URL . '/faculty/exams.php?section_id=' . $sectionId);
    }
}

if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $eid = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM exams WHERE id=? AND section_id=?")->execute([$eid, $sectionId]);
    flashMessage('success', 'Exam deleted.');
    redirect(BASE_URL . '/faculty/exams.php?section_id=' . $sectionId);
}

$editExam = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM exams WHERE id=? AND section_id=?");
    $stmt->execute([(int)$_GET['id'], $sectionId]);
    $editExam = $stmt->fetch();
}

$exams = $pdo->prepare("SELECT e.*,
    (SELECT COUNT(*) FROM exam_questions eq WHERE eq.exam_id=e.id) as question_count,
    (SELECT COUNT(*) FROM exam_attempts ea WHERE ea.exam_id=e.id AND ea.status != 'in_progress') as attempt_count
    FROM exams e WHERE e.section_id=? ORDER BY e.created_at DESC");
$exams->execute([$sectionId]);
$exams = $exams->fetchAll();

include '../includes/header.php';
?>

<div class="page-header">
    <div>
        <a href="<?= BASE_URL ?>/faculty/section_view.php?id=<?= $sectionId ?>&tab=exams" class="btn btn-sm btn-secondary mb-2">
            <i class="fas fa-arrow-left"></i> Back to Section
        </a>
        <h1><i class="fas fa-file-alt"></i> Exams & Quizzes</h1>
        <p class="text-muted"><?= sanitize($section['course_code']) ?> – <?= sanitize($section['course_name']) ?></p>
    </div>
    <div>
        <a href="<?= BASE_URL ?>/faculty/question_bank.php?section_id=<?= $sectionId ?>" class="btn btn-secondary">
            <i class="fas fa-database"></i> Question Bank
        </a>
        <a href="?section_id=<?= $sectionId ?>&action=create" class="btn btn-primary">
            <i class="fas fa-plus"></i> New Exam
        </a>
    </div>
</div>

<?php if (in_array($action, ['create','edit'])): ?>
<div class="card mb-4">
    <div class="card-header">
        <h3><?= $action === 'edit' ? '<i class="fas fa-edit"></i> Edit Exam' : '<i class="fas fa-plus-circle"></i> Create New Exam' ?></h3>
        <a href="?section_id=<?= $sectionId ?>" class="btn btn-sm btn-secondary">Cancel</a>
    </div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger"><?= sanitize($error) ?></div><?php endif; ?>
        <form method="POST">
            <input type="hidden" name="post_action" value="<?= $action === 'edit' ? 'update_exam' : 'create_exam' ?>">
            <?php if ($action === 'edit'): ?><input type="hidden" name="exam_id" value="<?= $editExam['id'] ?>"><?php endif; ?>
            <div class="form-row">
                <div class="form-group col-6">
                    <label>Title *</label>
                    <input type="text" name="title" class="form-control" value="<?= sanitize($editExam['title'] ?? '') ?>" required>
                </div>
                <div class="form-group col-3">
                    <label>Type</label>
                    <select name="exam_type" class="form-control">
                        <?php foreach(['quiz'=>'Quiz','exam'=>'Exam','midterm'=>'Midterm','final'=>'Final','activity'=>'Activity'] as $v=>$l): ?>
                        <option value="<?= $v ?>" <?= ($editExam['exam_type'] ?? 'quiz') === $v ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-3">
                    <label>Time Limit (minutes)</label>
                    <input type="number" name="time_limit" class="form-control" min="1" placeholder="No limit"
                        value="<?= $editExam['time_limit'] ?? '' ?>">
                </div>
            </div>
            <div class="form-group">
                <label>Description / Instructions</label>
                <textarea name="description" class="form-control" rows="2"><?= sanitize($editExam['description'] ?? '') ?></textarea>
            </div>
            <div class="form-row">
                <div class="form-group col-3">
                    <label>Max Attempts</label>
                    <input type="number" name="max_attempts" class="form-control" min="1" value="<?= $editExam['max_attempts'] ?? 1 ?>">
                </div>
                <div class="form-group col-3">
                    <label>Passing Score (%)</label>
                    <input type="number" name="passing_score" class="form-control" min="0" max="100" value="<?= $editExam['passing_score'] ?? 60 ?>">
                </div>
                <div class="form-group col-3">
                    <label>Available From</label>
                    <input type="datetime-local" name="start_datetime" class="form-control"
                        value="<?= $editExam['start_datetime'] ? date('Y-m-d\TH:i', strtotime($editExam['start_datetime'])) : '' ?>">
                </div>
                <div class="form-group col-3">
                    <label>Available Until</label>
                    <input type="datetime-local" name="end_datetime" class="form-control"
                        value="<?= $editExam['end_datetime'] ? date('Y-m-d\TH:i', strtotime($editExam['end_datetime'])) : '' ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group col-4">
                    <label class="checkbox-label">
                        <input type="checkbox" name="randomize_questions" <?= ($editExam['randomize_questions'] ?? 0) ? 'checked' : '' ?>>
                        Randomize Question Order
                    </label>
                </div>
                <div class="form-group col-4">
                    <label class="checkbox-label">
                        <input type="checkbox" name="randomize_options" <?= ($editExam['randomize_options'] ?? 0) ? 'checked' : '' ?>>
                        Randomize Answer Options
                    </label>
                </div>
                <div class="form-group col-4">
                    <label class="checkbox-label">
                        <input type="checkbox" name="show_results" <?= ($editExam['show_results'] ?? 1) ? 'checked' : '' ?>>
                        Show Results to Students
                    </label>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?= $action === 'edit' ? 'Update' : 'Create & Add Questions' ?></button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Exams Table -->
<div class="card">
    <div class="card-body p-0">
        <table class="table">
            <thead>
                <tr><th>Title</th><th>Type</th><th>Time</th><th>Questions</th><th>Attempts</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($exams as $e): ?>
                <tr>
                    <td>
                        <strong><?= sanitize($e['title']) ?></strong>
                        <?php if ($e['description']): ?><br><small class="text-muted"><?= sanitize(substr($e['description'],0,60)) ?>...</small><?php endif; ?>
                    </td>
                    <td><span class="badge badge-info"><?= ucfirst($e['exam_type']) ?></span></td>
                    <td><?= $e['time_limit'] ? $e['time_limit'].' min' : 'No limit' ?></td>
                    <td>
                        <span class="badge <?= $e['question_count'] > 0 ? 'badge-secondary' : 'badge-danger' ?>">
                            <?= $e['question_count'] ?> Q
                        </span>
                        <?php if ($e['question_count'] == 0): ?>
                        <br><small><a href="<?= BASE_URL ?>/faculty/exam_builder.php?id=<?= $e['id'] ?>"><i class="fas fa-exclamation-triangle"></i> Add questions first</a></small>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge-secondary"><?= $e['attempt_count'] ?></span></td>
                    <td>
                        <span class="badge <?= $e['is_published'] ? 'badge-success' : 'badge-warning' ?>">
                            <?= $e['is_published'] ? 'Published' : 'Draft' ?>
                        </span>
                    </td>
                    <td>
                        <a href="<?= BASE_URL ?>/faculty/exam_builder.php?id=<?= $e['id'] ?>" class="btn btn-sm btn-primary" title="Build">
                            <i class="fas fa-tools"></i>
                        </a>
                        <a href="?section_id=<?= $sectionId ?>&action=edit&id=<?= $e['id'] ?>" class="btn btn-sm btn-info" title="Edit Settings">
                            <i class="fas fa-edit"></i>
                        </a>
                        <a href="<?= BASE_URL ?>/faculty/exam_results.php?exam_id=<?= $e['id'] ?>" class="btn btn-sm btn-secondary" title="Results">
                            <i class="fas fa-chart-bar"></i>
                        </a>
                        <a href="?section_id=<?= $sectionId ?>&toggle=<?= $e['id'] ?>" class="btn btn-sm <?= $e['is_published'] ? 'btn-warning' : 'btn-success' ?>"
                           onclick="return confirm('<?= $e['is_published'] ? 'Unpublish' : 'Publish' ?> this exam?')" title="<?= $e['is_published'] ? 'Unpublish' : 'Publish' ?>">
                            <i class="fas fa-<?= $e['is_published'] ? 'eye-slash' : 'globe' ?>"></i>
                        </a>
                        <a href="?section_id=<?= $sectionId ?>&delete=<?= $e['id'] ?>" class="btn btn-sm btn-danger"
                           onclick="return confirm('Delete this exam and all attempts?')">
                            <i class="fas fa-trash"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($exams)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No exams yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

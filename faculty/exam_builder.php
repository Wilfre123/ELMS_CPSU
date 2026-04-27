<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty');

$examId = (int)($_GET['id'] ?? 0);
$examStmt = $pdo->prepare("SELECT e.*, s.faculty_id, s.id as section_id, c.course_code FROM exams e JOIN sections s ON e.section_id=s.id JOIN courses c ON s.course_id=c.id WHERE e.id=?");
$examStmt->execute([$examId]);
$exam = $examStmt->fetch();
if (!$exam || $exam['faculty_id'] != $_SESSION['user_id']) redirect(BASE_URL . '/faculty/sections.php');

$pageTitle = 'Build: ' . $exam['title'];
$sectionId = $exam['section_id'];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pa = $_POST['post_action'] ?? '';

    if ($pa === 'add_to_exam') {
        $qid = (int)$_POST['question_id'];
        $maxOrder = $pdo->prepare("SELECT COALESCE(MAX(order_index),0)+1 FROM exam_questions WHERE exam_id=?");
        $maxOrder->execute([$examId]);
        $ord = $maxOrder->fetchColumn();
        try {
            $pdo->prepare("INSERT INTO exam_questions (exam_id, question_id, order_index) VALUES (?,?,?)")->execute([$examId, $qid, $ord]);
        } catch (Exception $e) {} // ignore duplicate
        redirect(BASE_URL . '/faculty/exam_builder.php?id=' . $examId);
    }

    if ($pa === 'remove_from_exam') {
        $qid = (int)$_POST['question_id'];
        $pdo->prepare("DELETE FROM exam_questions WHERE exam_id=? AND question_id=?")->execute([$examId, $qid]);
        redirect(BASE_URL . '/faculty/exam_builder.php?id=' . $examId);
    }

    if ($pa === 'update_points') {
        $qid = (int)$_POST['question_id'];
        $pts = (float)$_POST['points_override'];
        $pdo->prepare("UPDATE exam_questions SET points_override=? WHERE exam_id=? AND question_id=?")->execute([$pts ?: null, $examId, $qid]);
        flashMessage('success', 'Points updated.');
        redirect(BASE_URL . '/faculty/exam_builder.php?id=' . $examId);
    }
}

// Get bank
$bankStmt = $pdo->prepare("SELECT id FROM question_banks WHERE section_id=? LIMIT 1");
$bankStmt->execute([$sectionId]);
$bank = $bankStmt->fetch();
$bankId = $bank['id'] ?? null;

// Questions in this exam
$examQuestions = $pdo->prepare("SELECT eq.*, q.question_text, q.question_type, q.points as default_points,
    COALESCE(eq.points_override, q.points) as effective_points
    FROM exam_questions eq JOIN questions q ON eq.question_id=q.id
    WHERE eq.exam_id=? ORDER BY eq.order_index");
$examQuestions->execute([$examId]);
$examQuestions = $examQuestions->fetchAll();

$examQIds = array_column($examQuestions, 'question_id');
$totalPoints = array_sum(array_column($examQuestions, 'effective_points'));

// Bank questions NOT in exam
$bankQuestions = [];
if ($bankId) {
    $bq = $pdo->prepare("SELECT q.*, (SELECT COUNT(*) FROM question_options WHERE question_id=q.id) as opt_count FROM questions q WHERE q.bank_id=? ORDER BY q.created_at DESC");
    $bq->execute([$bankId]);
    $bankQuestions = $bq->fetchAll();
}

include '../includes/header.php';
?>

<div class="page-header">
    <div>
        <a href="<?= BASE_URL ?>/faculty/exams.php?section_id=<?= $sectionId ?>" class="btn btn-sm btn-secondary mb-2">
            <i class="fas fa-arrow-left"></i> Back to Exams
        </a>
        <h1><i class="fas fa-tools"></i> <?= sanitize($exam['title']) ?></h1>
        <p class="text-muted">
            <?= sanitize($exam['course_code']) ?> &nbsp;|&nbsp;
            <span class="badge badge-info"><?= ucfirst($exam['exam_type']) ?></span>
            <?= $exam['time_limit'] ? '<span class="badge badge-warning">'.$exam['time_limit'].' min</span>' : '' ?>
            <?= $exam['randomize_questions'] ? '<span class="badge badge-secondary">Randomized</span>' : '' ?>
            &nbsp; Total: <strong><?= $totalPoints ?> pts</strong> across <strong><?= count($examQuestions) ?></strong> question(s)
        </p>
    </div>
    <a href="<?= BASE_URL ?>/faculty/question_bank.php?section_id=<?= $sectionId ?>" class="btn btn-secondary">
        <i class="fas fa-database"></i> Question Bank
    </a>
</div>

<div class="builder-grid">
    <!-- Left: Exam Questions -->
    <div>
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-list-ol"></i> Questions in Exam (<?= count($examQuestions) ?>)</h3></div>
            <div class="card-body p-0">
                <?php if (empty($examQuestions)): ?>
                <p class="text-center text-muted py-4">No questions added yet. Add from the bank on the right.</p>
                <?php else: ?>
                <?php foreach ($examQuestions as $i => $eq): ?>
                <div class="exam-q-item">
                    <div class="exam-q-num"><?= $i + 1 ?></div>
                    <div class="exam-q-body">
                        <span class="qtype-badge qtype-<?= $eq['question_type'] ?>"><?= str_replace('_',' ', ucfirst($eq['question_type'])) ?></span>
                        <p><?= sanitize($eq['question_text']) ?></p>
                        <form method="POST" class="inline-pts-form">
                            <input type="hidden" name="post_action" value="update_points">
                            <input type="hidden" name="question_id" value="<?= $eq['question_id'] ?>">
                            <label>Points: <input type="number" name="points_override" class="form-control-sm"
                                value="<?= $eq['effective_points'] ?>" min="0.5" step="0.5" style="width:70px"></label>
                            <button type="submit" class="btn btn-xs btn-info"><i class="fas fa-save"></i></button>
                        </form>
                    </div>
                    <form method="POST" style="align-self:flex-start">
                        <input type="hidden" name="post_action" value="remove_from_exam">
                        <input type="hidden" name="question_id" value="<?= $eq['question_id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger" title="Remove"><i class="fas fa-times"></i></button>
                    </form>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right: Question Bank -->
    <div>
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-database"></i> Question Bank (<?= count($bankQuestions) ?>)</h3>
                <a href="<?= BASE_URL ?>/faculty/question_bank.php?section_id=<?= $sectionId ?>" class="btn btn-sm btn-primary">
                    <i class="fas fa-plus"></i> Add Questions
                </a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($bankQuestions)): ?>
                <p class="text-center text-muted py-4">No questions in bank. <a href="<?= BASE_URL ?>/faculty/question_bank.php?section_id=<?= $sectionId ?>">Add some first.</a></p>
                <?php else: ?>
                <?php foreach ($bankQuestions as $bq): ?>
                <div class="bank-q-item <?= in_array($bq['id'], $examQIds) ? 'already-added' : '' ?>">
                    <div class="bank-q-body">
                        <span class="qtype-badge qtype-<?= $bq['question_type'] ?>"><?= str_replace('_',' ',ucfirst($bq['question_type'])) ?></span>
                        <span class="qpoints"><?= $bq['points'] ?> pt<?= $bq['points'] != 1 ? 's' : '' ?></span>
                        <p><?= sanitize(substr($bq['question_text'], 0, 100)) ?><?= strlen($bq['question_text']) > 100 ? '...' : '' ?></p>
                    </div>
                    <?php if (in_array($bq['id'], $examQIds)): ?>
                    <span class="badge badge-success"><i class="fas fa-check"></i> Added</span>
                    <?php else: ?>
                    <form method="POST" style="align-self:flex-start">
                        <input type="hidden" name="post_action" value="add_to_exam">
                        <input type="hidden" name="question_id" value="<?= $bq['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-success" title="Add to Exam"><i class="fas fa-plus"></i></button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('student');

$sectionId = (int)($_GET['section_id'] ?? 0);

// Verify enrollment
$enrStmt = $pdo->prepare("SELECT e.id FROM enrollments e JOIN sections s ON e.section_id=s.id WHERE e.section_id=? AND e.student_id=? AND e.status='enrolled'");
$enrStmt->execute([$sectionId, $_SESSION['user_id']]);
if (!$enrStmt->fetch()) redirect(BASE_URL . '/student/courses.php');

$secStmt = $pdo->prepare("SELECT s.*, c.course_code, c.course_name FROM sections s JOIN courses c ON s.course_id=c.id WHERE s.id=?");
$secStmt->execute([$sectionId]);
$section = $secStmt->fetch();

$pageTitle = 'Exams – ' . $section['course_code'];
$now = date('Y-m-d H:i:s');

$exams = $pdo->prepare("SELECT e.*,
    (SELECT COUNT(*) FROM exam_questions eq WHERE eq.exam_id=e.id) as question_count,
    (SELECT COUNT(*) FROM exam_attempts ea WHERE ea.exam_id=e.id AND ea.student_id=? AND ea.status != 'in_progress') as completed_attempts,
    (SELECT id FROM exam_attempts ea WHERE ea.exam_id=e.id AND ea.student_id=? AND ea.status != 'in_progress' ORDER BY ea.attempt_number DESC LIMIT 1) as last_attempt_id,
    (SELECT status FROM exam_attempts ea WHERE ea.exam_id=e.id AND ea.student_id=? AND ea.status != 'in_progress' ORDER BY ea.attempt_number DESC LIMIT 1) as last_status,
    (SELECT percentage FROM exam_attempts ea WHERE ea.exam_id=e.id AND ea.student_id=? AND ea.status != 'in_progress' ORDER BY ea.attempt_number DESC LIMIT 1) as last_pct
    FROM exams e
    WHERE e.section_id=? AND e.is_published=1
    ORDER BY e.exam_type, e.start_datetime");
$exams->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id'], $sectionId]);
$exams = $exams->fetchAll();

include '../includes/header.php';
?>

<div class="page-header">
    <div>
        <a href="<?= BASE_URL ?>/student/course_view.php?id=<?= $sectionId ?>&tab=exams" class="btn btn-sm btn-secondary mb-2">
            <i class="fas fa-arrow-left"></i> Back to Course
        </a>
        <h1><i class="fas fa-file-alt"></i> Exams & Quizzes</h1>
        <p class="text-muted"><?= sanitize($section['course_code']) ?> – <?= sanitize($section['course_name']) ?></p>
    </div>
</div>

<?php if (empty($exams)): ?>
<div class="empty-state">
    <i class="fas fa-clipboard-list"></i>
    <p>No exams available yet.</p>
</div>
<?php else: ?>
<div class="exam-list">
    <?php foreach ($exams as $e): ?>
    <?php
        $available = true;
        $statusMsg = '';
        if ($e['start_datetime'] && $now < $e['start_datetime']) { $available = false; $statusMsg = 'Opens ' . date('M j, g:i A', strtotime($e['start_datetime'])); }
        if ($e['end_datetime']   && $now > $e['end_datetime'])   { $available = false; $statusMsg = 'Closed ' . date('M j, g:i A', strtotime($e['end_datetime'])); }
        $attemptsLeft = $e['max_attempts'] - $e['completed_attempts'];
        $canTake = $available && $attemptsLeft > 0 && !in_array($e['last_status'], ['in_progress']);
    ?>
    <div class="exam-card">
        <div class="exam-card-header">
            <div>
                <span class="qtype-badge qtype-<?= $e['exam_type'] ?>" style="background:#dbeafe;color:#1d4ed8"><?= ucfirst($e['exam_type']) ?></span>
                <h3><?= sanitize($e['title']) ?></h3>
                <?php if ($e['description']): ?><p class="text-muted"><?= sanitize($e['description']) ?></p><?php endif; ?>
            </div>
            <div class="exam-card-meta">
                <span><i class="fas fa-question-circle"></i> <?= $e['question_count'] ?> questions</span>
                <?php if ($e['time_limit']): ?><span><i class="fas fa-clock"></i> <?= $e['time_limit'] ?> min</span><?php endif; ?>
                <span><i class="fas fa-redo"></i> <?= $e['max_attempts'] ?> attempt<?= $e['max_attempts'] != 1 ? 's' : '' ?></span>
                <span><i class="fas fa-medal"></i> Passing: <?= $e['passing_score'] ?>%</span>
            </div>
        </div>
        <div class="exam-card-footer">
            <div>
                <?php if ($e['start_datetime'] || $e['end_datetime']): ?>
                <small class="text-muted">
                    <?= $e['start_datetime'] ? 'From: '.date('M j, g:i A', strtotime($e['start_datetime'])) : '' ?>
                    <?= $e['end_datetime']   ? ' &nbsp;To: '.date('M j, g:i A', strtotime($e['end_datetime']))   : '' ?>
                </small>
                <?php endif; ?>
            </div>
            <div class="exam-actions">
                <?php if ($e['last_attempt_id']): ?>
                    <?php if ($e['last_status'] === 'graded' && $e['show_results']): ?>
                    <span class="<?= $e['last_pct'] >= $e['passing_score'] ? 'result-correct' : 'result-wrong' ?>">
                        <strong><?= number_format($e['last_pct'],1) ?>%</strong>
                    </span>
                    <a href="<?= BASE_URL ?>/student/exam_result.php?attempt_id=<?= $e['last_attempt_id'] ?>" class="btn btn-sm btn-secondary">
                        <i class="fas fa-eye"></i> View Result
                    </a>
                    <?php elseif ($e['last_status'] === 'submitted'): ?>
                    <span class="badge badge-warning">Awaiting Grading</span>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (!$available): ?>
                    <span class="badge badge-secondary"><?= $statusMsg ?></span>
                <?php elseif ($attemptsLeft <= 0): ?>
                    <span class="badge badge-secondary">No Attempts Left</span>
                <?php elseif ($canTake): ?>
                    <a href="<?= BASE_URL ?>/student/exam_take.php?exam_id=<?= $e['id'] ?>" class="btn btn-primary">
                        <i class="fas fa-pencil-alt"></i>
                        <?= $e['completed_attempts'] > 0 ? 'Retake' : 'Start' ?> Exam
                    </a>
                    <small class="text-muted"><?= $attemptsLeft ?> attempt<?= $attemptsLeft != 1 ? 's' : '' ?> left</small>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>

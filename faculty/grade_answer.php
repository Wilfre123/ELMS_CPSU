<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty');

$attemptId  = (int)($_GET['attempt_id'] ?? 0);
$questionId = (int)($_GET['question_id'] ?? 0);

// Load attempt + exam + verify faculty owns the section
$stmt = $pdo->prepare("SELECT ea.*, e.section_id, e.id as exam_id, e.title as exam_title, s.faculty_id
    FROM exam_attempts ea JOIN exams e ON ea.exam_id=e.id JOIN sections s ON e.section_id=s.id
    WHERE ea.id=?");
$stmt->execute([$attemptId]);
$attempt = $stmt->fetch();
if (!$attempt || $attempt['faculty_id'] != $_SESSION['user_id']) redirect(BASE_URL . '/faculty/sections.php');

// Load question
$qStmt = $pdo->prepare("SELECT q.*, COALESCE(eq.points_override, q.points) as max_pts
    FROM questions q JOIN exam_questions eq ON eq.question_id=q.id
    WHERE q.id=? AND eq.exam_id=?");
$qStmt->execute([$questionId, $attempt['exam_id']]);
$question = $qStmt->fetch();
if (!$question || $question['question_type'] !== 'essay') redirect(BASE_URL . '/faculty/exam_results.php?exam_id=' . $attempt['exam_id']);

// Load rubric
$rubric = $pdo->prepare("SELECT * FROM rubric_criteria WHERE question_id=? ORDER BY criterion_order");
$rubric->execute([$questionId]);
$rubric = $rubric->fetchAll();

// Load existing answer
$ansStmt = $pdo->prepare("SELECT * FROM exam_answers WHERE attempt_id=? AND question_id=?");
$ansStmt->execute([$attemptId, $questionId]);
$answer = $ansStmt->fetch();

// Load student info
$stuStmt = $pdo->prepare("SELECT name, username FROM users WHERE id=?");
$stuStmt->execute([$attempt['student_id']]);
$student = $stuStmt->fetch();

$pageTitle = 'Grade Essay';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['post_action'] === 'grade') {
    $points   = (float)$_POST['points_earned'];
    $feedback = trim($_POST['feedback'] ?? '');
    $points   = min($points, $question['max_pts']);

    $pdo->prepare("UPDATE exam_answers SET points_earned=?, is_correct=?, feedback=? WHERE attempt_id=? AND question_id=?")
        ->execute([$points, $points > 0 ? 1 : 0, $feedback, $attemptId, $questionId]);

    // Recalculate attempt total
    recalculateAttemptScore($pdo, $attemptId, $attempt['exam_id']);

    flashMessage('success', 'Essay graded successfully.');
    redirect(BASE_URL . '/faculty/exam_results.php?exam_id=' . $attempt['exam_id'] . '&attempt_id=' . $attemptId);
}

function recalculateAttemptScore($pdo, $attemptId, $examId) {
    $scoreStmt = $pdo->prepare("SELECT COALESCE(SUM(ans.points_earned),0), COALESCE(SUM(COALESCE(eq.points_override, q.points)),0)
        FROM exam_answers ans
        JOIN questions q ON ans.question_id=q.id
        JOIN exam_questions eq ON eq.exam_id=? AND eq.question_id=q.id
        WHERE ans.attempt_id=?");
    $scoreStmt->execute([$examId, $attemptId]);
    [$score, $total] = $scoreStmt->fetch(PDO::FETCH_NUM);
    $pct = $total > 0 ? round($score / $total * 100, 2) : 0;

    // Check if any essays still ungraded
    $ungradedStmt = $pdo->prepare("SELECT COUNT(*) FROM exam_answers ans JOIN questions q ON ans.question_id=q.id
        WHERE ans.attempt_id=? AND q.question_type='essay' AND ans.points_earned IS NULL");
    $ungradedStmt->execute([$attemptId]);
    $ungraded = $ungradedStmt->fetchColumn();
    $isGraded = $ungraded == 0 ? 1 : 0;
    $status   = $isGraded ? 'graded' : 'submitted';

    $pdo->prepare("UPDATE exam_attempts SET score=?, total_points=?, percentage=?, is_graded=?, status=? WHERE id=?")
        ->execute([$score, $total, $pct, $isGraded, $status, $attemptId]);
}

include '../includes/header.php';
?>

<div class="page-header">
    <div>
        <a href="<?= BASE_URL ?>/faculty/exam_results.php?exam_id=<?= $attempt['exam_id'] ?>&attempt_id=<?= $attemptId ?>" class="btn btn-sm btn-secondary mb-2">
            <i class="fas fa-arrow-left"></i> Back to Attempt
        </a>
        <h1><i class="fas fa-pen-fancy"></i> Grade Essay</h1>
        <p class="text-muted">
            <?= sanitize($student['name']) ?> (<?= sanitize($student['username']) ?>) &nbsp;|&nbsp;
            <?= sanitize($attempt['exam_title']) ?>
        </p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><h3><i class="fas fa-question-circle"></i> Question</h3></div>
    <div class="card-body">
        <p class="question-text"><?= sanitize($question['question_text']) ?></p>
        <p class="text-muted">Max Points: <?= $question['max_pts'] ?></p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><h3><i class="fas fa-user-edit"></i> Student Answer</h3></div>
    <div class="card-body">
        <div class="essay-box">
            <?= nl2br(sanitize($answer['answer_text'] ?? 'No answer submitted.')) ?>
        </div>
    </div>
</div>

<form method="POST">
    <input type="hidden" name="post_action" value="grade">
    <?php if (!empty($rubric)): ?>
    <div class="card mb-4">
        <div class="card-header"><h3><i class="fas fa-ruler-combined"></i> Rubric</h3></div>
        <div class="card-body p-0">
            <table class="table">
                <thead><tr><th>Criterion</th><th>Description</th><th>Max Points</th></tr></thead>
                <tbody>
                <?php foreach ($rubric as $rc): ?>
                <tr>
                    <td><strong><?= sanitize($rc['criterion_name']) ?></strong></td>
                    <td><?= sanitize($rc['description'] ?? '') ?></td>
                    <td><?= $rc['max_points'] ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h3><i class="fas fa-star"></i> Grade</h3></div>
        <div class="card-body">
            <div class="form-group">
                <label>Points Earned (max: <?= $question['max_pts'] ?>)</label>
                <input type="number" name="points_earned" class="form-control" style="max-width:120px"
                    min="0" max="<?= $question['max_pts'] ?>" step="0.5"
                    value="<?= $answer['points_earned'] ?? 0 ?>" required>
            </div>
            <div class="form-group">
                <label>Feedback to Student</label>
                <textarea name="feedback" class="form-control" rows="3" placeholder="Optional feedback..."><?= sanitize($answer['feedback'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Grade</button>
        </div>
    </div>
</form>

<?php include '../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('student');

$attemptId = (int)($_GET['attempt_id'] ?? 0);
$studentId = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT ea.*, e.title as exam_title, e.passing_score, e.show_results, e.section_id, e.exam_type
    FROM exam_attempts ea JOIN exams e ON ea.exam_id=e.id
    WHERE ea.id=? AND ea.student_id=?");
$stmt->execute([$attemptId, $studentId]);
$attempt = $stmt->fetch();
if (!$attempt || $attempt['status'] === 'in_progress') redirect(BASE_URL . '/student/courses.php');

$pageTitle = 'Result: ' . $attempt['exam_title'];
$passed = $attempt['percentage'] !== null && $attempt['percentage'] >= $attempt['passing_score'];

$answers = [];
if ($attempt['show_results'] && $attempt['status'] === 'graded') {
    $aStmt = $pdo->prepare("SELECT ans.*, q.question_text, q.question_type, COALESCE(eq.points_override, q.points) as max_pts,
        q.explanation,
        o.option_text as sel_option_text
        FROM exam_answers ans
        JOIN questions q ON ans.question_id=q.id
        JOIN exam_questions eq ON eq.exam_id=? AND eq.question_id=q.id
        LEFT JOIN question_options o ON ans.selected_option_id=o.id
        WHERE ans.attempt_id=?
        ORDER BY eq.order_index");
    $aStmt->execute([$attempt['exam_id'], $attemptId]);
    $answers = $aStmt->fetchAll();

    foreach ($answers as &$ans) {
        if ($ans['question_type'] === 'multiple_choice') {
            $corrOpt = $pdo->prepare("SELECT option_text FROM question_options WHERE question_id=? AND is_correct=1 LIMIT 1");
            $corrOpt->execute([$ans['question_id']]);
            $ans['correct_option'] = $corrOpt->fetchColumn();
        }
        if ($ans['question_type'] === 'matching') {
            $mOpts = $pdo->prepare("SELECT option_text, match_pair FROM question_options WHERE question_id=? ORDER BY option_order");
            $mOpts->execute([$ans['question_id']]);
            $ans['match_pairs_correct'] = $mOpts->fetchAll();
        }
    }
    unset($ans);
}

$integrity = $pdo->prepare("SELECT * FROM integrity_logs WHERE attempt_id=?");
$integrity->execute([$attemptId]);
$intLogs = $integrity->fetchAll();

// Section info for back link
$secStmt = $pdo->prepare("SELECT s.*, c.course_code FROM sections s JOIN courses c ON s.course_id=c.id WHERE s.id=?");
$secStmt->execute([$attempt['section_id']]);
$section = $secStmt->fetch();

include '../includes/header.php';
?>

<div class="page-header">
    <div>
        <a href="<?= BASE_URL ?>/student/exams.php?section_id=<?= $attempt['section_id'] ?>" class="btn btn-sm btn-secondary mb-2">
            <i class="fas fa-arrow-left"></i> Back to Exams
        </a>
        <h1><i class="fas fa-poll"></i> <?= sanitize($attempt['exam_title']) ?></h1>
        <p class="text-muted"><?= sanitize($section['course_code'] ?? '') ?> — <?= ucfirst($attempt['exam_type']) ?></p>
    </div>
</div>

<!-- Score Card -->
<div class="result-score-card <?= $attempt['status'] === 'submitted' ? 'result-pending' : ($passed ? 'result-pass' : 'result-fail') ?>">
    <?php if ($attempt['status'] === 'submitted'): ?>
    <div class="result-badge pending"><i class="fas fa-hourglass-half"></i> Awaiting Grading</div>
    <p>Your exam contains essay questions that need to be graded by your instructor.</p>
    <?php else: ?>
    <div class="result-badge <?= $passed ? 'pass' : 'fail' ?>">
        <?= $passed ? '<i class="fas fa-check-circle"></i> PASSED' : '<i class="fas fa-times-circle"></i> FAILED' ?>
    </div>
    <div class="result-nums">
        <div><span class="result-big"><?= number_format($attempt['score'],1) ?></span><span class="result-denom"> / <?= number_format($attempt['total_points'],1) ?></span></div>
        <div class="result-pct"><?= number_format($attempt['percentage'],1) ?>%</div>
    </div>
    <p class="result-passing">Passing score: <?= $attempt['passing_score'] ?>%</p>
    <?php endif; ?>
    <?php if ($attempt['submitted_at']): ?>
    <p class="result-time"><i class="fas fa-clock"></i> Submitted: <?= date('F j, Y g:i A', strtotime($attempt['submitted_at'])) ?></p>
    <?php endif; ?>
</div>

<?php if (!empty($intLogs)): ?>
<div class="integrity-banner mt-3">
    <strong><i class="fas fa-exclamation-triangle"></i> Integrity Notice:</strong>
    The following activities were detected during your exam:
    <?php foreach ($intLogs as $il): ?>
    <span class="badge badge-danger ml-1"><?= str_replace('_',' ', $il['event_type']) ?> (<?= $il['event_count'] ?>×)</span>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($attempt['show_results'] && $attempt['status'] === 'graded' && !empty($answers)): ?>
<h2 class="mt-4 mb-3"><i class="fas fa-list-check"></i> Answer Review</h2>
<?php foreach ($answers as $i => $ans): ?>
<div class="question-block">
    <div class="question-number">
        Question <?= $i+1 ?> &nbsp;|&nbsp;
        <?php if ($ans['points_earned'] == $ans['max_pts']): ?>
            <span class="result-correct"><i class="fas fa-check-circle"></i> Full Credit</span>
        <?php elseif ($ans['points_earned'] > 0): ?>
            <span class="result-partial"><i class="fas fa-adjust"></i> Partial Credit</span>
        <?php else: ?>
            <span class="result-wrong"><i class="fas fa-times-circle"></i> No Credit</span>
        <?php endif; ?>
        &nbsp; <?= $ans['points_earned'] ?? '—' ?> / <?= $ans['max_pts'] ?> pts
    </div>
    <p class="question-text"><?= sanitize($ans['question_text']) ?></p>

    <?php if ($ans['question_type'] === 'essay'): ?>
        <div class="answer-box">
            <strong>Your Answer:</strong>
            <p><?= nl2br(sanitize($ans['answer_text'] ?? 'No answer')) ?></p>
        </div>
        <?php if ($ans['feedback']): ?><p class="qbank-explanation"><i class="fas fa-comment"></i> <?= sanitize($ans['feedback']) ?></p><?php endif; ?>

    <?php elseif ($ans['question_type'] === 'multiple_choice' || $ans['question_type'] === 'true_false'): ?>
        <p>Your answer: <strong><?= sanitize($ans['sel_option_text'] ?? '—') ?></strong></p>
        <?php if (!$ans['is_correct'] && $ans['correct_option']): ?>
        <p class="result-correct"><i class="fas fa-check"></i> Correct answer: <strong><?= sanitize($ans['correct_option']) ?></strong></p>
        <?php endif; ?>

    <?php elseif ($ans['question_type'] === 'short_answer'): ?>
        <p>Your answer: <strong><?= sanitize($ans['answer_text'] ?? '—') ?></strong></p>
        <?php if (!$ans['is_correct']): ?>
        <?php
        $saCorr = $pdo->prepare("SELECT option_text FROM question_options WHERE question_id=? AND is_correct=1 LIMIT 1");
        $saCorr->execute([$ans['question_id']]);
        $saCorrectAnswer = $saCorr->fetchColumn();
        ?>
        <p class="result-correct"><i class="fas fa-check"></i> Correct: <strong><?= sanitize($saCorrectAnswer) ?></strong></p>
        <?php endif; ?>

    <?php elseif ($ans['question_type'] === 'matching'): ?>
        <?php $studentPairs = json_decode($ans['matching_pairs'] ?? '{}', true); ?>
        <table class="match-preview">
        <thead><tr><th>Item</th><th>Your Answer</th><th>Correct</th></tr></thead>
        <tbody>
        <?php foreach ($ans['match_pairs_correct'] as $mp): ?>
        <tr>
            <td><?= sanitize($mp['option_text']) ?></td>
            <td class="<?= strtolower($studentPairs[$mp['option_text']] ?? '') === strtolower($mp['match_pair']) ? 'result-correct' : 'result-wrong' ?>">
                <?= sanitize($studentPairs[$mp['option_text']] ?? '—') ?>
            </td>
            <td class="result-correct"><?= sanitize($mp['match_pair']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        </table>
    <?php endif; ?>

    <?php if ($ans['explanation']): ?>
    <p class="qbank-explanation mt-2"><i class="fas fa-lightbulb"></i> <?= sanitize($ans['explanation']) ?></p>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<?php elseif ($attempt['status'] === 'graded' && !$attempt['show_results']): ?>
<div class="alert alert-info mt-3"><i class="fas fa-info-circle"></i> Detailed results are not available for this exam.</div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>

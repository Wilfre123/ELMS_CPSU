<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty');

$examId = (int)($_GET['exam_id'] ?? 0);
$examStmt = $pdo->prepare("SELECT e.*, s.faculty_id, s.id as section_id, c.course_code FROM exams e JOIN sections s ON e.section_id=s.id JOIN courses c ON s.course_id=c.id WHERE e.id=?");
$examStmt->execute([$examId]);
$exam = $examStmt->fetch();
if (!$exam || $exam['faculty_id'] != $_SESSION['user_id']) redirect(BASE_URL . '/faculty/sections.php');

$pageTitle = 'Results: ' . $exam['title'];
$sectionId = $exam['section_id'];

$attemptId = (int)($_GET['attempt_id'] ?? 0);

if ($attemptId) {
    // Detail view
    $attStmt = $pdo->prepare("SELECT ea.*, CONCAT(u.first_name,' ',u.last_name) as student_name, u.username FROM exam_attempts ea JOIN users u ON ea.student_id=u.id WHERE ea.id=? AND ea.exam_id=?");
    $attStmt->execute([$attemptId, $examId]);
    $attempt = $attStmt->fetch();
    if (!$attempt) redirect(BASE_URL . '/faculty/exam_results.php?exam_id=' . $examId);

    // Answers
    $answers = $pdo->prepare("SELECT ans.*, q.question_text, q.question_type, q.points as max_points,
        COALESCE(eq.points_override, q.points) as question_points,
        o.option_text as selected_option_text
        FROM exam_answers ans
        JOIN questions q ON ans.question_id=q.id
        JOIN exam_questions eq ON eq.exam_id=? AND eq.question_id=q.id
        LEFT JOIN question_options o ON ans.selected_option_id=o.id
        WHERE ans.attempt_id=?
        ORDER BY eq.order_index");
    $answers->execute([$examId, $attemptId]);
    $answers = $answers->fetchAll();

    // Integrity log
    $integrity = $pdo->prepare("SELECT * FROM integrity_logs WHERE attempt_id=?");
    $integrity->execute([$attemptId]);
    $intLogs = $integrity->fetchAll();

    include '../includes/header.php';
    ?>
<div class="page-header">
    <div>
        <a href="<?= BASE_URL ?>/faculty/exam_results.php?exam_id=<?= $examId ?>" class="btn btn-sm btn-secondary mb-2">
            <i class="fas fa-arrow-left"></i> Back to Results List
        </a>
        <h1><i class="fas fa-user-graduate"></i> <?= sanitize($attempt['student_name']) ?> — <?= sanitize($exam['title']) ?></h1>
        <p class="text-muted">Attempt #<?= $attempt['attempt_number'] ?> &nbsp;|&nbsp;
            Submitted: <?= $attempt['submitted_at'] ? date('M j, Y g:i A', strtotime($attempt['submitted_at'])) : 'In Progress' ?>
        </p>
    </div>
</div>

<!-- Score Summary -->
<div class="stats-row mb-4">
    <div class="stat-card">
        <span class="stat-num"><?= number_format($attempt['score'],1) ?></span>
        <span class="stat-label">Score</span>
    </div>
    <div class="stat-card">
        <span class="stat-num"><?= number_format($attempt['total_points'],1) ?></span>
        <span class="stat-label">Total Points</span>
    </div>
    <div class="stat-card">
        <span class="stat-num"><?= $attempt['percentage'] !== null ? number_format($attempt['percentage'],1).'%' : '—' ?></span>
        <span class="stat-label">Percentage</span>
    </div>
    <div class="stat-card">
        <span class="stat-num">
            <?php if ($attempt['percentage'] !== null): ?>
            <span class="<?= $attempt['percentage'] >= $exam['passing_score'] ? 'result-correct' : 'result-wrong' ?>">
                <?= $attempt['percentage'] >= $exam['passing_score'] ? 'PASSED' : 'FAILED' ?>
            </span>
            <?php else: ?>—<?php endif; ?>
        </span>
        <span class="stat-label">Status</span>
    </div>
</div>

<?php if (!empty($intLogs)): ?>
<div class="integrity-banner mb-3">
    <strong><i class="fas fa-exclamation-triangle"></i> Academic Integrity Flags:</strong>
    <?php foreach ($intLogs as $il): ?>
    <span class="badge badge-danger ml-2"><?= str_replace('_',' ', $il['event_type']) ?>: <?= $il['event_count'] ?>×</span>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Answers Detail -->
<?php foreach ($answers as $i => $ans): ?>
<div class="question-block">
    <div class="question-number">Question <?= $i+1 ?> &nbsp;|&nbsp; <?= str_replace('_',' ',ucfirst($ans['question_type'])) ?> &nbsp;|&nbsp; <?= $ans['question_points'] ?> pts</div>
    <p class="question-text"><?= sanitize($ans['question_text']) ?></p>

    <?php if ($ans['question_type'] === 'essay'): ?>
        <div class="answer-box">
            <strong>Student Answer:</strong>
            <p><?= nl2br(sanitize($ans['answer_text'] ?? 'No answer')) ?></p>
        </div>
        <div class="grading-row">
            <strong>Points Earned:</strong>
            <?php if ($ans['points_earned'] !== null): ?>
                <span class="result-correct"><?= $ans['points_earned'] ?> / <?= $ans['question_points'] ?></span>
            <?php else: ?>
                <span class="badge badge-warning">Not Yet Graded</span>
            <?php endif; ?>
            &nbsp;
            <a href="<?= BASE_URL ?>/faculty/grade_answer.php?attempt_id=<?= $attemptId ?>&question_id=<?= $ans['question_id'] ?>" class="btn btn-sm btn-primary">
                <i class="fas fa-pen"></i> Grade
            </a>
        </div>
        <?php if ($ans['feedback']): ?><p class="qbank-explanation"><i class="fas fa-comment"></i> <?= sanitize($ans['feedback']) ?></p><?php endif; ?>
    <?php else: ?>
        <?php
        $correct   = $ans['is_correct'];
        $pts       = $ans['points_earned'];
        $maxPts    = $ans['question_points'];
        $statusClass = $correct ? 'result-correct' : ($pts > 0 ? 'result-partial' : 'result-wrong');
        $icon = $correct ? 'fa-check-circle' : ($pts > 0 ? 'fa-adjust' : 'fa-times-circle');
        ?>
        <div class="answer-inline">
            <i class="fas <?= $icon ?> <?= $statusClass ?>"></i>
            <?php if ($ans['question_type'] === 'matching'): ?>
                <span>Student pairs: <code><?= sanitize($ans['matching_pairs'] ?? '') ?></code></span>
            <?php else: ?>
                <span>Answer: <strong><?= sanitize($ans['selected_option_text'] ?? $ans['answer_text'] ?? '—') ?></strong></span>
            <?php endif; ?>
            <span class="<?= $statusClass ?>">&nbsp; <?= $pts ?> / <?= $maxPts ?> pts</span>
        </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php include '../includes/footer.php'; ?>
<?php

} else {
    // List view
    $attempts = $pdo->prepare("SELECT ea.*, CONCAT(u.first_name,' ',u.last_name) as student_name, u.username
        FROM exam_attempts ea JOIN users u ON ea.student_id=u.id
        WHERE ea.exam_id=? AND ea.status != 'in_progress'
        ORDER BY ea.submitted_at DESC");
    $attempts->execute([$examId]);
    $attempts = $attempts->fetchAll();

    $stats = ['count' => count($attempts), 'avg' => 0, 'high' => 0, 'low' => PHP_INT_MAX, 'pass' => 0];
    foreach ($attempts as $a) {
        if ($a['percentage'] !== null) {
            $stats['avg'] += $a['percentage'];
            if ($a['percentage'] > $stats['high']) $stats['high'] = $a['percentage'];
            if ($a['percentage'] < $stats['low'])  $stats['low']  = $a['percentage'];
            if ($a['percentage'] >= $exam['passing_score']) $stats['pass']++;
        }
    }
    if ($stats['count'] > 0) $stats['avg'] /= $stats['count'];
    if ($stats['low'] === PHP_INT_MAX) $stats['low'] = 0;

    include '../includes/header.php';
    ?>
<div class="page-header">
    <div>
        <a href="<?= BASE_URL ?>/faculty/exams.php?section_id=<?= $sectionId ?>" class="btn btn-sm btn-secondary mb-2">
            <i class="fas fa-arrow-left"></i> Back to Exams
        </a>
        <h1><i class="fas fa-chart-bar"></i> Results: <?= sanitize($exam['title']) ?></h1>
        <p class="text-muted"><?= sanitize($exam['course_code']) ?></p>
    </div>
</div>

<div class="stats-row mb-4">
    <div class="stat-card">
        <span class="stat-num"><?= $stats['count'] ?></span>
        <span class="stat-label">Submissions</span>
    </div>
    <div class="stat-card">
        <span class="stat-num"><?= number_format($stats['avg'],1) ?>%</span>
        <span class="stat-label">Average</span>
    </div>
    <div class="stat-card">
        <span class="stat-num"><?= number_format($stats['high'],1) ?>%</span>
        <span class="stat-label">Highest</span>
    </div>
    <div class="stat-card">
        <span class="stat-num"><?= number_format($stats['low'],1) ?>%</span>
        <span class="stat-label">Lowest</span>
    </div>
    <div class="stat-card">
        <span class="stat-num"><?= $stats['count'] > 0 ? number_format($stats['pass']/$stats['count']*100,0).'%' : '—' ?></span>
        <span class="stat-label">Pass Rate</span>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table">
            <thead>
                <tr><th>Student</th><th>Attempt</th><th>Submitted</th><th>Score</th><th>%</th><th>Status</th><th>Integrity</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($attempts as $a): ?>
            <?php
                $intStmt = $pdo->prepare("SELECT SUM(event_count) FROM integrity_logs WHERE attempt_id=?");
                $intStmt->execute([$a['id']]);
                $flags = (int)$intStmt->fetchColumn();
            ?>
            <tr>
                <td><strong><?= sanitize($a['student_name']) ?></strong><br><small class="text-muted"><?= sanitize($a['username']) ?></small></td>
                <td>#<?= $a['attempt_number'] ?></td>
                <td><?= $a['submitted_at'] ? date('M j, g:i A', strtotime($a['submitted_at'])) : '—' ?></td>
                <td><?= $a['score'] !== null ? number_format($a['score'],1).' / '.number_format($a['total_points'],1) : '—' ?></td>
                <td><?= $a['percentage'] !== null ? number_format($a['percentage'],1).'%' : '—' ?></td>
                <td>
                    <?php $statusColors = ['submitted'=>'badge-warning','graded'=>'badge-success','in_progress'=>'badge-secondary']; ?>
                    <span class="badge <?= $statusColors[$a['status']] ?? 'badge-secondary' ?>"><?= ucfirst($a['status']) ?></span>
                    <?php if ($a['is_graded'] == 0 && $a['status'] === 'submitted'): ?>
                    <span class="badge badge-danger">Needs Grading</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($flags > 0): ?>
                    <span class="badge badge-danger"><i class="fas fa-flag"></i> <?= $flags ?></span>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="?exam_id=<?= $examId ?>&attempt_id=<?= $a['id'] ?>" class="btn btn-sm btn-primary">
                        <i class="fas fa-eye"></i> View
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($attempts)): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">No submissions yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
    include '../includes/footer.php';
}
?>

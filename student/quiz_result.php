<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('student');

$attemptId = (int)($_GET['attempt_id'] ?? 0);
if (!$attemptId) redirect(BASE_URL . '/student/courses.php');

$userId = $_SESSION['user_id'];

// Load attempt for this student
$stmt = $pdo->prepare("
    SELECT qa.*, q.title as quiz_title, q.passing_score, q.show_results,
           s.id as section_id, c.course_code, c.course_name, q.id as quiz_id,
           q.time_limit, q.quiz_type
    FROM quiz_attempts qa
    JOIN quizzes q ON q.id = qa.quiz_id
    JOIN sections s ON s.id = q.section_id
    JOIN courses c ON c.id = s.course_id
    WHERE qa.id=? AND qa.student_id=?
");
$stmt->execute([$attemptId, $userId]);
$attempt = $stmt->fetch();
if (!$attempt || $attempt['status'] === 'in_progress') redirect(BASE_URL . '/student/courses.php');

$pageTitle = 'Result - ' . $attempt['quiz_title'];
$passed = $attempt['percentage'] !== null && $attempt['percentage'] >= $attempt['passing_score'];

// Load answers with question details if show_results is enabled
$answers = [];
if ($attempt['show_results']) {
    $ansStmt = $pdo->prepare("
        SELECT ans.*, q.question_text, q.question_type, q.explanation,
               COALESCE(qq.points_override, q.points) as max_pts
        FROM quiz_answers ans
        JOIN questions q ON q.id = ans.question_id
        JOIN quiz_questions qq ON qq.quiz_id=? AND qq.question_id=q.id
        WHERE ans.attempt_id=?
        ORDER BY qq.order_index
    ");
    $ansStmt->execute([$attempt['quiz_id'], $attemptId]);
    $answers = $ansStmt->fetchAll();

    foreach ($answers as &$ans) {
        if (in_array($ans['question_type'], ['multiple_choice','true_false','matching'])) {
            $opts = $pdo->prepare("SELECT * FROM question_options WHERE question_id=? ORDER BY order_index");
            $opts->execute([$ans['question_id']]);
            $ans['options'] = $opts->fetchAll();
        }
    }
    unset($ans);
}

$timeSpent = null;
if ($attempt['started_at'] && $attempt['submitted_at']) {
    $secs = strtotime($attempt['submitted_at']) - strtotime($attempt['started_at']);
    $timeSpent = gmdate('H:i:s', max(0, $secs));
}

include '../includes/header.php';
?>

<div style="max-width:800px;margin:0 auto;">
    <div class="page-header">
        <div>
            <a href="<?= BASE_URL ?>/student/courses.php" class="btn btn-sm btn-secondary mb-2">
                <i class="fas fa-arrow-left"></i> Back to Courses
            </a>
            <h1><i class="fas fa-clipboard-check"></i> Quiz Result</h1>
            <p class="text-muted"><?= sanitize($attempt['course_code']) ?> — <?= sanitize($attempt['quiz_title']) ?></p>
        </div>
    </div>

    <!-- Result Card -->
    <div class="card mb-4 result-hero <?= $attempt['percentage'] === null ? 'pending' : ($passed ? 'passed' : 'failed') ?>">
        <div class="card-body text-center py-5">
            <?php if ($attempt['percentage'] === null): ?>
            <div class="result-icon pending"><i class="fas fa-hourglass-half"></i></div>
            <h2>Pending Grading</h2>
            <p class="text-muted">Your essay answers are being graded by your instructor. Check back later.</p>
            <?php else: ?>
            <div class="result-icon <?= $passed ? 'pass' : 'fail' ?>">
                <i class="fas fa-<?= $passed ? 'check-circle' : 'times-circle' ?>"></i>
            </div>
            <h2><?= $passed ? 'Passed!' : 'Not Passed' ?></h2>
            <div class="score-display"><?= number_format($attempt['percentage'], 1) ?>%</div>
            <p><?= number_format($attempt['score'], 1) ?> / <?= number_format($attempt['max_score'], 1) ?> points</p>
            <p class="text-muted">Passing score: <?= $attempt['passing_score'] ?>%</p>
            <?php endif; ?>

            <div class="result-meta">
                <div><i class="fas fa-redo"></i> Attempt <?= $attempt['attempt_number'] ?></div>
                <?php if ($timeSpent): ?>
                <div><i class="fas fa-clock"></i> Time: <?= $timeSpent ?></div>
                <?php endif; ?>
                <div><i class="fas fa-calendar"></i> Submitted: <?= $attempt['submitted_at'] ? date('M d, Y g:i A', strtotime($attempt['submitted_at'])) : '—' ?></div>
            </div>
        </div>
    </div>

    <!-- Answer Review -->
    <?php if ($attempt['show_results'] && !empty($answers)): ?>
    <h3 style="margin-bottom:16px;"><i class="fas fa-list-check"></i> Answer Review</h3>
    <?php foreach ($answers as $i => $ans): ?>
    <div class="card mb-3 ans-review <?= $ans['is_correct'] === null ? 'manual' : ($ans['is_correct'] ? 'correct' : 'incorrect') ?>">
        <div class="card-body">
            <div class="d-flex" style="justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
                <div style="display:flex;gap:8px;align-items:center;">
                    <span class="q-num-sm"><?= $i+1 ?></span>
                    <span class="badge badge-<?= $ans['question_type'] ?>"><?= str_replace('_',' ',ucfirst($ans['question_type'])) ?></span>
                </div>
                <div style="display:flex;gap:8px;align-items:center;">
                    <?php if ($ans['is_correct'] === null): ?>
                    <span class="badge badge-warning"><i class="fas fa-pen"></i> Manual Grading</span>
                    <?php elseif ($ans['is_correct']): ?>
                    <span class="badge badge-success"><i class="fas fa-check"></i> Correct</span>
                    <?php else: ?>
                    <span class="badge badge-danger"><i class="fas fa-times"></i> Incorrect</span>
                    <?php endif; ?>
                    <span class="text-muted" style="font-size:13px;"><?= $ans['score'] ?? '?' ?> / <?= $ans['max_pts'] ?> pts</span>
                </div>
            </div>
            <p class="qcard-text"><?= nl2br(sanitize($ans['question_text'])) ?></p>

            <?php if ($ans['question_type'] === 'multiple_choice' || $ans['question_type'] === 'true_false'): ?>
            <ul style="list-style:none;padding:0;margin:0;">
                <?php foreach ($ans['options'] as $opt): ?>
                <li class="review-opt <?= $opt['id'] == $ans['selected_option_id'] ? 'student-ans' : '' ?> <?= $opt['is_correct'] ? 'correct-opt' : '' ?>">
                    <?php if ($opt['id'] == $ans['selected_option_id']): ?><i class="fas fa-dot-circle"></i><?php else: ?><i class="far fa-circle"></i><?php endif; ?>
                    <?= sanitize($opt['option_text']) ?>
                    <?php if ($opt['is_correct']): ?><span class="badge badge-success ml-1">Correct answer</span><?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>

            <?php elseif ($ans['question_type'] === 'short_answer'): ?>
            <div class="text-answer <?= $ans['is_correct'] ? 'correct-bg' : 'wrong-bg' ?>">
                <strong>Your answer:</strong> <?= sanitize($ans['answer_text'] ?? '(none)') ?>
            </div>

            <?php elseif ($ans['question_type'] === 'essay'): ?>
            <div class="essay-display"><?= nl2br(sanitize($ans['answer_text'] ?? '(no answer)')) ?></div>
            <?php if ($ans['feedback']): ?>
            <div class="feedback-box"><i class="fas fa-comment"></i> <strong>Instructor feedback:</strong> <?= sanitize($ans['feedback']) ?></div>
            <?php endif; ?>

            <?php elseif ($ans['question_type'] === 'matching'): ?>
            <?php
                $studentMatches = [];
                if (!empty($ans['answer_text'])) $studentMatches = json_decode($ans['answer_text'], true) ?? [];
            ?>
            <table class="matching-review-table">
                <thead><tr><th>Left</th><th>Your Answer</th><th>Correct</th></tr></thead>
                <tbody>
                <?php foreach ($ans['options'] as $opt): ?>
                <tr>
                    <td><?= sanitize($opt['option_text']) ?></td>
                    <td class="<?= ($studentMatches[$opt['id']] ?? '') === $opt['match_text'] ? 'correct-cell' : 'wrong-cell' ?>">
                        <?= sanitize($studentMatches[$opt['id']] ?? '—') ?>
                    </td>
                    <td><?= sanitize($opt['match_text']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if ($ans['explanation']): ?>
            <div class="explanation-review"><i class="fas fa-lightbulb"></i> <?= sanitize($ans['explanation']) ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php elseif (!$attempt['show_results']): ?>
    <div class="alert alert-info"><i class="fas fa-eye-slash"></i> Detailed results are not shown for this quiz.</div>
    <?php endif; ?>
</div>

<style>
.result-hero { border-top:6px solid #e2e8f0; }
.result-hero.passed { border-top-color:#22c55e; }
.result-hero.failed { border-top-color:#ef4444; }
.result-hero.pending { border-top-color:#f59e0b; }
.result-icon { font-size:60px; margin-bottom:12px; }
.result-icon.pass { color:#22c55e; }
.result-icon.fail { color:#ef4444; }
.result-icon.pending { color:#f59e0b; }
.score-display { font-size:48px; font-weight:800; margin:8px 0; }
.result-meta { display:flex; gap:24px; justify-content:center; flex-wrap:wrap; margin-top:16px; font-size:13px; color:#64748b; }
.result-meta div { display:flex; gap:6px; align-items:center; }
.ans-review { border-left:4px solid #e2e8f0; }
.ans-review.correct { border-left-color:#22c55e; }
.ans-review.incorrect { border-left-color:#ef4444; }
.ans-review.manual { border-left-color:#f59e0b; }
.q-num-sm { width:24px; height:24px; border-radius:50%; background:var(--primary); color:white; font-size:11px; font-weight:700; display:inline-flex; align-items:center; justify-content:center; }
.review-opt { padding:7px 12px; border:1px solid #e2e8f0; border-radius:6px; margin-bottom:6px; font-size:13px; display:flex; align-items:center; gap:8px; }
.review-opt.student-ans { background:#eff6ff; border-color:#93c5fd; }
.review-opt.correct-opt { background:#dcfce7; border-color:#86efac; }
.review-opt.student-ans.correct-opt { background:#bbf7d0; border-color:#4ade80; font-weight:600; }
.text-answer { padding:8px 12px; border-radius:6px; font-size:13px; margin-top:6px; border:1px solid #e2e8f0; }
.correct-bg { background:#dcfce7; border-color:#86efac; }
.wrong-bg { background:#fee2e2; border-color:#fca5a5; }
.essay-display { padding:12px 16px; background:#f8fafc; border-radius:6px; font-size:14px; white-space:pre-wrap; border:1px solid #e2e8f0; }
.feedback-box { margin-top:8px; background:#fffbeb; padding:8px 12px; border-radius:6px; font-size:13px; border:1px solid #fde68a; }
.matching-review-table { width:100%; font-size:13px; border-collapse:collapse; }
.matching-review-table td, .matching-review-table th { padding:6px 10px; border:1px solid #e2e8f0; }
.correct-cell { background:#dcfce7; color:#16a34a; font-weight:600; }
.wrong-cell { background:#fee2e2; color:#dc2626; }
.explanation-review { margin-top:10px; font-size:12px; color:#64748b; font-style:italic; background:#fffbeb; padding:6px 10px; border-radius:4px; }
.ml-1 { margin-left:4px; }
.qcard-text { font-size:14px; font-weight:500; }
</style>

<?php include '../includes/footer.php'; ?>

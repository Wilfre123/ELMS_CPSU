<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('student');

$examId = (int)($_GET['exam_id'] ?? 0);
$studentId = $_SESSION['user_id'];

// Load exam
$examStmt = $pdo->prepare("SELECT e.*, s.id as section_id FROM exams e JOIN sections s ON e.section_id=s.id WHERE e.id=? AND e.is_published=1");
$examStmt->execute([$examId]);
$exam = $examStmt->fetch();
if (!$exam) redirect(BASE_URL . '/student/courses.php');

$sectionId = $exam['section_id'];
$now = date('Y-m-d H:i:s');

// Check enrollment
$enrStmt = $pdo->prepare("SELECT id FROM enrollments WHERE section_id=? AND student_id=? AND status='enrolled'");
$enrStmt->execute([$sectionId, $studentId]);
if (!$enrStmt->fetch()) redirect(BASE_URL . '/student/courses.php');

// Date range check
if ($exam['start_datetime'] && $now < $exam['start_datetime']) {
    flashMessage('warning', 'This exam is not yet available.');
    redirect(BASE_URL . '/student/exams.php?section_id=' . $sectionId);
}
if ($exam['end_datetime'] && $now > $exam['end_datetime']) {
    flashMessage('warning', 'This exam has closed.');
    redirect(BASE_URL . '/student/exams.php?section_id=' . $sectionId);
}

// Count completed attempts
$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM exam_attempts WHERE exam_id=? AND student_id=? AND status != 'in_progress'");
$cntStmt->execute([$examId, $studentId]);
if ($cntStmt->fetchColumn() >= $exam['max_attempts']) {
    flashMessage('warning', 'You have used all allowed attempts.');
    redirect(BASE_URL . '/student/exams.php?section_id=' . $sectionId);
}

// Check for in-progress attempt
$inProg = $pdo->prepare("SELECT * FROM exam_attempts WHERE exam_id=? AND student_id=? AND status='in_progress' ORDER BY id DESC LIMIT 1");
$inProg->execute([$examId, $studentId]);
$attempt = $inProg->fetch();

// Handle submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_exam'])) {
    $attemptId = (int)$_POST['attempt_id'];
    // Verify it's ours
    $verStmt = $pdo->prepare("SELECT * FROM exam_attempts WHERE id=? AND student_id=? AND exam_id=? AND status='in_progress'");
    $verStmt->execute([$attemptId, $studentId, $examId]);
    $attempt = $verStmt->fetch();
    if (!$attempt) redirect(BASE_URL . '/student/exams.php?section_id=' . $sectionId);

    $questionOrder = json_decode($attempt['question_order'] ?? '[]', true);
    $totalPts = 0;
    $earnedPts = 0;
    $hasEssay = false;

    foreach ($questionOrder as $qid) {
        $qStmt = $pdo->prepare("SELECT q.*, COALESCE(eq.points_override, q.points) as eff_pts FROM questions q JOIN exam_questions eq ON eq.exam_id=? AND eq.question_id=q.id WHERE q.id=?");
        $qStmt->execute([$examId, $qid]);
        $q = $qStmt->fetch();
        if (!$q) continue;
        $totalPts += $q['eff_pts'];

        $ansText      = null;
        $selOptId     = null;
        $matchPairs   = null;
        $pointsEarned = 0;
        $isCorrect    = 0;

        switch ($q['question_type']) {
            case 'multiple_choice':
            case 'true_false':
                $selOptId = (int)($_POST['q_' . $qid] ?? 0);
                if ($selOptId) {
                    $optStmt = $pdo->prepare("SELECT is_correct FROM question_options WHERE id=? AND question_id=?");
                    $optStmt->execute([$selOptId, $qid]);
                    $opt = $optStmt->fetch();
                    if ($opt && $opt['is_correct']) { $pointsEarned = $q['eff_pts']; $isCorrect = 1; }
                }
                break;
            case 'short_answer':
                $ansText = trim($_POST['q_' . $qid] ?? '');
                if ($ansText !== '') {
                    $saStmt = $pdo->prepare("SELECT option_text FROM question_options WHERE question_id=? AND is_correct=1");
                    $saStmt->execute([$qid]);
                    $accepted = $saStmt->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($accepted as $acc) {
                        if (strtolower(trim($ansText)) === strtolower(trim($acc))) {
                            $pointsEarned = $q['eff_pts']; $isCorrect = 1; break;
                        }
                    }
                }
                break;
            case 'matching':
                $pairData = [];
                $mLeft = $pdo->prepare("SELECT id, option_text, match_pair FROM question_options WHERE question_id=? ORDER BY option_order");
                $mLeft->execute([$qid]);
                $leftItems = $mLeft->fetchAll();
                $allCorrect = true; $hasAnswer = false;
                foreach ($leftItems as $li) {
                    $submitted = trim($_POST['q_' . $qid . '_' . $li['id']] ?? '');
                    $pairData[$li['option_text']] = $submitted;
                    if ($submitted !== '') $hasAnswer = true;
                    if (strtolower($submitted) !== strtolower($li['match_pair'])) $allCorrect = false;
                }
                $matchPairs = json_encode($pairData);
                if ($hasAnswer && $allCorrect) { $pointsEarned = $q['eff_pts']; $isCorrect = 1; }
                elseif ($hasAnswer) {
                    // Partial — count correct pairs
                    $correct = 0;
                    foreach ($leftItems as $li) {
                        $submitted = trim($_POST['q_' . $qid . '_' . $li['id']] ?? '');
                        if (strtolower($submitted) === strtolower($li['match_pair'])) $correct++;
                    }
                    if (count($leftItems) > 0) $pointsEarned = round($q['eff_pts'] * $correct / count($leftItems), 2);
                    if ($pointsEarned > 0) $isCorrect = 1;
                }
                break;
            case 'essay':
                $ansText = trim($_POST['q_' . $qid] ?? '');
                $hasEssay = true;
                $pointsEarned = null; // manual grading
                $isCorrect = null;
                $earnedPts = null; // can't sum
                break;
        }

        if ($earnedPts !== null) $earnedPts += $pointsEarned ?? 0;

        $pdo->prepare("INSERT INTO exam_answers (attempt_id, question_id, answer_text, selected_option_id, matching_pairs, points_earned, is_correct) VALUES (?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE answer_text=VALUES(answer_text), selected_option_id=VALUES(selected_option_id), matching_pairs=VALUES(matching_pairs), points_earned=VALUES(points_earned), is_correct=VALUES(is_correct)")
            ->execute([$attempt['id'], $qid, $ansText, $selOptId ?: null, $matchPairs, $pointsEarned ?? null, $isCorrect]);
    }

    $timeSpent = time() - strtotime($attempt['started_at']);
    $pct = null;
    $status = 'submitted';
    $isGraded = 0;
    if (!$hasEssay) {
        $pct = $totalPts > 0 ? round($earnedPts / $totalPts * 100, 2) : 0;
        $status = 'graded';
        $isGraded = 1;
    }

    $pdo->prepare("UPDATE exam_attempts SET submitted_at=NOW(), time_spent=?, score=?, total_points=?, percentage=?, is_graded=?, status=? WHERE id=?")
        ->execute([$timeSpent, $earnedPts, $totalPts, $pct, $isGraded, $status, $attempt['id']]);

    redirect(BASE_URL . '/student/exam_result.php?attempt_id=' . $attempt['id']);
}

// Create new attempt if none
if (!$attempt) {
    $attNumStmt = $pdo->prepare("SELECT COALESCE(MAX(attempt_number),0)+1 FROM exam_attempts WHERE exam_id=? AND student_id=?");
    $attNumStmt->execute([$examId, $studentId]);
    $attNum = $attNumStmt->fetchColumn();

    // Build question order
    $qOrderStmt = $pdo->prepare("SELECT question_id FROM exam_questions WHERE exam_id=? ORDER BY order_index");
    $qOrderStmt->execute([$examId]);
    $qIds = $qOrderStmt->fetchAll(PDO::FETCH_COLUMN);
    if ($exam['randomize_questions']) shuffle($qIds);

    $pdo->prepare("INSERT INTO exam_attempts (exam_id, student_id, attempt_number, started_at, status, question_order) VALUES (?,?,?,NOW(),'in_progress',?)")
        ->execute([$examId, $studentId, $attNum, json_encode($qIds)]);
    $attempt = $pdo->prepare("SELECT * FROM exam_attempts WHERE id=?");
    $attempt->execute([$pdo->lastInsertId()]);
    $attempt = $attempt->fetch();
}

$questionOrder = json_decode($attempt['question_order'] ?? '[]', true);

// Load questions with options
$questionsData = [];
foreach ($questionOrder as $qid) {
    $qStmt = $pdo->prepare("SELECT q.*, COALESCE(eq.points_override, q.points) as eff_pts FROM questions q JOIN exam_questions eq ON eq.exam_id=? AND eq.question_id=q.id WHERE q.id=?");
    $qStmt->execute([$examId, $qid]);
    $q = $qStmt->fetch();
    if (!$q) continue;
    $optStmt = $pdo->prepare("SELECT * FROM question_options WHERE question_id=? ORDER BY option_order");
    $optStmt->execute([$qid]);
    $opts = $optStmt->fetchAll();
    if ($exam['randomize_options'] && in_array($q['question_type'], ['multiple_choice','matching'])) shuffle($opts);
    $q['options'] = $opts;
    // Load saved answer
    $savedStmt = $pdo->prepare("SELECT * FROM exam_answers WHERE attempt_id=? AND question_id=?");
    $savedStmt->execute([$attempt['id'], $qid]);
    $q['saved_answer'] = $savedStmt->fetch();
    $questionsData[] = $q;
}

// Timer — auto-submit server-side if time is already up
$timerEnd = null;
if ($exam['time_limit']) {
    $timerEnd = strtotime($attempt['started_at']) + $exam['time_limit'] * 60;
    if ($timerEnd <= time()) {
        // Time has expired: mark attempt as submitted with whatever answers exist
        $timeSpent = time() - strtotime($attempt['started_at']);
        $scoreStmt = $pdo->prepare("SELECT COALESCE(SUM(ans.points_earned),0), COALESCE(SUM(COALESCE(eq.points_override,q.points)),0)
            FROM exam_answers ans JOIN questions q ON ans.question_id=q.id
            JOIN exam_questions eq ON eq.exam_id=? AND eq.question_id=q.id
            WHERE ans.attempt_id=?");
        $scoreStmt->execute([$examId, $attempt['id']]);
        [$score, $total] = $scoreStmt->fetch(PDO::FETCH_NUM);
        $pct = $total > 0 ? round($score / $total * 100, 2) : 0;
        $ungradedStmt = $pdo->prepare("SELECT COUNT(*) FROM exam_answers ans JOIN questions q ON ans.question_id=q.id WHERE ans.attempt_id=? AND q.question_type='essay' AND ans.points_earned IS NULL");
        $ungradedStmt->execute([$attempt['id']]);
        $hasEssay = $ungradedStmt->fetchColumn() > 0;
        $pdo->prepare("UPDATE exam_attempts SET submitted_at=NOW(), time_spent=?, score=?, total_points=?, percentage=?, is_graded=?, status=? WHERE id=?")
            ->execute([$timeSpent, $score, $total, $pct, $hasEssay ? 0 : 1, $hasEssay ? 'submitted' : 'graded', $attempt['id']]);
        flashMessage('warning', 'Time is up! Your exam was automatically submitted.');
        redirect(BASE_URL . '/student/exam_result.php?attempt_id=' . $attempt['id']);
    }
}

$pageTitle = $exam['title'];
include '../includes/header.php';
?>

<form method="POST" id="examForm">
    <input type="hidden" name="submit_exam" value="1">
    <input type="hidden" name="attempt_id" value="<?= $attempt['id'] ?>">

    <div class="exam-header">
        <div>
            <h2><?= sanitize($exam['title']) ?></h2>
            <small><?= count($questionsData) ?> questions</small>
        </div>
        <?php if ($exam['time_limit']): ?>
        <div>
            <div class="exam-timer" id="examTimer">--:--</div>
            <small style="color:#9ca3af">Time Remaining</small>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($exam['description']): ?>
    <div class="alert alert-info mb-3"><?= sanitize($exam['description']) ?></div>
    <?php endif; ?>

    <div class="integrity-banner" id="integrityBanner" style="display:none">
        <i class="fas fa-exclamation-triangle"></i> <strong>Warning:</strong> Suspicious activity detected. This will be logged.
    </div>

    <?php foreach ($questionsData as $i => $q): ?>
    <div class="question-block" id="qblock-<?= $q['id'] ?>">
        <div class="question-number">Question <?= $i + 1 ?> of <?= count($questionsData) ?> &nbsp;|&nbsp; <?= $q['eff_pts'] ?> pt<?= $q['eff_pts'] != 1 ? 's' : '' ?></div>
        <p class="question-text"><?= sanitize($q['question_text']) ?></p>

        <?php if (in_array($q['question_type'], ['multiple_choice','true_false'])): ?>
        <?php $savedOpt = $q['saved_answer']['selected_option_id'] ?? null; ?>
        <?php foreach ($q['options'] as $opt): ?>
        <label class="option-item">
            <input type="radio" name="q_<?= $q['id'] ?>" value="<?= $opt['id'] ?>" <?= $savedOpt == $opt['id'] ? 'checked' : '' ?>>
            <span><?= sanitize($opt['option_text']) ?></span>
        </label>
        <?php endforeach; ?>

        <?php elseif ($q['question_type'] === 'short_answer'): ?>
        <input type="text" name="q_<?= $q['id'] ?>" class="form-control" style="max-width:400px"
            value="<?= sanitize($q['saved_answer']['answer_text'] ?? '') ?>" placeholder="Your answer...">

        <?php elseif ($q['question_type'] === 'essay'): ?>
        <textarea name="q_<?= $q['id'] ?>" class="form-control" rows="6" placeholder="Write your answer here..."><?= sanitize($q['saved_answer']['answer_text'] ?? '') ?></textarea>

        <?php elseif ($q['question_type'] === 'matching'): ?>
        <?php
            // Build right-side pool from options
            $rightPool = array_column($q['options'], 'match_pair');
            $shuffledRight = $rightPool;
            shuffle($shuffledRight);
            $savedPairs = $q['saved_answer']['matching_pairs'] ? json_decode($q['saved_answer']['matching_pairs'], true) : [];
        ?>
        <table class="matching-table">
            <thead><tr><th>Item</th><th>Match</th></tr></thead>
            <tbody>
            <?php foreach ($q['options'] as $opt): ?>
            <tr>
                <td><?= sanitize($opt['option_text']) ?></td>
                <td>
                    <select name="q_<?= $q['id'] ?>_<?= $opt['id'] ?>" class="form-control-sm">
                        <option value="">— Select —</option>
                        <?php foreach ($shuffledRight as $right): ?>
                        <option value="<?= sanitize($right) ?>" <?= ($savedPairs[$opt['option_text']] ?? '') === $right ? 'selected' : '' ?>>
                            <?= sanitize($right) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div class="mt-4 mb-5">
        <button type="submit" class="btn btn-primary btn-lg" onclick="return confirm('Submit this exam? You cannot change answers after submission.')">
            <i class="fas fa-paper-plane"></i> Submit Exam
        </button>
    </div>
</form>

<?php if ($exam['time_limit']): ?>
<script>
const timerEnd = <?= $timerEnd * 1000 ?>;
const timerEl  = document.getElementById('examTimer');
function updateTimer() {
    const left = Math.floor((timerEnd - Date.now()) / 1000);
    if (left <= 0) {
        timerEl.textContent = '00:00';
        timerEl.classList.add('warning');
        document.getElementById('examForm').submit();
        return;
    }
    const m = String(Math.floor(left/60)).padStart(2,'0');
    const s = String(left%60).padStart(2,'0');
    timerEl.textContent = m+':'+s;
    if (left <= 60) timerEl.classList.add('warning');
}
updateTimer();
setInterval(updateTimer, 1000);
</script>
<?php endif; ?>

<script>
const attemptId = <?= $attempt['id'] ?>;
const banner = document.getElementById('integrityBanner');
function logEvent(type) {
    fetch('<?= BASE_URL ?>/ajax/integrity_log.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({attempt_id: attemptId, event_type: type})
    });
    banner.style.display = 'block';
    setTimeout(() => banner.style.display = 'none', 3000);
}
document.addEventListener('visibilitychange', () => { if (document.hidden) logEvent('tab_switch'); });
window.addEventListener('blur', () => logEvent('window_blur'));
document.addEventListener('contextmenu', e => { e.preventDefault(); logEvent('right_click'); });
document.addEventListener('copy', e => { logEvent('copy_attempt'); });
document.addEventListener('paste', e => { logEvent('paste_attempt'); });
document.addEventListener('fullscreenchange', () => { if (!document.fullscreenElement) logEvent('fullscreen_exit'); });
</script>

<?php include '../includes/footer.php'; ?>

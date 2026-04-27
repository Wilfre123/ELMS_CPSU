<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('student');

$assignmentId = (int)($_GET['assignment_id'] ?? 0);
if (!$assignmentId) redirect(BASE_URL . '/student/courses.php');

$studentId = $_SESSION['user_id'];

// Load assignment with enrollment check
$aStmt = $pdo->prepare("SELECT a.*, s.id as section_id, c.course_code, c.course_name, s.section_name
    FROM assignments a
    JOIN sections s ON a.section_id = s.id
    JOIN courses c ON s.course_id = c.id
    JOIN enrollments e ON e.section_id = s.id AND e.student_id = ? AND e.status = 'enrolled'
    WHERE a.id = ? AND a.is_published = 1");
$aStmt->execute([$studentId, $assignmentId]);
$assignment = $aStmt->fetch();
if (!$assignment) redirect(BASE_URL . '/student/courses.php');

$sectionId  = $assignment['section_id'];
$now        = date('Y-m-d H:i:s');
$isPastDue  = $assignment['due_date'] && $now > $assignment['due_date'];

// Count attempts
$attemptsQ = $pdo->prepare("SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id=? AND student_id=?");
$attemptsQ->execute([$assignmentId, $studentId]);
$attemptsUsed = (int)$attemptsQ->fetchColumn();

// Latest submission
$latestQ = $pdo->prepare("SELECT * FROM assignment_submissions WHERE assignment_id=? AND student_id=? ORDER BY attempt_number DESC LIMIT 1");
$latestQ->execute([$assignmentId, $studentId]);
$latestSub = $latestQ->fetch();

// Can the student submit?
$canSubmit = false;
$blockReason = '';
if ($isPastDue && !$assignment['allow_late']) {
    $blockReason = 'The submission deadline has passed.';
} elseif ($isPastDue && $assignment['allow_late'] && $assignment['max_late_days'] > 0) {
    $minutesPast = (strtotime($now) - strtotime($assignment['due_date'])) / 60;
    $daysPast    = ceil($minutesPast / 1440);
    if ($daysPast > $assignment['max_late_days']) {
        $blockReason = 'The late submission window has also closed.';
    } else {
        $canSubmit = $attemptsUsed < $assignment['max_submissions'];
    }
} elseif ($attemptsUsed >= $assignment['max_submissions']) {
    $blockReason = 'You have used all allowed submissions.';
    if ($assignment['max_submissions'] > 1) {
        $blockReason .= " (max {$assignment['max_submissions']})";
    }
} else {
    $canSubmit = true;
}

// Returned? Can resubmit if attempts remain
if ($latestSub && $latestSub['status'] === 'returned' && $attemptsUsed < $assignment['max_submissions'] && !($isPastDue && !$assignment['allow_late'])) {
    $canSubmit = true;
    $blockReason = '';
}

// Faculty-approved resubmit overrides all blocks
if ($latestSub && $latestSub['resubmit_approved'] == 1) {
    $canSubmit = true;
    $blockReason = '';
}

// ──────────────────────────────────
// Handle resubmission request
// ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_resubmit') {
    if ($latestSub && !$canSubmit) {
        $note = trim($_POST['resubmit_note'] ?? '');
        // Only allow if no pending/approved request already
        if ($latestSub['resubmit_approved'] != 1) {
            $pdo->prepare("UPDATE assignment_submissions SET resubmit_requested=1, resubmit_note=?, resubmit_requested_at=NOW(), resubmit_approved=0, resubmit_deny_reason=NULL WHERE id=?")
                ->execute([$note, $latestSub['id']]);
            flashMessage('success', 'Resubmission request sent. Your instructor will review it.');
        }
    }
    redirect(BASE_URL . '/student/assignment_submit.php?assignment_id=' . $assignmentId);
}

// ──────────────────────────────────
// Handle file submission
// ──────────────────────────────────
function detectPlagiarism(PDO $pdo, int $subId, int $assignmentId, int $studentId): void
{
    $myQ = $pdo->prepare("SELECT file_hash, file_path, file_type FROM submission_files WHERE submission_id=?");
    $myQ->execute([$subId]);
    $myFiles = $myQ->fetchAll();
    if (empty($myFiles)) return;

    $myHashes = array_filter(array_column($myFiles, 'file_hash'));

    $othersQ = $pdo->prepare("SELECT sub.id as sub_id, sf.file_hash, sf.file_path, sf.file_type
        FROM assignment_submissions sub
        JOIN submission_files sf ON sf.submission_id = sub.id
        WHERE sub.assignment_id = ? AND sub.id != ? AND sub.student_id != ?");
    $othersQ->execute([$assignmentId, $subId, $studentId]);
    $others = $othersQ->fetchAll();

    $bySubmission = [];
    foreach ($others as $o) { $bySubmission[$o['sub_id']][] = $o; }

    $maxSim = 0.0;
    $matchedId = null;
    $textExts = ['txt','py','java','cpp','c','js','ts','html','php','cs','rb','go','md','json','xml','css','sh'];

    foreach ($bySubmission as $otherSubId => $otherFiles) {
        $otherHashes = array_filter(array_column($otherFiles, 'file_hash'));
        // Exact hash match → 100%
        if (!empty($myHashes) && !empty($otherHashes) && !empty(array_intersect($myHashes, $otherHashes))) {
            if (100 > $maxSim) { $maxSim = 100.0; $matchedId = $otherSubId; }
            continue;
        }
        // Text-based Jaccard similarity
        $totalSim = 0; $comparisons = 0;
        foreach ($myFiles as $mf) {
            $ext = strtolower(pathinfo($mf['file_path'] ?? '', PATHINFO_EXTENSION));
            if (!in_array($ext, $textExts) || !file_exists($mf['file_path'])) continue;
            $myText = @file_get_contents($mf['file_path']);
            if ($myText === false || strlen($myText) < 50) continue;
            $w1 = array_unique(array_filter(preg_split('/\s+/', strtolower($myText)), fn($w) => strlen($w) > 3));
            foreach ($otherFiles as $of) {
                $oExt = strtolower(pathinfo($of['file_path'] ?? '', PATHINFO_EXTENSION));
                if ($oExt !== $ext || !file_exists($of['file_path'])) continue;
                $oText = @file_get_contents($of['file_path']);
                if ($oText === false || strlen($oText) < 50) continue;
                $w2 = array_unique(array_filter(preg_split('/\s+/', strtolower($oText)), fn($w) => strlen($w) > 3));
                $intersection = count(array_intersect($w1, $w2));
                $union = count(array_unique(array_merge(array_values($w1), array_values($w2))));
                if ($union > 0) { $totalSim += ($intersection / $union) * 100; $comparisons++; }
            }
        }
        if ($comparisons > 0) {
            $avg = $totalSim / $comparisons;
            if ($avg > $maxSim) { $maxSim = $avg; $matchedId = $otherSubId; }
        }
    }

    $flag = $maxSim >= 70 ? 1 : 0;
    $pdo->prepare("UPDATE assignment_submissions SET plagiarism_flag=?, plagiarism_similarity=?, plagiarism_matched_id=? WHERE id=?")
        ->execute([$flag, round($maxSim, 2), $matchedId, $subId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canSubmit) {
    // Validate files uploaded
    if (empty($_FILES['submission_files']['name'][0])) {
        flashMessage('danger', 'Please select at least one file to submit.');
        redirect(BASE_URL . '/student/assignment_submit.php?assignment_id=' . $assignmentId);
    }

    $allowedExts = array_map('trim', explode(',', strtolower($assignment['allowed_file_types'])));
    $maxBytes    = $assignment['max_file_size'];
    $uploadDir   = UPLOAD_PATH . 'assignments/';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

    // Validate all files first
    $errors = [];
    $filesToProcess = [];
    foreach ($_FILES['submission_files']['name'] as $i => $origName) {
        if ($_FILES['submission_files']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        if ($_FILES['submission_files']['error'][$i] !== UPLOAD_ERR_OK) {
            $errors[] = "Upload error for file: " . sanitize($origName);
            continue;
        }
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts)) {
            $errors[] = "File type .$ext is not allowed. Allowed: " . $assignment['allowed_file_types'];
            continue;
        }
        if ($_FILES['submission_files']['size'][$i] > $maxBytes) {
            $errors[] = sanitize($origName) . ' exceeds max file size of ' . round($maxBytes/1024/1024, 0) . 'MB.';
            continue;
        }
        $filesToProcess[] = ['name' => $origName, 'tmp' => $_FILES['submission_files']['tmp_name'][$i], 'size' => $_FILES['submission_files']['size'][$i], 'ext' => $ext];
    }

    if (!empty($errors)) {
        foreach ($errors as $err) flashMessage('danger', $err);
        redirect(BASE_URL . '/student/assignment_submit.php?assignment_id=' . $assignmentId);
    }
    if (empty($filesToProcess)) {
        flashMessage('danger', 'No valid files to submit.');
        redirect(BASE_URL . '/student/assignment_submit.php?assignment_id=' . $assignmentId);
    }

    // Calculate lateness
    $isLate = $isPastDue ? 1 : 0;
    $minutesLate = 0;
    if ($isLate && $assignment['due_date']) {
        $minutesLate = (int)round((strtotime($now) - strtotime($assignment['due_date'])) / 60);
    }

    // Create submission record
    $newAttempt = $attemptsUsed + 1;
    $pdo->prepare("INSERT INTO assignment_submissions (assignment_id, student_id, attempt_number, is_late, minutes_late) VALUES (?,?,?,?,?)")
        ->execute([$assignmentId, $studentId, $newAttempt, $isLate, $minutesLate]);
    $subId = (int)$pdo->lastInsertId();

    // Move files and record them
    $combinedHash = '';
    foreach ($filesToProcess as $fi) {
        $stored = $subId . '_' . bin2hex(random_bytes(8)) . '.' . $fi['ext'];
        $dest   = $uploadDir . $stored;
        if (!move_uploaded_file($fi['tmp'], $dest)) {
            // Roll back submission
            $pdo->prepare("DELETE FROM assignment_submissions WHERE id=?")->execute([$subId]);
            flashMessage('danger', 'Failed to save uploaded file. Please try again.');
            redirect(BASE_URL . '/student/assignment_submit.php?assignment_id=' . $assignmentId);
        }
        $hash = hash_file('sha256', $dest);
        $combinedHash .= $hash;
        $pdo->prepare("INSERT INTO submission_files (submission_id, original_filename, stored_filename, file_path, file_size, file_type, file_hash) VALUES (?,?,?,?,?,?,?)")
            ->execute([$subId, $fi['name'], $stored, $dest, $fi['size'], $fi['ext'], $hash]);
    }

    // Update combined hash on submission
    $pdo->prepare("UPDATE assignment_submissions SET plagiarism_hash=? WHERE id=?")
        ->execute([hash('sha256', $combinedHash), $subId]);

    // Run plagiarism detection (async-like: just run it inline, it's fast enough for typical class sizes)
    detectPlagiarism($pdo, $subId, $assignmentId, $studentId);

    $msg = 'Submission received!';
    if ($isLate) $msg .= ' (Marked as late)';
    if ($assignment['max_submissions'] > $newAttempt) {
        $remaining = $assignment['max_submissions'] - $newAttempt;
        $msg .= " You have $remaining resubmission(s) remaining.";
    }
    flashMessage('success', $msg);
    redirect(BASE_URL . '/student/assignment_submit.php?assignment_id=' . $assignmentId);
}

// Load submission history
$historyQ = $pdo->prepare("SELECT sub.*, GROUP_CONCAT(sf.original_filename ORDER BY sf.id SEPARATOR '|||') as filenames,
    GROUP_CONCAT(sf.id ORDER BY sf.id SEPARATOR ',') as file_ids
    FROM assignment_submissions sub
    LEFT JOIN submission_files sf ON sf.submission_id = sub.id
    WHERE sub.assignment_id = ? AND sub.student_id = ?
    GROUP BY sub.id ORDER BY sub.attempt_number DESC");
$historyQ->execute([$assignmentId, $studentId]);
$history = $historyQ->fetchAll();

$pageTitle = $assignment['title'];
include '../includes/header.php';
?>

<div class="page-header">
    <div>
        <a href="<?= BASE_URL ?>/student/course_view.php?id=<?= $sectionId ?>&tab=assignments" class="btn btn-sm btn-secondary mb-2">
            <i class="fas fa-arrow-left"></i> Back to Course
        </a>
        <h1><i class="fas fa-tasks"></i> <?= sanitize($assignment['title']) ?></h1>
        <p class="text-muted"><?= sanitize($assignment['course_code']) ?> – <?= sanitize($assignment['section_name']) ?></p>
    </div>
</div>

<?= getFlash() ?>

<!-- Assignment Details -->
<div class="card mb-4">
    <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr auto;gap:24px;align-items:start">
            <div>
                <?php if ($assignment['description']): ?>
                <p style="font-size:15px;color:var(--secondary);margin-bottom:12px"><?= sanitize($assignment['description']) ?></p>
                <?php endif; ?>
                <?php if ($assignment['instructions']): ?>
                <div style="background:var(--light);border-left:4px solid var(--primary);padding:14px 18px;border-radius:0 var(--radius) var(--radius) 0;font-size:14px;line-height:1.7">
                    <?= nl2br(sanitize($assignment['instructions'])) ?>
                </div>
                <?php endif; ?>
            </div>
            <div style="min-width:200px">
                <div class="assign-info-box">
                    <div class="assign-info-row">
                        <i class="fas fa-calendar-alt"></i>
                        <div>
                            <strong>Due Date</strong>
                            <?php if ($assignment['due_date']): ?>
                            <br><?= date('M j, Y', strtotime($assignment['due_date'])) ?>
                            <br><small><?= date('g:i A', strtotime($assignment['due_date'])) ?></small>
                            <?php if ($isPastDue): ?>
                            <br><span class="badge badge-danger">Deadline Passed</span>
                            <?php elseif (strtotime($assignment['due_date']) - time() < 86400): ?>
                            <br><span class="badge badge-warning">Due Soon</span>
                            <?php endif; ?>
                            <?php else: ?><br><em>No deadline</em><?php endif; ?>
                        </div>
                    </div>
                    <div class="assign-info-row">
                        <i class="fas fa-file-upload"></i>
                        <div>
                            <strong>Allowed Files</strong><br>
                            <small><?= sanitize($assignment['allowed_file_types']) ?></small><br>
                            <small>Max <?= round($assignment['max_file_size']/1024/1024, 0) ?>MB per file</small>
                        </div>
                    </div>
                    <div class="assign-info-row">
                        <i class="fas fa-redo"></i>
                        <div>
                            <strong>Submissions</strong><br>
                            <?= $attemptsUsed ?> / <?= $assignment['max_submissions'] ?> used
                        </div>
                    </div>
                    <div class="assign-info-row">
                        <i class="fas fa-star"></i>
                        <div>
                            <strong>Max Score</strong><br>
                            <?= number_format($assignment['max_score'], 0) ?> pts
                            (Pass: <?= $assignment['passing_score'] ?>%)
                        </div>
                    </div>
                    <?php if ($assignment['allow_late']): ?>
                    <div class="assign-info-row">
                        <i class="fas fa-clock" style="color:#d97706"></i>
                        <div>
                            <strong style="color:#d97706">Late Submissions OK</strong><br>
                            <?php if ($assignment['late_penalty'] > 0): ?><small>-<?= $assignment['late_penalty'] ?>% per day</small><br><?php endif; ?>
                            <?php if ($assignment['max_late_days'] > 0): ?><small>Up to <?= $assignment['max_late_days'] ?> day(s)</small><?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($latestSub && $latestSub['status'] === 'graded'): ?>
<!-- Grade card -->
<?php
$pct = $assignment['max_score'] > 0 ? ($latestSub['final_score'] / $assignment['max_score'] * 100) : 0;
$passed = $pct >= $assignment['passing_score'];
?>
<div class="result-score-card <?= $passed ? 'result-pass' : 'result-fail' ?> mb-4">
    <span class="result-badge <?= $passed ? 'pass' : 'fail' ?>"><?= $passed ? 'PASSED' : 'FAILED' ?></span>
    <div class="result-nums">
        <span class="result-big"><?= number_format($latestSub['final_score'], 1) ?></span>
        <span class="result-denom"> / <?= number_format($assignment['max_score'], 0) ?></span>
    </div>
    <div class="result-pct"><?= number_format($pct, 1) ?>%</div>
    <?php if ($latestSub['late_penalty_applied'] > 0): ?>
    <p style="color:#d97706;font-size:13px"><i class="fas fa-clock"></i> Late penalty applied: -<?= $latestSub['late_penalty_applied'] ?>%</p>
    <?php endif; ?>
    <?php if ($latestSub['feedback']): ?>
    <div style="margin-top:16px;text-align:left;background:white;border-radius:var(--radius);padding:14px 18px;border:1px solid var(--border)">
        <strong><i class="fas fa-comment-alt"></i> Instructor Feedback:</strong>
        <p style="margin-top:8px;line-height:1.7"><?= nl2br(sanitize($latestSub['feedback'])) ?></p>
    </div>
    <?php endif; ?>
</div>
<?php elseif ($latestSub && $latestSub['status'] === 'returned'): ?>
<div class="card mb-4" style="border-color:#d97706;background:#fffbeb">
    <div class="card-body">
        <h4 style="color:#d97706"><i class="fas fa-undo"></i> Returned for Revision</h4>
        <?php if ($latestSub['feedback']): ?>
        <p><?= nl2br(sanitize($latestSub['feedback'])) ?></p>
        <?php endif; ?>
        <?php if ($canSubmit): ?><p><strong>You can resubmit below.</strong></p><?php endif; ?>
    </div>
</div>
<?php elseif ($latestSub && $latestSub['status'] === 'submitted'): ?>
<div class="card mb-4" style="border-color:var(--primary);background:#eff6ff">
    <div class="card-body">
        <p><i class="fas fa-check-circle" style="color:var(--primary)"></i> <strong>Submission received</strong> on <?= date('M j, Y g:i A', strtotime($latestSub['submitted_at'])) ?><?= $latestSub['is_late'] ? ' <span style="color:#dc2626">(Late)</span>' : '' ?>. Awaiting grading.</p>
    </div>
</div>
<?php endif; ?>

<!-- Submit Form -->
<?php if ($canSubmit): ?>
<?php
$minutesPast = $isPastDue && $assignment['due_date'] ? (int)round((strtotime($now) - strtotime($assignment['due_date'])) / 60) : 0;
$daysPast = $minutesPast > 0 ? ceil($minutesPast / 1440) : 0;
?>
<?php if ($isPastDue): ?>
<div class="card mb-3" style="border-color:#d97706;background:#fffbeb">
    <div class="card-body">
        <i class="fas fa-exclamation-triangle" style="color:#d97706"></i>
        <strong style="color:#d97706">Late Submission</strong> — You are submitting <?= $daysPast ?>
        day<?= $daysPast != 1 ? 's' : '' ?> after the deadline.
        <?php if ($assignment['late_penalty'] > 0): ?> A <?= $assignment['late_penalty'] * $daysPast ?>% penalty will be applied.<?php endif; ?>
    </div>
</div>
<?php endif; ?>
<div class="card mb-4">
    <div class="card-header">
        <h3><i class="fas fa-upload"></i> <?= $attemptsUsed > 0 ? 'Resubmit Assignment' : 'Submit Assignment' ?>
            <small class="text-muted">(Attempt <?= $attemptsUsed + 1 ?> of <?= $assignment['max_submissions'] ?>)</small>
        </h3>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label><i class="fas fa-paperclip"></i> Upload Files
                    <small class="text-muted">(Max <?= round($assignment['max_file_size']/1024/1024, 0) ?>MB each — Allowed: <?= sanitize($assignment['allowed_file_types']) ?>)</small>
                </label>
                <input type="file" name="submission_files[]" class="form-control" multiple accept=".<?= str_replace(',', ',.', $assignment['allowed_file_types']) ?>" required>
                <small class="text-muted">You can select multiple files at once.</small>
            </div>
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="fas fa-paper-plane"></i> <?= $attemptsUsed > 0 ? 'Resubmit' : 'Submit Assignment' ?>
            </button>
        </form>
    </div>
</div>
<?php elseif ($blockReason): ?>
<div class="card mb-4" style="border-color:var(--border)">
    <div class="card-body text-center text-muted py-4">
        <i class="fas fa-lock fa-2x mb-2"></i><br>
        <?= sanitize($blockReason) ?>
    </div>
</div>

<?php
// Show resubmit request section if student has a submission but can't submit
$showResubmitSection = $latestSub && !$canSubmit;
?>
<?php if ($showResubmitSection): ?>
<?php
$reqStatus  = (int)($latestSub['resubmit_requested'] ?? 0);
$reqApproved = (int)($latestSub['resubmit_approved'] ?? 0);
$reqDenyReason = $latestSub['resubmit_deny_reason'] ?? '';
$reqNote    = $latestSub['resubmit_note'] ?? '';
$reqAt      = $latestSub['resubmit_requested_at'] ?? '';
?>
<?php if ($reqApproved == 2): ?>
<!-- Denied -->
<div class="card mb-4" style="border-color:#dc2626;background:#fef2f2">
    <div class="card-body">
        <h4 style="color:#dc2626"><i class="fas fa-times-circle"></i> Resubmission Request Denied</h4>
        <?php if ($reqDenyReason): ?>
        <p style="margin:8px 0 0"><strong>Reason:</strong> <?= nl2br(sanitize($reqDenyReason)) ?></p>
        <?php else: ?><p class="text-muted" style="margin:8px 0 0">No reason provided.</p><?php endif; ?>
        <div style="margin-top:12px">
            <button class="btn btn-sm btn-secondary" onclick="document.getElementById('resubmit-req-form').style.display='block';this.style.display='none'">
                <i class="fas fa-redo"></i> Send Another Request
            </button>
            <div id="resubmit-req-form" style="display:none;margin-top:12px">
                <form method="POST">
                    <input type="hidden" name="action" value="request_resubmit">
                    <div class="form-group">
                        <label><strong>Reason for Resubmission Request</strong></label>
                        <textarea name="resubmit_note" class="form-control" rows="3" placeholder="Explain why you need to resubmit..." required></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Send Request</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php elseif ($reqStatus == 1 && $reqApproved == 0): ?>
<!-- Pending -->
<div class="card mb-4" style="border-color:#d97706;background:#fffbeb">
    <div class="card-body">
        <h4 style="color:#92400e"><i class="fas fa-hourglass-half"></i> Resubmission Request Pending</h4>
        <p>Your request has been sent and is awaiting instructor approval.</p>
        <?php if ($reqNote): ?>
        <p style="margin:0;font-size:13px;color:var(--secondary)"><strong>Your note:</strong> <?= sanitize($reqNote) ?></p>
        <?php endif; ?>
        <?php if ($reqAt): ?>
        <p style="margin:4px 0 0;font-size:12px;color:var(--secondary)"><i class="fas fa-clock"></i> Sent <?= date('M j, Y g:i A', strtotime($reqAt)) ?></p>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<!-- No request yet -->
<div class="card mb-4" style="border-color:var(--primary)">
    <div class="card-header">
        <h3><i class="fas fa-redo" style="color:var(--primary)"></i> Request Resubmission</h3>
    </div>
    <div class="card-body">
        <p class="text-muted">You've reached your submission limit or the deadline has passed. You can request your instructor to allow a resubmission.</p>
        <form method="POST">
            <input type="hidden" name="action" value="request_resubmit">
            <div class="form-group">
                <label><strong>Reason</strong> <small class="text-muted">(required)</small></label>
                <textarea name="resubmit_note" class="form-control" rows="3" placeholder="e.g. I encountered a technical issue, or I need to correct my work..." required></textarea>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-paper-plane"></i> Send Resubmission Request
            </button>
        </form>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>
<?php endif; /* canSubmit / blockReason */ ?>

<!-- Submission History -->
<?php if (!empty($history)): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-history"></i> Submission History</h3></div>
    <div class="card-body p-0">
        <table class="table">
            <thead>
                <tr><th>Attempt</th><th>Submitted</th><th>Files</th><th>Status</th><th>Score</th><th>Feedback</th></tr>
            </thead>
            <tbody>
            <?php foreach ($history as $h): ?>
            <tr>
                <td><span class="qtype-badge" style="background:#dbeafe;color:#1d4ed8">#<?= $h['attempt_number'] ?></span>
                    <?php if ($h['is_late']): ?><br><span style="color:#dc2626;font-size:11px"><i class="fas fa-clock"></i> Late</span><?php endif; ?>
                </td>
                <td><?= date('M j, Y', strtotime($h['submitted_at'])) ?><br><small><?= date('g:i A', strtotime($h['submitted_at'])) ?></small></td>
                <td>
                    <?php if ($h['filenames']): ?>
                    <?php
                    $fnames = explode('|||', $h['filenames']);
                    $fids   = explode(',', $h['file_ids']);
                    foreach ($fnames as $fi => $fname): ?>
                    <a href="<?= BASE_URL ?>/student/download_submission.php?file_id=<?= $fids[$fi] ?>" class="btn btn-xs btn-secondary" style="display:block;margin-bottom:2px">
                        <i class="fas fa-download"></i> <?= sanitize($fname) ?>
                    </a>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($h['status'] === 'graded'): ?><span class="badge badge-success">Graded</span>
                    <?php elseif ($h['status'] === 'returned'): ?><span class="badge badge-warning">Returned</span>
                    <?php else: ?><span class="badge badge-info">Submitted</span><?php endif; ?>
                </td>
                <td>
                    <?php if ($h['status'] === 'graded' && $h['final_score'] !== null): ?>
                    <strong><?= number_format($h['final_score'], 1) ?></strong> / <?= number_format($assignment['max_score'], 0) ?>
                    <?php if ($h['late_penalty_applied'] > 0): ?>
                    <br><small style="color:#d97706">-<?= $h['late_penalty_applied'] ?>% late</small>
                    <?php endif; ?>
                    <?php else: ?>–<?php endif; ?>
                </td>
                <td><?= $h['feedback'] ? nl2br(sanitize($h['feedback'])) : '–' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>

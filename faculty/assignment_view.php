<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty');

$assignmentId = (int)($_GET['assignment_id'] ?? 0);
if (!$assignmentId) redirect(BASE_URL . '/faculty/sections.php');

// Load assignment, verify faculty owns section
$aStmt = $pdo->prepare("SELECT a.*, s.faculty_id, s.id as section_id, c.course_code, c.course_name, s.section_name
    FROM assignments a
    JOIN sections s ON a.section_id=s.id
    JOIN courses c ON s.course_id=c.id
    WHERE a.id=? AND s.faculty_id=?");
$aStmt->execute([$assignmentId, $_SESSION['user_id']]);
$assignment = $aStmt->fetch();
if (!$assignment) redirect(BASE_URL . '/faculty/sections.php');
$sectionId = $assignment['section_id'];

// Handle grading POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'grade') {
        $subId    = (int)($_POST['submission_id'] ?? 0);
        $score    = max(0, min($assignment['max_score'], (float)($_POST['score'] ?? 0)));
        $feedback = trim($_POST['feedback'] ?? '');
        $status   = $_POST['status'] ?? 'graded';

        // Compute final score with late penalty
        $subQ = $pdo->prepare("SELECT * FROM assignment_submissions WHERE id=? AND assignment_id=?");
        $subQ->execute([$subId, $assignmentId]);
        $sub = $subQ->fetch();
        if ($sub) {
            $penalty   = $sub['is_late'] ? $assignment['late_penalty'] : 0;
            $daysLate  = $sub['minutes_late'] > 0 ? ceil($sub['minutes_late'] / 1440) : 0;
            $totalPenalty = min(100, $penalty * $daysLate);
            $finalScore = $score * (1 - $totalPenalty / 100);
            $pdo->prepare("UPDATE assignment_submissions SET score=?, late_penalty_applied=?, final_score=?, feedback=?, status=?, graded_by=?, graded_at=NOW() WHERE id=? AND assignment_id=?")
                ->execute([$score, $totalPenalty, round($finalScore, 2), $feedback, $status, $_SESSION['user_id'], $subId, $assignmentId]);
            flashMessage('success', 'Submission graded.');
        }
        redirect(BASE_URL . '/faculty/assignment_view.php?assignment_id=' . $assignmentId);
    }
    
    if ($action === 'return') {
        $subId = (int)($_POST['submission_id'] ?? 0);
        $feedback = trim($_POST['feedback'] ?? '');
        $pdo->prepare("UPDATE assignment_submissions SET status='returned', feedback=?, graded_by=?, graded_at=NOW() WHERE id=? AND assignment_id=?")
            ->execute([$feedback, $_SESSION['user_id'], $subId, $assignmentId]);
        flashMessage('info', 'Submission returned for revision.');
        redirect(BASE_URL . '/faculty/assignment_view.php?assignment_id=' . $assignmentId);
    }

    // Re-run plagiarism check on all submissions for this assignment
    if ($action === 'run_plagiarism') {
        $allSubsQ = $pdo->prepare("SELECT id, student_id FROM assignment_submissions WHERE assignment_id=?");
        $allSubsQ->execute([$assignmentId]);
        $allSubs = $allSubsQ->fetchAll();

        // Reset all flags first
        $pdo->prepare("UPDATE assignment_submissions SET plagiarism_flag=0, plagiarism_similarity=0, plagiarism_matched_id=NULL WHERE assignment_id=?")
            ->execute([$assignmentId]);

        $textExts = ['txt','py','java','cpp','c','js','ts','html','php','cs','rb','go','md','json','xml','css','sh'];

        // Compare every pair of submissions
        for ($i = 0; $i < count($allSubs); $i++) {
            for ($j = $i + 1; $j < count($allSubs); $j++) {
                $subA = $allSubs[$i];
                $subB = $allSubs[$j];

                $fA = $pdo->prepare("SELECT * FROM submission_files WHERE submission_id=?");
                $fA->execute([$subA['id']]);
                $filesA = $fA->fetchAll();

                $fB = $pdo->prepare("SELECT * FROM submission_files WHERE submission_id=?");
                $fB->execute([$subB['id']]);
                $filesB = $fB->fetchAll();

                if (empty($filesA) || empty($filesB)) continue;

                // Exact hash match
                $hashesA = array_filter(array_column($filesA, 'file_hash'));
                $hashesB = array_filter(array_column($filesB, 'file_hash'));
                if (!empty($hashesA) && !empty($hashesB) && !empty(array_intersect($hashesA, $hashesB))) {
                    $sim = 100.0;
                } else {
                    // Jaccard on text/code files
                    $totalSim = 0; $comps = 0;
                    foreach ($filesA as $fa) {
                        $extA = strtolower(pathinfo($fa['file_path'] ?? '', PATHINFO_EXTENSION));
                        if (!in_array($extA, $textExts) || !file_exists($fa['file_path'])) continue;
                        $textA = @file_get_contents($fa['file_path']);
                        if (!$textA || strlen($textA) < 50) continue;
                        $wA = array_unique(array_filter(preg_split('/\W+/', strtolower($textA)), fn($w) => strlen($w) > 3));
                        foreach ($filesB as $fb) {
                            $extB = strtolower(pathinfo($fb['file_path'] ?? '', PATHINFO_EXTENSION));
                            if ($extB !== $extA || !file_exists($fb['file_path'])) continue;
                            $textB = @file_get_contents($fb['file_path']);
                            if (!$textB || strlen($textB) < 50) continue;
                            $wB = array_unique(array_filter(preg_split('/\W+/', strtolower($textB)), fn($w) => strlen($w) > 3));
                            $union = count(array_unique(array_merge(array_values($wA), array_values($wB))));
                            if ($union > 0) {
                                $totalSim += (count(array_intersect($wA, $wB)) / $union) * 100;
                                $comps++;
                            }
                        }
                    }
                    $sim = $comps > 0 ? ($totalSim / $comps) : 0.0;
                }

                // Update both submissions if similarity is notable
                if ($sim > 0) {
                    $simRound = round($sim, 2);
                    $flag = $sim >= 70 ? 1 : 0;

                    // Only update if this pair's similarity is higher than what's already stored
                    $curA = $pdo->prepare("SELECT plagiarism_similarity FROM assignment_submissions WHERE id=?");
                    $curA->execute([$subA['id']]); $curSimA = (float)$curA->fetchColumn();
                    $curB = $pdo->prepare("SELECT plagiarism_similarity FROM assignment_submissions WHERE id=?");
                    $curB->execute([$subB['id']]); $curSimB = (float)$curB->fetchColumn();

                    if ($simRound > $curSimA) {
                        $pdo->prepare("UPDATE assignment_submissions SET plagiarism_flag=?, plagiarism_similarity=?, plagiarism_matched_id=? WHERE id=?")
                            ->execute([$flag, $simRound, $subB['id'], $subA['id']]);
                    }
                    if ($simRound > $curSimB) {
                        $pdo->prepare("UPDATE assignment_submissions SET plagiarism_flag=?, plagiarism_similarity=?, plagiarism_matched_id=? WHERE id=?")
                            ->execute([$flag, $simRound, $subA['id'], $subB['id']]);
                    }
                }
            }
        }
        flashMessage('success', 'Plagiarism check complete. All submissions have been analyzed.');
        redirect(BASE_URL . '/faculty/assignment_view.php?assignment_id=' . $assignmentId . '&tab=plagiarism');
    }

    // Run plagiarism check for ONE student against all others
    if ($action === 'run_plagiarism_one') {
        $targetSubId = (int)($_POST['submission_id'] ?? 0);
        if ($targetSubId) {
            // Verify submission belongs to this assignment
            $chkQ = $pdo->prepare("SELECT * FROM submission_files WHERE submission_id=?");
            $chkQ->execute([$targetSubId]);
            $targetFiles = $chkQ->fetchAll();

            // Reset only this submission's plagiarism data
            $pdo->prepare("UPDATE assignment_submissions SET plagiarism_flag=0, plagiarism_similarity=0, plagiarism_matched_id=NULL WHERE id=? AND assignment_id=?")
                ->execute([$targetSubId, $assignmentId]);

            // Get all other submissions
            $othersQ = $pdo->prepare("SELECT id FROM assignment_submissions WHERE assignment_id=? AND id != ?");
            $othersQ->execute([$assignmentId, $targetSubId]);
            $others = $othersQ->fetchAll();

            $textExts = ['txt','py','java','cpp','c','js','ts','html','php','cs','rb','go','md','json','xml','css','sh'];
            $bestSim = 0.0; $bestMatchId = null;

            foreach ($others as $other) {
                $otherFilesQ = $pdo->prepare("SELECT * FROM submission_files WHERE submission_id=?");
                $otherFilesQ->execute([$other['id']]);
                $otherFiles = $otherFilesQ->fetchAll();
                if (empty($targetFiles) || empty($otherFiles)) continue;

                // Exact hash match
                $hashesTarget = array_filter(array_column($targetFiles, 'file_hash'));
                $hashesOther  = array_filter(array_column($otherFiles, 'file_hash'));
                if (!empty($hashesTarget) && !empty($hashesOther) && !empty(array_intersect($hashesTarget, $hashesOther))) {
                    $sim = 100.0;
                } else {
                    $totalSim = 0; $comps = 0;
                    foreach ($targetFiles as $fa) {
                        $extA = strtolower(pathinfo($fa['file_path'] ?? '', PATHINFO_EXTENSION));
                        if (!in_array($extA, $textExts) || !file_exists($fa['file_path'])) continue;
                        $textA = @file_get_contents($fa['file_path']);
                        if (!$textA || strlen($textA) < 50) continue;
                        $wA = array_unique(array_filter(preg_split('/\W+/', strtolower($textA)), fn($w) => strlen($w) > 3));
                        foreach ($otherFiles as $fb) {
                            $extB = strtolower(pathinfo($fb['file_path'] ?? '', PATHINFO_EXTENSION));
                            if ($extB !== $extA || !file_exists($fb['file_path'])) continue;
                            $textB = @file_get_contents($fb['file_path']);
                            if (!$textB || strlen($textB) < 50) continue;
                            $wB = array_unique(array_filter(preg_split('/\W+/', strtolower($textB)), fn($w) => strlen($w) > 3));
                            $union = count(array_unique(array_merge(array_values($wA), array_values($wB))));
                            if ($union > 0) {
                                $totalSim += (count(array_intersect($wA, $wB)) / $union) * 100;
                                $comps++;
                            }
                        }
                    }
                    $sim = $comps > 0 ? ($totalSim / $comps) : 0.0;
                }

                if ($sim > $bestSim) {
                    $bestSim = $sim;
                    $bestMatchId = $other['id'];
                }

                // Also update the other submission if this pair is higher than what they have
                if ($sim > 0) {
                    $simRound = round($sim, 2);
                    $curOther = $pdo->prepare("SELECT plagiarism_similarity FROM assignment_submissions WHERE id=?");
                    $curOther->execute([$other['id']]); $curSimOther = (float)$curOther->fetchColumn();
                    if ($simRound > $curSimOther) {
                        $pdo->prepare("UPDATE assignment_submissions SET plagiarism_flag=?, plagiarism_similarity=?, plagiarism_matched_id=? WHERE id=?")
                            ->execute([$sim >= 70 ? 1 : 0, $simRound, $targetSubId, $other['id']]);
                    }
                }
            }

            // Update target submission
            $bestRound = round($bestSim, 2);
            $pdo->prepare("UPDATE assignment_submissions SET plagiarism_flag=?, plagiarism_similarity=?, plagiarism_matched_id=? WHERE id=? AND assignment_id=?")
                ->execute([$bestSim >= 70 ? 1 : 0, $bestRound, $bestMatchId, $targetSubId, $assignmentId]);

            if ($bestSim >= 70) {
                flashMessage('danger', 'Plagiarism check done — similarity of ' . number_format($bestSim, 1) . '% detected!');
            } elseif ($bestSim >= 40) {
                flashMessage('warning', 'Plagiarism check done — moderate similarity of ' . number_format($bestSim, 1) . '% found.');
            } else {
                flashMessage('success', 'Plagiarism check done — no significant similarity found (' . number_format($bestSim, 1) . '%).');
            }
        }
        redirect(BASE_URL . '/faculty/assignment_view.php?assignment_id=' . $assignmentId . '&tab=submissions');
    }

    // Approve a student's resubmission request
    if ($action === 'approve_resubmit') {
        $subId = (int)($_POST['submission_id'] ?? 0);
        if ($subId) {
            $pdo->prepare("UPDATE assignment_submissions SET resubmit_approved=1, resubmit_approved_by=?, resubmit_approved_at=NOW() WHERE id=? AND assignment_id=?")
                ->execute([$_SESSION['user_id'], $subId, $assignmentId]);
            flashMessage('success', 'Resubmission approved. The student may now resubmit.');
        }
        redirect(BASE_URL . '/faculty/assignment_view.php?assignment_id=' . $assignmentId . '&tab=submissions');
    }

    // Deny a student's resubmission request
    if ($action === 'deny_resubmit') {
        $subId = (int)($_POST['submission_id'] ?? 0);
        $denyReason = trim($_POST['deny_reason'] ?? '');
        if ($subId) {
            $pdo->prepare("UPDATE assignment_submissions SET resubmit_approved=2, resubmit_deny_reason=?, resubmit_approved_by=?, resubmit_approved_at=NOW() WHERE id=? AND assignment_id=?")
                ->execute([$denyReason, $_SESSION['user_id'], $subId, $assignmentId]);
            flashMessage('info', 'Resubmission request denied.');
        }
        redirect(BASE_URL . '/faculty/assignment_view.php?assignment_id=' . $assignmentId . '&tab=submissions');
    }
}

$now    = date('Y-m-d H:i:s');
$activeTab = $_GET['tab'] ?? 'submissions';

// Enrolled students with their LATEST submission (LEFT JOIN)
$studentsStmt = $pdo->prepare("
    SELECT u.id as student_id, CONCAT(u.last_name, ', ', u.first_name) as student_name, u.username,
        sub.id as submission_id, sub.attempt_number, sub.submitted_at, sub.is_late, sub.minutes_late,
        sub.status, sub.score, sub.final_score, sub.late_penalty_applied, sub.feedback,
        sub.plagiarism_flag, sub.plagiarism_similarity, sub.plagiarism_matched_id,
        sub.resubmit_requested, sub.resubmit_approved, sub.resubmit_note, sub.resubmit_deny_reason, sub.resubmit_requested_at
    FROM enrollments e
    JOIN users u ON e.student_id = u.id
    LEFT JOIN assignment_submissions sub ON sub.assignment_id = ?
        AND sub.student_id = u.id
        AND sub.attempt_number = (
            SELECT MAX(attempt_number) FROM assignment_submissions
            WHERE assignment_id = ? AND student_id = u.id
        )
    WHERE e.section_id = ? AND e.status = 'enrolled'
    ORDER BY u.last_name, u.first_name");
$studentsStmt->execute([$assignmentId, $assignmentId, $sectionId]);
$students = $studentsStmt->fetchAll();

// Stats
$totalEnrolled  = count($students);
$totalSubmitted = count(array_filter($students, fn($s) => $s['submission_id']));
$totalGraded    = count(array_filter($students, fn($s) => $s['status'] === 'graded'));
$totalLate      = count(array_filter($students, fn($s) => $s['is_late']));
$totalFlagged   = count(array_filter($students, fn($s) => $s['plagiarism_flag']));
$scores         = array_filter(array_column($students, 'final_score'), fn($v) => $v !== null);
$avgScore       = count($scores) > 0 ? array_sum($scores) / count($scores) : null;

// Plagiarism pairs (both sides of each flagged pair)
$plagPairsQ = $pdo->prepare("
    SELECT
        subA.id as sub_a_id, CONCAT(uA.last_name,', ',uA.first_name) as name_a, uA.username as uname_a,
        subB.id as sub_b_id, CONCAT(uB.last_name,', ',uB.first_name) as name_b, uB.username as uname_b,
        subA.plagiarism_similarity as similarity,
        subA.submitted_at as submitted_a, subB.submitted_at as submitted_b
    FROM assignment_submissions subA
    JOIN assignment_submissions subB ON subA.plagiarism_matched_id = subB.id
    JOIN users uA ON subA.student_id = uA.id
    JOIN users uB ON subB.student_id = uB.id
    WHERE subA.assignment_id = ? AND subA.plagiarism_similarity > 0
    AND subA.id < subB.id
    ORDER BY subA.plagiarism_similarity DESC");
$plagPairsQ->execute([$assignmentId]);
$plagPairs = $plagPairsQ->fetchAll();

$pageTitle = 'Submissions – ' . $assignment['title'];
include '../includes/header.php';
?>

<div class="page-header">
    <div>
        <a href="<?= BASE_URL ?>/faculty/assignments.php?section_id=<?= $sectionId ?>" class="btn btn-sm btn-secondary mb-2">
            <i class="fas fa-arrow-left"></i> Back to Assignments
        </a>
        <h1><i class="fas fa-tasks"></i> <?= sanitize($assignment['title']) ?></h1>
        <p class="text-muted">
            <?= sanitize($assignment['course_code']) ?> – <?= sanitize($assignment['section_name']) ?>
            <?php if ($assignment['due_date']): ?>
            &nbsp;|&nbsp; Due: <strong><?= date('M j, Y g:i A', strtotime($assignment['due_date'])) ?></strong>
            <?php if ($now > $assignment['due_date']): ?><span class="badge badge-danger">Closed</span><?php endif; ?>
            <?php endif; ?>
        </p>
    </div>
    <form method="POST">
        <input type="hidden" name="action" value="run_plagiarism">
        <button type="submit" class="btn btn-warning" onclick="return confirm('Run plagiarism check on all submissions? This compares every pair of submitted files.')">
            <i class="fas fa-search"></i> Run Plagiarism Check
        </button>
    </form>
</div>

<?= getFlash() ?>

<!-- Stats -->
<div class="stats-row mb-4">
    <div class="stat-card"><span class="stat-num"><?= $totalEnrolled ?></span><span class="stat-label">Enrolled</span></div>
    <div class="stat-card"><span class="stat-num"><?= $totalSubmitted ?></span><span class="stat-label">Submitted</span></div>
    <div class="stat-card"><span class="stat-num" style="color:var(--warning)"><?= $totalEnrolled - $totalSubmitted ?></span><span class="stat-label">Not Submitted</span></div>
    <div class="stat-card"><span class="stat-num" style="color:var(--success)"><?= $totalGraded ?></span><span class="stat-label">Graded</span></div>
    <div class="stat-card"><span class="stat-num" style="color:var(--danger)"><?= $totalLate ?></span><span class="stat-label">Late</span></div>
    <div class="stat-card"><span class="stat-num" style="color:#dc2626"><?= $totalFlagged ?></span><span class="stat-label">Plagiarism Flags</span></div>
    <?php if ($avgScore !== null): ?>
    <div class="stat-card"><span class="stat-num"><?= number_format($avgScore, 1) ?></span><span class="stat-label">Avg Score</span></div>
    <?php endif; ?>
</div>

<!-- Tab Nav -->
<div class="tabs mb-4">
    <a href="?assignment_id=<?= $assignmentId ?>&tab=submissions" class="tab <?= $activeTab === 'submissions' ? 'active' : '' ?>">
        <i class="fas fa-list-alt"></i> Submissions (<?= $totalSubmitted ?>)
    </a>
    <a href="?assignment_id=<?= $assignmentId ?>&tab=plagiarism" class="tab <?= $activeTab === 'plagiarism' ? 'active' : '' ?>">
        <i class="fas fa-search"></i> Plagiarism Report
        <?php if ($totalFlagged > 0): ?><span class="badge badge-danger"><?= $totalFlagged ?></span><?php endif; ?>
    </a>
</div>

<?php if ($activeTab === 'submissions'): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-list-alt"></i> Student Submissions</h3></div>
    <div class="card-body p-0">
        <table class="table">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th>Attempt</th>
                    <th>Files</th>
                    <th>Score</th>
                    <th>Plagiarism</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($students as $st): ?>
            <tr id="row-<?= $st['student_id'] ?>">
                <td>
                    <strong><?= sanitize($st['student_name']) ?></strong><br>
                    <small class="text-muted"><?= sanitize($st['username']) ?></small>
                    <?php if ($st['plagiarism_flag']): ?>
                    <br><span style="color:#dc2626;font-size:11px"><i class="fas fa-exclamation-triangle"></i> <?= number_format($st['plagiarism_similarity'], 1) ?>% similar</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!$st['submission_id']): ?>
                        <span class="badge badge-secondary">Not Submitted</span>
                    <?php elseif ($st['status'] === 'graded'): ?>
                        <span class="badge badge-success">Graded</span>
                    <?php elseif ($st['status'] === 'returned'): ?>
                        <span class="badge badge-warning">Returned</span>
                    <?php else: ?>
                        <span class="badge badge-info">Submitted</span>
                    <?php endif; ?>
                    <?php if ($st['is_late']): ?>
                    <br><span style="color:#dc2626;font-size:11px"><i class="fas fa-clock"></i> Late <?= $st['minutes_late'] >= 1440 ? ceil($st['minutes_late']/1440).'d' : ceil($st['minutes_late']/60).'h' ?></span>
                    <?php endif; ?>
                    <?php if ($st['resubmit_requested'] && $st['resubmit_approved'] == 0): ?>
                    <br><span style="background:#fef3c7;color:#92400e;font-size:11px;padding:2px 7px;border-radius:20px;display:inline-block;margin-top:3px"><i class="fas fa-redo"></i> Resubmit Requested</span>
                    <?php elseif ($st['resubmit_approved'] == 1): ?>
                    <br><span style="background:#dcfce7;color:#166534;font-size:11px;padding:2px 7px;border-radius:20px;display:inline-block;margin-top:3px"><i class="fas fa-check"></i> Resubmit Approved</span>
                    <?php elseif ($st['resubmit_approved'] == 2): ?>
                    <br><span style="background:#fee2e2;color:#991b1b;font-size:11px;padding:2px 7px;border-radius:20px;display:inline-block;margin-top:3px"><i class="fas fa-times"></i> Resubmit Denied</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($st['submitted_at']): ?>
                    <?= date('M j, Y', strtotime($st['submitted_at'])) ?><br>
                    <small class="text-muted"><?= date('g:i A', strtotime($st['submitted_at'])) ?></small>
                    <?php else: ?><span class="text-muted">–</span><?php endif; ?>
                </td>
                <td>
                    <?php if ($st['attempt_number']): ?>
                    <span class="qtype-badge" style="background:#dbeafe;color:#1d4ed8">#<?= $st['attempt_number'] ?></span>
                    <?php else: ?><span class="text-muted">–</span><?php endif; ?>
                </td>
                <td>
                    <?php if ($st['submission_id']): ?>
                    <?php
                        $filesQ = $pdo->prepare("SELECT * FROM submission_files WHERE submission_id=?");
                        $filesQ->execute([$st['submission_id']]);
                        $files = $filesQ->fetchAll();
                    ?>
                    <?php foreach ($files as $fi): ?>
                    <a href="<?= BASE_URL ?>/faculty/download_submission.php?file_id=<?= $fi['id'] ?>" class="btn btn-xs btn-secondary" title="<?= sanitize($fi['original_filename']) ?>" style="display:block;margin-bottom:2px;max-width:160px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis">
                        <i class="fas fa-download"></i> <?= sanitize($fi['original_filename']) ?>
                    </a>
                    <?php endforeach; ?>
                    <?php else: ?><span class="text-muted">–</span><?php endif; ?>
                </td>
                <td>
                    <?php if ($st['status'] === 'graded' && $st['final_score'] !== null): ?>
                    <span class="<?= ($st['final_score'] / $assignment['max_score'] * 100) >= $assignment['passing_score'] ? 'result-correct' : 'result-wrong' ?>">
                        <strong><?= number_format($st['final_score'], 1) ?></strong>/<strong><?= number_format($assignment['max_score'], 0) ?></strong>
                    </span>
                    <?php if ($st['late_penalty_applied'] > 0): ?>
                    <br><small style="color:#d97706">-<?= $st['late_penalty_applied'] ?>% late</small>
                    <?php endif; ?>
                    <?php else: ?><span class="text-muted">–</span><?php endif; ?>
                </td>
                <td>
                    <?php if ($st['submission_id']): ?>
                    <?php
                        $pSim = (float)($st['plagiarism_similarity'] ?? 0);
                        $pFlag = (bool)($st['plagiarism_flag'] ?? false);
                        $pChecked = isset($st['plagiarism_similarity']) && $st['plagiarism_similarity'] !== null;
                        $pCol = $pSim >= 70 ? '#dc2626' : ($pSim >= 40 ? '#d97706' : '#16a34a');
                    ?>
                    <?php if ($pSim > 0): ?>
                    <div style="margin-bottom:6px">
                        <div class="plag-bar-wrap" style="width:90px">
                            <div class="plag-bar" style="width:<?= min(100,$pSim) ?>%;background:<?= $pCol ?>"></div>
                        </div>
                        <span style="color:<?= $pCol ?>;font-weight:700;font-size:13px"><?= number_format($pSim,1) ?>%</span>
                        <?php if ($pFlag): ?>
                        <span class="badge badge-danger" style="font-size:10px;display:block;margin-top:2px"><i class="fas fa-exclamation-triangle"></i> FLAGGED</span>
                        <?php endif; ?>
                        <?php if ($st['plagiarism_matched_id']): ?>
                        <a href="?assignment_id=<?= $assignmentId ?>&tab=plagiarism" style="font-size:11px;display:block;margin-top:2px"><i class="fas fa-eye"></i> View Report</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="action" value="run_plagiarism_one">
                        <input type="hidden" name="submission_id" value="<?= $st['submission_id'] ?>">
                        <button type="submit" class="btn btn-xs" style="background:#f3f4f6;border:1px solid var(--border);color:var(--text)" title="Run plagiarism check for this student">
                            <i class="fas fa-search"></i> <?= $pSim > 0 ? 'Re-check' : 'Check' ?>
                        </button>
                    </form>
                    <?php else: ?>
                    <span class="text-muted">–</span>
                    <?php endif; ?>
                </td>
                <td style="min-width:150px">
                    <?php if ($st['submission_id']): ?>
                    <button class="btn btn-sm btn-primary mb-1" onclick="toggleGradeForm(<?= $st['student_id'] ?>)">
                        <i class="fas fa-pen"></i> <?= $st['status'] === 'graded' ? 'Re-grade' : 'Grade' ?>
                    </button>
                    <?php if ($st['resubmit_requested'] && $st['resubmit_approved'] == 0): ?>
                    <div style="margin-top:6px;padding:8px;background:#fffbeb;border:1px solid #fcd34d;border-radius:var(--radius);font-size:12px">
                        <strong style="color:#92400e"><i class="fas fa-redo"></i> Resubmission Request</strong>
                        <?php if ($st['resubmit_note']): ?>
                        <p style="margin:4px 0 6px;color:#78350f"><em><?= sanitize($st['resubmit_note']) ?></em></p>
                        <?php endif; ?>
                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="approve_resubmit">
                                <input type="hidden" name="submission_id" value="<?= $st['submission_id'] ?>">
                                <button type="submit" class="btn btn-xs" style="background:#16a34a;color:white" onclick="return confirm('Approve resubmission for <?= sanitize($st['student_name']) ?>?')">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                            </form>
                            <button class="btn btn-xs" style="background:#dc2626;color:white" onclick="toggleDenyForm(<?= $st['student_id'] ?>)">
                                <i class="fas fa-times"></i> Deny
                            </button>
                        </div>
                        <div id="deny-form-<?= $st['student_id'] ?>" style="display:none;margin-top:8px">
                            <form method="POST">
                                <input type="hidden" name="action" value="deny_resubmit">
                                <input type="hidden" name="submission_id" value="<?= $st['submission_id'] ?>">
                                <input type="text" name="deny_reason" class="form-control" style="font-size:12px;margin-bottom:4px" placeholder="Reason (optional)">
                                <button type="submit" class="btn btn-xs" style="background:#dc2626;color:white"><i class="fas fa-times"></i> Confirm Deny</button>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if ($st['submission_id']): ?>
            <!-- Grade Form Row -->
            <tr id="grade-form-<?= $st['student_id'] ?>" style="display:none;background:#f8fafc">
                <td colspan="9">
                    <form method="POST" style="padding:16px">
                        <input type="hidden" name="action" value="grade">
                        <input type="hidden" name="submission_id" value="<?= $st['submission_id'] ?>">
                        <div class="form-row align-items-end">
                            <div class="form-group col-2">
                                <label><strong>Score</strong> (max <?= number_format($assignment['max_score'],0) ?>)</label>
                                <input type="number" name="score" class="form-control" value="<?= $st['score'] ?? '' ?>" min="0" max="<?= $assignment['max_score'] ?>" step="0.5" required placeholder="0">
                                <?php if ($assignment['allow_late'] && $st['is_late'] && $assignment['late_penalty'] > 0): ?>
                                <small style="color:#d97706">Late penalty: <?= $assignment['late_penalty'] ?>%/day applied automatically</small>
                                <?php endif; ?>
                            </div>
                            <div class="form-group col-2">
                                <label>Mark As</label>
                                <select name="status" class="form-control">
                                    <option value="graded" <?= $st['status']==='graded'?'selected':'' ?>>Graded</option>
                                    <option value="returned" <?= $st['status']==='returned'?'selected':'' ?>>Return for Revision</option>
                                </select>
                            </div>
                            <div class="form-group col-6">
                                <label>Feedback</label>
                                <textarea name="feedback" class="form-control" rows="2" placeholder="Feedback for student..."><?= sanitize($st['feedback'] ?? '') ?></textarea>
                            </div>
                            <div class="form-group col-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-success" style="width:100%"><i class="fas fa-save"></i> Save Grade</button>
                            </div>
                        </div>
                    </form>
                </td>
            </tr>
            <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; /* submissions tab */ ?>

<?php if ($activeTab === 'plagiarism'): ?>
<?php
// Helper: load file content for text-based files
function getFileText(string $path): ?string {
    $textExts = ['txt','py','java','cpp','c','js','ts','html','php','cs','rb','go','md','json','xml','css','sh'];
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, $textExts) || !file_exists($path)) return null;
    $content = @file_get_contents($path);
    return ($content && strlen($content) >= 20) ? $content : null;
}

// Highlight common words between two texts
function highlightShared(string $text, array $shared): string {
    foreach ($shared as $word) {
        $text = preg_replace('/\b(' . preg_quote($word, '/') . ')\b/i', '<mark>$1</mark>', $text);
    }
    return htmlspecialchars_decode(strip_tags(htmlspecialchars($text, ENT_QUOTES), '<mark>'));
}
?>

<div class="card mb-4" style="border-left:4px solid #dc2626">
    <div class="card-body">
        <h3 style="margin:0 0 8px"><i class="fas fa-search" style="color:#dc2626"></i> Plagiarism Analysis Report</h3>
        <p class="text-muted" style="margin:0">Similarity is calculated using SHA-256 hash comparison (exact copies) and Jaccard word-overlap for text and code files. Pairs above 70% are flagged.</p>
        <div style="margin-top:12px;display:flex;gap:20px;flex-wrap:wrap;font-size:13px">
            <span><span class="plag-dot" style="background:#dc2626"></span> 70–100%: High – Likely copied</span>
            <span><span class="plag-dot" style="background:#d97706"></span> 40–69%: Medium – Similar content</span>
            <span><span class="plag-dot" style="background:#16a34a"></span> &lt;40%: Low – Minor overlap</span>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
        <h3><i class="fas fa-exclamation-triangle" style="color:#dc2626"></i> Similarity Overview</h3>
        <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="run_plagiarism">
            <button type="submit" class="btn btn-sm btn-warning" onclick="return confirm('Re-run plagiarism analysis on all submissions?')">
                <i class="fas fa-sync"></i> Re-run Check
            </button>
        </form>
    </div>
    <div class="card-body p-0">
        <?php if (empty($plagPairs)): ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-check-circle fa-2x mb-2" style="color:var(--success)"></i>
            <p>No similarities detected yet. Click "Run Plagiarism Check" to analyze all submissions.</p>
        </div>
        <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Student A</th>
                    <th>Student B</th>
                    <th>Similarity</th>
                    <th>Severity</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($plagPairs as $pair): ?>
            <?php
                $sim = (float)$pair['similarity'];
                $severity = $sim >= 70 ? 'high' : ($sim >= 40 ? 'medium' : 'low');
                $sevColor  = $sim >= 70 ? '#dc2626' : ($sim >= 40 ? '#d97706' : '#16a34a');
                $sevLabel  = $sim >= 70 ? 'High' : ($sim >= 40 ? 'Medium' : 'Low');
            ?>
            <tr>
                <td>
                    <strong><?= sanitize($pair['name_a']) ?></strong><br>
                    <small class="text-muted"><?= sanitize($pair['uname_a']) ?></small><br>
                    <small><?= $pair['submitted_a'] ? date('M j g:i A', strtotime($pair['submitted_a'])) : '–' ?></small>
                </td>
                <td>
                    <strong><?= sanitize($pair['name_b']) ?></strong><br>
                    <small class="text-muted"><?= sanitize($pair['uname_b']) ?></small><br>
                    <small><?= $pair['submitted_b'] ? date('M j g:i A', strtotime($pair['submitted_b'])) : '–' ?></small>
                </td>
                <td>
                    <div class="plag-bar-wrap">
                        <div class="plag-bar" style="width:<?= min(100, $sim) ?>%;background:<?= $sevColor ?>"></div>
                    </div>
                    <strong style="color:<?= $sevColor ?>;font-size:18px"><?= number_format($sim, 1) ?>%</strong>
                </td>
                <td><span class="badge" style="background:<?= $sevColor ?>;color:white;font-size:12px"><?= $sevLabel ?></span></td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="toggleCompare('compare-<?= $pair['sub_a_id'] ?>-<?= $pair['sub_b_id'] ?>')">
                        <i class="fas fa-columns"></i> Compare
                    </button>
                </td>
            </tr>
            <!-- Side-by-side comparison row -->
            <tr id="compare-<?= $pair['sub_a_id'] ?>-<?= $pair['sub_b_id'] ?>" style="display:none;background:#fafafa">
                <td colspan="5" style="padding:0">
                <?php
                    // Load text files for both submissions
                    $fqA = $pdo->prepare("SELECT * FROM submission_files WHERE submission_id=?");
                    $fqA->execute([$pair['sub_a_id']]);
                    $fqAFiles = $fqA->fetchAll();

                    $fqB = $pdo->prepare("SELECT * FROM submission_files WHERE submission_id=?");
                    $fqB->execute([$pair['sub_b_id']]);
                    $fqBFiles = $fqB->fetchAll();

                    // Find first readable text file pair
                    $textA = null; $textB = null; $fnameA = ''; $fnameB = '';
                    foreach ($fqAFiles as $fa) {
                        $t = getFileText($fa['file_path']);
                        if ($t !== null) { $textA = $t; $fnameA = $fa['original_filename']; break; }
                    }
                    foreach ($fqBFiles as $fb) {
                        $t = getFileText($fb['file_path']);
                        if ($t !== null) { $textB = $t; $fnameB = $fb['original_filename']; break; }
                    }

                    // Find shared words for highlighting
                    $sharedWords = [];
                    if ($textA && $textB) {
                        $wA = array_unique(array_filter(preg_split('/\W+/', strtolower($textA)), fn($w) => strlen($w) > 4));
                        $wB = array_unique(array_filter(preg_split('/\W+/', strtolower($textB)), fn($w) => strlen($w) > 4));
                        $sharedWords = array_intersect($wA, $wB);
                    }
                ?>
                <div style="padding:16px">
                    <?php if ($textA || $textB): ?>
                    <div class="plag-compare-grid">
                        <div class="plag-compare-pane">
                            <div class="plag-compare-header" style="background:<?= $sevColor ?>22;border-bottom:2px solid <?= $sevColor ?>">
                                <strong><?= sanitize($pair['name_a']) ?></strong>
                                <span class="text-muted" style="font-size:12px"><?= sanitize($fnameA) ?></span>
                                <a href="<?= BASE_URL ?>/faculty/download_submission.php?file_id=<?= $fqAFiles[0]['id'] ?? 0 ?>" class="btn btn-xs btn-secondary"><i class="fas fa-download"></i></a>
                            </div>
                            <pre class="plag-code"><?= !empty($sharedWords) ? highlightShared($textA ?? '', $sharedWords) : htmlspecialchars($textA ?? '(No readable text file)') ?></pre>
                        </div>
                        <div class="plag-compare-pane">
                            <div class="plag-compare-header" style="background:<?= $sevColor ?>22;border-bottom:2px solid <?= $sevColor ?>">
                                <strong><?= sanitize($pair['name_b']) ?></strong>
                                <span class="text-muted" style="font-size:12px"><?= sanitize($fnameB) ?></span>
                                <a href="<?= BASE_URL ?>/faculty/download_submission.php?file_id=<?= $fqBFiles[0]['id'] ?? 0 ?>" class="btn btn-xs btn-secondary"><i class="fas fa-download"></i></a>
                            </div>
                            <pre class="plag-code"><?= !empty($sharedWords) ? highlightShared($textB ?? '', $sharedWords) : htmlspecialchars($textB ?? '(No readable text file)') ?></pre>
                        </div>
                    </div>
                    <?php if (!empty($sharedWords)): ?>
                    <div style="margin-top:8px;font-size:12px;color:var(--secondary)">
                        <i class="fas fa-info-circle"></i> <mark style="background:#fef08a;padding:1px 4px">Highlighted words</mark> are shared between both submissions (<?= count($sharedWords) ?> common words).
                    </div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="text-muted text-center py-3">
                        <i class="fas fa-file-archive"></i> No readable text content to compare inline.
                        Download the files above to compare manually.
                    </div>
                    <?php endif; ?>
                </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- All submissions similarity summary -->
<?php
$allSims = array_filter(array_map(fn($s) => $s['plagiarism_similarity'] > 0 ? $s : null, $students));
if (!empty($allSims)):
?>
<div class="card mb-4">
    <div class="card-header"><h3><i class="fas fa-chart-bar"></i> Per-Student Similarity Summary</h3></div>
    <div class="card-body p-0">
        <table class="table">
            <thead><tr><th>Student</th><th>Similarity Score</th><th>Compared With</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($students as $st):
                if (!$st['submission_id'] || $st['plagiarism_similarity'] <= 0) continue;
                $sim = (float)$st['plagiarism_similarity'];
                $col = $sim >= 70 ? '#dc2626' : ($sim >= 40 ? '#d97706' : '#16a34a');
                $matchQ = $pdo->prepare("SELECT CONCAT(u.last_name,', ',u.first_name) as name FROM assignment_submissions sub JOIN users u ON sub.student_id=u.id WHERE sub.id=?");
                $matchQ->execute([$st['plagiarism_matched_id']]);
                $matchName = $matchQ->fetchColumn();
            ?>
            <tr>
                <td><strong><?= sanitize($st['student_name']) ?></strong></td>
                <td>
                    <div class="plag-bar-wrap">
                        <div class="plag-bar" style="width:<?= min(100,$sim) ?>%;background:<?= $col ?>"></div>
                    </div>
                    <span style="color:<?= $col ?>;font-weight:700"><?= number_format($sim,1) ?>%</span>
                    <?php if ($st['plagiarism_flag']): ?>
                    <span class="badge badge-danger" style="font-size:10px">FLAGGED</span>
                    <?php endif; ?>
                </td>
                <td><?= $matchName ? sanitize($matchName) : '–' ?></td>
                <td>
                    <?php if ($st['plagiarism_matched_id']): ?>
                    <?php $otherSubQ = $pdo->prepare("SELECT id FROM assignment_submissions WHERE id=?"); $otherSubQ->execute([$st['plagiarism_matched_id']]); $otherSub = $otherSubQ->fetch(); ?>
                    <a href="?assignment_id=<?= $assignmentId ?>&tab=plagiarism" class="btn btn-xs btn-secondary"><i class="fas fa-search"></i> See Report</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php endif; /* plagiarism tab */ ?>

<script>
function toggleGradeForm(studentId) {
    const row = document.getElementById('grade-form-' + studentId);
    row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
}
function toggleCompare(id) {
    const row = document.getElementById(id);
    row.style.display = row.style.display === 'none' ? 'table-row' : 'none';
}
function toggleDenyForm(studentId) {
    const el = document.getElementById('deny-form-' + studentId);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
</script>

<?php include '../includes/footer.php'; ?>

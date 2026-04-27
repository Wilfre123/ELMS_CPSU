<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('student');

$sectionId = (int)($_GET['id'] ?? 0);
if (!$sectionId) redirect(BASE_URL . '/student/courses.php');

// Verify enrollment
$enroll = $pdo->prepare("SELECT * FROM enrollments WHERE section_id=? AND student_id=? AND status='enrolled'");
$enroll->execute([$sectionId, $_SESSION['user_id']]);
if (!$enroll->fetch()) redirect(BASE_URL . '/student/courses.php');

$section = $pdo->prepare("SELECT s.*, c.course_code, c.course_name, c.description as course_desc, c.units,
    u.first_name as faculty_first, u.last_name as faculty_last
    FROM sections s JOIN courses c ON s.course_id=c.id JOIN users u ON s.faculty_id=u.id WHERE s.id=?");
$section->execute([$sectionId]);
$section = $section->fetch();

$pageTitle = $section['course_code'] . ' - ' . $section['section_name'];
$tab = $_GET['tab'] ?? 'modules';

// Fetch visible modules with visible materials
$modules = $pdo->prepare("SELECT * FROM modules WHERE section_id=? AND is_visible=1 ORDER BY order_index, id");
$modules->execute([$sectionId]);
$modules = $modules->fetchAll();

foreach ($modules as &$mod) {
    $matStmt = $pdo->prepare("SELECT * FROM materials WHERE module_id=? AND is_visible=1 ORDER BY order_index, id");
    $matStmt->execute([$mod['id']]);
    $mod['materials'] = $matStmt->fetchAll();
}
unset($mod);

// Announcements
$announcements = $pdo->prepare("SELECT a.*, u.first_name, u.last_name FROM announcements a JOIN users u ON a.posted_by=u.id WHERE a.section_id=? ORDER BY a.created_at DESC");
$announcements->execute([$sectionId]);
$announcements = $announcements->fetchAll();

// Calendar events
$events = $pdo->prepare("SELECT * FROM calendar_events WHERE section_id=? ORDER BY event_date ASC");
$events->execute([$sectionId]);
$events = $events->fetchAll();

// Exams
$nowDt = date('Y-m-d H:i:s');
$examsList = $pdo->prepare("SELECT e.*,
    (SELECT COUNT(*) FROM exam_questions eq WHERE eq.exam_id=e.id) as question_count,
    (SELECT COUNT(*) FROM exam_attempts ea WHERE ea.exam_id=e.id AND ea.student_id=? AND ea.status != 'in_progress') as completed_attempts,
    (SELECT id FROM exam_attempts ea WHERE ea.exam_id=e.id AND ea.student_id=? AND ea.status != 'in_progress' ORDER BY ea.attempt_number DESC LIMIT 1) as last_attempt_id,
    (SELECT status FROM exam_attempts ea WHERE ea.exam_id=e.id AND ea.student_id=? AND ea.status != 'in_progress' ORDER BY ea.attempt_number DESC LIMIT 1) as last_status,
    (SELECT percentage FROM exam_attempts ea WHERE ea.exam_id=e.id AND ea.student_id=? AND ea.status != 'in_progress' ORDER BY ea.attempt_number DESC LIMIT 1) as last_pct
    FROM exams e WHERE e.section_id=? AND e.is_published=1 ORDER BY e.start_datetime, e.created_at");
$examsList->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id'], $sectionId]);
$examsList = $examsList->fetchAll();

// Assignments
$assignList = $pdo->prepare("SELECT a.*,
    (SELECT id FROM assignment_submissions WHERE assignment_id=a.id AND student_id=? ORDER BY attempt_number DESC LIMIT 1) as last_sub_id,
    (SELECT status FROM assignment_submissions WHERE assignment_id=a.id AND student_id=? ORDER BY attempt_number DESC LIMIT 1) as last_sub_status,
    (SELECT final_score FROM assignment_submissions WHERE assignment_id=a.id AND student_id=? ORDER BY attempt_number DESC LIMIT 1) as last_score,
    (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id=a.id AND student_id=?) as attempts_used
    FROM assignments a WHERE a.section_id=? AND a.is_published=1 ORDER BY ISNULL(a.due_date), a.due_date");
$assignList->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id'], $sectionId]);
$assignList = $assignList->fetchAll();

include '../includes/header.php';
?>

<div class="page-header">
    <div>
        <a href="<?= BASE_URL ?>/student/courses.php" class="btn btn-sm btn-secondary mb-2">
            <i class="fas fa-arrow-left"></i> Back
        </a>
        <h1><i class="fas fa-book-open"></i> <?= sanitize($section['course_code']) ?> - <?= sanitize($section['section_name']) ?></h1>
        <p class="text-muted">
            <?= sanitize($section['course_name']) ?> |
            <i class="fas fa-user-tie"></i> <?= sanitize($section['faculty_last'] . ', ' . $section['faculty_first']) ?> |
            <?= sanitize($section['schedule'] ?? '') ?> | <?= sanitize($section['room'] ?? '') ?>
        </p>
    </div>
</div>

<!-- Tabs -->
<div class="tabs">
    <a href="?id=<?= $sectionId ?>&tab=modules" class="tab <?= $tab === 'modules' ? 'active' : '' ?>">
        <i class="fas fa-layer-group"></i> Course Content
    </a>
    <a href="?id=<?= $sectionId ?>&tab=announcements" class="tab <?= $tab === 'announcements' ? 'active' : '' ?>">
        <i class="fas fa-bullhorn"></i> Announcements <?php if (count($announcements)): ?><span class="badge badge-info"><?= count($announcements) ?></span><?php endif; ?>
    </a>
    <a href="?id=<?= $sectionId ?>&tab=calendar" class="tab <?= $tab === 'calendar' ? 'active' : '' ?>">
        <i class="fas fa-calendar-alt"></i> Schedule
    </a>
    <a href="?id=<?= $sectionId ?>&tab=exams" class="tab <?= $tab === 'exams' ? 'active' : '' ?>">
        <i class="fas fa-file-alt"></i> Exams
    </a>
    <a href="?id=<?= $sectionId ?>&tab=assignments" class="tab <?= $tab === 'assignments' ? 'active' : '' ?>">
        <i class="fas fa-tasks"></i> Assignments
    </a>
</div>

<?php if ($tab === 'modules'): ?>
<?php if (empty($modules)): ?>
<div class="card"><div class="card-body text-center text-muted py-4">No course content available yet.</div></div>
<?php endif; ?>
<?php foreach ($modules as $mod): ?>
<div class="module-card">
    <div class="module-header">
        <div class="module-title">
            <span class="module-type-badge"><?= ucfirst($mod['module_type']) ?> <?= $mod['week_number'] ? '#' . $mod['week_number'] : '' ?></span>
            <h3><?= sanitize($mod['title']) ?></h3>
        </div>
    </div>
    <?php if ($mod['description']): ?><p class="module-desc"><?= sanitize($mod['description']) ?></p><?php endif; ?>

    <?php if (!empty($mod['materials'])): ?>
    <div class="materials-list">
        <?php foreach ($mod['materials'] as $mat): ?>
        <div class="material-item">
            <div class="material-icon">
                <i class="fas <?= getMaterialIcon($mat['material_type']) ?>"></i>
            </div>
            <div class="material-info">
                <strong><?= sanitize($mat['title']) ?></strong>
                <?php if ($mat['description']): ?><p><?= sanitize($mat['description']) ?></p><?php endif; ?>
                <small class="text-muted">
                    <?= ucfirst(str_replace('_', ' ', $mat['material_type'])) ?>
                    <?php if ($mat['file_size']): ?> · <?= formatFileSize($mat['file_size']) ?><?php endif; ?>
                    · <?= date('M d, Y', strtotime($mat['created_at'])) ?>
                </small>
            </div>
            <div class="material-actions">
                <?php if ($mat['file_path']): ?>
                <a href="<?= BASE_URL ?>/uploads/<?= htmlspecialchars($mat['file_path']) ?>" target="_blank" class="btn btn-sm btn-primary">
                    <i class="fas fa-download"></i> Download
                </a>
                <?php elseif ($mat['external_url']): ?>
                <a href="<?= htmlspecialchars($mat['external_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-primary">
                    <i class="fas fa-external-link-alt"></i> Open
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p class="text-muted pl-3"><em>No materials in this module yet.</em></p>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php elseif ($tab === 'announcements'): ?>
<?php foreach ($announcements as $ann): ?>
<div class="card mb-3">
    <div class="card-header d-flex justify-between">
        <strong><i class="fas fa-bullhorn"></i> <?= sanitize($ann['title']) ?></strong>
        <small class="text-muted"><?= sanitize($ann['first_name'] . ' ' . $ann['last_name']) ?> · <?= date('M d, Y g:i A', strtotime($ann['created_at'])) ?></small>
    </div>
    <div class="card-body"><?= nl2br(sanitize($ann['content'])) ?></div>
</div>
<?php endforeach; ?>
<?php if (empty($announcements)): ?>
<div class="card"><div class="card-body text-center text-muted">No announcements yet.</div></div>
<?php endif; ?>

<?php elseif ($tab === 'calendar'): ?>
<div class="card mb-4">
    <div class="card-header"><h3><i class="fas fa-calendar-alt"></i> Course Schedule</h3></div>
    <div class="card-body">
        <div id="calendar"></div>
    </div>
</div>
<div class="card">
    <div class="card-body p-0">
        <table class="table">
            <thead><tr><th>Date</th><th>Title</th><th>Type</th><th>Time</th></tr></thead>
            <tbody>
                <?php foreach ($events as $ev): ?>
                <tr>
                    <td><?= date('M d, Y', strtotime($ev['event_date'])) ?></td>
                    <td><?= sanitize($ev['title']) ?></td>
                    <td><span class="badge badge-event-<?= $ev['event_type'] ?>"><?= ucfirst($ev['event_type']) ?></span></td>
                    <td><?= $ev['start_time'] ? date('g:i A', strtotime($ev['start_time'])) . ($ev['end_time'] ? ' - ' . date('g:i A', strtotime($ev['end_time'])) : '') : '-' ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($events)): ?>
                <tr><td colspan="4" class="text-center text-muted">No events yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var calendarEl = document.getElementById('calendar');
    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        events: <?= json_encode(array_map(function($e) {
            $colors = ['class'=>'#3788d8','exam'=>'#e74c3c','assignment'=>'#f39c12','activity'=>'#2ecc71','holiday'=>'#9b59b6','other'=>'#95a5a6'];
            return ['title'=>$e['title'],'start'=>$e['event_date'],'backgroundColor'=>$colors[$e['event_type']]??'#3788d8'];
        }, $events)) ?>,
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listWeek' }
    });
    calendar.render();
});
</script>
<?php endif; ?>

<?php if ($tab === 'exams'): ?>
<?php if (empty($examsList)): ?>
<div class="card"><div class="card-body text-center text-muted py-4"><i class="fas fa-clipboard-list fa-2x mb-2"></i><br>No exams or quizzes available yet.</div></div>
<?php else: ?>
<div class="exam-list">
    <?php foreach ($examsList as $e): ?>
    <?php
        $available = true;
        $statusMsg = '';
        if ($e['start_datetime'] && $nowDt < $e['start_datetime']) { $available = false; $statusMsg = 'Opens ' . date('M j, g:i A', strtotime($e['start_datetime'])); }
        if ($e['end_datetime']   && $nowDt > $e['end_datetime'])   { $available = false; $statusMsg = 'Closed ' . date('M j, g:i A', strtotime($e['end_datetime'])); }
        $attemptsLeft = $e['max_attempts'] - $e['completed_attempts'];
        $canTake = $available && $attemptsLeft > 0;
    ?>
    <div class="exam-card">
        <div class="exam-card-header">
            <div>
                <span class="qtype-badge" style="background:#dbeafe;color:#1d4ed8"><?= ucfirst($e['exam_type']) ?></span>
                <h3><?= sanitize($e['title']) ?></h3>
                <?php if ($e['description']): ?><p class="text-muted"><?= sanitize($e['description']) ?></p><?php endif; ?>
            </div>
            <div class="exam-card-meta">
                <span><i class="fas fa-question-circle"></i> <?= $e['question_count'] ?> question<?= $e['question_count'] != 1 ? 's' : '' ?></span>
                <?php if ($e['time_limit']): ?><span><i class="fas fa-clock"></i> <?= $e['time_limit'] ?> min</span><?php endif; ?>
                <span><i class="fas fa-redo"></i> <?= $e['max_attempts'] ?> attempt<?= $e['max_attempts'] != 1 ? 's' : '' ?></span>
                <span><i class="fas fa-medal"></i> Pass: <?= $e['passing_score'] ?>%</span>
            </div>
        </div>
        <div class="exam-card-footer">
            <div>
                <?php if ($e['start_datetime'] || $e['end_datetime']): ?>
                <small class="text-muted">
                    <?= $e['start_datetime'] ? 'From: '.date('M j, g:i A', strtotime($e['start_datetime'])) : '' ?>
                    <?= $e['end_datetime']   ? ' – To: '.date('M j, g:i A', strtotime($e['end_datetime'])) : '' ?>
                </small>
                <?php else: ?><small class="text-muted">Always available</small><?php endif; ?>
            </div>
            <div class="exam-actions">
                <?php if ($e['last_attempt_id']): ?>
                    <?php if ($e['last_status'] === 'graded'): ?>
                        <span class="<?= $e['last_pct'] >= $e['passing_score'] ? 'result-correct' : 'result-wrong' ?>">
                            <strong><?= number_format($e['last_pct'],1) ?>%</strong>
                        </span>
                        <?php if ($e['show_results']): ?>
                        <a href="<?= BASE_URL ?>/student/exam_result.php?attempt_id=<?= $e['last_attempt_id'] ?>" class="btn btn-sm btn-secondary">
                            <i class="fas fa-eye"></i> View Result
                        </a>
                        <?php endif; ?>
                    <?php elseif ($e['last_status'] === 'submitted'): ?>
                        <span class="badge badge-warning">Awaiting Grading</span>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (!$available): ?>
                    <span class="badge badge-secondary"><?= sanitize($statusMsg) ?></span>
                <?php elseif ($attemptsLeft <= 0): ?>
                    <span class="badge badge-secondary">No Attempts Left</span>
                <?php elseif ($canTake): ?>
                    <a href="<?= BASE_URL ?>/student/exam_take.php?exam_id=<?= $e['id'] ?>" class="btn btn-primary">
                        <i class="fas fa-pencil-alt"></i> <?= $e['completed_attempts'] > 0 ? 'Retake' : 'Start Exam' ?>
                    </a>
                    <small class="text-muted"><?= $attemptsLeft ?> left</small>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'assignments'): ?>
<?php if (empty($assignList)): ?>
<div class="card"><div class="card-body text-center text-muted py-4"><i class="fas fa-tasks fa-2x mb-2"></i><br>No assignments posted yet.</div></div>
<?php else: ?>
<div class="assign-list">
    <?php foreach ($assignList as $a): ?>
    <?php
        $isPastDue   = $a['due_date'] && $nowDt > $a['due_date'];
        $isDueSoon   = !$isPastDue && $a['due_date'] && (strtotime($a['due_date']) - time()) < 86400;
        $attLeft     = $a['max_submissions'] - $a['attempts_used'];
        $canSubmit   = $attLeft > 0 && (!$isPastDue || $a['allow_late']);
        $status      = $a['last_sub_status'] ?? null;
        if ($status === 'returned' && $attLeft > 0) $canSubmit = true;
    ?>
    <div class="assign-card">
        <div class="assign-card-header">
            <div>
                <h3><?= sanitize($a['title']) ?></h3>
                <?php if ($a['description']): ?><p class="text-muted"><?= sanitize($a['description']) ?></p><?php endif; ?>
            </div>
            <div class="assign-card-meta">
                <span><i class="fas fa-star"></i> <?= number_format($a['max_score'], 0) ?> pts</span>
                <span><i class="fas fa-redo"></i> <?= $a['max_submissions'] ?> attempt<?= $a['max_submissions'] != 1 ? 's' : '' ?></span>
                <span><i class="fas fa-medal"></i> Pass: <?= $a['passing_score'] ?>%</span>
                <?php if ($a['allow_late']): ?>
                <span style="color:#d97706"><i class="fas fa-clock"></i> Late OK</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="assign-card-footer">
            <div>
                <?php if ($a['due_date']): ?>
                <small class="<?= $isPastDue ? 'text-danger' : ($isDueSoon ? 'text-warning' : 'text-muted') ?>">
                    <i class="fas fa-calendar"></i>
                    <?= $isPastDue ? 'Was due' : 'Due' ?>: <?= date('M j, Y g:i A', strtotime($a['due_date'])) ?>
                    <?php if ($isDueSoon): ?><strong> — Due Soon!</strong><?php endif; ?>
                </small>
                <?php else: ?><small class="text-muted">No deadline</small><?php endif; ?>
            </div>
            <div class="exam-actions">
                <?php if ($status === 'graded' && $a['last_score'] !== null): ?>
                    <?php $pct = $a['max_score'] > 0 ? ($a['last_score'] / $a['max_score'] * 100) : 0; ?>
                    <span class="<?= $pct >= $a['passing_score'] ? 'result-correct' : 'result-wrong' ?>">
                        <strong><?= number_format($a['last_score'], 1) ?>/<?= number_format($a['max_score'], 0) ?></strong>
                    </span>
                <?php elseif ($status === 'submitted'): ?>
                    <span class="badge badge-info">Awaiting Grade</span>
                <?php elseif ($status === 'returned'): ?>
                    <span class="badge badge-warning">Returned – Revise</span>
                <?php endif; ?>

                <?php if ($canSubmit): ?>
                    <a href="<?= BASE_URL ?>/student/assignment_submit.php?assignment_id=<?= $a['id'] ?>" class="btn btn-primary">
                        <i class="fas fa-upload"></i> <?= $a['attempts_used'] > 0 ? 'Resubmit' : 'Submit' ?>
                    </a>
                <?php elseif (!$canSubmit && $a['attempts_used'] > 0): ?>
                    <a href="<?= BASE_URL ?>/student/assignment_submit.php?assignment_id=<?= $a['id'] ?>" class="btn btn-sm btn-secondary">
                        <i class="fas fa-eye"></i> View
                    </a>
                <?php elseif ($isPastDue && !$a['allow_late']): ?>
                    <span class="badge badge-secondary">Closed</span>
                <?php else: ?>
                    <a href="<?= BASE_URL ?>/student/assignment_submit.php?assignment_id=<?= $a['id'] ?>" class="btn btn-primary">
                        <i class="fas fa-upload"></i> Submit
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$pageTitle = 'Calendar';
$role = $_SESSION['role'];

// Handle event creation (secretary, dean, faculty)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($role, ['secretary', 'dean']) && isset($_POST['post_action']) && $_POST['post_action'] === 'create_event') {
    $evTitle     = trim($_POST['event_title'] ?? '');
    $evDate      = $_POST['event_date'] ?? '';
    $evType      = $_POST['event_type'] ?? 'other';
    $evDesc      = trim($_POST['event_desc'] ?? '');
    $evStart     = $_POST['start_time'] ?? null;
    $evEnd       = $_POST['end_time'] ?? null;
    $evSectionId = (int)($_POST['section_id'] ?? 0) ?: null;

    if ($evTitle && $evDate) {
        $pdo->prepare("INSERT INTO calendar_events (section_id, title, description, event_type, event_date, start_time, end_time, created_by) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$evSectionId, $evTitle, $evDesc, $evType, $evDate, $evStart ?: null, $evEnd ?: null, $_SESSION['user_id']]);
        flashMessage('success', 'Event added to calendar.');
    }
    redirect(BASE_URL . '/calendar.php');
}

// Handle event delete (secretary, dean)
if (isset($_GET['delete']) && is_numeric($_GET['delete']) && in_array($role, ['secretary', 'dean'])) {
    $pdo->prepare("DELETE FROM calendar_events WHERE id=?")->execute([(int)$_GET['delete']]);
    flashMessage('success', 'Event deleted.');
    redirect(BASE_URL . '/calendar.php');
}

// Gather events depending on role
if (in_array($role, ['dean', 'secretary'])) {
    $events = $pdo->query("SELECT ce.*, s.section_name, c.course_code FROM calendar_events ce LEFT JOIN sections s ON ce.section_id=s.id LEFT JOIN courses c ON s.course_id=c.id ORDER BY ce.event_date")->fetchAll();
} elseif ($role === 'faculty') {
    // Section-specific events for their sections + school-wide events
    $stmt = $pdo->prepare("
        SELECT ce.*, s.section_name, c.course_code FROM calendar_events ce
        LEFT JOIN sections s ON ce.section_id = s.id
        LEFT JOIN courses c ON s.course_id = c.id
        WHERE ce.section_id IS NULL
           OR s.faculty_id = ?
        ORDER BY ce.event_date");
    $stmt->execute([$_SESSION['user_id']]);
    $events = $stmt->fetchAll();
} else {
    // Section-specific events for enrolled sections + school-wide events
    $stmt = $pdo->prepare("
        SELECT ce.*, s.section_name, c.course_code FROM calendar_events ce
        LEFT JOIN sections s ON ce.section_id = s.id
        LEFT JOIN courses c ON s.course_id = c.id
        LEFT JOIN enrollments e ON e.section_id = ce.section_id AND e.student_id = ? AND e.status = 'enrolled'
        WHERE ce.section_id IS NULL
           OR e.student_id = ?
        ORDER BY ce.event_date");
    $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
    $events = $stmt->fetchAll();
}

include 'includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-calendar-alt"></i> Calendar</h1>
</div>

<?php if (in_array($role, ['secretary', 'dean'])): ?>
<?php $sections = $pdo->query("SELECT s.id, s.section_name, c.course_code FROM sections s JOIN courses c ON s.course_id=c.id WHERE s.is_active=1 ORDER BY c.course_code, s.section_name")->fetchAll(); ?>
<div class="card mb-4">
    <div class="card-header"><h3><i class="fas fa-calendar-plus"></i> Add Calendar Event</h3></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="post_action" value="create_event">
            <div class="form-row">
                <div class="form-group col-4">
                    <label>Event Title *</label>
                    <input type="text" name="event_title" class="form-control" required>
                </div>
                <div class="form-group col-2">
                    <label>Type</label>
                    <select name="event_type" class="form-control">
                        <option value="class">Class</option>
                        <option value="exam">Exam</option>
                        <option value="assignment">Assignment</option>
                        <option value="activity">Activity</option>
                        <option value="holiday">Holiday</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group col-2">
                    <label>Date *</label>
                    <input type="date" name="event_date" class="form-control" required>
                </div>
                <div class="form-group col-2">
                    <label>Start Time</label>
                    <input type="time" name="start_time" class="form-control">
                </div>
                <div class="form-group col-2">
                    <label>End Time</label>
                    <input type="time" name="end_time" class="form-control">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group col-5">
                    <label>Section <small class="text-muted">(optional — leave blank for school-wide)</small></label>
                    <select name="section_id" class="form-control">
                        <option value="">All / School-wide</option>
                        <?php foreach ($sections as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= sanitize($s['course_code'] . ' - ' . $s['section_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-7">
                    <label>Description</label>
                    <input type="text" name="event_desc" class="form-control" placeholder="Optional description">
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Event</button>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
        <div id="calendar"></div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>Upcoming Events</h3></div>
    <div class="card-body p-0">
        <table class="table">
            <thead><tr><th>Date</th><th>Course/Section</th><th>Title</th><th>Type</th><th>Time</th><?php if (in_array($role, ['secretary','dean'])): ?><th>Actions</th><?php endif; ?></tr></thead>
            <tbody>
                <?php foreach ($events as $ev): ?>
                <tr>
                    <td><?= date('M d, Y', strtotime($ev['event_date'])) ?></td>
                    <td><?= isset($ev['course_code']) && $ev['course_code'] ? sanitize($ev['course_code'] . ' - ' . $ev['section_name']) : '<span class="text-muted">School-wide</span>' ?></td>
                    <td><?= sanitize($ev['title']) ?></td>
                    <td><span class="badge badge-event-<?= $ev['event_type'] ?>"><?= ucfirst($ev['event_type']) ?></span></td>
                    <td><?= $ev['start_time'] ? date('g:i A', strtotime($ev['start_time'])) : '-' ?></td>
                    <?php if (in_array($role, ['secretary','dean'])): ?>
                    <td>
                        <a href="?delete=<?= $ev['id'] ?>" class="btn btn-sm btn-danger"
                           onclick="return confirm('Delete this event?')">
                            <i class="fas fa-trash"></i>
                        </a>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($events)): ?>
                <tr><td colspan="5" class="text-center text-muted">No events found.</td></tr>
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
        height: 600,
        events: <?= json_encode(array_map(function($e) {
            $colors = ['class'=>'#3788d8','exam'=>'#e74c3c','assignment'=>'#f39c12','activity'=>'#2ecc71','holiday'=>'#9b59b6','other'=>'#95a5a6'];
            $label = isset($e['course_code']) ? '[' . $e['course_code'] . '] ' . $e['title'] : $e['title'];
            return ['title'=>$label,'start'=>$e['event_date'],'backgroundColor'=>$colors[$e['event_type']]??'#3788d8','borderColor'=>$colors[$e['event_type']]??'#3788d8'];
        }, $events)) ?>,
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,listMonth' }
    });
    calendar.render();
});
</script>

<?php include 'includes/footer.php'; ?>

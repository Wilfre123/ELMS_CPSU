<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();

// Check first login
$user = getCurrentUser();
if ($user['is_first_login'] && in_array($user['role'], ['faculty', 'student'])) {
    redirect(BASE_URL . '/auth/change_password.php?first=1');
}

$pageTitle = 'Dashboard';
$role = $_SESSION['role'];

// Stats
$totalCourses = $totalSections = $totalStudents = $totalFaculty = 0;

if (in_array($role, ['dean', 'secretary'])) {
    $totalCourses  = $pdo->query("SELECT COUNT(*) FROM courses WHERE is_active=1")->fetchColumn();
    $totalSections = $pdo->query("SELECT COUNT(*) FROM sections WHERE is_active=1")->fetchColumn();
    $totalStudents = $pdo->query("SELECT COUNT(*) FROM users WHERE role='student' AND is_active=1")->fetchColumn();
    $totalFaculty  = $pdo->query("SELECT COUNT(*) FROM users WHERE role='faculty' AND is_active=1")->fetchColumn();
} elseif ($role === 'faculty') {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sections WHERE faculty_id=? AND is_active=1");
    $stmt->execute([$_SESSION['user_id']]);
    $totalSections = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT e.student_id) FROM enrollments e JOIN sections s ON e.section_id=s.id WHERE s.faculty_id=?");
    $stmt->execute([$_SESSION['user_id']]);
    $totalStudents = $stmt->fetchColumn();
} elseif ($role === 'student') {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE student_id=? AND status='enrolled'");
    $stmt->execute([$_SESSION['user_id']]);
    $totalSections = $stmt->fetchColumn();
}

// Upcoming events
$upcomingEvents = [];
if (in_array($role, ['dean', 'secretary'])) {
    $upcomingEvents = $pdo->query("SELECT * FROM calendar_events WHERE event_date >= CURDATE() ORDER BY event_date ASC LIMIT 5")->fetchAll();
} elseif ($role === 'faculty') {
    $stmt = $pdo->prepare("SELECT ce.* FROM calendar_events ce JOIN sections s ON ce.section_id=s.id WHERE s.faculty_id=? AND ce.event_date >= CURDATE() ORDER BY ce.event_date ASC LIMIT 5");
    $stmt->execute([$_SESSION['user_id']]);
    $upcomingEvents = $stmt->fetchAll();
} elseif ($role === 'student') {
    $stmt = $pdo->prepare("SELECT ce.* FROM calendar_events ce JOIN enrollments e ON ce.section_id=e.section_id WHERE e.student_id=? AND ce.event_date >= CURDATE() ORDER BY ce.event_date ASC LIMIT 5");
    $stmt->execute([$_SESSION['user_id']]);
    $upcomingEvents = $stmt->fetchAll();
}

// Recent announcements
$announcements = [];
if ($role === 'faculty') {
    $stmt = $pdo->prepare("SELECT a.*, s.section_name FROM announcements a LEFT JOIN sections s ON a.section_id=s.id JOIN sections s2 ON a.section_id=s2.id WHERE s2.faculty_id=? ORDER BY a.created_at DESC LIMIT 5");
    $stmt->execute([$_SESSION['user_id']]);
    $announcements = $stmt->fetchAll();
} elseif ($role === 'student') {
    $stmt = $pdo->prepare("SELECT a.*, s.section_name FROM announcements a LEFT JOIN sections s ON a.section_id=s.id JOIN enrollments e ON a.section_id=e.section_id WHERE e.student_id=? ORDER BY a.created_at DESC LIMIT 5");
    $stmt->execute([$_SESSION['user_id']]);
    $announcements = $stmt->fetchAll();
}

include 'includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-tachometer-alt"></i> Dashboard</h1>
    <p>Welcome back, <?= sanitize($user['first_name']) ?>!</p>
</div>

<!-- Stats Cards -->
<div class="stats-grid">
    <?php if (in_array($role, ['dean', 'secretary'])): ?>
    <div class="stat-card blue">
        <div class="stat-icon"><i class="fas fa-book"></i></div>
        <div class="stat-info">
            <h3><?= $totalCourses ?></h3>
            <p>Total Courses</p>
        </div>
    </div>
    <div class="stat-card green">
        <div class="stat-icon"><i class="fas fa-chalkboard-teacher"></i></div>
        <div class="stat-info">
            <h3><?= $totalSections ?></h3>
            <p>Class Sections</p>
        </div>
    </div>
    <div class="stat-card orange">
        <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
        <div class="stat-info">
            <h3><?= $totalStudents ?></h3>
            <p>Students</p>
        </div>
    </div>
    <div class="stat-card purple">
        <div class="stat-icon"><i class="fas fa-chalkboard"></i></div>
        <div class="stat-info">
            <h3><?= $totalFaculty ?></h3>
            <p>Faculty</p>
        </div>
    </div>
    <?php elseif ($role === 'faculty'): ?>
    <div class="stat-card blue">
        <div class="stat-icon"><i class="fas fa-chalkboard"></i></div>
        <div class="stat-info">
            <h3><?= $totalSections ?></h3>
            <p>My Sections</p>
        </div>
    </div>
    <div class="stat-card green">
        <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
        <div class="stat-info">
            <h3><?= $totalStudents ?></h3>
            <p>Total Students</p>
        </div>
    </div>
    <?php elseif ($role === 'student'): ?>
    <div class="stat-card blue">
        <div class="stat-icon"><i class="fas fa-book-open"></i></div>
        <div class="stat-info">
            <h3><?= $totalSections ?></h3>
            <p>Enrolled Courses</p>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="dashboard-grid">
    <!-- Upcoming Events -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-calendar-alt"></i> Upcoming Events</h3>
            <a href="<?= BASE_URL ?>/calendar.php" class="btn btn-sm btn-primary">View Calendar</a>
        </div>
        <div class="card-body">
            <?php if (empty($upcomingEvents)): ?>
                <p class="text-muted text-center">No upcoming events.</p>
            <?php else: ?>
                <ul class="event-list">
                    <?php foreach ($upcomingEvents as $event): ?>
                    <li class="event-item event-<?= $event['event_type'] ?>">
                        <span class="event-date"><?= date('M d', strtotime($event['event_date'])) ?></span>
                        <div>
                            <strong><?= sanitize($event['title']) ?></strong>
                            <small><?= ucfirst($event['event_type']) ?></small>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Links -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
        </div>
        <div class="card-body">
            <div class="quick-links">
                <?php if (in_array($role, ['dean', 'secretary'])): ?>
                    <a href="<?= BASE_URL ?>/admin/users.php?action=create" class="quick-link">
                        <i class="fas fa-user-plus"></i> Add User
                    </a>
                    <a href="<?= BASE_URL ?>/admin/courses.php?action=create" class="quick-link">
                        <i class="fas fa-plus-circle"></i> Add Course
                    </a>
                    <a href="<?= BASE_URL ?>/admin/sections.php?action=create" class="quick-link">
                        <i class="fas fa-chalkboard-teacher"></i> Add Section
                    </a>
                    <a href="<?= BASE_URL ?>/admin/enrollments.php" class="quick-link">
                        <i class="fas fa-user-graduate"></i> Enroll Students
                    </a>
                <?php elseif ($role === 'faculty'): ?>
                    <a href="<?= BASE_URL ?>/faculty/sections.php" class="quick-link">
                        <i class="fas fa-chalkboard"></i> My Sections
                    </a>
                    <a href="<?= BASE_URL ?>/calendar.php" class="quick-link">
                        <i class="fas fa-calendar-plus"></i> Add Event
                    </a>
                <?php elseif ($role === 'student'): ?>
                    <a href="<?= BASE_URL ?>/student/courses.php" class="quick-link">
                        <i class="fas fa-book-open"></i> My Courses
                    </a>
                    <a href="<?= BASE_URL ?>/calendar.php" class="quick-link">
                        <i class="fas fa-calendar-alt"></i> Schedule
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

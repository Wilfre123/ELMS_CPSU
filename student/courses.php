<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('student');

$pageTitle = 'My Courses';

$sections = $pdo->prepare("SELECT s.*, c.course_code, c.course_name, c.units,
    u.first_name as faculty_first, u.last_name as faculty_last,
    (SELECT COUNT(*) FROM modules m WHERE m.section_id=s.id AND m.is_visible=1) as module_count
    FROM enrollments e
    JOIN sections s ON e.section_id=s.id
    JOIN courses c ON s.course_id=c.id
    JOIN users u ON s.faculty_id=u.id
    WHERE e.student_id=? AND e.status='enrolled' AND s.is_active=1
    ORDER BY c.course_code");
$sections->execute([$_SESSION['user_id']]);
$sections = $sections->fetchAll();

include '../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-book-open"></i> My Courses</h1>
</div>

<?php if (empty($sections)): ?>
<div class="card">
    <div class="card-body text-center text-muted py-5">
        <i class="fas fa-book-open fa-3x mb-3"></i>
        <p>You are not enrolled in any courses yet.</p>
    </div>
</div>
<?php else: ?>
<div class="course-grid">
    <?php foreach ($sections as $s): ?>
    <div class="course-card">
        <div class="course-card-header">
            <h3><?= sanitize($s['course_code']) ?></h3>
            <span class="section-label"><?= sanitize($s['section_name']) ?></span>
        </div>
        <div class="course-card-body">
            <p class="course-name"><?= sanitize($s['course_name']) ?></p>
            <div class="course-meta">
                <span><i class="fas fa-user-tie"></i> <?= sanitize($s['faculty_last'] . ', ' . $s['faculty_first']) ?></span>
                <span><i class="fas fa-clock"></i> <?= sanitize($s['schedule'] ?? 'No schedule') ?></span>
                <span><i class="fas fa-door-open"></i> <?= sanitize($s['room'] ?? 'No room') ?></span>
                <span><i class="fas fa-layer-group"></i> <?= $s['module_count'] ?> Modules</span>
            </div>
        </div>
        <div class="course-card-footer">
            <a href="<?= BASE_URL ?>/student/course_view.php?id=<?= $s['id'] ?>" class="btn btn-primary btn-sm">
                <i class="fas fa-arrow-right"></i> View Course
            </a>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty');

$pageTitle = 'My Sections';

$sections = $pdo->prepare("SELECT s.*, c.course_code, c.course_name,
    (SELECT COUNT(*) FROM enrollments e WHERE e.section_id=s.id AND e.status='enrolled') as enrolled_count,
    (SELECT COUNT(*) FROM modules m WHERE m.section_id=s.id) as module_count
    FROM sections s JOIN courses c ON s.course_id=c.id
    WHERE s.faculty_id=? AND s.is_active=1
    ORDER BY c.course_code, s.section_name");
$sections->execute([$_SESSION['user_id']]);
$sections = $sections->fetchAll();

include '../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-chalkboard"></i> My Sections</h1>
</div>

<?php if (empty($sections)): ?>
<div class="card">
    <div class="card-body text-center text-muted py-5">
        <i class="fas fa-chalkboard fa-3x mb-3"></i>
        <p>You have no assigned sections.</p>
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
                <span><i class="fas fa-clock"></i> <?= sanitize($s['schedule'] ?? 'No schedule') ?></span>
                <span><i class="fas fa-door-open"></i> <?= sanitize($s['room'] ?? 'No room') ?></span>
                <span><i class="fas fa-users"></i> <?= $s['enrolled_count'] ?> Students</span>
                <span><i class="fas fa-layer-group"></i> <?= $s['module_count'] ?> Modules</span>
                <?php if ($s['semester']): ?><span><i class="fas fa-calendar"></i> <?= sanitize($s['semester']) ?></span><?php endif; ?>
            </div>
        </div>
        <div class="course-card-footer">
            <a href="<?= BASE_URL ?>/faculty/section_view.php?id=<?= $s['id'] ?>" class="btn btn-primary btn-sm">
                <i class="fas fa-arrow-right"></i> Manage Section
            </a>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>

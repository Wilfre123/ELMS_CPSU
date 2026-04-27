<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty');

$sectionId = (int)($_GET['id'] ?? 0);
if (!$sectionId) redirect(BASE_URL . '/faculty/sections.php');

// Verify ownership
$sectionStmt = $pdo->prepare("SELECT s.*, c.course_code, c.course_name FROM sections s JOIN courses c ON s.course_id=c.id WHERE s.id=? AND s.faculty_id=?");
$sectionStmt->execute([$sectionId, $_SESSION['user_id']]);
$section = $sectionStmt->fetch();
if (!$section) redirect(BASE_URL . '/faculty/sections.php');

$pageTitle = $section['course_code'] . ' - ' . $section['section_name'];
$tab = $_GET['tab'] ?? 'modules';

// Handle module create/edit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['post_action'] ?? '';

    if ($postAction === 'create_module') {
        $title      = trim($_POST['title'] ?? '');
        $desc       = trim($_POST['description'] ?? '');
        $moduleType = $_POST['module_type'] ?? 'module';
        $weekNum    = (int)($_POST['week_number'] ?? 0);
        if ($title) {
            $maxOrder = $pdo->prepare("SELECT COALESCE(MAX(order_index),0)+1 FROM modules WHERE section_id=?");
            $maxOrder->execute([$sectionId]);
            $order = $maxOrder->fetchColumn();
            $pdo->prepare("INSERT INTO modules (section_id, title, description, module_type, week_number, order_index) VALUES (?,?,?,?,?,?)")
                ->execute([$sectionId, $title, $desc, $moduleType, $weekNum, $order]);
            flashMessage('success', 'Module created.');
        }
        redirect(BASE_URL . '/faculty/section_view.php?id=' . $sectionId . '&tab=modules');
    }

    if ($postAction === 'edit_module') {
        $mid   = (int)$_POST['module_id'];
        $title = trim($_POST['title'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $moduleType = $_POST['module_type'] ?? 'module';
        $weekNum = (int)($_POST['week_number'] ?? 0);
        $visible = isset($_POST['is_visible']) ? 1 : 0;
        $pdo->prepare("UPDATE modules SET title=?, description=?, module_type=?, week_number=?, is_visible=? WHERE id=? AND section_id=?")
            ->execute([$title, $desc, $moduleType, $weekNum, $visible, $mid, $sectionId]);
        flashMessage('success', 'Module updated.');
        redirect(BASE_URL . '/faculty/section_view.php?id=' . $sectionId . '&tab=modules');
    }

    if ($postAction === 'delete_module') {
        $mid = (int)$_POST['module_id'];
        $pdo->prepare("DELETE FROM materials WHERE module_id=?")->execute([$mid]);
        $pdo->prepare("DELETE FROM modules WHERE id=? AND section_id=?")->execute([$mid, $sectionId]);
        flashMessage('success', 'Module deleted.');
        redirect(BASE_URL . '/faculty/section_view.php?id=' . $sectionId . '&tab=modules');
    }

    if ($postAction === 'upload_material') {
        $moduleId    = (int)$_POST['module_id'];
        $title       = trim($_POST['material_title'] ?? '');
        $desc        = trim($_POST['material_desc'] ?? '');
        $matType     = $_POST['material_type'] ?? 'other';
        $extUrl      = trim($_POST['external_url'] ?? '');
        $changeNotes = trim($_POST['change_notes'] ?? '');

        if (empty($title)) {
            flashMessage('danger', 'Material title is required.');
            redirect(BASE_URL . '/faculty/section_view.php?id=' . $sectionId . '&tab=modules');
        }

        $filePath = null; $fileSize = null; $mimeType = null;

        if (isset($_FILES['material_file']) && $_FILES['material_file']['error'] === UPLOAD_ERR_OK) {
            $file   = $_FILES['material_file'];
            $ext    = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf','doc','docx','ppt','pptx','xls','xlsx','mp4','avi','mov','png','jpg','jpeg','gif','zip','txt'];
            if (!in_array($ext, $allowed)) {
                flashMessage('danger', 'File type not allowed.');
                redirect(BASE_URL . '/faculty/section_view.php?id=' . $sectionId . '&tab=modules');
            }
            if ($file['size'] > MAX_FILE_SIZE) {
                flashMessage('danger', 'File size exceeds 50MB limit.');
                redirect(BASE_URL . '/faculty/section_view.php?id=' . $sectionId . '&tab=modules');
            }
            $uploadDir = UPLOAD_PATH . 'materials/' . $sectionId . '/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
            move_uploaded_file($file['tmp_name'], $uploadDir . $filename);
            $filePath = 'materials/' . $sectionId . '/' . $filename;
            $fileSize = $file['size'];
            $mimeType = $file['type'];
        }

        $maxOrder = $pdo->prepare("SELECT COALESCE(MAX(order_index),0)+1 FROM materials WHERE module_id=?");
        $maxOrder->execute([$moduleId]);
        $order = $maxOrder->fetchColumn();

        $pdo->prepare("INSERT INTO materials (module_id, title, description, material_type, file_path, external_url, file_size, mime_type, uploaded_by, order_index) VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$moduleId, $title, $desc, $matType, $filePath, $extUrl ?: null, $fileSize, $mimeType, $_SESSION['user_id'], $order]);

        $matId = $pdo->lastInsertId();

        // Save version 1
        $pdo->prepare("INSERT INTO material_versions (material_id, version_number, title, file_path, external_url, file_size, change_notes, updated_by) VALUES (?,1,?,?,?,?,?,?)")
            ->execute([$matId, $title, $filePath, $extUrl ?: null, $fileSize, $changeNotes ?: 'Initial upload', $_SESSION['user_id']]);

        flashMessage('success', 'Material uploaded.');
        redirect(BASE_URL . '/faculty/section_view.php?id=' . $sectionId . '&tab=modules');
    }

    if ($postAction === 'update_material') {
        $matId       = (int)$_POST['material_id'];
        $title       = trim($_POST['material_title'] ?? '');
        $desc        = trim($_POST['material_desc'] ?? '');
        $matType     = $_POST['material_type'] ?? 'other';
        $extUrl      = trim($_POST['external_url'] ?? '');
        $changeNotes = trim($_POST['change_notes'] ?? 'Updated');
        $visible     = isset($_POST['is_visible']) ? 1 : 0;

        // Get current material
        $curMat = $pdo->prepare("SELECT * FROM materials WHERE id=?");
        $curMat->execute([$matId]);
        $curMat = $curMat->fetch();

        $filePath = $curMat['file_path'];
        $fileSize = $curMat['file_size'];
        $mimeType = $curMat['mime_type'];

        if (isset($_FILES['material_file']) && $_FILES['material_file']['error'] === UPLOAD_ERR_OK) {
            $file    = $_FILES['material_file'];
            $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf','doc','docx','ppt','pptx','xls','xlsx','mp4','avi','mov','png','jpg','jpeg','gif','zip','txt'];
            if (in_array($ext, $allowed) && $file['size'] <= MAX_FILE_SIZE) {
                $uploadDir = UPLOAD_PATH . 'materials/' . $sectionId . '/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
                move_uploaded_file($file['tmp_name'], $uploadDir . $filename);
                $filePath = 'materials/' . $sectionId . '/' . $filename;
                $fileSize = $file['size'];
                $mimeType = $file['type'];
            }
        }

        $pdo->prepare("UPDATE materials SET title=?, description=?, material_type=?, file_path=?, external_url=?, file_size=?, mime_type=?, is_visible=? WHERE id=?")
            ->execute([$title, $desc, $matType, $filePath, $extUrl ?: null, $fileSize, $mimeType, $visible, $matId]);

        // Add new version
        $verStmt = $pdo->prepare("SELECT MAX(version_number) FROM material_versions WHERE material_id=?");
        $verStmt->execute([$matId]);
        $newVer = (int)$verStmt->fetchColumn() + 1;

        $pdo->prepare("INSERT INTO material_versions (material_id, version_number, title, file_path, external_url, file_size, change_notes, updated_by) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$matId, $newVer, $title, $filePath, $extUrl ?: null, $fileSize, $changeNotes, $_SESSION['user_id']]);

        flashMessage('success', 'Material updated. Version ' . $newVer . ' saved.');
        redirect(BASE_URL . '/faculty/section_view.php?id=' . $sectionId . '&tab=modules');
    }

    if ($postAction === 'delete_material') {
        $matId = (int)$_POST['material_id'];
        $pdo->prepare("DELETE FROM material_versions WHERE material_id=?")->execute([$matId]);
        $pdo->prepare("DELETE FROM materials WHERE id=?")->execute([$matId]);
        flashMessage('success', 'Material deleted.');
        redirect(BASE_URL . '/faculty/section_view.php?id=' . $sectionId . '&tab=modules');
    }

    if ($postAction === 'create_announcement') {
        $annTitle   = trim($_POST['ann_title'] ?? '');
        $annContent = trim($_POST['ann_content'] ?? '');
        if ($annTitle && $annContent) {
            $pdo->prepare("INSERT INTO announcements (section_id, title, content, posted_by) VALUES (?,?,?,?)")
                ->execute([$sectionId, $annTitle, $annContent, $_SESSION['user_id']]);
            flashMessage('success', 'Announcement posted.');
        }
        redirect(BASE_URL . '/faculty/section_view.php?id=' . $sectionId . '&tab=announcements');
    }

    if ($postAction === 'create_event') {
        $evTitle = trim($_POST['event_title'] ?? '');
        $evDate  = $_POST['event_date'] ?? '';
        $evType  = $_POST['event_type'] ?? 'other';
        $evDesc  = trim($_POST['event_desc'] ?? '');
        $evStart = $_POST['start_time'] ?? null;
        $evEnd   = $_POST['end_time'] ?? null;
        if ($evTitle && $evDate) {
            $pdo->prepare("INSERT INTO calendar_events (section_id, title, description, event_type, event_date, start_time, end_time, created_by) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$sectionId, $evTitle, $evDesc, $evType, $evDate, $evStart ?: null, $evEnd ?: null, $_SESSION['user_id']]);
            flashMessage('success', 'Event added to calendar.');
        }
        redirect(BASE_URL . '/faculty/section_view.php?id=' . $sectionId . '&tab=calendar');
    }
}

// Fetch modules with materials
$modules = $pdo->prepare("SELECT * FROM modules WHERE section_id=? ORDER BY order_index, id");
$modules->execute([$sectionId]);
$modules = $modules->fetchAll();

foreach ($modules as &$mod) {
    $matStmt = $pdo->prepare("SELECT * FROM materials WHERE module_id=? ORDER BY order_index, id");
    $matStmt->execute([$mod['id']]);
    $mod['materials'] = $matStmt->fetchAll();
}
unset($mod);

// Students
$students = $pdo->prepare("SELECT u.*, e.enrolled_at FROM enrollments e JOIN users u ON e.student_id=u.id WHERE e.section_id=? AND e.status='enrolled' ORDER BY u.last_name");
$students->execute([$sectionId]);
$students = $students->fetchAll();

// Announcements
$announcements = $pdo->prepare("SELECT * FROM announcements WHERE section_id=? ORDER BY created_at DESC");
$announcements->execute([$sectionId]);
$announcements = $announcements->fetchAll();

// Calendar events
$events = $pdo->prepare("SELECT * FROM calendar_events WHERE section_id=? ORDER BY event_date ASC");
$events->execute([$sectionId]);
$events = $events->fetchAll();

include '../includes/header.php';
?>

<div class="page-header">
    <div>
        <a href="<?= BASE_URL ?>/faculty/sections.php" class="btn btn-sm btn-secondary mb-2">
            <i class="fas fa-arrow-left"></i> Back
        </a>
        <h1><i class="fas fa-chalkboard"></i> <?= sanitize($section['course_code']) ?> - <?= sanitize($section['section_name']) ?></h1>
        <p class="text-muted"><?= sanitize($section['course_name']) ?> | <?= sanitize($section['schedule'] ?? '') ?> | <?= sanitize($section['room'] ?? '') ?></p>
    </div>
</div>

<!-- Tabs -->
<div class="tabs">
    <a href="?id=<?= $sectionId ?>&tab=modules" class="tab <?= $tab === 'modules' ? 'active' : '' ?>">
        <i class="fas fa-layer-group"></i> Modules & Materials
    </a>
    <a href="?id=<?= $sectionId ?>&tab=students" class="tab <?= $tab === 'students' ? 'active' : '' ?>">
        <i class="fas fa-users"></i> Students (<?= count($students) ?>)
    </a>
    <a href="?id=<?= $sectionId ?>&tab=announcements" class="tab <?= $tab === 'announcements' ? 'active' : '' ?>">
        <i class="fas fa-bullhorn"></i> Announcements
    </a>
    <a href="?id=<?= $sectionId ?>&tab=calendar" class="tab <?= $tab === 'calendar' ? 'active' : '' ?>">
        <i class="fas fa-calendar-alt"></i> Calendar
    </a>
    <a href="?id=<?= $sectionId ?>&tab=exams" class="tab <?= $tab === 'exams' ? 'active' : '' ?>">
        <i class="fas fa-file-alt"></i> Exams
    </a>
    <a href="?id=<?= $sectionId ?>&tab=assignments" class="tab <?= $tab === 'assignments' ? 'active' : '' ?>">
        <i class="fas fa-tasks"></i> Assignments
    </a>
</div>

<?php if ($tab === 'modules'): ?>
<!-- Add Module -->
<div class="card mb-4">
    <div class="card-header">
        <h3><i class="fas fa-plus-circle"></i> Add New Module</h3>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="post_action" value="create_module">
            <div class="form-row">
                <div class="form-group col-5">
                    <label>Module Title *</label>
                    <input type="text" name="title" class="form-control" placeholder="e.g. Week 1: Introduction" required>
                </div>
                <div class="form-group col-3">
                    <label>Type</label>
                    <select name="module_type" class="form-control">
                        <option value="module">Module</option>
                        <option value="week">Week</option>
                        <option value="topic">Topic</option>
                    </select>
                </div>
                <div class="form-group col-2">
                    <label>Week #</label>
                    <input type="number" name="week_number" class="form-control" min="1" placeholder="1">
                </div>
                <div class="form-group col-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-plus"></i> Add</button>
                </div>
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" class="form-control" rows="2" placeholder="Optional description..."></textarea>
            </div>
        </form>
    </div>
</div>

<!-- Modules List -->
<?php foreach ($modules as $mod): ?>
<div class="module-card <?= !$mod['is_visible'] ? 'module-hidden' : '' ?>">
    <div class="module-header">
        <div class="module-title">
            <span class="module-type-badge"><?= ucfirst($mod['module_type']) ?> <?= $mod['week_number'] ? '#' . $mod['week_number'] : '' ?></span>
            <h3><?= sanitize($mod['title']) ?></h3>
            <?php if (!$mod['is_visible']): ?><span class="badge badge-warning">Hidden</span><?php endif; ?>
        </div>
        <div class="module-actions">
            <button class="btn btn-sm btn-info" onclick="toggleModuleForm('edit-module-<?= $mod['id'] ?>')">
                <i class="fas fa-edit"></i>
            </button>
            <button class="btn btn-sm btn-success" onclick="toggleModuleForm('upload-material-<?= $mod['id'] ?>')">
                <i class="fas fa-upload"></i> Add Material
            </button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this module and all its materials?')">
                <input type="hidden" name="post_action" value="delete_module">
                <input type="hidden" name="module_id" value="<?= $mod['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
            </form>
        </div>
    </div>
    <?php if ($mod['description']): ?><p class="module-desc"><?= sanitize($mod['description']) ?></p><?php endif; ?>

    <!-- Edit Module Form -->
    <div id="edit-module-<?= $mod['id'] ?>" class="collapsible-form" style="display:none">
        <form method="POST" class="inner-form">
            <input type="hidden" name="post_action" value="edit_module">
            <input type="hidden" name="module_id" value="<?= $mod['id'] ?>">
            <div class="form-row">
                <div class="form-group col-5">
                    <label>Title</label>
                    <input type="text" name="title" class="form-control" value="<?= sanitize($mod['title']) ?>" required>
                </div>
                <div class="form-group col-3">
                    <label>Type</label>
                    <select name="module_type" class="form-control">
                        <option value="module" <?= $mod['module_type']==='module'?'selected':'' ?>>Module</option>
                        <option value="week"   <?= $mod['module_type']==='week'?'selected':'' ?>>Week</option>
                        <option value="topic"  <?= $mod['module_type']==='topic'?'selected':'' ?>>Topic</option>
                    </select>
                </div>
                <div class="form-group col-2">
                    <label>Week #</label>
                    <input type="number" name="week_number" class="form-control" value="<?= $mod['week_number'] ?>">
                </div>
                <div class="form-group col-2">
                    <label>Visible</label>
                    <div class="checkbox-wrap">
                        <input type="checkbox" name="is_visible" <?= $mod['is_visible'] ? 'checked' : '' ?>> Visible to students
                    </div>
                </div>
            </div>
            <div class="form-group">
                <textarea name="description" class="form-control" rows="2"><?= sanitize($mod['description']) ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Update</button>
        </form>
    </div>

    <!-- Upload Material Form -->
    <div id="upload-material-<?= $mod['id'] ?>" class="collapsible-form" style="display:none">
        <form method="POST" enctype="multipart/form-data" class="inner-form">
            <input type="hidden" name="post_action" value="upload_material">
            <input type="hidden" name="module_id" value="<?= $mod['id'] ?>">
            <div class="form-row">
                <div class="form-group col-5">
                    <label>Title *</label>
                    <input type="text" name="material_title" class="form-control" required>
                </div>
                <div class="form-group col-3">
                    <label>Type</label>
                    <select name="material_type" class="form-control">
                        <option value="syllabus">Syllabus</option>
                        <option value="lecture_note">Lecture Note</option>
                        <option value="pdf">PDF</option>
                        <option value="presentation">Presentation</option>
                        <option value="video">Video</option>
                        <option value="link">Link</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group col-4">
                    <label>File (max 50MB)</label>
                    <input type="file" name="material_file" class="form-control">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group col-6">
                    <label>Or External URL</label>
                    <input type="url" name="external_url" class="form-control" placeholder="https://...">
                </div>
                <div class="form-group col-6">
                    <label>Change Notes</label>
                    <input type="text" name="change_notes" class="form-control" placeholder="Initial upload">
                </div>
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea name="material_desc" class="form-control" rows="2"></textarea>
            </div>
            <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-upload"></i> Upload</button>
        </form>
    </div>

    <!-- Materials List -->
    <?php if (!empty($mod['materials'])): ?>
    <div class="materials-list">
        <?php foreach ($mod['materials'] as $mat): ?>
        <div class="material-item <?= !$mat['is_visible'] ? 'material-hidden' : '' ?>">
            <div class="material-icon">
                <i class="fas <?= getMaterialIcon($mat['material_type']) ?>"></i>
            </div>
            <div class="material-info">
                <strong><?= sanitize($mat['title']) ?></strong>
                <?php if ($mat['description']): ?><p><?= sanitize($mat['description']) ?></p><?php endif; ?>
                <small class="text-muted">
                    <?= ucfirst(str_replace('_', ' ', $mat['material_type'])) ?>
                    <?php if ($mat['file_size']): ?> · <?= formatFileSize($mat['file_size']) ?><?php endif; ?>
                    · Uploaded <?= date('M d, Y', strtotime($mat['created_at'])) ?>
                    <?php if (!$mat['is_visible']): ?> · <span class="text-warning">Hidden</span><?php endif; ?>
                </small>
            </div>
            <div class="material-actions">
                <?php if ($mat['file_path']): ?>
                <a href="<?= BASE_URL ?>/uploads/<?= htmlspecialchars($mat['file_path']) ?>" target="_blank" class="btn btn-sm btn-primary" title="Download">
                    <i class="fas fa-download"></i>
                </a>
                <?php elseif ($mat['external_url']): ?>
                <a href="<?= htmlspecialchars($mat['external_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-primary" title="Open Link">
                    <i class="fas fa-external-link-alt"></i>
                </a>
                <?php endif; ?>
                <button class="btn btn-sm btn-info" onclick="toggleModuleForm('edit-mat-<?= $mat['id'] ?>')" title="Edit">
                    <i class="fas fa-edit"></i>
                </button>
                <a href="<?= BASE_URL ?>/faculty/material_versions.php?id=<?= $mat['id'] ?>&section=<?= $sectionId ?>" class="btn btn-sm btn-secondary" title="Versions">
                    <i class="fas fa-history"></i>
                </a>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete material?')">
                    <input type="hidden" name="post_action" value="delete_material">
                    <input type="hidden" name="material_id" value="<?= $mat['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                </form>
            </div>
        </div>
        <!-- Edit Material Form -->
        <div id="edit-mat-<?= $mat['id'] ?>" class="collapsible-form" style="display:none">
            <form method="POST" enctype="multipart/form-data" class="inner-form">
                <input type="hidden" name="post_action" value="update_material">
                <input type="hidden" name="material_id" value="<?= $mat['id'] ?>">
                <div class="form-row">
                    <div class="form-group col-5">
                        <label>Title</label>
                        <input type="text" name="material_title" class="form-control" value="<?= sanitize($mat['title']) ?>" required>
                    </div>
                    <div class="form-group col-3">
                        <label>Type</label>
                        <select name="material_type" class="form-control">
                            <option value="syllabus"     <?= $mat['material_type']==='syllabus'?'selected':'' ?>>Syllabus</option>
                            <option value="lecture_note" <?= $mat['material_type']==='lecture_note'?'selected':'' ?>>Lecture Note</option>
                            <option value="pdf"          <?= $mat['material_type']==='pdf'?'selected':'' ?>>PDF</option>
                            <option value="presentation" <?= $mat['material_type']==='presentation'?'selected':'' ?>>Presentation</option>
                            <option value="video"        <?= $mat['material_type']==='video'?'selected':'' ?>>Video</option>
                            <option value="link"         <?= $mat['material_type']==='link'?'selected':'' ?>>Link</option>
                            <option value="other"        <?= $mat['material_type']==='other'?'selected':'' ?>>Other</option>
                        </select>
                    </div>
                    <div class="form-group col-4">
                        <label>Replace File</label>
                        <input type="file" name="material_file" class="form-control">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-5">
                        <label>External URL</label>
                        <input type="url" name="external_url" class="form-control" value="<?= sanitize($mat['external_url'] ?? '') ?>">
                    </div>
                    <div class="form-group col-4">
                        <label>Change Notes *</label>
                        <input type="text" name="change_notes" class="form-control" placeholder="Describe changes..." required>
                    </div>
                    <div class="form-group col-3">
                        <label>Visibility</label>
                        <div class="checkbox-wrap">
                            <input type="checkbox" name="is_visible" <?= $mat['is_visible'] ? 'checked' : '' ?>> Visible
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <textarea name="material_desc" class="form-control" rows="2"><?= sanitize($mat['description'] ?? '') ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Update</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p class="text-muted pl-3"><em>No materials yet. Click "Add Material" to upload.</em></p>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<?php if (empty($modules)): ?>
<div class="card"><div class="card-body text-center text-muted">No modules yet. Add one above.</div></div>
<?php endif; ?>

<?php elseif ($tab === 'students'): ?>
<div class="card">
    <div class="card-header"><h3><i class="fas fa-users"></i> Enrolled Students (<?= count($students) ?>)</h3></div>
    <div class="card-body p-0">
        <table class="table">
            <thead><tr><th>#</th><th>Name</th><th>Username</th><th>Enrolled Date</th></tr></thead>
            <tbody>
                <?php foreach ($students as $i => $st): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= sanitize($st['last_name'] . ', ' . $st['first_name']) ?></td>
                    <td><?= sanitize($st['username']) ?></td>
                    <td><?= date('M d, Y', strtotime($st['enrolled_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($students)): ?>
                <tr><td colspan="4" class="text-center text-muted">No students enrolled.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($tab === 'announcements'): ?>
<div class="card mb-4">
    <div class="card-header"><h3><i class="fas fa-bullhorn"></i> Post Announcement</h3></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="post_action" value="create_announcement">
            <div class="form-group">
                <label>Title</label>
                <input type="text" name="ann_title" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Content</label>
                <textarea name="ann_content" class="form-control" rows="4" required></textarea>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Post</button>
        </form>
    </div>
</div>
<?php foreach ($announcements as $ann): ?>
<div class="card mb-3">
    <div class="card-header">
        <strong><?= sanitize($ann['title']) ?></strong>
        <small class="text-muted"><?= date('M d, Y g:i A', strtotime($ann['created_at'])) ?></small>
    </div>
    <div class="card-body"><?= nl2br(sanitize($ann['content'])) ?></div>
</div>
<?php endforeach; ?>
<?php if (empty($announcements)): ?>
<div class="card"><div class="card-body text-center text-muted">No announcements yet.</div></div>
<?php endif; ?>

<?php elseif ($tab === 'calendar'): ?>
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
            <div class="form-group">
                <textarea name="event_desc" class="form-control" rows="2" placeholder="Description (optional)"></textarea>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Event</button>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><h3><i class="fas fa-calendar-alt"></i> Calendar</h3></div>
    <div class="card-body">
        <div id="calendar"></div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3><i class="fas fa-list"></i> Events List</h3></div>
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
<div class="card mb-3">
    <div class="card-body" style="display:flex;gap:12px;align-items:center;">
        <a href="<?= BASE_URL ?>/faculty/exams.php?section_id=<?= $sectionId ?>" class="btn btn-primary"><i class="fas fa-file-alt"></i> Manage Exams & Quizzes</a>
        <a href="<?= BASE_URL ?>/faculty/question_bank.php?section_id=<?= $sectionId ?>" class="btn btn-secondary"><i class="fas fa-database"></i> Question Bank</a>
    </div>
</div>
<?php endif; ?>

<?php if ($tab === 'assignments'): ?>
<div class="card mb-3">
    <div class="card-body" style="display:flex;gap:12px;align-items:center;">
        <a href="<?= BASE_URL ?>/faculty/assignments.php?section_id=<?= $sectionId ?>" class="btn btn-primary"><i class="fas fa-tasks"></i> Manage Assignments</a>
    </div>
</div>
<?php endif; ?>

<script>
function toggleModuleForm(id) {
    const el = document.getElementById(id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
</script>

<?php include '../includes/footer.php'; ?>

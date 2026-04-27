<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty');

$matId     = (int)($_GET['id'] ?? 0);
$sectionId = (int)($_GET['section'] ?? 0);

$material = $pdo->prepare("SELECT m.*, mod.section_id FROM materials m JOIN modules mod ON m.module_id=mod.id WHERE m.id=?");
$material->execute([$matId]);
$material = $material->fetch();

if (!$material) redirect(BASE_URL . '/faculty/sections.php');

// Verify faculty owns section
$sec = $pdo->prepare("SELECT id FROM sections WHERE id=? AND faculty_id=?");
$sec->execute([$material['section_id'], $_SESSION['user_id']]);
if (!$sec->fetch()) redirect(BASE_URL . '/faculty/sections.php');

$versions = $pdo->prepare("SELECT mv.*, u.first_name, u.last_name FROM material_versions mv JOIN users u ON mv.updated_by=u.id WHERE mv.material_id=? ORDER BY mv.version_number DESC");
$versions->execute([$matId]);
$versions = $versions->fetchAll();

$pageTitle = 'Version History - ' . $material['title'];
include '../includes/header.php';
?>

<div class="page-header">
    <a href="<?= BASE_URL ?>/faculty/section_view.php?id=<?= $sectionId ?>&tab=modules" class="btn btn-sm btn-secondary mb-2">
        <i class="fas fa-arrow-left"></i> Back
    </a>
    <h1><i class="fas fa-history"></i> Version History</h1>
    <p class="text-muted">Material: <strong><?= sanitize($material['title']) ?></strong></p>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table">
            <thead>
                <tr>
                    <th>Version</th>
                    <th>Title</th>
                    <th>Change Notes</th>
                    <th>Updated By</th>
                    <th>Date</th>
                    <th>File</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($versions as $v): ?>
                <tr <?= $v['version_number'] === max(array_column($versions, 'version_number')) ? 'class="table-row-latest"' : '' ?>>
                    <td>
                        <span class="badge badge-info">v<?= $v['version_number'] ?></span>
                        <?php if ($v['version_number'] === max(array_column($versions, 'version_number'))): ?>
                            <span class="badge badge-success">Current</span>
                        <?php endif; ?>
                    </td>
                    <td><?= sanitize($v['title']) ?></td>
                    <td><?= sanitize($v['change_notes'] ?? '-') ?></td>
                    <td><?= sanitize($v['first_name'] . ' ' . $v['last_name']) ?></td>
                    <td><?= date('M d, Y g:i A', strtotime($v['updated_at'])) ?></td>
                    <td>
                        <?php if ($v['file_path']): ?>
                        <a href="<?= BASE_URL ?>/uploads/<?= htmlspecialchars($v['file_path']) ?>" target="_blank" class="btn btn-sm btn-primary">
                            <i class="fas fa-download"></i> Download
                        </a>
                        <?php elseif ($v['external_url']): ?>
                        <a href="<?= htmlspecialchars($v['external_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-primary">
                            <i class="fas fa-external-link-alt"></i> Link
                        </a>
                        <?php else: ?>
                        <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

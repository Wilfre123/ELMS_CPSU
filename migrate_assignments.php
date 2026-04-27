<?php
require_once __DIR__ . '/config/database.php';
$sql = file_get_contents(__DIR__ . '/database/assignment_tables.sql');
$statements = array_filter(array_map('trim', explode(';', $sql)));
$errors = [];
foreach ($statements as $stmt) {
    if (empty($stmt)) continue;
    try { $pdo->exec($stmt); } catch (PDOException $e) { $errors[] = $e->getMessage(); }
}
@mkdir(UPLOAD_PATH . 'assignments', 0755, true);
// Create .htaccess to block direct HTTP access to assignment uploads
$htaccess = UPLOAD_PATH . 'assignments/.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "Order deny,allow\nDeny from all\n");
}
?><!DOCTYPE html><html><head><title>Assignment Migration</title></head>
<body style="font-family:sans-serif;max-width:640px;margin:60px auto;padding:24px;border:1px solid #ddd;border-radius:8px">
<h2>Assignment Migration</h2>
<?php if (empty($errors)): ?>
<p style="color:green;font-weight:bold">✓ Tables created. Uploads directory ready.</p>
<?php else: ?>
<p style="color:orange">Completed with notes (may be safe if tables already exist):</p>
<ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<p><a href="<?= BASE_URL ?>">← Go to ELMS</a> &nbsp;|&nbsp; <strong style="color:red">Delete this file after running!</strong></p>
</body></html>

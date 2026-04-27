<?php
// Run this once to add quiz/exam tables. Delete after use.
$host = 'localhost'; $user = 'root'; $pass = '';
try {
    $pdo = new PDO("mysql:host=$host;dbname=elms_db;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $sql = file_get_contents(__DIR__ . '/database/quiz_tables.sql');
    // Remove USE statement for PDO
    $sql = preg_replace('/USE\s+\w+;\s*/i', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt) $pdo->exec($stmt);
    }
    echo '<div style="font-family:sans-serif;max-width:600px;margin:50px auto;padding:30px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;">';
    echo '<h2 style="color:#16a34a;">✅ Quiz Tables Created!</h2>';
    echo '<p>All quiz and exam tables have been added successfully.</p>';
    echo '<p><a href="/ELMS/index.php" style="background:#2563eb;color:white;padding:10px 20px;border-radius:6px;text-decoration:none;">Go to ELMS</a></p>';
    echo '<p style="color:#dc2626;margin-top:16px;"><strong>Delete this migrate_quiz.php file now!</strong></p>';
    echo '</div>';
} catch (Exception $e) {
    echo '<div style="font-family:sans-serif;padding:30px;background:#fee2e2;">';
    echo '<h2 style="color:#dc2626;">❌ Migration Failed</h2><p>' . htmlspecialchars($e->getMessage()) . '</p></div>';
}
?>

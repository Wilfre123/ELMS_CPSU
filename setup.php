<?php
// ELMS Setup Script - Run once to create database
// After setup, delete this file!

$host = 'localhost';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    
    $sql = file_get_contents(__DIR__ . '/database/elms.sql');
    $pdo->exec($sql);
    
    echo '<div style="font-family:sans-serif;max-width:600px;margin:50px auto;padding:30px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;">';
    echo '<h2 style="color:#16a34a;">✅ ELMS Database Setup Complete!</h2>';
    echo '<p>The database has been created successfully.</p>';
    echo '<hr>';
    echo '<h3>Default Login Credentials:</h3>';
    echo '<table style="width:100%;border-collapse:collapse;">';
    echo '<tr style="background:#dcfce7"><th style="padding:8px;text-align:left;border:1px solid #86efac">Role</th><th style="padding:8px;text-align:left;border:1px solid #86efac">Username</th><th style="padding:8px;text-align:left;border:1px solid #86efac">Password</th></tr>';
    echo '<tr><td style="padding:8px;border:1px solid #e2e8f0">Dean</td><td style="padding:8px;border:1px solid #e2e8f0">dean_admin</td><td style="padding:8px;border:1px solid #e2e8f0">password</td></tr>';
    echo '<tr><td style="padding:8px;border:1px solid #e2e8f0">Secretary</td><td style="padding:8px;border:1px solid #e2e8f0">secretary_admin</td><td style="padding:8px;border:1px solid #e2e8f0">password</td></tr>';
    echo '</table>';
    echo '<p style="color:#dc2626;margin-top:20px;"><strong>⚠️ IMPORTANT:</strong> Delete this setup.php file after setup!</p>';
    echo '<p><a href="/ELMS/index.php" style="background:#2563eb;color:white;padding:10px 20px;border-radius:6px;text-decoration:none;">Go to Login Page</a></p>';
    echo '</div>';
} catch (Exception $e) {
    echo '<div style="font-family:sans-serif;max-width:600px;margin:50px auto;padding:30px;background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;">';
    echo '<h2 style="color:#dc2626;">❌ Setup Failed</h2>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '</div>';
}
?>

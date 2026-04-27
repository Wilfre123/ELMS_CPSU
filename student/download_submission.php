<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('student');

$fileId = (int)($_GET['file_id'] ?? 0);
if (!$fileId) { http_response_code(404); exit('Not found.'); }

$stmt = $pdo->prepare("SELECT sf.* FROM submission_files sf
    JOIN assignment_submissions sub ON sf.submission_id = sub.id
    WHERE sf.id = ? AND sub.student_id = ?");
$stmt->execute([$fileId, $_SESSION['user_id']]);
$file = $stmt->fetch();

if (!$file || !file_exists($file['file_path'])) {
    http_response_code(404); exit('File not found.');
}

$safeName = preg_replace('/[^\w\.\-]/', '_', $file['original_filename']);
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $safeName . '"');
header('Content-Length: ' . filesize($file['file_path']));
header('Cache-Control: no-cache');
readfile($file['file_path']);
exit;

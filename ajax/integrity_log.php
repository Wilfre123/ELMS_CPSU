<?php
session_start();
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['ok'=>false]); exit; }

$input = json_decode(file_get_contents('php://input'), true);
$attemptId = (int)($input['attempt_id'] ?? 0);
$eventType = $input['event_type'] ?? '';

$allowed = ['tab_switch','window_blur','copy_attempt','paste_attempt','right_click','fullscreen_exit'];
if (!$attemptId || !in_array($eventType, $allowed)) { http_response_code(400); echo json_encode(['ok'=>false]); exit; }

// Verify attempt belongs to this student and is in progress
$stmt = $pdo->prepare("SELECT id FROM exam_attempts WHERE id=? AND student_id=? AND status='in_progress'");
$stmt->execute([$attemptId, $_SESSION['user_id']]);
if (!$stmt->fetch()) { http_response_code(403); echo json_encode(['ok'=>false]); exit; }

$chk = $pdo->prepare("SELECT id, event_count FROM integrity_logs WHERE attempt_id=? AND event_type=?");
$chk->execute([$attemptId, $eventType]);
$existing = $chk->fetch();
if ($existing) {
    $pdo->prepare("UPDATE integrity_logs SET event_count=event_count+1, logged_at=NOW() WHERE id=?")->execute([$existing['id']]);
} else {
    $pdo->prepare("INSERT INTO integrity_logs (attempt_id, event_type) VALUES (?,?)")->execute([$attemptId, $eventType]);
}
echo json_encode(['ok' => true]);

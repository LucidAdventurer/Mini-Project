<?php
// ============================================================
// api/admin/toggle-user-status.php
// Blocks or activates a user. Remove audit_logs INSERT (table gone).
//
// POST JSON { user_id: int, action: 'block'|'activate' }
// Returns { success, is_active: bool }
// ============================================================
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';
header('Content-Type: application/json');

$adminUser = validateSession($conn, 'admin');
$adminId   = (int) $adminUser['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method not allowed.']); exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Invalid JSON.']); exit; }

$userId = (int)($body['user_id'] ?? 0);
$action = trim($body['action']   ?? '');

if ($userId <= 0 || !in_array($action, ['block','activate'], true)) {
    http_response_code(400); echo json_encode(['success'=>false,'error'=>'Invalid user_id or action.']); exit;
}
if ($userId === $adminId) {
    http_response_code(403); echo json_encode(['success'=>false,'error'=>'You cannot block your own account.']); exit;
}

$check = safePreparedQuery($conn, "SELECT user_id, is_active FROM users WHERE user_id = ?", "i", [$userId]);
if (!$check['success'] || !$check['result'] || $check['result']->num_rows === 0) {
    if ($check['result']) $check['result']->free();
    http_response_code(404); echo json_encode(['success'=>false,'error'=>'User not found.']); exit;
}
$check['result']->free();

$newActive = $action === 'activate' ? 1 : 0;
$result = safePreparedQuery($conn, "UPDATE users SET is_active = ? WHERE user_id = ?", "ii", [$newActive, $userId]);

if ($result['success']) {
    echo json_encode(['success'=>true,'is_active'=>(bool)$newActive]);
} else {
    http_response_code(500); echo json_encode(['success'=>false,'error'=>'Status update failed.']);
}
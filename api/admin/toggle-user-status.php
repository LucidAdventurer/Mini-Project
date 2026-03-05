<?php
// ============================================================
// api/admin/toggle-user-status.php
//
// Blocks (is_active=0) or activates (is_active=1) a user.
// Admin cannot block themselves.
//
// POST JSON { user_id: int, action: 'block'|'activate' }
// Returns { success, is_active: bool, error? }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

// validateSession enforces role, session existence, and CSRF on POST automatically
$adminUser = validateSession($conn, 'admin');
$adminId   = (int) $adminUser['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON.']);
    exit;
}

$userId = (int)($body['user_id'] ?? 0);
$action = trim($body['action']   ?? '');

if ($userId <= 0 || !in_array($action, ['block', 'activate'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid user_id or action.']);
    exit;
}

if ($userId === $adminId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You cannot block your own account.']);
    exit;
}

// ── Verify user exists ──
$check = safePreparedQuery($conn,
    "SELECT user_id, is_active, full_name FROM users WHERE user_id = ?",
    "i", [$userId]
);
if (!$check['success'] || !$check['result'] || $check['result']->num_rows === 0) {
    if ($check['result']) $check['result']->free();
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'User not found.']);
    exit;
}
$user = $check['result']->fetch_assoc();
$check['result']->free();

$newActive = $action === 'activate' ? 1 : 0;

$result = safePreparedQuery($conn,
    "UPDATE users SET is_active = ? WHERE user_id = ?",
    "ii", [$newActive, $userId]
);

if ($result['success']) {
    safePreparedQuery($conn,
        "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address)
         VALUES (?, ?, 'user', ?, ?, ?, ?)",
        "isiiss",
        [
            $adminId,
            $action . '_user',
            $userId,
            json_encode(['is_active' => (int)$user['is_active']]),
            json_encode(['is_active' => $newActive]),
            $_SERVER['REMOTE_ADDR'] ?? '',
        ]
    );

    echo json_encode(['success' => true, 'is_active' => (bool)$newActive]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Status update failed.']);
}
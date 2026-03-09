<?php
// ============================================================
// api/profile/change-password.php
// Any logged-in user. Changes own password securely.
//
// POST JSON { current_password, new_password, confirm_password }
// Returns   { success, error? }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$currentUser = validateSession($conn);
$userId = (int)$currentUser['user_id'];

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

$currentPw  = $body['current_password']  ?? '';
$newPw      = $body['new_password']      ?? '';
$confirmPw  = $body['confirm_password']  ?? '';

// Validate inputs
if ($currentPw === '' || $newPw === '' || $confirmPw === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'All password fields are required.']);
    exit;
}
if ($newPw !== $confirmPw) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'New passwords do not match.']);
    exit;
}
if (strlen($newPw) < 8) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'New password must be at least 8 characters.']);
    exit;
}
if (!preg_match('/[A-Z]/', $newPw) || !preg_match('/[0-9]/', $newPw)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Password must contain at least one uppercase letter and one number.']);
    exit;
}

// Fetch current hash
$r = safePreparedQuery($conn,
    "SELECT password_hash FROM users WHERE user_id = ?", "i", [$userId]);
if (!$r['success'] || !$r['result'] || $r['result']->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'User not found.']);
    exit;
}
$row = $r['result']->fetch_assoc();
$r['result']->free();

if (!password_verify($currentPw, $row['password_hash'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Current password is incorrect.']);
    exit;
}
if (password_verify($newPw, $row['password_hash'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'New password must be different from the current password.']);
    exit;
}

$newHash = password_hash($newPw, PASSWORD_DEFAULT);

$ru = safePreparedQuery($conn,
    "UPDATE users SET password_hash = ? WHERE user_id = ?", "si", [$newHash, $userId]);

if (!$ru['success']) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to update password. Please try again.']);
    exit;
}

// Audit
safePreparedQuery($conn,
    "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, user_agent)
     VALUES (?, 'change_password', 'user', ?, ?, ?)",
    "iiss",
    [$userId, $userId, $_SERVER['REMOTE_ADDR'] ?? '', substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]
);

echo json_encode(['success' => true, 'message' => 'Password changed successfully.']);

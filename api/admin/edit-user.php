<?php
// ============================================================
// api/admin/edit-user.php
//
// Updates a user's profile fields. Admin only.
// Password update is optional — only if 'password' key present.
//
// POST JSON {
//   user_id, full_name, email, role,
//   department?, registration_number?,
//   is_verified?, is_active?,
//   password?   ← optional, min 8 chars
// }
// Returns { success, error? }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

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

$userId   = (int)($body['user_id']  ?? 0);
$fullName = trim($body['full_name'] ?? '');
$email    = trim($body['email']     ?? '');
$role     = trim($body['role']      ?? '');   // column is now `role`

if ($userId <= 0 || $fullName === '' || $email === '' || $role === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'user_id, full_name, email and role are required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid email.']);
    exit;
}

$allowedRoles = ['student', 'teacher', 'admin'];
if (!in_array($role, $allowedRoles, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid role.']);
    exit;
}

if ($userId === $adminId && $role !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You cannot change your own role.']);
    exit;
}

// ── Fetch old values ──
// Select `role` not `user_type`
$old = safePreparedQuery($conn,
    "SELECT full_name, email, role, department, registration_number, is_verified, is_active
     FROM users WHERE user_id = ?",
    "i", [$userId]
);
if (!$old['success'] || !$old['result'] || $old['result']->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'User not found.']);
    exit;
}
$oldData = $old['result']->fetch_assoc();
$old['result']->free();

// ── Email uniqueness ──
$dup = safePreparedQuery($conn,
    "SELECT user_id FROM users WHERE email = ? AND user_id != ? LIMIT 1", "si", [$email, $userId]);
if ($dup['success'] && $dup['result'] && $dup['result']->num_rows > 0) {
    $dup['result']->free();
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'Email already in use by another account.']);
    exit;
}
if ($dup['result']) $dup['result']->free();

// ── Optional fields ──
$department         = trim($body['department']          ?? '') ?: null;
$registrationNumber = trim($body['registration_number'] ?? '') ?: null;
$isVerified         = isset($body['is_verified']) ? (int)(bool)$body['is_verified'] : (int)$oldData['is_verified'];
$isActive           = isset($body['is_active'])   ? (int)(bool)$body['is_active']   : (int)$oldData['is_active'];

if ($userId === $adminId && !$isActive) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You cannot deactivate your own account.']);
    exit;
}

// ── Build UPDATE — column is `role` ──
$setClauses = [
    'full_name'           => $fullName,
    'email'               => $email,
    'role'                => $role,
    'department'          => $department,
    'registration_number' => $registrationNumber,
    'is_verified'         => $isVerified,
    'is_active'           => $isActive,
];

$newPassword = trim($body['password'] ?? '');
if ($newPassword !== '') {
    if (strlen($newPassword) < 8) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'New password must be at least 8 characters.']);
        exit;
    }
    $setClauses['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
}

$setParts = [];
$values   = [];
foreach ($setClauses as $col => $val) {
    $setParts[] = "`$col` = ?";
    $values[]   = $val;
}
$values[] = $userId;
$types    = str_repeat('s', count($values) - 1) . 'i';

$result = safePreparedQuery($conn,
    "UPDATE users SET " . implode(', ', $setParts) . " WHERE user_id = ?",
    $types, $values
);

if ($result['success'] && $result['affected_rows'] >= 0) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Update failed. Please try again.']);
}
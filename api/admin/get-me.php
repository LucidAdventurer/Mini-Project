<?php
// ============================================================
// api/admin/get-me.php
//
// Returns the currently logged-in admin's basic profile info.
// Used by the dashboard topbar and profile page.
//
// GET
// Returns { success: bool, user: { user_id, full_name, email, department } }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$currentUser = validateSession($conn);
$userId      = (int) $currentUser['user_id'];

$r = safePreparedQuery($conn,
    "SELECT user_id, full_name, email, department
     FROM users WHERE user_id = ? AND is_active = 1",
    "i", [$userId]
);

if (!$r['success'] || !$r['result'] || $r['result']->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'User not found.']);
    exit;
}

$user = $r['result']->fetch_assoc();
$r['result']->free();

echo json_encode([
    'success' => true,
    'user'    => [
        'user_id'    => (int) $user['user_id'],
        'full_name'  => $user['full_name'],
        'email'      => $user['email'],
        'department' => $user['department'],
    ],
]);
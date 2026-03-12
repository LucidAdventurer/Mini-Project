<?php
// ============================================================
// api/notifications/mark-read.php
//
// Marks all unread notifications as read for the logged-in user.
// POST (no body needed — user is identified from session)
// Returns { success: bool }
// ============================================================
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$currentUser = validateSession($conn);
$userId      = (int) $currentUser['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$r = safePreparedQuery($conn,
    "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0",
    "i", [$userId]
);

echo json_encode(['success' => $r['success']]);
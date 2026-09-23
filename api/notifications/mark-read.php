<?php
/* ========================================
 * API: MARK ALL NOTIFICATIONS AS READ
 * File: api/notifications/mark-read.php
 *
 * Marks all unread notifications for the
 * authenticated student as read.
 *
 * Returns JSON: { success }
 * ======================================== */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CSRF validation
$sentToken    = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';

if ($sessionToken === '' || $sentToken === '' || !hash_equals($sessionToken, $sentToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$user = validateSession($conn, 'student');
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = (int) $user['user_id'];

$res = safePreparedQuery(
    $conn,
    "UPDATE notifications
     SET is_read = TRUE
     WHERE user_id = ?
       AND is_read = FALSE",
    "i",
    [$userId]
);

echo json_encode(['success' => (bool) ($res['success'] ?? false)]);

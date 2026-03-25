<?php
/* ========================================
 * API: MARK ALL NOTIFICATIONS AS READ
 * File: api/notifications/mark-read.php
 *
 * Called when the student opens the notification bell dropdown.
 * Marks all unread notifications for the user as read.
 *
 * Returns JSON: { success }
 * ======================================== */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$user = validateSession($conn, 'student');
if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = (int) $user['user_id'];

$res = safePreparedQuery($conn,
    "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0",
    "i", [$userId]
);

echo json_encode(['success' => $res['success']]);

<?php
/* ========================================
 * API: UNREAD NOTIFICATION COUNT
 * File: api/notifications/unread-count.php
 *
 * Called every 30 seconds by the polling interval on all pages.
 * Returns the current unread notification count for the badge.
 *
 * Returns JSON: { success, count }
 * ======================================== */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$user = validateSession($conn, 'student');
if (!$user) {
    echo json_encode(['success' => false, 'count' => 0]);
    exit;
}

$userId = (int) $user['user_id'];

$res = safePreparedQuery($conn,
    "SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0",
    "i", [$userId]
);

$count = 0;
if ($res['success'] && $res['result']) {
    $row   = $res['result']->fetch_assoc();
    $count = (int)($row['cnt'] ?? 0);
    $res['result']->free();
}

echo json_encode(['success' => true, 'count' => $count]);

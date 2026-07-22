<?php
/* ========================================
 * API: ACTIVE NOTIFICATION IDs
 * File: api/notifications/active-ids.php
 *
 * Returns the IDs of all notifications that currently
 * exist in the DB for this user, plus the unread count.
 *
 * Called every 30s by the client to sync the DOM —
 * any notification rendered on the page whose ID is
 * NOT in this list gets removed from the DOM silently.
 *
 * Returns JSON: { success, ids: [1,2,3,...], unread_count: N }
 * ======================================== */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$user = validateSession($conn, 'student');
if (!$user) {
    echo json_encode(['success' => false, 'ids' => [], 'unread_count' => 0]);
    exit;
}

$userId = (int) $user['user_id'];

$res = safePreparedQuery($conn,
    "SELECT notification_id, is_read FROM notifications WHERE user_id = ?",
    "i", [$userId]
);

$ids         = [];
$unreadCount = 0;

if ($res['success'] && $res['result']) {
    while ($row = $res['result']->fetch_assoc()) {
        $ids[] = (int) $row['notification_id'];
        if (!pgBoolGuard($row['is_read'])) $unreadCount++;
    }
    $res['result']->free();
}

echo json_encode([
    'success'      => true,
    'ids'          => $ids,
    'unread_count' => $unreadCount,
]);

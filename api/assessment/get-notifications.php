<?php
// ============================================================
// api/notifications/get-notifications.php
//
// Returns unread + recent notifications for the logged-in user.
//
// GET ?unread_only=1   (default: returns all recent)
//     ?limit=20        (default 20, max 50)
//
// Returns {
//   success: bool,
//   notifications: [...],
//   unread_count: int
// }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$currentUser = validateSession($conn);
$userId      = (int) $currentUser['user_id'];

$unreadOnly = !empty($_GET['unread_only']);
$limit      = min(50, max(1, (int)($_GET['limit'] ?? 20)));

// ── Fetch notifications ──
$whereClause = $unreadOnly ? "WHERE user_id = ? AND is_read = 0" : "WHERE user_id = ?";

$r = safePreparedQuery($conn,
    "SELECT notification_id, title, message, notification_type,
            related_entity_type, related_entity_id,
            is_read, read_at, action_url, created_at
     FROM notifications
     $whereClause
     ORDER BY created_at DESC
     LIMIT $limit",
    "i", [$userId]
);

$notifications = [];
if ($r['success'] && $r['result']) {
    while ($row = $r['result']->fetch_assoc()) {
        $notifications[] = [
            'notification_id'     => (int)$row['notification_id'],
            'title'               => $row['title'],
            'message'             => $row['message'],
            'notification_type'   => $row['notification_type'],
            'related_entity_type' => $row['related_entity_type'],
            'related_entity_id'   => $row['related_entity_id'] ? (int)$row['related_entity_id'] : null,
            'is_read'             => (bool)$row['is_read'],
            'action_url'          => $row['action_url'],
            'created_at'          => $row['created_at'],
            'time_ago'            => timeAgo($row['created_at']),
        ];
    }
    $r['result']->free();
}

// ── Unread count (always fresh) ──
$unreadCount = 0;
$rc = safePreparedQuery($conn,
    "SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0",
    "i", [$userId]
);
if ($rc['success'] && $rc['result']) {
    $row = $rc['result']->fetch_assoc();
    $unreadCount = (int)($row['cnt'] ?? 0);
    $rc['result']->free();
}

echo json_encode([
    'success'       => true,
    'notifications' => $notifications,
    'unread_count'  => $unreadCount,
]);

// ── Helper ──
function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)          return 'just now';
    if ($diff < 3600)        return floor($diff / 60) . 'm ago';
    if ($diff < 86400)       return floor($diff / 3600) . 'h ago';
    if ($diff < 2592000)     return floor($diff / 86400) . 'd ago';
    return date('M j', strtotime($datetime));
}
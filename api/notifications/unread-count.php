<?php
// ============================================================
// api/notifications/unread-count.php
//
// Returns the current unread notification count for the
// logged-in user. Used for live badge polling.
//
// GET (no body needed)
// Returns { success: bool, count: int }
// ============================================================
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$currentUser = validateSession($conn);
$userId      = (int) $currentUser['user_id'];

$r = safePreparedQuery($conn,
    "SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0",
    "i", [$userId]
);

if ($r['success'] && $r['result']) {
    $row = $r['result']->fetch_assoc();
    $r['result']->free();
    echo json_encode(['success' => true, 'count' => (int)($row['cnt'] ?? 0)]);
} else {
    echo json_encode(['success' => false, 'count' => 0]);
}

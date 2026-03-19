<?php
/* ========================================
 * NOTIFICATION UNREAD COUNT API
 * File: api/notifications/unread-count.php
 *
 * Returns the number of unread notifications for the current student.
 *
 * Optional: ?with_latest=1
 *   Also returns the single most recent unread notification row so the
 *   frontend can show a popup / inject it into the dropdown without a
 *   second request.
 *
 * Response:
 * {
 *   "success": true,
 *   "count": 3,
 *   "latest": {              ← only when with_latest=1 and count > 0
 *     "notification_id": 42,
 *     "title": "New Resource",
 *     "message": "...",
 *     "type": "material",
 *     "material_id": 17,
 *     "link_url": "student-resources.php",
 *     "created_at": "2026-03-19 10:42:00"
 *   }
 * }
 * ======================================== */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$currentUser = validateSession($conn, 'student');
$userId      = (int) $currentUser['user_id'];

// ── Unread count ──
$countResult = safePreparedQuery(
    $conn,
    "SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0",
    "i",
    [$userId]
);

$count = 0;
if ($countResult['success'] && $countResult['result']) {
    $row   = $countResult['result']->fetch_assoc();
    $count = (int) ($row['cnt'] ?? 0);
    $countResult['result']->free();
}

$response = ['success' => true, 'count' => $count];

// ── Latest unread (optional) ──
$withLatest = isset($_GET['with_latest']) && $_GET['with_latest'] === '1';

if ($withLatest && $count > 0) {
    $latestResult = safePreparedQuery(
        $conn,
        "SELECT notification_id, title, message, type, material_id, link_url, created_at
         FROM notifications
         WHERE user_id = ? AND is_read = 0
         ORDER BY created_at DESC
         LIMIT 1",
        "i",
        [$userId]
    );

    if ($latestResult['success'] && $latestResult['result']) {
        $latest = $latestResult['result']->fetch_assoc();
        $latestResult['result']->free();

        if ($latest) {
            // Ensure material_id is int or null (not string '0')
            $latest['material_id'] = $latest['material_id'] ? (int) $latest['material_id'] : null;
            $response['latest']    = $latest;
        }
    }
}

echo json_encode($response);

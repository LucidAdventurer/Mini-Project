<?php
/* ========================================
 * API: DISMISS NOTIFICATION
 * File: api/notifications/dismiss-notification.php
 *
 * Handles two use cases:
 *
 *  A) Manual dismiss (X button)
 *     POST { action: "dismiss_one", notification_id: 123 }
 *     → Deletes that single notification row
 *     → Returns { success, unread_count }
 *
 *  B) Action-based dismiss (clicking a notification item)
 *     POST { action: "assessment_done", assessment_id: 45 }
 *     → Deletes all assessment notifications for that assessment
 *     POST { action: "resource_viewed", material_id: 12 }
 *     → Deletes all material notifications for that resource
 *
 * All deletions are scoped to the logged-in user only.
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

// Parse JSON body
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = trim($body['action'] ?? '');

$success = false;

switch ($action) {

    // ── A. Dismiss a single notification by its ID ──
    case 'dismiss_one':
        $notifId = (int)($body['notification_id'] ?? 0);
        if ($notifId > 0) {
            $res = safePreparedQuery($conn,
                "DELETE FROM notifications WHERE notification_id = ? AND user_id = ?",
                "ii", [$notifId, $userId]
            );
            $success = $res['success'];
        }
        break;

    // ── B. Student opened/started an assessment ──
    case 'assessment_done':
        $assessmentId = (int)($body['assessment_id'] ?? 0);
        if ($assessmentId > 0) {
            $res = safePreparedQuery($conn,
                "DELETE FROM notifications
                 WHERE user_id = ? AND type = 'assessment' AND related_entity_id = ?",
                "ii", [$userId, $assessmentId]
            );
            $success = $res['success'];
        }
        break;

    // ── C. Student viewed a resource ──
    case 'resource_viewed':
        $materialId = (int)($body['material_id'] ?? 0);
        if ($materialId > 0) {
            $res = safePreparedQuery($conn,
                "DELETE FROM notifications
                 WHERE user_id = ? AND type = 'material' AND related_entity_id = ?",
                "ii", [$userId, $materialId]
            );
            $success = $res['success'];
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
        exit;
}

// Return updated unread count so badge can be updated
$countRes    = safePreparedQuery($conn,
    "SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = false",
    "i", [$userId]
);
$unreadCount = 0;
if ($countRes['success'] && $countRes['result']) {
    $row         = $countRes['result']->fetch_assoc();
    $unreadCount = (int)($row['cnt'] ?? 0);
    $countRes['result']->free();
}

echo json_encode([
    'success'      => $success,
    'unread_count' => $unreadCount,
]);

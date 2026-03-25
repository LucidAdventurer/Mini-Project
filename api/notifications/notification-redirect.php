<?php
/* ========================================
 * API: NOTIFICATION REDIRECT (with validation)
 * File: api/notifications/notification-redirect.php
 *
 * Called when a student clicks a notification item.
 * Validates the linked entity still exists before redirecting.
 *
 *  GET ?notification_id=123
 *
 * Behaviour:
 *  - type = 'assessment' → check assessment exists, is published,
 *    not expired → redirect to test-preview.php?id=X
 *    else → delete notification + redirect to student-assessments.php
 *           with a flash message
 *
 *  - type = 'material'   → check material exists
 *    → redirect to student-resources.php
 *    else → delete notification + redirect to student-resources.php
 *
 *  - type = 'result'     → check attempt exists
 *    → redirect to test-results.php?attempt_id=X
 *    else → delete notification + redirect to student-assessments.php
 *
 * Also marks the notification as read and optionally deletes it.
 * ======================================== */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

$user = validateSession($conn, 'student');
if (!$user) {
    header('Location: ../../login.php');
    exit;
}

$userId     = (int) $user['user_id'];
$notifId    = (int)($_GET['notification_id'] ?? 0);

if ($notifId <= 0) {
    header('Location: ../../student-dashboard.php');
    exit;
}

// Fetch the notification (must belong to this user)
$notifRes = safePreparedQuery($conn,
    "SELECT notification_id, type, related_entity_id, is_read
     FROM notifications
     WHERE notification_id = ? AND user_id = ?",
    "ii", [$notifId, $userId]
);

if (!$notifRes['success'] || !$notifRes['result']) {
    header('Location: ../../student-dashboard.php');
    exit;
}

$notif = $notifRes['result']->fetch_assoc();
$notifRes['result']->free();

if (!$notif) {
    header('Location: ../../student-dashboard.php');
    exit;
}

$type     = $notif['type'] ?? '';
$entityId = (int)($notif['related_entity_id'] ?? 0);

// Mark as read
safePreparedQuery($conn,
    "UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?",
    "ii", [$notifId, $userId]
);

// ── Validate entity and redirect ──
switch ($type) {

    case 'assessment':
        if ($entityId > 0) {
            $aRes = safePreparedQuery($conn,
                "SELECT assessment_id FROM assessments
                 WHERE assessment_id = ?
                   AND status = 'published'
                   AND (end_time IS NULL OR end_time > NOW())",
                "i", [$entityId]
            );
            $exists = false;
            if ($aRes['success'] && $aRes['result']) {
                $exists = (bool) $aRes['result']->fetch_assoc();
                $aRes['result']->free();
            }

            if ($exists) {
                // Delete notification so it won't appear again after clicking
                safePreparedQuery($conn,
                    "DELETE FROM notifications WHERE notification_id = ? AND user_id = ?",
                    "ii", [$notifId, $userId]
                );
                header('Location: ../../test-preview.php?id=' . $entityId);
                exit;
            }
        }
        // Assessment gone — clean up notification and show message
        safePreparedQuery($conn,
            "DELETE FROM notifications WHERE notification_id = ? AND user_id = ?",
            "ii", [$notifId, $userId]
        );
        header('Location: ../../student-assessments.php?notif_stale=1');
        exit;

    case 'material':
        if ($entityId > 0) {
            $mRes = safePreparedQuery($conn,
                "SELECT material_id FROM materials WHERE material_id = ?",
                "i", [$entityId]
            );
            $exists = false;
            if ($mRes['success'] && $mRes['result']) {
                $exists = (bool) $mRes['result']->fetch_assoc();
                $mRes['result']->free();
            }

            if ($exists) {
                // Delete notification after redirect (resource viewed)
                safePreparedQuery($conn,
                    "DELETE FROM notifications WHERE notification_id = ? AND user_id = ?",
                    "ii", [$notifId, $userId]
                );
                header('Location: ../../student-resources.php?material_id=' . $entityId);
                exit;
            }
        }
        // Resource gone
        safePreparedQuery($conn,
            "DELETE FROM notifications WHERE notification_id = ? AND user_id = ?",
            "ii", [$notifId, $userId]
        );
        header('Location: ../../student-resources.php?notif_stale=1');
        exit;

    case 'result':
        if ($entityId > 0) {
            $rRes = safePreparedQuery($conn,
                "SELECT attempt_id FROM assessment_attempts
                 WHERE attempt_id = ? AND user_id = ? AND status = 'submitted'",
                "ii", [$entityId, $userId]
            );
            $exists = false;
            if ($rRes['success'] && $rRes['result']) {
                $exists = (bool) $rRes['result']->fetch_assoc();
                $rRes['result']->free();
            }

            if ($exists) {
                safePreparedQuery($conn,
                    "DELETE FROM notifications WHERE notification_id = ? AND user_id = ?",
                    "ii", [$notifId, $userId]
                );
                header('Location: ../../test-results.php?attempt_id=' . $entityId);
                exit;
            }
        }
        safePreparedQuery($conn,
            "DELETE FROM notifications WHERE notification_id = ? AND user_id = ?",
            "ii", [$notifId, $userId]
        );
        header('Location: ../../student-assessments.php?notif_stale=1');
        exit;

    default:
        header('Location: ../../student-dashboard.php');
        exit;
}

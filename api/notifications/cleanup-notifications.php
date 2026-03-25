<?php
/* ========================================
 * API: CLEANUP NOTIFICATIONS
 * File: api/notifications/cleanup-notifications.php
 *
 * Called automatically on every page load.
 * Deletes stale notifications for the logged-in student:
 *
 *  1. Assessment notifications whose assessment was DELETED
 *  2. Assessment notifications whose assessment end_time has EXPIRED
 *     (and the student never attempted it)
 *  3. Assessment notifications where the student already COMPLETED
 *     all allowed attempts (no longer actionable)
 *  4. Resource/material notifications whose resource was DELETED
 *
 * Returns JSON: { success, deleted }
 * ======================================== */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

// Must be a logged-in student
$user = validateSession($conn, 'student');
if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = (int) $user['user_id'];
$deleted = 0;

// ──────────────────────────────────────────────────────────────
// 1. Remove assessment notifications where the assessment no
//    longer exists (teacher deleted it) OR end_time has passed
// ──────────────────────────────────────────────────────────────
$staleAssessmentResult = safePreparedQuery($conn,
    "SELECT n.notification_id
     FROM notifications n
     WHERE n.user_id = ?
       AND n.type = 'assessment'
       AND n.related_entity_id IS NOT NULL
       AND NOT EXISTS (
           SELECT 1 FROM assessments a
           WHERE a.assessment_id = n.related_entity_id
             AND a.status = 'published'
             AND (a.end_time IS NULL OR a.end_time > NOW())
       )",
    "i", [$userId]
);

$staleAssessmentIds = [];
if ($staleAssessmentResult['success'] && $staleAssessmentResult['result']) {
    while ($row = $staleAssessmentResult['result']->fetch_assoc()) {
        $staleAssessmentIds[] = (int) $row['notification_id'];
    }
    $staleAssessmentResult['result']->free();
}

// ──────────────────────────────────────────────────────────────
// 2. Remove assessment notifications where the student has
//    used all allowed attempts (notification is no longer useful)
// ──────────────────────────────────────────────────────────────
$exhaustedResult = safePreparedQuery($conn,
    "SELECT n.notification_id
     FROM notifications n
     JOIN assessments a ON a.assessment_id = n.related_entity_id
     WHERE n.user_id = ?
       AND n.type = 'assessment'
       AND n.related_entity_id IS NOT NULL
       AND (
           SELECT COUNT(*) FROM assessment_attempts aa
           WHERE aa.assessment_id = n.related_entity_id
             AND aa.user_id = ?
             AND aa.status = 'submitted'
       ) >= a.max_attempts",
    "ii", [$userId, $userId]
);

$exhaustedIds = [];
if ($exhaustedResult['success'] && $exhaustedResult['result']) {
    while ($row = $exhaustedResult['result']->fetch_assoc()) {
        $exhaustedIds[] = (int) $row['notification_id'];
    }
    $exhaustedResult['result']->free();
}

// ──────────────────────────────────────────────────────────────
// 3. Remove resource/material notifications where the resource
//    no longer exists (teacher deleted it)
// ──────────────────────────────────────────────────────────────
$staleResourceResult = safePreparedQuery($conn,
    "SELECT n.notification_id
     FROM notifications n
     WHERE n.user_id = ?
       AND n.type = 'material'
       AND n.related_entity_id IS NOT NULL
       AND NOT EXISTS (
           SELECT 1 FROM materials m
           WHERE m.material_id = n.related_entity_id
       )",
    "i", [$userId]
);

$staleResourceIds = [];
if ($staleResourceResult['success'] && $staleResourceResult['result']) {
    while ($row = $staleResourceResult['result']->fetch_assoc()) {
        $staleResourceIds[] = (int) $row['notification_id'];
    }
    $staleResourceResult['result']->free();
}

// ──────────────────────────────────────────────────────────────
// 4. Remove result notifications where the attempt no longer
//    exists (edge case: attempt was purged)
// ──────────────────────────────────────────────────────────────
$staleResultResult = safePreparedQuery($conn,
    "SELECT n.notification_id
     FROM notifications n
     WHERE n.user_id = ?
       AND n.type = 'result'
       AND n.related_entity_id IS NOT NULL
       AND NOT EXISTS (
           SELECT 1 FROM assessment_attempts aa
           WHERE aa.attempt_id = n.related_entity_id
             AND aa.user_id = ?
       )",
    "ii", [$userId, $userId]
);

$staleResultIds = [];
if ($staleResultResult['success'] && $staleResultResult['result']) {
    while ($row = $staleResultResult['result']->fetch_assoc()) {
        $staleResultIds[] = (int) $row['notification_id'];
    }
    $staleResultResult['result']->free();
}

// ──────────────────────────────────────────────────────────────
// Merge all IDs to delete and run a single DELETE
// ──────────────────────────────────────────────────────────────
$allStaleIds = array_unique(array_merge(
    $staleAssessmentIds,
    $exhaustedIds,
    $staleResourceIds,
    $staleResultIds
));

if (!empty($allStaleIds)) {
    $placeholders = implode(',', array_fill(0, count($allStaleIds), '?'));
    $types        = str_repeat('i', count($allStaleIds));

    $deleteResult = safePreparedQuery($conn,
        "DELETE FROM notifications WHERE notification_id IN ($placeholders) AND user_id = ?",
        $types . 'i',
        array_merge($allStaleIds, [$userId])
    );

    if ($deleteResult['success']) {
        $deleted = is_int($deleteResult['result']) ? $deleteResult['result'] : count($allStaleIds);
    }
}

echo json_encode([
    'success' => true,
    'deleted' => $deleted,
]);

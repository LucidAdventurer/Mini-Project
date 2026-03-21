<?php
// ============================================================
// api/notifications/cleanup-notifications.php
//
// Smart notification cleanup — called silently on page load.
//
// RULES:
//  1. Assessment notifications:
//     - Student completed/submitted the test → DELETE notification
//     - Due date passed without attempt → DELETE notification
//
//  2. Material (resource) notifications:
//     - Student viewed the resource → DELETE notification
//     - Material availability ended (available_until passed) → DELETE
//     - Material no longer public/exists → DELETE
//
// Called via POST (fire-and-forget fetch from dashboard/resources page).
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false]);
    exit;
}

// ── Auth ──────────────────────────────────────────────────────────────────
$sessionRole = $_SESSION['role'] ?? $_SESSION['user_type'] ?? '';
$userId      = (int)($_SESSION['user_id'] ?? 0);
if ($userId === 0 || !in_array($sessionRole, ['student', 'teacher', 'admin'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false]);
    exit;
}

$deleted = 0;

// ── 1. Assessment notifications ───────────────────────────────────────────
// Delete if: student already submitted OR assessment due date has passed
$assessmentCleanup = safePreparedQuery($conn,
    "DELETE n FROM notifications n
     WHERE n.user_id = ?
       AND n.type    = 'assessment'
       AND n.related_entity_id IS NOT NULL
       AND (
           -- Student already submitted this assessment
           EXISTS (
               SELECT 1 FROM assessment_attempts aa
               WHERE aa.assessment_id = n.related_entity_id
                 AND aa.user_id       = n.user_id
                 AND aa.status        = 'submitted'
           )
           OR
           -- Due date has passed (end_time in the past)
           EXISTS (
               SELECT 1 FROM assessments a
               WHERE a.assessment_id = n.related_entity_id
                 AND a.end_time IS NOT NULL
                 AND a.end_time < NOW()
           )
           OR
           -- Assessment no longer exists or not published
           NOT EXISTS (
               SELECT 1 FROM assessments a
               WHERE a.assessment_id = n.related_entity_id
                 AND a.status = 'published'
           )
       )",
    "i", [$userId]
);
if ($assessmentCleanup['success']) {
    $deleted += $assessmentCleanup['affected_rows'];
}

// ── 2. Material (resource) notifications ─────────────────────────────────
// Delete if: student viewed it, availability ended, or material gone/private
$materialCleanup = safePreparedQuery($conn,
    "DELETE n FROM notifications n
     WHERE n.user_id = ?
       AND n.type    = 'material'
       AND n.related_entity_id IS NOT NULL
       AND (
           -- Material no longer exists or no longer public
           NOT EXISTS (
               SELECT 1 FROM materials m
               WHERE m.material_id = n.related_entity_id
                 AND m.visibility  = 'public'
           )
           OR
           -- Availability window has ended (if available_until column exists)
           EXISTS (
               SELECT 1 FROM materials m
               WHERE m.material_id    = n.related_entity_id
                 AND m.available_until IS NOT NULL
                 AND m.available_until < NOW()
           )
       )",
    "i", [$userId]
);
if ($materialCleanup['success']) {
    $deleted += $materialCleanup['affected_rows'];
}

// ── 3. General stale notifications (older than 30 days, already read) ────
$staleCleanup = safePreparedQuery($conn,
    "DELETE FROM notifications
     WHERE user_id  = ?
       AND is_read  = 1
       AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
    "i", [$userId]
);
if ($staleCleanup['success']) {
    $deleted += $staleCleanup['affected_rows'];
}

echo json_encode(['success' => true, 'deleted' => $deleted]);

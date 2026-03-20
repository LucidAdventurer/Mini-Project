<?php
// ============================================================
// api/notifications/dismiss-notification.php
//
// Deletes notifications based on three rules:
//
//  1. ASSESSMENT DONE  — deletes all 'assessment' notifications
//                        for a given assessment_id for this user
//
//  2. RESOURCE VIEWED  — deletes the 'material' notification
//                        for a given material_id for this user
//
//  3. THREE-DAY PURGE  — deletes ALL notifications older than
//                        3 days for this user (runs automatically
//                        on every call, no extra param needed)
//
// POST JSON — one of:
//   { "action": "assessment_done", "assessment_id": 5  }
//   { "action": "resource_viewed", "material_id":   12 }
//
// Returns { success: bool, deleted: int }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$currentUser = validateSession($conn);
$userId      = (int) $currentUser['user_id'];

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body.']);
    exit;
}

$action  = $body['action'] ?? '';
$deleted = 0;

// ── Rule 3: Always purge notifications older than 3 days for this user ──
$purge = safePreparedQuery(
    $conn,
    "DELETE FROM notifications
     WHERE user_id = ? AND created_at < NOW() - INTERVAL 3 DAY",
    'i', [$userId]
);
if ($purge['success']) {
    $deleted += max(0, (int)($purge['affected_rows'] ?? 0));
}

// ── Rule 1: Assessment done → remove assessment notification ──
if ($action === 'assessment_done') {
    $assessmentId = (int)($body['assessment_id'] ?? 0);
    if ($assessmentId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid assessment_id.']);
        exit;
    }

    $del = safePreparedQuery(
        $conn,
        "DELETE FROM notifications
         WHERE user_id = ?
           AND type = 'assessment'
           AND related_entity_id = ?",
        'ii', [$userId, $assessmentId]
    );
    if ($del['success']) {
        $deleted += max(0, (int)($del['affected_rows'] ?? 0));
    }
}

// ── Rule 2: Resource viewed → remove material notification ──
elseif ($action === 'resource_viewed') {
    $materialId = (int)($body['material_id'] ?? 0);
    if ($materialId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid material_id.']);
        exit;
    }

    $del = safePreparedQuery(
        $conn,
        "DELETE FROM notifications
         WHERE user_id = ?
           AND type = 'material'
           AND related_entity_id = ?",
        'ii', [$userId, $materialId]
    );
    if ($del['success']) {
        $deleted += max(0, (int)($del['affected_rows'] ?? 0));
    }
}

elseif ($action !== '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    exit;
}

echo json_encode(['success' => true, 'deleted' => $deleted]);

<?php
// ============================================================
// api/assessment/update-status.php
//
// Toggles an assessment's status between 'published' and 'draft'.
// Also allows 'archived' as a valid target.
//
// When status transitions TO 'published' for the first time,
// notifies all eligible students via notifyAssessmentPublished().
//
// POST JSON { assessment_id: int, status: string }
// Returns   { success: bool, status?: string, error?: string }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';
require_once __DIR__ . '/../notify-helpers.php';

header('Content-Type: application/json');

$currentUser = validateSession($conn, 'teacher');
$teacherId   = (int) $currentUser['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body.']);
    exit;
}

$assessmentId = (int)($body['assessment_id'] ?? 0);
$newStatus    = trim($body['status'] ?? '');

if ($assessmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid assessment ID.']);
    exit;
}

// 'scheduled' does not exist in the live schema
$allowedStatuses = ['active', 'draft', 'archived'];
if (!in_array($newStatus, $allowedStatuses, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid status. Allowed: published, draft, archived.']);
    exit;
}

// ── Verify ownership + fetch current status ──
$check = safePreparedQuery($conn,
    "SELECT assessment_id, status FROM assessments WHERE assessment_id = ? AND created_by = ?",
    "ii", [$assessmentId, $teacherId]
);

if (!$check['success'] || !$check['result'] || $check['result']->num_rows === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Assessment not found or access denied.']);
    exit;
}
$row       = $check['result']->fetch_assoc();
$oldStatus = $row['status'];
$check['result']->free();

if ($oldStatus === $newStatus) {
    echo json_encode(['success' => true, 'status' => $newStatus]);
    exit;
}

// ── Update status ──
$result = safePreparedQuery($conn,
    "UPDATE assessments SET status = ?, updated_at = NOW() WHERE assessment_id = ? AND created_by = ?",
    "sii", [$newStatus, $assessmentId, $teacherId]
);

if (!$result['success'] || $result['affected_rows'] === 0) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Status update failed. Please try again.']);
    exit;
}

// ── Notify students when assessment is published ──
// Only fires on transition TO 'published'.
// Uses INSERT IGNORE so toggling published→draft→published
// does not send duplicate notifications.
if ($newStatus === 'published' && $oldStatus !== 'published') {
    notifyAssessmentPublished($conn, $assessmentId);
}

echo json_encode(['success' => true, 'status' => $newStatus]);

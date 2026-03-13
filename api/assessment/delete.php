<?php
// ============================================================
// api/assessment/delete.php
//
// Deletes an assessment owned by the logged-in teacher.
// Cascades to questions + attempts via FK ON DELETE CASCADE.
// POST { assessment_id: int }
// Returns { success: bool, error?: string }
// ============================================================
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$conn = createDatabaseConnection();
if (!$conn) { http_response_code(503); echo json_encode(['success'=>false,'error'=>'Database unavailable.']); exit; }

$currentUser = validateSession($conn, 'teacher');
$teacherId   = (int) $currentUser['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$body         = json_decode(file_get_contents('php://input'), true);
$assessmentId = (int)($body['assessment_id'] ?? 0);

if ($assessmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid assessment ID.']);
    exit;
}

// ── Verify ownership before deleting ──
$check = safePreparedQuery($conn,
    "SELECT assessment_id FROM assessments WHERE assessment_id = ? AND created_by = ?",
    "ii", [$assessmentId, $teacherId]
);

if (!$check['success'] || !$check['result'] || $check['result']->num_rows === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Assessment not found or access denied.']);
    exit;
}
$check['result']->free();

// ── Delete (FK CASCADE handles questions + attempts) ──
$del = safePreparedQuery($conn,
    "DELETE FROM assessments WHERE assessment_id = ? AND created_by = ?",
    "ii", [$assessmentId, $teacherId]
);

if ($del['success'] && $del['affected_rows'] > 0) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Delete failed. Please try again or contact your administrator.']);
}
<?php
// ============================================================
// api/admin/update-test-status.php
//
// Admin can change any assessment's status.
// Unlike the teacher endpoint, admin is NOT restricted to
// assessments they created — they manage all.
//
// POST { assessment_id: int, status: string }
// Returns { success: bool, status?: string }
// ============================================================
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');
validateSession($conn, 'admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$body         = json_decode(file_get_contents('php://input'), true);
$assessmentId = (int)($body['assessment_id'] ?? 0);
$newStatus    = trim($body['status'] ?? '');

if ($assessmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid assessment ID.']);
    exit;
}

$allowed = ['active', 'draft', 'archived', 'scheduled'];
if (!in_array($newStatus, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid status. Allowed: ' . implode(', ', $allowed)]);
    exit;
}

// Verify assessment exists
$check = safePreparedQuery($conn,
    "SELECT assessment_id, status FROM assessments WHERE assessment_id = ?",
    "i", [$assessmentId]
);
if (!$check['success'] || !$check['result'] || $check['result']->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Assessment not found.']);
    exit;
}
$old = $check['result']->fetch_assoc();
$check['result']->free();

if ($old['status'] === $newStatus) {
    echo json_encode(['success' => true, 'status' => $newStatus]);
    exit;
}

$r = safePreparedQuery($conn,
    "UPDATE assessments SET status = ?, updated_at = NOW() WHERE assessment_id = ?",
    "si", [$newStatus, $assessmentId]
);

if ($r['success'] && $r['affected_rows'] > 0) {
    echo json_encode(['success' => true, 'status' => $newStatus]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Status update failed.']);
}

<?php
// ============================================================
// api/admin/delete-test.php
//
// Admin hard-deletes any assessment.
// FK CASCADE removes questions, attempts, answers automatically.
//
// POST { assessment_id: int }
// Returns { success: bool }
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

if ($assessmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid assessment ID.']);
    exit;
}

// Verify exists
$check = safePreparedQuery($conn,
    "SELECT assessment_id, title FROM assessments WHERE assessment_id = ?",
    "i", [$assessmentId]
);
if (!$check['success'] || !$check['result'] || $check['result']->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Assessment not found.']);
    exit;
}
$check['result']->free();

$r = safePreparedQuery($conn,
    "DELETE FROM assessments WHERE assessment_id = ?",
    "i", [$assessmentId]
);

if ($r['success'] && $r['affected_rows'] > 0) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Delete failed.']);
}

<?php
/* ============================================================
 * API: Delete Self Assessment Attempt
 * api/self-assessment/delete-attempt.php
 * POST: attempt_id, csrf_token
 * ============================================================ */

require_once '../../config.php';
require_once '../../db-guard.php';

header('Content-Type: application/json');

$user   = validateSession($conn, 'student');
$userId = (int)$user['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid method']); exit;
}

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'CSRF error']); exit;
}

$attemptId = (int)($_POST['attempt_id'] ?? 0);
if (!$attemptId) {
    echo json_encode(['success' => false, 'error' => 'Missing attempt_id']); exit;
}

// Verify the attempt belongs to this student
$check = safePreparedQuery($conn,
    "SELECT attempt_id FROM self_assessment_attempts WHERE attempt_id = ? AND user_id = ?",
    "ii", [$attemptId, $userId]
);
$owns = false;
if ($check['success'] && $check['result']) {
    $owns = (bool)$check['result']->fetch_assoc();
    $check['result']->free();
}
if (!$owns) {
    echo json_encode(['success' => false, 'error' => 'Not found']); exit;
}

// Delete answers first, then attempt
safePreparedQuery($conn,
    "DELETE FROM self_assessment_answers WHERE attempt_id = ?",
    "i", [$attemptId]
);
$del = safePreparedQuery($conn,
    "DELETE FROM self_assessment_attempts WHERE attempt_id = ? AND user_id = ?",
    "ii", [$attemptId, $userId]
);

if ($del['success']) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Delete failed']);
}

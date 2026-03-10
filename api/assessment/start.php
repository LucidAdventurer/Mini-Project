<?php
// ============================================================
// api/assessment/start.php
//
// Creates a new assessment attempt for the logged-in student.
// Validates access, attempt limits, and availability window.
//
// FIX: If an in_progress attempt already exists for this student
// and assessment, return it instead of creating a duplicate.
// This prevents two-tab exploits and stale orphaned attempts.
//
// POST JSON { assessment_id: int }
// Returns {
//   success: bool,
//   attempt_id?: int,
//   error?: string
// }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$user         = validateSession($conn, 'student');
$userId       = (int) $user['user_id'];
$userDept     = $user['department'] ?? null;

$body         = json_decode(file_get_contents('php://input'), true);
$assessmentId = (int)($body['assessment_id'] ?? 0);

if ($assessmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid assessment ID.']);
    exit;
}

// ── Verify assessment is active, in window, and accessible ──
$asmResult = safePreparedQuery($conn,
    "SELECT assessment_id, max_attempts
     FROM assessments
     WHERE assessment_id = ?
       AND status = 'active'
       AND (available_from  IS NULL OR available_from  <= NOW())
       AND (available_until IS NULL OR available_until >= NOW())
       AND (
           is_public = 1
           OR EXISTS (
               SELECT 1 FROM assessment_access ac
               WHERE ac.assessment_id = assessments.assessment_id
                 AND ac.access_type   = 'allow'
                 AND (ac.user_id = ? OR ac.department = ?)
           )
       )",
    "iis", [$assessmentId, $userId, $userDept]
);

if (!$asmResult['success'] || !$asmResult['result'] || $asmResult['result']->num_rows === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Assessment not found or not accessible.']);
    exit;
}

$asm = $asmResult['result']->fetch_assoc();
$asmResult['result']->free();
$maxAttempts = (int)$asm['max_attempts'];

// ── Resume existing in_progress attempt if one exists ──
// Prevents duplicate attempts from double-clicks or multiple tabs.
$existingResult = safePreparedQuery($conn,
    "SELECT attempt_id FROM assessment_attempts
     WHERE assessment_id = ? AND user_id = ? AND status = 'in_progress'
     ORDER BY start_time DESC
     LIMIT 1",
    "ii", [$assessmentId, $userId]
);

if ($existingResult['success'] && $existingResult['result'] && $existingResult['result']->num_rows > 0) {
    $existingRow = $existingResult['result']->fetch_assoc();
    $existingResult['result']->free();
    echo json_encode([
        'success'    => true,
        'attempt_id' => (int)$existingRow['attempt_id'],
        'resumed'    => true,
    ]);
    exit;
}
if ($existingResult['result']) {
    $existingResult['result']->free();
}

// ── Check completed attempt count ──
$countResult = safePreparedQuery($conn,
    "SELECT COUNT(*) AS cnt
     FROM assessment_attempts
     WHERE assessment_id = ? AND user_id = ? AND status = 'completed'",
    "ii", [$assessmentId, $userId]
);

$attemptsUsed = 0;
if ($countResult['success'] && $countResult['result']) {
    $row          = $countResult['result']->fetch_assoc();
    $attemptsUsed = (int)($row['cnt'] ?? 0);
    $countResult['result']->free();
}

if ($attemptsUsed >= $maxAttempts) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You have used all available attempts for this assessment.']);
    exit;
}

// ── Create attempt record ──
$attemptNumber = $attemptsUsed + 1;
$ipAddress     = $_SERVER['REMOTE_ADDR'] ?? null;
$userAgent     = $_SERVER['HTTP_USER_AGENT'] ?? null;

$insert = safePreparedQuery($conn,
    "INSERT INTO assessment_attempts
        (assessment_id, user_id, attempt_number, start_time, status, ip_address, user_agent)
     VALUES (?, ?, ?, NOW(), 'in_progress', ?, ?)",
    "iiiss", [$assessmentId, $userId, $attemptNumber, $ipAddress, $userAgent]
);

if ($insert['success'] && $insert['insert_id'] > 0) {
    echo json_encode([
        'success'    => true,
        'attempt_id' => $insert['insert_id'],
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to start assessment. Please try again.']);
}
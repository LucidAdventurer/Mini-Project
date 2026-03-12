<?php
// ============================================================
// api/assessment/start.php
//
// Creates a new assessment attempt for the logged-in student.
// If an in_progress attempt already exists, resumes it.
//
// POST JSON { assessment_id: int }
// Returns { success: bool, attempt_id?: int, resumed?: bool, error?: string }
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

$body         = json_decode(file_get_contents('php://input'), true);
$assessmentId = (int)($body['assessment_id'] ?? 0);

if ($assessmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid assessment ID.']);
    exit;
}

// ── Verify assessment is active, within window, and accessible ──
// Access: either visibility = 'public', or the student/their group
// appears in assessment_targets.
$asmResult = safePreparedQuery($conn,
    "SELECT assessment_id, max_attempts
     FROM assessments
     WHERE assessment_id = ?
       AND status = 'published'
       AND (start_time IS NULL OR start_time <= NOW())
       AND (end_time   IS NULL OR end_time   >= NOW())
       AND (
           visibility = 'public'
           OR EXISTS (
               SELECT 1 FROM assessment_targets at2
               WHERE at2.assessment_id = assessments.assessment_id
                 AND at2.target_type   = 'student'
                 AND at2.target_id     = ?
           )
           OR EXISTS (
               SELECT 1 FROM assessment_targets at3
               JOIN group_members gm ON gm.group_id = at3.target_id
               WHERE at3.assessment_id = assessments.assessment_id
                 AND at3.target_type   = 'group'
                 AND gm.student_id     = ?
           )
       )",
    "iii", [$assessmentId, $userId, $userId]
);

if (!$asmResult['success'] || !$asmResult['result'] || $asmResult['result']->num_rows === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Assessment not found or not accessible.']);
    exit;
}

$asm = $asmResult['result']->fetch_assoc();
$asmResult['result']->free();
$maxAttempts = (int)$asm['max_attempts'];

// ── Resume existing in_progress attempt ──
$existingResult = safePreparedQuery($conn,
    "SELECT attempt_id FROM assessment_attempts
     WHERE assessment_id = ? AND user_id = ? AND status = 'in_progress'
     ORDER BY start_time DESC LIMIT 1",
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
if (!empty($existingResult['result'])) {
    $existingResult['result']->free();
}

// ── Check completed attempt count ──
// 'submitted' and 'timeout' both count as used attempts
$countResult = safePreparedQuery($conn,
    "SELECT COUNT(*) AS cnt
     FROM assessment_attempts
     WHERE assessment_id = ? AND user_id = ? AND status IN ('submitted', 'timeout')",
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

// ── Create attempt ──
$attemptNumber = $attemptsUsed + 1;
$ipAddress     = $_SERVER['REMOTE_ADDR']     ?? null;
$userAgent     = $_SERVER['HTTP_USER_AGENT'] ?? null;

$insert = safePreparedQuery($conn,
    "INSERT INTO assessment_attempts
        (assessment_id, user_id, attempt_number, start_time, status, ip_address, user_agent, created_at)
     VALUES (?, ?, ?, NOW(), 'in_progress', ?, ?, NOW())",
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
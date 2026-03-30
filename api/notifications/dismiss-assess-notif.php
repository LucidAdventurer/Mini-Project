<?php
/* ============================================================
 * api/notifications/dismiss-assess-notif.php
 *
 * Persists a student's dismissal of an assessment notification.
 * Requires:  student_notif_dismiss  table (see SQL below).
 *
 * CREATE TABLE IF NOT EXISTS student_notif_dismiss (
 *     id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *     user_id       INT UNSIGNED NOT NULL,
 *     assessment_id INT UNSIGNED NOT NULL,
 *     dismissed_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE KEY uq_user_assess (user_id, assessment_id)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 * ============================================================ */

require_once "../../config.php";
require_once "../../db-guard.php";

header('Content-Type: application/json');

// Must be POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Validate session
$user   = validateSession($conn, 'student');
$userId = (int)($user['user_id'] ?? 0);
if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthenticated']);
    exit;
}

// Parse JSON body
$body         = json_decode(file_get_contents('php://input'), true) ?? [];
$assessmentId = (int)($body['assessment_id'] ?? 0);
$csrfToken    = $body['csrf_token'] ?? '';

// CSRF check
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF mismatch']);
    exit;
}

if ($assessmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid assessment_id']);
    exit;
}

// Verify the student has at least one submitted attempt before allowing dismiss
$checkResult = safePreparedQuery($conn,
    "SELECT COUNT(*) AS cnt FROM assessment_attempts
     WHERE assessment_id = ? AND user_id = ? AND status = 'submitted'",
    "ii", [$assessmentId, $userId]
);

if (!$checkResult['success'] || !$checkResult['result']) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB error']);
    exit;
}

$checkRow   = $checkResult['result']->fetch_assoc();
$checkResult['result']->free();
$attemptCnt = (int)($checkRow['cnt'] ?? 0);

if ($attemptCnt === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No completed attempt — cannot dismiss']);
    exit;
}

// Insert dismissal (ignore duplicate)
$insertResult = safePreparedQuery($conn,
    "INSERT IGNORE INTO student_notif_dismiss (user_id, assessment_id) VALUES (?, ?)",
    "ii", [$userId, $assessmentId]
);

if (!$insertResult['success']) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to persist dismissal']);
    exit;
}

echo json_encode(['success' => true]);

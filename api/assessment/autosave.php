<?php
/* ============================================================
 * api/assessment/autosave.php
 *
 * Called every 30 seconds by take-test.php while a student
 * is taking the test. Saves/updates each answer so progress
 * is never lost on a page refresh or connection drop.
 *
 * FIX: The old guard `!in_array($selectedAnswer, ['A','B','C','D'])`
 * silently dropped multiple_select answers ("A,C") and short_answer /
 * fill_blank freetext. Now accepts any non-empty string up to 500 chars.
 * Grading is done only in submit.php — autosave just stores the raw value.
 *
 * POST JSON {
 *   attempt_id:    int,
 *   answers:       { "questionId": "A"|"B"|"C"|"D"|"A,C"|"freetext", ... },
 *   time_remaining: int   (seconds, optional — for logging)
 * }
 * Returns { success: bool }
 * ============================================================ */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$user   = validateSession($conn, 'student');
$userId = (int) $user['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON.']);
    exit;
}

$attemptId = (int)($body['attempt_id'] ?? 0);
$answers   = $body['answers'] ?? [];

if ($attemptId <= 0 || !is_array($answers)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid parameters.']);
    exit;
}

/* ── Verify this attempt belongs to this student and is still in_progress ── */
$check = safePreparedQuery($conn,
    "SELECT assessment_id FROM assessment_attempts
     WHERE attempt_id = ? AND user_id = ? AND status = 'in_progress'",
    "ii", [$attemptId, $userId]
);

if (!$check['success'] || !$check['result'] || $check['result']->num_rows === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Attempt not found or already submitted.']);
    exit;
}
$check['result']->free();

/* ── Upsert each answer ──
 * Accept any non-empty answer string — MCQ single letter, comma-separated
 * multiple_select, or freetext for short_answer/fill_blank.
 * Truncate to 500 chars as a safety cap.
 * Grading happens in submit.php, not here.
 */
foreach ($answers as $questionId => $selectedAnswer) {
    $questionId     = (int)$questionId;
    $selectedAnswer = strtoupper(trim((string)$selectedAnswer));

    if ($questionId <= 0 || $selectedAnswer === '') {
        continue;
    }

    // Cap length to prevent oversized payloads being stored
    $selectedAnswer = mb_substr($selectedAnswer, 0, 500);

    safePreparedQuery($conn,
        "INSERT INTO answers (attempt_id, question_id, selected_answer, answered_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            selected_answer = VALUES(selected_answer),
            answered_at     = NOW()",
        "iis", [$attemptId, $questionId, $selectedAnswer]
    );
}

echo json_encode(['success' => true]);
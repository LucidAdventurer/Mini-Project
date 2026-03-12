<?php
/* ============================================================
 * api/assessment/autosave.php
 *
 * Called periodically by take-test.php while a student is
 * taking a test. Saves/updates each answer so progress is
 * never lost on a page refresh or connection drop.
 *
 * Schema: answers(answer_id, attempt_id, question_id,
 *                 selected_option_id, text_answer, marks_awarded)
 * - Option-based answers (mcq/true_false/multiple_select):
 *   stored as selected_option_id (int — the option_id PK)
 * - Free-text answers (short_answer): stored as text_answer
 * - marks_awarded is left NULL here; filled only by submit.php
 *
 * POST JSON {
 *   attempt_id:     int,
 *   answers:        { "questionId": optionId | "freetext", ... },
 *   time_remaining: int  (optional)
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

/* ── Verify attempt belongs to this student and is still in_progress ── */
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
 * Numeric value → option-based answer (selected_option_id)
 * String value  → free-text answer (text_answer)
 * Grading is done only in submit.php — autosave just persists the raw value.
 */
foreach ($answers as $questionId => $answer) {
    $questionId = (int)$questionId;
    if ($questionId <= 0) continue;

    if (is_numeric($answer) && (int)$answer > 0) {
        /* Option-based: store the option_id */
        $optionId = (int)$answer;
        safePreparedQuery($conn,
            "INSERT INTO answers (attempt_id, question_id, selected_option_id, text_answer)
             VALUES (?, ?, ?, NULL)
             ON DUPLICATE KEY UPDATE
                selected_option_id = VALUES(selected_option_id),
                text_answer        = NULL",
            "iii", [$attemptId, $questionId, $optionId]
        );
    } else {
        /* Free-text: store in text_answer */
        $textAnswer = mb_substr(trim((string)$answer), 0, 500);
        if ($textAnswer === '') continue;
        safePreparedQuery($conn,
            "INSERT INTO answers (attempt_id, question_id, selected_option_id, text_answer)
             VALUES (?, ?, NULL, ?)
             ON DUPLICATE KEY UPDATE
                selected_option_id = NULL,
                text_answer        = VALUES(text_answer)",
            "iis", [$attemptId, $questionId, $textAnswer]
        );
    }
}

echo json_encode(['success' => true]);
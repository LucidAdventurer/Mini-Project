<?php
/* ============================================================
 * api/assessment/autosave.php
 *
 * Called periodically by take-test.php while a student is
 * taking a test. Saves/updates each answer so progress is
 * never lost on a page refresh or connection drop.
 * Called periodically by take-test.php while a student is
 * taking a test. Saves/updates each answer so progress is
 * never lost on a page refresh or connection drop.
 *
 * The answers table schema:
 *   answer_id, attempt_id, question_id,
 *   selected_option_id (FK → question_options, nullable),
 *   text_answer        (for short_answer / fill_blank),
 *   marks_awarded
 *
 * For MCQ / true_false / multiple_select the frontend sends
 * option_id(s). For short_answer / fill_blank it sends a text
 * string. Grading happens only in submit.php — autosave just
 * stores the raw selection.
 *
 * POST JSON {
 *   attempt_id: int,
 *   answers: {
 *     "<question_id>": {
 *       "option_id":  int|null,     ← for option-based types
 *       "text":       string|null,  ← for text-based types
 *     },
 *     ...
 *   }
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

/* ── Verify this attempt belongs to this student and is in_progress ── */
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
 * selected_option_id: set for option-based questions, NULL otherwise.
 * text_answer:        set for text-based questions, NULL otherwise.
 * marks_awarded:      left at 0 — grading happens in submit.php.
 */
foreach ($answers as $questionId => $answer) {
    $questionId = (int)$questionId;
    if ($questionId <= 0) continue;

    $optionId  = isset($answer['option_id']) && $answer['option_id'] !== null
                     ? (int)$answer['option_id']
                     : null;
    $textAns   = isset($answer['text']) && (string)$answer['text'] !== ''
                     ? mb_substr(trim((string)$answer['text']), 0, 1000)
                     : null;

    // Skip completely empty answers
    if ($optionId === null && $textAns === null) continue;

    safePreparedQuery($conn,
        "INSERT INTO answers (attempt_id, question_id, selected_option_id, text_answer, marks_awarded)
         VALUES (?, ?, ?, ?, 0)
         ON DUPLICATE KEY UPDATE
            selected_option_id = VALUES(selected_option_id),
            text_answer        = VALUES(text_answer)",
        "iiis", [$attemptId, $questionId, $optionId, $textAns]
    );
}

echo json_encode(['success' => true]);
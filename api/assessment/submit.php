<?php
/* ============================================================
 * api/assessment/submit.php
 *
 * Final submission endpoint. Grades every answer, calculates
 * score/percentage, and marks the attempt as 'submitted'.
 *
 * Schema notes:
 * - answers columns: answer_id, attempt_id, question_id,
 *   selected_option_id, text_answer, marks_awarded
 * - questions columns: question_id, assessment_id, question_text,
 *   question_type, marks, negative_marks, explanation, question_order
 * - question_options columns: option_id, question_id, option_text,
 *   is_correct, option_order
 * - assessment_attempts has NO: end_time, total_questions,
 *   correct_answers, wrong_answers, unanswered — these are
 *   derived at read-time from the answers table.
 * - attempt status enum: 'in_progress','submitted','timeout'
 *
 * POST JSON {
 *   attempt_id:     int,
 *   answers:        { "questionId": optionId | "text", ... },
 *   time_remaining: int,   (optional — kept for backwards compat)
 *   auto_submit:    bool   (optional — set true on timeout)
 * }
 * Returns { success: bool, attempt_id: int, error?: string }
 * ============================================================ */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

/* ── Auth ── */
$user   = validateSession($conn, 'student');
$userId = (int) $user['user_id'];

/* Support GET ?attempt_id=X&auto=1 for server-side timeout redirect */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $attemptId  = (int)($_GET['attempt_id'] ?? 0);
    $answers    = [];
    $autoSubmit = true;
} else {
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
    $attemptId  = (int)($body['attempt_id']    ?? 0);
    $answers    = $body['answers']             ?? [];
    $autoSubmit = !empty($body['auto_submit']);
}

if ($attemptId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid attempt ID.']);
    exit;
}

/* ── Fetch attempt — must be in_progress/timeout and belong to this student ── */
$aResult = safePreparedQuery($conn,
    "SELECT aa.attempt_id, aa.assessment_id, aa.attempt_number, aa.start_time,
            a.total_marks, a.passing_marks, a.duration_minutes
     FROM assessment_attempts aa
     JOIN assessments a ON a.assessment_id = aa.assessment_id
     WHERE aa.attempt_id = ? AND aa.user_id = ?
       AND aa.status IN ('in_progress', 'timeout')",
    "ii", [$attemptId, $userId]
);

if (!$aResult['success'] || !$aResult['result'] || $aResult['result']->num_rows === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Attempt not found or already submitted.']);
    exit;
}
$attempt      = $aResult['result']->fetch_assoc();
$aResult['result']->free();
$assessmentId = (int)$attempt['assessment_id'];

/* ── Save any final answers sent with this request ──
 * Option-based answers (MCQ/TF/multiple_select): stored in selected_option_id
 * Text answers (short_answer): stored in text_answer
 */
if (!empty($answers) && is_array($answers)) {
    foreach ($answers as $questionId => $answer) {
        $questionId = (int)$questionId;
        if ($questionId <= 0) continue;

        if (is_numeric($answer) && (int)$answer > 0) {
            /* Option-based answer */
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
            /* Text answer */
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
}

/* ── Load all questions with their correct option IDs ── */
$qResult = safePreparedQuery($conn,
    "SELECT q.question_id, q.question_type, q.marks, q.negative_marks,
            GROUP_CONCAT(
                CASE WHEN qo.is_correct = 1 THEN qo.option_id END
                ORDER BY qo.option_id
            ) AS correct_option_ids
     FROM questions q
     LEFT JOIN question_options qo ON qo.question_id = q.question_id
     WHERE q.assessment_id = ?
     GROUP BY q.question_id, q.question_type, q.marks, q.negative_marks",
    "i", [$assessmentId]
);

$questions = [];
if ($qResult['success'] && $qResult['result']) {
    while ($row = $qResult['result']->fetch_assoc()) {
        $questions[(int)$row['question_id']] = $row;
    }
    $qResult['result']->free();
}

if (empty($questions)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'No questions found for this assessment.']);
    exit;
}

/* ── Load all saved answers for this attempt ── */
$savedResult = safePreparedQuery($conn,
    "SELECT question_id, selected_option_id, text_answer
     FROM answers WHERE attempt_id = ?",
    "i", [$attemptId]
);
$savedAnswers = [];
if ($savedResult['success'] && $savedResult['result']) {
    while ($row = $savedResult['result']->fetch_assoc()) {
        $savedAnswers[(int)$row['question_id']] = $row;
    }
    $savedResult['result']->free();
}

/* ── Grade every question ── */
$rawScore = 0.0;

foreach ($questions as $qid => $q) {
    $qType    = $q['question_type'];
    $marks    = (int)$q['marks'];
    $negMarks = (float)$q['negative_marks'];
    $saved    = $savedAnswers[$qid] ?? null;

    if ($saved === null) {
        /* No answer row at all — skip */
        continue;
    }

    $marksAwarded = 0.0;

    if ($qType === 'short_answer') {
        /* Short answer: requires teacher grading — leave marks_awarded = 0 */
        $marksAwarded = 0.0;
    } else {
        /* Option-based: compare selected_option_id against correct options */
        $correctIds = $q['correct_option_ids']
            ? array_map('intval', explode(',', $q['correct_option_ids']))
            : [];

        $selectedId = $saved['selected_option_id'] !== null
            ? (int)$saved['selected_option_id']
            : null;

        if ($selectedId !== null) {
            $isCorrect    = in_array($selectedId, $correctIds, true);
            $marksAwarded = $isCorrect ? (float)$marks : -(float)$negMarks;

            if ($isCorrect) {
                $rawScore += $marks;
            } else {
                $rawScore -= $negMarks;
            }
        }
    }

    /* Update the answer row with marks */
    safePreparedQuery($conn,
        "UPDATE answers SET marks_awarded = ?
         WHERE attempt_id = ? AND question_id = ?",
        "dii", [$marksAwarded, $attemptId, $qid]
    );
}

/* ── Clamp score and compute percentage ── */
$finalScore = max(0, round($rawScore, 2));
$totalMarks = (int)$attempt['total_marks'];
$percentage = $totalMarks > 0 ? round(($finalScore / $totalMarks) * 100, 2) : 0;

/* ── Time taken — derived from DB timestamps ── */
$startTime        = $attempt['start_time'] ? new DateTime($attempt['start_time']) : null;
$now              = new DateTime();
$timeTakenSeconds = $startTime ? max(0, $now->getTimestamp() - $startTime->getTimestamp()) : 0;
$maxSeconds       = (int)$attempt['duration_minutes'] * 60;
$timeTakenSeconds = min($timeTakenSeconds, $maxSeconds);

/* ── Mark attempt as submitted ── */
safePreparedQuery($conn,
    "UPDATE assessment_attempts
     SET status       = 'submitted',
         submitted_at = NOW(),
         score        = ?,
         percentage   = ?
     WHERE attempt_id = ? AND user_id = ?",
    "ddii", [$finalScore, $percentage, $attemptId, $userId]
);

/* ── For GET (timeout redirect), go straight to results ── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header("Location: ../../test-results.php?attempt_id=$attemptId");
    exit;
}

echo json_encode(['success' => true, 'attempt_id' => $attemptId]);
<?php
/* ============================================================
 * api/assessment/submit.php
 *
 * Final submission. Grades every answer via question_options.is_correct,
 * computes score/percentage, marks the attempt as 'submitted'.
 *
 * answers table schema:
 *   answer_id, attempt_id, question_id,
 *   selected_option_id (FK → question_options, nullable),
 *   text_answer        (for short_answer/fill_blank),
 *   marks_awarded
 *
 * assessment_attempts columns written here:
 *   status = 'submitted', submitted_at, score, percentage
 *
 * POST JSON {
 *   attempt_id:     int,
 *   answers: {
 *     "<question_id>": { option_id?: int, text?: string },
 *     ...
 *   },
 *   auto_submit?: bool   (set true on timeout)
 * }
 * Returns { success: bool, attempt_id: int, error?: string }
 * ============================================================ */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

/* $conn is already created by config.php — no need to reconnect */
if (!$conn) { http_response_code(503); echo json_encode(['success'=>false,'error'=>'Database unavailable.']); exit; }

/* Support GET ?attempt_id=X for server-side timeout redirect */
$user   = validateSession($conn, 'student');
$userId = (int) $user['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $attemptId  = (int)($_GET['attempt_id'] ?? 0);
    $answers    = [];
    $autoSubmit = true;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON.']);
        exit;
    }
    $attemptId  = (int)($body['attempt_id'] ?? 0);
    $answers    = $body['answers']          ?? [];
    $autoSubmit = !empty($body['auto_submit']);
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

if ($attemptId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid attempt ID.']);
    exit;
}

/* ── Fetch attempt — must be in_progress or timeout, belong to this student ── */
$aResult = safePreparedQuery($conn,
    "SELECT aa.attempt_id, aa.assessment_id, aa.start_time,
            a.total_marks, a.passing_marks, a.duration_minutes
     FROM assessment_attempts aa
     JOIN assessments a ON a.assessment_id = aa.assessment_id
     WHERE aa.attempt_id = ? AND aa.user_id = ?
       AND aa.status IN ('in_progress', 'timeout', 'completed')",
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

/* ── Save any final answers sent with this request ── */
if (!empty($answers) && is_array($answers)) {
    foreach ($answers as $questionId => $answer) {
        $questionId = (int)$questionId;
        if ($questionId <= 0) continue;

        /* Accept two formats from the client:
         *   Flat:   { "123": 45 }            → option_id = 45
         *   Object: { "123": {"option_id":45} } → option_id = 45
         */
        if (is_array($answer)) {
            $optionId = isset($answer['option_id']) && $answer['option_id'] !== null
                            ? (int)$answer['option_id']
                            : null;
            $textAns  = isset($answer['text']) && (string)$answer['text'] !== ''
                            ? mb_substr(trim((string)$answer['text']), 0, 1000)
                            : null;
        } else {
            // Flat integer value = option_id
            $optionId = is_numeric($answer) ? (int)$answer : null;
            $textAns  = null;
        }

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
}

/* ── Load all questions for this assessment ── */
$qResult = safePreparedQuery($conn,
    "SELECT question_id, question_type, marks, negative_marks
     FROM questions WHERE assessment_id = ?",
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

/* ── Load correct option sets per question ──
 * For each question, collect the set of option_ids that are marked correct.
 */
$qids      = implode(',', array_keys($questions));
$optsResult = $conn->query(
    "SELECT question_id, option_id, is_correct
     FROM question_options
     WHERE question_id IN ($qids)"
);
$correctOptionSets = []; // question_id => [option_id, ...]
if ($optsResult) {
    while ($row = $optsResult->fetch_assoc()) {
        $qid = (int)$row['question_id'];
        if ((int)$row['is_correct'] === 1) {
            $correctOptionSets[$qid][] = (int)$row['option_id'];
        }
    }
    $optsResult->free();
}

/* ── Load all saved answers for this attempt ── */
$savedResult = safePreparedQuery($conn,
    "SELECT question_id, selected_option_id, text_answer
     FROM answers WHERE attempt_id = ?",
    "i", [$attemptId]
);
$savedAnswers = []; // question_id => ['option_id' => int|null, 'text' => string|null]
if ($savedResult['success'] && $savedResult['result']) {
    while ($row = $savedResult['result']->fetch_assoc()) {
        $savedAnswers[(int)$row['question_id']] = [
            'option_id' => $row['selected_option_id'] !== null ? (int)$row['selected_option_id'] : null,
            'text'      => $row['text_answer'],
        ];
    }
    $savedResult['result']->free();
}

/* ── Grade every question ── */
$rawScore  = 0.0;
$totalMark = (int)$attempt['total_marks'];

foreach ($questions as $qid => $q) {
    $qType         = $q['question_type'];
    $marks         = (int)$q['marks'];
    $negMarks      = (float)$q['negative_marks'];
    $saved         = $savedAnswers[$qid] ?? null;
    $marksAwarded  = 0.0;

    if ($saved === null) {
        // Unanswered — 0 marks, no penalty
        $marksAwarded = 0.0;
    } elseif (in_array($qType, ['short_answer', 'fill_blank'], true)) {
        // Text answer: compare against the correct option's text (case-insensitive trim).
        // The correct option row holds the expected answer in option_text.
        // For now: mark correct if the student's text matches any correct option_text.
        $textAns = trim(strtolower((string)($saved['text'] ?? '')));
        if ($textAns === '') {
            $marksAwarded = 0.0;
        } else {
            // Fetch correct option texts for this question
            $correctTexts = [];
            if (!empty($correctOptionSets[$qid])) {
                $correctIds = implode(',', array_map('intval', $correctOptionSets[$qid]));
                $tr = $conn->query(
                    "SELECT option_text FROM question_options
                     WHERE question_id = $qid AND option_id IN ($correctIds)"
                );
                if ($tr) {
                    while ($r = $tr->fetch_assoc()) {
                        $correctTexts[] = trim(strtolower($r['option_text']));
                    }
                    $tr->free();
                }
            }
            if (in_array($textAns, $correctTexts, true)) {
                $marksAwarded = $marks;
                $rawScore    += $marks;
            } else {
                $marksAwarded = -$negMarks;
                $rawScore    -= $negMarks;
            }
        }
    } else {
        // Option-based question
        $selectedOptionId = $saved['option_id'] ?? null;
        if ($selectedOptionId === null) {
            $marksAwarded = 0.0;
        } else {
            $correctSet = $correctOptionSets[$qid] ?? [];
            if (in_array($selectedOptionId, $correctSet, true)) {
                $marksAwarded = $marks;
                $rawScore    += $marks;
            } else {
                $marksAwarded = -$negMarks;
                $rawScore    -= $negMarks;
            }
        }
    }

    // Write marks_awarded back to the answer row
    safePreparedQuery($conn,
        "INSERT INTO answers (attempt_id, question_id, selected_option_id, text_answer, marks_awarded)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE marks_awarded = VALUES(marks_awarded)",
        "iiisd",
        [
            $attemptId,
            $qid,
            $saved['option_id'] ?? null,
            $saved['text']      ?? null,
            $marksAwarded,
        ]
    );
}

/* Clamp score to >= 0 */
$finalScore = max(0.0, round($rawScore, 2));
$percentage = $totalMark > 0 ? round(($finalScore / $totalMark) * 100, 2) : 0.0;

/* ── Mark attempt as submitted ── */
safePreparedQuery($conn,
    "UPDATE assessment_attempts SET
        status       = 'completed',
        submitted_at = NOW(),
        score        = ?,
        percentage   = ?
     WHERE attempt_id = ? AND user_id = ?",
    "ddii",
    [$finalScore, $percentage, $attemptId, $userId]
);

/* ── Rule 1: Remove 'assessment' notifications for this assessment ── */
safePreparedQuery($conn,
    "DELETE FROM notifications
     WHERE user_id = ?
       AND type = 'assessment'
       AND related_entity_id = ?",
    'ii', [$userId, $assessmentId]
);

/* ── Rule 3: Purge any notifications older than 3 days for this user ── */
safePreparedQuery($conn,
    "DELETE FROM notifications
     WHERE user_id = ? AND created_at < NOW() - INTERVAL 3 DAY",
    'i', [$userId]
);

/* ── Timeout redirect (GET) ── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header("Location: ../../test-results.php?attempt_id=$attemptId");
    exit;
}

echo json_encode(['success' => true, 'attempt_id' => $attemptId]);
<?php
/* ============================================================
 * api/assessment/submit.php
 *
 * Final submission endpoint. Grades every answer, calculates
 * score/percentage, marks the attempt as 'completed', and
 * writes a performance_analytics row.
 *
 * FIXES:
 * 1. multiple_select grading: sort both sides before comparing
 *    so "A,C" == "C,A".
 * 2. time_taken_seconds now derived from DB timestamps
 *    (start_time vs NOW()), not from client-supplied time_remaining
 *    which can be 0 or stale.
 * 3. Answer filter widened to accept multiple_select and
 *    short_answer/fill_blank values (any non-empty string).
 *
 * POST JSON {
 *   attempt_id:     int,
 *   answers:        { "questionId": "A"|"B"|"C"|"D"|"A,C"|"text", ... },
 *   time_remaining: int,   (optional — kept for backwards compat)
 *   auto_submit:    bool   (optional — set true on timeout)
 * }
 * Returns { success: bool, attempt_id: int, error?: string }
 * ============================================================ */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

/* ── Auth — also accept GET for auto-submit redirect (timeout case) ── */
$user   = validateSession($conn, 'student');
$userId = (int) $user['user_id'];

/* Support GET ?attempt_id=X&auto=1 for server-side timeout redirect */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $attemptId  = (int)($_GET['attempt_id'] ?? 0);
    $answers    = [];
    $autoSubmit = true;
    $timeLeft   = 0;
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
    $timeLeft   = (int)($body['time_remaining'] ?? 0);
}

if ($attemptId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid attempt ID.']);
    exit;
}

/* ── Fetch attempt — must be in_progress and belong to this student ── */
$aResult = safePreparedQuery($conn,
    "SELECT aa.attempt_id, aa.assessment_id, aa.attempt_number, aa.start_time,
            a.total_marks, a.passing_marks, a.duration_minutes,
            a.show_results_immediately
     FROM assessment_attempts aa
     JOIN assessments a ON a.assessment_id = aa.assessment_id
     WHERE aa.attempt_id = ? AND aa.user_id = ?
       AND aa.status IN ('in_progress','timeout')",
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
    foreach ($answers as $questionId => $selectedAnswer) {
        $questionId     = (int)$questionId;
        $selectedAnswer = strtoupper(trim((string)$selectedAnswer));

        if ($questionId <= 0 || $selectedAnswer === '') {
            continue;
        }

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
}

/* ── Load all answers stored in DB for this attempt ── */
$savedResult = safePreparedQuery($conn,
    "SELECT question_id, selected_answer FROM answers WHERE attempt_id = ?",
    "i", [$attemptId]
);
$savedAnswers = [];
if ($savedResult['success'] && $savedResult['result']) {
    while ($row = $savedResult['result']->fetch_assoc()) {
        $savedAnswers[(int)$row['question_id']] = strtoupper(trim($row['selected_answer']));
    }
    $savedResult['result']->free();
}

/* ── Load all questions for this assessment ── */
$qResult = safePreparedQuery($conn,
    "SELECT question_id, question_type, correct_answer, marks, negative_marks, topic
     FROM questions
     WHERE assessment_id = ?",
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

/* ── Helper: normalise a multiple_select answer string for comparison ──
 * Sorts letters so "C,A" and "A,C" are treated as equal.
 */
function normaliseMCAnswer(string $raw): string {
    $parts = array_filter(array_map('trim', explode(',', $raw)));
    sort($parts);
    return implode(',', $parts);
}

/* ── Grade every question ── */
$totalQuestions  = count($questions);
$correctCount    = 0;
$wrongCount      = 0;
$unansweredCount = 0;
$rawScore        = 0.0;
$topicScores     = [];  // topic => [correct, total]

foreach ($questions as $qid => $q) {
    $correctAns    = strtoupper(trim($q['correct_answer']));
    $qType         = $q['question_type'];
    $studentAns    = $savedAnswers[$qid] ?? null;
    $marks         = (int)$q['marks'];
    $negMarks      = (float)$q['negative_marks'];
    $topic         = $q['topic'] ?? 'General';
    $isCorrect     = null;
    $marksObtained = 0.0;

    if (!isset($topicScores[$topic])) {
        $topicScores[$topic] = ['correct' => 0, 'total' => 0];
    }
    $topicScores[$topic]['total']++;

    if ($studentAns === null || $studentAns === '') {
        /* Unanswered */
        $unansweredCount++;
        $isCorrect = null;
    } else {
        /* Compare — sort both sides for multiple_select */
        if ($qType === 'multiple_select') {
            $match = normaliseMCAnswer($studentAns) === normaliseMCAnswer($correctAns);
        } else {
            $match = $studentAns === $correctAns;
        }

        if ($match) {
            $correctCount++;
            $marksObtained = $marks;
            $rawScore     += $marks;
            $isCorrect     = true;
            $topicScores[$topic]['correct']++;
        } else {
            $wrongCount++;
            $marksObtained = -$negMarks;
            $rawScore     -= $negMarks;
            $isCorrect     = false;
        }
    }

    /* Update (or insert) the answer row with grading result.
     * Note: time_taken_seconds is NOT written here — that column
     * is per-question and only meaningful if the frontend tracks it.
     * We omit it to avoid a column-not-found error on installs that
     * don't have it yet. */
    safePreparedQuery($conn,
        "INSERT INTO answers
            (attempt_id, question_id, selected_answer, is_correct, marks_obtained, answered_at)
         VALUES (?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            selected_answer = VALUES(selected_answer),
            is_correct      = VALUES(is_correct),
            marks_obtained  = VALUES(marks_obtained)",
        "iisid",
        [$attemptId, $qid, $studentAns ?? '', $isCorrect, $marksObtained]
    );
}

/* Clamp score to 0 */
$finalScore  = max(0, round($rawScore, 2));
$totalMarks  = (int)$attempt['total_marks'];
$percentage  = $totalMarks > 0 ? round(($finalScore / $totalMarks) * 100, 2) : 0;

/* ── Time taken — derive from DB timestamps, not client time_remaining.
 * This is accurate regardless of what the client sends. ── */
$startTime        = $attempt['start_time'] ? new DateTime($attempt['start_time']) : null;
$now              = new DateTime();
$timeTakenSeconds = $startTime ? max(0, $now->getTimestamp() - $startTime->getTimestamp()) : 0;
// Cap at the assessment's full duration (can't take longer than allowed)
$maxSeconds       = (int)$attempt['duration_minutes'] * 60;
$timeTakenSeconds = min($timeTakenSeconds, $maxSeconds);

/* ── Mark attempt as completed ── */
safePreparedQuery($conn,
    "UPDATE assessment_attempts SET
        status          = 'completed',
        submitted_at    = NOW(),
        end_time        = NOW(),
        score           = ?,
        percentage      = ?,
        total_questions = ?,
        correct_answers = ?,
        wrong_answers   = ?,
        unanswered      = ?
     WHERE attempt_id = ? AND user_id = ?",
    "ddiiiiii",
    [$finalScore, $percentage, $totalQuestions,
     $correctCount, $wrongCount, $unansweredCount,
     $attemptId, $userId]
);

/* ── Write performance_analytics row ──
 * The schema has no UNIQUE KEY on attempt_id, so ON DUPLICATE KEY
 * never fires. Each attempt maps to exactly one analytics row —
 * the status check above prevents submit.php from running twice
 * for the same attempt. Plain INSERT is correct. */
$timeTakenMins = (int)ceil($timeTakenSeconds / 60);

safePreparedQuery($conn,
    "INSERT INTO performance_analytics
        (user_id, assessment_id, attempt_id, score, percentage,
         time_taken_minutes, topic_scores)
     VALUES (?, ?, ?, ?, ?, ?, ?)",
    "iiiddis",
    [$userId, $assessmentId, $attemptId,
     $finalScore, $percentage, $timeTakenMins,
     json_encode($topicScores)]
);

/* ── For GET (timeout redirect), go straight to results ── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header("Location: ../../test-results.php?attempt_id=$attemptId");
    exit;
}

echo json_encode(['success' => true, 'attempt_id' => $attemptId]);
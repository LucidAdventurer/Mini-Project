<?php
// ============================================================
// api/assessment/get-test-results.php
//
// Returns full result data for one assessment attempt.
// Only the student who owns the attempt may view it.
//
// GET  ?attempt_id=int
// Returns {
//   success, testName, completedAt, score, totalMarks,
//   passingMarks, percentage, passed, timeTakenSeconds,
//   durationMinutes, correctAnswers, wrongAnswers, unanswered,
//   totalQuestions, percentile, showCorrectAnswers,
//   categoryPerformance: [...], questions: [...] | null
// }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$currentUser = validateSession($conn, 'student');
$userId      = (int) $currentUser['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$attemptId = (int)($_GET['attempt_id'] ?? 0);
if ($attemptId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid attempt ID.']);
    exit;
}

// ── 1. Fetch attempt + assessment meta ──
// Verify ownership: user_id must match the logged-in student.
$attemptResult = safePreparedQuery($conn,
    "SELECT
        aa.attempt_id,
        aa.assessment_id,
        aa.score,
        aa.percentage,
        aa.total_questions,
        aa.correct_answers,
        aa.wrong_answers,
        aa.unanswered,
        aa.start_time,
        aa.submitted_at,
        aa.status,
        a.title            AS test_name,
        a.total_marks,
        a.passing_marks,
        a.duration_minutes,
        a.show_correct_answers,
        a.category
     FROM assessment_attempts aa
     JOIN assessments a ON a.assessment_id = aa.assessment_id
     WHERE aa.attempt_id = ?
       AND aa.user_id    = ?
       AND aa.status     = 'completed'",
    "ii", [$attemptId, $userId]
);

if (!$attemptResult['success'] || !$attemptResult['result'] || $attemptResult['result']->num_rows === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Result not found or access denied.']);
    exit;
}

$attempt = $attemptResult['result']->fetch_assoc();
$attemptResult['result']->free();

// ── 2. Calculate time taken ──
$timeTakenSeconds = 0;
if ($attempt['start_time'] && $attempt['submitted_at']) {
    $start     = new DateTime($attempt['start_time']);
    $submitted = new DateTime($attempt['submitted_at']);
    $timeTakenSeconds = max(0, $submitted->getTimestamp() - $start->getTimestamp());
}

// ── 3. Percentile: rank among all completed attempts for this assessment ──
// percentile = (number of attempts with score < this score) / (total attempts) * 100
$percentileResult = safePreparedQuery($conn,
    "SELECT
        COUNT(*) AS total_attempts,
        SUM(CASE WHEN score < ? THEN 1 ELSE 0 END) AS below_count
     FROM assessment_attempts
     WHERE assessment_id = ?
       AND status        = 'completed'
       AND user_id IS NOT NULL",
    "di", [(float)$attempt['score'], (int)$attempt['assessment_id']]
);

$percentile = 0;
if ($percentileResult['success'] && $percentileResult['result']) {
    $pRow = $percentileResult['result']->fetch_assoc();
    $percentileResult['result']->free();
    if ($pRow && (int)$pRow['total_attempts'] > 0) {
        $percentile = (int) round(((int)$pRow['below_count'] / (int)$pRow['total_attempts']) * 100);
    }
}

// ── 4. Category/topic performance breakdown ──
// Group answers by the question's topic field.
$categoryResult = safePreparedQuery($conn,
    "SELECT
        COALESCE(q.topic, 'General')   AS topic,
        COUNT(*)                        AS total,
        SUM(CASE WHEN ans.is_correct = 1 THEN 1 ELSE 0 END) AS correct
     FROM answers ans
     JOIN questions q ON q.question_id = ans.question_id
     WHERE ans.attempt_id = ?
     GROUP BY COALESCE(q.topic, 'General')",
    "i", [$attemptId]
);

$categoryPerformance = [];
if ($categoryResult['success'] && $categoryResult['result']) {
    while ($row = $categoryResult['result']->fetch_assoc()) {
        $categoryPerformance[] = [
            'topic'   => $row['topic'],
            'correct' => (int) $row['correct'],
            'total'   => (int) $row['total'],
        ];
    }
    $categoryResult['result']->free();
}

// ── 5. Questions + answers (only if teacher allowed it) ──
$questions = null;

if ((int)$attempt['show_correct_answers'] === 1) {
    $qResult = safePreparedQuery($conn,
        "SELECT
            q.question_id,
            q.question_text,
            q.question_type,
            q.option_a,
            q.option_b,
            q.option_c,
            q.option_d,
            q.correct_answer,
            q.explanation,
            q.topic,
            q.marks,
            q.negative_marks,
            ans.selected_answer,
            ans.is_correct,
            ans.marks_obtained,
            ans.time_taken_seconds
         FROM answers ans
         JOIN questions q ON q.question_id = ans.question_id
         WHERE ans.attempt_id = ?
         ORDER BY ans.answer_id ASC",
        "i", [$attemptId]
    );

    if ($qResult['success'] && $qResult['result']) {
        $questions = [];
        $qNum      = 1;

        while ($row = $qResult['result']->fetch_assoc()) {
            // Build options array (only non-null options)
            $options = [];
            $labels  = ['A', 'B', 'C', 'D'];
            $fields  = ['option_a', 'option_b', 'option_c', 'option_d'];
            foreach ($fields as $idx => $field) {
                if ($row[$field] !== null && $row[$field] !== '') {
                    $options[$labels[$idx]] = $row[$field];
                }
            }

            $questions[] = [
                'questionNumber'  => $qNum++,
                'questionId'      => (int) $row['question_id'],
                'text'            => $row['question_text'],
                'type'            => $row['question_type'],
                'options'         => $options,
                'correctAnswer'   => $row['correct_answer'],
                'userAnswer'      => $row['selected_answer'],
                'isCorrect'       => (bool) $row['is_correct'],
                'marksObtained'   => (float) $row['marks_obtained'],
                'marks'           => (int) $row['marks'],
                'negativeMarks'   => (float) $row['negative_marks'],
                'explanation'     => $row['explanation'],
                'topic'           => $row['topic'],
                'timeTakenSeconds'=> (int) $row['time_taken_seconds'],
            ];
        }
        $qResult['result']->free();
    }
}

// ── 6. Build response ──
$passed     = (float)$attempt['score'] >= (float)$attempt['passing_marks'];
$durationSec = (int)$attempt['duration_minutes'] * 60;
$timeRemaining = max(0, $durationSec - $timeTakenSeconds);

echo json_encode([
    'success'            => true,
    'attemptId'          => (int) $attempt['attempt_id'],
    'assessmentId'       => (int) $attempt['assessment_id'],
    'testName'           => $attempt['test_name'],
    'completedAt'        => $attempt['submitted_at'],
    'score'              => (float) $attempt['score'],
    'totalMarks'         => (int) $attempt['total_marks'],
    'passingMarks'       => (int) $attempt['passing_marks'],
    'percentage'         => round((float)$attempt['percentage'], 2),
    'passed'             => $passed,
    'timeTakenSeconds'   => $timeTakenSeconds,
    'durationMinutes'    => (int) $attempt['duration_minutes'],
    'timeRemainingSeconds' => $timeRemaining,
    'totalQuestions'     => (int) $attempt['total_questions'],
    'correctAnswers'     => (int) $attempt['correct_answers'],
    'wrongAnswers'       => (int) $attempt['wrong_answers'],
    'unanswered'         => (int) $attempt['unanswered'],
    'percentile'         => $percentile,
    'showCorrectAnswers' => (bool) $attempt['show_correct_answers'],
    'category'           => $attempt['category'],
    'categoryPerformance'=> $categoryPerformance,
    'questions'          => $questions,   // null when show_correct_answers = 0
]);
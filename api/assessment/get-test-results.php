<?php
// ============================================================
// api/assessment/get-test-results.php
//
// Returns full result data for one assessment attempt.
// Only the student who owns the attempt may view it.
//
// Schema notes:
// - assessment_attempts: no end_time, total_questions,
//   correct_answers, wrong_answers, unanswered — all derived
//   from answers + questions tables at read-time.
// - answers columns: answer_id, attempt_id, question_id,
//   selected_option_id, text_answer, marks_awarded
// - questions columns: question_id, assessment_id, question_text,
//   question_type, marks, negative_marks, explanation, question_order
// - question_options columns: option_id, question_id, option_text,
//   is_correct, option_order
// - assessments has NO: show_correct_answers, show_results_immediately
// - attempt status enum: 'in_progress','submitted','timeout'
//
// GET  ?attempt_id=int
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
$attemptResult = safePreparedQuery($conn,
    "SELECT
        aa.attempt_id,
        aa.assessment_id,
        aa.score,
        aa.percentage,
        aa.start_time,
        aa.submitted_at,
        aa.status,
        a.title            AS test_name,
        a.total_marks,
        a.passing_marks,
        a.duration_minutes,
        a.category
     FROM assessment_attempts aa
     JOIN assessments a ON a.assessment_id = aa.assessment_id
     WHERE aa.attempt_id = ?
       AND aa.user_id    = ?
       AND aa.status     = 'submitted'",
    "ii", [$attemptId, $userId]
);

if (!$attemptResult['success'] || !$attemptResult['result'] || $attemptResult['result']->num_rows === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Result not found or access denied.']);
    exit;
}

$attempt = $attemptResult['result']->fetch_assoc();
$attemptResult['result']->free();
$assessmentId = (int)$attempt['assessment_id'];

// ── 2. Calculate time taken from DB timestamps ──
$timeTakenSeconds = 0;
if ($attempt['start_time'] && $attempt['submitted_at']) {
    $start     = new DateTime($attempt['start_time']);
    $submitted = new DateTime($attempt['submitted_at']);
    $timeTakenSeconds = max(0, $submitted->getTimestamp() - $start->getTimestamp());
}

// ── 3. Derive answer stats from answers table ──
// Count answered questions: has selected_option_id OR non-empty text_answer
// Count correct: marks_awarded >= marks of that question (positive)
$statsResult = safePreparedQuery($conn,
    "SELECT
        COUNT(*)                                                      AS total_answered,
        SUM(CASE WHEN ans.marks_awarded > 0 THEN 1 ELSE 0 END)       AS correct_count,
        SUM(CASE WHEN ans.marks_awarded <= 0
                  AND (ans.selected_option_id IS NOT NULL
                       OR (ans.text_answer IS NOT NULL AND ans.text_answer != ''))
                  THEN 1 ELSE 0 END)                                  AS wrong_count
     FROM answers ans
     WHERE ans.attempt_id = ?",
    "i", [$attemptId]
);

$totalQuestions  = count([]);  // filled below from questions query
$correctAnswers  = 0;
$wrongAnswers    = 0;
$unanswered      = 0;

if ($statsResult['success'] && $statsResult['result']) {
    $sRow           = $statsResult['result']->fetch_assoc();
    $correctAnswers = (int)($sRow['correct_count'] ?? 0);
    $wrongAnswers   = (int)($sRow['wrong_count']   ?? 0);
    $statsResult['result']->free();
}

// Get total question count for this assessment
$qCountResult = safePreparedQuery($conn,
    "SELECT COUNT(*) AS cnt FROM questions WHERE assessment_id = ?",
    "i", [$assessmentId]
);
if ($qCountResult['success'] && $qCountResult['result']) {
    $qcRow          = $qCountResult['result']->fetch_assoc();
    $totalQuestions = (int)($qcRow['cnt'] ?? 0);
    $qCountResult['result']->free();
}
$unanswered = max(0, $totalQuestions - $correctAnswers - $wrongAnswers);

// ── 4. Percentile ──
$percentileResult = safePreparedQuery($conn,
    "SELECT
        COUNT(*) AS total_attempts,
        SUM(CASE WHEN score < ? THEN 1 ELSE 0 END) AS below_count
     FROM assessment_attempts
     WHERE assessment_id = ?
       AND status        = 'submitted'
       AND user_id IS NOT NULL",
    "di", [(float)$attempt['score'], $assessmentId]
);

$percentile = 0;
if ($percentileResult['success'] && $percentileResult['result']) {
    $pRow = $percentileResult['result']->fetch_assoc();
    $percentileResult['result']->free();
    if ($pRow && (int)$pRow['total_attempts'] > 0) {
        $percentile = (int) round(((int)$pRow['below_count'] / (int)$pRow['total_attempts']) * 100);
    }
}

// ── 5. Category performance breakdown (using assessments.category) ──
// Since questions have no topic column, we group by the assessment category
$categoryPerformance = [];
if (!empty($attempt['category'])) {
    $categoryPerformance[] = [
        'topic'   => $attempt['category'],
        'correct' => $correctAnswers,
        'total'   => $totalQuestions,
    ];
}

// ── 6. Questions + answers ──
// Always return questions with student's answers and marks.
// Correct option is shown via is_correct on question_options.
$qResult = safePreparedQuery($conn,
    "SELECT
        q.question_id,
        q.question_text,
        q.question_type,
        q.marks,
        q.negative_marks,
        q.explanation,
        q.question_order,
        ans.selected_option_id,
        ans.text_answer,
        ans.marks_awarded
     FROM questions q
     LEFT JOIN answers ans ON ans.question_id  = q.question_id
                           AND ans.attempt_id   = ?
     WHERE q.assessment_id = ?
     ORDER BY q.question_order ASC, q.question_id ASC",
    "ii", [$attemptId, $assessmentId]
);

$questions = null;

if ($qResult['success'] && $qResult['result']) {
    $questions = [];
    $qNum      = 1;
    $qRows     = [];

    while ($row = $qResult['result']->fetch_assoc()) {
        $qRows[] = $row;
    }
    $qResult['result']->free();

    if (!empty($qRows)) {
        // Fetch all options for these questions in one query
        $qIds        = implode(',', array_map(fn($r) => (int)$r['question_id'], $qRows));
        $optResult   = safePreparedQuery($conn,
            "SELECT option_id, question_id, option_text, is_correct, option_order
             FROM question_options
             WHERE question_id IN ($qIds)
             ORDER BY question_id, option_order ASC",
            "", []
        );

        $optionsByQuestion = [];
        if ($optResult['success'] && $optResult['result']) {
            while ($opt = $optResult['result']->fetch_assoc()) {
                $optionsByQuestion[(int)$opt['question_id']][] = $opt;
            }
            $optResult['result']->free();
        }

        foreach ($qRows as $row) {
            $qid     = (int)$row['question_id'];
            $opts    = $optionsByQuestion[$qid] ?? [];
            $options = [];
            $correctOptionId = null;

            foreach ($opts as $opt) {
                $options[] = [
                    'optionId'  => (int)$opt['option_id'],
                    'text'      => $opt['option_text'],
                    'isCorrect' => (bool)$opt['is_correct'],
                ];
                if ((int)$opt['is_correct'] === 1) {
                    $correctOptionId = (int)$opt['option_id'];
                }
            }

            $marksAwarded   = $row['marks_awarded'] !== null ? (float)$row['marks_awarded'] : null;
            $selectedOption = $row['selected_option_id'] !== null ? (int)$row['selected_option_id'] : null;
            $isCorrect      = null;
            if ($marksAwarded !== null) {
                $isCorrect = $marksAwarded > 0;
            }

            $questions[] = [
                'questionNumber'   => $qNum++,
                'questionId'       => $qid,
                'text'             => $row['question_text'],
                'type'             => $row['question_type'],
                'options'          => $options,
                'correctOptionId'  => $correctOptionId,
                'selectedOptionId' => $selectedOption,
                'textAnswer'       => $row['text_answer'],
                'isCorrect'        => $isCorrect,
                'marksAwarded'     => $marksAwarded,
                'marks'            => (int)$row['marks'],
                'negativeMarks'    => (float)$row['negative_marks'],
                'explanation'      => $row['explanation'],
            ];
        }
    }
}

// ── 7. Build response ──
$passed = (float)$attempt['score'] >= (float)$attempt['passing_marks'];

echo json_encode([
    'success'             => true,
    'attemptId'           => (int) $attempt['attempt_id'],
    'assessmentId'        => $assessmentId,
    'testName'            => $attempt['test_name'],
    'completedAt'         => $attempt['submitted_at'],
    'score'               => (float) $attempt['score'],
    'totalMarks'          => (int) $attempt['total_marks'],
    'passingMarks'        => (int) $attempt['passing_marks'],
    'percentage'          => round((float)$attempt['percentage'], 2),
    'passed'              => $passed,
    'timeTakenSeconds'    => $timeTakenSeconds,
    'durationMinutes'     => (int) $attempt['duration_minutes'],
    'totalQuestions'      => $totalQuestions,
    'correctAnswers'      => $correctAnswers,
    'wrongAnswers'        => $wrongAnswers,
    'unanswered'          => $unanswered,
    'percentile'          => $percentile,
    'category'            => $attempt['category'],
    'categoryPerformance' => $categoryPerformance,
    'questions'           => $questions,
]);
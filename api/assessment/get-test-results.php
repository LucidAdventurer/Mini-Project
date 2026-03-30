<?php
// ============================================================
// api/assessment/get-test-results.php
//
// Returns full result data for one assessment attempt.
// Only the student who owns the attempt may view it.
//
// Stats (correct/wrong/unanswered) are derived from the answers
// table — assessment_attempts no longer stores those columns.
//
// Questions + options are only returned if the assessment's
// category allows it (there's no show_correct_answers flag in
// the current schema — we always return them to the student
// who completed the attempt; restrict in future if needed).
//
// GET ?attempt_id=int
// Returns {
//   success, testName, completedAt, score, totalMarks,
//   passingMarks, percentage, passed, timeTakenSeconds,
//   durationMinutes, totalQuestions, correctAnswers,
//   wrongAnswers, unanswered, percentile,
//   categoryPerformance: [...], questions: [...]
// }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$conn = createDatabaseConnection();
if (!$conn) { http_response_code(503); echo json_encode(['success'=>false,'error'=>'Database unavailable.']); exit; }

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

// ── 2. Time taken from DB timestamps ──
$timeTakenSeconds = 0;
if ($attempt['start_time'] && $attempt['submitted_at']) {
    $start     = new DateTime($attempt['start_time']);
    $submitted = new DateTime($attempt['submitted_at']);
    $timeTakenSeconds = max(0, $submitted->getTimestamp() - $start->getTimestamp());
}

// ── 3. Percentile ──
$percentileResult = safePreparedQuery($conn,
    "SELECT
        COUNT(*)                                            AS total_attempts,
        SUM(CASE WHEN score < ? THEN 1 ELSE 0 END)         AS below_count
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

// ── 4. Load all questions for this assessment ──
$qResult = safePreparedQuery($conn,
    "SELECT question_id, question_text, question_type, marks, negative_marks, explanation, question_order
     FROM questions
     WHERE assessment_id = ?
     ORDER BY question_order ASC",
    "i", [$assessmentId]
);
$questions = [];
$questionIds = [];
if ($qResult['success'] && $qResult['result']) {
    while ($row = $qResult['result']->fetch_assoc()) {
        $qid           = (int)$row['question_id'];
        $questions[$qid] = $row;
        $questionIds[]   = $qid;
    }
    $qResult['result']->free();
}

$totalQuestions = count($questionIds);

// ── 5. Load all options for these questions ──
$optionsByQuestion = []; // question_id => [option_id => [...], ...]
if (!empty($questionIds)) {
    $qidList   = implode(',', $questionIds);
    $optsResult = $conn->query(
        "SELECT option_id, question_id, option_text, is_correct, option_order
         FROM question_options
         WHERE question_id IN ($qidList)
         ORDER BY option_order ASC"
    );
    if ($optsResult) {
        while ($row = $optsResult->fetch_assoc()) {
            $qid = (int)$row['question_id'];
            $optionsByQuestion[$qid][(int)$row['option_id']] = [
                'option_id'    => (int)$row['option_id'],
                'option_text'  => $row['option_text'],
                'is_correct'   => (bool)$row['is_correct'],
                'option_order' => (int)$row['option_order'],
            ];
        }
        $optsResult->free();
    }
}

// ── 6. Load answers for this attempt ──
$answersByQuestion = []; // question_id => ['option_id' => int|null, 'text' => string|null, 'marks_awarded' => float]
$answersResult = safePreparedQuery($conn,
    "SELECT question_id, selected_option_id, text_answer, marks_awarded
     FROM answers WHERE attempt_id = ?",
    "i", [$attemptId]
);
if ($answersResult['success'] && $answersResult['result']) {
    while ($row = $answersResult['result']->fetch_assoc()) {
        $qid = (int)$row['question_id'];
        $answersByQuestion[$qid] = [
            'option_id'    => $row['selected_option_id'] !== null ? (int)$row['selected_option_id'] : null,
            'text'         => $row['text_answer'],
            'marks_awarded' => (float)$row['marks_awarded'],
        ];
    }
    $answersResult['result']->free();
}

// ── 7. Compute correct/wrong/unanswered ──
$correctCount    = 0;
$wrongCount      = 0;
$unansweredCount = 0;

foreach ($questionIds as $qid) {
    $ans = $answersByQuestion[$qid] ?? null;
    if ($ans === null) {
        $unansweredCount++;
    } elseif ($ans['marks_awarded'] > 0) {
        $correctCount++;
    } else {
        // marks_awarded == 0 could be unanswered text q or wrong; check if option/text was set
        $hasAnswer = ($ans['option_id'] !== null) || ($ans['text'] !== null && $ans['text'] !== '');
        if ($hasAnswer) {
            $wrongCount++;
        } else {
            $unansweredCount++;
        }
    }
}

// ── 8. Category performance — group by question_type as proxy for topic ──
// The schema has no topic column on questions; group by question_type instead.
$categoryPerformance = [];
$categoryMap = []; // type => ['correct' => 0, 'total' => 0]
foreach ($questionIds as $qid) {
    $qType = $questions[$qid]['question_type'] ?? 'mcq';
    if (!isset($categoryMap[$qType])) {
        $categoryMap[$qType] = ['correct' => 0, 'total' => 0];
    }
    $categoryMap[$qType]['total']++;
    $ans = $answersByQuestion[$qid] ?? null;
    if ($ans !== null && $ans['marks_awarded'] > 0) {
        $categoryMap[$qType]['correct']++;
    }
}
foreach ($categoryMap as $type => $counts) {
    $categoryPerformance[] = [
        'topic'   => $type,
        'correct' => $counts['correct'],
        'total'   => $counts['total'],
    ];
}

// ── 9. Build questions list with student's answer and correct options ──
// The frontend (buildQuestionCard) expects:
//   options       => { 'a': 'text', 'b': 'text', ... }  (label-keyed map)
//   correctAnswer => 'a' | 'b' | ...  (label of the correct option)
//   userAnswer    => 'a' | 'b' | null (label of the student's selected option)
//   marksObtained => float            (alias for marksAwarded used by frontend)
$labels = ['a', 'b', 'c', 'd', 'e', 'f'];

$questionList = [];
$qNum = 1;
foreach ($questionIds as $qid) {
    $q    = $questions[$qid];
    $ans  = $answersByQuestion[$qid] ?? null;
    $opts = $optionsByQuestion[$qid] ?? [];

    $selectedOptionId = $ans['option_id']    ?? null;
    $textAnswer       = $ans['text']          ?? null;
    $marksAwarded     = $ans['marks_awarded'] ?? 0.0;

    // Sort options by option_order so labels are always a, b, c, d in order
    usort($opts, fn($x, $y) => $x['option_order'] <=> $y['option_order']);

    // Build label-keyed map; track which label is correct and which the student picked
    $optionsMap   = [];
    $correctLabel = null;
    $userLabel    = null;
    $idx          = 0;
    foreach ($opts as $opt) {
        $label = $labels[$idx] ?? chr(97 + $idx);
        $optionsMap[$label] = $opt['option_text'];
        if ($opt['is_correct'])                      $correctLabel = $label;
        if ($opt['option_id'] === $selectedOptionId) $userLabel    = $label;
        $idx++;
    }

    // Determine correctness
    $isCorrect = false;
    if ($userLabel !== null) {
        $isCorrect = ($userLabel === $correctLabel);
    } elseif ($textAnswer !== null && $marksAwarded > 0) {
        $isCorrect = true;
    }

    $questionList[] = [
        'questionNumber' => $qNum++,
        'questionId'     => $qid,
        'text'           => $q['question_text'],
        'questionText'   => $q['question_text'],
        'type'           => $q['question_type'],
        'marks'          => (int)$q['marks'],
        'negativeMarks'  => (float)$q['negative_marks'],
        'explanation'    => $q['explanation'],
        'options'        => $optionsMap,
        'correctAnswer'  => $correctLabel,
        'userAnswer'     => $userLabel,
        'textAnswer'     => $textAnswer,
        'isCorrect'      => $isCorrect,
        'marksAwarded'   => $marksAwarded,
        'marksObtained'  => $marksAwarded,
    ];
}

// ── 10. Build response ──
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
    'correctAnswers'      => $correctCount,
    'wrongAnswers'        => $wrongCount,
    'unanswered'          => $unansweredCount,
    'percentile'          => $percentile,
    'category'            => $attempt['category'],
    'categoryPerformance' => $categoryPerformance,
    'questions'           => $questionList,
]);
<?php
// ============================================================
// api/guest/grade-test.php
//
// Grades a guest (or logged-in) test submission server-side.
// Correct answers are NEVER sent to the browser before this
// endpoint is called — they live only in the DB and session.
//
// POST JSON {
//   assessment_id : int,
//   answers       : { "<question_id>": "A"|"B"|"C"|"D"|"True"|"False" }
// }
//
// Returns {
//   success      : bool,
//   score        : float,
//   total_marks  : int,
//   passing_marks: int,
//   passed       : bool,
//   pct          : int,
//   answered     : int,
//   total_q      : int,
//   // Only present when show_correct_answers = 1:
//   results      : { "<question_id>": { correct: bool, correct_answer: string } }
// }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

// ── Method ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Parse body ──
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body.']);
    exit;
}

$assessmentId = (int)($body['assessment_id'] ?? 0);
$submitted    = $body['answers'] ?? null;

if ($assessmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid assessment ID.']);
    exit;
}

if (!is_array($submitted) || empty($submitted)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No answers submitted.']);
    exit;
}

// ── Verify assessment is still active & public ──
// New schema: assessments table columns are identical — assessment_id,
//   total_marks, passing_marks, show_correct_answers, is_public, status.
$aRes = safePreparedQuery(
    $conn,
    "SELECT assessment_id, total_marks, passing_marks, show_correct_answers,
            randomize_questions, questions_per_attempt
     FROM assessments
     WHERE assessment_id = ? AND is_public = 1 AND status = 'active'",
    "i", [$assessmentId]
);

if (!$aRes['success'] || !$aRes['result'] || $aRes['result']->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Assessment not found or no longer available.']);
    exit;
}

$assmt = $aRes['result']->fetch_assoc();
$aRes['result']->free();

$totalMarks          = (int)$assmt['total_marks'];
$passingMarks        = (int)$assmt['passing_marks'];
$showCorrectAnswers  = (bool)$assmt['show_correct_answers'];
$randomizeQuestions  = (bool)$assmt['randomize_questions'];

// ── Resolve the attempt_id for this submission ──
// The new schema introduces attempt_questions: when questions are randomised
// (or a subset is served via questions_per_attempt), the exact set of
// question IDs assigned to an attempt is stored there.
// We check the session for a guest attempt_id set by the test-delivery layer.
// If found, we restrict grading to only those question IDs — preventing a
// client from submitting answers for questions they were never served.
$allowedQuestionIds = null; // null = no restriction (all questions allowed)

if ($randomizeQuestions || $assmt['questions_per_attempt'] !== null) {
    $attemptId = isset($_SESSION['current_attempt_id'])
                 ? (int)$_SESSION['current_attempt_id']
                 : 0;

    if ($attemptId > 0) {
        // Verify the attempt belongs to this assessment before trusting it
        $aqRes = safePreparedQuery(
            $conn,
            "SELECT aq.question_id
             FROM attempt_questions aq
             INNER JOIN assessment_attempts aa
                     ON aa.attempt_id = aq.attempt_id
            WHERE aq.attempt_id  = ?
              AND aa.assessment_id = ?",
            "ii", [$attemptId, $assessmentId]
        );

        if ($aqRes['success'] && $aqRes['result']) {
            $allowedQuestionIds = [];
            while ($r = $aqRes['result']->fetch_assoc()) {
                $allowedQuestionIds[] = (int)$r['question_id'];
            }
            $aqRes['result']->free();

            if (empty($allowedQuestionIds)) {
                // attempt_id found but belongs to a different assessment — reject
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Invalid attempt for this assessment.']);
                exit;
            }
        }
        // If the query fails, fall through without restriction (graceful degradation)
    }
}

// ── Fetch questions (marks, negative_marks, correct_answer) ──
// Only fetch question IDs submitted by the client to avoid loading
// the entire question bank. Validate each ID is an integer first.
$submittedIds = [];
foreach (array_keys($submitted) as $qid) {
    $int = (int)$qid;
    if ($int > 0) {
        // If we have an allowed set (randomised/subset attempt), silently drop
        // any question IDs the client submitted that weren't part of their attempt.
        if ($allowedQuestionIds !== null && !in_array($int, $allowedQuestionIds, true)) {
            continue;
        }
        $submittedIds[] = $int;
    }
}

if (empty($submittedIds)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No valid question IDs in submission.']);
    exit;
}

// Fetch only questions that belong to this assessment (ownership enforced by assessment_id join)
$placeholders = implode(',', array_fill(0, count($submittedIds), '?'));
$types        = 'i' . str_repeat('i', count($submittedIds));
$params       = array_merge([$assessmentId], $submittedIds);

$qRes = safePreparedQuery(
    $conn,
    "SELECT question_id, correct_answer, marks, negative_marks, question_type
     FROM questions
     WHERE assessment_id = ? AND question_id IN ($placeholders)
       AND question_type IN ('mcq','true_false')",
    $types, $params
);

if (!$qRes['success'] || !$qRes['result']) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to load questions.']);
    exit;
}

$questions = [];
while ($row = $qRes['result']->fetch_assoc()) {
    $questions[(int)$row['question_id']] = $row;
}
$qRes['result']->free();

// ── Grade ──
$score    = 0.0;
$answered = 0;
$results  = [];

foreach ($questions as $qid => $q) {
    $clientAnswer = isset($submitted[(string)$qid]) ? strtoupper(trim($submitted[(string)$qid])) : null;

    if ($clientAnswer === null || $clientAnswer === '') {
        // Unanswered — score zero, no penalty
        if ($showCorrectAnswers) {
            $results[(string)$qid] = [
                'correct'        => false,
                'skipped'        => true,
                'correct_answer' => $q['correct_answer'],
            ];
        }
        continue;
    }

    $answered++;
    $correctAnswer = strtoupper(trim($q['correct_answer']));
    $isCorrect     = ($clientAnswer === $correctAnswer);

    if ($isCorrect) {
        $score += (float)$q['marks'];
    } else {
        // Wrong answer: apply negative marks (already confirmed no penalty for skipped)
        $score -= (float)$q['negative_marks'];
    }

    if ($showCorrectAnswers) {
        $results[(string)$qid] = [
            'correct'        => $isCorrect,
            'skipped'        => false,
            'correct_answer' => $q['correct_answer'],
        ];
    }
}

// Floor at zero
$score = max(0.0, round($score, 2));
$pct   = $totalMarks > 0 ? (int)round(($score / $totalMarks) * 100) : 0;
$passed = ($score >= $passingMarks);

// ── Build response ──
$response = [
    'success'      => true,
    'score'        => $score,
    'total_marks'  => $totalMarks,
    'passing_marks'=> $passingMarks,
    'passed'       => $passed,
    'pct'          => $pct,
    'answered'     => $answered,
    'total_q'      => count($questions),
];

if ($showCorrectAnswers) {
    $response['results'] = $results;
}

echo json_encode($response);
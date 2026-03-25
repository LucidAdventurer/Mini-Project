<?php
// ============================================================
// api/guest/grade-test.php
//
// Grades a guest (or logged-in) test submission server-side.
// Correct answers live in question_options.is_correct — they
// are never sent to the browser before this endpoint is called.
//
// POST JSON {
//   assessment_id : int,
//   answers       : { "<question_id>": <option_id (int)> }
// }
//
// Returns {
//   success      : bool,
//   score        : float,
//   total_marks  : float,
//   passing_marks: float,
//   passed       : bool,
//   pct          : int,
//   answered     : int,
//   total_q      : int,
//   results      : {
//     "<question_id>": {
//       correct          : bool,
//       skipped          : bool,
//       correct_option_id: int        -- always returned so front-end can highlight
//     }
//   }
// }
//
// SCHEMA NOTES (live DB, March 2026):
//  - assessments: visibility enum('public','group','private'), status enum('draft','published','archived')
//    NO is_public column, NO show_correct_answers, NO questions_per_attempt column.
//  - questions: NO correct_answer column. Correct answers are rows in question_options
//    with is_correct = 1.
//  - attempt_questions table does NOT exist in live DB — removed entirely.
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

// ── Method ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Parse body ────────────────────────────────────────────────────────────
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

// ── Verify assessment: visibility='public', status='published' ────────────
// FIX: old code used is_public=1 and status='active' — neither exist.
//      Live schema: visibility enum('public','group','private'),
//                   status     enum('draft','published','archived').
$aRes = safePreparedQuery(
    $conn,
    "SELECT a.assessment_id, a.total_marks, a.passing_marks, a.randomize_questions
     FROM assessments a
     INNER JOIN users u ON u.user_id = a.created_by
     WHERE a.assessment_id  = ?
       AND a.visibility     = 'public'
       AND a.status         = 'published'
       AND u.role           IN ('admin', 'teacher')
       AND u.is_active      = 1
       AND (a.start_time IS NULL OR a.start_time <= NOW())
       AND (a.end_time   IS NULL OR a.end_time   >= NOW())",
    "i", [$assessmentId]
);

if (!$aRes['success'] || !$aRes['result'] || $aRes['result']->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Assessment not found or no longer available.']);
    exit;
}

$assmt = $aRes['result']->fetch_assoc();
$aRes['result']->free();

$totalMarks   = (float)$assmt['total_marks'];
$passingMarks = (float)$assmt['passing_marks'];
// FIX: show_correct_answers column does not exist — always return results
//      so the front-end review panel works. No sensitive data is leaked
//      because we only return correct_option_id AFTER submission.
// FIX: questions_per_attempt and attempt_questions table do not exist —
//      removed the entire subset-restriction block. All submitted question
//      IDs are validated against assessment ownership instead.

// ── Sanitise submitted question IDs ──────────────────────────────────────
$submittedIds = [];
foreach (array_keys($submitted) as $qid) {
    $int = (int)$qid;
    if ($int > 0) {
        $submittedIds[] = $int;
    }
}

if (empty($submittedIds)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No valid question IDs in submission.']);
    exit;
}

// ── Fetch questions (marks, negative_marks) ───────────────────────────────
// FIX: old code selected correct_answer FROM questions — that column does
//      NOT exist. Marks and type are all we need here; correctness comes
//      from question_options below.
$placeholders = implode(',', array_fill(0, count($submittedIds), '?'));
$types        = 'i' . str_repeat('i', count($submittedIds));
$params       = array_merge([$assessmentId], $submittedIds);

$qRes = safePreparedQuery(
    $conn,
    "SELECT question_id, marks, negative_marks, question_type
     FROM questions
     WHERE assessment_id = ?
       AND question_id IN ($placeholders)
       AND question_type IN ('mcq', 'true_false')",
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

if (empty($questions)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No valid questions found for this assessment.']);
    exit;
}

// ── Fetch correct options for every question in one query ─────────────────
// FIX: correct answers live in question_options.is_correct = 1, not in a
//      questions.correct_answer column.
$qIds        = array_keys($questions);
$oPlaceholders = implode(',', array_fill(0, count($qIds), '?'));
$oTypes        = str_repeat('i', count($qIds));

$oRes = safePreparedQuery(
    $conn,
    "SELECT question_id, option_id
     FROM question_options
     WHERE question_id IN ($oPlaceholders)
       AND is_correct = 1",
    $oTypes, $qIds
);

// Map question_id → correct option_id
$correctOptionMap = [];
if ($oRes['success'] && $oRes['result']) {
    while ($row = $oRes['result']->fetch_assoc()) {
        // For MCQ/true_false there is exactly one correct option per question.
        $correctOptionMap[(int)$row['question_id']] = (int)$row['option_id'];
    }
    $oRes['result']->free();
}

// ── Grade ─────────────────────────────────────────────────────────────────
$score    = 0.0;
$answered = 0;
$results  = [];

foreach ($questions as $qid => $q) {
    // Client submits option_id (integer) for each question_id
    $chosenOptionId = isset($submitted[(string)$qid])
        ? (int)$submitted[(string)$qid]
        : null;

    $correctOptionId = $correctOptionMap[$qid] ?? null;

    if (!$chosenOptionId) {
        // Unanswered / skipped — no penalty
        $results[(string)$qid] = [
            'correct'           => false,
            'skipped'           => true,
            'correct_option_id' => $correctOptionId,
        ];
        continue;
    }

    $answered++;
    $isCorrect = ($correctOptionId !== null && $chosenOptionId === $correctOptionId);

    if ($isCorrect) {
        $score += (float)$q['marks'];
    } else {
        $score -= (float)($q['negative_marks'] ?? 0);
    }

    $results[(string)$qid] = [
        'correct'           => $isCorrect,
        'skipped'           => false,
        'correct_option_id' => $correctOptionId,
    ];
}

// Floor score at zero — negative totals are not meaningful
$score  = max(0.0, round($score, 2));
$pct    = $totalMarks > 0 ? (int)round(($score / $totalMarks) * 100) : 0;
$passed = ($score >= $passingMarks);

// ── Respond ───────────────────────────────────────────────────────────────
echo json_encode([
    'success'       => true,
    'score'         => $score,
    'total_marks'   => $totalMarks,
    'passing_marks' => $passingMarks,
    'passed'        => $passed,
    'pct'           => $pct,
    'answered'      => $answered,
    'total_q'       => count($questions),
    'results'       => $results,   // always returned; front-end uses correct_option_id to highlight
]);
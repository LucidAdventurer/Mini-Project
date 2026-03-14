<?php
// ============================================================
// api/guest/get-test-questions.php
//
// Returns a public published assessment + its MCQ / true-false
// questions (with options) for the inline test modal.
//
// The is_correct flag on each option is included so the
// front-end can do a client-side fallback grade if the
// grade-test endpoint is unreachable.  The server-side
// grade-test.php endpoint is still the authoritative grader.
//
// GET ?assessment_id=<int>
//
// Response:
// {
//   success     : true,
//   assessment  : { assessment_id, title, description, category,
//                   difficulty, duration_minutes, total_marks,
//                   passing_marks, randomize_options },
//   questions   : [
//     {
//       question_id, question_text, question_type,
//       marks, negative_marks, explanation,
//       options: [{ option_id, option_text, is_correct, option_order }]
//     }, ...
//   ]
// }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$assessmentId = (int)($_GET['assessment_id'] ?? 0);
if ($assessmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid assessment ID.']);
    exit;
}

// ── Verify assessment is public + published, created by admin/teacher ──
$aRes = safePreparedQuery(
    $conn,
    "SELECT a.assessment_id, a.title, a.description, a.category,
            a.difficulty, a.duration_minutes, a.total_marks,
            a.passing_marks, a.randomize_questions, a.randomize_options,
            u.role AS creator_role
     FROM assessments a
     INNER JOIN users u ON u.user_id = a.created_by
     WHERE a.assessment_id = ?
       AND a.visibility    = 'public'
       AND a.status        = 'published'
       AND u.role          IN ('admin', 'teacher')
       AND u.is_active     = 1
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

// ── Fetch questions (MCQ and true/false only) ──
$qRes = safePreparedQuery(
    $conn,
    "SELECT question_id, question_text, question_type,
            marks, negative_marks, explanation, question_order
     FROM questions
     WHERE assessment_id  = ?
       AND question_type  IN ('mcq', 'true_false')
     ORDER BY question_order ASC, question_id ASC",
    "i", [$assessmentId]
);

if (!$qRes['success'] || !$qRes['result']) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to load questions.']);
    exit;
}

$questions = [];
$qIds      = [];
while ($row = $qRes['result']->fetch_assoc()) {
    $row['options'] = [];
    $questions[(int)$row['question_id']] = $row;
    $qIds[] = (int)$row['question_id'];
}
$qRes['result']->free();

if (empty($qIds)) {
    // Assessment exists but has no eligible questions yet
    echo json_encode([
        'success'    => true,
        'assessment' => $assmt,
        'questions'  => [],
    ]);
    exit;
}

// ── Fetch options for all questions in one query ──
$placeholders = implode(',', array_fill(0, count($qIds), '?'));
$types        = str_repeat('i', count($qIds));

$oRes = safePreparedQuery(
    $conn,
    "SELECT option_id, question_id, option_text, is_correct, option_order
     FROM question_options
     WHERE question_id IN ($placeholders)
     ORDER BY option_order ASC, option_id ASC",
    $types, $qIds
);

if ($oRes['success'] && $oRes['result']) {
    while ($opt = $oRes['result']->fetch_assoc()) {
        $qid = (int)$opt['question_id'];
        if (isset($questions[$qid])) {
            $questions[$qid]['options'][] = [
                'option_id'    => (int)$opt['option_id'],
                'option_text'  => $opt['option_text'],
                'is_correct'   => (int)$opt['is_correct'],   // needed for client-side fallback grading
                'option_order' => (int)$opt['option_order'],
            ];
        }
    }
    $oRes['result']->free();
}

// ── Optionally shuffle question order ──
$qList = array_values($questions);
if ($assmt['randomize_questions']) {
    shuffle($qList);
}

// ── Build response ──
echo json_encode([
    'success'    => true,
    'assessment' => [
        'assessment_id'    => (int)$assmt['assessment_id'],
        'title'            => $assmt['title'],
        'description'      => $assmt['description'],
        'category'         => $assmt['category'],
        'difficulty'       => $assmt['difficulty'],
        'duration_minutes' => (int)$assmt['duration_minutes'],
        'total_marks'      => (float)$assmt['total_marks'],
        'passing_marks'    => (float)$assmt['passing_marks'],
        'randomize_options'=> (bool)$assmt['randomize_options'],
    ],
    'questions'  => $qList,
]);

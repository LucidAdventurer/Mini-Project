<?php
// ============================================================
// api/admin/get-test-questions.php
//
// Returns all questions + options for a given assessment.
// Options are now in question_options table (no option_a/b/c/d on questions).
// questions table no longer has: topic, difficulty, correct_answer, option_a/b/c/d
//
// GET ?assessment_id=<int>
// Returns { success, assessment:{...}, questions:[...] }
// ============================================================
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';
header('Content-Type: application/json');
validateSession($conn, 'admin');

$assessmentId = (int)($_GET['assessment_id'] ?? 0);
if ($assessmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'Invalid assessment ID.']);
    exit;
}

// Assessment info
$ra = safePreparedQuery($conn,
    "SELECT a.assessment_id, a.title, a.category, a.difficulty, a.total_marks,
            a.duration_minutes, a.status, u.full_name AS creator_name
     FROM assessments a LEFT JOIN users u ON u.user_id = a.created_by
     WHERE a.assessment_id = ?",
    "i", [$assessmentId]);
if (!$ra['success'] || !$ra['result'] || $ra['result']->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success'=>false,'error'=>'Assessment not found.']);
    exit;
}
$assessment = $ra['result']->fetch_assoc();
$ra['result']->free();

// Questions — no option_a/b/c/d, no correct_answer, no topic, no difficulty on questions table
$rq = safePreparedQuery($conn,
    "SELECT question_id, question_type, question_text, marks, negative_marks,
            explanation, question_order
     FROM questions
     WHERE assessment_id = ?
     ORDER BY question_order ASC, question_id ASC",
    "i", [$assessmentId]);

$questions = [];
if ($rq['success'] && $rq['result']) {
    while ($row = $rq['result']->fetch_assoc()) {
        $questions[$row['question_id']] = [
            'question_id'    => (int)$row['question_id'],
            'question_type'  => $row['question_type'],
            'question_text'  => $row['question_text'],
            'marks'          => (int)$row['marks'],
            'negative_marks' => (float)$row['negative_marks'],
            'explanation'    => $row['explanation'],
            'question_order' => (int)$row['question_order'],
            'options'        => [],
        ];
    }
    $rq['result']->free();
}

// Fetch options from question_options table
if (!empty($questions)) {
    $ids       = implode(',', array_keys($questions));
    $ro = safePreparedQuery($conn,
        "SELECT option_id, question_id, option_text, is_correct, option_order
         FROM question_options
         WHERE question_id IN ($ids)
         ORDER BY question_id, option_order ASC",
        "", []);
    if ($ro['success'] && $ro['result']) {
        while ($opt = $ro['result']->fetch_assoc()) {
            $qid = (int)$opt['question_id'];
            if (isset($questions[$qid])) {
                $questions[$qid]['options'][] = [
                    'option_id'    => (int)$opt['option_id'],
                    'option_text'  => $opt['option_text'],
                    'is_correct'   => (bool)$opt['is_correct'],
                    'option_order' => (int)$opt['option_order'],
                ];
            }
        }
        $ro['result']->free();
    }
}

echo json_encode(['success'=>true,'assessment'=>$assessment,'questions'=>array_values($questions)]);
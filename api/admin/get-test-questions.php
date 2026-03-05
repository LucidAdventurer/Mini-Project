<?php
// ============================================================
// api/admin/get-test-questions.php
//
// Returns all questions for a given assessment (read-only view).
// Admin can view questions for any assessment.
//
// GET ?assessment_id=<int>
//
// Returns {
//   success, assessment: { title, ... }, questions: [...]
// }
// ============================================================
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');
validateSession($conn, 'admin');

$assessmentId = (int)($_GET['assessment_id'] ?? 0);

if ($assessmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid assessment ID.']);
    exit;
}

// Fetch assessment info
$ra = safePreparedQuery($conn,
    "SELECT a.assessment_id, a.title, a.category, a.difficulty,
            a.total_marks, a.duration_minutes, a.status,
            u.full_name AS creator_name
     FROM assessments a
     LEFT JOIN users u ON u.user_id = a.created_by
     WHERE a.assessment_id = ?",
    "i", [$assessmentId]
);
if (!$ra['success'] || !$ra['result'] || $ra['result']->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Assessment not found.']);
    exit;
}
$assessment = $ra['result']->fetch_assoc();
$ra['result']->free();

// Fetch questions
$rq = safePreparedQuery($conn,
    "SELECT question_id, question_type, question_text, marks, negative_marks,
            option_a, option_b, option_c, option_d,
            correct_answer, explanation, topic, question_order, difficulty
     FROM questions
     WHERE assessment_id = ?
     ORDER BY question_order ASC, question_id ASC",
    "i", [$assessmentId]
);

$questions = [];
if ($rq['success'] && $rq['result']) {
    while ($row = $rq['result']->fetch_assoc()) {
        $questions[] = [
            'question_id'    => (int)$row['question_id'],
            'question_type'  => $row['question_type'],
            'question_text'  => $row['question_text'],
            'marks'          => (int)$row['marks'],
            'negative_marks' => (float)$row['negative_marks'],
            'option_a'       => $row['option_a'],
            'option_b'       => $row['option_b'],
            'option_c'       => $row['option_c'],
            'option_d'       => $row['option_d'],
            'correct_answer' => $row['correct_answer'],
            'explanation'    => $row['explanation'],
            'topic'          => $row['topic'],
            'question_order' => (int)$row['question_order'],
            'difficulty'     => $row['difficulty'],
        ];
    }
    $rq['result']->free();
}

echo json_encode([
    'success'    => true,
    'assessment' => $assessment,
    'questions'  => $questions,
]);

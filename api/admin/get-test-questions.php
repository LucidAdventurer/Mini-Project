<?php
// ============================================================
// api/admin/get-test-questions.php
//
// Returns all questions + options for a given assessment.
// Uses fully parameterized queries — no raw SQL injection risk.
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
    echo json_encode(['success' => false, 'error' => 'Invalid assessment ID.']);
    exit;
}

// ── Fetch assessment info ─────────────────────────────────────────────────
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

// ── Fetch questions ───────────────────────────────────────────────────────
$rq = safePreparedQuery($conn,
    "SELECT question_id, question_type, question_text,
            marks, negative_marks, explanation, question_order
     FROM questions
     WHERE assessment_id = ?
     ORDER BY question_order ASC, question_id ASC",
    "i", [$assessmentId]
);

$questions   = [];
$questionIds = [];

if ($rq['success'] && $rq['result']) {
    while ($row = $rq['result']->fetch_assoc()) {
        $qid = (int)$row['question_id'];
        $questions[$qid] = [
            'question_id'    => $qid,
            'question_type'  => $row['question_type'],
            'question_text'  => $row['question_text'],
            'marks'          => (int)$row['marks'],
            'negative_marks' => (float)$row['negative_marks'],
            'explanation'    => $row['explanation'],
            'question_order' => (int)$row['question_order'],
            'options'        => [],
        ];
        $questionIds[] = $qid;
    }
    $rq['result']->free();
}

// ── Fetch options using fully parameterized IN clause ─────────────────────
if (!empty($questionIds)) {
    $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
    $types        = str_repeat('i', count($questionIds));

    $ro = safePreparedQuery($conn,
        "SELECT option_id, question_id, option_text, is_correct, option_order
         FROM question_options
         WHERE question_id IN ($placeholders)
         ORDER BY question_id ASC, option_order ASC",
        $types, $questionIds
    );

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

echo json_encode([
    'success'    => true,
    'assessment' => $assessment,
    'questions'  => array_values($questions),
]);
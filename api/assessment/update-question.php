<?php
// ============================================================
// api/assessment/update-question.php
//
// Saves edits to an existing question.
// Verifies the question belongs to an assessment owned by the
// logged-in teacher before writing any changes.
//
// POST JSON {
//   question_id, assessment_id,
//   question_text, correct_answer,
//   option_a?, option_b?, option_c?, option_d?,
//   marks, negative_marks, topic?, explanation?
// }
// Returns { success: bool, error?: string }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$currentUser = validateSession($conn, 'teacher');
$teacherId   = (int) $currentUser['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body.']);
    exit;
}

// ── Required fields ──
$questionId   = (int)($body['question_id']   ?? 0);
$assessmentId = (int)($body['assessment_id'] ?? 0);
$questionText = trim($body['question_text']  ?? '');
$correctAnswer = trim($body['correct_answer'] ?? '');

if ($questionId <= 0 || $assessmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid question or assessment ID.']);
    exit;
}
if ($questionText === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Question text is required.']);
    exit;
}
if ($correctAnswer === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Correct answer is required.']);
    exit;
}

// ── Numeric fields ──
$marks       = max(1, (int)($body['marks']          ?? 1));
$negMarks    = max(0, (float)($body['negative_marks'] ?? 0));
$negMarks    = round($negMarks, 2);

// ── Optional fields ──
$optionA     = isset($body['option_a']) ? trim($body['option_a']) : null;
$optionB     = isset($body['option_b']) ? trim($body['option_b']) : null;
$optionC     = isset($body['option_c']) && $body['option_c'] !== '' ? trim($body['option_c']) : null;
$optionD     = isset($body['option_d']) && $body['option_d'] !== '' ? trim($body['option_d']) : null;
$topic       = trim($body['topic']       ?? '');
$explanation = trim($body['explanation'] ?? '');

// Normalise correct_answer to uppercase for MCQ types
// (could be a single letter or comma-separated list for multiple_select)
$correctAnswer = strtoupper($correctAnswer);

// ── Verify this question belongs to an assessment owned by this teacher ──
$check = safePreparedQuery($conn,
    "SELECT q.question_id, q.question_type
     FROM questions q
     JOIN assessments a ON a.assessment_id = q.assessment_id
     WHERE q.question_id = ?
       AND q.assessment_id = ?
       AND a.created_by = ?",
    "iii", [$questionId, $assessmentId, $teacherId]
);

if (!$check['success'] || !$check['result'] || $check['result']->num_rows === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Question not found or access denied.']);
    exit;
}
$qRow      = $check['result']->fetch_assoc();
$qType     = $qRow['question_type'];
$check['result']->free();

// ── Type-specific validation ──
$mcqTypes = ['mcq', 'true_false', 'multiple_select'];

if (in_array($qType, $mcqTypes, true)) {
    if ($optionA === null || $optionA === '' || $optionB === null || $optionB === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Options A and B are required for this question type.']);
        exit;
    }

    if ($qType === 'multiple_select') {
        // Correct answer is comma-separated letters e.g. "A,C"
        $letters = array_filter(array_map('trim', explode(',', $correctAnswer)));
        $valid   = array_filter($letters, fn($l) => in_array($l, ['A','B','C','D'], true));
        if (count($valid) === 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Correct answer must be one or more of A, B, C, D separated by commas.']);
            exit;
        }
        $correctAnswer = implode(',', array_unique($valid));
    } elseif ($qType === 'true_false') {
        if (!in_array($correctAnswer, ['A', 'B'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Correct answer for True/False must be A or B.']);
            exit;
        }
    } else {
        // mcq
        if (!in_array($correctAnswer, ['A', 'B', 'C', 'D'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Correct answer must be A, B, C, or D.']);
            exit;
        }
    }
}

// ── Update question ──
$result = safePreparedQuery($conn,
    "UPDATE questions SET
        question_text  = ?,
        correct_answer = ?,
        option_a       = ?,
        option_b       = ?,
        option_c       = ?,
        option_d       = ?,
        marks          = ?,
        negative_marks = ?,
        topic          = ?,
        explanation    = ?
     WHERE question_id = ? AND assessment_id = ?",
    "ssssssidssii",
    [
        $questionText, $correctAnswer,
        $optionA, $optionB, $optionC, $optionD,
        $marks, $negMarks,
        $topic ?: null,
        $explanation ?: null,
        $questionId, $assessmentId,
    ]
);

if ($result['success'] && $result['affected_rows'] >= 0) {
    // Also update assessment's updated_at so the dashboard reflects a change
    safePreparedQuery($conn,
        "UPDATE assessments SET updated_at = NOW() WHERE assessment_id = ? AND created_by = ?",
        "ii", [$assessmentId, $teacherId]
    );
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Question update failed. Please try again.']);
}
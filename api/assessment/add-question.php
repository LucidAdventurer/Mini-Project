<?php
// ============================================================
// api/assessment/add-question.php
//
// Adds a new question to an existing assessment.
// Verifies the logged-in teacher owns the assessment.
// Auto-assigns question_order as (current max + 1).
//
// POST JSON {
//   assessment_id, question_type,
//   question_text, correct_answer,
//   option_a?, option_b?, option_c?, option_d?,
//   marks, negative_marks?, topic?, explanation?
// }
// Returns { success: bool, question_id?: int, error?: string }
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
$assessmentId  = (int)($body['assessment_id']  ?? 0);
$questionType  = trim($body['question_type']   ?? '');
$questionText  = trim($body['question_text']   ?? '');
$correctAnswer = trim($body['correct_answer']  ?? '');

if ($assessmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid assessment ID.']);
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

$allowedTypes = ['mcq', 'multiple_select', 'true_false', 'short_answer', 'fill_blank', 'match'];
if (!in_array($questionType, $allowedTypes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid question type.']);
    exit;
}

// ── Numeric fields ──
$marks    = max(1, (int)($body['marks']          ?? 1));
$negMarks = max(0, (float)($body['negative_marks'] ?? 0));
$negMarks = round($negMarks, 2);

// ── Optional text fields ──
$optionA     = isset($body['option_a']) && $body['option_a'] !== '' ? trim($body['option_a']) : null;
$optionB     = isset($body['option_b']) && $body['option_b'] !== '' ? trim($body['option_b']) : null;
$optionC     = isset($body['option_c']) && $body['option_c'] !== '' ? trim($body['option_c']) : null;
$optionD     = isset($body['option_d']) && $body['option_d'] !== '' ? trim($body['option_d']) : null;
$topic       = trim($body['topic']       ?? '') ?: null;
$explanation = trim($body['explanation'] ?? '') ?: null;

// ── Type-specific validation ──
$mcqTypes = ['mcq', 'multiple_select', 'true_false'];

if (in_array($questionType, $mcqTypes, true)) {
    if ($optionA === null || $optionB === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Options A and B are required for this question type.']);
        exit;
    }

    $correctAnswer = strtoupper($correctAnswer);

    if ($questionType === 'multiple_select') {
        $letters = array_filter(array_map('trim', explode(',', $correctAnswer)));
        $valid   = array_filter($letters, fn($l) => in_array($l, ['A','B','C','D'], true));
        if (count($valid) === 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Correct answer must be one or more of A, B, C, D separated by commas.']);
            exit;
        }
        $correctAnswer = implode(',', array_unique($valid));
    } elseif ($questionType === 'true_false') {
        // For True/False, force option values and constrain correct answer to A or B
        $optionA = $optionA ?? 'True';
        $optionB = $optionB ?? 'False';
        if (!in_array($correctAnswer, ['A', 'B'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Correct answer for True/False must be A (True) or B (False).']);
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

// ── Verify ownership of the assessment ──
$check = safePreparedQuery($conn,
    "SELECT assessment_id FROM assessments WHERE assessment_id = ? AND created_by = ?",
    "ii", [$assessmentId, $teacherId]
);

if (!$check['success'] || !$check['result'] || $check['result']->num_rows === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Assessment not found or access denied.']);
    exit;
}
$check['result']->free();

// ── Determine next question_order ──
$orderResult = safePreparedQuery($conn,
    "SELECT COALESCE(MAX(question_order), 0) + 1 AS next_order FROM questions WHERE assessment_id = ?",
    "i", [$assessmentId]
);
$nextOrder = 1;
if ($orderResult['success'] && $orderResult['result']) {
    $row       = $orderResult['result']->fetch_assoc();
    $nextOrder = (int)($row['next_order'] ?? 1);
    $orderResult['result']->free();
}

// ── Insert the new question ──
$insert = safePreparedQuery($conn,
    "INSERT INTO questions
        (assessment_id, question_type, question_text, marks, negative_marks,
         option_a, option_b, option_c, option_d,
         correct_answer, topic, explanation, question_order)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
    "issidsssssssi",
    [
        $assessmentId, $questionType, $questionText,
        $marks, $negMarks,
        $optionA, $optionB, $optionC, $optionD,
        $correctAnswer, $topic, $explanation,
        $nextOrder,
    ]
);

if ($insert['success'] && $insert['insert_id'] > 0) {
    // Touch assessment updated_at
    safePreparedQuery($conn,
        "UPDATE assessments SET updated_at = NOW() WHERE assessment_id = ? AND created_by = ?",
        "ii", [$assessmentId, $teacherId]
    );
    echo json_encode(['success' => true, 'question_id' => $insert['insert_id']]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to add question. Please try again.']);
}
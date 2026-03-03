<?php
// ============================================================
// api/assessment/add-question.php
//
// FIXES:
// - Race condition on question_order fixed with transaction +
//   SELECT MAX ... FOR UPDATE, then retry on duplicate key.
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
$marks    = max(1, (int)($body['marks']            ?? 1));
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
        $optionA = $optionA ?? 'True';
        $optionB = $optionB ?? 'False';
        if (!in_array($correctAnswer, ['A', 'B'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Correct answer for True/False must be A (True) or B (False).']);
            exit;
        }
    } else {
        if (!in_array($correctAnswer, ['A', 'B', 'C', 'D'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Correct answer must be A, B, C, or D.']);
            exit;
        }
    }
}

// ── Verify ownership ──
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

// ── Insert inside transaction with FOR UPDATE lock ──
// SELECT MAX ... FOR UPDATE locks the assessment's question rows,
// preventing two simultaneous requests from reading the same MAX
// and producing a duplicate question_order.
// The UNIQUE KEY unique_assessment_order is a safety net that will
// cause the INSERT to fail (errno 1062) if a race still somehow occurs.

$conn->begin_transaction();

try {
    // Lock all question rows for this assessment while we read MAX
    $stmt = $conn->prepare(
        "SELECT COALESCE(MAX(question_order), 0) + 1 AS next_order
         FROM questions
         WHERE assessment_id = ?
         FOR UPDATE"
    );
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("i", $assessmentId);
    $stmt->execute();
    $result    = $stmt->get_result();
    $row       = $result->fetch_assoc();
    $nextOrder = (int)($row['next_order'] ?? 1);
    $stmt->close();

    // Insert
    $stmt = $conn->prepare(
        "INSERT INTO questions
            (assessment_id, question_type, question_text, marks, negative_marks,
             option_a, option_b, option_c, option_d,
             correct_answer, topic, explanation, question_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param(
        "issidsssssssi",
        $assessmentId, $questionType, $questionText,
        $marks, $negMarks,
        $optionA, $optionB, $optionC, $optionD,
        $correctAnswer, $topic, $explanation,
        $nextOrder
    );
    $stmt->execute();
    $newQuestionId = $stmt->insert_id;
    $stmt->close();

    // Touch assessment updated_at
    $stmt = $conn->prepare(
        "UPDATE assessments SET updated_at = NOW() WHERE assessment_id = ? AND created_by = ?"
    );
    if ($stmt) {
        $stmt->bind_param("ii", $assessmentId, $teacherId);
        $stmt->execute();
        $stmt->close();
    }

    $conn->commit();

    echo json_encode(['success' => true, 'question_id' => $newQuestionId]);

} catch (Exception $e) {
    $conn->rollback();

    // Duplicate question_order = two requests raced; ask client to retry
    if ($conn->errno === 1062 || str_contains($e->getMessage(), '1062')) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Conflict adding question. Please try again.']);
    } else {
        error_log("add-question transaction failed: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to add question. Please try again.']);
    }
}
<?php
// ============================================================
// api/assessment/add-question.php
//
// Inserts a new question + its options into the DB.
// Options live in question_options, not flat columns on questions.
//
// POST JSON {
//   assessment_id: int,
//   question_type: 'mcq'|'multiple_select'|'true_false'|'short_answer'|'fill_blank',
//   question_text: string,
//   marks: int,
//   negative_marks: float,
//   explanation?: string,
//   options: [
//     { option_text: string, is_correct: bool, option_order: int },
//     ...
//   ]
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
$assessmentId = (int)($body['assessment_id'] ?? 0);
$questionType = trim($body['question_type']  ?? '');
$questionText = trim($body['question_text']  ?? '');
$options      = $body['options']             ?? [];

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

$allowedTypes = ['mcq', 'multiple_select', 'true_false', 'short_answer', 'fill_blank'];
if (!in_array($questionType, $allowedTypes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid question type.']);
    exit;
}

if (!is_array($options) || count($options) < 2) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'At least 2 options are required.']);
    exit;
}

// ── Numeric fields ──
$marks    = max(1, (int)($body['marks']            ?? 1));
$negMarks = max(0, (float)($body['negative_marks'] ?? 0));
$negMarks = round($negMarks, 2);

// ── Optional fields ──
$explanation = trim($body['explanation'] ?? '') ?: null;

// ── Validate options ──
$correctCount = 0;
$cleanOptions = [];
foreach ($options as $idx => $opt) {
    $text      = trim($opt['option_text'] ?? '');
    $isCorrect = !empty($opt['is_correct']) ? 1 : 0;
    $order     = (int)($opt['option_order'] ?? ($idx + 1));

    if ($text === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Option $order text is required."]);
        exit;
    }

    $cleanOptions[] = [
        'option_text'  => $text,
        'is_correct'   => $isCorrect,
        'option_order' => $order,
    ];
    if ($isCorrect) $correctCount++;
}

if ($correctCount === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'At least one option must be marked correct.']);
    exit;
}

if (in_array($questionType, ['mcq', 'true_false'], true) && $correctCount !== 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Exactly one correct option is required for this question type.']);
    exit;
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

// ── Insert question + options in a transaction ──
$conn->begin_transaction();

try {
    // Lock rows to get a safe question_order
    $stmt = $conn->prepare(
        "SELECT COALESCE(MAX(question_order), 0) + 1 AS next_order
         FROM questions WHERE assessment_id = ? FOR UPDATE"
    );
    if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
    $stmt->bind_param("i", $assessmentId);
    $stmt->execute();
    $row       = $stmt->get_result()->fetch_assoc();
    $nextOrder = (int)($row['next_order'] ?? 1);
    $stmt->close();

    // Insert question — no option columns, no correct_answer, no topic
    $stmt = $conn->prepare(
        "INSERT INTO questions
            (assessment_id, question_type, question_text, marks, negative_marks, explanation, question_order)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) throw new Exception("Prepare question failed: " . $conn->error);
    $stmt->bind_param("ississi",
        $assessmentId,
        $questionType,
        $questionText,
        $marks,
        $negMarks,
        $explanation,
        $nextOrder
    );
    $stmt->execute();
    $newQuestionId = $stmt->insert_id;
    $stmt->close();

    // Insert each option into question_options
    $optStmt = $conn->prepare(
        "INSERT INTO question_options (question_id, option_text, is_correct, option_order)
         VALUES (?, ?, ?, ?)"
    );
    if (!$optStmt) throw new Exception("Prepare options failed: " . $conn->error);

    foreach ($cleanOptions as $opt) {
        $optText      = $opt['option_text'];
        $optIsCorrect = $opt['is_correct'];
        $optOrder     = $opt['option_order'];
        $optStmt->bind_param("isii", $newQuestionId, $optText, $optIsCorrect, $optOrder);
        $optStmt->execute();
    }
    $optStmt->close();

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

    if ($conn->errno === 1062 || str_contains($e->getMessage(), '1062')) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Conflict adding question. Please try again.']);
    } else {
        error_log("add-question transaction failed: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to add question. Please try again.']);
    }
}
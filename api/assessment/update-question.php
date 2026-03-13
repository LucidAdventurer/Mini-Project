<?php
// ============================================================
// api/assessment/update-question.php
//
// Saves edits to an existing question and replaces its options.
// Verifies the question belongs to an assessment owned by the
// logged-in teacher before writing any changes.
//
// POST JSON {
//   question_id:    int,
//   assessment_id:  int,
//   question_text:  string,
//   marks:          int,
//   negative_marks: float,
//   explanation?:   string,
//   options: [
//     { option_text: string, is_correct: bool, option_order: int },
//     ...
//   ]
// }
// Returns { success: bool, error?: string }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$conn = createDatabaseConnection();
if (!$conn) { http_response_code(503); echo json_encode(['success'=>false,'error'=>'Database unavailable.']); exit; }

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
$options      = $body['options']             ?? [];

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

// ── Verify ownership ──
$check = safePreparedQuery($conn,
    "SELECT q.question_id, q.question_type
     FROM questions q
     JOIN assessments a ON a.assessment_id = q.assessment_id
     WHERE q.question_id   = ?
       AND q.assessment_id = ?
       AND a.created_by    = ?",
    "iii", [$questionId, $assessmentId, $teacherId]
);

if (!$check['success'] || !$check['result'] || $check['result']->num_rows === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Question not found or access denied.']);
    exit;
}
$qRow  = $check['result']->fetch_assoc();
$qType = $qRow['question_type'];
$check['result']->free();

// Type-specific correct-count enforcement
if (in_array($qType, ['mcq', 'true_false'], true) && $correctCount !== 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Exactly one correct option is required for this question type.']);
    exit;
}

// ── Update question + options in a transaction ──
$conn->begin_transaction();

try {
    // Update question row — no option columns, no correct_answer, no topic
    $stmt = $conn->prepare(
        "UPDATE questions SET
            question_text  = ?,
            marks          = ?,
            negative_marks = ?,
            explanation    = ?
         WHERE question_id = ? AND assessment_id = ?"
    );
    if (!$stmt) throw new Exception("Prepare question update failed: " . $conn->error);
    $stmt->bind_param("sidsii",
        $questionText,
        $marks,
        $negMarks,
        $explanation,
        $questionId,
        $assessmentId
    );
    $stmt->execute();
    $stmt->close();

    // Replace options: delete existing, re-insert fresh set.
    // Existing answers referencing old option_ids will have their
    // selected_option_id set NULL via FK ON DELETE SET NULL.
    $del = $conn->prepare("DELETE FROM question_options WHERE question_id = ?");
    if (!$del) throw new Exception("Prepare delete options failed: " . $conn->error);
    $del->bind_param("i", $questionId);
    $del->execute();
    $del->close();

    $optStmt = $conn->prepare(
        "INSERT INTO question_options (question_id, option_text, is_correct, option_order)
         VALUES (?, ?, ?, ?)"
    );
    if (!$optStmt) throw new Exception("Prepare insert options failed: " . $conn->error);

    foreach ($cleanOptions as $opt) {
        $optText      = $opt['option_text'];
        $optIsCorrect = $opt['is_correct'];
        $optOrder     = $opt['option_order'];
        $optStmt->bind_param("isii", $questionId, $optText, $optIsCorrect, $optOrder);
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

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    $conn->rollback();
    error_log("update-question transaction failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Question update failed. Please try again.']);
}
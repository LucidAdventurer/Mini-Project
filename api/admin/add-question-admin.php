<?php
// ============================================================
// api/admin/add-question.php
//
// Adds a single question + its options to any assessment.
// Uses question_options table (not option_a/b/c/d columns).
// Admin-only endpoint.
//
// POST JSON body:
//   assessment_id, question_type, question_text,
//   marks, negative_marks, explanation,
//   options: [ { option_text, is_correct }, ... ]
// ============================================================
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');
validateSession($conn, 'admin');

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

// ── Required fields ──────────────────────────────────────────────────────
$assessmentId = (int)($body['assessment_id'] ?? 0);
$questionType = trim($body['question_type']  ?? '');
$questionText = trim($body['question_text']  ?? '');

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

// ── Numeric fields ────────────────────────────────────────────────────────
$marks    = max(1, (int)($body['marks']            ?? 1));
$negMarks = max(0.0, (float)($body['negative_marks'] ?? 0));
$negMarks = round($negMarks, 2);

// ── Optional ──────────────────────────────────────────────────────────────
$explanation = trim($body['explanation'] ?? '') ?: null;

// ── Validate options for choice-based types ───────────────────────────────
$options     = $body['options'] ?? [];
$needOptions = in_array($questionType, ['mcq', 'multiple_select', 'true_false'], true);

if ($needOptions) {
    if (!is_array($options) || count($options) < 2) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'At least 2 options required for this question type.']);
        exit;
    }
    $hasCorrect = false;
    foreach ($options as $opt) {
        if (!empty($opt['is_correct'])) { $hasCorrect = true; break; }
    }
    if (!$hasCorrect) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'At least one option must be marked correct.']);
        exit;
    }
    if ($questionType === 'mcq' || $questionType === 'true_false') {
        $correctCount = count(array_filter($options, fn($o) => !empty($o['is_correct'])));
        if ($correctCount > 1) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Only one correct answer allowed for this type.']);
            exit;
        }
    }
}

// ── Verify assessment exists ──────────────────────────────────────────────
$check = safePreparedQuery($conn,
    "SELECT assessment_id FROM assessments WHERE assessment_id = ?",
    "i", [$assessmentId]
);
if (!$check['success'] || !$check['result'] || $check['result']->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Assessment not found.']);
    exit;
}
$check['result']->free();

// ── Insert question + options in one transaction ──────────────────────────
$conn->begin_transaction();
try {
    // Next question_order (locked to prevent race)
    $stmt = $conn->prepare(
        "SELECT COALESCE(MAX(question_order), 0) + 1 AS next_order
         FROM questions WHERE assessment_id = ? FOR UPDATE"
    );
    $stmt->bind_param("i", $assessmentId);
    $stmt->execute();
    $nextOrder = (int)($stmt->get_result()->fetch_assoc()['next_order'] ?? 1);
    $stmt->close();

    // Insert question row (no option_a/b/c/d columns in this schema)
    $stmt = $conn->prepare(
        "INSERT INTO questions
            (assessment_id, question_type, question_text,
             marks, negative_marks, explanation, question_order)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("issidsi",
        $assessmentId, $questionType, $questionText,
        $marks, $negMarks, $explanation, $nextOrder
    );
    $stmt->execute();
    $questionId = $stmt->insert_id;
    $stmt->close();

    // Insert options into question_options table
    if (!empty($options) && $needOptions) {
        $stmt = $conn->prepare(
            "INSERT INTO question_options
                (question_id, option_text, is_correct, option_order)
             VALUES (?, ?, ?, ?)"
        );
        foreach ($options as $i => $opt) {
            $optText    = trim($opt['option_text'] ?? '');
            $isCorrect  = empty($opt['is_correct']) ? 0 : 1;
            $optOrder   = (int)($opt['option_order'] ?? ($i + 1));
            if ($optText === '') continue;
            $stmt->bind_param("isii", $questionId, $optText, $isCorrect, $optOrder);
            $stmt->execute();
        }
        $stmt->close();
    }

    // Touch assessment updated_at
    $conn->query("UPDATE assessments SET updated_at = NOW() WHERE assessment_id = $assessmentId");

    $conn->commit();
    echo json_encode(['success' => true, 'question_id' => $questionId, 'question_order' => $nextOrder]);

} catch (Exception $e) {
    $conn->rollback();
    error_log("admin add-question failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save question. Please try again.']);
}

<?php
// ============================================================
// api/admin/delete-question.php
//
// Deletes a question and its options, then resequences
// remaining questions for that assessment.
// Admin-only — no ownership restriction.
//
// POST JSON: { question_id: int }
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

$body       = json_decode(file_get_contents('php://input'), true);
$questionId = (int)($body['question_id'] ?? 0);

if ($questionId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid question ID.']);
    exit;
}

// ── Get the question and its assessment ──────────────────────────────────
$check = safePreparedQuery($conn,
    "SELECT q.question_id, q.assessment_id, q.question_order
     FROM questions q
     WHERE q.question_id = ?",
    "i", [$questionId]
);

if (!$check['success'] || !$check['result'] || $check['result']->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Question not found.']);
    exit;
}
$row          = $check['result']->fetch_assoc();
$assessmentId = (int)$row['assessment_id'];
$check['result']->free();

// ── Delete question + options in transaction, then resequence ─────────────
$conn->begin_transaction();
try {
    // Delete options first (FK)
    $stmt = $conn->prepare("DELETE FROM question_options WHERE question_id = ?");
    $stmt->bind_param("i", $questionId);
    $stmt->execute();
    $stmt->close();

    // Delete the question
    $stmt = $conn->prepare("DELETE FROM questions WHERE question_id = ?");
    $stmt->bind_param("i", $questionId);
    $stmt->execute();
    $stmt->close();

    // Resequence remaining questions
    $conn->query(
        "SET @rn := 0;
         UPDATE questions
         SET question_order = (@rn := @rn + 1)
         WHERE assessment_id = $assessmentId
         ORDER BY question_order ASC"
    );

    // Touch assessment updated_at
    $stmt = $conn->prepare("UPDATE assessments SET updated_at = NOW() WHERE assessment_id = ?");
    $stmt->bind_param("i", $assessmentId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    $conn->rollback();
    error_log("admin delete-question failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to delete question.']);
}

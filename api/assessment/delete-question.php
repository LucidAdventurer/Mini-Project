<?php
// ============================================================
// api/assessment/delete-question.php
//
// FIXES:
// - Resequencing replaced with a single MariaDB user-variable
//   UPDATE instead of N individual UPDATE queries.
// - Entire delete + resequence wrapped in a transaction.
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

$questionId   = (int)($body['question_id']   ?? 0);
$assessmentId = (int)($body['assessment_id'] ?? 0);

if ($questionId <= 0 || $assessmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid question or assessment ID.']);
    exit;
}

// ── Verify ownership ──
$check = safePreparedQuery($conn,
    "SELECT q.question_id
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
$check['result']->free();

// ── Delete + resequence in a single transaction ──
$conn->begin_transaction();

try {
    // Delete — FK CASCADE removes associated answers + attempt_questions rows
    $stmt = $conn->prepare(
        "DELETE FROM questions WHERE question_id = ? AND assessment_id = ?"
    );
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("ii", $questionId, $assessmentId);
    $stmt->execute();
    if ($stmt->affected_rows === 0) {
        throw new Exception("Delete affected 0 rows");
    }
    $stmt->close();

    // Resequence in one query using MariaDB user variables.
    // This replaces the old O(N) PHP loop.
    // The UNIQUE KEY allows this because we temporarily disable
    // the constraint check order via ORDER BY (MariaDB processes
    // the SET before checking uniqueness per row in this pattern).
    $conn->query("SET @i = 0");
    $stmt = $conn->prepare(
        "UPDATE questions
         SET    question_order = (@i := @i + 1)
         WHERE  assessment_id  = ?
         ORDER  BY question_order ASC, question_id ASC"
    );
    if (!$stmt) {
        throw new Exception("Prepare resequence failed: " . $conn->error);
    }
    $stmt->bind_param("i", $assessmentId);
    $stmt->execute();
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

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    $conn->rollback();
    error_log("delete-question transaction failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Delete failed. Please try again.']);
}
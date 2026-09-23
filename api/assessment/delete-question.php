<?php
// ============================================================
// api/assessment/delete-question.php
//
// FIXES:
/// - Resequencing uses PostgreSQL window functions inside the
//   same transaction instead of MariaDB user variables.
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
$conn->beginTransaction();

try {
    // Delete the question.
    // The question_id FK on answers is configured with ON DELETE CASCADE.
    $stmt = $conn->prepare(
        "DELETE FROM questions
         WHERE question_id = ?
           AND assessment_id = ?"
    );

    $stmt->execute([$questionId, $assessmentId]);

    if ($stmt->rowCount() === 0) {
        throw new Exception("Delete affected 0 rows");
    }

    // ── Resequence remaining questions ──
    //
    // PostgreSQL does not support the MariaDB user-variable
    // technique used by the old implementation.
    //
    // First move existing order values into a temporary negative
    // range so the UNIQUE constraint cannot collide while we
    // assign 1..N.
    $stmt = $conn->prepare(
        "UPDATE questions
         SET question_order = -question_order
         WHERE assessment_id = ?"
    );
    $stmt->execute([$assessmentId]);

    // Preserve the original ordering:
    // -1, -2, -3 ... sorted DESC => 1, 2, 3 ...
    $stmt = $conn->prepare(
        "WITH ordered AS (
            SELECT
                question_id,
                ROW_NUMBER() OVER (
                    ORDER BY question_order DESC, question_id ASC
                ) AS new_order
            FROM questions
            WHERE assessment_id = ?
        )
        UPDATE questions q
        SET question_order = ordered.new_order
        FROM ordered
        WHERE q.question_id = ordered.question_id"
    );
    $stmt->execute([$assessmentId]);

    // Touch assessment updated_at.
    $stmt = $conn->prepare(
        "UPDATE assessments
         SET updated_at = NOW()
         WHERE assessment_id = ?
           AND created_by = ?"
    );
    $stmt->execute([$assessmentId, $teacherId]);

    $conn->commit();

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    error_log("delete-question transaction failed: " . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Delete failed. Please try again.'
    ]);
}
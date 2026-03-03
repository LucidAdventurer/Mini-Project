<?php
// ============================================================
// api/assessment/delete-question.php
//
// Permanently deletes a single question.
// Verifies the question belongs to an assessment owned by the
// logged-in teacher before deleting.
// FK ON DELETE CASCADE in the schema removes associated
// answers and attempt_questions rows automatically.
// After deletion, re-sequences question_order so there are
// no gaps (1, 2, 3 … with no missing numbers).
//
// POST JSON { question_id: int, assessment_id: int }
// Returns   { success: bool, error?: string }
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

$questionId   = (int)($body['question_id']   ?? 0);
$assessmentId = (int)($body['assessment_id'] ?? 0);

if ($questionId <= 0 || $assessmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid question or assessment ID.']);
    exit;
}

// ── Verify the question belongs to an assessment this teacher owns ──
$check = safePreparedQuery($conn,
    "SELECT q.question_id
     FROM questions q
     JOIN assessments a ON a.assessment_id = q.assessment_id
     WHERE q.question_id  = ?
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

// ── Delete ──
// answers and attempt_questions rows are removed by FK CASCADE.
$del = safePreparedQuery($conn,
    "DELETE FROM questions WHERE question_id = ? AND assessment_id = ?",
    "ii", [$questionId, $assessmentId]
);

if (!$del['success'] || $del['affected_rows'] === 0) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Delete failed. Please try again.']);
    exit;
}

// ── Re-sequence question_order to remove gaps ──
// Load remaining questions in their current order, then reassign 1, 2, 3 …
// Done with individual updates to avoid needing a stored procedure or
// user-defined variables (which may not be available on all MariaDB configs).
$remaining = safePreparedQuery($conn,
    "SELECT question_id FROM questions WHERE assessment_id = ? ORDER BY question_order ASC, question_id ASC",
    "i", [$assessmentId]
);

if ($remaining['success'] && $remaining['result']) {
    $pos = 1;
    while ($row = $remaining['result']->fetch_assoc()) {
        safePreparedQuery($conn,
            "UPDATE questions SET question_order = ? WHERE question_id = ?",
            "ii", [$pos, (int)$row['question_id']]
        );
        $pos++;
    }
    $remaining['result']->free();
}

// Touch assessment updated_at
safePreparedQuery($conn,
    "UPDATE assessments SET updated_at = NOW() WHERE assessment_id = ? AND created_by = ?",
    "ii", [$assessmentId, $teacherId]
);

echo json_encode(['success' => true]);
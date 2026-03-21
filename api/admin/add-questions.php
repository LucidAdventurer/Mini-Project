<?php
// ============================================================
// api/admin/add-questions.php
//
// Admin: bulk-inserts questions + options for an existing assessment.
// Used by both the manual question queue and the PDF/DOCX import.
//
// POST JSON {
//   assessment_id : int   (required)
//   append        : bool  (if false, delete existing questions first)
//   questions     : [
//     {
//       text           : string
//       type           : 'mcq' | 'true_false' | 'multiple_select' | 'short_answer'
//       marks          : int      (default 1)
//       negative_marks : float    (default 0)
//       explanation    : string   (optional)
//       order          : int      (optional)
//       options        : [
//         { text: string, is_correct: bool, order: int }
//       ]
//     }
//   ]
// }
// Returns { success: bool, inserted: int, errors: [] }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$admin   = validateSession($conn, 'admin');
$adminId = (int) $admin['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON.']);
    exit;
}

$assessmentId = (int)($body['assessment_id'] ?? 0);
$append       = !empty($body['append']);
$questions    = $body['questions'] ?? [];

if ($assessmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid assessment_id.']);
    exit;
}

if (!is_array($questions) || empty($questions)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No questions provided.']);
    exit;
}

// ── Verify the assessment exists ──
$chk = safePreparedQuery($conn,
    "SELECT assessment_id, total_marks FROM assessments WHERE assessment_id = ?",
    "i", [$assessmentId]
);
if (!$chk['success'] || !$chk['result'] || $chk['result']->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Assessment not found.']);
    exit;
}
$assessmentRow = $chk['result']->fetch_assoc();
$chk['result']->free();

// ── Optional: wipe existing questions first ──
if (!$append) {
    // Delete options first (FK), then questions
    $conn->query(
        "DELETE qo FROM question_options qo
         INNER JOIN questions q ON q.question_id = qo.question_id
         WHERE q.assessment_id = $assessmentId"
    );
    $conn->query("DELETE FROM questions WHERE assessment_id = $assessmentId");
}

// ── Get current max question_order ──
$orderBase = 0;
if ($append) {
    $rOrd = safePreparedQuery($conn,
        "SELECT COALESCE(MAX(question_order),0) AS mx FROM questions WHERE assessment_id = ?",
        "i", [$assessmentId]
    );
    if ($rOrd['success'] && $rOrd['result']) {
        $r = $rOrd['result']->fetch_assoc();
        $orderBase = (int)($r['mx'] ?? 0);
        $rOrd['result']->free();
    }
}

// ── Allowed types ──
$allowedTypes = ['mcq', 'true_false', 'multiple_select', 'short_answer', 'fill_blank'];

$inserted    = 0;
$errors      = [];
$addedMarks  = 0;

foreach ($questions as $idx => $q) {
    $qText   = trim($q['text'] ?? '');
    $qType   = trim($q['type'] ?? 'mcq');
    $qMarks  = max(1, (int)($q['marks'] ?? 1));
    $qNeg    = max(0, (float)($q['negative_marks'] ?? 0));
    $qExpl   = trim($q['explanation'] ?? '');
    $qOrder  = $orderBase + (int)($q['order'] ?? ($idx + 1));
    $options = $q['options'] ?? [];

    if ($qText === '') {
        $errors[] = "Question " . ($idx + 1) . ": empty text, skipped.";
        continue;
    }

    if (!in_array($qType, $allowedTypes, true)) {
        $qType = 'mcq';
    }

    // ── Insert question row ──
    $rq = safePreparedQuery($conn,
        "INSERT INTO questions
            (assessment_id, question_text, question_type, marks, negative_marks,
             explanation, question_order)
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        "issidsi",
        [$assessmentId, $qText, $qType, $qMarks, $qNeg, $qExpl ?: null, $qOrder]
    );

    if (!$rq['success'] || !$rq['insert_id']) {
        $errors[] = "Question " . ($idx + 1) . ": DB insert failed — " . ($rq['error'] ?? 'unknown');
        continue;
    }

    $questionId = (int) $rq['insert_id'];

    // ── Insert options ──
    if (!empty($options) && is_array($options)) {
        $hasCorrect = false;

        foreach ($options as $oi => $opt) {
            $optText      = trim($opt['text'] ?? '');
            $optIsCorrect = !empty($opt['is_correct']) ? 1 : 0;
            $optOrder     = (int)($opt['order'] ?? ($oi + 1));

            if ($optText === '') continue;

            safePreparedQuery($conn,
                "INSERT INTO question_options (question_id, option_text, is_correct, option_order)
                 VALUES (?, ?, ?, ?)",
                "isii",
                [$questionId, $optText, $optIsCorrect, $optOrder]
            );

            if ($optIsCorrect) $hasCorrect = true;
        }

        // If no option was marked correct (e.g. no Answer: line in doc), default first option
        if (!$hasCorrect && in_array($qType, ['mcq', 'true_false', 'multiple_select'])) {
            $conn->query(
                "UPDATE question_options
                 SET is_correct = 1
                 WHERE question_id = $questionId
                 ORDER BY option_order ASC
                 LIMIT 1"
            );
        }
    }

    $inserted++;
    $addedMarks += $qMarks;
}

// ── Update assessment total_marks if not appending ──
if (!$append && $inserted > 0) {
    safePreparedQuery($conn,
        "UPDATE assessments
         SET total_marks = ?,
             passing_marks = GREATEST(COALESCE(passing_marks, 0), FLOOR(? * 0.4)),
             updated_at = NOW()
         WHERE assessment_id = ?",
        "iii", [$addedMarks, $addedMarks, $assessmentId]
    );
} elseif ($append && $inserted > 0) {
    // Accumulate marks when appending
    safePreparedQuery($conn,
        "UPDATE assessments
         SET total_marks = COALESCE(total_marks, 0) + ?,
             updated_at = NOW()
         WHERE assessment_id = ?",
        "ii", [$addedMarks, $assessmentId]
    );
}

echo json_encode([
    'success'  => $inserted > 0,
    'inserted' => $inserted,
    'errors'   => $errors,
    'error'    => $inserted === 0 ? 'No questions were saved. Check the errors array.' : null,
]);

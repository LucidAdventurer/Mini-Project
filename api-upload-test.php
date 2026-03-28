<?php
/* ========================================
 * API: Upload Test from JSON
 * File: api-upload-test.php
 * POST body (JSON): { test: { ...assessmentData } }
 *
 * Inserts into: assessments, questions, question_options
 * Requires: admin or teacher session
 * ======================================== */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db-guard.php';

header('Content-Type: application/json');

// ── Auth: admin OR teacher may upload ───────────────────────────
$user = null;
foreach (['admin', 'teacher'] as $role) {
    try { $user = validateSession($conn, $role); break; } catch (Exception $e) {}
}
if (!$user) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit; }

// ── CSRF check ──────────────────────────────────────────────────
validateCsrfToken();

// ── Parse request body ──────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!$body || empty($body['test'])) {
    echo json_encode(['success' => false, 'error' => 'Missing test payload.']); exit;
}
$t = $body['test'];

// ── Server-side validation ───────────────────────────────────────
function vErr(string $msg): void {
    echo json_encode(['success' => false, 'error' => $msg]); exit;
}

$title = trim($t['title'] ?? '');
if (!$title) vErr('title is required.');
if (strlen($title) > 200) vErr('title must be ≤ 200 characters.');

$description       = trim($t['description'] ?? '');
$category          = trim($t['category'] ?? '');
$duration_minutes  = intval($t['duration_minutes'] ?? 0);
$total_marks       = intval($t['total_marks'] ?? 0);
$passing_marks     = intval($t['passing_marks'] ?? 0);
$max_attempts      = max(1, intval($t['max_attempts'] ?? 1));
$randomize_q       = !empty($t['randomize_questions']) ? 1 : 0;
$randomize_o       = !empty($t['randomize_options'])   ? 1 : 0;

$allowed_diff = ['easy', 'medium', 'hard'];
$difficulty = in_array($t['difficulty'] ?? '', $allowed_diff) ? $t['difficulty'] : 'medium';

$allowed_vis = ['public', 'group', 'private'];
$visibility = in_array($t['visibility'] ?? '', $allowed_vis) ? $t['visibility'] : 'public';

if ($duration_minutes < 1)  vErr('duration_minutes must be ≥ 1.');
if ($total_marks < 1)       vErr('total_marks must be ≥ 1.');
if ($passing_marks < 0)     vErr('passing_marks must be ≥ 0.');
if ($passing_marks > $total_marks) vErr('passing_marks cannot exceed total_marks.');

$questions = $t['questions'] ?? [];
if (!is_array($questions) || count($questions) === 0) vErr('questions array is empty or missing.');

$allowed_qtypes = ['mcq', 'multiple_select', 'true_false', 'short_answer'];
foreach ($questions as $idx => $q) {
    $n = $idx + 1;
    if (empty(trim($q['question_text'] ?? ''))) vErr("Question $n: question_text is required.");
    if (!in_array($q['question_type'] ?? '', $allowed_qtypes)) vErr("Question $n: invalid question_type.");
    if (!is_array($q['options'] ?? null) || count($q['options']) < 2) vErr("Question $n: must have ≥ 2 options. Got " . count($q['options'] ?? []) . ". Check that your DOCX/PDF has options on separate lines (a) b) c) d)) or uses the 'Answer: X' format.");
    $hasCorrect = false;
    foreach ($q['options'] as $o) {
        if (empty(trim($o['option_text'] ?? ''))) vErr("Question $n: option_text cannot be empty.");
        if (!empty($o['is_correct'])) $hasCorrect = true;
    }
    if (!$hasCorrect && $q['question_type'] !== 'short_answer') vErr("Question $n: no correct option marked.");
}

// ── Transaction: insert assessment + questions + options ─────────
$conn->begin_transaction();

try {
    // 1. Insert assessment (status = 'draft' so admin can review before publishing)
    $stmt = $conn->prepare(
        "INSERT INTO assessments
            (created_by, title, description, visibility, status, category, difficulty,
             duration_minutes, total_marks, passing_marks, max_attempts,
             randomize_questions, randomize_options, created_at, updated_at)
         VALUES (?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
    );
    $userId = (int) $user['user_id'];
    $stmt->bind_param(
        "isssssiiiiii",
        $userId, $title, $description, $visibility,
        $category, $difficulty, $duration_minutes,
        $total_marks, $passing_marks, $max_attempts,
        $randomize_q, $randomize_o
    );
    $stmt->execute();
    $assessmentId = (int) $conn->insert_id;
    $stmt->close();

    // 2. Insert each question and its options
    $qStmt = $conn->prepare(
        "INSERT INTO questions
            (assessment_id, question_text, question_type, marks, negative_marks,
             explanation, question_order, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    if (!$qStmt) throw new Exception("Prepare questions failed: " . $conn->error);

    $oStmt = $conn->prepare(
        "INSERT INTO question_options
            (question_id, option_text, is_correct, option_order)
         VALUES (?, ?, ?, ?)"
    );
    if (!$oStmt) throw new Exception("Prepare question_options failed: " . $conn->error);

    foreach ($questions as $order => $q) {
        $qText    = trim($q['question_text']);
        $qType    = $q['question_type'];
        $marks    = max(0, intval($q['marks'] ?? 1));
        $negMarks = max(0.0, floatval($q['negative_marks'] ?? 0));
        $expl     = trim($q['explanation'] ?? '');
        $qOrder   = $order + 1;

        $qStmt->bind_param("issidsi", $assessmentId, $qText, $qType, $marks, $negMarks, $expl, $qOrder);
        $qStmt->execute();
        $questionId = (int) $conn->insert_id;

        foreach ($q['options'] as $optOrder => $o) {
            $optText   = trim($o['option_text']);
            $isCorrect = !empty($o['is_correct']) ? 1 : 0;
            $optOrd    = $optOrder + 1;
            $oStmt->bind_param("isii", $questionId, $optText, $isCorrect, $optOrd);
            $oStmt->execute();
        }
    }

    $qStmt->close();
    $oStmt->close();

    $conn->commit();

    echo json_encode([
        'success'       => true,
        'assessment_id' => $assessmentId,
        'message'       => 'Assessment imported as draft successfully.',
    ]);

} catch (Exception $e) {
    $conn->rollback();
    error_log('[api-upload-test] DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
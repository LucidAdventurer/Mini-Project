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

// ── CSRF check (uses your existing verifyCsrf helper) ───────────
verifyCsrf();

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
    if (!is_array($q['options'] ?? null) || count($q['options']) < 2) vErr("Question $n: must have ≥ 2 options.");
    $hasCorrect = false;
    foreach ($q['options'] as $o) {
        if (empty(trim($o['option_text'] ?? ''))) vErr("Question $n: option_text cannot be empty.");
        if (!empty($o['is_correct'])) $hasCorrect = true;
    }
    if (!$hasCorrect && $q['question_type'] !== 'short_answer') vErr("Question $n: no correct option marked.");
}

// ── Transaction: insert assessment + questions + options ─────────
try {
    $conn->beginTransaction();

    // 1. Insert assessment (status = 'draft' so admin can review before publishing)
    $stmt = $conn->prepare("
        INSERT INTO assessments
            (created_by, title, description, visibility, status, category, difficulty,
             duration_minutes, total_marks, passing_marks, max_attempts,
             randomize_questions, randomize_options, created_at, updated_at)
        VALUES
            (:created_by, :title, :description, :visibility, 'draft', :category, :difficulty,
             :duration_minutes, :total_marks, :passing_marks, :max_attempts,
             :rq, :ro, NOW(), NOW())
    ");
    $stmt->execute([
        ':created_by'        => $user['user_id'],
        ':title'             => $title,
        ':description'       => $description,
        ':visibility'        => $visibility,
        ':category'          => $category,
        ':difficulty'        => $difficulty,
        ':duration_minutes'  => $duration_minutes,
        ':total_marks'       => $total_marks,
        ':passing_marks'     => $passing_marks,
        ':max_attempts'      => $max_attempts,
        ':rq'                => $randomize_q,
        ':ro'                => $randomize_o,
    ]);
    $assessmentId = (int) $conn->lastInsertId();

    // 2. Insert each question and its options
    $qStmt = $conn->prepare("
        INSERT INTO questions
            (assessment_id, question_text, question_type, marks, negative_marks,
             explanation, question_order, created_at)
        VALUES
            (:assessment_id, :question_text, :question_type, :marks, :negative_marks,
             :explanation, :question_order, NOW())
    ");

    $oStmt = $conn->prepare("
        INSERT INTO question_options
            (question_id, option_text, is_correct, option_order)
        VALUES
            (:question_id, :option_text, :is_correct, :option_order)
    ");

    foreach ($questions as $order => $q) {
        $qStmt->execute([
            ':assessment_id'  => $assessmentId,
            ':question_text'  => trim($q['question_text']),
            ':question_type'  => $q['question_type'],
            ':marks'          => max(0, intval($q['marks'] ?? 1)),
            ':negative_marks' => max(0, floatval($q['negative_marks'] ?? 0)),
            ':explanation'    => trim($q['explanation'] ?? ''),
            ':question_order' => $order + 1,
        ]);
        $questionId = (int) $conn->lastInsertId();

        foreach ($q['options'] as $optOrder => $o) {
            $oStmt->execute([
                ':question_id' => $questionId,
                ':option_text' => trim($o['option_text']),
                ':is_correct'  => !empty($o['is_correct']) ? 1 : 0,
                ':option_order'=> $optOrder + 1,
            ]);
        }
    }

    $conn->commit();

    echo json_encode([
        'success'       => true,
        'assessment_id' => $assessmentId,
        'message'       => 'Assessment imported as draft successfully.',
    ]);

} catch (PDOException $e) {
    $conn->rollBack();
    error_log('[api-upload-test] DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error. Please try again.']);
}

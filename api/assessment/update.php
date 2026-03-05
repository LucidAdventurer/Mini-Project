<?php
// ============================================================
// api/assessment/update.php
//
// Saves all basic information edits for an assessment.
// Verifies the logged-in teacher owns the assessment before
// writing any changes.
//
// POST JSON {
//   assessment_id, title, description, instructions,
//   category, difficulty, duration_minutes, total_marks,
//   passing_marks, max_attempts, available_from,
//   available_until, show_results_immediately,
//   show_correct_answers, randomize_questions,
//   randomize_options, is_public, status
// }
// Returns { success: bool, error?: string }
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
$title        = trim($body['title']      ?? '');
$category     = trim($body['category']   ?? '');
$difficulty   = trim($body['difficulty'] ?? '');
$duration     = (int)($body['duration_minutes'] ?? 0);
$totalMarks   = (int)($body['total_marks']      ?? 0);
$passingMarks = (int)($body['passing_marks']    ?? 0);

if ($assessmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid assessment ID.']);
    exit;
}
if ($title === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Title is required.']);
    exit;
}
if (mb_strlen($title) > 200) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Title must not exceed 200 characters.']);
    exit;
}

$allowedCategories   = ['aptitude', 'technical', 'coding', 'reasoning', 'english', 'general'];
$allowedDifficulties = ['easy', 'medium', 'hard'];

if (!in_array($category, $allowedCategories, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid category.']);
    exit;
}
if (!in_array($difficulty, $allowedDifficulties, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid difficulty.']);
    exit;
}
if ($duration < 1 || $duration > 480) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Duration must be between 1 and 480 minutes.']);
    exit;
}
if ($totalMarks < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Total marks must be at least 1.']);
    exit;
}
if ($passingMarks < 0 || $passingMarks > $totalMarks) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Passing marks must be between 0 and total marks.']);
    exit;
}

// ── Optional fields ──
$description  = trim($body['description']  ?? '');
$instructions = trim($body['instructions'] ?? '');
$maxAttempts  = max(1, (int)($body['max_attempts'] ?? 1));

// Validate datetime fields
$availableFrom  = null;
$availableUntil = null;

if (!empty($body['available_from'])) {
    $dt = DateTime::createFromFormat('Y-m-d\TH:i:s', $body['available_from']);
    if (!$dt) $dt = DateTime::createFromFormat('Y-m-d\TH:i', $body['available_from']);
    if (!$dt) $dt = DateTime::createFromFormat('Y-m-d H:i:s', $body['available_from']);
    if (!$dt) $dt = DateTime::createFromFormat('Y-m-d H:i', $body['available_from']);
    if ($dt) $availableFrom = $dt->format('Y-m-d H:i:s');
}
if (!empty($body['available_until'])) {
    $dt = DateTime::createFromFormat('Y-m-d\TH:i:s', $body['available_until']);
    if (!$dt) $dt = DateTime::createFromFormat('Y-m-d\TH:i', $body['available_until']);
    if (!$dt) $dt = DateTime::createFromFormat('Y-m-d H:i:s', $body['available_until']);
    if (!$dt) $dt = DateTime::createFromFormat('Y-m-d H:i', $body['available_until']);
    if ($dt) $availableUntil = $dt->format('Y-m-d H:i:s');
}

if ($availableFrom && $availableUntil && $availableFrom >= $availableUntil) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '"Available Until" must be after "Available From".']);
    exit;
}

// Boolean flags
$showResultsImmediately = !empty($body['show_results_immediately']) ? 1 : 0;
$showCorrectAnswers     = !empty($body['show_correct_answers'])     ? 1 : 0;
$randomizeQuestions     = !empty($body['randomize_questions'])      ? 1 : 0;
$randomizeOptions       = !empty($body['randomize_options'])        ? 1 : 0;
$isPublic               = !empty($body['is_public'])                ? 1 : 0;

// Status — only allow valid enum values
$status = trim($body['status'] ?? 'draft');
if (!in_array($status, ['draft', 'active', 'archived', 'scheduled'], true)) {
    $status = 'draft';
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

// ── Update ──
// 19 params: s s s s s i i i i s s i i i i i s i i
// title description instructions category difficulty
// duration totalMarks passingMarks maxAttempts
// availableFrom availableUntil
// showResults showCorrect randQ randO isPublic
// status
// assessmentId teacherId
$result = safePreparedQuery($conn,
    "UPDATE assessments SET
        title                    = ?,
        description              = ?,
        instructions             = ?,
        category                 = ?,
        difficulty               = ?,
        duration_minutes         = ?,
        total_marks              = ?,
        passing_marks            = ?,
        max_attempts             = ?,
        available_from           = ?,
        available_until          = ?,
        show_results_immediately = ?,
        show_correct_answers     = ?,
        randomize_questions      = ?,
        randomize_options        = ?,
        is_public                = ?,
        status                   = ?,
        updated_at               = NOW()
     WHERE assessment_id = ? AND created_by = ?",
    "sssssiiiissiiiiisii",
    [
        $title, $description, $instructions,
        $category, $difficulty,
        $duration, $totalMarks, $passingMarks, $maxAttempts,
        $availableFrom, $availableUntil,
        $showResultsImmediately, $showCorrectAnswers,
        $randomizeQuestions, $randomizeOptions, $isPublic,
        $status,
        $assessmentId, $teacherId,
    ]
);

if ($result['success'] && $result['affected_rows'] >= 0) {
    echo json_encode(['success' => true]);
} else {
    error_log("update assessment failed for assessment_id=$assessmentId teacher_id=$teacherId");
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Update failed. Please try again.']);
}
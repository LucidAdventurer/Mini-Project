<?php
// ============================================================
// api/assessment/create.php
//
// Creates a new assessment for the logged-in teacher.
//
// POST JSON {
//   title, description, category, difficulty,
//   duration_minutes, total_marks, passing_marks,
//   max_attempts, start_time?, end_time?,
//   randomize_questions?, randomize_options?,
//   visibility?: 'public'|'private',
//   status?: 'draft'|'active'
// }
// Returns { success: bool, assessment_id?: int, error?: string }
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
$title        = trim($body['title']      ?? '');
$category     = trim($body['category']   ?? '');
$difficulty   = trim($body['difficulty'] ?? '');
$duration     = (int)($body['duration_minutes'] ?? 0);
$totalMarks   = (int)($body['total_marks']      ?? 0);
$passingMarks = (int)($body['passing_marks']    ?? 0);

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
$description = trim($body['description'] ?? '');
$maxAttempts = max(1, (int)($body['max_attempts'] ?? 1));

// ── Datetime fields ──
$startTime = null;
$endTime   = null;

if (!empty($body['start_time'])) {
    $ts = strtotime($body['start_time']);
    if ($ts !== false) $startTime = date('Y-m-d H:i:s', $ts);
}
if (!empty($body['end_time'])) {
    $ts = strtotime($body['end_time']);
    if ($ts !== false) $endTime = date('Y-m-d H:i:s', $ts);
}

if ($startTime && $endTime && $startTime >= $endTime) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '"End Time" must be after "Start Time".']);
    exit;
}

// ── Boolean flags ──
$randomizeQuestions = !empty($body['randomize_questions']) ? 1 : 0;
$randomizeOptions   = !empty($body['randomize_options'])   ? 1 : 0;

// ── Visibility ──
$visibility = trim($body['visibility'] ?? 'private');
if (!in_array($visibility, ['public', 'private'], true)) {
    $visibility = 'private';
}

// ── Status — only draft or active ──
$status = trim($body['status'] ?? 'draft');
if (!in_array($status, ['draft', 'active'], true)) {
    $status = 'draft';
}

// ── Insert ──
$result = safePreparedQuery($conn,
    "INSERT INTO assessments
        (created_by, title, description, category, difficulty,
         duration_minutes, total_marks, passing_marks, max_attempts,
         start_time, end_time,
         randomize_questions, randomize_options, visibility,
         status, created_at, updated_at)
     VALUES
        (?, ?, ?, ?, ?,
         ?, ?, ?, ?,
         ?, ?,
         ?, ?, ?,
         ?, NOW(), NOW())",
    "issssiiiissiiss",
    [
        $teacherId,
        $title, $description, $category, $difficulty,
        $duration, $totalMarks, $passingMarks, $maxAttempts,
        $startTime, $endTime,
        $randomizeQuestions, $randomizeOptions, $visibility,
        $status,
    ]
);

if ($result['success'] && $result['insert_id'] > 0) {
    echo json_encode(['success' => true, 'assessment_id' => $result['insert_id']]);
} else {
    error_log("create assessment failed for teacher_id=$teacherId: " . ($result['error'] ?? 'unknown'));
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to create assessment. Please try again.']);
}
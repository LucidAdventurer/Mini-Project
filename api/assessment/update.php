<?php
// ============================================================
// api/assessment/update.php
//
// Saves edits for an existing assessment owned by the teacher.
//
// POST JSON {
//   assessment_id, title, description,
//   category, difficulty, duration_minutes, total_marks,
//   passing_marks, max_attempts, start_time, end_time,
//   randomize_questions, randomize_options,
//   visibility: 'public'|'group'|'private',
//   targets: [{ type: 'group'|'student', id: int }],
//   status: 'draft'|'active'
// }
// Returns { success: bool, error?: string }
// ============================================================

// Catch fatal errors / exceptions before any output so the
// client always gets valid JSON instead of an empty 500 body.
set_exception_handler(function (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    error_log('update.php exception: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    exit;
});

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
$description = trim($body['description'] ?? '');
$maxAttempts = max(1, (int)($body['max_attempts'] ?? 1));

// ── Datetime fields — null when not provided ──
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

// ── Targets (parse first — needed to derive visibility) ──
$targets = [];
if (!empty($body['targets']) && is_array($body['targets'])) {
    foreach ($body['targets'] as $t) {
        $ttype = trim($t['type'] ?? '');
        $tid   = (int)($t['id'] ?? 0);
        if (in_array($ttype, ['group', 'student'], true) && $tid > 0) {
            $targets[] = ['type' => $ttype, 'id' => $tid];
        }
    }
}

// ── Visibility — DB enum('public','group','private') ──
// The frontend checkbox only sends 'public' or 'private'.
// When targets are present and visibility is not public, auto-derive:
//   any 'group' target  → store as 'group'
//   only 'student' targets → store as 'private'
//   no targets          → store as 'private'
$visibilityRaw = trim($body['visibility'] ?? 'private');
if ($visibilityRaw === 'public') {
    $visibility = 'public';
} elseif (!empty($targets)) {
    $hasGroupTarget = false;
    foreach ($targets as $t) {
        if ($t['type'] === 'group') { $hasGroupTarget = true; break; }
    }
    $visibility = $hasGroupTarget ? 'group' : 'private';
} else {
    $visibility = 'private';
}

// ── Status — map 'active' → 'published' to match DB enum('draft','published','archived') ──
$status = trim($body['status'] ?? 'draft');
if ($status === 'active') $status = 'published';
if (!in_array($status, ['draft', 'published', 'archived'], true)) {
    $status = 'draft';
}

// ── Verify ownership ──
$chk = $conn->prepare("SELECT assessment_id FROM assessments WHERE assessment_id = ? AND created_by = ?");
if (!$chk) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB error (ownership check).']);
    exit;
}
$chk->bind_param("ii", $assessmentId, $teacherId);
$chk->execute();
$chk->store_result();
if ($chk->num_rows === 0) {
    $chk->close();
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Assessment not found or access denied.']);
    exit;
}
$chk->close();

// ── UPDATE assessments ──
//
// Columns (14 SET params + 2 WHERE params = 16 total):
//  # | type | param
//  1 |  s   | title
//  2 |  s   | description
//  3 |  s   | category
//  4 |  s   | difficulty
//  5 |  i   | duration_minutes
//  6 |  i   | total_marks
//  7 |  i   | passing_marks
//  8 |  i   | max_attempts
//  9 |  s   | start_time
// 10 |  s   | end_time
// 11 |  i   | randomize_questions
// 12 |  i   | randomize_options
// 13 |  s   | visibility
// 14 |  s   | status
// 15 |  i   | assessment_id (WHERE)
// 16 |  i   | created_by    (WHERE)
//
// Type string: s s s s i i i i s s i i s s i i = "ssssiiiissiiassii"
// Wait — positions 13,14 are 's', not 'a'. Let me write it character by character:
// 1=s, 2=s, 3=s, 4=s, 5=i, 6=i, 7=i, 8=i, 9=s, 10=s, 11=i, 12=i, 13=s, 14=s, 15=i, 16=i
// Concatenated: "ssssiiiissiissii"  ← 16 chars, verified

$upd = $conn->prepare(
    "UPDATE assessments
     SET title               = ?,
         description         = ?,
         category            = ?,
         difficulty          = ?,
         duration_minutes    = ?,
         total_marks         = ?,
         passing_marks       = ?,
         max_attempts        = ?,
         start_time          = ?,
         end_time            = ?,
         randomize_questions = ?,
         randomize_options   = ?,
         visibility          = ?,
         status              = ?,
         updated_at          = NOW()
     WHERE assessment_id = ? AND created_by = ?"
);

if (!$upd) {
    error_log("update.php prepare failed: " . $conn->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB prepare error: ' . $conn->error]);
    exit;
}

$upd->bind_param(
    "ssssiiiissiissii",   // 16 chars: ssss iiii ss ii ss ii
    $title,               //  1 s
    $description,         //  2 s
    $category,            //  3 s
    $difficulty,          //  4 s
    $duration,            //  5 i
    $totalMarks,          //  6 i
    $passingMarks,        //  7 i
    $maxAttempts,         //  8 i
    $startTime,           //  9 s  (nullable)
    $endTime,             // 10 s  (nullable)
    $randomizeQuestions,  // 11 i
    $randomizeOptions,    // 12 i
    $visibility,          // 13 s
    $status,              // 14 s
    $assessmentId,        // 15 i  WHERE
    $teacherId            // 16 i  WHERE
);

if (!$upd->execute()) {
    error_log("update.php execute failed: assessment_id=$assessmentId err=" . $upd->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Update failed: ' . $upd->error]);
    $upd->close();
    exit;
}
$upd->close();

// ── Sync assessment_targets ──
$del = $conn->prepare("DELETE FROM assessment_targets WHERE assessment_id = ?");
if ($del) {
    $del->bind_param("i", $assessmentId);
    $del->execute();
    $del->close();
}

if ($visibility !== 'public' && !empty($targets)) {
    $ins = $conn->prepare(
        "INSERT IGNORE INTO assessment_targets (assessment_id, target_type, target_id) VALUES (?, ?, ?)"
    );
    if ($ins) {
        foreach ($targets as $t) {
            $ins->bind_param("isi", $assessmentId, $t['type'], $t['id']);
            $ins->execute();
        }
        $ins->close();
    }
}

echo json_encode(['success' => true]);
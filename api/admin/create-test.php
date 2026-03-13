<?php
// ============================================================
// api/admin/create-test.php
//
// Creates a new assessment record. Questions are added
// separately after creation via add-questions.php.
//
// POST JSON {
//   title            : string   (required)
//   description      : string   (optional)
//   category         : string   (default 'general')
//   difficulty       : 'easy'|'medium'|'hard'  (default 'medium')
//   duration_minutes : int      (required, min 1)
//   total_marks      : int      (default 100)
//   passing_marks    : int      (default 40)
//   marks_per_q      : float    (stored in settings)
//   negative_marks   : float    (stored in settings)
//   max_attempts     : int      (default 3)
//   visibility       : 'public'|'private'  (default 'public')
//   status           : 'published'|'draft' (default 'published')
//   pdf_url          : string   (Cloudinary URL, optional)
//   public_id        : string   (Cloudinary public_id, optional)
//   file_size        : int      (bytes, optional)
// }
// Returns { success: bool, assessment_id: int }
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

// ── Parse & validate inputs ──
$title         = trim($body['title']       ?? '');
$description   = trim($body['description'] ?? '');
$category      = trim($body['category']    ?? 'general');
$difficulty    = trim($body['difficulty']  ?? 'medium');
$durationMin   = (int)($body['duration_minutes'] ?? 0);
$totalMarks    = (int)($body['total_marks']   ?? 100);
$passingMarks  = (int)($body['passing_marks'] ?? 40);
$marksPerQ     = (float)($body['marks_per_q']    ?? 1);
$negMarks      = (float)($body['negative_marks'] ?? 0);
$maxAttempts   = max(1, (int)($body['max_attempts'] ?? 3));
$visibility    = trim($body['visibility']  ?? 'public');
$status        = trim($body['status']      ?? 'published');
$pdfUrl        = trim($body['pdf_url']     ?? '');
$publicId      = trim($body['public_id']   ?? '');
$fileSize      = (int)($body['file_size']  ?? 0);

if ($title === '' || mb_strlen($title) > 200) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Title is required (max 200 characters).']);
    exit;
}
if ($durationMin < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Duration must be at least 1 minute.']);
    exit;
}

$allowedCategories   = ['aptitude','technical','verbal','logical','reasoning','english','coding','general'];
$allowedDifficulties = ['easy','medium','hard'];

if (!in_array($difficulty,  $allowedDifficulties, true)) $difficulty = 'medium';
if (!in_array($category,    $allowedCategories,   true)) $category   = 'general';
if (!in_array($visibility,  ['public','group','private'],            true)) $visibility = 'public';
if (!in_array($status,      ['draft','published','archived'],        true)) $status     = 'published';

// Append PDF note to description if a PDF is attached
if ($pdfUrl !== '') {
    $pdfNote = 'Question paper PDF attached. Download available during the test.';
    $description = $description !== '' ? $description . "\n\n" . $pdfNote : $pdfNote;
}

// ── Insert assessment row ──
$r = safePreparedQuery(
    $conn,
    "INSERT INTO assessments
        (created_by, title, description, category, difficulty,
         duration_minutes, total_marks, passing_marks, max_attempts,
         visibility, status, randomize_questions, randomize_options)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)",
    "issssiiiiiss",
    [
        $adminId, $title,
        $description ?: null,
        $category, $difficulty,
        $durationMin, $totalMarks, $passingMarks, $maxAttempts,
        $visibility, $status,
    ]
);

if (!$r['success'] || !$r['insert_id']) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to create assessment: ' . ($r['error'] ?? 'unknown')]);
    exit;
}

$assessmentId = (int) $r['insert_id'];

// ── Store per-question marking config in system_settings ──
$config = json_encode([
    'marks_per_q'    => $marksPerQ,
    'negative_marks' => $negMarks,
]);
safePreparedQuery(
    $conn,
    "INSERT INTO system_settings (setting_key, setting_value, setting_type, description)
     VALUES (?, ?, 'json', 'Marking config for assessment')
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
    "ss",
    ["assessment_config_{$assessmentId}", $config]
);

// ── Store PDF metadata if a PDF was uploaded ──
if ($pdfUrl !== '') {
    $meta = json_encode([
        'url'       => $pdfUrl,
        'public_id' => $publicId,
        'size'      => $fileSize,
    ]);
    safePreparedQuery(
        $conn,
        "INSERT INTO system_settings (setting_key, setting_value, setting_type, description)
         VALUES (?, ?, 'json', 'PDF attachment for assessment')
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
        "ss",
        ["assessment_pdf_{$assessmentId}", $meta]
    );
}

echo json_encode([
    'success'       => true,
    'assessment_id' => $assessmentId,
]);
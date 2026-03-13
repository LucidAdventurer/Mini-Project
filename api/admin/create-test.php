<?php
// ============================================================
// api/admin/create-test.php
//
// Admin-only. Creates a new assessment matching the actual DB
// schema (start_time/end_time, visibility, status enum).
//
// If pdf_url is provided, also inserts a linked resource entry
// in the `resources` table so the PDF appears in Resources tab
// and can be served to guests.
//
// POST JSON {
//   title, description, category, difficulty,
//   duration_minutes, total_marks, passing_marks,
//   max_attempts, start_time?, end_time?,
//   randomize_questions, randomize_options,
//   status ('draft'|'published'), visibility ('public'|'private'),
//   pdf_url?, pdf_public_id?, pdf_size?
// }
// Returns { success: bool, assessment_id?: int, error?: string }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$adminUser = validateSession($conn, 'admin');
$adminId   = (int) $adminUser['user_id'];

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

// ── Required fields ───────────────────────────────────────────────────────
$title        = trim($body['title']             ?? '');
$category     = trim($body['category']          ?? '');
$difficulty   = trim($body['difficulty']        ?? '');
$duration     = (int)($body['duration_minutes'] ?? 0);
$totalMarks   = (int)($body['total_marks']      ?? 0);
$passingMarks = (int)($body['passing_marks']    ?? 0);

if ($title === '') { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Title is required.']); exit; }
if (mb_strlen($title) > 200) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Title must not exceed 200 characters.']); exit; }

$allowedCategories   = ['aptitude','technical','coding','reasoning','english','general'];
$allowedDifficulties = ['easy','medium','hard'];

if (!in_array($category, $allowedCategories, true))   { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Invalid category.']); exit; }
if (!in_array($difficulty, $allowedDifficulties, true)){ http_response_code(400); echo json_encode(['success'=>false,'error'=>'Invalid difficulty.']); exit; }
if ($duration < 1 || $duration > 480)                  { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Duration must be 1–480 minutes.']); exit; }
if ($totalMarks < 1)                                   { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Total marks must be at least 1.']); exit; }
if ($passingMarks < 0 || $passingMarks > $totalMarks)  { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Passing marks must be between 0 and total marks.']); exit; }

// ── Optional fields ───────────────────────────────────────────────────────
$description = trim($body['description'] ?? '');
$maxAttempts = max(1, (int)($body['max_attempts'] ?? 1));
$randomizeQ  = !empty($body['randomize_questions']) ? 1 : 0;
$randomizeO  = !empty($body['randomize_options'])   ? 1 : 0;

$status = trim($body['status'] ?? 'draft');
if (!in_array($status, ['draft','published'], true)) $status = 'draft';

$visibility = trim($body['visibility'] ?? 'public');
if (!in_array($visibility, ['public','group','private'], true)) $visibility = 'public';

// ── Datetime: actual column names are start_time / end_time ──────────────
$startTime = null;
$endTime   = null;
if (!empty($body['start_time'])) { $ts = strtotime($body['start_time']); if ($ts !== false) $startTime = date('Y-m-d H:i:s', $ts); }
if (!empty($body['end_time']))   { $ts = strtotime($body['end_time']);   if ($ts !== false) $endTime   = date('Y-m-d H:i:s', $ts); }
if ($startTime && $endTime && $startTime >= $endTime) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'End time must be after start time.']);
    exit;
}

// ── Optional PDF attachment (uploaded to Cloudinary in the browser) ───────
$pdfUrl      = trim($body['pdf_url']       ?? '');
$pdfPublicId = trim($body['pdf_public_id'] ?? '');
$pdfSize     = (int)($body['pdf_size']     ?? 0);

// Validate PDF URL if provided
if ($pdfUrl !== '' && !filter_var($pdfUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>'Invalid PDF URL.']);
    exit;
}

// ── Insert assessment ──────────────────────────────────────────────────────
$result = safePreparedQuery($conn,
    "INSERT INTO assessments
        (created_by, title, description, visibility, status,
         category, difficulty, duration_minutes, total_marks, passing_marks,
         start_time, end_time, max_attempts,
         randomize_questions, randomize_options,
         created_at, updated_at)
     VALUES
        (?, ?, ?, ?, ?,
         ?, ?, ?, ?, ?,
         ?, ?, ?,
         ?, ?,
         NOW(), NOW())",
    "issssssiiissiii",
    [
        $adminId, $title, $description, $visibility, $status,
        $category, $difficulty, $duration, $totalMarks, $passingMarks,
        $startTime, $endTime, $maxAttempts,
        $randomizeQ, $randomizeO,
    ]
);

if (!$result['success'] || !isset($result['insert_id']) || $result['insert_id'] <= 0) {
    error_log("admin create-test failed for admin_id=$adminId: " . ($result['error'] ?? 'unknown'));
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'Failed to create test. Please try again.']);
    exit;
}

$assessmentId = $result['insert_id'];

// ── If a PDF was attached, save it as a resource entry too ────────────────
if ($pdfUrl !== '') {
    $resourceTitle = $title . ' — Question Paper';
    $resResult = safePreparedQuery($conn,
        "INSERT INTO resources
            (title, description, category, resource_type,
             external_url, file_size, cloudinary_public_id,
             is_public, uploaded_by, created_at)
         VALUES (?, ?, ?, 'pdf', ?, ?, ?, ?, ?, NOW())",
        "ssssiiii",
        [
            $resourceTitle,
            'Question paper PDF for: ' . $title,
            $category,
            $pdfUrl,
            $pdfSize,
            $pdfPublicId,
            ($visibility === 'public') ? 1 : 0,
            $adminId,
        ]
    );
    // Non-fatal: test is created even if resource insert fails
    if (!$resResult['success']) {
        error_log("create-test: resource insert failed for assessment $assessmentId: " . ($resResult['error'] ?? ''));
    }
}

echo json_encode(['success' => true, 'assessment_id' => $assessmentId]);
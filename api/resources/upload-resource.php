<?php
// ============================================================
// api/resources/upload-resource.php
//
// Saves a new material record to the `materials` table after
// the admin has already uploaded the file to Cloudinary from
// the browser. Only DB metadata is handled here.
//
// Requires: admin session + valid CSRF token.
//
// POST JSON {
//   title                : string   (required, max 200)
//   description          : string
//   category             : 'aptitude'|'verbal'|'logical'|'technical'|'general'
//   difficulty           : 'beginner'|'intermediate'|'advanced'
//   external_url         : string   (Cloudinary secure_url or plain URL)
//   cloudinary_public_id : string   (empty for plain links)
//   visibility           : 'public'|'group'|'private'  (default 'public')
// }
//
// Returns { success: bool, material_id: int }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Admin session guard ───────────────────────────────────────────────────
if (empty($_SESSION['user_id']) || empty($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required.']);
    exit;
}

// ── CSRF check ────────────────────────────────────────────────────────────
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrfHeader) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

// ── Parse body ────────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body.']);
    exit;
}

// ── Validate & sanitise ───────────────────────────────────────────────────
$title = trim($body['title'] ?? '');
if ($title === '' || mb_strlen($title) > 200) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Title is required (max 200 chars).']);
    exit;
}

$validCategories  = ['aptitude', 'verbal', 'logical', 'technical', 'general'];
$validDifficulties = ['beginner', 'intermediate', 'advanced'];
$validVisibilities = ['public', 'group', 'private'];

$category   = strtolower(trim($body['category'] ?? 'general'));
$difficulty = strtolower(trim($body['difficulty'] ?? 'beginner'));
$visibility = strtolower(trim($body['visibility'] ?? 'public'));

if (!in_array($category, $validCategories, true))     $category   = 'general';
if (!in_array($difficulty, $validDifficulties, true)) $difficulty = 'beginner';
if (!in_array($visibility, $validVisibilities, true)) $visibility = 'public';

$description        = trim($body['description']          ?? '');
$externalUrl        = trim($body['external_url']         ?? '');
$cloudinaryPublicId = trim($body['cloudinary_public_id'] ?? '');

if ($externalUrl === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'A URL or uploaded file URL is required.']);
    exit;
}

if (!filter_var($externalUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid URL format.']);
    exit;
}

$createdBy = (int)$_SESSION['user_id'];

// ── Insert ────────────────────────────────────────────────────────────────
// Columns: title, description, created_by, visibility,
//          cloudinary_public_id, external_url, category, difficulty
// (created_at has a default; no file_size, resource_type, is_public,
//  uploaded_by, tags, estimated_time_minutes in the actual schema)
$ins = safePreparedQuery(
    $conn,
    'INSERT INTO materials
         (title, description, created_by, visibility,
          cloudinary_public_id, external_url, category, difficulty)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
    'ssisssss',
    [
        $title,
        $description,
        $createdBy,
        $visibility,
        $cloudinaryPublicId ?: null,
        $externalUrl,
        $category,
        $difficulty,
    ]
);

if (!$ins['success']) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save material to database.']);
    exit;
}

$newId = $conn->insert_id;

echo json_encode(['success' => true, 'material_id' => $newId]);

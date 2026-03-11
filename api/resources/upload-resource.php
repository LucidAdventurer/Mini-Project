<?php
// ============================================================
// api/resources/upload-resource.php
//
// Saves a resource record to the `resources` table after the
// admin has already uploaded the file to Cloudinary from the
// browser. This endpoint only handles DB metadata — the file
// itself travels directly from browser → Cloudinary.
//
// Requires: admin session + valid CSRF token.
//
// POST JSON {
//   title                 : string   (required)
//   material_type         : 'pdf'|'video'|'link'|'article'|'quiz'
//   category              : 'aptitude'|'verbal'|'logical'|'technical'|'general'
//   description           : string
//   external_url          : string   (Cloudinary secure_url or plain URL)
//   file_size             : int      (bytes; 0 for links/articles)
//   cloudinary_public_id  : string   (empty for links/articles)
//   is_public             : 0|1
// }
//
// Returns { success: bool, resource_id: int }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

// ── Method ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Admin session guard ────────────────────────────────────────────────────
if (empty($_SESSION['user_id']) || empty($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required.']);
    exit;
}

// ── CSRF check ─────────────────────────────────────────────────────────────
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrfHeader) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

// ── Parse body ─────────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body.']);
    exit;
}

// ── Validate & sanitise inputs ─────────────────────────────────────────────
$title = trim($body['title'] ?? '');
if ($title === '' || mb_strlen($title) > 200) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Title is required (max 200 chars).']);
    exit;
}

$validTypes      = ['pdf', 'video', 'link', 'article', 'quiz'];
$validCategories = ['aptitude', 'verbal', 'logical', 'technical', 'general'];

$materialType = strtolower(trim($body['material_type'] ?? ''));
if (!in_array($materialType, $validTypes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid material type.']);
    exit;
}

$category = strtolower(trim($body['category'] ?? 'general'));
if (!in_array($category, $validCategories, true)) {
    $category = 'general';
}

$description        = trim($body['description']           ?? '');
$externalUrl        = trim($body['external_url']          ?? '');
$fileSize           = max(0, (int)($body['file_size']     ?? 0));
$cloudinaryPublicId = trim($body['cloudinary_public_id']  ?? '');
$isPublic           = (int)(bool)($body['is_public']      ?? 1);

// File types must have a URL (the Cloudinary secure_url); link/article must also have a URL
if ($externalUrl === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'A URL or uploaded file URL is required.']);
    exit;
}

// Basic URL validation
if (!filter_var($externalUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid URL format.']);
    exit;
}

$uploadedBy = (int)$_SESSION['user_id'];

// ── Insert ─────────────────────────────────────────────────────────────────
$ins = safePreparedQuery(
    $conn,
    "INSERT INTO resources
        (title, description, category, resource_type,
         external_url, file_size, cloudinary_public_id,
         is_public, uploaded_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
    "sssssisis",
    [
        $title,
        $description,
        $category,
        $materialType,
        $externalUrl,
        $fileSize,
        $cloudinaryPublicId,
        $isPublic,
        $uploadedBy,
    ]
);

if (!$ins['success']) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save resource to database.']);
    exit;
}

$newId = $conn->insert_id;

echo json_encode(['success' => true, 'resource_id' => $newId]);
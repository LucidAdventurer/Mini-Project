<?php
// ============================================================
// api/materials/create-material.php
//
// Teacher uploads a new study resource (file or external link).
// After saving, notifies all eligible students via
// notifyMaterialUploaded().
//
// POST multipart/form-data OR JSON {
//   title:       string  (required)
//   description: string
//   category:    string  (required) aptitude|technical|coding|reasoning|english|general
//   difficulty:  string  easy|medium|hard
//   visibility:  string  public|group|private  (default: public)
//   external_url: string  (for link-type resources)
//   // File upload field: 'material_file'  (PDF, video, doc)
// }
//
// Returns { success: bool, material_id?: int, error?: string }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';
require_once __DIR__ . '/../notify-helpers.php';

header('Content-Type: application/json');

$currentUser = validateSession($conn, 'teacher');
$teacherId   = (int) $currentUser['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Accept both multipart (file upload) and JSON (link-only) ──
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    $body = $_POST;
}

// ── Required fields ──
$title      = trim($body['title']       ?? '');
$category   = trim($body['category']    ?? '');
$visibility = trim($body['visibility']  ?? 'public');

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

$allowedCategories  = ['aptitude', 'technical', 'coding', 'reasoning', 'english', 'general'];
$allowedDifficulties = ['easy', 'medium', 'hard'];
$allowedVisibilities = ['public', 'group', 'private'];

if (!in_array($category, $allowedCategories, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid category.']);
    exit;
}
if (!in_array($visibility, $allowedVisibilities, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid visibility.']);
    exit;
}

// ── Optional fields ──
$description  = trim($body['description']  ?? '');
$difficulty   = trim($body['difficulty']   ?? '');
$externalUrl  = trim($body['external_url'] ?? '');

if ($difficulty !== '' && !in_array($difficulty, $allowedDifficulties, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid difficulty.']);
    exit;
}
$difficulty = $difficulty ?: null;

// Validate external URL if provided
if ($externalUrl !== '' && !filter_var($externalUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid external URL.']);
    exit;
}
$externalUrl = $externalUrl ?: null;

// ── Handle optional file upload ──
// Stores Cloudinary public_id after upload (Cloudinary integration
// handled separately — here we just accept the public_id if already
// uploaded client-side, or a raw tmp path for server-side upload).
$cloudinaryPublicId = trim($body['cloudinary_public_id'] ?? '') ?: null;

// If neither a file reference nor a URL is provided, that's allowed
// (teacher may add file later via edit). But warn if both are missing.
if ($cloudinaryPublicId === null && $externalUrl === null) {
    // Still valid — resource created as a placeholder
}

// ── Insert material ──
$result = safePreparedQuery($conn,
    "INSERT INTO materials
        (title, description, category, difficulty, visibility,
         cloudinary_public_id, external_url,
         created_by, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
    "sssssssi",
    [
        $title, $description, $category, $difficulty, $visibility,
        $cloudinaryPublicId, $externalUrl,
        $teacherId,
    ]
);

if (!$result['success'] || $result['insert_id'] <= 0) {
    error_log("create-material failed for teacher_id=$teacherId: " . ($result['error'] ?? 'unknown'));
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save material. Please try again.']);
    exit;
}

$materialId = $result['insert_id'];

// ── Notify students ──
// Only notifies if visibility is 'public' or 'group' (private skipped
// inside notifyMaterialUploaded → resolve_material_students).
notifyMaterialUploaded($conn, $materialId);

echo json_encode(['success' => true, 'material_id' => $materialId]);

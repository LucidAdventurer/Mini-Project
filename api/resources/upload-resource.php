<?php
// ============================================================
// api/resources/upload-resource.php
//
// Handles resource uploads from teacher-resources.php.
//
// Supports two modes sent by the frontend:
//   action = "upload"       → multipart/form-data with a file
//   action = "upload_link"  → JSON body with an external_url
//
// Frontend fields (FormData / JSON):
//   title, description, category, is_public (0|1),
//   available_from, available_until,
//   file          (FormData only)
//   external_url  (JSON only)
//
// Requires: teacher or admin session + valid CSRF token.
// ============================================================

// ── Must be ABSOLUTELY first — suppress HTML errors for this API endpoint ──
// config.php sets display_errors=1 in development which outputs HTML and
// corrupts our JSON response. Force errors to log only, never to output.
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// ── Increase upload limits BEFORE anything else ───────────────────────────
@ini_set('upload_max_filesize', '55M');
@ini_set('post_max_size',       '60M');
@ini_set('memory_limit',        '256M');

// ── Catch PHP's own post_max_size overflow early ──────────────────────────
// When POST data exceeds post_max_size, PHP empties $_POST and $_FILES.
// Detect this and return clean JSON instead of broken HTML.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_SERVER['CONTENT_LENGTH']) &&
    (int) $_SERVER['CONTENT_LENGTH'] > 0 &&
    empty($_POST) && empty($_FILES) &&
    strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false
) {
    header('Content-Type: application/json');
    http_response_code(413);
    echo json_encode(['success' => false, 'error' => 'File too large. Maximum upload size is 50 MB.']);
    exit;
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

// ── Method guard ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Session guard — teacher or admin ─────────────────────────────────────
$sessionRole = $_SESSION['role'] ?? $_SESSION['user_type'] ?? '';
if (empty($_SESSION['user_id']) || !in_array($sessionRole, ['admin', 'teacher'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied. Teacher or admin account required.']);
    exit;
}

// ── CSRF check ────────────────────────────────────────────────────────────
$csrfSent    = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
$csrfSession = $_SESSION['csrf_token'] ?? '';
if ($csrfSession === '' || !hash_equals($csrfSession, $csrfSent)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

// ── Detect mode: file upload vs JSON link ─────────────────────────────────
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isJson      = str_contains($contentType, 'application/json');

if ($isJson) {
    // JSON body (action = upload_link)
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON body.']);
        exit;
    }
    $action             = $body['action']               ?? 'upload_link';
    $title              = trim($body['title']            ?? '');
    $description        = trim($body['description']      ?? '');
    $category           = trim($body['category']         ?? '');
    // FIX: accept both 'visibility' => 'public' and 'is_public' => 1 from JSON callers
    $isPublic           = (($body['visibility'] ?? '') === 'public' || ($body['is_public'] ?? 0) == 1) ? 1 : 0;
    $availFrom          = $body['available_from']        ?? null;
    $availUntil         = $body['available_until']       ?? null;
    $externalUrl        = trim($body['external_url']     ?? '');
    $cloudinaryPublicId = trim($body['cloudinary_public_id'] ?? '');
    $uploadedFile       = null;
} else {
    // FormData (action = upload)
    $action             = $_POST['action']          ?? 'upload';
    $title              = trim($_POST['title']       ?? '');
    $description        = trim($_POST['description'] ?? '');
    $category           = trim($_POST['category']    ?? '');
    // FIX: frontend sends is_public="1" or is_public="0" (string).
    // The old check used !empty($_POST['is_public']) which treats "0" as truthy.
    // Now we check visibility first (preferred), then fall back to is_public === "1" strictly.
    $isPublic           = (($_POST['visibility'] ?? '') === 'public' || ($_POST['is_public'] ?? '') === '1') ? 1 : 0;
    $availFrom          = $_POST['available_from']   ?? null;
    $availUntil         = $_POST['available_until']  ?? null;
    $externalUrl        = '';
    $cloudinaryPublicId = '';
    $uploadedFile       = $_FILES['file'] ?? null;
}

// ── Validate title ────────────────────────────────────────────────────────
if ($title === '' || mb_strlen($title) > 200) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Title is required (max 200 chars).']);
    exit;
}

// ── Sanitise category ─────────────────────────────────────────────────────
$validCategories = ['aptitude', 'verbal', 'logical', 'technical', 'general', 'coding'];
$category = strtolower($category);
if (!in_array($category, $validCategories, true)) $category = 'general';

// ── Sanitise dates (allow null / empty) ───────────────────────────────────
$availFrom  = ($availFrom  && $availFrom  !== '') ? $availFrom  : null;
$availUntil = ($availUntil && $availUntil !== '') ? $availUntil : null;

$createdBy  = (int) $_SESSION['user_id'];
$fileUrl    = null;
$fileSize   = null;
$fileType   = null;
$destPath   = null;

// ── Handle file upload ───────────────────────────────────────────────────
if ($action === 'upload' && $uploadedFile) {
    if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'File upload error code: ' . $uploadedFile['error']]);
        exit;
    }

    $maxBytes = 50 * 1024 * 1024; // 50 MB
    if ($uploadedFile['size'] > $maxBytes) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'File exceeds 50 MB limit.']);
        exit;
    }

    // Allowed MIME types → resource type label
    $mimeMap = [
        'application/pdf'  => 'pdf',
        'video/mp4'        => 'video',
        'video/webm'       => 'video',
        'video/ogg'        => 'video',
        'image/jpeg'       => 'image',
        'image/png'        => 'image',
        'image/gif'        => 'image',
        'image/webp'       => 'image',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'document',
        'application/msword' => 'document',
    ];

    $mime = mime_content_type($uploadedFile['tmp_name']);
    if (!array_key_exists($mime, $mimeMap)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'File type not allowed. Accepted: PDF, MP4, JPEG, PNG, DOCX.']);
        exit;
    }

    $fileType = $mimeMap[$mime];
    $fileSize = (int) $uploadedFile['size'];

    // Save file to uploads/materials/
    $ext       = pathinfo($uploadedFile['name'], PATHINFO_EXTENSION);
    $safeName  = bin2hex(random_bytes(12)) . '.' . strtolower($ext);
    $uploadDir = __DIR__ . '/../../uploads/materials/';

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $destPath = $uploadDir . $safeName;
    if (!move_uploaded_file($uploadedFile['tmp_name'], $destPath)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to save uploaded file.']);
        exit;
    }

    $fileUrl = 'uploads/materials/' . $safeName;

} elseif ($action === 'upload_link') {
    if ($externalUrl === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'A URL is required.']);
        exit;
    }
    if (!filter_var($externalUrl, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid URL format.']);
        exit;
    }
    $fileUrl  = $externalUrl;
    $fileType = 'link';
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No file or URL provided.']);
    exit;
}

// ── Map frontend is_public (0/1) → DB visibility enum ────────────────────
// Frontend sends is_public=1 for "Public (all students)", 0 for private
$visibility = $isPublic ? 'public' : 'private';

// ── Insert into materials ─────────────────────────────────────────────────
// Columns: material_id(auto), title, description, created_by, visibility,
//          cloudinary_public_id, external_url, category
$ins = safePreparedQuery(
    $conn,
    'INSERT INTO materials
         (title, description, created_by, visibility,
          cloudinary_public_id, external_url, category)
     VALUES (?, ?, ?, ?, ?, ?, ?)',
    'ssissss',
    [
        $title,
        $description,
        $createdBy,           // created_by: user_id of the uploader
        $visibility,          // visibility: 'public' or 'private'
        $cloudinaryPublicId ?: null,  // cloudinary_public_id from frontend
        $fileUrl,             // external_url: Cloudinary URL or external link
        $category,
    ]
);

if (!$ins['success']) {
    // Clean up orphaned file if DB insert failed
    if ($destPath && file_exists($destPath)) {
        unlink($destPath);
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save resource to database.']);
    exit;
}

$newId = $ins['insert_id'];

// ── Notify students for public resources ──────────────────────────────────
if ($isPublic) {
    $notifTitle   = '📚 New Resource Available';
    $notifMessage = 'A new resource "' . $title . '" has been shared with you.';

    $students = safePreparedQuery(
        $conn,
        "SELECT user_id FROM users WHERE role = 'student' AND is_active = 1",
        '', []
    );

    if ($students['success'] && $students['result']) {
        $studentIds = [];
        while ($row = $students['result']->fetch_assoc()) {
            $studentIds[] = (int) $row['user_id'];
        }
        $students['result']->free();

        if (!empty($studentIds)) {
            $placeholders = implode(', ', array_fill(0, count($studentIds), '(?, ?, ?, ?, ?)'));
            $types  = str_repeat('isssi', count($studentIds));
            $params = [];
            foreach ($studentIds as $sid) {
                $params[] = $sid;
                $params[] = $notifTitle;
                $params[] = $notifMessage;
                $params[] = 'material';
                $params[] = $newId;
            }
            safePreparedQuery(
                $conn,
                "INSERT INTO notifications (user_id, title, message, type, related_entity_id)
                 VALUES $placeholders",
                $types, $params
            );
        }
    }
}

echo json_encode(['success' => true, 'material_id' => $newId]);

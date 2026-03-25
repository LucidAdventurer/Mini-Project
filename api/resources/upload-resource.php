<?php
// ============================================================
// api/resources/upload-resource.php
//
// File uploads go to Cloudinary (unsigned upload preset).
// External links are stored directly in external_url.
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Session guard ─────────────────────────────────────────────────────────
$sessionRole = $_SESSION['role'] ?? '';
if (empty($_SESSION['user_id']) || !in_array($sessionRole, ['admin', 'teacher'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
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

// ── Detect mode ───────────────────────────────────────────────────────────
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isJson      = str_contains($contentType, 'application/json');

if ($isJson) {
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
    // Accept both 'visibility' => 'public' and 'is_public' => 1 from JSON callers
    $isPublic           = (($body['visibility'] ?? '') === 'public' || ($body['is_public'] ?? 0) == 1) ? 1 : 0;
    $externalUrl        = trim($body['external_url']     ?? '');
    $uploadedFile       = null;
} else {
    // FormData (action = upload)
    $action             = $_POST['action']          ?? 'upload';
    $title              = trim($_POST['title']       ?? '');
    $description        = trim($_POST['description'] ?? '');
    $category           = trim($_POST['category']    ?? '');
    // Frontend sends is_public="1" or is_public="0" (string).
    // Check visibility first (preferred), then fall back to is_public === "1" strictly.
    $isPublic           = (($_POST['visibility'] ?? '') === 'public' || ($_POST['is_public'] ?? '') === '1') ? 1 : 0;
    $externalUrl        = '';
    $uploadedFile       = $_FILES['file'] ?? null;
}

// ── Validate title ────────────────────────────────────────────────────────
if ($title === '' || mb_strlen($title) > 200) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Title is required (max 200 chars).']);
    exit;
}

// ── Sanitise category ─────────────────────────────────────────────────────
$validCategories = ['aptitude', 'verbal', 'logical', 'technical', 'general', 'coding', 'reasoning', 'english'];
$category = strtolower($category);
if (!in_array($category, $validCategories, true)) $category = 'general';

$visibility = $isPublic ? 'public' : 'private';
$createdBy  = (int) $_SESSION['user_id'];

// These will be set by the upload or link branch below
$cloudinaryPublicId = null;
$storedExternalUrl  = null;

// ── Handle file upload → Cloudinary ──────────────────────────────────────
if ($action === 'upload' && $uploadedFile) {
    if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'File upload error code: ' . $uploadedFile['error']]);
        exit;
    }

    $maxBytes = 50 * 1024 * 1024;
    if ($uploadedFile['size'] > $maxBytes) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'File exceeds 50 MB limit.']);
        exit;
    }

    $allowedMimes = [
        'application/pdf',
        'video/mp4', 'video/webm', 'video/ogg',
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/msword',
    ];
    $mime = mime_content_type($uploadedFile['tmp_name']);
    if (!in_array($mime, $allowedMimes, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'File type not allowed. Accepted: PDF, MP4, JPEG, PNG, DOCX.']);
        exit;
    }

    // Determine Cloudinary resource_type
    if (str_starts_with($mime, 'video/')) {
        $resourceType = 'video';
    } elseif (str_starts_with($mime, 'image/')) {
        $resourceType = 'image';
    } else {
        $resourceType = 'raw';  // PDF, DOCX, etc.
    }

    $cloudName    = defined('CLOUDINARY_CLOUD_NAME') ? CLOUDINARY_CLOUD_NAME : 'dmysg5azm';
    $uploadPreset = 'ptauploads';
    $uploadUrl    = "https://api.cloudinary.com/v1_1/{$cloudName}/{$resourceType}/upload";

    $postFields = [
        'file'          => new CURLFile($uploadedFile['tmp_name'], $mime, $uploadedFile['name']),
        'upload_preset' => $uploadPreset,
        'folder'        => 'materials',
    ];

    $ch = curl_init($uploadUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr || $httpCode !== 200) {
        error_log("Cloudinary upload failed: HTTP $httpCode — $curlErr — $response");
        http_response_code(502);
        echo json_encode(['success' => false, 'error' => 'Failed to upload file to Cloudinary. Please try again.']);
        exit;
    }

    $cloudData = json_decode($response, true);
    if (empty($cloudData['public_id'])) {
        error_log("Cloudinary missing public_id: $response");
        http_response_code(502);
        echo json_encode(['success' => false, 'error' => 'Cloudinary did not return a valid response.']);
        exit;
    }

    $cloudinaryPublicId = $cloudData['public_id'];
    // external_url stays null for Cloudinary uploads

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
    $storedExternalUrl = $externalUrl;
    // cloudinary_public_id stays null for link uploads

} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No file or URL provided.']);
    exit;
}

// ── Insert into materials ─────────────────────────────────────────────────
// Live schema columns: material_id (auto), title, description, created_by,
//   visibility, cloudinary_public_id, external_url, category, created_at.
// FIX: was using undefined $fileUrl — corrected to $storedExternalUrl.
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
        $createdBy,
        $visibility,
        $cloudinaryPublicId,   // null for link uploads
        $storedExternalUrl,    // FIX: was $fileUrl (undefined). null for Cloudinary uploads.
        $category,
    ]
);

if (!$ins['success']) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save resource to database.']);
    exit;
}

$newId = $ins['insert_id'];

// ── Notify students for public resources ──────────────────────────────────
if ($visibility === 'public') {
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
            $types        = str_repeat('isssi', count($studentIds));
            $params       = [];
            foreach ($studentIds as $sid) {
                $params[] = $sid;
                $params[] = $notifTitle;
                $params[] = $notifMessage;
                $params[] = 'material';   // notifications.type enum includes 'material'
                $params[] = $newId;
            }
            safePreparedQuery(
                $conn,
                "INSERT IGNORE INTO notifications (user_id, title, message, type, related_entity_id)
                 VALUES $placeholders",
                $types, $params
            );
        }
    }
}

echo json_encode(['success' => true, 'material_id' => $newId]);
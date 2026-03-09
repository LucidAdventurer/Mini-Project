<?php
// ============================================================
// api/profile/upload-avatar.php
// Any logged-in user. Uploads a profile picture.
//
// POST multipart/form-data: avatar (image file)
// Returns { success, profile_image_url }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$currentUser = validateSession($conn);
$userId = (int)$currentUser['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE  => 'File exceeds server limit.',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds form limit.',
        UPLOAD_ERR_PARTIAL   => 'File only partially uploaded.',
        UPLOAD_ERR_NO_FILE   => 'No file uploaded.',
    ];
    $code = $_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE;
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $uploadErrors[$code] ?? 'Upload error.']);
    exit;
}

$file     = $_FILES['avatar'];
$tmpPath  = $file['tmp_name'];
$fileSize = $file['size'];
$mimeType = mime_content_type($tmpPath);

if ($fileSize > 2 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Avatar must be under 2 MB.']);
    exit;
}

$allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($mimeType, $allowedMimes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Only JPG, PNG, GIF, or WebP images are allowed.']);
    exit;
}

$extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
$ext    = $extMap[$mimeType];

// Build upload path relative to project root — no hardcoded absolute paths
$uploadDir = __DIR__ . '/../../uploads/avatars/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$filename = 'avatar_' . $userId . '_' . time() . '.' . $ext;
$destPath = $uploadDir . $filename;

if (!move_uploaded_file($tmpPath, $destPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save image.']);
    exit;
}

// Delete old avatar file if it exists
$r = safePreparedQuery($conn,
    "SELECT profile_image FROM users WHERE user_id = ?", "i", [$userId]);
if ($r['success'] && $r['result']) {
    $old = $r['result']->fetch_assoc();
    $r['result']->free();
    if (!empty($old['profile_image'])) {
        $oldPath = __DIR__ . '/../../' . ltrim($old['profile_image'], '/');
        if (file_exists($oldPath)) @unlink($oldPath);
    }
}

$relPath = 'uploads/avatars/' . $filename;

$ru = safePreparedQuery($conn,
    "UPDATE users SET profile_image = ? WHERE user_id = ?", "si", [$relPath, $userId]);

if (!$ru['success']) {
    @unlink($destPath);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save avatar.']);
    exit;
}

echo json_encode(['success' => true, 'profile_image_url' => $relPath]);

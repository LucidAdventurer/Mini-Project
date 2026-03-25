<?php
// ============================================================
// api/admin/upload-admin-resource.php
//
// Admin uploads go to the `resources` table.
// File is already uploaded to Cloudinary client-side.
// POST JSON {
//   title, description, category,
//   visibility: 'public'|'private',
//   cloudinary_public_id?: string,
//   external_url?: string,
//   file_size?: int
// }
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

$title       = trim($body['title']                ?? '');
$description = trim($body['description']          ?? '');
$category    = trim($body['category']             ?? 'general');
$visibility  = trim($body['visibility']           ?? 'public');
$publicId    = trim($body['cloudinary_public_id'] ?? '');
$externalUrl = trim($body['external_url']         ?? '');
$fileSize    = (int)($body['file_size']           ?? 0);

if ($title === '' || mb_strlen($title) > 200) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Title is required (max 200 chars).']);
    exit;
}

$validCategories = ['aptitude', 'verbal', 'logical', 'technical', 'general'];
if (!in_array($category, $validCategories, true)) $category = 'general';

$isPublic = ($visibility === 'public') ? 1 : 0;

if ($publicId === '' && $externalUrl === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No file or URL provided.']);
    exit;
}

if ($externalUrl !== '' && !filter_var($externalUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid URL format.']);
    exit;
}

// Derive resource_type from cloudinary_public_id extension
$resourceType = 'link';
if ($publicId !== '') {
    $ext = strtolower(pathinfo($publicId, PATHINFO_EXTENSION));
    if ($ext === 'pdf')                                              $resourceType = 'pdf';
    elseif (in_array($ext, ['mp4','webm','ogg','mov'], true))       $resourceType = 'video';
    elseif (in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) $resourceType = 'image';
    elseif (in_array($ext, ['doc','docx'], true))                   $resourceType = 'document';
    else                                                             $resourceType = 'pdf';
}

$finalPublicId   = $publicId   !== '' ? $publicId   : null;
$finalExtUrl     = $externalUrl !== '' ? $externalUrl : null;
$finalFileSize   = $fileSize > 0 ? $fileSize : null;

$r = safePreparedQuery($conn,
    "INSERT INTO resources
        (title, description, category, resource_type,
         cloudinary_public_id, external_url, file_size,
         is_public, uploaded_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
    'ssssssiii',
    [
        $title,
        $description ?: null,
        $category,
        $resourceType,
        $finalPublicId,
        $finalExtUrl,
        $finalFileSize,
        $isPublic,
        $adminId,
    ]
);

if (!$r['success'] || !$r['insert_id']) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save resource.']);
    exit;
}

echo json_encode(['success' => true, 'resource_id' => $r['insert_id']]);

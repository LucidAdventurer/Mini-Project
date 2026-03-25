<?php
// ============================================================
// api/admin/serve-admin-resource.php
//
// Serves a file from the `resources` table via signed Cloudinary URL.
// GET ?resource_id=int&action=view|download
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

$sessionUid  = (int)($_SESSION['user_id'] ?? 0);
$sessionRole = $_SESSION['role'] ?? '';
if ($sessionUid <= 0 || $sessionRole !== 'admin') {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$resourceId = (int)($_GET['resource_id'] ?? 0);
$action     = in_array($_GET['action'] ?? '', ['view','download'], true) ? $_GET['action'] : 'view';

if ($resourceId <= 0) {
    http_response_code(400); echo 'Invalid resource ID.'; exit;
}

$r = safePreparedQuery($conn,
    "SELECT resource_id, title, cloudinary_public_id, external_url, resource_type
     FROM resources WHERE resource_id = ?",
    'i', [$resourceId]
);

if (!$r['success'] || !$r['result'] || $r['result']->num_rows === 0) {
    http_response_code(404); echo 'Resource not found.'; exit;
}

$res = $r['result']->fetch_assoc();
$r['result']->free();

$publicId    = $res['cloudinary_public_id'] ?? '';
$externalUrl = $res['external_url'] ?? '';
$title       = $res['title'];

// ── External URL ──────────────────────────────────────────────────────────
if ($externalUrl !== '' && $publicId === '') {
    if (filter_var($externalUrl, FILTER_VALIDATE_URL)) {
        header('Location: ' . $externalUrl);
        exit;
    }
    http_response_code(400); echo 'Invalid URL.'; exit;
}

// ── Cloudinary ────────────────────────────────────────────────────────────
if ($publicId === '') {
    http_response_code(404); echo 'No file associated with this resource.'; exit;
}

$cloudName = defined('CLOUDINARY_CLOUD_NAME') ? CLOUDINARY_CLOUD_NAME : 'dmysg5azm';
$ext       = strtolower(pathinfo($publicId, PATHINFO_EXTENSION));

$videoExts = ['mp4','webm','ogg','mov'];
$imageExts = ['jpg','jpeg','png','gif','webp'];

if (in_array($ext, $videoExts, true))       $resourceType = 'video';
elseif (in_array($ext, $imageExts, true))   $resourceType = 'image';
else                                         $resourceType = 'raw';

// Signed URL if credentials available (must use !empty, not defined —
// config.php defines these as '' when not set, which would produce an
// invalid signature and a 401 from Cloudinary)
if (!empty(CLOUDINARY_API_KEY) && !empty(CLOUDINARY_API_SECRET)) {
    $timestamp  = time();
    $expiresAt  = $timestamp + 300;
    $attachment = $action === 'download' ? 'true' : 'false';

    $sigString = "attachment={$attachment}&expires_at={$expiresAt}&public_id={$publicId}&timestamp={$timestamp}&type=upload"
               . CLOUDINARY_API_SECRET;
    $signature = sha1($sigString);

    $deliveryUrl = "https://api.cloudinary.com/v1_1/{$cloudName}/{$resourceType}/download?"
        . http_build_query([
            'attachment' => $attachment,
            'expires_at' => $expiresAt,
            'public_id'  => $publicId,
            'timestamp'  => $timestamp,
            'type'       => 'upload',
            'api_key'    => CLOUDINARY_API_KEY,
            'signature'  => $signature,
        ]);

    header('Location: ' . $deliveryUrl);
    exit;
}

// Fallback unsigned for images/video
if ($resourceType === 'image' || $resourceType === 'video') {
    header('Location: https://res.cloudinary.com/' . $cloudName . '/' . $resourceType . '/upload/' . $publicId);
    exit;
}

http_response_code(501);
echo 'Add CLOUDINARY_API_KEY and CLOUDINARY_API_SECRET to config.php to serve PDF/document files.';
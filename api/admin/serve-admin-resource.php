<?php
/* ========================================
 * ADMIN RESOURCE SERVE
 * File: api/admin/serve-admin-resource.php
 *
 * Proxies a resource's bytes through PHP so the browser receives the correct
 * Content-Type header and can render PDFs inline.
 *
 * WHY PROXY (not redirect):
 *   Cloudinary stores files without extensions in the public_id
 *   (e.g. /image/upload/pta/b8qd9vszzg7it4hdvcpo). Without an extension
 *   Chrome cannot detect the MIME type, so redirecting directly produces
 *   "Failed to load PDF document" even though the bytes are correct.
 *   Proxying through PHP lets us set Content-Type explicitly.
 *
 * WHY NOT USE validateSession():
 *   db-guard's validateSession() calls isApiRequest() which matches any URL
 *   containing /api/ and returns a JSON 401 — the browser tab / iframe
 *   receives JSON instead of a file. We authenticate manually here so we can
 *   issue a plain redirect to the login page on auth failure.
 * ======================================== */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

// ── 1. Auth (manual — avoids JSON 401 from isApiRequest) ────────────────────

$uid  = getSessionUserId();
$role = getSessionRole();

if ($uid === 0 || $role === '') {
    header('Location: ' . _adminRootUrl() . 'index.html?error=session_expired');
    exit;
}
if ($role !== 'admin') {
    header('Location: ' . _adminRootUrl() . 'index.html?error=unauthorized');
    exit;
}
$adminRow = getUserData($conn, $uid);
if (!$adminRow || !$adminRow['is_active']) {
    session_destroy();
    header('Location: ' . _adminRootUrl() . 'index.html?error=session_expired');
    exit;
}

// ── 2. Input ─────────────────────────────────────────────────────────────────

$resourceId = filter_input(INPUT_GET, 'resource_id', FILTER_VALIDATE_INT);
$action     = in_array($_GET['action'] ?? '', ['view', 'download'], true)
              ? $_GET['action'] : 'view';

if (!$resourceId || $resourceId <= 0) {
    http_response_code(400);
    die('Invalid resource ID.');
}

// ── 3. Fetch resource ────────────────────────────────────────────────────────

$q = safePreparedQuery(
    $conn,
    "SELECT resource_id, title, cloudinary_public_id, external_url, resource_type
     FROM   resources
     WHERE  resource_id = ?
     LIMIT  1",
    'i', [$resourceId]
);

if (!$q['success'] || !$q['result']) {
    http_response_code(500); die('Database error.');
}
$resource = $q['result']->fetch_assoc();
$q['result']->free();

if (!$resource) {
    http_response_code(404); die('Resource not found.');
}

// ── 4. Counter ───────────────────────────────────────────────────────────────

$col = $action === 'download' ? 'downloads' : 'views';
$conn->query("UPDATE resources SET {$col} = {$col} + 1 WHERE resource_id = " . (int)$resourceId);

// ── 5. Resolve source URL ────────────────────────────────────────────────────

$publicId    = $resource['cloudinary_public_id'] ?? '';
$externalUrl = $resource['external_url']         ?? '';
$title       = $resource['title']                ?? 'resource';

if (!$publicId && !$externalUrl) {
    http_response_code(404); die('No file attached to this resource.');
}

if ($publicId) {
    $cloudName = defined('CLOUDINARY_CLOUD_NAME') ? CLOUDINARY_CLOUD_NAME : '';
    if (!$cloudName) { http_response_code(500); die('Cloudinary not configured.'); }

    $ext       = strtolower(pathinfo($publicId, PATHINFO_EXTENSION));
    $videoExts = ['mp4', 'mov', 'webm', 'avi', 'mkv', 'ogg'];
    $rawExts   = ['zip', 'rar', '7z', 'tar', 'gz', 'csv', 'xml', 'json'];

    if (in_array($ext, $videoExts, true))   $cdnType = 'video';
    elseif (in_array($ext, $rawExts, true)) $cdnType = 'raw';
    else                                    $cdnType = 'image';

    $sourceUrl = "https://res.cloudinary.com/{$cloudName}/{$cdnType}/upload/{$publicId}";
} else {
    header('Location: ' . $externalUrl);
    exit;
}

// ── 6. Detect MIME type ───────────────────────────────────────────────────────

$mimeMap = [
    'pdf'  => 'application/pdf',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'mp4'  => 'video/mp4',
    'webm' => 'video/webm',
    'mov'  => 'video/quicktime',
    'ogg'  => 'video/ogg',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt'  => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
];

$mime = $mimeMap[$ext] ?? null;
if (!$mime) {
    $typeFromDb = strtolower($resource['resource_type'] ?? '');
    $mime = match($typeFromDb) {
        'pdf'   => 'application/pdf',
        'video' => 'video/mp4',
        'image' => 'image/jpeg',
        default => 'application/octet-stream',
    };
}

// ── 7. Proxy the file through PHP ────────────────────────────────────────────

$safeTitle = preg_replace('/[^a-zA-Z0-9._\- ]/', '_', $title);
$fileExt   = $ext ?: 'bin';

if ($action === 'download') {
    header('Content-Disposition: attachment; filename="' . $safeTitle . '.' . $fileExt . '"');
} else {
    header('Content-Disposition: inline; filename="' . $safeTitle . '.' . $fileExt . '"');
}

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');

$ch = curl_init($sourceUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT      => 'PREPAURA-Server/1.0',
    CURLOPT_WRITEFUNCTION  => function($ch, $data) {
        echo $data;
        return strlen($data);
    },
]);

$ok       = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$ok || $httpCode !== 200) {
    error_log("serve-admin-resource: Cloudinary fetch failed. HTTP {$httpCode} for {$sourceUrl}");
}
exit;

function _adminRootUrl(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', dirname(dirname(dirname($_SERVER['SCRIPT_NAME']))));
    return $scheme . '://' . $host . rtrim($script, '/') . '/';
}

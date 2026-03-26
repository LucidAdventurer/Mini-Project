<?php
/* ========================================
 * ADMIN RESOURCE SERVE
 * File: api/admin/serve-admin-resource.php
 * ======================================== */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

// ── 1. Auth ──────────────────────────────────────────────────────────────────

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

// ── 5. Resolve source ────────────────────────────────────────────────────────

$publicId    = $resource['cloudinary_public_id'] ?? '';
$externalUrl = $resource['external_url']         ?? '';
$title       = $resource['title']                ?? 'resource';

if (!$publicId && !$externalUrl) {
    http_response_code(404); die('No file attached to this resource.');
}

// ── Case 1: External URL only (no Cloudinary) ────────────────────────────────
if (!$publicId && $externalUrl) {
    header('Location: ' . $externalUrl);
    exit;
}

// ── Case 2: Cloudinary file — use signed URL (same as teacher side) ──────────

$cloudName = !empty(CLOUDINARY_CLOUD_NAME) ? CLOUDINARY_CLOUD_NAME : '';
$apiKey    = !empty(CLOUDINARY_API_KEY)    ? CLOUDINARY_API_KEY    : '';
$apiSecret = !empty(CLOUDINARY_API_SECRET) ? CLOUDINARY_API_SECRET : '';

if (!$cloudName) {
    http_response_code(500); die('Cloudinary not configured.');
}

// Determine resource type — admin resources are uploaded as 'image' type even
// for PDFs (that's what the Cloudinary dashboard shows for this account).
// Fall back to resource_type column when no extension in public_id.
$ext       = strtolower(pathinfo($publicId, PATHINFO_EXTENSION));
$videoExts = ['mp4', 'mov', 'webm', 'avi', 'mkv', 'ogg'];
$imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

if (in_array($ext, $videoExts, true)) {
    $cdnType = 'video';
} elseif (in_array($ext, $imageExts, true)) {
    $cdnType = 'image';
} else {
    // PDFs and docs — admin upload script stores them under 'image' type
    $cdnType = 'image';
}

// No extension fallback
if (!$ext) {
    $typeFromDb = strtolower($resource['resource_type'] ?? '');
    $cdnType = match($typeFromDb) {
        'video'           => 'video',
        'pdf', 'document' => 'image', // stored as image type on this account
        default           => 'image',
    };
    if ($typeFromDb === 'pdf') $ext = 'pdf';
}

// ── Signed URL (same logic as serve-resource.php teacher side) ───────────────
if (!empty($apiKey) && !empty($apiSecret)) {
    $timestamp = time();
    $expiresAt = $timestamp + 300;

    $sigString = "attachment=" . ($action === 'download' ? 'true' : 'false')
               . "&expires_at={$expiresAt}"
               . "&public_id={$publicId}"
               . "&timestamp={$timestamp}"
               . "&type=upload"
               . $apiSecret;
    $signature = sha1($sigString);

    $deliveryUrl = "https://api.cloudinary.com/v1_1/{$cloudName}/{$cdnType}/download?"
        . http_build_query([
            'attachment' => $action === 'download' ? 'true' : 'false',
            'expires_at' => $expiresAt,
            'public_id'  => $publicId,
            'timestamp'  => $timestamp,
            'type'       => 'upload',
            'api_key'    => $apiKey,
            'signature'  => $signature,
        ]);

    header('Location: ' . $deliveryUrl);
    exit;
}

// ── No credentials fallback ───────────────────────────────────────────────────
http_response_code(503);
die('Cloudinary API credentials not configured. Add CLOUDINARY_API_KEY and CLOUDINARY_API_SECRET to env.php.');

function _adminRootUrl(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', dirname(dirname(dirname($_SERVER['SCRIPT_NAME']))));
    return $scheme . '://' . $host . rtrim($script, '/') . '/';
}
<?php
// ============================================================
// api/resources/serve-resource.php
//
// Proxies a Cloudinary file through the server, or redirects
// to an external URL for link/article types.
// Works for logged-in users AND guests (public materials only).
//
// GET ?material_id=int&action=view|download
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

// ── Optional session: guests get null ────────────────────────────────────
$currentUser = optionalSession($conn);
$userId      = $currentUser ? (int)$currentUser['user_id'] : null;
$role        = $currentUser ? $currentUser['user_type']    : 'guest';
$isGuest     = $userId === null;

$materialId = (int)($_GET['material_id'] ?? 0);
$action     = $_GET['action'] ?? 'view';
$action     = in_array($action, ['view', 'download'], true) ? $action : 'view';

if ($materialId <= 0) {
    http_response_code(400);
    echo 'Invalid material ID.';
    exit;
}

// ── Fetch material ────────────────────────────────────────────────────────
// Columns that actually exist in the materials table.
// material_type is derived below from cloudinary_public_id / external_url.
$r = safePreparedQuery($conn,
    "SELECT material_id, title, cloudinary_public_id, external_url, visibility
     FROM materials WHERE material_id = ?",
    'i', [$materialId]
);

if (!$r['success'] || !$r['result'] || $r['result']->num_rows === 0) {
    http_response_code(404);
    echo 'Material not found.';
    exit;
}

$material = $r['result']->fetch_assoc();
$r['result']->free();

// ── Access control ────────────────────────────────────────────────────────
// Guests may only access public materials.
// Logged-in students follow the same rule for now (group-targeted
// materials are enforced in get-resources; serving is public-or-authed).
if ($isGuest && $material['visibility'] !== 'public') {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

// Derive type — no material_type column; infer from stored data
$hasCloudinary = !empty($material['cloudinary_public_id']);
$hasUrl        = !empty($material['external_url']);

// External link — redirect directly, no proxying needed
if (!$hasCloudinary && $hasUrl) {
    header('Location: ' . $material['external_url']);
    exit;
}

if (!$hasCloudinary) {
    http_response_code(404);
    echo 'File not found in storage.';
    exit;
}

// ── Determine Cloudinary resource_type from public_id prefix convention ──
// Cloudinary public_ids for video are typically stored under a video/ folder
// or with a resource_type prefix. Fall back to 'raw' for PDFs/documents.
$publicId     = $material['cloudinary_public_id'];
$resourceType = (strpos($publicId, 'video/') === 0) ? 'video' : 'raw';

$attachment = $action === 'download' ? 'true' : 'false';
$timestamp  = time();
$expiresAt  = $timestamp + 300; // 5-minute signed URL

// ── Cloudinary signed download URL ───────────────────────────────────────
$sigString = "attachment={$attachment}&expires_at={$expiresAt}&public_id={$publicId}&timestamp={$timestamp}&type=upload"
           . CLOUDINARY_API_SECRET;
$signature = sha1($sigString);

$apiUrl = 'https://api.cloudinary.com/v1_1/' . CLOUDINARY_CLOUD_NAME . "/{$resourceType}/download";

$postFields = http_build_query([
    'attachment' => $attachment,
    'expires_at' => $expiresAt,
    'public_id'  => $publicId,
    'timestamp'  => $timestamp,
    'type'       => 'upload',
    'api_key'    => CLOUDINARY_API_KEY,
    'signature'  => $signature,
]);

$cloudinaryHeaders = [];
$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postFields,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HEADERFUNCTION => function ($curl, $header) use (&$cloudinaryHeaders) {
        $parts = explode(':', $header, 2);
        if (count($parts) === 2) {
            $cloudinaryHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        return strlen($header);
    },
]);
$fileData = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    error_log("serve-resource.php Cloudinary API failed: HTTP {$httpCode}, body: " . substr((string)$fileData, 0, 300));
    http_response_code(502);
    echo 'Failed to fetch file. Please try again.';
    exit;
}

// ── Determine MIME type ───────────────────────────────────────────────────
$ext = strtolower(pathinfo($publicId, PATHINFO_EXTENSION));
$mimeByExt = [
    'pdf'  => 'application/pdf',
    'mp4'  => 'video/mp4',
    'webm' => 'video/webm',
    'mov'  => 'video/quicktime',
];
$mimeType = $mimeByExt[$ext]
    ?? ($cloudinaryHeaders['content-type'] ?? 'application/octet-stream');

// ── Safe filename ─────────────────────────────────────────────────────────
$safeName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $material['title']);
if ($ext) {
    $safeName .= '.' . $ext;
}

// ── Stream to browser ─────────────────────────────────────────────────────
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . strlen($fileData));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($action === 'download') {
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
} else {
    header('Content-Disposition: inline; filename="' . $safeName . '"');
}

echo $fileData;
exit;

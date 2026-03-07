<?php
// ============================================================
// api/resources/serve-resource.php
//
// Proxies a Cloudinary file through the server.
// Cloudinary /download endpoint returns raw file bytes directly.
//
// GET ?material_id=int&action=view|download
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

$currentUser = validateSession($conn);
$userId      = (int) $currentUser['user_id'];
$role        = $currentUser['user_type'];

$materialId = (int)($_GET['material_id'] ?? 0);
$action     = $_GET['action'] ?? 'view';
$action     = in_array($action, ['view', 'download'], true) ? $action : 'view';

if ($materialId <= 0) {
    http_response_code(400);
    echo "Invalid material ID.";
    exit;
}

// ── Fetch material ──
$r = safePreparedQuery($conn,
    "SELECT material_id, title, material_type, external_url, cloudinary_public_id, is_public
     FROM training_materials WHERE material_id = ?",
    "i", [$materialId]
);

if (!$r['success'] || !$r['result'] || $r['result']->num_rows === 0) {
    http_response_code(404);
    echo "Material not found.";
    exit;
}

$material = $r['result']->fetch_assoc();
$r['result']->free();

// ── Access control ──
if ($role === 'student' && !$material['is_public']) {
    http_response_code(403);
    echo "Access denied.";
    exit;
}

$fileTypes = ['pdf', 'video', 'quiz'];

// link/article — redirect directly
if (!in_array($material['material_type'], $fileTypes, true)) {
    header('Location: ' . $material['external_url']);
    exit;
}

if (empty($material['cloudinary_public_id'])) {
    http_response_code(404);
    echo "File not found in storage.";
    exit;
}

$resourceType = $material['material_type'] === 'video' ? 'video' : 'raw';
$publicId     = $material['cloudinary_public_id'];
$attachment   = $action === 'download' ? 'true' : 'false';
$timestamp    = time();
$expiresAt    = $timestamp + 300; // 5 min

// ── Signature exactly as Cloudinary expects ──
$sigString = "attachment={$attachment}&expires_at={$expiresAt}&public_id={$publicId}&timestamp={$timestamp}&type=upload"
           . CLOUDINARY_API_SECRET;
$signature = sha1($sigString);

$apiUrl = "https://api.cloudinary.com/v1_1/" . CLOUDINARY_CLOUD_NAME . "/{$resourceType}/download";

$postFields = http_build_query([
    'attachment' => $attachment,
    'expires_at' => $expiresAt,
    'public_id'  => $publicId,
    'timestamp'  => $timestamp,
    'type'       => 'upload',
    'api_key'    => CLOUDINARY_API_KEY,
    'signature'  => $signature,
]);

// Capture response headers from Cloudinary
$cloudinaryHeaders = [];
$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postFields,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$cloudinaryHeaders) {
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
    error_log("serve-resource.php Admin API failed: HTTP {$httpCode}, response: " . substr($fileData, 0, 300));
    http_response_code(502);
    echo "Failed to fetch file. Please try again.";
    exit;
}

// ── Track view/download ──
try {
    $col  = $action === 'download' ? 'downloads' : 'views';
    $stmt = $conn->prepare("UPDATE training_materials SET {$col} = {$col} + 1 WHERE material_id = ?");
    if ($stmt) { $stmt->bind_param("i", $materialId); $stmt->execute(); $stmt->close(); }
} catch (Exception $e) {
    error_log("serve-resource.php tracking failed: " . $e->getMessage());
}

// ── MIME type ──
$mimeMap = [
    'pdf'   => 'application/pdf',
    'video' => $cloudinaryHeaders['content-type'] ?? 'video/mp4',
    'quiz'  => 'application/pdf',
];
$mimeType = $mimeMap[$material['material_type']] ?? ($cloudinaryHeaders['content-type'] ?? 'application/octet-stream');

// ── Safe filename ──
$safeName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $material['title']);
$ext      = pathinfo(parse_url($material['external_url'], PHP_URL_PATH), PATHINFO_EXTENSION);
if ($ext) $safeName .= '.' . $ext;

// ── Stream to browser ──
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
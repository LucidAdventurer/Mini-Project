<?php
// ============================================================
// api/resources/serve-resource.php
//
// Serves a locally-stored file or redirects to an external URL.
// Works for logged-in users AND guests (public materials only).
//
// GET ?material_id=int&action=view|download
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

// ── Optional session: resolve from session, guests get null ──────────────
$sessionUid  = (int)($_SESSION['user_id'] ?? 0);
$sessionRole = $_SESSION['role'] ?? $_SESSION['user_type'] ?? '';
$currentUser = ($sessionUid > 0) ? getUserData($conn, $sessionUid) : null;
$userId      = $currentUser ? (int)$currentUser['user_id'] : null;
$role        = $currentUser ? ($currentUser['role'] ?? $sessionRole) : 'guest';
$isGuest     = $userId === null;

$materialId = (int)($_GET['material_id'] ?? 0);
$action     = $_GET['action'] ?? 'view';
$action     = in_array($action, ['view', 'download'], true) ? $action : 'view';

// ── When a logged-in student views a resource, dismiss its notification ───
if ($userId && $materialId > 0 && $role === 'student') {
    safePreparedQuery($conn,
        "DELETE FROM notifications WHERE user_id = ? AND type = 'material' AND related_entity_id = ?",
        "ii", [$userId, $materialId]
    );
}

if ($materialId <= 0) {
    http_response_code(400);
    echo 'Invalid material ID.';
    exit;
}

// ── Fetch material ────────────────────────────────────────────────────────
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
if ($isGuest && $material['visibility'] !== 'public') {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}
if ($role === 'student' && $material['visibility'] === 'private') {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$externalUrl       = $material['external_url']        ?? '';
$cloudinaryPublicId = $material['cloudinary_public_id'] ?? '';

// ── Case 1: External / local URL stored in external_url ──────────────────
if ($externalUrl !== '') {
    // Local file saved by upload-resource.php (e.g. uploads/materials/abc.pdf)
    $localPath = __DIR__ . '/../../' . $externalUrl;

    if (file_exists($localPath)) {
        // Stream local file to browser
        $ext = strtolower(pathinfo($localPath, PATHINFO_EXTENSION));
        $mimeMap = [
            'pdf'  => 'application/pdf',
            'mp4'  => 'video/mp4',
            'webm' => 'video/webm',
            'ogg'  => 'video/ogg',
            'mov'  => 'video/quicktime',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'doc'  => 'application/msword',
        ];
        $mimeType = $mimeMap[$ext] ?? mime_content_type($localPath) ?? 'application/octet-stream';
        $safeName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $material['title']) . '.' . $ext;

        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($localPath));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        if ($action === 'download') {
            header('Content-Disposition: attachment; filename="' . $safeName . '"');
        } else {
            header('Content-Disposition: inline; filename="' . $safeName . '"');
        }

        readfile($localPath);
        exit;
    }

    // External URL (YouTube, Google Drive, etc.) — redirect
    if (filter_var($externalUrl, FILTER_VALIDATE_URL)) {
        header('Location: ' . $externalUrl);
        exit;
    }
}

// ── Case 2: Cloudinary-stored file ───────────────────────────────────────
if ($cloudinaryPublicId !== '') {
    // Only attempt if Cloudinary constants are defined
    if (!defined('CLOUDINARY_CLOUD_NAME') || !defined('CLOUDINARY_API_KEY') || !defined('CLOUDINARY_API_SECRET')) {
        http_response_code(501);
        echo 'Cloudinary is not configured on this server.';
        exit;
    }

    $resourceType = (strpos($cloudinaryPublicId, 'video/') === 0) ? 'video' : 'raw';
    $attachment   = $action === 'download' ? 'true' : 'false';
    $timestamp    = time();
    $expiresAt    = $timestamp + 300;

    $sigString = "attachment={$attachment}&expires_at={$expiresAt}&public_id={$cloudinaryPublicId}&timestamp={$timestamp}&type=upload"
               . CLOUDINARY_API_SECRET;
    $signature = sha1($sigString);

    $apiUrl     = 'https://api.cloudinary.com/v1_1/' . CLOUDINARY_CLOUD_NAME . "/{$resourceType}/download";
    $postFields = http_build_query([
        'attachment' => $attachment,
        'expires_at' => $expiresAt,
        'public_id'  => $cloudinaryPublicId,
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
        http_response_code(502);
        echo 'Failed to fetch file from Cloudinary.';
        exit;
    }

    $ext      = strtolower(pathinfo($cloudinaryPublicId, PATHINFO_EXTENSION));
    $mimeType = ['pdf'=>'application/pdf','mp4'=>'video/mp4','webm'=>'video/webm'][$ext]
                ?? ($cloudinaryHeaders['content-type'] ?? 'application/octet-stream');
    $safeName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $material['title']) . ($ext ? '.'.$ext : '');

    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . strlen($fileData));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    header($action === 'download'
        ? 'Content-Disposition: attachment; filename="' . $safeName . '"'
        : 'Content-Disposition: inline; filename="' . $safeName . '"');

    echo $fileData;
    exit;
}

http_response_code(404);
echo 'No file associated with this resource.';

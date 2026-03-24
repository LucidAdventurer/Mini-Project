<?php
// ============================================================
// api/resources/serve-resource.php
//
// Serves a Cloudinary-stored file or redirects to an external URL.
// Works for logged-in users AND guests (public materials only).
//
// GET ?material_id=int&action=view|download
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

// ── Optional session ──────────────────────────────────────────────────────
$sessionUid  = (int)($_SESSION['user_id'] ?? 0);
$sessionRole = $_SESSION['role'] ?? '';
$userId      = $sessionUid > 0 ? $sessionUid : null;
$role        = $sessionUid > 0 ? $sessionRole : 'guest';
$isGuest     = $userId === null;

$materialId = (int)($_GET['material_id'] ?? 0);
$action     = in_array($_GET['action'] ?? '', ['view', 'download'], true) ? $_GET['action'] : 'view';

if ($materialId <= 0) {
    http_response_code(400);
    echo 'Invalid material ID.';
    exit;
}

// ── Dismiss notification for students ────────────────────────────────────
if ($userId && $role === 'student') {
    safePreparedQuery($conn,
        "DELETE FROM notifications WHERE user_id = ? AND type = 'material' AND related_entity_id = ?",
        "ii", [$userId, $materialId]
    );
}

// ── Fetch material ────────────────────────────────────────────────────────
$r = safePreparedQuery($conn,
    "SELECT material_id, title, cloudinary_public_id, external_url,
            (visibility = 'public') AS is_public
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
if ($isGuest && !$material['is_public']) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}
if ($role === 'student' && !$material['is_public']) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$externalUrl        = $material['external_url']         ?? '';
$cloudinaryPublicId = $material['cloudinary_public_id'] ?? '';

// ── Case 1: External URL (link-type resource) ─────────────────────────────
if ($externalUrl !== '') {
    // Check if it's a legacy local file path
    $localPath = __DIR__ . '/../../' . $externalUrl;
    if (file_exists($localPath)) {
        $ext      = strtolower(pathinfo($localPath, PATHINFO_EXTENSION));
        $mimeMap  = [
            'pdf'  => 'application/pdf',
            'mp4'  => 'video/mp4', 'webm' => 'video/webm',
            'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png'  => 'image/png',  'gif'  => 'image/gif',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'doc'  => 'application/msword',
        ];
        $mimeType = $mimeMap[$ext] ?? mime_content_type($localPath) ?? 'application/octet-stream';
        $safeName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $material['title']) . '.' . $ext;

        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($localPath));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        header($action === 'download'
            ? 'Content-Disposition: attachment; filename="' . $safeName . '"'
            : 'Content-Disposition: inline; filename="' . $safeName . '"');
        readfile($localPath);
        exit;
    }

    // External URL — redirect directly
    if (filter_var($externalUrl, FILTER_VALIDATE_URL)) {
        header('Location: ' . $externalUrl);
        exit;
    }
}

// ── Case 2: Cloudinary-stored file ───────────────────────────────────────
if ($cloudinaryPublicId !== '') {
    $cloudName = defined('CLOUDINARY_CLOUD_NAME') ? CLOUDINARY_CLOUD_NAME : 'dmysg5azm';

    // Detect resource type from public_id prefix set by upload-resource.php
    // Cloudinary stores images under image/, videos under video/, raw under raw/
    // Our upload stores them under 'materials/' folder
    $ext = strtolower(pathinfo($cloudinaryPublicId, PATHINFO_EXTENSION));

    $videoExts = ['mp4', 'webm', 'ogg', 'mov', 'avi'];
    $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (in_array($ext, $videoExts, true)) {
        $resourceType = 'video';
    } elseif (in_array($ext, $imageExts, true)) {
        $resourceType = 'image';
    } else {
        $resourceType = 'raw';  // PDF, DOCX, etc.
    }

    // ── Signed URL (works without proxying, avoids free-tier block) ───────
    // Requires CLOUDINARY_API_KEY + CLOUDINARY_API_SECRET in config.php
    if (defined('CLOUDINARY_API_KEY') && defined('CLOUDINARY_API_SECRET')) {
        $timestamp = time();
        $expiresAt = $timestamp + 300;

        $sigString = "attachment=" . ($action === 'download' ? 'true' : 'false')
                   . "&expires_at={$expiresAt}"
                   . "&public_id={$cloudinaryPublicId}"
                   . "&timestamp={$timestamp}"
                   . "&type=upload"
                   . CLOUDINARY_API_SECRET;
        $signature = sha1($sigString);

        $deliveryUrl = "https://api.cloudinary.com/v1_1/{$cloudName}/{$resourceType}/download?"
            . http_build_query([
                'attachment' => $action === 'download' ? 'true' : 'false',
                'expires_at' => $expiresAt,
                'public_id'  => $cloudinaryPublicId,
                'timestamp'  => $timestamp,
                'type'       => 'upload',
                'api_key'    => CLOUDINARY_API_KEY,
                'signature'  => $signature,
            ]);

        header('Location: ' . $deliveryUrl);
        exit;
    }

    // ── Fallback: unsigned delivery URL ───────────────────────────────────
    // Works for images and videos on free tier.
    // Raw files (PDF) are blocked by Cloudinary on free tier without signing —
    // in that case, add CLOUDINARY_API_KEY and CLOUDINARY_API_SECRET to config.php.
    if ($resourceType === 'image' || $resourceType === 'video') {
        $deliveryUrl = "https://res.cloudinary.com/{$cloudName}/{$resourceType}/upload/{$cloudinaryPublicId}";
        header('Location: ' . $deliveryUrl);
        exit;
    }

    // Raw file (PDF/DOCX) with no API credentials — proxy via curl
    $fetchUrl = "https://res.cloudinary.com/{$cloudName}/raw/upload/{$cloudinaryPublicId}";
    $ch = curl_init($fetchUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $fileData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $fileData) {
        $mimeMap  = ['pdf' => 'application/pdf', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'doc' => 'application/msword'];
        $mimeType = $mimeMap[$ext] ?? 'application/octet-stream';
        $safeName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $material['title']) . ($ext ? '.' . $ext : '');

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

    http_response_code(502);
    echo 'Could not retrieve file from Cloudinary. Add CLOUDINARY_API_KEY and CLOUDINARY_API_SECRET to config.php for reliable PDF delivery.';
    exit;
}

http_response_code(404);
echo 'No file associated with this resource.';
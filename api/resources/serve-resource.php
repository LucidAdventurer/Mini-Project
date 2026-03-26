<?php
// ============================================================
// api/resources/serve-resource.php
//
// Serves a Cloudinary-stored file or redirects to an external URL.
// Works for logged-in users AND guests (public items only).
//
// Handles two sources:
//   ?material_id=int  — materials table (teacher/admin learning materials)
//   ?resource_id=int  — resources table (public resource library)
//
// GET ?material_id=int&action=view|download
// GET ?resource_id=int&action=view|download
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

// ── Optional session ──────────────────────────────────────────────────────
$sessionUid  = (int)($_SESSION['user_id'] ?? 0);
$sessionRole = $_SESSION['role'] ?? '';
$userId      = $sessionUid > 0 ? $sessionUid : null;
$role        = $sessionUid > 0 ? $sessionRole : 'guest';
$isGuest     = $userId === null;

$action = in_array($_GET['action'] ?? '', ['view', 'download'], true) ? $_GET['action'] : 'view';

// ── Determine source: resources table or materials table ──────────────────
$resourceId = (int)($_GET['resource_id'] ?? 0);
$materialId = (int)($_GET['material_id'] ?? 0);

if ($resourceId <= 0 && $materialId <= 0) {
    http_response_code(400);
    echo 'Invalid resource or material ID.';
    exit;
}

// ── Fetch from the correct table ──────────────────────────────────────────
if ($resourceId > 0) {
    $r = safePreparedQuery($conn,
        "SELECT resource_id AS id, title, cloudinary_public_id, external_url,
                file_path, is_public, resource_type
         FROM resources WHERE resource_id = ?",
        'i', [$resourceId]
    );

    if (!$r['success'] || !$r['result'] || $r['result']->num_rows === 0) {
        http_response_code(404);
        echo 'Resource not found.';
        exit;
    }

    $item = $r['result']->fetch_assoc();
    $r['result']->free();

    if (!$item['is_public'] && ($isGuest || $role === 'student')) {
        http_response_code(403);
        echo 'Access denied.';
        exit;
    }

    $title              = $item['title'];
    $externalUrl        = $item['external_url']         ?? '';
    $cloudinaryPublicId = $item['cloudinary_public_id'] ?? '';
    $filePath           = $item['file_path']            ?? '';
    $resourceTypeDb     = strtolower($item['resource_type'] ?? '');

} else {
    if ($userId && $role === 'student') {
        safePreparedQuery($conn,
            "DELETE FROM notifications WHERE user_id = ? AND type = 'material' AND related_entity_id = ?",
            "ii", [$userId, $materialId]
        );
    }

    $r = safePreparedQuery($conn,
        "SELECT material_id AS id, title, cloudinary_public_id, external_url,
                NULL AS file_path,
                visibility, NULL AS resource_type
         FROM materials WHERE material_id = ?",
        'i', [$materialId]
    );

    if (!$r['success'] || !$r['result'] || $r['result']->num_rows === 0) {
        http_response_code(404);
        echo 'Material not found.';
        exit;
    }

    $item = $r['result']->fetch_assoc();
    $r['result']->free();

    $vis = $item['visibility'] ?? 'private';

    if ($isGuest && $vis !== 'public') {
        http_response_code(403);
        echo 'Access denied.';
        exit;
    }

    if ($role === 'student' && $vis !== 'public') {
        if ($vis === 'group') {
            $access = safePreparedQuery($conn,
                "SELECT 1 FROM material_targets mt
                 JOIN group_members gm ON gm.group_id = mt.target_id
                 WHERE mt.material_id = ? AND mt.target_type = 'group' AND gm.student_id = ?
                 UNION
                 SELECT 1 FROM material_targets mt
                 WHERE mt.material_id = ? AND mt.target_type = 'student' AND mt.target_id = ?
                 LIMIT 1",
                'iiii', [$materialId, $userId, $materialId, $userId]
            );
            $hasAccess = $access['success'] && $access['result'] && $access['result']->num_rows > 0;
            if ($access['result']) $access['result']->free();
            if (!$hasAccess) {
                http_response_code(403);
                echo 'Access denied.';
                exit;
            }
        } else {
            http_response_code(403);
            echo 'Access denied.';
            exit;
        }
    }

    $title              = $item['title'];
    $externalUrl        = $item['external_url']         ?? '';
    $cloudinaryPublicId = $item['cloudinary_public_id'] ?? '';
    $filePath           = '';
    $resourceTypeDb     = '';
}

// ── Case 1: Local file ────────────────────────────────────────────────────
if ($filePath !== '') {
    $localPath = __DIR__ . '/../../' . $filePath;
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
        $safeName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $title) . '.' . $ext;

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
}

// ── Case 2: Cloudinary file ───────────────────────────────────────────────
if ($cloudinaryPublicId !== '') {
    $cloudName = !empty(CLOUDINARY_CLOUD_NAME) ? CLOUDINARY_CLOUD_NAME : 'dmysg5azm';
    $apiKey    = !empty(CLOUDINARY_API_KEY)    ? CLOUDINARY_API_KEY    : '';
    $apiSecret = !empty(CLOUDINARY_API_SECRET) ? CLOUDINARY_API_SECRET : '';

    $ext       = strtolower(pathinfo($cloudinaryPublicId, PATHINFO_EXTENSION));
    $videoExts = ['mp4', 'webm', 'ogg', 'mov', 'avi'];
    $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (in_array($ext, $videoExts, true)) {
        $resourceType = 'video';
    } elseif (in_array($ext, $imageExts, true)) {
        $resourceType = 'image';
    } else {
        // resources table = admin uploads (stored as image)
        // materials table = teacher uploads (stored as raw)
        $resourceType = ($resourceId > 0) ? 'image' : 'raw';
    }

    // No extension — fall back to resource_type column
    if (!$ext) {
        $resourceType = match($resourceTypeDb) {
            'video'           => 'video',
            'pdf', 'document' => ($resourceId > 0) ? 'image' : 'raw',
            default           => ($resourceId > 0) ? 'image' : 'raw',
        };
        if ($resourceTypeDb === 'pdf') $ext = 'pdf';
    }

    // ── Signed URL ────────────────────────────────────────────────────────
    if (!empty($apiKey) && !empty($apiSecret)) {
        $timestamp = time();
        $expiresAt = $timestamp + 300;

        $sigString = "attachment=" . ($action === 'download' ? 'true' : 'false')
                   . "&expires_at={$expiresAt}"
                   . "&public_id={$cloudinaryPublicId}"
                   . "&timestamp={$timestamp}"
                   . "&type=upload"
                   . $apiSecret;
        $signature = sha1($sigString);

        $deliveryUrl = "https://api.cloudinary.com/v1_1/{$cloudName}/{$resourceType}/download?"
            . http_build_query([
                'attachment' => $action === 'download' ? 'true' : 'false',
                'expires_at' => $expiresAt,
                'public_id'  => $cloudinaryPublicId,
                'timestamp'  => $timestamp,
                'type'       => 'upload',
                'api_key'    => $apiKey,
                'signature'  => $signature,
            ]);

        header('Location: ' . $deliveryUrl);
        exit;
    }

    // ── No credentials: unsigned (images/video only) ──────────────────────
    if ($resourceType === 'image' || $resourceType === 'video') {
        $deliveryUrl = "https://res.cloudinary.com/{$cloudName}/{$resourceType}/upload/{$cloudinaryPublicId}";
        header('Location: ' . $deliveryUrl);
        exit;
    }

    // ── No credentials + raw: proxy attempt ───────────────────────────────
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
        $mimeMap  = [
            'pdf'  => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'doc'  => 'application/msword',
        ];
        $mimeType = $mimeMap[$ext] ?? 'application/octet-stream';
        $safeName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $title) . ($ext ? '.' . $ext : '');

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

    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
          <style>body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f8fafc;}
          .box{text-align:center;padding:40px;max-width:420px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;}
          h2{color:#0f172a;margin-bottom:8px;}p{color:#64748b;margin-bottom:20px;}
          a{display:inline-block;padding:10px 20px;background:#0d9488;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;}
          a:hover{background:#0f766e;}</style></head>
          <body><div class="box">
          <h2>📄 PDF unavailable</h2>
          <p>This file requires Cloudinary API credentials to serve.<br>
          Add <code>CLOUDINARY_API_KEY</code> and <code>CLOUDINARY_API_SECRET</code> to <code>env.php</code>.</p>
          <a href="javascript:history.back()">← Go back</a>
          </div></body></html>';
    exit;
}

// ── Case 3: External URL ──────────────────────────────────────────────────
if ($externalUrl !== '') {
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
        $safeName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $title) . '.' . $ext;

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

    if (filter_var($externalUrl, FILTER_VALIDATE_URL)) {
        header('Location: ' . $externalUrl);
        exit;
    }
}

http_response_code(404);
echo 'No file associated with this resource.';
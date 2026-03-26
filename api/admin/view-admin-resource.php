<?php
/* ========================================
 * ADMIN RESOURCE VIEWER PAGE
 * File: api/admin/view-admin-resource.php
 *
 * Renders an inline viewer page (iframe for PDF/docs, video tag for video,
 * img tag for images). Embeds serve-admin-resource.php as the src so the
 * browser receives a properly typed byte stream — same pattern as the
 * teacher-side view-resource.php / serve-resource.php pair.
 * ======================================== */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

// ── Auth (manual — same reason as serve-admin-resource.php) ─────────────────

$uid  = getSessionUserId();
$role = getSessionRole();

if ($uid === 0 || $role === '') {
    header('Location: ../../index.html?error=session_expired');
    exit;
}
if ($role !== 'admin') {
    header('Location: ../../index.html?error=unauthorized');
    exit;
}
$adminRow = getUserData($conn, $uid);
if (!$adminRow || !$adminRow['is_active']) {
    session_destroy();
    header('Location: ../../index.html?error=session_expired');
    exit;
}

// ── Input ────────────────────────────────────────────────────────────────────

$resourceId = filter_input(INPUT_GET, 'resource_id', FILTER_VALIDATE_INT);

if (!$resourceId || $resourceId <= 0) {
    http_response_code(400);
    die('Invalid resource ID.');
}

// ── Fetch resource ───────────────────────────────────────────────────────────

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

$title     = htmlspecialchars($resource['title'] ?? 'Resource', ENT_QUOTES, 'UTF-8');
$publicId  = $resource['cloudinary_public_id'] ?? '';
$ext       = strtolower(pathinfo($publicId, PATHINFO_EXTENSION));

$videoExts = ['mp4', 'mov', 'webm', 'ogg'];
$imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

$isVideo = !empty($publicId) && in_array($ext, $videoExts, true);
$isImage = !empty($publicId) && in_array($ext, $imageExts, true);
$isLink  = empty($publicId) && !empty($resource['external_url']);

// Build serve URLs (relative — same directory)
$serveBase = 'serve-admin-resource.php?resource_id=' . $resourceId;
$serveUrl  = htmlspecialchars($serveBase . '&action=view',     ENT_QUOTES, 'UTF-8');
$dlUrl     = htmlspecialchars($serveBase . '&action=download', ENT_QUOTES, 'UTF-8');

header('X-Frame-Options: SAMEORIGIN');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?> — PREPAURA Admin</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #0f1117;
            color: #e2e8f0;
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 20px;
            background: #1a1d27;
            border-bottom: 1px solid #2d3148;
            flex-shrink: 0;
            gap: 12px;
        }
        .topbar-left {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: #2d3148;
            color: #a0aec0;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            text-decoration: none;
            flex-shrink: 0;
            transition: background 0.15s;
        }
        .back-btn:hover { background: #363b5e; color: #e2e8f0; }

        .title {
            font-size: 15px;
            font-weight: 600;
            color: #e2e8f0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .dl-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            background: #0066ff;
            color: #fff;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            flex-shrink: 0;
            transition: background 0.15s;
        }
        .dl-btn:hover { background: #0052cc; }

        .viewer { flex: 1; overflow: hidden; position: relative; }

        iframe#pdf-frame { width: 100%; height: 100%; border: none; display: block; }

        .center-wrap {
            width: 100%; height: 100%;
            display: flex; align-items: center; justify-content: center;
            background: #000;
        }
        video { max-width: 100%; max-height: 100%; }
        .center-wrap img { max-width: 100%; max-height: 100%; object-fit: contain; }

        .loader-overlay {
            position: absolute; inset: 0;
            background: #0f1117;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            gap: 14px; color: #a0aec0; font-size: 14px;
            pointer-events: none; transition: opacity 0.3s;
        }
        .loader-overlay.hidden { opacity: 0; pointer-events: none; }
        .spinner {
            width: 36px; height: 36px;
            border: 3px solid #2d3148;
            border-top-color: #0066ff;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>

<div class="topbar">
    <div class="topbar-left">
        <button class="back-btn" onclick="window.close()">← Close</button>
        <span class="title"><?= $title ?></span>
    </div>
    <?php if (!$isLink): ?>
    <a href="<?= $dlUrl ?>" class="dl-btn">↓ Download</a>
    <?php endif; ?>
</div>

<div class="viewer">
<?php if ($isLink): ?>
    <?php
        $extUrl = htmlspecialchars($resource['external_url'], ENT_QUOTES, 'UTF-8');
        header('Location: ' . $resource['external_url']);
        exit;
    ?>
<?php elseif ($isVideo): ?>
    <div class="center-wrap">
        <video controls autoplay>
            <source src="<?= $serveUrl ?>" type="video/mp4">
            Your browser does not support the video tag.
        </video>
    </div>
<?php elseif ($isImage): ?>
    <div class="center-wrap">
        <img src="<?= $serveUrl ?>" alt="<?= $title ?>">
    </div>
<?php else: ?>
    <div class="loader-overlay" id="loader">
        <div class="spinner"></div>
        <span>Loading document…</span>
    </div>
    <iframe
        id="pdf-frame"
        src="<?= $serveUrl ?>"
        title="<?= $title ?>"
        onload="document.getElementById('loader').classList.add('hidden')"
    ></iframe>
<?php endif; ?>
</div>

</body>
</html>

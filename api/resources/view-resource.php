<?php
// ============================================================
// api/resources/view-resource.php
//
// Inline PDF/video viewer page.
// Embeds serve-resource.php in an <iframe> or <video> element.
// Works for logged-in users AND guests (public materials only).
//
// GET ?material_id=int
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

// ── Optional session: guests get null ────────────────────────────────────
// Resolve current user from session (guests get null)
$sessionRole = $_SESSION['role'] ?? $_SESSION['user_type'] ?? '';
$sessionUid  = (int)($_SESSION['user_id'] ?? 0);
$currentUser = ($sessionUid > 0) ? getUserData($conn, $sessionUid) : null;
$userId      = $currentUser ? (int)$currentUser['user_id'] : null;
$role        = $currentUser ? ($currentUser['role'] ?? $sessionRole) : 'guest';
$isGuest     = $currentUser === null;

$materialId = (int)($_GET['material_id'] ?? 0);

if ($materialId <= 0) {
    http_response_code(400);
    echo 'Invalid material ID.';
    exit;
}

// ── Fetch material ────────────────────────────────────────────────────────
$r = safePreparedQuery($conn,
    'SELECT material_id, title, cloudinary_public_id, external_url, visibility
     FROM materials WHERE material_id = ?',
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
// Guests may only view public materials.
if ($isGuest && $material['visibility'] !== 'public') {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

// Logged-in students cannot view private materials either.
if ($role === 'student' && $material['visibility'] === 'private') {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$title = htmlspecialchars($material['title'], ENT_QUOTES, 'UTF-8');

// ── Student viewed this resource — dismiss its notification immediately ───
if (!$isGuest && $role === 'student') {
    safePreparedQuery($conn,
        "DELETE FROM notifications WHERE user_id = ? AND related_entity_id = ? AND type IN ('material','resource')",
        "ii", [$userId, $materialId]
    );
}

// Derive type from stored data — no material_type column in schema
$hasCloudinary = !empty($material['cloudinary_public_id']);
$publicId      = $material['cloudinary_public_id'] ?? '';

// Detect video by public_id prefix convention (e.g. stored under video/)
$isVideo = $hasCloudinary && (strpos($publicId, 'video/') === 0);

$serveUrl = htmlspecialchars('serve-resource.php?material_id=' . $materialId . '&action=view',  ENT_QUOTES, 'UTF-8');
$dlUrl    = htmlspecialchars('serve-resource.php?material_id=' . $materialId . '&action=download', ENT_QUOTES, 'UTF-8');

// Prevent this page from being embedded by external origins
header('X-Frame-Options: SAMEORIGIN');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?> — PTA Platform</title>
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

        .video-wrap {
            width: 100%; height: 100%;
            display: flex; align-items: center; justify-content: center;
            background: #000;
        }
        video { max-width: 100%; max-height: 100%; }

        .loader-overlay {
            position: absolute; inset: 0;
            background: #0f1117;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            gap: 14px; color: #a0aec0; font-size: 14px;
            pointer-events: none; transition: opacity 0.3s;
        }
        .loader-overlay.hidden { opacity: 0; }
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
    <a href="<?= $dlUrl ?>" class="dl-btn">↓ Download</a>
</div>

<div class="viewer">
<?php if ($isVideo): ?>
    <div class="video-wrap">
        <video controls autoplay>
            <source src="<?= $serveUrl ?>" type="video/mp4">
            Your browser does not support the video tag.
        </video>
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

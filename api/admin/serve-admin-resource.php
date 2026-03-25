<?php
// ============================================================
// api/admin/serve-admin-resource.php
//
// Serves an admin-uploaded resource by redirecting to the
// correct Cloudinary delivery URL.
//
// The core problem: PDFs uploaded via the unsigned upload preset
// land under /raw/upload/ in Cloudinary. Browsers cannot render
// a /raw/ URL as a PDF — you must use /image/upload/ with the
// .pdf extension (Cloudinary supports PDF delivery under image).
//
// This script fixes the URL on-the-fly:
//   raw/upload/  →  image/upload/  (for view)
//   raw/upload/  →  image/upload/fl_attachment/  (for download)
//
// GET ?resource_id=int&action=view|download
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

$currentUser = validateSession($conn);
$role        = $currentUser['user_type'] ?? $currentUser['role'] ?? '';

$resourceId = (int)($_GET['resource_id'] ?? 0);
$action     = $_GET['action'] ?? 'view';
$action     = in_array($action, ['view', 'download'], true) ? $action : 'view';

if ($resourceId <= 0) {
    http_response_code(400);
    exit('Invalid resource ID.');
}

$r = safePreparedQuery(
    $conn,
    "SELECT resource_id, title, resource_type, external_url, cloudinary_public_id, is_public
     FROM resources WHERE resource_id = ?",
    "i", [$resourceId]
);

if (!$r['success'] || !$r['result'] || $r['result']->num_rows === 0) {
    http_response_code(404);
    exit('Resource not found.');
}

$res = $r['result']->fetch_assoc();
$r['result']->free();

if ($role !== 'admin' && !$res['is_public']) {
    http_response_code(403);
    exit('Access denied.');
}

$url = trim($res['external_url'] ?? '');

if (empty($url)) {
    http_response_code(404);
    exit('No file URL found for this resource.');
}

// Increment counter
$col  = $action === 'download' ? 'downloads' : 'views';
$stmt = $conn->prepare("UPDATE resources SET {$col} = {$col} + 1 WHERE resource_id = ?");
if ($stmt) { $stmt->bind_param('i', $resourceId); $stmt->execute(); $stmt->close(); }

// ── Serve the file ────────────────────────────────────────────────────────
//
// Files uploaded as 'raw' in Cloudinary MUST stay at /raw/upload/ —
// rewriting to /image/upload/ causes a 404. Instead:
//   - download → simple redirect to the raw URL (browser will download it)
//   - view     → render an HTML viewer page that embeds the URL in a
//                <iframe> (PDF) or <video> tag, which the browser handles
//
// For PDFs the browser's built-in PDF viewer loads the raw URL just fine
// inside an iframe — it only fails when navigated to directly in Edge/Chrome
// because those browsers try to use their own PDF renderer at top-level
// and choke on the Cloudinary raw response headers.

if ($action === 'download') {
    // Direct redirect — browser will prompt Save As
    header('Location: ' . $url, true, 302);
    exit;
}

// ── View: render inline viewer page ──────────────────────────────────────
$title       = htmlspecialchars($res['title'] ?? 'Resource', ENT_QUOTES);
$resourceType = strtolower($res['resource_type'] ?? 'pdf');
$isVideo      = ($resourceType === 'video');
$downloadUrl  = htmlspecialchars($url, ENT_QUOTES);
$iframeUrl    = htmlspecialchars($url, ENT_QUOTES);

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $title ?></title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Arial, sans-serif; background: #0f1117; color: #e2e8f0;
         height: 100vh; display: flex; flex-direction: column; overflow: hidden; }
  .topbar { display: flex; align-items: center; justify-content: space-between;
            padding: 10px 20px; background: #1a1d27;
            border-bottom: 1px solid #2d3148; flex-shrink: 0; gap: 12px; }
  .title { font-size: 15px; font-weight: 600; color: #e2e8f0;
           white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1; }
  .dl-btn { display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 14px; background: #0066ff; color: #fff;
            border: none; border-radius: 6px; cursor: pointer;
            font-size: 13px; font-weight: 500; text-decoration: none;
            flex-shrink: 0; transition: background 0.15s; }
  .dl-btn:hover { background: #0052cc; }
  .viewer { flex: 1; overflow: hidden; position: relative; }
  iframe { width: 100%; height: 100%; border: none; display: block; }
  .video-wrap { width: 100%; height: 100%; display: flex;
                align-items: center; justify-content: center; background: #000; }
  video { max-width: 100%; max-height: 100%; }
  /* Google Docs embed fallback */
  .gdocs-btn { position: absolute; inset: 0; display: flex; flex-direction: column;
               align-items: center; justify-content: center; gap: 16px;
               background: #0f1117; text-align: center; padding: 24px; }
  .gdocs-btn p { color: #9ca3af; font-size: 14px; max-width: 400px; line-height: 1.6; }
  .gdocs-btn a { padding: 10px 24px; background: #0066ff; color: white;
                 border-radius: 8px; text-decoration: none; font-weight: 600; }
</style>
</head>
<body>
<div class="topbar">
  <span class="title"><?= $title ?></span>
  <a href="<?= $downloadUrl ?>" class="dl-btn" download>↓ Download</a>
</div>
<div class="viewer">
<?php if ($isVideo): ?>
  <div class="video-wrap">
    <video controls autoplay>
      <source src="<?= $iframeUrl ?>">
      Your browser does not support video playback.
    </video>
  </div>
<?php else: ?>
  <!--
    Embed using Google Docs Viewer as a proxy — this renders the raw
    Cloudinary PDF URL without needing any content-type tricks.
    Falls back to a direct-open button if the viewer is blocked.
  -->
  <iframe
    id="viewer-frame"
    src="https://docs.google.com/viewer?url=<?= urlencode($url) ?>&embedded=true"
    title="<?= $title ?>"
    onload="checkLoaded(this)"
  ></iframe>
  <div class="gdocs-btn" id="fallback" style="display:none;">
    <p>Unable to display preview. Click below to open the PDF directly in a new tab.</p>
    <a href="<?= $iframeUrl ?>" target="_blank" rel="noopener">Open PDF ↗</a>
  </div>
  <script>
    // Google Docs viewer sometimes fails silently — show fallback after 12s
    const t = setTimeout(() => {
      document.getElementById('viewer-frame').style.display = 'none';
      document.getElementById('fallback').style.display = 'flex';
    }, 12000);
    function checkLoaded(frame) {
      try {
        // If the iframe loaded our own fallback page, it'll have a title
        // otherwise it loaded Google Docs successfully — cancel the timer
        clearTimeout(t);
      } catch(e) {}
    }
  </script>
<?php endif; ?>
</div>
</body>
</html>
<?php
exit;
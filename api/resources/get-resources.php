<?php
// ============================================================
// api/resources/get-resources.php
//
// Returns a paginated list of resources from the `resources`
// table. Public resources are visible to everyone (guests
// included). Admin requests also receive non-public drafts
// and aggregate stats.
//
// GET params:
//   category  string  'aptitude'|'verbal'|'logical'|'technical'|'general'|'all'
//   limit     int     1–50  (default 8 for guests, 20 for admin)
//   page      int     ≥1    (default 1)
//
// Returns:
// {
//   success   : bool,
//   materials : [ { resource_id, title, description, category,
//                   material_type, external_url, file_size,
//                   views, downloads, created_at } ],
//   total     : int,
//   // Only present for admin (logged-in admin role):
//   stats     : { total_materials, storage_used_bytes, total_downloads }
// }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Detect admin session (unlocks drafts + stats) ──────────────────────────
$isAdmin = false;
if (!empty($_SESSION['user_id']) && !empty($_SESSION['user_type'])) {
    $isAdmin = ($_SESSION['user_type'] === 'admin');
}

// ── Sanitise query params ──────────────────────────────────────────────────
$validCategories = ['aptitude', 'verbal', 'logical', 'technical', 'general'];
$category = 'all';
if (!empty($_GET['category']) && in_array(strtolower($_GET['category']), $validCategories, true)) {
    $category = strtolower($_GET['category']);
}

$limit = min(50, max(1, (int)($_GET['limit'] ?? 8)));
$page  = max(1, (int)($_GET['page']  ?? 1));
$offset = ($page - 1) * $limit;

// ── Build query ────────────────────────────────────────────────────────────
// Guests only see is_public = 1. Admins see everything.
$publicClause = $isAdmin ? '' : 'AND r.is_public = 1';
$catClause    = ($category !== 'all') ? 'AND r.category = ?' : '';

$sql = "SELECT r.resource_id,
               r.title,
               r.description,
               r.category,
               r.resource_type  AS material_type,
               r.external_url,
               r.file_size,
               r.views,
               r.downloads,
               r.is_public,
               r.created_at
        FROM resources r
        WHERE 1=1
          {$publicClause}
          {$catClause}
        ORDER BY r.created_at DESC
        LIMIT ? OFFSET ?";

// Build types + params dynamically
if ($category !== 'all') {
    $types  = 'sii';
    $params = [$category, $limit, $offset];
} else {
    $types  = 'ii';
    $params = [$limit, $offset];
}

$res = safePreparedQuery($conn, $sql, $types, $params);

if (!$res['success'] || !$res['result']) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to load resources.']);
    exit;
}

$materials = [];
while ($row = $res['result']->fetch_assoc()) {
    $materials[] = $row;
}
$res['result']->free();

// ── Count total rows (for pagination) ─────────────────────────────────────
$countSql = "SELECT COUNT(*) AS total FROM resources r WHERE 1=1 {$publicClause} {$catClause}";
if ($category !== 'all') {
    $cRes = safePreparedQuery($conn, $countSql, 's', [$category]);
} else {
    $cRes = safePreparedQuery($conn, $countSql, '', []);
}
$total = 0;
if ($cRes['success'] && $cRes['result']) {
    $total = (int)($cRes['result']->fetch_assoc()['total'] ?? 0);
    $cRes['result']->free();
}

// ── Build response ─────────────────────────────────────────────────────────
$response = [
    'success'   => true,
    'materials' => $materials,
    'total'     => $total,
];

// Admin-only aggregate stats
if ($isAdmin) {
    $statsRes = safePreparedQuery(
        $conn,
        "SELECT COUNT(*)        AS total_materials,
                COALESCE(SUM(file_size), 0) AS storage_used_bytes,
                COALESCE(SUM(downloads),  0) AS total_downloads
         FROM resources",
        '', []
    );
    if ($statsRes['success'] && $statsRes['result']) {
        $response['stats'] = $statsRes['result']->fetch_assoc();
        $statsRes['result']->free();
    }
}

echo json_encode($response);
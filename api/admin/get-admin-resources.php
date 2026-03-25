<?php
// ============================================================
// api/admin/get-admin-resources.php
//
// Returns resources from the `resources` table for the admin panel.
// Admin sees all resources (public + private).
//
// Live schema — resources table columns:
//   resource_id, title, description,
//   category enum('aptitude','verbal','logical','technical','general'),
//   resource_type enum('pdf','video','link','article','quiz'),
//   file_path, cloudinary_public_id, file_size, external_url,
//   views, downloads, is_public, uploaded_by, created_at, updated_at
//
// GET params:
//   category : string  (optional)
//   search   : string  (optional)
//   page     : int     (default 1)
//   limit    : int     (default 20, max 100)
//
// Response shape mirrors what admin-resources.php expects:
// {
//   success         : true,
//   materials       : [ resource rows aliased for backwards compat ],
//   total           : int,
//   page            : int,
//   pages           : int,
//   stats           : { total_materials, total_views, total_downloads }
// }
// Note: response key is intentionally "materials" so the existing
// renderResources() in admin-resources.php works without JS changes.
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

// ── Auth: admin only ──────────────────────────────────────────────────────
$admin   = validateSession($conn, 'admin');
$adminId = (int)$admin['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Query params ──────────────────────────────────────────────────────────
$page     = max(1, (int)($_GET['page']     ?? 1));
$limit    = min(100, max(1, (int)($_GET['limit'] ?? 20)));
$offset   = ($page - 1) * $limit;
$category = trim($_GET['category'] ?? '');
$search   = trim($_GET['search']   ?? '');

$validCategories = ['aptitude', 'verbal', 'logical', 'technical', 'general'];

// ── WHERE ─────────────────────────────────────────────────────────────────
$conditions = [];
$params     = [];
$types      = '';

if ($category !== '' && in_array($category, $validCategories, true)) {
    $conditions[] = 'r.category = ?';
    $params[]     = $category;
    $types       .= 's';
}

if ($search !== '') {
    $conditions[] = '(r.title LIKE ? OR r.description LIKE ?)';
    $like         = '%' . $search . '%';
    $params[]     = $like;
    $params[]     = $like;
    $types       .= 'ss';
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

// ── Count ─────────────────────────────────────────────────────────────────
$countRes = safePreparedQuery(
    $conn,
    "SELECT COUNT(*) AS total FROM resources r $where",
    $types, $params
);

$totalRows = 0;
if ($countRes['success'] && $countRes['result']) {
    $totalRows = (int)$countRes['result']->fetch_assoc()['total'];
    $countRes['result']->free();
}
$totalPages = max(1, (int)ceil($totalRows / $limit));

// ── Fetch ─────────────────────────────────────────────────────────────────
$fetchTypes  = $types . 'ii';
$fetchParams = array_merge($params, [$limit, $offset]);

$fetchRes = safePreparedQuery(
    $conn,
    "SELECT
         r.resource_id,
         r.resource_id      AS material_id,      -- alias: renderResources() reads material_id
         r.title,
         r.description,
         r.category,
         r.resource_type,
         r.cloudinary_public_id,
         r.external_url,
         r.file_path,
         r.file_size,
         r.views,
         r.downloads,
         r.is_public,
         CASE WHEN r.is_public = 1 THEN 'public' ELSE 'private' END AS visibility,
         r.created_at,
         u.full_name  AS created_by_name,
         u.full_name  AS uploaded_by_name
     FROM resources r
     LEFT JOIN users u ON u.user_id = r.uploaded_by
     $where
     ORDER BY r.created_at DESC
     LIMIT ? OFFSET ?",
    $fetchTypes, $fetchParams
);

$resources = [];
if ($fetchRes['success'] && $fetchRes['result']) {
    while ($row = $fetchRes['result']->fetch_assoc()) {
        $resources[] = $row;
    }
    $fetchRes['result']->free();
}

// ── Stats ─────────────────────────────────────────────────────────────────
$statsRes = safePreparedQuery(
    $conn,
    "SELECT COUNT(*) AS total_materials,
            COALESCE(SUM(views), 0)     AS total_views,
            COALESCE(SUM(downloads), 0) AS total_downloads
     FROM resources",
    '', []
);

$stats = ['total_materials' => 0, 'total_views' => 0, 'total_downloads' => 0];
if ($statsRes['success'] && $statsRes['result']) {
    $sRow  = $statsRes['result']->fetch_assoc();
    $stats = [
        'total_materials'  => (int)$sRow['total_materials'],
        'total_views'      => (int)$sRow['total_views'],
        'total_downloads'  => (int)$sRow['total_downloads'],
    ];
    $statsRes['result']->free();
}

echo json_encode([
    'success'   => true,
    'materials' => $resources,   // key kept as 'materials' for renderResources() compat
    'total'     => $totalRows,
    'page'      => $page,
    'pages'     => $totalPages,
    'stats'     => $stats,
]);
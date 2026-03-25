<?php
// ============================================================
// api/guest/get-guest-resources.php
//
// Returns public resources from the `resources` table for
// unauthenticated (guest) visitors and the guest resources page.
//
// No session required — only is_public = 1 rows are returned.
//
// Live schema — resources table columns:
//   resource_id, title, description, category
//     enum('aptitude','verbal','logical','technical','general'),
//   resource_type enum('pdf','video','link','article','quiz'),
//   file_path, cloudinary_public_id, file_size, external_url,
//   views, downloads, is_public, uploaded_by, created_at, updated_at
//
// GET params:
//   category  : aptitude|verbal|logical|technical|general  (optional)
//   search    : string                                      (optional)
//   type      : pdf|video|link|article|quiz                 (optional)
//   page      : int (default 1)
//   limit     : int (default 20, max 50)
//
// Response:
// {
//   success   : true,
//   resources : [ { resource_id, title, description, category,
//                   resource_type, file_size, external_url,
//                   cloudinary_public_id, views, downloads,
//                   uploaded_by_name, created_at } ],
//   total     : int,
//   page      : int,
//   pages     : int
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

// ── Query params ──────────────────────────────────────────────────────────
$page   = max(1, (int)($_GET['page']  ?? 1));
$limit  = min(50, max(1, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

$category = trim($_GET['category'] ?? '');
$search   = trim($_GET['search']   ?? '');
$type     = trim($_GET['type']     ?? '');

$validCategories = ['aptitude', 'verbal', 'logical', 'technical', 'general'];
$validTypes      = ['pdf', 'video', 'link', 'article', 'quiz'];

// ── Build WHERE ───────────────────────────────────────────────────────────
// Always restrict to public resources only
$conditions = ['r.is_public = 1'];
$params     = [];
$types      = '';

if ($category !== '' && in_array($category, $validCategories, true)) {
    $conditions[] = 'r.category = ?';
    $params[]     = $category;
    $types       .= 's';
}

if ($type !== '' && in_array($type, $validTypes, true)) {
    $conditions[] = 'r.resource_type = ?';
    $params[]     = $type;
    $types       .= 's';
}

if ($search !== '') {
    $conditions[] = '(r.title LIKE ? OR r.description LIKE ?)';
    $like         = '%' . $search . '%';
    $params[]     = $like;
    $params[]     = $like;
    $types       .= 'ss';
}

$where = 'WHERE ' . implode(' AND ', $conditions);

// ── Count ─────────────────────────────────────────────────────────────────
$countRes = safePreparedQuery(
    $conn,
    "SELECT COUNT(*) AS total
     FROM resources r
     $where",
    $types, $params
);

if (!$countRes['success'] || !$countRes['result']) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to count resources.']);
    exit;
}

$totalRows  = (int)$countRes['result']->fetch_assoc()['total'];
$countRes['result']->free();
$totalPages = max(1, (int)ceil($totalRows / $limit));

// ── Fetch ─────────────────────────────────────────────────────────────────
$fetchTypes  = $types . 'ii';
$fetchParams = array_merge($params, [$limit, $offset]);

$fetchRes = safePreparedQuery(
    $conn,
    "SELECT
         r.resource_id,
         r.title,
         r.description,
         r.category,
         r.resource_type,
         r.file_size,
         r.external_url,
         r.cloudinary_public_id,
         r.file_path,
         r.views,
         r.downloads,
         r.created_at,
         u.full_name AS uploaded_by_name
     FROM resources r
     LEFT JOIN users u ON u.user_id = r.uploaded_by
     $where
     ORDER BY r.created_at DESC
     LIMIT ? OFFSET ?",
    $fetchTypes, $fetchParams
);

if (!$fetchRes['success'] || !$fetchRes['result']) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to load resources.']);
    exit;
}

$resources = [];
while ($row = $fetchRes['result']->fetch_assoc()) {
    $resources[] = [
        'resource_id'          => (int)$row['resource_id'],
        'title'                => $row['title'],
        'description'          => $row['description'] ?? '',
        'category'             => $row['category'],
        'resource_type'        => $row['resource_type'],
        'file_size'            => $row['file_size'] ? (int)$row['file_size'] : null,
        'external_url'         => $row['external_url'] ?? null,
        'cloudinary_public_id' => $row['cloudinary_public_id'] ?? null,
        'file_path'            => $row['file_path'] ?? null,
        'views'                => (int)$row['views'],
        'downloads'            => (int)$row['downloads'],
        'created_at'           => $row['created_at'],
        'uploaded_by_name'     => $row['uploaded_by_name'] ?? null,
    ];
}
$fetchRes['result']->free();

echo json_encode([
    'success'   => true,
    'resources' => $resources,
    'total'     => $totalRows,
    'page'      => $page,
    'pages'     => $totalPages,
]);

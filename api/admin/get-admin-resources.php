<?php
// ============================================================
// api/admin/get-admin-resources.php
//
// Admin only. Returns paginated rows from the `resources` table.
// Table: resources
// Columns: resource_id, title, description, category, resource_type,
//          file_path, cloudinary_public_id, file_size, external_url,
//          views, downloads, is_public, uploaded_by, created_at, updated_at
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

validateSession($conn, 'admin');

$page     = max(1, (int)($_GET['page']    ?? 1));
$limit    = min(100, max(1, (int)($_GET['limit'] ?? 20)));
$offset   = ($page - 1) * $limit;
$category = trim($_GET['category'] ?? '');
$search   = trim($_GET['search']   ?? '');

$conditions = [];
$params     = [];
$types      = '';

if ($category !== '') {
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

// Count
$countStmt = $conn->prepare(
    "SELECT COUNT(*) AS total FROM resources r $where"
);
if ($types !== '') $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$totalRows  = (int) $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();
$totalPages = max(1, (int) ceil($totalRows / $limit));

// Stats
$statsStmt = $conn->prepare(
    "SELECT COUNT(*) AS total_materials,
            COALESCE(SUM(views), 0) AS total_views,
            COALESCE(SUM(downloads), 0) AS total_downloads,
            COALESCE(SUM(file_size), 0) AS storage_used_bytes
     FROM resources"
);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();
$statsStmt->close();

// Fetch
$fetchTypes  = $types . 'ii';
$fetchParams = array_merge($params, [$limit, $offset]);

$stmt = $conn->prepare("
    SELECT
        r.resource_id          AS material_id,
        r.title,
        r.description,
        r.category,
        r.resource_type        AS material_type,
        r.cloudinary_public_id,
        r.external_url,
        r.file_size,
        r.is_public,
        r.views,
        r.downloads,
        r.created_at,
        r.uploaded_by          AS uploaded_by,
        u.full_name            AS created_by_name,
        IF(r.is_public, 'public', 'private') AS visibility
    FROM resources r
    LEFT JOIN users u ON u.user_id = r.uploaded_by
    $where
    ORDER BY r.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param($fetchTypes, ...$fetchParams);
$stmt->execute();
$result    = $stmt->get_result();
$materials = [];
while ($row = $result->fetch_assoc()) {
    $materials[] = $row;
}
$stmt->close();

echo json_encode([
    'success'   => true,
    'materials' => $materials,
    'total'     => $totalRows,
    'page'      => $page,
    'pages'     => $totalPages,
    'stats'     => $stats,
]);
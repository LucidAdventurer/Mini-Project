<?php
// ============================================================
// api/resources/get-resources.php
//
// Returns training materials with filters, search, pagination.
// Accessible by: admin (all), teacher (own + public), student (public only)
//
// GET ?category=pdf|video|...
//     ?search=keyword
//     ?type=pdf|video|link|article|quiz
//     ?page=1&limit=20
//     ?my_uploads=1   (teacher: their own uploads only)
//
// Returns {
//   success: bool,
//   materials: [...],
//   total: int, page: int, pages: int,
//   stats: { total_materials, storage_used_bytes, total_downloads, total_views }
// }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$currentUser = validateSession($conn);
$userId      = (int) $currentUser['user_id'];
$role        = $currentUser['user_type'];

// ── Query params ──
$search    = trim($_GET['search']   ?? '');
$category  = trim($_GET['category'] ?? '');
$type      = trim($_GET['type']     ?? '');
$myUploads = !empty($_GET['my_uploads']) && $role === 'teacher';
$page      = max(1, (int)($_GET['page']  ?? 1));
$limit     = min(100, max(1, (int)($_GET['limit'] ?? 20)));
$offset    = ($page - 1) * $limit;

$allowedTypes = ['pdf', 'video', 'link', 'article', 'quiz'];
$allowedCategories = ['aptitude', 'technical', 'coding', 'reasoning', 'english', 'general', 'placement', 'interview'];

// ── Build WHERE ──
$conditions = [];
$params     = [];
$types      = "";

// Access control
if ($role === 'admin') {
    // Admins see everything
} elseif ($role === 'teacher') {
    if ($myUploads) {
        $conditions[] = "m.uploaded_by = ?";
        $params[]     = $userId;
        $types       .= "i";
    } else {
        $conditions[] = "(m.is_public = 1 OR m.uploaded_by = ?)";
        $params[]     = $userId;
        $types       .= "i";
    }
} else {
    // Students see only public resources uploaded by teachers
    $conditions[] = "m.is_public = 1 AND u.user_type = 'teacher'";
}

if ($search !== '') {
    $conditions[] = "(m.title LIKE ? OR m.description LIKE ? OR u.full_name LIKE ?)";
    $like         = '%' . $search . '%';
    $params[]     = $like;
    $params[]     = $like;
    $params[]     = $like;
    $types       .= "sss";
}

if ($type !== '' && in_array($type, $allowedTypes, true)) {
    $conditions[] = "m.material_type = ?";
    $params[]     = $type;
    $types       .= "s";
}

if ($category !== '' && in_array($category, $allowedCategories, true)) {
    $conditions[] = "m.category = ?";
    $params[]     = $category;
    $types       .= "s";
}

$where = !empty($conditions) ? implode(' AND ', $conditions) : '1=1';

// ── Aggregate stats ──
$rsStats = safePreparedQuery($conn,
    "SELECT
        COUNT(*)                AS total_materials,
        SUM(m.file_size)        AS storage_used_bytes,
        SUM(m.downloads)        AS total_downloads,
        SUM(m.views)            AS total_views
     FROM training_materials m
     LEFT JOIN users u ON u.user_id = m.uploaded_by
     WHERE $where",
    $types ?: "", $params ?: []
);

$stats = ['total_materials' => 0, 'storage_used_bytes' => 0, 'total_downloads' => 0, 'total_views' => 0];
if ($rsStats['success'] && $rsStats['result']) {
    $row = $rsStats['result']->fetch_assoc();
    if ($row) {
        $stats = [
            'total_materials'    => (int)($row['total_materials']    ?? 0),
            'storage_used_bytes' => (int)($row['storage_used_bytes'] ?? 0),
            'total_downloads'    => (int)($row['total_downloads']    ?? 0),
            'total_views'        => (int)($row['total_views']        ?? 0),
        ];
    }
    $rsStats['result']->free();
}

// ── Total count for pagination ──
$total = 0;
$rc = safePreparedQuery($conn,
    "SELECT COUNT(*) AS cnt
     FROM training_materials m
     LEFT JOIN users u ON u.user_id = m.uploaded_by
     WHERE $where",
    $types ?: "", $params ?: []
);
if ($rc['success'] && $rc['result']) {
    $row   = $rc['result']->fetch_assoc();
    $total = (int)($row['cnt'] ?? 0);
    $rc['result']->free();
}

// ── Fetch paginated rows ──
$listTypes  = $types . "ii";
$listParams = array_merge($params, [$limit, $offset]);

// Include user's own progress if student/teacher
// $userId is already (int) cast — safe to embed in JOIN since it's a
// numeric literal, but we use a subquery-style approach via params instead.
$progressJoin  = '';
$progressField = 'NULL AS user_progress, NULL AS is_completed';
if (in_array($role, ['student', 'teacher'], true)) {
    // Use a correlated subquery so $userId passes through bind_param safely
    $progressField = "(SELECT mp.progress_percentage FROM material_progress mp
                       WHERE mp.material_id = m.material_id AND mp.user_id = ?) AS user_progress,
                      (SELECT mp.is_completed FROM material_progress mp
                       WHERE mp.material_id = m.material_id AND mp.user_id = ?) AS is_completed";
    // Prepend the two user_id params before limit/offset
    $listTypes  = "ii" . $listTypes;
    $listParams = array_merge([$userId, $userId], $listParams);
}

$r = safePreparedQuery($conn,
    "SELECT
        m.material_id,
        m.title,
        m.description,
        m.material_type,
        m.file_path,
        m.external_url,
        m.file_size,
        m.category,
        m.difficulty,
        m.is_public,
        m.views,
        m.downloads,
        m.tags,
        m.estimated_time_minutes,
        m.created_at,
        m.updated_at,
        u.full_name   AS uploaded_by_name,
        u.user_id     AS uploaded_by_id,
        $progressField
     FROM training_materials m
     LEFT JOIN users u ON u.user_id = m.uploaded_by
     WHERE $where
     ORDER BY m.created_at DESC
     LIMIT ? OFFSET ?",
    $listTypes, $listParams
);

$materials = [];
if ($r['success'] && $r['result']) {
    while ($row = $r['result']->fetch_assoc()) {
        $materials[] = [
            'material_id'            => (int)$row['material_id'],
            'title'                  => $row['title'],
            'description'            => $row['description'],
            'material_type'          => $row['material_type'],
            'file_path'              => $row['file_path'],
            'external_url'           => $row['external_url'],
            'file_size'              => (int)($row['file_size'] ?? 0),
            'category'               => $row['category'],
            'difficulty'             => $row['difficulty'],
            'is_public'              => (bool)$row['is_public'],
            'views'                  => (int)$row['views'],
            'downloads'              => (int)$row['downloads'],
            'tags'                   => $row['tags'] ? json_decode($row['tags'], true) : [],
            'estimated_time_minutes' => (int)($row['estimated_time_minutes'] ?? 0),
            'created_at'             => $row['created_at'],
            'updated_at'             => $row['updated_at'],
            'uploaded_by_name'       => $row['uploaded_by_name'],
            'uploaded_by_id'         => (int)($row['uploaded_by_id'] ?? 0),
            'user_progress'          => isset($row['user_progress']) ? (int)$row['user_progress'] : null,
            'is_completed'           => isset($row['is_completed']) ? (bool)$row['is_completed'] : null,
        ];
    }
    $r['result']->free();
}

echo json_encode([
    'success'   => true,
    'materials' => $materials,
    'total'     => $total,
    'page'      => $page,
    'pages'     => (int)ceil($total / max(1, $limit)),
    'stats'     => $stats,
]);
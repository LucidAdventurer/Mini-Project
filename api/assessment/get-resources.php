<?php
// ============================================================
// api/resources/get-resources.php
//
// Returns training materials with filters, search, pagination.
// Accessible by: admin (all), teacher (own + public), student (public only)
//
// Real schema — materials table columns:
//   material_id, title, description, created_by, visibility,
//   cloudinary_public_id, external_url, category, difficulty, created_at
//
// No: file_path, file_size, views, downloads, tags,
//     estimated_time_minutes, material_type, uploaded_by
//
// GET ?category=...
//     ?search=keyword
//     ?visibility=public|private
//     ?my_uploads=1   (teacher: their own uploads only)
//     ?page=1&limit=20
//
// Returns {
//   success: bool,
//   materials: [...],
//   total: int, page: int, pages: int,
//   stats: { total_materials }
// }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$currentUser = validateSession($conn);
$userId      = (int) $currentUser['user_id'];
$role        = $currentUser['role'];   // column is 'role', not 'user_type'

// ── Query params ──
$search     = trim($_GET['search']     ?? '');
$category   = trim($_GET['category']   ?? '');
$visibility = trim($_GET['visibility'] ?? '');
$myUploads  = !empty($_GET['my_uploads']) && $role === 'teacher';
$page       = max(1, (int)($_GET['page']  ?? 1));
$limit      = min(100, max(1, (int)($_GET['limit'] ?? 20)));
$offset     = ($page - 1) * $limit;

$allowedCategories  = ['aptitude', 'technical', 'coding', 'reasoning', 'english', 'general', 'placement', 'interview'];
$allowedVisibility  = ['public', 'private'];

// ── Build WHERE ──
$conditions = [];
$params     = [];
$types      = "";

// Access control per role
if ($role === 'admin') {
    // Admin sees everything — no restriction
} elseif ($role === 'teacher') {
    if ($myUploads) {
        $conditions[] = "m.created_by = ?";
        $params[]     = $userId;
        $types       .= "i";
    } else {
        // Own uploads OR anything public
        $conditions[] = "(m.visibility = 'public' OR m.created_by = ?)";
        $params[]     = $userId;
        $types       .= "i";
    }
} else {
    // Students see only public materials
    $conditions[] = "m.visibility = 'public'";
}

if ($search !== '') {
    $conditions[] = "(m.title LIKE ? OR m.description LIKE ? OR u.full_name LIKE ?)";
    $like         = '%' . $search . '%';
    $params[]     = $like;
    $params[]     = $like;
    $params[]     = $like;
    $types       .= "sss";
}

if ($category !== '' && in_array($category, $allowedCategories, true)) {
    $conditions[] = "m.category = ?";
    $params[]     = $category;
    $types       .= "s";
}

if ($visibility !== '' && in_array($visibility, $allowedVisibility, true)) {
    $conditions[] = "m.visibility = ?";
    $params[]     = $visibility;
    $types       .= "s";
}

$where = !empty($conditions) ? implode(' AND ', $conditions) : '1=1';

// ── Aggregate stats ──
$rsStats = safePreparedQuery($conn,
    "SELECT COUNT(*) AS total_materials
     FROM materials m
     LEFT JOIN users u ON u.user_id = m.created_by
     WHERE $where",
    $types ?: "", $params ?: []
);

$stats = ['total_materials' => 0];
if ($rsStats['success'] && $rsStats['result']) {
    $row = $rsStats['result']->fetch_assoc();
    if ($row) {
        $stats['total_materials'] = (int)($row['total_materials'] ?? 0);
    }
    $rsStats['result']->free();
}

// ── Total count for pagination ──
$total = $stats['total_materials'];

// ── Material progress subquery (student/teacher only) ──
$progressField = "NULL AS user_progress, NULL AS completed";
$listTypes     = $types . "ii";
$listParams    = array_merge($params, [$limit, $offset]);

if (in_array($role, ['student', 'teacher'], true)) {
    $progressField = "(SELECT mp.progress_percentage FROM material_progress mp
                       WHERE mp.material_id = m.material_id AND mp.user_id = ?) AS user_progress,
                      (SELECT mp.completed FROM material_progress mp
                       WHERE mp.material_id = m.material_id AND mp.user_id = ?) AS completed";
    $listTypes  = "ii" . $listTypes;
    $listParams = array_merge([$userId, $userId], $listParams);
}

// ── Fetch paginated rows ──
$r = safePreparedQuery($conn,
    "SELECT
        m.material_id,
        m.title,
        m.description,
        m.created_by,
        m.visibility,
        m.cloudinary_public_id,
        m.external_url,
        m.category,
        m.difficulty,
        m.created_at,
        u.full_name AS created_by_name,
        $progressField
     FROM materials m
     LEFT JOIN users u ON u.user_id = m.created_by
     WHERE $where
     ORDER BY m.created_at DESC
     LIMIT ? OFFSET ?",
    $listTypes, $listParams
);

$materials = [];
if ($r['success'] && $r['result']) {
    while ($row = $r['result']->fetch_assoc()) {
        $materials[] = [
            'material_id'          => (int)$row['material_id'],
            'title'                => $row['title'],
            'description'          => $row['description'],
            'created_by'           => (int)$row['created_by'],
            'created_by_name'      => $row['created_by_name'],
            'visibility'           => $row['visibility'],
            'cloudinary_public_id' => $row['cloudinary_public_id'],
            'external_url'         => $row['external_url'],
            'category'             => $row['category'],
            'difficulty'           => $row['difficulty'],
            'created_at'           => $row['created_at'],
            'user_progress'        => isset($row['user_progress']) ? (int)$row['user_progress'] : null,
            'completed'            => isset($row['completed'])     ? (bool)$row['completed']    : null,
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
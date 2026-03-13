<?php
// ============================================================
// api/resources/get-teacher-resources.php
//
// Returns paginated materials uploaded by the logged-in teacher.
// Also returns aggregate stats for the teacher's own resources.
//
// GET ?category=aptitude|technical|verbal|interview|other
//     ?type=pdf|video|link|image|document
//     ?search=keyword
//     ?page=1&limit=20
//     ?material_id=X   → return single material (for edit modal)
//
// Returns {
//   success: bool,
//   materials: [...],
//   total: int, page: int, pages: int,
//   stats: { total_materials, total_views, total_downloads, storage_used_bytes }
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

// ── Auth: teacher required ────────────────────────────────────────────────
$currentUser = validateSession($conn, 'teacher');
$teacherId   = (int)$currentUser['user_id'];

// ── Single material fetch (for edit modal) ────────────────────────────────
if (!empty($_GET['material_id'])) {
    $mid = (int)$_GET['material_id'];
    $r = safePreparedQuery($conn,
        "SELECT m.*, u.full_name AS uploaded_by_name
         FROM materials m
         LEFT JOIN users u ON u.user_id = m.created_by
         WHERE m.material_id = ? AND m.created_by = ?",
        "ii", [$mid, $teacherId]
    );
    if (!$r['success'] || !$r['result']) {
        echo json_encode(['success' => false, 'error' => 'Not found.']);
        exit;
    }
    $row = $r['result']->fetch_assoc();
    $r['result']->free();
    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Resource not found or not yours.']);
        exit;
    }
    echo json_encode([
        'success'   => true,
        'materials' => [buildMaterial($row)],
    ]);
    exit;
}

// ── Query params ──────────────────────────────────────────────────────────
$search   = trim($_GET['search']   ?? '');
$category = trim($_GET['category'] ?? '');
$type     = trim($_GET['type']     ?? '');
$page     = max(1, (int)($_GET['page']  ?? 1));
$limit    = min(50, max(1, (int)($_GET['limit'] ?? 20)));
$offset   = ($page - 1) * $limit;

$allowedCategories = ['aptitude', 'technical', 'verbal', 'interview', 'other'];
$allowedTypes      = ['pdf', 'video', 'image', 'link', 'document'];

// ── WHERE conditions (always scoped to this teacher) ──────────────────────
$conditions = ['m.created_by = ?'];
$params     = [$teacherId];
$types      = 'i';

if ($search !== '') {
    $conditions[] = '(m.title LIKE ? OR m.description LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ss';
}

if ($category !== '' && in_array($category, $allowedCategories, true)) {
    $conditions[] = 'm.category = ?';
    $params[]     = $category;
    $types       .= 's';
}

if ($type !== '' && in_array($type, $allowedTypes, true)) {
    if ($type === 'link') {
        $conditions[] = 'm.external_url IS NOT NULL AND m.external_url != ""';
    } else {
        $conditions[] = 'm.material_type = ?';
        $params[]     = $type;
        $types       .= 's';
    }
}

$where = implode(' AND ', $conditions);

// ── Stats for this teacher ────────────────────────────────────────────────
$statsRes = safePreparedQuery($conn,
    "SELECT
        COUNT(*)                    AS total_materials,
        COALESCE(SUM(m.view_count), 0)      AS total_views,
        COALESCE(SUM(m.download_count), 0)  AS total_downloads,
        COALESCE(SUM(m.file_size), 0)       AS storage_used_bytes
     FROM materials m
     WHERE m.created_by = ?",
    "i", [$teacherId]
);
$stats = ['total_materials' => 0, 'total_views' => 0, 'total_downloads' => 0, 'storage_used_bytes' => 0];
if ($statsRes['success'] && $statsRes['result']) {
    $srow  = $statsRes['result']->fetch_assoc();
    $stats = [
        'total_materials'   => (int)($srow['total_materials']   ?? 0),
        'total_views'       => (int)($srow['total_views']       ?? 0),
        'total_downloads'   => (int)($srow['total_downloads']   ?? 0),
        'storage_used_bytes'=> (int)($srow['storage_used_bytes']?? 0),
    ];
    $statsRes['result']->free();
}

// ── Total count ───────────────────────────────────────────────────────────
$countRes = safePreparedQuery($conn,
    "SELECT COUNT(*) AS total FROM materials m WHERE {$where}",
    $types, $params
);
$total = 0;
if ($countRes['success'] && $countRes['result']) {
    $total = (int)($countRes['result']->fetch_assoc()['total'] ?? 0);
    $countRes['result']->free();
}

// ── Fetch paginated rows ──────────────────────────────────────────────────
$listTypes  = $types . 'ii';
$listParams = array_merge($params, [$limit, $offset]);

$r = safePreparedQuery($conn,
    "SELECT
        m.material_id,
        m.title,
        m.description,
        m.category,
        m.difficulty,
        m.visibility,
        m.material_type,
        m.cloudinary_public_id,
        m.external_url,
        m.file_size,
        m.view_count,
        m.download_count,
        m.estimated_time_minutes,
        m.created_at,
        u.full_name AS uploaded_by_name
     FROM materials m
     LEFT JOIN users u ON u.user_id = m.created_by
     WHERE {$where}
     ORDER BY m.created_at DESC
     LIMIT ? OFFSET ?",
    $listTypes, $listParams
);

$materials = [];
if ($r['success'] && $r['result']) {
    while ($row = $r['result']->fetch_assoc()) {
        $materials[] = buildMaterial($row);
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

// ── Helper ────────────────────────────────────────────────────────────────
function buildMaterial(array $row): array {
    // Derive type if no material_type column: fall back to link/file/document
    $type = $row['material_type'] ?? '';
    if (!$type) {
        if (!empty($row['external_url'])) $type = 'link';
        elseif (!empty($row['cloudinary_public_id'])) $type = 'file';
        else $type = 'document';
    }

    // Map visibility → is_public flag
    $isPublic = ($row['visibility'] ?? '') === 'public' ? 1 : 0;

    return [
        'material_id'            => (int)$row['material_id'],
        'title'                  => $row['title'],
        'description'            => $row['description'],
        'category'               => $row['category'],
        'difficulty'             => $row['difficulty'],
        'material_type'          => $type,
        'is_public'              => $isPublic,
        'visibility'             => $row['visibility'],
        'cloudinary_public_id'   => $row['cloudinary_public_id'] ?? null,
        'external_url'           => $row['external_url'] ?? null,
        'file_size'              => isset($row['file_size']) ? (int)$row['file_size'] : null,
        'views'                  => (int)($row['view_count']      ?? 0),
        'downloads'              => (int)($row['download_count']  ?? 0),
        'estimated_time_minutes' => isset($row['estimated_time_minutes']) ? (int)$row['estimated_time_minutes'] : null,
        'created_at'             => $row['created_at'],
        'uploaded_by_name'       => $row['uploaded_by_name'] ?? null,
    ];
}

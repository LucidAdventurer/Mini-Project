<?php
// ============================================================
// api/resources/get-teacher-resources.php
// Fixed: SELECT only uses columns that exist in the materials table
// Confirmed columns: material_id, title, description, created_by,
//                    visibility, cloudinary_public_id, external_url, category
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Auth: teacher or admin ────────────────────────────────────────────────
$sessionRole = $_SESSION['role'] ?? $_SESSION['user_type'] ?? '';
if (empty($_SESSION['user_id']) || !in_array($sessionRole, ['admin', 'teacher'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}
$teacherId = (int) $_SESSION['user_id'];

// ── Single material fetch (for edit modal) ────────────────────────────────
if (!empty($_GET['material_id'])) {
    $mid = (int)$_GET['material_id'];
    $r = safePreparedQuery($conn,
        "SELECT m.material_id, m.title, m.description, m.category,
                m.visibility, m.cloudinary_public_id, m.external_url,
                m.created_by, m.created_at,
                u.full_name AS uploaded_by_name,
                (SELECT mt.target_id FROM material_targets mt
                 WHERE mt.material_id = m.material_id
                   AND mt.target_type = 'group' LIMIT 1) AS group_id
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
    echo json_encode(['success' => true, 'materials' => [buildMaterial($row)]]);
    exit;
}

// ── Query params ──────────────────────────────────────────────────────────
$search   = trim($_GET['search']   ?? '');
$category = trim($_GET['category'] ?? '');
$type     = trim($_GET['type']     ?? '');
$page     = max(1, (int)($_GET['page']  ?? 1));
$limit    = min(50, max(1, (int)($_GET['limit'] ?? 20)));
$offset   = ($page - 1) * $limit;

$allowedCategories = ['aptitude', 'technical', 'verbal', 'coding', 'reasoning', 'english', 'general', 'interview', 'other'];
$allowedTypes      = ['pdf', 'video', 'image', 'link', 'document'];

// ── WHERE conditions ──────────────────────────────────────────────────────
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

// Type filter: derive from external_url (link) vs cloudinary/file
if ($type !== '' && in_array($type, $allowedTypes, true)) {
    if ($type === 'link') {
        $conditions[] = 'm.external_url IS NOT NULL AND m.external_url != ""';
    }
    // other type filters skipped — material_type column doesn't exist
}

$where = implode(' AND ', $conditions);

// ── Stats ─────────────────────────────────────────────────────────────────
$statsRes = safePreparedQuery($conn,
    "SELECT COUNT(*) AS total_materials FROM materials m WHERE m.created_by = ?",
    "i", [$teacherId]
);
$stats = ['total_materials' => 0, 'total_views' => 0, 'total_downloads' => 0, 'storage_used_bytes' => 0];
if ($statsRes['success'] && $statsRes['result']) {
    $srow = $statsRes['result']->fetch_assoc();
    $stats['total_materials'] = (int)($srow['total_materials'] ?? 0);
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
        m.visibility,
        m.cloudinary_public_id,
        m.external_url,
        m.created_at,
        u.full_name AS uploaded_by_name,
        (SELECT mt.target_id FROM material_targets mt
         WHERE mt.material_id = m.material_id
           AND mt.target_type = 'group' LIMIT 1) AS group_id
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
    // Derive type from available data
    $type = 'document';
    if (!empty($row['external_url'])) {
        $url = strtolower($row['external_url']);
        $ext = pathinfo(parse_url($url, PHP_URL_PATH) ?: $url, PATHINFO_EXTENSION);
        if ($ext === 'pdf')                                                      $type = 'pdf';
        elseif (in_array($ext, ['mp4','webm','ogg','mov'], true))               $type = 'video';
        elseif (in_array($ext, ['jpg','jpeg','png','gif','webp'], true))        $type = 'image';
        elseif (in_array($ext, ['doc','docx','xls','xlsx','ppt','pptx'], true)) $type = 'document';
        elseif ($ext === '')                                                     $type = 'link';
    } elseif (!empty($row['cloudinary_public_id'])) {
        $type = 'file';
    }

    $isPublic = ($row['visibility'] ?? '') === 'public' ? 1 : 0;

    return [
        'material_id'          => (int)$row['material_id'],
        'title'                => $row['title'],
        'description'          => $row['description'] ?? '',
        'category'             => $row['category'] ?? '',
        'difficulty'           => null,
        'material_type'        => $type,
        'is_public'            => $isPublic,
        'visibility'           => $row['visibility'] ?? 'public',
        'group_id'             => isset($row['group_id']) ? (int)$row['group_id'] : null,
        'cloudinary_public_id' => $row['cloudinary_public_id'] ?? null,
        'external_url'         => $row['external_url'] ?? null,
        'file_size'            => null,
        'views'                => 0,
        'downloads'            => 0,
        'estimated_time_minutes' => null,
        'created_at'           => $row['created_at'] ?? null,
        'uploaded_by_name'     => $row['uploaded_by_name'] ?? null,
        'available_from'       => null,
        'available_until'      => null,
    ];
}
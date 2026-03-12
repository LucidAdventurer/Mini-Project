<?php
// ============================================================
// api/resources/get-resources.php
//
// Returns paginated materials for the logged-in student.
//
// Schema: materials(material_id, title, description, created_by,
//   visibility, cloudinary_public_id, external_url, category,
//   difficulty, created_at)
// material_progress(material_id, user_id, progress_percentage,
//   completed, last_accessed)
// material_targets(id, material_id, target_type, target_id)
// Access: visibility='public' OR targeted via material_targets
//
// GET ?category=aptitude|technical|...
//     ?type=pdf|video|link  (maps to cloudinary vs external_url)
//     ?search=keyword
//     ?page=1&limit=20
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

$currentUser = validateSession($conn, 'student');
$userId      = (int) $currentUser['user_id'];

// ── Query params ──
$search   = trim($_GET['search']   ?? '');
$category = trim($_GET['category'] ?? '');
$page     = max(1, (int)($_GET['page']  ?? 1));
$limit    = min(50, max(1, (int)($_GET['limit'] ?? 20)));
$offset   = ($page - 1) * $limit;

$allowedCategories = ['aptitude', 'technical', 'coding', 'reasoning', 'english', 'general'];

// ── Access control: student sees public materials OR
//    those targeted at them directly or via their groups ──
$accessClause = "(
    m.visibility = 'public'
    OR (m.visibility = 'group' AND EXISTS (
        SELECT 1 FROM material_targets mt
        WHERE mt.material_id = m.material_id
          AND (
              (mt.target_type = 'student' AND mt.target_id = $userId)
              OR (mt.target_type = 'group' AND mt.target_id IN (
                  SELECT gm.group_id FROM group_members gm WHERE gm.student_id = $userId
              ))
          )
    ))
)";

// ── Build WHERE conditions ──
$conditions = [$accessClause];
$params     = [];
$types      = "";

if ($search !== '') {
    $conditions[] = "(m.title LIKE ? OR m.description LIKE ?)";
    $like         = '%' . $search . '%';
    $params[]     = $like;
    $params[]     = $like;
    $types       .= "ss";
}

if ($category !== '' && in_array($category, $allowedCategories, true)) {
    $conditions[] = "m.category = ?";
    $params[]     = $category;
    $types       .= "s";
}

$where = implode(' AND ', $conditions);

// ── Stats (counts only — no file_size/views/downloads in schema) ──
$statsResult = safePreparedQuery($conn,
    "SELECT COUNT(*) AS total_materials
     FROM materials m
     WHERE $where",
    $types ?: "", $params ?: []
);
$stats = ['total_materials' => 0, 'total_views' => 0, 'total_downloads' => 0, 'storage_used_bytes' => 0];
if ($statsResult['success'] && $statsResult['result']) {
    $row = $statsResult['result']->fetch_assoc();
    $stats['total_materials'] = (int)($row['total_materials'] ?? 0);
    $statsResult['result']->free();
}

// ── Total count for pagination ──
$total = $stats['total_materials'];

// ── Fetch paginated rows with progress ──
$listTypes  = "ii" . $types . "ii";
$listParams = array_merge([$userId, $userId], $params, [$limit, $offset]);

$r = safePreparedQuery($conn,
    "SELECT
        m.material_id,
        m.title,
        m.description,
        m.category,
        m.difficulty,
        m.visibility,
        m.cloudinary_public_id,
        m.external_url,
        m.created_at,
        u.full_name AS uploaded_by_name,
        -- Progress for this student
        (SELECT mp.progress_percentage FROM material_progress mp
         WHERE mp.material_id = m.material_id AND mp.user_id = ?) AS user_progress,
        (SELECT mp.completed FROM material_progress mp
         WHERE mp.material_id = m.material_id AND mp.user_id = ?) AS is_completed
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
        // Derive material_type from available data
        $materialType = 'document';
        if (!empty($row['external_url'])) {
            $materialType = 'link';
        } elseif (!empty($row['cloudinary_public_id'])) {
            $materialType = 'file';
        }

        $materials[] = [
            'material_id'        => (int) $row['material_id'],
            'title'              => $row['title'],
            'description'        => $row['description'],
            'category'           => $row['category'],
            'difficulty'         => $row['difficulty'],
            'material_type'      => $materialType,
            'cloudinary_public_id' => $row['cloudinary_public_id'],
            'external_url'       => $row['external_url'],
            'created_at'         => $row['created_at'],
            'uploaded_by_name'   => $row['uploaded_by_name'],
            'user_progress'      => $row['user_progress'] !== null ? (int)$row['user_progress'] : null,
            'is_completed'       => $row['is_completed'] !== null ? (bool)$row['is_completed'] : null,
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
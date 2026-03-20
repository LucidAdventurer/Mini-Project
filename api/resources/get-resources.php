<?php
/* ========================================
 * GET RESOURCES API
 * File: api/resources/get-resources.php
 *
 * Fixed to match actual `materials` table schema:
 *   material_id, title, description, created_by,
 *   visibility, cloudinary_public_id, external_url,
 *   category, + 2 more (created_at, updated_at)
 *
 * Students only see public materials uploaded by teachers.
 * ======================================== */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

// ── Auth: student only ────────────────────────────────────────────────────
$currentUser = validateSession($conn, 'student');
$userId      = (int) $currentUser['user_id'];

// ── Input ─────────────────────────────────────────────────────────────────
$page     = max(1, (int) ($_GET['page']  ?? 1));
$limit    = min(50, max(1, (int) ($_GET['limit'] ?? 12)));
$offset   = ($page - 1) * $limit;
$category = trim($_GET['category'] ?? '');
$search   = trim($_GET['search']   ?? '');

$allowedTypes     = ['pdf', 'video', 'document', 'link', 'image'];
$allowedCategories = ['aptitude', 'technical', 'verbal', 'coding', 'reasoning', 'english', 'general'];
$typeRaw          = trim($_GET['type'] ?? '');

// ── WHERE: students only see public, teacher-uploaded materials ───────────
$conditions = [
    "m.visibility = 'public'",  // only public materials
    "u.role = 'teacher'",       // only teacher-uploaded (never admin)
];
$types  = "";
$params = [];

if ($category !== '' && in_array($category, $allowedCategories, true)) {
    $conditions[] = "m.category = ?";
    $types       .= "s";
    $params[]     = $category;
}

// Type filter: derived from external_url since material_type col doesn't exist
if ($typeRaw !== '' && in_array($typeRaw, $allowedTypes, true)) {
    if ($typeRaw === 'link') {
        $conditions[] = "m.external_url IS NOT NULL AND m.cloudinary_public_id IS NULL";
    } elseif ($typeRaw === 'pdf') {
        $conditions[] = "m.external_url LIKE '%.pdf'";
    } elseif ($typeRaw === 'video') {
        $conditions[] = "(m.external_url LIKE '%.mp4' OR m.external_url LIKE '%.webm')";
    } elseif ($typeRaw === 'image') {
        $conditions[] = "(m.external_url LIKE '%.jpg' OR m.external_url LIKE '%.png' OR m.external_url LIKE '%.jpeg')";
    }
}

if ($search !== '') {
    $conditions[] = "(m.title LIKE ? OR m.description LIKE ?)";
    $types       .= "ss";
    $like         = '%' . $search . '%';
    $params[]     = $like;
    $params[]     = $like;
}

$where = implode(' AND ', $conditions);

// ── Stats ─────────────────────────────────────────────────────────────────
$statsResult = safePreparedQuery(
    $conn,
    "SELECT COUNT(m.material_id) AS total_materials
     FROM materials m
     JOIN users u ON u.user_id = m.created_by
     WHERE u.role = 'teacher' AND m.visibility = 'public'",
    "", []
);
$stats = ['total_materials' => 0, 'total_views' => 0, 'total_downloads' => 0, 'storage_used_bytes' => 0];
if ($statsResult['success'] && $statsResult['result']) {
    $row = $statsResult['result']->fetch_assoc();
    $stats['total_materials'] = (int)($row['total_materials'] ?? 0);
    $statsResult['result']->free();
}

// ── Count total matching rows ─────────────────────────────────────────────
$countResult = safePreparedQuery(
    $conn,
    "SELECT COUNT(*) AS total
     FROM materials m
     JOIN users u ON u.user_id = m.created_by
     WHERE $where",
    $types, $params
);
$totalRows = 0;
if ($countResult['success'] && $countResult['result']) {
    $row       = $countResult['result']->fetch_assoc();
    $totalRows = (int)($row['total'] ?? 0);
    $countResult['result']->free();
}
$totalPages = max(1, (int) ceil($totalRows / $limit));

// ── Fetch materials ───────────────────────────────────────────────────────
$listTypes  = $types . "ii";
$listParams = array_merge($params, [$limit, $offset]);

$matResult = safePreparedQuery(
    $conn,
    "SELECT
         m.material_id,
         m.title,
         m.description,
         m.category,
         m.visibility,
         m.cloudinary_public_id,
         m.external_url,
         m.created_at,
         u.full_name AS uploaded_by_name
     FROM materials m
     JOIN users u ON u.user_id = m.created_by
     WHERE $where
     ORDER BY m.created_at DESC
     LIMIT ? OFFSET ?",
    $listTypes, $listParams
);

$materials = [];
if ($matResult['success'] && $matResult['result']) {
    while ($row = $matResult['result']->fetch_assoc()) {
        // Derive material_type from URL for frontend compatibility
        $type = 'document';
        $url  = strtolower($row['external_url'] ?? '');
        if ($url !== '') {
            if (str_ends_with($url, '.pdf'))                           $type = 'pdf';
            elseif (preg_match('/\.(mp4|webm|ogg|mov)$/', $url))      $type = 'video';
            elseif (preg_match('/\.(jpg|jpeg|png|gif|webp)$/', $url)) $type = 'image';
            elseif (empty($row['cloudinary_public_id']))               $type = 'link';
        } elseif (!empty($row['cloudinary_public_id'])) {
            $type = 'file';
        }

        $materials[] = [
            'material_id'          => (int) $row['material_id'],
            'title'                => $row['title'],
            'description'          => $row['description'] ?? '',
            'category'             => $row['category'] ?? '',
            'material_type'        => $type,
            'is_public'            => 1,
            'visibility'           => $row['visibility'],
            'cloudinary_public_id' => $row['cloudinary_public_id'] ?? null,
            'external_url'         => $row['external_url'] ?? null,
            'file_size'            => null,
            'views'                => 0,
            'downloads'            => 0,
            'difficulty'           => null,
            'estimated_time_minutes' => null,
            'created_at'           => $row['created_at'] ?? null,
            'uploaded_by_name'     => $row['uploaded_by_name'] ?? null,
            'user_progress'        => null,
            'is_completed'         => 0,
        ];
    }
    $matResult['result']->free();
}

echo json_encode([
    'success'   => true,
    'materials' => $materials,
    'total'     => $totalRows,
    'page'      => $page,
    'pages'     => $totalPages,
    'stats'     => $stats,
]);

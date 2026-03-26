<?php
/**
 * api/resources/get-resources.php
 *
 * Table  : materials
 * Columns: material_id, title, description, created_by, visibility (enum: public/group/private),
 *          cloudinary_public_id, external_url, category, created_at
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$currentUser = validateSession($conn);
$role        = $currentUser['role'];
$userId      = (int) $currentUser['user_id'];

if (!in_array($role, ['admin', 'teacher', 'student'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

$page         = max(1, (int) ($_GET['page']          ?? 1));
$limit        = min(100, max(1, (int) ($_GET['limit'] ?? 20)));
$offset       = ($page - 1) * $limit;
$category     = trim($_GET['category']      ?? '');
$search       = trim($_GET['search']        ?? '');
$uploaderRole = trim($_GET['uploader_role'] ?? '');

$conditions = [];
$params     = [];
$types      = '';

if ($role === 'student') {
    // Public materials OR group-targeted where student is a member OR student-targeted directly
    $conditions[] = "(
        m.visibility = 'public'
        OR (
            m.visibility = 'group'
            AND EXISTS (
                SELECT 1 FROM material_targets mt
                JOIN group_members gm ON gm.group_id = mt.target_id
                WHERE mt.material_id = m.material_id
                  AND mt.target_type = 'group'
                  AND gm.student_id = ?
            )
        )
        OR (
            m.visibility = 'group'
            AND EXISTS (
                SELECT 1 FROM material_targets mt
                WHERE mt.material_id = m.material_id
                  AND mt.target_type = 'student'
                  AND mt.target_id = ?
            )
        )
    )";
    $params[] = $userId;
    $params[] = $userId;
    $types   .= 'ii';
    if ($uploaderRole !== '') {
        $conditions[] = 'u.role = ?';
        $params[]     = $uploaderRole;
        $types       .= 's';
    }
} elseif ($role === 'teacher') {
    $conditions[] = 'm.created_by = ?';
    $params[]     = $userId;
    $types       .= 'i';
}

if ($category !== '') {
    $conditions[] = 'm.category = ?';
    $params[]     = $category;
    $types       .= 's';
}
if ($search !== '') {
    $conditions[] = '(m.title LIKE ? OR m.description LIKE ?)';
    $like         = '%' . $search . '%';
    $params[]     = $like;
    $params[]     = $like;
    $types       .= 'ss';
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

// ── Count ─────────────────────────────────────────────────────────────────
$countStmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM materials m
     LEFT JOIN users u ON u.user_id = m.created_by
     $where"
);
if ($types !== '') {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalRows  = (int) $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();
$totalPages = max(1, (int) ceil($totalRows / $limit));

// ── Fetch ─────────────────────────────────────────────────────────────────
$sql = "
    SELECT
        m.material_id,
        m.title,
        m.description,
        m.category,
        m.visibility,
        m.cloudinary_public_id,
        m.external_url,
        m.created_at,
        m.created_by                AS uploaded_by,
        u.full_name                 AS created_by_name,
        u.full_name                 AS uploaded_by_name,
        CASE
            WHEN m.cloudinary_public_id IS NOT NULL AND m.cloudinary_public_id != '' THEN 'file'
            WHEN m.external_url IS NOT NULL AND m.external_url != ''                 THEN 'link'
            ELSE 'file'
        END                         AS material_type,
        0                           AS file_size,
        0                           AS views,
        0                           AS downloads,
        (m.visibility = 'public')   AS is_public
    FROM materials m
    LEFT JOIN users u ON u.user_id = m.created_by
    $where
    ORDER BY m.created_at DESC
    LIMIT ? OFFSET ?
";

$fetchTypes  = $types . 'ii';
$fetchParams = array_merge($params, [$limit, $offset]);

$stmt = $conn->prepare($sql);
$stmt->bind_param($fetchTypes, ...$fetchParams);
$stmt->execute();
$result    = $stmt->get_result();
$materials = [];
while ($row = $result->fetch_assoc()) {
    $materials[] = $row;
}
$stmt->close();

// ── Stats ─────────────────────────────────────────────────────────────────
$statsConditions = [];
$statsParams     = [];
$statsTypes      = '';

if ($role === 'student') {
    $statsConditions[] = "(
        m.visibility = 'public'
        OR (
            m.visibility = 'group'
            AND EXISTS (
                SELECT 1 FROM material_targets mt
                JOIN group_members gm ON gm.group_id = mt.target_id
                WHERE mt.material_id = m.material_id
                  AND mt.target_type = 'group'
                  AND gm.student_id = ?
            )
        )
        OR (
            m.visibility = 'group'
            AND EXISTS (
                SELECT 1 FROM material_targets mt
                WHERE mt.material_id = m.material_id
                  AND mt.target_type = 'student'
                  AND mt.target_id = ?
            )
        )
    )";
    $statsParams[] = $userId;
    $statsParams[] = $userId;
    $statsTypes   .= 'ii';
    if ($uploaderRole !== '') {
        $statsConditions[] = 'u.role = ?';
        $statsParams[]     = $uploaderRole;
        $statsTypes       .= 's';
    }
} elseif ($role === 'teacher') {
    $statsConditions[] = 'm.created_by = ?';
    $statsParams[]     = $userId;
    $statsTypes       .= 'i';
}

$statsWhere = $statsConditions ? 'WHERE ' . implode(' AND ', $statsConditions) : '';
$statsJoin  = 'LEFT JOIN users u ON u.user_id = m.created_by';

$statsStmt = $conn->prepare(
    "SELECT COUNT(*) AS total_materials FROM materials m $statsJoin $statsWhere"
);
if ($statsTypes !== '') {
    $statsStmt->bind_param($statsTypes, ...$statsParams);
}
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();
$stats['total_views']        = 0;
$stats['total_downloads']    = 0;
$stats['storage_used_bytes'] = 0;
$statsStmt->close();

echo json_encode([
    'success'   => true,
    'materials' => $materials,
    'total'     => $totalRows,
    'page'      => $page,
    'pages'     => $totalPages,
    'stats'     => $stats,
]);
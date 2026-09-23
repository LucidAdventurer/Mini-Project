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
$category = trim($_GET['category'] ?? '');
$search   = trim($_GET['search']   ?? '');
$type     = trim($_GET['type']     ?? '');

$conditions = [];
$params     = [];

if ($role === 'student') {
    // Public materials OR group-targeted where student is a member OR student-targeted directly
    $conditions[] = "(
        m.visibility = 'public'
        OR (
            m.visibility = 'group'
            AND EXISTS (
                SELECT 1
                FROM material_targets mt
                JOIN group_members gm ON gm.group_id = mt.target_id
                WHERE mt.material_id = m.material_id
                AND mt.target_type = 'group'
                AND gm.student_id = ?
            )
        )
        OR (
            m.visibility = 'group'
            AND EXISTS (
                SELECT 1
                FROM material_targets mt
                WHERE mt.material_id = m.material_id
                AND mt.target_type = 'student'
                AND mt.target_id = ?
            )
        )
    )
    AND (m.available_from IS NULL OR CURRENT_DATE >= m.available_from)
    AND (m.available_until IS NULL OR CURRENT_DATE <= m.available_until)";
    $params[] = $userId;
    $params[] = $userId;
    $conditions[] = "u.role = 'teacher'";
} elseif ($role === 'teacher') {
    $conditions[] = 'm.created_by = ?';
    $params[]     = $userId;
}

if ($category !== '') {
    $conditions[] = 'm.category = ?';
    $params[]     = $category;
}
if ($search !== '') {
    $conditions[] = '(m.title LIKE ? OR m.description LIKE ?)';
    $like         = '%' . $search . '%';
    $params[]     = $like;
    $params[]     = $like;
}

if ($type !== '' && in_array($type, ['file', 'link'], true)) {
    if ($type === 'file') {
        $conditions[] = "(
            (m.cloudinary_public_id IS NOT NULL
             AND m.cloudinary_public_id != '')
            OR
            (
                m.external_url IS NOT NULL
                AND m.external_url != ''
                AND m.external_url NOT LIKE 'http://%'
                AND m.external_url NOT LIKE 'https://%'
            )
        )";
    } else {
        $conditions[] = "(
            m.external_url IS NOT NULL
            AND m.external_url != ''
            AND (
                m.external_url LIKE 'http://%'
                OR m.external_url LIKE 'https://%'
            )
        )";
    }
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

// ── Count ─────────────────────────────────────────────────────────────────
$countStmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM materials m
     LEFT JOIN users u ON u.user_id = m.created_by
     $where"
);
$countStmt->execute($params);

$countRow  = $countStmt->fetch(PDO::FETCH_ASSOC);
$totalRows = (int)($countRow['total'] ?? 0);

$countStmt = null;
$totalPages = max(1, (int) ceil($totalRows / $limit));

$progressSelect = "NULL AS user_progress, NULL AS is_completed";
$progressParams = [];

if ($role === 'student') {
    $progressSelect = "
        (
            SELECT mp.progress_percentage
            FROM material_progress mp
            WHERE mp.material_id = m.material_id
              AND mp.user_id = ?
        ) AS user_progress,
        (
            SELECT mp.completed
            FROM material_progress mp
            WHERE mp.material_id = m.material_id
              AND mp.user_id = ?
        ) AS is_completed
    ";

    $progressParams = [$userId, $userId];
}

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
        m.available_from,
        m.available_until,
        m.difficulty,
        m.created_at,
        m.created_by AS uploaded_by,
        u.full_name AS created_by_name,
        u.full_name AS uploaded_by_name,
        $progressSelect,
        CASE
            WHEN m.cloudinary_public_id IS NOT NULL
                AND m.cloudinary_public_id != '' THEN 'file'

            WHEN m.external_url IS NOT NULL
                AND m.external_url != ''
                AND m.external_url NOT LIKE 'http://%'
                AND m.external_url NOT LIKE 'https://%' THEN 'file'

            WHEN m.external_url IS NOT NULL
                AND m.external_url != '' THEN 'link'

            ELSE 'file'
        END AS material_type,
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

$fetchParams = array_merge(
    $progressParams,
    $params,
    [$limit, $offset]
);

$stmt = $conn->prepare($sql);
$stmt->execute($fetchParams);

$materials = [];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $row['material_id'] = (int) $row['material_id'];

    if ($row['user_progress'] !== null) {
        $row['user_progress'] = (float) $row['user_progress'];
    }

    if ($row['is_completed'] !== null) {
        $row['is_completed'] = pgBoolGuard($row['is_completed']);
    }

    $materials[] = $row;
}

$stmt = null;

// ── Stats ─────────────────────────────────────────────────────────────────
$statsConditions = [];
$statsParams     = [];

if ($role === 'student') {
    $statsConditions[] = "(
        m.visibility = 'public'
        OR (
            m.visibility = 'group'
            AND EXISTS (
                SELECT 1
                FROM material_targets mt
                JOIN group_members gm ON gm.group_id = mt.target_id
                WHERE mt.material_id = m.material_id
                AND mt.target_type = 'group'
                AND gm.student_id = ?
            )
        )
        OR (
            m.visibility = 'group'
            AND EXISTS (
                SELECT 1
                FROM material_targets mt
                WHERE mt.material_id = m.material_id
                AND mt.target_type = 'student'
                AND mt.target_id = ?
            )
        )
    )
    AND (m.available_from IS NULL OR CURRENT_DATE >= m.available_from)
    AND (m.available_until IS NULL OR CURRENT_DATE <= m.available_until)";
    $statsParams[] = $userId;
    $statsParams[] = $userId;
    $statsConditions[] = "u.role = 'teacher'";
} elseif ($role === 'teacher') {
    $statsConditions[] = 'm.created_by = ?';
    $statsParams[]     = $userId;
}

$statsWhere = $statsConditions ? 'WHERE ' . implode(' AND ', $statsConditions) : '';
$statsJoin  = 'LEFT JOIN users u ON u.user_id = m.created_by';

$statsStmt = $conn->prepare(
    "SELECT COUNT(*) AS total_materials
     FROM materials m
     $statsJoin
     $statsWhere"
);
$statsStmt->execute($statsParams);

$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$stats['total_materials']    = (int)($stats['total_materials'] ?? 0);
$stats['total_views']        = 0;
$stats['total_downloads']    = 0;
$stats['storage_used_bytes'] = 0;

$statsStmt = null;

echo json_encode([
    'success'   => true,
    'materials' => $materials,
    'total'     => $totalRows,
    'page'      => $page,
    'pages'     => $totalPages,
    'stats'     => $stats,
]);
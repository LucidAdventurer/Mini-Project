<?php
/* ========================================
 * GET RESOURCES API
 * File: api/resources/get-resources.php
 *
 * Returns paginated study materials.
 *
 * STUDENT RESTRICTION:
 *   Students may only see materials uploaded by users with role = 'teacher'.
 *   Admin-uploaded materials are never exposed to students regardless of any
 *   other filter parameter. The uploader_role param sent by the frontend is
 *   validated server-side — it cannot be overridden to 'admin'.
 * ======================================== */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

// ── Auth: student only ──
$currentUser = validateSession($conn, 'student');
$userId      = (int) $currentUser['user_id'];

// ── Input ──
$page     = max(1, (int) ($_GET['page']  ?? 1));
$limit    = min(50, max(1, (int) ($_GET['limit'] ?? 12)));
$offset   = ($page - 1) * $limit;
$category = trim($_GET['category'] ?? '');
$search   = trim($_GET['search']   ?? '');

// Allowed material types that students can filter by
$allowedTypes = ['pdf', 'video', 'document', 'link', 'image'];
$typeRaw  = trim($_GET['type'] ?? '');
$typeFilter = in_array($typeRaw, $allowedTypes, true) ? $typeRaw : '';

// ── uploader_role: students may ONLY see teacher-uploaded materials.
//    We ignore whatever the client sends and enforce 'teacher' server-side.
//    This means even a tampered request cannot expose admin uploads. ──
$uploaderRole = 'teacher';

// ── Build WHERE clauses ──
$conditions = [
    "u.role = ?",           // uploader must be a teacher
    "m.is_published = 1",   // only published materials
];
$types  = "s";   // for uploader role
$params = [$uploaderRole];

if ($category !== '') {
    $conditions[] = "m.category = ?";
    $types       .= "s";
    $params[]     = $category;
}

if ($typeFilter !== '') {
    $conditions[] = "m.material_type = ?";
    $types       .= "s";
    $params[]     = $typeFilter;
}

if ($search !== '') {
    $conditions[] = "(m.title LIKE ? OR m.description LIKE ?)";
    $types       .= "ss";
    $like         = '%' . $search . '%';
    $params[]     = $like;
    $params[]     = $like;
}

$where = implode(' AND ', $conditions);

// ── Count total matching rows ──
$countResult = safePreparedQuery(
    $conn,
    "SELECT COUNT(*) AS total
     FROM study_materials m
     JOIN users u ON u.user_id = m.uploaded_by
     WHERE $where",
    $types,
    $params
);

$totalRows = 0;
if ($countResult['success'] && $countResult['result']) {
    $row       = $countResult['result']->fetch_assoc();
    $totalRows = (int) ($row['total'] ?? 0);
    $countResult['result']->free();
}

$totalPages = $totalRows > 0 ? (int) ceil($totalRows / $limit) : 1;

// ── Fetch materials ──
$matResult = safePreparedQuery(
    $conn,
    "SELECT
         m.material_id,
         m.title,
         m.description,
         m.material_type,
         m.category,
         m.difficulty,
         m.file_path,
         m.external_url,
         m.file_size,
         m.estimated_time_minutes,
         m.views,
         m.downloads,
         m.created_at,
         u.full_name   AS uploaded_by_name,
         -- Student's personal progress on this material (nullable)
         sp.progress_percent AS user_progress,
         sp.is_completed
     FROM study_materials m
     JOIN users u ON u.user_id = m.uploaded_by
     LEFT JOIN student_material_progress sp
            ON sp.material_id = m.material_id
           AND sp.student_id  = ?
     WHERE $where
     ORDER BY m.created_at DESC
     LIMIT ? OFFSET ?",
    "i" . $types . "ii",
    array_merge([$userId], $params, [$limit, $offset])
);

$materials = [];
if ($matResult['success'] && $matResult['result']) {
    while ($row = $matResult['result']->fetch_assoc()) {
        // Never expose the raw server file path to the client
        unset($row['file_path']);
        $materials[] = $row;
    }
    $matResult['result']->free();
}

// ── Aggregate stats (teacher-uploaded only) ──
$statsResult = safePreparedQuery(
    $conn,
    "SELECT
         COUNT(m.material_id)  AS total_materials,
         COALESCE(SUM(m.views), 0)     AS total_views,
         COALESCE(SUM(m.downloads), 0) AS total_downloads,
         COALESCE(SUM(m.file_size), 0) AS storage_used_bytes
     FROM study_materials m
     JOIN users u ON u.user_id = m.uploaded_by
     WHERE u.role = ? AND m.is_published = 1",
    "s",
    ['teacher']
);

$stats = ['total_materials' => 0, 'total_views' => 0, 'total_downloads' => 0, 'storage_used_bytes' => 0];
if ($statsResult['success'] && $statsResult['result']) {
    $row   = $statsResult['result']->fetch_assoc();
    $stats = $row ?: $stats;
    $statsResult['result']->free();
}

echo json_encode([
    'success'   => true,
    'materials' => $materials,
    'total'     => $totalRows,
    'page'      => $page,
    'pages'     => $totalPages,
    'stats'     => $stats,
]);

<?php
// ============================================================
// api/resources/get-resources.php
//
// Returns paginated materials.
// Works for logged-in students (with progress) AND guests
// (public materials only, no progress).
//
// Table: materials(material_id, title, description, created_by,
//   visibility, cloudinary_public_id, external_url, category,
//   difficulty, created_at)
// Table: material_progress(material_id, user_id,
//   progress_percentage, completed, last_accessed)
// Table: material_targets(id, material_id, target_type, target_id)
//
// GET ?category=aptitude|technical|coding|reasoning|english|general
//     ?search=keyword
//     ?page=1&limit=20
//
// Returns {
//   success: bool,
//   materials: [...],
//   total: int, page: int, pages: int
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

// ── Optional session: guests get null ────────────────────────────────────
$currentUser = optionalSession($conn);
$userId      = $currentUser ? (int)$currentUser['user_id'] : null;
$isGuest     = $userId === null;

// ── Query params ──────────────────────────────────────────────────────────
$search   = trim($_GET['search']   ?? '');
$category = trim($_GET['category'] ?? '');
$page     = max(1, (int)($_GET['page']  ?? 1));
$limit    = min(50, max(1, (int)($_GET['limit'] ?? 20)));
$offset   = ($page - 1) * $limit;

$allowedCategories = ['aptitude', 'technical', 'coding', 'reasoning', 'english', 'general'];

// ── Access clause ─────────────────────────────────────────────────────────
// Guests: public only.
// Students: public OR group-targeted at them or their groups.
if ($isGuest) {
    $accessClause = "m.visibility = 'public'";
} else {
    $accessClause = "(
        m.visibility = 'public'
        OR (m.visibility = 'group' AND EXISTS (
            SELECT 1 FROM material_targets mt
            WHERE mt.material_id = m.material_id
              AND (
                  (mt.target_type = 'student' AND mt.target_id = {$userId})
                  OR (mt.target_type = 'group' AND mt.target_id IN (
                      SELECT gm.group_id FROM group_members gm WHERE gm.student_id = {$userId}
                  ))
              )
        ))
    )";
}

// ── Build WHERE conditions ─────────────────────────────────────────────────
$conditions = [$accessClause];
$params     = [];
$types      = '';

if ($search !== '') {
    $conditions[] = '(m.title LIKE ? OR m.description LIKE ?)';
    $like         = '%' . $search . '%';
    $params[]     = $like;
    $params[]     = $like;
    $types       .= 'ss';
}

if ($category !== '' && in_array($category, $allowedCategories, true)) {
    $conditions[] = 'm.category = ?';
    $params[]     = $category;
    $types       .= 's';
}

$where = implode(' AND ', $conditions);

// ── Total count for pagination ─────────────────────────────────────────────
$countRes = safePreparedQuery($conn,
    "SELECT COUNT(*) AS total FROM materials m WHERE {$where}",
    $types ?: '', $params ?: []
);
$total = 0;
if ($countRes['success'] && $countRes['result']) {
    $row   = $countRes['result']->fetch_assoc();
    $total = (int)($row['total'] ?? 0);
    $countRes['result']->free();
}

// ── Fetch paginated rows ───────────────────────────────────────────────────
// Progress subqueries are included only for logged-in users.
if ($isGuest) {
    $selectProgress = 'NULL AS user_progress, NULL AS is_completed';
    $listTypes      = $types . 'ii';
    $listParams     = array_merge($params, [$limit, $offset]);
} else {
    $selectProgress = "(SELECT mp.progress_percentage FROM material_progress mp
                        WHERE mp.material_id = m.material_id AND mp.user_id = ?) AS user_progress,
                       (SELECT mp.completed FROM material_progress mp
                        WHERE mp.material_id = m.material_id AND mp.user_id = ?) AS is_completed";
    $listTypes      = 'ii' . $types . 'ii';
    $listParams     = array_merge([$userId, $userId], $params, [$limit, $offset]);
}

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
        {$selectProgress}
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
        // Derive material_type from stored data — no material_type column in schema
        $materialType = 'document';
        if (!empty($row['external_url'])) {
            $materialType = 'link';
        } elseif (!empty($row['cloudinary_public_id'])) {
            $materialType = 'file';
        }

        $materials[] = [
            'material_id'          => (int)$row['material_id'],
            'title'                => $row['title'],
            'description'          => $row['description'],
            'category'             => $row['category'],
            'difficulty'           => $row['difficulty'],
            'material_type'        => $materialType,
            'cloudinary_public_id' => $row['cloudinary_public_id'],
            'external_url'         => $row['external_url'],
            'created_at'           => $row['created_at'],
            'uploaded_by_name'     => $row['uploaded_by_name'],
            'user_progress'        => $row['user_progress'] !== null ? (int)$row['user_progress'] : null,
            'is_completed'         => $row['is_completed']  !== null ? (bool)$row['is_completed']  : null,
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
]);

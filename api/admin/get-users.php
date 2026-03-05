<?php
// ============================================================
// api/admin/get-users.php
//
// Returns paginated user list for admin user management.
//
// GET ?role=all|student|teacher|admin  (default: all)
//     ?search=keyword                  (name, email, reg_no)
//     ?page=1&limit=20
//     ?status=active|inactive|unverified
//
// Returns {
//   success, users:[...], total, page, pages,
//   counts: { all, student, teacher, admin }
// }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

validateSession($conn, 'admin');

// ── Query params ──
$role   = trim($_GET['role']   ?? 'all');
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$page   = max(1, (int)($_GET['page']  ?? 1));
$limit  = min(100, max(1, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

$allowedRoles   = ['all', 'student', 'teacher', 'admin'];
$allowedStatuses = ['active', 'inactive', 'unverified'];

if (!in_array($role, $allowedRoles, true))     $role   = 'all';
if (!in_array($status, $allowedStatuses, true)) $status = '';

// ── Build WHERE ──
$conditions = [];
$params     = [];
$types      = '';

if ($role !== 'all') {
    $conditions[] = 'u.user_type = ?';
    $params[]     = $role;
    $types       .= 's';
}

if ($search !== '') {
    $conditions[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.registration_number LIKE ?)';
    $like         = '%' . $search . '%';
    $params[]     = $like;
    $params[]     = $like;
    $params[]     = $like;
    $types       .= 'sss';
}

if ($status === 'active') {
    $conditions[] = 'u.is_active = 1 AND u.is_verified = 1';
} elseif ($status === 'inactive') {
    $conditions[] = 'u.is_active = 0';
} elseif ($status === 'unverified') {
    $conditions[] = 'u.is_verified = 0 AND u.is_active = 1';
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

// ── Tab counts (all / student / teacher / admin) ──
$counts = ['all' => 0, 'student' => 0, 'teacher' => 0, 'admin' => 0];

$searchConditions = [];
$searchParams     = [];
$searchTypes      = '';

if ($search !== '') {
    $searchConditions[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.registration_number LIKE ?)';
    $like               = '%' . $search . '%';
    $searchParams[]     = $like;
    $searchParams[]     = $like;
    $searchParams[]     = $like;
    $searchTypes       .= 'sss';
}

$searchWhere = $searchConditions ? 'WHERE ' . implode(' AND ', $searchConditions) : '';

$rc = safePreparedQuery($conn,
    "SELECT user_type, COUNT(*) AS cnt FROM users u $searchWhere GROUP BY user_type",
    $searchTypes, $searchParams
);
if ($rc['success'] && $rc['result']) {
    while ($row = $rc['result']->fetch_assoc()) {
        $counts[$row['user_type']] = (int)$row['cnt'];
        $counts['all'] += (int)$row['cnt'];
    }
    $rc['result']->free();
}

// ── Total count for current filter ──
$total = 0;
$rc = safePreparedQuery($conn,
    "SELECT COUNT(*) AS cnt FROM users u $where",
    $types, $params
);
if ($rc['success'] && $rc['result']) {
    $row   = $rc['result']->fetch_assoc();
    $total = (int)($row['cnt'] ?? 0);
    $rc['result']->free();
}

// ── Fetch page ──
$listTypes  = $types . 'ii';
$listParams = array_merge($params, [$limit, $offset]);

$r = safePreparedQuery($conn,
    "SELECT
        u.user_id, u.full_name, u.email, u.user_type,
        u.department, u.registration_number,
        u.is_verified, u.is_active,
        u.created_at, u.last_login, u.profile_image,
        (SELECT COUNT(*) FROM assessment_attempts aa
         WHERE aa.user_id = u.user_id AND aa.status = 'completed') AS attempts_count
     FROM users u
     $where
     ORDER BY
        CASE WHEN u.last_login IS NOT NULL THEN 0 ELSE 1 END,
        u.last_login DESC,
        u.created_at DESC
     LIMIT ? OFFSET ?",
    $listTypes, $listParams
);

$users = [];
if ($r['success'] && $r['result']) {
    while ($row = $r['result']->fetch_assoc()) {
        $users[] = [
            'user_id'             => (int)$row['user_id'],
            'full_name'           => $row['full_name'],
            'email'               => $row['email'],
            'user_type'           => $row['user_type'],
            'department'          => $row['department'] ?? '',
            'registration_number' => $row['registration_number'] ?? '',
            'is_verified'         => (bool)$row['is_verified'],
            'is_active'           => (bool)$row['is_active'],
            'created_at'          => $row['created_at'],
            'last_login'          => $row['last_login'],
            'last_login_ago'      => $row['last_login'] ? timeAgo($row['last_login']) : 'Never',
            'profile_image'       => $row['profile_image'] ?? '',
            'attempts_count'      => (int)$row['attempts_count'],
            'initials'            => getInitials($row['full_name']),
            'avatar_color'        => getAvatarColor($row['user_type']),
        ];
    }
    $r['result']->free();
}

echo json_encode([
    'success' => true,
    'users'   => $users,
    'total'   => $total,
    'page'    => $page,
    'pages'   => max(1, (int)ceil($total / $limit)),
    'counts'  => $counts,
]);

// ── Helpers ──
function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)      return 'just now';
    if ($diff < 3600)    return floor($diff / 60) . ' min ago';
    if ($diff < 86400)   return floor($diff / 3600) . ' hr ago';
    if ($diff < 2592000) return floor($diff / 86400) . ' days ago';
    return date('M j, Y', strtotime($datetime));
}

function getInitials(string $name): string {
    $parts = array_filter(explode(' ', trim($name)));
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
    }
    return strtoupper(substr($name, 0, 2));
}

function getAvatarColor(string $role): string {
    return match($role) {
        'teacher' => 'green',
        'admin'   => 'red',
        default   => 'blue',
    };
}

<?php
// ============================================================
// api/admin/get-users.php
//
// Returns paginated user list.
// `role` replaces `user_type`; attempts status 'submitted' replaces 'completed'
//
// GET ?role=all|student|teacher|admin
//     ?search=keyword
//     ?page=1&limit=20
//     ?status=active|inactive|unverified
// ============================================================
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';
header('Content-Type: application/json');
validateSession($conn, 'admin');

$role   = trim($_GET['role']   ?? 'all');
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$page   = max(1, (int)($_GET['page']  ?? 1));
$limit  = min(100, max(1, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

if (!in_array($role, ['all','student','teacher','admin'], true))       $role   = 'all';
if (!in_array($status, ['active','inactive','unverified'], true))      $status = '';

// WHERE — `role` replaces `user_type`
$conditions = []; $params = []; $types = '';
if ($role !== 'all') { $conditions[] = 'u.role = ?'; $params[] = $role; $types .= 's'; }
if ($search !== '') {
    $like = '%'.$search.'%';
    $conditions[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.registration_number LIKE ?)';
    $params[] = $like; $params[] = $like; $params[] = $like; $types .= 'sss';
}
if ($status === 'active')       $conditions[] = 'u.is_active=1 AND u.is_verified=1';
elseif ($status === 'inactive')  $conditions[] = 'u.is_active=0';
elseif ($status === 'unverified')$conditions[] = 'u.is_verified=0 AND u.is_active=1';
$where = $conditions ? 'WHERE '.implode(' AND ',$conditions) : '';

// Tab counts — GROUP BY role
$counts = ['all'=>0,'student'=>0,'teacher'=>0,'admin'=>0];
$sCond = []; $sP = []; $sT = '';
if ($search !== '') {
    $like = '%'.$search.'%';
    $sCond[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.registration_number LIKE ?)';
    $sP[] = $like; $sP[] = $like; $sP[] = $like; $sT .= 'sss';
}
$sWhere = $sCond ? 'WHERE '.implode(' AND ',$sCond) : '';
$rc = safePreparedQuery($conn, "SELECT role, COUNT(*) AS cnt FROM users u $sWhere GROUP BY role", $sT, $sP);
if ($rc['success'] && $rc['result']) {
    while ($row = $rc['result']->fetch_assoc()) {
        if (isset($counts[$row['role']])) $counts[$row['role']] = (int)$row['cnt'];
        $counts['all'] += (int)$row['cnt'];
    }
    $rc['result']->free();
}

// Total
$total = 0;
$rc = safePreparedQuery($conn, "SELECT COUNT(*) AS cnt FROM users u $where", $types, $params);
if ($rc['success'] && $rc['result']) { $total=(int)($rc['result']->fetch_assoc()['cnt']??0); $rc['result']->free(); }

// Fetch — `role` replaces `user_type`; attempts status 'submitted' replaces 'completed'
$r = safePreparedQuery($conn,
    "SELECT u.user_id, u.full_name, u.email, u.role,
            u.department, u.registration_number,
            u.is_verified, u.is_active, u.created_at, u.last_login, u.profile_image,
            (SELECT COUNT(*) FROM assessment_attempts aa
             WHERE aa.user_id=u.user_id AND aa.status='submitted') AS attempts_count
     FROM users u $where
     ORDER BY CASE WHEN u.last_login IS NOT NULL THEN 0 ELSE 1 END, u.last_login DESC, u.created_at DESC
     LIMIT ? OFFSET ?",
    $types.'ii', array_merge($params, [$limit, $offset]));

$users = [];
if ($r['success'] && $r['result']) {
    while ($row = $r['result']->fetch_assoc()) {
        $users[] = [
            'user_id'             => (int)$row['user_id'],
            'full_name'           => $row['full_name'],
            'email'               => $row['email'],
            'role'                => $row['role'],
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
            'avatar_color'        => getAvatarColor($row['role']),
        ];
    }
    $r['result']->free();
}

echo json_encode(['success'=>true,'users'=>$users,'total'=>$total,'page'=>$page,
                  'pages'=>max(1,(int)ceil($total/$limit)),'counts'=>$counts]);

function timeAgo(string $dt): string {
    $d = time() - strtotime($dt);
    if ($d<60) return 'just now'; if ($d<3600) return floor($d/60).' min ago';
    if ($d<86400) return floor($d/3600).' hr ago'; if ($d<2592000) return floor($d/86400).' days ago';
    return date('M j, Y', strtotime($dt));
}
function getInitials(string $name): string {
    $p = array_filter(explode(' ', trim($name)));
    return count($p)>=2 ? strtoupper(substr($p[0],0,1).substr(end($p),0,1)) : strtoupper(substr($name,0,2));
}
function getAvatarColor(string $role): string {
    return match($role) { 'teacher'=>'green','admin'=>'red',default=>'blue' };
}
<?php
// ============================================================
// api/admin/get-logs.php
// Admin-only. Paginated login activity log.
//
// GET ?search=keyword
//     ?user_id=int
//     ?date_from=YYYY-MM-DD
//     ?date_to=YYYY-MM-DD
//     ?page=1&limit=50
//
// Returns {
//   success, logs:[...], total, page, pages,
//   stats:{ total_logins, failed_logins, unique_ips }
// }
//
// Note: audit_logs and email_queue do not exist in this schema.
// Only login_activity is available.
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

validateSession($conn, 'admin');

$search    = trim($_GET['search']    ?? '');
$filterUid = (int)($_GET['user_id']  ?? 0);
$dateFrom  = trim($_GET['date_from'] ?? '');
$dateTo    = trim($_GET['date_to']   ?? '');
$page      = max(1, (int)($_GET['page']  ?? 1));
$limit     = min(100, max(1, (int)($_GET['limit'] ?? 50)));
$offset    = ($page - 1) * $limit;

$dateFromSql = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? $dateFrom . ' 00:00:00' : '';
$dateToSql   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)   ? $dateTo   . ' 23:59:59' : '';

// Global stats
$stats = ['total_logins' => 0, 'failed_logins' => 0, 'unique_ips' => 0];
$r = safePreparedQuery($conn,
    "SELECT COUNT(*) AS tl, SUM(is_success = 0) AS fl, COUNT(DISTINCT ip_address) AS ui
     FROM login_activity", "", []);
if ($r['success'] && $r['result']) {
    $row = $r['result']->fetch_assoc();
    $stats['total_logins']  = (int)($row['tl'] ?? 0);
    $stats['failed_logins'] = (int)($row['fl'] ?? 0);
    $stats['unique_ips']    = (int)($row['ui'] ?? 0);
    $r['result']->free();
}

// WHERE builder — login_activity.user_id is NULLABLE
$c = []; $p = []; $t = '';
if ($search !== '') {
    $like = '%' . $search . '%';
    $c[]  = "(u.full_name LIKE ? OR u.email LIKE ? OR la.ip_address LIKE ? OR la.failure_reason LIKE ?)";
    array_push($p, $like, $like, $like, $like);
    $t .= 'ssss';
}
if ($filterUid > 0) { $c[] = "la.user_id = ?";    $p[] = $filterUid; $t .= 'i'; }
if ($dateFromSql)    { $c[] = "la.created_at >= ?"; $p[] = $dateFromSql; $t .= 's'; }
if ($dateToSql)      { $c[] = "la.created_at <= ?"; $p[] = $dateToSql;   $t .= 's'; }
$where = $c ? implode(' AND ', $c) : '1=1';

// Count
$total = 0;
$rc = safePreparedQuery($conn,
    "SELECT COUNT(*) AS c FROM login_activity la LEFT JOIN users u ON u.user_id = la.user_id WHERE $where",
    $t, $p);
if ($rc['success'] && $rc['result']) {
    $total = (int)($rc['result']->fetch_assoc()['c'] ?? 0);
    $rc['result']->free();
}

// Fetch — LEFT JOIN (user_id nullable); u.role replaces u.user_type
$r = safePreparedQuery($conn,
    "SELECT la.log_id, la.user_id,
            u.full_name AS user_name, u.email AS user_email, u.role AS user_role,
            la.ip_address, la.user_agent, la.is_success, la.failure_reason, la.created_at,
            IF(la.is_success = 1, 'success', 'error') AS level,
            IF(la.is_success = 1,
               CONCAT('Login successful — ', COALESCE(u.email, 'unknown')),
               CONCAT('Login failed — ', COALESCE(la.failure_reason, 'Unknown reason'))
            ) AS message
     FROM login_activity la
     LEFT JOIN users u ON u.user_id = la.user_id
     WHERE $where
     ORDER BY la.created_at DESC
     LIMIT ? OFFSET ?",
    $t . 'ii', array_merge($p, [$limit, $offset]));

$logs = [];
if ($r['success'] && $r['result']) {
    while ($row = $r['result']->fetch_assoc()) {
        $logs[] = [
            'log_id'         => (int)$row['log_id'],
            'log_type'       => 'login',
            'user_id'        => $row['user_id'] !== null ? (int)$row['user_id'] : null,
            'user_name'      => $row['user_name'] ?? '—',
            'email'          => $row['user_email'] ?? '—',
            'user_role'      => $row['user_role'],
            'action'         => 'Login — ' . ($row['is_success'] ? 'Success' : 'Failed'),
            'message'        => $row['message'],
            'level'          => $row['level'],
            'entity_type'    => null,
            'entity_id'      => null,
            'ip_address'     => $row['ip_address'],
            'user_agent'     => $row['user_agent'],
            'created_at'     => $row['created_at'],
            'old_values'     => null,
            'new_values'     => null,
            'is_success'     => $row['is_success'] !== null ? (bool)$row['is_success'] : null,
            'failure_reason' => $row['failure_reason'],
        ];
    }
    $r['result']->free();
}

echo json_encode([
    'success' => true,
    'logs'    => $logs,
    'total'   => $total,
    'page'    => $page,
    'pages'   => (int) ceil($total / max(1, $limit)),
    'stats'   => $stats,
]);
<?php
// ============================================================
// api/admin/get-logs.php
// Admin-only. Paginated logs from audit_logs + login_activity.
//
// GET ?type=audit|login|all  (default: all)
//     ?search=keyword
//     ?user_id=int
//     ?date_from=YYYY-MM-DD
//     ?date_to=YYYY-MM-DD
//     ?page=1&limit=50
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

validateSession($conn, 'admin');

$type      = trim($_GET['type']      ?? 'all');
$search    = trim($_GET['search']    ?? '');
$filterUid = (int)($_GET['user_id']  ?? 0);
$dateFrom  = trim($_GET['date_from'] ?? '');
$dateTo    = trim($_GET['date_to']   ?? '');
$page      = max(1, (int)($_GET['page']  ?? 1));
$limit     = min(100, max(1, (int)($_GET['limit'] ?? 50)));
$offset    = ($page - 1) * $limit;

if (!in_array($type, ['audit', 'login', 'all'], true)) $type = 'all';

$dateFromSql = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? $dateFrom . ' 00:00:00' : '';
$dateToSql   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)   ? $dateTo   . ' 23:59:59' : '';

// Global stats
$stats = ['total_actions' => 0, 'total_logins' => 0, 'failed_logins' => 0, 'unique_ips' => 0];
$r = safePreparedQuery($conn, "SELECT COUNT(*) AS c FROM audit_logs", "", []);
if ($r['success'] && $r['result']) { $stats['total_actions'] = (int)($r['result']->fetch_assoc()['c'] ?? 0); $r['result']->free(); }
$r = safePreparedQuery($conn, "SELECT COUNT(*) AS tl, SUM(is_success=0) AS fl, COUNT(DISTINCT ip_address) AS ui FROM login_activity", "", []);
if ($r['success'] && $r['result']) {
    $row = $r['result']->fetch_assoc();
    $stats['total_logins'] = (int)($row['tl'] ?? 0);
    $stats['failed_logins'] = (int)($row['fl'] ?? 0);
    $stats['unique_ips'] = (int)($row['ui'] ?? 0);
    $r['result']->free();
}

function buildAuditWhere(string $search, int $uid, string $from, string $to): array {
    $c = []; $p = []; $t = '';
    if ($search !== '') { $like = '%'.$search.'%'; $c[] = "(al.action LIKE ? OR al.entity_type LIKE ? OR u.full_name LIKE ? OR al.ip_address LIKE ?)"; array_push($p,$like,$like,$like,$like); $t .= 'ssss'; }
    if ($uid > 0) { $c[] = "al.user_id = ?";    $p[] = $uid;  $t .= 'i'; }
    if ($from)    { $c[] = "al.created_at >= ?"; $p[] = $from; $t .= 's'; }
    if ($to)      { $c[] = "al.created_at <= ?"; $p[] = $to;   $t .= 's'; }
    return [$c ? implode(' AND ', $c) : '1=1', $p, $t];
}

function buildLoginWhere(string $search, int $uid, string $from, string $to): array {
    $c = []; $p = []; $t = '';
    if ($search !== '') { $like = '%'.$search.'%'; $c[] = "(u.full_name LIKE ? OR la.ip_address LIKE ? OR la.failure_reason LIKE ?)"; array_push($p,$like,$like,$like); $t .= 'sss'; }
    if ($uid > 0) { $c[] = "la.user_id = ?";    $p[] = $uid;  $t .= 'i'; }
    if ($from)    { $c[] = "la.created_at >= ?"; $p[] = $from; $t .= 's'; }
    if ($to)      { $c[] = "la.created_at <= ?"; $p[] = $to;   $t .= 's'; }
    return [$c ? implode(' AND ', $c) : '1=1', $p, $t];
}

// Count
$total = 0;
if ($type !== 'login') {
    [$aw,$ap,$at] = buildAuditWhere($search, $filterUid, $dateFromSql, $dateToSql);
    $rc = safePreparedQuery($conn, "SELECT COUNT(*) AS c FROM audit_logs al LEFT JOIN users u ON u.user_id = al.user_id WHERE $aw", $at, $ap);
    if ($rc['success'] && $rc['result']) { $total += (int)($rc['result']->fetch_assoc()['c'] ?? 0); $rc['result']->free(); }
}
if ($type !== 'audit') {
    [$lw,$lp,$lt] = buildLoginWhere($search, $filterUid, $dateFromSql, $dateToSql);
    $rc = safePreparedQuery($conn, "SELECT COUNT(*) AS c FROM login_activity la LEFT JOIN users u ON u.user_id = la.user_id WHERE $lw", $lt, $lp);
    if ($rc['success'] && $rc['result']) { $total += (int)($rc['result']->fetch_assoc()['c'] ?? 0); $rc['result']->free(); }
}

// Fetch
$rows = [];
if ($type !== 'login') {
    [$aw,$ap,$at] = buildAuditWhere($search, $filterUid, $dateFromSql, $dateToSql);
    $fo = ($type === 'audit') ? $offset : 0;
    $r = safePreparedQuery($conn,
        "SELECT al.log_id, 'audit' AS log_type, al.user_id,
                u.full_name AS user_name, u.user_type AS user_role,
                al.action, al.entity_type, al.entity_id,
                al.ip_address, al.user_agent, al.created_at,
                al.old_values, al.new_values,
                NULL AS is_success, NULL AS failure_reason
         FROM audit_logs al LEFT JOIN users u ON u.user_id = al.user_id
         WHERE $aw ORDER BY al.created_at DESC LIMIT ? OFFSET ?",
        $at.'ii', array_merge($ap, [$limit, $fo]));
    if ($r['success'] && $r['result']) { while ($row = $r['result']->fetch_assoc()) $rows[] = $row; $r['result']->free(); }
}
if ($type !== 'audit') {
    [$lw,$lp,$lt] = buildLoginWhere($search, $filterUid, $dateFromSql, $dateToSql);
    $fo = ($type === 'login') ? $offset : 0;
    $r = safePreparedQuery($conn,
        "SELECT la.log_id, 'login' AS log_type, la.user_id,
                u.full_name AS user_name, u.user_type AS user_role,
                CONCAT('Login — ', IF(la.is_success,'Success','Failed')) AS action,
                NULL AS entity_type, NULL AS entity_id,
                la.ip_address, la.user_agent, la.created_at,
                NULL AS old_values, NULL AS new_values,
                la.is_success, la.failure_reason
         FROM login_activity la LEFT JOIN users u ON u.user_id = la.user_id
         WHERE $lw ORDER BY la.created_at DESC LIMIT ? OFFSET ?",
        $lt.'ii', array_merge($lp, [$limit, $fo]));
    if ($r['success'] && $r['result']) { while ($row = $r['result']->fetch_assoc()) $rows[] = $row; $r['result']->free(); }
}

if ($type === 'all') {
    usort($rows, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
    $rows = array_slice($rows, $offset, $limit);
}

$logs = array_map(fn($row) => [
    'log_id'         => (int)$row['log_id'],
    'log_type'       => $row['log_type'],
    'user_id'        => $row['user_id'] !== null ? (int)$row['user_id'] : null,
    'user_name'      => $row['user_name'] ?? 'System',
    'user_role'      => $row['user_role'],
    'action'         => $row['action'],
    'entity_type'    => $row['entity_type'],
    'entity_id'      => $row['entity_id'] !== null ? (int)$row['entity_id'] : null,
    'ip_address'     => $row['ip_address'],
    'user_agent'     => $row['user_agent'],
    'created_at'     => $row['created_at'],
    'old_values'     => $row['old_values'] ? json_decode($row['old_values'], true) : null,
    'new_values'     => $row['new_values'] ? json_decode($row['new_values'], true) : null,
    'is_success'     => $row['is_success'] !== null ? (bool)$row['is_success'] : null,
    'failure_reason' => $row['failure_reason'],
], $rows);

echo json_encode(['success'=>true,'logs'=>$logs,'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/max(1,$limit)),'stats'=>$stats]);
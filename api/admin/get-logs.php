<?php
// ============================================================
// api/admin/get-logs.php
//
// Returns login activity, audit logs, or email queue.
//
// GET ?type=login|audit|email   (default: login)
//     ?page=1&limit=30
//     ?search=keyword
//
// Returns { success, logs:[...], total, page, pages }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

validateSession($conn, 'admin');

$type   = trim($_GET['type']   ?? 'login');
$search = trim($_GET['search'] ?? '');
$page   = max(1, (int)($_GET['page']  ?? 1));
$limit  = min(100, max(1, (int)($_GET['limit'] ?? 30)));
$offset = ($page - 1) * $limit;

$logs  = [];
$total = 0;

if ($type === 'login') {
    // ── Login activity ──
    $conditions = [];
    $params     = [];
    $types      = '';

    if ($search !== '') {
        $conditions[] = '(u.email LIKE ? OR la.ip_address LIKE ? OR la.failure_reason LIKE ?)';
        $like         = '%' . $search . '%';
        $params[]     = $like;
        $params[]     = $like;
        $params[]     = $like;
        $types       .= 'sss';
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $rc = safePreparedQuery($conn,
        "SELECT COUNT(*) AS cnt
         FROM login_activity la LEFT JOIN users u ON u.user_id = la.user_id $where",
        $types, $params
    );
    if ($rc['success'] && $rc['result']) {
        $row   = $rc['result']->fetch_assoc();
        $total = (int)($row['cnt'] ?? 0);
        $rc['result']->free();
    }

    $listTypes  = $types . 'ii';
    $listParams = array_merge($params, [$limit, $offset]);

    $r = safePreparedQuery($conn,
        "SELECT la.log_id, la.ip_address, la.user_agent,
                la.is_success, la.failure_reason, la.created_at,
                u.email, u.full_name, u.user_type
         FROM login_activity la
         LEFT JOIN users u ON u.user_id = la.user_id
         $where
         ORDER BY la.created_at DESC
         LIMIT ? OFFSET ?",
        $listTypes, $listParams
    );

    if ($r['success'] && $r['result']) {
        while ($row = $r['result']->fetch_assoc()) {
            $logs[] = [
                'id'             => (int)$row['log_id'],
                'email'          => $row['email'] ?? 'unknown',
                'full_name'      => $row['full_name'] ?? '',
                'user_type'      => $row['user_type'] ?? '',
                'ip_address'     => $row['ip_address'],
                'is_success'     => (bool)$row['is_success'],
                'failure_reason' => $row['failure_reason'],
                'created_at'     => $row['created_at'],
                'time_ago'       => timeAgo($row['created_at']),
                'level'          => $row['is_success'] ? 'success' : 'error',
                'message'        => $row['is_success']
                    ? 'Login successful'
                    : 'Login failed: ' . ($row['failure_reason'] ?? 'unknown'),
            ];
        }
        $r['result']->free();
    }

} elseif ($type === 'audit') {
    // ── Audit logs ──
    $conditions = [];
    $params     = [];
    $types      = '';

    if ($search !== '') {
        $conditions[] = '(al.action LIKE ? OR al.entity_type LIKE ? OR u.email LIKE ?)';
        $like         = '%' . $search . '%';
        $params[]     = $like;
        $params[]     = $like;
        $params[]     = $like;
        $types       .= 'sss';
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $rc = safePreparedQuery($conn,
        "SELECT COUNT(*) AS cnt FROM audit_logs al LEFT JOIN users u ON u.user_id = al.user_id $where",
        $types, $params
    );
    if ($rc['success'] && $rc['result']) {
        $row   = $rc['result']->fetch_assoc();
        $total = (int)($row['cnt'] ?? 0);
        $rc['result']->free();
    }

    $listTypes  = $types . 'ii';
    $listParams = array_merge($params, [$limit, $offset]);

    $r = safePreparedQuery($conn,
        "SELECT al.log_id, al.action, al.entity_type, al.entity_id,
                al.ip_address, al.created_at,
                u.email, u.full_name
         FROM audit_logs al
         LEFT JOIN users u ON u.user_id = al.user_id
         $where
         ORDER BY al.created_at DESC
         LIMIT ? OFFSET ?",
        $listTypes, $listParams
    );

    if ($r['success'] && $r['result']) {
        while ($row = $r['result']->fetch_assoc()) {
            $logs[] = [
                'id'          => (int)$row['log_id'],
                'action'      => $row['action'],
                'entity_type' => $row['entity_type'],
                'entity_id'   => $row['entity_id'],
                'actor_email' => $row['email'] ?? 'system',
                'actor_name'  => $row['full_name'] ?? 'System',
                'ip_address'  => $row['ip_address'],
                'created_at'  => $row['created_at'],
                'time_ago'    => timeAgo($row['created_at']),
                'level'       => 'info',
                'message'     => ucwords(str_replace('_', ' ', $row['action']))
                    . ($row['entity_type'] ? ' on ' . $row['entity_type'] : ''),
            ];
        }
        $r['result']->free();
    }

} elseif ($type === 'email') {
    // ── Email queue ──
    $conditions = [];
    $params     = [];
    $types      = '';

    if ($search !== '') {
        $conditions[] = '(recipient_email LIKE ? OR subject LIKE ?)';
        $like         = '%' . $search . '%';
        $params[]     = $like;
        $params[]     = $like;
        $types       .= 'ss';
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $rc = safePreparedQuery($conn,
        "SELECT COUNT(*) AS cnt FROM email_queue $where",
        $types, $params
    );
    if ($rc['success'] && $rc['result']) {
        $row   = $rc['result']->fetch_assoc();
        $total = (int)($row['cnt'] ?? 0);
        $rc['result']->free();
    }

    $listTypes  = $types . 'ii';
    $listParams = array_merge($params, [$limit, $offset]);

    $r = safePreparedQuery($conn,
        "SELECT email_id, recipient_email, recipient_name, subject,
                email_type, status, attempts, error_message, sent_at, created_at
         FROM email_queue $where
         ORDER BY created_at DESC
         LIMIT ? OFFSET ?",
        $listTypes, $listParams
    );

    if ($r['success'] && $r['result']) {
        while ($row = $r['result']->fetch_assoc()) {
            $logs[] = [
                'id'              => (int)$row['email_id'],
                'recipient_email' => $row['recipient_email'],
                'recipient_name'  => $row['recipient_name'],
                'subject'         => $row['subject'],
                'email_type'      => $row['email_type'],
                'status'          => $row['status'],
                'attempts'        => (int)$row['attempts'],
                'error_message'   => $row['error_message'],
                'sent_at'         => $row['sent_at'],
                'created_at'      => $row['created_at'],
                'time_ago'        => timeAgo($row['created_at']),
                'level'           => match($row['status']) {
                    'sent'    => 'success',
                    'failed'  => 'error',
                    default   => 'warning',
                },
                'message'         => 'Email to ' . $row['recipient_email'] . ': ' . $row['subject'],
            ];
        }
        $r['result']->free();
    }
}

echo json_encode([
    'success' => true,
    'logs'    => $logs,
    'total'   => $total,
    'page'    => $page,
    'pages'   => max(1, (int)ceil($total / $limit)),
]);

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)      return 'just now';
    if ($diff < 3600)    return floor($diff / 60) . ' min ago';
    if ($diff < 86400)   return floor($diff / 3600) . ' hr ago';
    if ($diff < 2592000) return floor($diff / 86400) . ' days ago';
    return date('M j, Y', strtotime($datetime));
}

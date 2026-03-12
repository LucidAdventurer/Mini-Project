<?php
// ============================================================
// api/admin/clear-logs.php
// Admin-only. Clears audit_logs and/or login_activity.
//
// POST JSON { type: "audit"|"login"|"all", older_than_days?: int }
// Returns   { success, deleted_count }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$currentUser = validateSession($conn, 'admin');
$adminId     = (int)$currentUser['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$body      = json_decode(file_get_contents('php://input'), true);
$type      = trim($body['type']             ?? 'all');
$olderThan = max(0, (int)($body['older_than_days'] ?? 0));

if (!in_array($type, ['audit', 'login', 'all'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid type. Use: audit, login, or all.']);
    exit;
}

$deleted = 0;
$cutoff  = $olderThan > 0 ? date('Y-m-d H:i:s', strtotime("-{$olderThan} days")) : null;

if (in_array($type, ['audit', 'all'], true)) {
    if ($cutoff) {
        $r = safePreparedQuery($conn, "DELETE FROM audit_logs WHERE created_at < ?", "s", [$cutoff]);
    } else {
        $r = safePreparedQuery($conn, "DELETE FROM audit_logs", "", []);
    }
    if ($r['success']) $deleted += (int)$r['affected_rows'];
}

if (in_array($type, ['login', 'all'], true)) {
    if ($cutoff) {
        $r = safePreparedQuery($conn, "DELETE FROM login_activity WHERE created_at < ?", "s", [$cutoff]);
    } else {
        // login_activity has FK to users — DELETE all rows is safe since we're
        // deleting the log rows themselves, not the users.
        $r = safePreparedQuery($conn, "DELETE FROM login_activity", "", []);
    }
    if ($r['success']) $deleted += (int)$r['affected_rows'];
}

// Write audit entry for this clear action
// Note: we do this AFTER the delete so the audit row isn't immediately deleted.
safePreparedQuery($conn,
    "INSERT INTO audit_logs (user_id, action, entity_type, new_values, ip_address, user_agent)
     VALUES (?, 'clear_logs', 'system', ?, ?, ?)",
    "isss",
    [
        $adminId,
        json_encode(['type' => $type, 'older_than_days' => $olderThan, 'deleted' => $deleted]),
        $_SERVER['REMOTE_ADDR'] ?? '',
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]
);

echo json_encode(['success' => true, 'deleted_count' => $deleted]);
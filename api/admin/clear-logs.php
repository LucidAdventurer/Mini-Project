<?php
// ============================================================
// api/admin/clear-logs.php
// Admin-only. Clears login_activity records.
//
// POST JSON { older_than_days?: int }
// Returns   { success, deleted_count }
//
// Note: audit_logs table does not exist in this schema.
// Only login_activity is available to clear.
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

validateSession($conn, 'admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$body      = json_decode(file_get_contents('php://input'), true);
$olderThan = max(0, (int)($body['older_than_days'] ?? 0));

$deleted = 0;
$cutoff  = $olderThan > 0 ? date('Y-m-d H:i:s', strtotime("-{$olderThan} days")) : null;

if ($cutoff) {
    $r = safePreparedQuery($conn, "DELETE FROM login_activity WHERE created_at < ?", "s", [$cutoff]);
} else {
    $r = safePreparedQuery($conn, "DELETE FROM login_activity", "", []);
}
if ($r['success']) $deleted += (int)$r['affected_rows'];

echo json_encode(['success' => true, 'deleted_count' => $deleted]);
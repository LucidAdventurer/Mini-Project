<?php
// ============================================================
// api/admin/update-settings.php
// Admin-only. Updates one or more system settings.
//
// POST JSON { settings: { key: value, ... } }
// Returns   { success, updated: int, errors: {key: msg} }
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

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || empty($body['settings']) || !is_array($body['settings'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Provide a settings object: {"settings":{"key":"value"}}.']);
    exit;
}

$sys     = SystemSettings::getInstance();
$updated = 0;
$errors  = [];

foreach ($body['settings'] as $key => $value) {
    $key = trim((string)$key);
    if ($key === '') continue;

    if ($sys->isImmutable($key)) {
        $errors[$key] = 'This setting cannot be changed.';
        continue;
    }
    if (!$sys->validate($key, $value)) {
        $errors[$key] = 'Invalid value for this setting.';
        continue;
    }
    if ($sys->set($key, $value, $adminId)) {
        $updated++;
    } else {
        $errors[$key] = 'Failed to save.';
    }
}

// Audit
if ($updated > 0) {
    safePreparedQuery($conn,
        "INSERT INTO audit_logs (user_id, action, entity_type, new_values, ip_address, user_agent)
         VALUES (?, 'update_settings', 'system_settings', ?, ?, ?)",
        "isss",
        [
            $adminId,
            json_encode($body['settings']),
            $_SERVER['REMOTE_ADDR'] ?? '',
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]
    );
}

echo json_encode([
    'success' => $updated > 0 || empty($errors),
    'updated' => $updated,
    'errors'  => $errors,
]);

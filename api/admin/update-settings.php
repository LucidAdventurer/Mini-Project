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
$all     = $sys->getAll();   // keyed by setting_key; each entry has 'type', 'is_editable'
$updated = 0;
$errors  = [];

foreach ($body['settings'] as $key => $value) {
    $key = trim((string)$key);
    if ($key === '') continue;

    // Guard: key must exist in system_settings
    if (!isset($all[$key])) {
        $errors[$key] = 'Unknown setting key.';
        continue;
    }

    // Guard: respect is_editable flag (replaces old 'immutable' concept)
    if (!(bool)($all[$key]['is_editable'] ?? true)) {
        $errors[$key] = 'This setting cannot be changed.';
        continue;
    }

    // Type-cast and validate value against declared type
    $type = $all[$key]['type'] ?? 'string';
    if (!validateSettingValue($type, $value, $errorMsg)) {
        $errors[$key] = $errorMsg;
        continue;
    }

    // Persist via SystemSettings::set() which writes to system_settings table
    if ($sys->set($key, $value)) {
        $updated++;
    } else {
        $errors[$key] = 'Failed to save.';
    }
}

// Audit entry
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

// ── Local validation helper ──
// Validates $value against system_settings setting_type.
// Returns true on pass; sets $errorMsg on fail.
function validateSettingValue(string $type, mixed $value, ?string &$errorMsg): bool {
    $errorMsg = null;
    switch ($type) {
        case 'integer':
            if (!is_numeric($value) || (int)$value != $value) {
                $errorMsg = 'Must be an integer.'; return false;
            }
            break;
        case 'float':
            if (!is_numeric($value)) {
                $errorMsg = 'Must be a number.'; return false;
            }
            break;
        case 'boolean':
            // Accept true/false/1/0/'true'/'false'
            if (!in_array($value, [true, false, 1, 0, 'true', 'false', '1', '0'], true)) {
                $errorMsg = 'Must be a boolean (true/false).'; return false;
            }
            break;
        case 'json':
            if (is_string($value)) {
                json_decode($value);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $errorMsg = 'Must be valid JSON.'; return false;
                }
            }
            break;
        default: // string — accept anything
            break;
    }
    return true;
}
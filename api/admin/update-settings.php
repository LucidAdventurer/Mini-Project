<?php
// ============================================================
// api/admin/update-settings.php
// Admin-only. Updates one or more system settings.
//
// system_settings schema: setting_key, setting_value, setting_type, description, updated_at
// NO is_editable column, NO updated_by column, NO audit_logs table
// setting_type enum: 'string','integer','boolean','json'  (no 'float')
//
// POST JSON { settings: { key: value, ... } }
// Returns   { success, updated: int, errors: {key: msg} }
// ============================================================
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';
header('Content-Type: application/json');

validateSession($conn, 'admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method not allowed.']); exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || empty($body['settings']) || !is_array($body['settings'])) {
    http_response_code(400); echo json_encode(['success'=>false,'error'=>'Provide {"settings":{"key":"value"}}.']); exit;
}

$sys     = SystemSettings::getInstance();
$all     = $sys->getAll();
$updated = 0;
$errors  = [];

foreach ($body['settings'] as $key => $value) {
    $key = trim((string)$key);
    if ($key === '') continue;

    if (!isset($all[$key])) { $errors[$key] = 'Unknown setting key.'; continue; }

    // No is_editable column — all keys are editable
    $type = $all[$key]['type'] ?? 'string';
    if (!validateSettingValue($type, $value, $errorMsg)) { $errors[$key] = $errorMsg; continue; }

    if ($sys->set($key, $value)) { $updated++; } else { $errors[$key] = 'Failed to save.'; }
}

// No audit_logs table — skip audit INSERT

echo json_encode(['success'=>$updated > 0 || empty($errors),'updated'=>$updated,'errors'=>$errors]);

function validateSettingValue(string $type, mixed $value, ?string &$errorMsg): bool {
    $errorMsg = null;
    switch ($type) {
        case 'integer':
            if (!is_numeric($value) || (int)$value != $value) { $errorMsg='Must be an integer.'; return false; } break;
        // 'float' not in new schema's setting_type enum — omitted
        case 'boolean':
            if (!in_array($value,[true,false,1,0,'true','false','1','0'],true)) { $errorMsg='Must be a boolean.'; return false; } break;
        case 'json':
            if (is_string($value)) { json_decode($value); if (json_last_error()!==JSON_ERROR_NONE) { $errorMsg='Must be valid JSON.'; return false; } } break;
        default: break;
    }
    return true;
}
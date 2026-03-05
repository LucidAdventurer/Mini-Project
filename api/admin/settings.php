<?php
// ============================================================
// api/admin/settings.php
//
// GET  → returns all configurable system settings
// POST → saves updated settings
//
// GET returns { success, settings: { key: value, ... } }
// POST JSON { key: value, ... }  → { success, error? }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

// validateSession enforces role, session existence, and CSRF on POST automatically
$adminUser = validateSession($conn, 'admin');
$adminId   = (int) $adminUser['user_id'];

// Guard: $settings may be null if SystemSettings failed to load
if ($settings === null) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Settings service unavailable.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $all      = $settings->getAll();
    $response = [];
    foreach ($all as $key => $data) {
        if (!($data['immutable'] ?? false)) {
            $response[$key] = $data['value'];
        }
    }

    echo json_encode(['success' => true, 'settings' => $response]);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON.']);
        exit;
    }

    $allowedKeys = [
        'otp_expiry_minutes',
        'max_login_attempts',
        'lockout_duration_minutes',
        'session_timeout_minutes',
        'maintenance_mode',
        'allow_guest_tests',
        'enable_proctoring',
        'max_file_size_mb',
        'results_retention_days',
    ];

    $failed = [];
    foreach ($body as $key => $value) {
        if (!in_array($key, $allowedKeys, true)) continue;

        if (!$settings->validate($key, $value)) {
            $failed[] = $key;
            continue;
        }

        if (!$settings->set($key, $value)) {
            $failed[] = $key;
        }
    }

    if (!empty($failed)) {
        echo json_encode([
            'success' => false,
            'error'   => 'Failed to save: ' . implode(', ', $failed),
        ]);
    } else {
        safePreparedQuery($conn,
            "INSERT INTO audit_logs (user_id, action, entity_type, new_values, ip_address)
             VALUES (?, 'update_settings', 'system_settings', ?, ?)",
            "iss",
            [$adminId, json_encode($body), $_SERVER['REMOTE_ADDR'] ?? '']
        );
        echo json_encode(['success' => true]);
    }

} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
}
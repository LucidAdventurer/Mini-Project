<?php
// ============================================================
// api/admin/settings.php (legacy combined GET/POST endpoint)
//
// GET  → returns all configurable system settings
// POST → saves updated settings
// ============================================================
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';
header('Content-Type: application/json');

$adminUser = validateSession($conn, 'admin');
$adminId   = (int) $adminUser['user_id'];

$settings = SystemSettings::getInstance();
if ($settings === null) {
    http_response_code(503);
    echo json_encode(['success'=>false,'error'=>'Settings service unavailable.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $all = $settings->getAll();
    $response = [];
    foreach ($all as $key => $data) {
        // system_settings has no immutable column — include all
        $response[$key] = $data['value'];
    }
    echo json_encode(['success'=>true,'settings'=>$response]);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Invalid JSON.']); exit; }

    // Keys that actually exist in new system_settings DB
    $allowedKeys = [
        'max_login_attempts','lockout_duration_minutes','session_timeout_minutes',
        'email_verification_required','maintenance_mode','allow_guest_attempts',
        'show_results_immediately','show_correct_answers','max_upload_size_mb',
        'log_retention_days','email_notifications_enabled','demo_mode',
    ];

    $failed = [];
    foreach ($body as $key => $value) {
        if (!in_array($key, $allowedKeys, true)) continue;
        if (!$settings->set($key, $value)) $failed[] = $key;
    }

    if (!empty($failed)) {
        echo json_encode(['success'=>false,'error'=>'Failed to save: '.implode(', ',$failed)]);
    } else {
        echo json_encode(['success'=>true]);
    }

} else {
    http_response_code(405);
    echo json_encode(['success'=>false,'error'=>'Method not allowed.']);
}
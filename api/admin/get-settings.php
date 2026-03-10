<?php
// ============================================================
// api/admin/get-settings.php
// Admin-only. Returns all system settings grouped by category.
//
// GET (no params)
// Returns { success, settings: { category: [{key,value,type,description,is_editable,immutable}] } }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

validateSession($conn, 'admin');

$settings = SystemSettings::getInstance();
$all      = $settings->getAll();

// Group keys into UI categories
$groups = [
    'Authentication'  => ['max_login_attempts','lockout_duration_minutes','session_timeout_minutes',
                          'remember_me_duration_days','force_password_change_days'],
    'Verification'    => ['otp_expiry_minutes','otp_length','email_verification_expiry_hours',
                          'resend_otp_cooldown_seconds','require_email_verification'],
    'Assessments'     => ['allow_guest_tests','max_assessment_duration_minutes',
                          'auto_submit_on_timeout','show_results_immediately',
                          'allow_review_after_submission'],
    'Proctoring'      => ['enable_proctoring','proctoring_tab_switch_limit','proctoring_strict_mode'],
    'File Uploads'    => ['max_file_size_mb','allowed_file_types','enable_file_virus_scan'],
    'Email / SMTP'    => ['smtp_configured','smtp_from_email','smtp_from_name',
                          'enable_email_notifications','enable_push_notifications'],
    'Data Retention'  => ['results_retention_days','login_activity_retention_days',
                          'audit_log_retention_days','notification_retention_days'],
    'System'          => ['maintenance_mode','allow_registration','default_user_timezone',
                          'items_per_page','max_items_per_page'],
    'Performance'     => ['enable_query_cache','cache_expiry_seconds','enable_compression'],
    'Analytics'       => ['track_user_activity','enable_advanced_analytics','analytics_retention_days'],
];

$result = [];
foreach ($groups as $groupName => $keys) {
    $result[$groupName] = [];
    foreach ($keys as $key) {
        if (!isset($all[$key])) continue;
        $result[$groupName][] = [
            'key'         => $key,
            'value'       => $all[$key]['value'],
            'type'        => $all[$key]['type'],
            'description' => $all[$key]['description'] ?? ucwords(str_replace('_', ' ', $key)),
            'is_editable' => !($all[$key]['immutable'] ?? false),
            'immutable'   => $all[$key]['immutable'] ?? false,
        ];
    }
}

// ── Database Overview counts ──
$dbCounts = [
    'users'         => 0,
    'assessments'   => 0,
    'attempts'      => 0,
    'notifications' => 0,
    'audit_logs'    => 0,
];

$countQueries = [
    'users'         => "SELECT COUNT(*) AS c FROM users",
    'assessments'   => "SELECT COUNT(*) AS c FROM assessments",
    'attempts'      => "SELECT COUNT(*) AS c FROM assessment_attempts",
    'notifications' => "SELECT COUNT(*) AS c FROM notifications",
    'audit_logs'    => "SELECT COUNT(*) AS c FROM audit_logs",
];

foreach ($countQueries as $key => $sql) {
    $r = safePreparedQuery($conn, $sql, "", []);
    if ($r['success'] && $r['result']) {
        $dbCounts[$key] = (int)($r['result']->fetch_assoc()['c'] ?? 0);
        $r['result']->free();
    }
}

echo json_encode(['success' => true, 'settings' => $result, 'db_counts' => $dbCounts]);
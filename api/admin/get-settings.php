<?php
// ============================================================
// api/admin/get-settings.php
// Admin-only. Returns all system settings grouped by category.
//
// GET (no params)
// Returns {
//   success,
//   settings: { "Category": [{key, value, type, description, is_editable}] },
//   db_counts: { users, assessments, attempts, notifications, audit_logs }
// }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

validateSession($conn, 'admin');

$settings = SystemSettings::getInstance();
$all      = $settings->getAll();

// ── Groups mapped to actual keys present in the new system_settings table ──
$groups = [
    'Authentication'   => [
        'max_login_attempts',
        'lockout_duration_minutes',
        'session_timeout_minutes',
        'email_verification_required',
    ],
    'Assessments'      => [
        'default_assessment_duration',
        'default_max_attempts',
        'allow_negative_marking',
        'shuffle_questions_default',
        'shuffle_options_default',
        'allow_guest_attempts',
    ],
    'Results'          => [
        'show_results_immediately',
        'show_correct_answers',
        'leaderboard_enabled',
    ],
    'File Uploads'     => [
        'max_upload_size_mb',
        'allowed_file_types',
        'max_resource_downloads_per_day',
    ],
    'Email'            => [
        'email_notifications_enabled',
        'system_notifications_enabled',
        'reminder_email_hours_before',
        'disable_email_sending',
    ],
    'Data Retention'   => [
        'log_retention_days',
    ],
    'Materials'        => [
        'materials_public_by_default',
        'track_material_progress',
        'material_view_increment',
    ],
    'System'           => [
        'maintenance_mode',
        'demo_mode',
    ],
    'Analytics'        => [
        'analytics_enabled',
        'track_user_activity',
    ],
];

$result = [];
foreach ($groups as $groupName => $keys) {
    $result[$groupName] = [];
    foreach ($keys as $key) {
        if (!isset($all[$key])) continue;
        $entry = $all[$key];
        $result[$groupName][] = [
            'key'         => $key,
            'value'       => $entry['value'],
            'type'        => $entry['type'],
            'description' => $entry['description'] ?? ucwords(str_replace('_', ' ', $key)),
            // New schema uses is_editable (tinyint), not 'immutable'
            'is_editable' => (bool)($entry['is_editable'] ?? true),
            'immutable'   => !(bool)($entry['is_editable'] ?? true),
        ];
    }
    // Drop empty groups
    if (empty($result[$groupName])) {
        unset($result[$groupName]);
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

echo json_encode([
    'success'   => true,
    'settings'  => $result,
    'db_counts' => $dbCounts,
]);
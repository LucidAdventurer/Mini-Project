<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';
header('Content-Type: application/json');
validateSession($conn, 'admin');

$settings = SystemSettings::getInstance();
$all      = $settings->getAll();

$groups = [
    'Authentication' => ['max_login_attempts','lockout_duration_minutes','session_timeout_minutes','email_verification_required'],
    'Assessments'    => ['default_assessment_duration','default_max_attempts','allow_negative_marking','shuffle_questions_default','shuffle_options_default','allow_guest_attempts'],
    'Results'        => ['show_results_immediately','show_correct_answers','leaderboard_enabled'],
    'File Uploads'   => ['max_upload_size_mb','allowed_file_types','max_resource_downloads_per_day'],
    'Email'          => ['email_notifications_enabled','system_notifications_enabled','reminder_email_hours_before','disable_email_sending'],
    'Data Retention' => ['log_retention_days'],
    'Materials'      => ['materials_public_by_default','track_material_progress','material_view_increment'],
    'System'         => ['maintenance_mode','demo_mode'],
    'Analytics'      => ['analytics_enabled','track_user_activity'],
];

$result = [];
foreach ($groups as $groupName => $keys) {
    $result[$groupName] = [];
    foreach ($keys as $key) {
        if (!isset($all[$key])) continue;
        $e = $all[$key];
        // system_settings has no is_editable column — treat all as editable
        $result[$groupName][] = ['key'=>$key,'value'=>$e['value'],'type'=>$e['type'],
            'description'=>$e['description'] ?? ucwords(str_replace('_',' ',$key)),'is_editable'=>true];
    }
    if (empty($result[$groupName])) unset($result[$groupName]);
}

// audit_logs gone; materials replaces training_materials
$dbCounts = ['users'=>0,'assessments'=>0,'attempts'=>0,'notifications'=>0,'materials'=>0];
foreach (['users'=>'users','assessments'=>'assessments','attempts'=>'assessment_attempts',
          'notifications'=>'notifications','materials'=>'materials'] as $k=>$tbl) {
    $r = safePreparedQuery($conn, "SELECT COUNT(*) AS c FROM `$tbl`", "", []);
    if ($r['success'] && $r['result']) { $dbCounts[$k]=(int)($r['result']->fetch_assoc()['c']??0); $r['result']->free(); }
}

echo json_encode(['success'=>true,'settings'=>$result,'db_counts'=>$dbCounts]);
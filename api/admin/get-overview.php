<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';
header('Content-Type: application/json');
validateSession($conn, 'admin');

// 1. Stats — published not active
$stats = ['total_users'=>0,'published_assessments'=>0,'total_attempts'=>0,'failed_logins_today'=>0,'suspicious_ips_today'=>0];
$r = safePreparedQuery($conn,
    "SELECT (SELECT COUNT(*) FROM users) AS total_users,
            (SELECT COUNT(*) FROM assessments WHERE status='published') AS published_assessments,
            (SELECT COUNT(*) FROM assessment_attempts) AS total_attempts,
            (SELECT COUNT(*) FROM login_activity WHERE is_success=0 AND created_at>=CURDATE()) AS failed_logins_today,
            (SELECT COUNT(DISTINCT ip_address) FROM login_activity WHERE is_success=0 AND created_at>=CURDATE()) AS suspicious_ips_today",
    "", []);
if ($r['success'] && $r['result']) {
    $row = $r['result']->fetch_assoc();
    $stats = ['total_users'=>(int)($row['total_users']??0),'published_assessments'=>(int)($row['published_assessments']??0),
              'total_attempts'=>(int)($row['total_attempts']??0),'failed_logins_today'=>(int)($row['failed_logins_today']??0),
              'suspicious_ips_today'=>(int)($row['suspicious_ips_today']??0)];
    $r['result']->free();
}

// 2. User distribution — role replaces user_type
$dist = ['student'=>0,'teacher'=>0,'admin'=>0,'unverified'=>0,'inactive'=>0];
$r2 = safePreparedQuery($conn,
    "SELECT SUM(role='student' AND is_active=1 AND is_verified=1) AS students,
            SUM(role='teacher' AND is_active=1 AND is_verified=1) AS teachers,
            SUM(role='admin') AS admins,
            SUM(is_verified=0 AND is_active=1) AS unverified,
            SUM(is_active=0) AS inactive
     FROM users", "", []);
if ($r2['success'] && $r2['result']) {
    $row = $r2['result']->fetch_assoc();
    $dist = ['student'=>(int)($row['students']??0),'teacher'=>(int)($row['teachers']??0),
             'admin'=>(int)($row['admins']??0),'unverified'=>(int)($row['unverified']??0),'inactive'=>(int)($row['inactive']??0)];
    $r2['result']->free();
}
$tu = $stats['total_users'];
$dist['student_pct']    = $tu > 0 ? round($dist['student']    / $tu * 100) : 0;
$dist['teacher_pct']    = $tu > 0 ? round($dist['teacher']    / $tu * 100) : 0;
$dist['unverified_pct'] = $tu > 0 ? round($dist['unverified'] / $tu * 100) : 0;
$dist['inactive_pct']   = $tu > 0 ? round($dist['inactive']   / $tu * 100) : 0;

// 3. Department breakdown
$depts = [];
$r3 = safePreparedQuery($conn,
    "SELECT department, COUNT(*) AS cnt FROM users
     WHERE department IS NOT NULL AND department != '' AND is_active=1
     GROUP BY department ORDER BY cnt DESC LIMIT 6", "", []);
if ($r3['success'] && $r3['result']) {
    $maxD = 1; $rows = [];
    while ($row = $r3['result']->fetch_assoc()) { $rows[] = $row; $maxD = max($maxD, (int)$row['cnt']); }
    foreach ($rows as $row) $depts[] = ['department'=>$row['department'],'count'=>(int)$row['cnt'],'pct'=>round((int)$row['cnt']/$maxD*100)];
    $r3['result']->free();
}

// 4. Assessment summary — submitted replaces completed; published replaces active
$assessments = [];
$r5 = safePreparedQuery($conn,
    "SELECT a.title, a.category, a.difficulty, a.duration_minutes, a.status,
            COUNT(DISTINCT aa.attempt_id) AS attempt_count,
            ROUND(SUM(CASE WHEN aa.status='submitted' AND aa.percentage>=(a.passing_marks/a.total_marks*100) THEN 1.0 ELSE 0 END)
                  / NULLIF(SUM(CASE WHEN aa.status='submitted' THEN 1 ELSE 0 END),0)*100, 0) AS pass_rate
     FROM assessments a
     LEFT JOIN assessment_attempts aa ON aa.assessment_id=a.assessment_id
     GROUP BY a.assessment_id
     ORDER BY FIELD(a.status,'published','draft','archived'), a.updated_at DESC
     LIMIT 5", "", []);
if ($r5['success'] && $r5['result']) {
    while ($row = $r5['result']->fetch_assoc())
        $assessments[] = ['title'=>$row['title'],'category'=>$row['category'],'difficulty'=>$row['difficulty'],
                          'duration_minutes'=>(int)$row['duration_minutes'],'status'=>$row['status'],
                          'attempt_count'=>(int)$row['attempt_count'],'pass_rate'=>$row['attempt_count']>0?(int)$row['pass_rate']:null];
    $r5['result']->free();
}

// 5. DB counts — audit_logs gone; materials replaces training_materials
$dbCounts = ['users'=>0,'assessments'=>0,'attempts'=>0,'notifications'=>0,'materials'=>0];
foreach (['users'=>'users','assessments'=>'assessments','attempts'=>'assessment_attempts',
          'notifications'=>'notifications','materials'=>'materials'] as $k => $tbl) {
    $rc = safePreparedQuery($conn, "SELECT COUNT(*) AS cnt FROM `$tbl`", "", []);
    if ($rc['success'] && $rc['result']) { $dbCounts[$k] = (int)($rc['result']->fetch_assoc()['cnt']??0); $rc['result']->free(); }
}

echo json_encode(['success'=>true,'stats'=>$stats,'user_distribution'=>$dist,'dept_breakdown'=>$depts,
                  'assessment_summary'=>$assessments,'db_counts'=>$dbCounts]);
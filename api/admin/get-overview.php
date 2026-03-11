<?php
// ============================================================
// api/admin/get-overview.php
//
// Returns all stats needed for the System Overview page.
// Single endpoint — one call loads the entire dashboard.
//
// GET (no params)
// Returns {
//   success,
//   stats: { total_users, active_assessments, total_attempts,
//             failed_logins_today, suspicious_ips_today },
//   user_distribution: { students, teachers, admins, unverified, inactive,
//                        student_pct, teacher_pct, unverified_pct, inactive_pct },
//   dept_breakdown: [{ department, count, pct }],
//   recent_activity: [...],
//   assessment_summary: [...],
//   db_counts: { users, assessments, attempts, notifications, audit_logs }
// }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

validateSession($conn, 'admin');

// ── 1. Top stat cards ──
$stats = [
    'total_users'          => 0,
    'active_assessments'   => 0,
    'total_attempts'       => 0,
    'failed_logins_today'  => 0,
    'suspicious_ips_today' => 0,
];

$r = safePreparedQuery($conn,
    "SELECT
        (SELECT COUNT(*) FROM users)                                   AS total_users,
        (SELECT COUNT(*) FROM assessments WHERE status = 'active')     AS active_assessments,
        (SELECT COUNT(*) FROM assessment_attempts)                     AS total_attempts,
        (SELECT COUNT(*) FROM login_activity
         WHERE is_success = 0
           AND created_at >= CURDATE())                                AS failed_logins_today,
        (SELECT COUNT(DISTINCT ip_address) FROM login_activity
         WHERE is_success = 0
           AND created_at >= CURDATE())                                AS suspicious_ips_today",
    "", []
);
if ($r['success'] && $r['result']) {
    $row   = $r['result']->fetch_assoc();
    $stats = [
        'total_users'          => (int)($row['total_users']          ?? 0),
        'active_assessments'   => (int)($row['active_assessments']   ?? 0),
        'total_attempts'       => (int)($row['total_attempts']        ?? 0),
        'failed_logins_today'  => (int)($row['failed_logins_today']  ?? 0),
        'suspicious_ips_today' => (int)($row['suspicious_ips_today'] ?? 0),
    ];
    $r['result']->free();
}

// ── 2. User distribution ──
$dist = ['student' => 0, 'teacher' => 0, 'admin' => 0, 'unverified' => 0, 'inactive' => 0];
$r2   = safePreparedQuery($conn,
    "SELECT
        SUM(user_type = 'student' AND is_active = 1 AND is_verified = 1)  AS students,
        SUM(user_type = 'teacher' AND is_active = 1 AND is_verified = 1)  AS teachers,
        SUM(user_type = 'admin')                                           AS admins,
        SUM(is_verified = 0 AND is_active = 1)                            AS unverified,
        SUM(is_active = 0)                                                 AS inactive
     FROM users",
    "", []
);
if ($r2['success'] && $r2['result']) {
    $row  = $r2['result']->fetch_assoc();
    $dist = [
        'student'    => (int)($row['students']   ?? 0),
        'teacher'    => (int)($row['teachers']   ?? 0),
        'admin'      => (int)($row['admins']     ?? 0),
        'unverified' => (int)($row['unverified'] ?? 0),
        'inactive'   => (int)($row['inactive']   ?? 0),
    ];
    $r2['result']->free();
}

$totalUsers = $stats['total_users'];
$dist['student_pct']    = $totalUsers > 0 ? round($dist['student']    / $totalUsers * 100) : 0;
$dist['teacher_pct']    = $totalUsers > 0 ? round($dist['teacher']    / $totalUsers * 100) : 0;
$dist['unverified_pct'] = $totalUsers > 0 ? round($dist['unverified'] / $totalUsers * 100) : 0;
$dist['inactive_pct']   = $totalUsers > 0 ? round($dist['inactive']   / $totalUsers * 100) : 0;

// ── 3. Department breakdown ──
$depts = [];
$r3 = safePreparedQuery($conn,
    "SELECT department, COUNT(*) AS cnt
     FROM users
     WHERE department IS NOT NULL AND department != '' AND is_active = 1
     GROUP BY department
     ORDER BY cnt DESC
     LIMIT 6",
    "", []
);
if ($r3['success'] && $r3['result']) {
    $maxDept = 1;
    $rows    = [];
    while ($row = $r3['result']->fetch_assoc()) {
        $rows[]  = $row;
        $maxDept = max($maxDept, (int)$row['cnt']);
    }
    foreach ($rows as $row) {
        $depts[] = [
            'department' => $row['department'],
            'count'      => (int)$row['cnt'],
            'pct'        => round((int)$row['cnt'] / $maxDept * 100),
        ];
    }
    $r3['result']->free();
}

// ── 4. Recent activity (from audit_logs) ──
$activity = [];
$r4 = safePreparedQuery($conn,
    "SELECT al.action, al.entity_type, al.created_at,
            u.full_name, u.email
     FROM audit_logs al
     LEFT JOIN users u ON u.user_id = al.user_id
     ORDER BY al.created_at DESC
     LIMIT 8",
    "", []
);
if ($r4['success'] && $r4['result']) {
    while ($row = $r4['result']->fetch_assoc()) {
        $activity[] = [
            'action'     => $row['action'],
            'actor'      => $row['full_name'] ?? 'System',
            'entity'     => $row['entity_type'],
            'time_ago'   => timeAgo($row['created_at']),
            'created_at' => $row['created_at'],
        ];
    }
    $r4['result']->free();
}

// ── 5. Assessment summary ──
$assessments = [];
$r5 = safePreparedQuery($conn,
    "SELECT a.title, a.category, a.difficulty, a.duration_minutes, a.status,
            COUNT(DISTINCT aa.attempt_id) AS attempt_count,
            ROUND(
                SUM(CASE WHEN aa.status = 'completed'
                         AND aa.percentage >= (a.passing_marks / a.total_marks * 100)
                    THEN 1.0 ELSE 0 END)
                / NULLIF(SUM(CASE WHEN aa.status = 'completed' THEN 1 ELSE 0 END), 0) * 100
            , 0) AS pass_rate
     FROM assessments a
     LEFT JOIN assessment_attempts aa ON aa.assessment_id = a.assessment_id
     GROUP BY a.assessment_id
     ORDER BY FIELD(a.status,'active','draft','archived'), a.updated_at DESC
     LIMIT 5",
    "", []
);
if ($r5['success'] && $r5['result']) {
    while ($row = $r5['result']->fetch_assoc()) {
        $assessments[] = [
            'title'            => $row['title'],
            'category'         => $row['category'],
            'difficulty'       => $row['difficulty'],
            'duration_minutes' => (int)$row['duration_minutes'],
            'status'           => $row['status'],
            'attempt_count'    => (int)$row['attempt_count'],
            'pass_rate'        => $row['attempt_count'] > 0 ? (int)$row['pass_rate'] : null,
        ];
    }
    $r5['result']->free();
}

// ── 6. DB table counts (use safePreparedQuery — safeQuery removed) ──
$dbCounts = [
    'users'         => 0,
    'assessments'   => 0,
    'attempts'      => 0,
    'notifications' => 0,
    'audit_logs'    => 0,
];
$countMap = [
    'users'         => "SELECT COUNT(*) AS cnt FROM users",
    'assessments'   => "SELECT COUNT(*) AS cnt FROM assessments",
    'attempts'      => "SELECT COUNT(*) AS cnt FROM assessment_attempts",
    'notifications' => "SELECT COUNT(*) AS cnt FROM notifications",
    'audit_logs'    => "SELECT COUNT(*) AS cnt FROM audit_logs",
];
foreach ($countMap as $key => $sql) {
    $rc = safePreparedQuery($conn, $sql, "", []);
    if ($rc['success'] && $rc['result']) {
        $dbCounts[$key] = (int)($rc['result']->fetch_assoc()['cnt'] ?? 0);
        $rc['result']->free();
    }
}

echo json_encode([
    'success'            => true,
    'stats'              => $stats,
    'user_distribution'  => $dist,
    'dept_breakdown'     => $depts,
    'recent_activity'    => $activity,
    'assessment_summary' => $assessments,
    'db_counts'          => $dbCounts,
]);

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)      return 'just now';
    if ($diff < 3600)    return floor($diff / 60) . ' min ago';
    if ($diff < 86400)   return floor($diff / 3600) . ' hr ago';
    if ($diff < 2592000) return floor($diff / 86400) . ' days ago';
    return date('M j', strtotime($datetime));
}
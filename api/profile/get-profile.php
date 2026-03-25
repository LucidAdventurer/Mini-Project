<?php
// ============================================================
// api/profile/get-profile.php
// Any logged-in user. Returns own full profile + stats.
//
// GET (no params — reads from session)
// Returns { success, user{}, stats{} }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$currentUser = validateSession($conn);
$userId = (int)$currentUser['user_id'];
$role   = $currentUser['role'];

// Full profile
$r = safePreparedQuery($conn,
    "SELECT user_id, full_name, email, role, department,
            registration_number, is_verified, is_active,
            created_at, last_login, profile_image
     FROM users WHERE user_id = ?",
    "i", [$userId]);

if (!$r['success'] || !$r['result'] || $r['result']->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'User not found.']);
    exit;
}
$user = $r['result']->fetch_assoc();
$r['result']->free();
unset($user['password_hash']);

// Role-specific stats
$stats = [];

if ($role === 'student') {
    $rs = safePreparedQuery($conn,
        "SELECT
            COUNT(*) AS total_attempts,
            SUM(status='submitted') AS submitted,
            ROUND(AVG(CASE WHEN status='submitted' THEN percentage END),1) AS avg_score,
            ROUND(MAX(percentage),1) AS best_score
         FROM assessment_attempts WHERE user_id = ?",
        "i", [$userId]);
    if ($rs['success'] && $rs['result']) {
        $row = $rs['result']->fetch_assoc(); $rs['result']->free();
        $stats = [
            'total_attempts' => (int)($row['total_attempts'] ?? 0),
            'completed'      => (int)($row['submitted']      ?? 0),
            'avg_score'      => (float)($row['avg_score']    ?? 0),
            'best_score'     => (float)($row['best_score']   ?? 0),
        ];
    }
    // Materials completed
    $rm = safePreparedQuery($conn,
        "SELECT COUNT(*) AS c FROM material_progress WHERE user_id = ? AND completed = 1",
        "i", [$userId]);
    if ($rm['success'] && $rm['result']) {
        $stats['materials_completed'] = (int)($rm['result']->fetch_assoc()['c'] ?? 0);
        $rm['result']->free();
    }
} elseif ($role === 'teacher') {
    $rt = safePreparedQuery($conn,
        "SELECT COUNT(*) AS total_assessments,
                SUM(status='published') AS active_assessments
         FROM assessments WHERE created_by = ?",
        "i", [$userId]);
    if ($rt['success'] && $rt['result']) {
        $row = $rt['result']->fetch_assoc(); $rt['result']->free();
        $stats['total_assessments']  = (int)($row['total_assessments']  ?? 0);
        $stats['active_assessments'] = (int)($row['active_assessments'] ?? 0);
    }
    $rta = safePreparedQuery($conn,
        "SELECT COUNT(DISTINCT aa.attempt_id) AS total_attempts
         FROM assessment_attempts aa
         JOIN assessments a ON a.assessment_id = aa.assessment_id
         WHERE a.created_by = ? AND aa.status = 'completed'",
        "i", [$userId]);
    if ($rta['success'] && $rta['result']) {
        $stats['total_student_attempts'] = (int)($rta['result']->fetch_assoc()['total_attempts'] ?? 0);
        $rta['result']->free();
    }
    $rrm = safePreparedQuery($conn,
        "SELECT COUNT(*) AS c FROM materials WHERE uploaded_by = ?",
        "i", [$userId]);
    if ($rrm['success'] && $rrm['result']) {
        $stats['resources_uploaded'] = (int)($rrm['result']->fetch_assoc()['c'] ?? 0);
        $rrm['result']->free();
    }
} elseif ($role === 'admin') {
    $ra = safePreparedQuery($conn,
        "SELECT
            (SELECT COUNT(*) FROM users)        AS total_users,
            (SELECT COUNT(*) FROM assessments)  AS total_assessments,
            (SELECT COUNT(*) FROM resources) AS total_materials,
            (SELECT COUNT(*) FROM assessment_attempts WHERE status='completed') AS total_attempts",
        "", []);
    if ($ra['success'] && $ra['result']) {
        $row = $ra['result']->fetch_assoc(); $ra['result']->free();
        $stats = [
            'total_users'        => (int)($row['total_users']        ?? 0),
            'total_assessments'  => (int)($row['total_assessments']  ?? 0),
            'total_materials'    => (int)($row['total_materials']    ?? 0),
            'total_attempts'     => (int)($row['total_attempts']     ?? 0),
        ];
    }
}

// Recent login history (last 5)
$rl = safePreparedQuery($conn,
    "SELECT ip_address, is_success, failure_reason, created_at
     FROM login_activity WHERE user_id = ?
     ORDER BY created_at DESC LIMIT 5",
    "i", [$userId]);
$loginHistory = [];
if ($rl['success'] && $rl['result']) {
    while ($row = $rl['result']->fetch_assoc()) {
        $loginHistory[] = [
            'ip_address'     => $row['ip_address'],
            'is_success'     => (bool)$row['is_success'],
            'failure_reason' => $row['failure_reason'],
            'created_at'     => $row['created_at'],
        ];
    }
    $rl['result']->free();
}

echo json_encode([
    'success'       => true,
    'user'          => $user,
    'stats'         => $stats,
    'login_history' => $loginHistory,
]);

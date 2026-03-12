<?php
// ============================================================
// api/assessment/get-assessment-stats.php
//
// Returns live aggregate stats for one assessment.
// Used by edit-assessment.php to refresh stats without reload.
//
// GET ?assessment_id=<int>
//
// Returns {
//   success: bool,
//   stats: {
//     total_attempts, completed, avg_score, highest_score,
//     lowest_score, pass_count, pass_rate, avg_time_minutes,
//     unique_students, question_count
//   }
// }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$currentUser  = validateSession($conn, 'teacher');
$teacherId    = (int) $currentUser['user_id'];

$assessmentId = (int)($_GET['assessment_id'] ?? 0);

if ($assessmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid assessment ID.']);
    exit;
}

// ── Verify ownership ──
$check = safePreparedQuery($conn,
    "SELECT passing_marks, total_marks FROM assessments
     WHERE assessment_id = ? AND created_by = ?",
    "ii", [$assessmentId, $teacherId]
);
if (!$check['success'] || !$check['result'] || $check['result']->num_rows === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Assessment not found or access denied.']);
    exit;
}
$aRow        = $check['result']->fetch_assoc();
$check['result']->free();
$passingPct  = $aRow['total_marks'] > 0
    ? ($aRow['passing_marks'] / $aRow['total_marks']) * 100
    : 0;

// ── Aggregate stats ──
$rs = safePreparedQuery($conn,
    "SELECT
        COUNT(*)                                                         AS total_attempts,
        SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END)             AS completed,
        ROUND(AVG(CASE WHEN status='completed' THEN percentage END), 1)  AS avg_score,
        ROUND(MAX(percentage), 1)                                        AS highest_score,
        ROUND(MIN(CASE WHEN status='completed' THEN percentage END), 1)  AS lowest_score,
        SUM(CASE WHEN status='completed' AND percentage >= ? THEN 1 ELSE 0 END) AS pass_count,
        ROUND(AVG(CASE WHEN status='completed'
                  THEN TIMESTAMPDIFF(MINUTE, start_time, submitted_at) END), 0) AS avg_time,
        COUNT(DISTINCT user_id)                                          AS unique_students
     FROM assessment_attempts
     WHERE assessment_id = ?",
    "di", [$passingPct, $assessmentId]
);

$stats = [
    'total_attempts'   => 0,
    'completed'        => 0,
    'avg_score'        => 0,
    'highest_score'    => 0,
    'lowest_score'     => 0,
    'pass_count'       => 0,
    'pass_rate'        => 0,
    'avg_time_minutes' => 0,
    'unique_students'  => 0,
];

if ($rs['success'] && $rs['result']) {
    $row = $rs['result']->fetch_assoc();
    if ($row) {
        $completed = (int)($row['completed'] ?? 0);
        $passCount = (int)($row['pass_count'] ?? 0);
        $stats = [
            'total_attempts'   => (int)($row['total_attempts']   ?? 0),
            'completed'        => $completed,
            'avg_score'        => (float)($row['avg_score']      ?? 0),
            'highest_score'    => (float)($row['highest_score']  ?? 0),
            'lowest_score'     => (float)($row['lowest_score']   ?? 0),
            'pass_count'       => $passCount,
            'pass_rate'        => $completed > 0 ? round(($passCount / $completed) * 100, 1) : 0,
            'avg_time_minutes' => (int)($row['avg_time']         ?? 0),
            'unique_students'  => (int)($row['unique_students']  ?? 0),
        ];
    }
    $rs['result']->free();
}

// ── Question count ──
$rq = safePreparedQuery($conn,
    "SELECT COUNT(*) AS cnt FROM questions WHERE assessment_id = ?",
    "i", [$assessmentId]
);
if ($rq['success'] && $rq['result']) {
    $row = $rq['result']->fetch_assoc();
    $stats['question_count'] = (int)($row['cnt'] ?? 0);
    $rq['result']->free();
}

echo json_encode(['success' => true, 'stats' => $stats]);
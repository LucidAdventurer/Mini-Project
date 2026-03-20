<?php
// ============================================================
// api/assessments/results.php
// Returns JSON results for a given assessment (teacher view).
// GET ?assessment_id=int
// Returns {
//   success: bool,
//   results: [...],
//   meta: { total_students, avg_score, pass_rate, pass_percentage, passing_marks, total_marks }
// }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$currentUser = validateSession($conn, 'teacher');
$teacherId   = (int) $currentUser['user_id'];

$assessmentId = (int)($_GET['assessment_id'] ?? 0);
if ($assessmentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid assessment ID.']);
    exit;
}

// Verify teacher owns this assessment
$asmRes = safePreparedQuery($conn,
    "SELECT assessment_id, total_marks, passing_marks
     FROM assessments WHERE assessment_id = ? AND created_by = ?",
    "ii", [$assessmentId, $teacherId]
);

if (!$asmRes['success'] || !$asmRes['result'] || $asmRes['result']->num_rows === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Assessment not found or access denied.']);
    exit;
}
$asm = $asmRes['result']->fetch_assoc();
$asmRes['result']->free();

$totalMarks   = (int)$asm['total_marks'];
$passingMarks = (int)$asm['passing_marks'];
$passPct      = $totalMarks > 0 ? round($passingMarks / $totalMarks * 100, 2) : 0;

// Fetch all attempts — use direct query (safe: assessmentId is cast to int)
$raw = $conn->query(
    "SELECT
        aa.attempt_id,
        aa.score,
        aa.percentage,
        aa.submitted_at,
        u.full_name   AS student_name,
        u.email,
        u.department,
        u.registration_number
     FROM assessment_attempts aa
     LEFT JOIN users u ON u.user_id = aa.user_id
     WHERE aa.assessment_id = $assessmentId
       AND aa.status IN ('submitted','completed','timeout')
     ORDER BY aa.percentage DESC"
);

if (!$raw) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Query failed: ' . $conn->error]);
    exit;
}

$results     = [];
$totalPct    = 0;
$passCount   = 0;
$studentIds  = [];

while ($row = $raw->fetch_assoc()) {
    $pct = (float)($row['percentage'] ?? 0);
    $totalPct += $pct;
    if ($pct >= $passPct) $passCount++;
    $results[] = [
        'attempt_id'   => (int)$row['attempt_id'],
        'student_name' => $row['student_name'] ?? 'Unknown',
        'email'        => $row['email']         ?? '',
        'department'   => $row['department']    ?? '',
        'reg_no'       => $row['registration_number'] ?? '',
        'score'        => round((float)($row['score'] ?? 0), 2),
        'percentage'   => round($pct, 2),
        'submitted_at' => $row['submitted_at'],
    ];
    $studentIds[] = $row['attempt_id'];
}
$raw->free();

$count    = count($results);
$avgScore = $count > 0 ? round($totalPct / $count, 1) : 0;
$passRate = $count > 0 ? round($passCount / $count * 100) : 0;

echo json_encode([
    'success' => true,
    'results' => $results,
    'meta'    => [
        'total_students' => $count,
        'avg_score'      => $avgScore,
        'pass_rate'      => $passRate,
        'pass_percentage'=> $passPct,
        'passing_marks'  => $passingMarks,
        'total_marks'    => $totalMarks,
    ],
]);

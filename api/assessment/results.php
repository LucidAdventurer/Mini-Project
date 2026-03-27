<?php
// ============================================================
// api/assessments/results.php
// Returns JSON results for a given assessment (teacher view).
// GET ?assessment_id=int
// Returns {
//   success: bool,
//   results: [...],      ← best attempt per student
//   meta: { total_students, avg_score, pass_rate, pass_percentage,
//           passing_marks, total_marks, max_attempts }
//   multi_attempt: bool  ← true when max_attempts > 1
// }
//
// When multi_attempt === true, each result row also contains:
//   attempts: [{ attempt_number, score, percentage, submitted_at, status }]
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
    "SELECT assessment_id, total_marks, passing_marks, max_attempts
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
$maxAttempts  = (int)($asm['max_attempts'] ?? 1);
$passPct      = $totalMarks > 0 ? round($passingMarks / $totalMarks * 100, 2) : 0;
$isMultiAttempt = $maxAttempts > 1;

// ── Fetch all completed attempts, ordered by user then attempt number ──
// Status values from live schema: 'in_progress','completed','abandoned','timeout','under_review'
$raw = $conn->query(
    "SELECT
        aa.attempt_id,
        aa.user_id,
        aa.attempt_number,
        aa.score,
        aa.percentage,
        aa.submitted_at,
        aa.status,
        aa.correct_answers,
        aa.wrong_answers,
        aa.unanswered,
        u.full_name          AS student_name,
        u.email,
        u.department,
        u.registration_number
     FROM assessment_attempts aa
     LEFT JOIN users u ON u.user_id = aa.user_id
     WHERE aa.assessment_id = $assessmentId
       AND aa.status IN ('completed','timeout','under_review')
     ORDER BY aa.user_id ASC, aa.attempt_number ASC"
);

if (!$raw) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Query failed: ' . $conn->error]);
    exit;
}

// ── Group attempts by student ──
$studentMap = [];   // keyed by user_id (or fallback key for guests)

while ($row = $raw->fetch_assoc()) {
    $uid = $row['user_id'] ?? ('guest_' . $row['attempt_id']);

    if (!isset($studentMap[$uid])) {
        $studentMap[$uid] = [
            'user_id'      => $row['user_id'],
            'student_name' => $row['student_name'] ?? 'Unknown',
            'email'        => $row['email']         ?? '',
            'department'   => $row['department']    ?? '',
            'reg_no'       => $row['registration_number'] ?? '',
            'attempts'     => [],
            'best_pct'     => -1,
            'best_idx'     => 0,
        ];
    }

    $pct = (float)($row['percentage'] ?? 0);
    $attemptEntry = [
        'attempt_id'      => (int)$row['attempt_id'],
        'attempt_number'  => (int)$row['attempt_number'],
        'score'           => round((float)($row['score'] ?? 0), 2),
        'percentage'      => round($pct, 2),
        'submitted_at'    => $row['submitted_at'],
        'status'          => $row['status'],
        'correct_answers' => (int)($row['correct_answers'] ?? 0),
        'wrong_answers'   => (int)($row['wrong_answers']   ?? 0),
        'unanswered'      => (int)($row['unanswered']      ?? 0),
    ];

    $studentMap[$uid]['attempts'][] = $attemptEntry;

    // Track best attempt (highest percentage)
    if ($pct > $studentMap[$uid]['best_pct']) {
        $studentMap[$uid]['best_pct'] = $pct;
        $studentMap[$uid]['best_idx'] = count($studentMap[$uid]['attempts']) - 1;
    }
}
$raw->free();

// ── Build result rows (one per student, using best attempt) ──
$results   = [];
$totalPct  = 0;
$passCount = 0;

foreach ($studentMap as $uid => $s) {
    $best = $s['attempts'][$s['best_idx']];
    $pct  = $best['percentage'];
    $totalPct += $pct;
    if ($pct >= $passPct) $passCount++;

    $row = [
        'attempt_id'      => $best['attempt_id'],
        'student_name'    => $s['student_name'],
        'email'           => $s['email'],
        'department'      => $s['department'],
        'reg_no'          => $s['reg_no'],
        'score'           => $best['score'],
        'percentage'      => $pct,
        'submitted_at'    => $best['submitted_at'],
        'total_attempts'  => count($s['attempts']),
    ];

    // Include full attempt breakdown only when multi-attempt is enabled
    if ($isMultiAttempt) {
        $row['attempts'] = $s['attempts'];
    }

    $results[] = $row;
}

// Sort by best percentage descending
usort($results, fn($a, $b) => $b['percentage'] <=> $a['percentage']);

$count    = count($results);
$avgScore = $count > 0 ? round($totalPct / $count, 1) : 0;
$passRate = $count > 0 ? round($passCount / $count * 100) : 0;

echo json_encode([
    'success'       => true,
    'results'       => $results,
    'multi_attempt' => $isMultiAttempt,
    'meta'          => [
        'total_students'  => $count,
        'avg_score'       => $avgScore,
        'pass_rate'       => $passRate,
        'pass_percentage' => $passPct,
        'passing_marks'   => $passingMarks,
        'total_marks'     => $totalMarks,
        'max_attempts'    => $maxAttempts,
    ],
]);
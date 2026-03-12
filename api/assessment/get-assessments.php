<?php
// ============================================================
// api/assessment/get-assessments.php
//
// Returns all active/available assessments for the logged-in student.
// Split into not_attended and attended (has a submitted attempt).
//
// Best attempt stats (correct/wrong/unanswered) are derived on the
// fly from the answers table — assessment_attempts no longer stores
// those columns.
//
// GET (no body)
// Returns { success: bool, not_attended: [...], attended: [...] }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$currentUser = validateSession($conn, 'student');
$studentId   = (int) $currentUser['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$now = date('Y-m-d H:i:s');

// ── Fetch assessments accessible to this student ──
// Accessible = visibility 'public'  OR  student targeted  OR  group targeted
$result = safePreparedQuery($conn,
    "SELECT
        a.assessment_id,
        a.title,
        a.description,
        a.category,
        a.difficulty,
        a.duration_minutes,
        a.total_marks,
        a.passing_marks,
        a.max_attempts,
        a.visibility,
        a.start_time,
        a.end_time,
        a.randomize_questions,
        a.randomize_options,
        a.created_at,
        u.full_name AS created_by_name,

        -- Question count
        (SELECT COUNT(*) FROM questions q WHERE q.assessment_id = a.assessment_id) AS question_count,

        -- Attempts used (submitted or timeout)
        (SELECT COUNT(*) FROM assessment_attempts aa
         WHERE aa.assessment_id = a.assessment_id
           AND aa.user_id = ?
           AND aa.status IN ('submitted', 'timeout')) AS attempts_used,

        -- Active in-progress attempt (if any)
        (SELECT aa2.attempt_id FROM assessment_attempts aa2
         WHERE aa2.assessment_id = a.assessment_id
           AND aa2.user_id = ?
           AND aa2.status = 'in_progress'
         ORDER BY aa2.created_at DESC LIMIT 1) AS in_progress_attempt_id,

        -- Best submitted attempt
        best.attempt_id     AS best_attempt_id,
        best.score          AS best_score,
        best.percentage     AS best_percentage,
        best.submitted_at   AS best_submitted_at,
        best.attempt_number AS best_attempt_number

     FROM assessments a
     JOIN users u ON u.user_id = a.created_by

     LEFT JOIN (
         SELECT
             aa.assessment_id,
             aa.attempt_id,
             aa.score,
             aa.percentage,
             aa.submitted_at,
             aa.attempt_number
         FROM assessment_attempts aa
         WHERE aa.user_id = ?
           AND aa.status  = 'submitted'
           AND aa.percentage = (
               SELECT MAX(aa2.percentage)
               FROM assessment_attempts aa2
               WHERE aa2.assessment_id = aa.assessment_id
                 AND aa2.user_id       = aa.user_id
                 AND aa2.status        = 'submitted'
                 AND aa2.user_id       = aa.user_id
                 AND aa2.status        = 'submitted'
           )
         GROUP BY aa.assessment_id
     ) best ON best.assessment_id = a.assessment_id

     WHERE a.status = 'active'
       AND (a.start_time IS NULL OR a.start_time <= ?)
       AND (a.end_time   IS NULL OR a.end_time   >= ?)
       AND (
           a.visibility = 'public'
           OR EXISTS (
               SELECT 1 FROM assessment_targets at2
               WHERE at2.assessment_id = a.assessment_id
                 AND at2.target_type   = 'student'
                 AND at2.target_id     = ?
           )
           OR EXISTS (
               SELECT 1 FROM assessment_targets at3
               JOIN group_members gm ON gm.group_id = at3.target_id
               WHERE at3.assessment_id = a.assessment_id
                 AND at3.target_type   = 'group'
                 AND gm.student_id     = ?
           )
       )
     ORDER BY a.created_at DESC",
    "iiiissii",
    [$studentId, $studentId, $studentId, $now, $now, $studentId, $studentId]
);

if (!$result['success']) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to load assessments.']);
    exit;
}

$notAttended = [];
$attended    = [];

if ($result['result']) {
    while ($row = $result['result']->fetch_assoc()) {
        $attemptsUsed = (int)$row['attempts_used'];
        $maxAttempts  = (int)$row['max_attempts'];

        $assessment = [
            'assessment_id'          => (int) $row['assessment_id'],
            'title'                  => $row['title'],
            'description'            => $row['description'],
            'category'               => $row['category'],
            'difficulty'             => $row['difficulty'],
            'duration_minutes'       => (int) $row['duration_minutes'],
            'total_marks'            => (int) $row['total_marks'],
            'passing_marks'          => (int) $row['passing_marks'],
            'max_attempts'           => (int) $row['max_attempts'],
            'visibility'             => $row['visibility'],
            'start_time'             => $row['start_time'],
            'end_time'               => $row['end_time'],
            'randomize_questions'    => (bool) $row['randomize_questions'],
            'randomize_options'      => (bool) $row['randomize_options'],
            'question_count'         => (int) $row['question_count'],
            'created_by_name'        => $row['created_by_name'],
            'attempts_used'          => (int) $row['attempts_used'],
            'in_progress_attempt_id' => $row['in_progress_attempt_id']
                                            ? (int) $row['in_progress_attempt_id']
                                            : null,
            'can_attempt'            => ((int)$row['attempts_used'] < (int)$row['max_attempts']),
        ];

        if ($row['best_attempt_id']) {
            $bestAttemptId = (int)$row['best_attempt_id'];
            $totalMarks    = (int)$row['total_marks'];
            $passingMarks  = (int)$row['passing_marks'];
            $bestPct       = (float)$row['best_percentage'];

            // Derive correct/wrong/unanswered counts from answers table
            $statsResult = safePreparedQuery($conn,
                "SELECT
                    COUNT(ans.answer_id)                                          AS total_answered,
                    SUM(CASE WHEN ans.marks_awarded > 0 THEN 1 ELSE 0 END)       AS correct_count,
                    SUM(CASE WHEN ans.marks_awarded < 0 THEN 1 ELSE 0 END)       AS wrong_count,
                    (SELECT COUNT(*) FROM questions qq
                     WHERE qq.assessment_id = ?) AS total_questions
                 FROM answers ans
                 WHERE ans.attempt_id = ?",
                "ii", [(int)$row['assessment_id'], $bestAttemptId]
            );

            $correct    = 0;
            $wrong      = 0;
            $unanswered = 0;
            $totalQ     = (int)$row['question_count'];

            if ($statsResult['success'] && $statsResult['result']) {
                $sr         = $statsResult['result']->fetch_assoc();
                $statsResult['result']->free();
                $correct    = (int)($sr['correct_count']   ?? 0);
                $wrong      = (int)($sr['wrong_count']     ?? 0);
                $totalQ     = (int)($sr['total_questions'] ?? $totalQ);
                $answered   = (int)($sr['total_answered']  ?? 0);
                $unanswered = max(0, $totalQ - $answered);
            }

            $assessment['best_attempt'] = [
                'attempt_id'     => $bestAttemptId,
                'score'          => (float)$row['best_score'],
                'percentage'     => $bestPct,
                'correct'        => $correct,
                'wrong'          => $wrong,
                'unanswered'     => $unanswered,
                'submitted_at'   => $row['best_submitted_at'],
                'attempt_number' => (int)$row['best_attempt_number'],
                'passed'         => $totalMarks > 0
                    ? ($bestPct >= ($passingMarks / $totalMarks * 100))
                    : false,
            ];
            $attended[] = $assessment;
        } else {
            $notAttended[] = $assessment;
        }
    }
    $result['result']->free();
}

echo json_encode([
    'success'      => true,
    'not_attended' => $notAttended,
    'attended'     => $attended,
]);
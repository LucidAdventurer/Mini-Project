<?php
// ============================================================
// api/assessment/get-assessments.php
//
// Returns all published/available assessments for the student,
// split into not_attended and attended.
//
// Schema fixes:
// - status 'active' → 'published'; 'completed' → 'submitted'
// - available_from/available_until → start_time/end_time
// - removed non-existent: show_results_immediately, instructions,
//   is_public, correct_answers, wrong_answers, unanswered
// - access control uses visibility + assessment_targets table
//
// GET (no body needed)
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
        a.start_time,
        a.end_time,
        a.created_at,

        -- Question count
        (SELECT COUNT(*) FROM questions q WHERE q.assessment_id = a.assessment_id) AS question_count,

        -- Creator name
        u.full_name AS created_by_name,

        -- Best submitted attempt
        best.attempt_id     AS best_attempt_id,
        best.score          AS best_score,
        best.percentage     AS best_percentage,
        best.submitted_at   AS best_submitted_at,
        best.attempt_number AS best_attempt_number,

        -- Total submitted/timeout attempts by student
        (SELECT COUNT(*) FROM assessment_attempts aa
         WHERE aa.assessment_id = a.assessment_id
           AND aa.user_id = ?
           AND aa.status IN ('submitted','timeout')) AS attempts_used,

        -- Any in-progress attempt?
        (SELECT aa2.attempt_id FROM assessment_attempts aa2
         WHERE aa2.assessment_id = a.assessment_id
           AND aa2.user_id = ?
           AND aa2.status = 'in_progress'
         ORDER BY aa2.created_at DESC LIMIT 1) AS in_progress_attempt_id

     FROM assessments a
     JOIN users u ON u.user_id = a.created_by

     -- Best submitted attempt (highest percentage)
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
           AND aa.status = 'submitted'
           AND aa.percentage = (
               SELECT MAX(aa2.percentage)
               FROM assessment_attempts aa2
               WHERE aa2.assessment_id = aa.assessment_id
                 AND aa2.user_id       = aa.user_id
                 AND aa2.status        = 'submitted'
           )
         GROUP BY aa.assessment_id
     ) best ON best.assessment_id = a.assessment_id

     WHERE a.status = 'published'
       AND (a.start_time IS NULL OR a.start_time <= ?)
       AND (a.end_time   IS NULL OR a.end_time   >= ?)
       AND (
           a.visibility = 'public'
           OR (a.visibility = 'group' AND EXISTS (
               SELECT 1 FROM assessment_targets at2
               WHERE at2.assessment_id = a.assessment_id
                 AND (
                     (at2.target_type = 'student' AND at2.target_id = ?)
                     OR (at2.target_type = 'group' AND at2.target_id IN (
                         SELECT gm.group_id FROM group_members gm WHERE gm.student_id = ?
                     ))
                 )
           ))
       )
     ORDER BY a.created_at DESC",
    "iiissii",
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
            'max_attempts'           => $maxAttempts,
            'start_time'             => $row['start_time'],
            'end_time'               => $row['end_time'],
            'question_count'         => (int) $row['question_count'],
            'created_by_name'        => $row['created_by_name'],
            'attempts_used'          => $attemptsUsed,
            'in_progress_attempt_id' => $row['in_progress_attempt_id'] ? (int)$row['in_progress_attempt_id'] : null,
            'can_attempt'            => ($attemptsUsed < $maxAttempts),
        ];

        if ($row['best_attempt_id']) {
            $passingPct = (int)$row['total_marks'] > 0
                ? ((int)$row['passing_marks'] / (int)$row['total_marks']) * 100
                : 0;

            $assessment['best_attempt'] = [
                'attempt_id'     => (int)  $row['best_attempt_id'],
                'score'          => (float) $row['best_score'],
                'percentage'     => (float) $row['best_percentage'],
                'submitted_at'   => $row['best_submitted_at'],
                'attempt_number' => (int)  $row['best_attempt_number'],
                'passed'         => ((float)$row['best_percentage'] >= $passingPct),
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
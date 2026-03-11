<?php
// ============================================================
// api/assessment/get-assessments.php
//
// Returns all active/available assessments for the logged-in
// student, split into:
//   - not_attended : assessments the student has NOT completed
//   - attended     : assessments the student HAS completed
//                    (status = 'completed'), with their best result
//
// GET (no body needed)
// Returns {
//   success: bool,
//   not_attended: [...],
//   attended: [...]
// }
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

// ── Fetch all active assessments that are currently available ──
// Includes question count and the student's best completed attempt (if any).
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
        a.show_results_immediately,
        a.available_from,
        a.available_until,
        a.instructions,
        a.is_public,
        a.created_at,

        -- Question count
        (SELECT COUNT(*) FROM questions q WHERE q.assessment_id = a.assessment_id) AS question_count,

        -- Creator name
        u.full_name AS created_by_name,

        -- Student's best completed attempt
        best.attempt_id       AS best_attempt_id,
        best.score            AS best_score,
        best.percentage       AS best_percentage,
        best.correct_answers  AS best_correct,
        best.wrong_answers    AS best_wrong,
        best.unanswered       AS best_unanswered,
        best.submitted_at     AS best_submitted_at,
        best.attempt_number   AS best_attempt_number,

        -- Total attempts by student
        (SELECT COUNT(*) FROM assessment_attempts aa
         WHERE aa.assessment_id = a.assessment_id
           AND aa.user_id = ?
           AND aa.status IN ('completed','timeout')) AS attempts_used,

        -- Has any in-progress attempt?
        (SELECT aa2.attempt_id FROM assessment_attempts aa2
         WHERE aa2.assessment_id = a.assessment_id
           AND aa2.user_id = ?
           AND aa2.status = 'in_progress'
         ORDER BY aa2.created_at DESC LIMIT 1) AS in_progress_attempt_id

     FROM assessments a
     JOIN users u ON u.user_id = a.created_by

     -- Best completed attempt subquery
     LEFT JOIN (
         SELECT
             aa.assessment_id,
             aa.attempt_id,
             aa.score,
             aa.percentage,
             aa.correct_answers,
             aa.wrong_answers,
             aa.unanswered,
             aa.submitted_at,
             aa.attempt_number
         FROM assessment_attempts aa
         WHERE aa.user_id = ?
           AND aa.status = 'completed'
           AND aa.percentage = (
               SELECT MAX(aa2.percentage)
               FROM assessment_attempts aa2
               WHERE aa2.assessment_id = aa.assessment_id
                 AND aa2.user_id = aa.user_id
                 AND aa2.status = 'completed'
           )
         GROUP BY aa.assessment_id
     ) best ON best.assessment_id = a.assessment_id

     WHERE a.status = 'active'
       AND (a.available_from IS NULL OR a.available_from <= ?)
       AND (a.available_until IS NULL OR a.available_until >= ?)
     ORDER BY a.created_at DESC",
    "iiiss",
    [$studentId, $studentId, $studentId, $now, $now]
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
        $assessment = [
            'assessment_id'            => (int) $row['assessment_id'],
            'title'                    => $row['title'],
            'description'              => $row['description'],
            'category'                 => $row['category'],
            'difficulty'               => $row['difficulty'],
            'duration_minutes'         => (int) $row['duration_minutes'],
            'total_marks'              => (int) $row['total_marks'],
            'passing_marks'            => (int) $row['passing_marks'],
            'max_attempts'             => (int) $row['max_attempts'],
            'show_results_immediately' => (bool) $row['show_results_immediately'],
            'available_from'           => $row['available_from'],
            'available_until'          => $row['available_until'],
            'instructions'             => $row['instructions'],
            'question_count'           => (int) $row['question_count'],
            'created_by_name'          => $row['created_by_name'],
            'attempts_used'            => (int) $row['attempts_used'],
            'in_progress_attempt_id'   => $row['in_progress_attempt_id'] ? (int) $row['in_progress_attempt_id'] : null,
            'can_attempt'              => ((int)$row['attempts_used'] < (int)$row['max_attempts']),
        ];

        // Has a completed attempt?
        if ($row['best_attempt_id']) {
            $assessment['best_attempt'] = [
                'attempt_id'     => (int) $row['best_attempt_id'],
                'score'          => (float) $row['best_score'],
                'percentage'     => (float) $row['best_percentage'],
                'correct'        => (int) $row['best_correct'],
                'wrong'          => (int) $row['best_wrong'],
                'unanswered'     => (int) $row['best_unanswered'],
                'submitted_at'   => $row['best_submitted_at'],
                'attempt_number' => (int) $row['best_attempt_number'],
                'passed'         => ((float)$row['best_percentage'] >= ((int)$row['passing_marks'] / (int)$row['total_marks'] * 100)),
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

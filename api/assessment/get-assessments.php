<?php
// ============================================================
// api/assessment/get-assessments.php
//
// CHANGE: Replaced LEFT JOIN questions + LEFT JOIN attempts
// (which caused row multiplication requiring DISTINCT + GROUP BY)
// with correlated subqueries. Each subquery hits one table with
// a targeted index lookup — no row explosion, no heavy aggregation.
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

$currentUser = validateSession($conn, 'teacher');
$teacherId   = (int) $currentUser['user_id'];

// ── Query params ──
$status = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');
$page   = max(1, (int)($_GET['page']  ?? 1));
$limit  = min(100, max(1, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

$allowedStatuses = ['published', 'draft', 'archived'];

// ── Build WHERE clause ──
$conditions = ["a.created_by = ?"];
$params     = [$teacherId];
$types      = "i";

if ($status !== '' && in_array($status, $allowedStatuses, true)) {
    $conditions[] = "a.status = ?";
    $params[]     = $status;
    $types       .= "s";
}

if ($search !== '') {
    $conditions[] = "(a.title LIKE ? OR a.category LIKE ?)";
    $like         = '%' . $search . '%';
    $params[]     = $like;
    $params[]     = $like;
    $types       .= "ss";
}

$where = implode(' AND ', $conditions);

// ── Total count ──
$total = 0;
$rc = safePreparedQuery($conn,
    "SELECT COUNT(*) AS cnt FROM assessments a WHERE $where",
    $types, $params
);
if ($rc['success'] && $rc['result']) {
    $row   = $rc['result']->fetch_assoc();
    $total = (int)($row['cnt'] ?? 0);
    $rc['result']->free();
}

// ── Fetch page using correlated subqueries ──
// Each subquery does a single indexed lookup per row.
// No row multiplication, no GROUP BY, no DISTINCT needed.
$listTypes  = $types . "ii";
$listParams = array_merge($params, [$limit, $offset]);

$r = safePreparedQuery($conn,
    "SELECT
        a.assessment_id,
        a.title,
        a.category,
        a.difficulty,
        a.status,
        a.duration_minutes,
        a.total_marks,
        a.passing_marks,
        a.is_public,
        a.start_time,
        a.end_time,
        a.created_at,
        a.updated_at,
        (SELECT COUNT(*)
         FROM questions q
         WHERE q.assessment_id = a.assessment_id)                              AS question_count,
        (SELECT COUNT(*)
         FROM assessment_attempts aa
         WHERE aa.assessment_id = a.assessment_id
           AND aa.status = 'submitted')                                        AS attempt_count,
        (SELECT COUNT(DISTINCT aa2.user_id)
         FROM assessment_attempts aa2
         WHERE aa2.assessment_id = a.assessment_id
           AND aa2.status = 'submitted'
           AND aa2.user_id IS NOT NULL)                                        AS student_count,
        (SELECT ROUND(AVG(aa3.percentage), 1)
         FROM assessment_attempts aa3
         WHERE aa3.assessment_id = a.assessment_id
           AND aa3.status = 'submitted')                                       AS avg_score
     FROM assessments a
     WHERE $where
     ORDER BY FIELD(a.status,'published','draft','archived'), a.updated_at DESC
     LIMIT ? OFFSET ?",
    $listTypes, $listParams
);

$assessments = [];
if ($r['success'] && $r['result']) {
    while ($row = $r['result']->fetch_assoc()) {
        $assessments[] = [
            'assessment_id'    => (int)$row['assessment_id'],
            'title'            => $row['title'],
            'category'         => $row['category'],
            'difficulty'       => $row['difficulty'],
            'status'           => $row['status'],
            'duration_minutes' => (int)$row['duration_minutes'],
            'total_marks'      => (int)$row['total_marks'],
            'passing_marks'    => (int)$row['passing_marks'],
            'is_public'        => (bool)$row['is_public'],
            'start_time'       => $row['start_time'],
            'end_time'         => $row['end_time'],
            'created_at'       => $row['created_at'],
            'updated_at'       => $row['updated_at'],
            'question_count'   => (int)$row['question_count'],
            'attempt_count'    => (int)$row['attempt_count'],
            'student_count'    => (int)$row['student_count'],
            'avg_score'        => (float)($row['avg_score'] ?? 0),
        ];
    }
    $r['result']->free();
}

echo json_encode([
    'success'     => true,
    'assessments' => $assessments,
    'total'       => $total,
    'page'        => $page,
    'pages'       => (int) ceil($total / $limit),
]);
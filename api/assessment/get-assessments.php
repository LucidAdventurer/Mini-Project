<?php
// ============================================================
// api/assessment/get-assessments.php
//
// Returns all assessments created by the logged-in teacher,
// with question count, attempt count, and student count.
//
// GET ?status=active|draft|archived|scheduled  (optional)
//     ?search=keyword                           (optional)
//     ?page=1&limit=20                          (optional)
//
// Returns {
//   success: bool,
//   assessments: [...],
//   total: int,
//   page: int,
//   pages: int
// }
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

$allowedStatuses = ['active', 'draft', 'archived', 'scheduled'];

// ── Build WHERE clause ──
$conditions = ["a.created_by = ?"];
$params     = [$teacherId];
$types      = "i";

if ($status && in_array($status, $allowedStatuses, true)) {
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

// ── Fetch page ──
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
        a.available_from,
        a.available_until,
        a.created_at,
        a.updated_at,
        COUNT(DISTINCT q.question_id)   AS question_count,
        COUNT(DISTINCT aa.attempt_id)   AS attempt_count,
        COUNT(DISTINCT aa.user_id)      AS student_count,
        ROUND(AVG(aa.percentage), 1)    AS avg_score
     FROM assessments a
     LEFT JOIN questions q
            ON q.assessment_id = a.assessment_id
     LEFT JOIN assessment_attempts aa
            ON aa.assessment_id = a.assessment_id
           AND aa.status = 'completed'
     WHERE $where
     GROUP BY a.assessment_id
     ORDER BY FIELD(a.status,'active','scheduled','draft','archived'), a.updated_at DESC
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
            'available_from'   => $row['available_from'],
            'available_until'  => $row['available_until'],
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
    'pages'       => (int)ceil($total / $limit),
]);
<?php
// ============================================================
// api/assessment/get-assessments-admin.php
//
// Admin-only. Returns ALL assessments across all teachers with
// aggregate stats. Supports filtering, search, and pagination.
//
// GET ?status=active|draft|archived
//     ?search=keyword
//     ?category=...
//     ?page=1&limit=20
//
// Returns {
//   success: bool,
//   assessments: [...],
//   total: int, page: int, pages: int,
//   stats: { total, active, drafts, archived, avg_pass_rate }
// }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

validateSession($conn, 'admin');

// ── Query params ──
$status   = trim($_GET['status']   ?? '');
$search   = trim($_GET['search']   ?? '');
$category = trim($_GET['category'] ?? '');
$page     = max(1, (int)($_GET['page']  ?? 1));
$limit    = min(100, max(1, (int)($_GET['limit'] ?? 20)));
$offset   = ($page - 1) * $limit;

// 'scheduled' does not exist in live schema
$allowedStatuses   = ['active', 'draft', 'archived'];
$allowedCategories = ['aptitude', 'technical', 'coding', 'reasoning', 'english', 'general'];

// ── Build WHERE clause ──
$conditions = ["1=1"];
$params     = [];
$types      = "";

if ($status !== '' && in_array($status, $allowedStatuses, true)) {
    $conditions[] = "a.status = ?";
    $params[]     = $status;
    $types       .= "s";
}

if ($category !== '' && in_array($category, $allowedCategories, true)) {
    $conditions[] = "a.category = ?";
    $params[]     = $category;
    $types       .= "s";
}

if ($search !== '') {
    $conditions[] = "(a.title LIKE ? OR a.category LIKE ? OR u.full_name LIKE ?)";
    $like         = '%' . $search . '%';
    $params[]     = $like;
    $params[]     = $like;
    $params[]     = $like;
    $types       .= "sss";
}

$where = implode(' AND ', $conditions);

// ── Aggregate stats ──
$rsStats = safePreparedQuery($conn,
    "SELECT
        COUNT(*)                                                    AS total,
        SUM(CASE WHEN a.status = 'active'   THEN 1 ELSE 0 END)    AS active,
        SUM(CASE WHEN a.status = 'draft'    THEN 1 ELSE 0 END)    AS drafts,
        SUM(CASE WHEN a.status = 'archived' THEN 1 ELSE 0 END)    AS archived,
        ROUND(
            AVG(
                CASE WHEN aa_stats.completed > 0
                THEN (aa_stats.pass_count / aa_stats.completed) * 100
                ELSE NULL END
            ), 1
        ) AS avg_pass_rate
     FROM assessments a
     JOIN users u ON u.user_id = a.created_by
     LEFT JOIN (
         SELECT
             aa.assessment_id,
             SUM(CASE WHEN aa.status IN ('submitted','timeout') THEN 1 ELSE 0 END) AS completed,
             SUM(CASE WHEN aa.status IN ('submitted','timeout')
                       AND aa.percentage >= (
                           SELECT (asm.passing_marks / asm.total_marks) * 100
                           FROM assessments asm WHERE asm.assessment_id = aa.assessment_id
                       ) THEN 1 ELSE 0 END) AS pass_count
         FROM assessment_attempts aa
         GROUP BY aa.assessment_id
     ) aa_stats ON aa_stats.assessment_id = a.assessment_id
     WHERE $where",
    $types ?: "", $params ?: []
);

$stats = ['total' => 0, 'active' => 0, 'drafts' => 0, 'archived' => 0, 'avg_pass_rate' => 0];
if ($rsStats['success'] && $rsStats['result']) {
    $row = $rsStats['result']->fetch_assoc();
    if ($row) {
        $stats = [
            'total'         => (int)($row['total']         ?? 0),
            'active'        => (int)($row['active']        ?? 0),
            'drafts'        => (int)($row['drafts']        ?? 0),
            'archived'      => (int)($row['archived']      ?? 0),
            'avg_pass_rate' => (float)($row['avg_pass_rate'] ?? 0),
        ];
    }
    $rsStats['result']->free();
}

// ── Total count for pagination ──
$total = 0;
$rc = safePreparedQuery($conn,
    "SELECT COUNT(*) AS cnt
     FROM assessments a
     JOIN users u ON u.user_id = a.created_by
     WHERE $where",
    $types ?: "", $params ?: []
);
if ($rc['success'] && $rc['result']) {
    $row   = $rc['result']->fetch_assoc();
    $total = (int)($row['cnt'] ?? 0);
    $rc['result']->free();
}

// ── Fetch paginated rows ──
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
        a.visibility,
        a.start_time,
        a.end_time,
        a.created_at,
        a.updated_at,
        u.full_name                                  AS teacher_name,
        u.user_id                                    AS teacher_id,
        COUNT(DISTINCT q.question_id)                AS question_count,
        COUNT(DISTINCT aa.attempt_id)                AS attempt_count,
        COUNT(DISTINCT aa.user_id)                   AS student_count,
        ROUND(AVG(aa.percentage), 1)                 AS avg_score,
        SUM(CASE WHEN aa.status IN ('submitted','timeout')
                  AND aa.percentage >= (a.passing_marks / NULLIF(a.total_marks,0) * 100)
             THEN 1 ELSE 0 END)                      AS pass_count,
        SUM(CASE WHEN aa.status IN ('submitted','timeout') THEN 1 ELSE 0 END) AS completed_count
     FROM assessments a
     JOIN users u ON u.user_id = a.created_by
     LEFT JOIN questions q ON q.assessment_id = a.assessment_id
     LEFT JOIN assessment_attempts aa ON aa.assessment_id = a.assessment_id
     WHERE $where
     GROUP BY a.assessment_id, u.full_name, u.user_id
     ORDER BY FIELD(a.status,'active','draft','archived'), a.updated_at DESC
     LIMIT ? OFFSET ?",
    $listTypes, $listParams
);

$assessments = [];
if ($r['success'] && $r['result']) {
    while ($row = $r['result']->fetch_assoc()) {
        $completed = (int)($row['completed_count'] ?? 0);
        $passCount = (int)($row['pass_count']      ?? 0);
        $passRate  = $completed > 0 ? round(($passCount / $completed) * 100, 1) : 0;

        $assessments[] = [
            'assessment_id'    => (int)$row['assessment_id'],
            'title'            => $row['title'],
            'category'         => $row['category'],
            'difficulty'       => $row['difficulty'],
            'status'           => $row['status'],
            'duration_minutes' => (int)$row['duration_minutes'],
            'total_marks'      => (int)$row['total_marks'],
            'passing_marks'    => (int)$row['passing_marks'],
            'visibility'       => $row['visibility'],
            'start_time'       => $row['start_time'],
            'end_time'         => $row['end_time'],
            'created_at'       => $row['created_at'],
            'updated_at'       => $row['updated_at'],
            'teacher_name'     => $row['teacher_name'],
            'teacher_id'       => (int)$row['teacher_id'],
            'question_count'   => (int)$row['question_count'],
            'attempt_count'    => (int)$row['attempt_count'],
            'student_count'    => (int)$row['student_count'],
            'avg_score'        => (float)($row['avg_score'] ?? 0),
            'pass_rate'        => $passRate,
        ];
    }
    $r['result']->free();
}

echo json_encode([
    'success'     => true,
    'assessments' => $assessments,
    'total'       => $total,
    'page'        => $page,
    'pages'       => (int)ceil($total / max(1, $limit)),
    'stats'       => $stats,
]);
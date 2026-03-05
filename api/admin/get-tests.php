<?php
// ============================================================
// api/admin/get-tests.php
//
// Admin: paginated assessment list with stats.
//
// GET ?status=all|active|draft|archived|scheduled
//     ?search=keyword
//     ?category=aptitude|technical|...
//     ?page=1&limit=20
//
// Returns {
//   success, assessments:[...], total, page, pages,
//   counts:{ all, active, draft, archived, scheduled },
//   stats:{ total_assessments, active, draft, total_attempts, avg_pass_rate }
// }
// ============================================================
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');
validateSession($conn, 'admin');

$status   = trim($_GET['status']   ?? 'all');
$search   = trim($_GET['search']   ?? '');
$category = trim($_GET['category'] ?? '');
$page     = max(1, (int)($_GET['page']  ?? 1));
$limit    = min(100, max(1, (int)($_GET['limit'] ?? 20)));
$offset   = ($page - 1) * $limit;

$allowedStatuses = ['all', 'active', 'draft', 'archived', 'scheduled'];
if (!in_array($status, $allowedStatuses, true)) $status = 'all';

// ── WHERE builder ──
$conditions = [];
$params     = [];
$types      = '';

if ($status !== 'all') {
    $conditions[] = 'a.status = ?';
    $params[]     = $status;
    $types       .= 's';
}
if ($search !== '') {
    $conditions[] = '(a.title LIKE ? OR a.category LIKE ? OR u.full_name LIKE ?)';
    $like         = '%' . $search . '%';
    $params[]     = $like; $params[] = $like; $params[] = $like;
    $types       .= 'sss';
}
if ($category !== '') {
    $conditions[] = 'a.category = ?';
    $params[]     = $category;
    $types       .= 's';
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

// ── Status counts ──
$counts = ['all' => 0, 'active' => 0, 'draft' => 0, 'archived' => 0, 'scheduled' => 0];

// Build search-only where for counts (exclude status filter)
$cCond = []; $cParams = []; $cTypes = '';
if ($search !== '') {
    $cCond[]    = '(a.title LIKE ? OR a.category LIKE ? OR u.full_name LIKE ?)';
    $like       = '%' . $search . '%';
    $cParams[]  = $like; $cParams[] = $like; $cParams[] = $like;
    $cTypes    .= 'sss';
}
if ($category !== '') {
    $cCond[]   = 'a.category = ?';
    $cParams[] = $category;
    $cTypes   .= 's';
}
$cWhere = $cCond ? 'WHERE ' . implode(' AND ', $cCond) : '';

$rc = safePreparedQuery($conn,
    "SELECT a.status, COUNT(*) AS cnt
     FROM assessments a
     LEFT JOIN users u ON u.user_id = a.created_by
     $cWhere
     GROUP BY a.status",
    $cTypes, $cParams
);
if ($rc['success'] && $rc['result']) {
    while ($row = $rc['result']->fetch_assoc()) {
        $s = $row['status'];
        if (isset($counts[$s])) $counts[$s] = (int)$row['cnt'];
        $counts['all'] += (int)$row['cnt'];
    }
    $rc['result']->free();
}

// ── Top-level stats ──
$stats = ['total_assessments' => 0, 'active' => 0, 'draft' => 0, 'total_attempts' => 0, 'avg_pass_rate' => 0];
$rs = safePreparedQuery($conn,
    "SELECT
        COUNT(*)                                                      AS total_assessments,
        SUM(CASE WHEN a.status='active'  THEN 1 ELSE 0 END)          AS active,
        SUM(CASE WHEN a.status='draft'   THEN 1 ELSE 0 END)          AS draft,
        (SELECT COUNT(*) FROM assessment_attempts)                    AS total_attempts,
        ROUND(AVG(sub.pass_rate),1)                                   AS avg_pass_rate
     FROM assessments a
     LEFT JOIN (
        SELECT aa.assessment_id,
               ROUND(SUM(CASE WHEN aa.status='completed' AND aa.percentage >= (
                   SELECT (a2.passing_marks/a2.total_marks)*100
                   FROM assessments a2 WHERE a2.assessment_id = aa.assessment_id
               ) THEN 1 ELSE 0 END) / NULLIF(SUM(CASE WHEN aa.status='completed' THEN 1 ELSE 0 END),0)*100,1) AS pass_rate
        FROM assessment_attempts aa
        GROUP BY aa.assessment_id
     ) sub ON sub.assessment_id = a.assessment_id",
    '', []
);
if ($rs['success'] && $rs['result']) {
    $row   = $rs['result']->fetch_assoc();
    $stats = [
        'total_assessments' => (int)($row['total_assessments'] ?? 0),
        'active'            => (int)($row['active']            ?? 0),
        'draft'             => (int)($row['draft']             ?? 0),
        'total_attempts'    => (int)($row['total_attempts']    ?? 0),
        'avg_pass_rate'     => (float)($row['avg_pass_rate']   ?? 0),
    ];
    $rs['result']->free();
}

// ── Total count for current filter ──
$total = 0;
$rtc = safePreparedQuery($conn,
    "SELECT COUNT(*) AS cnt FROM assessments a LEFT JOIN users u ON u.user_id = a.created_by $where",
    $types, $params
);
if ($rtc['success'] && $rtc['result']) {
    $row   = $rtc['result']->fetch_assoc();
    $total = (int)($row['cnt'] ?? 0);
    $rtc['result']->free();
}

// ── Fetch page ──
$listTypes  = $types . 'ii';
$listParams = array_merge($params, [$limit, $offset]);

$r = safePreparedQuery($conn,
    "SELECT
        a.assessment_id, a.title, a.category, a.difficulty, a.status,
        a.duration_minutes, a.total_marks, a.passing_marks,
        a.max_attempts, a.is_public,
        a.available_from, a.available_until,
        a.created_at, a.updated_at,
        u.full_name AS creator_name,
        u.user_id   AS creator_id,
        COUNT(DISTINCT q.question_id)                                        AS question_count,
        COUNT(DISTINCT aa.attempt_id)                                        AS attempt_count,
        COUNT(DISTINCT aa.user_id)                                           AS student_count,
        ROUND(AVG(CASE WHEN aa.status='completed' THEN aa.percentage END),1) AS avg_score,
        ROUND(SUM(CASE WHEN aa.status='completed' AND aa.percentage >= (a.passing_marks/a.total_marks)*100 THEN 1 ELSE 0 END)
              / NULLIF(SUM(CASE WHEN aa.status='completed' THEN 1 ELSE 0 END),0)*100, 1) AS pass_rate
     FROM assessments a
     LEFT JOIN users u      ON u.user_id   = a.created_by
     LEFT JOIN questions q  ON q.assessment_id = a.assessment_id
     LEFT JOIN assessment_attempts aa ON aa.assessment_id = a.assessment_id
     $where
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
            'category'         => $row['category'] ?? '',
            'difficulty'       => $row['difficulty'],
            'status'           => $row['status'],
            'duration_minutes' => (int)$row['duration_minutes'],
            'total_marks'      => (int)$row['total_marks'],
            'passing_marks'    => (int)$row['passing_marks'],
            'max_attempts'     => (int)$row['max_attempts'],
            'is_public'        => (bool)$row['is_public'],
            'available_from'   => $row['available_from'],
            'available_until'  => $row['available_until'],
            'created_at'       => $row['created_at'],
            'updated_at'       => $row['updated_at'],
            'creator_name'     => $row['creator_name'] ?? 'Unknown',
            'creator_id'       => (int)($row['creator_id'] ?? 0),
            'question_count'   => (int)$row['question_count'],
            'attempt_count'    => (int)$row['attempt_count'],
            'student_count'    => (int)$row['student_count'],
            'avg_score'        => (float)($row['avg_score']  ?? 0),
            'pass_rate'        => $row['pass_rate'] !== null ? (float)$row['pass_rate'] : null,
        ];
    }
    $r['result']->free();
}

echo json_encode([
    'success'     => true,
    'assessments' => $assessments,
    'total'       => $total,
    'page'        => $page,
    'pages'       => max(1, (int)ceil($total / $limit)),
    'counts'      => $counts,
    'stats'       => $stats,
]);

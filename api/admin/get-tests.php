<?php
// ============================================================
// api/admin/get-tests.php
//
// Admin: paginated assessment list with stats.
// Status values: draft, published, archived  (no 'active','scheduled')
// attempts status: submitted (not 'completed')
// is_public removed; use visibility enum('public','group','private')
// available_from/until removed; use start_time/end_time
//
// GET ?status=all|published|draft|archived
//     ?search=keyword
//     ?category=...
//     ?page=1&limit=20
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

// Valid statuses in new schema
$allowedStatuses = ['all', 'published', 'draft', 'archived'];
if (!in_array($status, $allowedStatuses, true)) $status = 'all';

// WHERE builder
$conditions = []; $params = []; $types = '';
if ($status !== 'all') { $conditions[] = 'a.status = ?'; $params[] = $status; $types .= 's'; }
if ($search !== '') {
    $like = '%'.$search.'%';
    $conditions[] = '(a.title LIKE ? OR a.category LIKE ? OR u.full_name LIKE ?)';
    $params[] = $like; $params[] = $like; $params[] = $like; $types .= 'sss';
}
if ($category !== '') { $conditions[] = 'a.category = ?'; $params[] = $category; $types .= 's'; }
$where = $conditions ? 'WHERE '.implode(' AND ',$conditions) : '';

// Status counts (search/category only, not status filter)
$counts = ['all'=>0,'published'=>0,'draft'=>0,'archived'=>0];
$cCond = []; $cP = []; $cT = '';
if ($search !== '') {
    $like = '%'.$search.'%';
    $cCond[] = '(a.title LIKE ? OR a.category LIKE ? OR u.full_name LIKE ?)';
    $cP[] = $like; $cP[] = $like; $cP[] = $like; $cT .= 'sss';
}
if ($category !== '') { $cCond[] = 'a.category = ?'; $cP[] = $category; $cT .= 's'; }
$cWhere = $cCond ? 'WHERE '.implode(' AND ',$cCond) : '';

$rc = safePreparedQuery($conn,
    "SELECT a.status, COUNT(*) AS cnt FROM assessments a LEFT JOIN users u ON u.user_id=a.created_by $cWhere GROUP BY a.status",
    $cT, $cP);
if ($rc['success'] && $rc['result']) {
    while ($row = $rc['result']->fetch_assoc()) {
        $s = $row['status'];
        if (isset($counts[$s])) $counts[$s] = (int)$row['cnt'];
        $counts['all'] += (int)$row['cnt'];
    }
    $rc['result']->free();
}

// Top-level stats — submitted replaces completed; no 'active' status
$stats = ['total_assessments'=>0,'published'=>0,'draft'=>0,'total_attempts'=>0,'avg_pass_rate'=>0];
$rs = safePreparedQuery($conn,
    "SELECT COUNT(*) AS total_assessments,
            SUM(CASE WHEN a.status='published' THEN 1 ELSE 0 END) AS published,
            SUM(CASE WHEN a.status='draft'     THEN 1 ELSE 0 END) AS draft,
            (SELECT COUNT(*) FROM assessment_attempts)             AS total_attempts,
            ROUND(AVG(sub.pass_rate),1)                            AS avg_pass_rate
     FROM assessments a
     LEFT JOIN (
        SELECT aa.assessment_id,
               ROUND(SUM(CASE WHEN aa.status='submitted' AND aa.percentage>=(
                   SELECT a2.passing_marks/a2.total_marks*100 FROM assessments a2 WHERE a2.assessment_id=aa.assessment_id
               ) THEN 1 ELSE 0 END) / NULLIF(SUM(CASE WHEN aa.status='submitted' THEN 1 ELSE 0 END),0)*100,1) AS pass_rate
        FROM assessment_attempts aa GROUP BY aa.assessment_id
     ) sub ON sub.assessment_id=a.assessment_id",
    '', []);
if ($rs['success'] && $rs['result']) {
    $row = $rs['result']->fetch_assoc();
    $stats = ['total_assessments'=>(int)($row['total_assessments']??0),'published'=>(int)($row['published']??0),
              'draft'=>(int)($row['draft']??0),'total_attempts'=>(int)($row['total_attempts']??0),'avg_pass_rate'=>(float)($row['avg_pass_rate']??0)];
    $rs['result']->free();
}

// Total count
$total = 0;
$rtc = safePreparedQuery($conn, "SELECT COUNT(*) AS cnt FROM assessments a LEFT JOIN users u ON u.user_id=a.created_by $where", $types, $params);
if ($rtc['success'] && $rtc['result']) { $total=(int)($rtc['result']->fetch_assoc()['cnt']??0); $rtc['result']->free(); }

// Fetch page
// is_public removed → use visibility; available_from/until → start_time/end_time; submitted replaces completed
$r = safePreparedQuery($conn,
    "SELECT a.assessment_id, a.title, a.category, a.difficulty, a.status, a.visibility,
            a.duration_minutes, a.total_marks, a.passing_marks, a.max_attempts,
            a.start_time, a.end_time, a.created_at, a.updated_at,
            u.full_name AS creator_name, u.user_id AS creator_id,
            COUNT(DISTINCT q.question_id) AS question_count,
            COUNT(DISTINCT aa.attempt_id) AS attempt_count,
            COUNT(DISTINCT aa.user_id)    AS student_count,
            ROUND(AVG(CASE WHEN aa.status='submitted' THEN aa.percentage END),1) AS avg_score,
            ROUND(SUM(CASE WHEN aa.status='submitted' AND aa.percentage>=(a.passing_marks/a.total_marks)*100 THEN 1 ELSE 0 END)
                  / NULLIF(SUM(CASE WHEN aa.status='submitted' THEN 1 ELSE 0 END),0)*100, 1) AS pass_rate
     FROM assessments a
     LEFT JOIN users u ON u.user_id=a.created_by
     LEFT JOIN questions q ON q.assessment_id=a.assessment_id
     LEFT JOIN assessment_attempts aa ON aa.assessment_id=a.assessment_id
     $where
     GROUP BY a.assessment_id
     ORDER BY FIELD(a.status,'published','draft','archived'), a.updated_at DESC
     LIMIT ? OFFSET ?",
    $types.'ii', array_merge($params, [$limit, $offset]));

$assessments = [];
if ($r['success'] && $r['result']) {
    while ($row = $r['result']->fetch_assoc()) {
        $assessments[] = [
            'assessment_id'  => (int)$row['assessment_id'],
            'title'          => $row['title'],
            'category'       => $row['category'] ?? '',
            'difficulty'     => $row['difficulty'],
            'status'         => $row['status'],
            'visibility'     => $row['visibility'],
            'duration_minutes'=> (int)$row['duration_minutes'],
            'total_marks'    => (int)$row['total_marks'],
            'passing_marks'  => (int)$row['passing_marks'],
            'max_attempts'   => (int)$row['max_attempts'],
            'start_time'     => $row['start_time'],
            'end_time'       => $row['end_time'],
            'created_at'     => $row['created_at'],
            'updated_at'     => $row['updated_at'],
            'creator_name'   => $row['creator_name'] ?? 'Unknown',
            'creator_id'     => (int)($row['creator_id'] ?? 0),
            'question_count' => (int)$row['question_count'],
            'attempt_count'  => (int)$row['attempt_count'],
            'student_count'  => (int)$row['student_count'],
            'avg_score'      => (float)($row['avg_score']  ?? 0),
            'pass_rate'      => $row['pass_rate'] !== null ? (float)$row['pass_rate'] : null,
        ];
    }
    $r['result']->free();
}

echo json_encode(['success'=>true,'assessments'=>$assessments,'total'=>$total,'page'=>$page,
                  'pages'=>max(1,(int)ceil($total/$limit)),'counts'=>$counts,'stats'=>$stats]);
<?php
/* ========================================
 * ASSESSMENT RESULTS
 * File: assessment-results.php
 *
 * Requires: ?id=<assessment_id>
 * Access:   Teachers only — ownership verified
 *
 * Shows:
 *   - Assessment summary + aggregate stats
 *   - Per-student attempt list (paginated, searchable)
 *   - Score distribution breakdown
 * ======================================== */

require 'config.php';
require_once 'db-guard.php';

$currentUser  = validateSession($conn, 'teacher');
$teacherId    = (int) $currentUser['user_id'];
$userName     = htmlspecialchars($currentUser['full_name'] ?? 'Teacher');
$userInitials = strtoupper(substr($currentUser['full_name'] ?? 'T', 0, 2));

// ── Validate assessment ID ──
$assessmentId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($assessmentId <= 0) {
    header('Location: teacher-dashboard.php?error=invalid_id');
    exit;
}

// ── Load assessment — verify ownership ──
$assessment = null;
$r = safePreparedQuery($conn,
    "SELECT assessment_id, title, category, difficulty, status,
            duration_minutes, total_marks, passing_marks,
            available_from, available_until, created_at, updated_at
     FROM assessments
     WHERE assessment_id = ? AND created_by = ?",
    "ii", [$assessmentId, $teacherId]
);
if ($r['success'] && $r['result']) {
    $assessment = $r['result']->fetch_assoc();
    $r['result']->free();
}
if (!$assessment) {
    header('Location: teacher-dashboard.php?error=not_found');
    exit;
}

// ── Aggregate stats ──
$stats = [
    'total_attempts'   => 0,
    'completed'        => 0,
    'avg_score'        => 0,
    'highest_score'    => 0,
    'lowest_score'     => 0,
    'pass_count'       => 0,
    'avg_time_minutes' => 0,
    'unique_students'  => 0,
];

$rs = safePreparedQuery($conn,
    "SELECT
        COUNT(*)                                                        AS total_attempts,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END)          AS completed,
        ROUND(AVG(CASE WHEN status='completed' THEN percentage END), 1) AS avg_score,
        ROUND(MAX(percentage), 1)                                       AS highest_score,
        ROUND(MIN(CASE WHEN status='completed' THEN percentage END), 1) AS lowest_score,
        SUM(CASE WHEN status='completed'
                  AND percentage >= ? THEN 1 ELSE 0 END)               AS pass_count,
        ROUND(AVG(CASE WHEN status='completed'
                  THEN TIMESTAMPDIFF(MINUTE, start_time, submitted_at)
                  END), 0)                                             AS avg_time,
        COUNT(DISTINCT user_id)                                         AS unique_students
     FROM assessment_attempts
     WHERE assessment_id = ?",
    "di", [
        ($assessment['total_marks'] > 0
            ? ($assessment['passing_marks'] / $assessment['total_marks']) * 100
            : 0),
        $assessmentId
    ]
);
if ($rs['success'] && $rs['result']) {
    $row = $rs['result']->fetch_assoc();
    if ($row) {
        $stats['total_attempts']   = (int)($row['total_attempts'] ?? 0);
        $stats['completed']        = (int)($row['completed']       ?? 0);
        $stats['avg_score']        = (float)($row['avg_score']      ?? 0);
        $stats['highest_score']    = (float)($row['highest_score']  ?? 0);
        $stats['lowest_score']     = (float)($row['lowest_score']   ?? 0);
        $stats['pass_count']       = (int)($row['pass_count']       ?? 0);
        $stats['avg_time_minutes'] = (int)($row['avg_time']         ?? 0);
        $stats['unique_students']  = (int)($row['unique_students']  ?? 0);
    }
    $rs['result']->free();
}

$passRate = $stats['completed'] > 0
    ? round(($stats['pass_count'] / $stats['completed']) * 100, 1)
    : 0;

// ── Score distribution (buckets: 0-20, 20-40, 40-60, 60-80, 80-100) ──
$distribution = [0, 0, 0, 0, 0];
$rd = safePreparedQuery($conn,
    "SELECT percentage FROM assessment_attempts
     WHERE assessment_id = ? AND status = 'completed' AND percentage IS NOT NULL",
    "i", [$assessmentId]
);
if ($rd['success'] && $rd['result']) {
    while ($row = $rd['result']->fetch_assoc()) {
        $p = (float)$row['percentage'];
        if ($p <= 20)      $distribution[0]++;
        elseif ($p <= 40)  $distribution[1]++;
        elseif ($p <= 60)  $distribution[2]++;
        elseif ($p <= 80)  $distribution[3]++;
        else               $distribution[4]++;
    }
    $rd['result']->free();
}
$maxBucket = max(1, max($distribution)); // avoid division by zero

// ── All attempts (for the table) ──
$attempts = [];
$ra = safePreparedQuery($conn,
    "SELECT
        aa.attempt_id,
        aa.user_id,
        COALESCE(u.full_name,  aa.guest_name,  'Guest')    AS student_name,
        COALESCE(u.email,      aa.guest_email, '')          AS student_email,
        COALESCE(u.registration_number, '—')                AS reg_number,
        aa.attempt_number,
        aa.status,
        aa.score,
        aa.percentage,
        aa.correct_answers,
        aa.wrong_answers,
        aa.unanswered,
        aa.total_questions,
        aa.start_time,
        aa.submitted_at,
        TIMESTAMPDIFF(MINUTE, aa.start_time, aa.submitted_at) AS time_taken
     FROM assessment_attempts aa
     LEFT JOIN users u ON u.user_id = aa.user_id
     WHERE aa.assessment_id = ?
     ORDER BY aa.submitted_at DESC, aa.created_at DESC",
    "i", [$assessmentId]
);
if ($ra['success'] && $ra['result']) {
    while ($row = $ra['result']->fetch_assoc()) {
        $attempts[] = $row;
    }
    $ra['result']->free();
}

// ── Question count ──
$questionCount = 0;
$rqc = safePreparedQuery($conn,
    "SELECT COUNT(*) AS cnt FROM questions WHERE assessment_id = ?",
    "i", [$assessmentId]
);
if ($rqc['success'] && $rqc['result']) {
    $row = $rqc['result']->fetch_assoc();
    $questionCount = (int)($row['cnt'] ?? 0);
    $rqc['result']->free();
}

// ── Helpers ──
function fmtDate(?string $dt): string {
    if (!$dt) return '—';
    return date('M j, Y', strtotime($dt));
}
function fmtDateTime(?string $dt): string {
    if (!$dt) return '—';
    return date('M j, Y g:i A', strtotime($dt));
}
function passBadge(float $pct, float $passingPct): string {
    if ($pct >= $passingPct) {
        return '<span class="badge badge-pass">Pass</span>';
    }
    return '<span class="badge badge-fail">Fail</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Results: <?= htmlspecialchars($assessment['title']) ?> - Placement Portal</title>
    <style>
        :root {
            --font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            --color-teacher-primary: #2E073F;
            --color-teacher-secondary: #AD49E1;
            --color-text: #2d3748;
            --color-text-light: #718096;
            --color-bg: #D3DAD9;
            --color-bg-light: #f5f7fa;
            --color-white: #ffffff;
            --color-border: #e2e8f0;
            --color-success: #48bb78;
            --color-error: #f56565;
            --shadow-sm: 0 2px 10px rgba(0,0,0,0.08);
            --shadow-md: 0 4px 20px rgba(0,0,0,0.08);
            --shadow-lg: 0 8px 30px rgba(0,0,0,0.15);
            --radius: 10px;
            --radius-lg: 20px;
            --transition: all 0.3s ease;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: var(--font-family);
            background: var(--color-bg);
            min-height: 100vh;
            color: var(--color-text);
            padding-top: 71px;
        }

        /* ── NAVBAR ── */
        .navbar {
            background: var(--color-teacher-primary);
            padding: 12px 28px;
            display: flex; align-items: center; justify-content: space-between;
            position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.15);
        }
        .navbar-brand {
            display: flex; align-items: center; gap: 12px;
            font-size: 20px; font-weight: 700; color: white; text-decoration: none;
        }
        .brand-logo {
            width: 44px; height: 44px;
            background: linear-gradient(135deg, var(--color-teacher-primary), var(--color-teacher-secondary));
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 16px;
        }
        .nav-actions { display: flex; gap: 12px; align-items: center; }
        .btn-nav {
            padding: 9px 18px;
            background: rgba(255,255,255,0.15);
            color: white;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: var(--radius);
            font-weight: 600; font-size: 13px;
            cursor: pointer; text-decoration: none;
            display: inline-flex; align-items: center; gap: 6px;
            transition: var(--transition);
        }
        .btn-nav:hover { background: rgba(255,255,255,0.25); }

        /* ── CONTAINER ── */
        .container { max-width: 1200px; margin: 0 auto; padding: 30px 20px; }

        /* ── PAGE HEADER ── */
        .page-header {
            background: white; border-radius: var(--radius-lg); padding: 28px 30px;
            margin-bottom: 24px; box-shadow: var(--shadow-sm);
        }
        .header-top {
            display: flex; justify-content: space-between; align-items: flex-start;
            gap: 20px; flex-wrap: wrap;
        }
        .page-title { font-size: 26px; font-weight: 700; color: var(--color-text); margin-bottom: 6px; }
        .page-meta  { font-size: 14px; color: var(--color-text-light); margin-bottom: 14px; }
        .badge-row  { display: flex; gap: 8px; flex-wrap: wrap; }
        .meta-badge {
            padding: 5px 12px; border-radius: 7px;
            font-size: 12px; font-weight: 600;
            display: inline-flex; align-items: center; gap: 5px;
        }
        .meta-badge.active    { background: #d1fae5; color: #065f46; }
        .meta-badge.draft     { background: #fef3c7; color: #92400e; }
        .meta-badge.archived  { background: #dbeafe; color: #1e40af; }
        .meta-badge.scheduled { background: #e0e7ff; color: #3730a3; }
        .meta-badge.info      { background: #e6f7ff; color: #0c5460; }

        .btn-export {
            padding: 10px 22px;
            background: linear-gradient(135deg, var(--color-teacher-primary), var(--color-teacher-secondary));
            color: white; border: none; border-radius: var(--radius);
            font-weight: 700; font-size: 13px; cursor: pointer;
            transition: var(--transition); white-space: nowrap;
            display: inline-flex; align-items: center; gap: 7px;
        }
        .btn-export:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(46,7,63,0.3); }

        /* ── STATS GRID ── */
        .stats-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 16px; margin-bottom: 24px;
        }
        .stat-card {
            background: white; border-radius: var(--radius); padding: 22px 18px;
            box-shadow: var(--shadow-sm); text-align: center;
            border-top: 4px solid var(--color-teacher-secondary);
            transition: var(--transition);
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
        .stat-icon  { font-size: 28px; margin-bottom: 8px; }
        .stat-value { font-size: 30px; font-weight: 700; color: var(--color-text); margin-bottom: 4px; }
        .stat-label { font-size: 12px; color: var(--color-text-light); font-weight: 500; }

        /* ── TWO-COL LAYOUT ── */
        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }

        /* ── CARD ── */
        .card {
            background: white; border-radius: var(--radius-lg); padding: 26px 28px;
            box-shadow: var(--shadow-sm);
        }
        .card-title {
            font-size: 17px; font-weight: 700; color: var(--color-text);
            margin-bottom: 20px; display: flex; align-items: center; gap: 8px;
        }
        .card-icon {
            width: 34px; height: 34px; border-radius: 9px;
            background: linear-gradient(135deg, var(--color-teacher-primary), var(--color-teacher-secondary));
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 16px; flex-shrink: 0;
        }

        /* ── DISTRIBUTION CHART ── */
        .dist-bars { display: flex; flex-direction: column; gap: 12px; }
        .dist-row  { display: flex; align-items: center; gap: 12px; }
        .dist-label { font-size: 12px; color: var(--color-text-light); min-width: 60px; text-align: right; }
        .dist-bar-wrap { flex: 1; background: var(--color-bg-light); border-radius: 6px; overflow: hidden; height: 22px; }
        .dist-bar {
            height: 100%; border-radius: 6px;
            background: linear-gradient(90deg, var(--color-teacher-primary), var(--color-teacher-secondary));
            display: flex; align-items: center; justify-content: flex-end;
            padding-right: 8px; min-width: 28px;
            transition: width 0.6s ease;
        }
        .dist-bar-num { font-size: 11px; font-weight: 700; color: white; }
        .dist-bar.zero { background: var(--color-border); }
        .dist-bar.zero .dist-bar-num { color: var(--color-text-light); }

        /* ── ASSESSMENT INFO ── */
        .info-list { display: flex; flex-direction: column; gap: 12px; }
        .info-row  { display: flex; justify-content: space-between; align-items: center; padding-bottom: 12px; border-bottom: 1px solid var(--color-border); }
        .info-row:last-child { border-bottom: none; padding-bottom: 0; }
        .info-key   { font-size: 13px; color: var(--color-text-light); }
        .info-value { font-size: 13px; font-weight: 600; color: var(--color-text); text-align: right; }

        /* ── ATTEMPTS TABLE SECTION ── */
        .table-section {
            background: white; border-radius: var(--radius-lg); padding: 26px 28px;
            box-shadow: var(--shadow-sm); margin-bottom: 30px;
        }
        .table-controls {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 18px; gap: 14px; flex-wrap: wrap;
        }
        .search-wrap { position: relative; flex: 1; min-width: 200px; max-width: 340px; }
        .search-input {
            width: 100%; padding: 10px 38px 10px 14px;
            border: 2px solid var(--color-border); border-radius: var(--radius);
            font-size: 14px; font-family: var(--font-family);
            transition: var(--transition);
        }
        .search-input:focus { outline: none; border-color: var(--color-teacher-secondary); }
        .search-icon { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #a0aec0; }

        .filter-select {
            padding: 10px 14px; border: 2px solid var(--color-border); border-radius: var(--radius);
            font-size: 13px; background: white; cursor: pointer; font-family: var(--font-family);
        }
        .filter-select:focus { outline: none; border-color: var(--color-teacher-secondary); }

        .results-count { font-size: 13px; color: var(--color-text-light); white-space: nowrap; }

        /* Table */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        thead th {
            padding: 12px 14px; text-align: left;
            font-size: 11px; font-weight: 700; color: var(--color-text-light);
            text-transform: uppercase; letter-spacing: 0.5px;
            border-bottom: 2px solid var(--color-border);
            white-space: nowrap; cursor: pointer; user-select: none;
        }
        thead th:hover { color: var(--color-teacher-secondary); }
        thead th .sort-icon { margin-left: 4px; opacity: 0.4; }
        thead th.sorted .sort-icon { opacity: 1; color: var(--color-teacher-secondary); }
        tbody tr { transition: var(--transition); }
        tbody tr:hover { background: var(--color-bg-light); }
        tbody td { padding: 13px 14px; border-bottom: 1px solid var(--color-border); vertical-align: middle; }
        tbody tr:last-child td { border-bottom: none; }

        .student-info .name  { font-weight: 600; color: var(--color-text); margin-bottom: 2px; }
        .student-info .email { font-size: 12px; color: var(--color-text-light); }
        .student-info .reg   { font-size: 11px; color: #a0aec0; }

        .score-cell .score { font-size: 16px; font-weight: 700; color: var(--color-text); }
        .score-cell .pct   { font-size: 12px; color: var(--color-text-light); margin-top: 2px; }

        .badge {
            display: inline-block; padding: 3px 10px; border-radius: 5px;
            font-size: 11px; font-weight: 700;
        }
        .badge-pass      { background: #c6f6d5; color: #22543d; }
        .badge-fail      { background: #fed7d7; color: #742a2a; }
        .badge-completed { background: #d1fae5; color: #065f46; }
        .badge-progress  { background: #fef3c7; color: #92400e; }
        .badge-abandoned { background: #e2e8f0; color: #4a5568; }
        .badge-timeout   { background: #fee2e2; color: #c53030; }

        .answers-split { display: flex; gap: 8px; font-size: 12px; }
        .ans-correct { color: #22543d; font-weight: 600; }
        .ans-wrong   { color: #c53030; font-weight: 600; }
        .ans-skip    { color: #a0aec0; }

        .btn-detail {
            padding: 6px 14px; background: var(--color-bg-light);
            border: 1px solid var(--color-border); border-radius: 7px;
            font-size: 12px; font-weight: 600; cursor: pointer;
            transition: var(--transition); white-space: nowrap;
        }
        .btn-detail:hover { background: var(--color-border); }

        /* Empty / no attempts */
        .empty-state {
            text-align: center; padding: 60px 20px; color: var(--color-text-light);
        }
        .empty-icon { font-size: 52px; opacity: 0.3; margin-bottom: 14px; }
        .empty-text { font-size: 16px; }

        /* Pagination */
        .pagination {
            display: flex; justify-content: center; align-items: center;
            gap: 8px; margin-top: 20px; flex-wrap: wrap;
        }
        .page-btn {
            padding: 7px 14px; border: 2px solid var(--color-border);
            border-radius: 8px; background: white; cursor: pointer;
            font-size: 13px; font-weight: 600; transition: var(--transition);
        }
        .page-btn:hover { border-color: var(--color-teacher-secondary); color: var(--color-teacher-secondary); }
        .page-btn.active {
            background: var(--color-teacher-secondary); border-color: var(--color-teacher-secondary);
            color: white;
        }
        .page-btn:disabled { opacity: 0.4; cursor: not-allowed; }

        /* ── TOAST ── */
        .toast {
            position: fixed; bottom: 28px; left: 50%; transform: translateX(-50%) translateY(80px);
            background: #1a202c; color: white; padding: 13px 26px;
            border-radius: var(--radius); font-size: 14px; font-weight: 600;
            box-shadow: var(--shadow-lg); z-index: 9999;
            transition: transform 0.3s ease, opacity 0.3s ease;
            opacity: 0; pointer-events: none;
        }
        .toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
        .toast.success { background: #276749; }
        .toast.error   { background: #c53030; }

        /* ── RESPONSIVE ── */
        @media (max-width: 900px) {
            .two-col { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .container { padding: 16px; }
            .navbar { padding: 10px 14px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .table-controls { flex-direction: column; align-items: flex-start; }
            .search-wrap { max-width: 100%; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .page-title { font-size: 20px; }
        }
    </style>
</head>
<body>

<!-- ── NAVBAR ── -->
<nav class="navbar">
    <a href="teacher-dashboard.php" class="navbar-brand">
        <div class="brand-logo">PT</div>
        <span>Placement Portal</span>
    </a>
    <div class="nav-actions">
        <a href="edit-assessment.php?id=<?= $assessmentId ?>" class="btn-nav">✏️ Edit Assessment</a>
        <a href="teacher-dashboard.php" class="btn-nav">← Dashboard</a>
    </div>
</nav>

<!-- ── MAIN ── -->
<div class="container">

    <!-- Page Header -->
    <div class="page-header">
        <div class="header-top">
            <div>
                <h1 class="page-title"><?= htmlspecialchars($assessment['title']) ?></h1>
                <p class="page-meta">
                    Assessment #<?= $assessmentId ?> &nbsp;·&nbsp;
                    <?= $questionCount ?> question<?= $questionCount !== 1 ? 's' : '' ?> &nbsp;·&nbsp;
                    <?= (int)$assessment['duration_minutes'] ?> min &nbsp;·&nbsp;
                    <?= (int)$assessment['total_marks'] ?> marks &nbsp;·&nbsp;
                    Pass: <?= (int)$assessment['passing_marks'] ?> marks
                </p>
                <div class="badge-row">
                    <span class="meta-badge <?= htmlspecialchars($assessment['status']) ?>">
                        ● <?= ucfirst($assessment['status']) ?>
                    </span>
                    <span class="meta-badge info">📚 <?= ucfirst($assessment['category'] ?? 'General') ?></span>
                    <span class="meta-badge info">🎯 <?= ucfirst($assessment['difficulty'] ?? 'Medium') ?></span>
                    <?php if ($assessment['available_from']): ?>
                        <span class="meta-badge info">📅 From: <?= fmtDate($assessment['available_from']) ?></span>
                    <?php endif; ?>
                    <?php if ($assessment['available_until']): ?>
                        <span class="meta-badge info">📅 Until: <?= fmtDate($assessment['available_until']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <button class="btn-export" onclick="exportCSV()">⬇️ Export CSV</button>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">👥</div>
            <div class="stat-value"><?= $stats['unique_students'] ?></div>
            <div class="stat-label">Unique Students</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">📋</div>
            <div class="stat-value"><?= $stats['total_attempts'] ?></div>
            <div class="stat-label">Total Attempts</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">✅</div>
            <div class="stat-value"><?= $stats['completed'] ?></div>
            <div class="stat-label">Completed</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">📈</div>
            <div class="stat-value"><?= $stats['avg_score'] ?>%</div>
            <div class="stat-label">Average Score</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🏆</div>
            <div class="stat-value"><?= $stats['highest_score'] ?>%</div>
            <div class="stat-label">Highest Score</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🎯</div>
            <div class="stat-value"><?= $passRate ?>%</div>
            <div class="stat-label">Pass Rate</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">⏱️</div>
            <div class="stat-value"><?= $stats['avg_time_minutes'] ?>m</div>
            <div class="stat-label">Avg. Time Taken</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">📉</div>
            <div class="stat-value"><?= $stats['lowest_score'] ?>%</div>
            <div class="stat-label">Lowest Score</div>
        </div>
    </div>

    <!-- Distribution + Info -->
    <div class="two-col">

        <!-- Score Distribution -->
        <div class="card">
            <div class="card-title">
                <div class="card-icon">📊</div>
                Score Distribution
            </div>
            <div class="dist-bars">
                <?php
                $buckets = [
                    '0 – 20%'   => $distribution[0],
                    '21 – 40%'  => $distribution[1],
                    '41 – 60%'  => $distribution[2],
                    '61 – 80%'  => $distribution[3],
                    '81 – 100%' => $distribution[4],
                ];
                foreach ($buckets as $label => $count):
                    $width = $maxBucket > 0 ? round(($count / $maxBucket) * 100) : 0;
                ?>
                <div class="dist-row">
                    <div class="dist-label"><?= $label ?></div>
                    <div class="dist-bar-wrap">
                        <div class="dist-bar <?= $count === 0 ? 'zero' : '' ?>"
                             style="width: <?= max(28, $width) ?>%">
                            <span class="dist-bar-num"><?= $count ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Assessment Info -->
        <div class="card">
            <div class="card-title">
                <div class="card-icon">📝</div>
                Assessment Info
            </div>
            <div class="info-list">
                <div class="info-row">
                    <span class="info-key">Category</span>
                    <span class="info-value"><?= ucfirst($assessment['category'] ?? '—') ?></span>
                </div>
                <div class="info-row">
                    <span class="info-key">Difficulty</span>
                    <span class="info-value"><?= ucfirst($assessment['difficulty'] ?? '—') ?></span>
                </div>
                <div class="info-row">
                    <span class="info-key">Duration</span>
                    <span class="info-value"><?= (int)$assessment['duration_minutes'] ?> minutes</span>
                </div>
                <div class="info-row">
                    <span class="info-key">Total Marks</span>
                    <span class="info-value"><?= (int)$assessment['total_marks'] ?></span>
                </div>
                <div class="info-row">
                    <span class="info-key">Passing Marks</span>
                    <span class="info-value"><?= (int)$assessment['passing_marks'] ?></span>
                </div>
                <div class="info-row">
                    <span class="info-key">Questions</span>
                    <span class="info-value"><?= $questionCount ?></span>
                </div>
                <div class="info-row">
                    <span class="info-key">Created</span>
                    <span class="info-value"><?= fmtDate($assessment['created_at']) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-key">Last Updated</span>
                    <span class="info-value"><?= fmtDate($assessment['updated_at']) ?></span>
                </div>
            </div>
        </div>

    </div>

    <!-- Attempts Table -->
    <div class="table-section">
        <div class="table-controls">
            <div class="card-title" style="margin-bottom:0;">
                <div class="card-icon">📋</div>
                Student Attempts
            </div>
            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <div class="search-wrap">
                    <input type="text" class="search-input" id="searchInput"
                           placeholder="Search by name, email or reg…" oninput="filterTable()">
                    <span class="search-icon">🔍</span>
                </div>
                <select class="filter-select" id="statusFilter" onchange="filterTable()">
                    <option value="">All statuses</option>
                    <option value="completed">Completed</option>
                    <option value="in_progress">In Progress</option>
                    <option value="abandoned">Abandoned</option>
                    <option value="timeout">Timeout</option>
                </select>
                <select class="filter-select" id="resultFilter" onchange="filterTable()">
                    <option value="">Pass &amp; Fail</option>
                    <option value="pass">Pass only</option>
                    <option value="fail">Fail only</option>
                </select>
                <span class="results-count" id="resultsCount">
                    <?= count($attempts) ?> attempt<?= count($attempts) !== 1 ? 's' : '' ?>
                </span>
            </div>
        </div>

        <?php if (empty($attempts)): ?>
            <div class="empty-state">
                <div class="empty-icon">📭</div>
                <div class="empty-text">No attempts yet for this assessment.</div>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table id="attemptsTable">
                    <thead>
                        <tr>
                            <th onclick="sortTable(0)">Student <span class="sort-icon">↕</span></th>
                            <th onclick="sortTable(1)">Reg. No. <span class="sort-icon">↕</span></th>
                            <th onclick="sortTable(2)"># <span class="sort-icon">↕</span></th>
                            <th onclick="sortTable(3)">Score <span class="sort-icon">↕</span></th>
                            <th onclick="sortTable(4)">Result <span class="sort-icon">↕</span></th>
                            <th onclick="sortTable(5)">Answers <span class="sort-icon">↕</span></th>
                            <th onclick="sortTable(6)">Time <span class="sort-icon">↕</span></th>
                            <th onclick="sortTable(7)">Status <span class="sort-icon">↕</span></th>
                            <th onclick="sortTable(8)">Submitted <span class="sort-icon">↕</span></th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <?php
                        $passingPct = $assessment['total_marks'] > 0
                            ? ($assessment['passing_marks'] / $assessment['total_marks']) * 100
                            : 0;

                        foreach ($attempts as $a):
                            $pct      = $a['percentage'] !== null ? (float)$a['percentage'] : null;
                            $isPassed = $pct !== null && $pct >= $passingPct;
                            $statusCls = match($a['status']) {
                                'completed'   => 'badge-completed',
                                'in_progress' => 'badge-progress',
                                'abandoned'   => 'badge-abandoned',
                                'timeout'     => 'badge-timeout',
                                default       => 'badge-abandoned',
                            };
                        ?>
                        <tr data-status="<?= htmlspecialchars($a['status']) ?>"
                            data-result="<?= ($a['status'] === 'completed' ? ($isPassed ? 'pass' : 'fail') : '') ?>"
                            data-search="<?= strtolower(htmlspecialchars($a['student_name'] . ' ' . $a['student_email'] . ' ' . $a['reg_number'])) ?>">
                            <td>
                                <div class="student-info">
                                    <div class="name"><?= htmlspecialchars($a['student_name']) ?></div>
                                    <div class="email"><?= htmlspecialchars($a['student_email']) ?></div>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($a['reg_number']) ?></td>
                            <td><?= (int)$a['attempt_number'] ?></td>
                            <td>
                                <div class="score-cell">
                                    <div class="score"><?= $a['score'] !== null ? number_format((float)$a['score'], 1) : '—' ?></div>
                                    <div class="pct"><?= $pct !== null ? number_format($pct, 1) . '%' : '—' ?></div>
                                </div>
                            </td>
                            <td>
                                <?php if ($a['status'] === 'completed' && $pct !== null): ?>
                                    <?= passBadge($pct, $passingPct) ?>
                                <?php else: ?>
                                    <span style="color:var(--color-text-light);font-size:12px;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($a['status'] === 'completed'): ?>
                                    <div class="answers-split">
                                        <span class="ans-correct">✓ <?= (int)$a['correct_answers'] ?></span>
                                        <span class="ans-wrong">✗ <?= (int)$a['wrong_answers'] ?></span>
                                        <span class="ans-skip">– <?= (int)$a['unanswered'] ?></span>
                                    </div>
                                <?php else: ?>
                                    <span style="color:var(--color-text-light);font-size:12px;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= $a['time_taken'] !== null ? (int)$a['time_taken'] . ' min' : '—' ?>
                            </td>
                            <td>
                                <span class="badge <?= $statusCls ?>">
                                    <?= ucfirst(str_replace('_', ' ', $a['status'])) ?>
                                </span>
                            </td>
                            <td style="white-space:nowrap; font-size:12px; color:var(--color-text-light);">
                                <?= fmtDateTime($a['submitted_at']) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="pagination" id="pagination"></div>
        <?php endif; ?>
    </div>

</div><!-- /container -->

<div class="toast" id="toast"></div>

<script>
// ── Toast ──
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className   = 'toast ' + type;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3000);
}

// ── Filter & Search ──
const PAGE_SIZE = 20;
let currentPage = 1;
let visibleRows = [];

function filterTable() {
    const search       = document.getElementById('searchInput').value.toLowerCase().trim();
    const statusFilter = document.getElementById('statusFilter').value;
    const resultFilter = document.getElementById('resultFilter').value;
    const rows         = Array.from(document.querySelectorAll('#tableBody tr'));

    visibleRows = rows.filter(row => {
        const matchSearch = !search || row.dataset.search.includes(search);
        const matchStatus = !statusFilter || row.dataset.status === statusFilter;
        const matchResult = !resultFilter || row.dataset.result === resultFilter;
        return matchSearch && matchStatus && matchResult;
    });

    currentPage = 1;
    renderPage();
    document.getElementById('resultsCount').textContent =
        visibleRows.length + ' attempt' + (visibleRows.length !== 1 ? 's' : '');
}

function renderPage() {
    const allRows = Array.from(document.querySelectorAll('#tableBody tr'));
    allRows.forEach(r => r.style.display = 'none');

    const start = (currentPage - 1) * PAGE_SIZE;
    const end   = start + PAGE_SIZE;
    visibleRows.slice(start, end).forEach(r => r.style.display = '');

    renderPagination();
}

function renderPagination() {
    const total = Math.ceil(visibleRows.length / PAGE_SIZE);
    const pg    = document.getElementById('pagination');
    if (!pg) return;
    pg.innerHTML = '';
    if (total <= 1) return;

    const mkBtn = (label, page, active = false, disabled = false) => {
        const b = document.createElement('button');
        b.className   = 'page-btn' + (active ? ' active' : '');
        b.textContent = label;
        b.disabled    = disabled;
        b.onclick     = () => { currentPage = page; renderPage(); };
        return b;
    };

    pg.appendChild(mkBtn('‹ Prev', currentPage - 1, false, currentPage === 1));
    for (let i = 1; i <= total; i++) {
        if (i === 1 || i === total || Math.abs(i - currentPage) <= 1) {
            pg.appendChild(mkBtn(i, i, i === currentPage));
        } else if (Math.abs(i - currentPage) === 2) {
            const dots = document.createElement('span');
            dots.textContent = '…'; dots.style.padding = '0 4px';
            pg.appendChild(dots);
        }
    }
    pg.appendChild(mkBtn('Next ›', currentPage + 1, false, currentPage === total));
}

// ── Sort ──
let sortDir = {};
function sortTable(colIdx) {
    const tbody = document.getElementById('tableBody');
    const rows  = Array.from(tbody.querySelectorAll('tr'));
    sortDir[colIdx] = !sortDir[colIdx];
    const asc = sortDir[colIdx];

    rows.sort((a, b) => {
        const av = a.cells[colIdx]?.textContent?.trim() ?? '';
        const bv = b.cells[colIdx]?.textContent?.trim() ?? '';
        const an = parseFloat(av), bn = parseFloat(bv);
        if (!isNaN(an) && !isNaN(bn)) return asc ? an - bn : bn - an;
        return asc ? av.localeCompare(bv) : bv.localeCompare(av);
    });

    rows.forEach(r => tbody.appendChild(r));
    document.querySelectorAll('thead th').forEach((th, i) => {
        th.classList.toggle('sorted', i === colIdx);
        const icon = th.querySelector('.sort-icon');
        if (icon) icon.textContent = i === colIdx ? (asc ? '↑' : '↓') : '↕';
    });

    // Re-filter after sort
    filterTable();
}

// ── Export CSV ──
function exportCSV() {
    const rows   = Array.from(document.querySelectorAll('#tableBody tr'));
    const header = ['Student Name', 'Email', 'Reg No.', 'Attempt #', 'Score', 'Percentage', 'Result', 'Correct', 'Wrong', 'Unanswered', 'Time (min)', 'Status', 'Submitted'];

    const lines = [header.join(',')];

    rows.forEach(row => {
        const cells = Array.from(row.cells).map(c => {
            const text = c.innerText.replace(/\n/g, ' ').replace(/,/g, ';').trim();
            return '"' + text + '"';
        });
        lines.push(cells.join(','));
    });

    const blob = new Blob([lines.join('\n')], { type: 'text/csv' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = 'results_assessment_<?= $assessmentId ?>_<?= date('Ymd') ?>.csv';
    a.click();
    URL.revokeObjectURL(url);
    showToast('CSV exported successfully!');
}

// ── Init ──
window.addEventListener('DOMContentLoaded', () => {
    visibleRows = Array.from(document.querySelectorAll('#tableBody tr'));
    renderPage();
});
</script>
</body>
</html>
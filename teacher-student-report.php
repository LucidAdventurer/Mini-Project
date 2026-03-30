<?php
/* ========================================
 * TEACHER — STUDENT PERFORMANCE REPORT
 * File: teacher-student-report.php
 * ======================================== */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db-guard.php';

$currentUser = validateSession($conn, 'teacher');
$teacherId   = (int) $currentUser['user_id'];
$userName    = htmlspecialchars($currentUser['full_name'] ?? 'Teacher');
$userEmail   = htmlspecialchars($currentUser['email'] ?? '');
$userInitials = strtoupper(substr($currentUser['full_name'] ?? 'T', 0, 2));

// ── Profile picture ──
$picStmt = $conn->prepare("SELECT profile_image FROM users WHERE user_id = ?");
$picStmt->bind_param("i", $teacherId);
$picStmt->execute();
$picRow      = $picStmt->get_result()->fetch_assoc();
$userPicture = $picRow['profile_image'] ?? '';

// ── Target student ──
$studentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
if ($studentId <= 0) {
    header('Location: teacher-students.php');
    exit;
}

// ── Verify this student has attempted one of this teacher's assessments
//    OR is a member of one of this teacher's groups ──
$accessCheck = safePreparedQuery($conn,
    "SELECT u.user_id FROM users u
     WHERE u.user_id = ? AND u.role = 'student'
       AND (
           EXISTS (
               SELECT 1 FROM assessment_attempts aa
               JOIN assessments a ON a.assessment_id = aa.assessment_id
               WHERE aa.user_id = u.user_id AND a.created_by = ?
               AND aa.status IN ('submitted','timeout')
           )
           OR EXISTS (
               SELECT 1 FROM group_members gm
               JOIN groups g ON g.group_id = gm.group_id
               WHERE gm.student_id = u.user_id AND g.teacher_id = ?
           )
       )",
    "iii", [$studentId, $teacherId, $teacherId]
);
if (!$accessCheck['success'] || !$accessCheck['result'] || $accessCheck['result']->num_rows === 0) {
    header('Location: teacher-students.php');
    exit;
}
if ($accessCheck['result']) $accessCheck['result']->free();

// ── Student profile ──
$student = [];
$sResult = safePreparedQuery($conn,
    "SELECT user_id, full_name, email, department, registration_number, profile_image, created_at
     FROM users WHERE user_id = ?",
    "i", [$studentId]
);
if ($sResult['success'] && $sResult['result']) {
    $student = $sResult['result']->fetch_assoc() ?? [];
    $sResult['result']->free();
}
if (empty($student)) {
    header('Location: teacher-students.php');
    exit;
}

// ── Groups this student is in (from this teacher) ──
$studentGroups = [];
$sgResult = safePreparedQuery($conn,
    "SELECT g.name FROM group_members gm
     JOIN groups g ON g.group_id = gm.group_id
     WHERE gm.student_id = ? AND g.teacher_id = ?",
    "ii", [$studentId, $teacherId]
);
if ($sgResult['success'] && $sgResult['result']) {
    while ($row = $sgResult['result']->fetch_assoc()) {
        $studentGroups[] = $row['name'];
    }
    $sgResult['result']->free();
}

// ── Overall stats ──
$stats = ['total_attempts' => 0, 'avg_score' => null, 'max_score' => null, 'min_score' => null,
          'pass_count' => 0, 'fail_count' => 0, 'total_assessments' => 0];
$stResult = safePreparedQuery($conn,
    "SELECT
        COUNT(aa.attempt_id)            AS total_attempts,
        ROUND(AVG(aa.percentage), 2)   AS avg_score,
        MAX(aa.percentage)             AS max_score,
        MIN(aa.percentage)             AS min_score,
        SUM(CASE WHEN aa.percentage >= a.passing_marks / a.total_marks * 100 THEN 1 ELSE 0 END) AS pass_count,
        SUM(CASE WHEN aa.percentage <  a.passing_marks / a.total_marks * 100 THEN 1 ELSE 0 END) AS fail_count,
        COUNT(DISTINCT aa.assessment_id)  AS total_assessments
     FROM assessment_attempts aa
     JOIN assessments a ON a.assessment_id = aa.assessment_id
     WHERE aa.user_id = ? AND a.created_by = ?
       AND aa.status IN ('submitted','timeout')",
    "ii", [$studentId, $teacherId]
);
if ($stResult['success'] && $stResult['result']) {
    $stats = array_merge($stats, $stResult['result']->fetch_assoc() ?? []);
    $stResult['result']->free();
}

// ── Per-assessment detail ──
$attempts = [];
$aResult = safePreparedQuery($conn,
    "SELECT
        aa.attempt_id,
        aa.attempt_number,
        aa.score,
        aa.percentage,
        aa.status,
        aa.start_time,
        aa.submitted_at,
        TIMESTAMPDIFF(MINUTE, aa.start_time, aa.submitted_at) AS duration_taken,
        a.assessment_id,
        a.title,
        a.category,
        a.difficulty,
        a.total_marks,
        a.passing_marks,
        a.duration_minutes
     FROM assessment_attempts aa
     JOIN assessments a ON a.assessment_id = aa.assessment_id
     WHERE aa.user_id = ? AND a.created_by = ?
       AND aa.status IN ('submitted','timeout')
     ORDER BY aa.submitted_at DESC",
    "ii", [$studentId, $teacherId]
);
if ($aResult['success'] && $aResult['result']) {
    while ($row = $aResult['result']->fetch_assoc()) {
        $attempts[] = $row;
    }
    $aResult['result']->free();
}

// ── Score trend data (for line chart) — last 20 attempts chronologically ──
$trendData  = array_slice(array_reverse($attempts), 0, 20);
$trendLabels = [];
$trendScores = [];
foreach ($trendData as $t) {
    $trendLabels[] = date('M j', strtotime($t['submitted_at']));
    $trendScores[] = round((float)$t['percentage'], 1);
}

// ── Category breakdown ──
$catMap = [];
foreach ($attempts as $a) {
    $cat = $a['category'] ?: 'Uncategorized';
    if (!isset($catMap[$cat])) {
        $catMap[$cat] = ['count' => 0, 'total_pct' => 0];
    }
    $catMap[$cat]['count']++;
    $catMap[$cat]['total_pct'] += (float)$a['percentage'];
}
$categories    = [];
$catAvgScores  = [];
foreach ($catMap as $cat => $val) {
    $categories[]   = $cat;
    $catAvgScores[] = round($val['total_pct'] / $val['count'], 1);
}

// ── Score distribution (0-25, 26-50, 51-75, 76-100) ──
$dist = [0, 0, 0, 0];
foreach ($attempts as $a) {
    $p = (float)$a['percentage'];
    if ($p <= 25)       $dist[0]++;
    elseif ($p <= 50)   $dist[1]++;
    elseif ($p <= 75)   $dist[2]++;
    else                $dist[3]++;
}

// Helpers
function fmtDate(?string $dt): string {
    if (!$dt) return '—';
    return date('M j, Y', strtotime($dt));
}
function fmtDateTime(?string $dt): string {
    if (!$dt) return '—';
    return date('M j, Y g:i A', strtotime($dt));
}
function scoreColor(float $pct): string {
    if ($pct >= 75) return '#10b981';
    if ($pct >= 50) return '#f59e0b';
    return '#f43f5e';
}
function diffBadge(string $d): string {
    return match($d) {
        'easy'   => 'background:rgba(16,185,129,0.1);color:#10b981',
        'medium' => 'background:rgba(245,158,11,0.1);color:#f59e0b',
        'hard'   => 'background:rgba(244,63,94,0.1);color:#f43f5e',
        default  => 'background:rgba(139,127,168,0.1);color:#8b7fa8',
    };
}

$studentName    = htmlspecialchars($student['full_name'] ?? 'Student');
$studentInitials = strtoupper(substr($student['full_name'] ?? '?', 0, 2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $studentName ?> — Performance Report — PREPAURA</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
:root {
  --ink:         #0d0a14;
  --ink-2:       #1a1425;
  --ink-3:       #261d35;
  --surface:     #f7f5fb;
  --surface-2:   #ede9f6;
  --surface-3:   #ffffff;
  --violet:      #7c3aed;
  --violet-lt:   #9f67f5;
  --violet-dim:  rgba(124,58,237,0.12);
  --violet-glow: rgba(124,58,237,0.25);
  --orchid:      #c084fc;
  --gold:        #f59e0b;
  --emerald:     #10b981;
  --rose:        #f43f5e;
  --sky:         #38bdf8;
  --text-1:      #1a1425;
  --text-2:      #4b4565;
  --text-3:      #8b7fa8;
  --border:      rgba(124,58,237,0.1);
  --border-2:    rgba(124,58,237,0.18);
  --shadow-xs:   0 1px 3px rgba(13,10,20,0.06);
  --shadow-sm:   0 2px 12px rgba(13,10,20,0.08);
  --shadow-md:   0 8px 32px rgba(13,10,20,0.12);
  --shadow-lg:   0 20px 60px rgba(13,10,20,0.18);
  --r-sm:        8px;
  --r-md:        14px;
  --r-lg:        20px;
  --r-xl:        28px;
  --ease:        cubic-bezier(0.22,1,0.36,1);
  --t:           0.22s var(--ease);
  --font-head:   'Times New Roman', Arial, serif;
  --font-body:   'Calibri', 'Segoe UI', Arial, sans-serif;
  --nav-h:       64px;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
html{-webkit-font-smoothing:antialiased;scroll-behavior:smooth}
body{font-family:var(--font-body);font-size:15px;background:var(--surface);color:var(--text-1);min-height:100vh;padding-top:var(--nav-h);overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.03'/%3E%3C/svg%3E");background-size:200px 200px}

/* NAVBAR */
.navbar{height:var(--nav-h);background:rgba(13,10,20,0.96);backdrop-filter:blur(20px) saturate(1.6);border-bottom:1px solid rgba(255,255,255,0.06);padding:0 28px;display:flex;align-items:center;justify-content:space-between;gap:20px;position:fixed;top:0;left:0;right:0;z-index:1000}
.navbar-brand{display:flex;align-items:center;gap:12px;text-decoration:none;flex-shrink:0}
.brand-logo-img{width:36px;height:36px;border-radius:9px;object-fit:contain;background:white;padding:3px}
.brand-text-group{display:flex;flex-direction:column;line-height:1.15}
.brand-name{font-family:var(--font-head);font-size:16px;font-weight:800;letter-spacing:0.06em;color:white}
.brand-tagline{font-size:10px;font-weight:400;color:rgba(255,255,255,0.45);letter-spacing:0.03em}
.nav-right{display:flex;align-items:center;gap:12px}
.nav-back-btn{display:inline-flex;align-items:center;gap:7px;padding:8px 14px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.12);border-radius:var(--r-sm);color:rgba(255,255,255,0.8);font-size:13px;font-weight:500;text-decoration:none;transition:var(--t)}
.nav-back-btn:hover{background:rgba(255,255,255,0.14);color:white}
.profile-wrap{position:relative}
.profile-button{display:flex;align-items:center;gap:9px;padding:6px 12px 6px 6px;background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.1);border-radius:40px;cursor:pointer;transition:var(--t);color:white}
.profile-button:hover{background:rgba(255,255,255,0.13)}
.profile-avatar{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--violet),var(--orchid));display:flex;align-items:center;justify-content:center;font-family:var(--font-head);font-weight:700;font-size:12px;color:white;overflow:hidden;flex-shrink:0}
.profile-avatar img{width:100%;height:100%;object-fit:cover;border-radius:50%}
.profile-name{font-size:13px;font-weight:500}
.profile-caret{font-size:9px;color:rgba(255,255,255,0.5);margin-left:2px}
.profile-dropdown{position:absolute;top:calc(100% + 10px);right:0;background:var(--surface-3);border-radius:var(--r-md);box-shadow:var(--shadow-lg),0 0 0 1px var(--border);min-width:230px;opacity:0;visibility:hidden;transform:translateY(-6px) scale(0.98);transition:var(--t);z-index:1001;overflow:hidden}
.profile-dropdown.open{opacity:1;visibility:visible;transform:translateY(0) scale(1)}
.dropdown-header{padding:18px 20px;background:linear-gradient(135deg,var(--ink) 0%,var(--ink-3) 100%);border-bottom:1px solid rgba(255,255,255,0.06)}
.dd-avatar{width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,var(--violet),var(--orchid));display:flex;align-items:center;justify-content:center;font-family:var(--font-head);font-weight:700;font-size:16px;color:white;overflow:hidden;margin-bottom:10px}
.dd-avatar img{width:100%;height:100%;object-fit:cover;border-radius:50%}
.dropdown-name{font-weight:600;font-size:14px;color:white}
.dropdown-email{font-size:12px;color:rgba(255,255,255,0.5);margin-top:2px}
.dropdown-role{display:inline-block;margin-top:8px;padding:2px 10px;background:var(--violet-dim);border:1px solid rgba(124,58,237,0.3);color:var(--orchid);border-radius:20px;font-size:11px;font-weight:600;letter-spacing:0.04em;text-transform:uppercase}
.dropdown-menu{padding:6px 0}
.dropdown-item{display:flex;align-items:center;gap:11px;padding:10px 18px;color:var(--text-2);text-decoration:none;font-size:13.5px;transition:var(--t);cursor:pointer;border:none;background:none;width:100%;text-align:left;font-family:var(--font-body)}
.dropdown-item i{width:16px;text-align:center;color:var(--text-3)}
.dropdown-item:hover{background:var(--surface-2);color:var(--text-1)}
.dropdown-item.danger{color:var(--rose)}
.dropdown-item.danger:hover{background:rgba(244,63,94,0.06)}
.dropdown-divider{height:1px;background:var(--border);margin:4px 0}

/* LAYOUT */
.page-wrapper{display:flex;min-height:calc(100vh - var(--nav-h));position:relative;z-index:1}
.left-sidebar{width:230px;flex-shrink:0;padding:28px 12px;display:flex;flex-direction:column;gap:2px;background:rgba(255,255,255,0.6);backdrop-filter:blur(12px);border-right:1px solid var(--border);min-height:calc(100vh - var(--nav-h));position:sticky;top:var(--nav-h);align-self:flex-start}
.sidebar-section-label{font-family:var(--font-head);font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-3);padding:14px 14px 6px}
.sidebar-link{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:var(--r-sm);text-decoration:none;font-size:13.5px;font-weight:500;color:var(--text-2);transition:var(--t)}
.sidebar-link i{width:18px;text-align:center;font-size:14px;color:var(--text-3);transition:var(--t)}
.sidebar-link:hover{background:var(--violet-dim);color:var(--violet)}
.sidebar-link:hover i{color:var(--violet)}
.sidebar-link.active{background:linear-gradient(135deg,rgba(124,58,237,0.12),rgba(192,132,252,0.08));color:var(--violet);font-weight:600;box-shadow:inset 3px 0 0 var(--violet)}
.sidebar-link.active i{color:var(--violet)}
.sidebar-bottom{margin-top:auto;padding-top:16px;border-top:1px solid var(--border)}
.sidebar-logout{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:var(--r-sm);font-size:13.5px;font-weight:500;color:var(--rose);background:none;border:none;cursor:pointer;width:100%;transition:var(--t);font-family:var(--font-body)}
.sidebar-logout i{width:18px;text-align:center;font-size:14px}
.sidebar-logout:hover{background:rgba(244,63,94,0.07)}
.page-content{flex:1;min-width:0;padding:36px 36px 60px 28px}

/* STUDENT HERO */
.student-hero{background:linear-gradient(135deg,var(--ink) 0%,var(--ink-3) 55%,#3d1f6e 100%);border-radius:var(--r-xl);padding:32px 36px;margin-bottom:28px;display:flex;align-items:center;gap:24px;position:relative;overflow:hidden;box-shadow:var(--shadow-md)}
.student-hero::before{content:'';position:absolute;top:-60px;right:-40px;width:280px;height:280px;background:radial-gradient(circle,rgba(124,58,237,0.35) 0%,transparent 70%);pointer-events:none}
.student-hero::after{content:'';position:absolute;bottom:-80px;left:30%;width:200px;height:200px;background:radial-gradient(circle,rgba(192,132,252,0.2) 0%,transparent 70%);pointer-events:none}
.hero-avatar{width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,var(--violet),var(--orchid));display:flex;align-items:center;justify-content:center;font-family:var(--font-head);font-weight:700;font-size:26px;color:white;overflow:hidden;flex-shrink:0;border:3px solid rgba(255,255,255,0.15);position:relative;z-index:1}
.hero-avatar img{width:100%;height:100%;object-fit:cover;border-radius:50%}
.hero-info{flex:1;position:relative;z-index:1}
.hero-name{font-family:var(--font-head);font-size:24px;font-weight:800;color:white;margin-bottom:4px}
.hero-meta{display:flex;flex-wrap:wrap;gap:16px;margin-top:6px}
.hero-meta-item{display:flex;align-items:center;gap:6px;font-size:13px;color:rgba(255,255,255,0.65)}
.hero-meta-item i{font-size:11px}
.hero-groups{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}
.hero-group-chip{padding:3px 10px;border-radius:20px;background:rgba(124,58,237,0.3);border:1px solid rgba(124,58,237,0.5);color:var(--orchid);font-size:11px;font-weight:600}
.hero-actions{display:flex;flex-direction:column;gap:8px;align-items:flex-end;flex-shrink:0;position:relative;z-index:1}
.download-btn{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border-radius:var(--r-sm);font-family:var(--font-body);font-size:13px;font-weight:600;cursor:pointer;border:none;transition:var(--t);text-decoration:none;white-space:nowrap}
.btn-pdf{background:rgba(244,63,94,0.15);border:1px solid rgba(244,63,94,0.35);color:#f87171}
.btn-pdf:hover{background:var(--rose);color:white}
.btn-excel{background:rgba(16,185,129,0.12);border:1px solid rgba(16,185,129,0.3);color:#34d399}
.btn-excel:hover{background:var(--emerald);color:white}
.btn-csv{background:rgba(56,189,248,0.12);border:1px solid rgba(56,189,248,0.3);color:#7dd3fc}
.btn-csv:hover{background:var(--sky);color:white}
.btn-print{background:rgba(192,132,252,0.15);border:1px solid rgba(192,132,252,0.3);color:var(--orchid)}
.btn-print:hover{background:var(--orchid);color:white}
.download-row{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}

/* STAT CARDS */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px}
.stat-card{background:var(--surface-3);border-radius:var(--r-lg);padding:22px 24px;border:1px solid var(--border);box-shadow:var(--shadow-xs);transition:var(--t)}
.stat-card:hover{box-shadow:var(--shadow-sm);transform:translateY(-2px)}
.stat-card-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-3);margin-bottom:10px}
.stat-card-value{font-family:var(--font-head);font-size:32px;font-weight:800;color:var(--text-1);line-height:1}
.stat-card-sub{font-size:12px;color:var(--text-3);margin-top:6px}
.stat-icon{width:40px;height:40px;border-radius:var(--r-sm);display:flex;align-items:center;justify-content:center;font-size:18px;margin-bottom:12px}

/* CHARTS SECTION */
.charts-grid{display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:28px}
.chart-card{background:var(--surface-3);border-radius:var(--r-lg);padding:24px;border:1px solid var(--border);box-shadow:var(--shadow-xs)}
.chart-card-full{grid-column:1/-1}
.chart-title{font-family:var(--font-head);font-size:15px;font-weight:700;color:var(--text-1);margin-bottom:18px;display:flex;align-items:center;gap:8px}
.chart-title i{color:var(--violet);font-size:14px}
.chart-wrap{position:relative;height:220px}
.chart-wrap-sm{position:relative;height:200px}
.charts-row2{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px}

/* ATTEMPTS TABLE */
.section-title{font-family:var(--font-head);font-size:18px;font-weight:800;color:var(--text-1);margin-bottom:16px;display:flex;align-items:center;gap:10px}
.section-title i{color:var(--violet)}
.attempts-card{background:var(--surface-3);border-radius:var(--r-lg);border:1px solid var(--border);box-shadow:var(--shadow-xs);overflow:hidden;margin-bottom:28px}
.attempts-table{width:100%;border-collapse:collapse}
.attempts-table thead tr{background:linear-gradient(135deg,var(--ink) 0%,var(--ink-3) 100%)}
.attempts-table thead th{padding:13px 16px;text-align:left;font-family:var(--font-head);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:rgba(255,255,255,0.7);white-space:nowrap}
.attempts-table tbody tr{border-bottom:1px solid var(--border);transition:background var(--t)}
.attempts-table tbody tr:last-child{border-bottom:none}
.attempts-table tbody tr:hover{background:var(--violet-dim)}
.attempts-table td{padding:13px 16px;font-size:13.5px;color:var(--text-2);vertical-align:middle}
.score-pill{display:inline-flex;align-items:center;justify-content:center;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;min-width:58px}
.diff-badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600}
.pass-badge{background:rgba(16,185,129,0.1);color:var(--emerald);padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600}
.fail-badge{background:rgba(244,63,94,0.08);color:var(--rose);padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600}
.timeout-badge{background:rgba(245,158,11,0.1);color:var(--gold);padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600}
.empty-state{text-align:center;padding:64px 24px;color:var(--text-3)}
.empty-state i{font-size:40px;margin-bottom:14px;display:block;color:var(--border-2)}
.empty-state h3{font-size:17px;font-weight:600;color:var(--text-2);margin-bottom:8px}

/* PRINT STYLES */
@media print {
  .navbar,.left-sidebar,.hero-actions,.no-print{display:none!important}
  body{padding-top:0;background:white}
  .page-wrapper{display:block}
  .page-content{padding:20px}
  .student-hero{background:#1a1425!important;print-color-adjust:exact;-webkit-print-color-adjust:exact}
  .chart-card,.attempts-card,.stat-card{break-inside:avoid;page-break-inside:avoid}
  .charts-grid,.charts-row2{display:grid}
}
@media(max-width:1100px){
  .charts-grid{grid-template-columns:1fr}
  .stats-grid{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:900px){.left-sidebar{display:none}.charts-row2{grid-template-columns:1fr}}
@media(max-width:640px){
  .page-content{padding:20px 16px 48px}
  .stats-grid{grid-template-columns:1fr 1fr}
  .student-hero{flex-direction:column;align-items:flex-start}
  .hero-actions{align-items:flex-start;width:100%}
  .download-row{justify-content:flex-start}
}
</style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar">
  <a href="teacher-dashboard.php" class="navbar-brand">
    <img src="prepaura-logo.png" alt="PREPAURA" class="brand-logo-img">
    <div class="brand-text-group">
      <span class="brand-name">PREPAURA</span>
      <span class="brand-tagline">Placement Training Platform</span>
    </div>
  </a>
  <div class="nav-right">
    <a href="teacher-students.php" class="nav-back-btn no-print">
      <i class="fa fa-arrow-left"></i> Back to Students
    </a>
    <div class="profile-wrap">
      <button class="profile-button" id="profileBtn">
        <div class="profile-avatar">
          <?php if (!empty($userPicture)): ?>
            <img src="<?= htmlspecialchars($userPicture) ?>" alt="">
          <?php else: ?>
            <?= $userInitials ?>
          <?php endif; ?>
        </div>
        <span class="profile-name"><?= $userName ?></span>
        <i class="fa fa-chevron-down profile-caret"></i>
        <div class="profile-dropdown" id="profileDropdown">
          <div class="dropdown-header">
            <div class="dd-avatar">
              <?php if (!empty($userPicture)): ?>
                <img src="<?= htmlspecialchars($userPicture) ?>" alt="">
              <?php else: ?>
                <?= $userInitials ?>
              <?php endif; ?>
            </div>
            <div class="dropdown-name"><?= $userName ?></div>
            <div class="dropdown-email"><?= $userEmail ?></div>
            <span class="dropdown-role">Teacher</span>
          </div>
          <div class="dropdown-menu">
            <a href="teacher-profile.php" class="dropdown-item"><i class="fa fa-user"></i> My Profile</a>
            <div class="dropdown-divider"></div>
            <a href="#" onclick="handleLogout()" class="dropdown-item danger"><i class="fa fa-right-from-bracket"></i> Logout</a>
          </div>
        </div>
      </button>
    </div>
  </div>
</nav>

<!-- MAIN -->
<div class="page-wrapper">
  <aside class="left-sidebar no-print">
    <span class="sidebar-section-label">Navigation</span>
    <a href="teacher-dashboard.php" class="sidebar-link"><i class="fa fa-house"></i> Dashboard</a>
    <a href="teacher-assessments.php" class="sidebar-link"><i class="fa fa-clipboard-list"></i> Assessments</a>
    <a href="teacher-students.php" class="sidebar-link active"><i class="fa fa-user-graduate"></i> Students</a>
    <a href="manage-groups.php" class="sidebar-link"><i class="fa fa-users"></i> Manage Groups</a>
    <a href="teacher-resources.php" class="sidebar-link"><i class="fa fa-folder-open"></i> Resources</a>
    <div class="sidebar-bottom">
      <button onclick="handleLogout()" class="sidebar-logout"><i class="fa fa-right-from-bracket"></i> Logout</button>
    </div>
  </aside>

  <div class="page-content" id="reportContent">

    <!-- Student Hero -->
    <div class="student-hero">
      <div class="hero-avatar">
        <?php if (!empty($student['profile_image'])): ?>
          <img src="<?= htmlspecialchars($student['profile_image']) ?>" alt="">
        <?php else: ?>
          <?= $studentInitials ?>
        <?php endif; ?>
      </div>
      <div class="hero-info">
        <div class="hero-name"><?= $studentName ?></div>
        <div class="hero-meta">
          <span class="hero-meta-item"><i class="fa fa-envelope"></i> <?= htmlspecialchars($student['email']) ?></span>
          <?php if (!empty($student['registration_number'])): ?>
          <span class="hero-meta-item"><i class="fa fa-id-card"></i> <?= htmlspecialchars($student['registration_number']) ?></span>
          <?php endif; ?>
          <?php if (!empty($student['department'])): ?>
          <span class="hero-meta-item"><i class="fa fa-building"></i> <?= htmlspecialchars($student['department']) ?></span>
          <?php endif; ?>
          <span class="hero-meta-item"><i class="fa fa-calendar"></i> Joined <?= fmtDate($student['created_at']) ?></span>
        </div>
        <?php if (!empty($studentGroups)): ?>
        <div class="hero-groups">
          <?php foreach ($studentGroups as $g): ?>
          <span class="hero-group-chip"><i class="fa fa-users" style="font-size:9px;margin-right:3px"></i><?= htmlspecialchars($g) ?></span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <div class="hero-actions no-print">
        <div class="download-row">
          <button onclick="downloadPDF()" class="download-btn btn-pdf"><i class="fa fa-file-pdf"></i> PDF</button>
          <button onclick="downloadExcel()" class="download-btn btn-excel"><i class="fa fa-file-excel"></i> Excel</button>
          <button onclick="downloadCSV()" class="download-btn btn-csv"><i class="fa fa-file-csv"></i> CSV</button>
          <button onclick="window.print()" class="download-btn btn-print"><i class="fa fa-print"></i> Print</button>
        </div>
      </div>
    </div>

    <!-- Stat Cards -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--violet-dim);color:var(--violet)"><i class="fa fa-clipboard-check"></i></div>
        <div class="stat-card-label">Total Attempts</div>
        <div class="stat-card-value"><?= (int)$stats['total_attempts'] ?></div>
        <div class="stat-card-sub"><?= (int)$stats['total_assessments'] ?> assessment<?= $stats['total_assessments'] != 1 ? 's' : '' ?> attempted</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:rgba(56,189,248,0.1);color:var(--sky)"><i class="fa fa-chart-bar"></i></div>
        <div class="stat-card-label">Average Score</div>
        <div class="stat-card-value" style="color:<?= $stats['avg_score'] !== null ? scoreColor((float)$stats['avg_score']) : 'var(--text-3)' ?>">
          <?= $stats['avg_score'] !== null ? number_format((float)$stats['avg_score'], 1) . '%' : '—' ?>
        </div>
        <div class="stat-card-sub">across all submissions</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:rgba(16,185,129,0.1);color:var(--emerald)"><i class="fa fa-trophy"></i></div>
        <div class="stat-card-label">Best Score</div>
        <div class="stat-card-value" style="color:var(--emerald)">
          <?= $stats['max_score'] !== null ? number_format((float)$stats['max_score'], 1) . '%' : '—' ?>
        </div>
        <div class="stat-card-sub">
          <?php if ($stats['min_score'] !== null): ?>
          Lowest: <?= number_format((float)$stats['min_score'], 1) ?>%
          <?php else: ?>No data<?php endif; ?>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:rgba(245,158,11,0.1);color:var(--gold)"><i class="fa fa-check-circle"></i></div>
        <div class="stat-card-label">Pass Rate</div>
        <div class="stat-card-value" style="color:var(--gold)">
          <?php
          $total = (int)$stats['total_attempts'];
          $pass  = (int)$stats['pass_count'];
          echo $total > 0 ? round($pass / $total * 100) . '%' : '—';
          ?>
        </div>
        <div class="stat-card-sub">
          <?= $pass ?> passed · <?= (int)$stats['fail_count'] ?> failed
        </div>
      </div>
    </div>

    <!-- Charts -->
    <div class="charts-grid">
      <!-- Score Trend -->
      <div class="chart-card">
        <div class="chart-title"><i class="fa fa-chart-line"></i> Score Trend Over Time</div>
        <?php if (count($trendScores) >= 2): ?>
        <div class="chart-wrap"><canvas id="trendChart"></canvas></div>
        <?php else: ?>
        <div style="text-align:center;padding:40px;color:var(--text-3);font-size:13px">
          Not enough attempts to show a trend yet.
        </div>
        <?php endif; ?>
      </div>
      <!-- Score Distribution -->
      <div class="chart-card">
        <div class="chart-title"><i class="fa fa-chart-pie"></i> Score Distribution</div>
        <?php if ($total > 0): ?>
        <div class="chart-wrap-sm"><canvas id="distChart"></canvas></div>
        <?php else: ?>
        <div style="text-align:center;padding:40px;color:var(--text-3);font-size:13px">No data yet.</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="charts-row2">
      <!-- Pass vs Fail -->
      <div class="chart-card">
        <div class="chart-title"><i class="fa fa-circle-half-stroke"></i> Pass vs Fail</div>
        <?php if ($total > 0): ?>
        <div class="chart-wrap-sm"><canvas id="passFailChart"></canvas></div>
        <?php else: ?>
        <div style="text-align:center;padding:40px;color:var(--text-3);font-size:13px">No data yet.</div>
        <?php endif; ?>
      </div>
      <!-- Category Performance -->
      <div class="chart-card">
        <div class="chart-title"><i class="fa fa-layer-group"></i> Avg Score by Category</div>
        <?php if (!empty($categories)): ?>
        <div class="chart-wrap-sm"><canvas id="catChart"></canvas></div>
        <?php else: ?>
        <div style="text-align:center;padding:40px;color:var(--text-3);font-size:13px">No category data yet.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Attempts Table -->
    <div class="section-title"><i class="fa fa-list-check"></i> All Attempts</div>
    <div class="attempts-card">
      <?php if (empty($attempts)): ?>
        <div class="empty-state">
          <i class="fa fa-clipboard-list"></i>
          <h3>No attempts yet</h3>
          <p>This student hasn't attempted any of your assessments.</p>
        </div>
      <?php else: ?>
      <table class="attempts-table" id="attemptsTable">
        <thead>
          <tr>
            <th>#</th>
            <th>Assessment</th>
            <th>Category</th>
            <th>Difficulty</th>
            <th>Score</th>
            <th>Percentage</th>
            <th>Result</th>
            <th>Duration</th>
            <th>Submitted</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($attempts as $i => $a):
            $pct       = (float)$a['percentage'];
            $passing   = $a['total_marks'] > 0 ? ($a['passing_marks'] / $a['total_marks'] * 100) : 50;
            $passed    = $pct >= $passing;
            $barColor  = scoreColor($pct);
            $durTaken  = $a['duration_taken'] !== null ? (int)$a['duration_taken'] . ' min' : '—';
          ?>
          <tr>
            <td style="color:var(--text-3);font-size:12px"><?= $i + 1 ?></td>
            <td style="font-weight:600;color:var(--text-1);max-width:200px">
              <?= htmlspecialchars($a['title']) ?>
            </td>
            <td style="font-size:12.5px"><?= htmlspecialchars($a['category'] ?: '—') ?></td>
            <td>
              <span class="diff-badge" style="<?= diffBadge($a['difficulty']) ?>">
                <?= ucfirst($a['difficulty']) ?>
              </span>
            </td>
            <td style="font-weight:600">
              <?= number_format((float)$a['score'], 1) ?> / <?= (int)$a['total_marks'] ?>
            </td>
            <td>
              <span class="score-pill" style="background:<?= $barColor ?>1a;color:<?= $barColor ?>">
                <?= number_format($pct, 1) ?>%
              </span>
            </td>
            <td>
              <?php if ($a['status'] === 'timeout'): ?>
              <span class="timeout-badge">Timeout</span>
              <?php elseif ($passed): ?>
              <span class="pass-badge">Pass</span>
              <?php else: ?>
              <span class="fail-badge">Fail</span>
              <?php endif; ?>
            </td>
            <td style="font-size:12.5px;color:var(--text-3)"><?= $durTaken ?></td>
            <td style="font-size:12.5px;color:var(--text-3)"><?= fmtDateTime($a['submitted_at']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <!-- Report timestamp -->
    <div style="text-align:right;font-size:12px;color:var(--text-3);margin-top:-12px" class="no-print">
      Report generated: <?= date('F j, Y g:i A') ?> · PREPAURA
    </div>

  </div><!-- /page-content -->
</div><!-- /page-wrapper -->

<!-- ── DATA for JS ── -->
<script>
const TREND_LABELS   = <?= json_encode($trendLabels) ?>;
const TREND_SCORES   = <?= json_encode($trendScores) ?>;
const DIST_DATA      = <?= json_encode($dist) ?>;
const CAT_LABELS     = <?= json_encode($categories) ?>;
const CAT_SCORES     = <?= json_encode($catAvgScores) ?>;
const PASS_COUNT     = <?= (int)$stats['pass_count'] ?>;
const FAIL_COUNT     = <?= (int)$stats['fail_count'] ?>;
const STUDENT_NAME   = <?= json_encode($student['full_name'] ?? '') ?>;
const STUDENT_EMAIL  = <?= json_encode($student['email'] ?? '') ?>;
const STUDENT_REG    = <?= json_encode($student['registration_number'] ?? '') ?>;
const STUDENT_DEPT   = <?= json_encode($student['department'] ?? '') ?>;
const AVG_SCORE      = <?= json_encode($stats['avg_score']) ?>;
const MAX_SCORE      = <?= json_encode($stats['max_score']) ?>;
const MIN_SCORE      = <?= json_encode($stats['min_score']) ?>;
const TOTAL_ATTEMPTS = <?= (int)$stats['total_attempts'] ?>;
const PASS_RATE      = <?= $total > 0 ? round($pass / $total * 100) : 0 ?>;

// ── Raw attempt data for CSV/Excel ──
const ATTEMPTS_DATA  = <?= json_encode(array_map(fn($a) => [
  'assessment'   => $a['title'],
  'category'     => $a['category'] ?: 'Uncategorized',
  'difficulty'   => ucfirst($a['difficulty']),
  'score'        => $a['score'],
  'total_marks'  => $a['total_marks'],
  'percentage'   => round((float)$a['percentage'], 2),
  'status'       => $a['status'],
  'result'       => ((float)$a['percentage'] >= ($a['total_marks'] > 0 ? $a['passing_marks'] / $a['total_marks'] * 100 : 50)) ? 'Pass' : ($a['status'] === 'timeout' ? 'Timeout' : 'Fail'),
  'duration'     => $a['duration_taken'] ? (int)$a['duration_taken'] . ' min' : '',
  'submitted_at' => $a['submitted_at'],
], $attempts)) ?>;
</script>

<script>
// ── Profile dropdown ──
document.getElementById('profileBtn')?.addEventListener('click', e => {
  e.stopPropagation();
  document.getElementById('profileDropdown').classList.toggle('open');
});
document.addEventListener('click', () => document.getElementById('profileDropdown')?.classList.remove('open'));
function handleLogout() {
  if (confirm('Are you sure you want to logout?')) window.location.href = 'logout.php';
}

// ── Chart defaults ──
Chart.defaults.font.family = "'Calibri', 'Segoe UI', Arial, sans-serif";
Chart.defaults.color       = '#8b7fa8';

// ── Score Trend ──
if (TREND_SCORES.length >= 2) {
  const tCtx = document.getElementById('trendChart')?.getContext('2d');
  if (tCtx) {
    const gradient = tCtx.createLinearGradient(0, 0, 0, 220);
    gradient.addColorStop(0, 'rgba(124,58,237,0.25)');
    gradient.addColorStop(1, 'rgba(124,58,237,0)');
    new Chart(tCtx, {
      type: 'line',
      data: {
        labels: TREND_LABELS,
        datasets: [{
          label: 'Score %',
          data: TREND_SCORES,
          borderColor: '#7c3aed',
          backgroundColor: gradient,
          borderWidth: 2.5,
          pointBackgroundColor: '#7c3aed',
          pointRadius: 4,
          pointHoverRadius: 6,
          tension: 0.4,
          fill: true,
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: { label: ctx => ' ' + ctx.parsed.y + '%' }
          }
        },
        scales: {
          y: {
            min: 0, max: 100,
            ticks: { callback: v => v + '%', stepSize: 25 },
            grid: { color: 'rgba(124,58,237,0.06)' }
          },
          x: { grid: { display: false } }
        }
      }
    });
  }
}

// ── Score Distribution ──
if (DIST_DATA.some(v => v > 0)) {
  const dCtx = document.getElementById('distChart')?.getContext('2d');
  if (dCtx) {
    new Chart(dCtx, {
      type: 'bar',
      data: {
        labels: ['0–25%', '26–50%', '51–75%', '76–100%'],
        datasets: [{
          label: 'Attempts',
          data: DIST_DATA,
          backgroundColor: ['#f43f5e88','#f59e0b88','#38bdf888','#10b98188'],
          borderColor:     ['#f43f5e',  '#f59e0b',  '#38bdf8',  '#10b981'],
          borderWidth: 1.5,
          borderRadius: 6,
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: { ticks: { stepSize: 1 }, grid: { color: 'rgba(124,58,237,0.06)' }, beginAtZero: true },
          x: { grid: { display: false } }
        }
      }
    });
  }
}

// ── Pass vs Fail doughnut ──
if (PASS_COUNT + FAIL_COUNT > 0) {
  const pfCtx = document.getElementById('passFailChart')?.getContext('2d');
  if (pfCtx) {
    new Chart(pfCtx, {
      type: 'doughnut',
      data: {
        labels: ['Passed', 'Failed'],
        datasets: [{
          data: [PASS_COUNT, FAIL_COUNT],
          backgroundColor: ['#10b981cc', '#f43f5ecc'],
          borderColor:     ['#10b981',   '#f43f5e'],
          borderWidth: 2,
          hoverOffset: 8,
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        cutout: '65%',
        plugins: {
          legend: { position: 'bottom', labels: { padding: 14, boxWidth: 12 } },
          tooltip: { callbacks: { label: ctx => ' ' + ctx.label + ': ' + ctx.parsed } }
        }
      }
    });
  }
}

// ── Category bar ──
if (CAT_LABELS.length > 0) {
  const catCtx = document.getElementById('catChart')?.getContext('2d');
  if (catCtx) {
    new Chart(catCtx, {
      type: 'bar',
      data: {
        labels: CAT_LABELS,
        datasets: [{
          label: 'Avg %',
          data: CAT_SCORES,
          backgroundColor: 'rgba(124,58,237,0.55)',
          borderColor:     '#7c3aed',
          borderWidth: 1.5,
          borderRadius: 6,
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: {
            min: 0, max: 100,
            ticks: { callback: v => v + '%' },
            grid: { color: 'rgba(124,58,237,0.06)' }
          },
          y: { grid: { display: false } }
        }
      }
    });
  }
}

/* ============================
   DOWNLOAD FUNCTIONS
   ============================ */

// ── CSV ──
function downloadCSV() {
  const headers = ['#','Assessment','Category','Difficulty','Score','Total Marks','Percentage','Result','Duration','Submitted'];
  const rows = ATTEMPTS_DATA.map((a, i) => [
    i + 1, `"${a.assessment.replace(/"/g,'""')}"`, a.category, a.difficulty,
    a.score, a.total_marks, a.percentage + '%', a.result, a.duration, a.submitted_at
  ]);

  const meta = [
    ['Student Report — PREPAURA'],
    ['Student', STUDENT_NAME],
    ['Email', STUDENT_EMAIL],
    STUDENT_REG ? ['Reg No', STUDENT_REG] : null,
    STUDENT_DEPT ? ['Department', STUDENT_DEPT] : null,
    ['Total Attempts', TOTAL_ATTEMPTS],
    ['Average Score', AVG_SCORE !== null ? AVG_SCORE + '%' : '—'],
    ['Best Score', MAX_SCORE !== null ? MAX_SCORE + '%' : '—'],
    ['Pass Rate', PASS_RATE + '%'],
    ['Generated', new Date().toLocaleString()],
    [],
  ].filter(Boolean);

  const allRows = [...meta, headers, ...rows];
  const csv     = allRows.map(r => r.join(',')).join('\n');
  const blob    = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url     = URL.createObjectURL(blob);
  const a       = document.createElement('a');
  a.href        = url;
  a.download    = `report_${STUDENT_NAME.replace(/\s+/g,'_')}.csv`;
  a.click();
  URL.revokeObjectURL(url);
}

// ── Excel (XLSX via SheetJS CDN) ──
function downloadExcel() {
  const script = document.createElement('script');
  script.src   = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';
  script.onload = _buildExcel;
  document.head.appendChild(script);
}
function _buildExcel() {
  const wb = XLSX.utils.book_new();

  // Summary sheet
  const summaryData = [
    ['PREPAURA — Student Performance Report'],
    [],
    ['Student',         STUDENT_NAME],
    ['Email',           STUDENT_EMAIL],
    ['Reg No',          STUDENT_REG   || '—'],
    ['Department',      STUDENT_DEPT  || '—'],
    ['Total Attempts',  TOTAL_ATTEMPTS],
    ['Average Score',   AVG_SCORE !== null ? AVG_SCORE + '%' : '—'],
    ['Best Score',      MAX_SCORE !== null ? MAX_SCORE + '%' : '—'],
    ['Lowest Score',    MIN_SCORE !== null ? MIN_SCORE + '%' : '—'],
    ['Pass Rate',       PASS_RATE + '%'],
    ['Passed',          PASS_COUNT],
    ['Failed',          FAIL_COUNT],
    ['Report Date',     new Date().toLocaleString()],
  ];
  const wsSummary = XLSX.utils.aoa_to_sheet(summaryData);
  wsSummary['!cols'] = [{wch:20},{wch:40}];
  XLSX.utils.book_append_sheet(wb, wsSummary, 'Summary');

  // Attempts sheet
  const attRows = [
    ['#','Assessment','Category','Difficulty','Score','Total Marks','Percentage','Result','Duration','Submitted']
  ];
  ATTEMPTS_DATA.forEach((a, i) => {
    attRows.push([i+1, a.assessment, a.category, a.difficulty, a.score, a.total_marks, a.percentage, a.result, a.duration, a.submitted_at]);
  });
  const wsAttempts = XLSX.utils.aoa_to_sheet(attRows);
  wsAttempts['!cols'] = [{wch:4},{wch:35},{wch:18},{wch:12},{wch:8},{wch:12},{wch:12},{wch:8},{wch:10},{wch:22}];
  XLSX.utils.book_append_sheet(wb, wsAttempts, 'Attempts');

  // Category breakdown sheet
  const catRows = [['Category','Average Score (%)']];
  CAT_LABELS.forEach((c, i) => catRows.push([c, CAT_SCORES[i]]));
  const wsCat = XLSX.utils.aoa_to_sheet(catRows);
  wsCat['!cols'] = [{wch:25},{wch:20}];
  XLSX.utils.book_append_sheet(wb, wsCat, 'By Category');

  XLSX.writeFile(wb, `report_${STUDENT_NAME.replace(/\s+/g,'_')}.xlsx`);
}

/* ============================
   PDF GENERATION — SVG CHARTS
   ============================ */

function loadScript(src) {
  return new Promise((resolve, reject) => {
    if (document.querySelector(`script[src="${src}"]`)) { resolve(); return; }
    const s = document.createElement('script');
    s.src = src; s.onload = resolve; s.onerror = reject;
    document.head.appendChild(s);
  });
}

// ── Build inline SVG charts for the PDF template ──
function buildPDFCharts() {
  // 1. Score trend — line chart SVG
  buildTrendSVG();
  // 2. Score distribution — bar chart SVG
  buildDistSVG();
  // 3. Pass vs Fail — doughnut SVG
  buildPassFailSVG();
  // 4. Category — horizontal bar SVG
  buildCatSVG();
}

function svgEl(tag, attrs, children) {
  const ns  = 'http://www.w3.org/2000/svg';
  const el  = document.createElementNS(ns, tag);
  for (const [k, v] of Object.entries(attrs)) el.setAttribute(k, v);
  if (children) el.innerHTML = children;
  return el;
}

function buildTrendSVG() {
  const wrap = document.getElementById('pdf-trend-svg');
  if (!wrap) return;
  wrap.innerHTML = '';
  if (TREND_SCORES.length < 2) {
    wrap.innerHTML = '<p style="color:#8b7fa8;text-align:center;padding:40px 0;font-size:13px">Not enough data for trend</p>';
    return;
  }
  const W = 520, H = 180, padL = 48, padR = 16, padT = 12, padB = 36;
  const chartW = W - padL - padR, chartH = H - padT - padB;
  const n = TREND_SCORES.length;
  const xStep = chartW / Math.max(n - 1, 1);

  const points = TREND_SCORES.map((v, i) => {
    const x = padL + i * xStep;
    const y = padT + chartH - (v / 100) * chartH;
    return [x, y];
  });
  const polyline = points.map(p => p.join(',')).join(' ');
  // fill polygon
  const fillPts  = `${points[0][0]},${padT + chartH} ` + polyline + ` ${points[n-1][0]},${padT + chartH}`;

  const svg = svgEl('svg', { viewBox: `0 0 ${W} ${H}`, width: W, height: H });

  // grid lines
  [0, 25, 50, 75, 100].forEach(v => {
    const y = padT + chartH - (v / 100) * chartH;
    const line = svgEl('line', { x1: padL, y1: y, x2: W - padR, y2: y, stroke: '#ede9f6', 'stroke-width': '1' });
    svg.appendChild(line);
    const label = svgEl('text', { x: padL - 6, y: y + 4, 'text-anchor': 'end', 'font-size': '10', fill: '#8b7fa8' }, v + '%');
    svg.appendChild(label);
  });

  // fill area
  const fill = svgEl('polygon', { points: fillPts, fill: 'rgba(124,58,237,0.12)' });
  svg.appendChild(fill);

  // line
  const line2 = svgEl('polyline', { points: polyline, fill: 'none', stroke: '#7c3aed', 'stroke-width': '2.5', 'stroke-linejoin': 'round', 'stroke-linecap': 'round' });
  svg.appendChild(line2);

  // dots + x-labels
  points.forEach(([x, y], i) => {
    const dot = svgEl('circle', { cx: x, cy: y, r: '4', fill: '#7c3aed' });
    svg.appendChild(dot);
    if (i === 0 || i === n - 1 || n <= 8 || i % Math.ceil(n / 6) === 0) {
      const lbl = svgEl('text', { x, y: H - 4, 'text-anchor': 'middle', 'font-size': '10', fill: '#8b7fa8' }, TREND_LABELS[i]);
      svg.appendChild(lbl);
    }
  });

  wrap.appendChild(svg);
}

function buildDistSVG() {
  const wrap = document.getElementById('pdf-dist-svg');
  if (!wrap || !DIST_DATA.some(v => v > 0)) {
    if (wrap) wrap.innerHTML = '<p style="color:#8b7fa8;text-align:center;padding:40px 0;font-size:13px">No data</p>';
    return;
  }
  wrap.innerHTML = '';
  const W = 240, H = 160, padL = 24, padR = 12, padT = 12, padB = 30;
  const chartW = W - padL - padR, chartH = H - padT - padB;
  const labels  = ['0–25%','26–50%','51–75%','76–100%'];
  const colors  = ['#f43f5e','#f59e0b','#38bdf8','#10b981'];
  const maxVal  = Math.max(...DIST_DATA, 1);
  const barW    = chartW / 4 - 8;

  const svg = svgEl('svg', { viewBox: `0 0 ${W} ${H}`, width: W, height: H });

  DIST_DATA.forEach((v, i) => {
    const bH  = (v / maxVal) * chartH;
    const x   = padL + i * (chartW / 4) + 4;
    const y   = padT + chartH - bH;
    const rect = svgEl('rect', { x, y, width: barW, height: bH || 2, fill: colors[i], rx: '4' });
    svg.appendChild(rect);
    if (v > 0) {
      const val = svgEl('text', { x: x + barW / 2, y: y - 4, 'text-anchor': 'middle', 'font-size': '11', fill: colors[i], 'font-weight': '700' }, v);
      svg.appendChild(val);
    }
    const lbl = svgEl('text', { x: x + barW / 2, y: H - 6, 'text-anchor': 'middle', 'font-size': '9', fill: '#8b7fa8' }, labels[i]);
    svg.appendChild(lbl);
  });

  wrap.appendChild(svg);
}

function buildPassFailSVG() {
  const wrap = document.getElementById('pdf-passfail-svg');
  if (!wrap) return;
  wrap.innerHTML = '';
  const total = PASS_COUNT + FAIL_COUNT;
  if (total === 0) { wrap.innerHTML = '<p style="color:#8b7fa8;text-align:center;padding:30px 0;font-size:13px">No data</p>'; return; }

  const W = 220, H = 160, cx = 100, cy = 76, r = 62, inner = 42;
  const passAngle = (PASS_COUNT / total) * 2 * Math.PI;

  function polarToCartesian(angle, radius) {
    return [cx + radius * Math.cos(angle - Math.PI / 2), cy + radius * Math.sin(angle - Math.PI / 2)];
  }
  function arcPath(startAngle, endAngle, radius) {
    const [x1, y1] = polarToCartesian(startAngle, radius);
    const [x2, y2] = polarToCartesian(endAngle,   radius);
    const [ix1, iy1] = polarToCartesian(startAngle, inner);
    const [ix2, iy2] = polarToCartesian(endAngle,   inner);
    const large = (endAngle - startAngle) > Math.PI ? 1 : 0;
    return `M ${ix1} ${iy1} L ${x1} ${y1} A ${radius} ${radius} 0 ${large} 1 ${x2} ${y2} L ${ix2} ${iy2} A ${inner} ${inner} 0 ${large} 0 ${ix1} ${iy1} Z`;
  }

  const svg = svgEl('svg', { viewBox: `0 0 ${W} ${H}`, width: W, height: H });

  if (PASS_COUNT > 0 && PASS_COUNT < total) {
    const pPass = svgEl('path', { d: arcPath(0, passAngle, r), fill: '#10b981' });
    svg.appendChild(pPass);
    const pFail = svgEl('path', { d: arcPath(passAngle, 2 * Math.PI, r), fill: '#f43f5e' });
    svg.appendChild(pFail);
  } else {
    const color = PASS_COUNT === total ? '#10b981' : '#f43f5e';
    const circle = svgEl('circle', { cx, cy, r, fill: color });
    svg.appendChild(circle);
    const hole = svgEl('circle', { cx, cy, r: inner, fill: 'white' });
    svg.appendChild(hole);
  }

  const pct = Math.round(PASS_COUNT / total * 100);
  const label = svgEl('text', { x: cx, y: cy + 5, 'text-anchor': 'middle', 'font-size': '16', 'font-weight': '700', fill: '#1a1425' }, pct + '%');
  svg.appendChild(label);
  const sub = svgEl('text', { x: cx, y: cy + 18, 'text-anchor': 'middle', 'font-size': '9', fill: '#8b7fa8' }, 'pass rate');
  svg.appendChild(sub);

  // legend
  [[`Passed (${PASS_COUNT})`, '#10b981'], [`Failed (${FAIL_COUNT})`, '#f43f5e']].forEach(([text, color], i) => {
    const lx = cx + 32, ly = 130 + i * 18;
    svg.appendChild(svgEl('rect', { x: lx, y: ly - 8, width: 10, height: 10, fill: color, rx: '2' }));
    svg.appendChild(svgEl('text', { x: lx + 14, y: ly, 'font-size': '11', fill: '#4b4565' }, text));
  });

  wrap.appendChild(svg);
}

function buildCatSVG() {
  const wrap = document.getElementById('pdf-cat-svg');
  if (!wrap || CAT_LABELS.length === 0) {
    if (wrap) wrap.innerHTML = '<p style="color:#8b7fa8;text-align:center;padding:30px 0;font-size:13px">No category data</p>';
    return;
  }
  wrap.innerHTML = '';
  const rowH = 28, padL = 90, padR = 60, padT = 8, padB = 8;
  const W    = 340;
  const H    = padT + CAT_LABELS.length * rowH + padB;
  const chartW = W - padL - padR;

  const svg = svgEl('svg', { viewBox: `0 0 ${W} ${H}`, width: W, height: H });

  CAT_LABELS.forEach((cat, i) => {
    const y    = padT + i * rowH;
    const barH = 16;
    const bW   = (CAT_SCORES[i] / 100) * chartW;
    const barY = y + (rowH - barH) / 2;

    // label
    const lbl = svgEl('text', { x: padL - 8, y: barY + 11, 'text-anchor': 'end', 'font-size': '11', fill: '#4b4565' }, cat);
    svg.appendChild(lbl);
    // bg
    const bg = svgEl('rect', { x: padL, y: barY, width: chartW, height: barH, fill: '#ede9f6', rx: '4' });
    svg.appendChild(bg);
    // fill
    const fill = svgEl('rect', { x: padL, y: barY, width: bW, height: barH, fill: '#7c3aed', rx: '4', opacity: '0.75' });
    svg.appendChild(fill);
    // value
    const val = svgEl('text', { x: padL + chartW + 6, y: barY + 11, 'font-size': '11', fill: '#7c3aed', 'font-weight': '700' }, CAT_SCORES[i] + '%');
    svg.appendChild(val);
  });

  wrap.appendChild(svg);
}

// ── Main PDF download ──
function downloadPDF() {
  const btn = event.target.closest('button');
  const origText = btn.innerHTML;
  btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Generating…';
  btn.disabled  = true;

  Promise.all([
    loadScript('https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js'),
    loadScript('https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js')
  ]).then(() => {
    buildPDFCharts();
    // give SVGs a tick to paint
    setTimeout(() => _buildPDF(btn, origText), 120);
  }).catch(() => {
    btn.innerHTML = origText;
    btn.disabled  = false;
    alert('Failed to load PDF libraries. Please use Print instead.');
  });
}

async function _buildPDF(btn, origText) {
  try {
    const { jsPDF } = window.jspdf;
    const template  = document.getElementById('pdfTemplate');
    template.style.display = 'block';

    const canvas = await html2canvas(template, {
      scale:           2,
      useCORS:         true,
      backgroundColor: '#ffffff',
      logging:         false,
      width:           template.offsetWidth,
      windowWidth:     template.offsetWidth,
    });

    template.style.display = 'none';

    const imgData = canvas.toDataURL('image/jpeg', 0.95);
    const pdfW    = 210;
    const pdfH    = (canvas.height * pdfW) / canvas.width;
    const pdf     = new jsPDF({ orientation: 'p', unit: 'mm', format: 'a4' });
    const pageH   = pdf.internal.pageSize.getHeight();
    let   yOff    = 0;

    while (yOff < pdfH) {
      if (yOff > 0) pdf.addPage();
      pdf.addImage(imgData, 'JPEG', 0, -yOff, pdfW, pdfH);
      yOff += pageH;
    }

    pdf.save(`report_${STUDENT_NAME.replace(/\s+/g,'_')}.pdf`);
  } catch (err) {
    console.error(err);
    alert('PDF generation failed. Please use the Print button instead.');
  } finally {
    btn.innerHTML = origText;
    btn.disabled  = false;
  }
}
</script>

<!-- ═══════════════════════════════════════════
     HIDDEN PDF TEMPLATE — captured by html2canvas
     Fixed width, no sidebar, white background,
     all charts are inline SVG (no canvas)
     ═══════════════════════════════════════════ -->
<div id="pdfTemplate" style="display:none;position:fixed;top:0;left:0;width:900px;background:#ffffff;padding:0;font-family:Calibri,Segoe UI,Arial,sans-serif;font-size:14px;color:#1a1425;z-index:99999;overflow:visible">

  <!-- Header bar -->
  <div style="background:linear-gradient(135deg,#0d0a14 0%,#261d35 55%,#3d1f6e 100%);padding:28px 36px;display:flex;align-items:center;gap:20px">
    <div style="width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,#7c3aed,#c084fc);display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:700;color:white;flex-shrink:0;border:3px solid rgba(255,255,255,0.2)">
      <?= $studentInitials ?>
    </div>
    <div style="flex:1">
      <div style="font-size:22px;font-weight:800;color:white;margin-bottom:4px"><?= $studentName ?></div>
      <div style="display:flex;gap:16px;flex-wrap:wrap">
        <span style="font-size:12px;color:rgba(255,255,255,0.65)"><?= htmlspecialchars($student['email']) ?></span>
        <?php if (!empty($student['registration_number'])): ?>
        <span style="font-size:12px;color:rgba(255,255,255,0.65)"><?= htmlspecialchars($student['registration_number']) ?></span>
        <?php endif; ?>
        <?php if (!empty($student['department'])): ?>
        <span style="font-size:12px;color:rgba(255,255,255,0.65)"><?= htmlspecialchars($student['department']) ?></span>
        <?php endif; ?>
      </div>
      <?php if (!empty($studentGroups)): ?>
      <div style="margin-top:8px;display:flex;gap:6px">
        <?php foreach ($studentGroups as $g): ?>
        <span style="padding:2px 10px;border-radius:20px;background:rgba(124,58,237,0.35);border:1px solid rgba(124,58,237,0.5);color:#c084fc;font-size:11px;font-weight:600"><?= htmlspecialchars($g) ?></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <div style="text-align:right;flex-shrink:0">
      <div style="font-size:11px;color:rgba(255,255,255,0.45);margin-bottom:2px">PREPAURA</div>
      <div style="font-size:11px;color:rgba(255,255,255,0.45)">Performance Report</div>
      <div style="font-size:11px;color:rgba(255,255,255,0.35);margin-top:4px"><?= date('M j, Y') ?></div>
    </div>
  </div>

  <!-- Stat cards row -->
  <div style="display:flex;gap:0;border-bottom:2px solid #ede9f6">
    <?php
    $pdfTotal = (int)$stats['total_attempts'];
    $pdfPass  = (int)$stats['pass_count'];
    $pdfRate  = $pdfTotal > 0 ? round($pdfPass / $pdfTotal * 100) : 0;
    $statItems = [
      ['Total Attempts',  (int)$stats['total_attempts'],   '#7c3aed', (int)$stats['total_assessments'] . ' assessments'],
      ['Average Score',   $stats['avg_score'] !== null ? number_format((float)$stats['avg_score'],1).'%' : '—', $stats['avg_score'] !== null ? scoreColor((float)$stats['avg_score']) : '#8b7fa8', 'across all submissions'],
      ['Best Score',      $stats['max_score'] !== null ? number_format((float)$stats['max_score'],1).'%' : '—', '#10b981', $stats['min_score'] !== null ? 'Lowest: '.number_format((float)$stats['min_score'],1).'%' : 'No data'],
      ['Pass Rate',       $pdfRate . '%', '#f59e0b', $pdfPass . ' passed · ' . (int)$stats['fail_count'] . ' failed'],
    ];
    foreach ($statItems as $si):
    ?>
    <div style="flex:1;padding:20px 20px 16px;border-right:1px solid #ede9f6">
      <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#8b7fa8;margin-bottom:8px"><?= $si[0] ?></div>
      <div style="font-size:28px;font-weight:800;color:<?= $si[2] ?>;line-height:1"><?= $si[1] ?></div>
      <div style="font-size:11px;color:#8b7fa8;margin-top:5px"><?= $si[3] ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Charts row 1: Trend + Distribution -->
  <div style="display:flex;gap:0;border-bottom:1px solid #ede9f6">
    <!-- Score Trend -->
    <div style="flex:2;padding:20px 20px 16px;border-right:1px solid #ede9f6">
      <div style="font-size:13px;font-weight:700;color:#1a1425;margin-bottom:12px">📈 Score Trend Over Time</div>
      <div id="pdf-trend-svg"></div>
    </div>
    <!-- Distribution -->
    <div style="flex:1;padding:20px 20px 16px">
      <div style="font-size:13px;font-weight:700;color:#1a1425;margin-bottom:12px">📊 Score Distribution</div>
      <div id="pdf-dist-svg"></div>
    </div>
  </div>

  <!-- Charts row 2: Pass/Fail + Category -->
  <div style="display:flex;gap:0;border-bottom:1px solid #ede9f6">
    <!-- Pass vs Fail -->
    <div style="flex:1;padding:20px 20px 16px;border-right:1px solid #ede9f6">
      <div style="font-size:13px;font-weight:700;color:#1a1425;margin-bottom:12px">⬤ Pass vs Fail</div>
      <div id="pdf-passfail-svg"></div>
    </div>
    <!-- Category -->
    <div style="flex:1.5;padding:20px 20px 16px">
      <div style="font-size:13px;font-weight:700;color:#1a1425;margin-bottom:12px">▦ Avg Score by Category</div>
      <div id="pdf-cat-svg"></div>
    </div>
  </div>

  <!-- Attempts table -->
  <div style="padding:20px 24px 28px">
    <div style="font-size:13px;font-weight:700;color:#1a1425;margin-bottom:12px">☑ All Attempts</div>
    <table style="width:100%;border-collapse:collapse;font-size:12px">
      <thead>
        <tr style="background:#0d0a14">
          <th style="padding:10px 12px;text-align:left;color:rgba(255,255,255,0.7);font-size:10px;text-transform:uppercase;letter-spacing:0.06em;white-space:nowrap">#</th>
          <th style="padding:10px 12px;text-align:left;color:rgba(255,255,255,0.7);font-size:10px;text-transform:uppercase;letter-spacing:0.06em">Assessment</th>
          <th style="padding:10px 12px;text-align:left;color:rgba(255,255,255,0.7);font-size:10px;text-transform:uppercase;letter-spacing:0.06em">Category</th>
          <th style="padding:10px 12px;text-align:left;color:rgba(255,255,255,0.7);font-size:10px;text-transform:uppercase;letter-spacing:0.06em">Difficulty</th>
          <th style="padding:10px 12px;text-align:left;color:rgba(255,255,255,0.7);font-size:10px;text-transform:uppercase;letter-spacing:0.06em">Score</th>
          <th style="padding:10px 12px;text-align:left;color:rgba(255,255,255,0.7);font-size:10px;text-transform:uppercase;letter-spacing:0.06em">%</th>
          <th style="padding:10px 12px;text-align:left;color:rgba(255,255,255,0.7);font-size:10px;text-transform:uppercase;letter-spacing:0.06em">Result</th>
          <th style="padding:10px 12px;text-align:left;color:rgba(255,255,255,0.7);font-size:10px;text-transform:uppercase;letter-spacing:0.06em">Submitted</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($attempts as $idx => $a):
          $pct2    = (float)$a['percentage'];
          $passing2 = $a['total_marks'] > 0 ? ($a['passing_marks'] / $a['total_marks'] * 100) : 50;
          $passed2  = $pct2 >= $passing2;
          $sc       = scoreColor($pct2);
          $rowBg    = $idx % 2 === 0 ? '#ffffff' : '#faf9fd';
          $diffColors = ['easy'=>'#10b981','medium'=>'#f59e0b','hard'=>'#f43f5e'];
          $dc = $diffColors[$a['difficulty']] ?? '#8b7fa8';
        ?>
        <tr style="background:<?= $rowBg ?>;border-bottom:1px solid #ede9f6">
          <td style="padding:9px 12px;color:#8b7fa8"><?= $idx + 1 ?></td>
          <td style="padding:9px 12px;font-weight:600;color:#1a1425"><?= htmlspecialchars($a['title']) ?></td>
          <td style="padding:9px 12px;color:#4b4565"><?= htmlspecialchars($a['category'] ?: '—') ?></td>
          <td style="padding:9px 12px"><span style="padding:2px 8px;border-radius:20px;font-size:10px;font-weight:600;background:<?= $dc ?>1a;color:<?= $dc ?>"><?= ucfirst($a['difficulty']) ?></span></td>
          <td style="padding:9px 12px;font-weight:600"><?= number_format((float)$a['score'],1) ?> / <?= (int)$a['total_marks'] ?></td>
          <td style="padding:9px 12px"><span style="padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;background:<?= $sc ?>1a;color:<?= $sc ?>"><?= number_format($pct2,1) ?>%</span></td>
          <td style="padding:9px 12px">
            <?php if ($a['status'] === 'timeout'): ?>
            <span style="padding:2px 8px;border-radius:20px;font-size:10px;font-weight:600;background:#fef3c7;color:#f59e0b">Timeout</span>
            <?php elseif ($passed2): ?>
            <span style="padding:2px 8px;border-radius:20px;font-size:10px;font-weight:600;background:#d1fae5;color:#10b981">Pass</span>
            <?php else: ?>
            <span style="padding:2px 8px;border-radius:20px;font-size:10px;font-weight:600;background:#fee2e2;color:#f43f5e">Fail</span>
            <?php endif; ?>
          </td>
          <td style="padding:9px 12px;color:#8b7fa8;white-space:nowrap"><?= fmtDateTime($a['submitted_at']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Footer -->
  <div style="padding:14px 24px;background:#f7f5fb;border-top:1px solid #ede9f6;display:flex;justify-content:space-between;align-items:center">
    <span style="font-size:11px;color:#8b7fa8">PREPAURA — Placement Training &amp; Assessment Platform</span>
    <span style="font-size:11px;color:#8b7fa8">Report generated: <?= date('F j, Y g:i A') ?></span>
  </div>

</div><!-- /pdfTemplate -->

</body>
</html>
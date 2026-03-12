<?php
/* ========================================
 * TEACHER DASHBOARD
 * File: teacher-dashboard.php
 * ======================================== */
require 'config.php';
require_once 'db-guard.php';
// ── Session guard ──
$currentUser = validateSession($conn, 'teacher');
$teacherId   = (int) $currentUser['user_id'];
$userName    = htmlspecialchars($currentUser['full_name'] ?? 'Teacher');
$userEmail   = htmlspecialchars($currentUser['email'] ?? '');
$userInitials = strtoupper(substr($currentUser['full_name'] ?? 'T', 0, 2));
// ============================================================
// DATABASE QUERIES
// ============================================================
$dbError = false;
// ── 1. Stats: total assessments this teacher created ──
$totalAssessments = 0;
$newThisMonth     = 0;
$r = safePreparedQuery($conn,
"SELECT
COUNT(*) AS total,
SUM(CASE WHEN MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) THEN 1 ELSE 0 END) AS new_this_month
FROM assessments
WHERE created_by = ?",
"i", [$teacherId]
);
if ($r['success'] && $r['result']) {
$row = $r['result']->fetch_assoc();
$totalAssessments = (int)($row['total'] ?? 0);
$newThisMonth     = (int)($row['new_this_month'] ?? 0);
$r['result']->free();
} else {
$dbError = true;
}
// ── 2. Stats: distinct students who attempted this teacher's assessments ──
$activeStudents  = 0;
$newStudentsWeek = 0;
$r2 = safePreparedQuery($conn,
"SELECT
COUNT(DISTINCT aa.user_id) AS total_students,
COUNT(DISTINCT CASE WHEN aa.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN aa.user_id END) AS new_this_week
FROM assessment_attempts aa
JOIN assessments a ON aa.assessment_id = a.assessment_id
WHERE a.created_by = ?
AND aa.user_id IS NOT NULL",
"i", [$teacherId]
);
if ($r2['success'] && $r2['result']) {
$row = $r2['result']->fetch_assoc();
$activeStudents  = (int)($row['total_students'] ?? 0);
$newStudentsWeek = (int)($row['new_this_week'] ?? 0);
$r2['result']->free();
} else {
$dbError = true;
}
// ── 3. Unread notifications count ──
$unreadCount = 0;
$r3 = safePreparedQuery($conn,
"SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0",
"i", [$teacherId]
);
if ($r3['success'] && $r3['result']) {
$row = $r3['result']->fetch_assoc();
$unreadCount = (int)($row['cnt'] ?? 0);
$r3['result']->free();
}
// ── 4. Assessments list with question count and attempt count ──
$assessments = [];
$r4 = safePreparedQuery($conn,
"SELECT
        a.assessment_id,
        a.title,
        a.category,
        a.difficulty,
        a.status,
        a.duration_minutes,
        a.total_marks,
        a.passing_marks,
        a.start_time,
        a.end_time,
        a.created_at,
        a.updated_at,
COUNT(DISTINCT q.question_id)            AS question_count,
COUNT(DISTINCT aa.attempt_id)            AS attempt_count,
COUNT(DISTINCT aa.user_id)               AS student_count
FROM assessments a
LEFT JOIN questions q
ON q.assessment_id = a.assessment_id
LEFT JOIN assessment_attempts aa
ON aa.assessment_id = a.assessment_id
AND aa.status IN ('submitted','timeout')
WHERE a.created_by = ?
GROUP BY a.assessment_id, a.title, a.category, a.difficulty, a.status, a.duration_minutes, a.total_marks, a.passing_marks, a.start_time, a.end_time, a.created_at, a.updated_at
ORDER BY
        FIELD(a.status, 'active', 'draft', 'archived') ASC,
        a.updated_at DESC",
"i", [$teacherId]
);
if ($r4['success'] && $r4['result']) {
while ($row = $r4['result']->fetch_assoc()) {
$assessments[] = $row;
    }
$r4['result']->free();
} else {
$dbError = true;
}
// ── 5. Recent unread notifications (up to 5) ──
$notifications = [];
$r5 = safePreparedQuery($conn,
"SELECT notification_id, title, message, type, created_at
FROM notifications
WHERE user_id = ? AND is_read = 0
ORDER BY created_at DESC
LIMIT 5",
"i", [$teacherId]
);
if ($r5['success'] && $r5['result']) {
while ($row = $r5['result']->fetch_assoc()) {
$notifications[] = $row;
    }
$r5['result']->free();
}
// ── Helper: format date for display ──
function fmtDate(?string $dt): string {
if (!$dt) return '—';
return date('M j, Y', strtotime($dt));
}
// ── Helper: status label map ──
function statusLabel(string $status): string {
return match($status) {
'active' => 'Active',
// no 'scheduled' status in schema
'draft'    => 'Draft',
'archived' => 'Completed',
default    => ucfirst($status),
    };
}
// ── Helper: notification type icon ──
function notifIcon(string $type): string {
return match($type) {
'success'    => '✅',
'warning'    => '⚠️',
'error'      => '❌',
'assessment' => '📝',
'result'     => '📊',
'material'   => '📚',
default      => 'ℹ️',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Teacher Dashboard - PREPAURA</title>
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
overflow-x: hidden;
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
.brand-logo-img {
width: 44px; height: 44px;
border-radius: 10px;
object-fit: contain;
flex-shrink: 0;
        }
.brand-text-group { display: flex; flex-direction: column; line-height: 1; }
.brand-name { font-size: 19px; font-weight: 800; color: white; letter-spacing: 1px; }
.brand-tagline { font-size: 10.5px; font-weight: 400; color: rgba(255,255,255,0.65); letter-spacing: 0.3px; margin-top: 3px; }
.nav-search {
flex: 1; max-width: 500px; margin: 0 30px; position: relative;
        }
.search-input {
width: 100%; padding: 10px 40px 10px 15px;
border: 2px solid #e2e8f0; border-radius: 10px;
font-size: 14px; transition: var(--transition);
font-family: var(--font-family);
        }
.search-input:focus { outline: none; border-color: var(--color-teacher-secondary); }
.search-icon {
position: absolute; right: 15px; top: 50%; transform: translateY(-50%);
color: #a0aec0; font-size: 16px;
        }
.nav-profile { display: flex; align-items: center; gap: 15px; }
.notification-btn {
position: relative; width: 40px; height: 40px;
background: rgba(255,255,255,0.1); border: none; border-radius: 10px;
display: flex; align-items: center; justify-content: center;
cursor: pointer; transition: var(--transition); font-size: 18px;
color: white;
        }
.notification-btn:hover { background: rgba(255,255,255,0.2); }
.notif-badge {
position: absolute; top: -4px; right: -4px;
background: #ff6b6b; color: white;
width: 18px; height: 18px; border-radius: 50%;
font-size: 10px; font-weight: 700;
display: flex; align-items: center; justify-content: center;
        }
.profile-button {
display: flex; align-items: center; gap: 10px;
padding: 8px 14px; background: rgba(255,255,255,0.1);
border: none; border-radius: 10px; cursor: pointer;
transition: var(--transition); position: relative;
        }
.profile-button:hover { background: rgba(255,255,255,0.2); }
.profile-avatar {
width: 34px; height: 34px;
background: linear-gradient(135deg, var(--color-teacher-primary), var(--color-teacher-secondary));
border: 2px solid rgba(255,255,255,0.4);
border-radius: 50%;
display: flex; align-items: center; justify-content: center;
color: white; font-weight: 700; font-size: 13px;
        }
.profile-name { font-weight: 600; font-size: 14px; color: white; }
.profile-caret { color: rgba(255,255,255,0.6); font-size: 10px; }
.profile-dropdown {
position: absolute; top: calc(100% + 12px); right: 0;
background: white; border-radius: var(--radius);
box-shadow: var(--shadow-lg); min-width: 220px;
opacity: 0; visibility: hidden; transform: translateY(-8px);
transition: var(--transition); z-index: 1001;
        }
.profile-dropdown.open { opacity: 1; visibility: visible; transform: translateY(0); }
.dropdown-header {
padding: 16px 20px; border-bottom: 1px solid var(--color-border);
        }
.dropdown-name  { font-weight: 700; font-size: 14px; color: var(--color-text); }
.dropdown-email { font-size: 12px; color: var(--color-text-light); margin-top: 2px; }
.dropdown-role  {
display: inline-block; margin-top: 6px;
padding: 2px 10px;
background: linear-gradient(135deg, var(--color-teacher-primary), var(--color-teacher-secondary));
color: white; border-radius: 20px; font-size: 11px; font-weight: 600;
        }
.dropdown-menu { padding: 6px 0; }
.dropdown-item {
display: flex; align-items: center; gap: 12px;
padding: 11px 20px; color: var(--color-text);
text-decoration: none; font-size: 14px; transition: var(--transition);
cursor: pointer; border: none; background: none; width: 100%; text-align: left;
        }
.dropdown-item:hover { background: var(--color-bg-light); }
.dropdown-item.danger { color: var(--color-error); }
.dropdown-item.danger:hover { background: #fff5f5; }
.dropdown-divider { height: 1px; background: var(--color-border); margin: 4px 0; }
.notif-panel {
position: absolute; top: calc(100% + 12px); right: 60px;
background: white; border-radius: var(--radius);
box-shadow: var(--shadow-lg); width: 340px;
opacity: 0; visibility: hidden; transform: translateY(-8px);
transition: var(--transition); z-index: 1001;
        }
.notif-panel.open { opacity: 1; visibility: visible; transform: translateY(0); }
.notif-panel-header {
padding: 16px 20px; border-bottom: 1px solid var(--color-border);
font-weight: 700; font-size: 15px; display: flex; justify-content: space-between; align-items: center;
        }
.notif-mark-all {
font-size: 12px; font-weight: 600; color: var(--color-teacher-secondary);
cursor: pointer; border: none; background: none;
        }
.notif-item {
padding: 14px 20px; border-bottom: 1px solid var(--color-border);
display: flex; gap: 12px; align-items: flex-start;
text-decoration: none; color: var(--color-text);
transition: var(--transition);
        }
.notif-item:hover { background: var(--color-bg-light); }
.notif-icon { font-size: 20px; flex-shrink: 0; margin-top: 2px; }
.notif-title { font-size: 13px; font-weight: 600; margin-bottom: 2px; }
.notif-msg   { font-size: 12px; color: var(--color-text-light); line-height: 1.4; }
.notif-time  { font-size: 11px; color: #a0aec0; margin-top: 4px; }
.notif-empty { padding: 30px 20px; text-align: center; color: var(--color-text-light); font-size: 14px; }
/* ── CONTAINER ── */
.container { max-width: 1400px; margin: 0 auto; padding: 30px 20px; }
/* ── DB ERROR BANNER ── */
.db-error-banner {
background: #fff5f5; border: 2px solid var(--color-error);
border-radius: var(--radius); padding: 16px 20px; margin-bottom: 24px;
display: flex; align-items: center; gap: 12px;
color: #c53030; font-weight: 600; font-size: 14px;
        }
/* ── WELCOME ── */
.welcome-section { margin-bottom: 28px; }
.welcome-title { font-size: 28px; font-weight: 700; color: var(--color-text); margin-bottom: 6px; }
.welcome-subtitle { font-size: 15px; color: var(--color-text-light); }
/* ── STATS ── */
.stats-grid {
display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
gap: 20px; margin-bottom: 28px;
        }
.stat-card {
background: white; border-radius: var(--radius); padding: 24px;
box-shadow: var(--shadow-sm); border-left: 4px solid var(--color-teacher-secondary);
transition: var(--transition);
        }
.stat-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); }
.stat-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
.stat-label { font-size: 12px; font-weight: 600; color: var(--color-text-light); text-transform: uppercase; letter-spacing: 0.5px; }
.stat-icon {
width: 38px; height: 38px; border-radius: 10px; font-size: 18px;
background: linear-gradient(135deg, var(--color-teacher-primary), var(--color-teacher-secondary));
display: flex; align-items: center; justify-content: center; color: white;
        }
.stat-value { font-size: 34px; font-weight: 700; color: var(--color-text); margin-bottom: 4px; }
.stat-change { font-size: 13px; color: var(--color-success); font-weight: 600; }
.stat-change.none { color: var(--color-text-light); font-weight: normal; }
/* ── SECTION HEADER ── */
.section-header {
display: flex; align-items: center; justify-content: space-between;
margin-bottom: 20px; flex-wrap: wrap; gap: 12px;
        }
.section-title { font-size: 22px; font-weight: 700; color: var(--color-text); }
.filter-tabs { display: flex; gap: 8px; flex-wrap: wrap; }
.filter-tab {
padding: 7px 16px; background: white; border: 2px solid var(--color-border);
border-radius: 8px; font-size: 13px; font-weight: 600;
color: var(--color-text-light); cursor: pointer; transition: var(--transition);
        }
.filter-tab:hover { border-color: var(--color-teacher-secondary); color: var(--color-teacher-secondary); }
.filter-tab.active {
background: linear-gradient(135deg, var(--color-teacher-primary), var(--color-teacher-secondary));
border-color: transparent; color: white;
        }
/* ── EMPTY STATE ── */
.empty-state {
background: white; border-radius: var(--radius-lg); padding: 60px 30px;
text-align: center; box-shadow: var(--shadow-sm);
        }
.empty-icon { font-size: 64px; margin-bottom: 16px; opacity: 0.4; }
.empty-title { font-size: 20px; font-weight: 700; color: var(--color-text); margin-bottom: 8px; }
.empty-subtitle { font-size: 15px; color: var(--color-text-light); margin-bottom: 24px; }
.btn-create {
display: inline-block; padding: 12px 28px;
background: linear-gradient(135deg, var(--color-teacher-primary), var(--color-teacher-secondary));
color: white; border-radius: var(--radius); font-weight: 700;
text-decoration: none; transition: var(--transition);
        }
.btn-create:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(46,7,63,0.3); }
/* ── ASSESSMENT CARDS GRID ── */
.assessments-grid {
display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
gap: 24px; margin-bottom: 30px;
        }
.assessment-card {
background: white; border-radius: var(--radius); padding: 24px;
box-shadow: var(--shadow-sm); transition: var(--transition);
position: relative; overflow: hidden;
        }
.assessment-card::before {
content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
background: linear-gradient(90deg, var(--color-teacher-primary), var(--color-teacher-secondary));
        }
.assessment-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); }
.assessment-card.hidden { display: none; }
.assessment-title {
font-size: 17px; font-weight: 700; color: var(--color-text);
margin-bottom: 8px; line-height: 1.3;
        }
.assessment-category {
display: inline-block; padding: 3px 10px;
background: #f0f9ff; color: #0284c7;
border-radius: 5px; font-size: 12px; font-weight: 600; margin-bottom: 14px;
        }
.assessment-meta {
display: flex; flex-direction: column; gap: 8px;
margin-bottom: 14px; padding: 12px;
background: var(--color-bg-light); border-radius: 8px;
        }
.meta-item {
display: flex; align-items: center; gap: 8px;
font-size: 13px; color: var(--color-text-light);
        }
.meta-icon { font-size: 14px; width: 18px; text-align: center; flex-shrink: 0; }
.status-badge {
display: inline-flex; align-items: center; gap: 5px;
padding: 5px 12px; border-radius: 6px;
font-size: 12px; font-weight: 600; margin-bottom: 14px;
        }
.status-badge.active    { background: #d1fae5; color: #065f46; }
.status-badge.draft        { background: #fef3c7; color: #92400e; }
.status-badge.archived     { background: #dbeafe; color: #1e40af; }
.assessment-actions { display: flex; gap: 8px; }
.btn {
padding: 9px 14px; border: none; border-radius: 8px;
font-size: 13px; font-weight: 600; cursor: pointer;
transition: var(--transition); flex: 1; text-align: center;
text-decoration: none; display: inline-flex; align-items: center; justify-content: center;
        }
.btn-primary {
background: linear-gradient(135deg, var(--color-teacher-primary), var(--color-teacher-secondary));
color: white;
        }
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 3px 10px rgba(46,7,63,0.3); }
.btn-secondary { background: var(--color-bg-light); color: var(--color-text); }
.btn-secondary:hover { background: var(--color-border); }
.btn-danger { background: #fee2e2; color: #dc2626; }
.btn-danger:hover { background: #fecaca; }
/* ── FAB ── */
.fab-container { position: fixed; bottom: 30px; right: 30px; z-index: 999; }
.fab-button {
width: 58px; height: 58px; border-radius: 50%;
background: linear-gradient(135deg, var(--color-teacher-primary), var(--color-teacher-secondary));
color: white; border: none; font-size: 26px;
cursor: pointer; box-shadow: var(--shadow-lg);
transition: var(--transition);
display: flex; align-items: center; justify-content: center;
text-decoration: none;
        }
.fab-button:hover { transform: scale(1.1) rotate(90deg); box-shadow: 0 8px 25px rgba(46,7,63,0.4); }
/* ── DELETE CONFIRM MODAL ── */
.modal-overlay {
display: none; position: fixed; inset: 0;
background: rgba(0,0,0,0.5); z-index: 2000;
align-items: center; justify-content: center;
        }
.modal-overlay.open { display: flex; }
.modal {
background: white; border-radius: var(--radius-lg); padding: 30px;
width: 90%; max-width: 440px; box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
.modal-title { font-size: 20px; font-weight: 700; margin-bottom: 12px; color: var(--color-text); }
.modal-body  { font-size: 14px; color: var(--color-text-light); margin-bottom: 24px; line-height: 1.6; }
.modal-actions { display: flex; gap: 12px; justify-content: flex-end; }
.btn-cancel {
padding: 10px 22px; background: var(--color-bg-light); color: var(--color-text);
border: 2px solid var(--color-border); border-radius: var(--radius);
font-weight: 600; cursor: pointer; transition: var(--transition);
        }
.btn-cancel:hover { border-color: var(--color-error); color: var(--color-error); }
.btn-confirm-delete {
padding: 10px 22px; background: var(--color-error); color: white;
border: none; border-radius: var(--radius);
font-weight: 700; cursor: pointer; transition: var(--transition);
        }
.btn-confirm-delete:hover { background: #c53030; }
.btn-confirm-delete:disabled { opacity: 0.6; cursor: not-allowed; }
/* ── RESPONSIVE ── */
@media (max-width: 768px) {
            .container { padding: 20px 15px; }
.nav-search { display: none; }
.navbar { padding: 10px 15px; }
.stats-grid { grid-template-columns: 1fr 1fr; }
.assessments-grid { grid-template-columns: 1fr; }
.section-header { flex-direction: column; align-items: flex-start; }
.notif-panel { right: 10px; width: calc(100vw - 20px); }
        }
@media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
.welcome-title { font-size: 22px; }
        }
</style>
</head>
<body>
<!-- ── NAVIGATION ── -->
<nav class="navbar">
<a href="teacher-dashboard.php" class="navbar-brand">
<img src="data:image/png;base64,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" alt="PREPAURA Logo" class="brand-logo-img">
<div class="brand-text-group">
<span class="brand-name">PREPAURA</span>
<span class="brand-tagline">Placement Training Platform</span>
</div>
</a>
<div class="nav-search">
<input type="text" class="search-input" id="searchInput" placeholder="Search assessments..." autocomplete="off">
<span class="search-icon">🔍</span>
</div>
<div class="nav-profile" style="position:relative;">
<!-- Notification bell -->
<button class="notification-btn" id="notifBtn" title="Notifications">
            🔔
<?php if ($unreadCount > 0): ?>
<span class="notif-badge"><?= $unreadCount > 9 ? '9+' : $unreadCount ?></span>
<?php endif; ?>
</button>
<!-- Notification panel -->
<div class="notif-panel" id="notifPanel">
<div class="notif-panel-header">
<span>Notifications</span>
<?php if (!empty($notifications)): ?>
<button class="notif-mark-all" onclick="markAllRead()">Mark all read</button>
<?php endif; ?>
</div>
<?php if (empty($notifications)): ?>
<div class="notif-empty">🎉 You're all caught up!</div>
<?php else: ?>
<?php foreach ($notifications as $n): ?>
<a class="notif-item"
href="<?= htmlspecialchars('#') ?>"
data-notif-id="<?= (int)$n['notification_id'] ?>">
<div class="notif-icon"><?= notifIcon($n['type']) ?></div>
<div>
<div class="notif-title"><?= htmlspecialchars($n['title']) ?></div>
<?php if ($n['message']): ?>
<div class="notif-msg"><?= htmlspecialchars(mb_strimwidth($n['message'], 0, 80, '…')) ?></div>
<?php endif; ?>
<div class="notif-time"><?= fmtDate($n['created_at']) ?></div>
</div>
</a>
<?php endforeach; ?>
<?php endif; ?>
</div>
<!-- Profile button + dropdown -->
<button class="profile-button" id="profileBtn">
<div class="profile-avatar"><?= $userInitials ?></div>
<span class="profile-name"><?= $userName ?></span>
<span class="profile-caret">▼</span>
<div class="profile-dropdown" id="profileDropdown">
<div class="dropdown-header">
<div class="dropdown-name"><?= $userName ?></div>
<div class="dropdown-email"><?= $userEmail ?></div>
<span class="dropdown-role">Teacher</span>
</div>
<div class="dropdown-menu">
<a href="teacher-profile.php" class="dropdown-item">👤 My Profile</a>
<a href="teacher-dashboard.php" class="dropdown-item">📊 Dashboard</a>
<a href="help.html" target="_blank" rel="noopener" class="dropdown-item">❓ Help & Support</a>
<div class="dropdown-divider"></div>
<a onclick="handleLogout()" class="dropdown-item">🚪 Logout</a>
</div>
</div>
</button>
</div>
</nav>
<!-- ── MAIN ── -->
<div class="container">
<?php if ($dbError): ?>
<div class="db-error-banner">
        ⚠️ Some data could not be loaded. Please report this to your administrator.
</div>
<?php endif; ?>
<!-- Welcome -->
<div class="welcome-section">
<h1 class="welcome-title">Welcome back, <?= $userName ?>! 👋</h1>
<p class="welcome-subtitle">Here's what's happening with your assessments today.</p>
</div>
<!-- Stats -->
<div class="stats-grid">
<div class="stat-card">
<div class="stat-header">
<span class="stat-label">Total Assessments</span>
<div class="stat-icon">📝</div>
</div>
<div class="stat-value"><?= $totalAssessments ?></div>
<?php if ($newThisMonth > 0): ?>
<div class="stat-change">↑ <?= $newThisMonth ?> new this month</div>
<?php else: ?>
<div class="stat-change none">No new assessments this month</div>
<?php endif; ?>
</div>
<div class="stat-card">
<div class="stat-header">
<span class="stat-label">Students Attempted</span>
<div class="stat-icon">👥</div>
</div>
<div class="stat-value"><?= $activeStudents ?></div>
<?php if ($newStudentsWeek > 0): ?>
<div class="stat-change">↑ <?= $newStudentsWeek ?> this week</div>
<?php else: ?>
<div class="stat-change none">No new attempts this week</div>
<?php endif; ?>
</div>
</div>
<!-- Assessments section -->
<div class="section-header">
<h2 class="section-title">My Assessments</h2>
<div class="filter-tabs" role="tablist">
<button class="filter-tab active" data-filter="all"      role="tab">All</button>
<button class="filter-tab"         data-filter="active"   role="tab">Active</button>
<button class="filter-tab"         data-filter="draft"    role="tab">Draft</button>
<button class="filter-tab"         data-filter="archived" role="tab">Completed</button>
</div>
</div>
<?php if (empty($assessments)): ?>
<div class="empty-state">
<div class="empty-icon">📋</div>
<div class="empty-title">No assessments yet</div>
<div class="empty-subtitle">Create your first assessment to get started.</div>
<a href="create-assessment.php" class="btn-create">+ Create Assessment</a>
</div>
<?php else: ?>
<div class="assessments-grid" id="assessmentsGrid">
<?php foreach ($assessments as $a):
$aid      = (int)$a['assessment_id'];
$status   = $a['status'];
$qCount   = (int)$a['question_count'];
$attempts = (int)$a['attempt_count'];
$students = (int)$a['student_count'];
if ($status === 'active' && $a['end_time']) {
    $dateLabel = 'Due: ' . fmtDate($a['end_time']);
} elseif ($status === 'archived') {
    $dateLabel = 'Completed: ' . fmtDate($a['updated_at']);
} else {
    $dateLabel = 'Created: ' . fmtDate($a['created_at']);
}
?>
<div class="assessment-card" data-status="<?= htmlspecialchars($status) ?>" data-id="<?= $aid ?>">
<h3 class="assessment-title"><?= htmlspecialchars($a['title']) ?></h3>
<span class="assessment-category"><?= htmlspecialchars(ucfirst($a['category'] ?? 'General')) ?></span>
<div class="assessment-meta">
<div class="meta-item">
<span class="meta-icon">📅</span>
<span><?= $dateLabel ?></span>
</div>
<div class="meta-item">
<span class="meta-icon">⏱️</span>
<span><?= (int)$a['duration_minutes'] ?> minutes</span>
</div>
<div class="meta-item">
<span class="meta-icon">📝</span>
<span><?= $qCount ?> question<?= $qCount !== 1 ? 's' : '' ?></span>
</div>
<div class="meta-item">
<span class="meta-icon">👥</span>
<?php if ($status === 'draft'): ?>
<span>Not published yet</span>
<?php elseif ($attempts === 0): ?>
<span>No attempts yet</span>
<?php else: ?>
<span><?= $students ?> student<?= $students !== 1 ? 's' : '' ?> · <?= $attempts ?> attempt<?= $attempts !== 1 ? 's' : '' ?></span>
<?php endif; ?>
</div>
</div>
<span class="status-badge <?= $status ?>">
                    ● <?= statusLabel($status) ?>
</span>
<div class="assessment-actions">
<?php if ($status === 'draft'): ?>
<a href="create-assessment.php?edit=<?= $aid ?>" class="btn btn-primary">Continue Editing</a>
<button class="btn btn-danger" onclick="confirmDelete(<?= $aid ?>, '<?= htmlspecialchars(addslashes($a['title'])) ?>')">Delete</button>
<?php elseif ($status === 'active' || $status === 'archived'): ?>
<a href="assessment-results.php?id=<?= $aid ?>" class="btn btn-primary">View Results</a>
<a href="edit-assessment.php?id=<?= $aid ?>" class="btn btn-secondary">Edit</a>
<?php else: ?>
<a href="edit-assessment.php?id=<?= $aid ?>" class="btn btn-secondary">Edit</a>
<?php endif; ?>
</div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div><!-- /container -->
<!-- FAB -->
<div class="fab-container">
<a href="create-assessment.php" class="fab-button" title="Create New Assessment">+</a>
</div>
<!-- Delete Confirm Modal -->
<div class="modal-overlay" id="deleteModal">
<div class="modal">
<div class="modal-title">Delete Assessment?</div>
<div class="modal-body" id="deleteModalBody">
            This will permanently delete the assessment and all associated questions and student attempts. This cannot be undone.
</div>
<div class="modal-actions">
<button class="btn-cancel" onclick="closeDeleteModal()">Cancel</button>
<button class="btn-confirm-delete" id="confirmDeleteBtn">Delete</button>
</div>
</div>
</div>
<script>
// ── CSRF token — fetched once on page load, reused for all POST requests ──
let csrfToken = null;
async function getCsrfToken() {
    if (csrfToken) return csrfToken;
    const res  = await fetch('api/csrf-token.php', { credentials: 'same-origin' });
    const data = await res.json();
    if (!data.success) throw new Error('Could not fetch CSRF token.');
    csrfToken = data.token;
    return csrfToken;
}

// ── Panel toggles ──
const profileBtn  = document.getElementById('profileBtn');
const profileDrop = document.getElementById('profileDropdown');
const notifBtn    = document.getElementById('notifBtn');
const notifPanel  = document.getElementById('notifPanel');
profileBtn.addEventListener('click', e => {
    e.stopPropagation();
    profileDrop.classList.toggle('open');
    notifPanel.classList.remove('open');
});
notifBtn.addEventListener('click', e => {
    e.stopPropagation();
    notifPanel.classList.toggle('open');
    profileDrop.classList.remove('open');
});
document.addEventListener('click', () => {
    profileDrop.classList.remove('open');
    notifPanel.classList.remove('open');
});

// ── Logout ──
function handleLogout() {
    if (confirm('Are you sure you want to logout?')) {
        window.location.href = 'logout.php';
    }
}

// ── Filter tabs ──
document.querySelectorAll('.filter-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        const filter = tab.dataset.filter;
        document.querySelectorAll('.assessment-card').forEach(card => {
            const match = filter === 'all' || card.dataset.status === filter;
            card.classList.toggle('hidden', !match);
        });
    });
});

// ── Search ──
document.getElementById('searchInput')?.addEventListener('input', function() {
    const q = this.value.toLowerCase().trim();
    document.querySelectorAll('.assessment-card').forEach(card => {
        const title    = card.querySelector('.assessment-title')?.textContent?.toLowerCase() ?? '';
        const category = card.querySelector('.assessment-category')?.textContent?.toLowerCase() ?? '';
        card.classList.toggle('hidden', q !== '' && !title.includes(q) && !category.includes(q));
    });
    if (q) {
        document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
    }
});

// ── Delete modal ──
let deleteTargetId = null;
function confirmDelete(id, title) {
    deleteTargetId = id;
    document.getElementById('deleteModalBody').textContent =
        `Are you sure you want to delete "${title}"? This will permanently remove all questions and student attempts. This cannot be undone.`;
    document.getElementById('deleteModal').classList.add('open');
}
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('open');
    deleteTargetId = null;
}
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});

document.getElementById('confirmDeleteBtn').addEventListener('click', async function() {
    if (!deleteTargetId) return;
    this.disabled    = true;
    this.textContent = 'Deleting…';
    try {
        const token = await getCsrfToken();
        const res   = await fetch('api/assessment/delete.php', {
            method:      'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': token,
            },
            body: JSON.stringify({ assessment_id: deleteTargetId }),
        });
        const data = await res.json();
        if (data.success) {
            document.querySelector(`.assessment-card[data-id="${deleteTargetId}"]`)?.remove();
            closeDeleteModal();
            if (!document.querySelector('.assessment-card')) location.reload();
        } else {
            alert(data.error || 'Delete failed. Please try again.');
            this.disabled    = false;
            this.textContent = 'Delete';
        }
    } catch (err) {
        alert('Network error. Please try again.');
        this.disabled    = false;
        this.textContent = 'Delete';
    }
});

// ── Mark all notifications read ──
async function markAllRead() {
    try {
        const token = await getCsrfToken();
        await fetch('api/notifications/mark-read.php', {
            method:      'POST',
            credentials: 'same-origin',
            headers:     { 'X-CSRF-Token': token },
        });
        document.getElementById('notifPanel').innerHTML =
            '<div class="notif-panel-header"><span>Notifications</span></div><div class="notif-empty">🎉 You\'re all caught up!</div>';
        document.querySelector('.notif-badge')?.remove();
    } catch(e) { /* silent fail */ }
}

// ── Keyboard shortcuts ──
document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
        e.preventDefault();
        window.location.href = 'create-assessment.php';
    }
});
</script>
</body>
</html>
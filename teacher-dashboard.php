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

// Fetch profile_image (validateSession may not include it)
$picStmt = $conn->prepare("SELECT profile_image FROM users WHERE user_id = ?");
$picStmt->bind_param("i", $teacherId);
$picStmt->execute();
$picRow      = $picStmt->get_result()->fetch_assoc();
$userPicture = $picRow['profile_image'] ?? '';
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

// ── Helper: format date for display ──
function fmtDate(?string $dt): string {
if (!$dt) return '—';
return date('M j, Y', strtotime($dt));
}
// ── Helper: status label map ──
function statusLabel(string $status): string {
return match($status) {
'active' => 'Active',
'draft'    => 'Draft',
'archived' => 'Completed',
default    => ucfirst($status),
    };
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Teacher Dashboard — PREPAURA</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">
<style>
/* ── DESIGN TOKENS ── */
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
  --shadow-vl:   0 0 0 1px var(--border), 0 4px 24px rgba(124,58,237,0.1);
  --r-sm:        8px;
  --r-md:        14px;
  --r-lg:        20px;
  --r-xl:        28px;
  --ease:        cubic-bezier(0.22,1,0.36,1);
  --t:           0.22s var(--ease);
  --font-head:   'Syne', system-ui, sans-serif;
  --font-body:   'DM Sans', system-ui, sans-serif;
  --nav-h:       64px;
}

/* ── BASE ── */
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
html { -webkit-font-smoothing: antialiased; scroll-behavior: smooth; }

body {
  font-family: var(--font-body);
  background: var(--surface);
  color: var(--text-1);
  min-height: 100vh;
  padding-top: var(--nav-h);
  overflow-x: hidden;
}

/* ── NOISE TEXTURE OVERLAY ── */
body::before {
  content: '';
  position: fixed; inset: 0; z-index: 0; pointer-events: none;
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.03'/%3E%3C/svg%3E");
  background-size: 200px 200px;
}

/* ── NAVBAR ── */
.navbar {
  height: var(--nav-h);
  background: rgba(13,10,20,0.96);
  backdrop-filter: blur(20px) saturate(1.6);
  -webkit-backdrop-filter: blur(20px) saturate(1.6);
  border-bottom: 1px solid rgba(255,255,255,0.06);
  padding: 0 28px;
  display: flex; align-items: center; justify-content: space-between; gap: 20px;
  position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
}

.navbar-brand {
  display: flex; align-items: center; gap: 12px;
  text-decoration: none; flex-shrink: 0;
}
.brand-logo-img {
  width: 36px; height: 36px; border-radius: 9px;
  object-fit: contain; background: white; padding: 3px;
}
.brand-text-group { display: flex; flex-direction: column; line-height: 1.15; }
.brand-name {
  font-family: var(--font-head);
  font-size: 16px; font-weight: 800; letter-spacing: 0.06em;
  color: white;
}
.brand-tagline {
  font-size: 10px; font-weight: 400; color: rgba(255,255,255,0.45);
  letter-spacing: 0.03em;
}

.nav-search {
  flex: 1; max-width: 420px; position: relative;
}
.search-input {
  width: 100%; padding: 9px 38px 9px 14px;
  background: rgba(255,255,255,0.07);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: var(--r-sm);
  font-family: var(--font-body); font-size: 13.5px; color: white;
  transition: var(--t);
  outline: none;
}
.search-input::placeholder { color: rgba(255,255,255,0.35); }
.search-input:focus {
  background: rgba(255,255,255,0.1);
  border-color: rgba(124,58,237,0.5);
  box-shadow: 0 0 0 3px rgba(124,58,237,0.15);
}
.search-icon {
  position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
  color: rgba(255,255,255,0.35); font-size: 13px; pointer-events: none;
}

.nav-right { display: flex; align-items: center; gap: 12px; }

.nav-create-btn {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 8px 16px;
  background: var(--violet);
  color: white; border-radius: var(--r-sm);
  font-family: var(--font-body); font-size: 13px; font-weight: 600;
  text-decoration: none; border: none; cursor: pointer;
  transition: var(--t);
  white-space: nowrap;
}
.nav-create-btn:hover {
  background: var(--violet-lt);
  transform: translateY(-1px);
  box-shadow: 0 4px 16px rgba(124,58,237,0.4);
}

.profile-wrap { position: relative; }
.profile-button {
  display: flex; align-items: center; gap: 9px;
  padding: 6px 12px 6px 6px;
  background: rgba(255,255,255,0.07);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 40px; cursor: pointer;
  transition: var(--t); color: white;
}
.profile-button:hover { background: rgba(255,255,255,0.13); border-color: rgba(255,255,255,0.18); }
.profile-avatar {
  width: 32px; height: 32px; border-radius: 50%;
  background: linear-gradient(135deg, var(--violet), var(--orchid));
  display: flex; align-items: center; justify-content: center;
  font-family: var(--font-head); font-weight: 700; font-size: 12px; color: white;
  overflow: hidden; flex-shrink: 0;
}
.profile-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
.profile-name { font-size: 13px; font-weight: 500; }
.profile-caret { font-size: 9px; color: rgba(255,255,255,0.5); margin-left: 2px; }

/* ── DROPDOWN ── */
.profile-dropdown {
  position: absolute; top: calc(100% + 10px); right: 0;
  background: var(--surface-3); border-radius: var(--r-md);
  box-shadow: var(--shadow-lg), 0 0 0 1px var(--border);
  min-width: 230px;
  opacity: 0; visibility: hidden; transform: translateY(-6px) scale(0.98);
  transition: var(--t); z-index: 1001;
  overflow: hidden;
}
.profile-dropdown.open {
  opacity: 1; visibility: visible; transform: translateY(0) scale(1);
}
.dropdown-header {
  padding: 18px 20px;
  background: linear-gradient(135deg, var(--ink) 0%, var(--ink-3) 100%);
  border-bottom: 1px solid rgba(255,255,255,0.06);
}
.dd-avatar {
  width: 44px; height: 44px; border-radius: 50%;
  background: linear-gradient(135deg, var(--violet), var(--orchid));
  display: flex; align-items: center; justify-content: center;
  font-family: var(--font-head); font-weight: 700; font-size: 16px; color: white;
  overflow: hidden; margin-bottom: 10px;
}
.dd-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
.dropdown-name { font-weight: 600; font-size: 14px; color: white; }
.dropdown-email { font-size: 12px; color: rgba(255,255,255,0.5); margin-top: 2px; }
.dropdown-role {
  display: inline-block; margin-top: 8px;
  padding: 2px 10px;
  background: var(--violet-dim);
  border: 1px solid rgba(124,58,237,0.3);
  color: var(--orchid); border-radius: 20px; font-size: 11px; font-weight: 600;
  letter-spacing: 0.04em; text-transform: uppercase;
}
.dropdown-menu { padding: 6px 0; }
.dropdown-item {
  display: flex; align-items: center; gap: 11px;
  padding: 10px 18px; color: var(--text-2);
  text-decoration: none; font-size: 13.5px; transition: var(--t);
  cursor: pointer; border: none; background: none; width: 100%; text-align: left;
  font-family: var(--font-body);
}
.dropdown-item i { width: 16px; text-align: center; color: var(--text-3); }
.dropdown-item:hover { background: var(--surface-2); color: var(--text-1); }
.dropdown-item.danger { color: var(--rose); }
.dropdown-item.danger i { color: var(--rose); }
.dropdown-item.danger:hover { background: rgba(244,63,94,0.06); }
.dropdown-divider { height: 1px; background: var(--border); margin: 4px 0; }

/* ── PAGE LAYOUT ── */
.page-wrapper { display: flex; min-height: calc(100vh - var(--nav-h)); position: relative; z-index: 1; }

/* ── SIDEBAR ── */
.left-sidebar {
  width: 230px; flex-shrink: 0;
  padding: 28px 12px;
  display: flex; flex-direction: column; gap: 2px;
  background: rgba(255,255,255,0.6);
  backdrop-filter: blur(12px);
  border-right: 1px solid var(--border);
  min-height: calc(100vh - var(--nav-h));
  position: sticky; top: var(--nav-h); align-self: flex-start;
}

.sidebar-section-label {
  font-family: var(--font-head);
  font-size: 10px; font-weight: 700; text-transform: uppercase;
  letter-spacing: 0.1em; color: var(--text-3);
  padding: 14px 14px 6px;
}

.sidebar-link {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 14px; border-radius: var(--r-sm);
  text-decoration: none; font-size: 13.5px; font-weight: 500;
  color: var(--text-2); transition: var(--t);
}
.sidebar-link i { width: 18px; text-align: center; font-size: 14px; color: var(--text-3); transition: var(--t); }
.sidebar-link:hover { background: var(--violet-dim); color: var(--violet); }
.sidebar-link:hover i { color: var(--violet); }
.sidebar-link.active {
  background: linear-gradient(135deg, rgba(124,58,237,0.12), rgba(192,132,252,0.08));
  color: var(--violet); font-weight: 600;
  box-shadow: inset 3px 0 0 var(--violet);
}
.sidebar-link.active i { color: var(--violet); }

.sidebar-bottom {
  margin-top: auto; padding-top: 16px;
  border-top: 1px solid var(--border);
}
.sidebar-logout {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 14px; border-radius: var(--r-sm);
  font-size: 13.5px; font-weight: 500; color: var(--rose);
  background: none; border: none; cursor: pointer; width: 100%;
  transition: var(--t); font-family: var(--font-body);
}
.sidebar-logout i { width: 18px; text-align: center; font-size: 14px; }
.sidebar-logout:hover { background: rgba(244,63,94,0.07); }

/* ── PAGE CONTENT ── */
.page-content { flex: 1; min-width: 0; padding: 36px 36px 48px 28px; }

/* ── DB ERROR ── */
.db-error-banner {
  background: rgba(244,63,94,0.06);
  border: 1px solid rgba(244,63,94,0.25);
  border-radius: var(--r-md); padding: 14px 18px; margin-bottom: 28px;
  display: flex; align-items: center; gap: 10px;
  color: var(--rose); font-size: 13.5px; font-weight: 600;
}

/* ── WELCOME SECTION ── */
.welcome-section {
  background: linear-gradient(135deg, var(--ink) 0%, var(--ink-3) 55%, #3d1f6e 100%);
  border-radius: var(--r-xl);
  padding: 36px 40px;
  margin-bottom: 28px;
  display: flex; justify-content: space-between; align-items: center; gap: 24px;
  position: relative; overflow: hidden;
  box-shadow: var(--shadow-md);
}
.welcome-section::before {
  content: '';
  position: absolute; top: -60px; right: -40px;
  width: 300px; height: 300px;
  background: radial-gradient(circle, rgba(124,58,237,0.35) 0%, transparent 70%);
  pointer-events: none;
}
.welcome-section::after {
  content: '';
  position: absolute; bottom: -80px; left: 30%;
  width: 200px; height: 200px;
  background: radial-gradient(circle, rgba(192,132,252,0.2) 0%, transparent 70%);
  pointer-events: none;
}
.welcome-content { position: relative; z-index: 1; }
.welcome-greeting {
  font-size: 11px; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase;
  color: var(--orchid); margin-bottom: 8px;
}
.welcome-content h1 {
  font-family: var(--font-head);
  font-size: 30px; font-weight: 800; color: white; margin-bottom: 6px;
  line-height: 1.15;
}
.welcome-subtitle { font-size: 14px; color: rgba(255,255,255,0.55); }

.welcome-stats {
  display: flex; gap: 2px; position: relative; z-index: 1;
  background: rgba(255,255,255,0.05);
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: var(--r-md); overflow: hidden;
}
.w-stat {
  padding: 20px 28px; text-align: center; flex: 1;
  border-right: 1px solid rgba(255,255,255,0.08);
}
.w-stat:last-child { border-right: none; }
.w-stat-num {
  font-family: var(--font-head);
  font-size: 28px; font-weight: 800; color: white; display: block;
}
.w-stat-label { font-size: 11px; color: rgba(255,255,255,0.45); margin-top: 2px; font-weight: 500; }

/* ── STATS GRID ── */
.stats-grid {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 16px; margin-bottom: 32px;
}

.stat-card {
  background: var(--surface-3);
  border: 1px solid var(--border);
  border-radius: var(--r-lg); padding: 24px;
  box-shadow: var(--shadow-xs);
  transition: var(--t); position: relative; overflow: hidden;
}
.stat-card::after {
  content: '';
  position: absolute; top: 0; left: 0; right: 0; height: 3px;
  background: linear-gradient(90deg, var(--violet), var(--orchid));
  border-radius: var(--r-lg) var(--r-lg) 0 0;
}
.stat-card:hover {
  transform: translateY(-3px);
  box-shadow: var(--shadow-vl);
  border-color: var(--border-2);
}

.stat-card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; }
.stat-card-label {
  font-size: 12px; font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.08em; color: var(--text-3);
}
.stat-card-icon {
  width: 36px; height: 36px; border-radius: var(--r-sm);
  background: var(--violet-dim);
  display: flex; align-items: center; justify-content: center;
  color: var(--violet); font-size: 15px;
}
.stat-card-value {
  font-family: var(--font-head);
  font-size: 38px; font-weight: 800; color: var(--text-1);
  line-height: 1; margin-bottom: 6px;
}
.stat-card-delta {
  font-size: 12.5px; font-weight: 600; color: var(--emerald);
  display: flex; align-items: center; gap: 4px;
}
.stat-card-delta.neutral { color: var(--text-3); font-weight: 400; }
.stat-card-delta i { font-size: 10px; }

/* ── SECTION HEADER ── */
.section-header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 18px; flex-wrap: wrap; gap: 12px;
}
.section-title {
  font-family: var(--font-head);
  font-size: 20px; font-weight: 700; color: var(--text-1);
}
.section-actions { display: flex; align-items: center; gap: 10px; }

.btn-create-sm {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 9px 18px;
  background: linear-gradient(135deg, var(--violet), #9333ea);
  color: white; border-radius: var(--r-sm);
  font-family: var(--font-body); font-size: 13px; font-weight: 600;
  text-decoration: none; transition: var(--t);
}
.btn-create-sm:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 16px rgba(124,58,237,0.4);
}

.view-all-link {
  font-size: 13px; font-weight: 600; color: var(--violet);
  text-decoration: none; display: flex; align-items: center; gap: 5px;
  transition: var(--t);
}
.view-all-link:hover { color: var(--violet-lt); }

/* ── FILTER TABS ── */
.filter-tabs { display: flex; gap: 6px; margin-bottom: 22px; flex-wrap: wrap; }
.filter-tab {
  padding: 7px 16px;
  background: var(--surface-3);
  border: 1px solid var(--border);
  border-radius: 40px; font-size: 13px; font-weight: 500;
  color: var(--text-2); cursor: pointer;
  transition: var(--t); font-family: var(--font-body);
}
.filter-tab:hover { border-color: var(--violet); color: var(--violet); }
.filter-tab.active {
  background: var(--violet); border-color: var(--violet);
  color: white; font-weight: 600;
  box-shadow: 0 2px 10px rgba(124,58,237,0.3);
}

/* ── ASSESSMENT CARDS ── */
.assessments-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 20px; margin-bottom: 40px;
}

.assessment-card {
  background: var(--surface-3);
  border: 1px solid var(--border);
  border-radius: var(--r-lg); padding: 24px;
  box-shadow: var(--shadow-xs);
  transition: var(--t); position: relative; overflow: hidden;
  display: flex; flex-direction: column;
}
.assessment-card::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
  background: linear-gradient(90deg, var(--violet), var(--orchid));
}
.assessment-card.draft::before  { background: linear-gradient(90deg, #d97706, var(--gold)); }
.assessment-card.archived::before { background: linear-gradient(90deg, #0ea5e9, var(--sky)); }

.assessment-card:hover {
  transform: translateY(-4px);
  box-shadow: var(--shadow-md), 0 0 0 1px var(--border-2);
  border-color: var(--border-2);
}
.assessment-card.hidden { display: none; }

.card-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; gap: 10px; }
.assessment-title {
  font-family: var(--font-head);
  font-size: 15.5px; font-weight: 700; color: var(--text-1);
  line-height: 1.3; flex: 1;
}

.status-badge {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 4px 10px; border-radius: 40px;
  font-size: 11px; font-weight: 700;
  letter-spacing: 0.04em; text-transform: uppercase; flex-shrink: 0;
}
.status-badge.active   { background: rgba(16,185,129,0.1); color: #059669; border: 1px solid rgba(16,185,129,0.25); }
.status-badge.draft    { background: rgba(245,158,11,0.1); color: #b45309; border: 1px solid rgba(245,158,11,0.25); }
.status-badge.archived { background: rgba(56,189,248,0.1); color: #0284c7; border: 1px solid rgba(56,189,248,0.25); }
.badge-dot { width: 5px; height: 5px; border-radius: 50%; background: currentColor; }

.assessment-category {
  display: inline-block; padding: 3px 10px;
  background: var(--violet-dim);
  color: var(--violet);
  border: 1px solid rgba(124,58,237,0.18);
  border-radius: 40px; font-size: 11.5px; font-weight: 600;
  margin-bottom: 16px; letter-spacing: 0.02em;
}

.assessment-meta {
  display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
  margin-bottom: 18px;
  background: var(--surface); border-radius: var(--r-md);
  padding: 12px; flex: 1;
}
.meta-item {
  display: flex; align-items: center; gap: 7px;
  font-size: 12.5px; color: var(--text-2);
}
.meta-item i { font-size: 11px; color: var(--text-3); width: 13px; text-align: center; }

.assessment-actions { display: flex; gap: 8px; margin-top: auto; }
.btn {
  padding: 9px 14px; border: 1px solid transparent;
  border-radius: var(--r-sm); font-size: 13px; font-weight: 600;
  cursor: pointer; transition: var(--t); flex: 1;
  text-align: center; text-decoration: none;
  display: inline-flex; align-items: center; justify-content: center;
  font-family: var(--font-body);
}
.btn-primary {
  background: linear-gradient(135deg, var(--violet), #9333ea);
  color: white; border-color: transparent;
}
.btn-primary:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 14px rgba(124,58,237,0.35);
}
.btn-secondary {
  background: var(--surface); color: var(--text-2);
  border-color: var(--border);
}
.btn-secondary:hover { background: var(--surface-2); color: var(--text-1); }
.btn-danger { background: rgba(244,63,94,0.08); color: var(--rose); border-color: rgba(244,63,94,0.2); }
.btn-danger:hover { background: rgba(244,63,94,0.14); }

/* ── EMPTY STATE ── */
.empty-state {
  background: var(--surface-3); border: 1px dashed var(--border-2);
  border-radius: var(--r-xl); padding: 72px 30px;
  text-align: center; box-shadow: var(--shadow-xs);
}
.empty-icon { font-size: 56px; margin-bottom: 18px; opacity: 0.35; }
.empty-title {
  font-family: var(--font-head);
  font-size: 20px; font-weight: 700; color: var(--text-1); margin-bottom: 8px;
}
.empty-subtitle { font-size: 14.5px; color: var(--text-3); margin-bottom: 28px; }
.btn-create-empty {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 12px 28px;
  background: linear-gradient(135deg, var(--violet), #9333ea);
  color: white; border-radius: var(--r-md); font-weight: 700; font-size: 14px;
  text-decoration: none; transition: var(--t);
}
.btn-create-empty:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(124,58,237,0.35); }

/* ── FAB ── */
.fab-container { position: fixed; bottom: 32px; right: 32px; z-index: 999; }
.fab-button {
  width: 56px; height: 56px; border-radius: 50%;
  background: linear-gradient(135deg, var(--violet), #9333ea);
  color: white; border: none; font-size: 22px;
  cursor: pointer; box-shadow: 0 6px 24px rgba(124,58,237,0.45);
  transition: var(--t);
  display: flex; align-items: center; justify-content: center;
  text-decoration: none;
}
.fab-button:hover { transform: scale(1.1) rotate(90deg); box-shadow: 0 10px 32px rgba(124,58,237,0.55); }

/* ── DELETE MODAL ── */
.modal-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(13,10,20,0.6); backdrop-filter: blur(4px);
  z-index: 2000; align-items: center; justify-content: center;
}
.modal-overlay.open { display: flex; }
.modal {
  background: var(--surface-3); border-radius: var(--r-xl);
  padding: 32px; width: 90%; max-width: 440px;
  box-shadow: var(--shadow-lg), 0 0 0 1px var(--border);
  animation: modal-in 0.2s var(--ease) forwards;
}
@keyframes modal-in {
  from { opacity: 0; transform: scale(0.95) translateY(12px); }
  to   { opacity: 1; transform: scale(1) translateY(0); }
}
.modal-icon {
  width: 48px; height: 48px; border-radius: 50%;
  background: rgba(244,63,94,0.1); border: 1px solid rgba(244,63,94,0.25);
  display: flex; align-items: center; justify-content: center;
  color: var(--rose); font-size: 20px; margin-bottom: 18px;
}
.modal-title {
  font-family: var(--font-head);
  font-size: 19px; font-weight: 700; margin-bottom: 10px; color: var(--text-1);
}
.modal-body  { font-size: 14px; color: var(--text-2); margin-bottom: 26px; line-height: 1.65; }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; }
.btn-cancel {
  padding: 10px 22px; background: var(--surface); color: var(--text-2);
  border: 1px solid var(--border); border-radius: var(--r-sm);
  font-weight: 600; font-size: 13.5px; cursor: pointer; transition: var(--t);
  font-family: var(--font-body);
}
.btn-cancel:hover { border-color: var(--violet); color: var(--violet); }
.btn-confirm-delete {
  padding: 10px 22px; background: var(--rose); color: white;
  border: none; border-radius: var(--r-sm);
  font-weight: 700; font-size: 13.5px; cursor: pointer; transition: var(--t);
  font-family: var(--font-body);
}
.btn-confirm-delete:hover { background: #e11d48; box-shadow: 0 4px 14px rgba(244,63,94,0.35); }
.btn-confirm-delete:disabled { opacity: 0.55; cursor: not-allowed; }

/* ── RESPONSIVE ── */
@media (max-width: 960px) {
  .left-sidebar { display: none; }
  .page-content { padding: 28px 20px; }
  .welcome-section { flex-direction: column; align-items: flex-start; }
  .welcome-stats { width: 100%; }
}
@media (max-width: 640px) {
  .nav-search { display: none; }
  .navbar { padding: 0 16px; }
  .stats-grid { grid-template-columns: 1fr 1fr; }
  .assessments-grid { grid-template-columns: 1fr; }
  .section-header { flex-direction: column; align-items: flex-start; }
  .welcome-content h1 { font-size: 22px; }
}
@media (max-width: 400px) {
  .stats-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<!-- ── NAVIGATION ── -->
<nav class="navbar">
  <a href="teacher-dashboard.php" class="navbar-brand">
    <img src="prepaura-logo.png" alt="PREPAURA" class="brand-logo-img">
    <div class="brand-text-group">
      <span class="brand-name">PREPAURA</span>
      <span class="brand-tagline">Placement Training Platform</span>
    </div>
  </a>

  <div class="nav-search">
    <input type="text" class="search-input" id="searchInput" placeholder="Search assessments…" autocomplete="off">
    <i class="fa fa-search search-icon"></i>
  </div>

  <div class="nav-right">
    <a href="create-assessment.php" class="nav-create-btn">
      <i class="fa fa-plus"></i> New Assessment
    </a>

    <div class="profile-wrap">
      <button class="profile-button" id="profileBtn">
        <div class="profile-avatar">
          <?php if (!empty($userPicture)): ?>
            <img src="<?= htmlspecialchars($userPicture) ?>" alt="Profile">
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
                <img src="<?= htmlspecialchars($userPicture) ?>" alt="Profile">
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
            <a href="help.html" target="_blank" rel="noopener" class="dropdown-item"><i class="fa fa-circle-question"></i> Help &amp; Support</a>
            <div class="dropdown-divider"></div>
            <a href="#" onclick="handleLogout()" class="dropdown-item"><i class="fa fa-right-from-bracket"></i> Logout</a>
          </div>
        </div>
      </button>
    </div>
  </div>
</nav>

<!-- ── MAIN ── -->
<div class="page-wrapper">

  <!-- Sidebar -->
  <aside class="left-sidebar">
    <span class="sidebar-section-label">Navigation</span>
    <a href="teacher-dashboard.php" class="sidebar-link active"><i class="fa fa-house"></i> Dashboard</a>
    <a href="teacher-assessments.php" class="sidebar-link"><i class="fa fa-clipboard-list"></i> Assessments</a>
    <a href="manage-groups.php" class="sidebar-link"><i class="fa fa-users"></i> Manage Groups</a>
    <a href="teacher-resources.php" class="sidebar-link"><i class="fa fa-folder-open"></i> Resources</a>
    <div class="sidebar-bottom">
      <button onclick="handleLogout()" class="sidebar-logout"><i class="fa fa-right-from-bracket"></i> Logout</button>
    </div>
  </aside>

  <!-- Content -->
  <div class="page-content">

    <?php if ($dbError): ?>
    <div class="db-error-banner">
      <i class="fa fa-triangle-exclamation"></i>
      Some data could not be loaded. Please report this to your administrator.
    </div>
    <?php endif; ?>

    <!-- Welcome -->
    <div class="welcome-section">
      <div class="welcome-content">
        <div class="welcome-greeting">Good to see you</div>
        <h1>Welcome back, <?= $userName ?>!</h1>
        <p class="welcome-subtitle">Here's what's happening with your assessments today.</p>
      </div>
      <div class="welcome-stats">
        <div class="w-stat">
          <span class="w-stat-num"><?= $totalAssessments ?></span>
          <span class="w-stat-label">Assessments</span>
        </div>
        <div class="w-stat">
          <span class="w-stat-num"><?= $activeStudents ?></span>
          <span class="w-stat-label">Students</span>
        </div>
        <div class="w-stat">
          <span class="w-stat-num"><?= $newThisMonth ?></span>
          <span class="w-stat-label">New This Month</span>
        </div>
      </div>
    </div>

    <!-- Stat Cards -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-card-header">
          <span class="stat-card-label">Total Assessments</span>
          <div class="stat-card-icon"><i class="fa fa-file-pen"></i></div>
        </div>
        <div class="stat-card-value"><?= $totalAssessments ?></div>
        <?php if ($newThisMonth > 0): ?>
        <div class="stat-card-delta"><i class="fa fa-arrow-up"></i><?= $newThisMonth ?> new this month</div>
        <?php else: ?>
        <div class="stat-card-delta neutral">No new assessments this month</div>
        <?php endif; ?>
      </div>

      <div class="stat-card">
        <div class="stat-card-header">
          <span class="stat-card-label">Students Attempted</span>
          <div class="stat-card-icon"><i class="fa fa-user-group"></i></div>
        </div>
        <div class="stat-card-value"><?= $activeStudents ?></div>
        <?php if ($newStudentsWeek > 0): ?>
        <div class="stat-card-delta"><i class="fa fa-arrow-up"></i><?= $newStudentsWeek ?> this week</div>
        <?php else: ?>
        <div class="stat-card-delta neutral">No new attempts this week</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Assessments Section -->
    <div class="section-header">
      <div class="section-actions">
        <h2 class="section-title">My Assessments</h2>
        <a href="create-assessment.php" class="btn-create-sm"><i class="fa fa-plus"></i> Create</a>
      </div>
      <a href="teacher-assessments.php" class="view-all-link">View all <i class="fa fa-arrow-right"></i></a>
    </div>

    <div class="filter-tabs" role="tablist">
      <button class="filter-tab active" data-filter="all"      role="tab">All</button>
      <button class="filter-tab"         data-filter="active"  role="tab">Active</button>
      <button class="filter-tab"         data-filter="draft"   role="tab">Draft</button>
      <button class="filter-tab"         data-filter="archived" role="tab">Completed</button>
    </div>

    <?php if (empty($assessments)): ?>
    <div class="empty-state">
      <div class="empty-icon">📋</div>
      <div class="empty-title">No assessments yet</div>
      <div class="empty-subtitle">Create your first assessment to get started.</div>
      <a href="create-assessment.php" class="btn-create-empty"><i class="fa fa-plus"></i> Create Assessment</a>
    </div>
    <?php else: ?>
    <div class="assessments-grid" id="assessmentsGrid">
      <?php foreach ($assessments as $a):
        $aid      = (int)$a['assessment_id'];
        $status   = $a['status'];
        $qCount   = (int)$a['question_count'];
        $attempts = (int)$a['attempt_count'];
        $students = (int)$a['student_count'];
        if (in_array($status, ['active','published']) && $a['end_time']) {
          $dateLabel = 'Due ' . fmtDate($a['end_time']);
        } elseif ($status === 'archived') {
          $dateLabel = 'Completed ' . fmtDate($a['updated_at']);
        } else {
          $dateLabel = 'Created ' . fmtDate($a['created_at']);
        }
      ?>
      <div class="assessment-card <?= htmlspecialchars($status) ?>"
           data-status="<?= htmlspecialchars($status) ?>"
           data-id="<?= $aid ?>">

        <div class="card-top">
          <h3 class="assessment-title"><?= htmlspecialchars($a['title']) ?></h3>
          <span class="status-badge <?= $status ?>">
            <span class="badge-dot"></span>
            <?= statusLabel($status) ?>
          </span>
        </div>

        <span class="assessment-category"><?= htmlspecialchars(ucfirst($a['category'] ?? 'General')) ?></span>

        <div class="assessment-meta">
          <div class="meta-item"><i class="fa fa-calendar"></i> <?= $dateLabel ?></div>
          <div class="meta-item"><i class="fa fa-clock"></i> <?= (int)$a['duration_minutes'] ?> min</div>
          <div class="meta-item"><i class="fa fa-circle-question"></i> <?= $qCount ?> question<?= $qCount !== 1 ? 's' : '' ?></div>
          <div class="meta-item">
            <i class="fa fa-users"></i>
            <?php if ($status === 'draft'): ?>
              Not published
            <?php elseif ($attempts === 0): ?>
              No attempts yet
            <?php else: ?>
              <?= $students ?> student<?= $students !== 1 ? 's' : '' ?>
            <?php endif; ?>
          </div>
        </div>

        <div class="assessment-actions">
          <?php if ($status === 'draft'): ?>
            <a href="create-assessment.php?edit=<?= $aid ?>" class="btn btn-primary">Continue Editing</a>
            <button class="btn btn-danger" onclick="confirmDelete(<?= $aid ?>, '<?= htmlspecialchars(addslashes($a['title'])) ?>')">Delete</button>
          <?php elseif (in_array($status, ['active','published']) || $status === 'archived'): ?>
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

  </div><!-- /page-content -->
</div><!-- /page-wrapper -->

<!-- FAB -->
<a href="create-assessment.php" class="fab-container" title="Create Assessment (Ctrl+N)">
  <span class="fab-button"><i class="fa fa-plus"></i></span>
</a>

<!-- Delete Confirm Modal -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal">
    <div class="modal-icon"><i class="fa fa-trash-can"></i></div>
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

// ── Profile dropdown ──
const profileBtn  = document.getElementById('profileBtn');
const profileDrop = document.getElementById('profileDropdown');
profileBtn.addEventListener('click', e => {
  e.stopPropagation();
  profileDrop.classList.toggle('open');
});
document.addEventListener('click', () => profileDrop.classList.remove('open'));

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
<?php
/* ========================================
 * TEACHER ASSESSMENTS PAGE
 * File: teacher-assessments.php
 * ======================================== */

require_once 'config.php';
require_once 'db-guard.php';

$currentUser  = validateSession($conn, 'teacher');
$teacherId    = (int) $currentUser['user_id'];
$userName     = htmlspecialchars($currentUser['full_name'] ?? 'Teacher');
$userEmail    = htmlspecialchars($currentUser['email'] ?? '');
$userInitials = strtoupper(substr($currentUser['full_name'] ?? 'T', 0, 2));

$picStmt = $conn->prepare("SELECT profile_image FROM users WHERE user_id = ?");
$picStmt->bind_param("i", $teacherId);
$picStmt->execute();
$picRow      = $picStmt->get_result()->fetch_assoc();
$userPicture = $picRow['profile_image'] ?? '';

$activeFilter   = trim($_GET['filter']   ?? 'all');
$allowedFilters = ['all', 'published', 'draft', 'archived'];
if (!in_array($activeFilter, $allowedFilters, true)) $activeFilter = 'all';

$activeCategory    = trim($_GET['category'] ?? 'all');
$allowedCategories = ['all', 'aptitude', 'technical', 'coding', 'reasoning', 'english', 'general'];
if (!in_array($activeCategory, $allowedCategories, true)) $activeCategory = 'all';

$assessments     = [];
$assessmentError = false;

$r3 = safePreparedQuery($conn,
    "SELECT
        a.assessment_id, a.title, a.description, a.category, a.difficulty,
        a.status, a.duration_minutes, a.total_marks, a.passing_marks,
        a.max_attempts, a.start_time, a.end_time, a.created_at,
        (SELECT COUNT(*) FROM questions q WHERE q.assessment_id = a.assessment_id) AS question_count,
        (SELECT COUNT(DISTINCT aa.user_id) FROM assessment_attempts aa
          WHERE aa.assessment_id = a.assessment_id AND aa.status = 'submitted') AS students_completed,
        (SELECT COUNT(DISTINCT aa2.user_id) FROM assessment_attempts aa2
          WHERE aa2.assessment_id = a.assessment_id) AS students_attempted,
        (SELECT ROUND(AVG(aa3.percentage),1) FROM assessment_attempts aa3
          WHERE aa3.assessment_id = a.assessment_id AND aa3.status = 'submitted') AS avg_score
     FROM assessments a
     WHERE a.created_by = ?
     ORDER BY a.created_at DESC",
    "i", [$teacherId]
);

if ($r3['success'] && $r3['result']) {
    while ($row = $r3['result']->fetch_assoc()) $assessments[] = $row;
    $r3['result']->free();
} else {
    $assessmentError = true;
}

// ── Fetch teacher's groups for publish modal ──
$pubGroups = [];
$pgRes = safePreparedQuery($conn,
    "SELECT group_id, name FROM groups WHERE teacher_id = ? ORDER BY name",
    "i", [$teacherId]
);
if ($pgRes['success'] && $pgRes['result']) {
    while ($row = $pgRes['result']->fetch_assoc()) $pubGroups[] = $row;
    $pgRes['result']->free();
}

// ── Fetch all students for publish modal ──
$pubStudents = [];
$psRes = safePreparedQuery($conn,
    "SELECT user_id, full_name, email, department FROM users WHERE role = 'student' AND is_active = 1 ORDER BY full_name",
    "", []
);
if ($psRes['success'] && $psRes['result']) {
    while ($row = $psRes['result']->fetch_assoc()) $pubStudents[] = $row;
    $psRes['result']->free();
}

$totalAll       = count($assessments);
$totalPublished = 0; $totalDraft = 0; $totalArchived = 0;
foreach ($assessments as $a) {
    if ($a['status'] === 'published') $totalPublished++;
    elseif ($a['status'] === 'draft') $totalDraft++;
    else $totalArchived++;
}

if (!function_exists('timeAgo')) {
    function timeAgo(string $datetime): string {
        $diff = time() - strtotime($datetime);
        if ($diff < 60)     return 'Just now';
        if ($diff < 3600)   return floor($diff / 60)   . ' min ago';
        if ($diff < 86400)  return floor($diff / 3600)  . ' hr ago';
        if ($diff < 604800) return floor($diff / 86400) . ' day ago';
        return date('d M Y', strtotime($datetime));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Assessments — PREPAURA</title>
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
.navbar-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; flex-shrink: 0; }
.brand-logo-img { width: 36px; height: 36px; border-radius: 9px; object-fit: contain; background: white; padding: 3px; }
.brand-text-group { display: flex; flex-direction: column; line-height: 1.15; }
.brand-name { font-family: var(--font-head); font-size: 16px; font-weight: 800; letter-spacing: 0.06em; color: white; }
.brand-tagline { font-size: 10px; font-weight: 400; color: rgba(255,255,255,0.45); letter-spacing: 0.03em; }

.nav-center { flex: 1; max-width: 560px; display: flex; align-items: center; gap: 10px; }
.search-input-wrap { flex: 1; position: relative; }
.search-input {
  width: 100%; padding: 9px 38px 9px 14px;
  background: rgba(255,255,255,0.07);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: var(--r-sm);
  font-family: var(--font-body); font-size: 13.5px; color: white;
  transition: var(--t); outline: none;
}
.search-input::placeholder { color: rgba(255,255,255,0.35); }
.search-input:focus { background: rgba(255,255,255,0.1); border-color: rgba(124,58,237,0.5); box-shadow: 0 0 0 3px rgba(124,58,237,0.15); }
.search-icon { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: rgba(255,255,255,0.35); font-size: 13px; pointer-events: none; }

.date-box {
  display: flex; align-items: center; gap: 6px;
  background: rgba(255,255,255,0.07);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: var(--r-sm); padding: 8px 12px; flex-shrink: 0;
}
.date-box label { font-size: 10px; font-weight: 700; color: rgba(255,255,255,0.4); text-transform: uppercase; letter-spacing: 0.06em; white-space: nowrap; }
.date-box input[type="date"] { border: none; background: transparent; font-family: var(--font-body); font-size: 12.5px; color: rgba(255,255,255,0.75); outline: none; cursor: pointer; width: 110px; }

.nav-right { display: flex; align-items: center; gap: 12px; }
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

.profile-dropdown {
  position: absolute; top: calc(100% + 10px); right: 0;
  background: var(--surface-3); border-radius: var(--r-md);
  box-shadow: var(--shadow-lg), 0 0 0 1px var(--border);
  min-width: 230px;
  opacity: 0; visibility: hidden; transform: translateY(-6px) scale(0.98);
  transition: var(--t); z-index: 1001; overflow: hidden;
}
.profile-dropdown.open { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
.dropdown-header {
  padding: 18px 20px;
  background: linear-gradient(135deg, var(--ink) 0%, var(--ink-3) 100%);
  border-bottom: 1px solid rgba(255,255,255,0.06);
  text-align: left;
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
  display: inline-block; margin-top: 8px; padding: 2px 10px;
  background: var(--violet-dim); border: 1px solid rgba(124,58,237,0.3);
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

/* ── LAYOUT ── */
.page-wrapper { display: flex; min-height: calc(100vh - var(--nav-h)); position: relative; z-index: 1; }

.left-sidebar {
  width: 230px; flex-shrink: 0; padding: 28px 12px;
  display: flex; flex-direction: column; gap: 2px;
  background: rgba(255,255,255,0.6); backdrop-filter: blur(12px);
  border-right: 1px solid var(--border);
  min-height: calc(100vh - var(--nav-h));
  position: sticky; top: var(--nav-h); align-self: flex-start;
}
.sidebar-section-label {
  font-family: var(--font-head); font-size: 10px; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.1em; color: var(--text-3);
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
.sidebar-link.active { background: linear-gradient(135deg, rgba(124,58,237,0.12), rgba(192,132,252,0.08)); color: var(--violet); font-weight: 600; box-shadow: inset 3px 0 0 var(--violet); }
.sidebar-link.active i { color: var(--violet); }
.sidebar-bottom { margin-top: auto; padding-top: 16px; border-top: 1px solid var(--border); }
.sidebar-logout {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 14px; border-radius: var(--r-sm);
  font-size: 13.5px; font-weight: 500; color: var(--rose);
  background: none; border: none; cursor: pointer; width: 100%;
  transition: var(--t); font-family: var(--font-body);
}
.sidebar-logout i { width: 18px; text-align: center; font-size: 14px; }
.sidebar-logout:hover { background: rgba(244,63,94,0.07); }

.page-content { flex: 1; min-width: 0; padding: 36px 36px 48px 28px; }

/* ── PAGE HEADER ── */
.page-header {
  background: linear-gradient(135deg, var(--ink) 0%, var(--ink-3) 55%, #3d1f6e 100%);
  border-radius: var(--r-xl); padding: 32px 40px; margin-bottom: 28px;
  display: flex; justify-content: space-between; align-items: center; gap: 24px;
  position: relative; overflow: hidden; box-shadow: var(--shadow-md);
}
.page-header::before {
  content: ''; position: absolute; top: -60px; right: -40px;
  width: 280px; height: 280px;
  background: radial-gradient(circle, rgba(124,58,237,0.35) 0%, transparent 70%);
  pointer-events: none;
}
.page-header-left { position: relative; z-index: 1; }
.page-header-label { font-size: 11px; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; color: var(--orchid); margin-bottom: 6px; }
.page-header-left h1 { font-family: var(--font-head); font-size: 26px; font-weight: 800; color: white; margin-bottom: 4px; line-height: 1.2; }
.page-header-left p { font-size: 14px; color: rgba(255,255,255,0.55); }
.btn-create {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 11px 22px; border-radius: var(--r-sm); border: none;
  background: rgba(255,255,255,0.15); backdrop-filter: blur(8px);
  border: 1px solid rgba(255,255,255,0.2);
  color: white; font-weight: 600; font-size: 13.5px;
  cursor: pointer; text-decoration: none; transition: var(--t);
  font-family: var(--font-body); position: relative; z-index: 1;
}
.btn-create:hover { background: rgba(255,255,255,0.25); transform: translateY(-1px); }

/* ── SUMMARY CARDS ── */
.summary-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 28px; }
.summary-card {
  background: var(--surface-3); border: 1px solid var(--border);
  border-radius: var(--r-lg); padding: 20px 22px;
  box-shadow: var(--shadow-xs); display: flex; align-items: center; gap: 14px;
  cursor: pointer; transition: var(--t); position: relative; overflow: hidden;
}
.summary-card::after {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
  background: linear-gradient(90deg, var(--violet), var(--orchid));
  border-radius: var(--r-lg) var(--r-lg) 0 0;
}
.summary-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-vl); border-color: var(--border-2); }
.summary-card.active-filter { border-color: var(--violet); box-shadow: var(--shadow-vl); }
.summary-icon {
  width: 46px; height: 46px; border-radius: var(--r-sm);
  background: var(--violet-dim); display: flex; align-items: center;
  justify-content: center; font-size: 20px; flex-shrink: 0;
}
.summary-number { font-family: var(--font-head); font-size: 26px; font-weight: 800; color: var(--text-1); line-height: 1; }
.summary-label  { font-size: 12px; color: var(--text-3); margin-top: 3px; font-weight: 500; }

/* ── ASSESSMENT CARDS ── */
.assessments-grid { display: flex; flex-direction: column; gap: 16px; }
.assessment-card {
  background: var(--surface-3); border: 1px solid var(--border);
  border-radius: var(--r-lg); padding: 24px 28px;
  box-shadow: var(--shadow-xs); transition: var(--t);
  display: flex; flex-direction: column; gap: 16px;
  position: relative; overflow: hidden;
}
.assessment-card::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
  background: linear-gradient(90deg, var(--violet), var(--orchid));
}
.assessment-card.draft-card::before   { background: linear-gradient(90deg, #d97706, var(--gold)); }
.assessment-card.archived-card::before { background: linear-gradient(90deg, #0ea5e9, var(--sky)); }
.assessment-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md), 0 0 0 1px var(--border-2); border-color: var(--border-2); }
.assessment-card.hidden { display: none !important; }

.card-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.card-title-group { flex: 1; }
.card-title { font-family: var(--font-head); font-size: 17px; font-weight: 700; color: var(--text-1); margin-bottom: 4px; line-height: 1.3; }
.card-meta-sub { font-size: 12.5px; color: var(--text-3); }
.card-badges { display: flex; gap: 6px; flex-shrink: 0; flex-wrap: wrap; justify-content: flex-end; }

.badge { padding: 4px 10px; border-radius: 40px; font-size: 11px; font-weight: 700; text-transform: capitalize; white-space: nowrap; letter-spacing: 0.03em; }
.badge-easy     { background: rgba(16,185,129,0.1);  color: #059669; border: 1px solid rgba(16,185,129,0.25); }
.badge-medium   { background: rgba(245,158,11,0.1);  color: #b45309; border: 1px solid rgba(245,158,11,0.25); }
.badge-hard     { background: rgba(244,63,94,0.1);   color: #e11d48; border: 1px solid rgba(244,63,94,0.25); }
.badge-published{ background: rgba(16,185,129,0.1);  color: #059669; border: 1px solid rgba(16,185,129,0.25); }
.badge-draft    { background: rgba(245,158,11,0.1);  color: #b45309; border: 1px solid rgba(245,158,11,0.25); }
.badge-archived { background: rgba(56,189,248,0.1);  color: #0284c7; border: 1px solid rgba(56,189,248,0.25); }
.badge-dot { width: 5px; height: 5px; border-radius: 50%; background: currentColor; display: inline-block; margin-right: 3px; }

.card-meta { display: flex; flex-wrap: wrap; gap: 18px; }
.meta-item { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--text-2); }
.meta-item i { font-size: 11px; color: var(--text-3); width: 13px; text-align: center; }

.card-stats {
  display: flex; gap: 0; padding: 0;
  background: var(--surface); border-radius: var(--r-md);
  border: 1px solid var(--border); overflow: hidden;
}
.stat-box {
  display: flex; flex-direction: column; align-items: center; gap: 2px;
  padding: 14px 20px; flex: 1;
  border-right: 1px solid var(--border);
}
.stat-box:last-child { border-right: none; }
.stat-box-num { font-family: var(--font-head); font-size: 20px; font-weight: 800; color: var(--violet); }
.stat-box-lbl { font-size: 11px; color: var(--text-3); text-align: center; font-weight: 500; }

.score-section { flex: 1; padding: 14px 20px; display: flex; flex-direction: column; justify-content: center; gap: 6px; }
.score-label { display: flex; justify-content: space-between; font-size: 12.5px; }
.score-label span:first-child { color: var(--text-3); }
.score-label span:last-child  { font-weight: 700; color: var(--text-1); }
.score-bar { height: 6px; background: var(--border); border-radius: 10px; overflow: hidden; }
.score-bar-fill { height: 100%; border-radius: 10px; transition: width 0.6s var(--ease); }
.score-bar-fill.good { background: linear-gradient(90deg, var(--emerald), #34d399); }
.score-bar-fill.avg  { background: linear-gradient(90deg, var(--gold), #fcd34d); }
.score-bar-fill.low  { background: linear-gradient(90deg, var(--rose), #fb7185); }

.card-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.btn-action {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 9px 16px; border-radius: var(--r-sm); border: 1px solid transparent;
  font-size: 13px; font-weight: 600; cursor: pointer;
  transition: var(--t); font-family: var(--font-body); text-decoration: none;
}
.btn-action:hover { transform: translateY(-1px); }
.btn-edit     { background: linear-gradient(135deg, var(--violet), #9333ea); color: white; }
.btn-edit:hover { box-shadow: 0 4px 14px rgba(124,58,237,0.35); }
.btn-results  { background: var(--surface); color: var(--text-2); border-color: var(--border); }
.btn-results:hover { background: var(--surface-2); color: var(--text-1); }
.btn-publish  { background: rgba(16,185,129,0.08); color: #059669; border-color: rgba(16,185,129,0.25); }
.btn-publish:hover { background: rgba(16,185,129,0.15); }
.btn-delete   { background: rgba(244,63,94,0.08); color: var(--rose); border-color: rgba(244,63,94,0.2); margin-left: auto; }
.btn-delete:hover { background: rgba(244,63,94,0.14); }
.btn-download { background: var(--violet-dim); color: var(--violet); border-color: var(--border-2); }
.btn-download:hover { background: rgba(124,58,237,0.2); }
.btn-print    { background: rgba(56,189,248,0.08); color: #0284c7; border-color: rgba(56,189,248,0.2); }
.btn-print:hover { background: rgba(56,189,248,0.15); }

/* ── STATE MESSAGES ── */
.state-message {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  gap: 12px; padding: 72px 20px; border-radius: var(--r-xl); text-align: center;
}
.state-message .state-icon { font-size: 48px; opacity: 0.4; }
.state-message h3 { font-family: var(--font-head); font-size: 20px; font-weight: 700; color: var(--text-1); }
.state-message p  { font-size: 14px; color: var(--text-3); }
.state-empty { background: var(--surface-3); border: 1px dashed var(--border-2); box-shadow: var(--shadow-xs); }
.state-error { background: rgba(244,63,94,0.04); border: 1px solid rgba(244,63,94,0.2); }
.state-error h3, .state-error p { color: var(--rose); }
.hidden { display: none !important; }

/* ── RESULTS MODAL ── */
.modal-overlay {
  position: fixed; inset: 0;
  background: rgba(13,10,20,0.6); backdrop-filter: blur(4px);
  z-index: 2000; display: none; align-items: center; justify-content: center; padding: 20px;
}
.modal-overlay.show { display: flex; }
.modal {
  background: var(--surface-3); border-radius: var(--r-xl);
  width: 100%; max-width: 920px; max-height: 88vh;
  overflow: hidden; display: flex; flex-direction: column;
  box-shadow: var(--shadow-lg), 0 0 0 1px var(--border);
}
.modal-header {
  padding: 22px 28px; border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
  background: linear-gradient(135deg, var(--ink) 0%, var(--ink-3) 100%);
  color: white; border-radius: var(--r-xl) var(--r-xl) 0 0;
}
.modal-title    { font-family: var(--font-head); font-size: 18px; font-weight: 700; }
.modal-subtitle { font-size: 13px; opacity: 0.6; margin-top: 3px; }
.modal-close {
  background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.15);
  color: white; width: 34px; height: 34px; border-radius: var(--r-sm);
  font-size: 16px; cursor: pointer; transition: var(--t);
  display: flex; align-items: center; justify-content: center;
}
.modal-close:hover { background: rgba(255,255,255,0.2); }
.modal-toolbar {
  padding: 14px 20px; border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
  background: var(--surface);
}
.modal-toolbar input {
  flex: 1; min-width: 180px; padding: 8px 14px;
  border: 1px solid var(--border); border-radius: var(--r-sm);
  font-family: var(--font-body); font-size: 13px; outline: none;
  transition: var(--t); background: var(--surface-3); color: var(--text-1);
}
.modal-toolbar input:focus { border-color: var(--violet); box-shadow: 0 0 0 3px var(--violet-dim); }
.modal-toolbar select {
  padding: 8px 12px; border: 1px solid var(--border); border-radius: var(--r-sm);
  font-family: var(--font-body); font-size: 13px; outline: none; cursor: pointer;
  background: var(--surface-3); color: var(--text-1);
}
.btn-modal-action {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 8px 14px; border-radius: var(--r-sm);
  font-size: 13px; font-weight: 600; cursor: pointer;
  font-family: var(--font-body); transition: var(--t); border: none;
}
.btn-modal-action:hover { opacity: 0.85; transform: translateY(-1px); }
.btn-csv      { background: var(--emerald); color: white; }
.btn-pdf-dl   { background: var(--violet); color: white; }
.btn-modal-print { background: var(--sky); color: var(--ink); }
.modal-body { padding: 0; overflow-y: auto; flex: 1; }
.results-table { width: 100%; border-collapse: collapse; font-size: 14px; }
.results-table thead th {
  padding: 12px 16px; background: var(--surface);
  font-weight: 700; color: var(--text-3); font-size: 11px;
  text-transform: uppercase; letter-spacing: 0.05em;
  border-bottom: 1px solid var(--border); text-align: left;
  position: sticky; top: 0; font-family: var(--font-head);
}
.results-table tbody td { padding: 13px 16px; border-bottom: 1px solid var(--surface-2); vertical-align: middle; }
.results-table tbody tr:hover { background: var(--surface); }
.result-rank  { font-weight: 700; color: var(--violet); font-family: var(--font-head); }
.result-name  { font-weight: 600; color: var(--text-1); }
.result-email { font-size: 12px; color: var(--text-3); }
.result-score { font-weight: 700; font-size: 15px; }
.result-score.pass { color: var(--emerald); }
.result-score.fail { color: var(--rose); }
.result-mini-bar { height: 5px; background: var(--border); border-radius: 10px; overflow: hidden; margin-top: 4px; min-width: 80px; }
.result-mini-fill { height: 100%; border-radius: 10px; }
.result-mini-fill.pass { background: linear-gradient(90deg, var(--emerald), #34d399); }
.result-mini-fill.fail { background: linear-gradient(90deg, var(--rose), #fb7185); }
.modal-footer { padding: 14px 20px; border-top: 1px solid var(--border); font-size: 13px; color: var(--text-3); background: var(--surface); }

/* ── RESPONSIVE ── */
@media (max-width: 1100px) { .summary-row { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 960px)  { .left-sidebar { display: none; } .page-content { padding: 28px 20px; } }
@media (max-width: 768px)  { .nav-center { display: none; } }
@media (max-width: 600px)  { .card-actions { flex-direction: column; } .card-stats { flex-wrap: wrap; } .summary-row { grid-template-columns: 1fr 1fr; } }
</style>
</head>
<body>

<!-- ── NAVBAR ── -->
<nav class="navbar">
  <a href="teacher-dashboard.php" class="navbar-brand">
    <img src="prepaura-logo.png" alt="PREPAURA" class="brand-logo-img">
    <div class="brand-text-group">
      <span class="brand-name">PREPAURA</span>
      <span class="brand-tagline">Placement Training Platform</span>
    </div>
  </a>

  <div class="nav-center">
    <div class="search-input-wrap">
      <input type="text" class="search-input" id="navSearchInput" placeholder="Search assessments…" autocomplete="off">
      <i class="fa fa-search search-icon"></i>
    </div>
    <div class="date-box">
      <i class="fa fa-calendar-days" style="color:rgba(255,255,255,0.35);font-size:12px"></i>
      <label for="dateFrom">From</label>
      <input type="date" id="dateFrom">
    </div>
    <div class="date-box">
      <i class="fa fa-calendar-days" style="color:rgba(255,255,255,0.35);font-size:12px"></i>
      <label for="dateTo">To</label>
      <input type="date" id="dateTo">
    </div>
  </div>

  <div class="nav-right">
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
      </button>

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
          <button class="dropdown-item danger" onclick="handleLogout()"><i class="fa fa-right-from-bracket"></i> Logout</button>
        </div>
      </div>
    </div>
  </div>
</nav>

<!-- ── PAGE WRAPPER ── -->
<div class="page-wrapper">

  <!-- Sidebar -->
  <aside class="left-sidebar">
    <span class="sidebar-section-label">Navigation</span>
    <a href="teacher-dashboard.php"   class="sidebar-link"><i class="fa fa-house"></i> Dashboard</a>
    <a href="teacher-assessments.php" class="sidebar-link active"><i class="fa fa-clipboard-list"></i> Assessments</a>
    <a href="api/groups/manage-groups.php"       class="sidebar-link"><i class="fa fa-users"></i> Manage Groups</a>
    <a href="teacher-resources.php"   class="sidebar-link"><i class="fa fa-folder-open"></i> Resources</a>

    <span class="sidebar-section-label">Filter by Category</span>
    <a href="#" class="sidebar-link" id="cat-all"       onclick="setSidebarCat('all',this);return false;"><i class="fa fa-layer-group"></i> All Tests</a>
    <a href="#" class="sidebar-link" id="cat-aptitude"  onclick="setSidebarCat('aptitude',this);return false;"><i class="fa fa-calculator" style="color:#38bdf8"></i> Aptitude</a>
    <a href="#" class="sidebar-link" id="cat-technical" onclick="setSidebarCat('technical',this);return false;"><i class="fa fa-microchip"  style="color:#c084fc"></i> Technical</a>
    <a href="#" class="sidebar-link" id="cat-coding"    onclick="setSidebarCat('coding',this);return false;"><i class="fa fa-code"        style="color:#10b981"></i> Coding</a>
    <a href="#" class="sidebar-link" id="cat-reasoning" onclick="setSidebarCat('reasoning',this);return false;"><i class="fa fa-brain"       style="color:#f59e0b"></i> Reasoning</a>
    <a href="#" class="sidebar-link" id="cat-english"   onclick="setSidebarCat('english',this);return false;"><i class="fa fa-book"        style="color:#f43f5e"></i> English</a>
    <a href="#" class="sidebar-link" id="cat-general"   onclick="setSidebarCat('general',this);return false;"><i class="fa fa-globe"       style="color:#7c3aed"></i> General</a>

    <div class="sidebar-bottom">
      <button onclick="handleLogout()" class="sidebar-logout"><i class="fa fa-right-from-bracket"></i> Logout</button>
    </div>
  </aside>

  <!-- Content -->
  <div class="page-content">

    <!-- Page Header -->
    <div class="page-header">
      <div class="page-header-left">
        <div class="page-header-label">Manage</div>
        <h1>My Assessments</h1>
        <p>Create, manage and track student performance on your assessments.</p>
      </div>
      <a href="create-assessment.php" class="btn-create">
        <i class="fa fa-plus"></i> New Assessment
      </a>
    </div>

    <!-- Summary Cards -->
    <div class="summary-row">
      <div class="summary-card <?= $activeFilter === 'all'       ? 'active-filter' : '' ?>" onclick="setFilter('all')">
        <div class="summary-icon">📋</div>
        <div><div class="summary-number"><?= $totalAll ?></div><div class="summary-label">Total Created</div></div>
      </div>
      <div class="summary-card <?= $activeFilter === 'published' ? 'active-filter' : '' ?>" onclick="setFilter('published')">
        <div class="summary-icon">✅</div>
        <div><div class="summary-number"><?= $totalPublished ?></div><div class="summary-label">Published</div></div>
      </div>
      <div class="summary-card <?= $activeFilter === 'draft'     ? 'active-filter' : '' ?>" onclick="setFilter('draft')">
        <div class="summary-icon">✏️</div>
        <div><div class="summary-number"><?= $totalDraft ?></div><div class="summary-label">Drafts</div></div>
      </div>
      <div class="summary-card <?= $activeFilter === 'archived'  ? 'active-filter' : '' ?>" onclick="setFilter('archived')">
        <div class="summary-icon">📦</div>
        <div><div class="summary-number"><?= $totalArchived ?></div><div class="summary-label">Archived</div></div>
      </div>
    </div>

    <!-- Assessment Cards -->
    <div class="assessments-grid" id="assessmentsGrid">

      <?php if ($assessmentError): ?>
        <div class="state-message state-error">
          <div class="state-icon">⚠️</div>
          <h3>Failed to load assessments</h3>
          <p>There was a problem fetching your assessments. Please refresh the page.</p>
        </div>

      <?php elseif (empty($assessments)): ?>
        <div class="state-message state-empty">
          <div class="state-icon">📭</div>
          <h3>No assessments yet</h3>
          <p>Click "New Assessment" to create your first assessment.</p>
          <a href="create-assessment.php" class="btn-create" style="margin-top:8px;">
            <i class="fa fa-plus"></i> New Assessment
          </a>
        </div>

      <?php else: foreach ($assessments as $a):
        $id                = (int) $a['assessment_id'];
        $studentsCompleted = (int) ($a['students_completed'] ?? 0);
        $studentsAttempted = (int) ($a['students_attempted'] ?? 0);
        $avgScore          = $a['avg_score'] !== null ? (float) $a['avg_score'] : null;
        $status            = $a['status'] ?? 'draft';
        $cardExtraClass    = $status === 'draft' ? 'draft-card' : ($status === 'archived' ? 'archived-card' : '');
        $jsCategory        = htmlspecialchars($a['category'] ?? '');
        $jsStatus          = htmlspecialchars($status);
        $jsTitle           = htmlspecialchars(strtolower($a['title']));
        $jsCreated         = !empty($a['created_at']) ? strtotime($a['created_at']) : 0;
        $barClass = 'good';
        if ($avgScore !== null) { if ($avgScore < 40) $barClass = 'low'; elseif ($avgScore < 70) $barClass = 'avg'; }
      ?>
        <div class="assessment-card <?= $cardExtraClass ?>"
             data-id="<?= $id ?>" data-category="<?= $jsCategory ?>"
             data-status="<?= $jsStatus ?>" data-title="<?= $jsTitle ?>" data-created="<?= $jsCreated ?>">

          <!-- Header -->
          <div class="card-header">
            <div class="card-title-group">
              <div class="card-title"><?= htmlspecialchars($a['title']) ?></div>
              <div class="card-meta-sub">
                Created <?= timeAgo($a['created_at']) ?>
                <?php if (!empty($a['category'])): ?> · <?= htmlspecialchars(ucfirst($a['category'])) ?><?php endif; ?>
              </div>
            </div>
            <div class="card-badges">
              <span class="badge badge-<?= htmlspecialchars($a['difficulty'] ?? 'medium') ?>">
                <span class="badge-dot"></span><?= htmlspecialchars(ucfirst($a['difficulty'] ?? 'medium')) ?>
              </span>
              <span class="badge badge-<?= $status ?>">
                <span class="badge-dot"></span><?= ucfirst($status) ?>
              </span>
            </div>
          </div>

          <!-- Meta row -->
          <div class="card-meta">
            <div class="meta-item"><i class="fa fa-circle-question"></i><?= (int)$a['question_count'] ?> Questions</div>
            <div class="meta-item"><i class="fa fa-clock"></i><?= (int)$a['duration_minutes'] ?> Minutes</div>
            <div class="meta-item"><i class="fa fa-trophy"></i><?= (int)$a['total_marks'] ?> Points</div>
            <div class="meta-item"><i class="fa fa-rotate"></i><?= (int)$a['max_attempts'] ?> Max Attempts</div>
            <?php if (!empty($a['end_time'])): ?>
            <div class="meta-item"><i class="fa fa-calendar"></i>Ends <?= date('d M Y', strtotime($a['end_time'])) ?></div>
            <?php endif; ?>
          </div>

          <!-- Student stats -->
          <div class="card-stats">
            <div class="stat-box">
              <div class="stat-box-num"><?= $studentsAttempted ?></div>
              <div class="stat-box-lbl">Attempted</div>
            </div>
            <div class="stat-box">
              <div class="stat-box-num"><?= $studentsCompleted ?></div>
              <div class="stat-box-lbl">Completed</div>
            </div>
            <div class="stat-box">
              <div class="stat-box-num"><?= $studentsAttempted > 0 ? round(($studentsCompleted / max(1,$studentsAttempted)) * 100) : 0 ?>%</div>
              <div class="stat-box-lbl">Completion Rate</div>
            </div>
            <?php if ($avgScore !== null): ?>
            <div class="score-section">
              <div class="score-label">
                <span>Class Avg. Score</span>
                <span><?= number_format($avgScore, 1) ?>%</span>
              </div>
              <div class="score-bar">
                <div class="score-bar-fill <?= $barClass ?>" style="width:<?= min(100, $avgScore) ?>%"></div>
              </div>
            </div>
            <?php else: ?>
            <div class="stat-box">
              <div class="stat-box-num" style="color:var(--text-3)">—</div>
              <div class="stat-box-lbl">Avg. Score</div>
            </div>
            <?php endif; ?>
          </div>

          <!-- Actions -->
          <div class="card-actions">
            <a href="edit-assessment.php?id=<?= $id ?>" class="btn-action btn-edit">
              <i class="fa fa-pen"></i> Edit
            </a>
            <?php if ($status === 'draft'): ?>
            <button class="btn-action btn-publish" onclick="publishAssessment(<?= $id ?>)">
              <i class="fa fa-upload"></i> Publish
            </button>
            <?php endif; ?>
            <button class="btn-action btn-results" onclick="viewResults(<?= $id ?>, <?= htmlspecialchars(json_encode($a['title'])) ?>)">
              <i class="fa fa-chart-bar"></i> Student Results
            </button>
            <button class="btn-action btn-download" onclick="downloadResults(<?= $id ?>, 'csv')">
              <i class="fa fa-download"></i> CSV
            </button>
            <button class="btn-action btn-print" onclick="printResults(<?= $id ?>)">
              <i class="fa fa-print"></i> Print
            </button>
            <button class="btn-action btn-delete" onclick="deleteAssessment(<?= $id ?>, <?= htmlspecialchars(json_encode($a['title'])) ?>)">
              <i class="fa fa-trash"></i> Delete
            </button>
          </div>

        </div>
      <?php endforeach; endif; ?>

      <div class="state-message state-empty hidden" id="noResultsState">
        <div class="state-icon">🔍</div>
        <h3>No assessments found</h3>
        <p>Try adjusting your search or filter.</p>
      </div>

    </div><!-- /.assessments-grid -->
  </div><!-- /.page-content -->
</div><!-- /.page-wrapper -->

<!-- ── RESULTS MODAL ── -->
<div class="modal-overlay" id="resultsModal">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="modalTitle">Student Results</div>
        <div class="modal-subtitle" id="modalSubtitle">Loading...</div>
      </div>
      <button class="modal-close" onclick="closeResultsModal()">✕</button>
    </div>
    <div class="modal-toolbar">
      <input type="text" id="modalSearch" placeholder="Search student name or email…" oninput="filterModalTable()">
      <select id="modalStatusFilter" onchange="filterModalTable()">
        <option value="all">All Results</option>
        <option value="pass">Passed</option>
        <option value="fail">Failed</option>
      </select>
      <button class="btn-modal-action btn-csv" onclick="downloadCurrentCSV()"><i class="fa fa-file-csv"></i> CSV</button>
      <button class="btn-modal-action btn-pdf-dl" onclick="downloadCurrentPDF()"><i class="fa fa-file-pdf"></i> PDF</button>
      <button class="btn-modal-action btn-modal-print" onclick="printModal()"><i class="fa fa-print"></i> Print</button>
    </div>
    <div class="modal-body" id="modalBody">
      <div style="padding:48px;text-align:center;color:var(--text-3);font-size:14px;">
        <div style="font-size:40px;margin-bottom:14px;opacity:0.4">⏳</div>Loading results…
      </div>
    </div>
    <div class="modal-footer" id="modalFooter"></div>
  </div>
</div>

<script>
const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;
let currentModalAssessmentId = null;
let currentModalTitle = '';

// ── Profile dropdown ──
const profileBtn  = document.getElementById('profileBtn');
const profileDrop = document.getElementById('profileDropdown');
profileBtn.addEventListener('click', e => { e.stopPropagation(); profileDrop.classList.toggle('open'); });
document.addEventListener('click', () => profileDrop.classList.remove('open'));

// ── Logout ──
function handleLogout() {
  if (confirm('Are you sure you want to logout?')) window.location.href = 'logout.php';
}

// ── Category sidebar filter ──
const catIds = ['cat-all','cat-aptitude','cat-technical','cat-coding','cat-reasoning','cat-english','cat-general'];
let currentCategory = 'all';
let currentFilter   = '<?= $activeFilter ?>';
let currentSearch   = '';
let dateFrom = '', dateTo = '';

function setSidebarCat(cat, el) {
  currentCategory = cat;
  catIds.forEach(id => document.getElementById(id)?.classList.remove('active'));
  el.classList.add('active');
  applyFilters();
}
document.getElementById('cat-all').classList.add('active');

function setFilter(f) {
  currentFilter = f;
  document.querySelectorAll('.summary-card').forEach((c, i) => {
    const filters = ['all','published','draft','archived'];
    c.classList.toggle('active-filter', filters[i] === f);
  });
  applyFilters();
}

document.getElementById('navSearchInput').addEventListener('input', e => {
  currentSearch = e.target.value.toLowerCase();
  applyFilters();
});
document.getElementById('dateFrom').addEventListener('change', e => { dateFrom = e.target.value; applyFilters(); });
document.getElementById('dateTo').addEventListener('change',   e => { dateTo   = e.target.value; applyFilters(); });

function applyFilters() {
  const grid  = document.getElementById('assessmentsGrid');
  const cards = [...grid.querySelectorAll('.assessment-card')];
  const noRes = document.getElementById('noResultsState');
  let visible = 0;
  cards.forEach(card => {
    const cat     = card.dataset.category;
    const status  = card.dataset.status;
    const title   = card.dataset.title;
    const created = card.dataset.created > 0 ? new Date(card.dataset.created * 1000).toISOString().slice(0,10) : '';
    const matchCat    = currentCategory === 'all' || cat === currentCategory;
    const matchStatus = currentFilter   === 'all' || status === currentFilter;
    const matchSearch = !currentSearch  || title.includes(currentSearch);
    const matchFrom   = !dateFrom || !created || created >= dateFrom;
    const matchTo     = !dateTo   || !created || created <= dateTo;
    const show = matchCat && matchStatus && matchSearch && matchFrom && matchTo;
    card.classList.toggle('hidden', !show);
    if (show) visible++;
  });
  noRes.classList.toggle('hidden', visible > 0);
}

// ── Publish — opens assign modal first ──
function publishAssessment(id) {
  currentPublishId = id;
  // Reset modal state
  document.querySelectorAll('.pub-group-cb').forEach(cb => cb.checked = false);
  document.querySelectorAll('.pub-student-row').forEach(r => r.querySelector('input[type=checkbox]').checked = false);
  document.getElementById('pubStudentSearch').value = '';
  filterPubStudents('');
  document.getElementById('publishModal').style.display = 'flex';
}

let currentPublishId = null;

async function confirmPublish() {
  if (!currentPublishId) return;
  const targets = [];
  document.querySelectorAll('.pub-group-cb:checked').forEach(cb => {
    targets.push({ type: 'group', id: parseInt(cb.value) });
  });
  document.querySelectorAll('.pub-student-cb:checked').forEach(cb => {
    targets.push({ type: 'student', id: parseInt(cb.value) });
  });
  if (targets.length === 0) {
    alert('Please select at least one group or student to assign this assessment to.');
    return;
  }
  closePublishModal();
  try {
    const res = await fetch('api/assessment/update-status.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
      body: JSON.stringify({ assessment_id: currentPublishId, status: 'published', targets })
    });
    const data = await res.json();
    if (data.success) location.reload();
    else alert('Failed to publish: ' + (data.message || 'Unknown error'));
  } catch { alert('Network error. Please try again.'); }
}

function closePublishModal() {
  document.getElementById('publishModal').style.display = 'none';
  currentPublishId = null;
}

function filterPubStudents(q) {
  q = q.toLowerCase();
  document.querySelectorAll('.pub-student-row').forEach(row => {
    row.style.display = row.dataset.name.includes(q) || row.dataset.email.includes(q) ? '' : 'none';
  });
}

// ── Delete ──
function deleteAssessment(id, title) {
  if (!confirm(`Delete "${title}"?\n\nThis will permanently remove the assessment and all associated data.`)) return;
  fetch('api/assessment/delete.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
    body: JSON.stringify({ assessment_id: id })
  }).then(r => r.json()).then(data => {
    if (data.success) {
      const card = document.querySelector(`.assessment-card[data-id="${id}"]`);
      if (card) { card.style.transition = 'opacity .3s'; card.style.opacity = '0'; setTimeout(() => card.remove(), 300); }
    } else alert('Failed to delete: ' + (data.message || 'Unknown error'));
  }).catch(() => alert('Network error. Please try again.'));
}

// ── Results Modal ──
function viewResults(id, title) {
  currentModalAssessmentId = id;
  currentModalTitle = title;
  document.getElementById('modalTitle').textContent = title;
  document.getElementById('modalSubtitle').textContent = 'Loading student results...';
  document.getElementById('modalBody').innerHTML = '<div style="padding:48px;text-align:center;color:var(--text-3);font-size:14px;"><div style="font-size:40px;margin-bottom:14px;opacity:0.4">⏳</div>Loading results…</div>';
  document.getElementById('resultsModal').classList.add('show');
  document.getElementById('modalSearch').value = '';
  document.getElementById('modalStatusFilter').value = 'all';
  fetch(`api/assessment/results.php?assessment_id=${id}`)
    .then(r => r.json())
    .then(data => {
      if (!data.success) throw new Error(data.message || 'Failed to load');
      renderResultsTable(data.results, data.meta);
    })
    .catch(err => {
      document.getElementById('modalBody').innerHTML = `<div style="padding:48px;text-align:center;color:var(--rose);"><div style="font-size:40px;margin-bottom:14px;">⚠️</div><strong>Failed to load results</strong><br><small>${err.message}</small></div>`;
    });
}

function renderResultsTable(results, meta) {
  document.getElementById('modalSubtitle').textContent =
    `${meta?.total_students ?? results.length} students · Avg: ${meta?.avg_score ?? '—'}% · Pass rate: ${meta?.pass_rate ?? '—'}%`;
  document.getElementById('modalFooter').textContent =
    `Showing ${results.length} result(s) · Pass mark: ${meta?.passing_marks ?? '—'} / ${meta?.total_marks ?? '—'} points`;
  if (!results.length) {
    document.getElementById('modalBody').innerHTML = '<div style="padding:48px;text-align:center;color:var(--text-3);font-size:14px;"><div style="font-size:40px;margin-bottom:14px;opacity:0.4">📭</div>No students have attempted this assessment yet.</div>';
    return;
  }
  results.sort((a, b) => b.percentage - a.percentage);
  let rows = '';
  results.forEach((r, i) => {
    const pass = r.percentage >= (meta?.pass_percentage ?? 40);
    rows += `<tr data-name="${(r.student_name||'').toLowerCase()}" data-email="${(r.email||'').toLowerCase()}" data-result="${pass?'pass':'fail'}">
      <td class="result-rank">#${i+1}</td>
      <td><div class="result-name">${escHtml(r.student_name || '—')}</div><div class="result-email">${escHtml(r.email || '')}</div></td>
      <td>${escHtml(r.department || '—')}</td>
      <td>
        <div class="result-score ${pass?'pass':'fail'}">${Number(r.percentage).toFixed(1)}%</div>
        <div class="result-mini-bar"><div class="result-mini-fill ${pass?'pass':'fail'}" style="width:${Math.min(100,r.percentage)}%"></div></div>
      </td>
      <td>${r.score ?? '—'} / ${meta?.total_marks ?? '—'}</td>
      <td>${pass ? '<span style="color:var(--emerald);font-weight:700;">✅ Pass</span>' : '<span style="color:var(--rose);font-weight:700;">❌ Fail</span>'}</td>
      <td style="font-size:12px;color:var(--text-3);">${r.submitted_at ? new Date(r.submitted_at).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}) : '—'}</td>
      <td><a href="assessment-results.php?id=${currentModalAssessmentId}" style="color:var(--violet);font-size:12px;font-weight:600;text-decoration:none;">View →</a></td>
    </tr>`;
  });
  document.getElementById('modalBody').innerHTML = `
    <table class="results-table" id="resultsTable">
      <thead><tr><th>#</th><th>Student</th><th>Department</th><th>Score %</th><th>Marks</th><th>Result</th><th>Date</th><th>Details</th></tr></thead>
      <tbody id="resultsTableBody">${rows}</tbody>
    </table>`;
}

function filterModalTable() {
  const search = document.getElementById('modalSearch').value.toLowerCase();
  const status = document.getElementById('modalStatusFilter').value;
  document.querySelectorAll('#resultsTableBody tr').forEach(row => {
    const matchSearch = !search || (row.dataset.name||'').includes(search) || (row.dataset.email||'').includes(search);
    const matchStatus = status === 'all' || row.dataset.result === status;
    row.style.display = matchSearch && matchStatus ? '' : 'none';
  });
}

function closeResultsModal() {
  document.getElementById('resultsModal').classList.remove('show');
  currentModalAssessmentId = null;
}
document.getElementById('resultsModal').addEventListener('click', function(e) {
  if (e.target === this) closeResultsModal();
});

function downloadResults(id, type) { window.location.href = `api/assessment/export-results.php?assessment_id=${id}&format=${type}`; }
function downloadCurrentCSV()      { if (currentModalAssessmentId) downloadResults(currentModalAssessmentId, 'csv'); }
function downloadCurrentPDF()      { if (currentModalAssessmentId) window.location.href = `api/assessment/export-results.php?assessment_id=${currentModalAssessmentId}&format=pdf`; }

function printResults(id) {
  const w = window.open(`api/assessment/export-results.php?assessment_id=${id}&format=print`, '_blank');
  if (w) w.focus();
}
function printModal() {
  const content = document.getElementById('modalBody')?.innerHTML;
  const title   = currentModalTitle;
  const w = window.open('', '_blank');
  if (!w) return;
  w.document.write(`<!DOCTYPE html><html><head>
    <title>Results - ${escHtml(title)}</title>
    <style>
      body { font-family: 'DM Sans', sans-serif; font-size: 13px; color: #1a1425; padding: 20px; }
      h1 { font-size: 18px; margin-bottom: 4px; } p { color: #8b7fa8; font-size: 12px; margin-bottom: 20px; }
      table { width: 100%; border-collapse: collapse; }
      th { background: #f7f5fb; padding: 10px 12px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; border-bottom: 2px solid rgba(124,58,237,0.1); }
      td { padding: 10px 12px; border-bottom: 1px solid #ede9f6; }
      .pass { color: #10b981; font-weight: 700; } .fail { color: #f43f5e; font-weight: 700; }
      .result-mini-bar, a[href] { display: none; }
    </style>
  </head><body>
    <h1>📋 ${escHtml(title)}</h1>
    <p>Printed on ${new Date().toLocaleDateString('en-GB',{day:'2-digit',month:'long',year:'numeric'})}</p>
    ${content}
  </body></html>`);
  w.document.close();
  setTimeout(() => { w.print(); }, 400);
}

function escHtml(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

window.addEventListener('load', () => {
  document.querySelectorAll('.score-bar-fill').forEach(bar => {
    const w = bar.style.width; bar.style.width = '0';
    setTimeout(() => { bar.style.width = w; }, 150);
  });
  const cat = '<?= $activeCategory ?>';
  if (cat !== 'all') {
    currentCategory = cat;
    const el = document.getElementById('cat-' + cat);
    if (el) { catIds.forEach(id => document.getElementById(id)?.classList.remove('active')); el.classList.add('active'); }
    applyFilters();
  }
});
</script>

<!-- ── Publish & Assign Modal ── -->
<div id="publishModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:2000;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:16px;width:100%;max-width:520px;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 8px 40px rgba(0,0,0,0.18);overflow:hidden;">
    <div style="padding:22px 24px 16px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;">
      <div>
        <div style="font-family:'Sora',sans-serif;font-size:17px;font-weight:700;color:#0f172a;">Assign & Publish</div>
        <div style="font-size:13px;color:#64748b;margin-top:2px;">Select who can see this assessment</div>
      </div>
      <button onclick="closePublishModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#94a3b8;line-height:1;">✕</button>
    </div>
    <div style="overflow-y:auto;flex:1;padding:20px 24px;">

      <?php if (!empty($pubGroups)): ?>
      <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin-bottom:10px;">👥 Groups</div>
      <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:20px;">
        <?php foreach ($pubGroups as $g): ?>
        <label style="display:flex;align-items:center;gap:10px;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:10px;cursor:pointer;transition:.15s;" onmouseover="this.style.borderColor='#7c3aed'" onmouseout="this.style.borderColor='#e2e8f0'">
          <input type="checkbox" class="pub-group-cb" value="<?= (int)$g['group_id'] ?>" style="accent-color:#7c3aed;width:16px;height:16px;">
          <span style="font-size:14px;font-weight:600;color:#0f172a;"><?= htmlspecialchars($g['name']) ?></span>
        </label>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin-bottom:10px;">🎓 Individual Students</div>
      <input type="text" id="pubStudentSearch" placeholder="Search by name or email…" oninput="filterPubStudents(this.value)"
        style="width:100%;padding:9px 14px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:13px;margin-bottom:10px;outline:none;font-family:'Inter',sans-serif;">
      <div style="max-height:220px;overflow-y:auto;display:flex;flex-direction:column;gap:6px;">
        <?php foreach ($pubStudents as $s): ?>
        <label class="pub-student-row" data-name="<?= strtolower(htmlspecialchars($s['full_name'])) ?>" data-email="<?= strtolower(htmlspecialchars($s['email'])) ?>"
          style="display:flex;align-items:center;gap:10px;padding:9px 14px;border:1.5px solid #e2e8f0;border-radius:9px;cursor:pointer;transition:.15s;" onmouseover="this.style.borderColor='#7c3aed'" onmouseout="this.style.borderColor='#e2e8f0'">
          <input type="checkbox" class="pub-student-cb" value="<?= (int)$s['user_id'] ?>" style="accent-color:#7c3aed;width:16px;height:16px;">
          <div>
            <div style="font-size:13.5px;font-weight:600;color:#0f172a;"><?= htmlspecialchars($s['full_name']) ?></div>
            <div style="font-size:11.5px;color:#64748b;"><?= htmlspecialchars($s['email']) ?><?= $s['department'] ? ' · ' . htmlspecialchars($s['department']) : '' ?></div>
          </div>
        </label>
        <?php endforeach; ?>
        <?php if (empty($pubStudents)): ?>
        <div style="text-align:center;padding:24px;color:#94a3b8;font-size:13px;">No students found.</div>
        <?php endif; ?>
      </div>
    </div>
    <div style="padding:16px 24px;border-top:1px solid #e2e8f0;display:flex;gap:10px;justify-content:flex-end;">
      <button onclick="closePublishModal()" style="padding:10px 20px;border:1.5px solid #e2e8f0;border-radius:9px;background:#fff;font-size:13.5px;font-weight:600;cursor:pointer;color:#475569;">Cancel</button>
      <button onclick="confirmPublish()" style="padding:10px 24px;background:#7c3aed;color:#fff;border:none;border-radius:9px;font-size:13.5px;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(124,58,237,0.3);">🚀 Publish</button>
    </div>
  </div>
</div>

</body>
</html>

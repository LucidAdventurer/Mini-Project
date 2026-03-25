<?php
/* ========================================
 * MANAGE GROUPS
 * File: manage-groups.php
 * ======================================== */
require 'config.php';
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

$groups = [];
$rg = safePreparedQuery($conn,
    "SELECT g.group_id, g.name, g.description, g.created_at,
            COUNT(gm.student_id) AS member_count
     FROM groups g
     LEFT JOIN group_members gm ON gm.group_id = g.group_id
     WHERE g.teacher_id = ?
     GROUP BY g.group_id
     ORDER BY g.created_at DESC",
    "i", [$teacherId]
);
if ($rg['success'] && $rg['result']) {
    while ($row = $rg['result']->fetch_assoc()) $groups[] = $row;
    $rg['result']->free();
}

$students = [];
$rs = safePreparedQuery($conn,
    "SELECT user_id, full_name, email, department, registration_number
     FROM users WHERE role = 'student' AND is_active = 1 ORDER BY full_name ASC",
    "", []
);
if ($rs['success'] && $rs['result']) {
    while ($row = $rs['result']->fetch_assoc()) $students[] = $row;
    $rs['result']->free();
}

function fmtDate(?string $dt): string {
    if (!$dt) return '—';
    return date('M j, Y', strtotime($dt));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Groups — PREPAURA</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">
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
  font-family: var(--font-body); background: var(--surface);
  color: var(--text-1); min-height: 100vh;
  padding-top: var(--nav-h); overflow-x: hidden;
}
body::before {
  content: ''; position: fixed; inset: 0; z-index: 0; pointer-events: none;
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.03'/%3E%3C/svg%3E");
  background-size: 200px 200px;
}

/* ── NAVBAR ── */
.navbar {
  height: var(--nav-h); background: rgba(13,10,20,0.96);
  backdrop-filter: blur(20px) saturate(1.6); -webkit-backdrop-filter: blur(20px) saturate(1.6);
  border-bottom: 1px solid rgba(255,255,255,0.06); padding: 0 28px;
  display: flex; align-items: center; justify-content: space-between; gap: 20px;
  position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
}
.navbar-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; flex-shrink: 0; }
.brand-logo-img { width: 36px; height: 36px; border-radius: 9px; object-fit: contain; background: white; padding: 3px; }
.brand-text-group { display: flex; flex-direction: column; line-height: 1.15; }
.brand-name { font-family: var(--font-head); font-size: 16px; font-weight: 800; letter-spacing: 0.06em; color: white; }
.brand-tagline { font-size: 10px; color: rgba(255,255,255,0.45); letter-spacing: 0.03em; }

.nav-right { display: flex; align-items: center; gap: 12px; }
.profile-wrap { position: relative; }
.profile-button {
  display: flex; align-items: center; gap: 9px; padding: 6px 12px 6px 6px;
  background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,0.1);
  border-radius: 40px; cursor: pointer; transition: var(--t); color: white;
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
  box-shadow: var(--shadow-lg), 0 0 0 1px var(--border); min-width: 230px;
  opacity: 0; visibility: hidden; transform: translateY(-6px) scale(0.98);
  transition: var(--t); z-index: 1001; overflow: hidden;
}
.profile-dropdown.open { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
.dropdown-header {
  padding: 18px 20px; text-align: left;
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
.dropdown-name  { font-weight: 600; font-size: 14px; color: white; }
.dropdown-email { font-size: 12px; color: rgba(255,255,255,0.5); margin-top: 2px; }
.dropdown-role  {
  display: inline-block; margin-top: 8px; padding: 2px 10px;
  background: var(--violet-dim); border: 1px solid rgba(124,58,237,0.3);
  color: var(--orchid); border-radius: 20px; font-size: 11px; font-weight: 600;
  letter-spacing: 0.04em; text-transform: uppercase;
}
.dropdown-menu { padding: 6px 0; }
.dropdown-item {
  display: flex; align-items: center; gap: 11px; padding: 10px 18px;
  color: var(--text-2); text-decoration: none; font-size: 13.5px; transition: var(--t);
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
  border-right: 1px solid var(--border); min-height: calc(100vh - var(--nav-h));
  position: sticky; top: var(--nav-h); align-self: flex-start;
}
.sidebar-section-label {
  font-family: var(--font-head); font-size: 10px; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.1em; color: var(--text-3); padding: 14px 14px 6px;
}
.sidebar-link {
  display: flex; align-items: center; gap: 10px; padding: 10px 14px;
  border-radius: var(--r-sm); text-decoration: none; font-size: 13.5px;
  font-weight: 500; color: var(--text-2); transition: var(--t);
}
.sidebar-link i { width: 18px; text-align: center; font-size: 14px; color: var(--text-3); transition: var(--t); }
.sidebar-link:hover { background: var(--violet-dim); color: var(--violet); }
.sidebar-link:hover i { color: var(--violet); }
.sidebar-link.active { background: linear-gradient(135deg, rgba(124,58,237,0.12), rgba(192,132,252,0.08)); color: var(--violet); font-weight: 600; box-shadow: inset 3px 0 0 var(--violet); }
.sidebar-link.active i { color: var(--violet); }
.sidebar-bottom { margin-top: auto; padding-top: 16px; border-top: 1px solid var(--border); }
.sidebar-logout {
  display: flex; align-items: center; gap: 10px; padding: 10px 14px;
  border-radius: var(--r-sm); font-size: 13.5px; font-weight: 500; color: var(--rose);
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
.page-header-left h1 { font-family: var(--font-head); font-size: 26px; font-weight: 800; color: white; margin-bottom: 4px; }
.page-header-left p { font-size: 14px; color: rgba(255,255,255,0.55); }
.btn-create-group {
  display: inline-flex; align-items: center; gap: 8px; padding: 11px 22px;
  border-radius: var(--r-sm); background: rgba(255,255,255,0.15);
  backdrop-filter: blur(8px); border: 1px solid rgba(255,255,255,0.2);
  color: white; font-weight: 600; font-size: 13.5px; cursor: pointer;
  text-decoration: none; transition: var(--t); font-family: var(--font-body);
  position: relative; z-index: 1;
}
.btn-create-group:hover { background: rgba(255,255,255,0.25); transform: translateY(-1px); }

/* ── TWO-COLUMN ── */
.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
@media(max-width: 960px) { .two-col { grid-template-columns: 1fr; } }

/* ── PANEL ── */
.panel {
  background: var(--surface-3); border: 1px solid var(--border);
  border-radius: var(--r-lg); box-shadow: var(--shadow-xs); overflow: hidden;
  position: relative;
}
.panel::after {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
  background: linear-gradient(90deg, var(--violet), var(--orchid));
  border-radius: var(--r-lg) var(--r-lg) 0 0;
}
.panel-head {
  padding: 18px 22px; border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
}
.panel-title {
  font-family: var(--font-head); font-size: 15px; font-weight: 700;
  color: var(--text-1); display: flex; align-items: center; gap: 8px;
}
.panel-count {
  font-size: 11px; background: var(--violet-dim); color: var(--violet);
  padding: 2px 10px; border-radius: 99px; font-weight: 700;
  border: 1px solid var(--border-2);
}
.panel-body { padding: 0; }

/* ── SEARCH BAR ── */
.search-wrap { padding: 14px 18px; border-bottom: 1px solid var(--border); }
.search-inner { position: relative; }
.search-inner input {
  width: 100%; padding: 9px 36px 9px 12px; border: 1px solid var(--border);
  border-radius: var(--r-sm); font-size: 13px; font-family: var(--font-body);
  outline: none; transition: var(--t); background: var(--surface); color: var(--text-1);
}
.search-inner input:focus { border-color: var(--violet); box-shadow: 0 0 0 3px var(--violet-dim); }
.search-inner .s-icon { position: absolute; right: 11px; top: 50%; transform: translateY(-50%); font-size: 13px; color: var(--text-3); }

/* ── STUDENT LIST ── */
.student-list { max-height: 520px; overflow-y: auto; }
.student-item {
  display: flex; align-items: center; gap: 12px; padding: 11px 18px;
  border-bottom: 1px solid var(--surface-2); transition: background var(--t); cursor: pointer;
}
.student-item:last-child { border-bottom: none; }
.student-item:hover { background: var(--violet-dim); }
.student-item.selected { background: rgba(124,58,237,0.08); }
.student-check {
  width: 18px; height: 18px; border-radius: 5px; flex-shrink: 0;
  border: 1.5px solid var(--border-2); display: flex; align-items: center;
  justify-content: center; transition: var(--t); font-size: 11px; color: transparent;
}
.student-item.selected .student-check {
  background: var(--violet); border-color: var(--violet); color: white;
}
.student-avatar {
  width: 34px; height: 34px; border-radius: 50%; flex-shrink: 0;
  background: linear-gradient(135deg, var(--violet), var(--orchid));
  display: flex; align-items: center; justify-content: center;
  color: white; font-size: 12px; font-weight: 700; font-family: var(--font-head);
}
.student-info { flex: 1; min-width: 0; }
.student-name { font-size: 13px; font-weight: 600; color: var(--text-1); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.student-meta { font-size: 11px; color: var(--text-3); margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.select-all-bar {
  padding: 8px 18px; background: var(--surface);
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
}
.select-all-bar button {
  font-size: 12px; font-weight: 600; color: var(--violet);
  border: none; background: none; cursor: pointer; padding: 0; font-family: var(--font-body);
}
.select-all-bar button:hover { color: var(--violet-lt); }
.selected-count { font-size: 12px; color: var(--text-3); }
.panel-footer {
  padding: 14px 18px; border-top: 1px solid var(--border); text-align: right;
}
.btn-create-from-sel {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 9px 18px; background: linear-gradient(135deg, var(--violet), #9333ea);
  color: white; border: none; border-radius: var(--r-sm);
  font-size: 13px; font-weight: 600; cursor: pointer; transition: var(--t);
  font-family: var(--font-body);
}
.btn-create-from-sel:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(124,58,237,0.35); }

/* ── GROUPS LIST ── */
.groups-list { padding: 14px; display: flex; flex-direction: column; gap: 10px; min-height: 100px; }
.empty-groups { text-align: center; padding: 48px 20px; color: var(--text-3); }
.empty-groups .empty-icon { font-size: 36px; margin-bottom: 10px; opacity: 0.4; }
.empty-groups .empty-title { font-family: var(--font-head); font-size: 15px; font-weight: 700; color: var(--text-1); margin-bottom: 4px; }
.empty-groups .empty-sub { font-size: 13px; }

.group-card {
  border: 1px solid var(--border); border-radius: var(--r-md); overflow: hidden;
  transition: box-shadow var(--t), border-color var(--t);
}
.group-card:hover { border-color: var(--border-2); box-shadow: var(--shadow-sm); }
.group-card-head {
  display: flex; align-items: center; gap: 12px; padding: 13px 16px;
  cursor: pointer; user-select: none; background: var(--surface-3);
}
.group-icon {
  width: 38px; height: 38px; border-radius: var(--r-sm); flex-shrink: 0;
  background: linear-gradient(135deg, var(--violet), var(--orchid));
  display: flex; align-items: center; justify-content: center; color: white; font-size: 16px;
}
.group-info { flex: 1; min-width: 0; }
.group-name { font-family: var(--font-head); font-size: 14px; font-weight: 700; color: var(--text-1); }
.group-desc { font-size: 12px; color: var(--text-3); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.group-badge {
  font-size: 11px; font-weight: 700; padding: 3px 10px;
  background: var(--violet-dim); color: var(--violet);
  border: 1px solid var(--border-2); border-radius: 99px; flex-shrink: 0;
}
.group-chevron { color: var(--text-3); font-size: 11px; transition: transform 0.2s; flex-shrink: 0; }
.group-card.expanded .group-chevron { transform: rotate(180deg); }

.group-body { display: none; border-top: 1px solid var(--border); }
.group-card.expanded .group-body { display: block; }
.group-actions-bar {
  display: flex; align-items: center; justify-content: space-between;
  padding: 10px 14px; background: var(--surface); border-bottom: 1px solid var(--border);
  gap: 8px;
}
.group-actions-left { display: flex; gap: 6px; }
.btn-grp {
  font-size: 12px; font-weight: 600; padding: 6px 12px;
  border-radius: var(--r-sm); border: 1px solid transparent; cursor: pointer;
  transition: var(--t); font-family: var(--font-body);
  display: inline-flex; align-items: center; gap: 5px;
}
.btn-edit-group   { background: var(--violet-dim); color: var(--violet); border-color: var(--border-2); }
.btn-edit-group:hover { background: rgba(124,58,237,0.2); }
.btn-delete-group { background: rgba(244,63,94,0.08); color: var(--rose); border-color: rgba(244,63,94,0.2); }
.btn-delete-group:hover { background: rgba(244,63,94,0.15); }
.btn-add-members  { background: rgba(16,185,129,0.08); color: var(--emerald); border-color: rgba(16,185,129,0.25); }
.btn-add-members:hover { background: rgba(16,185,129,0.15); }

.member-list { max-height: 240px; overflow-y: auto; }
.member-item {
  display: flex; align-items: center; gap: 10px; padding: 9px 16px;
  border-bottom: 1px solid var(--surface-2);
}
.member-item:last-child { border-bottom: none; }
.member-avatar {
  width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0;
  background: linear-gradient(135deg, var(--violet), var(--orchid));
  display: flex; align-items: center; justify-content: center;
  color: white; font-size: 11px; font-weight: 700; font-family: var(--font-head);
}
.member-info { flex: 1; min-width: 0; }
.member-name { font-size: 13px; font-weight: 600; color: var(--text-1); }
.member-meta { font-size: 11px; color: var(--text-3); }
.btn-remove-member {
  border: none; background: none; color: var(--text-3); font-size: 16px;
  cursor: pointer; padding: 2px 6px; border-radius: var(--r-sm); transition: var(--t); flex-shrink: 0;
}
.btn-remove-member:hover { background: rgba(244,63,94,0.1); color: var(--rose); }
.member-empty { padding: 16px; text-align: center; font-size: 13px; color: var(--text-3); }

/* ── MODALS ── */
.modal-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(13,10,20,0.6); backdrop-filter: blur(4px);
  z-index: 2000; align-items: center; justify-content: center; padding: 20px;
}
.modal-overlay.open { display: flex; }
.modal {
  background: var(--surface-3); border-radius: var(--r-xl); width: 100%; max-width: 480px;
  box-shadow: var(--shadow-lg), 0 0 0 1px var(--border); overflow: hidden;
  animation: modalIn 0.2s var(--ease);
}
@keyframes modalIn { from { transform: scale(0.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.modal-head {
  padding: 20px 24px 16px; border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
  background: linear-gradient(135deg, var(--ink) 0%, var(--ink-3) 100%);
}
.modal-title { font-family: var(--font-head); font-size: 17px; font-weight: 700; color: white; }
.modal-close {
  background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.15);
  color: white; width: 30px; height: 30px; border-radius: var(--r-sm);
  font-size: 16px; cursor: pointer; display: flex; align-items: center; justify-content: center;
  transition: var(--t);
}
.modal-close:hover { background: rgba(255,255,255,0.2); }
.modal-body { padding: 20px 24px; }
.form-group { margin-bottom: 16px; }
.form-label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: var(--text-2); }
.form-input {
  width: 100%; padding: 10px 12px; border: 1px solid var(--border);
  border-radius: var(--r-sm); font-size: 13px; font-family: var(--font-body);
  outline: none; transition: var(--t); background: var(--surface); color: var(--text-1);
}
.form-input:focus { border-color: var(--violet); box-shadow: 0 0 0 3px var(--violet-dim); }
.form-textarea { resize: vertical; min-height: 72px; }
.modal-footer {
  padding: 14px 24px 20px; display: flex; justify-content: flex-end; gap: 10px;
  border-top: 1px solid var(--border);
}
.btn-cancel {
  padding: 9px 18px; border: 1px solid var(--border); background: var(--surface);
  border-radius: var(--r-sm); font-size: 13px; font-weight: 600; cursor: pointer;
  transition: var(--t); font-family: var(--font-body); color: var(--text-2);
}
.btn-cancel:hover { border-color: var(--violet); color: var(--violet); }
.btn-save {
  padding: 9px 18px; background: linear-gradient(135deg, var(--violet), #9333ea);
  color: white; border: none; border-radius: var(--r-sm);
  font-size: 13px; font-weight: 600; cursor: pointer; transition: var(--t);
  font-family: var(--font-body);
}
.btn-save:hover { box-shadow: 0 4px 14px rgba(124,58,237,0.35); transform: translateY(-1px); }
.btn-save:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
.btn-danger-confirm {
  padding: 9px 18px; background: var(--rose); color: white; border: none;
  border-radius: var(--r-sm); font-size: 13px; font-weight: 600;
  cursor: pointer; transition: var(--t); font-family: var(--font-body);
}
.btn-danger-confirm:hover { background: #e11d48; box-shadow: 0 4px 14px rgba(244,63,94,0.35); }

/* ── TOAST ── */
.toast {
  position: fixed; bottom: 28px; right: 28px; z-index: 3000;
  padding: 12px 20px; border-radius: var(--r-md); font-size: 13px; font-weight: 600;
  box-shadow: var(--shadow-md); opacity: 0; transform: translateY(10px);
  transition: all 0.25s var(--ease); pointer-events: none;
  font-family: var(--font-body);
}
.toast.show { opacity: 1; transform: translateY(0); }
.toast.success { background: rgba(16,185,129,0.1); color: var(--emerald); border: 1px solid rgba(16,185,129,0.25); }
.toast.error   { background: rgba(244,63,94,0.08); color: var(--rose); border: 1px solid rgba(244,63,94,0.2); }

/* ── ADD MEMBERS MODAL ── */
.add-members-search { position: relative; margin-bottom: 10px; }
.add-members-search input {
  width: 100%; padding: 9px 36px 9px 12px; border: 1px solid var(--border);
  border-radius: var(--r-sm); font-size: 13px; font-family: var(--font-body);
  outline: none; transition: var(--t); background: var(--surface); color: var(--text-1);
}
.add-members-search input:focus { border-color: var(--violet); box-shadow: 0 0 0 3px var(--violet-dim); }
.add-members-search .s-icon { position: absolute; right: 11px; top: 50%; transform: translateY(-50%); color: var(--text-3); font-size: 13px; }
.add-members-list { max-height: 280px; overflow-y: auto; border: 1px solid var(--border); border-radius: var(--r-sm); }
.add-member-item {
  display: flex; align-items: center; gap: 10px; padding: 9px 12px;
  border-bottom: 1px solid var(--surface-2); cursor: pointer; transition: background var(--t);
}
.add-member-item:last-child { border-bottom: none; }
.add-member-item:hover { background: var(--violet-dim); }
.add-member-item.selected { background: rgba(124,58,237,0.08); }
.add-member-check {
  width: 17px; height: 17px; border-radius: 4px; flex-shrink: 0;
  border: 1.5px solid var(--border-2); display: flex; align-items: center;
  justify-content: center; font-size: 11px; transition: var(--t); color: transparent;
}
.add-member-item.selected .add-member-check { background: var(--violet); border-color: var(--violet); color: white; }
.add-member-name { font-size: 13px; font-weight: 500; color: var(--text-1); }
.add-member-meta { font-size: 11px; color: var(--text-3); }
.add-members-count { font-size: 12px; color: var(--text-3); margin-bottom: 8px; }
.hidden { display: none !important; }

/* ── RESPONSIVE ── */
@media (max-width: 960px) { .left-sidebar { display: none; } .page-content { padding: 28px 20px; } }
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
          <button onclick="handleLogout()" class="dropdown-item danger"><i class="fa fa-right-from-bracket"></i> Logout</button>
        </div>
      </div>
    </div>
  </div>
</nav>

<!-- ── MAIN ── -->
<div class="page-wrapper">

  <aside class="left-sidebar">
    <span class="sidebar-section-label">Navigation</span>
    <a href="teacher-dashboard.php"   class="sidebar-link"><i class="fa fa-house"></i> Dashboard</a>
    <a href="teacher-assessments.php" class="sidebar-link"><i class="fa fa-clipboard-list"></i> Assessments</a>
    <a href="manage-groups.php"       class="sidebar-link active"><i class="fa fa-users"></i> Manage Groups</a>
    <a href="teacher-resources.php"   class="sidebar-link"><i class="fa fa-folder-open"></i> Resources</a>
    <div class="sidebar-bottom">
      <button onclick="handleLogout()" class="sidebar-logout"><i class="fa fa-right-from-bracket"></i> Logout</button>
    </div>
  </aside>

  <div class="page-content">

    <!-- Page Header -->
    <div class="page-header">
      <div class="page-header-left">
        <div class="page-header-label">Organise</div>
        <h1>Manage Groups</h1>
        <p>Create groups and assign students to control assessment access.</p>
      </div>
      <button class="btn-create-group" onclick="openCreateModal()">
        <i class="fa fa-plus"></i> New Group
      </button>
    </div>

    <div class="two-col">

      <!-- LEFT: All Students -->
      <div class="panel">
        <div class="panel-head">
          <div class="panel-title"><i class="fa fa-graduation-cap" style="color:var(--violet)"></i> All Students <span class="panel-count" id="studentTotalCount"><?= count($students) ?></span></div>
        </div>
        <div class="search-wrap">
          <div class="search-inner">
            <input type="text" id="studentSearch" placeholder="Search by name, email or reg. number…" oninput="filterStudents(this.value)">
            <i class="fa fa-search s-icon"></i>
          </div>
        </div>
        <?php if (!empty($students)): ?>
        <div class="select-all-bar">
          <button onclick="toggleSelectAll()">Select all visible</button>
          <span class="selected-count" id="selectedCount">0 selected</span>
        </div>
        <?php endif; ?>
        <div class="student-list" id="studentList">
          <?php if (empty($students)): ?>
          <div style="padding:40px;text-align:center;color:var(--text-3);">
            <div style="font-size:32px;margin-bottom:8px;opacity:0.4">🎓</div>
            <div style="font-family:var(--font-head);font-weight:700;color:var(--text-1);">No active students yet</div>
          </div>
          <?php else: ?>
          <?php foreach ($students as $s):
            $initials = strtoupper(substr($s['full_name'], 0, 2));
            $meta = array_filter([$s['registration_number'], $s['department']]);
          ?>
          <div class="student-item"
               data-id="<?= $s['user_id'] ?>"
               data-name="<?= htmlspecialchars(strtolower($s['full_name'])) ?>"
               data-email="<?= htmlspecialchars(strtolower($s['email'])) ?>"
               data-reg="<?= htmlspecialchars(strtolower($s['registration_number'] ?? '')) ?>"
               onclick="toggleStudent(this)">
            <div class="student-check">✓</div>
            <div class="student-avatar"><?= $initials ?></div>
            <div class="student-info">
              <div class="student-name"><?= htmlspecialchars($s['full_name']) ?></div>
              <div class="student-meta"><?= htmlspecialchars($s['email']) ?><?= !empty($meta) ? ' · ' . htmlspecialchars(implode(' · ', $meta)) : '' ?></div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <?php if (!empty($students)): ?>
        <div class="panel-footer">
          <button class="btn-create-from-sel" onclick="createGroupFromSelection()">
            <i class="fa fa-plus"></i> Create Group from Selection
          </button>
        </div>
        <?php endif; ?>
      </div>

      <!-- RIGHT: Groups -->
      <div class="panel">
        <div class="panel-head">
          <div class="panel-title"><i class="fa fa-folder-open" style="color:var(--violet)"></i> Your Groups <span class="panel-count" id="groupTotalCount"><?= count($groups) ?></span></div>
          <button class="btn-grp btn-add-members" onclick="openCreateModal()" style="font-size:12px;padding:6px 12px;">
            <i class="fa fa-plus"></i> New Group
          </button>
        </div>
        <div class="panel-body">
          <div class="groups-list" id="groupsList">
            <?php if (empty($groups)): ?>
            <div class="empty-groups">
              <div class="empty-icon">📂</div>
              <div class="empty-title">No groups yet</div>
              <div class="empty-sub">Create a group to organise students and control who can access your assessments.</div>
            </div>
            <?php else: ?>
            <?php foreach ($groups as $g): ?>
            <div class="group-card" id="gcard-<?= $g['group_id'] ?>" data-group-id="<?= $g['group_id'] ?>">
              <div class="group-card-head" onclick="toggleGroup(<?= $g['group_id'] ?>)">
                <div class="group-icon"><i class="fa fa-users"></i></div>
                <div class="group-info">
                  <div class="group-name"><?= htmlspecialchars($g['name']) ?></div>
                  <?php if ($g['description']): ?>
                  <div class="group-desc"><?= htmlspecialchars($g['description']) ?></div>
                  <?php endif; ?>
                </div>
                <span class="group-badge"><?= (int)$g['member_count'] ?> student<?= $g['member_count'] != 1 ? 's' : '' ?></span>
                <i class="fa fa-chevron-down group-chevron"></i>
              </div>
              <div class="group-body" id="gbody-<?= $g['group_id'] ?>">
                <div class="group-actions-bar">
                  <div class="group-actions-left">
                    <button class="btn-grp btn-edit-group" onclick="openEditModal(<?= $g['group_id'] ?>, '<?= htmlspecialchars(addslashes($g['name'])) ?>', '<?= htmlspecialchars(addslashes($g['description'] ?? '')) ?>')">
                      <i class="fa fa-pen"></i> Edit
                    </button>
                    <button class="btn-grp btn-delete-group" onclick="openDeleteModal(<?= $g['group_id'] ?>, '<?= htmlspecialchars(addslashes($g['name'])) ?>')">
                      <i class="fa fa-trash"></i> Delete
                    </button>
                  </div>
                  <button class="btn-grp btn-add-members" onclick="openAddMembersModal(<?= $g['group_id'] ?>)">
                    <i class="fa fa-plus"></i> Add Students
                  </button>
                </div>
                <div class="member-list" id="mlist-<?= $g['group_id'] ?>">
                  <div class="member-empty">Loading…</div>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </div><!-- /two-col -->
  </div><!-- /page-content -->
</div><!-- /page-wrapper -->

<!-- ── CREATE / EDIT MODAL ── -->
<div class="modal-overlay" id="groupModal">
  <div class="modal">
    <div class="modal-head">
      <div class="modal-title" id="groupModalTitle">New Group</div>
      <button class="modal-close" onclick="closeGroupModal()">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="editGroupId" value="">
      <div class="form-group">
        <label class="form-label">Group Name *</label>
        <input type="text" class="form-input" id="groupNameInput" placeholder="e.g. CSE Batch A 2024" maxlength="120">
      </div>
      <div class="form-group">
        <label class="form-label">Description</label>
        <textarea class="form-input form-textarea" id="groupDescInput" placeholder="Optional description…"></textarea>
      </div>
      <div id="preSelectedBlock" style="display:none;">
        <label class="form-label">Pre-selected students</label>
        <div id="preSelectedChips" style="display:flex;flex-wrap:wrap;gap:6px;padding:8px;background:var(--surface);border-radius:var(--r-sm);min-height:36px;border:1px solid var(--border);"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeGroupModal()">Cancel</button>
      <button class="btn-save" id="groupSaveBtn" onclick="saveGroup()">Create Group</button>
    </div>
  </div>
</div>

<!-- ── DELETE CONFIRM MODAL ── -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal">
    <div class="modal-head">
      <div class="modal-title">Delete Group?</div>
      <button class="modal-close" onclick="closeDeleteModal()">✕</button>
    </div>
    <div class="modal-body">
      <p style="font-size:14px;line-height:1.65;color:var(--text-2);" id="deleteModalBody"></p>
    </div>
    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeDeleteModal()">Cancel</button>
      <button class="btn-danger-confirm" id="confirmDeleteBtn">Delete Group</button>
    </div>
  </div>
</div>

<!-- ── ADD MEMBERS MODAL ── -->
<div class="modal-overlay" id="addMembersModal">
  <div class="modal" style="max-width:520px;">
    <div class="modal-head">
      <div class="modal-title">Add Students</div>
      <button class="modal-close" onclick="closeAddMembersModal()">✕</button>
    </div>
    <div class="modal-body">
      <div class="add-members-search">
        <input type="text" id="addMembersSearch" placeholder="Search students…" oninput="filterAddList(this.value)">
        <i class="fa fa-search s-icon"></i>
      </div>
      <div class="add-members-count" id="addMembersCount"></div>
      <div class="add-members-list" id="addMembersList"></div>
    </div>
    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeAddMembersModal()">Cancel</button>
      <button class="btn-save" id="addMembersSaveBtn" onclick="saveAddMembers()">Add Selected</button>
    </div>
  </div>
</div>

<!-- ── TOAST ── -->
<div class="toast" id="toast"></div>

<script>
const ALL_STUDENTS = <?= json_encode(array_map(fn($s) => [
    'user_id'             => (int)$s['user_id'],
    'full_name'           => $s['full_name'],
    'email'               => $s['email'],
    'department'          => $s['department'] ?? '',
    'registration_number' => $s['registration_number'] ?? '',
], $students)) ?>;

// ── CSRF ──
let csrfToken = null;
async function getCsrfToken() {
    if (csrfToken) return csrfToken;
    const res  = await fetch('api/csrf-token.php', { credentials: 'same-origin' });
    const data = await res.json();
    if (!data.success) throw new Error('CSRF fetch failed');
    csrfToken = data.token;
    return csrfToken;
}

// ── Toast ──
let _toastTimer;
function showToast(msg, type = 'success') {
    const el = document.getElementById('toast');
    el.textContent = msg; el.className = `toast ${type}`;
    void el.offsetWidth; el.classList.add('show');
    clearTimeout(_toastTimer);
    _toastTimer = setTimeout(() => el.classList.remove('show'), 3000);
}

// ── Profile dropdown ──
const profileBtn  = document.getElementById('profileBtn');
const profileDrop = document.getElementById('profileDropdown');
profileBtn.addEventListener('click', e => { e.stopPropagation(); profileDrop.classList.toggle('open'); });
document.addEventListener('click', () => profileDrop.classList.remove('open'));

function handleLogout() {
    if (confirm('Are you sure you want to logout?')) window.location.href = 'logout.php';
}

// ── Helpers ──
function esc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function initials(name) { return name.trim().substring(0, 2).toUpperCase(); }

// ── Student list ──
function filterStudents(q) {
    q = q.toLowerCase();
    document.querySelectorAll('#studentList .student-item').forEach(el => {
        const match = !q || el.dataset.name.includes(q) || el.dataset.email.includes(q) || el.dataset.reg.includes(q);
        el.classList.toggle('hidden', !match);
    });
    updateSelectedCount();
}
function toggleStudent(el) { el.classList.toggle('selected'); updateSelectedCount(); }
function toggleSelectAll() {
    const visible = [...document.querySelectorAll('#studentList .student-item:not(.hidden)')];
    const allSel  = visible.every(el => el.classList.contains('selected'));
    visible.forEach(el => el.classList.toggle('selected', !allSel));
    updateSelectedCount();
}
function updateSelectedCount() {
    const count = document.querySelectorAll('#studentList .student-item.selected').length;
    document.getElementById('selectedCount').textContent = `${count} selected`;
}
function getSelectedStudentIds() {
    return [...document.querySelectorAll('#studentList .student-item.selected')].map(el => parseInt(el.dataset.id));
}
function createGroupFromSelection() { openCreateModal(true); }

// ── Group accordion ──
const loadedGroups = new Set();
async function toggleGroup(groupId) {
    const card   = document.getElementById(`gcard-${groupId}`);
    const body   = document.getElementById(`gbody-${groupId}`);
    const isOpen = card.classList.contains('expanded');
    document.querySelectorAll('.group-card.expanded').forEach(c => {
        c.classList.remove('expanded');
        c.querySelector('.group-body').style.display = 'none';
    });
    if (!isOpen) {
        card.classList.add('expanded');
        body.style.display = 'block';
        if (!loadedGroups.has(groupId)) await loadGroupMembers(groupId);
    }
}
async function loadGroupMembers(groupId) {
    const mlist = document.getElementById(`mlist-${groupId}`);
    try {
        const token = await getCsrfToken();
        const res   = await fetch(`api/groups/get-group-details.php?group_id=${groupId}`, {
            credentials: 'same-origin', headers: { 'X-CSRF-Token': token },
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error);
        loadedGroups.add(groupId);
        renderMemberList(groupId, data.group.members);
        const badge = document.querySelector(`#gcard-${groupId} .group-badge`);
        if (badge) badge.textContent = `${data.group.member_count} student${data.group.member_count != 1 ? 's' : ''}`;
    } catch(e) {
        mlist.innerHTML = `<div class="member-empty" style="color:var(--rose);">Failed to load members.</div>`;
    }
}
function renderMemberList(groupId, members) {
    const mlist = document.getElementById(`mlist-${groupId}`);
    if (!members.length) { mlist.innerHTML = '<div class="member-empty">No students in this group yet.</div>'; return; }
    mlist.innerHTML = members.map(m => `
        <div class="member-item" id="mi-${groupId}-${m.user_id}">
            <div class="member-avatar">${esc(initials(m.full_name))}</div>
            <div class="member-info">
                <div class="member-name">${esc(m.full_name)}</div>
                <div class="member-meta">${esc(m.email)}${m.registration_number ? ' · ' + esc(m.registration_number) : ''}</div>
            </div>
            <button class="btn-remove-member" title="Remove from group"
                    onclick="removeMember(${groupId}, ${m.user_id}, '${esc(m.full_name)}')">×</button>
        </div>`).join('');
}

// ── Create / Edit modal ──
let _isEdit = false;
function openCreateModal(fromSelection = false) {
    _isEdit = false;
    document.getElementById('editGroupId').value        = '';
    document.getElementById('groupNameInput').value     = '';
    document.getElementById('groupDescInput').value     = '';
    document.getElementById('groupModalTitle').textContent = 'New Group';
    document.getElementById('groupSaveBtn').textContent    = 'Create Group';
    const block  = document.getElementById('preSelectedBlock');
    const chips  = document.getElementById('preSelectedChips');
    const selIds = getSelectedStudentIds();
    if (fromSelection && selIds.length > 0) {
        const selStudents = ALL_STUDENTS.filter(s => selIds.includes(s.user_id));
        chips.innerHTML   = selStudents.map(s =>
            `<span style="font-size:12px;background:var(--violet-dim);color:var(--violet);padding:3px 10px;border-radius:99px;border:1px solid var(--border-2);">${esc(s.full_name)}</span>`
        ).join('');
        block.style.display = '';
    } else {
        block.style.display = 'none'; chips.innerHTML = '';
    }
    document.getElementById('groupModal').classList.add('open');
    setTimeout(() => document.getElementById('groupNameInput').focus(), 100);
}
function openEditModal(groupId, name, desc) {
    _isEdit = true;
    document.getElementById('editGroupId').value           = groupId;
    document.getElementById('groupNameInput').value        = name;
    document.getElementById('groupDescInput').value        = desc;
    document.getElementById('groupModalTitle').textContent = 'Edit Group';
    document.getElementById('groupSaveBtn').textContent    = 'Save Changes';
    document.getElementById('preSelectedBlock').style.display = 'none';
    document.getElementById('groupModal').classList.add('open');
    setTimeout(() => document.getElementById('groupNameInput').focus(), 100);
}
function closeGroupModal() { document.getElementById('groupModal').classList.remove('open'); }
async function saveGroup() {
    const name = document.getElementById('groupNameInput').value.trim();
    const desc = document.getElementById('groupDescInput').value.trim();
    if (!name) { document.getElementById('groupNameInput').focus(); return; }
    const btn = document.getElementById('groupSaveBtn');
    btn.disabled = true; btn.textContent = _isEdit ? 'Saving…' : 'Creating…';
    try {
        const token = await getCsrfToken();
        let body;
        if (_isEdit) {
            body = { action: 'update', group_id: parseInt(document.getElementById('editGroupId').value), name, description: desc };
        } else {
            body = { action: 'create', name, description: desc, student_ids: getSelectedStudentIds() };
        }
        const res  = await fetch('api/groups/manage-group.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
            body: JSON.stringify(body),
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Save failed');
        closeGroupModal();
        showToast(_isEdit ? 'Group updated.' : 'Group created!');
        setTimeout(() => location.reload(), 800);
    } catch(e) {
        showToast(e.message, 'error');
        btn.disabled = false; btn.textContent = _isEdit ? 'Save Changes' : 'Create Group';
    }
}
document.getElementById('groupNameInput').addEventListener('keydown', e => { if (e.key === 'Enter') saveGroup(); });
document.getElementById('groupModal').addEventListener('click', e => { if (e.target === document.getElementById('groupModal')) closeGroupModal(); });

// ── Delete modal ──
let _deleteGroupId = null;
function openDeleteModal(groupId, name) {
    _deleteGroupId = groupId;
    document.getElementById('deleteModalBody').textContent =
        `Delete "${name}"? All members will be removed from this group and it will be removed from any assessments it was assigned to. This cannot be undone.`;
    document.getElementById('deleteModal').classList.add('open');
}
function closeDeleteModal() { document.getElementById('deleteModal').classList.remove('open'); _deleteGroupId = null; }
document.getElementById('deleteModal').addEventListener('click', e => { if (e.target === document.getElementById('deleteModal')) closeDeleteModal(); });
document.getElementById('confirmDeleteBtn').addEventListener('click', async function() {
    if (!_deleteGroupId) return;
    this.disabled = true; this.textContent = 'Deleting…';
    try {
        const token = await getCsrfToken();
        const res   = await fetch('api/groups/manage-group.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
            body: JSON.stringify({ action: 'delete', group_id: _deleteGroupId }),
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Delete failed');
        closeDeleteModal(); showToast('Group deleted.');
        setTimeout(() => location.reload(), 800);
    } catch(e) {
        showToast(e.message, 'error');
        this.disabled = false; this.textContent = 'Delete Group';
    }
});

// ── Remove single member ──
async function removeMember(groupId, studentId, name) {
    if (!confirm(`Remove ${name} from this group?`)) return;
    try {
        const token = await getCsrfToken();
        const res   = await fetch('api/groups/manage-group.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
            body: JSON.stringify({ action: 'remove_member', group_id: groupId, student_id: studentId }),
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error);
        document.getElementById(`mi-${groupId}-${studentId}`)?.remove();
        const mlist     = document.getElementById(`mlist-${groupId}`);
        const remaining = mlist.querySelectorAll('.member-item').length;
        const badge     = document.querySelector(`#gcard-${groupId} .group-badge`);
        if (badge) badge.textContent = `${remaining} student${remaining != 1 ? 's' : ''}`;
        if (!remaining) mlist.innerHTML = '<div class="member-empty">No students in this group yet.</div>';
        showToast(`${name} removed.`);
    } catch(e) { showToast(e.message, 'error'); }
}

// ── Add members modal ──
let _addMembersGroupId  = null;
let _addMembersExisting = new Set();
let _addMembersSelected = new Set();

async function openAddMembersModal(groupId) {
    _addMembersGroupId  = groupId;
    _addMembersSelected = new Set();
    document.getElementById('addMembersSearch').value = '';
    document.getElementById('addMembersSaveBtn').disabled    = false;
    document.getElementById('addMembersSaveBtn').textContent = 'Add Selected';
    try {
        const token = await getCsrfToken();
        const res   = await fetch(`api/groups/get-group-details.php?group_id=${groupId}`, {
            credentials: 'same-origin', headers: { 'X-CSRF-Token': token },
        });
        const data = await res.json();
        _addMembersExisting = new Set((data.group?.members ?? []).map(m => m.user_id));
    } catch(e) { _addMembersExisting = new Set(); }
    renderAddList('');
    document.getElementById('addMembersModal').classList.add('open');
    setTimeout(() => document.getElementById('addMembersSearch').focus(), 100);
}
function closeAddMembersModal() { document.getElementById('addMembersModal').classList.remove('open'); _addMembersGroupId = null; }
document.getElementById('addMembersModal').addEventListener('click', e => { if (e.target === document.getElementById('addMembersModal')) closeAddMembersModal(); });

function renderAddList(q) {
    q = q.toLowerCase();
    const eligible = ALL_STUDENTS.filter(s =>
        !_addMembersExisting.has(s.user_id) &&
        (!q || s.full_name.toLowerCase().includes(q) || s.email.toLowerCase().includes(q) || (s.registration_number||'').toLowerCase().includes(q))
    );
    const count = document.getElementById('addMembersCount');
    count.textContent = eligible.length ? `${eligible.length} student${eligible.length != 1 ? 's' : ''} available` : '';
    const list = document.getElementById('addMembersList');
    if (!eligible.length) { list.innerHTML = '<div class="member-empty">No students to add.</div>'; return; }
    list.innerHTML = eligible.map(s => {
        const sel  = _addMembersSelected.has(s.user_id);
        const meta = [s.registration_number, s.department].filter(Boolean).join(' · ');
        return `<div class="add-member-item ${sel ? 'selected' : ''}" data-id="${s.user_id}" onclick="toggleAddMember(this, ${s.user_id})">
            <div class="add-member-check">${sel ? '✓' : ''}</div>
            <div>
                <div class="add-member-name">${esc(s.full_name)}</div>
                <div class="add-member-meta">${esc(s.email)}${meta ? ' · ' + esc(meta) : ''}</div>
            </div>
        </div>`;
    }).join('');
}
function filterAddList(q) { renderAddList(q); }
function toggleAddMember(el, userId) {
    if (_addMembersSelected.has(userId)) {
        _addMembersSelected.delete(userId); el.classList.remove('selected'); el.querySelector('.add-member-check').textContent = '';
    } else {
        _addMembersSelected.add(userId); el.classList.add('selected'); el.querySelector('.add-member-check').textContent = '✓';
    }
}
async function saveAddMembers() {
    if (!_addMembersSelected.size) { showToast('Select at least one student.', 'error'); return; }
    const btn = document.getElementById('addMembersSaveBtn');
    btn.disabled = true; btn.textContent = 'Adding…';
    try {
        const token = await getCsrfToken();
        const res   = await fetch('api/groups/manage-group.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
            body: JSON.stringify({ action: 'add_members', group_id: _addMembersGroupId, student_ids: [..._addMembersSelected] }),
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error);
        closeAddMembersModal();
        showToast(`${data.added} student${data.added != 1 ? 's' : ''} added.`);
        loadedGroups.delete(_addMembersGroupId);
        const card = document.getElementById(`gcard-${_addMembersGroupId}`);
        if (card?.classList.contains('expanded')) await loadGroupMembers(_addMembersGroupId);
    } catch(e) {
        showToast(e.message, 'error');
        btn.disabled = false; btn.textContent = 'Add Selected';
    }
}
</script>
</body>
</html>
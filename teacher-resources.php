<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db-guard.php';

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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function timeAgoPhp(string $dt): string {
    $d = time() - strtotime($dt);
    if ($d < 60)     return 'Just now';
    if ($d < 3600)   return floor($d/60)   . ' min ago';
    if ($d < 86400)  return floor($d/3600)  . ' hr ago';
    if ($d < 604800) return floor($d/86400) . ' day ago';
    return date('d M Y', strtotime($dt));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Resources — PREPAURA</title>
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

/* Nav search */
.nav-search { flex: 1; max-width: 480px; margin: 0 24px; position: relative; }
.nav-search input {
  width: 100%; padding: 9px 38px 9px 14px;
  background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,0.12);
  border-radius: 40px; font-size: 13px; font-family: var(--font-body);
  color: white; outline: none; transition: var(--t);
}
.nav-search input::placeholder { color: rgba(255,255,255,0.35); }
.nav-search input:focus { background: rgba(255,255,255,0.12); border-color: rgba(124,58,237,0.5); box-shadow: 0 0 0 3px rgba(124,58,237,0.15); }
.nav-search .sicon { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); color: rgba(255,255,255,0.35); font-size: 13px; }

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
  cursor: pointer; border: none; background: none; width: 100%; text-align: left; font-family: var(--font-body);
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
.sidebar-filter-btn {
  display: flex; align-items: center; gap: 10px; padding: 10px 14px;
  border-radius: var(--r-sm); font-size: 13.5px; font-weight: 500; color: var(--text-2);
  background: none; border: none; cursor: pointer; width: 100%; text-align: left;
  transition: var(--t); font-family: var(--font-body);
}
.sidebar-filter-btn i { width: 18px; text-align: center; font-size: 14px; color: var(--text-3); transition: var(--t); }
.sidebar-filter-btn:hover { background: var(--violet-dim); color: var(--violet); }
.sidebar-filter-btn:hover i { color: var(--violet); }
.sidebar-filter-btn.active { background: linear-gradient(135deg, rgba(124,58,237,0.12), rgba(192,132,252,0.08)); color: var(--violet); font-weight: 600; box-shadow: inset 3px 0 0 var(--violet); }
.sidebar-filter-btn.active i { color: var(--violet); }
.sidebar-bottom { margin-top: auto; padding-top: 16px; border-top: 1px solid var(--border); }
.sidebar-logout {
  display: flex; align-items: center; gap: 10px; padding: 10px 14px;
  border-radius: var(--r-sm); font-size: 13.5px; font-weight: 500; color: var(--rose);
  background: none; border: none; cursor: pointer; width: 100%; transition: var(--t); font-family: var(--font-body);
}
.sidebar-logout i { width: 18px; text-align: center; font-size: 14px; }
.sidebar-logout:hover { background: rgba(244,63,94,0.07); }

.main { flex: 1; min-width: 0; padding: 36px 36px 80px 28px; }

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
.btn-upload-header {
  display: inline-flex; align-items: center; gap: 8px; padding: 11px 22px;
  border-radius: var(--r-sm); background: rgba(255,255,255,0.15);
  backdrop-filter: blur(8px); border: 1px solid rgba(255,255,255,0.2);
  color: white; font-weight: 600; font-size: 13.5px; cursor: pointer;
  text-decoration: none; transition: var(--t); font-family: var(--font-body);
  position: relative; z-index: 1;
}
.btn-upload-header:hover { background: rgba(255,255,255,0.25); transform: translateY(-1px); }

/* ── STATS ── */
.stats-row { display: grid; grid-template-columns: repeat(4,1fr); gap: 16px; margin-bottom: 28px; }
.stat-card {
  background: var(--surface-3); border: 1px solid var(--border);
  border-radius: var(--r-lg); padding: 20px 22px;
  box-shadow: var(--shadow-xs); display: flex; align-items: center; gap: 16px;
  position: relative; overflow: hidden;
}
.stat-card::after {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
  background: linear-gradient(90deg, var(--violet), var(--orchid));
  border-radius: var(--r-lg) var(--r-lg) 0 0;
}
.stat-icon { width: 46px; height: 46px; border-radius: var(--r-sm); display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.si-violet { background: var(--violet-dim); color: var(--violet); }
.si-green  { background: rgba(16,185,129,0.1); color: var(--emerald); }
.si-gold   { background: rgba(245,158,11,0.1); color: var(--gold); }
.si-sky    { background: rgba(56,189,248,0.1); color: var(--sky); }
.stat-val  { font-family: var(--font-head); font-size: 1.6rem; font-weight: 800; color: var(--text-1); line-height: 1; }
.stat-lbl  { font-size: 12px; color: var(--text-3); margin-top: 4px; }

/* ── SECTION LABEL ── */
.section-label { font-family: var(--font-head); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-3); margin-bottom: 18px; }

/* ── RESOURCE GRID ── */
.resource-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(290px,1fr)); gap: 18px; margin-bottom: 32px; }

/* ── RESOURCE CARD ── */
.resource-card {
  background: var(--surface-3); border: 1px solid var(--border);
  border-radius: var(--r-lg); padding: 20px; box-shadow: var(--shadow-xs);
  display: flex; flex-direction: column; gap: 12px;
  transition: transform var(--t), box-shadow var(--t), border-color var(--t);
  position: relative; overflow: hidden;
}
.resource-card::after {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
  background: linear-gradient(90deg, var(--violet), var(--orchid));
  border-radius: var(--r-lg) var(--r-lg) 0 0;
}
.resource-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); border-color: var(--border-2); }
.card-top { display: flex; align-items: flex-start; gap: 14px; }
.ricon { width: 46px; height: 46px; border-radius: var(--r-sm); display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
.ic-pdf   { background: rgba(244,63,94,0.1);    color: var(--rose); }
.ic-video { background: rgba(245,158,11,0.1);   color: var(--gold); }
.ic-image { background: rgba(124,58,237,0.1);   color: var(--violet); }
.ic-file  { background: var(--surface-2);       color: var(--text-3); }
.card-title { font-family: var(--font-head); font-size: 14px; font-weight: 700; color: var(--text-1); line-height: 1.35; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.card-badges { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 5px; }
.badge-cat  { padding: 2px 9px; border-radius: 99px; font-size: 11px; font-weight: 600; background: rgba(16,185,129,0.1); color: var(--emerald); border: 1px solid rgba(16,185,129,0.2); }
.badge-vis  { padding: 2px 9px; border-radius: 99px; font-size: 11px; font-weight: 600; }
.badge-vis.public  { background: rgba(56,189,248,0.1); color: var(--sky); border: 1px solid rgba(56,189,248,0.2); }
.badge-vis.private { background: rgba(245,158,11,0.1); color: var(--gold); border: 1px solid rgba(245,158,11,0.2); }
.card-desc { font-size: 13px; color: var(--text-3); line-height: 1.5; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.card-meta { display: flex; flex-wrap: wrap; gap: 10px; font-size: 12px; color: var(--text-3); }
.card-meta span { display: flex; align-items: center; gap: 4px; }
.card-actions { display: flex; gap: 7px; margin-top: auto; }
.btn-view {
  flex: 1; padding: 9px 0;
  background: linear-gradient(135deg, var(--violet), #9333ea);
  color: white; border: none; border-radius: var(--r-sm); font-family: var(--font-body);
  font-size: 13px; font-weight: 600; cursor: pointer;
  display: flex; align-items: center; justify-content: center; gap: 6px;
  transition: var(--t); text-decoration: none;
}
.btn-view:hover { box-shadow: 0 4px 14px rgba(124,58,237,0.35); transform: translateY(-1px); }
.btn-icon {
  padding: 9px 11px; background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--r-sm); font-size: 13px; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: var(--t); color: var(--text-3);
}
.btn-icon:hover { border-color: var(--violet); color: var(--violet); background: var(--violet-dim); }
.btn-icon.danger:hover { border-color: var(--rose); color: var(--rose); background: rgba(244,63,94,0.08); }

/* ── PAGINATION ── */
.pagination { display: flex; justify-content: center; gap: 8px; margin-top: 8px; }
.page-btn {
  width: 38px; height: 38px; border-radius: var(--r-sm); border: 1px solid var(--border);
  background: var(--surface-3); font-family: var(--font-head); font-size: 13px; font-weight: 700;
  cursor: pointer; display: flex; align-items: center; justify-content: center;
  color: var(--text-2); transition: var(--t);
}
.page-btn:hover, .page-btn.active { background: linear-gradient(135deg, var(--violet), #9333ea); border-color: transparent; color: white; }
.page-btn:disabled { opacity: 0.4; cursor: default; pointer-events: none; }

/* ── EMPTY STATE ── */
.empty-state { text-align: center; padding: 60px 24px; color: var(--text-3); grid-column: 1/-1; }
.empty-state i { font-size: 3rem; margin-bottom: 16px; display: block; opacity: 0.3; }
.empty-state p { font-size: 14px; line-height: 1.65; }

/* ── SKELETON ── */
.skeleton { animation: pulse 1.4s ease-in-out infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
.skel { background: var(--surface-2); border-radius: var(--r-sm); }

/* ── MODAL ── */
.modal-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(13,10,20,0.6); backdrop-filter: blur(4px);
  z-index: 2000; align-items: center; justify-content: center; padding: 20px;
}
.modal-overlay.open { display: flex; }
.modal {
  background: var(--surface-3); border-radius: var(--r-xl); width: 90%; max-width: 520px;
  box-shadow: var(--shadow-lg), 0 0 0 1px var(--border);
  max-height: 90vh; overflow-y: auto; animation: modalIn 0.2s var(--ease);
}
@keyframes modalIn { from { transform: scale(0.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.modal-head {
  padding: 20px 28px 18px; border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
  background: linear-gradient(135deg, var(--ink) 0%, var(--ink-3) 100%);
  border-radius: var(--r-xl) var(--r-xl) 0 0;
  position: sticky; top: 0; z-index: 1;
}
.modal-title { font-family: var(--font-head); font-size: 17px; font-weight: 700; color: white; }
.modal-close {
  background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.15);
  color: white; width: 30px; height: 30px; border-radius: var(--r-sm);
  font-size: 16px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: var(--t);
}
.modal-close:hover { background: rgba(255,255,255,0.2); }
.modal-body { padding: 24px 28px; }
.form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
.form-label { font-family: var(--font-head); font-size: 11px; font-weight: 700; color: var(--text-3); text-transform: uppercase; letter-spacing: 0.07em; }
.form-control {
  padding: 10px 14px; border: 1px solid var(--border); border-radius: var(--r-sm);
  font-size: 13.5px; font-family: var(--font-body); color: var(--text-1);
  background: var(--surface); outline: none; transition: var(--t);
}
.form-control:focus { border-color: var(--violet); box-shadow: 0 0 0 3px var(--violet-dim); background: var(--surface-3); }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.upload-zone {
  border: 1.5px dashed var(--border-2); border-radius: var(--r-md);
  padding: 28px; text-align: center; cursor: pointer; transition: var(--t);
}
.upload-zone:hover, .upload-zone.dragover { border-color: var(--violet); background: var(--violet-dim); }
.upload-zone i { font-size: 2rem; color: var(--violet); margin-bottom: 10px; display: block; }
.upload-zone p { font-size: 13px; color: var(--text-3); }
.file-name-display { font-size: 13px; color: var(--violet); margin-top: 8px; display: none; font-weight: 500; }
.modal-footer { display: flex; gap: 12px; justify-content: flex-end; padding: 16px 28px 24px; border-top: 1px solid var(--border); }
.btn-primary {
  padding: 10px 24px; background: linear-gradient(135deg, var(--violet), #9333ea);
  color: white; border: none; border-radius: var(--r-sm);
  font-size: 13.5px; font-weight: 600; cursor: pointer; transition: var(--t); font-family: var(--font-body);
}
.btn-primary:hover { box-shadow: 0 4px 14px rgba(124,58,237,0.35); transform: translateY(-1px); }
.btn-primary:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
.btn-secondary {
  padding: 10px 24px; background: var(--surface); color: var(--text-2);
  border: 1px solid var(--border); border-radius: var(--r-sm);
  font-size: 13.5px; font-weight: 600; cursor: pointer; font-family: var(--font-body); transition: var(--t);
}
.btn-secondary:hover { border-color: var(--violet); color: var(--violet); }
.btn-danger {
  padding: 10px 24px; background: rgba(244,63,94,0.1); color: var(--rose);
  border: 1px solid rgba(244,63,94,0.25); border-radius: var(--r-sm);
  font-size: 13.5px; font-weight: 600; cursor: pointer; font-family: var(--font-body); transition: var(--t);
}
.btn-danger:hover { background: var(--rose); color: white; box-shadow: 0 4px 14px rgba(244,63,94,0.3); }

/* ── TOAST ── */
.toast {
  position: fixed; bottom: 28px; right: 28px; z-index: 9999;
  padding: 12px 20px; border-radius: var(--r-md); font-size: 13px; font-weight: 600;
  box-shadow: var(--shadow-md); opacity: 0; transform: translateY(10px);
  transition: all 0.25s var(--ease); pointer-events: none; font-family: var(--font-body);
}
.toast.show { opacity: 1; transform: translateY(0); }
.toast.success { background: rgba(16,185,129,0.1); color: var(--emerald); border: 1px solid rgba(16,185,129,0.25); }
.toast.error   { background: rgba(244,63,94,0.08); color: var(--rose); border: 1px solid rgba(244,63,94,0.2); }

@media (max-width: 960px) { .left-sidebar { display: none; } .main { padding: 24px 20px 80px; } .stats-row { grid-template-columns: 1fr 1fr; } }
@media (max-width: 600px) { .stats-row { grid-template-columns: 1fr; } .form-row { grid-template-columns: 1fr; } }
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

  <div class="nav-search">
    <input type="text" id="searchInput" placeholder="Search resources…" autocomplete="off">
    <i class="fa fa-search sicon"></i>
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
          <button onclick="handleLogout()" class="dropdown-item danger"><i class="fa fa-right-from-bracket"></i> Logout</button>
        </div>
      </div>
    </div>
  </div>
</nav>

<!-- ── PAGE WRAPPER ── -->
<div class="page-wrapper">

  <!-- LEFT SIDEBAR -->
  <aside class="left-sidebar">
    <span class="sidebar-section-label">Navigation</span>
    <a href="teacher-dashboard.php"   class="sidebar-link"><i class="fa fa-house"></i> Dashboard</a>
    <a href="teacher-assessments.php" class="sidebar-link"><i class="fa fa-clipboard-list"></i> Assessments</a>
    <a href="manage-groups.php"       class="sidebar-link"><i class="fa fa-users"></i> Manage Groups</a>
    <a href="teacher-resources.php"   class="sidebar-link active"><i class="fa fa-folder-open"></i> Resources</a>

    <span class="sidebar-section-label">Filter by Category</span>
    <button class="sidebar-filter-btn active" id="cat-all"       onclick="setCat('',this)"><i class="fa fa-layer-group"></i> All</button>
    <button class="sidebar-filter-btn"        id="cat-aptitude"  onclick="setCat('aptitude',this)"><i class="fa fa-calculator"  style="color:#38bdf8"></i> Aptitude</button>
    <button class="sidebar-filter-btn"        id="cat-technical" onclick="setCat('technical',this)"><i class="fa fa-microchip"   style="color:#c084fc"></i> Technical</button>
    <button class="sidebar-filter-btn"        id="cat-coding"    onclick="setCat('coding',this)"><i class="fa fa-code"           style="color:#10b981"></i> Coding</button>
    <button class="sidebar-filter-btn"        id="cat-reasoning" onclick="setCat('reasoning',this)"><i class="fa fa-brain"       style="color:#f59e0b"></i> Reasoning</button>
    <button class="sidebar-filter-btn"        id="cat-english"   onclick="setCat('english',this)"><i class="fa fa-book"          style="color:#f43f5e"></i> English</button>
    <button class="sidebar-filter-btn"        id="cat-general"   onclick="setCat('general',this)"><i class="fa fa-globe"         style="color:#7c3aed"></i> General</button>

    <div class="sidebar-bottom">
      <button onclick="handleLogout()" class="sidebar-logout"><i class="fa fa-right-from-bracket"></i> Logout</button>
    </div>
  </aside>

  <!-- MAIN -->
  <main class="main">

    <!-- Page Header -->
    <div class="page-header">
      <div class="page-header-left">
        <div class="page-header-label">Library</div>
        <h1>Resources</h1>
        <p>Upload, manage, and share learning materials with your students.</p>
      </div>
      <button class="btn-upload-header" onclick="openUploadModal()">
        <i class="fa fa-plus"></i> Upload Resource
      </button>
    </div>

    <!-- Stats -->
    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-icon si-violet"><i class="fa fa-book-open"></i></div>
        <div><div class="stat-val" id="st-total">—</div><div class="stat-lbl">Total Resources</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon si-green"><i class="fa fa-eye"></i></div>
        <div><div class="stat-val" id="st-views">—</div><div class="stat-lbl">Total Views</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon si-gold"><i class="fa fa-download"></i></div>
        <div><div class="stat-val" id="st-dl">—</div><div class="stat-lbl">Downloads</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon si-sky"><i class="fa fa-hard-drive"></i></div>
        <div><div class="stat-val" id="st-size">—</div><div class="stat-lbl">Storage Used</div></div>
      </div>
    </div>

    <div class="section-label" id="sectionLabel">Loading resources…</div>

    <div class="resource-grid" id="resourceGrid">
      <?php for ($s = 0; $s < 8; $s++): ?>
      <div class="resource-card skeleton">
        <div style="display:flex;gap:14px">
          <div class="skel" style="width:46px;height:46px;border-radius:10px;flex-shrink:0"></div>
          <div style="flex:1">
            <div class="skel" style="height:14px;width:80%;margin-bottom:8px"></div>
            <div class="skel" style="height:11px;width:50%"></div>
          </div>
        </div>
        <div class="skel" style="height:12px;width:90%"></div>
        <div class="skel" style="height:12px;width:65%"></div>
        <div style="display:flex;gap:8px">
          <div class="skel" style="flex:1;height:36px;border-radius:8px"></div>
          <div class="skel" style="width:42px;height:36px;border-radius:8px"></div>
          <div class="skel" style="width:42px;height:36px;border-radius:8px"></div>
        </div>
      </div>
      <?php endfor; ?>
    </div>

    <div class="pagination" id="pagination"></div>

  </main>
</div>

<!-- ══ UPLOAD MODAL ══ -->
<div class="modal-overlay" id="uploadModal">
  <div class="modal">
    <div class="modal-head">
      <div class="modal-title">Upload Resource</div>
      <button class="modal-close" onclick="closeUploadModal()">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label">Title *</label>
        <input type="text" class="form-control" id="up-title" placeholder="e.g. Aptitude Practice Set 1">
      </div>
      <div class="form-group">
        <label class="form-label">Description</label>
        <textarea class="form-control" id="up-desc" rows="3" placeholder="Brief description of this resource…"></textarea>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Category</label>
          <select class="form-control" id="up-category">
            <option value="">Select category</option>
            <option value="aptitude">Aptitude</option>
            <option value="technical">Technical</option>
            <option value="coding">Coding</option>
            <option value="reasoning">Reasoning</option>
            <option value="english">English</option>
            <option value="general">General</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Visibility</label>
          <select class="form-control" id="up-visibility">
            <option value="public">🌐 Public (all students)</option>
            <option value="private">🔒 Private (only me)</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Available From</label>
          <input type="date" class="form-control" id="up-from">
        </div>
        <div class="form-group">
          <label class="form-label">Available To</label>
          <input type="date" class="form-control" id="up-to">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">File or Link</label>
        <select class="form-control" id="up-type" onchange="toggleUploadType(this.value)" style="margin-bottom:10px;">
          <option value="file">📎 Upload File (PDF, Video, Image, Doc)</option>
          <option value="link">🔗 External Link</option>
        </select>
        <div id="up-file-zone">
          <div class="upload-zone" id="uploadZone"
               onclick="document.getElementById('up-file').click()"
               ondragover="event.preventDefault();this.classList.add('dragover')"
               ondragleave="this.classList.remove('dragover')"
               ondrop="handleDrop(event)">
            <i class="fa fa-cloud-arrow-up"></i>
            <p>Click or drag &amp; drop your file here</p>
            <p style="font-size:12px;margin-top:6px;color:var(--text-3)">PDF, MP4, JPEG, PNG, DOCX — max 50 MB</p>
          </div>
          <input type="file" id="up-file" style="display:none"
                 accept=".pdf,.mp4,.mov,.jpg,.jpeg,.png,.webp,.doc,.docx,.ppt,.pptx,.xls,.xlsx"
                 onchange="previewFile(this)">
          <div class="file-name-display" id="fileNameDisplay"></div>
        </div>
        <div id="up-link-zone" style="display:none;">
          <input type="url" class="form-control" id="up-link" placeholder="https://…">
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeUploadModal()">Cancel</button>
      <button class="btn-primary" id="uploadSubmitBtn" onclick="submitUpload()"><i class="fa fa-upload"></i> Upload</button>
    </div>
  </div>
</div>

<!-- ══ EDIT MODAL ══ -->
<div class="modal-overlay" id="editModal">
  <div class="modal">
    <div class="modal-head">
      <div class="modal-title">Edit Resource</div>
      <button class="modal-close" onclick="closeEditModal()">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="edit-id">
      <div class="form-group">
        <label class="form-label">Title *</label>
        <input type="text" class="form-control" id="edit-title">
      </div>
      <div class="form-group">
        <label class="form-label">Description</label>
        <textarea class="form-control" id="edit-desc" rows="3"></textarea>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Category</label>
          <select class="form-control" id="edit-category">
            <option value="">Select category</option>
            <option value="aptitude">Aptitude</option>
            <option value="technical">Technical</option>
            <option value="coding">Coding</option>
            <option value="reasoning">Reasoning</option>
            <option value="english">English</option>
            <option value="general">General</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Visibility</label>
          <select class="form-control" id="edit-visibility">
            <option value="public">🌐 Public</option>
            <option value="private">🔒 Private</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Available From</label>
          <input type="date" class="form-control" id="edit-from">
        </div>
        <div class="form-group">
          <label class="form-label">Available To</label>
          <input type="date" class="form-control" id="edit-to">
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeEditModal()">Cancel</button>
      <button class="btn-primary" onclick="submitEdit()"><i class="fa fa-save"></i> Save Changes</button>
    </div>
  </div>
</div>

<!-- ══ DELETE MODAL ══ -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal" style="max-width:440px;">
    <div class="modal-head">
      <div class="modal-title">Delete Resource</div>
      <button class="modal-close" onclick="closeDeleteModal()">✕</button>
    </div>
    <div class="modal-body">
      <p style="font-size:14px;color:var(--text-2);line-height:1.65;" id="deleteModalBody">Are you sure you want to delete this resource? This cannot be undone.</p>
      <input type="hidden" id="delete-id">
    </div>
    <div class="modal-footer">
      <button class="btn-secondary" onclick="closeDeleteModal()">Cancel</button>
      <button class="btn-danger" onclick="submitDelete()"><i class="fa fa-trash"></i> Delete</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;
let activeCat   = '';
let activeType  = '';
let searchQ     = '';
let currentPage = 1;
const LIMIT     = 20;

const ICONS = {
    pdf:     ['ic-pdf',   'fa-file-pdf'],
    video:   ['ic-video', 'fa-file-video'],
    link:    ['ic-file',  'fa-link'],
    article: ['ic-file',  'fa-newspaper'],
    image:   ['ic-image', 'fa-image'],
    quiz:    ['ic-image', 'fa-circle-question'],
};
function iconFor(t) { return ICONS[t] || ['ic-file','fa-file']; }

function fmtSize(b) {
    if (!b) return '';
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b/1024).toFixed(1) + ' KB';
    if (b < 1073741824) return (b/1048576).toFixed(1) + ' MB';
    return (b/1073741824).toFixed(1) + ' GB';
}
function fmtNum(n) {
    n = parseInt(n)||0;
    if (n>=1000000) return (n/1000000).toFixed(1)+'M';
    if (n>=1000)    return (n/1000).toFixed(1)+'K';
    return String(n);
}
function timeAgo(d) {
    if (!d) return '';
    const s = Math.floor((Date.now()-new Date(d))/1000);
    if (s<60)    return 'just now';
    if (s<3600)  return Math.floor(s/60)+'m ago';
    if (s<86400) return Math.floor(s/3600)+'h ago';
    return Math.floor(s/86400)+'d ago';
}
function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── Load resources ── */
async function load() {
    const p = new URLSearchParams({ page: currentPage, limit: LIMIT });
    if (activeCat)  p.set('category', activeCat);
    if (activeType) p.set('type', activeType);
    if (searchQ)    p.set('search', searchQ);

    try {
        const res  = await fetch('api/resources/get-teacher-resources.php?' + p);
        const data = await res.json();
        if (!data.success) { showError(data.error||'Failed to load.'); return; }

        const s = data.stats||{};
        document.getElementById('st-total').textContent = fmtNum(s.total_materials);
        document.getElementById('st-views').textContent = fmtNum(s.total_views);
        document.getElementById('st-dl').textContent    = fmtNum(s.total_downloads);
        document.getElementById('st-size').textContent  = fmtSize(s.storage_used_bytes)||'0 B';

        renderGrid(data.materials||[], data.total||0);
        renderPagination(data.pages||1);
    } catch(e) {
        showError('Could not reach server.');
    }
}

/* ── Render cards ── */
function renderGrid(mats, total) {
    const grid  = document.getElementById('resourceGrid');
    const label = document.getElementById('sectionLabel');
    label.textContent = total ? total + (total===1?' Resource':' Resources') : 'No resources found';

    if (!mats.length) {
        grid.innerHTML = `<div class="empty-state"><i class="fa fa-folder-open"></i><p>No resources uploaded yet.<br>Click <strong>Upload Resource</strong> to add your first one.</p></div>`;
        return;
    }

    grid.innerHTML = mats.map(m => {
        const [ic, fa] = iconFor(m.material_type);
        const catBadge = m.category ? `<span class="badge-cat">${esc(m.category)}</span>` : '';
        const visBadge = `<span class="badge-vis ${m.is_public?'public':'private'}">${m.is_public?'🌐 Public':'🔒 Private'}</span>`;
        const isLink   = m.material_type === 'link';
        const primaryBtn = isLink
            ? `<a class="btn-view" href="${esc(m.external_url)}" target="_blank" rel="noopener"><i class="fa fa-external-link-alt"></i> Open</a>`
            : `<button class="btn-view" onclick="openFile(${m.material_id},'${esc(m.material_type)}')"><i class="fa fa-eye"></i> View</button>`;
        const visIcon  = m.is_public ? 'fa-lock-open' : 'fa-lock';
        const visTitle = m.is_public ? 'Make Private' : 'Make Public';

        return `
        <div class="resource-card" id="rcard-${m.material_id}">
            <div class="card-top">
                <div class="ricon ${ic}"><i class="fa ${fa}"></i></div>
                <div style="flex:1;min-width:0">
                    <div class="card-title" title="${esc(m.title)}">${esc(m.title)}</div>
                    <div class="card-badges">${catBadge}${visBadge}</div>
                </div>
            </div>
            ${m.description ? `<div class="card-desc">${esc(m.description)}</div>` : ''}
            <div class="card-meta">
                ${m.file_size ? `<span><i class="fa fa-database"></i>${fmtSize(m.file_size)}</span>` : ''}
                ${m.estimated_time_minutes ? `<span><i class="fa fa-clock"></i>${m.estimated_time_minutes} min</span>` : ''}
                <span><i class="fa fa-eye"></i>${m.views||0} views</span>
                <span><i class="fa fa-download"></i>${m.downloads||0}</span>
                <span><i class="fa fa-clock"></i>${timeAgo(m.created_at)}</span>
            </div>
            <div class="card-actions">
                ${primaryBtn}
                <button class="btn-icon" onclick="openEditModal(${m.material_id})" title="Edit"><i class="fa fa-pen"></i></button>
                <button class="btn-icon" onclick="toggleVisibility(${m.material_id},${m.is_public?0:1})" title="${visTitle}"><i class="fa ${visIcon}"></i></button>
                <button class="btn-icon danger" onclick="openDeleteModal(${m.material_id},'${esc(m.title)}')" title="Delete"><i class="fa fa-trash"></i></button>
            </div>
        </div>`;
    }).join('');
}

/* ── Pagination ── */
function renderPagination(totalPages) {
    const pg = document.getElementById('pagination');
    if (totalPages<=1) { pg.innerHTML=''; return; }
    let html = `<button class="page-btn" onclick="goPage(${currentPage-1})" ${currentPage===1?'disabled':''}>‹</button>`;
    for (let i=1; i<=totalPages; i++) {
        if (i===1||i===totalPages||Math.abs(i-currentPage)<=2)
            html += `<button class="page-btn ${i===currentPage?'active':''}" onclick="goPage(${i})">${i}</button>`;
        else if (Math.abs(i-currentPage)===3)
            html += `<span style="align-self:center;color:var(--text-3)">…</span>`;
    }
    html += `<button class="page-btn" onclick="goPage(${currentPage+1})" ${currentPage===totalPages?'disabled':''}>›</button>`;
    pg.innerHTML = html;
}
function goPage(p) { currentPage=p; window.scrollTo({top:0,behavior:'smooth'}); load(); }

/* ── File actions ── */
function openFile(id, type) {
    if (['pdf','video'].includes(type))
        window.open('api/resources/view-resource.php?material_id='+id, '_blank');
    else
        window.open('api/resources/serve-resource.php?material_id='+id+'&action=download', '_blank');
}

/* ── Filters ── */
function setCat(cat, el) {
    activeCat=cat; currentPage=1;
    document.querySelectorAll('.sidebar-filter-btn').forEach(b=>b.classList.remove('active'));
    el.classList.add('active'); load();
}
function setType(type, el) {
    activeType=type; currentPage=1;
    document.querySelectorAll('.left-sidebar a[id^="t-"]').forEach(a=>a.classList.remove('active'));
    el.classList.add('active'); load();
}

let st;
document.getElementById('searchInput').addEventListener('input', e => {
    clearTimeout(st);
    st = setTimeout(() => { searchQ=e.target.value.trim(); currentPage=1; load(); }, 350);
});

/* ── Upload modal ── */
function openUploadModal() {
    ['up-title','up-desc','up-from','up-to','up-link'].forEach(id => document.getElementById(id).value='');
    document.getElementById('up-category').value   = '';
    
    document.getElementById('up-visibility').value = 'public';
    document.getElementById('up-file').value        = '';
    document.getElementById('fileNameDisplay').style.display = 'none';
    document.getElementById('up-type').value = 'file';
    toggleUploadType('file');
    document.getElementById('uploadModal').classList.add('open');
}
function closeUploadModal() { document.getElementById('uploadModal').classList.remove('open'); }

function toggleUploadType(val) {
    document.getElementById('up-file-zone').style.display = val==='file' ? 'block' : 'none';
    document.getElementById('up-link-zone').style.display = val==='link' ? 'block' : 'none';
}
function previewFile(input) {
    const file = input.files[0];
    if (!file) return;
    const d = document.getElementById('fileNameDisplay');
    d.textContent = '📎 ' + file.name + ' (' + fmtSize(file.size) + ')';
    d.style.display = 'block';
}
function handleDrop(e) {
    e.preventDefault();
    document.getElementById('uploadZone').classList.remove('dragover');
    const files = e.dataTransfer.files;
    if (files.length) {
        const input = document.getElementById('up-file');
        const dt = new DataTransfer();
        dt.items.add(files[0]);
        input.files = dt.files;
        previewFile(input);
    }
}
async function submitUpload() {
    const title = document.getElementById('up-title').value.trim();
    if (!title) { toast('Title is required.', 'error'); return; }
    const type = document.getElementById('up-type').value;
    const btn  = document.getElementById('uploadSubmitBtn');
    btn.disabled = true; btn.innerHTML = 'Uploading…';
    try {
        let body;
        let headers = { 'X-CSRF-Token': CSRF_TOKEN };
        if (type === 'file') {
            const file = document.getElementById('up-file').files[0];
            if (!file) { toast('Please select a file.', 'error'); btn.disabled=false; btn.innerHTML='<i class="fa fa-upload"></i> Upload'; return; }
            body = new FormData();
            body.append('action', 'upload');
            body.append('title', title);
            body.append('description', document.getElementById('up-desc').value.trim());
            body.append('category', document.getElementById('up-category').value);
            body.append('available_from', document.getElementById('up-from').value || '');
            body.append('is_public', document.getElementById('up-visibility').value === 'public' ? 1 : 0);
            body.append('available_until', document.getElementById('up-to').value || '');
            body.append('file', file);
        } else {
            const link = document.getElementById('up-link').value.trim();
            if (!link) { toast('Please enter a URL.', 'error'); btn.disabled=false; btn.innerHTML='<i class="fa fa-upload"></i> Upload'; return; }
            body = JSON.stringify({ action:'upload_link', title, description: document.getElementById('up-desc').value.trim(), category: document.getElementById('up-category').value, is_public: document.getElementById('up-visibility').value==='public'?1:0, available_from: document.getElementById('up-from').value||null, available_until: document.getElementById('up-to').value||null, external_url: link });
            headers['Content-Type'] = 'application/json';
        }
        const res  = await fetch('api/resources/upload-resource.php', { method:'POST', credentials:'same-origin', headers, body });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Upload failed.');
        closeUploadModal();
        toast('Resource uploaded!', 'success');
        load();
    } catch(e) {
        toast(e.message, 'error');
    } finally {
        btn.disabled = false; btn.innerHTML = '<i class="fa fa-upload"></i> Upload';
    }
}

/* ── Edit modal ── */
let _editData = {};
async function openEditModal(id) {
    try {
        const res  = await fetch('api/resources/get-teacher-resources.php?material_id='+id);
        const data = await res.json();
        const m    = data.materials?.[0];
        if (!m) { toast('Could not load resource.', 'error'); return; }
        _editData = m;
        document.getElementById('edit-id').value         = m.material_id;
        document.getElementById('edit-title').value      = m.title || '';
        document.getElementById('edit-desc').value       = m.description || '';
        document.getElementById('edit-category').value   = m.category || '';
        document.getElementById('edit-from').value = m.available_from ? m.available_from.substring(0,10) : '';
        document.getElementById('edit-visibility').value = m.is_public ? 'public' : 'private';
        document.getElementById('edit-to').value   = m.available_until ? m.available_until.substring(0,10) : '';
        document.getElementById('editModal').classList.add('open');
    } catch(e) { toast('Error loading resource.', 'error'); }
}
function closeEditModal() { document.getElementById('editModal').classList.remove('open'); }
async function submitEdit() {
    const id    = document.getElementById('edit-id').value;
    const title = document.getElementById('edit-title').value.trim();
    if (!title) { toast('Title is required.', 'error'); return; }
    try {
        const res  = await fetch('api/resources/update-resource.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type':'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify({
                material_id: id, title,
                description: document.getElementById('edit-desc').value.trim(),
                category:    document.getElementById('edit-category').value,
                available_from: document.getElementById('edit-from').value || null,
                is_public:   document.getElementById('edit-visibility').value === 'public' ? 1 : 0,
                available_until: document.getElementById('edit-to').value || null,
            }),
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Update failed.');
        closeEditModal(); toast('Resource updated!', 'success'); load();
    } catch(e) { toast(e.message, 'error'); }
}

/* ── Visibility toggle ── */
async function toggleVisibility(id, newPublic) {
    try {
        const res  = await fetch('api/resources/update-resource.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type':'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify({ material_id: id, is_public: newPublic }),
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed to update visibility.');
        toast(newPublic ? '🌐 Made public' : '🔒 Made private', 'success'); load();
    } catch(e) { toast(e.message, 'error'); }
}

/* ── Delete modal ── */
function openDeleteModal(id, title) {
    document.getElementById('delete-id').value = id;
    document.getElementById('deleteModalBody').textContent = `Delete "${title}"? This cannot be undone.`;
    document.getElementById('deleteModal').classList.add('open');
}
function closeDeleteModal() { document.getElementById('deleteModal').classList.remove('open'); }
async function submitDelete() {
    const id = document.getElementById('delete-id').value;
    try {
        const res  = await fetch('api/resources/delete-resource.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type':'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify({ material_id: id }),
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Delete failed.');
        closeDeleteModal(); toast('Resource deleted.', 'success'); load();
    } catch(e) { toast(e.message, 'error'); }
}

/* ── Close modals on overlay click ── */
['uploadModal','editModal','deleteModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    });
});

/* ── Profile dropdown ── */
const profileBtn  = document.getElementById('profileBtn');
const profileDrop = document.getElementById('profileDropdown');
profileBtn.addEventListener('click', e => { e.stopPropagation(); profileDrop.classList.toggle('open'); });
document.addEventListener('click', () => profileDrop.classList.remove('open'));

function handleLogout() {
    if (confirm('Are you sure you want to logout?')) window.location.href = 'logout.php';
}

/* ── Toast ── */
function toast(msg, type='') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast' + (type ? ' '+type : '');
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2800);
}
function showError(msg) {
    document.getElementById('resourceGrid').innerHTML = `<div class="empty-state"><i class="fa fa-circle-exclamation"></i><p>${msg}</p></div>`;
    document.getElementById('sectionLabel').textContent = 'Error';
}

load();
</script>
</body>
</html>

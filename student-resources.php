<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db-guard.php';


$currentUser  = validateSession($conn);
$userName     = htmlspecialchars($currentUser['full_name']);
$userInitials = strtoupper(substr($currentUser['full_name'], 0, 2));
$userId       = (int) $currentUser['user_id'];

// Ensure CSRF token exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Unread notification count ──
$notifResult = safePreparedQuery($conn,
    "SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0",
    "i", [$userId]
);
$unreadCount = 0;
if ($notifResult['success'] && $notifResult['result']) {
    $notifRow    = $notifResult['result']->fetch_assoc();
    $unreadCount = (int)($notifRow['cnt'] ?? 0);
    $notifResult['result']->free();
}

// ── All notifications for dropdown (scroll, latest first) ──
$notifDropResult = safePreparedQuery($conn,
    "SELECT notification_id, title, message, type, is_read, created_at
     FROM notifications WHERE user_id = ?
     ORDER BY created_at DESC",
    "i", [$userId]
);
$notifItems = [];
if ($notifDropResult['success'] && $notifDropResult['result']) {
    while ($row = $notifDropResult['result']->fetch_assoc()) {
        $notifItems[] = $row;
    }
    $notifDropResult['result']->free();
}

function timeAgoPhp(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff / 60)   . ' min ago';
    if ($diff < 86400)  return floor($diff / 3600)  . ' hr ago';
    if ($diff < 604800) return floor($diff / 86400) . ' day ago';
    return date('d M Y', strtotime($datetime));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Resources – PTA Platform</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --primary:       #1a3a52;
    --primary-mid:   #234C6A;
    --accent:        #0ea5e9;
    --accent-glow:   rgba(14,165,233,.18);
    --accent2:       #06b6d4;
    --success:       #10b981;
    --warning:       #f59e0b;
    --danger:        #ef4444;
    --bg:            #f0f4f8;
    --surface:       #ffffff;
    --surface2:      #f8fafc;
    --border:        #e2e8f0;
    --text:          #0f172a;
    --text-mid:      #475569;
    --text-soft:     #94a3b8;
    --radius:        16px;
    --radius-sm:     10px;
    --shadow:        0 1px 3px rgba(0,0,0,.06), 0 4px 16px rgba(0,0,0,.06);
    --shadow-md:     0 4px 24px rgba(0,0,0,.10);
    --nav-h:         68px;
    --sidebar-w:     230px;
    --transition:    .2s cubic-bezier(.4,0,.2,1);
}

body {
    font-family: 'Inter', sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    padding-top: var(--nav-h);
    -webkit-font-smoothing: antialiased;
}

/* ══════════════════════════════
   NAVBAR
══════════════════════════════ */
.navbar {
    background: var(--primary);
    padding: 0 28px;
    height: var(--nav-h);
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: fixed;
    top: 0; left: 0; right: 0;
    z-index: 1000;
    box-shadow: 0 1px 0 rgba(255,255,255,.06), 0 4px 20px rgba(0,0,0,.18);
}
.navbar-brand {
    display: flex; align-items: center; gap: 12px;
    text-decoration: none; flex-shrink: 0;
}
.nav-search {
    flex: 1; max-width: 440px; margin: 0 32px; position: relative;
}
.nav-search input {
    width: 100%;
    padding: 10px 18px 10px 42px;
    border: 1.5px solid rgba(255,255,255,.15);
    border-radius: 10px;
    font-family: 'Inter', sans-serif; font-size: 14px;
    background: rgba(255,255,255,.1); color: white;
    outline: none; transition: var(--transition);
}
.nav-search input::placeholder { color: rgba(255,255,255,.5); }
.nav-search input:focus {
    background: rgba(255,255,255,.18);
    border-color: rgba(255,255,255,.35);
    box-shadow: 0 0 0 3px rgba(14,165,233,.25);
}
.nav-search .sicon {
    position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
    color: rgba(255,255,255,.5); font-size: 13px; pointer-events: none;
}

.nav-profile { display: flex; align-items: center; gap: 10px; }

/* Notification icon */
.notification-btn {
    position: relative;
    width: 38px; height: 38px;
    background: rgba(255,255,255,.12);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; border: 1.5px solid rgba(255,255,255,.15);
    transition: var(--transition); color: white; font-size: 16px;
}
.notification-btn:hover { background: rgba(255,255,255,.2); border-color: rgba(255,255,255,.3); }

.notif-dropdown-wrap { position: relative; }
.notif-dropdown {
    position: absolute; top: calc(100% + 12px); right: 0;
    background: var(--surface); border-radius: var(--radius);
    box-shadow: var(--shadow-md); border: 1px solid var(--border);
    width: 348px;
    opacity: 0; visibility: hidden; transform: translateY(-6px) scale(.98);
    transition: var(--transition); z-index: 1002;
}
.notif-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
.notif-dropdown-header {
    padding: 16px 20px 14px;
    font-family: 'Sora', sans-serif; font-weight: 700; font-size: 14px; color: var(--text);
    border-bottom: 1px solid var(--border);
}
.notif-list {
    max-height: 360px; overflow-y: auto;
    scrollbar-width: thin; scrollbar-color: var(--border) transparent;
}
.notif-list::-webkit-scrollbar { width: 4px; }
.notif-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 10px; }
.notif-item {
    display: flex; gap: 12px; align-items: flex-start;
    padding: 13px 20px; border-bottom: 1px solid var(--border);
    cursor: pointer; transition: background var(--transition);
}
.notif-item:last-child { border-bottom: none; }
.notif-item:hover { background: var(--surface2); }
.notif-item.unread { background: #eff8ff; }
.notif-item.unread:hover { background: #e0f2fe; }
.notif-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--accent); flex-shrink: 0; margin-top: 5px; }
.notif-dot.read { background: transparent; }
.notif-item-body { flex: 1; }
.notif-item-title { font-size: 13px; font-weight: 600; color: var(--text); margin-bottom: 2px; }
.notif-item-msg { font-size: 12px; color: var(--text-mid); line-height: 1.45; }
.notif-item-time { font-size: 11px; color: var(--text-soft); margin-top: 4px; }
.notif-empty { padding: 32px 20px; text-align: center; color: var(--text-soft); font-size: 13px; }

.notif-badge {
    position: absolute; top: -4px; right: -4px;
    background: var(--danger); color: white;
    min-width: 18px; height: 18px; border-radius: 9px; padding: 0 4px;
    font-size: 10px; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    animation: badgePulse 2s ease-in-out infinite;
    border: 2px solid var(--primary);
}
@keyframes badgePulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(239,68,68,.5); }
    60%       { box-shadow: 0 0 0 5px rgba(239,68,68,0); }
}

/* Profile button & dropdown */
.profile-wrapper { position: relative; }
.profile-button {
    display: flex; align-items: center; gap: 9px;
    padding: 6px 12px 6px 6px;
    background: rgba(255,255,255,.12);
    border: 1.5px solid rgba(255,255,255,.15);
    border-radius: 10px; cursor: pointer; transition: var(--transition);
    font-family: inherit;
}
.profile-button:hover { background: rgba(255,255,255,.2); border-color: rgba(255,255,255,.3); }
.profile-avatar {
    width: 32px; height: 32px;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    color: white; font-weight: 700; font-size: 13px;
    font-family: 'Sora', sans-serif;
}
.profile-name { font-weight: 600; font-size: 13.5px; color: rgba(255,255,255,.95); }
.dropdown-arrow { font-size: 10px; color: rgba(255,255,255,.6); }

.profile-dropdown {
    display: none;
    position: absolute; top: calc(100% + 12px); right: 0;
    background: var(--surface); border-radius: var(--radius);
    box-shadow: var(--shadow-md); border: 1px solid var(--border);
    min-width: 240px; z-index: 1001; overflow: hidden;
}
.profile-dropdown.open { display: block; }

.dropdown-header {
    padding: 18px 20px;
    background: linear-gradient(135deg, var(--primary), var(--primary-mid));
    display: flex; gap: 12px; align-items: center;
}
.dropdown-avatar {
    width: 44px; height: 44px;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    color: white; font-weight: 800; font-size: 18px;
    font-family: 'Sora', sans-serif; flex-shrink: 0;
}
.dropdown-user-info { flex: 1; overflow: hidden; }
.dropdown-name  { font-family: 'Sora', sans-serif; font-weight: 700; font-size: 15px; color: white; margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dropdown-email { font-size: 12px; color: rgba(255,255,255,.65); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.dropdown-divider { height: 1px; background: var(--border); margin: 6px 8px; }
.dropdown-item {
    display: flex; align-items: center; gap: 11px;
    padding: 10px 12px; border-radius: 8px; margin: 2px 8px;
    color: var(--text-mid); text-decoration: none;
    cursor: pointer; border: none; background: none;
    width: calc(100% - 16px); text-align: left; font-size: 13.5px;
    font-family: 'Inter', sans-serif; transition: var(--transition);
}
.dropdown-item:hover { background: var(--surface2); color: var(--text); }
.dropdown-item .di { font-size: 16px; width: 20px; text-align: center; flex-shrink: 0; }
.dropdown-item i { width: 16px; text-align: center; color: var(--text-soft); }
.dropdown-item.logout { color: var(--danger); }
.dropdown-item.logout i { color: var(--danger); }
.dropdown-item.logout:hover { background: #fef2f2; }

/* ══════════════════════════════
   PAGE LAYOUT
══════════════════════════════ */
.page-wrapper { display: flex; min-height: calc(100vh - var(--nav-h)); }

/* ── LEFT SIDEBAR ── */
.sidebar {
    width: var(--sidebar-w); flex-shrink: 0;
    padding: 20px 12px;
    display: flex; flex-direction: column; gap: 2px;
    background: var(--surface);
    border-right: 1px solid var(--border);
    min-height: calc(100vh - var(--nav-h));
    position: sticky; top: var(--nav-h); align-self: flex-start;
}
.sidebar-section {
    font-size: 10.5px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .1em;
    color: var(--text-soft); padding: 14px 12px 7px;
}
.sidebar a {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 13px; border-radius: var(--radius-sm);
    text-decoration: none; font-size: 13.5px; font-weight: 500;
    color: var(--text-mid); transition: var(--transition);
    position: relative;
}
.sidebar a:hover { background: var(--surface2); color: var(--primary); }
.sidebar a.active {
    background: linear-gradient(135deg, #e0f2fe, #e0f9ff);
    color: var(--accent); font-weight: 600;
}
.sidebar a.active::before {
    content: '';
    position: absolute; left: 0; top: 20%; bottom: 20%;
    width: 3px; border-radius: 0 3px 3px 0;
    background: var(--accent);
}
.sidebar a i { width: 18px; text-align: center; font-size: 14px; flex-shrink: 0; }
.sidebar-bottom {
    margin-top: auto; padding-top: 12px;
    border-top: 1px solid var(--border);
}
.sidebar-bottom button {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 13px; border-radius: var(--radius-sm);
    font-size: 13.5px; font-weight: 500;
    color: var(--danger); background: none; border: none;
    cursor: pointer; width: 100%; transition: var(--transition);
    font-family: 'Inter', sans-serif;
}
.sidebar-bottom button:hover { background: #fef2f2; }
.sidebar-bottom button i { width: 18px; text-align: center; font-size: 14px; }

/* ── MAIN ── */
.main { flex: 1; padding: 28px 28px 80px; min-width: 0; }

/* ══════════════════════════════
   PAGE HEADER BANNER
══════════════════════════════ */
.page-header {
    background: linear-gradient(135deg, var(--primary) 0%, #1e5276 60%, #1a6fa0 100%);
    border-radius: var(--radius);
    padding: 26px 32px;
    margin-bottom: 24px;
    display: flex; align-items: center; justify-content: space-between;
    position: relative; overflow: hidden;
    box-shadow: 0 4px 24px rgba(26,58,82,.3);
}
.page-header::before {
    content: ''; position: absolute; top: -60px; right: -60px;
    width: 220px; height: 220px; border-radius: 50%;
    background: rgba(255,255,255,.05); pointer-events: none;
}
.page-header::after {
    content: ''; position: absolute; bottom: -80px; right: 120px;
    width: 180px; height: 180px; border-radius: 50%;
    background: rgba(14,165,233,.08); pointer-events: none;
}
.page-header-left h1 {
    font-family: 'Sora', sans-serif;
    font-size: 22px; font-weight: 800; color: white;
    margin-bottom: 5px; letter-spacing: -.2px; position: relative; z-index: 1;
}
.page-header-left p { font-size: 13.5px; color: rgba(255,255,255,.7); position: relative; z-index: 1; }
.page-header-right {
    font-family: 'Sora', sans-serif;
    font-size: 13px; color: rgba(255,255,255,.75);
    text-align: right; position: relative; z-index: 1;
}
.page-header-right strong { font-size: 26px; font-weight: 800; color: white; display: block; line-height: 1; }

/* ══════════════════════════════
   STATS ROW
══════════════════════════════ */
.stats-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
.stat-card {
    background: var(--surface); border-radius: var(--radius);
    padding: 20px 22px; box-shadow: var(--shadow);
    border: 1px solid var(--border);
    display: flex; align-items: center; gap: 16px;
    transition: var(--transition);
}
.stat-card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }
.stat-icon {
    width: 48px; height: 48px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.si-blue   { background: #e0f2fe; color: var(--accent); }
.si-green  { background: #d1fae5; color: var(--success); }
.si-orange { background: #fef3c7; color: var(--warning); }
.si-purple { background: #ede9fe; color: #8b5cf6; }
.stat-val  { font-family: 'Sora', sans-serif; font-size: 1.6rem; font-weight: 800; color: var(--text); line-height: 1; }
.stat-lbl  { font-size: 12px; color: var(--text-soft); margin-top: 4px; }

/* ══════════════════════════════
   FILTER TABS
══════════════════════════════ */
.filter-bar {
    display: flex; gap: 8px; flex-wrap: wrap;
    margin-bottom: 20px;
}
.filter-tab {
    padding: 7px 16px; border-radius: 8px;
    border: 1.5px solid var(--border);
    background: var(--surface);
    font-family: 'Inter', sans-serif; font-size: 13px; font-weight: 600;
    cursor: pointer; color: var(--text-mid);
    transition: var(--transition);
}
.filter-tab:hover:not(.active) { background: var(--border); color: var(--text); }
.filter-tab.active {
    background: var(--accent); color: white;
    border-color: var(--accent);
    box-shadow: 0 2px 8px rgba(14,165,233,.3);
}

/* ══════════════════════════════
   SECTION LABEL
══════════════════════════════ */
.section-label {
    font-family: 'Sora', sans-serif;
    font-size: 18px; font-weight: 700; color: var(--text);
    margin-bottom: 18px;
}

/* ══════════════════════════════
   RESOURCE GRID
══════════════════════════════ */
.resource-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(268px, 1fr));
    gap: 18px;
    margin-bottom: 32px;
}

/* ══════════════════════════════
   RESOURCE CARD
══════════════════════════════ */
.resource-card {
    background: var(--surface); border-radius: var(--radius);
    padding: 20px; box-shadow: var(--shadow);
    border: 1.5px solid var(--border);
    display: flex; flex-direction: column; gap: 12px;
    transition: var(--transition);
}
.resource-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 20px rgba(14,165,233,.12), 0 0 0 1px rgba(14,165,233,.1);
    border-color: var(--accent);
}

.card-top { display: flex; align-items: flex-start; gap: 14px; }
.ricon {
    width: 46px; height: 46px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.3rem; flex-shrink: 0;
}
.ic-pdf    { background: #fee2e2; color: #ef4444; }
.ic-video  { background: #fef3c7; color: var(--warning); }
.ic-image  { background: #ede9fe; color: #8b5cf6; }
.ic-purple { background: #ede9fe; color: #8b5cf6; }
.ic-file   { background: var(--surface2); color: var(--text-soft); }

.card-title {
    font-family: 'Sora', sans-serif;
    font-size: 14.5px; font-weight: 700; color: var(--text);
    line-height: 1.35;
    overflow: hidden; text-overflow: ellipsis;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
}
.card-badges { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 5px; }

.badge-cat {
    padding: 3px 10px; border-radius: 6px;
    font-size: 11px; font-weight: 700;
    background: #d1fae5; color: #065f46;
    font-family: 'Sora', sans-serif;
}
.badge-diff {
    padding: 3px 10px; border-radius: 6px;
    font-size: 11px; font-weight: 700;
    font-family: 'Sora', sans-serif;
}
.badge-diff.beginner     { background: #dcfce7; color: #166534; }
.badge-diff.intermediate { background: #fef3c7; color: #92400e; }
.badge-diff.advanced     { background: #fee2e2; color: #991b1b; }

.card-desc {
    font-size: 13px; color: var(--text-mid); line-height: 1.45;
    overflow: hidden; text-overflow: ellipsis;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
}

.card-meta {
    display: flex; flex-wrap: wrap; gap: 10px;
    font-size: 12px; color: var(--text-soft);
}
.card-meta span { display: flex; align-items: center; gap: 4px; }

/* Progress bar */
.prog-wrap { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--text-mid); }
.prog-bar  { flex: 1; height: 6px; background: var(--border); border-radius: 50px; overflow: hidden; }
.prog-fill { height: 100%; background: linear-gradient(90deg, var(--accent), var(--accent2)); border-radius: 50px; }

/* Card buttons */
.card-actions { display: flex; gap: 8px; }
.btn-view {
    flex: 1; padding: 9px 0;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    color: white; border: none; border-radius: var(--radius-sm);
    font-family: 'Inter', sans-serif; font-size: 13px; font-weight: 600;
    cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;
    transition: var(--transition); text-decoration: none;
    box-shadow: 0 2px 8px rgba(14,165,233,.3);
}
.btn-view:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(14,165,233,.45); }
.btn-dl {
    padding: 9px 14px;
    background: var(--surface); color: var(--accent);
    border: 1.5px solid var(--accent); border-radius: var(--radius-sm);
    font-family: 'Inter', sans-serif; font-size: 13px; font-weight: 600;
    cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;
    transition: var(--transition);
}
.btn-dl:hover { background: var(--accent); color: white; box-shadow: 0 4px 12px rgba(14,165,233,.3); }

/* ══════════════════════════════
   PAGINATION
══════════════════════════════ */
.pagination { display: flex; justify-content: center; gap: 8px; margin-top: 8px; }
.page-btn {
    width: 38px; height: 38px; border-radius: var(--radius-sm);
    border: 1.5px solid var(--border); background: var(--surface);
    font-family: 'Inter', sans-serif; font-size: 13.5px; font-weight: 600;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    color: var(--text-mid); transition: var(--transition);
}
.page-btn:hover, .page-btn.active {
    background: var(--accent); border-color: var(--accent);
    color: white; box-shadow: 0 2px 8px rgba(14,165,233,.3);
}
.page-btn:disabled { opacity: .4; cursor: default; pointer-events: none; }

/* ══════════════════════════════
   EMPTY / ERROR STATES
══════════════════════════════ */
.empty-state {
    text-align: center; padding: 60px 24px;
    color: var(--text-soft); grid-column: 1/-1;
    background: var(--surface); border-radius: var(--radius);
    border: 1px solid var(--border); box-shadow: var(--shadow);
}
.empty-state i { font-size: 3rem; margin-bottom: 16px; display: block; opacity: .4; }
.empty-state p { font-size: 14px; line-height: 1.6; }

/* ══════════════════════════════
   SKELETON
══════════════════════════════ */
.skeleton { animation: pulse 1.4s ease-in-out infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
.skel { background: var(--border); border-radius: 8px; }

/* ══════════════════════════════
   TOAST
══════════════════════════════ */
.toast {
    position: fixed; bottom: 32px; left: 50%; transform: translateX(-50%) translateY(20px);
    background: var(--text); color: white; padding: 12px 24px; border-radius: var(--radius-sm);
    font-size: 13px; font-weight: 500; box-shadow: var(--shadow-md);
    opacity: 0; transition: var(--transition); pointer-events: none; z-index: 2000;
}
.toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

/* ══════════════════════════════
   RESPONSIVE
══════════════════════════════ */
@media (max-width: 1100px) { .stats-row { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 900px)  { .sidebar { display: none; } .main { padding: 20px 20px 80px; } }
@media (max-width: 768px)  {
    .navbar { padding: 0 16px; }
    .nav-search { display: none; }
    .profile-name { display: none; }
    .main { padding: 16px 16px 80px; }
}

/* Page load animation */
@keyframes fadeUp {
    from { opacity: 0; transform: translateY(16px); }
    to   { opacity: 1; transform: translateY(0); }
}
.page-header   { animation: fadeUp .4s ease both; }
.stats-row     { animation: fadeUp .4s .08s ease both; }
.filter-bar    { animation: fadeUp .4s .15s ease both; }
.resource-grid { animation: fadeUp .4s .22s ease both; }
</style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar">
    <a href="student-dashboard.php" class="navbar-brand">
        <img src="prepaura-logo.png" alt="Prepaura Logo" style="width:44px;height:44px;border-radius:10px;object-fit:contain;background:white;padding:3px;">
        <div style="display:flex;flex-direction:column;line-height:1.15;">
            <span style="font-family:'Sora',sans-serif;font-size:17px;font-weight:800;letter-spacing:.5px;color:white;">PREPAURA</span>
            <span style="font-size:10.5px;font-weight:400;color:rgba(255,255,255,.65);letter-spacing:.02em;">Placement Training Platform</span>
        </div>
    </a>

    <div class="nav-search">
        <i class="fa fa-search sicon"></i>
        <input type="text" id="searchInput" placeholder="Search resources…" autocomplete="off">
    </div>

    <div class="nav-profile">
        <div class="notif-dropdown-wrap">
            <button class="notification-btn" onclick="toggleNotifDropdown()" title="Notifications" id="notifBtn">
                <span>🔔</span>
                <?php if ($unreadCount > 0): ?>
                <div class="notif-badge" id="notifBadge"><?= $unreadCount ?></div>
                <?php endif; ?>
            </button>
            <div class="notif-dropdown" id="notifDropdown">
                <div class="notif-dropdown-header">Notifications</div>
                <div class="notif-list">
                    <?php if (empty($notifItems)): ?>
                        <div class="notif-empty">No notifications yet.</div>
                    <?php else: foreach ($notifItems as $n):
                        $isUnread = !$n['is_read'];
                        $typeIcons = ['info'=>'ℹ️','success'=>'✅','warning'=>'⚠️','error'=>'❌','assessment'=>'📝','result'=>'🏆','material'=>'📚'];
                        $icon = $typeIcons[$n['type']] ?? '🔔';
                    ?>
                    <div class="notif-item <?= $isUnread ? 'unread' : '' ?>">
                        <div class="notif-dot <?= $isUnread ? '' : 'read' ?>"></div>
                        <div class="notif-item-body">
                            <div class="notif-item-title"><?= $icon ?> <?= htmlspecialchars($n['title']) ?></div>
                            <?php if ($n['message']): ?>
                            <div class="notif-item-msg"><?= htmlspecialchars($n['message']) ?></div>
                            <?php endif; ?>
                            <div class="notif-item-time"><?= timeAgoPhp($n['created_at']) ?></div>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <div class="profile-wrapper" id="profileWrapper">
            <button class="profile-button" onclick="toggleDropdown()">
                <div class="profile-avatar"><?= $userInitials ?></div>
                <span class="profile-name"><?= $userName ?></span>
                <span class="dropdown-arrow">▼</span>
            </button>
            <div class="profile-dropdown" id="profileDropdown">
                <div class="dropdown-header">
                    <div class="dropdown-avatar"><?= $userInitials ?></div>
                    <div class="dropdown-user-info">
                        <div class="dropdown-name"><?= $userName ?></div>
                        <div class="dropdown-email"><?= htmlspecialchars($currentUser['email'] ?? '') ?></div>
                    </div>
                </div>
                <div style="padding: 8px 0;">
                    <a href="student-profile.php" class="dropdown-item"><span class="di">👤</span> My Profile</a>
                    <a href="help.html" target="_blank" rel="noopener noreferrer" class="dropdown-item"><span class="di">❓</span> Help &amp; Support</a>
                    <div class="dropdown-divider"></div>
                    <a href="#" onclick="if(confirm('Are you sure you want to logout?')) window.location.href='logout.php'" class="dropdown-item logout"><span class="di">🚪</span> Logout</a>
                </div>
            </div>
        </div>
    </div>
</nav>

<div class="page-wrapper">

<!-- SIDEBAR -->
<aside class="sidebar">
    <span class="sidebar-section">Navigation</span>
    <a href="student-dashboard.php"><i class="fa fa-home"></i> Dashboard</a>
    <a href="student-assessments.php"><i class="fa fa-clipboard-list"></i> Assessments</a>
    <a href="student-resources.php" class="active"><i class="fa fa-folder-open"></i> Resources</a>

    <span class="sidebar-section">Filter by Type</span>
    <a href="#" id="t-all"   onclick="setType('',this)"><i class="fa fa-layer-group"></i> All Types</a>
    <a href="#" id="t-pdf"   onclick="setType('pdf',this)"><i class="fa fa-file-pdf"   style="color:#ef4444"></i> PDF</a>
    <a href="#" id="t-video" onclick="setType('video',this)"><i class="fa fa-file-video" style="color:#f59e0b"></i> Video</a>
    <a href="#" id="t-image" onclick="setType('image',this)"><i class="fa fa-image"      style="color:#8b5cf6"></i> Images</a>

    <div class="sidebar-bottom">
        <button onclick="if(confirm('Are you sure you want to logout?')) window.location.href='logout.php'">
            <i class="fa fa-sign-out-alt"></i> Logout
        </button>
    </div>
</aside>

<!-- MAIN -->
<main class="main">

    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-left">
            <h1>📚 My Resources</h1>
            <p>Study materials and files shared by your teachers</p>
        </div>
        <div class="page-header-right">
            <strong id="st-total-banner">—</strong>
            total resources
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon si-blue"><i class="fa fa-book-open"></i></div>
            <div><div class="stat-val" id="st-total">—</div><div class="stat-lbl">Total Resources</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon si-green"><i class="fa fa-eye"></i></div>
            <div><div class="stat-val" id="st-views">—</div><div class="stat-lbl">Total Views</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon si-orange"><i class="fa fa-download"></i></div>
            <div><div class="stat-val" id="st-dl">—</div><div class="stat-lbl">Downloads</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon si-purple"><i class="fa fa-hard-drive"></i></div>
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
                    <div class="skel" style="height:15px;width:80%;margin-bottom:8px"></div>
                    <div class="skel" style="height:11px;width:50%"></div>
                </div>
            </div>
            <div class="skel" style="height:12px;width:90%"></div>
            <div class="skel" style="height:12px;width:65%"></div>
            <div style="display:flex;gap:8px">
                <div class="skel" style="flex:1;height:36px;border-radius:8px"></div>
                <div class="skel" style="width:50px;height:36px;border-radius:8px"></div>
            </div>
        </div>
        <?php endfor; ?>
    </div>

    <div class="pagination" id="pagination"></div>

</main>
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
    pdf:     ['ic-pdf',     'fa-file-pdf'],
    video:   ['ic-video',   'fa-file-video'],
    link:    ['ic-file',    'fa-link'],
    article: ['ic-file',    'fa-newspaper'],
    quiz:    ['ic-purple',  'fa-circle-question'],
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

/* ── Notifications ── */
function toggleNotifDropdown() {
    const dd = document.getElementById('notifDropdown');
    const isOpen = dd.classList.contains('show');
    document.getElementById('profileDropdown').classList.remove('open');
    dd.classList.toggle('show', !isOpen);
    if (!isOpen) {
        fetch('api/notifications/mark-read.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': CSRF_TOKEN, 'Content-Type': 'application/json' }
        }).then(() => {
            const badge = document.getElementById('notifBadge');
            if (badge) badge.remove();
            document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
            document.querySelectorAll('.notif-dot:not(.read)').forEach(el => el.classList.add('read'));
        }).catch(() => {});
    }
}

/* ── Profile dropdown ── */
function toggleDropdown() {
    document.getElementById('profileDropdown').classList.toggle('open');
    document.getElementById('notifDropdown').classList.remove('show');
}
document.addEventListener('click', e => {
    const w  = document.getElementById('profileWrapper');
    const nw = document.querySelector('.notif-dropdown-wrap');
    if (w  && !w.contains(e.target))  document.getElementById('profileDropdown').classList.remove('open');
    if (nw && !nw.contains(e.target)) document.getElementById('notifDropdown').classList.remove('show');
});

/* ── Fetch ── */
async function load() {
    const p = new URLSearchParams({ page: currentPage, limit: LIMIT });
    if (activeCat)  p.set('category', activeCat);
    if (activeType) p.set('type', activeType);
    if (searchQ)    p.set('search', searchQ);

    try {
        const res  = await fetch('api/resources/get-resources.php?' + p);
        const data = await res.json();
        if (!data.success) { showError(data.error||'Failed to load.'); return; }

        const s = data.stats||{};
        document.getElementById('st-total').textContent = fmtNum(s.total_materials);
        document.getElementById('st-total-banner').textContent = fmtNum(s.total_materials);
        document.getElementById('st-views').textContent = fmtNum(s.total_views);
        document.getElementById('st-dl').textContent    = fmtNum(s.total_downloads);
        document.getElementById('st-size').textContent  = fmtSize(s.storage_used_bytes)||'0 B';

        renderGrid(data.materials||[], data.total||0);
        renderPagination(data.pages||1);
    } catch(e) {
        showError('Could not reach server.');
    }
}

/* ── Render ── */
function renderGrid(mats, total) {
    const grid  = document.getElementById('resourceGrid');
    const label = document.getElementById('sectionLabel');

    label.textContent = total
        ? total + (total===1?' Resource':' Resources')
        : 'No resources found';

    if (!mats.length) {
        grid.innerHTML = `<div class="empty-state"><i class="fa fa-folder-open"></i><p>No resources match your filters.<br>Check back later or try a different category.</p></div>`;
        return;
    }

    grid.innerHTML = mats.map(m => {
        const [ic, fa] = iconFor(m.material_type);

        const catBadge  = m.category   ? `<span class="badge-cat">${esc(m.category)}</span>`   : '';
        const diffBadge = m.difficulty ? `<span class="badge-diff ${esc(m.difficulty)}">${esc(m.difficulty)}</span>` : '';

        const prog = m.user_progress;
        const progHtml = (prog!==null && prog!==undefined)
            ? `<div class="prog-wrap"><div class="prog-bar"><div class="prog-fill" style="width:${prog}%"></div></div><span>${prog}%${m.is_completed?' ✓':''}</span></div>`
            : '';

        const isLink = m.material_type === 'link';

        const primaryBtn = isLink
            ? `<a class="btn-view" href="${esc(m.external_url)}" target="_blank" rel="noopener"><i class="fa fa-external-link-alt"></i> Open</a>`
            : `<button class="btn-view" onclick="openFile(${m.material_id},'${esc(m.material_type)}')"><i class="fa fa-eye"></i> View</button>`;

        const dlBtn = (!isLink && m.file_path)
            ? `<button class="btn-dl" onclick="dlFile(${m.material_id},'${esc(m.title)}')"><i class="fa fa-download"></i></button>`
            : '';

        return `
        <div class="resource-card">
            <div class="card-top">
                <div class="ricon ${ic}"><i class="fa ${fa}"></i></div>
                <div style="flex:1;min-width:0">
                    <div class="card-title" title="${esc(m.title)}">${esc(m.title)}</div>
                    <div class="card-badges">${catBadge}${diffBadge}</div>
                </div>
            </div>
            ${m.description ? `<div class="card-desc">${esc(m.description)}</div>` : ''}
            <div class="card-meta">
                ${m.uploaded_by_name        ? `<span><i class="fa fa-user"></i>${esc(m.uploaded_by_name)}</span>` : ''}
                ${m.file_size               ? `<span><i class="fa fa-database"></i>${fmtSize(m.file_size)}</span>` : ''}
                ${m.estimated_time_minutes  ? `<span><i class="fa fa-clock"></i>${m.estimated_time_minutes} min</span>` : ''}
                <span><i class="fa fa-eye"></i>${m.views||0}</span>
                <span><i class="fa fa-download"></i>${m.downloads||0}</span>
                ${m.created_at ? `<span><i class="fa fa-clock"></i>${timeAgo(m.created_at)}</span>` : ''}
            </div>
            ${progHtml}
            <div class="card-actions">${primaryBtn}${dlBtn}</div>
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
            html += `<span style="align-self:center;color:var(--text-soft)">…</span>`;
    }
    html += `<button class="page-btn" onclick="goPage(${currentPage+1})" ${currentPage===totalPages?'disabled':''}>›</button>`;
    pg.innerHTML = html;
}
function goPage(p) { currentPage=p; window.scrollTo({top:0,behavior:'smooth'}); load(); }

/* ── Actions ── */
function openFile(id, type) {
    if (['pdf','video'].includes(type))
        window.open('api/resources/view-resource.php?material_id='+id, '_blank');
    else
        dlFile(id,'');
}
function dlFile(id, title) {
    const a = document.createElement('a');
    a.href = 'api/resources/serve-resource.php?material_id='+id+'&action=download';
    a.download = title||'';
    document.body.appendChild(a); a.click(); a.remove();
    toast('Downloading…');
}

/* ── Filters ── */
function setCat(cat, el) {
    activeCat=cat; currentPage=1;
    document.querySelectorAll('.filter-bar .filter-tab').forEach(b=>b.classList.remove('active'));
    el.classList.add('active'); load();
}
function setType(type, el) {
    activeType=type; currentPage=1;
    document.querySelectorAll('.sidebar a[id^="t-"]').forEach(a=>a.classList.remove('active'));
    el.classList.add('active'); load();
}

let st;
document.getElementById('searchInput').addEventListener('input', e=>{
    clearTimeout(st);
    st=setTimeout(()=>{ searchQ=e.target.value.trim(); currentPage=1; load(); }, 350);
});

/* ── Toast ── */
function toast(msg) {
    const t=document.getElementById('toast');
    t.textContent=msg; t.classList.add('show');
    setTimeout(()=>t.classList.remove('show'),2800);
}
function showError(msg) {
    document.getElementById('resourceGrid').innerHTML=`<div class="empty-state"><i class="fa fa-circle-exclamation" style="color:var(--danger)"></i><p>${msg}</p></div>`;
    document.getElementById('sectionLabel').textContent='Error';
}

document.getElementById('t-all').classList.add('active');
load();

// ── Live notification badge polling ──
let lastUnreadCount = <?= $unreadCount ?>;

function updateNotifBadge(count) {
    let badge = document.getElementById('notifBadge');
    if (count > 0) {
        if (!badge) {
            badge = document.createElement('div');
            badge.id = 'notifBadge';
            badge.className = 'notif-badge';
            document.getElementById('notifBtn').appendChild(badge);
        }
        badge.textContent = count > 99 ? '99+' : count;
    } else {
        if (badge) badge.remove();
    }
}

function pollNotifications() {
    fetch('api/notifications/unread-count.php')
        .then(r => r.json())
        .then(data => {
            if (data.success && typeof data.count === 'number') {
                updateNotifBadge(data.count);
                lastUnreadCount = data.count;
            }
        })
        .catch(() => {});
}

setInterval(pollNotifications, 30000);
</script>
</body>
</html>

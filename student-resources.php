<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db-guard.php';


$currentUser = validateSession($conn);
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

// ── Latest 5 notifications for dropdown ──
$notifDropResult = safePreparedQuery($conn,
    "SELECT notification_id, title, message, notification_type, is_read, created_at
     FROM notifications WHERE user_id = ?
     ORDER BY created_at DESC LIMIT 5",
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
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --primary:      #234C6A;
    --primary-dark: #456882;
}

body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: linear-gradient(135deg, #D3DAD9 0%, white 100%);
    min-height: 100vh;
}

/* ── NAVBAR (exact match) ── */
.navbar {
    background: var(--primary);
    padding: 0 30px;
    height: 70px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: 0 2px 10px rgba(0,0,0,0.15);
}
.navbar-brand {
    display: flex;
    align-items: center;
    gap: 12px;
    text-decoration: none;
}
.brand-logo {
    width: 42px; height: 42px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    color: white; font-size: 18px; font-weight: bold;
    border: 2px solid rgba(255,255,255,0.3);
}
.brand-name { color: white; font-size: 20px; font-weight: 600; }

.nav-search {
    flex: 1; max-width: 500px; margin: 0 30px;
    position: relative;
}
.nav-search input {
    width: 100%;
    padding: 10px 20px 10px 45px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-family: inherit; font-size: 14px;
    background: #f7fafc; color: #2d3748;
    outline: none;
    transition: border-color .2s, box-shadow .2s;
}
.nav-search input:focus { border-color: #4facfe; box-shadow: 0 0 0 3px rgba(79,172,254,.15); }
.nav-search .sicon {
    position: absolute; left: 15px; top: 50%; transform: translateY(-50%);
    color: #a0aec0; font-size: 14px;
}

.nav-right { display: flex; align-items: center; gap: 15px; }

.notification-btn {
    position: relative;
    width: 40px; height: 40px;
    background: #f7fafc; border-radius: 10px;
    border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; transition: 0.3s;
}
.notification-btn:hover { background: #e2e8f0; }
/* Notification dropdown */
.notif-dropdown-wrap { position: relative; }
.notif-dropdown {
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    background: white;
    border-radius: 14px;
    box-shadow: 0 8px 30px rgba(0,0,0,0.15);
    width: 340px;
    opacity: 0;
    visibility: hidden;
    transform: translateY(-8px);
    transition: 0.25s;
    z-index: 1002;
}
.notif-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0); }
.notif-dropdown-header {
    padding: 16px 20px 12px;
    font-weight: 700; font-size: 15px; color: #2d3748;
    border-bottom: 1px solid #e2e8f0;
}
.notif-list { max-height: 320px; overflow-y: auto; }
.notif-item {
    display: flex; gap: 12px; align-items: flex-start;
    padding: 14px 20px;
    border-bottom: 1px solid #f0f4f8;
    cursor: pointer; transition: background .15s;
}
.notif-item:hover { background: #f7fafc; }
.notif-item.unread { background: #f0f7ff; }
.notif-item.unread:hover { background: #e6f0fb; }
.notif-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: #4facfe; flex-shrink: 0; margin-top: 5px;
}
.notif-dot.read { background: transparent; }
.notif-item-body { flex: 1; }
.notif-item-title { font-size: 13px; font-weight: 600; color: #2d3748; margin-bottom: 3px; }
.notif-item-msg { font-size: 12px; color: #718096; line-height: 1.4; }
.notif-item-time { font-size: 11px; color: #a0aec0; margin-top: 4px; }
.notif-see-all {
    display: block; text-align: center;
    padding: 12px; font-size: 13px; font-weight: 600;
    color: #4facfe; text-decoration: none;
    border-top: 1px solid #e2e8f0;
    transition: background .15s; border-radius: 0 0 14px 14px;
}
.notif-see-all:hover { background: #f7fafc; }
.notif-empty { padding: 28px 20px; text-align: center; color: #a0aec0; font-size: 13px; }
.notif-badge {
    position: absolute; top: -5px; right: -5px;
    background: #e53e3e; color: white;
    width: 20px; height: 20px; border-radius: 50%;
    font-size: 11px; font-weight: bold;
    display: flex; align-items: center; justify-content: center;
    animation: badgePulse 1.8s ease-in-out infinite;
    box-shadow: 0 0 0 0 rgba(229,62,62,0.6);
}
@keyframes badgePulse {
    0%   { box-shadow: 0 0 0 0 rgba(229,62,62,0.6); }
    70%  { box-shadow: 0 0 0 7px rgba(229,62,62,0); }
    100% { box-shadow: 0 0 0 0 rgba(229,62,62,0); }
}

.profile-button {
    display: flex; align-items: center; gap: 10px;
    background: #f7fafc;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    padding: 6px 14px 6px 6px;
    cursor: pointer; transition: background .2s;
    font-family: inherit;
}
.profile-button:hover { background: #e2e8f0; }
.profile-avatar {
    width: 32px; height: 32px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: white; font-size: 12px; font-weight: 700;
}
.profile-name { color: #2d3748; font-size: 14px; font-weight: 500; }

/* ── LAYOUT ── */
.page-wrapper {
    display: flex;
    min-height: calc(100vh - 70px);
}

/* ── SIDEBAR ── */
.sidebar {
    width: 230px;
    flex-shrink: 0;
    padding: 24px 12px;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.sidebar-section {
    font-size: 11px; font-weight: 600;
    text-transform: uppercase; letter-spacing: .08em;
    color: #718096;
    padding: 14px 12px 6px;
}
.sidebar a {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px;
    border-radius: 10px;
    text-decoration: none;
    font-size: 14px; font-weight: 500;
    color: #4a5568;
    transition: background .15s, color .15s;
}
.sidebar a:hover { background: rgba(35,76,106,.08); color: var(--primary); }
.sidebar a.active {
    background: rgba(35,76,106,.12);
    color: var(--primary);
    font-weight: 600;
}
.sidebar a i { width: 18px; text-align: center; font-size: 15px; }
.sidebar-bottom {
    margin-top: auto;
    padding-top: 12px;
    border-top: 1px solid rgba(35,76,106,.12);
}
.sidebar-bottom button {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px; border-radius: 10px;
    font-size: 14px; font-weight: 500;
    color: #e53e3e; background: none; border: none;
    cursor: pointer; width: 100%;
    transition: background .15s, color .15s;
    font-family: inherit;
}
.sidebar-bottom button:hover { background: rgba(229,62,62,.08); }
.sidebar-bottom button i { width: 18px; text-align: center; font-size: 15px; }

/* ── MAIN ── */
.main { flex: 1; padding: 28px 28px 100px; }

/* ── STATS ── */
.stats-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
.stat-card {
    background: white;
    border-radius: 20px;
    padding: 20px 22px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    display: flex;
    align-items: center;
    gap: 16px;
}
.stat-icon {
    width: 48px; height: 48px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.si-blue   { background: rgba(79,172,254,.15); color: #4facfe; }
.si-green  { background: rgba(72,187,120,.15); color: #48bb78; }
.si-orange { background: rgba(237,137,54,.15); color: #ed8936; }
.si-purple { background: rgba(159,122,234,.15); color: #9f7aea; }
.stat-val  { font-size: 1.6rem; font-weight: 700; color: #2d3748; line-height: 1; }
.stat-lbl  { font-size: 12px; color: #718096; margin-top: 4px; }

/* ── FILTER TABS (match dashboard filter-tab style) ── */
.filter-bar {
    display: flex; gap: 8px; flex-wrap: wrap;
    margin-bottom: 20px;
}
.filter-tab {
    padding: 8px 18px;
    border-radius: 8px;
    border: 2px solid #e2e8f0;
    background: white;
    font-family: inherit; font-size: 13px; font-weight: 500;
    cursor: pointer; color: #4a5568;
    transition: all .18s;
}
.filter-tab:hover:not(.active) { background: #e2e8f0; }
.filter-tab.active {
    background: linear-gradient(135deg, #4facfe, #00f2fe);
    border-color: transparent;
    color: white;
    font-weight: 600;
}

/* ── SECTION LABEL ── */
.section-label {
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .08em;
    color: #718096; margin-bottom: 16px;
}

/* ── RESOURCE GRID ── */
.resource-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 18px;
    margin-bottom: 32px;
}

/* ── RESOURCE CARD (match assessment-card style) ── */
.resource-card {
    background: white;
    border-radius: 15px;
    padding: 20px;
    box-shadow: 0 4px 15px rgba(79,172,254,0.1);
    border: 2px solid #e2e8f0;
    display: flex; flex-direction: column; gap: 12px;
    transition: transform .2s, box-shadow .2s, border-color .2s;
}
.resource-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(79,172,254,0.2);
    border-color: #4facfe;
}

.card-top { display: flex; align-items: flex-start; gap: 14px; }
.ricon {
    width: 46px; height: 46px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.3rem; flex-shrink: 0;
}
.ic-pdf     { background: #fff5f5; color: #fc8181; }
.ic-video   { background: #fffaf0; color: #ed8936; }
.ic-image   { background: #faf5ff; color: #9f7aea; }
.ic-purple  { background: #faf5ff; color: #9f7aea; }
.ic-file    { background: #f7fafc; color: #a0aec0; }

.card-title {
    font-size: 15px; font-weight: 600; color: #2d3748;
    line-height: 1.35;
    overflow: hidden; text-overflow: ellipsis;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
}
.card-badges { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 5px; }

.badge-cat {
    padding: 3px 10px; border-radius: 6px;
    font-size: 11px; font-weight: 600;
    background: #c6f6d5; color: #22543d;
}
.badge-diff {
    padding: 3px 10px; border-radius: 6px;
    font-size: 11px; font-weight: 600;
}
.badge-diff.beginner     { background: #c6f6d5; color: #22543d; }
.badge-diff.intermediate { background: #feebc8; color: #744210; }
.badge-diff.advanced     { background: #fed7d7; color: #742a2a; }

.card-desc {
    font-size: 13px; color: #718096; line-height: 1.45;
    overflow: hidden; text-overflow: ellipsis;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
}

.card-meta {
    display: flex; flex-wrap: wrap; gap: 10px;
    font-size: 12px; color: #718096;
}
.card-meta span { display: flex; align-items: center; gap: 4px; }

/* Progress bar */
.prog-wrap { display: flex; align-items: center; gap: 8px; font-size: 12px; color: #718096; }
.prog-bar  { flex: 1; height: 5px; background: #e2e8f0; border-radius: 50px; overflow: hidden; }
.prog-fill { height: 100%; background: linear-gradient(90deg, #4facfe, #00f2fe); border-radius: 50px; }

/* Card buttons */
.card-actions { display: flex; gap: 8px; }
.btn-view {
    flex: 1; padding: 9px 0;
    background: linear-gradient(135deg, #4facfe, #00f2fe);
    color: white; border: none; border-radius: 8px;
    font-family: inherit; font-size: 13px; font-weight: 600;
    cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;
    transition: opacity .2s, transform .15s; text-decoration: none;
}
.btn-view:hover { opacity: .9; transform: translateY(-1px); }
.btn-dl {
    padding: 9px 14px;
    background: white; color: #4facfe;
    border: 2px solid var(--primary); border-radius: 8px;
    font-family: inherit; font-size: 13px; font-weight: 600;
    cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;
    transition: all .2s;
}
.btn-dl:hover { background: #4facfe; color: white; border-color: #4facfe; }

/* ── PAGINATION ── */
.pagination { display: flex; justify-content: center; gap: 8px; margin-top: 8px; }
.page-btn {
    width: 38px; height: 38px; border-radius: 8px;
    border: 2px solid #e2e8f0; background: white;
    font-family: inherit; font-size: 14px; font-weight: 600;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    color: #4a5568; transition: all .18s;
}
.page-btn:hover, .page-btn.active {
    background: linear-gradient(135deg, #4facfe, #00f2fe);
    border-color: transparent; color: white;
}
.page-btn:disabled { opacity: .4; cursor: default; pointer-events: none; }

/* ── EMPTY STATE ── */
.empty-state {
    text-align: center; padding: 60px 24px;
    color: #a0aec0; grid-column: 1/-1;
}
.empty-state i { font-size: 3rem; margin-bottom: 16px; display: block; opacity: .4; }
.empty-state p { font-size: 14px; line-height: 1.6; }

/* ── SKELETON ── */
.skeleton { animation: pulse 1.4s ease-in-out infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
.skel { background: #e2e8f0; border-radius: 8px; }

/* ── PROFILE DROPDOWN ── */
.profile-wrapper { position: relative; }
.profile-button { cursor: pointer; }

.profile-dropdown {
    display: none;
    position: absolute; top: calc(100% + 10px); right: 0;
    background: white;
    border-radius: 12px;
    box-shadow: 0 8px 30px rgba(0,0,0,0.15);
    min-width: 220px;
    z-index: 200;
    overflow: hidden;
    border: 1px solid #e2e8f0;
}
.profile-dropdown.open { display: block; }

.dropdown-header {
    display: flex; align-items: center; gap: 12px;
    padding: 16px;
    border-bottom: 1px solid #e2e8f0;
}
.dropdown-avatar {
    width: 42px; height: 42px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: white; font-size: 14px; font-weight: 700; flex-shrink: 0;
}
.dropdown-name  { font-size: 14px; font-weight: 600; color: #2d3748; }
.dropdown-email { font-size: 12px; color: #718096; margin-top: 2px; }

.dropdown-divider { height: 1px; background: #e2e8f0; }

.dropdown-item { display: flex; align-items: center; gap: 10px; padding: 11px 16px; font-size: 14px; color: #2d3748; text-decoration: none; cursor: pointer; transition: background .15s; font-family: inherit; background: none; border: none; width: 100%; text-align: left; }
.dropdown-item .di { width: 20px; text-align: center; font-size: 16px; }
.dropdown-item i { width: 16px; text-align: center; color: #718096; }
.dropdown-item:hover { background: #f7fafc; }
.dropdown-item.logout { color: #e53e3e; }
.dropdown-item.logout i { color: #e53e3e; }
.dropdown-item.logout:hover { background: #fff5f5; }

/* ── FAB (exact match) ── */


/* ── TOAST ── */
.toast {
    position: fixed; bottom: 100px; left: 50%; transform: translateX(-50%) translateY(20px);
    background: #2d3748; color: white; padding: 12px 24px; border-radius: 10px;
    font-size: 13px; font-weight: 500; box-shadow: 0 8px 30px rgba(0,0,0,.15);
    opacity: 0; transition: all .3s; pointer-events: none; z-index: 999;
}
.toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

@media (max-width: 900px) { .stats-row { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 768px) { .sidebar { display: none; } .main { padding: 16px 16px 100px; } }
</style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar">
    <a href="student-dashboard.php" class="navbar-brand">
        <img src="prepaura-logo.png" alt="Prepaura Logo" style="width:44px;height:44px;border-radius:10px;object-fit:contain;background:white;padding:3px;">
        <div style="display:flex;flex-direction:column;line-height:1.1;color:white">
            <span style="font-size:18px;font-weight:800;letter-spacing:.5px">PREPAURA</span>
            <span style="font-size:11px;font-weight:400;opacity:.85;font-style:italic">Placement Training Platform</span>
        </div>
    </a>

    <div class="nav-search">
        <i class="fa fa-search sicon"></i>
        <input type="text" id="searchInput" placeholder="Search resources…" autocomplete="off">
    </div>

    <div class="nav-right">
        <div class="notif-dropdown-wrap">
            <button class="notification-btn" onclick="toggleNotifDropdown()" title="Notifications">
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
                        $icon = $typeIcons[$n['notification_type']] ?? '🔔';
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
                <a href="notifications.php" class="notif-see-all">See All</a>
            </div>
        </div>
        <div class="profile-wrapper" id="profileWrapper">
            <div class="profile-button" onclick="toggleDropdown()">
                <div class="profile-avatar"><?= $userInitials ?></div>
                <span class="profile-name"><?= $userName ?></span>
                <i class="fa fa-chevron-down" style="font-size:11px;color:#a0aec0;margin-left:4px"></i>
            </div>
            <div class="profile-dropdown" id="profileDropdown">
                <div class="dropdown-header">
                    <div class="dropdown-avatar"><?= $userInitials ?></div>
                    <div>
                        <div class="dropdown-name"><?= $userName ?></div>
                        <div class="dropdown-email"><?= htmlspecialchars($currentUser['email'] ?? '') ?></div>
                    </div>
                </div>
                <div class="dropdown-divider"></div>
                <a href="student-profile.php" class="dropdown-item"><span class="di">👤</span> My Profile</a>
                <a href="help.html" target="_blank" rel="noopener noreferrer" class="dropdown-item"><span class="di">❓</span> Help &amp; Support</a>
                <div class="dropdown-divider"></div>
                <a href="#" onclick="if(confirm('Are you sure you want to logout?')) window.location.href='logout.php'" class="dropdown-item logout"><span class="di">🚪</span> Logout</a>
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
    <a href="notifications.php" style="position:relative">
        <i class="fa fa-bell"></i> Notifications
        <?php if ($unreadCount > 0): ?>
        <span style="margin-left:auto;background:#e53e3e;color:white;font-size:11px;font-weight:700;padding:2px 7px;border-radius:20px;min-width:20px;text-align:center;"><?= $unreadCount ?></span>
        <?php endif; ?>
    </a>

    <span class="sidebar-section">Filter by Type</span>
    <a href="#" id="t-all"     onclick="setType('',this)"><i class="fa fa-layer-group"></i> All Types</a>
    <a href="#" id="t-pdf"     onclick="setType('pdf',this)"><i class="fa fa-file-pdf"         style="color:#fc8181"></i> PDF</a>
    <a href="#" id="t-video"   onclick="setType('video',this)"><i class="fa fa-file-video"     style="color:#ed8936"></i> Video</a>
    <a href="#" id="t-image"   onclick="setType('image',this)"><i class="fa fa-image"          style="color:#9f7aea"></i> Images</a>
    <div class="sidebar-bottom">
        <button onclick="if(confirm('Are you sure you want to logout?')) window.location.href='logout.php'">
            <i class="fa fa-sign-out-alt"></i> Logout
        </button>
    </div>
</aside>

<!-- MAIN -->
<main class="main">

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

/* ── Dropdown ── */
function toggleDropdown() {
    document.getElementById('profileDropdown').classList.toggle('open');
    document.getElementById('notifDropdown').classList.remove('show');
}
document.addEventListener('click', e => {
    const w = document.getElementById('profileWrapper');
    const nw = document.querySelector('.notif-dropdown-wrap');
    if (w && !w.contains(e.target))
        document.getElementById('profileDropdown').classList.remove('open');
    if (nw && !nw.contains(e.target))
        document.getElementById('notifDropdown').classList.remove('show');
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

        const catBadge  = m.category  ? `<span class="badge-cat">${esc(m.category)}</span>`  : '';
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
                ${m.uploaded_by_name ? `<span><i class="fa fa-user"></i>${esc(m.uploaded_by_name)}</span>` : ''}
                ${m.file_size        ? `<span><i class="fa fa-database"></i>${fmtSize(m.file_size)}</span>` : ''}
                ${m.estimated_time_minutes ? `<span><i class="fa fa-clock"></i>${m.estimated_time_minutes} min</span>` : ''}
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
            html += `<span style="align-self:center;color:#a0aec0">…</span>`;
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
    document.getElementById('resourceGrid').innerHTML=`<div class="empty-state"><i class="fa fa-circle-exclamation" style="color:#fc8181"></i><p>${msg}</p></div>`;
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
            document.querySelector('.notification-btn').appendChild(badge);
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

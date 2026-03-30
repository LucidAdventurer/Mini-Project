<?php
// ============================================================
// assessments.php — Student Assessment Hub
// Matches the exact design system of student-resources.php
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db-guard.php';

$currentUser  = validateSession($conn, 'student');
$userName     = htmlspecialchars($currentUser['full_name']);
$userInitials = strtoupper(substr($currentUser['full_name'], 0, 2));
$userId       = (int) $currentUser['user_id'];

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
<title>Assessments – PTA Platform</title>
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

/* ── NAVBAR ── */
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
.navbar-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; }
.nav-search { flex: 1; max-width: 500px; margin: 0 30px; position: relative; }
.nav-search input {
    width: 100%; padding: 10px 20px 10px 45px;
    border: 2px solid #e2e8f0; border-radius: 10px;
    font-family: inherit; font-size: 14px;
    background: #f7fafc; color: #2d3748; outline: none;
    transition: border-color .2s, box-shadow .2s;
}
.nav-search input:focus { border-color: #4facfe; box-shadow: 0 0 0 3px rgba(79,172,254,.15); }
.nav-search .sicon { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #a0aec0; font-size: 14px; }
.nav-right { display: flex; align-items: center; gap: 15px; }

/* Notifications */
.notification-btn {
    position: relative; width: 40px; height: 40px;
    background: #f7fafc; border-radius: 10px; border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center; font-size: 16px; transition: 0.3s;
}
.notification-btn:hover { background: #e2e8f0; }
.notif-dropdown-wrap { position: relative; }
.notif-dropdown {
    position: absolute; top: calc(100% + 10px); right: 0;
    background: white; border-radius: 14px; box-shadow: 0 8px 30px rgba(0,0,0,0.15);
    width: 340px; opacity: 0; visibility: hidden; transform: translateY(-8px);
    transition: 0.25s; z-index: 1002;
}
.notif-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0); }
.notif-dropdown-header { padding: 16px 20px 12px; font-weight: 700; font-size: 15px; color: #2d3748; border-bottom: 1px solid #e2e8f0; }
.notif-list { max-height: 320px; overflow-y: auto; }
.notif-item { display: flex; gap: 12px; align-items: flex-start; padding: 14px 20px; border-bottom: 1px solid #f0f4f8; cursor: pointer; transition: background .15s; }
.notif-item:hover { background: #f7fafc; }
.notif-item.unread { background: #f0f7ff; }
.notif-dot { width: 8px; height: 8px; border-radius: 50%; background: #4facfe; flex-shrink: 0; margin-top: 5px; }
.notif-dot.read { background: transparent; }
.notif-item-body { flex: 1; }
.notif-item-title { font-size: 13px; font-weight: 600; color: #2d3748; margin-bottom: 3px; }
.notif-item-msg { font-size: 12px; color: #718096; line-height: 1.4; }
.notif-item-time { font-size: 11px; color: #a0aec0; margin-top: 4px; }
.notif-see-all { display: block; text-align: center; padding: 12px; font-size: 13px; font-weight: 600; color: #4facfe; text-decoration: none; border-top: 1px solid #e2e8f0; transition: background .15s; border-radius: 0 0 14px 14px; }
.notif-see-all:hover { background: #f7fafc; }
.notif-empty { padding: 28px 20px; text-align: center; color: #a0aec0; font-size: 13px; }
.notif-badge {
    position: absolute; top: -5px; right: -5px;
    background: #e53e3e; color: white; width: 20px; height: 20px; border-radius: 50%;
    font-size: 11px; font-weight: bold; display: flex; align-items: center; justify-content: center;
    animation: badgePulse 1.8s ease-in-out infinite;
}
@keyframes badgePulse { 0%{box-shadow:0 0 0 0 rgba(229,62,62,0.6)} 70%{box-shadow:0 0 0 7px rgba(229,62,62,0)} 100%{box-shadow:0 0 0 0 rgba(229,62,62,0)} }

/* Profile */
.profile-button { display: flex; align-items: center; gap: 10px; background: #f7fafc; border: 2px solid #e2e8f0; border-radius: 10px; padding: 6px 14px 6px 6px; cursor: pointer; transition: background .2s; font-family: inherit; }
.profile-button:hover { background: #e2e8f0; }
.profile-avatar { width: 32px; height: 32px; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 12px; font-weight: 700; }
.profile-name { color: #2d3748; font-size: 14px; font-weight: 500; }
.profile-wrapper { position: relative; }
.profile-dropdown { display: none; position: absolute; top: calc(100% + 10px); right: 0; background: white; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,0.15); min-width: 220px; z-index: 200; overflow: hidden; border: 1px solid #e2e8f0; }
.profile-dropdown.open { display: block; }
.dropdown-header { display: flex; align-items: center; gap: 12px; padding: 16px; border-bottom: 1px solid #e2e8f0; }
.dropdown-avatar { width: 42px; height: 42px; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 14px; font-weight: 700; flex-shrink: 0; }
.dropdown-name { font-size: 14px; font-weight: 600; color: #2d3748; }
.dropdown-email { font-size: 12px; color: #718096; margin-top: 2px; }
.dropdown-divider { height: 1px; background: #e2e8f0; }
.dropdown-item { display: flex; align-items: center; gap: 10px; padding: 11px 16px; font-size: 14px; color: #2d3748; text-decoration: none; cursor: pointer; transition: background .15s; font-family: inherit; background: none; border: none; width: 100%; text-align: left; }
.dropdown-item .di { width: 20px; text-align: center; font-size: 16px; }
.dropdown-item:hover { background: #f7fafc; }
.dropdown-item.logout { color: #e53e3e; }
.dropdown-item.logout:hover { background: #fff5f5; }

/* ── LAYOUT ── */
.page-wrapper { display: flex; min-height: calc(100vh - 70px); align-items: stretch; }

/* ── SIDEBAR ── */
.sidebar { width: 230px; flex-shrink: 0; padding: 24px 12px; display: flex; flex-direction: column; gap: 2px; }
.sidebar-section { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .08em; color: #718096; padding: 14px 12px 6px; }
.sidebar a { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 10px; text-decoration: none; font-size: 14px; font-weight: 500; color: #4a5568; transition: background .15s, color .15s; }
.sidebar a:hover { background: rgba(35,76,106,.08); color: var(--primary); }
.sidebar a.active { background: rgba(35,76,106,.12); color: var(--primary); font-weight: 600; }
.sidebar a i { width: 18px; text-align: center; font-size: 15px; }
.sidebar-bottom { margin-top: auto; padding-top: 12px; border-top: 1px solid rgba(35,76,106,.12); }
.sidebar-bottom button { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 10px; font-size: 14px; font-weight: 500; color: #e53e3e; background: none; border: none; cursor: pointer; width: 100%; transition: background .15s; font-family: inherit; }
.sidebar-bottom button:hover { background: rgba(229,62,62,.08); }
.sidebar-bottom button i { width: 18px; text-align: center; font-size: 15px; }

/* ── MAIN ── */
.main { flex: 1; padding: 28px 28px 100px; }

/* ── STATS ── */
.stats-row { display: grid; grid-template-columns: repeat(4,1fr); gap: 16px; margin-bottom: 24px; }
.stat-card { background: white; border-radius: 20px; padding: 20px 22px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); display: flex; align-items: center; gap: 16px; }
.stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
.si-blue   { background: rgba(79,172,254,.15); color: #4facfe; }
.si-green  { background: rgba(72,187,120,.15); color: #48bb78; }
.si-orange { background: rgba(237,137,54,.15);  color: #ed8936; }
.si-purple { background: rgba(159,122,234,.15); color: #9f7aea; }
.stat-val  { font-size: 1.6rem; font-weight: 700; color: #2d3748; line-height: 1; }
.stat-lbl  { font-size: 12px; color: #718096; margin-top: 4px; }

/* ── FILTER TABS ── */
.filter-bar { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
.filter-tab { padding: 8px 18px; border-radius: 8px; border: 2px solid #e2e8f0; background: white; font-family: inherit; font-size: 13px; font-weight: 500; cursor: pointer; color: #4a5568; transition: all .18s; }
.filter-tab:hover:not(.active) { background: #e2e8f0; }
.filter-tab.active { background: linear-gradient(135deg, #4facfe, #00f2fe); border-color: transparent; color: white; font-weight: 600; }

/* ── SECTION HEADER ── */
.section-header { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; }
.section-header h2 { font-size: 15px; font-weight: 700; color: #2d3748; }
.section-count { background: #e2e8f0; color: #718096; padding: 2px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; }
.section-line { flex: 1; height: 1px; background: #e2e8f0; }

/* ── ASSESSMENT GRID ── */
.assessment-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 18px; margin-bottom: 36px; }

/* ── ASSESSMENT CARD ── */
.assessment-card { background: white; border-radius: 15px; padding: 20px; box-shadow: 0 4px 15px rgba(79,172,254,0.1); border: 2px solid #e2e8f0; display: flex; flex-direction: column; gap: 12px; transition: transform .2s, box-shadow .2s, border-color .2s; }
.assessment-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(79,172,254,0.2); border-color: #4facfe; }

.card-top { display: flex; align-items: flex-start; gap: 14px; }
.aicon { width: 46px; height: 46px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
.ai-pending   { background: #ebf8ff; color: #4facfe; }
.ai-completed { background: #f0fff4; color: #48bb78; }
.ai-failed    { background: #fff5f5; color: #fc8181; }

.card-title { font-size: 15px; font-weight: 600; color: #2d3748; line-height: 1.35; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.card-badges { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 5px; }
.badge-cat { padding: 3px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; background: #e9f4ff; color: #2b6cb0; }
.badge-diff { padding: 3px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; }
.badge-diff.easy   { background: #c6f6d5; color: #22543d; }
.badge-diff.medium { background: #feebc8; color: #744210; }
.badge-diff.hard   { background: #fed7d7; color: #742a2a; }

.card-desc { font-size: 13px; color: #718096; line-height: 1.45; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.card-meta { display: flex; flex-wrap: wrap; gap: 10px; font-size: 12px; color: #718096; }
.card-meta span { display: flex; align-items: center; gap: 4px; }
.card-divider { height: 1px; background: #e2e8f0; }

/* Result block */
.result-block { background: #f7fafc; border-radius: 10px; padding: 12px; display: flex; align-items: center; gap: 12px; }
.score-ring { width: 52px; height: 52px; border-radius: 50%; border: 3px solid; display: flex; flex-direction: column; align-items: center; justify-content: center; flex-shrink: 0; }
.score-ring.pass { border-color: #48bb78; }
.score-ring.fail { border-color: #fc8181; }
.ring-pct { font-size: 13px; font-weight: 800; line-height: 1; }
.ring-pct.pass { color: #48bb78; }
.ring-pct.fail { color: #fc8181; }
.ring-lbl { font-size: 9px; color: #a0aec0; font-weight: 500; }
.result-stats { flex: 1; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 4px 8px; }
.rstat-val { font-size: 15px; font-weight: 700; line-height: 1; }
.rstat-val.green  { color: #48bb78; }
.rstat-val.red    { color: #fc8181; }
.rstat-val.orange { color: #ed8936; }
.rstat-lbl { font-size: 10px; color: #a0aec0; text-transform: uppercase; letter-spacing: .04em; }
.pass-chip { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; margin-top: 4px; }
.pass-chip.pass { background: #c6f6d5; color: #276749; }
.pass-chip.fail { background: #fed7d7; color: #742a2a; }

/* Chips */
.deadline-chip { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; background: #feebc8; color: #744210; }
.inprogress-chip { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; background: #ebf8ff; color: #2b6cb0; }

/* Buttons */
.btn-start { flex: 1; padding: 9px 0; background: linear-gradient(135deg, #4facfe, #00f2fe); color: white; border: none; border-radius: 8px; font-family: inherit; font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px; transition: opacity .2s, transform .15s; text-decoration: none; }
.btn-start:hover { opacity: .9; transform: translateY(-1px); }
.btn-start.resume { background: linear-gradient(135deg, #f6ad55, #ed8936); }
.btn-start:disabled { background: #e2e8f0; color: #a0aec0; cursor: not-allowed; transform: none; opacity: 1; }
.btn-view { padding: 9px 14px; background: white; color: var(--primary); border: 2px solid var(--primary); border-radius: 8px; font-family: inherit; font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px; transition: all .2s; text-decoration: none; }
.btn-view:hover { background: var(--primary); color: white; }
.card-actions { display: flex; gap: 8px; }
.attempt-info { font-size: 12px; color: #718096; display: flex; align-items: center; gap: 4px; }
.attempt-info strong { color: #4a5568; }

/* ── DASHBOARD-STYLE PENDING CARD ── */
.dash-card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px; }
.dash-card-title { font-size: 17px; font-weight: 700; color: #2d3748; margin-bottom: 4px; }
.dash-card-category { font-size: 13px; color: #718096; }
.dash-card-meta { display: flex; gap: 18px; flex-wrap: wrap; margin: 10px 0; }
.dash-meta-item { display: flex; align-items: center; gap: 6px; font-size: 13px; color: #718096; }
.dash-card-actions { display: flex; gap: 10px; margin-top: 4px; }

/* ── EMPTY STATE ── */
.empty-state { text-align: center; padding: 50px 24px; color: #a0aec0; grid-column: 1/-1; }
.empty-state i { font-size: 3rem; margin-bottom: 16px; display: block; opacity: .4; }
.empty-state p { font-size: 14px; line-height: 1.6; }

/* ── SKELETON ── */
.skeleton { animation: pulse 1.4s ease-in-out infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
.skel { background: #e2e8f0; border-radius: 8px; }

/* ── TOAST ── */
.toast { position: fixed; bottom: 100px; left: 50%; transform: translateX(-50%) translateY(20px); background: #2d3748; color: white; padding: 12px 24px; border-radius: 10px; font-size: 13px; font-weight: 500; box-shadow: 0 8px 30px rgba(0,0,0,.15); opacity: 0; transition: all .3s; pointer-events: none; z-index: 999; }
.toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

@media (max-width: 900px) { .stats-row { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 768px) { .sidebar { display: none; } .main { padding: 16px 16px 100px; } }
</style>
</head>
<body>

<!-- ── NAVBAR ── -->
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
        <input type="text" id="searchInput" placeholder="Search assessments…" autocomplete="off">
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
                        $isUnread  = !$n['is_read'];
                        $typeIcons = ['info'=>'ℹ️','success'=>'✅','warning'=>'⚠️','error'=>'❌','assessment'=>'📝','result'=>'🏆','material'=>'📚'];
                        $icon      = $typeIcons[$n['notification_type']] ?? '🔔';
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

<!-- ── SIDEBAR ── -->
<aside class="sidebar">
    <span class="sidebar-section">Navigation</span>
    <a href="student-dashboard.php"><i class="fa fa-home"></i> Dashboard</a>
    <a href="assessments.php" class="active"><i class="fa fa-clipboard-list"></i> Assessments</a>
    <a href="teacher-students.php" class="sidebar-link"><i class="fa fa-user-graduate"></i> Students</a>
    <a href="student-resources.php"><i class="fa fa-folder-open"></i> Resources</a>
    <a href="notifications.php" style="position:relative">
        <i class="fa fa-bell"></i> Notifications
        <?php if ($unreadCount > 0): ?>
        <span style="margin-left:auto;background:#e53e3e;color:white;font-size:11px;font-weight:700;padding:2px 7px;border-radius:20px;min-width:20px;text-align:center;"><?= $unreadCount ?></span>
        <?php endif; ?>
    </a>

    <span class="sidebar-section">Filter by Difficulty</span>
    <a href="#" id="f-all"    onclick="setDiff('',this);return false;"><i class="fa fa-layer-group"></i> All Types</a>
    <a href="#" id="f-easy"   onclick="setDiff('easy',this);return false;"><i class="fa fa-circle-check" style="color:#48bb78"></i> Easy</a>
    <a href="#" id="f-medium" onclick="setDiff('medium',this);return false;"><i class="fa fa-circle-check" style="color:#ed8936"></i> Medium</a>
    <a href="#" id="f-hard"   onclick="setDiff('hard',this);return false;"><i class="fa fa-circle-check" style="color:#fc8181"></i> Hard</a>

    <div class="sidebar-bottom">
        <button onclick="if(confirm('Are you sure you want to logout?')) window.location.href='logout.php'">
            <i class="fa fa-sign-out-alt"></i> Logout
        </button>
    </div>
</aside>

<!-- ── MAIN ── -->
<main class="main">

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon si-blue"><i class="fa fa-clipboard-list"></i></div>
            <div><div class="stat-val" id="st-total">—</div><div class="stat-lbl">Total Available</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon si-orange"><i class="fa fa-hourglass-half"></i></div>
            <div><div class="stat-val" id="st-pending">—</div><div class="stat-lbl">Pending</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon si-green"><i class="fa fa-circle-check"></i></div>
            <div><div class="stat-val" id="st-done">—</div><div class="stat-lbl">Completed</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon si-purple"><i class="fa fa-trophy"></i></div>
            <div><div class="stat-val" id="st-passed">—</div><div class="stat-lbl">Passed</div></div>
        </div>
    </div>

    <!-- Category filter tabs -->
    <div class="filter-bar" id="catFilter">
        <button class="filter-tab active" data-cat="">All Categories</button>
        <button class="filter-tab" data-cat="aptitude">Aptitude</button>
        <button class="filter-tab" data-cat="technical">Technical</button>
        <button class="filter-tab" data-cat="coding">Coding</button>
        <button class="filter-tab" data-cat="reasoning">Reasoning</button>
        <button class="filter-tab" data-cat="english">English</button>
        <button class="filter-tab" data-cat="general">General</button>
    </div>

    <!-- ── PENDING SECTION ── -->
    <div class="section-header">
        <h2><i class="fa fa-hourglass-half" style="color:#ed8936;margin-right:6px"></i>Pending</h2>
        <span class="section-count" id="pendingCount">—</span>
        <div class="section-line"></div>
    </div>

    <div class="assessment-grid" id="pendingGrid">
        <?php for ($s = 0; $s < 3; $s++): ?>
        <div class="assessment-card skeleton">
            <div style="display:flex;gap:14px">
                <div class="skel" style="width:46px;height:46px;border-radius:10px;flex-shrink:0"></div>
                <div style="flex:1">
                    <div class="skel" style="height:15px;width:80%;margin-bottom:8px"></div>
                    <div class="skel" style="height:11px;width:50%"></div>
                </div>
            </div>
            <div class="skel" style="height:12px;width:90%"></div>
            <div class="skel" style="height:12px;width:65%"></div>
            <div class="skel" style="height:36px;border-radius:8px;margin-top:4px"></div>
        </div>
        <?php endfor; ?>
    </div>

    <!-- ── COMPLETED SECTION ── -->
    <div class="section-header" style="margin-top:36px">
        <h2><i class="fa fa-circle-check" style="color:#48bb78;margin-right:6px"></i>Completed</h2>
        <span class="section-count" id="completedCount">—</span>
        <div class="section-line"></div>
    </div>

    <div class="assessment-grid" id="completedGrid"></div>

</main>
</div>

<div class="toast" id="toast"></div>

<script>
const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;

let allPending   = [];
let allCompleted = [];
let activeCat    = '';
let activeDiff   = '';
let searchQ      = '';

function esc(s) {
    const d = document.createElement('div');
    d.textContent = String(s || '');
    return d.innerHTML;
}
function capFirst(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
function fmtDuration(m) {
    if (m < 60) return m + ' min';
    const h = Math.floor(m/60), r = m%60;
    return r ? `${h}h ${r}m` : `${h}h`;
}
function fmtDate(dt) {
    if (!dt) return '';
    return new Date(dt).toLocaleDateString('en-IN', {day:'numeric',month:'short',year:'numeric'});
}
function toast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg; t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2800);
}

function buildPendingCard(a) {
    const resume       = !!a.in_progress_attempt_id;
    const canAttempt   = a.can_attempt;
    const attemptsLeft = a.max_attempts - a.attempts_used;

    let btn = '';
    if (resume) {
        btn = `<a class="btn-start resume" href="take-assessment.php?id=${a.assessment_id}&attempt=${a.in_progress_attempt_id}">Resume Test</a>`;
    } else if (canAttempt) {
        btn = `<a class="btn-start" href="take-assessment.php?id=${a.assessment_id}">Start Test</a>`;
    } else {
        btn = `<button class="btn-start" disabled>No Attempts Left</button>`;
    }

    return `
    <div class="assessment-card">
        <div class="dash-card-header">
            <div>
                <div class="dash-card-title">${esc(a.title)}</div>
                <div class="dash-card-category">${esc(capFirst(a.category))}</div>
            </div>
            <span class="badge-diff ${esc(a.difficulty)}">${esc(capFirst(a.difficulty))}</span>
        </div>
        <div class="dash-card-meta">
            <div class="dash-meta-item"><span>❓</span><span>${a.question_count} Questions</span></div>
            <div class="dash-meta-item"><span>⏱️</span><span>${esc(fmtDuration(a.duration_minutes))}</span></div>
            <div class="dash-meta-item"><span>🏆</span><span>${a.total_marks} Points</span></div>
            <div class="dash-meta-item"><span>🔄</span><span>${attemptsLeft > 0 ? attemptsLeft + ' attempt(s) left' : 'No attempts left'}</span></div>
        </div>
        <div class="dash-card-actions">
            ${btn}
        </div>
    </div>`;
}

function buildCompletedCard(a) {
    const b   = a.best_attempt;
    const pct = parseFloat(b.percentage).toFixed(1);
    const rc  = b.passed ? 'pass' : 'fail';
    const ic  = b.passed ? 'ai-completed' : 'ai-failed';
    const ico = b.passed ? 'circle-check' : 'circle-xmark';

    const viewBtn  = a.show_results_immediately
        ? `<a class="btn-view" href="test-results.php?attempt_id=${b.attempt_id}"><i class="fa fa-eye"></i> Results</a>` : '';
    const retryBtn = a.can_attempt
        ? `<a class="btn-start" href="take-assessment.php?id=${a.assessment_id}" style="flex:none;padding:9px 14px"><i class="fa fa-rotate-right"></i> Retry</a>` : '';

    return `
    <div class="assessment-card">
        <div class="card-top">
            <div class="aicon ${ic}"><i class="fa fa-${ico}"></i></div>
            <div style="flex:1;min-width:0">
                <div class="card-title" title="${esc(a.title)}">${esc(a.title)}</div>
                <div class="card-badges">
                    <span class="badge-cat">${esc(capFirst(a.category))}</span>
                    <span class="badge-diff ${esc(a.difficulty)}">${esc(capFirst(a.difficulty))}</span>
                </div>
            </div>
        </div>
        <div class="result-block">
            <div class="score-ring ${rc}">
                <span class="ring-pct ${rc}">${pct}%</span>
                <span class="ring-lbl">score</span>
            </div>
            <div style="flex:1">
                <div class="result-stats">
                    <div><div class="rstat-val green">${b.correct}</div><div class="rstat-lbl">Correct</div></div>
                    <div><div class="rstat-val red">${b.wrong}</div><div class="rstat-lbl">Wrong</div></div>
                    <div><div class="rstat-val orange">${b.unanswered}</div><div class="rstat-lbl">Skipped</div></div>
                </div>
                <span class="pass-chip ${rc}">${b.passed ? '✓ Passed' : '✕ Failed'}</span>
            </div>
        </div>
        <div class="card-meta">
            <span><i class="fa fa-clock"></i>${esc(fmtDuration(a.duration_minutes))}</span>
            <span><i class="fa fa-circle-question"></i>${a.question_count} Qs</span>
            <span><i class="fa fa-star"></i>${parseFloat(b.score).toFixed(0)}/${a.total_marks}</span>
            <span><i class="fa fa-calendar"></i>${esc(fmtDate(b.submitted_at))}</span>
        </div>
        <div class="card-divider"></div>
        <div class="card-actions">
            ${viewBtn}
            ${retryBtn}
            ${!viewBtn && !retryBtn ? '<span class="attempt-info">Max attempts reached</span>' : ''}
        </div>
    </div>`;
}

function applyFilters() {
    const q = searchQ.toLowerCase().trim();
    function match(a) {
        if (activeCat  && a.category   !== activeCat)  return false;
        if (activeDiff && a.difficulty !== activeDiff) return false;
        if (q && !a.title.toLowerCase().includes(q) && !(a.description||'').toLowerCase().includes(q)) return false;
        return true;
    }
    const pending   = allPending.filter(match);
    const completed = allCompleted.filter(match);

    document.getElementById('pendingCount').textContent   = pending.length;
    document.getElementById('completedCount').textContent = completed.length;

    document.getElementById('pendingGrid').innerHTML = pending.length
        ? pending.map(buildPendingCard).join('')
        : `<div class="empty-state"><i class="fa fa-party-horn"></i><p>No pending assessments match your filter.<br>You're all caught up!</p></div>`;

    document.getElementById('completedGrid').innerHTML = completed.length
        ? completed.map(buildCompletedCard).join('')
        : `<div class="empty-state"><i class="fa fa-clipboard"></i><p>No completed assessments yet.<br>Start one from the Pending section above.</p></div>`;
}

async function load() {
    try {
        const res  = await fetch('api/assessment/get-assessments.php', {
            headers: { 'X-CSRF-Token': CSRF_TOKEN, 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed to load');

        allPending   = data.not_attended;
        allCompleted = data.attended;

        const passed = allCompleted.filter(a => a.best_attempt?.passed).length;
        document.getElementById('st-total').textContent   = allPending.length + allCompleted.length;
        document.getElementById('st-pending').textContent = allPending.length;
        document.getElementById('st-done').textContent    = allCompleted.length;
        document.getElementById('st-passed').textContent  = passed;

        applyFilters();
    } catch(e) {
        toast('Could not load assessments: ' + e.message);
        document.getElementById('pendingGrid').innerHTML =
            `<div class="empty-state"><i class="fa fa-circle-exclamation" style="color:#fc8181"></i><p>${esc(e.message)}</p></div>`;
        document.getElementById('completedGrid').innerHTML = '';
    }
}

/* Category tabs */
document.getElementById('catFilter').addEventListener('click', e => {
    const btn = e.target.closest('.filter-tab');
    if (!btn) return;
    document.querySelectorAll('#catFilter .filter-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    activeCat = btn.dataset.cat;
    applyFilters();
});

/* Sidebar difficulty */
function setDiff(diff, el) {
    activeDiff = diff;
    document.querySelectorAll('.sidebar a[id^="f-"]').forEach(a => a.classList.remove('active'));
    el.classList.add('active');
    applyFilters();
}

/* Search */
let st;
document.getElementById('searchInput').addEventListener('input', e => {
    clearTimeout(st);
    st = setTimeout(() => { searchQ = e.target.value.trim(); applyFilters(); }, 300);
});

/* Notifications */
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

/* Notification polling */
let lastUnread = <?= $unreadCount ?>;
function updateNotifBadge(count) {
    let badge = document.getElementById('notifBadge');
    if (count > 0) {
        if (!badge) { badge = document.createElement('div'); badge.id='notifBadge'; badge.className='notif-badge'; document.querySelector('.notification-btn').appendChild(badge); }
        badge.textContent = count > 99 ? '99+' : count;
    } else { if (badge) badge.remove(); }
}
function pollNotifications() {
    fetch('api/notifications/unread-count.php').then(r=>r.json()).then(d=>{
        if (d.success && typeof d.count==='number') { updateNotifBadge(d.count); lastUnread=d.count; }
    }).catch(()=>{});
}
setInterval(pollNotifications, 30000);

document.getElementById('f-all').classList.add('active');
load();
</script>
</body>
</html>

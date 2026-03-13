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

// ── Load all groups with member counts ──
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
    while ($row = $rg['result']->fetch_assoc()) {
        $groups[] = $row;
    }
    $rg['result']->free();
}

// ── Load all active students ──
$students = [];
$rs = safePreparedQuery($conn,
    "SELECT user_id, full_name, email, department, registration_number
     FROM users
     WHERE role = 'student' AND is_active = 1
     ORDER BY full_name ASC",
    "", []
);
if ($rs['success'] && $rs['result']) {
    while ($row = $rs['result']->fetch_assoc()) {
        $students[] = $row;
    }
    $rs['result']->free();
}

// ── Unread notifications count ──
$unreadCount = 0;
$rn = safePreparedQuery($conn,
    "SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0",
    "i", [$teacherId]
);
if ($rn['success'] && $rn['result']) {
    $unreadCount = (int)($rn['result']->fetch_assoc()['cnt'] ?? 0);
    $rn['result']->free();
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
<title>Manage Groups - Placement Portal</title>
<style>
:root {
    --primary:   #2E073F;
    --secondary: #AD49E1;
    --text:      #2d3748;
    --text-light:#718096;
    --bg:        #D3DAD9;
    --bg-light:  #f5f7fa;
    --white:     #ffffff;
    --border:    #e2e8f0;
    --success:   #48bb78;
    --error:     #f56565;
    --warning:   #f59e0b;
    --shadow-sm: 0 2px 10px rgba(0,0,0,.08);
    --shadow-md: 0 4px 20px rgba(0,0,0,.08);
    --shadow-lg: 0 8px 30px rgba(0,0,0,.15);
    --radius:    10px;
    --transition:all 0.2s ease;
}
* { margin:0; padding:0; box-sizing:border-box; }
body {
    font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;
    background:var(--bg); min-height:100vh;
    color:var(--text); padding-top:71px; overflow-x:hidden;
}

/* ── NAVBAR ── */
.navbar {
    background:var(--primary); padding:12px 28px;
    display:flex; align-items:center; justify-content:space-between;
    position:fixed; top:0; left:0; right:0; z-index:1000;
    box-shadow:0 2px 10px rgba(0,0,0,.15);
}
.navbar-brand {
    display:flex; align-items:center; gap:12px;
    font-size:20px; font-weight:700; color:white; text-decoration:none;
}
.brand-logo-img { width:44px; height:44px; border-radius:10px; object-fit:contain; flex-shrink:0; background:white; padding:4px; }
.brand-text-group { display:flex; flex-direction:column; line-height:1.1; color:white; }
.brand-name { font-size:18px; font-weight:800; letter-spacing:.5px; }
.brand-tagline { font-size:11px; font-weight:400; opacity:.85; font-style:italic; }
.page-wrapper { display:flex; min-height:calc(100vh - 71px); }
.left-sidebar { width:220px; flex-shrink:0; padding:24px 12px; display:flex; flex-direction:column; gap:2px; background:#D3DAD9; position:fixed; top:71px; left:0; height:calc(100vh - 71px); z-index:100; }
.left-sidebar-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#718096; padding:14px 12px 6px; }
.left-sidebar a { display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:10px; text-decoration:none; font-size:14px; font-weight:500; color:#4a5568; transition:background .15s, color .15s; }
.left-sidebar a:hover { background:rgba(46,7,63,.08); color:var(--primary); }
.left-sidebar a.active { background:rgba(46,7,63,.12); color:var(--primary); font-weight:600; }
.left-sidebar a i { width:18px; text-align:center; font-size:15px; }
.left-sidebar-bottom { margin-top:auto; padding-top:12px; border-top:1px solid rgba(46,7,63,.12); }
.left-sidebar-bottom button { display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:10px; font-size:14px; font-weight:500; color:#e53e3e; background:none; border:none; cursor:pointer; width:100%; transition:background .15s; font-family:inherit; }
.left-sidebar-bottom button:hover { background:rgba(229,62,62,.08); }
.left-sidebar-bottom button i { width:18px; text-align:center; font-size:15px; }
.page-content { flex:1; min-width:0; margin-left:220px; }

.notification-btn {
    position:relative; width:40px; height:40px;
    background:rgba(255,255,255,.1); border:none; border-radius:10px;
    display:flex; align-items:center; justify-content:center;
    cursor:pointer; font-size:18px; color:white; transition:var(--transition);
}
.notification-btn:hover { background:rgba(255,255,255,.2); }
.notif-badge {
    position:absolute; top:-4px; right:-4px;
    background:#ff6b6b; color:white; width:18px; height:18px;
    border-radius:50%; font-size:10px; font-weight:700;
    display:flex; align-items:center; justify-content:center;
}
.profile-button {
    display:flex; align-items:center; gap:10px; padding:8px 14px;
    background:rgba(255,255,255,.1); border:none; border-radius:10px;
    cursor:pointer; transition:var(--transition); position:relative;
}
.profile-button:hover { background:rgba(255,255,255,.2); }
.profile-avatar {
    width:34px; height:34px;
    background:linear-gradient(135deg,var(--primary),var(--secondary));
    border:2px solid rgba(255,255,255,.4); border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    color:white; font-weight:700; font-size:13px;
}
.profile-name { font-weight:600; font-size:14px; color:white; }
.profile-caret { color:rgba(255,255,255,.6); font-size:10px; }
.profile-dropdown {
    position:absolute; top:calc(100% + 12px); right:0;
    background:white; border-radius:var(--radius);
    box-shadow:var(--shadow-lg); min-width:220px;
    opacity:0; visibility:hidden; transform:translateY(-8px);
    transition:var(--transition); z-index:1001;
}
.profile-dropdown.open { opacity:1; visibility:visible; transform:translateY(0); }
.dropdown-header { padding:16px 20px; border-bottom:1px solid var(--border); }
.dropdown-name  { font-weight:700; font-size:14px; }
.dropdown-email { font-size:12px; color:var(--text-light); margin-top:2px; }
.dropdown-role  {
    display:inline-block; margin-top:6px; padding:2px 10px;
    background:linear-gradient(135deg,var(--primary),var(--secondary));
    color:white; border-radius:20px; font-size:11px; font-weight:600;
}
.dropdown-menu { padding:6px 0; }
.dropdown-item {
    display:flex; align-items:center; gap:12px;
    padding:11px 20px; color:var(--text); text-decoration:none;
    font-size:14px; cursor:pointer; border:none; background:none;
    width:100%; text-align:left; transition:var(--transition);
}
.dropdown-item:hover { background:var(--bg-light); }
.dropdown-item.danger { color:var(--error); }
.dropdown-item.danger:hover { background:#fff5f5; }
.dropdown-divider { height:1px; background:var(--border); margin:4px 0; }

/* ── LAYOUT ── */
.container { max-width:1400px; margin:0 auto; padding:30px 20px; }
.page-header {
    display:flex; align-items:center; justify-content:space-between;
    margin-bottom:28px; flex-wrap:wrap; gap:12px;
}
.page-title { font-size:26px; font-weight:700; color:var(--primary); }
.page-subtitle { font-size:14px; color:var(--text-light); margin-top:4px; }
.btn-create-group {
    display:inline-flex; align-items:center; gap:8px;
    padding:10px 20px; background:var(--secondary); color:white;
    border:none; border-radius:8px; font-size:14px; font-weight:600;
    cursor:pointer; transition:var(--transition); text-decoration:none;
}
.btn-create-group:hover { background:#9333ea; transform:translateY(-1px); }

/* ── TWO-COLUMN LAYOUT ── */
.two-col { display:grid; grid-template-columns:1fr 1fr; gap:24px; }
@media(max-width:900px) { .two-col { grid-template-columns:1fr; } }

/* ── PANEL ── */
.panel {
    background:white; border-radius:14px;
    box-shadow:var(--shadow-sm); overflow:hidden;
}
.panel-head {
    padding:18px 22px; border-bottom:1px solid var(--border);
    display:flex; align-items:center; justify-content:space-between;
}
.panel-title {
    font-size:16px; font-weight:700; display:flex; align-items:center; gap:8px;
}
.panel-count {
    font-size:12px; background:var(--bg); color:var(--text-light);
    padding:2px 10px; border-radius:99px; font-weight:600;
}
.panel-body { padding:0; }

/* ── SEARCH BAR ── */
.search-wrap { padding:14px 18px; border-bottom:1px solid var(--border); }
.search-inner { position:relative; }
.search-inner input {
    width:100%; padding:9px 36px 9px 12px;
    border:1.5px solid var(--border); border-radius:8px;
    font-size:13px; font-family:inherit; outline:none; transition:var(--transition);
}
.search-inner input:focus { border-color:var(--secondary); }
.search-inner .s-icon { position:absolute; right:11px; top:50%; transform:translateY(-50%); font-size:14px; color:#a0aec0; }

/* ── STUDENT LIST ── */
.student-list { max-height:520px; overflow-y:auto; }
.student-item {
    display:flex; align-items:center; gap:12px;
    padding:11px 18px; border-bottom:1px solid #f3f4f6;
    transition:background .12s; cursor:pointer;
}
.student-item:last-child { border-bottom:none; }
.student-item:hover { background:#faf5ff; }
.student-item.selected { background:#f3e8ff; }
.student-check {
    width:18px; height:18px; border-radius:5px; flex-shrink:0;
    border:2px solid var(--border); display:flex; align-items:center;
    justify-content:center; transition:var(--transition); font-size:11px;
}
.student-item.selected .student-check {
    background:var(--secondary); border-color:var(--secondary); color:white;
}
.student-avatar {
    width:34px; height:34px; border-radius:50%; flex-shrink:0;
    background:linear-gradient(135deg,var(--primary),var(--secondary));
    display:flex; align-items:center; justify-content:center;
    color:white; font-size:12px; font-weight:700;
}
.student-info { flex:1; min-width:0; }
.student-name { font-size:13px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.student-meta { font-size:11px; color:var(--text-light); margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.select-all-bar {
    padding:8px 18px; background:#faf5ff; border-bottom:1px solid var(--border);
    display:flex; align-items:center; justify-content:space-between;
}
.select-all-bar button {
    font-size:12px; font-weight:600; color:var(--secondary);
    border:none; background:none; cursor:pointer; padding:0;
}
.selected-count { font-size:12px; color:var(--text-light); }

/* ── GROUPS LIST ── */
.groups-list { padding:16px; display:flex; flex-direction:column; gap:12px; min-height:100px; }
.empty-groups {
    text-align:center; padding:48px 20px; color:var(--text-light);
}
.empty-groups .empty-icon { font-size:36px; margin-bottom:10px; }
.empty-groups .empty-title { font-size:15px; font-weight:600; margin-bottom:4px; }
.empty-groups .empty-sub { font-size:13px; }

.group-card {
    border:1.5px solid var(--border); border-radius:10px; overflow:hidden;
    transition:box-shadow .15s, border-color .15s;
}
.group-card:hover { border-color:var(--secondary); box-shadow:0 2px 12px rgba(173,73,225,.1); }
.group-card-head {
    display:flex; align-items:center; gap:12px;
    padding:13px 16px; cursor:pointer; user-select:none;
}
.group-icon {
    width:38px; height:38px; border-radius:9px; flex-shrink:0;
    background:linear-gradient(135deg,var(--primary),var(--secondary));
    display:flex; align-items:center; justify-content:center;
    color:white; font-size:16px;
}
.group-info { flex:1; min-width:0; }
.group-name { font-size:14px; font-weight:700; }
.group-desc { font-size:12px; color:var(--text-light); margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.group-badge {
    font-size:11px; font-weight:600; padding:3px 10px;
    background:#f3e8ff; color:var(--secondary); border-radius:99px; flex-shrink:0;
}
.group-chevron { color:var(--text-light); font-size:12px; transition:transform .2s; flex-shrink:0; }
.group-card.expanded .group-chevron { transform:rotate(180deg); }

.group-body { display:none; border-top:1px solid var(--border); }
.group-card.expanded .group-body { display:block; }
.group-actions-bar {
    display:flex; align-items:center; justify-content:space-between;
    padding:10px 16px; background:#faf5ff; border-bottom:1px solid var(--border);
}
.group-actions-bar button {
    font-size:12px; font-weight:600; padding:5px 12px;
    border-radius:6px; border:none; cursor:pointer; transition:var(--transition);
}
.btn-edit-group   { background:#e9d5ff; color:#7c3aed; }
.btn-edit-group:hover { background:#ddd6fe; }
.btn-delete-group { background:#fee2e2; color:var(--error); }
.btn-delete-group:hover { background:#fecaca; }
.btn-add-members  { background:#dcfce7; color:#16a34a; }
.btn-add-members:hover { background:#bbf7d0; }

.member-list { max-height:240px; overflow-y:auto; }
.member-item {
    display:flex; align-items:center; gap:10px;
    padding:9px 16px; border-bottom:1px solid #f3f4f6;
}
.member-item:last-child { border-bottom:none; }
.member-avatar {
    width:30px; height:30px; border-radius:50%; flex-shrink:0;
    background:linear-gradient(135deg,#6366f1,#8b5cf6);
    display:flex; align-items:center; justify-content:center;
    color:white; font-size:11px; font-weight:700;
}
.member-info { flex:1; min-width:0; }
.member-name { font-size:13px; font-weight:500; }
.member-meta { font-size:11px; color:var(--text-light); }
.btn-remove-member {
    border:none; background:none; color:#e53e3e; font-size:16px;
    cursor:pointer; padding:2px 6px; border-radius:4px; transition:var(--transition); flex-shrink:0;
}
.btn-remove-member:hover { background:#fee2e2; }
.member-empty { padding:16px; text-align:center; font-size:13px; color:var(--text-light); }
.group-footer {
    padding:10px 16px; display:flex; justify-content:flex-end;
}
.btn-add-here {
    font-size:12px; font-weight:600; padding:6px 14px;
    background:var(--secondary); color:white; border:none;
    border-radius:6px; cursor:pointer; transition:var(--transition);
}
.btn-add-here:hover { background:#9333ea; }

/* ── MODALS ── */
.modal-overlay {
    display:none; position:fixed; inset:0;
    background:rgba(0,0,0,.5); z-index:2000;
    align-items:center; justify-content:center; padding:20px;
}
.modal-overlay.open { display:flex; }
.modal {
    background:white; border-radius:14px; width:100%; max-width:480px;
    box-shadow:var(--shadow-lg); overflow:hidden;
    animation:modalIn .2s ease;
}
@keyframes modalIn { from{transform:scale(.95);opacity:0} to{transform:scale(1);opacity:1} }
.modal-head {
    padding:20px 24px 16px; border-bottom:1px solid var(--border);
    display:flex; align-items:center; justify-content:space-between;
}
.modal-title { font-size:17px; font-weight:700; }
.modal-close {
    background:none; border:none; font-size:20px; color:var(--text-light);
    cursor:pointer; line-height:1; padding:2px;
}
.modal-close:hover { color:var(--text); }
.modal-body { padding:20px 24px; }
.form-group { margin-bottom:16px; }
.form-label { display:block; font-size:13px; font-weight:600; margin-bottom:6px; }
.form-input {
    width:100%; padding:10px 12px; border:1.5px solid var(--border);
    border-radius:8px; font-size:13px; font-family:inherit;
    outline:none; transition:var(--transition);
}
.form-input:focus { border-color:var(--secondary); }
.form-textarea { resize:vertical; min-height:72px; }
.modal-footer {
    padding:14px 24px 20px; display:flex; justify-content:flex-end; gap:10px;
}
.btn-cancel {
    padding:9px 18px; border:1.5px solid var(--border); background:white;
    border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; transition:var(--transition);
}
.btn-cancel:hover { background:var(--bg-light); }
.btn-save {
    padding:9px 18px; background:var(--secondary); color:white;
    border:none; border-radius:8px; font-size:13px; font-weight:600;
    cursor:pointer; transition:var(--transition);
}
.btn-save:hover { background:#9333ea; }
.btn-save:disabled { opacity:.6; cursor:not-allowed; }
.btn-danger-confirm {
    padding:9px 18px; background:var(--error); color:white;
    border:none; border-radius:8px; font-size:13px; font-weight:600;
    cursor:pointer; transition:var(--transition);
}
.btn-danger-confirm:hover { background:#e53e3e; }

/* ── TOAST ── */
.toast {
    position:fixed; bottom:28px; right:28px; z-index:3000;
    padding:12px 20px; border-radius:10px; font-size:13px; font-weight:600;
    box-shadow:var(--shadow-lg); opacity:0; transform:translateY(10px);
    transition:all .25s ease; pointer-events:none;
}
.toast.show { opacity:1; transform:translateY(0); }
.toast.success { background:#dcfce7; color:#16a34a; border:1px solid #bbf7d0; }
.toast.error   { background:#fee2e2; color:#dc2626; border:1px solid #fecaca; }

/* ── ADD MEMBERS MODAL ── */
.add-members-search { position:relative; margin-bottom:10px; }
.add-members-search input {
    width:100%; padding:9px 36px 9px 12px;
    border:1.5px solid var(--border); border-radius:8px;
    font-size:13px; font-family:inherit; outline:none; transition:var(--transition);
}
.add-members-search input:focus { border-color:var(--secondary); }
.add-members-search .s-icon { position:absolute; right:11px; top:50%; transform:translateY(-50%); color:#a0aec0; }
.add-members-list { max-height:280px; overflow-y:auto; border:1.5px solid var(--border); border-radius:8px; }
.add-member-item {
    display:flex; align-items:center; gap:10px;
    padding:9px 12px; border-bottom:1px solid #f3f4f6; cursor:pointer;
    transition:background .1s;
}
.add-member-item:last-child { border-bottom:none; }
.add-member-item:hover { background:#faf5ff; }
.add-member-item.selected { background:#f3e8ff; }
.add-member-check {
    width:17px; height:17px; border-radius:4px; flex-shrink:0;
    border:2px solid var(--border); display:flex; align-items:center;
    justify-content:center; font-size:11px; transition:var(--transition);
}
.add-member-item.selected .add-member-check { background:var(--secondary); border-color:var(--secondary); color:white; }
.add-member-name { font-size:13px; font-weight:500; }
.add-member-meta { font-size:11px; color:var(--text-light); }
.add-members-count { font-size:12px; color:var(--text-light); margin-bottom:8px; }
.hidden { display:none !important; }
</style>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
</head>
<body>

<!-- ── NAVBAR ── -->
<nav class="navbar">
    <a href="teacher-dashboard.php" class="navbar-brand">
        <img src="prepaura-logo.png" alt="PREPAURA Logo" class="brand-logo-img">
        <div class="brand-text-group">
            <span class="brand-name">PREPAURA</span>
            <span class="brand-tagline">Placement Training Platform</span>
        </div>
    </a>
    <div class="nav-right" style="position:relative;display:flex;align-items:center;gap:15px;">
        <button class="notification-btn" id="notifBtn">
            🔔
            <?php if ($unreadCount > 0): ?>
            <span class="notif-badge"><?= $unreadCount > 9 ? '9+' : $unreadCount ?></span>
            <?php endif; ?>
        </button>
        <button class="profile-button" id="profileBtn">
            <div class="profile-avatar"><?= $userInitials ?></div>
            <span class="profile-name"><?= $userName ?></span>
            <span class="profile-caret">▼</span>
        </button>
        <div class="profile-dropdown" id="profileDropdown">
            <div class="dropdown-header">
                <div style="display:flex;flex-direction:column;align-items:flex-start;gap:8px;width:100%;text-align:left;">
                    <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--secondary));display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:15px;flex-shrink:0;overflow:hidden;"><?= $userInitials ?></div>
                    <div>
                        <div class="dropdown-name"><?= $userName ?></div>
                        <div class="dropdown-email"><?= $userEmail ?></div>
                    </div>
                </div>
            </div>
            <div class="dropdown-menu">
                <a href="teacher-profile.php" class="dropdown-item">👤 My Profile</a>
                <a href="help.html" target="_blank" rel="noopener" class="dropdown-item">❓ Help & Support</a>
                <div class="dropdown-divider"></div>
                <button onclick="handleLogout()" class="dropdown-item danger">🚪 Logout</button>
            </div>
        </div>
    </div>
</nav>

<!-- ── MAIN ── -->
<div class="page-wrapper">
    <aside class="left-sidebar">
        <span class="left-sidebar-label">Navigation</span>
        <a href="teacher-dashboard.php"><i class="fa fa-home"></i> Dashboard</a>
        <a href="teacher-assessments.php"><i class="fa fa-clipboard-list"></i> Assessments</a>
        <a href="manage-groups.php" class="active"><i class="fa fa-users"></i> Manage Groups</a>
        <a href="teacher-resources.php"><i class="fa fa-folder-open"></i> Resources</a>
        <a href="notifications.php"><i class="fa fa-bell"></i> Notifications</a>
        <div class="left-sidebar-bottom">
            <button onclick="handleLogout()"><i class="fa fa-sign-out-alt"></i> Logout</button>
        </div>
    </aside>
    <div class="page-content">
<div class="container">
    <div class="page-header">
        <div>
            <div class="page-title">👥 Manage Groups</div>
            <div class="page-subtitle">Create groups and assign students to control assessment access.</div>
        </div>
        <button class="btn-create-group" onclick="openCreateModal()">+ New Group</button>
    </div>

    <div class="two-col">

        <!-- LEFT: All Students -->
        <div class="panel">
            <div class="panel-head">
                <div class="panel-title">🎓 All Students <span class="panel-count" id="studentTotalCount"><?= count($students) ?></span></div>
            </div>
            <div class="search-wrap">
                <div class="search-inner">
                    <input type="text" id="studentSearch" placeholder="Search by name, email or reg. number…" oninput="filterStudents(this.value)">
                    <span class="s-icon">🔍</span>
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
                <div style="padding:40px;text-align:center;color:var(--text-light);">
                    <div style="font-size:32px;margin-bottom:8px;">🎓</div>
                    <div style="font-weight:600;">No active students yet</div>
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
            <div style="padding:14px 18px; border-top:1px solid var(--border); text-align:right;">
                <button class="btn-create-group" onclick="createGroupFromSelection()" style="font-size:13px;padding:8px 16px;">
                    Create Group from Selection
                </button>
            </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT: Groups -->
        <div class="panel">
            <div class="panel-head">
                <div class="panel-title">📂 Your Groups <span class="panel-count" id="groupTotalCount"><?= count($groups) ?></span></div>
                <button class="btn-create-group" onclick="openCreateModal()" style="font-size:12px;padding:7px 14px;">+ New Group</button>
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
                            <div class="group-icon">📂</div>
                            <div class="group-info">
                                <div class="group-name"><?= htmlspecialchars($g['name']) ?></div>
                                <?php if ($g['description']): ?>
                                <div class="group-desc"><?= htmlspecialchars($g['description']) ?></div>
                                <?php endif; ?>
                            </div>
                            <span class="group-badge"><?= (int)$g['member_count'] ?> student<?= $g['member_count'] != 1 ? 's' : '' ?></span>
                            <span class="group-chevron">▼</span>
                        </div>
                        <div class="group-body" id="gbody-<?= $g['group_id'] ?>">
                            <div class="group-actions-bar">
                                <div style="display:flex;gap:8px;">
                                    <button class="btn-edit-group" onclick="openEditModal(<?= $g['group_id'] ?>, '<?= htmlspecialchars(addslashes($g['name'])) ?>', '<?= htmlspecialchars(addslashes($g['description'] ?? '')) ?>')">✏️ Edit</button>
                                    <button class="btn-delete-group" onclick="openDeleteModal(<?= $g['group_id'] ?>, '<?= htmlspecialchars(addslashes($g['name'])) ?>')">🗑 Delete</button>
                                </div>
                                <button class="btn-add-members" onclick="openAddMembersModal(<?= $g['group_id'] ?>)">+ Add Students</button>
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
    </div><!-- /container -->
    </div><!-- /page-content -->
</div><!-- /page-wrapper -->

<!-- ── CREATE / EDIT MODAL ── -->
<div class="modal-overlay" id="groupModal">
    <div class="modal">
        <div class="modal-head">
            <div class="modal-title" id="groupModalTitle">New Group</div>
            <button class="modal-close" onclick="closeGroupModal()">×</button>
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
            <!-- Pre-selected students (create from selection) -->
            <div id="preSelectedBlock" style="display:none;">
                <label class="form-label">Pre-selected students</label>
                <div id="preSelectedChips" style="display:flex;flex-wrap:wrap;gap:6px;padding:8px;background:var(--bg-light);border-radius:8px;min-height:36px;"></div>
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
            <button class="modal-close" onclick="closeDeleteModal()">×</button>
        </div>
        <div class="modal-body">
            <p style="font-size:14px;line-height:1.6;color:var(--text-light);" id="deleteModalBody"></p>
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
            <button class="modal-close" onclick="closeAddMembersModal()">×</button>
        </div>
        <div class="modal-body">
            <div class="add-members-search">
                <input type="text" id="addMembersSearch" placeholder="Search students…" oninput="filterAddList(this.value)">
                <span class="s-icon">🔍</span>
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
// ── All students from PHP ──
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
    el.textContent = msg;
    el.className   = `toast ${type}`;
    void el.offsetWidth;
    el.classList.add('show');
    clearTimeout(_toastTimer);
    _toastTimer = setTimeout(() => el.classList.remove('show'), 3000);
}

// ── Profile dropdown ──
const profileBtn  = document.getElementById('profileBtn');
const profileDrop = document.getElementById('profileDropdown');
const notifBtn    = document.getElementById('notifBtn');
profileBtn.addEventListener('click', e => { e.stopPropagation(); profileDrop.classList.toggle('open'); });
document.addEventListener('click', () => profileDrop.classList.remove('open'));

function handleLogout() {
    if (confirm('Are you sure you want to logout?')) window.location.href = 'logout.php';
}

// ── Helpers ──
function esc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function initials(name) {
    return name.trim().substring(0, 2).toUpperCase();
}

// ==============================================================
// STUDENT LIST (left panel)
// ==============================================================
function filterStudents(q) {
    q = q.toLowerCase();
    document.querySelectorAll('#studentList .student-item').forEach(el => {
        const match = !q
            || el.dataset.name.includes(q)
            || el.dataset.email.includes(q)
            || el.dataset.reg.includes(q);
        el.classList.toggle('hidden', !match);
    });
    updateSelectedCount();
}

function toggleStudent(el) {
    el.classList.toggle('selected');
    updateSelectedCount();
}

function toggleSelectAll() {
    const visible  = [...document.querySelectorAll('#studentList .student-item:not(.hidden)')];
    const allSel   = visible.every(el => el.classList.contains('selected'));
    visible.forEach(el => el.classList.toggle('selected', !allSel));
    updateSelectedCount();
}

function updateSelectedCount() {
    const count = document.querySelectorAll('#studentList .student-item.selected').length;
    document.getElementById('selectedCount').textContent = `${count} selected`;
}

function getSelectedStudentIds() {
    return [...document.querySelectorAll('#studentList .student-item.selected')]
        .map(el => parseInt(el.dataset.id));
}

function createGroupFromSelection() {
    openCreateModal(true);
}

// ==============================================================
// GROUP ACCORDION
// ==============================================================
const loadedGroups = new Set(); // track which groups have had members fetched

async function toggleGroup(groupId) {
    const card = document.getElementById(`gcard-${groupId}`);
    const body = document.getElementById(`gbody-${groupId}`);
    const isOpen = card.classList.contains('expanded');

    // Close all
    document.querySelectorAll('.group-card.expanded').forEach(c => {
        c.classList.remove('expanded');
        c.querySelector('.group-body').style.display = 'none';
    });

    if (!isOpen) {
        card.classList.add('expanded');
        body.style.display = 'block';
        if (!loadedGroups.has(groupId)) {
            await loadGroupMembers(groupId);
        }
    }
}

async function loadGroupMembers(groupId) {
    const mlist = document.getElementById(`mlist-${groupId}`);
    try {
        const token = await getCsrfToken();
        const res   = await fetch(`api/groups/get-group-details.php?group_id=${groupId}`, {
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': token },
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error);

        loadedGroups.add(groupId);
        renderMemberList(groupId, data.group.members);
        // Update badge count
        const badge = document.querySelector(`#gcard-${groupId} .group-badge`);
        if (badge) badge.textContent = `${data.group.member_count} student${data.group.member_count != 1 ? 's' : ''}`;
    } catch(e) {
        mlist.innerHTML = `<div class="member-empty" style="color:var(--error);">Failed to load members.</div>`;
    }
}

function renderMemberList(groupId, members) {
    const mlist = document.getElementById(`mlist-${groupId}`);
    if (!members.length) {
        mlist.innerHTML = '<div class="member-empty">No students in this group yet.</div>';
        return;
    }
    mlist.innerHTML = members.map(m => `
        <div class="member-item" id="mi-${groupId}-${m.user_id}">
            <div class="member-avatar">${esc(initials(m.full_name))}</div>
            <div class="member-info">
                <div class="member-name">${esc(m.full_name)}</div>
                <div class="member-meta">${esc(m.email)}${m.registration_number ? ' · ' + esc(m.registration_number) : ''}</div>
            </div>
            <button class="btn-remove-member" title="Remove from group"
                    onclick="removeMember(${groupId}, ${m.user_id}, '${esc(m.full_name)}')">×</button>
        </div>
    `).join('');
}

// ==============================================================
// CREATE / EDIT GROUP MODAL
// ==============================================================
let _isEdit = false;

function openCreateModal(fromSelection = false) {
    _isEdit = false;
    document.getElementById('editGroupId').value = '';
    document.getElementById('groupNameInput').value  = '';
    document.getElementById('groupDescInput').value  = '';
    document.getElementById('groupModalTitle').textContent = 'New Group';
    document.getElementById('groupSaveBtn').textContent    = 'Create Group';

    // Pre-selected students
    const block  = document.getElementById('preSelectedBlock');
    const chips  = document.getElementById('preSelectedChips');
    const selIds = getSelectedStudentIds();
    if (fromSelection && selIds.length > 0) {
        const selStudents = ALL_STUDENTS.filter(s => selIds.includes(s.user_id));
        chips.innerHTML   = selStudents.map(s =>
            `<span style="font-size:12px;background:#e9d5ff;color:#7c3aed;padding:3px 10px;border-radius:99px;">${esc(s.full_name)}</span>`
        ).join('');
        block.style.display = '';
    } else {
        block.style.display = 'none';
        chips.innerHTML = '';
    }

    document.getElementById('groupModal').classList.add('open');
    setTimeout(() => document.getElementById('groupNameInput').focus(), 100);
}

function openEditModal(groupId, name, desc) {
    _isEdit = true;
    document.getElementById('editGroupId').value         = groupId;
    document.getElementById('groupNameInput').value      = name;
    document.getElementById('groupDescInput').value      = desc;
    document.getElementById('groupModalTitle').textContent = 'Edit Group';
    document.getElementById('groupSaveBtn').textContent    = 'Save Changes';
    document.getElementById('preSelectedBlock').style.display = 'none';
    document.getElementById('groupModal').classList.add('open');
    setTimeout(() => document.getElementById('groupNameInput').focus(), 100);
}

function closeGroupModal() {
    document.getElementById('groupModal').classList.remove('open');
}

async function saveGroup() {
    const name = document.getElementById('groupNameInput').value.trim();
    const desc = document.getElementById('groupDescInput').value.trim();
    if (!name) {
        document.getElementById('groupNameInput').focus();
        return;
    }

    const btn = document.getElementById('groupSaveBtn');
    btn.disabled = true;
    btn.textContent = _isEdit ? 'Saving…' : 'Creating…';

    try {
        const token = await getCsrfToken();
        let body;

        if (_isEdit) {
            body = { action: 'update', group_id: parseInt(document.getElementById('editGroupId').value), name, description: desc };
        } else {
            const selIds = getSelectedStudentIds();
            body = { action: 'create', name, description: desc, student_ids: selIds };
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
        btn.disabled    = false;
        btn.textContent = _isEdit ? 'Save Changes' : 'Create Group';
    }
}

// ── Enter key in name input ──
document.getElementById('groupNameInput').addEventListener('keydown', e => {
    if (e.key === 'Enter') saveGroup();
});

// Close modal on overlay click
document.getElementById('groupModal').addEventListener('click', e => {
    if (e.target === document.getElementById('groupModal')) closeGroupModal();
});

// ==============================================================
// DELETE GROUP MODAL
// ==============================================================
let _deleteGroupId = null;

function openDeleteModal(groupId, name) {
    _deleteGroupId = groupId;
    document.getElementById('deleteModalBody').textContent =
        `Delete "${name}"? All ${name}'s members will be removed from this group and the group will be removed from any assessments it was assigned to. This cannot be undone.`;
    document.getElementById('deleteModal').classList.add('open');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('open');
    _deleteGroupId = null;
}

document.getElementById('deleteModal').addEventListener('click', e => {
    if (e.target === document.getElementById('deleteModal')) closeDeleteModal();
});

document.getElementById('confirmDeleteBtn').addEventListener('click', async function() {
    if (!_deleteGroupId) return;
    this.disabled    = true;
    this.textContent = 'Deleting…';
    try {
        const token = await getCsrfToken();
        const res   = await fetch('api/groups/manage-group.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
            body: JSON.stringify({ action: 'delete', group_id: _deleteGroupId }),
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Delete failed');
        closeDeleteModal();
        showToast('Group deleted.');
        setTimeout(() => location.reload(), 800);
    } catch(e) {
        showToast(e.message, 'error');
        this.disabled    = false;
        this.textContent = 'Delete Group';
    }
});

// ==============================================================
// REMOVE SINGLE MEMBER
// ==============================================================
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

        // Update badge
        const mlist = document.getElementById(`mlist-${groupId}`);
        const remaining = mlist.querySelectorAll('.member-item').length;
        const badge = document.querySelector(`#gcard-${groupId} .group-badge`);
        if (badge) badge.textContent = `${remaining} student${remaining != 1 ? 's' : ''}`;
        if (!remaining) mlist.innerHTML = '<div class="member-empty">No students in this group yet.</div>';

        showToast(`${name} removed.`);
    } catch(e) {
        showToast(e.message, 'error');
    }
}

// ==============================================================
// ADD MEMBERS MODAL
// ==============================================================
let _addMembersGroupId   = null;
let _addMembersExisting  = new Set();
let _addMembersSelected  = new Set();

async function openAddMembersModal(groupId) {
    _addMembersGroupId  = groupId;
    _addMembersSelected = new Set();
    document.getElementById('addMembersSearch').value = '';
    document.getElementById('addMembersSaveBtn').disabled = false;
    document.getElementById('addMembersSaveBtn').textContent = 'Add Selected';

    // Load current members to exclude them
    try {
        const token = await getCsrfToken();
        const res   = await fetch(`api/groups/get-group-details.php?group_id=${groupId}`, {
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': token },
        });
        const data = await res.json();
        _addMembersExisting = new Set((data.group?.members ?? []).map(m => m.user_id));
    } catch(e) {
        _addMembersExisting = new Set();
    }

    renderAddList('');
    document.getElementById('addMembersModal').classList.add('open');
    setTimeout(() => document.getElementById('addMembersSearch').focus(), 100);
}

function closeAddMembersModal() {
    document.getElementById('addMembersModal').classList.remove('open');
    _addMembersGroupId = null;
}

document.getElementById('addMembersModal').addEventListener('click', e => {
    if (e.target === document.getElementById('addMembersModal')) closeAddMembersModal();
});

function renderAddList(q) {
    q = q.toLowerCase();
    const eligible = ALL_STUDENTS.filter(s =>
        !_addMembersExisting.has(s.user_id) &&
        (!q || s.full_name.toLowerCase().includes(q)
             || s.email.toLowerCase().includes(q)
             || (s.registration_number || '').toLowerCase().includes(q))
    );
    const count = document.getElementById('addMembersCount');
    count.textContent = eligible.length
        ? `${eligible.length} student${eligible.length != 1 ? 's' : ''} available`
        : '';

    const list = document.getElementById('addMembersList');
    if (!eligible.length) {
        list.innerHTML = '<div class="member-empty">No students to add.</div>';
        return;
    }
    list.innerHTML = eligible.map(s => {
        const sel = _addMembersSelected.has(s.user_id);
        const meta = [s.registration_number, s.department].filter(Boolean).join(' · ');
        return `<div class="add-member-item ${sel ? 'selected' : ''}"
                     data-id="${s.user_id}"
                     onclick="toggleAddMember(this, ${s.user_id})">
            <div class="add-member-check">${sel ? '✓' : ''}</div>
            <div>
                <div class="add-member-name">${esc(s.full_name)}</div>
                <div class="add-member-meta">${esc(s.email)}${meta ? ' · ' + esc(meta) : ''}</div>
            </div>
        </div>`;
    }).join('');
}

function filterAddList(q) {
    renderAddList(q);
}

function toggleAddMember(el, userId) {
    if (_addMembersSelected.has(userId)) {
        _addMembersSelected.delete(userId);
        el.classList.remove('selected');
        el.querySelector('.add-member-check').textContent = '';
    } else {
        _addMembersSelected.add(userId);
        el.classList.add('selected');
        el.querySelector('.add-member-check').textContent = '✓';
    }
}

async function saveAddMembers() {
    if (!_addMembersSelected.size) {
        showToast('Select at least one student.', 'error');
        return;
    }
    const btn = document.getElementById('addMembersSaveBtn');
    btn.disabled    = true;
    btn.textContent = 'Adding…';
    try {
        const token = await getCsrfToken();
        const res   = await fetch('api/groups/manage-group.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
            body: JSON.stringify({
                action:      'add_members',
                group_id:    _addMembersGroupId,
                student_ids: [..._addMembersSelected],
            }),
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error);

        closeAddMembersModal();
        showToast(`${data.added} student${data.added != 1 ? 's' : ''} added.`);

        // Refresh the member list in the accordion
        loadedGroups.delete(_addMembersGroupId);
        const card = document.getElementById(`gcard-${_addMembersGroupId}`);
        if (card?.classList.contains('expanded')) {
            await loadGroupMembers(_addMembersGroupId);
        }
    } catch(e) {
        showToast(e.message, 'error');
        btn.disabled    = false;
        btn.textContent = 'Add Selected';
    }
}
</script>
</body>
</html>

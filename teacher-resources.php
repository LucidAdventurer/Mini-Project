<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db-guard.php';

$currentUser  = validateSession($conn, 'teacher');
$teacherId    = (int) $currentUser['user_id'];
$userName     = htmlspecialchars($currentUser['full_name'] ?? 'Teacher');
$userEmail    = htmlspecialchars($currentUser['email'] ?? '');
$userInitials = strtoupper(substr($currentUser['full_name'] ?? 'T', 0, 2));

// Fetch profile_image (validateSession may not include it)
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
<title>Resources – Teacher | Placement Portal</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
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
    --shadow-sm: 0 2px 10px rgba(0,0,0,.08);
    --shadow-md: 0 4px 20px rgba(0,0,0,.08);
    --shadow-lg: 0 8px 30px rgba(0,0,0,.15);
    --radius:    10px;
    --transition:all 0.2s ease;
}
body { font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; background:var(--bg); min-height:100vh; color:var(--text); padding-top:71px; overflow-x:hidden; }

/* ── NAVBAR ── */
.navbar { background:var(--primary); padding:12px 28px; display:flex; align-items:center; justify-content:space-between; position:fixed; top:0; left:0; right:0; z-index:1000; box-shadow:0 2px 10px rgba(0,0,0,.15); }
.navbar-brand { display:flex; align-items:center; gap:12px; text-decoration:none; }
.brand-logo-img { width:44px; height:44px; border-radius:10px; object-fit:contain; flex-shrink:0; background:white; padding:4px; }
.brand-text-group { display:flex; flex-direction:column; line-height:1.1; color:white; }
.brand-name { font-size:18px; font-weight:800; letter-spacing:.5px; }
.brand-tagline { font-size:11px; font-weight:400; opacity:.85; font-style:italic; }
.nav-search { flex:1; max-width:500px; margin:0 30px; position:relative; }
.nav-search input { width:100%; padding:10px 40px 10px 15px; border:2px solid var(--border); border-radius:10px; font-size:14px; transition:var(--transition); font-family:inherit; }
.nav-search input:focus { outline:none; border-color:var(--secondary); }
.nav-search .sicon { position:absolute; right:15px; top:50%; transform:translateY(-50%); color:#a0aec0; font-size:16px; }
.nav-right { display:flex; align-items:center; gap:15px; position:relative; }

/* Notification */


/* Profile */
.profile-button { display:flex; align-items:center; gap:10px; padding:8px 14px; background:rgba(255,255,255,.1); border:none; border-radius:10px; cursor:pointer; transition:var(--transition); }
.profile-button:hover { background:rgba(255,255,255,.2); }
.profile-avatar { width:34px; height:34px; background:linear-gradient(135deg,var(--primary),var(--secondary)); border:2px solid rgba(255,255,255,.4); border-radius:50%; display:flex; align-items:center; justify-content:center; color:white; font-weight:700; font-size:13px; }
.profile-name { font-weight:600; font-size:14px; color:white; }
.profile-caret { color:rgba(255,255,255,.6); font-size:10px; }
.profile-dropdown { position:absolute; top:calc(100% + 12px); right:0; background:white; border-radius:var(--radius); box-shadow:var(--shadow-lg); min-width:220px; opacity:0; visibility:hidden; transform:translateY(-8px); transition:var(--transition); z-index:1001; }
.profile-dropdown.open { opacity:1; visibility:visible; transform:translateY(0); }
.dropdown-header { padding:16px 20px; border-bottom:1px solid var(--border); }
.dropdown-name { font-weight:500; font-size:14px; color:var(--text); }
.dropdown-email { font-size:12px; color:var(--text-light); margin-top:2px; }
.dropdown-menu { padding:6px 0; }
.dropdown-item { display:flex; align-items:center; gap:12px; padding:11px 20px; color:var(--text); text-decoration:none; font-size:14px; cursor:pointer; border:none; background:none; width:100%; text-align:left; transition:var(--transition); }
.dropdown-item:hover { background:var(--bg-light); }
.dropdown-item.danger { color:var(--error); }
.dropdown-item.danger:hover { background:#fff5f5; }
.dropdown-divider { height:1px; background:var(--border); margin:4px 0; }

/* ── LAYOUT ── */
.page-wrapper { display:flex; min-height:calc(100vh - 71px); }
.left-sidebar { width:220px; flex-shrink:0; padding:24px 12px; display:flex; flex-direction:column; gap:2px; background:var(--bg); position:fixed; top:71px; left:0; height:calc(100vh - 71px); z-index:100; }
.left-sidebar-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#718096; padding:14px 12px 6px; }
.left-sidebar a { display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:10px; text-decoration:none; font-size:14px; font-weight:500; color:#4a5568; transition:background .15s, color .15s; }
.left-sidebar a:hover { background:rgba(46,7,63,.08); color:var(--primary); }
.left-sidebar a.active { background:rgba(46,7,63,.12); color:var(--primary); font-weight:600; }
.left-sidebar a i { width:18px; text-align:center; font-size:15px; }
.left-sidebar-bottom { margin-top:auto; padding-top:12px; border-top:1px solid rgba(46,7,63,.12); }
.left-sidebar-bottom button { display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:10px; font-size:14px; font-weight:500; color:#e53e3e; background:none; border:none; cursor:pointer; width:100%; transition:background .15s; font-family:inherit; }
.left-sidebar-bottom button:hover { background:rgba(229,62,62,.08); }
.left-sidebar-bottom button i { width:18px; text-align:center; font-size:15px; }
.sidebar-filter-btn { display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:10px; font-size:14px; font-weight:500; color:#4a5568; background:none; border:none; cursor:pointer; width:100%; text-align:left; transition:background .15s, color .15s; font-family:inherit; }
.sidebar-filter-btn:hover { background:rgba(46,7,63,.08); color:var(--primary); }
.sidebar-filter-btn.active { background:rgba(46,7,63,.12); color:var(--primary); font-weight:600; }
.sidebar-filter-btn i { width:18px; text-align:center; font-size:15px; }
.main { flex:1; margin-left:220px; padding:28px 28px 100px; }

/* ── STATS ── */
.stats-row { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:24px; }
.stat-card { background:white; border-radius:20px; padding:20px 22px; box-shadow:var(--shadow-md); display:flex; align-items:center; gap:16px; }
.stat-icon { width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
.si-blue   { background:rgba(173,73,225,.12); color:var(--secondary); }
.si-green  { background:rgba(72,187,120,.15);  color:#48bb78; }
.si-orange { background:rgba(237,137,54,.15);  color:#ed8936; }
.si-purple { background:rgba(46,7,63,.12);     color:var(--primary); }
.stat-val { font-size:1.6rem; font-weight:700; color:var(--text); line-height:1; }
.stat-lbl { font-size:12px; color:var(--text-light); margin-top:4px; }

/* ── TOOLBAR ── */
.toolbar { display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; flex-wrap:wrap; gap:12px; }
.filter-bar { display:flex; gap:8px; flex-wrap:wrap; }
.filter-tab { padding:8px 18px; border-radius:8px; border:2px solid var(--border); background:white; font-family:inherit; font-size:13px; font-weight:500; cursor:pointer; color:#4a5568; transition:all .18s; }
.filter-tab:hover:not(.active) { background:var(--border); }
.filter-tab.active { background:linear-gradient(135deg,var(--primary),var(--secondary)); border-color:transparent; color:white; font-weight:600; }
.btn-upload { display:inline-flex; align-items:center; gap:8px; padding:10px 20px; background:linear-gradient(135deg,var(--primary),var(--secondary)); color:white; border:none; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; transition:var(--transition); font-family:inherit; }
.btn-upload:hover { opacity:.9; transform:translateY(-1px); }

/* ── SECTION LABEL ── */
.section-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#718096; margin-bottom:16px; }

/* ── RESOURCE GRID ── */
.resource-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:18px; margin-bottom:32px; }

/* ── RESOURCE CARD ── */
.resource-card { background:white; border-radius:15px; padding:20px; box-shadow:var(--shadow-sm); border:2px solid var(--border); display:flex; flex-direction:column; gap:12px; transition:transform .2s, box-shadow .2s, border-color .2s; position:relative; }
.resource-card:hover { transform:translateY(-3px); box-shadow:var(--shadow-md); border-color:var(--secondary); }
.card-top { display:flex; align-items:flex-start; gap:14px; }
.ricon { width:46px; height:46px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; flex-shrink:0; }
.ic-pdf   { background:#fff5f5; color:#fc8181; }
.ic-video { background:#fffaf0; color:#ed8936; }
.ic-image { background:#faf5ff; color:#9f7aea; }
.ic-file  { background:#f7fafc; color:#a0aec0; }
.card-title { font-size:15px; font-weight:600; color:var(--text); line-height:1.35; overflow:hidden; text-overflow:ellipsis; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
.card-badges { display:flex; gap:6px; flex-wrap:wrap; margin-top:5px; }
.badge-cat  { padding:3px 10px; border-radius:6px; font-size:11px; font-weight:600; background:#c6f6d5; color:#22543d; }
.badge-vis  { padding:3px 10px; border-radius:6px; font-size:11px; font-weight:600; }
.badge-vis.public  { background:#bee3f8; color:#2a4365; }
.badge-vis.private { background:#feebc8; color:#744210; }
.card-desc { font-size:13px; color:var(--text-light); line-height:1.45; overflow:hidden; text-overflow:ellipsis; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
.card-meta { display:flex; flex-wrap:wrap; gap:10px; font-size:12px; color:var(--text-light); }
.card-meta span { display:flex; align-items:center; gap:4px; }
.card-actions { display:flex; gap:8px; margin-top:auto; }
.btn-view { flex:1; padding:9px 0; background:linear-gradient(135deg,var(--primary),var(--secondary)); color:white; border:none; border-radius:8px; font-family:inherit; font-size:13px; font-weight:600; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:6px; transition:opacity .2s,transform .15s; text-decoration:none; }
.btn-view:hover { opacity:.9; transform:translateY(-1px); }
.btn-icon { padding:9px 12px; background:white; border:2px solid var(--border); border-radius:8px; font-family:inherit; font-size:13px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all .2s; color:var(--text-light); }
.btn-icon:hover { border-color:var(--secondary); color:var(--secondary); }
.btn-icon.danger:hover { border-color:var(--error); color:var(--error); }

/* Visibility toggle badge on card */
.vis-toggle { position:absolute; top:14px; right:14px; }

/* ── PAGINATION ── */
.pagination { display:flex; justify-content:center; gap:8px; margin-top:8px; }
.page-btn { width:38px; height:38px; border-radius:8px; border:2px solid var(--border); background:white; font-family:inherit; font-size:14px; font-weight:600; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#4a5568; transition:all .18s; }
.page-btn:hover,.page-btn.active { background:linear-gradient(135deg,var(--primary),var(--secondary)); border-color:transparent; color:white; }
.page-btn:disabled { opacity:.4; cursor:default; pointer-events:none; }

/* ── EMPTY STATE ── */
.empty-state { text-align:center; padding:60px 24px; color:#a0aec0; grid-column:1/-1; }
.empty-state i { font-size:3rem; margin-bottom:16px; display:block; opacity:.4; }
.empty-state p { font-size:14px; line-height:1.6; }

/* ── SKELETON ── */
.skeleton { animation:pulse 1.4s ease-in-out infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
.skel { background:var(--border); border-radius:8px; }

/* ── MODAL ── */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:2000; align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal { background:white; border-radius:20px; padding:30px; width:90%; max-width:520px; box-shadow:0 20px 60px rgba(0,0,0,.3); max-height:90vh; overflow-y:auto; }
.modal-title { font-size:20px; font-weight:700; margin-bottom:20px; color:var(--text); }
.form-group { display:flex; flex-direction:column; gap:6px; margin-bottom:16px; }
.form-label { font-size:13px; font-weight:600; color:var(--text-light); text-transform:uppercase; letter-spacing:.05em; }
.form-control { padding:10px 14px; border:2px solid var(--border); border-radius:8px; font-size:14px; font-family:inherit; color:var(--text); transition:var(--transition); background:white; }
.form-control:focus { outline:none; border-color:var(--secondary); box-shadow:0 0 0 3px rgba(173,73,225,.1); }
.form-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.upload-zone { border:2px dashed var(--border); border-radius:var(--radius); padding:28px; text-align:center; cursor:pointer; transition:var(--transition); }
.upload-zone:hover,.upload-zone.dragover { border-color:var(--secondary); background:rgba(173,73,225,.03); }
.upload-zone i { font-size:2rem; color:var(--secondary); margin-bottom:10px; display:block; }
.upload-zone p { font-size:13px; color:var(--text-light); }
.file-name-display { font-size:13px; color:var(--text-light); margin-top:8px; display:none; }
.modal-actions { display:flex; gap:12px; justify-content:flex-end; margin-top:24px; }
.btn-primary { padding:10px 24px; background:linear-gradient(135deg,var(--primary),var(--secondary)); color:white; border:none; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; transition:var(--transition); font-family:inherit; }
.btn-primary:hover { opacity:.9; transform:translateY(-1px); }
.btn-secondary { padding:10px 24px; background:var(--bg-light); color:var(--text); border:2px solid var(--border); border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; font-family:inherit; }
.btn-secondary:hover { background:var(--border); }
.btn-danger { padding:10px 24px; background:#fee2e2; color:#dc2626; border:none; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; font-family:inherit; }
.btn-danger:hover { background:#fecaca; }

/* ── TOAST ── */
.toast { position:fixed; bottom:30px; left:50%; transform:translateX(-50%) translateY(80px); background:#1a202c; color:white; padding:14px 28px; border-radius:var(--radius); font-size:14px; font-weight:600; box-shadow:var(--shadow-lg); z-index:9999; transition:transform .3s ease,opacity .3s ease; opacity:0; pointer-events:none; }
.toast.show { transform:translateX(-50%) translateY(0); opacity:1; }
.toast.success { background:#276749; }
.toast.error   { background:#c53030; }

@media (max-width:900px) { .left-sidebar { display:none; } .main { margin-left:0; padding:16px 16px 100px; } .stats-row { grid-template-columns:1fr 1fr; } }
@media (max-width:600px) { .stats-row { grid-template-columns:1fr; } .form-row { grid-template-columns:1fr; } }
</style>
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

    <div class="nav-search">
        <input type="text" id="searchInput" placeholder="Search resources…" autocomplete="off">
        <i class="fa fa-search sicon"></i>
    </div>

    <div class="nav-right">

        <!-- Profile -->
        <button class="profile-button" id="profileBtn">
            <div class="profile-avatar">
                <?php if (!empty($userPicture)): ?>
                    <img src="<?= htmlspecialchars($userPicture) ?>" alt="Profile" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                <?php else: ?>
                    <?= $userInitials ?>
                <?php endif; ?>
            </div>
            <span class="profile-name"><?= $userName ?></span>
            <span class="profile-caret">▼</span>
        </button>
        <div class="profile-dropdown" id="profileDropdown">
            <div class="dropdown-header">
                <div style="display:flex;flex-direction:column;align-items:flex-start;gap:8px;width:100%;">
                    <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--secondary));display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:15px;overflow:hidden;">
                        <?php if (!empty($userPicture)): ?>
                            <img src="<?= htmlspecialchars($userPicture) ?>" alt="Profile" style="width:100%;height:100%;object-fit:cover;">
                        <?php else: ?>
                            <?= $userInitials ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="dropdown-name"><?= $userName ?></div>
                        <div class="dropdown-email"><?= $userEmail ?></div>
                    </div>
                </div>
            </div>
            <div class="dropdown-menu">
                <a href="teacher-profile.php" class="dropdown-item">👤 My Profile</a>
                <a href="help.html" target="_blank" rel="noopener" class="dropdown-item">❓ Help &amp; Support</a>
                <div class="dropdown-divider"></div>
                <button onclick="handleLogout()" class="dropdown-item danger">🚪 Logout</button>
            </div>
        </div>
    </div>
</nav>

<!-- ── PAGE WRAPPER ── -->
<div class="page-wrapper">

<!-- ── LEFT SIDEBAR ── -->
<aside class="left-sidebar">
    <span class="left-sidebar-label">Navigation</span>
    <a href="teacher-dashboard.php"><i class="fa fa-home"></i> Dashboard</a>
    <a href="teacher-assessments.php"><i class="fa fa-clipboard-list"></i> Assessments</a>
    <a href="manage-groups.php"><i class="fa fa-users"></i> Manage Groups</a>
    <a href="teacher-resources.php" class="active"><i class="fa fa-folder-open"></i> Resources</a>
    <span class="left-sidebar-label">Filter by Category</span>
    <button class="sidebar-filter-btn active" id="cat-all"       onclick="setCat('',this)"><i class="fa fa-layer-group"></i> All</button>
    <button class="sidebar-filter-btn"        id="cat-aptitude"  onclick="setCat('aptitude',this)"><i class="fa fa-calculator"></i> Aptitude</button>
    <button class="sidebar-filter-btn"        id="cat-technical" onclick="setCat('technical',this)"><i class="fa fa-code"></i> Technical</button>
    <button class="sidebar-filter-btn"        id="cat-verbal"    onclick="setCat('verbal',this)"><i class="fa fa-spell-check"></i> Verbal</button>
    <button class="sidebar-filter-btn"        id="cat-interview" onclick="setCat('interview',this)"><i class="fa fa-comments"></i> Interview</button>
    <div class="left-sidebar-bottom">
        <button onclick="handleLogout()"><i class="fa fa-sign-out-alt"></i> Logout</button>
    </div>
</aside>

<!-- ── MAIN ── -->
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

    <!-- Toolbar -->
    <div class="toolbar" style="justify-content:flex-end;">
        <button class="btn-upload" onclick="openUploadModal()"><i class="fa fa-plus"></i> Upload Resource</button>
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
                <div class="skel" style="width:42px;height:36px;border-radius:8px"></div>
                <div class="skel" style="width:42px;height:36px;border-radius:8px"></div>
            </div>
        </div>
        <?php endfor; ?>
    </div>

    <div class="pagination" id="pagination"></div>

</main>
</div><!-- /page-wrapper -->

<!-- ══ UPLOAD MODAL ══ -->
<div class="modal-overlay" id="uploadModal">
    <div class="modal">
        <div class="modal-title">📤 Upload Resource</div>
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
                    <option value="verbal">Verbal</option>
                    <option value="interview">Interview</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Difficulty</label>
                <select class="form-control" id="up-difficulty">
                    <option value="">Select difficulty</option>
                    <option value="beginner">Beginner</option>
                    <option value="intermediate">Intermediate</option>
                    <option value="advanced">Advanced</option>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Visibility</label>
                <select class="form-control" id="up-visibility">
                    <option value="public">🌐 Public (all students)</option>
                    <option value="private">🔒 Private (only me)</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Est. Time (minutes)</label>
                <input type="number" class="form-control" id="up-time" placeholder="e.g. 30" min="1">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">File or Link</label>
            <select class="form-control" id="up-type" onchange="toggleUploadType(this.value)" style="margin-bottom:10px;">
                <option value="file">📎 Upload File (PDF, Video, Image, Doc)</option>
                <option value="link">🔗 External Link</option>
            </select>
            <div id="up-file-zone">
                <div class="upload-zone" id="uploadZone" onclick="document.getElementById('up-file').click()" ondragover="event.preventDefault();this.classList.add('dragover')" ondragleave="this.classList.remove('dragover')" ondrop="handleDrop(event)">
                    <i class="fa fa-cloud-arrow-up"></i>
                    <p>Click or drag & drop your file here</p>
                    <p style="font-size:12px;margin-top:6px;color:#a0aec0">PDF, MP4, JPEG, PNG, DOCX — max 50 MB</p>
                </div>
                <input type="file" id="up-file" style="display:none" accept=".pdf,.mp4,.mov,.jpg,.jpeg,.png,.webp,.doc,.docx,.ppt,.pptx,.xls,.xlsx" onchange="previewFile(this)">
                <div class="file-name-display" id="fileNameDisplay"></div>
            </div>
            <div id="up-link-zone" style="display:none;">
                <input type="url" class="form-control" id="up-link" placeholder="https://…">
            </div>
        </div>
        <div class="modal-actions">
            <button class="btn-secondary" onclick="closeUploadModal()">Cancel</button>
            <button class="btn-primary" id="uploadSubmitBtn" onclick="submitUpload()"><i class="fa fa-upload"></i> Upload</button>
        </div>
    </div>
</div>

<!-- ══ EDIT MODAL ══ -->
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <div class="modal-title">✏️ Edit Resource</div>
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
                    <option value="verbal">Verbal</option>
                    <option value="interview">Interview</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Difficulty</label>
                <select class="form-control" id="edit-difficulty">
                    <option value="">Select difficulty</option>
                    <option value="beginner">Beginner</option>
                    <option value="intermediate">Intermediate</option>
                    <option value="advanced">Advanced</option>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Visibility</label>
                <select class="form-control" id="edit-visibility">
                    <option value="public">🌐 Public</option>
                    <option value="private">🔒 Private</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Est. Time (minutes)</label>
                <input type="number" class="form-control" id="edit-time" min="1">
            </div>
        </div>
        <div class="modal-actions">
            <button class="btn-secondary" onclick="closeEditModal()">Cancel</button>
            <button class="btn-primary" onclick="submitEdit()"><i class="fa fa-save"></i> Save Changes</button>
        </div>
    </div>
</div>

<!-- ══ DELETE MODAL ══ -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal" style="max-width:420px;">
        <div class="modal-title">🗑️ Delete Resource</div>
        <p style="font-size:14px;color:var(--text-light);margin-bottom:24px;" id="deleteModalBody">Are you sure you want to delete this resource? This cannot be undone.</p>
        <input type="hidden" id="delete-id">
        <div class="modal-actions">
            <button class="btn-secondary" onclick="closeDeleteModal()">Cancel</button>
            <button class="btn-danger" onclick="submitDelete()">Delete</button>
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

/* ── Load resources (teacher view) ── */
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

/* ── Render cards (teacher has edit/delete/visibility) ── */
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

        const visIcon = m.is_public ? 'fa-lock-open' : 'fa-lock';
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
            html += `<span style="align-self:center;color:#a0aec0">…</span>`;
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

/* ══ UPLOAD MODAL ══ */
function openUploadModal() {
    document.getElementById('up-title').value = '';
    document.getElementById('up-desc').value  = '';
    document.getElementById('up-category').value  = '';
    document.getElementById('up-difficulty').value = '';
    document.getElementById('up-visibility').value = 'public';
    document.getElementById('up-time').value  = '';
    document.getElementById('up-file').value  = '';
    document.getElementById('fileNameDisplay').style.display = 'none';
    document.getElementById('up-type').value  = 'file';
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
    btn.disabled = true; btn.textContent = 'Uploading…';

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
            body.append('difficulty', document.getElementById('up-difficulty').value);
            body.append('is_public', document.getElementById('up-visibility').value === 'public' ? 1 : 0);
            body.append('estimated_time_minutes', document.getElementById('up-time').value || '');
            body.append('file', file);
        } else {
            const link = document.getElementById('up-link').value.trim();
            if (!link) { toast('Please enter a URL.', 'error'); btn.disabled=false; btn.innerHTML='<i class="fa fa-upload"></i> Upload'; return; }
            body = JSON.stringify({ action:'upload_link', title, description: document.getElementById('up-desc').value.trim(), category: document.getElementById('up-category').value, difficulty: document.getElementById('up-difficulty').value, is_public: document.getElementById('up-visibility').value==='public'?1:0, estimated_time_minutes: document.getElementById('up-time').value||null, external_url: link });
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

/* ══ EDIT MODAL ══ */
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
        document.getElementById('edit-difficulty').value = m.difficulty || '';
        document.getElementById('edit-visibility').value = m.is_public ? 'public' : 'private';
        document.getElementById('edit-time').value       = m.estimated_time_minutes || '';
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
                difficulty:  document.getElementById('edit-difficulty').value,
                is_public:   document.getElementById('edit-visibility').value === 'public' ? 1 : 0,
                estimated_time_minutes: document.getElementById('edit-time').value || null,
            }),
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Update failed.');
        closeEditModal();
        toast('Resource updated!', 'success');
        load();
    } catch(e) { toast(e.message, 'error'); }
}

/* ══ VISIBILITY TOGGLE ══ */
async function toggleVisibility(id, newPublic) {
    try {
        const res  = await fetch('api/resources/update-resource.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type':'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify({ material_id: id, is_public: newPublic }),
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed to update visibility.');
        toast(newPublic ? '🌐 Made public' : '🔒 Made private', 'success');
        load();
    } catch(e) { toast(e.message, 'error'); }
}

/* ══ DELETE MODAL ══ */
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
        closeDeleteModal();
        toast('Resource deleted.', 'success');
        load();
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
document.addEventListener('click', e => {
    if (!document.getElementById('nav-right')?.contains(e.target)) {
        profileDrop.classList.remove('open');
    }
});

function handleLogout() {
    if (confirm('Are you sure you want to logout?')) window.location.href = 'logout.php';
}

/* ── Toast ── */
function toast(msg, type='') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className   = 'toast' + (type ? ' '+type : '');
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2800);
}
function showError(msg) {
    document.getElementById('resourceGrid').innerHTML = `<div class="empty-state"><i class="fa fa-circle-exclamation" style="color:#fc8181"></i><p>${msg}</p></div>`;
    document.getElementById('sectionLabel').textContent = 'Error';
}

load();
</script>
</body>
</html>
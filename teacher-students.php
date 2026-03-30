<?php
/* ========================================
 * TEACHER — STUDENTS LIST
 * File: teacher-students.php
 * ======================================== */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db-guard.php';

$currentUser  = validateSession($conn, 'teacher');
$teacherId    = (int) $currentUser['user_id'];
$userName     = htmlspecialchars($currentUser['full_name'] ?? 'Teacher');
$userEmail    = htmlspecialchars($currentUser['email'] ?? '');
$userInitials = strtoupper(substr($currentUser['full_name'] ?? 'T', 0, 2));

// ── Profile picture ──
$picStmt = $conn->prepare("SELECT profile_image FROM users WHERE user_id = ?");
$picStmt->bind_param("i", $teacherId);
$picStmt->execute();
$picRow      = $picStmt->get_result()->fetch_assoc();
$userPicture = $picRow['profile_image'] ?? '';

// ── Groups this teacher owns ──
$groups    = [];
$grpResult = safePreparedQuery($conn,
    "SELECT group_id, name FROM groups WHERE teacher_id = ? ORDER BY name",
    "i", [$teacherId]
);
if ($grpResult['success'] && $grpResult['result']) {
    while ($row = $grpResult['result']->fetch_assoc()) {
        $groups[] = $row;
    }
    $grpResult['result']->free();
}

// ── Selected group filter ──
$selectedGroup = isset($_GET['group']) && $_GET['group'] !== '' ? (int)$_GET['group'] : null;

// ── Students query ──
// Students who either:
//   (a) are in one of this teacher's groups, OR
//   (b) have attempted one of this teacher's assessments
// When a group filter is active, restrict to members of that group only.
$students = [];

if ($selectedGroup !== null) {
    // Verify teacher owns this group
    $ownCheck = safePreparedQuery($conn,
        "SELECT group_id FROM groups WHERE group_id = ? AND teacher_id = ?",
        "ii", [$selectedGroup, $teacherId]
    );
    $validGroup = $ownCheck['success'] && $ownCheck['result'] && $ownCheck['result']->num_rows > 0;
    if ($ownCheck['result']) $ownCheck['result']->free();

    if ($validGroup) {
        $r = safePreparedQuery($conn,
            "SELECT
                u.user_id,
                u.full_name,
                u.email,
                u.department,
                u.registration_number,
                u.profile_image,
                u.is_active,
                gm.joined_at,
                COUNT(DISTINCT aa.attempt_id)   AS total_attempts,
                ROUND(AVG(aa.percentage), 1)    AS avg_score,
                MAX(aa.percentage)              AS max_score,
                MAX(aa.submitted_at)            AS last_activity
            FROM group_members gm
            JOIN users u ON u.user_id = gm.student_id
            LEFT JOIN assessment_attempts aa
                ON aa.user_id = u.user_id
                AND aa.status IN ('submitted','timeout')
                AND aa.assessment_id IN (
                    SELECT assessment_id FROM assessments WHERE created_by = ?
                )
            WHERE gm.group_id = ?
            GROUP BY u.user_id, u.full_name, u.email, u.department, u.registration_number, u.profile_image, u.is_active, gm.joined_at
            ORDER BY u.full_name",
            "ii", [$teacherId, $selectedGroup]
        );
    } else {
        $r = ['success' => false];
    }
} else {
    // All students across this teacher's groups + assessment takers
    $r = safePreparedQuery($conn,
        "SELECT
            u.user_id,
            u.full_name,
            u.email,
            u.department,
            u.registration_number,
            u.profile_image,
            u.is_active,
            MIN(gm.joined_at)                   AS joined_at,
            COUNT(DISTINCT aa.attempt_id)       AS total_attempts,
            ROUND(AVG(aa.percentage), 1)        AS avg_score,
            MAX(aa.percentage)                  AS max_score,
            MAX(aa.submitted_at)                AS last_activity
        FROM users u
        LEFT JOIN group_members gm ON gm.student_id = u.user_id
            AND gm.group_id IN (SELECT group_id FROM groups WHERE teacher_id = ?)
        LEFT JOIN assessment_attempts aa
            ON aa.user_id = u.user_id
            AND aa.status IN ('submitted','timeout')
            AND aa.assessment_id IN (
                SELECT assessment_id FROM assessments WHERE created_by = ?
            )
        WHERE u.role = 'student'
          AND (
              gm.group_id IS NOT NULL
              OR aa.attempt_id IS NOT NULL
          )
        GROUP BY u.user_id, u.full_name, u.email, u.department, u.registration_number, u.profile_image, u.is_active
        ORDER BY u.full_name",
        "ii", [$teacherId, $teacherId]
    );
}

if ($r['success'] && $r['result']) {
    while ($row = $r['result']->fetch_assoc()) {
        $students[] = $row;
    }
    $r['result']->free();
}

// ── Helper ──
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
<title>Students — PREPAURA</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
/* ── DESIGN TOKENS (match dashboard) ── */
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
  --font-head:   'Times New Roman', Arial, serif;
  --font-body:   'Calibri', 'Segoe UI', Arial, sans-serif;
  --nav-h:       64px;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
html{-webkit-font-smoothing:antialiased;scroll-behavior:smooth}
body{font-family:var(--font-body);font-size:15px;background:var(--surface);color:var(--text-1);min-height:100vh;padding-top:var(--nav-h);overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.03'/%3E%3C/svg%3E");background-size:200px 200px}

/* NAVBAR */
.navbar{height:var(--nav-h);background:rgba(13,10,20,0.96);backdrop-filter:blur(20px) saturate(1.6);border-bottom:1px solid rgba(255,255,255,0.06);padding:0 28px;display:flex;align-items:center;justify-content:space-between;gap:20px;position:fixed;top:0;left:0;right:0;z-index:1000}
.navbar-brand{display:flex;align-items:center;gap:12px;text-decoration:none;flex-shrink:0}
.brand-logo-img{width:36px;height:36px;border-radius:9px;object-fit:contain;background:white;padding:3px}
.brand-text-group{display:flex;flex-direction:column;line-height:1.15}
.brand-name{font-family:var(--font-head);font-size:16px;font-weight:800;letter-spacing:0.06em;color:white}
.brand-tagline{font-size:10px;font-weight:400;color:rgba(255,255,255,0.45);letter-spacing:0.03em}
.nav-right{display:flex;align-items:center;gap:12px}
.profile-wrap{position:relative}
.profile-button{display:flex;align-items:center;gap:9px;padding:6px 12px 6px 6px;background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.1);border-radius:40px;cursor:pointer;transition:var(--t);color:white}
.profile-button:hover{background:rgba(255,255,255,0.13);border-color:rgba(255,255,255,0.18)}
.profile-avatar{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--violet),var(--orchid));display:flex;align-items:center;justify-content:center;font-family:var(--font-head);font-weight:700;font-size:12px;color:white;overflow:hidden;flex-shrink:0}
.profile-avatar img{width:100%;height:100%;object-fit:cover;border-radius:50%}
.profile-name{font-size:13px;font-weight:500}
.profile-caret{font-size:9px;color:rgba(255,255,255,0.5);margin-left:2px}
.profile-dropdown{position:absolute;top:calc(100% + 10px);right:0;background:var(--surface-3);border-radius:var(--r-md);box-shadow:var(--shadow-lg),0 0 0 1px var(--border);min-width:230px;opacity:0;visibility:hidden;transform:translateY(-6px) scale(0.98);transition:var(--t);z-index:1001;overflow:hidden}
.profile-dropdown.open{opacity:1;visibility:visible;transform:translateY(0) scale(1)}
.dropdown-header{padding:18px 20px;background:linear-gradient(135deg,var(--ink) 0%,var(--ink-3) 100%);border-bottom:1px solid rgba(255,255,255,0.06)}
.dd-avatar{width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,var(--violet),var(--orchid));display:flex;align-items:center;justify-content:center;font-family:var(--font-head);font-weight:700;font-size:16px;color:white;overflow:hidden;margin-bottom:10px}
.dd-avatar img{width:100%;height:100%;object-fit:cover;border-radius:50%}
.dropdown-name{font-weight:600;font-size:14px;color:white}
.dropdown-email{font-size:12px;color:rgba(255,255,255,0.5);margin-top:2px}
.dropdown-role{display:inline-block;margin-top:8px;padding:2px 10px;background:var(--violet-dim);border:1px solid rgba(124,58,237,0.3);color:var(--orchid);border-radius:20px;font-size:11px;font-weight:600;letter-spacing:0.04em;text-transform:uppercase}
.dropdown-menu{padding:6px 0}
.dropdown-item{display:flex;align-items:center;gap:11px;padding:10px 18px;color:var(--text-2);text-decoration:none;font-size:13.5px;transition:var(--t);cursor:pointer;border:none;background:none;width:100%;text-align:left;font-family:var(--font-body)}
.dropdown-item i{width:16px;text-align:center;color:var(--text-3)}
.dropdown-item:hover{background:var(--surface-2);color:var(--text-1)}
.dropdown-item.danger{color:var(--rose)}
.dropdown-item.danger i{color:var(--rose)}
.dropdown-item.danger:hover{background:rgba(244,63,94,0.06)}
.dropdown-divider{height:1px;background:var(--border);margin:4px 0}

/* LAYOUT */
.page-wrapper{display:flex;min-height:calc(100vh - var(--nav-h));position:relative;z-index:1}
.left-sidebar{width:230px;flex-shrink:0;padding:28px 12px;display:flex;flex-direction:column;gap:2px;background:rgba(255,255,255,0.6);backdrop-filter:blur(12px);border-right:1px solid var(--border);min-height:calc(100vh - var(--nav-h));position:sticky;top:var(--nav-h);align-self:flex-start}
.sidebar-section-label{font-family:var(--font-head);font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-3);padding:14px 14px 6px}
.sidebar-link{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:var(--r-sm);text-decoration:none;font-size:13.5px;font-weight:500;color:var(--text-2);transition:var(--t)}
.sidebar-link i{width:18px;text-align:center;font-size:14px;color:var(--text-3);transition:var(--t)}
.sidebar-link:hover{background:var(--violet-dim);color:var(--violet)}
.sidebar-link:hover i{color:var(--violet)}
.sidebar-link.active{background:linear-gradient(135deg,rgba(124,58,237,0.12),rgba(192,132,252,0.08));color:var(--violet);font-weight:600;box-shadow:inset 3px 0 0 var(--violet)}
.sidebar-link.active i{color:var(--violet)}
.sidebar-bottom{margin-top:auto;padding-top:16px;border-top:1px solid var(--border)}
.sidebar-logout{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:var(--r-sm);font-size:13.5px;font-weight:500;color:var(--rose);background:none;border:none;cursor:pointer;width:100%;transition:var(--t);font-family:var(--font-body)}
.sidebar-logout i{width:18px;text-align:center;font-size:14px}
.sidebar-logout:hover{background:rgba(244,63,94,0.07)}
.page-content{flex:1;min-width:0;padding:36px 36px 48px 28px}

/* PAGE HEADER */
.page-header{margin-bottom:28px}
.page-header h1{font-family:var(--font-head);font-size:28px;font-weight:800;color:var(--text-1);margin-bottom:4px}
.page-header p{color:var(--text-3);font-size:14px}

/* TOOLBAR */
.toolbar{display:flex;align-items:center;gap:12px;margin-bottom:24px;flex-wrap:wrap}
.toolbar-search{flex:1;min-width:200px;max-width:340px;position:relative}
.toolbar-search input{width:100%;padding:10px 38px 10px 14px;background:var(--surface-3);border:1.5px solid var(--border-2);border-radius:var(--r-md);font-family:var(--font-body);font-size:13.5px;color:var(--text-1);outline:none;transition:var(--t)}
.toolbar-search input:focus{border-color:var(--violet);box-shadow:0 0 0 3px var(--violet-dim)}
.toolbar-search i{position:absolute;right:13px;top:50%;transform:translateY(-50%);color:var(--text-3);font-size:13px;pointer-events:none}
.group-filter{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.group-btn{padding:8px 14px;border-radius:var(--r-md);border:1.5px solid var(--border-2);background:var(--surface-3);font-family:var(--font-body);font-size:13px;font-weight:500;color:var(--text-2);cursor:pointer;text-decoration:none;transition:var(--t);white-space:nowrap}
.group-btn:hover{border-color:var(--violet);color:var(--violet);background:var(--violet-dim)}
.group-btn.active{background:var(--violet);border-color:var(--violet);color:white;font-weight:600}
.student-count-badge{margin-left:auto;font-size:13px;color:var(--text-3);white-space:nowrap}

/* STUDENT TABLE */
.students-card{background:var(--surface-3);border-radius:var(--r-lg);box-shadow:var(--shadow-sm);border:1px solid var(--border);overflow:hidden}
.students-table{width:100%;border-collapse:collapse}
.students-table thead tr{background:linear-gradient(135deg,var(--ink) 0%,var(--ink-3) 100%)}
.students-table thead th{padding:14px 18px;text-align:left;font-family:var(--font-head);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:rgba(255,255,255,0.7);white-space:nowrap}
.students-table thead th:first-child{border-radius:0}
.students-table tbody tr{border-bottom:1px solid var(--border);transition:background var(--t);cursor:pointer}
.students-table tbody tr:last-child{border-bottom:none}
.students-table tbody tr:hover{background:var(--violet-dim)}
.students-table td{padding:14px 18px;font-size:13.5px;color:var(--text-2);vertical-align:middle}
.student-info{display:flex;align-items:center;gap:12px}
.student-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,var(--violet),var(--orchid));display:flex;align-items:center;justify-content:center;font-family:var(--font-head);font-weight:700;font-size:13px;color:white;overflow:hidden;flex-shrink:0}
.student-avatar img{width:100%;height:100%;object-fit:cover;border-radius:50%}
.student-name{font-weight:600;color:var(--text-1);font-size:14px}
.student-email{font-size:12px;color:var(--text-3);margin-top:1px}
.student-reg{font-size:12px;color:var(--text-3)}
.score-bar-wrap{display:flex;align-items:center;gap:8px;min-width:100px}
.score-bar{flex:1;height:6px;border-radius:3px;background:var(--surface-2);overflow:hidden}
.score-bar-fill{height:100%;border-radius:3px;transition:width 0.6s var(--ease)}
.score-val{font-size:13px;font-weight:600;color:var(--text-1);white-space:nowrap}
.badge-active{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600}
.badge-active.yes{background:rgba(16,185,129,0.1);color:var(--emerald)}
.badge-active.no{background:rgba(244,63,94,0.08);color:var(--rose)}
.view-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;background:var(--violet-dim);border:1px solid rgba(124,58,237,0.25);border-radius:var(--r-sm);color:var(--violet);font-family:var(--font-body);font-size:12.5px;font-weight:600;text-decoration:none;transition:var(--t);white-space:nowrap}
.view-btn:hover{background:var(--violet);color:white}
.empty-state{text-align:center;padding:64px 24px;color:var(--text-3)}
.empty-state i{font-size:40px;margin-bottom:14px;display:block;color:var(--border-2)}
.empty-state h3{font-size:17px;font-weight:600;color:var(--text-2);margin-bottom:8px}
.empty-state p{font-size:14px}
.hidden{display:none!important}

@media(max-width:900px){
  .left-sidebar{display:none}
  .students-table thead th:nth-child(3),
  .students-table td:nth-child(3),
  .students-table thead th:nth-child(5),
  .students-table td:nth-child(5){display:none}
}
@media(max-width:640px){
  .page-content{padding:20px 16px 40px}
  .students-table thead th:nth-child(4),
  .students-table td:nth-child(4){display:none}
}
</style>
</head>
<body>

<!-- NAVBAR -->
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
            <div class="dropdown-divider"></div>
            <a href="#" onclick="handleLogout()" class="dropdown-item danger"><i class="fa fa-right-from-bracket"></i> Logout</a>
          </div>
        </div>
      </button>
    </div>
  </div>
</nav>

<!-- MAIN -->
<div class="page-wrapper">
  <aside class="left-sidebar">
    <span class="sidebar-section-label">Navigation</span>
    <a href="teacher-dashboard.php" class="sidebar-link"><i class="fa fa-house"></i> Dashboard</a>
    <a href="teacher-assessments.php" class="sidebar-link"><i class="fa fa-clipboard-list"></i> Assessments</a>
    <a href="teacher-students.php" class="sidebar-link active"><i class="fa fa-user-graduate"></i> Students</a>
    <a href="manage-groups.php" class="sidebar-link"><i class="fa fa-users"></i> Manage Groups</a>
    <a href="teacher-resources.php" class="sidebar-link"><i class="fa fa-folder-open"></i> Resources</a>
    <div class="sidebar-bottom">
      <button onclick="handleLogout()" class="sidebar-logout"><i class="fa fa-right-from-bracket"></i> Logout</button>
    </div>
  </aside>

  <div class="page-content">
    <div class="page-header">
      <h1>Students</h1>
      <p>View and filter students by group, then open detailed performance reports.</p>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
      <div class="toolbar-search">
        <input type="text" id="studentSearch" placeholder="Search by name, email, or reg no…" autocomplete="off">
        <i class="fa fa-search"></i>
      </div>
      <div class="group-filter">
        <a href="teacher-students.php" class="group-btn <?= $selectedGroup === null ? 'active' : '' ?>">All Groups</a>
        <?php foreach ($groups as $g): ?>
          <a href="teacher-students.php?group=<?= $g['group_id'] ?>"
             class="group-btn <?= $selectedGroup === (int)$g['group_id'] ? 'active' : '' ?>">
            <?= htmlspecialchars($g['name']) ?>
          </a>
        <?php endforeach; ?>
      </div>
      <span class="student-count-badge" id="studentCountBadge">
        <?= count($students) ?> student<?= count($students) !== 1 ? 's' : '' ?>
      </span>
    </div>

    <!-- Table -->
    <div class="students-card">
      <?php if (empty($students)): ?>
        <div class="empty-state">
          <i class="fa fa-user-graduate"></i>
          <h3>No students found</h3>
          <p><?= $selectedGroup ? 'No students in this group yet.' : 'Students will appear here once they join your groups or attempt your assessments.' ?></p>
        </div>
      <?php else: ?>
      <table class="students-table" id="studentsTable">
        <thead>
          <tr>
            <th>Student</th>
            <th>Reg. No.</th>
            <th>Department</th>
            <th>Attempts</th>
            <th>Avg Score</th>
            <th>Last Active</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($students as $s):
            $initials = strtoupper(substr($s['full_name'] ?? '?', 0, 2));
            $avg      = $s['avg_score'] !== null ? (float)$s['avg_score'] : null;
            $barColor = $avg === null ? '#ccc' : ($avg >= 75 ? 'var(--emerald)' : ($avg >= 50 ? 'var(--gold)' : 'var(--rose)'));
          ?>
          <tr class="student-row"
              data-name="<?= strtolower(htmlspecialchars($s['full_name'])) ?>"
              data-email="<?= strtolower(htmlspecialchars($s['email'])) ?>"
              data-reg="<?= strtolower(htmlspecialchars($s['registration_number'] ?? '')) ?>"
              onclick="window.location='teacher-student-report.php?student_id=<?= $s['user_id'] ?>'">
            <td>
              <div class="student-info">
                <div class="student-avatar">
                  <?php if (!empty($s['profile_image'])): ?>
                    <img src="<?= htmlspecialchars($s['profile_image']) ?>" alt="">
                  <?php else: ?>
                    <?= $initials ?>
                  <?php endif; ?>
                </div>
                <div>
                  <div class="student-name"><?= htmlspecialchars($s['full_name']) ?></div>
                  <div class="student-email"><?= htmlspecialchars($s['email']) ?></div>
                </div>
              </div>
            </td>
            <td class="student-reg"><?= htmlspecialchars($s['registration_number'] ?? '—') ?></td>
            <td><?= htmlspecialchars($s['department'] ?? '—') ?></td>
            <td style="font-weight:600;color:var(--text-1)"><?= (int)$s['total_attempts'] ?></td>
            <td>
              <?php if ($avg !== null): ?>
              <div class="score-bar-wrap">
                <div class="score-bar">
                  <div class="score-bar-fill" style="width:<?= min($avg,100) ?>%;background:<?= $barColor ?>"></div>
                </div>
                <span class="score-val"><?= number_format($avg, 1) ?>%</span>
              </div>
              <?php else: ?>
              <span style="color:var(--text-3);font-size:13px">No attempts</span>
              <?php endif; ?>
            </td>
            <td style="font-size:13px;color:var(--text-3)"><?= fmtDate($s['last_activity']) ?></td>
            <td onclick="event.stopPropagation()">
              <a href="teacher-student-report.php?student_id=<?= $s['user_id'] ?>" class="view-btn">
                <i class="fa fa-chart-line"></i> Report
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
// ── Profile dropdown ──
const profileBtn      = document.getElementById('profileBtn');
const profileDropdown = document.getElementById('profileDropdown');
profileBtn?.addEventListener('click', e => {
  e.stopPropagation();
  profileDropdown.classList.toggle('open');
});
document.addEventListener('click', () => profileDropdown?.classList.remove('open'));

// ── Logout ──
function handleLogout() {
  if (confirm('Are you sure you want to logout?')) window.location.href = 'logout.php';
}

// ── Live search ──
document.getElementById('studentSearch')?.addEventListener('input', function () {
  const q     = this.value.toLowerCase().trim();
  const rows  = document.querySelectorAll('.student-row');
  let visible = 0;
  rows.forEach(row => {
    const match = !q
      || row.dataset.name.includes(q)
      || row.dataset.email.includes(q)
      || row.dataset.reg.includes(q);
    row.classList.toggle('hidden', !match);
    if (match) visible++;
  });
  const badge = document.getElementById('studentCountBadge');
  if (badge) badge.textContent = visible + ' student' + (visible !== 1 ? 's' : '');
});
</script>
</body>
</html>

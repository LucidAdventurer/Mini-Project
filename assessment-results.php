<?php
// ============================================================
// assessment-results.php  — Teacher view
// Shows all student attempts for a given assessment.
// URL: assessment-results.php?id=ASSESSMENT_ID
// ============================================================

require_once "config.php";
require_once "db-guard.php";

$currentUser = validateSession($conn, 'teacher');
$teacherId   = (int) $currentUser['user_id'];
$userName    = $currentUser['full_name'] ?? 'Teacher';
$userEmail   = $currentUser['email']     ?? '';
$userInitials = strtoupper(substr($userName, 0, 2));

// Fetch teacher profile picture
$picStmt = $conn->prepare("SELECT profile_image FROM users WHERE user_id = ?");
$picStmt->bind_param("i", $teacherId);
$picStmt->execute();
$picRow = $picStmt->get_result()->fetch_assoc();
$picStmt->close();
$userPicture = $picRow['profile_image'] ?? '';

// Ensure CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Get assessment ID ──
$assessmentId = (int)($_GET['id'] ?? 0);
if ($assessmentId <= 0) {
    header('Location: teacher-dashboard.php?error=invalid_assessment');
    exit;
}

// ── Verify teacher owns this assessment ──
$asmResult = safePreparedQuery($conn,
    "SELECT assessment_id, title, category, difficulty, duration_minutes,
            total_marks, passing_marks, max_attempts, status,
            (SELECT COUNT(*) FROM questions q WHERE q.assessment_id = a.assessment_id) AS question_count
     FROM assessments a
     WHERE a.assessment_id = ? AND a.created_by = ?",
    "ii", [$assessmentId, $teacherId]
);

if (!$asmResult['success'] || !$asmResult['result'] || $asmResult['result']->num_rows === 0) {
    header('Location: teacher-dashboard.php?error=not_found');
    exit;
}
$asm = $asmResult['result']->fetch_assoc();
$asmResult['result']->free();

// ── Fetch all attempts with student info ──
// Use direct query (no prepared stmt) to avoid IN() issue with safePreparedQuery
$_aid = (int)$assessmentId;
$attemptsRaw = $conn->query(
    "SELECT
        aa.attempt_id,
        aa.attempt_number,
        aa.score,
        aa.percentage,
        aa.start_time,
        aa.submitted_at,
        TIMESTAMPDIFF(MINUTE, aa.start_time, aa.submitted_at) AS time_taken_min,
        u.user_id,
        u.full_name,
        u.email,
        u.registration_number,
        u.department
     FROM assessment_attempts aa
     LEFT JOIN users u ON u.user_id = aa.user_id
     WHERE aa.assessment_id = $_aid
       AND aa.status IN ('submitted','completed','timeout')
     ORDER BY aa.submitted_at DESC"
);
$attemptsResult = ['success' => ($attemptsRaw !== false), 'result' => $attemptsRaw ?: null, 'error' => $conn->error];

$attempts = [];

if ($attemptsResult['success'] && $attemptsResult['result']) {
    while ($row = $attemptsResult['result']->fetch_assoc()) {
        $attempts[] = $row;
    }
    $attemptsResult['result']->free();
}

// ── Summary stats ──
$totalAttempts  = count($attempts);
$passCount      = 0;
$totalPct       = 0;
$highScore      = 0;
$studentIds     = [];
foreach ($attempts as $a) {
    $pct = (float)($a['percentage'] ?? 0);
    $totalPct += $pct;
    if ($pct >= ($asm['passing_marks'] / max($asm['total_marks'], 1) * 100)) $passCount++;
    if ($pct > $highScore) $highScore = $pct;
    $studentIds[$a['user_id']] = true;
}
$avgScore      = $totalAttempts > 0 ? round($totalPct / $totalAttempts, 1) : 0;
$uniqueStudents = count($studentIds);
$passRate       = $totalAttempts > 0 ? round($passCount / $totalAttempts * 100) : 0;

function diffBadge(string $d): string {
    $map = ['easy' => ['#dcfce7','#166534'], 'medium' => ['#fef3c7','#92400e'], 'hard' => ['#fee2e2','#991b1b']];
    [$bg, $col] = $map[$d] ?? ['#f3f4f6','#374151'];
    return "<span style=\"background:$bg;color:$col;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;\">$d</span>";
}
function scoreColor(float $pct, float $passPct): string {
    if ($pct >= $passPct) return '#10b981';
    if ($pct >= $passPct * 0.7) return '#f59e0b';
    return '#f43f5e';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($asm['title']) ?> — Results | PREPAURA</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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
  border-bottom: 1px solid rgba(255,255,255,0.06); text-align: left;
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

/* ── PAGE SPECIFIC ── */
.back-link {
  display: inline-flex; align-items: center; gap: 8px;
  color: var(--text-3); font-size: 13px; text-decoration: none;
  margin-bottom: 24px; transition: var(--t);
}
.back-link:hover { color: var(--violet); }

.page-header { margin-bottom: 28px; }
.page-title {
  font-family: var(--font-head);
  font-size: 26px; font-weight: 800; color: var(--text-1);
  margin-bottom: 6px;
}
.page-meta { display: flex; align-items: center; flex-wrap: wrap; gap: 12px; }
.meta-chip {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 4px 12px;
  background: var(--surface-3);
  border: 1px solid var(--border);
  border-radius: 20px;
  font-size: 12px; color: var(--text-2); font-weight: 500;
}
.meta-chip i { color: var(--text-3); font-size: 11px; }

/* ── SUMMARY CARDS ── */
.summary-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
  margin-bottom: 28px;
}
.summary-card {
  background: var(--surface-3);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  padding: 20px 22px;
  box-shadow: var(--shadow-xs);
}
.summary-label {
  font-size: 11px; font-weight: 700; text-transform: uppercase;
  letter-spacing: 0.08em; color: var(--text-3); margin-bottom: 10px;
}
.summary-value {
  font-family: var(--font-head);
  font-size: 32px; font-weight: 800; color: var(--text-1);
  line-height: 1;
}
.summary-sub { font-size: 12px; color: var(--text-3); margin-top: 6px; }

/* ── TABLE ── */
.table-card {
  background: var(--surface-3);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  box-shadow: var(--shadow-xs);
  overflow: hidden;
}
.table-card-header {
  padding: 18px 24px;
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
}
.table-card-title {
  font-family: var(--font-head);
  font-size: 15px; font-weight: 700; color: var(--text-1);
}
.search-bar {
  display: flex; align-items: center; gap: 8px;
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--r-sm); padding: 7px 12px;
}
.search-bar input {
  border: none; background: none; outline: none;
  font-family: var(--font-body); font-size: 13px; color: var(--text-1);
  width: 200px;
}
.search-bar input::placeholder { color: var(--text-3); }
.search-bar i { color: var(--text-3); font-size: 12px; }

table { width: 100%; border-collapse: collapse; }
thead tr { background: var(--surface); }
th {
  padding: 11px 16px;
  font-size: 11px; font-weight: 700; text-transform: uppercase;
  letter-spacing: 0.07em; color: var(--text-3);
  text-align: left; border-bottom: 1px solid var(--border);
  white-space: nowrap;
}
td {
  padding: 13px 16px;
  font-size: 13.5px; color: var(--text-2);
  border-bottom: 1px solid var(--border);
  vertical-align: middle;
}
tbody tr:last-child td { border-bottom: none; }
tbody tr { transition: var(--t); }
tbody tr:hover { background: var(--surface); }

.student-name { font-weight: 600; color: var(--text-1); }
.student-meta { font-size: 11.5px; color: var(--text-3); margin-top: 2px; }

.score-pill {
  display: inline-flex; align-items: center;
  padding: 4px 12px; border-radius: 20px;
  font-size: 12px; font-weight: 700;
}
.pass  { background: rgba(16,185,129,0.12); color: #065f46; }
.fail  { background: rgba(244,63,94,0.1);   color: #9f1239; }
.mid   { background: rgba(245,158,11,0.12); color: #92400e; }

.attempt-badge {
  display: inline-block; padding: 2px 8px;
  background: var(--violet-dim); color: var(--violet);
  border-radius: 20px; font-size: 11px; font-weight: 600;
}

.empty-state {
  text-align: center; padding: 60px 24px;
  color: var(--text-3);
}
.empty-state i { font-size: 40px; margin-bottom: 16px; display: block; color: var(--border-2); }
.empty-state p { font-size: 14px; }

@media (max-width: 900px) {
  .summary-grid { grid-template-columns: repeat(2, 1fr); }
  .left-sidebar { display: none; }
  .page-content { padding: 24px 16px; }
  th:nth-child(4), td:nth-child(4),
  th:nth-child(5), td:nth-child(5) { display: none; }
}
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
          <?php if (!empty($userPicture) && file_exists($userPicture)): ?>
            <img src="<?= htmlspecialchars($userPicture) ?>" alt="Profile">
          <?php else: ?>
            <?= $userInitials ?>
          <?php endif; ?>
        </div>
        <span class="profile-name"><?= htmlspecialchars($userName) ?></span>
        <i class="fa fa-chevron-down profile-caret"></i>
        <div class="profile-dropdown" id="profileDropdown">
          <div class="dropdown-header">
            <div class="dd-avatar">
              <?php if (!empty($userPicture) && file_exists($userPicture)): ?>
                <img src="<?= htmlspecialchars($userPicture) ?>" alt="Profile">
              <?php else: ?>
                <?= $userInitials ?>
              <?php endif; ?>
            </div>
            <div class="dropdown-name"><?= htmlspecialchars($userName) ?></div>
            <div class="dropdown-email"><?= htmlspecialchars($userEmail) ?></div>
            <span class="dropdown-role">Teacher</span>
          </div>
          <div class="dropdown-menu">
            <a href="teacher-profile.php" class="dropdown-item"><i class="fa fa-user"></i> My Profile</a>
            <a href="help.html" target="_blank" rel="noopener" class="dropdown-item"><i class="fa fa-circle-question"></i> Help &amp; Support</a>
            <div class="dropdown-divider"></div>
            <a href="#" onclick="handleLogout()" class="dropdown-item danger"><i class="fa fa-right-from-bracket"></i> Logout</a>
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

    <a href="teacher-dashboard.php" class="back-link">
      <i class="fa fa-arrow-left"></i> Back to Dashboard
    </a>

    <!-- Page Header -->
    <div class="page-header">
      <div class="page-title"><?= htmlspecialchars($asm['title']) ?></div>
      <div class="page-meta">
        <?= diffBadge($asm['difficulty'] ?? 'medium') ?>
        <span class="meta-chip"><i class="fa fa-tag"></i><?= htmlspecialchars(ucfirst($asm['category'] ?? '')) ?></span>
        <span class="meta-chip"><i class="fa fa-clock"></i><?= (int)$asm['duration_minutes'] ?> min</span>
        <span class="meta-chip"><i class="fa fa-circle-question"></i><?= (int)$asm['question_count'] ?> questions</span>
        <span class="meta-chip"><i class="fa fa-star"></i><?= (int)$asm['total_marks'] ?> marks</span>
        <span class="meta-chip"><i class="fa fa-check-circle"></i>Pass: <?= (int)$asm['passing_marks'] ?></span>
      </div>
    </div>

    <!-- Summary Cards -->
    <div class="summary-grid">
      <div class="summary-card">
        <div class="summary-label">Total Attempts</div>
        <div class="summary-value"><?= $totalAttempts ?></div>
        <div class="summary-sub"><?= $uniqueStudents ?> unique student<?= $uniqueStudents !== 1 ? 's' : '' ?></div>
      </div>
      <div class="summary-card">
        <div class="summary-label">Average Score</div>
        <div class="summary-value" style="color:var(--violet)"><?= $avgScore ?>%</div>
        <div class="summary-sub">across all attempts</div>
      </div>
      <div class="summary-card">
        <div class="summary-label">Pass Rate</div>
        <div class="summary-value" style="color:<?= $passRate >= 60 ? 'var(--emerald)' : 'var(--rose)' ?>"><?= $passRate ?>%</div>
        <div class="summary-sub"><?= $passCount ?> of <?= $totalAttempts ?> passed</div>
      </div>
      <div class="summary-card">
        <div class="summary-label">High Score</div>
        <div class="summary-value" style="color:var(--gold)"><?= round($highScore, 1) ?>%</div>
        <div class="summary-sub">best attempt</div>
      </div>
    </div>

    <!-- Attempts Table -->
    <div class="table-card">
      <div class="table-card-header">
        <div class="table-card-title">
          <i class="fa fa-list-ul" style="color:var(--violet);margin-right:8px;"></i>
          All Attempts
        </div>
        <div class="search-bar">
          <i class="fa fa-search"></i>
          <input type="text" id="searchInput" placeholder="Search by name or reg. no…" oninput="filterTable(this.value)">
        </div>
      </div>

      <?php if (empty($attempts)): ?>
        <div class="empty-state">
          <i class="fa fa-inbox"></i>
          <p>No attempts yet. Students haven't taken this assessment.</p>
        </div>
      <?php else: ?>
      <div style="overflow-x:auto;">
        <table id="attemptsTable">
          <thead>
            <tr>
              <th>#</th>
              <th>Student</th>
              <th>Score</th>
              <th>Time Taken</th>
              <th>Submitted</th>
              <th>Attempt</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $passPct = $asm['total_marks'] > 0
                ? ($asm['passing_marks'] / $asm['total_marks'] * 100)
                : 50;
            $i = 1;
            foreach ($attempts as $att):
                $pct     = (float)($att['percentage'] ?? 0);
                $col     = scoreColor($pct, $passPct);
                $pillCls = $pct >= $passPct ? 'pass' : ($pct >= $passPct * 0.7 ? 'mid' : 'fail');
                $pillLbl = $pct >= $passPct ? 'PASS' : 'FAIL';
                $timeTaken = $att['time_taken_min'] !== null ? (int)$att['time_taken_min'] . ' min' : '—';
                $submitted = $att['submitted_at'] ? date('d M Y, g:i a', strtotime($att['submitted_at'])) : '—';
            ?>
            <tr>
              <td style="color:var(--text-3);font-size:12px;"><?= $i++ ?></td>
              <td>
                <div class="student-name"><?= htmlspecialchars($att['full_name']) ?></div>
                <div class="student-meta">
                  <?= htmlspecialchars($att['email']) ?>
                  <?php if ($att['registration_number']): ?>
                    · <?= htmlspecialchars($att['registration_number']) ?>
                  <?php endif; ?>
                  <?php if ($att['department']): ?>
                    · <?= htmlspecialchars($att['department']) ?>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <div style="font-weight:700;font-size:15px;color:<?= $col ?>;"><?= round($pct, 1) ?>%</div>
                <div style="font-size:11.5px;color:var(--text-3);"><?= round((float)($att['score'] ?? 0), 1) ?> / <?= (int)$asm['total_marks'] ?></div>
              </td>

              <td><?= $timeTaken ?></td>
              <td style="font-size:12.5px;"><?= $submitted ?></td>
              <td>
                <span class="attempt-badge">Attempt <?= (int)$att['attempt_number'] ?></span>
                <div style="margin-top:4px;">
                  <span class="score-pill <?= $pillCls ?>"><?= $pillLbl ?></span>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- /page-content -->
</div><!-- /page-wrapper -->

<script>
document.getElementById('profileBtn').addEventListener('click', function(e) {
    e.stopPropagation();
    document.getElementById('profileDropdown').classList.toggle('open');
});
document.addEventListener('click', function() {
    document.getElementById('profileDropdown').classList.remove('open');
});

function handleLogout() {
    if (confirm('Are you sure you want to logout?')) window.location.href = 'logout.php';
}

function filterTable(val) {
    const q = val.toLowerCase();
    document.querySelectorAll('#attemptsTable tbody tr').forEach(tr => {
        const text = tr.textContent.toLowerCase();
        tr.style.display = text.includes(q) ? '' : 'none';
    });
}
</script>
</body>
</html>

<?php
/* ========================================
 * ADMIN PROFILE PAGE
 * File: admin-profile.php
 *
 * Displays and allows editing of the authenticated admin's
 * profile: personal info, password change, and login activity.
 * ======================================== */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db-guard.php';

// Validate session — admin only
$admin = validateSession($conn, 'admin');
$adminId = (int) $admin['user_id'];

$errors   = [];
$success  = [];

// ── Handle POST actions ──────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();

    $action = $_POST['action'] ?? '';

    // ── Update profile info ──────────────────────────────────────────────────
    if ($action === 'update_profile') {
        $fullName   = trim($_POST['full_name']   ?? '');
        $department = trim($_POST['department']  ?? '');

        if (strlen($fullName) < 2 || strlen($fullName) > 100) {
            $errors[] = 'Full name must be 2–100 characters.';
        }

        if (empty($errors)) {
            $r = safePreparedQuery(
                $conn,
                "UPDATE users SET full_name = ?, department = ? WHERE user_id = ?",
                "ssi",
                [$fullName, $department, $adminId]
            );
            if ($r['success'] && $r['affected_rows'] >= 0) {
                $success[] = 'Profile updated successfully.';
                $_SESSION['full_name'] = $fullName;
            } else {
                $errors[] = 'Failed to update profile. Please try again.';
            }
        }
    }

    // ── Change password ──────────────────────────────────────────────────────
    if ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword     = $_POST['new_password']     ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($currentPassword)) {
            $errors[] = 'Current password is required.';
        }
        if (strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        }
        if ($newPassword !== $confirmPassword) {
            $errors[] = 'New passwords do not match.';
        }

        if (empty($errors)) {
            // Verify current password
            $r = safePreparedQuery(
                $conn,
                "SELECT password_hash FROM users WHERE user_id = ?",
                "i",
                [$adminId]
            );
            if ($r['success'] && $r['result']) {
                $row = $r['result']->fetch_assoc();
                $r['result']->free();

                if (!password_verify($currentPassword, $row['password_hash'])) {
                    $errors[] = 'Current password is incorrect.';
                } else {
                    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $upd = safePreparedQuery(
                        $conn,
                        "UPDATE users SET password_hash = ? WHERE user_id = ?",
                        "si",
                        [$newHash, $adminId]
                    );
                    if ($upd['success']) {
                        $success[] = 'Password changed successfully.';
                    } else {
                        $errors[] = 'Failed to update password. Please try again.';
                    }
                }
            } else {
                $errors[] = 'Could not verify current password.';
            }
        }
    }
}

// ── Fetch fresh admin data ───────────────────────────────────────────────────
$r = safePreparedQuery(
    $conn,
    "SELECT user_id, full_name, email, role, department, registration_number,
            is_verified, is_active, created_at, last_login
     FROM users WHERE user_id = ? LIMIT 1",
    "i",
    [$adminId]
);
$adminData = ($r['success'] && $r['result']) ? $r['result']->fetch_assoc() : $admin;
if ($r['result'] ?? null) $r['result']->free();

// ── Recent login activity ────────────────────────────────────────────────────
$activityResult = safePreparedQuery(
    $conn,
    "SELECT ip_address, user_agent, is_success, failure_reason, created_at
     FROM login_activity
     WHERE user_id = ?
     ORDER BY created_at DESC
     LIMIT 10",
    "i",
    [$adminId]
);
$loginActivity = [];
if ($activityResult['success'] && $activityResult['result']) {
    while ($row = $activityResult['result']->fetch_assoc()) {
        $loginActivity[] = $row;
    }
    $activityResult['result']->free();
}

// ── System stats for admin overview ─────────────────────────────────────────
$stats = [];
foreach ([
    'total_users'       => "SELECT COUNT(*) AS c FROM users",
    'total_assessments' => "SELECT COUNT(*) AS c FROM assessments",
    'active_students'   => "SELECT COUNT(*) AS c FROM users WHERE role='student' AND is_active=1",
    'total_teachers'    => "SELECT COUNT(*) AS c FROM users WHERE role='teacher'",
] as $key => $sql) {
    $sr = safeQuery($conn, $sql);
    if ($sr) {
        $row = $sr->fetch_assoc();
        $stats[$key] = (int) $row['c'];
        $sr->free();
    } else {
        $stats[$key] = 0;
    }
}

$csrfToken   = getCsrfToken();
$memberSince = date('F j, Y', strtotime($adminData['created_at'] ?? 'now'));
$lastLogin   = $adminData['last_login']
    ? date('M j, Y g:i A', strtotime($adminData['last_login']))
    : 'Never';
$initials    = implode('', array_map(fn($p) => strtoupper($p[0]), array_slice(explode(' ', trim($adminData['full_name'] ?? 'Admin')), 0, 2)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Profile — PrepaUra</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ── Reset & Base ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:          #0b0e17;
    --bg-card:     #111520;
    --bg-input:    #181d2a;
    --border:      #1f2638;
    --border-focus:#3b5bdb;
    --accent:      #3b5bdb;
    --accent-glow: rgba(59,91,219,0.25);
    --accent-2:    #00d4aa;
    --accent-warn: #f59e0b;
    --accent-err:  #ef4444;
    --text:        #e8ecf4;
    --text-muted:  #6b7a99;
    --text-subtle: #3d4a68;
    --success:     #10b981;
    --font-head:   'Syne', sans-serif;
    --font-body:   'DM Sans', sans-serif;
    --radius:      12px;
    --radius-sm:   8px;
    --shadow:      0 4px 24px rgba(0,0,0,0.4);
}

html { font-size: 16px; scroll-behavior: smooth; }
body {
    background: var(--bg);
    color: var(--text);
    font-family: var(--font-body);
    font-weight: 400;
    line-height: 1.6;
    min-height: 100vh;
}

/* ── Layout ── */
.page {
    display: grid;
    grid-template-columns: 260px 1fr;
    min-height: 100vh;
}

/* ── Sidebar ── */
.sidebar {
    background: var(--bg-card);
    border-right: 1px solid var(--border);
    padding: 32px 0;
    display: flex;
    flex-direction: column;
    position: sticky;
    top: 0;
    height: 100vh;
    overflow-y: auto;
}
.sidebar-logo {
    padding: 0 24px 32px;
    display: flex;
    align-items: center;
    gap: 10px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 24px;
}
.sidebar-logo .logo-mark {
    width: 36px; height: 36px;
    background: var(--accent);
    border-radius: var(--radius-sm);
    display: grid; place-items: center;
    font-family: var(--font-head);
    font-weight: 800;
    font-size: 18px;
    color: #fff;
}
.sidebar-logo span {
    font-family: var(--font-head);
    font-size: 18px;
    font-weight: 700;
    color: var(--text);
}
.nav-label {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--text-subtle);
    padding: 0 24px;
    margin-bottom: 8px;
}
.nav-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 11px 24px;
    color: var(--text-muted);
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    border-left: 3px solid transparent;
    transition: all .18s;
}
.nav-item:hover { color: var(--text); background: rgba(255,255,255,.03); }
.nav-item.active {
    color: var(--accent);
    border-left-color: var(--accent);
    background: var(--accent-glow);
}
.nav-item i { width: 18px; text-align: center; font-size: 15px; }
.nav-spacer { flex: 1; }
.sidebar-user {
    margin: 0 16px;
    padding: 14px;
    background: var(--bg-input);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    display: flex;
    align-items: center;
    gap: 12px;
}
.sidebar-user .avatar-sm {
    width: 36px; height: 36px;
    background: var(--accent);
    border-radius: 50%;
    display: grid; place-items: center;
    font-family: var(--font-head);
    font-weight: 700;
    font-size: 13px;
    flex-shrink: 0;
}
.sidebar-user .info .name {
    font-size: 13px; font-weight: 600; color: var(--text);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 130px;
}
.sidebar-user .info .role {
    font-size: 11px; color: var(--accent); text-transform: uppercase;
    letter-spacing: .06em; font-weight: 600;
}

/* ── Main content ── */
.main {
    padding: 40px 48px;
    overflow-y: auto;
}

/* ── Page header ── */
.page-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 36px;
}
.page-header h1 {
    font-family: var(--font-head);
    font-size: 28px;
    font-weight: 800;
    color: var(--text);
    line-height: 1.2;
}
.page-header p { color: var(--text-muted); font-size: 14px; margin-top: 4px; }
.breadcrumb {
    display: flex; align-items: center; gap: 6px;
    font-size: 13px; color: var(--text-muted);
    margin-bottom: 6px;
}
.breadcrumb a { color: var(--text-muted); text-decoration: none; }
.breadcrumb a:hover { color: var(--accent); }
.breadcrumb span { color: var(--text-subtle); }

/* ── Alert banners ── */
.alert {
    padding: 14px 18px;
    border-radius: var(--radius-sm);
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 24px;
}
.alert-success { background: rgba(16,185,129,.12); border: 1px solid rgba(16,185,129,.3); color: var(--success); }
.alert-error   { background: rgba(239,68,68,.12);  border: 1px solid rgba(239,68,68,.3);  color: var(--accent-err); }

/* ── Profile hero card ── */
.hero-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 32px;
    display: flex;
    align-items: center;
    gap: 28px;
    margin-bottom: 28px;
    position: relative;
    overflow: hidden;
}
.hero-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0; height: 3px;
    background: linear-gradient(90deg, var(--accent), var(--accent-2));
}
.hero-avatar {
    width: 88px; height: 88px;
    background: linear-gradient(135deg, var(--accent), #6b48ff);
    border-radius: 50%;
    display: grid; place-items: center;
    font-family: var(--font-head);
    font-weight: 800;
    font-size: 32px;
    color: #fff;
    flex-shrink: 0;
    box-shadow: 0 0 0 4px rgba(59,91,219,.2);
}
.hero-info .name {
    font-family: var(--font-head);
    font-size: 24px; font-weight: 800;
    color: var(--text);
}
.hero-info .role-badge {
    display: inline-flex; align-items: center; gap: 6px;
    background: var(--accent-glow);
    border: 1px solid rgba(59,91,219,.4);
    color: var(--accent);
    font-size: 12px; font-weight: 600;
    letter-spacing: .08em; text-transform: uppercase;
    padding: 4px 12px; border-radius: 20px;
    margin: 6px 0 12px;
}
.hero-meta {
    display: flex; gap: 20px; flex-wrap: wrap;
    font-size: 13px; color: var(--text-muted);
}
.hero-meta span { display: flex; align-items: center; gap: 6px; }
.hero-meta i { color: var(--accent); font-size: 13px; }
.hero-stats {
    margin-left: auto;
    display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;
    flex-shrink: 0;
}
.stat-pill {
    background: var(--bg-input);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 12px 18px;
    text-align: center;
    min-width: 100px;
}
.stat-pill .val {
    font-family: var(--font-head);
    font-size: 22px; font-weight: 800;
    color: var(--text);
    line-height: 1;
}
.stat-pill .lbl {
    font-size: 11px; color: var(--text-muted);
    margin-top: 4px; text-transform: uppercase; letter-spacing: .06em;
}

/* ── Content grid ── */
.content-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
}
.content-grid .full { grid-column: 1 / -1; }

/* ── Cards ── */
.card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 28px;
}
.card-title {
    font-family: var(--font-head);
    font-size: 16px; font-weight: 700;
    color: var(--text);
    margin-bottom: 22px;
    display: flex; align-items: center; gap: 10px;
}
.card-title i { color: var(--accent); font-size: 15px; }

/* ── Form elements ── */
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
.form-row.single { grid-template-columns: 1fr; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-group label {
    font-size: 12px; font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase; letter-spacing: .07em;
}
.form-control {
    background: var(--bg-input);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    color: var(--text);
    font-family: var(--font-body);
    font-size: 14px;
    padding: 11px 14px;
    width: 100%;
    outline: none;
    transition: border-color .18s, box-shadow .18s;
}
.form-control:focus {
    border-color: var(--border-focus);
    box-shadow: 0 0 0 3px var(--accent-glow);
}
.form-control[readonly] { opacity: .55; cursor: not-allowed; }
.form-hint { font-size: 11px; color: var(--text-subtle); }

.pw-wrap { position: relative; }
.pw-wrap .form-control { padding-right: 42px; }
.pw-toggle {
    position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
    background: none; border: none; cursor: pointer;
    color: var(--text-muted); font-size: 14px;
    padding: 4px;
}
.pw-toggle:hover { color: var(--text); }

.btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 11px 22px;
    border: none; border-radius: var(--radius-sm);
    font-family: var(--font-head); font-size: 14px; font-weight: 600;
    cursor: pointer; transition: all .18s;
    text-decoration: none;
}
.btn-primary {
    background: var(--accent); color: #fff;
    box-shadow: 0 4px 14px rgba(59,91,219,.35);
}
.btn-primary:hover { background: #2f4bbf; transform: translateY(-1px); }
.btn-secondary {
    background: var(--bg-input);
    border: 1px solid var(--border);
    color: var(--text-muted);
}
.btn-secondary:hover { color: var(--text); border-color: var(--text-muted); }
.btn-sm { padding: 8px 16px; font-size: 13px; }
.form-actions { display: flex; gap: 12px; justify-content: flex-end; margin-top: 20px; }

/* ── Divider ── */
.divider { border: none; border-top: 1px solid var(--border); margin: 20px 0; }

/* ── Activity table ── */
.activity-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.activity-table th {
    color: var(--text-muted);
    font-size: 11px; font-weight: 600;
    text-transform: uppercase; letter-spacing: .07em;
    padding: 0 12px 12px 0; text-align: left;
    border-bottom: 1px solid var(--border);
}
.activity-table td {
    padding: 12px 12px 12px 0;
    border-bottom: 1px solid var(--border);
    color: var(--text-muted);
    vertical-align: top;
}
.activity-table tr:last-child td { border-bottom: none; }
.activity-table .ip { font-family: monospace; font-size: 12px; color: var(--text); }
.activity-table .ua { max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 9px; border-radius: 20px; font-size: 11px; font-weight: 600;
}
.badge-ok  { background: rgba(16,185,129,.12); color: var(--success); }
.badge-err { background: rgba(239,68,68,.12);  color: var(--accent-err); }

/* ── Empty state ── */
.empty-state { text-align: center; padding: 32px; color: var(--text-muted); font-size: 14px; }
.empty-state i { font-size: 32px; color: var(--text-subtle); display: block; margin-bottom: 10px; }

/* ── Responsive ── */
@media (max-width: 1024px) {
    .page { grid-template-columns: 1fr; }
    .sidebar { display: none; }
    .main { padding: 24px; }
    .content-grid { grid-template-columns: 1fr; }
    .hero-stats { grid-template-columns: repeat(4, 1fr); }
    .hero-card { flex-wrap: wrap; }
}
@media (max-width: 640px) {
    .form-row { grid-template-columns: 1fr; }
    .hero-stats { grid-template-columns: repeat(2, 1fr); }
}
</style>
</head>
<body>
<div class="page">

  <!-- ── Sidebar ── -->
  <aside class="sidebar">
    <div class="sidebar-logo">
      <div class="logo-mark">P</div>
      <span>PrepaUra</span>
    </div>

    <div class="nav-label">Main</div>
    <a href="admin-dashboard.php" class="nav-item"><i class="fas fa-th-large"></i> Dashboard</a>
    <a href="admin-users.php"     class="nav-item"><i class="fas fa-users"></i> Users</a>
    <a href="admin-assessments.php" class="nav-item"><i class="fas fa-file-alt"></i> Assessments</a>
    <a href="admin-groups.php"    class="nav-item"><i class="fas fa-layer-group"></i> Groups</a>
    <a href="admin-materials.php" class="nav-item"><i class="fas fa-book-open"></i> Materials</a>

    <div class="nav-label" style="margin-top:16px">System</div>
    <a href="admin-settings.php"  class="nav-item"><i class="fas fa-sliders-h"></i> Settings</a>
    <a href="admin-profile.php"   class="nav-item active"><i class="fas fa-user-shield"></i> My Profile</a>
    <a href="logout.php"          class="nav-item"><i class="fas fa-sign-out-alt"></i> Logout</a>

    <div class="nav-spacer"></div>
    <div class="sidebar-user">
      <div class="avatar-sm"><?= htmlspecialchars($initials) ?></div>
      <div class="info">
        <div class="name"><?= htmlspecialchars($adminData['full_name'] ?? 'Admin') ?></div>
        <div class="role">Administrator</div>
      </div>
    </div>
  </aside>

  <!-- ── Main ── -->
  <main class="main">

    <!-- Header -->
    <div class="page-header">
      <div>
        <div class="breadcrumb">
          <a href="admin-dashboard.php">Dashboard</a>
          <span>/</span>
          <span>My Profile</span>
        </div>
        <h1>Admin Profile</h1>
        <p>Manage your personal information and account security.</p>
      </div>
      <a href="admin-dashboard.php" class="btn btn-secondary btn-sm">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
      </a>
    </div>

    <!-- Alerts -->
    <?php foreach ($success as $msg): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($msg) ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $msg): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($msg) ?></div>
    <?php endforeach; ?>

    <!-- Hero card -->
    <div class="hero-card">
      <div class="hero-avatar"><?= htmlspecialchars($initials) ?></div>
      <div class="hero-info">
        <div class="name"><?= htmlspecialchars($adminData['full_name'] ?? 'Administrator') ?></div>
        <div class="role-badge"><i class="fas fa-shield-alt"></i> Super Administrator</div>
        <div class="hero-meta">
          <span><i class="fas fa-envelope"></i><?= htmlspecialchars($adminData['email'] ?? '') ?></span>
          <?php if (!empty($adminData['department'])): ?>
          <span><i class="fas fa-building"></i><?= htmlspecialchars($adminData['department']) ?></span>
          <?php endif; ?>
          <span><i class="fas fa-calendar-alt"></i>Member since <?= $memberSince ?></span>
          <span><i class="fas fa-clock"></i>Last login: <?= $lastLogin ?></span>
        </div>
      </div>
      <div class="hero-stats">
        <div class="stat-pill">
          <div class="val"><?= number_format($stats['total_users']) ?></div>
          <div class="lbl">Total Users</div>
        </div>
        <div class="stat-pill">
          <div class="val"><?= number_format($stats['active_students']) ?></div>
          <div class="lbl">Students</div>
        </div>
        <div class="stat-pill">
          <div class="val"><?= number_format($stats['total_teachers']) ?></div>
          <div class="lbl">Teachers</div>
        </div>
        <div class="stat-pill">
          <div class="val"><?= number_format($stats['total_assessments']) ?></div>
          <div class="lbl">Assessments</div>
        </div>
      </div>
    </div>

    <!-- Content grid -->
    <div class="content-grid">

      <!-- Edit profile -->
      <div class="card">
        <div class="card-title"><i class="fas fa-user-edit"></i> Personal Information</div>
        <form method="POST" novalidate>
          <input type="hidden" name="action"     value="update_profile">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

          <div class="form-row">
            <div class="form-group">
              <label for="full_name">Full Name</label>
              <input type="text" id="full_name" name="full_name" class="form-control"
                     value="<?= htmlspecialchars($adminData['full_name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
              <label>Email Address</label>
              <input type="email" class="form-control"
                     value="<?= htmlspecialchars($adminData['email'] ?? '') ?>" readonly>
              <span class="form-hint">Contact support to change your email.</span>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label for="department">Department</label>
              <input type="text" id="department" name="department" class="form-control"
                     placeholder="e.g. Information Technology"
                     value="<?= htmlspecialchars($adminData['department'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label>Role</label>
              <input type="text" class="form-control" value="Administrator" readonly>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>Registration Number</label>
              <input type="text" class="form-control"
                     value="<?= htmlspecialchars($adminData['registration_number'] ?? '—') ?>" readonly>
            </div>
            <div class="form-group">
              <label>Account Status</label>
              <input type="text" class="form-control"
                     value="<?= $adminData['is_active'] ? 'Active' : 'Inactive' ?>" readonly>
            </div>
          </div>

          <div class="form-actions">
            <button type="submit" class="btn btn-primary">
              <i class="fas fa-save"></i> Save Changes
            </button>
          </div>
        </form>
      </div>

      <!-- Change password -->
      <div class="card">
        <div class="card-title"><i class="fas fa-lock"></i> Change Password</div>
        <form method="POST" novalidate id="pwForm">
          <input type="hidden" name="action"     value="change_password">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

          <div class="form-row single">
            <div class="form-group">
              <label for="current_password">Current Password</label>
              <div class="pw-wrap">
                <input type="password" id="current_password" name="current_password"
                       class="form-control" autocomplete="current-password">
                <button type="button" class="pw-toggle" data-target="current_password">
                  <i class="fas fa-eye"></i>
                </button>
              </div>
            </div>
          </div>

          <div class="form-row single">
            <div class="form-group">
              <label for="new_password">New Password</label>
              <div class="pw-wrap">
                <input type="password" id="new_password" name="new_password"
                       class="form-control" autocomplete="new-password">
                <button type="button" class="pw-toggle" data-target="new_password">
                  <i class="fas fa-eye"></i>
                </button>
              </div>
              <span class="form-hint">Minimum 8 characters.</span>
            </div>
          </div>

          <div class="form-row single">
            <div class="form-group">
              <label for="confirm_password">Confirm New Password</label>
              <div class="pw-wrap">
                <input type="password" id="confirm_password" name="confirm_password"
                       class="form-control" autocomplete="new-password">
                <button type="button" class="pw-toggle" data-target="confirm_password">
                  <i class="fas fa-eye"></i>
                </button>
              </div>
            </div>
          </div>

          <!-- Strength bar -->
          <div style="margin: 4px 0 16px">
            <div style="height:4px; background: var(--border); border-radius:4px; overflow:hidden">
              <div id="pwStrengthBar" style="height:100%; width:0; transition: width .3s, background .3s; border-radius:4px;"></div>
            </div>
            <span id="pwStrengthLabel" style="font-size:11px; color:var(--text-subtle); margin-top:4px; display:block;"></span>
          </div>

          <div class="form-actions">
            <button type="submit" class="btn btn-primary">
              <i class="fas fa-key"></i> Update Password
            </button>
          </div>
        </form>
      </div>

      <!-- Login activity -->
      <div class="card full">
        <div class="card-title"><i class="fas fa-history"></i> Recent Login Activity</div>
        <?php if (empty($loginActivity)): ?>
        <div class="empty-state">
          <i class="fas fa-history"></i>
          No login activity recorded yet.
        </div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="activity-table">
          <thead>
            <tr>
              <th>Date &amp; Time</th>
              <th>IP Address</th>
              <th>Browser / Device</th>
              <th>Status</th>
              <th>Note</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($loginActivity as $log): ?>
            <tr>
              <td><?= date('M j, Y g:i A', strtotime($log['created_at'])) ?></td>
              <td class="ip"><?= htmlspecialchars($log['ip_address'] ?? '—') ?></td>
              <td class="ua" title="<?= htmlspecialchars($log['user_agent'] ?? '') ?>">
                <?= htmlspecialchars(substr($log['user_agent'] ?? '—', 0, 60)) ?>
              </td>
              <td>
                <?php if ($log['is_success']): ?>
                  <span class="badge badge-ok"><i class="fas fa-check"></i> Success</span>
                <?php else: ?>
                  <span class="badge badge-err"><i class="fas fa-times"></i> Failed</span>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($log['failure_reason'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php endif; ?>
      </div>

    </div><!-- /content-grid -->
  </main>
</div>

<script>
// ── Password visibility toggle ──────────────────────────────────────────────
document.querySelectorAll('.pw-toggle').forEach(btn => {
    btn.addEventListener('click', () => {
        const target = document.getElementById(btn.dataset.target);
        const icon   = btn.querySelector('i');
        if (target.type === 'password') {
            target.type = 'password' === target.type ? 'text' : 'password';
            target.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            target.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    });
});

// ── Password strength meter ─────────────────────────────────────────────────
const newPwInput = document.getElementById('new_password');
const bar        = document.getElementById('pwStrengthBar');
const label      = document.getElementById('pwStrengthLabel');

function passwordStrength(pw) {
    let score = 0;
    if (pw.length >= 8)  score++;
    if (pw.length >= 12) score++;
    if (/[A-Z]/.test(pw)) score++;
    if (/[0-9]/.test(pw)) score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;
    return score;
}

newPwInput?.addEventListener('input', () => {
    const pw    = newPwInput.value;
    const score = passwordStrength(pw);
    const map   = [
        { w: '0%',   c: 'transparent',          t: '' },
        { w: '20%',  c: '#ef4444',               t: 'Very weak' },
        { w: '40%',  c: '#f59e0b',               t: 'Weak' },
        { w: '60%',  c: '#eab308',               t: 'Fair' },
        { w: '80%',  c: '#3b82f6',               t: 'Strong' },
        { w: '100%', c: 'var(--success)',         t: 'Very strong' },
    ];
    const s = map[Math.min(score, 5)];
    bar.style.width      = pw ? s.w : '0%';
    bar.style.background = s.c;
    label.textContent    = pw ? s.t : '';
});

// ── Client-side password confirmation check ─────────────────────────────────
document.getElementById('pwForm')?.addEventListener('submit', e => {
    const np = document.getElementById('new_password').value;
    const cp = document.getElementById('confirm_password').value;
    if (np && np !== cp) {
        e.preventDefault();
        alert('New passwords do not match.');
    }
});

// ── Auto-dismiss alerts after 5s ────────────────────────────────────────────
document.querySelectorAll('.alert').forEach(el => {
    setTimeout(() => el.style.transition = 'opacity .5s', 4500);
    setTimeout(() => el.style.opacity = '0', 5000);
    setTimeout(() => el.remove(), 5500);
});
</script>
</body>
</html>

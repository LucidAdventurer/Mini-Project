<?php
// ============================================================
// test-results.php
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db-guard.php';

$currentUser = validateSession($conn, 'student');
$userId      = (int) $currentUser['user_id'];

$attemptId = (int)($_GET['attempt_id'] ?? 0);
if ($attemptId <= 0) {
    header('Location: student-dashboard.php?error=invalid_attempt');
    exit;
}

// ── Fetch all submitted attempts for this assessment (for dropdown) ──
$allAttempts = [];
$thisAttemptRow = safePreparedQuery($conn,
    "SELECT aa.assessment_id FROM assessment_attempts aa
     WHERE aa.attempt_id = ? AND aa.user_id = ?",
    "ii", [$attemptId, $userId]
);
if ($thisAttemptRow['success'] && $thisAttemptRow['result'] && $thisAttemptRow['result']->num_rows > 0) {
    $tar = $thisAttemptRow['result']->fetch_assoc();
    $thisAttemptRow['result']->free();
    $asmId = (int)$tar['assessment_id'];
    $allAttR = safePreparedQuery($conn,
        "SELECT attempt_id, attempt_number, submitted_at, score, percentage
         FROM assessment_attempts
         WHERE assessment_id = ? AND user_id = ? AND status = 'submitted'
         ORDER BY attempt_number ASC",
        "ii", [$asmId, $userId]
    );
    if ($allAttR['success'] && $allAttR['result']) {
        while ($r = $allAttR['result']->fetch_assoc()) $allAttempts[] = $r;
        $allAttR['result']->free();
    }
}


$userName     = $currentUser['full_name'] ?? 'Student';
$userEmail    = $currentUser['email']     ?? '';
$userInitials = strtoupper(substr($userName, 0, 2));

// ── Report status ──
$reportStatusResult = safePreparedQuery($conn,
    "SELECT status FROM student_reports WHERE user_id = ? ORDER BY created_at DESC LIMIT 1",
    "i", [$userId]
);
$latestReportStatus = null;
if ($reportStatusResult['success'] && $reportStatusResult['result']) {
    $rrow = $reportStatusResult['result']->fetch_assoc();
    $latestReportStatus = $rrow['status'] ?? null;
    $reportStatusResult['result']->free();
}
$hasOpenReport = in_array($latestReportStatus, ['pending', 'in_progress']);

// ── Handle report submission ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_report') {
    $reportTitle = trim($_POST['report_title'] ?? '');
    $reportDesc  = trim($_POST['report_description'] ?? '');
    $reportImage = null;
    if (!empty($_FILES['report_image']) && $_FILES['report_image']['error'] === UPLOAD_ERR_OK) {
        $file    = $_FILES['report_image'];
        $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
        if ($file['size'] <= 5*1024*1024 && in_array($file['type'], $allowed)) {
            $ext       = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $uploadDir = 'uploads/reports/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $stored    = 'report_'.$userId.'_'.time().'.'.$ext;
            $fullPath  = $uploadDir.$stored;
            if (move_uploaded_file($file['tmp_name'], $fullPath)) $reportImage = $fullPath;
        }
    }
    if ($reportTitle !== '' && $reportDesc !== '') {
        safePreparedQuery($conn,
            "INSERT INTO student_reports (user_id, title, description, image_path, status, created_at) VALUES (?,?,?,?,'pending',NOW())",
            "isss", [$userId, $reportTitle, $reportDesc, $reportImage]
        );
        $hasOpenReport = true; $latestReportStatus = 'pending';
    }
    header('Location: '.$_SERVER['PHP_SELF'].'?report=sent');
    exit;
}
// Fetch profile image
$imgRes = safePreparedQuery($conn, "SELECT profile_image FROM users WHERE user_id = ?", "i", [$userId]);
$userProfileImage = '';
if ($imgRes['success'] && $imgRes['result']) {
    $imgRow = $imgRes['result']->fetch_assoc();
    $userProfileImage = $imgRow['profile_image'] ?? '';
    $imgRes['result']->free();
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

// ── All notifications for dropdown ──
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

function timeAgo(string $datetime): string {
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
    <meta name="description" content="Test Results - Placement Portal">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <title>Test Results - Placement Portal</title>
    <style>
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
            --sidebar-w:     260px;
            --transition:    .2s cubic-bezier(.4,0,.2,1);
        }

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding-top: var(--nav-h);
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
        }

        /* ══ NAVBAR ══ */
        .navbar {
            background: var(--primary);
            padding: 0 28px; height: var(--nav-h);
            display: flex; align-items: center; justify-content: space-between;
            position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
            box-shadow: 0 1px 0 rgba(255,255,255,.06), 0 4px 20px rgba(0,0,0,.18);
        }
        .navbar-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; }

        .nav-search { flex: 1; max-width: 440px; margin: 0 32px; position: relative; }
        .nav-search input {
            width: 100%; padding: 10px 18px 10px 42px;
            border: 1.5px solid rgba(255,255,255,.15); border-radius: 10px;
            font-family: 'Inter', sans-serif; font-size: 14px;
            background: rgba(255,255,255,.1); color: white;
            outline: none; transition: var(--transition);
        }
        .nav-search input::placeholder { color: rgba(255,255,255,.5); }
        .nav-search input:focus {
            background: rgba(255,255,255,.18); border-color: rgba(255,255,255,.35);
            box-shadow: 0 0 0 3px rgba(14,165,233,.25);
        }
        .nav-search .sicon {
            position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
            color: rgba(255,255,255,.5); font-size: 13px; pointer-events: none;
        }

        .nav-profile { display: flex; align-items: center; gap: 10px; }

        .notification-icon {
            position: relative; width: 38px; height: 38px;
            background: rgba(255,255,255,.12); border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; border: 1.5px solid rgba(255,255,255,.15);
            transition: var(--transition); color: white;
        }
        .notification-icon:hover { background: rgba(255,255,255,.2); border-color: rgba(255,255,255,.3); }

        .notif-dropdown-wrap { position: relative; }
        .notif-dropdown {
            position: absolute; top: calc(100% + 12px); right: 0;
            background: var(--surface); border-radius: var(--radius);
            box-shadow: var(--shadow-md); border: 1px solid var(--border);
            width: 348px; opacity: 0; visibility: hidden;
            transform: translateY(-6px) scale(.98); transition: var(--transition); z-index: 1002;
        }
        .notif-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
        .notif-dropdown-header {
            padding: 16px 20px 14px;
            font-family: 'Sora', sans-serif; font-weight: 700; font-size: 14px; color: var(--text);
            border-bottom: 1px solid var(--border);
        }
        .notif-list { max-height: 360px; overflow-y: auto; scrollbar-width: thin; scrollbar-color: var(--border) transparent; }
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
        .notif-dismiss-btn {
            background: none; border: none; color: var(--text-soft);
            font-size: 13px; line-height: 1; padding: 2px 5px;
            border-radius: 4px; cursor: pointer; flex-shrink: 0;
            opacity: 0; transition: opacity .15s, background .15s, color .15s;
            align-self: flex-start; margin-top: 2px;
        }
        .notif-item:hover .notif-dismiss-btn { opacity: 1; }
        .notif-dismiss-btn:hover { background: rgba(239,68,68,.1); color: #ef4444; }

        .notification-badge {
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

        .profile-dropdown-container { position: relative; }
        .profile-button {
            display: flex; align-items: center; gap: 9px;
            padding: 6px 12px 6px 6px;
            background: rgba(255,255,255,.12);
            border: 1.5px solid rgba(255,255,255,.15);
            border-radius: 10px; cursor: pointer; transition: var(--transition);
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
            position: absolute; top: calc(100% + 12px); right: 0;
            background: var(--surface); border-radius: var(--radius);
            box-shadow: var(--shadow-md); border: 1px solid var(--border);
            min-width: 240px; opacity: 0; visibility: hidden;
            transform: translateY(-6px) scale(.98); transition: var(--transition);
            z-index: 1001; overflow: hidden;
        }
        .profile-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
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
        .dropdown-user-name { font-family: 'Sora', sans-serif; font-weight: 700; font-size: 15px; color: white; margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .dropdown-user-email { font-size: 12px; color: rgba(255,255,255,.65); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .dropdown-menu { padding: 8px; }
        .dropdown-item {
            display: flex; align-items: center; gap: 11px;
            padding: 10px 12px; border-radius: 8px;
            color: var(--text-mid); text-decoration: none;
            cursor: pointer; border: none; background: none;
            width: 100%; text-align: left; font-size: 13.5px;
            font-family: 'Inter', sans-serif; transition: var(--transition);
        }
        .dropdown-item:hover { background: var(--surface2); color: var(--text); }
        .dropdown-item-icon { font-size: 16px; width: 20px; text-align: center; flex-shrink: 0; }
        .dropdown-divider { height: 1px; background: var(--border); margin: 6px 8px; }
        .dropdown-item.logout { color: var(--danger); }
        .dropdown-item.logout:hover { background: #fef2f2; }

        .dropdown-overlay { position: fixed; inset: 0; background: transparent; z-index: 999; display: none; }
        .dropdown-overlay.show { display: block; }

        /* ══ PAGE LAYOUT ══ */
        .page-wrapper { display: flex; min-height: calc(100vh - var(--nav-h)); }

        .left-sidebar {
            width: var(--sidebar-w); flex-shrink: 0;
            padding: 20px 12px;
            display: flex; flex-direction: column; gap: 2px;
            background: var(--surface); border-right: 1px solid var(--border);
            min-height: calc(100vh - var(--nav-h));
            position: sticky; top: var(--nav-h); align-self: flex-start;
        }
        .left-sidebar-label {
            font-size: 10.5px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .1em;
            color: var(--text-soft); padding: 14px 12px 7px;
        }
        .left-sidebar a {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 13px; border-radius: var(--radius-sm);
            text-decoration: none; font-size: 13.5px; font-weight: 500;
            color: var(--text-mid); transition: var(--transition); position: relative;
        }
        .left-sidebar a:hover { background: var(--surface2); color: var(--primary); }
        .left-sidebar a.active {
            background: linear-gradient(135deg, #e0f2fe, #e0f9ff);
            color: var(--accent); font-weight: 600;
        }
        .left-sidebar a.active::before {
            content: ''; position: absolute; left: 0; top: 20%; bottom: 20%;
            width: 3px; border-radius: 0 3px 3px 0; background: var(--accent);
        }
        .left-sidebar a i { width: 18px; text-align: center; font-size: 14px; flex-shrink: 0; }
        .left-sidebar-bottom { margin-top: auto; padding-top: 12px; border-top: 1px solid var(--border); }
        .left-sidebar-bottom button {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 13px; border-radius: var(--radius-sm);
            font-size: 13.5px; font-weight: 500;
            color: var(--danger); background: none; border: none;
            cursor: pointer; width: 100%; transition: var(--transition);
            font-family: 'Inter', sans-serif;
        }
        .left-sidebar-bottom button:hover { background: #fef2f2; }
        .left-sidebar-bottom button i { width: 18px; text-align: center; font-size: 14px; }

        .page-content { flex: 1; min-width: 0; padding: 28px 28px 40px 0; }

        @media (max-width: 900px) { .left-sidebar { display: none; } .page-content { padding: 20px; } }

        /* ══ LOADING / ERROR ══ */
        .loading-state, .error-state {
            background: var(--surface); border-radius: var(--radius);
            padding: 60px 40px; text-align: center; box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }
        .loading-spinner {
            width: 48px; height: 48px;
            border: 4px solid var(--border); border-top-color: var(--accent);
            border-radius: 50%; margin: 0 auto 20px;
            animation: spin .8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .error-state h2 { font-family: 'Sora', sans-serif; color: var(--danger); margin-bottom: 10px; }
        .error-state p  { color: var(--text-mid); margin-bottom: 20px; }
        .btn-back-link {
            display: inline-block; padding: 10px 24px;
            background: var(--accent); color: white;
            text-decoration: none; border-radius: var(--radius-sm); font-weight: 700;
        }

        /* ══ ATTEMPT HISTORY SIDEBAR ══ */
        .attempt-history-label {
            font-size: 10.5px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .1em;
            color: var(--text-soft); padding: 18px 12px 8px;
            border-top: 1px solid var(--border); margin-top: 6px;
        }
        .attempt-card {
            display: flex; flex-direction: column; gap: 3px;
            padding: 10px 13px; border-radius: var(--radius-sm);
            text-decoration: none; font-size: 13px; font-weight: 500;
            color: var(--text-mid); transition: var(--transition);
            border: 1.5px solid transparent; cursor: pointer;
            background: none; width: 100%; text-align: left;
            font-family: 'Inter', sans-serif;
        }
        .attempt-card:hover { background: var(--surface2); color: var(--primary); border-color: var(--border); }
        .attempt-card.active {
            background: linear-gradient(135deg, #e0f2fe, #e0f9ff);
            border-color: var(--accent); color: var(--accent);
        }
        .attempt-card .ac-top {
            display: flex; align-items: center; justify-content: space-between;
        }
        .attempt-card .ac-num { font-weight: 700; font-size: 13px; }
        .attempt-card .ac-pct {
            font-family: 'Sora', sans-serif; font-weight: 700; font-size: 14px;
            color: var(--success);
        }
        .attempt-card.active .ac-pct { color: var(--accent); }
        .attempt-card .ac-pct.fail { color: var(--danger); }
        .attempt-card .ac-date { font-size: 11px; color: var(--text-soft); margin-top: 1px; }
        .attempt-card .ac-bar {
            height: 4px; border-radius: 99px;
            background: var(--border); margin-top: 5px; overflow: hidden;
        }
        .attempt-card .ac-bar-fill {
            height: 100%; border-radius: 99px;
            background: linear-gradient(90deg, var(--accent), var(--success));
            transition: width .6s ease;
        }

        /* ══ RESULTS HEADER ══ */
        .results-header {
            background: linear-gradient(135deg, var(--primary) 0%, #1e5276 60%, #1a6fa0 100%);
            border-radius: var(--radius); padding: 40px 36px;
            margin-bottom: 24px; text-align: center;
            position: relative; overflow: hidden;
            box-shadow: 0 4px 24px rgba(26,58,82,.3);
        }
        .results-header::before {
            content: ''; position: absolute; top: -60px; right: -60px;
            width: 220px; height: 220px; border-radius: 50%;
            background: rgba(255,255,255,.05); pointer-events: none;
        }
        .results-header::after {
            content: ''; position: absolute; bottom: -80px; left: 80px;
            width: 180px; height: 180px; border-radius: 50%;
            background: rgba(14,165,233,.08); pointer-events: none;
        }
        .test-title {
            font-family: 'Sora', sans-serif;
            font-size: 26px; font-weight: 800; color: white;
            margin-bottom: 8px; position: relative; z-index: 1;
        }
        .test-date { font-size: 13.5px; color: rgba(255,255,255,.7); margin-bottom: 28px; position: relative; z-index: 1; }

        /* Score circle */
        .score-display { display: flex; justify-content: center; margin-bottom: 28px; position: relative; z-index: 1; }
        .score-circle {
            position: relative; width: 190px; height: 190px; border-radius: 50%;
            background: conic-gradient(
                rgba(255,255,255,.9) 0%,
                rgba(255,255,255,.9) var(--score-pct),
                rgba(255,255,255,.15) var(--score-pct),
                rgba(255,255,255,.15) 100%
            );
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 8px 30px rgba(0,0,0,.2);
        }
        .score-circle::before {
            content: ''; position: absolute;
            width: 160px; height: 160px; border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), #1e5276);
        }
        .score-content { position: relative; z-index: 1; text-align: center; }
        .score-value   { font-family: 'Sora', sans-serif; font-size: 46px; font-weight: 800; color: white; line-height: 1; }
        .score-denom   { font-size: 13px; color: rgba(255,255,255,.7); }
        .score-pct-text{ font-family: 'Sora', sans-serif; font-size: 17px; color: rgba(255,255,255,.9); margin-top: 4px; font-weight: 700; }

        /* Performance badge */
        .performance-badge {
            display: inline-block; padding: 9px 22px;
            border-radius: 20px; font-family: 'Sora', sans-serif;
            font-size: 14px; font-weight: 700; margin-bottom: 22px;
            position: relative; z-index: 1;
        }
        .badge-excellent { background: linear-gradient(135deg, var(--success), #34d399); color: white; }
        .badge-good      { background: linear-gradient(135deg, var(--accent), var(--accent2)); color: white; }
        .badge-average   { background: linear-gradient(135deg, var(--warning), #fbbf24); color: white; }
        .badge-poor      { background: linear-gradient(135deg, var(--danger), #f87171); color: white; }
        .badge-passed    { background: linear-gradient(135deg, var(--success), #34d399); color: white; }
        .badge-failed    { background: linear-gradient(135deg, var(--danger), #f87171); color: white; }

        /* Quick stats */
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 14px; margin-top: 4px; position: relative; z-index: 1;
        }
        .stat-item {
            text-align: center; padding: 14px 10px;
            background: rgba(255,255,255,.1);
            border: 1px solid rgba(255,255,255,.15);
            border-radius: 12px; backdrop-filter: blur(8px);
            opacity: 0;
        }
        .stat-value { font-family: 'Sora', sans-serif; font-size: 22px; font-weight: 800; color: white; margin-bottom: 4px; }
        .stat-label { font-size: 11.5px; color: rgba(255,255,255,.65); }

        /* ══ ANALYSIS GRID ══ */
        .analysis-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px; margin-bottom: 24px;
        }
        .analysis-card {
            background: var(--surface); border-radius: var(--radius);
            padding: 24px; box-shadow: var(--shadow); border: 1px solid var(--border);
            opacity: 0;
        }
        .analysis-title {
            font-family: 'Sora', sans-serif;
            font-size: 16px; font-weight: 700; color: var(--text);
            margin-bottom: 18px; display: flex; align-items: center; gap: 10px;
        }
        .analysis-icon {
            width: 38px; height: 38px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center; font-size: 18px;
        }
        .icon-correct { background: #d1fae5; }
        .icon-chart   { background: #e0f2fe; }
        .icon-time    { background: #e0f2fe; }

        .breakdown-list { display: flex; flex-direction: column; gap: 10px; }
        .breakdown-item {
            display: flex; justify-content: space-between; align-items: center;
            padding: 11px 13px; background: var(--surface2); border-radius: var(--radius-sm);
            border: 1px solid var(--border);
        }
        .breakdown-label { font-size: 13.5px; color: var(--text-mid); }
        .breakdown-value { font-family: 'Sora', sans-serif; font-size: 15px; font-weight: 700; color: var(--text); }

        .progress-bar { width: 100%; height: 7px; background: var(--border); border-radius: 10px; overflow: hidden; margin-top: 6px; }
        .progress-fill { height: 100%; border-radius: 10px; transition: width .5s ease; }
        .fill-correct   { background: linear-gradient(90deg, var(--success), #34d399); }
        .fill-incorrect { background: linear-gradient(90deg, var(--danger), #f87171); }
        .fill-topic     { background: linear-gradient(90deg, var(--accent), var(--accent2)); }

        /* ══ QUESTIONS REVIEW ══ */
        .questions-section {
            background: var(--surface); border-radius: var(--radius);
            padding: 28px; box-shadow: var(--shadow); border: 1px solid var(--border);
        }
        .section-header {
            display: flex; justify-content: space-between;
            align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 14px;
        }
        .section-title { font-family: 'Sora', sans-serif; font-size: 20px; font-weight: 800; color: var(--text); }

        .filter-buttons { display: flex; gap: 8px; flex-wrap: wrap; }
        .filter-btn {
            padding: 7px 16px; background: var(--surface2);
            border: 1.5px solid var(--border); border-radius: 8px;
            font-family: 'Inter', sans-serif; font-size: 13px; font-weight: 600;
            color: var(--text-mid); cursor: pointer; transition: var(--transition);
        }
        .filter-btn.active { background: var(--accent); color: white; border-color: var(--accent); box-shadow: 0 2px 8px rgba(14,165,233,.3); }
        .filter-btn:hover:not(.active) { background: var(--border); color: var(--text); }

        .questions-list { display: flex; flex-direction: column; gap: 18px; margin-top: 18px; }

        .question-card {
            border: 1.5px solid var(--border); border-radius: var(--radius);
            padding: 24px; transition: var(--transition);
        }
        .question-card.hidden { display: none; }
        .question-card.correct {
            border-color: var(--success);
            background: linear-gradient(135deg, rgba(16,185,129,.04), rgba(52,211,153,.04));
        }
        .question-card.incorrect {
            border-color: var(--danger);
            background: linear-gradient(135deg, rgba(239,68,68,.04), rgba(248,113,113,.04));
        }
        .question-card.skipped {
            border-color: var(--warning);
            background: rgba(245,158,11,.03);
        }

        .question-header-row {
            display: flex; justify-content: space-between;
            align-items: center; margin-bottom: 14px;
        }
        .question-number { font-family: 'Sora', sans-serif; font-size: 15px; font-weight: 700; color: var(--text-mid); }
        .question-meta   { font-size: 11.5px; color: var(--text-soft); margin-top: 2px; }

        .result-badge {
            padding: 5px 12px; border-radius: 6px;
            font-family: 'Sora', sans-serif; font-size: 11.5px; font-weight: 700;
            display: flex; align-items: center; gap: 5px;
        }
        .result-badge.correct   { background: #dcfce7; color: #166534; }
        .result-badge.incorrect { background: #fee2e2; color: #991b1b; }
        .result-badge.skipped   { background: #fef3c7; color: #92400e; }

        .question-text { font-size: 16px; color: var(--text); margin-bottom: 18px; line-height: 1.65; }

        .answer-options { display: flex; flex-direction: column; gap: 10px; }
        .answer-option {
            display: flex; align-items: center;
            padding: 13px 16px; border-radius: var(--radius-sm);
            background: var(--surface2); border: 1.5px solid transparent;
        }
        .answer-option.user-selected      { border-color: var(--accent); background: #eff8ff; }
        .answer-option.correct-highlight  { border-color: var(--success); background: #f0fdf4; }
        .answer-option.wrong-highlight    { border-color: var(--danger); background: #fef2f2; }
        .option-label { font-weight: 700; margin-right: 12px; min-width: 28px; color: var(--text); }
        .option-text  { flex: 1; color: var(--text); font-size: 14px; }
        .option-badge { padding: 3px 9px; border-radius: 5px; font-size: 11px; font-weight: 700; margin-left: 8px; font-family: 'Sora', sans-serif; }
        .badge-yours   { background: #e0f2fe; color: #075985; }
        .badge-correct { background: #dcfce7; color: #166534; }

        .explanation-box {
            margin-top: 14px; padding: 13px 16px;
            background: #fefce8; border-left: 4px solid var(--warning);
            border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
            font-size: 13.5px; color: #92400e; line-height: 1.6;
        }
        .explanation-box strong { display: block; margin-bottom: 4px; }

        .marks-info { font-size: 12.5px; color: var(--text-soft); margin-top: 10px; text-align: right; font-weight: 600; }
        .marks-info.gained { color: var(--success); }
        .marks-info.lost   { color: var(--danger); }

        .hidden-results-notice {
            text-align: center; padding: 40px 20px;
            color: var(--text-soft); font-size: 15px;
        }
        .hidden-results-notice .notice-icon { font-size: 48px; margin-bottom: 14px; }

        /* ══ ACTION SECTION ══ */
        .action-section {
            margin-top: 28px; padding: 22px;
            background: var(--surface2); border-radius: var(--radius);
            border: 1px solid var(--border);
            display: flex; justify-content: center; gap: 14px; flex-wrap: wrap;
        }
        .action-btn {
            padding: 11px 28px; border: none; border-radius: var(--radius-sm);
            font-family: 'Inter', sans-serif; font-weight: 700; font-size: 13.5px;
            cursor: pointer; transition: var(--transition);
            display: flex; align-items: center; gap: 8px; text-decoration: none;
        }
        .btn-primary   { background: linear-gradient(135deg, var(--accent), var(--accent2)); color: white; box-shadow: 0 2px 8px rgba(14,165,233,.3); }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 14px rgba(14,165,233,.45); }
        .btn-secondary { background: var(--surface); color: var(--accent); border: 1.5px solid var(--accent); }
        .btn-secondary:hover { background: var(--accent); color: white; }

        /* ══ RESPONSIVE ══ */
        @media (max-width: 768px) {
            .navbar { padding: 0 16px; }
            .nav-search { display: none; }
            .profile-name { display: none; }
            .results-header { padding: 28px 20px; }
            .score-circle { width: 155px; height: 155px; }
            .score-circle::before { width: 127px; height: 127px; }
            .score-value { font-size: 36px; }
            .quick-stats { grid-template-columns: repeat(2,1fr); }
            .analysis-grid { grid-template-columns: 1fr; }
            .section-header { flex-direction: column; align-items: flex-start; }
            .question-header-row { flex-direction: column; align-items: flex-start; gap: 8px; }
            .action-section { flex-direction: column; }
            .action-btn { width: 100%; justify-content: center; }
        }

        @media print {
            .navbar, .action-section, .filter-buttons, .left-sidebar { display: none; }
            body { background: white; padding-top: 0; }
            .page-content { padding: 0; }
            .results-header, .analysis-card, .questions-section { box-shadow: none; border: 1px solid var(--border); }
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }
    
        /* ── Report status dot ── */
        .report-status-dot {
            width:11px;height:11px;border-radius:50%;background:#ef4444;
            border:2px solid var(--primary);display:inline-block;flex-shrink:0;
            animation:reportPulse 2s ease-in-out infinite;
        }
        .report-status-dot.resolved{background:#10b981;animation:none;}
        @keyframes reportPulse{0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.5);}60%{box-shadow:0 0 0 6px rgba(239,68,68,0);}}
        .report-dot-wrap {
            display:flex;align-items:center;gap:7px;padding:6px 10px;
            background:rgba(255,255,255,.1);border:1.5px solid rgba(255,255,255,.15);
            border-radius:9px;cursor:pointer;transition:var(--transition);
            font-size:11px;font-weight:600;color:rgba(255,255,255,.8);
        }
        .report-dot-wrap:hover{background:rgba(255,255,255,.18);}
        /* ── Report Modal ── */
        .report-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9100;
            display:flex;align-items:center;justify-content:center;backdrop-filter:blur(4px);
            opacity:0;visibility:hidden;transition:opacity .25s,visibility .25s;}
        .report-modal-overlay.open{opacity:1;visibility:visible;}
        .report-modal{background:#fff;border-radius:20px;width:100%;max-width:500px;margin:16px;
            box-shadow:0 24px 64px rgba(0,0,0,.22);overflow:hidden;
            transform:translateY(18px) scale(.97);transition:transform .28s cubic-bezier(.4,0,.2,1);}
        .report-modal-overlay.open .report-modal{transform:translateY(0) scale(1);}
        .report-modal-header{background:linear-gradient(135deg,#1a3a52,#1e5276);padding:22px 24px 18px;
            display:flex;align-items:flex-start;justify-content:space-between;}
        .report-modal-title{font-family:'Sora',sans-serif;font-size:17px;font-weight:800;color:#fff;margin-bottom:4px;}
        .report-modal-sub{font-size:12px;color:rgba(255,255,255,.6);}
        .report-modal-close{background:rgba(255,255,255,.15);border:none;border-radius:8px;color:#fff;
            width:30px;height:30px;font-size:16px;cursor:pointer;display:flex;align-items:center;
            justify-content:center;flex-shrink:0;transition:background .15s;margin-left:12px;}
        .report-modal-close:hover{background:rgba(255,255,255,.28);}
        .report-modal-body{padding:24px;display:flex;flex-direction:column;gap:16px;}
        .report-field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;
            letter-spacing:.06em;color:#64748b;margin-bottom:6px;}
        .report-field label span{color:#ef4444;margin-left:2px;}
        .report-field input,.report-field textarea{width:100%;padding:11px 14px;border:1.5px solid #e2e8f0;
            border-radius:10px;font-family:'Inter',sans-serif;font-size:13.5px;color:#0f172a;
            outline:none;transition:border-color .15s,box-shadow .15s;resize:vertical;}
        .report-field input:focus,.report-field textarea:focus{border-color:#0ea5e9;box-shadow:0 0 0 3px rgba(14,165,233,.15);}
        .report-drop-zone{border:2px dashed #cbd5e1;border-radius:12px;padding:20px;text-align:center;
            cursor:pointer;background:#f8fafc;transition:border-color .2s,background .2s;}
        .report-drop-zone:hover,.report-drop-zone.dragover{border-color:#0ea5e9;background:#eff8ff;}
        .report-drop-zone .dz-icon{font-size:28px;margin-bottom:6px;}
        .report-drop-zone .dz-text{font-size:13.5px;font-weight:600;color:#475569;}
        .report-drop-zone .dz-sub{font-size:12px;color:#94a3b8;margin-top:3px;}
        .report-img-preview{max-width:100%;max-height:140px;border-radius:8px;object-fit:contain;display:none;margin:8px auto 0;}
        .report-modal-footer{padding:0 24px 22px;display:flex;gap:10px;}
        .btn-report-cancel{flex:1;padding:11px;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;
            color:#475569;font-size:13.5px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;transition:.15s;}
        .btn-report-cancel:hover{background:#f1f5f9;}
        .btn-report-submit{flex:1;padding:11px;border-radius:10px;border:none;
            background:linear-gradient(135deg,#0ea5e9,#06b6d4);color:#fff;font-size:13.5px;font-weight:700;
            cursor:pointer;font-family:'Inter',sans-serif;transition:.15s;}
        .btn-report-submit:hover{opacity:.9;}
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
        <input type="text" id="searchInput" placeholder="Search questions..." autocomplete="off">
    </div>
    <div class="nav-profile">
        <div class="notif-dropdown-wrap">
            <button class="notification-icon" onclick="toggleNotifDropdown()" id="notifBtn">
                <span>🔔</span>
                <?php if ($unreadCount > 0): ?>
                <div class="notification-badge"><?= $unreadCount ?></div>
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
                    <div class="notif-item <?= $isUnread ? 'unread' : '' ?>" id="notif-<?= (int)$n['notification_id'] ?>">
                        <div class="notif-dot <?= $isUnread ? '' : 'read' ?>"></div>
                        <div class="notif-item-body">
                            <div class="notif-item-title"><?= $icon ?> <?= htmlspecialchars($n['title']) ?></div>
                            <?php if ($n['message']): ?>
                            <div class="notif-item-msg"><?= htmlspecialchars($n['message']) ?></div>
                            <?php endif; ?>
                            <div class="notif-item-time"><?= timeAgo($n['created_at']) ?></div>
                        </div>
                        <button class="notif-dismiss-btn" onclick="event.stopPropagation(); dismissNotification(<?= (int)$n['notification_id'] ?>)" title="Dismiss">✕</button>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
        <div class="profile-dropdown-container">
            <button class="profile-button" onclick="toggleProfileDropdown()" aria-label="Profile menu">
                <?php if ($userProfileImage && file_exists($userProfileImage)): ?>
                    <img src="<?= htmlspecialchars($userProfileImage) ?>?v=<?= time() ?>" alt="Avatar" style="width:32px;height:32px;border-radius:8px;object-fit:cover;flex-shrink:0;">
                <?php else: ?>
                    <div class="profile-avatar"><?= $userInitials ?></div>
                <?php endif; ?>
                <span class="profile-name"><?= htmlspecialchars($userName) ?></span>
                <span class="dropdown-arrow">▼</span>
            </button>
            <div class="profile-dropdown" id="profileDropdown">
                <div class="dropdown-header">
                    <?php if ($userProfileImage && file_exists($userProfileImage)): ?>
                        <img src="<?= htmlspecialchars($userProfileImage) ?>?v=<?= time() ?>" alt="Avatar" style="width:44px;height:44px;border-radius:50%;object-fit:cover;">
                    <?php else: ?>
                        <div class="dropdown-avatar"><?= $userInitials ?></div>
                    <?php endif; ?>
                    <div class="dropdown-user-info">
                        <div class="dropdown-user-name"><?= htmlspecialchars($userName) ?></div>
                        <div class="dropdown-user-email"><?= htmlspecialchars($userEmail) ?></div>
                    </div>
                </div>
                <div class="dropdown-menu">
                    <a href="student-profile.php" class="dropdown-item">
                        <span class="dropdown-item-icon">👤</span><span>My Profile</span>
                    </a>
                    <button onclick="openReportModal(); closeProfileDropdown();" class="dropdown-item" style="background:none;border:none;width:100%;text-align:left;cursor:pointer;">
                        <span class="dropdown-item-icon">🚩</span><span>Help &amp; Support</span>
                    </button>
                    <div class="dropdown-divider"></div>
                    <button onclick="handleLogout()" class="dropdown-item logout">
                        <span class="dropdown-item-icon">🚪</span><span>Logout</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</nav>

<div class="dropdown-overlay" id="dropdownOverlay" onclick="closeAllDropdowns()"></div>

<div class="page-wrapper">
    <aside class="left-sidebar">
        <span class="left-sidebar-label">Navigation</span>
        <a href="student-dashboard.php"><i class="fa fa-home"></i> Dashboard</a>
        <a href="student-assessments.php" class="active"><i class="fa fa-clipboard-list"></i> Assessments</a>
        <a href="student-resources.php"><i class="fa fa-folder-open"></i> Resources</a>

        <?php if (!empty($allAttempts)): ?>
        <span class="attempt-history-label"><i class="fa fa-history" style="margin-right:6px"></i>Attempt History</span>
        <?php foreach ($allAttempts as $att):
            $isActive = (int)$att['attempt_id'] === $attemptId;
            $pct      = round((float)$att['percentage'], 1);
            $isFail   = $pct < 50;
            $dateStr  = $att['submitted_at'] ? date('d M Y, h:i A', strtotime($att['submitted_at'])) : 'In progress';
        ?>
        <button class="attempt-card <?= $isActive ? 'active' : '' ?>"
                onclick="window.location.href='test-results.php?attempt_id=<?= $att['attempt_id'] ?>'">
            <div class="ac-top">
                <span class="ac-num"><i class="fa fa-file-alt" style="margin-right:5px;font-size:11px"></i>Attempt <?= $att['attempt_number'] ?></span>
                <span class="ac-pct <?= $isFail ? 'fail' : '' ?>"><?= $pct ?>%</span>
            </div>
            <div class="ac-date"><?= $dateStr ?></div>
            <div class="ac-bar"><div class="ac-bar-fill" style="width:<?= $pct ?>%"></div></div>
        </button>
        <?php endforeach; endif; ?>

        <div class="left-sidebar-bottom">
            <button onclick="handleLogout()"><i class="fa fa-sign-out-alt"></i> Logout</button>
        </div>
    </aside>
    <div class="page-content">

<div style="padding:0;max-width:100%;">
    <div class="loading-state" id="loadingState">
        <div class="loading-spinner"></div>
        <p style="color:var(--text-soft)">Loading your results…</p>
    </div>

    <div class="error-state" id="errorState" style="display:none">
        <div style="font-size:48px;margin-bottom:15px">⚠️</div>
        <h2>Could Not Load Results</h2>
        <p id="errorMsg">Something went wrong while fetching your results.</p>
        <a href="student-dashboard.php" class="btn-back-link">← Back to Dashboard</a>
    </div>

    <div id="resultsContent" style="display:none">

        <!-- Results Header -->
        <div class="results-header">
            <h1 class="test-title" id="testTitle">Loading…</h1>
            <p class="test-date" id="testDate"></p>

            <div class="score-display">
                <div class="score-circle" id="scoreCircle" style="--score-pct: 0%">
                    <div class="score-content">
                        <div class="score-value" id="scoreValue">0</div>
                        <div class="score-denom" id="scoreDenom">out of 0</div>
                        <div class="score-pct-text" id="scorePctText">0%</div>
                    </div>
                </div>
            </div>

            <div class="performance-badge" id="performanceBadge"></div>

            <div class="quick-stats">
                <div class="stat-item">
                    <div class="stat-value" id="statCorrect">—</div>
                    <div class="stat-label">Correct</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value" id="statIncorrect">—</div>
                    <div class="stat-label">Incorrect</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value" id="statUnanswered">—</div>
                    <div class="stat-label">Skipped</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value" id="statPercentile">—</div>
                    <div class="stat-label">Percentile</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value" id="statTime">—</div>
                    <div class="stat-label">Time Taken</div>
                </div>
            </div>
        </div>

        <!-- Analysis Grid -->
        <div class="analysis-grid">
            <div class="analysis-card">
                <h3 class="analysis-title">
                    <div class="analysis-icon icon-correct">✓</div>
                    Accuracy Breakdown
                </h3>
                <div class="breakdown-list" id="accuracyBreakdown"></div>
            </div>
            <div class="analysis-card">
                <h3 class="analysis-title">
                    <div class="analysis-icon icon-chart">📊</div>
                    Topic Performance
                </h3>
                <div class="breakdown-list" id="categoryBreakdown"></div>
            </div>
            <div class="analysis-card">
                <h3 class="analysis-title">
                    <div class="analysis-icon icon-time">⏱️</div>
                    Time Analysis
                </h3>
                <div class="breakdown-list" id="timeBreakdown"></div>
            </div>
        </div>

        <!-- Questions Review -->
        <div class="questions-section">
            <div class="section-header">
                <h2 class="section-title">Answer Review</h2>
                <div class="filter-buttons" id="filterButtons" style="display:none">
                    <button class="filter-btn active" data-filter="all">All Questions</button>
                    <button class="filter-btn" data-filter="correct">✓ Correct</button>
                    <button class="filter-btn" data-filter="incorrect">✗ Incorrect</button>
                    <button class="filter-btn" data-filter="skipped">— Skipped</button>
                </div>
            </div>
            <div class="questions-list" id="questionsList"></div>

            <div class="action-section">
                <button class="action-btn btn-primary" onclick="window.print()">
                    🖨️ Download Results
                </button>
                <a href="student-assessments.php" class="action-btn btn-secondary">
                    📝 Back to Assessments
                </a>
            </div>
        </div>

    </div><!-- /resultsContent -->
</div>

<script>
// ── Navbar ──
function toggleProfileDropdown() {
    const dropdown = document.getElementById('profileDropdown');
    const overlay  = document.getElementById('dropdownOverlay');
    dropdown.classList.toggle('show');
    overlay.classList.toggle('show');
}
function toggleNotifDropdown() {
    const dd      = document.getElementById('notifDropdown');
    const overlay = document.getElementById('dropdownOverlay');
    const isOpen  = dd.classList.contains('show');
    document.getElementById('profileDropdown').classList.remove('show');
    dd.classList.toggle('show', !isOpen);
    overlay.classList.toggle('show', !isOpen);
    if (!isOpen) {
        fetch('api/notifications/mark-read.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': <?= json_encode($_SESSION['csrf_token'] ?? '') ?>, 'Content-Type': 'application/json' }
        }).then(() => {
            const badge = document.querySelector('.notification-badge');
            if (badge) badge.remove();
            document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
            document.querySelectorAll('.notif-dot:not(.read)').forEach(el => el.classList.add('read'));
        }).catch(() => {});
    }
}
function closeAllDropdowns() {
    document.getElementById('profileDropdown').classList.remove('show');
    document.getElementById('notifDropdown').classList.remove('show');
    document.getElementById('dropdownOverlay').classList.remove('show');
}
function handleLogout() {
    if (confirm('Are you sure you want to logout?')) window.location.href = 'logout.php';
}

async function dismissNotification(notifId) {
    const el = document.getElementById('notif-' + notifId);
    if (el) { el.style.opacity = '0.4'; el.style.pointerEvents = 'none'; }
    try {
        const res = await fetch('api/notifications/dismiss-notification.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify({ action: 'dismiss_one', notification_id: notifId })
        });
        const data = await res.json();
        if (!data.success) {
            if (el) { el.style.opacity = '1'; el.style.pointerEvents = ''; }
            return;
        }
    } catch(e) {
        if (el) { el.style.opacity = '1'; el.style.pointerEvents = ''; }
        return;
    }
    if (el) el.remove();
    const badge = document.querySelector('.notification-badge');
    if (badge) {
        const cur = parseInt(badge.textContent) || 0;
        if (cur <= 1) badge.remove();
        else badge.textContent = cur - 1;
    }
    const list = document.querySelector('.notif-list');
    if (list && list.querySelectorAll('.notif-item').length === 0) {
        list.innerHTML = '<div class="notif-empty">No notifications yet.</div>';
    }
}

const ATTEMPT_ID = <?= $attemptId ?>;
const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;
const API_URL    = 'api/assessment/get-test-results.php';

document.addEventListener('DOMContentLoaded', loadResults);

async function loadResults() {
    try {
        const res  = await fetch(`${API_URL}?attempt_id=${ATTEMPT_ID}`, {
            headers: { 'X-CSRF-Token': CSRF_TOKEN, 'Accept': 'application/json' }
        });
        const data = await res.json();
        if (!data.success) { showError(data.error || 'Failed to load results.'); return; }
        renderResults(data);
    } catch (err) {
        console.error('loadResults error:', err);
        showError('Network error. Please refresh the page.');
    }
}

function renderResults(d) {
    document.getElementById('testTitle').textContent = d.testName;
    document.getElementById('testDate').textContent  = 'Completed on ' + formatDatetime(d.completedAt);

    const pct = d.percentage;
    document.getElementById('scoreCircle').style.setProperty('--score-pct', pct + '%');
    animateNumber('scoreValue', 0, Math.round(d.score), 1400);
    document.getElementById('scoreDenom').textContent   = `out of ${d.totalMarks}`;
    document.getElementById('scorePctText').textContent = pct.toFixed(1) + '%';

    renderBadge(pct, d.passed, d.passingMarks, d.totalMarks);

    document.getElementById('statCorrect').textContent    = d.correctAnswers;
    document.getElementById('statIncorrect').textContent  = d.wrongAnswers;
    document.getElementById('statUnanswered').textContent = d.unanswered;
    document.getElementById('statPercentile').textContent = ordinal(d.percentile);
    document.getElementById('statTime').textContent       = formatSeconds(d.timeTakenSeconds);

    renderAccuracy(d);
    renderCategories(d.categoryPerformance);
    renderTime(d);

    if (d.showCorrectAnswers && d.questions && d.questions.length) {
        renderQuestions(d.questions, d.correctAnswers, d.wrongAnswers, d.unanswered);
        updateFilterCounts(d.correctAnswers, d.wrongAnswers, d.unanswered);
        document.getElementById('filterButtons').style.display = 'flex';
        setupFilters();
    } else if (!d.showCorrectAnswers) {
        document.getElementById('questionsList').innerHTML = `
            <div class="hidden-results-notice">
                <div class="notice-icon">🔒</div>
                <p>The teacher has not enabled answer review for this assessment.</p>
            </div>`;
    }

    document.getElementById('loadingState').style.display  = 'none';
    document.getElementById('resultsContent').style.display = 'block';
    animateEntrance();
}

function renderBadge(pct, passed, passingMarks, totalMarks) {
    const badge = document.getElementById('performanceBadge');
    let cls, label;
    if (pct >= 90)      { cls = 'badge-excellent'; label = '🏆 Excellent Performance!'; }
    else if (pct >= 75) { cls = 'badge-good';      label = '🎉 Good Performance!'; }
    else if (pct >= 60) { cls = 'badge-average';   label = '👍 Average Performance'; }
    else                { cls = 'badge-poor';       label = '📚 Keep Practicing!'; }
    if (!passed) { cls = 'badge-failed'; label = `❌ Not Passed (Passing: ${passingMarks}/${totalMarks})`; }
    badge.className   = `performance-badge ${cls}`;
    badge.textContent = label;
}

function renderAccuracy(d) {
    const total   = d.totalQuestions || 1;
    const corrPct = ((d.correctAnswers / total) * 100).toFixed(1);
    const wrPct   = ((d.wrongAnswers   / total) * 100).toFixed(1);
    const skipPct = ((d.unanswered     / total) * 100).toFixed(1);
    document.getElementById('accuracyBreakdown').innerHTML = `
        <div class="breakdown-item"><div class="breakdown-label">Correct Answers</div><div class="breakdown-value">${d.correctAnswers} / ${total}</div></div>
        <div class="progress-bar"><div class="progress-fill fill-correct" style="width:${corrPct}%"></div></div>
        <div class="breakdown-item"><div class="breakdown-label">Incorrect Answers</div><div class="breakdown-value">${d.wrongAnswers} / ${total}</div></div>
        <div class="progress-bar"><div class="progress-fill fill-incorrect" style="width:${wrPct}%"></div></div>
        <div class="breakdown-item"><div class="breakdown-label">Skipped</div><div class="breakdown-value">${d.unanswered} / ${total}</div></div>
        <div class="progress-bar"><div class="progress-fill" style="width:${skipPct}%;background:var(--text-soft)"></div></div>
        <div class="breakdown-item"><div class="breakdown-label">Passing Marks</div><div class="breakdown-value">${d.passingMarks} / ${d.totalMarks}</div></div>`;
}

function renderCategories(cats) {
    if (!cats || cats.length === 0) {
        document.getElementById('categoryBreakdown').innerHTML = '<p style="color:var(--text-soft);font-size:13.5px">No topic data available.</p>';
        return;
    }
    document.getElementById('categoryBreakdown').innerHTML = cats.map(c => {
        const pct = c.total > 0 ? ((c.correct / c.total) * 100).toFixed(0) : 0;
        return `<div class="breakdown-item"><div class="breakdown-label">${escHtml(c.topic)}</div><div class="breakdown-value">${c.correct}/${c.total}</div></div>
                <div class="progress-bar"><div class="progress-fill fill-topic" style="width:${pct}%"></div></div>`;
    }).join('');
}

function renderTime(d) {
    const avgSec = d.totalQuestions > 0 ? Math.round(d.timeTakenSeconds / d.totalQuestions) : 0;
    document.getElementById('timeBreakdown').innerHTML = `
        <div class="breakdown-item"><div class="breakdown-label">Total Time Taken</div><div class="breakdown-value">${formatSeconds(d.timeTakenSeconds)}</div></div>
        <div class="breakdown-item"><div class="breakdown-label">Avg per Question</div><div class="breakdown-value">${formatSeconds(avgSec)}</div></div>
        <div class="breakdown-item"><div class="breakdown-label">Time Remaining</div><div class="breakdown-value">${formatSeconds(d.timeRemainingSeconds)}</div></div>
        <div class="breakdown-item"><div class="breakdown-label">Duration Allowed</div><div class="breakdown-value">${d.durationMinutes} min</div></div>`;
}

function renderQuestions(questions) {
    const fragment = document.createDocumentFragment();
    questions.forEach(q => fragment.appendChild(buildQuestionCard(q)));
    const list = document.getElementById('questionsList');
    list.innerHTML = '';
    list.appendChild(fragment);
}

function buildQuestionCard(q) {
    const skipped   = !q.userAnswer;
    const statusCls = skipped ? 'skipped' : (q.isCorrect ? 'correct' : 'incorrect');

    const card = document.createElement('div');
    card.className = `question-card ${statusCls}`;
    card.dataset.status = statusCls;
    card.dataset.text   = (q.questionText || '').toLowerCase();

    let badgeHtml;
    if (skipped)          badgeHtml = '<span class="result-badge skipped">— Skipped</span>';
    else if (q.isCorrect) badgeHtml = '<span class="result-badge correct">✓ Correct</span>';
    else                  badgeHtml = '<span class="result-badge incorrect">✗ Incorrect</span>';

    const marksClass = q.marksObtained > 0 ? 'gained' : (q.marksObtained < 0 ? 'lost' : '');
    const marksLabel = q.marksObtained > 0
        ? `+${q.marksObtained} marks`
        : (q.marksObtained < 0 ? `${q.marksObtained} marks (negative)` : '0 marks');

    let optionsHtml = '';
    for (const [label, text] of Object.entries(q.options)) {
        const isCorrect  = label === q.correctAnswer;
        const isSelected = label === q.userAnswer;
        let cls = '';
        if (isCorrect)               cls += ' correct-highlight';
        if (isSelected && isCorrect)  cls += ' user-selected';
        if (isSelected && !isCorrect) cls += ' wrong-highlight user-selected';
        let badges = '';
        if (isCorrect)                    badges += '<span class="option-badge badge-correct">Correct Answer</span>';
        if (isSelected && !q.isCorrect)   badges += '<span class="option-badge badge-yours">Your Answer</span>';
        if (isSelected && q.isCorrect && q.userAnswer) badges += '<span class="option-badge badge-correct">Your Answer ✓</span>';
        optionsHtml += `<div class="answer-option${cls}"><span class="option-label">${escHtml(label)})</span><span class="option-text">${escHtml(text)}</span>${badges}</div>`;
    }

    const explHtml = q.explanation
        ? `<div class="explanation-box"><strong>💡 Explanation:</strong>${escHtml(q.explanation)}</div>` : '';
    const timeStr  = q.timeTakenSeconds ? ` · ${formatSeconds(q.timeTakenSeconds)}` : '';
    const topicStr = q.topic ? ` · ${escHtml(q.topic)}` : '';

    card.innerHTML = `
        <div class="question-header-row">
            <div>
                <div class="question-number">Question ${q.questionNumber}</div>
                <div class="question-meta">${q.marks} mark${q.marks !== 1 ? 's' : ''}${q.negativeMarks > 0 ? ` · -${q.negativeMarks} neg` : ''}${topicStr}${timeStr}</div>
            </div>
            ${badgeHtml}
        </div>
        <p class="question-text">${escHtml(q.text)}</p>
        <div class="answer-options">${optionsHtml}</div>
        ${explHtml}
        <div class="marks-info ${marksClass}">${marksLabel}</div>`;
    return card;
}

function updateFilterCounts(correct, wrong, skipped) {
    document.querySelectorAll('.filter-btn').forEach(btn => {
        const f = btn.dataset.filter;
        if (f === 'correct')   btn.textContent = `✓ Correct (${correct})`;
        if (f === 'incorrect') btn.textContent = `✗ Incorrect (${wrong})`;
        if (f === 'skipped')   btn.textContent = `— Skipped (${skipped})`;
    });
}

function setupFilters() {
    document.getElementById('filterButtons').addEventListener('click', e => {
        const btn = e.target.closest('.filter-btn');
        if (!btn) return;
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        const filter = btn.dataset.filter;
        const search = (document.getElementById('searchInput')?.value || '').toLowerCase().trim();
        document.querySelectorAll('.question-card').forEach(card => {
            const statusMatch = filter === 'all' || card.dataset.status === filter;
            const textMatch   = !search || (card.dataset.text || '').includes(search);
            card.classList.toggle('hidden', !statusMatch || !textMatch);
        });
    });

    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            const search = this.value.toLowerCase().trim();
            const activeFilter = (document.querySelector('.filter-btn.active')?.dataset.filter) || 'all';
            document.querySelectorAll('.question-card').forEach(card => {
                const statusMatch = activeFilter === 'all' || card.dataset.status === activeFilter;
                const textMatch   = !search || (card.dataset.text || '').includes(search);
                card.classList.toggle('hidden', !statusMatch || !textMatch);
            });
            const questionsList = document.getElementById('questionsList');
            if (questionsList) {
                const anyVisible = [...questionsList.querySelectorAll('.question-card')].some(c => !c.classList.contains('hidden'));
                let hint = questionsList.querySelector('.search-no-results');
                if (!anyVisible && search) {
                    if (!hint) {
                        hint = document.createElement('div');
                        hint.className = 'search-no-results';
                        hint.style.cssText = 'text-align:center;padding:30px;color:var(--text-soft);font-size:14.5px;';
                        hint.textContent = 'No questions match your search.';
                        questionsList.appendChild(hint);
                    }
                } else if (hint) { hint.remove(); }
            }
        });
    }
}

function animateEntrance() {
    document.querySelectorAll('.stat-item').forEach((el, i) => {
        setTimeout(() => el.style.animation = 'fadeInUp .5s ease forwards', i * 80);
    });
    document.querySelectorAll('.analysis-card').forEach((el, i) => {
        setTimeout(() => el.style.animation = 'fadeInUp .5s ease forwards', 300 + i * 100);
    });
}

function showError(msg) {
    document.getElementById('loadingState').style.display = 'none';
    document.getElementById('errorState').style.display   = 'block';
    document.getElementById('errorMsg').textContent = msg;
}

function animateNumber(id, from, to, duration) {
    const el = document.getElementById(id);
    if (!el) return;
    const start = performance.now();
    (function tick(now) {
        const p   = Math.min((now - start) / duration, 1);
        const val = Math.floor(from + (to - from) * (p * (2 - p)));
        el.textContent = val;
        if (p < 1) requestAnimationFrame(tick);
        else el.textContent = to;
    })(start);
}

function formatSeconds(sec) {
    if (!sec || sec < 0) return '0s';
    const m = Math.floor(sec / 60), s = sec % 60;
    return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

function formatDatetime(dt) {
    if (!dt) return '';
    const d = new Date(dt.replace(' ', 'T'));
    return d.toLocaleDateString('en-IN', { day:'numeric', month:'long', year:'numeric' }) +
           ' at ' + d.toLocaleTimeString('en-IN', { hour:'2-digit', minute:'2-digit' });
}

function ordinal(n) {
    if (!n && n !== 0) return '—';
    const s = ['th','st','nd','rd'], v = n % 100;
    return n + (s[(v-20)%10] || s[v] || s[0]);
}

function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

document.addEventListener('keydown', e => {
    if ((e.key === 'p' || e.key === 'P') && !e.ctrlKey) { e.preventDefault(); window.print(); }
    if (e.key === 'd' || e.key === 'D') window.location.href = 'student-dashboard.php';
});
</script>
    </div><!-- /.page-content -->
</div><!-- /.page-wrapper -->

<!-- ══ REPORT MODAL ══ -->
<div class="report-modal-overlay" id="reportModalOverlay" onclick="if(event.target===this)closeReportModal()">
    <div class="report-modal">
        <div class="report-modal-header">
            <div>
                <div class="report-modal-title">🚩 Report an Issue</div>
                <div class="report-modal-sub">We'll review your report and get back to you</div>
            </div>
            <button class="report-modal-close" onclick="closeReportModal()">✕</button>
        </div>
        <?php if (!empty($_GET['report']) && $_GET['report'] === 'sent'): ?>
        <div style="margin:16px 24px 0;padding:12px 16px;border-radius:10px;background:#d1fae5;border:1px solid #a7f3d0;font-size:13px;font-weight:600;color:#065f46;display:flex;align-items:center;gap:8px;">✅ Your report was submitted! We'll look into it soon.</div>
        <?php endif; ?>
        <?php if ($latestReportStatus): ?>
        <div style="margin:12px 24px 0;padding:12px 16px;border-radius:10px;background:<?= $hasOpenReport ? '#fff8ed' : '#d1fae5' ?>;border:1px solid <?= $hasOpenReport ? '#f59e0b' : '#a7f3d0' ?>;font-size:13px;font-weight:600;color:<?= $hasOpenReport ? '#92400e' : '#065f46' ?>;display:flex;align-items:center;gap:8px;">
            <?= $hasOpenReport ? '⏳ Your last report is <strong>'.ucfirst(str_replace('_',' ',$latestReportStatus)).'</strong> — admin will respond soon.' : '✅ Your last report has been <strong>Resolved</strong>.' ?>
        </div>
        <?php endif; ?>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="submit_report">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <div class="report-modal-body">
                <div class="report-field">
                    <label>Report Title <span>*</span></label>
                    <input type="text" name="report_title" placeholder="e.g. Assessment not loading" required maxlength="150">
                </div>
                <div class="report-field">
                    <label>Explanation <span>*</span></label>
                    <textarea name="report_description" rows="4" placeholder="Describe the issue in detail..." required maxlength="2000"></textarea>
                </div>
                <div class="report-field">
                    <label>Screenshot / Image <span style="color:#94a3b8;font-weight:500;">(optional)</span></label>
                    <label for="reportImageInput" class="report-drop-zone" id="reportDropZone">
                        <div class="dz-icon">📷</div>
                        <div class="dz-text">Click to upload or drag & drop</div>
                        <div class="dz-sub">JPG, PNG, GIF, WEBP — max 5 MB</div>
                    </label>
                    <input type="file" name="report_image" id="reportImageInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none" onchange="previewReportImg(this)">
                    <img id="reportImgPreview" class="report-img-preview" alt="Preview">
                </div>
            </div>
            <div class="report-modal-footer">
                <button type="button" class="btn-report-cancel" onclick="closeReportModal()">Cancel</button>
                <button type="submit" class="btn-report-submit">🚩 Submit Report</button>
            </div>
        </form>
    </div>
</div>
<script>
function openReportModal(){
    document.getElementById('reportModalOverlay').classList.add('open');
    document.addEventListener('keydown', escReport);
}
function closeReportModal(){
    document.getElementById('reportModalOverlay').classList.remove('open');
    document.removeEventListener('keydown', escReport);
}
function escReport(e){ if(e.key==='Escape') closeReportModal(); }
function previewReportImg(input){
    const file = input.files[0]; if(!file) return;
    if(file.size > 5*1024*1024){ alert('Image must be under 5MB.'); input.value=''; return; }
    const reader = new FileReader();
    reader.onload = e => {
        const p = document.getElementById('reportImgPreview');
        p.src = e.target.result; p.style.display = 'block';
        document.querySelector('#reportDropZone .dz-text').textContent = file.name;
        document.querySelector('#reportDropZone .dz-sub').textContent = (file.size/1024).toFixed(1)+' KB';
    };
    reader.readAsDataURL(file);
}
const rdz = document.getElementById('reportDropZone');
if(rdz){
    rdz.addEventListener('dragover', e=>{ e.preventDefault(); rdz.classList.add('dragover'); });
    rdz.addEventListener('dragleave', ()=> rdz.classList.remove('dragover'));
    rdz.addEventListener('drop', e=>{
        e.preventDefault(); rdz.classList.remove('dragover');
        const file = e.dataTransfer.files[0];
        if(file && file.type.startsWith('image/')){
            const input = document.getElementById('reportImageInput');
            try{ const dt=new DataTransfer(); dt.items.add(file); input.files=dt.files; }catch(ex){}
            previewReportImg(input);
        }
    });
}
<?php if(!empty($_GET['report']) && $_GET['report']==='sent'): ?>
window.addEventListener('load', ()=> openReportModal());
<?php endif; ?>
</script>
</body>
</html>
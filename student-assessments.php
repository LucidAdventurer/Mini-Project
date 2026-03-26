<?php
/* ========================================
 * STUDENT ASSESSMENTS PAGE
 * File: student-assessments.php
 *
 * Shows all assessments assigned to the student by their teacher(s).
 * Covers: direct user assignment, department-based assignment, and
 * teacher_groups / group_members assignment (the newer path).
 * ======================================== */

require_once "config.php";
require_once "db-guard.php";

$user         = validateSession($conn, 'student');
$userName     = $user['full_name'] ?? 'Student';
$userEmail    = $user['email']     ?? '';
$userDept     = $user['department'] ?? null;
$userInitials = strtoupper(substr($userName, 0, 2));
$userId       = (int) $user['user_id'];

// Fetch fresh profile image
$imgRes = safePreparedQuery($conn, "SELECT profile_image FROM users WHERE user_id = ?", "i", [$userId]);
$userProfileImage = '';
if ($imgRes['success'] && $imgRes['result']) {
    $imgRow = $imgRes['result']->fetch_assoc();
    $userProfileImage = $imgRow['profile_image'] ?? '';
    $imgRes['result']->free();
}


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
// ── Unread notification count ──
$notifResult = safePreparedQuery($conn,
    "SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0",
    "i", [$userId]
);
$unreadCount = 0;
if ($notifResult['success'] && $notifResult['result']) {
    $r = $notifResult['result']->fetch_assoc();
    $unreadCount = (int)($r['cnt'] ?? 0);
    $notifResult['result']->free();
}

// ── All notifications for dropdown (scroll, latest first) ──
$notifDropResult = safePreparedQuery($conn,
    "SELECT notification_id, title, message, is_read, created_at, type, related_entity_id
     FROM notifications WHERE user_id = ?
     ORDER BY created_at DESC",
    "i", [$userId]
);
$notifItems = [];
if ($notifDropResult['success'] && $notifDropResult['result']) {
    while ($row = $notifDropResult['result']->fetch_assoc()) $notifItems[] = $row;
    $notifDropResult['result']->free();
}

// ── Active filter from URL ──
$activeFilter = trim($_GET['filter'] ?? 'all');
$allowedFilters = ['all', 'pending', 'completed', 'available', 'expired'];
if (!in_array($activeFilter, $allowedFilters, true)) $activeFilter = 'all';

$activeCategory = trim($_GET['category'] ?? 'all');
$allowedCategories = ['all', 'aptitude', 'technical', 'coding', 'reasoning', 'english', 'general'];
if (!in_array($activeCategory, $allowedCategories, true)) $activeCategory = 'all';

// ── Fetch all published assessments available to this student ──
$assignedResult = safePreparedQuery($conn,
    "SELECT DISTINCT
        a.assessment_id,
        a.title,
        a.description,
        a.category,
        a.difficulty,
        a.duration_minutes,
        a.total_marks,
        a.passing_marks,
        a.max_attempts,
        a.start_time,
        a.end_time,
        a.created_at,
        u.full_name AS teacher_name,
        (SELECT COUNT(*) FROM questions q WHERE q.assessment_id = a.assessment_id) AS question_count,
        (SELECT COUNT(*) FROM assessment_attempts aa
          WHERE aa.assessment_id = a.assessment_id
            AND aa.user_id = ?
            AND aa.status  = 'submitted') AS attempts_used,
        (SELECT aa2.attempt_id FROM assessment_attempts aa2
          WHERE aa2.assessment_id = a.assessment_id
            AND aa2.user_id = ?
            AND aa2.status  = 'submitted'
          ORDER BY aa2.submitted_at DESC LIMIT 1) AS last_attempt_id,
        (SELECT aa3.percentage FROM assessment_attempts aa3
          WHERE aa3.assessment_id = a.assessment_id
            AND aa3.user_id = ?
            AND aa3.status  = 'submitted'
          ORDER BY aa3.submitted_at DESC LIMIT 1) AS last_score,
        (SELECT aa4.submitted_at FROM assessment_attempts aa4
          WHERE aa4.assessment_id = a.assessment_id
            AND aa4.user_id = ?
            AND aa4.status  = 'submitted'
          ORDER BY aa4.submitted_at DESC LIMIT 1) AS last_submitted_at,
        (SELECT aa5.attempt_id FROM assessment_attempts aa5
          WHERE aa5.assessment_id = a.assessment_id
            AND aa5.user_id = ?
            AND aa5.status  = 'in_progress'
          ORDER BY aa5.created_at DESC LIMIT 1) AS in_progress_attempt_id
     FROM assessments a
     JOIN users u ON u.user_id = a.created_by
     WHERE a.status = 'published'
       AND (a.start_time IS NULL OR a.start_time <= NOW())
       AND (a.end_time   IS NULL OR a.end_time   >= NOW())
       AND (
           a.visibility = 'public'
           OR EXISTS (
               SELECT 1 FROM assessment_targets at2
               WHERE at2.assessment_id = a.assessment_id
                 AND at2.target_type = 'student'
                 AND at2.target_id = ?
           )
           OR EXISTS (
               SELECT 1 FROM assessment_targets at2
               JOIN group_members gm ON gm.group_id = at2.target_id
               WHERE at2.assessment_id = a.assessment_id
                 AND at2.target_type = 'group'
                 AND gm.student_id = ?
           )
       )
     ORDER BY a.start_time IS NULL ASC, a.start_time DESC, a.created_at DESC",
    "iiiiiii",
    [$userId, $userId, $userId, $userId, $userId, $userId, $userId]
);

$assessments     = [];
$assessmentError = false;

if ($assignedResult['success'] && $assignedResult['result']) {
    while ($row = $assignedResult['result']->fetch_assoc()) {
        $assessments[] = $row;
    }
    $assignedResult['result']->free();
} elseif (!$assignedResult['success']) {
    $assessmentError = true;
}

// ── Summary counts ──
$totalAssigned = count($assessments);
$totalPending  = 0;
$totalDone     = 0;
$totalExpired  = 0;

foreach ($assessments as $a) {
    $isExpired   = !empty($a['end_time']) && strtotime($a['end_time']) < time();
    $isExhausted = ((int)$a['attempts_used'] >= (int)$a['max_attempts']);
    if ((int)$a['attempts_used'] > 0) $totalDone++;
    if ($isExpired && (int)$a['attempts_used'] === 0) $totalExpired++;
    if (!$isExpired && !$isExhausted && (int)$a['attempts_used'] === 0) $totalPending++;
}

/* Helper: human-readable time-ago */
function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff / 60)   . ' min ago';
    if ($diff < 86400)  return floor($diff / 3600)  . ' hr ago';
    if ($diff < 604800) return floor($diff / 86400) . ' day ago';
    return date('d M Y', strtotime($datetime));
}
function timeAgoFull(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff / 60)   . ' min ago';
    if ($diff < 86400)  return floor($diff / 3600)  . ' hr ago';
    if ($diff < 604800) return floor($diff / 86400) . ' day ago';
    return date('d M Y, g:i A', strtotime($datetime));
}

/* Helper: deadline label */
function deadlineLabel(?string $until): string {
    if (empty($until)) return '';
    $ts   = strtotime($until);
    $diff = $ts - time();
    if ($diff < 0)       return 'Expired';
    if ($diff < 3600)    return 'Due in ' . floor($diff / 60) . ' min';
    if ($diff < 86400)   return 'Due in ' . floor($diff / 3600) . ' hr';
    if ($diff < 604800)  return 'Due in ' . floor($diff / 86400) . ' days';
    return 'Due ' . date('d M Y', $ts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Assessments - PTA Platform</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
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
            --sidebar-w:     230px;
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
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            flex-shrink: 0;
        }

        .nav-center {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0 28px;
            max-width: 680px;
        }

        .nav-search {
            flex: 1;
            position: relative;
        }

        .nav-search input {
            width: 100%;
            padding: 10px 18px 10px 42px;
            border: 1.5px solid rgba(255,255,255,.15);
            border-radius: 10px;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            background: rgba(255,255,255,.1);
            color: white;
            outline: none;
            transition: var(--transition);
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

        .nav-date-box {
            display: flex; align-items: center; gap: 6px;
            background: rgba(255,255,255,.1);
            border: 1.5px solid rgba(255,255,255,.15);
            border-radius: 10px; padding: 8px 12px;
            flex-shrink: 0; transition: var(--transition);
        }
        .nav-date-box:focus-within {
            background: rgba(255,255,255,.18);
            border-color: rgba(255,255,255,.35);
            box-shadow: 0 0 0 3px rgba(14,165,233,.25);
        }
        .nav-date-label { font-size: 10px; font-weight: 700; color: rgba(255,255,255,.5); text-transform: uppercase; letter-spacing: .06em; }
        .nav-date-box input[type="date"] {
            border: none; background: transparent;
            font-family: 'Inter', sans-serif; font-size: 13px; color: white;
            outline: none; cursor: pointer; width: 118px;
            color-scheme: dark;
        }
        .nav-date-box input[type="date"]::-webkit-calendar-picker-indicator { opacity: 0.5; cursor: pointer; filter: invert(1); }

        .nav-profile { display: flex; align-items: center; gap: 10px; }

        .notification-icon {
            position: relative;
            width: 38px; height: 38px;
            background: rgba(255,255,255,.12);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; border: 1.5px solid rgba(255,255,255,.15);
            transition: var(--transition); color: white;
        }
        .notification-icon:hover { background: rgba(255,255,255,.2); border-color: rgba(255,255,255,.3); }

        /* Notification dropdown */
        .notif-dropdown-wrap { position: relative; overflow: visible; }
        .notif-dropdown {
            position: absolute;
            top: calc(100% + 12px); right: 0;
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border);
            width: 348px;
            opacity: 0; visibility: hidden; transform: translateY(-6px) scale(.98);
            transition: var(--transition);
            z-index: 1002;
        }
        .notif-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
        .notif-dropdown-header {
            padding: 16px 20px 14px;
            font-family: 'Sora', sans-serif;
            font-weight: 700; font-size: 14px; color: var(--text);
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
        }
        .notif-list {
            max-height: 360px; overflow-y: auto;
            scrollbar-width: thin; scrollbar-color: var(--border) transparent;
        }
        .notif-list::-webkit-scrollbar { width: 4px; }
        .notif-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 10px; }
        .notif-item {
            display: flex; gap: 12px; align-items: flex-start;
            padding: 13px 20px;
            border-bottom: 1px solid var(--border);
            cursor: pointer; transition: background var(--transition);
        }
        .notif-item:last-child { border-bottom: none; }
        .notif-item:hover { background: var(--surface2); }
        .notif-item.unread { background: #eff8ff; }
        .notif-item.unread:hover { background: #e0f2fe; }
        .notif-dot {
            width: 7px; height: 7px; border-radius: 50%;
            background: var(--accent); flex-shrink: 0; margin-top: 5px;
        }
        .notif-dot.read { background: transparent; }
        .notif-item-body { flex: 1; }
        .notif-item-title { font-size: 13px; font-weight: 600; color: var(--text); margin-bottom: 2px; }
        .notif-item-msg { font-size: 12px; color: var(--text-mid); line-height: 1.45; }
        .notif-dismiss-btn {
            background: none; border: none;
            color: var(--text-soft); font-size: 13px; line-height: 1;
            padding: 2px 5px; border-radius: 4px; cursor: pointer;
            flex-shrink: 0; opacity: 0;
            transition: opacity .15s, background .15s, color .15s;
            align-self: flex-start; margin-top: 2px;
        }
        .notif-item:hover .notif-dismiss-btn { opacity: 1; }
        .notif-dismiss-btn:hover { background: rgba(239,68,68,.1); color: #ef4444; }
        .notif-item-time { font-size: 11px; color: var(--text-soft); margin-top: 4px; }
        .notif-empty { padding: 32px 20px; text-align: center; color: var(--text-soft); font-size: 13px; }

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
            border-radius: 10px;
            cursor: pointer; transition: var(--transition);
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
            min-width: 240px;
            opacity: 0; visibility: hidden; transform: translateY(-6px) scale(.98);
            transition: var(--transition); z-index: 1001; overflow: hidden;
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

        .dropdown-overlay {
            position: fixed; inset: 0;
            background: transparent; z-index: 999; display: none;
        }
        .dropdown-overlay.show { display: block; }

        /* ══════════════════════════════
           PAGE LAYOUT
        ══════════════════════════════ */
        .page-wrapper { display: flex; min-height: calc(100vh - var(--nav-h)); }

        .left-sidebar {
            width: var(--sidebar-w);
            flex-shrink: 0;
            padding: 20px 12px;
            display: flex; flex-direction: column; gap: 2px;
            background: var(--surface);
            border-right: 1px solid var(--border);
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
            color: var(--text-mid); transition: var(--transition);
            position: relative;
        }
        .left-sidebar a:hover { background: var(--surface2); color: var(--primary); }
        .left-sidebar a.active {
            background: linear-gradient(135deg, #e0f2fe, #e0f9ff);
            color: var(--accent); font-weight: 600;
        }
        .left-sidebar a.active::before {
            content: '';
            position: absolute; left: 0; top: 20%; bottom: 20%;
            width: 3px; border-radius: 0 3px 3px 0;
            background: var(--accent);
        }
        .left-sidebar a i { width: 18px; text-align: center; font-size: 14px; flex-shrink: 0; }
        .left-sidebar-section {
            font-size: 10.5px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .1em;
            color: var(--text-soft); padding: 14px 12px 7px;
        }
        .left-sidebar-bottom {
            margin-top: auto; padding-top: 12px;
            border-top: 1px solid var(--border);
        }
        .left-sidebar-bottom a {
            color: var(--danger) !important;
        }
        .left-sidebar-bottom a:hover { background: #fef2f2 !important; }

        .page-content { flex: 1; min-width: 0; padding: 28px; }

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
           SUMMARY CARDS
        ══════════════════════════════ */
        .summary-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        .summary-card {
            background: var(--surface); border-radius: var(--radius);
            padding: 20px 22px; box-shadow: var(--shadow);
            border: 2px solid var(--border);
            display: flex; align-items: center; gap: 14px;
            cursor: pointer; transition: var(--transition);
        }
        .summary-card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); border-color: var(--accent); }
        .summary-card.active-filter { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow), var(--shadow); }
        .summary-icon {
            width: 48px; height: 48px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; flex-shrink: 0;
        }
        .summary-icon.blue  { background: #e0f2fe; }
        .summary-icon.green { background: #d1fae5; }
        .summary-icon.amber { background: #fef3c7; }
        .summary-icon.red   { background: #fee2e2; }
        .summary-number { font-family: 'Sora', sans-serif; font-size: 26px; font-weight: 800; color: var(--text); line-height: 1; }
        .summary-label  { font-size: 12.5px; color: var(--text-soft); margin-top: 3px; }

        /* ══════════════════════════════
           ASSESSMENT CARDS
        ══════════════════════════════ */
        .assessments-grid { display: flex; flex-direction: column; gap: 14px; }

        .assessment-card {
            background: var(--surface); border-radius: var(--radius);
            padding: 22px 24px; border: 1.5px solid var(--border);
            display: flex; flex-direction: column; gap: 14px;
            transition: var(--transition);
            box-shadow: var(--shadow);
        }
        .assessment-card:hover { border-color: var(--accent); box-shadow: 0 4px 20px rgba(14,165,233,.12), 0 0 0 1px rgba(14,165,233,.1); transform: translateY(-1px); }
        .assessment-card.exhausted { opacity: 0.72; }
        .assessment-card.expired   { opacity: 0.65; }
        .assessment-card.in-progress { border-color: var(--warning); box-shadow: 0 0 0 2px rgba(245,158,11,.12); }

        /* Card header */
        .card-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
        .card-title-group { flex: 1; }
        .card-title { font-family: 'Sora', sans-serif; font-size: 16px; font-weight: 700; color: var(--text); margin-bottom: 4px; line-height: 1.3; }
        .card-teacher { font-size: 12.5px; color: var(--text-soft); }
        .card-badges { display: flex; gap: 6px; flex-shrink: 0; flex-wrap: wrap; justify-content: flex-end; }

        /* Badges */
        .badge {
            padding: 4px 11px; border-radius: 6px; font-size: 11.5px; font-weight: 700;
            text-transform: capitalize; white-space: nowrap;
            font-family: 'Sora', sans-serif; letter-spacing: .02em;
        }
        .badge-easy   { background: #dcfce7; color: #166534; }
        .badge-medium { background: #fef3c7; color: #92400e; }
        .badge-hard   { background: #fee2e2; color: #991b1b; }
        .badge-category { background: #e0f2fe; color: #075985; }
        .badge-status-pending   { background: #e0f2fe; color: #075985; }
        .badge-status-done      { background: #d1fae5; color: #065f46; }
        .badge-status-progress  { background: #fef3c7; color: #92400e; }
        .badge-status-expired   { background: #f1f5f9; color: var(--text-mid); }
        .badge-status-exhausted { background: #f1f5f9; color: var(--text-soft); }

        /* Meta row */
        .card-meta { display: flex; flex-wrap: wrap; gap: 18px; }
        .meta-item { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--text-mid); }

        /* Deadline strip */
        .deadline-strip {
            display: inline-flex; align-items: center; gap: 7px;
            font-size: 11.5px; font-weight: 600;
            padding: 4px 11px; border-radius: 20px;
        }
        .deadline-strip.urgent  { background: #fee2e2; color: #991b1b; }
        .deadline-strip.soon    { background: #fef3c7; color: #92400e; }
        .deadline-strip.normal  { background: #d1fae5; color: #065f46; }
        .deadline-strip.expired { background: #f1f5f9; color: var(--text-soft); }

        /* Score bar */
        .score-section { display: flex; flex-direction: column; gap: 7px; }
        .score-label { display: flex; justify-content: space-between; font-size: 13px; }
        .score-label span:first-child { color: var(--text-mid); }
        .score-label span:last-child { font-weight: 700; color: var(--text); }
        .score-bar { height: 8px; background: var(--border); border-radius: 10px; overflow: hidden; }
        .score-bar-fill { height: 100%; border-radius: 10px; transition: width .6s cubic-bezier(.4,0,.2,1); }
        .score-bar-fill.pass  { background: linear-gradient(90deg, var(--success), #34d399); }
        .score-bar-fill.fail  { background: linear-gradient(90deg, var(--danger), #f87171); }
        .score-bar-fill.empty { background: var(--border); }

        /* Attempts indicator */
        .attempts-row { display: flex; align-items: center; justify-content: space-between; }
        .attempts-dots { display: flex; gap: 5px; }
        .attempt-dot {
            width: 10px; height: 10px; border-radius: 50%;
            border: 2px solid var(--border); background: transparent; transition: var(--transition);
        }
        .attempt-dot.used { background: var(--accent); border-color: var(--accent); }
        .attempts-text { font-size: 12px; color: var(--text-soft); }

        /* Action buttons */
        .card-actions { display: flex; gap: 10px; }
        .btn-start {
            flex: 1; padding: 10px 0; border-radius: var(--radius-sm); border: none;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            color: white; font-weight: 700; font-size: 13.5px; cursor: pointer;
            transition: var(--transition); font-family: 'Inter', sans-serif;
            display: flex; align-items: center; justify-content: center; gap: 6px;
            box-shadow: 0 2px 8px rgba(14,165,233,.3);
        }
        .btn-start:hover { transform: translateY(-2px); box-shadow: 0 4px 14px rgba(14,165,233,.45); }
        .btn-resume {
            flex: 1; padding: 10px 0; border-radius: var(--radius-sm); border: none;
            background: linear-gradient(135deg, var(--warning), #fbbf24);
            color: white; font-weight: 700; font-size: 13.5px; cursor: pointer;
            transition: var(--transition); font-family: 'Inter', sans-serif;
            display: flex; align-items: center; justify-content: center; gap: 6px;
            box-shadow: 0 2px 8px rgba(245,158,11,.3);
        }
        .btn-resume:hover { transform: translateY(-2px); box-shadow: 0 4px 14px rgba(245,158,11,.45); }
        .btn-results {
            flex: 1; padding: 10px 0; border-radius: var(--radius-sm);
            border: 1.5px solid var(--accent); background: var(--surface);
            color: var(--accent); font-weight: 700; font-size: 13.5px; cursor: pointer;
            transition: var(--transition); font-family: 'Inter', sans-serif;
            display: flex; align-items: center; justify-content: center; gap: 6px;
        }
        .btn-results:hover { background: var(--accent); color: white; box-shadow: 0 4px 12px rgba(14,165,233,.3); }
        .btn-disabled {
            flex: 1; padding: 10px 0; border-radius: var(--radius-sm);
            border: 1.5px solid var(--border); background: var(--surface2);
            color: var(--text-soft); font-weight: 600; font-size: 13.5px;
            cursor: not-allowed; font-family: 'Inter', sans-serif;
            display: flex; align-items: center; justify-content: center; gap: 6px;
        }

        /* State messages */
        .state-message {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 12px; padding: 64px 20px; border-radius: var(--radius); text-align: center;
        }
        .state-message .state-icon { font-size: 44px; }
        .state-message h3 { font-family: 'Sora', sans-serif; font-size: 18px; font-weight: 700; color: var(--text); }
        .state-message p  { font-size: 13.5px; color: var(--text-mid); }
        .state-empty { background: var(--surface); box-shadow: var(--shadow); border: 1px solid var(--border); }
        .state-error { background: #fef2f2; border: 1px solid #fecaca; }
        .state-error h3, .state-error p { color: #991b1b; }

        /* Hidden utility */
        .hidden { display: none !important; }

        /* ── RESPONSIVE ── */
        @media (max-width: 1100px) { .summary-row { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 900px)  { .left-sidebar { display: none; } .page-content { padding: 20px; } }
        @media (max-width: 768px)  {
            .navbar { padding: 0 16px; }
            .nav-center { display: none; }
            .profile-name { display: none; }
            .page-content { padding: 16px; }
        }

        /* Page load animation */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .page-header   { animation: fadeUp .4s ease both; }
        .summary-row   { animation: fadeUp .4s .08s ease both; }
        .assessments-grid { animation: fadeUp .4s .15s ease both; }
    
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

<!-- ── NAVBAR ── -->
<nav class="navbar">
    <a href="student-dashboard.php" class="navbar-brand">
        <img src="prepaura-logo.png" alt="Prepaura Logo" style="width:44px;height:44px;border-radius:10px;object-fit:contain;background:white;padding:3px;">
        <div style="display:flex;flex-direction:column;line-height:1.15;">
            <span style="font-family:'Sora',sans-serif;font-size:17px;font-weight:800;letter-spacing:.5px;color:white;">PREPAURA</span>
            <span style="font-size:10.5px;font-weight:400;color:rgba(255,255,255,.65);letter-spacing:.02em;">Placement Training Platform</span>
        </div>
    </a>
    <div class="nav-center">
        <div class="nav-search">
            <i class="fa fa-search sicon"></i>
            <input type="text" id="navSearchInput" placeholder="Search by title or teacher..." autocomplete="off">
        </div>
        <div class="nav-date-box">
            <i class="fa fa-calendar-days" style="color:rgba(255,255,255,.5);font-size:13px"></i>
            <span class="nav-date-label">From</span>
            <input type="date" id="dateFrom">
        </div>
        <div class="nav-date-box">
            <i class="fa fa-calendar-days" style="color:rgba(255,255,255,.5);font-size:13px"></i>
            <span class="nav-date-label">To</span>
            <input type="date" id="dateTo">
        </div>
    </div>
    <div class="nav-profile">
        <!-- Notifications -->
        <div class="notif-dropdown-wrap">
            <button class="notification-icon" onclick="toggleNotifDropdown()" id="notifBtn">
                <span>🔔</span>
                <?php if ($unreadCount > 0): ?>
                <div class="notification-badge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></div>
                <?php endif ?>
            </button>
            <div class="notif-dropdown" id="notifDropdown">
                <div class="notif-dropdown-header">Notifications</div>
                <div class="notif-list">
                    <?php if (empty($notifItems)): ?>
                        <div class="notif-empty">No notifications yet.</div>
                    <?php else: foreach ($notifItems as $n):
                        $isUnread = !$n['is_read'];
                        $icon = '🔔';
                        $entityId  = (int)($n['related_entity_id'] ?? 0);
                        $nType     = $n['type'] ?? '';
                        $hasLink   = in_array($nType, ['assessment', 'material', 'result']) && $entityId > 0;
                        $redirectUrl = $hasLink
                            ? 'api/notifications/notification-redirect.php?notification_id=' . $n['notification_id']
                            : '';
                    ?>
                    <div class="notif-item <?= $isUnread ? 'unread' : '' ?>" id="notif-<?= $n['notification_id'] ?>"
                         <?php if ($redirectUrl): ?>
                         onclick="handleNotifClick(<?= $n['notification_id'] ?>, '<?= $redirectUrl ?>')"
                         style="cursor:pointer;"
                         <?php endif; ?>>
                        <div class="notif-dot <?= $isUnread ? '' : 'read' ?>"></div>
                        <div class="notif-item-body">
                            <div class="notif-item-title"><?= $icon ?> <?= htmlspecialchars($n['title']) ?></div>
                            <?php if ($n['message']): ?>
                            <div class="notif-item-msg"><?= htmlspecialchars($n['message']) ?></div>
                            <?php endif; ?>
                            <div class="notif-item-time"><?= timeAgo($n['created_at']) ?></div>
                        </div>
                        <button class="notif-dismiss-btn" title="Dismiss"
                            onclick="event.stopPropagation(); dismissNotification(<?= $n['notification_id'] ?>)"
                            aria-label="Dismiss notification">✕</button>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
        <!-- Profile -->
        <div class="profile-dropdown-container">
            <button class="profile-button" onclick="toggleProfileDropdown()">
                <?php if ($userProfileImage && file_exists($userProfileImage)): ?>
                    <img src="<?= htmlspecialchars($userProfileImage) ?>?v=<?= time() ?>" alt="Avatar" style="width:32px;height:32px;border-radius:8px;object-fit:cover;flex-shrink:0;">
                <?php else: ?>
                    <div class="profile-avatar"><?= htmlspecialchars($userInitials) ?></div>
                <?php endif; ?>
                <span class="profile-name"><?= htmlspecialchars($userName) ?></span>
                <span class="dropdown-arrow">▼</span>
            </button>
            <div class="profile-dropdown" id="profileDropdown">
                <div class="dropdown-header">
                    <div class="dropdown-avatar">
                        <?php if ($userProfileImage && file_exists($userProfileImage)): ?>
                            <img src="<?= htmlspecialchars($userProfileImage) ?>?v=<?= time() ?>" alt="Avatar" style="width:44px;height:44px;border-radius:12px;object-fit:cover;">
                        <?php else: ?>
                            <?= htmlspecialchars($userInitials) ?>
                        <?php endif; ?>
                    </div>
                    <div class="dropdown-user-info">
                        <div class="dropdown-user-name"><?= htmlspecialchars($userName) ?></div>
                        <div class="dropdown-user-email"><?= htmlspecialchars($userEmail) ?></div>
                    </div>
                </div>
                <div class="dropdown-menu">
                    <a href="student-profile.php" class="dropdown-item">
                        <span class="dropdown-item-icon">👤</span><span>My Profile</span>
                    </a>
                    <button onclick="openReportModal(); closeProfileDropdown();" class="dropdown-item" style="background:none;border:none;width:100%;text-align:left;cursor:pointer;display:flex;align-items:center;gap:11px;padding:10px 12px;border-radius:8px;font-size:13.5px;color:#475569;font-family:'Inter',sans-serif;transition:.15s;">
                        <span class="dropdown-item-icon">🚩</span><span>Help &amp; Support</span>
                    </button>
                    <div class="dropdown-divider"></div>
                    <button class="dropdown-item logout" onclick="handleLogout()">
                        <span class="dropdown-item-icon">🚪</span><span>Logout</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</nav>
<div class="dropdown-overlay" id="dropdownOverlay" onclick="closeAllDropdowns()"></div>

<!-- ── PAGE WRAPPER ── -->
<div class="page-wrapper">

    <!-- Left sidebar -->
    <aside class="left-sidebar">
        <span class="left-sidebar-label">Navigation</span>
        <a href="student-dashboard.php"><i class="fa fa-home"></i> Dashboard</a>
        <a href="student-assessments.php" class="active"><i class="fa fa-clipboard-list"></i> Assessments</a>
        <a href="student-resources.php"><i class="fa fa-folder-open"></i> Resources</a>

        <span class="left-sidebar-section">Filter by Category</span>
        <a href="#" id="cat-all"       onclick="setSidebarCat('all',this);return false;"><i class="fa fa-layer-group"></i> All Tests</a>
        <a href="#" id="cat-aptitude"  onclick="setSidebarCat('aptitude',this);return false;"><i class="fa fa-calculator" style="color:#0ea5e9"></i> Aptitude</a>
        <a href="#" id="cat-technical" onclick="setSidebarCat('technical',this);return false;"><i class="fa fa-microchip" style="color:#8b5cf6"></i> Technical</a>
        <a href="#" id="cat-coding"    onclick="setSidebarCat('coding',this);return false;"><i class="fa fa-code" style="color:#10b981"></i> Coding</a>
        <a href="#" id="cat-reasoning" onclick="setSidebarCat('reasoning',this);return false;"><i class="fa fa-brain" style="color:#f59e0b"></i> Reasoning</a>
        <a href="#" id="cat-english"   onclick="setSidebarCat('english',this);return false;"><i class="fa fa-book" style="color:#ef4444"></i> English</a>
        <a href="#" id="cat-general"   onclick="setSidebarCat('general',this);return false;"><i class="fa fa-globe" style="color:#06b6d4"></i> General</a>

        <div class="left-sidebar-bottom">
            <a href="logout.php"><i class="fa fa-sign-out-alt"></i> Logout</a>
        </div>
    </aside>

    <div class="page-content">

        <?php if (!empty($_GET['notif_stale'])): ?>
        <!-- Stale notification toast -->
        <div id="staleToast" style="
            display:flex;align-items:center;gap:12px;
            background:#fff8ed;border:1.5px solid #f59e0b;
            border-radius:12px;padding:14px 18px;margin-bottom:20px;
            box-shadow:0 2px 12px rgba(245,158,11,.15);
            animation:fadeUp .3s ease both;">
            <span style="font-size:20px;flex-shrink:0;">⚠️</span>
            <div style="flex:1;">
                <div style="font-weight:700;font-size:13.5px;color:#92400e;">This item is no longer available</div>
                <div style="font-size:12.5px;color:#b45309;margin-top:2px;">The test or resource linked to that notification was removed or has expired.</div>
            </div>
            <button onclick="document.getElementById('staleToast').remove()" style="
                background:none;border:none;cursor:pointer;
                color:#b45309;font-size:18px;padding:2px 6px;
                border-radius:6px;line-height:1;
                transition:.15s;">✕</button>
        </div>
        <script>setTimeout(()=>{ const t=document.getElementById('staleToast'); if(t){t.style.transition='opacity .4s';t.style.opacity='0';setTimeout(()=>t.remove(),400);} }, 5000);</script>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-left">
                <h1>📋 My Assessments</h1>
                <p>Tests and assessments assigned to you by your teacher</p>
            </div>
            <div class="page-header-right">
                <strong><?= $totalAssigned ?></strong>
                Total Assigned
            </div>
        </div>

        <!-- Summary cards -->
        <div class="summary-row">
            <div class="summary-card <?= $activeFilter === 'all' ? 'active-filter' : '' ?>" onclick="setFilter('all')">
                <div class="summary-icon blue">📋</div>
                <div>
                    <div class="summary-number"><?= $totalAssigned ?></div>
                    <div class="summary-label">Total Assigned</div>
                </div>
            </div>
            <div class="summary-card <?= $activeFilter === 'pending' ? 'active-filter' : '' ?>" onclick="setFilter('pending')">
                <div class="summary-icon amber">⏳</div>
                <div>
                    <div class="summary-number"><?= $totalPending ?></div>
                    <div class="summary-label">Pending</div>
                </div>
            </div>
            <div class="summary-card <?= $activeFilter === 'completed' ? 'active-filter' : '' ?>" onclick="setFilter('completed')">
                <div class="summary-icon green">✅</div>
                <div>
                    <div class="summary-number"><?= $totalDone ?></div>
                    <div class="summary-label">Completed</div>
                </div>
            </div>
            <div class="summary-card <?= $activeFilter === 'expired' ? 'active-filter' : '' ?>" onclick="setFilter('expired')">
                <div class="summary-icon red">❌</div>
                <div>
                    <div class="summary-number"><?= $totalExpired ?></div>
                    <div class="summary-label">Expired / Missed</div>
                </div>
            </div>
        </div>

        <!-- hidden — kept for JS sync with sidebar category filter -->
        <select id="categorySelect" style="display:none">
            <option value="all">All Categories</option>
            <option value="aptitude"  <?= $activeCategory === 'aptitude'  ? 'selected' : '' ?>>Aptitude</option>
            <option value="technical" <?= $activeCategory === 'technical' ? 'selected' : '' ?>>Technical</option>
            <option value="coding"    <?= $activeCategory === 'coding'    ? 'selected' : '' ?>>Coding</option>
            <option value="reasoning" <?= $activeCategory === 'reasoning' ? 'selected' : '' ?>>Reasoning</option>
            <option value="english"   <?= $activeCategory === 'english'   ? 'selected' : '' ?>>English</option>
            <option value="general"   <?= $activeCategory === 'general'   ? 'selected' : '' ?>>General</option>
        </select>

        <!-- Assessment Cards -->
        <div class="assessments-grid" id="assessmentsGrid">

            <?php if ($assessmentError): ?>
            <div class="state-message state-error">
                <div class="state-icon">⚠️</div>
                <h3>Failed to load assessments</h3>
                <p>There was a problem fetching your assigned assessments. Please refresh the page.</p>
            </div>

            <?php elseif (empty($assessments)): ?>
            <div class="state-message state-empty">
                <div class="state-icon">📭</div>
                <h3>No assessments assigned yet</h3>
                <p>Your teacher hasn't assigned any assessments to you yet. Check back later.</p>
            </div>

            <?php else: foreach ($assessments as $a):
                $id           = (int) $a['assessment_id'];
                $attemptsUsed = (int) $a['attempts_used'];
                $maxAttempts  = (int) $a['max_attempts'];
                $attemptsLeft = $maxAttempts - $attemptsUsed;
                $isExhausted  = $attemptsUsed >= $maxAttempts;
                $isExpired    = !empty($a['end_time']) && strtotime($a['end_time']) < time();
                $isAvailable  = !empty($a['start_time'])  && strtotime($a['start_time'])  > time();
                $inProgress   = !empty($a['in_progress_attempt_id']);
                $lastScore    = $a['last_score'] !== null ? round((float)$a['last_score']) : null;
                $passing      = (int)$a['passing_marks'];
                $total        = (int)$a['total_marks'];
                $passPct      = $total > 0 ? round(($passing / $total) * 100) : 0;

                // Derive status
                if ($inProgress) {
                    $statusLabel = 'In Progress';
                    $statusClass = 'badge-status-progress';
                    $cardClass   = 'in-progress';
                } elseif ($isExpired && $attemptsUsed === 0) {
                    $statusLabel = 'Expired';
                    $statusClass = 'badge-status-expired';
                    $cardClass   = 'expired';
                } elseif ($isExhausted) {
                    $statusLabel = 'Completed';
                    $statusClass = 'badge-status-done';
                    $cardClass   = 'exhausted';
                } elseif ($attemptsUsed > 0) {
                    $statusLabel = 'Attempted';
                    $statusClass = 'badge-status-done';
                    $cardClass   = '';
                } elseif ($isAvailable) {
                    $statusLabel = 'Scheduled';
                    $statusClass = 'badge-status-expired';
                    $cardClass   = '';
                } else {
                    $statusLabel = 'Pending';
                    $statusClass = 'badge-status-pending';
                    $cardClass   = '';
                }

                // Deadline display
                $dLabel = deadlineLabel($a['end_time'] ?? null);
                $dClass = '';
                if ($dLabel === 'Expired') $dClass = 'expired';
                elseif (str_contains($dLabel, 'min') || str_contains($dLabel, 'hr')) $dClass = 'urgent';
                elseif (str_contains($dLabel, ' 1 ') || str_contains($dLabel, ' 2 ') || str_contains($dLabel, ' 3 ')) $dClass = 'soon';
                else $dClass = 'normal';

                // Data attributes for JS filtering
                $jsCategory  = htmlspecialchars($a['category'] ?? '');
                $jsStatus    = $inProgress ? 'progress' : ($isExpired && $attemptsUsed === 0 ? 'expired' : ($attemptsUsed > 0 ? 'completed' : 'pending'));
                $jsTitle     = htmlspecialchars(strtolower($a['title']));
                $jsTeacher   = htmlspecialchars(strtolower($a['teacher_name'] ?? ''));
                $jsScore     = $lastScore ?? -1;
                $jsDeadline  = !empty($a['end_time']) ? strtotime($a['end_time']) : 9999999999;
                $jsCreated   = !empty($a['created_at']) ? strtotime($a['created_at']) : 0;
            ?>
            <div class="assessment-card <?= $cardClass ?>"
                 data-category="<?= $jsCategory ?>"
                 data-difficulty="<?= htmlspecialchars($a['difficulty'] ?? '') ?>"
                 data-status="<?= $jsStatus ?>"
                 data-title="<?= $jsTitle ?>"
                 data-teacher="<?= $jsTeacher ?>"
                 data-score="<?= $jsScore ?>"
                 data-deadline="<?= $jsDeadline ?>"
                 data-created="<?= $jsCreated ?>">

                <!-- Header: title + badges -->
                <div class="card-header">
                    <div class="card-title-group">
                        <div class="card-title"><?= htmlspecialchars($a['title']) ?></div>
                        <div class="card-teacher"><?= htmlspecialchars($a['teacher_name'] ?? 'Teacher') ?></div>
                    </div>
                    <div class="card-badges">
                        <span class="badge badge-<?= htmlspecialchars($a['difficulty'] ?? 'medium') ?>">
                            <?= htmlspecialchars(ucfirst($a['difficulty'] ?? 'medium')) ?>
                        </span>
                        <span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                        <?php if ($dLabel): ?>
                        <span class="deadline-strip <?= $dClass ?>">⏰ <?= $dLabel ?></span>
                        <?php endif ?>
                    </div>
                </div>

                <!-- Meta: questions, duration, marks, attempts -->
                <div class="card-meta">
                    <div class="meta-item"><span>❓</span><?= (int)$a['question_count'] ?> Questions</div>
                    <div class="meta-item"><span>⏱️</span><?= (int)$a['duration_minutes'] ?> Minutes</div>
                    <div class="meta-item"><span>🏆</span><?= (int)$a['total_marks'] ?> Points</div>
                    <div class="meta-item"><span>🔄</span><?= $attemptsLeft > 0 ? $attemptsLeft . ' attempt(s) left' : 'No attempts left' ?></div>
                </div>

                <!-- Score bar (only when attempted) -->
                <?php if ($lastScore !== null): ?>
                <div class="score-section">
                    <div class="score-label">
                        <span>Latest Score</span>
                        <span><?= $lastScore ?>% <?= $lastScore >= $passPct ? '✅ Passed' : '❌ Failed' ?></span>
                    </div>
                    <div class="score-bar">
                        <div class="score-bar-fill <?= $lastScore >= $passPct ? 'pass' : 'fail' ?>"
                             style="width:<?= min(100, $lastScore) ?>%"></div>
                    </div>
                    <?php if (!empty($a['last_submitted_at'])): ?>
                    <div style="font-size:11.5px;color:var(--text-soft);margin-top:2px">
                        Submitted <?= timeAgo($a['last_submitted_at']) ?>
                    </div>
                    <?php endif ?>
                </div>
                <?php endif ?>

                <!-- Action buttons -->
                <div class="card-actions">
                    <?php if ($inProgress): ?>
                        <button class="btn-resume" onclick="startAssessment(<?= $id ?>)">
                            ▶ Resume Test
                        </button>
                        <?php if (!empty($a['last_attempt_id'])): ?>
                        <button class="btn-results" onclick="viewResults(<?= (int)$a['last_attempt_id'] ?>)">View Results</button>
                        <?php endif ?>

                    <?php elseif ($isExpired && $attemptsUsed === 0): ?>
                        <button class="btn-disabled" disabled>Deadline Passed</button>

                    <?php elseif ($isAvailable): ?>
                        <button class="btn-disabled" disabled>
                            🔒 Opens <?= date('d M', strtotime($a['start_time'])) ?>
                        </button>

                    <?php elseif (!$isExhausted): ?>
                        <button class="btn-start" onclick="startAssessment(<?= $id ?>)">
                            ▶ <?= $attemptsUsed > 0 ? 'Retry Test' : 'Start Test' ?>
                        </button>
                        <?php if ($attemptsUsed > 0 && !empty($a['last_attempt_id'])): ?>
                        <button class="btn-results" onclick="viewResults(<?= (int)$a['last_attempt_id'] ?>)">View Results</button>
                        <?php endif ?>

                    <?php else: ?>
                        <?php if (!empty($a['last_attempt_id'])): ?>
                        <button class="btn-results" onclick="viewResults(<?= (int)$a['last_attempt_id'] ?>)">
                            View Results
                        </button>
                        <?php else: ?>
                        <button class="btn-disabled" disabled>No Attempts Remaining</button>
                        <?php endif ?>
                    <?php endif ?>
                </div>

            </div>
            <?php endforeach; endif ?>

            <!-- Empty state shown by JS when all cards are filtered out -->
            <div class="state-message state-empty hidden" id="noResultsState" <?= $assessmentError ? 'style="display:none"' : '' ?>>
                <div class="state-icon">🔍</div>
                <h3>No assessments found</h3>
                <p>Try adjusting your search or filter.</p>
            </div>

        </div><!-- /.assessments-grid -->

    </div><!-- /.page-content -->
</div><!-- /.page-wrapper -->

<script>
    const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;

    // Smart notification cleanup on page load
    fetch('api/notifications/cleanup-notifications.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
    }).catch(() => {});

    // ── Category filter (sidebar) ──
    const sidebarCatIds = ['cat-all','cat-aptitude','cat-technical','cat-coding','cat-reasoning','cat-english','cat-general'];
    function setSidebarCat(cat, el) {
        currentCategory = cat;
        sidebarCatIds.forEach(id => document.getElementById(id)?.classList.remove('active'));
        el.classList.add('active');
        const sel = document.getElementById('categorySelect');
        if (sel) sel.value = cat;
        applyFilters();
    }
    document.getElementById('cat-all').classList.add('active');

    // ── Navigation ──
    function startAssessment(id) {
        window.location.href = 'test-preview.php?id=' + id;
    }
    function viewResults(attemptId) {
        window.location.href = 'test-results.php?attempt_id=' + attemptId;
    }
    function handleLogout() {
        if (confirm('Are you sure you want to logout?')) window.location.href = 'logout.php';
    }

    // ── Dropdowns ──
    function toggleProfileDropdown() {
        document.getElementById('profileDropdown').classList.toggle('show');
        document.getElementById('dropdownOverlay').classList.toggle('show');
        document.getElementById('notifDropdown').classList.remove('show');
    }
    function toggleNotifDropdown() {
        const dd   = document.getElementById('notifDropdown');
        const overlay = document.getElementById('dropdownOverlay');
        const isOpen = dd.classList.contains('show');
        document.getElementById('profileDropdown').classList.remove('show');
        dd.classList.toggle('show', !isOpen);
        overlay.classList.toggle('show', !isOpen);
        if (!isOpen) {
            fetch('api/notifications/mark-read.php', {
                method: 'POST',
                headers: { 'X-CSRF-Token': CSRF_TOKEN, 'Content-Type': 'application/json' }
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

    // ── Summary card filter ──
    let currentFilter   = '<?= $activeFilter ?>';
    let currentCategory = 'all';
    let currentSearch   = '';
    let currentSort     = 'deadline';

    function setFilter(f) {
        currentFilter = f;
        document.querySelectorAll('.summary-card').forEach((c, i) => {
            const filters = ['all', 'pending', 'completed', 'expired'];
            c.classList.toggle('active-filter', filters[i] === f);
        });
        applyFilters();
    }

    // ── Category select ──
    document.getElementById('categorySelect').addEventListener('change', function() {
        currentCategory = this.value;
        sidebarCatIds.forEach(id => document.getElementById(id)?.classList.remove('active'));
        const activeId = 'cat-' + (this.value === 'all' ? 'all' : this.value);
        document.getElementById(activeId)?.classList.add('active');
        applyFilters();
    });

    // ── Search ──
    document.getElementById('navSearchInput').addEventListener('input', e => {
        currentSearch = e.target.value.toLowerCase();
        applyFilters();
    });

    // ── Date range filter ──
    let dateFrom = '', dateTo = '';
    document.getElementById('dateFrom').addEventListener('change', e => { dateFrom = e.target.value; applyFilters(); });
    document.getElementById('dateTo').addEventListener('change',   e => { dateTo   = e.target.value; applyFilters(); });

    // ── Core filter + sort ──
    function applyFilters() {
        const grid  = document.getElementById('assessmentsGrid');
        const cards = [...grid.querySelectorAll('.assessment-card')];
        const noRes = document.getElementById('noResultsState');
        let visible = 0;

        cards.forEach(card => {
            const cat    = card.dataset.category;
            const status = card.dataset.status;
            const title  = card.dataset.title;
            const teacher = card.dataset.teacher;

            const matchCat    = currentCategory === 'all' || cat === currentCategory;
            const matchSearch = !currentSearch || title.includes(currentSearch) || teacher.includes(currentSearch);
            const cardDeadline = card.dataset.deadline > 0 ? new Date(card.dataset.deadline * 1000).toISOString().slice(0,10) : '';
            const matchFrom   = !dateFrom || !cardDeadline || cardDeadline >= dateFrom;
            const matchTo     = !dateTo   || !cardDeadline || cardDeadline <= dateTo;

            let matchFilter = true;
            if (currentFilter === 'pending')   matchFilter = status === 'pending';
            if (currentFilter === 'completed') matchFilter = status === 'completed' || status === 'progress';
            if (currentFilter === 'available') matchFilter = status === 'pending';
            if (currentFilter === 'expired')   matchFilter = status === 'expired';

            const show = matchCat && matchSearch && matchFilter && matchFrom && matchTo;
            card.classList.toggle('hidden', !show);
            if (show) visible++;
        });

        noRes.classList.toggle('hidden', visible > 0);

        // Sort visible cards
        const visibleCards = cards.filter(c => !c.classList.contains('hidden'));
        visibleCards.sort((a, b) => {
            if (currentSort === 'deadline') return a.dataset.deadline - b.dataset.deadline;
            if (currentSort === 'newest')   return b.dataset.created - a.dataset.created;
            if (currentSort === 'title')    return a.dataset.title.localeCompare(b.dataset.title);
            if (currentSort === 'score')    return b.dataset.score - a.dataset.score;
            return 0;
        });
        visibleCards.forEach(c => grid.appendChild(c));
    }

    // Animate score bars
    window.addEventListener('load', () => {
        document.querySelectorAll('.score-bar-fill').forEach(bar => {
            const w = bar.style.width;
            bar.style.width = '0';
            setTimeout(() => { bar.style.width = w; }, 120);
        });
        // Apply default category from URL if set
        const cat = '<?= $activeCategory ?>';
        if (cat !== 'all') {
            currentCategory = cat;
            document.getElementById('categorySelect').value = cat;
            applyFilters();
        }
    });

    // ── Live notification sync (badge + DOM) ──
    let lastUnreadCount = <?= $unreadCount ?>;
    let lastPollTime    = 0; // 0 so first poll fires immediately on load

    function updateNotifBadge(count) {
        let badge = document.querySelector('.notification-badge');
        if (count > 0) {
            if (!badge) {
                badge = document.createElement('div');
                badge.className = 'notification-badge';
                document.querySelector('#notifBtn').appendChild(badge);
            }
            badge.textContent = count > 99 ? '99+' : count;
        } else {
            if (badge) badge.remove();
        }
    }

    function syncNotifications() {
        if (document.hidden) return;
        if (Date.now() - lastPollTime < 30000) return;

        fetch('api/notifications/active-ids.php')
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                lastPollTime = Date.now();

                // ── 1. Update badge ──
                updateNotifBadge(data.unread_count);
                lastUnreadCount = data.unread_count;

                // ── 2. Remove any DOM items not in the active list ──
                const activeSet = new Set(data.ids);
                const list = document.querySelector('.notif-list');
                if (!list) return;

                list.querySelectorAll('.notif-item[id^="notif-"]').forEach(el => {
                    const id = parseInt(el.id.replace('notif-', ''));
                    if (!activeSet.has(id)) {
                        // Fade out then remove
                        el.style.transition = 'opacity .25s, max-height .3s, padding .3s';
                        el.style.overflow   = 'hidden';
                        el.style.maxHeight  = el.offsetHeight + 'px';
                        el.style.opacity    = '0';
                        requestAnimationFrame(() => {
                            el.style.maxHeight = '0';
                            el.style.padding   = '0';
                            el.style.borderWidth = '0';
                        });
                        setTimeout(() => el.remove(), 320);
                    }
                });

                // ── 3. Show empty state if all items removed ──
                setTimeout(() => {
                    if (list && list.querySelectorAll('.notif-item').length === 0
                        && !list.querySelector('.notif-empty')) {
                        list.innerHTML = '<div class="notif-empty">No notifications yet.</div>';
                    }
                }, 350);
            }).catch(() => {});
    }

    // Run immediately on load, then every 30s
    window.addEventListener('load', syncNotifications);
    setInterval(syncNotifications, 30000);

    // Dismiss a single notification by ID (X button)
    async function dismissNotification(notifId) {
        const el = document.getElementById('notif-' + notifId);
        if (el) { el.style.opacity = '0.4'; el.style.pointerEvents = 'none'; }
        try {
            await fetch('api/notifications/dismiss-notification.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'dismiss_one', notification_id: notifId })
            });
        } catch(e) {}
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

    // Dismiss notification then navigate via server-side redirect
    // The redirect API validates entity still exists before sending user there
    function handleNotifClick(notifId, redirectUrl) {
        // Remove from DOM immediately for snappy UX
        const el = document.getElementById('notif-' + notifId);
        if (el) { el.style.opacity = '0.5'; el.style.pointerEvents = 'none'; }
        window.location.href = redirectUrl;
    }
</script>

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
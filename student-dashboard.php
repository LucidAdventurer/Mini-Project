<?php
/* ========================================
 * STUDENT DASHBOARD
 * ======================================== */

require_once "config.php";
require_once "db-guard.php";

$user         = validateSession($conn, 'student');
$userName     = $user['full_name'] ?? 'Student';
$userEmail    = $user['email']     ?? '';
$userDept     = $user['department'] ?? null;
$userInitials = strtoupper(substr($userName, 0, 2));
$userId       = (int) $user['user_id'];

// ── Student statistics ──
$statsResult = safePreparedQuery($conn,
    "SELECT
        COUNT(DISTINCT attempt_id)   AS tests_completed,
        COALESCE(AVG(percentage), 0) AS avg_score
     FROM assessment_attempts
     WHERE user_id = ? AND status = 'completed'",
    "i", [$userId]
);

$testsCompleted = 0;
$avgScore       = 0;

if ($statsResult['success'] && $statsResult['result']) {
    $stats          = $statsResult['result']->fetch_assoc();
    $testsCompleted = (int)   ($stats['tests_completed'] ?? 0);
    $avgScore       = round((float)($stats['avg_score']      ?? 0));
    $statsResult['result']->free();
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
// Accessible = public OR explicitly allowed for this user/department
$availCountResult = safePreparedQuery($conn,
    "SELECT COUNT(DISTINCT a.assessment_id) AS cnt
     FROM assessments a
     WHERE a.status = 'active'
       AND (a.available_from  IS NULL OR a.available_from  <= NOW())
       AND (a.available_until IS NULL OR a.available_until >= NOW())
       AND (
           a.is_public = 1
           OR EXISTS (
               SELECT 1 FROM assessment_access ac
               WHERE ac.assessment_id = a.assessment_id
                 AND ac.access_type   = 'allow'
                 AND (ac.user_id = ? OR ac.department = ?)
           )
       )",
    "is", [$userId, $userDept]
);

$availableTests = 0;
if ($availCountResult['success'] && $availCountResult['result']) {
    $row            = $availCountResult['result']->fetch_assoc();
    $availableTests = (int)($row['cnt'] ?? 0);
    $availCountResult['result']->free();
}

// ── Fetch assessments for the dashboard list (latest 20) ──
// Excludes assessments the student has already exhausted max_attempts on.
$assessmentsResult = safePreparedQuery($conn,
    "SELECT
        a.assessment_id,
        a.title,
        a.description,
        a.category,
        a.difficulty,
        a.duration_minutes,
        a.total_marks,
        a.passing_marks,
        a.max_attempts,
        a.available_until,
        (SELECT COUNT(*) FROM questions q WHERE q.assessment_id = a.assessment_id) AS question_count,
        (SELECT COUNT(*) FROM assessment_attempts aa
          WHERE aa.assessment_id = a.assessment_id
            AND aa.user_id = ?
            AND aa.status  = 'completed') AS attempts_used,
        (SELECT aa2.attempt_id FROM assessment_attempts aa2
          WHERE aa2.assessment_id = a.assessment_id
            AND aa2.user_id = ?
            AND aa2.status  = 'completed'
          ORDER BY aa2.submitted_at DESC LIMIT 1) AS last_attempt_id
     FROM assessments a
     WHERE a.status = 'active'
       AND (a.available_from  IS NULL OR a.available_from  <= NOW())
       AND (a.available_until IS NULL OR a.available_until >= NOW())
       AND (
           a.is_public = 1
           OR EXISTS (
               SELECT 1 FROM assessment_access ac
               WHERE ac.assessment_id = a.assessment_id
                 AND ac.access_type   = 'allow'
                 AND (ac.user_id = ? OR ac.department = ?)
           )
       )
     ORDER BY a.created_at DESC
     LIMIT 20",
    "iiis", [$userId, $userId, $userId, $userDept]
);

$assessments      = [];
$assessmentError  = false;

if ($assessmentsResult['success'] && $assessmentsResult['result']) {
    while ($row = $assessmentsResult['result']->fetch_assoc()) {
        $assessments[] = $row;
    }
    $assessmentsResult['result']->free();
} elseif (!$assessmentsResult['success']) {
    $assessmentError = true;
}

// ── Recent activity (last 5 completed attempts) ──
$activityResult = safePreparedQuery($conn,
    "SELECT aa.attempt_id, aa.percentage, aa.submitted_at,
            a.title
     FROM assessment_attempts aa
     JOIN assessments a ON a.assessment_id = aa.assessment_id
     WHERE aa.user_id = ? AND aa.status = 'completed'
     ORDER BY aa.submitted_at DESC
     LIMIT 5",
    "i", [$userId]
);

$recentActivity = [];
if ($activityResult['success'] && $activityResult['result']) {
    while ($row = $activityResult['result']->fetch_assoc()) {
        $recentActivity[] = $row;
    }
    $activityResult['result']->free();
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

// ── Overall completion % (attempts used vs available) ──
$completionPct = ($availableTests > 0)
    ? min(100, round(($testsCompleted / $availableTests) * 100))
    : 0;

/* Helper: human-readable time-ago */
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
    <title>Student Dashboard - PTA Platform</title>
    <style>
        :root {
            --primary: #234C6A;
            --primary-dark: #456882;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #D3DAD9 0%, white 100%);
            min-height: 100vh;
            padding-top: 71px;
        }
        .navbar {
            background: var(--primary);
            padding: 12px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            color: white;
            text-decoration: none;
            font-weight: 700;
            font-size: 20px;
        }
        .brand-logo {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 18px;
        }
        .nav-search {
            flex: 1;
            max-width: 500px;
            margin: 0 30px;
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
        .nav-profile {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .notification-icon {
            position: relative;
            width: 40px;
            height: 40px;
            background: #f7fafc;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: none;
            transition: 0.3s;
        }
        .notification-icon:hover { background: #e2e8f0; }
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
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #e53e3e;
            color: white;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            font-size: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            animation: badgePulse 1.8s ease-in-out infinite;
            box-shadow: 0 0 0 0 rgba(229,62,62,0.6);
        }
        @keyframes badgePulse {
            0%   { box-shadow: 0 0 0 0 rgba(229,62,62,0.6); }
            70%  { box-shadow: 0 0 0 7px rgba(229,62,62,0); }
            100% { box-shadow: 0 0 0 0 rgba(229,62,62,0); }
        }
        }
        .profile-dropdown-container { position: relative; }
        .profile-button {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 15px;
            background: #f7fafc;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: 0.3s;
        }
        .profile-button:hover { background: #e2e8f0; }
        .profile-avatar {
            width: 35px;
            height: 35px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 14px;
        }
        .profile-name {
            font-weight: 600;
            font-size: 14px;
            color: #2d3748;
        }
        .dropdown-arrow {
            font-size: 12px;
            color: #718096;
        }
        .profile-dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            min-width: 220px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: 0.3s;
            z-index: 1001;
        }
        .profile-dropdown.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        .dropdown-header {
            padding: 20px;
            border-bottom: 1px solid #e2e8f0;
        }
        .dropdown-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 20px;
        }
        .dropdown-user-info {
            flex: 1;
        }
        .dropdown-user-name {
            font-weight: 700;
            font-size: 16px;
            color: #2d3748;
            margin-bottom: 4px;
        }
        .dropdown-user-email {
            font-size: 13px;
            color: #718096;
        }
        .dropdown-menu { padding: 8px 0; }
        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            color: #2d3748;
            text-decoration: none;
            cursor: pointer;
            border: none;
            background: none;
            width: 100%;
            text-align: left;
            font-size: 14px;
            font-family: inherit;
        }
        .dropdown-item:hover { background: #f7fafc; }
        .dropdown-item-icon {
            font-size: 18px;
            width: 20px;
            text-align: center;
        }
        .dropdown-divider {
            height: 1px;
            background: #e2e8f0;
            margin: 8px 0;
        }
        .dropdown-item.logout { color: #f56565; }
        .dropdown-item.logout:hover { background: #fff5f5; }
        .dropdown-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: transparent;
            z-index: 999;
            display: none;
        }
        .dropdown-overlay.show { display: block; }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px;
        }

        /* ── LEFT NAV SIDEBAR ── */
        .page-wrapper {
            display: flex;
            min-height: calc(100vh - 70px);
        }
        .left-sidebar {
            width: 220px;
            flex-shrink: 0;
            padding: 24px 12px;
            display: flex;
            flex-direction: column;
            gap: 2px;
            background: transparent;
            min-height: calc(100vh - 71px);
            position: sticky;
            top: 71px;
            align-self: flex-start;
        }
        .left-sidebar-label {
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .08em;
            color: #718096; padding: 14px 12px 6px;
        }
        .left-sidebar a {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; border-radius: 10px;
            text-decoration: none; font-size: 14px; font-weight: 500;
            color: #4a5568; transition: background .15s, color .15s;
        }
        .left-sidebar a:hover { background: rgba(35,76,106,.08); color: var(--primary); }
        .left-sidebar a.active { background: rgba(35,76,106,.12); color: var(--primary); font-weight: 600; }
        .left-sidebar a i { width: 18px; text-align: center; font-size: 15px; }
        .left-sidebar-bottom {
            margin-top: auto;
            padding-top: 12px;
            border-top: 1px solid rgba(35,76,106,.12);
        }
        .left-sidebar-bottom button {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; border-radius: 10px;
            font-size: 14px; font-weight: 500;
            color: #e53e3e; background: none; border: none;
            cursor: pointer; width: 100%;
            transition: background .15s, color .15s;
        }
        .left-sidebar-bottom button:hover { background: rgba(229,62,62,.08); }
        .left-sidebar-bottom button i { width: 18px; text-align: center; font-size: 15px; }
        .page-content { flex: 1; min-width: 0; padding: 30px 30px 30px 0; }

        @media (max-width: 900px) { .left-sidebar { display: none; } .page-content { padding: 30px; } }
        .welcome-section {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .welcome-content h1 {
            font-size: 32px;
            color: #2d3748;
            margin-bottom: 8px;
        }
        .welcome-content p {
            font-size: 16px;
            color: #718096;
        }
        .quick-stats {
            display: flex;
            gap: 30px;
        }
        .stat-item { text-align: center; }
        .stat-number {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            display: block;
        }
        .stat-label {
            font-size: 13px;
            color: #718096;
            margin-top: 5px;
        }
        .main-content {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
        }
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .section-title {
            font-size: 22px;
            font-weight: 700;
            color: #2d3748;
        }
        .view-all-link {
            color: #4facfe;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
        }
        .view-all-link:hover { color: #00f2fe; }
        .assessments-section {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        .filter-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .filter-tab {
            padding: 8px 18px;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            color: #718096;
            transition: 0.3s;
        }
        .filter-tab.active {
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            color: white;
            border-color: transparent;
        }
        .filter-tab:hover:not(.active) { background: #e2e8f0; }
        .assessment-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        .assessment-card {
            background: #f7fafc;
            border-radius: 15px;
            padding: 20px;
            border: 2px solid transparent;
            transition: 0.3s;
        }
        .assessment-card.hidden { display: none; }
        .assessment-card:hover {
            border-color: var(--primary);
            background: white;
            box-shadow: 0 4px 15px rgba(79,172,254,0.15);
        }
        .assessment-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 12px;
        }
        .assessment-title {
            font-size: 18px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 5px;
        }
        .assessment-category {
            font-size: 13px;
            color: #718096;
        }
        .difficulty-badge {
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }
        .difficulty-badge.easy {
            background: #c6f6d5;
            color: #22543d;
        }
        .difficulty-badge.medium {
            background: #feebc8;
            color: #7c2d12;
        }
        .difficulty-badge.hard {
            background: #fed7d7;
            color: #742a2a;
        }
        .assessment-meta {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        .meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: #718096;
        }
        .assessment-actions {
            display: flex;
            gap: 10px;
        }
        .btn-start {
            padding: 10px 24px;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: 0.3s;
        }
        .btn-start:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(79,172,254,0.4);
        }
        .btn-details {
            padding: 10px 24px;
            background: white;
            color: #4facfe;
            border: 2px solid var(--primary);
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: 0.3s;
        }
        .btn-details:hover {
            background: #4facfe;
            color: white;
        }
        .sidebar {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .sidebar-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        .sidebar-card-title {
            font-size: 18px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 20px;
        }
        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        .activity-item {
            display: flex;
            gap: 12px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e2e8f0;
        }
        .activity-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }
        .activity-icon {
            width: 40px;
            height: 40px;
            background: #f7fafc;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #4facfe;
            font-size: 18px;
            flex-shrink: 0;
        }
        .activity-content { flex: 1; }
        .activity-title {
            font-size: 14px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 4px;
        }
        .activity-time {
            font-size: 12px;
            color: #a0aec0;
        }
        .progress-chart {
            height: 200px;
            background: linear-gradient(135deg, #f7fafc, #e2e8f0);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #718096;
            font-size: 14px;
            margin-bottom: 15px;
            flex-direction: column;
        }
        .overall-progress { margin-top: 15px; }
        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .progress-bar-container {
            width: 100%;
            height: 12px;
            background: #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
        }
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #4facfe, #00f2fe);
            border-radius: 10px;
            transition: width 0.5s;
        }

        .assessment-card.exhausted {
            opacity: 0.7;
        }
        .assessment-card.exhausted:hover {
            border-color: #e2e8f0;
            box-shadow: none;
        }
        .state-message {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 48px 20px;
            border-radius: 12px;
            text-align: center;
        }
        .state-message .state-icon { font-size: 40px; }
        .state-message p { font-size: 15px; color: #718096; }
        .state-empty { background: #f7fafc; }
        .state-error { background: #fff5f5; }
        .state-error p { color: #c53030; }
        @media (max-width: 1024px) {
            .main-content { grid-template-columns: 1fr; }
            .nav-search { display: none; }
        }
        @media (max-width: 768px) {
            .navbar { padding: 15px; }
            .container { padding: 15px; }
            .welcome-section {
                flex-direction: column;
                align-items: flex-start;
                gap: 20px;
            }
            .profile-name { display: none; }
        }
    </style>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
</head>
<body>
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
            <input type="text" id="searchInput" placeholder="Search assessments..." autocomplete="off">
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
                            $icon = $typeIcons[$n['notification_type']] ?? '🔔';
                        ?>
                        <div class="notif-item <?= $isUnread ? 'unread' : '' ?>">
                            <div class="notif-dot <?= $isUnread ? '' : 'read' ?>"></div>
                            <div class="notif-item-body">
                                <div class="notif-item-title"><?= $icon ?> <?= htmlspecialchars($n['title']) ?></div>
                                <?php if ($n['message']): ?>
                                <div class="notif-item-msg"><?= htmlspecialchars($n['message']) ?></div>
                                <?php endif; ?>
                                <div class="notif-item-time"><?= timeAgo($n['created_at']) ?></div>
                            </div>
                        </div>
                        <?php endforeach; endif; ?>
                    </div>
                    <a href="notifications.php" class="notif-see-all">See All</a>
                </div>
            </div>
            <div class="profile-dropdown-container">
                <button class="profile-button" onclick="toggleProfileDropdown()" aria-label="Profile menu" aria-expanded="false">
                    <div class="profile-avatar" aria-hidden="true"><?php echo $userInitials; ?></div>
                    <span class="profile-name"><?php echo htmlspecialchars($userName); ?></span>
                    <span class="dropdown-arrow">▼</span>
                </button>
                <div class="profile-dropdown" id="profileDropdown">
                    <div class="dropdown-header">
                        <div class="dropdown-avatar"><?php echo $userInitials; ?></div>
                        <div class="dropdown-user-info">
                            <div class="dropdown-user-name"><?php echo htmlspecialchars($userName); ?></div>
                            <div class="dropdown-user-email"><?php echo htmlspecialchars($userEmail); ?></div>
                        </div>
                    </div>
                    <div class="dropdown-menu">
                        <a href="student-profile.php" class="dropdown-item">
                            <span class="dropdown-item-icon">👤</span>
                            <span>My Profile</span>
                        </a>
                        <a href="help.html" target="_blank" rel="noopener noreferrer" class="dropdown-item">
                            <span>❓</span>
                            <span>Help & Support</span>
                        </a>
                        <div class="dropdown-divider"></div>
                        <button onclick="handleLogout()" class="dropdown-item logout">
                            <span class="dropdown-item-icon">🚪</span>
                            <span>Logout</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <div class="dropdown-overlay" id="dropdownOverlay" onclick="closeProfileDropdown()"></div>

    <div class="page-wrapper">
    <aside class="left-sidebar">
        <span class="left-sidebar-label">Navigation</span>
        <a href="student-dashboard.php" class="active"><i class="fa fa-home"></i> Dashboard</a>
        <a href="student-resources.php"><i class="fa fa-folder-open"></i> Resources</a>
        <a href="notifications.php" style="position:relative">
            <i class="fa fa-bell"></i> Notifications
            <?php if ($unreadCount > 0): ?>
            <span style="margin-left:auto;background:#e53e3e;color:white;font-size:11px;font-weight:700;padding:2px 7px;border-radius:20px;min-width:20px;text-align:center;"><?= $unreadCount ?></span>
            <?php endif; ?>
        </a>
        <div class="left-sidebar-bottom">
            <button onclick="handleLogout()"><i class="fa fa-sign-out-alt"></i> Logout</button>
        </div>
    </aside>
    <div class="page-content">
    <div class="container" style="padding: 0; max-width: 100%;">
        <div class="welcome-section">
            <div class="welcome-content">
                <h1>Welcome back, <?php echo strtoupper(htmlspecialchars($userName)); ?> 👋</h1>
                <p>Ready to continue your learning journey?</p>
            </div>
            <div class="quick-stats">
                <div class="stat-item">
                    <span class="stat-number"><?php echo $testsCompleted; ?></span>
                    <span class="stat-label">Tests Completed</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number"><?php echo $availableTests; ?></span>
                    <span class="stat-label">Available</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number"><?php echo $avgScore; ?>%</span>
                    <span class="stat-label">Avg. Score</span>
                </div>
            </div>
        </div>

        <div class="main-content">
            <div class="assessments-section">
                <div class="section-header">
                    <h2 class="section-title">Available Assessments</h2>
                    <a href="all-assessments.php" class="view-all-link">View All →</a>
                </div>
                <div class="filter-tabs">
                    <button class="filter-tab active" data-category="all">All Tests</button>
                    <button class="filter-tab" data-category="aptitude">Aptitude</button>
                    <button class="filter-tab" data-category="technical">Technical</button>
                    <button class="filter-tab" data-category="coding">Coding</button>
                    <button class="filter-tab" data-category="reasoning">Reasoning</button>
                    <button class="filter-tab" data-category="english">English</button>
                </div>
                <div class="assessment-list">
                    <?php if ($assessmentError): ?>
                        <div class="state-message state-error">
                            <span class="state-icon">⚠️</span>
                            <p>Could not load assessments. Please contact your administrator.</p>
                        </div>
                    <?php elseif (empty($assessments)): ?>
                        <div class="state-message state-empty">
                            <span class="state-icon">📋</span>
                            <p>No assessments available at the moment. Check back later.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($assessments as $a):
                            $id           = (int) $a['assessment_id'];
                            $lastAttemptId = (int) ($a['last_attempt_id'] ?? 0);
                            $attemptsLeft = (int)$a['max_attempts'] - (int)$a['attempts_used'];
                            $exhausted    = $attemptsLeft <= 0;
                            $catClass     = htmlspecialchars(strtolower($a['category']));
                            $diff         = strtolower($a['difficulty']);
                            $diffLabel    = ucfirst($diff);
                            $deadline     = $a['available_until']
                                            ? date('d M Y, g:i A', strtotime($a['available_until']))
                                            : null;
                        ?>
                        <div class="assessment-card <?= $exhausted ? 'exhausted' : '' ?>" data-category="<?= $catClass ?>">
                            <div class="assessment-header">
                                <div>
                                    <div class="assessment-title"><?= htmlspecialchars($a['title']) ?></div>
                                    <div class="assessment-category">
                                        <?= htmlspecialchars(ucfirst($a['category'])) ?>
                                        <?php if ($deadline): ?> • Due <?= $deadline ?><?php endif ?>
                                    </div>
                                </div>
                                <span class="difficulty-badge <?= $diff ?>"><?= $diffLabel ?></span>
                            </div>
                            <div class="assessment-meta">
                                <div class="meta-item"><span>❓</span><span><?= (int)$a['question_count'] ?> Questions</span></div>
                                <div class="meta-item"><span>⏱️</span><span><?= (int)$a['duration_minutes'] ?> Minutes</span></div>
                                <div class="meta-item"><span>🏆</span><span><?= (int)$a['total_marks'] ?> Points</span></div>
                                <?php if ((int)$a['max_attempts'] > 1): ?>
                                <div class="meta-item">
                                    <span>🔄</span>
                                    <span><?= $exhausted ? 'No attempts left' : "$attemptsLeft attempt(s) left" ?></span>
                                </div>
                                <?php endif ?>
                            </div>
                            <?php if (!$exhausted): ?>
                            <div class="assessment-actions">
                                <button class="btn-start" onclick="startAssessment(<?= $id ?>)">Start Test</button>
                                <button class="btn-details" onclick="viewDetails(<?= $id ?>)">View Details</button>
                            </div>
                            <?php else: ?>
                            <div class="assessment-actions">
                                <button class="btn-details" onclick="viewDetails(<?= $lastAttemptId ?>)">View Results</button>
                            </div>
                            <?php endif ?>
                        </div>
                        <?php endforeach ?>
                    <?php endif ?>
                </div>
            </div>

            <div class="sidebar">
                <div class="sidebar-card">
                    <h3 class="sidebar-card-title">Recent Activity</h3>
                    <div class="activity-list">
                        <?php if (empty($recentActivity)): ?>
                            <p style="color:#a0aec0;font-size:14px;text-align:center;padding:10px 0">
                                No completed tests yet.
                            </p>
                        <?php else: ?>
                            <?php foreach ($recentActivity as $act): ?>
                            <div class="activity-item">
                                <div class="activity-icon">✅</div>
                                <div class="activity-content">
                                    <div class="activity-title">Completed: <?= htmlspecialchars($act['title']) ?></div>
                                    <div class="activity-time">
                                        <?= timeAgo($act['submitted_at']) ?> • Score: <?= round((float)$act['percentage']) ?>%
                                    </div>
                                </div>
                            </div>
                            <?php endforeach ?>
                        <?php endif ?>
                    </div>
                </div>

                <div class="sidebar-card">
                    <h3 class="sidebar-card-title">Your Progress</h3>
                    <div class="progress-chart">
                        <span>📈 Progress Chart</span>
                        <small>(Chart visualization will be here)</small>
                    </div>
                    <div class="overall-progress">
                        <div class="progress-label">
                            <span style="font-weight:600">Overall Completion</span>
                            <span style="color:#4facfe;font-weight:700"><?= $completionPct ?>%</span>
                        </div>
                        <div class="progress-bar-container">
                            <div class="progress-bar-fill" style="width:<?= $completionPct ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div><!-- /.container -->
    </div><!-- /.page-content -->
    </div><!-- /.page-wrapper -->



    <script>
        const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;

        function toggleProfileDropdown() {
            const dropdown = document.getElementById('profileDropdown');
            const overlay = document.getElementById('dropdownOverlay');
            dropdown.classList.toggle('show');
            overlay.classList.toggle('show');
        }

        function closeProfileDropdown() {
            document.getElementById('profileDropdown').classList.remove('show');
            document.getElementById('notifDropdown').classList.remove('show');
            document.getElementById('dropdownOverlay').classList.remove('show');
        }

        function handleLogout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = 'logout.php';
            }
        }

        function toggleNotifDropdown() {
            const dd = document.getElementById('notifDropdown');
            const overlay = document.getElementById('dropdownOverlay');
            const isOpen = dd.classList.contains('show');
            // Close profile dropdown if open
            document.getElementById('profileDropdown').classList.remove('show');
            dd.classList.toggle('show', !isOpen);
            overlay.classList.toggle('show', !isOpen);
            // Mark all as read when opening
            if (!isOpen) {
                fetch('api/notifications/mark-read.php', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': CSRF_TOKEN, 'Content-Type': 'application/json' }
                }).then(() => {
                    // Clear badge
                    const badge = document.querySelector('.notification-badge');
                    if (badge) badge.remove();
                    // Remove unread highlights
                    document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
                    document.querySelectorAll('.notif-dot:not(.read)').forEach(el => el.classList.add('read'));
                }).catch(() => {});
            }
        }

        function startAssessment(id) {
            if (confirm('Are you ready to start this assessment?')) {
                window.location.href = 'test-preview.php?id=' + id;
            }
        }

        function viewDetails(id) {
            window.location.href = 'test-results.php?attempt_id=' + id;
        }



        // Filter tabs
        document.querySelectorAll('.filter-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                const category = this.dataset.category;
                document.querySelectorAll('.assessment-card').forEach(card => {
                    if (category === 'all' || card.dataset.category === category) {
                        card.classList.remove('hidden');
                    } else {
                        card.classList.add('hidden');
                    }
                });
            });
        });

        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const search = e.target.value.toLowerCase();
            document.querySelectorAll('.assessment-card').forEach(card => {
                const title = card.querySelector('.assessment-title').textContent.toLowerCase();
                const category = card.querySelector('.assessment-category').textContent.toLowerCase();
                if (title.includes(search) || category.includes(search)) {
                    card.classList.remove('hidden');
                } else {
                    card.classList.add('hidden');
                }
            });
        });

        // Animate progress bars on load
        window.addEventListener('load', function() {
            document.querySelectorAll('.progress-bar-fill').forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0';
                setTimeout(() => bar.style.width = width, 100);
            });
        });

        // ── Live notification badge polling ──
        let lastUnreadCount = <?= $unreadCount ?>;

        function updateNotifBadge(count) {
            const wrap = document.querySelector('.notif-dropdown-wrap') || document.querySelector('.notification-icon').parentElement;
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

        function pollNotifications() {
            fetch('api/notifications/mark-read.php', { method: 'GET' })
                .then(() => {}) .catch(() => {});

            // Use a dedicated lightweight count endpoint
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

        // Poll every 30 seconds
        setInterval(pollNotifications, 30000);
    </script>
</body>
</html>
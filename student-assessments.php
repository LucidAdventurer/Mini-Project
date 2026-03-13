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

// ── Latest 5 notifications for dropdown ──
$notifDropResult = safePreparedQuery($conn,
    "SELECT notification_id, title, message, is_read, created_at
     FROM notifications WHERE user_id = ?
     ORDER BY created_at DESC LIMIT 5",
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
     ORDER BY a.start_time IS NULL ASC, a.start_time DESC, a.created_at DESC",
    "iiiii",
    [$userId, $userId, $userId, $userId, $userId]
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
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
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

        /* ── NAVBAR ── */
        .navbar {
            background: var(--primary);
            padding: 12px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: fixed;
            top: 0; left: 0; right: 0;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        .navbar-brand {
            display: flex; align-items: center; gap: 12px;
            color: white; text-decoration: none; font-weight: 700; font-size: 20px;
        }
        .nav-center { display: flex; align-items: center; gap: 10px; flex: 1; max-width: 750px; margin: 0 24px; }
        .nav-search { display: flex; align-items: center; gap: 8px; background: #f7fafc; border: 2px solid #e2e8f0; border-radius: 10px; padding: 8px 14px; flex: 1; transition: border-color .2s, box-shadow .2s; }
        .nav-search:focus-within { border-color: #4facfe; box-shadow: 0 0 0 3px rgba(79,172,254,.15); }
        .nav-search .sicon { color: #a0aec0; font-size: 14px; flex-shrink: 0; }
        .nav-search input[type="text"] { border: none; background: transparent; font-family: inherit; font-size: 14px; color: #2d3748; outline: none; width: 100%; }
        .nav-date-box { display: flex; align-items: center; gap: 6px; background: #f7fafc; border: 2px solid #e2e8f0; border-radius: 10px; padding: 8px 12px; flex-shrink: 0; transition: border-color .2s, box-shadow .2s; }
        .nav-date-box:focus-within { border-color: #4facfe; box-shadow: 0 0 0 3px rgba(79,172,254,.15); }
        .nav-date-label { font-size: 11px; font-weight: 700; color: #a0aec0; text-transform: uppercase; letter-spacing: .05em; }
        .nav-date-box input[type="date"] { border: none; background: transparent; font-family: inherit; font-size: 13px; color: #4a5568; outline: none; cursor: pointer; width: 120px; }
        .nav-date-box input[type="date"]::-webkit-calendar-picker-indicator { opacity: 0.5; cursor: pointer; }
        .nav-profile { display: flex; align-items: center; gap: 15px; }
        .notification-icon {
            position: relative; width: 40px; height: 40px; background: #f7fafc;
            border-radius: 10px; display: flex; align-items: center; justify-content: center;
            cursor: pointer; border: none; transition: 0.3s;
        }
        .notification-icon:hover { background: #e2e8f0; }
        .notif-dropdown-wrap { position: relative; }
        .notif-dropdown {
            position: absolute; top: calc(100% + 10px); right: 0;
            background: white; border-radius: 14px; box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            width: 340px; opacity: 0; visibility: hidden;
            transform: translateY(-8px); transition: 0.25s; z-index: 1002;
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
        .notification-badge {
            position: absolute; top: -5px; right: -5px;
            background: #e53e3e; color: white;
            width: 20px; height: 20px; border-radius: 50%;
            font-size: 11px; display: flex; align-items: center; justify-content: center;
            font-weight: bold;
            animation: badgePulse 1.8s ease-in-out infinite;
        }
        @keyframes badgePulse {
            0%   { box-shadow: 0 0 0 0 rgba(229,62,62,0.6); }
            70%  { box-shadow: 0 0 0 7px rgba(229,62,62,0); }
            100% { box-shadow: 0 0 0 0 rgba(229,62,62,0); }
        }
        .profile-dropdown-container { position: relative; }
        .profile-button { display: flex; align-items: center; gap: 10px; padding: 8px 15px; background: #f7fafc; border: none; border-radius: 10px; cursor: pointer; transition: 0.3s; }
        .profile-button:hover { background: #e2e8f0; }
        .profile-avatar { width: 35px; height: 35px; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 14px; }
        .profile-name { font-weight: 600; font-size: 14px; color: #2d3748; }
        .dropdown-arrow { font-size: 12px; color: #718096; }
        .profile-dropdown { position: absolute; top: calc(100% + 10px); right: 0; background: white; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,0.15); min-width: 220px; opacity: 0; visibility: hidden; transform: translateY(-10px); transition: 0.3s; z-index: 1001; }
        .profile-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0); }
        .dropdown-header { padding: 20px; border-bottom: 1px solid #e2e8f0; display: flex; gap: 12px; align-items: center; }
        .dropdown-avatar { width: 50px; height: 50px; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 20px; }
        .dropdown-user-name { font-weight: 700; font-size: 16px; color: #2d3748; margin-bottom: 4px; }
        .dropdown-user-email { font-size: 13px; color: #718096; }
        .dropdown-menu { padding: 8px 0; }
        .dropdown-item { display: flex; align-items: center; gap: 12px; padding: 12px 20px; color: #2d3748; text-decoration: none; cursor: pointer; border: none; background: none; width: 100%; text-align: left; font-size: 14px; font-family: inherit; }
        .dropdown-item:hover { background: #f7fafc; }
        .dropdown-item.logout { color: #f56565; }
        .dropdown-item.logout:hover { background: #fff5f5; }
        .dropdown-divider { height: 1px; background: #e2e8f0; margin: 8px 0; }
        .dropdown-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: transparent; z-index: 999; display: none; }
        .dropdown-overlay.show { display: block; }

        /* ── PAGE LAYOUT ── */
        .page-wrapper { display: flex; min-height: calc(100vh - 70px); }
        .left-sidebar {
            width: 220px; flex-shrink: 0; padding: 24px 12px;
            display: flex; flex-direction: column; gap: 2px;
            background: transparent; min-height: calc(100vh - 71px);
            position: sticky; top: 71px; align-self: flex-start;
        }
        .left-sidebar-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #718096; padding: 14px 12px 6px; }
        .left-sidebar a { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 10px; text-decoration: none; font-size: 14px; font-weight: 500; color: #4a5568; transition: background .15s, color .15s; }
        .left-sidebar a:hover { background: rgba(35,76,106,.08); color: var(--primary); }
        .left-sidebar a.active { background: rgba(35,76,106,.12); color: var(--primary); font-weight: 600; }
        .left-sidebar a i { width: 18px; text-align: center; font-size: 15px; }
        .left-sidebar-section { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #718096; padding: 14px 12px 6px; }
        .left-sidebar-bottom { margin-top: auto; padding-top: 8px; border-top: 1px solid rgba(35,76,106,.12); }
        .logout-link { color: #e53e3e !important; }
        .logout-link:hover { background: rgba(229,62,62,.08) !important; }

        .page-content { flex: 1; min-width: 0; }
        .container { max-width: 1200px; margin: 0 auto; padding: 30px; }

        /* ── PAGE HEADER ── */
        .page-header {
            background: white; border-radius: 16px;
            padding: 28px 32px; margin-bottom: 24px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            display: flex; align-items: center; justify-content: space-between;
            gap: 20px;
        }
        .page-header-left h1 { font-size: 24px; font-weight: 800; color: #2d3748; margin-bottom: 4px; }
        .page-header-left p { font-size: 14px; color: #718096; }

        /* ── SUMMARY CARDS ── */
        .summary-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        .summary-card {
            background: white; border-radius: 14px;
            padding: 20px 22px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex; align-items: center; gap: 14px;
            cursor: pointer; border: 2px solid transparent;
            transition: box-shadow .2s, border-color .2s, transform .15s;
        }
        .summary-card:hover { box-shadow: 0 6px 20px rgba(0,0,0,0.1); transform: translateY(-2px); }
        .summary-card.active-filter { border-color: var(--primary); }
        .summary-icon {
            width: 48px; height: 48px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0;
        }
        .summary-icon.blue  { background: #ebf8ff; }
        .summary-icon.green { background: #f0fff4; }
        .summary-icon.amber { background: #fffbeb; }
        .summary-icon.red   { background: #fff5f5; }
        .summary-number { font-size: 26px; font-weight: 800; color: #2d3748; line-height: 1; }
        .summary-label  { font-size: 13px; color: #718096; margin-top: 3px; }

        /* ── TOOLBAR ── */
        .toolbar {
            background: white; border-radius: 14px;
            padding: 16px 20px; margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
        }
        .toolbar-search { flex: 1; min-width: 200px; position: relative; }
        .toolbar-search input {
            width: 100%; padding: 10px 16px 10px 40px;
            border: 2px solid #e2e8f0; border-radius: 10px;
            font-family: inherit; font-size: 14px; color: #2d3748; outline: none;
            background: #f7fafc; transition: border-color .2s, box-shadow .2s;
        }
        .toolbar-search input:focus { border-color: #4facfe; box-shadow: 0 0 0 3px rgba(79,172,254,.15); }
        .toolbar-search .sicon { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: #a0aec0; }
        .toolbar-select {
            padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 10px;
            font-family: inherit; font-size: 14px; color: #2d3748; background: #f7fafc;
            outline: none; cursor: pointer; transition: border-color .2s;
        }
        .toolbar-select:focus { border-color: #4facfe; }
        .filter-tabs { display: flex; gap: 8px; flex-wrap: wrap; }
        .filter-tab {
            padding: 8px 16px; border-radius: 20px; font-size: 13px; font-weight: 500;
            cursor: pointer; border: 2px solid #e2e8f0; background: white; color: #718096;
            transition: .15s; white-space: nowrap;
        }
        .filter-tab:hover { border-color: var(--primary); color: var(--primary); }
        .filter-tab.active { background: var(--primary); border-color: var(--primary); color: white; }

        /* ── ASSESSMENT LIST ── */
        .assessments-grid {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        /* ── ASSESSMENT CARD ── */
        .assessment-card {
            background: white; border-radius: 15px;
            padding: 20px 24px; border: 2px solid #e2e8f0;
            display: flex; flex-direction: column; gap: 12px;
            transition: border-color .2s, box-shadow .2s;
            width: 100%;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .assessment-card:hover { border-color: #4facfe; box-shadow: 0 4px 15px rgba(79,172,254,0.15); }
        .assessment-card.exhausted { opacity: 0.72; }
        .assessment-card.expired  { opacity: 0.65; }
        .assessment-card.in-progress { border-color: #4facfe; }

        /* Card header */
        .card-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
        .card-title-group { flex: 1; }
        .card-title { font-size: 18px; font-weight: 700; color: #2d3748; margin-bottom: 5px; line-height: 1.3; }
        .card-teacher { font-size: 13px; color: #718096; }
        .card-badges { display: flex; gap: 6px; flex-shrink: 0; flex-wrap: wrap; justify-content: flex-end; }

        /* Badges */
        .badge {
            padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 600;
            text-transform: capitalize; white-space: nowrap;
        }
        .badge-easy     { background: #c6f6d5; color: #22543d; }
        .badge-medium   { background: #feebc8; color: #744210; }
        .badge-hard     { background: #fed7d7; color: #742a2a; }
        .badge-category { background: #e9f4ff; color: #2b6cb0; }
        .badge-status-pending    { background: #ebf8ff; color: #2b6cb0; }
        .badge-status-done       { background: #f0fff4; color: #276749; }
        .badge-status-progress   { background: #fef3c7; color: #92400e; }
        .badge-status-expired    { background: #fff5f5; color: #9b2c2c; }
        .badge-status-exhausted  { background: #f7fafc; color: #718096; }

        /* Meta row */
        .card-meta { display: flex; flex-wrap: wrap; gap: 20px; }
        .meta-item { display: flex; align-items: center; gap: 6px; font-size: 13px; color: #718096; }

        /* Deadline strip */
        .deadline-strip {
            display: inline-flex; align-items: center; gap: 7px; font-size: 11px; font-weight: 600;
            padding: 3px 10px; border-radius: 20px;
        }
        .deadline-strip.urgent  { background: #fff5f5; color: #c53030; }
        .deadline-strip.soon    { background: #fffbeb; color: #7b5c00; }
        .deadline-strip.normal  { background: #f0fff4; color: #276749; }
        .deadline-strip.expired { background: #f7fafc; color: #718096; }

        /* Score bar */
        .score-section { display: flex; flex-direction: column; gap: 6px; }
        .score-label { display: flex; justify-content: space-between; font-size: 13px; }
        .score-label span:first-child { color: #718096; }
        .score-label span:last-child { font-weight: 700; color: #2d3748; }
        .score-bar { height: 8px; background: #e2e8f0; border-radius: 10px; overflow: hidden; }
        .score-bar-fill { height: 100%; border-radius: 10px; transition: width .6s; }
        .score-bar-fill.pass  { background: linear-gradient(90deg, #38a169, #68d391); }
        .score-bar-fill.fail  { background: linear-gradient(90deg, #e53e3e, #fc8181); }
        .score-bar-fill.empty { background: #e2e8f0; }

        /* Attempts indicator */
        .attempts-row { display: flex; align-items: center; justify-content: space-between; }
        .attempts-dots { display: flex; gap: 5px; }
        .attempt-dot {
            width: 10px; height: 10px; border-radius: 50%;
            border: 2px solid #e2e8f0; background: transparent; transition: .2s;
        }
        .attempt-dot.used { background: var(--primary); border-color: var(--primary); }
        .attempts-text { font-size: 12px; color: #a0aec0; }

        /* Action buttons */
        .card-actions { display: flex; gap: 10px; }
        .btn-start {
            flex: 1; padding: 10px 0; border-radius: 8px; border: none;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            color: white; font-weight: 700; font-size: 14px; cursor: pointer;
            transition: opacity .2s, transform .15s; font-family: inherit;
            display: flex; align-items: center; justify-content: center; gap: 6px;
        }
        .btn-start:hover { opacity: .9; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(79,172,254,0.4); }
        .btn-resume {
            flex: 1; padding: 10px 0; border-radius: 8px; border: none;
            background: linear-gradient(135deg, #f6ad55, #ed8936);
            color: white; font-weight: 700; font-size: 14px; cursor: pointer;
            transition: opacity .2s, transform .15s; font-family: inherit;
            display: flex; align-items: center; justify-content: center; gap: 6px;
        }
        .btn-resume:hover { opacity: .9; transform: translateY(-2px); }
        .btn-results {
            flex: 1; padding: 10px 0; border-radius: 8px;
            border: 2px solid #4facfe; background: white;
            color: #4facfe; font-weight: 700; font-size: 14px; cursor: pointer;
            transition: background .2s, color .2s; font-family: inherit;
            display: flex; align-items: center; justify-content: center; gap: 6px;
        }
        .btn-results:hover { background: #4facfe; color: white; }
        .btn-disabled {
            flex: 1; padding: 10px 0; border-radius: 8px; border: 2px solid #e2e8f0;
            background: #f7fafc; color: #a0aec0; font-weight: 600; font-size: 14px;
            cursor: not-allowed; font-family: inherit;
            display: flex; align-items: center; justify-content: center; gap: 6px;
        }

        /* State messages */
        .state-message {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 12px; padding: 64px 20px; border-radius: 16px; text-align: center;
            grid-column: 1 / -1;
        }
        .state-message .state-icon { font-size: 48px; }
        .state-message h3 { font-size: 18px; font-weight: 700; color: #2d3748; }
        .state-message p  { font-size: 14px; color: #718096; }
        .state-empty { background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .state-error { background: #fff5f5; }
        .state-error h3, .state-error p { color: #c53030; }

        /* Hidden utility */
        .hidden { display: none !important; }

        /* ── RESPONSIVE ── */
        @media (max-width: 1100px) { .summary-row { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 900px)  { .nav-search { display: none; } }
        @media (max-width: 768px)  { .container { padding: 15px; } .left-sidebar { display: none; } .profile-name { display: none; } }
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
    <div class="nav-center">
        <div class="nav-search">
            <i class="fa fa-search sicon"></i>
            <input type="text" id="navSearchInput" placeholder="Search by title or teacher..." autocomplete="off">
        </div>
        <div class="nav-date-box">
            <i class="fa fa-calendar-days" style="color:#a0aec0;font-size:13px"></i>
            <span class="nav-date-label">From</span>
            <input type="date" id="dateFrom">
        </div>
        <div class="nav-date-box">
            <i class="fa fa-calendar-days" style="color:#a0aec0;font-size:13px"></i>
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
                <div class="notif-dropdown-header">🔔 Notifications</div>
                <div class="notif-list">
                    <?php if (empty($notifItems)): ?>
                        <div class="notif-empty">No notifications yet.</div>
                    <?php else: foreach ($notifItems as $n): ?>
                        <div class="notif-item <?= $n['is_read'] ? '' : 'unread' ?>">
                            <div class="notif-dot <?= $n['is_read'] ? 'read' : '' ?>"></div>
                            <div class="notif-item-body">
                                <div class="notif-item-title"><?= htmlspecialchars($n['title']) ?></div>
                                <div class="notif-item-msg"><?= htmlspecialchars(mb_substr($n['message'] ?? '', 0, 80)) ?><?= strlen($n['message'] ?? '') > 80 ? '…' : '' ?></div>
                                <div class="notif-item-time"><?= timeAgo($n['created_at']) ?></div>
                            </div>
                        </div>
                    <?php endforeach; endif ?>
                </div>
                <a href="notifications.php" class="notif-see-all">View all notifications →</a>
            </div>
        </div>
        <!-- Profile -->
        <div class="profile-dropdown-container">
            <button class="profile-button" onclick="toggleProfileDropdown()">
                <div class="profile-avatar"><?= htmlspecialchars($userInitials) ?></div>
                <span class="profile-name"><?= htmlspecialchars($userName) ?></span>
                <span class="dropdown-arrow">▼</span>
            </button>
            <div class="profile-dropdown" id="profileDropdown">
                <div class="dropdown-header">
                    <div class="dropdown-avatar"><?= htmlspecialchars($userInitials) ?></div>
                    <div>
                        <div class="dropdown-user-name"><?= htmlspecialchars($userName) ?></div>
                        <div class="dropdown-user-email"><?= htmlspecialchars($userEmail) ?></div>
                    </div>
                </div>
                <div class="dropdown-menu">
                    <a href="student-profile.php" class="dropdown-item"><span>👤</span> My Profile</a>
                    <a href="student-dashboard.php" class="dropdown-item"><span>🏠</span> Dashboard</a>
                    <div class="dropdown-divider"></div>
                    <button class="dropdown-item logout" onclick="handleLogout()"><span>🚪</span> Logout</button>
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
        <a href="notifications.php"><i class="fa fa-bell"></i> Notifications</a>

        <span class="left-sidebar-section">Filter by Category</span>
        <a href="#" id="cat-all"       onclick="setSidebarCat('all',this);return false;"><i class="fa fa-layer-group"></i> All Tests</a>
        <a href="#" id="cat-aptitude"  onclick="setSidebarCat('aptitude',this);return false;"><i class="fa fa-calculator" style="color:#4facfe"></i> Aptitude</a>
        <a href="#" id="cat-technical" onclick="setSidebarCat('technical',this);return false;"><i class="fa fa-microchip" style="color:#9f7aea"></i> Technical</a>
        <a href="#" id="cat-coding"    onclick="setSidebarCat('coding',this);return false;"><i class="fa fa-code" style="color:#48bb78"></i> Coding</a>
        <a href="#" id="cat-reasoning" onclick="setSidebarCat('reasoning',this);return false;"><i class="fa fa-brain" style="color:#ed8936"></i> Reasoning</a>
        <a href="#" id="cat-english"   onclick="setSidebarCat('english',this);return false;"><i class="fa fa-book" style="color:#fc8181"></i> English</a>
        <a href="#" id="cat-general"   onclick="setSidebarCat('general',this);return false;"><i class="fa fa-globe" style="color:#38b2ac"></i> General</a>

        <div class="left-sidebar-bottom">
            <a href="logout.php" class="logout-link"><i class="fa fa-sign-out-alt"></i> Logout</a>
        </div>
    </aside>

    <div class="page-content">
    <div class="container">

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-left">
                <h1>📋 My Assessments</h1>
                <p>Tests and assessments assigned to you by your teacher</p>
            </div>
            <div style="font-size:13px;color:#718096;text-align:right">
                <span style="font-weight:600;color:#2d3748"><?= $totalAssigned ?></span> total assigned
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
        </div>

        <!-- Assessment Cards Grid -->
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
                $id          = (int) $a['assessment_id'];
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
                    $statusClass = 'badge-status-expired'; // reuse grey
                    $cardClass   = '';
                } else {
                    $statusLabel = 'Pending';
                    $statusClass = 'badge-status-pending';
                    $cardClass   = '';
                }

                // Deadline display
                $dLabel    = deadlineLabel($a['end_time'] ?? null);
                $dClass    = '';
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
                $jsCreated   = !empty($a['created_at'])      ? strtotime($a['created_at'])      : 0;
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

                <!-- Header: title + difficulty badge -->
                <div class="card-header">
                    <div class="card-title-group">
                        <div class="card-title"><?= htmlspecialchars($a['title']) ?></div>
                        <div class="card-teacher"><?= htmlspecialchars($a['teacher_name'] ?? 'Teacher') ?></div>
                    </div>
                    <span class="badge badge-<?= htmlspecialchars($a['difficulty'] ?? 'medium') ?>">
                        <?= htmlspecialchars(ucfirst($a['difficulty'] ?? 'medium')) ?>
                    </span>
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
                    <div style="font-size:11px;color:#a0aec0;margin-top:2px">
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

    </div><!-- /.container -->
    </div><!-- /.page-content -->
</div><!-- /.page-wrapper -->

<script>
    const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;

    // ── Category filter (sidebar) ──
    const sidebarCatIds = ['cat-all','cat-aptitude','cat-technical','cat-coding','cat-reasoning','cat-english','cat-general'];
    function setSidebarCat(cat, el) {
        currentCategory = cat;
        sidebarCatIds.forEach(id => document.getElementById(id)?.classList.remove('active'));
        el.classList.add('active');
        // Keep the dropdown in sync
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
        const isOpen = dd.classList.contains('show');
        document.getElementById('profileDropdown').classList.remove('show');
        dd.classList.toggle('show', !isOpen);
        document.getElementById('dropdownOverlay').classList.toggle('show', !isOpen);
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
        // Sync sidebar highlight
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
            const status = card.dataset.status;   // pending | completed | expired | progress
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

    // Notification polling
    let lastUnreadCount = <?= $unreadCount ?>;
    function pollNotifications() {
        fetch('api/notifications/unread-count.php')
            .then(r => r.json())
            .then(data => {
                if (data.success && typeof data.count === 'number') {
                    const badge = document.querySelector('.notification-badge');
                    if (data.count > 0) {
                        if (!badge) {
                            const b = document.createElement('div');
                            b.className = 'notification-badge';
                            b.textContent = data.count > 99 ? '99+' : data.count;
                            document.getElementById('notifBtn').appendChild(b);
                        } else {
                            badge.textContent = data.count > 99 ? '99+' : data.count;
                        }
                    } else if (badge) { badge.remove(); }
                    lastUnreadCount = data.count;
                }
            }).catch(() => {});
    }
    setInterval(pollNotifications, 30000);
</script>
</body>
</html>
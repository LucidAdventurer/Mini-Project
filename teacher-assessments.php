<?php
/* ========================================
 * TEACHER ASSESSMENTS PAGE
 * File: teacher-assessments.php
 * Shows all assessments created by this teacher.
 * Teacher can create, edit, delete, view student results,
 * download & print result lists.
 * ======================================== */

require_once 'config.php';
require_once 'db-guard.php';

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



// ── Active filter from URL ──
$activeFilter   = trim($_GET['filter']   ?? 'all');
$allowedFilters = ['all', 'published', 'draft', 'archived'];
if (!in_array($activeFilter, $allowedFilters, true)) $activeFilter = 'all';

$activeCategory   = trim($_GET['category'] ?? 'all');
$allowedCategories = ['all', 'aptitude', 'technical', 'coding', 'reasoning', 'english', 'general'];
if (!in_array($activeCategory, $allowedCategories, true)) $activeCategory = 'all';

// ── Fetch all assessments created by this teacher ──
$assessments     = [];
$assessmentError = false;

$r3 = safePreparedQuery($conn,
    "SELECT
        a.assessment_id,
        a.title,
        a.description,
        a.category,
        a.difficulty,
        a.status,
        a.duration_minutes,
        a.total_marks,
        a.passing_marks,
        a.max_attempts,
        a.start_time,
        a.end_time,
        a.created_at,
        (SELECT COUNT(*) FROM questions q WHERE q.assessment_id = a.assessment_id) AS question_count,
        (SELECT COUNT(DISTINCT aa.user_id) FROM assessment_attempts aa
          WHERE aa.assessment_id = a.assessment_id
            AND aa.status = 'submitted') AS students_completed,
        (SELECT COUNT(DISTINCT aa2.user_id) FROM assessment_attempts aa2
          WHERE aa2.assessment_id = a.assessment_id) AS students_attempted,
        (SELECT ROUND(AVG(aa3.percentage),1) FROM assessment_attempts aa3
          WHERE aa3.assessment_id = a.assessment_id
            AND aa3.status = 'submitted') AS avg_score
     FROM assessments a
     WHERE a.created_by = ?
     ORDER BY a.created_at DESC",
    "i", [$teacherId]
);

if ($r3['success'] && $r3['result']) {
    while ($row = $r3['result']->fetch_assoc()) $assessments[] = $row;
    $r3['result']->free();
} else {
    $assessmentError = true;
}

// ── Summary counts ──
$totalAll       = count($assessments);
$totalPublished = 0;
$totalDraft     = 0;
$totalArchived  = 0;

foreach ($assessments as $a) {
    if ($a['status'] === 'published') $totalPublished++;
    elseif ($a['status'] === 'draft') $totalDraft++;
    else $totalArchived++;
}

/* Helper: human-readable time-ago */
if (!function_exists('timeAgo')) {
    function timeAgo(string $datetime): string {
        $diff = time() - strtotime($datetime);
        if ($diff < 60)     return 'Just now';
        if ($diff < 3600)   return floor($diff / 60)   . ' min ago';
        if ($diff < 86400)  return floor($diff / 3600)  . ' hr ago';
        if ($diff < 604800) return floor($diff / 86400) . ' day ago';
        return date('d M Y', strtotime($datetime));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Assessments - PREPAURA Teacher</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary:   #2E073F;
            --secondary: #AD49E1;
            --bg:        #D3DAD9;
            --text:      #2d3748;
            --text-light:#718096;
            --border:    #e2e8f0;
            --white:     #ffffff;
            --success:   #48bb78;
            --error:     #f56565;
            --radius:    10px;
            --radius-lg: 20px;
            --shadow-sm: 0 2px 10px rgba(0,0,0,0.07);
            --shadow-md: 0 4px 20px rgba(0,0,0,0.1);
            --shadow-lg: 0 8px 30px rgba(0,0,0,0.15);
            --transition: all 0.25s ease;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--bg);
            min-height: 100vh;
            padding-top: 71px;
            color: var(--text);
        }

        /* ── NAVBAR ── */
        .navbar {
            background: var(--primary);
            padding: 12px 28px;
            display: flex; align-items: center; justify-content: space-between;
            position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.15);
        }
        .navbar-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; }
        .brand-logo-img { width: 44px; height: 44px; border-radius: 10px; object-fit: contain; background: white; padding: 4px; flex-shrink: 0; }
        .brand-text-group { display: flex; flex-direction: column; line-height: 1; }
        .brand-name { font-size: 19px; font-weight: 800; color: white; letter-spacing: 1px; }
        .brand-tagline { font-size: 10.5px; color: rgba(255,255,255,0.65); margin-top: 3px; }
        .nav-center { display: flex; align-items: center; gap: 10px; flex: 1; max-width: 700px; margin: 0 24px; }
        .nav-search { display: flex; align-items: center; gap: 8px; background: #f7fafc; border: 2px solid #e2e8f0; border-radius: 10px; padding: 8px 14px; flex: 1; transition: border-color .2s, box-shadow .2s; }
        .nav-search:focus-within { border-color: var(--secondary); box-shadow: 0 0 0 3px rgba(173,73,225,.15); }
        .nav-search .sicon { color: #a0aec0; font-size: 14px; flex-shrink: 0; }
        .nav-search input { border: none; background: transparent; font-family: inherit; font-size: 14px; color: var(--text); outline: none; width: 100%; }
        .nav-date-box { display: flex; align-items: center; gap: 6px; background: #f7fafc; border: 2px solid #e2e8f0; border-radius: 10px; padding: 8px 12px; flex-shrink: 0; transition: border-color .2s; }
        .nav-date-box:focus-within { border-color: var(--secondary); }
        .nav-date-label { font-size: 11px; font-weight: 700; color: #a0aec0; text-transform: uppercase; letter-spacing: .05em; }
        .nav-date-box input[type="date"] { border: none; background: transparent; font-family: inherit; font-size: 13px; color: #4a5568; outline: none; cursor: pointer; width: 120px; }
        .nav-profile { display: flex; align-items: center; gap: 15px; position: relative; }



        /* Profile dropdown */
        .profile-dropdown-container { position: relative; }
        .profile-button { display: flex; align-items: center; gap: 10px; padding: 8px 14px; background: rgba(255,255,255,0.1); border: none; border-radius: 10px; cursor: pointer; transition: var(--transition); }
        .profile-button:hover { background: rgba(255,255,255,0.2); }
        .profile-avatar { width: 34px; height: 34px; background: linear-gradient(135deg, var(--primary), var(--secondary)); border: 2px solid rgba(255,255,255,0.4); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 13px; }
        .profile-name { font-weight: 600; font-size: 14px; color: white; }
        .profile-caret { color: rgba(255,255,255,0.6); font-size: 10px; }
        .profile-dropdown { position: absolute; top: calc(100% + 12px); right: 0; background: white; border-radius: var(--radius); box-shadow: var(--shadow-lg); min-width: 220px; opacity: 0; visibility: hidden; transform: translateY(-8px); transition: var(--transition); z-index: 1001; }
        .profile-dropdown.open { opacity: 1; visibility: visible; transform: translateY(0); }
        .dropdown-header { padding: 16px 20px; border-bottom: 1px solid var(--border); }
        .dropdown-name  { font-weight: 700; font-size: 14px; color: var(--text); }
        .dropdown-email { font-size: 12px; color: var(--text-light); margin-top: 2px; }
        .dropdown-role  { display: inline-block; margin-top: 6px; padding: 2px 10px; background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .dropdown-menu { padding: 6px 0; }
        .dropdown-item { display: flex; align-items: center; gap: 12px; padding: 11px 20px; color: var(--text); text-decoration: none; font-size: 14px; transition: var(--transition); cursor: pointer; border: none; background: none; width: 100%; text-align: left; font-family: inherit; }
        .dropdown-item:hover { background: #f7fafc; }
        .dropdown-item.danger { color: var(--error); }
        .dropdown-item.danger:hover { background: #fff5f5; }
        .dropdown-divider { height: 1px; background: var(--border); margin: 4px 0; }
        .dropdown-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: transparent; z-index: 999; display: none; }
        .dropdown-overlay.show { display: block; }

        /* ── PAGE LAYOUT ── */
        .page-wrapper { display: flex; min-height: calc(100vh - 71px); }
        .left-sidebar {
            width: 220px; flex-shrink: 0; padding: 24px 12px;
            display: flex; flex-direction: column; gap: 2px;
            background: transparent; min-height: calc(100vh - 71px);
            position: sticky; top: 71px; align-self: flex-start;
        }
        .left-sidebar-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #718096; padding: 14px 12px 6px; }
        .left-sidebar a { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 10px; text-decoration: none; font-size: 14px; font-weight: 500; color: #4a5568; transition: background .15s, color .15s; }
        .left-sidebar a:hover { background: rgba(46,7,63,.08); color: var(--primary); }
        .left-sidebar a.active { background: rgba(46,7,63,.12); color: var(--primary); font-weight: 600; }
        .left-sidebar a i { width: 18px; text-align: center; font-size: 15px; }
        .left-sidebar-section { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #718096; padding: 14px 12px 6px; }
        .left-sidebar-bottom { margin-top: auto; padding-top: 8px; border-top: 1px solid rgba(46,7,63,.12); }
        .left-sidebar-bottom button { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 10px; font-size: 14px; font-weight: 500; color: #e53e3e; background: none; border: none; cursor: pointer; width: 100%; transition: background .15s; font-family: inherit; }
        .left-sidebar-bottom button:hover { background: rgba(229,62,62,.08); }
        .left-sidebar-bottom button i { width: 18px; text-align: center; font-size: 15px; }
        .page-content { flex: 1; min-width: 0; }
        .container { max-width: 1200px; margin: 0 auto; padding: 30px; }

        /* ── PAGE HEADER ── */
        .page-header {
            background: white; border-radius: 16px;
            padding: 28px 32px; margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
            display: flex; align-items: center; justify-content: space-between; gap: 20px;
        }
        .page-header-left h1 { font-size: 24px; font-weight: 800; color: var(--text); margin-bottom: 4px; }
        .page-header-left p { font-size: 14px; color: var(--text-light); }
        .btn-create {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 12px 22px; border-radius: 10px; border: none;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white; font-weight: 700; font-size: 14px;
            cursor: pointer; text-decoration: none; transition: opacity .2s, transform .15s;
            font-family: inherit;
        }
        .btn-create:hover { opacity: .9; transform: translateY(-1px); box-shadow: 0 4px 15px rgba(46,7,63,.35); }

        /* ── SUMMARY CARDS ── */
        .summary-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
        .summary-card {
            background: white; border-radius: 14px; padding: 20px 22px;
            box-shadow: var(--shadow-sm); display: flex; align-items: center; gap: 14px;
            cursor: pointer; border: 2px solid transparent;
            transition: box-shadow .2s, border-color .2s, transform .15s;
        }
        .summary-card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }
        .summary-card.active-filter { border-color: var(--primary); }
        .summary-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; }
        .summary-icon.purple { background: #f3e8ff; }
        .summary-icon.green  { background: #f0fff4; }
        .summary-icon.amber  { background: #fffbeb; }
        .summary-icon.grey   { background: #f7fafc; }
        .summary-number { font-size: 26px; font-weight: 800; color: var(--text); line-height: 1; }
        .summary-label  { font-size: 13px; color: var(--text-light); margin-top: 3px; }

        /* ── ASSESSMENT CARDS ── */
        .assessments-grid { display: flex; flex-direction: column; gap: 16px; }
        .assessment-card {
            background: white; border-radius: 15px; padding: 20px 24px;
            border: 2px solid var(--border); display: flex; flex-direction: column; gap: 14px;
            transition: border-color .2s, box-shadow .2s;
            box-shadow: var(--shadow-sm);
        }
        .assessment-card:hover { border-color: var(--secondary); box-shadow: 0 4px 20px rgba(173,73,225,.15); }
        .assessment-card.draft-card  { border-left: 4px solid #a0aec0; }
        .assessment-card.archived-card { opacity: .7; }

        /* Card header */
        .card-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
        .card-title-group { flex: 1; }
        .card-title { font-size: 18px; font-weight: 700; color: var(--text); margin-bottom: 5px; line-height: 1.3; }
        .card-meta-sub { font-size: 13px; color: var(--text-light); }
        .card-badges { display: flex; gap: 6px; flex-shrink: 0; flex-wrap: wrap; justify-content: flex-end; }

        /* Badges */
        .badge { padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; text-transform: capitalize; white-space: nowrap; }
        .badge-easy         { background: #c6f6d5; color: #22543d; }
        .badge-medium       { background: #feebc8; color: #744210; }
        .badge-hard         { background: #fed7d7; color: #742a2a; }
        .badge-published    { background: #c6f6d5; color: #22543d; }
        .badge-draft        { background: #e2e8f0; color: #4a5568; }
        .badge-archived     { background: #f7fafc; color: #718096; }

        /* Meta row */
        .card-meta { display: flex; flex-wrap: wrap; gap: 20px; }
        .meta-item { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text-light); }

        /* Stats row */
        .card-stats { display: flex; gap: 24px; padding: 12px 16px; background: #f9fafb; border-radius: 10px; }
        .stat-box { display: flex; flex-direction: column; align-items: center; gap: 2px; min-width: 60px; }
        .stat-box-num { font-size: 20px; font-weight: 800; color: var(--primary); }
        .stat-box-lbl { font-size: 11px; color: var(--text-light); text-align: center; }

        /* Avg score bar */
        .score-section { display: flex; flex-direction: column; gap: 6px; }
        .score-label { display: flex; justify-content: space-between; font-size: 13px; }
        .score-label span:first-child { color: var(--text-light); }
        .score-label span:last-child  { font-weight: 700; color: var(--text); }
        .score-bar { height: 8px; background: #e2e8f0; border-radius: 10px; overflow: hidden; }
        .score-bar-fill { height: 100%; border-radius: 10px; transition: width .6s; }
        .score-bar-fill.good { background: linear-gradient(90deg, #38a169, #68d391); }
        .score-bar-fill.avg  { background: linear-gradient(90deg, #d69e2e, #f6e05e); }
        .score-bar-fill.low  { background: linear-gradient(90deg, #e53e3e, #fc8181); }

        /* Action buttons */
        .card-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn-action {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 9px 16px; border-radius: 8px; border: none;
            font-size: 13px; font-weight: 600; cursor: pointer;
            transition: opacity .2s, transform .15s; font-family: inherit;
            text-decoration: none;
        }
        .btn-action:hover { opacity: .88; transform: translateY(-1px); }
        .btn-edit     { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; }
        .btn-results  { background: #ebf8ff; color: #2b6cb0; border: 1.5px solid #bee3f8; }
        .btn-results:hover { background: #bee3f8; }
        .btn-publish  { background: #f0fff4; color: #276749; border: 1.5px solid #9ae6b4; }
        .btn-publish:hover { background: #9ae6b4; }
        .btn-delete   { background: #fff5f5; color: #c53030; border: 1.5px solid #fed7d7; margin-left: auto; }
        .btn-delete:hover { background: #fed7d7; }
        .btn-download { background: #faf5ff; color: #6b21a8; border: 1.5px solid #e9d5ff; }
        .btn-download:hover { background: #e9d5ff; }
        .btn-print    { background: #f0fdf4; color: #166534; border: 1.5px solid #bbf7d0; }
        .btn-print:hover { background: #bbf7d0; }

        /* State messages */
        .state-message {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 12px; padding: 64px 20px; border-radius: 16px; text-align: center;
        }
        .state-message .state-icon { font-size: 48px; }
        .state-message h3 { font-size: 18px; font-weight: 700; color: var(--text); }
        .state-message p  { font-size: 14px; color: var(--text-light); }
        .state-empty { background: white; box-shadow: var(--shadow-sm); }
        .state-error { background: #fff5f5; }
        .state-error h3, .state-error p { color: #c53030; }
        .hidden { display: none !important; }

        /* ── RESULTS MODAL ── */
        .modal-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,0.5);
            z-index: 2000; display: none; align-items: center; justify-content: center;
            padding: 20px;
        }
        .modal-overlay.show { display: flex; }
        .modal {
            background: white; border-radius: 16px; width: 100%; max-width: 900px;
            max-height: 88vh; overflow: hidden; display: flex; flex-direction: column;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .modal-header {
            padding: 20px 28px; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white; border-radius: 16px 16px 0 0;
        }
        .modal-title { font-size: 18px; font-weight: 700; }
        .modal-subtitle { font-size: 13px; opacity: .8; margin-top: 3px; }
        .modal-close { background: rgba(255,255,255,.15); border: none; color: white; width: 34px; height: 34px; border-radius: 8px; font-size: 18px; cursor: pointer; transition: background .2s; display: flex; align-items: center; justify-content: center; }
        .modal-close:hover { background: rgba(255,255,255,.3); }
        .modal-toolbar {
            padding: 14px 20px; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        }
        .modal-toolbar input { flex: 1; min-width: 180px; padding: 8px 14px; border: 2px solid var(--border); border-radius: 8px; font-family: inherit; font-size: 13px; outline: none; transition: border-color .2s; }
        .modal-toolbar input:focus { border-color: var(--secondary); }
        .modal-toolbar select { padding: 8px 12px; border: 2px solid var(--border); border-radius: 8px; font-family: inherit; font-size: 13px; outline: none; cursor: pointer; }
        .btn-modal-action { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; font-family: inherit; transition: opacity .2s; }
        .btn-modal-action:hover { opacity: .85; }
        .btn-csv     { background: #276749; color: white; border: none; }
        .btn-pdf-dl  { background: var(--primary); color: white; border: none; }
        .btn-modal-print { background: #2c5282; color: white; border: none; }
        .modal-body { padding: 0; overflow-y: auto; flex: 1; }
        .results-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .results-table thead th { padding: 12px 16px; background: #f7fafc; font-weight: 700; color: var(--text-light); font-size: 12px; text-transform: uppercase; letter-spacing: .04em; border-bottom: 2px solid var(--border); text-align: left; position: sticky; top: 0; }
        .results-table tbody td { padding: 13px 16px; border-bottom: 1px solid #f0f4f8; vertical-align: middle; }
        .results-table tbody tr:hover { background: #f9fafb; }
        .result-rank { font-weight: 700; color: var(--primary); }
        .result-name { font-weight: 600; color: var(--text); }
        .result-email { font-size: 12px; color: var(--text-light); }
        .result-score { font-weight: 700; font-size: 15px; }
        .result-score.pass { color: #276749; }
        .result-score.fail { color: #c53030; }
        .result-mini-bar { height: 6px; background: #e2e8f0; border-radius: 10px; overflow: hidden; margin-top: 4px; min-width: 80px; }
        .result-mini-fill { height: 100%; border-radius: 10px; }
        .result-mini-fill.pass { background: linear-gradient(90deg, #38a169, #68d391); }
        .result-mini-fill.fail { background: linear-gradient(90deg, #e53e3e, #fc8181); }
        .modal-footer { padding: 14px 20px; border-top: 1px solid var(--border); font-size: 13px; color: var(--text-light); }

        /* ── RESPONSIVE ── */
        @media (max-width: 1100px) { .summary-row { grid-template-columns: repeat(2,1fr); } }
        @media (max-width: 900px)  { .nav-center { display: none; } .left-sidebar { display: none; } .container { padding: 15px; } .profile-name { display: none; } }
        @media (max-width: 600px)  { .card-actions { flex-direction: column; } .card-stats { flex-wrap: wrap; } }
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
    <div class="nav-center">
        <div class="nav-search">
            <i class="fa fa-search sicon"></i>
            <input type="text" id="navSearchInput" placeholder="Search assessments by title or category..." autocomplete="off">
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
        <!-- Profile -->
        <div class="profile-dropdown-container">
            <button class="profile-button" id="profileBtn" onclick="toggleProfileDropdown()">
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
                    <div style="display:flex;flex-direction:column;align-items:flex-start;gap:8px;">
                        <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--secondary));display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:16px;flex-shrink:0;overflow:hidden;">
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
                    <a href="teacher-profile.php" class="dropdown-item"><span>👤</span> My Profile</a>
                    <a href="help.html" target="_blank" rel="noopener" class="dropdown-item"><span>❓</span> Help & Support</a>
                    <div class="dropdown-divider"></div>
                    <button class="dropdown-item danger" onclick="handleLogout()"><span>🚪</span> Logout</button>
                </div>
            </div>
        </div>
    </div>
</nav>

<div class="dropdown-overlay" id="dropdownOverlay" onclick="closeAllDropdowns()"></div>

<!-- ── PAGE WRAPPER ── -->
<div class="page-wrapper">
    <!-- Left Sidebar -->
    <aside class="left-sidebar">
        <span class="left-sidebar-label">Navigation</span>
        <a href="teacher-dashboard.php"><i class="fa fa-home"></i> Dashboard</a>
        <a href="teacher-assessments.php" class="active"><i class="fa fa-clipboard-list"></i> Assessments</a>
        <a href="teacher-resources.php"><i class="fa fa-folder-open"></i> Resources</a>
        <a href="manage-groups.php"><i class="fa fa-users"></i> Manage Groups</a>
        <span class="left-sidebar-section">Filter by Category</span>
        <a href="#" id="cat-all"       onclick="setSidebarCat('all',this);return false;"><i class="fa fa-layer-group"></i> All Tests</a>
        <a href="#" id="cat-aptitude"  onclick="setSidebarCat('aptitude',this);return false;"><i class="fa fa-calculator"  style="color:#4facfe"></i> Aptitude</a>
        <a href="#" id="cat-technical" onclick="setSidebarCat('technical',this);return false;"><i class="fa fa-microchip"   style="color:#9f7aea"></i> Technical</a>
        <a href="#" id="cat-coding"    onclick="setSidebarCat('coding',this);return false;"><i class="fa fa-code"         style="color:#48bb78"></i> Coding</a>
        <a href="#" id="cat-reasoning" onclick="setSidebarCat('reasoning',this);return false;"><i class="fa fa-brain"        style="color:#ed8936"></i> Reasoning</a>
        <a href="#" id="cat-english"   onclick="setSidebarCat('english',this);return false;"><i class="fa fa-book"         style="color:#fc8181"></i> English</a>
        <a href="#" id="cat-general"   onclick="setSidebarCat('general',this);return false;"><i class="fa fa-globe"        style="color:#38b2ac"></i> General</a>
        <div class="left-sidebar-bottom">
            <button onclick="handleLogout()"><i class="fa fa-sign-out-alt"></i> Logout</button>
        </div>
    </aside>

    <div class="page-content">
    <div class="container">

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-left">
                <h1>📋 My Assessments</h1>
                <p>Create, manage and track student performance on your assessments</p>
            </div>
            <a href="create-assessment.php" class="btn-create">
                <i class="fa fa-plus"></i> New Assessment
            </a>
        </div>

        <!-- Summary Cards -->
        <div class="summary-row">
            <div class="summary-card <?= $activeFilter === 'all' ? 'active-filter' : '' ?>" onclick="setFilter('all')">
                <div class="summary-icon purple">📋</div>
                <div>
                    <div class="summary-number"><?= $totalAll ?></div>
                    <div class="summary-label">Total Created</div>
                </div>
            </div>
            <div class="summary-card <?= $activeFilter === 'published' ? 'active-filter' : '' ?>" onclick="setFilter('published')">
                <div class="summary-icon green">✅</div>
                <div>
                    <div class="summary-number"><?= $totalPublished ?></div>
                    <div class="summary-label">Published</div>
                </div>
            </div>
            <div class="summary-card <?= $activeFilter === 'draft' ? 'active-filter' : '' ?>" onclick="setFilter('draft')">
                <div class="summary-icon amber">✏️</div>
                <div>
                    <div class="summary-number"><?= $totalDraft ?></div>
                    <div class="summary-label">Drafts</div>
                </div>
            </div>
            <div class="summary-card <?= $activeFilter === 'archived' ? 'active-filter' : '' ?>" onclick="setFilter('archived')">
                <div class="summary-icon grey">📦</div>
                <div>
                    <div class="summary-number"><?= $totalArchived ?></div>
                    <div class="summary-label">Archived</div>
                </div>
            </div>
        </div>

        <!-- Assessment Cards -->
        <div class="assessments-grid" id="assessmentsGrid">

        <?php if ($assessmentError): ?>
            <div class="state-message state-error">
                <div class="state-icon">⚠️</div>
                <h3>Failed to load assessments</h3>
                <p>There was a problem fetching your assessments. Please refresh the page.</p>
            </div>

        <?php elseif (empty($assessments)): ?>
            <div class="state-message state-empty">
                <div class="state-icon">📭</div>
                <h3>No assessments yet</h3>
                <p>Click "New Assessment" to create your first assessment.</p>
            </div>

        <?php else: foreach ($assessments as $a):
            $id              = (int) $a['assessment_id'];
            $studentsCompleted = (int) ($a['students_completed'] ?? 0);
            $studentsAttempted = (int) ($a['students_attempted'] ?? 0);
            $avgScore          = $a['avg_score'] !== null ? (float) $a['avg_score'] : null;
            $status            = $a['status'] ?? 'draft';

            $statusBadgeClass  = 'badge-' . $status;
            $cardExtraClass    = $status === 'draft' ? 'draft-card' : ($status === 'archived' ? 'archived-card' : '');

            $jsCategory = htmlspecialchars($a['category'] ?? '');
            $jsStatus   = htmlspecialchars($status);
            $jsTitle    = htmlspecialchars(strtolower($a['title']));
            $jsCreated  = !empty($a['created_at']) ? strtotime($a['created_at']) : 0;

            $barClass = 'good';
            if ($avgScore !== null) {
                if ($avgScore < 40) $barClass = 'low';
                elseif ($avgScore < 70) $barClass = 'avg';
            }
        ?>
            <div class="assessment-card <?= $cardExtraClass ?>"
                 data-id="<?= $id ?>"
                 data-category="<?= $jsCategory ?>"
                 data-status="<?= $jsStatus ?>"
                 data-title="<?= $jsTitle ?>"
                 data-created="<?= $jsCreated ?>">

                <!-- Header -->
                <div class="card-header">
                    <div class="card-title-group">
                        <div class="card-title"><?= htmlspecialchars($a['title']) ?></div>
                        <div class="card-meta-sub">
                            Created <?= timeAgo($a['created_at']) ?>
                            <?php if (!empty($a['category'])): ?>· <?= htmlspecialchars(ucfirst($a['category'])) ?><?php endif; ?>
                        </div>
                    </div>
                    <div class="card-badges">
                        <span class="badge badge-<?= htmlspecialchars($a['difficulty'] ?? 'medium') ?>">
                            <?= htmlspecialchars(ucfirst($a['difficulty'] ?? 'medium')) ?>
                        </span>
                        <span class="badge <?= $statusBadgeClass ?>">
                            <?= ucfirst($status) ?>
                        </span>
                    </div>
                </div>

                <!-- Meta row -->
                <div class="card-meta">
                    <div class="meta-item"><span>❓</span><?= (int)$a['question_count'] ?> Questions</div>
                    <div class="meta-item"><span>⏱️</span><?= (int)$a['duration_minutes'] ?> Minutes</div>
                    <div class="meta-item"><span>🏆</span><?= (int)$a['total_marks'] ?> Points</div>
                    <div class="meta-item"><span>🔄</span><?= (int)$a['max_attempts'] ?> Max Attempts</div>
                    <?php if (!empty($a['end_time'])): ?>
                    <div class="meta-item"><span>📅</span>Ends <?= date('d M Y', strtotime($a['end_time'])) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Student stats -->
                <div class="card-stats">
                    <div class="stat-box">
                        <div class="stat-box-num"><?= $studentsAttempted ?></div>
                        <div class="stat-box-lbl">Students Attempted</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-box-num"><?= $studentsCompleted ?></div>
                        <div class="stat-box-lbl">Completed</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-box-num"><?= $studentsAttempted > 0 ? round(($studentsCompleted / max(1,$studentsAttempted)) * 100) : 0 ?>%</div>
                        <div class="stat-box-lbl">Completion Rate</div>
                    </div>
                    <?php if ($avgScore !== null): ?>
                    <div style="flex:1;">
                        <div class="score-section">
                            <div class="score-label">
                                <span>Class Avg. Score</span>
                                <span><?= number_format($avgScore, 1) ?>%</span>
                            </div>
                            <div class="score-bar">
                                <div class="score-bar-fill <?= $barClass ?>" style="width:<?= min(100, $avgScore) ?>%"></div>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="stat-box">
                        <div class="stat-box-num" style="color:#a0aec0;">—</div>
                        <div class="stat-box-lbl">Avg. Score</div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Action buttons -->
                <div class="card-actions">
                    <a href="edit-assessment.php?id=<?= $id ?>" class="btn-action btn-edit">
                        <i class="fa fa-pen"></i> Edit
                    </a>
                    <?php if ($status === 'draft'): ?>
                    <button class="btn-action btn-publish" onclick="publishAssessment(<?= $id ?>)">
                        <i class="fa fa-upload"></i> Publish
                    </button>
                    <?php endif; ?>
                    <button class="btn-action btn-results" onclick="viewResults(<?= $id ?>, <?= htmlspecialchars(json_encode($a['title'])) ?>)">
                        <i class="fa fa-chart-bar"></i> Student Results
                    </button>
                    <button class="btn-action btn-download" onclick="downloadResults(<?= $id ?>, 'csv')">
                        <i class="fa fa-download"></i> CSV
                    </button>
                    <button class="btn-action btn-print" onclick="printResults(<?= $id ?>)">
                        <i class="fa fa-print"></i> Print
                    </button>
                    <button class="btn-action btn-delete" onclick="deleteAssessment(<?= $id ?>, <?= htmlspecialchars(json_encode($a['title'])) ?>)">
                        <i class="fa fa-trash"></i> Delete
                    </button>
                </div>

            </div>
        <?php endforeach; endif; ?>

            <!-- No results (JS filtering) -->
            <div class="state-message state-empty hidden" id="noResultsState">
                <div class="state-icon">🔍</div>
                <h3>No assessments found</h3>
                <p>Try adjusting your search or filter.</p>
            </div>

        </div><!-- /.assessments-grid -->

    </div><!-- /.container -->
    </div><!-- /.page-content -->
</div><!-- /.page-wrapper -->

<!-- ── RESULTS MODAL ── -->
<div class="modal-overlay" id="resultsModal">
    <div class="modal">
        <div class="modal-header">
            <div>
                <div class="modal-title" id="modalTitle">Student Results</div>
                <div class="modal-subtitle" id="modalSubtitle">Loading...</div>
            </div>
            <button class="modal-close" onclick="closeResultsModal()">✕</button>
        </div>
        <div class="modal-toolbar">
            <input type="text" id="modalSearch" placeholder="Search student name or email..." oninput="filterModalTable()">
            <select id="modalStatusFilter" onchange="filterModalTable()">
                <option value="all">All Results</option>
                <option value="pass">Passed</option>
                <option value="fail">Failed</option>
            </select>
            <button class="btn-modal-action btn-csv" id="btnDownloadCSV" onclick="downloadCurrentCSV()">
                <i class="fa fa-file-csv"></i> Download CSV
            </button>
            <button class="btn-modal-action btn-pdf-dl" id="btnDownloadPDF" onclick="downloadCurrentPDF()">
                <i class="fa fa-file-pdf"></i> Download PDF
            </button>
            <button class="btn-modal-action btn-modal-print" onclick="printModal()">
                <i class="fa fa-print"></i> Print
            </button>
        </div>
        <div class="modal-body" id="modalBody">
            <div style="padding:40px;text-align:center;color:#a0aec0;font-size:14px;">
                <div style="font-size:36px;margin-bottom:12px;">⏳</div>
                Loading results...
            </div>
        </div>
        <div class="modal-footer" id="modalFooter"></div>
    </div>
</div>

<script>
const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;
let currentModalAssessmentId = null;
let currentModalTitle = '';

// ── Category sidebar filter ──
const catIds = ['cat-all','cat-aptitude','cat-technical','cat-coding','cat-reasoning','cat-english','cat-general'];
let currentCategory = 'all';
let currentFilter   = '<?= $activeFilter ?>';
let currentSearch   = '';
let dateFrom = '', dateTo = '';

function setSidebarCat(cat, el) {
    currentCategory = cat;
    catIds.forEach(id => document.getElementById(id)?.classList.remove('active'));
    el.classList.add('active');
    applyFilters();
}
document.getElementById('cat-all').classList.add('active');

// ── Summary filter ──
function setFilter(f) {
    currentFilter = f;
    document.querySelectorAll('.summary-card').forEach((c, i) => {
        const filters = ['all', 'published', 'draft', 'archived'];
        c.classList.toggle('active-filter', filters[i] === f);
    });
    applyFilters();
}

// ── Search ──
document.getElementById('navSearchInput').addEventListener('input', e => {
    currentSearch = e.target.value.toLowerCase();
    applyFilters();
});

// ── Date filter ──
document.getElementById('dateFrom').addEventListener('change', e => { dateFrom = e.target.value; applyFilters(); });
document.getElementById('dateTo').addEventListener('change',   e => { dateTo   = e.target.value; applyFilters(); });

// ── Core filter ──
function applyFilters() {
    const grid   = document.getElementById('assessmentsGrid');
    const cards  = [...grid.querySelectorAll('.assessment-card')];
    const noRes  = document.getElementById('noResultsState');
    let visible  = 0;

    cards.forEach(card => {
        const cat     = card.dataset.category;
        const status  = card.dataset.status;
        const title   = card.dataset.title;
        const created = card.dataset.created > 0 ? new Date(card.dataset.created * 1000).toISOString().slice(0,10) : '';

        const matchCat    = currentCategory === 'all' || cat === currentCategory;
        const matchStatus = currentFilter   === 'all' || status === currentFilter;
        const matchSearch = !currentSearch  || title.includes(currentSearch);
        const matchFrom   = !dateFrom || !created || created >= dateFrom;
        const matchTo     = !dateTo   || !created || created <= dateTo;

        const show = matchCat && matchStatus && matchSearch && matchFrom && matchTo;
        card.classList.toggle('hidden', !show);
        if (show) visible++;
    });

    noRes.classList.toggle('hidden', visible > 0);
}

// ── Dropdowns ──
function toggleProfileDropdown() {
    document.getElementById('profileDropdown').classList.toggle('open');
    document.getElementById('dropdownOverlay').classList.toggle('show');
}
function closeAllDropdowns() {
    document.getElementById('profileDropdown').classList.remove('open');
    document.getElementById('dropdownOverlay').classList.remove('show');
}

// ── Logout ──
function handleLogout() {
    if (confirm('Are you sure you want to logout?')) window.location.href = 'logout.php';
}

// ── Publish assessment ──
function publishAssessment(id) {
    if (!confirm('Publish this assessment? Students will be able to see and take it.')) return;
    fetch('api/assessments/update-status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
        body: JSON.stringify({ assessment_id: id, status: 'published' })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) location.reload();
        else alert('Failed to publish: ' + (data.message || 'Unknown error'));
    })
    .catch(() => alert('Network error. Please try again.'));
}

// ── Delete assessment ──
function deleteAssessment(id, title) {
    if (!confirm(`Delete "${title}"?\n\nThis will permanently remove the assessment and all associated data.`)) return;
    fetch('api/assessments/delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
        body: JSON.stringify({ assessment_id: id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const card = document.querySelector(`.assessment-card[data-id="${id}"]`);
            if (card) { card.style.transition = 'opacity .3s'; card.style.opacity = '0'; setTimeout(() => card.remove(), 300); }
        } else {
            alert('Failed to delete: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(() => alert('Network error. Please try again.'));
}

// ── View Results Modal ──
function viewResults(id, title) {
    currentModalAssessmentId = id;
    currentModalTitle = title;
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalSubtitle').textContent = 'Loading student results...';
    document.getElementById('modalBody').innerHTML = '<div style="padding:40px;text-align:center;color:#a0aec0;font-size:14px;"><div style="font-size:36px;margin-bottom:12px;">⏳</div>Loading results...</div>';
    document.getElementById('resultsModal').classList.add('show');
    document.getElementById('modalSearch').value = '';
    document.getElementById('modalStatusFilter').value = 'all';

    fetch(`api/assessments/results.php?assessment_id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) throw new Error(data.message || 'Failed to load');
            renderResultsTable(data.results, data.meta);
        })
        .catch(err => {
            document.getElementById('modalBody').innerHTML = `<div style="padding:40px;text-align:center;color:#c53030;"><div style="font-size:36px;margin-bottom:12px;">⚠️</div><strong>Failed to load results</strong><br><small>${err.message}</small></div>`;
        });
}

function renderResultsTable(results, meta) {
    document.getElementById('modalSubtitle').textContent =
        `${meta?.total_students ?? results.length} students · Avg: ${meta?.avg_score ?? '—'}% · Pass rate: ${meta?.pass_rate ?? '—'}%`;
    document.getElementById('modalFooter').textContent =
        `Showing ${results.length} result(s) · Pass mark: ${meta?.passing_marks ?? '—'} / ${meta?.total_marks ?? '—'} points`;

    if (!results.length) {
        document.getElementById('modalBody').innerHTML = '<div style="padding:40px;text-align:center;color:#a0aec0;font-size:14px;"><div style="font-size:36px;margin-bottom:12px;">📭</div>No students have attempted this assessment yet.</div>';
        return;
    }

    // Rank by score desc
    results.sort((a, b) => b.percentage - a.percentage);

    let rows = '';
    results.forEach((r, i) => {
        const pass = r.percentage >= (meta?.pass_percentage ?? 40);
        rows += `<tr data-name="${(r.student_name||'').toLowerCase()}" data-email="${(r.email||'').toLowerCase()}" data-result="${pass?'pass':'fail'}">
            <td class="result-rank">#${i+1}</td>
            <td><div class="result-name">${escHtml(r.student_name || '—')}</div><div class="result-email">${escHtml(r.email || '')}</div></td>
            <td>${escHtml(r.department || '—')}</td>
            <td>
                <div class="result-score ${pass ? 'pass' : 'fail'}">${Number(r.percentage).toFixed(1)}%</div>
                <div class="result-mini-bar"><div class="result-mini-fill ${pass?'pass':'fail'}" style="width:${Math.min(100,r.percentage)}%"></div></div>
            </td>
            <td>${r.score ?? '—'} / ${meta?.total_marks ?? '—'}</td>
            <td>${pass ? '<span style="color:#276749;font-weight:700;">✅ Pass</span>' : '<span style="color:#c53030;font-weight:700;">❌ Fail</span>'}</td>
            <td style="font-size:12px;color:#718096;">${r.submitted_at ? new Date(r.submitted_at).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}) : '—'}</td>
            <td><a href="test-results.php?attempt_id=${r.attempt_id}" style="color:var(--secondary);font-size:12px;font-weight:600;text-decoration:none;">View →</a></td>
        </tr>`;
    });

    document.getElementById('modalBody').innerHTML = `
        <table class="results-table" id="resultsTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Student</th>
                    <th>Department</th>
                    <th>Score %</th>
                    <th>Marks</th>
                    <th>Result</th>
                    <th>Date</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody id="resultsTableBody">${rows}</tbody>
        </table>`;
}

function filterModalTable() {
    const search = document.getElementById('modalSearch').value.toLowerCase();
    const status = document.getElementById('modalStatusFilter').value;
    const rows   = document.querySelectorAll('#resultsTableBody tr');
    rows.forEach(row => {
        const name   = row.dataset.name   || '';
        const email  = row.dataset.email  || '';
        const result = row.dataset.result || '';
        const matchSearch = !search || name.includes(search) || email.includes(search);
        const matchStatus = status === 'all' || result === status;
        row.style.display = matchSearch && matchStatus ? '' : 'none';
    });
}

function closeResultsModal() {
    document.getElementById('resultsModal').classList.remove('show');
    currentModalAssessmentId = null;
}

// Close modal on overlay click
document.getElementById('resultsModal').addEventListener('click', function(e) {
    if (e.target === this) closeResultsModal();
});

// ── Download CSV directly (bypassing modal) ──
function downloadResults(id, type) {
    window.location.href = `api/assessments/export-results.php?assessment_id=${id}&format=${type}`;
}

// ── Download from modal ──
function downloadCurrentCSV() {
    if (currentModalAssessmentId) downloadResults(currentModalAssessmentId, 'csv');
}
function downloadCurrentPDF() {
    if (currentModalAssessmentId) window.location.href = `api/assessments/export-results.php?assessment_id=${currentModalAssessmentId}&format=pdf`;
}

// ── Print ──
function printResults(id) {
    const w = window.open(`api/assessments/export-results.php?assessment_id=${id}&format=print`, '_blank');
    if (w) w.focus();
}
function printModal() {
    const content = document.getElementById('modalBody')?.innerHTML;
    const title   = currentModalTitle;
    const w = window.open('', '_blank');
    if (!w) return;
    w.document.write(`<!DOCTYPE html><html><head>
        <title>Results - ${escHtml(title)}</title>
        <style>
            body { font-family: 'Segoe UI', sans-serif; font-size: 13px; color: #2d3748; padding: 20px; }
            h1 { font-size: 18px; margin-bottom: 4px; } p { color: #718096; font-size: 12px; margin-bottom: 20px; }
            table { width: 100%; border-collapse: collapse; }
            th { background: #f7fafc; padding: 10px 12px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; border-bottom: 2px solid #e2e8f0; }
            td { padding: 10px 12px; border-bottom: 1px solid #f0f4f8; }
            .pass { color: #276749; font-weight: 700; }
            .fail { color: #c53030; font-weight: 700; }
            .result-mini-bar, a[href] { display: none; }
            @media print { body { padding: 0; } }
        </style>
    </head><body>
        <h1>📋 ${escHtml(title)}</h1>
        <p>Printed on ${new Date().toLocaleDateString('en-GB',{day:'2-digit',month:'long',year:'numeric'})}</p>
        ${content}
    </body></html>`);
    w.document.close();
    setTimeout(() => { w.print(); }, 400);
}

// ── Escape HTML helper ──
function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Animate score bars on load ──
window.addEventListener('load', () => {
    document.querySelectorAll('.score-bar-fill').forEach(bar => {
        const w = bar.style.width;
        bar.style.width = '0';
        setTimeout(() => { bar.style.width = w; }, 150);
    });
    // Apply initial URL category if set
    const cat = '<?= $activeCategory ?>';
    if (cat !== 'all') {
        currentCategory = cat;
        const el = document.getElementById('cat-' + cat);
        if (el) { catIds.forEach(id => document.getElementById(id)?.classList.remove('active')); el.classList.add('active'); }
        applyFilters();
    }
});


</script>
</body>
</html>
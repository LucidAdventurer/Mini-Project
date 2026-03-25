<?php
/* ========================================
 * PRACTICE TESTS PAGE  –  home.php
 * Accessible to: guest, student, teacher, admin
 * Tests fetched from DB: visibility='public', status='published',
 *   created_by a user with role IN ('admin','teacher')
 * Inline MCQ test-taking modal with results on same page.
 * ======================================== */

require_once "config.php";
require_once "db-guard.php";

// ── Detect session without forcing login ──────────────────────────────────
$isLoggedIn   = false;
$userRole     = 'guest';
$userName     = '';
$userEmail    = '';
$userInitials = '';

if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
    $sid   = (int)$_SESSION['user_id'];
    $stype = $_SESSION['role'];

    $chk = safePreparedQuery(
        $conn,
        "SELECT user_id, full_name, email, role, is_active
         FROM users
         WHERE user_id = ? AND role = ? AND is_active = 1",
        "is", [$sid, $stype]
    );

    if ($chk['success'] && $chk['result']) {
        $row = $chk['result']->fetch_assoc();
        $chk['result']->free();

        if ($row) {
            $isLoggedIn   = true;
            $userRole     = $row['role'];
            $userName     = $row['full_name'];
            $userEmail    = $row['email'];
            $userInitials = strtoupper(substr($userName, 0, 2));
        } else {
            session_destroy();
        }
    }
}

// ── Fetch public published assessments created by admin or teacher ────────
// Schema: assessments.visibility = 'public', assessments.status = 'published'
// Join users to ensure created_by is an admin or teacher.
// Also pull question count from questions table.
$assessments = [];

$aQuery = safePreparedQuery(
    $conn,
    "SELECT a.assessment_id,
            a.title,
            a.description,
            a.category,
            a.difficulty,
            a.duration_minutes,
            a.total_marks,
            a.passing_marks,
            a.max_attempts,
            a.randomize_questions,
            a.randomize_options,
            u.full_name   AS creator_name,
            u.role        AS creator_role,
            COUNT(q.question_id) AS question_count
     FROM assessments a
     INNER JOIN users u ON u.user_id = a.created_by
     LEFT  JOIN questions q ON q.assessment_id = a.assessment_id
     WHERE a.visibility = 'public'
       AND a.status     = 'published'
       AND u.role       IN ('admin', 'teacher')
       AND u.is_active  = 1
       AND (a.start_time IS NULL OR a.start_time <= NOW())
       AND (a.end_time   IS NULL OR a.end_time   >= NOW())
     GROUP BY a.assessment_id
     ORDER BY a.created_at DESC",
    "", []
);

if ($aQuery['success'] && $aQuery['result']) {
    while ($row = $aQuery['result']->fetch_assoc()) {
        $assessments[] = $row;
    }
    $aQuery['result']->free();
}

// ── Role → dashboard mapping ──────────────────────────────────────────────
$dashboardMap = [
    'student' => 'student-dashboard.php',
    'teacher' => 'teacher-dashboard.php',
    'admin'   => 'admin-dashboard.php',
];
$dashboardUrl   = $dashboardMap[$userRole] ?? null;
$dashboardLabel = match($userRole) {
    'student' => 'Student Dashboard',
    'teacher' => 'Teacher Dashboard',
    'admin'   => 'Admin Dashboard',
    default   => null,
};

// ── Auto-filter via ?category= query param ────────────────────────────────
$validCategories = ['aptitude', 'verbal', 'logical', 'technical'];
$initialCategory = 'all';
if (!empty($_GET['category'])) {
    $candidate = strtolower(trim($_GET['category']));
    if (in_array($candidate, $validCategories, true)) {
        $initialCategory = $candidate;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Free Online Practice Tests – PREPAURA Placement Training Platform">
    <title>Practice Tests – PREPAURA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
    <style>
        /* ── Design tokens ── */
        :root {
            --p:         #0b6e72;
            --p-dark:    #084f52;
            --p-glow:    #0d9488;
            --p-light:   #ccfbf1;
            --p-xlight:  #f0fdfa;
            --ink:       #0d1f22;
            --ink-2:     #334e55;
            --ink-3:     #607d83;
            --ink-4:     #94b4b9;
            --surface:   #ffffff;
            --surface-2: #f4fafa;
            --surface-3: #e8f5f5;
            --border:    #d0e8e8;
            --border-2:  #b0d4d4;
            --red:       #e53935;
            --green:     #16a34a;
            --amber:     #d97706;
            --sh-sm:  0 1px 3px rgba(11,110,114,.08), 0 1px 2px rgba(11,110,114,.04);
            --sh-md:  0 4px 20px rgba(11,110,114,.12), 0 2px 8px rgba(11,110,114,.06);
            --sh-lg:  0 12px 40px rgba(11,110,114,.18), 0 4px 16px rgba(11,110,114,.08);
            --sh-xl:  0 24px 64px rgba(0,0,0,.22);
            --r-sm: 8px; --r: 12px; --r-lg: 18px; --r-xl: 24px;
            --font-display: 'DM Serif Display', Georgia, serif;
            --font-ui: 'Sora', system-ui, sans-serif;
        }

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        html { scroll-behavior: smooth; }

        body {
            font-family: var(--font-ui);
            background: var(--surface);
            color: var(--ink);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }

        /* ════════════════════════════════════════
           NAVBAR
        ════════════════════════════════════════ */
        .navbar {
            position: sticky; top: 0; z-index: 1000;
            background: rgba(255,255,255,0.88);
            backdrop-filter: blur(18px) saturate(1.6);
            -webkit-backdrop-filter: blur(18px) saturate(1.6);
            border-bottom: 1px solid var(--border);
            box-shadow: var(--sh-sm);
        }
        .nav-container {
            max-width: 1240px; margin: 0 auto; padding: 0 28px;
            display: flex; align-items: center; justify-content: space-between; height: 66px;
        }
        .navbar-brand {
            display: flex; align-items: center; gap: 11px;
            text-decoration: none; color: var(--ink); flex-shrink: 0;
        }
        .brand-logo { width: 38px; height: 38px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .brand-logo img { width: 38px; height: 38px; object-fit: contain; }
        .brand-text { display: flex; flex-direction: column; gap: 1px; }
        .brand-name  { font-size: 17px; font-weight: 800; letter-spacing: 0.06em; color: var(--p); line-height: 1.1; }
        .brand-tagline { font-size: 9.5px; font-weight: 500; color: var(--ink-3); letter-spacing: 0.04em; line-height: 1; }

        .nav-search { flex: 1; max-width: 380px; margin: 0 28px; position: relative; }
        .search-input {
            width: 100%; padding: 9px 42px 9px 16px;
            border: 1.5px solid var(--border); border-radius: 50px;
            font-size: 13.5px; font-family: var(--font-ui);
            background: var(--surface-2); color: var(--ink);
            transition: all 0.2s; outline: none;
        }
        .search-input::placeholder { color: var(--ink-4); }
        .search-input:focus {
            border-color: var(--p); background: var(--surface);
            box-shadow: 0 0 0 3px rgba(11,110,114,.12);
        }
        .search-icon {
            position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
            color: var(--ink-4); font-size: 15px; pointer-events: none;
        }

        /* profile dropdown */
        .profile-dropdown-container { position: relative; }
        .profile-button {
            display: flex; align-items: center; gap: 9px; padding: 6px 14px 6px 6px;
            background: var(--surface-2); border: 1.5px solid var(--border);
            border-radius: 50px; cursor: pointer; transition: all 0.2s;
            font-family: var(--font-ui);
        }
        .profile-button:hover { background: var(--surface-3); border-color: var(--border-2); box-shadow: var(--sh-sm); }
        .profile-avatar {
            width: 32px; height: 32px;
            background: linear-gradient(135deg, var(--p), var(--p-dark));
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 12px; flex-shrink: 0;
            letter-spacing: 0.02em;
        }
        .profile-name { font-weight: 600; font-size: 13px; color: var(--ink); }
        .dropdown-arrow { font-size: 10px; color: var(--ink-3); }
        .profile-dropdown {
            position: absolute; top: calc(100% + 10px); right: 0;
            background: white; border-radius: var(--r-lg); box-shadow: var(--sh-xl); min-width: 240px;
            opacity: 0; visibility: hidden; transform: translateY(-6px) scale(0.98);
            transition: 0.22s cubic-bezier(.22,1,.36,1); z-index: 1001;
            border: 1px solid var(--border);
        }
        .profile-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
        .dropdown-header {
            padding: 16px 18px; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 12px;
        }
        .dropdown-header-avatar {
            width: 40px; height: 40px;
            background: linear-gradient(135deg, var(--p), var(--p-dark));
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 15px; flex-shrink: 0;
        }
        .dropdown-header-name { font-size: 14px; font-weight: 700; color: var(--ink); margin-bottom: 2px; }
        .dropdown-header-email { font-size: 11.5px; color: var(--ink-3); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 155px; }
        .dropdown-role-badge { display: inline-block; margin-top: 5px; padding: 2px 9px; border-radius: 20px; font-size: 10.5px; font-weight: 700; text-transform: capitalize; letter-spacing: 0.03em; }
        .role-student { background: #dbeafe; color: #1e40af; }
        .role-teacher { background: #dcfce7; color: #15803d; }
        .role-admin   { background: #fef3c7; color: #a16207; }
        .dropdown-menu { padding: 6px 0; }
        .dropdown-item {
            display: flex; align-items: center; gap: 11px; padding: 10px 18px;
            color: var(--ink); text-decoration: none; font-size: 13.5px; font-weight: 500;
            cursor: pointer; border: none; background: none; width: 100%; text-align: left;
            font-family: var(--font-ui); transition: background 0.15s;
        }
        .dropdown-item:hover { background: var(--surface-2); }
        .dropdown-item-icon { font-size: 15px; width: 20px; text-align: center; }
        .dropdown-divider { height: 1px; background: var(--border); margin: 4px 0; }
        .dropdown-item.logout { color: var(--red); }
        .dropdown-item.logout:hover { background: #fff5f5; }
        .dropdown-overlay { position: fixed; inset: 0; background: transparent; z-index: 999; display: none; }
        .dropdown-overlay.show { display: block; }

        /* role banner */
        .role-banner {
            background: var(--p-xlight); border-bottom: 1px solid var(--border);
            padding: 8px 28px; display: flex; align-items: center; justify-content: space-between;
            font-size: 13px; color: var(--ink-2);
        }
        .role-banner-left { display: flex; align-items: center; gap: 8px; }
        .back-dashboard-btn {
            display: inline-flex; align-items: center; gap: 6px; padding: 6px 16px;
            background: var(--p); color: white; border: none; border-radius: 50px;
            font-size: 12.5px; font-weight: 600; font-family: var(--font-ui);
            cursor: pointer; text-decoration: none; transition: all 0.2s; letter-spacing: 0.02em;
        }
        .back-dashboard-btn:hover { background: var(--p-dark); box-shadow: var(--sh-md); transform: translateY(-1px); }

        /* ════════════════════════════════════════
           HERO
        ════════════════════════════════════════ */
        .hero-section {
            position: relative; overflow: hidden;
            background: linear-gradient(145deg, var(--p-dark) 0%, var(--p) 55%, #0f9186 100%);
            color: white; padding: 90px 28px 72px; text-align: center;
        }
        /* Decorative mesh blobs */
        .hero-section::before {
            content: ''; position: absolute; top: -80px; right: -80px;
            width: 420px; height: 420px; border-radius: 50%;
            background: radial-gradient(circle, rgba(255,255,255,.10) 0%, transparent 70%);
            pointer-events: none;
        }
        .hero-section::after {
            content: ''; position: absolute; bottom: -60px; left: -60px;
            width: 340px; height: 340px; border-radius: 50%;
            background: radial-gradient(circle, rgba(20,255,236,.08) 0%, transparent 70%);
            pointer-events: none;
        }
        /* Subtle dot-grid overlay */
        .hero-grid {
            position: absolute; inset: 0; pointer-events: none;
            background-image: radial-gradient(circle, rgba(255,255,255,.12) 1px, transparent 1px);
            background-size: 28px 28px;
            mask-image: radial-gradient(ellipse 80% 70% at 50% 50%, black, transparent);
            -webkit-mask-image: radial-gradient(ellipse 80% 70% at 50% 50%, black, transparent);
        }
        .hero-container { max-width: 760px; margin: 0 auto; position: relative; z-index: 1; }
        .hero-eyebrow {
            display: inline-flex; align-items: center; gap: 8px;
            background: rgba(255,255,255,.14); backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,.22); border-radius: 50px;
            padding: 5px 16px; font-size: 12px; font-weight: 600; letter-spacing: 0.06em;
            text-transform: uppercase; color: rgba(255,255,255,.9); margin-bottom: 24px;
        }
        .hero-eyebrow-dot { width: 6px; height: 6px; border-radius: 50%; background: #4ade80; flex-shrink: 0; }
        .hero-title {
            font-family: var(--font-display); font-size: clamp(38px, 6vw, 58px);
            font-weight: 400; line-height: 1.1; letter-spacing: -0.01em;
            margin-bottom: 18px; color: #fff;
        }
        .hero-title em { font-style: italic; color: rgba(255,255,255,.75); }
        .hero-subtitle { font-size: 17px; margin-bottom: 0; opacity: 0.82; max-width: 500px; margin: 0 auto; font-weight: 400; line-height: 1.65; }
        .hero-stats {
            display: flex; justify-content: center; gap: 0; margin-top: 52px;
            background: rgba(255,255,255,.1); backdrop-filter: blur(12px);
            border: 1px solid rgba(255,255,255,.18); border-radius: var(--r-lg);
            overflow: hidden; max-width: 540px; margin-left: auto; margin-right: auto;
        }
        .stat-box {
            flex: 1; text-align: center; padding: 20px 16px; position: relative;
        }
        .stat-box + .stat-box::before {
            content: ''; position: absolute; left: 0; top: 20%; height: 60%;
            width: 1px; background: rgba(255,255,255,.2);
        }
        .stat-number { font-size: 30px; font-weight: 800; display: block; margin-bottom: 3px; letter-spacing: -0.02em; }
        .stat-label { font-size: 11.5px; opacity: 0.75; letter-spacing: 0.04em; font-weight: 500; text-transform: uppercase; }

        /* ════════════════════════════════════════
           BREADCRUMB
        ════════════════════════════════════════ */
        .breadcrumb { background: var(--surface-2); padding: 12px 0; border-bottom: 1px solid var(--border); }
        .breadcrumb-container {
            max-width: 1240px; margin: 0 auto; padding: 0 28px;
            display: flex; align-items: center; gap: 7px; font-size: 13px; color: var(--ink-3);
        }
        .breadcrumb-link { color: var(--ink-3); text-decoration: none; transition: color 0.2s; font-weight: 500; }
        .breadcrumb-link:hover { color: var(--p); }

        /* ════════════════════════════════════════
           MAIN / SECTION HEADER
        ════════════════════════════════════════ */
        .main-container { max-width: 1240px; margin: 0 auto; padding: 52px 28px; }
        .section-header { margin-bottom: 36px; }
        .section-title {
            font-family: var(--font-display); font-size: 32px; font-weight: 400;
            margin-bottom: 8px; color: var(--ink); letter-spacing: -0.01em;
        }
        .section-subtitle { font-size: 15px; color: var(--ink-3); font-weight: 400; }

        /* ════════════════════════════════════════
           FILTERS
        ════════════════════════════════════════ */
        .filters-bar {
            display: flex; gap: 10px; margin-bottom: 36px; flex-wrap: wrap; align-items: center;
            background: var(--surface-2); border: 1px solid var(--border);
            border-radius: var(--r-lg); padding: 8px 12px;
        }
        .filter-group { display: flex; gap: 4px; flex-wrap: wrap; }
        .filter-separator { width: 1px; background: var(--border); height: 28px; margin: 0 6px; align-self: center; }
        .filter-btn {
            padding: 7px 16px; border: 1.5px solid transparent; border-radius: 50px;
            background: transparent; color: var(--ink-2); font-size: 13px; font-weight: 600;
            cursor: pointer; transition: all 0.18s; font-family: var(--font-ui); letter-spacing: 0.01em;
        }
        .filter-btn:hover { background: white; border-color: var(--border); color: var(--p); box-shadow: var(--sh-sm); }
        .filter-btn.active {
            background: var(--p); color: white; border-color: var(--p);
            box-shadow: 0 2px 8px rgba(11,110,114,.35);
        }

        /* ════════════════════════════════════════
           TEST GRID & CARDS
        ════════════════════════════════════════ */
        .tests-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 22px; margin-bottom: 52px;
        }
        .test-card {
            background: var(--surface);
            border: 1.5px solid var(--border);
            border-radius: var(--r-lg); padding: 26px;
            transition: all 0.22s cubic-bezier(.22,1,.36,1);
            position: relative; overflow: hidden;
            display: flex; flex-direction: column;
        }
        /* Subtle top accent line by category */
        .test-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
            background: var(--border); border-radius: var(--r-lg) var(--r-lg) 0 0;
            transition: background 0.2s;
        }
        .test-card[data-category="aptitude"]::before  { background: #3b82f6; }
        .test-card[data-category="verbal"]::before    { background: #ec4899; }
        .test-card[data-category="logical"]::before   { background: #22c55e; }
        .test-card[data-category="technical"]::before { background: #f59e0b; }
        .test-card:hover {
            box-shadow: var(--sh-lg); transform: translateY(-3px);
            border-color: var(--border-2);
        }
        .test-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; }
        .test-category {
            padding: 4px 11px; border-radius: 50px; font-size: 11px;
            font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
        }
        .category-aptitude  { background: #eff6ff; color: #1d4ed8; }
        .category-verbal    { background: #fdf2f8; color: #9d174d; }
        .category-logical   { background: #f0fdf4; color: #15803d; }
        .category-technical { background: #fffbeb; color: #b45309; }
        .difficulty-badge {
            padding: 4px 10px; border-radius: 50px; font-size: 10.5px; font-weight: 700;
            letter-spacing: 0.04em; text-transform: uppercase;
        }
        .difficulty-easy   { background: #dcfce7; color: #166534; }
        .difficulty-medium { background: #ffedd5; color: #9a3412; }
        .difficulty-hard   { background: #fee2e2; color: #991b1b; }
        .test-title {
            font-size: 17px; font-weight: 700; margin-bottom: 8px; line-height: 1.4;
            color: var(--ink); letter-spacing: -0.01em;
        }
        .test-description { font-size: 13.5px; color: var(--ink-3); margin-bottom: 14px; line-height: 1.55; flex: 1; }
        .creator-tag {
            font-size: 11px; color: var(--ink-4); margin-bottom: 14px;
            display: flex; align-items: center; gap: 5px;
        }
        .creator-tag::before { content: ''; width: 12px; height: 1px; background: var(--border-2); }
        .test-meta {
            display: flex; gap: 0; margin-bottom: 18px; flex-wrap: wrap;
            background: var(--surface-2); border-radius: var(--r-sm);
            border: 1px solid var(--border); overflow: hidden;
        }
        .meta-item {
            display: flex; align-items: center; gap: 5px;
            padding: 7px 12px; font-size: 12px; color: var(--ink-2); font-weight: 600;
            border-right: 1px solid var(--border); flex: 1; justify-content: center;
        }
        .meta-item:last-child { border-right: none; }
        .test-actions { display: flex; gap: 0; margin-top: auto; }
        .btn-start {
            flex: 1; padding: 11px 20px;
            background: linear-gradient(135deg, var(--p) 0%, var(--p-glow) 100%);
            color: white; border: none; border-radius: var(--r);
            font-weight: 700; font-size: 13.5px; font-family: var(--font-ui);
            cursor: pointer; transition: all 0.2s; letter-spacing: 0.02em;
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .btn-start::after { content: '▶'; font-size: 10px; opacity: 0.8; }
        .btn-start:hover {
            background: linear-gradient(135deg, var(--p-dark) 0%, var(--p) 100%);
            box-shadow: 0 4px 16px rgba(11,110,114,.4);
            transform: translateY(-1px);
        }

        /* pagination */
        .pagination { display: flex; justify-content: center; align-items: center; gap: 6px; margin-top: 48px; }
        .page-btn {
            padding: 8px 14px; border: 1.5px solid var(--border); border-radius: var(--r-sm);
            background: var(--surface); color: var(--ink); font-size: 13.5px;
            cursor: pointer; transition: all 0.2s; min-width: 40px; text-align: center;
            font-family: var(--font-ui); font-weight: 600;
        }
        .page-btn:hover { background: var(--surface-2); border-color: var(--border-2); }
        .page-btn.active { background: var(--p); color: white; border-color: var(--p); box-shadow: 0 2px 8px rgba(11,110,114,.3); }

        /* ════════════════════════════════════════
           FOOTER
        ════════════════════════════════════════ */
        .footer {
            background: var(--ink); color: rgba(255,255,255,.65);
            padding: 52px 28px 32px; margin-top: 80px;
        }
        .footer-container { max-width: 1240px; margin: 0 auto; display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 36px; }
        .footer-section h3 { font-size: 13px; font-weight: 700; margin-bottom: 16px; color: white; letter-spacing: 0.06em; text-transform: uppercase; }
        .footer-links { list-style: none; }
        .footer-links li { margin-bottom: 9px; }
        .footer-links a { color: rgba(255,255,255,.5); text-decoration: none; font-size: 13.5px; transition: color 0.2s; }
        .footer-links a:hover { color: white; }
        .footer-bottom {
            max-width: 1240px; margin: 36px auto 0; padding-top: 24px;
            border-top: 1px solid rgba(255,255,255,.1);
            text-align: center; font-size: 13px; color: rgba(255,255,255,.35);
        }

        /* ════════════════════════════════════════
           EMPTY STATE
        ════════════════════════════════════════ */
        .empty-state {
            grid-column: 1 / -1; text-align: center; padding: 80px 20px;
            color: var(--ink-3);
        }
        .empty-state-icon { font-size: 52px; margin-bottom: 20px; display: block; }
        .empty-state-title { font-family: var(--font-display); font-size: 24px; font-weight: 400; margin-bottom: 10px; color: var(--ink); }
        .empty-state-sub   { font-size: 15px; color: var(--ink-3); }

        /* ════════════════════════════════════════
           TEST MODAL
        ════════════════════════════════════════ */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(5,20,22,.65);
            backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
            z-index: 2000; align-items: flex-start; justify-content: center;
            overflow-y: auto; padding: 28px 16px;
        }
        .modal-overlay.open { display: flex; }
        .modal {
            background: white; border-radius: var(--r-xl); width: 100%; max-width: 800px;
            box-shadow: var(--sh-xl); margin: auto;
            display: flex; flex-direction: column; overflow: hidden;
            border: 1px solid rgba(255,255,255,.5);
        }

        /* modal header */
        .modal-header {
            background: linear-gradient(135deg, var(--p-dark) 0%, var(--p) 60%, #0e9285 100%);
            color: white; padding: 22px 28px;
            display: flex; justify-content: space-between; align-items: flex-start; gap: 16px;
            position: relative; overflow: hidden;
        }
        .modal-header::after {
            content: ''; position: absolute; inset: 0;
            background-image: radial-gradient(circle, rgba(255,255,255,.08) 1px, transparent 1px);
            background-size: 22px 22px; pointer-events: none;
        }
        .modal-title { font-size: 19px; font-weight: 700; margin-bottom: 6px; position: relative; z-index: 1; letter-spacing: -0.01em; }
        .modal-meta  {
            font-size: 12.5px; opacity: 0.8; display: flex; gap: 0; flex-wrap: wrap;
            position: relative; z-index: 1;
        }
        .modal-close-btn {
            background: rgba(255,255,255,.15); border: 1px solid rgba(255,255,255,.25);
            color: white; border-radius: var(--r-sm); padding: 7px 12px; font-size: 16px;
            cursor: pointer; transition: all 0.2s; flex-shrink: 0; font-family: var(--font-ui);
            position: relative; z-index: 1;
        }
        .modal-close-btn:hover { background: rgba(255,255,255,.28); }

        /* progress bar */
        .modal-progress-wrap { background: #e0f0f0; height: 5px; }
        .modal-progress-bar  { height: 5px; background: linear-gradient(90deg, #22c55e, #4ade80); transition: width 0.4s ease; }

        /* timer */
        .timer-bar {
            background: var(--surface-2); border-bottom: 1px solid var(--border);
            padding: 10px 28px; display: flex; align-items: center; justify-content: space-between;
            font-size: 13.5px; font-weight: 600; color: var(--ink);
        }
        .timer-display { font-variant-numeric: tabular-nums; display: flex; align-items: center; gap: 7px; }
        .timer-display.warning { color: var(--red); }
        .timer-icon { font-size: 15px; }
        .q-counter { color: var(--ink-3); font-weight: 500; font-size: 13px; }

        /* questions panel */
        .modal-body { padding: 28px; flex: 1; }

        /* question navigator */
        .q-nav {
            display: flex; flex-wrap: wrap; gap: 7px; margin-bottom: 26px;
            padding: 14px 16px; background: var(--surface-2);
            border-radius: var(--r); border: 1px solid var(--border);
        }
        .q-nav-dot {
            width: 34px; height: 34px; border-radius: 50%;
            border: 2px solid var(--border); background: white;
            font-size: 12px; font-weight: 700; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: all 0.18s; font-family: var(--font-ui); color: var(--ink-2);
        }
        .q-nav-dot:hover { border-color: var(--p); color: var(--p); }
        .q-nav-dot.answered    { background: var(--p); border-color: var(--p); color: white; }
        .q-nav-dot.current     { border-color: var(--p); box-shadow: 0 0 0 3px rgba(11,110,114,.18); background: white; color: var(--p); }
        .q-nav-dot.correct-dot { background: var(--green); border-color: var(--green); color: white; }
        .q-nav-dot.wrong-dot   { background: var(--red); border-color: var(--red); color: white; }
        .q-nav-dot.skipped-dot { background: #9ca3af; border-color: #9ca3af; color: white; }

        /* single question */
        .question-block { animation: fadeSlide 0.22s cubic-bezier(.22,1,.36,1); }
        @keyframes fadeSlide {
            from { opacity: 0; transform: translateX(10px); }
            to   { opacity: 1; transform: translateX(0); }
        }
        .question-number {
            font-size: 11px; font-weight: 700; color: var(--p); text-transform: uppercase;
            letter-spacing: 0.08em; margin-bottom: 10px;
            display: flex; align-items: center; gap: 8px;
        }
        .question-number::after { content: ''; flex: 1; height: 1px; background: var(--border); }
        .question-text {
            font-size: 17px; font-weight: 600; line-height: 1.55;
            margin-bottom: 24px; color: var(--ink);
        }
        .options-list { display: flex; flex-direction: column; gap: 10px; }
        .option-label {
            display: flex; align-items: center; gap: 14px; padding: 14px 18px;
            border: 2px solid var(--border); border-radius: var(--r); cursor: pointer;
            transition: all 0.15s; user-select: none; background: white;
        }
        .option-label:hover { border-color: var(--p); background: var(--p-xlight); }
        .option-label input[type="radio"] { display: none; }
        .option-key {
            width: 32px; height: 32px; border-radius: 50%;
            background: var(--surface-2); border: 1.5px solid var(--border);
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; font-weight: 700; flex-shrink: 0; transition: all 0.15s; color: var(--ink-2);
        }
        .option-text { font-size: 14.5px; color: var(--ink); flex: 1; line-height: 1.45; }
        .option-label.selected { border-color: var(--p); background: var(--p-xlight); }
        .option-label.selected .option-key { background: var(--p); border-color: var(--p); color: white; }
        .option-label.result-correct { border-color: var(--green); background: #f0fdf4; }
        .option-label.result-correct .option-key { background: var(--green); border-color: var(--green); color: white; }
        .option-label.result-wrong   { border-color: var(--red); background: #fef2f2; }
        .option-label.result-wrong   .option-key { background: var(--red); border-color: var(--red); color: white; }
        .option-label.result-neutral { opacity: 0.48; }
        .option-result-tag { font-size: 11px; font-weight: 700; margin-left: auto; flex-shrink: 0; padding: 3px 9px; border-radius: 20px; }
        .tag-correct { color: var(--green); background: #dcfce7; }
        .tag-wrong   { color: var(--red);   background: #fee2e2; }

        /* explanation box */
        .explanation-box {
            margin-top: 16px; padding: 14px 18px;
            background: #fffbeb; border: 1.5px solid #fde68a;
            border-radius: var(--r); font-size: 13.5px; color: #78350f; line-height: 1.55;
            display: none;
        }
        .explanation-box.visible { display: block; }
        .explanation-label { font-weight: 700; margin-bottom: 5px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; }

        /* navigation buttons */
        .modal-nav {
            padding: 16px 28px; border-top: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center; gap: 12px;
            background: var(--surface-2);
        }
        .btn-modal-nav {
            padding: 10px 22px; border-radius: 50px; font-size: 13.5px; font-weight: 600;
            cursor: pointer; transition: all 0.2s; font-family: var(--font-ui);
            display: flex; align-items: center; gap: 6px;
        }
        .btn-nav-prev { background: white; color: var(--ink); border: 1.5px solid var(--border); }
        .btn-nav-prev:hover { background: var(--surface-2); border-color: var(--border-2); }
        .btn-nav-prev:disabled { opacity: 0.32; cursor: not-allowed; }
        .btn-nav-next { background: var(--p); color: white; border: 1.5px solid var(--p); }
        .btn-nav-next:hover { background: var(--p-dark); box-shadow: 0 3px 10px rgba(11,110,114,.35); }
        .btn-nav-submit {
            background: linear-gradient(135deg, #16a34a, #15803d);
            color: white; border: none;
            padding: 10px 24px; border-radius: 50px; font-size: 13.5px; font-weight: 700;
            cursor: pointer; transition: all 0.2s; font-family: var(--font-ui);
            display: flex; align-items: center; gap: 8px;
            box-shadow: 0 2px 8px rgba(22,163,74,.3);
        }
        .btn-nav-submit:hover { background: linear-gradient(135deg, #15803d, #166534); box-shadow: 0 4px 16px rgba(22,163,74,.45); transform: translateY(-1px); }
        .btn-nav-submit:disabled { opacity: 0.4; cursor: not-allowed; transform: none; }

        /* ── RESULTS SCREEN ── */
        .results-screen { padding: 28px; display: none; }
        .results-screen.visible { display: block; }

        .result-hero {
            text-align: center; padding: 32px 24px 28px;
            border-radius: var(--r-lg); margin-bottom: 24px; position: relative; overflow: hidden;
        }
        .result-hero.passed  { background: linear-gradient(135deg,#f0fdf4,#dcfce7); border: 1.5px solid #86efac; }
        .result-hero.failed  { background: linear-gradient(135deg,#fff1f2,#fecaca); border: 1.5px solid #fca5a5; }
        .result-emoji { font-size: 48px; margin-bottom: 12px; display: block; }
        .result-verdict { font-family: var(--font-display); font-size: 28px; font-weight: 400; margin-bottom: 6px; }
        .result-verdict.pass { color: #15803d; }
        .result-verdict.fail { color: #b91c1c; }
        .result-subtitle { font-size: 14.5px; color: var(--ink-3); }

        .result-score-ring {
            width: 116px; height: 116px; border-radius: 50%; margin: 16px auto 10px;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            border: 6px solid; box-shadow: 0 0 0 4px rgba(0,0,0,.05);
        }
        .result-score-ring.pass { border-color: var(--green); }
        .result-score-ring.fail { border-color: var(--red); }
        .ring-pct  { font-size: 28px; font-weight: 800; line-height: 1; }
        .ring-pct.pass  { color: #15803d; }
        .ring-pct.fail  { color: #b91c1c; }
        .ring-label { font-size: 11px; color: var(--ink-3); text-transform: uppercase; letter-spacing: 0.06em; }

        .result-stats {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 10px; margin-bottom: 28px;
        }
        .result-stat-card {
            background: var(--surface-2); border: 1.5px solid var(--border);
            border-radius: var(--r); padding: 16px; text-align: center;
        }
        .result-stat-value { font-size: 26px; font-weight: 800; color: var(--ink); line-height: 1.1; letter-spacing: -0.02em; }
        .result-stat-label { font-size: 11px; color: var(--ink-3); margin-top: 4px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; }

        /* review section */
        .review-section { margin-bottom: 20px; }
        .review-heading {
            font-size: 16px; font-weight: 700; margin-bottom: 14px;
            display: flex; align-items: center; gap: 8px; color: var(--ink);
        }
        .review-q-list { display: flex; flex-direction: column; gap: 10px; }
        .review-q-item { border: 1.5px solid var(--border); border-radius: var(--r); overflow: hidden; }
        .review-q-header {
            padding: 12px 16px; display: flex; align-items: center; gap: 10px;
            cursor: pointer; background: var(--surface-2); user-select: none;
            transition: background 0.15s;
        }
        .review-q-header:hover { background: var(--surface-3); }
        .review-q-status { width: 22px; height: 22px; border-radius: 50%; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; }
        .review-q-status.correct { background: var(--green); color: white; }
        .review-q-status.wrong   { background: var(--red);   color: white; }
        .review-q-status.skipped { background: #9ca3af; color: white; }
        .review-q-text { font-size: 13.5px; font-weight: 600; flex: 1; color: var(--ink); }
        .review-q-marks { font-size: 12px; font-weight: 700; flex-shrink: 0; padding: 2px 9px; border-radius: 20px; }
        .review-q-marks.positive { color: var(--green); background: #dcfce7; }
        .review-q-marks.negative { color: var(--red);   background: #fee2e2; }
        .review-q-marks.zero     { color: var(--ink-3); background: var(--surface-3); }
        .review-q-chevron { font-size: 11px; color: var(--ink-4); transition: transform 0.2s; }
        .review-q-item.open .review-q-chevron { transform: rotate(180deg); }
        .review-q-body { display: none; padding: 16px; border-top: 1px solid var(--border); background: white; }
        .review-q-item.open .review-q-body { display: block; }

        /* result action buttons */
        .result-actions {
            display: flex; gap: 10px; flex-wrap: wrap; justify-content: center;
            padding: 18px 28px; border-top: 1px solid var(--border);
            background: var(--surface-2);
        }
        .btn-retake {
            padding: 11px 28px;
            background: linear-gradient(135deg, var(--p) 0%, var(--p-glow) 100%);
            color: white; border: none; border-radius: 50px; font-weight: 700; font-size: 13.5px;
            cursor: pointer; transition: all 0.2s; font-family: var(--font-ui); letter-spacing: 0.02em;
            box-shadow: 0 2px 8px rgba(11,110,114,.3);
        }
        .btn-retake:hover { background: linear-gradient(135deg, var(--p-dark), var(--p)); box-shadow: 0 4px 16px rgba(11,110,114,.4); transform: translateY(-1px); }
        .btn-close-result {
            padding: 11px 28px; background: white; color: var(--ink);
            border: 1.5px solid var(--border); border-radius: 50px; font-weight: 600;
            font-size: 13.5px; cursor: pointer; transition: all 0.2s; font-family: var(--font-ui);
        }
        .btn-close-result:hover { background: var(--surface-2); border-color: var(--border-2); }

        /* loading spinner */
        .modal-loading {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            padding: 88px 28px; gap: 16px; color: var(--ink-3);
        }
        .spinner {
            width: 40px; height: 40px;
            border: 3px solid var(--border); border-top-color: var(--p);
            border-radius: 50%; animation: spin 0.65s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* error state */
        .modal-error { padding: 56px 28px; text-align: center; color: #b91c1c; }
        .modal-error-icon { font-size: 38px; margin-bottom: 14px; display: block; }
        .modal-error-msg { font-size: 16px; font-weight: 600; }

        /* ════════════════════════════════════════
           RESPONSIVE
        ════════════════════════════════════════ */
        @media (max-width: 768px) {
            .nav-search { display: none; }
            .hero-title { font-size: 34px; }
            .hero-stats { flex-direction: column; gap: 0; }
            .tests-grid { grid-template-columns: 1fr; }
            .filters-bar { flex-direction: column; align-items: stretch; }
            .filter-group { justify-content: flex-start; }
            .filter-separator { display: none; }
            .profile-name { display: none; }
            .modal { border-radius: var(--r-lg); }
            .modal-body { padding: 18px; }
            .modal-nav { padding: 14px 18px; gap: 8px; }
            .modal-header { padding: 16px 20px; }
            .results-screen { padding: 18px; }
            .result-actions { padding: 14px 18px; }
            .main-container { padding: 36px 18px; }
        }
    </style>
</head>
<body>

<!-- ── Navbar ── -->
<nav class="navbar">
    <div class="nav-container">
        <a href="home.php" class="navbar-brand">
            <div class="brand-logo">
                <img src="prepaura-logo.png" alt="PREPAURA Logo">
            </div>
            <div class="brand-text">
                <span class="brand-name">PREPAURA</span>
                <span class="brand-tagline">Placement Training Platform</span>
            </div>
        </a>

        <div class="nav-search">
            <input type="text" id="searchInput" class="search-input"
                   placeholder="Search tests…" oninput="handleSearch(this.value)">
            <span class="search-icon">🔍</span>
        </div>

        <div class="nav-actions">
            <?php if ($isLoggedIn): ?>
            <div class="profile-dropdown-container">
                <button class="profile-button" onclick="toggleDropdown()" aria-label="Profile menu">
                    <div class="profile-avatar"><?php echo $userInitials; ?></div>
                    <span class="profile-name"><?php echo htmlspecialchars($userName); ?></span>
                    <span class="dropdown-arrow">▼</span>
                </button>
                <div class="profile-dropdown" id="profileDropdown">
                    <div class="dropdown-header">
                        <div class="dropdown-header-avatar"><?php echo $userInitials; ?></div>
                        <div>
                            <div class="dropdown-header-name"><?php echo htmlspecialchars($userName); ?></div>
                            <div class="dropdown-header-email" title="<?php echo htmlspecialchars($userEmail); ?>"><?php echo htmlspecialchars($userEmail); ?></div>
                            <span class="dropdown-role-badge role-<?php echo $userRole; ?>"><?php echo ucfirst($userRole); ?></span>
                        </div>
                    </div>
                    <div class="dropdown-menu">
                        <?php if ($userRole === 'student'): ?>
                        <a href="student-profile.php" class="dropdown-item">
                            <span class="dropdown-item-icon">👤</span><span>My Profile</span>
                        </a>
                        <?php elseif ($userRole === 'teacher'): ?>
                        <a href="teacher-profile.php" class="dropdown-item">
                            <span class="dropdown-item-icon">👤</span><span>My Profile</span>
                        </a>
                        <?php endif; ?>
                        <?php if ($dashboardUrl): ?>
                        <a href="<?php echo $dashboardUrl; ?>" class="dropdown-item">
                            <span class="dropdown-item-icon">🏠</span><span><?php echo $dashboardLabel; ?></span>
                        </a>
                        <?php endif; ?>
                        <a href="home.php" class="dropdown-item">
                            <span class="dropdown-item-icon">📝</span><span>Practice Tests</span>
                        </a>
                        <a href="help.html" target="_blank" rel="noopener noreferrer" class="dropdown-item">
                            <span class="dropdown-item-icon">❓</span><span>Help &amp; Support</span>
                        </a>
                        <div class="dropdown-divider"></div>
                        <button onclick="confirmLogout()" class="dropdown-item logout">
                            <span class="dropdown-item-icon">🚪</span><span>Logout</span>
                        </button>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</nav>

<div class="dropdown-overlay" id="dropdownOverlay" onclick="closeDropdown()"></div>

<?php if ($isLoggedIn && $dashboardUrl): ?>
<div class="role-banner">
    <div class="role-banner-left">
        <span>Browsing as <strong><?php echo ucfirst($userRole); ?></strong></span>
    </div>
    <a href="<?php echo $dashboardUrl; ?>" class="back-dashboard-btn">← Back to <?php echo $dashboardLabel; ?></a>
</div>
<?php endif; ?>

<!-- ── Hero ── -->
<section class="hero-section">
    <div class="hero-grid" aria-hidden="true"></div>
    <div class="hero-container">
        <div class="hero-eyebrow">
            <span class="hero-eyebrow-dot"></span>
            Free Placement Practice
        </div>
        <h1 class="hero-title">Master Your<br><em>Placement Tests</em></h1>
        <p class="hero-subtitle">Practice with real test scenarios, instant results, and detailed answer review — no account needed.</p>
        <div class="hero-stats">
            <div class="stat-box">
                <span class="stat-number"><?php echo count($assessments); ?>+</span>
                <span class="stat-label">Practice Tests</span>
            </div>
            <div class="stat-box">
                <span class="stat-number">50K+</span>
                <span class="stat-label">Students</span>
            </div>
            <div class="stat-box">
                <span class="stat-number">95%</span>
                <span class="stat-label">Success Rate</span>
            </div>
        </div>
    </div>
</section>

<!-- ── Breadcrumb ── -->
<div class="breadcrumb">
    <div class="breadcrumb-container">
        <?php if ($isLoggedIn && $dashboardUrl): ?>
        <a href="<?php echo $dashboardUrl; ?>" class="breadcrumb-link"><?php echo $dashboardLabel; ?></a>
        <span>›</span>
        <?php else: ?>
        <a href="guest-dashboard.html" class="breadcrumb-link">Home</a>
        <span>›</span>
        <?php endif; ?>
        <span>All Tests</span>
    </div>
</div>

<!-- ── Main Content ── -->
<main class="main-container">
    <div class="section-header">
        <h2 class="section-title">All Practice Tests</h2>
        <p class="section-subtitle">
            <?php if ($isLoggedIn): ?>
                Choose a test to attempt. Your results will be saved to your account.
            <?php else: ?>
                Choose from our collection of tests. No account needed — start right away.
            <?php endif; ?>
        </p>
    </div>

    <!-- ── Filters ── -->
    <div class="filters-bar">
        <div class="filter-group" id="catFilterGroup">
            <button class="filter-btn <?php echo $initialCategory === 'all'       ? 'active' : ''; ?>" onclick="filterCategory('all',event)">All</button>
            <button class="filter-btn <?php echo $initialCategory === 'aptitude'  ? 'active' : ''; ?>" onclick="filterCategory('aptitude',event)">Aptitude</button>
            <button class="filter-btn <?php echo $initialCategory === 'verbal'    ? 'active' : ''; ?>" onclick="filterCategory('verbal',event)">Verbal</button>
            <button class="filter-btn <?php echo $initialCategory === 'logical'   ? 'active' : ''; ?>" onclick="filterCategory('logical',event)">Logical</button>
            <button class="filter-btn <?php echo $initialCategory === 'technical' ? 'active' : ''; ?>" onclick="filterCategory('technical',event)">Technical</button>
        </div>
        <div class="filter-separator"></div>
        <div class="filter-group" id="diffFilterGroup">
            <button class="filter-btn active" onclick="filterDifficulty('all',event)">All Levels</button>
            <button class="filter-btn" onclick="filterDifficulty('easy',event)">Easy</button>
            <button class="filter-btn" onclick="filterDifficulty('medium',event)">Medium</button>
            <button class="filter-btn" onclick="filterDifficulty('hard',event)">Hard</button>
        </div>
    </div>

    <!-- ── Test Cards ── -->
    <div class="tests-grid" id="testsGrid">

        <?php if (empty($assessments)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">📭</div>
            <div class="empty-state-title">No tests available right now.</div>
            <div class="empty-state-sub">Check back soon — new tests are added regularly.</div>
        </div>
        <?php else: ?>
        <?php foreach ($assessments as $a):
            $cat      = strtolower(htmlspecialchars($a['category'] ?? 'general'));
            $diff     = strtolower(htmlspecialchars($a['difficulty'] ?? 'medium'));
            $id       = (int)$a['assessment_id'];
            $title    = htmlspecialchars($a['title']);
            $desc     = htmlspecialchars($a['description'] ?? '');
            $duration = (int)$a['duration_minutes'];
            $qCount   = (int)$a['question_count'];
            $marks    = (int)$a['total_marks'];
            $creator  = htmlspecialchars($a['creator_name']);
            $creatorRole = htmlspecialchars($a['creator_role']);
            $catClass = in_array($cat, ['aptitude','verbal','logical','technical'])
                        ? 'category-' . $cat : 'category-aptitude';
        ?>
        <div class="test-card"
             data-category="<?php echo $cat; ?>"
             data-difficulty="<?php echo $diff; ?>"
             data-id="<?php echo $id; ?>">
            <div class="test-header">
                <span class="test-category <?php echo $catClass; ?>"><?php echo ucfirst($cat); ?></span>
                <span class="difficulty-badge difficulty-<?php echo $diff; ?>"><?php echo ucfirst($diff); ?></span>
            </div>
            <h3 class="test-title"><?php echo $title; ?></h3>
            <?php if ($desc): ?>
            <p class="test-description"><?php echo $desc; ?></p>
            <?php endif; ?>
            <div class="creator-tag">by <?php echo $creator; ?> &middot; <?php echo ucfirst($creatorRole); ?></div>
            <div class="test-meta">
                <?php if ($duration > 0): ?><span class="meta-item">⏱️ <?php echo $duration; ?> mins</span><?php endif; ?>
                <span class="meta-item">📝 <?php echo $qCount; ?> questions</span>
                <?php if ($marks > 0): ?><span class="meta-item">⭐ <?php echo $marks; ?> marks</span><?php endif; ?>
            </div>
            <div class="test-actions">
                <button class="btn-start" onclick="openTestModal(<?php echo $id; ?>)">Start Test</button>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

    </div><!-- /tests-grid -->

    <?php if (count($assessments) > 9): ?>
    <div class="pagination">
        <button class="page-btn active">1</button>
    </div>
    <?php endif; ?>
</main>

<footer class="footer">
    <div class="footer-container">
        <div class="footer-section">
            <h3>About</h3>
            <ul class="footer-links">
                <li><a href="#">About Us</a></li>
                <li><a href="#">How It Works</a></li>
                <li><a href="#">Contact</a></li>
            </ul>
        </div>
        <div class="footer-section">
            <h3>Resources</h3>
            <ul class="footer-links">
                <li><a href="#">Study Materials</a></li>
                <li><a href="home.php">Practice Tests</a></li>
                <li><a href="#">Success Stories</a></li>
            </ul>
        </div>
        <div class="footer-section">
            <h3>Support</h3>
            <ul class="footer-links">
                <li><a href="#">Help Center</a></li>
                <li><a href="#">FAQ</a></li>
                <li><a href="#">Feedback</a></li>
            </ul>
        </div>
        <div class="footer-section">
            <h3>Legal</h3>
            <ul class="footer-links">
                <li><a href="#">Privacy Policy</a></li>
                <li><a href="#">Terms of Service</a></li>
                <li><a href="#">Cookie Policy</a></li>
            </ul>
        </div>
    </div>
    <div class="footer-bottom">
        <p>© 2025 PREPAURA – Placement Training Platform. All rights reserved.</p>
    </div>
</footer>

<!-- ══════════════════════════════════════════════════════════
     TEST MODAL
══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="testModalOverlay" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal" id="testModal">

        <!-- Header (always visible) -->
        <div class="modal-header">
            <div>
                <div class="modal-title" id="modalTitle">Loading…</div>
                <div class="modal-meta" id="modalMeta"></div>
            </div>
            <button class="modal-close-btn" onclick="closeModal()" aria-label="Close test">✕</button>
        </div>

        <!-- Progress bar (test mode only) -->
        <div class="modal-progress-wrap" id="modalProgressWrap" style="display:none">
            <div class="modal-progress-bar" id="modalProgressBar" style="width:0%"></div>
        </div>

        <!-- Timer row (test mode only) -->
        <div class="timer-bar" id="timerBar" style="display:none">
            <div class="timer-display" id="timerDisplay">
                <span class="timer-icon">⏱️</span>
                <span id="timerText">--:--</span>
            </div>
            <div class="q-counter" id="qCounter"></div>
        </div>

        <!-- Loading state -->
        <div class="modal-loading" id="modalLoading">
            <div class="spinner"></div>
            <span>Loading questions…</span>
        </div>

        <!-- Error state -->
        <div class="modal-error" id="modalError" style="display:none">
            <div class="modal-error-icon">⚠️</div>
            <div class="modal-error-msg" id="modalErrorMsg">Something went wrong.</div>
        </div>

        <!-- Test body (question navigator + current question) -->
        <div class="modal-body" id="modalBody" style="display:none">
            <div class="q-nav" id="qNav"></div>
            <div class="question-block" id="questionBlock"></div>
        </div>

        <!-- Navigation buttons (test mode) -->
        <div class="modal-nav" id="modalNav" style="display:none">
            <button class="btn-modal-nav btn-nav-prev" id="btnPrev" onclick="goQuestion(currentQ-1)" disabled>← Prev</button>
            <button class="btn-nav-submit" id="btnSubmit" onclick="confirmSubmit()">
                ✅ Submit Test
            </button>
            <button class="btn-modal-nav btn-nav-next" id="btnNext" onclick="goQuestion(currentQ+1)">Next →</button>
        </div>

        <!-- Results screen -->
        <div class="results-screen" id="resultsScreen">
            <!-- filled dynamically -->
        </div>

        <!-- Result action buttons -->
        <div class="result-actions" id="resultActions" style="display:none">
            <button class="btn-retake" onclick="retakeTest()">🔄 Retake Test</button>
            <button class="btn-close-result" onclick="closeModal()">← Back to Tests</button>
        </div>

    </div>
</div>

<script>
// ══════════════════════════════════════════════════════════
//  CONSTANTS (from PHP)
// ══════════════════════════════════════════════════════════
const INITIAL_CATEGORY = <?php echo json_encode($initialCategory); ?>;

// ══════════════════════════════════════════════════════════
//  STATE
// ══════════════════════════════════════════════════════════
let activeCategory   = INITIAL_CATEGORY;
let activeDifficulty = 'all';
let currentAssessmentId = null;
let questions   = [];   // [{question_id, question_text, question_type, marks, negative_marks, explanation, options:[{option_id,option_text,is_correct,option_order}]}]
let answers     = {};   // {question_id: option_id}  (selected by user)
let currentQ    = 0;    // 0-based index
let timerSecs   = 0;
let timerHandle = null;
let testMeta    = {};   // {title, duration_minutes, total_marks, passing_marks, ...}
let submitted   = false;

// ══════════════════════════════════════════════════════════
//  NAVBAR HELPERS
// ══════════════════════════════════════════════════════════
function toggleDropdown() {
    document.getElementById('profileDropdown').classList.toggle('show');
    document.getElementById('dropdownOverlay').classList.toggle('show');
}
function closeDropdown() {
    document.getElementById('profileDropdown')?.classList.remove('show');
    document.getElementById('dropdownOverlay')?.classList.remove('show');
}
function confirmLogout() {
    closeDropdown();
    if (confirm('Are you sure you want to logout?')) window.location.href = 'logout.php';
}

// ══════════════════════════════════════════════════════════
//  FILTER + SEARCH
// ══════════════════════════════════════════════════════════
function handleSearch(query) {
    const term = query.toLowerCase();
    document.querySelectorAll('.test-card').forEach(card => {
        const title = card.querySelector('.test-title').textContent.toLowerCase();
        const desc  = card.querySelector('.test-description')?.textContent.toLowerCase() ?? '';
        const cat   = card.querySelector('.test-category').textContent.toLowerCase();
        const visible = !term || title.includes(term) || desc.includes(term) || cat.includes(term);
        card.dataset.searchHide = visible ? '' : '1';
        syncCardVisibility(card);
    });
}

function filterCategory(cat, e) {
    activeCategory = cat;
    document.querySelectorAll('#catFilterGroup .filter-btn').forEach(b => b.classList.remove('active'));
    e.target.classList.add('active');
    applyFilters();
}
function filterDifficulty(diff, e) {
    activeDifficulty = diff;
    document.querySelectorAll('#diffFilterGroup .filter-btn').forEach(b => b.classList.remove('active'));
    e.target.classList.add('active');
    applyFilters();
}
function applyFilters() {
    document.querySelectorAll('.test-card').forEach(card => {
        const catMatch  = activeCategory   === 'all' || card.dataset.category   === activeCategory;
        const diffMatch = activeDifficulty === 'all' || card.dataset.difficulty === activeDifficulty;
        card.dataset.filterHide = (catMatch && diffMatch) ? '' : '1';
        syncCardVisibility(card);
    });
}
function syncCardVisibility(card) {
    card.style.display = (card.dataset.filterHide || card.dataset.searchHide) ? 'none' : '';
}

// ══════════════════════════════════════════════════════════
//  OPEN TEST MODAL  –  fetch questions via API
// ══════════════════════════════════════════════════════════
function openTestModal(assessmentId) {
    currentAssessmentId = assessmentId;
    questions   = [];
    answers     = {};
    currentQ    = 0;
    submitted   = false;
    testMeta    = {};

    // Show overlay in loading state
    showModalState('loading');
    document.getElementById('testModalOverlay').classList.add('open');
    document.getElementById('modalTitle').textContent = 'Loading…';
    document.getElementById('modalMeta').textContent  = '';
    document.body.style.overflow = 'hidden';

    // Fetch assessment meta + questions
    fetch('api/guest/get-test-questions.php?assessment_id=' + assessmentId)
        .then(r => r.json())
        .then(data => {
            if (!data.success) throw new Error(data.error || 'Failed to load questions.');
            testMeta  = data.assessment;
            questions = data.questions;
            if (!questions || questions.length === 0) throw new Error('This test has no questions yet.');
            initTest();
        })
        .catch(err => {
            showModalState('error');
            document.getElementById('modalErrorMsg').textContent = err.message;
        });
}

function closeModal() {
    stopTimer();
    document.getElementById('testModalOverlay').classList.remove('open');
    document.body.style.overflow = '';
}

// Trap Escape key
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        if (document.getElementById('testModalOverlay').classList.contains('open')) {
            if (!submitted) {
                if (confirm('Close the test? Your progress will be lost.')) closeModal();
            } else {
                closeModal();
            }
        }
        if (e.key === '/' && !e.target.matches('input, textarea')) {
            e.preventDefault();
            document.getElementById('searchInput')?.focus();
        }
    }
});

// ══════════════════════════════════════════════════════════
//  INIT TEST  –  set up state, render first question
// ══════════════════════════════════════════════════════════
function initTest() {
    // Randomise options per question if flag set
    if (testMeta.randomize_options) {
        questions.forEach(q => { if (q.options) q.options.sort(() => Math.random() - 0.5); });
    }

    // Set header
    document.getElementById('modalTitle').textContent = testMeta.title;
    const metaParts = [];
    if (testMeta.duration_minutes > 0) metaParts.push('⏱️ ' + testMeta.duration_minutes + ' min');
    metaParts.push('📝 ' + questions.length + ' questions');
    if (testMeta.total_marks > 0) metaParts.push('⭐ ' + testMeta.total_marks + ' marks');
    if (testMeta.passing_marks > 0) metaParts.push('✅ Pass: ' + testMeta.passing_marks);
    document.getElementById('modalMeta').innerHTML = metaParts.join('<span style="opacity:0.4;padding:0 4px">|</span>');

    showModalState('test');

    // Start timer
    if (testMeta.duration_minutes > 0) {
        timerSecs = testMeta.duration_minutes * 60;
        startTimer();
    }

    renderQNav();
    goQuestion(0);
}

// ══════════════════════════════════════════════════════════
//  TIMER
// ══════════════════════════════════════════════════════════
function startTimer() {
    updateTimerDisplay();
    timerHandle = setInterval(() => {
        timerSecs--;
        updateTimerDisplay();
        if (timerSecs <= 0) {
            clearInterval(timerHandle);
            alert('⏰ Time is up! Your test is being submitted now.');
            submitTest(true);
        }
    }, 1000);
}
function stopTimer() {
    clearInterval(timerHandle);
    timerHandle = null;
}
function updateTimerDisplay() {
    const el = document.getElementById('timerDisplay');
    const txt = document.getElementById('timerText');
    const mins = Math.floor(Math.max(0, timerSecs) / 60);
    const secs = Math.max(0, timerSecs) % 60;
    txt.textContent = String(mins).padStart(2,'0') + ':' + String(secs).padStart(2,'0');
    const warn = timerSecs <= 60;
    el.classList.toggle('warning', warn);
}

// ══════════════════════════════════════════════════════════
//  RENDER QUESTION NAVIGATOR
// ══════════════════════════════════════════════════════════
function renderQNav() {
    const nav = document.getElementById('qNav');
    nav.innerHTML = '';
    questions.forEach((q, i) => {
        const dot = document.createElement('button');
        dot.className = 'q-nav-dot' + (i === currentQ ? ' current' : '') + (answers[q.question_id] ? ' answered' : '');
        dot.textContent = i + 1;
        dot.title = 'Question ' + (i + 1);
        dot.onclick = () => goQuestion(i);
        nav.appendChild(dot);
    });
}

function updateQNav() {
    const dots = document.querySelectorAll('.q-nav-dot');
    dots.forEach((dot, i) => {
        dot.classList.toggle('current',  i === currentQ);
        dot.classList.toggle('answered', !!answers[questions[i].question_id]);
    });
}

// ══════════════════════════════════════════════════════════
//  RENDER SINGLE QUESTION
// ══════════════════════════════════════════════════════════
function goQuestion(idx) {
    if (idx < 0 || idx >= questions.length) return;
    currentQ = idx;
    const q  = questions[idx];
    const block = document.getElementById('questionBlock');
    const keys  = ['A','B','C','D','E','F'];

    // Progress
    const answeredCount = Object.keys(answers).length;
    const pct = Math.round((answeredCount / questions.length) * 100);
    document.getElementById('modalProgressBar').style.width = pct + '%';
    document.getElementById('qCounter').textContent = 'Q ' + (idx+1) + ' / ' + questions.length;

    let optionsHTML = '';
    (q.options || []).forEach((opt, oi) => {
        const key = keys[oi] || (oi + 1);
        const sel = answers[q.question_id] === opt.option_id ? 'selected' : '';
        optionsHTML += `
            <label class="option-label ${sel}" id="opt-${q.question_id}-${opt.option_id}">
                <input type="radio" name="q_${q.question_id}" value="${opt.option_id}"
                       onchange="selectAnswer(${q.question_id}, ${opt.option_id})"
                       ${sel ? 'checked' : ''}>
                <span class="option-key">${key}</span>
                <span class="option-text">${escHtml(opt.option_text)}</span>
            </label>`;
    });

    block.innerHTML = `
        <div class="question-number">Question ${idx+1} of ${questions.length} &nbsp;·&nbsp; ${q.marks} mark${q.marks!==1?'s':''}</div>
        <div class="question-text">${escHtml(q.question_text)}</div>
        <div class="options-list" id="opts-${q.question_id}">${optionsHTML}</div>
        ${q.explanation ? `<div class="explanation-box" id="exp-${q.question_id}"><div class="explanation-label">💡 Explanation</div>${escHtml(q.explanation)}</div>` : ''}
    `;

    // Prev / Next buttons
    document.getElementById('btnPrev').disabled = (idx === 0);
    const nextBtn = document.getElementById('btnNext');
    if (idx === questions.length - 1) {
        nextBtn.style.display = 'none';
    } else {
        nextBtn.style.display = '';
        nextBtn.disabled = false;
    }

    updateQNav();
}

// ══════════════════════════════════════════════════════════
//  SELECT ANSWER
// ══════════════════════════════════════════════════════════
function selectAnswer(questionId, optionId) {
    if (submitted) return;
    answers[questionId] = optionId;

    // Visual feedback
    document.querySelectorAll(`#opts-${questionId} .option-label`).forEach(lbl => {
        lbl.classList.remove('selected');
    });
    const selected = document.getElementById(`opt-${questionId}-${optionId}`);
    if (selected) selected.classList.add('selected');

    updateQNav();

    // Update progress bar
    const pct = Math.round((Object.keys(answers).length / questions.length) * 100);
    document.getElementById('modalProgressBar').style.width = pct + '%';
}

// ══════════════════════════════════════════════════════════
//  SUBMIT
// ══════════════════════════════════════════════════════════
function confirmSubmit() {
    const unanswered = questions.length - Object.keys(answers).length;
    let msg = 'Submit the test?';
    if (unanswered > 0) msg = `You have ${unanswered} unanswered question${unanswered>1?'s':''}. Submit anyway?`;
    if (confirm(msg)) submitTest(false);
}

function submitTest(timedOut) {
    stopTimer();
    submitted = true;

    // Disable all options
    document.querySelectorAll('.option-label input').forEach(i => i.disabled = true);

    // Build answer payload: {question_id: option_id, ...}
    const payload = {
        assessment_id: currentAssessmentId,
        answers: answers   // {question_id: option_id} — server resolves correctness
    };

    showModalState('loading');

    fetch('api/guest/grade-test.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) throw new Error(data.error || 'Grading failed.');
        renderResults(data, timedOut);
    })
    .catch(err => {
        // Fallback: grade client-side using is_correct flags on options
        const fallback = gradeClientSide();
        renderResults(fallback, timedOut);
    });
}

// Client-side fallback grading (uses is_correct on each option)
function gradeClientSide() {
    let score = 0;
    let answered = 0;
    const results = {};

    questions.forEach(q => {
        const chosenId = answers[q.question_id];
        if (!chosenId) {
            results[q.question_id] = { correct: false, skipped: true };
            return;
        }
        answered++;
        const chosenOpt = (q.options || []).find(o => o.option_id === chosenId);
        const isCorrect = chosenOpt && chosenOpt.is_correct == 1;
        if (isCorrect) {
            score += parseFloat(q.marks);
        } else {
            score -= parseFloat(q.negative_marks || 0);
        }
        const correctOpt = (q.options || []).find(o => o.is_correct == 1);
        results[q.question_id] = {
            correct: isCorrect,
            skipped: false,
            correct_option_id: correctOpt ? correctOpt.option_id : null
        };
    });

    score = Math.max(0, Math.round(score * 100) / 100);
    const totalMarks = parseFloat(testMeta.total_marks) || questions.reduce((s,q)=>s+parseFloat(q.marks),0);
    const pct = totalMarks > 0 ? Math.round((score / totalMarks) * 100) : 0;
    const passingMarks = parseFloat(testMeta.passing_marks) || 0;
    return {
        success: true,
        score, total_marks: totalMarks, passing_marks: passingMarks,
        passed: score >= passingMarks, pct, answered,
        total_q: questions.length, results,
        client_graded: true
    };
}

// ══════════════════════════════════════════════════════════
//  RENDER RESULTS
// ══════════════════════════════════════════════════════════
function renderResults(data, timedOut) {
    const passed       = data.passed;
    const pct          = data.pct ?? 0;
    const score        = data.score ?? 0;
    const totalMarks   = data.total_marks ?? 0;
    const passingMarks = data.passing_marks ?? 0;
    const answered     = data.answered ?? 0;
    const totalQ       = data.total_q ?? questions.length;
    const serverResults = data.results || {};  // {question_id: {correct, skipped, correct_answer?, correct_option_id?}}
    const hasReview    = Object.keys(serverResults).length > 0;

    // Build result HTML
    const passClass   = passed ? 'passed' : 'failed';
    const verdictText = passed ? '🎉 Congratulations!' : '😔 Better luck next time';
    const verdictClass= passed ? 'pass' : 'fail';
    const ringClass   = passed ? 'pass' : 'fail';
    const emoji       = timedOut ? '⏰' : (passed ? '🏆' : '📚');

    let html = `
    <div class="result-hero ${passClass}">
        <div class="result-emoji">${emoji}</div>
        ${timedOut ? '<p style="color:#b45309;font-weight:700;margin-bottom:8px;">⏰ Time ran out</p>' : ''}
        <div class="result-score-ring ${ringClass}">
            <span class="ring-pct ${ringClass}">${pct}%</span>
            <span class="ring-label">Score</span>
        </div>
        <div class="result-verdict ${verdictClass}">${verdictText}</div>
        <div class="result-subtitle">${passed ? 'You passed this test!' : 'You need ' + passingMarks + ' marks to pass.'}</div>
    </div>

    <div class="result-stats">
        <div class="result-stat-card">
            <div class="result-stat-value">${score}<span style="font-size:14px;color:var(--color-text-light)">/${totalMarks}</span></div>
            <div class="result-stat-label">Score</div>
        </div>
        <div class="result-stat-card">
            <div class="result-stat-value">${answered}</div>
            <div class="result-stat-label">Answered</div>
        </div>
        <div class="result-stat-card">
            <div class="result-stat-value">${totalQ - answered}</div>
            <div class="result-stat-label">Skipped</div>
        </div>
        <div class="result-stat-card">
            <div class="result-stat-value" style="color:${passed?'#16a34a':'#dc2626'}">${passed?'PASS':'FAIL'}</div>
            <div class="result-stat-label">Result</div>
        </div>
    </div>`;

    if (hasReview) {
        html += `<div class="review-section">
            <div class="review-heading">📋 Question Review</div>
            <div class="review-q-list">`;

        questions.forEach((q, idx) => {
            const res = serverResults[q.question_id] || serverResults[String(q.question_id)];
            if (!res) return;

            const isCorrect = res.correct;
            const isSkipped = res.skipped;
            let statusClass = isSkipped ? 'skipped' : (isCorrect ? 'correct' : 'wrong');
            let statusIcon  = isSkipped ? '—' : (isCorrect ? '✓' : '✗');
            let marksDelta  = isSkipped ? 0 : (isCorrect ? +q.marks : -parseFloat(q.negative_marks||0));
            let marksClass  = marksDelta > 0 ? 'positive' : (marksDelta < 0 ? 'negative' : 'zero');
            let marksLabel  = marksDelta > 0 ? '+'+marksDelta : String(marksDelta);

            // Build options HTML for review
            const keys = ['A','B','C','D','E','F'];
            let optsHtml = '<div class="options-list">';
            (q.options||[]).forEach((opt, oi) => {
                const key = keys[oi] || (oi+1);
                const userChose = answers[q.question_id] === opt.option_id;
                // Determine correct option: server always returns correct_option_id.
                // Fallback to is_correct flag from get-test-questions for client-side grading.
                let isThisCorrect;
                if (res.correct_option_id) {
                    isThisCorrect = (opt.option_id === res.correct_option_id);
                } else {
                    isThisCorrect = opt.is_correct == 1;
                }

                let cls = 'result-neutral';
                let tag = '';
                if (isThisCorrect) { cls = 'result-correct'; tag = '<span class="option-result-tag tag-correct">✓ Correct</span>'; }
                if (userChose && !isThisCorrect) { cls = 'result-wrong'; tag = '<span class="option-result-tag tag-wrong">✗ Your answer</span>'; }

                optsHtml += `<label class="option-label ${cls}" style="cursor:default">
                    <span class="option-key">${key}</span>
                    <span class="option-text">${escHtml(opt.option_text)}</span>
                    ${tag}
                </label>`;
            });
            optsHtml += '</div>';

            const expHtml = q.explanation
                ? `<div class="explanation-box visible"><div class="explanation-label">💡 Explanation</div>${escHtml(q.explanation)}</div>` : '';

            html += `
            <div class="review-q-item" id="rqi-${idx}">
                <div class="review-q-header" onclick="toggleReviewQ(${idx})">
                    <span class="review-q-status ${statusClass}">${statusIcon}</span>
                    <span class="review-q-text">Q${idx+1}: ${escHtml(q.question_text.substring(0,80))}${q.question_text.length>80?'…':''}</span>
                    <span class="review-q-marks ${marksClass}">${marksLabel} marks</span>
                    <span class="review-q-chevron">▼</span>
                </div>
                <div class="review-q-body">
                    <p style="font-size:15px;font-weight:600;margin-bottom:14px;line-height:1.55">${escHtml(q.question_text)}</p>
                    ${optsHtml}
                    ${expHtml}
                </div>
            </div>`;
        });

        html += `</div></div>`;
    }

    const screen = document.getElementById('resultsScreen');
    screen.innerHTML = html;
    showModalState('results');
    screen.scrollTop = 0;

    // Scroll modal to top
    document.getElementById('testModal').scrollIntoView({ behavior: 'smooth', block: 'start' });

    // Update nav dots to show correct/wrong/skipped
    if (hasReview) {
        const dots = document.querySelectorAll('.q-nav-dot');
        questions.forEach((q, i) => {
            const res = serverResults[q.question_id] || serverResults[String(q.question_id)];
            if (!res || !dots[i]) return;
            dots[i].classList.remove('answered','current');
            if (res.skipped) dots[i].classList.add('skipped-dot');
            else if (res.correct) dots[i].classList.add('correct-dot');
            else dots[i].classList.add('wrong-dot');
        });
    }
}

function toggleReviewQ(idx) {
    const item = document.getElementById('rqi-' + idx);
    item.classList.toggle('open');
}

// ══════════════════════════════════════════════════════════
//  RETAKE
// ══════════════════════════════════════════════════════════
function retakeTest() {
    openTestModal(currentAssessmentId);
}

// ══════════════════════════════════════════════════════════
//  SHOW / HIDE MODAL SECTIONS
// ══════════════════════════════════════════════════════════
function showModalState(state) {
    // states: 'loading' | 'error' | 'test' | 'results'
    document.getElementById('modalLoading').style.display      = state === 'loading'  ? '' : 'none';
    document.getElementById('modalError').style.display        = state === 'error'    ? '' : 'none';
    document.getElementById('modalBody').style.display         = state === 'test'     ? '' : 'none';
    document.getElementById('modalNav').style.display          = state === 'test'     ? '' : 'none';
    document.getElementById('modalProgressWrap').style.display = state === 'test'     ? '' : 'none';
    document.getElementById('timerBar').style.display          = state === 'test'     ? '' : 'none';
    document.getElementById('resultActions').style.display     = state === 'results'  ? '' : 'none';

    const rs = document.getElementById('resultsScreen');
    if (state === 'results') rs.classList.add('visible'); else rs.classList.remove('visible');
}

// ══════════════════════════════════════════════════════════
//  UTILITY
// ══════════════════════════════════════════════════════════
function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// ══════════════════════════════════════════════════════════
//  INIT ON LOAD
// ══════════════════════════════════════════════════════════
window.addEventListener('load', () => {
    // Apply ?category= filter before animating
    if (INITIAL_CATEGORY !== 'all') {
        activeCategory = INITIAL_CATEGORY;
        applyFilters();
        // Highlight the correct filter button
        document.querySelectorAll('#catFilterGroup .filter-btn').forEach(btn => {
            btn.classList.toggle('active', btn.textContent.toLowerCase() === INITIAL_CATEGORY);
        });
    }

    document.querySelectorAll('.test-card').forEach((card, i) => {
        if (card.style.display === 'none') return;
        card.style.opacity = '0';
        card.style.transform = 'translateY(10px)';
        card.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
        setTimeout(() => {
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, i * 50);
    });
});

// Keyboard shortcut: / to focus search
document.addEventListener('keydown', e => {
    if (e.key === '/' && !e.target.matches('input,textarea') && !document.getElementById('testModalOverlay').classList.contains('open')) {
        e.preventDefault();
        document.getElementById('searchInput')?.focus();
    }
    if (e.key === 'Escape' && e.target.matches('#searchInput')) {
        e.target.value = '';
        handleSearch('');
    }
});
</script>

</body>
</html>
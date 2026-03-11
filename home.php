<?php
/* ========================================
 * PRACTICE TESTS PAGE
 * Accessible to: guest, student, teacher, admin
 * Nav and back button adapt per role
 * ======================================== */

require_once "config.php";
require_once "db-guard.php";

// ── Detect session without forcing login ──────────────────────────────────
// Unlike validateSession(), we don't redirect — guests are allowed here.

$isLoggedIn   = false;
$userRole     = 'guest';
$userName     = '';
$userEmail    = '';
$userInitials = '';

if (!empty($_SESSION['user_id']) && !empty($_SESSION['user_type'])) {
    // Verify the session is still valid in the DB.
    // New schema: users table has user_id, full_name, email, user_type, is_active — unchanged.
    $sid   = (int)$_SESSION['user_id'];
    $stype = $_SESSION['user_type'];

    $chk = safePreparedQuery(
        $conn,
        "SELECT user_id, full_name, email, user_type, is_active
         FROM users
         WHERE user_id = ? AND user_type = ? AND is_active = 1",
        "is", [$sid, $stype]
    );

    if ($chk['success'] && $chk['result']) {
        $row = $chk['result']->fetch_assoc();
        $chk['result']->free();

        if ($row) {
            $isLoggedIn   = true;
            $userRole     = $row['user_type'];   // student | teacher | admin
            $userName     = $row['full_name'];
            $userEmail    = $row['email'];
            $userInitials = strtoupper(substr($userName, 0, 2));
        } else {
            // Session exists but user is deactivated or role mismatch — clear it
            session_destroy();
        }
    }
}

// ── Fetch public active assessments from DB ───────────────────────────────
// Respects: is_public = 1, status = 'active', optional availability window.
// New schema columns used: assessment_id, title, description, category,
//   difficulty, duration_minutes, total_marks, passing_marks,
//   available_from, available_until, is_public, status.
// questions_per_attempt is used to show the displayed question count —
//   falls back to COUNT(*) of actual questions when NULL.
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
            a.questions_per_attempt,
            COUNT(q.question_id) AS question_count
     FROM assessments a
     LEFT JOIN questions q ON q.assessment_id = a.assessment_id
     WHERE a.is_public = 1
       AND a.status    = 'active'
       AND (a.available_from  IS NULL OR a.available_from  <= NOW())
       AND (a.available_until IS NULL OR a.available_until >= NOW())
     GROUP BY a.assessment_id
     ORDER BY a.created_at DESC",
    "", []
);

if ($aQuery['success'] && $aQuery['result']) {
    while ($row = $aQuery['result']->fetch_assoc()) {
        // questions_per_attempt overrides actual count when set
        $row['display_q_count'] = $row['questions_per_attempt'] ?? $row['question_count'];
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

// ── Auto-filter via ?category= query param (set by guest-dashboard links) ──
// Whitelist against known categories so nothing unsanitised ever reaches JS.
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
    <meta name="description" content="Free Online Practice Tests - Placement Portal">
    <title>Practice Tests – Placement Portal</title>
    <style>
        :root {
            --color-primary:      #0D7377;
            --color-primary-dark: #14FFEC;
            --color-accent:       #FF6B6B;
            --color-text:         #1a1a1a;
            --color-text-light:   #6b7280;
            --color-bg:           #ffffff;
            --color-bg-light:     #f9fafb;
            --color-border:       #e5e7eb;
            --shadow-sm:          0 2px 8px rgba(0,0,0,0.06);
            --shadow-md:          0 4px 16px rgba(0,0,0,0.10);
            --shadow-lg:          0 8px 30px rgba(0,0,0,0.14);
        }
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--color-bg);
            color: var(--color-text);
            line-height: 1.6;
        }

        /* ── Navbar ── */
        .navbar {
            background: var(--color-bg);
            border-bottom: 1px solid var(--color-border);
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: var(--shadow-sm);
        }
        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 64px;
        }
        .navbar-brand {
            display: flex; align-items: center; gap: 12px;
            text-decoration: none; color: var(--color-text);
            font-size: 20px; font-weight: 700;
        }
        .brand-logo {
            width: 36px; height: 36px;
            background: var(--color-primary);
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 20px; font-weight: 700;
        }
        .nav-search {
            flex: 1; max-width: 400px; margin: 0 32px; position: relative;
        }
        .search-input {
            width: 100%; padding: 10px 40px 10px 16px;
            border: 1px solid var(--color-border); border-radius: 8px;
            font-size: 14px; background: var(--color-bg-light); transition: all 0.2s;
        }
        .search-input:focus {
            outline: none; border-color: var(--color-primary); background: var(--color-bg);
        }
        .search-icon {
            position: absolute; right: 12px; top: 50%;
            transform: translateY(-50%); color: var(--color-text-light); font-size: 18px;
        }

        /* ── Logged-in profile dropdown ── */
        .profile-dropdown-container { position: relative; }
        .profile-button {
            display: flex; align-items: center; gap: 10px;
            padding: 7px 13px;
            background: var(--color-bg-light);
            border: 1px solid var(--color-border);
            border-radius: 10px; cursor: pointer; transition: 0.2s;
        }
        .profile-button:hover { background: var(--color-border); }
        .profile-avatar {
            width: 34px; height: 34px;
            background: linear-gradient(135deg, var(--color-primary), #0a5c5f);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 13px; flex-shrink: 0;
        }
        .profile-name { font-weight: 600; font-size: 14px; color: var(--color-text); }
        .dropdown-arrow { font-size: 11px; color: var(--color-text-light); }
        .profile-dropdown {
            position: absolute; top: calc(100% + 10px); right: 0;
            background: white; border-radius: 12px;
            box-shadow: var(--shadow-lg); min-width: 230px;
            opacity: 0; visibility: hidden; transform: translateY(-8px);
            transition: 0.25s cubic-bezier(.22,1,.36,1); z-index: 1001;
            border: 1px solid var(--color-border);
        }
        .profile-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0); }
        .dropdown-header {
            padding: 16px 18px; border-bottom: 1px solid var(--color-border);
            display: flex; align-items: center; gap: 12px;
        }
        .dropdown-header-avatar {
            width: 42px; height: 42px;
            background: linear-gradient(135deg, var(--color-primary), #0a5c5f);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 16px; flex-shrink: 0;
        }
        .dropdown-header-name { font-size: 14px; font-weight: 700; color: var(--color-text); margin-bottom: 2px; }
        .dropdown-header-email {
            font-size: 12px; color: var(--color-text-light);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 150px;
        }
        .dropdown-role-badge {
            display: inline-block; margin-top: 4px;
            padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 700;
            text-transform: capitalize;
        }
        .role-student { background: #dbeafe; color: #1e40af; }
        .role-teacher { background: #dcfce7; color: #15803d; }
        .role-admin   { background: #fef3c7; color: #a16207; }
        .dropdown-menu { padding: 6px 0; }
        .dropdown-item {
            display: flex; align-items: center; gap: 11px;
            padding: 10px 18px; color: var(--color-text); text-decoration: none;
            font-size: 14px; font-weight: 500; cursor: pointer;
            border: none; background: none; width: 100%;
            text-align: left; font-family: inherit; transition: background 0.15s;
        }
        .dropdown-item:hover { background: var(--color-bg-light); }
        .dropdown-item-icon { font-size: 16px; width: 20px; text-align: center; }
        .dropdown-divider { height: 1px; background: var(--color-border); margin: 4px 0; }
        .dropdown-item.logout { color: #ef4444; }
        .dropdown-item.logout:hover { background: #fff5f5; }
        .dropdown-overlay {
            position: fixed; inset: 0; background: transparent;
            z-index: 999; display: none;
        }
        .dropdown-overlay.show { display: block; }

        /* ── Role banner (subtle indicator for logged-in users) ── */
        .role-banner {
            background: var(--color-bg-light);
            border-bottom: 1px solid var(--color-border);
            padding: 8px 24px;
            display: flex; align-items: center; justify-content: space-between;
            font-size: 13px; color: var(--color-text-light);
        }
        .role-banner-left { display: flex; align-items: center; gap: 8px; }
        .back-dashboard-btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 14px;
            background: var(--color-primary); color: white;
            border: none; border-radius: 7px; font-size: 13px; font-weight: 600;
            cursor: pointer; text-decoration: none; transition: 0.2s;
        }
        .back-dashboard-btn:hover { background: #0a5c5f; }

        /* ── Hero ── */
        .hero-section {
            background: linear-gradient(135deg, var(--color-primary) 0%, #0a5c5f 100%);
            color: white; padding: 80px 24px 60px; text-align: center;
        }
        .hero-container { max-width: 800px; margin: 0 auto; }
        .hero-title { font-size: 48px; font-weight: 700; margin-bottom: 16px; line-height: 1.2; }
        .hero-subtitle { font-size: 20px; margin-bottom: 32px; opacity: 0.9; }
        .hero-stats { display: flex; justify-content: center; gap: 48px; margin-top: 40px; }
        .stat-box { text-align: center; }
        .stat-number { font-size: 40px; font-weight: 700; display: block; margin-bottom: 4px; }
        .stat-label { font-size: 14px; opacity: 0.85; }

        /* ── Breadcrumb ── */
        .breadcrumb {
            background: var(--color-bg-light);
            padding: 14px 0;
            border-bottom: 1px solid var(--color-border);
        }
        .breadcrumb-container {
            max-width: 1200px; margin: 0 auto; padding: 0 24px;
            display: flex; align-items: center; gap: 8px;
            font-size: 14px; color: var(--color-text-light);
        }
        .breadcrumb-link {
            color: var(--color-text-light); text-decoration: none; transition: color 0.2s;
        }
        .breadcrumb-link:hover { color: var(--color-primary); }

        /* ── Main ── */
        .main-container { max-width: 1200px; margin: 0 auto; padding: 48px 24px; }
        .section-header { margin-bottom: 32px; }
        .section-title { font-size: 28px; font-weight: 700; margin-bottom: 8px; }
        .section-subtitle { font-size: 16px; color: var(--color-text-light); }

        /* ── Filters ── */
        .filters-bar { display: flex; gap: 12px; margin-bottom: 32px; flex-wrap: wrap; }
        .filter-group { display: flex; gap: 8px; }
        .filter-btn {
            padding: 8px 16px; border: 1px solid var(--color-border);
            border-radius: 8px; background: var(--color-bg);
            color: var(--color-text); font-size: 14px; cursor: pointer; transition: all 0.2s;
        }
        .filter-btn:hover { background: var(--color-bg-light); }
        .filter-btn.active { background: var(--color-primary); color: white; border-color: var(--color-primary); }

        /* ── Test grid ── */
        .tests-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 24px; margin-bottom: 48px;
        }
        .test-card {
            background: var(--color-bg); border: 1px solid var(--color-border);
            border-radius: 12px; padding: 24px; transition: all 0.2s;
        }
        .test-card:hover {
            box-shadow: var(--shadow-md); transform: translateY(-2px);
        }
        .test-header {
            display: flex; justify-content: space-between;
            align-items: start; margin-bottom: 16px;
        }
        .test-category {
            padding: 4px 12px; border-radius: 6px;
            font-size: 12px; font-weight: 600; text-transform: uppercase;
        }
        .category-aptitude  { background: #dbeafe; color: #1e40af; }
        .category-verbal    { background: #fce7f3; color: #be185d; }
        .category-logical   { background: #dcfce7; color: #15803d; }
        .category-technical { background: #fef3c7; color: #a16207; }
        .difficulty-badge { padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; }
        .difficulty-easy   { background: #d1fae5; color: #065f46; }
        .difficulty-medium { background: #fed7aa; color: #9a3412; }
        .difficulty-hard   { background: #fecaca; color: #991b1b; }
        .test-title { font-size: 18px; font-weight: 700; margin-bottom: 8px; }
        .test-description { font-size: 14px; color: var(--color-text-light); margin-bottom: 16px; line-height: 1.5; }
        .test-meta { display: flex; gap: 16px; margin-bottom: 16px; font-size: 13px; color: var(--color-text-light); flex-wrap: wrap; }
        .meta-item { display: flex; align-items: center; gap: 6px; }
        .test-actions { display: flex; gap: 8px; }
        .btn-start {
            flex: 1; padding: 10px 16px;
            background: var(--color-primary); color: white;
            border: none; border-radius: 8px; font-weight: 600; font-size: 14px;
            cursor: pointer; transition: all 0.2s;
        }
        .btn-start:hover { background: #0a5c5f; }
        .btn-details {
            padding: 10px 16px; background: var(--color-bg-light);
            color: var(--color-text); border: 1px solid var(--color-border);
            border-radius: 8px; font-weight: 600; font-size: 14px;
            cursor: pointer; transition: all 0.2s;
        }
        .btn-details:hover { background: var(--color-bg); }

        /* ── Guest lock overlay on cards ── */
        .test-card.guest-locked { position: relative; }
        .guest-lock-overlay {
            display: none;
            position: absolute; inset: 0;
            background: rgba(255,255,255,0.85);
            border-radius: 12px;
            align-items: center; justify-content: center;
            flex-direction: column; gap: 10px; text-align: center; padding: 20px;
            font-size: 14px; color: var(--color-text-light);
        }
        /* Guests only see overlay on hover — tests are visible but starting requires login */

        /* ── Pagination ── */
        .pagination { display: flex; justify-content: center; align-items: center; gap: 8px; margin-top: 48px; }
        .page-btn {
            padding: 8px 12px; border: 1px solid var(--color-border);
            border-radius: 6px; background: var(--color-bg);
            color: var(--color-text); font-size: 14px; cursor: pointer;
            transition: all 0.2s; min-width: 40px; text-align: center;
        }
        .page-btn:hover { background: var(--color-bg-light); }
        .page-btn.active { background: var(--color-primary); color: white; border-color: var(--color-primary); }
        .page-btn:disabled { opacity: 0.4; cursor: not-allowed; }

        /* ── Footer ── */
        .footer { background: var(--color-bg-light); border-top: 1px solid var(--color-border); padding: 40px 24px; margin-top: 80px; }
        .footer-container {
            max-width: 1200px; margin: 0 auto;
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 32px;
        }
        .footer-section h3 { font-size: 16px; font-weight: 700; margin-bottom: 16px; }
        .footer-links { list-style: none; }
        .footer-links li { margin-bottom: 8px; }
        .footer-links a { color: var(--color-text-light); text-decoration: none; font-size: 14px; transition: color 0.2s; }
        .footer-links a:hover { color: var(--color-primary); }
        .footer-bottom {
            max-width: 1200px; margin: 32px auto 0; padding-top: 24px;
            border-top: 1px solid var(--color-border);
            text-align: center; color: var(--color-text-light); font-size: 14px;
        }

        @media (max-width: 768px) {
            .nav-search { display: none; }
            .hero-title { font-size: 36px; }
            .hero-stats { flex-direction: column; gap: 24px; }
            .tests-grid { grid-template-columns: 1fr; }
            .filters-bar { flex-direction: column; }
            .profile-name { display: none; }
        }
    </style>
</head>
<body>

<!-- ── Navbar ── -->
<nav class="navbar">
    <div class="nav-container">
        <a href="home.php" class="navbar-brand">
            <div class="brand-logo">P</div>
            <span>Placement Portal</span>
        </a>

        <div class="nav-search">
            <input type="text" id="searchInput" class="search-input"
                   placeholder="Search tests..." oninput="handleSearch(this.value)">
            <span class="search-icon">🔍</span>
        </div>

        <div class="nav-actions">
            <?php if ($isLoggedIn): ?>
                <!-- Logged-in dropdown -->
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
                                <div class="dropdown-header-email" title="<?php echo htmlspecialchars($userEmail); ?>">
                                    <?php echo htmlspecialchars($userEmail); ?>
                                </div>
                                <span class="dropdown-role-badge role-<?php echo $userRole; ?>">
                                    <?php echo ucfirst($userRole); ?>
                                </span>
                            </div>
                        </div>
                        <div class="dropdown-menu">
                            <?php if ($userRole === 'student'): ?>
                            <a href="student-profile.php" class="dropdown-item">
                                <span class="dropdown-item-icon">👤</span>
                                <span>My Profile</span>
                            </a>
                            <?php endif; ?>
                            <?php if ($userRole === 'teacher'): ?>
                            <a href="teacher-profile.php" class="dropdown-item">
                                <span class="dropdown-item-icon">👤</span>
                                <span>My Profile</span>
                            </a>
                            <?php endif; ?>
                            <?php if ($dashboardUrl): ?>
                            <a href="<?php echo $dashboardUrl; ?>" class="dropdown-item">
                                <span class="dropdown-item-icon">🏠</span>
                                <span><?php echo $dashboardLabel; ?></span>
                            </a>
                            <?php endif; ?>
                            <a href="home.php" class="dropdown-item">
                                <span class="dropdown-item-icon">📝</span>
                                <span>Practice Tests</span>
                            </a>
                            <a href="help.html" target="_blank" rel="noopener noreferrer" class="dropdown-item">
                                <span>❓</span>
                                <span>Help & Support</span>
                                </a>
                            <div class="dropdown-divider"></div>
                            <button onclick="confirmLogout()" class="dropdown-item logout">
                                <span class="dropdown-item-icon">🚪</span>
                                <span>Logout</span>
                            </button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</nav>

<div class="dropdown-overlay" id="dropdownOverlay" onclick="closeDropdown()"></div>

<!-- ── Role banner (only for logged-in users) ── -->
<?php if ($isLoggedIn && $dashboardUrl): ?>
<div class="role-banner">
    <div class="role-banner-left">
        <span>Browsing as <strong><?php echo ucfirst($userRole); ?></strong></span>
    </div>
    <a href="<?php echo $dashboardUrl; ?>" class="back-dashboard-btn">
        ← Back to <?php echo $dashboardLabel; ?>
    </a>
</div>
<?php endif; ?>

<!-- ── Hero ── -->
<section class="hero-section">
    <div class="hero-container">
        <h1 class="hero-title">Master Your Placement Tests</h1>
        <p class="hero-subtitle">Practice with real test scenarios and boost your confidence</p>
        <div class="hero-stats">
            <div class="stat-box">
                <span class="stat-number">150+</span>
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
            <a href="home.php" class="breadcrumb-link">Home</a>
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
                Choose from our comprehensive collection of tests.
            <?php endif; ?>
        </p>
    </div>

    <div class="filters-bar">
        <div class="filter-group">
            <button class="filter-btn <?php echo $initialCategory === 'all'       ? 'active' : ''; ?>" onclick="filterCategory('all', event)">All</button>
            <button class="filter-btn <?php echo $initialCategory === 'aptitude'  ? 'active' : ''; ?>" onclick="filterCategory('aptitude', event)">Aptitude</button>
            <button class="filter-btn <?php echo $initialCategory === 'verbal'    ? 'active' : ''; ?>" onclick="filterCategory('verbal', event)">Verbal</button>
            <button class="filter-btn <?php echo $initialCategory === 'logical'   ? 'active' : ''; ?>" onclick="filterCategory('logical', event)">Logical</button>
            <button class="filter-btn <?php echo $initialCategory === 'technical' ? 'active' : ''; ?>" onclick="filterCategory('technical', event)">Technical</button>
        </div>
        <div class="filter-group">
            <button class="filter-btn active" onclick="filterDifficulty('all', event)">All Levels</button>
            <button class="filter-btn" onclick="filterDifficulty('easy', event)">Easy</button>
            <button class="filter-btn" onclick="filterDifficulty('medium', event)">Medium</button>
            <button class="filter-btn" onclick="filterDifficulty('hard', event)">Hard</button>
        </div>
    </div>

    <div class="tests-grid" id="testsGrid">

        <?php if (empty($assessments)): ?>
        <div style="grid-column:1/-1; text-align:center; padding:60px 20px; color:var(--color-text-light);">
            <p style="font-size:18px; margin-bottom:8px;">No tests available right now.</p>
            <p style="font-size:14px;">Check back soon — new tests are added regularly.</p>
        </div>
        <?php else: ?>
        <?php foreach ($assessments as $a):
            $cat      = strtolower(htmlspecialchars($a['category'] ?? 'general'));
            $diff     = strtolower(htmlspecialchars($a['difficulty'] ?? 'medium'));
            $id       = (int)$a['assessment_id'];
            $title    = htmlspecialchars($a['title']);
            $desc     = htmlspecialchars($a['description'] ?? '');
            $duration = (int)$a['duration_minutes'];
            $qCount   = (int)$a['display_q_count'];
            $marks    = (int)$a['total_marks'];
            // Map category to CSS class; fall back to a neutral style for unknown categories
            $catClass = in_array($cat, ['aptitude','verbal','logical','technical'])
                        ? 'category-' . $cat : 'category-aptitude';
        ?>
        <div class="test-card" data-category="<?php echo $cat; ?>" data-difficulty="<?php echo $diff; ?>">
            <div class="test-header">
                <span class="test-category <?php echo $catClass; ?>"><?php echo ucfirst($cat); ?></span>
                <span class="difficulty-badge difficulty-<?php echo $diff; ?>"><?php echo ucfirst($diff); ?></span>
            </div>
            <h3 class="test-title"><?php echo $title; ?></h3>
            <?php if ($desc): ?>
            <p class="test-description"><?php echo $desc; ?></p>
            <?php endif; ?>
            <div class="test-meta">
                <span class="meta-item">⏱️ <?php echo $duration; ?> mins</span>
                <span class="meta-item">📝 <?php echo $qCount; ?> questions</span>
                <span class="meta-item">⭐ <?php echo $marks; ?> marks</span>
            </div>
            <div class="test-actions">
                <button class="btn-start"   onclick="startTest(<?php echo $id; ?>)">Start Test</button>
                <button class="btn-details" onclick="viewDetails(<?php echo $id; ?>)">Details</button>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

    </div><!-- /tests-grid -->

    <?php
    // Simple pagination — only rendered when there are enough tests to warrant it.
    // JS-side filtering (category/difficulty/search) operates within the loaded page.
    // Full server-side pagination can be wired in later by adding LIMIT/OFFSET to the query.
    $totalTests = count($assessments);
    if ($totalTests > 9): ?>
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
        <p>© 2024 Placement Portal. All rights reserved.</p>
    </div>
</footer>

<script>
    // Pass PHP role and DB-derived categories to JS
    const USER_ROLE       = <?php echo json_encode($userRole); ?>;
    const IS_LOGGED_IN    = <?php echo json_encode($isLoggedIn); ?>;
    const DASHBOARD_URL   = <?php echo json_encode($dashboardUrl); ?>;
    const INITIAL_CATEGORY = <?php echo json_encode($initialCategory); ?>;
    // Unique categories present in the loaded assessments (for filter buttons)
    const DB_CATEGORIES = <?php
        $cats = array_values(array_unique(array_filter(
            array_map(fn($a) => strtolower($a['category'] ?? ''), $assessments)
        )));
        echo json_encode($cats);
    ?>;

    // ── Dropdown ──────────────────────────────────────────────────────────
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

    // ── Search ────────────────────────────────────────────────────────────
    function handleSearch(query) {
        const term = query.toLowerCase();
        document.querySelectorAll('.test-card').forEach(card => {
            const title = card.querySelector('.test-title').textContent.toLowerCase();
            const desc  = card.querySelector('.test-description').textContent.toLowerCase();
            const cat   = card.querySelector('.test-category').textContent.toLowerCase();
            card.style.display = (title.includes(term) || desc.includes(term) || cat.includes(term))
                ? '' : 'none';
        });
    }

    // ── Category filter ───────────────────────────────────────────────────
    let activeCategory   = INITIAL_CATEGORY; // seeded from ?category= param
    let activeDifficulty = 'all';

    function filterCategory(category, e) {
        activeCategory = category;
        document.querySelectorAll('.filter-group:first-child .filter-btn')
            .forEach(b => b.classList.remove('active'));
        e.target.classList.add('active');
        applyFilters();
    }
    function filterDifficulty(difficulty, e) {
        activeDifficulty = difficulty;
        document.querySelectorAll('.filter-group:last-child .filter-btn')
            .forEach(b => b.classList.remove('active'));
        e.target.classList.add('active');
        applyFilters();
    }
    function applyFilters() {
        document.querySelectorAll('.test-card').forEach(card => {
            const catMatch  = activeCategory   === 'all' || card.dataset.category   === activeCategory;
            const diffMatch = activeDifficulty === 'all' || card.dataset.difficulty === activeDifficulty;
            card.style.display = (catMatch && diffMatch) ? '' : 'none';
        });
    }

    // ── Start test ────────────────────────────────────────────────────────
    function startTest(id) {
        if (confirm('Ready to start this test?')) {
            window.location.href = 'take-test.php?id=' + id;
        }
    }

    function viewDetails(id) {
        window.location.href = 'test-details.php?id=' + id;
    }

    // ── Pagination ────────────────────────────────────────────────────────
    function changePage(page) {
        document.querySelectorAll('.page-btn').forEach(btn => {
            btn.classList.toggle('active', btn.textContent === page.toString());
        });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // ── Keyboard shortcut: / to focus search ─────────────────────────────
    document.addEventListener('keydown', e => {
        if (e.key === '/' && !e.target.matches('input, textarea')) {
            e.preventDefault();
            document.getElementById('searchInput')?.focus();
        }
        if (e.key === 'Escape' && e.target.matches('#searchInput')) {
            e.target.value = '';
            handleSearch('');
        }
    });

    // ── Animate cards on load & apply any incoming category filter ───────
    window.addEventListener('load', () => {
        // Apply the ?category= filter before animating so hidden cards
        // never flash visible then disappear.
        if (INITIAL_CATEGORY !== 'all') {
            applyFilters();
        }

        document.querySelectorAll('.test-card').forEach((card, i) => {
            if (card.style.display === 'none') return; // skip already-hidden cards
            card.style.opacity = '0';
            card.style.transform = 'translateY(10px)';
            card.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            setTimeout(() => {
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, i * 50);
        });
    });
</script>
</body>
</html>
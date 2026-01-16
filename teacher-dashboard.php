<?php
require "config.php";
session_start();

/* 🔒 SESSION GUARD */
if (!isset($_SESSION['uid']) || $_SESSION['role'] !== 'teacher') {
    header("Location: index.html");
    exit;
}

/* Fetch user info from database */
$stmt = $conn->prepare("SELECT name, email FROM users WHERE uid = ?");
$stmt->bind_param("i", $_SESSION['uid']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

$userName = $user['name'] ?? 'Teacher';
$userEmail = $user['email'] ?? '';
$userInitials = strtoupper(substr($userName, 0, 2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Teacher Dashboard - Placement Portal">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Teacher Dashboard - Placement Portal</title>
    <style>
        /* ============================================
           CSS VARIABLES - Reusable values
           ============================================ */
        :root {
            --font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            --color-primary: #234C6A;
            --color-primary-dark: #456882;
            --color-teacher-primary: #2E073F;
            --color-teacher-secondary: #AD49E1;
            --color-text: #2d3748;
            --color-text-light: #718096;
            --color-text-lighter: #a0aec0;
            --color-bg: #FFEDFA;
            --color-bg-light: #f5f7fa;
            --color-white: #ffffff;
            --color-border: #e2e8f0;
            --color-success: #48bb78;
            --color-error: #f56565;
            --color-warning: #ffc107;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 4px 20px rgba(0, 0, 0, 0.08);
            --shadow-lg: 0 8px 30px rgba(0, 0, 0, 0.15);
            --border-radius: 10px;
            --border-radius-lg: 20px;
            --transition: all 0.3s ease;
        }

        /* ============================================
           GLOBAL RESET & BASE STYLES
           ============================================ */
        html {
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--font-family);
            background: #D3DAD9;
            min-height: 100vh;
            color: var(--color-text);
            margin: 0;
            padding: 0;
            padding-top: 71px;
            overflow-x: hidden;
        }

        /* ============================================
           NAVIGATION BAR
           ============================================ */
        .navbar {
            background: var(--color-teacher-primary);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            padding: 12px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            border-bottom: 3px solid var(--color-teacher-primary);
        }

        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 20px;
            font-weight: 700;
            color: white;
            text-decoration: none;
        }

        .brand-logo {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, var(--color-teacher-primary) 0%, var(--color-teacher-secondary) 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--color-white);
            font-weight: 700;
            font-size: 18px;
        }

        /* Search bar in navigation */
        .nav-search {
            flex: 1;
            max-width: 500px;
            margin: 0 30px;
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 10px 40px 10px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px rgba(79, 172, 254, 0.1);
        }

        .search-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #a0aec0;
            font-size: 18px;
        }

        /* User profile section in navbar */
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
            transition: all 0.3s ease;
        }

        .notification-icon:hover {
            background: var(--color-teacher-primary);
            color: var(--color-white);
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #ff6b6b;
            color: white;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            font-size: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        .profile-button {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 15px;
            background: #f7fafc;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .profile-button:hover {
            background: #e2e8f0;
        }

        .profile-avatar {
            width: 35px;
            height: 35px;
            background: linear-gradient(135deg, var(--color-teacher-primary) 0%, var(--color-teacher-secondary) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--color-white);
            font-weight: bold;
            font-size: 14px;
        }

        .profile-name {
            font-weight: 600;
            font-size: 14px;
            color: var(--color-text);
        }

        /* Profile Dropdown */
        .profile-dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.15);
            min-width: 280px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            z-index: 1001;
        }

        .profile-dropdown.active {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .profile-dropdown-header {
            padding: 20px;
            border-bottom: 2px solid var(--color-border);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .dropdown-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--color-teacher-primary) 0%, var(--color-teacher-secondary) 100%);
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
            color: var(--color-text);
            margin-bottom: 4px;
        }

        .dropdown-user-email {
            font-size: 13px;
            color: var(--color-text-light);
        }

        .profile-dropdown-menu {
            padding: 10px;
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: var(--color-text);
            font-size: 14px;
            font-weight: 500;
        }

        .dropdown-item:hover {
            background: linear-gradient(135deg, rgba(46, 7, 63, 0.1), rgba(173, 73, 225, 0.1));
            color: var(--color-teacher-primary);
        }

        .dropdown-item-icon {
            font-size: 18px;
            width: 20px;
            text-align: center;
        }

        .dropdown-divider {
            height: 1px;
            background: var(--color-border);
            margin: 8px 0;
        }

        .dropdown-item.logout {
            color: var(--color-error);
        }

        .dropdown-item.logout:hover {
            background: rgba(245, 101, 101, 0.1);
            color: var(--color-error);
        }

        /* ============================================
           MAIN CONTAINER
           ============================================ */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px;
        }

        /* Welcome header section */
        .welcome-section {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
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

        .stat-item {
            text-align: center;
        }

        .stat-number {
            font-size: 28px;
            font-weight: 700;
            color: var(--color-teacher-primary);
            display: block;
        }

        .stat-label {
            font-size: 13px;
            color: #718096;
            margin-top: 5px;
        }

        /* ============================================
           MAIN CONTENT GRID
           ============================================ */
        .main-content {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
        }

        /* Section headers */
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
            color: var(--color-teacher-primary);
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .view-all-link:hover {
            color: #fee140;
            transform: translateX(3px);
        }

        /* ============================================
           CREATE ASSESSMENT BUTTON
           ============================================ */
        .create-assessment-btn {
            padding: 12px 30px;
            background: linear-gradient(135deg, var(--color-teacher-primary) 0%, var(--color-teacher-secondary) 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .create-assessment-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(250, 112, 154, 0.4);
        }

        /* ============================================
           MY ASSESSMENTS SECTION
           ============================================ */
        .assessments-section {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        /* Filter tabs */
        .filter-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 8px 18px;
            background: #f7fafc;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            color: #718096;
            transition: all 0.3s ease;
        }

        .filter-tab.active {
            background: linear-gradient(135deg, var(--color-teacher-primary) 0%, var(--color-teacher-secondary) 100%);
            color: white;
        }

        .filter-tab:hover:not(.active) {
            background: #e2e8f0;
        }

        /* Assessment cards */
        .assessment-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .assessment-card {
            background: #f7fafc;
            border-radius: 15px;
            padding: 20px;
            transition: var(--transition);
            border: 2px solid transparent;
        }

        .assessment-card.hidden {
            display: none;
        }

        .assessment-card:hover {
            border-color: var(--color-teacher-primary);
            background: var(--color-white);
            box-shadow: 0 4px 15px rgba(250, 112, 154, 0.15);
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

        /* Status badge */
        .status-badge {
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-badge.active {
            background: #c6f6d5;
            color: #22543d;
        }

        .status-badge.draft {
            background: #feebc8;
            color: #7c2d12;
        }

        .status-badge.inactive {
            background: #e2e8f0;
            color: #718096;
        }

        /* Assessment metadata */
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

        .meta-icon {
            font-size: 16px;
        }

        /* Action buttons for teachers */
        .assessment-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn-view {
            padding: 8px 20px;
            background: linear-gradient(135deg, var(--color-teacher-primary) 0%, var(--color-teacher-secondary) 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-view:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(250, 112, 154, 0.4);
        }

        .btn-edit {
            padding: 8px 20px;
            background: white;
            color: var(--color-teacher-primary);
            border: 2px solid var(--color-teacher-primary);
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-edit:hover {
            background: var(--color-teacher-primary);
            color: white;
        }

        .btn-delete {
            padding: 8px 20px;
            background: white;
            color: #f56565;
            border: 2px solid #f56565;
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-delete:hover {
            background: #f56565;
            color: white;
        }

        /* ============================================
           SIDEBAR
           ============================================ */
        .sidebar {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .sidebar-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .sidebar-card-title {
            font-size: 18px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 20px;
        }

        /* Recent submissions */
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
            color: var(--color-teacher-primary);
            font-size: 18px;
            flex-shrink: 0;
        }

        .activity-content {
            flex: 1;
        }

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

        /* Class performance chart */
        .performance-chart {
            height: 200px;
            background: linear-gradient(135deg, #f7fafc 0%, #e2e8f0 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #718096;
            font-size: 14px;
            margin-bottom: 15px;
        }

        /* Quick action list */
        .quick-actions-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .quick-action-item {
            padding: 12px;
            background: #f7fafc;
            border-radius: var(--border-radius);
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            color: inherit;
        }

        .quick-action-item:hover {
            background: linear-gradient(135deg, rgba(250, 112, 154, 0.1), rgba(254, 225, 64, 0.1));
            transform: translateX(5px);
        }

        .quick-action-text {
            font-size: 14px;
            font-weight: 600;
            color: #2d3748;
        }

        .quick-action-icon {
            color: var(--color-teacher-primary);
            font-size: 18px;
        }

        /* ============================================
           FLOATING ACTION BUTTON
           ============================================ */
        .fab-container {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 50;
        }

        .fab-button {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--color-teacher-primary) 0%, var(--color-teacher-secondary) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--color-white);
            font-size: 24px;
            cursor: pointer;
            box-shadow: 0 6px 20px rgba(250, 112, 154, 0.4);
            transition: var(--transition);
            border: none;
            text-decoration: none;
        }

        .fab-button:hover {
            transform: scale(1.1);
            box-shadow: 0 8px 30px rgba(250, 112, 154, 0.6);
        }

        /* ============================================
           RESPONSIVE DESIGN
           ============================================ */
        @media (max-width: 1024px) {
            .main-content {
                grid-template-columns: 1fr;
            }

            .nav-search {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .navbar {
                padding: 15px;
            }

            .container {
                padding: 15px;
            }

            .welcome-section {
                flex-direction: column;
                align-items: flex-start;
                gap: 20px;
            }

            .assessment-meta {
                flex-direction: column;
                gap: 10px;
            }

            .assessment-actions {
                flex-direction: column;
            }

            .profile-name {
                display: none;
            }

            .profile-dropdown {
                right: -10px;
            }
        }

        /* ============================================
           LOADING ANIMATION
           ============================================ */
        .loading {
            display: none;
            text-align: center;
            padding: 40px;
        }

        .loading.active {
            display: block;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #e2e8f0;
            border-top-color: var(--color-teacher-primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto 15px;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }
    </style>
</head>
<body>
    <!-- ============================================
         NAVIGATION BAR
         ============================================ -->
    <nav class="navbar">
        <a href="teacher-dashboard.php" class="navbar-brand">
            <div class="brand-logo">P</div>
            <span>Teacher Portal</span>
        </a>

        <!-- Search bar -->
        <div class="nav-search">
            <input type="text" class="search-input" placeholder="Search assessments, students..." id="searchInput" aria-label="Search assessments and students" autocomplete="off">
            <span class="search-icon" aria-hidden="true">🔍</span>
        </div>

        <!-- User profile section -->
        <div class="nav-profile">
            <!-- Notification icon with badge -->
            <button class="notification-icon" onclick="showNotifications()" aria-label="View notifications" aria-describedby="notification-count">
                <span aria-hidden="true">🔔</span>
                <div class="notification-badge" id="notification-count" aria-live="polite">5</div>
            </button>

            <!-- Profile dropdown button -->
            <button class="profile-button" onclick="toggleProfileDropdown()" aria-label="Profile menu" aria-expanded="false">
                <div class="profile-avatar" aria-hidden="true"><?php echo $userInitials; ?></div>
                <span class="profile-name"><?php echo htmlspecialchars($userName); ?></span>
                <span class="dropdown-arrow">▼</span>
                <!-- Profile Dropdown Menu -->
                <div class="profile-dropdown" id="profileDropdown">
                    <div class="profile-dropdown-header">
                        <div class="dropdown-avatar"><?php echo $userInitials; ?></div>
                        <div class="dropdown-user-info">
                            <div class="dropdown-user-name"><?php echo htmlspecialchars($userName); ?></div>
                            <div class="dropdown-user-email"><?php echo htmlspecialchars($userEmail); ?></div>
                        </div>
                    </div>
                    <div class="profile-dropdown-menu">
                        <a href="teacher-profile.php" class="dropdown-item">
                            <span class="dropdown-item-icon">👤</span>
                            <span>My Profile</span>
                        </a>
                        <a href="teacher-classes.php" class="dropdown-item">
                            <span class="dropdown-item-icon">👥</span>
                            <span>View Classes</span>
                        </a>
                        <a href="teacher-assessments.php" class="dropdown-item">
                            <span class="dropdown-item-icon">📝</span>
                            <span>My Assessments</span>
                        </a>
                        <div class="dropdown-divider"></div>
                        <a onclick="handleLogout()" class="dropdown-item logout">
                            <span class="dropdown-item-icon">🚪</span>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </button>
        </div>
    </nav>

    <!-- ============================================
         MAIN DASHBOARD CONTAINER
         ============================================ -->
    <div class="container">
        <!-- Welcome section with quick stats -->
        <div class="welcome-section">
            <div class="welcome-content">
                <h1>Welcome back, <?php echo htmlspecialchars($userName); ?>! 👨‍🏫</h1>
                <p>Manage your assessments and track student performance</p>
            </div>
            <div class="quick-stats">
                <div class="stat-item">
                    <span class="stat-number">15</span>
                    <span class="stat-label">Active Tests</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number">342</span>
                    <span class="stat-label">Students</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number">1,248</span>
                    <span class="stat-label">Submissions</span>
                </div>
            </div>
        </div>

        <!-- Main content grid -->
        <div class="main-content">
            <!-- My assessments section -->
            <div class="assessments-section">
                <div class="section-header">
                    <h2 class="section-title">My Assessments</h2>
                    <a href="create-assessment.php" class="create-assessment-btn">
                        ➕ Create New Test
                    </a>
                </div>

                <!-- Filter tabs -->
                <div class="filter-tabs" role="tablist" aria-label="Filter assessments by status">
                    <button class="filter-tab active" data-status="all" role="tab" aria-selected="true" aria-controls="assessmentList">All Tests</button>
                    <button class="filter-tab" data-status="active" role="tab" aria-selected="false" aria-controls="assessmentList">Active</button>
                    <button class="filter-tab" data-status="draft" role="tab" aria-selected="false" aria-controls="assessmentList">Drafts</button>
                    <button class="filter-tab" data-status="inactive" role="tab" aria-selected="false" aria-controls="assessmentList">Inactive</button>
                </div>

                <!-- Assessment list -->
                <div class="assessment-list" id="assessmentList">
                    <!-- Assessment Card 1 - Active -->
                    <div class="assessment-card" data-status="active">
                        <div class="assessment-header">
                            <div>
                                <div class="assessment-title">Quantitative Aptitude - Set 1</div>
                                <div class="assessment-category">Aptitude • Mathematics</div>
                            </div>
                            <span class="status-badge active">Active</span>
                        </div>
                        <div class="assessment-meta">
                            <div class="meta-item">
                                <span class="meta-icon">❓</span>
 Questions</span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-icon">👥</span>
                                <span>85 Attempts</span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-icon">📊</span>
                                <span>Avg: 76%</span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-icon">📅</span>
                                <span>Created: Dec 1, 2025</span>
                            </div>
                        </div>
                        <div class="assessment-actions">
                            <button class="btn-view" data-assessment-id="1" aria-label="View results for Quantitative Aptitude - Set 1">View Results</button>
                            <button class="btn-edit" data-assessment-id="1" aria-label="Edit Quantitative Aptitude - Set 1">Edit</button>
                            <button class="btn-delete" data-assessment-id="1" aria-label="Delete Quantitative Aptitude - Set 1">Delete</button>
                        </div>
                    </div>

                    <!-- Assessment Card 2 - Draft -->
                    <div class="assessment-card" data-status="draft">
                        <div class="assessment-header">
                            <div>
                                <div class="assessment-title">Data Structures Advanced</div>
                                <div class="assessment-category">Technical • CS Fundamentals</div>
                            </div>
                            <span class="status-badge draft">Draft</span>
                        </div>
                        <div class="assessment-meta">
                            <div class="meta-item">
                                <span class="meta-icon">❓</span>
                                <span>18 Questions</span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-icon">⚠️</span>
                                <span>Incomplete</span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-icon">📅</span>
                                <span>Last edited: Dec 18, 2025</span>
                            </div>
                        </div>
                        <div class="assessment-actions">
                            <button class="btn-edit" data-assessment-id="2" aria-label="Continue editing Data Structures Advanced">Continue Editing</button>
                            <button class="btn-delete" data-assessment-id="2" aria-label="Delete Data Structures Advanced">Delete</button>
                        </div>
                    </div>

                    <!-- Assessment Card 3 - Active -->
                    <div class="assessment-card" data-status="active">
                        <div class="assessment-header">
                            <div>
                                <div class="assessment-title">Python Programming Basics</div>
                                <div class="assessment-category">Coding • Python</div>
                            </div>
                            <span class="status-badge active">Active</span>
                        </div>
                        <div class="assessment-meta">
                            <div class="meta-item">
                                <span class="meta-icon">❓</span>
                                <span>25 Questions</span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-icon">👥</span>
                                <span>124 Attempts</span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-icon">📊</span>
                                <span>Avg: 68%</span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-icon">📅</span>
                                <span>Created: Nov 28, 2025</span>
                            </div>
                        </div>
                        <div class="assessment-actions">
                            <button class="btn-view" data-assessment-id="3">View Results</button>
                            <button class="btn-edit" data-assessment-id="3">Edit</button>
                            <button class="btn-delete" data-assessment-id="3">Delete</button>
                        </div>
                    </div>

                    <!-- Assessment Card 4 - Inactive -->
                    <div class="assessment-card" data-status="inactive">
                        <div class="assessment-header">
                            <div>
                                <div class="assessment-title">Logical Reasoning Set 2</div>
                                <div class="assessment-category">Aptitude • Logic</div>
                            </div>
                            <span class="status-badge inactive">Inactive</span>
                        </div>
                        <div class="assessment-meta">
                            <div class="meta-item">
                                <span class="meta-icon">❓</span>
                                <span>35 Questions</span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-icon">👥</span>
                                <span>56 Attempts</span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-icon">📊</span>
                                <span>Avg: 71%</span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-icon">📅</span>
                                <span>Deactivated: Dec 10, 2025</span>
                            </div>
                        </div>
                        <div class="assessment-actions">
                            <button class="btn-view" data-assessment-id="4">View Results</button>
                            <button class="btn-edit" data-assessment-id="4">Edit</button>
                            <button class="btn-delete" data-assessment-id="4">Delete</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sidebar with activity and quick actions -->
            <div class="sidebar">
                <!-- Recent submissions card -->
                <div class="sidebar-card">
                    <h3 class="sidebar-card-title">Recent Submissions</h3>
                    <div class="activity-list">
                        <div class="activity-item">
                            <div class="activity-icon">📝</div>
                            <div class="activity-content">
                                <div class="activity-title">Alice Johnson - Python Test</div>
                                <div class="activity-time">5 minutes ago • Score: 88%</div>
                            </div>
                        </div>
                        <div class="activity-item">
                            <div class="activity-icon">📝</div>
                            <div class="activity-content">
                                <div class="activity-title">Bob Smith - Aptitude Test</div>
                                <div class="activity-time">12 minutes ago • Score: 74%</div>
                            </div>
                        </div>
                        <div class="activity-item">
                            <div class="activity-icon">📝</div>
                            <div class="activity-content">
                                <div class="activity-title">Carol White - Data Structures</div>
                                <div class="activity-time">23 minutes ago • Score: 92%</div>
                            </div>
                        </div>
                        <div class="activity-item">
                            <div class="activity-icon">📝</div>
                            <div class="activity-content">
                                <div class="activity-title">David Lee - SQL Basics</div>
                                <div class="activity-time">1 hour ago • Score: 81%</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Class performance card -->
                <div class="sidebar-card">
                    <h3 class="sidebar-card-title">Class Performance</h3>
                    <div class="performance-chart">
                        📈 Performance Trends
                        <br><small>(Chart visualization will be here)</small>
                    </div>
                </div>

                <!-- Quick actions card -->
                <div class="sidebar-card">
                    <h3 class="sidebar-card-title">Quick Actions</h3>
                    <div class="quick-actions-list">
                        <a href="create-assessment.php" class="quick-action-item" role="button" aria-label="Create New Assessment">
                            <span class="quick-action-text">Create New Assessment</span>
                            <span class="quick-action-icon" aria-hidden="true">➕</span>
                        </a>
                        <a href="view-all-results.php" class="quick-action-item" role="button" aria-label="View All Results">
                            <span class="quick-action-text">View All Results</span>
                            <span class="quick-action-icon" aria-hidden="true">📊</span>
                        </a>
                        <a href="student-management.php" class="quick-action-item" role="button" aria-label="Manage Students">
                            <span class="quick-action-text">Manage Students</span>
                            <span class="quick-action-icon" aria-hidden="true">👥</span>
                        </a>
                        <a href="reports.php" class="quick-action-item" role="button" aria-label="Generate Reports">
                            <span class="quick-action-text">Generate Reports</span>
                            <span class="quick-action-icon" aria-hidden="true">📄</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Floating Action Button -->
    <div class="fab-container">
        <a href="create-assessment.php" class="fab-button" aria-label="Create New Assessment" title="Create New Assessment">
            ➕
        </a>
    </div>

    <script>
        /* ============================================
           DEBOUNCE UTILITY
           ============================================ */
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        /* ============================================
           PROFILE DROPDOWN TOGGLE
           ============================================ */
        function toggleProfileDropdown() {
            const dropdown = document.getElementById('profileDropdown');
            const button = document.querySelector('.profile-button');
            
            dropdown.classList.toggle('active');
            
            const isExpanded = dropdown.classList.contains('active');
            button.setAttribute('aria-expanded', isExpanded);
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            const profileButton = document.querySelector('.profile-button');
            const dropdown = document.getElementById('profileDropdown');
            
            if (!profileButton.contains(e.target) && dropdown.classList.contains('active')) {
                dropdown.classList.remove('active');
                profileButton.setAttribute('aria-expanded', 'false');
            }
        });
        
        function handleLogout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = 'logout.php';
            }
        }

        /* ============================================
           ASSESSMENT FILTERING
           ============================================ */
        function filterAssessments(status, targetElement) {
            const tabs = document.querySelectorAll('.filter-tab');
            tabs.forEach(tab => tab.classList.remove('active'));
            if (targetElement) {
                targetElement.classList.add('active');
            }

            const cards = document.querySelectorAll('.assessment-card');
            cards.forEach(card => {
                if (status === 'all') {
                    card.classList.remove('hidden');
                } else {
                    const cardStatus = card.dataset.status;
                    if (cardStatus === status) {
                        card.classList.remove('hidden');
                    } else {
                        card.classList.add('hidden');
                    }
                }
            });
        }

        document.addEventListener('click', function(e) {
            const filterTab = e.target.closest('.filter-tab');
            if (filterTab) {
                const status = filterTab.dataset.status;
                if (status) {
                    e.preventDefault();
                    filterAssessments(status, filterTab);
                    document.querySelectorAll('.filter-tab').forEach(tab => {
                        tab.setAttribute('aria-selected', 'false');
                    });
                    filterTab.setAttribute('aria-selected', 'true');
                }
            }
        });

        /* ============================================
           VIEW RESULTS
           ============================================ */
        function viewResults(assessmentId) {
            if (!assessmentId) return;
            console.log('Viewing results for assessment:', assessmentId);
            alert(`Opening results analytics for assessment ${assessmentId}...\n\nWill show:\n• Student scores\n• Question-wise analysis\n• Performance trends\n• Export options`);
        }

        /* ============================================
           EDIT ASSESSMENT
           ============================================ */
        function editAssessment(assessmentId) {
            if (!assessmentId) return;
            console.log('Editing assessment:', assessmentId);
            alert(`Opening editor for assessment ${assessmentId}...\n\nWill allow editing:\n• Questions\n• Settings\n• Difficulty levels\n• Time limits`);
        }

        /* ============================================
           DELETE ASSESSMENT
           ============================================ */
        function deleteAssessment(assessmentId) {
            if (!assessmentId) return;
            if (confirm('Are you sure you want to delete this assessment?\n\nThis action cannot be undone. All student attempts and results will be permanently deleted.')) {
                console.log('Deleting assessment:', assessmentId);
                alert(`Assessment ${assessmentId} deleted successfully!`);
            }
        }

        document.addEventListener('click', function(e) {
            const assessmentId = e.target.dataset.assessmentId;
            if (!assessmentId) return;
            
            if (e.target.classList.contains('btn-view')) {
                viewResults(parseInt(assessmentId));
            } else if (e.target.classList.contains('btn-edit')) {
                editAssessment(parseInt(assessmentId));
            } else if (e.target.classList.contains('btn-delete')) {
                deleteAssessment(parseInt(assessmentId));
            }
        });

        /* ============================================
           SEARCH FUNCTIONALITY
           ============================================ */
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            const performSearch = debounce(function(e) {
                const searchTerm = e.target.value.toLowerCase().trim();
                const cards = document.querySelectorAll('.assessment-card');
                
                cards.forEach(card => {
                    const title = card.querySelector('.assessment-title')?.textContent?.toLowerCase() || '';
                    const category = card.querySelector('.assessment-category')?.textContent?.toLowerCase() || '';
                    
                    if (!searchTerm || title.includes(searchTerm) || category.includes(searchTerm)) {
                        card.classList.remove('hidden');
                    } else {
                        card.classList.add('hidden');
                    }
                });
            }, 300);

            searchInput.addEventListener('input', performSearch);
        }

        /* ============================================
           NOTIFICATION SYSTEM
           ============================================ */
        function showNotifications() {
            alert('Notifications:\n\n1. 15 new submissions pending review\n2. Alice Johnson scored 100% on Python Test\n3. New student registered: Mike Brown\n4. Reminder: Review draft assessments\n5. System update scheduled for tonight');
        }

        /* ============================================
           PAGE LOAD ANIMATIONS
           ============================================ */
        window.addEventListener('load', function() {
            console.log('Teacher Dashboard loaded successfully');
            
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                requestAnimationFrame(() => {
                    setTimeout(() => {
                        card.style.animation = 'fadeInUp 0.5s ease forwards';
                    }, index * 100);
                });
            });
        });

        /* ============================================
           KEYBOARD SHORTCUTS
           ============================================ */
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                window.location.href = 'create-assessment.php';
            }
            
            if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
                e.preventDefault();
                window.location.href = 'reports.php';
            }
        });

        /* ============================================
           ADD ENTRANCE ANIMATIONS
           ============================================ */
        const style = document.createElement('style');
        style.textContent = `
            @keyframes fadeInUp {
                from {
                    opacity: 0;
                    transform: translateY(20px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            
            .stat-card {
                opacity: 0;
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
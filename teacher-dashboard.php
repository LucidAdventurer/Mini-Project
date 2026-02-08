<?php
/* ========================================
 * TEACHER DASHBOARD - FIXED VERSION
 * ======================================== */

// Load configuration (session_start is already in config.php)
require "config.php";

/* 🔒 SESSION GUARD - Fixed to check correct session variable */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: index.html");
    exit;
}

/* Fetch user info from database - FIXED: Using correct column names */
// BEFORE: SELECT name, email FROM users WHERE uid = ?
// AFTER:  SELECT full_name, email FROM users WHERE user_id = ?
$stmt = $conn->prepare("SELECT full_name, email FROM users WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']); // Changed from uid to user_id
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Changed from 'name' to 'full_name'
$userName = $user['full_name'] ?? 'Teacher';
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
            background: var(--color-white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            min-width: 220px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: var(--transition);
            z-index: 1001;
        }

        .profile-dropdown.active {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .profile-dropdown::before {
            content: '';
            position: absolute;
            top: -8px;
            right: 20px;
            width: 16px;
            height: 16px;
            background: var(--color-white);
            transform: rotate(45deg);
            border-radius: 3px;
        }

        .dropdown-header {
            padding: 15px 20px;
            border-bottom: 1px solid var(--color-border);
        }

        .dropdown-name {
            font-weight: 600;
            font-size: 14px;
            color: var(--color-text);
            margin-bottom: 4px;
        }

        .dropdown-email {
            font-size: 13px;
            color: var(--color-text-light);
        }

        .dropdown-menu {
            padding: 8px 0;
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            color: var(--color-text);
            text-decoration: none;
            font-size: 14px;
            transition: var(--transition);
            cursor: pointer;
        }

        .dropdown-item:hover {
            background: var(--color-bg-light);
        }

        .dropdown-item.danger {
            color: var(--color-error);
        }

        .dropdown-item.danger:hover {
            background: rgba(245, 101, 101, 0.1);
        }

        .dropdown-divider {
            height: 1px;
            background: var(--color-border);
            margin: 8px 0;
        }

        /* ============================================
           MAIN CONTAINER
           ============================================ */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        /* ============================================
           WELCOME HEADER
           ============================================ */
        .welcome-section {
            margin-bottom: 30px;
        }

        .welcome-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--color-text);
            margin-bottom: 8px;
        }

        .welcome-subtitle {
            font-size: 15px;
            color: var(--color-text-light);
        }

        /* ============================================
           STATISTICS CARDS
           ============================================ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--color-white);
            border-radius: var(--border-radius);
            padding: 24px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            border-left: 4px solid var(--color-teacher-secondary);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }

        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .stat-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--color-text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            background: linear-gradient(135deg, var(--color-teacher-primary) 0%, var(--color-teacher-secondary) 100%);
            color: var(--color-white);
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--color-text);
            margin-bottom: 4px;
        }

        .stat-change {
            font-size: 13px;
            color: var(--color-success);
            font-weight: 600;
        }

        .stat-change.negative {
            color: var(--color-error);
        }

        /* ============================================
           ASSESSMENTS SECTION
           ============================================ */
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .section-title {
            font-size: 22px;
            font-weight: 700;
            color: var(--color-text);
        }

        .filter-tabs {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 8px 16px;
            background: var(--color-white);
            border: 2px solid var(--color-border);
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            color: var(--color-text-light);
            cursor: pointer;
            transition: var(--transition);
        }

        .filter-tab:hover {
            border-color: var(--color-teacher-secondary);
            color: var(--color-teacher-secondary);
        }

        .filter-tab.active {
            background: linear-gradient(135deg, var(--color-teacher-primary) 0%, var(--color-teacher-secondary) 100%);
            border-color: var(--color-teacher-primary);
            color: var(--color-white);
        }

        /* ============================================
           ASSESSMENTS GRID
           ============================================ */
        .assessments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 24px;
            margin-bottom: 30px;
        }

        .assessment-card {
            background: var(--color-white);
            border-radius: var(--border-radius);
            padding: 24px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .assessment-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--color-teacher-primary) 0%, var(--color-teacher-secondary) 100%);
        }

        .assessment-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }

        .assessment-card.hidden {
            display: none;
        }

        .assessment-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .assessment-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--color-text);
            margin-bottom: 8px;
        }

        .assessment-category {
            display: inline-block;
            padding: 4px 12px;
            background: #f0f9ff;
            color: #0284c7;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }

        .assessment-meta {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 16px;
            padding: 12px;
            background: var(--color-bg-light);
            border-radius: 8px;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--color-text-light);
        }

        .meta-icon {
            font-size: 16px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 16px;
        }

        .status-badge.active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge.draft {
            background: #fef3c7;
            color: #92400e;
        }

        .status-badge.completed {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-badge.scheduled {
            background: #e0e7ff;
            color: #3730a3;
        }

        .assessment-actions {
            display: flex;
            gap: 8px;
        }

        .btn {
            padding: 10px 16px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            flex: 1;
            text-align: center;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--color-teacher-primary) 0%, var(--color-teacher-secondary) 100%);
            color: var(--color-white);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(46, 7, 63, 0.3);
        }

        .btn-secondary {
            background: var(--color-bg-light);
            color: var(--color-text);
        }

        .btn-secondary:hover {
            background: var(--color-border);
        }

        .btn-danger {
            background: #fee2e2;
            color: #dc2626;
        }

        .btn-danger:hover {
            background: #fecaca;
        }

        /* ============================================
           FLOATING ACTION BUTTON
           ============================================ */
        .fab-container {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 999;
        }

        .fab-button {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--color-teacher-primary) 0%, var(--color-teacher-secondary) 100%);
            color: var(--color-white);
            border: none;
            font-size: 24px;
            cursor: pointer;
            box-shadow: var(--shadow-lg);
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
        }

        .fab-button:hover {
            transform: scale(1.1) rotate(90deg);
            box-shadow: 0 8px 25px rgba(46, 7, 63, 0.4);
        }

        /* ============================================
           RESPONSIVE DESIGN
           ============================================ */
        @media (max-width: 768px) {
            .container {
                padding: 20px 15px;
            }

            .welcome-title {
                font-size: 22px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .assessments-grid {
                grid-template-columns: 1fr;
            }

            .nav-search {
                display: none;
            }

            .navbar {
                padding: 10px 15px;
            }

            .filter-tabs {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
        }

        @media (max-width: 480px) {
            .welcome-title {
                font-size: 20px;
            }

            .stat-value {
                font-size: 28px;
            }

            .assessment-title {
                font-size: 16px;
            }

            .fab-button {
                width: 50px;
                height: 50px;
                font-size: 20px;
            }

            .fab-container {
                bottom: 20px;
                right: 20px;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar">
        <a href="teacher-dashboard.php" class="navbar-brand">
            <div class="brand-logo">PT</div>
            <span>Placement Portal</span>
        </a>

        <div class="nav-search">
            <input type="text" class="search-input" id="searchInput" placeholder="Search assessments..." aria-label="Search assessments">
            <span class="search-icon">🔍</span>
        </div>

        <div class="nav-profile">
            <div class="notification-icon" onclick="showNotifications()" aria-label="Notifications" title="Notifications">
                🔔
                <span class="notification-badge">5</span>
            </div>

            <button class="profile-button" onclick="toggleProfileDropdown()" aria-expanded="false" aria-haspopup="true">
                <div class="profile-avatar"><?= htmlspecialchars($userInitials) ?></div>
                <span class="profile-name"><?= htmlspecialchars($userName) ?></span>
                <span style="color: #a0aec0;">▼</span>
            </button>

            <div class="profile-dropdown" id="profileDropdown">
                <div class="dropdown-header">
                    <div class="dropdown-name"><?= htmlspecialchars($userName) ?></div>
                    <div class="dropdown-email"><?= htmlspecialchars($userEmail) ?></div>
                </div>
                <div class="dropdown-menu">
                    <a href="profile.php" class="dropdown-item">
                        <span>👤</span>
                        <span>My Profile</span>
                    </a>
                    <a href="settings.php" class="dropdown-item">
                        <span>⚙️</span>
                        <span>Settings</span>
                    </a>
                    <a href="help.php" class="dropdown-item">
                        <span>❓</span>
                        <span>Help & Support</span>
                    </a>
                    <div class="dropdown-divider"></div>
                    <a onclick="handleLogout()" class="dropdown-item danger">
                        <span>🚪</span>
                        <span>Logout</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Container -->
    <div class="container">
        <!-- Welcome Section -->
        <div class="welcome-section">
            <h1 class="welcome-title">Welcome back, <?= htmlspecialchars($userName) ?>! 👋</h1>
            <p class="welcome-subtitle">Here's what's happening with your assessments today</p>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-label">Total Assessments</span>
                    <div class="stat-icon">📝</div>
                </div>
                <div class="stat-value">24</div>
                <div class="stat-change">↑ 3 new this month</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-label">Active Students</span>
                    <div class="stat-icon">👥</div>
                </div>
                <div class="stat-value">156</div>
                <div class="stat-change">↑ 12 this week</div>
            </div>
        </div>

        <!-- Assessments Section -->
        <div class="section-header">
            <h2 class="section-title">My Assessments</h2>
            <div class="filter-tabs" role="tablist">
                <button class="filter-tab active" data-status="all" role="tab" aria-selected="true">All</button>
                <button class="filter-tab" data-status="active" role="tab" aria-selected="false">Active</button>
                <button class="filter-tab" data-status="draft" role="tab" aria-selected="false">Draft</button>
                <button class="filter-tab" data-status="completed" role="tab" aria-selected="false">Completed</button>
            </div>
        </div>

        <!-- Assessments Grid -->
        <div class="assessments-grid">
            <!-- Assessment Card 1 -->
            <div class="assessment-card" data-status="active">
                <div class="assessment-header">
                    <div>
                        <h3 class="assessment-title">Python Programming Basics</h3>
                        <span class="assessment-category">Programming</span>
                    </div>
                </div>
                
                <div class="assessment-meta">
                    <div class="meta-item">
                        <span class="meta-icon">📅</span>
                        <span>Due: Feb 15, 2026</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-icon">⏱️</span>
                        <span>Duration: 60 minutes</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-icon">📝</span>
                        <span>25 Questions</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-icon">👥</span>
                        <span>42 Students Enrolled</span>
                    </div>
                </div>

                <span class="status-badge active">
                    <span>●</span>
                    <span>Active</span>
                </span>

                <div class="assessment-actions">
                    <button class="btn btn-primary btn-view" data-assessment-id="1">View Results</button>
                    <button class="btn btn-secondary btn-edit" data-assessment-id="1">Edit</button>
                </div>
            </div>

            <!-- Assessment Card 2 -->
            <div class="assessment-card" data-status="draft">
                <div class="assessment-header">
                    <div>
                        <h3 class="assessment-title">Data Structures & Algorithms</h3>
                        <span class="assessment-category">Computer Science</span>
                    </div>
                </div>
                
                <div class="assessment-meta">
                    <div class="meta-item">
                        <span class="meta-icon">📅</span>
                        <span>Scheduled: Feb 20, 2026</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-icon">⏱️</span>
                        <span>Duration: 90 minutes</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-icon">📝</span>
                        <span>30 Questions</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-icon">👥</span>
                        <span>0 Students Enrolled</span>
                    </div>
                </div>

                <span class="status-badge draft">
                    <span>●</span>
                    <span>Draft</span>
                </span>

                <div class="assessment-actions">
                    <button class="btn btn-primary btn-edit" data-assessment-id="2">Continue Editing</button>
                    <button class="btn btn-danger btn-delete" data-assessment-id="2">Delete</button>
                </div>
            </div>

            <!-- Assessment Card 3 -->
            <div class="assessment-card" data-status="completed">
                <div class="assessment-header">
                    <div>
                        <h3 class="assessment-title">Database Management Quiz</h3>
                        <span class="assessment-category">Database</span>
                    </div>
                </div>
                
                <div class="assessment-meta">
                    <div class="meta-item">
                        <span class="meta-icon">📅</span>
                        <span>Completed: Jan 28, 2026</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-icon">⏱️</span>
                        <span>Duration: 45 minutes</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-icon">📝</span>
                        <span>20 Questions</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-icon">👥</span>
                        <span>38 Students Completed</span>
                    </div>
                </div>

                <span class="status-badge completed">
                    <span>●</span>
                    <span>Completed</span>
                </span>

                <div class="assessment-actions">
                    <button class="btn btn-primary btn-view" data-assessment-id="3">View Results</button>
                    <button class="btn btn-secondary btn-edit" data-assessment-id="3">Review</button>
                </div>
            </div>

            <!-- Assessment Card 4 -->
            <div class="assessment-card" data-status="active">
                <div class="assessment-header">
                    <div>
                        <h3 class="assessment-title">Web Development Fundamentals</h3>
                        <span class="assessment-category">Web Development</span>
                    </div>
                </div>
                
                <div class="assessment-meta">
                    <div class="meta-item">
                        <span class="meta-icon">📅</span>
                        <span>Due: Feb 18, 2026</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-icon">⏱️</span>
                        <span>Duration: 75 minutes</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-icon">📝</span>
                        <span>28 Questions</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-icon">👥</span>
                        <span>51 Students Enrolled</span>
                    </div>
                </div>

                <span class="status-badge active">
                    <span>●</span>
                    <span>Active</span>
                </span>

                <div class="assessment-actions">
                    <button class="btn btn-primary btn-view" data-assessment-id="4">View Results</button>
                    <button class="btn btn-secondary btn-edit" data-assessment-id="4">Edit</button>
                </div>
            </div>

            <!-- Assessment Card 5 -->
            <div class="assessment-card" data-status="scheduled">
                <div class="assessment-header">
                    <div>
                        <h3 class="assessment-title">Machine Learning Basics</h3>
                        <span class="assessment-category">AI & ML</span>
                    </div>
                </div>
                
                <div class="assessment-meta">
                    <div class="meta-item">
                        <span class="meta-icon">📅</span>
                        <span>Scheduled: Feb 25, 2026</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-icon">⏱️</span>
                        <span>Duration: 120 minutes</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-icon">📝</span>
                        <span>35 Questions</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-icon">👥</span>
                        <span>28 Students Enrolled</span>
                    </div>
                </div>

                <span class="status-badge scheduled">
                    <span>●</span>
                    <span>Scheduled</span>
                </span>

                <div class="assessment-actions">
                    <button class="btn btn-secondary btn-edit" data-assessment-id="5">Preview</button>
                    <button class="btn btn-secondary btn-edit" data-assessment-id="5">Edit</button>
                </div>
            </div>

            <!-- Assessment Card 6 -->
            <div class="assessment-card" data-status="completed">
                <div class="assessment-header">
                    <div>
                        <h3 class="assessment-title">Networking Essentials</h3>
                        <span class="assessment-category">Networking</span>
                    </div>
                </div>
                
                <div class="assessment-meta">
                    <div class="meta-item">
                        <span class="meta-icon">📅</span>
                        <span>Completed: Jan 15, 2026</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-icon">⏱️</span>
                        <span>Duration: 50 minutes</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-icon">📝</span>
                        <span>22 Questions</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-icon">👥</span>
                        <span>45 Students Completed</span>
                    </div>
                </div>

                <span class="status-badge completed">
                    <span>●</span>
                    <span>Completed</span>
                </span>

                <div class="assessment-actions">
                    <button class="btn btn-primary btn-view" data-assessment-id="6">View Results</button>
                    <button class="btn btn-secondary btn-edit" data-assessment-id="6">Archive</button>
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
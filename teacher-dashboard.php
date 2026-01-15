<?php
session_start();

/* 🔒 SESSION GUARD */
if (!isset($_SESSION['uid']) || $_SESSION['role'] !== 'student') {
    header("Location: index.html");
    exit;
}

/* (Optional) fetch user info later from DB using $_SESSION['uid'] */
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
            /* Colors #4facfe #00f2fe*/

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
           Top navigation with logo, search, and profile
           Uses teacher theme colors (purple/orange)
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

        /* ============================================
           MAIN CONTAINER
           Dashboard content wrapper
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
            /* Teacher theme */
            display: block;
        }

        .stat-label {
            font-size: 13px;
            color: #718096;
            margin-top: 5px;
        }

        /* ============================================
           PERFORMANCE OVERVIEW CARDS
           Quick statistics display
           ============================================ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }

        /* Decorative gradient background */
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, rgba(250, 112, 154, 0.1), transparent);
            border-radius: 50%;
            transform: translate(30%, -30%);
        }

        .stat-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .stat-card-title {
            font-size: 14px;
            color: #718096;
            font-weight: 600;
        }

        .stat-card-icon {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, var(--color-teacher-primary) 0%, var(--color-teacher-secondary) 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }

        .stat-card-value {
            font-size: 36px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .stat-card-change {
            font-size: 13px;
            color: #48bb78;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* ============================================
           MAIN CONTENT GRID
           Assessments and student performance
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
           Prominent CTA for creating new tests
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
           List of created tests
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
           SIDEBAR - Recent Activity & Analytics
           Right sidebar with updates
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
           Bottom right corner quick access
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
           Mobile and tablet adjustments
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

            .stats-grid {
                grid-template-columns: 1fr;
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
        }

        /* ============================================
           LOADING ANIMATION
           For dynamic content loading
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
         Top navigation with teacher theme
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
            <button class="profile-button" onclick="toggleProfileMenu()" aria-label="Profile menu" aria-expanded="false">
                <div class="profile-avatar" aria-hidden="true">DR</div>
                <span class="profile-name">Dr. Rajesh Kumar</span>
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
                <h1>Welcome back, Dr. Kumar! 👨‍🏫</h1>
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

        <!-- Performance overview cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-card-header">
                    <span class="stat-card-title">Total Assessments</span>
                    <div class="stat-card-icon">📝</div>
                </div>
                <div class="stat-card-value">23</div>
                <div class="stat-card-change">
                    <span>↗</span> 3 created this month
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-card-header">
                    <span class="stat-card-title">Avg. Class Score</span>
                    <div class="stat-card-icon">📊</div>
                </div>
                <div class="stat-card-value">72%</div>
                <div class="stat-card-change">
                    <span>↗</span> +3% from last week
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-card-header">
                    <span class="stat-card-title">Pending Reviews</span>
                    <div class="stat-card-icon">⏳</div>
                </div>
                <div class="stat-card-value">47</div>
                <div class="stat-card-change">
                    Submissions to review
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-card-header">
                    <span class="stat-card-title">Active Students</span>
                    <div class="stat-card-icon">👥</div>
                </div>
                <div class="stat-card-value">342</div>
                <div class="stat-card-change">
                    Across all classes
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
                                <span>30 Questions</span>
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
                            <button class="btn-view" onclick="viewResults(3)">View Results</button>
                            <button class="btn-edit" onclick="editAssessment(3)">Edit</button>
                            <button class="btn-delete" onclick="deleteAssessment(3)">Delete</button>
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
                            <button class="btn-view" onclick="viewResults(4)">View Results</button>
                            <button class="btn-edit" onclick="editAssessment(4)">Edit</button>
                            <button class="btn-delete" onclick="deleteAssessment(4)">Delete</button>
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
           Optimizes event handlers
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
           ASSESSMENT FILTERING
           Filter tests by status - optimized with event delegation
           ============================================ */
        function filterAssessments(status, targetElement) {
            // Update active tab
            const tabs = document.querySelectorAll('.filter-tab');
            tabs.forEach(tab => tab.classList.remove('active'));
            if (targetElement) {
                targetElement.classList.add('active');
            }

            // Filter assessment cards - use class toggle for better performance
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

        // Use event delegation for filter tabs
        document.addEventListener('click', function(e) {
            const filterTab = e.target.closest('.filter-tab');
            if (filterTab) {
                const status = filterTab.dataset.status;
                if (status) {
                    e.preventDefault();
                    filterAssessments(status, filterTab);
                    // Update ARIA attributes for accessibility
                    document.querySelectorAll('.filter-tab').forEach(tab => {
                        tab.setAttribute('aria-selected', 'false');
                    });
                    filterTab.setAttribute('aria-selected', 'true');
                }
            }
        });

        /* ============================================
           VIEW RESULTS
           Opens results analytics page for an assessment
           SECURITY: Verify teacher owns this assessment
           ============================================ */
        function viewResults(assessmentId) {
            if (!assessmentId) return;
            console.log('Viewing results for assessment:', assessmentId);
            // In production: window.location.href = `assessment-results.php?id=${assessmentId}`;
            alert(`Opening results analytics for assessment ${assessmentId}...\n\nWill show:\n• Student scores\n• Question-wise analysis\n• Performance trends\n• Export options`);
        }

        /* ============================================
           EDIT ASSESSMENT
           Opens assessment editor
           SECURITY: Verify teacher owns this assessment
           ============================================ */
        function editAssessment(assessmentId) {
            if (!assessmentId) return;
            console.log('Editing assessment:', assessmentId);
            // In production: window.location.href = `edit-assessment.php?id=${assessmentId}`;
            alert(`Opening editor for assessment ${assessmentId}...\n\nWill allow editing:\n• Questions\n• Settings\n• Difficulty levels\n• Time limits`);
        }

        /* ============================================
           DELETE ASSESSMENT
           Deletes an assessment with confirmation
           SECURITY: Verify teacher owns this assessment
           WARNING: This should be a soft delete in production
           ============================================ */
        function deleteAssessment(assessmentId) {
            if (!assessmentId) return;
            // Confirm deletion
            if (confirm('Are you sure you want to delete this assessment?\n\nThis action cannot be undone. All student attempts and results will be permanently deleted.')) {
                console.log('Deleting assessment:', assessmentId);
                
                // In production, make AJAX call to backend
                // Show loading state
                alert(`Assessment ${assessmentId} deleted successfully!`);
                
                // In production, remove card from DOM or reload page
                // location.reload();
            }
        }

        // Use event delegation for assessment action buttons
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
           Real-time search through assessments and students - optimized with debouncing
           ============================================ */
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            const performSearch = debounce(function(e) {
                const searchTerm = e.target.value.toLowerCase().trim();
            const cards = document.querySelectorAll('.assessment-card');
            
            cards.forEach(card => {
                    const title = card.querySelector('.assessment-title')?.textContent?.toLowerCase() || '';
                    const category = card.querySelector('.assessment-category')?.textContent?.toLowerCase() || '';
                
                    // Use class toggle instead of inline styles for better performance
                    if (!searchTerm || title.includes(searchTerm) || category.includes(searchTerm)) {
                        card.classList.remove('hidden');
                } else {
                        card.classList.add('hidden');
                }
            });
            }, 300); // 300ms debounce

            searchInput.addEventListener('input', performSearch);
        }

        /* ============================================
           NOTIFICATION SYSTEM
           Show notifications dropdown
           ============================================ */
        function showNotifications() {
            // In production, this would open a dropdown with notifications
            alert('Notifications:\n\n1. 15 new submissions pending review\n2. Alice Johnson scored 100% on Python Test\n3. New student registered: Mike Brown\n4. Reminder: Review draft assessments\n5. System update scheduled for tonight');
        }

        /* ============================================
           PROFILE MENU
           Toggle user profile dropdown
           ============================================ */
        function toggleProfileMenu() {
            // In production, this would show a dropdown menu with:
            // - View Profile
            // - Settings
            // - My Classes
            // - Logout
            alert('Profile Menu:\n\n• View Profile\n• Account Settings\n• My Classes\n• Assessment Library\n• Help & Support\n• Logout');
        }

        /* ============================================
           EXPORT RESULTS
           Export assessment results to various formats
           ============================================ */
        function exportResults(assessmentId, format) {
            console.log(`Exporting results for assessment ${assessmentId} as ${format}`);
            // In production, call backend API to generate export file
            alert(`Generating ${format.toUpperCase()} export...\n\nThis will include:\n• Student names and scores\n• Question-wise breakdown\n• Statistics and analytics`);
        }

        /* ============================================
           BULK ACTIONS
           Perform actions on multiple assessments
           ============================================ */
        function bulkAction(action) {
            // Get selected assessments (would need checkboxes in production)
            console.log(`Performing bulk action: ${action}`);
            alert(`Bulk ${action} feature\n\nWould allow:\n• Select multiple assessments\n• Activate/deactivate\n• Delete\n• Export results`);
        }

        /* ============================================
           PAGE LOAD ANIMATIONS
           Smooth entrance effects - optimized
           ============================================ */
        window.addEventListener('load', function() {
            console.log('Teacher Dashboard loaded successfully');
            
            // Animate stat cards - use requestAnimationFrame for better performance
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
           AUTO-REFRESH SUBMISSIONS
           Periodically check for new submissions
           ============================================ */
        // In production, poll backend every 30 seconds for new submissions
        // setInterval(function() {
        //     // Make AJAX call to check for new submissions
        //     console.log('Checking for new submissions...');
        //     // Update recent submissions list if new ones found
        // }, 30000);

        /* ============================================
           KEYBOARD SHORTCUTS
           Enhanced user experience for teachers
           ============================================ */
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + N: Create new assessment
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                window.location.href = 'create-assessment.php';
            }
            
            // Ctrl/Cmd + R: View reports
            if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
                e.preventDefault();
                window.location.href = 'reports.php';
            }
        });

        /* ============================================
           ADD ENTRANCE ANIMATIONS
           CSS keyframes for smooth appearance
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

        /* ============================================
           BACKEND INTEGRATION NOTES
           When connecting to PHP backend:
           
           1. FETCH ASSESSMENTS:
              - Endpoint: get-teacher-assessments.php
              - Filter by teacher ID from session
              - Return: Array of assessment objects
           
           2. DELETE ASSESSMENT:
              - Endpoint: delete-assessment.php
              - Method: POST
              - Verify ownership before deletion
              - Use soft delete (set active=0) rather than hard delete
           
           3. GET STATISTICS:
              - Endpoint: get-teacher-stats.php
              - Return: Total assessments, submissions, avg scores
           
           4. RECENT SUBMISSIONS:
              - Endpoint: get-recent-submissions.php
              - Return: Last 10 submissions with student info
           
           5. SECURITY REQUIREMENTS:
              - Validate teacher session
              - Verify assessment ownership for all operations
              - Use prepared statements for SQL queries
              - Implement CSRF tokens for delete/edit actions
           ============================================ */
    </script>
</body>
</html>
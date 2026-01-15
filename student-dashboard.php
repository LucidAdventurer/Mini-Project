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
    <meta name="description" content="Student Dashboard - Placement Assessment Platform">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Student Dashboard - Placement Assessment</title>
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
            --color-bg: #D3DAD9;
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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: var(--font-family);
            background: linear-gradient(135deg, var(--color-bg) 0%, white 100%);
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
           ============================================ */
        /* NAVIGATION BAR - standardized across pages */
        .navbar {
            background: var(--color-primary);
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
            border-bottom: 3px solid var(--color-primary);
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
            background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 18px;
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 10px;
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
            background: var(--color-primary);
            color: white;
        }

        /* Notification badge */
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
            background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%);
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
            color: var(--color-primary);
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
            background: linear-gradient(135deg, rgba(79, 172, 254, 0.1), transparent);
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
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
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

        .stat-card-change.negative {
            color: #f56565;
        }

        /* ============================================
           MAIN CONTENT GRID
           Available assessments and recent activity
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
            color: #4facfe;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .view-all-link:hover {
            color: #00f2fe;
            transform: translateX(3px);
        }

        /* ============================================
           AVAILABLE ASSESSMENTS SECTION
           List of tests student can take
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
            background: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            color: #718096;
            transition: all 0.3s ease;
        }

        .filter-tab.active {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
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
            background: white;
            border-radius: 15px;
            padding: 20px;
            transition: var(--transition);
            border: 2px solid transparent;
            cursor: pointer;
        }

        .assessment-card.hidden {
            display: none;
        }

        .assessment-card:hover {
            border-color: var(--color-primary);
            background: var(--color-white);
            box-shadow: 0 4px 15px rgba(79, 172, 254, 0.15);
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

        /* Difficulty badge */
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

        /* Action buttons */
        .assessment-actions {
            display: flex;
            gap: 10px;
        }

        .btn-start {
            padding: 10px 24px;
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-start:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(79, 172, 254, 0.4);
        }

        .btn-details {
            padding: 10px 24px;
            background: white;
            color: #4facfe;
            border: 2px solid var(--color-primary);
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-details:hover {
            background: #4facfe;
            color: white;
        }

        /* ============================================
           SIDEBAR - Recent Activity & Progress
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

        /* Recent activity items */
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
            background: white;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #4facfe;
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

        /* Progress chart placeholder */
        .progress-chart {
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

        /* Overall progress bar */
        .overall-progress {
            margin-top: 15px;
        }

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
            background: linear-gradient(90deg, #4facfe 0%, #00f2fe 100%);
            border-radius: 10px;
            transition: width 0.5s ease;
        }

        /* ============================================
           QUICK ACTIONS FLOATING BUTTON
           Bottom right corner action menu
           ============================================ */
        .quick-actions {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 50;
        }

        .action-button {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            cursor: pointer;
            box-shadow: 0 6px 20px rgba(79, 172, 254, 0.4);
            transition: all 0.3s ease;
        }

        .action-button:hover {
            transform: scale(1.1);
            box-shadow: 0 8px 30px rgba(79, 172, 254, 0.6);
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
            border-top-color: var(--color-primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto 15px;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* ============================================
           EMPTY STATE
           When no assessments available
           ============================================ */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #a0aec0;
        }

        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state-title {
            font-size: 20px;
            font-weight: 600;
            color: #718096;
            margin-bottom: 10px;
        }

        .empty-state-text {
            font-size: 14px;
            color: #a0aec0;
        }
    </style>
</head>
<body>
    <!-- ============================================
         NAVIGATION BAR
         Top navigation with branding and user profile
         ============================================ -->
    <nav class="navbar">
        <a href="student-dashboard.php" class="navbar-brand">
            <div class="brand-logo">P</div>
            <span>Student Portal</span>
        </a>

        <!-- Search bar -->
        <div class="nav-search">
            <input type="text" class="search-input" placeholder="Search assessments..." id="searchInput" aria-label="Search assessments" autocomplete="off">
            <span class="search-icon" aria-hidden="true">🔍</span>
        </div>

        <!-- User profile section -->
        <div class="nav-profile">
            <!-- Notification icon with badge -->
            <button class="notification-icon" onclick="showNotifications()" aria-label="View notifications" aria-describedby="notification-count">
                <span aria-hidden="true">🔔</span>
                <div class="notification-badge" id="notification-count" aria-live="polite">3</div>
            </button>

            <!-- Profile dropdown button -->
            <button class="profile-button" onclick="toggleProfileMenu()" aria-label="Profile menu" aria-expanded="false">
                <div class="profile-avatar" aria-hidden="true">JK</div>
                <span class="profile-name">Justin Kurian</span>
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
                <h1>Welcome back, Justin! 👋</h1>
                <p>Ready to continue your placement preparation journey?</p>
            </div>
            <div class="quick-stats">
                <div class="stat-item">
                    <span class="stat-number">12</span>
                    <span class="stat-label">Tests Completed</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number">8</span>
                    <span class="stat-label">Available</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number">76%</span>
                    <span class="stat-label">Avg. Score</span>
                </div>
            </div>
        </div>

        <!-- Performance overview cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-card-header">
                    <span class="stat-card-title">Tests Completed</span>
                    <div class="stat-card-icon">📝</div>
                </div>
                <div class="stat-card-value">12</div>
                <div class="stat-card-change">
                    <span>↗</span> 3 from last week
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-card-header">
                    <span class="stat-card-title">Average Score</span>
                    <div class="stat-card-icon">📊</div>
                </div>
                <div class="stat-card-value">76%</div>
                <div class="stat-card-change">
                    <span>↗</span> +4% improvement
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-card-header">
                    <span class="stat-card-title">Study Streak</span>
                    <div class="stat-card-icon">🔥</div>
                </div>
                <div class="stat-card-value">7</div>
                <div class="stat-card-change">
                    Days in a row
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-card-header">
                    <span class="stat-card-title">Time Invested</span>
                    <div class="stat-card-icon">⏱️</div>
                </div>
                <div class="stat-card-value">24h</div>
                <div class="stat-card-change">
                    This month
                </div>
            </div>
        </div>

        <!-- Main content grid -->
        <div class="main-content">
            <!-- Available assessments section -->
            <div class="assessments-section">
                <div class="section-header">
                    <h2 class="section-title">Available Assessments</h2>
                    <a href="all-assessments.php" class="view-all-link">View All →</a>
                </div>

                <!-- Filter tabs -->
                <div class="filter-tabs">
                    <button class="filter-tab active" onclick="filterAssessments('all')">All Tests</button>
                    <button class="filter-tab" onclick="filterAssessments('aptitude')">Aptitude</button>
                    <button class="filter-tab" onclick="filterAssessments('technical')">Technical</button>
                    <button class="filter-tab" onclick="filterAssessments('coding')">Coding</button>
                </div>

                <!-- Assessment list -->
                <div class="assessment-list" id="assessmentList">
                    <!-- Assessment Card 1 -->
                    <div class="assessment-card" data-category="aptitude">
                        <div class="assessment-header">
                            <div>
                                <div class="assessment-title">Quantitative Aptitude - Set 1</div>
                                <div class="assessment-category">Aptitude • Mathematics</div>
                            </div>
                            <span class="difficulty-badge easy">Easy</span>
                        </div>
                        <div class="assessment-meta">
                            <div class="meta-item">
                                <span class="meta-icon">❓</span>
                                <span>30 Questions</span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-icon">⏱️</span>
                                <span>45 Minutes</span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-icon">🏆</span>
                                <span>100 Points</span>
                            </div>
                        </div>
                        <div class="assessment-actions">
                            <button class="btn-start" onclick="startAssessment(1)">Start Test</button>
                            <button class="btn-details" onclick="viewDetails(1)">View Details</button>
                        </div>
                    </div>

                    <!-- Assessment Card 2 -->
                    <div class="assessment-card" data-category="technical">
                        <div class="assessment-header">
                            <div>
                                <div class="assessment-title">Data Structures & Algorithms</div>
                                <div class="assessment-category">Technical • CS Fundamentals</div>
                            </div>
                            <span class="difficulty-badge medium">Medium</span>
                        </div>
                        <div class="assessment-meta">
                            <div class="meta-item">
                                <span class="meta-icon">❓</span>
                                <span>25 Questions</span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-icon">⏱️</span>
                                <span>60 Minutes</span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-icon">🏆</span>
                                <span>150 Points</span>
                            </div>
                        </div>
                        <div class="assessment-actions">
                            <button class="btn-start" onclick="startAssessment(2)">Start Test</button>
                            <button class="btn-details" onclick="viewDetails(2)">View Details</button>
                        </div>
                    </div>

                    <!-- Assessment Card 3 -->
                    <div class="assessment-card" data-category="coding">
                        <div class="assessment-header">
                            <div>
                                <div class="assessment-title">Python Programming Challenge</div>
                                <div class="assessment-category">Coding • Python</div>
                            </div>
                            <span class="difficulty-badge hard">Hard</span>
                        </div>
                        <div class="assessment-meta">
                            <div class="meta-item">
                                <span class="meta-icon">❓</span>
                                <span>5 Problems</span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-icon">⏱️</span>
                                <span>90 Minutes</span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-icon">🏆</span>
                                <span>200 Points</span>
                            </div>
                        </div>
                        <div class="assessment-actions">
                            <button class="btn-start" onclick="startAssessment(3)">Start Test</button>
                            <button class="btn-details" onclick="viewDetails(3)">View Details</button>
                        </div>
                    </div>

                    <!-- Assessment Card 4 -->
                    <div class="assessment-card" data-category="aptitude">
                        <div class="assessment-header">
                            <div>
                                <div class="assessment-title">Logical Reasoning Assessment</div>
                                <div class="assessment-category">Aptitude • Logic</div>
                            </div>
                            <span class="difficulty-badge medium">Medium</span>
                        </div>
                        <div class="assessment-meta">
                            <div class="meta-item">
                                <span class="meta-icon">❓</span>
                                <span>35 Questions</span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-icon">⏱️</span>
                                <span>50 Minutes</span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-icon">🏆</span>
                                <span>120 Points</span>
                            </div>
                        </div>
                        <div class="assessment-actions">
                            <button class="btn-start" onclick="startAssessment(4)">Start Test</button>
                            <button class="btn-details" onclick="viewDetails(4)">View Details</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sidebar with recent activity and progress -->
            <div class="sidebar">
                <!-- Recent Activity Card -->
                <div class="sidebar-card">
                    <h3 class="sidebar-card-title">Recent Activity</h3>
                    <div class="activity-list">
                        <div class="activity-item">
                            <div class="activity-icon">✅</div>
                            <div class="activity-content">
                                <div class="activity-title">Completed: SQL Basics Test</div>
                                <div class="activity-time">2 hours ago • Score: 85%</div>
                            </div>
                        </div>
                        <div class="activity-item">
                            <div class="activity-icon">⭐</div>
                            <div class="activity-content">
                                <div class="activity-title">Achieved: Perfect Score Badge</div>
                                <div class="activity-time">Yesterday</div>
                            </div>
                        </div>
                        <div class="activity-item">
                            <div class="activity-icon">📝</div>
                            <div class="activity-content">
                                <div class="activity-title">Started: Java Programming</div>
                                <div class="activity-time">2 days ago</div>
                            </div>
                        </div>
                        <div class="activity-item">
                            <div class="activity-icon">🎯</div>
                            <div class="activity-content">
                                <div class="activity-title">New Test Available</div>
                                <div class="activity-time">3 days ago</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Progress Tracking Card -->
                <div class="sidebar-card">
                    <h3 class="sidebar-card-title">Your Progress</h3>
                    <div class="progress-chart">
                        📈 Progress Chart
                        <br><small>(Chart visualization will be here)</small>
                    </div>
                    <div class="overall-progress">
                        <div class="progress-label">
                            <span style="font-weight: 600;">Overall Completion</span>
                            <span style="color: #4facfe; font-weight: 700;">60%</span>
                        </div>
                        <div class="progress-bar-container">
                            <div class="progress-bar-fill" style="width: 60%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions Floating Button -->
    <div class="quick-actions">
        <div class="action-button" onclick="showQuickActions()" title="Quick Actions">
            ➕
        </div>
    </div>

    <script>
        /* ============================================
           ASSESSMENT FILTERING
           Filter tests by category - optimized with event delegation
           ============================================ */
        function filterAssessments(category, targetElement) {
            // Update active tab
            const tabs = document.querySelectorAll('.filter-tab');
            tabs.forEach(tab => tab.classList.remove('active'));
            if (targetElement) {
                targetElement.classList.add('active');
            }

            // Filter assessment cards - use class toggle for better performance
            const cards = document.querySelectorAll('.assessment-card');
            cards.forEach(card => {
                if (category === 'all') {
                    card.classList.remove('hidden');
                } else {
                    const cardCategory = card.dataset.category;
                    if (cardCategory === category) {
                        card.classList.remove('hidden');
                    } else {
                        card.classList.add('hidden');
                    }
                }
            });
        }

        // Use event delegation for filter tabs - improved version
        document.addEventListener('click', function(e) {
            const filterTab = e.target.closest('.filter-tab');
            if (filterTab) {
                const category = filterTab.dataset.category;
                if (category) {
                    e.preventDefault();
                    filterAssessments(category, filterTab);
                    // Update ARIA attributes for accessibility
                    document.querySelectorAll('.filter-tab').forEach(tab => {
                        tab.setAttribute('aria-selected', 'false');
                    });
                    filterTab.setAttribute('aria-selected', 'true');
                }
            }
        });

        /* ============================================
           START ASSESSMENT
           Redirects to test-taking page
           SECURITY: Check user authentication on backend
           ============================================ */
        function startAssessment(assessmentId) {
            // In production, this would:
            // 1. Check if user is authenticated
            // 2. Verify assessment availability
            // 3. Create a new test attempt record
            // 4. Redirect to secure test environment
            
            console.log('Starting assessment:', assessmentId);
            
            // Show confirmation dialog
            if (confirm('Are you ready to start this assessment? Make sure you have stable internet connection.')) {
                // Redirect to test page with assessment ID
                // window.location.href = `take-test.php?id=${assessmentId}`;
                alert(`Redirecting to assessment ${assessmentId}...\n\nIn production, this will open the test interface.`);
            }
        }

        /* ============================================
           VIEW ASSESSMENT DETAILS
           Opens detailed information about the test
           ============================================ */
        function viewDetails(assessmentId) {
            console.log('Viewing details for assessment:', assessmentId);
            // window.location.href = `assessment-details.php?id=${assessmentId}`;
            alert(`Opening details for assessment ${assessmentId}...\n\nWill show: syllabus, sample questions, previous attempts, etc.`);
        }

        /* ============================================
           DEBOUNCE UTILITY
           Optimizes search input
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
           SEARCH FUNCTIONALITY
           Real-time search through assessments with debouncing
           ============================================ */
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            const performSearch = debounce(function(e) {
                const searchTerm = e.target.value.toLowerCase().trim();
            const cards = document.querySelectorAll('.assessment-card');
            
                // Use document fragment for better performance
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
            alert('Notifications:\n\n1. New test available: Web Development Quiz\n2. Your result is ready: SQL Basics\n3. Reminder: Complete pending tests');
        }

        /* ============================================
           PROFILE MENU
           Toggle user profile dropdown
           ============================================ */
        function toggleProfileMenu() {
            // In production, this would show a dropdown menu with:
            // - View Profile
            // - Settings
            // - My Results
            // - Logout
            alert('Profile Menu:\n\n• View Profile\n• Settings\n• My Results\n• Help & Support\n• Logout');
        }

        /* ============================================
           QUICK ACTIONS MENU
           Floating button actions
           ============================================ */
        function showQuickActions() {
            alert('Quick Actions:\n\n• Take Practice Test\n• View Progress Report\n• Schedule Assessment\n• Contact Support');
        }

        /* ============================================
           AUTO-SAVE FEATURE (FOR FUTURE)
           Periodically save user activity
           ============================================ */
        // This would run every 2 minutes to save user's current state
        // setInterval(function() {
        //     // Send AJAX request to save user activity
        //     console.log('Auto-saving user activity...');
        // }, 120000);

        /* ============================================
           PAGE LOAD ANIMATIONS
           Smooth entrance effects - optimized
           ============================================ */
        window.addEventListener('load', function() {
            // Add animation classes or trigger effects
            console.log('Student Dashboard loaded successfully');
            
            // Example: Update progress bars with animation - use requestAnimationFrame
            const progressBars = document.querySelectorAll('.progress-bar-fill');
            progressBars.forEach((bar, index) => {
                const width = bar.style.width || bar.getAttribute('data-width') || '0%';
                bar.setAttribute('data-width', width);
                bar.style.width = '0';
                
                requestAnimationFrame(() => {
                setTimeout(() => {
                    bar.style.width = width;
                    }, index * 50); // Stagger animations
                });
            });
        });

        /* ============================================
           SESSION TIMEOUT WARNING
           Warn user before session expires
           SECURITY: Implement proper session management on backend
           ============================================ */
        // In production, warn user 5 minutes before session timeout
        // setTimeout(function() {
        //     if (confirm('Your session will expire in 5 minutes. Do you want to extend?')) {
        //         // Make AJAX call to extend session
        //         console.log('Session extended');
        //     }
        // }, 25 * 60 * 1000); // 25 minutes (assuming 30 min session)
    </script>
</body>
</html>
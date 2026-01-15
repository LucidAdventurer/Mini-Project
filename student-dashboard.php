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
           ============================================ */
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

        /* ============================================
           PROFILE DROPDOWN
           ============================================ */
        .profile-dropdown-container {
            position: relative;
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

        .dropdown-arrow {
            font-size: 12px;
            color: #718096;
            transition: transform 0.3s ease;
        }

        .profile-button:hover .dropdown-arrow {
            transform: translateY(2px);
        }

        /* Dropdown Menu */
        .profile-dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.15);
            min-width: 220px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
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

        .dropdown-menu {
            padding: 8px 0;
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            color: #2d3748;
            text-decoration: none;
            transition: all 0.2s ease;
            cursor: pointer;
            border: none;
            background: none;
            width: 100%;
            text-align: left;
            font-size: 14px;
            font-family: var(--font-family);
        }

        .dropdown-item:hover {
            background: #f7fafc;
        }

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

        .dropdown-item.logout {
            color: #f56565;
        }

        .dropdown-item.logout:hover {
            background: #fff5f5;
        }

        /* ============================================
           MAIN CONTAINER
           ============================================ */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px;
        }

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
           MAIN CONTENT GRID
           ============================================ */
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
            transition: all 0.3s ease;
        }

        .view-all-link:hover {
            color: #00f2fe;
            transform: translateX(3px);
        }

        /* ============================================
           ASSESSMENTS SECTION
           ============================================ */
        .assessments-section {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
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
            transition: all 0.3s ease;
        }

        .filter-tab.active {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
            border-color: transparent;
        }

        .filter-tab:hover:not(.active) {
            background: #e2e8f0;
        }

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

        .meta-icon {
            font-size: 16px;
        }

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
           QUICK ACTIONS
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
            border: none;
        }

        .action-button:hover {
            transform: scale(1.1);
            box-shadow: 0 8px 30px rgba(79, 172, 254, 0.6);
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
        }

        /* Overlay for dropdown */
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

        .dropdown-overlay.show {
            display: block;
        }
    </style>
</head>
<body>
    <!-- ============================================
         NAVIGATION BAR
         ============================================ -->
    <nav class="navbar">
        <a href="student-dashboard.php" class="navbar-brand">
            <div class="brand-logo">P</div>
            <span>Student Portal</span>
        </a>

        <div class="nav-search">
            <input type="text" class="search-input" placeholder="Search assessments..." id="searchInput" aria-label="Search assessments" autocomplete="off">
            <span class="search-icon" aria-hidden="true">🔍</span>
        </div>

        <div class="nav-profile">
            <button class="notification-icon" onclick="showNotifications()" aria-label="View notifications">
                <span aria-hidden="true">🔔</span>
                <div class="notification-badge" id="notification-count">3</div>
            </button>

            <div class="profile-dropdown-container">
                <button class="profile-button" onclick="toggleProfileDropdown()" aria-label="Profile menu" aria-expanded="false" id="profileButton">
                    <div class="profile-avatar" aria-hidden="true">JK</div>
                    <span class="profile-name">Justin Kurian</span>
                    <span class="dropdown-arrow">▼</span>
                </button>
                
                <div class="profile-dropdown" id="profileDropdown">
                    <div class="dropdown-header">
                        <div class="dropdown-user-name">Justin Kurian</div>
                        <div class="dropdown-user-email">justin.kurian@example.com</div>
                    </div>
                    <div class="dropdown-menu">
                        <a href="view-profile.php" class="dropdown-item">
                            <span class="dropdown-item-icon">👤</span>
                            <span>View Profile</span>
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

    <!-- Dropdown Overlay -->
    <div class="dropdown-overlay" id="dropdownOverlay" onclick="closeProfileDropdown()"></div>

    <!-- ============================================
         MAIN DASHBOARD CONTAINER
         ============================================ -->
    <div class="container">
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
                </div>

                <div class="assessment-list" id="assessmentList">
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

            <div class="sidebar">
                <div class
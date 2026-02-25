<?php
/* ========================================
 * TEACHER PROFILE PAGE
 * ======================================== */

require "config.php";

/*
 * SESSION GUARD
 * Column: users.user_type (ENUM: 'student','teacher','admin')
 * Session key set at login must match: $_SESSION['user_type']
 */
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'teacher') {
    header("Location: index.html");
    exit;
}

/*
 * FETCH TEACHER INFO
 * Table: users
 * Columns used:
 *   full_name       VARCHAR(100)
 *   email           VARCHAR(100)
 *   department      VARCHAR(50)
 *   profile_image   VARCHAR(255)   ← NOT profile_picture
 *   created_at      TIMESTAMP
 * Columns NOT in schema (removed): phone, bio
 */
$stmt = $conn->prepare("
    SELECT full_name, email, department, profile_image, created_at
    FROM users
    WHERE user_id = ?
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$userName     = $user['full_name']    ?? 'Teacher';
$userEmail    = $user['email']        ?? '';
$userDept     = $user['department']   ?? '';
$userPicture  = $user['profile_image'] ?? '';   // correct column: profile_image
$memberSince  = $user['created_at']   ? date('F Y', strtotime($user['created_at'])) : 'N/A';
$userInitials = strtoupper(substr($userName, 0, 2));

/*
 * REAL STATS from database
 * assessments.created_by references users.user_id
 * assessment_attempts.user_id → students who attempted teacher's assessments
 */
$statsStmt = $conn->prepare("
    SELECT
        COUNT(DISTINCT a.assessment_id)                          AS total_assessments,
        COUNT(DISTINCT aa.user_id)                               AS total_students,
        IFNULL(AVG(aa.percentage), 0)                           AS avg_score
    FROM assessments a
    LEFT JOIN assessment_attempts aa
        ON aa.assessment_id = a.assessment_id
        AND aa.status = 'completed'
    WHERE a.created_by = ?
");
$statsStmt->bind_param("i", $_SESSION['user_id']);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();

$totalAssessments = (int)($stats['total_assessments'] ?? 0);
$totalStudents    = (int)($stats['total_students']    ?? 0);
$avgScore         = round((float)($stats['avg_score'] ?? 0), 1);

/*
 * REAL ACTIVITY LOG
 * login_activity: recent login events for this user
 * assessments: recently created/updated by this teacher
 */
$activityStmt = $conn->prepare("
    (
        SELECT
            'login'              AS activity_type,
            CASE WHEN is_success = 1 THEN 'Logged in successfully' ELSE 'Failed login attempt' END AS title,
            CONCAT('From IP: ', IFNULL(ip_address, 'unknown'))  AS description,
            created_at
        FROM login_activity
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 5
    )
    UNION ALL
    (
        SELECT
            'assessment'         AS activity_type,
            CONCAT(
                CASE status
                    WHEN 'active'   THEN 'Published assessment: '
                    WHEN 'draft'    THEN 'Saved draft: '
                    WHEN 'archived' THEN 'Archived assessment: '
                    ELSE 'Updated assessment: '
                END,
                title
            )                    AS title,
            CONCAT(category, ' · ', duration_minutes, ' min')  AS description,
            updated_at           AS created_at
        FROM assessments
        WHERE created_by = ?
        ORDER BY updated_at DESC
        LIMIT 5
    )
    ORDER BY created_at DESC
    LIMIT 8
");
$activityStmt->bind_param("ii", $_SESSION['user_id'], $_SESSION['user_id']);
$activityStmt->execute();
$activityRows = $activityStmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* Handle profile update */
$successMsg = '';
$errorMsg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    /* ── Update basic info ──
     * Only updates columns that actually exist in users:
     *   full_name, department
     * phone and bio are NOT in the schema — removed.
     */
    if ($_POST['action'] === 'update_info') {
        $newName = trim($_POST['full_name']  ?? '');
        $newDept = trim($_POST['department'] ?? '');

        if (empty($newName)) {
            $errorMsg = 'Full name is required.';
        } else {
            $upd = $conn->prepare("
                UPDATE users
                SET full_name = ?, department = ?
                WHERE user_id = ?
            ");
            $upd->bind_param("ssi", $newName, $newDept, $_SESSION['user_id']);
            if ($upd->execute()) {
                $userName     = $newName;
                $userDept     = $newDept;
                $userInitials = strtoupper(substr($userName, 0, 2));
                $successMsg   = 'Profile updated successfully.';
            } else {
                $errorMsg = 'Failed to update profile. Please try again.';
            }
        }
    }

    /* ── Change password ──
     * Column: users.password_hash  (NOT "password")
     */
    if ($_POST['action'] === 'change_password') {
        $currentPwd = $_POST['current_password'] ?? '';
        $newPwd     = $_POST['new_password']     ?? '';
        $confirmPwd = $_POST['confirm_password'] ?? '';

        $pwdQuery = $conn->prepare("
            SELECT password_hash FROM users WHERE user_id = ?
        ");
        $pwdQuery->bind_param("i", $_SESSION['user_id']);
        $pwdQuery->execute();
        $pwdResult = $pwdQuery->get_result()->fetch_assoc();

        if (!password_verify($currentPwd, $pwdResult['password_hash'])) {
            $errorMsg = 'Current password is incorrect.';
        } elseif (strlen($newPwd) < 8) {
            $errorMsg = 'New password must be at least 8 characters.';
        } elseif ($newPwd !== $confirmPwd) {
            $errorMsg = 'New passwords do not match.';
        } else {
            $hashedPwd = password_hash($newPwd, PASSWORD_DEFAULT);
            $updPwd    = $conn->prepare("
                UPDATE users SET password_hash = ? WHERE user_id = ?
            ");
            $updPwd->bind_param("si", $hashedPwd, $_SESSION['user_id']);
            if ($updPwd->execute()) {
                $successMsg = 'Password changed successfully.';
            } else {
                $errorMsg = 'Failed to change password. Please try again.';
            }
        }
    }

    /* ── Upload profile picture ──
     * Column: users.profile_image  (NOT profile_picture)
     * Also inserts a row into uploaded_files for centralised file tracking.
     * uploaded_files.entity_type ENUM includes 'user_profile'
     */
    if ($_POST['action'] === 'upload_picture' && isset($_FILES['profile_picture'])) {
        $file    = $_FILES['profile_picture'];
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize = 2 * 1024 * 1024; // 2 MB

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errorMsg = 'Upload error. Please try again.';
        } elseif (!in_array($file['type'], $allowed)) {
            $errorMsg = 'Invalid file type. Please upload a JPG, PNG, GIF, or WEBP image.';
        } elseif ($file['size'] > $maxSize) {
            $errorMsg = 'File size exceeds 2 MB limit.';
        } else {
            $ext          = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $storedName   = 'teacher_' . $_SESSION['user_id'] . '_' . time() . '.' . $ext;
            $uploadDir    = 'uploads/profiles/';
            $fullPath     = $uploadDir . $storedName;

            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            if (move_uploaded_file($file['tmp_name'], $fullPath)) {

                // 1. Update users.profile_image (correct column name)
                $updPic = $conn->prepare("
                    UPDATE users SET profile_image = ? WHERE user_id = ?
                ");
                $updPic->bind_param("si", $fullPath, $_SESSION['user_id']);
                $updPic->execute();

                // 2. Insert into uploaded_files for centralised tracking
                //    Columns: original_filename, stored_filename, file_path,
                //             file_type, mime_type, file_size,
                //             uploaded_by, entity_type, entity_id
                $insFile = $conn->prepare("
                    INSERT INTO uploaded_files
                        (original_filename, stored_filename, file_path, file_type,
                         mime_type, file_size, uploaded_by, entity_type, entity_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'user_profile', ?)
                ");
                $fileType = $ext;
                $mimeType = $file['type'];
                $fileSize = (int)$file['size'];
                $userId   = (int)$_SESSION['user_id'];
                $insFile->bind_param(
                    "sssssiis",
                    $file['name'],  // original_filename
                    $storedName,    // stored_filename
                    $fullPath,      // file_path
                    $fileType,      // file_type
                    $mimeType,      // mime_type
                    $fileSize,      // file_size
                    $userId,        // uploaded_by
                    $userId         // entity_id (this user)
                );
                $insFile->execute();

                $userPicture = $fullPath;
                $successMsg  = 'Profile picture updated successfully.';
            } else {
                $errorMsg = 'Failed to upload picture. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Teacher | Placement Portal</title>
    <style>
        /* ============================================
           CSS VARIABLES
           ============================================ */
        :root {
            --font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            --color-teacher-primary: #2E073F;
            --color-teacher-secondary: #AD49E1;
            --color-text: #2d3748;
            --color-text-light: #718096;
            --color-bg-light: #f5f7fa;
            --color-white: #ffffff;
            --color-border: #e2e8f0;
            --color-success: #48bb78;
            --color-error: #f56565;
            --shadow-sm: 0 2px 10px rgba(0,0,0,0.1);
            --shadow-md: 0 4px 20px rgba(0,0,0,0.08);
            --shadow-lg: 0 8px 30px rgba(0,0,0,0.15);
            --border-radius: 10px;
            --transition: all 0.3s ease;
        }

        /* ============================================
           BASE
           ============================================ */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--font-family);
            background: #D3DAD9;
            min-height: 100vh;
            color: var(--color-text);
            padding-top: 71px;
        }

        /* ============================================
           NAVBAR
           ============================================ */
        .navbar {
            background: var(--color-teacher-primary);
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            padding: 12px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: fixed;
            top: 0; left: 0; right: 0;
            z-index: 1000;
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
            border: 2px solid rgba(255,255,255,0.3);
        }

        .nav-profile {
            display: flex;
            align-items: center;
            gap: 15px;
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
            transition: var(--transition);
            position: relative;
        }

        .profile-button:hover { background: #e2e8f0; }

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
            overflow: hidden;
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-name {
            font-weight: 600;
            font-size: 14px;
            color: var(--color-text);
        }

        /* Dropdown */
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

        .dropdown-menu { padding: 8px 0; }

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

        .dropdown-item:hover { background: var(--color-bg-light); }

        .dropdown-item.danger { color: var(--color-error); }
        .dropdown-item.danger:hover { background: rgba(245,101,101,0.1); }

        .dropdown-divider {
            height: 1px;
            background: var(--color-border);
            margin: 8px 0;
        }

        /* ============================================
           BREADCRUMB
           ============================================ */
        .breadcrumb {
            max-width: 1100px;
            margin: 24px auto 0;
            padding: 0 20px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: var(--color-text-light);
        }

        .breadcrumb a {
            color: var(--color-teacher-secondary);
            text-decoration: none;
            font-weight: 600;
        }

        .breadcrumb a:hover { text-decoration: underline; }

        /* ============================================
           PAGE LAYOUT
           ============================================ */
        .container {
            max-width: 1100px;
            margin: 24px auto 40px;
            padding: 0 20px;
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 24px;
        }

        /* ============================================
           PROFILE CARD (left column)
           ============================================ */
        .profile-card {
            background: var(--color-white);
            border-radius: var(--border-radius);
            padding: 32px 24px;
            box-shadow: var(--shadow-sm);
            text-align: center;
            height: fit-content;
            position: sticky;
            top: 90px;
        }

        .avatar-wrapper {
            position: relative;
            display: inline-block;
            margin-bottom: 16px;
        }

        .avatar-large {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--color-teacher-primary) 0%, var(--color-teacher-secondary) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            font-weight: 700;
            color: var(--color-white);
            margin: 0 auto;
            overflow: hidden;
            border: 4px solid rgba(173,73,225,0.2);
        }

        .avatar-large img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-edit-btn {
            position: absolute;
            bottom: 4px;
            right: 4px;
            width: 30px;
            height: 30px;
            background: var(--color-teacher-secondary);
            border: 2px solid white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 14px;
            transition: var(--transition);
        }

        .avatar-edit-btn:hover {
            background: var(--color-teacher-primary);
            transform: scale(1.1);
        }

        .profile-card-name {
            font-size: 20px;
            font-weight: 700;
            color: var(--color-text);
            margin-bottom: 6px;
        }

        .role-badge {
            display: inline-block;
            padding: 4px 14px;
            background: linear-gradient(135deg, var(--color-teacher-primary), var(--color-teacher-secondary));
            color: white;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-bottom: 16px;
        }

        .profile-card-detail {
            font-size: 13px;
            color: var(--color-text-light);
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .profile-divider {
            height: 1px;
            background: var(--color-border);
            margin: 20px 0;
        }

        .stat-row {
            display: flex;
            justify-content: space-around;
        }

        .stat-item {
            text-align: center;
        }

        .stat-item-value {
            font-size: 22px;
            font-weight: 700;
            color: var(--color-teacher-secondary);
        }

        .stat-item-label {
            font-size: 11px;
            color: var(--color-text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Sidebar nav tabs */
        .profile-nav {
            margin-top: 20px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .profile-nav-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 14px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            color: var(--color-text-light);
            cursor: pointer;
            transition: var(--transition);
            border: none;
            background: transparent;
            width: 100%;
            text-align: left;
        }

        .profile-nav-item:hover {
            background: var(--color-bg-light);
            color: var(--color-text);
        }

        .profile-nav-item.active {
            background: linear-gradient(135deg, var(--color-teacher-primary) 0%, var(--color-teacher-secondary) 100%);
            color: white;
        }

        /* ============================================
           RIGHT COLUMN - TAB PANELS
           ============================================ */
        .tab-panel {
            display: none;
        }

        .tab-panel.active {
            display: block;
        }

        .card {
            background: var(--color-white);
            border-radius: var(--border-radius);
            padding: 28px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 24px;
        }

        .card-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--color-text);
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--color-border);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Form styles */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--color-text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-control {
            padding: 10px 14px;
            border: 2px solid var(--color-border);
            border-radius: 8px;
            font-size: 14px;
            font-family: var(--font-family);
            color: var(--color-text);
            transition: var(--transition);
            background: var(--color-white);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--color-teacher-secondary);
            box-shadow: 0 0 0 3px rgba(173,73,225,0.1);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .form-control[readonly] {
            background: var(--color-bg-light);
            cursor: not-allowed;
            color: var(--color-text-light);
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 24px;
        }

        .btn {
            padding: 10px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            border: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--color-teacher-primary) 0%, var(--color-teacher-secondary) 100%);
            color: white;
        }

        .btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        .btn-secondary {
            background: var(--color-bg-light);
            color: var(--color-text);
            border: 2px solid var(--color-border);
        }

        .btn-secondary:hover {
            background: var(--color-border);
        }

        /* Alert banners */
        .alert {
            padding: 14px 18px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: rgba(72,187,120,0.1);
            color: #276749;
            border: 1px solid rgba(72,187,120,0.3);
        }

        .alert-error {
            background: rgba(245,101,101,0.1);
            color: #c53030;
            border: 1px solid rgba(245,101,101,0.3);
        }

        /* Password strength */
        .password-strength {
            margin-top: 6px;
            height: 4px;
            border-radius: 2px;
            background: var(--color-border);
            overflow: hidden;
        }

        .password-strength-bar {
            height: 100%;
            border-radius: 2px;
            transition: var(--transition);
            width: 0%;
        }

        .strength-weak   { width: 33%; background: var(--color-error); }
        .strength-medium { width: 66%; background: var(--color-warning, #ffc107); }
        .strength-strong { width: 100%; background: var(--color-success); }

        .strength-label {
            font-size: 12px;
            margin-top: 4px;
        }

        /* Activity list */
        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 0;
        }

        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 16px 0;
            border-bottom: 1px solid var(--color-border);
        }

        .activity-item:last-child { border-bottom: none; }

        .activity-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--color-teacher-primary) 0%, var(--color-teacher-secondary) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .activity-text {
            flex: 1;
        }

        .activity-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--color-text);
            margin-bottom: 3px;
        }

        .activity-desc {
            font-size: 13px;
            color: var(--color-text-light);
        }

        .activity-time {
            font-size: 12px;
            color: var(--color-text-light);
            white-space: nowrap;
            margin-top: 2px;
        }

        /* Picture upload */
        .upload-zone {
            border: 2px dashed var(--color-border);
            border-radius: var(--border-radius);
            padding: 32px;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
        }

        .upload-zone:hover {
            border-color: var(--color-teacher-secondary);
            background: rgba(173,73,225,0.03);
        }

        .upload-zone.drag-over {
            border-color: var(--color-teacher-secondary);
            background: rgba(173,73,225,0.06);
        }

        .upload-icon { font-size: 40px; margin-bottom: 12px; }

        .upload-text {
            font-size: 14px;
            color: var(--color-text-light);
            margin-bottom: 12px;
        }

        /* ============================================
           RESPONSIVE
           ============================================ */
        @media (max-width: 900px) {
            .container {
                grid-template-columns: 1fr;
            }
            .profile-card {
                position: static;
            }
            .profile-nav {
                flex-direction: row;
                flex-wrap: wrap;
            }
            .profile-nav-item {
                flex: 1;
                min-width: 120px;
                justify-content: center;
            }
        }

        @media (max-width: 600px) {
            .form-grid { grid-template-columns: 1fr; }
            .navbar { padding: 12px 16px; }
        }
    </style>
</head>
<body>

<!-- ================================================
     NAVBAR
     ================================================ -->
<nav class="navbar">
    <a href="teacher-dashboard.php" class="navbar-brand">
        <div class="brand-logo">PT</div>
        <span>Placement Portal</span>
    </a>

    <div class="nav-profile">
        <button class="profile-button" onclick="toggleProfileDropdown()" aria-expanded="false" aria-haspopup="true">
            <div class="profile-avatar">
                <?php if ($userPicture): ?>
                    <img src="<?= htmlspecialchars($userPicture) ?>" alt="Profile picture">
                <?php else: ?>
                    <?= htmlspecialchars($userInitials) ?>
                <?php endif; ?>
            </div>
            <span class="profile-name"><?= htmlspecialchars($userName) ?></span>
            <span style="color:#a0aec0;">▼</span>
        </button>

        <div class="profile-dropdown" id="profileDropdown">
            <div class="dropdown-header">
                <div class="dropdown-name"><?= htmlspecialchars($userName) ?></div>
                <div class="dropdown-email"><?= htmlspecialchars($userEmail) ?></div>
                <div style="margin-top:6px;">
                    <span style="display:inline-block;padding:2px 10px;background:linear-gradient(135deg,var(--color-teacher-primary),var(--color-teacher-secondary));color:#fff;border-radius:20px;font-size:11px;font-weight:600;letter-spacing:0.5px;">Teacher</span>
                </div>
            </div>
            <div class="dropdown-menu">
                <a href="teacher-profile.php" class="dropdown-item" style="background:var(--color-bg-light);">
                    <span>👤</span>
                    <span>My Profile</span>
                </a>
                <a href="teacher-dashboard.php" class="dropdown-item">
                    <span>📊</span>
                    <span>Dashboard</span>
                </a>
                <a href="home.php" class="dropdown-item">
                        <span class="dropdown-item-icon">📝</span>
                        <span>Practice Tests</span>
                </a>
                <a href="help.html" target="_blank" rel="noopener noreferrer" class="dropdown-item">
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

<!-- Breadcrumb -->
<div class="breadcrumb">
    <a href="teacher-dashboard.php">Dashboard</a>
    <span>›</span>
    <span>My Profile</span>
</div>

<!-- ================================================
     MAIN LAYOUT
     ================================================ -->
<div class="container">

    <!-- ── LEFT: Profile Card ── -->
    <aside>
        <div class="profile-card">
            <!-- Avatar -->
            <div class="avatar-wrapper">
                <div class="avatar-large">
                    <?php if ($userPicture): ?>
                        <img src="<?= htmlspecialchars($userPicture) ?>" alt="Profile picture">
                    <?php else: ?>
                        <?= htmlspecialchars($userInitials) ?>
                    <?php endif; ?>
                </div>
                <div class="avatar-edit-btn" onclick="switchTab('picture')" title="Change photo">✏️</div>
            </div>

            <div class="profile-card-name"><?= htmlspecialchars($userName) ?></div>
            <div class="role-badge">Teacher</div>

            <?php if ($userDept): ?>
                <div class="profile-card-detail">🏫 <?= htmlspecialchars($userDept) ?></div>
            <?php endif; ?>
            <div class="profile-card-detail">✉️ <?= htmlspecialchars($userEmail) ?></div>
            <div class="profile-card-detail">📅 Member since <?= htmlspecialchars($memberSince) ?></div>

            <div class="profile-divider"></div>

            <!-- Quick stats — live from DB -->
            <div class="stat-row">
                <div class="stat-item">
                    <div class="stat-item-value"><?= $totalAssessments ?></div>
                    <div class="stat-item-label">Assessments</div>
                </div>
                <div class="stat-item">
                    <div class="stat-item-value"><?= $totalStudents ?></div>
                    <div class="stat-item-label">Students</div>
                </div>
                <div class="stat-item">
                    <div class="stat-item-value"><?= $avgScore ?>%</div>
                    <div class="stat-item-label">Avg Score</div>
                </div>
            </div>

            <div class="profile-divider"></div>

            <!-- Sidebar nav -->
            <nav class="profile-nav">
                <button class="profile-nav-item active" onclick="switchTab('info')" id="nav-info">
                    <span>👤</span> Personal Info
                </button>
                <button class="profile-nav-item" onclick="switchTab('password')" id="nav-password">
                    <span>🔒</span> Change Password
                </button>
                <button class="profile-nav-item" onclick="switchTab('picture')" id="nav-picture">
                    <span>🖼️</span> Profile Picture
                </button>
                <button class="profile-nav-item" onclick="switchTab('activity')" id="nav-activity">
                    <span>📋</span> Activity Log
                </button>
            </nav>
        </div>
    </aside>

    <!-- ── RIGHT: Tab Panels ── -->
    <main>

        <?php if ($successMsg): ?>
            <div class="alert alert-success">✅ <?= htmlspecialchars($successMsg) ?></div>
        <?php endif; ?>

        <?php if ($errorMsg): ?>
            <div class="alert alert-error">⚠️ <?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <!-- ══ TAB: Personal Info ══ -->
        <div class="tab-panel active" id="panel-info">
            <div class="card">
                <div class="card-title">👤 Personal Information</div>
                <!-- Editable columns: full_name, department (schema: users table) -->
                <!-- Email is read-only — used as login identifier, not updatable here -->
                <!-- phone and bio do NOT exist in the users table — removed -->
                <form method="POST">
                    <input type="hidden" name="action" value="update_info">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="full_name">Full Name</label>
                            <input type="text" id="full_name" name="full_name" class="form-control"
                                   value="<?= htmlspecialchars($userName) ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="email">Email Address</label>
                            <input type="email" id="email" class="form-control"
                                   value="<?= htmlspecialchars($userEmail) ?>" readonly>
                        </div>
                        <div class="form-group full-width">
                            <label class="form-label" for="department">Department</label>
                            <input type="text" id="department" name="department" class="form-control"
                                   value="<?= htmlspecialchars($userDept) ?>" placeholder="e.g. Computer Science">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="reset" class="btn btn-secondary">Reset</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>

            <!-- Read-only account info -->
            <div class="card">
                <div class="card-title">🔧 Account Details</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Role</label>
                        <!-- users.user_type value for this account -->
                        <input type="text" class="form-control" value="Teacher" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Member Since</label>
                        <!-- users.created_at -->
                        <input type="text" class="form-control" value="<?= htmlspecialchars($memberSince) ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">User ID</label>
                        <!-- users.user_id -->
                        <input type="text" class="form-control" value="#<?= htmlspecialchars($_SESSION['user_id']) ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Account Status</label>
                        <!-- users.is_active -->
                        <input type="text" class="form-control" value="Active ✅" readonly>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══ TAB: Change Password ══ -->
        <div class="tab-panel" id="panel-password">
            <div class="card">
                <div class="card-title">🔒 Change Password</div>
                <form method="POST" id="passwordForm">
                    <input type="hidden" name="action" value="change_password">
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label class="form-label" for="current_password">Current Password</label>
                            <input type="password" id="current_password" name="current_password"
                                   class="form-control" required placeholder="Enter your current password">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="new_password">New Password</label>
                            <input type="password" id="new_password" name="new_password"
                                   class="form-control" required placeholder="At least 8 characters"
                                   oninput="checkPasswordStrength(this.value)">
                            <div class="password-strength">
                                <div class="password-strength-bar" id="strengthBar"></div>
                            </div>
                            <span class="strength-label" id="strengthLabel" style="color:var(--color-text-light)"></span>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="confirm_password">Confirm New Password</label>
                            <input type="password" id="confirm_password" name="confirm_password"
                                   class="form-control" required placeholder="Repeat new password">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="reset" class="btn btn-secondary">Clear</button>
                        <button type="submit" class="btn btn-primary">Update Password</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ══ TAB: Profile Picture ══ -->
        <div class="tab-panel" id="panel-picture">
            <div class="card">
                <div class="card-title">🖼️ Profile Picture</div>

                <!-- Current picture preview -->
                <div style="text-align:center;margin-bottom:24px;">
                    <div style="width:120px;height:120px;border-radius:50%;background:linear-gradient(135deg,var(--color-teacher-primary),var(--color-teacher-secondary));display:flex;align-items:center;justify-content:center;font-size:40px;font-weight:700;color:white;margin:0 auto 12px;overflow:hidden;border:4px solid rgba(173,73,225,0.2);" id="previewAvatar">
                        <?php if ($userPicture): ?>
                            <img src="<?= htmlspecialchars($userPicture) ?>" id="previewImg" style="width:100%;height:100%;object-fit:cover;" alt="Profile picture">
                        <?php else: ?>
                            <span id="previewInitials"><?= htmlspecialchars($userInitials) ?></span>
                        <?php endif; ?>
                    </div>
                    <p style="font-size:13px;color:var(--color-text-light);">Current profile picture</p>
                </div>

                <form method="POST" enctype="multipart/form-data" id="pictureForm">
                    <input type="hidden" name="action" value="upload_picture">

                    <div class="upload-zone" id="uploadZone" onclick="document.getElementById('pictureInput').click()">
                        <div class="upload-icon">📁</div>
                        <div class="upload-text">Click to browse or drag & drop your image here</div>
                        <p style="font-size:12px;color:var(--color-text-light);">JPG, PNG, GIF, WEBP · Max 2 MB</p>
                        <input type="file" id="pictureInput" name="profile_picture" accept="image/*"
                               style="display:none;" onchange="previewPicture(this)">
                    </div>

                    <div id="fileNameDisplay" style="font-size:13px;color:var(--color-text-light);margin-top:10px;display:none;"></div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="uploadBtn" disabled>Upload Picture</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ══ TAB: Activity Log ══ -->
        <!-- Sources:
             login_activity: log_id, user_id, ip_address, is_success, created_at
             assessments:    assessment_id, title, category, duration_minutes, status, updated_at
             Both queried in $activityRows UNION above.
        -->
        <div class="tab-panel" id="panel-activity">
            <div class="card">
                <div class="card-title">📋 Recent Activity</div>
                <div class="activity-list">
                    <?php if (empty($activityRows)): ?>
                        <p style="text-align:center;color:var(--color-text-light);padding:30px 0;">No activity recorded yet.</p>
                    <?php else: ?>
                        <?php foreach ($activityRows as $row): ?>
                            <?php
                                $icon = $row['activity_type'] === 'login' ? '🔑' : '📝';
                                $ts   = strtotime($row['created_at']);
                                $diff = time() - $ts;
                                if ($diff < 3600)           $timeAgo = round($diff / 60) . ' minutes ago';
                                elseif ($diff < 86400)      $timeAgo = round($diff / 3600) . ' hours ago';
                                elseif ($diff < 604800)     $timeAgo = round($diff / 86400) . ' days ago';
                                elseif ($diff < 2592000)    $timeAgo = round($diff / 604800) . ' weeks ago';
                                else                        $timeAgo = date('d M Y', $ts);
                            ?>
                            <div class="activity-item">
                                <div class="activity-icon"><?= $icon ?></div>
                                <div class="activity-text">
                                    <div class="activity-title"><?= htmlspecialchars($row['title']) ?></div>
                                    <?php if (!empty($row['description'])): ?>
                                        <div class="activity-desc"><?= htmlspecialchars($row['description']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="activity-time"><?= $timeAgo ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- Always show account creation at the bottom -->
                    <div class="activity-item">
                        <div class="activity-icon">🚀</div>
                        <div class="activity-text">
                            <div class="activity-title">Account created</div>
                            <div class="activity-desc">Welcome to the Placement Portal</div>
                        </div>
                        <div class="activity-time"><?= htmlspecialchars($memberSince) ?></div>
                    </div>
                </div>
            </div>
        </div>

    </main>
</div>

<script>
    /* ============================================
       TAB SWITCHING
       ============================================ */
    function switchTab(tab) {
        // Deactivate all panels and nav items
        document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.profile-nav-item').forEach(n => n.classList.remove('active'));

        // Activate selected
        const panel = document.getElementById('panel-' + tab);
        const nav   = document.getElementById('nav-' + tab);
        if (panel) panel.classList.add('active');
        if (nav)   nav.classList.add('active');
    }

    /* ============================================
       PROFILE DROPDOWN
       ============================================ */
    function toggleProfileDropdown() {
        const dropdown = document.getElementById('profileDropdown');
        const button   = document.querySelector('.profile-button');
        dropdown.classList.toggle('active');
        button.setAttribute('aria-expanded', dropdown.classList.contains('active'));
    }

    document.addEventListener('click', function(e) {
        const btn      = document.querySelector('.profile-button');
        const dropdown = document.getElementById('profileDropdown');
        if (!btn.contains(e.target) && dropdown.classList.contains('active')) {
            dropdown.classList.remove('active');
            btn.setAttribute('aria-expanded', 'false');
        }
    });

    function handleLogout() {
        if (confirm('Are you sure you want to logout?')) {
            window.location.href = 'logout.php';
        }
    }

    /* ============================================
       PASSWORD STRENGTH METER
       ============================================ */
    function checkPasswordStrength(value) {
        const bar   = document.getElementById('strengthBar');
        const label = document.getElementById('strengthLabel');

        if (!value) {
            bar.className = 'password-strength-bar';
            label.textContent = '';
            return;
        }

        let score = 0;
        if (value.length >= 8)            score++;
        if (/[A-Z]/.test(value))          score++;
        if (/[0-9]/.test(value))          score++;
        if (/[^A-Za-z0-9]/.test(value))   score++;

        if (score <= 1) {
            bar.className = 'password-strength-bar strength-weak';
            label.textContent = 'Weak';
            label.style.color = '#f56565';
        } else if (score <= 3) {
            bar.className = 'password-strength-bar strength-medium';
            label.textContent = 'Medium';
            label.style.color = '#ffc107';
        } else {
            bar.className = 'password-strength-bar strength-strong';
            label.textContent = 'Strong';
            label.style.color = '#48bb78';
        }
    }

    /* ============================================
       PICTURE PREVIEW
       ============================================ */
    function previewPicture(input) {
        const file = input.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = function(e) {
            const avatar = document.getElementById('previewAvatar');
            avatar.innerHTML = `<img src="${e.target.result}" style="width:100%;height:100%;object-fit:cover;" alt="Preview">`;
        };
        reader.readAsDataURL(file);

        const display = document.getElementById('fileNameDisplay');
        display.textContent = '📎 ' + file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
        display.style.display = 'block';

        document.getElementById('uploadBtn').disabled = false;
    }

    /* ── Drag & Drop ── */
    const uploadZone = document.getElementById('uploadZone');

    uploadZone.addEventListener('dragover', function(e) {
        e.preventDefault();
        uploadZone.classList.add('drag-over');
    });

    uploadZone.addEventListener('dragleave', function() {
        uploadZone.classList.remove('drag-over');
    });

    uploadZone.addEventListener('drop', function(e) {
        e.preventDefault();
        uploadZone.classList.remove('drag-over');
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            const input = document.getElementById('pictureInput');
            // Assign files via DataTransfer
            const dt = new DataTransfer();
            dt.items.add(files[0]);
            input.files = dt.files;
            previewPicture(input);
        }
    });

    /* ============================================
       AUTO-OPEN TAB FROM URL HASH
       ============================================ */
    window.addEventListener('DOMContentLoaded', function() {
        const hash = window.location.hash.replace('#', '');
        const validTabs = ['info', 'password', 'picture', 'activity'];
        if (validTabs.includes(hash)) {
            switchTab(hash);
        }
    });
</script>

</body>
</html>
<?php
/* ========================================
 * TEACHER PROFILE PAGE
 * ======================================== */

require "config.php";
require_once "db-guard.php";

$currentUser = validateSession($conn, 'teacher');
$teacherId   = (int) $currentUser['user_id'];

/*
 * FETCH TEACHER INFO
 */
$stmt = $conn->prepare("
    SELECT full_name, email, department, profile_image, created_at
    FROM users
    WHERE user_id = ?
");
$stmt->bind_param("i", $teacherId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$userName     = $user['full_name']     ?? 'Teacher';
$userEmail    = $user['email']         ?? '';
$userDept     = $user['department']    ?? '';
$userPicture  = $user['profile_image'] ?? '';
$memberSince  = $user['created_at']    ? date('F Y', strtotime($user['created_at'])) : 'N/A';
$userInitials = strtoupper(substr($userName, 0, 2));

/*
 * STATS
 */
$statsStmt = $conn->prepare("
    SELECT
        COUNT(DISTINCT a.assessment_id)       AS total_assessments,
        COUNT(DISTINCT aa.user_id)            AS total_students,
        IFNULL(AVG(aa.percentage), 0)         AS avg_score
    FROM assessments a
    LEFT JOIN assessment_attempts aa
        ON aa.assessment_id = a.assessment_id
        AND aa.status = 'completed'
    WHERE a.created_by = ?
");
$statsStmt->bind_param("i", $teacherId);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();

$totalAssessments = (int)($stats['total_assessments'] ?? 0);
$totalStudents    = (int)($stats['total_students']    ?? 0);
$avgScore         = round((float)($stats['avg_score'] ?? 0), 1);

/*
 * ACTIVITY LOG
 */
$activityStmt = $conn->prepare("
    (
        SELECT
            'login' AS activity_type,
            CASE WHEN is_success = 1 THEN 'Logged in successfully' ELSE 'Failed login attempt' END AS title,
            CONCAT('From IP: ', IFNULL(ip_address, 'unknown')) AS description,
            created_at
        FROM login_activity
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 5
    )
    UNION ALL
    (
        SELECT
            'assessment' AS activity_type,
            CONCAT(
                CASE status
                    WHEN 'active'   THEN 'Published assessment: '
                    WHEN 'draft'    THEN 'Saved draft: '
                    WHEN 'archived' THEN 'Archived assessment: '
                    ELSE 'Updated assessment: '
                END,
                title
            ) AS title,
            CONCAT(category, ' · ', duration_minutes, ' min') AS description,
            updated_at AS created_at
        FROM assessments
        WHERE created_by = ?
        ORDER BY updated_at DESC
        LIMIT 5
    )
    ORDER BY created_at DESC
    LIMIT 8
");
$activityStmt->bind_param("ii", $teacherId, $teacherId);
$activityStmt->execute();
$activityRows = $activityStmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* ── Handle POST actions ── */
$successMsg = '';
$errorMsg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'update_info') {
        $newName = trim($_POST['full_name']  ?? '');
        $newDept = trim($_POST['department'] ?? '');

        if (empty($newName)) {
            $errorMsg = 'Full name is required.';
        } else {
            $upd = $conn->prepare("UPDATE users SET full_name = ?, department = ? WHERE user_id = ?");
            $upd->bind_param("ssi", $newName, $newDept, $teacherId);
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

    if ($_POST['action'] === 'change_password') {
        $currentPwd = $_POST['current_password'] ?? '';
        $newPwd     = $_POST['new_password']     ?? '';
        $confirmPwd = $_POST['confirm_password'] ?? '';

        $pwdQuery = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ?");
        $pwdQuery->bind_param("i", $teacherId);
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
            $updPwd    = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
            $updPwd->bind_param("si", $hashedPwd, $teacherId);
            if ($updPwd->execute()) {
                $successMsg = 'Password changed successfully.';
            } else {
                $errorMsg = 'Failed to change password. Please try again.';
            }
        }
    }

    if ($_POST['action'] === 'upload_picture' && isset($_FILES['profile_picture'])) {
        $file    = $_FILES['profile_picture'];
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize = 2 * 1024 * 1024;

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errorMsg = 'Upload error. Please try again.';
        } elseif (!in_array($file['type'], $allowed)) {
            $errorMsg = 'Invalid file type. Please upload a JPG, PNG, GIF, or WEBP image.';
        } elseif ($file['size'] > $maxSize) {
            $errorMsg = 'File size exceeds 2 MB limit.';
        } else {
            $ext        = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $storedName = 'teacher_' . $teacherId . '_' . time() . '.' . $ext;
            $uploadDir  = 'uploads/profiles/';
            $fullPath   = $uploadDir . $storedName;

            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            if (move_uploaded_file($file['tmp_name'], $fullPath)) {
                $updPic = $conn->prepare("UPDATE users SET profile_image = ? WHERE user_id = ?");
                $updPic->bind_param("si", $fullPath, $teacherId);
                $updPic->execute();

                $insFile = $conn->prepare("
                    INSERT INTO uploaded_files
                        (original_filename, stored_filename, file_path, file_type,
                         mime_type, file_size, uploaded_by, entity_type, entity_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'user_profile', ?)
                ");
                $fileType = $ext;
                $mimeType = $file['type'];
                $fileSize = (int)$file['size'];
                $insFile->bind_param("sssssiii",
                    $file['name'], $storedName, $fullPath,
                    $fileType, $mimeType, $fileSize,
                    $teacherId, $teacherId
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
  <title>My Profile – PREPAURA</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
  <style>
:root {
  --ink:         #0d0a14;
  --ink-2:       #1a1425;
  --ink-3:       #261d35;
  --surface:     #f7f5fb;
  --surface-2:   #ede9f6;
  --surface-3:   #ffffff;
  --violet:      #7c3aed;
  --violet-lt:   #9f67f5;
  --violet-dim:  rgba(124,58,237,0.12);
  --violet-glow: rgba(124,58,237,0.25);
  --orchid:      #c084fc;
  --gold:        #f59e0b;
  --emerald:     #10b981;
  --rose:        #f43f5e;
  --sky:         #38bdf8;
  --text-1:      #1a1425;
  --text-2:      #4b4565;
  --text-3:      #8b7fa8;
  --border:      rgba(124,58,237,0.1);
  --border-2:    rgba(124,58,237,0.18);
  --shadow-xs:   0 1px 3px rgba(13,10,20,0.06);
  --shadow-sm:   0 2px 12px rgba(13,10,20,0.08);
  --shadow-md:   0 8px 32px rgba(13,10,20,0.12);
  --shadow-lg:   0 20px 60px rgba(13,10,20,0.18);
  --shadow-vl:   0 0 0 1px var(--border), 0 4px 24px rgba(124,58,237,0.1);
  --r-sm:        8px;
  --r-md:        14px;
  --r-lg:        20px;
  --r-xl:        28px;
  --ease:        cubic-bezier(0.22,1,0.36,1);
  --t:           0.22s var(--ease);
  --font-head:   'Syne', system-ui, sans-serif;
  --font-body:   'DM Sans', system-ui, sans-serif;
  --nav-h:       64px;
}

*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
html { -webkit-font-smoothing: antialiased; }

body {
  font-family: var(--font-body);
  background: var(--surface);
  color: var(--text-1);
  min-height: 100vh;
  padding-top: var(--nav-h);
  overflow-x: hidden;
}
body::before {
  content: '';
  position: fixed; inset: 0; z-index: 0; pointer-events: none;
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.03'/%3E%3C/svg%3E");
  background-size: 200px 200px;
}

/* ── NAVBAR ── */
.navbar {
  height: var(--nav-h);
  background: rgba(13,10,20,0.96);
  backdrop-filter: blur(20px) saturate(1.6);
  -webkit-backdrop-filter: blur(20px) saturate(1.6);
  border-bottom: 1px solid rgba(255,255,255,0.06);
  padding: 0 28px;
  display: flex; align-items: center; justify-content: space-between; gap: 20px;
  position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
}
.navbar-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; flex-shrink: 0; }
.brand-logo-img { width: 36px; height: 36px; border-radius: 9px; object-fit: contain; background: white; padding: 3px; }
.brand-text-group { display: flex; flex-direction: column; line-height: 1.15; }
.brand-name { font-family: var(--font-head); font-size: 16px; font-weight: 800; letter-spacing: 0.06em; color: white; }
.brand-tagline { font-size: 10px; font-weight: 400; color: rgba(255,255,255,0.45); letter-spacing: 0.03em; }

.nav-right { display: flex; align-items: center; gap: 12px; margin-left: auto; }
.profile-wrap { position: relative; }
.profile-button {
  display: flex; align-items: center; gap: 9px;
  padding: 6px 12px 6px 6px;
  background: rgba(255,255,255,0.07);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 40px; cursor: pointer; transition: var(--t); color: white;
}
.profile-button:hover { background: rgba(255,255,255,0.13); border-color: rgba(255,255,255,0.18); }
.profile-avatar {
  width: 32px; height: 32px; border-radius: 50%;
  background: linear-gradient(135deg, var(--violet), var(--orchid));
  display: flex; align-items: center; justify-content: center;
  font-family: var(--font-head); font-weight: 700; font-size: 12px; color: white;
  overflow: hidden; flex-shrink: 0;
}
.profile-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
.profile-name { font-size: 13px; font-weight: 500; }
.profile-caret { font-size: 9px; color: rgba(255,255,255,0.5); margin-left: 2px; }

.profile-dropdown {
  position: absolute; top: calc(100% + 10px); right: 0;
  background: var(--surface-3); border-radius: var(--r-md);
  box-shadow: var(--shadow-lg), 0 0 0 1px var(--border);
  min-width: 230px;
  opacity: 0; visibility: hidden; transform: translateY(-6px) scale(0.98);
  transition: var(--t); z-index: 1001; overflow: hidden;
}
.profile-dropdown.open { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
.dropdown-header {
  padding: 18px 20px;
  background: linear-gradient(135deg, var(--ink) 0%, var(--ink-3) 100%);
  border-bottom: 1px solid rgba(255,255,255,0.06);
}
.dd-avatar {
  width: 44px; height: 44px; border-radius: 50%;
  background: linear-gradient(135deg, var(--violet), var(--orchid));
  display: flex; align-items: center; justify-content: center;
  font-family: var(--font-head); font-weight: 700; font-size: 16px; color: white;
  overflow: hidden; margin-bottom: 10px;
}
.dd-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
.dropdown-name { font-weight: 600; font-size: 14px; color: white; }
.dropdown-email { font-size: 12px; color: rgba(255,255,255,0.5); margin-top: 2px; }
.dropdown-role {
  display: inline-block; margin-top: 8px; padding: 2px 10px;
  background: var(--violet-dim); border: 1px solid rgba(124,58,237,0.3);
  color: var(--orchid); border-radius: 20px; font-size: 11px; font-weight: 600;
  letter-spacing: 0.04em; text-transform: uppercase;
}
.dropdown-menu { padding: 6px 0; }
.dropdown-item {
  display: flex; align-items: center; gap: 11px;
  padding: 10px 18px; color: var(--text-2);
  text-decoration: none; font-size: 13.5px; transition: var(--t);
  cursor: pointer; border: none; background: none; width: 100%; text-align: left;
  font-family: var(--font-body);
}
.dropdown-item i { width: 16px; text-align: center; color: var(--text-3); }
.dropdown-item:hover { background: var(--surface-2); color: var(--text-1); }
.dropdown-item.danger { color: var(--rose); }
.dropdown-item.danger i { color: var(--rose); }
.dropdown-item.danger:hover { background: rgba(244,63,94,0.06); }
.dropdown-divider { height: 1px; background: var(--border); margin: 4px 0; }

/* ── PAGE LAYOUT ── */
.page-wrapper { display: flex; min-height: calc(100vh - var(--nav-h)); position: relative; z-index: 1; }

.left-sidebar {
  width: 230px; flex-shrink: 0; padding: 28px 12px;
  display: flex; flex-direction: column; gap: 2px;
  background: rgba(255,255,255,0.6); backdrop-filter: blur(12px);
  border-right: 1px solid var(--border);
  min-height: calc(100vh - var(--nav-h));
  position: sticky; top: var(--nav-h); align-self: flex-start;
}
.sidebar-section-label {
  font-family: var(--font-head);
  font-size: 10px; font-weight: 700; text-transform: uppercase;
  letter-spacing: 0.1em; color: var(--text-3); padding: 14px 14px 6px;
}
.sidebar-link {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 14px; border-radius: var(--r-sm);
  text-decoration: none; font-size: 13.5px; font-weight: 500;
  color: var(--text-2); transition: var(--t);
}
.sidebar-link i { width: 18px; text-align: center; font-size: 14px; color: var(--text-3); transition: var(--t); }
.sidebar-link:hover { background: var(--violet-dim); color: var(--violet); }
.sidebar-link:hover i { color: var(--violet); }
.sidebar-link.active {
  background: linear-gradient(135deg, rgba(124,58,237,0.12), rgba(192,132,252,0.08));
  color: var(--violet); font-weight: 600; box-shadow: inset 3px 0 0 var(--violet);
}
.sidebar-link.active i { color: var(--violet); }
.sidebar-bottom { margin-top: auto; padding-top: 16px; border-top: 1px solid var(--border); }
.sidebar-logout {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 14px; border-radius: var(--r-sm);
  font-size: 13.5px; font-weight: 500; color: var(--rose);
  background: none; border: none; cursor: pointer; width: 100%;
  transition: var(--t); font-family: var(--font-body);
}
.sidebar-logout i { width: 18px; text-align: center; font-size: 14px; }
.sidebar-logout:hover { background: rgba(244,63,94,0.07); }

.page-content { flex: 1; min-width: 0; padding: 36px 36px 48px 28px; }

/* ── PROFILE GRID ── */
.profile-grid { display: grid; grid-template-columns: 272px 1fr; gap: 24px; align-items: start; }

/* ── PROFILE CARD ── */
.profile-card {
  background: var(--surface-3); border-radius: var(--r-lg);
  padding: 28px 20px; box-shadow: var(--shadow-vl);
  text-align: center;
  position: sticky; top: calc(var(--nav-h) + 24px);
}
.avatar-wrapper { position: relative; display: inline-block; margin-bottom: 16px; }
.avatar-large {
  width: 96px; height: 96px; border-radius: 50%;
  background: linear-gradient(135deg, var(--violet), var(--orchid));
  display: flex; align-items: center; justify-content: center;
  font-family: var(--font-head); font-size: 30px; font-weight: 700; color: white;
  margin: 0 auto; overflow: hidden;
  border: 3px solid rgba(124,58,237,0.2);
  box-shadow: 0 0 0 1px var(--border-2);
}
.avatar-large img { width: 100%; height: 100%; object-fit: cover; }
.avatar-edit-btn {
  position: absolute; bottom: 2px; right: 2px;
  width: 26px; height: 26px; background: var(--violet);
  border: 2px solid white; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; font-size: 10px; color: white; transition: var(--t);
}
.avatar-edit-btn:hover { background: var(--violet-lt); transform: scale(1.1); }

.profile-card-name { font-family: var(--font-head); font-size: 17px; font-weight: 700; color: var(--text-1); margin-bottom: 6px; }
.role-badge {
  display: inline-block; padding: 3px 12px;
  background: var(--violet-dim); border: 1px solid rgba(124,58,237,0.3);
  color: var(--orchid); border-radius: 20px;
  font-size: 11px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase;
  margin-bottom: 14px;
}
.profile-card-detail {
  font-size: 12.5px; color: var(--text-3); margin-bottom: 5px;
  display: flex; align-items: center; justify-content: center; gap: 6px;
}
.profile-card-detail i { color: var(--violet); font-size: 11px; }

.profile-divider { height: 1px; background: var(--border); margin: 16px 0; }

.stat-row { display: flex; justify-content: space-around; }
.stat-item { text-align: center; }
.stat-item-value { font-family: var(--font-head); font-size: 20px; font-weight: 700; color: var(--violet); }
.stat-item-label { font-size: 10px; color: var(--text-3); text-transform: uppercase; letter-spacing: 0.06em; margin-top: 2px; }

.profile-nav { margin-top: 16px; display: flex; flex-direction: column; gap: 3px; }
.profile-nav-item {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 14px; border-radius: var(--r-sm);
  font-size: 13.5px; font-weight: 500; color: var(--text-2);
  cursor: pointer; transition: var(--t);
  border: none; background: transparent; width: 100%; text-align: left;
  font-family: var(--font-body);
}
.profile-nav-item i { width: 16px; text-align: center; font-size: 13px; color: var(--text-3); transition: var(--t); }
.profile-nav-item:hover { background: var(--violet-dim); color: var(--violet); }
.profile-nav-item:hover i { color: var(--violet); }
.profile-nav-item.active {
  background: linear-gradient(135deg, rgba(124,58,237,0.12), rgba(192,132,252,0.08));
  color: var(--violet); font-weight: 600; box-shadow: inset 3px 0 0 var(--violet);
}
.profile-nav-item.active i { color: var(--violet); }

/* ── TAB PANELS ── */
.tab-panel { display: none; }
.tab-panel.active { display: block; }

.card {
  background: var(--surface-3); border-radius: var(--r-lg);
  padding: 28px; box-shadow: var(--shadow-vl); margin-bottom: 20px;
}
.card-title {
  font-family: var(--font-head);
  font-size: 15px; font-weight: 700; color: var(--text-1);
  margin-bottom: 20px; padding-bottom: 14px;
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap: 10px;
}
.card-title i { color: var(--violet); }

/* ── Forms ── */
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-group.full-width { grid-column: 1 / -1; }
.form-label { font-size: 11px; font-weight: 700; color: var(--text-3); text-transform: uppercase; letter-spacing: 0.08em; }
.form-control {
  padding: 10px 14px;
  border: 1px solid var(--border-2); border-radius: var(--r-sm);
  font-size: 13.5px; font-family: var(--font-body); color: var(--text-1);
  background: var(--surface); transition: var(--t); outline: none;
}
.form-control:focus { border-color: var(--violet); background: var(--surface-3); box-shadow: 0 0 0 3px var(--violet-dim); }
.form-control[readonly] { background: var(--surface-2); color: var(--text-3); cursor: not-allowed; border-color: var(--border); }
textarea.form-control { resize: vertical; min-height: 100px; }

.form-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; }

.btn {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 9px 20px; border-radius: var(--r-sm);
  font-size: 13px; font-weight: 600; font-family: var(--font-body);
  cursor: pointer; transition: var(--t); border: none;
}
.btn-primary { background: var(--violet); color: white; }
.btn-primary:hover { background: var(--violet-lt); transform: translateY(-1px); box-shadow: 0 4px 16px rgba(124,58,237,0.4); }
.btn-secondary { background: var(--surface-2); color: var(--text-2); border: 1px solid var(--border-2); }
.btn-secondary:hover { background: var(--surface); color: var(--text-1); }

/* ── Alerts ── */
.alert {
  padding: 12px 16px; border-radius: var(--r-sm);
  font-size: 13.5px; font-weight: 500; margin-bottom: 18px;
  display: flex; align-items: center; gap: 10px;
}
.alert-success { background: rgba(16,185,129,0.08); border: 1px solid rgba(16,185,129,0.3); color: #065f46; }
.alert-error { background: rgba(244,63,94,0.07); border: 1px solid rgba(244,63,94,0.25); color: var(--rose); }

/* ── Password strength ── */
.password-strength { margin-top: 6px; height: 3px; border-radius: 2px; background: var(--border-2); overflow: hidden; }
.password-strength-bar { height: 100%; border-radius: 2px; transition: var(--t); width: 0%; }
.strength-weak   { width: 33%; background: var(--rose); }
.strength-medium { width: 66%; background: var(--gold); }
.strength-strong { width: 100%; background: var(--emerald); }
.strength-label  { font-size: 12px; margin-top: 4px; }

/* ── Activity ── */
.activity-list { display: flex; flex-direction: column; }
.activity-item { display: flex; align-items: flex-start; gap: 14px; padding: 14px 0; border-bottom: 1px solid var(--border); }
.activity-item:last-child { border-bottom: none; }
.activity-icon {
  width: 36px; height: 36px; border-radius: var(--r-sm);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; font-size: 15px;
}
.activity-icon.login-icon      { background: rgba(56,189,248,0.12); color: var(--sky); }
.activity-icon.assessment-icon { background: var(--violet-dim); color: var(--violet); }
.activity-icon.default-icon    { background: rgba(16,185,129,0.1); color: var(--emerald); }
.activity-text { flex: 1; min-width: 0; }
.activity-title { font-size: 13.5px; font-weight: 600; color: var(--text-1); margin-bottom: 2px; }
.activity-desc  { font-size: 12px; color: var(--text-3); }
.activity-time  { font-size: 11.5px; color: var(--text-3); white-space: nowrap; margin-top: 2px; }

/* ── Upload zone ── */
.upload-zone {
  border: 1.5px dashed var(--border-2); border-radius: var(--r-md);
  padding: 32px; text-align: center; cursor: pointer; transition: var(--t);
}
.upload-zone:hover { border-color: var(--violet); background: var(--violet-dim); }
.upload-zone.drag-over { border-color: var(--violet); background: rgba(124,58,237,0.08); }
.upload-icon { font-size: 32px; color: var(--text-3); margin-bottom: 10px; }
.upload-text { font-size: 13.5px; color: var(--text-2); margin-bottom: 6px; }
.upload-hint { font-size: 12px; color: var(--text-3); }

/* ── Responsive ── */
@media (max-width: 1024px) {
  .profile-grid { grid-template-columns: 1fr; }
  .profile-card { position: static; }
  .profile-nav { flex-direction: row; flex-wrap: wrap; }
  .profile-nav-item { flex: 1; min-width: 120px; justify-content: center; }
}
@media (max-width: 768px) {
  .left-sidebar { display: none; }
  .page-content { padding: 24px 16px; }
  .form-grid { grid-template-columns: 1fr; }
  .navbar { padding: 0 16px; }
}
  </style>
</head>
<body>

<!-- ── NAVBAR ── -->
<nav class="navbar">
  <a href="teacher-dashboard.php" class="navbar-brand">
    <img src="prepaura-logo.png" alt="PREPAURA" class="brand-logo-img">
    <div class="brand-text-group">
      <span class="brand-name">PREPAURA</span>
      <span class="brand-tagline">Placement Training Platform</span>
    </div>
  </a>

  <div class="nav-right">
    <div class="profile-wrap">
      <button class="profile-button" id="profileBtn">
        <div class="profile-avatar">
          <?php if (!empty($userPicture)): ?>
            <img src="<?= htmlspecialchars($userPicture) ?>" alt="Profile">
          <?php else: ?>
            <?= htmlspecialchars($userInitials) ?>
          <?php endif; ?>
        </div>
        <span class="profile-name"><?= htmlspecialchars($userName) ?></span>
        <i class="fa fa-chevron-down profile-caret"></i>

        <div class="profile-dropdown" id="profileDropdown">
          <div class="dropdown-header">
            <div class="dd-avatar">
              <?php if (!empty($userPicture)): ?>
                <img src="<?= htmlspecialchars($userPicture) ?>" alt="Profile">
              <?php else: ?>
                <?= htmlspecialchars($userInitials) ?>
              <?php endif; ?>
            </div>
            <div class="dropdown-name"><?= htmlspecialchars($userName) ?></div>
            <div class="dropdown-email"><?= htmlspecialchars($userEmail) ?></div>
            <span class="dropdown-role">Teacher</span>
          </div>
          <div class="dropdown-menu">
            <a href="teacher-profile.php" class="dropdown-item"><i class="fa fa-user"></i> My Profile</a>
            <a href="help.html" target="_blank" rel="noopener" class="dropdown-item"><i class="fa fa-circle-question"></i> Help &amp; Support</a>
            <div class="dropdown-divider"></div>
            <a href="#" onclick="handleLogout()" class="dropdown-item danger"><i class="fa fa-right-from-bracket"></i> Logout</a>
          </div>
        </div>
      </button>
    </div>
  </div>
</nav>

<!-- ── MAIN ── -->
<div class="page-wrapper">

  <aside class="left-sidebar">
    <span class="sidebar-section-label">Navigation</span>
    <a href="teacher-dashboard.php" class="sidebar-link"><i class="fa fa-house"></i> Dashboard</a>
    <a href="teacher-assessments.php" class="sidebar-link"><i class="fa fa-clipboard-list"></i> Assessments</a>
    <a href="api/groups/manage-groups.php" class="sidebar-link"><i class="fa fa-users"></i> Manage Groups</a>
    <a href="teacher-resources.php" class="sidebar-link"><i class="fa fa-folder-open"></i> Resources</a>
    <div class="sidebar-bottom">
      <button onclick="handleLogout()" class="sidebar-logout"><i class="fa fa-right-from-bracket"></i> Logout</button>
    </div>
  </aside>

  <div class="page-content">
    <div class="profile-grid">

      <!-- Profile Card -->
      <aside>
        <div class="profile-card">
          <div class="avatar-wrapper">
            <div class="avatar-large">
              <?php if (!empty($userPicture)): ?>
                <img src="<?= htmlspecialchars($userPicture) ?>" alt="Profile picture">
              <?php else: ?>
                <?= htmlspecialchars($userInitials) ?>
              <?php endif; ?>
            </div>
            <div class="avatar-edit-btn" onclick="switchTab('picture')" title="Change photo">
              <i class="fa fa-pen"></i>
            </div>
          </div>

          <div class="profile-card-name"><?= htmlspecialchars($userName) ?></div>
          <div class="role-badge">Teacher</div>

          <?php if ($userDept): ?>
            <div class="profile-card-detail"><i class="fa fa-building"></i> <?= htmlspecialchars($userDept) ?></div>
          <?php endif; ?>
          <div class="profile-card-detail"><i class="fa fa-envelope"></i> <?= htmlspecialchars($userEmail) ?></div>
          <div class="profile-card-detail"><i class="fa fa-calendar"></i> Since <?= htmlspecialchars($memberSince) ?></div>

          <div class="profile-divider"></div>

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

          <nav class="profile-nav">
            <button class="profile-nav-item active" onclick="switchTab('info')" id="nav-info">
              <i class="fa fa-user"></i> Personal Info
            </button>
            <button class="profile-nav-item" onclick="switchTab('password')" id="nav-password">
              <i class="fa fa-lock"></i> Change Password
            </button>
            <button class="profile-nav-item" onclick="switchTab('picture')" id="nav-picture">
              <i class="fa fa-image"></i> Profile Picture
            </button>
            <button class="profile-nav-item" onclick="switchTab('activity')" id="nav-activity">
              <i class="fa fa-clock-rotate-left"></i> Activity Log
            </button>
          </nav>
        </div>
      </aside>

      <!-- Tab Panels -->
      <main>

        <?php if ($successMsg): ?>
          <div class="alert alert-success"><i class="fa fa-circle-check"></i> <?= htmlspecialchars($successMsg) ?></div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
          <div class="alert alert-error"><i class="fa fa-triangle-exclamation"></i> <?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <!-- Personal Info -->
        <div class="tab-panel active" id="panel-info">
          <div class="card">
            <div class="card-title"><i class="fa fa-user"></i> Personal Information</div>
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
                <button type="submit" class="btn btn-primary"><i class="fa fa-floppy-disk"></i> Save Changes</button>
              </div>
            </form>
          </div>

          <div class="card">
            <div class="card-title"><i class="fa fa-gear"></i> Account Details</div>
            <div class="form-grid">
              <div class="form-group">
                <label class="form-label">Role</label>
                <input type="text" class="form-control" value="Teacher" readonly>
              </div>
              <div class="form-group">
                <label class="form-label">Member Since</label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($memberSince) ?>" readonly>
              </div>
              <div class="form-group">
                <label class="form-label">User ID</label>
                <input type="text" class="form-control" value="#<?= htmlspecialchars($teacherId) ?>" readonly>
              </div>
              <div class="form-group">
                <label class="form-label">Account Status</label>
                <input type="text" class="form-control" value="Active" readonly>
              </div>
            </div>
          </div>
        </div>

        <!-- Change Password -->
        <div class="tab-panel" id="panel-password">
          <div class="card">
            <div class="card-title"><i class="fa fa-lock"></i> Change Password</div>
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
                  <span class="strength-label" id="strengthLabel" style="color:var(--text-3)"></span>
                </div>
                <div class="form-group">
                  <label class="form-label" for="confirm_password">Confirm New Password</label>
                  <input type="password" id="confirm_password" name="confirm_password"
                         class="form-control" required placeholder="Repeat new password">
                </div>
              </div>
              <div class="form-actions">
                <button type="reset" class="btn btn-secondary">Clear</button>
                <button type="submit" class="btn btn-primary"><i class="fa fa-key"></i> Update Password</button>
              </div>
            </form>
          </div>
        </div>

        <!-- Profile Picture -->
        <div class="tab-panel" id="panel-picture">
          <div class="card">
            <div class="card-title"><i class="fa fa-image"></i> Profile Picture</div>

            <div style="text-align:center;margin-bottom:24px;">
              <div id="previewAvatar" style="width:110px;height:110px;border-radius:50%;background:linear-gradient(135deg,var(--violet),var(--orchid));display:flex;align-items:center;justify-content:center;font-family:var(--font-head);font-size:36px;font-weight:700;color:white;margin:0 auto 10px;overflow:hidden;border:3px solid rgba(124,58,237,0.2);">
                <?php if (!empty($userPicture)): ?>
                  <img src="<?= htmlspecialchars($userPicture) ?>" style="width:100%;height:100%;object-fit:cover;" alt="Profile picture">
                <?php else: ?>
                  <span id="previewInitials"><?= htmlspecialchars($userInitials) ?></span>
                <?php endif; ?>
              </div>
              <p style="font-size:12px;color:var(--text-3);">Current profile picture</p>
            </div>

            <form method="POST" enctype="multipart/form-data" id="pictureForm">
              <input type="hidden" name="action" value="upload_picture">
              <div class="upload-zone" id="uploadZone" onclick="document.getElementById('pictureInput').click()">
                <div class="upload-icon"><i class="fa fa-cloud-arrow-up"></i></div>
                <div class="upload-text">Click to browse or drag &amp; drop your image here</div>
                <div class="upload-hint">JPG, PNG, GIF, WEBP · Max 2 MB</div>
                <input type="file" id="pictureInput" name="profile_picture" accept="image/*"
                       style="display:none;" onchange="previewPicture(this)">
              </div>
              <div id="fileNameDisplay" style="font-size:12.5px;color:var(--text-3);margin-top:10px;display:none;"></div>
              <div class="form-actions">
                <button type="submit" class="btn btn-primary" id="uploadBtn" disabled>
                  <i class="fa fa-upload"></i> Upload Picture
                </button>
              </div>
            </form>
          </div>
        </div>

        <!-- Activity Log -->
        <div class="tab-panel" id="panel-activity">
          <div class="card">
            <div class="card-title"><i class="fa fa-clock-rotate-left"></i> Recent Activity</div>
            <div class="activity-list">
              <?php if (empty($activityRows)): ?>
                <p style="text-align:center;color:var(--text-3);padding:30px 0;">No activity recorded yet.</p>
              <?php else: ?>
                <?php foreach ($activityRows as $row):
                  $isLogin = $row['activity_type'] === 'login';
                  $ts   = strtotime($row['created_at']);
                  $diff = time() - $ts;
                  if ($diff < 3600)        $timeAgo = round($diff / 60) . ' min ago';
                  elseif ($diff < 86400)   $timeAgo = round($diff / 3600) . ' hr ago';
                  elseif ($diff < 604800)  $timeAgo = round($diff / 86400) . ' days ago';
                  elseif ($diff < 2592000) $timeAgo = round($diff / 604800) . ' wks ago';
                  else                     $timeAgo = date('d M Y', $ts);
                ?>
                  <div class="activity-item">
                    <div class="activity-icon <?= $isLogin ? 'login-icon' : 'assessment-icon' ?>">
                      <i class="fa <?= $isLogin ? 'fa-right-to-bracket' : 'fa-clipboard-list' ?>"></i>
                    </div>
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

              <div class="activity-item">
                <div class="activity-icon default-icon"><i class="fa fa-rocket"></i></div>
                <div class="activity-text">
                  <div class="activity-title">Account created</div>
                  <div class="activity-desc">Welcome to PREPAURA</div>
                </div>
                <div class="activity-time"><?= htmlspecialchars($memberSince) ?></div>
              </div>
            </div>
          </div>
        </div>

      </main>
    </div><!-- /profile-grid -->
  </div><!-- /page-content -->
</div><!-- /page-wrapper -->

<script>
/* ── Tab switching ── */
function switchTab(tab) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.profile-nav-item').forEach(n => n.classList.remove('active'));
  const panel = document.getElementById('panel-' + tab);
  const nav   = document.getElementById('nav-' + tab);
  if (panel) panel.classList.add('active');
  if (nav)   nav.classList.add('active');
}

/* ── Profile dropdown ── */
const profileBtn      = document.getElementById('profileBtn');
const profileDropdown = document.getElementById('profileDropdown');
profileBtn.addEventListener('click', e => { e.stopPropagation(); profileDropdown.classList.toggle('open'); });
document.addEventListener('click', () => profileDropdown.classList.remove('open'));
profileDropdown.addEventListener('click', e => e.stopPropagation());

function handleLogout() {
  if (confirm('Are you sure you want to logout?')) window.location.href = 'logout.php';
}

/* ── Password strength ── */
function checkPasswordStrength(value) {
  const bar   = document.getElementById('strengthBar');
  const label = document.getElementById('strengthLabel');
  if (!value) { bar.className = 'password-strength-bar'; label.textContent = ''; return; }
  let score = 0;
  if (value.length >= 8)           score++;
  if (/[A-Z]/.test(value))         score++;
  if (/[0-9]/.test(value))         score++;
  if (/[^A-Za-z0-9]/.test(value))  score++;
  if (score <= 1) {
    bar.className = 'password-strength-bar strength-weak';
    label.textContent = 'Weak'; label.style.color = 'var(--rose)';
  } else if (score <= 3) {
    bar.className = 'password-strength-bar strength-medium';
    label.textContent = 'Medium'; label.style.color = 'var(--gold)';
  } else {
    bar.className = 'password-strength-bar strength-strong';
    label.textContent = 'Strong'; label.style.color = 'var(--emerald)';
  }
}

/* ── Picture preview ── */
function previewPicture(input) {
  const file = input.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = e => {
    document.getElementById('previewAvatar').innerHTML =
      `<img src="${e.target.result}" style="width:100%;height:100%;object-fit:cover;" alt="Preview">`;
  };
  reader.readAsDataURL(file);
  const display = document.getElementById('fileNameDisplay');
  display.textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
  display.style.display = 'block';
  document.getElementById('uploadBtn').disabled = false;
}

/* ── Drag & drop ── */
const uploadZone = document.getElementById('uploadZone');
uploadZone.addEventListener('dragover',  e => { e.preventDefault(); uploadZone.classList.add('drag-over'); });
uploadZone.addEventListener('dragleave', ()  => uploadZone.classList.remove('drag-over'));
uploadZone.addEventListener('drop', function(e) {
  e.preventDefault();
  uploadZone.classList.remove('drag-over');
  const files = e.dataTransfer.files;
  if (files.length > 0) {
    const input = document.getElementById('pictureInput');
    const dt = new DataTransfer();
    dt.items.add(files[0]);
    input.files = dt.files;
    previewPicture(input);
  }
});

/* ── Auto-open tab from URL hash ── */
window.addEventListener('DOMContentLoaded', () => {
  const hash = window.location.hash.replace('#', '');
  if (['info','password','picture','activity'].includes(hash)) switchTab(hash);
});
</script>
</body>
</html>

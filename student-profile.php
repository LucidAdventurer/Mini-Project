<?php
/* ========================================
 * STUDENT PROFILE PAGE
 * ======================================== */
 
require_once "config.php";
require_once "db-guard.php";
 
$user = validateSession($conn, 'student');
 
// Always re-fetch fresh user data from DB (so profile_image is current)
$freshUser = safePreparedQuery($conn,
    "SELECT * FROM users WHERE user_id = ?",
    "i", [(int)$user['user_id']]
);
if ($freshUser['success'] && $freshUser['result']) {
    $row = $freshUser['result']->fetch_assoc();
    if ($row) $user = array_merge($user, $row);
    $freshUser['result']->free();
}

// Postgres returns boolean columns via PDO as 't'/'f' strings, both of which
// are truthy in plain PHP — normalize once here so every ?: / truthiness
// check on these two fields below works correctly.
if (array_key_exists('is_active', $user))   $user['is_active']   = pgBoolGuard($user['is_active']);
if (array_key_exists('is_verified', $user)) $user['is_verified'] = pgBoolGuard($user['is_verified']);
 
$userName     = $user['full_name']           ?? 'Student';
$userEmail    = $user['email']               ?? '';
$userDept     = $user['department']          ?? '';
$userRegNo    = $user['registration_number'] ?? '';
$userInitials = strtoupper(substr($userName, 0, 2));
$userId       = $user['user_id'];
$memberSince  = !empty($user['created_at']) ? date('F Y', strtotime($user['created_at'])) : 'N/A';
 



// Ensure CSRF token exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$lastLogin = $user['last_login'] ?? null;
 
// ── Unread notification count ──
$unreadResult = safePreparedQuery($conn,
    "SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = false",
    "i", [$userId]
);
$unreadCount = 0;
if ($unreadResult['success'] && $unreadResult['result']) {
    $row = $unreadResult['result']->fetch_assoc();
    $unreadCount = (int)($row['cnt'] ?? 0);
    $unreadResult['result']->free();
}
 
// ── All notifications for navbar dropdown (scroll, latest first) ──
$notifDropResult = safePreparedQuery($conn,
    "SELECT notification_id, title, message, type, is_read, created_at
     FROM notifications WHERE user_id = ?
     ORDER BY created_at DESC",
    "i", [$userId]
);
$notifItems = [];
if ($notifDropResult['success'] && $notifDropResult['result']) {
    while ($nrow = $notifDropResult['result']->fetch_assoc()) {
        $notifItems[] = $nrow;
    }
    $notifDropResult['result']->free();
}
 
function timeAgoProfile(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff / 60)   . ' min ago';
    if ($diff < 86400)  return floor($diff / 3600)  . ' hr ago';
    if ($diff < 604800) return floor($diff / 86400) . ' day ago';
    return date('d M Y', strtotime($datetime));
}
 
$statsQuery = "
    SELECT
        COUNT(DISTINCT a.attempt_id)             AS tests_completed,
        COALESCE(AVG(a.percentage), 0)           AS avg_score,
        COALESCE(MAX(a.percentage), 0)           AS best_score,
        COUNT(DISTINCT DATE(a.submitted_at))     AS active_days
    FROM assessment_attempts a
    WHERE a.user_id = ? AND a.status = 'submitted'
";
$statsResult    = safePreparedQuery($conn, $statsQuery, "i", [$userId]);
$testsCompleted = 0; $avgScore = 0; $bestScore = 0;
$activeDays     = 0;
if ($statsResult['success'] && $statsResult['result']) {
    $s              = $statsResult['result']->fetch_assoc();
    $testsCompleted = (int)   ($s['tests_completed'] ?? 0);
    $avgScore       = (int) round($s['avg_score']    ?? 0);
    $bestScore      = (int) round($s['best_score']   ?? 0);
    $activeDays     = (int)   ($s['active_days']     ?? 0);
    $statsResult['result']->free();
}
 
// ── Recent attempts (last 10) ──
$recentQuery = "
    SELECT
        a.attempt_id,
        a.percentage,
        a.score,
        a.submitted_at,
        FLOOR(EXTRACT(EPOCH FROM (a.submitted_at - a.start_time)) / 60)::int AS time_taken_minutes,
        t.title      AS test_title,
        t.category,
        t.total_marks,
        t.difficulty
    FROM assessment_attempts a
    JOIN assessments t ON t.assessment_id = a.assessment_id
    WHERE a.user_id = ? AND a.status = 'submitted'
    ORDER BY a.submitted_at DESC
    LIMIT 10
";
$recentResult   = safePreparedQuery($conn, $recentQuery, "i", [$userId]);
$recentAttempts = [];
if ($recentResult['success'] && $recentResult['result']) {
    while ($row = $recentResult['result']->fetch_assoc()) {
        $recentAttempts[] = $row;
    }
    $recentResult['result']->free();
}
 
// ── Category performance breakdown ──
$categoryQuery = "
    SELECT
        t.category,
        COUNT(a.attempt_id)                  AS attempts,
        COALESCE(AVG(a.percentage), 0)       AS avg_score,
        COALESCE(MAX(a.percentage), 0)       AS best_score
    FROM assessment_attempts a
    JOIN assessments t ON t.assessment_id = a.assessment_id
    WHERE a.user_id = ? AND a.status = 'submitted'
      AND t.category IS NOT NULL AND t.category != ''
    GROUP BY t.category
    ORDER BY avg_score DESC
";
$categoryResult = safePreparedQuery($conn, $categoryQuery, "i", [$userId]);
$categories     = [];
if ($categoryResult['success'] && $categoryResult['result']) {
    while ($row = $categoryResult['result']->fetch_assoc()) {
        $categories[] = $row;
    }
    $categoryResult['result']->free();
}
 
// ── Answer accuracy (correct vs wrong) ──
$totalCorrect = 0;
$totalWrong   = 0;
$accResult = safePreparedQuery($conn,
    "SELECT
        SUM(CASE WHEN a.marks_awarded > 0 THEN 1 ELSE 0 END) AS correct,
        SUM(CASE WHEN a.marks_awarded <= 0 AND a.selected_option_id IS NOT NULL THEN 1 ELSE 0 END) AS wrong
     FROM answers a
     JOIN assessment_attempts aa ON aa.attempt_id = a.attempt_id
     WHERE aa.user_id = ? AND aa.status = 'submitted'",
    "i", [$userId]
);
if ($accResult['success'] && $accResult['result']) {
    $accRow       = $accResult['result']->fetch_assoc();
    $totalCorrect = (int)($accRow['correct'] ?? 0);
    $totalWrong   = (int)($accRow['wrong']   ?? 0);
    $accResult['result']->free();
}
 
// ── Notifications (unread count) ──
$notifResult = safePreparedQuery(
    $conn,
    "SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = false",
    "i", [$userId]
);
$unreadCount = 0;
if ($notifResult['success'] && $notifResult['result']) {
    $nr = $notifResult['result']->fetch_assoc();
    $unreadCount = (int)($nr['cnt'] ?? 0);
    $notifResult['result']->free();
}
 
// ── Handle POST ──
$updateMessage = '';
$updateType    = '';
 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
 
    if ($_POST['action'] === 'update_profile') {
        $newName    = trim($_POST['full_name']           ?? '');
        $newEmail   = trim($_POST['email']               ?? '');
        $newDept    = trim($_POST['department']          ?? '');
        $newRegNo   = trim($_POST['registration_number'] ?? '');
        $confirmPw  = $_POST['confirm_password_profile'] ?? '';
 
        if ($newName === '') {
            $updateMessage = 'Full name is required.';
            $updateType    = 'error';
        } elseif ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $updateMessage = 'A valid email address is required.';
            $updateType    = 'error';
        } else {
            // Email change requires password confirmation
            $emailChanged = ($newEmail !== $userEmail);
            if ($emailChanged) {
                if ($confirmPw === '') {
                    $updateMessage = 'Please enter your current password to change your email.';
                    $updateType    = 'error';
                } else {
                    $pwCheck = safePreparedQuery($conn, "SELECT password_hash FROM users WHERE user_id = ?", "i", [$userId]);
                    $pwRow   = null;
                    if ($pwCheck['success'] && $pwCheck['result']) {
                        $pwRow = $pwCheck['result']->fetch_assoc();
                        $pwCheck['result']->free();
                    }
                    if (!$pwRow || !password_verify($confirmPw, $pwRow['password_hash'])) {
                        $updateMessage = 'Incorrect password. Email not updated.';
                        $updateType    = 'error';
                        $emailChanged  = false;
                    }
                }
            }
 
            if ($updateType !== 'error') {
                // Check email uniqueness if changed
                if ($emailChanged) {
                    $emailCheck = safePreparedQuery($conn,
                        "SELECT user_id FROM users WHERE email = ? AND user_id != ?",
                        "si", [$newEmail, $userId]
                    );
                    if ($emailCheck['success'] && $emailCheck['result'] && $emailCheck['result']->num_rows > 0) {
                        $updateMessage = 'That email address is already in use.';
                        $updateType    = 'error';
                        $emailCheck['result']->free();
                    }
                }
 
                if ($updateType !== 'error') {
                    $upRes = safePreparedQuery($conn,
                        "UPDATE users SET full_name = ?, email = ?, department = ?, registration_number = ? WHERE user_id = ?",
                        "ssssi",
                        [$newName, $newEmail, $newDept ?: null, $newRegNo ?: null, $userId]
                    );
                    if ($upRes['success']) {
                        $userName     = $newName;
                        $userEmail    = $newEmail;
                        $userDept     = $newDept;
                        $userRegNo    = $newRegNo;
                        $userInitials = strtoupper(substr($userName, 0, 2));
                        $user['full_name']           = $newName;
                        $user['email']               = $newEmail;
                        $user['department']          = $newDept;
                        $user['registration_number'] = $newRegNo;
                        $updateMessage = 'Profile updated successfully!';
                        $updateType    = 'success';
                        header('Location: student-profile.php?t=' . time() . '&msg=profile_updated');
                        exit;
                    } else {
                        $updateMessage = 'Failed to update profile. Please try again.';
                        $updateType    = 'error';
                    }
                }
            }
        }
    }
 
    if ($_POST['action'] === 'upload_avatar') {
        if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            $updateMessage = 'Upload error. Please try again.';
            $updateType    = 'error';
        } else {
            $file    = $_FILES['avatar'];
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $maxSize = 2 * 1024 * 1024;
            $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
 
            if ($file['size'] > $maxSize) {
                $updateMessage = 'Image must be under 2MB.';
                $updateType    = 'error';
            } elseif (!in_array($file['type'], $allowed)) {
                $updateMessage = 'Only JPG, PNG, GIF or WEBP images allowed.';
                $updateType    = 'error';
            } else {
                $uploadDir  = 'uploads/avatars/';
                $storedName = 'student_' . $userId . '_' . time() . '.' . $ext;
                $fullPath   = $uploadDir . $storedName;
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                // Delete old avatar
                $oldImg = $user['profile_image'] ?? '';
                if ($oldImg && file_exists($oldImg)) @unlink($oldImg);
                if (move_uploaded_file($file['tmp_name'], $fullPath)) {
                    $upRes = safePreparedQuery($conn, "UPDATE users SET profile_image = ? WHERE user_id = ?", "si", [$fullPath, $userId]);
                    if ($upRes['success']) {
                        $_SESSION['profile_image'] = $fullPath;
                        $user['profile_image']     = $fullPath;
                        $updateMessage = 'Profile picture updated!';
                        $updateType    = 'success';
                        // Redirect to avoid re-POST on refresh
                        header('Location: student-profile.php?t=' . time() . '&msg=avatar_updated');
                        exit;
                    } else {
                        $updateMessage = 'File saved but DB update failed.';
                        $updateType    = 'error';
                    }
                } else {
                    $updateMessage = 'Failed to save file. Check folder permissions.';
                    $updateType    = 'error';
                }
            }
        }
    }
 
    if ($_POST['action'] === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
 
        if ($new !== $confirm) {
            $updateMessage = 'New passwords do not match.';
            $updateType    = 'error';
        } elseif (strlen($new) < 8) {
            $updateMessage = 'Password must be at least 8 characters.';
            $updateType    = 'error';
        } else {
            $pwResult = safePreparedQuery(
                $conn, "SELECT password_hash FROM users WHERE user_id = ?", "i", [$userId]
            );
            $pwRow = null;
            if ($pwResult['success'] && $pwResult['result']) {
                $pwRow = $pwResult['result']->fetch_assoc();
                $pwResult['result']->free();
            }
            if ($pwRow && password_verify($current, $pwRow['password_hash'])) {
                $newHash  = password_hash($new, PASSWORD_DEFAULT);
                $upResult = safePreparedQuery(
                    $conn, "UPDATE users SET password_hash = ? WHERE user_id = ?", "si", [$newHash, $userId]
                );
                if ($upResult['success']) {
                    $updateMessage = 'Password changed successfully.';
                    $updateType    = 'success';
                } else {
                    $updateMessage = 'Failed to change password.';
                    $updateType    = 'error';
                }
            } else {
                $updateMessage = 'Current password is incorrect.';
                $updateType    = 'error';
            }
        }
    }
}
 
// ── student_profiles table does not exist in the current schema ──
$profile  = [];
$spExists = false;
 
// ── Login activity (last 5 successful logins) ──
$loginResult = safePreparedQuery(
    $conn,
    "SELECT ip_address, user_agent, created_at
     FROM login_activity
     WHERE user_id = ? AND is_success = true
     ORDER BY created_at DESC LIMIT 5",
    "i", [$userId]
);
$loginHistory = [];
if ($loginResult['success'] && $loginResult['result']) {
    while ($row = $loginResult['result']->fetch_assoc()) {
        $loginHistory[] = $row;
    }
    $loginResult['result']->free();
}
 
// ── Helpers ──
function scoreColor(int $s): string {
    if ($s >= 80) return '#065f46';
    if ($s >= 60) return '#92400e';
    return '#991b1b';
}
function scoreBg(int $s): string {
    if ($s >= 80) return '#d1fae5';
    if ($s >= 60) return '#fef3c7';
    return '#fee2e2';
}
function scoreLabel(int $s): string {
    if ($s >= 80) return 'Excellent';
    if ($s >= 60) return 'Good';
    return 'Needs Work';
}
function timeAgo(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', strtotime($dt));
}
function difficultyBadge(string $d): string {
    $map = [
        'easy'   => 'background:#dcfce7;color:#166534',
        'medium' => 'background:#fef3c7;color:#92400e',
        'hard'   => 'background:#fee2e2;color:#991b1b',
    ];
    $style = $map[$d] ?? 'background:#f1f5f9;color:#475569';
    return "<span style=\"{$style};padding:3px 9px;border-radius:6px;font-size:11px;font-weight:700;font-family:'Sora',sans-serif;\">"
         . ucfirst($d) . "</span>";
}
function parseUA(string $ua): string {
    if (stripos($ua, 'Chrome')  !== false) return '🌐 Chrome';
    if (stripos($ua, 'Firefox') !== false) return '🦊 Firefox';
    if (stripos($ua, 'Safari')  !== false) return '🧭 Safari';
    if (stripos($ua, 'Edge')    !== false) return '🔷 Edge';
    return '🌐 Browser';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile – Student | Placement Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary:       #1a3a52;
            --primary-mid:   #234C6A;
            --accent:        #0ea5e9;
            --accent-glow:   rgba(14,165,233,.18);
            --accent2:       #06b6d4;
            --success:       #10b981;
            --warning:       #f59e0b;
            --danger:        #ef4444;
            --bg:            #f0f4f8;
            --surface:       #ffffff;
            --surface2:      #f8fafc;
            --border:        #e2e8f0;
            --text:          #0f172a;
            --text-mid:      #475569;
            --text-soft:     #94a3b8;
            --radius:        16px;
            --radius-sm:     10px;
            --shadow:        0 1px 3px rgba(0,0,0,.06), 0 4px 16px rgba(0,0,0,.06);
            --shadow-md:     0 4px 24px rgba(0,0,0,.10);
            --nav-h:         68px;
            --sidebar-w:     230px;
            --transition:    .2s cubic-bezier(.4,0,.2,1);
        }
 
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
 
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding-top: var(--nav-h);
            -webkit-font-smoothing: antialiased;
        }
 
        /* ══════════════════════════════
           NAVBAR
        ══════════════════════════════ */
        .navbar {
            background: var(--primary);
            padding: 0 28px;
            height: var(--nav-h);
            display: flex; align-items: center; justify-content: space-between;
            position: fixed; top: 0; left: 0; right: 0;
            z-index: 1000;
            box-shadow: 0 1px 0 rgba(255,255,255,.06), 0 4px 20px rgba(0,0,0,.18);
        }
        .navbar-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; flex-shrink: 0; }
 
        .nav-profile { display: flex; align-items: center; gap: 10px; }
 
        .notification-btn {
            position: relative; width: 38px; height: 38px;
            background: rgba(255,255,255,.12); border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; border: 1.5px solid rgba(255,255,255,.15);
            transition: var(--transition); color: white; font-size: 16px;
        }
        .notification-btn:hover { background: rgba(255,255,255,.2); border-color: rgba(255,255,255,.3); }
 
        .notif-dropdown-wrap { position: relative; }
        .notif-dropdown-menu {
            position: absolute; top: calc(100% + 12px); right: 0;
            background: var(--surface); border-radius: var(--radius);
            box-shadow: var(--shadow-md); border: 1px solid var(--border);
            width: 348px; opacity: 0; visibility: hidden;
            transform: translateY(-6px) scale(.98); transition: var(--transition); z-index: 1002;
        }
        .notif-dropdown-menu.show { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
        .notif-dd-header {
            padding: 16px 20px 14px;
            font-family: 'Sora', sans-serif; font-weight: 700; font-size: 14px; color: var(--text);
            border-bottom: 1px solid var(--border);
        }
        .notif-dd-list { max-height: 360px; overflow-y: auto; scrollbar-width: thin; scrollbar-color: var(--border) transparent; }
        .notif-dd-list::-webkit-scrollbar { width: 4px; }
        .notif-dd-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 10px; }
        .notif-dd-item {
            display: flex; gap: 12px; align-items: flex-start;
            padding: 13px 20px; border-bottom: 1px solid var(--border);
            cursor: pointer; transition: background var(--transition);
        }
        .notif-dd-item:last-child { border-bottom: none; }
        .notif-dd-item:hover { background: var(--surface2); }
        .notif-dd-item.unread { background: #eff8ff; }
        .notif-dd-item.unread:hover { background: #e0f2fe; }
        .notif-dd-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--accent); flex-shrink: 0; margin-top: 5px; }
        .notif-dd-dot.read { background: transparent; }
        .notif-dd-body { flex: 1; }
        .notif-dd-title { font-size: 13px; font-weight: 600; color: var(--text); margin-bottom: 2px; }
        .notif-dd-msg   { font-size: 12px; color: var(--text-mid); line-height: 1.45; }
        .notif-dd-time  { font-size: 11px; color: var(--text-soft); margin-top: 4px; }
        .notif-dd-empty { padding: 32px 20px; text-align: center; color: var(--text-soft); font-size: 13px; }
        .notif-dismiss-btn {
            background: none; border: none; color: var(--text-soft);
            font-size: 13px; line-height: 1; padding: 2px 5px;
            border-radius: 4px; cursor: pointer; flex-shrink: 0;
            opacity: 0; transition: opacity .15s, background .15s, color .15s;
            align-self: flex-start; margin-top: 2px;
        }
        .notif-dd-item:hover .notif-dismiss-btn { opacity: 1; }
        .notif-dismiss-btn:hover { background: rgba(239,68,68,.1); color: #ef4444; }
 
        .notif-badge {
            position: absolute; top: -4px; right: -4px;
            background: var(--danger); color: white;
            min-width: 18px; height: 18px; border-radius: 9px; padding: 0 4px;
            font-size: 10px; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            animation: badgePulse 2s ease-in-out infinite;
            border: 2px solid var(--primary);
        }
        @keyframes badgePulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(239,68,68,.5); }
            60%       { box-shadow: 0 0 0 5px rgba(239,68,68,0); }
        }
 
        .profile-button {
            display: flex; align-items: center; gap: 9px;
            padding: 6px 12px 6px 6px;
            background: rgba(255,255,255,.12);
            border: 1.5px solid rgba(255,255,255,.15);
            border-radius: 10px; cursor: pointer; transition: var(--transition);
            font-family: inherit;
        }
        .profile-button:hover { background: rgba(255,255,255,.2); border-color: rgba(255,255,255,.3); }
        .profile-avatar-sm {
            width: 32px; height: 32px;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 13px;
            font-family: 'Sora', sans-serif;
        }
        .profile-name-nav { font-weight: 600; font-size: 13.5px; color: rgba(255,255,255,.95); }
        .dropdown-arrow { font-size: 10px; color: rgba(255,255,255,.6); }
 
        .nav-profile-dropdown {
            position: absolute; top: calc(100% + 12px); right: 0;
            background: var(--surface); border-radius: var(--radius);
            box-shadow: var(--shadow-md); border: 1px solid var(--border);
            min-width: 240px; z-index: 1001; overflow: hidden;
            opacity: 0; visibility: hidden; transform: translateY(-6px) scale(.98);
            transition: var(--transition);
        }
        .nav-profile-dropdown.active { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
        .nav-dropdown-header {
            padding: 18px 20px;
            background: linear-gradient(135deg, var(--primary), var(--primary-mid));
            display: flex; gap: 12px; align-items: center;
        }
        .nav-dropdown-avatar {
            width: 44px; height: 44px;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 800; font-size: 18px;
            font-family: 'Sora', sans-serif; flex-shrink: 0;
        }
        .nav-dropdown-info { flex: 1; overflow: hidden; }
        .nav-dropdown-name  { font-family: 'Sora', sans-serif; font-weight: 700; font-size: 15px; color: white; margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .nav-dropdown-email { font-size: 12px; color: rgba(255,255,255,.65); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .nav-dropdown-menu { padding: 8px; }
        .nav-dropdown-item {
            display: flex; align-items: center; gap: 11px;
            padding: 10px 12px; border-radius: 8px;
            color: var(--text-mid); text-decoration: none;
            cursor: pointer; border: none; background: none;
            width: 100%; text-align: left; font-size: 13.5px;
            font-family: 'Inter', sans-serif; transition: var(--transition);
        }
        .nav-dropdown-item:hover { background: var(--surface2); color: var(--text); }
        .nav-dropdown-item i { width: 18px; text-align: center; font-size: 14px; color: var(--text-soft); }
        .nav-dropdown-divider { height: 1px; background: var(--border); margin: 6px 8px; }
        .nav-dropdown-item.danger { color: var(--danger); }
        .nav-dropdown-item.danger i { color: var(--danger); }
        .nav-dropdown-item.danger:hover { background: #fef2f2; }
 
        /* ══════════════════════════════
           PAGE LAYOUT
        ══════════════════════════════ */
        .page-wrapper { display: flex; min-height: calc(100vh - var(--nav-h)); }
 
        .left-sidebar {
            width: var(--sidebar-w); flex-shrink: 0;
            padding: 20px 12px;
            display: flex; flex-direction: column; gap: 2px;
            background: var(--surface);
            border-right: 1px solid var(--border);
            min-height: calc(100vh - var(--nav-h));
            position: sticky; top: var(--nav-h); align-self: flex-start;
        }
        .left-sidebar-label {
            font-size: 10.5px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .1em;
            color: var(--text-soft); padding: 14px 12px 7px;
        }
        .left-sidebar a {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 13px; border-radius: var(--radius-sm);
            text-decoration: none; font-size: 13.5px; font-weight: 500;
            color: var(--text-mid); transition: var(--transition);
            position: relative;
        }
        .left-sidebar a:hover { background: var(--surface2); color: var(--primary); }
        .left-sidebar a.active {
            background: linear-gradient(135deg, #e0f2fe, #e0f9ff);
            color: var(--accent); font-weight: 600;
        }
        .left-sidebar a.active::before {
            content: ''; position: absolute; left: 0; top: 20%; bottom: 20%;
            width: 3px; border-radius: 0 3px 3px 0; background: var(--accent);
        }
        .left-sidebar a i { width: 18px; text-align: center; font-size: 14px; flex-shrink: 0; }
        .left-sidebar-bottom { margin-top: auto; padding-top: 12px; border-top: 1px solid var(--border); }
        .left-sidebar-bottom button {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 13px; border-radius: var(--radius-sm);
            font-size: 13.5px; font-weight: 500;
            color: var(--danger); background: none; border: none;
            cursor: pointer; width: 100%; transition: var(--transition);
            font-family: 'Inter', sans-serif;
        }
        .left-sidebar-bottom button:hover { background: #fef2f2; }
        .left-sidebar-bottom button i { width: 18px; text-align: center; font-size: 14px; }
 
        .page-content { flex: 1; min-width: 0; padding: 28px 28px 40px 0; }
 
        @media (max-width: 900px) { .left-sidebar { display: none; } .page-content { padding: 20px; } }
 
        /* ══════════════════════════════
           PROFILE CONTENT GRID
        ══════════════════════════════ */
        .container {
            max-width: 100%; margin: 0;
            display: grid; grid-template-columns: 300px 1fr; gap: 24px;
        }
 
        /* ══════════════════════════════
           PROFILE CARD (left column)
        ══════════════════════════════ */
        .profile-card {
            background: var(--surface); border-radius: var(--radius);
            padding: 28px 24px; box-shadow: var(--shadow);
            border: 1px solid var(--border);
            text-align: center; height: fit-content;
            position: sticky; top: calc(var(--nav-h) + 16px);
        }
 
        .avatar-large {
            width: 110px; height: 110px; border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-mid));
            display: flex; align-items: center; justify-content: center;
            font-family: 'Sora', sans-serif;
            font-size: 36px; font-weight: 800; color: white;
            margin: 0 auto 16px;
            border: 4px solid var(--accent-glow);
            box-shadow: 0 0 0 6px rgba(14,165,233,.08);
        }
 
        .avatar-wrap {
            position: relative; width: 110px; margin: 0 auto 16px; cursor: pointer;
        }
        .avatar-wrap .avatar-large { margin: 0; }
        .avatar-pencil {
            position: absolute; bottom: 4px; right: 4px;
            width: 28px; height: 28px; border-radius: 50%;
            background: var(--accent); color: white;
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; border: 2px solid white;
            box-shadow: 0 2px 6px rgba(0,0,0,.2); transition: .2s; pointer-events: none;
        }
        .avatar-wrap:hover .avatar-pencil { background: var(--accent2); transform: scale(1.1); }
        .avatar-wrap:hover .avatar-large  { opacity: .85; }
        #avatarModal {
            display:none; position:fixed; inset:0; z-index:9100;
            background:rgba(0,0,0,.5); backdrop-filter:blur(4px);
            align-items:center; justify-content:center;
        }
        #avatarModal.open { display:flex; animation:fadeIn .2s ease; }
        #avatarModalBox {
            background:#fff; border-radius:20px; width:100%; max-width:380px;
            box-shadow:0 20px 60px rgba(0,0,0,.25); overflow:hidden;
            animation:slideUp .25s cubic-bezier(.4,0,.2,1);
        }
        .avatar-large { overflow:hidden; }
        .profile-card-name {
            font-family: 'Sora', sans-serif;
            font-size: 18px; font-weight: 800; color: var(--text); margin-bottom: 8px;
        }
 
        .role-badge {
            display: inline-block; padding: 4px 14px;
            background: linear-gradient(135deg, var(--primary), var(--primary-mid));
            color: white; border-radius: 20px;
            font-family: 'Sora', sans-serif;
            font-size: 11.5px; font-weight: 700; letter-spacing: 0.5px;
            margin-bottom: 16px;
        }
 
        .profile-card-detail {
            font-size: 13px; color: var(--text-mid); margin-bottom: 6px;
            display: flex; align-items: center; justify-content: center; gap: 6px;
        }
 
        .profile-divider { height: 1px; background: var(--border); margin: 18px 0; }
 
        .stat-row { display: flex; justify-content: space-around; }
        .stat-item { text-align: center; }
        .stat-item-value {
            font-family: 'Sora', sans-serif;
            font-size: 22px; font-weight: 800; color: var(--accent);
        }
        .stat-item-label { font-size: 11px; color: var(--text-soft); text-transform: uppercase; letter-spacing: 0.5px; }
 
        .cat-bar  { height: 7px; background: var(--border); border-radius: 9px; overflow: hidden; }
        .cat-fill { height: 100%; border-radius: 9px; background: linear-gradient(90deg, var(--accent), var(--accent2)); transition: width .6s ease; }
 
        /* Profile inner nav */
        .profile-nav { margin-top: 18px; display: flex; flex-direction: column; gap: 4px; }
 
        .profile-nav-item {
            display: flex; align-items: center; gap: 10px;
            padding: 11px 14px; border-radius: var(--radius-sm);
            font-size: 13.5px; font-weight: 500; color: var(--text-mid);
            cursor: pointer; transition: var(--transition);
            border: none; background: transparent;
            width: 100%; text-align: left; font-family: 'Inter', sans-serif;
        }
        .profile-nav-item:hover { background: var(--surface2); color: var(--text); }
        .profile-nav-item.active {
            background: linear-gradient(135deg, #e0f2fe, #e0f9ff);
            color: var(--accent); font-weight: 600;
        }
 
        /* ══════════════════════════════
           RIGHT COLUMN — PANELS
        ══════════════════════════════ */
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }
 
        .card {
            background: var(--surface); border-radius: var(--radius);
            padding: 26px; box-shadow: var(--shadow);
            border: 1px solid var(--border);
            margin-bottom: 22px;
        }
 
        .card-title {
            font-family: 'Sora', sans-serif;
            font-size: 17px; font-weight: 700; color: var(--text);
            margin-bottom: 20px; padding-bottom: 14px;
            border-bottom: 1.5px solid var(--border);
            display: flex; align-items: center; gap: 10px;
        }
 
        /* Forms */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group.full-width { grid-column: 1 / -1; }
 
        .form-label {
            font-size: 11.5px; font-weight: 700; color: var(--text-soft);
            text-transform: uppercase; letter-spacing: 0.06em;
        }
        .form-label .req { color: var(--danger); margin-left: 2px; }
 
        .form-control {
            padding: 10px 14px; border: 1.5px solid var(--border); border-radius: var(--radius-sm);
            font-size: 14px; font-family: 'Inter', sans-serif; color: var(--text);
            background: var(--surface); transition: var(--transition); width: 100%;
        }
        .form-control:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }
        .form-control[disabled], .form-control[readonly] { background: var(--surface2); color: var(--text-soft); cursor: not-allowed; }
        textarea.form-control { resize: vertical; min-height: 88px; line-height: 1.5; }
        .form-hint { font-size: 11px; color: var(--text-soft); }
 
        .form-actions {
            display: flex; align-items: center; justify-content: space-between;
            margin-top: 24px; padding-top: 18px;
            border-top: 1px solid var(--border);
        }
        .form-actions-right { display: flex; gap: 12px; }
 
        .btn {
            padding: 10px 24px; border-radius: var(--radius-sm);
            font-size: 13.5px; font-weight: 700;
            cursor: pointer; transition: var(--transition); border: none;
            display: inline-flex; align-items: center; gap: 8px;
            font-family: 'Inter', sans-serif;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            color: white; box-shadow: 0 2px 8px rgba(14,165,233,.3);
        }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(14,165,233,.45); }
        .btn-secondary {
            background: var(--surface2); color: var(--text-mid);
            border: 1.5px solid var(--border);
        }
        .btn-secondary:hover { background: var(--border); color: var(--text); }
 
        /* Alert banners */
        .alert {
            padding: 14px 18px; border-radius: var(--radius-sm);
            font-size: 13.5px; font-weight: 500; margin-bottom: 20px;
            display: flex; align-items: center; gap: 10px;
        }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error   { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
 
        /* Info boxes */
        .info-grid { display: grid; grid-template-columns: repeat(2,1fr); gap: 10px; }
        .info-box {
            background: var(--surface2); border-radius: var(--radius-sm);
            padding: 14px; border: 1px solid var(--border);
        }
        .info-box-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--text-soft); margin-bottom: 4px; }
        .info-box-value { font-size: 15px; font-weight: 700; color: var(--primary-mid); }
        .info-box-value.mono { font-family: 'Courier New', monospace; font-size: 13px; }
 
        /* History table */
        .history-table { width: 100%; border-collapse: collapse; }
        .history-table th {
            text-align: left; padding: 9px 13px;
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .06em; color: var(--text-soft);
            background: var(--surface2); border-bottom: 1px solid var(--border);
        }
        .history-table th:first-child { border-radius: 8px 0 0 8px; }
        .history-table th:last-child  { border-radius: 0 8px 8px 0; }
        .history-table td {
            padding: 12px 13px; font-size: 13px;
            border-bottom: 1px solid var(--border); vertical-align: middle;
        }
        .history-table tr:last-child td { border-bottom: none; }
        .history-table tr:hover td { background: var(--surface2); }
        .score-pill { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; }
        .test-name  { font-weight: 600; color: var(--text); margin-bottom: 2px; font-family: 'Sora', sans-serif; font-size: 13.5px; }
        .test-cat   { font-size: 11px; color: var(--text-soft); }
 
        /* Password */
        .pw-wrap { position: relative; }
        .pw-wrap .form-control { padding-right: 42px; }
        .pw-eye {
            position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            background: none; border: none; cursor: pointer;
            font-size: 16px; color: var(--text-soft); padding: 0;
        }
        .pw-eye:hover { color: var(--text); }
 
        .password-strength { margin-top: 6px; height: 5px; border-radius: 3px; background: var(--border); overflow: hidden; }
        .password-strength-bar { height: 100%; border-radius: 3px; transition: var(--transition); width: 0%; }
        .strength-weak   { width: 25%;  background: var(--danger); }
        .strength-fair   { width: 50%;  background: var(--warning); }
        .strength-good   { width: 75%;  background: #eab308; }
        .strength-strong { width: 100%; background: var(--success); }
        .strength-label  { font-size: 12px; font-weight: 600; margin-top: 4px; }
        .match-msg       { font-size: 12px; font-weight: 600; margin-top: 5px; min-height: 16px; }
 
        /* Login history */
        .login-row {
            display: flex; align-items: center; justify-content: space-between;
            padding: 12px 14px; border-radius: var(--radius-sm);
            background: var(--surface2); border: 1px solid var(--border);
            margin-bottom: 8px;
        }
        .login-row:last-child { margin-bottom: 0; }
        .login-info   { display: flex; align-items: center; gap: 10px; }
        .login-icon   { font-size: 18px; }
        .login-detail { font-size: 13px; font-weight: 600; color: var(--text); }
        .login-ip     { font-size: 11px; color: var(--text-soft); font-family: monospace; }
        .login-time   { font-size: 12px; color: var(--text-soft); }
 
        /* Empty state */
        .empty-state { text-align: center; padding: 44px 20px; color: var(--text-soft); }
        .empty-icon  { font-size: 38px; margin-bottom: 10px; }
        .empty-title { font-family: 'Sora', sans-serif; font-size: 15px; font-weight: 700; margin-bottom: 5px; color: var(--text); }
        .empty-sub   { font-size: 13px; }
 
        .divider { height: 1px; background: var(--border); margin: 22px 0; }
 
        /* ── RESPONSIVE ── */
        @media (max-width: 900px) {
            .container { grid-template-columns: 1fr; }
            .profile-card { position: static; }
            .profile-nav { flex-direction: row; flex-wrap: wrap; }
            .profile-nav-item { flex: 1; min-width: 120px; justify-content: center; }
        }
        @media (max-width: 600px) {
            .form-grid { grid-template-columns: 1fr; }
            .info-grid { grid-template-columns: 1fr; }
            .navbar { padding: 0 16px; }
            .profile-name-nav { display: none; }
        }
 
        /* Page load animation */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .profile-card { animation: fadeUp .4s ease both; }
        .tab-panel.active { animation: fadeUp .35s ease both; }
    
                </style>
</head>
<body>
 
<!-- NAVBAR -->
<nav class="navbar">
    <a href="student-dashboard.php" class="navbar-brand">
        <img src="prepaura-logo.png" alt="Prepaura Logo" style="width:44px;height:44px;border-radius:10px;object-fit:contain;background:white;padding:3px;">
        <div style="display:flex;flex-direction:column;line-height:1.15;">
            <span style="font-family:'Sora',sans-serif;font-size:17px;font-weight:800;letter-spacing:.5px;color:white;">PREPAURA</span>
            <span style="font-size:10.5px;font-weight:400;color:rgba(255,255,255,.65);letter-spacing:.02em;">Placement Training Platform</span>
        </div>
    </a>
 
    <div class="nav-profile">
        <!-- Notification bell -->
        <div class="notif-dropdown-wrap">
            <button class="notification-btn" onclick="toggleNotifDropdown()" title="Notifications" id="notifBtn">
                <span>🔔</span>
                <?php if ($unreadCount > 0): ?>
                <div class="notif-badge" id="notifBadge"><?= $unreadCount ?></div>
                <?php endif; ?>
            </button>
            <div class="notif-dropdown-menu" id="notifDropdown">
                <div class="notif-dd-header">Notifications</div>
                <div class="notif-dd-list">
                    <?php if (empty($notifItems)): ?>
                        <div class="notif-dd-empty">No notifications yet.</div>
                    <?php else: foreach ($notifItems as $n):
                        $isU = !pgBoolGuard($n['is_read']);
                        $typeIcons = ['info'=>'ℹ️','success'=>'✅','warning'=>'⚠️','error'=>'❌','assessment'=>'📝','result'=>'🏆','material'=>'📚'];
                        $ico = $typeIcons[$n['type']] ?? '🔔';
                    ?>
                    <div class="notif-dd-item <?= $isU ? 'unread' : '' ?>" id="notif-<?= (int)$n['notification_id'] ?>">
                        <div class="notif-dd-dot <?= $isU ? '' : 'read' ?>"></div>
                        <div class="notif-dd-body">
                            <div class="notif-dd-title"><?= $ico ?> <?= htmlspecialchars($n['title']) ?></div>
                            <?php if ($n['message']): ?>
                            <div class="notif-dd-msg"><?= htmlspecialchars($n['message']) ?></div>
                            <?php endif; ?>
                            <div class="notif-dd-time"><?= timeAgoProfile($n['created_at']) ?></div>
                        </div>
                        <button class="notif-dismiss-btn" onclick="event.stopPropagation(); dismissNotification(<?= (int)$n['notification_id'] ?>)" title="Dismiss">✕</button>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
 
        <!-- Profile dropdown -->
        <div style="position:relative" id="profileWrapper">
            <button class="profile-button" onclick="toggleProfileDropdown()" aria-expanded="false" aria-haspopup="true">
                <?php $navImg = $user['profile_image'] ?? ''; ?>
                <?php if ($navImg && file_exists($navImg)): ?>
                    <img src="<?= htmlspecialchars($navImg) ?>?v=<?= time() ?>" alt="Avatar" style="width:32px;height:32px;border-radius:8px;object-fit:cover;flex-shrink:0;">
                <?php else: ?>
                    <div class="profile-avatar-sm"><?= htmlspecialchars($userInitials) ?></div>
                <?php endif; ?>
                <span class="profile-name-nav"><?= htmlspecialchars($userName) ?></span>
                <span class="dropdown-arrow">▼</span>
            </button>
 
            <div class="nav-profile-dropdown" id="profileDropdown">
                <div class="nav-dropdown-header">
                    <div class="nav-dropdown-avatar">
                        <?php if ($navImg && file_exists($navImg)): ?>
                            <img src="<?= htmlspecialchars($navImg) ?>?v=<?= time() ?>" alt="Avatar" style="width:44px;height:44px;border-radius:12px;object-fit:cover;">
                        <?php else: ?>
                            <?= htmlspecialchars($userInitials) ?>
                        <?php endif; ?>
                    </div>
                    <div class="nav-dropdown-info">
                        <div class="nav-dropdown-name"><?= htmlspecialchars($userName) ?></div>
                        <div class="nav-dropdown-email"><?= htmlspecialchars($userEmail) ?></div>
                    </div>
                </div>
                <div class="nav-dropdown-menu">
                    <a href="student-dashboard.php" class="nav-dropdown-item">
                        <i class="fa fa-home"></i><span>Dashboard</span>
                    </a>
                    <a href="help.html" class="dropdown-item" style="display:flex;align-items:center;gap:11px;padding:10px 12px;border-radius:8px;font-size:13.5px;color:#475569;font-family:'Inter',sans-serif;text-decoration:none;transition:.15s;"><span class="dropdown-item-icon">🚩</span><span>Help &amp; Support</span></a>
                    <div class="nav-dropdown-divider"></div>
                    <button onclick="handleLogout()" class="nav-dropdown-item danger">
                        <i class="fa fa-sign-out-alt"></i><span>Logout</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</nav>
 
<div class="page-wrapper">
 
<!-- LEFT SIDEBAR -->
<aside class="left-sidebar">
    <span class="left-sidebar-label">Navigation</span>
    <a href="student-dashboard.php"><i class="fa fa-home"></i> Dashboard</a>
    <a href="student-assessments.php"><i class="fa fa-clipboard-list"></i> Assessments</a>
    <a href="self-assessment.php"><i class="fa fa-user-check"></i> Self Assessment</a>
    <a href="student-resources.php"><i class="fa fa-folder-open"></i> Resources</a>
    <div class="left-sidebar-bottom">
        <button onclick="handleLogout()"><i class="fa fa-sign-out-alt"></i> Logout</button>
    </div>
</aside>
 
<div class="page-content">
<div class="container">
 
    <!-- ── LEFT: Profile Card ── -->
    <aside>
        <div class="profile-card">
 
            <?php $profileImg = $user['profile_image'] ?? ''; ?>
            <div class="avatar-wrap" onclick="openAvatarModal()" title="Change profile picture">
                <?php if ($profileImg && file_exists(__DIR__ . '/' . $profileImg)): ?>
                    <img src="<?= htmlspecialchars($profileImg) ?>?v=<?= time() ?>"
                         class="avatar-large" alt="Profile"
                         style="width:110px;height:110px;border-radius:50%;object-fit:cover;border:4px solid var(--accent-glow);box-shadow:0 0 0 6px rgba(14,165,233,.08);display:block;">
                <?php else: ?>
                    <div class="avatar-large"><?= htmlspecialchars($userInitials) ?></div>
                <?php endif; ?>
                <div class="avatar-pencil">✏️</div>
            </div>
 
            <div class="profile-card-name"><?= htmlspecialchars($userName) ?></div>
            <div class="role-badge">🎓 Student</div>
 
            <?php if ($userDept): ?>
                <div class="profile-card-detail">🏛️ <?= htmlspecialchars($userDept) ?></div>
            <?php endif; ?>
            <?php if ($userRegNo): ?>
                <div class="profile-card-detail">🆔 <?= htmlspecialchars($userRegNo) ?></div>
            <?php endif; ?>
 
            <div class="profile-card-detail">✉️ <?= htmlspecialchars($userEmail) ?></div>
 
            <?php if ($lastLogin): ?>
                <div class="profile-card-detail">🕐 Last login <?= timeAgo($lastLogin) ?></div>
            <?php endif; ?>
 
            <div class="profile-card-detail">📅 Member since <?= htmlspecialchars($memberSince) ?></div>
 
            <div class="profile-divider"></div>
 
            <!-- Live stats -->
            <div class="stat-row">
                <div class="stat-item">
                    <div class="stat-item-value"><?= $testsCompleted ?></div>
                    <div class="stat-item-label">Tests</div>
                </div>
                <div class="stat-item">
                    <div class="stat-item-value"><?= $avgScore ?>%</div>
                    <div class="stat-item-label">Avg</div>
                </div>
                <div class="stat-item">
                    <div class="stat-item-value"><?= $bestScore ?>%</div>
                    <div class="stat-item-label">Best</div>
                </div>
            </div>
 
            <?php if (!empty($categories)): ?>
            <div class="profile-divider"></div>
            <div style="text-align:left;">
                <div style="font-size:11px;font-weight:700;color:var(--text-soft);text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px;">Performance by Category</div>
                <div style="display:flex;flex-direction:column;gap:10px;">
                    <?php foreach ($categories as $cat):
                        $cs = (int)round($cat['avg_score']);
                    ?>
                    <div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                            <span style="font-size:12px;font-weight:600;color:var(--text);"><?= htmlspecialchars(ucfirst($cat['category'])) ?></span>
                            <span style="font-size:11px;color:var(--text-soft);"><?= $cat['attempts'] ?> · <?= $cs ?>%</span>
                        </div>
                        <div class="cat-bar"><div class="cat-fill" style="width:<?= $cs ?>%"></div></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
 
            <?php if ($testsCompleted > 0):
                $total   = $totalCorrect + $totalWrong;
                $accRate = $total > 0 ? round(($totalCorrect / $total) * 100) : 0;
            ?>
            <div class="profile-divider"></div>
            <div style="text-align:left;">
                <div style="font-size:11px;font-weight:700;color:var(--text-soft);text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px;">Answer Accuracy</div>
                <div style="display:flex;flex-direction:column;gap:8px;">
                    <div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                            <span style="font-size:12px;font-weight:700;color:#065f46;">Correct</span>
                            <span style="font-size:12px;font-weight:700;color:#065f46;"><?= $totalCorrect ?></span>
                        </div>
                        <div class="cat-bar"><div class="cat-fill" style="width:<?= $accRate ?>%;background:linear-gradient(90deg,#10b981,#34d399);"></div></div>
                    </div>
                    <div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                            <span style="font-size:12px;font-weight:700;color:#991b1b;">Wrong</span>
                            <span style="font-size:12px;font-weight:700;color:#991b1b;"><?= $totalWrong ?></span>
                        </div>
                        <div class="cat-bar"><div class="cat-fill" style="width:<?= (100 - $accRate) ?>%;background:linear-gradient(90deg,#ef4444,#f87171);"></div></div>
                    </div>
                    <div style="text-align:center;padding-top:6px;">
                        <span style="font-family:'Sora',sans-serif;font-size:22px;font-weight:800;color:var(--accent);"><?= $accRate ?>%</span>
                        <div style="font-size:10px;color:var(--text-soft);font-weight:700;text-transform:uppercase;letter-spacing:.06em;">accuracy rate</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
 
            <div class="profile-divider"></div>
 
            <!-- Inner tab nav -->
            <nav class="profile-nav">
                <button class="profile-nav-item active" onclick="switchTab('info')" id="nav-info">
                    <span>👤</span> Personal Info
                </button>
                <button class="profile-nav-item" onclick="switchTab('history')" id="nav-history">
                    <span>📋</span> Test History
                </button>
                <button class="profile-nav-item" onclick="switchTab('security')" id="nav-security">
                    <span>🔒</span> Security
                </button>
            </nav>
        </div>
    </aside>
 
    <!-- ── RIGHT: Tab Panels ── -->
    <main>
 
        <?php if ($updateMessage): ?>
            <div class="alert alert-<?= $updateType ?>">
                <?= $updateType === 'success' ? '✅' : '⚠️' ?>
                <?= htmlspecialchars($updateMessage) ?>
            </div>
        <?php endif; ?>
 
        <!-- ══ TAB: Personal Info ══ -->
        <div class="tab-panel active" id="panel-info">
            <div class="card">
                <div class="card-title">👤 Personal Information</div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_profile">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <div class="form-grid">
 
                    <div class="form-group">
                        <label class="form-label">Full Name <span class="req">*</span></label>
                        <input type="text" name="full_name" class="form-control"
                               value="<?= htmlspecialchars($userName) ?>" required maxlength="100">
                    </div>
 
                    <div class="form-group">
                        <label class="form-label">Email Address <span class="req">*</span></label>
                        <input type="email" name="email" class="form-control" id="profileEmail"
                               value="<?= htmlspecialchars($userEmail) ?>" required maxlength="100"
                               oninput="checkEmailChange(this.value)">
                        <span class="form-hint">Changing email requires password confirmation.</span>
                    </div>
 
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <input type="text" name="department" class="form-control"
                               value="<?= htmlspecialchars($userDept ?: '') ?>" maxlength="50">
                    </div>
 
                    <div class="form-group">
                        <label class="form-label">Registration Number</label>
                        <input type="text" name="registration_number" class="form-control"
                               value="<?= htmlspecialchars($userRegNo ?: '') ?>" maxlength="50">
                    </div>
 
                    <div class="form-group full-width" id="emailConfirmWrap" style="display:none;">
                        <label class="form-label">Current Password <span class="req">*</span> <span style="font-weight:400;color:var(--text-soft);font-size:11px;">(required to change email)</span></label>
                        <div class="pw-wrap">
                            <input type="password" name="confirm_password_profile" class="form-control"
                                   id="profilePwConfirm" placeholder="Enter your current password" autocomplete="current-password">
                            <button type="button" class="pw-eye" onclick="togglePw('profilePwConfirm',this)">👁️</button>
                        </div>
                    </div>
 
                </div>
                <div class="form-actions">
                    <div class="form-actions-right">
                        <button type="submit" class="btn btn-primary">💾 Save Changes</button>
                    </div>
                </div>
                </form>
            </div>
 
            <!-- Account Details -->
            <div class="card">
                <div class="card-title">🔧 Account Details</div>
                <div class="info-grid">
                    <div class="info-box">
                        <div class="info-box-label">User ID</div>
                        <div class="info-box-value mono">#<?= $userId ?></div>
                    </div>
                    <div class="info-box">
                        <div class="info-box-label">Role</div>
                        <div class="info-box-value">Student</div>
                    </div>
                    <div class="info-box">
                        <div class="info-box-label">Account Status</div>
                        <div class="info-box-value" style="color:<?= $user['is_active'] ? '#065f46' : '#991b1b' ?>;">
                            <?= $user['is_active'] ? '✅ Active' : '❌ Inactive' ?>
                        </div>
                    </div>
                    <div class="info-box">
                        <div class="info-box-label">Verified</div>
                        <div class="info-box-value" style="color:<?= $user['is_verified'] ? '#065f46' : '#991b1b' ?>;">
                            <?= $user['is_verified'] ? '✅ Yes' : '❌ No' ?>
                        </div>
                    </div>
                    <?php if (!empty($user['created_at'])): ?>
                    <div class="info-box" style="grid-column:1/-1;">
                        <div class="info-box-label">Member Since</div>
                        <div class="info-box-value" style="font-size:14px;">
                            <?= date('F j, Y', strtotime($user['created_at'])) ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
 
        <!-- ══ TAB: Test History ══ -->
        <div class="tab-panel" id="panel-history">
            <div class="card">
                <div class="card-title">📋 Test History</div>
                <?php if (empty($recentAttempts)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📝</div>
                    <div class="empty-title">No tests completed yet</div>
                    <div class="empty-sub">Complete your first assessment to see history here.</div>
                </div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Assessment</th>
                                <th>Score</th>
                                <th>Breakdown</th>
                                <th>Duration</th>
                                <th>Submitted</th>
                                <th>Result</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentAttempts as $a):
                                $pct    = (int)round($a['percentage'] ?? 0);
                                $mins   = $a['time_taken_minutes'] ?? 0;
                                $scored = number_format($a['score'] ?? 0, 1);
                                $total  = $a['total_marks'] ?? '—';
                            ?>
                            <tr>
                                <td>
                                    <div class="test-name"><?= htmlspecialchars($a['test_title']) ?></div>
                                    <div class="test-cat">
                                        <?= htmlspecialchars(ucfirst($a['category'] ?? '')) ?>
                                        &nbsp;<?= difficultyBadge($a['difficulty'] ?? 'medium') ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="score-pill" style="background:<?= scoreBg($pct) ?>;color:<?= scoreColor($pct) ?>">
                                        <?= $pct ?>%
                                    </span>
                                    <div style="font-size:11px;color:var(--text-soft);margin-top:3px;">
                                        <?= $scored ?> / <?= $total ?> marks
                                    </div>
                                </td>
                                <td style="font-size:12px;">
                                    <span style="color:#065f46;font-weight:700;"><?= $scored ?></span>&nbsp;/&nbsp;
                                    <span style="color:var(--text-soft);"><?= $total ?> marks</span>
                                </td>
                                <td style="font-size:12px;color:var(--text-soft);">
                                    <?= $mins ? $mins . ' min' : '—' ?>
                                </td>
                                <td style="font-size:12px;color:var(--text-soft);">
                                    <?= $a['submitted_at'] ? timeAgo($a['submitted_at']) : '—' ?>
                                </td>
                                <td style="font-size:12px;font-weight:700;color:<?= scoreColor($pct) ?>">
                                    <?= scoreLabel($pct) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div style="text-align:center;margin-top:16px;">
                    <a href="all-results.php" style="color:var(--accent);font-size:13px;font-weight:700;text-decoration:none;">
                        View full history →
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
 
        <!-- ══ TAB: Security ══ -->
        <div class="tab-panel" id="panel-security">
 
            <div class="card">
                <div class="card-title">🔒 Change Password</div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="change_password">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <div class="form-grid">
 
                        <div class="form-group full-width">
                            <label class="form-label">Current Password <span class="req">*</span></label>
                            <div class="pw-wrap">
                                <input type="password" name="current_password" class="form-control"
                                       id="pwCurrent" placeholder="Enter current password"
                                       autocomplete="current-password" required>
                                <button type="button" class="pw-eye" onclick="togglePw('pwCurrent',this)">👁️</button>
                            </div>
                        </div>
 
                        <div class="form-group">
                            <label class="form-label">New Password <span class="req">*</span></label>
                            <div class="pw-wrap">
                                <input type="password" name="new_password" class="form-control"
                                       id="pwNew" placeholder="Minimum 8 characters"
                                       autocomplete="new-password" required
                                       oninput="checkStrength(this.value)">
                                <button type="button" class="pw-eye" onclick="togglePw('pwNew',this)">👁️</button>
                            </div>
                            <div class="password-strength">
                                <div class="password-strength-bar" id="strengthBar"></div>
                            </div>
                            <span class="strength-label" id="strengthLabel" style="color:var(--text-soft);"></span>
                        </div>
 
                        <div class="form-group">
                            <label class="form-label">Confirm New Password <span class="req">*</span></label>
                            <div class="pw-wrap">
                                <input type="password" name="confirm_password" class="form-control"
                                       id="pwConfirm" placeholder="Repeat new password"
                                       autocomplete="new-password" required
                                       oninput="checkMatch()">
                                <button type="button" class="pw-eye" onclick="togglePw('pwConfirm',this)">👁️</button>
                            </div>
                            <div class="match-msg" id="matchMsg"></div>
                        </div>
 
                    </div>
                    <div class="form-actions">
                        <span style="font-size:12px;color:var(--text-soft);">Use letters, numbers, and symbols.</span>
                        <button type="submit" class="btn btn-primary"><span>🔑</span> Update Password</button>
                    </div>
                </form>
            </div>
 
            <div class="card">
                <div class="card-title">ℹ️ Account Information</div>
                <div class="info-grid" style="margin-bottom:<?= !empty($loginHistory) ? '22px' : '0' ?>;">
                    <div class="info-box">
                        <div class="info-box-label">User ID</div>
                        <div class="info-box-value mono">#<?= $userId ?></div>
                    </div>
                    <div class="info-box">
                        <div class="info-box-label">Role</div>
                        <div class="info-box-value">Student</div>
                    </div>
                    <div class="info-box">
                        <div class="info-box-label">Account Status</div>
                        <div class="info-box-value" style="color:<?= $user['is_active'] ? '#065f46' : '#991b1b' ?>;">
                            <?= $user['is_active'] ? '✅ Active' : '❌ Inactive' ?>
                        </div>
                    </div>
                    <div class="info-box">
                        <div class="info-box-label">Verified</div>
                        <div class="info-box-value" style="color:<?= $user['is_verified'] ? '#065f46' : '#991b1b' ?>;">
                            <?= $user['is_verified'] ? '✅ Yes' : '❌ No' ?>
                        </div>
                    </div>
                    <?php if (!empty($user['created_at'])): ?>
                    <div class="info-box" style="grid-column:1/-1;">
                        <div class="info-box-label">Member Since</div>
                        <div class="info-box-value" style="font-size:14px;">
                            <?= date('F j, Y', strtotime($user['created_at'])) ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
 
                <?php if (!empty($loginHistory)): ?>
                <div class="divider"></div>
                <div class="card-title" style="margin-bottom:14px;">🔐 Recent Login Activity</div>
                <?php foreach ($loginHistory as $ll): ?>
                <div class="login-row">
                    <div class="login-info">
                        <span class="login-icon"><?= parseUA($ll['user_agent'] ?? '') ?></span>
                        <div>
                            <div class="login-detail"><?= parseUA($ll['user_agent'] ?? '') ?></div>
                            <div class="login-ip"><?= htmlspecialchars($ll['ip_address'] ?? '—') ?></div>
                        </div>
                    </div>
                    <div class="login-time"><?= timeAgo($ll['created_at']) ?></div>
                </div>
                <?php endforeach; ?>
                <div style="margin-top:8px;font-size:11px;color:var(--text-soft);text-align:right;">
                    Showing last <?= count($loginHistory) ?> successful logins
                </div>
                <?php endif; ?>
            </div>
 
        </div><!-- /panel-security -->
 
    </main>
</div><!-- /.container -->
</div><!-- /.page-content -->
</div><!-- /.page-wrapper -->
 
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
 
    /* ── Dropdowns ── */
    const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;
 
    function toggleProfileDropdown() {
        const dropdown = document.getElementById('profileDropdown');
        document.getElementById('notifDropdown').classList.remove('show');
        dropdown.classList.toggle('active');
        document.querySelector('.profile-button').setAttribute('aria-expanded', dropdown.classList.contains('active'));
    }
 
    function toggleNotifDropdown() {
        const dd = document.getElementById('notifDropdown');
        document.getElementById('profileDropdown').classList.remove('active');
        dd.classList.toggle('show');
        if (dd.classList.contains('show')) {
            fetch('api/notifications/mark-read.php', {
                method: 'POST',
                headers: { 'X-CSRF-Token': CSRF_TOKEN, 'Content-Type': 'application/json' }
            }).then(() => {
                const badge = document.getElementById('notifBadge');
                if (badge) badge.remove();
                document.querySelectorAll('.notif-dd-item.unread').forEach(el => el.classList.remove('unread'));
                document.querySelectorAll('.notif-dd-dot:not(.read)').forEach(el => el.classList.add('read'));
            }).catch(() => {});
        }
    }
 
    // Dismiss a single notification by ID (X button)
    async function dismissNotification(notifId) {
        const el = document.getElementById('notif-' + notifId);
        if (el) { el.style.opacity = '0.4'; el.style.pointerEvents = 'none'; }
        try {
            const res = await fetch('api/notifications/dismiss-notification.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                body: JSON.stringify({ action: 'dismiss_one', notification_id: notifId })
            });
            const data = await res.json();
            if (!data.success) {
                if (el) { el.style.opacity = '1'; el.style.pointerEvents = ''; }
                return;
            }
        } catch(e) {
            if (el) { el.style.opacity = '1'; el.style.pointerEvents = ''; }
            return;
        }
        if (el) el.remove();
        const badge = document.getElementById('notifBadge');
        if (badge) {
            const cur = parseInt(badge.textContent) || 0;
            if (cur <= 1) badge.remove();
            else badge.textContent = cur - 1;
        }
        const list = document.querySelector('.notif-dd-list');
        if (list && list.querySelectorAll('.notif-dd-item').length === 0) {
            list.innerHTML = '<div class="notif-dd-empty">No notifications yet.</div>';
        }
    }
 
    document.addEventListener('click', function(e) {
        const nw = document.querySelector('.notif-dropdown-wrap');
        if (pw && !pw.contains(e.target)) {
            document.getElementById('profileDropdown').classList.remove('active');
        }
        if (nw && !nw.contains(e.target)) {
            document.getElementById('notifDropdown').classList.remove('show');
        }
    });
 
    const ORIGINAL_EMAIL = <?= json_encode($userEmail) ?>;
    function checkEmailChange(val) {
        const wrap = document.getElementById('emailConfirmWrap');
        const inp  = document.getElementById('profilePwConfirm');
        if (val.trim() !== ORIGINAL_EMAIL) {
            wrap.style.display = 'block';
            inp.required = true;
        } else {
            wrap.style.display = 'none';
            inp.required = false;
            inp.value = '';
        }
    }
 
    function handleLogout() {
        if (confirm('Are you sure you want to logout?')) window.location.href = 'logout.php';
    }
 
    /* ── Password eye toggle ── */
    function togglePw(id, btn) {
        const inp = document.getElementById(id);
        if (inp.type === 'password') { inp.type = 'text';     btn.textContent = '🙈'; }
        else                          { inp.type = 'password'; btn.textContent = '👁️'; }
    }
 
    /* ── Password strength meter ── */
    function checkStrength(val) {
        const bar   = document.getElementById('strengthBar');
        const label = document.getElementById('strengthLabel');
        if (!val) { bar.className = 'password-strength-bar'; label.textContent = ''; return; }
        let score = 0;
        if (val.length >= 8)           score++;
        if (/[A-Z]/.test(val))         score++;
        if (/[0-9]/.test(val))         score++;
        if (/[^A-Za-z0-9]/.test(val))  score++;
        const levels = [
            { cls: 'strength-weak',   label: 'Weak',   color: '#ef4444' },
            { cls: 'strength-fair',   label: 'Fair',   color: '#f59e0b' },
            { cls: 'strength-good',   label: 'Good',   color: '#eab308' },
            { cls: 'strength-strong', label: 'Strong', color: '#10b981' },
        ];
        const lvl         = levels[Math.max(score - 1, 0)];
        bar.className     = 'password-strength-bar ' + lvl.cls;
        label.textContent = lvl.label;
        label.style.color = lvl.color;
    }
 
    /* ── Password match check ── */
    function checkMatch() {
        const nw  = document.getElementById('pwNew').value;
        const cf  = document.getElementById('pwConfirm').value;
        const msg = document.getElementById('matchMsg');
        if (!cf) { msg.textContent = ''; return; }
        if (nw === cf) { msg.style.color = '#065f46'; msg.textContent = '✓ Passwords match'; }
        else           { msg.style.color = '#991b1b'; msg.textContent = '✗ Passwords do not match'; }
    }
 
    /* ── Animate category bars on load ── */
    window.addEventListener('load', () => {
        document.querySelectorAll('.cat-fill').forEach(bar => {
            const w = bar.style.width;
            bar.style.width = '0';
            setTimeout(() => { bar.style.width = w; }, 250);
        });
    });
 
    /* ── Auto-open tab from URL hash ── */
    window.addEventListener('DOMContentLoaded', function() {
        const hash = window.location.hash.replace('#', '');
        const validTabs = ['info', 'history', 'security'];
        if (validTabs.includes(hash)) switchTab(hash);
    });
</script>
 
<!-- AVATAR MODAL -->
<div id="avatarModal" onclick="if(event.target===this)closeAvatarModal()">
    <div id="avatarModalBox">
        <div style="background:linear-gradient(135deg,#1a3a52,#1e5276);padding:20px 24px;display:flex;align-items:center;justify-content:space-between;">
            <div>
                <div style="font-family:'Sora',sans-serif;font-size:16px;font-weight:800;color:#fff;">Change Profile Picture</div>
                <div style="font-size:12px;color:rgba(255,255,255,.6);margin-top:2px;">JPG, PNG, GIF or WEBP · Max 2MB</div>
            </div>
            <button type="button" onclick="closeAvatarModal()" style="background:rgba(255,255,255,.15);border:none;border-radius:8px;color:#fff;width:30px;height:30px;font-size:16px;cursor:pointer;">✕</button>
        </div>
 
        <!-- Simple form — same pattern as teacher profile, no fetch/CSRF issues -->
        <form method="POST" enctype="multipart/form-data" id="avatarForm">
            <input type="hidden" name="action" value="upload_avatar">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <div style="padding:24px;text-align:center;">
                <?php $profileImg2 = $user['profile_image'] ?? ''; ?>
                <?php if ($profileImg2 && file_exists($profileImg2)): ?>
                    <img id="avatarPreview" src="<?= htmlspecialchars($profileImg2) ?>?v=<?= time() ?>"
                         style="width:120px;height:120px;border-radius:50%;object-fit:cover;border:4px solid var(--accent);display:block;margin:0 auto 8px;">
                    <div id="avatarInitials" style="display:none;width:120px;height:120px;border-radius:50%;background:linear-gradient(135deg,#1a3a52,#234C6A);align-items:center;justify-content:center;font-family:'Sora',sans-serif;font-size:40px;font-weight:800;color:white;margin:0 auto 8px;"><?= htmlspecialchars($userInitials) ?></div>
                <?php else: ?>
                    <img id="avatarPreview" src="" style="width:120px;height:120px;border-radius:50%;object-fit:cover;border:4px solid var(--accent);display:none;margin:0 auto 8px;">
                    <div id="avatarInitials" style="width:120px;height:120px;border-radius:50%;background:linear-gradient(135deg,#1a3a52,#234C6A);display:flex;align-items:center;justify-content:center;font-family:'Sora',sans-serif;font-size:40px;font-weight:800;color:white;margin:0 auto 8px;"><?= htmlspecialchars($userInitials) ?></div>
                <?php endif; ?>
                <div style="font-size:12px;color:#94a3b8;margin-bottom:16px;">Click pencil or drop image below</div>
                <label for="avatarFileInput" id="dropZone" style="display:block;border:2px dashed #cbd5e1;border-radius:12px;padding:20px;cursor:pointer;background:#f8fafc;transition:.2s;">
                    <div style="font-size:28px;margin-bottom:6px;">📷</div>
                    <div style="font-size:13.5px;font-weight:600;color:#475569;">Click to choose a photo</div>
                    <div style="font-size:12px;color:#94a3b8;margin-top:3px;">or drag and drop here</div>
                </label>
                <input type="file" name="avatar" id="avatarFileInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;" onchange="previewAvatar(this)">
            </div>
            <div style="padding:0 24px 20px;display:flex;gap:10px;">
                <button type="button" onclick="closeAvatarModal()" style="flex:1;padding:11px;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;color:#475569;font-size:13.5px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;">Cancel</button>
                <button type="submit" id="saveAvatarBtn" disabled style="flex:1;padding:11px;border-radius:10px;border:none;background:linear-gradient(135deg,#0ea5e9,#06b6d4);color:#fff;font-size:13.5px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;opacity:.5;transition:.2s;">Save Photo</button>
            </div>
        </form>
    </div>
</div>
<script>
function openAvatarModal() {
    document.getElementById('avatarModal').classList.add('open');
    document.addEventListener('keydown', escAvatar);
}
function closeAvatarModal() {
    document.getElementById('avatarModal').classList.remove('open');
    document.removeEventListener('keydown', escAvatar);
    document.getElementById('avatarFileInput').value = '';
    const btn = document.getElementById('saveAvatarBtn');
    btn.disabled = true; btn.style.opacity = '.5';
    // Reset preview
    document.getElementById('avatarPreview').style.display = 'none';
    document.getElementById('avatarInitials').style.display = 'flex';
}
function escAvatar(e) { if (e.key === 'Escape') closeAvatarModal(); }
function previewAvatar(input) {
    const file = input.files[0];
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) { alert('Image must be under 2MB.'); input.value=''; return; }
    const reader = new FileReader();
    reader.onload = e => {
        const preview  = document.getElementById('avatarPreview');
        const initials = document.getElementById('avatarInitials');
        preview.src = e.target.result;
        preview.style.display = 'block';
        if (initials) initials.style.display = 'none';
    };
    reader.readAsDataURL(file);
    const btn = document.getElementById('saveAvatarBtn');
    btn.disabled = false; btn.style.opacity = '1';
}
// Drag and drop
const dz = document.getElementById('dropZone');
dz.addEventListener('dragover', e => { e.preventDefault(); dz.style.borderColor='#0ea5e9'; dz.style.background='#eff8ff'; });
dz.addEventListener('dragleave', () => { dz.style.borderColor='#cbd5e1'; dz.style.background='#f8fafc'; });
dz.addEventListener('drop', e => {
    e.preventDefault(); dz.style.borderColor='#cbd5e1'; dz.style.background='#f8fafc';
    const file = e.dataTransfer.files[0];
    if (file && file.type.startsWith('image/')) {
        const input = document.getElementById('avatarFileInput');
        try { const dt = new DataTransfer(); dt.items.add(file); input.files = dt.files; } catch(e) {}
        previewAvatar(input);
    }
});
</script>

</body>
</html>
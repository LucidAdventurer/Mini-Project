<?php
/* ========================================
 * STUDENT PROFILE PAGE
 * ======================================== */

require_once "config.php";
require_once "db-guard.php";

$user = validateSession($conn, 'student');

$userName     = $user['full_name']           ?? 'Student';
$userEmail    = $user['email']               ?? '';
$userDept     = $user['department']          ?? '';
$userRegNo    = $user['registration_number'] ?? '';
$userInitials = strtoupper(substr($userName, 0, 2));
$userId       = $user['user_id'];
$memberSince  = !empty($user['created_at']) ? date('F Y', strtotime($user['created_at'])) : 'N/A';
$lastLogin    = $user['last_login'] ?? null;

// ── Aggregate stats from assessment_attempts ──────────────────────────────
$statsQuery = "
    SELECT
        COUNT(DISTINCT a.attempt_id)             AS tests_completed,
        COALESCE(AVG(a.percentage), 0)           AS avg_score,
        COALESCE(MAX(a.percentage), 0)           AS best_score,
        COUNT(DISTINCT DATE(a.submitted_at))     AS active_days,
        COALESCE(SUM(a.correct_answers), 0)      AS total_correct,
        COALESCE(SUM(a.wrong_answers), 0)        AS total_wrong
    FROM assessment_attempts a
    WHERE a.user_id = ? AND a.status = 'completed'
";
$statsResult    = safePreparedQuery($conn, $statsQuery, "i", [$userId]);
$testsCompleted = 0; $avgScore = 0; $bestScore = 0;
$activeDays     = 0; $totalCorrect = 0; $totalWrong = 0;
if ($statsResult['success'] && $statsResult['result']) {
    $s              = $statsResult['result']->fetch_assoc();
    $testsCompleted = (int)   ($s['tests_completed'] ?? 0);
    $avgScore       = (int) round($s['avg_score']    ?? 0);
    $bestScore      = (int) round($s['best_score']   ?? 0);
    $activeDays     = (int)   ($s['active_days']     ?? 0);
    $totalCorrect   = (int)   ($s['total_correct']   ?? 0);
    $totalWrong     = (int)   ($s['total_wrong']     ?? 0);
    $statsResult['result']->free();
}

// ── Recent attempts (last 10) ─────────────────────────────────────────────
$recentQuery = "
    SELECT
        a.attempt_id,
        a.percentage,
        a.score,
        a.correct_answers,
        a.wrong_answers,
        a.unanswered,
        a.total_questions,
        a.submitted_at,
        TIMESTAMPDIFF(MINUTE, a.start_time, a.end_time) AS time_taken_minutes,
        t.title      AS test_title,
        t.category,
        t.total_marks,
        t.difficulty
    FROM assessment_attempts a
    JOIN assessments t ON t.assessment_id = a.assessment_id
    WHERE a.user_id = ? AND a.status = 'completed'
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

// ── Category performance breakdown ────────────────────────────────────────
$categoryQuery = "
    SELECT
        t.category,
        COUNT(a.attempt_id)                  AS attempts,
        COALESCE(AVG(a.percentage), 0)       AS avg_score,
        COALESCE(MAX(a.percentage), 0)       AS best_score,
        COALESCE(SUM(a.correct_answers), 0)  AS correct_total,
        COALESCE(SUM(a.total_questions), 0)  AS questions_total
    FROM assessment_attempts a
    JOIN assessments t ON t.assessment_id = a.assessment_id
    WHERE a.user_id = ? AND a.status = 'completed'
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

// ── Notifications (unread count) ──────────────────────────────────────────
$notifResult = safePreparedQuery(
    $conn,
    "SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0",
    "i", [$userId]
);
$unreadCount = 0;
if ($notifResult['success'] && $notifResult['result']) {
    $nr = $notifResult['result']->fetch_assoc();
    $unreadCount = (int)($nr['cnt'] ?? 0);
    $notifResult['result']->free();
}

// ── Handle POST ───────────────────────────────────────────────────────────
$updateMessage = '';
$updateType    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // ── Update profile ────────────────────────────────────────────────────
    if ($_POST['action'] === 'update_profile') {

        $phone  = trim($_POST['phone']   ?? '');
        $bio    = trim($_POST['bio']     ?? '');
        $year   = intval($_POST['year']  ?? 0);
        $degree = trim($_POST['degree']  ?? '');
        $branch = trim($_POST['branch']  ?? '');

        $tableCheck = safeQuery($conn, "SHOW TABLES LIKE 'student_profiles'");
        $tableExists = false;
        if ($tableCheck) {
            $tableExists = (bool)$tableCheck->fetch_row();
            $tableCheck->free();
        }

        if ($tableExists) {
            $existsResult = safePreparedQuery(
                $conn, "SELECT user_id FROM student_profiles WHERE user_id = ?", "i", [$userId]
            );
            $rowExists = false;
            if ($existsResult['success'] && $existsResult['result']) {
                $rowExists = (bool)$existsResult['result']->fetch_assoc();
                $existsResult['result']->free();
            }

            if ($rowExists) {
                $uq = "UPDATE student_profiles
                       SET phone=?, bio=?, year_of_study=?, degree=?, branch=?, updated_at=NOW()
                       WHERE user_id=?";
                $ur = safePreparedQuery($conn, $uq, "ssissi", [$phone, $bio, $year, $degree, $branch, $userId]);
            } else {
                $uq = "INSERT INTO student_profiles (user_id, phone, bio, year_of_study, degree, branch, created_at, updated_at)
                       VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())";
                $ur = safePreparedQuery($conn, $uq, "ississ", [$userId, $phone, $bio, $year, $degree, $branch]);
            }

            if ($ur['success']) {
                $updateMessage = 'Profile updated successfully.';
                $updateType    = 'success';
            } else {
                $updateMessage = 'Failed to update profile. Please try again.';
                $updateType    = 'error';
            }
        } else {
            $updateMessage = 'Profile details cannot be saved yet — student_profiles table not found.';
            $updateType    = 'error';
        }

        // Re-fetch profile
        if ($tableExists) {
            $pRefresh = safePreparedQuery($conn, "SELECT * FROM student_profiles WHERE user_id = ?", "i", [$userId]);
            if ($pRefresh['success'] && $pRefresh['result']) {
                $profile = $pRefresh['result']->fetch_assoc() ?? [];
                $pRefresh['result']->free();
            }
        }
    }

    // ── Change password ───────────────────────────────────────────────────
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
                    safePreparedQuery(
                        $conn,
                        "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address) VALUES (?, 'password_change', 'user', ?, ?)",
                        "iis", [$userId, $userId, $_SERVER['REMOTE_ADDR'] ?? '']
                    );
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

// ── Fetch student_profiles if table exists ────────────────────────────────
$profile     = [];
$tableCheck2 = safeQuery($conn, "SHOW TABLES LIKE 'student_profiles'");
$spExists    = false;
if ($tableCheck2) {
    $spExists = (bool)$tableCheck2->fetch_row();
    $tableCheck2->free();
}
if ($spExists) {
    $pResult = safePreparedQuery($conn, "SELECT * FROM student_profiles WHERE user_id = ?", "i", [$userId]);
    if ($pResult['success'] && $pResult['result']) {
        $profile = $pResult['result']->fetch_assoc() ?? [];
        $pResult['result']->free();
    }
}

// ── Login activity (last 5 successful logins) ─────────────────────────────
$loginResult = safePreparedQuery(
    $conn,
    "SELECT ip_address, user_agent, created_at
     FROM login_activity
     WHERE user_id = ? AND is_success = 1
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

// ── Helpers ───────────────────────────────────────────────────────────────
function scoreColor(int $s): string {
    if ($s >= 80) return '#22543d';
    if ($s >= 60) return '#7c2d12';
    return '#742a2a';
}
function scoreBg(int $s): string {
    if ($s >= 80) return '#c6f6d5';
    if ($s >= 60) return '#feebc8';
    return '#fed7d7';
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
    $map = ['easy' => '#c6f6d5:#22543d', 'medium' => '#feebc8:#7c2d12', 'hard' => '#fed7d7:#742a2a'];
    [$bg, $color] = explode(':', $map[$d] ?? '#e2e8f0:#4a5568');
    return "<span style=\"background:{$bg};color:{$color};padding:3px 9px;border-radius:12px;font-size:11px;font-weight:700;\">"
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
    <style>
        /* ============================================
           CSS VARIABLES
           ============================================ */
        :root {
            --font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            --color-primary: #234C6A;
            --color-primary-dark: #456882;
            --color-accent: #4facfe;
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
            background: var(--color-primary);
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            padding: 12px 28px;
            display: flex; align-items: center; justify-content: space-between;
            position: fixed; top: 0; left: 0; right: 0;
            z-index: 1000;
        }

        .navbar-brand {
            display: flex; align-items: center; gap: 12px;
            font-size: 20px; font-weight: 700; color: white; text-decoration: none;
        }

        .brand-logo {
            width: 44px; height: 44px;
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 18px;
            border: 2px solid rgba(255,255,255,0.3);
        }

        .nav-profile { display: flex; align-items: center; gap: 15px; }

        .profile-button {
            display: flex; align-items: center; gap: 10px;
            padding: 8px 15px;
            background: #f7fafc;
            border: none; border-radius: 10px;
            cursor: pointer; transition: var(--transition);
            position: relative;
        }

        .profile-button:hover { background: #e2e8f0; }

        .profile-avatar {
            width: 35px; height: 35px;
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: bold; font-size: 14px;
        }

        .profile-name { font-weight: 600; font-size: 14px; color: var(--color-text); }

        /* Dropdown */
        .profile-dropdown {
            position: absolute; top: calc(100% + 10px); right: 0;
            background: var(--color-white); border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg); min-width: 220px;
            opacity: 0; visibility: hidden; transform: translateY(-10px);
            transition: var(--transition); z-index: 1001;
        }

        .profile-dropdown.active { opacity: 1; visibility: visible; transform: translateY(0); }

        .profile-dropdown::before {
            content: ''; position: absolute; top: -8px; right: 20px;
            width: 16px; height: 16px; background: var(--color-white);
            transform: rotate(45deg); border-radius: 3px;
        }

        .dropdown-header { padding: 15px 20px; border-bottom: 1px solid var(--color-border); }
        .dropdown-name  { font-weight: 600; font-size: 14px; color: var(--color-text); margin-bottom: 4px; }
        .dropdown-email { font-size: 13px; color: var(--color-text-light); }
        .dropdown-menu  { padding: 8px 0; }

        .dropdown-item {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 20px; color: var(--color-text); text-decoration: none;
            font-size: 14px; transition: var(--transition);
            cursor: pointer; border: none; background: none;
            width: 100%; text-align: left; font-family: inherit;
        }

        .dropdown-item:hover { background: var(--color-bg-light); }
        .dropdown-item.danger { color: var(--color-error); }
        .dropdown-item.danger:hover { background: rgba(245,101,101,0.1); }
        .dropdown-divider { height: 1px; background: var(--color-border); margin: 8px 0; }

        /* ============================================
           BREADCRUMB
           ============================================ */
        .breadcrumb {
            max-width: 1100px; margin: 24px auto 0; padding: 0 20px;
            display: flex; align-items: center; gap: 8px;
            font-size: 14px; color: var(--color-text-light);
        }

        .breadcrumb a { color: var(--color-accent); text-decoration: none; font-weight: 600; }
        .breadcrumb a:hover { text-decoration: underline; }

        /* ============================================
           PAGE LAYOUT
           ============================================ */
        .container {
            max-width: 1100px; margin: 24px auto 40px; padding: 0 20px;
            display: grid; grid-template-columns: 300px 1fr; gap: 24px;
        }

        /* ============================================
           PROFILE CARD (left column)
           ============================================ */
        .profile-card {
            background: var(--color-white); border-radius: var(--border-radius);
            padding: 32px 24px; box-shadow: var(--shadow-sm);
            text-align: center; height: fit-content;
            position: sticky; top: 90px;
        }

        .avatar-large {
            width: 110px; height: 110px; border-radius: 50%;
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
            display: flex; align-items: center; justify-content: center;
            font-size: 36px; font-weight: 700; color: white;
            margin: 0 auto 16px;
            border: 4px solid rgba(79,172,254,0.2);
        }

        .profile-card-name { font-size: 20px; font-weight: 700; color: var(--color-text); margin-bottom: 6px; }

        .role-badge {
            display: inline-block; padding: 4px 14px;
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
            color: white; border-radius: 20px;
            font-size: 12px; font-weight: 600; letter-spacing: 0.5px;
            margin-bottom: 16px;
        }

        .profile-card-detail {
            font-size: 13px; color: var(--color-text-light); margin-bottom: 6px;
            display: flex; align-items: center; justify-content: center; gap: 6px;
        }

        .profile-divider { height: 1px; background: var(--color-border); margin: 20px 0; }

        .stat-row { display: flex; justify-content: space-around; }
        .stat-item { text-align: center; }
        .stat-item-value { font-size: 22px; font-weight: 700; color: var(--color-accent); }
        .stat-item-label { font-size: 11px; color: var(--color-text-light); text-transform: uppercase; letter-spacing: 0.5px; }

        .cat-bar  { height: 9px; background: var(--color-border); border-radius: 9px; overflow: hidden; }
        .cat-fill { height: 100%; border-radius: 9px; background: linear-gradient(90deg, var(--color-accent), #00f2fe); transition: width .6s ease; }

        /* Sidebar nav */
        .profile-nav { margin-top: 20px; display: flex; flex-direction: column; gap: 4px; }

        .profile-nav-item {
            display: flex; align-items: center; gap: 10px;
            padding: 11px 14px; border-radius: 8px;
            font-size: 14px; font-weight: 500; color: var(--color-text-light);
            cursor: pointer; transition: var(--transition);
            border: none; background: transparent;
            width: 100%; text-align: left;
        }

        .profile-nav-item:hover { background: var(--color-bg-light); color: var(--color-text); }

        .profile-nav-item.active {
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
            color: white;
        }

        /* ============================================
           RIGHT COLUMN — PANELS
           ============================================ */
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }

        .card {
            background: var(--color-white); border-radius: var(--border-radius);
            padding: 28px; box-shadow: var(--shadow-sm); margin-bottom: 24px;
        }

        .card-title {
            font-size: 18px; font-weight: 700; color: var(--color-text);
            margin-bottom: 20px; padding-bottom: 12px;
            border-bottom: 2px solid var(--color-border);
            display: flex; align-items: center; gap: 10px;
        }

        /* Forms */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group.full-width { grid-column: 1 / -1; }

        .form-label { font-size: 13px; font-weight: 600; color: var(--color-text-light); text-transform: uppercase; letter-spacing: 0.5px; }
        .form-label .req { color: var(--color-error); margin-left: 2px; }

        .form-control {
            padding: 10px 14px; border: 2px solid var(--color-border); border-radius: 8px;
            font-size: 14px; font-family: var(--font-family); color: var(--color-text);
            background: var(--color-white); transition: var(--transition); width: 100%;
        }

        .form-control:focus { outline: none; border-color: var(--color-accent); box-shadow: 0 0 0 3px rgba(79,172,254,0.1); }
        .form-control[disabled], .form-control[readonly] { background: var(--color-bg-light); color: var(--color-text-light); cursor: not-allowed; }
        textarea.form-control { resize: vertical; min-height: 88px; line-height: 1.5; }
        .form-hint { font-size: 11px; color: var(--color-text-light); }

        .form-actions {
            display: flex; align-items: center; justify-content: space-between;
            margin-top: 24px; padding-top: 18px;
            border-top: 1px solid var(--color-border);
        }

        .form-actions-right { display: flex; gap: 12px; }

        .btn {
            padding: 10px 24px; border-radius: 8px;
            font-size: 14px; font-weight: 600;
            cursor: pointer; transition: var(--transition); border: none;
            display: inline-flex; align-items: center; gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
            color: white;
        }

        .btn-primary:hover { opacity: 0.9; transform: translateY(-1px); box-shadow: var(--shadow-sm); }

        .btn-secondary { background: var(--color-bg-light); color: var(--color-text); border: 2px solid var(--color-border); }
        .btn-secondary:hover { background: var(--color-border); }

        /* Alert banners */
        .alert {
            padding: 14px 18px; border-radius: 8px;
            font-size: 14px; font-weight: 500; margin-bottom: 20px;
            display: flex; align-items: center; gap: 10px;
        }

        .alert-success { background: rgba(72,187,120,0.1); color: #276749; border: 1px solid rgba(72,187,120,0.3); }
        .alert-error   { background: rgba(245,101,101,0.1); color: #c53030; border: 1px solid rgba(245,101,101,0.3); }

        /* Info boxes */
        .info-grid { display: grid; grid-template-columns: repeat(2,1fr); gap: 10px; }

        .info-box { background: var(--color-bg-light); border-radius: 9px; padding: 13px; border: 1px solid var(--color-border); }
        .info-box-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--color-text-light); margin-bottom: 4px; }
        .info-box-value { font-size: 15px; font-weight: 700; color: var(--color-primary); }
        .info-box-value.mono { font-family: 'Courier New', monospace; font-size: 13px; }

        /* History table */
        .history-table { width: 100%; border-collapse: collapse; }

        .history-table th {
            text-align: left; padding: 9px 13px;
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .5px; color: var(--color-text-light);
            background: var(--color-bg-light); border-bottom: 1px solid var(--color-border);
        }

        .history-table th:first-child { border-radius: 8px 0 0 8px; }
        .history-table th:last-child  { border-radius: 0 8px 8px 0; }

        .history-table td {
            padding: 12px 13px; font-size: 13px;
            border-bottom: 1px solid var(--color-border); vertical-align: middle;
        }

        .history-table tr:last-child td { border-bottom: none; }
        .history-table tr:hover td { background: var(--color-bg-light); }

        .score-pill { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; }
        .test-name  { font-weight: 600; color: var(--color-text); margin-bottom: 2px; }
        .test-cat   { font-size: 11px; color: var(--color-text-light); }

        /* Password */
        .pw-wrap { position: relative; }
        .pw-wrap .form-control { padding-right: 42px; }

        .pw-eye {
            position: absolute; right: 12px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none; cursor: pointer;
            font-size: 16px; color: var(--color-text-light); padding: 0;
        }

        .pw-eye:hover { color: var(--color-text); }

        .password-strength { margin-top: 6px; height: 4px; border-radius: 2px; background: var(--color-border); overflow: hidden; }
        .password-strength-bar { height: 100%; border-radius: 2px; transition: var(--transition); width: 0%; }
        .strength-weak   { width: 25%;  background: var(--color-error); }
        .strength-fair   { width: 50%;  background: #ed8936; }
        .strength-good   { width: 75%;  background: #ecc94b; }
        .strength-strong { width: 100%; background: var(--color-success); }
        .strength-label  { font-size: 12px; font-weight: 600; margin-top: 4px; }
        .match-msg       { font-size: 12px; font-weight: 600; margin-top: 5px; min-height: 16px; }

        /* Login history */
        .login-row {
            display: flex; align-items: center; justify-content: space-between;
            padding: 12px 14px; border-radius: 9px;
            background: var(--color-bg-light); border: 1px solid var(--color-border);
            margin-bottom: 8px;
        }

        .login-row:last-child { margin-bottom: 0; }
        .login-info   { display: flex; align-items: center; gap: 10px; }
        .login-icon   { font-size: 18px; }
        .login-detail { font-size: 13px; font-weight: 600; color: var(--color-text); }
        .login-ip     { font-size: 11px; color: var(--color-text-light); font-family: monospace; }
        .login-time   { font-size: 12px; color: var(--color-text-light); }

        /* Empty state */
        .empty-state { text-align: center; padding: 44px 20px; color: var(--color-text-light); }
        .empty-icon  { font-size: 38px; margin-bottom: 10px; }
        .empty-title { font-size: 15px; font-weight: 600; margin-bottom: 5px; color: var(--color-text); }
        .empty-sub   { font-size: 13px; }

        .divider { height: 1px; background: var(--color-border); margin: 24px 0; }

        /* ============================================
           RESPONSIVE
           ============================================ */
        @media (max-width: 900px) {
            .container { grid-template-columns: 1fr; }
            .profile-card { position: static; }
            .profile-nav { flex-direction: row; flex-wrap: wrap; }
            .profile-nav-item { flex: 1; min-width: 120px; justify-content: center; }
        }

        @media (max-width: 600px) {
            .form-grid { grid-template-columns: 1fr; }
            .info-grid { grid-template-columns: 1fr; }
            .navbar { padding: 12px 16px; }
            .container { padding: 0 14px; }
        }
    </style>
</head>
<body>

<!-- ================================================
     NAVBAR
     ================================================ -->
<nav class="navbar">
    <a href="student-dashboard.php" class="navbar-brand">
        <div class="brand-logo">PT</div>
        <span>Placement Portal</span>
    </a>

    <div class="nav-profile">
        <button class="profile-button" onclick="toggleProfileDropdown()" aria-expanded="false" aria-haspopup="true">
            <div class="profile-avatar"><?php echo htmlspecialchars($userInitials); ?></div>
            <span class="profile-name"><?php echo htmlspecialchars($userName); ?></span>
            <span style="color:#a0aec0;">▼</span>
        </button>

        <div class="profile-dropdown" id="profileDropdown">
            <div class="dropdown-header">
                <div class="dropdown-name"><?php echo htmlspecialchars($userName); ?></div>
                <div class="dropdown-email"><?php echo htmlspecialchars($userEmail); ?></div>
                <div style="margin-top:6px;">
                    <span style="display:inline-block;padding:2px 10px;background:linear-gradient(135deg,var(--color-primary),var(--color-primary-dark));color:#fff;border-radius:20px;font-size:11px;font-weight:600;letter-spacing:0.5px;">Student</span>
                </div>
            </div>
            <div class="dropdown-menu">
                <a href="student-profile.php" class="dropdown-item" style="background:var(--color-bg-light);">
                    <span>👤</span><span>My Profile</span>
                </a>
                <a href="student-dashboard.php" class="dropdown-item">
                    <span>📊</span><span>Dashboard</span>
                </a>
                <a href="home.php" class="dropdown-item">
                    <span>📝</span><span>Practice Tests</span>
                </a>
                <a href="help.html" target="_blank" rel="noopener noreferrer" class="dropdown-item">
                    <span>❓</span>
                    <span>Help & Support</span>
                </a>
                <div class="dropdown-divider"></div>
                <button onclick="handleLogout()" class="dropdown-item danger">
                    <span>🚪</span><span>Logout</span>
                </button>
            </div>
        </div>
    </div>
</nav>

<!-- Breadcrumb -->
<div class="breadcrumb">
    <a href="student-dashboard.php">Dashboard</a>
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

            <div class="avatar-large"><?php echo htmlspecialchars($userInitials); ?></div>

            <div class="profile-card-name"><?php echo htmlspecialchars($userName); ?></div>
            <div class="role-badge">🎓 Student</div>

            <?php if ($userDept): ?>
                <div class="profile-card-detail">🏛️ <?php echo htmlspecialchars($userDept); ?></div>
            <?php endif; ?>

            <?php if ($userRegNo): ?>
                <div class="profile-card-detail">🆔 <?php echo htmlspecialchars($userRegNo); ?></div>
            <?php endif; ?>

            <div class="profile-card-detail">✉️ <?php echo htmlspecialchars($userEmail); ?></div>

            <?php if ($lastLogin): ?>
                <div class="profile-card-detail">🕐 Last login <?php echo timeAgo($lastLogin); ?></div>
            <?php endif; ?>

            <div class="profile-card-detail">📅 Member since <?php echo htmlspecialchars($memberSince); ?></div>

            <div class="profile-divider"></div>

            <!-- Live stats -->
            <div class="stat-row">
                <div class="stat-item">
                    <div class="stat-item-value"><?php echo $testsCompleted; ?></div>
                    <div class="stat-item-label">Tests</div>
                </div>
                <div class="stat-item">
                    <div class="stat-item-value"><?php echo $avgScore; ?>%</div>
                    <div class="stat-item-label">Avg</div>
                </div>
                <div class="stat-item">
                    <div class="stat-item-value"><?php echo $bestScore; ?>%</div>
                    <div class="stat-item-label">Best</div>
                </div>
            </div>

            <?php if (!empty($categories)): ?>
            <div class="profile-divider"></div>
            <div style="text-align:left;">
                <div style="font-size:12px;font-weight:700;color:var(--color-text-light);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:12px;">Performance by Category</div>
                <div style="display:flex;flex-direction:column;gap:10px;">
                    <?php foreach ($categories as $cat):
                        $cs = (int)round($cat['avg_score']);
                    ?>
                    <div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                            <span style="font-size:12px;font-weight:700;color:var(--color-text);"><?php echo htmlspecialchars(ucfirst($cat['category'])); ?></span>
                            <span style="font-size:11px;color:var(--color-text-light);"><?php echo $cat['attempts']; ?> · <?php echo $cs; ?>%</span>
                        </div>
                        <div class="cat-bar">
                            <div class="cat-fill" style="width:<?php echo $cs; ?>%"></div>
                        </div>
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
                <div style="font-size:12px;font-weight:700;color:var(--color-text-light);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:12px;">Answer Accuracy</div>
                <div style="display:flex;flex-direction:column;gap:8px;">
                    <div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                            <span style="font-size:12px;font-weight:700;color:#22543d;">Correct</span>
                            <span style="font-size:12px;font-weight:700;color:#22543d;"><?php echo $totalCorrect; ?></span>
                        </div>
                        <div class="cat-bar">
                            <div class="cat-fill" style="width:<?php echo $accRate; ?>%;background:linear-gradient(90deg,#48bb78,#9ae6b4);"></div>
                        </div>
                    </div>
                    <div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                            <span style="font-size:12px;font-weight:700;color:#c53030;">Wrong</span>
                            <span style="font-size:12px;font-weight:700;color:#c53030;"><?php echo $totalWrong; ?></span>
                        </div>
                        <div class="cat-bar">
                            <div class="cat-fill" style="width:<?php echo (100 - $accRate); ?>%;background:linear-gradient(90deg,#fc8181,#feb2b2);"></div>
                        </div>
                    </div>
                    <div style="text-align:center;padding-top:4px;">
                        <span style="font-size:20px;font-weight:800;color:var(--color-primary);"><?php echo $accRate; ?>%</span>
                        <div style="font-size:10px;color:var(--color-text-light);font-weight:600;text-transform:uppercase;letter-spacing:.5px;">accuracy rate</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="profile-divider"></div>

            <!-- Sidebar nav -->
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
            <div class="alert alert-<?php echo $updateType; ?>">
                <?php echo $updateType === 'success' ? '✅' : '⚠️'; ?>
                <?php echo htmlspecialchars($updateMessage); ?>
            </div>
        <?php endif; ?>

        <!-- ══ TAB: Personal Info ══ -->
        <div class="tab-panel active" id="panel-info">
            <div class="card">
                <div class="card-title">👤 Personal Information</div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="form-grid">

                        <div class="form-group">
                            <label class="form-label">Full Name</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($userName); ?>" disabled>
                            <span class="form-hint">Managed by admin.</span>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Email Address</label>
                            <input type="email" class="form-control" value="<?php echo htmlspecialchars($userEmail); ?>" disabled>
                            <span class="form-hint">Managed by admin.</span>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Department</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($userDept); ?>" disabled>
                            <span class="form-hint">Managed by admin.</span>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Registration Number</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($userRegNo); ?>" disabled>
                            <span class="form-hint">Managed by admin.</span>
                        </div>

                        <?php if ($spExists): ?>

                        <div class="form-group">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" name="phone" class="form-control"
                                   placeholder="+91 9876543210"
                                   value="<?php echo htmlspecialchars($profile['phone'] ?? ''); ?>"
                                   maxlength="20">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Year of Study</label>
                            <select name="year" class="form-control">
                                <option value="0">Select year</option>
                                <?php for ($y = 1; $y <= 5; $y++): ?>
                                <option value="<?php echo $y; ?>"
                                    <?php echo (($profile['year_of_study'] ?? 0) == $y) ? 'selected' : ''; ?>>
                                    Year <?php echo $y; ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Degree</label>
                            <select name="degree" class="form-control">
                                <option value="">Select degree</option>
                                <?php foreach (['B.Tech','B.E.','B.Sc','B.Com','BBA','B.A','M.Tech','MBA','M.Sc','PhD','Other'] as $d): ?>
                                <option value="<?php echo $d; ?>" <?php echo (($profile['degree'] ?? '') === $d) ? 'selected' : ''; ?>>
                                    <?php echo $d; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Branch / Specialization</label>
                            <input type="text" name="branch" class="form-control"
                                   placeholder="e.g. Computer Science"
                                   value="<?php echo htmlspecialchars($profile['branch'] ?? ''); ?>"
                                   maxlength="100">
                        </div>

                        <div class="form-group full-width">
                            <label class="form-label">College / University</label>
                            <input type="text" name="college" class="form-control"
                                   placeholder="e.g. Indian Institute of Technology"
                                   value="<?php echo htmlspecialchars($profile['college'] ?? ''); ?>"
                                   maxlength="150">
                        </div>

                        <div class="form-group full-width">
                            <label class="form-label">Bio</label>
                            <textarea name="bio" class="form-control"
                                      placeholder="Tell us a little about yourself..."
                                      maxlength="500"><?php echo htmlspecialchars($profile['bio'] ?? ''); ?></textarea>
                            <span class="form-hint">Max 500 characters.</span>
                        </div>

                        <?php else: ?>
                        <div class="form-group full-width">
                            <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:9px;padding:14px;font-size:13px;color:#92400e;">
                                ⚠️ The <code>student_profiles</code> table does not exist yet.
                                Additional profile fields (phone, degree, bio, etc.) will be available once it is created.
                            </div>
                        </div>
                        <?php endif; ?>

                    </div>

                    <?php if ($spExists): ?>
                    <div class="form-actions">
                        <span style="font-size:12px;color:var(--color-text-light);">
                            <?php if (!empty($profile['updated_at'])): ?>
                                Last updated: <?php echo date('M j, Y', strtotime($profile['updated_at'])); ?>
                            <?php else: ?>
                                Profile not yet saved.
                            <?php endif; ?>
                        </span>
                        <div class="form-actions-right">
                            <button type="reset" class="btn btn-secondary">Reset</button>
                            <button type="submit" class="btn btn-primary"><span>💾</span> Save Changes</button>
                        </div>
                    </div>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Account Details -->
            <div class="card">
                <div class="card-title">🔧 Account Details</div>
                <div class="info-grid">
                    <div class="info-box">
                        <div class="info-box-label">User ID</div>
                        <div class="info-box-value mono">#<?php echo $userId; ?></div>
                    </div>
                    <div class="info-box">
                        <div class="info-box-label">Role</div>
                        <div class="info-box-value">Student</div>
                    </div>
                    <div class="info-box">
                        <div class="info-box-label">Account Status</div>
                        <div class="info-box-value" style="color:<?php echo $user['is_active'] ? '#22543d' : '#c53030'; ?>;">
                            <?php echo $user['is_active'] ? '✅ Active' : '❌ Inactive'; ?>
                        </div>
                    </div>
                    <div class="info-box">
                        <div class="info-box-label">Verified</div>
                        <div class="info-box-value" style="color:<?php echo $user['is_verified'] ? '#22543d' : '#c53030'; ?>;">
                            <?php echo $user['is_verified'] ? '✅ Yes' : '❌ No'; ?>
                        </div>
                    </div>
                    <?php if (!empty($user['created_at'])): ?>
                    <div class="info-box" style="grid-column:1/-1;">
                        <div class="info-box-label">Member Since</div>
                        <div class="info-box-value" style="font-size:14px;">
                            <?php echo date('F j, Y', strtotime($user['created_at'])); ?>
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
                                $pct  = (int)round($a['percentage'] ?? 0);
                                $mins = $a['time_taken_minutes'] ?? 0;
                                $cor  = $a['correct_answers']    ?? 0;
                                $wrg  = $a['wrong_answers']      ?? 0;
                                $unans= $a['unanswered']         ?? 0;
                            ?>
                            <tr>
                                <td>
                                    <div class="test-name"><?php echo htmlspecialchars($a['test_title']); ?></div>
                                    <div class="test-cat">
                                        <?php echo htmlspecialchars(ucfirst($a['category'] ?? '')); ?>
                                        &nbsp;<?php echo difficultyBadge($a['difficulty'] ?? 'medium'); ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="score-pill" style="background:<?php echo scoreBg($pct); ?>;color:<?php echo scoreColor($pct); ?>">
                                        <?php echo $pct; ?>%
                                    </span>
                                    <div style="font-size:11px;color:var(--color-text-light);margin-top:3px;">
                                        <?php echo number_format($a['score'] ?? 0, 1); ?> / <?php echo $a['total_marks'] ?? '—'; ?> marks
                                    </div>
                                </td>
                                <td style="font-size:12px;">
                                    <span style="color:#22543d;font-weight:700;">✓<?php echo $cor; ?></span>&nbsp;
                                    <span style="color:#c53030;font-weight:700;">✗<?php echo $wrg; ?></span>&nbsp;
                                    <span style="color:var(--color-text-light);">–<?php echo $unans; ?></span>
                                </td>
                                <td style="font-size:12px;color:var(--color-text-light);">
                                    <?php echo $mins ? $mins . ' min' : '—'; ?>
                                </td>
                                <td style="font-size:12px;color:var(--color-text-light);">
                                    <?php echo $a['submitted_at'] ? timeAgo($a['submitted_at']) : '—'; ?>
                                </td>
                                <td style="font-size:12px;font-weight:700;color:<?php echo scoreColor($pct); ?>">
                                    <?php echo scoreLabel($pct); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div style="text-align:center;margin-top:16px;">
                    <a href="all-results.php" style="color:var(--color-accent);font-size:13px;font-weight:700;text-decoration:none;">
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
                            <span class="strength-label" id="strengthLabel" style="color:var(--color-text-light);"></span>
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
                        <span style="font-size:12px;color:var(--color-text-light);">Use letters, numbers, and symbols.</span>
                        <button type="submit" class="btn btn-primary"><span>🔑</span> Update Password</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-title">ℹ️ Account Information</div>
                <div class="info-grid" style="margin-bottom:<?php echo !empty($loginHistory) ? '24px' : '0'; ?>;">
                    <div class="info-box">
                        <div class="info-box-label">User ID</div>
                        <div class="info-box-value mono">#<?php echo $userId; ?></div>
                    </div>
                    <div class="info-box">
                        <div class="info-box-label">Role</div>
                        <div class="info-box-value">Student</div>
                    </div>
                    <div class="info-box">
                        <div class="info-box-label">Account Status</div>
                        <div class="info-box-value" style="color:<?php echo $user['is_active'] ? '#22543d' : '#c53030'; ?>;">
                            <?php echo $user['is_active'] ? '✅ Active' : '❌ Inactive'; ?>
                        </div>
                    </div>
                    <div class="info-box">
                        <div class="info-box-label">Verified</div>
                        <div class="info-box-value" style="color:<?php echo $user['is_verified'] ? '#22543d' : '#c53030'; ?>;">
                            <?php echo $user['is_verified'] ? '✅ Yes' : '❌ No'; ?>
                        </div>
                    </div>
                    <?php if (!empty($user['created_at'])): ?>
                    <div class="info-box" style="grid-column:1/-1;">
                        <div class="info-box-label">Member Since</div>
                        <div class="info-box-value" style="font-size:14px;">
                            <?php echo date('F j, Y', strtotime($user['created_at'])); ?>
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
                        <span class="login-icon"><?php echo parseUA($ll['user_agent'] ?? ''); ?></span>
                        <div>
                            <div class="login-detail"><?php echo parseUA($ll['user_agent'] ?? ''); ?></div>
                            <div class="login-ip"><?php echo htmlspecialchars($ll['ip_address'] ?? '—'); ?></div>
                        </div>
                    </div>
                    <div class="login-time"><?php echo timeAgo($ll['created_at']); ?></div>
                </div>
                <?php endforeach; ?>
                <div style="margin-top:8px;font-size:11px;color:var(--color-text-light);text-align:right;">
                    Showing last <?php echo count($loginHistory); ?> successful logins
                </div>
                <?php endif; ?>
            </div>

        </div><!-- /panel-security -->

    </main>
</div>

<script>
    /* ============================================
       TAB SWITCHING
       ============================================ */
    function switchTab(tab) {
        document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.profile-nav-item').forEach(n => n.classList.remove('active'));
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
       PASSWORD EYE TOGGLE
       ============================================ */
    function togglePw(id, btn) {
        const inp = document.getElementById(id);
        if (inp.type === 'password') { inp.type = 'text';     btn.textContent = '🙈'; }
        else                         { inp.type = 'password'; btn.textContent = '👁️'; }
    }

    /* ============================================
       PASSWORD STRENGTH METER
       ============================================ */
    function checkStrength(val) {
        const bar   = document.getElementById('strengthBar');
        const label = document.getElementById('strengthLabel');

        if (!val) { bar.className = 'password-strength-bar'; label.textContent = ''; return; }

        let score = 0;
        if (val.length >= 8)            score++;
        if (/[A-Z]/.test(val))          score++;
        if (/[0-9]/.test(val))          score++;
        if (/[^A-Za-z0-9]/.test(val))   score++;

        const levels = [
            { cls: 'strength-weak',   label: 'Weak',   color: '#f56565' },
            { cls: 'strength-fair',   label: 'Fair',   color: '#ed8936' },
            { cls: 'strength-good',   label: 'Good',   color: '#ecc94b' },
            { cls: 'strength-strong', label: 'Strong', color: '#48bb78' },
        ];

        const lvl             = levels[Math.max(score - 1, 0)];
        bar.className         = 'password-strength-bar ' + lvl.cls;
        label.textContent     = lvl.label;
        label.style.color     = lvl.color;
    }

    /* ============================================
       PASSWORD MATCH CHECK
       ============================================ */
    function checkMatch() {
        const nw  = document.getElementById('pwNew').value;
        const cf  = document.getElementById('pwConfirm').value;
        const msg = document.getElementById('matchMsg');
        if (!cf) { msg.textContent = ''; return; }
        if (nw === cf) { msg.style.color = '#22543d'; msg.textContent = '✓ Passwords match'; }
        else           { msg.style.color = '#742a2a'; msg.textContent = '✗ Passwords do not match'; }
    }

    /* ============================================
       ANIMATE CATEGORY BARS ON LOAD
       ============================================ */
    window.addEventListener('load', () => {
        document.querySelectorAll('.cat-fill').forEach(bar => {
            const w = bar.style.width;
            bar.style.width = '0';
            setTimeout(() => { bar.style.width = w; }, 250);
        });
    });

    /* ============================================
       AUTO-OPEN TAB FROM URL HASH
       ============================================ */
    window.addEventListener('DOMContentLoaded', function() {
        const hash = window.location.hash.replace('#', '');
        const validTabs = ['info', 'history', 'security'];
        if (validTabs.includes(hash)) switchTab(hash);
    });
</script>

</body>
</html>
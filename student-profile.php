<?php
/* ========================================
 * STUDENT PROFILE PAGE
 * ======================================== */

require_once "config.php";
require_once "db-guard.php";

$user = validateSession($conn, 'student');

$userName     = $user['full_name']          ?? 'Student';
$userEmail    = $user['email']              ?? '';
$userDept     = $user['department']         ?? '';
$userRegNo    = $user['registration_number'] ?? '';
$userInitials = strtoupper(substr($userName, 0, 2));
$userId       = $user['user_id'];

// ── Aggregate stats from assessment_attempts ──────────────────────────────
// Uses: percentage, score, correct_answers, wrong_answers, unanswered,
//       start_time, end_time, submitted_at — all real columns per schema.
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
// time_taken derived from end_time - start_time since no time_taken_minutes col.
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
    // Only fields that exist on the users table: department (editable),
    // registration_number is read-only (set by admin). We persist extra
    // student info in a student_profiles table if it exists, otherwise
    // we gracefully degrade to updating only what's in users.
    if ($_POST['action'] === 'update_profile') {

        $phone  = trim($_POST['phone']   ?? '');
        $bio    = trim($_POST['bio']     ?? '');
        $year   = intval($_POST['year']  ?? 0);
        $degree = trim($_POST['degree']  ?? '');
        $branch = trim($_POST['branch']  ?? '');

        // Check whether student_profiles table exists
        $tableCheck = safeQuery($conn, "SHOW TABLES LIKE 'student_profiles'");
        $tableExists = false;
        if ($tableCheck) {
            $tableExists = (bool)$tableCheck->fetch_row();
            $tableCheck->free();
        }

        if ($tableExists) {
            // Check if row exists for this user
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
            // Fallback: no student_profiles table yet — update only allowed
            // fields on users (department is the only flexible field there)
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
        $current = $_POST['current_password']  ?? '';
        $new     = $_POST['new_password']       ?? '';
        $confirm = $_POST['confirm_password']   ?? '';

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
                    // Log it
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

// ── Last login from users table ───────────────────────────────────────────
$lastLogin = $user['last_login'] ?? null;

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
    <title>My Profile – PTA Platform</title>
    <style>
        :root {
            --primary:      #234C6A;
            --primary-dark: #456882;
            --accent:       #4facfe;
            --accent-2:     #00f2fe;
            --bg:           linear-gradient(135deg, #D3DAD9 0%, white 100%);
            --white:        #ffffff;
            --gray-50:      #f7fafc;
            --gray-100:     #e2e8f0;
            --gray-400:     #a0aec0;
            --gray-500:     #718096;
            --gray-700:     #4a5568;
            --gray-800:     #2d3748;
            --red:          #f56565;
            --red-bg:       #fff5f5;
            --green:        #48bb78;
            --green-bg:     #f0fff4;
            --shadow-sm:    0 2px 8px rgba(0,0,0,0.06);
            --shadow-md:    0 4px 20px rgba(0,0,0,0.08);
            --shadow-lg:    0 8px 30px rgba(0,0,0,0.12);
            --radius:       16px;
            --radius-sm:    10px;
        }
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--bg);
            min-height: 100vh;
            padding-top: 71px;
            color: var(--gray-800);
        }

        /* ── Navbar ── */
        .navbar {
            background: var(--primary);
            padding: 12px 28px;
            display: flex; align-items: center; justify-content: space-between;
            position: fixed; top: 0; left: 0; right: 0;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        .navbar-brand {
            display: flex; align-items: center; gap: 12px;
            color: white; text-decoration: none;
            font-weight: 700; font-size: 20px;
        }
        .brand-logo {
            width: 44px; height: 44px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 18px;
        }
        .nav-right { display: flex; align-items: center; gap: 12px; }

        /* ── Profile dropdown ── */
        .profile-dropdown-container { position: relative; }
        .profile-button {
            display: flex; align-items: center; gap: 10px;
            padding: 7px 13px;
            background: rgba(255,255,255,0.12);
            border: none; border-radius: 10px; cursor: pointer; transition: 0.2s;
        }
        .profile-button:hover { background: rgba(255,255,255,0.22); }
        .nav-avatar {
            width: 35px; height: 35px;
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 14px; flex-shrink: 0;
        }
        .nav-username { color: white; font-weight: 600; font-size: 14px; }
        .dropdown-arrow { font-size: 11px; color: rgba(255,255,255,0.7); }
        .profile-dropdown {
            position: absolute; top: calc(100% + 10px); right: 0;
            background: white; border-radius: 12px;
            box-shadow: var(--shadow-lg); min-width: 220px;
            opacity: 0; visibility: hidden; transform: translateY(-8px);
            transition: 0.25s cubic-bezier(.22,1,.36,1); z-index: 1001;
        }
        .profile-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0); }
        .dropdown-header {
            padding: 16px 18px; border-bottom: 1px solid var(--gray-100);
            display: flex; align-items: center; gap: 12px;
        }
        .dropdown-header-avatar {
            width: 42px; height: 42px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 16px; flex-shrink: 0;
        }
        .dropdown-header-name  { font-size: 14px; font-weight: 700; color: var(--gray-800); margin-bottom: 2px; }
        .dropdown-header-email { font-size: 12px; color: var(--gray-500); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 140px; }
        .dropdown-menu { padding: 6px 0; }
        .dropdown-item {
            display: flex; align-items: center; gap: 11px;
            padding: 10px 18px; color: var(--gray-800); text-decoration: none;
            font-size: 14px; font-weight: 500; cursor: pointer;
            border: none; background: none; width: 100%;
            text-align: left; font-family: inherit; transition: background 0.15s;
        }
        .dropdown-item:hover { background: var(--gray-50); }
        .dropdown-item-icon { font-size: 16px; width: 20px; text-align: center; }
        .dropdown-divider { height: 1px; background: var(--gray-100); margin: 4px 0; }
        .dropdown-item.logout { color: var(--red); }
        .dropdown-item.logout:hover { background: var(--red-bg); }
        .dropdown-overlay {
            position: fixed; inset: 0; background: transparent;
            z-index: 999; display: none;
        }
        .dropdown-overlay.show { display: block; }

        /* ── Layout ── */
        .container { max-width: 1300px; margin: 0 auto; padding: 30px; }
        .page-grid {
            display: grid;
            grid-template-columns: 310px 1fr;
            gap: 28px; align-items: start;
        }

        /* ── Toast ── */
        .toast {
            position: fixed; top: 85px; right: 28px; z-index: 2000;
            padding: 14px 22px; border-radius: 10px;
            font-size: 14px; font-weight: 600;
            box-shadow: var(--shadow-lg);
            display: flex; align-items: center; gap: 10px;
            transform: translateX(120%);
            transition: transform 0.35s cubic-bezier(.22,1,.36,1);
        }
        .toast.show       { transform: translateX(0); }
        .toast.success    { background: var(--green-bg); color: #22543d; border: 1px solid #9ae6b4; }
        .toast.error      { background: var(--red-bg);   color: #742a2a; border: 1px solid #feb2b2; }

        /* ── Cards ── */
        .card { background: var(--white); border-radius: var(--radius); box-shadow: var(--shadow-md); overflow: hidden; }
        .card-body { padding: 24px; }
        .card-title {
            font-size: 16px; font-weight: 700; color: var(--gray-800);
            margin-bottom: 18px; padding-bottom: 14px;
            border-bottom: 1px solid var(--gray-100);
            display: flex; align-items: center; gap: 10px;
        }
        .card-title-icon {
            width: 32px; height: 32px;
            background: linear-gradient(135deg, rgba(79,172,254,.15), rgba(0,242,254,.12));
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 15px;
        }

        /* ── Profile Hero ── */
        .hero-banner {
            height: 90px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 50%, var(--accent) 100%);
        }
        .hero-body { padding: 0 22px 22px; }
        .avatar-wrap { margin-top: -38px; margin-bottom: 12px; }
        .profile-avatar-lg {
            width: 76px; height: 76px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 26px;
            border: 4px solid white;
            box-shadow: var(--shadow-md);
        }
        .hero-name  { font-size: 20px; font-weight: 800; color: var(--gray-800); margin-bottom: 3px; }
        .hero-email { font-size: 13px; color: var(--gray-500); margin-bottom: 10px; }
        .hero-meta  { display: flex; flex-direction: column; gap: 5px; margin-bottom: 14px; }
        .hero-meta-item {
            display: flex; align-items: center; gap: 7px;
            font-size: 12px; color: var(--gray-500);
        }
        .role-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 12px;
            background: linear-gradient(135deg, rgba(79,172,254,.12), rgba(0,242,254,.08));
            border: 1px solid rgba(79,172,254,.28);
            border-radius: 20px;
            font-size: 12px; font-weight: 700; color: var(--primary);
            margin-bottom: 16px;
        }
        .hero-stats {
            display: grid; grid-template-columns: repeat(2,1fr); gap: 8px;
        }
        .hero-stat {
            background: var(--gray-50); border-radius: 9px; padding: 11px;
            text-align: center; border: 1px solid var(--gray-100);
        }
        .hero-stat-num   { font-size: 21px; font-weight: 800; color: var(--primary); line-height: 1; margin-bottom: 3px; }
        .hero-stat-label { font-size: 10px; color: var(--gray-500); font-weight: 700; text-transform: uppercase; letter-spacing: .5px; }

        /* ── Category bars ── */
        .cat-list { display: flex; flex-direction: column; gap: 13px; }
        .cat-header { display: flex; justify-content: space-between; margin-bottom: 6px; }
        .cat-name   { font-size: 13px; font-weight: 700; color: var(--gray-800); }
        .cat-meta   { font-size: 11px; color: var(--gray-400); }
        .cat-bar    { height: 9px; background: var(--gray-100); border-radius: 9px; overflow: hidden; }
        .cat-fill   { height: 100%; border-radius: 9px; background: linear-gradient(90deg, var(--accent), var(--accent-2)); transition: width .6s ease; }

        /* ── Tabs ── */
        .tab-nav {
            display: flex; gap: 4px; background: var(--gray-50);
            padding: 5px; border-radius: 12px; margin-bottom: 22px;
        }
        .tab-btn {
            flex: 1; padding: 9px 12px;
            background: transparent; border: none; border-radius: 9px;
            font-size: 13px; font-weight: 600; color: var(--gray-500);
            cursor: pointer; transition: 0.2s;
            display: flex; align-items: center; justify-content: center; gap: 6px;
        }
        .tab-btn.active { background: white; color: var(--primary); box-shadow: var(--shadow-sm); }
        .tab-btn:hover:not(.active) { color: var(--gray-700); }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }

        /* ── Form ── */
        .form-grid   { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-group  { display: flex; flex-direction: column; gap: 6px; }
        .form-group.full { grid-column: 1 / -1; }
        .form-label  { font-size: 13px; font-weight: 700; color: var(--gray-700); }
        .form-label .req { color: var(--red); margin-left: 2px; }
        .form-input, .form-select, .form-textarea {
            padding: 10px 13px;
            border: 2px solid var(--gray-100);
            border-radius: 9px;
            font-size: 14px; color: var(--gray-800); font-family: inherit;
            background: var(--white); transition: 0.2s; width: 100%;
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none; border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(79,172,254,.1);
        }
        .form-input:disabled {
            background: var(--gray-50); color: var(--gray-500); cursor: not-allowed;
        }
        .form-textarea { resize: vertical; min-height: 88px; line-height: 1.5; }
        .form-hint     { font-size: 11px; color: var(--gray-400); }
        .form-footer {
            margin-top: 22px; padding-top: 18px;
            border-top: 1px solid var(--gray-100);
            display: flex; align-items: center; justify-content: space-between;
        }
        .last-updated { font-size: 12px; color: var(--gray-400); }
        .btn-primary {
            padding: 11px 26px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white; border: none; border-radius: 9px;
            font-size: 14px; font-weight: 700; cursor: pointer; transition: 0.2s;
            display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(35,76,106,.32); }
        .btn-primary:active { transform: translateY(0); }

        /* ── Info badge row ── */
        .info-grid {
            display: grid; grid-template-columns: repeat(2,1fr); gap: 10px;
        }
        .info-box {
            background: var(--gray-50); border-radius: 9px;
            padding: 13px; border: 1px solid var(--gray-100);
        }
        .info-box-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--gray-400); margin-bottom: 4px; }
        .info-box-value { font-size: 15px; font-weight: 700; color: var(--primary); }
        .info-box-value.mono { font-family: 'Courier New', monospace; font-size: 13px; }

        /* ── History table ── */
        .history-table { width: 100%; border-collapse: collapse; }
        .history-table th {
            text-align: left; padding: 9px 13px;
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .5px; color: var(--gray-500);
            background: var(--gray-50); border-bottom: 1px solid var(--gray-100);
        }
        .history-table th:first-child { border-radius: 8px 0 0 8px; }
        .history-table th:last-child  { border-radius: 0 8px 8px 0; }
        .history-table td {
            padding: 12px 13px; font-size: 13px;
            border-bottom: 1px solid var(--gray-100); vertical-align: middle;
        }
        .history-table tr:last-child td { border-bottom: none; }
        .history-table tr:hover td { background: var(--gray-50); }
        .score-pill {
            display: inline-block; padding: 3px 10px;
            border-radius: 20px; font-size: 12px; font-weight: 700;
        }
        .test-name { font-weight: 600; color: var(--gray-800); margin-bottom: 2px; }
        .test-cat  { font-size: 11px; color: var(--gray-400); }

        /* ── Password ── */
        .pw-grid     { display: flex; flex-direction: column; gap: 14px; }
        .pw-wrap     { position: relative; }
        .pw-wrap .form-input { padding-right: 42px; }
        .pw-eye {
            position: absolute; right: 12px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none; cursor: pointer;
            font-size: 16px; color: var(--gray-400); padding: 0;
        }
        .pw-eye:hover { color: var(--gray-700); }
        .strength-bar  { height: 4px; background: var(--gray-100); border-radius: 4px; overflow: hidden; margin-top: 7px; margin-bottom: 4px; }
        .strength-fill { height: 100%; border-radius: 4px; transition: .3s; }
        .strength-text { font-size: 12px; font-weight: 600; }
        .match-msg     { font-size: 12px; font-weight: 600; margin-top: 5px; min-height: 16px; }

        /* ── Login history ── */
        .login-row {
            display: flex; align-items: center; justify-content: space-between;
            padding: 10px 14px; border-radius: 9px;
            background: var(--gray-50); border: 1px solid var(--gray-100);
            margin-bottom: 8px;
        }
        .login-row:last-child { margin-bottom: 0; }
        .login-info { display: flex; align-items: center; gap: 10px; }
        .login-icon { font-size: 18px; }
        .login-detail  { font-size: 13px; font-weight: 600; color: var(--gray-800); }
        .login-ip      { font-size: 11px; color: var(--gray-400); font-family: monospace; }
        .login-time    { font-size: 12px; color: var(--gray-500); }

        /* ── Empty state ── */
        .empty-state { text-align: center; padding: 44px 20px; color: var(--gray-500); }
        .empty-icon  { font-size: 38px; margin-bottom: 10px; }
        .empty-title { font-size: 15px; font-weight: 600; margin-bottom: 5px; }
        .empty-sub   { font-size: 13px; }

        /* ── Divider ── */
        .divider { height: 1px; background: var(--gray-100); margin: 26px 0; }

        /* ── Responsive ── */
        @media (max-width: 1024px) {
            .page-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 640px) {
            .container { padding: 14px; }
            .form-grid { grid-template-columns: 1fr; }
            .nav-username { display: none; }
            .info-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<!-- ── Navbar ── -->
<nav class="navbar">
    <a href="student-dashboard.php" class="navbar-brand">
        <div class="brand-logo">P</div>
        <span>Student Portal</span>
    </a>
    <div class="nav-right">
        <div class="profile-dropdown-container">
            <button class="profile-button" onclick="toggleDropdown()" aria-label="Profile menu">
                <div class="nav-avatar"><?php echo $userInitials; ?></div>
                <span class="nav-username"><?php echo htmlspecialchars($userName); ?></span>
                <span class="dropdown-arrow">▼</span>
            </button>
            <div class="profile-dropdown" id="profileDropdown">
                <div class="dropdown-header">
                    <div class="dropdown-header-avatar"><?php echo $userInitials; ?></div>
                    <div>
                        <div class="dropdown-header-name"><?php echo htmlspecialchars($userName); ?></div>
                        <div class="dropdown-header-email" title="<?php echo htmlspecialchars($userEmail); ?>"><?php echo htmlspecialchars($userEmail); ?></div>
                    </div>
                </div>
                <div class="dropdown-menu">
                    <a href="student-profile.php" class="dropdown-item">
                        <span class="dropdown-item-icon">👤</span>
                        <span>My Profile</span>
                    </a>
                    <a href="student-dashboard.php" class="dropdown-item">
                        <span class="dropdown-item-icon">🏠</span>
                        <span>Dashboard</span>
                    </a>
                    <a href="home.php" class="dropdown-item">
                        <span class="dropdown-item-icon">📝</span>
                        <span>Practice Tests</span>
                    </a>
                    <div class="dropdown-divider"></div>
                    <button onclick="confirmLogout()" class="dropdown-item logout">
                        <span class="dropdown-item-icon">🚪</span>
                        <span>Logout</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</nav>
<div class="dropdown-overlay" id="dropdownOverlay" onclick="closeDropdown()"></div>

<!-- ── Toast ── -->
<?php if ($updateMessage): ?>
<div class="toast <?php echo $updateType; ?> show" id="toast">
    <span><?php echo $updateType === 'success' ? '✅' : '❌'; ?></span>
    <span><?php echo htmlspecialchars($updateMessage); ?></span>
</div>
<?php endif; ?>

<div class="container">
    <div class="page-grid">

        <!-- ══ LEFT COLUMN ══ -->
        <div style="display:flex;flex-direction:column;gap:20px;">

            <!-- Profile Hero -->
            <div class="card">
                <div class="hero-banner"></div>
                <div class="hero-body">
                    <div class="avatar-wrap">
                        <div class="profile-avatar-lg"><?php echo $userInitials; ?></div>
                    </div>
                    <div class="hero-name"><?php echo htmlspecialchars($userName); ?></div>
                    <div class="hero-email"><?php echo htmlspecialchars($userEmail); ?></div>
                    <div class="hero-meta">
                        <?php if ($userDept): ?>
                        <div class="hero-meta-item"><span>🏛️</span><span><?php echo htmlspecialchars($userDept); ?></span></div>
                        <?php endif; ?>
                        <?php if ($userRegNo): ?>
                        <div class="hero-meta-item"><span>🆔</span><span><?php echo htmlspecialchars($userRegNo); ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($profile['college'])): ?>
                        <div class="hero-meta-item"><span>🏫</span><span><?php echo htmlspecialchars($profile['college']); ?></span></div>
                        <?php endif; ?>
                        <?php if ($lastLogin): ?>
                        <div class="hero-meta-item"><span>🕐</span><span>Last login <?php echo timeAgo($lastLogin); ?></span></div>
                        <?php endif; ?>
                    </div>
                    <div class="role-badge"><span>🎓</span> Student</div>
                    <div class="hero-stats">
                        <div class="hero-stat">
                            <div class="hero-stat-num"><?php echo $testsCompleted; ?></div>
                            <div class="hero-stat-label">Tests Done</div>
                        </div>
                        <div class="hero-stat">
                            <div class="hero-stat-num"><?php echo $avgScore; ?>%</div>
                            <div class="hero-stat-label">Avg Score</div>
                        </div>
                        <div class="hero-stat">
                            <div class="hero-stat-num"><?php echo $bestScore; ?>%</div>
                            <div class="hero-stat-label">Best Score</div>
                        </div>
                        <div class="hero-stat">
                            <div class="hero-stat-num"><?php echo $activeDays; ?></div>
                            <div class="hero-stat-label">Active Days</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Category Breakdown -->
            <?php if (!empty($categories)): ?>
            <div class="card">
                <div class="card-body">
                    <div class="card-title">
                        <div class="card-title-icon">📊</div>
                        Performance by Category
                    </div>
                    <div class="cat-list">
                        <?php foreach ($categories as $cat):
                            $cs = (int)round($cat['avg_score']);
                        ?>
                        <div>
                            <div class="cat-header">
                                <span class="cat-name"><?php echo htmlspecialchars(ucfirst($cat['category'])); ?></span>
                                <span class="cat-meta"><?php echo $cat['attempts']; ?> tests · <?php echo $cs; ?>%</span>
                            </div>
                            <div class="cat-bar">
                                <div class="cat-fill" style="width:<?php echo $cs; ?>%"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Answer Accuracy Summary -->
            <?php if ($testsCompleted > 0): ?>
            <div class="card">
                <div class="card-body">
                    <div class="card-title">
                        <div class="card-title-icon">🎯</div>
                        Answer Accuracy
                    </div>
                    <?php
                        $total     = $totalCorrect + $totalWrong;
                        $accRate   = $total > 0 ? round(($totalCorrect / $total) * 100) : 0;
                    ?>
                    <div style="display:flex;flex-direction:column;gap:10px;">
                        <div>
                            <div style="display:flex;justify-content:space-between;margin-bottom:5px;">
                                <span style="font-size:13px;font-weight:700;color:#22543d;">Correct</span>
                                <span style="font-size:13px;font-weight:700;color:#22543d;"><?php echo $totalCorrect; ?></span>
                            </div>
                            <div class="cat-bar">
                                <div class="cat-fill" style="width:<?php echo $accRate; ?>%;background:linear-gradient(90deg,#48bb78,#9ae6b4);"></div>
                            </div>
                        </div>
                        <div>
                            <div style="display:flex;justify-content:space-between;margin-bottom:5px;">
                                <span style="font-size:13px;font-weight:700;color:#c53030;">Wrong</span>
                                <span style="font-size:13px;font-weight:700;color:#c53030;"><?php echo $totalWrong; ?></span>
                            </div>
                            <div class="cat-bar">
                                <div class="cat-fill" style="width:<?php echo (100 - $accRate); ?>%;background:linear-gradient(90deg,#fc8181,#feb2b2);"></div>
                            </div>
                        </div>
                        <div style="text-align:center;padding-top:5px;">
                            <span style="font-size:24px;font-weight:800;color:var(--primary);"><?php echo $accRate; ?>%</span>
                            <div style="font-size:11px;color:var(--gray-500);font-weight:600;text-transform:uppercase;letter-spacing:.5px;">accuracy rate</div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
        <!-- ══ END LEFT ══ -->

        <!-- ══ RIGHT COLUMN ══ -->
        <div>
            <div class="card">
                <div class="card-body">

                    <div class="tab-nav">
                        <button class="tab-btn active" onclick="switchTab('profile',this)"><span>👤</span> Profile</button>
                        <button class="tab-btn"         onclick="switchTab('history',this)"><span>📋</span> History</button>
                        <button class="tab-btn"         onclick="switchTab('security',this)"><span>🔒</span> Security</button>
                    </div>

                    <!-- ════ TAB: Profile Info ════ -->
                    <div class="tab-panel active" id="tab-profile">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="update_profile">
                            <div class="form-grid">

                                <div class="form-group">
                                    <label class="form-label">Full Name</label>
                                    <input type="text" class="form-input" value="<?php echo htmlspecialchars($userName); ?>" disabled>
                                    <span class="form-hint">Managed by admin.</span>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Email Address</label>
                                    <input type="email" class="form-input" value="<?php echo htmlspecialchars($userEmail); ?>" disabled>
                                    <span class="form-hint">Managed by admin.</span>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Department</label>
                                    <input type="text" class="form-input" value="<?php echo htmlspecialchars($userDept); ?>" disabled>
                                    <span class="form-hint">Managed by admin.</span>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Registration Number</label>
                                    <input type="text" class="form-input" value="<?php echo htmlspecialchars($userRegNo); ?>" disabled>
                                    <span class="form-hint">Managed by admin.</span>
                                </div>

                                <?php if ($spExists): ?>

                                <div class="form-group">
                                    <label class="form-label">Phone Number</label>
                                    <input type="tel" name="phone" class="form-input"
                                           placeholder="+91 9876543210"
                                           value="<?php echo htmlspecialchars($profile['phone'] ?? ''); ?>"
                                           maxlength="20">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Year of Study</label>
                                    <select name="year" class="form-select">
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
                                    <select name="degree" class="form-select">
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
                                    <input type="text" name="branch" class="form-input"
                                           placeholder="e.g. Computer Science"
                                           value="<?php echo htmlspecialchars($profile['branch'] ?? ''); ?>"
                                           maxlength="100">
                                </div>

                                <div class="form-group full">
                                    <label class="form-label">College / University</label>
                                    <input type="text" name="college" class="form-input"
                                           placeholder="e.g. Indian Institute of Technology"
                                           value="<?php echo htmlspecialchars($profile['college'] ?? ''); ?>"
                                           maxlength="150">
                                </div>

                                <div class="form-group full">
                                    <label class="form-label">Bio</label>
                                    <textarea name="bio" class="form-textarea"
                                              placeholder="Tell us a little about yourself..."
                                              maxlength="500"><?php echo htmlspecialchars($profile['bio'] ?? ''); ?></textarea>
                                    <span class="form-hint">Max 500 characters.</span>
                                </div>

                                <?php else: ?>
                                <div class="form-group full">
                                    <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:9px;padding:14px;font-size:13px;color:#92400e;">
                                        ⚠️ The <code>student_profiles</code> table does not exist yet.
                                        Additional profile fields (phone, degree, bio, etc.) will be available once it is created.
                                        Name, email, department, and registration number are still shown above from the <code>users</code> table.
                                    </div>
                                </div>
                                <?php endif; ?>

                            </div>
                            <?php if ($spExists): ?>
                            <div class="form-footer">
                                <span class="last-updated">
                                    <?php if (!empty($profile['updated_at'])): ?>
                                        Last updated: <?php echo date('M j, Y', strtotime($profile['updated_at'])); ?>
                                    <?php else: ?>
                                        Profile not yet saved.
                                    <?php endif; ?>
                                </span>
                                <button type="submit" class="btn-primary">
                                    <span>💾</span> Save Changes
                                </button>
                            </div>
                            <?php endif; ?>
                        </form>
                    </div>

                    <!-- ════ TAB: Test History ════ -->
                    <div class="tab-panel" id="tab-history">
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
                                        <div style="font-size:11px;color:var(--gray-400);margin-top:3px;">
                                            <?php echo number_format($a['score'] ?? 0,1); ?> / <?php echo $a['total_marks'] ?? '—'; ?> marks
                                        </div>
                                    </td>
                                    <td style="font-size:12px;color:var(--gray-600);">
                                        <span style="color:#22543d;font-weight:700;">✓<?php echo $cor; ?></span>&nbsp;
                                        <span style="color:#c53030;font-weight:700;">✗<?php echo $wrg; ?></span>&nbsp;
                                        <span style="color:var(--gray-400);">–<?php echo $unans; ?></span>
                                    </td>
                                    <td style="font-size:12px;color:var(--gray-500);">
                                        <?php echo $mins ? $mins . ' min' : '—'; ?>
                                    </td>
                                    <td style="font-size:12px;color:var(--gray-500);">
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
                            <a href="all-results.php" style="color:var(--accent);font-size:13px;font-weight:700;text-decoration:none;">
                                View full history →
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- ════ TAB: Security ════ -->
                    <div class="tab-panel" id="tab-security">

                        <!-- Change Password -->
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="change_password">
                            <div class="pw-grid">

                                <div class="form-group">
                                    <label class="form-label">Current Password <span class="req">*</span></label>
                                    <div class="pw-wrap">
                                        <input type="password" name="current_password" class="form-input"
                                               id="pwCurrent" placeholder="Enter current password"
                                               autocomplete="current-password" required>
                                        <button type="button" class="pw-eye" onclick="togglePw('pwCurrent',this)">👁️</button>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">New Password <span class="req">*</span></label>
                                    <div class="pw-wrap">
                                        <input type="password" name="new_password" class="form-input"
                                               id="pwNew" placeholder="Minimum 8 characters"
                                               autocomplete="new-password" required
                                               oninput="checkStrength(this.value)">
                                        <button type="button" class="pw-eye" onclick="togglePw('pwNew',this)">👁️</button>
                                    </div>
                                    <div id="strengthWrap" style="display:none;">
                                        <div class="strength-bar">
                                            <div class="strength-fill" id="strengthFill"></div>
                                        </div>
                                        <div class="strength-text" id="strengthText"></div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Confirm New Password <span class="req">*</span></label>
                                    <div class="pw-wrap">
                                        <input type="password" name="confirm_password" class="form-input"
                                               id="pwConfirm" placeholder="Repeat new password"
                                               autocomplete="new-password" required
                                               oninput="checkMatch()">
                                        <button type="button" class="pw-eye" onclick="togglePw('pwConfirm',this)">👁️</button>
                                    </div>
                                    <div class="match-msg" id="matchMsg"></div>
                                </div>

                                <div class="form-footer" style="margin-top:4px;">
                                    <span class="last-updated">Use letters, numbers, and symbols.</span>
                                    <button type="submit" class="btn-primary">
                                        <span>🔑</span> Update Password
                                    </button>
                                </div>

                            </div>
                        </form>

                        <div class="divider"></div>

                        <!-- Account Info -->
                        <div class="card-title" style="margin-bottom:14px;">
                            <div class="card-title-icon">ℹ️</div>
                            Account Information
                        </div>
                        <div class="info-grid" style="margin-bottom:20px;">
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
                                <div class="info-box-value" style="color:#22543d;">
                                    <?php echo $user['is_active'] ? '✅ Active' : '❌ Inactive'; ?>
                                </div>
                            </div>
                            <div class="info-box">
                                <div class="info-box-label">Verified</div>
                                <div class="info-box-value" style="color:<?php echo $user['is_verified'] ? '#22543d' : '#c53030'; ?>">
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

                        <!-- Login History -->
                        <?php if (!empty($loginHistory)): ?>
                        <div class="card-title" style="margin-bottom:14px;">
                            <div class="card-title-icon">🔐</div>
                            Recent Login Activity
                        </div>
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
                        <div style="margin-top:6px;font-size:11px;color:var(--gray-400);text-align:right;">
                            Showing last <?php echo count($loginHistory); ?> successful logins
                        </div>
                        <?php endif; ?>

                    </div><!-- /tab-security -->

                </div><!-- /card-body -->
            </div><!-- /card -->
        </div>
        <!-- ══ END RIGHT ══ -->

    </div>
</div>

<script>
    // ── Profile dropdown ──────────────────────────────────────────────────
    function toggleDropdown() {
        const dd = document.getElementById('profileDropdown');
        const ov = document.getElementById('dropdownOverlay');
        dd.classList.toggle('show');
        ov.classList.toggle('show');
    }
    function closeDropdown() {
        document.getElementById('profileDropdown').classList.remove('show');
        document.getElementById('dropdownOverlay').classList.remove('show');
    }

    // ── Tabs ──────────────────────────────────────────────────────────────
    function switchTab(name, btn) {
        document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('tab-' + name).classList.add('active');
        btn.classList.add('active');
    }

    // ── Password eye toggle ───────────────────────────────────────────────
    function togglePw(id, btn) {
        const inp = document.getElementById(id);
        if (inp.type === 'password') { inp.type = 'text';     btn.textContent = '🙈'; }
        else                         { inp.type = 'password'; btn.textContent = '👁️'; }
    }

    // ── Password strength ─────────────────────────────────────────────────
    function checkStrength(val) {
        const wrap = document.getElementById('strengthWrap');
        const fill = document.getElementById('strengthFill');
        const text = document.getElementById('strengthText');
        if (!val) { wrap.style.display = 'none'; return; }
        wrap.style.display = 'block';
        let score = 0;
        if (val.length >= 8)          score++;
        if (/[A-Z]/.test(val))        score++;
        if (/[0-9]/.test(val))        score++;
        if (/[^A-Za-z0-9]/.test(val)) score++;
        const levels = [
            { w: '25%',  c: '#f56565', l: 'Weak'   },
            { w: '50%',  c: '#ed8936', l: 'Fair'   },
            { w: '75%',  c: '#ecc94b', l: 'Good'   },
            { w: '100%', c: '#48bb78', l: 'Strong' }
        ];
        const lvl         = levels[Math.max(score - 1, 0)];
        fill.style.width       = lvl.w;
        fill.style.background  = lvl.c;
        text.style.color       = lvl.c;
        text.textContent       = lvl.l;
    }

    // ── Password match ────────────────────────────────────────────────────
    function checkMatch() {
        const nw  = document.getElementById('pwNew').value;
        const cf  = document.getElementById('pwConfirm').value;
        const msg = document.getElementById('matchMsg');
        if (!cf) { msg.textContent = ''; return; }
        if (nw === cf) { msg.style.color = '#22543d'; msg.textContent = '✓ Passwords match'; }
        else           { msg.style.color = '#742a2a'; msg.textContent = '✗ Passwords do not match'; }
    }

    // ── Logout ────────────────────────────────────────────────────────────
    function confirmLogout() {
        closeDropdown();
        if (confirm('Are you sure you want to logout?')) window.location.href = 'logout.php';
    }

    // ── Animate bars on load ──────────────────────────────────────────────
    window.addEventListener('load', () => {
        document.querySelectorAll('.cat-fill').forEach(bar => {
            const w = bar.style.width;
            bar.style.width = '0';
            setTimeout(() => { bar.style.width = w; }, 250);
        });
    });

    // ── Auto-dismiss toast ────────────────────────────────────────────────
    const toast = document.getElementById('toast');
    if (toast) setTimeout(() => toast.classList.remove('show'), 4500);
</script>
</body>
</html>
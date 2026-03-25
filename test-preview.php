<?php
/* ============================================================
 * test-preview.php
 * Rewritten to match the actual live database schema.
 *
 * Key schema facts (from newdatabase.txt):
 *   assessments.status      : enum('draft','published','archived')
 *   assessments.visibility  : enum('public','group','private')
 *   assessments             : NO show_results_immediately / show_correct_answers
 *                             / instructions columns
 *   assessment_attempts.status : enum('in_progress','submitted','timeout')
 *   questions               : NO option_a/b/c/d — uses question_options table
 *   questions               : HAS negative_marks decimal(4,2)
 *   users.role              : enum('admin','teacher','student')  (not user_type)
 * ============================================================ */

require_once 'config.php';
require_once 'db-guard.php';

/* ── Auth ── */
$user   = validateSession($conn, 'student');
$userId = (int) $user['user_id'];

/* ── Validate ?id= ── */
$assessmentId = (int)($_GET['id'] ?? 0);
if ($assessmentId <= 0) {
    header('Location: student-dashboard.php?error=invalid_test');
    exit;
}

/* ── Fetch assessment ── */
$asmResult = safePreparedQuery($conn,
    "SELECT
        a.assessment_id,
        a.title,
        a.description,
        a.category,
        a.difficulty,
        a.duration_minutes,
        a.total_marks,
        a.passing_marks,
        a.max_attempts,
        a.start_time,
        a.end_time,
        a.randomize_questions,
        a.randomize_options,
        a.visibility,
        (SELECT COUNT(*) FROM questions q
         WHERE q.assessment_id = a.assessment_id) AS question_count,
        COALESCE(
            (SELECT MAX(q2.negative_marks) FROM questions q2
             WHERE q2.assessment_id = a.assessment_id), 0
        ) AS max_negative_marks
     FROM assessments a
     WHERE a.assessment_id = ?
       AND a.status = 'published'",
    "i", [$assessmentId]
);

if (!$asmResult['success'] || !$asmResult['result'] || $asmResult['result']->num_rows === 0) {
    header('Location: student-dashboard.php?error=test_not_found');
    exit;
}
$a = $asmResult['result']->fetch_assoc();
$asmResult['result']->free();

/* ── Access check for non-public assessments ── */
if ($a['visibility'] !== 'public') {
    $accessCheck = safePreparedQuery($conn,
        "SELECT 1 FROM assessment_targets at
         WHERE at.assessment_id = ?
           AND (
             (at.target_type = 'student' AND at.target_id = ?)
             OR
             (at.target_type = 'group'   AND at.target_id IN (
                 SELECT gm.group_id FROM group_members gm WHERE gm.student_id = ?
             ))
           )
         LIMIT 1",
        "iii", [$assessmentId, $userId, $userId]
    );
    $hasAccess = $accessCheck['success']
              && $accessCheck['result']
              && $accessCheck['result']->num_rows > 0;
    if ($accessCheck['result']) $accessCheck['result']->free();

    if (!$hasAccess) {
        header('Location: student-dashboard.php?error=access_denied');
        exit;
    }
}

/* ── Previous attempts by this student ── */
$prevResult = safePreparedQuery($conn,
    "SELECT score, percentage, submitted_at
     FROM assessment_attempts
     WHERE assessment_id = ? AND user_id = ? AND status = 'submitted'
     ORDER BY submitted_at DESC",
    "ii", [$assessmentId, $userId]
);

$previousAttempts = [];
if ($prevResult['success'] && $prevResult['result']) {
    while ($row = $prevResult['result']->fetch_assoc()) {
        $previousAttempts[] = $row;
    }
    $prevResult['result']->free();
}

$attemptsUsed = count($previousAttempts);
$attemptsLeft = max(0, (int)$a['max_attempts'] - $attemptsUsed);
$exhausted    = $attemptsLeft <= 0;

/* ── Derived display values ── */
$diff      = strtolower($a['difficulty']);
$diffLabel = ucfirst($diff) . ' Level';
$hasNeg    = (float)$a['max_negative_marks'] > 0;
$passPct   = $a['total_marks'] > 0
           ? round(((int)$a['passing_marks'] / (int)$a['total_marks']) * 100)
           : 0;

function fmtDt(?string $dt): string {
    return $dt ? date('d M Y, g:i A', strtotime($dt)) : '—';
}

/* ── User display info ── */
$userName     = $user['full_name'] ?? 'Student';
$userEmail    = $user['email']     ?? '';
$userInitials = strtoupper(substr($userName, 0, 2));

// Fetch profile image
$imgRes = safePreparedQuery($conn, "SELECT profile_image FROM users WHERE user_id = ?", "i", [$userId]);
$userProfileImage = '';
if ($imgRes['success'] && $imgRes['result']) {
    $imgRow = $imgRes['result']->fetch_assoc();
    $userProfileImage = $imgRow['profile_image'] ?? '';
    $imgRes['result']->free();
}

// Ensure CSRF token exists

// ── Report status ──
$reportStatusResult = safePreparedQuery($conn,
    "SELECT status FROM student_reports WHERE user_id = ? ORDER BY created_at DESC LIMIT 1",
    "i", [$userId]
);
$latestReportStatus = null;
if ($reportStatusResult['success'] && $reportStatusResult['result']) {
    $rrow = $reportStatusResult['result']->fetch_assoc();
    $latestReportStatus = $rrow['status'] ?? null;
    $reportStatusResult['result']->free();
}
$hasOpenReport = in_array($latestReportStatus, ['pending', 'in_progress']);

// ── Handle report submission ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_report') {
    $reportTitle = trim($_POST['report_title'] ?? '');
    $reportDesc  = trim($_POST['report_description'] ?? '');
    $reportImage = null;
    if (!empty($_FILES['report_image']) && $_FILES['report_image']['error'] === UPLOAD_ERR_OK) {
        $file    = $_FILES['report_image'];
        $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
        if ($file['size'] <= 5*1024*1024 && in_array($file['type'], $allowed)) {
            $ext       = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $uploadDir = 'uploads/reports/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $stored    = 'report_'.$userId.'_'.time().'.'.$ext;
            $fullPath  = $uploadDir.$stored;
            if (move_uploaded_file($file['tmp_name'], $fullPath)) $reportImage = $fullPath;
        }
    }
    if ($reportTitle !== '' && $reportDesc !== '') {
        safePreparedQuery($conn,
            "INSERT INTO student_reports (user_id, title, description, image_path, status, created_at) VALUES (?,?,?,?,'pending',NOW())",
            "isss", [$userId, $reportTitle, $reportDesc, $reportImage]
        );
        $hasOpenReport = true; $latestReportStatus = 'pending';
    }
    header('Location: '.$_SERVER['PHP_SELF'].'?report=sent');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ── Unread notification count ── */
$notifResult = safePreparedQuery($conn,
    "SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0",
    "i", [$userId]
);
$unreadCount = 0;
if ($notifResult['success'] && $notifResult['result']) {
    $notifRow    = $notifResult['result']->fetch_assoc();
    $unreadCount = (int)($notifRow['cnt'] ?? 0);
    $notifResult['result']->free();
}

/* ── All notifications for dropdown ── */
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

function timeAgoPreview(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff / 60)   . ' min ago';
    if ($diff < 86400)  return floor($diff / 3600)  . ' hr ago';
    if ($diff < 604800) return floor($diff / 86400) . ' day ago';
    return date('d M Y', strtotime($datetime));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($a['title']) ?> - Preview</title>
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
            --transition:    .2s cubic-bezier(.4,0,.2,1);
        }

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding-top: var(--nav-h);
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
        }

        /* ══ NAVBAR ══ */
        .navbar {
            background: var(--primary);
            padding: 0 28px; height: var(--nav-h);
            display: flex; align-items: center; justify-content: space-between;
            position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
            box-shadow: 0 1px 0 rgba(255,255,255,.06), 0 4px 20px rgba(0,0,0,.18);
        }
        .navbar-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; }
        .btn-back {
            padding: 9px 20px;
            background: rgba(255,255,255,.12);
            color: white; border: 1.5px solid rgba(255,255,255,.25);
            border-radius: var(--radius-sm);
            font-family: 'Inter', sans-serif; font-weight: 600; font-size: 13.5px;
            cursor: pointer; transition: var(--transition); text-decoration: none;
            display: flex; align-items: center; gap: 8px;
        }
        .btn-back:hover { background: rgba(255,255,255,.22); border-color: rgba(255,255,255,.4); }

        /* ══ NOTIFICATION DROPDOWN ══ */
        .nav-profile { display: flex; align-items: center; gap: 10px; }
        .notification-icon {
            position: relative; width: 38px; height: 38px;
            background: rgba(255,255,255,.12); border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; border: 1.5px solid rgba(255,255,255,.15);
            transition: var(--transition); color: white;
        }
        .notification-icon:hover { background: rgba(255,255,255,.2); border-color: rgba(255,255,255,.3); }
        .notif-dropdown-wrap { position: relative; }
        .notif-dropdown {
            position: absolute; top: calc(100% + 12px); right: 0;
            background: var(--surface); border-radius: var(--radius);
            box-shadow: var(--shadow-md); border: 1px solid var(--border);
            width: 348px; opacity: 0; visibility: hidden;
            transform: translateY(-6px) scale(.98); transition: var(--transition); z-index: 1002;
        }
        .notif-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
        .notif-dropdown-header {
            padding: 16px 20px 14px;
            font-family: 'Sora', sans-serif; font-weight: 700; font-size: 14px; color: var(--text);
            border-bottom: 1px solid var(--border);
        }
        .notif-list { max-height: 360px; overflow-y: auto; scrollbar-width: thin; scrollbar-color: var(--border) transparent; }
        .notif-list::-webkit-scrollbar { width: 4px; }
        .notif-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 10px; }
        .notif-item {
            display: flex; gap: 12px; align-items: flex-start;
            padding: 13px 20px; border-bottom: 1px solid var(--border);
            cursor: pointer; transition: background var(--transition);
        }
        .notif-item:last-child { border-bottom: none; }
        .notif-item:hover { background: var(--surface2); }
        .notif-item.unread { background: #eff8ff; }
        .notif-item.unread:hover { background: #e0f2fe; }
        .notif-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--accent); flex-shrink: 0; margin-top: 5px; }
        .notif-dot.read { background: transparent; }
        .notif-item-body { flex: 1; }
        .notif-item-title { font-size: 13px; font-weight: 600; color: var(--text); margin-bottom: 2px; }
        .notif-item-msg   { font-size: 12px; color: var(--text-mid); line-height: 1.45; }
        .notif-item-time  { font-size: 11px; color: var(--text-soft); margin-top: 4px; }
        .notif-empty { padding: 32px 20px; text-align: center; color: var(--text-soft); font-size: 13px; }
        .notif-dismiss-btn {
            background: none; border: none; color: var(--text-soft);
            font-size: 13px; line-height: 1; padding: 2px 5px;
            border-radius: 4px; cursor: pointer; flex-shrink: 0;
            opacity: 0; transition: opacity .15s, background .15s, color .15s;
            align-self: flex-start; margin-top: 2px;
        }
        .notif-item:hover .notif-dismiss-btn { opacity: 1; }
        .notif-dismiss-btn:hover { background: rgba(239,68,68,.1); color: #ef4444; }
        .notification-badge {
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
        /* Profile button */
        .profile-button {
            display: flex; align-items: center; gap: 9px;
            padding: 6px 12px 6px 6px;
            background: rgba(255,255,255,.12);
            border: 1.5px solid rgba(255,255,255,.15);
            border-radius: 10px; cursor: pointer; transition: var(--transition);
        }
        .profile-button:hover { background: rgba(255,255,255,.2); border-color: rgba(255,255,255,.3); }
        .profile-avatar {
            width: 32px; height: 32px;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            border-radius: 8px; display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 13px; font-family: 'Sora', sans-serif;
        }
        .profile-name { font-weight: 600; font-size: 13.5px; color: rgba(255,255,255,.95); }
        .dropdown-arrow { font-size: 10px; color: rgba(255,255,255,.6); }
        .profile-dropdown-container { position: relative; }
        .profile-dropdown {
            position: absolute; top: calc(100% + 12px); right: 0;
            background: var(--surface); border-radius: var(--radius);
            box-shadow: var(--shadow-md); border: 1px solid var(--border);
            min-width: 240px; opacity: 0; visibility: hidden;
            transform: translateY(-6px) scale(.98); transition: var(--transition);
            z-index: 1001; overflow: hidden;
        }
        .profile-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
        .dropdown-header {
            padding: 18px 20px;
            background: linear-gradient(135deg, var(--primary), var(--primary-mid));
            display: flex; gap: 12px; align-items: center;
        }
        .dropdown-avatar {
            width: 44px; height: 44px;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            border-radius: 12px; display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 800; font-size: 18px;
            font-family: 'Sora', sans-serif; flex-shrink: 0;
        }
        .dropdown-user-info { flex: 1; overflow: hidden; }
        .dropdown-user-name { font-family: 'Sora', sans-serif; font-weight: 700; font-size: 15px; color: white; margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .dropdown-user-email { font-size: 12px; color: rgba(255,255,255,.65); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .dropdown-menu { padding: 8px; }
        .dropdown-item {
            display: flex; align-items: center; gap: 11px;
            padding: 10px 12px; border-radius: 8px;
            color: var(--text-mid); text-decoration: none;
            cursor: pointer; border: none; background: none;
            width: 100%; text-align: left; font-size: 13.5px;
            font-family: 'Inter', sans-serif; transition: var(--transition);
        }
        .dropdown-item:hover { background: var(--surface2); color: var(--text); }
        .dropdown-item-icon { font-size: 16px; width: 20px; text-align: center; flex-shrink: 0; }
        .dropdown-divider { height: 1px; background: var(--border); margin: 6px 8px; }
        .dropdown-item.logout { color: var(--danger); }
        .dropdown-item.logout:hover { background: #fef2f2; }
        .dropdown-overlay { position: fixed; inset: 0; background: transparent; z-index: 999; display: none; }
        .dropdown-overlay.show { display: block; }

        /* ══ CONTAINER ══ */
        .container { max-width: 860px; margin: 0 auto; padding: 32px 24px 60px; }

        /* ══ TEST HEADER ══ */
        .test-header {
            background: linear-gradient(135deg, var(--primary) 0%, #1e5276 60%, #1a6fa0 100%);
            border-radius: var(--radius);
            padding: 40px 36px 36px;
            margin-bottom: 24px;
            text-align: center;
            position: relative; overflow: hidden;
            box-shadow: 0 4px 24px rgba(26,58,82,.3);
            animation: fadeUp .4s ease both;
        }
        .test-header::before {
            content: ''; position: absolute; top: -60px; right: -60px;
            width: 220px; height: 220px; border-radius: 50%;
            background: rgba(255,255,255,.05); pointer-events: none;
        }
        .test-header::after {
            content: ''; position: absolute; bottom: -80px; left: 80px;
            width: 180px; height: 180px; border-radius: 50%;
            background: rgba(14,165,233,.08); pointer-events: none;
        }
        .test-icon {
            width: 76px; height: 76px;
            background: rgba(255,255,255,.15);
            border: 2px solid rgba(255,255,255,.25);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 20px; font-size: 34px;
            backdrop-filter: blur(8px);
            position: relative; z-index: 1;
        }
        .test-title {
            font-family: 'Sora', sans-serif;
            font-size: 28px; font-weight: 800; color: white;
            margin-bottom: 8px; letter-spacing: -.3px;
            position: relative; z-index: 1;
        }
        .test-category { font-size: 14px; color: rgba(255,255,255,.7); margin-bottom: 18px; position: relative; z-index: 1; }
        .difficulty-badge {
            display: inline-block; padding: 6px 18px;
            border-radius: 20px; font-family: 'Sora', sans-serif;
            font-size: 12px; font-weight: 700; margin-bottom: 28px;
            position: relative; z-index: 1;
        }
        .difficulty-badge.easy   { background: #dcfce7; color: #166534; }
        .difficulty-badge.medium { background: #fef3c7; color: #92400e; }
        .difficulty-badge.hard   { background: #fee2e2; color: #991b1b; }

        .test-quick-stats {
            display: grid; grid-template-columns: repeat(4,1fr); gap: 14px;
            margin-top: 4px; position: relative; z-index: 1;
        }
        .quick-stat {
            padding: 16px 12px;
            background: rgba(255,255,255,.1);
            border: 1px solid rgba(255,255,255,.15);
            border-radius: 12px; text-align: center;
            backdrop-filter: blur(8px); transition: var(--transition);
        }
        .quick-stat:hover { background: rgba(255,255,255,.18); }
        .quick-stat-icon  { font-size: 24px; margin-bottom: 8px; }
        .quick-stat-value { font-family: 'Sora', sans-serif; font-size: 22px; font-weight: 800; color: white; margin-bottom: 4px; }
        .quick-stat-label { font-size: 11.5px; color: rgba(255,255,255,.65); }

        /* ══ CARDS ══ */
        .card {
            background: var(--surface); border-radius: var(--radius);
            padding: 28px; box-shadow: var(--shadow);
            border: 1px solid var(--border); margin-bottom: 20px;
            animation: fadeUp .4s ease both;
        }
        .section-title {
            font-family: 'Sora', sans-serif;
            font-size: 17px; font-weight: 700; color: var(--text);
            margin-bottom: 18px; display: flex; align-items: center; gap: 10px;
        }
        .description-text { font-size: 14.5px; color: var(--text-mid); line-height: 1.8; margin-bottom: 20px; }

        .btn-instructions {
            padding: 10px 20px;
            background: var(--surface2); color: var(--accent);
            border: 1.5px solid var(--accent); border-radius: var(--radius-sm);
            font-family: 'Inter', sans-serif; font-weight: 600; font-size: 13.5px;
            cursor: pointer; transition: var(--transition);
            display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-instructions:hover { background: var(--accent); color: white; box-shadow: 0 4px 12px rgba(14,165,233,.3); }

        /* Info grid */
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .info-row {
            display: flex; align-items: flex-start; gap: 12px;
            background: var(--surface2); border-radius: var(--radius-sm);
            padding: 14px 16px; border: 1px solid var(--border);
        }
        .info-row-icon { font-size: 20px; flex-shrink: 0; margin-top: 2px; }
        .info-row-label {
            font-size: 10.5px; font-weight: 700; color: var(--text-soft);
            text-transform: uppercase; letter-spacing: .06em; margin-bottom: 3px;
        }
        .info-row-value { font-size: 13.5px; font-weight: 600; color: var(--text); }

        /* Tags */
        .tag { display: inline-block; padding: 3px 10px; border-radius: 6px; font-size: 11.5px; font-weight: 700; font-family: 'Sora', sans-serif; }
        .tag-yes  { background: #dcfce7; color: #166534; }
        .tag-no   { background: #f1f5f9; color: var(--text-soft); }
        .tag-warn { background: #fee2e2; color: #991b1b; }
        .tag-info { background: #e0f2fe; color: #075985; }

        /* Previous attempts */
        .attempt-row {
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 10px;
            background: var(--surface2); border: 1px solid var(--border);
            border-radius: var(--radius-sm); padding: 13px 16px;
            margin-bottom: 10px; font-size: 13.5px;
        }
        .attempt-row:last-child { margin-bottom: 0; }
        .pct-pass { background: #dcfce7; color: #166534; }
        .pct-fail { background: #fee2e2; color: #991b1b; }

        /* ══ ACTION SECTION ══ */
        .action-section {
            background: var(--surface); border-radius: var(--radius);
            padding: 40px 36px; box-shadow: var(--shadow);
            border: 1px solid var(--border);
            text-align: center; animation: fadeUp .4s .2s ease both;
        }
        .btn-start-test {
            padding: 16px 56px;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            color: white; border: none; border-radius: var(--radius-sm);
            font-family: 'Sora', sans-serif; font-weight: 800; font-size: 18px;
            cursor: pointer; transition: var(--transition);
            box-shadow: 0 4px 20px rgba(14,165,233,.4);
            display: inline-flex; align-items: center; gap: 12px;
        }
        .btn-start-test:hover { transform: translateY(-3px); box-shadow: 0 8px 28px rgba(14,165,233,.5); }
        .btn-start-test:disabled { opacity: .5; cursor: not-allowed; transform: none; box-shadow: none; }
        .action-note { font-size: 13.5px; color: var(--text-soft); margin-top: 18px; }
        .attempts-left-note {
            display: inline-block; margin-top: 12px;
            background: #e0f2fe; color: #075985;
            padding: 5px 16px; border-radius: 20px;
            font-size: 13px; font-weight: 600;
        }

        /* ══ MODALS ══ */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.65); z-index: 2000;
            align-items: center; justify-content: center;
            overflow-y: auto; padding: 20px;
        }
        .modal-overlay.active { display: flex; }
        .modal-content {
            background: var(--surface); border-radius: var(--radius);
            padding: 36px; max-width: 680px; width: 100%;
            max-height: 90vh; overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,.25);
            animation: fadeUp .25s ease both;
        }
        .modal-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 24px;
        }
        .modal-title { font-family: 'Sora', sans-serif; font-size: 22px; font-weight: 800; color: var(--text); }
        .btn-close-modal {
            width: 34px; height: 34px; background: var(--surface2); border: 1px solid var(--border);
            border-radius: 8px; font-size: 18px; cursor: pointer;
            transition: var(--transition); display: flex; align-items: center; justify-content: center;
        }
        .btn-close-modal:hover { background: var(--border); transform: rotate(90deg); }

        .instructions-list { display: flex; flex-direction: column; gap: 14px; }
        .instruction-item {
            display: flex; gap: 14px; padding: 14px;
            background: var(--surface2); border-radius: var(--radius-sm);
            border: 1px solid var(--border);
        }
        .instruction-number {
            width: 28px; height: 28px;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 13px; flex-shrink: 0;
        }
        .instruction-text { flex: 1; font-size: 13.5px; color: var(--text-mid); line-height: 1.6; }

        .important-notes {
            margin-top: 22px; padding: 18px;
            background: #fefce8; border-radius: var(--radius-sm);
            border-left: 4px solid var(--warning);
        }
        .notes-title { font-family: 'Sora', sans-serif; font-size: 14px; font-weight: 700; color: #92400e; margin-bottom: 10px; }
        .notes-list  { display: flex; flex-direction: column; gap: 7px; }
        .note-item   { font-size: 13.5px; color: #92400e; display: flex; gap: 8px; }

        /* Confirm modal */
        .confirm-modal {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.65); z-index: 2001;
            align-items: center; justify-content: center;
        }
        .confirm-modal.active { display: flex; }
        .confirm-content {
            background: var(--surface); border-radius: var(--radius);
            padding: 40px; max-width: 480px; width: 90%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,.25);
            animation: fadeUp .25s ease both;
        }
        .confirm-icon    { font-size: 60px; margin-bottom: 18px; }
        .confirm-title   { font-family: 'Sora', sans-serif; font-size: 22px; font-weight: 800; color: var(--text); margin-bottom: 14px; }
        .confirm-message { font-size: 14.5px; color: var(--text-mid); margin-bottom: 28px; line-height: 1.7; }
        .modal-buttons   { display: flex; gap: 14px; justify-content: center; }
        .modal-btn {
            padding: 11px 28px; border: none; border-radius: var(--radius-sm);
            font-family: 'Inter', sans-serif; font-weight: 700; font-size: 14px;
            cursor: pointer; transition: var(--transition);
        }
        .modal-btn.primary   { background: linear-gradient(135deg, var(--accent), var(--accent2)); color: white; box-shadow: 0 2px 8px rgba(14,165,233,.3); }
        .modal-btn.primary:hover { transform: translateY(-2px); box-shadow: 0 4px 14px rgba(14,165,233,.45); }
        .modal-btn.secondary { background: var(--surface2); color: var(--text-mid); border: 1.5px solid var(--border); }
        .modal-btn.secondary:hover { background: var(--border); color: var(--text); }
        .modal-btn:disabled  { opacity: .6; cursor: not-allowed; transform: none; }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 768px) {
            .navbar { padding: 0 16px; }
            .container { padding: 20px 16px 60px; }
            .test-header { padding: 28px 20px; }
            .test-title  { font-size: 22px; }
            .test-quick-stats { grid-template-columns: repeat(2,1fr); gap: 10px; }
            .info-grid   { grid-template-columns: 1fr; }
            .action-section { padding: 28px 20px; }
            .btn-start-test { width: 100%; justify-content: center; }
            .attempt-row { flex-direction: column; align-items: flex-start; }
        }
    
        /* ── Report status dot ── */
        .report-status-dot {
            width:11px;height:11px;border-radius:50%;background:#ef4444;
            border:2px solid var(--primary);display:inline-block;flex-shrink:0;
            animation:reportPulse 2s ease-in-out infinite;
        }
        .report-status-dot.resolved{background:#10b981;animation:none;}
        @keyframes reportPulse{0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.5);}60%{box-shadow:0 0 0 6px rgba(239,68,68,0);}}
        .report-dot-wrap {
            display:flex;align-items:center;gap:7px;padding:6px 10px;
            background:rgba(255,255,255,.1);border:1.5px solid rgba(255,255,255,.15);
            border-radius:9px;cursor:pointer;transition:var(--transition);
            font-size:11px;font-weight:600;color:rgba(255,255,255,.8);
        }
        .report-dot-wrap:hover{background:rgba(255,255,255,.18);}
        /* ── Report Modal ── */
        .report-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9100;
            display:flex;align-items:center;justify-content:center;backdrop-filter:blur(4px);
            opacity:0;visibility:hidden;transition:opacity .25s,visibility .25s;}
        .report-modal-overlay.open{opacity:1;visibility:visible;}
        .report-modal{background:#fff;border-radius:20px;width:100%;max-width:500px;margin:16px;
            box-shadow:0 24px 64px rgba(0,0,0,.22);overflow:hidden;
            transform:translateY(18px) scale(.97);transition:transform .28s cubic-bezier(.4,0,.2,1);}
        .report-modal-overlay.open .report-modal{transform:translateY(0) scale(1);}
        .report-modal-header{background:linear-gradient(135deg,#1a3a52,#1e5276);padding:22px 24px 18px;
            display:flex;align-items:flex-start;justify-content:space-between;}
        .report-modal-title{font-family:'Sora',sans-serif;font-size:17px;font-weight:800;color:#fff;margin-bottom:4px;}
        .report-modal-sub{font-size:12px;color:rgba(255,255,255,.6);}
        .report-modal-close{background:rgba(255,255,255,.15);border:none;border-radius:8px;color:#fff;
            width:30px;height:30px;font-size:16px;cursor:pointer;display:flex;align-items:center;
            justify-content:center;flex-shrink:0;transition:background .15s;margin-left:12px;}
        .report-modal-close:hover{background:rgba(255,255,255,.28);}
        .report-modal-body{padding:24px;display:flex;flex-direction:column;gap:16px;}
        .report-field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;
            letter-spacing:.06em;color:#64748b;margin-bottom:6px;}
        .report-field label span{color:#ef4444;margin-left:2px;}
        .report-field input,.report-field textarea{width:100%;padding:11px 14px;border:1.5px solid #e2e8f0;
            border-radius:10px;font-family:'Inter',sans-serif;font-size:13.5px;color:#0f172a;
            outline:none;transition:border-color .15s,box-shadow .15s;resize:vertical;}
        .report-field input:focus,.report-field textarea:focus{border-color:#0ea5e9;box-shadow:0 0 0 3px rgba(14,165,233,.15);}
        .report-drop-zone{border:2px dashed #cbd5e1;border-radius:12px;padding:20px;text-align:center;
            cursor:pointer;background:#f8fafc;transition:border-color .2s,background .2s;}
        .report-drop-zone:hover,.report-drop-zone.dragover{border-color:#0ea5e9;background:#eff8ff;}
        .report-drop-zone .dz-icon{font-size:28px;margin-bottom:6px;}
        .report-drop-zone .dz-text{font-size:13.5px;font-weight:600;color:#475569;}
        .report-drop-zone .dz-sub{font-size:12px;color:#94a3b8;margin-top:3px;}
        .report-img-preview{max-width:100%;max-height:140px;border-radius:8px;object-fit:contain;display:none;margin:8px auto 0;}
        .report-modal-footer{padding:0 24px 22px;display:flex;gap:10px;}
        .btn-report-cancel{flex:1;padding:11px;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;
            color:#475569;font-size:13.5px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;transition:.15s;}
        .btn-report-cancel:hover{background:#f1f5f9;}
        .btn-report-submit{flex:1;padding:11px;border-radius:10px;border:none;
            background:linear-gradient(135deg,#0ea5e9,#06b6d4);color:#fff;font-size:13.5px;font-weight:700;
            cursor:pointer;font-family:'Inter',sans-serif;transition:.15s;}
        .btn-report-submit:hover{opacity:.9;}
</style>
</head>
<body>

<nav class="navbar">
    <a href="student-dashboard.php" class="navbar-brand">
        <img src="prepaura-logo.png" alt="Prepaura Logo" style="width:44px;height:44px;border-radius:10px;object-fit:contain;background:white;padding:3px;">
        <div style="display:flex;flex-direction:column;line-height:1.15;">
            <span style="font-family:'Sora',sans-serif;font-size:17px;font-weight:800;letter-spacing:.5px;color:white;">PREPAURA</span>
            <span style="font-size:10.5px;font-weight:400;color:rgba(255,255,255,.65);letter-spacing:.02em;">Placement Training Platform</span>
        </div>
    </a>
    <div class="nav-profile">
        <a href="student-assessments.php" class="btn-back">← Back to Assessments</a>

        <!-- Notification bell -->
        <div class="notif-dropdown-wrap">
            <button class="notification-icon" onclick="toggleNotifDropdown()" id="notifBtn">
                <span>🔔</span>
                <?php if ($unreadCount > 0): ?>
                <div class="notification-badge"><?= $unreadCount ?></div>
                <?php endif; ?>
            </button>
            <div class="notif-dropdown" id="notifDropdown">
                <div class="notif-dropdown-header">Notifications</div>
                <div class="notif-list">
                    <?php if (empty($notifItems)): ?>
                        <div class="notif-empty">No notifications yet.</div>
                    <?php else: foreach ($notifItems as $n):
                        $isUnread = !$n['is_read'];
                        $typeIcons = ['info'=>'ℹ️','success'=>'✅','warning'=>'⚠️','error'=>'❌','assessment'=>'📝','result'=>'🏆','material'=>'📚'];
                        $icon = $typeIcons[$n['type']] ?? '🔔';
                    ?>
                    <div class="notif-item <?= $isUnread ? 'unread' : '' ?>" id="notif-<?= (int)$n['notification_id'] ?>">
                        <div class="notif-dot <?= $isUnread ? '' : 'read' ?>"></div>
                        <div class="notif-item-body">
                            <div class="notif-item-title"><?= $icon ?> <?= htmlspecialchars($n['title']) ?></div>
                            <?php if ($n['message']): ?>
                            <div class="notif-item-msg"><?= htmlspecialchars($n['message']) ?></div>
                            <?php endif; ?>
                            <div class="notif-item-time"><?= timeAgoPreview($n['created_at']) ?></div>
                        </div>
                        <button class="notif-dismiss-btn" onclick="event.stopPropagation(); dismissNotification(<?= (int)$n['notification_id'] ?>)" title="Dismiss">✕</button>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <!-- Profile dropdown -->
        <div class="profile-dropdown-container">
            <button class="profile-button" onclick="toggleProfileDropdown()">
                <?php if ($userProfileImage && file_exists($userProfileImage)): ?>
                    <img src="<?= htmlspecialchars($userProfileImage) ?>?v=<?= time() ?>" alt="Avatar" style="width:32px;height:32px;border-radius:8px;object-fit:cover;flex-shrink:0;">
                <?php else: ?>
                    <div class="profile-avatar"><?= htmlspecialchars($userInitials) ?></div>
                <?php endif; ?>
                <span class="profile-name"><?= htmlspecialchars($userName) ?></span>
                <span class="dropdown-arrow">▼</span>
            </button>
            <div class="profile-dropdown" id="profileDropdown">
                <div class="dropdown-header">
                    <?php if ($userProfileImage && file_exists($userProfileImage)): ?>
                        <img src="<?= htmlspecialchars($userProfileImage) ?>?v=<?= time() ?>" alt="Avatar" style="width:44px;height:44px;border-radius:12px;object-fit:cover;">
                    <?php else: ?>
                        <div class="dropdown-avatar"><?= htmlspecialchars($userInitials) ?></div>
                    <?php endif; ?>
                    <div class="dropdown-user-info">
                        <div class="dropdown-user-name"><?= htmlspecialchars($userName) ?></div>
                        <div class="dropdown-user-email"><?= htmlspecialchars($userEmail) ?></div>
                    </div>
                </div>
                <div class="dropdown-menu">
                    <a href="student-dashboard.php" class="dropdown-item">
                        <span class="dropdown-item-icon">🏠</span><span>Dashboard</span>
                    </a>
                    <a href="student-profile.php" class="dropdown-item">
                        <span class="dropdown-item-icon">👤</span><span>My Profile</span>
                    </a>
                    <button onclick="openReportModal(); closeProfileDropdown();" class="dropdown-item" style="background:none;border:none;width:100%;text-align:left;cursor:pointer;">
                        <span class="dropdown-item-icon">🚩</span><span>Help &amp; Support</span>
                    </button>
                    <div class="dropdown-divider"></div>
                    <button onclick="handleLogout()" class="dropdown-item logout">
                        <span class="dropdown-item-icon">🚪</span><span>Logout</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</nav>

<div class="dropdown-overlay" id="dropdownOverlay" onclick="closeAllDropdowns()"></div>

<div class="container">

    <!-- Test Header -->
    <div class="test-header">
        <div class="test-icon">📝</div>
        <h1 class="test-title"><?= htmlspecialchars($a['title']) ?></h1>
        <p class="test-category"><?= htmlspecialchars(ucfirst($a['category'] ?? 'General')) ?></p>
        <span class="difficulty-badge <?= $diff ?>"><?= $diffLabel ?></span>
        <div class="test-quick-stats">
            <div class="quick-stat">
                <div class="quick-stat-icon">❓</div>
                <div class="quick-stat-value"><?= (int)$a['question_count'] ?></div>
                <div class="quick-stat-label">Questions</div>
            </div>
            <div class="quick-stat">
                <div class="quick-stat-icon">⏱️</div>
                <div class="quick-stat-value"><?= (int)$a['duration_minutes'] ?></div>
                <div class="quick-stat-label">Minutes</div>
            </div>
            <div class="quick-stat">
                <div class="quick-stat-icon">🎯</div>
                <div class="quick-stat-value"><?= (int)$a['total_marks'] ?></div>
                <div class="quick-stat-label">Total Marks</div>
            </div>
            <div class="quick-stat">
                <div class="quick-stat-icon">✅</div>
                <div class="quick-stat-value"><?= (int)$a['passing_marks'] ?></div>
                <div class="quick-stat-label">Pass Marks</div>
            </div>
        </div>
    </div>

    <!-- Description -->
    <div class="card">
        <h2 class="section-title">📋 About This Test</h2>
        <p class="description-text">
            <?php if (!empty(trim($a['description'] ?? ''))): ?>
                <?= nl2br(htmlspecialchars($a['description'])) ?>
            <?php else: ?>
                This is a <?= $diff ?> level
                <?= htmlspecialchars(strtolower($a['category'] ?? 'general')) ?>
                assessment consisting of <?= (int)$a['question_count'] ?> questions
                to be completed in <?= (int)$a['duration_minutes'] ?> minutes.
            <?php endif ?>
        </p>
        <button class="btn-instructions" onclick="showInstructions()">📖 View Detailed Instructions</button>
    </div>

    <!-- Test Info -->
    <div class="card">
        <h2 class="section-title">ℹ️ Test Information</h2>
        <div class="info-grid">

            <div class="info-row">
                <div class="info-row-icon">📅</div>
                <div>
                    <div class="info-row-label">Available From</div>
                    <div class="info-row-value">
                        <?= $a['start_time']
                            ? fmtDt($a['start_time'])
                            : '<span style="color:#10b981;font-weight:700">Open</span>' ?>
                    </div>
                </div>
            </div>

            <div class="info-row">
                <div class="info-row-icon">⏰</div>
                <div>
                    <div class="info-row-label">Deadline</div>
                    <div class="info-row-value">
                        <?= $a['end_time']
                            ? fmtDt($a['end_time'])
                            : '<span style="color:#10b981;font-weight:700">No deadline</span>' ?>
                    </div>
                </div>
            </div>

            <div class="info-row">
                <div class="info-row-icon">🔄</div>
                <div>
                    <div class="info-row-label">Attempts Allowed</div>
                    <div class="info-row-value">
                        <?= (int)$a['max_attempts'] ?> total &nbsp;
                        <span class="tag <?= $attemptsLeft > 0 ? 'tag-info' : 'tag-warn' ?>">
                            <?= $attemptsLeft > 0 ? "{$attemptsLeft} left" : 'None left' ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="info-row">
                <div class="info-row-icon">🎓</div>
                <div>
                    <div class="info-row-label">Passing Criteria</div>
                    <div class="info-row-value">
                        <?= (int)$a['passing_marks'] ?> / <?= (int)$a['total_marks'] ?> marks (<?= $passPct ?>%)
                    </div>
                </div>
            </div>

            <div class="info-row">
                <div class="info-row-icon">🔀</div>
                <div>
                    <div class="info-row-label">Question Order</div>
                    <div class="info-row-value">
                        <span class="tag <?= $a['randomize_questions'] ? 'tag-warn' : 'tag-no' ?>">
                            <?= $a['randomize_questions'] ? 'Randomised' : 'Fixed order' ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="info-row">
                <div class="info-row-icon">➖</div>
                <div>
                    <div class="info-row-label">Negative Marking</div>
                    <div class="info-row-value">
                        <span class="tag <?= $hasNeg ? 'tag-warn' : 'tag-yes' ?>">
                            <?= $hasNeg ? '⚠️ Yes — marks deducted' : 'No penalty' ?>
                        </span>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Previous Attempts -->
    <?php if (!empty($previousAttempts)): ?>
    <div class="card">
        <h2 class="section-title">📜 Your Previous Attempts</h2>
        <?php foreach ($previousAttempts as $i => $pa):
            $pct    = round((float)$pa['percentage']);
            $passed = $pct >= $passPct;
            $num    = $attemptsUsed - $i;
        ?>
        <div class="attempt-row">
            <span style="font-weight:700;font-family:'Sora',sans-serif;">Attempt #<?= $num ?></span>
            <span style="color:var(--text-soft)">📅 <?= fmtDt($pa['submitted_at']) ?></span>
            <span style="color:var(--text-mid)">Score: <strong><?= round($pa['score']) ?> / <?= (int)$a['total_marks'] ?></strong></span>
            <span class="tag <?= $passed ? 'pct-pass' : 'pct-fail' ?>"><?= $pct ?>%</span>
        </div>
        <?php endforeach ?>
    </div>
    <?php endif ?>

    <!-- Action -->
    <div class="action-section">
        <?php if ($exhausted): ?>
            <p style="font-size:48px;margin-bottom:16px">🔒</p>
            <p style="font-family:'Sora',sans-serif;font-weight:800;font-size:16px;color:var(--danger);margin-bottom:8px">
                You have used all <?= (int)$a['max_attempts'] ?> attempt(s) for this test.
            </p>
            <?php if (!empty($previousAttempts)): ?>
            <p style="color:var(--text-soft);font-size:14px;margin-top:6px">
                Best score: <strong><?= max(array_column($previousAttempts, 'percentage')) ?>%</strong>
            </p>
            <?php endif ?>
        <?php else: ?>
            <button class="btn-start-test" id="startBtn" onclick="confirmStart()">
                🚀 Start Test
            </button>
            <p class="action-note">💡 The timer will begin immediately when you start</p>
            <?php if ((int)$a['max_attempts'] > 1): ?>
            <div class="attempts-left-note">
                🔄 <?= $attemptsLeft ?> of <?= (int)$a['max_attempts'] ?> attempt(s) remaining
            </div>
            <?php endif ?>
        <?php endif ?>
    </div>

</div><!-- /.container -->

<!-- Instructions Modal -->
<div class="modal-overlay" id="instructionsModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">📖 Test Instructions</h2>
            <button class="btn-close-modal" onclick="closeInstructions()">✕</button>
        </div>
        <div class="instructions-list">
            <div class="instruction-item">
                <div class="instruction-number">1</div>
                <div class="instruction-text"><strong>Read Carefully:</strong> Read each question and all answer options thoroughly before selecting your answer.</div>
            </div>
            <div class="instruction-item">
                <div class="instruction-number">2</div>
                <div class="instruction-text"><strong>Time Management:</strong> You have <strong><?= (int)$a['duration_minutes'] ?> minutes</strong> to complete all <strong><?= (int)$a['question_count'] ?> questions</strong>. A timer will be visible at the top of your screen.</div>
            </div>
            <div class="instruction-item">
                <div class="instruction-number">3</div>
                <div class="instruction-text"><strong>Attempts:</strong>
                    <?= (int)$a['max_attempts'] === 1
                        ? 'This test allows <strong>one attempt only</strong>. You cannot retake it once started.'
                        : 'You have <strong>' . (int)$a['max_attempts'] . ' attempts</strong> allowed for this test.' ?>
                </div>
            </div>
            <div class="instruction-item">
                <div class="instruction-number">4</div>
                <div class="instruction-text"><strong>Navigation:</strong> Use "Previous" and "Next" buttons to move between questions. You can also jump directly to any question using the question palette.</div>
            </div>
            <div class="instruction-item">
                <div class="instruction-number">5</div>
                <div class="instruction-text"><strong>Scoring:</strong>
                    <?php if ($hasNeg): ?>
                        Correct answers earn marks. <strong>Wrong answers carry negative marks</strong> — avoid blind guessing.
                    <?php else: ?>
                        Each correct answer earns you points. There is no negative marking for incorrect answers.
                    <?php endif ?>
                </div>
            </div>
            <div class="instruction-item">
                <div class="instruction-number">6</div>
                <div class="instruction-text"><strong>Submission:</strong> Click "Submit Test" when you're done. You cannot change answers after submission.</div>
            </div>
            <div class="instruction-item">
                <div class="instruction-number">7</div>
                <div class="instruction-text"><strong>No Cheating:</strong> Do not use any external resources or assistance. This test must be completed independently.</div>
            </div>
        </div>
        <div class="important-notes">
            <div class="notes-title">⚠️ Important Notes</div>
            <div class="notes-list">
                <div class="note-item"><span>•</span><span>Ensure stable internet connection throughout the test</span></div>
                <div class="note-item"><span>•</span><span>Do not refresh the page or close the browser during the test</span></div>
                <div class="note-item"><span>•</span><span>Once time expires, the test will auto-submit</span></div>
                <?php if ($a['randomize_questions']): ?>
                <div class="note-item"><span>•</span><span>Questions are presented in a randomised order</span></div>
                <?php endif ?>
                <?php if ($a['randomize_options']): ?>
                <div class="note-item"><span>•</span><span>Answer option positions are shuffled — they may differ from any sample papers</span></div>
                <?php endif ?>
                <div class="note-item"><span>•</span><span>Use a laptop or desktop for the best experience</span></div>
            </div>
        </div>
    </div>
</div>

<!-- Confirm Start Modal -->
<div class="confirm-modal" id="confirmModal">
    <div class="confirm-content">
        <div class="confirm-icon">⚡</div>
        <h2 class="confirm-title">Ready to Start?</h2>
        <p class="confirm-message">
            Once you begin, the timer will start immediately.<br><br>
            <strong>Duration:</strong> <?= (int)$a['duration_minutes'] ?> minutes<br>
            <strong>Questions:</strong> <?= (int)$a['question_count'] ?><br>
            <strong>Total Marks:</strong> <?= (int)$a['total_marks'] ?><br>
            <?php if ($hasNeg): ?>
            <strong style="color:var(--danger)">⚠️ Negative marking applies.</strong><br>
            <?php endif ?>
            <br>Make sure you're ready before proceeding.
        </p>
        <div class="modal-buttons">
            <button class="modal-btn secondary" id="cancelBtn" onclick="closeConfirm()">Not Yet</button>
            <button class="modal-btn primary"   id="beginBtn"  onclick="beginTest()">Yes, Begin!</button>
        </div>
    </div>
</div>

<script>
    const ASSESSMENT_ID = <?= $assessmentId ?>;
    const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;

    // ── Navbar functions ──
    function toggleProfileDropdown() {
        const dd = document.getElementById('profileDropdown');
        document.getElementById('notifDropdown').classList.remove('show');
        dd.classList.toggle('show');
        document.getElementById('dropdownOverlay').classList.toggle('show', dd.classList.contains('show'));
    }
    function toggleNotifDropdown() {
        const dd = document.getElementById('notifDropdown');
        const isOpen = dd.classList.contains('show');
        document.getElementById('profileDropdown').classList.remove('show');
        dd.classList.toggle('show', !isOpen);
        document.getElementById('dropdownOverlay').classList.toggle('show', !isOpen);
        if (!isOpen) {
            fetch('api/notifications/mark-read.php', {
                method: 'POST',
                headers: { 'X-CSRF-Token': CSRF_TOKEN, 'Content-Type': 'application/json' }
            }).then(() => {
                const badge = document.querySelector('.notification-badge');
                if (badge) badge.remove();
                document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
                document.querySelectorAll('.notif-dot:not(.read)').forEach(el => el.classList.add('read'));
            }).catch(() => {});
        }
    }
    function closeAllDropdowns() {
        document.getElementById('profileDropdown').classList.remove('show');
        document.getElementById('notifDropdown').classList.remove('show');
        document.getElementById('dropdownOverlay').classList.remove('show');
    }
    function handleLogout() {
        if (confirm('Are you sure you want to logout?')) window.location.href = 'logout.php';
    }
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
        const badge = document.querySelector('.notification-badge');
        if (badge) {
            const cur = parseInt(badge.textContent) || 0;
            if (cur <= 1) badge.remove();
            else badge.textContent = cur - 1;
        }
        const list = document.querySelector('.notif-list');
        if (list && list.querySelectorAll('.notif-item').length === 0) {
            list.innerHTML = '<div class="notif-empty">No notifications yet.</div>';
        }
    }
    document.addEventListener('click', function(e) {
        const nw = document.querySelector('.notif-dropdown-wrap');
        const pw = document.querySelector('.profile-dropdown-container');
        if (nw && !nw.contains(e.target)) document.getElementById('notifDropdown').classList.remove('show');
        if (pw && !pw.contains(e.target)) document.getElementById('profileDropdown').classList.remove('show');
    });

    let csrfToken = CSRF_TOKEN;

    function showInstructions() { document.getElementById('instructionsModal').classList.add('active'); }
    function closeInstructions(){ document.getElementById('instructionsModal').classList.remove('active'); }
    function confirmStart()     { document.getElementById('confirmModal').classList.add('active'); }
    function closeConfirm()     { document.getElementById('confirmModal').classList.remove('active'); }

    async function beginTest() {
        const beginBtn  = document.getElementById('beginBtn');
        const cancelBtn = document.getElementById('cancelBtn');
        const startBtn  = document.getElementById('startBtn');

        beginBtn.textContent = 'Starting…';
        beginBtn.disabled    = true;
        cancelBtn.disabled   = true;
        if (startBtn) startBtn.disabled = true;

        const apiUrl = 'api/assessment/start.php';

        try {
            const res  = await fetch(apiUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body:    JSON.stringify({ assessment_id: ASSESSMENT_ID })
            });

            const text = await res.text();
            let data;
            try { data = JSON.parse(text); } catch (e) {
                console.error('start.php raw response:', text);
                throw new Error(res.status === 404
                    ? 'start.php not found (404). Check the file exists at api/assessment/start.php'
                    : 'Server error (HTTP ' + res.status + '). Check PHP error logs.');
            }

            if (data.success && data.attempt_id) {
                window.location.href = 'take-test.php?attempt_id=' + data.attempt_id;
            } else {
                resetButtons(beginBtn, cancelBtn, startBtn);
                closeConfirm();
                showError(data.error || 'Could not start test. Please try again.');
            }
        } catch (err) {
            console.error('beginTest error:', err);
            resetButtons(beginBtn, cancelBtn, startBtn);
            closeConfirm();
            showError(err.message || 'Could not reach the server. Please check your connection.');
        }
    }

    function resetButtons(b, c, s) {
        b.textContent = 'Yes, Begin!'; b.disabled = false;
        c.disabled = false;
        if (s) s.disabled = false;
    }

    function showError(msg) {
        let el = document.getElementById('startError');
        if (!el) {
            el = document.createElement('div');
            el.id = 'startError';
            el.style.cssText = 'margin-top:16px;padding:12px 20px;background:#fee2e2;color:#991b1b;' +
                'border-radius:10px;font-size:13.5px;font-weight:600;max-width:500px;margin-inline:auto;';
            document.querySelector('.action-section').appendChild(el);
        }
        el.textContent = '⚠️ ' + msg;
        el.style.display = 'block';
    }

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') { closeInstructions(); closeConfirm(); }
    });
</script>

<!-- ══ REPORT MODAL ══ -->
<div class="report-modal-overlay" id="reportModalOverlay" onclick="if(event.target===this)closeReportModal()">
    <div class="report-modal">
        <div class="report-modal-header">
            <div>
                <div class="report-modal-title">🚩 Report an Issue</div>
                <div class="report-modal-sub">We'll review your report and get back to you</div>
            </div>
            <button class="report-modal-close" onclick="closeReportModal()">✕</button>
        </div>
        <?php if (!empty($_GET['report']) && $_GET['report'] === 'sent'): ?>
        <div style="margin:16px 24px 0;padding:12px 16px;border-radius:10px;background:#d1fae5;border:1px solid #a7f3d0;font-size:13px;font-weight:600;color:#065f46;display:flex;align-items:center;gap:8px;">✅ Your report was submitted! We'll look into it soon.</div>
        <?php endif; ?>
        <?php if ($latestReportStatus): ?>
        <div style="margin:12px 24px 0;padding:12px 16px;border-radius:10px;background:<?= $hasOpenReport ? '#fff8ed' : '#d1fae5' ?>;border:1px solid <?= $hasOpenReport ? '#f59e0b' : '#a7f3d0' ?>;font-size:13px;font-weight:600;color:<?= $hasOpenReport ? '#92400e' : '#065f46' ?>;display:flex;align-items:center;gap:8px;">
            <?= $hasOpenReport ? '⏳ Your last report is <strong>'.ucfirst(str_replace('_',' ',$latestReportStatus)).'</strong> — admin will respond soon.' : '✅ Your last report has been <strong>Resolved</strong>.' ?>
        </div>
        <?php endif; ?>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="submit_report">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <div class="report-modal-body">
                <div class="report-field">
                    <label>Report Title <span>*</span></label>
                    <input type="text" name="report_title" placeholder="e.g. Assessment not loading" required maxlength="150">
                </div>
                <div class="report-field">
                    <label>Explanation <span>*</span></label>
                    <textarea name="report_description" rows="4" placeholder="Describe the issue in detail..." required maxlength="2000"></textarea>
                </div>
                <div class="report-field">
                    <label>Screenshot / Image <span style="color:#94a3b8;font-weight:500;">(optional)</span></label>
                    <label for="reportImageInput" class="report-drop-zone" id="reportDropZone">
                        <div class="dz-icon">📷</div>
                        <div class="dz-text">Click to upload or drag & drop</div>
                        <div class="dz-sub">JPG, PNG, GIF, WEBP — max 5 MB</div>
                    </label>
                    <input type="file" name="report_image" id="reportImageInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none" onchange="previewReportImg(this)">
                    <img id="reportImgPreview" class="report-img-preview" alt="Preview">
                </div>
            </div>
            <div class="report-modal-footer">
                <button type="button" class="btn-report-cancel" onclick="closeReportModal()">Cancel</button>
                <button type="submit" class="btn-report-submit">🚩 Submit Report</button>
            </div>
        </form>
    </div>
</div>
<script>
function openReportModal(){
    document.getElementById('reportModalOverlay').classList.add('open');
    document.addEventListener('keydown', escReport);
}
function closeReportModal(){
    document.getElementById('reportModalOverlay').classList.remove('open');
    document.removeEventListener('keydown', escReport);
}
function escReport(e){ if(e.key==='Escape') closeReportModal(); }
function previewReportImg(input){
    const file = input.files[0]; if(!file) return;
    if(file.size > 5*1024*1024){ alert('Image must be under 5MB.'); input.value=''; return; }
    const reader = new FileReader();
    reader.onload = e => {
        const p = document.getElementById('reportImgPreview');
        p.src = e.target.result; p.style.display = 'block';
        document.querySelector('#reportDropZone .dz-text').textContent = file.name;
        document.querySelector('#reportDropZone .dz-sub').textContent = (file.size/1024).toFixed(1)+' KB';
    };
    reader.readAsDataURL(file);
}
const rdz = document.getElementById('reportDropZone');
if(rdz){
    rdz.addEventListener('dragover', e=>{ e.preventDefault(); rdz.classList.add('dragover'); });
    rdz.addEventListener('dragleave', ()=> rdz.classList.remove('dragover'));
    rdz.addEventListener('drop', e=>{
        e.preventDefault(); rdz.classList.remove('dragover');
        const file = e.dataTransfer.files[0];
        if(file && file.type.startsWith('image/')){
            const input = document.getElementById('reportImageInput');
            try{ const dt=new DataTransfer(); dt.items.add(file); input.files=dt.files; }catch(ex){}
            previewReportImg(input);
        }
    });
}
<?php if(!empty($_GET['report']) && $_GET['report']==='sent'): ?>
window.addEventListener('load', ()=> openReportModal());
<?php endif; ?>
</script>
</body>
</html>
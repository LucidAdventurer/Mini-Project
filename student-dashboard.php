<?php
/* ========================================
 * STUDENT DASHBOARD
 * ======================================== */

require_once "config.php";
require_once "db-guard.php";

$user         = validateSession($conn, 'student');
$userName     = $user['full_name'] ?? 'Student';
$userEmail    = $user['email']     ?? '';
$userDept     = $user['department'] ?? '';
$userInitials = strtoupper(substr($userName, 0, 2));
$userId       = (int) $user['user_id'];

// Fetch fresh profile image
$imgRes = safePreparedQuery($conn, "SELECT profile_image FROM users WHERE user_id = ?", "i", [$userId]);
$userProfileImage = '';
if ($imgRes['success'] && $imgRes['result']) {
    $imgRow = $imgRes['result']->fetch_assoc();
    $userProfileImage = $imgRow['profile_image'] ?? '';
    $imgRes['result']->free();
}

// Ensure CSRF token exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Due soon popup (show once per login session) ──
$duePopupAssessments = [];
$showDuePopup = false;

if (!empty($_SESSION['login_time']) && empty($_SESSION['due_popup_shown'])) {
    $_SESSION['due_popup_shown'] = true;

    $dueResult = safePreparedQuery($conn,
        "SELECT a.assessment_id, a.title, a.end_time,
                TIMESTAMPDIFF(HOUR, NOW(), a.end_time) AS hours_left
         FROM assessments a
         WHERE a.status = 'published'
           AND a.end_time IS NOT NULL
           AND a.end_time > NOW()
           AND a.end_time <= DATE_ADD(NOW(), INTERVAL 3 DAY)
           AND (
               a.visibility = 'public'
               OR EXISTS (
                   SELECT 1 FROM assessment_targets at2
                   WHERE at2.assessment_id = a.assessment_id
                     AND at2.target_type = 'student'
                     AND at2.target_id = ?
               )
               OR EXISTS (
                   SELECT 1 FROM assessment_targets at2
                   JOIN group_members gm ON gm.group_id = at2.target_id
                   WHERE at2.assessment_id = a.assessment_id
                     AND at2.target_type = 'group'
                     AND gm.student_id = ?
               )
           )
           AND NOT EXISTS (
               SELECT 1 FROM assessment_attempts aa
               WHERE aa.assessment_id = a.assessment_id
                 AND aa.user_id = ?
                 AND aa.status = 'submitted'
           )
         ORDER BY a.end_time ASC",
        "iii", [$userId, $userId, $userId]
    );

    if ($dueResult['success'] && $dueResult['result']) {
        while ($row = $dueResult['result']->fetch_assoc()) {
            $hoursLeft = (int)$row['hours_left'];
            if ($hoursLeft < 24) {
                $urgency = 'today';
                $label   = 'Due Today!';
            } elseif ($hoursLeft < 48) {
                $urgency = 'tomorrow';
                $label   = 'Due Tomorrow';
            } else {
                $days    = ceil($hoursLeft / 24);
                $urgency = 'soon';
                $label   = "Due in $days days";
            }
            $duePopupAssessments[] = [
                'id'       => (int)$row['assessment_id'],
                'title'    => $row['title'],
                'end_time' => $row['end_time'],
                'urgency'  => $urgency,
                'label'    => $label,
            ];
        }
        $dueResult['result']->free();
        $showDuePopup = !empty($duePopupAssessments);
    }
}

// ── Student statistics ──
$statsResult = safePreparedQuery($conn,
    "SELECT
        COUNT(DISTINCT attempt_id)   AS tests_completed,
        COALESCE(AVG(percentage), 0) AS avg_score
     FROM assessment_attempts
     WHERE user_id = ? AND status = 'submitted'",
    "i", [$userId]
);

$testsCompleted = 0;
$avgScore       = 0;

if ($statsResult['success'] && $statsResult['result']) {
    $stats          = $statsResult['result']->fetch_assoc();
    $testsCompleted = (int)   ($stats['tests_completed'] ?? 0);
    $avgScore       = round((float)($stats['avg_score']  ?? 0));
    $statsResult['result']->free();
}

// ── Unread notification count ──
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
// Assessment notif count will be added to badge after $assessNotifNew is populated (see below)

// ── Available assessment count (only assigned to this student) ──
$availCountResult = safePreparedQuery($conn,
    "SELECT COUNT(DISTINCT a.assessment_id) AS cnt
     FROM assessments a
     WHERE a.status = 'published'
       AND (a.start_time IS NULL OR a.start_time <= NOW())
       AND (a.end_time   IS NULL OR a.end_time   >= NOW())
       AND (
           a.visibility = 'public'
           OR EXISTS (
               SELECT 1 FROM assessment_targets at2
               WHERE at2.assessment_id = a.assessment_id
                 AND at2.target_type = 'student'
                 AND at2.target_id = ?
           )
           OR EXISTS (
               SELECT 1 FROM assessment_targets at2
               JOIN group_members gm ON gm.group_id = at2.target_id
               WHERE at2.assessment_id = a.assessment_id
                 AND at2.target_type = 'group'
                 AND gm.student_id = ?
           )
       )",
    "ii", [$userId, $userId]
);

$availableTests = 0;
if ($availCountResult['success'] && $availCountResult['result']) {
    $row            = $availCountResult['result']->fetch_assoc();
    $availableTests = (int)($row['cnt'] ?? 0);
    $availCountResult['result']->free();
}

// ── Fetch assessments for the dashboard list (only assigned to this student) ──
$assessmentsResult = safePreparedQuery($conn,
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
        a.end_time,
        (SELECT COUNT(*) FROM questions q WHERE q.assessment_id = a.assessment_id) AS question_count,
        (SELECT COUNT(*) FROM assessment_attempts aa
          WHERE aa.assessment_id = a.assessment_id
            AND aa.user_id = ?
            AND aa.status  = 'submitted') AS attempts_used,
        (SELECT aa2.attempt_id FROM assessment_attempts aa2
          WHERE aa2.assessment_id = a.assessment_id
            AND aa2.user_id = ?
            AND aa2.status  = 'submitted'
          ORDER BY aa2.submitted_at DESC LIMIT 1) AS last_attempt_id
     FROM assessments a
     WHERE a.status = 'published'
       AND (a.start_time IS NULL OR a.start_time <= NOW())
       AND (a.end_time   IS NULL OR a.end_time   >= NOW())
       AND (
           a.visibility = 'public'
           OR EXISTS (
               SELECT 1 FROM assessment_targets at2
               WHERE at2.assessment_id = a.assessment_id
                 AND at2.target_type = 'student'
                 AND at2.target_id = ?
           )
           OR EXISTS (
               SELECT 1 FROM assessment_targets at2
               JOIN group_members gm ON gm.group_id = at2.target_id
               WHERE at2.assessment_id = a.assessment_id
                 AND at2.target_type = 'group'
                 AND gm.student_id = ?
           )
       )
       AND (
           SELECT COUNT(*) FROM assessment_attempts aa3
           WHERE aa3.assessment_id = a.assessment_id
             AND aa3.user_id = ?
             AND aa3.status  = 'submitted'
       ) < a.max_attempts
     ORDER BY a.created_at DESC
     LIMIT 3",
    "iiiii", [$userId, $userId, $userId, $userId, $userId]
);

$assessments      = [];
$assessmentError  = false;

if ($assessmentsResult['success'] && $assessmentsResult['result']) {
    while ($row = $assessmentsResult['result']->fetch_assoc()) {
        $assessments[] = $row;
    }
    $assessmentsResult['result']->free();
} elseif (!$assessmentsResult['success']) {
    $assessmentError = true;
}

// ── Recent activity (last 5 completed attempts) ──
$activityResult = safePreparedQuery($conn,
    "SELECT aa.attempt_id, aa.percentage, aa.submitted_at,
            a.title
     FROM assessment_attempts aa
     JOIN assessments a ON a.assessment_id = aa.assessment_id
     WHERE aa.user_id = ? AND aa.status = 'submitted'
     ORDER BY aa.submitted_at DESC
     LIMIT 5",
    "i", [$userId]
);

$recentActivity = [];
if ($activityResult['success'] && $activityResult['result']) {
    while ($row = $activityResult['result']->fetch_assoc()) {
        $recentActivity[] = $row;
    }
    $activityResult['result']->free();
}

// ── All notifications for dropdown (scroll, latest first) ──
$notifDropResult = safePreparedQuery($conn,
    "SELECT notification_id, title, message, is_read, created_at, type, related_entity_id
     FROM notifications WHERE user_id = ?
     ORDER BY created_at DESC",
    "i", [$userId]
);
$notifItems = [];
if ($notifDropResult['success'] && $notifDropResult['result']) {
    while ($row = $notifDropResult['result']->fetch_assoc()) {
        $notifItems[] = $row;
    }
    $notifDropResult['result']->free();
}

// ── Assessment notifications: new tests (not started) + previous tests (at least 1 attempt) ──
// ── Fetch dismissed assessment IDs (table may not exist yet — handled gracefully) ──
$dismissedAssessIds = [];
$dismissRes = safePreparedQuery($conn,
    "SELECT assessment_id FROM student_notif_dismiss WHERE user_id = ?",
    "i", [$userId]
);
if ($dismissRes['success'] && $dismissRes['result']) {
    while ($row = $dismissRes['result']->fetch_assoc()) {
        $dismissedAssessIds[] = (int)$row['assessment_id'];
    }
    $dismissRes['result']->free();
}
$dismissedSet = array_flip($dismissedAssessIds);

// ── Assessment notifications: new tests + previous tests ──
$assessNotifResult = safePreparedQuery($conn,
    "SELECT
        a.assessment_id,
        a.title,
        a.category,
        a.end_time,
        a.created_at,
        (SELECT COUNT(*) FROM assessment_attempts aa
          WHERE aa.assessment_id = a.assessment_id
            AND aa.user_id = ?
            AND aa.status = 'submitted') AS attempts_used,
        (SELECT aa2.percentage FROM assessment_attempts aa2
          WHERE aa2.assessment_id = a.assessment_id
            AND aa2.user_id = ?
            AND aa2.status = 'submitted'
          ORDER BY aa2.submitted_at DESC LIMIT 1) AS last_score
     FROM assessments a
     WHERE a.status = 'published'
       AND (
           a.visibility = 'public'
           OR EXISTS (
               SELECT 1 FROM assessment_targets at2
               WHERE at2.assessment_id = a.assessment_id
                 AND at2.target_type = 'student'
                 AND at2.target_id = ?
           )
           OR EXISTS (
               SELECT 1 FROM assessment_targets at2
               JOIN group_members gm ON gm.group_id = at2.target_id
               WHERE at2.assessment_id = a.assessment_id
                 AND at2.target_type = 'group'
                 AND gm.student_id = ?
           )
       )
     ORDER BY a.created_at DESC
     LIMIT 20",
    "iiii", [$userId, $userId, $userId, $userId]
);

$assessNotifNew  = []; // Tests not yet attempted
$assessNotifPrev = []; // Tests with at least 1 attempt (and not dismissed)

if ($assessNotifResult['success'] && $assessNotifResult['result']) {
    while ($row = $assessNotifResult['result']->fetch_assoc()) {
        $row['attempts_used'] = (int)$row['attempts_used'];
        $isDismissed = isset($dismissedSet[(int)$row['assessment_id']]);
        if ($row['attempts_used'] === 0) {
            $assessNotifNew[] = $row; // New tests are never dismissable
        } elseif (!$isDismissed) {
            $assessNotifPrev[] = $row; // Previous tests only show if not dismissed
        }
    }
    $assessNotifResult['result']->free();
}

// Total assessment notification count (for badge supplement)
$assessNotifCount = count($assessNotifNew);

// ── Overall completion % (attempts used vs available) ──
$completionPct = ($availableTests > 0)
    ? min(100, round(($testsCompleted / $availableTests) * 100))
    : 0;

// ── Chart data 1: Score % over last 5 attempts ──
$scoreChartResult = safePreparedQuery($conn,
    "SELECT a.title, aa.percentage, aa.submitted_at
     FROM assessment_attempts aa
     JOIN assessments a ON a.assessment_id = aa.assessment_id
     WHERE aa.user_id = ? AND aa.status = 'submitted'
     ORDER BY aa.submitted_at DESC
     LIMIT 5",
    "i", [$userId]
);
$scoreLabels = [];
$scoreData   = [];
if ($scoreChartResult['success'] && $scoreChartResult['result']) {
    while ($row = $scoreChartResult['result']->fetch_assoc()) {
        // Shorten title for label
        $scoreLabels[] = mb_strimwidth($row['title'], 0, 14, '…');
        $scoreData[]   = round((float)$row['percentage']);
    }
    $scoreChartResult['result']->free();
}
// Reverse so oldest → newest left to right
$scoreLabels = array_reverse($scoreLabels);
$scoreData   = array_reverse($scoreData);

// ── Chart data 2: Tests completed per category ──
$catChartResult = safePreparedQuery($conn,
    "SELECT a.category, COUNT(*) AS cnt
     FROM assessment_attempts aa
     JOIN assessments a ON a.assessment_id = aa.assessment_id
     WHERE aa.user_id = ? AND aa.status = 'submitted'
     GROUP BY a.category",
    "i", [$userId]
);
$catLabels = [];
$catData   = [];
if ($catChartResult['success'] && $catChartResult['result']) {
    while ($row = $catChartResult['result']->fetch_assoc()) {
        $catLabels[] = ucfirst($row['category']);
        $catData[]   = (int)$row['cnt'];
    }
    $catChartResult['result']->free();
}

// ── Report submission disabled on student dashboard ──
    // (report=sent redirect kept for URL safety)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_report') {
    header('Location: student-dashboard.php');
    exit;
}

/* Helper: human-readable time-ago */
function timeAgo(string $datetime): string {
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
    <title>Student Dashboard - PTA Platform</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
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
            --shadow-glow:   0 0 0 3px var(--accent-glow);
            --nav-h:         68px;
            --sidebar-w:     230px;
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
        }

        /* ══════════════════════════════
           NAVBAR
        ══════════════════════════════ */
        .navbar {
            background: var(--primary);
            padding: 0 28px;
            height: var(--nav-h);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: fixed;
            top: 0; left: 0; right: 0;
            z-index: 1000;
            box-shadow: 0 1px 0 rgba(255,255,255,.06), 0 4px 20px rgba(0,0,0,.18);
        }

        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            flex-shrink: 0;
        }

        .brand-logo {
            width: 40px; height: 40px;
            border-radius: 10px;
            overflow: hidden;
            background: white;
            display: flex; align-items: center; justify-content: center;
        }

        .nav-search {
            flex: 1;
            max-width: 440px;
            margin: 0 32px;
            position: relative;
        }

        .nav-search input {
            width: 100%;
            padding: 10px 18px 10px 42px;
            border: 1.5px solid rgba(255,255,255,.15);
            border-radius: 10px;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            background: rgba(255,255,255,.1);
            color: white;
            outline: none;
            transition: var(--transition);
        }
        .nav-search input::placeholder { color: rgba(255,255,255,.5); }
        .nav-search input:focus {
            background: rgba(255,255,255,.18);
            border-color: rgba(255,255,255,.35);
            box-shadow: 0 0 0 3px rgba(14,165,233,.25);
        }
        .nav-search .sicon {
            position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
            color: rgba(255,255,255,.5); font-size: 13px; pointer-events: none;
        }

        .nav-profile { display: flex; align-items: center; gap: 10px; }

        .notification-icon {
            position: relative;
            width: 38px; height: 38px;
            background: rgba(255,255,255,.12);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; border: 1.5px solid rgba(255,255,255,.15);
            transition: var(--transition); color: white;
        }
        .notification-icon:hover { background: rgba(255,255,255,.2); border-color: rgba(255,255,255,.3); }

        /* Notification dropdown */
        .notif-dropdown-wrap { position: relative; overflow: visible; }
        .notif-dropdown {
            position: absolute;
            top: calc(100% + 12px); right: 0;
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border);
            width: 348px;
            opacity: 0; visibility: hidden; transform: translateY(-6px) scale(.98);
            transition: var(--transition);
            z-index: 1002;
        }
        .notif-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }

        .notif-dropdown-header {
            padding: 16px 20px 14px;
            font-family: 'Times New Roman', Times, serif;
            font-weight: 700; font-size: 14px; color: var(--text);
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
        }

        .notif-list {
            max-height: 360px;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: var(--border) transparent;
        }
        .notif-list::-webkit-scrollbar { width: 4px; }
        .notif-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 10px; }

        .notif-item {
            display: flex; gap: 12px; align-items: flex-start;
            padding: 13px 20px;
            border-bottom: 1px solid var(--border);
            cursor: pointer; transition: background var(--transition);
        }
        .notif-item:last-child { border-bottom: none; }
        .notif-item:hover { background: var(--surface2); }
        .notif-item.unread { background: #eff8ff; }
        .notif-item.unread:hover { background: #e0f2fe; }

        .notif-dot {
            width: 7px; height: 7px; border-radius: 50%;
            background: var(--accent); flex-shrink: 0; margin-top: 5px;
        }
        .notif-dot.read { background: transparent; }
        .notif-item-body { flex: 1; }
        .notif-item-title { font-size: 13px; font-weight: 600; color: var(--text); margin-bottom: 2px; }
        .notif-item-msg { font-size: 12px; color: var(--text-mid); line-height: 1.45; }
        .notif-dismiss-btn {
    background: none;
    border: none;
    color: var(--text-soft, #94a3b8);
    font-size: 13px;
    line-height: 1;
    padding: 2px 5px;
    border-radius: 4px;
    cursor: pointer;
    flex-shrink: 0;
    opacity: 0;
    transition: opacity .15s, background .15s, color .15s;
    align-self: flex-start;
    margin-top: 2px;
}
.notif-item:hover .notif-dismiss-btn { opacity: 1; }
.notif-dismiss-btn:hover { background: rgba(239,68,68,.1); color: #ef4444; }
.notif-item-time { font-size: 11px; color: var(--text-soft); margin-top: 4px; }
        .notif-empty { padding: 32px 20px; text-align: center; color: var(--text-soft); font-size: 13px; }
        .notif-section-label {
            padding: 8px 20px 5px;
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--text-soft);
            background: var(--surface2);
            border-bottom: 1px solid var(--border);
            border-top: 1px solid var(--border);
        }

        .notification-badge {
            position: absolute;
            top: -4px; right: -4px;
            background: var(--danger);
            color: white;
            min-width: 18px; height: 18px;
            border-radius: 9px; padding: 0 4px;
            font-size: 10px; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            animation: badgePulse 2s ease-in-out infinite;
            border: 2px solid var(--primary);
        }
        @keyframes badgePulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(239,68,68,.5); }
            60%       { box-shadow: 0 0 0 5px rgba(239,68,68,0); }
        }

        .profile-dropdown-container { position: relative; }
        .profile-button {
            display: flex; align-items: center; gap: 9px;
            padding: 6px 12px 6px 6px;
            background: rgba(255,255,255,.12);
            border: 1.5px solid rgba(255,255,255,.15);
            border-radius: 10px;
            cursor: pointer; transition: var(--transition);
        }
        .profile-button:hover { background: rgba(255,255,255,.2); border-color: rgba(255,255,255,.3); }

        .profile-avatar {
            width: 32px; height: 32px;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 13px;
            font-family: 'Times New Roman', Times, serif;
        }

        .profile-name { font-weight: 600; font-size: 13.5px; color: rgba(255,255,255,.95); }
        .dropdown-arrow { font-size: 10px; color: rgba(255,255,255,.6); }

        .profile-dropdown {
            position: absolute;
            top: calc(100% + 12px); right: 0;
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border);
            min-width: 240px;
            opacity: 0; visibility: hidden; transform: translateY(-6px) scale(.98);
            transition: var(--transition);
            z-index: 1001;
            overflow: hidden;
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
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 800; font-size: 18px;
            font-family: 'Times New Roman', Times, serif; flex-shrink: 0;
        }
        .dropdown-user-info { flex: 1; overflow: hidden; }
        .dropdown-user-name { font-family: 'Times New Roman', Times, serif; font-weight: 700; font-size: 15px; color: white; margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
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

        .dropdown-overlay {
            position: fixed; inset: 0;
            background: transparent; z-index: 999; display: none;
        }
        .dropdown-overlay.show { display: block; }

        .container { max-width: 1400px; margin: 0 auto; padding: 28px; }

        /* ══════════════════════════════
           LEFT SIDEBAR
        ══════════════════════════════ */
        .page-wrapper { display: flex; min-height: calc(100vh - var(--nav-h)); }

        .left-sidebar {
            width: var(--sidebar-w);
            flex-shrink: 0;
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
            color: var(--accent);
            font-weight: 600;
        }
        .left-sidebar a.active::before {
            content: '';
            position: absolute; left: 0; top: 20%; bottom: 20%;
            width: 3px; border-radius: 0 3px 3px 0;
            background: var(--accent);
        }
        .left-sidebar a i { width: 18px; text-align: center; font-size: 14px; flex-shrink: 0; }

        .left-sidebar-bottom {
            margin-top: auto; padding-top: 12px;
            border-top: 1px solid var(--border);
        }
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

        .page-content { flex: 1; min-width: 0; padding: 28px; }

        @media (max-width: 900px) { .left-sidebar { display: none; } .page-content { padding: 20px; } }


        /* ══════════════════════════════
           WELCOME BANNER
        ══════════════════════════════ */
        .welcome-section {
            background: linear-gradient(135deg, #1a3a52 0%, #1e5276 55%, #1a6fa0 100%);
            border-radius: var(--radius);
            padding: 28px 32px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(26,58,82,.45);
            border: 1px solid rgba(255,255,255,.08);
        }
        .welcome-section::before {
            content: '';
            position: absolute; top: -40px; right: -40px;
            width: 200px; height: 200px;
            border-radius: 50%;
            background: rgba(255,255,255,.04);
            pointer-events: none;
        }
        .welcome-section::after {
            content: '';
            position: absolute; bottom: -60px; right: 100px;
            width: 160px; height: 160px;
            border-radius: 50%;
            background: rgba(14,165,233,.06);
            pointer-events: none;
        }

        .welcome-content { position: relative; z-index: 1; }
        .welcome-content h1 {
            font-family: 'Sora', sans-serif;
            font-size: 26px; font-weight: 800;
            color: white; margin-bottom: 6px;
            letter-spacing: -.3px;
        }
        .welcome-content p { font-size: 14px; color: rgba(255,255,255,.75); }

        .quick-stats { display: flex; gap: 8px; position: relative; z-index: 1; }

        .stat-item {
            text-align: center;
            background: rgba(255,255,255,.1);
            border: 1px solid rgba(255,255,255,.15);
            border-radius: 12px;
            padding: 14px 20px;
            min-width: 90px;
            backdrop-filter: blur(8px);
            transition: var(--transition);
        }
        .stat-item:hover { background: rgba(255,255,255,.16); }
        .stat-number {
            font-family: 'Times New Roman', Times, serif;
            font-size: 26px; font-weight: 800;
            color: white; display: block; line-height: 1;
        }
        .stat-label { font-size: 11px; color: rgba(255,255,255,.65); margin-top: 5px; white-space: nowrap; }

        /* ══════════════════════════════
           MAIN CONTENT GRID
        ══════════════════════════════ */
        .main-content {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 24px;
        }

        /* ══════════════════════════════
           ASSESSMENTS SECTION
        ══════════════════════════════ */
        .section-header {
            display: flex; justify-content: space-between; align-items: flex-start;
            margin-bottom: 18px;
        }
        .section-title {
            font-family: 'Times New Roman', Times, serif;
            font-size: 18px; font-weight: 700; color: var(--text);
        }
        .view-all-link {
            display: inline-flex; align-items: center; gap: 6px;
            background: var(--accent); color: white;
            padding: 9px 18px; border-radius: var(--radius-sm);
            font-size: 13px; font-weight: 600; text-decoration: none;
            transition: var(--transition); white-space: nowrap;
            box-shadow: 0 2px 8px rgba(14,165,233,.3);
        }
        .view-all-link:hover { background: #0284c7; box-shadow: 0 4px 14px rgba(14,165,233,.4); transform: translateY(-1px); }

        .assessments-section {
            background: var(--surface);
            border-radius: var(--radius);
            padding: 24px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }

        .filter-tabs { display: flex; gap: 8px; margin-bottom: 18px; flex-wrap: wrap; }

        .filter-tab {
            padding: 7px 16px;
            background: var(--surface2);
            border: 1.5px solid var(--border);
            border-radius: 8px; cursor: pointer;
            font-size: 13px; font-weight: 600;
            color: var(--text-mid);
            font-family: 'Inter', sans-serif;
            transition: var(--transition);
        }
        .filter-tab.active {
            background: var(--accent); color: white;
            border-color: var(--accent);
            box-shadow: 0 2px 8px rgba(14,165,233,.3);
        }
        .filter-tab:hover:not(.active) { background: var(--border); color: var(--text); }

        .assessment-list { display: flex; flex-direction: column; gap: 14px; }

        .assessment-card {
            background: var(--surface2);
            border-radius: 12px;
            padding: 20px;
            border: 1.5px solid var(--border);
            transition: var(--transition);
        }
        .assessment-card.hidden { display: none; }
        .assessment-card:not(.exhausted):hover {
            border-color: var(--accent);
            background: var(--surface);
            box-shadow: 0 4px 20px rgba(14,165,233,.12), 0 0 0 1px rgba(14,165,233,.1);
            transform: translateY(-1px);
        }

        .assessment-header {
            display: flex; justify-content: space-between; align-items: flex-start;
            margin-bottom: 12px; gap: 12px;
        }

        .assessment-title {
            font-family: 'Times New Roman', Times, serif;
            font-size: 15.5px; font-weight: 700; color: var(--text);
            margin-bottom: 4px; line-height: 1.3;
        }
        .assessment-category { font-size: 12.5px; color: var(--text-soft); }

        .difficulty-badge {
            padding: 4px 11px; border-radius: 6px;
            font-size: 11.5px; font-weight: 700;
            white-space: nowrap; flex-shrink: 0;
            font-family: 'Times New Roman', Times, serif;
            letter-spacing: .02em;
        }
        .difficulty-badge.easy   { background: #dcfce7; color: #166534; }
        .difficulty-badge.medium { background: #fef3c7; color: #92400e; }
        .difficulty-badge.hard   { background: #fee2e2; color: #991b1b; }

        .assessment-meta {
            display: flex; gap: 16px; margin-bottom: 16px; flex-wrap: wrap;
        }
        .meta-item {
            display: flex; align-items: center; gap: 5px;
            font-size: 12.5px; color: var(--text-mid);
        }

        .assessment-actions { display: flex; gap: 9px; }

        .btn-start {
            padding: 9px 22px;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            color: white; border: none; border-radius: 8px;
            font-weight: 600; font-size: 13.5px; cursor: pointer;
            transition: var(--transition); font-family: 'Inter', sans-serif;
            box-shadow: 0 2px 8px rgba(14,165,233,.3);
        }
        .btn-start:hover { transform: translateY(-2px); box-shadow: 0 4px 14px rgba(14,165,233,.45); }

        .btn-details {
            padding: 9px 22px;
            background: var(--surface);
            color: var(--accent);
            border: 1.5px solid var(--accent);
            border-radius: 8px; font-weight: 600;
            font-size: 13.5px; cursor: pointer;
            transition: var(--transition); font-family: 'Inter', sans-serif;
        }
        .btn-details:hover { background: var(--accent); color: white; box-shadow: 0 4px 12px rgba(14,165,233,.3); }

        /* ══════════════════════════════
           RIGHT SIDEBAR CARDS
        ══════════════════════════════ */
        .sidebar { display: flex; flex-direction: column; gap: 20px; }

        .sidebar-card {
            background: var(--surface);
            border-radius: var(--radius);
            padding: 22px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }
        .sidebar-card-title {
            font-family: 'Times New Roman', Times, serif;
            font-size: 15px; font-weight: 700; color: var(--text);
            margin-bottom: 18px;
            display: flex; align-items: center; gap: 8px;
        }
        .sidebar-card-title::after {
            content: '';
            flex: 1; height: 1px; background: var(--border);
        }

        .activity-list { display: flex; flex-direction: column; gap: 0; }

        .activity-item {
            display: flex; gap: 12px; align-items: flex-start;
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
        }
        .activity-item:last-child { border-bottom: none; padding-bottom: 0; }

        .activity-icon {
            width: 36px; height: 36px;
            background: linear-gradient(135deg, #e0f2fe, #e0f9ff);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; flex-shrink: 0; color: var(--accent);
        }

        .activity-content { flex: 1; min-width: 0; }
        .activity-title {
            font-size: 13px; font-weight: 600; color: var(--text);
            margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .activity-time { font-size: 11.5px; color: var(--text-soft); }

        .progress-chart {
            height: 180px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 14px;
        }

        .overall-progress { margin-top: 14px; }
        .progress-label {
            display: flex; justify-content: space-between;
            margin-bottom: 8px; font-size: 13px;
        }
        .progress-label span:first-child { font-weight: 600; color: var(--text); }
        .progress-label span:last-child { color: var(--accent); font-weight: 700; }

        .progress-bar-container {
            width: 100%; height: 8px;
            background: var(--border); border-radius: 10px; overflow: hidden;
        }
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--accent), var(--accent2));
            border-radius: 10px; transition: width .8s cubic-bezier(.4,0,.2,1);
        }

        /* ══════════════════════════════
           STATE MESSAGES
        ══════════════════════════════ */
        .assessment-card.exhausted { opacity: .65; }
        .assessment-card.exhausted:hover { transform: none; }

        .state-message {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 10px; padding: 48px 20px;
            border-radius: 12px; text-align: center;
        }
        .state-message .state-icon { font-size: 36px; }
        .state-message p { font-size: 14px; color: var(--text-mid); }
        .state-empty { background: var(--surface2); }
        .state-error { background: #fef2f2; }
        .state-error p { color: #b91c1c; }

        /* ══════════════════════════════
           RESPONSIVE
        ══════════════════════════════ */
        @media (max-width: 1100px) {
            .main-content { grid-template-columns: 1fr; }
            .nav-search { max-width: 320px; margin: 0 20px; }
        }
        @media (max-width: 768px) {
            .navbar { padding: 0 16px; }
            .nav-search { display: none; }
            .welcome-section { flex-direction: column; align-items: flex-start; gap: 18px; padding: 22px; }
            .quick-stats { width: 100%; justify-content: space-between; }
            .profile-name { display: none; }
            .page-content { padding: 16px; }
        }

        /* ── Page load animation ── */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .welcome-section  { animation: fadeUp .4s ease both; }
        .assessments-section { animation: fadeUp .4s .1s ease both; }
        .sidebar > * { animation: fadeUp .4s ease both; }
        .sidebar > *:nth-child(2) { animation-delay: .15s; }
    </style>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
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
        <div class="nav-search">
            <i class="fa fa-search sicon"></i>
            <input type="text" id="searchInput" placeholder="Search assessments..." autocomplete="off">
        </div>
        <div class="nav-profile">
            <!-- Help & Support accessible via profile dropdown -->
            <div class="notif-dropdown-wrap">
                <button class="notification-icon" onclick="toggleNotifDropdown()" id="notifBtn">
                    <span>🔔</span>
                    <?php
                    $totalBadge = $unreadCount + $assessNotifCount;
                    if ($totalBadge > 0): ?>
                    <div class="notification-badge"><?= $totalBadge > 99 ? '99+' : $totalBadge ?></div>
                    <?php endif; ?>
                </button>
                <div class="notif-dropdown" id="notifDropdown">
                    <div class="notif-dropdown-header">
                        <span>🔔 Notifications</span>
                        <?php if ($unreadCount > 0): ?>
                        <button onclick="markAllRead()" style="
                            background:none;border:none;font-size:11.5px;color:var(--accent);
                            font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;
                            padding:3px 8px;border-radius:6px;transition:.15s;"
                            onmouseover="this.style.background='#eff8ff'"
                            onmouseout="this.style.background='none'">
                            Mark all read
                        </button>
                        <?php endif; ?>
                    </div>
                    <div class="notif-list">

                        <?php /* ── NEW TESTS section ── */
                        if (!empty($assessNotifNew)): ?>
                        <div class="notif-section-label">🆕 New Tests</div>
                        <?php foreach ($assessNotifNew as $an):
                            $anEnd = $an['end_time'] ? date('d M, h:i A', strtotime($an['end_time'])) : null;
                            $isExpiringSoon = $an['end_time'] && strtotime($an['end_time']) < strtotime('+3 days');
                        ?>
                        <div class="notif-item unread assess-notif" id="assess-notif-<?= $an['assessment_id'] ?>" data-assess-id="<?= $an['assessment_id'] ?>">
                            <div class="notif-dot"></div>
                            <div class="notif-item-body">
                                <div class="notif-item-title">📋 <?= htmlspecialchars($an['title']) ?></div>
                                <div class="notif-item-msg">
                                    <?php if ($an['category']): ?>
                                    <span style="display:inline-block;background:#e0f2fe;color:#0369a1;font-size:10.5px;font-weight:600;padding:1px 7px;border-radius:10px;margin-bottom:3px;">
                                        <?= htmlspecialchars(ucfirst($an['category'])) ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php if ($anEnd): ?>
                                    <span style="display:block;margin-top:2px;<?= $isExpiringSoon ? 'color:#ef4444;font-weight:600;' : '' ?>">
                                        <?= $isExpiringSoon ? '⚠️' : '📅' ?> Due: <?= $anEnd ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <div class="notif-item-time"><?= timeAgo($an['created_at']) ?></div>
                                <div style="margin-top:6px;">
                                    <button onclick="event.stopPropagation(); window.location.href='student-assessments.php'"
                                        style="font-size:11.5px;font-weight:600;color:#fff;
                                            background:linear-gradient(135deg,#0ea5e9,#06b6d4);
                                            border:none;border-radius:6px;padding:4px 12px;cursor:pointer;">
                                        Take Test →
                                    </button>
                                </div>
                            </div>
                            <!-- No dismiss on new (unstarted) tests -->
                        </div>
                        <?php endforeach; endif; ?>

                        <?php /* ── PREVIOUS TESTS section ── */
                        if (!empty($assessNotifPrev)): ?>
                        <div class="notif-section-label">📁 Previous Tests</div>
                        <?php foreach ($assessNotifPrev as $ap):
                            $score = $ap['last_score'] !== null ? round((float)$ap['last_score']) . '%' : '—';
                            $apEnd = $ap['end_time'] ? date('d M, h:i A', strtotime($ap['end_time'])) : null;
                        ?>
                        <div class="notif-item assess-notif" id="assess-notif-<?= $ap['assessment_id'] ?>" data-assess-id="<?= $ap['assessment_id'] ?>">
                            <div class="notif-dot read"></div>
                            <div class="notif-item-body">
                                <div class="notif-item-title">✅ <?= htmlspecialchars($ap['title']) ?></div>
                                <div class="notif-item-msg">
                                    <?php if ($ap['category']): ?>
                                    <span style="display:inline-block;background:#f0fdf4;color:#166534;font-size:10.5px;font-weight:600;padding:1px 7px;border-radius:10px;margin-bottom:3px;">
                                        <?= htmlspecialchars(ucfirst($ap['category'])) ?>
                                    </span>
                                    <?php endif; ?>
                                    <span style="display:block;margin-top:2px;color:#10b981;font-weight:600;">
                                        Last score: <?= $score ?>
                                        · <?= $ap['attempts_used'] ?> attempt<?= $ap['attempts_used'] > 1 ? 's' : '' ?>
                                    </span>
                                </div>
                                <div class="notif-item-time"><?= timeAgo($ap['created_at']) ?></div>
                            </div>
                            <button class="notif-dismiss-btn" title="Remove from notifications"
                                onclick="event.stopPropagation(); dismissAssessNotif(<?= $ap['assessment_id'] ?>)"
                                aria-label="Remove test notification">✕</button>
                        </div>
                        <?php endforeach; endif; ?>

                        <?php /* ── Regular system notifications ── */
                        if (!empty($notifItems)): ?>
                        <?php if (!empty($assessNotifNew) || !empty($assessNotifPrev)): ?>
                        <div class="notif-section-label">🔔 Notifications</div>
                        <?php endif; ?>
                        <?php foreach ($notifItems as $n):
                            $isUnread = !$n['is_read'];
                            $icon = '🔔';
                            $entityId  = (int)($n['related_entity_id'] ?? 0);
                            $nType     = $n['type'] ?? '';
                            $hasLink   = in_array($nType, ['assessment', 'material', 'result']) && $entityId > 0;
                            $redirectUrl = $hasLink
                                ? 'api/notifications/notification-redirect.php?notification_id=' . $n['notification_id']
                                : '';
                        ?>
                        <div class="notif-item <?= $isUnread ? 'unread' : '' ?>" id="notif-<?= $n['notification_id'] ?>"
                             <?php if ($redirectUrl): ?>
                             onclick="handleNotifClick(<?= $n['notification_id'] ?>, '<?= $redirectUrl ?>')"
                             style="cursor:pointer;"
                             <?php endif; ?>>
                            <div class="notif-dot <?= $isUnread ? '' : 'read' ?>"></div>
                            <div class="notif-item-body">
                                <div class="notif-item-title"><?= $icon ?> <?= htmlspecialchars($n['title']) ?></div>
                                <?php if ($n['message']): ?>
                                <div class="notif-item-msg"><?= htmlspecialchars($n['message']) ?></div>
                                <?php endif; ?>
                                <div class="notif-item-time"><?= timeAgo($n['created_at']) ?></div>
                            </div>
                            <button class="notif-dismiss-btn" title="Dismiss"
                                onclick="event.stopPropagation(); dismissNotification(<?= $n['notification_id'] ?>)"
                                aria-label="Dismiss notification">✕</button>
                        </div>
                        <?php endforeach; endif; ?>

                        <?php if (empty($notifItems) && empty($assessNotifNew) && empty($assessNotifPrev)): ?>
                            <div class="notif-empty">No notifications yet.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="profile-dropdown-container">
                <button class="profile-button" onclick="toggleProfileDropdown()" aria-label="Profile menu" aria-expanded="false">
                    <?php if ($userProfileImage && file_exists($userProfileImage)): ?>
                        <img src="<?= htmlspecialchars($userProfileImage) ?>?v=<?= time() ?>" alt="Avatar" style="width:32px;height:32px;border-radius:8px;object-fit:cover;flex-shrink:0;">
                    <?php else: ?>
                        <div class="profile-avatar" aria-hidden="true"><?php echo $userInitials; ?></div>
                    <?php endif; ?>
                    <span class="profile-name"><?php echo htmlspecialchars($userName); ?></span>
                    <span class="dropdown-arrow">▼</span>
                </button>
                <div class="profile-dropdown" id="profileDropdown">
                    <div class="dropdown-header">
                        <div class="dropdown-avatar">
                            <?php if ($userProfileImage && file_exists($userProfileImage)): ?>
                                <img src="<?= htmlspecialchars($userProfileImage) ?>?v=<?= time() ?>" alt="Avatar" style="width:44px;height:44px;border-radius:12px;object-fit:cover;">
                            <?php else: ?>
                                <?php echo $userInitials; ?>
                            <?php endif; ?>
                        </div>
                        <div class="dropdown-user-info">
                            <div class="dropdown-user-name"><?php echo htmlspecialchars($userName); ?></div>
                            <div class="dropdown-user-email"><?php echo htmlspecialchars($userEmail); ?></div>
                        </div>
                    </div>
                    <div class="dropdown-menu">
                        <a href="student-profile.php" class="dropdown-item">
                            <span class="dropdown-item-icon">👤</span>
                            <span>My Profile</span>
                        </a>
                        <a href="help.html" class="dropdown-item" style="display:flex;align-items:center;gap:11px;padding:10px 12px;border-radius:8px;font-size:13.5px;color:#475569;font-family:'Inter',sans-serif;text-decoration:none;transition:.15s;"><span class="dropdown-item-icon">🚩</span><span>Help &amp; Support</span></a>
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

    <div class="dropdown-overlay" id="dropdownOverlay" onclick="closeProfileDropdown()"></div>

    <div class="page-wrapper">
    <aside class="left-sidebar">
        <span class="left-sidebar-label">Navigation</span>
        <a href="student-dashboard.php" class="active"><i class="fa fa-home"></i> Dashboard</a>
        <a href="student-assessments.php"><i class="fa fa-clipboard-list"></i> Assessments</a>
        <a href="self-assessment.php"><i class="fa fa-user-check"></i> Self Assessment</a>
        <a href="student-resources.php"><i class="fa fa-folder-open"></i> Resources</a>

        <div class="left-sidebar-bottom">
            <button onclick="handleLogout()"><i class="fa fa-sign-out-alt"></i> Logout</button>
        </div>
    </aside>
    <div class="page-content">
    <div class="container" style="padding: 0; max-width: 100%;">
        <?php if (!empty($_GET['notif_stale'])): ?>
        <div id="staleToast" style="
            display:flex;align-items:center;gap:12px;
            background:#fff8ed;border:1.5px solid #f59e0b;
            border-radius:12px;padding:14px 18px;margin-bottom:20px;
            box-shadow:0 2px 12px rgba(245,158,11,.15);
            animation:fadeUp .3s ease both;">
            <span style="font-size:20px;flex-shrink:0;">⚠️</span>
            <div style="flex:1;">
                <div style="font-weight:700;font-size:13.5px;color:#92400e;">This item is no longer available</div>
                <div style="font-size:12.5px;color:#b45309;margin-top:2px;">The test or resource linked to that notification was removed or has expired.</div>
            </div>
            <button onclick="document.getElementById('staleToast').remove()" style="
                background:none;border:none;cursor:pointer;
                color:#b45309;font-size:18px;padding:2px 6px;
                border-radius:6px;line-height:1;transition:.15s;">✕</button>
        </div>
        <script>setTimeout(()=>{ const t=document.getElementById('staleToast'); if(t){t.style.transition='opacity .4s';t.style.opacity='0';setTimeout(()=>t.remove(),400);} }, 5000);</script>
        <?php endif; ?>
        <div class="welcome-section">
            <div class="welcome-content">
                <h1>Welcome back, <?php echo strtoupper(htmlspecialchars($userName)); ?> 👋</h1>
                <p>Ready to continue your learning journey?</p>
            </div>
            <div class="quick-stats">
                <div class="stat-item">
                    <span class="stat-number"><?php echo $testsCompleted; ?></span>
                    <span class="stat-label">Tests Completed</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number"><?php echo $availableTests; ?></span>
                    <span class="stat-label">Available</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number"><?php echo $avgScore; ?>%</span>
                    <span class="stat-label">Avg. Score</span>
                </div>
            </div>
        </div>

        <div class="main-content">
            <div class="assessments-section" id="assessments-section">
                <div class="section-header">
                    <div>
                        <h2 class="section-title">Available Assessments</h2>
                        <p style="font-size:13px;color:var(--text-soft);margin-top:3px;">Showing latest 3 — <a href="student-assessments.php" style="color:var(--accent);font-weight:600;text-decoration:none;">view all</a></p>
                    </div>
                    <a href="student-assessments.php" class="view-all-link">View All Tests →</a>
                </div>
                <div class="filter-tabs">
                    <button class="filter-tab active" data-category="all">All Tests</button>
                    <button class="filter-tab" data-category="aptitude">Aptitude</button>
                    <button class="filter-tab" data-category="technical">Technical</button>
                    <button class="filter-tab" data-category="coding">Coding</button>
                    <button class="filter-tab" data-category="reasoning">Reasoning</button>
                    <button class="filter-tab" data-category="english">English</button>
                </div>
                <div class="assessment-list">
                    <?php if ($assessmentError): ?>
                        <div class="state-message state-error">
                            <span class="state-icon">⚠️</span>
                            <p>Could not load assessments. Please contact your administrator.</p>
                        </div>
                    <?php elseif (empty($assessments)): ?>
                        <div class="state-message state-empty">
                            <span class="state-icon">📋</span>
                            <p>No assessments available at the moment. Check back later.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($assessments as $a):
                            $id           = (int) $a['assessment_id'];
                            $lastAttemptId = (int) ($a['last_attempt_id'] ?? 0);
                            $attemptsLeft = (int)$a['max_attempts'] - (int)$a['attempts_used'];
                            $exhausted    = $attemptsLeft <= 0;
                            $catClass     = htmlspecialchars(strtolower($a['category'] ?? ''));
                            $diff         = strtolower($a['difficulty'] ?? 'medium');
                            $diffLabel    = ucfirst($diff);
                            $deadline     = ($a['end_time'] ?? null)
                                            ? date('d M Y, g:i A', strtotime($a['end_time']))
                                            : null;
                        ?>
                        <div class="assessment-card <?= $exhausted ? 'exhausted' : '' ?>" data-category="<?= $catClass ?>">
                            <div class="assessment-header">
                                <div>
                                    <div class="assessment-title"><?= htmlspecialchars($a['title']) ?></div>
                                    <div class="assessment-category">
                                        <?= htmlspecialchars(ucfirst($a['category'] ?? '')) ?>
                                        <?php if ($deadline): ?> • Due <?= $deadline ?><?php endif ?>
                                    </div>
                                </div>
                                <span class="difficulty-badge <?= $diff ?>"><?= $diffLabel ?></span>
                            </div>
                            <div class="assessment-meta">
                                <div class="meta-item"><span>❓</span><span><?= (int)$a['question_count'] ?> Questions</span></div>
                                <div class="meta-item"><span>⏱️</span><span><?= (int)$a['duration_minutes'] ?> Minutes</span></div>
                                <div class="meta-item"><span>🏆</span><span><?= (int)$a['total_marks'] ?> Points</span></div>
                                <?php if ((int)$a['max_attempts'] > 1): ?>
                                <div class="meta-item">
                                    <span>🔄</span>
                                    <span><?= $exhausted ? 'No attempts left' : "$attemptsLeft attempt(s) left" ?></span>
                                </div>
                                <?php endif ?>
                            </div>
                            <?php if (!$exhausted): ?>
                            <div class="assessment-actions">
                                <button class="btn-start" onclick="startAssessment(<?= $id ?>)">Start Test</button>

                            </div>
                            <?php else: ?>
                            <div class="assessment-actions">
                                <button class="btn-details" onclick="viewDetails(<?= $lastAttemptId ?>)">View Results</button>
                            </div>
                            <?php endif ?>
                        </div>
                        <?php endforeach ?>
                    <?php endif ?>
                </div>
            </div>

            <div class="sidebar">
                <div class="sidebar-card">
                    <h3 class="sidebar-card-title">Recent Activity</h3>
                    <div class="activity-list">
                        <?php if (empty($recentActivity)): ?>
                            <p style="color:#a0aec0;font-size:14px;text-align:center;padding:10px 0">
                                No completed tests yet.
                            </p>
                        <?php else: ?>
                            <?php foreach ($recentActivity as $act): ?>
                            <div class="activity-item">
                                <div class="activity-icon">✅</div>
                                <div class="activity-content">
                                    <div class="activity-title">Completed: <?= htmlspecialchars($act['title']) ?></div>
                                    <div class="activity-time">
                                        <?= timeAgo($act['submitted_at']) ?> • Score: <?= round((float)$act['percentage']) ?>%
                                    </div>
                                </div>
                            </div>
                            <?php endforeach ?>
                        <?php endif ?>
                    </div>
                </div>

                <div class="sidebar-card">
                    <h3 class="sidebar-card-title">Your Progress</h3>

                    <?php if (empty($scoreData) && empty($catData)): ?>
                        <div style="text-align:center;padding:30px 10px;color:#a0aec0;font-size:13px;">
                            📊 Complete your first test to see charts here.
                        </div>
                    <?php else: ?>

                    <?php if (!empty($scoreData)): ?>
                    <div style="margin-bottom:18px;">
                        <div style="font-size:12px;font-weight:600;color:#718096;margin-bottom:8px;text-transform:uppercase;letter-spacing:.05em;">Score % — Last <?= count($scoreData) ?> Attempts</div>
                        <canvas id="scoreChart" height="130"></canvas>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($catData)): ?>
                    <div style="margin-bottom:10px;">
                        <div style="font-size:12px;font-weight:600;color:#718096;margin-bottom:8px;text-transform:uppercase;letter-spacing:.05em;">Tests by Category</div>
                        <canvas id="catChart" height="130"></canvas>
                    </div>
                    <?php endif; ?>

                    <?php endif; ?>

                    <div class="overall-progress">
                        <div class="progress-label">
                            <span style="font-weight:600">Overall Completion</span>
                            <span style="color:var(--accent);font-weight:700"><?= $completionPct ?>%</span>
                        </div>
                        <div class="progress-bar-container">
                            <div class="progress-bar-fill" style="width:<?= $completionPct ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div><!-- /.container -->
    </div><!-- /.page-content -->
    </div><!-- /.page-wrapper -->

    <script>
        const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;

        // Preserve scroll position on refresh
        window.addEventListener('beforeunload', () => {
            sessionStorage.setItem('scrollPos', window.scrollY);
        });
        window.addEventListener('load', () => {
            const pos = sessionStorage.getItem('scrollPos');
            if (pos) window.scrollTo(0, parseInt(pos));
        });

        // Smart notification cleanup on page load:
        // removes completed/expired assessment and viewed/expired resource notifications
        fetch('api/notifications/cleanup-notifications.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name=csrf]')?.content || '' },
        }).catch(() => {});

        function toggleProfileDropdown() {
            const dropdown = document.getElementById('profileDropdown');
            const overlay = document.getElementById('dropdownOverlay');
            dropdown.classList.toggle('show');
            overlay.classList.toggle('show');
        }

        function closeProfileDropdown() {
            document.getElementById('profileDropdown').classList.remove('show');
            document.getElementById('notifDropdown').classList.remove('show');
            document.getElementById('dropdownOverlay').classList.remove('show');
        }

        function handleLogout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = 'logout.php';
            }
        }

        function toggleNotifDropdown() {
            const dd = document.getElementById('notifDropdown');
            const overlay = document.getElementById('dropdownOverlay');
            const isOpen = dd.classList.contains('show');
            // Close profile dropdown if open
            document.getElementById('profileDropdown').classList.remove('show');
            dd.classList.toggle('show', !isOpen);
            overlay.classList.toggle('show', !isOpen);
            // Mark all as read when opening
            if (!isOpen) {
                fetch('api/notifications/mark-read.php', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': CSRF_TOKEN, 'Content-Type': 'application/json' }
                }).then(() => {
                    // Clear badge
                    const badge = document.querySelector('.notification-badge');
                    if (badge) badge.remove();
                    // Remove unread highlights
                    document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
                    document.querySelectorAll('.notif-dot:not(.read)').forEach(el => el.classList.add('read'));
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
                    // Backend rejected — restore element and abort
                    if (el) { el.style.opacity = '1'; el.style.pointerEvents = ''; }
                    return;
                }
            } catch(e) {
                // Network error — restore element and abort
                if (el) { el.style.opacity = '1'; el.style.pointerEvents = ''; }
                return;
            }
            // Only remove from DOM after backend confirmed deletion
            if (el) el.remove();
            // Update badge count
            const badge = document.querySelector('.notification-badge');
            if (badge) {
                const cur = parseInt(badge.textContent) || 0;
                if (cur <= 1) badge.remove();
                else badge.textContent = cur - 1;
            }
            // Show empty state if no items left
            const list = document.querySelector('.notif-list');
            if (list && list.querySelectorAll('.notif-item').length === 0) {
                list.innerHTML = '<div class="notif-empty">No notifications yet.</div>';
            }
        }

        // Dismiss notification then navigate via server-side redirect
        // The redirect API validates entity still exists before sending user there
        function handleNotifClick(notifId, redirectUrl) {
            const el = document.getElementById('notif-' + notifId);
            if (el) { el.style.opacity = '0.5'; el.style.pointerEvents = 'none'; }
            window.location.href = redirectUrl;
        }

        function startAssessment(id) {
            if (confirm('Are you ready to start this assessment?')) {
                window.location.href = 'test-preview.php?id=' + id;
            }
        }

        function viewDetails(id) {
            window.location.href = 'test-results.php?attempt_id=' + id;
        }

        // Filter tabs
        document.querySelectorAll('.filter-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                const category = this.dataset.category;
                document.querySelectorAll('.assessment-card').forEach(card => {
                    if (category === 'all' || card.dataset.category === category) {
                        card.classList.remove('hidden');
                    } else {
                        card.classList.add('hidden');
                    }
                });
            });
        });

        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const search = e.target.value.toLowerCase();
            document.querySelectorAll('.assessment-card').forEach(card => {
                const title = card.querySelector('.assessment-title').textContent.toLowerCase();
                const category = card.querySelector('.assessment-category').textContent.toLowerCase();
                if (title.includes(search) || category.includes(search)) {
                    card.classList.remove('hidden');
                } else {
                    card.classList.add('hidden');
                }
            });
        });

        // Animate progress bars on load
        window.addEventListener('load', function() {
            document.querySelectorAll('.progress-bar-fill').forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0';
                setTimeout(() => bar.style.width = width, 100);
            });
        });

        // ── Dismiss an assessment notification (previous tests only) ──
        function dismissAssessNotif(assessmentId) {
            const el = document.getElementById('assess-notif-' + assessmentId);
            if (!el) return;

            // Animate out
            el.style.transition  = 'opacity .25s, max-height .3s, padding .3s';
            el.style.overflow    = 'hidden';
            el.style.maxHeight   = el.offsetHeight + 'px';
            el.style.opacity     = '0';
            requestAnimationFrame(() => {
                el.style.maxHeight   = '0';
                el.style.padding     = '0';
                el.style.borderWidth = '0';
            });
            setTimeout(() => {
                el.remove();
                // Also remove section label if no siblings left
                document.querySelectorAll('.notif-section-label').forEach(label => {
                    let next = label.nextElementSibling;
                    let hasItems = false;
                    while (next && !next.classList.contains('notif-section-label')) {
                        if (next.classList.contains('notif-item')) { hasItems = true; break; }
                        next = next.nextElementSibling;
                    }
                    if (!hasItems) label.remove();
                });
                // Show empty state if everything gone
                const list = document.querySelector('.notif-list');
                if (list && list.querySelectorAll('.notif-item').length === 0
                        && !list.querySelector('.notif-empty')) {
                    list.innerHTML = '<div class="notif-empty">No notifications yet.</div>';
                }
            }, 320);

            // Persist dismissal to server
            fetch('api/notifications/dismiss-assess-notif.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ assessment_id: assessmentId, csrf_token: '<?= $_SESSION['csrf_token'] ?>' })
            }).catch(() => {});
        }

        // ── Mark all regular notifications read ──
        function markAllRead() {
            fetch('api/notifications/mark-all-read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: '<?= $_SESSION['csrf_token'] ?>' })
            }).then(() => {
                document.querySelectorAll('.notif-item.unread:not(.assess-notif)').forEach(el => {
                    el.classList.remove('unread');
                    const dot = el.querySelector('.notif-dot');
                    if (dot) dot.classList.add('read');
                });
                // Remove "Mark all read" button
                const btn = document.querySelector('[onclick="markAllRead()"]');
                if (btn) btn.remove();
                updateNotifBadge(0);
            }).catch(() => {});
        }

        // ── Live notification sync (badge + DOM) ──
        let lastUnreadCount = <?= $unreadCount + $assessNotifCount ?>;
        let lastPollTime    = 0;

        function updateNotifBadge(count) {
            let badge = document.querySelector('.notification-badge');
            if (count > 0) {
                if (!badge) {
                    badge = document.createElement('div');
                    badge.className = 'notification-badge';
                    document.querySelector('#notifBtn').appendChild(badge);
                }
                badge.textContent = count > 99 ? '99+' : count;
            } else {
                if (badge) badge.remove();
            }
        }

        function syncNotifications() {
            if (document.hidden) return;
            if (Date.now() - lastPollTime < 30000) return;

            fetch('api/notifications/active-ids.php')
                .then(r => r.json())
                .then(data => {
                    if (!data.success) return;
                    lastPollTime = Date.now();

                    // ── 1. Update badge ──
                    updateNotifBadge(data.unread_count);
                    lastUnreadCount = data.unread_count;

                    // ── 2. Remove any DOM items not in the active list ──
                    const activeSet = new Set(data.ids);
                    const list = document.querySelector('.notif-list');
                    if (!list) return;

                    list.querySelectorAll('.notif-item[id^="notif-"]').forEach(el => {
                        const id = parseInt(el.id.replace('notif-', ''));
                        if (!activeSet.has(id)) {
                            el.style.transition  = 'opacity .25s, max-height .3s, padding .3s';
                            el.style.overflow    = 'hidden';
                            el.style.maxHeight   = el.offsetHeight + 'px';
                            el.style.opacity     = '0';
                            requestAnimationFrame(() => {
                                el.style.maxHeight   = '0';
                                el.style.padding     = '0';
                                el.style.borderWidth = '0';
                            });
                            setTimeout(() => el.remove(), 320);
                        }
                    });

                    // ── 3. Show empty state if all items removed ──
                    setTimeout(() => {
                        if (list && list.querySelectorAll('.notif-item').length === 0
                            && !list.querySelector('.notif-empty')) {
                            list.innerHTML = '<div class="notif-empty">No notifications yet.</div>';
                        }
                    }, 350);
                }).catch(() => {});
        }

        // Run immediately on load, then every 30s
        window.addEventListener('load', syncNotifications);
        setInterval(syncNotifications, 30000);
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <script>
        // ── Score % line chart ──
        <?php if (!empty($scoreData)): ?>
        new Chart(document.getElementById('scoreChart'), {
            type: 'line',
            data: {
                labels: <?= json_encode($scoreLabels) ?>,
                datasets: [{
                    label: 'Score %',
                    data: <?= json_encode($scoreData) ?>,
                    borderColor: '#0ea5e9',
                    backgroundColor: 'rgba(14,165,233,0.1)',
                    borderWidth: 2,
                    pointBackgroundColor: '#0ea5e9',
                    pointRadius: 4,
                    tension: 0.4,
                    fill: true,
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    y: {
                        min: 0, max: 100,
                        ticks: { font: { size: 11 }, callback: v => v + '%' },
                        grid: { color: 'rgba(0,0,0,0.04)' }
                    },
                    x: { ticks: { font: { size: 10 } }, grid: { display: false } }
                }
            }
        });
        <?php endif; ?>

        // ── Category doughnut chart ──
        <?php if (!empty($catData)): ?>
        new Chart(document.getElementById('catChart'), {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($catLabels) ?>,
                datasets: [{
                    data: <?= json_encode($catData) ?>,
                    backgroundColor: ['#0ea5e9','#8b5cf6','#10b981','#f59e0b','#ef4444','#06b6d4'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                cutout: '60%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { font: { size: 11 }, padding: 10, boxWidth: 12 }
                    }
                }
            }
        });
        <?php endif; ?>
    </script>
<?php if ($showDuePopup): ?>
<!-- ══ DUE SOON POPUP ══ -->
<div id="duePopupOverlay" style="
    position:fixed;inset:0;background:rgba(0,0,0,.5);
    z-index:9000;display:flex;align-items:center;justify-content:center;
    backdrop-filter:blur(4px);animation:fadeIn .25s ease;">
    <div style="
        background:#fff;border-radius:20px;width:100%;max-width:460px;
        box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;
        animation:slideUp .3s cubic-bezier(.4,0,.2,1);">

        <!-- Header -->
        <div style="
            background:linear-gradient(135deg,#1a3a52,#1e5276);
            padding:24px 28px 20px;position:relative;">
            <div style="font-size:28px;margin-bottom:6px;">⏰</div>
            <div style="font-family:'Sora',sans-serif;font-size:18px;font-weight:800;color:#fff;">
                Upcoming Deadlines
            </div>
            <div style="font-size:13px;color:rgba(255,255,255,.65);margin-top:3px;">
                You have <?= count($duePopupAssessments) ?> test<?= count($duePopupAssessments) > 1 ? 's' : '' ?> due soon
            </div>
            <button onclick="closeDuePopup()" style="
                position:absolute;top:16px;right:16px;
                background:rgba(255,255,255,.15);border:none;border-radius:8px;
                color:#fff;width:32px;height:32px;font-size:18px;cursor:pointer;
                display:flex;align-items:center;justify-content:center;
                transition:.2s;">✕</button>
        </div>

        <!-- List -->
        <div style="padding:16px 20px;max-height:340px;overflow-y:auto;">
            <?php foreach ($duePopupAssessments as $da):
                $urgencyColor = match($da['urgency']) {
                    'today'    => '#ef4444',
                    'tomorrow' => '#f59e0b',
                    default    => '#0ea5e9',
                };
                $urgencyBg = match($da['urgency']) {
                    'today'    => '#fef2f2',
                    'tomorrow' => '#fffbeb',
                    default    => '#eff8ff',
                };
                $formattedDate = date('d M Y, h:i A', strtotime($da['end_time']));
            ?>
            <div style="
                display:flex;align-items:center;gap:14px;
                padding:14px 16px;border-radius:12px;margin-bottom:10px;
                background:<?= $urgencyBg ?>;border:1.5px solid <?= $urgencyColor ?>22;">
                <div style="
                    width:42px;height:42px;border-radius:10px;flex-shrink:0;
                    background:<?= $urgencyColor ?>;
                    display:flex;align-items:center;justify-content:center;
                    font-size:20px;">
                    <?= $da['urgency'] === 'today' ? '🔴' : ($da['urgency'] === 'tomorrow' ? '🟡' : '🔵') ?>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:700;font-size:14px;color:#0f172a;
                        white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?= htmlspecialchars($da['title']) ?>
                    </div>
                    <div style="font-size:11.5px;color:#64748b;margin-top:2px;">
                        📅 <?= $formattedDate ?>
                    </div>
                </div>
                <span style="
                    background:<?= $urgencyColor ?>;color:#fff;
                    font-size:11px;font-weight:700;padding:4px 10px;
                    border-radius:20px;white-space:nowrap;flex-shrink:0;">
                    <?= htmlspecialchars($da['label']) ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Footer -->
        <div style="padding:16px 20px;border-top:1px solid #e2e8f0;display:flex;gap:10px;">
            <button onclick="closeDuePopup()" style="
                flex:1;padding:11px;border-radius:10px;border:1.5px solid #e2e8f0;
                background:#fff;color:#475569;font-size:13.5px;font-weight:600;
                cursor:pointer;font-family:'Inter',sans-serif;transition:.2s;">
                Remind me later
            </button>
            <button onclick="closeDuePopup();window.location.href='student-assessments.php'" style="
                flex:1;padding:11px;border-radius:10px;border:none;
                background:linear-gradient(135deg,#0ea5e9,#06b6d4);
                color:#fff;font-size:13.5px;font-weight:700;
                cursor:pointer;font-family:'Inter',sans-serif;transition:.2s;">
                View Tests →
            </button>
        </div>
    </div>
</div>

<style>
@keyframes fadeIn  { from { opacity:0 } to { opacity:1 } }
@keyframes slideUp { from { opacity:0;transform:translateY(20px) scale(.97) } to { opacity:1;transform:translateY(0) scale(1) } }
</style>

<script>
function closeDuePopup() {
    const overlay = document.getElementById('duePopupOverlay');
    if (overlay) {
        overlay.style.animation = 'fadeIn .2s ease reverse';
        setTimeout(() => overlay.remove(), 200);
    }
}
// Close on overlay click
document.getElementById('duePopupOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeDuePopup();
});
// Close on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeDuePopup();
});
</script>
<?php endif; ?>

</body>
</html>
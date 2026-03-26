<?php
/* ========================================
 * EDIT ASSESSMENT
 * File: edit-assessment.php
 *
 * Requires: ?id=<assessment_id>
 * Access:   Teachers only — ownership verified
 * ======================================== */

require 'config.php';

// ── CSRF token for report form ──
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Report status ──
$reportStatusResult = safePreparedQuery($conn,
    "SELECT status FROM student_reports WHERE user_id = ? ORDER BY created_at DESC LIMIT 1",
    "i", [$teacherId]
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
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if ($file['size'] <= 5 * 1024 * 1024 && in_array($file['type'], $allowed)) {
            $ext       = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $uploadDir = 'uploads/reports/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $stored    = 'report_' . $teacherId . '_' . time() . '.' . $ext;
            $fullPath  = $uploadDir . $stored;
            if (move_uploaded_file($file['tmp_name'], $fullPath)) {
                $reportImage = $fullPath;
            }
        }
    }

    if ($reportTitle !== '' && $reportDesc !== '') {
        $insResult = safePreparedQuery($conn,
            "INSERT INTO student_reports (user_id, title, description, image_path, status, created_at)
             VALUES (?, ?, ?, ?, 'pending', NOW())",
            "isss", [$teacherId, $reportTitle, $reportDesc, $reportImage]
        );
        if ($insResult['success']) {
            $hasOpenReport      = true;
            $latestReportStatus = 'pending';

            // ── Notify all admins ──
            $notifTitle   = '🚩 Teacher Report: ' . mb_strimwidth($reportTitle, 0, 60, '…');
            $notifMessage = $currentUser['full_name'] . ' submitted a support report: ' . mb_strimwidth($reportDesc, 0, 120, '…');
            $adminResult  = safePreparedQuery($conn,
                "SELECT user_id FROM users WHERE role = 'admin'",
                "", []
            );
            if ($adminResult['success'] && $adminResult['result']) {
                while ($adminRow = $adminResult['result']->fetch_assoc()) {
                    safePreparedQuery($conn,
                        "INSERT INTO notifications (user_id, title, message, type, is_read, created_at)
                         VALUES (?, ?, ?, 'warning', 0, NOW())",
                        "iss", [(int)$adminRow['user_id'], $notifTitle, $notifMessage]
                    );
                }
                $adminResult['result']->free();
            }
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?report=sent');
    exit;
}

require_once 'db-guard.php';

$currentUser = validateSession($conn, 'teacher');
$teacherId   = (int) $currentUser['user_id'];
$userName     = htmlspecialchars($currentUser['full_name'] ?? 'Teacher');
$userEmail    = htmlspecialchars($currentUser['email'] ?? '');
$userInitials = strtoupper(substr($currentUser['full_name'] ?? 'T', 0, 2));

// Fetch profile_image (validateSession may not include it)
$picStmt = $conn->prepare("SELECT profile_image FROM users WHERE user_id = ?");
$picStmt->bind_param("i", $teacherId);
$picStmt->execute();
$picRow      = $picStmt->get_result()->fetch_assoc();
$userPicture = $picRow['profile_image'] ?? '';


$assessmentId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($assessmentId <= 0) {
    header('Location: teacher-dashboard.php?error=invalid_id');
    exit;
}

// ── Load assessment — verify ownership ──
$assessment = null;
$r = safePreparedQuery($conn,
    "SELECT
        assessment_id,
        title,
        description,
        category,
        difficulty,
        status,
        visibility,
        duration_minutes,
        total_marks,
        passing_marks,
        start_time,
        end_time,
        max_attempts,
        randomize_questions,
        randomize_options,
        created_at,
        updated_at,
        created_by
     FROM assessments
     WHERE assessment_id = ? AND created_by = ?",
    "ii", [$assessmentId, $teacherId]
);
if ($r['success'] && $r['result']) {
    $assessment = $r['result']->fetch_assoc();
    $r['result']->free();
}
if (!$assessment) {
    header('Location: teacher-dashboard.php?error=not_found');
    exit;
}

// ── Load questions with their options ──
// Fetch questions first, then options separately and map by question_id
$questions = [];
$rq = safePreparedQuery($conn,
    "SELECT
        question_id,
        question_type,
        question_text,
        marks,
        negative_marks,
        explanation,
        question_order
     FROM questions
     WHERE assessment_id = ?
     ORDER BY question_order ASC, question_id ASC",
    "i", [$assessmentId]
);
if ($rq['success'] && $rq['result']) {
    while ($row = $rq['result']->fetch_assoc()) {
        $row['options'] = [];
        $questions[$row['question_id']] = $row;
    }
    $rq['result']->free();
}

// Fetch all options for this assessment's questions in one query
if (!empty($questions)) {
    $qids        = implode(',', array_keys($questions));
    $ropts = safePreparedQuery($conn,
        "SELECT qo.option_id, qo.question_id, qo.option_text, qo.is_correct, qo.option_order
         FROM question_options qo
         WHERE qo.question_id IN ($qids)
         ORDER BY qo.option_order ASC, qo.option_id ASC",
        "", []
    );
    // safePreparedQuery with no params — use direct query instead
    $ropts2 = $conn->query(
        "SELECT option_id, question_id, option_text, is_correct, option_order
         FROM question_options
         WHERE question_id IN ($qids)
         ORDER BY option_order ASC, option_id ASC"
    );
    if ($ropts2) {
        while ($opt = $ropts2->fetch_assoc()) {
            $qid = (int)$opt['question_id'];
            if (isset($questions[$qid])) {
                $questions[$qid]['options'][] = $opt;
            }
        }
        $ropts2->free();
    }
}
$questions = array_values($questions);

// ── Attempt stats ──
$stats = ['total_attempts' => 0, 'avg_score' => 0, 'avg_time' => 0, 'completion_rate' => 0];
$rs = safePreparedQuery($conn,
    "SELECT
        COUNT(*) AS total_attempts,
        ROUND(AVG(percentage), 1) AS avg_score,
        ROUND(AVG(TIMESTAMPDIFF(MINUTE, aa.start_time, aa.submitted_at)), 0) AS avg_time,
        ROUND(
            100.0 * SUM(CASE WHEN status IN ('submitted','timeout') THEN 1 ELSE 0 END)
            / NULLIF(COUNT(*), 0), 1
        ) AS completion_rate
     FROM assessment_attempts aa
     WHERE assessment_id = ? AND start_time IS NOT NULL",
    "i", [$assessmentId]
);
if ($rs['success'] && $rs['result']) {
    $row = $rs['result']->fetch_assoc();
    if ($row) {
        $stats['total_attempts']  = (int)($row['total_attempts'] ?? 0);
        $stats['avg_score']       = (float)($row['avg_score'] ?? 0);
        $stats['avg_time']        = (int)($row['avg_time'] ?? 0);
        $stats['completion_rate'] = (float)($row['completion_rate'] ?? 0);
    }
    $rs['result']->free();
}

// ── Load teacher's groups ──
$teacherGroups = [];
$rg = safePreparedQuery($conn,
    "SELECT g.group_id, g.name, COUNT(gm.student_id) AS member_count
     FROM groups g
     LEFT JOIN group_members gm ON gm.group_id = g.group_id
     WHERE g.teacher_id = ?
     GROUP BY g.group_id ORDER BY g.name ASC",
    "i", [$teacherId]
);
if ($rg['success'] && $rg['result']) {
    while ($row = $rg['result']->fetch_assoc()) $teacherGroups[] = $row;
    $rg['result']->free();
}

// ── Load existing targets ──
$existingTargets = [];
$rt = safePreparedQuery($conn,
    "SELECT target_type, target_id FROM assessment_targets WHERE assessment_id = ?",
    "i", [$assessmentId]
);
if ($rt['success'] && $rt['result']) {
    while ($row = $rt['result']->fetch_assoc())
        $existingTargets[] = ['type' => $row['target_type'], 'id' => (int)$row['target_id']];
    $rt['result']->free();
}

function jsStr(string $s): string {
    return addslashes(htmlspecialchars($s, ENT_QUOTES, 'UTF-8'));
}
function toDatetimeLocal(?string $dt): string {
    if (!$dt) return '';
    return date('Y-m-d\TH:i', strtotime($dt));
}
function fmtDate(?string $dt): string {
    if (!$dt) return '—';
    return date('M j, Y', strtotime($dt));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit: <?= htmlspecialchars($assessment['title']) ?> — PREPAURA</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">
    <style>
        /* ── DESIGN TOKENS ── */
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

        /* ── BASE ── */
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        html { -webkit-font-smoothing: antialiased; scroll-behavior: smooth; }
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
        .brand-tagline { font-size: 10px; color: rgba(255,255,255,0.45); letter-spacing: 0.03em; }

        .nav-right { display: flex; align-items: center; gap: 12px; }
        .profile-wrap { position: relative; }
        .profile-button {
            display: flex; align-items: center; gap: 9px;
            padding: 6px 12px 6px 6px;
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 40px; cursor: pointer;
            transition: var(--t); color: white;
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

        /* ── DROPDOWN ── */
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
            text-align: left;
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
            color: var(--orchid); border-radius: 20px;
            font-size: 11px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase;
        }
        .dropdown-menu { padding: 6px 0; }
        .dropdown-item {
            display: flex; align-items: center; gap: 11px;
            padding: 10px 18px; color: var(--text-2);
            text-decoration: none; font-size: 13.5px; transition: var(--t);
            cursor: pointer; border: none; background: none;
            width: 100%; text-align: left; font-family: var(--font-body);
        }
        .dropdown-item i { width: 16px; text-align: center; color: var(--text-3); }
        .dropdown-item:hover { background: var(--surface-2); color: var(--text-1); }
        .dropdown-item.danger { color: var(--rose); }
        .dropdown-item.danger i { color: var(--rose); }
        .dropdown-item.danger:hover { background: rgba(244,63,94,0.06); }
        .dropdown-divider { height: 1px; background: var(--border); margin: 4px 0; }

        /* ── LAYOUT ── */
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
            font-family: var(--font-head); font-size: 10px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.1em; color: var(--text-3);
            padding: 14px 14px 6px;
        }
        .sidebar-link {
            display: flex; align-items: center; gap: 10px; padding: 10px 14px;
            border-radius: var(--r-sm); text-decoration: none;
            font-size: 13.5px; font-weight: 500; color: var(--text-2); transition: var(--t);
        }
        .sidebar-link i { width: 18px; text-align: center; font-size: 14px; color: var(--text-3); transition: var(--t); }
        .sidebar-link:hover { background: var(--violet-dim); color: var(--violet); }
        .sidebar-link:hover i { color: var(--violet); }
        .sidebar-link.active {
            background: linear-gradient(135deg, rgba(124,58,237,0.12), rgba(192,132,252,0.08));
            color: var(--violet); font-weight: 600;
            box-shadow: inset 3px 0 0 var(--violet);
        }
        .sidebar-link.active i { color: var(--violet); }
        .sidebar-bottom { margin-top: auto; padding-top: 16px; border-top: 1px solid var(--border); }
        .sidebar-logout {
            display: flex; align-items: center; gap: 10px; padding: 10px 14px;
            border-radius: var(--r-sm); font-size: 13.5px; font-weight: 500; color: var(--rose);
            background: none; border: none; cursor: pointer; width: 100%;
            transition: var(--t); font-family: var(--font-body);
        }
        .sidebar-logout i { width: 18px; text-align: center; font-size: 14px; }
        .sidebar-logout:hover { background: rgba(244,63,94,0.07); }

        /* ── PAGE CONTENT ── */
        .page-content { flex: 1; min-width: 0; padding: 36px 36px 56px 28px; }

        /* ── TOAST ── */
        .toast {
            position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%) translateY(80px);
            background: var(--ink-2); color: white;
            padding: 13px 26px; border-radius: var(--r-md);
            font-family: var(--font-body); font-size: 13.5px; font-weight: 600;
            box-shadow: var(--shadow-lg); z-index: 9999;
            transition: transform 0.3s var(--ease), opacity 0.3s ease;
            opacity: 0; pointer-events: none; white-space: nowrap;
        }
        .toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
        .toast.success { background: #065f46; border: 1px solid rgba(16,185,129,0.3); }
        .toast.error   { background: #9f1239; border: 1px solid rgba(244,63,94,0.3); }

        /* ── PAGE HEADER ── */
        .page-header {
            background: linear-gradient(135deg, var(--ink) 0%, var(--ink-3) 55%, #3d1f6e 100%);
            border-radius: var(--r-xl); padding: 32px 40px;
            margin-bottom: 24px;
            display: flex; justify-content: space-between; align-items: flex-start; gap: 24px;
            position: relative; overflow: hidden; box-shadow: var(--shadow-md);
        }
        .page-header::before {
            content: ''; position: absolute; top: -60px; right: -40px;
            width: 280px; height: 280px;
            background: radial-gradient(circle, rgba(124,58,237,0.35) 0%, transparent 70%);
            pointer-events: none;
        }
        .page-header::after {
            content: ''; position: absolute; bottom: -60px; left: 30%;
            width: 180px; height: 180px;
            background: radial-gradient(circle, rgba(192,132,252,0.2) 0%, transparent 70%);
            pointer-events: none;
        }
        .header-content { position: relative; z-index: 1; flex: 1; min-width: 0; }
        .header-label {
            font-size: 11px; font-weight: 600; letter-spacing: 0.1em;
            text-transform: uppercase; color: var(--orchid); margin-bottom: 6px;
        }
        .page-title {
            font-family: var(--font-head);
            font-size: 24px; font-weight: 800; color: white;
            margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .page-description { font-size: 13.5px; color: rgba(255,255,255,0.5); }
        .header-badges { display: flex; gap: 8px; margin-top: 14px; flex-wrap: wrap; }
        .meta-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 12px; border-radius: 40px;
            font-size: 11px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase;
        }
        .meta-badge.active   { background: rgba(16,185,129,0.15); color: #6ee7b7; border: 1px solid rgba(16,185,129,0.3); }
        .meta-badge.draft    { background: rgba(245,158,11,0.15);  color: #fcd34d; border: 1px solid rgba(245,158,11,0.3); }
        .meta-badge.archived { background: rgba(56,189,248,0.15);  color: #7dd3fc; border: 1px solid rgba(56,189,248,0.3); }
        .meta-badge.info     { background: rgba(255,255,255,0.1);   color: rgba(255,255,255,0.65); border: 1px solid rgba(255,255,255,0.12); }
        .badge-dot { width: 5px; height: 5px; border-radius: 50%; background: currentColor; }

        /* ── STATUS TOGGLE ── */
        .status-toggle-wrap { position: relative; z-index: 1; display: flex; flex-direction: column; align-items: flex-end; gap: 8px; flex-shrink: 0; }
        .toggle-label { font-size: 11px; font-weight: 600; color: rgba(255,255,255,0.45); text-transform: uppercase; letter-spacing: 0.06em; white-space: nowrap; }
        .toggle-switch {
            position: relative; width: 52px; height: 28px;
            background: rgba(255,255,255,0.15); border-radius: 14px;
            cursor: pointer; transition: var(--t); border: 1px solid rgba(255,255,255,0.15);
        }
        .toggle-switch.active { background: var(--emerald); border-color: transparent; box-shadow: 0 0 12px rgba(16,185,129,0.4); }
        .toggle-slider {
            position: absolute; top: 3px; left: 3px;
            width: 20px; height: 20px;
            background: white; border-radius: 50%;
            transition: var(--t); box-shadow: 0 2px 6px rgba(0,0,0,0.25);
        }
        .toggle-switch.active .toggle-slider { left: 29px; }

        /* ── SECTION CARD ── */
        .section-card {
            background: var(--surface-3); border: 1px solid var(--border);
            border-radius: var(--r-lg); padding: 28px 30px;
            margin-bottom: 22px; box-shadow: var(--shadow-xs);
        }
        .section-title {
            display: flex; align-items: center; gap: 12px;
            font-family: var(--font-head); font-size: 17px; font-weight: 700;
            color: var(--text-1); margin-bottom: 22px;
        }
        .section-icon {
            width: 34px; height: 34px; border-radius: var(--r-sm);
            background: var(--violet-dim);
            display: flex; align-items: center; justify-content: center;
            color: var(--violet); font-size: 15px; flex-shrink: 0;
        }

        /* ── STATS GRID ── */
        .stats-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px;
        }
        .stat-card {
            padding: 20px; background: var(--surface);
            border-radius: var(--r-md); border: 1px solid var(--border);
            text-align: center; transition: var(--t); position: relative; overflow: hidden;
        }
        .stat-card::after {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
            background: linear-gradient(90deg, var(--violet), var(--orchid));
            border-radius: var(--r-md) var(--r-md) 0 0;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-vl); border-color: var(--border-2); }
        .stat-card-icon { font-size: 22px; margin-bottom: 10px; }
        .stat-card-value { font-family: var(--font-head); font-size: 28px; font-weight: 800; color: var(--text-1); line-height: 1; margin-bottom: 4px; }
        .stat-card-label { font-size: 11.5px; color: var(--text-3); font-weight: 500; }

        /* ── FORM ── */
        .form-group { margin-bottom: 18px; }
        .form-label {
            display: block; font-size: 12.5px; font-weight: 600;
            color: var(--text-2); margin-bottom: 7px; letter-spacing: 0.02em;
        }
        .form-label .required { color: var(--rose); margin-left: 3px; }
        .form-input {
            width: 100%; padding: 10px 14px;
            border: 1px solid var(--border-2); border-radius: var(--r-sm);
            font-size: 14px; font-family: var(--font-body);
            transition: var(--t); background: var(--surface); color: var(--text-1);
        }
        .form-input:focus {
            outline: none; border-color: var(--violet);
            background: var(--surface-3);
            box-shadow: 0 0 0 3px var(--violet-dim);
        }
        .form-textarea { min-height: 88px; resize: vertical; }
        .form-select {
            width: 100%; padding: 10px 14px;
            border: 1px solid var(--border-2); border-radius: var(--r-sm);
            font-size: 14px; font-family: var(--font-body); color: var(--text-1);
            background: var(--surface); cursor: pointer; transition: var(--t);
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='7' viewBox='0 0 12 7'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%238b7fa8' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 14px center;
            padding-right: 38px;
        }
        .form-select:focus { outline: none; border-color: var(--violet); box-shadow: 0 0 0 3px var(--violet-dim); }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
        .checkbox-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;
        }
        .checkbox-label {
            display: flex; align-items: center; gap: 9px;
            font-size: 13.5px; color: var(--text-2); cursor: pointer;
            padding: 10px 14px; background: var(--surface);
            border-radius: var(--r-sm); border: 1px solid var(--border);
            transition: var(--t); user-select: none; font-weight: 500;
        }
        .checkbox-label:hover { border-color: var(--violet); color: var(--violet); }
        .checkbox-label input[type=checkbox] { width: 15px; height: 15px; accent-color: var(--violet); }

        /* ── QUESTIONS ── */
        .questions-header {
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 22px;
        }
        .btn-add-question {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 9px 18px;
            background: linear-gradient(135deg, var(--violet), #9333ea);
            color: white; border: none; border-radius: var(--r-sm);
            font-family: var(--font-body); font-weight: 600; font-size: 13px;
            cursor: pointer; transition: var(--t);
        }
        .btn-add-question:hover { transform: translateY(-1px); box-shadow: 0 4px 16px rgba(124,58,237,0.4); }

        .questions-list { display: flex; flex-direction: column; gap: 16px; }
        .question-card {
            padding: 22px; background: var(--surface);
            border: 1px solid var(--border); border-radius: var(--r-md);
            transition: var(--t); position: relative; overflow: hidden;
        }
        .question-card::before {
            content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px;
            background: linear-gradient(180deg, var(--violet), var(--orchid));
            border-radius: 3px 0 0 3px;
        }
        .question-card:hover { border-color: var(--border-2); box-shadow: var(--shadow-sm); }
        .question-card.editing { background: var(--surface-3); border-color: var(--violet); }

        .question-header {
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;
        }
        .question-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .question-number {
            font-family: var(--font-head); font-size: 13px; font-weight: 700; color: var(--violet);
        }
        .question-type-badge {
            font-size: 10.5px; font-weight: 700; padding: 3px 9px;
            background: var(--violet-dim); color: var(--violet);
            border: 1px solid rgba(124,58,237,0.2);
            border-radius: 40px; text-transform: uppercase; letter-spacing: 0.04em;
        }
        .question-marks-badge {
            font-size: 10.5px; font-weight: 700; padding: 3px 9px;
            background: rgba(16,185,129,0.1); color: #059669;
            border: 1px solid rgba(16,185,129,0.2); border-radius: 40px;
        }
        .question-actions { display: flex; gap: 7px; }
        .btn-sm {
            padding: 6px 13px; border: none; border-radius: var(--r-sm);
            font-family: var(--font-body); font-size: 12px; font-weight: 600;
            cursor: pointer; transition: var(--t); display: inline-flex; align-items: center; gap: 5px;
        }
        .btn-edit-q   { background: var(--surface-2); color: var(--text-2); border: 1px solid var(--border); }
        .btn-edit-q:hover { background: var(--violet-dim); color: var(--violet); border-color: var(--violet); }
        .btn-delete-q { background: rgba(244,63,94,0.08); color: var(--rose); border: 1px solid rgba(244,63,94,0.2); }
        .btn-delete-q:hover { background: rgba(244,63,94,0.15); }
        .btn-save-q   { background: linear-gradient(135deg, var(--emerald), #059669); color: white; }
        .btn-save-q:hover { transform: translateY(-1px); box-shadow: 0 3px 10px rgba(16,185,129,0.35); }
        .btn-cancel-q { background: var(--surface-2); color: var(--text-3); border: 1px solid var(--border); }
        .btn-cancel-q:hover { border-color: var(--rose); color: var(--rose); }

        .question-display { display: block; }
        .question-display.hidden { display: none; }
        .question-text { font-size: 14.5px; color: var(--text-1); margin-bottom: 14px; line-height: 1.65; }

        .options-list { display: flex; flex-direction: column; gap: 7px; }
        .option-item {
            padding: 9px 14px; background: var(--surface-3); border-radius: var(--r-sm);
            font-size: 13px; color: var(--text-2);
            display: flex; align-items: center; gap: 8px;
            border: 1px solid var(--border);
        }
        .option-item.correct { border-color: rgba(16,185,129,0.35); background: rgba(16,185,129,0.05); color: var(--text-1); }
        .option-label { font-family: var(--font-head); font-weight: 700; min-width: 22px; color: var(--text-3); font-size: 12px; }
        .option-item.correct .option-label { color: var(--emerald); }
        .correct-badge {
            margin-left: auto; padding: 2px 9px;
            background: rgba(16,185,129,0.12); color: #059669;
            border: 1px solid rgba(16,185,129,0.25);
            border-radius: 40px; font-size: 10.5px; font-weight: 700;
        }
        .short-answer-display {
            padding: 10px 14px; background: var(--surface-3); border-radius: var(--r-sm);
            font-size: 13px; color: var(--text-1);
            border: 1px solid rgba(16,185,129,0.3);
        }
        .short-answer-label { font-size: 11px; font-weight: 600; color: var(--text-3); margin-bottom: 5px; text-transform: uppercase; letter-spacing: 0.05em; }
        .explanation-block {
            margin-top: 12px; padding: 10px 14px;
            background: rgba(245,158,11,0.06);
            border: 1px solid rgba(245,158,11,0.2);
            border-radius: var(--r-sm); font-size: 12.5px; color: #92400e;
        }
        .explanation-block strong { color: #78350f; }

        /* ── EDIT FORM ── */
        .question-edit-form { display: none; }
        .question-edit-form.active { display: block; }
        .edit-form-group { margin-bottom: 13px; }
        .edit-form-label {
            display: block; font-size: 12px; font-weight: 600;
            color: var(--text-2); margin-bottom: 5px; letter-spacing: 0.02em;
        }
        .edit-form-input {
            width: 100%; padding: 9px 12px;
            border: 1px solid var(--border-2); border-radius: var(--r-sm);
            font-size: 13px; font-family: var(--font-body);
            transition: var(--t); background: var(--surface-3); color: var(--text-1);
        }
        .edit-form-input:focus { outline: none; border-color: var(--violet); box-shadow: 0 0 0 3px var(--violet-dim); }
        .edit-form-input[readonly] { background: var(--surface-2); color: var(--text-3); cursor: not-allowed; }
        .options-edit-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 12px; }
        .edit-form-row { display: flex; gap: 12px; }
        .edit-form-row .edit-form-group { flex: 1; }
        .correct-answer-select {
            width: 100%; padding: 9px 12px;
            border: 1px solid var(--border-2); border-radius: var(--r-sm);
            font-size: 13px; font-family: var(--font-body); color: var(--text-1);
            background: var(--surface-3); cursor: pointer; transition: var(--t);
        }
        .correct-answer-select:focus { outline: none; border-color: var(--emerald); box-shadow: 0 0 0 3px rgba(16,185,129,0.12); }
        .type-indicator {
            display: inline-block; padding: 4px 12px;
            background: var(--violet-dim); color: var(--violet);
            border: 1px solid rgba(124,58,237,0.2);
            border-radius: 40px; font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 14px;
        }
        .no-questions {
            text-align: center; padding: 52px 20px;
            color: var(--text-3);
        }
        .no-questions-icon { font-size: 48px; margin-bottom: 14px; opacity: 0.35; }
        .no-questions p { font-size: 14.5px; }

        /* ── ACTION BAR ── */
        .action-bar {
            background: var(--surface-3); border: 1px solid var(--border);
            border-radius: var(--r-lg); padding: 22px 28px;
            margin-bottom: 30px; box-shadow: var(--shadow-xs);
            display: flex; justify-content: space-between; align-items: center;
            gap: 20px; flex-wrap: wrap;
        }
        .action-tip {
            font-size: 13px; color: var(--text-3);
            display: flex; align-items: center; gap: 8px;
        }
        .action-tip kbd {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 2px 7px; background: var(--surface-2);
            border: 1px solid var(--border-2); border-radius: 5px;
            font-size: 11px; font-family: var(--font-body); color: var(--text-2);
        }
        .action-buttons { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 10px 20px; border: 1px solid transparent;
            border-radius: var(--r-sm); font-family: var(--font-body);
            font-size: 13.5px; font-weight: 600; cursor: pointer; transition: var(--t);
            text-decoration: none;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--violet), #9333ea);
            color: white; border-color: transparent;
        }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(124,58,237,0.4); }
        .btn-primary:disabled { opacity: 0.55; cursor: not-allowed; transform: none; box-shadow: none; }
        .btn-secondary {
            background: var(--surface); color: var(--text-2); border-color: var(--border-2);
        }
        .btn-secondary:hover { background: var(--surface-2); color: var(--text-1); }
        .btn-danger { background: rgba(244,63,94,0.08); color: var(--rose); border-color: rgba(244,63,94,0.22); }
        .btn-danger:hover { background: rgba(244,63,94,0.15); }

        /* ── ADD QUESTION MODAL ── */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(13,10,20,0.65); backdrop-filter: blur(4px);
            z-index: 2000; align-items: center; justify-content: center;
        }
        .modal-overlay.active { display: flex; }
        .modal-content {
            background: var(--surface-3); border-radius: var(--r-xl); padding: 32px;
            max-width: 700px; width: 94%; max-height: 90vh; overflow-y: auto;
            box-shadow: var(--shadow-lg), 0 0 0 1px var(--border);
            animation: modal-in 0.22s var(--ease) forwards;
        }
        @keyframes modal-in {
            from { opacity: 0; transform: scale(0.96) translateY(10px); }
            to   { opacity: 1; transform: scale(1) translateY(0); }
        }
        .modal-header {
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 22px;
        }
        .modal-title { font-family: var(--font-head); font-size: 20px; font-weight: 700; color: var(--text-1); }
        .btn-close-modal {
            width: 32px; height: 32px; background: var(--surface-2); border: 1px solid var(--border);
            border-radius: 50%; font-size: 16px; cursor: pointer; transition: var(--t);
            display: flex; align-items: center; justify-content: center; color: var(--text-3);
        }
        .btn-close-modal:hover { background: var(--surface-2); color: var(--rose); border-color: rgba(244,63,94,0.3); transform: rotate(90deg); }

        .type-tabs { display: flex; gap: 6px; margin-bottom: 22px; flex-wrap: wrap; }
        .type-tab {
            padding: 6px 14px; background: var(--surface);
            border: 1px solid var(--border-2); border-radius: 40px;
            font-family: var(--font-body); font-size: 12.5px; font-weight: 600;
            cursor: pointer; transition: var(--t); color: var(--text-3);
        }
        .type-tab:hover { border-color: var(--violet); color: var(--violet); }
        .type-tab.active { background: var(--violet); border-color: var(--violet); color: white; }
        .mcq-fields, .tf-fields, .short-fields { display: none; }
        .mcq-fields.show, .tf-fields.show, .short-fields.show { display: block; }

        /* ── CONFIRM MODAL ── */
        .confirm-modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(13,10,20,0.65); backdrop-filter: blur(4px);
            z-index: 3000; align-items: center; justify-content: center;
        }
        .confirm-modal-overlay.open { display: flex; }
        .confirm-modal {
            background: var(--surface-3); border-radius: var(--r-xl); padding: 30px;
            width: 90%; max-width: 420px;
            box-shadow: var(--shadow-lg), 0 0 0 1px var(--border);
            animation: modal-in 0.2s var(--ease) forwards;
        }
        .confirm-modal-icon {
            width: 44px; height: 44px; border-radius: 50%;
            background: rgba(244,63,94,0.1); border: 1px solid rgba(244,63,94,0.25);
            display: flex; align-items: center; justify-content: center;
            color: var(--rose); font-size: 18px; margin-bottom: 16px;
        }
        .confirm-modal-title { font-family: var(--font-head); font-size: 18px; font-weight: 700; color: var(--text-1); margin-bottom: 8px; }
        .confirm-modal-body  { font-size: 13.5px; color: var(--text-2); margin-bottom: 22px; line-height: 1.65; }
        .confirm-actions { display: flex; gap: 10px; justify-content: flex-end; }
        .btn-cancel-confirm {
            padding: 9px 20px; background: var(--surface); color: var(--text-2);
            border: 1px solid var(--border-2); border-radius: var(--r-sm);
            font-family: var(--font-body); font-weight: 600; font-size: 13px; cursor: pointer; transition: var(--t);
        }
        .btn-cancel-confirm:hover { border-color: var(--violet); color: var(--violet); }
        .btn-confirm-action {
            padding: 9px 20px; background: var(--rose); color: white;
            border: none; border-radius: var(--r-sm);
            font-family: var(--font-body); font-weight: 700; font-size: 13px; cursor: pointer; transition: var(--t);
        }
        .btn-confirm-action:hover { background: #e11d48; box-shadow: 0 4px 14px rgba(244,63,94,0.35); }
        .btn-confirm-action:disabled { opacity: 0.55; cursor: not-allowed; }
        .btn-confirm-action.green { background: var(--emerald); }
        .btn-confirm-action.green:hover { background: #059669; box-shadow: 0 4px 14px rgba(16,185,129,0.35); }

        /* ── LOADING ── */
        .loading-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(247,245,251,0.88); backdrop-filter: blur(6px);
            z-index: 4000; align-items: center; justify-content: center;
        }
        .loading-overlay.active { display: flex; }
        .loading-content { text-align: center; }
        .spinner {
            width: 44px; height: 44px;
            border: 4px solid var(--border-2);
            border-top-color: var(--violet);
            border-radius: 50%; animation: spin 0.7s linear infinite;
            margin: 0 auto 14px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .loading-text { font-family: var(--font-head); font-size: 15px; font-weight: 700; color: var(--text-1); }

        /* ── RESPONSIVE ── */
        @media (max-width: 960px) {
            .left-sidebar { display: none; }
            .page-content { padding: 24px 20px; }
            .page-header { flex-direction: column; }
            .status-toggle-wrap { align-items: flex-start; }
        }
        @media (max-width: 640px) {
            .form-grid, .options-edit-grid { grid-template-columns: 1fr; }
            .edit-form-row { flex-direction: column; }
            .action-bar { flex-direction: column; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .modal-content { padding: 22px; }
        }
        @media (max-width: 400px) {
            .stats-grid { grid-template-columns: 1fr; }
        }

        /* ── TARGETING ── */
        .target-tabs { display: flex; gap: 6px; margin-bottom: 14px; flex-wrap: wrap; }
        .target-tab {
            padding: 6px 14px; background: var(--surface);
            border: 1px solid var(--border-2); border-radius: 40px;
            font-family: var(--font-body); font-size: 12.5px; font-weight: 600;
            cursor: pointer; transition: var(--t); color: var(--text-3);
        }
        .target-tab:hover { border-color: var(--violet); color: var(--violet); }
        .target-tab.active { background: var(--violet); border-color: var(--violet); color: white; }
        .group-picker { display: flex; flex-direction: column; gap: 8px; }
        .group-pick-item {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 14px; background: var(--surface);
            border: 1px solid var(--border); border-radius: var(--r-sm);
            cursor: pointer; transition: var(--t);
        }
        .group-pick-item:has(input:checked) { border-color: var(--violet); background: var(--violet-dim); }
        .group-pick-name { flex: 1; font-size: 13.5px; font-weight: 500; color: var(--text-1); }
        .group-pick-count { font-size: 11.5px; color: var(--text-3); }
        .student-chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 10px; background: var(--violet-dim);
            border: 1px solid rgba(124,58,237,0.25); border-radius: 40px;
            font-size: 12.5px; font-weight: 500; color: var(--violet);
        }
        .student-chip button { background: none; border: none; cursor: pointer; color: inherit; font-size: 14px; line-height: 1; padding: 0; }
        .student-result-item {
            padding: 10px 14px; cursor: pointer; transition: var(--t);
            border-bottom: 1px solid var(--border);
        }
        .student-result-item:hover { background: var(--violet-dim); }
        .student-result-item:last-child { border-bottom: none; }
        .student-result-name { font-size: 13.5px; font-weight: 500; color: var(--text-1); }
        .student-result-meta { font-size: 11.5px; color: var(--text-3); margin-top: 2px; }
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
    <?php if ($hasOpenReport || $latestReportStatus === 'resolved'): ?>
    <div class="report-dot-wrap" onclick="openReportModal()" title="<?= $hasOpenReport ? 'Your report is being reviewed' : 'Report resolved' ?>">
        <span class="report-status-dot <?= $latestReportStatus === 'resolved' ? 'resolved' : '' ?>"></span>
        <span><?= $hasOpenReport ? 'Report Pending' : 'Resolved' ?></span>
    </div>
    <?php endif; ?>

        <div class="profile-wrap">
            <button class="profile-button" id="profileBtn">
                <div class="profile-avatar">
                    <?php if (!empty($userPicture)): ?>
                        <img src="<?= htmlspecialchars($userPicture) ?>" alt="Profile">
                    <?php else: ?>
                        <?= $userInitials ?>
                    <?php endif; ?>
                </div>
                <span class="profile-name"><?= $userName ?></span>
                <i class="fa fa-chevron-down profile-caret"></i>
            </button>
            <div class="profile-dropdown" id="profileDropdown">
                <div class="dropdown-header">
                    <div class="dd-avatar">
                        <?php if (!empty($userPicture)): ?>
                            <img src="<?= htmlspecialchars($userPicture) ?>" alt="Profile">
                        <?php else: ?>
                            <?= $userInitials ?>
                        <?php endif; ?>
                    </div>
                    <div class="dropdown-name"><?= $userName ?></div>
                    <div class="dropdown-email"><?= $userEmail ?></div>
                    <span class="dropdown-role">Teacher</span>
                </div>
                <div class="dropdown-menu">
                    <a href="teacher-profile.php" class="dropdown-item"><i class="fa fa-user"></i> My Profile</a>
                    <a href="#" onclick="event.preventDefault(); document.getElementById('profileDropdown').classList.remove('open'); openReportModal();" class="dropdown-item"><i class="fa fa-circle-question"></i> Help &amp; Support</a>
                    <div class="dropdown-divider"></div>
                    <button onclick="handleLogout()" class="dropdown-item danger"><i class="fa fa-right-from-bracket"></i> Logout</button>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- ── MAIN ── -->
<div class="page-wrapper">

    <aside class="left-sidebar">
        <span class="sidebar-section-label">Navigation</span>
        <a href="teacher-dashboard.php"   class="sidebar-link"><i class="fa fa-house"></i> Dashboard</a>
        <a href="teacher-assessments.php" class="sidebar-link active"><i class="fa fa-clipboard-list"></i> Assessments</a>
        <a href="manage-groups.php"       class="sidebar-link"><i class="fa fa-users"></i> Manage Groups</a>
        <a href="teacher-resources.php"   class="sidebar-link"><i class="fa fa-folder-open"></i> Resources</a>
        <div class="sidebar-bottom">
            <button onclick="handleLogout()" class="sidebar-logout"><i class="fa fa-right-from-bracket"></i> Logout</button>
        </div>
    </aside>

    <div class="page-content">

        <!-- Page Header -->
        <div class="page-header">
            <div class="header-content">
                <div class="header-label">Edit Assessment</div>
                <h1 class="page-title" id="headerTitle"><?= htmlspecialchars($assessment['title']) ?></h1>
                <p class="page-description">ID #<?= $assessmentId ?> · Last updated <?= fmtDate($assessment['updated_at']) ?></p>
                <div class="header-badges">
                    <span class="meta-badge <?= htmlspecialchars($assessment['status']) ?>" id="statusBadge">
                        <span class="badge-dot"></span>
                        <?= ucfirst($assessment['status']) ?>
                    </span>
                    <span class="meta-badge info"><i class="fa fa-calendar" style="font-size:10px;"></i> Created <?= fmtDate($assessment['created_at']) ?></span>
                    <span class="meta-badge info"><i class="fa fa-users" style="font-size:10px;"></i> <?= $stats['total_attempts'] ?> Attempt<?= $stats['total_attempts'] !== 1 ? 's' : '' ?></span>
                </div>
            </div>
            <div class="status-toggle-wrap">
                <span class="toggle-label">Published</span>
                <div class="toggle-switch <?= in_array($assessment['status'], ['active','published']) ? 'active' : '' ?>"
                     id="statusToggle" onclick="toggleStatus()" title="Toggle active/draft">
                    <div class="toggle-slider"></div>
                </div>
            </div>
        </div>

        <!-- Statistics -->
        <div class="section-card">
            <h2 class="section-title">
                <div class="section-icon"><i class="fa fa-chart-bar"></i></div>
                Performance Statistics
            </h2>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-card-icon">👥</div>
                    <div class="stat-card-value"><?= $stats['total_attempts'] ?></div>
                    <div class="stat-card-label">Total Attempts</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-icon">📈</div>
                    <div class="stat-card-value"><?= $stats['avg_score'] ?>%</div>
                    <div class="stat-card-label">Average Score</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-icon">⏱️</div>
                    <div class="stat-card-value"><?= $stats['avg_time'] ?>m</div>
                    <div class="stat-card-label">Avg. Completion Time</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-icon">🎯</div>
                    <div class="stat-card-value"><?= $stats['completion_rate'] ?>%</div>
                    <div class="stat-card-label">Completion Rate</div>
                </div>
            </div>
        </div>

        <!-- Basic Info Form -->
        <div class="section-card">
            <h2 class="section-title">
                <div class="section-icon"><i class="fa fa-pen-to-square"></i></div>
                Basic Information
            </h2>

            <div class="form-group">
                <label class="form-label">Assessment Title <span class="required">*</span></label>
                <input type="text" class="form-input" id="assessmentTitle"
                       value="<?= htmlspecialchars($assessment['title']) ?>" maxlength="200" required>
            </div>

            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea class="form-input form-textarea" id="assessmentDescription"><?= htmlspecialchars($assessment['description'] ?? '') ?></textarea>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">Category <span class="required">*</span></label>
                    <select class="form-select" id="assessmentCategory" required>
                        <option value="">Select Category</option>
                        <?php foreach (['aptitude'=>'Aptitude','technical'=>'Technical','coding'=>'Coding','reasoning'=>'Reasoning','english'=>'English','general'=>'General'] as $val => $label): ?>
                            <option value="<?= $val ?>" <?= $assessment['category'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Difficulty <span class="required">*</span></label>
                    <select class="form-select" id="difficultyLevel" required>
                        <option value="">Select Difficulty</option>
                        <?php foreach (['easy'=>'Easy','medium'=>'Medium','hard'=>'Hard'] as $val => $label): ?>
                            <option value="<?= $val ?>" <?= $assessment['difficulty'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Duration (minutes) <span class="required">*</span></label>
                    <input type="number" class="form-input" id="duration"
                           min="1" max="480" value="<?= (int)$assessment['duration_minutes'] ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Total Marks <span class="required">*</span></label>
                    <input type="number" class="form-input" id="totalMarks"
                           min="1" value="<?= (int)$assessment['total_marks'] ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Passing Marks <span class="required">*</span></label>
                    <input type="number" class="form-input" id="passingMarks"
                           min="0" value="<?= (int)$assessment['passing_marks'] ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Max Attempts</label>
                    <input type="number" class="form-input" id="maxAttempts"
                           min="1" value="<?= (int)($assessment['max_attempts'] ?? 1) ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Start Time</label>
                    <input type="datetime-local" class="form-input" id="startTime"
                           value="<?= toDatetimeLocal($assessment['start_time']) ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">End Time</label>
                    <input type="datetime-local" class="form-input" id="endTime"
                           value="<?= toDatetimeLocal($assessment['end_time']) ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Options</label>
                <div class="checkbox-grid">
                    <label class="checkbox-label">
                        <input type="checkbox" id="randomizeQuestions"
                               <?= $assessment['randomize_questions'] ? 'checked' : '' ?>>
                        Randomize questions
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" id="randomizeOptions"
                               <?= $assessment['randomize_options'] ? 'checked' : '' ?>>
                        Randomize options
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" id="isPublic"
                               <?= $assessment['visibility'] === 'public' ? 'checked' : '' ?>
                               onchange="toggleGroupPicker()">
                        Public (allow guest access)
                    </label>
                </div>
            </div>

            <!-- Group / Student Targeting -->
            <div class="form-group" id="targetingBlock" style="<?= $assessment['visibility'] === 'public' ? 'display:none' : '' ?>">
                <label class="form-label">Restrict Access To</label>
                <p style="font-size:13px;color:var(--text-3);margin:0 0 10px;">
                    Choose groups or individual students. Leave both empty for private (no access).
                </p>

                <div class="target-tabs">
                    <button type="button" class="target-tab active" onclick="switchTargetTab('groups',this)">👥 Groups</button>
                    <button type="button" class="target-tab" onclick="switchTargetTab('students',this)">🎓 Students</button>
                </div>

                <!-- Groups tab -->
                <div id="targetTabGroups">
                    <?php if (empty($teacherGroups)): ?>
                        <p style="font-size:13px;color:var(--gold);padding:10px 0;">⚠ You have no groups yet. <a href="manage-groups.php" style="color:var(--violet);">Create a group</a> first.</p>
                    <?php else: ?>
                    <div class="group-picker" id="groupPicker">
                        <?php foreach ($teacherGroups as $g): ?>
                        <label class="group-pick-item">
                            <input type="checkbox" class="group-pick-cb"
                                   value="<?= $g['group_id'] ?>"
                                   <?php
                                   foreach ($existingTargets as $t) {
                                       if ($t['type'] === 'group' && $t['id'] === (int)$g['group_id']) {
                                           echo 'checked'; break;
                                       }
                                   }
                                   ?>>
                            <span class="group-pick-name"><?= htmlspecialchars($g['name']) ?></span>
                            <span class="group-pick-count"><?= (int)$g['member_count'] ?> student<?= $g['member_count'] != 1 ? 's' : '' ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Students tab -->
                <div id="targetTabStudents" style="display:none;">
                    <div style="position:relative;margin-bottom:10px;">
                        <input type="text" id="studentSearchInput" class="form-input"
                               placeholder="Search by name, email or reg. number…"
                               oninput="debounceStudentSearch(this.value)">
                        <div id="studentSearchResults" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--surface-3);border:1.5px solid var(--border);border-radius:8px;box-shadow:var(--shadow-md);z-index:50;max-height:220px;overflow-y:auto;"></div>
                    </div>
                    <div id="selectedStudentsChips" style="display:flex;flex-wrap:wrap;gap:6px;min-height:32px;">
                        <?php foreach ($existingTargets as $t): ?>
                        <?php if ($t['type'] === 'student'): ?>
                        <?php
                            $rs2 = safePreparedQuery($conn,
                                "SELECT user_id, full_name FROM users WHERE user_id = ?",
                                "i", [$t['id']]);
                            $su = null;
                            if ($rs2['success'] && $rs2['result']) {
                                $su = $rs2['result']->fetch_assoc();
                                $rs2['result']->free();
                            }
                        ?>
                        <?php if ($su): ?>
                        <span class="student-chip" data-id="<?= $su['user_id'] ?>">
                            <?= htmlspecialchars($su['full_name']) ?>
                            <button type="button" onclick="removeStudentChip(<?= $su['user_id'] ?>)">&times;</button>
                        </span>
                        <input type="hidden" class="student-pick-hidden" value="<?= $su['user_id'] ?>">
                        <?php endif; ?>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Questions -->
        <div class="section-card">
            <div class="questions-header">
                <h2 class="section-title" style="margin-bottom:0;">
                    <div class="section-icon"><i class="fa fa-circle-question"></i></div>
                    <span id="questionCountTitle">Questions (<?= count($questions) ?>)</span>
                </h2>
                <button class="btn-add-question" onclick="openAddModal()">
                    <i class="fa fa-plus"></i> Add Question
                </button>
            </div>

            <div class="questions-list" id="questionsList">
                <?php if (empty($questions)): ?>
                    <div class="no-questions" id="noQuestionsMsg">
                        <div class="no-questions-icon">❓</div>
                        <p>No questions yet. Add your first question above.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($questions as $i => $q):
                        $qid   = (int)$q['question_id'];
                        $qNum  = $i + 1;
                        $qType = $q['question_type'];
                        $opts  = $q['options'];
                        $letters = ['A','B','C','D','E'];
                        $isMCQ = in_array($qType, ['mcq','true_false','multiple_select']);
                    ?>
                    <div class="question-card" data-qid="<?= $qid ?>">
                        <div class="question-header">
                            <div class="question-meta">
                                <span class="question-number">Q<?= $qNum ?></span>
                                <span class="question-type-badge"><?= htmlspecialchars(str_replace('_',' ', $qType)) ?></span>
                                <span class="question-marks-badge"><?= (int)$q['marks'] ?> mark<?= (int)$q['marks'] !== 1 ? 's' : '' ?></span>
                            </div>
                            <div class="question-actions">
                                <button class="btn-sm btn-edit-q" onclick="editQ(<?= $qid ?>)"><i class="fa fa-pen"></i> Edit</button>
                                <button class="btn-sm btn-delete-q" onclick="deleteQ(<?= $qid ?>, <?= $qNum ?>)"><i class="fa fa-trash"></i> Delete</button>
                            </div>
                        </div>

                        <!-- Display Mode -->
                        <div class="question-display" id="disp<?= $qid ?>">
                            <div class="question-text"><?= htmlspecialchars($q['question_text']) ?></div>

                            <?php if ($isMCQ): ?>
                                <div class="options-list">
                                    <?php foreach ($opts as $oi => $opt): ?>
                                    <div class="option-item <?= $opt['is_correct'] ? 'correct' : '' ?>">
                                        <span class="option-label"><?= $letters[$oi] ?? ($oi+1) ?></span>
                                        <span><?= htmlspecialchars($opt['option_text']) ?></span>
                                        <?php if ($opt['is_correct']): ?><span class="correct-badge">✓ Correct</span><?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <?php
                                $correctOpt  = array_filter($opts, fn($o) => $o['is_correct']);
                                $correctText = $correctOpt ? reset($correctOpt)['option_text'] : '—';
                                ?>
                                <div class="short-answer-label">Expected Answer</div>
                                <div class="short-answer-display"><?= htmlspecialchars($correctText) ?></div>
                            <?php endif; ?>

                            <?php if ($q['explanation']): ?>
                                <div class="explanation-block">
                                    <strong>Explanation:</strong> <?= htmlspecialchars($q['explanation']) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Edit Mode -->
                        <div class="question-edit-form" id="edit<?= $qid ?>">
                            <div class="type-indicator"><?= htmlspecialchars(str_replace('_', ' ', $qType)) ?></div>

                            <div class="edit-form-group">
                                <label class="edit-form-label">Question Text <span style="color:var(--rose)">*</span></label>
                                <textarea class="edit-form-input" id="qt<?= $qid ?>" rows="3"><?= htmlspecialchars($q['question_text']) ?></textarea>
                            </div>

                            <?php if ($isMCQ): ?>
                                <script>
                                window.existingOpts = window.existingOpts || {};
                                window.existingOpts[<?= $qid ?>] = <?= json_encode(array_map(fn($o) => [
                                    'option_id'   => (int)$o['option_id'],
                                    'option_text' => $o['option_text'],
                                    'is_correct'  => (bool)$o['is_correct'],
                                ], $opts)) ?>;
                                </script>
                                <div class="options-edit-grid" id="optsGrid<?= $qid ?>">
                                    <?php foreach ($opts as $oi => $opt): ?>
                                    <div class="edit-form-group">
                                        <label class="edit-form-label">
                                            Option <?= $letters[$oi] ?? ($oi+1) ?>
                                            <?php if ($qType !== 'true_false'): ?>
                                                <input type="checkbox"
                                                       id="isCorrect<?= $qid ?>_<?= $oi ?>"
                                                       <?= $opt['is_correct'] ? 'checked' : '' ?>
                                                       style="margin-left:6px;accent-color:var(--emerald);">
                                                Correct
                                            <?php endif; ?>
                                        </label>
                                        <input type="text" class="edit-form-input"
                                               id="opt<?= $qid ?>_<?= $oi ?>"
                                               value="<?= htmlspecialchars($opt['option_text']) ?>"
                                               data-optid="<?= (int)$opt['option_id'] ?>"
                                               <?= $qType === 'true_false' ? 'readonly' : '' ?>>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if ($qType === 'true_false'): ?>
                                <div class="edit-form-group">
                                    <label class="edit-form-label">Correct Answer</label>
                                    <select class="correct-answer-select" id="tfCorrect<?= $qid ?>">
                                        <?php foreach ($opts as $oi => $opt): ?>
                                        <option value="<?= (int)$opt['option_id'] ?>" <?= $opt['is_correct'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($opt['option_text']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="edit-form-group">
                                    <label class="edit-form-label">Correct Answer / Expected Answer</label>
                                    <?php
                                    $correctOpt  = array_filter($opts, fn($o) => $o['is_correct']);
                                    $existingText  = $correctOpt ? reset($correctOpt)['option_text'] : '';
                                    $existingOptId = $correctOpt ? (int)reset($correctOpt)['option_id'] : 0;
                                    ?>
                                    <input type="hidden" id="shortOptId<?= $qid ?>" value="<?= $existingOptId ?>">
                                    <textarea class="edit-form-input" id="ca<?= $qid ?>" rows="2"><?= htmlspecialchars($existingText) ?></textarea>
                                </div>
                            <?php endif; ?>

                            <div class="edit-form-row">
                                <div class="edit-form-group">
                                    <label class="edit-form-label">Marks</label>
                                    <input type="number" class="edit-form-input" id="marks<?= $qid ?>"
                                           min="1" value="<?= (int)$q['marks'] ?>">
                                </div>
                                <div class="edit-form-group">
                                    <label class="edit-form-label">Negative Marks</label>
                                    <input type="number" class="edit-form-input" id="negmarks<?= $qid ?>"
                                           min="0" step="0.25" value="<?= number_format((float)($q['negative_marks'] ?? 0), 2) ?>">
                                </div>
                            </div>

                            <div class="edit-form-group">
                                <label class="edit-form-label">Explanation (optional)</label>
                                <textarea class="edit-form-input" id="expl<?= $qid ?>" rows="2"><?= htmlspecialchars($q['explanation'] ?? '') ?></textarea>
                            </div>

                            <div class="question-actions" style="margin-top:14px;">
                                <button class="btn-sm btn-save-q"   onclick="saveQ(<?= $qid ?>, '<?= $qType ?>')"><i class="fa fa-floppy-disk"></i> Save</button>
                                <button class="btn-sm btn-cancel-q" onclick="cancelQ(<?= $qid ?>)">Cancel</button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Action Bar -->
        <div class="action-bar">
            <p class="action-tip"><kbd>Ctrl</kbd> + <kbd>S</kbd> to save all changes quickly.</p>
            <div class="action-buttons">
                <a href="assessment-results.php?id=<?= $assessmentId ?>" class="btn btn-secondary">
                    <i class="fa fa-chart-bar"></i> View Results
                </a>
                <button class="btn btn-primary" id="saveAllBtn" onclick="saveAll()">
                    <i class="fa fa-floppy-disk"></i> Save All Changes
                </button>
                <button class="btn btn-danger" onclick="confirmDeleteAssessment()">
                    <i class="fa fa-trash"></i> Delete Assessment
                </button>
            </div>
        </div>

    </div><!-- /page-content -->
</div><!-- /page-wrapper -->

<!-- ── ADD QUESTION MODAL ── -->
<div class="modal-overlay" id="addModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Add New Question</h2>
            <button class="btn-close-modal" onclick="closeAddModal()"><i class="fa fa-xmark"></i></button>
        </div>

        <div class="type-tabs">
            <button class="type-tab active" data-type="mcq"             onclick="switchType('mcq', this)">MCQ</button>
            <button class="type-tab"        data-type="true_false"      onclick="switchType('true_false', this)">True / False</button>
            <button class="type-tab"        data-type="short_answer"    onclick="switchType('short_answer', this)">Short Answer</button>
            <button class="type-tab"        data-type="fill_blank"      onclick="switchType('fill_blank', this)">Fill in the Blank</button>
            <button class="type-tab"        data-type="multiple_select" onclick="switchType('multiple_select', this)">Multiple Select</button>
        </div>
        <input type="hidden" id="newQType" value="mcq">

        <div class="edit-form-group">
            <label class="edit-form-label">Question Text <span style="color:var(--rose)">*</span></label>
            <textarea class="edit-form-input" id="newQt" rows="3" placeholder="Enter question text…"></textarea>
        </div>

        <!-- MCQ / Multiple Select options -->
        <div class="mcq-fields show" id="mcqFields">
            <div class="options-edit-grid">
                <div class="edit-form-group">
                    <label class="edit-form-label">Option A <span style="color:var(--rose)">*</span></label>
                    <input type="text" class="edit-form-input" id="newOa" placeholder="Option A">
                </div>
                <div class="edit-form-group">
                    <label class="edit-form-label">Option B <span style="color:var(--rose)">*</span></label>
                    <input type="text" class="edit-form-input" id="newOb" placeholder="Option B">
                </div>
                <div class="edit-form-group">
                    <label class="edit-form-label">Option C</label>
                    <input type="text" class="edit-form-input" id="newOc" placeholder="Option C">
                </div>
                <div class="edit-form-group">
                    <label class="edit-form-label">Option D</label>
                    <input type="text" class="edit-form-input" id="newOd" placeholder="Option D">
                </div>
            </div>
            <!-- MCQ: single correct -->
            <div class="edit-form-group" id="mcqAnswerGroup">
                <label class="edit-form-label">Correct Answer <span style="color:var(--rose)">*</span></label>
                <select class="correct-answer-select" id="newCa">
                    <option value="">Select</option>
                    <option value="A">A</option>
                    <option value="B">B</option>
                    <option value="C">C</option>
                    <option value="D">D</option>
                </select>
            </div>
            <!-- Multiple Select: checkboxes -->
            <div class="edit-form-group" id="msAnswerGroup" style="display:none;">
                <label class="edit-form-label">Correct Options <span style="color:var(--rose)">*</span></label>
                <div style="display:flex;gap:16px;flex-wrap:wrap;">
                    <label style="display:flex;align-items:center;gap:5px;font-size:13px;color:var(--text-2);">
                        <input type="checkbox" id="msA" style="accent-color:var(--emerald);"> A
                    </label>
                    <label style="display:flex;align-items:center;gap:5px;font-size:13px;color:var(--text-2);">
                        <input type="checkbox" id="msB" style="accent-color:var(--emerald);"> B
                    </label>
                    <label style="display:flex;align-items:center;gap:5px;font-size:13px;color:var(--text-2);">
                        <input type="checkbox" id="msC" style="accent-color:var(--emerald);"> C
                    </label>
                    <label style="display:flex;align-items:center;gap:5px;font-size:13px;color:var(--text-2);">
                        <input type="checkbox" id="msD" style="accent-color:var(--emerald);"> D
                    </label>
                </div>
            </div>
        </div>

        <!-- True/False -->
        <div class="tf-fields" id="tfFields">
            <div class="edit-form-group">
                <label class="edit-form-label">Correct Answer <span style="color:var(--rose)">*</span></label>
                <select class="correct-answer-select" id="newCaTf">
                    <option value="true">True</option>
                    <option value="false">False</option>
                </select>
            </div>
        </div>

        <!-- Short answer / fill blank -->
        <div class="short-fields" id="shortFields">
            <div class="edit-form-group">
                <label class="edit-form-label">Expected Answer <span style="color:var(--rose)">*</span></label>
                <textarea class="edit-form-input" id="newCaShort" rows="2" placeholder="Expected answer or keywords…"></textarea>
            </div>
        </div>

        <div class="edit-form-row">
            <div class="edit-form-group">
                <label class="edit-form-label">Marks <span style="color:var(--rose)">*</span></label>
                <input type="number" class="edit-form-input" id="newMarks" min="1" value="1">
            </div>
            <div class="edit-form-group">
                <label class="edit-form-label">Negative Marks</label>
                <input type="number" class="edit-form-input" id="newNegMarks" min="0" step="0.25" value="0">
            </div>
        </div>

        <div class="edit-form-group">
            <label class="edit-form-label">Explanation (optional)</label>
            <textarea class="edit-form-input" id="newExpl" rows="2" placeholder="Explanation shown after submission…"></textarea>
        </div>

        <div style="display:flex;gap:10px;margin-top:22px;">
            <button class="btn btn-primary" onclick="addNewQ()" style="flex:1;justify-content:center;">
                <i class="fa fa-plus"></i> Add Question
            </button>
            <button class="btn btn-secondary" onclick="closeAddModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- ── CONFIRM MODAL ── -->
<div class="confirm-modal-overlay" id="confirmModal">
    <div class="confirm-modal">
        <div class="confirm-modal-icon"><i class="fa fa-triangle-exclamation"></i></div>
        <div class="confirm-modal-title" id="confirmTitle">Confirm Action</div>
        <div class="confirm-modal-body"  id="confirmBody">Are you sure?</div>
        <div class="confirm-actions">
            <button class="btn-cancel-confirm" onclick="closeConfirmModal()">Cancel</button>
            <button class="btn-confirm-action" id="confirmActionBtn">Confirm</button>
        </div>
    </div>
</div>

<!-- ── LOADING ── -->
<div class="loading-overlay" id="loading">
    <div class="loading-content">
        <div class="spinner"></div>
        <div class="loading-text" id="loadingText">Saving…</div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
const ASSESSMENT_ID = <?= $assessmentId ?>;
let editingSet      = new Set();
let confirmCallback = null;
let csrfToken       = null;

async function getCsrfToken() {
    if (csrfToken) return csrfToken;
    const res  = await fetch('api/csrf-token.php', { credentials: 'same-origin' });
    const data = await res.json();
    if (!data.success) throw new Error('Could not fetch CSRF token.');
    csrfToken = data.token;
    return csrfToken;
}

// ── Toast / Loading ──
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className   = 'toast ' + type;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3000);
}
function showLoading(msg = 'Saving…') {
    document.getElementById('loadingText').textContent = msg;
    document.getElementById('loading').classList.add('active');
}
function hideLoading() {
    document.getElementById('loading').classList.remove('active');
}

// ── Confirm Modal ──
function openConfirmModal(title, body, onConfirm, btnLabel = 'Confirm', dangerous = false) {
    document.getElementById('confirmTitle').textContent = title;
    document.getElementById('confirmBody').textContent  = body;
    const btn = document.getElementById('confirmActionBtn');
    btn.textContent = btnLabel;
    btn.className   = 'btn-confirm-action' + (dangerous ? '' : ' green');
    btn.disabled    = false;
    confirmCallback = onConfirm;
    document.getElementById('confirmModal').classList.add('open');
}
function closeConfirmModal() {
    document.getElementById('confirmModal').classList.remove('open');
    confirmCallback = null;
}
document.getElementById('confirmActionBtn').addEventListener('click', function() {
    if (confirmCallback) { this.disabled = true; confirmCallback(); }
});
document.getElementById('confirmModal').addEventListener('click', function(e) {
    if (e.target === this) closeConfirmModal();
});

// ── Status Toggle ──
function toggleStatus() {
    const toggle    = document.getElementById('statusToggle');
    const isActive  = toggle.classList.contains('active');
    const newStatus = isActive ? 'draft' : 'published';

    openConfirmModal(
        isActive ? 'Set to Draft?' : 'Publish Assessment?',
        isActive
            ? 'Students will no longer be able to take this assessment.'
            : 'Students will be able to take this assessment immediately.',
        async () => {
            closeConfirmModal();
            showLoading('Updating status…');
            try {
                const token = await getCsrfToken();
                const res   = await fetch('api/assessment/update-status.php', {
                    method:      'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
                    body:    JSON.stringify({ assessment_id: ASSESSMENT_ID, status: newStatus })
                });
                const data = await res.json();
                if (data.success) {
                    toggle.classList.toggle('active');
                    const badge = document.getElementById('statusBadge');
                    badge.className = 'meta-badge ' + (newStatus === 'published' ? 'active' : newStatus);
                    badge.innerHTML = '<span class="badge-dot"></span> ' + (newStatus === 'published' ? 'Published' : 'Draft');
                    showToast('Status updated to ' + newStatus);
                } else {
                    showToast(data.error || 'Failed to update status.', 'error');
                }
            } catch {
                showToast('Network error. Please try again.', 'error');
            } finally {
                hideLoading();
            }
        },
        isActive ? 'Set Draft' : 'Publish',
        false
    );
}

// ── Save All Changes ──
async function saveAll() {
    const title    = document.getElementById('assessmentTitle').value.trim();
    const category = document.getElementById('assessmentCategory').value;
    const diff     = document.getElementById('difficultyLevel').value;
    const dur      = parseInt(document.getElementById('duration').value, 10);
    const marks    = parseInt(document.getElementById('totalMarks').value, 10);
    const passing  = parseInt(document.getElementById('passingMarks').value, 10);

    if (!title)    { showToast('Title is required.', 'error'); return; }
    if (!category) { showToast('Category is required.', 'error'); return; }
    if (!diff)     { showToast('Difficulty is required.', 'error'); return; }
    if (!dur || dur < 1) { showToast('Duration must be at least 1 minute.', 'error'); return; }
    if (!marks || marks < 1) { showToast('Total marks must be at least 1.', 'error'); return; }
    if (passing < 0 || passing > marks) { showToast('Passing marks must be between 0 and total marks.', 'error'); return; }

    if (editingSet.size > 0) {
        showToast('You have unsaved question edits. Save or cancel them first.', 'error');
        return;
    }

    const btn = document.getElementById('saveAllBtn');
    btn.disabled = true;
    showLoading('Saving changes…');

    const isPublicChecked = document.getElementById('isPublic').checked;
    const targets         = isPublicChecked ? [] : collectTargets();
    const visibilityVal   = isPublicChecked ? 'public'
                          : targets.length  ? 'group'
                          : 'private';

    const payload = {
        assessment_id       : ASSESSMENT_ID,
        title,
        description         : document.getElementById('assessmentDescription').value.trim(),
        category,
        difficulty          : diff,
        duration_minutes    : dur,
        total_marks         : marks,
        passing_marks       : passing,
        max_attempts        : parseInt(document.getElementById('maxAttempts').value, 10) || 1,
        start_time          : document.getElementById('startTime').value || null,
        end_time            : document.getElementById('endTime').value || null,
        randomize_questions : document.getElementById('randomizeQuestions').checked ? 1 : 0,
        randomize_options   : document.getElementById('randomizeOptions').checked ? 1 : 0,
        visibility          : visibilityVal,
        targets             : targets,
    };

    try {
        const token = await getCsrfToken();
        const res   = await fetch('api/assessment/update.php', {
            method:      'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
            body:    JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.success) {
            document.getElementById('headerTitle').textContent = title;
            showToast('All changes saved!');
        } else {
            showToast(data.error || 'Save failed. Please try again.', 'error');
        }
    } catch {
        showToast('Network error. Please try again.', 'error');
    } finally {
        hideLoading();
        btn.disabled = false;
    }
}

// ── Question Edit / Cancel ──
function editQ(id) {
    if (editingSet.has(id)) return;
    editingSet.add(id);
    document.getElementById('disp' + id).classList.add('hidden');
    document.getElementById('edit' + id).classList.add('active');
    document.querySelector('[data-qid="' + id + '"]').classList.add('editing');
}
function cancelQ(id) {
    editingSet.delete(id);
    document.getElementById('disp' + id).classList.remove('hidden');
    document.getElementById('edit' + id).classList.remove('active');
    document.querySelector('[data-qid="' + id + '"]').classList.remove('editing');
}

// ── Save Question ──
async function saveQ(id, qtype) {
    const qt = document.getElementById('qt' + id).value.trim();
    if (!qt) { showToast('Question text is required.', 'error'); return; }

    const marks    = parseInt(document.getElementById('marks'   + id).value, 10) || 1;
    const negMarks = parseFloat(document.getElementById('negmarks' + id).value) || 0;
    const expl     = document.getElementById('expl' + id).value.trim();

    const isMCQ = ['mcq', 'true_false', 'multiple_select'].includes(qtype);
    let options = [];

    if (isMCQ) {
        const opts = window.existingOpts?.[id] ?? [];

        if (qtype === 'true_false') {
            const correctOptId = parseInt(document.getElementById('tfCorrect' + id).value, 10);
            opts.forEach(o => {
                options.push({
                    option_id  : o.option_id,
                    option_text: o.option_text,
                    is_correct : o.option_id === correctOptId,
                });
            });
        } else {
            for (let oi = 0; oi < opts.length; oi++) {
                const textEl    = document.getElementById('opt' + id + '_' + oi);
                const correctEl = document.getElementById('isCorrect' + id + '_' + oi);
                if (!textEl) continue;
                options.push({
                    option_id  : opts[oi].option_id,
                    option_text: textEl.value.trim(),
                    is_correct : correctEl ? correctEl.checked : false,
                });
            }
            if (!options.some(o => o.is_correct)) {
                showToast('At least one correct answer is required.', 'error');
                return;
            }
        }
    } else {
        const optId = parseInt(document.getElementById('shortOptId' + id).value, 10) || 0;
        const text  = document.getElementById('ca' + id).value.trim();
        if (!text) { showToast('Expected answer is required.', 'error'); return; }
        options.push({ option_id: optId, option_text: text, is_correct: true });
    }

    const payload = {
        question_id   : id,
        assessment_id : ASSESSMENT_ID,
        question_text : qt,
        marks,
        negative_marks: negMarks,
        explanation   : expl,
        options,
    };

    showLoading('Saving question…');
    try {
        const token = await getCsrfToken();
        const res   = await fetch('api/assessment/update-question.php', {
            method:      'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
            body:    JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.success) {
            showToast('Question saved!');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast(data.error || 'Failed to save question.', 'error');
        }
    } catch {
        showToast('Network error. Please try again.', 'error');
    } finally {
        hideLoading();
    }
}

// ── Delete Question ──
function deleteQ(id, num) {
    openConfirmModal(
        'Delete Question ' + num + '?',
        'This will permanently remove the question and all associated student answers.',
        async () => {
            closeConfirmModal();
            showLoading('Deleting question…');
            try {
                const token = await getCsrfToken();
                const res   = await fetch('api/assessment/delete-question.php', {
                    method:      'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
                    body:    JSON.stringify({ question_id: id, assessment_id: ASSESSMENT_ID })
                });
                const data = await res.json();
                if (data.success) {
                    const card = document.querySelector('[data-qid="' + id + '"]');
                    card.style.transition = 'opacity 0.3s, transform 0.3s';
                    card.style.opacity    = '0';
                    card.style.transform  = 'translateX(-8px)';
                    setTimeout(() => { card.remove(); renumberQuestions(); }, 300);
                    showToast('Question deleted.');
                } else {
                    showToast(data.error || 'Delete failed.', 'error');
                }
            } catch {
                showToast('Network error. Please try again.', 'error');
            } finally {
                hideLoading();
            }
        },
        'Delete', true
    );
}

function renumberQuestions() {
    const cards = document.querySelectorAll('.question-card');
    cards.forEach((card, i) => {
        const numEl = card.querySelector('.question-number');
        if (numEl) numEl.textContent = 'Q' + (i + 1);
    });
    document.getElementById('questionCountTitle').textContent = 'Questions (' + cards.length + ')';
    const noMsg = document.getElementById('noQuestionsMsg');
    if (cards.length === 0 && noMsg) noMsg.style.display = 'block';
}

// ── Add Question Modal ──
function switchType(type, btn) {
    document.getElementById('newQType').value = type;
    document.querySelectorAll('.type-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');

    document.getElementById('mcqFields').classList.remove('show');
    document.getElementById('tfFields').classList.remove('show');
    document.getElementById('shortFields').classList.remove('show');
    document.getElementById('mcqAnswerGroup').style.display = 'none';
    document.getElementById('msAnswerGroup').style.display  = 'none';

    if (type === 'mcq') {
        document.getElementById('mcqFields').classList.add('show');
        document.getElementById('mcqAnswerGroup').style.display = 'block';
    } else if (type === 'multiple_select') {
        document.getElementById('mcqFields').classList.add('show');
        document.getElementById('msAnswerGroup').style.display  = 'block';
    } else if (type === 'true_false') {
        document.getElementById('tfFields').classList.add('show');
    } else {
        document.getElementById('shortFields').classList.add('show');
    }
}

function openAddModal() {
    ['newQt','newOa','newOb','newOc','newOd','newCaShort','newExpl'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    document.getElementById('newCa').value   = '';
    document.getElementById('newCaTf').value = 'true';
    document.getElementById('newMarks').value    = '1';
    document.getElementById('newNegMarks').value = '0';
    ['msA','msB','msC','msD'].forEach(id => { document.getElementById(id).checked = false; });

    document.querySelectorAll('.type-tab').forEach(t => t.classList.remove('active'));
    const mcqTab = document.querySelector('[data-type="mcq"]');
    mcqTab.classList.add('active');
    switchType('mcq', mcqTab);

    document.getElementById('addModal').classList.add('active');
}
function closeAddModal() {
    document.getElementById('addModal').classList.remove('active');
}

// ── Add Question Submit ──
async function addNewQ() {
    const type = document.getElementById('newQType').value;
    const qt   = document.getElementById('newQt').value.trim();
    if (!qt) { showToast('Question text is required.', 'error'); return; }

    const marks    = parseInt(document.getElementById('newMarks').value, 10) || 1;
    const negMarks = parseFloat(document.getElementById('newNegMarks').value) || 0;
    const expl     = document.getElementById('newExpl').value.trim();

    let options = [];

    if (type === 'mcq') {
        const texts = ['a','b','c','d'].map(l => document.getElementById('newO' + l).value.trim());
        const correctLetter = document.getElementById('newCa').value.toUpperCase();
        if (!texts[0] || !texts[1]) { showToast('Options A and B are required.', 'error'); return; }
        if (!correctLetter) { showToast('Select a correct answer.', 'error'); return; }
        ['A','B','C','D'].forEach((l, i) => {
            if (!texts[i]) return;
            options.push({ option_text: texts[i], is_correct: l === correctLetter, option_order: i + 1 });
        });

    } else if (type === 'multiple_select') {
        const texts = ['a','b','c','d'].map(l => document.getElementById('newO' + l).value.trim());
        if (!texts[0] || !texts[1]) { showToast('Options A and B are required.', 'error'); return; }
        const correct = ['A','B','C','D'].filter(l => document.getElementById('ms' + l).checked);
        if (!correct.length) { showToast('Select at least one correct option.', 'error'); return; }
        ['A','B','C','D'].forEach((l, i) => {
            if (!texts[i]) return;
            options.push({ option_text: texts[i], is_correct: correct.includes(l), option_order: i + 1 });
        });

    } else if (type === 'true_false') {
        const correctVal = document.getElementById('newCaTf').value;
        options = [
            { option_text: 'True',  is_correct: correctVal === 'true',  option_order: 1 },
            { option_text: 'False', is_correct: correctVal === 'false', option_order: 2 },
        ];

    } else {
        const answer = document.getElementById('newCaShort').value.trim();
        if (!answer) { showToast('Expected answer is required.', 'error'); return; }
        options = [{ option_text: answer, is_correct: true, option_order: 1 }];
    }

    const payload = {
        assessment_id : ASSESSMENT_ID,
        question_type : type,
        question_text : qt,
        marks,
        negative_marks: negMarks,
        explanation   : expl,
        options,
    };

    closeAddModal();
    showLoading('Adding question…');
    try {
        const token = await getCsrfToken();
        const res   = await fetch('api/assessment/add-question.php', {
            method:      'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
            body:    JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.success) {
            showToast('Question added!');
            setTimeout(() => location.reload(), 600);
        } else {
            showToast(data.error || 'Failed to add question.', 'error');
        }
    } catch {
        showToast('Network error. Please try again.', 'error');
    } finally {
        hideLoading();
    }
}

// ── Delete Assessment ──
function confirmDeleteAssessment() {
    openConfirmModal(
        'Delete Assessment?',
        'This will permanently delete the assessment, all questions, and all student attempts.',
        () => {
            closeConfirmModal();
            const code = prompt('Type DELETE to confirm:');
            if (code !== 'DELETE') { showToast('Deletion cancelled.'); return; }
            doDeleteAssessment();
        },
        'Delete', true
    );
}

async function doDeleteAssessment() {
    showLoading('Deleting assessment…');
    try {
        const token = await getCsrfToken();
        const res   = await fetch('api/assessment/delete.php', {
            method:      'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
            body:    JSON.stringify({ assessment_id: ASSESSMENT_ID })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Assessment deleted. Redirecting…');
            setTimeout(() => { window.location.href = 'teacher-dashboard.php'; }, 1200);
        } else {
            hideLoading();
            showToast(data.error || 'Delete failed.', 'error');
        }
    } catch {
        hideLoading();
        showToast('Network error. Please try again.', 'error');
    }
}

// ── Targeting helpers ──
function toggleGroupPicker() {
    const isPublic = document.getElementById('isPublic').checked;
    const block    = document.getElementById('targetingBlock');
    if (block) block.style.display = isPublic ? 'none' : '';
}

function switchTargetTab(tab, btn) {
    document.querySelectorAll('.target-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('targetTabGroups').style.display   = tab === 'groups'   ? '' : 'none';
    document.getElementById('targetTabStudents').style.display = tab === 'students' ? '' : 'none';
}

function collectTargets() {
    const targets = [];
    document.querySelectorAll('.group-pick-cb:checked').forEach(cb => {
        targets.push({ type: 'group', id: parseInt(cb.value) });
    });
    document.querySelectorAll('.student-pick-hidden').forEach(inp => {
        targets.push({ type: 'student', id: parseInt(inp.value) });
    });
    return targets;
}

let _studentSearchTimer = null;
function debounceStudentSearch(val) {
    clearTimeout(_studentSearchTimer);
    if (val.length < 2) { closeStudentResults(); return; }
    _studentSearchTimer = setTimeout(() => searchStudents(val), 300);
}

async function searchStudents(q) {
    try {
        const token = await getCsrfToken();
        const res   = await fetch('api/students/search-students.php?q=' + encodeURIComponent(q), {
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': token },
        });
        const data = await res.json();
        if (!data.success) return;
        const box = document.getElementById('studentSearchResults');
        if (!data.students.length) {
            box.innerHTML = '<div class="student-result-item" style="color:#6b7280;">No students found.</div>';
        } else {
            box.innerHTML = data.students.map(s => {
                if (document.querySelector(`.student-pick-hidden[value="${s.user_id}"]`)) return '';
                const meta = [s.registration_number, s.department].filter(Boolean).join(' · ');
                return `<div class="student-result-item" onclick="addStudentTarget(${s.user_id}, ${JSON.stringify(s.full_name)})">
                    <div class="student-result-name">${escHtml(s.full_name)}</div>
                    <div class="student-result-meta">${escHtml(s.email)}${meta ? ' · ' + escHtml(meta) : ''}</div>
                </div>`;
            }).join('');
        }
        box.style.display = 'block';
        document.addEventListener('click', closeStudentResultsOutside, { once: true });
    } catch(e) { console.error(e); }
}

function closeStudentResultsOutside(e) {
    const box = document.getElementById('studentSearchResults');
    if (box && !box.contains(e.target)) closeStudentResults();
}

function closeStudentResults() {
    const box = document.getElementById('studentSearchResults');
    if (box) box.style.display = 'none';
}

function addStudentTarget(userId, fullName) {
    if (document.querySelector(`.student-pick-hidden[value="${userId}"]`)) {
        closeStudentResults();
        document.getElementById('studentSearchInput').value = '';
        return;
    }
    const chips  = document.getElementById('selectedStudentsChips');
    const chip   = document.createElement('span');
    chip.className   = 'student-chip';
    chip.dataset.id  = userId;
    chip.innerHTML   = `${escHtml(fullName)} <button type="button" onclick="removeStudentChip(${userId})">&times;</button>`;
    const hidden     = document.createElement('input');
    hidden.type      = 'hidden';
    hidden.className = 'student-pick-hidden';
    hidden.value     = userId;
    chips.appendChild(chip);
    chips.appendChild(hidden);
    closeStudentResults();
    document.getElementById('studentSearchInput').value = '';
}

function removeStudentChip(userId) {
    document.querySelector(`.student-chip[data-id="${userId}"]`)?.remove();
    document.querySelector(`.student-pick-hidden[value="${userId}"]`)?.remove();
}

function escHtml(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Keyboard Shortcuts ──
document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); saveAll(); }
    if (e.key === 'Escape') {
        closeAddModal();
        closeConfirmModal();
        editingSet.forEach(id => cancelQ(id));
    }
});
document.getElementById('addModal').addEventListener('click', function(e) {
    if (e.target === this) closeAddModal();
});

// ── Profile Dropdown ──
const profileBtn  = document.getElementById('profileBtn');
const profileDrop = document.getElementById('profileDropdown');
profileBtn.addEventListener('click', e => { e.stopPropagation(); profileDrop.classList.toggle('open'); });
document.addEventListener('click', () => profileDrop.classList.remove('open'));

function handleLogout() {
    if (confirm('Are you sure you want to logout?')) window.location.href = 'logout.php';
}
</script>

<!-- ── REPORT MODAL ── -->
<div class="report-modal-overlay" id="reportModalOverlay" onclick="if(event.target===this)closeReportModal()">
    <div class="report-modal">
        <div class="report-modal-header">
            <div>
                <div class="report-modal-title">🚩 Help &amp; Support</div>
                <div class="report-modal-sub">We'll review your report and get back to you</div>
            </div>
            <button class="report-modal-close" onclick="closeReportModal()">✕</button>
        </div>

        <?php if (!empty($_GET['report']) && $_GET['report'] === 'sent'): ?>
        <div class="report-success-banner">✅ Your report was submitted! We'll look into it soon.</div>
        <?php endif; ?>

        <?php if ($latestReportStatus): ?>
        <div style="margin:16px 24px 0;padding:12px 16px;border-radius:var(--r-md,14px);
            background:<?= $hasOpenReport ? '#fff8ed' : '#d1fae5' ?>;
            border:1px solid <?= $hasOpenReport ? '#f59e0b' : '#a7f3d0' ?>;
            font-size:13px;font-weight:600;
            color:<?= $hasOpenReport ? '#92400e' : '#065f46' ?>;
            display:flex;align-items:center;gap:8px;">
            <?= $hasOpenReport
                ? '⏳ Your last report is <strong>' . ucfirst(str_replace('_', ' ', $latestReportStatus)) . '</strong> — admin will respond soon.'
                : '✅ Your last report has been <strong>Resolved</strong>.' ?>
        </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="reportForm">
            <input type="hidden" name="action" value="submit_report">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <div class="report-modal-body">
                <div class="report-field">
                    <label>Report Title <span>*</span></label>
                    <input type="text" name="report_title" placeholder="e.g. Page not loading" required maxlength="150">
                </div>
                <div class="report-field">
                    <label>Explanation <span>*</span></label>
                    <textarea name="report_description" rows="4" placeholder="Describe the issue in detail..." required maxlength="2000"></textarea>
                </div>
                <div class="report-field">
                    <label>Screenshot / Image <span style="color:var(--text-3,#8b7fa8);font-weight:500;">(optional)</span></label>
                    <label for="reportImageInput" class="report-drop-zone" id="reportDropZone">
                        <div class="dz-icon">📷</div>
                        <div class="dz-text">Click to upload or drag &amp; drop</div>
                        <div class="dz-sub">JPG, PNG, GIF, WEBP — max 5 MB</div>
                    </label>
                    <input type="file" name="report_image" id="reportImageInput"
                        accept="image/jpeg,image/png,image/gif,image/webp"
                        style="display:none" onchange="previewReportImg(this)">
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
function openReportModal() {
    document.getElementById('reportModalOverlay').classList.add('open');
    document.addEventListener('keydown', escReport);
}
function closeReportModal() {
    document.getElementById('reportModalOverlay').classList.remove('open');
    document.removeEventListener('keydown', escReport);
}
function escReport(e) { if (e.key === 'Escape') closeReportModal(); }
function previewReportImg(input) {
    const file = input.files[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) { alert('Image must be under 5MB.'); input.value = ''; return; }
    const reader = new FileReader();
    reader.onload = e => {
        const p = document.getElementById('reportImgPreview');
        p.src = e.target.result; p.style.display = 'block';
        document.querySelector('#reportDropZone .dz-text').textContent = file.name;
        document.querySelector('#reportDropZone .dz-sub').textContent = (file.size/1024).toFixed(1)+' KB';
    };
    reader.readAsDataURL(file);
}
const _rdz = document.getElementById('reportDropZone');
if (_rdz) {
    _rdz.addEventListener('dragover', e => { e.preventDefault(); _rdz.classList.add('dragover'); });
    _rdz.addEventListener('dragleave', () => _rdz.classList.remove('dragover'));
    _rdz.addEventListener('drop', e => {
        e.preventDefault(); _rdz.classList.remove('dragover');
        const file = e.dataTransfer.files[0];
        if (file && file.type.startsWith('image/')) {
            const inp = document.getElementById('reportImageInput');
            try { const dt = new DataTransfer(); dt.items.add(file); inp.files = dt.files; } catch(ex) {}
            previewReportImg(inp);
        }
    });
}
<?php if (!empty($_GET['report']) && $_GET['report'] === 'sent'): ?>
window.addEventListener('load', () => openReportModal());
<?php endif; ?>
</script>
</body>
</html>
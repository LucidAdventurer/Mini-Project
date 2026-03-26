<?php
/* ========================================
 * CREATE / EDIT DRAFT ASSESSMENT
 * File: create-assessment.php
 *
 * Modes:
 *   ?            — new blank assessment
 *   ?edit=<id>   — continue editing a draft
 *
 * Access: Teachers only
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

$currentUser  = validateSession($conn, 'teacher');
$teacherId    = (int) $currentUser['user_id'];
$userName     = htmlspecialchars($currentUser['full_name'] ?? 'Teacher');
$userEmail    = htmlspecialchars($currentUser['email'] ?? '');
$userInitials = strtoupper(substr($currentUser['full_name'] ?? 'T', 0, 2));

// Fetch profile_image (validateSession may not include it)
$picStmt = $conn->prepare("SELECT profile_image FROM users WHERE user_id = ?");
$picStmt->bind_param("i", $teacherId);
$picStmt->execute();
$picRow      = $picStmt->get_result()->fetch_assoc();
$userPicture = $picRow['profile_image'] ?? '';

$editMode     = false;
$assessmentId = 0;
$assessment   = null;
$questions    = [];

if (isset($_GET['edit']) && (int)$_GET['edit'] > 0) {
    $assessmentId = (int)$_GET['edit'];

    $r = safePreparedQuery($conn,
        "SELECT assessment_id, title, description, category, difficulty,
                status, visibility, duration_minutes, total_marks, passing_marks,
                max_attempts, start_time, end_time,
                randomize_questions, randomize_options, created_at
         FROM assessments
         WHERE assessment_id = ? AND created_by = ?",
        "ii", [$assessmentId, $teacherId]
    );
    if ($r['success'] && $r['result']) {
        $assessment = $r['result']->fetch_assoc();
        $r['result']->free();
    }

    if (!$assessment) {
        header('Location: create-assessment.php');
        exit;
    }

    // Only allow editing drafts here; active/archived go to edit-assessment.php
    if ($assessment['status'] === 'published' || $assessment['status'] === 'active' || $assessment['status'] === 'archived') {
        header('Location: edit-assessment.php?id=' . $assessmentId);
        exit;
    }

    $editMode = true;

    // Load questions + options
    $rq = safePreparedQuery($conn,
        "SELECT question_id, question_type, question_text, marks, negative_marks,
                explanation, question_order
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

    if (!empty($questions)) {
        $qids  = implode(',', array_keys($questions));
        $ropts = $conn->query(
            "SELECT option_id, question_id, option_text, is_correct, option_order
             FROM question_options
             WHERE question_id IN ($qids)
             ORDER BY option_order ASC, option_id ASC"
        );
        if ($ropts) {
            while ($opt = $ropts->fetch_assoc()) {
                $qid = (int)$opt['question_id'];
                if (isset($questions[$qid])) {
                    $questions[$qid]['options'][] = $opt;
                }
            }
            $ropts->free();
        }
    }
    $questions = array_values($questions);
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
    while ($row = $rg['result']->fetch_assoc()) {
        $teacherGroups[] = $row;
    }
    $rg['result']->free();
}

// ── Load existing targets (edit mode) ──
$existingTargets = [];
if ($assessmentId > 0) {
    $rt = safePreparedQuery($conn,
        "SELECT target_type, target_id FROM assessment_targets WHERE assessment_id = ?",
        "i", [$assessmentId]
    );
    if ($rt['success'] && $rt['result']) {
        while ($row = $rt['result']->fetch_assoc()) {
            $existingTargets[] = ['type' => $row['target_type'], 'id' => (int)$row['target_id']];
        }
        $rt['result']->free();
    }
}

function toDatetimeLocal(?string $dt): string {
    if (!$dt) return '';
    return date('Y-m-d\TH:i', strtotime($dt));
}
function val(?array $a, string $key, string $default = ''): string {
    if ($a === null) return $default;
    return htmlspecialchars($a[$key] ?? $default, ENT_QUOTES, 'UTF-8');
}
function checked(?array $a, string $key, bool $defaultOn = false): string {
    if ($a === null) return $defaultOn ? 'checked' : '';
    return !empty($a[$key]) ? 'checked' : '';
}
function sel(?array $a, string $key, string $value, string $default = ''): string {
    $current = $a[$key] ?? $default;
    return $current === $value ? 'selected' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $editMode ? 'Continue Editing Draft' : 'Create Assessment' ?> - Placement Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink:        #0d0a14;
            --ink-2:      #1a1425;
            --ink-3:      #261d35;
            --surface:    #f7f5fb;
            --surface-2:  #ede9f6;
            --surface-3:  #ffffff;
            --violet:     #7c3aed;
            --violet-lt:  #9f67f5;
            --violet-dim: rgba(124,58,237,0.12);
            --orchid:     #c084fc;
            --gold:       #f59e0b;
            --emerald:    #10b981;
            --rose:       #f43f5e;
            --sky:        #38bdf8;
            --text-1:     #1a1425;
            --text-2:     #4b4565;
            --text-3:     #8b7fa8;
            --border:     rgba(124,58,237,0.15);
            --shadow-sm:  0 2px 12px rgba(13,10,20,0.08);
            --shadow-md:  0 4px 20px rgba(13,10,20,0.12);
            --shadow-vl:  0 8px 32px rgba(13,10,20,0.14);
            --nav-h:      64px;
            --sidebar-w:  230px;
            --radius:     10px;
            --radius-lg:  18px;
            --r-sm:       8px;
            --r-md:       14px;
            --r-lg:       20px;
            --r-xl:       28px;
            --transition: all 0.25s ease;
            /* legacy compat */
            --font-family: 'DM Sans', sans-serif;
            --color-teacher-primary: #7c3aed;
            --color-teacher-secondary: #9f67f5;
            --color-text: var(--text-1);
            --color-text-light: var(--text-3);
            --color-bg: var(--surface);
            --color-bg-light: var(--surface-2);
            --color-border: var(--border);
            --color-success: var(--emerald);
            --color-error: var(--rose);
            --shadow-lg: var(--shadow-vl);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--surface);
            min-height: 100vh;
            color: var(--text-1);
            padding-top: var(--nav-h);
        }
        body::before {
            content: '';
            position: fixed; inset: 0; pointer-events: none; z-index: 0;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='300'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='300' height='300' filter='url(%23n)' opacity='0.03'/%3E%3C/svg%3E");
            opacity: 0.4;
        }

        /* ── NAVBAR ── */
        .navbar {
            background: rgba(13,10,20,0.96);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            padding: 0 28px;
            height: var(--nav-h);
            display: flex; align-items: center; justify-content: space-between;
            position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
            border-bottom: 1px solid rgba(124,58,237,0.2);
        }
        .navbar-brand {
            display: flex; align-items: center; gap: 12px;
            font-family: 'Syne', sans-serif;
            font-size: 20px; font-weight: 700; color: white; text-decoration: none;
        }
        .brand-logo-img {
            width: 38px; height: 38px;
            border-radius: 9px;
            object-fit: contain;
            flex-shrink: 0;
            background: var(--violet-dim);
            padding: 4px;
            border: 1px solid rgba(124,58,237,0.3);
        }
        .brand-text-group {
            display: flex; flex-direction: column; line-height: 1.1; color: white;
        }
        .brand-name    { font-size: 17px; font-weight: 800; letter-spacing: .5px; font-family: 'Syne', sans-serif; }
        .brand-tagline { font-size: 10px; font-weight: 400; opacity: .6; letter-spacing: .03em; }
        .nav-profile-btn {
            display: flex; align-items: center; gap: 10px;
            padding: 7px 14px; background: rgba(124,58,237,0.15);
            border: 1px solid rgba(124,58,237,0.25); border-radius: 10px;
            cursor: pointer; transition: var(--transition); color: white;
        }
        .nav-profile-btn:hover { background: rgba(124,58,237,0.28); border-color: rgba(124,58,237,0.5); }
        .nav-avatar {
            width: 32px; height: 32px;
            background: linear-gradient(135deg, var(--violet), var(--orchid));
            border: 2px solid rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 12px;
            overflow: hidden;
        }
        .profile-dropdown {
            position: absolute; top: calc(100% + 10px); right: 0;
            background: var(--surface-3); border-radius: 12px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-vl); min-width: 220px;
            opacity: 0; visibility: hidden; transform: translateY(-8px);
            transition: all 0.25s ease; z-index: 1001;
        }
        .profile-dropdown.open { opacity: 1; visibility: visible; transform: translateY(0); }
        .dropdown-header { padding: 16px 20px; border-bottom: 1px solid var(--border); }
        .dropdown-name  { font-weight: 700; font-size: 14px; color: var(--text-1); }
        .dropdown-email { font-size: 12px; color: var(--text-3); margin-top: 2px; }
        .dropdown-menu  { padding: 6px 0; }
        .dropdown-item {
            display: flex; align-items: center; gap: 12px;
            padding: 11px 20px; color: var(--text-2);
            text-decoration: none; font-size: 14px; transition: all 0.2s;
            cursor: pointer; border: none; background: none; width: 100%; text-align: left;
            font-family: inherit;
        }
        .dropdown-item:hover { background: var(--surface-2); color: var(--violet); }
        .dropdown-item.danger { color: var(--rose); }
        .dropdown-item.danger:hover { background: rgba(244,63,94,0.08); }
        .dropdown-divider { height: 1px; background: var(--border); margin: 4px 0; }

        .container { max-width: 960px; margin: 0 auto; padding: 30px 20px 60px; position: relative; z-index: 1; }

        /* ── PAGE HEADER ── */
        .page-header {
            background: linear-gradient(135deg, var(--ink) 0%, var(--ink-3) 55%, #3d1f6e 100%);
            border-radius: var(--r-xl); padding: 32px 40px;
            margin-bottom: 24px; box-shadow: var(--shadow-md);
            position: relative; overflow: hidden;
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
        .page-header-inner { position: relative; z-index: 1; }
        .page-header-label {
            font-size: 11px; font-weight: 600; letter-spacing: 0.1em;
            text-transform: uppercase; color: var(--orchid); margin-bottom: 6px;
        }
        .page-title {
            font-family: 'Syne', sans-serif;
            font-size: 28px; font-weight: 800; color: white; margin-bottom: 5px;
        }
        .page-subtitle { font-size: 14px; color: rgba(255,255,255,0.55); }
        .draft-badge {
            display: inline-flex; align-items: center; gap: 6px;
            margin-top: 10px; padding: 5px 12px;
            background: rgba(245,158,11,0.18); color: #fcd34d;
            border: 1px solid rgba(245,158,11,0.35);
            border-radius: 40px; font-size: 12px; font-weight: 700;
        }

        /* ── STEPS ── */
        .steps-bar {
            display: flex; margin-bottom: 20px;
            background: var(--surface-3); border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm); overflow: hidden;
            border: 1px solid var(--border);
        }
        .step {
            flex: 1; padding: 16px 12px; text-align: center;
            font-size: 13px; font-weight: 600; color: var(--text-3);
            border-right: 1px solid var(--border); cursor: pointer;
            transition: var(--transition);
        }
        .step:last-child { border-right: none; }
        .step.active { color: var(--violet); background: var(--violet-dim); }
        .step.done   { color: var(--emerald); }
        .step-num { display: block; font-size: 20px; margin-bottom: 4px; font-family: 'Syne', sans-serif; }
        .step.done .step-num::after { content: ' ✓'; font-size: 14px; }

        /* ── PANELS ── */
        .panel {
            background: var(--surface-3); border-radius: var(--radius-lg); padding: 28px 30px;
            margin-bottom: 20px; box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
            display: none;
        }
        .panel.active { display: block; }
        .panel-title {
            font-family: 'Syne', sans-serif;
            font-size: 17px; font-weight: 700; color: var(--text-1);
            margin-bottom: 22px; display: flex; align-items: center; gap: 10px;
        }
        .panel-icon {
            width: 34px; height: 34px; border-radius: 9px;
            background: linear-gradient(135deg, var(--violet), var(--orchid));
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 16px; flex-shrink: 0;
        }

        /* ── FORM ELEMENTS ── */
        .form-group { margin-bottom: 18px; }
        .form-label {
            display: block; font-size: 13px; font-weight: 600;
            color: var(--text-2); margin-bottom: 7px;
        }
        .form-label .req { color: var(--rose); margin-left: 3px; }
        .form-input {
            width: 100%; padding: 10px 14px;
            border: 1.5px solid var(--border); border-radius: var(--radius);
            font-size: 14px; font-family: 'DM Sans', sans-serif;
            transition: var(--transition); background: var(--surface-3); color: var(--text-1);
        }
        .form-input:focus {
            outline: none; border-color: var(--violet);
            box-shadow: 0 0 0 3px var(--violet-dim);
        }
        .form-textarea { min-height: 90px; resize: vertical; }
        .form-select {
            width: 100%; padding: 10px 14px;
            border: 1.5px solid var(--border); border-radius: var(--radius);
            font-size: 14px; background: var(--surface-3); cursor: pointer;
            font-family: 'DM Sans', sans-serif; color: var(--text-1);
            transition: var(--transition);
        }
        .form-select:focus {
            outline: none; border-color: var(--violet);
            box-shadow: 0 0 0 3px var(--violet-dim);
        }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 18px; }

        .checkbox-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; }
        .checkbox-label {
            display: flex; align-items: center; gap: 9px; cursor: pointer;
            padding: 10px 14px; background: var(--surface-2);
            border-radius: var(--radius); border: 1.5px solid var(--border);
            font-size: 13px; transition: var(--transition); user-select: none; color: var(--text-2);
        }
        .checkbox-label:hover { border-color: var(--violet); color: var(--violet); }
        .checkbox-label input[type=checkbox] { width: 15px; height: 15px; accent-color: var(--violet); }
        .group-picker { display: flex; flex-direction: column; gap: 8px; max-height: 260px; overflow-y: auto; }
        .group-pick-item {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 14px; border-radius: 8px;
            border: 1.5px solid var(--border); cursor: pointer;
            transition: border-color .15s, background .15s; background: var(--surface-3);
        }
        .group-pick-item:has(input:checked) { border-color: var(--violet); background: var(--violet-dim); }
        .group-pick-item input[type=checkbox] { width: 16px; height: 16px; accent-color: var(--violet); flex-shrink: 0; }
        .group-pick-name { flex: 1; font-size: 14px; font-weight: 500; color: var(--text-1); }
        .group-pick-count { font-size: 12px; color: var(--text-3); }
        .target-tabs { display: flex; gap: 6px; margin-bottom: 12px; }
        .target-tab {
            padding: 6px 16px; border-radius: 6px; border: 1.5px solid var(--border);
            background: var(--surface-3); font-size: 13px; font-weight: 500; cursor: pointer;
            transition: all .15s; color: var(--text-2);
        }
        .target-tab.active { border-color: var(--violet); background: var(--violet-dim); color: var(--violet); }
        .student-chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 10px; border-radius: 99px;
            background: var(--violet-dim); color: var(--violet);
            font-size: 13px; font-weight: 500; border: 1px solid rgba(124,58,237,0.2);
        }
        .student-chip button { background: none; border: none; cursor: pointer; font-size: 15px; line-height: 1; color: inherit; padding: 0; }
        .student-result-item {
            padding: 10px 14px; cursor: pointer; font-size: 13px;
            border-bottom: 1px solid var(--border); transition: background .1s; color: var(--text-1);
        }
        .student-result-item:hover { background: var(--surface-2); }
        .student-result-item:last-child { border-bottom: none; }
        .student-result-name { font-weight: 500; }
        .student-result-meta { font-size: 11px; color: var(--text-3); margin-top: 2px; }

        /* ── QUESTIONS LIST ── */
        .q-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .q-count { font-size: 14px; color: var(--text-3); font-weight: 600; }
        .q-list { display: flex; flex-direction: column; gap: 14px; }

        .q-card {
            border: 1.5px solid var(--border); border-radius: 12px;
            background: var(--surface); overflow: hidden;
            transition: var(--transition);
        }
        .q-card:hover { border-color: var(--violet); box-shadow: var(--shadow-sm); }
        .q-card-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 14px 18px;
            background: var(--surface-3); border-bottom: 1px solid var(--border);
        }
        .q-num-row { display: flex; align-items: center; gap: 8px; }
        .q-num  { font-weight: 700; color: var(--violet); font-size: 14px; font-family: 'Syne', sans-serif; }
        .q-type-badge {
            font-size: 11px; font-weight: 600; padding: 2px 8px;
            background: var(--violet-dim); color: var(--violet); border-radius: 4px;
        }
        .q-marks-badge {
            font-size: 11px; font-weight: 600; padding: 2px 8px;
            background: rgba(16,185,129,0.12); color: #065f46; border-radius: 4px;
        }
        .q-card-actions { display: flex; gap: 7px; }
        .q-card-body { padding: 14px 18px; background: var(--surface); }
        .q-text { font-size: 14px; color: var(--text-1); line-height: 1.6; margin-bottom: 10px; }

        .q-options { display: flex; flex-direction: column; gap: 6px; }
        .q-option {
            padding: 8px 12px; background: var(--surface-3); border-radius: 7px;
            font-size: 13px; display: flex; align-items: center; gap: 8px;
            border: 1.5px solid transparent; color: var(--text-2);
        }
        .q-option.correct { border-color: var(--emerald); background: rgba(16,185,129,0.07); }
        .q-opt-label { font-weight: 700; min-width: 22px; color: var(--text-3); }
        .q-correct-badge {
            margin-left: auto; padding: 2px 8px;
            background: rgba(16,185,129,0.15); color: #065f46;
            border-radius: 4px; font-size: 11px; font-weight: 700;
        }
        .q-short-ans {
            padding: 8px 12px; background: var(--surface-3); border-radius: 7px;
            font-size: 13px; border: 1.5px solid var(--emerald); color: var(--text-1);
        }
        .q-ans-label { font-size: 11px; font-weight: 600; color: var(--text-3); margin-bottom: 4px; }

        .no-questions { text-align: center; padding: 40px 20px; color: var(--text-3); font-size: 15px; }
        .no-q-icon { font-size: 44px; margin-bottom: 10px; opacity: 0.4; }

        /* ── ADD QUESTION AREA ── */
        .add-q-section {
            background: var(--surface-3); border-radius: var(--radius-lg); padding: 26px 28px;
            margin-bottom: 20px; box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
        }
        .add-q-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; }

        .type-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 18px; }
        .type-tab {
            padding: 6px 14px; background: var(--surface-2);
            border: 1.5px solid var(--border); border-radius: 8px;
            font-size: 12px; font-weight: 600; cursor: pointer; transition: var(--transition);
            color: var(--text-3);
        }
        .type-tab.active {
            background: var(--violet);
            border-color: var(--violet); color: white;
        }

        .add-form-group { margin-bottom: 14px; }
        .add-form-label {
            display: block; font-size: 12px; font-weight: 600;
            color: var(--text-2); margin-bottom: 5px;
        }
        .add-form-input {
            width: 100%; padding: 9px 12px;
            border: 1.5px solid var(--border); border-radius: 8px;
            font-size: 13px; font-family: 'DM Sans', sans-serif;
            transition: var(--transition); background: var(--surface-3); color: var(--text-1);
        }
        .add-form-input:focus {
            outline: none; border-color: var(--violet);
            box-shadow: 0 0 0 3px var(--violet-dim);
        }
        .opts-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px; }
        .add-form-row { display: flex; gap: 12px; }
        .add-form-row .add-form-group { flex: 1; }
        .ca-select {
            width: 100%; padding: 9px 12px;
            border: 1.5px solid var(--border); border-radius: 8px;
            font-size: 13px; background: var(--surface-3); cursor: pointer;
            font-family: 'DM Sans', sans-serif; color: var(--text-1);
        }
        .ca-select:focus { outline: none; border-color: var(--emerald); }

        .fields-mcq, .fields-tf, .fields-short { display: none; }
        .fields-mcq.show, .fields-tf.show, .fields-short.show { display: block; }

        /* ── UPLOAD ── */
        .upload-area {
            border: 2px dashed rgba(124,58,237,0.25); border-radius: var(--radius-lg);
            padding: 40px 20px; text-align: center; cursor: pointer;
            transition: var(--transition); background: var(--surface-2);
            margin-bottom: 18px;
        }
        .upload-area:hover, .upload-area.drag { border-color: var(--violet); background: var(--violet-dim); }
        .upload-icon { font-size: 44px; margin-bottom: 12px; }
        .upload-title { font-size: 17px; font-weight: 700; color: var(--text-1); margin-bottom: 6px; font-family: 'Syne', sans-serif; }
        .upload-sub   { font-size: 13px; color: var(--text-3); }
        .upload-input { display: none; }
        .upload-result {
            background: var(--surface-2); border-radius: var(--radius); padding: 14px 18px;
            margin-bottom: 14px; font-size: 13px; color: var(--text-1); display: none;
            border: 1px solid var(--border);
        }
        .upload-result.show { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .upload-result-info { display: flex; flex-direction: column; gap: 3px; }
        .upload-result-name { font-weight: 700; color: var(--text-1); }
        .upload-result-count { color: var(--text-3); }
        .btn-import {
            padding: 10px 22px;
            background: linear-gradient(135deg, var(--violet), var(--orchid));
            color: white; border: none; border-radius: var(--radius);
            font-weight: 700; font-size: 14px; cursor: pointer; transition: var(--transition);
        }
        .btn-import:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(124,58,237,0.35); }
        .btn-import:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

        /* ── BUTTONS ── */
        .btn-sm {
            padding: 6px 13px; border: none; border-radius: 7px;
            font-size: 12px; font-weight: 600; cursor: pointer; transition: var(--transition);
        }
        .btn-edit-q    { background: var(--surface-2); color: var(--text-2); border: 1px solid var(--border); }
        .btn-edit-q:hover { background: var(--violet-dim); color: var(--violet); border-color: var(--violet); }
        .btn-delete-q  { background: rgba(244,63,94,0.1); color: var(--rose); border: 1px solid rgba(244,63,94,0.2); }
        .btn-delete-q:hover { background: var(--rose); color: white; }
        .btn-add-q {
            padding: 10px 20px;
            background: linear-gradient(135deg, var(--violet), var(--orchid));
            color: white; border: none; border-radius: var(--radius);
            font-weight: 700; font-size: 13px; cursor: pointer; transition: var(--transition);
            display: flex; align-items: center; gap: 7px;
        }
        .btn-add-q:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(124,58,237,0.35); }
        .btn-add-q:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

        /* ── ACTION BAR ── */
        .action-bar {
            background: var(--surface-3); border-radius: var(--radius-lg); padding: 18px 24px;
            box-shadow: var(--shadow-sm); margin-bottom: 30px;
            border: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center; gap: 16px;
            flex-wrap: wrap;
        }
        .action-bar-info { font-size: 13px; color: var(--text-3); }
        .action-bar-btns { display: flex; gap: 12px; flex-wrap: wrap; }
        .btn-save-draft {
            padding: 10px 22px; background: var(--surface-2);
            color: var(--text-2); border: 1.5px solid var(--border);
            border-radius: var(--radius); font-weight: 700; font-size: 14px;
            cursor: pointer; transition: var(--transition);
        }
        .btn-save-draft:hover { border-color: var(--violet); color: var(--violet); }
        .btn-save-draft:disabled { opacity: 0.6; cursor: not-allowed; }
        .btn-publish {
            padding: 10px 26px;
            background: linear-gradient(135deg, var(--violet), var(--orchid));
            color: white; border: none; border-radius: var(--radius);
            font-weight: 700; font-size: 14px; cursor: pointer; transition: var(--transition);
        }
        .btn-publish:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(124,58,237,0.4); }
        .btn-publish:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

        /* ── INLINE EDIT ── */
        .q-edit-form { display: none; padding: 16px 18px; border-top: 1px solid var(--border); background: var(--surface-2); }
        .q-edit-form.active { display: block; }
        .q-edit-row { display: flex; gap: 10px; }
        .q-edit-row .add-form-group { flex: 1; }
        .btn-save-q {
            background: linear-gradient(135deg, var(--emerald), #059669); color: white;
            border: none; border-radius: 7px; padding: 8px 16px;
            font-size: 12px; font-weight: 600; cursor: pointer; transition: var(--transition);
        }
        .btn-save-q:hover { transform: translateY(-1px); }
        .btn-cancel-q {
            background: var(--surface-3); color: var(--text-3);
            border: 1.5px solid var(--border); border-radius: 7px;
            padding: 6px 14px; font-size: 12px; font-weight: 600; cursor: pointer;
            transition: var(--transition);
        }
        .btn-cancel-q:hover { border-color: var(--rose); color: var(--rose); }

        /* ── TOAST / LOADING ── */
        .toast {
            position: fixed; bottom: 30px; left: 50%;
            transform: translateX(-50%) translateY(80px);
            background: var(--ink-2); color: white;
            padding: 12px 24px; border-radius: var(--radius);
            font-size: 14px; font-weight: 600; box-shadow: var(--shadow-vl);
            z-index: 9999; transition: transform 0.3s, opacity 0.3s;
            opacity: 0; pointer-events: none; border: 1px solid rgba(124,58,237,0.2);
        }
        .toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
        .toast.success { background: #064e3b; border-color: rgba(16,185,129,0.3); }
        .toast.error   { background: #7f1d1d; border-color: rgba(244,63,94,0.3); }
        .loading-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(247,245,251,0.9); z-index: 3000;
            align-items: center; justify-content: center;
        }
        .loading-overlay.active { display: flex; }
        .spinner {
            width: 44px; height: 44px;
            border: 4px solid var(--border);
            border-top-color: var(--violet);
            border-radius: 50%; animation: spin 0.8s linear infinite;
            margin: 0 auto 14px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .loading-text { font-size: 15px; font-weight: 600; color: var(--text-2); text-align: center; }

        @media (max-width: 768px) {
            .container { padding: 16px; }
            .steps-bar { display: none; }
            .form-grid { grid-template-columns: 1fr; }
            .opts-grid  { grid-template-columns: 1fr; }
            .action-bar { flex-direction: column; }
            .action-bar-btns { width: 100%; flex-direction: column; }
            .btn-save-draft, .btn-publish { width: 100%; }
        }
    </style>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        .page-wrapper { display:flex; min-height:calc(100vh - var(--nav-h)); }
        .left-sidebar {
            width: var(--sidebar-w); flex-shrink:0; padding:20px 12px;
            display:flex; flex-direction:column; gap:2px;
            background: rgba(255,255,255,0.6);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            position:fixed; top:var(--nav-h); left:0;
            height:calc(100vh - var(--nav-h)); z-index:100;
            border-right: 1px solid var(--border);
        }
        .left-sidebar-label {
            font-size:10px; font-weight:700; text-transform:uppercase;
            letter-spacing:.1em; color:var(--text-3); padding:12px 12px 6px;
        }
        .left-sidebar a {
            display:flex; align-items:center; gap:10px; padding:10px 12px;
            border-radius:10px; text-decoration:none; font-size:14px;
            font-weight:500; color:var(--text-2); transition:background .15s, color .15s;
        }
        .left-sidebar a:hover { background:var(--violet-dim); color:var(--violet); }
        .left-sidebar a.active { background:var(--violet-dim); color:var(--violet); font-weight:600; }
        .left-sidebar a i { width:18px; text-align:center; font-size:14px; }
        .left-sidebar-bottom { margin-top:auto; padding-top:12px; border-top:1px solid var(--border); }
        .left-sidebar-bottom button {
            display:flex; align-items:center; gap:10px; padding:10px 12px;
            border-radius:10px; font-size:14px; font-weight:500; color:var(--rose);
            background:none; border:none; cursor:pointer; width:100%;
            transition:background .15s; font-family:'DM Sans',sans-serif;
        }
        .left-sidebar-bottom button:hover { background:rgba(244,63,94,0.08); }
        .left-sidebar-bottom button i { width:18px; text-align:center; font-size:14px; }
        .page-content { flex:1; min-width:0; margin-left:var(--sidebar-w); }

/* ── Report status dot ── */
.report-dot-wrap {
    display: flex; align-items: center; gap: 7px;
    padding: 6px 12px;
    background: rgba(255,255,255,.1);
    border: 1.5px solid rgba(255,255,255,.15);
    border-radius: var(--r-sm);
    cursor: pointer; transition: var(--t);
    font-size: 11px; font-weight: 600; color: rgba(255,255,255,.85);
    margin-right: 8px;
}
.report-dot-wrap:hover { background: rgba(255,255,255,.2); }
.report-status-dot {
    width: 10px; height: 10px; border-radius: 50%;
    background: #f43f5e;
    border: 2px solid rgba(255,255,255,.4);
    display: inline-block; flex-shrink: 0;
    animation: reportPulse 2s ease-in-out infinite;
}
.report-status-dot.resolved { background: var(--emerald); animation: none; }
@keyframes reportPulse {
    0%,100% { box-shadow: 0 0 0 0 rgba(244,63,94,.5); }
    60%      { box-shadow: 0 0 0 6px rgba(244,63,94,0); }
}
/* ── Report Modal ── */
.report-modal-overlay {
    position: fixed; inset: 0;
    background: rgba(13,10,20,.6);
    z-index: 9100;
    display: flex; align-items: center; justify-content: center;
    backdrop-filter: blur(6px);
    opacity: 0; visibility: hidden;
    transition: opacity .25s, visibility .25s;
}
.report-modal-overlay.open { opacity: 1; visibility: visible; }
.report-modal {
    background: var(--surface-3, #fff);
    border-radius: var(--r-xl, 20px);
    width: 100%; max-width: 500px;
    margin: 16px;
    box-shadow: 0 20px 60px rgba(13,10,20,.18), 0 0 0 1px rgba(124,58,237,0.18);
    overflow: hidden;
    transform: translateY(20px) scale(.97);
    transition: transform .28s cubic-bezier(0.22,1,0.36,1);
}
.report-modal-overlay.open .report-modal { transform: translateY(0) scale(1); }
.report-modal-header {
    background: linear-gradient(135deg, #4c1d95, #6d28d9);
    padding: 22px 24px 18px;
    display: flex; align-items: flex-start; justify-content: space-between;
}
.report-modal-title {
    font-family: var(--font-head, 'Syne', sans-serif); font-size: 17px; font-weight: 700; color: #fff;
    margin-bottom: 4px;
}
.report-modal-sub { font-size: 12px; color: rgba(255,255,255,.6); }
.report-modal-close {
    background: rgba(255,255,255,.15); border: none; border-radius: var(--r-sm, 8px);
    color: #fff; width: 30px; height: 30px; font-size: 15px;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; transition: background .15s; margin-left: 12px;
}
.report-modal-close:hover { background: rgba(255,255,255,.28); }
.report-modal-body { padding: 24px; display: flex; flex-direction: column; gap: 16px; }
.report-field label {
    display: block; font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .06em; color: var(--text-3, #8b7fa8); margin-bottom: 6px;
}
.report-field label span { color: #f43f5e; margin-left: 2px; }
.report-field input, .report-field textarea {
    width: 100%; padding: 11px 14px;
    border: 1.5px solid rgba(124,58,237,0.18); border-radius: var(--r-md, 14px);
    font-family: var(--font-body, 'DM Sans', sans-serif); font-size: 13.5px; color: var(--text-1, #1a1425);
    background: var(--surface, #f7f5fb);
    outline: none; transition: border-color .15s, box-shadow .15s;
    resize: vertical;
}
.report-field input:focus, .report-field textarea:focus {
    border-color: #7c3aed;
    box-shadow: 0 0 0 3px rgba(124,58,237,0.12);
}
.report-drop-zone {
    border: 2px dashed rgba(124,58,237,0.25); border-radius: var(--r-md, 14px);
    padding: 20px; text-align: center;
    cursor: pointer; background: var(--surface, #f7f5fb);
    transition: border-color .2s, background .2s;
}
.report-drop-zone:hover, .report-drop-zone.dragover {
    border-color: #7c3aed; background: rgba(124,58,237,0.06);
}
.report-drop-zone .dz-icon { font-size: 28px; margin-bottom: 6px; }
.report-drop-zone .dz-text { font-size: 13.5px; font-weight: 600; color: var(--text-2, #4b4565); }
.report-drop-zone .dz-sub  { font-size: 12px; color: var(--text-3, #8b7fa8); margin-top: 3px; }
.report-img-preview {
    max-width: 100%; max-height: 140px; border-radius: var(--r-sm, 8px);
    object-fit: contain; display: none; margin: 8px auto 0;
}
.report-modal-footer { padding: 0 24px 22px; display: flex; gap: 10px; }
.btn-report-cancel {
    flex: 1; padding: 11px; border-radius: var(--r-md, 14px);
    border: 1.5px solid rgba(124,58,237,0.18); background: var(--surface, #f7f5fb);
    color: var(--text-2, #4b4565); font-size: 13.5px; font-weight: 600;
    cursor: pointer; font-family: var(--font-body, 'DM Sans', sans-serif); transition: .2s;
}
.btn-report-cancel:hover { background: var(--surface-2, #ede9f6); }
.btn-report-submit {
    flex: 1; padding: 11px; border-radius: var(--r-md, 14px); border: none;
    background: linear-gradient(135deg, #7c3aed, #9f67f5);
    color: #fff; font-size: 13.5px; font-weight: 700;
    cursor: pointer; font-family: var(--font-body, 'DM Sans', sans-serif); transition: .2s;
    box-shadow: 0 2px 12px rgba(124,58,237,0.25);
}
.btn-report-submit:hover { opacity: .9; transform: translateY(-1px); }
.report-success-banner {
    background: #d1fae5; border: 1px solid #a7f3d0; border-radius: var(--r-md, 14px);
    padding: 12px 16px; font-size: 13px; font-weight: 600; color: #065f46;
    display: flex; align-items: center; gap: 8px; margin: 0 24px 4px;
}

    </style>
</head>
<body>

<nav class="navbar">
    <a href="teacher-dashboard.php" class="navbar-brand" style="flex-shrink:0;">
        <img src="prepaura-logo.png" alt="PREPAURA Logo" class="brand-logo-img">
        <div class="brand-text-group">
            <span class="brand-name">PREPAURA</span>
            <span class="brand-tagline">Placement Training Platform</span>
        </div>
    </a>
    <div style="display:flex;align-items:center;gap:12px;flex-shrink:0;position:relative;">
        <button class="nav-profile-btn" id="profileBtn">
            <div class="nav-avatar">
                <?php if (!empty($userPicture)): ?>
                    <img src="<?= htmlspecialchars($userPicture) ?>" alt="Profile" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                <?php else: ?>
                    <?= $userInitials ?>
                <?php endif; ?>
            </div>
            <span style="font-weight:600;font-size:14px;"><?= $userName ?></span>
            <i class="fa fa-chevron-down" style="font-size:10px;opacity:.6;"></i>
        </button>
        <div class="profile-dropdown" id="profileDropdown">
            <div class="dropdown-header">
                <div style="display:flex;flex-direction:column;align-items:flex-start;gap:8px;">
                    <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,var(--violet),var(--orchid));display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:16px;flex-shrink:0;overflow:hidden;">
                        <?php if (!empty($userPicture)): ?>
                            <img src="<?= htmlspecialchars($userPicture) ?>" alt="Profile" style="width:100%;height:100%;object-fit:cover;">
                        <?php else: ?>
                            <?= $userInitials ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="dropdown-name"><?= $userName ?></div>
                        <div class="dropdown-email"><?= $userEmail ?></div>
                    </div>
                </div>
            </div>
            <div class="dropdown-menu">
                <a href="teacher-profile.php" class="dropdown-item"><i class="fa fa-user" style="width:16px;"></i> My Profile</a>
                <a href="#" onclick="event.preventDefault(); document.getElementById('profileDropdown').classList.remove('open'); openReportModal();" class="dropdown-item"><i class="fa fa-circle-question" style="width:16px;"></i> Help &amp; Support</a>
                <div class="dropdown-divider"></div>
                <button onclick="handleLogout()" class="dropdown-item danger"><i class="fa fa-right-from-bracket" style="width:16px;"></i> Logout</button>
            </div>
        </div>
    </div>
</nav>

<div class="page-wrapper">
    <aside class="left-sidebar">
        <span class="left-sidebar-label">Navigation</span>
        <a href="teacher-dashboard.php"><i class="fa fa-home"></i> Dashboard</a>
        <a href="teacher-assessments.php" class="active"><i class="fa fa-clipboard-list"></i> Assessments</a>
        <a href="manage-groups.php"><i class="fa fa-users"></i> Manage Groups</a>
        <a href="teacher-resources.php"><i class="fa fa-folder-open"></i> Resources</a>
        <div class="left-sidebar-bottom">
            <button onclick="handleLogout()"><i class="fa fa-sign-out-alt"></i> Logout</button>
        </div>
    </aside>
    <div class="page-content">
<div class="container">

    <div class="page-header">
        <div class="page-header-inner">
            <div class="page-header-label"><?= $editMode ? 'Edit Draft' : 'Create' ?></div>
            <h1 class="page-title"><?= $editMode ? 'Continue Editing Draft' : 'Create New Assessment' ?></h1>
            <p class="page-subtitle">
                <?= $editMode
                    ? 'Pick up where you left off — all saved data is loaded below.'
                    : 'Fill in the details, add questions, then publish or save as draft.' ?>
            </p>
            <?php if ($editMode): ?>
                <div class="draft-badge">📝 Draft #<?= $assessmentId ?> · <?= count($questions) ?> question<?= count($questions) !== 1 ? 's' : '' ?> saved</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="steps-bar">
        <div class="step active" id="tab1" onclick="switchTab(1)"><span class="step-num">1</span>Basic Info</div>
        <div class="step"        id="tab2" onclick="switchTab(2)"><span class="step-num">2</span>Settings</div>
        <div class="step"        id="tab3" onclick="switchTab(3)"><span class="step-num">3</span>Questions</div>
        <div class="step"        id="tab4" onclick="switchTab(4)"><span class="step-num">4</span>Upload Doc</div>
    </div>

    <!-- ── PANEL 1: Basic Info ── -->
    <div class="panel active" id="panel1">
        <h2 class="panel-title"><div class="panel-icon">📝</div> Basic Information</h2>

        <div class="form-group">
            <label class="form-label">Title <span class="req">*</span></label>
            <input type="text" class="form-input" id="title"
                   placeholder="e.g. Quantitative Aptitude - Set 1" maxlength="200"
                   value="<?= val($assessment, 'title') ?>">
        </div>

        <div class="form-group">
            <label class="form-label">Description</label>
            <textarea class="form-input form-textarea" id="description"
                      placeholder="Brief overview..."><?= val($assessment, 'description') ?></textarea>
        </div>

        <div class="form-grid">
            <div class="form-group">
                <label class="form-label">Category <span class="req">*</span></label>
                <select class="form-select" id="category">
                    <option value="">Select category</option>
                    <option value="aptitude"  <?= sel($assessment, 'category', 'aptitude')  ?>>Aptitude</option>
                    <option value="technical" <?= sel($assessment, 'category', 'technical') ?>>Technical</option>
                    <option value="coding"    <?= sel($assessment, 'category', 'coding')    ?>>Coding</option>
                    <option value="reasoning" <?= sel($assessment, 'category', 'reasoning') ?>>Reasoning</option>
                    <option value="english"   <?= sel($assessment, 'category', 'english')   ?>>English</option>
                    <option value="general"   <?= sel($assessment, 'category', 'general')   ?>>General</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Difficulty <span class="req">*</span></label>
                <select class="form-select" id="difficulty">
                    <option value="">Select difficulty</option>
                    <option value="easy"   <?= sel($assessment, 'difficulty', 'easy',   'medium') ?>>Easy</option>
                    <option value="medium" <?= sel($assessment, 'difficulty', 'medium', 'medium') ?>>Medium</option>
                    <option value="hard"   <?= sel($assessment, 'difficulty', 'hard',   'medium') ?>>Hard</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Duration (minutes) <span class="req">*</span></label>
                <input type="number" class="form-input" id="duration"
                       min="1" max="480" placeholder="e.g. 60"
                       value="<?= val($assessment, 'duration_minutes') ?>">
            </div>

            <div class="form-group">
                <label class="form-label">Total Marks <span class="req">*</span></label>
                <input type="number" class="form-input" id="totalMarks"
                       min="1" placeholder="e.g. 100"
                       value="<?= val($assessment, 'total_marks') ?>">
            </div>

            <div class="form-group">
                <label class="form-label">Passing Marks <span class="req">*</span></label>
                <input type="number" class="form-input" id="passingMarks"
                       min="0" placeholder="e.g. 40"
                       value="<?= val($assessment, 'passing_marks') ?>">
            </div>

            <div class="form-group">
                <label class="form-label">Max Attempts</label>
                <input type="number" class="form-input" id="maxAttempts"
                       min="1" value="<?= val($assessment, 'max_attempts', '1') ?>">
            </div>
        </div>

        <div class="action-bar" style="margin-top:10px;">
            <div class="action-bar-info">Step 1 of 4</div>
            <div class="action-bar-btns">
                <button class="btn-save-draft" onclick="saveDraft()">💾 Save Draft</button>
                <button class="btn-publish"    onclick="switchTab(2)">Next: Settings →</button>
            </div>
        </div>
    </div>

    <!-- ── PANEL 2: Settings ── -->
    <div class="panel" id="panel2">
        <h2 class="panel-title"><div class="panel-icon">⚙️</div> Settings</h2>

        <div class="form-grid">
            <div class="form-group">
                <label class="form-label">Start Time</label>
                <input type="datetime-local" class="form-input" id="startTime"
                       value="<?= toDatetimeLocal($assessment['start_time'] ?? null) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">End Time</label>
                <input type="datetime-local" class="form-input" id="endTime"
                       value="<?= toDatetimeLocal($assessment['end_time'] ?? null) ?>">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Options</label>
            <div class="checkbox-grid">
                <label class="checkbox-label">
                    <input type="checkbox" id="randQ" <?= checked($assessment, 'randomize_questions') ?>>
                    Randomize questions
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" id="randO" <?= checked($assessment, 'randomize_options') ?>>
                    Randomize options
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" id="isPublic"
                           <?= ($assessment['visibility'] ?? '') === 'public' ? 'checked' : '' ?>
                           onchange="toggleGroupPicker()">
                    Public (allow guest access)
                </label>
            </div>
        </div>

        <!-- Group / Student Targeting (shown when not public) -->
        <div class="form-group" id="targetingBlock" style="<?= ($assessment['visibility'] ?? 'public') === 'public' ? 'display:none' : '' ?>">
            <label class="form-label">Restrict Access To</label>
            <p style="font-size:13px;color:var(--text-3);margin:0 0 10px;">
                Choose groups or individual students. Leave both empty to block all access.
            </p>

            <!-- Tabs -->
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
                <!-- Selected students chips -->
                <div id="selectedStudentsChips" style="display:flex;flex-wrap:wrap;gap:6px;min-height:32px;">
                    <?php foreach ($existingTargets as $t): ?>
                    <?php if ($t['type'] === 'student'): ?>
                    <?php
                        // Load student name for pre-selected students
                        $rs = safePreparedQuery($conn,
                            "SELECT user_id, full_name, email FROM users WHERE user_id = ?",
                            "i", [$t['id']]);
                        $su = null;
                        if ($rs['success'] && $rs['result']) {
                            $su = $rs['result']->fetch_assoc();
                            $rs['result']->free();
                        }
                    ?>
                    <?php if ($su): ?>
                    <span class="student-chip" data-id="<?= $su['user_id'] ?>">
                        <?= htmlspecialchars($su['full_name']) ?>
                        <button type="button" onclick="removeStudentChip(<?= $su['user_id'] ?>)">&times;</button>
                    </span>
                    <input type="hidden" class="student-pick-hidden" name="student_target" value="<?= $su['user_id'] ?>">
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="action-bar" style="margin-top:10px;">
            <div class="action-bar-info">Step 2 of 4</div>
            <div class="action-bar-btns">
                <button class="btn-save-draft" onclick="saveDraft()">💾 Save Draft</button>
                <button class="btn-save-draft" onclick="switchTab(1)">← Back</button>
                <button class="btn-publish"    onclick="switchTab(3)">Next: Questions →</button>
            </div>
        </div>
    </div>

    <!-- ── PANEL 3: Questions ── -->
    <div class="panel" id="panel3">
        <h2 class="panel-title"><div class="panel-icon">❓</div> Questions</h2>

        <div class="q-header">
            <span class="q-count" id="qCount">
                <?= count($questions) ?> question<?= count($questions) !== 1 ? 's' : '' ?> saved
            </span>
        </div>

        <div class="q-list" id="qList">
            <?php if (empty($questions)): ?>
                <div class="no-questions" id="noQMsg">
                    <div class="no-q-icon">❓</div>
                    <div>No questions yet. Add one below or upload a document.</div>
                </div>
            <?php else: ?>
                <?php
                $letters = ['A','B','C','D','E'];
                foreach ($questions as $i => $q):
                    $qid   = (int)$q['question_id'];
                    $qtype = $q['question_type'];
                    $isMCQ = in_array($qtype, ['mcq','true_false','multiple_select']);
                    $opts  = $q['options'];
                ?>
                <div class="q-card" data-qid="<?= $qid ?>">
                    <div class="q-card-header">
                        <div class="q-num-row">
                            <span class="q-num">Q<?= $i + 1 ?></span>
                            <span class="q-type-badge"><?= htmlspecialchars(str_replace('_',' ', $qtype)) ?></span>
                            <span class="q-marks-badge"><?= (int)$q['marks'] ?>m</span>
                        </div>
                        <div class="q-card-actions">
                            <button class="btn-sm btn-edit-q" onclick="toggleEditCard(<?= $qid ?>)">✏️ Edit</button>
                            <button class="btn-sm btn-delete-q" onclick="deleteExistingQ(<?= $qid ?>)">🗑️</button>
                        </div>
                    </div>
                    <div class="q-card-body">
                        <div class="q-text"><?= htmlspecialchars($q['question_text']) ?></div>
                        <?php if ($isMCQ): ?>
                            <div class="q-options">
                                <?php foreach ($opts as $oi => $opt): ?>
                                <div class="q-option <?= $opt['is_correct'] ? 'correct' : '' ?>">
                                    <span class="q-opt-label"><?= $letters[$oi] ?? ($oi+1) ?>)</span>
                                    <span><?= htmlspecialchars($opt['option_text']) ?></span>
                                    <?php if ($opt['is_correct']): ?><span class="q-correct-badge">✓</span><?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <?php
                            $correctOpt  = array_filter($opts, fn($o) => $o['is_correct']);
                            $correctText = $correctOpt ? reset($correctOpt)['option_text'] : '—';
                            ?>
                            <div class="q-ans-label">Expected Answer:</div>
                            <div class="q-short-ans"><?= htmlspecialchars($correctText) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Inline edit form -->
                    <div class="q-edit-form" id="editForm<?= $qid ?>">
                        <script>
                        window.existingOpts = window.existingOpts || {};
                        window.existingOpts[<?= $qid ?>] = <?= json_encode(array_map(fn($o) => [
                            'option_id'   => (int)$o['option_id'],
                            'option_text' => $o['option_text'],
                            'is_correct'  => (bool)$o['is_correct'],
                        ], $opts)) ?>;
                        </script>

                        <div class="add-form-group">
                            <label class="add-form-label">Question Text</label>
                            <textarea class="add-form-input" id="eqt<?= $qid ?>" rows="2"><?= htmlspecialchars($q['question_text']) ?></textarea>
                        </div>

                        <?php if ($isMCQ && $qtype !== 'true_false'): ?>
                        <div class="opts-grid">
                            <?php foreach ($opts as $oi => $opt): ?>
                            <div class="add-form-group">
                                <label class="add-form-label">
                                    Option <?= $letters[$oi] ?? ($oi+1) ?>
                                    <input type="checkbox"
                                           id="eIsCorrect<?= $qid ?>_<?= $oi ?>"
                                           <?= $opt['is_correct'] ? 'checked' : '' ?>
                                           style="margin-left:5px;accent-color:var(--color-success);"> Correct
                                </label>
                                <input type="text" class="add-form-input"
                                       id="eOpt<?= $qid ?>_<?= $oi ?>"
                                       value="<?= htmlspecialchars($opt['option_text']) ?>"
                                       data-optid="<?= (int)$opt['option_id'] ?>">
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php elseif ($qtype === 'true_false'): ?>
                        <div class="add-form-group">
                            <label class="add-form-label">Correct Answer</label>
                            <select class="ca-select" id="eTfCorrect<?= $qid ?>">
                                <?php foreach ($opts as $opt): ?>
                                <option value="<?= (int)$opt['option_id'] ?>" <?= $opt['is_correct'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($opt['option_text']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php else: ?>
                        <?php
                        $cOpt   = array_filter($opts, fn($o) => $o['is_correct']);
                        $cText  = $cOpt ? reset($cOpt)['option_text'] : '';
                        $cOptId = $cOpt ? (int)reset($cOpt)['option_id'] : 0;
                        ?>
                        <input type="hidden" id="eShortOptId<?= $qid ?>" value="<?= $cOptId ?>">
                        <div class="add-form-group">
                            <label class="add-form-label">Expected Answer</label>
                            <textarea class="add-form-input" id="eCa<?= $qid ?>" rows="2"><?= htmlspecialchars($cText) ?></textarea>
                        </div>
                        <?php endif; ?>

                        <div class="add-form-row">
                            <div class="add-form-group">
                                <label class="add-form-label">Marks</label>
                                <input type="number" class="add-form-input" id="emk<?= $qid ?>" min="1" value="<?= (int)$q['marks'] ?>">
                            </div>
                        </div>
                        <div class="add-form-group">
                            <label class="add-form-label">Explanation (optional)</label>
                            <textarea class="add-form-input" id="eex<?= $qid ?>" rows="2"><?= htmlspecialchars($q['explanation'] ?? '') ?></textarea>
                        </div>
                        <div style="display:flex;gap:8px;margin-top:10px;">
                            <button class="btn-save-q"   onclick="saveExistingQ(<?= $qid ?>, '<?= $qtype ?>')">💾 Save</button>
                            <button class="btn-cancel-q" onclick="toggleEditCard(<?= $qid ?>)">Cancel</button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Add new question form -->
        <div class="add-q-section" style="margin-top:20px;">
            <div class="add-q-header">
                <h3 style="font-size:16px;font-weight:700;color:var(--text-1);font-family:'Syne',sans-serif;">Add a Question</h3>
            </div>

            <div class="type-tabs">
                <button class="type-tab active" data-type="mcq"            onclick="switchQType('mcq',this)">MCQ</button>
                <button class="type-tab"        data-type="true_false"     onclick="switchQType('true_false',this)">True/False</button>
                <button class="type-tab"        data-type="short_answer"   onclick="switchQType('short_answer',this)">Short Answer</button>
                <button class="type-tab"        data-type="fill_blank"     onclick="switchQType('fill_blank',this)">Fill Blank</button>
                <button class="type-tab"        data-type="multiple_select" onclick="switchQType('multiple_select',this)">Multi-Select</button>
            </div>
            <input type="hidden" id="newQType" value="mcq">

            <div class="add-form-group">
                <label class="add-form-label">Question Text <span style="color:var(--color-error)">*</span></label>
                <textarea class="add-form-input" id="newQt" rows="3" placeholder="Enter question text..."></textarea>
            </div>

            <!-- MCQ / Multi-Select -->
            <div class="fields-mcq show" id="fMcq">
                <div class="opts-grid">
                    <div class="add-form-group">
                        <label class="add-form-label">Option A <span style="color:var(--color-error)">*</span></label>
                        <input type="text" class="add-form-input" id="newOa" placeholder="Option A">
                    </div>
                    <div class="add-form-group">
                        <label class="add-form-label">Option B <span style="color:var(--color-error)">*</span></label>
                        <input type="text" class="add-form-input" id="newOb" placeholder="Option B">
                    </div>
                    <div class="add-form-group">
                        <label class="add-form-label">Option C</label>
                        <input type="text" class="add-form-input" id="newOc" placeholder="Option C">
                    </div>
                    <div class="add-form-group">
                        <label class="add-form-label">Option D</label>
                        <input type="text" class="add-form-input" id="newOd" placeholder="Option D">
                    </div>
                </div>
                <!-- MCQ: single select -->
                <div class="add-form-group" id="mcqCaWrap">
                    <label class="add-form-label">Correct Answer <span style="color:var(--color-error)">*</span></label>
                    <select class="ca-select" id="newCaMcq">
                        <option value="">Select</option>
                        <option value="A">A</option><option value="B">B</option>
                        <option value="C">C</option><option value="D">D</option>
                    </select>
                </div>
                <!-- Multi-select: checkboxes -->
                <div class="add-form-group" id="msCaWrap" style="display:none;">
                    <label class="add-form-label">Correct Options <span style="color:var(--color-error)">*</span></label>
                    <div style="display:flex;gap:14px;flex-wrap:wrap;">
                        <label style="display:flex;align-items:center;gap:5px;font-size:13px;"><input type="checkbox" id="msA" style="accent-color:var(--color-success);"> A</label>
                        <label style="display:flex;align-items:center;gap:5px;font-size:13px;"><input type="checkbox" id="msB" style="accent-color:var(--color-success);"> B</label>
                        <label style="display:flex;align-items:center;gap:5px;font-size:13px;"><input type="checkbox" id="msC" style="accent-color:var(--color-success);"> C</label>
                        <label style="display:flex;align-items:center;gap:5px;font-size:13px;"><input type="checkbox" id="msD" style="accent-color:var(--color-success);"> D</label>
                    </div>
                </div>
            </div>

            <!-- True/False -->
            <div class="fields-tf" id="fTf">
                <div class="add-form-group">
                    <label class="add-form-label">Correct Answer <span style="color:var(--color-error)">*</span></label>
                    <select class="ca-select" id="newCaTf">
                        <option value="true">True</option>
                        <option value="false">False</option>
                    </select>
                </div>
            </div>

            <!-- Short / Fill -->
            <div class="fields-short" id="fShort">
                <div class="add-form-group">
                    <label class="add-form-label">Expected Answer <span style="color:var(--color-error)">*</span></label>
                    <textarea class="add-form-input" id="newCaShort" rows="2" placeholder="Expected answer..."></textarea>
                </div>
            </div>

            <div class="add-form-row">
                <div class="add-form-group">
                    <label class="add-form-label">Marks</label>
                    <input type="number" class="add-form-input" id="newMarks" min="1" value="1">
                </div>
                <div class="add-form-group">
                    <label class="add-form-label">Negative Marks</label>
                    <input type="number" class="add-form-input" id="newNegMarks" min="0" step="0.25" value="0">
                </div>
            </div>
            <div class="add-form-group">
                <label class="add-form-label">Explanation (optional)</label>
                <textarea class="add-form-input" id="newExpl" rows="2" placeholder="Shown to students after submission..."></textarea>
            </div>

            <button class="btn-add-q" id="addQBtn" onclick="addQuestion()">➕ Add Question</button>
        </div>

        <div class="action-bar" style="margin-top:10px;">
            <div class="action-bar-info">Step 3 of 4</div>
            <div class="action-bar-btns">
                <button class="btn-save-draft" onclick="saveDraft()">💾 Save Draft</button>
                <button class="btn-save-draft" onclick="switchTab(2)">← Back</button>
                <button class="btn-publish"    onclick="switchTab(4)">Next: Upload Doc →</button>
            </div>
        </div>
    </div>

    <!-- ── PANEL 4: Upload Document ── -->
    <div class="panel" id="panel4">
        <h2 class="panel-title"><div class="panel-icon">📄</div> Import Questions from Document</h2>
        <p style="font-size:13px;color:var(--text-3);margin-bottom:20px;">
            Upload a PDF or DOCX with numbered MCQ questions. Parsed questions are added automatically.<br>
            Format: <code>1. Question text</code> followed by <code>a) Option</code> lines.
        </p>

        <div id="noDraftWarning" style="display:none;background:rgba(245,158,11,0.1);border:1.5px solid rgba(245,158,11,0.3);border-radius:10px;padding:14px 18px;margin-bottom:16px;font-size:13px;color:#92400e;">
            ⚠️ <strong>Complete Step 1 first.</strong>
            You need a title, category, difficulty, duration, and marks saved before importing questions.
            <button onclick="switchTab(1)" style="margin-left:10px;padding:4px 12px;background:var(--violet);color:white;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;">Go to Step 1 →</button>
        </div>

        <div class="upload-area" id="uploadArea" onclick="document.getElementById('fileInput').click()"
             ondragover="event.preventDefault();this.classList.add('drag')"
             ondragleave="this.classList.remove('drag')"
             ondrop="handleDrop(event)">
            <div class="upload-icon">📤</div>
            <div class="upload-title">Click to upload or drag & drop</div>
            <div class="upload-sub">PDF or DOCX · Max 10 MB</div>
        </div>
        <input type="file" id="fileInput" class="upload-input" accept=".pdf,.docx" onchange="handleFile(this.files[0])">

        <div class="upload-result" id="uploadResult">
            <div class="upload-result-info">
                <span class="upload-result-name" id="uploadFileName"></span>
                <span class="upload-result-count" id="uploadQCount"></span>
            </div>
            <button class="btn-import" id="importBtn" onclick="importParsedQuestions()">⬇️ Import Questions</button>
        </div>

        <div class="action-bar" style="margin-top:20px;">
            <div class="action-bar-info">Step 4 of 4</div>
            <div class="action-bar-btns">
                <button class="btn-save-draft" onclick="saveDraft()">💾 Save Draft</button>
                <button class="btn-save-draft" onclick="switchTab(3)">← Back</button>
                <button class="btn-publish" id="publishBtn" onclick="publish()">🚀 Publish Assessment</button>
            </div>
        </div>
    </div>

</div><!-- /container -->
    </div><!-- /page-content -->
</div><!-- /page-wrapper -->

<div class="loading-overlay" id="loading">
    <div>
        <div class="spinner"></div>
        <div class="loading-text" id="loadingText">Please wait…</div>
    </div>
</div>
<div class="toast" id="toast"></div>

<script>
let assessmentId = <?= $assessmentId ?>;
let parsedQs     = [];
let currentTab   = 1;

let csrfToken = null;
async function getCsrfToken() {
    if (csrfToken) return csrfToken;
    const res  = await fetch('api/csrf-token.php', { credentials: 'same-origin' });
    const data = await res.json();
    if (!data.success) throw new Error('Could not fetch CSRF token.');
    csrfToken = data.token;
    return csrfToken;
}

function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className   = 'toast ' + type;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3200);
}
function showLoading(msg = 'Please wait…') {
    document.getElementById('loadingText').textContent = msg;
    document.getElementById('loading').classList.add('active');
}
function hideLoading() {
    document.getElementById('loading').classList.remove('active');
}

// ── Tabs ──
function switchTab(n) {
    [1,2,3,4].forEach(i => {
        document.getElementById('panel' + i).classList.toggle('active', i === n);
        document.getElementById('tab'   + i).classList.remove('active');
        if (i < n) document.getElementById('tab' + i).classList.add('done');
    });
    document.getElementById('tab' + n).classList.add('active');
    currentTab = n;
    window.scrollTo({ top: 0, behavior: 'smooth' });

    // Show warning on upload panel if no draft saved yet
    if (n === 4) {
        const warn = document.getElementById('noDraftWarning');
        if (warn) warn.style.display = assessmentId ? 'none' : 'block';
    }
}

// ── Group / Student targeting ──
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
    // Groups
    document.querySelectorAll('.group-pick-cb:checked').forEach(cb => {
        targets.push({ type: 'group', id: parseInt(cb.value) });
    });
    // Individual students
    document.querySelectorAll('.student-pick-hidden').forEach(inp => {
        targets.push({ type: 'student', id: parseInt(inp.value) });
    });
    return targets;
}

// Student search
let _studentSearchTimer = null;
function debounceStudentSearch(val) {
    clearTimeout(_studentSearchTimer);
    if (val.length < 2) {
        closeStudentResults(); return;
    }
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
                const alreadyAdded = document.querySelector(`.student-pick-hidden[value="${s.user_id}"]`);
                if (alreadyAdded) return '';
                const meta = [s.registration_number, s.department].filter(Boolean).join(' · ');
                return `<div class="student-result-item" onclick="addStudentTarget(${s.user_id}, ${JSON.stringify(s.full_name)})">
                    <div class="student-result-name">${escHtml(s.full_name)}</div>
                    <div class="student-result-meta">${escHtml(s.email)}${meta ? ' · ' + escHtml(meta) : ''}</div>
                </div>`;
            }).join('');
        }
        box.style.display = 'block';

        // Close on outside click
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
    // Avoid duplicates
    if (document.querySelector(`.student-pick-hidden[value="${userId}"]`)) {
        closeStudentResults();
        document.getElementById('studentSearchInput').value = '';
        return;
    }
    const chips = document.getElementById('selectedStudentsChips');
    const chip  = document.createElement('span');
    chip.className       = 'student-chip';
    chip.dataset.id      = userId;
    chip.innerHTML       = `${escHtml(fullName)} <button type="button" onclick="removeStudentChip(${userId})">&times;</button>`;
    const hidden         = document.createElement('input');
    hidden.type          = 'hidden';
    hidden.className     = 'student-pick-hidden';
    hidden.value         = userId;
    chips.appendChild(chip);
    chips.appendChild(hidden);
    closeStudentResults();
    document.getElementById('studentSearchInput').value = '';
}

function removeStudentChip(userId) {
    const chip   = document.querySelector(`.student-chip[data-id="${userId}"]`);
    const hidden = document.querySelector(`.student-pick-hidden[value="${userId}"]`);
    if (chip)   chip.remove();
    if (hidden) hidden.remove();
}

function escHtml(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Collect form data ──
function collectBasicData() {
    return {
        assessment_id       : assessmentId || undefined,
        title               : document.getElementById('title').value.trim(),
        description         : document.getElementById('description').value.trim(),
        category            : document.getElementById('category').value,
        difficulty          : document.getElementById('difficulty').value,
        duration_minutes    : parseInt(document.getElementById('duration').value, 10) || 0,
        total_marks         : parseInt(document.getElementById('totalMarks').value, 10) || 0,
        passing_marks       : parseInt(document.getElementById('passingMarks').value, 10) || 0,
        max_attempts        : parseInt(document.getElementById('maxAttempts').value, 10) || 1,
        start_time          : document.getElementById('startTime').value || null,
        end_time            : document.getElementById('endTime').value || null,
        randomize_questions : document.getElementById('randQ').checked ? 1 : 0,
        randomize_options   : document.getElementById('randO').checked ? 1 : 0,
        visibility          : document.getElementById('isPublic').checked ? 'public' : 'private',
        targets             : collectTargets(),
        status              : 'draft',
    };
}

function validateBasic(data) {
    if (!data.title)           { showToast('Title is required.', 'error'); switchTab(1); return false; }
    if (!data.category)        { showToast('Category is required.', 'error'); switchTab(1); return false; }
    if (!data.difficulty)      { showToast('Difficulty is required.', 'error'); switchTab(1); return false; }
    if (data.duration_minutes < 1) { showToast('Duration must be at least 1 minute.', 'error'); switchTab(1); return false; }
    if (data.total_marks < 1)  { showToast('Total marks must be at least 1.', 'error'); switchTab(1); return false; }
    if (data.passing_marks < 0 || data.passing_marks > data.total_marks) {
        showToast('Passing marks must be between 0 and total marks.', 'error'); switchTab(1); return false;
    }
    return true;
}

// ── Save Draft ──
async function saveDraft() {
    const data = collectBasicData();
    if (!validateBasic(data)) return;

    showLoading('Saving draft…');
    try {
        const token = await getCsrfToken();
        const url   = assessmentId > 0 ? 'api/assessment/update.php' : 'api/assessment/create.php';
        const res   = await fetch(url, {
            method:      'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
            body:    JSON.stringify(data),
        });
        const result = await res.json();
        if (result.success) {
            if (!assessmentId && result.assessment_id) {
                assessmentId = result.assessment_id;
                history.replaceState(null, '', 'create-assessment.php?edit=' + assessmentId);
                document.getElementById('addQBtn').disabled = false;
                document.getElementById('addQBtn').title    = '';
                const warn = document.getElementById('noDraftWarning');
                if (warn) warn.style.display = 'none';
            }
            showToast('✅ Draft saved!');
        } else {
            showToast(result.error || 'Save failed.', 'error');
        }
    } catch {
        showToast('Network error. Please try again.', 'error');
    } finally {
        hideLoading();
    }
}

// ── Publish ──
async function publish() {
    const data = collectBasicData();
    if (!validateBasic(data)) return;

    const qCount = document.querySelectorAll('.q-card').length;
    if (qCount === 0) {
        showToast('Add at least one question before publishing.', 'error');
        switchTab(3);
        return;
    }

    data.status = 'active';
    showLoading('Publishing…');
    try {
        const token = await getCsrfToken();
        const url   = assessmentId > 0 ? 'api/assessment/update.php' : 'api/assessment/create.php';
        const res   = await fetch(url, {
            method:      'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
            body:    JSON.stringify(data),
        });
        const result = await res.json();
        if (result.success) {
            showToast('🎉 Assessment published and active!');
            setTimeout(() => { window.location.href = 'teacher-dashboard.php'; }, 1200);
        } else {
            showToast(result.error || 'Publish failed.', 'error');
        }
    } catch {
        showToast('Network error. Please try again.', 'error');
    } finally {
        hideLoading();
    }
}

// ── Question Type Tabs ──
function switchQType(type, btn) {
    document.getElementById('newQType').value = type;
    document.querySelectorAll('.type-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');

    document.getElementById('fMcq').classList.remove('show');
    document.getElementById('fTf').classList.remove('show');
    document.getElementById('fShort').classList.remove('show');
    document.getElementById('mcqCaWrap').style.display = 'none';
    document.getElementById('msCaWrap').style.display  = 'none';

    if (type === 'mcq') {
        document.getElementById('fMcq').classList.add('show');
        document.getElementById('mcqCaWrap').style.display = 'block';
    } else if (type === 'multiple_select') {
        document.getElementById('fMcq').classList.add('show');
        document.getElementById('msCaWrap').style.display  = 'block';
    } else if (type === 'true_false') {
        document.getElementById('fTf').classList.add('show');
    } else {
        document.getElementById('fShort').classList.add('show');
    }
}

// ── Add Question ──
// Sends options array; API creates question_options rows.
async function addQuestion() {
    if (!assessmentId) {
        showToast('Save a draft first to get an assessment ID.', 'error');
        await saveDraft();
        if (!assessmentId) return;
    }

    const type = document.getElementById('newQType').value;
    const qt   = document.getElementById('newQt').value.trim();
    if (!qt) { showToast('Question text is required.', 'error'); return; }

    const marks    = parseInt(document.getElementById('newMarks').value, 10) || 1;
    const negMarks = parseFloat(document.getElementById('newNegMarks').value) || 0;
    const expl     = document.getElementById('newExpl').value.trim();
    let options    = [];

    if (type === 'mcq') {
        const texts = ['a','b','c','d'].map(l => document.getElementById('newO' + l).value.trim());
        const correctLetter = document.getElementById('newCaMcq').value.toUpperCase();
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
        assessment_id : assessmentId,
        question_type : type,
        question_text : qt,
        marks,
        negative_marks: negMarks,
        explanation   : expl,
        options,
    };

    const btn = document.getElementById('addQBtn');
    btn.disabled = true;
    showLoading('Adding question…');

    try {
        const token = await getCsrfToken();
        const res   = await fetch('api/assessment/add-question.php', {
            method:      'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
            body:    JSON.stringify(payload),
        });
        const result = await res.json();
        if (result.success) {
            showToast('✅ Question added!');
            setTimeout(() => location.reload(), 500);
        } else {
            showToast(result.error || 'Failed to add question.', 'error');
        }
    } catch {
        showToast('Network error. Please try again.', 'error');
    } finally {
        hideLoading();
        btn.disabled = false;
    }
}

// ── Inline Edit (saved questions) ──
function toggleEditCard(qid) {
    document.getElementById('editForm' + qid).classList.toggle('active');
}

async function saveExistingQ(qid, qtype) {
    const qt = document.getElementById('eqt' + qid).value.trim();
    if (!qt) { showToast('Question text is required.', 'error'); return; }

    const isMCQ = ['mcq','true_false','multiple_select'].includes(qtype);
    const opts  = window.existingOpts?.[qid] ?? [];
    let options = [];

    if (qtype === 'true_false') {
        const correctOptId = parseInt(document.getElementById('eTfCorrect' + qid).value, 10);
        opts.forEach(o => {
            options.push({ option_id: o.option_id, option_text: o.option_text, is_correct: o.option_id === correctOptId });
        });
    } else if (isMCQ) {
        for (let oi = 0; oi < opts.length; oi++) {
            const textEl    = document.getElementById('eOpt' + qid + '_' + oi);
            const correctEl = document.getElementById('eIsCorrect' + qid + '_' + oi);
            if (!textEl) continue;
            options.push({
                option_id  : opts[oi].option_id,
                option_text: textEl.value.trim(),
                is_correct : correctEl ? correctEl.checked : false,
            });
        }
        if (!options.some(o => o.is_correct)) { showToast('At least one correct option required.', 'error'); return; }
    } else {
        const optId = parseInt(document.getElementById('eShortOptId' + qid).value, 10) || 0;
        const text  = document.getElementById('eCa' + qid).value.trim();
        if (!text) { showToast('Expected answer is required.', 'error'); return; }
        options = [{ option_id: optId, option_text: text, is_correct: true }];
    }

    const payload = {
        question_id   : qid,
        assessment_id : assessmentId,
        question_text : qt,
        marks         : parseInt(document.getElementById('emk' + qid).value, 10) || 1,
        explanation   : (document.getElementById('eex' + qid)?.value ?? '').trim(),
        options,
    };

    showLoading('Saving…');
    try {
        const token = await getCsrfToken();
        const res   = await fetch('api/assessment/update-question.php', {
            method:      'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
            body:    JSON.stringify(payload),
        });
        const result = await res.json();
        if (result.success) {
            showToast('✅ Question saved!');
            setTimeout(() => location.reload(), 500);
        } else {
            showToast(result.error || 'Save failed.', 'error');
        }
    } catch {
        showToast('Network error.', 'error');
    } finally {
        hideLoading();
    }
}

// ── Delete Question (saved) ──
async function deleteExistingQ(qid) {
    if (!confirm('Delete this question? This cannot be undone.')) return;
    showLoading('Deleting…');
    try {
        const token = await getCsrfToken();
        const res   = await fetch('api/assessment/delete-question.php', {
            method:      'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
            body:    JSON.stringify({ question_id: qid, assessment_id: assessmentId }),
        });
        const result = await res.json();
        if (result.success) {
            document.querySelector('[data-qid="' + qid + '"]')?.remove();
            updateQCount();
            showToast('Question deleted.');
        } else {
            showToast(result.error || 'Delete failed.', 'error');
        }
    } catch {
        showToast('Network error.', 'error');
    } finally {
        hideLoading();
    }
}

function updateQCount() {
    const n = document.querySelectorAll('.q-card').length;
    document.getElementById('qCount').textContent = n + ' question' + (n !== 1 ? 's' : '') + ' saved';
    const noMsg = document.getElementById('noQMsg');
    if (noMsg) noMsg.style.display = n === 0 ? 'block' : 'none';
}

// ── Document Upload & Parse ──
function handleDrop(e) {
    e.preventDefault();
    document.getElementById('uploadArea').classList.remove('drag');
    const file = e.dataTransfer.files[0];
    if (file) handleFile(file);
}

async function handleFile(file) {
    if (!file) return;
    const ext = file.name.split('.').pop().toLowerCase();
    if (!['pdf','docx'].includes(ext)) {
        showToast('Only PDF or DOCX files are supported.', 'error');
        return;
    }
    csrfToken = null; // force fresh token — cached token may have expired during file selection
    const formData = new FormData();
    formData.append('document', file);
    showLoading('Parsing document…');
    try {
        const token = await getCsrfToken();
        const res   = await fetch('api/assessment/parse-document.php', {
            method:      'POST',
            credentials: 'same-origin',
            headers:     { 'X-CSRF-Token': token },
            body:        formData,
        });
        const result = await res.json();
        if (result.success && result.questions?.length > 0) {
            parsedQs = result.questions;
            document.getElementById('uploadFileName').textContent = file.name;
            document.getElementById('uploadQCount').textContent   =
                result.count + ' question' + (result.count !== 1 ? 's' : '') + ' found';
            document.getElementById('uploadResult').classList.add('show');
            showToast('✅ Parsed ' + result.count + ' question' + (result.count !== 1 ? 's' : '') + '!');
        } else {
            showToast(result.error || 'No questions found in document.', 'error');
        }
    } catch (err) {
        console.error('handleFile error:', err);
        showToast('Parse failed. Please try again.', 'error');
    } finally {
        hideLoading();
    }
}

async function importParsedQuestions() {
    if (!parsedQs.length) return;

    if (!assessmentId) {
        showToast('Complete Step 1 (Basic Info) and save a draft first.', 'error');
        switchTab(1);
        return;
    }

    const btn = document.getElementById('importBtn');
    btn.disabled = true;
    showLoading('Importing ' + parsedQs.length + ' questions…');

    let imported = 0;
    const token  = await getCsrfToken();

    async function addOne(payload) {
        for (let attempt = 0; attempt < 3; attempt++) {
            if (attempt > 0) await new Promise(r => setTimeout(r, 150 * attempt));
            try {
                const res = await fetch('api/assessment/add-question.php', {
                    method:      'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
                    body:    JSON.stringify(payload),
                });
                const r = await res.json();
                if (r.success) return true;
                if (res.status !== 409) return false;
            } catch { return false; }
        }
        return false;
    }

    for (const q of parsedQs) {
        let options = [];

        if (q.type === 'true_false') {
            const isTrue = q.correctAnswer !== 'false';
            options = [
                { option_text: 'True',  is_correct: isTrue,  option_order: 1 },
                { option_text: 'False', is_correct: !isTrue, option_order: 2 },
            ];
        } else {
            // parser returns options array + correctAnswer as letter index
            const correctLetter = (q.correctAnswer ?? 'A').toUpperCase();
            const letters = ['A','B','C','D'];
            (q.options || []).forEach((text, i) => {
                if (!text) return;
                options.push({
                    option_text: text,
                    is_correct : letters[i] === correctLetter,
                    option_order: i + 1,
                });
            });
            if (!options.some(o => o.is_correct) && options.length > 0) {
                options[0].is_correct = true; // fallback
            }
        }

        const payload = {
            assessment_id : assessmentId,
            question_type : q.type === 'true_false' ? 'true_false' : 'mcq',
            question_text : q.text,
            marks         : 1,
            negative_marks: 0,
            explanation   : '',
            options,
        };

        if (await addOne(payload)) imported++;
        await new Promise(r => setTimeout(r, 80));
    }

    hideLoading();

    if (imported > 0) {
        showToast('✅ Imported ' + imported + ' of ' + parsedQs.length + ' questions!');
        parsedQs = [];
        document.getElementById('uploadResult').classList.remove('show');
        setTimeout(() => location.reload(), 800);
    } else {
        showToast('Import failed. Please try again.', 'error');
        btn.disabled = false;
    }
}

// ── Keyboard Shortcuts ──
document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); saveDraft(); }
});

// ── Profile dropdown ──
const profileBtn  = document.getElementById('profileBtn');
const profileDrop = document.getElementById('profileDropdown');
profileBtn.addEventListener('click', e => {
    e.stopPropagation();
    profileDrop.classList.toggle('open');
});
document.addEventListener('click', () => {
    profileDrop.classList.remove('open');
});

function handleLogout() {
    if (confirm('Are you sure you want to logout?')) {
        window.location.href = 'logout.php';
    }
}

// ── Init ──
<?php if ($editMode && count($questions) > 0): ?>
switchTab(3);
<?php elseif ($editMode): ?>
switchTab(1);
<?php endif; ?>

<?php if (!$editMode): ?>
document.getElementById('addQBtn').disabled = true;
document.getElementById('addQBtn').title = 'Save a draft first to enable adding questions';
<?php endif; ?>
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
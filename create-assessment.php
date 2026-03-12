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
require_once 'db-guard.php';

$currentUser  = validateSession($conn, 'teacher');
$teacherId    = (int) $currentUser['user_id'];
$userName     = htmlspecialchars($currentUser['full_name'] ?? 'Teacher');
$userInitials = strtoupper(substr($currentUser['full_name'] ?? 'T', 0, 2));

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
    if ($assessment['status'] === 'active' || $assessment['status'] === 'archived') {
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
    <style>
        :root {
            --font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            --color-teacher-primary: #2E073F;
            --color-teacher-secondary: #AD49E1;
            --color-text: #2d3748;
            --color-text-light: #718096;
            --color-bg: #D3DAD9;
            --color-bg-light: #f5f7fa;
            --color-border: #e2e8f0;
            --color-success: #48bb78;
            --color-error: #f56565;
            --shadow-sm: 0 2px 10px rgba(0,0,0,0.08);
            --shadow-lg: 0 8px 30px rgba(0,0,0,0.15);
            --radius: 10px;
            --radius-lg: 20px;
            --transition: all 0.3s ease;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: var(--font-family);
            background: var(--color-bg);
            min-height: 100vh;
            color: var(--color-text);
            padding-top: 71px;
        }

        /* ── NAVBAR ── */
        .navbar {
            background: var(--color-teacher-primary);
            padding: 12px 28px;
            display: flex; align-items: center; justify-content: space-between;
            position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.15);
        }
        .navbar-brand {
            display: flex; align-items: center; gap: 12px;
            font-size: 20px; font-weight: 700; color: white; text-decoration: none;
        }
        .brand-logo {
            width: 44px; height: 44px;
            background: linear-gradient(135deg, var(--color-teacher-primary), var(--color-teacher-secondary));
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 16px;
        }
        .nav-right { display: flex; align-items: center; gap: 12px; }
        .btn-back {
            padding: 9px 20px;
            background: rgba(255,255,255,0.15);
            color: white; border: 2px solid rgba(255,255,255,0.4);
            border-radius: var(--radius); font-weight: 600; font-size: 14px;
            text-decoration: none; display: flex; align-items: center; gap: 8px;
            transition: var(--transition);
        }
        .btn-back:hover { background: rgba(255,255,255,0.25); }

        .container { max-width: 960px; margin: 0 auto; padding: 30px 20px 60px; }

        /* ── PAGE HEADER ── */
        .page-header {
            background: white; border-radius: var(--radius-lg); padding: 28px 30px;
            margin-bottom: 24px; box-shadow: var(--shadow-sm);
        }
        .page-title { font-size: 26px; font-weight: 700; color: var(--color-text); margin-bottom: 6px; }
        .page-subtitle { font-size: 14px; color: var(--color-text-light); }
        .draft-badge {
            display: inline-flex; align-items: center; gap: 6px;
            margin-top: 10px; padding: 5px 12px;
            background: #fef3c7; color: #92400e;
            border-radius: 7px; font-size: 12px; font-weight: 700;
        }

        /* ── STEPS ── */
        .steps-bar {
            display: flex; margin-bottom: 24px;
            background: white; border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm); overflow: hidden;
        }
        .step {
            flex: 1; padding: 16px 12px; text-align: center;
            font-size: 13px; font-weight: 600; color: var(--color-text-light);
            border-right: 1px solid var(--color-border); cursor: pointer;
            transition: var(--transition);
        }
        .step:last-child { border-right: none; }
        .step.active { color: var(--color-teacher-secondary); background: #faf5ff; }
        .step.done   { color: var(--color-success); }
        .step-num { display: block; font-size: 20px; margin-bottom: 4px; }
        .step.done .step-num::after { content: ' ✓'; font-size: 14px; }

        /* ── PANELS ── */
        .panel {
            background: white; border-radius: var(--radius-lg); padding: 28px 30px;
            margin-bottom: 20px; box-shadow: var(--shadow-sm);
            display: none;
        }
        .panel.active { display: block; }
        .panel-title {
            font-size: 18px; font-weight: 700; color: var(--color-text);
            margin-bottom: 22px; display: flex; align-items: center; gap: 10px;
        }
        .panel-icon {
            width: 36px; height: 36px; border-radius: 9px;
            background: linear-gradient(135deg, var(--color-teacher-primary), var(--color-teacher-secondary));
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 17px; flex-shrink: 0;
        }

        /* ── FORM ELEMENTS ── */
        .form-group { margin-bottom: 18px; }
        .form-label {
            display: block; font-size: 13px; font-weight: 600;
            color: var(--color-text); margin-bottom: 7px;
        }
        .form-label .req { color: var(--color-error); margin-left: 3px; }
        .form-input {
            width: 100%; padding: 11px 14px;
            border: 2px solid var(--color-border); border-radius: var(--radius);
            font-size: 14px; font-family: var(--font-family);
            transition: var(--transition); background: white; color: var(--color-text);
        }
        .form-input:focus {
            outline: none; border-color: var(--color-teacher-secondary);
            box-shadow: 0 0 0 3px rgba(173,73,225,0.1);
        }
        .form-textarea { min-height: 90px; resize: vertical; }
        .form-select {
            width: 100%; padding: 11px 14px;
            border: 2px solid var(--color-border); border-radius: var(--radius);
            font-size: 14px; background: white; cursor: pointer;
            font-family: var(--font-family); color: var(--color-text);
            transition: var(--transition);
        }
        .form-select:focus {
            outline: none; border-color: var(--color-teacher-secondary);
            box-shadow: 0 0 0 3px rgba(173,73,225,0.1);
        }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 18px; }

        .checkbox-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; }
        .checkbox-label {
            display: flex; align-items: center; gap: 9px; cursor: pointer;
            padding: 10px 14px; background: var(--color-bg-light);
            border-radius: var(--radius); border: 2px solid var(--color-border);
            font-size: 13px; transition: var(--transition); user-select: none;
        }
        .checkbox-label:hover { border-color: var(--color-teacher-secondary); }
        .checkbox-label input[type=checkbox] { width: 15px; height: 15px; accent-color: var(--color-teacher-secondary); }

        /* ── QUESTIONS LIST ── */
        .q-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .q-count { font-size: 14px; color: var(--color-text-light); font-weight: 600; }
        .q-list { display: flex; flex-direction: column; gap: 14px; }

        .q-card {
            border: 2px solid var(--color-border); border-radius: 12px;
            background: var(--color-bg-light); overflow: hidden;
            transition: var(--transition);
        }
        .q-card:hover { border-color: #c4b5fd; }
        .q-card-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 14px 18px;
            background: white; border-bottom: 1px solid var(--color-border);
        }
        .q-num-row { display: flex; align-items: center; gap: 8px; }
        .q-num  { font-weight: 700; color: var(--color-teacher-secondary); font-size: 14px; }
        .q-type-badge {
            font-size: 11px; font-weight: 600; padding: 2px 8px;
            background: #ede9fe; color: #5b21b6; border-radius: 4px;
        }
        .q-marks-badge {
            font-size: 11px; font-weight: 600; padding: 2px 8px;
            background: #d1fae5; color: #065f46; border-radius: 4px;
        }
        .q-card-actions { display: flex; gap: 7px; }
        .q-card-body { padding: 14px 18px; }
        .q-text { font-size: 14px; color: var(--color-text); line-height: 1.6; margin-bottom: 10px; }

        .q-options { display: flex; flex-direction: column; gap: 6px; }
        .q-option {
            padding: 8px 12px; background: white; border-radius: 7px;
            font-size: 13px; display: flex; align-items: center; gap: 8px;
            border: 2px solid transparent;
        }
        .q-option.correct { border-color: var(--color-success); background: rgba(72,187,120,0.07); }
        .q-opt-label { font-weight: 700; min-width: 22px; }
        .q-correct-badge {
            margin-left: auto; padding: 2px 8px;
            background: #c6f6d5; color: #22543d;
            border-radius: 4px; font-size: 11px; font-weight: 700;
        }
        .q-short-ans {
            padding: 8px 12px; background: white; border-radius: 7px;
            font-size: 13px; border: 2px solid var(--color-success);
        }
        .q-ans-label { font-size: 11px; font-weight: 600; color: var(--color-text-light); margin-bottom: 4px; }

        .no-questions { text-align: center; padding: 40px 20px; color: var(--color-text-light); font-size: 15px; }
        .no-q-icon { font-size: 44px; margin-bottom: 10px; opacity: 0.4; }

        /* ── ADD QUESTION AREA ── */
        .add-q-section {
            background: white; border-radius: var(--radius-lg); padding: 28px 30px;
            margin-bottom: 20px; box-shadow: var(--shadow-sm);
        }
        .add-q-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; }

        .type-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 18px; }
        .type-tab {
            padding: 7px 14px; background: var(--color-bg-light);
            border: 2px solid var(--color-border); border-radius: 8px;
            font-size: 12px; font-weight: 600; cursor: pointer; transition: var(--transition);
            color: var(--color-text-light);
        }
        .type-tab.active {
            background: var(--color-teacher-secondary);
            border-color: var(--color-teacher-secondary); color: white;
        }

        .add-form-group { margin-bottom: 14px; }
        .add-form-label {
            display: block; font-size: 12px; font-weight: 600;
            color: var(--color-text); margin-bottom: 5px;
        }
        .add-form-input {
            width: 100%; padding: 9px 12px;
            border: 2px solid var(--color-border); border-radius: 8px;
            font-size: 13px; font-family: var(--font-family);
            transition: var(--transition); background: white;
        }
        .add-form-input:focus {
            outline: none; border-color: var(--color-teacher-secondary);
            box-shadow: 0 0 0 3px rgba(173,73,225,0.1);
        }
        .opts-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px; }
        .add-form-row { display: flex; gap: 12px; }
        .add-form-row .add-form-group { flex: 1; }
        .ca-select {
            width: 100%; padding: 9px 12px;
            border: 2px solid var(--color-border); border-radius: 8px;
            font-size: 13px; background: white; cursor: pointer; font-family: var(--font-family);
        }
        .ca-select:focus { outline: none; border-color: var(--color-success); }

        .fields-mcq, .fields-tf, .fields-short { display: none; }
        .fields-mcq.show, .fields-tf.show, .fields-short.show { display: block; }

        /* ── UPLOAD ── */
        .upload-area {
            border: 3px dashed var(--color-border); border-radius: var(--radius-lg);
            padding: 40px 20px; text-align: center; cursor: pointer;
            transition: var(--transition); background: var(--color-bg-light);
            margin-bottom: 18px;
        }
        .upload-area:hover, .upload-area.drag { border-color: var(--color-teacher-secondary); background: #faf5ff; }
        .upload-icon { font-size: 44px; margin-bottom: 12px; }
        .upload-title { font-size: 17px; font-weight: 700; color: var(--color-text); margin-bottom: 6px; }
        .upload-sub   { font-size: 13px; color: var(--color-text-light); }
        .upload-input { display: none; }
        .upload-result {
            background: var(--color-bg-light); border-radius: var(--radius); padding: 14px 18px;
            margin-bottom: 14px; font-size: 13px; color: var(--color-text); display: none;
        }
        .upload-result.show { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .upload-result-info { display: flex; flex-direction: column; gap: 3px; }
        .upload-result-name { font-weight: 700; }
        .upload-result-count { color: var(--color-text-light); }
        .btn-import {
            padding: 10px 22px;
            background: linear-gradient(135deg, var(--color-teacher-primary), var(--color-teacher-secondary));
            color: white; border: none; border-radius: var(--radius);
            font-weight: 700; font-size: 14px; cursor: pointer; transition: var(--transition);
        }
        .btn-import:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(46,7,63,0.3); }
        .btn-import:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

        /* ── BUTTONS ── */
        .btn-sm {
            padding: 7px 14px; border: none; border-radius: 7px;
            font-size: 12px; font-weight: 600; cursor: pointer; transition: var(--transition);
        }
        .btn-edit-q    { background: #e2e8f0; color: var(--color-text); }
        .btn-edit-q:hover { background: #cbd5e0; }
        .btn-delete-q  { background: #fed7d7; color: #742a2a; }
        .btn-delete-q:hover { background: var(--color-error); color: white; }
        .btn-add-q {
            padding: 10px 20px;
            background: linear-gradient(135deg, var(--color-teacher-primary), var(--color-teacher-secondary));
            color: white; border: none; border-radius: var(--radius);
            font-weight: 700; font-size: 13px; cursor: pointer; transition: var(--transition);
            display: flex; align-items: center; gap: 7px;
        }
        .btn-add-q:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(46,7,63,0.3); }
        .btn-add-q:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

        /* ── ACTION BAR ── */
        .action-bar {
            background: white; border-radius: var(--radius-lg); padding: 20px 28px;
            box-shadow: var(--shadow-sm); margin-bottom: 30px;
            display: flex; justify-content: space-between; align-items: center; gap: 16px;
            flex-wrap: wrap;
        }
        .action-bar-info { font-size: 13px; color: var(--color-text-light); }
        .action-bar-btns { display: flex; gap: 12px; flex-wrap: wrap; }
        .btn-save-draft {
            padding: 11px 24px; background: var(--color-bg-light);
            color: var(--color-text); border: 2px solid var(--color-border);
            border-radius: var(--radius); font-weight: 700; font-size: 14px;
            cursor: pointer; transition: var(--transition);
        }
        .btn-save-draft:hover { border-color: var(--color-teacher-secondary); }
        .btn-save-draft:disabled { opacity: 0.6; cursor: not-allowed; }
        .btn-publish {
            padding: 11px 28px;
            background: linear-gradient(135deg, var(--color-teacher-primary), var(--color-teacher-secondary));
            color: white; border: none; border-radius: var(--radius);
            font-weight: 700; font-size: 14px; cursor: pointer; transition: var(--transition);
        }
        .btn-publish:hover { transform: translateY(-2px); box-shadow: 0 4px 14px rgba(46,7,63,0.3); }
        .btn-publish:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

        /* ── INLINE EDIT ── */
        .q-edit-form { display: none; padding: 16px 18px; border-top: 1px solid var(--color-border); }
        .q-edit-form.active { display: block; }
        .q-edit-row { display: flex; gap: 10px; }
        .q-edit-row .add-form-group { flex: 1; }
        .btn-save-q {
            background: linear-gradient(135deg, #48bb78, #38a169); color: white;
            border: none; border-radius: 7px; padding: 8px 16px;
            font-size: 12px; font-weight: 600; cursor: pointer; transition: var(--transition);
        }
        .btn-save-q:hover { transform: translateY(-1px); }
        .btn-cancel-q {
            background: white; color: var(--color-text-light);
            border: 2px solid var(--color-border); border-radius: 7px;
            padding: 6px 14px; font-size: 12px; font-weight: 600; cursor: pointer;
            transition: var(--transition);
        }
        .btn-cancel-q:hover { border-color: var(--color-error); color: var(--color-error); }

        /* ── TOAST / LOADING ── */
        .toast {
            position: fixed; bottom: 30px; left: 50%;
            transform: translateX(-50%) translateY(80px);
            background: #1a202c; color: white;
            padding: 13px 26px; border-radius: var(--radius);
            font-size: 14px; font-weight: 600; box-shadow: var(--shadow-lg);
            z-index: 9999; transition: transform 0.3s, opacity 0.3s;
            opacity: 0; pointer-events: none;
        }
        .toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
        .toast.success { background: #276749; }
        .toast.error   { background: #c53030; }
        .loading-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(255,255,255,0.92); z-index: 3000;
            align-items: center; justify-content: center;
        }
        .loading-overlay.active { display: flex; }
        .spinner {
            width: 46px; height: 46px;
            border: 5px solid var(--color-border);
            border-top-color: var(--color-teacher-secondary);
            border-radius: 50%; animation: spin 0.8s linear infinite;
            margin: 0 auto 14px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .loading-text { font-size: 15px; font-weight: 600; color: var(--color-text); text-align: center; }

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
</head>
<body>

<nav class="navbar">
    <a href="teacher-dashboard.php" class="navbar-brand">
        <div class="brand-logo">PT</div>
        <span>Placement Portal</span>
    </a>
    <div class="nav-right">
        <a href="teacher-dashboard.php" class="btn-back">← Dashboard</a>
    </div>
</nav>

<div class="container">

    <div class="page-header">
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
                           <?= ($assessment['visibility'] ?? '') === 'public' ? 'checked' : '' ?>>
                    Public (allow guest access)
                </label>
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
                <h3 style="font-size:16px;font-weight:700;color:var(--color-text);">Add a Question</h3>
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
        <p style="font-size:13px;color:var(--color-text-light);margin-bottom:20px;">
            Upload a PDF or DOCX with numbered MCQ questions. Parsed questions are added automatically.<br>
            Format: <code>1. Question text</code> followed by <code>a) Option</code> lines.
        </p>

        <div id="noDraftWarning" style="display:none;background:#fef3c7;border:2px solid #f59e0b;border-radius:10px;padding:14px 18px;margin-bottom:16px;font-size:13px;color:#92400e;">
            ⚠️ <strong>Complete Step 1 first.</strong>
            You need a title, category, difficulty, duration, and marks saved before importing questions.
            <button onclick="switchTab(1)" style="margin-left:10px;padding:4px 12px;background:#92400e;color:white;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;">Go to Step 1 →</button>
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
    } catch {
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
</body>
</html>
<?php
/* ========================================
 * CREATE / EDIT DRAFT ASSESSMENT
 * File: create-assessment.php
 *
 * Modes:
 *   ?            — new blank assessment
 *   ?edit=<id>   — continue editing a draft (loads all data + questions)
 *
 * Access: Teachers only — ownership verified for edit mode
 * ======================================== */

require 'config.php';
require_once 'db-guard.php';

$currentUser = validateSession($conn, 'teacher');
$teacherId   = (int) $currentUser['user_id'];
$userName    = htmlspecialchars($currentUser['full_name'] ?? 'Teacher');
$userInitials = strtoupper(substr($currentUser['full_name'] ?? 'T', 0, 2));

// ── Edit mode: load existing draft ──
$editMode     = false;
$assessmentId = 0;
$assessment   = null;
$questions    = [];

if (isset($_GET['edit']) && (int)$_GET['edit'] > 0) {
    $assessmentId = (int)$_GET['edit'];

    $r = safePreparedQuery($conn,
        "SELECT assessment_id, title, description, instructions, category, difficulty,
                status, duration_minutes, total_marks, passing_marks, max_attempts,
                available_from, available_until,
                show_results_immediately, show_correct_answers,
                randomize_questions, randomize_options, is_public, created_at
         FROM assessments
         WHERE assessment_id = ? AND created_by = ?",
        "ii", [$assessmentId, $teacherId]
    );

    if ($r['success'] && $r['result']) {
        $assessment = $r['result']->fetch_assoc();
        $r['result']->free();
    }

    // If not found or not owned by this teacher, treat as new
    if (!$assessment) {
        header('Location: create-assessment.php');
        exit;
    }

    // Only allow editing drafts (and scheduled — teacher may want to adjust before it goes live)
    if (!in_array($assessment['status'], ['draft', 'scheduled'], true)) {
        // Active/archived assessments go to edit-assessment.php instead
        header('Location: edit-assessment.php?id=' . $assessmentId);
        exit;
    }

    $editMode = true;

    // Load existing questions
    $rq = safePreparedQuery($conn,
        "SELECT question_id, question_type, question_text, marks, negative_marks,
                option_a, option_b, option_c, option_d,
                correct_answer, explanation, topic, question_order
         FROM questions
         WHERE assessment_id = ?
         ORDER BY question_order ASC, question_id ASC",
        "i", [$assessmentId]
    );
    if ($rq['success'] && $rq['result']) {
        while ($row = $rq['result']->fetch_assoc()) {
            $questions[] = $row;
        }
        $rq['result']->free();
    }
}

// ── Helpers ──
function toDatetimeLocal(?string $dt): string {
    if (!$dt) return '';
    return date('Y-m-d\TH:i', strtotime($dt));
}
function val(?array $a = null, string $key, string $default = ''): string {
    if ($a === null) return $default;
    return htmlspecialchars($a[$key] ?? $default, ENT_QUOTES, 'UTF-8');
}
function checked(?array $a = null, string $key, bool $defaultOn = false): string {
    if ($a === null) return $defaultOn ? 'checked' : '';
    return !empty($a[$key]) ? 'checked' : '';
}
function sel(?array $a = null, string $key, string $value, string $default = ''): string {
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
            --color-white: #ffffff;
            --color-border: #e2e8f0;
            --color-success: #48bb78;
            --color-error: #f56565;
            --shadow-sm: 0 2px 10px rgba(0,0,0,0.08);
            --shadow-md: 0 4px 20px rgba(0,0,0,0.08);
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

        /* ── CONTAINER ── */
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
            display: flex; gap: 0; margin-bottom: 24px;
            background: white; border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm); overflow: hidden;
        }
        .step {
            flex: 1; padding: 16px 12px; text-align: center;
            font-size: 13px; font-weight: 600; color: var(--color-text-light);
            border-right: 1px solid var(--color-border); cursor: pointer;
            transition: var(--transition); position: relative;
        }
        .step:last-child { border-right: none; }
        .step.active { color: var(--color-teacher-secondary); background: #faf5ff; }
        .step.done   { color: var(--color-success); }
        .step-num {
            display: block; font-size: 20px; margin-bottom: 4px;
        }
        .step.done .step-num::after  { content: ' ✓'; font-size: 14px; }

        /* ── SECTION PANELS ── */
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
        .form-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 18px;
        }

        /* Checkbox row */
        .checkbox-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;
        }
        .checkbox-label {
            display: flex; align-items: center; gap: 9px; cursor: pointer;
            padding: 10px 14px; background: var(--color-bg-light);
            border-radius: var(--radius); border: 2px solid var(--color-border);
            font-size: 13px; transition: var(--transition); user-select: none;
        }
        .checkbox-label:hover { border-color: var(--color-teacher-secondary); }
        .checkbox-label input[type=checkbox] { width: 15px; height: 15px; accent-color: var(--color-teacher-secondary); }

        /* ── QUESTIONS LIST ── */
        .q-header {
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;
        }
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

        .no-questions {
            text-align: center; padding: 40px 20px;
            color: var(--color-text-light); font-size: 15px;
        }
        .no-q-icon { font-size: 44px; margin-bottom: 10px; opacity: 0.4; }

        /* ── ADD QUESTION AREA ── */
        .add-q-section {
            background: white; border-radius: var(--radius-lg); padding: 28px 30px;
            margin-bottom: 20px; box-shadow: var(--shadow-sm);
        }
        .add-q-header {
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px;
        }

        /* Type tabs */
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

        /* Add form fields */
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
        .opts-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px;
        }
        .add-form-row { display: flex; gap: 12px; }
        .add-form-row .add-form-group { flex: 1; }

        .ca-select {
            width: 100%; padding: 9px 12px;
            border: 2px solid var(--color-border); border-radius: 8px;
            font-size: 13px; background: white; cursor: pointer; font-family: var(--font-family);
        }
        .ca-select:focus { outline: none; border-color: var(--color-success); }

        /* Conditional field groups */
        .fields-mcq, .fields-tf, .fields-short { display: none; }
        .fields-mcq.show, .fields-tf.show, .fields-short.show { display: block; }

        /* ── UPLOAD SECTION ── */
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

        /* ── BOTTOM ACTION BAR ── */
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

        /* ── TOAST ── */
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

        /* ── LOADING ── */
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

        /* ── INLINE EDIT FORM (in q-card) ── */
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

        /* ── RESPONSIVE ── */
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

    <!-- Page header -->
    <div class="page-header">
        <h1 class="page-title"><?= $editMode ? 'Continue Editing Draft' : 'Create New Assessment' ?></h1>
        <p class="page-subtitle">
            <?= $editMode
                ? 'Pick up where you left off — all your saved data is loaded below.'
                : 'Fill in the details, add questions, then publish or save as draft.' ?>
        </p>
        <?php if ($editMode): ?>
            <div class="draft-badge">📝 Draft #<?= $assessmentId ?> · <?= count($questions) ?> question<?= count($questions) !== 1 ? 's' : '' ?> saved</div>
        <?php endif; ?>
    </div>

    <!-- Step tabs -->
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
                   placeholder="e.g. Quantitative Aptitude - Set 1"
                   maxlength="200"
                   value="<?= val($assessment, 'title') ?>">
        </div>

        <div class="form-group">
            <label class="form-label">Description</label>
            <textarea class="form-input form-textarea" id="description"
                      placeholder="Brief overview of this assessment..."><?= val($assessment, 'description') ?></textarea>
        </div>

        <div class="form-group">
            <label class="form-label">Instructions</label>
            <textarea class="form-input form-textarea" id="instructions"
                      placeholder="Instructions shown to students before they start..."><?= val($assessment, 'instructions') ?></textarea>
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
                <button class="btn-publish" onclick="switchTab(2)">Next: Settings →</button>
            </div>
        </div>
    </div>

    <!-- ── PANEL 2: Settings ── -->
    <div class="panel" id="panel2">
        <h2 class="panel-title"><div class="panel-icon">⚙️</div> Settings</h2>

        <div class="form-grid">
            <div class="form-group">
                <label class="form-label">Available From</label>
                <input type="datetime-local" class="form-input" id="availableFrom"
                       value="<?= toDatetimeLocal($assessment['available_from'] ?? null) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Available Until</label>
                <input type="datetime-local" class="form-input" id="availableUntil"
                       value="<?= toDatetimeLocal($assessment['available_until'] ?? null) ?>">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Options</label>
            <div class="checkbox-grid">
                <label class="checkbox-label">
                    <input type="checkbox" id="showResults" <?= checked($assessment, 'show_results_immediately', true) ?>>
                    Show results immediately
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" id="showAnswers" <?= checked($assessment, 'show_correct_answers', true) ?>>
                    Show correct answers
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" id="randQ" <?= checked($assessment, 'randomize_questions') ?>>
                    Randomize questions
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" id="randO" <?= checked($assessment, 'randomize_options') ?>>
                    Randomize options
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" id="isPublic" <?= checked($assessment, 'is_public') ?>>
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

        <!-- Saved questions list -->
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
                <?php foreach ($questions as $i => $q):
                    $qid   = (int)$q['question_id'];
                    $qtype = $q['question_type'];
                    $isMCQ = in_array($qtype, ['mcq','true_false','multiple_select']);
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
                                <?php foreach (['A'=>$q['option_a'],'B'=>$q['option_b'],'C'=>$q['option_c'],'D'=>$q['option_d']] as $letter => $opt):
                                    if ($opt === null) continue;
                                    $correct = in_array($letter, array_map('trim', explode(',', strtoupper($q['correct_answer']))));
                                ?>
                                <div class="q-option <?= $correct ? 'correct' : '' ?>">
                                    <span class="q-opt-label"><?= $letter ?>)</span>
                                    <span><?= htmlspecialchars($opt) ?></span>
                                    <?php if ($correct): ?><span class="q-correct-badge">✓</span><?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="q-ans-label">Expected Answer:</div>
                            <div class="q-short-ans"><?= htmlspecialchars($q['correct_answer']) ?></div>
                        <?php endif; ?>
                    </div>
                    <!-- Inline edit form for saved questions -->
                    <div class="q-edit-form" id="editForm<?= $qid ?>">
                        <div class="add-form-group">
                            <label class="add-form-label">Question Text</label>
                            <textarea class="add-form-input" id="eqt<?= $qid ?>" rows="2"><?= htmlspecialchars($q['question_text']) ?></textarea>
                        </div>
                        <?php if ($isMCQ && $qtype !== 'true_false'): ?>
                        <div class="opts-grid">
                            <?php foreach (['a'=>$q['option_a'],'b'=>$q['option_b'],'c'=>$q['option_c'],'d'=>$q['option_d']] as $l => $o): ?>
                            <div class="add-form-group">
                                <label class="add-form-label">Option <?= strtoupper($l) ?></label>
                                <input type="text" class="add-form-input" id="eo<?= $l . $qid ?>" value="<?= htmlspecialchars($o ?? '') ?>">
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <div class="add-form-row">
                            <div class="add-form-group">
                                <label class="add-form-label">Correct Answer</label>
                                <?php if ($qtype === 'multiple_select'): ?>
                                    <input type="text" class="add-form-input" id="eca<?= $qid ?>"
                                           value="<?= htmlspecialchars(strtoupper($q['correct_answer'])) ?>" placeholder="e.g. A,C">
                                <?php elseif ($qtype === 'true_false'): ?>
                                    <select class="ca-select" id="eca<?= $qid ?>">
                                        <option value="A" <?= strtoupper($q['correct_answer']) === 'A' ? 'selected' : '' ?>>A — True</option>
                                        <option value="B" <?= strtoupper($q['correct_answer']) === 'B' ? 'selected' : '' ?>>B — False</option>
                                    </select>
                                <?php elseif ($qtype === 'mcq'): ?>
                                    <select class="ca-select" id="eca<?= $qid ?>">
                                        <?php foreach (['A','B','C','D'] as $l): ?>
                                            <option value="<?= $l ?>" <?= strtoupper($q['correct_answer']) === $l ? 'selected' : '' ?>><?= $l ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <textarea class="add-form-input" id="eca<?= $qid ?>" rows="2"><?= htmlspecialchars($q['correct_answer']) ?></textarea>
                                <?php endif; ?>
                            </div>
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

            <!-- MCQ / Multiple Select options -->
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
                <div class="add-form-group" id="mcqCaWrap">
                    <label class="add-form-label">Correct Answer <span style="color:var(--color-error)">*</span></label>
                    <select class="ca-select" id="newCaMcq">
                        <option value="">Select</option>
                        <option value="A">A</option><option value="B">B</option>
                        <option value="C">C</option><option value="D">D</option>
                    </select>
                </div>
                <div class="add-form-group" id="msCaWrap" style="display:none;">
                    <label class="add-form-label">Correct Answers (comma-separated) <span style="color:var(--color-error)">*</span></label>
                    <input type="text" class="add-form-input" id="newCaMs" placeholder="e.g. A,C">
                </div>
            </div>

            <!-- True/False -->
            <div class="fields-tf" id="fTf">
                <div class="add-form-group">
                    <label class="add-form-label">Correct Answer <span style="color:var(--color-error)">*</span></label>
                    <select class="ca-select" id="newCaTf">
                        <option value="A">True</option>
                        <option value="B">False</option>
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

            <!-- Common -->
            <div class="add-form-row">
                <div class="add-form-group">
                    <label class="add-form-label">Marks</label>
                    <input type="number" class="add-form-input" id="newMarks" min="1" value="1">
                </div>
                <div class="add-form-group">
                    <label class="add-form-label">Negative Marks</label>
                    <input type="number" class="add-form-input" id="newNegMarks" min="0" step="0.25" value="0">
                </div>
                <div class="add-form-group">
                    <label class="add-form-label">Topic</label>
                    <input type="text" class="add-form-input" id="newTopic" placeholder="Optional">
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
            Upload a PDF or DOCX with numbered MCQ questions. Questions are parsed and added automatically.
            Format: <code>1. Question text</code> followed by <code>a) Option</code> lines.
        </p>

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

<!-- Loading -->
<div class="loading-overlay" id="loading">
    <div>
        <div class="spinner"></div>
        <div class="loading-text" id="loadingText">Please wait…</div>
    </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<script>
// =====================================================================
// STATE
// =====================================================================
let assessmentId  = <?= $assessmentId ?>;   // 0 = brand-new, >0 = editing draft
let parsedQs      = [];                      // from document upload, waiting to import
let currentTab    = 1;

let csrfToken = null;
async function getCsrfToken() {
    if (csrfToken) return csrfToken;
    const res  = await fetch('api/csrf-token.php', { credentials: 'same-origin' });
    const data = await res.json();
    if (!data.success) throw new Error('Could not fetch CSRF token.');
    csrfToken = data.token;
    return csrfToken;
}

// =====================================================================
// TOAST / LOADING
// =====================================================================
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

// =====================================================================
// TABS
// =====================================================================
function switchTab(n) {
    [1,2,3,4].forEach(i => {
        document.getElementById('panel' + i).classList.toggle('active', i === n);
        document.getElementById('tab'   + i).classList.remove('active');
        if (i < n) document.getElementById('tab' + i).classList.add('done');
    });
    document.getElementById('tab' + n).classList.add('active');
    currentTab = n;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// =====================================================================
// COLLECT FORM DATA
// =====================================================================
function collectBasicData() {
    return {
        assessment_id           : assessmentId || undefined,
        title                   : document.getElementById('title').value.trim(),
        description             : document.getElementById('description').value.trim(),
        instructions            : document.getElementById('instructions').value.trim(),
        category                : document.getElementById('category').value,
        difficulty              : document.getElementById('difficulty').value,
        duration_minutes        : parseInt(document.getElementById('duration').value, 10) || 0,
        total_marks             : parseInt(document.getElementById('totalMarks').value, 10) || 0,
        passing_marks           : parseInt(document.getElementById('passingMarks').value, 10) || 0,
        max_attempts            : parseInt(document.getElementById('maxAttempts').value, 10) || 1,
        available_from          : document.getElementById('availableFrom').value || null,
        available_until         : document.getElementById('availableUntil').value || null,
        show_results_immediately: document.getElementById('showResults').checked ? 1 : 0,
        show_correct_answers    : document.getElementById('showAnswers').checked ? 1 : 0,
        randomize_questions     : document.getElementById('randQ').checked ? 1 : 0,
        randomize_options       : document.getElementById('randO').checked ? 1 : 0,
        is_public               : document.getElementById('isPublic').checked ? 1 : 0,
        status                  : 'draft',
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

// =====================================================================
// SAVE DRAFT  — creates assessment if new, updates if existing
// =====================================================================
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

// =====================================================================
// PUBLISH
// =====================================================================
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
            showToast('🎉 Assessment published!');
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

// =====================================================================
// QUESTION TYPE TABS
// =====================================================================
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

// =====================================================================
// ADD QUESTION
// =====================================================================
async function addQuestion() {
    // Must save draft first to get an assessment ID
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

    let correctAnswer, optA = null, optB = null, optC = null, optD = null;

    if (type === 'mcq') {
        optA = document.getElementById('newOa').value.trim();
        optB = document.getElementById('newOb').value.trim();
        optC = document.getElementById('newOc').value.trim() || null;
        optD = document.getElementById('newOd').value.trim() || null;
        correctAnswer = document.getElementById('newCaMcq').value.toUpperCase();
        if (!optA || !optB) { showToast('Options A and B are required.', 'error'); return; }
        if (!correctAnswer) { showToast('Select a correct answer.', 'error'); return; }
    } else if (type === 'multiple_select') {
        optA = document.getElementById('newOa').value.trim();
        optB = document.getElementById('newOb').value.trim();
        optC = document.getElementById('newOc').value.trim() || null;
        optD = document.getElementById('newOd').value.trim() || null;
        correctAnswer = document.getElementById('newCaMs').value.trim().toUpperCase();
        if (!optA || !optB) { showToast('Options A and B are required.', 'error'); return; }
        if (!correctAnswer) { showToast('Correct answers are required.', 'error'); return; }
    } else if (type === 'true_false') {
        optA = 'True'; optB = 'False';
        correctAnswer = document.getElementById('newCaTf').value;
    } else {
        correctAnswer = document.getElementById('newCaShort').value.trim();
        if (!correctAnswer) { showToast('Expected answer is required.', 'error'); return; }
    }

    const payload = {
        assessment_id  : assessmentId,
        question_type  : type,
        question_text  : qt,
        correct_answer : correctAnswer,
        option_a: optA, option_b: optB, option_c: optC, option_d: optD,
        marks,
        negative_marks : negMarks,
        topic          : document.getElementById('newTopic').value.trim(),
        explanation    : document.getElementById('newExpl').value.trim(),
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
    } catch (err) {
        showToast('Network error. Please try again.', 'error');
    } finally {
        hideLoading();
        btn.disabled = false;
    }
}

// =====================================================================
// INLINE EDIT (saved questions)
// =====================================================================
function toggleEditCard(qid) {
    const form = document.getElementById('editForm' + qid);
    form.classList.toggle('active');
}

async function saveExistingQ(qid, qtype) {
    const qt = document.getElementById('eqt' + qid).value.trim();
    if (!qt) { showToast('Question text is required.', 'error'); return; }

    const caEl = document.getElementById('eca' + qid);
    const ca   = caEl ? caEl.value.trim().toUpperCase() : '';
    if (!ca)   { showToast('Correct answer is required.', 'error'); return; }

    const isMCQ = ['mcq','true_false','multiple_select'].includes(qtype);
    const payload = {
        question_id   : qid,
        assessment_id : assessmentId,
        question_text : qt,
        correct_answer: ca,
        marks         : parseInt(document.getElementById('emk' + qid).value, 10) || 1,
        explanation   : (document.getElementById('eex' + qid)?.value ?? '').trim(),
    };

    if (isMCQ && qtype !== 'true_false') {
        payload.option_a = (document.getElementById('eoa' + qid)?.value ?? '').trim();
        payload.option_b = (document.getElementById('eob' + qid)?.value ?? '').trim();
        payload.option_c = (document.getElementById('eoc' + qid)?.value ?? '').trim() || null;
        payload.option_d = (document.getElementById('eod' + qid)?.value ?? '').trim() || null;
    }

    showLoading('Saving…');
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

// =====================================================================
// DELETE QUESTION (saved)
// =====================================================================
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
            const card = document.querySelector('[data-qid="' + qid + '"]');
            card?.remove();
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

// =====================================================================
// DOCUMENT UPLOAD & PARSE
// =====================================================================
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
        showToast('Save a draft first before importing.', 'error');
        await saveDraft();
        if (!assessmentId) return;
    }

    const btn = document.getElementById('importBtn');
    btn.disabled = true;
    showLoading('Importing ' + parsedQs.length + ' questions…');

    let imported = 0;
    const token = await getCsrfToken();

    // Helper: add one question with up to 3 retries on 409 conflict
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
                if (res.status !== 409) return false; // non-retryable error
            } catch { return false; }
        }
        return false;
    }

    for (const q of parsedQs) {
        let qType, optA, optB, optC, optD, correctAnswer;

        if (q.type === 'true_false') {
            qType   = 'true_false';
            optA    = 'True';
            optB    = 'False';
            optC    = null;
            optD    = null;
            // parser returns 'true'/'false'; DB expects 'A'/'B'
            correctAnswer = (q.correctAnswer === 'false') ? 'B' : 'A';
        } else {
            qType   = 'mcq';
            optA    = q.options[0] || null;
            optB    = q.options[1] || null;
            optC    = q.options[2] || null;
            optD    = q.options[3] || null;
            correctAnswer = q.correctAnswer ? q.correctAnswer.toUpperCase() : 'A';
        }

        const payload = {
            assessment_id : assessmentId,
            question_type : qType,
            question_text : q.text,
            option_a      : optA,
            option_b      : optB,
            option_c      : optC,
            option_d      : optD,
            correct_answer: correctAnswer,
            marks         : 1,
            negative_marks: 0,
        };
        if (await addOne(payload)) imported++;
        // Small delay between questions to avoid transaction contention
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

// =====================================================================
// KEYBOARD SHORTCUTS
// =====================================================================
document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        saveDraft();
    }
});

// =====================================================================
// INIT
// =====================================================================
// If editing a draft, jump to questions tab so teacher sees saved questions right away
<?php if ($editMode && count($questions) > 0): ?>
switchTab(3);
<?php elseif ($editMode): ?>
switchTab(1);
<?php endif; ?>

// Disable add-question button if no assessment ID yet (new, unsaved)
<?php if (!$editMode): ?>
document.getElementById('addQBtn').disabled = true;
document.getElementById('addQBtn').title = 'Save a draft first to enable adding questions';
<?php endif; ?>
</script>
</body>
</html>
<?php
/* ========================================
 * EDIT ASSESSMENT
 * File: edit-assessment.php
 *
 * Requires: ?id=<assessment_id>
 * Access:   Teachers only — ownership verified
 * ======================================== */

require 'config.php';
require_once 'db-guard.php';

// ── Session guard (teacher only) ──
$currentUser = validateSession($conn, 'teacher');
$teacherId   = (int) $currentUser['user_id'];

// ── Validate assessment ID ──
$assessmentId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($assessmentId <= 0) {
    header('Location: teacher-dashboard.php?error=invalid_id');
    exit;
}

// ── Load assessment — verify this teacher owns it ──
$assessment = null;
$r = safePreparedQuery($conn,
    "SELECT
        a.assessment_id,
        a.title,
        a.description,
        a.category,
        a.difficulty,
        a.status,
        a.duration_minutes,
        a.total_marks,
        a.passing_marks,
        a.available_from,
        a.available_until,
        a.max_attempts,
        a.show_results_immediately,
        a.show_correct_answers,
        a.randomize_questions,
        a.randomize_options,
        a.is_public,
        a.instructions,
        a.created_at,
        a.updated_at,
        a.created_by
     FROM assessments a
     WHERE a.assessment_id = ? AND a.created_by = ?",
    "ii", [$assessmentId, $teacherId]
);

if ($r['success'] && $r['result']) {
    $assessment = $r['result']->fetch_assoc();
    $r['result']->free();
}

// If not found or doesn't belong to this teacher, redirect
if (!$assessment) {
    header('Location: teacher-dashboard.php?error=not_found');
    exit;
}

// ── Load questions for this assessment ──
$questions = [];
$rq = safePreparedQuery($conn,
    "SELECT
        question_id,
        question_type,
        question_text,
        marks,
        negative_marks,
        option_a,
        option_b,
        option_c,
        option_d,
        correct_answer,
        explanation,
        topic,
        difficulty,
        question_order
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

// ── Load attempt stats ──
$stats = ['total_attempts' => 0, 'avg_score' => 0, 'avg_time' => 0, 'completion_rate' => 0];
$rs = safePreparedQuery($conn,
    "SELECT
        COUNT(*) AS total_attempts,
        ROUND(AVG(percentage), 1) AS avg_score,
        ROUND(AVG(TIMESTAMPDIFF(MINUTE, start_time, submitted_at)), 0) AS avg_time,
        ROUND(
            100.0 * SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0),
            1
        ) AS completion_rate
     FROM assessment_attempts
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

// ── Helper: escape for JS string ──
function jsStr(string $s): string {
    return addslashes(htmlspecialchars($s, ENT_QUOTES, 'UTF-8'));
}

// ── Helper: format datetime for <input type="datetime-local"> ──
function toDatetimeLocal(?string $dt): string {
    if (!$dt) return '';
    return date('Y-m-d\TH:i', strtotime($dt));
}

// ── Helper: format date for display ──
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
    <title>Edit: <?= htmlspecialchars($assessment['title']) ?> - Placement Portal</title>
    <style>
        :root {
            --font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            --color-primary: #234C6A;
            --color-primary-dark: #456882;
            --color-teacher-primary: #2E073F;
            --color-teacher-secondary: #AD49E1;
            --color-text: #2d3748;
            --color-text-light: #718096;
            --color-text-lighter: #a0aec0;
            --color-bg: #D3DAD9;
            --color-bg-light: #f5f7fa;
            --color-white: #ffffff;
            --color-border: #e2e8f0;
            --color-success: #48bb78;
            --color-error: #f56565;
            --color-warning: #ffc107;
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
            overflow-x: hidden;
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
            width: 45px; height: 45px;
            background: linear-gradient(135deg, var(--color-teacher-primary), var(--color-teacher-secondary));
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 20px;
        }
        .nav-actions { display: flex; gap: 15px; align-items: center; }
        .btn-back {
            padding: 9px 20px;
            background: rgba(255,255,255,0.15);
            color: white;
            border: 2px solid rgba(255,255,255,0.4);
            border-radius: var(--radius);
            font-weight: 600; font-size: 14px;
            cursor: pointer; text-decoration: none;
            display: flex; align-items: center; gap: 8px;
            transition: var(--transition);
        }
        .btn-back:hover { background: rgba(255,255,255,0.25); }

        /* ── CONTAINER ── */
        .container { max-width: 1100px; margin: 0 auto; padding: 30px 20px; }

        /* ── TOAST ── */
        .toast {
            position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%) translateY(80px);
            background: #1a202c; color: white;
            padding: 14px 28px; border-radius: var(--radius);
            font-size: 14px; font-weight: 600;
            box-shadow: var(--shadow-lg); z-index: 9999;
            transition: transform 0.3s ease, opacity 0.3s ease;
            opacity: 0; pointer-events: none;
        }
        .toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
        .toast.success { background: #276749; }
        .toast.error   { background: #c53030; }

        /* ── PAGE HEADER ── */
        .page-header {
            background: white; border-radius: var(--radius-lg); padding: 28px 30px;
            margin-bottom: 24px; box-shadow: var(--shadow-sm);
            display: flex; justify-content: space-between; align-items: flex-start; gap: 20px;
        }
        .header-content { flex: 1; min-width: 0; }
        .page-title {
            font-size: 26px; font-weight: 700; color: var(--color-text);
            margin-bottom: 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .page-description { font-size: 14px; color: var(--color-text-light); }
        .header-badges { display: flex; gap: 10px; margin-top: 12px; flex-wrap: wrap; }
        .meta-badge {
            padding: 5px 12px; border-radius: 7px;
            font-size: 12px; font-weight: 600;
            display: inline-flex; align-items: center; gap: 5px;
        }
        .meta-badge.active   { background: #d1fae5; color: #065f46; }
        .meta-badge.draft    { background: #fef3c7; color: #92400e; }
        .meta-badge.archived { background: #dbeafe; color: #1e40af; }
        .meta-badge.scheduled{ background: #e0e7ff; color: #3730a3; }
        .meta-badge.info     { background: #e6f7ff; color: #0c5460; }

        /* Status toggle */
        .status-toggle { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; flex-shrink: 0; }
        .toggle-label { font-size: 12px; font-weight: 600; color: var(--color-text-light); white-space: nowrap; }
        .toggle-switch {
            position: relative; width: 58px; height: 28px;
            background: #e2e8f0; border-radius: 14px;
            cursor: pointer; transition: var(--transition);
        }
        .toggle-switch.active { background: var(--color-success); }
        .toggle-slider {
            position: absolute; top: 3px; left: 3px;
            width: 22px; height: 22px;
            background: white; border-radius: 50%;
            transition: var(--transition);
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        .toggle-switch.active .toggle-slider { left: 33px; }

        /* ── STATS SECTION ── */
        .stats-section {
            background: white; border-radius: var(--radius-lg); padding: 26px 30px;
            margin-bottom: 24px; box-shadow: var(--shadow-sm);
        }
        .section-title {
            font-size: 18px; font-weight: 700; color: var(--color-text);
            margin-bottom: 18px; display: flex; align-items: center; gap: 10px;
        }
        .section-icon {
            width: 36px; height: 36px;
            background: linear-gradient(135deg, var(--color-teacher-primary), var(--color-teacher-secondary));
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 17px; flex-shrink: 0;
        }
        .stats-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 16px;
        }
        .stat-card {
            padding: 18px; background: var(--color-bg-light);
            border-radius: var(--radius); border: 2px solid var(--color-border);
            transition: var(--transition); text-align: center;
        }
        .stat-card:hover { border-color: var(--color-teacher-secondary); transform: translateY(-2px); }
        .stat-card .stat-icon { font-size: 28px; margin-bottom: 8px; }
        .stat-card .stat-value { font-size: 26px; font-weight: 700; color: var(--color-text); margin-bottom: 4px; }
        .stat-card .stat-label { font-size: 12px; color: var(--color-text-light); }

        /* ── FORM SECTIONS ── */
        .form-section {
            background: white; border-radius: var(--radius-lg); padding: 26px 30px;
            margin-bottom: 24px; box-shadow: var(--shadow-sm);
        }
        .form-group { margin-bottom: 18px; }
        .form-label {
            display: block; font-size: 13px; font-weight: 600;
            color: var(--color-text); margin-bottom: 7px;
        }
        .form-label .required { color: var(--color-error); margin-left: 3px; }
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
            display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 18px;
        }

        /* Checkbox row */
        .checkbox-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;
            margin-top: 6px;
        }
        .checkbox-label {
            display: flex; align-items: center; gap: 9px;
            font-size: 14px; color: var(--color-text); cursor: pointer;
            padding: 10px 14px; background: var(--color-bg-light);
            border-radius: var(--radius); border: 2px solid var(--color-border);
            transition: var(--transition); user-select: none;
        }
        .checkbox-label:hover { border-color: var(--color-teacher-secondary); }
        .checkbox-label input[type=checkbox] { width: 16px; height: 16px; accent-color: var(--color-teacher-secondary); }

        /* ── QUESTIONS SECTION ── */
        .questions-section {
            background: white; border-radius: var(--radius-lg); padding: 26px 30px;
            margin-bottom: 24px; box-shadow: var(--shadow-sm);
        }
        .section-header {
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 22px;
        }
        .btn-add-question {
            padding: 10px 20px;
            background: linear-gradient(135deg, var(--color-teacher-primary), var(--color-teacher-secondary));
            color: white; border: none; border-radius: var(--radius);
            font-weight: 700; font-size: 13px; cursor: pointer;
            transition: var(--transition); display: flex; align-items: center; gap: 7px;
        }
        .btn-add-question:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(46,7,63,0.3); }

        .questions-list { display: flex; flex-direction: column; gap: 18px; }

        .question-card {
            padding: 22px; background: var(--color-bg-light);
            border: 2px solid var(--color-border); border-radius: 14px;
            transition: var(--transition);
        }
        .question-card:hover { border-color: #c4b5fd; }
        .question-card.editing { border-color: var(--color-teacher-secondary); background: white; }

        .question-header {
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;
        }
        .question-number { font-size: 15px; font-weight: 700; color: var(--color-teacher-secondary); }
        .question-type-badge {
            font-size: 11px; font-weight: 600; padding: 3px 9px;
            background: #ede9fe; color: #5b21b6; border-radius: 5px; margin-left: 10px;
        }
        .question-marks-badge {
            font-size: 11px; font-weight: 600; padding: 3px 9px;
            background: #d1fae5; color: #065f46; border-radius: 5px; margin-left: 6px;
        }
        .question-actions { display: flex; gap: 8px; }

        /* Shared button base */
        .btn-sm {
            padding: 7px 14px; border: none; border-radius: 7px;
            font-size: 12px; font-weight: 600; cursor: pointer; transition: var(--transition);
        }
        .btn-edit-q  { background: #e2e8f0; color: var(--color-text); }
        .btn-edit-q:hover { background: #cbd5e0; }
        .btn-delete-q { background: #fed7d7; color: #742a2a; }
        .btn-delete-q:hover { background: var(--color-error); color: white; }
        .btn-save-q  { background: linear-gradient(135deg, #48bb78, #38a169); color: white; }
        .btn-save-q:hover { transform: translateY(-1px); box-shadow: 0 3px 8px rgba(72,187,120,0.4); }
        .btn-cancel-q { background: white; color: var(--color-text-light); border: 2px solid var(--color-border); padding: 5px 14px; }
        .btn-cancel-q:hover { border-color: var(--color-error); color: var(--color-error); }

        /* Question display (read mode) */
        .question-display { display: block; }
        .question-display.hidden { display: none; }
        .question-text { font-size: 15px; color: var(--color-text); margin-bottom: 14px; line-height: 1.6; }

        .options-list { display: flex; flex-direction: column; gap: 8px; }
        .option-item {
            padding: 10px 14px; background: white; border-radius: 8px;
            font-size: 13px; color: var(--color-text);
            display: flex; align-items: center; gap: 8px;
            border: 2px solid transparent;
        }
        .option-item.correct {
            border-color: var(--color-success);
            background: rgba(72,187,120,0.07);
        }
        .option-label { font-weight: 700; min-width: 24px; }
        .correct-badge {
            margin-left: auto; padding: 3px 9px;
            background: #c6f6d5; color: #22543d;
            border-radius: 5px; font-size: 11px; font-weight: 700;
        }

        /* Short answer / other types */
        .short-answer-display {
            padding: 10px 14px; background: white; border-radius: 8px;
            font-size: 13px; color: var(--color-text); border: 2px solid var(--color-success);
        }
        .short-answer-label { font-size: 11px; font-weight: 600; color: var(--color-text-light); margin-bottom: 4px; }

        /* Question edit form */
        .question-edit-form { display: none; }
        .question-edit-form.active { display: block; }

        .edit-form-group { margin-bottom: 14px; }
        .edit-form-label { display: block; font-size: 12px; font-weight: 600; color: var(--color-text); margin-bottom: 5px; }
        .edit-form-input {
            width: 100%; padding: 9px 12px;
            border: 2px solid var(--color-border); border-radius: 8px;
            font-size: 13px; font-family: var(--font-family);
            transition: var(--transition); background: white; color: var(--color-text);
        }
        .edit-form-input:focus {
            outline: none; border-color: var(--color-teacher-secondary);
            box-shadow: 0 0 0 3px rgba(173,73,225,0.1);
        }
        .options-edit-grid {
            display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 12px;
        }
        .edit-form-row { display: flex; gap: 12px; }
        .edit-form-row .edit-form-group { flex: 1; }

        .correct-answer-select {
            width: 100%; padding: 9px 12px;
            border: 2px solid var(--color-border); border-radius: 8px;
            font-size: 13px; background: white; cursor: pointer;
            font-family: var(--font-family); transition: var(--transition);
        }
        .correct-answer-select:focus {
            outline: none; border-color: var(--color-success);
        }

        /* Question type tabs in edit form */
        .type-indicator {
            display: inline-block; padding: 4px 10px;
            background: #ede9fe; color: #5b21b6;
            border-radius: 6px; font-size: 12px; font-weight: 600; margin-bottom: 12px;
        }

        /* Empty questions */
        .no-questions {
            text-align: center; padding: 40px 20px;
            color: var(--color-text-light); font-size: 15px;
        }
        .no-questions-icon { font-size: 48px; margin-bottom: 12px; opacity: 0.4; }

        /* ── ACTION SECTION ── */
        .action-section {
            background: white; border-radius: var(--radius-lg); padding: 24px 30px;
            box-shadow: var(--shadow-sm); margin-bottom: 30px;
            display: flex; justify-content: space-between; align-items: center; gap: 20px;
            flex-wrap: wrap;
        }
        .action-info { font-size: 13px; color: var(--color-text-light); }
        .action-buttons { display: flex; gap: 12px; flex-wrap: wrap; }

        .btn-save-changes {
            padding: 11px 28px;
            background: linear-gradient(135deg, var(--color-teacher-primary), var(--color-teacher-secondary));
            color: white; border: none; border-radius: var(--radius);
            font-weight: 700; font-size: 14px; cursor: pointer; transition: var(--transition);
        }
        .btn-save-changes:hover { transform: translateY(-2px); box-shadow: 0 4px 14px rgba(46,7,63,0.3); }
        .btn-save-changes:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

        .btn-view-results {
            padding: 11px 22px; background: white;
            color: var(--color-primary); border: 2px solid var(--color-primary);
            border-radius: var(--radius); font-weight: 700; font-size: 14px;
            cursor: pointer; transition: var(--transition); text-decoration: none;
            display: inline-flex; align-items: center;
        }
        .btn-view-results:hover { background: var(--color-primary); color: white; transform: translateY(-2px); }

        .btn-delete-assessment {
            padding: 11px 22px; background: white;
            color: var(--color-error); border: 2px solid var(--color-error);
            border-radius: var(--radius); font-weight: 700; font-size: 14px;
            cursor: pointer; transition: var(--transition);
        }
        .btn-delete-assessment:hover { background: var(--color-error); color: white; transform: translateY(-2px); }

        /* ── ADD QUESTION MODAL ── */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.65); z-index: 1000;
            align-items: center; justify-content: center;
        }
        .modal-overlay.active { display: flex; }
        .modal-content {
            background: white; border-radius: var(--radius-lg); padding: 36px;
            max-width: 680px; width: 92%; max-height: 90vh; overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .modal-header {
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 22px;
        }
        .modal-title { font-size: 22px; font-weight: 700; color: var(--color-text); }
        .btn-close-modal {
            width: 34px; height: 34px; background: var(--color-bg-light); border: none;
            border-radius: 50%; font-size: 18px; cursor: pointer; transition: var(--transition);
            display: flex; align-items: center; justify-content: center;
        }
        .btn-close-modal:hover { background: var(--color-border); transform: rotate(90deg); }

        /* Question type selector in modal */
        .type-tabs { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
        .type-tab {
            padding: 7px 14px; background: var(--color-bg-light);
            border: 2px solid var(--color-border); border-radius: 8px;
            font-size: 13px; font-weight: 600; cursor: pointer; transition: var(--transition);
            color: var(--color-text-light);
        }
        .type-tab.active {
            background: var(--color-teacher-secondary); border-color: var(--color-teacher-secondary);
            color: white;
        }

        /* Fields that show/hide based on question type */
        .mcq-fields, .tf-fields, .short-fields { display: none; }
        .mcq-fields.show, .tf-fields.show, .short-fields.show { display: block; }

        /* ── DELETE CONFIRM MODAL ── */
        .confirm-modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.5); z-index: 2000;
            align-items: center; justify-content: center;
        }
        .confirm-modal-overlay.open { display: flex; }
        .confirm-modal {
            background: white; border-radius: var(--radius-lg); padding: 28px;
            width: 90%; max-width: 420px; box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .confirm-modal-title { font-size: 18px; font-weight: 700; margin-bottom: 10px; }
        .confirm-modal-body  { font-size: 14px; color: var(--color-text-light); margin-bottom: 22px; line-height: 1.6; }
        .confirm-actions { display: flex; gap: 10px; justify-content: flex-end; }
        .btn-cancel-confirm {
            padding: 9px 20px; background: var(--color-bg-light);
            border: 2px solid var(--color-border); border-radius: var(--radius);
            font-weight: 600; cursor: pointer; transition: var(--transition);
        }
        .btn-cancel-confirm:hover { border-color: var(--color-error); color: var(--color-error); }
        .btn-confirm-action {
            padding: 9px 20px; background: var(--color-error); color: white;
            border: none; border-radius: var(--radius);
            font-weight: 700; cursor: pointer; transition: var(--transition);
        }
        .btn-confirm-action:hover { background: #c53030; }
        .btn-confirm-action:disabled { opacity: 0.6; cursor: not-allowed; }

        /* ── LOADING OVERLAY ── */
        .loading-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(255,255,255,0.92); z-index: 3000;
            align-items: center; justify-content: center;
        }
        .loading-overlay.active { display: flex; }
        .loading-content { text-align: center; }
        .spinner {
            width: 48px; height: 48px;
            border: 5px solid var(--color-border);
            border-top-color: var(--color-teacher-secondary);
            border-radius: 50%; animation: spin 0.8s linear infinite;
            margin: 0 auto 16px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .loading-text { font-size: 16px; font-weight: 600; color: var(--color-text); }

        /* ── RESPONSIVE ── */
        @media (max-width: 768px) {
            .container { padding: 16px; }
            .page-header { flex-direction: column; }
            .status-toggle { align-items: flex-start; }
            .form-grid { grid-template-columns: 1fr; }
            .options-edit-grid { grid-template-columns: 1fr; }
            .action-section { flex-direction: column; }
            .action-buttons { width: 100%; flex-direction: column; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .modal-content { padding: 22px; }
            .edit-form-row { flex-direction: column; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

<!-- ── NAVBAR ── -->
<nav class="navbar">
    <a href="teacher-dashboard.php" class="navbar-brand">
        <div class="brand-logo">PT</div>
        <span>Placement Portal</span>
    </a>
    <div class="nav-actions">
        <a href="teacher-dashboard.php" class="btn-back">← Back to Dashboard</a>
    </div>
</nav>

<!-- ── MAIN ── -->
<div class="container">

    <!-- Page Header -->
    <div class="page-header">
        <div class="header-content">
            <h1 class="page-title" id="headerTitle"><?= htmlspecialchars($assessment['title']) ?></h1>
            <p class="page-description">Assessment ID #<?= $assessmentId ?> · Last updated <?= fmtDate($assessment['updated_at']) ?></p>
            <div class="header-badges">
                <span class="meta-badge <?= htmlspecialchars($assessment['status']) ?>" id="statusBadge">
                    ● <?= ucfirst($assessment['status']) ?>
                </span>
                <span class="meta-badge info">📅 Created: <?= fmtDate($assessment['created_at']) ?></span>
                <span class="meta-badge info">👥 <?= $stats['total_attempts'] ?> Attempt<?= $stats['total_attempts'] !== 1 ? 's' : '' ?></span>
            </div>
        </div>
        <div class="status-toggle">
            <span class="toggle-label">Active Status</span>
            <div class="toggle-switch <?= $assessment['status'] === 'active' ? 'active' : '' ?>"
                 id="statusToggle" onclick="toggleStatus()" title="Toggle active/draft">
                <div class="toggle-slider"></div>
            </div>
        </div>
    </div>

    <!-- Statistics -->
    <div class="stats-section">
        <h2 class="section-title">
            <div class="section-icon">📊</div>
            Performance Statistics
        </h2>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">👥</div>
                <div class="stat-value"><?= $stats['total_attempts'] ?></div>
                <div class="stat-label">Total Attempts</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📈</div>
                <div class="stat-value"><?= $stats['avg_score'] ?>%</div>
                <div class="stat-label">Average Score</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">⏱️</div>
                <div class="stat-value"><?= $stats['avg_time'] ?>m</div>
                <div class="stat-label">Avg. Completion Time</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🎯</div>
                <div class="stat-value"><?= $stats['completion_rate'] ?>%</div>
                <div class="stat-label">Completion Rate</div>
            </div>
        </div>
    </div>

    <!-- Basic Information Form -->
    <div class="form-section">
        <h2 class="section-title">
            <div class="section-icon">📝</div>
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

        <div class="form-group">
            <label class="form-label">Instructions</label>
            <textarea class="form-input form-textarea" id="assessmentInstructions"><?= htmlspecialchars($assessment['instructions'] ?? '') ?></textarea>
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
                <label class="form-label">Available From</label>
                <input type="datetime-local" class="form-input" id="availableFrom"
                       value="<?= toDatetimeLocal($assessment['available_from']) ?>">
            </div>

            <div class="form-group">
                <label class="form-label">Available Until</label>
                <input type="datetime-local" class="form-input" id="availableUntil"
                       value="<?= toDatetimeLocal($assessment['available_until']) ?>">
            </div>
        </div>

        <!-- Checkbox options -->
        <div class="form-group">
            <label class="form-label">Options</label>
            <div class="checkbox-grid">
                <label class="checkbox-label">
                    <input type="checkbox" id="showResultsImmediately"
                           <?= $assessment['show_results_immediately'] ? 'checked' : '' ?>>
                    Show results immediately
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" id="showCorrectAnswers"
                           <?= $assessment['show_correct_answers'] ? 'checked' : '' ?>>
                    Show correct answers
                </label>
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
                           <?= $assessment['is_public'] ? 'checked' : '' ?>>
                    Public assessment (guest access)
                </label>
            </div>
        </div>
    </div>

    <!-- Questions Section -->
    <div class="questions-section">
        <div class="section-header">
            <h2 class="section-title" style="margin-bottom:0;">
                <div class="section-icon">❓</div>
                <span id="questionCountTitle">Questions (<?= count($questions) ?>)</span>
            </h2>
            <button class="btn-add-question" onclick="openAddModal()">➕ Add Question</button>
        </div>

        <div class="questions-list" id="questionsList">
            <?php if (empty($questions)): ?>
                <div class="no-questions" id="noQuestionsMsg">
                    <div class="no-questions-icon">❓</div>
                    <div>No questions yet. Add your first question above.</div>
                </div>
            <?php else: ?>
                <?php foreach ($questions as $i => $q):
                    $qid    = (int)$q['question_id'];
                    $qNum   = $i + 1;
                    $qType  = $q['question_type'];
                    $qText  = $q['question_text'];
                    $qCA    = $q['correct_answer'];
                    $isMCQ  = in_array($qType, ['mcq', 'true_false']);
                ?>
                <div class="question-card" data-qid="<?= $qid ?>">
                    <div class="question-header">
                        <div>
                            <span class="question-number">Question <?= $qNum ?></span>
                            <span class="question-type-badge"><?= htmlspecialchars(str_replace('_',' ', $qType)) ?></span>
                            <span class="question-marks-badge"><?= (int)$q['marks'] ?> mark<?= (int)$q['marks'] !== 1 ? 's' : '' ?></span>
                        </div>
                        <div class="question-actions">
                            <button class="btn-sm btn-edit-q" onclick="editQ(<?= $qid ?>)">✏️ Edit</button>
                            <button class="btn-sm btn-delete-q" onclick="deleteQ(<?= $qid ?>, <?= $qNum ?>)">🗑️ Delete</button>
                        </div>
                    </div>

                    <!-- Display Mode -->
                    <div class="question-display" id="disp<?= $qid ?>">
                        <div class="question-text"><?= htmlspecialchars($qText) ?></div>

                        <?php if (in_array($qType, ['mcq', 'multiple_select', 'true_false'])): ?>
                            <div class="options-list">
                                <?php foreach (['A'=>$q['option_a'],'B'=>$q['option_b'],'C'=>$q['option_c'],'D'=>$q['option_d']] as $letter => $opt):
                                    if ($opt === null && $qType === 'true_false' && $letter > 'B') continue;
                                    if ($opt === null) continue;
                                    // correct_answer may be single letter or comma-separated for multiple_select
                                    $correctLetters = array_map('trim', explode(',', strtoupper($qCA)));
                                    $isCorrect = in_array($letter, $correctLetters);
                                ?>
                                <div class="option-item <?= $isCorrect ? 'correct' : '' ?>">
                                    <span class="option-label"><?= $letter ?>)</span>
                                    <span><?= htmlspecialchars($opt) ?></span>
                                    <?php if ($isCorrect): ?><span class="correct-badge">✓ Correct</span><?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>

                        <?php elseif (in_array($qType, ['short_answer', 'fill_blank'])): ?>
                            <div class="short-answer-label">Expected Answer:</div>
                            <div class="short-answer-display"><?= htmlspecialchars($qCA) ?></div>

                        <?php else: ?>
                            <div class="short-answer-label">Correct Answer:</div>
                            <div class="short-answer-display"><?= htmlspecialchars($qCA) ?></div>
                        <?php endif; ?>

                        <?php if ($q['explanation']): ?>
                            <div style="margin-top:10px;padding:8px 12px;background:#fffbeb;border-radius:7px;font-size:12px;color:#92400e;">
                                💡 <strong>Explanation:</strong> <?= htmlspecialchars($q['explanation']) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Edit Mode -->
                    <div class="question-edit-form" id="edit<?= $qid ?>">
                        <div class="type-indicator"><?= htmlspecialchars(str_replace('_', ' ', $qType)) ?></div>

                        <div class="edit-form-group">
                            <label class="edit-form-label">Question Text <span style="color:var(--color-error)">*</span></label>
                            <textarea class="edit-form-input" id="qt<?= $qid ?>" rows="3"><?= htmlspecialchars($qText) ?></textarea>
                        </div>

                        <?php if (in_array($qType, ['mcq', 'multiple_select', 'true_false'])): ?>
                            <?php if ($qType === 'true_false'): ?>
                                <!-- True/False has only A=True, B=False -->
                                <div class="edit-form-row">
                                    <div class="edit-form-group">
                                        <label class="edit-form-label">Option A (True)</label>
                                        <input type="text" class="edit-form-input" id="oa<?= $qid ?>"
                                               value="<?= htmlspecialchars($q['option_a'] ?? 'True') ?>">
                                    </div>
                                    <div class="edit-form-group">
                                        <label class="edit-form-label">Option B (False)</label>
                                        <input type="text" class="edit-form-input" id="ob<?= $qid ?>"
                                               value="<?= htmlspecialchars($q['option_b'] ?? 'False') ?>">
                                    </div>
                                </div>
                                <div class="edit-form-group">
                                    <label class="edit-form-label">Correct Answer</label>
                                    <select class="correct-answer-select" id="ca<?= $qid ?>">
                                        <option value="A" <?= strtoupper($qCA) === 'A' ? 'selected' : '' ?>>A — True</option>
                                        <option value="B" <?= strtoupper($qCA) === 'B' ? 'selected' : '' ?>>B — False</option>
                                    </select>
                                </div>
                            <?php else: ?>
                                <!-- MCQ / Multiple Select -->
                                <div class="options-edit-grid">
                                    <?php foreach (['A'=>$q['option_a'],'B'=>$q['option_b'],'C'=>$q['option_c'],'D'=>$q['option_d']] as $letter => $opt): ?>
                                        <div class="edit-form-group">
                                            <label class="edit-form-label">Option <?= $letter ?></label>
                                            <input type="text" class="edit-form-input" id="o<?= strtolower($letter) ?><?= $qid ?>"
                                                   value="<?= htmlspecialchars($opt ?? '') ?>">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="edit-form-group">
                                    <label class="edit-form-label">Correct Answer<?= $qType === 'multiple_select' ? ' (comma-separated, e.g. A,C)' : '' ?></label>
                                    <?php if ($qType === 'multiple_select'): ?>
                                        <input type="text" class="edit-form-input" id="ca<?= $qid ?>"
                                               value="<?= htmlspecialchars(strtoupper($qCA)) ?>" placeholder="e.g. A,C">
                                    <?php else: ?>
                                        <select class="correct-answer-select" id="ca<?= $qid ?>">
                                            <?php foreach (['A','B','C','D'] as $letter): ?>
                                                <option value="<?= $letter ?>" <?= strtoupper($qCA) === $letter ? 'selected' : '' ?>><?= $letter ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <!-- Short answer / fill_blank / match -->
                            <div class="edit-form-group">
                                <label class="edit-form-label">Correct Answer / Expected Answer</label>
                                <textarea class="edit-form-input" id="ca<?= $qid ?>" rows="2"><?= htmlspecialchars($qCA) ?></textarea>
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
                            <div class="edit-form-group">
                                <label class="edit-form-label">Topic</label>
                                <input type="text" class="edit-form-input" id="topic<?= $qid ?>"
                                       value="<?= htmlspecialchars($q['topic'] ?? '') ?>" placeholder="Optional">
                            </div>
                        </div>

                        <div class="edit-form-group">
                            <label class="edit-form-label">Explanation (optional)</label>
                            <textarea class="edit-form-input" id="expl<?= $qid ?>" rows="2"><?= htmlspecialchars($q['explanation'] ?? '') ?></textarea>
                        </div>

                        <div class="question-actions" style="margin-top:14px;">
                            <button class="btn-sm btn-save-q"   onclick="saveQ(<?= $qid ?>, '<?= $qType ?>')">💾 Save</button>
                            <button class="btn-sm btn-cancel-q" onclick="cancelQ(<?= $qid ?>)">Cancel</button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="action-section">
        <div class="action-info">💡 <strong>Tip:</strong> Use Ctrl+S to save all changes quickly.</div>
        <div class="action-buttons">
            <a href="assessment-results.php?id=<?= $assessmentId ?>" class="btn-view-results">📊 View Results</a>
            <button class="btn-save-changes" id="saveAllBtn" onclick="saveAll()">💾 Save All Changes</button>
            <button class="btn-delete-assessment" onclick="confirmDeleteAssessment()">🗑️ Delete Assessment</button>
        </div>
    </div>

</div><!-- /container -->

<!-- ── ADD QUESTION MODAL ── -->
<div class="modal-overlay" id="addModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Add New Question</h2>
            <button class="btn-close-modal" onclick="closeAddModal()">✕</button>
        </div>

        <!-- Question type tabs -->
        <div class="type-tabs">
            <button class="type-tab active" data-type="mcq"           onclick="switchType('mcq', this)">MCQ</button>
            <button class="type-tab"        data-type="true_false"     onclick="switchType('true_false', this)">True / False</button>
            <button class="type-tab"        data-type="short_answer"   onclick="switchType('short_answer', this)">Short Answer</button>
            <button class="type-tab"        data-type="fill_blank"     onclick="switchType('fill_blank', this)">Fill in the Blank</button>
            <button class="type-tab"        data-type="multiple_select" onclick="switchType('multiple_select', this)">Multiple Select</button>
        </div>
        <input type="hidden" id="newQType" value="mcq">

        <div class="edit-form-group">
            <label class="edit-form-label">Question Text <span style="color:var(--color-error)">*</span></label>
            <textarea class="edit-form-input" id="newQt" rows="3" placeholder="Enter question text..."></textarea>
        </div>

        <!-- MCQ / Multiple Select options -->
        <div class="mcq-fields show" id="mcqFields">
            <div class="options-edit-grid">
                <div class="edit-form-group">
                    <label class="edit-form-label">Option A <span style="color:var(--color-error)">*</span></label>
                    <input type="text" class="edit-form-input" id="newOa" placeholder="Option A">
                </div>
                <div class="edit-form-group">
                    <label class="edit-form-label">Option B <span style="color:var(--color-error)">*</span></label>
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
            <div class="edit-form-group" id="mcqAnswerGroup">
                <label class="edit-form-label">Correct Answer <span style="color:var(--color-error)">*</span></label>
                <select class="correct-answer-select" id="newCa">
                    <option value="">Select</option>
                    <option value="A">A</option>
                    <option value="B">B</option>
                    <option value="C">C</option>
                    <option value="D">D</option>
                </select>
            </div>
            <!-- Multiple select answer field (hidden by default) -->
            <div class="edit-form-group" id="msAnswerGroup" style="display:none;">
                <label class="edit-form-label">Correct Answers (comma-separated, e.g. A,C) <span style="color:var(--color-error)">*</span></label>
                <input type="text" class="edit-form-input" id="newCaMs" placeholder="e.g. A,C">
            </div>
        </div>

        <!-- True/False options -->
        <div class="tf-fields" id="tfFields">
            <div class="edit-form-group">
                <label class="edit-form-label">Correct Answer <span style="color:var(--color-error)">*</span></label>
                <select class="correct-answer-select" id="newCaTf">
                    <option value="A">True</option>
                    <option value="B">False</option>
                </select>
            </div>
        </div>

        <!-- Short answer / fill blank -->
        <div class="short-fields" id="shortFields">
            <div class="edit-form-group">
                <label class="edit-form-label">Expected Answer <span style="color:var(--color-error)">*</span></label>
                <textarea class="edit-form-input" id="newCaShort" rows="2" placeholder="Expected answer or keywords..."></textarea>
            </div>
        </div>

        <!-- Common fields -->
        <div class="edit-form-row">
            <div class="edit-form-group">
                <label class="edit-form-label">Marks <span style="color:var(--color-error)">*</span></label>
                <input type="number" class="edit-form-input" id="newMarks" min="1" value="1">
            </div>
            <div class="edit-form-group">
                <label class="edit-form-label">Negative Marks</label>
                <input type="number" class="edit-form-input" id="newNegMarks" min="0" step="0.25" value="0">
            </div>
            <div class="edit-form-group">
                <label class="edit-form-label">Topic</label>
                <input type="text" class="edit-form-input" id="newTopic" placeholder="Optional">
            </div>
        </div>

        <div class="edit-form-group">
            <label class="edit-form-label">Explanation (optional)</label>
            <textarea class="edit-form-input" id="newExpl" rows="2" placeholder="Explanation shown after submission..."></textarea>
        </div>

        <div class="action-buttons" style="margin-top:22px;">
            <button class="btn-save-q btn-sm" onclick="addNewQ()" style="padding:11px 28px;font-size:14px;">➕ Add Question</button>
            <button class="btn-cancel-q btn-sm" onclick="closeAddModal()" style="padding:11px 22px;font-size:14px;">Cancel</button>
        </div>
    </div>
</div>

<!-- ── CONFIRM MODAL (delete question / delete assessment) ── -->
<div class="confirm-modal-overlay" id="confirmModal">
    <div class="confirm-modal">
        <div class="confirm-modal-title" id="confirmTitle">Confirm Action</div>
        <div class="confirm-modal-body"  id="confirmBody">Are you sure?</div>
        <div class="confirm-actions">
            <button class="btn-cancel-confirm" onclick="closeConfirmModal()">Cancel</button>
            <button class="btn-confirm-action" id="confirmActionBtn">Confirm</button>
        </div>
    </div>
</div>

<!-- ── LOADING OVERLAY ── -->
<div class="loading-overlay" id="loading">
    <div class="loading-content">
        <div class="spinner"></div>
        <div class="loading-text" id="loadingText">Saving…</div>
    </div>
</div>

<!-- ── TOAST ── -->
<div class="toast" id="toast"></div>

<script>
// =====================================================================
// STATE
// =====================================================================
const ASSESSMENT_ID = <?= $assessmentId ?>;
let editingSet      = new Set();   // question IDs currently in edit mode
let confirmCallback = null;        // function to call on confirm modal confirm

// ── CSRF TOKEN — fetched once, reused for all POST requests ──
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
// TOAST
// =====================================================================
function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className   = 'toast ' + type;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3000);
}

// =====================================================================
// LOADING
// =====================================================================
function showLoading(msg = 'Saving…') {
    document.getElementById('loadingText').textContent = msg;
    document.getElementById('loading').classList.add('active');
}
function hideLoading() {
    document.getElementById('loading').classList.remove('active');
}

// =====================================================================
// CONFIRM MODAL
// =====================================================================
function openConfirmModal(title, body, onConfirm, btnLabel = 'Confirm', dangerous = false) {
    document.getElementById('confirmTitle').textContent = title;
    document.getElementById('confirmBody').textContent  = body;
    const btn = document.getElementById('confirmActionBtn');
    btn.textContent = btnLabel;
    btn.style.background = dangerous ? '#c53030' : '#276749';
    btn.disabled = false;
    confirmCallback = onConfirm;
    document.getElementById('confirmModal').classList.add('open');
}
function closeConfirmModal() {
    document.getElementById('confirmModal').classList.remove('open');
    confirmCallback = null;
}
document.getElementById('confirmActionBtn').addEventListener('click', function() {
    if (confirmCallback) {
        this.disabled = true;
        confirmCallback();
    }
});
document.getElementById('confirmModal').addEventListener('click', function(e) {
    if (e.target === this) closeConfirmModal();
});

// =====================================================================
// STATUS TOGGLE
// =====================================================================
function toggleStatus() {
    const toggle    = document.getElementById('statusToggle');
    const isActive  = toggle.classList.contains('active');
    const newStatus = isActive ? 'draft' : 'active';

    openConfirmModal(
        isActive ? 'Set to Draft?' : 'Set to Active?',
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
                    badge.className   = 'meta-badge ' + newStatus;
                    badge.textContent = '● ' + newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
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
        isActive ? 'Set Draft' : 'Set Active'
    );
}

// =====================================================================
// SAVE ALL CHANGES (assessment details)
// =====================================================================
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

    const payload = {
        assessment_id           : ASSESSMENT_ID,
        title,
        description             : document.getElementById('assessmentDescription').value.trim(),
        instructions            : document.getElementById('assessmentInstructions').value.trim(),
        category,
        difficulty              : diff,
        duration_minutes        : dur,
        total_marks             : marks,
        passing_marks           : passing,
        max_attempts            : parseInt(document.getElementById('maxAttempts').value, 10) || 1,
        available_from          : document.getElementById('availableFrom').value || null,
        available_until         : document.getElementById('availableUntil').value || null,
        show_results_immediately: document.getElementById('showResultsImmediately').checked ? 1 : 0,
        show_correct_answers    : document.getElementById('showCorrectAnswers').checked ? 1 : 0,
        randomize_questions     : document.getElementById('randomizeQuestions').checked ? 1 : 0,
        randomize_options       : document.getElementById('randomizeOptions').checked ? 1 : 0,
        is_public               : document.getElementById('isPublic').checked ? 1 : 0,
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
            showToast('✅ All changes saved!');
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

// =====================================================================
// QUESTION EDIT / CANCEL
// =====================================================================
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

// =====================================================================
// SAVE QUESTION (inline edit)
// =====================================================================
async function saveQ(id, qtype) {
    const qt = document.getElementById('qt' + id).value.trim();
    if (!qt) { showToast('Question text is required.', 'error'); return; }

    let correctAnswer;
    const isMCQType = ['mcq', 'true_false', 'multiple_select'].includes(qtype);

    if (isMCQType) {
        correctAnswer = document.getElementById('ca' + id).value.trim().toUpperCase();
    } else {
        correctAnswer = document.getElementById('ca' + id).value.trim();
    }
    if (!correctAnswer) { showToast('Correct answer is required.', 'error'); return; }

    const marks    = parseInt(document.getElementById('marks'   + id).value, 10) || 1;
    const negMarks = parseFloat(document.getElementById('negmarks' + id).value) || 0;

    const payload = {
        question_id    : id,
        assessment_id  : ASSESSMENT_ID,
        question_text  : qt,
        correct_answer : correctAnswer,
        marks,
        negative_marks : negMarks,
        topic          : document.getElementById('topic' + id).value.trim(),
        explanation    : document.getElementById('expl'  + id).value.trim(),
    };

    // Options for MCQ-style questions
    if (['mcq', 'true_false', 'multiple_select'].includes(qtype)) {
        payload.option_a = (document.getElementById('oa' + id)?.value ?? '').trim();
        payload.option_b = (document.getElementById('ob' + id)?.value ?? '').trim();
        payload.option_c = (document.getElementById('oc' + id)?.value ?? '').trim() || null;
        payload.option_d = (document.getElementById('od' + id)?.value ?? '').trim() || null;
    }

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
            cancelQ(id);
            // Refresh the display — simplest is a page reload after save
            showToast('✅ Question saved!');
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

// =====================================================================
// DELETE QUESTION
// =====================================================================
function deleteQ(id, num) {
    openConfirmModal(
        'Delete Question ' + num + '?',
        'This will permanently remove the question and all associated student answers. This cannot be undone.',
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
                    card.style.transition = 'opacity 0.3s';
                    card.style.opacity    = '0';
                    setTimeout(() => {
                        card.remove();
                        renumberQuestions();
                    }, 300);
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
        'Delete',
        true
    );
}

// Renumber question labels after a delete
function renumberQuestions() {
    const cards = document.querySelectorAll('.question-card');
    cards.forEach((card, i) => {
        const numEl = card.querySelector('.question-number');
        if (numEl) numEl.textContent = 'Question ' + (i + 1);
    });
    const count = cards.length;
    document.getElementById('questionCountTitle').textContent = 'Questions (' + count + ')';
    const noMsg = document.getElementById('noQuestionsMsg');
    if (count === 0 && noMsg) noMsg.style.display = 'block';
}

// =====================================================================
// ADD QUESTION MODAL — type switching
// =====================================================================
function switchType(type, btn) {
    document.getElementById('newQType').value = type;
    document.querySelectorAll('.type-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');

    // Show/hide option groups
    document.getElementById('mcqFields').classList.remove('show');
    document.getElementById('tfFields').classList.remove('show');
    document.getElementById('shortFields').classList.remove('show');

    // Toggle MCQ answer select vs. multiple-select text input
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
        // short_answer, fill_blank
        document.getElementById('shortFields').classList.add('show');
    }
}

function openAddModal() {
    // Reset form
    ['newQt','newOa','newOb','newOc','newOd','newCaMs','newCaShort','newTopic','newExpl'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    document.getElementById('newCa').value   = '';
    document.getElementById('newCaTf').value = 'A';
    document.getElementById('newMarks').value    = '1';
    document.getElementById('newNegMarks').value = '0';

    // Reset to MCQ tab
    document.querySelectorAll('.type-tab').forEach(t => t.classList.remove('active'));
    document.querySelector('[data-type="mcq"]').classList.add('active');
    switchType('mcq', document.querySelector('[data-type="mcq"]'));

    document.getElementById('addModal').classList.add('active');
}
function closeAddModal() {
    document.getElementById('addModal').classList.remove('active');
}

// =====================================================================
// ADD QUESTION — submit
// =====================================================================
async function addNewQ() {
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
        correctAnswer = document.getElementById('newCa').value.toUpperCase();
        if (!optA || !optB) { showToast('Options A and B are required.', 'error'); return; }
        if (!correctAnswer) { showToast('Correct answer is required.', 'error'); return; }

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
        // short_answer, fill_blank
        correctAnswer = document.getElementById('newCaShort').value.trim();
        if (!correctAnswer) { showToast('Expected answer is required.', 'error'); return; }
    }

    const payload = {
        assessment_id  : ASSESSMENT_ID,
        question_type  : type,
        question_text  : qt,
        correct_answer : correctAnswer,
        option_a       : optA,
        option_b       : optB,
        option_c       : optC,
        option_d       : optD,
        marks,
        negative_marks : negMarks,
        topic          : document.getElementById('newTopic').value.trim(),
        explanation    : document.getElementById('newExpl').value.trim(),
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
            showToast('✅ Question added!');
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

// =====================================================================
// DELETE ASSESSMENT
// =====================================================================
function confirmDeleteAssessment() {
    openConfirmModal(
        'Delete Assessment?',
        'This will permanently delete the assessment, all its questions, and all student attempts. This cannot be undone.',
        () => {
            closeConfirmModal();
            const code = prompt('Type DELETE to confirm:');
            if (code !== 'DELETE') { showToast('Deletion cancelled.'); return; }
            doDeleteAssessment();
        },
        'Delete',
        true
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

// =====================================================================
// KEYBOARD SHORTCUTS
// =====================================================================
document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        saveAll();
    }
    if (e.key === 'Escape') {
        closeAddModal();
        closeConfirmModal();
        editingSet.forEach(id => cancelQ(id));
    }
});

// Close add modal on backdrop click
document.getElementById('addModal').addEventListener('click', function(e) {
    if (e.target === this) closeAddModal();
});
</script>
</body>
</html>
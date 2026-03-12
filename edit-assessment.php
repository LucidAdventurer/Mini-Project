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

$currentUser = validateSession($conn, 'teacher');
$teacherId   = (int) $currentUser['user_id'];

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
    <title>Edit: <?= htmlspecialchars($assessment['title']) ?> - Placement Portal</title>
    <style>
        :root {
            --font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            --color-primary: #234C6A;
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
        .meta-badge.info     { background: #e6f7ff; color: #0c5460; }

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

        /* ── STATS ── */
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

        /* ── FORM ── */
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

        /* ── QUESTIONS ── */
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
        .option-item.correct { border-color: var(--color-success); background: rgba(72,187,120,0.07); }
        .option-label { font-weight: 700; min-width: 24px; }
        .correct-badge {
            margin-left: auto; padding: 3px 9px;
            background: #c6f6d5; color: #22543d;
            border-radius: 5px; font-size: 11px; font-weight: 700;
        }
        .short-answer-display {
            padding: 10px 14px; background: white; border-radius: 8px;
            font-size: 13px; color: var(--color-text); border: 2px solid var(--color-success);
        }
        .short-answer-label { font-size: 11px; font-weight: 600; color: var(--color-text-light); margin-bottom: 4px; }

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
        .correct-answer-select:focus { outline: none; border-color: var(--color-success); }
        .type-indicator {
            display: inline-block; padding: 4px 10px;
            background: #ede9fe; color: #5b21b6;
            border-radius: 6px; font-size: 12px; font-weight: 600; margin-bottom: 12px;
        }
        .no-questions { text-align: center; padding: 40px 20px; color: var(--color-text-light); font-size: 15px; }
        .no-questions-icon { font-size: 48px; margin-bottom: 12px; opacity: 0.4; }

        /* ── ACTION BAR ── */
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

        /* ── MODALS ── */
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
        .type-tabs { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
        .type-tab {
            padding: 7px 14px; background: var(--color-bg-light);
            border: 2px solid var(--color-border); border-radius: 8px;
            font-size: 13px; font-weight: 600; cursor: pointer; transition: var(--transition);
            color: var(--color-text-light);
        }
        .type-tab.active {
            background: var(--color-teacher-secondary); border-color: var(--color-teacher-secondary); color: white;
        }
        .mcq-fields, .tf-fields, .short-fields { display: none; }
        .mcq-fields.show, .tf-fields.show, .short-fields.show { display: block; }

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
        @media (max-width: 480px) { .stats-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<nav class="navbar">
    <a href="teacher-dashboard.php" class="navbar-brand">
        <div class="brand-logo">PT</div>
        <span>Placement Portal</span>
    </a>
    <div class="nav-actions">
        <a href="teacher-dashboard.php" class="btn-back">← Back to Dashboard</a>
    </div>
</nav>

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
            <span class="toggle-label">Published Status</span>
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

    <!-- Basic Info Form -->
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
                           <?= $assessment['visibility'] === 'public' ? 'checked' : '' ?>>
                    Public (guest access)
                </label>
            </div>
        </div>
    </div>

    <!-- Questions -->
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
                    $qid   = (int)$q['question_id'];
                    $qNum  = $i + 1;
                    $qType = $q['question_type'];
                    $opts  = $q['options']; // array of option rows
                    $letters = ['A','B','C','D','E'];
                    $isMCQ = in_array($qType, ['mcq','true_false','multiple_select']);
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
                        <div class="question-text"><?= htmlspecialchars($q['question_text']) ?></div>

                        <?php if ($isMCQ): ?>
                            <div class="options-list">
                                <?php foreach ($opts as $oi => $opt): ?>
                                <div class="option-item <?= $opt['is_correct'] ? 'correct' : '' ?>">
                                    <span class="option-label"><?= $letters[$oi] ?? ($oi+1) ?>)</span>
                                    <span><?= htmlspecialchars($opt['option_text']) ?></span>
                                    <?php if ($opt['is_correct']): ?><span class="correct-badge">✓ Correct</span><?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <?php
                            $correctOpt = array_filter($opts, fn($o) => $o['is_correct']);
                            $correctText = $correctOpt ? reset($correctOpt)['option_text'] : '—';
                            ?>
                            <div class="short-answer-label">Expected Answer:</div>
                            <div class="short-answer-display"><?= htmlspecialchars($correctText) ?></div>
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
                            <textarea class="edit-form-input" id="qt<?= $qid ?>" rows="3"><?= htmlspecialchars($q['question_text']) ?></textarea>
                        </div>

                        <?php if ($isMCQ): ?>
                            <!-- Pass existing options as JSON for JS to use -->
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
                                                   style="margin-left:6px;accent-color:var(--color-success);">
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
                                $correctOpt = array_filter($opts, fn($o) => $o['is_correct']);
                                $existingText = $correctOpt ? reset($correctOpt)['option_text'] : '';
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

        <div class="type-tabs">
            <button class="type-tab active" data-type="mcq"            onclick="switchType('mcq', this)">MCQ</button>
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
            <!-- MCQ: single correct -->
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
            <!-- Multiple Select: checkboxes -->
            <div class="edit-form-group" id="msAnswerGroup" style="display:none;">
                <label class="edit-form-label">Correct Options <span style="color:var(--color-error)">*</span></label>
                <div style="display:flex;gap:14px;flex-wrap:wrap;">
                    <label style="display:flex;align-items:center;gap:5px;font-size:13px;">
                        <input type="checkbox" id="msA" style="accent-color:var(--color-success);"> A
                    </label>
                    <label style="display:flex;align-items:center;gap:5px;font-size:13px;">
                        <input type="checkbox" id="msB" style="accent-color:var(--color-success);"> B
                    </label>
                    <label style="display:flex;align-items:center;gap:5px;font-size:13px;">
                        <input type="checkbox" id="msC" style="accent-color:var(--color-success);"> C
                    </label>
                    <label style="display:flex;align-items:center;gap:5px;font-size:13px;">
                        <input type="checkbox" id="msD" style="accent-color:var(--color-success);"> D
                    </label>
                </div>
            </div>
        </div>

        <!-- True/False -->
        <div class="tf-fields" id="tfFields">
            <div class="edit-form-group">
                <label class="edit-form-label">Correct Answer <span style="color:var(--color-error)">*</span></label>
                <select class="correct-answer-select" id="newCaTf">
                    <option value="true">True</option>
                    <option value="false">False</option>
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

        <div class="edit-form-row">
            <div class="edit-form-group">
                <label class="edit-form-label">Marks <span style="color:var(--color-error)">*</span></label>
                <input type="number" class="edit-form-input" id="newMarks" min="1" value="1">
            </div>
            <div class="edit-form-group">
                <label class="edit-form-label">Negative Marks</label>
                <input type="number" class="edit-form-input" id="newNegMarks" min="0" step="0.25" value="0">
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

<!-- ── CONFIRM MODAL ── -->
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
    if (confirmCallback) { this.disabled = true; confirmCallback(); }
});
document.getElementById('confirmModal').addEventListener('click', function(e) {
    if (e.target === this) closeConfirmModal();
});

// ── Status Toggle ──
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
        visibility          : document.getElementById('isPublic').checked ? 'public' : 'private',
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
// Sends updated question text + marks + explanation + options to the API.
// The API (update-question.php) must handle updating question_options rows.
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
            // Correct answer chosen from select; text is fixed
            const correctOptId = parseInt(document.getElementById('tfCorrect' + id).value, 10);
            opts.forEach(o => {
                options.push({
                    option_id  : o.option_id,
                    option_text: o.option_text,
                    is_correct : o.option_id === correctOptId,
                });
            });
        } else {
            // mcq / multiple_select — read text and correct checkboxes
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
        // short_answer / fill_blank — single option row as the expected answer
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
                    card.style.transition = 'opacity 0.3s';
                    card.style.opacity    = '0';
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
        if (numEl) numEl.textContent = 'Question ' + (i + 1);
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
// Sends options as an array; API creates question + question_options rows.
async function addNewQ() {
    const type = document.getElementById('newQType').value;
    const qt   = document.getElementById('newQt').value.trim();
    if (!qt) { showToast('Question text is required.', 'error'); return; }

    const marks    = parseInt(document.getElementById('newMarks').value, 10) || 1;
    const negMarks = parseFloat(document.getElementById('newNegMarks').value) || 0;
    const expl     = document.getElementById('newExpl').value.trim();

    let options = [];

    if (type === 'mcq') {
        const texts     = ['A','B','C','D'].map((l,i) =>
            document.getElementById('newO' + l.toLowerCase()).value.trim());
        const correctLetter = document.getElementById('newCa').value.toUpperCase();
        if (!texts[0] || !texts[1]) { showToast('Options A and B are required.', 'error'); return; }
        if (!correctLetter) { showToast('Select a correct answer.', 'error'); return; }
        ['A','B','C','D'].forEach((l, i) => {
            if (!texts[i]) return;
            options.push({ option_text: texts[i], is_correct: l === correctLetter, option_order: i + 1 });
        });

    } else if (type === 'multiple_select') {
        const texts = ['A','B','C','D'].map(l =>
            document.getElementById('newO' + l.toLowerCase()).value.trim());
        if (!texts[0] || !texts[1]) { showToast('Options A and B are required.', 'error'); return; }
        const correct = ['A','B','C','D'].filter(l => document.getElementById('ms' + l).checked);
        if (!correct.length) { showToast('Select at least one correct option.', 'error'); return; }
        ['A','B','C','D'].forEach((l, i) => {
            if (!texts[i]) return;
            options.push({ option_text: texts[i], is_correct: correct.includes(l), option_order: i + 1 });
        });

    } else if (type === 'true_false') {
        const correctVal = document.getElementById('newCaTf').value; // 'true' or 'false'
        options = [
            { option_text: 'True',  is_correct: correctVal === 'true',  option_order: 1 },
            { option_text: 'False', is_correct: correctVal === 'false', option_order: 2 },
        ];

    } else {
        // short_answer, fill_blank
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
</script>
</body>
</html>
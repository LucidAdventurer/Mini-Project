<?php
/* ============================================================
 * take-test.php
 *
 * Loads questions for an in-progress attempt.
 * Arrived at from: test-preview.php → api/assessment/start.php
 * URL: take-test.php?attempt_id=X
 *
 * What this file does:
 *  1. Validates session (student only)
 *  2. Validates the attempt belongs to this student and is 'in_progress'
 *  3. Loads the assessment config (title, duration, marks, randomize flags)
 *  4. Loads all questions for this attempt (respects randomize_questions
 *     and randomize_options set by the teacher)
 *  5. Injects everything as a JS constant so the frontend works unchanged
 *  6. submitTest() calls  api/assessment/submit.php
 *  7. autoSave() calls    api/assessment/autosave.php
 * ============================================================ */

require_once 'config.php';
require_once 'db-guard.php';

/* ── Auth ── */
$user   = validateSession($conn, 'student');
$userId = (int) $user['user_id'];

/* ── Validate attempt_id ── */
$attemptId = (int)($_GET['attempt_id'] ?? 0);
if ($attemptId <= 0) {
    header('Location: student-dashboard.php?error=invalid_attempt');
    exit;
}

/* ── Load attempt + assessment ── */
$asmResult = safePreparedQuery($conn,
    "SELECT
        aa.attempt_id,
        aa.assessment_id,
        aa.start_time,
        aa.status,

        a.title,
        a.duration_minutes,
        a.total_marks,
        a.passing_marks,
        a.randomize_questions,
        a.randomize_options,

        TIMESTAMPDIFF(SECOND, aa.start_time, NOW()) AS elapsed_seconds

     FROM assessment_attempts aa
     JOIN assessments a ON a.assessment_id = aa.assessment_id
     WHERE aa.attempt_id = ?
       AND aa.user_id    = ?",
    "ii", [$attemptId, $userId]
);

if (!$asmResult['success'] || !$asmResult['result'] || $asmResult['result']->num_rows === 0) {
    header('Location: student-dashboard.php?error=attempt_not_found');
    exit;
}
$attempt = $asmResult['result']->fetch_assoc();
$asmResult['result']->free();

/* ── Guard: only allow 'in_progress' attempts ── */
if ($attempt['status'] !== 'in_progress') {
    header("Location: test-results.php?attempt_id=$attemptId");
    exit;
}

/* ── Calculate time remaining ── */
$totalSeconds   = (int)$attempt['duration_minutes'] * 60;
$elapsedSeconds = max(0, (int)$attempt['elapsed_seconds']);
$timeRemaining  = max(0, $totalSeconds - $elapsedSeconds);

/* If timer has expired, auto-submit and redirect */
if ($timeRemaining <= 0) {
    safePreparedQuery($conn,
        "UPDATE assessment_attempts
         SET status = 'timeout', submitted_at = NOW()
         WHERE attempt_id = ? AND user_id = ? AND status = 'in_progress'",
        "ii", [$attemptId, $userId]
    );
    header("Location: api/assessment/submit.php?attempt_id=$attemptId&auto=1");
    exit;
}

/* ── Load questions ── */
$assessmentId = (int)$attempt['assessment_id'];

$qResult = safePreparedQuery($conn,
    "SELECT q.question_id, q.question_type, q.question_text,
            q.marks, q.negative_marks,
            qo.option_id, qo.option_text, qo.is_correct, qo.option_order
     FROM questions q
     LEFT JOIN question_options qo ON qo.question_id = q.question_id
     WHERE q.assessment_id = ?
     ORDER BY q.question_order ASC, q.question_id ASC, qo.option_order ASC",
    "i", [$assessmentId]
);

$questionsRaw = [];
if ($qResult['success'] && $qResult['result']) {
    while ($row = $qResult['result']->fetch_assoc()) {
        $qid = (int)$row['question_id'];
        if (!isset($questionsRaw[$qid])) {
            $questionsRaw[$qid] = [
                'question_id'    => $qid,
                'question_type'  => $row['question_type'],
                'question_text'  => $row['question_text'],
                'marks'          => (int)$row['marks'],
                'negative_marks' => (float)$row['negative_marks'],
                'options'        => [],
            ];
        }
        if ($row['option_id'] !== null) {
            $questionsRaw[$qid]['options'][] = [
                'option_id'   => (int)$row['option_id'],
                'option_text' => $row['option_text'],
                'option_order'=> (int)$row['option_order'],
            ];
        }
    }
    $qResult['result']->free();
}

$qIds = array_keys($questionsRaw);

if ($attempt['randomize_questions']) {
    shuffle($qIds);
}

if (empty($qIds)) {
    header('Location: student-dashboard.php?error=no_questions');
    exit;
}

/* ── Load any existing answers (resume support) ── */
$savedResult = safePreparedQuery($conn,
    "SELECT question_id, selected_option_id
     FROM answers
     WHERE attempt_id = ?",
    "i", [$attemptId]
);

$savedAnswers = [];
if ($savedResult['success'] && $savedResult['result']) {
    while ($row = $savedResult['result']->fetch_assoc()) {
        if ($row['selected_option_id'] !== null) {
            $savedAnswers[(int)$row['question_id']] = (int)$row['selected_option_id'];
        }
    }
    $savedResult['result']->free();
}

/* ── Build JS-safe question array ── */
$questions = [];
foreach ($qIds as $pos => $qid) {
    if (!isset($questionsRaw[$qid])) continue;
    $q = $questionsRaw[$qid];

    $opts = $q['options'];

    if ($attempt['randomize_options'] && count($opts) > 1) {
        shuffle($opts);
    }

    $labelList = ['A','B','C','D'];
    $builtOpts = [];
    foreach ($opts as $i => $opt) {
        $lbl = $labelList[$i] ?? chr(65 + $i);
        $builtOpts[] = [
            'label'     => $lbl,
            'text'      => $opt['option_text'],
            'option_id' => $opt['option_id'],
        ];
    }

    $savedOptionId = $savedAnswers[$qid] ?? null;
    $savedLabel    = null;
    if ($savedOptionId !== null) {
        foreach ($builtOpts as $bo) {
            if ($bo['option_id'] === $savedOptionId) {
                $savedLabel = $bo['label'];
                break;
            }
        }
    }

    $questions[] = [
        'id'          => $qid,
        'pos'         => $pos + 1,
        'type'        => $q['question_type'],
        'text'        => $q['question_text'],
        'options'     => $builtOpts,
        'marks'       => $q['marks'],
        'negMarks'    => $q['negative_marks'],
        'savedAnswer' => $savedLabel,
    ];
}

$totalQuestions = count($questions);

$questionsJson = json_encode($questions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP);
$savedJson     = json_encode(
    array_combine(
        array_column($questions, 'id'),
        array_column($questions, 'savedAnswer')
    ),
    JSON_HEX_TAG
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($attempt['title']) ?> - Assessment</title>
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
            --header-h:      68px;
            --transition:    .2s cubic-bezier(.4,0,.2,1);
        }

        html, *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            overflow-x: hidden;
            padding-top: var(--header-h);
            -webkit-font-smoothing: antialiased;
        }

        /* ══════════════════════════════
           TEST HEADER
        ══════════════════════════════ */
        .test-header {
            background: var(--primary);
            padding: 0 28px;
            height: var(--header-h);
            display: flex; justify-content: space-between; align-items: center;
            position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
            box-shadow: 0 1px 0 rgba(255,255,255,.06), 0 4px 20px rgba(0,0,0,.18);
        }

        .test-info { display: flex; align-items: center; gap: 16px; }

        .test-title {
            font-family: 'Sora', sans-serif;
            font-size: 16px; font-weight: 800; color: white;
            letter-spacing: -.2px;
            max-width: 360px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }

        .test-badge {
            padding: 5px 12px;
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.2);
            color: rgba(255,255,255,.9); border-radius: 8px;
            font-size: 12px; font-weight: 600; white-space: nowrap;
        }

        .timer-section { display: flex; align-items: center; gap: 12px; }

        .timer-display {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 20px;
            background: rgba(255,255,255,.12);
            border: 1.5px solid rgba(255,255,255,.2);
            border-radius: var(--radius-sm);
            color: white;
            font-family: 'Sora', sans-serif; font-weight: 800; font-size: 18px;
            min-width: 140px; justify-content: center;
            transition: var(--transition);
        }
        .timer-display.warning {
            background: rgba(239,68,68,.25);
            border-color: var(--danger);
            animation: pulse 1s ease-in-out infinite;
        }
        @keyframes pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.04); } }

        .submit-btn {
            padding: 10px 24px;
            background: linear-gradient(135deg, var(--success), #34d399);
            color: white; border: none; border-radius: var(--radius-sm);
            font-family: 'Inter', sans-serif; font-weight: 700; font-size: 13.5px;
            cursor: pointer; transition: var(--transition);
            box-shadow: 0 2px 8px rgba(16,185,129,.35);
        }
        .submit-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(16,185,129,.5); }

        .report-btn {
            padding: 10px 18px;
            background: rgba(255,255,255,.10);
            color: rgba(255,255,255,.88);
            border: 1.5px solid rgba(255,255,255,.22);
            border-radius: var(--radius-sm);
            font-family: 'Inter', sans-serif; font-weight: 600; font-size: 13px;
            cursor: pointer; transition: var(--transition);
            display: flex; align-items: center; gap: 7px;
            text-decoration: none;
        }
        .report-btn:hover {
            background: rgba(239,68,68,.25);
            border-color: var(--danger);
            color: #fca5a5;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239,68,68,.25);
        }

        /* ══════════════════════════════
           LAYOUT
        ══════════════════════════════ */
        .container {
            max-width: 1380px; margin: 0 auto; padding: 28px;
            display: grid; grid-template-columns: 1fr 290px; gap: 24px;
        }

        /* ══════════════════════════════
           QUESTION SECTION
        ══════════════════════════════ */
        .question-section {
            background: var(--surface); border-radius: var(--radius);
            padding: 36px; box-shadow: var(--shadow);
            border: 1px solid var(--border); min-height: 500px;
        }

        .question-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 28px; padding-bottom: 18px;
            border-bottom: 1.5px solid var(--border);
        }

        .question-number {
            font-family: 'Sora', sans-serif;
            font-size: 14px; color: var(--text-soft); font-weight: 700;
            text-transform: uppercase; letter-spacing: .06em;
        }

        .question-marks {
            display: flex; align-items: center; gap: 7px;
            padding: 7px 14px;
            background: var(--surface2); border: 1px solid var(--border);
            border-radius: 8px;
            font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 700; color: var(--accent);
        }

        .question-text {
            font-size: 18px; color: var(--text); line-height: 1.8;
            margin-bottom: 28px; font-weight: 500;
        }

        /* ══════════════════════════════
           OPTIONS
        ══════════════════════════════ */
        .options-container { display: flex; flex-direction: column; gap: 12px; }

        .option {
            display: flex; align-items: center; padding: 18px 20px;
            border: 1.5px solid var(--border); border-radius: var(--radius-sm);
            cursor: pointer; transition: var(--transition); background: var(--surface);
        }
        .option:hover {
            border-color: var(--accent); background: #f0f9ff;
            transform: translateX(4px);
            box-shadow: 0 2px 8px rgba(14,165,233,.1);
        }
        .option.selected {
            border-color: var(--accent);
            background: linear-gradient(135deg, rgba(14,165,233,.08), rgba(6,182,212,.08));
            box-shadow: 0 0 0 3px var(--accent-glow);
        }
        .option input[type="radio"] {
            width: 18px; height: 18px; margin-right: 14px;
            cursor: pointer; accent-color: var(--accent); flex-shrink: 0;
        }
        .option-label { font-size: 15px; color: var(--text); cursor: pointer; flex: 1; line-height: 1.5; }

        /* ══════════════════════════════
           NAVIGATION
        ══════════════════════════════ */
        .navigation-controls {
            display: flex; justify-content: space-between; align-items: center;
            margin-top: 36px; padding-top: 24px;
            border-top: 1.5px solid var(--border);
        }

        .nav-btn {
            padding: 10px 24px;
            border: 1.5px solid var(--accent);
            background: var(--surface); color: var(--accent);
            border-radius: var(--radius-sm);
            font-family: 'Inter', sans-serif; font-weight: 700; font-size: 13.5px;
            cursor: pointer; transition: var(--transition);
            display: flex; align-items: center; gap: 8px;
        }
        .nav-btn:hover:not(:disabled) {
            background: var(--accent); color: white;
            transform: translateY(-2px); box-shadow: 0 4px 12px rgba(14,165,233,.3);
        }
        .nav-btn:disabled { opacity: .4; cursor: not-allowed; border-color: var(--border); color: var(--text-soft); }

        .mark-review-btn {
            padding: 10px 20px;
            background: #fefce8; color: #92400e;
            border: 1.5px solid var(--warning); border-radius: var(--radius-sm);
            font-family: 'Inter', sans-serif; font-weight: 700; font-size: 13.5px;
            cursor: pointer; transition: var(--transition);
        }
        .mark-review-btn:hover { background: var(--warning); color: white; transform: translateY(-2px); }
        .mark-review-btn.marked { background: var(--warning); color: white; }

        /* ══════════════════════════════
           SIDEBAR
        ══════════════════════════════ */
        .sidebar { display: flex; flex-direction: column; gap: 18px; }

        .sidebar-card {
            background: var(--surface); border-radius: var(--radius);
            padding: 22px; box-shadow: var(--shadow); border: 1px solid var(--border);
        }

        .sidebar-title {
            font-family: 'Sora', sans-serif;
            font-size: 15px; font-weight: 700; color: var(--text);
            margin-bottom: 18px; padding-bottom: 14px;
            border-bottom: 1.5px solid var(--border);
        }

        .status-legend { display: flex; flex-direction: column; gap: 8px; margin-bottom: 18px; }
        .legend-item { display: flex; align-items: center; gap: 10px; font-size: 12.5px; color: var(--text-mid); }
        .legend-color {
            width: 28px; height: 28px; border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            font-size: 10px; font-weight: 700; flex-shrink: 0;
        }
        .legend-color.answered     { background: #dcfce7; color: #166534; }
        .legend-color.not-answered { background: var(--surface2); color: var(--text-soft); border: 1px solid var(--border); }
        .legend-color.marked       { background: #fef3c7; color: #92400e; }
        .legend-color.current      { background: linear-gradient(135deg, var(--accent), var(--accent2)); color: white; }

        .question-palette {
            display: grid; grid-template-columns: repeat(5,1fr); gap: 8px;
        }
        .palette-item {
            aspect-ratio: 1; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-family: 'Sora', sans-serif; font-size: 12px; font-weight: 700;
            cursor: pointer; transition: var(--transition);
            border: 1.5px solid transparent;
        }
        .palette-item:hover { transform: scale(1.12); box-shadow: 0 3px 10px rgba(0,0,0,.12); }
        .palette-item.answered     { background: #dcfce7; color: #166534; }
        .palette-item.not-answered { background: var(--surface2); color: var(--text-soft); border-color: var(--border); }
        .palette-item.marked       { background: #fef3c7; color: #92400e; }
        .palette-item.current      {
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            color: white; border-color: var(--accent);
            box-shadow: 0 3px 12px rgba(14,165,233,.4);
        }

        .test-summary {
            padding: 16px; background: var(--surface2);
            border-radius: var(--radius-sm); border: 1px solid var(--border);
            margin-top: 16px;
        }
        .summary-item {
            display: flex; justify-content: space-between; align-items: center;
            padding: 9px 0; border-bottom: 1px solid var(--border);
        }
        .summary-item:last-child { border-bottom: none; padding-bottom: 0; }
        .summary-label { font-size: 12.5px; color: var(--text-soft); }
        .summary-value { font-family: 'Sora', sans-serif; font-size: 14px; font-weight: 800; color: var(--text); }

        /* ══════════════════════════════
           SUBMIT MODAL
        ══════════════════════════════ */
        .submit-modal {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.65); z-index: 1001;
            align-items: center; justify-content: center;
        }
        .submit-modal.active { display: flex; }

        .modal-content {
            background: var(--surface); border-radius: var(--radius);
            padding: 40px; max-width: 480px; width: 90%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,.25);
            animation: fadeUp .25s ease both;
        }
        .modal-icon    { font-size: 60px; margin-bottom: 18px; }
        .modal-title   { font-family: 'Sora', sans-serif; font-size: 22px; font-weight: 800; color: var(--text); margin-bottom: 14px; }
        .modal-message { font-size: 14.5px; color: var(--text-mid); margin-bottom: 28px; line-height: 1.7; }
        .modal-buttons { display: flex; gap: 12px; justify-content: center; }
        .modal-btn {
            padding: 11px 28px; border: none; border-radius: var(--radius-sm);
            font-family: 'Inter', sans-serif; font-weight: 700; font-size: 13.5px;
            cursor: pointer; transition: var(--transition);
        }
        .modal-btn.primary   { background: linear-gradient(135deg, var(--success), #34d399); color: white; box-shadow: 0 2px 8px rgba(16,185,129,.3); }
        .modal-btn.primary:hover { transform: translateY(-2px); box-shadow: 0 4px 14px rgba(16,185,129,.45); }
        .modal-btn.secondary { background: var(--surface2); color: var(--text-mid); border: 1.5px solid var(--border); }
        .modal-btn.secondary:hover { background: var(--border); color: var(--text); }
        .modal-btn:disabled  { opacity: .6; cursor: not-allowed; transform: none; }

        /* ══════════════════════════════
           LOADING OVERLAY
        ══════════════════════════════ */
        .loading-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(255,255,255,.96); z-index: 1002;
            align-items: center; justify-content: center; flex-direction: column; gap: 20px;
        }
        .loading-overlay.active { display: flex; }
        .spinner {
            width: 48px; height: 48px;
            border: 4px solid var(--border); border-top-color: var(--accent);
            border-radius: 50%; animation: spin .8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .loading-text {
            font-family: 'Sora', sans-serif;
            font-size: 15px; color: var(--text-mid); font-weight: 600;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ══════════════════════════════
           RESPONSIVE
        ══════════════════════════════ */
        @media (max-width: 1024px) {
            .container { grid-template-columns: 1fr; }
            .sidebar   { order: -1; }
            .question-palette { grid-template-columns: repeat(8,1fr); }
        }
        @media (max-width: 768px) {
            .test-header { padding: 0 16px; height: auto; min-height: var(--header-h); flex-direction: column; gap: 10px; padding: 12px 16px; }
            body { padding-top: 110px; }
            .container { padding: 16px; gap: 16px; }
            .question-section { padding: 24px 18px; }
            .navigation-controls { flex-direction: column; gap: 12px; }
            .nav-btn { width: 100%; justify-content: center; }
            .question-palette { grid-template-columns: repeat(5,1fr); }
            .test-title { max-width: 100%; font-size: 14px; }
        }
    </style>
</head>
<body>

<!-- TEST HEADER -->
<header class="test-header">
    <div class="test-info">
        <div class="test-title"><?= htmlspecialchars($attempt['title']) ?></div>
        <div class="test-badge"><?= $totalQuestions ?> Questions</div>
    </div>
    <div class="timer-section">
        <div class="timer-display" id="timer">
            ⏱️ <span id="timeLeft">--:--</span>
        </div>
        <a class="report-btn" href="help.html" target="_blank" title="Report an issue or get help">
            🚩 Report
        </a>
        <button class="submit-btn" onclick="confirmSubmit()">Submit Test</button>
    </div>
</header>

<!-- MAIN CONTAINER -->
<div class="container">

    <!-- Question Section -->
    <div class="question-section">
        <div class="question-header">
            <div class="question-number">
                Question <span id="currentQuestion">1</span> of <span id="totalQuestions"><?= $totalQuestions ?></span>
            </div>
            <div class="question-marks">
                🏆 <span id="questionMarks">-</span> marks
            </div>
        </div>

        <div class="question-text" id="questionText">Loading…</div>

        <div class="options-container" id="optionsContainer"></div>

        <div class="navigation-controls">
            <button class="nav-btn" id="prevBtn" onclick="previousQuestion()">← Previous</button>
            <button class="mark-review-btn" id="markBtn" onclick="toggleMarkForReview()">🔖 Mark for Review</button>
            <button class="nav-btn" id="nextBtn" onclick="nextQuestion()">Next →</button>
        </div>
    </div>

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-card">
            <h3 class="sidebar-title">Question Palette</h3>
            <div class="status-legend">
                <div class="legend-item">
                    <div class="legend-color answered">✓</div><span>Answered</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color not-answered">—</div><span>Not Answered</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color marked">🔖</div><span>Marked for Review</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color current">→</div><span>Current Question</span>
                </div>
            </div>
            <div class="question-palette" id="questionPalette"></div>
            <div class="test-summary">
                <div class="summary-item">
                    <span class="summary-label">Answered</span>
                    <span class="summary-value" id="answeredCount">0</span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Not Answered</span>
                    <span class="summary-value" id="notAnsweredCount"><?= $totalQuestions ?></span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Marked</span>
                    <span class="summary-value" id="markedCount">0</span>
                </div>
            </div>
        </div>
    </aside>
</div>

<!-- SUBMIT MODAL -->
<div class="submit-modal" id="submitModal">
    <div class="modal-content">
        <div class="modal-icon">⚠️</div>
        <h2 class="modal-title">Submit Test?</h2>
        <p class="modal-message">
            Are you sure you want to submit?<br>
            <strong>Answered:</strong> <span id="finalAnswered">0</span> questions<br>
            <strong>Unanswered:</strong> <span id="finalUnanswered"><?= $totalQuestions ?></span> questions<br><br>
            You cannot change your answers after submission.
        </p>
        <div class="modal-buttons">
            <button class="modal-btn secondary" id="cancelSubmitBtn" onclick="closeSubmitModal()">Cancel</button>
            <button class="modal-btn primary"   id="confirmSubmitBtn" onclick="submitTest()">Yes, Submit</button>
        </div>
    </div>
</div>

<!-- LOADING OVERLAY -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner"></div>
    <div class="loading-text" id="loadingText">Submitting your test…</div>
</div>

<script>
/* ============================================================
   DATA FROM PHP — injected server-side, no AJAX needed
   ============================================================ */
const ATTEMPT_ID     = <?= $attemptId ?>;
const TIME_REMAINING = <?= $timeRemaining ?>;
const questionData   = <?= $questionsJson ?>;

/* ============================================================
   STATE
   ============================================================ */
let currentQuestionIndex = 0;
let userAnswers          = {};
let markedQuestions      = new Set();
let timeLeft             = TIME_REMAINING;
let timerInterval        = null;
let autoSaveInterval     = null;
let isSubmitting         = false;
let csrfToken            = '';

/* Pre-populate saved answers from PHP */
<?php if (!empty($questions)): ?>
const savedLabels = <?= $savedJson ?>;
questionData.forEach(q => {
    if (savedLabels[q.id]) {
        const lbl = savedLabels[q.id];
        const opt = q.options.find(o => o.label === lbl);
        if (opt) userAnswers[q.id] = { label: lbl, option_id: opt.option_id };
    }
});
<?php endif ?>

/* ============================================================
   INIT
   ============================================================ */
window.addEventListener('load', async function () {
    try {
        const res  = await fetch('api/csrf-token.php');
        const data = await res.json();
        if (data.success && data.token) csrfToken = data.token;
    } catch (e) {
        console.warn('Could not fetch CSRF token:', e);
    }

    generateQuestionPalette();
    loadQuestion(0);
    startTimer();
    setupNavigationGuard();
    autoSaveInterval = setInterval(autoSave, 30000);
});

/* ============================================================
   QUESTION PALETTE
   ============================================================ */
function generateQuestionPalette() {
    const palette  = document.getElementById('questionPalette');
    const fragment = document.createDocumentFragment();

    questionData.forEach((q, index) => {
        const item = document.createElement('div');
        item.className   = 'palette-item not-answered';
        item.textContent = index + 1;
        item.id          = `palette-${index}`;
        item.setAttribute('data-index', index);
        item.setAttribute('role', 'button');
        item.setAttribute('tabindex', '0');
        fragment.appendChild(item);
    });

    palette.innerHTML = '';
    palette.appendChild(fragment);

    palette.addEventListener('click', e => {
        const item = e.target.closest('.palette-item');
        if (item) jumpToQuestion(parseInt(item.dataset.index));
    });
    palette.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ' ') {
            const item = e.target.closest('.palette-item');
            if (item) { e.preventDefault(); jumpToQuestion(parseInt(item.dataset.index)); }
        }
    });
}

/* ============================================================
   LOAD QUESTION
   ============================================================ */
function loadQuestion(index) {
    const q = questionData[index];
    if (!q) return;
    currentQuestionIndex = index;

    document.getElementById('questionText').textContent    = q.text;
    document.getElementById('currentQuestion').textContent = index + 1;
    document.getElementById('questionMarks').textContent   = q.marks;

    const marksEl = document.getElementById('questionMarks').parentElement;
    marksEl.title = q.negMarks > 0 ? `−${q.negMarks} for wrong answer` : 'No negative marking';

    const container = document.getElementById('optionsContainer');
    const fragment  = document.createDocumentFragment();

    q.options.forEach(opt => {
        if (!opt.text) return;
        const savedAns   = userAnswers[q.id];
        const isSelected = savedAns && savedAns.label === opt.label;

        const label   = document.createElement('label');
        label.className = `option${isSelected ? ' selected' : ''}`;
        label.setAttribute('data-option', opt.label);

        const input   = document.createElement('input');
        input.type    = 'radio';
        input.name    = 'answer';
        input.value   = opt.label;
        input.checked = isSelected;

        const span    = document.createElement('span');
        span.className = 'option-label';
        span.textContent = `${opt.label})  ${opt.text}`;

        label.appendChild(input);
        label.appendChild(span);
        fragment.appendChild(label);
    });

    container.innerHTML = '';
    container.appendChild(fragment);

    container.addEventListener('change', e => {
        if (e.target.type === 'radio') selectOption(e.target.value);
    });

    document.getElementById('prevBtn').disabled = index === 0;
    document.getElementById('nextBtn').disabled = index === questionData.length - 1;

    const markBtn = document.getElementById('markBtn');
    if (markedQuestions.has(q.id)) {
        markBtn.classList.add('marked');
        markBtn.textContent = '✓ Marked';
    } else {
        markBtn.classList.remove('marked');
        markBtn.textContent = '🔖 Mark for Review';
    }

    updatePalette();
}

/* ============================================================
   SELECT OPTION
   ============================================================ */
function selectOption(displayLabel) {
    const q   = questionData[currentQuestionIndex];
    const opt = q.options.find(o => o.label === displayLabel);
    if (!opt) return;
    userAnswers[q.id] = { label: displayLabel, option_id: opt.option_id };

    document.querySelectorAll('.option').forEach(el => {
        el.classList.toggle('selected', el.getAttribute('data-option') === displayLabel);
    });

    updateStats();
    updatePalette();
}

/* ============================================================
   NAVIGATION
   ============================================================ */
function nextQuestion()     { if (currentQuestionIndex < questionData.length - 1) loadQuestion(currentQuestionIndex + 1); }
function previousQuestion() { if (currentQuestionIndex > 0) loadQuestion(currentQuestionIndex - 1); }
function jumpToQuestion(i)  { loadQuestion(i); }

/* ============================================================
   MARK FOR REVIEW
   ============================================================ */
function toggleMarkForReview() {
    const q = questionData[currentQuestionIndex];
    if (markedQuestions.has(q.id)) markedQuestions.delete(q.id);
    else                            markedQuestions.add(q.id);
    loadQuestion(currentQuestionIndex);
    updateStats();
}

/* ============================================================
   PALETTE + STATS UPDATE
   ============================================================ */
function updatePalette() {
    questionData.forEach((q, index) => {
        const item = document.getElementById(`palette-${index}`);
        if (!item) return;
        item.className = 'palette-item';

        if (index === currentQuestionIndex)    item.classList.add('current');
        else if (markedQuestions.has(q.id))    item.classList.add('marked');
        else if (userAnswers[q.id])            item.classList.add('answered');
        else                                   item.classList.add('not-answered');
    });
}

function updateStats() {
    const answered = Object.values(userAnswers).filter(Boolean).length;
    const total    = questionData.length;
    document.getElementById('answeredCount').textContent    = answered;
    document.getElementById('notAnsweredCount').textContent = total - answered;
    document.getElementById('markedCount').textContent      = markedQuestions.size;
}

/* ============================================================
   TIMER
   ============================================================ */
function startTimer() {
    renderTimer(timeLeft);

    timerInterval = setInterval(() => {
        timeLeft--;
        renderTimer(timeLeft);

        if (timeLeft <= 300) document.getElementById('timer').classList.add('warning');

        if (timeLeft <= 0) {
            clearInterval(timerInterval);
            document.getElementById('loadingText').textContent = 'Time is up! Submitting automatically…';
            submitTest(true);
        }
    }, 1000);
}

function renderTimer(secs) {
    const m = Math.floor(secs / 60);
    const s = secs % 60;
    document.getElementById('timeLeft').textContent =
        `${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
}

/* ============================================================
   AUTO-SAVE
   ============================================================ */
async function autoSave() {
    if (isSubmitting) return;

    const answersToSend = {};
    for (const [qid, ans] of Object.entries(userAnswers)) {
        if (ans && ans.option_id) answersToSend[qid] = ans.option_id;
    }

    try {
        await fetch('api/assessment/autosave.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body:    JSON.stringify({
                attempt_id:     ATTEMPT_ID,
                answers:        answersToSend,
                time_remaining: timeLeft
            })
        });
    } catch (e) {
        console.warn('Auto-save failed:', e);
    }
}

/* ============================================================
   SUBMIT FLOW
   ============================================================ */
function confirmSubmit() {
    const answered = Object.keys(userAnswers).length;
    document.getElementById('finalAnswered').textContent   = answered;
    document.getElementById('finalUnanswered').textContent = questionData.length - answered;
    document.getElementById('submitModal').classList.add('active');
}

function closeSubmitModal() {
    if (!isSubmitting) document.getElementById('submitModal').classList.remove('active');
}

async function submitTest(autoSubmit = false) {
    if (isSubmitting) return;
    isSubmitting = true;

    clearInterval(timerInterval);
    clearInterval(autoSaveInterval);

    document.getElementById('submitModal').classList.remove('active');
    document.getElementById('loadingOverlay').classList.add('active');

    const answersToSend = {};
    for (const [qid, ans] of Object.entries(userAnswers)) {
        if (ans && ans.option_id) answersToSend[qid] = ans.option_id;
    }

    try {
        const res  = await fetch('api/assessment/submit.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body:    JSON.stringify({
                attempt_id:    ATTEMPT_ID,
                answers:       answersToSend,
                time_remaining: timeLeft,
                auto_submit:   autoSubmit
            })
        });
        const data = await res.json();

        if (data.success) {
            window.onbeforeunload = null;
            window.location.href = `test-results.php?attempt_id=${ATTEMPT_ID}`;
        } else {
            alert('Submission failed: ' + (data.error || 'Please try again.'));
            isSubmitting = false;
            document.getElementById('loadingOverlay').classList.remove('active');
        }
    } catch (err) {
        alert('Network error during submission. Please check your connection and try again.');
        isSubmitting = false;
        document.getElementById('loadingOverlay').classList.remove('active');
    }
}

/* ============================================================
   NAVIGATION GUARD
   ============================================================ */
function setupNavigationGuard() {
    window.addEventListener('beforeunload', e => {
        if (!isSubmitting) {
            e.preventDefault();
            e.returnValue = 'Your test is in progress. Leave anyway?';
            return e.returnValue;
        }
    });

    history.pushState(null, null, location.href);
    window.onpopstate = function () {
        if (!isSubmitting) {
            if (confirm('Leave the test? Your progress will be saved.')) {
                autoSave().then(() => history.back());
            } else {
                history.pushState(null, null, location.href);
            }
        }
    };

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) autoSave();
    });

    document.addEventListener('contextmenu', e => e.preventDefault());
}

/* ============================================================
   KEYBOARD SHORTCUTS
   ============================================================ */
document.addEventListener('keydown', e => {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

    if (e.key === 'ArrowLeft')  { e.preventDefault(); previousQuestion(); }
    if (e.key === 'ArrowRight') { e.preventDefault(); nextQuestion(); }

    if (e.key >= '1' && e.key <= '4') {
        const q   = questionData[currentQuestionIndex];
        const idx = parseInt(e.key) - 1;
        if (q && idx < q.options.length) {
            e.preventDefault();
            selectOption(q.options[idx].label);
            const radio = document.querySelector(`input[value="${q.options[idx].label}"]`);
            if (radio) { radio.checked = true; }
        }
    }

    if (e.key === 'm' || e.key === 'M') { e.preventDefault(); toggleMarkForReview(); }
});
</script>
</body>
</html>

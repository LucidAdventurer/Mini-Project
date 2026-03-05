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
 *  6. submitTest() calls  api/assessment/submit.php  (created below as
 *     an inline heredoc so everything ships in one file)
 *  7. autoSave() calls    api/assessment/autosave.php (same)
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
        a.show_results_immediately,

        /* Seconds elapsed since the attempt started */
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
    /* Already submitted — send straight to results */
    header("Location: test-results.php?attempt_id=$attemptId");
    exit;
}

/* ── Calculate time remaining ── */
$totalSeconds   = (int)$attempt['duration_minutes'] * 60;
$elapsedSeconds = max(0, (int)$attempt['elapsed_seconds']);
$timeRemaining  = max(0, $totalSeconds - $elapsedSeconds);

/* If timer has expired, auto-submit and redirect */
if ($timeRemaining <= 0) {
    /* Mark attempt as timeout — a proper submit will happen on results page */
    safePreparedQuery($conn,
        "UPDATE assessment_attempts
         SET status = 'timeout', submitted_at = NOW(), end_time = NOW()
         WHERE attempt_id = ? AND user_id = ? AND status = 'in_progress'",
        "ii", [$attemptId, $userId]
    );
    header("Location: api/assessment/submit.php?attempt_id=$attemptId&auto=1");
    exit;
}

/* ── Load questions ── */
$assessmentId = (int)$attempt['assessment_id'];

/* Check if randomized order was already locked for this attempt */
$lockedResult = safePreparedQuery($conn,
    "SELECT aq.question_id, aq.question_order
     FROM attempt_questions aq
     WHERE aq.attempt_id = ?
     ORDER BY aq.question_order ASC",
    "i", [$attemptId]
);

$lockedOrder = [];
if ($lockedResult['success'] && $lockedResult['result'] && $lockedResult['result']->num_rows > 0) {
    while ($row = $lockedResult['result']->fetch_assoc()) {
        $lockedOrder[(int)$row['question_order']] = (int)$row['question_id'];
    }
    $lockedResult['result']->free();
}

/* If no locked order yet, fetch questions and lock them now */
if (empty($lockedOrder)) {
    $qResult = safePreparedQuery($conn,
        "SELECT question_id FROM questions
         WHERE assessment_id = ?
         ORDER BY question_order ASC, question_id ASC",
        "i", [$assessmentId]
    );

    $qIds = [];
    if ($qResult['success'] && $qResult['result']) {
        while ($row = $qResult['result']->fetch_assoc()) {
            $qIds[] = (int)$row['question_id'];
        }
        $qResult['result']->free();
    }

    if ($attempt['randomize_questions']) {
        shuffle($qIds);
    }

    /* Lock the order into attempt_questions */
    foreach ($qIds as $pos => $qid) {
        safePreparedQuery($conn,
            "INSERT IGNORE INTO attempt_questions (attempt_id, question_id, question_order)
             VALUES (?, ?, ?)",
            "iii", [$attemptId, $qid, $pos + 1]
        );
        $lockedOrder[$pos + 1] = $qid;
    }
}

/* ── Fetch full question data in locked order ── */
if (empty($lockedOrder)) {
    header('Location: student-dashboard.php?error=no_questions');
    exit;
}

$orderedIds   = array_values($lockedOrder); // [qid, qid, ...]
$placeholders = implode(',', array_fill(0, count($orderedIds), '?'));
$types        = str_repeat('i', count($orderedIds));

$fullQResult = safePreparedQuery($conn,
    "SELECT question_id, question_type, question_text,
            option_a, option_b, option_c, option_d,
            marks, negative_marks, topic
     FROM questions
     WHERE question_id IN ($placeholders)",
    $types, $orderedIds
);

$questionsById = [];
if ($fullQResult['success'] && $fullQResult['result']) {
    while ($row = $fullQResult['result']->fetch_assoc()) {
        $questionsById[(int)$row['question_id']] = $row;
    }
    $fullQResult['result']->free();
}

/* ── Load any existing answers (resume support) ── */
$savedResult = safePreparedQuery($conn,
    "SELECT question_id, selected_answer
     FROM answers
     WHERE attempt_id = ?",
    "i", [$attemptId]
);

$savedAnswers = [];
if ($savedResult['success'] && $savedResult['result']) {
    while ($row = $savedResult['result']->fetch_assoc()) {
        $savedAnswers[(int)$row['question_id']] = $row['selected_answer'];
    }
    $savedResult['result']->free();
}

/* ── Build JS-safe question array ── */
$questions = [];
foreach ($lockedOrder as $pos => $qid) {
    if (!isset($questionsById[$qid])) continue;
    $q = $questionsById[$qid];

    /* Build options array */
    $opts = [];
    $optMap = []; /* label → text, for randomise support */
    foreach (['A' => $q['option_a'], 'B' => $q['option_b'],
               'C' => $q['option_c'], 'D' => $q['option_d']] as $lbl => $txt) {
        if ($txt !== null && $txt !== '') {
            $optMap[$lbl] = $txt;
        }
    }

    if ($attempt['randomize_options'] && count($optMap) > 1) {
        $keys = array_keys($optMap);
        shuffle($keys);
        $newMap = [];
        $labelList = ['A','B','C','D'];
        foreach ($keys as $i => $origLabel) {
            $newMap[$labelList[$i]] = [
                'text'      => $optMap[$origLabel],
                'origLabel' => $origLabel   /* we need this to grade correctly */
            ];
        }
        foreach ($newMap as $lbl => $info) {
            $opts[] = ['label' => $lbl, 'text' => $info['text'], 'origLabel' => $info['origLabel']];
        }
    } else {
        foreach ($optMap as $lbl => $txt) {
            $opts[] = ['label' => $lbl, 'text' => $txt, 'origLabel' => $lbl];
        }
    }

    $questions[] = [
        'id'           => (int)$qid,
        'pos'          => (int)$pos,          /* 1-based display number */
        'type'         => $q['question_type'],
        'text'         => $q['question_text'],
        'options'      => $opts,
        'marks'        => (int)$q['marks'],
        'negMarks'     => (float)$q['negative_marks'],
        'topic'        => $q['topic'] ?? '',
        /* NOTE: correct_answer is NOT sent to the browser */
        'savedAnswer'  => $savedAnswers[$qid] ?? null,
    ];
}

$totalQuestions = count($questions);

/* ── JSON encode for JS injection ── */
$questionsJson = json_encode($questions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP);
$savedJson     = json_encode($savedAnswers, JSON_HEX_TAG);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($attempt['title']) ?> - Assessment</title>
    <style>
        :root {
            --font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            --color-primary: #234C6A;
            --color-primary-dark: #456882;
            --color-text: #2d3748;
            --color-text-light: #718096;
            --color-border: #e2e8f0;
            --color-success: #48bb78;
            --color-warning: #ffc107;
            --transition: all 0.3s ease;
        }
        html, * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: var(--font-family);
            background: #D3DAD9;
            color: var(--color-text);
            overflow-x: hidden;
            padding-top: 71px;
        }

        /* ── Header ── */
        .test-header {
            background: var(--color-primary);
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            padding: 12px 28px;
            display: flex; justify-content: space-between; align-items: center;
            position: fixed; top:0; left:0; right:0; z-index:1000;
            border-bottom: 3px solid var(--color-primary);
        }
        .test-info { display:flex; align-items:center; gap:20px; }
        .test-title { font-size:18px; font-weight:700; color:white; }
        .test-badge {
            padding:6px 12px; background:rgba(255,255,255,0.2);
            color:white; border-radius:6px; font-size:12px; font-weight:600;
        }
        .timer-section { display:flex; align-items:center; gap:15px; }
        .timer-display {
            display:flex; align-items:center; gap:10px;
            padding:10px 20px;
            background:linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%);
            border-radius:10px; color:white; font-weight:700; font-size:18px;
            min-width:150px; justify-content:center;
        }
        .timer-display.warning {
            background:linear-gradient(135deg, #ff9800 0%, #ff5722 100%);
            animation:pulse 1s infinite;
        }
        @keyframes pulse { 0%,100%{transform:scale(1)} 50%{transform:scale(1.05)} }
        .submit-btn {
            padding:10px 30px;
            background:linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color:white; border:none; border-radius:10px;
            font-weight:700; font-size:14px; cursor:pointer;
            transition:var(--transition);
            box-shadow:0 4px 15px rgba(72,187,120,0.3);
        }
        .submit-btn:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(72,187,120,0.4); }

        /* ── Layout ── */
        .container {
            max-width:1400px; margin:0 auto; padding:30px;
            display:grid; grid-template-columns:1fr 300px; gap:30px;
        }

        /* ── Question Area ── */
        .question-section {
            background:white; border-radius:20px; padding:40px;
            box-shadow:0 4px 20px rgba(0,0,0,0.08); min-height:500px;
        }
        .question-header {
            display:flex; justify-content:space-between; align-items:center;
            margin-bottom:30px; padding-bottom:20px; border-bottom:2px solid #e2e8f0;
        }
        .question-number { font-size:16px; color:#718096; font-weight:600; }
        .question-marks {
            display:flex; align-items:center; gap:8px;
            padding:8px 16px; background:#f7fafc; border-radius:8px;
            font-size:14px; font-weight:600; color:var(--color-primary);
        }
        .question-text {
            font-size:20px; color:#2d3748; line-height:1.8;
            margin-bottom:30px; font-weight:500;
        }

        /* ── Options ── */
        .options-container { display:flex; flex-direction:column; gap:15px; }
        .option {
            display:flex; align-items:center; padding:20px;
            border:2px solid #e2e8f0; border-radius:12px;
            cursor:pointer; transition:var(--transition); background:white;
        }
        .option:hover { border-color:var(--color-primary); background:#f7fafc; transform:translateX(5px); }
        .option.selected {
            border-color:#4facfe;
            background:linear-gradient(135deg, rgba(79,172,254,0.1), rgba(0,242,254,0.1));
            box-shadow:0 4px 15px rgba(79,172,254,0.2);
        }
        .option input[type="radio"] {
            width:20px; height:20px; margin-right:15px; cursor:pointer; accent-color:var(--color-primary);
        }
        .option-label { font-size:16px; color:#2d3748; cursor:pointer; flex:1; }

        /* ── Navigation ── */
        .navigation-controls {
            display:flex; justify-content:space-between;
            margin-top:40px; padding-top:30px; border-top:2px solid #e2e8f0;
        }
        .nav-btn {
            padding:12px 30px; border:2px solid var(--color-primary);
            background:white; color:#4facfe; border-radius:10px;
            font-weight:700; font-size:14px; cursor:pointer;
            transition:var(--transition);
            display:flex; align-items:center; gap:8px;
        }
        .nav-btn:hover:not(:disabled) {
            background:var(--color-primary); color:white;
            transform:translateY(-2px); box-shadow:0 4px 12px rgba(79,172,254,0.3);
        }
        .nav-btn:disabled { opacity:0.5; cursor:not-allowed; }
        .mark-review-btn {
            padding:12px 24px; background:#fff3cd; color:#856404;
            border:2px solid #ffc107; border-radius:10px;
            font-weight:700; font-size:14px; cursor:pointer; transition:var(--transition);
        }
        .mark-review-btn:hover { background:#ffc107; color:white; transform:translateY(-2px); }
        .mark-review-btn.marked { background:#ffc107; color:white; }

        /* ── Sidebar ── */
        .sidebar { display:flex; flex-direction:column; gap:20px; }
        .sidebar-card {
            background:white; border-radius:20px; padding:25px;
            box-shadow:0 4px 20px rgba(0,0,0,0.08);
        }
        .sidebar-title { font-size:18px; font-weight:700; color:#2d3748; margin-bottom:20px; }
        .status-legend { display:grid; grid-template-columns:1fr; gap:10px; margin-bottom:20px; }
        .legend-item { display:flex; align-items:center; gap:10px; font-size:13px; color:#718096; }
        .legend-color {
            width:30px; height:30px; border-radius:6px;
            display:flex; align-items:center; justify-content:center;
            font-size:11px; font-weight:700;
        }
        .legend-color.answered   { background:#c6f6d5; color:#22543d; }
        .legend-color.not-answered { background:#e2e8f0; color:#718096; }
        .legend-color.marked     { background:#feebc8; color:#7c2d12; }
        .legend-color.current    { background:linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color:white; }
        .question-palette { display:grid; grid-template-columns:repeat(5,1fr); gap:10px; }
        .palette-item {
            aspect-ratio:1; border-radius:8px;
            display:flex; align-items:center; justify-content:center;
            font-size:14px; font-weight:700; cursor:pointer;
            transition:var(--transition); border:2px solid transparent;
        }
        .palette-item:hover { transform:scale(1.1); box-shadow:0 4px 12px rgba(0,0,0,0.15); }
        .palette-item.answered   { background:#c6f6d5; color:#22543d; }
        .palette-item.not-answered { background:#e2e8f0; color:#718096; }
        .palette-item.marked     { background:#feebc8; color:#7c2d12; }
        .palette-item.current    {
            background:linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color:white; border-color:#4facfe; box-shadow:0 4px 15px rgba(79,172,254,0.4);
        }
        .test-summary { padding:20px; background:#f7fafc; border-radius:12px; margin-top:15px; }
        .summary-item {
            display:flex; justify-content:space-between;
            padding:10px 0; border-bottom:1px solid #e2e8f0;
        }
        .summary-item:last-child { border-bottom:none; }
        .summary-label { font-size:13px; color:#718096; }
        .summary-value { font-size:14px; font-weight:700; color:#2d3748; }

        /* ── Submit Modal ── */
        .submit-modal {
            display:none; position:fixed; top:0; left:0; width:100%; height:100%;
            background:rgba(0,0,0,0.7); z-index:1001;
            align-items:center; justify-content:center;
        }
        .submit-modal.active { display:flex; }
        .modal-content {
            background:white; border-radius:20px; padding:40px; max-width:500px;
            text-align:center; box-shadow:0 20px 60px rgba(0,0,0,0.3);
        }
        .modal-icon    { font-size:64px; margin-bottom:20px; }
        .modal-title   { font-size:24px; font-weight:700; color:#2d3748; margin-bottom:15px; }
        .modal-message { font-size:16px; color:#718096; margin-bottom:30px; line-height:1.6; }
        .modal-buttons { display:flex; gap:15px; justify-content:center; }
        .modal-btn {
            padding:12px 30px; border:none; border-radius:10px;
            font-weight:700; font-size:14px; cursor:pointer; transition:var(--transition);
        }
        .modal-btn.primary   { background:linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color:white; }
        .modal-btn.secondary { background:#e2e8f0; color:#718096; }
        .modal-btn:hover     { transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,0.2); }
        .modal-btn:disabled  { opacity:0.6; cursor:not-allowed; transform:none; }

        /* ── Loading Overlay ── */
        .loading-overlay {
            display:none; position:fixed; top:0; left:0; width:100%; height:100%;
            background:rgba(255,255,255,0.95); z-index:1002;
            align-items:center; justify-content:center; flex-direction:column; gap:20px;
        }
        .loading-overlay.active { display:flex; }
        .spinner {
            width:50px; height:50px;
            border:5px solid #e2e8f0; border-top-color:var(--color-primary);
            border-radius:50%; animation:spin 0.8s linear infinite;
        }
        .loading-text { font-size:16px; color:#718096; font-weight:600; }
        @keyframes spin { to { transform:rotate(360deg); } }

        /* ── Responsive ── */
        @media (max-width:1024px) {
            .container { grid-template-columns:1fr; }
            .sidebar   { order:-1; }
            .question-palette { grid-template-columns:repeat(8,1fr); }
        }
        @media (max-width:768px) {
            .test-header { flex-direction:column; gap:15px; padding:15px; }
            .container   { padding:15px; }
            .question-section { padding:25px 20px; }
            .navigation-controls { flex-direction:column; gap:15px; }
            .nav-btn     { width:100%; justify-content:center; }
            .question-palette { grid-template-columns:repeat(5,1fr); }
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
                    <div class="legend-color answered">1</div><span>Answered</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color not-answered">2</div><span>Not Answered</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color marked">3</div><span>Marked for Review</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color current">4</div><span>Current Question</span>
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
   correct_answer is never sent here; grading is server-side
   ============================================================ */
const ATTEMPT_ID     = <?= $attemptId ?>;
const TIME_REMAINING = <?= $timeRemaining ?>;   // seconds left
const questionData   = <?= $questionsJson ?>;   // array of question objects

/* ============================================================
   STATE
   ============================================================ */
let currentQuestionIndex = 0;
let userAnswers          = {};   // { questionId: displayLabel }
let origLabelMap         = {};   // { questionId: { displayLabel: origLabel } }
let markedQuestions      = new Set();
let timeLeft             = TIME_REMAINING;
let timerInterval        = null;
let autoSaveInterval     = null;
let isSubmitting         = false;
let csrfToken            = '';

/* Pre-populate saved answers from PHP */
<?php if (!empty($savedAnswers)): ?>
userAnswers = <?= $savedJson ?>;
<?php endif ?>

/* Build origLabelMap so we always send the original option label to the server */
questionData.forEach(q => {
    origLabelMap[q.id] = {};
    q.options.forEach(opt => {
        origLabelMap[q.id][opt.label] = opt.origLabel;
    });
});

/* ============================================================
   INIT
   ============================================================ */
window.addEventListener('load', async function () {
    /* Fetch CSRF token before anything that needs to POST */
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
    autoSaveInterval = setInterval(autoSave, 30000); // every 30s
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

    /* Negative marks hint */
    const marksEl = document.getElementById('questionMarks').parentElement;
    marksEl.title = q.negMarks > 0 ? `−${q.negMarks} for wrong answer` : 'No negative marking';

    /* Build options */
    const container = document.getElementById('optionsContainer');
    const fragment  = document.createDocumentFragment();

    q.options.forEach(opt => {
        if (!opt.text) return;
        const isSelected = userAnswers[q.id] === opt.label;

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

    /* Option selection via event delegation */
    container.addEventListener('change', e => {
        if (e.target.type === 'radio') selectOption(e.target.value);
    });

    /* Prev / Next buttons */
    document.getElementById('prevBtn').disabled = index === 0;
    document.getElementById('nextBtn').disabled = index === questionData.length - 1;

    /* Mark button state */
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
    const q = questionData[currentQuestionIndex];
    userAnswers[q.id] = displayLabel;

    document.querySelectorAll('.option').forEach(opt => {
        opt.classList.toggle('selected', opt.getAttribute('data-option') === displayLabel);
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

        if (index === currentQuestionIndex)     item.classList.add('current');
        else if (markedQuestions.has(q.id))     item.classList.add('marked');
        else if (userAnswers[q.id])             item.classList.add('answered');
        else                                    item.classList.add('not-answered');
    });
}

function updateStats() {
    const answered   = Object.keys(userAnswers).length;
    const total      = questionData.length;
    document.getElementById('answeredCount').textContent    = answered;
    document.getElementById('notAnsweredCount').textContent = total - answered;
    document.getElementById('markedCount').textContent      = markedQuestions.size;
}

/* ============================================================
   TIMER — counts down from server-calculated time remaining
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
   AUTO-SAVE — POST to api/assessment/autosave.php every 30s
   Saves answers to the DB so a page refresh doesn't lose work
   ============================================================ */
async function autoSave() {
    if (isSubmitting) return;

    /* Convert display labels back to original labels before saving */
    const answersToSend = {};
    for (const [qid, displayLabel] of Object.entries(userAnswers)) {
        answersToSend[qid] = origLabelMap[qid]?.[displayLabel] ?? displayLabel;
    }

    try {
        await fetch('api/assessment/autosave.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body:    JSON.stringify({
                attempt_id:   ATTEMPT_ID,
                answers:      answersToSend,
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
    const answered   = Object.keys(userAnswers).length;
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

    /* Convert display labels → original labels */
    const answersToSend = {};
    for (const [qid, displayLabel] of Object.entries(userAnswers)) {
        answersToSend[qid] = origLabelMap[qid]?.[displayLabel] ?? displayLabel;
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
            /* Remove beforeunload guard so redirect works cleanly */
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
   NAVIGATION GUARD — prevents accidental page leave
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

    /* Tab-switch logging */
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) autoSave();
    });

    /* Block right-click */
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
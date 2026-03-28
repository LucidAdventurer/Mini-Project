<?php
/* ============================================================
 * SELF ASSESSMENT — PDF TEST
 * self-take-pdf-test.php
 * UI matches take-test.php exactly:
 *   - Sidebar with question palette + legend + stats
 *   - Submit confirmation modal
 *   - Loading overlay
 *   - Mark for Review
 *   - Keyboard shortcuts
 * ============================================================ */

require_once "config.php";
require_once "db-guard.php";

$user   = validateSession($conn, 'student');
$userId = (int) $user['user_id'];
$userName = $user['full_name'] ?? 'Student';

$imgRes = safePreparedQuery($conn, "SELECT profile_image FROM users WHERE user_id = ?", "i", [$userId]);
$userProfileImage = '';
if ($imgRes['success'] && $imgRes['result']) {
    $r = $imgRes['result']->fetch_assoc();
    $userProfileImage = $r['profile_image'] ?? '';
    $imgRes['result']->free();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$saId = (int)($_GET['sa_id'] ?? 0);
if (!$saId) { header('Location: self-assessment.php'); exit; }

$saRes = safePreparedQuery($conn,
    "SELECT * FROM self_assessments WHERE sa_id = ? AND user_id = ? AND type = 'pdf'",
    "ii", [$saId, $userId]
);
$sa = null;
if ($saRes['success'] && $saRes['result']) {
    $sa = $saRes['result']->fetch_assoc();
    $saRes['result']->free();
}
if (!$sa) { header('Location: self-assessment.php'); exit; }

if ($sa['status'] !== 'ready') {
    header('Location: self-assessment.php?error=notready');
    exit;
}

/* ── Submit handler ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_attempt') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) die('CSRF error');

    $attemptId = (int)($_POST['attempt_id'] ?? 0);
    $timeTaken = max(0, (int)($_POST['time_taken'] ?? 0));
    $answers   = $_POST['answers'] ?? [];

    if ($attemptId) {
        $mapRes = safePreparedQuery($conn,
            "SELECT map_id, correct_option FROM self_assessment_q_map WHERE sa_id = ?",
            "i", [$saId]
        );
        $maps = [];
        if ($mapRes['success'] && $mapRes['result']) {
            while ($r = $mapRes['result']->fetch_assoc()) $maps[$r['map_id']] = $r['correct_option'];
            $mapRes['result']->free();
        }

        $score = 0; $total = count($maps);
        foreach ($maps as $mapId => $correct) {
            $selected  = $answers[$mapId] ?? null;
            $isCorrect = ($selected === $correct) ? 1 : 0;
            if ($isCorrect) $score++;
            safePreparedQuery($conn,
                "INSERT INTO self_assessment_answers (attempt_id, map_id, selected_option, is_correct)
                 VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE selected_option=VALUES(selected_option), is_correct=VALUES(is_correct)",
                "issi", [$attemptId, $mapId, $selected, $isCorrect]
            );
        }
        $pct = $total > 0 ? round(($score / $total) * 100, 2) : 0;
        safePreparedQuery($conn,
            "UPDATE self_assessment_attempts
             SET score=?, total=?, percentage=?, time_taken_sec=?, status='submitted', submitted_at=NOW()
             WHERE attempt_id=? AND user_id=?",
            "iiidii", [$score, $total, $pct, $timeTaken, $attemptId, $userId]
        );
        header("Location: self-result-pdf.php?attempt=$attemptId");
        exit;
    }
}

/* ── Load questions ── */
$questions = [];
$attemptId = 0;

$limit = (int)($sa['question_limit'] ?? 0);
if ($limit <= 0) $limit = (int)($sa['total_questions'] ?? 0);
if ($limit <= 0) {
    $cntRes = safePreparedQuery($conn,
        "SELECT COUNT(*) AS cnt FROM self_assessment_q_map WHERE sa_id = ?", "i", [$saId]);
    if ($cntRes['success'] && $cntRes['result']) {
        $cntRow = $cntRes['result']->fetch_assoc();
        $limit  = (int)($cntRow['cnt'] ?? 0);
        $cntRes['result']->free();
    }
}

$qRes = safePreparedQuery($conn,
    "SELECT * FROM self_assessment_q_map WHERE sa_id = ? ORDER BY RAND() LIMIT " . max(1, $limit),
    "i", [$saId]
);
if ($qRes['success'] && $qRes['result']) {
    while ($r = $qRes['result']->fetch_assoc()) $questions[] = $r;
    $qRes['result']->free();
}

if (empty($questions)) { header('Location: self-assessment.php?error=noquestions'); exit; }

$ains = safePreparedQuery($conn,
    "INSERT INTO self_assessment_attempts (sa_id, user_id, type, status) VALUES (?, ?, 'pdf', 'in_progress')",
    "ii", [$saId, $userId]
);
if ($ains['success']) $attemptId = $conn->insert_id;

$durationSec    = (int)$sa['duration_minutes'] * 60;
$totalQuestions = count($questions);

/* Build JS question array */
$jsQ = [];
foreach ($questions as $q) {
    $opts = [];
    $map  = [['option_a','a','A'],['option_b','b','B'],['option_c','c','C'],['option_d','d','D']];
    foreach ($map as [$col,$val,$lbl]) {
        if (!empty($q[$col])) $opts[] = ['label'=>$lbl,'value'=>$val,'text'=>$q[$col]];
    }
    $jsQ[] = ['map_id'=>$q['map_id'], 'text'=>$q['question_text'], 'options'=>$opts];
}
$questionsJson = json_encode($jsQ, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($sa['title']) ?> — PDF Self Test</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary:     #1a3a52;
            --primary-mid: #234C6A;
            --accent:      #0ea5e9;
            --accent-glow: rgba(14,165,233,.18);
            --accent2:     #06b6d4;
            --success:     #10b981;
            --warning:     #f59e0b;
            --danger:      #ef4444;
            --bg:          #f0f4f8;
            --surface:     #ffffff;
            --surface2:    #f8fafc;
            --border:      #e2e8f0;
            --text:        #0f172a;
            --text-mid:    #475569;
            --text-soft:   #94a3b8;
            --radius:      16px;
            --radius-sm:   10px;
            --shadow:      0 1px 3px rgba(0,0,0,.06), 0 4px 16px rgba(0,0,0,.06);
            --header-h:    68px;
            --transition:  .2s cubic-bezier(.4,0,.2,1);
        }
        html,*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);overflow-x:hidden;padding-top:var(--header-h);-webkit-font-smoothing:antialiased}

        /* HEADER */
        .test-header{background:var(--primary);padding:0 28px;height:var(--header-h);display:flex;justify-content:space-between;align-items:center;position:fixed;top:0;left:0;right:0;z-index:1000;box-shadow:0 1px 0 rgba(255,255,255,.06),0 4px 20px rgba(0,0,0,.18)}
        .test-info{display:flex;align-items:center;gap:16px}
        .test-title{font-family:'Sora',sans-serif;font-size:16px;font-weight:800;color:white;letter-spacing:-.2px;max-width:360px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .test-badge{padding:5px 12px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:rgba(255,255,255,.9);border-radius:8px;font-size:12px;font-weight:600;white-space:nowrap}
        .timer-section{display:flex;align-items:center;gap:12px}
        .timer-display{display:flex;align-items:center;gap:10px;padding:10px 20px;background:rgba(255,255,255,.12);border:1.5px solid rgba(255,255,255,.2);border-radius:var(--radius-sm);color:white;font-family:'Sora',sans-serif;font-weight:800;font-size:18px;min-width:140px;justify-content:center;transition:var(--transition)}
        .timer-display.warning{background:rgba(239,68,68,.25);border-color:var(--danger);animation:pulse 1s ease-in-out infinite}
        @keyframes pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.04)}}
        .submit-btn{padding:10px 24px;background:linear-gradient(135deg,var(--success),#34d399);color:white;border:none;border-radius:var(--radius-sm);font-family:'Inter',sans-serif;font-weight:700;font-size:13.5px;cursor:pointer;transition:var(--transition);box-shadow:0 2px 8px rgba(16,185,129,.35)}
        .submit-btn:hover{transform:translateY(-2px);box-shadow:0 4px 16px rgba(16,185,129,.5)}

        /* LAYOUT */
        .container{max-width:1380px;margin:0 auto;padding:28px;display:grid;grid-template-columns:1fr 290px;gap:24px}

        /* QUESTION SECTION */
        .question-section{background:var(--surface);border-radius:var(--radius);padding:36px;box-shadow:var(--shadow);border:1px solid var(--border);min-height:500px}
        .question-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:28px;padding-bottom:18px;border-bottom:1.5px solid var(--border)}
        .question-number{font-family:'Sora',sans-serif;font-size:14px;color:var(--text-soft);font-weight:700;text-transform:uppercase;letter-spacing:.06em}
        .question-text{font-size:18px;color:var(--text);line-height:1.8;margin-bottom:28px;font-weight:500}

        /* OPTIONS */
        .options-container{display:flex;flex-direction:column;gap:12px}
        .option{display:flex;align-items:center;padding:18px 20px;border:1.5px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;transition:var(--transition);background:var(--surface)}
        .option:hover{border-color:var(--accent);background:#f0f9ff;transform:translateX(4px);box-shadow:0 2px 8px rgba(14,165,233,.1)}
        .option.selected{border-color:var(--accent);background:linear-gradient(135deg,rgba(14,165,233,.08),rgba(6,182,212,.08));box-shadow:0 0 0 3px var(--accent-glow)}
        .option input[type=radio]{width:18px;height:18px;margin-right:14px;cursor:pointer;accent-color:var(--accent);flex-shrink:0}
        .option-label{font-size:15px;color:var(--text);cursor:pointer;flex:1;line-height:1.5}

        /* NAVIGATION */
        .navigation-controls{display:flex;justify-content:space-between;align-items:center;margin-top:36px;padding-top:24px;border-top:1.5px solid var(--border)}
        .nav-btn{padding:10px 24px;border:1.5px solid var(--accent);background:var(--surface);color:var(--accent);border-radius:var(--radius-sm);font-family:'Inter',sans-serif;font-weight:700;font-size:13.5px;cursor:pointer;transition:var(--transition);display:flex;align-items:center;gap:8px}
        .nav-btn:hover:not(:disabled){background:var(--accent);color:white;transform:translateY(-2px);box-shadow:0 4px 12px rgba(14,165,233,.3)}
        .nav-btn:disabled{opacity:.4;cursor:not-allowed;border-color:var(--border);color:var(--text-soft)}
        .mark-review-btn{padding:10px 20px;background:#fefce8;color:#92400e;border:1.5px solid var(--warning);border-radius:var(--radius-sm);font-family:'Inter',sans-serif;font-weight:700;font-size:13.5px;cursor:pointer;transition:var(--transition)}
        .mark-review-btn:hover{background:var(--warning);color:white;transform:translateY(-2px)}
        .mark-review-btn.marked{background:var(--warning);color:white}

        /* SIDEBAR */
        .sidebar{display:flex;flex-direction:column;gap:18px}
        .sidebar-card{background:var(--surface);border-radius:var(--radius);padding:22px;box-shadow:var(--shadow);border:1px solid var(--border)}
        .sidebar-title{font-family:'Sora',sans-serif;font-size:15px;font-weight:700;color:var(--text);margin-bottom:18px;padding-bottom:14px;border-bottom:1.5px solid var(--border)}
        .status-legend{display:flex;flex-direction:column;gap:8px;margin-bottom:18px}
        .legend-item{display:flex;align-items:center;gap:10px;font-size:12.5px;color:var(--text-mid)}
        .legend-color{width:28px;height:28px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;flex-shrink:0}
        .legend-color.answered{background:#dcfce7;color:#166534}
        .legend-color.not-answered{background:var(--surface2);color:var(--text-soft);border:1px solid var(--border)}
        .legend-color.marked{background:#fef3c7;color:#92400e}
        .legend-color.current{background:linear-gradient(135deg,var(--accent),var(--accent2));color:white}
        .question-palette{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}
        .palette-item{aspect-ratio:1;border-radius:8px;display:flex;align-items:center;justify-content:center;font-family:'Sora',sans-serif;font-size:12px;font-weight:700;cursor:pointer;transition:var(--transition);border:1.5px solid transparent}
        .palette-item:hover{transform:scale(1.12);box-shadow:0 3px 10px rgba(0,0,0,.12)}
        .palette-item.answered{background:#dcfce7;color:#166534}
        .palette-item.not-answered{background:var(--surface2);color:var(--text-soft);border-color:var(--border)}
        .palette-item.marked{background:#fef3c7;color:#92400e}
        .palette-item.current{background:linear-gradient(135deg,var(--accent),var(--accent2));color:white;border-color:var(--accent);box-shadow:0 3px 12px rgba(14,165,233,.4)}
        .test-summary{padding:16px;background:var(--surface2);border-radius:var(--radius-sm);border:1px solid var(--border);margin-top:16px}
        .summary-item{display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:1px solid var(--border)}
        .summary-item:last-child{border-bottom:none;padding-bottom:0}
        .summary-label{font-size:12.5px;color:var(--text-soft)}
        .summary-value{font-family:'Sora',sans-serif;font-size:14px;font-weight:800;color:var(--text)}

        /* SUBMIT MODAL */
        .submit-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:1001;align-items:center;justify-content:center}
        .submit-modal.active{display:flex}
        .modal-content{background:var(--surface);border-radius:var(--radius);padding:40px;max-width:480px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.25);animation:fadeUp .25s ease both}
        .modal-icon{font-size:60px;margin-bottom:18px}
        .modal-title{font-family:'Sora',sans-serif;font-size:22px;font-weight:800;color:var(--text);margin-bottom:14px}
        .modal-message{font-size:14.5px;color:var(--text-mid);margin-bottom:28px;line-height:1.7}
        .modal-buttons{display:flex;gap:12px;justify-content:center}
        .modal-btn{padding:11px 28px;border:none;border-radius:var(--radius-sm);font-family:'Inter',sans-serif;font-weight:700;font-size:13.5px;cursor:pointer;transition:var(--transition)}
        .modal-btn.primary{background:linear-gradient(135deg,var(--success),#34d399);color:white;box-shadow:0 2px 8px rgba(16,185,129,.3)}
        .modal-btn.primary:hover{transform:translateY(-2px);box-shadow:0 4px 14px rgba(16,185,129,.45)}
        .modal-btn.secondary{background:var(--surface2);color:var(--text-mid);border:1.5px solid var(--border)}
        .modal-btn.secondary:hover{background:var(--border);color:var(--text)}
        .modal-btn:disabled{opacity:.6;cursor:not-allowed;transform:none}

        /* LOADING OVERLAY */
        .loading-overlay{display:none;position:fixed;inset:0;background:rgba(255,255,255,.96);z-index:1002;align-items:center;justify-content:center;flex-direction:column;gap:20px}
        .loading-overlay.active{display:flex}
        .spinner{width:48px;height:48px;border:4px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:spin .8s linear infinite}
        @keyframes spin{to{transform:rotate(360deg)}}
        .loading-text{font-family:'Sora',sans-serif;font-size:15px;color:var(--text-mid);font-weight:600}
        @keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}

        /* RESPONSIVE */
        @media(max-width:1024px){
            .container{grid-template-columns:1fr}
            .sidebar{order:-1}
            .question-palette{grid-template-columns:repeat(8,1fr)}
        }
        @media(max-width:768px){
            .test-header{padding:12px 16px;height:auto;min-height:var(--header-h);flex-direction:column;gap:10px}
            body{padding-top:110px}
            .container{padding:16px;gap:16px}
            .question-section{padding:24px 18px}
            .navigation-controls{flex-direction:column;gap:12px}
            .nav-btn{width:100%;justify-content:center}
            .question-palette{grid-template-columns:repeat(5,1fr)}
            .test-title{max-width:100%;font-size:14px}
        }
    </style>
</head>
<body>

<!-- HEADER -->
<header class="test-header">
    <div class="test-info">
        <div class="test-title">📄 <?= htmlspecialchars($sa['title']) ?></div>
        <div class="test-badge"><?= $totalQuestions ?> Questions &middot; PDF Self Test</div>
    </div>
    <div class="timer-section">
        <div class="timer-display" id="timer">⏱️ <span id="timeLeft">--:--</span></div>
        <button class="submit-btn" onclick="confirmSubmit()">Submit Test</button>
    </div>
</header>

<!-- HIDDEN SUBMIT FORM -->
<form method="POST" id="testForm" style="display:none">
    <input type="hidden" name="action"     value="submit_attempt">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="attempt_id" value="<?= $attemptId ?>">
    <input type="hidden" name="time_taken" id="timeTakenInput" value="0">
    <?php foreach ($questions as $q): ?>
    <input type="hidden" name="answers[<?= $q['map_id'] ?>]" id="ans_<?= $q['map_id'] ?>" value="">
    <?php endforeach; ?>
</form>

<!-- MAIN -->
<div class="container">

    <!-- Left: Question -->
    <div class="question-section">
        <div class="question-header">
            <div class="question-number">
                Question <span id="currentQuestion">1</span> of <?= $totalQuestions ?>
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

    <!-- Right: Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-card">
            <h3 class="sidebar-title">Question Palette</h3>
            <div class="status-legend">
                <div class="legend-item"><div class="legend-color answered">✓</div><span>Answered</span></div>
                <div class="legend-item"><div class="legend-color not-answered">—</div><span>Not Answered</span></div>
                <div class="legend-item"><div class="legend-color marked">🔖</div><span>Marked for Review</span></div>
                <div class="legend-item"><div class="legend-color current">→</div><span>Current</span></div>
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
            <button class="modal-btn secondary" onclick="closeSubmitModal()">Cancel</button>
            <button class="modal-btn primary" id="confirmSubmitBtn" onclick="doSubmit()">Yes, Submit</button>
        </div>
    </div>
</div>

<!-- LOADING OVERLAY -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner"></div>
    <div class="loading-text" id="loadingText">Submitting your test…</div>
</div>

<script>
const questionData = <?= $questionsJson ?>;
const DURATION_SEC = <?= $durationSec ?>;
const TOTAL        = questionData.length;

let currentIndex  = 0;
let userAnswers   = {};   // map_id -> 'a'|'b'|'c'|'d'
let markedSet     = new Set();
let timeLeft      = DURATION_SEC;
let elapsed       = 0;
let timerInterval = null;
let isSubmitting  = false;

window.addEventListener('load', () => {
    generatePalette();
    loadQuestion(0);
    startTimer();
    setupGuard();
});

/* ── PALETTE ── */
function generatePalette() {
    const palette  = document.getElementById('questionPalette');
    const fragment = document.createDocumentFragment();
    questionData.forEach((q, i) => {
        const item         = document.createElement('div');
        item.className     = 'palette-item not-answered';
        item.textContent   = i + 1;
        item.id            = `palette-${i}`;
        item.dataset.index = i;
        item.setAttribute('role', 'button');
        item.setAttribute('tabindex', '0');
        fragment.appendChild(item);
    });
    palette.innerHTML = '';
    palette.appendChild(fragment);
    palette.addEventListener('click', e => {
        const item = e.target.closest('.palette-item');
        if (item) loadQuestion(parseInt(item.dataset.index));
    });
    palette.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ' ') {
            const item = e.target.closest('.palette-item');
            if (item) { e.preventDefault(); loadQuestion(parseInt(item.dataset.index)); }
        }
    });
}

/* ── LOAD QUESTION ── */
function loadQuestion(index) {
    const q = questionData[index];
    if (!q) return;
    currentIndex = index;

    document.getElementById('questionText').textContent    = q.text;
    document.getElementById('currentQuestion').textContent = index + 1;

    const container = document.getElementById('optionsContainer');
    const fragment  = document.createDocumentFragment();

    q.options.forEach(opt => {
        if (!opt.text) return;
        const isSelected = userAnswers[q.map_id] === opt.value;
        const label      = document.createElement('label');
        label.className       = `option${isSelected ? ' selected' : ''}`;
        label.dataset.option  = opt.label;

        const input   = document.createElement('input');
        input.type    = 'radio';
        input.name    = 'answer';
        input.value   = opt.value;
        input.checked = isSelected;

        const span         = document.createElement('span');
        span.className     = 'option-label';
        span.textContent   = `${opt.label})  ${opt.text}`;

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
    document.getElementById('nextBtn').disabled = index === TOTAL - 1;

    const markBtn = document.getElementById('markBtn');
    if (markedSet.has(q.map_id)) {
        markBtn.classList.add('marked');
        markBtn.textContent = '✓ Marked';
    } else {
        markBtn.classList.remove('marked');
        markBtn.textContent = '🔖 Mark for Review';
    }
    updatePalette();
}

/* ── SELECT OPTION ── */
function selectOption(value) {
    const q = questionData[currentIndex];
    userAnswers[q.map_id] = value;
    document.querySelectorAll('.option').forEach(el => {
        const inp = el.querySelector('input');
        el.classList.toggle('selected', inp && inp.value === value);
    });
    updateStats();
    updatePalette();
}

/* ── NAVIGATION ── */
function nextQuestion()     { if (currentIndex < TOTAL - 1) loadQuestion(currentIndex + 1); }
function previousQuestion() { if (currentIndex > 0)         loadQuestion(currentIndex - 1); }

/* ── MARK FOR REVIEW ── */
function toggleMarkForReview() {
    const q = questionData[currentIndex];
    if (markedSet.has(q.map_id)) markedSet.delete(q.map_id);
    else                          markedSet.add(q.map_id);
    loadQuestion(currentIndex);
    updateStats();
}

/* ── PALETTE + STATS ── */
function updatePalette() {
    questionData.forEach((q, i) => {
        const item = document.getElementById(`palette-${i}`);
        if (!item) return;
        item.className = 'palette-item';
        if (i === currentIndex)            item.classList.add('current');
        else if (markedSet.has(q.map_id))  item.classList.add('marked');
        else if (userAnswers[q.map_id])    item.classList.add('answered');
        else                               item.classList.add('not-answered');
    });
}
function updateStats() {
    const ans = Object.keys(userAnswers).length;
    document.getElementById('answeredCount').textContent    = ans;
    document.getElementById('notAnsweredCount').textContent = TOTAL - ans;
    document.getElementById('markedCount').textContent      = markedSet.size;
}

/* ── TIMER ── */
function startTimer() {
    renderTimer(timeLeft);
    timerInterval = setInterval(() => {
        timeLeft--; elapsed++;
        document.getElementById('timeTakenInput').value = elapsed;
        renderTimer(timeLeft);
        if (timeLeft <= 300) document.getElementById('timer').classList.add('warning');
        if (timeLeft <= 0) {
            clearInterval(timerInterval);
            document.getElementById('loadingText').textContent = 'Time is up! Submitting automatically…';
            doSubmit(true);
        }
    }, 1000);
}
function renderTimer(secs) {
    const m = Math.floor(secs / 60), s = secs % 60;
    document.getElementById('timeLeft').textContent =
        `${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
}

/* ── SUBMIT FLOW ── */
function confirmSubmit() {
    const ans = Object.keys(userAnswers).length;
    document.getElementById('finalAnswered').textContent   = ans;
    document.getElementById('finalUnanswered').textContent = TOTAL - ans;
    document.getElementById('submitModal').classList.add('active');
}
function closeSubmitModal() {
    if (!isSubmitting) document.getElementById('submitModal').classList.remove('active');
}
function doSubmit(autoSubmit = false) {
    if (isSubmitting) return;
    isSubmitting = true;
    clearInterval(timerInterval);
    document.getElementById('submitModal').classList.remove('active');
    document.getElementById('loadingOverlay').classList.add('active');
    // Write answers into hidden form
    questionData.forEach(q => {
        const inp = document.getElementById('ans_' + q.map_id);
        if (inp && userAnswers[q.map_id]) inp.value = userAnswers[q.map_id];
    });
    document.getElementById('testForm').submit();
}

/* ── GUARD ── */
function setupGuard() {
    window.addEventListener('beforeunload', e => {
        if (!isSubmitting) { e.preventDefault(); e.returnValue = 'Your test is in progress. Leave anyway?'; }
    });
    history.pushState(null, null, location.href);
    window.onpopstate = () => {
        if (!isSubmitting) {
            if (confirm('Leave the test? Your progress may be lost.')) history.back();
            else history.pushState(null, null, location.href);
        }
    };
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) document.getElementById('timeTakenInput').value = elapsed;
    });
    document.addEventListener('contextmenu', e => e.preventDefault());
}

/* ── KEYBOARD SHORTCUTS ── */
document.addEventListener('keydown', e => {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
    if (e.key === 'ArrowLeft')  { e.preventDefault(); previousQuestion(); }
    if (e.key === 'ArrowRight') { e.preventDefault(); nextQuestion(); }
    if (e.key >= '1' && e.key <= '4') {
        const q = questionData[currentIndex];
        const idx = parseInt(e.key) - 1;
        if (q && idx < q.options.length) { e.preventDefault(); selectOption(q.options[idx].value); }
    }
    if (e.key === 'm' || e.key === 'M') { e.preventDefault(); toggleMarkForReview(); }
});
</script>
</body>
</html>

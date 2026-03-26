<?php
/* ============================================================
 * SELF ASSESSMENT — PDF TEST (Setup + Take)
 * self-take-pdf-test.php
 * ?sa_id=X&setup=1  → question builder
 * ?sa_id=X          → take the test
 * ============================================================ */

require_once "config.php";
require_once "db-guard.php";

$user   = validateSession($conn, 'student');
$userId = (int) $user['user_id'];
$userName     = $user['full_name'] ?? 'Student';
$userInitials = strtoupper(substr($userName, 0, 2));

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

// Load assessment — must belong to this student
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

$isSetup = isset($_GET['setup']) && $sa['status'] === 'draft';

// ── Handle: save questions (setup mode) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_questions') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) die('CSRF error');

    $questions = $_POST['questions'] ?? [];
    if (!empty($questions)) {
        // Delete old map entries
        safePreparedQuery($conn, "DELETE FROM self_assessment_q_map WHERE sa_id = ?", "i", [$saId]);
        $order = 0;
        foreach ($questions as $q) {
            $qt  = trim($q['text']     ?? '');
            $oa  = trim($q['option_a'] ?? '');
            $ob  = trim($q['option_b'] ?? '');
            $oc  = trim($q['option_c'] ?? '');
            $od  = trim($q['option_d'] ?? '');
            $cor = $q['correct']       ?? 'a';
            $exp = trim($q['explanation'] ?? '');
            if ($qt === '' || $oa === '' || $ob === '') continue;
            safePreparedQuery($conn,
                "INSERT INTO self_assessment_q_map
                 (sa_id, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, q_order)
                 VALUES (?,?,?,?,?,?,?,?,?)",
                "isssssssi", [$saId, $qt, $oa, $ob, $oc, $od, $cor, $exp, $order]
            );
            $order++;
        }
        // Update total & mark ready
        safePreparedQuery($conn,
            "UPDATE self_assessments SET total_questions = ?, status = 'ready' WHERE sa_id = ?",
            "ii", [$order, $saId]
        );
    }
    header("Location: self-take-pdf-test.php?sa_id=$saId");
    exit;
}

// ── Handle: submit attempt ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_attempt') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) die('CSRF error');

    $attemptId  = (int)($_POST['attempt_id'] ?? 0);
    $timeTaken  = max(0, (int)($_POST['time_taken'] ?? 0));
    $answers    = $_POST['answers'] ?? [];

    if ($attemptId) {
        // Load map
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

// ── For take mode: load questions & create attempt ──
$questions = [];
$attemptId = 0;
if (!$isSetup && $sa['status'] === 'ready') {
    $qRes = safePreparedQuery($conn,
        "SELECT * FROM self_assessment_q_map WHERE sa_id = ? ORDER BY q_order ASC",
        "i", [$saId]
    );
    if ($qRes['success'] && $qRes['result']) {
        while ($r = $qRes['result']->fetch_assoc()) $questions[] = $r;
        $qRes['result']->free();
    }
    // Create attempt
    $ains = safePreparedQuery($conn,
        "INSERT INTO self_assessment_attempts (sa_id, user_id, type, status) VALUES (?, ?, 'pdf', 'in_progress')",
        "ii", [$saId, $userId]
    );
    if ($ains['success']) $attemptId = $conn->insert_id;
}

$durationSec = (int)$sa['duration_minutes'] * 60;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isSetup ? 'Setup PDF Test' : 'PDF Test' ?> — PTA Platform</title>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary:#1a3a52; --accent:#0ea5e9; --accent2:#06b6d4;
            --success:#10b981; --danger:#ef4444; --warning:#f59e0b;
            --bg:#f0f4f8; --surface:#fff; --surface2:#f8fafc;
            --border:#e2e8f0; --text:#0f172a; --text-mid:#475569; --text-soft:#94a3b8;
            --radius:14px; --shadow:0 2px 12px rgba(0,0,0,.08); --nav-h:64px;
        }
        *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;padding-top:var(--nav-h);}

        /* NAV */
        .navbar{background:var(--primary);padding:0 24px;height:var(--nav-h);display:flex;align-items:center;justify-content:space-between;position:fixed;top:0;left:0;right:0;z-index:100;box-shadow:0 2px 12px rgba(0,0,0,.2);}
        .nav-left{display:flex;align-items:center;gap:14px;}
        .nav-title{font-family:'Sora',sans-serif;font-size:15px;font-weight:700;color:white;}
        .nav-sub{font-size:12px;color:rgba(255,255,255,.6);}
        .timer-box{background:rgba(255,255,255,.12);border:1.5px solid rgba(255,255,255,.2);border-radius:10px;padding:8px 18px;font-family:'Sora',sans-serif;font-size:18px;font-weight:800;color:white;letter-spacing:.05em;}
        .timer-box.warn{background:rgba(245,158,11,.25);border-color:rgba(245,158,11,.5);color:#fcd34d;}
        .timer-box.danger{background:rgba(239,68,68,.25);border-color:rgba(239,68,68,.5);color:#fca5a5;animation:pulse .8s infinite;}
        @keyframes pulse{0%,100%{opacity:1}50%{opacity:.6}}

        .container{max-width:860px;margin:0 auto;padding:28px 20px 60px;}

        /* SETUP MODE */
        .setup-header{background:linear-gradient(135deg,var(--primary),#1e5276);border-radius:var(--radius);padding:24px 28px;margin-bottom:24px;color:white;}
        .setup-title{font-family:'Sora',sans-serif;font-size:20px;font-weight:800;margin-bottom:4px;}
        .setup-sub{font-size:13px;color:rgba(255,255,255,.7);}

        .pdf-viewer-hint{background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:14px 18px;margin-bottom:20px;font-size:13.5px;color:#1e40af;display:flex;align-items:center;gap:10px;}

        .q-builder{display:flex;flex-direction:column;gap:18px;}
        .q-card{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow);}
        .q-card-header{background:linear-gradient(135deg,#f8fafc,#f1f5f9);padding:14px 18px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);}
        .q-num{font-family:'Sora',sans-serif;font-weight:700;font-size:14px;color:var(--primary);}
        .q-card-body{padding:18px;}
        .q-input{width:100%;padding:9px 13px;border:1.5px solid var(--border);border-radius:9px;font-size:13.5px;font-family:'Inter',sans-serif;color:var(--text);transition:.2s;margin-bottom:10px;}
        .q-input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(14,165,233,.12);}
        .options-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px;}
        .option-wrap{display:flex;align-items:center;gap:8px;}
        .option-label{font-size:12px;font-weight:700;color:var(--text-soft);width:16px;flex-shrink:0;}
        .correct-row{display:flex;align-items:center;gap:10px;font-size:13px;}
        .correct-row select{padding:6px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:var(--surface);cursor:pointer;}
        .btn-remove{background:none;border:none;color:var(--danger);font-size:13px;cursor:pointer;padding:4px 8px;border-radius:6px;transition:.15s;}
        .btn-remove:hover{background:#fee2e2;}

        .add-q-btn{display:flex;align-items:center;gap:8px;justify-content:center;padding:13px;border-radius:var(--radius);border:2px dashed var(--border);background:transparent;color:var(--text-mid);font-size:14px;font-weight:600;cursor:pointer;transition:.2s;font-family:'Inter',sans-serif;width:100%;}
        .add-q-btn:hover{border-color:var(--accent);color:var(--accent);background:#f0f9ff;}

        .submit-bar{position:sticky;bottom:0;background:var(--surface);border-top:1px solid var(--border);padding:14px 0;margin-top:24px;display:flex;gap:12px;justify-content:flex-end;}
        .btn-save{padding:12px 32px;border-radius:10px;border:none;background:linear-gradient(135deg,var(--accent),var(--accent2));color:white;font-size:14px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;}
        .btn-save:hover{opacity:.9;}

        /* TAKE MODE */
        .test-progress{background:var(--surface);border-radius:var(--radius);padding:16px 20px;margin-bottom:20px;border:1px solid var(--border);box-shadow:var(--shadow);}
        .progress-bar-wrap{height:6px;background:var(--surface2);border-radius:3px;margin-top:8px;overflow:hidden;}
        .progress-bar-fill{height:100%;background:linear-gradient(90deg,var(--accent),var(--accent2));border-radius:3px;transition:.3s;}

        .question-card{background:var(--surface);border-radius:var(--radius);border:1.5px solid var(--border);padding:24px;margin-bottom:16px;box-shadow:var(--shadow);}
        .q-text{font-family:'Sora',sans-serif;font-size:16px;font-weight:600;color:var(--text);margin-bottom:18px;line-height:1.5;}
        .options-list{display:flex;flex-direction:column;gap:10px;}
        .option-item{display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:10px;border:1.5px solid var(--border);cursor:pointer;transition:.2s;}
        .option-item:hover{border-color:var(--accent);background:#f0f9ff;}
        .option-item input{display:none;}
        .option-item:has(input:checked){border-color:var(--accent);background:linear-gradient(135deg,#e0f2fe,#e0f9ff);}
        .opt-letter{width:28px;height:28px;border-radius:7px;background:var(--surface2);border:1.5px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:var(--text-mid);flex-shrink:0;transition:.2s;}
        .option-item:has(input:checked) .opt-letter{background:var(--accent);color:white;border-color:var(--accent);}
        .opt-text{font-size:14px;color:var(--text);}

        .nav-btns{display:flex;justify-content:space-between;align-items:center;margin-top:16px;}
        .btn-nav{padding:10px 22px;border-radius:9px;border:1.5px solid var(--border);background:var(--surface);color:var(--text-mid);font-size:13.5px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;transition:.15s;}
        .btn-nav:hover{background:var(--surface2);}
        .btn-nav:disabled{opacity:.4;cursor:not-allowed;}
        .btn-submit-test{padding:10px 28px;border-radius:9px;border:none;background:linear-gradient(135deg,var(--success),#059669);color:white;font-size:13.5px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;}

        .q-dots{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;}
        .q-dot{width:26px;height:26px;border-radius:6px;background:var(--surface2);border:1.5px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;color:var(--text-soft);cursor:pointer;transition:.2s;}
        .q-dot.answered{background:var(--accent);border-color:var(--accent);color:white;}
        .q-dot.current{border-color:var(--primary);color:var(--primary);}
    </style>
</head>
<body>

<nav class="navbar">
    <div class="nav-left">
        <div>
            <div class="nav-title">📄 <?= htmlspecialchars($sa['title']) ?></div>
            <div class="nav-sub"><?= $isSetup ? 'Setup — Add Questions' : 'PDF Self Test' ?></div>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:12px;">
        <?php if (!$isSetup && $sa['status'] === 'ready'): ?>
        <div class="timer-box" id="timerDisplay">
            <?= gmdate('i:s', $durationSec) ?>
        </div>
        <?php endif; ?>
        <a href="self-assessment.php" style="color:rgba(255,255,255,.7);font-size:13px;text-decoration:none;padding:8px 14px;border-radius:8px;border:1px solid rgba(255,255,255,.2);">✕ Exit</a>
    </div>
</nav>

<div class="container">

<?php if ($isSetup): ?>
<!-- ════════════════════
     SETUP MODE
════════════════════ -->
<div class="setup-header">
    <div class="setup-title">📝 Add Questions from Your PDF</div>
    <div class="setup-sub">Duration: <?= $sa['duration_minutes'] ?> min &nbsp;|&nbsp; Add as many questions as you like</div>
</div>

<?php if ($sa['pdf_path']): ?>
<div class="pdf-viewer-hint">
    📎 <span>Your PDF: <strong><?= htmlspecialchars(basename($sa['pdf_path'])) ?></strong> —
    <a href="<?= htmlspecialchars($sa['pdf_path']) ?>" target="_blank" style="color:#1e40af;">Open PDF ↗</a>
    to refer while adding questions below.</span>
</div>
<?php endif; ?>

<form method="POST" id="setupForm">
    <input type="hidden" name="action" value="save_questions">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

    <div class="q-builder" id="questionBuilder">
        <!-- Questions added by JS -->
    </div>

    <button type="button" class="add-q-btn" onclick="addQuestion()" style="margin-top:18px;">
        + Add Question
    </button>

    <div class="submit-bar">
        <a href="self-assessment.php" style="padding:12px 20px;border-radius:10px;border:1.5px solid var(--border);background:#fff;color:var(--text-mid);font-size:13.5px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;">Cancel</a>
        <button type="submit" class="btn-save" id="saveBtn" disabled>💾 Save & Start Test</button>
    </div>
</form>

<script>
let qCount = 0;
function addQuestion() {
    qCount++;
    const idx = qCount - 1;
    const div = document.createElement('div');
    div.className = 'q-card';
    div.id = 'qcard-' + idx;
    div.innerHTML = `
        <div class="q-card-header">
            <span class="q-num">Question ${qCount}</span>
            <button type="button" class="btn-remove" onclick="removeQuestion(${idx})">✕ Remove</button>
        </div>
        <div class="q-card-body">
            <input type="text" name="questions[${idx}][text]" class="q-input"
                placeholder="Enter your question here..." required oninput="checkSave()">
            <div class="options-grid">
                ${['a','b','c','d'].map(opt => `
                <div class="option-wrap">
                    <span class="option-label">${opt.toUpperCase()}.</span>
                    <input type="text" name="questions[${idx}][option_${opt}]" class="q-input"
                        placeholder="Option ${opt.toUpperCase()}" style="margin-bottom:0;"
                        ${opt==='a'||opt==='b' ? 'required' : ''} oninput="checkSave()">
                </div>`).join('')}
            </div>
            <div class="correct-row">
                <span style="font-size:13px;font-weight:600;color:var(--text-mid);">Correct Answer:</span>
                <select name="questions[${idx}][correct]">
                    <option value="a">A</option>
                    <option value="b">B</option>
                    <option value="c">C</option>
                    <option value="d">D</option>
                </select>
                <input type="text" name="questions[${idx}][explanation]" class="q-input"
                    placeholder="Explanation (optional)" style="margin-bottom:0;flex:1;">
            </div>
        </div>`;
    document.getElementById('questionBuilder').appendChild(div);
    checkSave();
}

function removeQuestion(idx) {
    const el = document.getElementById('qcard-' + idx);
    if (el) el.remove();
    checkSave();
}

function checkSave() {
    const filled = document.querySelectorAll('#questionBuilder input[name$="[text]"]');
    document.getElementById('saveBtn').disabled = filled.length === 0;
}

// Add first question on load
addQuestion();
</script>

<?php elseif ($sa['status'] === 'ready' && !empty($questions)): ?>
<!-- ════════════════════
     TAKE MODE
════════════════════ -->
<form method="POST" id="testForm">
    <input type="hidden" name="action" value="submit_attempt">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="attempt_id" value="<?= $attemptId ?>">
    <input type="hidden" name="time_taken" id="timeTakenInput" value="0">

    <!-- Progress -->
    <div class="test-progress">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <span style="font-size:13.5px;font-weight:600;color:var(--text-mid);">
                Question <span id="curQNum">1</span> of <?= count($questions) ?>
            </span>
            <span style="font-size:13px;color:var(--text-soft);" id="answeredCount">0 answered</span>
        </div>
        <div class="progress-bar-wrap">
            <div class="progress-bar-fill" id="progressFill" style="width:0%"></div>
        </div>
        <div class="q-dots" id="qDots">
            <?php foreach ($questions as $i => $q): ?>
            <div class="q-dot <?= $i===0?'current':'' ?>" id="dot-<?= $i ?>" onclick="goTo(<?= $i ?>)">
                <?= $i+1 ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Questions (shown one at a time) -->
    <?php foreach ($questions as $i => $q): ?>
    <div class="question-card" id="qcard-<?= $i ?>" style="<?= $i>0?'display:none':'' ?>">
        <div class="q-text"><?= $i+1 ?>. <?= htmlspecialchars($q['question_text']) ?></div>
        <div class="options-list">
            <?php
            $opts = [
                'a' => $q['option_a'],
                'b' => $q['option_b'],
                'c' => $q['option_c'],
                'd' => $q['option_d'],
            ];
            foreach ($opts as $letter => $text):
                if (!$text) continue;
            ?>
            <label class="option-item">
                <input type="radio" name="answers[<?= $q['map_id'] ?>]" value="<?= $letter ?>"
                    onchange="markAnswered(<?= $i ?>)">
                <div class="opt-letter"><?= strtoupper($letter) ?></div>
                <div class="opt-text"><?= htmlspecialchars($text) ?></div>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Nav buttons -->
    <div class="nav-btns">
        <button type="button" class="btn-nav" id="prevBtn" onclick="navigate(-1)" disabled>← Prev</button>
        <button type="button" class="btn-nav" id="nextBtn" onclick="navigate(1)">Next →</button>
        <button type="submit" class="btn-submit-test" id="submitBtn" style="display:none"
            onclick="return confirmSubmit()">✅ Submit Test</button>
    </div>
</form>

<script>
const total = <?= count($questions) ?>;
const durationSec = <?= $durationSec ?>;
let current = 0;
let answered = new Set();
let elapsed  = 0;

function goTo(idx) {
    document.getElementById('qcard-' + current).style.display = 'none';
    document.getElementById('dot-' + current).classList.remove('current');
    current = idx;
    document.getElementById('qcard-' + current).style.display = 'block';
    document.getElementById('dot-' + current).classList.add('current');
    document.getElementById('curQNum').textContent = current + 1;
    document.getElementById('prevBtn').disabled = current === 0;
    document.getElementById('nextBtn').style.display = current === total-1 ? 'none' : '';
    document.getElementById('submitBtn').style.display = current === total-1 ? '' : 'none';
}

function navigate(dir) { goTo(Math.max(0, Math.min(total-1, current+dir))); }

function markAnswered(idx) {
    answered.add(idx);
    document.getElementById('dot-' + idx).classList.add('answered');
    document.getElementById('answeredCount').textContent = answered.size + ' answered';
    document.getElementById('progressFill').style.width = (answered.size/total*100) + '%';
}

function confirmSubmit() {
    const unanswered = total - answered.size;
    if (unanswered > 0) {
        return confirm(`You have ${unanswered} unanswered question(s). Submit anyway?`);
    }
    return true;
}

// Timer
let timeLeft = durationSec;
const timerEl = document.getElementById('timerDisplay');
const timerInterval = setInterval(() => {
    elapsed++;
    timeLeft--;
    document.getElementById('timeTakenInput').value = elapsed;
    const m = String(Math.floor(timeLeft/60)).padStart(2,'0');
    const s = String(timeLeft%60).padStart(2,'0');
    timerEl.textContent = m + ':' + s;
    if (timeLeft <= 60)  timerEl.className = 'timer-box warn';
    if (timeLeft <= 10)  timerEl.className = 'timer-box danger';
    if (timeLeft <= 0) {
        clearInterval(timerInterval);
        alert('⏰ Time is up! Submitting your test.');
        document.getElementById('testForm').submit();
    }
}, 1000);

window.addEventListener('beforeunload', () => clearInterval(timerInterval));
</script>

<?php else: ?>
<div style="text-align:center;padding:60px 20px;">
    <div style="font-size:48px;margin-bottom:16px;">⚠️</div>
    <div style="font-size:16px;font-weight:600;color:var(--text-mid);">This test is not ready yet.</div>
    <a href="self-assessment.php" style="display:inline-block;margin-top:16px;color:var(--accent);">← Back to Dashboard</a>
</div>
<?php endif; ?>

</div>
</body>
</html>

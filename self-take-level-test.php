<?php
/* ============================================================
 * SELF ASSESSMENT — LEVEL TEST (Take)
 * self-take-level-test.php
 * ?sa_id=X
 * ============================================================ */

require_once "config.php";
require_once "db-guard.php";

$user   = validateSession($conn, 'student');
$userId = (int) $user['user_id'];
$userName     = $user['full_name'] ?? 'Student';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$saId = (int)($_GET['sa_id'] ?? 0);
if (!$saId) { header('Location: self-assessment.php'); exit; }

$saRes = safePreparedQuery($conn,
    "SELECT * FROM self_assessments WHERE sa_id = ? AND user_id = ? AND type = 'level'",
    "ii", [$saId, $userId]
);
$sa = null;
if ($saRes['success'] && $saRes['result']) {
    $sa = $saRes['result']->fetch_assoc();
    $saRes['result']->free();
}
if (!$sa || $sa['status'] !== 'ready') { header('Location: self-assessment.php'); exit; }

// ── Handle submit ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_attempt') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) die('CSRF error');

    $attemptId = (int)($_POST['attempt_id'] ?? 0);
    $timeTaken = max(0, (int)($_POST['time_taken'] ?? 0));
    $answers   = $_POST['answers'] ?? [];

    if ($attemptId) {
        // Load map + bank correct answers
        $mapRes = safePreparedQuery($conn,
            "SELECT m.map_id, COALESCE(b.correct_option, m.correct_option) AS correct_option,
                    b.difficulty
             FROM self_assessment_q_map m
             LEFT JOIN self_assessment_question_bank b ON b.question_id = m.bank_qid
             WHERE m.sa_id = ?",
            "i", [$saId]
        );
        $maps = [];
        if ($mapRes['success'] && $mapRes['result']) {
            while ($r = $mapRes['result']->fetch_assoc()) $maps[$r['map_id']] = $r;
            $mapRes['result']->free();
        }

        $score = 0; $total = count($maps);
        foreach ($maps as $mapId => $info) {
            $selected  = $answers[$mapId] ?? null;
            $isCorrect = ($selected === $info['correct_option']) ? 1 : 0;
            if ($isCorrect) $score++;
            safePreparedQuery($conn,
                "INSERT INTO self_assessment_answers (attempt_id, map_id, selected_option, is_correct)
                 VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE selected_option=VALUES(selected_option), is_correct=VALUES(is_correct)",
                "issi", [$attemptId, $mapId, $selected, $isCorrect]
            );
        }
        $pct = $total > 0 ? round(($score/$total)*100, 2) : 0;
        safePreparedQuery($conn,
            "UPDATE self_assessment_attempts
             SET score=?, total=?, percentage=?, time_taken_sec=?, levels_used=?,
                 status='submitted', submitted_at=NOW()
             WHERE attempt_id=? AND user_id=?",
            "iiidsi i", [$score, $total, $pct, $timeTaken, $sa['levels_selected'], $attemptId, $userId]
        );
        // fix types
        safePreparedQuery($conn,
            "UPDATE self_assessment_attempts
             SET score=?, total=?, percentage=?, time_taken_sec=?, levels_used=?,
                 status='submitted', submitted_at=NOW()
             WHERE attempt_id=? AND user_id=?",
            "iiidiii", [$score, $total, $pct, $timeTaken, $sa['levels_selected'], $attemptId, $userId]
        );
        header("Location: self-result-level.php?attempt=$attemptId");
        exit;
    }
}

// ── Load questions from bank via map ──
$questions = [];
$qRes = safePreparedQuery($conn,
    "SELECT m.map_id, m.q_order,
            COALESCE(b.question_text, m.question_text) AS question_text,
            COALESCE(b.option_a, m.option_a) AS option_a,
            COALESCE(b.option_b, m.option_b) AS option_b,
            COALESCE(b.option_c, m.option_c) AS option_c,
            COALESCE(b.option_d, m.option_d) AS option_d,
            COALESCE(b.difficulty, m.difficulty) AS difficulty
     FROM self_assessment_q_map m
     LEFT JOIN self_assessment_question_bank b ON b.question_id = m.bank_qid
     WHERE m.sa_id = ?
     ORDER BY m.q_order ASC",
    "i", [$saId]
);
if ($qRes['success'] && $qRes['result']) {
    while ($r = $qRes['result']->fetch_assoc()) $questions[] = $r;
    $qRes['result']->free();
}

// Create attempt
$attemptId = 0;
$ains = safePreparedQuery($conn,
    "INSERT INTO self_assessment_attempts (sa_id, user_id, type, levels_used, status)
     VALUES (?, ?, 'level', ?, 'in_progress')",
    "iis", [$saId, $userId, $sa['levels_selected']]
);
if ($ains['success']) $attemptId = $conn->insert_id;

$durationSec = (int)$sa['duration_minutes'] * 60;
$levelsArr   = array_map('ucfirst', explode(',', $sa['levels_selected'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Level Test — PTA Platform</title>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root{--primary:#1a3a52;--accent:#0ea5e9;--accent2:#06b6d4;--success:#10b981;--danger:#ef4444;--warning:#f59e0b;--bg:#f0f4f8;--surface:#fff;--surface2:#f8fafc;--border:#e2e8f0;--text:#0f172a;--text-mid:#475569;--text-soft:#94a3b8;--radius:14px;--shadow:0 2px 12px rgba(0,0,0,.08);--nav-h:64px;}
        *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;padding-top:var(--nav-h);}
        .navbar{background:var(--primary);padding:0 24px;height:var(--nav-h);display:flex;align-items:center;justify-content:space-between;position:fixed;top:0;left:0;right:0;z-index:100;box-shadow:0 2px 12px rgba(0,0,0,.2);}
        .nav-title{font-family:'Sora',sans-serif;font-size:15px;font-weight:700;color:white;}
        .nav-sub{font-size:12px;color:rgba(255,255,255,.6);}
        .timer-box{background:rgba(255,255,255,.12);border:1.5px solid rgba(255,255,255,.2);border-radius:10px;padding:8px 18px;font-family:'Sora',sans-serif;font-size:18px;font-weight:800;color:white;letter-spacing:.05em;}
        .timer-box.warn{background:rgba(245,158,11,.25);border-color:rgba(245,158,11,.5);color:#fcd34d;}
        .timer-box.danger{background:rgba(239,68,68,.25);border-color:rgba(239,68,68,.5);color:#fca5a5;animation:pulse .8s infinite;}
        @keyframes pulse{0%,100%{opacity:1}50%{opacity:.6}}

        .diff-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11.5px;font-weight:700;}
        .diff-easy{background:#dcfce7;color:#166534;}
        .diff-medium{background:#fef3c7;color:#92400e;}
        .diff-hard{background:#fee2e2;color:#991b1b;}

        .container{max-width:820px;margin:0 auto;padding:28px 20px 60px;}

        .test-progress{background:var(--surface);border-radius:var(--radius);padding:16px 20px;margin-bottom:20px;border:1px solid var(--border);box-shadow:var(--shadow);}
        .progress-bar-wrap{height:6px;background:var(--surface2);border-radius:3px;margin-top:8px;overflow:hidden;}
        .progress-bar-fill{height:100%;background:linear-gradient(90deg,var(--accent),var(--accent2));border-radius:3px;transition:.3s;}
        .q-dots{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;}
        .q-dot{width:28px;height:28px;border-radius:7px;background:var(--surface2);border:1.5px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;color:var(--text-soft);cursor:pointer;transition:.2s;}
        .q-dot.answered{background:var(--accent);border-color:var(--accent);color:white;}
        .q-dot.current{border-color:var(--primary);color:var(--primary);font-weight:700;}

        .question-card{background:var(--surface);border-radius:var(--radius);border:1.5px solid var(--border);padding:24px;margin-bottom:16px;box-shadow:var(--shadow);}
        .q-meta{display:flex;align-items:center;gap:8px;margin-bottom:14px;}
        .q-text{font-family:'Sora',sans-serif;font-size:15.5px;font-weight:600;color:var(--text);line-height:1.55;margin-bottom:18px;}
        .options-list{display:flex;flex-direction:column;gap:10px;}
        .option-item{display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:10px;border:1.5px solid var(--border);cursor:pointer;transition:.2s;}
        .option-item:hover{border-color:var(--accent);background:#f0f9ff;}
        .option-item input{display:none;}
        .option-item:has(input:checked){border-color:var(--accent);background:linear-gradient(135deg,#e0f2fe,#e0f9ff);}
        .opt-letter{width:30px;height:30px;border-radius:8px;background:var(--surface2);border:1.5px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:var(--text-mid);flex-shrink:0;transition:.2s;}
        .option-item:has(input:checked) .opt-letter{background:var(--accent);color:white;border-color:var(--accent);}
        .opt-text{font-size:14px;color:var(--text);}

        .nav-btns{display:flex;justify-content:space-between;align-items:center;margin-top:16px;}
        .btn-nav{padding:10px 22px;border-radius:9px;border:1.5px solid var(--border);background:var(--surface);color:var(--text-mid);font-size:13.5px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;transition:.15s;}
        .btn-nav:hover:not(:disabled){background:var(--surface2);}
        .btn-nav:disabled{opacity:.4;cursor:not-allowed;}
        .btn-submit-test{padding:10px 28px;border-radius:9px;border:none;background:linear-gradient(135deg,var(--success),#059669);color:white;font-size:13.5px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;}
    </style>
</head>
<body>

<nav class="navbar">
    <div>
        <div class="nav-title">🎯 Level Test &nbsp;
            <?php foreach($levelsArr as $lv): ?>
            <span class="diff-badge diff-<?= strtolower($lv) ?>"><?= htmlspecialchars($lv) ?></span>
            <?php endforeach; ?>
        </div>
        <div class="nav-sub"><?= count($questions) ?> Questions &nbsp;|&nbsp; <?= $sa['duration_minutes'] ?> min</div>
    </div>
    <div style="display:flex;align-items:center;gap:12px;">
        <div class="timer-box" id="timerDisplay"><?= gmdate('i:s', $durationSec) ?></div>
        <a href="self-assessment.php" style="color:rgba(255,255,255,.7);font-size:13px;text-decoration:none;padding:8px 14px;border-radius:8px;border:1px solid rgba(255,255,255,.2);"
           onclick="return confirm('Exit test? Progress will be lost.')">✕ Exit</a>
    </div>
</nav>

<div class="container">

<?php if (empty($questions)): ?>
<div style="text-align:center;padding:60px;">
    <div style="font-size:40px;margin-bottom:12px;">😕</div>
    <div style="font-size:15px;color:var(--text-mid);">No questions found for this level. Please try again.</div>
    <a href="self-assessment.php" style="display:inline-block;margin-top:14px;color:var(--accent);">← Back</a>
</div>
<?php else: ?>

<form method="POST" id="testForm">
    <input type="hidden" name="action" value="submit_attempt">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="attempt_id" value="<?= $attemptId ?>">
    <input type="hidden" name="time_taken" id="timeTakenInput" value="0">

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
            <div class="q-dot <?= $i===0?'current':'' ?>" id="dot-<?= $i ?>" onclick="goTo(<?= $i ?>)"><?= $i+1 ?></div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php foreach ($questions as $i => $q): ?>
    <div class="question-card" id="qcard-<?= $i ?>" style="<?= $i>0?'display:none':'' ?>">
        <div class="q-meta">
            <span style="font-size:12px;font-weight:600;color:var(--text-soft);">Q<?= $i+1 ?></span>
            <?php if ($q['difficulty']): ?>
            <span class="diff-badge diff-<?= $q['difficulty'] ?>"><?= ucfirst($q['difficulty']) ?></span>
            <?php endif; ?>
        </div>
        <div class="q-text"><?= htmlspecialchars($q['question_text']) ?></div>
        <div class="options-list">
            <?php foreach (['a','b','c','d'] as $letter):
                $optText = $q['option_' . $letter] ?? '';
                if (!$optText) continue;
            ?>
            <label class="option-item">
                <input type="radio" name="answers[<?= $q['map_id'] ?>]" value="<?= $letter ?>"
                    onchange="markAnswered(<?= $i ?>)">
                <div class="opt-letter"><?= strtoupper($letter) ?></div>
                <div class="opt-text"><?= htmlspecialchars($optText) ?></div>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

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
let current = 0, elapsed = 0, answered = new Set();

function goTo(idx) {
    document.getElementById('qcard-'+current).style.display = 'none';
    document.getElementById('dot-'+current).classList.remove('current');
    current = idx;
    document.getElementById('qcard-'+current).style.display = 'block';
    document.getElementById('dot-'+current).classList.add('current');
    document.getElementById('curQNum').textContent = current+1;
    document.getElementById('prevBtn').disabled = current===0;
    document.getElementById('nextBtn').style.display = current===total-1?'none':'';
    document.getElementById('submitBtn').style.display = current===total-1?'':'none';
}
function navigate(d){ goTo(Math.max(0,Math.min(total-1,current+d))); }
function markAnswered(i){
    answered.add(i);
    document.getElementById('dot-'+i).classList.add('answered');
    document.getElementById('answeredCount').textContent = answered.size+' answered';
    document.getElementById('progressFill').style.width=(answered.size/total*100)+'%';
}
function confirmSubmit(){
    const u=total-answered.size;
    return u>0?confirm(`${u} question(s) unanswered. Submit anyway?`):true;
}
let timeLeft=durationSec;
const timerEl=document.getElementById('timerDisplay');
const iv=setInterval(()=>{
    elapsed++; timeLeft--;
    document.getElementById('timeTakenInput').value=elapsed;
    timerEl.textContent=String(Math.floor(timeLeft/60)).padStart(2,'0')+':'+String(timeLeft%60).padStart(2,'0');
    if(timeLeft<=60) timerEl.className='timer-box warn';
    if(timeLeft<=10) timerEl.className='timer-box danger';
    if(timeLeft<=0){ clearInterval(iv); alert('⏰ Time up! Submitting.'); document.getElementById('testForm').submit(); }
},1000);
window.addEventListener('beforeunload',()=>clearInterval(iv));
</script>
<?php endif; ?>
</div>
</body>
</html>

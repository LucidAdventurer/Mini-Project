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
            "iiidsii", [$score, $total, $pct, $timeTaken, $sa['levels_selected'], $attemptId, $userId]
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
    $grouped = [];
    while ($r = $qRes['result']->fetch_assoc()) $grouped[$r['difficulty']][] = $r;
    $qRes['result']->free();
    foreach ($grouped as $grp) foreach ($grp as $q) $questions[] = $q;
    shuffle($questions);
    foreach ($questions as $i => &$q) $q['q_order'] = $i;
    unset($q);
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
            min-height: 100vh;
            padding-top: var(--header-h);
            -webkit-font-smoothing: antialiased;
        }

        /* ══ HEADER ══ */
        .test-header {
            background: var(--primary);
            padding: 0 28px;
            height: var(--header-h);
            display: flex; justify-content: space-between; align-items: center;
            position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
            box-shadow: 0 1px 0 rgba(255,255,255,.06), 0 4px 20px rgba(0,0,0,.18);
        }

        /* ══ BRAND (left) ══ */
        .header-left { display: flex; align-items: center; gap: 16px; }

        .brand { display: flex; align-items: center; gap: 11px; text-decoration: none; flex-shrink: 0; }
        .brand-logo {
            width: 42px; height: 42px; border-radius: 10px;
            object-fit: contain; background: white; padding: 3px;
            flex-shrink: 0;
        }
        .brand-text { display: flex; flex-direction: column; line-height: 1.2; }
        .brand-name {
            font-family: 'Sora', sans-serif; font-size: 16px; font-weight: 800;
            color: white; letter-spacing: .4px;
        }
        .brand-sub { font-size: 10.5px; font-weight: 400; color: rgba(255,255,255,.6); letter-spacing: .02em; }

        .header-divider { width: 1px; height: 30px; background: rgba(255,255,255,.15); flex-shrink: 0; }

        /* ══ CENTER TEST INFO ══ */
        .test-info { display: flex; align-items: center; gap: 10px; flex: 1; padding: 0 20px; }

        .test-title {
            font-family: 'Sora', sans-serif;
            font-size: 15px; font-weight: 700; color: white;
            letter-spacing: -.1px;
        }

        .test-badge {
            padding: 4px 11px;
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.2);
            color: rgba(255,255,255,.85); border-radius: 8px;
            font-size: 12px; font-weight: 600; white-space: nowrap;
        }

        /* ══ RIGHT (timer + back btn) ══ */
        .header-right { display: flex; align-items: center; gap: 12px; }

        .back-btn {
            display: flex; align-items: center; gap: 6px;
            color: rgba(255,255,255,.75); font-size: 13px; font-weight: 600;
            text-decoration: none; padding: 8px 14px;
            border: 1px solid rgba(255,255,255,.2); border-radius: 8px;
            transition: var(--transition); white-space: nowrap;
        }
        .back-btn:hover { background: rgba(255,255,255,.12); color: white; }

        .timer-display {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 20px;
            background: rgba(255,255,255,.12);
            border: 1.5px solid rgba(255,255,255,.2);
            border-radius: var(--radius-sm);
            color: white;
            font-family: 'Sora', sans-serif; font-weight: 800; font-size: 18px;
            min-width: 120px; justify-content: center;
            transition: var(--transition);
        }
        .timer-display.warn  { background: rgba(245,158,11,.25); border-color: rgba(245,158,11,.5); color: #fcd34d; }
        .timer-display.danger{ background: rgba(239,68,68,.25);  border-color: var(--danger); color: #fca5a5; animation: pulse 1s ease-in-out infinite; }
        @keyframes pulse { 0%,100%{transform:scale(1)} 50%{transform:scale(1.04)} }

        .submit-btn {
            padding: 10px 24px;
            background: linear-gradient(135deg, var(--success), #34d399);
            color: white; border: none; border-radius: var(--radius-sm);
            font-family: 'Inter', sans-serif; font-weight: 700; font-size: 13.5px;
            cursor: pointer; transition: var(--transition);
            box-shadow: 0 2px 8px rgba(16,185,129,.35);
        }
        .submit-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(16,185,129,.5); }

        /* ══ DIFF BADGES ══ */
        .diff-badge { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 700; }
        .diff-easy   { background: #dcfce7; color: #166534; }
        .diff-medium { background: #fef3c7; color: #92400e; }
        .diff-hard   { background: #fee2e2; color: #991b1b; }

        .container { max-width: 820px; margin: 0 auto; padding: 28px 20px 60px; }

        .test-progress{background:var(--surface);border-radius:var(--radius);padding:16px 20px;margin-bottom:20px;border:1px solid var(--border);box-shadow:var(--shadow);}
        .progress-bar-wrap{height:6px;background:var(--surface2);border-radius:3px;margin-top:8px;overflow:hidden;}
        .progress-bar-fill{height:100%;background:linear-gradient(90deg,var(--accent),var(--accent2));border-radius:3px;transition:.3s;}
        /* ══ PROGRESS BAR ══ */
        .test-progress {
            background: var(--surface); border-radius: var(--radius);
            padding: 18px 22px; margin-bottom: 22px;
            border: 1px solid var(--border); box-shadow: var(--shadow);
        }
        .progress-bar-wrap { height: 7px; background: var(--surface2); border-radius: 4px; margin-top: 10px; overflow: hidden; }
        .progress-bar-fill { height: 100%; background: linear-gradient(90deg, var(--accent), var(--accent2)); border-radius: 4px; transition: width .3s; }

        .q-dots { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 12px; }
        .q-dot {
            width: 30px; height: 30px; border-radius: 8px;
            background: var(--surface2); border: 1.5px solid var(--border);
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 700; color: var(--text-soft);
            cursor: pointer; transition: var(--transition);
        }
        .q-dot:hover { border-color: var(--accent); color: var(--accent); transform: scale(1.1); }
        .q-dot.answered { background: #dcfce7; border-color: var(--success); color: #166534; }
        .q-dot.current  { background: linear-gradient(135deg, var(--accent), var(--accent2)); border-color: var(--accent); color: white; box-shadow: 0 2px 8px rgba(14,165,233,.35); }

        /* ══ QUESTION CARD ══ */
        .question-card {
            background: var(--surface); border-radius: var(--radius);
            border: 1px solid var(--border); padding: 32px;
            margin-bottom: 16px; box-shadow: var(--shadow);
        }
        .q-meta { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; padding-bottom: 14px; border-bottom: 1.5px solid var(--border); }
        .q-num-label { font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 700; color: var(--text-soft); text-transform: uppercase; letter-spacing: .06em; }
        .q-text { font-size: 17px; color: var(--text); line-height: 1.75; margin-bottom: 24px; font-weight: 500; }

        .options-list { display: flex; flex-direction: column; gap: 12px; }
        .option-item {
            display: flex; align-items: center; gap: 14px;
            padding: 15px 18px; border-radius: var(--radius-sm);
            border: 1.5px solid var(--border); cursor: pointer; transition: var(--transition);
            background: var(--surface);
        }
        .option-item:hover { border-color: var(--accent); background: #f0f9ff; transform: translateX(4px); box-shadow: 0 2px 8px rgba(14,165,233,.1); }
        .option-item input { display: none; }
        .option-item:has(input:checked) { border-color: var(--accent); background: linear-gradient(135deg, rgba(14,165,233,.08), rgba(6,182,212,.08)); box-shadow: 0 0 0 3px var(--accent-glow); }
        .opt-letter {
            width: 32px; height: 32px; border-radius: 8px;
            background: var(--surface2); border: 1.5px solid var(--border);
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; font-weight: 700; color: var(--text-mid); flex-shrink: 0; transition: var(--transition);
        }
        .option-item:has(input:checked) .opt-letter { background: var(--accent); color: white; border-color: var(--accent); }
        .opt-text { font-size: 14.5px; color: var(--text); line-height: 1.5; }

        /* ══ NAV BUTTONS ══ */
        .nav-btns {
            display: flex; justify-content: space-between; align-items: center;
            margin-top: 20px; padding-top: 20px; border-top: 1.5px solid var(--border);
        }
        .btn-nav {
            padding: 10px 24px; border-radius: var(--radius-sm);
            border: 1.5px solid var(--accent); background: var(--surface); color: var(--accent);
            font-family: 'Inter', sans-serif; font-weight: 700; font-size: 13.5px;
            cursor: pointer; transition: var(--transition); display: flex; align-items: center; gap: 8px;
        }
        .btn-nav:hover:not(:disabled) { background: var(--accent); color: white; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(14,165,233,.3); }
        .btn-nav:disabled { opacity: .4; cursor: not-allowed; border-color: var(--border); color: var(--text-soft); }
        .btn-submit-test {
            padding: 10px 28px; border-radius: var(--radius-sm); border: none;
            background: linear-gradient(135deg, var(--success), #34d399); color: white;
            font-family: 'Inter', sans-serif; font-weight: 700; font-size: 13.5px; cursor: pointer;
            transition: var(--transition); box-shadow: 0 2px 8px rgba(16,185,129,.35);
        }
        .btn-submit-test:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(16,185,129,.5); }

        @media (max-width: 768px) {
            .test-header { padding: 0 16px; height: auto; min-height: var(--header-h); flex-wrap: wrap; gap: 8px; padding-top: 10px; padding-bottom: 10px; }
            body { padding-top: 90px; }
            .container { padding: 16px 14px 60px; }
            .question-card { padding: 22px 16px; }
            .nav-btns { flex-wrap: wrap; gap: 10px; }
            .btn-nav, .btn-submit-test { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>

<header class="test-header">
    <!-- LEFT: Brand -->
    <div class="header-left">
        <a href="student-dashboard.php" class="brand">
            <img src="prepaura-logo.png" alt="Prepaura Logo" class="brand-logo">
            <div class="brand-text">
                <span class="brand-name">PREPAURA</span>
                <span class="brand-sub">Placement Training Platform</span>
            </div>
        </a>
        <div class="header-divider"></div>
        <!-- Test info -->
        <div class="test-info">
            <div class="test-title">🎯 Level Test</div>
            <?php foreach($levelsArr as $lv): ?>
            <span class="diff-badge diff-<?= strtolower($lv) ?>"><?= htmlspecialchars($lv) ?></span>
            <?php endforeach; ?>
            <div class="test-badge"><?= count($questions) ?> Questions &nbsp;·&nbsp; <?= $sa['duration_minutes'] ?> min</div>
        </div>
    </div>
    <!-- RIGHT: Timer + Back -->
    <div class="header-right">
        <div class="timer-display" id="timerDisplay">⏱️ <?= gmdate('i:s', $durationSec) ?></div>
        <a href="self-assessment.php" class="back-btn"
           onclick="return confirm('Exit test? Progress will be lost.')">← Self Assessment</a>
    </div>
</header>

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
            <span class="q-num-label">Question <?= $i+1 ?></span>
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
    if(timeLeft<=60) timerEl.className='timer-display warn';
    if(timeLeft<=10) timerEl.className='timer-display danger';
    if(timeLeft<=0){ clearInterval(iv); alert('⏰ Time up! Submitting.'); document.getElementById('testForm').submit(); }
},1000);
window.addEventListener('beforeunload',()=>clearInterval(iv));
</script>
<?php endif; ?>
</div>
</body>
</html>

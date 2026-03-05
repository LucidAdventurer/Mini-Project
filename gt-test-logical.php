<?php
/* ============================================================
 * LOGICAL TEST PAGE  (guest-accessible)
 * Fetches MCQ questions from the `questions` + `assessments`
 * tables where category = 'logical' and is_public = 1.
 * ============================================================ */

require_once "config.php";
require_once "db-guard.php";

// ── Optional session (guests allowed) ────────────────────────
$isLoggedIn   = false;
$userRole     = 'guest';
$userName     = '';
$userInitials = '';

if (!empty($_SESSION['user_id']) && !empty($_SESSION['user_type'])) {
    $sid   = (int)$_SESSION['user_id'];
    $stype = $_SESSION['user_type'];
    $chk   = safePreparedQuery(
        $conn,
        "SELECT user_id, full_name, user_type, is_active
         FROM users WHERE user_id = ? AND user_type = ? AND is_active = 1",
        "is", [$sid, $stype]
    );
    if ($chk['success'] && $chk['result']) {
        $row = $chk['result']->fetch_assoc();
        $chk['result']->free();
        if ($row) {
            $isLoggedIn   = true;
            $userRole     = $row['user_type'];
            $userName     = $row['full_name'];
            $userInitials = strtoupper(substr($userName, 0, 2));
        } else {
            session_destroy();
        }
    }
}

// ── Fetch active public aptitude assessments ─────────────────
$assessments = [];
$aRes = safePreparedQuery(
    $conn,
    "SELECT assessment_id, title, description, duration_minutes,
            total_marks, passing_marks, difficulty, instructions
     FROM assessments
     WHERE category = 'logical'
       AND is_public = 1
       AND status    = 'active'
     ORDER BY created_at DESC",
    "", []
);
if ($aRes['success'] && $aRes['result']) {
    while ($r = $aRes['result']->fetch_assoc()) {
        $assessments[] = $r;
    }
    $aRes['result']->free();
}

// ── Fetch questions for a chosen assessment (GET param) ───────
$questions   = [];
$activeAssmt = null;
$aid         = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($aid > 0) {
    // Verify the assessment is valid & public
    $aCheck = safePreparedQuery(
        $conn,
        "SELECT assessment_id, title, description, duration_minutes,
                total_marks, passing_marks, difficulty, instructions,
                randomize_questions, randomize_options, questions_per_attempt
         FROM assessments
         WHERE assessment_id = ? AND category = 'logical'
           AND is_public = 1 AND status = 'active'",
        "i", [$aid]
    );
    if ($aCheck['success'] && $aCheck['result']) {
        $activeAssmt = $aCheck['result']->fetch_assoc();
        $aCheck['result']->free();
    }

    if ($activeAssmt) {
        $order = $activeAssmt['randomize_questions'] ? 'RAND()' : 'question_order ASC, question_id ASC';
        $qRes  = safePreparedQuery(
            $conn,
            "SELECT question_id, question_text, question_type, marks,
                    negative_marks, option_a, option_b, option_c, option_d,
                    correct_answer, explanation, topic, difficulty
             FROM questions
             WHERE assessment_id = ? AND question_type IN ('mcq','true_false')
             ORDER BY $order",
            "i", [$aid]
        );
        if ($qRes['success'] && $qRes['result']) {
            while ($q = $qRes['result']->fetch_assoc()) {
                // Optionally shuffle options
                if ($activeAssmt['randomize_options'] && $q['question_type'] === 'mcq') {
                    $opts    = ['A'=>$q['option_a'],'B'=>$q['option_b'],'C'=>$q['option_c'],'D'=>$q['option_d']];
                    $correct = $q['correct_answer'];
                    $vals    = array_values($opts);
                    shuffle($vals);
                    $keys    = ['A','B','C','D'];
                    $newOpts = array_combine($keys, $vals);
                    // Remap correct answer
                    foreach ($newOpts as $k => $v) {
                        if ($v === $opts[$correct]) { $q['correct_answer'] = $k; break; }
                    }
                    $q['option_a'] = $newOpts['A']; $q['option_b'] = $newOpts['B'];
                    $q['option_c'] = $newOpts['C']; $q['option_d'] = $newOpts['D'];
                }
                $questions[] = $q;
            }
            $qRes['result']->free();
            // Limit questions per attempt if set
            if ($activeAssmt['questions_per_attempt'] && count($questions) > $activeAssmt['questions_per_attempt']) {
                $questions = array_slice($questions, 0, (int)$activeAssmt['questions_per_attempt']);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Logical Test – Placement & Training Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
:root {
    --ink:       #18181b; --ink-muted: #52525b; --ink-faint: #a1a1aa;
    --surface:   #ffffff; --surface-2: #fafafa; --surface-3: #f4f4f5;
    --line:      #e4e4e7; --r: 10px;
    --accent:    #15803d; --accent-bg: #f0fdf4; --accent-line: #bbf7d0;
    --serif: 'Instrument Serif', Georgia, serif;
    --sans:  'DM Sans', system-ui, sans-serif;
}
html { scroll-behavior: smooth; }
body { font-family: var(--sans); background: var(--surface); color: var(--ink);
       font-size: 15px; line-height: 1.6; -webkit-font-smoothing: antialiased; }

/* ── Navbar ── */
.navbar {
    position: sticky; top: 0; z-index: 100;
    background: rgba(255,255,255,0.92); backdrop-filter: blur(12px);
    border-bottom: 1px solid var(--line); padding: 0 28px;
    display: flex; align-items: center; justify-content: space-between; height: 58px;
}
.brand { display: flex; align-items: center; gap: 10px; text-decoration: none;
         color: var(--ink); font-weight: 600; font-size: 15px; }
.brand-mark { width: 32px; height: 32px; background: var(--ink); border-radius: 8px;
              display: grid; place-items: center; color: #fff; font-size: 13px;
              font-weight: 700; font-family: var(--serif); font-style: italic; }
.nav-right { display: flex; align-items: center; gap: 8px; }
.nav-btn { padding: 7px 16px; border-radius: 8px; font-size: 14px; font-weight: 600;
           text-decoration: none; transition: 0.15s; font-family: var(--sans); }
.nav-btn.outline { border: 1px solid var(--line); color: var(--ink); background: var(--surface); }
.nav-btn.outline:hover { background: var(--surface-3); }
.nav-btn.solid { background: var(--ink); color: #fff; border: 1px solid var(--ink); }
.nav-btn.solid:hover { background: #27272a; }

/* ── Page header ── */
.page-header { padding: 52px 28px 36px; max-width: 900px; margin: 0 auto; }
.breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 13px;
              color: var(--ink-faint); margin-bottom: 20px; flex-wrap: wrap; }
.breadcrumb a { color: var(--ink-faint); text-decoration: none; }
.breadcrumb a:hover { color: var(--ink); }
.breadcrumb-sep { font-size: 11px; }
.cat-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px;
             border-radius: 99px; background: var(--accent-bg); border: 1px solid var(--accent-line);
             font-size: 12px; font-weight: 600; color: var(--accent); margin-bottom: 14px; }
.page-title { font-family: var(--serif); font-size: clamp(28px, 5vw, 42px); font-weight: 400;
              line-height: 1.15; letter-spacing: -0.02em; margin-bottom: 10px; }
.page-sub { font-size: 15px; color: var(--ink-muted); max-width: 520px; line-height: 1.65; }

/* ── Assessment list ── */
.assmt-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px,1fr));
              gap: 14px; max-width: 900px; margin: 0 auto 60px; padding: 0 28px; }
.assmt-card { border: 1px solid var(--line); border-radius: var(--r); padding: 24px;
              background: var(--surface); transition: 0.15s; text-decoration: none; color: var(--ink); display: block; }
.assmt-card:hover { border-color: var(--accent); box-shadow: 0 4px 18px rgba(29,78,216,0.08); transform: translateY(-2px); }
.assmt-meta { display: flex; gap: 10px; flex-wrap: wrap; margin: 10px 0 16px; }
.meta-chip { display: inline-flex; align-items: center; gap: 4px; font-size: 12px;
             padding: 3px 9px; border-radius: 6px; background: var(--surface-3);
             color: var(--ink-muted); border: 1px solid var(--line); }
.diff-easy   { background: #f0fdf4; color: #15803d; border-color: #bbf7d0; }
.diff-medium { background: #fffbeb; color: #a16207; border-color: #fde68a; }
.diff-hard   { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
.assmt-title { font-weight: 600; font-size: 16px; margin-bottom: 6px; }
.assmt-desc  { font-size: 13.5px; color: var(--ink-muted); line-height: 1.5; margin-bottom: 18px; }
.btn-start { display: inline-flex; align-items: center; gap: 7px; padding: 9px 18px;
             border-radius: var(--r); background: var(--accent); color: #fff;
             font-size: 13px; font-weight: 600; text-decoration: none; transition: 0.15s; }
.btn-start:hover { background: #1e40af; }

/* ── Empty state ── */
.empty-state { text-align: center; padding: 80px 28px; max-width: 420px; margin: 0 auto; }
.empty-icon { font-size: 40px; margin-bottom: 16px; }
.empty-title { font-family: var(--serif); font-size: 22px; margin-bottom: 8px; }
.empty-sub { font-size: 14px; color: var(--ink-muted); line-height: 1.6; }

/* ── Test interface ── */
.test-layout { display: grid; grid-template-columns: 1fr 280px; gap: 24px;
               max-width: 1040px; margin: 0 auto; padding: 0 28px 60px; align-items: start; }
@media (max-width: 760px) {
    .test-layout { grid-template-columns: 1fr; }
    .sidebar { order: -1; }
}

/* Sidebar */
.sidebar { position: sticky; top: 74px; }
.sidebar-card { border: 1px solid var(--line); border-radius: var(--r); padding: 20px;
                background: var(--surface-2); }
.sidebar-title { font-size: 13px; font-weight: 600; text-transform: uppercase;
                 letter-spacing: 0.06em; color: var(--ink-faint); margin-bottom: 14px; }
.timer-display { font-family: var(--serif); font-size: 36px; color: var(--ink);
                 margin-bottom: 4px; letter-spacing: -0.02em; }
.timer-label { font-size: 12px; color: var(--ink-muted); margin-bottom: 18px; }
.timer-warning { color: #dc2626; }
.progress-bar-wrap { height: 6px; background: var(--surface-3); border-radius: 99px;
                     overflow: hidden; margin-bottom: 18px; border: 1px solid var(--line); }
.progress-bar { height: 100%; background: var(--accent); border-radius: 99px; transition: width 0.3s; }
.q-nav-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 5px; margin-bottom: 18px; }
.q-nav-btn { aspect-ratio: 1; border: 1px solid var(--line); border-radius: 6px; font-size: 11px;
             font-weight: 600; background: var(--surface); cursor: pointer;
             transition: 0.15s; color: var(--ink-muted); font-family: var(--sans); }
.q-nav-btn.answered { background: var(--accent); border-color: var(--accent); color: #fff; }
.q-nav-btn.current  { border-color: var(--ink); color: var(--ink); }
.q-nav-btn:hover    { border-color: var(--ink-muted); }
.legend { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 18px; }
.legend-item { display: flex; align-items: center; gap: 5px; font-size: 11px; color: var(--ink-muted); }
.legend-dot { width: 10px; height: 10px; border-radius: 3px; border: 1px solid; flex-shrink: 0; }
.legend-dot.answered { background: var(--accent); border-color: var(--accent); }
.legend-dot.unanswered { background: var(--surface); border-color: var(--line); }
.btn-submit { width: 100%; padding: 11px; border-radius: var(--r); background: var(--ink);
              color: #fff; font-size: 14px; font-weight: 600; border: none; cursor: pointer;
              transition: 0.15s; font-family: var(--sans); }
.btn-submit:hover { background: #27272a; }

/* Questions panel */
.questions-panel {}
.q-progress { font-size: 13px; color: var(--ink-muted); margin-bottom: 20px; }
.q-progress strong { color: var(--ink); }
.question-card { border: 1px solid var(--line); border-radius: var(--r); padding: 28px;
                 background: var(--surface); margin-bottom: 16px; display: none; }
.question-card.active { display: block; }
.q-header { display: flex; align-items: center; gap: 10px; margin-bottom: 18px; flex-wrap: wrap; }
.q-num { width: 30px; height: 30px; border-radius: 50%; background: var(--accent-bg);
         border: 1px solid var(--accent-line); display: grid; place-items: center;
         font-size: 13px; font-weight: 700; color: var(--accent); flex-shrink: 0; }
.q-topic { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em;
           color: var(--ink-faint); }
.q-marks { margin-left: auto; font-size: 12px; color: var(--ink-muted); }
.q-text { font-size: 16px; line-height: 1.65; color: var(--ink); margin-bottom: 22px; }
.options-list { display: flex; flex-direction: column; gap: 10px; }
.option-label { display: flex; align-items: flex-start; gap: 12px; padding: 14px 16px;
                border: 1px solid var(--line); border-radius: var(--r); cursor: pointer;
                transition: 0.15s; user-select: none; }
.option-label:hover { border-color: var(--accent); background: var(--accent-bg); }
.option-label input[type=radio] { display: none; }
.option-label.selected { border-color: var(--accent); background: var(--accent-bg); }
.option-key { width: 26px; height: 26px; border-radius: 50%; border: 1.5px solid var(--line);
              display: grid; place-items: center; font-size: 12px; font-weight: 700;
              color: var(--ink-muted); flex-shrink: 0; transition: 0.15s; }
.option-label.selected .option-key { background: var(--accent); border-color: var(--accent); color: #fff; }
.option-text { font-size: 14.5px; line-height: 1.55; padding-top: 2px; }

/* Result feedback (shown after correct answer revealed) */
.option-label.correct  { border-color: #16a34a; background: #f0fdf4; }
.option-label.wrong    { border-color: #dc2626; background: #fef2f2; }
.option-label.correct .option-key { background: #16a34a; border-color: #16a34a; color: #fff; }
.option-label.wrong   .option-key { background: #dc2626; border-color: #dc2626; color: #fff; }
.explanation-box { margin-top: 14px; padding: 14px 16px; background: #f0fdf4;
                   border: 1px solid #bbf7d0; border-radius: 8px; font-size: 13.5px;
                   color: #14532d; line-height: 1.55; display: none; }
.explanation-box.visible { display: block; }

/* Question nav buttons */
.q-nav-actions { display: flex; gap: 10px; margin-top: 20px; justify-content: space-between; flex-wrap: wrap; }
.btn-nav { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px;
           border-radius: var(--r); font-size: 13px; font-weight: 600;
           border: 1px solid var(--line); background: var(--surface); color: var(--ink);
           cursor: pointer; transition: 0.15s; font-family: var(--sans); }
.btn-nav:hover { background: var(--surface-3); }
.btn-nav:disabled { opacity: 0.4; cursor: not-allowed; }
.btn-nav.primary { background: var(--ink); color: #fff; border-color: var(--ink); }
.btn-nav.primary:hover { background: #27272a; }

/* Result overlay */
.result-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5);
                  z-index: 200; align-items: center; justify-content: center; padding: 20px; }
.result-overlay.show { display: flex; }
.result-modal { background: var(--surface); border-radius: 16px; padding: 40px 36px;
                max-width: 480px; width: 100%; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,0.18); }
.result-icon { font-size: 48px; margin-bottom: 16px; }
.result-title { font-family: var(--serif); font-size: 30px; margin-bottom: 8px; }
.result-score { font-size: 40px; font-weight: 700; color: var(--accent); margin-bottom: 4px; font-family: var(--serif); }
.result-detail { font-size: 14px; color: var(--ink-muted); margin-bottom: 24px; line-height: 1.65; }
.result-actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
.result-pass { color: #15803d; }
.result-fail { color: #dc2626; }

/* Footer */
.footer-outer { border-top: 1px solid var(--line); margin-top: 40px; }
footer { padding: 24px 28px; display: flex; align-items: center; justify-content: space-between;
         flex-wrap: wrap; gap: 12px; max-width: 1040px; margin: 0 auto; }
.footer-copy { font-size: 13px; color: var(--ink-faint); }
.footer-links { display: flex; gap: 16px; }
.footer-link { font-size: 13px; color: var(--ink-muted); text-decoration: none; }
.footer-link:hover { color: var(--ink); }
</style>
</head>
<body>

<!-- ── Navbar ── -->
<nav class="navbar">
    <a href="guest-dashboard.html" class="brand">
        <div class="brand-mark">PT</div>
        Placement &amp; Training
    </a>
    <div class="nav-right">
        <?php if ($isLoggedIn): ?>
            <span style="font-size:14px;color:var(--ink-muted)">👋 <?= htmlspecialchars($userInitials) ?></span>
        <?php else: ?>
            <a href="index.html" class="nav-btn outline">Log in</a>
            <a href="register.html" class="nav-btn solid">Sign up</a>
        <?php endif; ?>
    </div>
</nav>

<!-- ── Page header ── -->
<div class="page-header">
    <div class="breadcrumb">
        <a href="guest-dashboard.html">Home</a>
        <span class="breadcrumb-sep">›</span>
        <a href="home.php">Tests</a>
        <span class="breadcrumb-sep">›</span>
        <span>Aptitude</span>
        <?php if ($activeAssmt): ?>
            <span class="breadcrumb-sep">›</span>
            <span><?= htmlspecialchars($activeAssmt['title']) ?></span>
        <?php endif; ?>
    </div>
    <div class="cat-badge">🧩 Logical</div>
    <h1 class="page-title">
        <?php if ($activeAssmt): ?>
            <?= htmlspecialchars($activeAssmt['title']) ?>
        <?php else: ?>
            Logical Reasoning Tests
        <?php endif; ?>
    </h1>
    <p class="page-sub">
        <?php if ($activeAssmt): ?>
            <?= htmlspecialchars($activeAssmt['description'] ?? 'Answer all questions within the time limit.') ?>
        <?php else: ?>
            Practice patterns, sequences, puzzles and critical reasoning. Choose a test below to begin.
        <?php endif; ?>
    </p>
</div>

<?php if (!$activeAssmt && !$aid): /* ── Assessment picker ── */ ?>
<div class="assmt-grid">
    <?php if (empty($assessments)): ?>
        <div class="empty-state" style="grid-column:1/-1">
            <div class="empty-icon">📋</div>
            <div class="empty-title">No tests available yet</div>
            <p class="empty-sub">The admin hasn't published any Aptitude tests. Check back soon!</p>
        </div>
    <?php else: ?>
        <?php foreach ($assessments as $a): ?>
        <div class="assmt-card">
            <div class="assmt-meta">
                <span class="meta-chip diff-<?= $a['difficulty'] ?>"><?= ucfirst($a['difficulty']) ?></span>
                <span class="meta-chip">⏱ <?= (int)$a['duration_minutes'] ?> min</span>
                <span class="meta-chip">⭐ <?= (int)$a['total_marks'] ?> marks</span>
            </div>
            <div class="assmt-title"><?= htmlspecialchars($a['title']) ?></div>
            <div class="assmt-desc"><?= htmlspecialchars($a['description'] ?? '') ?></div>
            <a href="test-logical.php?id=<?= $a['assessment_id'] ?>" class="btn-start">
                Start Test →
            </a>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php elseif ($activeAssmt && empty($questions)): /* ── No questions yet ── */ ?>
<div class="empty-state">
    <div class="empty-icon">❓</div>
    <div class="empty-title">No questions found</div>
    <p class="empty-sub">This test doesn't have any questions added yet. Try another test.</p>
    <br><a href="test-logical.php" class="btn-start">← Back to Logical Tests</a>
</div>

<?php elseif ($activeAssmt && !empty($questions)): /* ── Test interface ── */ ?>
<div class="test-layout">
    <!-- Questions column -->
    <div class="questions-panel">
        <p class="q-progress">Question <strong id="qCurrent">1</strong> of <strong><?= count($questions) ?></strong></p>

        <?php foreach ($questions as $i => $q): ?>
        <div class="question-card <?= $i === 0 ? 'active' : '' ?>" id="qcard-<?= $i ?>" data-index="<?= $i ?>">
            <div class="q-header">
                <div class="q-num"><?= $i + 1 ?></div>
                <?php if ($q['topic']): ?>
                    <span class="q-topic"><?= htmlspecialchars($q['topic']) ?></span>
                <?php endif; ?>
                <span class="q-marks"><?= (int)$q['marks'] ?> mark<?= $q['marks']>1?'s':'' ?>
                    <?php if ((float)$q['negative_marks'] > 0): ?>
                        &nbsp;·&nbsp;<span style="color:#dc2626">-<?= $q['negative_marks'] ?></span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="q-text"><?= nl2br(htmlspecialchars($q['question_text'])) ?></div>

            <div class="options-list">
                <?php
                $opts = [];
                if ($q['question_type'] === 'true_false') {
                    $opts = ['A'=>'True','B'=>'False'];
                } else {
                    $opts = ['A'=>$q['option_a'],'B'=>$q['option_b'],'C'=>$q['option_c'],'D'=>$q['option_d']];
                }
                foreach ($opts as $key => $val):
                    if ($val === null || $val === '') continue;
                ?>
                <label class="option-label" id="opt-<?= $i ?>-<?= $key ?>">
                    <input type="radio" name="q<?= $i ?>" value="<?= $key ?>"
                           onchange="selectOption(<?= $i ?>,'<?= $key ?>')">
                    <span class="option-key"><?= $key ?></span>
                    <span class="option-text"><?= htmlspecialchars($val) ?></span>
                </label>
                <?php endforeach; ?>
            </div>

            <!-- Hidden correct answer for client-side reveal -->
            <input type="hidden" id="correct-<?= $i ?>" value="<?= htmlspecialchars($q['correct_answer']) ?>">
            <div class="explanation-box" id="expl-<?= $i ?>">
                💡 <?= htmlspecialchars($q['explanation'] ?? 'No explanation provided.') ?>
            </div>

            <div class="q-nav-actions">
                <button class="btn-nav" onclick="prevQuestion()" <?= $i===0?'disabled':'' ?> id="prevBtn-<?= $i ?>">← Prev</button>
                <?php if ($i < count($questions)-1): ?>
                    <button class="btn-nav primary" onclick="nextQuestion()">Next →</button>
                <?php else: ?>
                    <button class="btn-nav primary" onclick="submitTest()">Submit Test ✓</button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-card">
            <div class="sidebar-title">Time Remaining</div>
            <div class="timer-display" id="timerDisplay">
                <?php
                $h = floor($activeAssmt['duration_minutes']/60);
                $m = $activeAssmt['duration_minutes'] % 60;
                echo ($h > 0 ? sprintf('%02d:',$h) : '') . sprintf('%02d:00', $m);
                ?>
            </div>
            <div class="timer-label" id="timerLabel">of <?= $activeAssmt['duration_minutes'] ?> minutes</div>

            <div class="progress-bar-wrap">
                <div class="progress-bar" id="progressBar" style="width:0%"></div>
            </div>

            <div class="sidebar-title">Questions</div>
            <div class="q-nav-grid" id="qNavGrid">
                <?php for ($i=0; $i<count($questions); $i++): ?>
                <button class="q-nav-btn <?= $i===0?'current':'' ?>"
                        id="qnav-<?= $i ?>" onclick="jumpTo(<?= $i ?>)"><?= $i+1 ?></button>
                <?php endfor; ?>
            </div>
            <div class="legend">
                <div class="legend-item"><div class="legend-dot answered"></div> Answered</div>
                <div class="legend-item"><div class="legend-dot unanswered"></div> Not answered</div>
            </div>
            <button class="btn-submit" onclick="submitTest()">Submit Test</button>
        </div>
    </aside>
</div>

<!-- Result modal -->
<div class="result-overlay" id="resultOverlay">
    <div class="result-modal">
        <div class="result-icon" id="resultIcon">🎉</div>
        <div class="result-title" id="resultTitle">Test Complete!</div>
        <div class="result-score" id="resultScore"></div>
        <div class="result-detail" id="resultDetail"></div>
        <div class="result-actions">
            <a href="test-logical.php" class="btn-nav">← All Logical Tests</a>
            <button class="btn-nav primary" onclick="reviewAnswers()">Review Answers</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Footer -->
<div class="footer-outer">
    <footer>
        <span class="footer-copy">© 2025 Placement &amp; Training Portal</span>
        <div class="footer-links">
            <a href="guest-dashboard.html" class="footer-link">Home</a>
            <a href="home.php" class="footer-link">All Tests</a>
        </div>
    </footer>
</div>

<?php if ($activeAssmt && !empty($questions)): ?>
<script>
const TOTAL_Q     = <?= count($questions) ?>;
const TOTAL_MARKS = <?= (int)$activeAssmt['total_marks'] ?>;
const PASS_MARKS  = <?= (int)$activeAssmt['passing_marks'] ?>;
const SHOW_ANSWERS= <?= $activeAssmt['show_correct_answers'] ? 'true' : 'false' ?>;
const Q_MARKS     = <?= json_encode(array_column($questions, 'marks', null)) ?>;
const Q_NEG       = <?= json_encode(array_column($questions, 'negative_marks', null)) ?>;

let currentQ  = 0;
let answers   = new Array(TOTAL_Q).fill(null);  // null | 'A'|'B'|'C'|'D'
let submitted = false;
let totalSeconds = <?= $activeAssmt['duration_minutes'] * 60 ?>;
let timerInterval;

// ── Timer ──
function startTimer() {
    timerInterval = setInterval(() => {
        totalSeconds--;
        if (totalSeconds <= 0) { clearInterval(timerInterval); submitTest(true); return; }
        const h = Math.floor(totalSeconds/3600);
        const m = Math.floor((totalSeconds%3600)/60);
        const s = totalSeconds % 60;
        const display = (h>0?String(h).padStart(2,'0')+':':'') +
                        String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
        document.getElementById('timerDisplay').textContent = display;
        if (totalSeconds <= 300) {
            document.getElementById('timerDisplay').classList.add('timer-warning');
            document.getElementById('timerLabel').textContent = '⚠ Less than 5 minutes left!';
        }
    }, 1000);
}

// ── Navigation ──
function showQuestion(idx) {
    document.querySelectorAll('.question-card').forEach(c => c.classList.remove('active'));
    document.getElementById('qcard-'+idx).classList.add('active');
    document.getElementById('qCurrent').textContent = idx+1;
    // Update sidebar nav
    document.querySelectorAll('.q-nav-btn').forEach((b,i) => {
        b.classList.toggle('current', i===idx);
    });
    currentQ = idx;
}
function nextQuestion() { if (currentQ < TOTAL_Q-1) showQuestion(currentQ+1); }
function prevQuestion() { if (currentQ > 0) showQuestion(currentQ-1); }
function jumpTo(idx)    { showQuestion(idx); }

// ── Select option ──
function selectOption(qIdx, key) {
    if (submitted) return;
    answers[qIdx] = key;
    // Style options
    document.querySelectorAll(`[id^="opt-${qIdx}-"]`).forEach(el => el.classList.remove('selected'));
    document.getElementById(`opt-${qIdx}-${key}`).classList.add('selected');
    // Update sidebar nav
    const navBtn = document.getElementById(`qnav-${qIdx}`);
    navBtn.classList.add('answered');
    // Update progress bar
    const answered = answers.filter(a=>a!==null).length;
    document.getElementById('progressBar').style.width = (answered/TOTAL_Q*100)+'%';
}

// ── Submit ──
function submitTest(timeout=false) {
    if (submitted) return;
    const answered = answers.filter(a=>a!==null).length;
    if (!timeout && answered < TOTAL_Q) {
        if (!confirm(`You've answered ${answered} of ${TOTAL_Q} questions. Submit anyway?`)) return;
    }
    clearInterval(timerInterval);
    submitted = true;

    // Score
    let score = 0;
    for (let i=0; i<TOTAL_Q; i++) {
        const correct = document.getElementById('correct-'+i)?.value;
        if (answers[i] && answers[i] === correct) {
            score += parseFloat(Q_MARKS[i]||1);
        } else if (answers[i] && answers[i] !== correct) {
            score -= parseFloat(Q_NEG[i]||0);
        }
    }
    score = Math.max(0, score);
    const pct = Math.round(score/TOTAL_MARKS*100);
    const passed = score >= PASS_MARKS;

    // Reveal correct answers if enabled
    if (SHOW_ANSWERS) {
        for (let i=0; i<TOTAL_Q; i++) {
            const correct = document.getElementById('correct-'+i)?.value;
            const expl = document.getElementById('expl-'+i);
            if (answers[i]) {
                document.getElementById(`opt-${i}-${answers[i]}`)?.classList.add(answers[i]===correct?'correct':'wrong');
            }
            if (correct) document.getElementById(`opt-${i}-${correct}`)?.classList.add('correct');
            if (expl) expl.classList.add('visible');
        }
    }

    // Show result modal
    document.getElementById('resultIcon').textContent  = passed ? '🎉' : '📘';
    document.getElementById('resultTitle').textContent = passed ? 'Well done!' : 'Keep practising!';
    document.getElementById('resultScore').textContent = score + ' / ' + TOTAL_MARKS;
    document.getElementById('resultScore').className   = 'result-score ' + (passed?'result-pass':'result-fail');
    document.getElementById('resultDetail').innerHTML  =
        `You answered <strong>${answered}</strong> of <strong>${TOTAL_Q}</strong> questions.<br>
         Score: <strong>${pct}%</strong> &nbsp;·&nbsp; Passing: <strong>${PASS_MARKS}</strong> marks<br>
         ${passed ? '✅ Passed' : '❌ Did not pass — review your answers below.'}`;
    document.getElementById('resultOverlay').classList.add('show');
}

function reviewAnswers() {
    document.getElementById('resultOverlay').classList.remove('show');
    showQuestion(0);
}

startTimer();
</script>
<?php endif; ?>
</body>
</html>

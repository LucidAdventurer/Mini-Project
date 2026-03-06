<?php
/* ============================================================
 * Aptitude Test PAGE  (guest-accessible)
 * Fetches MCQ questions from the `questions` + `assessments`
 * tables where category = 'aptitude' and is_public = 1.
 *
 * SECURITY: correct_answer is NOT sent to the browser.
 * Grading is done server-side via api/guest/grade-test.php.
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
     WHERE category = 'aptitude'
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
                randomize_questions, randomize_options, questions_per_attempt,
                show_correct_answers
         FROM assessments
         WHERE assessment_id = ? AND category = 'aptitude'
           AND is_public = 1 AND status = 'active'",
        "i", [$aid]
    );
    if ($aCheck['success'] && $aCheck['result']) {
        $activeAssmt = $aCheck['result']->fetch_assoc();
        $aCheck['result']->free();
    }

    if ($activeAssmt) {
        $order = $activeAssmt['randomize_questions'] ? 'RAND()' : 'question_order ASC, question_id ASC';
        // NOTE: correct_answer is fetched here for option-shuffle remapping only.
        // It is stripped from the array before the HTML template runs — never sent to the browser.
        $qRes  = safePreparedQuery(
            $conn,
            "SELECT question_id, question_text, question_type, marks,
                    negative_marks, option_a, option_b, option_c, option_d,
                    correct_answer, explanation, topic
             FROM questions
             WHERE assessment_id = ? AND question_type IN ('mcq','true_false')
             ORDER BY $order",
            "i", [$aid]
        );
        if ($qRes['success'] && $qRes['result']) {
            while ($q = $qRes['result']->fetch_assoc()) {
                // Optionally shuffle options and remap correct_answer server-side
                if ($activeAssmt['randomize_options'] && $q['question_type'] === 'mcq') {
                    $opts    = ['A'=>$q['option_a'],'B'=>$q['option_b'],'C'=>$q['option_c'],'D'=>$q['option_d']];
                    $correct = $q['correct_answer'];
                    $vals    = array_values($opts);
                    shuffle($vals);
                    $keys    = ['A','B','C','D'];
                    $newOpts = array_combine($keys, $vals);
                    foreach ($newOpts as $k => $v) {
                        if ($v === $opts[$correct]) { $q['correct_answer'] = $k; break; }
                    }
                    $q['option_a'] = $newOpts['A']; $q['option_b'] = $newOpts['B'];
                    $q['option_c'] = $newOpts['C']; $q['option_d'] = $newOpts['D'];
                }
                // Stash the (possibly remapped) correct answer in session so
                // grade-test.php can grade against the shuffled key if needed.
                // Session key format: ca_{assessmentId}_{questionId}
                $_SESSION['ca_' . $aid . '_' . $q['question_id']] = $q['correct_answer'];
                // Strip correct_answer from the array — it must not reach the HTML
                unset($q['correct_answer']);
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
<title>Aptitude Test – Placement &amp; Training Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
:root {
    --ink:       #18181b; --ink-muted: #52525b; --ink-faint: #a1a1aa;
    --surface:   #ffffff; --surface-2: #fafafa; --surface-3: #f4f4f5;
    --line:      #e4e4e7; --r: 10px;
    --accent:    #1d4ed8; --accent-bg: #eff6ff; --accent-line: #bfdbfe;
    --serif: 'Instrument Serif', Georgia, serif;
    --sans:  'DM Sans', system-ui, sans-serif;
}
html { scroll-behavior: smooth; }
body { font-family: var(--sans); background: var(--surface); color: var(--ink);
       font-size: 15px; line-height: 1.6; -webkit-font-smoothing: antialiased; }
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
.empty-state { text-align: center; padding: 80px 28px; max-width: 420px; margin: 0 auto; }
.empty-icon { font-size: 40px; margin-bottom: 16px; }
.empty-title { font-family: var(--serif); font-size: 22px; margin-bottom: 8px; }
.empty-sub { font-size: 14px; color: var(--ink-muted); line-height: 1.6; }
.test-layout { display: grid; grid-template-columns: 1fr 280px; gap: 24px;
               max-width: 1040px; margin: 0 auto; padding: 0 28px 60px; align-items: start; }
@media (max-width: 760px) {
    .test-layout { grid-template-columns: 1fr; }
    .sidebar { order: -1; }
}
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
.btn-submit:disabled { opacity: 0.6; cursor: not-allowed; }
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
.option-label.correct  { border-color: #16a34a; background: #f0fdf4; }
.option-label.wrong    { border-color: #dc2626; background: #fef2f2; }
.option-label.correct .option-key { background: #16a34a; border-color: #16a34a; color: #fff; }
.option-label.wrong   .option-key { background: #dc2626; border-color: #dc2626; color: #fff; }
.explanation-box { margin-top: 14px; padding: 14px 16px; background: #f0fdf4;
                   border: 1px solid #bbf7d0; border-radius: 8px; font-size: 13.5px;
                   color: #14532d; line-height: 1.55; display: none; }
.explanation-box.visible { display: block; }
.q-nav-actions { display: flex; gap: 10px; margin-top: 20px; justify-content: space-between; flex-wrap: wrap; }
.btn-nav { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px;
           border-radius: var(--r); font-size: 13px; font-weight: 600;
           border: 1px solid var(--line); background: var(--surface); color: var(--ink);
           cursor: pointer; transition: 0.15s; font-family: var(--sans); }
.btn-nav:hover { background: var(--surface-3); }
.btn-nav:disabled { opacity: 0.4; cursor: not-allowed; }
.btn-nav.primary { background: var(--ink); color: #fff; border-color: var(--ink); }
.btn-nav.primary:hover { background: #27272a; }
.submitting-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.3);
                      z-index: 150; align-items: center; justify-content: center; }
.submitting-overlay.show { display: flex; }
.submitting-box { background: #fff; border-radius: 12px; padding: 32px 40px;
                  text-align: center; font-size: 15px; color: var(--ink); }
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
.result-error { color: #b45309; font-size: 13px; margin-top: 8px; }
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
    <div class="cat-badge">📐 Aptitude</div>
    <h1 class="page-title">
        <?php if ($activeAssmt): ?>
            <?= htmlspecialchars($activeAssmt['title']) ?>
        <?php else: ?>
            Quantitative Aptitude Tests
        <?php endif; ?>
    </h1>
    <p class="page-sub">
        <?php if ($activeAssmt): ?>
            <?= htmlspecialchars($activeAssmt['description'] ?? 'Answer all questions within the time limit.') ?>
        <?php else: ?>
            Practice arithmetic, percentages, ratios, algebra and more. Choose a test below to begin.
        <?php endif; ?>
    </p>
</div>

<?php if (!$activeAssmt && !$aid): ?>
<div class="assmt-grid">
    <?php if (empty($assessments)): ?>
        <div class="empty-state" style="grid-column:1/-1">
            <div class="empty-icon">📋</div>
            <div class="empty-title">No tests available yet</div>
            <p class="empty-sub">The admin hasn&#39;t published any Aptitude tests. Check back soon!</p>
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
            <a href="test-aptitude.php?id=<?= $a['assessment_id'] ?>" class="btn-start">
                Start Test →
            </a>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php elseif ($activeAssmt && empty($questions)): ?>
<div class="empty-state">
    <div class="empty-icon">❓</div>
    <div class="empty-title">No questions found</div>
    <p class="empty-sub">This test doesn't have any questions added yet. Try another test.</p>
    <br><a href="test-aptitude.php" class="btn-start">← Back to Aptitude Tests</a>
</div>

<?php elseif ($activeAssmt && !empty($questions)): ?>
<div class="test-layout">
    <div class="questions-panel">
        <p class="q-progress">Question <strong id="qCurrent">1</strong> of <strong><?= count($questions) ?></strong></p>

        <?php foreach ($questions as $i => $q): ?>
        <div class="question-card <?= $i === 0 ? 'active' : '' ?>" id="qcard-<?= $i ?>" data-qid="<?= (int)$q['question_id'] ?>">
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

            <div class="explanation-box" id="expl-<?= $i ?>">
                💡 <?= htmlspecialchars($q['explanation'] ?? 'No explanation provided.') ?>
            </div>

            <div class="q-nav-actions">
                <button class="btn-nav" onclick="prevQuestion()" <?= $i===0?'disabled':'' ?>>← Prev</button>
                <?php if ($i < count($questions)-1): ?>
                    <button class="btn-nav primary" onclick="nextQuestion()">Next →</button>
                <?php else: ?>
                    <button class="btn-nav primary" onclick="submitTest()">Submit Test ✓</button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

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
            <div class="q-nav-grid">
                <?php for ($i=0; $i<count($questions); $i++): ?>
                <button class="q-nav-btn <?= $i===0?'current':'' ?>"
                        id="qnav-<?= $i ?>" onclick="jumpTo(<?= $i ?>)"><?= $i+1 ?></button>
                <?php endfor; ?>
            </div>
            <div class="legend">
                <div class="legend-item"><div class="legend-dot answered"></div> Answered</div>
                <div class="legend-item"><div class="legend-dot unanswered"></div> Not answered</div>
            </div>
            <button class="btn-submit" id="submitBtn" onclick="submitTest()">Submit Test</button>
        </div>
    </aside>
</div>

<div class="submitting-overlay" id="submittingOverlay">
    <div class="submitting-box">⏳ Grading your answers…</div>
</div>

<div class="result-overlay" id="resultOverlay">
    <div class="result-modal">
        <div class="result-icon" id="resultIcon">🎉</div>
        <div class="result-title" id="resultTitle">Test Complete!</div>
        <div class="result-score" id="resultScore"></div>
        <div class="result-detail" id="resultDetail"></div>
        <div class="result-error" id="resultError"></div>
        <div class="result-actions">
            <a href="test-aptitude.php" class="btn-nav">← All Aptitude Tests</a>
            <button class="btn-nav primary" onclick="reviewAnswers()" id="reviewBtn" style="display:none">Review Answers</button>
        </div>
    </div>
</div>
<?php endif; ?>

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
const ASSESSMENT_ID = <?= (int)$activeAssmt['assessment_id'] ?>;
const TOTAL_Q       = <?= count($questions) ?>;
const TOTAL_MARKS   = <?= (int)$activeAssmt['total_marks'] ?>;
const PASS_MARKS    = <?= (int)$activeAssmt['passing_marks'] ?>;
// Question IDs in display order — sent to the grading endpoint
const Q_IDS = <?= json_encode(array_column($questions, 'question_id')) ?>;

let currentQ     = 0;
let answers      = new Array(TOTAL_Q).fill(null);
let submitted    = false;
let totalSeconds = <?= $activeAssmt['duration_minutes'] * 60 ?>;
let timerInterval;

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

function showQuestion(idx) {
    document.querySelectorAll('.question-card').forEach(c => c.classList.remove('active'));
    document.getElementById('qcard-'+idx).classList.add('active');
    document.getElementById('qCurrent').textContent = idx+1;
    document.querySelectorAll('.q-nav-btn').forEach((b,i) => b.classList.toggle('current', i===idx));
    currentQ = idx;
}
function nextQuestion() { if (currentQ < TOTAL_Q-1) showQuestion(currentQ+1); }
function prevQuestion() { if (currentQ > 0) showQuestion(currentQ-1); }
function jumpTo(idx)    { showQuestion(idx); }

function selectOption(qIdx, key) {
    if (submitted) return;
    answers[qIdx] = key;
    document.querySelectorAll(`[id^="opt-${qIdx}-"]`).forEach(el => el.classList.remove('selected'));
    document.getElementById(`opt-${qIdx}-${key}`).classList.add('selected');
    document.getElementById(`qnav-${qIdx}`).classList.add('answered');
    const n = answers.filter(a=>a!==null).length;
    document.getElementById('progressBar').style.width = (n/TOTAL_Q*100)+'%';
}

async function submitTest(timeout=false) {
    if (submitted) return;
    const answeredCount = answers.filter(a=>a!==null).length;
    if (!timeout && answeredCount < TOTAL_Q) {
        if (!confirm(`You've answered ${answeredCount} of ${TOTAL_Q} questions. Submit anyway?`)) return;
    }
    clearInterval(timerInterval);
    submitted = true;

    document.getElementById('submitBtn').disabled = true;
    document.querySelectorAll('.btn-nav.primary').forEach(b => b.disabled = true);
    document.getElementById('submittingOverlay').classList.add('show');

    // Build answers map: { question_id: 'A'|'B'|'C'|'D' }
    // Only include answered questions — skipped ones score zero server-side
    const answersMap = {};
    for (let i = 0; i < TOTAL_Q; i++) {
        if (answers[i] !== null) {
            answersMap[String(Q_IDS[i])] = answers[i];
        }
    }

    try {
        const resp = await fetch('api/guest/grade-test.php', {
            method : 'POST',
            headers: { 'Content-Type': 'application/json' },
            body   : JSON.stringify({ assessment_id: ASSESSMENT_ID, answers: answersMap }),
        });

        const data = await resp.json();
        document.getElementById('submittingOverlay').classList.remove('show');

        if (!data.success) {
            showError(data.error || 'Grading failed. Please try again.');
            return;
        }

        // Reveal correct/wrong highlighting if server sent results
        if (data.results) {
            for (let i = 0; i < TOTAL_Q; i++) {
                const qid    = String(Q_IDS[i]);
                const result = data.results[qid];
                if (!result) continue;

                if (!result.skipped && answers[i] !== null) {
                    document.getElementById(`opt-${i}-${answers[i]}`)
                        ?.classList.add(result.correct ? 'correct' : 'wrong');
                }
                if (result.correct_answer) {
                    document.getElementById(`opt-${i}-${result.correct_answer}`)?.classList.add('correct');
                }
                document.getElementById(`expl-${i}`)?.classList.add('visible');
            }
            document.getElementById('reviewBtn').style.display = '';
        }

        const passed = data.passed;
        document.getElementById('resultIcon').textContent  = passed ? '🎉' : '📘';
        document.getElementById('resultTitle').textContent = passed ? 'Well done!' : 'Keep practising!';
        document.getElementById('resultScore').textContent = data.score + ' / ' + data.total_marks;
        document.getElementById('resultScore').className   = 'result-score ' + (passed ? 'result-pass' : 'result-fail');
        document.getElementById('resultDetail').innerHTML  =
            `You answered <strong>${data.answered}</strong> of <strong>${data.total_q}</strong> questions.<br>
             Score: <strong>${data.pct}%</strong> &nbsp;·&nbsp; Passing: <strong>${data.passing_marks}</strong> marks<br>
             ${passed ? '✅ Passed' : '❌ Did not pass — review your answers below.'}`;
        document.getElementById('resultOverlay').classList.add('show');

    } catch (err) {
        document.getElementById('submittingOverlay').classList.remove('show');
        showError('Network error. Please check your connection and try again.');
    }
}

function showError(msg) {
    document.getElementById('resultIcon').textContent   = '⚠️';
    document.getElementById('resultTitle').textContent  = 'Something went wrong';
    document.getElementById('resultScore').textContent  = '';
    document.getElementById('resultDetail').textContent = '';
    document.getElementById('resultError').textContent  = msg;
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
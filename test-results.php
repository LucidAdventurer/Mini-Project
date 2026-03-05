<?php
// ============================================================
// test-results.php
//
// Displays the result of a completed assessment attempt.
// Requires ?attempt_id=int in the query string.
// Session must be active (student role) — validated client-side
// before the API call; the API itself re-validates server-side.
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db-guard.php';

// Validate session — redirects to login if not authenticated.
// We only need the user to be a student; the API enforces ownership.
$currentUser = validateSession($conn, 'student');

$attemptId = (int)($_GET['attempt_id'] ?? 0);
if ($attemptId <= 0) {
    header('Location: student-dashboard.php?error=invalid_attempt');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Test Results - Placement Portal">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Test Results - Placement Portal</title>
    <style>
        /* ============================================
           CSS VARIABLES
           ============================================ */
        :root {
            --font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            --color-primary: #234C6A;
            --color-primary-dark: #456882;
            --color-text: #2d3748;
            --color-text-light: #718096;
            --color-bg: #D3DAD9;
            --color-white: #ffffff;
            --color-border: #e2e8f0;
            --color-success: #48bb78;
            --color-error: #f56565;
            --color-warning: #ffc107;
            --shadow-md: 0 4px 20px rgba(0, 0, 0, 0.08);
            --border-radius: 10px;
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

        /* ── Navbar ── */
        .navbar {
            background: var(--color-primary);
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            padding: 12px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: fixed;
            top: 0; left: 0; right: 0;
            z-index: 1000;
            border-bottom: 3px solid var(--color-primary);
        }
        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 20px;
            font-weight: 700;
            color: white;
            text-decoration: none;
        }
        .brand-logo {
            width: 45px; height: 45px;
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: bold; font-size: 20px;
        }
        .btn-dashboard {
            padding: 10px 24px;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            color: white;
            border: none; border-radius: 10px;
            font-weight: 600; font-size: 14px;
            cursor: pointer; transition: var(--transition);
            text-decoration: none;
            display: flex; align-items: center; gap: 8px;
        }
        .btn-dashboard:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(79,172,254,.4); }

        /* ── Container ── */
        .container { max-width: 1200px; margin: 0 auto; padding: 30px; }

        /* ── Loading / Error states ── */
        .loading-state, .error-state {
            background: white;
            border-radius: 24px;
            padding: 60px 40px;
            text-align: center;
            box-shadow: var(--shadow-md);
        }
        .loading-spinner {
            width: 50px; height: 50px;
            border: 4px solid #e2e8f0;
            border-top-color: var(--color-primary);
            border-radius: 50%;
            margin: 0 auto 20px;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .error-state h2 { color: var(--color-error); margin-bottom: 10px; }
        .error-state p { color: var(--color-text-light); margin-bottom: 20px; }
        .btn-back {
            display: inline-block;
            padding: 10px 24px;
            background: var(--color-primary);
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
        }

        /* ── Results Header ── */
        .results-header {
            background: white;
            border-radius: 24px;
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-md);
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .results-header::before {
            content: '🎉 🌟 ✨ 🎊 ⭐';
            position: absolute; top: -20px; left: 0;
            width: 100%; font-size: 40px;
            opacity: 0.1;
            animation: float 3s ease-in-out infinite;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50%       { transform: translateY(-10px); }
        }
        .test-title  { font-size: 28px; font-weight: 700; color: #2d3748; margin-bottom: 10px; }
        .test-date   { font-size: 14px; color: #718096; margin-bottom: 30px; }

        /* Score circle */
        .score-display { display: flex; justify-content: center; margin-bottom: 30px; }
        .score-circle {
            position: relative; width: 200px; height: 200px;
            border-radius: 50%;
            background: conic-gradient(
                var(--color-primary) 0%,
                var(--color-primary) var(--score-pct),
                #e2e8f0 var(--score-pct),
                #e2e8f0 100%
            );
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 8px 30px rgba(79,172,254,.3);
        }
        .score-circle::before {
            content: ''; position: absolute;
            width: 170px; height: 170px;
            border-radius: 50%; background: white;
        }
        .score-content { position: relative; z-index: 1; text-align: center; }
        .score-value   { font-size: 48px; font-weight: 700; color: var(--color-primary); line-height: 1; }
        .score-denom   { font-size: 14px; color: #718096; }
        .score-pct-text{ font-size: 18px; color: #718096; margin-top: 4px; }

        /* Performance badge */
        .performance-badge {
            display: inline-block; padding: 10px 24px;
            border-radius: 20px; font-size: 16px; font-weight: 700; margin-bottom: 20px;
        }
        .badge-excellent { background: linear-gradient(135deg,#48bb78,#38a169); color: white; }
        .badge-good      { background: linear-gradient(135deg,var(--color-primary),var(--color-primary-dark)); color: white; }
        .badge-average   { background: linear-gradient(135deg,#ffc107,#ff9800); color: white; }
        .badge-poor      { background: linear-gradient(135deg,#f56565,#e53e3e); color: white; }
        .badge-passed    { background: linear-gradient(135deg,#48bb78,#38a169); color: white; }
        .badge-failed    { background: linear-gradient(135deg,#f56565,#e53e3e); color: white; }

        /* Quick stats */
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px; margin-top: 30px;
        }
        .stat-item {
            text-align: center; padding: 15px;
            background: #f7fafc; border-radius: 12px;
            opacity: 0;
        }
        .stat-value { font-size: 24px; font-weight: 700; color: #2d3748; margin-bottom: 5px; }
        .stat-label { font-size: 13px; color: #718096; }

        /* ── Analysis Grid ── */
        .analysis-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px; margin-bottom: 30px;
        }
        .analysis-card {
            background: white; border-radius: 20px;
            padding: 25px; box-shadow: var(--shadow-md);
            opacity: 0;
        }
        .analysis-title {
            font-size: 18px; font-weight: 700; color: #2d3748;
            margin-bottom: 20px; display: flex; align-items: center; gap: 10px;
        }
        .analysis-icon {
            width: 40px; height: 40px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center; font-size: 20px;
        }
        .icon-correct   { background: #c6f6d5; }
        .icon-chart     { background: #e6f7ff; }
        .icon-time      { background: #e6f7ff; }

        .breakdown-list { display: flex; flex-direction: column; gap: 12px; }
        .breakdown-item {
            display: flex; justify-content: space-between; align-items: center;
            padding: 12px; background: #f7fafc; border-radius: 10px;
        }
        .breakdown-label { font-size: 14px; color: #718096; }
        .breakdown-value { font-size: 16px; font-weight: 700; color: #2d3748; }

        .progress-bar {
            width: 100%; height: 8px;
            background: #e2e8f0; border-radius: 10px; overflow: hidden; margin-top: 8px;
        }
        .progress-fill { height: 100%; border-radius: 10px; transition: width .5s ease; }
        .fill-correct   { background: linear-gradient(90deg,#48bb78,#38a169); }
        .fill-incorrect { background: linear-gradient(90deg,#f56565,#e53e3e); }
        .fill-topic     { background: linear-gradient(90deg,#4facfe,#00f2fe); }

        /* ── Questions Review ── */
        .questions-section {
            background: white; border-radius: 20px;
            padding: 30px; box-shadow: var(--shadow-md);
        }
        .section-header {
            display: flex; justify-content: space-between;
            align-items: center; margin-bottom: 25px;
        }
        .section-title { font-size: 24px; font-weight: 700; color: #2d3748; }

        .filter-buttons { display: flex; gap: 10px; flex-wrap: wrap; }
        .filter-btn {
            padding: 8px 18px; background: #f7fafc;
            border: 2px solid #e2e8f0; border-radius: 8px;
            font-size: 14px; font-weight: 600; color: #718096;
            cursor: pointer; transition: var(--transition);
        }
        .filter-btn.active {
            background: linear-gradient(135deg,#4facfe,#00f2fe);
            color: white; border-color: var(--color-primary);
        }
        .filter-btn:hover:not(.active) { background: #e2e8f0; }

        .questions-list { display: flex; flex-direction: column; gap: 20px; margin-top: 20px; }

        .question-card {
            border: 2px solid var(--color-border);
            border-radius: 16px; padding: 25px;
            transition: var(--transition);
        }
        .question-card.hidden { display: none; }
        .question-card.correct {
            border-color: #48bb78;
            background: linear-gradient(135deg,rgba(72,187,120,.05),rgba(56,161,105,.05));
        }
        .question-card.incorrect {
            border-color: #f56565;
            background: linear-gradient(135deg,rgba(245,101,101,.05),rgba(229,62,62,.05));
        }
        .question-card.skipped {
            border-color: #ffc107;
            background: rgba(255,193,7,.04);
        }

        .question-header-row {
            display: flex; justify-content: space-between;
            align-items: center; margin-bottom: 15px;
        }
        .question-number { font-size: 16px; font-weight: 700; color: #718096; }
        .question-meta   { font-size: 12px; color: #a0aec0; margin-top: 2px; }

        .result-badge {
            padding: 6px 14px; border-radius: 6px;
            font-size: 12px; font-weight: 700;
            display: flex; align-items: center; gap: 6px;
        }
        .result-badge.correct   { background: #c6f6d5; color: #22543d; }
        .result-badge.incorrect { background: #fed7d7; color: #742a2a; }
        .result-badge.skipped   { background: #fefcbf; color: #744210; }

        .question-text { font-size: 18px; color: #2d3748; margin-bottom: 20px; line-height: 1.6; }

        .answer-options { display: flex; flex-direction: column; gap: 12px; }
        .answer-option {
            display: flex; align-items: center;
            padding: 15px; border-radius: 10px;
            background: #f7fafc; border: 2px solid transparent;
        }
        .answer-option.user-selected     { border-color: var(--color-primary); background: rgba(79,172,254,.1); }
        .answer-option.correct-highlight  { border-color: #48bb78; background: rgba(72,187,120,.1); }
        .answer-option.wrong-highlight    { border-color: #f56565; background: rgba(245,101,101,.1); }
        .option-label { font-weight: 700; margin-right: 12px; min-width: 30px; color: #2d3748; }
        .option-text  { flex: 1; color: #2d3748; }
        .option-badge { padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; margin-left: 8px; }
        .badge-yours   { background: #bee3f8; color: #2c5282; }
        .badge-correct { background: #c6f6d5; color: #22543d; }

        .explanation-box {
            margin-top: 15px; padding: 14px 18px;
            background: #fffbeb; border-left: 4px solid #ffc107;
            border-radius: 0 8px 8px 0; font-size: 14px; color: #744210;
            line-height: 1.6;
        }
        .explanation-box strong { display: block; margin-bottom: 4px; }

        .marks-info { font-size: 13px; color: #718096; margin-top: 10px; text-align: right; }
        .marks-info.gained  { color: #38a169; }
        .marks-info.lost    { color: #e53e3e; }

        /* Hidden-results notice */
        .hidden-results-notice {
            text-align: center; padding: 40px 20px;
            color: #718096; font-size: 16px;
        }
        .hidden-results-notice .notice-icon { font-size: 48px; margin-bottom: 15px; }

        /* ── Action buttons ── */
        .action-section {
            margin-top: 30px; padding: 25px;
            background: #f7fafc; border-radius: 16px;
            display: flex; justify-content: center; gap: 15px; flex-wrap: wrap;
        }
        .action-btn {
            padding: 12px 30px; border: none; border-radius: 10px;
            font-weight: 700; font-size: 14px; cursor: pointer;
            transition: var(--transition); display: flex; align-items: center; gap: 8px;
            text-decoration: none;
        }
        .btn-primary  { background: linear-gradient(135deg,var(--color-primary),var(--color-primary-dark)); color: white; }
        .btn-secondary { background: white; color: var(--color-primary); border: 2px solid var(--color-primary); }
        .action-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(0,0,0,.2); }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            .navbar    { padding: 15px; }
            .container { padding: 15px; }
            .results-header { padding: 30px 20px; }
            .score-circle { width: 160px; height: 160px; }
            .score-circle::before { width: 130px; height: 130px; }
            .score-value { font-size: 36px; }
            .quick-stats { grid-template-columns: repeat(2, 1fr); }
            .analysis-grid { grid-template-columns: 1fr; }
            .section-header { flex-direction: column; align-items: flex-start; gap: 15px; }
            .question-header-row { flex-direction: column; align-items: flex-start; gap: 10px; }
            .action-section { flex-direction: column; }
            .action-btn { width: 100%; justify-content: center; }
        }

        @media print {
            .navbar, .action-section, .filter-buttons { display: none; }
            body { background: white; padding-top: 0; }
            .results-header, .analysis-card, .questions-section { box-shadow: none; border: 1px solid #e2e8f0; }
        }

        /* Animations */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

<!-- NAVIGATION -->
<nav class="navbar">
    <a href="student-dashboard.php" class="navbar-brand">
        <div class="brand-logo">P</div>
        <span>Placement Portal</span>
    </a>
    <div>
        <a href="student-dashboard.php" class="btn-dashboard">← Back to Dashboard</a>
    </div>
</nav>

<div class="container" id="app">
    <!-- Loading state shown until data arrives -->
    <div class="loading-state" id="loadingState">
        <div class="loading-spinner"></div>
        <p style="color:#718096">Loading your results…</p>
    </div>

    <!-- Error state (hidden by default) -->
    <div class="error-state" id="errorState" style="display:none">
        <div style="font-size:48px;margin-bottom:15px">⚠️</div>
        <h2>Could Not Load Results</h2>
        <p id="errorMsg">Something went wrong while fetching your results.</p>
        <a href="student-dashboard.php" class="btn-back">← Back to Dashboard</a>
    </div>

    <!-- Results content (hidden until data loaded) -->
    <div id="resultsContent" style="display:none">

        <!-- ── Results Header ── -->
        <div class="results-header">
            <h1 class="test-title" id="testTitle">Loading…</h1>
            <p class="test-date"   id="testDate"></p>

            <div class="score-display">
                <div class="score-circle" id="scoreCircle" style="--score-pct: 0%">
                    <div class="score-content">
                        <div class="score-value" id="scoreValue">0</div>
                        <div class="score-denom" id="scoreDenom">out of 0</div>
                        <div class="score-pct-text" id="scorePctText">0%</div>
                    </div>
                </div>
            </div>

            <div class="performance-badge" id="performanceBadge"></div>

            <div class="quick-stats">
                <div class="stat-item">
                    <div class="stat-value" id="statCorrect">—</div>
                    <div class="stat-label">Correct</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value" id="statIncorrect">—</div>
                    <div class="stat-label">Incorrect</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value" id="statUnanswered">—</div>
                    <div class="stat-label">Skipped</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value" id="statPercentile">—</div>
                    <div class="stat-label">Percentile</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value" id="statTime">—</div>
                    <div class="stat-label">Time Taken</div>
                </div>
            </div>
        </div>

        <!-- ── Analysis Grid ── -->
        <div class="analysis-grid">
            <!-- Accuracy card -->
            <div class="analysis-card">
                <h3 class="analysis-title">
                    <div class="analysis-icon icon-correct">✓</div>
                    Accuracy Breakdown
                </h3>
                <div class="breakdown-list" id="accuracyBreakdown"></div>
            </div>

            <!-- Category card -->
            <div class="analysis-card">
                <h3 class="analysis-title">
                    <div class="analysis-icon icon-chart">📊</div>
                    Topic Performance
                </h3>
                <div class="breakdown-list" id="categoryBreakdown"></div>
            </div>

            <!-- Time card -->
            <div class="analysis-card">
                <h3 class="analysis-title">
                    <div class="analysis-icon icon-time">⏱️</div>
                    Time Analysis
                </h3>
                <div class="breakdown-list" id="timeBreakdown"></div>
            </div>
        </div>

        <!-- ── Questions Review ── -->
        <div class="questions-section">
            <div class="section-header">
                <h2 class="section-title">Answer Review</h2>
                <div class="filter-buttons" id="filterButtons" style="display:none">
                    <button class="filter-btn active" data-filter="all">All Questions</button>
                    <button class="filter-btn" data-filter="correct">✓ Correct</button>
                    <button class="filter-btn" data-filter="incorrect">✗ Incorrect</button>
                    <button class="filter-btn" data-filter="skipped">— Skipped</button>
                </div>
            </div>
            <div class="questions-list" id="questionsList"></div>

            <div class="action-section">
                <button class="action-btn btn-primary" onclick="window.print()">
                    🖨️ Download Results
                </button>
                <a href="student-dashboard.php" class="action-btn btn-secondary">
                    📝 Take Another Test
                </a>
            </div>
        </div>

    </div><!-- /resultsContent -->
</div><!-- /container -->

<script>
// ── Inject server-side values so JS never touches the URL directly ──
const ATTEMPT_ID  = <?= $attemptId ?>;
const CSRF_TOKEN  = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;
const API_URL     = 'api/assessment/get-test-results.php';

// ============================================================
// BOOT
// ============================================================
document.addEventListener('DOMContentLoaded', loadResults);

async function loadResults() {
    try {
        const res  = await fetch(`${API_URL}?attempt_id=${ATTEMPT_ID}`, {
            headers: {
                'X-CSRF-Token': CSRF_TOKEN,
                'Accept':       'application/json',
            }
        });

        const data = await res.json();

        if (!data.success) {
            showError(data.error || 'Failed to load results.');
            return;
        }

        renderResults(data);

    } catch (err) {
        console.error('loadResults error:', err);
        showError('Network error. Please refresh the page.');
    }
}

// ============================================================
// RENDER ALL SECTIONS
// ============================================================
function renderResults(d) {
    // Header
    document.getElementById('testTitle').textContent = d.testName;
    document.getElementById('testDate').textContent  =
        'Completed on ' + formatDatetime(d.completedAt);

    // Score circle
    const pct = d.percentage;
    document.getElementById('scoreCircle').style.setProperty('--score-pct', pct + '%');
    animateNumber('scoreValue', 0, Math.round(d.score), 1400);
    document.getElementById('scoreDenom').textContent   = `out of ${d.totalMarks}`;
    document.getElementById('scorePctText').textContent = pct.toFixed(1) + '%';

    // Performance badge
    renderBadge(pct, d.passed, d.passingMarks, d.totalMarks);

    // Quick stats
    document.getElementById('statCorrect').textContent   = d.correctAnswers;
    document.getElementById('statIncorrect').textContent = d.wrongAnswers;
    document.getElementById('statUnanswered').textContent= d.unanswered;
    document.getElementById('statPercentile').textContent= ordinal(d.percentile);
    document.getElementById('statTime').textContent      = formatSeconds(d.timeTakenSeconds);

    // Analysis cards
    renderAccuracy(d);
    renderCategories(d.categoryPerformance);
    renderTime(d);

    // Questions
    if (d.showCorrectAnswers && d.questions && d.questions.length) {
        renderQuestions(d.questions, d.correctAnswers, d.wrongAnswers, d.unanswered);
        updateFilterCounts(d.correctAnswers, d.wrongAnswers, d.unanswered);
        document.getElementById('filterButtons').style.display = 'flex';
        setupFilters();
    } else if (!d.showCorrectAnswers) {
        document.getElementById('questionsList').innerHTML = `
            <div class="hidden-results-notice">
                <div class="notice-icon">🔒</div>
                <p>The teacher has not enabled answer review for this assessment.</p>
            </div>`;
    }

    // Reveal page
    document.getElementById('loadingState').style.display  = 'none';
    document.getElementById('resultsContent').style.display = 'block';

    // Entrance animations
    animateEntrance();
}

// ── Badge ──
function renderBadge(pct, passed, passingMarks, totalMarks) {
    const badge = document.getElementById('performanceBadge');
    let cls, label;
    if (pct >= 90)      { cls = 'badge-excellent'; label = '🏆 Excellent Performance!'; }
    else if (pct >= 75) { cls = 'badge-good';      label = '🎉 Good Performance!'; }
    else if (pct >= 60) { cls = 'badge-average';   label = '👍 Average Performance'; }
    else                { cls = 'badge-poor';       label = '📚 Keep Practicing!'; }

    if (!passed) {
        cls   = 'badge-failed';
        label = `❌ Not Passed (Passing: ${passingMarks}/${totalMarks})`;
    }

    badge.className = `performance-badge ${cls}`;
    badge.textContent = label;
}

// ── Accuracy Card ──
function renderAccuracy(d) {
    const total   = d.totalQuestions || 1;
    const corrPct = ((d.correctAnswers / total) * 100).toFixed(1);
    const wrPct   = ((d.wrongAnswers   / total) * 100).toFixed(1);
    const skipPct = ((d.unanswered     / total) * 100).toFixed(1);

    document.getElementById('accuracyBreakdown').innerHTML = `
        <div class="breakdown-item">
            <div class="breakdown-label">Correct Answers</div>
            <div class="breakdown-value">${d.correctAnswers} / ${total}</div>
        </div>
        <div class="progress-bar"><div class="progress-fill fill-correct" style="width:${corrPct}%"></div></div>

        <div class="breakdown-item">
            <div class="breakdown-label">Incorrect Answers</div>
            <div class="breakdown-value">${d.wrongAnswers} / ${total}</div>
        </div>
        <div class="progress-bar"><div class="progress-fill fill-incorrect" style="width:${wrPct}%"></div></div>

        <div class="breakdown-item">
            <div class="breakdown-label">Skipped</div>
            <div class="breakdown-value">${d.unanswered} / ${total}</div>
        </div>
        <div class="progress-bar"><div class="progress-fill" style="width:${skipPct}%;background:#a0aec0"></div></div>

        <div class="breakdown-item">
            <div class="breakdown-label">Passing Marks</div>
            <div class="breakdown-value">${d.passingMarks} / ${d.totalMarks}</div>
        </div>
    `;
}

// ── Category / Topic Card ──
function renderCategories(cats) {
    if (!cats || cats.length === 0) {
        document.getElementById('categoryBreakdown').innerHTML =
            '<p style="color:#a0aec0;font-size:14px">No topic data available.</p>';
        return;
    }

    const html = cats.map(c => {
        const pct = c.total > 0 ? ((c.correct / c.total) * 100).toFixed(0) : 0;
        return `
            <div class="breakdown-item">
                <div class="breakdown-label">${escHtml(c.topic)}</div>
                <div class="breakdown-value">${c.correct}/${c.total}</div>
            </div>
            <div class="progress-bar">
                <div class="progress-fill fill-topic" style="width:${pct}%"></div>
            </div>`;
    }).join('');

    document.getElementById('categoryBreakdown').innerHTML = html;
}

// ── Time Card ──
function renderTime(d) {
    const avgSec = d.totalQuestions > 0
        ? Math.round(d.timeTakenSeconds / d.totalQuestions) : 0;

    document.getElementById('timeBreakdown').innerHTML = `
        <div class="breakdown-item">
            <div class="breakdown-label">Total Time Taken</div>
            <div class="breakdown-value">${formatSeconds(d.timeTakenSeconds)}</div>
        </div>
        <div class="breakdown-item">
            <div class="breakdown-label">Avg per Question</div>
            <div class="breakdown-value">${formatSeconds(avgSec)}</div>
        </div>
        <div class="breakdown-item">
            <div class="breakdown-label">Time Remaining</div>
            <div class="breakdown-value">${formatSeconds(d.timeRemainingSeconds)}</div>
        </div>
        <div class="breakdown-item">
            <div class="breakdown-label">Duration Allowed</div>
            <div class="breakdown-value">${d.durationMinutes} min</div>
        </div>
    `;
}

// ── Questions ──
function renderQuestions(questions, correctCount, wrongCount, skippedCount) {
    const fragment = document.createDocumentFragment();
    questions.forEach(q => fragment.appendChild(buildQuestionCard(q)));
    const list = document.getElementById('questionsList');
    list.innerHTML = '';
    list.appendChild(fragment);
}

function buildQuestionCard(q) {
    const skipped   = !q.userAnswer;
    const statusCls = skipped ? 'skipped' : (q.isCorrect ? 'correct' : 'incorrect');
    const statusAttr= skipped ? 'skipped' : (q.isCorrect ? 'correct' : 'incorrect');

    const card = document.createElement('div');
    card.className = `question-card ${statusCls}`;
    card.dataset.status = statusAttr;

    // Badge text
    let badgeHtml;
    if (skipped)         badgeHtml = '<span class="result-badge skipped">— Skipped</span>';
    else if (q.isCorrect) badgeHtml = '<span class="result-badge correct">✓ Correct</span>';
    else                 badgeHtml = '<span class="result-badge incorrect">✗ Incorrect</span>';

    // Marks info
    const marksClass = q.marksObtained > 0 ? 'gained' : (q.marksObtained < 0 ? 'lost' : '');
    const marksLabel = q.marksObtained > 0
        ? `+${q.marksObtained} marks`
        : (q.marksObtained < 0 ? `${q.marksObtained} marks (negative)` : '0 marks');

    // Options
    let optionsHtml = '';
    for (const [label, text] of Object.entries(q.options)) {
        const isCorrect  = label === q.correctAnswer;
        const isSelected = label === q.userAnswer;

        let cls = '';
        if (isCorrect)              cls += ' correct-highlight';
        if (isSelected && isCorrect) cls += ' user-selected';
        if (isSelected && !isCorrect) cls += ' wrong-highlight user-selected';

        let badges = '';
        if (isCorrect)                      badges += '<span class="option-badge badge-correct">Correct Answer</span>';
        if (isSelected && !q.isCorrect)     badges += '<span class="option-badge badge-yours">Your Answer</span>';
        if (isSelected && q.isCorrect && q.userAnswer)
            badges += '<span class="option-badge badge-correct">Your Answer ✓</span>';

        optionsHtml += `
            <div class="answer-option${cls}">
                <span class="option-label">${escHtml(label)})</span>
                <span class="option-text">${escHtml(text)}</span>
                ${badges}
            </div>`;
    }

    // Explanation
    const explHtml = q.explanation
        ? `<div class="explanation-box"><strong>💡 Explanation:</strong>${escHtml(q.explanation)}</div>`
        : '';

    // Time taken per question
    const timeStr = q.timeTakenSeconds ? ` · ${formatSeconds(q.timeTakenSeconds)}` : '';
    const topicStr = q.topic ? ` · ${escHtml(q.topic)}` : '';

    card.innerHTML = `
        <div class="question-header-row">
            <div>
                <div class="question-number">Question ${q.questionNumber}</div>
                <div class="question-meta">${q.marks} mark${q.marks !== 1 ? 's' : ''}${q.negativeMarks > 0 ? ` · -${q.negativeMarks} neg` : ''}${topicStr}${timeStr}</div>
            </div>
            ${badgeHtml}
        </div>
        <p class="question-text">${escHtml(q.text)}</p>
        <div class="answer-options">${optionsHtml}</div>
        ${explHtml}
        <div class="marks-info ${marksClass}">${marksLabel}</div>
    `;

    return card;
}

// ── Filter buttons ──
function updateFilterCounts(correct, wrong, skipped) {
    const btns = document.querySelectorAll('.filter-btn');
    btns.forEach(btn => {
        const f = btn.dataset.filter;
        if (f === 'correct')   btn.textContent = `✓ Correct (${correct})`;
        if (f === 'incorrect') btn.textContent = `✗ Incorrect (${wrong})`;
        if (f === 'skipped')   btn.textContent = `— Skipped (${skipped})`;
    });
}

function setupFilters() {
    document.getElementById('filterButtons').addEventListener('click', e => {
        const btn = e.target.closest('.filter-btn');
        if (!btn) return;
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        const filter = btn.dataset.filter;
        document.querySelectorAll('.question-card').forEach(card => {
            card.classList.toggle('hidden',
                filter !== 'all' && card.dataset.status !== filter);
        });
    });
}

// ── Entrance animations ──
function animateEntrance() {
    document.querySelectorAll('.stat-item').forEach((el, i) => {
        setTimeout(() => el.style.animation = 'fadeInUp .5s ease forwards', i * 80);
    });
    document.querySelectorAll('.analysis-card').forEach((el, i) => {
        setTimeout(() => el.style.animation = 'fadeInUp .5s ease forwards', 300 + i * 100);
    });
}

// ============================================================
// UTILITIES
// ============================================================
function showError(msg) {
    document.getElementById('loadingState').style.display = 'none';
    document.getElementById('errorState').style.display   = 'block';
    document.getElementById('errorMsg').textContent = msg;
}

function animateNumber(id, from, to, duration) {
    const el = document.getElementById(id);
    if (!el) return;
    const start = performance.now();
    (function tick(now) {
        const p   = Math.min((now - start) / duration, 1);
        const val = Math.floor(from + (to - from) * (p * (2 - p)));
        el.textContent = val;
        if (p < 1) requestAnimationFrame(tick);
        else el.textContent = to;
    })(start);
}

function formatSeconds(sec) {
    if (!sec || sec < 0) return '0s';
    const m = Math.floor(sec / 60);
    const s = sec % 60;
    return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

function formatDatetime(dt) {
    if (!dt) return '';
    const d = new Date(dt.replace(' ', 'T'));
    return d.toLocaleDateString('en-IN', { day:'numeric', month:'long', year:'numeric' }) +
           ' at ' +
           d.toLocaleTimeString('en-IN', { hour:'2-digit', minute:'2-digit' });
}

function ordinal(n) {
    if (!n && n !== 0) return '—';
    const s = ['th','st','nd','rd'];
    const v = n % 100;
    return n + (s[(v-20)%10] || s[v] || s[0]);
}

function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#39;');
}

// ── Keyboard shortcuts ──
document.addEventListener('keydown', e => {
    if ((e.key === 'p' || e.key === 'P') && !e.ctrlKey) { e.preventDefault(); window.print(); }
    if (e.key === 'd' || e.key === 'D') window.location.href = 'student-dashboard.php';
});
</script>
</body>
</html>

<?php
/* ============================================================
 * test-preview.php
 * Converts test-preview.html to a live PHP page.
 * Keeps the original design 100% intact.
 * Adds:
 *   - Real DB data replacing all hardcoded values
 *   - "Test Info" section for teacher-set conditions
 *   - api/assessment/start.php wired to Start Test button
 * ============================================================ */

require_once 'config.php';
require_once 'db-guard.php';

/* ── Auth ── */
$user         = validateSession($conn, 'student');
$userId       = (int) $user['user_id'];
$userDept     = $user['department'] ?? null;

/* ── Validate ?id= ── */
$assessmentId = (int)($_GET['id'] ?? 0);
if ($assessmentId <= 0) {
    header('Location: student-dashboard.php?error=invalid_test');
    exit;
}

/* ── Fetch assessment + teacher-set conditions ── */
$asmResult = safePreparedQuery($conn,
    "SELECT
        a.assessment_id,
        a.title,
        a.description,
        a.instructions,
        a.category,
        a.difficulty,
        a.duration_minutes,
        a.total_marks,
        a.passing_marks,
        a.max_attempts,
        a.available_from,
        a.available_until,
        a.show_results_immediately,
        a.show_correct_answers,
        a.randomize_questions,
        a.randomize_options,
        a.is_public,
        (SELECT COUNT(*) FROM questions q
         WHERE q.assessment_id = a.assessment_id) AS question_count,
        COALESCE(
            (SELECT MAX(q2.negative_marks) FROM questions q2
             WHERE q2.assessment_id = a.assessment_id), 0
        ) AS max_negative_marks
     FROM assessments a
     WHERE a.assessment_id = ?
       AND a.status = 'active'
       AND (a.available_from  IS NULL OR a.available_from  <= NOW())
       AND (a.available_until IS NULL OR a.available_until >= NOW())
       AND (
           a.is_public = 1
           OR EXISTS (
               SELECT 1 FROM assessment_access ac
               WHERE ac.assessment_id = a.assessment_id
                 AND ac.access_type   = 'allow'
                 AND (ac.user_id = ? OR ac.department = ?)
           )
       )",
    "iis", [$assessmentId, $userId, $userDept]
);

if (!$asmResult['success'] || !$asmResult['result'] || $asmResult['result']->num_rows === 0) {
    header('Location: student-dashboard.php?error=test_not_found');
    exit;
}
$a = $asmResult['result']->fetch_assoc();
$asmResult['result']->free();

/* ── Previous attempts by this student ── */
$prevResult = safePreparedQuery($conn,
    "SELECT score, percentage, submitted_at
     FROM assessment_attempts
     WHERE assessment_id = ? AND user_id = ? AND status = 'completed'
     ORDER BY submitted_at DESC",
    "ii", [$assessmentId, $userId]
);

$previousAttempts = [];
if ($prevResult['success'] && $prevResult['result']) {
    while ($row = $prevResult['result']->fetch_assoc()) {
        $previousAttempts[] = $row;
    }
    $prevResult['result']->free();
}

$attemptsUsed = count($previousAttempts);
$attemptsLeft = max(0, (int)$a['max_attempts'] - $attemptsUsed);
$exhausted    = $attemptsLeft <= 0;

/* ── Derived display values ── */
$diff      = strtolower($a['difficulty']);
$diffLabel = ucfirst($diff) . ' Level';
$hasNeg    = (float)$a['max_negative_marks'] > 0;
$passPct   = round(((int)$a['passing_marks'] / max(1, (int)$a['total_marks'])) * 100);

function fmtDt(?string $dt): string {
    return $dt ? date('d M Y, g:i A', strtotime($dt)) : '—';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($a['title']) ?> - Preview</title>
    <style>
        :root {
            --font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            --color-primary: #234C6A;
            --color-primary-dark: #456882;
            --color-text: #2d3748;
            --color-text-light: #718096;
            --color-text-lighter: #a0aec0;
            --color-bg: #f0f4f8;
            --color-bg-light: #f5f7fa;
            --color-white: #ffffff;
            --color-border: #e2e8f0;
            --color-success: #48bb78;
            --color-error: #f56565;
            --color-warning: #ffc107;
            --shadow-sm: 0 2px 10px rgba(0,0,0,0.1);
            --shadow-md: 0 4px 20px rgba(0,0,0,0.08);
            --shadow-lg: 0 8px 30px rgba(0,0,0,0.15);
            --border-radius: 10px;
            --border-radius-lg: 20px;
            --transition: all 0.3s ease;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #D3DAD9;
            min-height: 100vh;
            color: #2d3748;
            padding-top: 71px;
            overflow-x: hidden;
        }

        /* ── Navbar ── */
        .navbar {
            background: var(--color-primary);
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            padding: 12px 28px;
            display: flex; align-items: center; justify-content: space-between;
            position: fixed; top:0; left:0; right:0; z-index:1000;
            border-bottom: 3px solid var(--color-primary);
        }
        .navbar-brand {
            display:flex; align-items:center; gap:12px;
            font-size:20px; font-weight:700; color:white; text-decoration:none;
        }
        .brand-logo {
            width:45px; height:45px;
            background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%);
            border-radius:10px;
            display:flex; align-items:center; justify-content:center;
            color:white; font-weight:bold; font-size:20px;
        }
        .nav-actions { display:flex; gap:15px; }
        .btn-back {
            padding:10px 24px; background:white; color:var(--color-primary);
            border:2px solid var(--color-primary); border-radius:10px;
            font-weight:600; font-size:14px; cursor:pointer;
            transition:all 0.3s ease; text-decoration:none;
            display:flex; align-items:center; gap:8px;
        }
        .btn-back:hover { background:var(--color-primary); color:white; transform:translateY(-2px); }

        /* ── Container ── */
        .container { max-width:900px; margin:0 auto; padding:30px; }

        /* ── Test Header ── */
        .test-header {
            background:white; border-radius:20px; padding:40px;
            margin-bottom:30px; box-shadow:0 4px 20px rgba(0,0,0,0.08);
            text-align:center; position:relative; overflow:hidden;
        }
        .test-header::before {
            content:''; position:absolute; top:-50px; right:-50px;
            width:200px; height:200px;
            background:linear-gradient(135deg, rgba(79,172,254,0.1), transparent);
            border-radius:50%;
        }
        .test-icon {
            width:80px; height:80px;
            background:linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border-radius:50%; display:flex; align-items:center; justify-content:center;
            margin:0 auto 20px; font-size:36px; color:white;
            box-shadow:0 8px 20px rgba(79,172,254,0.3);
        }
        .test-title { font-size:32px; font-weight:700; color:#2d3748; margin-bottom:10px; }
        .test-category { font-size:16px; color:#718096; margin-bottom:20px; }
        .difficulty-badge {
            display:inline-block; padding:8px 20px;
            border-radius:20px; font-size:14px; font-weight:700; margin-bottom:25px;
        }
        .difficulty-badge.easy   { background:#c6f6d5; color:#22543d; }
        .difficulty-badge.medium { background:#feebc8; color:#7c2d12; }
        .difficulty-badge.hard   { background:#fed7d7; color:#742a2a; }
        .test-quick-stats {
            display:grid; grid-template-columns:repeat(4,1fr); gap:20px; margin-top:25px;
        }
        .quick-stat {
            padding:20px; background:#f7fafc; border-radius:12px;
            text-align:center; transition:all 0.3s ease;
        }
        .quick-stat:hover { background:white; box-shadow:0 2px 8px rgba(0,0,0,0.1); }
        .quick-stat-icon  { font-size:28px; margin-bottom:10px; }
        .quick-stat-value { font-size:24px; font-weight:700; color:#2d3748; margin-bottom:5px; }
        .quick-stat-label { font-size:13px; color:#718096; }

        /* ── Description ── */
        .test-description {
            background:white; border-radius:20px; padding:30px;
            margin-bottom:30px; box-shadow:0 4px 20px rgba(0,0,0,0.08);
        }
        .description-title {
            font-size:20px; font-weight:700; color:#2d3748; margin-bottom:15px;
            display:flex; align-items:center; gap:10px;
        }
        .description-text { font-size:15px; color:#4a5568; line-height:1.8; margin-bottom:20px; }
        .btn-instructions {
            padding:12px 24px; background:#f7fafc; color:#4facfe;
            border:2px solid #4facfe; border-radius:10px;
            font-weight:600; font-size:14px; cursor:pointer;
            transition:all 0.3s ease; display:inline-flex; align-items:center; gap:8px;
        }
        .btn-instructions:hover { background:#4facfe; color:white; transform:translateY(-2px); }

        /* ── Test Info (teacher conditions) ── */
        .test-info {
            background:white; border-radius:20px; padding:30px;
            margin-bottom:30px; box-shadow:0 4px 20px rgba(0,0,0,0.08);
        }
        .info-title {
            font-size:20px; font-weight:700; color:#2d3748; margin-bottom:20px;
            display:flex; align-items:center; gap:10px;
        }
        .info-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .info-row {
            display:flex; align-items:flex-start; gap:12px;
            background:#f7fafc; border-radius:10px; padding:14px 16px;
        }
        .info-row-icon { font-size:20px; flex-shrink:0; margin-top:2px; }
        .info-row-label {
            font-size:11px; font-weight:700; color:#718096;
            text-transform:uppercase; letter-spacing:0.5px; margin-bottom:3px;
        }
        .info-row-value { font-size:14px; font-weight:600; color:#2d3748; }
        .tag {
            display:inline-block; padding:2px 10px; border-radius:20px;
            font-size:12px; font-weight:600;
        }
        .tag-yes  { background:#c6f6d5; color:#22543d; }
        .tag-no   { background:#e2e8f0; color:#718096; }
        .tag-warn { background:#fed7d7; color:#742a2a; }
        .tag-info { background:#bee3f8; color:#2b6cb0; }

        /* ── Previous Attempts ── */
        .prev-attempts {
            background:white; border-radius:20px; padding:30px;
            margin-bottom:30px; box-shadow:0 4px 20px rgba(0,0,0,0.08);
        }
        .prev-attempts-title {
            font-size:20px; font-weight:700; color:#2d3748; margin-bottom:16px;
            display:flex; align-items:center; gap:10px;
        }
        .attempt-row {
            display:flex; align-items:center; justify-content:space-between;
            flex-wrap:wrap; gap:10px;
            background:#f7fafc; border-radius:10px; padding:12px 16px;
            margin-bottom:10px; font-size:14px;
        }
        .attempt-row:last-child { margin-bottom:0; }
        .attempt-label { font-weight:600; }
        .attempt-date  { color:#718096; }
        .pct-pass { background:#c6f6d5; color:#22543d; }
        .pct-fail { background:#fed7d7; color:#742a2a; }

        /* ── Action Section ── */
        .action-section {
            background:white; border-radius:20px; padding:40px;
            box-shadow:0 4px 20px rgba(0,0,0,0.08); text-align:center;
        }
        .btn-start-test {
            padding:18px 60px;
            background:linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color:white; border:none; border-radius:12px;
            font-weight:700; font-size:20px; cursor:pointer;
            transition:all 0.3s ease;
            box-shadow:0 6px 20px rgba(79,172,254,0.4);
            display:inline-flex; align-items:center; gap:12px;
        }
        .btn-start-test:hover { transform:translateY(-3px); box-shadow:0 10px 30px rgba(79,172,254,0.5); }
        .btn-start-test:disabled { opacity:0.5; cursor:not-allowed; transform:none; box-shadow:none; }
        .action-note { font-size:14px; color:#718096; margin-top:20px; }
        .attempts-left-note {
            display:inline-block; margin-top:12px;
            background:#ebf8ff; color:#2b6cb0;
            padding:5px 16px; border-radius:20px; font-size:13px; font-weight:600;
        }

        /* ── Instructions Modal ── */
        .modal-overlay {
            display:none; position:fixed; top:0; left:0; width:100%; height:100%;
            background:rgba(0,0,0,0.7); z-index:1000;
            align-items:center; justify-content:center; overflow-y:auto; padding:20px;
        }
        .modal-overlay.active { display:flex; }
        .modal-content {
            background:white; border-radius:20px; padding:40px;
            max-width:700px; width:100%; max-height:90vh; overflow-y:auto;
            box-shadow:0 20px 60px rgba(0,0,0,0.3);
        }
        .modal-header {
            display:flex; justify-content:space-between; align-items:center; margin-bottom:25px;
        }
        .modal-title { font-size:24px; font-weight:700; color:#2d3748; }
        .btn-close-modal {
            width:35px; height:35px; background:#e2e8f0; border:none;
            border-radius:50%; font-size:20px; cursor:pointer;
            transition:all 0.3s ease; display:flex; align-items:center; justify-content:center;
        }
        .btn-close-modal:hover { background:#cbd5e0; transform:rotate(90deg); }
        .instructions-list { display:flex; flex-direction:column; gap:15px; }
        .instruction-item { display:flex; gap:15px; padding:15px; background:#f7fafc; border-radius:12px; }
        .instruction-number {
            width:30px; height:30px;
            background:linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border-radius:50%; display:flex; align-items:center; justify-content:center;
            color:white; font-weight:700; font-size:14px; flex-shrink:0;
        }
        .instruction-text { flex:1; font-size:14px; color:#2d3748; line-height:1.6; }
        .important-notes {
            margin-top:25px; padding:20px;
            background:linear-gradient(135deg, rgba(255,193,7,0.1), rgba(255,152,0,0.1));
            border-radius:12px; border-left:4px solid #ffc107;
        }
        .notes-title { font-size:16px; font-weight:700; color:#7c2d12; margin-bottom:12px; }
        .notes-list  { display:flex; flex-direction:column; gap:8px; }
        .note-item   { font-size:14px; color:#7c2d12; display:flex; gap:8px; }

        /* ── Confirm Modal ── */
        .confirm-modal {
            display:none; position:fixed; top:0; left:0; width:100%; height:100%;
            background:rgba(0,0,0,0.7); z-index:1001;
            align-items:center; justify-content:center;
        }
        .confirm-modal.active { display:flex; }
        .confirm-content {
            background:white; border-radius:20px; padding:40px; max-width:500px;
            text-align:center; box-shadow:0 20px 60px rgba(0,0,0,0.3);
        }
        .confirm-icon    { font-size:64px; margin-bottom:20px; }
        .confirm-title   { font-size:24px; font-weight:700; color:#2d3748; margin-bottom:15px; }
        .confirm-message { font-size:16px; color:#718096; margin-bottom:30px; line-height:1.6; }
        .modal-buttons   { display:flex; gap:15px; justify-content:center; }
        .modal-btn {
            padding:12px 30px; border:none; border-radius:10px;
            font-weight:700; font-size:14px; cursor:pointer; transition:all 0.3s ease;
        }
        .modal-btn.primary   { background:linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color:white; }
        .modal-btn.secondary { background:#e2e8f0; color:#718096; }
        .modal-btn:hover     { transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,0.2); }
        .modal-btn:disabled  { opacity:0.6; cursor:not-allowed; transform:none; }

        /* ── Responsive ── */
        @media (max-width:768px) {
            .navbar        { padding:15px; }
            .container     { padding:15px; }
            .test-header   { padding:30px 20px; }
            .test-title    { font-size:24px; }
            .test-quick-stats { grid-template-columns:repeat(2,1fr); gap:15px; }
            .test-description, .test-info, .prev-attempts { padding:20px; }
            .info-grid     { grid-template-columns:1fr; }
            .action-section { padding:30px 20px; }
            .btn-start-test { width:100%; justify-content:center; font-size:18px; padding:16px 40px; }
            .modal-content  { padding:30px 20px; margin:0 15px; }
            .confirm-content { margin:0 15px; padding:30px 20px; }
            .attempt-row   { flex-direction:column; align-items:flex-start; }
        }

        @keyframes fadeIn {
            from { opacity:0; transform:translateY(20px); }
            to   { opacity:1; transform:translateY(0); }
        }
        .test-header, .test-description, .test-info, .prev-attempts, .action-section {
            animation:fadeIn 0.5s ease;
        }
    </style>
</head>
<body>

<!-- NAVIGATION BAR -->
<nav class="navbar">
    <a href="student-dashboard.php" class="navbar-brand">
        <div class="brand-logo">P</div>
        <span>Placement Portal</span>
    </a>
    <div class="nav-actions">
        <a href="student-dashboard.php" class="btn-back">← Back to Dashboard</a>
    </div>
</nav>

<div class="container">

    <!-- Test Header -->
    <div class="test-header">
        <div class="test-icon">📝</div>
        <h1 class="test-title"><?= htmlspecialchars($a['title']) ?></h1>
        <p class="test-category"><?= htmlspecialchars(ucfirst($a['category'])) ?></p>
        <span class="difficulty-badge <?= $diff ?>"><?= $diffLabel ?></span>

        <div class="test-quick-stats">
            <div class="quick-stat">
                <div class="quick-stat-icon">❓</div>
                <div class="quick-stat-value"><?= (int)$a['question_count'] ?></div>
                <div class="quick-stat-label">Questions</div>
            </div>
            <div class="quick-stat">
                <div class="quick-stat-icon">⏱️</div>
                <div class="quick-stat-value"><?= (int)$a['duration_minutes'] ?></div>
                <div class="quick-stat-label">Minutes</div>
            </div>
            <div class="quick-stat">
                <div class="quick-stat-icon">🎯</div>
                <div class="quick-stat-value"><?= (int)$a['total_marks'] ?></div>
                <div class="quick-stat-label">Total Marks</div>
            </div>
            <div class="quick-stat">
                <div class="quick-stat-icon">✅</div>
                <div class="quick-stat-value"><?= (int)$a['passing_marks'] ?></div>
                <div class="quick-stat-label">Pass Marks</div>
            </div>
        </div>
    </div>

    <!-- Test Description -->
    <div class="test-description">
        <h2 class="description-title">📋 About This Test</h2>
        <p class="description-text">
            <?php if (!empty(trim($a['description']))): ?>
                <?= nl2br(htmlspecialchars($a['description'])) ?>
            <?php else: ?>
                This is a <?= $diff ?> level <?= htmlspecialchars(strtolower($a['category'])) ?> assessment
                consisting of <?= (int)$a['question_count'] ?> questions to be completed in
                <?= (int)$a['duration_minutes'] ?> minutes.
            <?php endif ?>
        </p>
        <button class="btn-instructions" onclick="showInstructions()">
            📖 View Detailed Instructions
        </button>
    </div>

    <!-- Test Info — teacher-set conditions -->
    <div class="test-info">
        <h2 class="info-title">ℹ️ Test Information</h2>
        <div class="info-grid">

            <div class="info-row">
                <div class="info-row-icon">📅</div>
                <div>
                    <div class="info-row-label">Available From</div>
                    <div class="info-row-value">
                        <?= $a['available_from']
                            ? fmtDt($a['available_from'])
                            : '<span style="color:#48bb78;font-weight:600">Open</span>' ?>
                    </div>
                </div>
            </div>

            <div class="info-row">
                <div class="info-row-icon">⏰</div>
                <div>
                    <div class="info-row-label">Deadline</div>
                    <div class="info-row-value">
                        <?= $a['available_until']
                            ? fmtDt($a['available_until'])
                            : '<span style="color:#48bb78;font-weight:600">No deadline</span>' ?>
                    </div>
                </div>
            </div>

            <div class="info-row">
                <div class="info-row-icon">🔄</div>
                <div>
                    <div class="info-row-label">Attempts Allowed</div>
                    <div class="info-row-value">
                        <?= (int)$a['max_attempts'] ?> total &nbsp;
                        <span class="tag <?= $attemptsLeft > 0 ? 'tag-info' : 'tag-warn' ?>">
                            <?= $attemptsLeft > 0 ? "$attemptsLeft left" : 'None left' ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="info-row">
                <div class="info-row-icon">🎓</div>
                <div>
                    <div class="info-row-label">Passing Criteria</div>
                    <div class="info-row-value">
                        <?= (int)$a['passing_marks'] ?> / <?= (int)$a['total_marks'] ?> marks (<?= $passPct ?>%)
                    </div>
                </div>
            </div>

            <div class="info-row">
                <div class="info-row-icon">📊</div>
                <div>
                    <div class="info-row-label">Results</div>
                    <div class="info-row-value">
                        <span class="tag <?= $a['show_results_immediately'] ? 'tag-yes' : 'tag-no' ?>">
                            <?= $a['show_results_immediately'] ? 'Shown immediately' : 'Released after review' ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="info-row">
                <div class="info-row-icon">🔍</div>
                <div>
                    <div class="info-row-label">Correct Answers</div>
                    <div class="info-row-value">
                        <span class="tag <?= $a['show_correct_answers'] ? 'tag-yes' : 'tag-no' ?>">
                            <?= $a['show_correct_answers'] ? 'Shown after submission' : 'Not shown' ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="info-row">
                <div class="info-row-icon">🔀</div>
                <div>
                    <div class="info-row-label">Question Order</div>
                    <div class="info-row-value">
                        <span class="tag <?= $a['randomize_questions'] ? 'tag-warn' : 'tag-no' ?>">
                            <?= $a['randomize_questions'] ? 'Randomised' : 'Fixed order' ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="info-row">
                <div class="info-row-icon">➖</div>
                <div>
                    <div class="info-row-label">Negative Marking</div>
                    <div class="info-row-value">
                        <span class="tag <?= $hasNeg ? 'tag-warn' : 'tag-yes' ?>">
                            <?= $hasNeg ? '⚠️ Yes — marks deducted' : 'No penalty' ?>
                        </span>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Previous Attempts -->
    <?php if (!empty($previousAttempts)): ?>
    <div class="prev-attempts">
        <h2 class="prev-attempts-title">📜 Your Previous Attempts</h2>
        <?php foreach ($previousAttempts as $i => $pa):
            $pct     = round((float)$pa['percentage']);
            $passed  = $pct >= $passPct;
            $num     = $attemptsUsed - $i;
        ?>
        <div class="attempt-row">
            <span class="attempt-label">Attempt #<?= $num ?></span>
            <span class="attempt-date">📅 <?= fmtDt($pa['submitted_at']) ?></span>
            <span>Score: <strong><?= round($pa['score']) ?> / <?= (int)$a['total_marks'] ?></strong></span>
            <span class="tag <?= $passed ? 'pct-pass' : 'pct-fail' ?>"><?= $pct ?>%</span>
        </div>
        <?php endforeach ?>
    </div>
    <?php endif ?>

    <!-- Action Section -->
    <div class="action-section">
        <?php if ($exhausted): ?>
            <p style="font-size:48px;margin-bottom:16px">🔒</p>
            <p style="font-weight:700;font-size:16px;color:#c53030;margin-bottom:8px">
                You have used all <?= (int)$a['max_attempts'] ?> attempt(s) for this test.
            </p>
            <?php if (!empty($previousAttempts)): ?>
            <p style="color:#718096;font-size:14px;margin-top:6px">
                Best score: <strong><?= max(array_column($previousAttempts, 'percentage')) ?>%</strong>
            </p>
            <?php endif ?>
        <?php else: ?>
            <button class="btn-start-test" id="startBtn" onclick="confirmStart()">
                🚀 Start Test
            </button>
            <p class="action-note">💡 The timer will begin immediately when you start</p>
            <?php if ((int)$a['max_attempts'] > 1): ?>
            <div class="attempts-left-note">
                🔄 <?= $attemptsLeft ?> of <?= (int)$a['max_attempts'] ?> attempt(s) remaining
            </div>
            <?php endif ?>
        <?php endif ?>
    </div>

</div><!-- /.container -->

<!-- Instructions Modal -->
<div class="modal-overlay" id="instructionsModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Test Instructions</h2>
            <button class="btn-close-modal" onclick="closeInstructions()">✕</button>
        </div>

        <div class="instructions-list">
            <?php
            $customInstr = trim($a['instructions'] ?? '');
            if ($customInstr !== ''):
                // Teacher wrote custom instructions — display them
                $lines = array_values(array_filter(array_map('trim', explode("\n", $customInstr))));
                foreach ($lines as $idx => $line): ?>
            <div class="instruction-item">
                <div class="instruction-number"><?= $idx + 1 ?></div>
                <div class="instruction-text"><?= htmlspecialchars($line) ?></div>
            </div>
            <?php endforeach;
            else: // Smart defaults generated from assessment config ?>
            <div class="instruction-item">
                <div class="instruction-number">1</div>
                <div class="instruction-text">
                    <strong>Read Carefully:</strong> Read each question and all answer options thoroughly before selecting your answer.
                </div>
            </div>
            <div class="instruction-item">
                <div class="instruction-number">2</div>
                <div class="instruction-text">
                    <strong>Time Management:</strong> You have <strong><?= (int)$a['duration_minutes'] ?> minutes</strong> to complete all <strong><?= (int)$a['question_count'] ?> questions</strong>. A timer will be visible at the top of your screen.
                </div>
            </div>
            <div class="instruction-item">
                <div class="instruction-number">3</div>
                <div class="instruction-text">
                    <strong>Attempts:</strong>
                    <?= (int)$a['max_attempts'] === 1
                        ? 'This test allows <strong>one attempt only</strong>. You cannot retake it once started.'
                        : 'You have <strong>' . (int)$a['max_attempts'] . ' attempts</strong> allowed for this test.' ?>
                </div>
            </div>
            <div class="instruction-item">
                <div class="instruction-number">4</div>
                <div class="instruction-text">
                    <strong>Navigation:</strong> Use "Previous" and "Next" buttons to move between questions. You can also use the question palette to jump to any question.
                </div>
            </div>
            <div class="instruction-item">
                <div class="instruction-number">5</div>
                <div class="instruction-text">
                    <strong>Scoring:</strong>
                    <?php if ($hasNeg): ?>
                        Correct answers earn marks. <strong>Wrong answers carry negative marks</strong> — avoid blind guessing.
                    <?php else: ?>
                        Each correct answer earns you points. There is no negative marking for incorrect answers.
                    <?php endif ?>
                </div>
            </div>
            <div class="instruction-item">
                <div class="instruction-number">6</div>
                <div class="instruction-text">
                    <strong>Submission:</strong> Click "Submit Test" when you're done. You cannot change answers after submission.
                    <?= $a['show_results_immediately']
                        ? ' Results will be shown <strong>immediately</strong>.'
                        : ' Results will be released after review.' ?>
                </div>
            </div>
            <div class="instruction-item">
                <div class="instruction-number">7</div>
                <div class="instruction-text">
                    <strong>No Cheating:</strong> Do not use any external resources, calculators, or assistance. This test must be completed independently.
                </div>
            </div>
            <?php endif ?>
        </div>

        <div class="important-notes">
            <div class="notes-title">⚠️ Important Notes</div>
            <div class="notes-list">
                <div class="note-item"><span>•</span><span>Ensure stable internet connection throughout the test</span></div>
                <div class="note-item"><span>•</span><span>Do not refresh the page or close the browser during the test</span></div>
                <div class="note-item"><span>•</span><span>Once time expires, the test will auto-submit</span></div>
                <?php if ($a['randomize_questions']): ?>
                <div class="note-item"><span>•</span><span>Questions are presented in a randomised order for this test</span></div>
                <?php endif ?>
                <?php if ($a['randomize_options']): ?>
                <div class="note-item"><span>•</span><span>Answer option positions are shuffled — they may differ from any sample papers</span></div>
                <?php endif ?>
                <div class="note-item"><span>•</span><span>Use a laptop or desktop for the best experience</span></div>
                <div class="note-item"><span>•</span><span>Keep your workspace quiet and distraction-free</span></div>
            </div>
        </div>
    </div>
</div>

<!-- Confirmation Modal -->
<div class="confirm-modal" id="confirmModal">
    <div class="confirm-content">
        <div class="confirm-icon">⚡</div>
        <h2 class="confirm-title">Ready to Start?</h2>
        <p class="confirm-message">
            Once you begin, the timer will start immediately.<br><br>
            <strong>Duration:</strong> <?= (int)$a['duration_minutes'] ?> minutes<br>
            <strong>Questions:</strong> <?= (int)$a['question_count'] ?><br>
            <strong>Total Marks:</strong> <?= (int)$a['total_marks'] ?><br>
            <?php if ($hasNeg): ?>
            <strong style="color:#c53030">⚠️ Negative marking applies.</strong><br>
            <?php endif ?>
            <br>Make sure you're ready before proceeding.
        </p>
        <div class="modal-buttons">
            <button class="modal-btn secondary" id="cancelBtn" onclick="closeConfirm()">Not Yet</button>
            <button class="modal-btn primary"   id="beginBtn"  onclick="beginTest()">Yes, Begin!</button>
        </div>
    </div>
</div>

<script>
    const ASSESSMENT_ID = <?= $assessmentId ?>;

    /* ── Fetch CSRF token once on page load ── */
    let csrfToken = '';
    (async function loadCsrfToken() {
        try {
            const base = window.location.pathname.replace(/\/[^\/]*$/, '');
            const res  = await fetch(base + '/api/csrf-token.php');
            const data = await res.json();
            if (data.success && data.token) {
                csrfToken = data.token;
            }
        } catch (e) {
            console.warn('Could not fetch CSRF token:', e);
        }
    })();

    function showInstructions() {
        document.getElementById('instructionsModal').classList.add('active');
    }
    function closeInstructions() {
        document.getElementById('instructionsModal').classList.remove('active');
    }
    function confirmStart() {
        document.getElementById('confirmModal').classList.add('active');
    }
    function closeConfirm() {
        document.getElementById('confirmModal').classList.remove('active');
    }

    async function beginTest() {
        const beginBtn  = document.getElementById('beginBtn');
        const cancelBtn = document.getElementById('cancelBtn');
        const startBtn  = document.getElementById('startBtn');

        beginBtn.textContent = 'Starting…';
        beginBtn.disabled    = true;
        cancelBtn.disabled   = true;
        if (startBtn) startBtn.disabled = true;

        /* Build an absolute URL so this works regardless of subdirectory depth */
        const base    = window.location.pathname.replace(/\/[^\/]*$/, '');
        const apiUrl  = base + '/api/assessment/start.php';

        try {
            const res = await fetch(apiUrl, {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body:    JSON.stringify({ assessment_id: ASSESSMENT_ID })
            });

            /* Read body as text first so we can show it if JSON parse fails */
            const text = await res.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                /* Server returned non-JSON — likely a PHP error or 404 page */
                console.error('start.php raw response:', text);
                throw new Error(
                    res.status === 404
                        ? 'start.php not found (404). Check your file is at api/assessment/start.php'
                        : 'Server returned unexpected response (HTTP ' + res.status + '). Check PHP error logs.'
                );
            }

            if (data.success && data.attempt_id) {
                window.location.href = base + '/take-test.php?attempt_id=' + data.attempt_id;
            } else {
                resetButtons(beginBtn, cancelBtn, startBtn);
                closeConfirm();
                showError(data.error || 'Could not start test. Please try again.');
            }
        } catch (err) {
            console.error('beginTest error:', err);
            resetButtons(beginBtn, cancelBtn, startBtn);
            closeConfirm();
            showError(err.message || 'Could not reach the server. Please check your connection.');
        }
    }

    function resetButtons(beginBtn, cancelBtn, startBtn) {
        beginBtn.textContent = 'Yes, Begin!';
        beginBtn.disabled    = false;
        cancelBtn.disabled   = false;
        if (startBtn) startBtn.disabled = false;
    }

    function showError(msg) {
        /* Show a styled error below the Start button instead of a plain alert */
        let errEl = document.getElementById('startError');
        if (!errEl) {
            errEl = document.createElement('div');
            errEl.id = 'startError';
            errEl.style.cssText =
                'margin-top:16px;padding:12px 20px;background:#fed7d7;color:#742a2a;' +
                'border-radius:10px;font-size:14px;font-weight:600;max-width:500px;margin-inline:auto;';
            document.querySelector('.action-section').appendChild(errEl);
        }
        errEl.textContent = '⚠️ ' + msg;
        errEl.style.display = 'block';
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeInstructions();
            closeConfirm();
        }
    });
</script>
</body>
</html>
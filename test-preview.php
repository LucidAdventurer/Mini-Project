<?php
/* ============================================================
 * test-preview.php
 * Rewritten to match the actual live database schema.
 *
 * Key schema facts (from newdatabase.txt):
 *   assessments.status      : enum('draft','published','archived')
 *   assessments.visibility  : enum('public','group','private')
 *   assessments             : NO show_results_immediately / show_correct_answers
 *                             / instructions columns
 *   assessment_attempts.status : enum('in_progress','submitted','timeout')
 *   questions               : NO option_a/b/c/d — uses question_options table
 *   questions               : HAS negative_marks decimal(4,2)
 *   users.role              : enum('admin','teacher','student')  (not user_type)
 * ============================================================ */

require_once 'config.php';
require_once 'db-guard.php';

/* ── Auth ── */
$user   = validateSession($conn, 'student');
$userId = (int) $user['user_id'];

/* ── Validate ?id= ── */
$assessmentId = (int)($_GET['id'] ?? 0);
if ($assessmentId <= 0) {
    header('Location: student-dashboard.php?error=invalid_test');
    exit;
}

/* ── Fetch assessment ── */
$asmResult = safePreparedQuery($conn,
    "SELECT
        a.assessment_id,
        a.title,
        a.description,
        a.category,
        a.difficulty,
        a.duration_minutes,
        a.total_marks,
        a.passing_marks,
        a.max_attempts,
        a.start_time,
        a.end_time,
        a.randomize_questions,
        a.randomize_options,
        a.visibility,
        (SELECT COUNT(*) FROM questions q
         WHERE q.assessment_id = a.assessment_id) AS question_count,
        COALESCE(
            (SELECT MAX(q2.negative_marks) FROM questions q2
             WHERE q2.assessment_id = a.assessment_id), 0
        ) AS max_negative_marks
     FROM assessments a
     WHERE a.assessment_id = ?
       AND a.status = 'published'",
    "i", [$assessmentId]
);

if (!$asmResult['success'] || !$asmResult['result'] || $asmResult['result']->num_rows === 0) {
    header('Location: student-dashboard.php?error=test_not_found');
    exit;
}
$a = $asmResult['result']->fetch_assoc();
$asmResult['result']->free();

/* ── Access check for non-public assessments ── */
if ($a['visibility'] !== 'public') {
    $accessCheck = safePreparedQuery($conn,
        "SELECT 1 FROM assessment_targets at
         WHERE at.assessment_id = ?
           AND (
             (at.target_type = 'student' AND at.target_id = ?)
             OR
             (at.target_type = 'group'   AND at.target_id IN (
                 SELECT gm.group_id FROM group_members gm WHERE gm.student_id = ?
             ))
           )
         LIMIT 1",
        "iii", [$assessmentId, $userId, $userId]
    );
    $hasAccess = $accessCheck['success']
              && $accessCheck['result']
              && $accessCheck['result']->num_rows > 0;
    if ($accessCheck['result']) $accessCheck['result']->free();

    if (!$hasAccess) {
        header('Location: student-dashboard.php?error=access_denied');
        exit;
    }
}

/* ── Previous attempts by this student ── */
$prevResult = safePreparedQuery($conn,
    "SELECT score, percentage, submitted_at
     FROM assessment_attempts
     WHERE assessment_id = ? AND user_id = ? AND status = 'submitted'
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
$passPct   = $a['total_marks'] > 0
           ? round(((int)$a['passing_marks'] / (int)$a['total_marks']) * 100)
           : 0;

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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
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
            --nav-h:         68px;
            --transition:    .2s cubic-bezier(.4,0,.2,1);
        }

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding-top: var(--nav-h);
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
        }

        /* ══ NAVBAR ══ */
        .navbar {
            background: var(--primary);
            padding: 0 28px; height: var(--nav-h);
            display: flex; align-items: center; justify-content: space-between;
            position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
            box-shadow: 0 1px 0 rgba(255,255,255,.06), 0 4px 20px rgba(0,0,0,.18);
        }
        .navbar-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; }
        .btn-back {
            padding: 9px 20px;
            background: rgba(255,255,255,.12);
            color: white; border: 1.5px solid rgba(255,255,255,.25);
            border-radius: var(--radius-sm);
            font-family: 'Inter', sans-serif; font-weight: 600; font-size: 13.5px;
            cursor: pointer; transition: var(--transition); text-decoration: none;
            display: flex; align-items: center; gap: 8px;
        }
        .btn-back:hover { background: rgba(255,255,255,.22); border-color: rgba(255,255,255,.4); }

        /* ══ CONTAINER ══ */
        .container { max-width: 860px; margin: 0 auto; padding: 32px 24px 60px; }

        /* ══ TEST HEADER ══ */
        .test-header {
            background: linear-gradient(135deg, var(--primary) 0%, #1e5276 60%, #1a6fa0 100%);
            border-radius: var(--radius);
            padding: 40px 36px 36px;
            margin-bottom: 24px;
            text-align: center;
            position: relative; overflow: hidden;
            box-shadow: 0 4px 24px rgba(26,58,82,.3);
            animation: fadeUp .4s ease both;
        }
        .test-header::before {
            content: ''; position: absolute; top: -60px; right: -60px;
            width: 220px; height: 220px; border-radius: 50%;
            background: rgba(255,255,255,.05); pointer-events: none;
        }
        .test-header::after {
            content: ''; position: absolute; bottom: -80px; left: 80px;
            width: 180px; height: 180px; border-radius: 50%;
            background: rgba(14,165,233,.08); pointer-events: none;
        }
        .test-icon {
            width: 76px; height: 76px;
            background: rgba(255,255,255,.15);
            border: 2px solid rgba(255,255,255,.25);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 20px; font-size: 34px;
            backdrop-filter: blur(8px);
            position: relative; z-index: 1;
        }
        .test-title {
            font-family: 'Sora', sans-serif;
            font-size: 28px; font-weight: 800; color: white;
            margin-bottom: 8px; letter-spacing: -.3px;
            position: relative; z-index: 1;
        }
        .test-category { font-size: 14px; color: rgba(255,255,255,.7); margin-bottom: 18px; position: relative; z-index: 1; }
        .difficulty-badge {
            display: inline-block; padding: 6px 18px;
            border-radius: 20px; font-family: 'Sora', sans-serif;
            font-size: 12px; font-weight: 700; margin-bottom: 28px;
            position: relative; z-index: 1;
        }
        .difficulty-badge.easy   { background: #dcfce7; color: #166534; }
        .difficulty-badge.medium { background: #fef3c7; color: #92400e; }
        .difficulty-badge.hard   { background: #fee2e2; color: #991b1b; }

        .test-quick-stats {
            display: grid; grid-template-columns: repeat(4,1fr); gap: 14px;
            margin-top: 4px; position: relative; z-index: 1;
        }
        .quick-stat {
            padding: 16px 12px;
            background: rgba(255,255,255,.1);
            border: 1px solid rgba(255,255,255,.15);
            border-radius: 12px; text-align: center;
            backdrop-filter: blur(8px); transition: var(--transition);
        }
        .quick-stat:hover { background: rgba(255,255,255,.18); }
        .quick-stat-icon  { font-size: 24px; margin-bottom: 8px; }
        .quick-stat-value { font-family: 'Sora', sans-serif; font-size: 22px; font-weight: 800; color: white; margin-bottom: 4px; }
        .quick-stat-label { font-size: 11.5px; color: rgba(255,255,255,.65); }

        /* ══ CARDS ══ */
        .card {
            background: var(--surface); border-radius: var(--radius);
            padding: 28px; box-shadow: var(--shadow);
            border: 1px solid var(--border); margin-bottom: 20px;
            animation: fadeUp .4s ease both;
        }
        .section-title {
            font-family: 'Sora', sans-serif;
            font-size: 17px; font-weight: 700; color: var(--text);
            margin-bottom: 18px; display: flex; align-items: center; gap: 10px;
        }
        .description-text { font-size: 14.5px; color: var(--text-mid); line-height: 1.8; margin-bottom: 20px; }

        .btn-instructions {
            padding: 10px 20px;
            background: var(--surface2); color: var(--accent);
            border: 1.5px solid var(--accent); border-radius: var(--radius-sm);
            font-family: 'Inter', sans-serif; font-weight: 600; font-size: 13.5px;
            cursor: pointer; transition: var(--transition);
            display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-instructions:hover { background: var(--accent); color: white; box-shadow: 0 4px 12px rgba(14,165,233,.3); }

        /* Info grid */
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .info-row {
            display: flex; align-items: flex-start; gap: 12px;
            background: var(--surface2); border-radius: var(--radius-sm);
            padding: 14px 16px; border: 1px solid var(--border);
        }
        .info-row-icon { font-size: 20px; flex-shrink: 0; margin-top: 2px; }
        .info-row-label {
            font-size: 10.5px; font-weight: 700; color: var(--text-soft);
            text-transform: uppercase; letter-spacing: .06em; margin-bottom: 3px;
        }
        .info-row-value { font-size: 13.5px; font-weight: 600; color: var(--text); }

        /* Tags */
        .tag { display: inline-block; padding: 3px 10px; border-radius: 6px; font-size: 11.5px; font-weight: 700; font-family: 'Sora', sans-serif; }
        .tag-yes  { background: #dcfce7; color: #166534; }
        .tag-no   { background: #f1f5f9; color: var(--text-soft); }
        .tag-warn { background: #fee2e2; color: #991b1b; }
        .tag-info { background: #e0f2fe; color: #075985; }

        /* Previous attempts */
        .attempt-row {
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 10px;
            background: var(--surface2); border: 1px solid var(--border);
            border-radius: var(--radius-sm); padding: 13px 16px;
            margin-bottom: 10px; font-size: 13.5px;
        }
        .attempt-row:last-child { margin-bottom: 0; }
        .pct-pass { background: #dcfce7; color: #166534; }
        .pct-fail { background: #fee2e2; color: #991b1b; }

        /* ══ ACTION SECTION ══ */
        .action-section {
            background: var(--surface); border-radius: var(--radius);
            padding: 40px 36px; box-shadow: var(--shadow);
            border: 1px solid var(--border);
            text-align: center; animation: fadeUp .4s .2s ease both;
        }
        .btn-start-test {
            padding: 16px 56px;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            color: white; border: none; border-radius: var(--radius-sm);
            font-family: 'Sora', sans-serif; font-weight: 800; font-size: 18px;
            cursor: pointer; transition: var(--transition);
            box-shadow: 0 4px 20px rgba(14,165,233,.4);
            display: inline-flex; align-items: center; gap: 12px;
        }
        .btn-start-test:hover { transform: translateY(-3px); box-shadow: 0 8px 28px rgba(14,165,233,.5); }
        .btn-start-test:disabled { opacity: .5; cursor: not-allowed; transform: none; box-shadow: none; }
        .action-note { font-size: 13.5px; color: var(--text-soft); margin-top: 18px; }
        .attempts-left-note {
            display: inline-block; margin-top: 12px;
            background: #e0f2fe; color: #075985;
            padding: 5px 16px; border-radius: 20px;
            font-size: 13px; font-weight: 600;
        }

        /* ══ MODALS ══ */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.65); z-index: 2000;
            align-items: center; justify-content: center;
            overflow-y: auto; padding: 20px;
        }
        .modal-overlay.active { display: flex; }
        .modal-content {
            background: var(--surface); border-radius: var(--radius);
            padding: 36px; max-width: 680px; width: 100%;
            max-height: 90vh; overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,.25);
            animation: fadeUp .25s ease both;
        }
        .modal-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 24px;
        }
        .modal-title { font-family: 'Sora', sans-serif; font-size: 22px; font-weight: 800; color: var(--text); }
        .btn-close-modal {
            width: 34px; height: 34px; background: var(--surface2); border: 1px solid var(--border);
            border-radius: 8px; font-size: 18px; cursor: pointer;
            transition: var(--transition); display: flex; align-items: center; justify-content: center;
        }
        .btn-close-modal:hover { background: var(--border); transform: rotate(90deg); }

        .instructions-list { display: flex; flex-direction: column; gap: 14px; }
        .instruction-item {
            display: flex; gap: 14px; padding: 14px;
            background: var(--surface2); border-radius: var(--radius-sm);
            border: 1px solid var(--border);
        }
        .instruction-number {
            width: 28px; height: 28px;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 13px; flex-shrink: 0;
        }
        .instruction-text { flex: 1; font-size: 13.5px; color: var(--text-mid); line-height: 1.6; }

        .important-notes {
            margin-top: 22px; padding: 18px;
            background: #fefce8; border-radius: var(--radius-sm);
            border-left: 4px solid var(--warning);
        }
        .notes-title { font-family: 'Sora', sans-serif; font-size: 14px; font-weight: 700; color: #92400e; margin-bottom: 10px; }
        .notes-list  { display: flex; flex-direction: column; gap: 7px; }
        .note-item   { font-size: 13.5px; color: #92400e; display: flex; gap: 8px; }

        /* Confirm modal */
        .confirm-modal {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.65); z-index: 2001;
            align-items: center; justify-content: center;
        }
        .confirm-modal.active { display: flex; }
        .confirm-content {
            background: var(--surface); border-radius: var(--radius);
            padding: 40px; max-width: 480px; width: 90%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,.25);
            animation: fadeUp .25s ease both;
        }
        .confirm-icon    { font-size: 60px; margin-bottom: 18px; }
        .confirm-title   { font-family: 'Sora', sans-serif; font-size: 22px; font-weight: 800; color: var(--text); margin-bottom: 14px; }
        .confirm-message { font-size: 14.5px; color: var(--text-mid); margin-bottom: 28px; line-height: 1.7; }
        .modal-buttons   { display: flex; gap: 14px; justify-content: center; }
        .modal-btn {
            padding: 11px 28px; border: none; border-radius: var(--radius-sm);
            font-family: 'Inter', sans-serif; font-weight: 700; font-size: 14px;
            cursor: pointer; transition: var(--transition);
        }
        .modal-btn.primary   { background: linear-gradient(135deg, var(--accent), var(--accent2)); color: white; box-shadow: 0 2px 8px rgba(14,165,233,.3); }
        .modal-btn.primary:hover { transform: translateY(-2px); box-shadow: 0 4px 14px rgba(14,165,233,.45); }
        .modal-btn.secondary { background: var(--surface2); color: var(--text-mid); border: 1.5px solid var(--border); }
        .modal-btn.secondary:hover { background: var(--border); color: var(--text); }
        .modal-btn:disabled  { opacity: .6; cursor: not-allowed; transform: none; }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 768px) {
            .navbar { padding: 0 16px; }
            .container { padding: 20px 16px 60px; }
            .test-header { padding: 28px 20px; }
            .test-title  { font-size: 22px; }
            .test-quick-stats { grid-template-columns: repeat(2,1fr); gap: 10px; }
            .info-grid   { grid-template-columns: 1fr; }
            .action-section { padding: 28px 20px; }
            .btn-start-test { width: 100%; justify-content: center; }
            .attempt-row { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>

<nav class="navbar">
    <a href="student-dashboard.php" class="navbar-brand">
        <img src="prepaura-logo.png" alt="Prepaura Logo" style="width:44px;height:44px;border-radius:10px;object-fit:contain;background:white;padding:3px;">
        <div style="display:flex;flex-direction:column;line-height:1.15;">
            <span style="font-family:'Sora',sans-serif;font-size:17px;font-weight:800;letter-spacing:.5px;color:white;">PREPAURA</span>
            <span style="font-size:10.5px;font-weight:400;color:rgba(255,255,255,.65);letter-spacing:.02em;">Placement Training Platform</span>
        </div>
    </a>
    <div>
        <a href="student-assessments.php" class="btn-back">← Back to Assessments</a>
    </div>
</nav>

<div class="container">

    <!-- Test Header -->
    <div class="test-header">
        <div class="test-icon">📝</div>
        <h1 class="test-title"><?= htmlspecialchars($a['title']) ?></h1>
        <p class="test-category"><?= htmlspecialchars(ucfirst($a['category'] ?? 'General')) ?></p>
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

    <!-- Description -->
    <div class="card">
        <h2 class="section-title">📋 About This Test</h2>
        <p class="description-text">
            <?php if (!empty(trim($a['description'] ?? ''))): ?>
                <?= nl2br(htmlspecialchars($a['description'])) ?>
            <?php else: ?>
                This is a <?= $diff ?> level
                <?= htmlspecialchars(strtolower($a['category'] ?? 'general')) ?>
                assessment consisting of <?= (int)$a['question_count'] ?> questions
                to be completed in <?= (int)$a['duration_minutes'] ?> minutes.
            <?php endif ?>
        </p>
        <button class="btn-instructions" onclick="showInstructions()">📖 View Detailed Instructions</button>
    </div>

    <!-- Test Info -->
    <div class="card">
        <h2 class="section-title">ℹ️ Test Information</h2>
        <div class="info-grid">

            <div class="info-row">
                <div class="info-row-icon">📅</div>
                <div>
                    <div class="info-row-label">Available From</div>
                    <div class="info-row-value">
                        <?= $a['start_time']
                            ? fmtDt($a['start_time'])
                            : '<span style="color:#10b981;font-weight:700">Open</span>' ?>
                    </div>
                </div>
            </div>

            <div class="info-row">
                <div class="info-row-icon">⏰</div>
                <div>
                    <div class="info-row-label">Deadline</div>
                    <div class="info-row-value">
                        <?= $a['end_time']
                            ? fmtDt($a['end_time'])
                            : '<span style="color:#10b981;font-weight:700">No deadline</span>' ?>
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
                            <?= $attemptsLeft > 0 ? "{$attemptsLeft} left" : 'None left' ?>
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
    <div class="card">
        <h2 class="section-title">📜 Your Previous Attempts</h2>
        <?php foreach ($previousAttempts as $i => $pa):
            $pct    = round((float)$pa['percentage']);
            $passed = $pct >= $passPct;
            $num    = $attemptsUsed - $i;
        ?>
        <div class="attempt-row">
            <span style="font-weight:700;font-family:'Sora',sans-serif;">Attempt #<?= $num ?></span>
            <span style="color:var(--text-soft)">📅 <?= fmtDt($pa['submitted_at']) ?></span>
            <span style="color:var(--text-mid)">Score: <strong><?= round($pa['score']) ?> / <?= (int)$a['total_marks'] ?></strong></span>
            <span class="tag <?= $passed ? 'pct-pass' : 'pct-fail' ?>"><?= $pct ?>%</span>
        </div>
        <?php endforeach ?>
    </div>
    <?php endif ?>

    <!-- Action -->
    <div class="action-section">
        <?php if ($exhausted): ?>
            <p style="font-size:48px;margin-bottom:16px">🔒</p>
            <p style="font-family:'Sora',sans-serif;font-weight:800;font-size:16px;color:var(--danger);margin-bottom:8px">
                You have used all <?= (int)$a['max_attempts'] ?> attempt(s) for this test.
            </p>
            <?php if (!empty($previousAttempts)): ?>
            <p style="color:var(--text-soft);font-size:14px;margin-top:6px">
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
            <h2 class="modal-title">📖 Test Instructions</h2>
            <button class="btn-close-modal" onclick="closeInstructions()">✕</button>
        </div>
        <div class="instructions-list">
            <div class="instruction-item">
                <div class="instruction-number">1</div>
                <div class="instruction-text"><strong>Read Carefully:</strong> Read each question and all answer options thoroughly before selecting your answer.</div>
            </div>
            <div class="instruction-item">
                <div class="instruction-number">2</div>
                <div class="instruction-text"><strong>Time Management:</strong> You have <strong><?= (int)$a['duration_minutes'] ?> minutes</strong> to complete all <strong><?= (int)$a['question_count'] ?> questions</strong>. A timer will be visible at the top of your screen.</div>
            </div>
            <div class="instruction-item">
                <div class="instruction-number">3</div>
                <div class="instruction-text"><strong>Attempts:</strong>
                    <?= (int)$a['max_attempts'] === 1
                        ? 'This test allows <strong>one attempt only</strong>. You cannot retake it once started.'
                        : 'You have <strong>' . (int)$a['max_attempts'] . ' attempts</strong> allowed for this test.' ?>
                </div>
            </div>
            <div class="instruction-item">
                <div class="instruction-number">4</div>
                <div class="instruction-text"><strong>Navigation:</strong> Use "Previous" and "Next" buttons to move between questions. You can also jump directly to any question using the question palette.</div>
            </div>
            <div class="instruction-item">
                <div class="instruction-number">5</div>
                <div class="instruction-text"><strong>Scoring:</strong>
                    <?php if ($hasNeg): ?>
                        Correct answers earn marks. <strong>Wrong answers carry negative marks</strong> — avoid blind guessing.
                    <?php else: ?>
                        Each correct answer earns you points. There is no negative marking for incorrect answers.
                    <?php endif ?>
                </div>
            </div>
            <div class="instruction-item">
                <div class="instruction-number">6</div>
                <div class="instruction-text"><strong>Submission:</strong> Click "Submit Test" when you're done. You cannot change answers after submission.</div>
            </div>
            <div class="instruction-item">
                <div class="instruction-number">7</div>
                <div class="instruction-text"><strong>No Cheating:</strong> Do not use any external resources or assistance. This test must be completed independently.</div>
            </div>
        </div>
        <div class="important-notes">
            <div class="notes-title">⚠️ Important Notes</div>
            <div class="notes-list">
                <div class="note-item"><span>•</span><span>Ensure stable internet connection throughout the test</span></div>
                <div class="note-item"><span>•</span><span>Do not refresh the page or close the browser during the test</span></div>
                <div class="note-item"><span>•</span><span>Once time expires, the test will auto-submit</span></div>
                <?php if ($a['randomize_questions']): ?>
                <div class="note-item"><span>•</span><span>Questions are presented in a randomised order</span></div>
                <?php endif ?>
                <?php if ($a['randomize_options']): ?>
                <div class="note-item"><span>•</span><span>Answer option positions are shuffled — they may differ from any sample papers</span></div>
                <?php endif ?>
                <div class="note-item"><span>•</span><span>Use a laptop or desktop for the best experience</span></div>
            </div>
        </div>
    </div>
</div>

<!-- Confirm Start Modal -->
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
            <strong style="color:var(--danger)">⚠️ Negative marking applies.</strong><br>
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

    let csrfToken = '';
    (async function loadCsrfToken() {
        try {
            const base = window.location.pathname.replace(/\/[^\/]*$/, '');
            const res  = await fetch(base + '/api/csrf-token.php');
            const data = await res.json();
            if (data.success && data.token) csrfToken = data.token;
        } catch (e) { console.warn('Could not fetch CSRF token:', e); }
    })();

    function showInstructions() { document.getElementById('instructionsModal').classList.add('active'); }
    function closeInstructions(){ document.getElementById('instructionsModal').classList.remove('active'); }
    function confirmStart()     { document.getElementById('confirmModal').classList.add('active'); }
    function closeConfirm()     { document.getElementById('confirmModal').classList.remove('active'); }

    async function beginTest() {
        const beginBtn  = document.getElementById('beginBtn');
        const cancelBtn = document.getElementById('cancelBtn');
        const startBtn  = document.getElementById('startBtn');

        beginBtn.textContent = 'Starting…';
        beginBtn.disabled    = true;
        cancelBtn.disabled   = true;
        if (startBtn) startBtn.disabled = true;

        const base   = window.location.pathname.replace(/\/[^\/]*$/, '');
        const apiUrl = base + '/api/assessment/start.php';

        try {
            const res  = await fetch(apiUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body:    JSON.stringify({ assessment_id: ASSESSMENT_ID })
            });

            const text = await res.text();
            let data;
            try { data = JSON.parse(text); } catch (e) {
                console.error('start.php raw response:', text);
                throw new Error(res.status === 404
                    ? 'start.php not found (404). Check the file exists at api/assessment/start.php'
                    : 'Server error (HTTP ' + res.status + '). Check PHP error logs.');
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

    function resetButtons(b, c, s) {
        b.textContent = 'Yes, Begin!'; b.disabled = false;
        c.disabled = false;
        if (s) s.disabled = false;
    }

    function showError(msg) {
        let el = document.getElementById('startError');
        if (!el) {
            el = document.createElement('div');
            el.id = 'startError';
            el.style.cssText = 'margin-top:16px;padding:12px 20px;background:#fee2e2;color:#991b1b;' +
                'border-radius:10px;font-size:13.5px;font-weight:600;max-width:500px;margin-inline:auto;';
            document.querySelector('.action-section').appendChild(el);
        }
        el.textContent = '⚠️ ' + msg;
        el.style.display = 'block';
    }

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') { closeInstructions(); closeConfirm(); }
    });
</script>
</body>
</html>

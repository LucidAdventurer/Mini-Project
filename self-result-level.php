<?php
/* ============================================================
 * SELF ASSESSMENT — LEVEL TEST RESULT
 * self-result-level.php?attempt=X
 * ============================================================ */

require_once "config.php";
require_once "db-guard.php";

$user   = validateSession($conn, 'student');
$userId = (int) $user['user_id'];
$userName     = $user['full_name'] ?? 'Student';

$attemptId = (int)($_GET['attempt'] ?? 0);
if (!$attemptId) { header('Location: self-assessment.php'); exit; }

$aRes = safePreparedQuery($conn,
    "SELECT sa.*, s.title, s.duration_minutes, s.levels_selected
     FROM self_assessment_attempts sa
     JOIN self_assessments s ON s.sa_id = sa.sa_id
     WHERE sa.attempt_id = ? AND sa.user_id = ? AND sa.type = 'level' AND sa.status = 'submitted'",
    "ii", [$attemptId, $userId]
);
$attempt = null;
if ($aRes['success'] && $aRes['result']) {
    $attempt = $aRes['result']->fetch_assoc();
    $aRes['result']->free();
}
if (!$attempt) { header('Location: self-assessment.php'); exit; }

// Per-question answers with bank data
$answersRes = safePreparedQuery($conn,
    "SELECT ans.map_id, ans.selected_option, ans.is_correct,
            COALESCE(b.question_text, m.question_text) AS question_text,
            COALESCE(b.option_a, m.option_a) AS option_a,
            COALESCE(b.option_b, m.option_b) AS option_b,
            COALESCE(b.option_c, m.option_c) AS option_c,
            COALESCE(b.option_d, m.option_d) AS option_d,
            COALESCE(b.correct_option, m.correct_option) AS correct_option,
            COALESCE(b.explanation, m.explanation) AS explanation,
            COALESCE(b.difficulty, m.difficulty) AS difficulty,
            m.q_order
     FROM self_assessment_answers ans
     JOIN self_assessment_q_map m ON m.map_id = ans.map_id
     LEFT JOIN self_assessment_question_bank b ON b.question_id = m.bank_qid
     WHERE ans.attempt_id = ?
     ORDER BY m.q_order ASC",
    "i", [$attemptId]
);
$answerRows = [];
if ($answersRes['success'] && $answersRes['result']) {
    while ($r = $answersRes['result']->fetch_assoc()) $answerRows[] = $r;
    $answersRes['result']->free();
}

// Breakdown by difficulty
$breakdown = ['easy'=>['correct'=>0,'total'=>0],'medium'=>['correct'=>0,'total'=>0],'hard'=>['correct'=>0,'total'=>0]];
foreach ($answerRows as $r) {
    $d = $r['difficulty'] ?? 'easy';
    if (!isset($breakdown[$d])) continue;
    $breakdown[$d]['total']++;
    if ($r['is_correct']) $breakdown[$d]['correct']++;
}

$pct    = round((float)$attempt['percentage']);
$passed = $pct >= 60;
$grade  = $pct >= 90 ? 'A+' : ($pct >= 75 ? 'A' : ($pct >= 60 ? 'B' : ($pct >= 45 ? 'C' : 'F')));
$levels = array_map('ucfirst', explode(',', $attempt['levels_used'] ?? ''));

function fmtTimeR(int $s): string { return floor($s/60).'m '.($s%60).'s'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Level Test Result — PTA Platform</title>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root{--primary:#1a3a52;--accent:#0ea5e9;--success:#10b981;--danger:#ef4444;--warning:#f59e0b;--bg:#f0f4f8;--surface:#fff;--surface2:#f8fafc;--border:#e2e8f0;--text:#0f172a;--text-mid:#475569;--text-soft:#94a3b8;--radius:14px;--shadow:0 2px 14px rgba(0,0,0,.08);--nav-h:64px;}
        *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;padding-top:var(--nav-h);}
        .navbar{background:var(--primary);padding:0 24px;height:var(--nav-h);display:flex;align-items:center;justify-content:space-between;position:fixed;top:0;left:0;right:0;z-index:100;box-shadow:0 2px 12px rgba(0,0,0,.2);}
        .nav-title{font-family:'Sora',sans-serif;font-size:15px;font-weight:700;color:white;}
        .nav-link{color:rgba(255,255,255,.75);font-size:13px;text-decoration:none;padding:8px 14px;border-radius:8px;border:1px solid rgba(255,255,255,.2);transition:.2s;}
        .container{max-width:860px;margin:0 auto;padding:28px 20px 60px;}
        .diff-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11.5px;font-weight:700;}
        .diff-easy{background:#dcfce7;color:#166534;}
        .diff-medium{background:#fef3c7;color:#92400e;}
        .diff-hard{background:#fee2e2;color:#991b1b;}

        .score-card{border-radius:20px;padding:36px;margin-bottom:28px;text-align:center;position:relative;overflow:hidden;color:white;}
        .score-card.pass{background:linear-gradient(135deg,#064e3b,#065f46,#10b981);}
        .score-card.fail{background:linear-gradient(135deg,#7f1d1d,#991b1b,#ef4444);}
        .score-card::before{content:'';position:absolute;top:-60px;right:-60px;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,.06);pointer-events:none;}
        .score-emoji{font-size:52px;display:block;margin-bottom:10px;}
        .score-pct{font-family:'Sora',sans-serif;font-size:64px;font-weight:800;line-height:1;}
        .score-grade{display:inline-block;background:rgba(255,255,255,.2);border:2px solid rgba(255,255,255,.3);border-radius:12px;padding:6px 20px;font-size:20px;font-weight:800;margin:10px 0;}
        .score-label{font-size:15px;opacity:.85;margin-top:4px;}
        .score-meta{display:flex;justify-content:center;gap:20px;margin-top:18px;flex-wrap:wrap;}
        .score-meta-item{background:rgba(255,255,255,.12);border-radius:10px;padding:10px 18px;font-size:13px;font-weight:600;}
        .score-meta-item span{display:block;font-size:20px;font-weight:800;font-family:'Sora',sans-serif;}

        /* Breakdown */
        .breakdown-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:28px;}
        @media(max-width:600px){.breakdown-grid{grid-template-columns:1fr;}}
        .breakdown-card{background:var(--surface);border-radius:var(--radius);border:1.5px solid var(--border);padding:18px;text-align:center;box-shadow:var(--shadow);}
        .breakdown-label{font-size:12px;font-weight:700;color:var(--text-soft);margin-bottom:8px;}
        .breakdown-score{font-family:'Sora',sans-serif;font-size:24px;font-weight:800;}
        .breakdown-bar{height:6px;background:var(--surface2);border-radius:3px;margin-top:10px;overflow:hidden;}
        .breakdown-fill{height:100%;border-radius:3px;}

        /* Review */
        .section-title{font-family:'Sora',sans-serif;font-size:17px;font-weight:700;color:var(--text);margin-bottom:18px;}
        .q-review-card{background:var(--surface);border-radius:var(--radius);border:1.5px solid var(--border);padding:20px;margin-bottom:14px;box-shadow:var(--shadow);}
        .q-review-card.correct{border-left:4px solid var(--success);}
        .q-review-card.wrong{border-left:4px solid var(--danger);}
        .q-review-card.skipped{border-left:4px solid var(--text-soft);}
        .q-header{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:12px;}
        .q-review-text{font-size:14.5px;font-weight:600;color:var(--text);line-height:1.5;}
        .status-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 11px;border-radius:20px;font-size:12px;font-weight:700;white-space:nowrap;flex-shrink:0;}
        .status-correct{background:#dcfce7;color:#166534;}
        .status-wrong{background:#fee2e2;color:#991b1b;}
        .status-skipped{background:#f1f5f9;color:#64748b;}
        .options-review{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px;}
        @media(max-width:580px){.options-review{grid-template-columns:1fr;}}
        .opt-review{display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:8px;font-size:13px;}
        .opt-review.selected-wrong{background:#fee2e2;border:1.5px solid #fca5a5;}
        .opt-review.correct-ans{background:#dcfce7;border:1.5px solid #86efac;}
        .opt-review.neutral{background:var(--surface2);border:1.5px solid var(--border);}
        .opt-letter-sm{width:22px;height:22px;border-radius:5px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;background:rgba(0,0,0,.08);flex-shrink:0;}
        .explanation{background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 13px;font-size:13px;color:#78350f;margin-top:8px;}
        .btn{padding:12px 24px;border-radius:10px;font-size:14px;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:7px;transition:.2s;}
        .btn-primary{background:linear-gradient(135deg,var(--accent),#06b6d4);color:white;border:none;}
        .btn-secondary{background:var(--surface);color:var(--text-mid);border:1.5px solid var(--border);}
    </style>
</head>
<body>

<nav class="navbar">
    <div class="nav-title">🎯 Level Test Result</div>
    <a href="self-assessment.php?tab=level" class="nav-link">← Dashboard</a>
</nav>

<div class="container">

    <!-- Score Card -->
    <div class="score-card <?= $passed ? 'pass' : 'fail' ?>">
        <span class="score-emoji"><?= $passed ? '🏆' : '😔' ?></span>
        <div class="score-pct"><?= $pct ?>%</div>
        <div class="score-grade"><?= $grade ?></div>
        <div class="score-label">
            <?= $passed ? 'Excellent! You passed.' : 'Keep going — practice makes perfect!' ?>
        </div>
        <div style="margin-top:8px;display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
            <?php foreach($levels as $lv): ?>
            <span class="diff-badge diff-<?= strtolower($lv) ?>"><?= htmlspecialchars($lv) ?></span>
            <?php endforeach; ?>
        </div>
        <div class="score-meta">
            <div class="score-meta-item"><span><?= $attempt['score'] ?>/<?= $attempt['total'] ?></span>Score</div>
            <div class="score-meta-item"><span><?= fmtTimeR((int)$attempt['time_taken_sec']) ?></span>Time</div>
            <div class="score-meta-item"><span><?= date('d M', strtotime($attempt['submitted_at'])) ?></span>Date</div>
        </div>
    </div>

    <!-- Difficulty Breakdown -->
    <?php
    $diffColors = ['easy'=>['#10b981','#dcfce7'],'medium'=>['#f59e0b','#fef3c7'],'hard'=>['#ef4444','#fee2e2']];
    $hasBreakdown = array_sum(array_column($breakdown,'total')) > 0;
    ?>
    <?php if ($hasBreakdown): ?>
    <div class="section-title">📊 Breakdown by Difficulty</div>
    <div class="breakdown-grid" style="margin-bottom:28px;">
        <?php foreach ($breakdown as $level => $data):
            if ($data['total'] === 0) continue;
            $bpct = round(($data['correct']/$data['total'])*100);
            [$color, $bg] = $diffColors[$level];
        ?>
        <div class="breakdown-card">
            <div class="breakdown-label"><?= ucfirst($level) ?></div>
            <div class="breakdown-score" style="color:<?= $color ?>;"><?= $bpct ?>%</div>
            <div style="font-size:12px;color:var(--text-soft);margin-top:4px;"><?= $data['correct'] ?>/<?= $data['total'] ?> correct</div>
            <div class="breakdown-bar">
                <div class="breakdown-fill" style="width:<?= $bpct ?>%;background:<?= $color ?>;"></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Actions -->
    <div style="display:flex;gap:12px;margin-bottom:28px;flex-wrap:wrap;">
        <a href="self-assessment.php?tab=level" class="btn btn-secondary">← Dashboard</a>
        <a href="self-assessment.php" class="btn btn-primary" onclick="event.preventDefault();document.getElementById('newLevelForm').submit()">🔄 New Level Test</a>
    </div>
    <form id="newLevelForm" action="self-assessment.php" method="GET" style="display:none;">
        <input type="hidden" name="tab" value="level">
    </form>

    <!-- Answer Review -->
    <div class="section-title">📝 Full Answer Review</div>

    <?php foreach ($answerRows as $i => $row):
        $sel     = $row['selected_option'];
        $correct = $row['correct_option'];
        $status  = !$sel ? 'skipped' : ($row['is_correct'] ? 'correct' : 'wrong');
        $opts    = ['a'=>$row['option_a'],'b'=>$row['option_b'],'c'=>$row['option_c'],'d'=>$row['option_d']];
    ?>
    <div class="q-review-card <?= $status ?>">
        <div class="q-header">
            <div>
                <?php if ($row['difficulty']): ?>
                <span class="diff-badge diff-<?= $row['difficulty'] ?>" style="margin-bottom:6px;display:inline-flex;"><?= ucfirst($row['difficulty']) ?></span>
                <?php endif; ?>
                <div class="q-review-text"><?= $i+1 ?>. <?= htmlspecialchars($row['question_text']) ?></div>
            </div>
            <span class="status-badge status-<?= $status ?>">
                <?= $status==='correct'?'✅ Correct':($status==='wrong'?'❌ Wrong':'⏭ Skipped') ?>
            </span>
        </div>
        <div class="options-review">
            <?php foreach ($opts as $letter => $text):
                if (!$text) continue;
                $cls = 'neutral';
                if ($letter === $correct) $cls = 'correct-ans';
                elseif ($letter === $sel && !$row['is_correct']) $cls = 'selected-wrong';
            ?>
            <div class="opt-review <?= $cls ?>">
                <div class="opt-letter-sm"><?= strtoupper($letter) ?></div>
                <span><?= htmlspecialchars($text) ?></span>
                <?php if($letter===$correct): ?><span style="margin-left:auto;">✅</span><?php endif; ?>
                <?php if($letter===$sel&&!$row['is_correct']): ?><span style="margin-left:auto;">❌</span><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($row['explanation']): ?>
        <div class="explanation">💡 <?= htmlspecialchars($row['explanation']) ?></div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div style="display:flex;gap:12px;margin-top:24px;flex-wrap:wrap;">
        <a href="self-assessment.php?tab=level" class="btn btn-secondary">← Dashboard</a>
    </div>

</div>
</body>
</html>

<?php
/* ============================================================
 * SELF ASSESSMENT — PDF TEST RESULT
 * self-result-pdf.php?attempt=X
 * UI matches test-results.php exactly
 * ============================================================ */

require_once "config.php";
require_once "db-guard.php";

$user   = validateSession($conn, 'student');
$userId = (int) $user['user_id'];
$userName     = $user['full_name'] ?? 'Student';
$userEmail    = $user['email'] ?? '';
$userInitials = strtoupper(substr($userName, 0, 2));

$attemptId = (int)($_GET['attempt'] ?? 0);
if (!$attemptId) { header('Location: self-assessment.php'); exit; }

// Load attempt
$aRes = safePreparedQuery($conn,
    "SELECT sa.*, s.title, s.duration_minutes, s.pdf_path
     FROM self_assessment_attempts sa
     JOIN self_assessments s ON s.sa_id = sa.sa_id
     WHERE sa.attempt_id = ? AND sa.user_id = ? AND sa.type = 'pdf' AND sa.status = 'submitted'",
    "ii", [$attemptId, $userId]
);
$attempt = null;
if ($aRes['success'] && $aRes['result']) {
    $attempt = $aRes['result']->fetch_assoc();
    $aRes['result']->free();
}
if (!$attempt) { header('Location: self-assessment.php'); exit; }

// Load per-question answers
$answersRes = safePreparedQuery($conn,
    "SELECT ans.map_id, ans.selected_option, ans.is_correct,
            m.question_text, m.option_a, m.option_b, m.option_c, m.option_d,
            m.correct_option, m.explanation, m.q_order
     FROM self_assessment_answers ans
     JOIN self_assessment_q_map m ON m.map_id = ans.map_id
     WHERE ans.attempt_id = ?
     ORDER BY m.q_order ASC",
    "i", [$attemptId]
);
$answerRows = [];
if ($answersRes['success'] && $answersRes['result']) {
    while ($r = $answersRes['result']->fetch_assoc()) $answerRows[] = $r;
    $answersRes['result']->free();
}

// Profile image
$imgRes = safePreparedQuery($conn, "SELECT profile_image FROM users WHERE user_id = ?", "i", [$userId]);
$userProfileImage = '';
if ($imgRes['success'] && $imgRes['result']) {
    $r = $imgRes['result']->fetch_assoc();
    $userProfileImage = $r['profile_image'] ?? '';
    $imgRes['result']->free();
}

// Notifications
$notifResult = safePreparedQuery($conn, "SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0", "i", [$userId]);
$unreadCount = 0;
if ($notifResult['success'] && $notifResult['result']) {
    $notifRow = $notifResult['result']->fetch_assoc();
    $unreadCount = (int)($notifRow['cnt'] ?? 0);
    $notifResult['result']->free();
}
$notifDropResult = safePreparedQuery($conn, "SELECT notification_id, title, message, type, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC", "i", [$userId]);
$notifItems = [];
if ($notifDropResult['success'] && $notifDropResult['result']) {
    while ($row = $notifDropResult['result']->fetch_assoc()) $notifItems[] = $row;
    $notifDropResult['result']->free();
}

// Report status
$reportStatusResult = safePreparedQuery($conn, "SELECT status FROM student_reports WHERE user_id = ? ORDER BY created_at DESC LIMIT 1", "i", [$userId]);
$latestReportStatus = null;
if ($reportStatusResult['success'] && $reportStatusResult['result']) {
    $rrow = $reportStatusResult['result']->fetch_assoc();
    $latestReportStatus = $rrow['status'] ?? null;
    $reportStatusResult['result']->free();
}
$hasOpenReport = in_array($latestReportStatus, ['pending', 'in_progress']);

// Handle report submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_report') {
    $reportTitle = trim($_POST['report_title'] ?? '');
    $reportDesc  = trim($_POST['report_description'] ?? '');
    $reportImage = null;
    if (!empty($_FILES['report_image']) && $_FILES['report_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['report_image'];
        $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
        if ($file['size'] <= 5*1024*1024 && in_array($file['type'], $allowed)) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $uploadDir = 'uploads/reports/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $stored = 'report_'.$userId.'_'.time().'.'.$ext;
            if (move_uploaded_file($file['tmp_name'], $uploadDir.$stored)) $reportImage = $uploadDir.$stored;
        }
    }
    if ($reportTitle !== '' && $reportDesc !== '') {
        safePreparedQuery($conn, "INSERT INTO student_reports (user_id, title, description, image_path, status, created_at) VALUES (?,?,?,?,'pending',NOW())", "isss", [$userId, $reportTitle, $reportDesc, $reportImage]);
        $hasOpenReport = true; $latestReportStatus = 'pending';
    }
    header('Location: '.$_SERVER['PHP_SELF'].'?attempt='.$attemptId.'&report=sent');
    exit;
}

// Computed stats
$totalQ   = count($answerRows);
$correct  = array_sum(array_column($answerRows, 'is_correct'));
$wrong    = 0; $skipped = 0;
foreach ($answerRows as $r) {
    if (!$r['selected_option']) $skipped++;
    elseif (!$r['is_correct'])  $wrong++;
}
$pct    = round((float)$attempt['percentage']);
$passed = $pct >= 60;
$grade  = $pct >= 90 ? 'A+' : ($pct >= 75 ? 'A' : ($pct >= 60 ? 'B' : ($pct >= 45 ? 'C' : 'F')));

function timeAgoSA(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff/60).' min ago';
    if ($diff < 86400)  return floor($diff/3600).' hr ago';
    if ($diff < 604800) return floor($diff/86400).' day ago';
    return date('d M Y', strtotime($dt));
}
function fmtTimeSA(int $s): string { return floor($s/60).'m '.($s%60).'s'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PDF Test Result — PTA Platform</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
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
            --shadow-md:   0 4px 24px rgba(0,0,0,.10);
            --nav-h:       68px;
            --sidebar-w:   260px;
            --transition:  .2s cubic-bezier(.4,0,.2,1);
        }
        *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;padding-top:var(--nav-h);-webkit-font-smoothing:antialiased;overflow-x:hidden}

        /* ══ NAVBAR ══ */
        .navbar{background:var(--primary);padding:0 28px;height:var(--nav-h);display:flex;align-items:center;justify-content:space-between;position:fixed;top:0;left:0;right:0;z-index:1000;box-shadow:0 1px 0 rgba(255,255,255,.06),0 4px 20px rgba(0,0,0,.18)}
        .navbar-brand{display:flex;align-items:center;gap:12px;text-decoration:none}
        .nav-search{flex:1;max-width:440px;margin:0 32px;position:relative}
        .nav-search input{width:100%;padding:10px 18px 10px 42px;border:1.5px solid rgba(255,255,255,.15);border-radius:10px;font-family:'Inter',sans-serif;font-size:14px;background:rgba(255,255,255,.1);color:white;outline:none;transition:var(--transition)}
        .nav-search input::placeholder{color:rgba(255,255,255,.5)}
        .nav-search input:focus{background:rgba(255,255,255,.18);border-color:rgba(255,255,255,.35);box-shadow:0 0 0 3px rgba(14,165,233,.25)}
        .nav-search .sicon{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:rgba(255,255,255,.5);font-size:13px;pointer-events:none}
        .nav-profile{display:flex;align-items:center;gap:10px}
        .notification-icon{position:relative;width:38px;height:38px;background:rgba(255,255,255,.12);border-radius:10px;display:flex;align-items:center;justify-content:center;cursor:pointer;border:1.5px solid rgba(255,255,255,.15);transition:var(--transition);color:white}
        .notification-icon:hover{background:rgba(255,255,255,.2);border-color:rgba(255,255,255,.3)}
        .notif-dropdown-wrap{position:relative}
        .notif-dropdown{position:absolute;top:calc(100% + 12px);right:0;background:var(--surface);border-radius:var(--radius);box-shadow:var(--shadow-md);border:1px solid var(--border);width:348px;opacity:0;visibility:hidden;transform:translateY(-6px) scale(.98);transition:var(--transition);z-index:1002}
        .notif-dropdown.show{opacity:1;visibility:visible;transform:translateY(0) scale(1)}
        .notif-dropdown-header{padding:16px 20px 14px;font-family:'Sora',sans-serif;font-weight:700;font-size:14px;color:var(--text);border-bottom:1px solid var(--border)}
        .notif-list{max-height:360px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:var(--border) transparent}
        .notif-item{display:flex;gap:12px;align-items:flex-start;padding:13px 20px;border-bottom:1px solid var(--border);cursor:pointer;transition:background var(--transition)}
        .notif-item:last-child{border-bottom:none}
        .notif-item:hover{background:var(--surface2)}
        .notif-item.unread{background:#eff8ff}
        .notif-item.unread:hover{background:#e0f2fe}
        .notif-dot{width:7px;height:7px;border-radius:50%;background:var(--accent);flex-shrink:0;margin-top:5px}
        .notif-dot.read{background:transparent}
        .notif-item-body{flex:1}
        .notif-item-title{font-size:13px;font-weight:600;color:var(--text);margin-bottom:2px}
        .notif-item-msg{font-size:12px;color:var(--text-mid);line-height:1.45}
        .notif-item-time{font-size:11px;color:var(--text-soft);margin-top:4px}
        .notif-empty{padding:32px 20px;text-align:center;color:var(--text-soft);font-size:13px}
        .notif-dismiss-btn{background:none;border:none;color:var(--text-soft);font-size:13px;line-height:1;padding:2px 5px;border-radius:4px;cursor:pointer;flex-shrink:0;opacity:0;transition:opacity .15s,background .15s,color .15s;align-self:flex-start;margin-top:2px}
        .notif-item:hover .notif-dismiss-btn{opacity:1}
        .notif-dismiss-btn:hover{background:rgba(239,68,68,.1);color:#ef4444}
        .notification-badge{position:absolute;top:-4px;right:-4px;background:var(--danger);color:white;min-width:18px;height:18px;border-radius:9px;padding:0 4px;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;animation:badgePulse 2s ease-in-out infinite;border:2px solid var(--primary)}
        @keyframes badgePulse{0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.5)}60%{box-shadow:0 0 0 5px rgba(239,68,68,0)}}
        .profile-dropdown-container{position:relative}
        .profile-button{display:flex;align-items:center;gap:9px;padding:6px 12px 6px 6px;background:rgba(255,255,255,.12);border:1.5px solid rgba(255,255,255,.15);border-radius:10px;cursor:pointer;transition:var(--transition)}
        .profile-button:hover{background:rgba(255,255,255,.2);border-color:rgba(255,255,255,.3)}
        .profile-avatar{width:32px;height:32px;background:linear-gradient(135deg,var(--accent),var(--accent2));border-radius:8px;display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:13px;font-family:'Sora',sans-serif}
        .profile-name{font-weight:600;font-size:13.5px;color:rgba(255,255,255,.95)}
        .dropdown-arrow{font-size:10px;color:rgba(255,255,255,.6)}
        .profile-dropdown{position:absolute;top:calc(100% + 12px);right:0;background:var(--surface);border-radius:var(--radius);box-shadow:var(--shadow-md);border:1px solid var(--border);min-width:240px;opacity:0;visibility:hidden;transform:translateY(-6px) scale(.98);transition:var(--transition);z-index:1001;overflow:hidden}
        .profile-dropdown.show{opacity:1;visibility:visible;transform:translateY(0) scale(1)}
        .dropdown-header{padding:18px 20px;background:linear-gradient(135deg,var(--primary),var(--primary-mid));display:flex;gap:12px;align-items:center}
        .dropdown-avatar{width:44px;height:44px;background:linear-gradient(135deg,var(--accent),var(--accent2));border-radius:12px;display:flex;align-items:center;justify-content:center;color:white;font-weight:800;font-size:18px;font-family:'Sora',sans-serif;flex-shrink:0}
        .dropdown-user-info{flex:1;overflow:hidden}
        .dropdown-user-name{font-family:'Sora',sans-serif;font-weight:700;font-size:15px;color:white;margin-bottom:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .dropdown-user-email{font-size:12px;color:rgba(255,255,255,.65);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .dropdown-menu{padding:8px}
        .dropdown-item{display:flex;align-items:center;gap:11px;padding:10px 12px;border-radius:8px;color:var(--text-mid);text-decoration:none;cursor:pointer;border:none;background:none;width:100%;text-align:left;font-size:13.5px;font-family:'Inter',sans-serif;transition:var(--transition)}
        .dropdown-item:hover{background:var(--surface2);color:var(--text)}
        .dropdown-item-icon{font-size:16px;width:20px;text-align:center;flex-shrink:0}
        .dropdown-divider{height:1px;background:var(--border);margin:6px 8px}
        .dropdown-item.logout{color:var(--danger)}
        .dropdown-item.logout:hover{background:#fef2f2}
        .dropdown-overlay{position:fixed;inset:0;background:transparent;z-index:999;display:none}
        .dropdown-overlay.show{display:block}

        /* ══ LAYOUT ══ */
        .page-wrapper{display:flex;min-height:calc(100vh - var(--nav-h))}
        .left-sidebar{width:var(--sidebar-w);flex-shrink:0;padding:20px 12px;display:flex;flex-direction:column;gap:2px;background:var(--surface);border-right:1px solid var(--border);min-height:calc(100vh - var(--nav-h));position:sticky;top:var(--nav-h);align-self:flex-start}
        .left-sidebar-label{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--text-soft);padding:14px 12px 7px}
        .left-sidebar a{display:flex;align-items:center;gap:10px;padding:10px 13px;border-radius:var(--radius-sm);text-decoration:none;font-size:13.5px;font-weight:500;color:var(--text-mid);transition:var(--transition);position:relative}
        .left-sidebar a:hover{background:var(--surface2);color:var(--primary)}
        .left-sidebar a.active{background:linear-gradient(135deg,#e0f2fe,#e0f9ff);color:var(--accent);font-weight:600}
        .left-sidebar a.active::before{content:'';position:absolute;left:0;top:20%;bottom:20%;width:3px;border-radius:0 3px 3px 0;background:var(--accent)}
        .left-sidebar a i{width:18px;text-align:center;font-size:14px;flex-shrink:0}
        .left-sidebar-bottom{margin-top:auto;padding-top:12px;border-top:1px solid var(--border)}
        .left-sidebar-bottom button{display:flex;align-items:center;gap:10px;padding:10px 13px;border-radius:var(--radius-sm);font-size:13.5px;font-weight:500;color:var(--danger);background:none;border:none;cursor:pointer;width:100%;transition:var(--transition);font-family:'Inter',sans-serif}
        .left-sidebar-bottom button:hover{background:#fef2f2}
        .left-sidebar-bottom button i{width:18px;text-align:center;font-size:14px}
        .page-content{flex:1;min-width:0;padding:28px 28px 40px 0}
        @media(max-width:900px){.left-sidebar{display:none}.page-content{padding:20px}}

        /* ══ RESULTS HEADER ══ */
        .results-header{background:linear-gradient(135deg,var(--primary) 0%,#1e5276 60%,#1a6fa0 100%);border-radius:var(--radius);padding:40px 36px;margin-bottom:24px;text-align:center;position:relative;overflow:hidden;box-shadow:0 4px 24px rgba(26,58,82,.3)}
        .results-header::before{content:'';position:absolute;top:-60px;right:-60px;width:220px;height:220px;border-radius:50%;background:rgba(255,255,255,.05);pointer-events:none}
        .results-header::after{content:'';position:absolute;bottom:-80px;left:80px;width:180px;height:180px;border-radius:50%;background:rgba(14,165,233,.08);pointer-events:none}
        .test-title-h{font-family:'Sora',sans-serif;font-size:26px;font-weight:800;color:white;margin-bottom:8px;position:relative;z-index:1}
        .test-date{font-size:13.5px;color:rgba(255,255,255,.7);margin-bottom:28px;position:relative;z-index:1}

        /* Score circle */
        .score-display{display:flex;justify-content:center;margin-bottom:28px;position:relative;z-index:1}
        .score-circle{position:relative;width:190px;height:190px;border-radius:50%;background:conic-gradient(rgba(255,255,255,.9) 0%,rgba(255,255,255,.9) var(--score-pct),rgba(255,255,255,.15) var(--score-pct),rgba(255,255,255,.15) 100%);display:flex;align-items:center;justify-content:center;box-shadow:0 8px 30px rgba(0,0,0,.2)}
        .score-circle::before{content:'';position:absolute;width:160px;height:160px;border-radius:50%;background:linear-gradient(135deg,var(--primary),#1e5276)}
        .score-content{position:relative;z-index:1;text-align:center}
        .score-value{font-family:'Sora',sans-serif;font-size:46px;font-weight:800;color:white;line-height:1}
        .score-denom{font-size:13px;color:rgba(255,255,255,.7)}
        .score-pct-text{font-family:'Sora',sans-serif;font-size:17px;color:rgba(255,255,255,.9);margin-top:4px;font-weight:700}

        /* Performance badge */
        .performance-badge{display:inline-block;padding:9px 22px;border-radius:20px;font-family:'Sora',sans-serif;font-size:14px;font-weight:700;margin-bottom:22px;position:relative;z-index:1}
        .badge-excellent{background:linear-gradient(135deg,var(--success),#34d399);color:white}
        .badge-good{background:linear-gradient(135deg,var(--accent),var(--accent2));color:white}
        .badge-average{background:linear-gradient(135deg,var(--warning),#fbbf24);color:white}
        .badge-poor{background:linear-gradient(135deg,var(--danger),#f87171);color:white}

        /* Quick stats */
        .quick-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:14px;margin-top:4px;position:relative;z-index:1}
        .stat-item{text-align:center;padding:14px 10px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);border-radius:12px;backdrop-filter:blur(8px);opacity:0}
        .stat-value{font-family:'Sora',sans-serif;font-size:22px;font-weight:800;color:white;margin-bottom:4px}
        .stat-label{font-size:11.5px;color:rgba(255,255,255,.65)}

        /* ══ ANALYSIS GRID ══ */
        .analysis-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;margin-bottom:24px}
        .analysis-card{background:var(--surface);border-radius:var(--radius);padding:24px;box-shadow:var(--shadow);border:1px solid var(--border);opacity:0}
        .analysis-title{font-family:'Sora',sans-serif;font-size:16px;font-weight:700;color:var(--text);margin-bottom:18px;display:flex;align-items:center;gap:10px}
        .analysis-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px}
        .icon-correct{background:#d1fae5}
        .icon-time{background:#e0f2fe}
        .breakdown-list{display:flex;flex-direction:column;gap:10px}
        .breakdown-item{display:flex;justify-content:space-between;align-items:center;padding:11px 13px;background:var(--surface2);border-radius:var(--radius-sm);border:1px solid var(--border)}
        .breakdown-label{font-size:13.5px;color:var(--text-mid)}
        .breakdown-value{font-family:'Sora',sans-serif;font-size:15px;font-weight:700;color:var(--text)}
        .progress-bar{width:100%;height:7px;background:var(--border);border-radius:10px;overflow:hidden;margin-top:6px}
        .progress-fill{height:100%;border-radius:10px;transition:width .5s ease}
        .fill-correct{background:linear-gradient(90deg,var(--success),#34d399)}
        .fill-incorrect{background:linear-gradient(90deg,var(--danger),#f87171)}

        /* ══ QUESTIONS SECTION ══ */
        .questions-section{background:var(--surface);border-radius:var(--radius);padding:28px;box-shadow:var(--shadow);border:1px solid var(--border)}
        .section-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:14px}
        .section-title{font-family:'Sora',sans-serif;font-size:20px;font-weight:800;color:var(--text)}
        .filter-buttons{display:flex;gap:8px;flex-wrap:wrap}
        .filter-btn{padding:7px 16px;background:var(--surface2);border:1.5px solid var(--border);border-radius:8px;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;color:var(--text-mid);cursor:pointer;transition:var(--transition)}
        .filter-btn.active{background:var(--accent);color:white;border-color:var(--accent);box-shadow:0 2px 8px rgba(14,165,233,.3)}
        .filter-btn:hover:not(.active){background:var(--border);color:var(--text)}
        .questions-list{display:flex;flex-direction:column;gap:18px;margin-top:18px}
        .question-card{border:1.5px solid var(--border);border-radius:var(--radius);padding:24px;transition:var(--transition)}
        .question-card.hidden{display:none}
        .question-card.correct{border-color:var(--success);background:linear-gradient(135deg,rgba(16,185,129,.04),rgba(52,211,153,.04))}
        .question-card.incorrect{border-color:var(--danger);background:linear-gradient(135deg,rgba(239,68,68,.04),rgba(248,113,113,.04))}
        .question-card.skipped{border-color:var(--warning);background:rgba(245,158,11,.03)}
        .question-header-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}
        .question-number{font-family:'Sora',sans-serif;font-size:15px;font-weight:700;color:var(--text-mid)}
        .result-badge{padding:5px 12px;border-radius:6px;font-family:'Sora',sans-serif;font-size:11.5px;font-weight:700;display:flex;align-items:center;gap:5px}
        .result-badge.correct{background:#dcfce7;color:#166534}
        .result-badge.incorrect{background:#fee2e2;color:#991b1b}
        .result-badge.skipped{background:#fef3c7;color:#92400e}
        .question-text{font-size:16px;color:var(--text);margin-bottom:18px;line-height:1.65}
        .answer-options{display:flex;flex-direction:column;gap:10px}
        .answer-option{display:flex;align-items:center;padding:13px 16px;border-radius:var(--radius-sm);background:var(--surface2);border:1.5px solid transparent}
        .answer-option.correct-highlight{border-color:var(--success);background:#f0fdf4}
        .answer-option.wrong-highlight{border-color:var(--danger);background:#fef2f2}
        .answer-option.user-selected{border-color:var(--accent);background:#eff8ff}
        .option-label{font-weight:700;margin-right:12px;min-width:28px;color:var(--text)}
        .option-text{flex:1;color:var(--text);font-size:14px}
        .option-badge{padding:3px 9px;border-radius:5px;font-size:11px;font-weight:700;margin-left:8px;font-family:'Sora',sans-serif}
        .badge-yours{background:#e0f2fe;color:#075985}
        .badge-correct-lbl{background:#dcfce7;color:#166534}
        .explanation-box{margin-top:14px;padding:13px 16px;background:#fefce8;border-left:4px solid var(--warning);border-radius:0 var(--radius-sm) var(--radius-sm) 0;font-size:13.5px;color:#92400e;line-height:1.6}
        .explanation-box strong{display:block;margin-bottom:4px}

        /* ══ ACTION SECTION ══ */
        .action-section{margin-top:28px;padding:22px;background:var(--surface2);border-radius:var(--radius);border:1px solid var(--border);display:flex;justify-content:center;gap:14px;flex-wrap:wrap}
        .action-btn{padding:11px 28px;border:none;border-radius:var(--radius-sm);font-family:'Inter',sans-serif;font-weight:700;font-size:13.5px;cursor:pointer;transition:var(--transition);display:flex;align-items:center;gap:8px;text-decoration:none}
        .btn-primary{background:linear-gradient(135deg,var(--accent),var(--accent2));color:white;box-shadow:0 2px 8px rgba(14,165,233,.3)}
        .btn-primary:hover{transform:translateY(-2px);box-shadow:0 4px 14px rgba(14,165,233,.45)}
        .btn-secondary{background:var(--surface);color:var(--accent);border:1.5px solid var(--accent)}
        .btn-secondary:hover{background:var(--accent);color:white}

        /* ══ REPORT MODAL ══ */
        .report-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9100;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(4px);opacity:0;visibility:hidden;transition:opacity .25s,visibility .25s}
        .report-modal-overlay.open{opacity:1;visibility:visible}
        .report-modal{background:#fff;border-radius:20px;width:100%;max-width:500px;margin:16px;box-shadow:0 24px 64px rgba(0,0,0,.22);overflow:hidden;transform:translateY(18px) scale(.97);transition:transform .28s cubic-bezier(.4,0,.2,1)}
        .report-modal-overlay.open .report-modal{transform:translateY(0) scale(1)}
        .report-modal-header{background:linear-gradient(135deg,#1a3a52,#1e5276);padding:22px 24px 18px;display:flex;align-items:flex-start;justify-content:space-between}
        .report-modal-title{font-family:'Sora',sans-serif;font-size:17px;font-weight:800;color:#fff;margin-bottom:4px}
        .report-modal-sub{font-size:12px;color:rgba(255,255,255,.6)}
        .report-modal-close{background:rgba(255,255,255,.15);border:none;border-radius:8px;color:#fff;width:30px;height:30px;font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .15s;margin-left:12px}
        .report-modal-close:hover{background:rgba(255,255,255,.28)}
        .report-modal-body{padding:24px;display:flex;flex-direction:column;gap:16px}
        .report-field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin-bottom:6px}
        .report-field label span{color:#ef4444;margin-left:2px}
        .report-field input,.report-field textarea{width:100%;padding:11px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-family:'Inter',sans-serif;font-size:13.5px;color:#0f172a;outline:none;transition:border-color .15s,box-shadow .15s;resize:vertical}
        .report-field input:focus,.report-field textarea:focus{border-color:#0ea5e9;box-shadow:0 0 0 3px rgba(14,165,233,.15)}
        .report-drop-zone{border:2px dashed #cbd5e1;border-radius:12px;padding:20px;text-align:center;cursor:pointer;background:#f8fafc;transition:border-color .2s,background .2s}
        .report-drop-zone:hover,.report-drop-zone.dragover{border-color:#0ea5e9;background:#eff8ff}
        .report-drop-zone .dz-icon{font-size:28px;margin-bottom:6px}
        .report-drop-zone .dz-text{font-size:13.5px;font-weight:600;color:#475569}
        .report-drop-zone .dz-sub{font-size:12px;color:#94a3b8;margin-top:3px}
        .report-img-preview{max-width:100%;max-height:140px;border-radius:8px;object-fit:contain;display:none;margin:8px auto 0}
        .report-modal-footer{padding:0 24px 22px;display:flex;gap:10px}
        .btn-report-cancel{flex:1;padding:11px;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;color:#475569;font-size:13.5px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;transition:.15s}
        .btn-report-cancel:hover{background:#f1f5f9}
        .btn-report-submit{flex:1;padding:11px;border-radius:10px;border:none;background:linear-gradient(135deg,#0ea5e9,#06b6d4);color:#fff;font-size:13.5px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;transition:.15s}
        .btn-report-submit:hover{opacity:.9}

        @keyframes fadeInUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
        @media(max-width:768px){.navbar{padding:0 16px}.nav-search{display:none}.profile-name{display:none}.results-header{padding:28px 20px}.score-circle{width:155px;height:155px}.score-circle::before{width:127px;height:127px}.score-value{font-size:36px}.quick-stats{grid-template-columns:repeat(2,1fr)}.analysis-grid{grid-template-columns:1fr}.section-header{flex-direction:column;align-items:flex-start}.question-header-row{flex-direction:column;align-items:flex-start;gap:8px}.action-section{flex-direction:column}.action-btn{width:100%;justify-content:center}}
        @media print{.navbar,.action-section,.filter-buttons,.left-sidebar{display:none}body{background:white;padding-top:0}.page-content{padding:0}}
    </style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar">
    <a href="student-dashboard.php" class="navbar-brand">
        <img src="prepaura-logo.png" alt="Prepaura Logo" style="width:44px;height:44px;border-radius:10px;object-fit:contain;background:white;padding:3px;">
        <div style="display:flex;flex-direction:column;line-height:1.15;">
            <span style="font-family:'Sora',sans-serif;font-size:17px;font-weight:800;letter-spacing:.5px;color:white;">PREPAURA</span>
            <span style="font-size:10.5px;font-weight:400;color:rgba(255,255,255,.65);">Placement Training Platform</span>
        </div>
    </a>
    <div class="nav-search">
        <i class="fa fa-search sicon"></i>
        <input type="text" id="searchInput" placeholder="Search questions..." autocomplete="off">
    </div>
    <div class="nav-profile">
        <div class="notif-dropdown-wrap">
            <button class="notification-icon" onclick="toggleNotifDropdown()" id="notifBtn">
                <span>🔔</span>
                <?php if ($unreadCount > 0): ?>
                <div class="notification-badge"><?= $unreadCount ?></div>
                <?php endif; ?>
            </button>
            <div class="notif-dropdown" id="notifDropdown">
                <div class="notif-dropdown-header">Notifications</div>
                <div class="notif-list">
                    <?php if (empty($notifItems)): ?>
                        <div class="notif-empty">No notifications yet.</div>
                    <?php else: foreach ($notifItems as $n):
                        $isUnread = !$n['is_read'];
                        $typeIcons = ['info'=>'ℹ️','success'=>'✅','warning'=>'⚠️','error'=>'❌','assessment'=>'📝','result'=>'🏆','material'=>'📚'];
                        $icon = $typeIcons[$n['type']] ?? '🔔';
                    ?>
                    <div class="notif-item <?= $isUnread ? 'unread' : '' ?>" id="notif-<?= (int)$n['notification_id'] ?>">
                        <div class="notif-dot <?= $isUnread ? '' : 'read' ?>"></div>
                        <div class="notif-item-body">
                            <div class="notif-item-title"><?= $icon ?> <?= htmlspecialchars($n['title']) ?></div>
                            <?php if ($n['message']): ?><div class="notif-item-msg"><?= htmlspecialchars($n['message']) ?></div><?php endif; ?>
                            <div class="notif-item-time"><?= timeAgoSA($n['created_at']) ?></div>
                        </div>
                        <button class="notif-dismiss-btn" onclick="event.stopPropagation();dismissNotification(<?= (int)$n['notification_id'] ?>)">✕</button>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
        <div class="profile-dropdown-container">
            <button class="profile-button" onclick="toggleProfileDropdown()">
                <?php if ($userProfileImage && file_exists($userProfileImage)): ?>
                    <img src="<?= htmlspecialchars($userProfileImage) ?>?v=<?= time() ?>" alt="Avatar" style="width:32px;height:32px;border-radius:8px;object-fit:cover;flex-shrink:0;">
                <?php else: ?>
                    <div class="profile-avatar"><?= $userInitials ?></div>
                <?php endif; ?>
                <span class="profile-name"><?= htmlspecialchars($userName) ?></span>
                <span class="dropdown-arrow">▼</span>
            </button>
            <div class="profile-dropdown" id="profileDropdown">
                <div class="dropdown-header">
                    <?php if ($userProfileImage && file_exists($userProfileImage)): ?>
                        <img src="<?= htmlspecialchars($userProfileImage) ?>?v=<?= time() ?>" alt="Avatar" style="width:44px;height:44px;border-radius:50%;object-fit:cover;">
                    <?php else: ?>
                        <div class="dropdown-avatar"><?= $userInitials ?></div>
                    <?php endif; ?>
                    <div class="dropdown-user-info">
                        <div class="dropdown-user-name"><?= htmlspecialchars($userName) ?></div>
                        <div class="dropdown-user-email"><?= htmlspecialchars($userEmail) ?></div>
                    </div>
                </div>
                <div class="dropdown-menu">
                    <a href="student-profile.php" class="dropdown-item"><span class="dropdown-item-icon">👤</span><span>My Profile</span></a>
                    <button onclick="openReportModal();closeProfileDropdown();" class="dropdown-item" style="background:none;border:none;width:100%;text-align:left;cursor:pointer;">
                        <span class="dropdown-item-icon">🚩</span><span>Help &amp; Support</span>
                    </button>
                    <div class="dropdown-divider"></div>
                    <button onclick="handleLogout()" class="dropdown-item logout"><span class="dropdown-item-icon">🚪</span><span>Logout</span></button>
                </div>
            </div>
        </div>
    </div>
</nav>

<div class="dropdown-overlay" id="dropdownOverlay" onclick="closeAllDropdowns()"></div>

<div class="page-wrapper">
    <aside class="left-sidebar">
        <span class="left-sidebar-label">Navigation</span>
        <a href="student-dashboard.php"><i class="fa fa-home"></i> Dashboard</a>
        <a href="self-assessment.php" class="active"><i class="fa fa-clipboard-list"></i> Self Assessment</a>
        <a href="student-resources.php"><i class="fa fa-folder-open"></i> Resources</a>
        <div class="left-sidebar-bottom">
            <button onclick="handleLogout()"><i class="fa fa-sign-out-alt"></i> Logout</button>
        </div>
    </aside>

    <div class="page-content">

        <!-- Results Header -->
        <div class="results-header">
            <h1 class="test-title-h">📄 <?= htmlspecialchars($attempt['title']) ?></h1>
            <p class="test-date">Completed on <?= date('d F Y \a\t h:i A', strtotime($attempt['submitted_at'])) ?></p>

            <div class="score-display">
                <div class="score-circle" id="scoreCircle" style="--score-pct:<?= $pct ?>%">
                    <div class="score-content">
                        <div class="score-value"><?= $attempt['score'] ?></div>
                        <div class="score-denom">out of <?= $attempt['total'] ?></div>
                        <div class="score-pct-text"><?= $pct ?>%</div>
                    </div>
                </div>
            </div>

            <?php
            if ($pct >= 90)      { $badgeCls = 'badge-excellent'; $badgeTxt = '🏆 Excellent Performance!'; }
            elseif ($pct >= 75)  { $badgeCls = 'badge-good';      $badgeTxt = '🎉 Good Performance!'; }
            elseif ($pct >= 60)  { $badgeCls = 'badge-average';   $badgeTxt = '👍 Average Performance'; }
            else                  { $badgeCls = 'badge-poor';       $badgeTxt = '📚 Keep Practicing!'; }
            ?>
            <div class="performance-badge <?= $badgeCls ?>"><?= $badgeTxt ?></div>

            <div class="quick-stats">
                <div class="stat-item"><div class="stat-value"><?= $correct ?></div><div class="stat-label">Correct</div></div>
                <div class="stat-item"><div class="stat-value"><?= $wrong ?></div><div class="stat-label">Incorrect</div></div>
                <div class="stat-item"><div class="stat-value"><?= $skipped ?></div><div class="stat-label">Skipped</div></div>
                <div class="stat-item"><div class="stat-value"><?= fmtTimeSA((int)$attempt['time_taken_sec']) ?></div><div class="stat-label">Time Taken</div></div>
            </div>
        </div>

        <!-- Analysis Grid -->
        <div class="analysis-grid">
            <div class="analysis-card">
                <h3 class="analysis-title"><div class="analysis-icon icon-correct">✓</div>Accuracy Breakdown</h3>
                <div class="breakdown-list">
                    <?php $corrPct = $totalQ > 0 ? round(($correct/$totalQ)*100) : 0; $wrPct = $totalQ > 0 ? round(($wrong/$totalQ)*100) : 0; $skipPct = $totalQ > 0 ? round(($skipped/$totalQ)*100) : 0; ?>
                    <div class="breakdown-item"><div class="breakdown-label">Correct Answers</div><div class="breakdown-value"><?= $correct ?> / <?= $totalQ ?></div></div>
                    <div class="progress-bar"><div class="progress-fill fill-correct" style="width:<?= $corrPct ?>%"></div></div>
                    <div class="breakdown-item"><div class="breakdown-label">Incorrect Answers</div><div class="breakdown-value"><?= $wrong ?> / <?= $totalQ ?></div></div>
                    <div class="progress-bar"><div class="progress-fill fill-incorrect" style="width:<?= $wrPct ?>%"></div></div>
                    <div class="breakdown-item"><div class="breakdown-label">Skipped</div><div class="breakdown-value"><?= $skipped ?> / <?= $totalQ ?></div></div>
                    <div class="progress-bar"><div class="progress-fill" style="width:<?= $skipPct ?>%;background:var(--text-soft)"></div></div>
                </div>
            </div>
            <div class="analysis-card">
                <h3 class="analysis-title"><div class="analysis-icon icon-time">⏱️</div>Time Analysis</h3>
                <div class="breakdown-list">
                    <?php $avgSec = $totalQ > 0 ? round((int)$attempt['time_taken_sec'] / $totalQ) : 0; ?>
                    <div class="breakdown-item"><div class="breakdown-label">Total Time Taken</div><div class="breakdown-value"><?= fmtTimeSA((int)$attempt['time_taken_sec']) ?></div></div>
                    <div class="breakdown-item"><div class="breakdown-label">Avg per Question</div><div class="breakdown-value"><?= fmtTimeSA($avgSec) ?></div></div>
                    <div class="breakdown-item"><div class="breakdown-label">Duration Allowed</div><div class="breakdown-value"><?= $attempt['duration_minutes'] ?> min</div></div>
                    <div class="breakdown-item"><div class="breakdown-label">Grade</div><div class="breakdown-value"><?= $grade ?></div></div>
                </div>
            </div>
        </div>

        <!-- Questions Review -->
        <div class="questions-section">
            <div class="section-header">
                <h2 class="section-title">Answer Review</h2>
                <div class="filter-buttons" id="filterButtons">
                    <button class="filter-btn active" data-filter="all">All Questions (<?= $totalQ ?>)</button>
                    <button class="filter-btn" data-filter="correct">✓ Correct (<?= $correct ?>)</button>
                    <button class="filter-btn" data-filter="incorrect">✗ Incorrect (<?= $wrong ?>)</button>
                    <button class="filter-btn" data-filter="skipped">— Skipped (<?= $skipped ?>)</button>
                </div>
            </div>

            <div class="questions-list" id="questionsList">
                <?php foreach ($answerRows as $i => $row):
                    $sel     = $row['selected_option'];
                    $correct_opt = $row['correct_option'];
                    $status  = !$sel ? 'skipped' : ($row['is_correct'] ? 'correct' : 'incorrect');
                    $opts    = ['a'=>$row['option_a'],'b'=>$row['option_b'],'c'=>$row['option_c'],'d'=>$row['option_d']];
                ?>
                <div class="question-card <?= $status ?>" data-status="<?= $status ?>" data-text="<?= strtolower(htmlspecialchars($row['question_text'])) ?>">
                    <div class="question-header-row">
                        <div class="question-number">Question <?= $i+1 ?></div>
                        <?php if ($status === 'correct'): ?>
                            <span class="result-badge correct">✓ Correct</span>
                        <?php elseif ($status === 'incorrect'): ?>
                            <span class="result-badge incorrect">✗ Incorrect</span>
                        <?php else: ?>
                            <span class="result-badge skipped">— Skipped</span>
                        <?php endif; ?>
                    </div>
                    <p class="question-text"><?= htmlspecialchars($row['question_text']) ?></p>
                    <div class="answer-options">
                        <?php foreach ($opts as $letter => $text):
                            if (!$text) continue;
                            $isCorrect  = ($letter === $correct_opt);
                            $isSelected = ($letter === $sel);
                            $cls = '';
                            if ($isCorrect)                    $cls .= ' correct-highlight';
                            if ($isSelected && $isCorrect)     $cls .= ' user-selected';
                            if ($isSelected && !$row['is_correct']) $cls .= ' wrong-highlight user-selected';
                        ?>
                        <div class="answer-option<?= $cls ?>">
                            <span class="option-label"><?= strtoupper($letter) ?>)</span>
                            <span class="option-text"><?= htmlspecialchars($text) ?></span>
                            <?php if ($isCorrect): ?><span class="option-badge badge-correct-lbl">Correct Answer</span><?php endif; ?>
                            <?php if ($isSelected && !$row['is_correct']): ?><span class="option-badge badge-yours">Your Answer</span><?php endif; ?>
                            <?php if ($isSelected && $row['is_correct']): ?><span class="option-badge badge-correct-lbl">Your Answer ✓</span><?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($row['explanation']): ?>
                    <div class="explanation-box"><strong>💡 Explanation:</strong><?= htmlspecialchars($row['explanation']) ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="action-section">
                <button class="action-btn btn-primary" onclick="window.print()">🖨️ Download Results</button>
                <a href="self-take-pdf-test.php?sa_id=<?= $attempt['sa_id'] ?>" class="action-btn btn-secondary">🔄 Retake Test</a>
                <a href="self-assessment.php" class="action-btn btn-secondary">← Back to Dashboard</a>
            </div>
        </div>

    </div><!-- /.page-content -->
</div><!-- /.page-wrapper -->

<!-- REPORT MODAL -->
<div class="report-modal-overlay" id="reportModalOverlay" onclick="if(event.target===this)closeReportModal()">
    <div class="report-modal">
        <div class="report-modal-header">
            <div>
                <div class="report-modal-title">🚩 Report an Issue</div>
                <div class="report-modal-sub">We'll review your report and get back to you</div>
            </div>
            <button class="report-modal-close" onclick="closeReportModal()">✕</button>
        </div>
        <?php if (!empty($_GET['report']) && $_GET['report'] === 'sent'): ?>
        <div style="margin:16px 24px 0;padding:12px 16px;border-radius:10px;background:#d1fae5;border:1px solid #a7f3d0;font-size:13px;font-weight:600;color:#065f46;display:flex;align-items:center;gap:8px;">✅ Your report was submitted!</div>
        <?php endif; ?>
        <?php if ($latestReportStatus): ?>
        <div style="margin:12px 24px 0;padding:12px 16px;border-radius:10px;background:<?= $hasOpenReport ? '#fff8ed' : '#d1fae5' ?>;border:1px solid <?= $hasOpenReport ? '#f59e0b' : '#a7f3d0' ?>;font-size:13px;font-weight:600;color:<?= $hasOpenReport ? '#92400e' : '#065f46' ?>;display:flex;align-items:center;gap:8px;">
            <?= $hasOpenReport ? '⏳ Your last report is <strong>'.ucfirst(str_replace('_',' ',$latestReportStatus)).'</strong> — admin will respond soon.' : '✅ Your last report has been <strong>Resolved</strong>.' ?>
        </div>
        <?php endif; ?>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="submit_report">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <div class="report-modal-body">
                <div class="report-field">
                    <label>Report Title <span>*</span></label>
                    <input type="text" name="report_title" placeholder="e.g. Question seems incorrect" required maxlength="150">
                </div>
                <div class="report-field">
                    <label>Explanation <span>*</span></label>
                    <textarea name="report_description" rows="4" placeholder="Describe the issue in detail..." required maxlength="2000"></textarea>
                </div>
                <div class="report-field">
                    <label>Screenshot / Image <span style="color:#94a3b8;font-weight:500;">(optional)</span></label>
                    <label for="reportImageInput" class="report-drop-zone" id="reportDropZone">
                        <div class="dz-icon">📷</div>
                        <div class="dz-text">Click to upload or drag & drop</div>
                        <div class="dz-sub">JPG, PNG, GIF, WEBP — max 5 MB</div>
                    </label>
                    <input type="file" name="report_image" id="reportImageInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none" onchange="previewReportImg(this)">
                    <img id="reportImgPreview" class="report-img-preview" alt="Preview">
                </div>
            </div>
            <div class="report-modal-footer">
                <button type="button" class="btn-report-cancel" onclick="closeReportModal()">Cancel</button>
                <button type="submit" class="btn-report-submit">🚩 Submit Report</button>
            </div>
        </form>
    </div>
</div>

<script>
const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;

function toggleProfileDropdown() {
    document.getElementById('profileDropdown').classList.toggle('show');
    document.getElementById('dropdownOverlay').classList.toggle('show');
}
function closeProfileDropdown() {
    document.getElementById('profileDropdown').classList.remove('show');
    document.getElementById('dropdownOverlay').classList.remove('show');
}
function toggleNotifDropdown() {
    const dd = document.getElementById('notifDropdown');
    const overlay = document.getElementById('dropdownOverlay');
    const isOpen = dd.classList.contains('show');
    document.getElementById('profileDropdown').classList.remove('show');
    dd.classList.toggle('show', !isOpen);
    overlay.classList.toggle('show', !isOpen);
    if (!isOpen) {
        fetch('api/notifications/mark-read.php', { method:'POST', headers:{'X-CSRF-Token':CSRF_TOKEN,'Content-Type':'application/json'} })
        .then(() => {
            const badge = document.querySelector('.notification-badge');
            if (badge) badge.remove();
            document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
            document.querySelectorAll('.notif-dot:not(.read)').forEach(el => el.classList.add('read'));
        }).catch(()=>{});
    }
}
function closeAllDropdowns() {
    document.getElementById('profileDropdown').classList.remove('show');
    document.getElementById('notifDropdown').classList.remove('show');
    document.getElementById('dropdownOverlay').classList.remove('show');
}
function handleLogout() {
    if (confirm('Are you sure you want to logout?')) window.location.href = 'logout.php';
}
async function dismissNotification(notifId) {
    const el = document.getElementById('notif-' + notifId);
    if (el) { el.style.opacity = '0.4'; el.style.pointerEvents = 'none'; }
    try {
        const res = await fetch('api/notifications/dismiss-notification.php', {
            method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF_TOKEN},
            body: JSON.stringify({action:'dismiss_one',notification_id:notifId})
        });
        const data = await res.json();
        if (!data.success) { if (el) { el.style.opacity='1'; el.style.pointerEvents=''; } return; }
    } catch(e) { if (el) { el.style.opacity='1'; el.style.pointerEvents=''; } return; }
    if (el) el.remove();
    const badge = document.querySelector('.notification-badge');
    if (badge) { const cur = parseInt(badge.textContent)||0; if(cur<=1) badge.remove(); else badge.textContent=cur-1; }
    const list = document.querySelector('.notif-list');
    if (list && list.querySelectorAll('.notif-item').length === 0) list.innerHTML = '<div class="notif-empty">No notifications yet.</div>';
}

/* Filters */
document.getElementById('filterButtons').addEventListener('click', e => {
    const btn = e.target.closest('.filter-btn');
    if (!btn) return;
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const filter = btn.dataset.filter;
    const search = (document.getElementById('searchInput')?.value || '').toLowerCase().trim();
    document.querySelectorAll('.question-card').forEach(card => {
        const statusMatch = filter === 'all' || card.dataset.status === filter;
        const textMatch   = !search || (card.dataset.text || '').includes(search);
        card.classList.toggle('hidden', !statusMatch || !textMatch);
    });
});
document.getElementById('searchInput').addEventListener('input', function() {
    const search = this.value.toLowerCase().trim();
    const activeFilter = (document.querySelector('.filter-btn.active')?.dataset.filter) || 'all';
    document.querySelectorAll('.question-card').forEach(card => {
        const statusMatch = activeFilter === 'all' || card.dataset.status === activeFilter;
        const textMatch   = !search || (card.dataset.text || '').includes(search);
        card.classList.toggle('hidden', !statusMatch || !textMatch);
    });
});

/* Animate stats */
window.addEventListener('load', () => {
    document.querySelectorAll('.stat-item').forEach((el, i) => {
        setTimeout(() => el.style.animation = 'fadeInUp .5s ease forwards', i * 80);
    });
    document.querySelectorAll('.analysis-card').forEach((el, i) => {
        setTimeout(() => el.style.animation = 'fadeInUp .5s ease forwards', 300 + i * 100);
    });
});

/* Report modal */
function openReportModal(){ document.getElementById('reportModalOverlay').classList.add('open'); document.addEventListener('keydown', escReport); }
function closeReportModal(){ document.getElementById('reportModalOverlay').classList.remove('open'); document.removeEventListener('keydown', escReport); }
function escReport(e){ if(e.key==='Escape') closeReportModal(); }
function previewReportImg(input){
    const file = input.files[0]; if(!file) return;
    if(file.size > 5*1024*1024){ alert('Image must be under 5MB.'); input.value=''; return; }
    const reader = new FileReader();
    reader.onload = e => {
        const p = document.getElementById('reportImgPreview');
        p.src = e.target.result; p.style.display = 'block';
        document.querySelector('#reportDropZone .dz-text').textContent = file.name;
        document.querySelector('#reportDropZone .dz-sub').textContent = (file.size/1024).toFixed(1)+' KB';
    };
    reader.readAsDataURL(file);
}
const rdz = document.getElementById('reportDropZone');
if(rdz){
    rdz.addEventListener('dragover', e=>{ e.preventDefault(); rdz.classList.add('dragover'); });
    rdz.addEventListener('dragleave', ()=> rdz.classList.remove('dragover'));
    rdz.addEventListener('drop', e=>{
        e.preventDefault(); rdz.classList.remove('dragover');
        const file = e.dataTransfer.files[0];
        if(file && file.type.startsWith('image/')){
            const input = document.getElementById('reportImageInput');
            try{ const dt=new DataTransfer(); dt.items.add(file); input.files=dt.files; }catch(ex){}
            previewReportImg(input);
        }
    });
}
<?php if(!empty($_GET['report']) && $_GET['report']==='sent'): ?>
window.addEventListener('load', ()=> openReportModal());
<?php endif; ?>

document.addEventListener('keydown', e => {
    if (e.key === 'p' || e.key === 'P') { e.preventDefault(); window.print(); }
});
</script>
</body>
</html>

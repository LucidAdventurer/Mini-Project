<?php
/* ============================================================
 * SELF ASSESSMENT DASHBOARD
 * self-assessment.php
 * Access: Students only
 * ============================================================ */

require_once "config.php";
require_once "db-guard.php";

$user         = validateSession($conn, 'student');
$userId       = (int) $user['user_id'];
$userName     = $user['full_name'] ?? 'Student';
$userEmail    = $user['email']     ?? '';
$userInitials = strtoupper(substr($userName, 0, 2));

// Fetch profile image
$imgRes = safePreparedQuery($conn, "SELECT profile_image FROM users WHERE user_id = ?", "i", [$userId]);
$userProfileImage = '';
if ($imgRes['success'] && $imgRes['result']) {
    $imgRow = $imgRes['result']->fetch_assoc();
    $userProfileImage = $imgRow['profile_image'] ?? '';
    $imgRes['result']->free();
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── PDF Test stats ──
$pdfStats = safePreparedQuery($conn,
    "SELECT COUNT(*) AS total, COALESCE(AVG(percentage),0) AS avg_pct,
            COALESCE(MAX(percentage),0) AS best_pct
     FROM self_assessment_attempts
     WHERE user_id = ? AND type = 'pdf' AND status = 'submitted'",
    "i", [$userId]
);
$pdfTotal = 0; $pdfAvg = 0; $pdfBest = 0;
if ($pdfStats['success'] && $pdfStats['result']) {
    $r = $pdfStats['result']->fetch_assoc();
    $pdfTotal = (int)$r['total'];
    $pdfAvg   = round((float)$r['avg_pct']);
    $pdfBest  = round((float)$r['best_pct']);
    $pdfStats['result']->free();
}

// ── Level Test stats ──
$lvlStats = safePreparedQuery($conn,
    "SELECT COUNT(*) AS total, COALESCE(AVG(percentage),0) AS avg_pct,
            COALESCE(MAX(percentage),0) AS best_pct
     FROM self_assessment_attempts
     WHERE user_id = ? AND type = 'level' AND status = 'submitted'",
    "i", [$userId]
);
$lvlTotal = 0; $lvlAvg = 0; $lvlBest = 0;
if ($lvlStats['success'] && $lvlStats['result']) {
    $r = $lvlStats['result']->fetch_assoc();
    $lvlTotal = (int)$r['total'];
    $lvlAvg   = round((float)$r['avg_pct']);
    $lvlBest  = round((float)$r['best_pct']);
    $lvlStats['result']->free();
}

// ── Max difficulty reached ──
$maxDiffResult = safePreparedQuery($conn,
    "SELECT levels_used FROM self_assessment_attempts
     WHERE user_id = ? AND type = 'level' AND status = 'submitted' AND percentage >= 60
     ORDER BY FIELD(levels_used,'easy','medium','hard') DESC LIMIT 1",
    "i", [$userId]
);
$maxDiff = 'None yet';
if ($maxDiffResult['success'] && $maxDiffResult['result']) {
    $r = $maxDiffResult['result']->fetch_assoc();
    if ($r && $r['levels_used']) {
        $levels = explode(',', $r['levels_used']);
        $order  = ['hard' => 3, 'medium' => 2, 'easy' => 1];
        usort($levels, fn($a,$b) => ($order[$b] ?? 0) - ($order[$a] ?? 0));
        $maxDiff = ucfirst($levels[0]);
    }
    $maxDiffResult['result']->free();
}

// ── Recent PDF attempts ──
$pdfHistory = safePreparedQuery($conn,
    "SELECT sa.attempt_id, sa.percentage, sa.score, sa.total, sa.time_taken_sec,
            sa.submitted_at, s.title
     FROM self_assessment_attempts sa
     JOIN self_assessments s ON s.sa_id = sa.sa_id
     WHERE sa.user_id = ? AND sa.type = 'pdf' AND sa.status = 'submitted'
     ORDER BY sa.submitted_at DESC LIMIT 5",
    "i", [$userId]
);
$pdfHistoryRows = [];
if ($pdfHistory['success'] && $pdfHistory['result']) {
    while ($r = $pdfHistory['result']->fetch_assoc()) $pdfHistoryRows[] = $r;
    $pdfHistory['result']->free();
}

// ── Recent Level attempts ──
$lvlHistory = safePreparedQuery($conn,
    "SELECT sa.attempt_id, sa.percentage, sa.score, sa.total, sa.time_taken_sec,
            sa.submitted_at, sa.levels_used, s.title
     FROM self_assessment_attempts sa
     JOIN self_assessments s ON s.sa_id = sa.sa_id
     WHERE sa.user_id = ? AND sa.type = 'level' AND sa.status = 'submitted'
     ORDER BY sa.submitted_at DESC LIMIT 5",
    "i", [$userId]
);
$lvlHistoryRows = [];
if ($lvlHistory['success'] && $lvlHistory['result']) {
    while ($r = $lvlHistory['result']->fetch_assoc()) $lvlHistoryRows[] = $r;
    $lvlHistory['result']->free();
}

// ── Chart: last 6 PDF scores ──
$pdfChartRes = safePreparedQuery($conn,
    "SELECT sa.percentage, sa.submitted_at, s.title
     FROM self_assessment_attempts sa
     JOIN self_assessments s ON s.sa_id = sa.sa_id
     WHERE sa.user_id = ? AND sa.type = 'pdf' AND sa.status = 'submitted'
     ORDER BY sa.submitted_at DESC LIMIT 6",
    "i", [$userId]
);
$pdfChartLabels = []; $pdfChartData = [];
if ($pdfChartRes['success'] && $pdfChartRes['result']) {
    while ($r = $pdfChartRes['result']->fetch_assoc()) {
        $pdfChartLabels[] = mb_strimwidth($r['title'], 0, 12, '…');
        $pdfChartData[]   = round((float)$r['percentage']);
    }
    $pdfChartRes['result']->free();
}
$pdfChartLabels = array_reverse($pdfChartLabels);
$pdfChartData   = array_reverse($pdfChartData);

// ── Chart: last 6 Level scores ──
$lvlChartRes = safePreparedQuery($conn,
    "SELECT sa.percentage, sa.submitted_at, sa.levels_used
     FROM self_assessment_attempts sa
     WHERE sa.user_id = ? AND sa.type = 'level' AND sa.status = 'submitted'
     ORDER BY sa.submitted_at DESC LIMIT 6",
    "i", [$userId]
);
$lvlChartLabels = []; $lvlChartData = [];
if ($lvlChartRes['success'] && $lvlChartRes['result']) {
    while ($r = $lvlChartRes['result']->fetch_assoc()) {
        $lvlChartLabels[] = ucfirst($r['levels_used'] ?? 'Mixed');
        $lvlChartData[]   = round((float)$r['percentage']);
    }
    $lvlChartRes['result']->free();
}
$lvlChartLabels = array_reverse($lvlChartLabels);
$lvlChartData   = array_reverse($lvlChartData);

// ── Handle PDF test creation (popup form POST) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_pdf_test') {
    // CSRF check
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token');
    }
    $title    = trim($_POST['title'] ?? '');
    $duration = max(1, (int)($_POST['duration'] ?? 30));
    $pdfPath  = null;

    if (!empty($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['pdf_file'];
        if ($file['type'] === 'application/pdf' && $file['size'] <= 20 * 1024 * 1024) {
            $dir = 'uploads/self_assessment_pdfs/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $name    = 'sa_pdf_' . $userId . '_' . time() . '.pdf';
            $fullPath = $dir . $name;
            if (move_uploaded_file($file['tmp_name'], $fullPath)) {
                $pdfPath = $fullPath;
            }
        }
    }

    if ($title !== '') {
        $ins = safePreparedQuery($conn,
            "INSERT INTO self_assessments (user_id, type, title, duration_minutes, total_questions, pdf_path, status)
             VALUES (?, 'pdf', ?, ?, 0, ?, 'draft')",
            "isis", [$userId, $title, $duration, $pdfPath]
        );
        if ($ins['success']) {
            $newSaId = $conn->insert_id;
            header("Location: self-take-pdf-test.php?sa_id=$newSaId&setup=1");
            exit;
        }
    }
    header('Location: self-assessment.php?error=1');
    exit;
}

// ── Handle Level test creation ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_level_test') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) die('Invalid CSRF token');
    $levels   = $_POST['levels'] ?? [];           // e.g. ['easy','easy','medium'] — one per checked sublevel
    $numQEach = max(5, min(20, (int)($_POST['num_questions'] ?? 10))); // questions per level selected
    $duration = max(1, (int)($_POST['duration'] ?? 20));
    $allowed  = ['easy','medium','hard'];
    $levels   = array_filter($levels, fn($l) => in_array($l, $allowed));
    $levels   = array_values($levels);

    if (!empty($levels)) {
        // Count how many sublevels per difficulty were checked
        $diffCounts = array_count_values($levels);
        $uniqueDiffs = array_unique($levels);
        $levStr = implode(',', $uniqueDiffs);
        $placeholders = implode(',', array_fill(0, count($uniqueDiffs), '?'));
        $types  = str_repeat('s', count($uniqueDiffs));

        // Pull random questions — num per difficulty = count of checked sublevels × numQEach
        $qids = [];
        foreach ($diffCounts as $diff => $count) {
            $need = $count * $numQEach;
            $bankQ = safePreparedQuery($conn,
                "SELECT question_id FROM self_assessment_question_bank
                 WHERE difficulty = ?
                 ORDER BY RAND() LIMIT $need",
                "s", [$diff]
            );
            if ($bankQ['success'] && $bankQ['result']) {
                while ($r = $bankQ['result']->fetch_assoc()) $qids[] = (int)$r['question_id'];
                $bankQ['result']->free();
            }
        }

        if (!empty($qids)) {
            $totalQ  = count($qids);
            $title   = 'Level Test (' . strtoupper($levStr) . ')';
            $ins = safePreparedQuery($conn,
                "INSERT INTO self_assessments (user_id, type, title, duration_minutes, total_questions, levels_selected, status)
                 VALUES (?, 'level', ?, ?, ?, ?, 'ready')",
                "isiss", [$userId, $title, $duration, $totalQ, $levStr]
            );
            if ($ins['success']) {
                $newSaId = $conn->insert_id;
                foreach ($qids as $order => $qid) {
                    safePreparedQuery($conn,
                        "INSERT INTO self_assessment_q_map (sa_id, bank_qid, q_order) VALUES (?,?,?)",
                        "iii", [$newSaId, $qid, $order]
                    );
                }
                header("Location: self-take-level-test.php?sa_id=$newSaId");
                exit;
            }
        }
    }
    header('Location: self-assessment.php?error=1');
    exit;
}

// ── Unread notification count ──
$notifResult = safePreparedQuery($conn,
    "SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0",
    "i", [$userId]
);
$unreadCount = 0;
if ($notifResult['success'] && $notifResult['result']) {
    $notifRow    = $notifResult['result']->fetch_assoc();
    $unreadCount = (int)($notifRow['cnt'] ?? 0);
    $notifResult['result']->free();
}

// ── All notifications for dropdown ──
$notifDropResult = safePreparedQuery($conn,
    "SELECT notification_id, title, message, is_read, created_at, type, related_entity_id
     FROM notifications WHERE user_id = ?
     ORDER BY created_at DESC",
    "i", [$userId]
);
$notifItems = [];
if ($notifDropResult['success'] && $notifDropResult['result']) {
    while ($row = $notifDropResult['result']->fetch_assoc()) {
        $notifItems[] = $row;
    }
    $notifDropResult['result']->free();
}

function fmtTime(int $sec): string {
    if ($sec < 60) return $sec . 's';
    return floor($sec/60) . 'm ' . ($sec%60) . 's';
}
function timeAgoSA(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)    return 'Just now';
    if ($diff < 3600)  return floor($diff/60) . ' min ago';
    if ($diff < 86400) return floor($diff/3600) . ' hr ago';
    return date('d M Y', strtotime($dt));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Self Assessment — PTA Platform</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --primary:      #1a3a52;
            --primary-mid:  #234C6A;
            --accent:       #0ea5e9;
            --accent2:      #06b6d4;
            --success:      #10b981;
            --warning:      #f59e0b;
            --danger:       #ef4444;
            --bg:           #f0f4f8;
            --surface:      #ffffff;
            --surface2:     #f8fafc;
            --border:       #e2e8f0;
            --text:         #0f172a;
            --text-mid:     #475569;
            --text-soft:    #94a3b8;
            --radius:       16px;
            --radius-sm:    10px;
            --shadow:       0 1px 3px rgba(0,0,0,.06), 0 4px 16px rgba(0,0,0,.06);
            --shadow-md:    0 4px 24px rgba(0,0,0,.10);
            --nav-h:        68px;
            --sidebar-w:    230px;
            --transition:   .2s cubic-bezier(.4,0,.2,1);
        }
        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family:'Inter',sans-serif;
            background:var(--bg); color:var(--text);
            min-height:100vh; padding-top:var(--nav-h);
            -webkit-font-smoothing:antialiased;
        }

        /* ── NAVBAR ── */
        .navbar {
            background: var(--primary); padding: 0 28px; height: var(--nav-h);
            display: flex; align-items: center; justify-content: space-between;
            position: fixed; top: 0; left: 0; right: 0; z-index: 1000;
            box-shadow: 0 1px 0 rgba(255,255,255,.06), 0 4px 20px rgba(0,0,0,.18);
        }
        .navbar-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; flex-shrink: 0; }
        .nav-center { flex: 1; display: flex; align-items: center; gap: 10px; margin: 0 28px; max-width: 480px; }
        .nav-search { flex: 1; position: relative; }
        .nav-search input {
            width: 100%; padding: 10px 18px 10px 42px;
            border: 1.5px solid rgba(255,255,255,.15); border-radius: 10px;
            font-family: 'Inter', sans-serif; font-size: 14px;
            background: rgba(255,255,255,.1); color: white; outline: none;
            transition: var(--transition);
        }
        .nav-search input::placeholder { color: rgba(255,255,255,.5); }
        .nav-search input:focus { background: rgba(255,255,255,.18); border-color: rgba(255,255,255,.35); box-shadow: 0 0 0 3px rgba(14,165,233,.25); }
        .nav-search .sicon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: rgba(255,255,255,.5); font-size: 13px; pointer-events: none; }
        .nav-profile { display: flex; align-items: center; gap: 10px; }
        .notification-icon {
            position: relative; width: 38px; height: 38px;
            background: rgba(255,255,255,.12); border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; border: 1.5px solid rgba(255,255,255,.15);
            transition: var(--transition); color: white;
        }
        .notification-icon:hover { background: rgba(255,255,255,.2); border-color: rgba(255,255,255,.3); }
        .notif-dropdown-wrap { position: relative; overflow: visible; }
        .notif-dropdown {
            position: absolute; top: calc(100% + 12px); right: 0;
            background: var(--surface); border-radius: var(--radius);
            box-shadow: var(--shadow-md); border: 1px solid var(--border);
            width: 348px; opacity: 0; visibility: hidden;
            transform: translateY(-6px) scale(.98); transition: var(--transition); z-index: 1002;
        }
        .notif-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
        .notif-dropdown-header { padding: 16px 20px 14px; font-family: 'Sora', sans-serif; font-weight: 700; font-size: 14px; color: var(--text); border-bottom: 1px solid var(--border); }
        .notif-list { max-height: 360px; overflow-y: auto; scrollbar-width: thin; scrollbar-color: var(--border) transparent; }
        .notif-list::-webkit-scrollbar { width: 4px; }
        .notif-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 10px; }
        .notif-item { display: flex; gap: 12px; align-items: flex-start; padding: 13px 20px; border-bottom: 1px solid var(--border); cursor: pointer; transition: background var(--transition); }
        .notif-item:last-child { border-bottom: none; }
        .notif-item:hover { background: var(--surface2); }
        .notif-item.unread { background: #eff8ff; }
        .notif-item.unread:hover { background: #e0f2fe; }
        .notif-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--accent); flex-shrink: 0; margin-top: 5px; }
        .notif-dot.read { background: transparent; }
        .notif-item-body { flex: 1; }
        .notif-item-title { font-size: 13px; font-weight: 600; color: var(--text); margin-bottom: 2px; }
        .notif-item-msg { font-size: 12px; color: var(--text-mid); line-height: 1.45; }
        .notif-dismiss-btn { background: none; border: none; color: var(--text-soft); font-size: 13px; padding: 2px 5px; border-radius: 4px; cursor: pointer; flex-shrink: 0; opacity: 0; transition: opacity .15s; align-self: flex-start; margin-top: 2px; }
        .notif-item:hover .notif-dismiss-btn { opacity: 1; }
        .notif-dismiss-btn:hover { background: rgba(239,68,68,.1); color: #ef4444; }
        .notif-item-time { font-size: 11px; color: var(--text-soft); margin-top: 4px; }
        .notif-empty { padding: 32px 20px; text-align: center; color: var(--text-soft); font-size: 13px; }
        .notification-badge {
            position: absolute; top: -4px; right: -4px;
            background: var(--danger); color: white;
            min-width: 18px; height: 18px; border-radius: 9px; padding: 0 4px;
            font-size: 10px; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            animation: badgePulse 2s ease-in-out infinite; border: 2px solid var(--primary);
        }
        @keyframes badgePulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(239,68,68,.5); } 60% { box-shadow: 0 0 0 5px rgba(239,68,68,0); } }
        .profile-dropdown-container { position: relative; }
        .profile-button {
            display: flex; align-items: center; gap: 9px;
            padding: 6px 12px 6px 6px;
            background: rgba(255,255,255,.12); border: 1.5px solid rgba(255,255,255,.15);
            border-radius: 10px; cursor: pointer; transition: var(--transition);
        }
        .profile-button:hover { background: rgba(255,255,255,.2); border-color: rgba(255,255,255,.3); }
        .profile-avatar {
            width: 32px; height: 32px;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            border-radius: 8px; display: flex; align-items: center; justify-content: center;
            color: white; font-weight: 700; font-size: 13px; font-family: 'Sora', sans-serif;
            overflow: hidden;
        }
        .profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .profile-name { font-weight: 600; font-size: 13.5px; color: rgba(255,255,255,.95); }
        .dropdown-arrow { font-size: 10px; color: rgba(255,255,255,.6); }
        .profile-dropdown {
            position: absolute; top: calc(100% + 12px); right: 0;
            background: var(--surface); border-radius: var(--radius);
            box-shadow: var(--shadow-md); border: 1px solid var(--border);
            min-width: 240px; opacity: 0; visibility: hidden;
            transform: translateY(-6px) scale(.98); transition: var(--transition); z-index: 1001; overflow: hidden;
        }
        .profile-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
        .dropdown-header { padding: 18px 20px; background: linear-gradient(135deg, var(--primary), var(--primary-mid)); display: flex; gap: 12px; align-items: center; }
        .dropdown-avatar { width: 44px; height: 44px; background: linear-gradient(135deg, var(--accent), var(--accent2)); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-weight: 800; font-size: 18px; font-family: 'Sora', sans-serif; flex-shrink: 0; overflow: hidden; }
        .dropdown-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .dropdown-user-info { flex: 1; overflow: hidden; }
        .dropdown-user-name { font-family: 'Sora', sans-serif; font-weight: 700; font-size: 15px; color: white; margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .dropdown-user-email { font-size: 12px; color: rgba(255,255,255,.65); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .dropdown-menu { padding: 8px; }
        .dropdown-item { display: flex; align-items: center; gap: 11px; padding: 10px 12px; border-radius: 8px; color: var(--text-mid); text-decoration: none; cursor: pointer; border: none; background: none; width: 100%; text-align: left; font-size: 13.5px; font-family: 'Inter', sans-serif; transition: var(--transition); }
        .dropdown-item:hover { background: var(--surface2); color: var(--text); }
        .dropdown-item-icon { font-size: 16px; width: 20px; text-align: center; flex-shrink: 0; }
        .dropdown-divider { height: 1px; background: var(--border); margin: 6px 8px; }
        .dropdown-item.logout { color: var(--danger); }
        .dropdown-item.logout:hover { background: #fef2f2; }
        .dropdown-overlay { position: fixed; inset: 0; background: transparent; z-index: 999; display: none; }
        .dropdown-overlay.show { display: block; }

        /* ── LAYOUT ── */
        .page-wrapper { display:flex; min-height:calc(100vh - var(--nav-h)); }

        .left-sidebar {
            width:var(--sidebar-w); flex-shrink:0;
            padding:20px 12px; display:flex; flex-direction:column; gap:2px;
            background:var(--surface); border-right:1px solid var(--border);
            min-height:calc(100vh - var(--nav-h));
            position:sticky; top:var(--nav-h); align-self:flex-start;
        }
        .left-sidebar-label {
            font-size:10.5px; font-weight:700; text-transform:uppercase;
            letter-spacing:.1em; color:var(--text-soft); padding:14px 12px 7px;
        }
        .left-sidebar a, .left-sidebar button.sidebar-btn {
            display:flex; align-items:center; gap:10px;
            padding:10px 13px; border-radius:var(--radius-sm);
            text-decoration:none; font-size:13.5px; font-weight:500;
            color:var(--text-mid); transition:var(--transition);
            position:relative; cursor:pointer;
            border:none; background:none; width:100%; text-align:left;
            font-family:'Inter',sans-serif;
        }
        .left-sidebar a:hover, .left-sidebar button.sidebar-btn:hover { background:var(--surface2); color:var(--primary); }
        .left-sidebar a.active {
            background:linear-gradient(135deg, #e0f2fe, #e0f9ff);
            color:var(--accent); font-weight:600;
        }
        .left-sidebar a.active::before {
            content:''; position:absolute; left:0; top:20%; bottom:20%;
            width:3px; border-radius:0 3px 3px 0; background:var(--accent);
        }
        .left-sidebar a i, .left-sidebar button.sidebar-btn i { width:18px; text-align:center; font-size:14px; flex-shrink:0; }
        .left-sidebar-section {
            font-size:10.5px; font-weight:700; text-transform:uppercase;
            letter-spacing:.1em; color:var(--text-soft); padding:14px 12px 7px;
        }
        .left-sidebar-bottom { margin-top:auto; padding-top:12px; border-top:1px solid var(--border); }
        .left-sidebar-bottom a { color:var(--danger) !important; }
        .left-sidebar-bottom a:hover { background:#fef2f2 !important; }

        .page-content { flex:1; min-width:0; padding:28px; }
        @media (max-width:900px) { .left-sidebar { display:none; } .page-content { padding:16px; } }

        /* ── HERO BANNER ── */
        .hero {
            background:linear-gradient(135deg, var(--primary) 0%, #1e5276 60%, #1a6fa0 100%);
            border-radius:var(--radius); padding:28px 32px; margin-bottom:24px;
            display:flex; justify-content:space-between; align-items:center;
            position:relative; overflow:hidden;
            box-shadow:0 4px 24px rgba(26,58,82,.3);
        }
        .hero::before {
            content:''; position:absolute; top:-60px; right:-60px;
            width:220px; height:220px; border-radius:50%;
            background:rgba(255,255,255,.05); pointer-events:none;
        }
        .hero-title {
            font-family:'Sora',sans-serif; font-size:24px; font-weight:800;
            color:white; margin-bottom:6px;
        }
        .hero-sub { font-size:13.5px; color:rgba(255,255,255,.7); }
        .hero-stats { display:flex; gap:8px; }
        .hero-stat {
            text-align:center; background:rgba(255,255,255,.1);
            border:1px solid rgba(255,255,255,.15); border-radius:12px;
            padding:12px 18px; min-width:80px;
        }
        .hero-stat-num {
            font-family:'Sora',sans-serif; font-size:22px; font-weight:800;
            color:white; display:block;
        }
        .hero-stat-lbl { font-size:10.5px; color:rgba(255,255,255,.65); margin-top:3px; white-space:nowrap; }

        /* ── TAB SWITCHER ── */
        .tab-bar {
            display:flex; gap:8px; margin-bottom:24px;
            background:var(--surface); border-radius:12px; padding:6px;
            border:1px solid var(--border); width:fit-content;
            box-shadow:var(--shadow);
        }
        .tab-btn {
            padding:9px 24px; border-radius:9px; border:none;
            font-size:14px; font-weight:600; font-family:'Inter',sans-serif;
            cursor:pointer; transition:var(--transition); color:var(--text-mid);
            background:transparent;
        }
        .tab-btn.active {
            background:linear-gradient(135deg, var(--accent), var(--accent2));
            color:white; box-shadow:0 2px 10px rgba(14,165,233,.3);
        }

        /* ── GRID ── */
        .dash-grid {
            display:grid; grid-template-columns:1fr 320px; gap:24px;
        }
        @media(max-width:1100px) { .dash-grid { grid-template-columns:1fr; } }

        /* ── CARDS ── */
        .card {
            background:var(--surface); border-radius:var(--radius);
            border:1px solid var(--border); box-shadow:var(--shadow);
            padding:24px;
        }
        .card-title {
            font-family:'Sora',sans-serif; font-size:15px; font-weight:700;
            color:var(--text); margin-bottom:18px;
            display:flex; align-items:center; gap:8px;
        }
        .card-title span { font-size:18px; }

        /* stat row */
        .stat-row { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:24px; }
        .stat-box {
            background:var(--surface2); border:1px solid var(--border);
            border-radius:12px; padding:16px; text-align:center;
        }
        .stat-box-num {
            font-family:'Sora',sans-serif; font-size:26px; font-weight:800;
            color:var(--accent); display:block;
        }
        .stat-box-lbl { font-size:11.5px; color:var(--text-soft); margin-top:4px; }

        /* history table */
        .history-table { width:100%; border-collapse:collapse; }
        .history-table th {
            font-size:11px; font-weight:700; text-transform:uppercase;
            letter-spacing:.07em; color:var(--text-soft);
            padding:8px 12px; text-align:left;
            border-bottom:1px solid var(--border);
        }
        .history-table td {
            padding:11px 12px; font-size:13.5px;
            border-bottom:1px solid var(--border); color:var(--text-mid);
        }
        .history-table tr:last-child td { border-bottom:none; }
        .history-table tr:hover td { background:var(--surface2); }
        .score-pill {
            display:inline-flex; align-items:center; justify-content:center;
            padding:3px 10px; border-radius:20px;
            font-size:12px; font-weight:700;
        }
        .score-high  { background:#dcfce7; color:#166534; }
        .score-mid   { background:#fef3c7; color:#92400e; }
        .score-low   { background:#fee2e2; color:#991b1b; }

        .empty-state {
            text-align:center; padding:40px 20px;
            color:var(--text-soft); font-size:13.5px;
        }
        .empty-state .emoji { font-size:36px; display:block; margin-bottom:10px; }

        /* sidebar right */
        .right-col { display:flex; flex-direction:column; gap:20px; }

        /* diff badge */
        .diff-badge {
            display:inline-flex; align-items:center; gap:6px;
            padding:6px 14px; border-radius:20px;
            font-size:13px; font-weight:700;
        }
        .diff-none   { background:#f1f5f9; color:#64748b; }
        .diff-easy   { background:#dcfce7; color:#166534; }
        .diff-medium { background:#fef3c7; color:#92400e; }
        .diff-hard   { background:#fee2e2; color:#991b1b; }

        /* tab panels */
        .tab-panel { display:none; }
        .tab-panel.active { display:block; }

        /* ── MODALS ── */
        .modal-overlay {
            position:fixed; inset:0; background:rgba(0,0,0,.55);
            z-index:9100; display:flex; align-items:center; justify-content:center;
            backdrop-filter:blur(4px); opacity:0; visibility:hidden;
            transition:opacity .25s, visibility .25s;
        }
        .modal-overlay.open { opacity:1; visibility:visible; }
        .modal {
            background:#fff; border-radius:20px;
            width:100%; max-width:520px; margin:16px;
            box-shadow:0 24px 64px rgba(0,0,0,.22); overflow:hidden;
            transform:translateY(18px) scale(.97);
            transition:transform .28s cubic-bezier(.4,0,.2,1);
            max-height:90vh; overflow-y:auto;
        }
        .modal-overlay.open .modal { transform:translateY(0) scale(1); }
        .modal-header {
            background:linear-gradient(135deg, #1a3a52, #1e5276);
            padding:22px 24px 18px;
            display:flex; align-items:flex-start; justify-content:space-between;
            position:sticky; top:0; z-index:1;
        }
        .modal-title { font-family:'Sora',sans-serif; font-size:17px; font-weight:800; color:#fff; margin-bottom:3px; }
        .modal-sub   { font-size:12px; color:rgba(255,255,255,.6); }
        .modal-close {
            background:rgba(255,255,255,.15); border:none; border-radius:8px;
            color:#fff; width:30px; height:30px; font-size:16px;
            cursor:pointer; display:flex; align-items:center; justify-content:center;
        }
        .modal-body { padding:22px 24px; }
        .modal-footer { padding:0 24px 22px; display:flex; gap:10px; }

        .form-group { margin-bottom:16px; }
        .form-label { display:block; font-size:13px; font-weight:600; color:var(--text-mid); margin-bottom:7px; }
        .form-label .req { color:var(--danger); }
        .form-input, .form-select {
            width:100%; padding:10px 14px; border:1.5px solid var(--border);
            border-radius:10px; font-size:14px; font-family:'Inter',sans-serif;
            color:var(--text); background:#fff; transition:var(--transition);
        }
        .form-input:focus, .form-select:focus {
            outline:none; border-color:var(--accent);
            box-shadow:0 0 0 3px rgba(14,165,233,.15);
        }
        .form-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; }

        /* PDF drop zone */
        .pdf-drop-zone {
            border:2px dashed var(--border); border-radius:12px;
            padding:28px; text-align:center; cursor:pointer;
            transition:var(--transition); display:block;
        }
        .pdf-drop-zone:hover, .pdf-drop-zone.dragover {
            border-color:var(--accent); background:#f0f9ff;
        }
        .dz-icon { font-size:32px; margin-bottom:8px; }
        .dz-text { font-size:14px; font-weight:600; color:var(--text-mid); }
        .dz-sub  { font-size:12px; color:var(--text-soft); margin-top:4px; }

        /* Level selector groups */
        .level-group { margin-bottom:14px; }
        .level-group-header {
            display:flex; align-items:center; gap:10px;
            padding:10px 14px; border-radius:10px; cursor:pointer;
            border:2px solid; font-size:13.5px; font-weight:700;
            margin-bottom:8px; transition:var(--transition); user-select:none;
        }
        .level-group-header.easy   { border-color:#86efac; color:#166534; background:#f0fdf4; }
        .level-group-header.medium { border-color:#fcd34d; color:#92400e; background:#fffbeb; }
        .level-group-header.hard   { border-color:#fca5a5; color:#991b1b; background:#fff1f2; }
        .level-group-header.easy.has-checked   { background:#dcfce7; }
        .level-group-header.medium.has-checked { background:#fef3c7; }
        .level-group-header.hard.has-checked   { background:#fee2e2; }
        .level-group-toggle {
            margin-left:auto; font-size:12px; opacity:.6;
            transition:transform .2s;
        }
        .level-group-toggle.open { transform:rotate(180deg); }
        .level-sublevel-list {
            display:none; flex-wrap:wrap; gap:8px;
            padding:4px 4px 8px 4px;
        }
        .level-sublevel-list.open { display:flex; }
        .sublevel-label {
            display:flex; align-items:center; gap:7px;
            padding:8px 14px; border-radius:8px; cursor:pointer;
            border:1.5px solid var(--border); background:#fff;
            font-size:13px; font-weight:500; transition:var(--transition);
            color:var(--text-mid);
        }
        .sublevel-label input[type=checkbox] { display:none; }
        .sublevel-label .check-box {
            width:16px; height:16px; border-radius:4px;
            border:2px solid #cbd5e1; background:#fff;
            display:flex; align-items:center; justify-content:center;
            font-size:10px; transition:var(--transition); flex-shrink:0;
        }
        .sublevel-label:has(input:checked) { font-weight:700; }
        .sublevel-label:has(input:checked) .check-box { border-color:transparent; }
        .easy-sub:has(input:checked)   { border-color:#86efac; background:#dcfce7; color:#166534; }
        .easy-sub:has(input:checked) .check-box   { background:#22c55e; color:#fff; }
        .medium-sub:has(input:checked) { border-color:#fcd34d; background:#fef3c7; color:#92400e; }
        .medium-sub:has(input:checked) .check-box { background:#f59e0b; color:#fff; }
        .hard-sub:has(input:checked)   { border-color:#fca5a5; background:#fee2e2; color:#991b1b; }
        .hard-sub:has(input:checked) .check-box   { background:#ef4444; color:#fff; }

        /* Buttons */
        .btn-cancel {
            flex:1; padding:11px; border-radius:10px;
            border:1.5px solid var(--border); background:#fff;
            color:var(--text-mid); font-size:13.5px; font-weight:600;
            cursor:pointer; font-family:'Inter',sans-serif; transition:.15s;
        }
        .btn-cancel:hover { background:var(--surface2); }
        .btn-primary {
            flex:1; padding:11px; border-radius:10px; border:none;
            background:linear-gradient(135deg, var(--accent), var(--accent2));
            color:#fff; font-size:13.5px; font-weight:700;
            cursor:pointer; font-family:'Inter',sans-serif; transition:.15s;
        }
        .btn-primary:hover { opacity:.9; }

        @keyframes fadeIn  { from{opacity:0} to{opacity:1} }
        @keyframes slideUp { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }
        .page-content { animation:slideUp .3s ease; }
    </style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar">
    <a href="student-dashboard.php" class="navbar-brand">
        <img src="prepaura-logo.png" alt="Prepaura Logo" style="width:44px;height:44px;border-radius:10px;object-fit:contain;background:white;padding:3px;">
        <div style="display:flex;flex-direction:column;line-height:1.15;">
            <span style="font-family:'Sora',sans-serif;font-size:17px;font-weight:800;letter-spacing:.5px;color:white;">PREPAURA</span>
            <span style="font-size:10.5px;font-weight:400;color:rgba(255,255,255,.65);letter-spacing:.02em;">Placement Training Platform</span>
        </div>
    </a>
    <div class="nav-center">
        <div class="nav-search">
            <i class="fa fa-search sicon"></i>
            <input type="text" placeholder="Search self assessments..." autocomplete="off">
        </div>
    </div>
    <div class="nav-profile">
        <div class="notif-dropdown-wrap">
            <button class="notification-icon" onclick="toggleNotifDropdown()" id="notifBtn">
                <span>🔔</span>
                <?php if ($unreadCount > 0): ?>
                <div class="notification-badge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></div>
                <?php endif; ?>
            </button>
            <div class="notif-dropdown" id="notifDropdown">
                <div class="notif-dropdown-header">Notifications</div>
                <div class="notif-list">
                    <?php if (empty($notifItems)): ?>
                        <div class="notif-empty">No notifications yet.</div>
                    <?php else: foreach ($notifItems as $n):
                        $isUnread = !$n['is_read'];
                        $entityId  = (int)($n['related_entity_id'] ?? 0);
                        $nType     = $n['type'] ?? '';
                        $hasLink   = in_array($nType, ['assessment', 'material', 'result']) && $entityId > 0;
                        $redirectUrl = $hasLink ? 'api/notifications/notification-redirect.php?notification_id=' . $n['notification_id'] : '';
                    ?>
                    <div class="notif-item <?= $isUnread ? 'unread' : '' ?>" id="notif-<?= $n['notification_id'] ?>"
                         <?php if ($redirectUrl): ?>onclick="handleNotifClick(<?= $n['notification_id'] ?>, '<?= $redirectUrl ?>')" style="cursor:pointer;"<?php endif; ?>>
                        <div class="notif-dot <?= $isUnread ? '' : 'read' ?>"></div>
                        <div class="notif-item-body">
                            <div class="notif-item-title">🔔 <?= htmlspecialchars($n['title']) ?></div>
                            <?php if ($n['message']): ?>
                            <div class="notif-item-msg"><?= htmlspecialchars($n['message']) ?></div>
                            <?php endif; ?>
                            <div class="notif-item-time"><?= timeAgoSA($n['created_at']) ?></div>
                        </div>
                        <button class="notif-dismiss-btn" title="Dismiss"
                            onclick="event.stopPropagation(); dismissNotification(<?= $n['notification_id'] ?>)"
                            aria-label="Dismiss notification">✕</button>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
        <div class="profile-dropdown-container">
            <button class="profile-button" onclick="toggleProfileDropdown()">
                <div class="profile-avatar">
                    <?php if ($userProfileImage && file_exists($userProfileImage)): ?>
                        <img src="<?= htmlspecialchars($userProfileImage) ?>?v=<?= time() ?>" alt="Avatar">
                    <?php else: ?>
                        <?= htmlspecialchars($userInitials) ?>
                    <?php endif; ?>
                </div>
                <span class="profile-name"><?= htmlspecialchars($userName) ?></span>
                <span class="dropdown-arrow">▼</span>
            </button>
            <div class="profile-dropdown" id="profileDropdown">
                <div class="dropdown-header">
                    <div class="dropdown-avatar">
                        <?php if ($userProfileImage && file_exists($userProfileImage)): ?>
                            <img src="<?= htmlspecialchars($userProfileImage) ?>?v=<?= time() ?>" alt="Avatar">
                        <?php else: ?>
                            <?= htmlspecialchars($userInitials) ?>
                        <?php endif; ?>
                    </div>
                    <div class="dropdown-user-info">
                        <div class="dropdown-user-name"><?= htmlspecialchars($userName) ?></div>
                        <div class="dropdown-user-email"><?= htmlspecialchars($userEmail) ?></div>
                    </div>
                </div>
                <div class="dropdown-menu">
                    <a href="student-profile.php" class="dropdown-item">
                        <span class="dropdown-item-icon">👤</span><span>My Profile</span>
                    </a>
                    <div class="dropdown-divider"></div>
                    <button class="dropdown-item logout" onclick="handleLogout()">
                        <span class="dropdown-item-icon">🚪</span><span>Logout</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</nav>
<div class="dropdown-overlay" id="dropdownOverlay" onclick="closeAllDropdowns()"></div>

<!-- LAYOUT -->
<div class="page-wrapper">

    <!-- LEFT SIDEBAR -->
    <aside class="left-sidebar">
        <span class="left-sidebar-label">Navigation</span>
        <a href="student-dashboard.php"><i class="fa fa-home"></i> Dashboard</a>
        <a href="student-assessments.php"><i class="fa fa-clipboard-list"></i> Assessments</a>
        <a href="self-assessment.php" class="active"><i class="fa fa-user-check"></i> Self Assessment</a>
        <a href="student-resources.php"><i class="fa fa-folder-open"></i> Resources</a>

        <span class="left-sidebar-section">Self Assessment</span>
        <button class="sidebar-btn" onclick="openPdfModal()"><i class="fa fa-file-pdf" style="color:#ef4444"></i> PDF Test</button>
        <button class="sidebar-btn" onclick="openLevelModal()"><i class="fa fa-layer-group" style="color:#8b5cf6"></i> Level Test</button>

        <div class="left-sidebar-bottom">
            <a href="logout.php"><i class="fa fa-sign-out-alt"></i> Logout</a>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="page-content">

        <?php if (!empty($_GET['error'])): ?>
        <div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:10px;padding:12px 16px;margin-bottom:20px;font-size:13.5px;color:#991b1b;">
            ⚠️ Something went wrong. Please try again.
        </div>
        <?php endif; ?>

        <!-- HERO -->
        <div class="hero">
            <div>
                <div class="hero-title">🧠 Self Assessment</div>
                <div class="hero-sub">Practice at your own pace — PDF tests & level-based quizzes</div>
            </div>
            <div class="hero-stats">
                <div class="hero-stat">
                    <span class="hero-stat-num"><?= $pdfTotal + $lvlTotal ?></span>
                    <div class="hero-stat-lbl">Total Tests</div>
                </div>
                <div class="hero-stat">
                    <span class="hero-stat-num"><?= $pdfTotal > 0 || $lvlTotal > 0 ? max($pdfAvg, $lvlAvg) : 0 ?>%</span>
                    <div class="hero-stat-lbl">Best Avg</div>
                </div>
                <div class="hero-stat">
                    <span class="hero-stat-num"><?= $maxDiff ?></span>
                    <div class="hero-stat-lbl">Max Level</div>
                </div>
            </div>
        </div>

        <!-- TAB BAR -->
        <div class="tab-bar">
            <button class="tab-btn <?= ($_GET['tab'] ?? 'pdf') === 'pdf' ? 'active' : '' ?>"
                onclick="switchTab('pdf')">📄 PDF Tests</button>
            <button class="tab-btn <?= ($_GET['tab'] ?? '') === 'level' ? 'active' : '' ?>"
                onclick="switchTab('level')">🎯 Level Tests</button>
        </div>

        <!-- PDF TAB -->
        <div class="tab-panel <?= ($_GET['tab'] ?? 'pdf') === 'pdf' ? 'active' : '' ?>" id="tab-pdf">
            <div class="dash-grid">
                <div>
                    <!-- PDF Stats -->
                    <div class="stat-row">
                        <div class="stat-box">
                            <span class="stat-box-num"><?= $pdfTotal ?></span>
                            <div class="stat-box-lbl">Tests Taken</div>
                        </div>
                        <div class="stat-box">
                            <span class="stat-box-num"><?= $pdfAvg ?>%</span>
                            <div class="stat-box-lbl">Avg Score</div>
                        </div>
                        <div class="stat-box">
                            <span class="stat-box-num"><?= $pdfBest ?>%</span>
                            <div class="stat-box-lbl">Best Score</div>
                        </div>
                    </div>

                    <!-- PDF History -->
                    <div class="card">
                        <div class="card-title"><span>📝</span> PDF Test History</div>
                        <?php if (empty($pdfHistoryRows)): ?>
                        <div class="empty-state">
                            <span class="emoji">📄</span>
                            No PDF tests taken yet.<br>Click <strong>PDF Test</strong> in the sidebar to start!
                        </div>
                        <?php else: ?>
                        <table class="history-table">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Score</th>
                                    <th>Time</th>
                                    <th>When</th>
                                    <th>Result</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pdfHistoryRows as $row):
                                    $pct = round((float)$row['percentage']);
                                    $cls = $pct >= 75 ? 'score-high' : ($pct >= 50 ? 'score-mid' : 'score-low');
                                ?>
                                <tr>
                                    <td style="font-weight:600;color:var(--text);"><?= htmlspecialchars($row['title']) ?></td>
                                    <td><?= $row['score'] ?>/<?= $row['total'] ?></td>
                                    <td><?= fmtTime((int)$row['time_taken_sec']) ?></td>
                                    <td><?= timeAgoSA($row['submitted_at']) ?></td>
                                    <td>
                                        <span class="score-pill <?= $cls ?>"><?= $pct ?>%</span>
                                        <a href="self-result-pdf.php?attempt=<?= $row['attempt_id'] ?>"
                                           style="font-size:12px;color:var(--accent);margin-left:6px;text-decoration:none;">View →</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right col: PDF chart -->
                <div class="right-col">
                    <div class="card">
                        <div class="card-title"><span>📈</span> PDF Score Trend</div>
                        <?php if (empty($pdfChartData)): ?>
                        <div class="empty-state" style="padding:20px;">No data yet</div>
                        <?php else: ?>
                        <canvas id="pdfChart" height="200"></canvas>
                        <?php endif; ?>
                    </div>
                    <div class="card">
                        <div class="card-title"><span>🚀</span> Quick Start</div>
                        <p style="font-size:13.5px;color:var(--text-mid);line-height:1.6;margin-bottom:16px;">
                            Upload a PDF study material, add your own questions, and test yourself!
                        </p>
                        <button onclick="openPdfModal()" class="btn-primary" style="width:100%;padding:12px;">
                            + Create PDF Test
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- LEVEL TAB -->
        <div class="tab-panel <?= ($_GET['tab'] ?? '') === 'level' ? 'active' : '' ?>" id="tab-level">
            <div class="dash-grid">
                <div>
                    <!-- Level Stats -->
                    <div class="stat-row">
                        <div class="stat-box">
                            <span class="stat-box-num"><?= $lvlTotal ?></span>
                            <div class="stat-box-lbl">Tests Taken</div>
                        </div>
                        <div class="stat-box">
                            <span class="stat-box-num"><?= $lvlAvg ?>%</span>
                            <div class="stat-box-lbl">Avg Score</div>
                        </div>
                        <div class="stat-box">
                            <span class="stat-box-num"><?= $lvlBest ?>%</span>
                            <div class="stat-box-lbl">Best Score</div>
                        </div>
                    </div>

                    <!-- Level History -->
                    <div class="card">
                        <div class="card-title"><span>🏆</span> Level Test History</div>
                        <?php if (empty($lvlHistoryRows)): ?>
                        <div class="empty-state">
                            <span class="emoji">🎯</span>
                            No level tests taken yet.<br>Click <strong>Level Test</strong> in the sidebar!
                        </div>
                        <?php else: ?>
                        <table class="history-table">
                            <thead>
                                <tr>
                                    <th>Level(s)</th>
                                    <th>Score</th>
                                    <th>Time</th>
                                    <th>When</th>
                                    <th>Result</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($lvlHistoryRows as $row):
                                    $pct = round((float)$row['percentage']);
                                    $cls = $pct >= 75 ? 'score-high' : ($pct >= 50 ? 'score-mid' : 'score-low');
                                    $levels = array_map('ucfirst', explode(',', $row['levels_used'] ?? 'mixed'));
                                ?>
                                <tr>
                                    <td>
                                        <?php foreach($levels as $lv): ?>
                                        <span class="diff-badge diff-<?= strtolower($lv) ?>" style="font-size:11px;padding:3px 8px;">
                                            <?= htmlspecialchars($lv) ?>
                                        </span>
                                        <?php endforeach; ?>
                                    </td>
                                    <td><?= $row['score'] ?>/<?= $row['total'] ?></td>
                                    <td><?= fmtTime((int)$row['time_taken_sec']) ?></td>
                                    <td><?= timeAgoSA($row['submitted_at']) ?></td>
                                    <td>
                                        <span class="score-pill <?= $cls ?>"><?= $pct ?>%</span>
                                        <a href="self-result-level.php?attempt=<?= $row['attempt_id'] ?>"
                                           style="font-size:12px;color:var(--accent);margin-left:6px;text-decoration:none;">View →</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right col: Level chart + diff progress -->
                <div class="right-col">
                    <div class="card">
                        <div class="card-title"><span>📊</span> Level Score Trend</div>
                        <?php if (empty($lvlChartData)): ?>
                        <div class="empty-state" style="padding:20px;">No data yet</div>
                        <?php else: ?>
                        <canvas id="lvlChart" height="200"></canvas>
                        <?php endif; ?>
                    </div>
                    <div class="card">
                        <div class="card-title"><span>🎯</span> Max Level Reached</div>
                        <div style="text-align:center;padding:12px 0;">
                            <?php
                            $diffClass = 'diff-none';
                            if ($maxDiff === 'Hard')   $diffClass = 'diff-hard';
                            elseif ($maxDiff === 'Medium') $diffClass = 'diff-medium';
                            elseif ($maxDiff === 'Easy')   $diffClass = 'diff-easy';
                            ?>
                            <span class="diff-badge <?= $diffClass ?>" style="font-size:16px;padding:10px 24px;">
                                <?= $maxDiff === 'None yet' ? '🔒 None yet' : '🏅 ' . $maxDiff ?>
                            </span>
                            <p style="font-size:12px;color:var(--text-soft);margin-top:10px;">
                                Score ≥ 60% to unlock next level
                            </p>
                        </div>
                        <button onclick="openLevelModal()" class="btn-primary" style="width:100%;padding:12px;margin-top:8px;">
                            + Start Level Test
                        </button>
                    </div>
                </div>
            </div>
        </div>

    </main>
</div>

<!-- ══ PDF TEST MODAL ══ -->
<div class="modal-overlay" id="pdfModalOverlay" onclick="if(event.target===this)closePdfModal()">
    <div class="modal">
        <div class="modal-header">
            <div>
                <div class="modal-title">📄 Create PDF Test</div>
                <div class="modal-sub">Upload your PDF & add questions manually</div>
            </div>
            <button class="modal-close" onclick="closePdfModal()">✕</button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="pdfTestForm">
            <input type="hidden" name="action" value="create_pdf_test">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Test Title <span class="req">*</span></label>
                    <input type="text" name="title" class="form-input"
                        placeholder="e.g. Chapter 5 - Thermodynamics" required maxlength="200">
                </div>
                <div class="form-group">
                    <label class="form-label">Upload PDF <span class="req">*</span></label>
                    <label for="pdfFileInput" class="pdf-drop-zone" id="pdfDropZone">
                        <div class="dz-icon">📎</div>
                        <div class="dz-text">Click to upload or drag & drop</div>
                        <div class="dz-sub">PDF only — max 20 MB</div>
                    </label>
                    <input type="file" name="pdf_file" id="pdfFileInput"
                        accept="application/pdf" style="display:none"
                        onchange="onPdfSelect(this)" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Duration (minutes) <span class="req">*</span></label>
                    <input type="number" name="duration" class="form-input"
                        value="30" min="5" max="180" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closePdfModal()">Cancel</button>
                <button type="submit" class="btn-primary">📄 Next: Add Questions →</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ LEVEL TEST MODAL ══ -->
<div class="modal-overlay" id="levelModalOverlay" onclick="if(event.target===this)closeLevelModal()">
    <div class="modal" style="max-width:520px;">
        <div class="modal-header">
            <div>
                <div class="modal-title">🎯 Start Level Test</div>
                <div class="modal-sub">Pick your levels & timer — we'll do the rest</div>
            </div>
            <button class="modal-close" onclick="closeLevelModal()">✕</button>
        </div>
        <form method="POST" id="levelTestForm">
            <input type="hidden" name="action" value="create_level_test">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <div class="modal-body">

                <div class="form-group">
                    <label class="form-label">Select Level(s) <span class="req">*</span></label>

                    <!-- Easy: Levels 1–5 -->
                    <div class="level-group">
                        <div class="level-group-header easy" onclick="toggleLevelGroup('easy-list', this)">
                            <span>🟢</span> Easy <span style="font-size:11px;font-weight:500;opacity:.7;">(Levels 1–5)</span>
                            <span class="level-group-toggle">▼</span>
                        </div>
                        <div class="level-sublevel-list" id="easy-list">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                            <label class="sublevel-label easy-sub">
                                <input type="checkbox" name="levels[]" value="easy" data-level="<?= $i ?>">
                                <span class="check-box">✓</span>
                                Level <?= $i ?>
                            </label>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <!-- Medium: Levels 6–10 -->
                    <div class="level-group">
                        <div class="level-group-header medium" onclick="toggleLevelGroup('medium-list', this)">
                            <span>🟡</span> Medium <span style="font-size:11px;font-weight:500;opacity:.7;">(Levels 6–10)</span>
                            <span class="level-group-toggle">▼</span>
                        </div>
                        <div class="level-sublevel-list" id="medium-list">
                            <?php for ($i = 6; $i <= 10; $i++): ?>
                            <label class="sublevel-label medium-sub">
                                <input type="checkbox" name="levels[]" value="medium" data-level="<?= $i ?>">
                                <span class="check-box">✓</span>
                                Level <?= $i ?>
                            </label>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <!-- Hard: Levels 11–15 -->
                    <div class="level-group">
                        <div class="level-group-header hard" onclick="toggleLevelGroup('hard-list', this)">
                            <span>🔴</span> Hard <span style="font-size:11px;font-weight:500;opacity:.7;">(Levels 11–15)</span>
                            <span class="level-group-toggle">▼</span>
                        </div>
                        <div class="level-sublevel-list" id="hard-list">
                            <?php for ($i = 11; $i <= 15; $i++): ?>
                            <label class="sublevel-label hard-sub">
                                <input type="checkbox" name="levels[]" value="hard" data-level="<?= $i ?>">
                                <span class="check-box">✓</span>
                                Level <?= $i ?>
                            </label>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label">Questions per Level <span class="req">*</span></label>
                        <input type="number" name="num_questions" class="form-input"
                            value="10" min="1" max="50" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Duration (minutes) <span class="req">*</span></label>
                        <input type="number" name="duration" class="form-input"
                            value="20" min="5" max="120" required>
                    </div>
                </div>

                <div id="levelSelectionInfo" style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:12px 14px;font-size:13px;color:#0369a1;display:none;">
                    ℹ️ <span id="levelSelectionText"></span>
                </div>
                <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:12px 14px;font-size:13px;color:#0369a1;">
                    ℹ️ Click a difficulty to expand, then tick the specific levels you want.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeLevelModal()">Cancel</button>
                <button type="submit" class="btn-primary" id="startLevelBtn">🎯 Start Test →</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Tab switch ──
function switchTab(tab) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
    event.currentTarget.classList.add('active');
    history.replaceState(null,'','?tab='+tab);
}

// ── Modals ──
function openPdfModal()   { document.getElementById('pdfModalOverlay').classList.add('open'); }
function closePdfModal()  { document.getElementById('pdfModalOverlay').classList.remove('open'); }
function openLevelModal() { document.getElementById('levelModalOverlay').classList.add('open'); }
function closeLevelModal(){ document.getElementById('levelModalOverlay').classList.remove('open'); }

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closePdfModal(); closeLevelModal(); }
});

// ── PDF file select ──
function onPdfSelect(input) {
    const file = input.files[0];
    if (!file) return;
    if (file.size > 20 * 1024 * 1024) {
        alert('PDF must be under 20MB.');
        input.value = '';
        return;
    }
    document.querySelector('#pdfDropZone .dz-text').textContent = file.name;
    document.querySelector('#pdfDropZone .dz-sub').textContent = (file.size/1024).toFixed(1) + ' KB';
}

// Drag & drop PDF
const pdz = document.getElementById('pdfDropZone');
if (pdz) {
    pdz.addEventListener('dragover',  e => { e.preventDefault(); pdz.classList.add('dragover'); });
    pdz.addEventListener('dragleave', () => pdz.classList.remove('dragover'));
    pdz.addEventListener('drop', e => {
        e.preventDefault(); pdz.classList.remove('dragover');
        const file = e.dataTransfer.files[0];
        if (file && file.type === 'application/pdf') {
            const input = document.getElementById('pdfFileInput');
            try { const dt = new DataTransfer(); dt.items.add(file); input.files = dt.files; } catch(e){}
            onPdfSelect(input);
        }
    });
}

// ── Level group toggle ──
function toggleLevelGroup(listId, header) {
    const list   = document.getElementById(listId);
    const arrow  = header.querySelector('.level-group-toggle');
    const isOpen = list.classList.contains('open');
    list.classList.toggle('open', !isOpen);
    arrow.classList.toggle('open', !isOpen);
}

// Update group header highlight when sublevel checkboxes change
document.querySelectorAll('.sublevel-label input[type=checkbox]').forEach(cb => {
    cb.addEventListener('change', () => {
        updateGroupHeaders();
        updateLevelInfo();
    });
});

function updateGroupHeaders() {
    [['easy-list','easy'],['medium-list','medium'],['hard-list','hard']].forEach(([listId, cls]) => {
        const list    = document.getElementById(listId);
        const header  = list.previousElementSibling;
        const anyChecked = list.querySelectorAll('input:checked').length > 0;
        header.classList.toggle('has-checked', anyChecked);
    });
}

function updateLevelInfo() {
    const checked = document.querySelectorAll('.sublevel-label input:checked');
    const infoBox  = document.getElementById('levelSelectionInfo');
    const infoText = document.getElementById('levelSelectionText');
    if (checked.length === 0) { infoBox.style.display = 'none'; return; }
    const numQ = document.querySelector('input[name="num_questions"]').value;
    const labels = [...checked].map(cb => 'Level ' + cb.dataset.level).join(', ');
    const total  = checked.length * parseInt(numQ);
    infoText.textContent = labels + ' selected — ' + total + ' questions total (' + numQ + ' per level).';
    infoBox.style.display = 'block';
}
document.querySelector('input[name="num_questions"]').addEventListener('input', updateLevelInfo);

// ── Level form validation ──
document.getElementById('levelTestForm').addEventListener('submit', function(e) {
    const checked = document.querySelectorAll('.sublevel-label input[type="checkbox"]:checked');
    if (checked.length === 0) {
        e.preventDefault();
        alert('Please select at least one level.');
    }
});

// ── Dropdowns ──
const CSRF_TOKEN = '<?= htmlspecialchars($_SESSION['csrf_token']) ?>';

function handleLogout() {
    if (confirm('Are you sure you want to logout?')) window.location.href = 'logout.php';
}
function toggleProfileDropdown() {
    document.getElementById('profileDropdown').classList.toggle('show');
    document.getElementById('dropdownOverlay').classList.toggle('show');
    document.getElementById('notifDropdown').classList.remove('show');
}
function toggleNotifDropdown() {
    const dd = document.getElementById('notifDropdown');
    const overlay = document.getElementById('dropdownOverlay');
    const isOpen = dd.classList.contains('show');
    document.getElementById('profileDropdown').classList.remove('show');
    dd.classList.toggle('show', !isOpen);
    overlay.classList.toggle('show', !isOpen);
    if (!isOpen) {
        fetch('api/notifications/mark-read.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': CSRF_TOKEN, 'Content-Type': 'application/json' }
        }).then(() => {
            const badge = document.querySelector('.notification-badge');
            if (badge) badge.remove();
            document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
            document.querySelectorAll('.notif-dot:not(.read)').forEach(el => el.classList.add('read'));
        }).catch(() => {});
    }
}
function closeAllDropdowns() {
    document.getElementById('profileDropdown').classList.remove('show');
    document.getElementById('notifDropdown').classList.remove('show');
    document.getElementById('dropdownOverlay').classList.remove('show');
}
async function dismissNotification(notifId) {
    const el = document.getElementById('notif-' + notifId);
    if (el) { el.style.opacity = '0'; el.style.transition = 'opacity .2s'; setTimeout(() => el.remove(), 200); }
    try {
        await fetch('api/notifications/dismiss.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify({ notification_id: notifId })
        });
    } catch(e) {}
}
function handleNotifClick(notifId, redirectUrl) {
    window.location.href = redirectUrl;
}

// ── Charts ──
<?php if (!empty($pdfChartData)): ?>
new Chart(document.getElementById('pdfChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($pdfChartLabels) ?>,
        datasets: [{
            label: 'Score %',
            data: <?= json_encode($pdfChartData) ?>,
            borderColor: '#0ea5e9',
            backgroundColor: 'rgba(14,165,233,.1)',
            tension: 0.4, fill: true,
            pointBackgroundColor: '#0ea5e9', pointRadius: 5,
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { min: 0, max: 100, ticks: { callback: v => v + '%' }, grid: { color: '#f1f5f9' } },
            x: { grid: { display: false } }
        }
    }
});
<?php endif; ?>

<?php if (!empty($lvlChartData)): ?>
new Chart(document.getElementById('lvlChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($lvlChartLabels) ?>,
        datasets: [{
            label: 'Score %',
            data: <?= json_encode($lvlChartData) ?>,
            backgroundColor: <?= json_encode(array_map(function($l){
                if(str_contains($l,'Hard'))   return 'rgba(239,68,68,.7)';
                if(str_contains($l,'Medium')) return 'rgba(245,158,11,.7)';
                return 'rgba(16,185,129,.7)';
            }, $lvlChartLabels)) ?>,
            borderRadius: 6,
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { min: 0, max: 100, ticks: { callback: v => v + '%' }, grid: { color: '#f1f5f9' } },
            x: { grid: { display: false } }
        }
    }
});
<?php endif; ?>
</script>
</body>
</html>

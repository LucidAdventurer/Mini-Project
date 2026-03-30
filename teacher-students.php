<?php
/* ========================================
 * TEACHER — STUDENTS & BATCHES
 * File: teacher-students.php
 * ======================================== */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db-guard.php';

$currentUser  = validateSession($conn, 'teacher');
$teacherId    = (int) $currentUser['user_id'];
$userName     = htmlspecialchars($currentUser['full_name'] ?? 'Teacher');
$userEmail    = htmlspecialchars($currentUser['email'] ?? '');
$userInitials = strtoupper(substr($currentUser['full_name'] ?? 'T', 0, 2));

// ── Profile picture ──
$picStmt = $conn->prepare("SELECT profile_image FROM users WHERE user_id = ?");
$picStmt->bind_param("i", $teacherId);
$picStmt->execute();
$picRow      = $picStmt->get_result()->fetch_assoc();
$userPicture = $picRow['profile_image'] ?? '';

// ── Active tab ──
$activeTab = isset($_GET['tab']) && $_GET['tab'] === 'batches' ? 'batches' : 'students';

// ── Groups this teacher owns ──
$groups    = [];
$grpResult = safePreparedQuery($conn,
    "SELECT group_id, name, description, created_at FROM groups WHERE teacher_id = ? ORDER BY name",
    "i", [$teacherId]
);
if ($grpResult['success'] && $grpResult['result']) {
    while ($row = $grpResult['result']->fetch_assoc()) {
        $groups[] = $row;
    }
    $grpResult['result']->free();
}

// ── Selected group filter (students tab) ──
$selectedGroup = isset($_GET['group']) && $_GET['group'] !== '' ? (int)$_GET['group'] : null;

// ── Selected batch (batches tab) ──
$selectedBatch = isset($_GET['batch']) && $_GET['batch'] !== '' ? (int)$_GET['batch'] : null;

// ── Students query ──
$students = [];

if ($selectedGroup !== null) {
    $ownCheck = safePreparedQuery($conn,
        "SELECT group_id FROM groups WHERE group_id = ? AND teacher_id = ?",
        "ii", [$selectedGroup, $teacherId]
    );
    $validGroup = $ownCheck['success'] && $ownCheck['result'] && $ownCheck['result']->num_rows > 0;
    if ($ownCheck['result']) $ownCheck['result']->free();

    if ($validGroup) {
        $r = safePreparedQuery($conn,
            "SELECT
                u.user_id,
                u.full_name,
                u.email,
                u.department,
                u.registration_number,
                u.profile_image,
                u.is_active,
                gm.joined_at,
                COUNT(DISTINCT aa.attempt_id)   AS total_attempts,
                ROUND(AVG(aa.percentage), 1)    AS avg_score,
                MAX(aa.percentage)              AS max_score,
                MAX(aa.submitted_at)            AS last_activity
            FROM group_members gm
            JOIN users u ON u.user_id = gm.student_id
            LEFT JOIN assessment_attempts aa
                ON aa.user_id = u.user_id
                AND aa.status IN ('submitted','timeout')
                AND aa.assessment_id IN (
                    SELECT assessment_id FROM assessments WHERE created_by = ?
                )
            WHERE gm.group_id = ?
            GROUP BY u.user_id, u.full_name, u.email, u.department, u.registration_number, u.profile_image, u.is_active, gm.joined_at
            ORDER BY u.full_name",
            "ii", [$teacherId, $selectedGroup]
        );
    } else {
        $r = ['success' => false];
    }
} else {
    $r = safePreparedQuery($conn,
        "SELECT
            u.user_id,
            u.full_name,
            u.email,
            u.department,
            u.registration_number,
            u.profile_image,
            u.is_active,
            MIN(gm.joined_at)                   AS joined_at,
            COUNT(DISTINCT aa.attempt_id)       AS total_attempts,
            ROUND(AVG(aa.percentage), 1)        AS avg_score,
            MAX(aa.percentage)                  AS max_score,
            MAX(aa.submitted_at)                AS last_activity
        FROM users u
        LEFT JOIN group_members gm ON gm.student_id = u.user_id
            AND gm.group_id IN (SELECT group_id FROM groups WHERE teacher_id = ?)
        LEFT JOIN assessment_attempts aa
            ON aa.user_id = u.user_id
            AND aa.status IN ('submitted','timeout')
            AND aa.assessment_id IN (
                SELECT assessment_id FROM assessments WHERE created_by = ?
            )
        WHERE u.role = 'student'
          AND (
              gm.group_id IS NOT NULL
              OR aa.attempt_id IS NOT NULL
          )
        GROUP BY u.user_id, u.full_name, u.email, u.department, u.registration_number, u.profile_image, u.is_active
        ORDER BY u.full_name",
        "ii", [$teacherId, $teacherId]
    );
}

if ($r['success'] && $r['result']) {
    while ($row = $r['result']->fetch_assoc()) {
        $students[] = $row;
    }
    $r['result']->free();
}

// ── Batch stats (for batches tab overview) ──
$batchStats = [];
foreach ($groups as $g) {
    $gid = (int)$g['group_id'];
    $bs = safePreparedQuery($conn,
        "SELECT
            COUNT(DISTINCT gm.student_id)           AS member_count,
            COUNT(DISTINCT aa.attempt_id)           AS total_attempts,
            ROUND(AVG(aa.percentage), 1)            AS avg_score,
            MAX(aa.percentage)                      AS top_score
        FROM group_members gm
        LEFT JOIN assessment_attempts aa
            ON aa.user_id = gm.student_id
            AND aa.status IN ('submitted','timeout')
            AND aa.assessment_id IN (
                SELECT assessment_id FROM assessments WHERE created_by = ?
            )
        WHERE gm.group_id = ?",
        "ii", [$teacherId, $gid]
    );
    $row = ['member_count'=>0,'total_attempts'=>0,'avg_score'=>null,'top_score'=>null];
    if ($bs['success'] && $bs['result']) {
        $row = array_merge($row, $bs['result']->fetch_assoc() ?? []);
        $bs['result']->free();
    }
    $batchStats[$gid] = $row;
}

// ── Batch detail: members with scores ──
$batchMembers    = [];
$batchDetail     = null;
$batchAssessments = [];

if ($selectedBatch !== null) {
    // Verify ownership
    foreach ($groups as $g) {
        if ((int)$g['group_id'] === $selectedBatch) {
            $batchDetail = $g;
            break;
        }
    }

    if ($batchDetail) {
        // Members with performance
        $bm = safePreparedQuery($conn,
            "SELECT
                u.user_id,
                u.full_name,
                u.email,
                u.department,
                u.registration_number,
                u.profile_image,
                gm.joined_at,
                COUNT(DISTINCT aa.attempt_id)   AS total_attempts,
                ROUND(AVG(aa.percentage), 1)    AS avg_score,
                MAX(aa.percentage)              AS max_score,
                MAX(aa.submitted_at)            AS last_activity
            FROM group_members gm
            JOIN users u ON u.user_id = gm.student_id
            LEFT JOIN assessment_attempts aa
                ON aa.user_id = u.user_id
                AND aa.status IN ('submitted','timeout')
                AND aa.assessment_id IN (
                    SELECT assessment_id FROM assessments WHERE created_by = ?
                )
            WHERE gm.group_id = ?
            GROUP BY u.user_id, u.full_name, u.email, u.department, u.registration_number, u.profile_image, gm.joined_at
            ORDER BY avg_score DESC, u.full_name",
            "ii", [$teacherId, $selectedBatch]
        );
        if ($bm['success'] && $bm['result']) {
            while ($row = $bm['result']->fetch_assoc()) {
                $batchMembers[] = $row;
            }
            $bm['result']->free();
        }

        // Assessments targeted at this group
        $ba = safePreparedQuery($conn,
            "SELECT
                a.assessment_id,
                a.title,
                a.status,
                a.start_time,
                COUNT(DISTINCT aa.attempt_id)   AS attempts_count,
                ROUND(AVG(aa.percentage), 1)    AS avg_score,
                MAX(aa.percentage)              AS top_score,
                MIN(aa.percentage)              AS low_score
            FROM assessments a
            LEFT JOIN assessment_targets at2 ON at2.assessment_id = a.assessment_id
                AND at2.target_type = 'group' AND at2.target_id = ?
            LEFT JOIN assessment_attempts aa
                ON aa.assessment_id = a.assessment_id
                AND aa.status IN ('submitted','timeout')
                AND aa.user_id IN (
                    SELECT student_id FROM group_members WHERE group_id = ?
                )
            WHERE a.created_by = ?
              AND (at2.id IS NOT NULL OR a.visibility = 'public')
            GROUP BY a.assessment_id, a.title, a.status, a.start_time
            ORDER BY a.start_time DESC",
            "iii", [$selectedBatch, $selectedBatch, $teacherId]
        );
        if ($ba['success'] && $ba['result']) {
            while ($row = $ba['result']->fetch_assoc()) {
                $batchAssessments[] = $row;
            }
            $ba['result']->free();
        }
    }
}

// ── Helpers ──
function fmtDate(?string $dt): string {
    if (!$dt) return '—';
    return date('M j, Y', strtotime($dt));
}
function scoreColor(?float $score): string {
    if ($score === null) return '#ccc';
    return $score >= 75 ? 'var(--emerald)' : ($score >= 50 ? 'var(--gold)' : 'var(--rose)');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Students & Batches — PREPAURA</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
/* ── DESIGN TOKENS ── */
:root {
  --ink:         #0d0a14;
  --ink-2:       #1a1425;
  --ink-3:       #261d35;
  --surface:     #f7f5fb;
  --surface-2:   #ede9f6;
  --surface-3:   #ffffff;
  --violet:      #7c3aed;
  --violet-lt:   #9f67f5;
  --violet-dim:  rgba(124,58,237,0.12);
  --violet-glow: rgba(124,58,237,0.25);
  --orchid:      #c084fc;
  --gold:        #f59e0b;
  --emerald:     #10b981;
  --rose:        #f43f5e;
  --sky:         #38bdf8;
  --text-1:      #1a1425;
  --text-2:      #4b4565;
  --text-3:      #8b7fa8;
  --border:      rgba(124,58,237,0.1);
  --border-2:    rgba(124,58,237,0.18);
  --shadow-xs:   0 1px 3px rgba(13,10,20,0.06);
  --shadow-sm:   0 2px 12px rgba(13,10,20,0.08);
  --shadow-md:   0 8px 32px rgba(13,10,20,0.12);
  --shadow-lg:   0 20px 60px rgba(13,10,20,0.18);
  --r-sm:        8px;
  --r-md:        14px;
  --r-lg:        20px;
  --r-xl:        28px;
  --ease:        cubic-bezier(0.22,1,0.36,1);
  --t:           0.22s var(--ease);
  --font-head:   'Times New Roman', Arial, serif;
  --font-body:   'Calibri', 'Segoe UI', Arial, sans-serif;
  --nav-h:       64px;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
html{-webkit-font-smoothing:antialiased;scroll-behavior:smooth}
body{font-family:var(--font-body);font-size:15px;background:var(--surface);color:var(--text-1);min-height:100vh;padding-top:var(--nav-h);overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.03'/%3E%3C/svg%3E");background-size:200px 200px}

/* NAVBAR */
.navbar{height:var(--nav-h);background:rgba(13,10,20,0.96);backdrop-filter:blur(20px) saturate(1.6);border-bottom:1px solid rgba(255,255,255,0.06);padding:0 28px;display:flex;align-items:center;justify-content:space-between;gap:20px;position:fixed;top:0;left:0;right:0;z-index:1000}
.navbar-brand{display:flex;align-items:center;gap:12px;text-decoration:none;flex-shrink:0}
.brand-logo-img{width:36px;height:36px;border-radius:9px;object-fit:contain;background:white;padding:3px}
.brand-text-group{display:flex;flex-direction:column;line-height:1.15}
.brand-name{font-family:var(--font-head);font-size:16px;font-weight:800;letter-spacing:0.06em;color:white}
.brand-tagline{font-size:10px;font-weight:400;color:rgba(255,255,255,0.45);letter-spacing:0.03em}
.nav-right{display:flex;align-items:center;gap:12px}
.profile-wrap{position:relative}
.profile-button{display:flex;align-items:center;gap:9px;padding:6px 12px 6px 6px;background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.1);border-radius:40px;cursor:pointer;transition:var(--t);color:white}
.profile-button:hover{background:rgba(255,255,255,0.13);border-color:rgba(255,255,255,0.18)}
.profile-avatar{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--violet),var(--orchid));display:flex;align-items:center;justify-content:center;font-family:var(--font-head);font-weight:700;font-size:12px;color:white;overflow:hidden;flex-shrink:0}
.profile-avatar img{width:100%;height:100%;object-fit:cover;border-radius:50%}
.profile-name{font-size:13px;font-weight:500}
.profile-caret{font-size:9px;color:rgba(255,255,255,0.5);margin-left:2px}
.profile-dropdown{position:absolute;top:calc(100% + 10px);right:0;background:var(--surface-3);border-radius:var(--r-md);box-shadow:var(--shadow-lg),0 0 0 1px var(--border);min-width:230px;opacity:0;visibility:hidden;transform:translateY(-6px) scale(0.98);transition:var(--t);z-index:1001;overflow:hidden}
.profile-dropdown.open{opacity:1;visibility:visible;transform:translateY(0) scale(1)}
.dropdown-header{padding:18px 20px;background:linear-gradient(135deg,var(--ink) 0%,var(--ink-3) 100%);border-bottom:1px solid rgba(255,255,255,0.06)}
.dd-avatar{width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,var(--violet),var(--orchid));display:flex;align-items:center;justify-content:center;font-family:var(--font-head);font-weight:700;font-size:16px;color:white;overflow:hidden;margin-bottom:10px}
.dd-avatar img{width:100%;height:100%;object-fit:cover;border-radius:50%}
.dropdown-name{font-weight:600;font-size:14px;color:white}
.dropdown-email{font-size:12px;color:rgba(255,255,255,0.5);margin-top:2px}
.dropdown-role{display:inline-block;margin-top:8px;padding:2px 10px;background:var(--violet-dim);border:1px solid rgba(124,58,237,0.3);color:var(--orchid);border-radius:20px;font-size:11px;font-weight:600;letter-spacing:0.04em;text-transform:uppercase}
.dropdown-menu{padding:6px 0}
.dropdown-item{display:flex;align-items:center;gap:11px;padding:10px 18px;color:var(--text-2);text-decoration:none;font-size:13.5px;transition:var(--t);cursor:pointer;border:none;background:none;width:100%;text-align:left;font-family:var(--font-body)}
.dropdown-item i{width:16px;text-align:center;color:var(--text-3)}
.dropdown-item:hover{background:var(--surface-2);color:var(--text-1)}
.dropdown-item.danger{color:var(--rose)}
.dropdown-item.danger i{color:var(--rose)}
.dropdown-item.danger:hover{background:rgba(244,63,94,0.06)}
.dropdown-divider{height:1px;background:var(--border);margin:4px 0}

/* LAYOUT */
.page-wrapper{display:flex;min-height:calc(100vh - var(--nav-h));position:relative;z-index:1}
.left-sidebar{width:230px;flex-shrink:0;padding:28px 12px;display:flex;flex-direction:column;gap:2px;background:rgba(255,255,255,0.6);backdrop-filter:blur(12px);border-right:1px solid var(--border);min-height:calc(100vh - var(--nav-h));position:sticky;top:var(--nav-h);align-self:flex-start}
.sidebar-section-label{font-family:var(--font-head);font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-3);padding:14px 14px 6px}
.sidebar-link{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:var(--r-sm);text-decoration:none;font-size:13.5px;font-weight:500;color:var(--text-2);transition:var(--t)}
.sidebar-link i{width:18px;text-align:center;font-size:14px;color:var(--text-3);transition:var(--t)}
.sidebar-link:hover{background:var(--violet-dim);color:var(--violet)}
.sidebar-link:hover i{color:var(--violet)}
.sidebar-link.active{background:linear-gradient(135deg,rgba(124,58,237,0.12),rgba(192,132,252,0.08));color:var(--violet);font-weight:600;box-shadow:inset 3px 0 0 var(--violet)}
.sidebar-link.active i{color:var(--violet)}
.sidebar-bottom{margin-top:auto;padding-top:16px;border-top:1px solid var(--border)}
.sidebar-logout{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:var(--r-sm);font-size:13.5px;font-weight:500;color:var(--rose);background:none;border:none;cursor:pointer;width:100%;transition:var(--t);font-family:var(--font-body)}
.sidebar-logout i{width:18px;text-align:center;font-size:14px}
.sidebar-logout:hover{background:rgba(244,63,94,0.07)}
.page-content{flex:1;min-width:0;padding:36px 36px 48px 28px}

/* PAGE HEADER */
.page-header{margin-bottom:28px}
.page-header h1{font-family:var(--font-head);font-size:28px;font-weight:800;color:var(--text-1);margin-bottom:4px}
.page-header p{color:var(--text-3);font-size:14px}

/* TAB SWITCHER */
.tab-switcher{display:flex;align-items:center;gap:4px;background:var(--surface-2);border-radius:var(--r-md);padding:4px;width:fit-content;margin-bottom:24px;border:1px solid var(--border)}
.tab-btn{display:flex;align-items:center;gap:8px;padding:9px 20px;border-radius:10px;border:none;background:none;font-family:var(--font-body);font-size:13.5px;font-weight:500;color:var(--text-2);cursor:pointer;transition:var(--t);text-decoration:none;white-space:nowrap}
.tab-btn i{font-size:13px;color:var(--text-3);transition:var(--t)}
.tab-btn:hover{background:rgba(255,255,255,0.7);color:var(--text-1)}
.tab-btn.active{background:var(--surface-3);color:var(--violet);font-weight:600;box-shadow:var(--shadow-xs)}
.tab-btn.active i{color:var(--violet)}

/* TOOLBAR */
.toolbar{display:flex;align-items:center;gap:12px;margin-bottom:24px;flex-wrap:wrap}
.toolbar-search{flex:1;min-width:200px;max-width:340px;position:relative}
.toolbar-search input{width:100%;padding:10px 38px 10px 14px;background:var(--surface-3);border:1.5px solid var(--border-2);border-radius:var(--r-md);font-family:var(--font-body);font-size:13.5px;color:var(--text-1);outline:none;transition:var(--t)}
.toolbar-search input:focus{border-color:var(--violet);box-shadow:0 0 0 3px var(--violet-dim)}
.toolbar-search i{position:absolute;right:13px;top:50%;transform:translateY(-50%);color:var(--text-3);font-size:13px;pointer-events:none}
.group-filter{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.group-btn{padding:8px 14px;border-radius:var(--r-md);border:1.5px solid var(--border-2);background:var(--surface-3);font-family:var(--font-body);font-size:13px;font-weight:500;color:var(--text-2);cursor:pointer;text-decoration:none;transition:var(--t);white-space:nowrap}
.group-btn:hover{border-color:var(--violet);color:var(--violet);background:var(--violet-dim)}
.group-btn.active{background:var(--violet);border-color:var(--violet);color:white;font-weight:600}
.count-badge{margin-left:auto;font-size:13px;color:var(--text-3);white-space:nowrap}

/* STUDENT TABLE */
.students-card{background:var(--surface-3);border-radius:var(--r-lg);box-shadow:var(--shadow-sm);border:1px solid var(--border);overflow:hidden}
.students-table{width:100%;border-collapse:collapse}
.students-table thead tr{background:linear-gradient(135deg,var(--ink) 0%,var(--ink-3) 100%)}
.students-table thead th{padding:14px 18px;text-align:left;font-family:var(--font-head);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:rgba(255,255,255,0.7);white-space:nowrap}
.students-table tbody tr{border-bottom:1px solid var(--border);transition:background var(--t);cursor:pointer}
.students-table tbody tr:last-child{border-bottom:none}
.students-table tbody tr:hover{background:var(--violet-dim)}
.students-table td{padding:14px 18px;font-size:13.5px;color:var(--text-2);vertical-align:middle}
.student-info{display:flex;align-items:center;gap:12px}
.student-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,var(--violet),var(--orchid));display:flex;align-items:center;justify-content:center;font-family:var(--font-head);font-weight:700;font-size:13px;color:white;overflow:hidden;flex-shrink:0}
.student-avatar img{width:100%;height:100%;object-fit:cover;border-radius:50%}
.student-name{font-weight:600;color:var(--text-1);font-size:14px}
.student-email{font-size:12px;color:var(--text-3);margin-top:1px}
.student-reg{font-size:12px;color:var(--text-3)}
.score-bar-wrap{display:flex;align-items:center;gap:8px;min-width:100px}
.score-bar{flex:1;height:6px;border-radius:3px;background:var(--surface-2);overflow:hidden}
.score-bar-fill{height:100%;border-radius:3px;transition:width 0.6s var(--ease)}
.score-val{font-size:13px;font-weight:600;color:var(--text-1);white-space:nowrap}
.view-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;background:var(--violet-dim);border:1px solid rgba(124,58,237,0.25);border-radius:var(--r-sm);color:var(--violet);font-family:var(--font-body);font-size:12.5px;font-weight:600;text-decoration:none;transition:var(--t);white-space:nowrap}
.view-btn:hover{background:var(--violet);color:white}
.empty-state{text-align:center;padding:64px 24px;color:var(--text-3)}
.empty-state i{font-size:40px;margin-bottom:14px;display:block;color:var(--border-2)}
.empty-state h3{font-size:17px;font-weight:600;color:var(--text-2);margin-bottom:8px}
.empty-state p{font-size:14px}
.hidden{display:none!important}

/* BATCH CARDS GRID */
.batch-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin-bottom:32px}
.batch-card{background:var(--surface-3);border-radius:var(--r-lg);border:1.5px solid var(--border);box-shadow:var(--shadow-sm);padding:22px 24px;cursor:pointer;transition:var(--t);text-decoration:none;display:block}
.batch-card:hover{border-color:var(--violet);box-shadow:var(--shadow-md);transform:translateY(-2px)}
.batch-card.selected{border-color:var(--violet);background:linear-gradient(135deg,rgba(124,58,237,0.06),rgba(192,132,252,0.04));box-shadow:0 0 0 3px var(--violet-dim),var(--shadow-sm)}
.batch-card-header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:16px}
.batch-icon{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,var(--violet),var(--orchid));display:flex;align-items:center;justify-content:center;color:white;font-size:17px;flex-shrink:0}
.batch-name{font-family:var(--font-head);font-size:15px;font-weight:700;color:var(--text-1);margin-bottom:2px}
.batch-created{font-size:11.5px;color:var(--text-3)}
.batch-stats-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.batch-stat{background:var(--surface-2);border-radius:var(--r-sm);padding:10px 12px}
.batch-stat-val{font-size:18px;font-weight:700;color:var(--text-1);font-family:var(--font-head);line-height:1}
.batch-stat-lbl{font-size:11px;color:var(--text-3);margin-top:3px}
.batch-avg-bar{margin-top:14px}
.batch-avg-label{display:flex;justify-content:space-between;font-size:12px;color:var(--text-3);margin-bottom:5px}
.batch-avg-label span:last-child{font-weight:600;color:var(--text-1)}

/* BATCH DETAIL PANEL */
.batch-detail-panel{background:var(--surface-3);border-radius:var(--r-lg);border:1.5px solid var(--border);box-shadow:var(--shadow-sm);overflow:hidden}
.batch-detail-header{padding:20px 24px;background:linear-gradient(135deg,var(--ink) 0%,var(--ink-3) 100%);display:flex;align-items:center;justify-content:space-between;gap:16px}
.batch-detail-title{font-family:var(--font-head);font-size:18px;font-weight:700;color:white}
.batch-detail-sub{font-size:13px;color:rgba(255,255,255,0.5);margin-top:2px}
.batch-detail-close{display:flex;align-items:center;gap:6px;padding:8px 14px;background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.15);border-radius:var(--r-sm);color:rgba(255,255,255,0.8);font-family:var(--font-body);font-size:12.5px;font-weight:600;cursor:pointer;text-decoration:none;transition:var(--t)}
.batch-detail-close:hover{background:rgba(255,255,255,0.18);color:white}

.batch-summary-bar{display:grid;grid-template-columns:repeat(4,1fr);gap:0;border-bottom:1px solid var(--border)}
.bsb-item{padding:16px 20px;border-right:1px solid var(--border);text-align:center}
.bsb-item:last-child{border-right:none}
.bsb-val{font-family:var(--font-head);font-size:22px;font-weight:800;color:var(--text-1)}
.bsb-lbl{font-size:11.5px;color:var(--text-3);margin-top:3px}

.detail-section{padding:0}
.detail-section-title{padding:16px 24px 12px;font-family:var(--font-head);font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-3);border-bottom:1px solid var(--border);background:var(--surface)}

/* Rank badge for batch member list */
.rank-badge{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:var(--font-head);font-weight:700;font-size:11px;flex-shrink:0}
.rank-badge.gold{background:rgba(245,158,11,0.15);color:var(--gold)}
.rank-badge.silver{background:rgba(148,163,184,0.15);color:#94a3b8}
.rank-badge.bronze{background:rgba(180,120,80,0.12);color:#b47850}
.rank-badge.normal{background:var(--surface-2);color:var(--text-3)}

/* Assessment performance table inside batch detail */
.assessment-perf-table{width:100%;border-collapse:collapse}
.assessment-perf-table th{padding:11px 18px;text-align:left;font-family:var(--font-head);font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-3);border-bottom:1px solid var(--border);background:var(--surface)}
.assessment-perf-table td{padding:13px 18px;font-size:13px;color:var(--text-2);border-bottom:1px solid var(--border)}
.assessment-perf-table tbody tr:last-child td{border-bottom:none}
.assessment-perf-table tbody tr:hover td{background:var(--violet-dim)}
.status-pill{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600}
.status-pill.published{background:rgba(16,185,129,0.1);color:var(--emerald)}
.status-pill.draft{background:rgba(139,127,168,0.12);color:var(--text-3)}
.status-pill.archived{background:rgba(244,63,94,0.08);color:var(--rose)}

@media(max-width:900px){
  .left-sidebar{display:none}
  .students-table thead th:nth-child(3),
  .students-table td:nth-child(3),
  .students-table thead th:nth-child(5),
  .students-table td:nth-child(5){display:none}
  .batch-summary-bar{grid-template-columns:1fr 1fr}
  .bsb-item:nth-child(2){border-right:none}
  .bsb-item:nth-child(3){border-top:1px solid var(--border)}
}
@media(max-width:640px){
  .page-content{padding:20px 16px 40px}
  .students-table thead th:nth-child(4),
  .students-table td:nth-child(4){display:none}
  .batch-grid{grid-template-columns:1fr}
}
</style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar">
  <a href="teacher-dashboard.php" class="navbar-brand">
    <img src="prepaura-logo.png" alt="PREPAURA" class="brand-logo-img">
    <div class="brand-text-group">
      <span class="brand-name">PREPAURA</span>
      <span class="brand-tagline">Placement Training Platform</span>
    </div>
  </a>
  <div class="nav-right">
    <div class="profile-wrap">
      <button class="profile-button" id="profileBtn">
        <div class="profile-avatar">
          <?php if (!empty($userPicture)): ?>
            <img src="<?= htmlspecialchars($userPicture) ?>" alt="Profile">
          <?php else: ?>
            <?= $userInitials ?>
          <?php endif; ?>
        </div>
        <span class="profile-name"><?= $userName ?></span>
        <i class="fa fa-chevron-down profile-caret"></i>
        <div class="profile-dropdown" id="profileDropdown">
          <div class="dropdown-header">
            <div class="dd-avatar">
              <?php if (!empty($userPicture)): ?>
                <img src="<?= htmlspecialchars($userPicture) ?>" alt="Profile">
              <?php else: ?>
                <?= $userInitials ?>
              <?php endif; ?>
            </div>
            <div class="dropdown-name"><?= $userName ?></div>
            <div class="dropdown-email"><?= $userEmail ?></div>
            <span class="dropdown-role">Teacher</span>
          </div>
          <div class="dropdown-menu">
            <a href="teacher-profile.php" class="dropdown-item"><i class="fa fa-user"></i> My Profile</a>
            <div class="dropdown-divider"></div>
            <a href="#" onclick="handleLogout()" class="dropdown-item danger"><i class="fa fa-right-from-bracket"></i> Logout</a>
          </div>
        </div>
      </button>
    </div>
  </div>
</nav>

<!-- MAIN -->
<div class="page-wrapper">
  <aside class="left-sidebar">
    <span class="sidebar-section-label">Navigation</span>
    <a href="teacher-dashboard.php" class="sidebar-link"><i class="fa fa-house"></i> Dashboard</a>
    <a href="teacher-assessments.php" class="sidebar-link"><i class="fa fa-clipboard-list"></i> Assessments</a>
    <a href="teacher-students.php" class="sidebar-link active"><i class="fa fa-user-graduate"></i> Students</a>
    <a href="manage-groups.php" class="sidebar-link"><i class="fa fa-users"></i> Manage Groups</a>
    <a href="teacher-resources.php" class="sidebar-link"><i class="fa fa-folder-open"></i> Resources</a>
    <div class="sidebar-bottom">
      <button onclick="handleLogout()" class="sidebar-logout"><i class="fa fa-right-from-bracket"></i> Logout</button>
    </div>
  </aside>

  <div class="page-content">
    <div class="page-header">
      <h1>Students &amp; Batches</h1>
      <p>View individual students or analyse performance by batch.</p>
    </div>

    <!-- TAB SWITCHER -->
    <div class="tab-switcher">
      <a href="teacher-students.php?tab=students" class="tab-btn <?= $activeTab === 'students' ? 'active' : '' ?>">
        <i class="fa fa-user-graduate"></i> Students
      </a>
      <a href="teacher-students.php?tab=batches" class="tab-btn <?= $activeTab === 'batches' ? 'active' : '' ?>">
        <i class="fa fa-layer-group"></i> Batches
      </a>
    </div>

    <?php if ($activeTab === 'students'): ?>
    <!-- ═══════════════════════════════════════ STUDENTS TAB ═══ -->

    <!-- Toolbar -->
    <div class="toolbar">
      <div class="toolbar-search">
        <input type="text" id="studentSearch" placeholder="Search by name, email, or reg no…" autocomplete="off">
        <i class="fa fa-search"></i>
      </div>
      <div class="group-filter">
        <a href="teacher-students.php?tab=students" class="group-btn <?= $selectedGroup === null ? 'active' : '' ?>">All Groups</a>
        <?php foreach ($groups as $g): ?>
          <a href="teacher-students.php?tab=students&group=<?= $g['group_id'] ?>"
             class="group-btn <?= $selectedGroup === (int)$g['group_id'] ? 'active' : '' ?>">
            <?= htmlspecialchars($g['name']) ?>
          </a>
        <?php endforeach; ?>
      </div>
      <span class="count-badge" id="studentCountBadge">
        <?= count($students) ?> student<?= count($students) !== 1 ? 's' : '' ?>
      </span>
    </div>

    <!-- Table -->
    <div class="students-card">
      <?php if (empty($students)): ?>
        <div class="empty-state">
          <i class="fa fa-user-graduate"></i>
          <h3>No students found</h3>
          <p><?= $selectedGroup ? 'No students in this group yet.' : 'Students will appear here once they join your groups or attempt your assessments.' ?></p>
        </div>
      <?php else: ?>
      <table class="students-table" id="studentsTable">
        <thead>
          <tr>
            <th>Student</th>
            <th>Reg. No.</th>
            <th>Department</th>
            <th>Attempts</th>
            <th>Avg Score</th>
            <th>Last Active</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($students as $s):
            $initials = strtoupper(substr($s['full_name'] ?? '?', 0, 2));
            $avg      = $s['avg_score'] !== null ? (float)$s['avg_score'] : null;
            $barColor = scoreColor($avg);
          ?>
          <tr class="student-row"
              data-name="<?= strtolower(htmlspecialchars($s['full_name'])) ?>"
              data-email="<?= strtolower(htmlspecialchars($s['email'])) ?>"
              data-reg="<?= strtolower(htmlspecialchars($s['registration_number'] ?? '')) ?>"
              onclick="window.location='teacher-student-report.php?student_id=<?= $s['user_id'] ?>'">
            <td>
              <div class="student-info">
                <div class="student-avatar">
                  <?php if (!empty($s['profile_image'])): ?>
                    <img src="<?= htmlspecialchars($s['profile_image']) ?>" alt="">
                  <?php else: ?>
                    <?= $initials ?>
                  <?php endif; ?>
                </div>
                <div>
                  <div class="student-name"><?= htmlspecialchars($s['full_name']) ?></div>
                  <div class="student-email"><?= htmlspecialchars($s['email']) ?></div>
                </div>
              </div>
            </td>
            <td class="student-reg"><?= htmlspecialchars($s['registration_number'] ?? '—') ?></td>
            <td><?= htmlspecialchars($s['department'] ?? '—') ?></td>
            <td style="font-weight:600;color:var(--text-1)"><?= (int)$s['total_attempts'] ?></td>
            <td>
              <?php if ($avg !== null): ?>
              <div class="score-bar-wrap">
                <div class="score-bar">
                  <div class="score-bar-fill" style="width:<?= min($avg,100) ?>%;background:<?= $barColor ?>"></div>
                </div>
                <span class="score-val"><?= number_format($avg, 1) ?>%</span>
              </div>
              <?php else: ?>
              <span style="color:var(--text-3);font-size:13px">No attempts</span>
              <?php endif; ?>
            </td>
            <td style="font-size:13px;color:var(--text-3)"><?= fmtDate($s['last_activity']) ?></td>
            <td onclick="event.stopPropagation()">
              <a href="teacher-student-report.php?student_id=<?= $s['user_id'] ?>" class="view-btn">
                <i class="fa fa-chart-line"></i> Report
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <?php else: ?>
    <!-- ═══════════════════════════════════════ BATCHES TAB ═══ -->

    <?php if (empty($groups)): ?>
      <div class="students-card">
        <div class="empty-state">
          <i class="fa fa-layer-group"></i>
          <h3>No batches yet</h3>
          <p>Create groups from <a href="manage-groups.php" style="color:var(--violet)">Manage Groups</a> to see batch performance here.</p>
        </div>
      </div>
    <?php else: ?>

      <!-- Batch cards grid -->
      <div class="batch-grid">
        <?php foreach ($groups as $g):
          $gid  = (int)$g['group_id'];
          $bs   = $batchStats[$gid] ?? [];
          $avg  = isset($bs['avg_score']) && $bs['avg_score'] !== null ? (float)$bs['avg_score'] : null;
          $bCol = scoreColor($avg);
          $isSelected = $selectedBatch === $gid;
        ?>
        <a href="teacher-students.php?tab=batches&batch=<?= $gid ?>"
           class="batch-card <?= $isSelected ? 'selected' : '' ?>">
          <div class="batch-card-header">
            <div style="flex:1;min-width:0">
              <div class="batch-name"><?= htmlspecialchars($g['name']) ?></div>
              <div class="batch-created">Created <?= fmtDate($g['created_at']) ?></div>
            </div>
            <div class="batch-icon"><i class="fa fa-users"></i></div>
          </div>
          <div class="batch-stats-row">
            <div class="batch-stat">
              <div class="batch-stat-val"><?= (int)($bs['member_count'] ?? 0) ?></div>
              <div class="batch-stat-lbl">Members</div>
            </div>
            <div class="batch-stat">
              <div class="batch-stat-val"><?= (int)($bs['total_attempts'] ?? 0) ?></div>
              <div class="batch-stat-lbl">Attempts</div>
            </div>
          </div>
          <div class="batch-avg-bar">
            <div class="batch-avg-label">
              <span>Avg Score</span>
              <span><?= $avg !== null ? number_format($avg, 1).'%' : '—' ?></span>
            </div>
            <div class="score-bar" style="height:7px">
              <div class="score-bar-fill" style="width:<?= $avg !== null ? min($avg,100) : 0 ?>%;background:<?= $bCol ?>"></div>
            </div>
          </div>
        </a>
        <?php endforeach; ?>
      </div>

      <!-- Batch detail panel -->
      <?php if ($selectedBatch !== null && $batchDetail !== null):
        $bs   = $batchStats[$selectedBatch] ?? [];
        $avg  = isset($bs['avg_score']) && $bs['avg_score'] !== null ? (float)$bs['avg_score'] : null;
        $top  = isset($bs['top_score']) && $bs['top_score'] !== null ? (float)$bs['top_score'] : null;
      ?>
      <div class="batch-detail-panel">
        <div class="batch-detail-header">
          <div>
            <div class="batch-detail-title"><?= htmlspecialchars($batchDetail['name']) ?></div>
            <?php if (!empty($batchDetail['description'])): ?>
            <div class="batch-detail-sub"><?= htmlspecialchars($batchDetail['description']) ?></div>
            <?php endif; ?>
          </div>
          <a href="teacher-students.php?tab=batches" class="batch-detail-close">
            <i class="fa fa-xmark"></i> Close
          </a>
        </div>

        <!-- Summary bar -->
        <div class="batch-summary-bar">
          <div class="bsb-item">
            <div class="bsb-val"><?= (int)($bs['member_count'] ?? 0) ?></div>
            <div class="bsb-lbl">Members</div>
          </div>
          <div class="bsb-item">
            <div class="bsb-val"><?= (int)($bs['total_attempts'] ?? 0) ?></div>
            <div class="bsb-lbl">Total Attempts</div>
          </div>
          <div class="bsb-item">
            <div class="bsb-val" style="color:<?= scoreColor($avg) ?>"><?= $avg !== null ? number_format($avg,1).'%' : '—' ?></div>
            <div class="bsb-lbl">Avg Score</div>
          </div>
          <div class="bsb-item">
            <div class="bsb-val" style="color:var(--gold)"><?= $top !== null ? number_format($top,1).'%' : '—' ?></div>
            <div class="bsb-lbl">Top Score</div>
          </div>
        </div>

        <!-- Member leaderboard -->
        <div class="detail-section-title"><i class="fa fa-trophy" style="margin-right:6px;color:var(--gold)"></i>Member Performance</div>
        <?php if (empty($batchMembers)): ?>
          <div class="empty-state" style="padding:36px 24px">
            <i class="fa fa-user-slash"></i>
            <h3>No members yet</h3>
            <p>Add students to this batch from Manage Groups.</p>
          </div>
        <?php else: ?>
        <table class="students-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Student</th>
              <th>Attempts</th>
              <th>Avg Score</th>
              <th>Best Score</th>
              <th>Last Active</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($batchMembers as $i => $m):
              $rank     = $i + 1;
              $initials = strtoupper(substr($m['full_name'] ?? '?', 0, 2));
              $mavg     = $m['avg_score'] !== null ? (float)$m['avg_score'] : null;
              $mmax     = $m['max_score'] !== null ? (float)$m['max_score'] : null;
              $rankClass = $rank === 1 ? 'gold' : ($rank === 2 ? 'silver' : ($rank === 3 ? 'bronze' : 'normal'));
            ?>
            <tr onclick="window.location='teacher-student-report.php?student_id=<?= $m['user_id'] ?>'" style="cursor:pointer">
              <td>
                <div class="rank-badge <?= $rankClass ?>"><?= $rank ?></div>
              </td>
              <td>
                <div class="student-info">
                  <div class="student-avatar">
                    <?php if (!empty($m['profile_image'])): ?>
                      <img src="<?= htmlspecialchars($m['profile_image']) ?>" alt="">
                    <?php else: ?>
                      <?= $initials ?>
                    <?php endif; ?>
                  </div>
                  <div>
                    <div class="student-name"><?= htmlspecialchars($m['full_name']) ?></div>
                    <div class="student-email"><?= htmlspecialchars($m['email']) ?></div>
                  </div>
                </div>
              </td>
              <td style="font-weight:600;color:var(--text-1)"><?= (int)$m['total_attempts'] ?></td>
              <td>
                <?php if ($mavg !== null): ?>
                <div class="score-bar-wrap">
                  <div class="score-bar">
                    <div class="score-bar-fill" style="width:<?= min($mavg,100) ?>%;background:<?= scoreColor($mavg) ?>"></div>
                  </div>
                  <span class="score-val"><?= number_format($mavg,1) ?>%</span>
                </div>
                <?php else: ?>
                <span style="color:var(--text-3);font-size:13px">No attempts</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($mmax !== null): ?>
                <span style="font-weight:600;color:<?= scoreColor($mmax) ?>"><?= number_format($mmax,1) ?>%</span>
                <?php else: ?>
                <span style="color:var(--text-3)">—</span>
                <?php endif; ?>
              </td>
              <td style="font-size:13px;color:var(--text-3)"><?= fmtDate($m['last_activity']) ?></td>
              <td onclick="event.stopPropagation()">
                <a href="teacher-student-report.php?student_id=<?= $m['user_id'] ?>" class="view-btn">
                  <i class="fa fa-chart-line"></i> Report
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>

        <!-- Assessment performance for this batch -->
        <div class="detail-section-title" style="border-top:1px solid var(--border)"><i class="fa fa-clipboard-list" style="margin-right:6px;color:var(--violet)"></i>Assessment Performance</div>
        <?php if (empty($batchAssessments)): ?>
          <div class="empty-state" style="padding:36px 24px">
            <i class="fa fa-clipboard-list"></i>
            <h3>No assessments yet</h3>
            <p>Publish assessments targeting this batch to see results here.</p>
          </div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="assessment-perf-table">
          <thead>
            <tr>
              <th>Assessment</th>
              <th>Status</th>
              <th>Date</th>
              <th>Attempts</th>
              <th>Avg Score</th>
              <th>Top</th>
              <th>Low</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($batchAssessments as $ap):
              $apAvg = $ap['avg_score'] !== null ? (float)$ap['avg_score'] : null;
              $apTop = $ap['top_score'] !== null ? (float)$ap['top_score'] : null;
              $apLow = $ap['low_score'] !== null ? (float)$ap['low_score'] : null;
            ?>
            <tr>
              <td style="font-weight:600;color:var(--text-1);max-width:240px">
                <a href="view-assessment.php?id=<?= $ap['assessment_id'] ?>" style="color:inherit;text-decoration:none" onclick="event.stopPropagation()">
                  <?= htmlspecialchars($ap['title']) ?>
                </a>
              </td>
              <td><span class="status-pill <?= htmlspecialchars($ap['status']) ?>"><?= ucfirst($ap['status']) ?></span></td>
              <td style="white-space:nowrap;font-size:12.5px;color:var(--text-3)"><?= fmtDate($ap['start_time']) ?></td>
              <td style="font-weight:600"><?= (int)$ap['attempts_count'] ?></td>
              <td>
                <?php if ($apAvg !== null): ?>
                <div class="score-bar-wrap" style="min-width:90px">
                  <div class="score-bar">
                    <div class="score-bar-fill" style="width:<?= min($apAvg,100) ?>%;background:<?= scoreColor($apAvg) ?>"></div>
                  </div>
                  <span class="score-val"><?= number_format($apAvg,1) ?>%</span>
                </div>
                <?php else: ?>
                <span style="color:var(--text-3);font-size:13px">—</span>
                <?php endif; ?>
              </td>
              <td style="font-weight:600;color:<?= scoreColor($apTop) ?>"><?= $apTop !== null ? number_format($apTop,1).'%' : '—' ?></td>
              <td style="font-weight:600;color:<?= scoreColor($apLow) ?>"><?= $apLow !== null ? number_format($apLow,1).'%' : '—' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

    <?php endif; ?>
    <?php endif; ?>

  </div><!-- /page-content -->
</div><!-- /page-wrapper -->

<script>
// ── Profile dropdown ──
const profileBtn      = document.getElementById('profileBtn');
const profileDropdown = document.getElementById('profileDropdown');
profileBtn?.addEventListener('click', e => {
  e.stopPropagation();
  profileDropdown.classList.toggle('open');
});
document.addEventListener('click', () => profileDropdown?.classList.remove('open'));

// ── Logout ──
function handleLogout() {
  if (confirm('Are you sure you want to logout?')) window.location.href = 'logout.php';
}

// ── Live search (students tab only) ──
document.getElementById('studentSearch')?.addEventListener('input', function () {
  const q     = this.value.toLowerCase().trim();
  const rows  = document.querySelectorAll('.student-row');
  let visible = 0;
  rows.forEach(row => {
    const match = !q
      || row.dataset.name.includes(q)
      || row.dataset.email.includes(q)
      || row.dataset.reg.includes(q);
    row.classList.toggle('hidden', !match);
    if (match) visible++;
  });
  const badge = document.getElementById('studentCountBadge');
  if (badge) badge.textContent = visible + ' student' + (visible !== 1 ? 's' : '');
});
</script>
</body>
</html>
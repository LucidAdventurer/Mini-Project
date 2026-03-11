<?php
// ============================================================
// notifications.php — All Notifications Page
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db-guard.php';

$currentUser  = validateSession($conn);
$userName     = htmlspecialchars($currentUser['full_name']);
$userInitials = strtoupper(substr($currentUser['full_name'], 0, 2));
$userId       = (int) $currentUser['user_id'];
$userRole     = $currentUser['user_type'] ?? 'student'; // from users.user_type: 'admin', 'teacher', 'student'
$canEdit      = ($userRole === 'admin');                // only admins can edit/delete notifications (no created_by column)

// Ensure CSRF token exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Handle AJAX edit / delete requests ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    header('Content-Type: application/json');

    // CSRF check
    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    if ($action === 'edit') {
        $nid     = (int)($body['notification_id'] ?? 0);
        $title   = trim($body['title']   ?? '');
        $message = trim($body['message'] ?? '');
        $type    = $body['notification_type'] ?? '';
        $allowed_types = ['info','success','warning','error','assessment','result','material'];

        if (!$nid || $title === '' || !in_array($type, $allowed_types, true)) {
            echo json_encode(['success' => false, 'error' => 'Invalid input']);
            exit;
        }

        // Only admins can edit notifications (no created_by column in schema)
        $editResult = safePreparedQuery($conn,
            "UPDATE notifications SET title=?, message=?, notification_type=? WHERE notification_id=?",
            "sssi", [$title, $message, $type, $nid]);

        echo json_encode(['success' => (bool)($editResult['success'] ?? false)]);
        exit;
    }

    if ($action === 'delete') {
        $nid = (int)($body['notification_id'] ?? 0);
        if (!$nid) { echo json_encode(['success' => false, 'error' => 'Invalid id']); exit; }

        // Only admins can delete any notification (no created_by column in schema)
        $delResult = safePreparedQuery($conn,
            "DELETE FROM notifications WHERE notification_id=?",
            "i", [$nid]);

        echo json_encode(['success' => (bool)($delResult['success'] ?? false)]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

// ── Unread count for sidebar badge & navbar ──
$unreadResult = safePreparedQuery($conn,
    "SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0",
    "i", [$userId]
);
$unreadCount = 0;
if ($unreadResult['success'] && $unreadResult['result']) {
    $row = $unreadResult['result']->fetch_assoc();
    $unreadCount = (int)($row['cnt'] ?? 0);
    $unreadResult['result']->free();
}

// ── Latest 5 for navbar dropdown ──
$notifDropResult = safePreparedQuery($conn,
    "SELECT notification_id, title, message, notification_type, is_read, created_at
     FROM notifications WHERE user_id = ?
     ORDER BY created_at DESC LIMIT 5",
    "i", [$userId]
);
$notifItems = [];
if ($notifDropResult['success'] && $notifDropResult['result']) {
    while ($row = $notifDropResult['result']->fetch_assoc()) {
        $notifItems[] = $row;
    }
    $notifDropResult['result']->free();
}

// ── Filter param ──
$filter = $_GET['filter'] ?? 'all';
$allowed = ['all', 'unread'];
if (!in_array($filter, $allowed, true)) $filter = 'all';

// ── Fetch all notifications ──
$whereExtra = '';
$params     = [$userId];
$types      = 'i';

if ($filter === 'unread') {
    $whereExtra = ' AND is_read = 0';
} elseif ($filter !== 'all') {
    $whereExtra = ' AND notification_type = ?';
    $params[]   = $filter;
    $types     .= 's';
}

$allNotifsResult = safePreparedQuery($conn,
    "SELECT notification_id, title, message, notification_type, is_read, read_at, action_url, created_at
     FROM notifications
     WHERE user_id = ? $whereExtra
     ORDER BY created_at DESC",
    $types, $params
);

$allNotifs = [];
if ($allNotifsResult['success'] && $allNotifsResult['result']) {
    while ($row = $allNotifsResult['result']->fetch_assoc()) {
        $allNotifs[] = $row;
    }
    $allNotifsResult['result']->free();
}

// ── Mark all as read on page load ──
safePreparedQuery($conn,
    "UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0",
    "i", [$userId]
);

// ── Helpers ──
function timeAgoFull(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff / 60)   . ' min ago';
    if ($diff < 86400)  return floor($diff / 3600)  . ' hr ago';
    if ($diff < 604800) return floor($diff / 86400) . ' day ago';
    return date('d M Y, g:i A', strtotime($datetime));
}

$typeIcons = [
    'info'       => ['ℹ️', '#4facfe', '#ebf8ff'],
    'success'    => ['✅', '#48bb78', '#f0fff4'],
    'warning'    => ['⚠️', '#ed8936', '#fffaf0'],
    'error'      => ['❌', '#fc8181', '#fff5f5'],
    'assessment' => ['📝', '#9f7aea', '#faf5ff'],
    'result'     => ['🏆', '#f6ad55', '#fffbeb'],
    'material'   => ['📚', '#4facfe', '#ebf8ff'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notifications – PREPAURA</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --primary:      #234C6A;
    --primary-dark: #456882;
}

body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: linear-gradient(135deg, #D3DAD9 0%, white 100%);
    min-height: 100vh;
}

/* ── NAVBAR ── */
.navbar {
    background: var(--primary);
    padding: 0 30px;
    height: 70px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: 0 2px 10px rgba(0,0,0,0.15);
}
.navbar-brand {
    display: flex;
    align-items: center;
    gap: 12px;
    text-decoration: none;
}
.nav-search {
    flex: 1; max-width: 500px; margin: 0 30px;
    position: relative;
}
.nav-search input {
    width: 100%;
    padding: 10px 20px 10px 45px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    font-family: inherit; font-size: 14px;
    background: #f7fafc; color: #2d3748;
    outline: none;
    transition: border-color .2s, box-shadow .2s;
}
.nav-search input:focus { border-color: #4facfe; box-shadow: 0 0 0 3px rgba(79,172,254,.15); }
.nav-search .sicon {
    position: absolute; left: 15px; top: 50%; transform: translateY(-50%);
    color: #a0aec0; font-size: 14px;
}
.nav-right { display: flex; align-items: center; gap: 15px; }
.notification-btn {
    position: relative;
    width: 40px; height: 40px;
    background: #f7fafc; border-radius: 10px;
    border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; transition: 0.3s;
}
.notification-btn:hover { background: #e2e8f0; }
.notif-badge {
    position: absolute; top: -5px; right: -5px;
    background: #e53e3e; color: white;
    width: 20px; height: 20px; border-radius: 50%;
    font-size: 11px; font-weight: bold;
    display: flex; align-items: center; justify-content: center;
    animation: badgePulse 1.8s ease-in-out infinite;
}
@keyframes badgePulse {
    0%   { box-shadow: 0 0 0 0 rgba(229,62,62,0.6); }
    70%  { box-shadow: 0 0 0 7px rgba(229,62,62,0); }
    100% { box-shadow: 0 0 0 0 rgba(229,62,62,0); }
}
/* Navbar notification dropdown */
.notif-dropdown-wrap { position: relative; }
.notif-dropdown {
    position: absolute; top: calc(100% + 10px); right: 0;
    background: white; border-radius: 14px;
    box-shadow: 0 8px 30px rgba(0,0,0,0.15);
    width: 340px; opacity: 0; visibility: hidden;
    transform: translateY(-8px); transition: 0.25s; z-index: 1002;
}
.notif-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0); }
.notif-dropdown-header {
    padding: 16px 20px 12px;
    font-weight: 700; font-size: 15px; color: #2d3748;
    border-bottom: 1px solid #e2e8f0;
}
.notif-list { max-height: 320px; overflow-y: auto; }
.nd-item {
    display: flex; gap: 12px; align-items: flex-start;
    padding: 14px 20px; border-bottom: 1px solid #f0f4f8;
    cursor: pointer; transition: background .15s;
}
.nd-item:hover { background: #f7fafc; }
.nd-item.unread { background: #f0f7ff; }
.nd-dot { width: 8px; height: 8px; border-radius: 50%; background: #4facfe; flex-shrink: 0; margin-top: 5px; }
.nd-dot.read { background: transparent; }
.nd-body { flex: 1; }
.nd-title { font-size: 13px; font-weight: 600; color: #2d3748; margin-bottom: 3px; }
.nd-msg   { font-size: 12px; color: #718096; line-height: 1.4; }
.nd-time  { font-size: 11px; color: #a0aec0; margin-top: 4px; }
.notif-see-all {
    display: block; text-align: center; padding: 12px;
    font-size: 13px; font-weight: 600; color: #4facfe;
    text-decoration: none; border-top: 1px solid #e2e8f0;
    transition: background .15s; border-radius: 0 0 14px 14px;
}
.notif-see-all:hover { background: #f7fafc; }
.notif-empty-dd { padding: 28px 20px; text-align: center; color: #a0aec0; font-size: 13px; }

/* Profile dropdown */
.profile-button {
    display: flex; align-items: center; gap: 10px;
    background: #f7fafc; border: 2px solid #e2e8f0;
    border-radius: 10px; padding: 6px 14px 6px 6px;
    cursor: pointer; transition: background .2s; font-family: inherit;
}
.profile-button:hover { background: #e2e8f0; }
.profile-avatar {
    width: 32px; height: 32px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: white; font-size: 12px; font-weight: 700;
}
.profile-name { color: #2d3748; font-size: 14px; font-weight: 500; }
.profile-wrapper { position: relative; }
.profile-dropdown {
    position: absolute; top: calc(100% + 10px); right: 0;
    background: white; border-radius: 12px;
    box-shadow: 0 8px 30px rgba(0,0,0,0.15);
    min-width: 220px; z-index: 1001;
    opacity: 0; visibility: hidden; transform: translateY(-8px); transition: 0.25s;
}
.profile-dropdown.open { opacity: 1; visibility: visible; transform: translateY(0); }
.dropdown-header {
    display: flex; align-items: center; gap: 12px;
    padding: 16px; border-bottom: 1px solid #e2e8f0;
}
.dropdown-avatar {
    width: 42px; height: 42px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: white; font-size: 14px; font-weight: 700; flex-shrink: 0;
}
.dropdown-user-name { font-weight: 700; font-size: 15px; color: #2d3748; }
.dropdown-user-email { font-size: 12px; color: #718096; margin-top: 2px; }
.dropdown-menu { padding: 8px 0; }
.dropdown-item {
    display: flex; align-items: center; gap: 12px;
    padding: 11px 16px; color: #2d3748; text-decoration: none;
    cursor: pointer; border: none; background: none;
    width: 100%; text-align: left; font-size: 14px; font-family: inherit;
    transition: background .15s;
}
.dropdown-item:hover { background: #f7fafc; }
.dropdown-item.logout { color: #e53e3e; }
.dropdown-item.logout:hover { background: #fff5f5; }
.dropdown-divider { height: 1px; background: #e2e8f0; margin: 6px 0; }

/* ── LAYOUT ── */
.page-wrapper { display: flex; min-height: calc(100vh - 70px); }

/* ── SIDEBAR ── */
.sidebar {
    width: 230px; flex-shrink: 0; padding: 24px 12px;
    display: flex; flex-direction: column; gap: 2px;
    min-height: calc(100vh - 70px);
    position: sticky; top: 70px; align-self: flex-start;
}
.sidebar-section {
    font-size: 11px; font-weight: 600;
    text-transform: uppercase; letter-spacing: .08em;
    color: #718096; padding: 14px 12px 6px;
}
.sidebar a {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px; border-radius: 10px;
    text-decoration: none; font-size: 14px; font-weight: 500;
    color: #4a5568; transition: background .15s, color .15s;
}
.sidebar a:hover { background: rgba(35,76,106,.08); color: var(--primary); }
.sidebar a.active { background: rgba(35,76,106,.12); color: var(--primary); font-weight: 600; }
.sidebar a i { width: 18px; text-align: center; font-size: 15px; }
.sidebar-bottom {
    margin-top: auto; padding-top: 12px;
    border-top: 1px solid rgba(35,76,106,.12);
}
.sidebar-bottom button {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px; border-radius: 10px;
    font-size: 14px; font-weight: 500;
    color: #e53e3e; background: none; border: none;
    cursor: pointer; width: 100%;
    transition: background .15s; font-family: inherit;
}
.sidebar-bottom button:hover { background: rgba(229,62,62,.08); }
.sidebar-bottom button i { width: 18px; text-align: center; font-size: 15px; }

/* ── MAIN ── */
.main { flex: 1; padding: 28px 28px 60px; min-width: 0; }

/* ── PAGE HEADER ── */
.page-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 24px;
}
.page-header h1 { font-size: 26px; font-weight: 700; color: #2d3748; }
.page-header p  { font-size: 14px; color: #718096; margin-top: 4px; }
.btn-mark-all {
    padding: 10px 20px; border-radius: 10px;
    background: linear-gradient(135deg, #4facfe, #00f2fe);
    color: white; border: none; font-size: 14px; font-weight: 600;
    cursor: pointer; font-family: inherit; transition: opacity .2s;
    display: flex; align-items: center; gap: 8px;
}
.btn-mark-all:hover { opacity: .88; }

/* ── FILTER TABS ── */
.filter-bar {
    display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 24px;
}
.filter-tab {
    padding: 8px 18px; border-radius: 8px;
    border: 2px solid #e2e8f0; background: white;
    font-family: inherit; font-size: 13px; font-weight: 500;
    cursor: pointer; color: #4a5568; text-decoration: none;
    transition: all .18s; display: inline-block;
}
.filter-tab:hover:not(.active) { background: #e2e8f0; }
.filter-tab.active {
    background: linear-gradient(135deg, #4facfe, #00f2fe);
    border-color: transparent; color: white; font-weight: 600;
}

/* ── NOTIFICATION CARDS ── */
.notif-card-list { display: flex; flex-direction: column; gap: 10px; }

.notif-card {
    background: white; border-radius: 14px;
    padding: 18px 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.06);
    display: flex; align-items: flex-start; gap: 16px;
    border-left: 4px solid transparent;
    transition: box-shadow .2s, transform .15s;
    cursor: default;
}
.notif-card:hover { box-shadow: 0 6px 20px rgba(0,0,0,0.1); transform: translateY(-1px); }
.notif-card.unread { border-left-color: #4facfe; background: #f8fbff; }

.notif-icon-wrap {
    width: 44px; height: 44px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.notif-card-body { flex: 1; min-width: 0; }
.notif-card-title {
    font-size: 15px; font-weight: 600; color: #2d3748;
    margin-bottom: 4px; line-height: 1.35;
}
.notif-card-msg { font-size: 13px; color: #718096; line-height: 1.5; }
.notif-card-meta {
    display: flex; align-items: center; gap: 14px;
    margin-top: 8px; flex-wrap: wrap;
}
.notif-card-time { font-size: 12px; color: #a0aec0; }
.notif-type-badge {
    font-size: 11px; font-weight: 600; padding: 2px 10px;
    border-radius: 20px; text-transform: capitalize;
}
.unread-dot {
    width: 9px; height: 9px; border-radius: 50%;
    background: #4facfe; flex-shrink: 0; margin-top: 5px;
}

.notif-card-action a {
    font-size: 13px; font-weight: 600; color: #4facfe;
    text-decoration: none;
}
.notif-card-action a:hover { text-decoration: underline; }

/* ── EMPTY STATE ── */
.empty-state {
    display: flex; flex-direction: column; align-items: center;
    justify-content: center; gap: 14px;
    padding: 60px 20px; text-align: center;
    background: white; border-radius: 20px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.06);
}
.empty-state i { font-size: 48px; color: #cbd5e0; }
.empty-state h3 { font-size: 18px; color: #4a5568; font-weight: 600; }
.empty-state p  { font-size: 14px; color: #a0aec0; }

/* ── CARD ACTION BUTTONS (edit/delete) ── */
.notif-card-actions {
    display: flex; gap: 8px; margin-top: 10px;
}
.btn-edit-notif, .btn-delete-notif {
    padding: 5px 14px; border-radius: 8px;
    font-size: 12px; font-weight: 600; font-family: inherit;
    border: none; cursor: pointer; display: inline-flex;
    align-items: center; gap: 6px; transition: opacity .2s, transform .15s;
}
.btn-edit-notif   { background: linear-gradient(135deg,#4facfe,#00f2fe); color: white; }
.btn-delete-notif { background: #fff5f5; color: #e53e3e; border: 1px solid #fed7d7; }
.btn-edit-notif:hover,
.btn-delete-notif:hover { opacity: .85; transform: translateY(-1px); }

/* ── EDIT MODAL ── */
.modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.45); z-index: 2000;
    align-items: center; justify-content: center;
}
.modal-overlay.open { display: flex; }
.modal-box {
    background: white; border-radius: 18px;
    padding: 30px 28px; width: 100%; max-width: 520px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
    animation: modalIn .22s ease;
}
@keyframes modalIn {
    from { opacity:0; transform: scale(.95) translateY(10px); }
    to   { opacity:1; transform: scale(1)  translateY(0);     }
}
.modal-box h2 { font-size: 18px; font-weight: 700; color: #2d3748; margin-bottom: 20px; }
.modal-field  { margin-bottom: 16px; }
.modal-field label {
    display: block; font-size: 13px; font-weight: 600;
    color: #4a5568; margin-bottom: 6px;
}
.modal-field input,
.modal-field textarea,
.modal-field select {
    width: 100%; padding: 10px 14px; border-radius: 10px;
    border: 2px solid #e2e8f0; font-size: 14px;
    font-family: inherit; color: #2d3748;
    outline: none; transition: border-color .2s;
    background: #f7fafc;
}
.modal-field textarea { resize: vertical; min-height: 90px; }
.modal-field input:focus,
.modal-field textarea:focus,
.modal-field select:focus { border-color: #4facfe; background: white; }
.modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; }
.btn-modal-cancel {
    padding: 10px 22px; border-radius: 10px;
    background: #f7fafc; border: 2px solid #e2e8f0;
    color: #4a5568; font-size: 14px; font-weight: 600;
    cursor: pointer; font-family: inherit; transition: background .15s;
}
.btn-modal-cancel:hover { background: #e2e8f0; }
.btn-modal-save {
    padding: 10px 22px; border-radius: 10px;
    background: linear-gradient(135deg,#4facfe,#00f2fe);
    color: white; border: none; font-size: 14px; font-weight: 600;
    cursor: pointer; font-family: inherit; transition: opacity .2s;
}
.btn-modal-save:hover { opacity: .88; }
.modal-error {
    margin-top: 12px; padding: 10px 14px;
    background: #fff5f5; border-radius: 8px;
    color: #e53e3e; font-size: 13px; display: none;
}

/* ── ROLE BADGE in header ── */
.role-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700;
    margin-left: 10px; vertical-align: middle;
}
.role-badge.admin   { background: #fef3c7; color: #92400e; }
.role-badge.teacher { background: #e0f2fe; color: #075985; }

@media (max-width: 900px) {
    .sidebar { display: none; }
    .main { padding: 20px; }
}
@media (max-width: 600px) {
    .nav-search { display: none; }
    .page-header { flex-direction: column; align-items: flex-start; gap: 12px; }
}
</style>
</head>
<body>

<!-- ── NAVBAR ── -->
<nav class="navbar">
    <a href="student-dashboard.php" class="navbar-brand">
        <img src="prepaura-logo.png" alt="Prepaura Logo" style="width:44px;height:44px;border-radius:10px;object-fit:contain;background:white;padding:3px;">
        <div style="display:flex;flex-direction:column;line-height:1.1;color:white">
            <span style="font-size:18px;font-weight:800;letter-spacing:.5px">PREPAURA</span>
            <span style="font-size:11px;font-weight:400;opacity:.85;font-style:italic">Placement Training Platform</span>
        </div>
    </a>

    <div class="nav-search">
        <i class="fa fa-search sicon"></i>
        <input type="text" id="searchInput" placeholder="Search notifications..." autocomplete="off">
    </div>

    <div class="nav-right">
        <div class="notif-dropdown-wrap">
            <button class="notification-btn" onclick="toggleNotifDropdown()" title="Notifications">
                <span>🔔</span>
                <?php if ($unreadCount > 0): ?>
                <div class="notif-badge" id="notifBadge"><?= $unreadCount ?></div>
                <?php endif; ?>
            </button>
            <div class="notif-dropdown" id="notifDropdown">
                <div class="notif-dropdown-header">Notifications</div>
                <div class="notif-list">
                    <?php if (empty($notifItems)): ?>
                        <div class="notif-empty-dd">No notifications yet.</div>
                    <?php else: foreach ($notifItems as $n):
                        $isU = !$n['is_read'];
                        $ico = $typeIcons[$n['notification_type']] ?? ['🔔','#4facfe','#ebf8ff'];
                    ?>
                    <div class="nd-item <?= $isU ? 'unread' : '' ?>">
                        <div class="nd-dot <?= $isU ? '' : 'read' ?>"></div>
                        <div class="nd-body">
                            <div class="nd-title"><?= $ico[0] ?> <?= htmlspecialchars($n['title']) ?></div>
                            <?php if ($n['message']): ?>
                            <div class="nd-msg"><?= htmlspecialchars($n['message']) ?></div>
                            <?php endif; ?>
                            <div class="nd-time"><?= timeAgoFull($n['created_at']) ?></div>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
                <a href="notifications.php" class="notif-see-all">See All</a>
            </div>
        </div>

        <div class="profile-wrapper" id="profileWrapper">
            <button class="profile-button" onclick="toggleDropdown()">
                <div class="profile-avatar"><?= $userInitials ?></div>
                <span class="profile-name"><?= $userName ?></span>
                <i class="fa fa-chevron-down" style="font-size:11px;color:#718096;margin-left:4px;"></i>
            </button>
            <div class="profile-dropdown" id="profileDropdown">
                <div class="dropdown-header">
                    <div class="dropdown-avatar"><?= $userInitials ?></div>
                    <div>
                        <div class="dropdown-user-name"><?= $userName ?></div>
                        <div class="dropdown-user-email"><?= htmlspecialchars($currentUser['email'] ?? '') ?></div>
                    </div>
                </div>
                <div class="dropdown-menu">
                    <a href="student-profile.php" class="dropdown-item"><i class="fa fa-user" style="width:18px"></i> My Profile</a>
                    <a href="help.html" target="_blank" class="dropdown-item"><i class="fa fa-circle-question" style="width:18px"></i> Help & Support</a>
                    <div class="dropdown-divider"></div>
                    <button onclick="if(confirm('Are you sure you want to logout?')) window.location.href='logout.php'" class="dropdown-item logout">
                        <i class="fa fa-sign-out-alt" style="width:18px"></i> Logout
                    </button>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- ── PAGE WRAPPER ── -->
<div class="page-wrapper">

    <!-- SIDEBAR -->
    <aside class="sidebar">
        <span class="sidebar-section">Navigation</span>
        <a href="student-dashboard.php"><i class="fa fa-home"></i> Dashboard</a>
        <a href="student-resources.php"><i class="fa fa-folder-open"></i> Resources</a>
        <a href="notifications.php" class="active" style="position:relative">
            <i class="fa fa-bell"></i> Notifications
            <?php if ($unreadCount > 0): ?>
            <span style="margin-left:auto;background:#e53e3e;color:white;font-size:11px;font-weight:700;padding:2px 7px;border-radius:20px;"><?= $unreadCount ?></span>
            <?php endif; ?>
        </a>
        <div class="sidebar-bottom">
            <button onclick="if(confirm('Are you sure you want to logout?')) window.location.href='logout.php'">
                <i class="fa fa-sign-out-alt"></i> Logout
            </button>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="main">

        <!-- Page header -->
        <div class="page-header">
            <div>
                <h1><i class="fa fa-bell" style="color:#4facfe;margin-right:10px;"></i>Notifications
                    <?php if ($canEdit): ?>
                    <span class="role-badge <?= htmlspecialchars($userRole) ?>">
                        <i class="fa fa-<?= $userRole === 'admin' ? 'shield-halved' : 'chalkboard-teacher' ?>"></i>
                        <?= ucfirst($userRole) ?> View
                    </span>
                    <?php endif; ?>
                </h1>
                <p><?= count($allNotifs) ?> notification<?= count($allNotifs) !== 1 ? 's' : '' ?>
                <?php if ($filter !== 'all'): ?> · filtered by <strong><?= ucfirst($filter) ?></strong><?php endif; ?></p>
            </div>
            <button class="btn-mark-all" id="markAllBtn" onclick="markAllRead()">
                <i class="fa fa-check-double"></i> Mark all as read
            </button>
        </div>

        <!-- Filter tabs -->
        <div class="filter-bar">
            <a href="notifications.php?filter=all"    class="filter-tab <?= $filter==='all'    ? 'active':'' ?>">All</a>
            <a href="notifications.php?filter=unread" class="filter-tab <?= $filter==='unread' ? 'active':'' ?>">Unread</a>
        </div>

        <!-- Notification list -->
        <?php if (empty($allNotifs)): ?>
            <div class="empty-state">
                <i class="fa fa-bell-slash"></i>
                <h3>No notifications</h3>
                <p>You're all caught up! Nothing here for this filter.</p>
            </div>
        <?php else: ?>
        <div class="notif-card-list" id="notifCardList">
            <?php foreach ($allNotifs as $n):
                $isUnread = !$n['is_read'];
                $info     = $typeIcons[$n['notification_type']] ?? ['🔔','#4facfe','#ebf8ff'];
                $ico      = $info[0]; $color = $info[1]; $bg = $info[2];
            ?>
            <div class="notif-card <?= $isUnread ? 'unread' : '' ?>" data-id="<?= $n['notification_id'] ?>" data-title="<?= htmlspecialchars(strtolower($n['title'])) ?>" data-msg="<?= htmlspecialchars(strtolower($n['message'] ?? '')) ?>">
                <div class="notif-icon-wrap" style="background:<?= $bg ?>; color:<?= $color ?>">
                    <?= $ico ?>
                </div>
                <div class="notif-card-body">
                    <div class="notif-card-title"><?= htmlspecialchars($n['title']) ?></div>
                    <?php if ($n['message']): ?>
                    <div class="notif-card-msg"><?= htmlspecialchars($n['message']) ?></div>
                    <?php endif; ?>
                    <div class="notif-card-meta">
                        <span class="notif-card-time"><i class="fa fa-clock" style="margin-right:4px;"></i><?= timeAgoFull($n['created_at']) ?></span>
                        <span class="notif-type-badge" style="background:<?= $bg ?>;color:<?= $color ?>"><?= ucfirst($n['notification_type']) ?></span>
                        <?php if ($isUnread): ?>
                        <span style="font-size:11px;color:#4facfe;font-weight:600;">● Unread</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($n['action_url'])): ?>
                    <div class="notif-card-action" style="margin-top:8px;">
                        <a href="<?= htmlspecialchars($n['action_url']) ?>">View details →</a>
                    </div>
                    <?php endif; ?>
                    <?php if ($canEdit): ?>
                    <div class="notif-card-actions">
                        <button class="btn-edit-notif"
                            onclick="openEditModal(<?= $n['notification_id'] ?>, <?= htmlspecialchars(json_encode($n['title'])) ?>, <?= htmlspecialchars(json_encode($n['message'] ?? '')) ?>, <?= htmlspecialchars(json_encode($n['notification_type'])) ?>)">
                            <i class="fa fa-pen"></i> Edit
                        </button>
                        <button class="btn-delete-notif"
                            onclick="deleteNotification(<?= $n['notification_id'] ?>, this)">
                            <i class="fa fa-trash"></i> Delete
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </main>
</div>

<!-- ── EDIT NOTIFICATION MODAL (admin / teacher only) ── -->
<?php if ($canEdit): ?>
<div class="modal-overlay" id="editModalOverlay" onclick="closeEditModalOnBg(event)">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="editModalTitle">
        <h2 id="editModalTitle"><i class="fa fa-pen" style="color:#4facfe;margin-right:8px;"></i>Edit Notification</h2>

        <input type="hidden" id="editNotifId">

        <div class="modal-field">
            <label for="editTitle">Title</label>
            <input type="text" id="editTitle" maxlength="255" placeholder="Notification title">
        </div>
        <div class="modal-field">
            <label for="editMessage">Message</label>
            <textarea id="editMessage" maxlength="1000" placeholder="Notification message (optional)"></textarea>
        </div>
        <div class="modal-field">
            <label for="editType">Type</label>
            <select id="editType">
                <option value="info">ℹ️ Info</option>
                <option value="success">✅ Success</option>
                <option value="warning">⚠️ Warning</option>
                <option value="error">❌ Error</option>
                <option value="assessment">📝 Assessment</option>
                <option value="result">🏆 Result</option>
                <option value="material">📚 Material</option>
            </select>
        </div>

        <div class="modal-error" id="editModalError"></div>

        <div class="modal-actions">
            <button class="btn-modal-cancel" onclick="closeEditModal()">Cancel</button>
            <button class="btn-modal-save"   onclick="saveEditModal()"><i class="fa fa-save"></i> Save Changes</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;
const CAN_EDIT   = <?= json_encode($canEdit) ?>;

// Profile dropdown
function toggleDropdown() {
    document.getElementById('profileDropdown').classList.toggle('open');
    document.getElementById('notifDropdown').classList.remove('show');
}

// Notif dropdown
function toggleNotifDropdown() {
    const dd = document.getElementById('notifDropdown');
    document.getElementById('profileDropdown').classList.remove('open');
    dd.classList.toggle('show');
}

// Close on outside click
document.addEventListener('click', e => {
    const pw = document.getElementById('profileWrapper');
    const nw = document.querySelector('.notif-dropdown-wrap');
    if (pw && !pw.contains(e.target)) document.getElementById('profileDropdown').classList.remove('open');
    if (nw && !nw.contains(e.target)) document.getElementById('notifDropdown').classList.remove('show');
});

// Mark all read
function markAllRead() {
    fetch('api/notifications/mark-read.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': CSRF_TOKEN, 'Content-Type': 'application/json' }
    }).then(r => r.json()).then(data => {
        if (data.success) {
            document.querySelectorAll('.notif-card.unread').forEach(el => el.classList.remove('unread'));
            document.querySelectorAll('[style*="● Unread"]').forEach(el => el.remove());
            const badge = document.getElementById('notifBadge');
            if (badge) badge.remove();
            document.getElementById('markAllBtn').innerHTML = '<i class="fa fa-check"></i> All read';
        }
    }).catch(() => {});
}

// Search filter
document.getElementById('searchInput').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.notif-card').forEach(card => {
        const title = card.dataset.title || '';
        const msg   = card.dataset.msg   || '';
        card.style.display = (title.includes(q) || msg.includes(q)) ? '' : 'none';
    });
});

// Live badge polling
function pollNotifications() {
    fetch('api/notifications/unread-count.php')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                let badge = document.getElementById('notifBadge');
                if (data.count > 0) {
                    if (!badge) {
                        badge = document.createElement('div');
                        badge.id = 'notifBadge';
                        badge.className = 'notif-badge';
                        document.querySelector('.notification-btn').appendChild(badge);
                    }
                    badge.textContent = data.count > 99 ? '99+' : data.count;
                } else if (badge) badge.remove();
            }
        }).catch(() => {});
}
setInterval(pollNotifications, 30000);

// ── Edit modal ──────────────────────────────────────────
function openEditModal(id, title, message, type) {
    document.getElementById('editNotifId').value  = id;
    document.getElementById('editTitle').value    = title;
    document.getElementById('editMessage').value  = message;
    document.getElementById('editType').value     = type;
    document.getElementById('editModalError').style.display = 'none';
    document.getElementById('editModalOverlay').classList.add('open');
    document.getElementById('editTitle').focus();
}

function closeEditModal() {
    document.getElementById('editModalOverlay').classList.remove('open');
}

function closeEditModalOnBg(e) {
    if (e.target === document.getElementById('editModalOverlay')) closeEditModal();
}

// Close on Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeEditModal();
});

async function saveEditModal() {
    const id      = parseInt(document.getElementById('editNotifId').value);
    const title   = document.getElementById('editTitle').value.trim();
    const message = document.getElementById('editMessage').value.trim();
    const type    = document.getElementById('editType').value;
    const errEl   = document.getElementById('editModalError');

    if (!title) {
        errEl.textContent = 'Title cannot be empty.';
        errEl.style.display = 'block';
        return;
    }

    try {
        const res  = await fetch('notifications.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': CSRF_TOKEN, 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'edit', notification_id: id, title, message, notification_type: type })
        });
        const data = await res.json();

        if (data.success) {
            // Update the card in the DOM
            const card = document.querySelector(`.notif-card[data-id="${id}"]`);
            if (card) {
                card.dataset.title = title.toLowerCase();
                card.dataset.msg   = message.toLowerCase();
                card.querySelector('.notif-card-title').textContent = title;
                const msgEl = card.querySelector('.notif-card-msg');
                if (msgEl) msgEl.textContent = message;
                else if (message) {
                    const bodyEl = card.querySelector('.notif-card-body');
                    const metaEl = card.querySelector('.notif-card-meta');
                    const p = document.createElement('div');
                    p.className = 'notif-card-msg';
                    p.textContent = message;
                    bodyEl.insertBefore(p, metaEl);
                }
                // Update type badge
                const typeIcons = {
                    info:'ℹ️', success:'✅', warning:'⚠️', error:'❌',
                    assessment:'📝', result:'🏆', material:'📚'
                };
                const badgeEl = card.querySelector('.notif-type-badge');
                if (badgeEl) badgeEl.textContent = type.charAt(0).toUpperCase() + type.slice(1);
                const iconEl = card.querySelector('.notif-icon-wrap');
                if (iconEl) iconEl.textContent = typeIcons[type] || '🔔';
            }
            closeEditModal();
            showToast('Notification updated successfully.', 'success');
        } else {
            errEl.textContent = data.error || 'Failed to update. Please try again.';
            errEl.style.display = 'block';
        }
    } catch {
        errEl.textContent = 'Network error. Please try again.';
        errEl.style.display = 'block';
    }
}

// ── Delete notification ──────────────────────────────────
async function deleteNotification(id, btn) {
    if (!confirm('Delete this notification? This cannot be undone.')) return;

    try {
        const res  = await fetch('notifications.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': CSRF_TOKEN, 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', notification_id: id })
        });
        const data = await res.json();

        if (data.success) {
            const card = btn.closest('.notif-card');
            card.style.transition = 'opacity .3s, transform .3s';
            card.style.opacity = '0';
            card.style.transform = 'translateX(30px)';
            setTimeout(() => card.remove(), 300);
            showToast('Notification deleted.', 'info');
        } else {
            showToast(data.error || 'Could not delete notification.', 'error');
        }
    } catch {
        showToast('Network error. Please try again.', 'error');
    }
}

// ── Toast helper ─────────────────────────────────────────
function showToast(msg, type = 'success') {
    const colors = { success: '#48bb78', error: '#e53e3e', info: '#4facfe' };
    const t = document.createElement('div');
    t.textContent = msg;
    Object.assign(t.style, {
        position: 'fixed', bottom: '24px', right: '24px',
        background: colors[type] || '#4facfe', color: 'white',
        padding: '12px 22px', borderRadius: '10px', fontWeight: '600',
        fontSize: '14px', boxShadow: '0 4px 20px rgba(0,0,0,0.15)',
        zIndex: '3000', opacity: '0', transition: 'opacity .3s'
    });
    document.body.appendChild(t);
    requestAnimationFrame(() => { t.style.opacity = '1'; });
    setTimeout(() => {
        t.style.opacity = '0';
        setTimeout(() => t.remove(), 400);
    }, 3000);
}
</script>
</body>
</html>

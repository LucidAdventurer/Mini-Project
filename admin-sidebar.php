<?php
/* ========================================
 * ADMIN SHARED SIDEBAR + TOPBAR INCLUDE
 * File: admin-sidebar.php
 *
 * Requires: $currentPage (string) set by the including page.
 * Values: 'overview' | 'users' | 'tests' | 'resources' | 'logs' | 'settings' | 'profile'
 * Also requires: $adminName, $adminEmail, $adminInitials (set from session/DB in each page)
 * ======================================== */

$navItems = [
    'overview'  => ['label' => 'System Overview',  'href' => 'admin-dashboard.php',  'section' => 'Main',       'svg' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>'],
    'users'     => ['label' => 'User Management',  'href' => 'admin-users.php',      'section' => 'Management', 'svg' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>'],
    'tests'         => ['label' => 'Test Management',  'href' => 'admin-tests.php',         'section' => 'Management', 'svg' => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>'],
    'notifications' => ['label' => 'Notifications',   'href' => 'admin-notifications.php', 'section' => 'Management', 'svg' => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>'],
    'resources' => ['label' => 'Resources',        'href' => 'admin-resources.php',  'section' => 'Management', 'svg' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>'],
    'logs'      => ['label' => 'System Logs',      'href' => 'admin-logs.php',       'section' => 'System',     'svg' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>'],
    'settings'  => ['label' => 'Settings',         'href' => 'admin-settings.php',   'section' => 'System',     'svg' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>'],
];

// Page titles for topbar
$pageTitles = [
    'overview'  => 'System Overview <span>/ Dashboard</span>',
    'users'     => 'User Management <span>/ Users</span>',
    'tests'     => 'Test Management <span>/ Assessments</span>',
    'notifications' => 'Notifications <span>/ Reports Inbox</span>',
    'resources' => 'Resources <span>/ Materials</span>',
    'logs'      => 'System Logs <span>/ Activity</span>',
    'settings'  => 'Settings <span>/ Configuration</span>',
    'profile'   => 'My Profile <span>/ Account</span>',
];

$topbarTitle = $pageTitles[$currentPage] ?? 'Admin Panel';

// Build dropdown items — exclude current page
$dropdownItems = [
    'overview' => ['label' => 'Dashboard',  'href' => 'admin-dashboard.php', 'svg' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>'],
    'profile'  => ['label' => 'My Profile', 'href' => 'admin-profile.php',  'svg' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>'],
];
?>
<!-- Sidebar overlay for mobile -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- ── SIDEBAR ── -->
<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="brand-logo">
            <svg viewBox="0 0 24 24" fill="none">
                <path d="M12 2L4 5.5V11c0 4.5 3.3 8.7 8 9.9 4.7-1.2 8-5.4 8-9.9V5.5L12 2z" fill="rgba(96,165,250,0.2)" stroke="#60a5fa" stroke-width="1.5"/>
                <path d="M8.5 12l2.5 2.5 5-5" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
        <div class="brand-text">PREPAURA <span>Admin Control</span></div>
    </div>

    <nav class="sidebar-nav">
        <?php
        $lastSection = null;
        foreach ($navItems as $key => $item):
            if ($item['section'] !== $lastSection):
                $lastSection = $item['section'];
        ?>
        <div class="nav-section-label"><?= htmlspecialchars($item['section']) ?></div>
        <?php endif; ?>
        <a href="<?= htmlspecialchars($item['href']) ?>"
           class="nav-item<?= $currentPage === $key ? ' active' : '' ?>">
            <svg viewBox="0 0 24 24"><?= $item['svg'] ?></svg>
            <?= htmlspecialchars($item['label']) ?>
            <?php if ($key === 'users'): ?>
                <span class="nav-badge blue" id="sidebarUserCount" style="display:none;"></span>
            <?php elseif ($key === 'tests'): ?>
                <span class="nav-badge" id="sidebarTestCount" style="display:none;"></span>
            <?php elseif ($key === 'logs'): ?>
                <span class="nav-badge" id="sidebarLogsBadge" style="display:none;">0</span>
            <?php elseif ($key === 'notifications'): ?>
                <?php
                $notifPending = 0;
                if (isset($conn)) {
                    try {
                        $nRes = $conn->query("SELECT COUNT(*) AS cnt FROM student_reports WHERE status = 'pending'");
                        if ($nRes) { $notifPending = (int)($nRes->fetch_assoc()['cnt'] ?? 0); $nRes->free(); }
                    } catch (Exception $e) { /* silently skip if table doesn't exist yet */ }
                }
                if ($notifPending > 0): ?>
                <span class="nav-badge" style="background:var(--red);"><?= $notifPending ?></span>
                <?php endif; ?>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-bottom">
        <a href="logout.php" class="nav-item" style="color:var(--red);"
           onclick="return confirm('Are you sure you want to log out?')">
            <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Log Out
        </a>
    </div>
</aside>

<!-- ── MAIN WRAPPER ── -->
<div class="main">
    <header class="topbar">
        <div class="hamburger" id="hamburger" onclick="toggleSidebar()">
            <span></span><span></span><span></span>
        </div>
        <div class="topbar-title"><?= $topbarTitle ?></div>
        <div class="topbar-actions">
            <div class="icon-btn" title="Refresh" onclick="window.location.reload()">
                <svg viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
            </div>
            <div class="profile-wrap">
                <div class="admin-chip" id="adminChip" onclick="toggleProfileDropdown(event)">
                    <div class="admin-avatar"><?= htmlspecialchars($adminInitials) ?></div>
                    <span><?= htmlspecialchars($adminName) ?></span>
                    <svg class="admin-chip-caret" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
                </div>
                <div class="profile-dropdown" id="profileDropdown">
                    <div class="dd-header">
                        <div class="dd-avatar"><?= htmlspecialchars($adminInitials) ?></div>
                        <div class="dd-name"><?= htmlspecialchars($adminName) ?></div>
                        <div class="dd-email"><?= htmlspecialchars($adminEmail) ?></div>
                        <span class="dd-role">Administrator</span>
                    </div>
                    <div class="dd-menu">
                        <?php foreach ($dropdownItems as $key => $item):
                            if ($key === $currentPage) continue; // skip current page
                        ?>
                        <a href="<?= htmlspecialchars($item['href']) ?>" class="dd-item">
                            <svg viewBox="0 0 24 24"><?= $item['svg'] ?></svg>
                            <?= htmlspecialchars($item['label']) ?>
                        </a>
                        <?php endforeach; ?>
                        <div class="dd-divider"></div>
                        <a href="logout.php" class="dd-item danger"
                           onclick="return confirm('Are you sure you want to log out?')">
                            <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                            Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </header>
    <!-- PAGE CONTENT STARTS HERE -->

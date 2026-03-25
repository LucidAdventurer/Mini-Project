<?php
/* ========================================
 * ADMIN SYSTEM LOGS
 * File: admin-logs.php
 * ======================================== */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db-guard.php';

$admin       = validateSession($conn, 'admin');
$adminName   = $admin['full_name'] ?? 'Admin';
$adminEmail  = $admin['email']     ?? '';
$adminInitials = implode('', array_map(
    fn($p) => strtoupper($p[0]),
    array_slice(explode(' ', trim($adminName)), 0, 2)
));

$currentPage = 'logs';
$pageTitle   = 'System Logs — PREPAURA Admin';
require_once __DIR__ . '/admin-head.php';
?>
<body>
<?php require_once __DIR__ . '/admin-sidebar.php'; ?>

    <div class="content">
        <div class="section-header">
            <div><h2>System Logs</h2><p>Login activity, audit trail, and security events</p></div>
            <div style="display:flex;gap:7px;">
                <button class="btn btn-ghost">
                    <svg viewBox="0 0 24 24"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                    Filter
                </button>
                <button class="btn btn-ghost">
                    <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Export
                </button>
            </div>
        </div>
        <div class="tabs">
            <button class="tab active" onclick="setLogTab(this,'login')">Login Activity</button>
        </div>
        <div class="card">
            <div class="card-header">
                <div>
                    <h3 id="logsCardTitle">Login Activity</h3>
                    <p>Recent authentication events from <code style="color:var(--accent2);font-size:10.5px;font-family:'DM Mono',monospace;">login_activity</code></p>
                </div>
                <span class="badge error" id="logsAlertBadge" style="display:none;"></span>
            </div>
            <div id="logsContainer"><div style="padding:28px;text-align:center;color:var(--muted);">Loading logs…</div></div>
        </div>
    </div>
</div><!-- /main -->

<?php require_once __DIR__ . '/admin-footer.php'; ?>
<script>
let logType='login', logPage=1;

document.addEventListener('DOMContentLoaded', async () => {
    await fetchCsrf();
    loadLogs();
});

function setLogTab(el, type) {
    document.querySelectorAll('.tabs .tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    logType = type; logPage = 1;
    PageCache.invalidate(`logs:${logType}:${logPage}`);
    loadLogs();
}

async function loadLogs() {
    const logDiv = document.getElementById('logsContainer');
    const cacheKey = `logs:${logType}:${logPage}`;
    const cached = PageCache.get(cacheKey);
    if (cached) renderLogs(cached, logDiv);
    else logDiv.innerHTML = '<div style="padding:28px;text-align:center;color:var(--muted);">Loading…</div>';
    if (!PageCache.isStale(cacheKey) && cached) return;
    setEl('logsCardTitle', {login:'Login Activity'}[logType]||'Logs');
    try {
        const d = await apiGet(API.getLogs, {type:logType, page:logPage, limit:30});
        if (!d.success) { if (!cached) logDiv.innerHTML=`<div style="padding:24px;text-align:center;color:var(--red);">${esc(d.error||'Failed to load logs.')}</div>`; return; }
        PageCache.set(cacheKey, d); renderLogs(d, logDiv);
    } catch(e) { console.error(e); }
}

function renderLogs(d, logDiv) {
    const alertBadge = document.getElementById('logsAlertBadge');
    if (alertBadge && d.alert_count) { alertBadge.textContent = d.alert_count+' alert'+(d.alert_count!==1?'s':''); alertBadge.style.display=''; }
    else if (alertBadge) alertBadge.style.display='none';
    if (!d.logs?.length) { logDiv.innerHTML='<div style="padding:24px;text-align:center;color:var(--muted);">No logs found.</div>'; return; }
    const levelBadge = {
        success:`<span class="badge success" style="font-size:10px;">SUCCESS</span>`,
        error:  `<span class="badge error"   style="font-size:10px;">FAIL</span>`,
        warning:`<span class="badge warning" style="font-size:10px;">WARN</span>`,
        info:   `<span class="badge info"    style="font-size:10px;">INFO</span>`,
    };
    logDiv.innerHTML = d.logs.map(log =>
        `<div class="log-entry"><span class="log-time">${esc(log.created_at)}</span><span class="log-level">${levelBadge[log.level]||levelBadge.info}</span><span class="log-user">${esc(log.email||log.user_name||'—')}</span><span class="log-msg">${esc(log.message)}</span><span class="log-ip">${esc(log.ip_address||'')}</span></div>`
    ).join('');

    // Update sidebar badge
    const lb = document.getElementById('sidebarLogsBadge');
    if (lb && d.alert_count > 0) { lb.textContent = d.alert_count; lb.style.display=''; lb.style.background='var(--red)'; }
    else if (lb) lb.style.display='none';
}
</script>
</body>
</html>

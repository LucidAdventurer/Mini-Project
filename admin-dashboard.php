<?php
/* ========================================
 * ADMIN DASHBOARD — OVERVIEW
 * File: admin-dashboard.php
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

$currentPage = 'overview';
$pageTitle   = 'System Overview — PREPAURA Admin';
require_once __DIR__ . '/admin-head.php';
?>
<body>
<?php require_once __DIR__ . '/admin-sidebar.php'; ?>

    <div class="content">
        <div class="section-header">
            <div><h2>System Overview</h2><p>Live platform snapshot</p></div>
            <button class="btn btn-primary">
                <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Export Report
            </button>
        </div>
        <div class="stats-grid">
            <div class="stat-card blue">
                <div class="stat-icon"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
                <div class="stat-value" id="statTotalUsers">—</div>
                <div class="stat-label">Total Users</div>
                <div class="stat-change up" id="statUserChange"></div>
            </div>
            <div class="stat-card green">
                <div class="stat-icon"><svg viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></div>
                <div class="stat-value" id="statActiveAssessments">—</div>
                <div class="stat-label">Active Assessments</div>
                <div class="stat-change up" id="statAssessmentChange"></div>
            </div>
            <div class="stat-card yellow">
                <div class="stat-icon"><svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></div>
                <div class="stat-value" id="statTotalAttempts">—</div>
                <div class="stat-label">Total Attempts</div>
                <div class="stat-change up" id="statAttemptsChange"></div>
            </div>
            <div class="stat-card red">
                <div class="stat-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
                <div class="stat-value" id="statFailedLogins">—</div>
                <div class="stat-label">Failed Logins Today</div>
                <div class="stat-change down" id="statSuspiciousIPs"></div>
            </div>
        </div>
        <div class="two-col">
            <div class="card">
                <div class="card-header"><div><h3>User Distribution</h3><p>By role and department</p></div></div>
                <div class="card-body">
                    <div class="donut-wrap" style="margin-bottom:18px;">
                        <div class="donut"></div>
                        <div class="donut-legend">
                            <div class="legend-item"><div class="legend-dot" style="background:var(--accent)"></div><span id="legendStudents">Students — —</span></div>
                            <div class="legend-item"><div class="legend-dot" style="background:var(--green)"></div><span id="legendTeachers">Teachers — —</span></div>
                            <div class="legend-item"><div class="legend-dot" style="background:var(--yellow)"></div><span id="legendUnverified">Unverified — —</span></div>
                            <div class="legend-item"><div class="legend-dot" style="background:var(--red)"></div><span id="legendInactive">Inactive — —</span></div>
                        </div>
                    </div>
                    <div class="bar-chart" id="deptBars"></div>
                </div>
            </div>
            <div class="card">
                <div class="card-header">
                    <div><h3>Recent Activity</h3><p>Latest system events</p></div>
                    <a href="admin-logs.php" class="btn btn-ghost btn-sm">View Logs</a>
                </div>
                <div class="card-body">
                    <div class="activity-list" id="activityList"></div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header">
                <div><h3>Assessment Status Summary</h3><p>All assessments across categories</p></div>
                <a href="admin-tests.php" class="btn btn-primary btn-sm">View All</a>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Title</th><th>Category</th><th>Difficulty</th><th>Duration</th><th>Attempts</th><th>Pass Rate</th><th>Status</th></tr></thead>
                    <tbody id="assessmentSummaryBody"><tr><td colspan="7" style="text-align:center;padding:28px;color:var(--muted);">Loading…</td></tr></tbody>
                </table>
            </div>
        </div>
    </div><!-- /content -->
</div><!-- /main -->

<?php require_once __DIR__ . '/admin-footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', async () => {
    await fetchCsrf();
    loadOverview();
});

async function loadOverview() {
    const cached = PageCache.get('overview');
    if (cached) renderOverview(cached);
    if (!PageCache.isStale('overview') && cached) return;
    try { const d = await apiGet(API.overview); if (!d.success) return; PageCache.set('overview', d); renderOverview(d); } catch(e) { console.error(e); }
}

function renderOverview(d) {
    const s = d.stats;
    setEl('statTotalUsers', fmtNum(s.total_users));
    setEl('statActiveAssessments', fmtNum(s.published_assessments || s.active_assessments || 0));
    setEl('statTotalAttempts', fmtNum(s.total_attempts));
    setEl('statFailedLogins', fmtNum(s.failed_logins_today));
    setEl('statSuspiciousIPs', (s.suspicious_ips_today || 0) + ' suspicious IPs');

    const dist = d.user_distribution;
    const sp = dist.student_pct, tp = dist.teacher_pct, up = dist.unverified_pct, ip = dist.inactive_pct;
    const donut = document.querySelector('.donut');
    if (donut) donut.style.background = `conic-gradient(var(--accent) 0% ${sp}%,var(--green) ${sp}% ${sp+tp}%,var(--yellow) ${sp+tp}% ${sp+tp+up}%,var(--red) ${sp+tp+up}% 100%)`;
    setEl('legendStudents', `Students — ${sp}%`);
    setEl('legendTeachers', `Teachers — ${tp}%`);
    setEl('legendUnverified', `Unverified — ${up}%`);
    setEl('legendInactive', `Inactive — ${ip}%`);

    const barChart = document.getElementById('deptBars');
    if (barChart && d.dept_breakdown?.length)
        barChart.innerHTML = d.dept_breakdown.map(dep =>
            `<div class="bar-row"><div class="bar-label">${esc(dep.department)}</div><div class="bar-track"><div class="bar-fill" style="width:${dep.pct}%"></div></div><div class="bar-val">${dep.pct}%</div></div>`
        ).join('');

    const actList = document.getElementById('activityList');
    if (actList) {
        const colorMap = {create_user:'blue',edit_user:'yellow',block_user:'red',activate_user:'green'};
        actList.innerHTML = d.recent_activity?.length
            ? d.recent_activity.map(a => `<div class="activity-item"><div class="activity-dot ${colorMap[a.action]||'blue'}"></div><div><div class="activity-text">${esc(ucwords(a.action.replace(/_/g,' ')))} by <b>${esc(a.actor)}</b></div><div class="activity-time">${a.time_ago}</div></div></div>`).join('')
            : '<div style="padding:16px;color:var(--muted);font-size:12.5px;">No recent activity.</div>';
    }

    const tbody = document.getElementById('assessmentSummaryBody');
    if (tbody) {
        tbody.innerHTML = d.assessment_summary?.length
            ? d.assessment_summary.map(a => `<tr><td>${esc(a.title)}</td><td>${esc(a.category||'—')}</td><td><span class="badge ${a.difficulty}">${esc(a.difficulty)}</span></td><td>${a.duration_minutes} min</td><td>${fmtNum(a.attempt_count)}</td><td>${a.pass_rate!==null?a.pass_rate+'%':'—'}</td><td><span class="badge ${a.status}">${esc(a.status)}</span></td></tr>`).join('')
            : '<tr><td colspan="7" style="text-align:center;padding:20px;color:var(--muted);">No assessments yet.</td></tr>';
    }

    if (d.db_counts) {
        const ub = document.getElementById('sidebarUserCount');
        if (ub) { ub.textContent = fmtNum(d.db_counts.users || 0); ub.style.display = ''; }
    }
    const tb = document.getElementById('sidebarTestCount');
    if (tb) { tb.textContent = fmtNum(s.total_assessments || 0); tb.style.display = ''; }
    const lb = document.getElementById('sidebarLogsBadge');
    if (lb) { if (s.failed_logins_today > 0) { lb.textContent = s.failed_logins_today; lb.style.display = ''; lb.style.background = 'var(--red)'; } else lb.style.display = 'none'; }
}
</script>
</body>
</html>

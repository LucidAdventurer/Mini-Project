<?php
/* ========================================
 * ADMIN SETTINGS
 * File: admin-settings.php
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

$currentPage = 'settings';
$pageTitle   = 'Settings — PREPAURA Admin';
require_once __DIR__ . '/admin-head.php';
?>
<body>
<?php require_once __DIR__ . '/admin-sidebar.php'; ?>

    <div class="content">
        <div class="section-header">
            <div><h2>Settings</h2><p>System configuration from <code style="color:var(--accent2);font-size:11.5px;font-family:'DM Mono',monospace;">system_settings</code></p></div>
            <button class="btn btn-primary" onclick="saveSettings()">Save All</button>
        </div>
        <div class="two-col">
            <div class="card">
                <div class="card-header"><h3>Security Configuration</h3><p>Login, session, and lockout settings</p></div>
                <div class="card-body">
                    <div class="settings-grid">
                        <div class="setting-group"><label>Max Login Attempts</label><input class="setting-input" type="number" value="5" data-key="max_login_attempts"/></div>
                        <div class="setting-group"><label>Lockout Duration (min)</label><input class="setting-input" type="number" value="15" data-key="lockout_duration_minutes"/></div>
                        <div class="setting-group"><label>Session Timeout (min)</label><input class="setting-input" type="number" value="60" data-key="session_timeout_minutes"/></div>
                        <div class="setting-group"><label>Assessment Duration (min)</label><input class="setting-input" type="number" value="60" data-key="default_assessment_duration"/></div>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h3>System Features</h3><p>Toggle system-wide features</p></div>
                <div class="card-body">
                    <div class="toggle-row"><div class="toggle-info"><h4>Maintenance Mode</h4><p>Block all non-admin logins</p></div><label class="toggle"><input type="checkbox" data-key="maintenance_mode"><span class="toggle-slider"></span></label></div>
                    <div class="toggle-row"><div class="toggle-info"><h4>Allow Guest Attempts</h4><p>Public access without login</p></div><label class="toggle"><input type="checkbox" checked data-key="allow_guest_attempts"><span class="toggle-slider"></span></label></div>
                    <div class="toggle-row"><div class="toggle-info"><h4>Email Verification</h4><p>Required before login</p></div><label class="toggle"><input type="checkbox" checked data-key="email_verification_required"><span class="toggle-slider"></span></label></div>
                    <div class="toggle-row"><div class="toggle-info"><h4>Email Notifications</h4><p>System emails to users</p></div><label class="toggle"><input type="checkbox" checked data-key="email_notifications_enabled"><span class="toggle-slider"></span></label></div>
                    <div class="toggle-row"><div class="toggle-info"><h4>Demo Mode</h4><p>Disable destructive operations</p></div><label class="toggle"><input type="checkbox" data-key="demo_mode"><span class="toggle-slider"></span></label></div>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h3>Storage &amp; Retention</h3></div>
                <div class="card-body">
                    <div class="settings-grid">
                        <div class="setting-group"><label>Max Upload Size (MB)</label><input class="setting-input" type="number" value="25" data-key="max_upload_size_mb"/></div>
                        <div class="setting-group"><label>Log Retention (days)</label><input class="setting-input" type="number" value="90" data-key="log_retention_days"/></div>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h3>Database Overview</h3><p>Live record counts from all active tables</p></div>
                <div class="card-body">
                    <div class="bar-chart">
                        <div class="bar-row"><div class="bar-label">users</div><div class="bar-track"><div class="bar-fill" id="dbUsersBar" style="width:0%"></div></div><div class="bar-val" id="dbUsers">—</div></div>
                        <div class="bar-row"><div class="bar-label">assessments</div><div class="bar-track"><div class="bar-fill" id="dbAssessmentsBar" style="width:0%"></div></div><div class="bar-val" id="dbAssessments">—</div></div>
                        <div class="bar-row"><div class="bar-label">questions</div><div class="bar-track"><div class="bar-fill" id="dbQuestionsBar" style="width:0%"></div></div><div class="bar-val" id="dbQuestions">—</div></div>
                        <div class="bar-row"><div class="bar-label">question_options</div><div class="bar-track"><div class="bar-fill" id="dbOptionsBar" style="width:0%"></div></div><div class="bar-val" id="dbOptions">—</div></div>
                        <div class="bar-row"><div class="bar-label">attempts</div><div class="bar-track"><div class="bar-fill green" id="dbAttemptsBar" style="width:0%"></div></div><div class="bar-val" id="dbAttempts">—</div></div>
                        <div class="bar-row"><div class="bar-label">answers</div><div class="bar-track"><div class="bar-fill green" id="dbAnswersBar" style="width:0%"></div></div><div class="bar-val" id="dbAnswers">—</div></div>
                        <div class="bar-row"><div class="bar-label">groups</div><div class="bar-track"><div class="bar-fill yellow" id="dbGroupsBar" style="width:0%"></div></div><div class="bar-val" id="dbGroups">—</div></div>
                        <div class="bar-row"><div class="bar-label">group_members</div><div class="bar-track"><div class="bar-fill yellow" id="dbGroupMembersBar" style="width:0%"></div></div><div class="bar-val" id="dbGroupMembers">—</div></div>
                        <div class="bar-row"><div class="bar-label">materials</div><div class="bar-track"><div class="bar-fill" id="dbMaterialsBar" style="width:0%"></div></div><div class="bar-val" id="dbMaterials">—</div></div>
                        <div class="bar-row"><div class="bar-label">resources</div><div class="bar-track"><div class="bar-fill" id="dbResourcesBar" style="width:0%"></div></div><div class="bar-val" id="dbResources">—</div></div>
                        <div class="bar-row"><div class="bar-label">notifications</div><div class="bar-track"><div class="bar-fill yellow" id="dbNotificationsBar" style="width:0%"></div></div><div class="bar-val" id="dbNotifications">—</div></div>
                        <div class="bar-row"><div class="bar-label">login_activity</div><div class="bar-track"><div class="bar-fill" id="dbLoginActivityBar" style="width:0%"></div></div><div class="bar-val" id="dbLoginActivity">—</div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div><!-- /main -->

<?php require_once __DIR__ . '/admin-footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', async () => {
    await fetchCsrf();
    loadSettings();
});

async function loadSettings() {
    const cached = PageCache.get('settings');
    if (cached) renderSettings(cached);
    if (!PageCache.isStale('settings') && cached) return;
    try {
        const d = await apiGet(API.getSettings);
        if (!d.success) return;
        PageCache.set('settings', d); renderSettings(d);
    } catch(e) { console.error(e); }
}

function renderSettings(d) {
    const s = {};
    Object.values(d.settings).forEach(group => { group.forEach(item => { s[item.key] = item.value; }); });
    document.querySelectorAll('input[data-key]').forEach(inp => {
        const key = inp.dataset.key;
        if (s[key] === undefined) return;
        inp.type === 'checkbox' ? inp.checked = !!s[key] : inp.value = s[key];
    });
    if (d.db_counts) populateDbOverview(d.db_counts);
}

function populateDbOverview(counts) {
    const rows = [
        { label:'dbUsers',        bar:'dbUsersBar',        val: counts.users },
        { label:'dbAssessments',  bar:'dbAssessmentsBar',  val: counts.assessments },
        { label:'dbQuestions',    bar:'dbQuestionsBar',    val: counts.questions },
        { label:'dbOptions',      bar:'dbOptionsBar',      val: counts.question_options },
        { label:'dbAttempts',     bar:'dbAttemptsBar',     val: counts.assessment_attempts },
        { label:'dbAnswers',      bar:'dbAnswersBar',      val: counts.answers },
        { label:'dbGroups',       bar:'dbGroupsBar',       val: counts.groups },
        { label:'dbGroupMembers', bar:'dbGroupMembersBar', val: counts.group_members },
        { label:'dbMaterials',    bar:'dbMaterialsBar',    val: counts.materials },
        { label:'dbResources',    bar:'dbResourcesBar',    val: counts.resources },
        { label:'dbNotifications',bar:'dbNotificationsBar',val: counts.notifications },
        { label:'dbLoginActivity',bar:'dbLoginActivityBar',val: counts.login_activity },
    ];
    const maxVal = Math.max(1, ...rows.map(r => r.val || 0));
    rows.forEach(({ label, bar, val }) => {
        const valEl = document.getElementById(label);
        if (valEl) valEl.textContent = (val !== undefined && val !== null) ? fmtNum(val) : '—';
        const barEl = document.getElementById(bar);
        if (barEl) barEl.style.width = Math.round(((val || 0) / maxVal) * 100) + '%';
    });
}

async function saveSettings() {
    const payload = {};
    document.querySelectorAll('input[data-key]').forEach(inp => {
        const key = inp.dataset.key;
        payload[key] = inp.type==='checkbox' ? inp.checked : inp.type==='number' ? parseFloat(inp.value) : inp.value;
    });
    try {
        const d = await apiPost(API.updateSettings, {settings:payload});
        if (d.success) { PageCache.invalidate('settings'); showToast(`Settings saved (${d.updated??Object.keys(payload).length} updated).`,'success'); }
        else { const errKeys = Object.keys(d.errors||{}); showToast(errKeys.length ? 'Some settings failed: '+errKeys.join(', ') : (d.error||'Save failed.'),'error'); }
    } catch(e) { showToast('Network error.','error'); }
}
</script>
</body>
</html>
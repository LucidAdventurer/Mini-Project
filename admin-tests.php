<?php
/* ========================================
 * ADMIN TEST MANAGEMENT
 * File: admin-tests.php
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

$currentPage = 'tests';
$pageTitle   = 'Test Management — PREPAURA Admin';
require_once __DIR__ . '/admin-head.php';
?>
<body>
<?php require_once __DIR__ . '/admin-sidebar.php'; ?>

    <div class="content">
        <div class="section-header">
            <div><h2>Test Management</h2><p>View, publish, archive, and delete assessments</p></div>
            <button class="btn btn-ghost" onclick="exportTests()">
                <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Export
            </button>
        </div>
        <div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:18px;">
            <div class="stat-card blue"><div class="stat-icon"><svg viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></div><div class="stat-value" id="tStatTotal">—</div><div class="stat-label">Total Assessments</div></div>
            <div class="stat-card green"><div class="stat-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div><div class="stat-value" id="tStatActive">—</div><div class="stat-label">Active / Live</div></div>
            <div class="stat-card yellow"><div class="stat-icon"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div><div class="stat-value" id="tStatDraft">—</div><div class="stat-label">Drafts</div></div>
            <div class="stat-card red"><div class="stat-icon"><svg viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg></div><div class="stat-value" id="tStatPassRate">—</div><div class="stat-label">Avg Pass Rate</div></div>
        </div>
        <div class="tabs">
            <button class="tab active" onclick="filterByStatus('all',this)">All</button>
            <button class="tab" onclick="filterByStatus('active',this)">Published</button>
            <button class="tab" onclick="filterByStatus('draft',this)">Draft</button>
            <button class="tab" onclick="filterByStatus('archived',this)">Archived</button>
        </div>
        <div style="display:flex;gap:9px;margin-bottom:14px;align-items:center;">
            <div class="search-bar" style="flex:1;margin-bottom:0;">
                <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input id="testSearchInput" placeholder="Search by title, category, or teacher…"/>
            </div>
            <select id="testCategoryFilter" class="form-input" style="width:170px;" onchange="testCategory=this.value;testPage=1;loadTests()">
                <option value="">All Categories</option>
                <option value="aptitude">Aptitude</option>
                <option value="technical">Technical</option>
                <option value="coding">Coding</option>
                <option value="reasoning">Reasoning</option>
                <option value="english">English</option>
                <option value="general">General</option>
            </select>
        </div>
        <div class="card">
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Title</th><th>Category</th><th>Difficulty</th><th>Duration</th><th>Marks</th><th>Questions</th><th>Attempts</th><th>Pass Rate</th><th>Created By</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody id="testsTableBody"><tr><td colspan="11" style="text-align:center;padding:28px;color:var(--muted);">Loading assessments…</td></tr></tbody>
                </table>
            </div>
            <div id="testPagination"></div>
        </div>
    </div>
</div><!-- /main -->

<!-- Questions Modal -->
<div class="modal-overlay" id="modalQuestions">
    <div class="modal" style="max-width:760px;width:95%;">
        <button class="modal-close" onclick="closeModal('modalQuestions')">✕</button>
        <h3 id="modalQTitle">Assessment Questions</h3>
        <p id="modalQMeta" style="font-size:11.5px;color:var(--muted);margin-bottom:14px;"></p>
        <div id="modalQBody" style="max-height:500px;overflow-y:auto;display:flex;flex-direction:column;gap:12px;"></div>
        <div class="form-actions" style="margin-top:14px;"><button class="btn btn-ghost" onclick="closeModal('modalQuestions')">Close</button></div>
    </div>
</div>

<?php require_once __DIR__ . '/admin-footer.php'; ?>
<script>
let testPage=1, testStatus='all', testSearch='', testCategory='', testSearchTimer=null;

document.addEventListener('DOMContentLoaded', async () => {
    await fetchCsrf();
    loadTests();
    document.getElementById('testSearchInput').addEventListener('input', e => {
        clearTimeout(testSearchTimer);
        testSearchTimer = setTimeout(() => { testSearch = e.target.value.trim(); testPage = 1; loadTests(); }, 350);
    });
});

function filterByStatus(status, tabEl) {
    document.querySelectorAll('.tabs .tab').forEach(t => t.classList.remove('active'));
    if (tabEl) tabEl.classList.add('active');
    testStatus = status; testPage = 1; loadTests();
}

async function loadTests() {
    const tbody = document.getElementById('testsTableBody');
    const cacheKey = `tests:${testStatus}:${testSearch}:${testCategory}:${testPage}`;
    const cached = PageCache.get(cacheKey);
    if (cached) renderTests(cached, tbody);
    else tbody.innerHTML = `<tr><td colspan="11" style="text-align:center;padding:28px;color:var(--muted);">Loading…</td></tr>`;
    if (!PageCache.isStale(cacheKey) && cached) return;
    try {
        const d = await apiGet(API.getTests, {status:testStatus,search:testSearch,category:testCategory,page:testPage,limit:20});
        if (!d.success) { if (!cached) tbody.innerHTML = `<tr><td colspan="11" style="text-align:center;color:var(--red);padding:24px;">${esc(d.error||'Failed to load')}</td></tr>`; return; }
        PageCache.set(cacheKey, d); renderTests(d, tbody);
    } catch(e) { if (!cached) tbody.innerHTML = `<tr><td colspan="11" style="text-align:center;color:var(--red);padding:24px;">Network error.</td></tr>`; }
}

function renderTests(d, tbody) {
    if (d.stats) { setEl('tStatTotal',fmtNum(d.stats.total_assessments)); setEl('tStatActive',fmtNum(d.stats.active)); setEl('tStatDraft',fmtNum(d.stats.draft)); setEl('tStatPassRate',(d.stats.avg_pass_rate||0)+'%'); }
    const tabs = document.querySelectorAll('.tabs .tab');
    const sKeys = ['all','active','draft','archived']; const sLabel = ['All','Active','Draft','Archived'];
    tabs.forEach((tab, i) => { const cnt = d.counts[sKeys[i]]; tab.textContent = sLabel[i] + (cnt ? ` (${fmtNum(cnt)})` : ''); });
    tbody.innerHTML = d.assessments.length
        ? d.assessments.map(t => renderTestRow(t)).join('')
        : `<tr><td colspan="11" style="text-align:center;padding:28px;color:var(--muted);">No assessments found.</td></tr>`;
    renderTestPagination(d.page, d.pages, d.total);
}

function renderTestRow(t) {
    const diffBadge = `<span class="badge ${t.difficulty}">${esc(t.difficulty)}</span>`;
    const statusLabel = {active:'Published',draft:'Draft',archived:'Archived'}[t.status]||t.status;
    const statusBadge = `<span class="badge ${t.status}">${esc(statusLabel)}</span>`;
    let actions = `<button class="btn btn-ghost btn-sm" onclick="viewQuestions(${t.assessment_id})">Questions</button>`;
    if (t.status==='draft')    actions += ` <button class="btn btn-primary btn-sm" onclick="changeTestStatus(${t.assessment_id},'active')">Publish</button><button class="btn btn-danger btn-sm" onclick="deleteTest(${t.assessment_id},'${esc(t.title)}')">Delete</button>`;
    else if (t.status==='active')   actions += ` <button class="btn btn-ghost btn-sm" onclick="changeTestStatus(${t.assessment_id},'draft')">Unpublish</button><button class="btn btn-danger btn-sm" onclick="changeTestStatus(${t.assessment_id},'archived')">Archive</button>`;
    else if (t.status==='archived') actions += ` <button class="btn btn-primary btn-sm" onclick="changeTestStatus(${t.assessment_id},'active')">Restore</button><button class="btn btn-danger btn-sm" onclick="deleteTest(${t.assessment_id},'${esc(t.title)}')">Delete</button>`;
    return `<tr><td><div><b>${esc(t.title)}</b></div><div style="font-size:10.5px;color:var(--muted);">${fmtNum(t.student_count)} students · max ${t.max_attempts} attempt${t.max_attempts!==1?'s':''}</div></td><td>${esc(t.category||'—')}</td><td>${diffBadge}</td><td>${t.duration_minutes} min</td><td>${fmtNum(t.total_marks)} / ${fmtNum(t.passing_marks)}</td><td>${fmtNum(t.question_count)}</td><td>${fmtNum(t.attempt_count)}</td><td>${t.pass_rate!==null?t.pass_rate+'%':'—'}</td><td><span style="font-size:11.5px;">${esc(t.creator_name)}</span></td><td>${statusBadge}</td><td><div style="display:flex;gap:4px;flex-wrap:wrap;">${actions}</div></td></tr>`;
}

async function changeTestStatus(assessmentId, newStatus) {
    const label = {active:'Publish',draft:'Unpublish',archived:'Archive'}[newStatus]||newStatus;
    if (!confirm(`${label} this assessment?`)) return;
    try {
        const d = await apiPost(API.updateTestStatus, {assessment_id:assessmentId, status:newStatus});
        if (d.success) { showToast(`Assessment ${label.toLowerCase()}d.`,'success'); PageCache.invalidate(`tests:${testStatus}:${testSearch}:${testCategory}:${testPage}`); loadTests(); }
        else showToast(d.error||'Action failed.','error');
    } catch(e) { showToast('Network error.','error'); }
}

async function deleteTest(assessmentId, title) {
    if (!confirm(`Permanently delete "${title}"?\n\nAll questions and attempt records will be deleted. This cannot be undone.`)) return;
    try {
        const d = await apiPost(API.deleteTest, {assessment_id:assessmentId});
        if (d.success) { showToast('Assessment deleted.','success'); PageCache.invalidate(`tests:${testStatus}:${testSearch}:${testCategory}:${testPage}`); loadTests(); }
        else showToast(d.error||'Delete failed.','error');
    } catch(e) { showToast('Network error.','error'); }
}

async function viewQuestions(assessmentId) {
    document.getElementById('modalQTitle').textContent='Loading…';
    document.getElementById('modalQMeta').textContent='';
    document.getElementById('modalQBody').innerHTML='<div style="text-align:center;padding:28px;color:var(--muted);">Loading questions…</div>';
    openModal('modalQuestions');
    try {
        const d = await apiGet(API.getTestQuestions, {assessment_id:assessmentId});
        if (!d.success) { document.getElementById('modalQBody').innerHTML=`<div style="color:var(--red);padding:14px;">${esc(d.error)}</div>`; return; }
        const a = d.assessment;
        document.getElementById('modalQTitle').textContent = a.title;
        document.getElementById('modalQMeta').textContent = `${a.category||''} · ${a.difficulty} · ${a.total_marks} marks · ${a.duration_minutes} min · by ${a.creator_name} · ${d.questions.length} question${d.questions.length!==1?'s':''}`;
        if (!d.questions.length) { document.getElementById('modalQBody').innerHTML='<div style="text-align:center;padding:28px;color:var(--muted);">No questions added yet.</div>'; return; }
        document.getElementById('modalQBody').innerHTML = d.questions.map((q,i) => {
            const optLabels = ['A','B','C','D'];
            const opts = [q.option_a,q.option_b,q.option_c,q.option_d].map((o,j)=>o?`<div style="padding:3px 8px;border-radius:4px;margin-top:3px;font-size:12.5px;${q.correct_answer?.toUpperCase().includes(optLabels[j])?'background:rgba(34,197,94,.12);color:var(--green);font-weight:600;':''}"><b>${optLabels[j]}.</b> ${esc(o)}</div>`:'').join('');
            return `<div style="border:1px solid var(--line);border-radius:8px;padding:12px;"><div style="display:flex;justify-content:space-between;align-items:flex-start;gap:9px;"><div style="font-size:12.5px;font-weight:600;">${i+1}. ${esc(q.question_text)}</div><div style="display:flex;gap:5px;flex-shrink:0;"><span class="badge info" style="font-size:10px;">${esc(q.question_type?.replace(/_/g,' '))}</span><span class="badge ${q.difficulty||'medium'}" style="font-size:10px;">${esc(q.difficulty||'medium')}</span><span style="font-size:10.5px;color:var(--muted);">${q.marks} mark${q.marks!==1?'s':''}</span></div></div>${opts?`<div style="margin-top:7px;">${opts}</div>`:''}${q.explanation?`<div style="margin-top:7px;font-size:11.5px;color:var(--muted);border-top:1px solid var(--line);padding-top:5px;"><b>Explanation:</b> ${esc(q.explanation)}</div>`:''}</div>`;
        }).join('');
    } catch(e) { document.getElementById('modalQBody').innerHTML='<div style="color:var(--red);padding:14px;">Network error loading questions.</div>'; }
}

async function exportTests() {
    try {
        const d = await apiGet(API.getTests,{status:testStatus,search:testSearch,category:testCategory,limit:1000,page:1});
        if (!d.success||!d.assessments.length) return showToast('No assessments to export.','error');
        const header = ['ID','Title','Category','Difficulty','Status','Duration(min)','Total Marks','Pass Marks','Questions','Attempts','Pass Rate(%)','Created By'];
        const rows = d.assessments.map(t=>[t.assessment_id,t.title,t.category,t.difficulty,t.status,t.duration_minutes,t.total_marks,t.passing_marks,t.question_count,t.attempt_count,t.pass_rate??'',t.creator_name]);
        const csv = [header,...rows].map(r=>r.map(c=>`"${String(c??'').replace(/"/g,'""')}"`).join(',')).join('\n');
        const a=document.createElement('a'); a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));
        a.download=`assessments_${new Date().toISOString().slice(0,10)}.csv`; a.click();
        showToast('Export ready.','success');
    } catch(e) { showToast('Export failed.','error'); }
}

function renderTestPagination(page, pages, total) {
    renderPagination('testPagination', page, pages, total, 'assessments', p => { testPage = p; loadTests(); });
}
</script>
</body>
</html>

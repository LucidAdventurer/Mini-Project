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
            <div style="display:flex;gap:8px;">
                <button class="btn btn-primary" onclick="openModal('modalUploadTest')">
                    <svg viewBox="0 0 24 24" style="width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    Upload Test
                </button>
                <button class="btn btn-ghost" onclick="exportTests()">
                    <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Export
                </button>
            </div>
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


<!-- ===================== UPLOAD TEST MODAL ===================== -->
<div class="modal-overlay" id="modalUploadTest">
    <div class="modal" style="max-width:680px;width:95%;">
        <button class="modal-close" onclick="closeUploadModal()">✕</button>
        <h3>Upload Test from JSON</h3>
        <p style="font-size:12px;color:var(--muted);margin-bottom:16px;">
            Upload a structured JSON file to create an assessment with questions and options.
            <a href="#" onclick="downloadTemplate();return false;" style="color:var(--blue);text-decoration:none;margin-left:4px;">Download template ↓</a>
        </p>

        <!-- Step 1: Drop zone -->
        <div id="uploadStep1">
            <div id="uploadDropZone" style="border:2px dashed var(--line);border-radius:10px;padding:40px 20px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s;"
                 onclick="document.getElementById('uploadFileInput').click()"
                 ondragover="handleDragOver(event)" ondragleave="handleDragLeave(event)" ondrop="handleDrop(event)">
                <svg viewBox="0 0 24 24" style="width:40px;height:40px;stroke:var(--muted);fill:none;stroke-width:1.5;margin-bottom:10px;stroke-linecap:round;stroke-linejoin:round;">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
                <div style="font-size:14px;font-weight:600;color:var(--text);margin-bottom:4px;">Drop your JSON file here</div>
                <div style="font-size:12px;color:var(--muted);">or click to browse — .json files only</div>
            </div>
            <input type="file" id="uploadFileInput" accept=".json" style="display:none;" onchange="handleFileSelect(this.files[0])">
            <div id="uploadFileInfo" style="display:none;margin-top:10px;padding:10px 14px;background:var(--card-alt,rgba(255,255,255,.04));border-radius:7px;display:none;align-items:center;gap:10px;">
                <svg viewBox="0 0 24 24" style="width:18px;height:18px;stroke:var(--green);fill:none;stroke-width:2;flex-shrink:0;stroke-linecap:round;stroke-linejoin:round;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <span id="uploadFileName" style="font-size:13px;font-weight:500;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
                <span id="uploadFileSize" style="font-size:11px;color:var(--muted);"></span>
                <button class="btn btn-ghost btn-sm" onclick="clearUpload()">✕</button>
            </div>
            <div id="uploadParseError" style="display:none;margin-top:10px;padding:10px 14px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:7px;font-size:12.5px;color:var(--red);"></div>
            <div class="form-actions" style="margin-top:16px;">
                <button class="btn btn-ghost" onclick="closeUploadModal()">Cancel</button>
                <button class="btn btn-primary" id="uploadPreviewBtn" disabled onclick="showUploadPreview()">Preview & Import</button>
            </div>
        </div>

        <!-- Step 2: Preview -->
        <div id="uploadStep2" style="display:none;">
            <div id="uploadPreviewMeta" style="background:var(--card-alt,rgba(255,255,255,.04));border-radius:8px;padding:14px;margin-bottom:14px;font-size:13px;display:grid;grid-template-columns:1fr 1fr;gap:6px 20px;"></div>
            <div style="font-size:12.5px;font-weight:600;color:var(--muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.05em;">Questions Preview</div>
            <div id="uploadPreviewQuestions" style="max-height:320px;overflow-y:auto;display:flex;flex-direction:column;gap:8px;"></div>
            <div id="uploadValidationWarnings" style="margin-top:10px;"></div>
            <div class="form-actions" style="margin-top:16px;">
                <button class="btn btn-ghost" onclick="backToUpload()">← Back</button>
                <button class="btn btn-primary" id="uploadSubmitBtn" onclick="submitUploadedTest()">
                    <span id="uploadSubmitLabel">Import Assessment</span>
                </button>
            </div>
        </div>
    </div>
</div>
<!-- ============================================================= -->

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

/* ==================== UPLOAD TEST ==================== */
let uploadParsedData = null;

const UPLOAD_TEMPLATE = {
    title: "Sample Assessment Title",
    description: "Optional description of this assessment.",
    category: "aptitude",
    difficulty: "medium",
    duration_minutes: 30,
    total_marks: 50,
    passing_marks: 25,
    max_attempts: 1,
    visibility: "public",
    randomize_questions: false,
    randomize_options: false,
    questions: [
        {
            question_text: "What is 2 + 2?",
            question_type: "mcq",
            marks: 2,
            negative_marks: 0,
            explanation: "Basic arithmetic.",
            options: [
                { option_text: "3", is_correct: false },
                { option_text: "4", is_correct: true },
                { option_text: "5", is_correct: false },
                { option_text: "6", is_correct: false }
            ]
        },
        {
            question_text: "The sky is blue.",
            question_type: "true_false",
            marks: 1,
            negative_marks: 0,
            explanation: "",
            options: [
                { option_text: "True",  is_correct: true  },
                { option_text: "False", is_correct: false }
            ]
        }
    ]
};

function downloadTemplate() {
    const blob = new Blob([JSON.stringify(UPLOAD_TEMPLATE, null, 2)], { type: 'application/json' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'test_template.json';
    a.click();
    showToast('Template downloaded.', 'success');
}

function closeUploadModal() {
    closeModal('modalUploadTest');
    setTimeout(() => { clearUpload(); backToUpload(); }, 300);
}

function clearUpload() {
    uploadParsedData = null;
    document.getElementById('uploadFileInput').value = '';
    document.getElementById('uploadFileInfo').style.display = 'none';
    document.getElementById('uploadParseError').style.display = 'none';
    document.getElementById('uploadPreviewBtn').disabled = true;
    document.getElementById('uploadDropZone').style.borderColor = 'var(--line)';
    document.getElementById('uploadDropZone').style.background = '';
}

function backToUpload() {
    document.getElementById('uploadStep1').style.display = '';
    document.getElementById('uploadStep2').style.display = 'none';
}

function handleDragOver(e) {
    e.preventDefault();
    document.getElementById('uploadDropZone').style.borderColor = 'var(--blue)';
    document.getElementById('uploadDropZone').style.background = 'rgba(59,130,246,.06)';
}

function handleDragLeave(e) {
    document.getElementById('uploadDropZone').style.borderColor = 'var(--line)';
    document.getElementById('uploadDropZone').style.background = '';
}

function handleDrop(e) {
    e.preventDefault();
    handleDragLeave(e);
    const file = e.dataTransfer.files[0];
    if (file) handleFileSelect(file);
}

function handleFileSelect(file) {
    const errEl = document.getElementById('uploadParseError');
    errEl.style.display = 'none';
    if (!file) return;
    if (!file.name.endsWith('.json')) {
        errEl.textContent = 'Only .json files are accepted.';
        errEl.style.display = '';
        return;
    }
    const kb = (file.size / 1024).toFixed(1);
    document.getElementById('uploadFileName').textContent = file.name;
    document.getElementById('uploadFileSize').textContent = kb + ' KB';
    document.getElementById('uploadFileInfo').style.display = 'flex';

    const reader = new FileReader();
    reader.onload = e => {
        try {
            const parsed = JSON.parse(e.target.result);
            const err = validateUploadJSON(parsed);
            if (err) {
                errEl.textContent = '⚠ ' + err;
                errEl.style.display = '';
                document.getElementById('uploadPreviewBtn').disabled = true;
                uploadParsedData = null;
            } else {
                uploadParsedData = parsed;
                document.getElementById('uploadPreviewBtn').disabled = false;
            }
        } catch (ex) {
            errEl.textContent = 'Invalid JSON: ' + ex.message;
            errEl.style.display = '';
            document.getElementById('uploadPreviewBtn').disabled = true;
            uploadParsedData = null;
        }
    };
    reader.readAsText(file);
}

function validateUploadJSON(d) {
    if (!d.title || typeof d.title !== 'string' || !d.title.trim()) return 'Missing required field: title';
    if (!Number.isInteger(d.duration_minutes) || d.duration_minutes < 1) return 'duration_minutes must be a positive integer';
    if (!Number.isInteger(d.total_marks) || d.total_marks < 1) return 'total_marks must be a positive integer';
    if (!Number.isInteger(d.passing_marks) || d.passing_marks < 0) return 'passing_marks must be a non-negative integer';
    if (d.passing_marks > d.total_marks) return 'passing_marks cannot exceed total_marks';
    const validDiff = ['easy','medium','hard'];
    if (d.difficulty && !validDiff.includes(d.difficulty)) return `difficulty must be one of: ${validDiff.join(', ')}`;
    const validVis = ['public','group','private'];
    if (d.visibility && !validVis.includes(d.visibility)) return `visibility must be one of: ${validVis.join(', ')}`;
    const validTypes = ['mcq','multiple_select','true_false','short_answer'];
    if (!Array.isArray(d.questions) || d.questions.length === 0) return 'questions array is empty or missing';
    for (let i = 0; i < d.questions.length; i++) {
        const q = d.questions[i];
        if (!q.question_text || !q.question_text.trim()) return `Question ${i+1}: question_text is required`;
        if (!validTypes.includes(q.question_type)) return `Question ${i+1}: question_type must be one of: ${validTypes.join(', ')}`;
        if (!Array.isArray(q.options) || q.options.length < 2) return `Question ${i+1}: must have at least 2 options`;
        const hasCorrect = q.options.some(o => o.is_correct === true);
        if (!hasCorrect && q.question_type !== 'short_answer') return `Question ${i+1}: no correct option marked`;
        for (let j = 0; j < q.options.length; j++) {
            if (!q.options[j].option_text || !String(q.options[j].option_text).trim()) return `Question ${i+1}, Option ${j+1}: option_text is required`;
        }
    }
    return null;
}

function showUploadPreview() {
    if (!uploadParsedData) return;
    const d = uploadParsedData;

    // Build meta grid
    const metaFields = [
        ['Title', d.title],
        ['Category', d.category || '—'],
        ['Difficulty', d.difficulty || 'medium'],
        ['Duration', (d.duration_minutes || '?') + ' min'],
        ['Marks', `${d.total_marks} total / ${d.passing_marks} passing`],
        ['Max Attempts', d.max_attempts || 1],
        ['Visibility', d.visibility || 'public'],
        ['Questions', d.questions.length],
    ];
    document.getElementById('uploadPreviewMeta').innerHTML = metaFields.map(([k,v]) =>
        `<div><span style="color:var(--muted);font-size:11px;">${k}</span><br><b style="font-size:13px;">${esc(String(v))}</b></div>`
    ).join('');

    // Warnings
    const warnings = [];
    const marksSum = d.questions.reduce((s,q) => s + (q.marks||1), 0);
    if (marksSum !== d.total_marks) warnings.push(`⚠ Sum of question marks (${marksSum}) does not match total_marks (${d.total_marks}). The server will use the JSON's total_marks value.`);
    document.getElementById('uploadValidationWarnings').innerHTML = warnings.length
        ? warnings.map(w => `<div style="padding:8px 12px;background:rgba(234,179,8,.08);border:1px solid rgba(234,179,8,.25);border-radius:6px;font-size:12px;color:var(--yellow,#eab308);margin-bottom:6px;">${w}</div>`).join('')
        : '';

    // Questions preview
    document.getElementById('uploadPreviewQuestions').innerHTML = d.questions.map((q,i) => {
        const opts = (q.options||[]).map((o,j) =>
            `<div style="font-size:12px;padding:3px 8px;border-radius:4px;margin-top:3px;${o.is_correct?'background:rgba(34,197,94,.12);color:var(--green);font-weight:600;':''}">
                <b>${String.fromCharCode(65+j)}.</b> ${esc(String(o.option_text))}${o.is_correct?' ✓':''}
            </div>`
        ).join('');
        return `<div style="border:1px solid var(--line);border-radius:8px;padding:11px 13px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
                <div style="font-size:13px;font-weight:600;">${i+1}. ${esc(q.question_text)}</div>
                <div style="display:flex;gap:5px;flex-shrink:0;">
                    <span class="badge info" style="font-size:10px;">${esc((q.question_type||'mcq').replace(/_/g,' '))}</span>
                    <span style="font-size:10.5px;color:var(--muted);white-space:nowrap;">${q.marks||1} mark${(q.marks||1)!==1?'s':''}</span>
                </div>
            </div>
            ${opts ? `<div style="margin-top:6px;">${opts}</div>` : ''}
            ${q.explanation ? `<div style="margin-top:6px;font-size:11px;color:var(--muted);border-top:1px solid var(--line);padding-top:5px;"><b>Explanation:</b> ${esc(q.explanation)}</div>` : ''}
        </div>`;
    }).join('');

    document.getElementById('uploadStep1').style.display = 'none';
    document.getElementById('uploadStep2').style.display = '';
}

async function submitUploadedTest() {
    if (!uploadParsedData) return;
    const btn = document.getElementById('uploadSubmitBtn');
    const label = document.getElementById('uploadSubmitLabel');
    btn.disabled = true;
    label.textContent = 'Importing…';
    try {
        const d = await apiPost(API.uploadTest || 'api-upload-test.php', { test: uploadParsedData });
        if (d.success) {
            showToast(`Assessment "${uploadParsedData.title}" imported successfully!`, 'success');
            closeUploadModal();
            PageCache.invalidate(`tests:${testStatus}:${testSearch}:${testCategory}:${testPage}`);
            loadTests();
        } else {
            showToast(d.error || 'Import failed.', 'error');
            btn.disabled = false;
            label.textContent = 'Import Assessment';
        }
    } catch(e) {
        showToast('Network error during import.', 'error');
        btn.disabled = false;
        label.textContent = 'Import Assessment';
    }
}
/* ===================================================== */
</script>
</body>
</html>
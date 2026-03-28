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
                <button class="btn btn-primary" onclick="openCreateModal()">
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


<!-- ===================== CREATE / UPLOAD TEST MODAL ===================== -->
<style>
#modalCreateTest .modal { max-width:780px;width:95%;max-height:92vh;display:flex;flex-direction:column; }
.ct-stepper { display:flex;align-items:center;gap:0;margin-bottom:24px;position:relative; }
.ct-stepper::before { content:'';position:absolute;top:17px;left:18px;right:18px;height:2px;background:var(--line);z-index:0; }
.ct-step { display:flex;flex-direction:column;align-items:center;gap:5px;flex:1;position:relative;z-index:1; }
.ct-step-dot { width:34px;height:34px;border-radius:50%;border:2px solid var(--line);background:var(--bg,#111);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:var(--muted);transition:.2s; }
.ct-step.active .ct-step-dot { border-color:var(--blue,#3b82f6);background:var(--blue,#3b82f6);color:#fff; }
.ct-step.done .ct-step-dot { border-color:var(--green,#22c55e);background:var(--green,#22c55e);color:#fff; }
.ct-step-label { font-size:11px;color:var(--muted);white-space:nowrap; }
.ct-step.active .ct-step-label,.ct-step.done .ct-step-label { color:var(--text); }
.ct-body { flex:1;overflow-y:auto;padding-right:4px; }
.ct-field { margin-bottom:16px; }
.ct-label { font-size:12.5px;font-weight:600;margin-bottom:5px;display:block; }
.ct-label .req { color:var(--red,#ef4444);margin-left:2px; }
.ct-input { width:100%;padding:9px 12px;border:1.5px solid var(--line);border-radius:8px;background:transparent;color:var(--text);font-size:13px;box-sizing:border-box;transition:border-color .15s; }
.ct-input:focus { outline:none;border-color:var(--blue,#3b82f6); }
.ct-input.error { border-color:var(--red,#ef4444); }
#modalCreateTest select.ct-input { background-color:#0f0f0f;color:#f0f0f0; }
#modalCreateTest select.ct-input option { background-color:#0f0f0f !important;color:#f0f0f0 !important; }
#modalCreateTest select.ct-input option:checked { background-color:#1e3a5f !important;color:#fff !important; }
.ct-row { display:grid;gap:12px; }
.ct-row.cols2 { grid-template-columns:1fr 1fr; }
.ct-row.cols4 { grid-template-columns:1fr 1fr 1fr 1fr; }
.ct-row.cols3 { grid-template-columns:1fr 1fr 1fr; }
.ct-checkbox-group { display:flex;gap:10px;flex-wrap:wrap; }
.ct-checkbox-item { display:flex;align-items:center;gap:8px;padding:9px 14px;border:1.5px solid var(--line);border-radius:8px;cursor:pointer;font-size:13px;transition:.15s;user-select:none; }
.ct-checkbox-item:hover { border-color:var(--blue,#3b82f6); }
.ct-checkbox-item input { accent-color:var(--blue,#3b82f6);width:15px;height:15px; }
.ct-checkbox-item.checked { border-color:var(--blue,#3b82f6);background:rgba(59,130,246,.06); }
/* Step 2 */
.ct-dropzone { border:2px dashed rgba(139,92,246,.5);border-radius:12px;padding:44px 20px;text-align:center;cursor:pointer;background:rgba(139,92,246,.04);transition:.2s; }
.ct-dropzone:hover,.ct-dropzone.drag { border-color:rgba(139,92,246,.9);background:rgba(139,92,246,.09); }
.ct-dropzone-icon { font-size:38px;margin-bottom:10px; }
.ct-warn { padding:12px 14px;background:rgba(234,179,8,.09);border:1px solid rgba(234,179,8,.3);border-radius:8px;font-size:12.5px;color:var(--yellow-text,#92700a);margin-bottom:12px; }
.ct-warn b { color:var(--yellow-text,#92700a); }
/* Step 3 preview */
.ct-q-card { border:1px solid var(--line);border-radius:9px;padding:12px 14px;margin-bottom:8px; }
.ct-q-opt { font-size:12px;padding:4px 9px;border-radius:5px;margin-top:3px; }
.ct-q-opt.correct { background:rgba(34,197,94,.12);color:var(--green,#22c55e);font-weight:600; }
.ct-footer { display:flex;justify-content:space-between;align-items:center;padding-top:14px;border-top:1px solid var(--line);margin-top:14px;flex-shrink:0; }
</style>

<div class="modal-overlay" id="modalCreateTest">
  <div class="modal" style="max-width:780px;width:95%;max-height:92vh;display:flex;flex-direction:column;padding:24px;">
    <button class="modal-close" onclick="closeCreateModal()" style="top:14px;right:14px;">✕</button>
    <h3 style="margin-bottom:18px;">Create New Assessment</h3>

    <!-- Stepper -->
    <div class="ct-stepper">
      <div class="ct-step active" id="ctStep1Dot">
        <div class="ct-step-dot">1</div>
        <div class="ct-step-label">Details</div>
      </div>
      <div class="ct-step" id="ctStep2Dot">
        <div class="ct-step-dot">2</div>
        <div class="ct-step-label">Questions</div>
      </div>
      <div class="ct-step" id="ctStep3Dot">
        <div class="ct-step-dot">3</div>
        <div class="ct-step-label">Review</div>
      </div>
    </div>

    <!-- ── STEP 1: Details ── -->
    <div class="ct-body" id="ctBodyStep1">
      <div class="ct-field">
        <label class="ct-label">Title <span class="req">*</span></label>
        <input id="ctTitle" class="ct-input" placeholder="e.g. Quantitative Aptitude – Set 1" maxlength="200">
      </div>
      <div class="ct-field">
        <label class="ct-label">Description</label>
        <textarea id="ctDesc" class="ct-input" rows="3" placeholder="Brief overview…" style="resize:vertical;"></textarea>
      </div>
      <div class="ct-row cols4 ct-field">
        <div>
          <label class="ct-label">Category <span class="req">*</span></label>
          <select id="ctCategory" class="ct-input">
            <option value="">Select category</option>
            <option value="aptitude">Aptitude</option>
            <option value="technical">Technical</option>
            <option value="coding">Coding</option>
            <option value="reasoning">Reasoning</option>
            <option value="english">English</option>
            <option value="general">General</option>
          </select>
        </div>
        <div>
          <label class="ct-label">Difficulty <span class="req">*</span></label>
          <select id="ctDifficulty" class="ct-input">
            <option value="easy">Easy</option>
            <option value="medium" selected>Medium</option>
            <option value="hard">Hard</option>
          </select>
        </div>
        <div>
          <label class="ct-label">Duration (minutes) <span class="req">*</span></label>
          <input id="ctDuration" class="ct-input" type="number" min="1" placeholder="e.g. 60">
        </div>
        <div>
          <label class="ct-label">Total Marks <span class="req">*</span></label>
          <input id="ctTotalMarks" class="ct-input" type="number" min="1" placeholder="e.g. 100">
        </div>
      </div>
      <div class="ct-row cols4 ct-field">
        <div>
          <label class="ct-label">Passing Marks <span class="req">*</span></label>
          <input id="ctPassingMarks" class="ct-input" type="number" min="0" placeholder="e.g. 40">
        </div>
        <div>
          <label class="ct-label">Max Attempts</label>
          <input id="ctMaxAttempts" class="ct-input" type="number" min="1" value="1">
        </div>
        <div>
          <label class="ct-label">Visibility</label>
          <select id="ctVisibility" class="ct-input">
            <option value="public">Public</option>
            <option value="private">Private</option>
          </select>
        </div>
        <div></div>
      </div>
      <div class="ct-row cols2 ct-field">
        <div>
          <label class="ct-label">Start Time</label>
          <input id="ctStartTime" class="ct-input" type="datetime-local">
        </div>
        <div>
          <label class="ct-label">End Time</label>
          <input id="ctEndTime" class="ct-input" type="datetime-local">
        </div>
      </div>
      <div class="ct-field">
        <label class="ct-label">Options</label>
        <div class="ct-checkbox-group">
          <label class="ct-checkbox-item" id="chkRQ" onclick="toggleChk(this,'ctRandQ')">
            <input type="checkbox" id="ctRandQ"> Randomize questions
          </label>
          <label class="ct-checkbox-item" id="chkRO" onclick="toggleChk(this,'ctRandO')">
            <input type="checkbox" id="ctRandO"> Randomize options
          </label>
          <label class="ct-checkbox-item" id="chkGuest" onclick="toggleChk(this,'ctGuest')">
            <input type="checkbox" id="ctGuest"> Public (allow guest access)
          </label>
        </div>
      </div>
      <div id="ctStep1Error" style="display:none;padding:9px 13px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:7px;font-size:12.5px;color:var(--red);margin-top:4px;"></div>
    </div>

    <!-- ── STEP 2: Questions ── -->
    <div class="ct-body" id="ctBodyStep2" style="display:none;">
      <p style="font-size:12.5px;color:var(--muted);margin-bottom:14px;line-height:1.6;">
        Upload a <b>PDF or DOCX</b> with numbered MCQ questions. Parsed questions are added automatically.<br>
        <span style="font-family:monospace;font-size:11.5px;">Format: 1. Question text</span> followed by
        <span style="font-family:monospace;font-size:11.5px;">a) Option lines.</span>
      </p>
      <div id="ctStep1Warn" style="display:none;margin-bottom:14px;" class="ct-warn">
        <b>⚠ Complete Step 1 first.</b> You need a title, category, difficulty, duration, and marks saved before importing questions.<br>
        <button class="btn btn-primary btn-sm" style="margin-top:8px;" onclick="ctGoStep(1)">Go to Step 1 →</button>
      </div>
      <div class="ct-dropzone" id="ctDropZone"
           onclick="document.getElementById('ctQFileInput').click()"
           ondragover="ctDragOver(event)" ondragleave="ctDragLeave(event)" ondrop="ctDrop(event)">
        <div class="ct-dropzone-icon">📤</div>
        <div style="font-size:15px;font-weight:700;color:var(--text);margin-bottom:4px;">Click to upload or drag &amp; drop</div>
        <div style="font-size:12px;color:var(--muted);">PDF or DOCX · Max 10 MB</div>
      </div>
      <input type="file" id="ctQFileInput" accept=".pdf,.docx" style="display:none;" onchange="ctHandleQFile(this.files[0])">

      <div id="ctFileChip" style="display:none;margin-top:10px;padding:9px 13px;background:var(--card-alt,rgba(255,255,255,.04));border-radius:7px;display:none;align-items:center;gap:10px;">
        <svg viewBox="0 0 24 24" style="width:18px;height:18px;stroke:var(--blue,#3b82f6);fill:none;stroke-width:2;flex-shrink:0;stroke-linecap:round;stroke-linejoin:round;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        <span id="ctFileName" style="flex:1;font-size:13px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
        <span id="ctFileSize" style="font-size:11px;color:var(--muted);"></span>
        <button class="btn btn-ghost btn-sm" onclick="ctClearQFile()">✕</button>
      </div>
      <div id="ctParseStatus" style="display:none;margin-top:10px;font-size:12.5px;padding:9px 13px;border-radius:7px;"></div>

      <!-- Questions list (populated by file upload) -->
      <div style="margin-top:20px;border-top:1px solid var(--line);padding-top:16px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
          <span style="font-size:12.5px;font-weight:600;">Questions <span id="ctQCount" style="color:var(--muted);font-weight:400;">(0 added)</span></span>
        </div>
        <div id="ctQList" style="display:flex;flex-direction:column;gap:10px;"></div>
      </div>
      <div id="ctStep2Error" style="display:none;padding:9px 13px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:7px;font-size:12.5px;color:var(--red);margin-top:12px;"></div>
    </div>

    <!-- ── STEP 3: Review ── -->
    <div class="ct-body" id="ctBodyStep3" style="display:none;">
      <div id="ctReviewMeta" style="background:var(--card-alt,rgba(255,255,255,.04));border-radius:10px;padding:16px;margin-bottom:16px;display:grid;grid-template-columns:repeat(4,1fr);gap:10px 16px;"></div>
      <div style="font-size:12px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px;">Questions</div>
      <div id="ctReviewQuestions" style="max-height:340px;overflow-y:auto;"></div>
      <div id="ctReviewWarn" style="margin-top:10px;"></div>
    </div>

    <!-- Footer -->
    <div class="ct-footer">
      <button class="btn btn-ghost" id="ctBtnBack" onclick="ctBack()" style="visibility:hidden;">← Back</button>
      <div style="display:flex;gap:8px;align-items:center;">
        <span id="ctStepLabel" style="font-size:12px;color:var(--muted);">Step 1 of 3</span>
        <button class="btn btn-ghost" onclick="closeCreateModal()">Cancel</button>
        <button class="btn btn-primary" id="ctBtnNext" onclick="ctNext()">Next →</button>
      </div>
    </div>
  </div>
</div>
<!-- ===================================================================== -->

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

/* ==================== CREATE / UPLOAD TEST ==================== */
let ctCurrentStep = 1;
let ctQuestions = [];   // { question_text, question_type, marks, negative_marks, explanation, options:[{option_text,is_correct}] }
let ctStep1Saved = false;

function openCreateModal() {
    ctReset();
    openModal('modalCreateTest');
}

function closeCreateModal() {
    closeModal('modalCreateTest');
}

function ctReset() {
    ctCurrentStep = 1;
    ctQuestions = [];
    ctStep1Saved = false;
    ['ctTitle','ctDesc','ctDuration','ctTotalMarks','ctPassingMarks'].forEach(id => { const el = document.getElementById(id); if(el) el.value = ''; });
    document.getElementById('ctCategory').value = '';
    document.getElementById('ctDifficulty').value = 'medium';
    document.getElementById('ctMaxAttempts').value = 1;
    document.getElementById('ctVisibility').value = 'public';
    document.getElementById('ctStartTime').value = '';
    document.getElementById('ctEndTime').value = '';
    ['ctRandQ','ctRandO','ctGuest'].forEach(id => { document.getElementById(id).checked = false; });
    ['chkRQ','chkRO','chkGuest'].forEach(id => { document.getElementById(id).classList.remove('checked'); });
    ctClearQFile();
    document.getElementById('ctQList').innerHTML = '';
    ctUpdateQCount();
    document.getElementById('ctStep1Error').style.display = 'none';
    document.getElementById('ctStep2Error').style.display = 'none';
    ctRenderStep(1);
}

function toggleChk(label, inputId) {
    const cb = document.getElementById(inputId);
    cb.checked = !cb.checked;
    label.classList.toggle('checked', cb.checked);
}

function ctRenderStep(n) {
    ctCurrentStep = n;
    [1,2,3].forEach(i => {
        document.getElementById(`ctBodyStep${i}`).style.display = i===n ? '' : 'none';
        const dot = document.getElementById(`ctStep${i}Dot`);
        dot.classList.remove('active','done');
        if (i === n) dot.classList.add('active');
        else if (i < n) dot.classList.add('done');
    });
    document.getElementById('ctStepLabel').textContent = `Step ${n} of 3`;
    document.getElementById('ctBtnBack').style.visibility = n > 1 ? 'visible' : 'hidden';
    const nextBtn = document.getElementById('ctBtnNext');
    if (n === 3) { nextBtn.textContent = 'Submit Assessment'; }
    else { nextBtn.textContent = 'Next →'; }
    // Show step1-incomplete warning on step 2
    if (n === 2) {
        document.getElementById('ctStep1Warn').style.display = ctStep1Saved ? 'none' : '';
    }
}

function ctGoStep(n) { ctRenderStep(n); }

function ctBack() {
    if (ctCurrentStep > 1) ctRenderStep(ctCurrentStep - 1);
}

function ctNext() {
    if (ctCurrentStep === 1) {
        if (!ctValidateStep1()) return;
        ctStep1Saved = true;
        ctRenderStep(2);
    } else if (ctCurrentStep === 2) {
        if (ctQuestions.length === 0) {
            const e = document.getElementById('ctStep2Error');
            e.textContent = 'Add at least one question before proceeding.';
            e.style.display = '';
            return;
        }
        document.getElementById('ctStep2Error').style.display = 'none';
        ctBuildReview();
        ctRenderStep(3);
    } else if (ctCurrentStep === 3) {
        ctSubmit();
    }
}

function ctValidateStep1() {
    const errEl = document.getElementById('ctStep1Error');
    const title = document.getElementById('ctTitle').value.trim();
    const category = document.getElementById('ctCategory').value;
    const duration = parseInt(document.getElementById('ctDuration').value);
    const total = parseInt(document.getElementById('ctTotalMarks').value);
    const passing = parseInt(document.getElementById('ctPassingMarks').value);
    const start = document.getElementById('ctStartTime').value;
    const end = document.getElementById('ctEndTime').value;

    let err = '';
    if (!title) err = 'Title is required.';
    else if (!category) err = 'Category is required.';
    else if (!duration || duration < 1) err = 'Duration must be at least 1 minute.';
    else if (!total || total < 1) err = 'Total marks must be at least 1.';
    else if (isNaN(passing) || passing < 0) err = 'Passing marks must be 0 or more.';
    else if (passing > total) err = 'Passing marks cannot exceed total marks.';
    else if (start && end && new Date(end) <= new Date(start)) err = 'End time must be after start time.';

    if (err) { errEl.textContent = err; errEl.style.display = ''; return false; }
    errEl.style.display = 'none';
    return true;
}

/* ── Step 2: file upload & parsing ── */
function ctDragOver(e) {
    e.preventDefault();
    document.getElementById('ctDropZone').classList.add('drag');
}
function ctDragLeave(e) {
    document.getElementById('ctDropZone').classList.remove('drag');
}
function ctDrop(e) {
    e.preventDefault();
    ctDragLeave(e);
    const file = e.dataTransfer.files[0];
    if (file) ctHandleQFile(file);
}

function ctClearQFile() {
    document.getElementById('ctQFileInput').value = '';
    document.getElementById('ctFileChip').style.display = 'none';
    document.getElementById('ctParseStatus').style.display = 'none';
}

async function ctHandleQFile(file) {
    if (!file) return;
    const maxMB = 10;
    if (file.size > maxMB * 1024 * 1024) {
        ctShowParseStatus('error', `File too large. Max ${maxMB} MB allowed.`); return;
    }
    const ext = file.name.split('.').pop().toLowerCase();
    if (!['pdf','docx'].includes(ext)) {
        ctShowParseStatus('error', 'Only PDF or DOCX files are accepted.'); return;
    }
    document.getElementById('ctFileName').textContent = file.name;
    document.getElementById('ctFileSize').textContent = (file.size/1024).toFixed(1) + ' KB';
    document.getElementById('ctFileChip').style.display = 'flex';
    ctShowParseStatus('info', '⏳ Parsing questions from file…');

    try {
        // Fetch a fresh CSRF token directly from the endpoint.
        // This avoids any dependency on how the shared JS stores it.
        const base = window.location.pathname.replace(/\/[^\/]*$/, '');
        const tokenResp = await fetch(base + '/api/csrf-token.php', { credentials: 'same-origin' });
        if (!tokenResp.ok) {
            ctShowParseStatus('error', 'Session expired. Please refresh the page and try again.');
            return;
        }
        const tokenData = await tokenResp.json();
        if (!tokenData.success || !tokenData.token) {
            ctShowParseStatus('error', 'Could not get CSRF token. Please refresh the page.');
            return;
        }
        const csrfToken = tokenData.token;
        // Keep in sync with whatever the shared JS uses
        window._csrfToken = csrfToken;

        const formData = new FormData();
        formData.append('file', file);
        const resp = await fetch('api-parse-questions.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': csrfToken },
            body: formData
        });

        // Read raw text first so a PHP fatal error doesn't break JSON.parse
        const raw = await resp.text();
        let d;
        try { d = JSON.parse(raw); } catch(_) {
            ctShowParseStatus('error', `Server error (HTTP ${resp.status}). Check PHP error log.`);
            console.error('api-parse-questions raw response:', raw);
            return;
        }

        if (!d.success) { ctShowParseStatus('error', d.error || 'Parsing failed.'); return; }
        const parsed = d.questions || [];
        if (!parsed.length) { ctShowParseStatus('warn', 'No questions found. Check the file format and try again.'); return; }
        parsed.forEach(q => ctQuestions.push(q));
        ctRenderQList();
        ctShowParseStatus('success', `✓ ${parsed.length} question${parsed.length!==1?'s':''} parsed and added.`);
    } catch(e) {
        ctShowParseStatus('error', 'Upload failed: ' + (e.message || 'Unknown error'));
        console.error('ctHandleQFile error:', e);
    }
}

function ctShowParseStatus(type, msg) {
    const el = document.getElementById('ctParseStatus');
    const styles = {
        error:   'background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);color:var(--red)',
        success: 'background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.2);color:var(--green)',
        warn:    'background:rgba(234,179,8,.08);border:1px solid rgba(234,179,8,.2);color:var(--muted)',
        info:    'background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.2);color:var(--blue,#3b82f6)',
    };
    el.style.cssText = styles[type] || styles.info;
    el.textContent = msg;
    el.style.display = '';
}

/* ── Manual question editor ── */
function ctAddBlankQuestion() {
    ctQuestions.push({
        question_text: '',
        question_type: 'mcq',
        marks: 1,
        negative_marks: 0,
        explanation: '',
        options: [
            { option_text: '', is_correct: true },
            { option_text: '', is_correct: false },
            { option_text: '', is_correct: false },
            { option_text: '', is_correct: false },
        ]
    });
    ctRenderQList();
    // Scroll to bottom of list
    setTimeout(() => {
        const list = document.getElementById('ctQList');
        list.lastElementChild?.scrollIntoView({ behavior:'smooth', block:'nearest' });
    }, 50);
}

function ctRenderQList() {
    const list = document.getElementById('ctQList');
    list.innerHTML = ctQuestions.map((q, qi) => {
        const opts = q.options.map((o, oi) => `
            <div style="display:flex;align-items:center;gap:7px;margin-top:5px;">
                <input type="radio" name="ctCorrect_${qi}" ${o.is_correct?'checked':''} onchange="ctSetCorrect(${qi},${oi})" title="Correct answer" style="accent-color:var(--green,#22c55e);flex-shrink:0;">
                <input class="ct-input" style="flex:1;padding:6px 9px;font-size:12px;" placeholder="Option ${String.fromCharCode(65+oi)}" value="${esc(o.option_text)}" oninput="ctQuestions[${qi}].options[${oi}].option_text=this.value">
            </div>`).join('');
        return `<div class="ct-q-card">
            <div style="display:flex;gap:8px;align-items:flex-start;margin-bottom:8px;">
                <span style="font-size:11px;color:var(--muted);font-weight:700;margin-top:10px;flex-shrink:0;">${qi+1}.</span>
                <textarea class="ct-input" rows="2" style="flex:1;font-size:12.5px;resize:vertical;" placeholder="Question text…" oninput="ctQuestions[${qi}].question_text=this.value">${esc(q.question_text)}</textarea>
                <div style="display:flex;flex-direction:column;gap:4px;flex-shrink:0;">
                    <select class="ct-input" style="font-size:11.5px;padding:5px 7px;background:#0f0f0f;color:#f0f0f0;" onchange="ctQuestions[${qi}].question_type=this.value;ctRenderQList()">
                        ${['mcq','multiple_select','true_false','short_answer'].map(t=>`<option value="${t}" style="background:#0f0f0f;color:#f0f0f0;" ${q.question_type===t?'selected':''}>${t.replace(/_/g,' ')}</option>`).join('')}
                    </select>
                    <div style="display:flex;gap:4px;">
                        <input class="ct-input" type="number" min="0" style="width:52px;padding:5px 7px;font-size:11.5px;" title="Marks" value="${q.marks}" oninput="ctQuestions[${qi}].marks=+this.value||1">
                        <input class="ct-input" type="number" min="0" step="0.5" style="width:52px;padding:5px 7px;font-size:11.5px;" title="Negative marks" value="${q.negative_marks||0}" oninput="ctQuestions[${qi}].negative_marks=+this.value||0">
                    </div>
                </div>
                <button class="btn btn-ghost btn-sm" style="flex-shrink:0;color:var(--red);" onclick="ctDeleteQuestion(${qi})">✕</button>
            </div>
            ${q.question_type !== 'short_answer' ? `<div style="padding-left:18px;">${opts}</div>` : `<div style="padding-left:18px;font-size:11.5px;color:var(--muted);">Short answer — no options needed.</div>`}
            <input class="ct-input" style="margin-top:8px;font-size:11.5px;padding:6px 9px;" placeholder="Explanation (optional)…" value="${esc(q.explanation||'')}" oninput="ctQuestions[${qi}].explanation=this.value">
        </div>`;
    }).join('');
    ctUpdateQCount();
}

function ctSetCorrect(qi, oi) {
    ctQuestions[qi].options.forEach((o,i) => o.is_correct = (i === oi));
}

function ctDeleteQuestion(qi) {
    ctQuestions.splice(qi, 1);
    ctRenderQList();
}

function ctUpdateQCount() {
    document.getElementById('ctQCount').textContent = `(${ctQuestions.length} added)`;
}

/* ── Step 3: Review ── */
function ctBuildReview() {
    const v = ctGetStep1Values();
    document.getElementById('ctReviewMeta').innerHTML = [
        ['Title', v.title], ['Category', v.category||'—'], ['Difficulty', v.difficulty],
        ['Duration', v.duration_minutes+' min'], ['Marks', `${v.total_marks} / ${v.passing_marks} pass`],
        ['Attempts', v.max_attempts], ['Visibility', v.visibility], ['Questions', ctQuestions.length],
    ].map(([k,val]) => `<div><div style="font-size:10.5px;color:var(--muted);">${k}</div><div style="font-size:13px;font-weight:600;">${esc(String(val))}</div></div>`).join('');

    const qHtml = ctQuestions.map((q,i) => {
        const opts = q.options.map((o,j) =>
            `<div class="ct-q-opt ${o.is_correct?'correct':''}"><b>${String.fromCharCode(65+j)}.</b> ${esc(o.option_text)}${o.is_correct?' ✓':''}</div>`
        ).join('');
        return `<div class="ct-q-card">
            <div style="display:flex;justify-content:space-between;gap:8px;margin-bottom:4px;">
                <div style="font-size:13px;font-weight:600;">${i+1}. ${esc(q.question_text)}</div>
                <div style="display:flex;gap:5px;flex-shrink:0;">
                    <span class="badge info" style="font-size:10px;">${esc(q.question_type.replace(/_/g,' '))}</span>
                    <span style="font-size:10.5px;color:var(--muted);">${q.marks}m${q.negative_marks?` -${q.negative_marks}`:''}</span>
                </div>
            </div>
            ${opts}
            ${q.explanation?`<div style="font-size:11px;color:var(--muted);margin-top:5px;"><b>Explanation:</b> ${esc(q.explanation)}</div>`:''}
        </div>`;
    }).join('');
    document.getElementById('ctReviewQuestions').innerHTML = qHtml;

    // Warnings
    const totalFromQ = ctQuestions.reduce((s,q)=>s+(q.marks||1),0);
    const warns = [];
    if (totalFromQ !== v.total_marks) warns.push(`⚠ Sum of question marks (${totalFromQ}) differs from total_marks (${v.total_marks}).`);
    document.getElementById('ctReviewWarn').innerHTML = warns.map(w =>
        `<div style="padding:8px 12px;background:rgba(234,179,8,.08);border:1px solid rgba(234,179,8,.25);border-radius:6px;font-size:12px;color:var(--muted);margin-bottom:6px;">${w}</div>`
    ).join('');
}

function ctGetStep1Values() {
    return {
        title:            document.getElementById('ctTitle').value.trim(),
        description:      document.getElementById('ctDesc').value.trim(),
        category:         document.getElementById('ctCategory').value,
        difficulty:       document.getElementById('ctDifficulty').value,
        duration_minutes: parseInt(document.getElementById('ctDuration').value)||0,
        total_marks:      parseInt(document.getElementById('ctTotalMarks').value)||0,
        passing_marks:    parseInt(document.getElementById('ctPassingMarks').value)||0,
        max_attempts:     parseInt(document.getElementById('ctMaxAttempts').value)||1,
        visibility:       document.getElementById('ctVisibility').value,
        start_time:       document.getElementById('ctStartTime').value || null,
        end_time:         document.getElementById('ctEndTime').value   || null,
        randomize_questions: document.getElementById('ctRandQ').checked,
        randomize_options:   document.getElementById('ctRandO').checked,
        guest_access:        document.getElementById('ctGuest').checked,
    };
}

async function ctSubmit() {
    const btn = document.getElementById('ctBtnNext');
    btn.disabled = true;
    btn.textContent = 'Submitting…';
    const payload = { ...ctGetStep1Values(), questions: ctQuestions };
    try {
        const d = await apiPost(API.uploadTest || 'api-upload-test.php', { test: payload });
        if (d.success) {
            showToast(`Assessment "${payload.title}" created as draft!`, 'success');
            closeCreateModal();
            PageCache.invalidate(`tests:${testStatus}:${testSearch}:${testCategory}:${testPage}`);
            loadTests();
        } else {
            showToast(d.error || 'Submission failed.', 'error');
            btn.disabled = false;
            btn.textContent = 'Submit Assessment';
        }
    } catch(e) {
        showToast('Network error. Please try again.', 'error');
        btn.disabled = false;
        btn.textContent = 'Submit Assessment';
    }
}

</script>
</body>
</html>
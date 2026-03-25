<?php
/* ========================================
 * ADMIN NOTIFICATIONS — REPORTS INBOX
 * File: admin-notifications.php
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

$currentPage = 'notifications';
$pageTitle   = 'Notifications — PREPAURA Admin';
require_once __DIR__ . '/admin-head.php';

/* ── Fetch reports ── */
$tab = $_GET['tab'] ?? 'student';   // student | teacher  (teachers use same table via role)

// student reports
$studentReports = [];
$sRes = safePreparedQuery($conn,
    "SELECT r.*, u.full_name, u.email, u.department, u.profile_image
     FROM student_reports r
     JOIN users u ON u.user_id = r.user_id
     WHERE u.role = 'student'
     ORDER BY FIELD(r.status,'pending','in_progress','resolved','rejected'), r.created_at DESC",
    "", []
);
if ($sRes['success'] && $sRes['result']) {
    while ($row = $sRes['result']->fetch_assoc()) $studentReports[] = $row;
    $sRes['result']->free();
}

// teacher reports (same table, different role)
$teacherReports = [];
$tRes = safePreparedQuery($conn,
    "SELECT r.*, u.full_name, u.email, u.department, u.profile_image
     FROM student_reports r
     JOIN users u ON u.user_id = r.user_id
     WHERE u.role = 'teacher'
     ORDER BY FIELD(r.status,'pending','in_progress','resolved','rejected'), r.created_at DESC",
    "", []
);
if ($tRes['success'] && $tRes['result']) {
    while ($row = $tRes['result']->fetch_assoc()) $teacherReports[] = $row;
    $tRes['result']->free();
}

$sPending = count(array_filter($studentReports, fn($r) => $r['status'] === 'pending'));
$tPending = count(array_filter($teacherReports, fn($r) => $r['status'] === 'pending'));
?>
<body>
<?php require_once __DIR__ . '/admin-sidebar.php'; ?>

<div class="content">
    <div class="section-header">
        <div>
            <h2>Notifications</h2>
            <p>Reports received from students and teachers</p>
        </div>
    </div>

    <!-- TAB SWITCHER -->
    <div class="notif-tabs">
        <button class="notif-tab <?= $tab==='student'?'active':'' ?>" onclick="switchTab('student')">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            Student Reports
            <?php if ($sPending > 0): ?>
                <span class="tab-badge"><?= $sPending ?></span>
            <?php endif; ?>
        </button>
        <button class="notif-tab <?= $tab==='teacher'?'active':'' ?>" onclick="switchTab('teacher')">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 7H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
            Teacher Reports
            <?php if ($tPending > 0): ?>
                <span class="tab-badge"><?= $tPending ?></span>
            <?php endif; ?>
        </button>
    </div>

    <!-- INBOX LAYOUT -->
    <div class="inbox-wrap">

        <!-- LEFT: LIST PANEL -->
        <div class="inbox-list" id="inboxList">

            <!-- STUDENT PANEL -->
            <div class="report-panel" id="panel-student" style="<?= $tab!=='student'?'display:none':'' ?>">
                <?php if (empty($studentReports)): ?>
                    <div class="inbox-empty">
                        <div class="inbox-empty-icon">📭</div>
                        <div>No student reports yet</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($studentReports as $r): ?>
                    <?php
                        $initials = strtoupper(substr($r['full_name'], 0, 2));
                        $isUnread = $r['status'] === 'pending';
                        $statusClass = match($r['status']) {
                            'pending'     => 'status-pending',
                            'in_progress' => 'status-inprog',
                            'resolved'    => 'status-resolved',
                            'rejected'    => 'status-rejected',
                            default       => ''
                        };
                        $statusLabel = match($r['status']) {
                            'pending'     => 'Pending',
                            'in_progress' => 'In Progress',
                            'resolved'    => 'Resolved',
                            'rejected'    => 'Rejected',
                            default       => ucfirst($r['status'])
                        };
                        $desc_preview = mb_strimwidth(strip_tags($r['description']), 0, 80, '...');
                        $timeAgo = timeAgo($r['created_at']);
                    ?>
                    <div class="inbox-item <?= $isUnread ? 'unread' : '' ?>"
                         onclick="openReport(<?= $r['report_id'] ?>, 'student')"
                         data-id="<?= $r['report_id'] ?>">
                        <div class="inbox-avatar"><?= $initials ?></div>
                        <div class="inbox-meta">
                            <div class="inbox-row1">
                                <span class="inbox-name"><?= htmlspecialchars($r['full_name']) ?></span>
                                <span class="inbox-time"><?= $timeAgo ?></span>
                            </div>
                            <div class="inbox-subject"><?= htmlspecialchars($r['title']) ?></div>
                            <div class="inbox-preview"><?= htmlspecialchars($desc_preview) ?></div>
                        </div>
                        <div class="inbox-badges">
                            <span class="status-badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                            <?php if ($isUnread): ?><span class="unread-dot"></span><?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- TEACHER PANEL -->
            <div class="report-panel" id="panel-teacher" style="<?= $tab!=='teacher'?'display:none':'' ?>">
                <?php if (empty($teacherReports)): ?>
                    <div class="inbox-empty">
                        <div class="inbox-empty-icon">📭</div>
                        <div>No teacher reports yet</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($teacherReports as $r): ?>
                    <?php
                        $initials = strtoupper(substr($r['full_name'], 0, 2));
                        $isUnread = $r['status'] === 'pending';
                        $statusClass = match($r['status']) {
                            'pending'     => 'status-pending',
                            'in_progress' => 'status-inprog',
                            'resolved'    => 'status-resolved',
                            'rejected'    => 'status-rejected',
                            default       => ''
                        };
                        $statusLabel = match($r['status']) {
                            'pending'     => 'Pending',
                            'in_progress' => 'In Progress',
                            'resolved'    => 'Resolved',
                            'rejected'    => 'Rejected',
                            default       => ucfirst($r['status'])
                        };
                        $desc_preview = mb_strimwidth(strip_tags($r['description']), 0, 80, '...');
                        $timeAgo = timeAgo($r['created_at']);
                    ?>
                    <div class="inbox-item <?= $isUnread ? 'unread' : '' ?>"
                         onclick="openReport(<?= $r['report_id'] ?>, 'teacher')"
                         data-id="<?= $r['report_id'] ?>">
                        <div class="inbox-avatar teacher-av"><?= $initials ?></div>
                        <div class="inbox-meta">
                            <div class="inbox-row1">
                                <span class="inbox-name"><?= htmlspecialchars($r['full_name']) ?></span>
                                <span class="inbox-time"><?= $timeAgo ?></span>
                            </div>
                            <div class="inbox-subject"><?= htmlspecialchars($r['title']) ?></div>
                            <div class="inbox-preview"><?= htmlspecialchars($desc_preview) ?></div>
                        </div>
                        <div class="inbox-badges">
                            <span class="status-badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                            <?php if ($isUnread): ?><span class="unread-dot"></span><?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div><!-- /inbox-list -->

        <!-- RIGHT: DETAIL PANEL -->
        <div class="inbox-detail" id="inboxDetail">
            <div class="detail-empty" id="detailEmpty">
                <div class="detail-empty-icon">
                    <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                </div>
                <div class="detail-empty-title">Select a report</div>
                <div class="detail-empty-sub">Click any item on the left to read the full report</div>
            </div>
        </div>

    </div><!-- /inbox-wrap -->
</div><!-- /content -->

<!-- REPORT DATA (JSON for JS) -->
<script>
const REPORTS = {
    student: <?= json_encode(array_values($studentReports), JSON_HEX_TAG) ?>,
    teacher: <?= json_encode(array_values($teacherReports), JSON_HEX_TAG) ?>
};
</script>

<?php require_once __DIR__ . '/admin-footer.php'; ?>

<style>
/* ── TAB SWITCHER ── */
.notif-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 6px;
    width: fit-content;
}
.notif-tab {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 9px 20px;
    border-radius: 10px;
    border: none;
    background: transparent;
    color: var(--muted);
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    transition: all .2s;
    font-family: inherit;
}
.notif-tab:hover { color: var(--text); background: var(--hover); }
.notif-tab.active { background: var(--accent); color: #fff; }
.notif-tab.active svg { stroke: #fff; }
.tab-badge {
    background: var(--red);
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    border-radius: 20px;
    padding: 1px 7px;
    min-width: 18px;
    text-align: center;
}
.notif-tab.active .tab-badge { background: rgba(255,255,255,.3); }

/* ── INBOX LAYOUT ── */
.inbox-wrap {
    display: grid;
    grid-template-columns: 380px 1fr;
    gap: 16px;
    height: calc(100vh - 220px);
    min-height: 500px;
}
.inbox-list {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    overflow-y: auto;
    overflow-x: hidden;
}
.inbox-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
    cursor: pointer;
    transition: background .15s;
    position: relative;
}
.inbox-item:last-child { border-bottom: none; }
.inbox-item:hover { background: var(--hover); }
.inbox-item.active { background: var(--accent-faint, rgba(14,165,233,.08)); }
.inbox-item.unread .inbox-name { font-weight: 700; }
.inbox-item.unread .inbox-subject { font-weight: 700; color: var(--text); }

.inbox-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), #06b6d4);
    color: #fff;
    font-size: 14px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.inbox-avatar.teacher-av {
    background: linear-gradient(135deg, var(--green), #10b981);
}
.inbox-meta { flex: 1; min-width: 0; }
.inbox-row1 { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2px; }
.inbox-name  { font-size: 13px; font-weight: 600; color: var(--text); }
.inbox-time  { font-size: 11px; color: var(--muted); white-space: nowrap; }
.inbox-subject { font-size: 13px; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 2px; }
.inbox-preview { font-size: 12px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.inbox-badges { display: flex; flex-direction: column; align-items: flex-end; gap: 5px; flex-shrink: 0; }

.unread-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: var(--accent);
    display: block;
}

.inbox-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 300px;
    color: var(--muted);
    gap: 10px;
    font-size: 14px;
}
.inbox-empty-icon { font-size: 40px; }

/* STATUS BADGES */
.status-badge {
    font-size: 10.5px;
    font-weight: 700;
    border-radius: 20px;
    padding: 3px 9px;
    white-space: nowrap;
}
.status-pending  { background: rgba(234,179,8,.15);   color: #b45309; }
.status-inprog   { background: rgba(59,130,246,.15);  color: #1d4ed8; }
.status-resolved { background: rgba(34,197,94,.15);   color: #15803d; }
.status-rejected { background: rgba(239,68,68,.15);   color: #b91c1c; }

/* ── DETAIL PANEL ── */
.inbox-detail {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    overflow-y: auto;
    position: relative;
}
.detail-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: var(--muted);
    gap: 12px;
    text-align: center;
    padding: 40px;
}
.detail-empty-icon { opacity: .3; }
.detail-empty-title { font-size: 17px; font-weight: 600; color: var(--text); opacity: .5; }
.detail-empty-sub { font-size: 13px; }

/* ── MAIL DETAIL VIEW ── */
.mail-view { padding: 28px 32px; }
.mail-view-inner {
    background: rgba(255, 255, 255, 0.03);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border: 1px solid rgba(255, 255, 255, 0.10);
    border-radius: 18px;
    padding: 28px 28px 24px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3), inset 0 1px 0 rgba(255,255,255,0.07);
}
.mail-header { border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 20px; margin-bottom: 24px; }
.mail-subject { font-size: 20px; font-weight: 700; color: var(--text); margin-bottom: 14px; line-height: 1.3; }
.mail-sender { display: flex; align-items: center; gap: 12px; }
.mail-sender-av {
    width: 44px; height: 44px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), #06b6d4);
    color: #fff; font-size: 15px; font-weight: 700;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.mail-sender-av.teacher-av { background: linear-gradient(135deg, var(--green), #10b981); }
.mail-sender-info .sender-name  { font-size: 14px; font-weight: 600; color: var(--text); }
.mail-sender-info .sender-email { font-size: 12px; color: var(--muted); }
.mail-date { margin-left: auto; font-size: 12px; color: var(--muted); white-space: nowrap; }

.mail-meta-tags { display: flex; align-items: center; gap: 10px; margin-top: 14px; flex-wrap: wrap; }
.mail-dept { font-size: 12px; color: var(--muted); background: var(--hover); border-radius: 8px; padding: 4px 10px; }

.mail-body { font-size: 14px; color: var(--text); line-height: 1.75; white-space: pre-wrap; word-break: break-word; margin-bottom: 24px; }

/* ATTACHMENT */
.mail-attachment {
    margin-bottom: 28px;
    background: rgba(255, 255, 255, 0.04);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 16px;
    padding: 16px;
    box-shadow: 0 4px 24px rgba(0, 0, 0, 0.25), inset 0 1px 0 rgba(255,255,255,0.06);
}
.mail-attachment-title { font-size: 12px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 12px; }
.attachment-img {
    max-width: 100%;
    max-height: 360px;
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.1);
    object-fit: contain;
    cursor: pointer;
    transition: opacity .2s, transform .2s;
    display: block;
}
.attachment-img:hover { opacity: .88; transform: scale(1.01); }

/* Previous admin note display */
.prev-note {
    background: rgba(14,165,233,.07);
    border: 1px solid rgba(14,165,233,.2);
    border-radius: 10px;
    padding: 12px 14px;
    font-size: 13px;
    color: var(--text);
    margin-bottom: 14px;
    line-height: 1.6;
}
.prev-note-label { font-size: 11px; font-weight: 700; color: var(--accent); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 4px; }

/* IMAGE LIGHTBOX */
.lightbox-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,.88);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}
.lightbox-overlay.open { display: flex; }
.lightbox-overlay img { max-width: 92vw; max-height: 90vh; border-radius: 10px; }
.lightbox-close {
    position: absolute; top: 20px; right: 28px;
    color: #fff; font-size: 28px;
    cursor: pointer; line-height: 1;
    background: none; border: none;
}

@media (max-width: 900px) {
    .inbox-wrap { grid-template-columns: 1fr; }
    .inbox-detail { display: none; }
    .inbox-detail.mobile-open { display: block; }
}
</style>

<!-- LIGHTBOX -->
<div class="lightbox-overlay" id="lightbox" onclick="closeLightbox()">
    <button class="lightbox-close" onclick="closeLightbox()">✕</button>
    <img id="lightboxImg" src="" alt="Report image">
</div>

<script>
document.addEventListener('DOMContentLoaded', () => fetchCsrf && fetchCsrf());

/* ── TAB SWITCH ── */
function switchTab(tab) {
    document.querySelectorAll('.notif-tab').forEach((btn, i) =>
        btn.classList.toggle('active', ['student','teacher'][i] === tab));
    document.getElementById('panel-student').style.display = tab==='student'?'':'none';
    document.getElementById('panel-teacher').style.display = tab==='teacher'?'':'none';
    clearDetail();
}

/* ── OPEN REPORT ── */
function openReport(id, role) {
    const list = REPORTS[role];
    const r = list.find(x => x.report_id == id);
    if (!r) return;

    // mark active
    document.querySelectorAll('.inbox-item').forEach(el => {
        el.classList.remove('active');
        if (el.dataset.id == id) el.classList.add('active');
    });

    const isTeacher = role === 'teacher';
    const av = isTeacher ? 'teacher-av' : '';
    const initials = r.full_name.substring(0,2).toUpperCase();
    const date = new Date(r.created_at).toLocaleString('en-IN', { day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' });
    const dept = r.department || '';

    const statusMap = { pending:'status-pending', in_progress:'status-inprog', resolved:'status-resolved', rejected:'status-rejected' };
    const labelMap  = { pending:'Pending', in_progress:'In Progress', resolved:'Resolved', rejected:'Rejected' };

    let imgHtml = '';
    if (r.image_path) {
        imgHtml = `
        <div class="mail-attachment">
            <div class="mail-attachment-title">📎 Attachment</div>
            <img class="attachment-img" src="${esc(r.image_path)}" alt="Attached screenshot"
                 onclick="openLightbox('${esc(r.image_path)}')">
        </div>`;
    }

    document.getElementById('inboxDetail').innerHTML = `
    <div class="mail-view">
      <div class="mail-view-inner">
        <div class="mail-header">
            <div class="mail-subject">${esc(r.title)}</div>
            <div class="mail-sender">
                <div class="mail-sender-av ${av}">${initials}</div>
                <div class="mail-sender-info">
                    <div class="sender-name">${esc(r.full_name)}</div>
                    <div class="sender-email">${esc(r.email)}</div>
                </div>
                <div class="mail-date">${date}</div>
            </div>
            <div class="mail-meta-tags">
                
<div class="status-toggle">
<span class="status-label pending">Pending</span>
<label class="switch">
<input type="checkbox" ${r.status==='resolved'?'checked':''}
onchange="toggleStatus(${r.report_id},this.checked)">
<span class="slider"></span>
</label>
<span class="status-label done">Done</span>
</div>

                ${dept ? `<span class="mail-dept">🏫 ${esc(dept)}</span>` : ''}
                <span class="mail-dept">${isTeacher ? '👩‍🏫 Teacher' : '🎓 Student'}</span>
            </div>
        </div>

        <div class="mail-body">${esc(r.description)}</div>

        ${imgHtml}
      </div>
    </div>`;
}


function clearDetail() {
    document.getElementById('inboxDetail').innerHTML = `
    <div class="detail-empty" id="detailEmpty">
        <div class="detail-empty-icon">
            <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        </div>
        <div class="detail-empty-title">Select a report</div>
        <div class="detail-empty-sub">Click any item on the left to read the full report</div>
    </div>`;
}

function openLightbox(src) {
    document.getElementById('lightboxImg').src = src;
    document.getElementById('lightbox').classList.add('open');
}
function closeLightbox() {
    document.getElementById('lightbox').classList.remove('open');
}
document.addEventListener('keydown', e => { if (e.key==='Escape') closeLightbox(); });

function esc(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<style>
.status-toggle{display:flex;align-items:center;gap:8px}
.status-label{font-size:11px;font-weight:700}
.status-label.pending{color:#ef4444}
.status-label.done{color:#22c55e}

.switch{position:relative;display:inline-block;width:42px;height:22px}
.switch input{opacity:0;width:0;height:0}
.slider{position:absolute;cursor:pointer;inset:0;background:#ef4444;border-radius:22px;transition:.3s}
.slider:before{content:"";position:absolute;height:16px;width:16px;left:3px;bottom:3px;background:white;border-radius:50%;transition:.3s}
input:checked + .slider{background:#22c55e}
input:checked + .slider:before{transform:translateX(20px)}
</style>

<script>
async function toggleStatus(id,checked){
const status = checked ? "resolved" : "pending";
try{
const res = await fetch("/pta/update-report-status.php",{
method:"POST",
headers:{'Content-Type':'application/json'},
body:JSON.stringify({report_id:id,status:status})
});
const data = await res.json();
if(!data.success){alert("Status update failed");}
}catch(e){alert("Server error");}
}
</script>

</body>
</html>

<?php
/* ── Helper: human-readable time ago ── */
function timeAgo(string $datetime): string {
    $now  = new DateTime();
    $then = new DateTime($datetime);
    $diff = $now->diff($then);
    if ($diff->days === 0) {
        if ($diff->h === 0) return $diff->i . 'm ago';
        return $diff->h . 'h ago';
    }
    if ($diff->days < 7)  return $diff->days . 'd ago';
    if ($diff->days < 30) return intdiv($diff->days, 7) . 'w ago';
    return $then->format('d M Y');
}
?>

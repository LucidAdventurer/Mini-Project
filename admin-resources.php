<?php
/* ========================================
 * ADMIN RESOURCES
 * File: admin-resources.php
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

$currentPage = 'resources';
$pageTitle   = 'Resources — PREPAURA Admin';
require_once __DIR__ . '/admin-head.php';
?>
<body>
<?php require_once __DIR__ . '/admin-sidebar.php'; ?>

    <div class="content">
        <div class="section-header">
            <div><h2>Resources</h2><p>Study materials, uploads, and learning content</p></div>
            <button class="btn btn-primary" onclick="openUploadModal()">
                <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Upload Resource
            </button>
        </div>
        <div class="stat-card blue" style="margin-bottom:18px;max-width:220px;">
            <div class="stat-icon"><svg viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg></div>
            <div class="stat-value" id="resTotalMaterials">—</div>
            <div class="stat-label">Total Materials</div>
        </div>
        <div class="resource-grid" id="resourceGrid">
            <div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--muted);">Loading resources…</div>
        </div>
    </div>
</div><!-- /main -->

<!-- Upload Resource Modal -->
<div class="modal-overlay" id="modalUploadResource">
    <div class="modal" style="width:540px;">
        <button class="modal-close" onclick="closeModal('modalUploadResource')">✕</button>
        <h3>Upload Resource</h3>
        <div class="form-group"><label>Title *</label><input class="form-input" id="urTitle" placeholder="e.g. GATE 2025 Prep Pack" maxlength="200"/></div>
        <div class="form-row">
            <div class="form-group"><label>Category *</label><select class="form-input" id="urCategory"><option value="aptitude">Aptitude</option><option value="verbal">Verbal</option><option value="logical">Logical</option><option value="technical">Technical</option><option value="general">General</option></select></div>
            <div class="form-group"><label>Difficulty</label><select class="form-input" id="urDifficulty"><option value="easy">Easy</option><option value="medium">Medium</option><option value="hard">Hard</option></select></div>
        </div>
        <div class="form-group">
            <label>Source</label>
            <div style="display:flex;gap:0;border:1px solid var(--ink4);border-radius:8px;overflow:hidden;margin-bottom:10px;">
                <button type="button" id="urToggleFile" onclick="urSetMode('file')" style="flex:1;padding:8px;font-size:12.5px;font-weight:600;border:none;cursor:pointer;background:var(--accent);color:#fff;transition:background 0.15s;">📎 Upload File</button>
                <button type="button" id="urToggleUrl" onclick="urSetMode('url')" style="flex:1;padding:8px;font-size:12.5px;font-weight:600;border:none;cursor:pointer;background:var(--ink3);color:var(--muted);transition:background 0.15s;">🔗 External URL</button>
            </div>
            <div id="urFileGroup"><input class="form-input" id="urFile" type="file" accept=".pdf,.mp4,.mov,.webm,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx" style="padding:5px 11px;"/><div style="font-size:10.5px;color:var(--muted);margin-top:4px;">PDF, MP4, JPG, PNG, DOCX — max 50 MB</div></div>
            <div id="urUrlGroup" style="display:none;"><input class="form-input" id="urUrl" type="url" placeholder="https://…"/></div>
        </div>
        <div class="form-group"><label>Description</label><textarea class="form-input" id="urDescription" rows="3" placeholder="Brief description…" style="resize:vertical;"></textarea></div>
        <div class="form-group" style="display:flex;align-items:center;gap:9px;"><label class="toggle" style="margin:0;"><input type="checkbox" id="urPublic" checked><span class="toggle-slider"></span></label><span style="font-size:12.5px;">Make Public</span></div>
        <input type="hidden" id="urType" value="pdf">
        <div id="urError" style="color:var(--red);font-size:12px;margin-top:4px;display:none;"></div>
        <div id="urProgress" style="display:none;margin-top:7px;">
            <div style="height:3px;background:var(--ink4);border-radius:4px;overflow:hidden;"><div id="urProgressBar" style="height:100%;width:0%;background:var(--accent);transition:width 0.3s;border-radius:4px;"></div></div>
            <div id="urProgressText" style="font-size:11px;color:var(--muted);margin-top:3px;">Uploading…</div>
        </div>
        <div class="form-actions">
            <button class="btn btn-ghost" onclick="closeModal('modalUploadResource')">Cancel</button>
            <button class="btn btn-primary" id="btnUploadResource" onclick="submitUploadResource()">Upload</button>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/admin-footer.php'; ?>
<script>
const CLOUDINARY_CLOUD='dmysg5azm', CLOUDINARY_PRESET='ptauploads';

document.addEventListener('DOMContentLoaded', async () => {
    await fetchCsrf();
    loadResources();
});

async function loadResources() {
    const grid = document.getElementById('resourceGrid');
    const cached = PageCache.get('resources');
    if (cached) renderResources(cached, grid);
    else grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--muted);">Loading…</div>';
    if (!PageCache.isStale('resources') && cached) return;
    try {
        const d = await apiGet(API.getResources, {limit:20, page:1});
        if (!d.success) { if (!cached) grid.innerHTML=`<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--red);">${esc(d.error||'Failed to load resources.')}</div>`; return; }
        PageCache.set('resources', d); renderResources(d, grid);
    } catch(e) { if (!cached) grid.innerHTML='<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--red);">Network error.</div>'; }
}

function renderResources(d, grid) {
    if (d.stats) setEl('resTotalMaterials', fmtNum(d.stats.total_materials));
    const catBg    = {aptitude:'rgba(59,130,246,0.12)',verbal:'rgba(236,72,153,0.12)',logical:'rgba(34,197,94,0.12)',technical:'rgba(234,179,8,0.12)',general:'rgba(107,114,128,0.12)'};
    const catColor = {aptitude:'#60a5fa',verbal:'#f0abfc',logical:'#4ade80',technical:'#fde047',general:'#9ca3af'};
    function getFileType(m) {
        const pid = m.cloudinary_public_id||''; const ext = (pid.split('.').pop()||'').toLowerCase();
        if (['mp4','webm','ogg','mov'].includes(ext)) return 'video';
        if (['jpg','jpeg','png','gif','webp'].includes(ext)) return 'image';
        if (['doc','docx'].includes(ext)) return 'doc';
        return pid ? 'pdf' : 'link';
    }
    const typeIcon = {pdf:'📄',video:'🎬',image:'🖼️',doc:'📝',link:'🔗'};
    const typeBg   = {pdf:'rgba(59,130,246,0.12)',video:'rgba(234,179,8,0.12)',image:'rgba(236,72,153,0.12)',doc:'rgba(107,114,128,0.12)',link:'rgba(234,179,8,0.12)'};
    let html = d.materials.map(m => {
        const rid=m.material_id, hasFile=!!m.cloudinary_public_id, ftype=getFileType(m);
        const serveBase=`${_base}api/admin/serve-admin-resource.php?resource_id=${rid}`;
        const viewUrl=hasFile?`${serveBase}&action=view`:m.external_url;
        const dlUrl  =hasFile?`${serveBase}&action=download`:m.external_url||null;
        const viewBtn=viewUrl?`<a href="${viewUrl}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">View</a>`:'';
        const dlBtn  =dlUrl?`<a href="${dlUrl}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">Download</a>`:'';
        const cat=(m.category||'general').toLowerCase();
        const catPill=`<span style="display:inline-block;padding:1px 7px;border-radius:99px;font-size:10px;font-weight:600;background:${catBg[cat]||catBg.general};color:${catColor[cat]||catColor.general};margin-left:5px;">${esc(cat)}</span>`;
        const visIcon=m.visibility==='public'?'🌐':'🔒';
        const icon=typeIcon[ftype]||'📄', iconBg=typeBg[ftype]||typeBg.pdf;
        const uploaderName=m.created_by_name||'Unknown', uploaderInitial=uploaderName.trim()[0]?.toUpperCase()||'?';
        const uploaderChip=`<span style="display:inline-flex;align-items:center;gap:5px;"><span style="display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:var(--accent);color:#fff;font-size:9px;font-weight:700;flex-shrink:0;">${uploaderInitial}</span><span style="color:var(--text);font-weight:500;">${esc(uploaderName)}</span></span>`;
        return `<div class="resource-card"><div class="resource-icon" style="background:${iconBg};">${icon}</div><h4>${esc(m.title)}${catPill}</h4><p>${esc(m.description||'—')}</p><div class="resource-meta"><span>${esc(ftype.toUpperCase())} ${visIcon}</span><span style="display:inline-flex;align-items:center;gap:4px;font-size:10.5px;"><span style="color:var(--muted);">by</span> ${uploaderChip}</span></div><div style="display:flex;gap:5px;margin-top:9px;flex-wrap:wrap;">${viewBtn}${dlBtn}<button class="btn btn-danger btn-sm" onclick="deleteResource(${rid},'${esc(m.title)}')">Delete</button></div></div>`;
    }).join('');
    html += `<div class="resource-card" onclick="openUploadModal()" style="border-style:dashed;display:flex;flex-direction:column;align-items:center;justify-content:center;cursor:pointer;min-height:130px;"><div style="font-size:26px;margin-bottom:7px;">+</div><div style="font-size:12.5px;color:var(--muted);">Upload New Resource</div></div>`;
    grid.innerHTML = html;
}

async function deleteResource(resourceId, title) {
    if (!confirm(`Permanently delete "${title}"?\n\nThis cannot be undone.`)) return;
    try {
        const d = await apiPost(API.deleteResource, {resource_id:resourceId});
        if (d.success) { showToast('Resource deleted.','success'); PageCache.invalidate('resources'); loadResources(); }
        else showToast(d.error||'Delete failed.','error');
    } catch(e) { showToast('Network error.','error'); }
}

function urSetMode(mode) {
    const isFile = mode==='file';
    document.getElementById('urFileGroup').style.display = isFile?'block':'none';
    document.getElementById('urUrlGroup').style.display  = isFile?'none':'block';
    document.getElementById('urToggleFile').style.background = isFile?'var(--accent)':'var(--ink3)';
    document.getElementById('urToggleFile').style.color      = isFile?'#fff':'var(--muted)';
    document.getElementById('urToggleUrl').style.background  = isFile?'var(--ink3)':'var(--accent)';
    document.getElementById('urToggleUrl').style.color       = isFile?'var(--muted)':'#fff';
    if (isFile) document.getElementById('urUrl').value='';
    else document.getElementById('urFile').value='';
}

function openUploadModal() {
    ['urTitle','urUrl','urDescription'].forEach(id=>document.getElementById(id).value='');
    document.getElementById('urCategory').value='aptitude';
    document.getElementById('urDifficulty').value='easy';
    document.getElementById('urFile').value='';
    document.getElementById('urPublic').checked=true;
    document.getElementById('urError').style.display='none';
    document.getElementById('urProgress').style.display='none';
    document.getElementById('urProgressBar').style.width='0%';
    document.getElementById('btnUploadResource').disabled=false;
    document.getElementById('btnUploadResource').textContent='Upload';
    urSetMode('file');
    openModal('modalUploadResource');
}

async function uploadToCloudinary(file, onProgress) {
    const fd=new FormData(); fd.append('file',file); fd.append('upload_preset',CLOUDINARY_PRESET); fd.append('folder','pta');
    let resourceType='raw';
    if (file.type.startsWith('video/')) resourceType='video';
    else if (file.type.startsWith('image/')) resourceType='image';
    return new Promise((resolve,reject)=>{
        const xhr=new XMLHttpRequest();
        xhr.open('POST',`https://api.cloudinary.com/v1_1/${CLOUDINARY_CLOUD}/${resourceType}/upload`);
        xhr.upload.addEventListener('progress',e=>{if(e.lengthComputable)onProgress(Math.round(e.loaded/e.total*90));});
        xhr.addEventListener('load',()=>{
            let data; try{data=JSON.parse(xhr.responseText);}catch(e){reject(new Error('Cloudinary returned unexpected response.'));return;}
            if(xhr.status===200&&data.secure_url){onProgress(100);resolve({url:data.secure_url,publicId:data.public_id||'',fileSize:data.bytes||0});}
            else reject(new Error(data?.error?.message||'Cloudinary upload failed.'));
        });
        xhr.addEventListener('error',()=>reject(new Error('Network error during upload.')));
        xhr.send(fd);
    });
}

async function submitUploadResource() {
    const title=document.getElementById('urTitle').value.trim();
    const category=document.getElementById('urCategory').value;
    const diff=document.getElementById('urDifficulty').value;
    const desc=document.getElementById('urDescription').value.trim();
    const isPublic=document.getElementById('urPublic').checked;
    const fileEl=document.getElementById('urFile');
    const urlVal=document.getElementById('urUrl').value.trim();
    const errEl=document.getElementById('urError');
    const showErr=msg=>{errEl.textContent=msg;errEl.style.display='block';};
    errEl.style.display='none';
    if (!title) return showErr('Title is required.');
    if (!fileEl.files[0]&&!urlVal) return showErr('Provide a file or an external URL.');
    const btn=document.getElementById('btnUploadResource');
    const progressWrap=document.getElementById('urProgress');
    const progressBar=document.getElementById('urProgressBar');
    const progressText=document.getElementById('urProgressText');
    btn.disabled=true; btn.textContent='Uploading…'; progressWrap.style.display='block'; progressBar.style.width='0%';
    try {
        let externalUrl=urlVal, publicId='';
        if (fileEl.files[0]) {
            progressText.textContent='Uploading to storage…';
            const result=await uploadToCloudinary(fileEl.files[0],pct=>{progressBar.style.width=pct+'%';progressText.textContent=`Uploading… ${pct}%`;});
            externalUrl=result.url; publicId=result.publicId;
            progressText.textContent='Saving to database…';
        }
        const payload={title,category,difficulty:diff,description:desc,visibility:isPublic?'public':'private',external_url:externalUrl||null,cloudinary_public_id:publicId||null};
        const d=await apiPost(API.uploadResource,payload);
        if (d.success) { progressBar.style.width='100%'; progressText.textContent='Done!'; setTimeout(()=>{closeModal('modalUploadResource');showToast('Resource uploaded successfully.','success');PageCache.invalidate('resources');loadResources();},500); }
        else throw new Error(d.error||'Failed to save resource.');
    } catch(e) { progressWrap.style.display='none'; showErr(e.message); btn.disabled=false; btn.textContent='Upload'; }
}
</script>
</body>
</html>

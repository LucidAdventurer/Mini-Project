<?php
/* ========================================
 * ADMIN USER MANAGEMENT
 * File: admin-users.php
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

$currentPage = 'users';
$pageTitle   = 'User Management — PREPAURA Admin';
require_once __DIR__ . '/admin-head.php';
?>
<body>
<?php require_once __DIR__ . '/admin-sidebar.php'; ?>

    <div class="content">
        <div class="section-header">
            <div><h2>User Management</h2><p>Manage students, teachers, and admins</p></div>
            <div style="display:flex;gap:7px;">
                <button class="btn btn-ghost" onclick="exportUsers()">
                    <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Export
                </button>
                <button class="btn btn-primary" onclick="openAddUserModal()">
                    <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Add User
                </button>
            </div>
        </div>
        <div class="tabs">
            <button class="tab active" onclick="filterByRole('all',this)">All Users</button>
            <button class="tab" onclick="filterByRole('student',this)">Students</button>
            <button class="tab" onclick="filterByRole('teacher',this)">Teachers</button>
            <button class="tab" onclick="filterByRole('admin',this)">Admins</button>
        </div>
        <div class="search-bar">
            <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input id="userSearchInput" placeholder="Search by name, email, registration number…"/>
        </div>
        <div class="card">
            <div class="table-wrap">
                <table>
                    <thead><tr><th>User</th><th>Role</th><th>Department</th><th>Reg. No.</th><th>Last Login</th><th>Verified</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody id="usersTableBody"><tr><td colspan="8" style="text-align:center;padding:28px;color:var(--muted);">Loading users…</td></tr></tbody>
                </table>
            </div>
            <div id="userPagination"></div>
        </div>
    </div>
</div><!-- /main -->

<!-- Add/Edit User Modal -->
<div class="modal-overlay" id="modalAddUser">
    <div class="modal">
        <button class="modal-close" onclick="closeModal('modalAddUser')">✕</button>
        <h3 id="modalUserTitle">Add New User</h3>
        <input type="hidden" id="editUserId" value="">
        <div class="form-row">
            <div class="form-group"><label>Full Name *</label><input class="form-input" id="fFullName" placeholder="e.g. Rahul Kumar"/></div>
            <div class="form-group"><label>Email *</label><input class="form-input" id="fEmail" type="email" placeholder="user@college.edu"/></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label>Role *</label><select class="form-input" id="fRole"><option value="student">Student</option><option value="teacher">Teacher</option><option value="admin">Admin</option></select></div>
            <div class="form-group"><label>Department</label><input class="form-input" id="fDept" placeholder="e.g. CSE"/></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label>Registration Number</label><input class="form-input" id="fRegNo" placeholder="e.g. 21CSE001"/></div>
            <div class="form-group"><label id="fPasswordLabel">Password *</label><input class="form-input" id="fPassword" type="password" placeholder="Min 8 characters"/></div>
        </div>
        <div class="form-row">
            <div class="form-group" style="display:flex;align-items:center;gap:9px;padding-top:20px;"><label class="toggle" style="margin:0;"><input type="checkbox" id="fVerified" checked><span class="toggle-slider"></span></label><span style="font-size:12.5px;">Mark as Verified</span></div>
            <div class="form-group" style="display:flex;align-items:center;gap:9px;padding-top:20px;" id="fActiveRow"><label class="toggle" style="margin:0;"><input type="checkbox" id="fActive" checked><span class="toggle-slider"></span></label><span style="font-size:12.5px;">Active Account</span></div>
        </div>
        <div id="modalError" style="color:var(--red);font-size:12px;margin-top:4px;display:none;"></div>
        <div class="form-actions">
            <button class="btn btn-ghost" onclick="closeModal('modalAddUser')">Cancel</button>
            <button class="btn btn-primary" id="btnSaveUser" onclick="saveUser()">Create User</button>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/admin-footer.php'; ?>
<script>
let userPage=1, userRole='all', userSearch='', searchTimer=null;

document.addEventListener('DOMContentLoaded', async () => {
    await fetchCsrf();
    loadUsers();
    document.getElementById('userSearchInput').addEventListener('input', e => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => { userSearch = e.target.value.trim(); userPage = 1; loadUsers(); }, 350);
    });
});

function filterByRole(role, tabEl) {
    document.querySelectorAll('.tabs .tab').forEach(t => t.classList.remove('active'));
    if (tabEl) tabEl.classList.add('active');
    userRole = role; userPage = 1; loadUsers();
}

async function loadUsers() {
    const tbody = document.getElementById('usersTableBody');
    const cacheKey = `users:${userRole}:${userSearch}:${userPage}`;
    const cached = PageCache.get(cacheKey);
    if (cached) renderUsers(cached, tbody);
    else tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:28px;color:var(--muted);">Loading…</td></tr>`;
    if (!PageCache.isStale(cacheKey) && cached) return;
    try {
        const d = await apiGet(API.getUsers, {role:userRole, search:userSearch, page:userPage, limit:20});
        if (!d.success) { if (!cached) tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;color:var(--red);padding:24px;">${esc(d.error||'Failed to load')}</td></tr>`; return; }
        PageCache.set(cacheKey, d); renderUsers(d, tbody);
    } catch(e) { if (!cached) tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;color:var(--red);padding:24px;">Network error.</td></tr>`; }
}

function renderUsers(d, tbody) {
    const tabs = document.querySelectorAll('.tabs .tab');
    const labels = ['All Users','Students','Teachers','Admins'];
    const keys = ['all','student','teacher','admin'];
    tabs.forEach((tab, i) => { const cnt = d.counts[keys[i]]; tab.textContent = labels[i] + (cnt ? ` (${fmtNum(cnt)})` : ''); });
    tbody.innerHTML = d.users.length
        ? d.users.map(u => renderUserRow(u)).join('')
        : `<tr><td colspan="8" style="text-align:center;padding:28px;color:var(--muted);">No users found.</td></tr>`;
    renderPagination('userPagination', d.page, d.pages, d.total, 'users', p => { userPage = p; loadUsers(); });
}

function renderUserRow(u) {
    const verified = u.is_verified ? `<span class="badge success">✓ Yes</span>` : `<span class="badge warning">Pending</span>`;
    const status   = u.is_active   ? `<span class="badge active">Active</span>` : `<span class="badge inactive">Inactive</span>`;
    const roleBadge = `<span class="badge ${u.role==='admin'?'admin-b':u.role}">${ucwords(u.role)}</span>`;
    const blockBtn  = u.is_active
        ? `<button class="btn btn-danger btn-sm" onclick="toggleStatus(${u.user_id},'block')">Block</button>`
        : `<button class="btn btn-primary btn-sm" onclick="toggleStatus(${u.user_id},'activate')">Activate</button>`;
    return `<tr>
        <td><div class="user-cell"><div class="user-av ${u.avatar_color}">${esc(u.initials)}</div><div><div class="user-name">${esc(u.full_name)}</div><div class="user-email">${esc(u.email)}</div></div></div></td>
        <td>${roleBadge}</td><td>${esc(u.department||'—')}</td><td>${esc(u.registration_number||'—')}</td>
        <td>${esc(u.last_login_ago)}</td><td>${verified}</td><td>${status}</td>
        <td><div style="display:flex;gap:5px;"><button class="btn btn-ghost btn-sm" onclick="openEditUserModal(${JSON.stringify(u).replace(/"/g,'&quot;')})">Edit</button>${u.role!=='admin'?blockBtn:''}</div></td>
    </tr>`;
}

async function toggleStatus(userId, action) {
    if (!confirm(`${action==='block'?'Block':'Activate'} this user?`)) return;
    try {
        const d = await apiPost(API.toggleStatus, {user_id:userId, action});
        if (d.success) { showToast(action==='block'?'User blocked.':'User activated.','success'); PageCache.invalidate(`users:${userRole}:${userSearch}:${userPage}`); loadUsers(); }
        else showToast(d.error||'Action failed.','error');
    } catch(e) { showToast('Network error.','error'); }
}

function openAddUserModal() {
    document.getElementById('modalUserTitle').textContent='Add New User';
    document.getElementById('editUserId').value='';
    ['fFullName','fEmail','fDept','fRegNo','fPassword'].forEach(id=>document.getElementById(id).value='');
    document.getElementById('fRole').value='student';
    document.getElementById('fPasswordLabel').textContent='Password *';
    document.getElementById('fVerified').checked=true;
    document.getElementById('fActive').checked=true;
    document.getElementById('fActiveRow').style.display='none';
    document.getElementById('btnSaveUser').textContent='Create User';
    hideModalError(); openModal('modalAddUser');
}

function openEditUserModal(u) {
    document.getElementById('modalUserTitle').textContent='Edit User';
    document.getElementById('editUserId').value=u.user_id;
    document.getElementById('fFullName').value=u.full_name;
    document.getElementById('fEmail').value=u.email;
    document.getElementById('fRole').value=u.role;
    document.getElementById('fDept').value=u.department||'';
    document.getElementById('fRegNo').value=u.registration_number||'';
    document.getElementById('fPassword').value='';
    document.getElementById('fPasswordLabel').textContent='New Password (leave blank to keep)';
    document.getElementById('fVerified').checked=u.is_verified;
    document.getElementById('fActive').checked=u.is_active;
    document.getElementById('fActiveRow').style.display='flex';
    document.getElementById('btnSaveUser').textContent='Save Changes';
    hideModalError(); openModal('modalAddUser');
}

async function saveUser() {
    const userId=document.getElementById('editUserId').value; const isEdit=!!userId;
    const fullName=document.getElementById('fFullName').value.trim();
    const email=document.getElementById('fEmail').value.trim();
    const role=document.getElementById('fRole').value;
    const dept=document.getElementById('fDept').value.trim();
    const regNo=document.getElementById('fRegNo').value.trim();
    const password=document.getElementById('fPassword').value;
    const verified=document.getElementById('fVerified').checked;
    const active=document.getElementById('fActive').checked;
    if (!fullName||!email) return showModalError('Full name and email are required.');
    if (!isEdit&&!password) return showModalError('Password is required for new users.');
    if (password&&password.length<8) return showModalError('Password must be at least 8 characters.');
    const btn=document.getElementById('btnSaveUser'); btn.disabled=true; btn.textContent='Saving…';
    try {
        const payload={full_name:fullName,email,role,is_verified:verified,is_active:active};
        if (dept) payload.department=dept;
        if (regNo) payload.registration_number=regNo;
        if (password) payload.password=password;
        let d;
        if (isEdit) { payload.user_id=parseInt(userId); d=await apiPost(API.editUser,payload); }
        else d=await apiPost(API.addUser,payload);
        if (d.success) { closeModal('modalAddUser'); showToast(isEdit?'User updated.':'User created.','success'); PageCache.invalidate(`users:${userRole}:${userSearch}:${userPage}`); loadUsers(); }
        else showModalError(d.error||'Operation failed.');
    } catch(e) { showModalError('Network error.'); }
    finally { btn.disabled=false; btn.textContent=isEdit?'Save Changes':'Create User'; }
}

async function exportUsers() {
    try {
        const d=await apiGet(API.getUsers,{role:userRole,search:userSearch,limit:1000,page:1});
        if (!d.success||!d.users.length) return showToast('No users to export.','error');
        const header=['ID','Name','Email','Role','Department','Reg No','Verified','Active','Last Login'];
        const rows=d.users.map(u=>[u.user_id,u.full_name,u.email,u.role,u.department||'',u.registration_number||'',u.is_verified?'Yes':'No',u.is_active?'Yes':'No',u.last_login||'']);
        const csv=[header,...rows].map(r=>r.map(c=>`"${String(c).replace(/"/g,'""')}"`).join(',')).join('\n');
        const a=document.createElement('a'); a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));
        a.download=`users_${new Date().toISOString().slice(0,10)}.csv`; a.click();
        showToast('Export ready.','success');
    } catch(e) { showToast('Export failed.','error'); }
}
</script>
</body>
</html>

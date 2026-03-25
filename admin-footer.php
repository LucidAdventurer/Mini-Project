    <!-- ── TOAST ── -->
    <div class="toast" id="toast"></div>

    <script>
    // ════ SHARED UTILITIES ════
    function setEl(id,html){const el=document.getElementById(id);if(el)el.innerHTML=html;}
    function fmtNum(n){return Number(n).toLocaleString();}
    function esc(s){return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
    function ucwords(s){return s.replace(/\b\w/g,c=>c.toUpperCase());}

    // ── Sidebar mobile toggle ──
    function toggleSidebar(){
        const s=document.querySelector('.sidebar');
        const o=document.getElementById('sidebarOverlay');
        s.classList.toggle('mobile-open');
        o.style.display=s.classList.contains('mobile-open')?'block':'none';
    }
    function closeSidebar(){
        document.querySelector('.sidebar').classList.remove('mobile-open');
        document.getElementById('sidebarOverlay').style.display='none';
    }

    // ── Profile dropdown ──
    function toggleProfileDropdown(e){
        e.stopPropagation();
        document.getElementById('adminChip').classList.toggle('open');
        document.getElementById('profileDropdown').classList.toggle('open');
    }
    document.addEventListener('click',()=>{
        document.getElementById('adminChip')?.classList.remove('open');
        document.getElementById('profileDropdown')?.classList.remove('open');
    });

    // ── Toast ──
    let _toastTimer;
    function showToast(msg,type='success'){
        const t=document.getElementById('toast');
        t.textContent=(type==='success'?'✓  ':'✕  ')+msg;
        t.className=`toast ${type} show`;
        clearTimeout(_toastTimer);
        _toastTimer=setTimeout(()=>t.classList.remove('show'),3200);
    }

    // ── API helpers ──
    let csrfToken='';
    async function fetchCsrf(){
        try{const r=await fetch(_base+'api/csrf-token.php');const d=await r.json();if(d.success)csrfToken=d.token;}
        catch(e){console.error('CSRF fetch failed',e);}
    }
    async function safeJson(response){
        const text=await response.text();
        try{return JSON.parse(text);}
        catch(e){console.error('Non-JSON from:',response.url);return{success:false,error:'Server error — check Console > Network.'};}
    }
    async function apiPost(url,body){
        const r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},body:JSON.stringify(body)});
        return safeJson(r);
    }
    async function apiGet(url,params={}){
        const qs=new URLSearchParams(params).toString();
        const r=await fetch(url+(qs?'?'+qs:''),{credentials:'same-origin'});
        return safeJson(r);
    }

    const _base=window.location.pathname.substring(0,window.location.pathname.lastIndexOf('/')+1);
    const API={
        csrf:            _base+'api/csrf-token.php',
        overview:        _base+'api/admin/get-overview.php',
        getUsers:        _base+'api/admin/get-users.php',
        addUser:         _base+'api/admin/add-user.php',
        editUser:        _base+'api/admin/edit-user.php',
        toggleStatus:    _base+'api/admin/toggle-user-status.php',
        getLogs:         _base+'api/admin/get-logs.php',
        getSettings:     _base+'api/admin/get-settings.php',
        updateSettings:  _base+'api/admin/update-settings.php',
        getTests:        _base+'api/admin/get-tests.php',
        updateTestStatus:_base+'api/admin/update-test-status.php',
        deleteTest:      _base+'api/admin/delete-test.php',
        getTestQuestions:_base+'api/admin/get-test-questions.php',
        getResources:    _base+'api/admin/get-admin-resources.php',
        uploadResource:  _base+'api/admin/upload-admin-resource.php',
        deleteResource:  _base+'api/admin/delete-admin-resource.php',
        serveResource:   _base+'api/resources/serve-resource.php',
        viewResource:    _base+'api/resources/view-resource.php',
    };

    const PageCache=(()=>{
        const TTL={overview:60,users:30,tests:30,resources:60,logs:20,settings:300};
        const store={};
        return{
            get(k){const e=store[k];if(!e)return null;if(Date.now()>e.expiresAt){delete store[k];return null;}return e.data;},
            set(k,d){store[k]={data:d,expiresAt:Date.now()+(TTL[k]??30)*1000};},
            isStale(k){const e=store[k];if(!e)return true;return Date.now()>e.expiresAt;},
            invalidate(k){delete store[k];},
            invalidateMany(keys){keys.forEach(k=>delete store[k]);},
        };
    })();

    // ── Modal helpers ──
    function openModal(id){document.getElementById(id).classList.add('open');}
    function closeModal(id){document.getElementById(id).classList.remove('open');}
    document.addEventListener('click',e=>{if(e.target.classList.contains('modal-overlay'))e.target.classList.remove('open');});
    function showModalError(msg){const el=document.getElementById('modalError');if(el){el.textContent=msg;el.style.display='block';}}
    function hideModalError(){const el=document.getElementById('modalError');if(el){el.textContent='';el.style.display='none';}}

    // ── Pagination helper ──
    function renderPagination(containerId,page,pages,total,label,onPageChangeFn){
        const el=document.getElementById(containerId);if(!el)return;
        if(pages<=1){el.innerHTML='';return;}
        const start=(page-1)*20+1;const end=Math.min(page*20,total);
        const nums=[];for(let p=Math.max(1,page-2);p<=Math.min(pages,page+2);p++)nums.push(p);
        el.className='pagination';
        el.innerHTML=`<span>Showing ${fmtNum(start)}–${fmtNum(end)} of ${fmtNum(total)} ${label}</span><div class="pagination-btns"><button ${page<=1?'disabled':''} onclick="(${onPageChangeFn})(${page-1})">‹ Prev</button>${nums.map(p=>`<button class="${p===page?'current':''}" onclick="(${onPageChangeFn})(${p})">${p}</button>`).join('')}<button ${page>=pages?'disabled':''} onclick="(${onPageChangeFn})(${page+1})">Next ›</button></div>`;
    }
    </script>

<?php
// ============================================================
// assessments.php  — Student Assessment Hub
// Session-protected. Renders the available assessments page.
// CSRF token injected server-side for fetch() calls.
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db-guard.php';

$currentUser = validateSession($conn, 'student');
$csrfToken   = $_SESSION['csrf_token'];
$userName    = htmlspecialchars($currentUser['full_name'], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Assessments — PTA</title>

<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;1,9..40,300&display=swap" rel="stylesheet" />

<style>
/* ─── RESET & TOKENS ─────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:        #0c0f14;
    --surface:   #13171f;
    --surface2:  #1a1f2b;
    --border:    #252b38;
    --border2:   #2f3848;
    --text:      #e8edf5;
    --muted:     #6b7a96;
    --accent:    #4f8fff;
    --accent2:   #7eb8ff;
    --green:     #29d68a;
    --green-dim: #1a3d2e;
    --amber:     #f5a623;
    --amber-dim: #3d2c0f;
    --red:       #ff5252;
    --red-dim:   #3d1414;
    --easy:      #29d68a;
    --medium:    #f5a623;
    --hard:      #ff5252;
    --radius:    14px;
    --radius-sm: 8px;
    --shadow:    0 4px 24px rgba(0,0,0,.45);
    --shadow-lg: 0 12px 48px rgba(0,0,0,.6);
    --font-head: 'Syne', sans-serif;
    --font-body: 'DM Sans', sans-serif;
}

html { scroll-behavior: smooth; }
body {
    font-family: var(--font-body);
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    overflow-x: hidden;
}

/* ─── BACKGROUND MESH ─────────────────────────────── */
body::before {
    content: '';
    position: fixed;
    inset: 0;
    background:
        radial-gradient(ellipse 80% 50% at 10% 0%, rgba(79,143,255,.07) 0%, transparent 60%),
        radial-gradient(ellipse 60% 40% at 90% 100%, rgba(41,214,138,.05) 0%, transparent 60%);
    pointer-events: none;
    z-index: 0;
}

/* ─── NAV ─────────────────────────────────────────── */
.nav {
    position: sticky;
    top: 0;
    z-index: 100;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 2rem;
    height: 64px;
    background: rgba(13,16,21,.85);
    backdrop-filter: blur(16px);
    border-bottom: 1px solid var(--border);
}
.nav-brand {
    font-family: var(--font-head);
    font-size: 1.25rem;
    font-weight: 800;
    letter-spacing: -.02em;
    color: var(--text);
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: .5rem;
}
.nav-brand span {
    display: inline-block;
    width: 28px; height: 28px;
    background: var(--accent);
    border-radius: 7px;
    display: flex; align-items: center; justify-content: center;
    font-size: .8rem; font-weight: 800; color: #fff;
}
.nav-links {
    display: flex;
    align-items: center;
    gap: .25rem;
    list-style: none;
}
.nav-links a {
    display: flex;
    align-items: center;
    gap: .4rem;
    padding: .45rem .85rem;
    border-radius: var(--radius-sm);
    color: var(--muted);
    text-decoration: none;
    font-size: .875rem;
    font-weight: 500;
    transition: color .2s, background .2s;
}
.nav-links a:hover,
.nav-links a.active { color: var(--text); background: var(--surface2); }
.nav-links a.active { color: var(--accent); }
.nav-right {
    display: flex;
    align-items: center;
    gap: .75rem;
}
.avatar {
    width: 34px; height: 34px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), var(--green));
    display: flex; align-items: center; justify-content: center;
    font-family: var(--font-head);
    font-weight: 700; font-size: .8rem;
    color: #fff; cursor: pointer;
    flex-shrink: 0;
}

/* ─── PAGE LAYOUT ─────────────────────────────────── */
.page {
    position: relative;
    z-index: 1;
    max-width: 1240px;
    margin: 0 auto;
    padding: 2.5rem 2rem 5rem;
}

/* ─── PAGE HEADER ─────────────────────────────────── */
.page-header {
    margin-bottom: 2.5rem;
    animation: fadeUp .5s ease both;
}
.page-header h1 {
    font-family: var(--font-head);
    font-size: clamp(1.75rem, 4vw, 2.5rem);
    font-weight: 800;
    letter-spacing: -.03em;
    line-height: 1.15;
    margin-bottom: .5rem;
}
.page-header h1 em {
    font-style: normal;
    background: linear-gradient(90deg, var(--accent), var(--green));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}
.page-header p {
    color: var(--muted);
    font-size: .975rem;
    font-weight: 300;
}

/* ─── FILTER BAR ──────────────────────────────────── */
.filter-bar {
    display: flex;
    align-items: center;
    gap: .75rem;
    margin-bottom: 2rem;
    flex-wrap: wrap;
    animation: fadeUp .5s .08s ease both;
}
.search-wrap {
    position: relative;
    flex: 1;
    min-width: 220px;
}
.search-wrap svg {
    position: absolute;
    left: .85rem;
    top: 50%;
    transform: translateY(-50%);
    color: var(--muted);
    pointer-events: none;
}
.search-wrap input {
    width: 100%;
    padding: .65rem .9rem .65rem 2.5rem;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    color: var(--text);
    font-family: var(--font-body);
    font-size: .875rem;
    outline: none;
    transition: border-color .2s;
}
.search-wrap input:focus { border-color: var(--accent); }
.search-wrap input::placeholder { color: var(--muted); }

.filter-pill-group {
    display: flex;
    gap: .4rem;
    flex-wrap: wrap;
}
.filter-pill {
    padding: .45rem .9rem;
    border-radius: 100px;
    border: 1px solid var(--border);
    background: var(--surface);
    color: var(--muted);
    font-size: .8rem;
    font-weight: 500;
    cursor: pointer;
    transition: all .18s;
    font-family: var(--font-body);
}
.filter-pill:hover { border-color: var(--border2); color: var(--text); }
.filter-pill.active { background: var(--accent); border-color: var(--accent); color: #fff; }

/* ─── SECTION TITLE ───────────────────────────────── */
.section-label {
    display: flex;
    align-items: center;
    gap: .75rem;
    margin-bottom: 1.25rem;
    margin-top: 2.5rem;
}
.section-label:first-of-type { margin-top: 0; }
.section-label h2 {
    font-family: var(--font-head);
    font-size: 1.05rem;
    font-weight: 700;
    letter-spacing: -.01em;
}
.section-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 24px;
    height: 24px;
    padding: 0 7px;
    border-radius: 100px;
    background: var(--surface2);
    border: 1px solid var(--border);
    font-size: .75rem;
    font-weight: 600;
    color: var(--muted);
}
.section-line {
    flex: 1;
    height: 1px;
    background: var(--border);
}

/* ─── GRID ─────────────────────────────────────────── */
.cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 1.25rem;
}

/* ─── CARD ─────────────────────────────────────────── */
.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1.5rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
    transition: border-color .2s, transform .2s, box-shadow .2s;
    animation: fadeUp .4s ease both;
    cursor: default;
}
.card:hover {
    border-color: var(--border2);
    transform: translateY(-2px);
    box-shadow: var(--shadow);
}

/* Card top row */
.card-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: .75rem;
}
.card-badges {
    display: flex;
    gap: .4rem;
    flex-wrap: wrap;
}
.badge {
    display: inline-flex;
    align-items: center;
    gap: .3rem;
    padding: .25rem .6rem;
    border-radius: 100px;
    font-size: .72rem;
    font-weight: 600;
    letter-spacing: .02em;
    text-transform: uppercase;
}
.badge-category {
    background: rgba(79,143,255,.12);
    color: var(--accent2);
    border: 1px solid rgba(79,143,255,.2);
}
.badge-easy   { background: rgba(41,214,138,.1); color: var(--easy);   border: 1px solid rgba(41,214,138,.2); }
.badge-medium { background: rgba(245,166,35,.1);  color: var(--amber);  border: 1px solid rgba(245,166,35,.2); }
.badge-hard   { background: rgba(255,82,82,.1);   color: var(--red);    border: 1px solid rgba(255,82,82,.2); }

/* Card title */
.card-title {
    font-family: var(--font-head);
    font-size: 1.05rem;
    font-weight: 700;
    letter-spacing: -.015em;
    line-height: 1.3;
}
.card-desc {
    font-size: .85rem;
    color: var(--muted);
    line-height: 1.55;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/* Meta row */
.card-meta {
    display: flex;
    gap: 1.25rem;
    flex-wrap: wrap;
}
.meta-item {
    display: flex;
    align-items: center;
    gap: .35rem;
    font-size: .8rem;
    color: var(--muted);
}
.meta-item svg { flex-shrink: 0; }
.meta-item strong { color: var(--text); font-weight: 500; }

/* Divider */
.card-divider {
    height: 1px;
    background: var(--border);
    margin: 0 -.25rem;
}

/* Result block (for attended) */
.result-block {
    background: var(--surface2);
    border-radius: var(--radius-sm);
    padding: 1rem;
    display: flex;
    align-items: center;
    gap: 1rem;
}
.result-score-ring {
    flex-shrink: 0;
    width: 56px; height: 56px;
    border-radius: 50%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    border: 3px solid;
    gap: 0;
}
.result-score-ring.pass { border-color: var(--green); }
.result-score-ring.fail { border-color: var(--red); }
.ring-pct {
    font-family: var(--font-head);
    font-size: .95rem;
    font-weight: 800;
    line-height: 1;
}
.ring-pct.pass { color: var(--green); }
.ring-pct.fail { color: var(--red); }
.ring-label { font-size: .6rem; color: var(--muted); font-weight: 500; }

.result-stats {
    flex: 1;
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: .35rem .75rem;
}
.rstat {
    display: flex;
    flex-direction: column;
    gap: .1rem;
}
.rstat-val {
    font-family: var(--font-head);
    font-size: 1rem;
    font-weight: 700;
}
.rstat-val.green { color: var(--green); }
.rstat-val.red   { color: var(--red); }
.rstat-val.amber { color: var(--amber); }
.rstat-lbl {
    font-size: .7rem;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .04em;
}

.pass-badge {
    display: inline-flex;
    align-items: center;
    gap: .3rem;
    padding: .2rem .6rem;
    border-radius: 100px;
    font-size: .7rem;
    font-weight: 600;
    margin-top: .35rem;
}
.pass-badge.pass { background: var(--green-dim); color: var(--green); }
.pass-badge.fail { background: var(--red-dim);   color: var(--red); }

/* Card footer */
.card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .75rem;
}
.attempt-info {
    font-size: .78rem;
    color: var(--muted);
}
.attempt-info strong { color: var(--text); }

/* Buttons */
.btn {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    padding: .55rem 1.15rem;
    border-radius: var(--radius-sm);
    font-family: var(--font-body);
    font-size: .85rem;
    font-weight: 600;
    text-decoration: none;
    border: none;
    cursor: pointer;
    transition: all .18s;
    flex-shrink: 0;
}
.btn-primary {
    background: var(--accent);
    color: #fff;
}
.btn-primary:hover { background: #6aa5ff; }
.btn-outline {
    background: transparent;
    border: 1px solid var(--border2);
    color: var(--text);
}
.btn-outline:hover { background: var(--surface2); border-color: var(--accent); color: var(--accent); }
.btn-ghost {
    background: transparent;
    color: var(--muted);
}
.btn-ghost:hover { color: var(--text); }
.btn:disabled, .btn[disabled] {
    opacity: .45;
    cursor: not-allowed;
    pointer-events: none;
}

/* ─── EMPTY STATE ─────────────────────────────────── */
.empty-state {
    grid-column: 1 / -1;
    text-align: center;
    padding: 4rem 2rem;
    color: var(--muted);
}
.empty-state .empty-icon {
    font-size: 2.5rem;
    margin-bottom: 1rem;
    opacity: .5;
}
.empty-state h3 {
    font-family: var(--font-head);
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text);
    margin-bottom: .4rem;
}
.empty-state p { font-size: .875rem; }

/* ─── SKELETON ────────────────────────────────────── */
.skeleton-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 1.25rem;
}
.skeleton-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1.5rem;
    display: flex;
    flex-direction: column;
    gap: .85rem;
}
.skel {
    background: linear-gradient(90deg, var(--surface2) 25%, var(--border) 50%, var(--surface2) 75%);
    background-size: 200% 100%;
    border-radius: 6px;
    animation: shimmer 1.5s infinite;
}
@keyframes shimmer { to { background-position: -200% 0; } }

/* ─── TOAST ───────────────────────────────────────── */
.toast-container {
    position: fixed;
    bottom: 1.5rem;
    right: 1.5rem;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: .5rem;
    pointer-events: none;
}
.toast {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: .8rem 1.1rem;
    background: var(--surface);
    border: 1px solid var(--border2);
    border-radius: var(--radius-sm);
    box-shadow: var(--shadow-lg);
    font-size: .875rem;
    font-weight: 500;
    animation: slideIn .25s ease;
    pointer-events: all;
    max-width: 340px;
}
.toast.error { border-color: var(--red); }
.toast.success { border-color: var(--green); }
@keyframes slideIn { from { opacity: 0; transform: translateX(1rem); } }

/* ─── AVAILABILITY CHIP ───────────────────────────── */
.avail-chip {
    display: inline-flex;
    align-items: center;
    gap: .3rem;
    font-size: .72rem;
    color: var(--amber);
    background: var(--amber-dim);
    border: 1px solid rgba(245,166,35,.2);
    border-radius: 100px;
    padding: .2rem .55rem;
}

/* ─── ANIMATIONS ──────────────────────────────────── */
@keyframes fadeUp {
    from { opacity: 0; transform: translateY(16px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* stagger cards */
.card:nth-child(1)  { animation-delay: .03s; }
.card:nth-child(2)  { animation-delay: .06s; }
.card:nth-child(3)  { animation-delay: .09s; }
.card:nth-child(4)  { animation-delay: .12s; }
.card:nth-child(5)  { animation-delay: .15s; }
.card:nth-child(6)  { animation-delay: .18s; }

/* ─── RESPONSIVE ──────────────────────────────────── */
@media (max-width: 640px) {
    .nav { padding: 0 1rem; }
    .nav-links { display: none; }
    .page { padding: 1.5rem 1rem 4rem; }
    .cards-grid, .skeleton-grid { grid-template-columns: 1fr; }
    .result-stats { grid-template-columns: 1fr 1fr; }
}
</style>
</head>
<body>

<!-- ─── NAV ─────────────────────────────────────────── -->
<nav class="nav">
    <a class="nav-brand" href="dashboard.php">
        <span>P</span> PTA
    </a>
    <ul class="nav-links">
        <li><a href="dashboard.php">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
            Dashboard
        </a></li>
        <li><a href="assessments.php" class="active">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
            Assessments
        </a></li>
        <li><a href="materials.php">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>
            Materials
        </a></li>
        <li><a href="results.php">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            My Results
        </a></li>
    </ul>
    <div class="nav-right">
        <div class="avatar" title="<?= $userName ?>"><?= strtoupper(mb_substr($currentUser['full_name'], 0, 2)) ?></div>
    </div>
</nav>

<!-- ─── PAGE ─────────────────────────────────────────── -->
<main class="page">

    <header class="page-header">
        <h1>Your <em>Assessments</em></h1>
        <p>All available tests — pending ones on top so you never miss a deadline.</p>
    </header>

    <!-- Filter bar -->
    <div class="filter-bar">
        <div class="search-wrap">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" id="searchInput" placeholder="Search assessments…" autocomplete="off" />
        </div>
        <div class="filter-pill-group" id="categoryFilter">
            <button class="filter-pill active" data-cat="all">All</button>
            <button class="filter-pill" data-cat="aptitude">Aptitude</button>
            <button class="filter-pill" data-cat="technical">Technical</button>
            <button class="filter-pill" data-cat="coding">Coding</button>
            <button class="filter-pill" data-cat="reasoning">Reasoning</button>
            <button class="filter-pill" data-cat="english">English</button>
            <button class="filter-pill" data-cat="general">General</button>
        </div>
    </div>

    <!-- ── NOT ATTENDED ── -->
    <div class="section-label">
        <h2>Pending</h2>
        <span class="section-count" id="pendingCount">—</span>
        <div class="section-line"></div>
    </div>

    <div class="cards-grid" id="pendingGrid">
        <!-- Skeleton -->
        <div class="skeleton-card" id="skel1"><div class="skel" style="height:20px;width:60%"></div><div class="skel" style="height:14px;width:80%"></div><div class="skel" style="height:14px;width:40%"></div><div class="skel" style="height:36px;margin-top:.5rem"></div></div>
        <div class="skeleton-card" id="skel2"><div class="skel" style="height:20px;width:55%"></div><div class="skel" style="height:14px;width:75%"></div><div class="skel" style="height:14px;width:50%"></div><div class="skel" style="height:36px;margin-top:.5rem"></div></div>
        <div class="skeleton-card" id="skel3"><div class="skel" style="height:20px;width:65%"></div><div class="skel" style="height:14px;width:70%"></div><div class="skel" style="height:14px;width:45%"></div><div class="skel" style="height:36px;margin-top:.5rem"></div></div>
    </div>

    <!-- ── ATTENDED ── -->
    <div class="section-label" style="margin-top:3rem">
        <h2>Completed</h2>
        <span class="section-count" id="completedCount">—</span>
        <div class="section-line"></div>
    </div>

    <div class="cards-grid" id="completedGrid">
        <!-- filled by JS -->
    </div>

</main>

<!-- Toast container -->
<div class="toast-container" id="toastContainer"></div>

<script>
// ── Server-injected state ──────────────────────────
const CSRF = <?= json_encode($csrfToken) ?>;
const USER = <?= json_encode(['name' => $currentUser['full_name'], 'id' => (int)$currentUser['user_id']]) ?>;

// ── State ─────────────────────────────────────────
let allPending   = [];
let allCompleted = [];
let currentCat   = 'all';
let searchQuery  = '';

// ── Toast ─────────────────────────────────────────
function showToast(msg, type = 'info') {
    const tc   = document.getElementById('toastContainer');
    const icon = type === 'error'   ? '✕'
               : type === 'success' ? '✓' : 'i';
    const t    = document.createElement('div');
    t.className = `toast ${type}`;
    t.innerHTML = `<span>${icon}</span><span>${msg}</span>`;
    tc.appendChild(t);
    setTimeout(() => t.remove(), 4000);
}

// ── Icon helpers ───────────────────────────────────
const icons = {
    clock  : `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>`,
    list   : `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>`,
    star   : `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>`,
    user   : `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>`,
    refresh: `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>`,
    play   : `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>`,
    eye    : `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`,
    cal    : `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>`,
};

// ── Format helpers ─────────────────────────────────
function fmtDuration(mins) {
    if (mins < 60) return `${mins}m`;
    const h = Math.floor(mins / 60), m = mins % 60;
    return m ? `${h}h ${m}m` : `${h}h`;
}
function fmtDate(dt) {
    if (!dt) return null;
    return new Date(dt).toLocaleDateString('en-IN', { day:'numeric', month:'short', year:'numeric' });
}
function capFirst(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : s; }

// ── Build pending card ─────────────────────────────
function buildPendingCard(a) {
    const canAttempt = a.can_attempt;
    const diffClass  = `badge-${a.difficulty}`;
    const availUntil = a.available_until ? fmtDate(a.available_until) : null;
    const resume     = !!a.in_progress_attempt_id;

    let availChip = '';
    if (availUntil) {
        availChip = `<span class="avail-chip">${icons.cal} Ends ${availUntil}</span>`;
    }

    let footerBtn = '';
    if (resume) {
        footerBtn = `<a class="btn btn-primary" href="take-assessment.php?id=${a.assessment_id}&attempt=${a.in_progress_attempt_id}">${icons.play} Resume</a>`;
    } else if (canAttempt) {
        footerBtn = `<a class="btn btn-primary" href="take-assessment.php?id=${a.assessment_id}">${icons.play} Start</a>`;
    } else {
        footerBtn = `<button class="btn btn-outline" disabled>No Attempts Left</button>`;
    }

    return `
    <article class="card" data-cat="${a.category}" data-title="${a.title.toLowerCase()}">
        <div class="card-top">
            <div class="card-badges">
                <span class="badge badge-category">${capFirst(a.category)}</span>
                <span class="badge ${diffClass}">${capFirst(a.difficulty)}</span>
            </div>
            ${availChip}
        </div>

        <div>
            <h3 class="card-title">${escHtml(a.title)}</h3>
            ${a.description ? `<p class="card-desc">${escHtml(a.description)}</p>` : ''}
        </div>

        <div class="card-meta">
            <span class="meta-item">${icons.clock} <strong>${fmtDuration(a.duration_minutes)}</strong></span>
            <span class="meta-item">${icons.list} <strong>${a.question_count}</strong> Qs</span>
            <span class="meta-item">${icons.star} <strong>${a.total_marks}</strong> marks</span>
            <span class="meta-item">${icons.user} ${escHtml(a.created_by_name)}</span>
        </div>

        <div class="card-divider"></div>

        <div class="card-footer">
            <span class="attempt-info">
                <strong>${a.attempts_used}</strong>/${a.max_attempts} attempts used
                ${resume ? ' &mdash; <span style="color:var(--amber)">In progress</span>' : ''}
            </span>
            ${footerBtn}
        </div>
    </article>`;
}

// ── Build completed card ───────────────────────────
function buildCompletedCard(a) {
    const b        = a.best_attempt;
    const pct      = b.percentage.toFixed(1);
    const passClass = b.passed ? 'pass' : 'fail';
    const diffClass = `badge-${a.difficulty}`;
    const canRetry  = a.can_attempt;

    let retryBtn = '';
    if (canRetry) {
        retryBtn = `<a class="btn btn-outline" href="take-assessment.php?id=${a.assessment_id}">${icons.refresh} Retry</a>`;
    }
    let viewBtn = a.show_results_immediately
        ? `<a class="btn btn-ghost" href="test-results.php?attempt_id=${b.attempt_id}">${icons.eye} View</a>`
        : '';

    return `
    <article class="card" data-cat="${a.category}" data-title="${a.title.toLowerCase()}">
        <div class="card-top">
            <div class="card-badges">
                <span class="badge badge-category">${capFirst(a.category)}</span>
                <span class="badge ${diffClass}">${capFirst(a.difficulty)}</span>
            </div>
        </div>

        <div>
            <h3 class="card-title">${escHtml(a.title)}</h3>
            ${a.description ? `<p class="card-desc">${escHtml(a.description)}</p>` : ''}
        </div>

        <div class="result-block">
            <div class="result-score-ring ${passClass}">
                <span class="ring-pct ${passClass}">${pct}%</span>
                <span class="ring-label">score</span>
            </div>
            <div style="flex:1">
                <div class="result-stats">
                    <div class="rstat"><span class="rstat-val green">${b.correct}</span><span class="rstat-lbl">Correct</span></div>
                    <div class="rstat"><span class="rstat-val red">${b.wrong}</span><span class="rstat-lbl">Wrong</span></div>
                    <div class="rstat"><span class="rstat-val amber">${b.unanswered}</span><span class="rstat-lbl">Skipped</span></div>
                </div>
                <span class="pass-badge ${passClass}">${b.passed ? '✓ Passed' : '✕ Failed'}</span>
            </div>
        </div>

        <div class="card-meta">
            <span class="meta-item">${icons.clock} <strong>${fmtDuration(a.duration_minutes)}</strong></span>
            <span class="meta-item">${icons.list} <strong>${a.question_count}</strong> Qs</span>
            <span class="meta-item">${icons.star} <strong>${b.score.toFixed(0)}/${a.total_marks}</strong></span>
        </div>

        <div class="card-divider"></div>

        <div class="card-footer">
            <span class="attempt-info">
                Attempt <strong>${b.attempt_number}</strong> &bull; ${fmtDate(b.submitted_at)}
            </span>
            <div style="display:flex;gap:.5rem">
                ${viewBtn}
                ${retryBtn}
            </div>
        </div>
    </article>`;
}

// ── XSS helper ────────────────────────────────────
function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
}

// ── Filter & render ────────────────────────────────
function applyFilters() {
    const q = searchQuery.toLowerCase().trim();

    function match(a) {
        if (currentCat !== 'all' && a.category !== currentCat) return false;
        if (q && !a.title.toLowerCase().includes(q) && !(a.description || '').toLowerCase().includes(q)) return false;
        return true;
    }

    const pending   = allPending.filter(match);
    const completed = allCompleted.filter(match);

    document.getElementById('pendingCount').textContent   = pending.length;
    document.getElementById('completedCount').textContent = completed.length;

    const pg = document.getElementById('pendingGrid');
    const cg = document.getElementById('completedGrid');

    pg.innerHTML = pending.length
        ? pending.map(buildPendingCard).join('')
        : `<div class="empty-state"><div class="empty-icon">🎉</div><h3>All done!</h3><p>No pending assessments match your filter.</p></div>`;

    cg.innerHTML = completed.length
        ? completed.map(buildCompletedCard).join('')
        : `<div class="empty-state"><div class="empty-icon">📋</div><h3>Nothing here yet</h3><p>Complete an assessment to see your results.</p></div>`;
}

// ── Fetch data ─────────────────────────────────────
async function loadAssessments() {
    try {
        const res  = await fetch('api/assessment/get-assessments.php', {
            headers: {
                'X-CSRF-Token'    : CSRF,
                'X-Requested-With': 'XMLHttpRequest',
            }
        });
        const data = await res.json();

        if (!data.success) throw new Error(data.error || 'Failed to load');

        allPending   = data.not_attended;
        allCompleted = data.attended;

        applyFilters();

    } catch (err) {
        showToast(err.message || 'Could not load assessments', 'error');
        document.getElementById('pendingGrid').innerHTML =
            `<div class="empty-state"><div class="empty-icon">⚠️</div><h3>Error</h3><p>${escHtml(err.message)}</p></div>`;
        document.getElementById('completedGrid').innerHTML = '';
    }
}

// ── Event listeners ────────────────────────────────
document.getElementById('searchInput').addEventListener('input', e => {
    searchQuery = e.target.value;
    applyFilters();
});

document.getElementById('categoryFilter').addEventListener('click', e => {
    const pill = e.target.closest('.filter-pill');
    if (!pill) return;
    document.querySelectorAll('.filter-pill').forEach(p => p.classList.remove('active'));
    pill.classList.add('active');
    currentCat = pill.dataset.cat;
    applyFilters();
});

// ── Boot ───────────────────────────────────────────
loadAssessments();
</script>
</body>
</html>

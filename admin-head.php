<?php
/* ========================================
 * ADMIN SHARED HEAD / CSS INCLUDE
 * File: admin-head.php
 *
 * Requires $pageTitle to be set before including.
 * ======================================== */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle ?? 'Admin — PREPAURA') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;1,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{
    --ink:#0d0f14;--ink2:#13161e;--ink3:#191d28;--ink4:#1f2335;
    --line:rgba(255,255,255,0.055);--line2:rgba(255,255,255,0.09);
    --text:#dde2ed;--muted:#566073;--dim:#8d99ae;
    --accent:#3b82f6;--accent2:#60a5fa;--acc-glow:rgba(59,130,246,0.15);
    --green:#22c55e;--yellow:#eab308;--red:#ef4444;--orange:#f97316;--purple:#a855f7;
    --sidebar-w:232px;
}
[data-theme="light"]{
    --ink:#f0f2f7;--ink2:#ffffff;--ink3:#e8edf5;--ink4:#d8dfe9;
    --line:rgba(0,0,0,0.08);--line2:rgba(0,0,0,0.13);
    --text:#0f1117;--muted:#6b7a96;--dim:#4a5568;
    --accent:#2563eb;--accent2:#1d4ed8;--acc-glow:rgba(37,99,235,0.12);
    --green:#16a34a;--yellow:#ca8a04;--red:#dc2626;--orange:#ea580c;--purple:#7c3aed;
}
[data-theme="light"] body::before{background-image:linear-gradient(rgba(37,99,235,0.04) 1px,transparent 1px),linear-gradient(90deg,rgba(37,99,235,0.04) 1px,transparent 1px);}
[data-theme="light"] .topbar{background:rgba(240,242,247,0.92);}
[data-theme="light"] .profile-dropdown{box-shadow:0 16px 48px rgba(0,0,0,0.15),0 0 0 1px var(--line2);}
[data-theme="light"] .dd-header{background:linear-gradient(135deg,var(--ink3) 0%,var(--ink4) 100%);}
[data-theme="light"] .modal-overlay{background:rgba(0,0,0,0.45);}
[data-theme="light"] .toast{box-shadow:0 8px 28px rgba(0,0,0,0.15);}
[data-theme="light"] .tab.active{box-shadow:0 1px 4px rgba(0,0,0,0.12);}
[data-theme="light"] .user-av.blue{background:#2563eb;}
[data-theme="light"] .user-av.green{background:#16a34a;}
[data-theme="light"] .user-av.purple{background:#7c3aed;}
[data-theme="light"] .user-av.orange{background:#ea580c;}
[data-theme="light"] .badge.inactive,.badge.draft{color:#64748b;}
[data-theme="light"] .stat-card:hover{box-shadow:0 4px 16px rgba(0,0,0,0.08);}
[data-theme="light"] select.form-input option{background:var(--ink3);color:var(--text);}
/* Theme toggle button */
.theme-toggle{width:32px;height:32px;border-radius:7px;background:var(--ink3);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--muted);transition:color 0.15s,border-color 0.15s;flex-shrink:0;}
.theme-toggle:hover{color:var(--text);border-color:var(--line2);}
.theme-toggle svg{width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;position:absolute;transition:opacity 0.2s,transform 0.2s;}
.theme-toggle{position:relative;}
.theme-toggle .icon-sun{opacity:0;transform:rotate(90deg) scale(0.7);}
.theme-toggle .icon-moon{opacity:1;transform:rotate(0deg) scale(1);}
[data-theme="light"] .theme-toggle .icon-sun{opacity:1;transform:rotate(0deg) scale(1);}
[data-theme="light"] .theme-toggle .icon-moon{opacity:0;transform:rotate(-90deg) scale(0.7);}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'DM Sans',sans-serif;background:var(--ink);color:var(--text);min-height:100vh;display:flex;overflow-x:hidden;}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background-image:linear-gradient(rgba(59,130,246,0.025) 1px,transparent 1px),linear-gradient(90deg,rgba(59,130,246,0.025) 1px,transparent 1px);background-size:52px 52px;}

/* ── SIDEBAR ── */
.sidebar{width:var(--sidebar-w);background:var(--ink2);border-right:1px solid var(--line);display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;transition:transform 0.3s ease;}
.sidebar-brand{padding:20px 18px 16px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:11px;}
.brand-logo{width:34px;height:34px;background:linear-gradient(135deg,rgba(59,130,246,0.3),rgba(59,130,246,0.1));border:1px solid rgba(59,130,246,0.3);border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.brand-logo svg{width:17px;height:17px;}
.brand-text{font-family: 'Times New Roman', Times, serif;font-size:16px;font-weight:800;letter-spacing:-0.01em;color:var(--text);}
.brand-text span{font-family:'DM Sans',sans-serif;font-size:16px;font-weight:400;color:var(--muted);display:block;letter-spacing:0;}
.sidebar-nav{flex:1;padding:12px 8px;overflow-y:auto;display:flex;flex-direction:column;gap:1px;}
.nav-section-label{font-size:11px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:var(--muted);padding:10px 10px 5px;margin-top:4px;}
.nav-item{display:flex;align-items:center;gap:9px;padding:8px 11px;border-radius:7px;cursor:pointer;transition:background 0.15s,color 0.15s;color:var(--muted);font-size:17px;font-weight:500;user-select:none;text-decoration:none;position:relative;}
.nav-item:hover{background:var(--ink3);color:var(--dim);}
.nav-item.active{background:rgba(59,130,246,0.12);color:var(--accent2);border:1px solid rgba(59,130,246,0.18);}
.nav-item svg{width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2;flex-shrink:0;}
.nav-badge{margin-left:auto;background:var(--red);color:white;font-size:11px;font-weight:700;padding:1px 5px;border-radius:9px;min-width:17px;text-align:center;}
.nav-badge.blue{background:var(--accent);}
.sidebar-bottom{padding:10px 8px;border-top:1px solid var(--line);display:flex;flex-direction:column;gap:1px;}

/* ── MAIN ── */
.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-height:100vh;position:relative;z-index:1;}

/* ── TOPBAR ── */
.topbar{height:54px;background:rgba(13,15,20,0.9);backdrop-filter:blur(12px);border-bottom:1px solid var(--line);display:flex;align-items:center;padding:0 26px;gap:14px;position:sticky;top:0;z-index:50;}
.topbar-title{font-family: 'Times New Roman', Times, serif;font-size:16px;font-weight:700;color:var(--text);flex:1;}
.topbar-title span{color:var(--muted);font-weight:400;font-size:17px;font-family:'DM Sans',sans-serif;}
.topbar-actions{display:flex;align-items:center;gap:8px;}
.icon-btn{width:32px;height:32px;border-radius:7px;background:var(--ink3);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--muted);transition:color 0.15s,border-color 0.15s;}
.icon-btn:hover{color:var(--text);border-color:var(--line2);}
.icon-btn svg{width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;}

/* ── Profile dropdown ── */
.admin-chip{display:flex;align-items:center;gap:7px;background:var(--ink3);border:1px solid var(--line);border-radius:20px;padding:4px 11px 4px 4px;cursor:pointer;}
.admin-avatar{width:24px;height:24px;background:var(--accent);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700;color:white;}
.admin-chip span{font-size:16px;font-weight:600;color:var(--text);}
.admin-chip-caret{width:12px;height:12px;stroke:var(--muted);fill:none;stroke-width:2;transition:transform 0.2s;}
.admin-chip.open .admin-chip-caret{transform:rotate(180deg);}
.profile-wrap{position:relative;}
.profile-dropdown{position:absolute;top:calc(100% + 10px);right:0;background:var(--ink3);border-radius:10px;box-shadow:0 16px 48px rgba(0,0,0,0.5),0 0 0 1px var(--line2);min-width:220px;opacity:0;visibility:hidden;transform:translateY(-6px) scale(0.98);transition:opacity 0.18s,visibility 0.18s,transform 0.18s;z-index:200;overflow:hidden;}
.profile-dropdown.open{opacity:1;visibility:visible;transform:translateY(0) scale(1);}
.dd-header{padding:16px 18px;background:linear-gradient(135deg,var(--ink) 0%,var(--ink4) 100%);border-bottom:1px solid var(--line);}
.dd-avatar{width:40px;height:40px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;font-family: 'Times New Roman', Times, serif;font-weight:700;font-size:17px;color:white;margin-bottom:10px;}
.dd-name{font-size:17px;font-weight:600;color:var(--text);}
.dd-email{font-size:17px;color:var(--muted);margin-top:2px;}
.dd-role{display:inline-block;margin-top:7px;padding:2px 10px;background:rgba(59,130,246,0.12);border:1px solid rgba(59,130,246,0.25);color:var(--accent2);border-radius:20px;font-size:12px;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;}
.dd-menu{padding:5px 0;}
.dd-item{display:flex;align-items:center;gap:10px;padding:9px 16px;color:var(--dim);text-decoration:none;font-size:17px;transition:background 0.15s,color 0.15s;cursor:pointer;border:none;background:none;width:100%;text-align:left;font-family:'DM Sans',sans-serif;}
.dd-item svg{width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;flex-shrink:0;}
.dd-item:hover{background:var(--ink4);color:var(--text);}
.dd-item.danger{color:var(--red);}
.dd-item.danger:hover{background:rgba(239,68,68,0.08);}
.dd-divider{height:1px;background:var(--line);margin:4px 0;}

/* ── CONTENT ── */
.content{padding:26px;flex:1;}

/* ── SECTION HEADER ── */
.section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px;}
.section-header h2{font-family: 'Times New Roman', Times, serif;font-size:22px;font-weight:800;letter-spacing:-0.03em;}
.section-header p{font-size:16px;color:var(--muted);margin-top:3px;}

/* ── STATS GRID ── */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px;}
.stat-card{background:var(--ink2);border:1px solid var(--line);border-radius:12px;padding:18px;position:relative;overflow:hidden;transition:border-color 0.2s,transform 0.2s;}
.stat-card:hover{border-color:var(--line2);transform:translateY(-1px);}
.stat-card::before{content:'';position:absolute;top:0;right:0;width:70px;height:70px;border-radius:50%;filter:blur(28px);opacity:0.25;pointer-events:none;}
.stat-card.blue::before{background:var(--accent);}.stat-card.green::before{background:var(--green);}.stat-card.yellow::before{background:var(--yellow);}.stat-card.red::before{background:var(--red);}
.stat-icon{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;margin-bottom:12px;}
.stat-icon svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;}
.stat-card.blue .stat-icon{background:rgba(59,130,246,0.15);border:1px solid rgba(59,130,246,0.25);color:var(--accent2);}
.stat-card.green .stat-icon{background:rgba(34,197,94,0.12);border:1px solid rgba(34,197,94,0.25);color:var(--green);}
.stat-card.yellow .stat-icon{background:rgba(234,179,8,0.12);border:1px solid rgba(234,179,8,0.25);color:var(--yellow);}
.stat-card.red .stat-icon{background:rgba(239,68,68,0.12);border:1px solid rgba(239,68,68,0.25);color:var(--red);}
.stat-value{font-family: 'Times New Roman', Times, serif;font-size:28px;font-weight:800;color:var(--text);line-height:1;margin-bottom:4px;letter-spacing:-0.03em;}
.stat-label{font-size:17px;color:var(--muted);margin-bottom:6px;}
.stat-change{font-size:17px;display:flex;align-items:center;gap:4px;}
.stat-change.up{color:var(--green);}.stat-change.down{color:var(--red);}

/* ── CARD ── */
.card{background:var(--ink2);border:1px solid var(--line);border-radius:12px;overflow:hidden;}
.card-header{padding:16px 18px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;}
.card-header h3{font-family: 'Times New Roman', Times, serif;font-size:17px;font-weight:700;}
.card-header p{font-size:17px;color:var(--muted);margin-top:2px;}
.card-body{padding:18px;}

/* ── LAYOUT GRIDS ── */
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;}
.three-col{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px;}

/* ── TABLE ── */
.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:16px;}
thead th{padding:9px 14px;text-align:left;font-size:16px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--muted);background:var(--ink3);border-bottom:1px solid var(--line);}
tbody tr{border-bottom:1px solid var(--line);transition:background 0.12s;}
tbody tr:hover{background:var(--ink3);}
tbody tr:last-child{border-bottom:none;}
tbody td{padding:10px 14px;color:var(--text);vertical-align:middle;}
.user-cell{display:flex;align-items:center;gap:9px;}
.user-av{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700;color:white;flex-shrink:0;}
.user-av.blue{background:#1d4ed8;}.user-av.green{background:#15803d;}.user-av.purple{background:#7c3aed;}.user-av.orange{background:#c2410c;}
.user-name{font-size:16px;font-weight:600;}
.user-email{font-size:17px;color:var(--muted);}

/* ── BADGES ── */
.badge{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:20px;font-size:16px;font-weight:600;}
.badge.active,.badge.published,.badge.success{background:rgba(34,197,94,0.1);color:var(--green);border:1px solid rgba(34,197,94,0.22);}
.badge.inactive,.badge.draft{background:rgba(107,114,128,0.1);color:#9ca3af;border:1px solid rgba(107,114,128,0.18);}
.badge.student,.badge.info{background:rgba(59,130,246,0.1);color:var(--accent2);border:1px solid rgba(59,130,246,0.2);}
.badge.teacher{background:rgba(168,85,247,0.1);color:#c084fc;border:1px solid rgba(168,85,247,0.2);}
.badge.admin-b{background:rgba(239,68,68,0.1);color:#fca5a5;border:1px solid rgba(239,68,68,0.18);}
.badge.easy{background:rgba(34,197,94,0.1);color:var(--green);border:1px solid rgba(34,197,94,0.2);}
.badge.medium{background:rgba(234,179,8,0.1);color:var(--yellow);border:1px solid rgba(234,179,8,0.2);}
.badge.hard{background:rgba(239,68,68,0.1);color:var(--red);border:1px solid rgba(239,68,68,0.2);}
.badge.archived,.badge.warning{background:rgba(249,115,22,0.1);color:var(--orange);border:1px solid rgba(249,115,22,0.2);}
.badge.error{background:rgba(239,68,68,0.1);color:var(--red);border:1px solid rgba(239,68,68,0.2);}

/* ── BAR CHART ── */
.bar-chart{display:flex;flex-direction:column;gap:11px;}
.bar-row{display:flex;align-items:center;gap:9px;}
.bar-label{font-size:17px;color:var(--muted);width:88px;flex-shrink:0;}
.bar-track{flex:1;height:5px;background:var(--ink4);border-radius:10px;overflow:hidden;}
.bar-fill{height:100%;border-radius:10px;background:var(--accent);transition:width 0.9s ease;}
.bar-fill.green{background:var(--green);}.bar-fill.yellow{background:var(--yellow);}
.bar-val{font-size:17px;font-weight:700;color:var(--text);width:30px;text-align:right;}

/* ── ACTIVITY LIST ── */
.activity-list{display:flex;flex-direction:column;gap:0;}
.activity-item{display:flex;align-items:flex-start;gap:11px;padding:11px 0;border-bottom:1px solid var(--line);}
.activity-item:last-child{border-bottom:none;}
.activity-dot{width:7px;height:7px;border-radius:50%;margin-top:5px;flex-shrink:0;}
.activity-dot.blue{background:var(--accent);}.activity-dot.green{background:var(--green);}.activity-dot.yellow{background:var(--yellow);}.activity-dot.red{background:var(--red);}
.activity-text{font-size:16px;color:var(--text);line-height:1.5;}
.activity-time{font-size:16px;color:var(--muted);margin-top:2px;}

/* ── DONUT ── */
.donut-wrap{display:flex;align-items:center;gap:20px;}
.donut{width:90px;height:90px;border-radius:50%;background:conic-gradient(var(--accent) 0% 62%,var(--green) 62% 82%,var(--yellow) 82% 92%,var(--red) 92% 100%);position:relative;flex-shrink:0;}
.donut::after{content:'';position:absolute;top:16px;left:16px;width:58px;height:58px;background:var(--ink2);border-radius:50%;}
.donut-legend{display:flex;flex-direction:column;gap:7px;}
.legend-item{display:flex;align-items:center;gap:7px;font-size:17px;}
.legend-dot{width:9px;height:9px;border-radius:3px;flex-shrink:0;}

/* ── SEARCH ── */
.search-bar{display:flex;align-items:center;gap:7px;background:var(--ink3);border:1px solid var(--line);border-radius:8px;padding:7px 11px;font-size:16px;color:var(--muted);margin-bottom:14px;}
.search-bar svg{width:13px;height:13px;stroke:var(--muted);fill:none;stroke-width:2;}
.search-bar input{background:none;border:none;outline:none;font-family:'DM Sans',sans-serif;font-size:16px;color:var(--text);flex:1;}
.search-bar input::placeholder{color:var(--muted);}

/* ── TABS ── */
.tabs{display:flex;gap:3px;margin-bottom:16px;background:var(--ink3);padding:3px;border-radius:9px;width:fit-content;}
.tab{padding:5px 14px;border-radius:6px;font-size:16px;font-weight:600;cursor:pointer;color:var(--muted);transition:all 0.15s;border:none;background:none;font-family:'DM Sans',sans-serif;}
.tab.active{background:var(--ink2);color:var(--text);box-shadow:0 1px 4px rgba(0,0,0,0.4);}

/* ── RESOURCE GRID ── */
.resource-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;}
.resource-card{background:var(--ink2);border:1px solid var(--line);border-radius:10px;padding:16px;transition:border-color 0.2s,transform 0.2s;cursor:pointer;}
.resource-card:hover{border-color:var(--line2);transform:translateY(-1px);}
.resource-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;margin-bottom:10px;font-size:16px;}
.resource-card h4{font-size:16px;font-weight:700;margin-bottom:4px;}
.resource-card p{font-size:17px;color:var(--muted);line-height:1.5;}
.resource-meta{font-size:16px;color:var(--muted);margin-top:9px;display:flex;justify-content:space-between;}

/* ── LOG ENTRIES ── */
.log-entry{display:flex;align-items:center;gap:13px;padding:9px 15px;border-bottom:1px solid var(--line);font-size:17px;font-family:'DM Mono',monospace;}
.log-entry:last-child{border-bottom:none;}
.log-time{color:var(--muted);width:135px;flex-shrink:0;}
.log-level{width:58px;flex-shrink:0;}
.log-user{color:var(--accent2);width:115px;flex-shrink:0;}
.log-msg{color:var(--text);flex:1;}
.log-ip{color:var(--muted);width:95px;flex-shrink:0;text-align:right;}

/* ── SETTINGS ── */
.settings-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;}
.setting-group{margin-bottom:18px;}
.setting-group label{display:block;font-size:17px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:6px;}
.setting-input{width:100%;padding:8px 11px;background:var(--ink3);border:1px solid var(--line);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:16px;color:var(--text);outline:none;}
.setting-input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--acc-glow);}
.toggle-row{display:flex;align-items:center;justify-content:space-between;padding:11px 0;border-bottom:1px solid var(--line);}
.toggle-row:last-child{border-bottom:none;}
.toggle-info h4{font-size:16px;font-weight:600;}
.toggle-info p{font-size:17px;color:var(--muted);margin-top:2px;}
.toggle{position:relative;width:38px;height:21px;}
.toggle input{opacity:0;width:0;height:0;}
.toggle-slider{position:absolute;inset:0;background:var(--ink4);border-radius:20px;cursor:pointer;transition:background 0.2s;border:1px solid var(--line);}
.toggle-slider::before{content:'';position:absolute;width:15px;height:15px;left:2px;top:2px;background:var(--muted);border-radius:50%;transition:transform 0.2s,background 0.2s;}
.toggle input:checked+.toggle-slider{background:rgba(59,130,246,0.18);border-color:rgba(59,130,246,0.35);}
.toggle input:checked+.toggle-slider::before{transform:translateX(17px);background:var(--accent);}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;gap:5px;padding:7px 13px;border-radius:7px;font-family:'DM Sans',sans-serif;font-size:16px;font-weight:600;cursor:pointer;border:none;transition:all 0.15s;}
.btn svg{width:12px;height:12px;stroke:currentColor;fill:none;stroke-width:2;}
.btn-primary{background:var(--accent);color:white;}
.btn-primary:hover{background:#2563eb;box-shadow:0 4px 14px rgba(59,130,246,0.3);}
.btn-ghost{background:var(--ink3);color:var(--text);border:1px solid var(--line);}
.btn-ghost:hover{border-color:var(--line2);}
.btn-danger{background:rgba(239,68,68,0.1);color:var(--red);border:1px solid rgba(239,68,68,0.18);}
.btn-danger:hover{background:rgba(239,68,68,0.18);}
.btn-sm{padding:3px 9px;font-size:13px;}

/* ── MODAL ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.75);backdrop-filter:blur(4px);z-index:200;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal{background:var(--ink2);border:1px solid var(--line2);border-radius:14px;width:520px;max-width:95vw;max-height:90vh;overflow-y:auto;padding:26px;position:relative;}
.modal h3{font-family: 'Times New Roman', Times, serif;font-size:17px;font-weight:800;margin-bottom:18px;letter-spacing:-0.02em;}
.modal-close{position:absolute;top:14px;right:14px;background:none;border:none;color:var(--muted);cursor:pointer;font-size:18px;line-height:1;transition:color 0.15s;}
.modal-close:hover{color:var(--text);}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.form-group{margin-bottom:14px;}
.form-group label{display:block;font-size:16px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--muted);margin-bottom:5px;}
.form-input{width:100%;padding:8px 11px;background:var(--ink3);border:1px solid var(--line);border-radius:7px;font-family:'DM Sans',sans-serif;font-size:16px;color:var(--text);outline:none;box-sizing:border-box;}
.form-input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--acc-glow);}
select.form-input option{background:var(--ink3);}
.form-actions{display:flex;gap:7px;justify-content:flex-end;margin-top:18px;}

/* ── TOAST ── */
.toast{position:fixed;bottom:22px;right:22px;z-index:999;background:var(--ink2);border:1px solid var(--line);border-radius:9px;padding:11px 16px;font-size:16px;font-weight:600;display:flex;align-items:center;gap:9px;box-shadow:0 8px 28px rgba(0,0,0,0.5);transform:translateY(70px);opacity:0;transition:all 0.3s ease;pointer-events:none;}
.toast.show{transform:translateY(0);opacity:1;}
.toast.success{border-color:rgba(34,197,94,0.35);color:var(--green);}
.toast.error{border-color:rgba(239,68,68,0.35);color:var(--red);}

/* ── PAGINATION ── */
.pagination{display:flex;align-items:center;justify-content:space-between;padding:12px 15px;border-top:1px solid var(--line);font-size:17px;color:var(--muted);}
.pagination-btns{display:flex;gap:3px;}
.pagination-btns button{padding:4px 9px;border-radius:5px;font-size:17px;background:var(--ink3);border:1px solid var(--line);color:var(--text);cursor:pointer;font-family:'DM Sans',sans-serif;}
.pagination-btns button:disabled{opacity:0.35;cursor:not-allowed;}
.pagination-btns button.current{background:var(--accent);border-color:var(--accent);color:white;}

/* ── SKELETON ── */
.skeleton{background:linear-gradient(90deg,var(--ink3) 25%,var(--ink4) 50%,var(--ink3) 75%);background-size:200% 100%;animation:shimmer 1.5s infinite;border-radius:4px;}
@keyframes shimmer{0%{background-position:200% 0;}100%{background-position:-200% 0;}}

/* ── RESPONSIVE ── */
@media(max-width:1100px){.stats-grid{grid-template-columns:repeat(2,1fr);}.three-col{grid-template-columns:1fr 1fr;}}
@media(max-width:768px){
    .sidebar{transform:translateX(-100%);transition:transform 0.3s ease;}
    .sidebar.mobile-open{transform:translateX(0);}
    .main{margin-left:0;}
    .two-col,.three-col{grid-template-columns:1fr;}
    .stats-grid{grid-template-columns:1fr 1fr;}
    .hamburger{display:flex !important;}
    .sidebar-overlay{display:block;}
}
.hamburger{display:none;width:34px;height:34px;border-radius:7px;background:var(--ink3);border:1px solid var(--line);align-items:center;justify-content:center;cursor:pointer;flex-direction:column;gap:5px;padding:8px;flex-shrink:0;}
.hamburger span{display:block;width:100%;height:2px;background:var(--dim);border-radius:2px;transition:all 0.2s;}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:99;}
</style>
<!-- Apply saved theme before first paint — prevents flash on page load -->
<script>(function(){var t=localStorage.getItem('pta_theme');if(t==='light')document.documentElement.setAttribute('data-theme','light');}());</script>
</head>
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
/* ═══════════════════════════════════════════════
   PREPAURA ADMIN — TeamView-inspired Theme
   Dark: charcoal/olive dark  |  Accent: lime green
   Light: clean white/gray
   ═══════════════════════════════════════════════ */
:root{
    /* Backgrounds */
    --ink:#1c1c27;--ink2:#25253a;--ink3:#2e2e45;--ink4:#38384f;
    /* Borders */
    --line:rgba(255,255,255,0.07);--line2:rgba(255,255,255,0.13);
    /* Text */
    --text:#e8e8f0;--muted:#7c7c9a;--dim:#a0a0c0;
    /* Accent — lime green like TeamView */
    --accent:#a3e635;--accent2:#bef264;--acc-glow:rgba(163,230,53,0.18);
    --accent-dark:#1c1c27;/* text on lime buttons */
    /* Semantic colours */
    --green:#4ade80;--yellow:#facc15;--red:#f87171;--orange:#fb923c;--purple:#c084fc;
    --sidebar-w:240px;
    /* Card radius — rounder like TeamView */
    --radius:16px;--radius-sm:10px;
}
[data-theme="light"]{
    --ink:#f1f3f8;--ink2:#ffffff;--ink3:#e8ebf3;--ink4:#d6daea;
    --line:rgba(0,0,0,0.07);--line2:rgba(0,0,0,0.12);
    --text:#13131f;--muted:#6b7294;--dim:#4a4f6e;
    --accent:#65a30d;--accent2:#4d7c0f;--acc-glow:rgba(101,163,13,0.15);
    --accent-dark:#ffffff;
    --green:#16a34a;--yellow:#ca8a04;--red:#dc2626;--orange:#ea580c;--purple:#7c3aed;
}
[data-theme="light"] body::before{display:none;}
[data-theme="light"] .topbar{background:rgba(241,243,248,0.95);}
[data-theme="light"] .profile-dropdown{box-shadow:0 16px 48px rgba(0,0,0,0.12),0 0 0 1px var(--line2);}
[data-theme="light"] .dd-header{background:linear-gradient(135deg,var(--ink3),var(--ink4));}
[data-theme="light"] .modal-overlay{background:rgba(0,0,0,0.4);}
[data-theme="light"] .toast{box-shadow:0 8px 28px rgba(0,0,0,0.12);}
[data-theme="light"] .tab.active{box-shadow:0 1px 4px rgba(0,0,0,0.1);}
[data-theme="light"] select.form-input option{background:var(--ink3);color:var(--text);}
[data-theme="light"] .btn-primary{color:#fff;}
[data-theme="light"] .nav-item.active{color:var(--accent2);}

/* ── Theme toggle ── */
.theme-toggle{width:34px;height:34px;border-radius:var(--radius-sm);background:var(--ink3);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--muted);transition:color 0.15s,border-color 0.15s;flex-shrink:0;position:relative;}
.theme-toggle:hover{color:var(--accent);border-color:var(--acc-glow);}
.theme-toggle svg{width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2;position:absolute;transition:opacity 0.2s,transform 0.2s;}
.theme-toggle .icon-sun{opacity:0;transform:rotate(90deg) scale(0.7);}
.theme-toggle .icon-moon{opacity:1;transform:rotate(0deg) scale(1);}
[data-theme="light"] .theme-toggle .icon-sun{opacity:1;transform:rotate(0deg) scale(1);}
[data-theme="light"] .theme-toggle .icon-moon{opacity:0;transform:rotate(-90deg) scale(0.7);}

/* ── Reset ── */
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'DM Sans',sans-serif;background:var(--ink);color:var(--text);min-height:100vh;display:flex;overflow-x:hidden;}
/* Subtle noise texture like TeamView */
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
  background-image:radial-gradient(ellipse 80% 60% at 70% 0%,rgba(163,230,53,0.06) 0%,transparent 60%),
                   radial-gradient(ellipse 50% 40% at 10% 90%,rgba(163,230,53,0.04) 0%,transparent 60%);}

/* ══════════════════════════════
   SIDEBAR
   ══════════════════════════════ */
.sidebar{width:var(--sidebar-w);background:var(--ink2);border-right:1px solid var(--line);display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:100;transition:transform 0.3s ease;}
.sidebar-brand{padding:22px 20px 18px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:12px;}
.brand-logo{width:36px;height:36px;background:var(--accent);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.brand-logo svg{width:18px;height:18px;stroke:var(--accent-dark);fill:none;stroke-width:2.5;}
.brand-text{font-family:'DM Sans',sans-serif;font-size:16px;font-weight:800;letter-spacing:-0.01em;color:var(--text);}
.brand-text span{font-size:11px;font-weight:400;color:var(--muted);display:block;letter-spacing:0;margin-top:1px;}
.sidebar-nav{flex:1;padding:16px 12px;overflow-y:auto;display:flex;flex-direction:column;gap:2px;}
.nav-section-label{font-size:10px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:var(--muted);padding:12px 10px 4px;margin-top:6px;}
/* Pill-shaped active item — key TeamView detail */
.nav-item{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:12px;cursor:pointer;transition:background 0.15s,color 0.15s;color:var(--muted);font-size:14px;font-weight:500;user-select:none;text-decoration:none;position:relative;}
.nav-item:hover{background:var(--ink3);color:var(--dim);}
.nav-item.active{background:var(--accent);color:var(--accent-dark);font-weight:700;}
.nav-item.active svg{stroke:var(--accent-dark);}
.nav-item svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;flex-shrink:0;}
.nav-badge{margin-left:auto;background:var(--red);color:white;font-size:10px;font-weight:700;padding:2px 6px;border-radius:20px;min-width:18px;text-align:center;}
.nav-badge.blue{background:var(--accent);color:var(--accent-dark);}
.sidebar-bottom{padding:12px;border-top:1px solid var(--line);display:flex;flex-direction:column;gap:2px;}

/* ══════════════════════════════
   MAIN WRAPPER
   ══════════════════════════════ */
.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-height:100vh;position:relative;z-index:1;}

/* ══════════════════════════════
   TOPBAR
   ══════════════════════════════ */
.topbar{height:60px;background:rgba(28,28,39,0.85);backdrop-filter:blur(16px);border-bottom:1px solid var(--line);display:flex;align-items:center;padding:0 28px;gap:14px;position:sticky;top:0;z-index:50;}
.topbar-title{font-family:'DM Sans',sans-serif;font-size:16px;font-weight:700;color:var(--text);flex:1;}
.topbar-title span{color:var(--muted);font-weight:400;font-size:15px;}
.topbar-actions{display:flex;align-items:center;gap:8px;}
.icon-btn{width:34px;height:34px;border-radius:var(--radius-sm);background:var(--ink3);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--muted);transition:color 0.15s,background 0.15s;}
.icon-btn:hover{color:var(--accent);background:var(--ink4);}
.icon-btn svg{width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2;}

/* ── Profile chip + dropdown ── */
.admin-chip{display:flex;align-items:center;gap:8px;background:var(--ink3);border:1px solid var(--line);border-radius:24px;padding:4px 12px 4px 4px;cursor:pointer;transition:border-color 0.15s;}
.admin-chip:hover{border-color:var(--line2);}
.admin-avatar{width:28px;height:28px;background:var(--accent);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:var(--accent-dark);}
.admin-chip span{font-size:14px;font-weight:600;color:var(--text);}
.admin-chip-caret{width:12px;height:12px;stroke:var(--muted);fill:none;stroke-width:2;transition:transform 0.2s;}
.admin-chip.open .admin-chip-caret{transform:rotate(180deg);}
.profile-wrap{position:relative;}
.profile-dropdown{position:absolute;top:calc(100% + 10px);right:0;background:var(--ink3);border-radius:var(--radius);box-shadow:0 20px 60px rgba(0,0,0,0.5),0 0 0 1px var(--line2);min-width:230px;opacity:0;visibility:hidden;transform:translateY(-6px) scale(0.97);transition:opacity 0.18s,visibility 0.18s,transform 0.18s;z-index:200;overflow:hidden;}
.profile-dropdown.open{opacity:1;visibility:visible;transform:translateY(0) scale(1);}
.dd-header{padding:18px;background:linear-gradient(135deg,var(--ink2) 0%,var(--ink4) 100%);border-bottom:1px solid var(--line);}
.dd-avatar{width:44px;height:44px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:16px;color:var(--accent-dark);margin-bottom:10px;}
.dd-name{font-size:15px;font-weight:700;color:var(--text);}
.dd-email{font-size:13px;color:var(--muted);margin-top:2px;}
.dd-role{display:inline-block;margin-top:8px;padding:3px 10px;background:rgba(163,230,53,0.12);border:1px solid rgba(163,230,53,0.25);color:var(--accent);border-radius:20px;font-size:11px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;}
.dd-menu{padding:6px 0;}
.dd-item{display:flex;align-items:center;gap:10px;padding:10px 16px;color:var(--dim);text-decoration:none;font-size:14px;transition:background 0.15s,color 0.15s;cursor:pointer;border:none;background:none;width:100%;text-align:left;font-family:'DM Sans',sans-serif;}
.dd-item svg{width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;flex-shrink:0;}
.dd-item:hover{background:var(--ink4);color:var(--text);}
.dd-item.danger{color:var(--red);}
.dd-item.danger:hover{background:rgba(248,113,113,0.08);}
.dd-divider{height:1px;background:var(--line);margin:4px 0;}

/* ══════════════════════════════
   CONTENT AREA
   ══════════════════════════════ */
.content{padding:28px;flex:1;}
.section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;}
.section-header h2{font-family:'DM Sans',sans-serif;font-size:22px;font-weight:800;letter-spacing:-0.02em;}
.section-header p{font-size:14px;color:var(--muted);margin-top:3px;}

/* ══════════════════════════════
   STAT CARDS
   ══════════════════════════════ */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;}
.stat-card{background:var(--ink2);border:1px solid var(--line);border-radius:var(--radius);padding:20px;position:relative;overflow:hidden;transition:transform 0.2s,box-shadow 0.2s;}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 8px 32px rgba(0,0,0,0.2);}
/* Lime accent top bar on stat cards */
.stat-card::after{content:'';position:absolute;top:0;left:20px;right:20px;height:3px;border-radius:0 0 4px 4px;opacity:0.7;}
.stat-card.blue::after{background:var(--accent);}
.stat-card.green::after{background:var(--green);}
.stat-card.yellow::after{background:var(--yellow);}
.stat-card.red::after{background:var(--red);}
.stat-icon{width:40px;height:40px;border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;margin-bottom:14px;}
.stat-icon svg{width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:2;}
.stat-card.blue .stat-icon{background:rgba(163,230,53,0.12);border:1px solid rgba(163,230,53,0.2);color:var(--accent);}
.stat-card.green .stat-icon{background:rgba(74,222,128,0.12);border:1px solid rgba(74,222,128,0.2);color:var(--green);}
.stat-card.yellow .stat-icon{background:rgba(250,204,21,0.12);border:1px solid rgba(250,204,21,0.2);color:var(--yellow);}
.stat-card.red .stat-icon{background:rgba(248,113,113,0.12);border:1px solid rgba(248,113,113,0.2);color:var(--red);}
.stat-value{font-family:'DM Sans',sans-serif;font-size:30px;font-weight:800;color:var(--text);line-height:1;margin-bottom:4px;letter-spacing:-0.03em;}
.stat-label{font-size:13px;color:var(--muted);margin-bottom:6px;}
.stat-change{font-size:13px;display:flex;align-items:center;gap:4px;}
.stat-change.up{color:var(--green);}.stat-change.down{color:var(--red);}

/* ══════════════════════════════
   CARDS
   ══════════════════════════════ */
.card{background:var(--ink2);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;}
.card-header{padding:18px 20px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;}
.card-header h3{font-family:'DM Sans',sans-serif;font-size:16px;font-weight:700;}
.card-header p{font-size:13px;color:var(--muted);margin-top:2px;}
.card-body{padding:20px;}

/* ══════════════════════════════
   LAYOUT GRIDS
   ══════════════════════════════ */
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;}
.three-col{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:16px;}

/* ══════════════════════════════
   TABLE
   ══════════════════════════════ */
.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:14px;}
thead th{padding:11px 16px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.09em;color:var(--muted);background:var(--ink3);border-bottom:1px solid var(--line);}
tbody tr{border-bottom:1px solid var(--line);transition:background 0.12s;}
tbody tr:hover{background:var(--ink3);}
tbody tr:last-child{border-bottom:none;}
tbody td{padding:12px 16px;color:var(--text);vertical-align:middle;}
.user-cell{display:flex;align-items:center;gap:10px;}
.user-av{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:white;flex-shrink:0;}
.user-av.blue{background:#1d4ed8;}.user-av.green{background:#15803d;}.user-av.purple{background:#7c3aed;}.user-av.orange{background:#c2410c;}
.user-name{font-size:14px;font-weight:600;}
.user-email{font-size:13px;color:var(--muted);}

/* ══════════════════════════════
   BADGES
   ══════════════════════════════ */
.badge{display:inline-flex;align-items:center;gap:3px;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;}
.badge.active,.badge.published,.badge.success{background:rgba(74,222,128,0.12);color:var(--green);border:1px solid rgba(74,222,128,0.25);}
.badge.inactive,.badge.draft{background:rgba(107,114,128,0.1);color:#9ca3af;border:1px solid rgba(107,114,128,0.18);}
.badge.student,.badge.info{background:rgba(163,230,53,0.1);color:var(--accent);border:1px solid rgba(163,230,53,0.22);}
.badge.teacher{background:rgba(192,132,252,0.1);color:#c084fc;border:1px solid rgba(192,132,252,0.22);}
.badge.admin-b{background:rgba(248,113,113,0.1);color:#fca5a5;border:1px solid rgba(248,113,113,0.2);}
.badge.easy{background:rgba(74,222,128,0.1);color:var(--green);border:1px solid rgba(74,222,128,0.2);}
.badge.medium{background:rgba(250,204,21,0.1);color:var(--yellow);border:1px solid rgba(250,204,21,0.2);}
.badge.hard{background:rgba(248,113,113,0.1);color:var(--red);border:1px solid rgba(248,113,113,0.2);}
.badge.archived,.badge.warning{background:rgba(251,146,60,0.1);color:var(--orange);border:1px solid rgba(251,146,60,0.2);}
.badge.error{background:rgba(248,113,113,0.1);color:var(--red);border:1px solid rgba(248,113,113,0.2);}

/* ══════════════════════════════
   BAR CHART
   ══════════════════════════════ */
.bar-chart{display:flex;flex-direction:column;gap:12px;}
.bar-row{display:flex;align-items:center;gap:10px;}
.bar-label{font-size:13px;color:var(--muted);width:100px;flex-shrink:0;}
.bar-track{flex:1;height:6px;background:var(--ink4);border-radius:10px;overflow:hidden;}
.bar-fill{height:100%;border-radius:10px;background:var(--accent);transition:width 0.9s ease;}
.bar-fill.green{background:var(--green);}.bar-fill.yellow{background:var(--yellow);}
.bar-val{font-size:13px;font-weight:700;color:var(--text);width:36px;text-align:right;}

/* ══════════════════════════════
   ACTIVITY LIST
   ══════════════════════════════ */
.activity-list{display:flex;flex-direction:column;}
.activity-item{display:flex;align-items:flex-start;gap:12px;padding:12px 0;border-bottom:1px solid var(--line);}
.activity-item:last-child{border-bottom:none;}
.activity-dot{width:8px;height:8px;border-radius:50%;margin-top:5px;flex-shrink:0;}
.activity-dot.blue{background:var(--accent);}.activity-dot.green{background:var(--green);}.activity-dot.yellow{background:var(--yellow);}.activity-dot.red{background:var(--red);}
.activity-text{font-size:14px;color:var(--text);line-height:1.5;}
.activity-time{font-size:12px;color:var(--muted);margin-top:2px;}

/* ══════════════════════════════
   DONUT
   ══════════════════════════════ */
.donut-wrap{display:flex;align-items:center;gap:22px;}
.donut{width:96px;height:96px;border-radius:50%;background:conic-gradient(var(--accent) 0% 62%,var(--green) 62% 82%,var(--yellow) 82% 92%,var(--red) 92% 100%);position:relative;flex-shrink:0;}
.donut::after{content:'';position:absolute;top:18px;left:18px;width:60px;height:60px;background:var(--ink2);border-radius:50%;}
.donut-legend{display:flex;flex-direction:column;gap:8px;}
.legend-item{display:flex;align-items:center;gap:8px;font-size:13px;}
.legend-dot{width:10px;height:10px;border-radius:3px;flex-shrink:0;}

/* ══════════════════════════════
   SEARCH BAR
   ══════════════════════════════ */
.search-bar{display:flex;align-items:center;gap:8px;background:var(--ink3);border:1px solid var(--line);border-radius:var(--radius-sm);padding:9px 14px;font-size:14px;color:var(--muted);margin-bottom:16px;}
.search-bar svg{width:14px;height:14px;stroke:var(--muted);fill:none;stroke-width:2;}
.search-bar input{background:none;border:none;outline:none;font-family:'DM Sans',sans-serif;font-size:14px;color:var(--text);flex:1;}
.search-bar input::placeholder{color:var(--muted);}

/* ══════════════════════════════
   TABS
   ══════════════════════════════ */
.tabs{display:flex;gap:2px;margin-bottom:18px;background:var(--ink3);padding:4px;border-radius:12px;width:fit-content;}
.tab{padding:7px 16px;border-radius:9px;font-size:14px;font-weight:600;cursor:pointer;color:var(--muted);transition:all 0.15s;border:none;background:none;font-family:'DM Sans',sans-serif;}
.tab.active{background:var(--accent);color:var(--accent-dark);box-shadow:0 2px 8px rgba(163,230,53,0.3);}

/* ══════════════════════════════
   RESOURCE GRID
   ══════════════════════════════ */
.resource-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;}
.resource-card{background:var(--ink2);border:1px solid var(--line);border-radius:var(--radius);padding:18px;transition:border-color 0.2s,transform 0.2s;cursor:pointer;}
.resource-card:hover{border-color:rgba(163,230,53,0.3);transform:translateY(-2px);}
.resource-icon{width:38px;height:38px;border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;margin-bottom:12px;font-size:17px;background:rgba(163,230,53,0.1);}
.resource-card h4{font-size:14px;font-weight:700;margin-bottom:4px;}
.resource-card p{font-size:13px;color:var(--muted);line-height:1.5;}
.resource-meta{font-size:12px;color:var(--muted);margin-top:10px;display:flex;justify-content:space-between;}

/* ══════════════════════════════
   LOG ENTRIES
   ══════════════════════════════ */
.log-entry{display:flex;align-items:center;gap:14px;padding:10px 16px;border-bottom:1px solid var(--line);font-size:13px;font-family:'DM Mono',monospace;}
.log-entry:last-child{border-bottom:none;}
.log-time{color:var(--muted);width:140px;flex-shrink:0;}
.log-level{width:60px;flex-shrink:0;}
.log-user{color:var(--accent);width:120px;flex-shrink:0;}
.log-msg{color:var(--text);flex:1;}
.log-ip{color:var(--muted);width:100px;flex-shrink:0;text-align:right;}

/* ══════════════════════════════
   SETTINGS
   ══════════════════════════════ */
.settings-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;}
.setting-group{margin-bottom:18px;}
.setting-group label{display:block;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.08em;margin-bottom:7px;}
.setting-input{width:100%;padding:10px 13px;background:var(--ink3);border:1px solid var(--line);border-radius:var(--radius-sm);font-family:'DM Sans',sans-serif;font-size:14px;color:var(--text);outline:none;}
.setting-input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--acc-glow);}
.toggle-row{display:flex;align-items:center;justify-content:space-between;padding:13px 0;border-bottom:1px solid var(--line);}
.toggle-row:last-child{border-bottom:none;}
.toggle-info h4{font-size:14px;font-weight:600;}
.toggle-info p{font-size:13px;color:var(--muted);margin-top:2px;}
.toggle{position:relative;width:42px;height:24px;}
.toggle input{opacity:0;width:0;height:0;}
.toggle-slider{position:absolute;inset:0;background:var(--ink4);border-radius:20px;cursor:pointer;transition:background 0.2s;border:1px solid var(--line);}
.toggle-slider::before{content:'';position:absolute;width:18px;height:18px;left:2px;top:2px;background:var(--muted);border-radius:50%;transition:transform 0.2s,background 0.2s;}
.toggle input:checked+.toggle-slider{background:rgba(163,230,53,0.2);border-color:rgba(163,230,53,0.4);}
.toggle input:checked+.toggle-slider::before{transform:translateX(18px);background:var(--accent);}

/* ══════════════════════════════
   BUTTONS
   ══════════════════════════════ */
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:var(--radius-sm);font-family:'DM Sans',sans-serif;font-size:14px;font-weight:600;cursor:pointer;border:none;transition:all 0.15s;}
.btn svg{width:13px;height:13px;stroke:currentColor;fill:none;stroke-width:2;}
/* Primary = lime green like TeamView */
.btn-primary{background:var(--accent);color:var(--accent-dark);}
.btn-primary:hover{background:var(--accent2);box-shadow:0 4px 16px rgba(163,230,53,0.3);}
.btn-ghost{background:var(--ink3);color:var(--text);border:1px solid var(--line);}
.btn-ghost:hover{border-color:var(--line2);background:var(--ink4);}
.btn-danger{background:rgba(248,113,113,0.1);color:var(--red);border:1px solid rgba(248,113,113,0.2);}
.btn-danger:hover{background:rgba(248,113,113,0.18);}
.btn-sm{padding:5px 11px;font-size:12px;}

/* ══════════════════════════════
   MODAL
   ══════════════════════════════ */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);backdrop-filter:blur(6px);z-index:200;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal{background:var(--ink2);border:1px solid var(--line2);border-radius:var(--radius);width:540px;max-width:95vw;max-height:90vh;overflow-y:auto;padding:28px;position:relative;}
.modal h3{font-family:'DM Sans',sans-serif;font-size:18px;font-weight:800;margin-bottom:20px;letter-spacing:-0.02em;}
.modal-close{position:absolute;top:16px;right:16px;background:none;border:none;color:var(--muted);cursor:pointer;font-size:20px;line-height:1;transition:color 0.15s;}
.modal-close:hover{color:var(--text);}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.form-group{margin-bottom:16px;}
.form-group label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--muted);margin-bottom:6px;}
.form-input{width:100%;padding:10px 13px;background:var(--ink3);border:1px solid var(--line);border-radius:var(--radius-sm);font-family:'DM Sans',sans-serif;font-size:14px;color:var(--text);outline:none;box-sizing:border-box;}
.form-input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--acc-glow);}
select.form-input option{background:var(--ink3);}
.form-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:20px;}

/* ══════════════════════════════
   TOAST
   ══════════════════════════════ */
.toast{position:fixed;bottom:24px;right:24px;z-index:999;background:var(--ink2);border:1px solid var(--line);border-radius:var(--radius-sm);padding:13px 18px;font-size:14px;font-weight:600;display:flex;align-items:center;gap:10px;box-shadow:0 8px 32px rgba(0,0,0,0.4);transform:translateY(70px);opacity:0;transition:all 0.3s ease;pointer-events:none;}
.toast.show{transform:translateY(0);opacity:1;}
.toast.success{border-color:rgba(163,230,53,0.4);color:var(--accent);}
.toast.error{border-color:rgba(248,113,113,0.35);color:var(--red);}

/* ══════════════════════════════
   PAGINATION
   ══════════════════════════════ */
.pagination{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-top:1px solid var(--line);font-size:13px;color:var(--muted);}
.pagination-btns{display:flex;gap:4px;}
.pagination-btns button{padding:6px 11px;border-radius:8px;font-size:13px;background:var(--ink3);border:1px solid var(--line);color:var(--text);cursor:pointer;font-family:'DM Sans',sans-serif;transition:background 0.15s;}
.pagination-btns button:disabled{opacity:0.35;cursor:not-allowed;}
.pagination-btns button.current{background:var(--accent);border-color:var(--accent);color:var(--accent-dark);font-weight:700;}

/* ══════════════════════════════
   SKELETON
   ══════════════════════════════ */
.skeleton{background:linear-gradient(90deg,var(--ink3) 25%,var(--ink4) 50%,var(--ink3) 75%);background-size:200% 100%;animation:shimmer 1.5s infinite;border-radius:6px;}
@keyframes shimmer{0%{background-position:200% 0;}100%{background-position:-200% 0;}}

/* ══════════════════════════════
   RESPONSIVE
   ══════════════════════════════ */
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
.hamburger{display:none;width:36px;height:36px;border-radius:var(--radius-sm);background:var(--ink3);border:1px solid var(--line);align-items:center;justify-content:center;cursor:pointer;flex-direction:column;gap:5px;padding:9px;flex-shrink:0;}
.hamburger span{display:block;width:100%;height:2px;background:var(--dim);border-radius:2px;transition:all 0.2s;}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:99;}
</style>
<!-- Apply saved theme before first paint — prevents flash on page load -->
<script>(function(){var t=localStorage.getItem('pta_theme');if(t==='light')document.documentElement.setAttribute('data-theme','light');}());</script>
</head>
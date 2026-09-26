<?php
/**
 * Kounselia Admin — shared styles.
 *
 * Required by every page under /portal/admin/pages/. One design
 * system, edited here once, so nothing drifts between pages.
 *
 * Design notes: same brand palette as the member-facing site (navy,
 * gold, rose, sage, teal, plum, sienna on a warm cream base), refined
 * for a data-dense admin context: tighter spacing scale, soft
 * elevation instead of hard borders everywhere, and a mobile layout
 * where the top nav collapses into a menu and tables become stacked
 * cards instead of forcing horizontal scroll.
 */
?>
<style>
:root{
  --bg:#F8F6F2;
  --surface:#FFFFFF;
  --surface2:#F2EFE9;
  --text:#18160F;
  --text2:#5B574D;
  --text3:#A8A49A;
  --border:#EAE6DC;
  --accent:#1E3A5F;
  --accent2:#284B7A;
  --accent-light:#E8EEF6;
  --gold:#B07D3A;
  --gold-light:#FBF5EA;
  --rose:#8B3A52;
  --rose-light:#F7EBF0;
  --sage:#2E5C3E;
  --sage-light:#EAF2EC;
  --teal:#1E5C5C;
  --teal-light:#E6F2F2;
  --plum:#4A3070;
  --plum-light:#EEE9F8;
  --sienna:#7A3D1E;
  --sienna-light:#F5EBE5;

  --r-sm:8px;
  --r-md:12px;
  --r-lg:18px;
  --r-full:999px;

  --sh-sm:0 1px 2px rgba(24,22,15,.04), 0 1px 1px rgba(24,22,15,.03);
  --sh-md:0 4px 16px rgba(24,22,15,.06), 0 1px 3px rgba(24,22,15,.04);
  --sh-lg:0 12px 32px rgba(24,22,15,.09), 0 2px 6px rgba(24,22,15,.05);

  --ease: cubic-bezier(.4,0,.2,1);
}

*{margin:0;padding:0;box-sizing:border-box;font-family:'Outfit',-apple-system,BlinkMacSystemFont,sans-serif;-webkit-tap-highlight-color:transparent}
html{-webkit-text-size-adjust:100%}
body{background:var(--bg);color:var(--text);font-size:14px;line-height:1.5}
a{color:var(--rose);text-decoration:none;transition:color .15s var(--ease)}
a:hover{color:#6E2E41}
:focus-visible{outline:2px solid var(--accent);outline-offset:2px;border-radius:4px}
::selection{background:var(--accent-light);color:var(--accent)}

/* ---------------------------------------------------------------------
   STREAMING_CHUNK:Styling top navigation...
--------------------------------------------------------------------- */
/* Sidebar navigation (see inc/admin-nav.php). Desktop: fixed on the left.
   Under 960px: slim top bar + slide-in drawer. */
:root{--admin-sidebar-w:252px;--admin-top:0px}
body:has(.admin-sidebar){padding-left:var(--admin-sidebar-w)}
.admin-sidebar{
  position:fixed;top:0;left:0;bottom:0;width:var(--admin-sidebar-w);z-index:60;
  display:flex;flex-direction:column;background:var(--surface);border-right:1px solid var(--border);
}
.admin-sidebar-head{display:flex;align-items:center;justify-content:space-between;padding:20px 20px 14px}
.admin-logo{display:flex;align-items:center;gap:8px;white-space:nowrap;text-decoration:none}
.admin-site-logo{height:26px;width:auto;object-fit:contain;}
.admin-logo small{font-family:'Outfit',sans-serif;font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:var(--text3);font-weight:600;padding-left:8px;border-left:1px solid var(--border);margin-top:2px;}
.admin-sidebar-close{display:none;background:none;border:none;font-size:20px;color:var(--text2);cursor:pointer;padding:6px;border-radius:8px}
.admin-sidebar-close:hover{background:var(--surface2)}

.admin-nav{flex:1;overflow-y:auto;padding:4px 12px 16px;scrollbar-width:thin}
.admin-nav-group{margin-bottom:14px}
.admin-nav-label{font-size:10.5px;font-weight:600;letter-spacing:.09em;text-transform:uppercase;color:var(--text3);padding:10px 12px 6px}
.admin-nav a.nav-link{
  display:flex;align-items:center;gap:11px;padding:9px 12px;border-radius:10px;
  font-size:13.5px;color:var(--text2);text-decoration:none;position:relative;
  transition:background .15s var(--ease),color .15s var(--ease);
}
.admin-nav a.nav-link i{font-size:18px;color:var(--text3);transition:color .15s var(--ease);flex-shrink:0}
.admin-nav a.nav-link span{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.admin-nav a.nav-link:hover{background:var(--surface2);color:var(--text)}
.admin-nav a.nav-link:hover i{color:var(--accent)}
.admin-nav a.nav-link.active{background:var(--accent-light);color:var(--accent);font-weight:500}
.admin-nav a.nav-link.active i{color:var(--accent)}
.admin-nav a.nav-link.active::before{content:'';position:absolute;left:-12px;top:8px;bottom:8px;width:3px;border-radius:0 3px 3px 0;background:var(--accent)}
.nav-badge{font-size:10.5px;font-weight:600;min-width:20px;height:20px;padding:0 6px;border-radius:10px;background:var(--gold-light);color:var(--gold);display:inline-flex;align-items:center;justify-content:center;font-variant-numeric:tabular-nums}
.nav-badge.urgent{background:var(--rose);color:#fff}

.admin-who{display:flex;align-items:center;gap:10px;margin:0 12px 14px;padding:10px;border-radius:12px;background:var(--surface2)}
.who-av{width:34px;height:34px;border-radius:50%;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:14px;flex-shrink:0}
.who-meta{flex:1;min-width:0;display:flex;flex-direction:column}
.who-name{font-size:13px;font-weight:500;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.who-role{font-size:11px;color:var(--text3)}
.who-out{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:var(--text2);font-size:18px}
.who-out:hover{background:var(--surface);color:var(--rose)}

.admin-mobilebar{display:none}
.admin-nav-backdrop{display:none}
.nav-toggle{background:none;border:none;padding:8px;margin-left:-8px;cursor:pointer;color:var(--accent);border-radius:10px}
.nav-toggle:hover{background:var(--surface2)}
.nav-toggle svg{width:22px;height:22px;display:block}

@media (max-width:960px){
  :root{--admin-top:58px}
  body:has(.admin-sidebar){padding-left:0}
  .admin-mobilebar{
    display:flex;align-items:center;gap:10px;position:sticky;top:0;z-index:40;height:58px;padding:0 14px;
    background:rgba(255,255,255,.94);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border-bottom:1px solid var(--border);
  }
  .admin-mobilebar .admin-logo{flex:1}
  .admin-mobilebar-av{width:32px;height:32px;border-radius:50%;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:13px}
  .admin-sidebar{width:min(300px,86vw);transform:translateX(-102%);transition:transform .28s var(--ease);box-shadow:var(--sh-lg);padding-bottom:env(safe-area-inset-bottom)}
  .admin-nav-open .admin-sidebar{transform:none}
  .admin-sidebar-close{display:block}
  .admin-nav-backdrop{display:block;position:fixed;inset:0;z-index:55;background:rgba(24,22,15,.4);opacity:0;pointer-events:none;transition:opacity .25s var(--ease)}
  .admin-nav-open .admin-nav-backdrop{opacity:1;pointer-events:auto}
  html.admin-nav-open,html.admin-nav-open body{overflow:hidden}
  .admin-nav a.nav-link{padding:12px 12px;font-size:14.5px}
}
@media (prefers-reduced-motion:reduce){ .admin-sidebar,.admin-nav-backdrop{transition:none} }

/* ---------------------------------------------------------------------
   STREAMING_CHUNK:Styling page shell & cards...
--------------------------------------------------------------------- */
.admin-body{padding:32px 32px 64px;max-width:1180px;margin:0 auto}
@media (max-width:960px){ .admin-body{padding:24px 20px 56px} }
@media (max-width:640px){ .admin-body{padding:18px 14px 48px} }

h1.admin-title{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:27px;color:var(--accent);line-height:1.2;letter-spacing:.01em}
@media (max-width:640px){ h1.admin-title{font-size:22px} }
.admin-subtitle{color:var(--text3);font-size:13px;margin-bottom:22px;margin-top:3px}

/* ---------------------------------------------------------------------
   Stat cards
--------------------------------------------------------------------- */
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:24px}
.card{
  background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);
  padding:20px 22px;box-shadow:var(--sh-sm);transition:box-shadow .2s var(--ease),transform .2s var(--ease);
  position:relative;overflow:hidden;
}
.card:hover{box-shadow:var(--sh-md);transform:translateY(-1px)}
.card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--accent),var(--gold))}
.card .label{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--text3);margin-bottom:8px;font-weight:600}
.card .num{font-size:30px;font-weight:600;color:var(--accent);letter-spacing:-.01em;font-variant-numeric:tabular-nums}
.card .split{margin-top:12px;font-size:12.5px;color:var(--text2);display:flex;gap:16px;padding-top:12px;border-top:1px solid var(--border)}
.card .split b{color:var(--text);font-variant-numeric:tabular-nums}

/* ---------------------------------------------------------------------
   Panels
--------------------------------------------------------------------- */
.panel{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:18px 20px;margin-bottom:20px;box-shadow:var(--sh-sm)}
@media (max-width:640px){ .panel{padding:14px;border-radius:var(--r-md)} }
.panel-title{font-size:14.5px;font-weight:600;color:var(--accent);margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px}
.panel-title a{font-size:13px;font-weight:500}

/* ---------------------------------------------------------------------
   STREAMING_CHUNK:Styling tables and layout items...
--------------------------------------------------------------------- */
table.admin-table{width:100%;border-collapse:collapse;font-size:13.5px}
table.admin-table thead th{
  text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;
  color:var(--text3);font-weight:600;padding:0 10px 10px;border-bottom:1px solid var(--border);
}
table.admin-table tbody td{padding:12px 10px;border-bottom:1px solid var(--surface2);color:var(--text);vertical-align:middle}
table.admin-table tbody tr:last-child td{border-bottom:none}
table.admin-table tbody tr{transition:background .12s var(--ease)}
table.admin-table tbody tr:hover td{background:var(--surface2)}
table.admin-table .cell-who{display:flex;align-items:center;gap:8px;flex-wrap:wrap}

@media (max-width:720px){
  table.admin-table thead{display:none}
  table.admin-table, table.admin-table tbody, table.admin-table tr, table.admin-table td{display:block;width:100%}
  table.admin-table tbody tr{
    border:1px solid var(--border);border-radius:var(--r-md);margin-bottom:10px;padding:4px 12px;background:var(--surface);
  }
  table.admin-table tbody tr:hover td{background:transparent}
  table.admin-table td{
    display:flex;justify-content:space-between;align-items:center;gap:12px;
    border-bottom:1px solid var(--surface2);padding:9px 0;text-align:right;
  }
  table.admin-table td:last-child{border-bottom:none}
  table.admin-table td::before{
    content:attr(data-label);font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;
    color:var(--text3);font-weight:600;text-align:left;
  }
  table.admin-table td[data-label=""]::before{display:none}
}

/* Visible only to screen readers — for context sighted users get from
   color, icons or layout alone (e.g. "fast", "1 of 3 unread"). */
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.badge{display:inline-flex;align-items:center;font-size:10.5px;padding:3px 9px;border-radius:var(--r-full);font-weight:600;letter-spacing:.02em;white-space:nowrap}
.badge.member{background:var(--accent-light);color:var(--accent)}
.badge.guest{background:var(--surface2);color:var(--text2)}
.badge.active{background:var(--sage-light);color:var(--sage)}
.badge.ended{background:var(--surface2);color:var(--text3)}

/* Small pill action buttons — CRM row actions, member profile data controls, etc.
   Shared here (not per-page) so every admin screen that uses them looks and
   behaves the same, including on mobile. */
.btn-action{padding:9px 16px;border-radius:var(--r-full);font-size:12.5px;font-weight:500;border:none;cursor:pointer;transition:all .2s var(--ease);min-height:38px}
.btn-action.pro{background:var(--gold);color:#fff}
.btn-action.downgrade{background:var(--surface2);color:var(--text2)}
.btn-action.ban{background:var(--rose);color:#fff}
.btn-action.unban{background:var(--sage);color:#fff}
.btn-action:hover{filter:brightness(1.06)}
.btn-action:disabled{opacity:.6;cursor:not-allowed}

.empty-state{color:var(--text3);font-size:13.5px;padding:32px 0;text-align:center}

/* ---------------------------------------------------------------------
   Filters
--------------------------------------------------------------------- */
.filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:center}
.filters input,.filters select{
  padding:9px 13px;border:1px solid var(--border);border-radius:var(--r-sm);
  font-size:13px;background:var(--surface);font-family:inherit;color:var(--text);
  transition:border-color .15s var(--ease);
}
.filters input:focus,.filters select:focus{border-color:var(--accent);outline:none}
.filters input[type=text]{flex:1;min-width:180px}
.filters button{
  padding:9px 18px;border:none;border-radius:var(--r-sm);background:var(--accent);color:#fff;
  font-size:13px;font-weight:500;cursor:pointer;font-family:inherit;transition:background .15s var(--ease);
}
.filters button:hover{background:var(--accent2)}
.filters a.clear{font-size:12.5px;color:var(--rose)}
@media (max-width:640px){ .filters{flex-direction:column;align-items:stretch} .filters input[type=text]{min-width:0} }

/* ---------------------------------------------------------------------
   Pagination
--------------------------------------------------------------------- */
.pagination{display:flex;gap:6px;margin-top:16px;align-items:center;font-size:13px;color:var(--text2)}
.pagination a,.pagination span{padding:6px 13px;border-radius:var(--r-sm);border:1px solid var(--border)}
.pagination a:hover{background:var(--surface2);text-decoration:none}
.pagination span.current{background:var(--accent);color:#fff;border-color:var(--accent);font-weight:500}

/* ---------------------------------------------------------------------
   STREAMING_CHUNK:Styling Admin Login...
--------------------------------------------------------------------- */
.login-wrap{min-height:100vh;min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:20px}
.login-box{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:40px 32px;width:100%;max-width:380px;box-shadow:var(--sh-lg)}
@media (max-width:480px){ .login-box{padding:32px 22px;border-radius:var(--r-md)} }

.login-logo{display:flex;align-items:center;justify-content:center;margin-bottom:8px;}
.login-site-logo{height:36px;width:auto;object-fit:contain;}

.login-sub{text-align:center;color:var(--text3);font-size:11.5px;letter-spacing:.1em;text-transform:uppercase;margin:2px 0 30px;font-weight:600}
.login-field{margin-bottom:16px}
.login-field label{display:block;font-size:12.5px;color:var(--text2);margin-bottom:6px;font-weight:500}
.login-field input{width:100%;padding:12px 14px;border:1px solid var(--border);border-radius:var(--r-sm);font-size:16px;font-family:inherit;background:var(--bg);color:var(--text)}
.login-field input:focus{outline:none;border-color:var(--accent);background:var(--surface)}
.pw-wrap{position:relative}
.pw-wrap input{padding-right:44px}
.pw-toggle{position:absolute;right:4px;top:50%;transform:translateY(-50%);background:none;border:none;padding:8px;cursor:pointer;color:var(--text3);display:flex;line-height:0}
.pw-toggle:hover{color:var(--text2)}
.pw-toggle svg{width:19px;height:19px}
.hp-field{position:absolute;left:-9999px;opacity:0}
.login-submit{width:100%;padding:13px;border:none;border-radius:var(--r-sm);background:var(--accent);color:#fff;font-size:15px;font-weight:500;font-family:inherit;cursor:pointer;margin-top:6px;transition:background .15s var(--ease)}
.login-submit:hover{background:var(--accent2)}
.login-msg{border-radius:var(--r-sm);padding:11px 14px;font-size:13px;margin-bottom:18px;line-height:1.4}
.login-msg.error{background:var(--rose-light);color:var(--rose)}
.login-msg.notice{background:var(--accent-light);color:var(--accent)}

/* ---------------------------------------------------------------------
   STREAMING_CHUNK:Styling transcripts...
--------------------------------------------------------------------- */
.transcript-header{
  display:flex;align-items:center;gap:12px;background:var(--surface);border:1px solid var(--border);
  border-radius:var(--r-lg);padding:14px 18px;margin-bottom:16px;box-shadow:var(--sh-sm);
}
.transcript-avatar{
  width:38px;height:38px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;
  font-size:15px;font-weight:600;color:#fff;
}
.transcript-info{flex:1;min-width:0}
.transcript-info .t-name{font-size:14.5px;font-weight:600;color:var(--text)}
.transcript-info .t-name .with{font-weight:400;color:var(--text3)}
.transcript-info .t-meta{font-size:12px;color:var(--text3);margin-top:2px;display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.back-link{
  display:inline-flex;align-items:center;gap:6px;margin-bottom:14px;font-size:13px;color:var(--text2);font-weight:500;
}
.back-link:hover{color:var(--accent)}

.transcript-scroll{background:var(--surface2);border-radius:var(--r-lg);padding:20px 18px;max-height:70vh;overflow-y:auto}
@media (max-width:640px){ .transcript-scroll{padding:14px 10px;max-height:none} }

.t-day-divider{text-align:center;font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:.05em;margin:18px 0 12px;font-weight:600}
.t-day-divider:first-child{margin-top:0}

.t-row{display:flex;margin-bottom:2px}
.t-row.mine{justify-content:flex-end}
.t-row.grouped{margin-bottom:2px}
.t-row + .t-row.grouped{margin-top:-6px}

.t-bubble{
  max-width:72%;padding:9px 13px;border-radius:16px;font-size:13.5px;line-height:1.45;
  white-space:pre-wrap;word-wrap:break-word;box-shadow:0 1px 1px rgba(24,22,15,.04);
}
@media (max-width:640px){ .t-bubble{max-width:84%} }
.t-bubble.bot{background:var(--surface);color:var(--text);border-bottom-left-radius:4px}
.t-bubble.mine{background:var(--accent);color:#fff;border-bottom-right-radius:4px}
.t-row.grouped .t-bubble.bot{border-top-left-radius:16px;border-bottom-left-radius:4px}
.t-row.grouped .t-bubble.mine{border-top-right-radius:16px;border-bottom-right-radius:4px}

.t-time{font-size:10.5px;color:var(--text3);margin:3px 4px 10px;text-align:right}
.t-row.mine + .t-time{text-align:right}
.t-time.left{text-align:left}
</style>
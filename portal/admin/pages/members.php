<?php
/**
 * * Kounselia Admin — Members & CRM.
 *
 * Lists all active users. Includes a powerful slide-out drawer
 * for deep-diving into individual user profiles, checking safety
 * flags, manually upgrading plans, or banning abusers.
 */
require_once __DIR__ . '/../inc/admin-auth.php';
require_once __DIR__ . '/../inc/admin-helpers.php';
$kounselia_admin_active = 'members';

$ajax_url = set_url_scheme( admin_url( 'admin-ajax.php' ), is_ssl() ? 'https' : 'http' );
$nonce    = wp_create_nonce( 'kounselia_admin_nonce' );

// Fetch members (Limit to 500 for performance, ideally we'd paginate via AJAX for 10k+ users)
$members = get_users( array(
    'role'    => 'subscriber',
    'orderby' => 'registered',
    'order'   => 'DESC',
    'number'  => 500
) );
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kounselia Admin — Members</title>
<meta name="robots" content="noindex, nofollow">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
<?php require __DIR__ . '/../inc/admin-styles.php'; ?>
<style>
/* */
/* Elevated Search Bar */
.search-bar {
  position: relative;
  max-width: 420px;
  margin-bottom: 24px;
}
.search-bar input {
  width: 100%;
  padding: 13px 16px 13px 44px;
  border: 1px solid var(--border);
  border-radius: var(--r-md);
  font-family: inherit;
  font-size: 14px;
  background: var(--surface);
  color: var(--text);
  box-shadow: var(--sh-sm);
  transition: all 0.2s ease;
}
.search-bar input:focus {
  outline: none;
  border-color: var(--accent);
  box-shadow: 0 0 0 3px var(--accent-light);
}
.search-icon {
  position: absolute;
  left: 16px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--text3);
  font-size: 18px;
  pointer-events: none;
}

/* Premium CRM Table Overrides */
.crm-table-wrap {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  box-shadow: var(--sh-sm);
  overflow: hidden;
}
table.admin-table {
  width: 100%; border-collapse: collapse; margin: 0;
}
table.admin-table thead {
  background: var(--surface2); /* Grounds the header visually */
}
table.admin-table thead th {
  padding: 16px 24px;
  font-size: 11px;
  font-weight: 600;
  color: var(--text3);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  border-bottom: 1px solid var(--border);
}
table.admin-table tbody td {
  padding: 16px 24px;
  border-bottom: 1px solid var(--border);
  color: var(--text);
  vertical-align: middle;
}
table.admin-table tbody tr:last-child td {
  border-bottom: none;
}
table.admin-table tbody tr {
  cursor: pointer;
  transition: background 0.2s ease;
}
table.admin-table tbody tr:hover td {
  background: #FAFAF8; /* Soft hover state instead of stark grey */
}

/* UI Elements inside table */
.user-avatar { width:36px; height:36px; border-radius:50%; background:var(--accent-light); color:var(--accent); display:flex; align-items:center; justify-content:center; font-weight:600; font-size:14px; flex-shrink:0; }
.plan-badge { font-size:10.5px; font-weight:600; padding:4px 10px; border-radius:20px; text-transform:uppercase; letter-spacing:0.5px; }
.plan-badge.pro { background:var(--gold-light); color:var(--gold); }
.plan-badge.free { background:var(--surface2); color:var(--text3); }
.plan-badge.banned { background:var(--rose-light); color:var(--rose); }

.btn-view-profile {
  display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 500;
  padding: 6px 14px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-full);
  color: var(--accent); transition: all 0.2s ease; text-decoration: none;
}
.btn-view-profile:hover {
  background: var(--accent); color: #fff; border-color: var(--accent);
}

/* Mobile Click Affordance */
.row-chevron { display: none; color: var(--text3); font-size: 18px; margin-left: auto; }
@media (max-width: 720px) {
  .row-chevron { display: block; }
  /* On mobile, our tables become stacked cards. We need to tweak the Plan cell to fit the chevron nicely */
  table.admin-table td[data-label="Plan"] { justify-content: flex-start; gap: 8px; }
  table.admin-table td[data-label="Plan"]::after { content: ''; flex-grow: 1; } /* pushes chevron right */
  table.admin-table td[data-label="Action"] { justify-content: flex-end; }
}

/* */
.drawer-overlay {
  position: fixed; inset: 0; background: rgba(24, 22, 15, 0.4); backdrop-filter: blur(2px); -webkit-backdrop-filter: blur(2px);
  z-index: 100; opacity: 0; pointer-events: none; transition: opacity 0.3s ease;
}
.drawer-overlay.active { opacity: 1; pointer-events: auto; }
.drawer {
  position: fixed; top: 0; right: 0; bottom: 0; width: 100%; max-width: 460px;
  background: var(--bg); box-shadow: -10px 0 40px rgba(0,0,0,0.1);
  transform: translateX(100%); transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
  z-index: 101; display: flex; flex-direction: column;
}
.drawer.active { transform: translateX(0); }
.drawer-head {
  padding: 24px 28px; background: var(--surface); border-bottom: 1px solid var(--border);
  display: flex; justify-content: space-between; align-items: flex-start;
}
.drawer-close { background: var(--surface2); border: none; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--text2); transition: all 0.2s; }
.drawer-close:hover { background: var(--border); color: var(--text); }
.d-user { display: flex; gap: 16px; align-items: center; }
.d-av { width: 56px; height: 56px; border-radius: 50%; background: var(--accent-light); color: var(--accent); display: flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 600; }
.d-meta h2 { font-family: 'Cormorant Garamond', serif; font-size: 24px; font-weight: 500; margin-bottom: 2px; }
.d-meta p { font-size: 13px; color: var(--text3); }

.drawer-body { padding: 24px 28px; overflow-y: auto; flex: 1; }

.d-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 32px; }
.d-stat-box { background: var(--surface); border: 1px solid var(--border); padding: 16px; border-radius: var(--r-md); box-shadow: var(--sh-sm); }
.d-stat-box .num { font-size: 24px; font-weight: 500; font-family: 'Cormorant Garamond', serif; color: var(--accent); }
.d-stat-box .lbl { font-size: 11.5px; color: var(--text3); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; margin-top: 4px; }
.d-stat-box.danger { border-color: #F3D9E0; background: var(--rose-light); }
.d-stat-box.danger .num { color: var(--rose); }

.d-section { margin-bottom: 32px; }
.d-section h4 { font-size: 13px; font-weight: 600; text-transform: uppercase; color: var(--text3); letter-spacing: 1px; margin-bottom: 12px; }

/* Control Switches */
.d-control { display: flex; justify-content: space-between; align-items: center; padding: 14px 16px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-sm); margin-bottom: 10px; }
.d-control div { font-size: 14px; font-weight: 500; }
.d-control span { display: block; font-size: 12px; color: var(--text3); font-weight: 400; margin-top: 2px; }
.btn-action { padding: 8px 16px; border-radius: 50px; font-size: 12.5px; font-weight: 500; border: none; cursor: pointer; transition: all 0.2s; }
.btn-action.pro { background: var(--gold); color: #fff; }
.btn-action.downgrade { background: var(--surface2); color: var(--text2); }
.btn-action.ban { background: var(--rose); color: #fff; }
.btn-action.unban { background: var(--sage); color: #fff; }

.d-session { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid var(--border); }
.d-session:last-child { border-bottom: none; }
.d-session-info { font-size: 13.5px; font-weight: 500; }
.d-session-date { font-size: 12px; color: var(--text3); }
.d-session-link { font-size: 12px; color: var(--accent); text-decoration: none; font-weight: 500; }
.d-session-link:hover { text-decoration: underline; }

/* */
.d-tabs { display: flex; border-bottom: 1px solid var(--border); padding: 0 28px; background: var(--surface); }
.d-tab { padding: 14px 16px; border: none; background: none; font-size: 13.5px; font-weight: 500; color: var(--text3); cursor: pointer; border-bottom: 2px solid transparent; transition: all 0.2s; font-family: inherit; }
.d-tab:hover { color: var(--text); }
.d-tab.active { color: var(--accent); border-bottom-color: var(--accent); }
.d-pane { display: none; padding: 24px 28px; overflow-y: auto; flex: 1; }
.d-pane.active { display: block; animation: fadeIn 0.3s ease; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

.m-active-state { background: var(--accent-light); padding: 14px 16px; border-radius: var(--r-sm); border: 1px solid #C8D8EC; color: var(--accent); margin-bottom: 24px; }
.m-active-state .m-label { color: var(--accent); display: flex; align-items: center; gap: 6px; margin-bottom: 6px; }
.m-active-state .m-text { font-weight: 500; font-size: 13.5px; line-height: 1.5; }

.m-block { margin-bottom: 24px; }
.m-label { font-size: 11px; font-weight: 600; text-transform: uppercase; color: var(--text3); letter-spacing: 1px; margin-bottom: 8px; display: block; }
.m-text { font-size: 13.5px; color: var(--text); line-height: 1.6; }
.m-empty { color: var(--text3); font-style: italic; font-size: 13px; }

.m-chip-list { display: flex; flex-wrap: wrap; gap: 6px; }
.m-chip { background: var(--surface2); padding: 4px 10px; border-radius: 6px; font-size: 12px; color: var(--text2); border: 1px solid var(--border); }

.m-timeline { border-left: 2px solid var(--border); padding-left: 14px; margin-left: 6px; margin-top: 10px; }
.m-tl-item { position: relative; margin-bottom: 16px; }
.m-tl-item:last-child { margin-bottom: 0; }
.m-tl-item::before { content: ''; position: absolute; left: -21px; top: 4px; width: 10px; height: 10px; border-radius: 50%; background: var(--accent); border: 2px solid var(--surface); }
.m-tl-year { font-size: 11.5px; font-weight: 600; color: var(--accent); margin-bottom: 2px; }
.m-tl-event { font-size: 13.5px; color: var(--text); font-weight: 500; }
.m-tl-impact { font-size: 12.5px; color: var(--text2); line-height: 1.5; margin-top: 4px; }

.m-emo-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 10px; }
.m-emo-card { background: var(--bg); border: 1px solid var(--border); padding: 14px; border-radius: var(--r-sm); }
.m-emo-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 6px; }
.m-emo-entity { font-weight: 600; font-size: 13px; color: var(--text); }
.m-emo-badge { font-size: 10px; font-weight: 600; padding: 2px 8px; border-radius: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
.m-emo-feel { font-size: 13px; color: var(--accent); font-weight: 500; margin-bottom: 4px; }
.m-emo-ctx { font-size: 12px; color: var(--text2); line-height: 1.5; }

.i-box { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-sm); padding: 18px; margin-bottom: 16px; box-shadow: var(--sh-sm); }
.i-box h4 { font-size: 12.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
.i-box ul { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 10px; }
.i-box li { font-size: 13.5px; color: var(--text2); position: relative; padding-left: 18px; line-height: 1.5; }
.i-box li::before { content: ''; position: absolute; left: 0; top: 6px; width: 6px; height: 6px; border-radius: 50%; }
.i-patterns h4 { color: var(--accent); } .i-patterns li::before { background: var(--accent); }
.i-growth h4 { color: var(--sage); } .i-growth li::before { background: var(--sage); }
.i-fears h4 { color: var(--rose); } .i-fears li::before { background: var(--rose); }
.i-blind h4 { color: var(--plum); } .i-blind li::before { background: var(--plum); }

#toast-container { position: fixed; bottom: 24px; right: 24px; z-index: 999; }
.toast { background: var(--text); color: #fff; padding: 14px 20px; border-radius: var(--r-sm); font-size: 14px; box-shadow: var(--sh-lg); margin-top: 10px; animation: slideUp 0.3s ease; }
@keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
</style>
</head>
<body>

<?php require __DIR__ . '/../inc/admin-nav.php'; ?>

<!-- -->
<div class="admin-body">
  <h1 class="admin-title">Members CRM</h1>
  <div class="admin-subtitle">Manage your <?php echo count($members); ?> registered users.</div>

  <div class="search-bar">
    <i class="ti ti-search search-icon"></i>
    <input type="text" id="memberSearch" placeholder="Search by name or email..." onkeyup="filterMembers()">
  </div>

  <div class="crm-table-wrap">
    <table class="admin-table" id="membersTable">
      <thead>
        <tr>
          <th>User</th>
          <th>Email</th>
          <th>Joined</th>
          <th>Plan</th>
          <th style="text-align:right">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ( $members as $u ) : 
          $initial = strtoupper(substr($u->display_name ?: $u->user_email, 0, 1));
          $plan = get_user_meta( $u->ID, 'kounselia_plan', true ) ?: 'free';
          $banned = get_user_meta( $u->ID, 'kounselia_banned', true );
        ?>
        <tr onclick="openDrawer(<?php echo $u->ID; ?>)">
          <td data-label="User">
            <div class="cell-who">
              <div class="user-avatar"><?php echo esc_html($initial); ?></div>
              <strong style="font-size:14.5px;"><?php echo esc_html( $u->display_name ?: '—' ); ?></strong>
            </div>
          </td>
          <td data-label="Email"><?php echo esc_html( $u->user_email ); ?></td>
          <td data-label="Joined"><?php echo date_i18n( 'M j, Y', strtotime( $u->user_registered ) ); ?></td>
          <td data-label="Plan">
            <?php if($banned): ?>
                <span class="plan-badge banned">Banned</span>
            <?php else: ?>
                <span class="plan-badge <?php echo esc_attr($plan); ?>"><?php echo esc_html($plan); ?></span>
            <?php endif; ?>
            <i class="ti ti-chevron-right row-chevron"></i>
          </td>
          <td data-label="Action" style="text-align:right;">
            <a href="member-profile.php?id=<?php echo esc_attr( $u->ID ); ?>" class="btn-view-profile" onclick="event.stopPropagation();">
              <i class="ti ti-brain"></i> Intelligence Profile
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- -->
<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>
<div class="drawer" id="crmDrawer">
  <div class="drawer-head">
    <div class="d-user">
      <div class="d-av" id="d-initial">?</div>
      <div class="d-meta">
        <h2 id="d-name">Loading...</h2>
        <p id="d-email">user@email.com</p>
      </div>
    </div>
    <div style="display:flex; gap:12px; align-items:center;">
      <a href="#" id="d-full-profile-btn" class="btn-view-profile"><i class="ti ti-brain"></i> Full Profile</a>
      <button class="drawer-close" onclick="closeDrawer()"><i class="ti ti-x"></i></button>
    </div>
  </div>

  <div class="d-tabs" id="d-tabs" style="display:none;">
    <button class="d-tab active" data-target="pane-overview" onclick="switchTab('pane-overview')">Overview</button>
    <button class="d-tab" data-target="pane-memory" onclick="switchTab('pane-memory')">Memory Profile</button>
    <button class="d-tab" data-target="pane-milestones" onclick="switchTab('pane-milestones')">Milestones</button>
  </div>
  
  <div class="drawer-body" id="d-body" style="display:none; padding:0; display:flex; flex-direction:column;">
    
    <!-- Overview Pane -->
    <div class="d-pane active" id="pane-overview">
      <div class="d-stats">
        <div class="d-stat-box">
          <div class="num" id="d-sessions">0</div>
          <div class="lbl">Total Sessions</div>
        </div>
        <div class="d-stat-box">
          <div class="num" id="d-messages">0</div>
          <div class="lbl">Messages Sent</div>
        </div>
        <div class="d-stat-box" style="grid-column: span 2;">
          <div class="num" id="d-joined">Jan 1, 2024</div>
          <div class="lbl">Member Since</div>
        </div>
        <div class="d-stat-box danger" id="d-flags-box" style="grid-column: span 2; display: none;">
          <div class="num" id="d-flags">0</div>
          <div class="lbl">Safety Flags (Self-Harm/Crisis)</div>
        </div>
      </div>

      <div class="d-section">
        <h4>Access & Billing</h4>
        <div class="d-control">
          <div>Pro Plan <span>Unlocks deep memory & voice</span></div>
          <button class="btn-action" id="btn-plan" onclick="togglePlan()"></button>
        </div>
        <div class="d-control" style="border-color: #F3D9E0;">
          <div>Account Access <span>Block user & IP from chatting</span></div>
          <button class="btn-action" id="btn-ban" onclick="toggleBan()"></button>
        </div>
        <div class="d-control" style="border-color: #F3D9E0; background: var(--rose-light);">
          <div>Delete Account <span>Permanently erase user and data</span></div>
          <button class="btn-action ban" onclick="deleteUser()">Delete</button>
        </div>
      </div>

      <div class="d-section">
        <h4>Recent Sessions</h4>
        <div id="d-recent-sessions">
          <!-- Injected via JS -->
        </div>
      </div>
    </div>
    
    <div class="d-pane" id="pane-memory">
        <div id="m-content"></div>
    </div>

    <!-- Milestones Pane -->
    <div class="d-pane" id="pane-milestones">
        <div id="i-content"></div>
    </div>

  </div>
  
  <!-- Loading State -->
  <div id="d-loading" style="padding: 40px; text-align: center; color: var(--text3);">
    <i class="ti ti-loader-2" style="font-size: 24px; animation: spin 1s linear infinite;"></i>
    <p style="margin-top: 10px; font-size: 13px;">Loading profile...</p>
  </div>
</div>

<div id="toast-container"></div>

<!-- -->
<script>
const ADMIN_AJAX_URL = "<?php echo esc_js( $ajax_url ); ?>";
const ADMIN_NONCE    = "<?php echo esc_js( $nonce ); ?>";
let activeUserId = null;
let currentPlan = 'free';
let isBanned = 0;

function switchTab(targetId) {
  document.querySelectorAll('.d-tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.d-pane').forEach(p => p.classList.remove('active'));
  
  document.querySelector(`.d-tab[data-target="${targetId}"]`).classList.add('active');
  document.getElementById(targetId).classList.add('active');
}

function escHTML(t){ if(!t)return''; return String(t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function renderMemory(data) {
    const container = document.getElementById('m-content');
    if (!data) {
        container.innerHTML = `<div class="empty-state" style="padding: 20px 0;"><i class="ti ti-brain" style="font-size: 24px; display:block; margin-bottom:8px; color:var(--text3);"></i><p class="m-empty">No memory profile established yet.</p></div>`;
        return;
    }

    const bText = (lbl, text) => `<div class="m-block"><span class="m-label">${lbl}</span><div class="m-text">${text ? escHTML(text) : '<span class="m-empty">Not established</span>'}</div></div>`;
    const bChips = (lbl, arr) => `<div class="m-block"><span class="m-label">${lbl}</span>${arr && arr.length ? `<div class="m-chip-list">${arr.map(a => `<span class="m-chip">${escHTML(a)}</span>`).join('')}</div>` : '<span class="m-empty">None noted</span>'}</div>`;
    
    let timelineHtml = '<span class="m-empty">No events recorded</span>';
    if (data.life_timeline && data.life_timeline.length) {
        timelineHtml = `<div class="m-timeline">` + data.life_timeline.map(t => `
            <div class="m-tl-item">
                <div class="m-tl-year">${escHTML(t.year)}</div>
                <div class="m-tl-event">${escHTML(t.event)}</div>
                ${t.impact ? `<div class="m-tl-impact">${escHTML(t.impact)}</div>` : ''}
            </div>
        `).join('') + `</div>`;
    }

    let emoHtml = '<span class="m-empty">No anchors recorded</span>';
    if (data.emotional_map && Object.keys(data.emotional_map).length) {
        emoHtml = `<div class="m-emo-grid">` + Object.entries(data.emotional_map).map(([entity, d]) => `
            <div class="m-emo-card">
                <div class="m-emo-head"><span class="m-emo-entity">${escHTML(entity)}</span><span class="m-emo-badge badge-${d.intensity}">${escHTML(d.intensity)}</span></div>
                <div class="m-emo-feel">${escHTML(d.emotion)}</div>
                ${d.context ? `<div class="m-emo-ctx">${escHTML(d.context)}</div>` : ''}
            </div>
        `).join('') + `</div>`;
    }

    container.innerHTML = `
        ${data.temporary_context ? `
        <div class="m-active-state">
            <span class="m-label"><i class="ti ti-activity"></i> Active State (AI Managed)</span>
            <div class="m-text">${escHTML(data.temporary_context)}</div>
        </div>` : ''}
        
        ${bText('Identity & Core Self', data.identity)}
        ${bText('Career', data.career)}
        ${bChips('Goals', data.goals)}
        ${bChips('Core Values', data.values)}
        ${bChips('Habits & Patterns', data.habits)}
        ${bChips('Triggers', data.triggers)}
        
        <div class="m-block"><span class="m-label">Life Timeline</span>${timelineHtml}</div>
        <div class="m-block"><span class="m-label">Emotional Memory Map</span>${emoHtml}</div>
    `;
}

function renderReflection(data) {
    const container = document.getElementById('i-content');
    if (!data) {
        container.innerHTML = `<div class="empty-state" style="padding: 20px 0;"><i class="ti ti-bulb" style="font-size: 24px; display:block; margin-bottom:8px; color:var(--text3);"></i><p class="m-empty">No milestones generated yet. User needs more sessions.</p></div>`;
        return;
    }

    const bList = (css, icon, title, arr) => `
        <div class="i-box ${css}">
            <h4><i class="ti ${icon}"></i> ${title}</h4>
            <ul>${arr.map(i => `<li>${escHTML(i)}</li>`).join('')}</ul>
        </div>
    `;

    container.innerHTML = `
        ${bList('i-patterns', 'ti-repeat', 'Recurring Patterns', data.patterns || [])}
        ${bList('i-growth', 'ti-trending-up', 'Growth & Healing', data.growth || [])}
        ${bList('i-fears', 'ti-ghost', 'Core Fears', data.recurring_fears || [])}
        ${bList('i-blind', 'ti-eye-closed', 'Blind Spots', data.blind_spots || [])}
    `;
}

function showToast(msg) {
  const container = document.getElementById('toast-container');
  const toast = document.createElement('div');
  toast.className = 'toast';
  toast.textContent = msg;
  container.appendChild(toast);
  setTimeout(() => {
    toast.style.opacity = '0';
    setTimeout(() => toast.remove(), 300);
  }, 3000);
}

function filterMembers() {
  const input = document.getElementById('memberSearch').value.toLowerCase();
  const rows = document.querySelectorAll('#membersTable tbody tr');
  rows.forEach(row => {
    const text = row.innerText.toLowerCase();
    row.style.display = text.includes(input) ? '' : 'none';
  });
}

function closeDrawer() {
  document.getElementById('crmDrawer').classList.remove('active');
  document.getElementById('drawerOverlay').classList.remove('active');
}

function openDrawer(userId) {
  activeUserId = userId;
  document.getElementById('crmDrawer').classList.add('active');
  document.getElementById('drawerOverlay').classList.add('active');
  
  document.getElementById('d-body').style.display = 'none';
  document.getElementById('d-tabs').style.display = 'none';
  document.getElementById('d-loading').style.display = 'block';

  // Reset to overview tab
  switchTab('pane-overview');

  fetch(ADMIN_AJAX_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'kounselia_admin_get_member', nonce: ADMIN_NONCE, user_id: userId })
  })
  .then(res => res.json())
  .then(data => {
    document.getElementById('d-loading').style.display = 'none';
    if(data.success) {
      const d = data.data;
      document.getElementById('d-body').style.display = 'flex';
      document.getElementById('d-tabs').style.display = 'flex';
      
      document.getElementById('d-initial').textContent = d.name.charAt(0).toUpperCase();
      document.getElementById('d-name').textContent = d.name;
      document.getElementById('d-email').textContent = d.email;
      document.getElementById('d-sessions').textContent = d.sessions;
      document.getElementById('d-messages').textContent = d.messages;
      document.getElementById('d-joined').textContent = d.joined;
      
      // Hook up the Full Profile button in the drawer header
      document.getElementById('d-full-profile-btn').href = 'member-profile.php?id=' + userId;
      
      if(d.flags > 0) {
          document.getElementById('d-flags-box').style.display = 'block';
          document.getElementById('d-flags').textContent = d.flags;
      } else {
          document.getElementById('d-flags-box').style.display = 'none';
      }

      currentPlan = d.plan;
      isBanned = d.is_banned;
      updateControlButtons();

      // Render Rich Data
      renderMemory(d.core_memory);
      renderReflection(d.reflection);

      let sessionsHtml = '';
      if(d.recent.length === 0) {
          sessionsHtml = '<div style="font-size:13px; color:var(--text3);">No sessions yet.</div>';
      } else {
          d.recent.forEach(s => {
             sessionsHtml += `
             <div class="d-session">
               <div>
                 <div class="d-session-info">${s.counselor_slug.charAt(0).toUpperCase() + s.counselor_slug.slice(1)}</div>
                 <div class="d-session-date">${new Date(s.started_at.replace(' ', 'T')).toLocaleDateString()}</div>
               </div>
               <a href="/portal/admin/pages/session.php?id=${s.id}" class="d-session-link">View</a>
             </div>`;
          });
      }
      document.getElementById('d-recent-sessions').innerHTML = sessionsHtml;
    } else {
      showToast('Error loading user.');
      closeDrawer();
    }
  });
}

function updateControlButtons() {
    const pBtn = document.getElementById('btn-plan');
    if(currentPlan === 'pro') {
        pBtn.className = 'btn-action downgrade';
        pBtn.textContent = 'Remove Pro';
    } else {
        pBtn.className = 'btn-action pro';
        pBtn.textContent = 'Upgrade to Pro';
    }

    const bBtn = document.getElementById('btn-ban');
    if(isBanned) {
        bBtn.className = 'btn-action unban';
        bBtn.textContent = 'Unban User';
    } else {
        bBtn.className = 'btn-action ban';
        bBtn.textContent = 'Ban User';
    }
}

function executeUpdate(action) {
    if(!activeUserId) return;
    fetch(ADMIN_AJAX_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'kounselia_admin_update_member', nonce: ADMIN_NONCE, user_id: activeUserId, do_action: action })
    })
    .then(res => res.json())
    .then(data => {
        if(data.success) {
            showToast(data.data.message);
            if(action === 'upgrade') currentPlan = 'pro';
            if(action === 'downgrade') currentPlan = 'free';
            if(action === 'ban') isBanned = 1;
            if(action === 'unban') isBanned = 0;
            updateControlButtons();
            
            // To ensure the background table visually updates, normally we'd 
            // manipulate the DOM directly here, but for safety we can just let 
            // the user refresh the page when they are done. 
        }
    });
}

function togglePlan() {
    if(currentPlan === 'pro') {
        if(confirm("Remove Pro status from this user?")) executeUpdate('downgrade');
    } else {
        if(confirm("Grant Pro status to this user? They will bypass limits.")) executeUpdate('upgrade');
    }
}

function toggleBan() {
    if(isBanned) {
        if(confirm("Restore this user's access?")) executeUpdate('unban');
    } else {
        if(confirm("Ban this user and their IP address? They will be locked out immediately.")) executeUpdate('ban');
    }
}

function deleteUser() {
    if(confirm("DANGER: Are you absolutely sure you want to permanently delete this user and ALL their conversation history? This cannot be undone.")) {
        executeUpdate('delete');
        setTimeout(() => window.location.reload(), 1500); // Reload to clear them from the table
    }
}
</script>
</body>
</html>
<?php
/**
 * STREAMING_CHUNK:Bootstrapping the team management engine...
 * Kounselia Admin — Team Management.
 *
 * Modular access control system. Assign specific module access to staff,
 * generate passwords locally without sending emails, and manage permissions.
 */
require_once __DIR__ . '/../inc/admin-auth.php';
require_once __DIR__ . '/../inc/admin-helpers.php';
$kounselia_admin_active = 'team';

$ajax_url = set_url_scheme( admin_url( 'admin-ajax.php' ), is_ssl() ? 'https' : 'http' );
$nonce    = wp_create_nonce( 'kounselia_admin_nonce' );

if ( ! current_user_can('administrator') ) {
    wp_die('You do not have permission to access team management.');
}

$staff = get_users( array(
    'role__in' => array('administrator', 'kounselia_staff'),
    'orderby'  => 'registered',
    'order'    => 'ASC'
) );

if ( ! is_array($staff) ) {
    $staff = array();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kounselia Admin — Team</title>
<meta name="robots" content="noindex, nofollow">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
<?php require __DIR__ . '/../inc/admin-styles.php'; ?>
<style>
.header-wrap { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
.btn-primary { padding: 12px 24px; border-radius: var(--r-sm); background: var(--accent); color: #fff; border: none; font-family: inherit; font-size: 14px; font-weight: 500; cursor: pointer; transition: all 0.2s ease; display: inline-flex; align-items: center; gap: 8px; }
.btn-primary:hover { background: var(--accent2); transform: translateY(-1px); }

.crm-table-wrap { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-lg); box-shadow: var(--sh-sm); overflow: hidden; }
table.admin-table { width: 100%; border-collapse: collapse; margin: 0; }
table.admin-table thead { background: var(--surface2); }
table.admin-table thead th { padding: 16px 24px; font-size: 11px; font-weight: 600; color: var(--text3); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid var(--border); }
table.admin-table tbody td { padding: 16px 24px; border-bottom: 1px solid var(--border); color: var(--text); vertical-align: middle; }
table.admin-table tbody tr:last-child td { border-bottom: none; }
table.admin-table tbody tr:hover td { background: #FAFAF8; }

.user-avatar { width:36px; height:36px; border-radius:50%; background:var(--accent-light); color:var(--accent); display:flex; align-items:center; justify-content:center; font-weight:600; font-size:14px; flex-shrink:0; }
.role-badge { font-size:10px; font-weight:600; padding:4px 10px; border-radius:20px; text-transform:uppercase; letter-spacing:0.5px; }
.role-badge.super { background:var(--gold-light); color:var(--gold); }
.role-badge.staff { background:var(--sage-light); color:var(--sage); }

.action-btn { background: none; border: none; color: var(--accent); font-size: 13px; font-weight: 500; cursor: pointer; padding: 4px 10px; border-radius: 4px; transition: background 0.2s; text-decoration: none; display: inline-block; }
.action-btn:hover { background: var(--accent-light); }
.action-btn.danger { color: var(--rose); }
.action-btn.danger:hover { background: var(--rose-light); }

.modal-overlay { position: fixed; inset: 0; background: rgba(24, 22, 15, 0.4); backdrop-filter: blur(2px); z-index: 100; opacity: 0; pointer-events: none; transition: opacity 0.3s ease; display: flex; align-items: center; justify-content: center; }
.modal-overlay.active { opacity: 1; pointer-events: auto; }
.modal-box { background: var(--surface); width: 100%; max-width: 480px; max-height: 90vh; overflow-y: auto; border-radius: var(--r-lg); padding: 32px; box-shadow: var(--sh-lg); transform: translateY(20px); transition: transform 0.3s ease; }
.modal-overlay.active .modal-box { transform: translateY(0); }
.modal-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
.modal-head h2 { font-family: 'Cormorant Garamond', serif; font-size: 24px; color: var(--accent); font-weight: 500; }
.modal-close { background: var(--surface2); border: none; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--text2); }

.form-group { margin-bottom: 18px; }
.form-group label { display: block; font-size: 13px; font-weight: 500; color: var(--text2); margin-bottom: 6px; }
.form-group input[type="text"], .form-group input[type="email"], .form-group select { width: 100%; padding: 12px 14px; border: 1px solid var(--border); border-radius: var(--r-sm); font-family: inherit; font-size: 14.5px; background: var(--bg); color: var(--text); }
.form-group input:focus, .form-group select:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-light); }

.permissions-box { background: var(--surface2); border: 1px solid var(--border); border-radius: var(--r-sm); padding: 16px; margin-bottom: 24px; display: none; }
.permissions-box.active { display: block; }
.perm-row { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.perm-row:last-child { margin-bottom: 0; }
.perm-row label { margin-bottom: 0; font-size: 14px; color: var(--text); cursor: pointer; }

.cred-box { background: var(--bg); border: 1px solid var(--border); border-radius: var(--r-sm); padding: 12px; display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.cred-val { font-family: monospace; font-size: 16px; font-weight: 600; color: var(--accent); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cred-btn { background: var(--surface); border: 1px solid var(--border); padding: 6px 12px; border-radius: 6px; font-size: 12px; cursor: pointer; display: flex; align-items: center; gap: 6px; }
.cred-btn:hover { border-color: var(--accent); color: var(--accent); }
.cred-btn.copied { border-color: var(--sage); color: var(--sage); }

.perm-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
.perm-chip { background: var(--surface2); font-size: 10.5px; padding: 2px 8px; border-radius: 4px; color: var(--text3); }

#toast-container { position: fixed; bottom: 24px; right: 24px; z-index: 999; }
.toast { background: var(--text); color: #fff; padding: 14px 20px; border-radius: var(--r-sm); font-size: 14px; box-shadow: var(--sh-lg); margin-top: 10px; animation: slideUp 0.3s ease; }
@keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>
</head>
<body>

<?php require __DIR__ . '/../inc/admin-nav.php'; ?>

<div class="admin-body">
  <div class="header-wrap">
    <div>
      <h1 class="admin-title">Team Management</h1>
      <div class="admin-subtitle">Assign modular access control to Support Staff.</div>
    </div>
    <button class="btn-primary" onclick="openCreateModal()"><i class="ti ti-plus"></i> Add Team Member</button>
  </div>

  <div class="crm-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Email</th>
          <th>Access Level</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ( $staff as $u ) : 
          $display = $u->display_name ?: $u->user_email;
          $initial = strtoupper(substr($display, 0, 1));
          
          $roles = is_array($u->roles) ? $u->roles : array();
          $is_super = in_array('administrator', $roles);
          
          $perms = get_user_meta($u->ID, 'kounselia_permissions', true);
          $perms_arr = $perms ? json_decode($perms, true) : array();
          if ( ! is_array($perms_arr) ) {
              $perms_arr = array();
          }
        ?>
        <tr>
          <td data-label="Name">
            <div class="cell-who">
              <div class="user-avatar"><?php echo esc_html($initial); ?></div>
              <strong style="font-size:14.5px;"><?php echo esc_html( $display ); ?></strong>
            </div>
          </td>
          <td data-label="Email"><?php echo esc_html( $u->user_email ); ?></td>
          <td data-label="Access Level">
            <?php if($is_super): ?>
                <span class="role-badge super">Super Admin</span>
                <div class="perm-chips"><span class="perm-chip">All Access</span></div>
            <?php else: ?>
                <span class="role-badge staff">Support Staff</span>
                <div class="perm-chips">
                    <?php 
                    if(empty($perms_arr)) {
                        echo '<span class="perm-chip">No Access</span>';
                    } else {
                        foreach($perms_arr as $p) {
                            echo '<span class="perm-chip">'.esc_html(ucfirst(str_replace('-',' ',$p))).'</span>';
                        }
                    }
                    ?>
                </div>
            <?php endif; ?>
          </td>
          <td data-label="Actions" style="text-align:right;">
             <?php if( $u->ID !== get_current_user_id() ): ?>
                <button class="action-btn" onclick='openEditModal(<?php echo (int) $u->ID; ?>, <?php echo wp_json_encode($is_super ? "super_admin" : "kounselia_staff"); ?>, <?php echo wp_json_encode($perms_arr); ?>)'>Edit</button>
                <button class="action-btn" onclick="forceLogout(<?php echo (int) $u->ID; ?>)" title="Sign this person out of every device immediately">Force logout</button>
                <button class="action-btn danger" onclick="removeStaff(<?php echo (int) $u->ID; ?>)">Remove</button>
             <?php else: ?>
                <span style="font-size:12px; color:var(--text3);">You</span>
             <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-overlay" id="formModal">
  <div class="modal-box">
    <div class="modal-head">
      <h2 id="fm-title">Add Team Member</h2>
      <button class="modal-close" onclick="closeModals()"><i class="ti ti-x"></i></button>
    </div>
    
    <input type="hidden" id="fm-user-id" value="">

    <div class="form-group" id="grp-name">
      <label>Full Name</label>
      <input type="text" id="fm-name" placeholder="Jane Doe">
    </div>
    <div class="form-group" id="grp-email">
      <label>Email Address</label>
      <input type="email" id="fm-email" placeholder="jane@kounselia.com">
    </div>
    <div class="form-group">
      <label>Access Level</label>
      <select id="fm-role" onchange="togglePermissionsBox()">
        <option value="kounselia_staff">Support Staff (Custom Access)</option>
        <option value="super_admin">Super Admin (Full Access)</option>
      </select>
    </div>

    <div class="permissions-box" id="fm-permissions">
        <label style="display:block; margin-bottom:12px; color:var(--text); font-weight:600;">Select Allowed Modules</label>
        <div class="perm-row"><input type="checkbox" id="p-members" value="members" class="perm-check"> <label for="p-members">Members CRM</label></div>
        <div class="perm-row"><input type="checkbox" id="p-counselors" value="counselors" class="perm-check"> <label for="p-counselors">Counselors Studio</label></div>
        <div class="perm-row"><input type="checkbox" id="p-safety" value="safety" class="perm-check"> <label for="p-safety">Safety & Flags</label></div>
        <div class="perm-row"><input type="checkbox" id="p-professionals" value="professionals" class="perm-check"> <label for="p-professionals">Professionals</label></div>
        <div class="perm-row"><input type="checkbox" id="p-plans" value="plans" class="perm-check"> <label for="p-plans">Plans & Pricing</label></div>
        <div class="perm-row"><input type="checkbox" id="p-conversations" value="conversations" class="perm-check"> <label for="p-conversations">Conversations</label></div>
        <div class="perm-row"><input type="checkbox" id="p-broadcasts" value="broadcasts" class="perm-check"> <label for="p-broadcasts">Broadcasts</label></div>
        <div class="perm-row"><input type="checkbox" id="p-audit-log" value="audit-log" class="perm-check"> <label for="p-audit-log">Audit Log</label></div>
    </div>

    <div class="perm-row" id="grp-regenerate" style="display:none; margin-bottom:20px; background:var(--rose-light); padding:12px; border-radius:8px;">
        <input type="checkbox" id="fm-regenerate"> <label for="fm-regenerate" style="color:var(--rose); font-weight:500;">Regenerate their password</label>
    </div>

    <button class="btn-primary" id="fm-btn-submit" style="width:100%; justify-content:center;" onclick="saveStaff()">Create Account</button>
  </div>
</div>

<div class="modal-overlay" id="credModal">
  <div class="modal-box">
    <div class="modal-head">
      <h2 style="color:var(--sage);"><i class="ti ti-check"></i> Account Ready</h2>
    </div>
    <p style="font-size:14px; color:var(--text2); margin-bottom:24px; line-height:1.6;">The account has been updated. Send these temporary credentials securely. The user will be forced to change this password when they sign in.</p>

    <div class="cred-box">
        <span class="cred-val" id="cred-email"></span>
        <button class="cred-btn" onclick="copyCred('cred-email', this)"><i class="ti ti-copy"></i> Copy</button>
    </div>
    <div class="cred-box">
        <span class="cred-val" id="cred-password"></span>
        <button class="cred-btn" onclick="copyCred('cred-password', this)"><i class="ti ti-copy"></i> Copy</button>
    </div>

    <button class="btn-primary" style="width:100%; justify-content:center; margin-top:12px; background:var(--surface2); color:var(--text);" onclick="window.location.reload()">Done</button>
  </div>
</div>

<div id="toast-container"></div>

<script>
const ADMIN_AJAX_URL = "<?php echo esc_js( $ajax_url ); ?>";
const ADMIN_NONCE    = "<?php echo esc_js( $nonce ); ?>";

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

function closeModals() {
    document.getElementById('formModal').classList.remove('active');
}

function togglePermissionsBox() {
    const role = document.getElementById('fm-role').value;
    const box = document.getElementById('fm-permissions');
    if(role === 'kounselia_staff') box.classList.add('active');
    else box.classList.remove('active');
}

function openCreateModal() {
    document.getElementById('fm-title').textContent = 'Add Team Member';
    document.getElementById('fm-user-id').value = '';
    document.getElementById('fm-name').value = '';
    document.getElementById('fm-email').value = '';
    document.getElementById('fm-role').value = 'kounselia_staff';
    document.querySelectorAll('.perm-check').forEach(cb => cb.checked = false);
    
    document.getElementById('grp-name').style.display = 'block';
    document.getElementById('grp-email').style.display = 'block';
    document.getElementById('grp-regenerate').style.display = 'none';
    document.getElementById('fm-btn-submit').textContent = 'Create Account';
    
    togglePermissionsBox();
    document.getElementById('formModal').classList.add('active');
}

function openEditModal(userId, role, perms) {
    document.getElementById('fm-title').textContent = 'Edit Permissions';
    document.getElementById('fm-user-id').value = userId;
    document.getElementById('fm-role').value = role;
    
    if (!Array.isArray(perms)) { perms = []; }
    
    document.querySelectorAll('.perm-check').forEach(cb => {
        cb.checked = perms.includes(cb.value);
    });

    document.getElementById('grp-name').style.display = 'none';
    document.getElementById('grp-email').style.display = 'none';
    document.getElementById('fm-regenerate').checked = false;
    document.getElementById('grp-regenerate').style.display = 'flex';
    document.getElementById('fm-btn-submit').textContent = 'Save Changes';

    togglePermissionsBox();
    document.getElementById('formModal').classList.add('active');
}

function saveStaff() {
    const userId = document.getElementById('fm-user-id').value;
    const isEdit = userId !== '';
    const name = document.getElementById('fm-name').value.trim();
    const email = document.getElementById('fm-email').value.trim();
    const role = document.getElementById('fm-role').value;
    const regen = document.getElementById('fm-regenerate') && document.getElementById('fm-regenerate').checked ? '1' : '0';

    if(!isEdit && (!name || !email)) { showToast('Name and Email are required.'); return; }

    const perms = [];
    if(role === 'kounselia_staff') {
        document.querySelectorAll('.perm-check:checked').forEach(cb => perms.push(cb.value));
    }

    const btn = document.getElementById('fm-btn-submit');
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.innerHTML = '<i class="ti ti-loader-2" style="animation:spin 1s linear infinite"></i> Processing...';

    const action = isEdit ? 'kounselia_admin_update_staff' : 'kounselia_admin_create_staff';
    const params = new URLSearchParams({ 
        action: action, nonce: ADMIN_NONCE, role: role, permissions: perms.join(',')
    });
    
    if(isEdit) {
        params.append('user_id', userId);
        params.append('regenerate_pw', regen);
    } else {
        params.append('name', name);
        params.append('email', email);
    }

    fetch(ADMIN_AJAX_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
    })
    .then(async res => {
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch(e) {
            console.error("Raw response:", text);
            throw new Error("Invalid JSON response from server. Check console.");
        }
    })
    .then(data => {
        if(data.success) {
            closeModals();
            if(data.data.credentials || data.data.new_password) {
                document.getElementById('cred-email').textContent = data.data.credentials ? data.data.credentials.email : 'Password updated for user';
                document.getElementById('cred-password').textContent = data.data.credentials ? data.data.credentials.password : data.data.new_password;
                document.getElementById('credModal').classList.add('active');
            } else {
                showToast(data.data.message);
                setTimeout(() => window.location.reload(), 1500);
            }
        } else {
            showToast(data.data.message || 'Error processing request.');
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    })
    .catch(err => {
        console.error('saveStaff error:', err);
        btn.disabled = false;
        btn.innerHTML = originalText;
        showToast('A server error occurred. Check browser console.');
    });
}

function forceLogout(userId) {
    if(!confirm("Sign this team member out of every device right now? They'll need to log in again.")) return;

    fetch(ADMIN_AJAX_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'kounselia_admin_revoke_session', nonce: ADMIN_NONCE, user_id: userId, all: '1' })
    })
    .then(res => res.json())
    .then(data => {
        showToast((data.data && data.data.message) || 'Signed out.');
    })
    .catch(() => showToast('A server error occurred.'));
}

function removeStaff(userId) {
    if(!confirm("Are you sure you want to permanently revoke this team member's access?")) return;
    
    fetch(ADMIN_AJAX_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'kounselia_admin_delete_staff', nonce: ADMIN_NONCE, user_id: userId })
    })
    .then(async res => {
        const text = await res.text();
        try { return JSON.parse(text); } catch(e) { throw new Error(text); }
    })
    .then(data => {
        if(data.success) {
            showToast('Access revoked.');
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast(data.data.message || 'Error removing staff.');
        }
    })
    .catch(err => {
        showToast('A server error occurred.');
    });
}

function copyCred(targetId, btn) {
    const text = document.getElementById(targetId).textContent;
    navigator.clipboard.writeText(text).then(() => {
        const originalHtml = btn.innerHTML;
        btn.classList.add('copied');
        btn.innerHTML = '<i class="ti ti-check"></i> Copied';
        setTimeout(() => {
            btn.classList.remove('copied');
            btn.innerHTML = originalHtml;
        }, 2000);
    });
}
</script>
</body>
</html>
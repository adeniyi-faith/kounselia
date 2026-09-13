<?php
/**
 * Kounselia Admin — Broadcasts & Newsletters.
 *
 * A composer interface to send beautiful, branded HTML emails to all active members.
 */
require_once __DIR__ . '/../inc/admin-auth.php';
require_once __DIR__ . '/../inc/admin-helpers.php';
$kounselia_admin_active = 'broadcasts';

$ajax_url = set_url_scheme( admin_url( 'admin-ajax.php' ), is_ssl() ? 'https' : 'http' );
$nonce    = wp_create_nonce( 'kounselia_admin_nonce' );

// Get total recipient count for the UI
global $wpdb;
$member_count = (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->users} INNER JOIN {$wpdb->usermeta} ON ({$wpdb->users}.ID = {$wpdb->usermeta}.user_id) WHERE {$wpdb->usermeta}.meta_key = '{$wpdb->prefix}capabilities' AND {$wpdb->usermeta}.meta_value LIKE '%\"subscriber\"%'" );
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kounselia Admin — Broadcasts</title>
<meta name="robots" content="noindex, nofollow">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<?php require __DIR__ . '/../inc/admin-styles.php'; ?>
<style>
.composer-layout {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 32px;
  align-items: start;
}
@media (max-width: 900px) {
  .composer-layout { grid-template-columns: 1fr; }
}

.composer-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  padding: 28px;
  box-shadow: var(--sh-sm);
}

.form-group { margin-bottom: 20px; }
.form-group label {
  display: block;
  font-size: 13px;
  font-weight: 500;
  color: var(--text2);
  margin-bottom: 6px;
}
.form-group input, .form-group textarea {
  width: 100%;
  padding: 12px 14px;
  border: 1px solid var(--border);
  border-radius: var(--r-sm);
  font-family: inherit;
  font-size: 14.5px;
  color: var(--text);
  background: var(--bg);
  transition: all 0.2s ease;
}
.form-group input:focus, .form-group textarea:focus {
  outline: none;
  border-color: var(--accent);
  background: var(--surface);
  box-shadow: 0 0 0 3px var(--accent-light);
}
.form-group textarea {
  resize: vertical;
  min-height: 160px;
  line-height: 1.6;
}
.form-hint {
  font-size: 11.5px;
  color: var(--text3);
  margin-top: 6px;
}

.btn-send {
  width: 100%;
  padding: 14px;
  border: none;
  border-radius: var(--r-sm);
  background: var(--accent);
  color: #fff;
  font-family: inherit;
  font-size: 15px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s ease;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}
.btn-send:hover { background: var(--accent2); transform: translateY(-1px); }
.btn-send:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

/* Live Preview Device */
.preview-container {
  background: #E8EAEF;
  padding: 40px 20px;
  border-radius: 40px;
  box-shadow: inset 0 2px 10px rgba(0,0,0,0.05);
  display: flex;
  justify-content: center;
}
.preview-device {
  background: #F8F6F2;
  width: 100%;
  max-width: 380px;
  height: 600px;
  border-radius: 20px;
  box-shadow: 0 20px 40px rgba(0,0,0,0.1);
  overflow-y: auto;
  position: relative;
}
.preview-email {
  background: #ffffff;
  margin: 16px;
  border-radius: 16px;
  padding: 24px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.03);
}
.preview-logo {
  text-align: center;
  margin-bottom: 24px;
}
.preview-logo img {
  height: 24px;
  width: auto;
}
.preview-headline {
  color: #1E3A5F;
  font-family: 'Cormorant Garamond', Georgia, serif;
  font-size: 22px;
  font-weight: 500;
  text-align: center;
  margin-bottom: 16px;
  line-height: 1.2;
}
.preview-body {
  color: #5B574D;
  font-size: 14px;
  line-height: 1.6;
}
.preview-body p { margin-bottom: 14px; }
.preview-body p:last-child { margin-bottom: 0; }
.preview-btn-wrap {
  text-align: center;
  margin-top: 24px;
}
.preview-btn {
  display: inline-block;
  background-color: #1E3A5F;
  color: #ffffff;
  text-decoration: none;
  padding: 12px 24px;
  border-radius: 50px;
  font-weight: 500;
  font-size: 13px;
}

#toast-container {
  position: fixed;
  bottom: 24px;
  right: 24px;
  z-index: 100;
}
.toast {
  background: var(--text);
  color: #fff;
  padding: 14px 20px;
  border-radius: var(--r-sm);
  font-size: 14px;
  box-shadow: var(--sh-lg);
  margin-top: 10px;
  animation: slideUp 0.3s ease;
}
.toast.error { background: var(--rose); }
@keyframes slideUp {
  from { opacity: 0; transform: translateY(20px); }
  to { opacity: 1; transform: translateY(0); }
}
</style>
</head>
<body>

<?php require __DIR__ . '/../inc/admin-nav.php'; ?>

<div class="admin-body">
  <h1 class="admin-title">Broadcasts</h1>
  <div class="admin-subtitle">Send a branded message to all <?php echo number_format_i18n($member_count); ?> active members.</div>

  <div class="composer-layout">
    <!-- Editor -->
    <div class="composer-card">
      <div class="form-group">
        <label>Subject Line</label>
        <input type="text" id="email-subject" placeholder="New counselor available..." value="An update from Kounselia">
      </div>
      
      <div class="form-group">
        <label>Headline (Inside Email)</label>
        <input type="text" id="email-headline" placeholder="Welcome {name}..." value="Hi {name},">
        <div class="form-hint">Use <b>{name}</b> to insert the user's first name.</div>
      </div>

      <div class="form-group">
        <label>Body Message</label>
        <textarea id="email-body" placeholder="Write your message here...">We wanted to reach out and let you know that...</textarea>
        <div class="form-hint">Line breaks will automatically be converted to paragraphs.</div>
      </div>

      <div style="display:flex; gap:16px;">
        <div class="form-group" style="flex:1;">
          <label>Button Text (Optional)</label>
          <input type="text" id="email-btn-text" placeholder="Go to Dashboard">
        </div>
        <div class="form-group" style="flex:1;">
          <label>Button URL (Optional)</label>
          <input type="url" id="email-btn-url" placeholder="https://...">
        </div>
      </div>

      <button class="btn-send" id="btn-send" onclick="sendBroadcast()">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
        Send to <?php echo number_format_i18n($member_count); ?> members
      </button>
    </div>

    <!-- Live Preview -->
    <div class="preview-container">
      <div class="preview-device">
        <div class="preview-email">
          <div class="preview-logo">
            <img src="https://kounselia.com/img/Kounselia_Logo_IconMark_MidnightNavy.png" alt="Kounselia">
          </div>
          <div class="preview-headline" id="preview-headline">Hi there,</div>
          <div class="preview-body" id="preview-body">
            <p>We wanted to reach out and let you know that...</p>
          </div>
          <div class="preview-btn-wrap" id="preview-btn-wrap" style="display:none;">
            <a href="#" class="preview-btn" id="preview-btn">Button</a>
          </div>
        </div>
        
        <!-- Simulate email footer -->
        <div style="text-align:center; padding: 20px; font-size:11px; color:#A8A49A;">
          &copy; <?php echo date('Y'); ?> Kounselia.<br>A global mental wellness initiative.
        </div>
      </div>
    </div>
  </div>

</div>

<div id="toast-container"></div>

<script>
const ADMIN_AJAX_URL = "<?php echo esc_js( $ajax_url ); ?>";
const ADMIN_NONCE    = "<?php echo esc_js( $nonce ); ?>";

function showToast(msg, isError = false) {
  const container = document.getElementById('toast-container');
  const toast = document.createElement('div');
  toast.className = 'toast' + (isError ? ' error' : '');
  toast.textContent = msg;
  container.appendChild(toast);
  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transition = 'opacity 0.3s ease';
    setTimeout(() => toast.remove(), 300);
  }, 4000);
}

// Live Preview Binder
const els = {
  hInput: document.getElementById('email-headline'),
  bInput: document.getElementById('email-body'),
  btnTextInput: document.getElementById('email-btn-text'),
  btnUrlInput: document.getElementById('email-btn-url'),
  
  hPrev: document.getElementById('preview-headline'),
  bPrev: document.getElementById('preview-body'),
  btnWrapPrev: document.getElementById('preview-btn-wrap'),
  btnPrev: document.getElementById('preview-btn'),
};

function updatePreview() {
  // Replace {name} with a dummy name for preview purposes
  els.hPrev.textContent = els.hInput.value.replace('{name}', 'Sarah');
  
  // Convert line breaks to paragraphs
  const paragraphs = els.bInput.value.split('\n').filter(p => p.trim() !== '');
  els.bPrev.innerHTML = paragraphs.map(p => `<p>${p.replace('{name}', 'Sarah')}</p>`).join('');

  if (els.btnTextInput.value.trim() !== '') {
    els.btnPrev.textContent = els.btnTextInput.value;
    els.btnWrapPrev.style.display = 'block';
  } else {
    els.btnWrapPrev.style.display = 'none';
  }
}

// Attach listeners
els.hInput.addEventListener('input', updatePreview);
els.bInput.addEventListener('input', updatePreview);
els.btnTextInput.addEventListener('input', updatePreview);

// Initial render
updatePreview();

function sendBroadcast() {
  const subject  = document.getElementById('email-subject').value.trim();
  const headline = els.hInput.value.trim();
  const body     = els.bInput.value.trim();
  const btnText  = els.btnTextInput.value.trim();
  const btnUrl   = els.btnUrlInput.value.trim();

  if (!subject || !headline || !body) {
    showToast('Please fill out the subject, headline, and body.', true);
    return;
  }

  if (!confirm('Are you sure you want to send this email to all active members? This cannot be undone.')) {
    return;
  }

  const btn = document.getElementById('btn-send');
  const originalText = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = 'Sending... Please do not close page.';

  const params = new URLSearchParams({
    action: 'kounselia_admin_send_broadcast',
    nonce: ADMIN_NONCE,
    subject: subject,
    headline: headline,
    body: body,
    btn_text: btnText,
    btn_url: btnUrl
  });

  fetch(ADMIN_AJAX_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: params
  })
  .then(res => res.json())
  .then(data => {
    btn.disabled = false;
    btn.innerHTML = originalText;
    if (data.success) {
      showToast(data.data.message);
      // Clear form
      document.getElementById('email-subject').value = '';
      els.hInput.value = '';
      els.bInput.value = '';
      els.btnTextInput.value = '';
      els.btnUrlInput.value = '';
      updatePreview();
    } else {
      showToast(data.data.message || 'Error sending broadcast.', true);
    }
  })
  .catch(err => {
    btn.disabled = false;
    btn.innerHTML = originalText;
    showToast('Network error occurred.', true);
  });
}
</script>
</body>
</html>
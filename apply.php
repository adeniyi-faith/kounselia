<?php
/**
 * Kounselia — apply to join as a professional.
 *
 * Same boot pattern as talk.php/dashboard.php: wp-load.php only, no
 * theme. Works for both a brand-new visitor (the form includes account
 * fields) and an already signed-in member who wants to also become a
 * professional (account fields are skipped, they just fill in the
 * professional details and upload documents).
 *
 * Once someone has an application on file — pending, verified, or
 * rejected — this page shows its status instead of the form. See
 * portal/wp-content/mu-plugins/kounselia/includes/professionals.php for
 * all the actual logic (kounselia_ajax_apply_professional and friends).
 */
define( 'WP_USE_THEMES', false );
define( 'COOKIEPATH', '/' );
define( 'SITECOOKIEPATH', '/' );
require_once __DIR__ . '/portal/wp-load.php';

$is_logged_in = is_user_logged_in();
$application  = $is_logged_in ? kounselia_get_professional_application( get_current_user_id() ) : null;

$ajax_url = set_url_scheme( admin_url( 'admin-ajax.php' ), is_ssl() ? 'https' : 'http' );
$nonce    = wp_create_nonce( 'kounselia_auth' );
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Join as a Professional — Kounselia</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/png" href="https://kounselia.com/img/fv.png">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
<?php require __DIR__ . '/inc/kounselia-styles.php'; ?>
<style>
.apply-wrap{max-width:640px;margin:0 auto;padding:60px 20px 80px}
.apply-eyebrow{font-size:13px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--gold)}
.apply-wrap h1{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:38px;color:var(--accent);margin:10px 0 12px}
.apply-wrap>p.lead{color:var(--text2);font-size:15.5px;line-height:1.6;margin-bottom:32px}
.apply-card{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:32px}
.apply-card h3{font-size:15px;font-weight:600;color:var(--text);margin:24px 0 4px}
.apply-card h3:first-child{margin-top:0}
.apply-card .section-note{font-size:13px;color:var(--text3);margin-bottom:14px}
.form-field textarea{width:100%;padding:12px 14px;border:1.5px solid var(--border);border-radius:12px;font-family:inherit;font-size:14.5px;color:var(--text);background:var(--bg);outline:none;resize:vertical;min-height:90px}
.form-field textarea:focus{border-color:var(--accent);background:var(--surface);box-shadow:0 0 0 4px var(--accent-light)}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media (max-width:520px){.form-row{grid-template-columns:1fr}}
.file-field{border:1.5px dashed var(--border);border-radius:12px;padding:14px;font-size:13.5px;color:var(--text2)}
.file-field input{display:block;margin-top:8px;font-size:13px}
.rate-prefix{display:flex;align-items:center;gap:8px}
.rate-prefix span{font-weight:600;color:var(--text2)}
.apply-submit{width:100%;margin-top:24px;padding:14px;border:none;border-radius:14px;background:var(--accent);color:#fff;font-size:15px;font-weight:600;cursor:pointer;font-family:inherit}
.apply-submit:hover{background:var(--accent2)}
.apply-submit:disabled{opacity:.6;cursor:not-allowed}
.apply-msg{margin-top:14px;font-size:13.5px;padding:12px 14px;border-radius:10px;display:none}
.apply-msg.error{display:block;background:var(--rose-light);color:var(--rose)}
.apply-msg.notice{display:block;background:var(--sage-light);color:var(--sage)}
.status-badge{display:inline-flex;align-items:center;gap:8px;padding:6px 14px;border-radius:var(--r-full,999px);font-size:13px;font-weight:600}
.status-pending{background:var(--gold-light);color:#8a5a12}
.status-verified{background:var(--sage-light);color:var(--sage)}
.status-rejected{background:var(--rose-light);color:var(--rose)}
</style>
</head>
<body style="background:var(--bg)">

<div class="apply-wrap">
  <div class="apply-eyebrow">For licensed professionals</div>
  <h1>Bring your practice to Kounselia</h1>

  <?php if ( $application ) : ?>
    <p class="lead">Here's where your application stands.</p>
    <div class="apply-card">
      <?php if ( 'pending' === $application->status ) : ?>
        <span class="status-badge status-pending"><i class="ti ti-clock"></i> Under review</span>
        <p style="margin-top:16px;color:var(--text2);font-size:14.5px;line-height:1.6;">Thanks for applying. Our team is reviewing your documents — we'll email you at your account address once there's a decision, usually within a few business days.</p>
      <?php elseif ( 'verified' === $application->status ) : ?>
        <span class="status-badge status-verified"><i class="ti ti-check"></i> Verified</span>
        <p style="margin-top:16px;color:var(--text2);font-size:14.5px;line-height:1.6;">You're approved as a professional on Kounselia.</p>
        <a class="apply-submit" style="display:block;text-align:center;text-decoration:none;margin-top:16px" href="/pro-dashboard.php">Manage your profile &amp; rate</a>
      <?php else : ?>
        <span class="status-badge status-rejected"><i class="ti ti-x"></i> Not approved</span>
        <?php if ( $application->rejection_reason ) : ?>
          <p style="margin-top:16px;color:var(--text2);font-size:14.5px;line-height:1.6;"><strong>Reason:</strong> <?php echo esc_html( $application->rejection_reason ); ?></p>
        <?php endif; ?>
        <p style="margin-top:10px;color:var(--text2);font-size:14.5px;line-height:1.6;">You're welcome to update your documents and reapply below.</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ( ! $application || 'rejected' === $application->status ) : ?>
    <p class="lead">Tell us about your practice and upload your credentials. We review every application by hand before you can see clients here.</p>

    <div class="apply-card">
      <form id="apply-form">
        <?php if ( ! $is_logged_in ) : ?>
          <h3>Your account</h3>
          <div class="section-note">You'll use this to sign in and manage your profile.</div>
          <div class="form-field"><label>Full name</label><input type="text" name="name" id="ap-name" placeholder="Dr. Jane Okafor" autocomplete="name"></div>
          <div class="form-field"><label>Email address</label><input type="email" name="email" id="ap-email" placeholder="you@example.com" autocomplete="email"></div>
          <div class="form-field"><label>Password</label><input type="password" name="password" id="ap-password" placeholder="Create a password" autocomplete="new-password"></div>
        <?php endif; ?>

        <h3>Professional details</h3>
        <div class="form-field"><label>Professional title</label><input type="text" name="title" id="ap-title" placeholder="e.g. Licensed Clinical Psychologist"></div>
        <div class="form-row">
          <div class="form-field"><label>License / registration number</label><input type="text" name="license_number" id="ap-license" placeholder="Optional, if applicable"></div>
          <div class="form-field"><label>Years of experience</label><input type="number" name="years_experience" id="ap-years" min="0" max="60" placeholder="e.g. 8"></div>
        </div>
        <div class="form-field"><label>Specialty</label><input type="text" name="specialty" id="ap-specialty" placeholder="e.g. Anxiety, trauma, relationships"></div>
        <div class="form-field"><label>Short bio</label><textarea name="bio" id="ap-bio" placeholder="A couple of sentences clients will see on your profile."></textarea></div>

        <h3>Your rate</h3>
        <div class="section-note">You set this — Kounselia doesn't set it for you. You can change it later from your profile.</div>
        <div class="form-field rate-prefix"><span>₦</span><input type="number" name="rate_amount" id="ap-rate" min="0" step="0.01" placeholder="Per session"></div>

        <h3>Verification documents</h3>
        <div class="section-note">PDF, JPG, or PNG, up to 8MB each.</div>
        <div class="file-field">
          <label>License or credential document (required)</label>
          <input type="file" name="license_doc" id="ap-license-doc" accept=".pdf,.jpg,.jpeg,.png">
        </div>
        <div class="file-field" style="margin-top:10px">
          <label>Government-issued ID (optional, speeds up review)</label>
          <input type="file" name="id_doc" id="ap-id-doc" accept=".pdf,.jpg,.jpeg,.png">
        </div>

        <input type="text" name="website" id="ap-hp" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0">

        <button type="submit" class="apply-submit" id="apply-btn">Submit application</button>
        <div class="apply-msg" id="apply-msg"></div>
      </form>
    </div>
  <?php endif; ?>
</div>

<script>
const form = document.getElementById('apply-form');
if (form) {
  form.addEventListener('submit', function(e){
    e.preventDefault();
    const btn = document.getElementById('apply-btn');
    const msg = document.getElementById('apply-msg');
    msg.className = 'apply-msg';
    msg.textContent = '';

    const fd = new FormData(form);
    fd.append('action', 'kounselia_apply_professional');
    fd.append('nonce', <?php echo wp_json_encode( $nonce ); ?>);

    btn.disabled = true;
    btn.textContent = 'Submitting...';

    fetch(<?php echo wp_json_encode( $ajax_url ); ?>, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(res => {
        if (res.success) {
          msg.classList.add('notice');
          msg.textContent = res.data.message || 'Application submitted.';
          setTimeout(() => { window.location.href = (res.data && res.data.redirect) || '/dashboard.php'; }, 1200);
        } else {
          btn.disabled = false;
          btn.textContent = 'Submit application';
          msg.classList.add('error');
          msg.textContent = (res.data && res.data.message) ? res.data.message : 'Something went wrong, please try again.';
        }
      })
      .catch(() => {
        btn.disabled = false;
        btn.textContent = 'Submit application';
        msg.classList.add('error');
        msg.textContent = 'Something went wrong, please check your connection and try again.';
      });
  });
}
</script>

</body>
</html>

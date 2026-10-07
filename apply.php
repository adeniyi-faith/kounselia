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
$al       = kounselia_current_language();
$at       = function ( $key, $vars = array() ) use ( $al ) {
    return esc_html( kounselia_t( $key, $vars, $al ) );
};
$ata      = function ( $key ) use ( $al ) {
    return esc_attr( kounselia_t( $key, array(), $al ) );
};
?>
<!DOCTYPE html>
<html <?php echo function_exists( 'kounselia_html_attrs' ) ? kounselia_html_attrs() : 'lang="en"'; ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title><?php echo $at( 's.apply.title' ); ?></title>
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
  <div class="apply-eyebrow"><?php echo $at( 's.apply.eyebrow' ); ?></div>
  <h1><?php echo $at( 's.apply.h1' ); ?></h1>

  <?php if ( $application ) : ?>
    <p class="lead"><?php echo $at( 's.apply.status_lead' ); ?></p>
    <div class="apply-card">
      <?php if ( 'pending' === $application->status ) : ?>
        <span class="status-badge status-pending"><i class="ti ti-clock"></i> <?php echo $at( 's.apply.under_review' ); ?></span>
        <p style="margin-top:16px;color:var(--text2);font-size:14.5px;line-height:1.6;"><?php echo $at( 's.apply.pending_p' ); ?></p>
        <a class="apply-submit" style="display:block;text-align:center;text-decoration:none;margin-top:16px" href="/pro-dashboard.php"><?php echo $at( 's.apply.go_home' ); ?></a>
      <?php elseif ( 'verified' === $application->status ) : ?>
        <span class="status-badge status-verified"><i class="ti ti-check"></i> <?php echo $at( 's.apply.verified' ); ?></span>
        <p style="margin-top:16px;color:var(--text2);font-size:14.5px;line-height:1.6;"><?php echo $at( 's.apply.verified_p' ); ?></p>
        <a class="apply-submit" style="display:block;text-align:center;text-decoration:none;margin-top:16px" href="/pro-dashboard.php"><?php echo $at( 's.apply.manage' ); ?></a>
      <?php else : ?>
        <span class="status-badge status-rejected"><i class="ti ti-x"></i> <?php echo $at( 's.apply.not_approved' ); ?></span>
        <?php if ( $application->rejection_reason ) : ?>
          <p style="margin-top:16px;color:var(--text2);font-size:14.5px;line-height:1.6;"><strong><?php echo $at( 's.apply.reason' ); ?></strong> <?php echo esc_html( $application->rejection_reason ); ?></p>
        <?php endif; ?>
        <p style="margin-top:10px;color:var(--text2);font-size:14.5px;line-height:1.6;"><?php echo $at( 's.apply.reapply' ); ?></p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ( ! $application || 'rejected' === $application->status ) : ?>
    <p class="lead"><?php echo $at( 's.apply.lead' ); ?></p>

    <div class="apply-card">
      <form id="apply-form">
        <?php if ( ! $is_logged_in ) : ?>
          <h3><?php echo $at( 's.apply.account' ); ?></h3>
          <div class="section-note"><?php echo $at( 's.apply.account_note' ); ?></div>
          <div class="form-field"><label><?php echo $at( 's.apply.full_name' ); ?></label><input type="text" name="name" id="ap-name" placeholder="Jane Okafor" autocomplete="name"></div>
          <div class="form-field"><label><?php echo $at( 's.apply.email' ); ?></label><input type="email" name="email" id="ap-email" placeholder="you@example.com" autocomplete="email"></div>
          <div class="form-field"><label><?php echo $at( 's.apply.password' ); ?></label><input type="password" name="password" id="ap-password" placeholder="<?php echo $ata( 's.apply.password_ph' ); ?>" autocomplete="new-password"></div>
        <?php endif; ?>

        <h3><?php echo $at( 's.apply.details' ); ?></h3>
        <div class="form-field"><label><?php echo $at( 's.apply.prof_title' ); ?></label><input type="text" name="title" id="ap-title" placeholder="<?php echo $ata( 's.apply.prof_title_ph' ); ?>"></div>
        <div class="form-row">
          <div class="form-field"><label><?php echo $at( 's.apply.license' ); ?></label><input type="text" name="license_number" id="ap-license" placeholder="<?php echo $ata( 's.apply.license_ph' ); ?>"></div>
          <div class="form-field"><label><?php echo $at( 's.apply.years' ); ?></label><input type="number" name="years_experience" id="ap-years" min="0" max="60" placeholder="<?php echo $ata( 's.apply.years_ph' ); ?>"></div>
        </div>
        <div class="form-field"><label><?php echo $at( 's.apply.specialty' ); ?></label><input type="text" name="specialty" id="ap-specialty" placeholder="<?php echo $ata( 's.apply.specialty_ph' ); ?>"></div>
        <div class="form-field"><label><?php echo $at( 's.apply.bio' ); ?></label><textarea name="bio" id="ap-bio" placeholder="<?php echo $ata( 's.apply.bio_ph' ); ?>"></textarea></div>

        <h3><?php echo $at( 's.apply.rate' ); ?></h3>
        <div class="section-note"><?php echo $at( 's.apply.rate_note' ); ?></div>
        <div class="form-field rate-prefix"><span>₦</span><input type="number" name="rate_amount" id="ap-rate" min="0" step="0.01" placeholder="<?php echo $ata( 's.apply.rate_ph' ); ?>"></div>

        <h3><?php echo $at( 's.apply.docs' ); ?></h3>
        <div class="section-note"><?php echo $at( 's.apply.docs_note' ); ?></div>
        <div class="file-field">
          <label><?php echo $at( 's.apply.doc_license' ); ?></label>
          <input type="file" name="license_doc" id="ap-license-doc" accept=".pdf,.jpg,.jpeg,.png">
        </div>
        <div class="file-field" style="margin-top:10px">
          <label><?php echo $at( 's.apply.doc_id' ); ?></label>
          <input type="file" name="id_doc" id="ap-id-doc" accept=".pdf,.jpg,.jpeg,.png">
        </div>

        <input type="text" name="website" id="ap-hp" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0">

        <button type="submit" class="apply-submit" id="apply-btn"><?php echo $at( 's.apply.submit' ); ?></button>
        <div class="apply-msg" id="apply-msg"></div>
      </form>
    </div>
  <?php endif; ?>
</div>

<script>
const AT = <?php echo wp_json_encode( kounselia_i18n_subset( $al, 's.apply.js_' ) ); ?>;
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
    btn.textContent = AT['s.apply.js_submitting'];

    fetch(<?php echo wp_json_encode( $ajax_url ); ?>, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(res => {
        if (res.success) {
          msg.classList.add('notice');
          msg.textContent = res.data.message || AT['s.apply.js_done'];
          setTimeout(() => { window.location.href = (res.data && res.data.redirect) || '/pro-dashboard.php'; }, 1200);
        } else {
          btn.disabled = false;
          btn.textContent = AT['s.apply.js_submit'];
          msg.classList.add('error');
          msg.textContent = (res.data && res.data.message) ? res.data.message : AT['s.apply.js_error'];
        }
      })
      .catch(() => {
        btn.disabled = false;
        btn.textContent = AT['s.apply.js_submit'];
        msg.classList.add('error');
        msg.textContent = AT['s.apply.js_network'];
      });
  });
}
</script>

</body>
</html>

<?php
/**
 * Kounselia — a professional's own home, from the moment they apply.
 *
 * Same boot pattern as dashboard.php/talk.php: wp-load.php only, no
 * theme. Gated to a signed-in user who has an application on file at
 * all — anyone with none gets sent to /apply.php. Someone who just
 * registered as a professional lands here, not on the client-facing
 * /dashboard.php (mood check-ins, journal, "talk to someone now") —
 * that page is built for a person seeking support, and showing it to
 * someone who just said "I'm a therapist" is confusing, not welcoming.
 *
 * What changes by status is what's UNLOCKED on this same page, not
 * which page they see:
 *   - pending/rejected: can edit their draft profile & rate (so it's
 *     ready to go the moment they're approved), can't do anything that
 *     requires verification yet (nothing does today; the booking
 *     directory and earnings ledger land in later slices and will gate
 *     here the same way).
 *   - verified: same editor, plus whatever those later slices unlock.
 *
 * All the actual read/write logic lives in
 * portal/wp-content/mu-plugins/kounselia/includes/professionals.php
 * (kounselia_ajax_update_professional_profile). This page is just the form.
 */
define( 'WP_USE_THEMES', false );
define( 'COOKIEPATH', '/' );
define( 'SITECOOKIEPATH', '/' );
require_once __DIR__ . '/portal/wp-load.php';

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( '/index.php' );
    exit;
}

$user        = wp_get_current_user();
$application = kounselia_get_professional_application( $user->ID );

if ( ! $application ) {
    wp_safe_redirect( '/apply.php' );
    exit;
}

$is_verified = ( 'verified' === $application->status );

$ajax_url = set_url_scheme( admin_url( 'admin-ajax.php' ), is_ssl() ? 'https' : 'http' );
$nonce    = wp_create_nonce( 'kounselia_auth' );
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Your Professional Profile — Kounselia</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/png" href="https://kounselia.com/img/fv.png">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<?php require __DIR__ . '/inc/kounselia-styles.php'; ?>
<style>
.pro-wrap{max-width:640px;margin:0 auto;padding:60px 20px 80px}
.pro-eyebrow{font-size:13px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--gold)}
.pro-wrap h1{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:34px;color:var(--accent);margin:10px 0 12px}
.pro-wrap>p.lead{color:var(--text2);font-size:15px;line-height:1.6;margin-bottom:28px}
.pro-card{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:32px}
.form-field textarea{width:100%;padding:12px 14px;border:1.5px solid var(--border);border-radius:12px;font-family:inherit;font-size:14.5px;color:var(--text);background:var(--bg);outline:none;resize:vertical;min-height:90px}
.form-field textarea:focus{border-color:var(--accent);background:var(--surface);box-shadow:0 0 0 4px var(--accent-light)}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media (max-width:520px){.form-row{grid-template-columns:1fr}}
.rate-prefix{display:flex;align-items:center;gap:8px}
.rate-prefix span{font-weight:600;color:var(--text2)}
.readonly-note{font-size:12.5px;color:var(--text3);background:var(--bg2,#F8FAFC);border-radius:10px;padding:10px 12px;margin-bottom:20px}
.pro-submit{width:100%;margin-top:20px;padding:14px;border:none;border-radius:14px;background:var(--accent);color:#fff;font-size:15px;font-weight:600;cursor:pointer;font-family:inherit}
.pro-submit:hover{background:var(--accent2)}
.pro-submit:disabled{opacity:.6;cursor:not-allowed}
.pro-msg{margin-top:14px;font-size:13.5px;padding:12px 14px;border-radius:10px;display:none}
.pro-msg.error{display:block;background:var(--rose-light);color:var(--rose)}
.pro-msg.notice{display:block;background:var(--sage-light);color:var(--sage)}
.status-banner{border-radius:16px;padding:16px 18px;margin-bottom:24px;font-size:14px;line-height:1.55}
.status-banner.pending{background:var(--gold-light);color:#6B4A1F}
.status-banner.verified{background:var(--sage-light);color:var(--sage)}
.status-banner.rejected{background:var(--rose-light);color:var(--rose)}
.status-banner strong{display:block;font-size:13px;text-transform:uppercase;letter-spacing:.03em;margin-bottom:4px}
.capability-list{display:grid;gap:8px;margin-bottom:28px}
.capability-row{display:flex;align-items:center;gap:10px;font-size:13.5px;padding:10px 14px;border-radius:12px;background:var(--surface);border:1px solid var(--border)}
.capability-row.locked{color:var(--text3)}
.capability-row.unlocked{color:var(--text)}
.capability-row i{font-size:16px;flex-shrink:0}
.capability-row.unlocked i{color:var(--sage)}
.capability-row.locked i{color:var(--text3)}
.capability-row .tag{margin-left:auto;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.03em}
.capability-row.locked .tag{color:var(--text3)}
.capability-row.unlocked .tag{color:var(--sage)}
</style>
</head>
<body style="background:var(--bg)">

<div class="pro-wrap">
  <div class="pro-eyebrow"><?php echo $is_verified ? 'Verified professional' : 'Professional application'; ?></div>
  <h1><?php echo $is_verified ? 'Your profile' : 'Your professional home'; ?></h1>
  <p class="lead"><?php echo $is_verified
    ? 'This is what clients will see, and your rate is entirely yours to set — Kounselia never changes it for you.'
    : 'Get your profile ready while your application is reviewed. It stays private until you\'re verified.'; ?></p>

  <?php if ( 'pending' === $application->status ) : ?>
    <div class="status-banner pending"><strong>Under review</strong>We're checking your documents — we'll email you once there's a decision, usually within a few business days.</div>
  <?php elseif ( 'rejected' === $application->status ) : ?>
    <div class="status-banner rejected"><strong>Not approved yet</strong><?php echo $application->rejection_reason ? esc_html( $application->rejection_reason ) : 'Update your documents and reapply when you\'re ready.'; ?> — <a href="/apply.php" style="color:inherit;text-decoration:underline">reapply with new documents</a>.</div>
  <?php else : ?>
    <div class="status-banner verified"><strong>Verified</strong>Your profile is live for clients.</div>
  <?php endif; ?>

  <div class="capability-list">
    <div class="capability-row unlocked"><i class="ti ti-user-edit"></i> Edit your profile &amp; rate<span class="tag">Available</span></div>
    <div class="capability-row unlocked"><i class="ti ti-heart-handshake"></i> Use Kounselia as a client too — <a href="/dashboard.php" style="color:inherit">your dashboard</a><span class="tag">Available</span></div>
    <div class="capability-row <?php echo $is_verified ? 'unlocked' : 'locked'; ?>"><i class="ti ti-calendar-event"></i> Receive client bookings<span class="tag"><?php echo $is_verified ? 'Coming soon' : 'Locked until verified'; ?></span></div>
    <div class="capability-row <?php echo $is_verified ? 'unlocked' : 'locked'; ?>"><i class="ti ti-cash"></i> Earnings &amp; payouts<span class="tag"><?php echo $is_verified ? 'Coming soon' : 'Locked until verified'; ?></span></div>
  </div>

  <div class="pro-card">
    <?php if ( $application->license_number ) : ?>
      <div class="readonly-note">Verified against license/registration <strong><?php echo esc_html( $application->license_number ); ?></strong>. To change your credentials, contact support — that requires re-verification.</div>
    <?php endif; ?>

    <form id="pro-form">
      <div class="form-field"><label>Professional title</label><input type="text" name="title" id="pro-title" value="<?php echo esc_attr( $application->title ); ?>"></div>
      <div class="form-row">
        <div class="form-field"><label>Specialty</label><input type="text" name="specialty" id="pro-specialty" value="<?php echo esc_attr( $application->specialty ); ?>"></div>
        <div class="form-field"><label>Years of experience</label><input type="number" name="years_experience" id="pro-years" min="0" max="60" value="<?php echo esc_attr( $application->years_experience ); ?>"></div>
      </div>
      <div class="form-field"><label>Bio</label><textarea name="bio" id="pro-bio"><?php echo esc_textarea( $application->bio ); ?></textarea></div>
      <div class="form-field">
        <label>Your rate per session</label>
        <div class="rate-prefix"><span>₦</span><input type="number" name="rate_amount" id="pro-rate" min="0" step="0.01" value="<?php echo esc_attr( $application->rate_amount ); ?>"></div>
      </div>

      <button type="submit" class="pro-submit" id="pro-save-btn">Save changes</button>
      <div class="pro-msg" id="pro-msg"></div>
    </form>
  </div>
</div>

<script>
document.getElementById('pro-form').addEventListener('submit', function(e){
  e.preventDefault();
  const btn = document.getElementById('pro-save-btn');
  const msg = document.getElementById('pro-msg');
  msg.className = 'pro-msg';
  msg.textContent = '';
  btn.disabled = true;
  btn.textContent = 'Saving...';

  fetch(<?php echo wp_json_encode( $ajax_url ); ?>, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      action: 'kounselia_update_professional_profile',
      nonce: <?php echo wp_json_encode( $nonce ); ?>,
      title: document.getElementById('pro-title').value,
      specialty: document.getElementById('pro-specialty').value,
      years_experience: document.getElementById('pro-years').value,
      bio: document.getElementById('pro-bio').value,
      rate_amount: document.getElementById('pro-rate').value
    })
  })
  .then(r => r.json())
  .then(res => {
    btn.disabled = false;
    btn.textContent = 'Save changes';
    if (res.success) {
      msg.classList.add('notice');
      msg.textContent = res.data.message || 'Saved.';
    } else {
      msg.classList.add('error');
      msg.textContent = (res.data && res.data.message) ? res.data.message : 'Something went wrong, please try again.';
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.textContent = 'Save changes';
    msg.classList.add('error');
    msg.textContent = 'Something went wrong, please check your connection and try again.';
  });
});
</script>

</body>
</html>

<?php
/**
 * Kounselia Admin — access gatekeeper.
 *
 * Every protected page under /portal/admin/pages/ must require this file
 * FIRST, before any HTML or data is touched.
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'WP_USE_THEMES', false );
    define( 'COOKIEPATH', '/' );
    define( 'SITECOOKIEPATH', '/' );

    // This file lives at /portal/admin/inc/admin-auth.php, so wp-load.php
    // is two levels up, inside /portal itself.
    $kounselia_admin_wp_load = __DIR__ . '/../../wp-load.php';
    if ( ! file_exists( $kounselia_admin_wp_load ) ) {
        http_response_code( 500 );
        die( 'Configuration error: cannot locate the WordPress core engine.' );
    }
    require_once $kounselia_admin_wp_load;
}

// Never let search engines index anything under /admin/
header( 'X-Robots-Tag: noindex, nofollow', true );

if ( ! defined( 'KOUNSELIA_ADMIN_IDLE_LIMIT' ) ) {
    define( 'KOUNSELIA_ADMIN_IDLE_LIMIT', 20 * MINUTE_IN_SECONDS ); // 20 minutes
}

/**
 * Bulletproof wrapper to prevent "Cannot redeclare" Fatal Errors
 * if this file is accidentally required twice.
 */
if ( ! function_exists( 'kounselia_admin_bounce_to_login' ) ) {
    function kounselia_admin_bounce_to_login( $reason = '' ) {
        $login_url = '/portal/admin/index.php';
        if ( $reason ) {
            $login_url = add_query_arg( 'reason', rawurlencode( $reason ), $login_url );
        }
        wp_safe_redirect( $login_url );
        exit;
    }
}

if ( ! is_user_logged_in() ) {
    kounselia_admin_bounce_to_login( 'signed_out' );
}

if ( ! kounselia_user_is_admin() ) {
    kounselia_admin_bounce_to_login( 'signed_out' );
}

/* -------------------------------------------------------------------------
 * Inactivity timeout.
 * ---------------------------------------------------------------------- */
$kounselia_admin_id   = get_current_user_id();
$kounselia_last_seen  = (int) get_user_meta( $kounselia_admin_id, 'kounselia_admin_last_seen', true );
$kounselia_now        = time();

if ( $kounselia_last_seen && ( $kounselia_now - $kounselia_last_seen ) > KOUNSELIA_ADMIN_IDLE_LIMIT ) {
    wp_logout();
    kounselia_admin_bounce_to_login( 'timed_out' );
}

update_user_meta( $kounselia_admin_id, 'kounselia_admin_last_seen', $kounselia_now );

/* -------------------------------------------------------------------------
 * Security Interceptor: Force Password Reset for New Staff
 * ---------------------------------------------------------------------- */
$kounselia_admin_user = wp_get_current_user();

if ( get_user_meta( $kounselia_admin_user->ID, 'kounselia_force_password_change', true ) ) {
    $ajax_url = set_url_scheme( admin_url( 'admin-ajax.php' ), is_ssl() ? 'https' : 'http' );
    $nonce    = wp_create_nonce( 'kounselia_admin_nonce' );
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Secure Your Account</title>
      <meta name="robots" content="noindex, nofollow">
      <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
      <?php require __DIR__ . '/admin-styles.php'; ?>
    </head>
    <body>
      <div class="login-wrap">
        <div class="login-box">
          <div class="login-logo">
            <img src="https://kounselia.com/img/Kounselia_Logo_IconMark_MidnightNavy.png" alt="Kounselia" class="login-site-logo">
          </div>
          <div class="login-sub">Welcome, <?php echo esc_html( $kounselia_admin_user->first_name ); ?></div>
          
          <div class="login-msg notice">For your security, please set your permanent password before accessing the dashboard.</div>
          
          <div id="pw-error" class="login-msg error" style="display:none"></div>

          <div class="login-field">
            <label>New Password</label>
            <input type="password" id="new_pw" required autofocus>
          </div>

          <button id="save-pw" class="login-submit" onclick="savePassword()">Set Password & Continue</button>
        </div>
      </div>

      <script>
      function savePassword() {
          const btn = document.getElementById('save-pw');
          const err = document.getElementById('pw-error');
          const pw = document.getElementById('new_pw').value;

          if (pw.length < 8) {
              err.textContent = 'Password must be at least 8 characters long.';
              err.style.display = 'block';
              return;
          }

          err.style.display = 'none';
          btn.disabled = true;
          btn.textContent = 'Securing account...';

          fetch("<?php echo esc_js($ajax_url); ?>", {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: new URLSearchParams({
                  action: 'kounselia_admin_force_password',
                  nonce: "<?php echo esc_js($nonce); ?>",
                  new_password: pw
              })
          })
          .then(res => res.json())
          .then(data => {
              if (data.success) {
                  window.location.reload();
              } else {
                  err.textContent = data.data.message || 'An error occurred. Please try again.';
                  err.style.display = 'block';
                  btn.disabled = false;
                  btn.textContent = 'Set Password & Continue';
              }
          });
      }
      </script>
    </body>
    </html>
    <?php
    exit; // Halt execution so they absolutely cannot see the dashboard behind this
}
?>
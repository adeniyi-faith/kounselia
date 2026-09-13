<?php
/**
 * Kounselia Admin — login screen.
 *
 * This is the ONLY page under /portal/admin/ that a signed-out visitor
 * is allowed to reach. Every other page requires inc/admin-auth.php,
 * which bounces straight back here if the visitor isn't a confirmed
 * Kounselia admin.
 */
define( 'WP_USE_THEMES', false );
define( 'COOKIEPATH', '/' );
define( 'SITECOOKIEPATH', '/' );

$kounselia_admin_wp_load = __DIR__ . '/../wp-load.php';
if ( ! file_exists( $kounselia_admin_wp_load ) ) {
    http_response_code( 500 );
    die( 'Configuration error: cannot locate the WordPress core engine.' );
}
require_once $kounselia_admin_wp_load;

header( 'X-Robots-Tag: noindex, nofollow', true );

// Already signed in as a confirmed admin? Skip the form entirely.
if ( is_user_logged_in() && kounselia_user_is_admin() ) {
    wp_safe_redirect( '/portal/admin/pages/dashboard.php' );
    exit;
}

$kounselia_error = '';

if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {

    // Honeypot: a hidden field real humans never fill in.
    if ( ! empty( $_POST['website'] ) ) {
        $kounselia_error = 'Incorrect email or password.';
    } elseif ( kounselia_rate_limited( 'admin_login', 5, 600 ) ) {
        $kounselia_error = 'Too many attempts. Please wait a few minutes and try again.';
    } else {
        $kounselia_email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $kounselia_password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';

        $kounselia_signon = wp_signon( array(
            'user_login'    => $kounselia_email,
            'user_password' => $kounselia_password,
            'remember'      => false, // short admin sessions on purpose, see admin-auth.php
        ), is_ssl() );

        if ( is_wp_error( $kounselia_signon ) ) {
            $kounselia_error = 'Incorrect email or password.';
        } elseif ( ! kounselia_user_is_admin( $kounselia_signon->ID ) ) {
            wp_logout();
            $kounselia_error = 'Incorrect email or password.';
        } else {
            update_user_meta( $kounselia_signon->ID, 'kounselia_admin_last_seen', time() );
            wp_safe_redirect( '/portal/admin/pages/dashboard.php' );
            exit;
        }
    }
}

$kounselia_reason  = isset( $_GET['reason'] ) ? sanitize_key( $_GET['reason'] ) : '';
$kounselia_notices = array(
    'timed_out'  => 'You were signed out after a period of inactivity. Please sign in again.',
);
if ( empty( $kounselia_error ) && ! empty( $kounselia_notices[ $kounselia_reason ] ) ) {
    $kounselia_notice = $kounselia_notices[ $kounselia_reason ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Kounselia Admin — Sign In</title>
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#1E3A5F">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<?php require __DIR__ . '/inc/admin-styles.php'; ?>
</head>
<body>
<div class="login-wrap">
  <div class="login-box">
    
    <div class="login-logo">
      <img src="https://kounselia.com/img/Kounselia_Logo_IconMark_MidnightNavy.png" alt="Kounselia" class="login-site-logo" fetchpriority="high">
    </div>
    
    <div class="login-sub">Admin</div>

    <?php if ( $kounselia_error ) : ?>
      <div class="login-msg error"><?php echo esc_html( $kounselia_error ); ?></div>
    <?php elseif ( ! empty( $kounselia_notice ) ) : ?>
      <div class="login-msg notice"><?php echo esc_html( $kounselia_notice ); ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <div class="login-field hp-field">
        <label for="website">Website</label>
        <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
      </div>

      <div class="login-field">
        <label for="email">Email</label>
        <!-- Added a clear placeholder here -->
        <input type="email" id="email" name="email" placeholder="name@kounselia.com" inputmode="email" autocapitalize="off" autocorrect="off" required autofocus>
      </div>

      <div class="login-field">
        <label for="password">Password</label>
        <div class="pw-wrap">
          <!-- Added a clear placeholder here -->
          <input type="password" id="password" name="password" placeholder="••••••••" required>
          <button type="button" class="pw-toggle" id="pwToggle" aria-label="Show password" aria-pressed="false">
            <svg id="pwIconOpen" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg id="pwIconClosed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a20.6 20.6 0 0 1 5.06-6.06M9.9 4.24A10.4 10.4 0 0 1 12 4c7 0 11 8 11 8a20.7 20.7 0 0 1-3.22 4.44M14.12 14.12a3 3 0 1 1-4.24-4.24"/><path d="M1 1l22 22"/></svg>
          </button>
        </div>
      </div>

      <button type="submit" class="login-submit">Sign in</button>
    </form>
  </div>
</div>
<script>
(function(){
  var btn = document.getElementById('pwToggle');
  var input = document.getElementById('password');
  var open = document.getElementById('pwIconOpen');
  var closed = document.getElementById('pwIconClosed');
  if(!btn || !input) return;
  btn.addEventListener('click', function(){
    var showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    open.style.display = showing ? '' : 'none';
    closed.style.display = showing ? 'none' : '';
    btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
    btn.setAttribute('aria-pressed', showing ? 'false' : 'true');
  });
})();
</script>
</body>
</html>
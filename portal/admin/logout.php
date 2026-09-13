<?php
/**
 * Kounselia Admin — logout.
 *
 * Deliberately does NOT require admin-auth.php, a half-expired or
 * already-invalid session should still be able to reach this and get
 * cleanly logged out, rather than getting stuck bouncing between the
 * gatekeeper and the login screen.
 */
define( 'WP_USE_THEMES', false );
define( 'COOKIEPATH', '/' );
define( 'SITECOOKIEPATH', '/' );

require_once __DIR__ . '/../wp-load.php';

wp_logout();
wp_safe_redirect( '/portal/admin/index.php' );
exit;

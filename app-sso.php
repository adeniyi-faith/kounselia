<?php
/**
 * Kounselia — signs the in-app browser into the website.
 *
 * Same boot pattern as video-call.php/apply.php: wp-load.php only, no
 * theme. The mobile app is signed in with its own token (see
 * app-auth.php) that only it knows; the WebView it opens has never
 * signed in here and has no way to carry that token itself, so without
 * this it always lands on the website's own sign-in page even though
 * the member is already signed in on their phone.
 *
 * Right before opening one of our own pages in its in-app browser, the
 * app asks the server for a one-time code (kounselia_app_web_sso) and
 * sends the browser here with it instead of straight to the page. The
 * code is good for a few seconds and only once — just long enough for
 * this redirect — so it's safe to put in a URL a phone is about to
 * request. This signs the browser in with a real cookie, the same as
 * signing in on the website by hand, and sends it on to the page the
 * app actually wanted.
 *
 * A missing, expired, or already-used code isn't treated as an error:
 * the visitor is just sent on their way signed out, and sees the
 * website's own sign-in page if the page they land on needs one.
 */
define( 'WP_USE_THEMES', false );
define( 'COOKIEPATH', '/' );
define( 'SITECOOKIEPATH', '/' );
require_once __DIR__ . '/portal/wp-load.php';

$kounselia_to = isset( $_GET['to'] ) ? wp_unslash( (string) $_GET['to'] ) : '/';
// Only ever a path on this site — never another host, and never one
// that merely looks local (protocol-relative "//" URLs are a browser's
// way of following a scheme to any other host).
if ( '' === $kounselia_to || '/' !== $kounselia_to[0] || 0 === strpos( $kounselia_to, '//' ) ) {
    $kounselia_to = '/';
}

$kounselia_code = isset( $_GET['code'] ) ? (string) $_GET['code'] : '';
if ( '' !== $kounselia_code && function_exists( 'kounselia_app_sso_transient_key' ) ) {
    $kounselia_key     = kounselia_app_sso_transient_key( $kounselia_code );
    $kounselia_user_id = (int) get_transient( $kounselia_key );
    // Single-use: gone the instant it's read, whether or not it checks out.
    delete_transient( $kounselia_key );

    if ( $kounselia_user_id ) {
        wp_set_current_user( $kounselia_user_id );
        wp_set_auth_cookie( $kounselia_user_id, false, is_ssl() );
    }
}

wp_safe_redirect( $kounselia_to );
exit;

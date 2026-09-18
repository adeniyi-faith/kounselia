<?php
/**
 * Kounselia — Paystack checkout callback.
 *
 * Paystack redirects the browser here with ?reference=... once someone
 * finishes (or abandons) checkout, regardless of whether it succeeded —
 * so the reference is verified server-to-server against Paystack's own
 * API (see kounselia_complete_subscription_payment) before anything is
 * activated. This page never trusts the redirect itself as proof of
 * payment, only as "go check this reference."
 */
define( 'WP_USE_THEMES', false );
define( 'COOKIEPATH', '/' );
define( 'SITECOOKIEPATH', '/' );
require_once __DIR__ . '/portal/wp-load.php';

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( '/index.php' );
    exit;
}

$reference   = isset( $_GET['reference'] ) ? sanitize_text_field( wp_unslash( $_GET['reference'] ) ) : '';
$destination = function_exists( 'kounselia_user_is_professional' ) && kounselia_user_is_professional()
    ? '/dashboard.php?as=client'
    : '/dashboard.php';
$separator = ( false !== strpos( $destination, '?' ) ) ? '&' : '?';

if ( ! $reference || ! function_exists( 'kounselia_complete_subscription_payment' ) ) {
    wp_safe_redirect( $destination . $separator . 'sub=failed#upgrade' );
    exit;
}

$result = kounselia_complete_subscription_payment( $reference );

wp_safe_redirect( $destination . $separator . 'sub=' . ( $result['success'] ? 'success' : 'failed' ) . '#upgrade' );
exit;

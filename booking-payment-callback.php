<?php
/**
 * Kounselia — Paystack checkout callback for a booking payment.
 *
 * Same pattern as subscription-callback.php: Paystack redirects the
 * browser here with ?reference=... once someone finishes (or abandons)
 * checkout, regardless of outcome — so the reference is verified
 * server-to-server against Paystack's own API (see
 * kounselia_complete_booking_payment) before the booking is ever marked
 * confirmed. The redirect itself is never trusted as proof of payment,
 * only as "go check this reference."
 */
define( 'WP_USE_THEMES', false );
define( 'COOKIEPATH', '/' );
define( 'SITECOOKIEPATH', '/' );
require_once __DIR__ . '/portal/wp-load.php';

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( '/index.php' );
    exit;
}

$reference = isset( $_GET['reference'] ) ? sanitize_text_field( wp_unslash( $_GET['reference'] ) ) : '';

if ( ! $reference || ! function_exists( 'kounselia_complete_booking_payment' ) ) {
    wp_safe_redirect( '/dashboard.php?booking=failed#professionals' );
    exit;
}

$result = kounselia_complete_booking_payment( $reference );

wp_safe_redirect( '/dashboard.php?booking=' . ( $result['success'] ? 'success' : 'failed' ) . '#professionals' );
exit;

<?php
/**
 * Kounselia — Paystack webhook receiver.
 *
 * Paystack calls this server-to-server for every charge and transfer
 * event, so a payment still gets recorded when the payer closes the tab
 * before the browser redirect (subscription-callback.php /
 * booking-payment-callback.php) finishes. Every request is checked
 * against the x-paystack-signature header, signed with our secret key,
 * before anything is acted on — see kounselia_handle_paystack_webhook().
 *
 * Set this URL (https://<site>/paystack-webhook.php) as the Webhook URL
 * under Settings → API Keys & Webhooks in the Paystack dashboard.
 */
define( 'WP_USE_THEMES', false );
require_once __DIR__ . '/portal/wp-load.php';

if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
    status_header( 405 );
    exit;
}

$kounselia_raw_body  = file_get_contents( 'php://input' );
$kounselia_signature = isset( $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ) ? (string) $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] : '';

$kounselia_status = function_exists( 'kounselia_handle_paystack_webhook' )
    ? kounselia_handle_paystack_webhook( $kounselia_raw_body, $kounselia_signature )
    : 503;

status_header( $kounselia_status );
exit;

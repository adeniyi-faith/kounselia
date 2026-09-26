<?php
/**
 * Kounselia Core — nonce, honeypot, and rate-limit helpers used by every AJAX endpoint
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * 2. SHARED HELPERS
 * ---------------------------------------------------------------------- */

/**
 * Verify the request nonce. Mobile app requests carry no nonce and are
 * signed in by token instead — see app-auth.php for why that's safe.
 */
function kounselia_verify_nonce() {
    if ( function_exists( 'kounselia_is_app_request' ) && kounselia_is_app_request() ) {
        return;
    }
    if ( empty( $_POST['nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['nonce'] ), 'kounselia_auth' ) ) {
        wp_send_json_error( array( 'message' => 'Security check failed, please refresh the page and try again.' ), 403 );
    }
}

/**
 * Honeypot check.
 */
function kounselia_honeypot_tripped() {
    return ! empty( $_POST['website'] );
}

/**
 * Very small rate limiter keyed on IP
 */
function kounselia_rate_limited( $action, $limit = 8, $window_seconds = 300 ) {
    $ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    $key = 'kounselia_rl_' . $action . '_' . md5( $ip );
    $hits = (int) get_transient( $key );

    if ( $hits >= $limit ) {
        return true;
    }

    set_transient( $key, $hits + 1, $window_seconds );
    return false;
}



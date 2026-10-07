<?php
/**
 * Kounselia Core — forgot-password AJAX endpoint
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * 17. FORGOT PASSWORD
 * ---------------------------------------------------------------------- */

function kounselia_ajax_forgot_password() {
    kounselia_verify_nonce();

    if ( kounselia_honeypot_tripped() ) {
        wp_send_json_success(); // Silent success for bots
    }

    if ( kounselia_rate_limited( 'forgot_password', 5, 600 ) ) {
        wp_send_json_error( array( 'message' => 'Too many attempts, please wait a while and try again.' ), 429 );
    }

    $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    if ( empty( $email ) || ! is_email( $email ) ) {
        wp_send_json_error( array( 'message' => 'Please enter a valid email address.' ), 400 );
    }

    $user = get_user_by( 'email', $email );

    if ( $user ) {
        // Generate a secure reset key directly (bypassing native WP email logic)
        $key = get_password_reset_key( $user );
        
        if ( ! is_wp_error( $key ) ) {
            // Build the standard WordPress reset URL
            $reset_url = network_site_url( "wp-login.php?action=rp&key=$key&login=" . rawurlencode( $user->user_login ), 'login' );
            
            $first_name = explode( ' ', trim( $user->display_name ?: $user->user_login ) )[0];
            // The person asking is the one reading: their saved language, else this request's.
            $lang       = kounselia_mail_lang( $user, true );
            $headline   = kounselia_t( 'mail.reset.headline', array(), $lang );
            $content    = "<p style='margin-bottom: 18px;'>" . esc_html( kounselia_t( 'mail.reset.hi', array( 'name' => $first_name ), $lang ) ) . "</p>
                           <p style='margin-bottom: 18px;'>" . esc_html( kounselia_t( 'mail.reset.body1', array(), $lang ) ) . "</p>
                           <p style='margin-bottom: 0;'>" . esc_html( kounselia_t( 'mail.reset.body2', array(), $lang ) ) . "</p>";
            
            kounselia_send_html_email( $email, kounselia_t( 'mail.reset.subject', array(), $lang ), $headline, $content, kounselia_t( 'mail.reset.button', array(), $lang ), $reset_url, array( 'lang' => $lang ) );
        }
    }

    // Always send success to prevent email enumeration attacks
    wp_send_json_success();
}
add_action( 'wp_ajax_nopriv_kounselia_forgot_password', 'kounselia_ajax_forgot_password' );
add_action( 'wp_ajax_kounselia_forgot_password', 'kounselia_ajax_forgot_password' );
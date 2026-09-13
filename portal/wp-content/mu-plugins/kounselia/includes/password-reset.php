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
            $headline   = "Reset your password";
            $content    = "<p style='margin-bottom: 18px;'>Hi {$first_name},</p>
                           <p style='margin-bottom: 18px;'>Someone requested a password reset for your Kounselia account. If this was you, you can set a new password by clicking the button below.</p>
                           <p style='margin-bottom: 0;'>If you didn't request this, you can safely ignore this email and your account will remain secure.</p>";
            
            kounselia_send_html_email( $email, 'Password Reset - Kounselia', $headline, $content, 'Reset Password', $reset_url );
        }
    }

    // Always send success to prevent email enumeration attacks
    wp_send_json_success();
}
add_action( 'wp_ajax_nopriv_kounselia_forgot_password', 'kounselia_ajax_forgot_password' );
add_action( 'wp_ajax_kounselia_forgot_password', 'kounselia_ajax_forgot_password' );
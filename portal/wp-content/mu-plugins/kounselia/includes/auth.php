<?php
/**
 * STREAMING_CHUNK:Initializing WordPress authentication endpoints...
 * Kounselia Core — native WordPress login/register/logout endpoints.
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * 3. LOGIN
 * ---------------------------------------------------------------------- */

function kounselia_ajax_login() {
    kounselia_verify_nonce();

    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $banned_ips = get_option( 'kounselia_banned_ips', array() );
    if ( in_array( $ip, $banned_ips ) ) {
        wp_send_json_error( array( 'message' => 'Access denied from this network.' ), 403 );
    }

    if ( kounselia_honeypot_tripped() ) {
        wp_send_json_success( array(
            'message' => 'Logged in successfully.',
            'redirect' => '/dashboard.php'
        ) );
    }

    if ( kounselia_rate_limited( 'login', 5, 600 ) ) {
        wp_send_json_error( array( 'message' => 'Too many attempts. Please try again later.' ), 429 );
    }

    $email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    $password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';

    if ( empty( $email ) || empty( $password ) ) {
        wp_send_json_error( array( 'message' => 'Please enter both email and password.' ), 400 );
    }

    $user = wp_signon( array(
        'user_login'    => $email,
        'user_password' => $password,
        'remember'      => true,
    ), is_ssl() );

    if ( is_wp_error( $user ) ) {
        wp_send_json_error( array( 'message' => 'That email and password do not match.' ), 401 );
    }
    
    update_user_meta( $user->ID, 'kounselia_last_ip', $ip ); // Track IP

    // Ensure the frontend receives the user's name so it doesn't throw a JS error
    $display_name = $user->display_name ? $user->display_name : $user->user_login;

    wp_send_json_success( array(
        'message'  => 'Logged in successfully.',
        'redirect' => '/dashboard.php',
        'name'     => $display_name,
        'nonce'    => wp_create_nonce( 'kounselia_auth' )
    ) );
}
add_action( 'wp_ajax_nopriv_kounselia_login', 'kounselia_ajax_login' );

/* -------------------------------------------------------------------------
 * 4. REGISTER
 * ---------------------------------------------------------------------- */

function kounselia_ajax_register() {
    kounselia_verify_nonce();

    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $banned_ips = get_option( 'kounselia_banned_ips', array() );
    if ( in_array( $ip, $banned_ips ) ) {
        wp_send_json_error( array( 'message' => 'Registration is currently unavailable from your network.' ), 403 );
    }

    if ( kounselia_honeypot_tripped() ) {
        wp_send_json_success( array(
            'message' => 'Account created successfully.',
            'redirect' => '/dashboard.php'
        ) );
    }

    if ( kounselia_rate_limited( 'register', 3, 3600 ) ) {
        wp_send_json_error( array( 'message' => 'Too many attempts. Please try again later.' ), 429 );
    }

    $email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    $password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
    $name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

    if ( empty( $email ) || empty( $password ) ) {
        wp_send_json_error( array( 'message' => 'Please enter both email and password.' ), 400 );
    }

    if ( ! is_email( $email ) ) {
        wp_send_json_error( array( 'message' => 'Please enter a valid email address.' ), 400 );
    }

    if ( email_exists( $email ) || username_exists( $email ) ) {
        wp_send_json_error( array( 'message' => 'That email is already registered.' ), 400 );
    }

    $user_id = wp_create_user( $email, $password, $email );

    if ( is_wp_error( $user_id ) ) {
        wp_send_json_error( array( 'message' => 'Something went wrong creating your account.' ), 500 );
    }

    // Save the user's name to their WordPress profile during registration
    if ( ! empty( $name ) ) {
        wp_update_user( array(
            'ID'           => $user_id,
            'display_name' => $name,
            'first_name'   => explode( ' ', trim( $name ) )[0]
        ) );
    }

    wp_set_current_user( $user_id );
    wp_set_auth_cookie( $user_id, true, is_ssl() );

    update_user_meta( $user_id, 'kounselia_last_ip', $ip ); // Track IP
    
    // Flag this user so the dashboard knows to show the Intake Modal on first load
    update_user_meta( $user_id, 'kounselia_is_new_user', 1 );

    $user = get_userdata( $user_id );
    $display_name = $user->display_name ? $user->display_name : $user->user_login;

    wp_send_json_success( array(
        'message'  => 'Account created successfully.',
        'redirect' => '/dashboard.php',
        'name'     => $display_name,
        'nonce'    => wp_create_nonce( 'kounselia_auth' )
    ) );
}
add_action( 'wp_ajax_nopriv_kounselia_register', 'kounselia_ajax_register' );

/* -------------------------------------------------------------------------
 * 5. LOGOUT
 * ---------------------------------------------------------------------- */

function kounselia_ajax_logout() {
    kounselia_verify_nonce();
    wp_logout();
    wp_send_json_success();
}
add_action( 'wp_ajax_kounselia_logout', 'kounselia_ajax_logout' );
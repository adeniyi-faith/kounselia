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
 * 2B. BANNED AND TRASHED ACCOUNTS
 *
 * An admin banning a member, or moving them to the Trash on the Members
 * page, locks them out. Before, only the AI chat (and the app) checked
 * this: a banned member could still sign in on the website and book
 * sessions, comment, journal and so on.
 * ---------------------------------------------------------------------- */

define( 'KOUNSELIA_LOCKED_ACCOUNT_MESSAGE', 'This account has been suspended. If you think this is a mistake, please contact support.' );

function kounselia_account_is_locked( $user_id ) {
    return (bool) get_user_meta( $user_id, 'kounselia_banned', true )
        || (bool) get_user_meta( $user_id, 'kounselia_deleted_at', true );
}

/**
 * Refuses a correct password for a locked account, wherever someone signs
 * in (website, app, admin panel, wp-login.php).
 */
function kounselia_block_locked_account_sign_in( $user ) {
    if ( $user instanceof WP_User && kounselia_account_is_locked( $user->ID ) ) {
        return new WP_Error( 'kounselia_account_locked', KOUNSELIA_LOCKED_ACCOUNT_MESSAGE );
    }
    return $user;
}
add_filter( 'authenticate', 'kounselia_block_locked_account_sign_in', 100 );

/**
 * A locked account that is still signed in somewhere (a cookie from
 * before the ban) is treated as signed out on its next visit.
 */
function kounselia_sign_out_locked_account( $user_id ) {
    if ( $user_id && kounselia_account_is_locked( $user_id ) ) {
        return 0;
    }
    return $user_id;
}
add_filter( 'determine_current_user', 'kounselia_sign_out_locked_account', 40 );

/**
 * Signs a member out everywhere: every website session and the app.
 */
function kounselia_end_member_sessions( $user_id ) {
    WP_Session_Tokens::get_instance( $user_id )->destroy_all();
    if ( function_exists( 'kounselia_revoke_app_tokens' ) ) {
        kounselia_revoke_app_tokens( $user_id );
    }
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
        if ( 'kounselia_account_locked' === $user->get_error_code() ) {
            wp_send_json_error( array( 'message' => KOUNSELIA_LOCKED_ACCOUNT_MESSAGE ), 403 );
        }
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

/**
 * Creates a member account. Shared by the website's sign up and the
 * mobile app's (app-auth.php), so both follow the same rules. Returns
 * the new user id, or a WP_Error whose data is the HTTP status to send.
 */
function kounselia_create_member( $name, $email, $password ) {
    if ( empty( $email ) || empty( $password ) ) {
        return new WP_Error( 'kounselia_register', 'Please enter both email and password.', 400 );
    }

    if ( ! is_email( $email ) ) {
        return new WP_Error( 'kounselia_register', 'Please enter a valid email address.', 400 );
    }

    if ( email_exists( $email ) || username_exists( $email ) ) {
        return new WP_Error( 'kounselia_register', 'That email is already registered.', 400 );
    }

    $user_id = wp_create_user( $email, $password, $email );

    if ( is_wp_error( $user_id ) ) {
        return new WP_Error( 'kounselia_register', 'Something went wrong creating your account.', 500 );
    }

    // Save the user's name to their WordPress profile during registration
    if ( ! empty( $name ) ) {
        wp_update_user( array(
            'ID'           => $user_id,
            'display_name' => $name,
            'first_name'   => explode( ' ', trim( $name ) )[0]
        ) );
    }

    // Flag this user so the dashboard knows to show the Intake Modal on first load
    update_user_meta( $user_id, 'kounselia_is_new_user', 1 );

    return $user_id;
}

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

    $user_id = kounselia_create_member(
        isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
        isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
        isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''
    );
    if ( is_wp_error( $user_id ) ) {
        wp_send_json_error( array( 'message' => $user_id->get_error_message() ), (int) $user_id->get_error_data() );
    }

    wp_set_current_user( $user_id );
    wp_set_auth_cookie( $user_id, true, is_ssl() );

    update_user_meta( $user_id, 'kounselia_last_ip', $ip ); // Track IP
    
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
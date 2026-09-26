<?php
/**
 * Kounselia Core — sign-in for the mobile app.
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 *
 * The website signs people in with a browser cookie and protects each
 * request with a nonce printed into the page. A phone app has neither, so
 * it signs in once with kounselia_app_login, gets back a random token,
 * keeps it in the phone's secure storage and sends it with every request
 * in an X-Kounselia-Token header. It then calls the very same admin-ajax
 * actions the website uses — nothing else about those actions changes.
 *
 * App requests are marked with an X-Kounselia-Client: app header (sent
 * even before signing in, e.g. for guest chat or sign up). For those
 * requests:
 *   - cookies are ignored entirely: who you are comes only from the token
 *     (no token, or a bad/expired one, means "signed out");
 *   - the nonce check is skipped. Nonces exist to stop another website
 *     tricking a visitor's browser into sending a request with their
 *     cookie. A browser won't let another website add a custom header to
 *     a request here, and cookies aren't used anyway, so the check has
 *     nothing to protect.
 *
 * A custom header is used rather than "Authorization: Bearer" because
 * shared Apache/cPanel hosting often strips the Authorization header
 * before PHP sees it. Bearer is still accepted as a fallback.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// How long a sign-in lasts without the app being used. Each use pushes
// the expiry forward again, so a member who opens the app regularly stays
// signed in.
define( 'KOUNSELIA_APP_TOKEN_TTL', 90 * DAY_IN_SECONDS );

/**
 * True when the current request came from the mobile app.
 */
function kounselia_is_app_request() {
    if ( ! wp_doing_ajax() ) {
        return false;
    }
    $client = isset( $_SERVER['HTTP_X_KOUNSELIA_CLIENT'] ) ? strtolower( (string) $_SERVER['HTTP_X_KOUNSELIA_CLIENT'] ) : '';
    return 'app' === $client || '' !== kounselia_app_token_from_request();
}

/**
 * The raw token the app sent with this request, or '' if none.
 */
function kounselia_app_token_from_request() {
    if ( ! empty( $_SERVER['HTTP_X_KOUNSELIA_TOKEN'] ) ) {
        return trim( (string) $_SERVER['HTTP_X_KOUNSELIA_TOKEN'] );
    }
    foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
        if ( ! empty( $_SERVER[ $key ] ) && preg_match( '/^Bearer\s+(\S+)$/i', (string) $_SERVER[ $key ], $m ) ) {
            return $m[1];
        }
    }
    return '';
}

function kounselia_app_token_hash( $raw_token ) {
    return hash( 'sha256', (string) $raw_token );
}

/**
 * Creates a new sign-in for a user and returns the raw token. The raw
 * token is only ever returned here, to the app; the database keeps a hash.
 */
function kounselia_issue_app_token( $user_id, $device_name = '' ) {
    global $wpdb;

    $raw = bin2hex( random_bytes( 32 ) );
    $now = time();

    $wpdb->insert( $wpdb->prefix . 'kounselia_app_tokens', array(
        'user_id'      => (int) $user_id,
        'token_hash'   => kounselia_app_token_hash( $raw ),
        'device_name'  => mb_substr( sanitize_text_field( (string) $device_name ), 0, 100 ),
        'created_at'   => gmdate( 'Y-m-d H:i:s', $now ),
        'last_used_at' => gmdate( 'Y-m-d H:i:s', $now ),
        'expires_at'   => gmdate( 'Y-m-d H:i:s', $now + KOUNSELIA_APP_TOKEN_TTL ),
    ) );

    return $raw;
}

/**
 * The user a raw token belongs to, or 0 if it's unknown or expired.
 * Also slides the expiry forward, at most once an hour so a busy chat
 * doesn't write to the database on every message.
 */
function kounselia_user_id_from_app_token( $raw_token ) {
    global $wpdb;

    if ( '' === $raw_token || strlen( $raw_token ) > 128 ) {
        return 0;
    }

    $table = $wpdb->prefix . 'kounselia_app_tokens';
    $row   = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, user_id, last_used_at, expires_at FROM {$table} WHERE token_hash = %s",
        kounselia_app_token_hash( $raw_token )
    ) );

    if ( ! $row ) {
        return 0;
    }

    $now = time();
    if ( strtotime( $row->expires_at . ' UTC' ) < $now ) {
        $wpdb->delete( $table, array( 'id' => $row->id ) );
        return 0;
    }

    if ( strtotime( $row->last_used_at . ' UTC' ) < $now - HOUR_IN_SECONDS ) {
        $wpdb->update( $table, array(
            'last_used_at' => gmdate( 'Y-m-d H:i:s', $now ),
            'expires_at'   => gmdate( 'Y-m-d H:i:s', $now + KOUNSELIA_APP_TOKEN_TTL ),
        ), array( 'id' => $row->id ) );
    }

    return (int) $row->user_id;
}

/**
 * Signs out every device a user has the app on, optionally keeping one
 * token (the device making the request).
 */
function kounselia_revoke_app_tokens( $user_id, $keep_raw_token = '' ) {
    global $wpdb;
    $table = $wpdb->prefix . 'kounselia_app_tokens';

    if ( '' !== $keep_raw_token ) {
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE user_id = %d AND token_hash <> %s",
            $user_id,
            kounselia_app_token_hash( $keep_raw_token )
        ) );
        return;
    }
    $wpdb->delete( $table, array( 'user_id' => (int) $user_id ) );
}

/**
 * Decides who is signed in on an app request. Runs after WordPress's own
 * cookie check (priority 10/20) and overrides it for app requests only;
 * website requests are left exactly as they were.
 */
function kounselia_determine_app_user( $user_id ) {
    if ( ! kounselia_is_app_request() ) {
        return $user_id;
    }
    return kounselia_user_id_from_app_token( kounselia_app_token_from_request() );
}
add_filter( 'determine_current_user', 'kounselia_determine_app_user', 30 );

/**
 * A password change signs the member out of the app everywhere else —
 * the usual reason for changing a password is worrying someone else has
 * it. The phone that made the change (if it was the app) stays signed in.
 */
function kounselia_revoke_app_tokens_on_password_change( $password, $user_id ) {
    $keep = ( kounselia_is_app_request() && get_current_user_id() === (int) $user_id )
        ? kounselia_app_token_from_request()
        : '';
    kounselia_revoke_app_tokens( (int) $user_id, $keep );
}
add_action( 'wp_set_password', 'kounselia_revoke_app_tokens_on_password_change', 10, 2 );

function kounselia_delete_app_tokens_for_deleted_user( $user_id ) {
    kounselia_revoke_app_tokens( (int) $user_id );
}
add_action( 'deleted_user', 'kounselia_delete_app_tokens_for_deleted_user' );

/**
 * What the app needs to know about the signed-in member.
 */
function kounselia_app_user_payload( $user ) {
    return array(
        'id'    => (int) $user->ID,
        'name'  => $user->display_name ? $user->display_name : $user->user_login,
        'email' => $user->user_email,
    );
}

/* -------------------------------------------------------------------------
 * APP: SIGN IN
 * ---------------------------------------------------------------------- */

function kounselia_ajax_app_login() {
    kounselia_verify_nonce();

    $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
    if ( in_array( $ip, get_option( 'kounselia_banned_ips', array() ) ) ) {
        wp_send_json_error( array( 'message' => 'Access denied from this network.' ), 403 );
    }

    if ( kounselia_rate_limited( 'login', 5, 600 ) ) {
        wp_send_json_error( array( 'message' => 'Too many attempts. Please try again later.' ), 429 );
    }

    $email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    $password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
    $device   = isset( $_POST['device_name'] ) ? wp_unslash( $_POST['device_name'] ) : '';

    if ( empty( $email ) || empty( $password ) ) {
        wp_send_json_error( array( 'message' => 'Please enter both email and password.' ), 400 );
    }

    // wp_authenticate checks the password without setting a cookie,
    // unlike wp_signon which the website uses.
    $user = wp_authenticate( $email, $password );
    if ( is_wp_error( $user ) ) {
        wp_send_json_error( array( 'message' => 'That email and password do not match.' ), 401 );
    }

    update_user_meta( $user->ID, 'kounselia_last_ip', $ip );

    wp_send_json_success( array(
        'token' => kounselia_issue_app_token( $user->ID, $device ),
        'user'  => kounselia_app_user_payload( $user ),
    ) );
}
add_action( 'wp_ajax_nopriv_kounselia_app_login', 'kounselia_ajax_app_login' );
add_action( 'wp_ajax_kounselia_app_login', 'kounselia_ajax_app_login' );

/* -------------------------------------------------------------------------
 * APP: SIGN UP
 * ---------------------------------------------------------------------- */

function kounselia_ajax_app_register() {
    kounselia_verify_nonce();

    $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
    if ( in_array( $ip, get_option( 'kounselia_banned_ips', array() ) ) ) {
        wp_send_json_error( array( 'message' => 'Registration is currently unavailable from your network.' ), 403 );
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

    update_user_meta( $user_id, 'kounselia_last_ip', $ip );

    $device = isset( $_POST['device_name'] ) ? wp_unslash( $_POST['device_name'] ) : '';
    wp_send_json_success( array(
        'token' => kounselia_issue_app_token( $user_id, $device ),
        'user'  => kounselia_app_user_payload( get_userdata( $user_id ) ),
    ) );
}
add_action( 'wp_ajax_nopriv_kounselia_app_register', 'kounselia_ajax_app_register' );

/* -------------------------------------------------------------------------
 * APP: WHO AM I / SIGN OUT
 * ---------------------------------------------------------------------- */

// Lets the app check, when it opens, that its saved sign-in still works.
function kounselia_ajax_app_me() {
    kounselia_verify_nonce();
    wp_send_json_success( array( 'user' => kounselia_app_user_payload( wp_get_current_user() ) ) );
}
add_action( 'wp_ajax_kounselia_app_me', 'kounselia_ajax_app_me' );

function kounselia_ajax_app_me_signed_out() {
    wp_send_json_error( array( 'message' => 'Please sign in again.', 'signed_out' => true ), 401 );
}
add_action( 'wp_ajax_nopriv_kounselia_app_me', 'kounselia_ajax_app_me_signed_out' );

// Signs out this phone only.
function kounselia_ajax_app_logout() {
    global $wpdb;
    kounselia_verify_nonce();
    $wpdb->delete( $wpdb->prefix . 'kounselia_app_tokens', array(
        'token_hash' => kounselia_app_token_hash( kounselia_app_token_from_request() ),
    ) );
    wp_send_json_success();
}
add_action( 'wp_ajax_kounselia_app_logout', 'kounselia_ajax_app_logout' );

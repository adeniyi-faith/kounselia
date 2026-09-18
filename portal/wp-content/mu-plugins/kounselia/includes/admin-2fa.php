<?php
/**
 * Kounselia Core — Two-factor authentication (TOTP) for the admin panel.
 *
 * Standard 30-second, 6-digit TOTP (RFC 6238) implemented with nothing but
 * hash_hmac, so no Composer/external library is required. Each admin sets
 * this up for their own account from Settings > Security; once enabled,
 * the login screen (portal/admin/index.php) requires a valid code (or a
 * one-time backup code) before it will issue the session cookie.
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * TOTP primitives
 * ---------------------------------------------------------------------- */

function kounselia_base32_encode( $data ) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $binary   = '';
    foreach ( str_split( $data ) as $char ) {
        $binary .= str_pad( decbin( ord( $char ) ), 8, '0', STR_PAD_LEFT );
    }
    $output = '';
    foreach ( str_split( $binary, 5 ) as $chunk ) {
        $chunk = str_pad( $chunk, 5, '0', STR_PAD_RIGHT );
        $output .= $alphabet[ bindec( $chunk ) ];
    }
    return $output;
}

function kounselia_base32_decode( $b32 ) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32      = strtoupper( preg_replace( '/[^A-Z2-7]/', '', $b32 ) );
    $binary   = '';
    foreach ( str_split( $b32 ) as $char ) {
        $pos = strpos( $alphabet, $char );
        if ( false === $pos ) {
            continue;
        }
        $binary .= str_pad( decbin( $pos ), 5, '0', STR_PAD_LEFT );
    }
    $bytes = '';
    foreach ( str_split( $binary, 8 ) as $chunk ) {
        if ( strlen( $chunk ) < 8 ) {
            continue;
        }
        $bytes .= chr( bindec( $chunk ) );
    }
    return $bytes;
}

function kounselia_2fa_generate_secret() {
    return kounselia_base32_encode( random_bytes( 20 ) ); // 160-bit secret
}

function kounselia_totp_code( $secret, $timestamp = null, $period = 30, $digits = 6 ) {
    $timestamp = null === $timestamp ? time() : $timestamp;
    $counter   = (int) floor( $timestamp / $period );

    $binary_counter = pack( 'N*', 0 ) . pack( 'N*', $counter ); // 8-byte big-endian counter
    $key            = kounselia_base32_decode( $secret );
    $hash           = hash_hmac( 'sha1', $binary_counter, $key, true );

    $offset = ord( $hash[19] ) & 0xf;
    $code   = (
        ( ( ord( $hash[ $offset ] ) & 0x7f ) << 24 ) |
        ( ( ord( $hash[ $offset + 1 ] ) & 0xff ) << 16 ) |
        ( ( ord( $hash[ $offset + 2 ] ) & 0xff ) << 8 ) |
        ( ord( $hash[ $offset + 3 ] ) & 0xff )
    );

    return str_pad( (string) ( $code % (int) pow( 10, $digits ) ), $digits, '0', STR_PAD_LEFT );
}

/**
 * Accepts a code from up to one 30s step of clock drift either side, so a
 * phone that's a few seconds off doesn't lock the admin out.
 */
function kounselia_totp_verify( $secret, $code, $window = 1 ) {
    $code = preg_replace( '/\s+/', '', (string) $code );
    if ( '' === $code ) {
        return false;
    }
    for ( $i = -$window; $i <= $window; $i++ ) {
        if ( hash_equals( kounselia_totp_code( $secret, time() + ( $i * 30 ) ), $code ) ) {
            return true;
        }
    }
    return false;
}

function kounselia_2fa_provisioning_uri( $secret, $email ) {
    $issuer = 'Kounselia Admin';
    return 'otpauth://totp/' . rawurlencode( $issuer . ':' . $email )
        . '?secret=' . $secret
        . '&issuer=' . rawurlencode( $issuer )
        . '&algorithm=SHA1&digits=6&period=30';
}

/* -------------------------------------------------------------------------
 * Backup codes — 10 single-use codes, only the hash is stored.
 * ---------------------------------------------------------------------- */

function kounselia_2fa_generate_backup_codes() {
    $codes = array();
    for ( $i = 0; $i < 10; $i++ ) {
        $codes[] = strtoupper( substr( bin2hex( random_bytes( 5 ) ), 0, 10 ) );
    }
    return $codes;
}

function kounselia_2fa_hash_backup_codes( $codes ) {
    return array_map( function( $code ) {
        return wp_hash_password( $code );
    }, $codes );
}

function kounselia_2fa_consume_backup_code( $user_id, $code ) {
    $code   = strtoupper( trim( (string) $code ) );
    $hashes = get_user_meta( $user_id, 'kounselia_2fa_backup_codes', true );
    $hashes = is_array( $hashes ) ? $hashes : array();

    foreach ( $hashes as $index => $hash ) {
        if ( wp_check_password( $code, $hash ) ) {
            unset( $hashes[ $index ] );
            update_user_meta( $user_id, 'kounselia_2fa_backup_codes', array_values( $hashes ) );
            return true;
        }
    }
    return false;
}

function kounselia_2fa_is_enabled( $user_id ) {
    return (bool) get_user_meta( $user_id, 'kounselia_2fa_enabled', true );
}

/* -------------------------------------------------------------------------
 * Pending-login handoff between "password correct" and "code correct".
 *
 * wp_signon()/wp_set_auth_cookie() are never called until the TOTP step
 * also passes, so a stolen password alone can't open a session. The gap
 * between the two is bridged by a short-lived transient (5 min) keyed by
 * a random token that only ever leaves the server in an HttpOnly cookie.
 * ---------------------------------------------------------------------- */

function kounselia_2fa_start_pending_login( $user_id ) {
    $token = wp_generate_password( 40, false );
    set_transient( 'kounselia_2fa_pending_' . $token, $user_id, 5 * MINUTE_IN_SECONDS );

    setcookie(
        'kounselia_2fa_pending',
        $token,
        array(
            'expires'  => time() + ( 5 * MINUTE_IN_SECONDS ),
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Strict',
        )
    );

    return $token;
}

function kounselia_2fa_get_pending_user_id() {
    if ( empty( $_COOKIE['kounselia_2fa_pending'] ) ) {
        return 0;
    }
    $token = sanitize_text_field( wp_unslash( $_COOKIE['kounselia_2fa_pending'] ) );
    $user_id = get_transient( 'kounselia_2fa_pending_' . $token );
    return $user_id ? (int) $user_id : 0;
}

function kounselia_2fa_clear_pending_login() {
    if ( ! empty( $_COOKIE['kounselia_2fa_pending'] ) ) {
        $token = sanitize_text_field( wp_unslash( $_COOKIE['kounselia_2fa_pending'] ) );
        delete_transient( 'kounselia_2fa_pending_' . $token );
        setcookie( 'kounselia_2fa_pending', '', array(
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Strict',
        ) );
    }
}

/* -------------------------------------------------------------------------
 * AJAX: setup flow (used by the Settings > Security panel)
 * ---------------------------------------------------------------------- */

function kounselia_ajax_admin_2fa_setup_init() {
    check_ajax_referer( 'kounselia_admin_nonce', 'nonce' );
    if ( ! kounselia_user_is_admin() ) {
        kounselia_send_pure_json_error( array( 'message' => 'Unauthorized' ), 403 );
    }

    $user_id = get_current_user_id();
    $secret  = kounselia_2fa_generate_secret();

    // Held as "pending" (5 min) until confirmed with a real code, so a
    // half-finished setup never silently activates.
    set_transient( 'kounselia_2fa_setup_' . $user_id, $secret, 5 * MINUTE_IN_SECONDS );

    $user = wp_get_current_user();
    kounselia_send_pure_json_success( array(
        'secret' => $secret,
        'uri'    => kounselia_2fa_provisioning_uri( $secret, $user->user_email ),
    ) );
}
add_action( 'wp_ajax_kounselia_admin_2fa_setup_init', 'kounselia_ajax_admin_2fa_setup_init' );

function kounselia_ajax_admin_2fa_setup_confirm() {
    check_ajax_referer( 'kounselia_admin_nonce', 'nonce' );
    if ( ! kounselia_user_is_admin() ) {
        kounselia_send_pure_json_error( array( 'message' => 'Unauthorized' ), 403 );
    }

    $user_id = get_current_user_id();
    $secret  = get_transient( 'kounselia_2fa_setup_' . $user_id );
    $code    = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';

    if ( ! $secret ) {
        kounselia_send_pure_json_error( array( 'message' => 'Setup expired, please start again.' ), 400 );
    }
    if ( ! kounselia_totp_verify( $secret, $code ) ) {
        kounselia_send_pure_json_error( array( 'message' => 'That code did not match. Check your authenticator app and try again.' ), 400 );
    }

    $backup_codes = kounselia_2fa_generate_backup_codes();

    update_user_meta( $user_id, 'kounselia_2fa_secret', $secret );
    update_user_meta( $user_id, 'kounselia_2fa_enabled', 1 );
    update_user_meta( $user_id, 'kounselia_2fa_backup_codes', kounselia_2fa_hash_backup_codes( $backup_codes ) );
    delete_transient( 'kounselia_2fa_setup_' . $user_id );

    kounselia_admin_log( 'enabled_2fa', 'user', $user_id );

    kounselia_send_pure_json_success( array(
        'message'      => 'Two-factor authentication is now on for your account.',
        'backup_codes' => $backup_codes,
    ) );
}
add_action( 'wp_ajax_kounselia_admin_2fa_setup_confirm', 'kounselia_ajax_admin_2fa_setup_confirm' );

function kounselia_ajax_admin_2fa_disable() {
    check_ajax_referer( 'kounselia_admin_nonce', 'nonce' );
    if ( ! kounselia_user_is_admin() ) {
        kounselia_send_pure_json_error( array( 'message' => 'Unauthorized' ), 403 );
    }

    $user_id = get_current_user_id();
    $code    = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
    $secret  = get_user_meta( $user_id, 'kounselia_2fa_secret', true );

    $ok = $secret && kounselia_totp_verify( $secret, $code );
    if ( ! $ok ) {
        $ok = kounselia_2fa_consume_backup_code( $user_id, $code );
    }
    if ( ! $ok ) {
        kounselia_send_pure_json_error( array( 'message' => 'That code did not match.' ), 400 );
    }

    delete_user_meta( $user_id, 'kounselia_2fa_secret' );
    delete_user_meta( $user_id, 'kounselia_2fa_enabled' );
    delete_user_meta( $user_id, 'kounselia_2fa_backup_codes' );

    kounselia_admin_log( 'disabled_2fa', 'user', $user_id );
    kounselia_send_pure_json_success( array( 'message' => 'Two-factor authentication turned off.' ) );
}
add_action( 'wp_ajax_kounselia_admin_2fa_disable', 'kounselia_ajax_admin_2fa_disable' );

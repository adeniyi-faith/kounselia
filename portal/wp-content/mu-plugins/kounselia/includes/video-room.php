<?php
/**
 * Kounselia Core — where Kounselia's own video room runs.
 *
 * The free public Jitsi server (meet.jit.si) now makes whoever arrives
 * first wait with "The conference has not yet started because no
 * moderators have yet arrived... please log-in", until someone signs in
 * with a Google/GitHub account. Our members never have that account, so
 * a session could stall on that screen.
 *
 * The fix is 8x8's hosted Jitsi ("Jitsi as a Service", JaaS, at 8x8.vc):
 * the same Jitsi call screen, but each person is handed a short-lived
 * signed pass (a JWT — a small piece of text Kounselia signs with a
 * private key, which 8x8 checks with the matching public key). The pass
 * names the one room it is for and marks its holder as a host
 * ("moderator"), so whoever arrives first goes straight in and nobody is
 * ever asked to log in.
 *
 * An admin pastes the 8x8 App ID, API key ID and private key on Platform
 * Settings → Video calls (or sets KOUNSELIA_JAAS_APP_ID /
 * KOUNSELIA_JAAS_KEY_ID / KOUNSELIA_JAAS_PRIVATE_KEY in wp-config.php).
 * Until then, calls keep using meet.jit.si exactly as before.
 *
 * Passes are only made by video-call.php (website) and
 * kounselia_get_booking_room (app), after both have checked it's one of
 * the two people on the booking and that it's time to join.
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The 8x8 settings, from wp-config.php constants first, then the admin
 * page. Returns app_id, key_id (the full "vpaas-magic-cookie-.../abc"
 * form 8x8 expects) and private_key, each possibly ''.
 */
function kounselia_jaas_settings() {
    $app_id = defined( 'KOUNSELIA_JAAS_APP_ID' ) ? KOUNSELIA_JAAS_APP_ID : get_option( 'kounselia_jaas_app_id', '' );
    $key_id = defined( 'KOUNSELIA_JAAS_KEY_ID' ) ? KOUNSELIA_JAAS_KEY_ID : get_option( 'kounselia_jaas_key_id', '' );
    $key    = defined( 'KOUNSELIA_JAAS_PRIVATE_KEY' ) ? KOUNSELIA_JAAS_PRIVATE_KEY : get_option( 'kounselia_jaas_private_key', '' );

    $app_id = trim( (string) $app_id );
    $key_id = trim( (string) $key_id );

    // The 8x8 console shows the key ID on its own ("abc123") in some
    // places and with the App ID in front in others. Accept both.
    if ( '' !== $app_id && '' !== $key_id && false === strpos( $key_id, '/' ) ) {
        $key_id = $app_id . '/' . $key_id;
    }

    return array(
        'app_id'      => $app_id,
        'key_id'      => $key_id,
        'private_key' => trim( (string) $key ),
    );
}

/** True when all three 8x8 settings are filled in and the key can be read. */
function kounselia_jaas_is_configured() {
    $s = kounselia_jaas_settings();
    if ( '' === $s['app_id'] || '' === $s['key_id'] || '' === $s['private_key'] ) {
        return false;
    }
    return false !== openssl_pkey_get_private( $s['private_key'] );
}

function kounselia_base64url( $data ) {
    return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}

/**
 * A signed pass into one 8x8 room, or '' if it couldn't be made.
 *
 * $room is the room name without the App ID in front. $seconds is how
 * long the pass works for joining (a call already going isn't cut off
 * when it runs out).
 */
function kounselia_jaas_token( $room, $user, $seconds ) {
    $s   = kounselia_jaas_settings();
    $key = openssl_pkey_get_private( $s['private_key'] );
    if ( ! $key ) {
        return '';
    }

    $name   = $user->display_name ? $user->display_name : $user->user_login;
    $header = array( 'alg' => 'RS256', 'typ' => 'JWT', 'kid' => $s['key_id'] );
    $claims = array(
        'aud'     => 'jitsi',
        'iss'     => 'chat',
        'sub'     => $s['app_id'],
        'room'    => (string) $room,
        'nbf'     => time() - MINUTE_IN_SECONDS,
        'exp'     => time() + max( 0, (int) $seconds ),
        'context' => array(
            'user'     => array(
                'id'        => (string) $user->ID,
                'name'      => $name,
                'avatar'    => '',
                'email'     => '',
                // Both people are hosts, so neither ever waits for the other.
                'moderator' => 'true',
            ),
            'features' => array(
                'livestreaming' => 'false',
                'recording'     => 'false',
                'transcription' => 'false',
                'outbound-call' => 'false',
            ),
        ),
    );

    $signing_input = kounselia_base64url( wp_json_encode( $header ) ) . '.' . kounselia_base64url( wp_json_encode( $claims ) );
    $signature     = '';
    if ( ! openssl_sign( $signing_input, $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
        return '';
    }
    return $signing_input . '.' . kounselia_base64url( $signature );
}

/**
 * Everything needed to put $user into Kounselia's room for $booking.
 * Only call this once the caller has checked the person and the time.
 *
 *   domain     — the Jitsi server for the website's embedded call
 *   room       — the room name as that server wants it
 *   jwt        — the signed pass ('' on meet.jit.si)
 *   script_url — Jitsi's embedding script for that server
 *   url        — a plain link to the same room (used by the app)
 */
function kounselia_booking_room( $booking, $user ) {
    $room = 'kounselia-' . $booking->room_token;
    $name = $user->display_name ? $user->display_name : $user->user_login;
    $hash = '#config.prejoinPageEnabled=true&config.disableDeepLinking=true'
        . '&userInfo.displayName=' . rawurlencode( wp_json_encode( $name ) );

    if ( kounselia_jaas_is_configured() ) {
        // The pass works for joining until the session's window closes,
        // plus an hour's slack for clocks that disagree.
        $window = kounselia_booking_join_window( $booking );
        $left   = $window['closes_at'] - current_time( 'timestamp' );
        $jwt    = kounselia_jaas_token( $room, $user, max( 0, $left ) + HOUR_IN_SECONDS );

        if ( '' !== $jwt ) {
            $app_id = kounselia_jaas_settings()['app_id'];
            return array(
                'domain'     => '8x8.vc',
                'room'       => $app_id . '/' . $room,
                'jwt'        => $jwt,
                'script_url' => 'https://8x8.vc/' . rawurlencode( $app_id ) . '/external_api.js',
                'url'        => 'https://8x8.vc/' . rawurlencode( $app_id ) . '/' . rawurlencode( $room ) . '?jwt=' . rawurlencode( $jwt ) . $hash,
            );
        }
    }

    return array(
        'domain'     => 'meet.jit.si',
        'room'       => $room,
        'jwt'        => '',
        'script_url' => 'https://meet.jit.si/external_api.js',
        'url'        => 'https://meet.jit.si/' . rawurlencode( $room ) . $hash,
    );
}

<?php
/**
 * Kounselia Core — the one place any feature sends someone a
 * notification, so "how do we tell a user something" is answered once
 * instead of once per feature.
 *
 * kounselia_notify_user() does three things for every call:
 *   1. Records it in kounselia_notifications — an in-app notification
 *      history (see kounselia_ajax_get_notifications below), read by the
 *      bell icon on both dashboards today.
 *   2. Emails it, using the same branded template every other email on
 *      the site already uses.
 *   3. Attempts a push notification via kounselia_send_push_to_user().
 *      There is no mobile app yet, so this is currently always a no-op
 *      — but it reads from a real device-token table
 *      (kounselia_push_tokens) and a real provider-key check, so wiring
 *      up an actual push provider later is additive, not a rewrite of
 *      every call site that wants to notify someone.
 *
 * Every reminder, booking confirmation, cancellation, etc. that wants to
 * reach a user should go through this function rather than calling
 * kounselia_send_html_email() directly, so it's never missing from the
 * in-app history or from push once push is real.
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * @param int    $user_id
 * @param string $type          Short machine tag, e.g. 'booking_reminder', 'booking_cancelled'.
 * @param string $title         Short in-app/push title.
 * @param string $body          Short in-app/push body text.
 * @param string $url           Where tapping the notification should go (relative path).
 * @param array  $email         Optional. If given, also emails the user. Keys: subject, headline, content_html, btn_text, btn_url.
 */
function kounselia_notify_user( $user_id, $type, $title, $body, $url = '', $email = null ) {
    global $wpdb;

    if ( ! $user_id ) {
        return;
    }

    $wpdb->insert( $wpdb->prefix . 'kounselia_notifications', array(
        'user_id'    => $user_id,
        'type'       => sanitize_key( $type ),
        'title'      => $title,
        'body'       => $body,
        'url'        => $url ?: null,
        'created_at' => current_time( 'mysql' ),
    ) );

    if ( $email && function_exists( 'kounselia_send_html_email' ) ) {
        $user = get_userdata( $user_id );
        if ( $user ) {
            kounselia_send_html_email(
                $user->user_email,
                $email['subject'],
                $email['headline'],
                $email['content_html'],
                isset( $email['btn_text'] ) ? $email['btn_text'] : null,
                isset( $email['btn_url'] ) ? $email['btn_url'] : null
            );
        }
    }

    kounselia_send_push_to_user( $user_id, $title, $body, $url );
}

/**
 * Sends a push notification to every device a user has registered.
 * There is no push provider configured today (no mobile app exists to
 * register a device with one), so this always no-ops in that case — it
 * exists so that the day a provider key is defined, this is the only
 * function that needs a real implementation, not every call site that
 * wants to notify someone.
 */
function kounselia_send_push_to_user( $user_id, $title, $body, $url = '' ) {
    if ( ! defined( 'KOUNSELIA_PUSH_PROVIDER_KEY' ) || ! KOUNSELIA_PUSH_PROVIDER_KEY ) {
        return false;
    }

    global $wpdb;
    $tokens = $wpdb->get_results( $wpdb->prepare(
        "SELECT platform, token FROM {$wpdb->prefix}kounselia_push_tokens WHERE user_id = %d",
        $user_id
    ) );
    if ( empty( $tokens ) ) {
        return false;
    }

    /**
     * Intentionally unimplemented until there's a real provider (FCM,
     * APNs, or a unified service like OneSignal) to send through. Wire
     * that call in here — the token list and the KOUNSELIA_PUSH_PROVIDER_KEY
     * gate are already in place so nothing else needs to change.
     */
    do_action( 'kounselia_send_push_notification', $tokens, $title, $body, $url );

    return true;
}

/**
 * A device registering (or re-registering, e.g. after a token refresh)
 * for push notifications. Dormant until a mobile app actually calls it
 * — nothing on the current web app does.
 */
function kounselia_ajax_register_push_token() {
    kounselia_verify_nonce();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.' ), 401 );
    }

    $platform = isset( $_POST['platform'] ) ? sanitize_key( $_POST['platform'] ) : '';
    $token    = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

    if ( ! in_array( $platform, array( 'ios', 'android', 'web' ), true ) || '' === $token ) {
        wp_send_json_error( array( 'message' => 'Invalid device token.' ), 400 );
    }

    global $wpdb;
    $wpdb->query( $wpdb->prepare(
        "INSERT INTO {$wpdb->prefix}kounselia_push_tokens (user_id, platform, token, created_at)
         VALUES (%d, %s, %s, %s)
         ON DUPLICATE KEY UPDATE platform = VALUES(platform), created_at = VALUES(created_at)",
        get_current_user_id(),
        $platform,
        $token,
        current_time( 'mysql' )
    ) );

    wp_send_json_success( array( 'message' => 'Registered.' ) );
}
add_action( 'wp_ajax_kounselia_register_push_token', 'kounselia_ajax_register_push_token' );

/* -------------------------------------------------------------------------
 * IN-APP NOTIFICATION FEED (the bell icon on both dashboards)
 * ---------------------------------------------------------------------- */

function kounselia_get_notifications( $user_id, $limit = 20 ) {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}kounselia_notifications WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
        $user_id, $limit
    ) );
}

function kounselia_count_unread_notifications( $user_id ) {
    global $wpdb;
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_notifications WHERE user_id = %d AND read_at IS NULL",
        $user_id
    ) );
}

function kounselia_ajax_get_notifications() {
    kounselia_verify_nonce();
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.' ), 401 );
    }

    $user_id = get_current_user_id();
    $notifications = kounselia_get_notifications( $user_id );

    global $wpdb;
    $wpdb->query( $wpdb->prepare(
        "UPDATE {$wpdb->prefix}kounselia_notifications SET read_at = %s WHERE user_id = %d AND read_at IS NULL",
        current_time( 'mysql' ),
        $user_id
    ) );

    $out = array_map( function( $n ) {
        return array(
            'id'         => (int) $n->id,
            'title'      => $n->title,
            'body'       => $n->body,
            'url'        => $n->url,
            'created_at' => $n->created_at,
            'unread'     => empty( $n->read_at ),
        );
    }, $notifications );

    wp_send_json_success( array( 'notifications' => $out ) );
}
add_action( 'wp_ajax_kounselia_get_notifications', 'kounselia_ajax_get_notifications' );

function kounselia_ajax_get_unread_notification_count() {
    kounselia_verify_nonce();
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.' ), 401 );
    }
    wp_send_json_success( array( 'count' => kounselia_count_unread_notifications( get_current_user_id() ) ) );
}
add_action( 'wp_ajax_kounselia_get_unread_notification_count', 'kounselia_ajax_get_unread_notification_count' );

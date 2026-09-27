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
 *   3. Sends a push notification to every phone the member has the app
 *      on and has allowed notifications (kounselia_send_push_to_user()),
 *      through Expo's push service, which passes it on to Apple and
 *      Google.
 *
 * Every reminder, booking confirmation, cancellation, etc. that wants to
 * reach a user should go through this function rather than calling
 * kounselia_send_html_email() directly, so it's never missing from the
 * in-app history or from push.
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

// Expo's push service: the app registers an "Expo push token" (see
// mobile/src/notifications.ts), and one request here reaches both iPhones
// (through Apple) and Android phones (through Google). Apple and Google
// credentials are kept by Expo, not here. Optionally, an Expo access token
// (expo.dev → Account settings → Access tokens, with "enhanced security for
// push notifications" switched on) can be put in wp-config.php as
// KOUNSELIA_EXPO_ACCESS_TOKEN, so only this server can send to the app.
define( 'KOUNSELIA_EXPO_PUSH_URL', 'https://exp.host/--/api/v2/push/send' );

/**
 * Sends a push notification to every phone a user has registered. Does
 * nothing if they have none (never installed the app, or said no to
 * notifications). Returns true if anything was sent.
 */
function kounselia_send_push_to_user( $user_id, $title, $body, $url = '' ) {
    global $wpdb;
    $tokens = $wpdb->get_results( $wpdb->prepare(
        "SELECT platform, token FROM {$wpdb->prefix}kounselia_push_tokens WHERE user_id = %d",
        $user_id
    ) );
    if ( empty( $tokens ) ) {
        return false;
    }

    // Still here so another push provider could be added alongside Expo.
    do_action( 'kounselia_send_push_notification', $tokens, $title, $body, $url );

    $to = array();
    foreach ( $tokens as $t ) {
        if ( kounselia_is_expo_push_token( $t->token ) ) {
            $to[] = $t->token;
        }
    }
    if ( empty( $to ) ) {
        return false;
    }

    $messages = array();
    foreach ( $to as $token ) {
        $messages[] = array(
            'to'        => $token,
            'title'     => wp_strip_all_tags( $title ),
            'body'      => wp_strip_all_tags( $body ),
            'data'      => array( 'url' => (string) $url ),
            'sound'     => 'default',
            'channelId' => 'default',
            'priority'  => 'high',
        );
    }

    $headers = array(
        'Content-Type' => 'application/json',
        'Accept'       => 'application/json',
    );
    if ( defined( 'KOUNSELIA_EXPO_ACCESS_TOKEN' ) && KOUNSELIA_EXPO_ACCESS_TOKEN ) {
        $headers['Authorization'] = 'Bearer ' . KOUNSELIA_EXPO_ACCESS_TOKEN;
    }

    // Short timeout: this runs inside whatever request triggered the
    // notification (a booking, a comment), which shouldn't hang on it.
    $response = wp_remote_post( KOUNSELIA_EXPO_PUSH_URL, array(
        'timeout' => 5,
        'headers' => $headers,
        'body'    => wp_json_encode( $messages ),
    ) );
    if ( is_wp_error( $response ) ) {
        error_log( 'Kounselia push: ' . $response->get_error_message() );
        return false;
    }

    // Expo answers with one "ticket" per message, in the same order. A
    // phone the app was deleted from (or that turned notifications off)
    // answers DeviceNotRegistered: forget that token so we stop trying.
    $json    = json_decode( wp_remote_retrieve_body( $response ), true );
    $tickets = isset( $json['data'] ) && is_array( $json['data'] ) ? $json['data'] : array();
    foreach ( $tickets as $i => $ticket ) {
        if ( isset( $ticket['status'], $ticket['details']['error'], $to[ $i ] )
            && 'error' === $ticket['status']
            && 'DeviceNotRegistered' === $ticket['details']['error'] ) {
            $wpdb->delete( $wpdb->prefix . 'kounselia_push_tokens', array( 'token' => $to[ $i ] ) );
        }
    }

    return true;
}

function kounselia_is_expo_push_token( $token ) {
    return (bool) preg_match( '/^Expo(nent)?PushToken\[[^\]]+\]$/', (string) $token );
}

/**
 * A phone registering (or re-registering, e.g. after a token refresh)
 * for push notifications. Called by the app when the member allows
 * notifications, and on each launch after that.
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
    // One phone, one person: if someone else was signed in on this phone
    // before, they stop getting this phone's notifications.
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}kounselia_push_tokens WHERE token = %s AND user_id <> %d",
        $token,
        get_current_user_id()
    ) );
    // Tied to this phone's sign-in, so it ends when the sign-in does.
    $app_token_id = function_exists( 'kounselia_app_token_id_from_request' ) ? kounselia_app_token_id_from_request() : 0;
    $wpdb->query( $wpdb->prepare(
        "INSERT INTO {$wpdb->prefix}kounselia_push_tokens (user_id, app_token_id, platform, token, created_at)
         VALUES (%d, NULLIF(%d, 0), %s, %s, %s)
         ON DUPLICATE KEY UPDATE app_token_id = VALUES(app_token_id), platform = VALUES(platform), created_at = VALUES(created_at)",
        get_current_user_id(),
        $app_token_id,
        $platform,
        $token,
        current_time( 'mysql' )
    ) );

    wp_send_json_success( array( 'message' => 'Registered.' ) );
}
add_action( 'wp_ajax_kounselia_register_push_token', 'kounselia_ajax_register_push_token' );

/**
 * The member turned notifications off in the app's Settings: this phone
 * stops getting them. (Signing out does the same, in app-auth.php.)
 */
function kounselia_ajax_unregister_push_token() {
    kounselia_verify_nonce();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.' ), 401 );
    }

    $token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
    if ( '' !== $token ) {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'kounselia_push_tokens', array(
            'user_id' => get_current_user_id(),
            'token'   => $token,
        ) );
    }

    wp_send_json_success( array( 'message' => 'Unregistered.' ) );
}
add_action( 'wp_ajax_kounselia_unregister_push_token', 'kounselia_ajax_unregister_push_token' );

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

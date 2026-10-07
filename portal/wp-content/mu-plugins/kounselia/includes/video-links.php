<?php
/**
 * Kounselia Core — a professional's own video link (Zoom, Google Meet,
 * Microsoft Teams or Whereby) instead of Kounselia's video room.
 *
 * Only the video call moves. Booking, payment, messages, reminders and
 * reviews all stay on Kounselia, and so does the way in: everyone still
 * presses "Join" on Kounselia (video-call.php on the website,
 * kounselia_get_booking_room in the app), which checks it's one of the
 * two people on the booking and that it's time, and only then hands over
 * the link. The link never goes into an email.
 *
 * Rules:
 *   - An admin allows it per professional (video_link_allowed). Turning
 *     that off sends every session back to Kounselia's room at once.
 *   - The professional chooses Kounselia's room (the default) or their
 *     own link (video_mode = 'own', video_link), and can set a different
 *     link for one session (kounselia_bookings.video_link), which is
 *     safer than one personal room reused for everyone.
 *   - Only https links on a short list of video services are accepted.
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** host suffix => service name. */
function kounselia_video_providers() {
    return array(
        'zoom.us'              => 'Zoom',
        'zoom.com'             => 'Zoom',
        'meet.google.com'      => 'Google Meet',
        'teams.microsoft.com'  => 'Microsoft Teams',
        'teams.live.com'       => 'Microsoft Teams',
        'whereby.com'          => 'Whereby',
    );
}

/** The service a link belongs to, or '' if it isn't one we accept. */
function kounselia_video_provider_for( $url ) {
    $parts = wp_parse_url( trim( (string) $url ) );
    if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) || empty( $parts['host'] ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
        return '';
    }
    $host = strtolower( $parts['host'] );
    foreach ( kounselia_video_providers() as $suffix => $name ) {
        if ( $host === $suffix || substr( $host, -strlen( '.' . $suffix ) ) === '.' . $suffix ) {
            return $name;
        }
    }
    return '';
}

/**
 * A link cleaned for saving. '' clears it. Returns the link or WP_Error.
 */
function kounselia_video_clean_link( $url ) {
    $url = trim( (string) $url );
    if ( '' === $url ) {
        return '';
    }
    if ( ! preg_match( '#^https?://#i', $url ) ) {
        $url = 'https://' . $url;
    }
    $clean = esc_url_raw( $url, array( 'https' ) );
    if ( ! $clean || strlen( $clean ) > 500 || ! kounselia_video_provider_for( $clean ) ) {
        return new WP_Error( 'bad_link', 'Please paste a meeting link from Zoom, Google Meet, Microsoft Teams or Whereby (starting with https://).' );
    }
    return $clean;
}

/** Whether this professional may use their own link at all. */
function kounselia_video_own_allowed( $pro ) {
    return $pro && ! empty( $pro->video_link_allowed );
}

/**
 * Where a booked session happens.
 *
 * @return array{external:bool, provider:string, url:string} provider is
 *         'Kounselia' for our own room (url is then '').
 */
function kounselia_booking_video( $booking, $pro = null ) {
    $pro  = $pro ? $pro : kounselia_get_professional_by_id( $booking->professional_id );
    $room = array( 'external' => false, 'provider' => 'Kounselia', 'url' => '' );
    if ( ! kounselia_video_own_allowed( $pro ) ) {
        return $room;
    }
    $link = '';
    if ( ! empty( $booking->video_link ) ) {
        $link = $booking->video_link;
    } elseif ( 'own' === $pro->video_mode && ! empty( $pro->video_link ) ) {
        $link = $pro->video_link;
    }
    $provider = $link ? kounselia_video_provider_for( $link ) : '';
    return $provider ? array( 'external' => true, 'provider' => $provider, 'url' => $link ) : $room;
}

/**
 * What a member is told before booking: '' for Kounselia's room, or the
 * service when the professional's sessions happen elsewhere.
 */
function kounselia_professional_video_provider( $pro ) {
    if ( ! kounselia_video_own_allowed( $pro ) || 'own' !== $pro->video_mode || empty( $pro->video_link ) ) {
        return '';
    }
    return kounselia_video_provider_for( $pro->video_link );
}

/* -------------------------------------------------------------------------
 * PROFESSIONAL AJAX
 * ---------------------------------------------------------------------- */

/** Kounselia's room or their own link, for all their sessions. */
function kounselia_ajax_pro_video_settings() {
    kounselia_verify_nonce();
    $pro = is_user_logged_in() ? kounselia_get_professional_application( get_current_user_id() ) : null;
    if ( ! $pro ) {
        wp_send_json_error( array( 'message' => 'Only professionals can do this.' ), 403 );
    }
    if ( ! kounselia_video_own_allowed( $pro ) ) {
        wp_send_json_error( array( 'message' => 'Using your own video link is not switched on for your account. Please ask the Kounselia team.' ), 403 );
    }
    $mode = isset( $_POST['mode'] ) && 'own' === $_POST['mode'] ? 'own' : 'kounselia';
    $link = kounselia_video_clean_link( isset( $_POST['link'] ) ? wp_unslash( $_POST['link'] ) : '' );
    if ( is_wp_error( $link ) ) {
        wp_send_json_error( array( 'message' => $link->get_error_message() ), 400 );
    }
    if ( 'own' === $mode && '' === $link ) {
        wp_send_json_error( array( 'message' => 'Please paste your meeting link, or choose Kounselia\'s video room.' ), 400 );
    }
    global $wpdb;
    $wpdb->update( $wpdb->prefix . 'kounselia_professionals', array(
        'video_mode' => $mode,
        'video_link' => '' !== $link ? $link : null,
        'updated_at' => current_time( 'mysql' ),
    ), array( 'id' => $pro->id ) );
    wp_send_json_success( array(
        'message' => 'own' === $mode ? 'Saved. Your sessions will use your ' . kounselia_video_provider_for( $link ) . ' link.' : 'Saved. Your sessions will use Kounselia\'s private video room.',
    ) );
}
add_action( 'wp_ajax_kounselia_pro_video_settings', 'kounselia_ajax_pro_video_settings' );

/** A different link for one session ('' goes back to their usual setting). */
function kounselia_ajax_pro_booking_video_link() {
    kounselia_verify_nonce();
    $user_id = get_current_user_id();
    $pro     = $user_id ? kounselia_get_professional_application( $user_id ) : null;
    $booking = $pro && isset( $_POST['booking_id'] ) ? kounselia_get_booking_with_parties( absint( $_POST['booking_id'] ) ) : null;
    if ( ! $booking || (int) $booking->professional_id !== (int) $pro->id ) {
        wp_send_json_error( array( 'message' => 'That session was not found.' ), 404 );
    }
    if ( ! kounselia_video_own_allowed( $pro ) ) {
        wp_send_json_error( array( 'message' => 'Using your own video link is not switched on for your account.' ), 403 );
    }
    if ( 'confirmed' !== $booking->status ) {
        wp_send_json_error( array( 'message' => 'This session is no longer active.' ), 400 );
    }
    $link = kounselia_video_clean_link( isset( $_POST['link'] ) ? wp_unslash( $_POST['link'] ) : '' );
    if ( is_wp_error( $link ) ) {
        wp_send_json_error( array( 'message' => $link->get_error_message() ), 400 );
    }
    global $wpdb;
    $wpdb->update( $wpdb->prefix . 'kounselia_bookings', array( 'video_link' => '' !== $link ? $link : null, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $booking->id ) );

    $now_video = kounselia_booking_video( kounselia_get_booking_with_parties( $booking->id ), $pro );
    if ( function_exists( 'kounselia_notify_user' ) ) {
        // The client hears where the session is now; the link itself stays behind Join.
        $lang = kounselia_mail_lang( (int) $booking->client_user_id );
        kounselia_notify_user(
            (int) $booking->client_user_id,
            'booking_video_changed',
            kounselia_t( 'mail.video.changed_title', array( 'provider' => $now_video['provider'] ), $lang ),
            kounselia_t( 'mail.video.changed_body', array( 'when' => kounselia_mail_datetime( strtotime( $booking->scheduled_start ), $lang, true ) ), $lang ),
            '/dashboard.php#professionals'
        );
    }
    wp_send_json_success( array( 'message' => '' !== $link ? 'This session will use your ' . $now_video['provider'] . ' link. Your client has been told.' : 'This session is back to your usual video setting.' ) );
}
add_action( 'wp_ajax_kounselia_pro_booking_video_link', 'kounselia_ajax_pro_booking_video_link' );

/* -------------------------------------------------------------------------
 * ADMIN AJAX (Admin → Professionals → Manage)
 * ---------------------------------------------------------------------- */

function kounselia_ajax_admin_professional_video() {
    kounselia_content_admin_guard( 'professionals' );
    $pro = kounselia_get_professional_by_id( (int) kounselia_post_field( 'professional_id', 0 ) );
    if ( ! $pro ) {
        kounselia_send_pure_json_error( array( 'message' => 'That professional no longer exists.' ), 404 );
    }
    $allow = '1' === (string) kounselia_post_field( 'allow', '0' );
    global $wpdb;
    $wpdb->update( $wpdb->prefix . 'kounselia_professionals', array( 'video_link_allowed' => $allow ? 1 : 0, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $pro->id ) );
    kounselia_admin_log( $allow ? 'allowed_own_video' : 'stopped_own_video', 'professional', (int) $pro->id );
    if ( function_exists( 'kounselia_notify_user' ) ) {
        $lang = kounselia_mail_lang( (int) $pro->user_id );
        kounselia_notify_user(
            (int) $pro->user_id,
            $allow ? 'own_video_allowed' : 'own_video_stopped',
            $allow ? kounselia_t( 'mail.video.allowed_title', array(), $lang ) : kounselia_t( 'mail.video.stopped_title', array(), $lang ),
            $allow ? kounselia_t( 'mail.video.allowed_body', array(), $lang ) : kounselia_t( 'mail.video.stopped_body', array(), $lang ),
            '/pro-dashboard.php?tab=profile'
        );
    }
    kounselia_send_pure_json_success( array( 'message' => $allow ? 'Allowed. They can now set their own video link.' : 'Stopped. Their sessions use Kounselia\'s video room again.' ) );
}
add_action( 'wp_ajax_kounselia_admin_professional_video', 'kounselia_ajax_admin_professional_video' );

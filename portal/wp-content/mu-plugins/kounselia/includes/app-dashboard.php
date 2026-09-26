<?php
/**
 * Kounselia Core — the dashboard, as data for the mobile app.
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 *
 * The website builds its dashboard (dashboard.php) straight into the page.
 * The app can't read a page, so these actions hand it the same
 * information, built with the same helper functions so both always agree.
 * Saving (mood, journal) and booking actions already exist elsewhere and
 * are used by the app as they are.
 *
 * Times are sent twice where the app needs them: `*_local` in the site's
 * own time (the form the booking actions expect back) and `*_utc` so the
 * app can show them in the member's time zone.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A time stored in the site's local time, as UTC ISO 8601 (or null).
 */
function kounselia_app_utc( $local_mysql ) {
    if ( empty( $local_mysql ) || '0000-00-00 00:00:00' === $local_mysql ) {
        return null;
    }
    return get_gmt_from_date( $local_mysql, 'Y-m-d\TH:i:s\Z' );
}

/**
 * Which counselor to suggest, and why. Shared with dashboard.php so the
 * website and the app always suggest the same person.
 *
 * @param string|null $today_mood  Today's saved mood key, if any.
 * @param string[]    $active_slugs Counselors a member can talk to.
 * @param string[]    $tried_slugs  Counselors they've talked to, most recent first.
 * @return array{0:string,1:string} Slug and reason.
 */
function kounselia_recommended_counselor( $today_mood, $active_slugs, $tried_slugs ) {
    $mood_map  = function_exists( 'kounselia_mood_counselor_map' ) ? kounselia_mood_counselor_map() : array();
    $not_tried = array_values( array_diff( $active_slugs, $tried_slugs ) );

    if ( $today_mood && isset( $mood_map[ $today_mood ] ) && in_array( $mood_map[ $today_mood ], $active_slugs, true ) ) {
        return array( $mood_map[ $today_mood ], "Matched to how you said you're feeling today." );
    }
    if ( ! empty( $not_tried ) ) {
        return array( $not_tried[0], "Someone you haven't talked to yet." );
    }
    if ( ! empty( $tried_slugs ) ) {
        return array( $tried_slugs[0], 'Pick up where you left off.' );
    }
    return array( ! empty( $active_slugs ) ? $active_slugs[0] : 'serena', 'A good place to start.' );
}

function kounselia_app_require_member() {
    kounselia_verify_nonce();
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in again.', 'signed_out' => true ), 401 );
    }
    return get_current_user_id();
}

/* -------------------------------------------------------------------------
 * HOME: mood, stats, recommendation, today's journal
 * ---------------------------------------------------------------------- */

function kounselia_ajax_app_home() {
    $user_id = kounselia_app_require_member();

    $options = array();
    foreach ( kounselia_mood_options() as $key => $m ) {
        $options[] = array(
            'key'   => $key,
            'label' => $m['label'],
            'icon'  => preg_replace( '/^ti-/', '', $m['icon'] ),
            'color' => preg_replace( '/^ic-/', '', $m['class'] ),
        );
    }

    $today_mood   = kounselia_get_today_mood( $user_id );
    $active_slugs = wp_list_pluck( kounselia_public_counselors(), 'slug' );
    $tried_slugs  = array_values( array_unique( wp_list_pluck( kounselia_get_recent_sessions( $user_id, 5 ), 'counselor_slug' ) ) );
    list( $slug, $reason ) = kounselia_recommended_counselor( $today_mood, $active_slugs, $tried_slugs );
    $stats = kounselia_get_dashboard_stats( $user_id );

    wp_send_json_success( array(
        'mood'        => array(
            'options' => $options,
            'today'   => $today_mood ? $today_mood : null,
            'week'    => kounselia_get_recent_moods( $user_id, 7 ),
        ),
        'stats'       => array(
            'conversations'      => (int) $stats['total_sessions'],
            'messages_this_week' => (int) $stats['messages_this_week'],
            'counselors_met'     => (int) $stats['counselors_met'],
        ),
        'recommended' => array( 'slug' => $slug, 'reason' => $reason ),
        'journal'     => (string) kounselia_get_today_journal( $user_id ),
    ) );
}
add_action( 'wp_ajax_kounselia_app_home', 'kounselia_ajax_app_home' );
add_action( 'wp_ajax_nopriv_kounselia_app_home', 'kounselia_ajax_app_home' );

/* -------------------------------------------------------------------------
 * SESSIONS: recent conversations with counselors
 * ---------------------------------------------------------------------- */

function kounselia_ajax_get_sessions() {
    $user_id = kounselia_app_require_member();

    $sessions = array();
    foreach ( kounselia_get_recent_sessions( $user_id, 30 ) as $s ) {
        $sessions[] = array(
            'id'             => (int) $s->id,
            'counselor_slug' => $s->counselor_slug,
            'message_count'  => (int) $s->message_count,
            'last_at'        => kounselia_app_utc( $s->last_message_at ? $s->last_message_at : $s->started_at ),
        );
    }
    wp_send_json_success( array( 'sessions' => $sessions ) );
}
add_action( 'wp_ajax_kounselia_get_sessions', 'kounselia_ajax_get_sessions' );
add_action( 'wp_ajax_nopriv_kounselia_get_sessions', 'kounselia_ajax_get_sessions' );

/* -------------------------------------------------------------------------
 * BOOKINGS: upcoming and past sessions with professionals, and who can be
 * booked (with the price the dashboard would show this member)
 * ---------------------------------------------------------------------- */

function kounselia_ajax_app_bookings() {
    $user_id = kounselia_app_require_member();

    $upcoming = array();
    foreach ( kounselia_get_client_bookings( $user_id ) as $b ) {
        $upcoming[] = array(
            'id'              => (int) $b->id,
            'professional_id' => (int) $b->professional_id,
            'pro_name'        => $b->pro_name,
            'pro_title'       => (string) $b->pro_title,
            'start_local'     => $b->scheduled_start,
            'start_utc'       => kounselia_app_utc( $b->scheduled_start ),
            'series_id'       => (int) $b->series_id,
            'joinable'        => kounselia_booking_is_joinable( $b ),
        );
    }

    $past = array();
    foreach ( kounselia_get_client_past_bookings( $user_id ) as $b ) {
        $past[] = array(
            'id'            => (int) $b->id,
            'pro_name'      => $b->pro_name,
            'pro_title'     => (string) $b->pro_title,
            'start_utc'     => kounselia_app_utc( $b->scheduled_start ),
            'review_rating' => $b->review_id ? (int) $b->review_rating : null,
        );
    }

    $currency = function_exists( 'kounselia_viewer_currency' ) ? kounselia_viewer_currency() : 'NGN';
    $discount = function_exists( 'kounselia_member_session_price' ) && function_exists( 'kounselia_booking_commission_percent' )
        ? (float) kounselia_member_session_price( $user_id, 100, kounselia_booking_commission_percent() )['discount_percent']
        : 0;

    $professionals = array();
    foreach ( kounselia_get_verified_professionals() as $pro ) {
        $rating = function_exists( 'kounselia_get_professional_rating_summary' )
            ? kounselia_get_professional_rating_summary( $pro->id )
            : array( 'average' => 0, 'count' => 0 );
        $price = null;
        $full  = null;
        if ( $pro->rate_amount ) {
            $full_amount  = kounselia_convert_ngn( $pro->rate_amount, $currency );
            $price_amount = round( $full_amount * ( 1 - $discount / 100 ), 2 );
            $price        = kounselia_format_money( $price_amount, $currency );
            $full         = $price_amount < $full_amount ? kounselia_format_money( $full_amount, $currency ) : null;
        }
        $avatar          = kounselia_get_avatar_url( $pro->user_id, 'thumbnail' );
        $professionals[] = array(
            'id'           => (int) $pro->id,
            'name'         => $pro->display_name,
            'title'        => (string) $pro->title,
            'specialty'    => (string) $pro->specialty,
            'avatar_url'   => $avatar ? $avatar : null,
            'rating'       => (float) $rating['average'],
            'review_count' => (int) $rating['count'],
            'price'        => $price, // e.g. "₦15,000", already with any Pro discount
            'full_price'   => $full,  // the undiscounted price, only when a discount applies
        );
    }

    wp_send_json_success( array(
        'upcoming'        => $upcoming,
        'past'            => $past,
        'professionals'   => $professionals,
        'session_minutes' => kounselia_session_length_minutes(),
    ) );
}
add_action( 'wp_ajax_kounselia_app_bookings', 'kounselia_ajax_app_bookings' );
add_action( 'wp_ajax_nopriv_kounselia_app_bookings', 'kounselia_ajax_app_bookings' );

/* -------------------------------------------------------------------------
 * JOIN: the private video room for a booked session
 * ---------------------------------------------------------------------- */

/**
 * The website's video-call.php needs the website sign-in, which the app
 * doesn't have. This gives the app the same Jitsi room, under the same
 * rules: only one of the two people on the booking, and only inside the
 * joining window.
 */
function kounselia_ajax_get_booking_room() {
    $user_id    = kounselia_app_require_member();
    $booking_id = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0;
    $booking    = $booking_id ? kounselia_get_booking_with_parties( $booking_id ) : null;

    if ( ! $booking || ! kounselia_user_is_booking_party( $booking, $user_id ) ) {
        wp_send_json_error( array( 'message' => 'You do not have access to this session.' ), 404 );
    }
    if ( ! kounselia_booking_is_joinable( $booking ) ) {
        wp_send_json_error( array( 'message' => 'This room opens 10 minutes before your session.' ), 400 );
    }

    $user = wp_get_current_user();
    $name = $user->display_name ? $user->display_name : $user->user_login;
    $url  = 'https://meet.jit.si/kounselia-' . rawurlencode( $booking->room_token )
        . '#config.prejoinPageEnabled=true&config.disableDeepLinking=true'
        . '&userInfo.displayName=' . rawurlencode( wp_json_encode( $name ) );

    wp_send_json_success( array( 'url' => $url ) );
}
add_action( 'wp_ajax_kounselia_get_booking_room', 'kounselia_ajax_get_booking_room' );
add_action( 'wp_ajax_nopriv_kounselia_get_booking_room', 'kounselia_ajax_get_booking_room' );

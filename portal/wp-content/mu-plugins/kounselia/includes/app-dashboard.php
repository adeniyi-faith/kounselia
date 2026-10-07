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

/**
 * A member's confirmed sessions that haven't finished yet, soonest first.
 * kounselia_get_client_bookings() drops a session the moment it starts,
 * which would hide its Join button from anyone a minute late; this keeps
 * it until its joining window closes (15 minutes after the end).
 */
function kounselia_app_open_bookings( $user_id ) {
    $now = current_time( 'timestamp' );
    return array_values( array_filter( kounselia_get_client_bookings( $user_id, false ), function ( $b ) use ( $now ) {
        return strtotime( $b->scheduled_end ) + 15 * MINUTE_IN_SECONDS >= $now;
    } ) );
}

/**
 * "Your care team" on Home (inc/dashboard-care-team.php): the member's
 * next booked session with a professional, or, with none booked, up to
 * three professionals to invite them to book, plus their Pro discount.
 */
function kounselia_app_care_team( $user_id ) {
    $bookings = kounselia_app_open_bookings( $user_id );
    $next     = null;
    if ( ! empty( $bookings ) ) {
        $b    = $bookings[0];
        $next = array(
            'id'          => (int) $b->id,
            'pro_name'    => $b->pro_name,
            'pro_title'   => (string) $b->pro_title,
            'start_utc'   => kounselia_app_utc( $b->scheduled_start ),
            'joinable'    => kounselia_booking_is_joinable( $b ),
            'more_booked' => count( $bookings ) - 1,
        );
    }

    $faces = array();
    if ( ! $next ) {
        foreach ( array_slice( (array) kounselia_get_verified_professionals(), 0, 3 ) as $pro ) {
            $avatar  = kounselia_get_avatar_url( $pro->user_id, 'thumbnail' );
            $faces[] = array( 'name' => $pro->display_name, 'avatar_url' => $avatar ? $avatar : null );
        }
    }

    $discount = function_exists( 'kounselia_member_session_price' ) && function_exists( 'kounselia_booking_commission_percent' )
        ? (float) kounselia_member_session_price( $user_id, 100, kounselia_booking_commission_percent() )['discount_percent']
        : 0;

    return array(
        'next'             => $next,
        'professionals'    => $faces,
        'discount_percent' => $discount,
    );
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

    // "<Counselor> wants to check in": something the member mentioned
    // was coming up, dated today or yesterday. Asked by the counselor
    // they last talked to, as on the website.
    $checkin = null;
    $event   = function_exists( 'kounselia_get_next_checkin' ) ? kounselia_get_next_checkin( $user_id ) : null;
    if ( $event ) {
        $checkin = array(
            'id'             => (int) $event->id,
            'event_text'     => $event->event_text,
            // Someone switched off since can't ask; fall back to the
            // suggested counselor, as the website does.
            'counselor_slug' => ( ! empty( $tried_slugs ) && in_array( $tried_slugs[0], $active_slugs, true ) ) ? $tried_slugs[0] : $slug,
        );
    }

    wp_send_json_success( array(
        'checkin'     => $checkin,
        'language'    => function_exists( 'kounselia_current_language' ) ? kounselia_current_language( $user_id ) : 'en',
        // Their running growth plan (today's task and progress), or null.
        'growth'      => function_exists( 'kounselia_growth_home_summary' ) ? kounselia_growth_home_summary( $user_id ) : null,
        'care'        => kounselia_app_care_team( $user_id ),
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
    foreach ( kounselia_app_open_bookings( $user_id ) as $b ) {
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
        $price     = null;
        $full      = null;
        $free_left = function_exists( 'kounselia_free_sessions_left' ) ? kounselia_free_sessions_left( $pro, $user_id ) : 0;
        $all_free  = defined( 'KOUNSELIA_FREE_ALWAYS' ) && $free_left >= KOUNSELIA_FREE_ALWAYS;
        if ( $pro->rate_amount && ! $all_free ) {
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
            'bio'          => $pro->bio ? wp_trim_words( wp_strip_all_tags( $pro->bio ), 60 ) : null,
            'avatar_url'   => $avatar ? $avatar : null,
            'rating'       => (float) $rating['average'],
            'review_count' => (int) $rating['count'],
            'price'        => $price, // e.g. "₦15,000", already with any Pro discount
            'full_price'   => $full,  // the undiscounted price, only when a discount applies
            // e.g. "Your next session is free"; null when there's nothing free left.
            'free_label'   => $free_left && function_exists( 'kounselia_free_sessions_label' ) ? kounselia_free_sessions_label( $pro, $user_id ) : null,
            // "Zoom", "Google Meet"... when their sessions aren't in Kounselia's own room.
            'video_provider' => function_exists( 'kounselia_professional_video_provider' ) ? ( kounselia_professional_video_provider( $pro ) ?: null ) : null,
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

/**
 * What clients said about a professional, for the app's "Book a session"
 * screen: the star average, how many ratings, and the most recent written
 * reviews. Anonymous, like the website's profile page: no names, only the
 * stars, the words and when (kounselia_public_professional_reviews()).
 */
function kounselia_ajax_app_professional_reviews() {
    kounselia_verify_nonce();

    $pro_id = isset( $_POST['professional_id'] ) ? (int) $_POST['professional_id'] : 0;
    $pro    = $pro_id ? kounselia_get_professional_by_id( $pro_id ) : null;
    if ( ! $pro || 'verified' !== $pro->status || ! empty( $pro->admin_hidden ) ) {
        wp_send_json_error( array( 'message' => 'This professional isn’t available.' ), 404 );
    }

    $summary = function_exists( 'kounselia_get_professional_rating_summary' )
        ? kounselia_get_professional_rating_summary( $pro_id )
        : array( 'average' => 0, 'count' => 0 );
    $reviews = array();
    if ( function_exists( 'kounselia_public_professional_reviews' ) ) {
        foreach ( kounselia_public_professional_reviews( $pro_id, 10 ) as $r ) {
            $reviews[] = array(
                'rating'   => (int) $r['rating'],
                'comment'  => wp_strip_all_tags( (string) $r['comment'] ),
                'date_utc' => kounselia_app_utc( $r['date'] ),
            );
        }
    }

    wp_send_json_success( array(
        'average' => (float) $summary['average'],
        'count'   => (int) $summary['count'],
        'reviews' => $reviews,
    ) );
}
add_action( 'wp_ajax_kounselia_app_professional_reviews', 'kounselia_ajax_app_professional_reviews' );
add_action( 'wp_ajax_nopriv_kounselia_app_professional_reviews', 'kounselia_ajax_app_professional_reviews' );

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

    // The professional's own Zoom / Meet / Teams / Whereby link, if they
    // use one (video-links.php). Same rules got us here either way.
    $video = function_exists( 'kounselia_booking_video' ) ? kounselia_booking_video( $booking ) : array( 'external' => false );
    if ( $video['external'] ) {
        wp_send_json_success( array( 'url' => $video['url'], 'external' => true, 'provider' => $video['provider'] ) );
    }

    // 8x8's hosted Jitsi with a signed pass when it's set up, so nobody
    // waits for a "moderator" or is asked to log in (video-room.php).
    $room = kounselia_booking_room( $booking, wp_get_current_user() );

    wp_send_json_success( array( 'url' => $room['url'], 'external' => false, 'provider' => 'Kounselia' ) );
}
add_action( 'wp_ajax_kounselia_get_booking_room', 'kounselia_ajax_get_booking_room' );
add_action( 'wp_ajax_nopriv_kounselia_get_booking_room', 'kounselia_ajax_get_booking_room' );

/* -------------------------------------------------------------------------
 * PAYMENT CHECK: has Paystack confirmed a booking yet?
 * ---------------------------------------------------------------------- */

/**
 * Asked by the app every few seconds while the member pays, so it can say
 * "You're booked" as soon as Paystack confirms, without reloading the
 * whole Book screen each time.
 */
function kounselia_ajax_get_booking_status() {
    $user_id    = kounselia_app_require_member();
    $booking_id = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0;
    $booking    = $booking_id ? kounselia_get_booking_with_parties( $booking_id ) : null;

    if ( ! $booking || (int) $booking->client_user_id !== (int) $user_id ) {
        wp_send_json_error( array( 'message' => 'Booking not found.' ), 404 );
    }
    wp_send_json_success( array( 'status' => $booking->status ) );
}
add_action( 'wp_ajax_kounselia_get_booking_status', 'kounselia_ajax_get_booking_status' );
add_action( 'wp_ajax_nopriv_kounselia_get_booking_status', 'kounselia_ajax_get_booking_status' );

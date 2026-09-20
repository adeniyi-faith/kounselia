<?php
/**
 * Kounselia Core — booking calendar: a professional's recurring weekly
 * availability, expanded into concrete open slots, and the confirmed
 * sessions clients book against them.
 *
 * There is no "pending" booking state: a slot is only ever offered if it's
 * inside the professional's own availability window and not already taken,
 * so booking it confirms it immediately. Cancelling (by either side) just
 * frees the slot back up — it goes on generating from the same weekly
 * rules, nothing to re-approve.
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Every booking is this long. Keeping it fixed platform-wide (rather than
 * a per-professional setting) is what keeps slot generation a simple
 * fixed-width walk across each availability window, with no overlap math.
 */
function kounselia_session_length_minutes() {
    return 60;
}

/**
 * How far in advance a slot must start to be bookable at all — stops
 * someone booking a session for eleven minutes from now.
 */
function kounselia_booking_lead_seconds() {
    return 2 * HOUR_IN_SECONDS;
}

/**
 * How many days out slots are generated for.
 */
function kounselia_booking_horizon_days() {
    return 21;
}

/* -------------------------------------------------------------------------
 * AVAILABILITY
 * ---------------------------------------------------------------------- */

function kounselia_get_availability_rules( $professional_id ) {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}kounselia_professional_availability WHERE professional_id = %d ORDER BY day_of_week ASC, start_time ASC",
        $professional_id
    ) );
}

/**
 * Replace a professional's whole weekly schedule with the given rules.
 * $rules is an array of ['day' => 0-6, 'start' => 'HH:MM', 'end' => 'HH:MM'].
 * Anything malformed is silently skipped rather than rejecting the whole
 * save — a typo in one row shouldn't cost the other six.
 */
function kounselia_save_availability_rules( $professional_id, $rules ) {
    global $wpdb;
    $table = $wpdb->prefix . 'kounselia_professional_availability';
    $wpdb->delete( $table, array( 'professional_id' => $professional_id ) );

    if ( ! is_array( $rules ) ) {
        return;
    }

    $now = current_time( 'mysql' );
    foreach ( $rules as $rule ) {
        $day   = isset( $rule['day'] ) ? (int) $rule['day'] : -1;
        $start = isset( $rule['start'] ) ? trim( (string) $rule['start'] ) : '';
        $end   = isset( $rule['end'] ) ? trim( (string) $rule['end'] ) : '';

        if ( $day < 0 || $day > 6 ) {
            continue;
        }
        if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $start ) || ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $end ) ) {
            continue;
        }
        if ( $start >= $end ) {
            continue;
        }

        $wpdb->insert( $table, array(
            'professional_id' => $professional_id,
            'day_of_week'     => $day,
            'start_time'      => $start . ':00',
            'end_time'        => $end . ':00',
            'created_at'      => $now,
        ) );
    }
}

/* -------------------------------------------------------------------------
 * SLOTS
 * ---------------------------------------------------------------------- */

function kounselia_get_booked_slot_starts( $professional_id ) {
    global $wpdb;
    $rows = $wpdb->get_col( $wpdb->prepare(
        "SELECT scheduled_start FROM {$wpdb->prefix}kounselia_bookings WHERE professional_id = %d AND status = 'confirmed' AND scheduled_start >= %s",
        $professional_id,
        current_time( 'mysql' )
    ) );
    return array_flip( $rows );
}

/**
 * Expand a professional's weekly rules into concrete open slots (as
 * 'Y-m-d H:i:s' strings) over the booking horizon, skipping anything
 * already booked or too soon to book.
 */
function kounselia_get_available_slots( $professional_id ) {
    $rules = kounselia_get_availability_rules( $professional_id );
    if ( empty( $rules ) ) {
        return array();
    }

    $length_seconds = kounselia_session_length_minutes() * 60;
    $now            = current_time( 'timestamp' );
    $earliest       = $now + kounselia_booking_lead_seconds();
    $days_ahead     = kounselia_booking_horizon_days();

    $by_day = array();
    foreach ( $rules as $rule ) {
        $by_day[ (int) $rule->day_of_week ][] = $rule;
    }

    $booked = kounselia_get_booked_slot_starts( $professional_id );

    $slots = array();
    for ( $d = 0; $d <= $days_ahead; $d++ ) {
        $day_ts = strtotime( "+{$d} days", $now );
        $dow    = (int) date( 'w', $day_ts );
        if ( empty( $by_day[ $dow ] ) ) {
            continue;
        }
        $date_str = date( 'Y-m-d', $day_ts );

        foreach ( $by_day[ $dow ] as $rule ) {
            $window_start = strtotime( "{$date_str} {$rule->start_time}" );
            $window_end   = strtotime( "{$date_str} {$rule->end_time}" );

            for ( $slot_ts = $window_start; ( $slot_ts + $length_seconds ) <= $window_end; $slot_ts += $length_seconds ) {
                if ( $slot_ts < $earliest ) {
                    continue;
                }
                $slot_mysql = date( 'Y-m-d H:i:s', $slot_ts );
                if ( isset( $booked[ $slot_mysql ] ) ) {
                    continue;
                }
                $slots[] = $slot_mysql;
            }
        }
    }

    sort( $slots );
    return $slots;
}

/* -------------------------------------------------------------------------
 * BOOKINGS
 * ---------------------------------------------------------------------- */

function kounselia_get_verified_professionals() {
    global $wpdb;
    return $wpdb->get_results(
        "SELECT p.*, u.display_name, u.ID AS user_id
         FROM {$wpdb->prefix}kounselia_professionals p
         INNER JOIN {$wpdb->users} u ON u.ID = p.user_id
         WHERE p.status = 'verified'
         ORDER BY u.display_name ASC"
    );
}

function kounselia_get_professional_by_id( $professional_id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT p.*, u.display_name, u.ID AS user_id
         FROM {$wpdb->prefix}kounselia_professionals p
         INNER JOIN {$wpdb->users} u ON u.ID = p.user_id
         WHERE p.id = %d",
        $professional_id
    ) );
}

/**
 * Book a slot on behalf of a client. Re-validates against the live slot
 * list rather than trusting the client's submitted time, so a slot that
 * was taken or dropped from availability a second ago can't be booked.
 */
function kounselia_create_booking( $professional_id, $client_user_id, $scheduled_start_mysql, $note ) {
    global $wpdb;

    $professional = kounselia_get_professional_by_id( $professional_id );
    if ( ! $professional || 'verified' !== $professional->status ) {
        return new WP_Error( 'not_available', 'That professional is not currently taking bookings.' );
    }
    if ( (int) $professional->user_id === (int) $client_user_id ) {
        return new WP_Error( 'self_booking', 'You cannot book a session with yourself.' );
    }

    $start_ts = strtotime( $scheduled_start_mysql );
    if ( ! $start_ts ) {
        return new WP_Error( 'invalid_slot', 'That time is no longer available.' );
    }
    $normalized_start = date( 'Y-m-d H:i:s', $start_ts );

    $valid_slots = kounselia_get_available_slots( $professional_id );
    if ( ! in_array( $normalized_start, $valid_slots, true ) ) {
        return new WP_Error( 'invalid_slot', 'That time is no longer available. Please pick another slot.' );
    }

    // Last-moment race check: two people clicking the same slot in the
    // same instant. Not a hard DB constraint, but closes the gap enough
    // for real-world traffic on a booking flow like this one.
    $already_taken = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_bookings WHERE professional_id = %d AND scheduled_start = %s AND status = 'confirmed'",
        $professional_id,
        $normalized_start
    ) );
    if ( $already_taken > 0 ) {
        return new WP_Error( 'invalid_slot', 'That time was just booked by someone else. Please pick another slot.' );
    }

    $end_ts = $start_ts + ( kounselia_session_length_minutes() * 60 );
    $now    = current_time( 'mysql' );

    $wpdb->insert( $wpdb->prefix . 'kounselia_bookings', array(
        'professional_id' => $professional_id,
        'client_user_id'  => $client_user_id,
        'scheduled_start' => $normalized_start,
        'scheduled_end'   => date( 'Y-m-d H:i:s', $end_ts ),
        'status'          => 'confirmed',
        'client_note'     => $note ? substr( $note, 0, 500 ) : null,
        'room_token'      => wp_generate_password( 40, false ),
        'created_at'      => $now,
        'updated_at'      => $now,
    ) );

    $booking_id = (int) $wpdb->insert_id;
    kounselia_notify_booking_created( $booking_id );

    return $booking_id;
}

function kounselia_get_professional_bookings( $professional_id, $upcoming_only = true ) {
    global $wpdb;
    $sql  = "SELECT b.*, u.display_name AS client_name, u.user_email AS client_email
             FROM {$wpdb->prefix}kounselia_bookings b
             LEFT JOIN {$wpdb->users} u ON u.ID = b.client_user_id
             WHERE b.professional_id = %d AND b.status = 'confirmed'";
    $args = array( $professional_id );
    if ( $upcoming_only ) {
        $sql   .= ' AND b.scheduled_start >= %s';
        $args[] = current_time( 'mysql' );
    }
    $sql .= ' ORDER BY b.scheduled_start ASC';

    return $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
}

function kounselia_get_client_bookings( $client_user_id, $upcoming_only = true ) {
    global $wpdb;
    $sql  = "SELECT b.*, p.title AS pro_title, p.specialty AS pro_specialty, u.display_name AS pro_name
             FROM {$wpdb->prefix}kounselia_bookings b
             INNER JOIN {$wpdb->prefix}kounselia_professionals p ON p.id = b.professional_id
             INNER JOIN {$wpdb->users} u ON u.ID = p.user_id
             WHERE b.client_user_id = %d AND b.status = 'confirmed'";
    $args = array( $client_user_id );
    if ( $upcoming_only ) {
        $sql   .= ' AND b.scheduled_start >= %s';
        $args[] = current_time( 'mysql' );
    }
    $sql .= ' ORDER BY b.scheduled_start ASC';

    return $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
}

/**
 * A booking joined with the professional's user_id, so callers can check
 * "is this person one of the two people on this booking" without
 * repeating the join everywhere that check is needed.
 */
function kounselia_get_booking_with_parties( $booking_id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT b.*, p.user_id AS professional_user_id
         FROM {$wpdb->prefix}kounselia_bookings b
         INNER JOIN {$wpdb->prefix}kounselia_professionals p ON p.id = b.professional_id
         WHERE b.id = %d",
        $booking_id
    ) );
}

function kounselia_user_is_booking_party( $booking, $user_id ) {
    if ( ! $booking || ! $user_id ) {
        return false;
    }
    return ( (int) $booking->client_user_id === (int) $user_id ) || ( (int) $booking->professional_user_id === (int) $user_id );
}

/**
 * Whether the video/voice room for a booking can be joined right now —
 * open a little before the scheduled time so people aren't locked out by
 * clock skew, closed well after it so a stale link doesn't stay live
 * indefinitely.
 */
function kounselia_booking_join_window( $booking ) {
    $opens_at  = strtotime( $booking->scheduled_start ) - ( 10 * MINUTE_IN_SECONDS );
    $closes_at = strtotime( $booking->scheduled_end ) + ( 15 * MINUTE_IN_SECONDS );
    return array( 'opens_at' => $opens_at, 'closes_at' => $closes_at );
}

function kounselia_booking_is_joinable( $booking ) {
    if ( ! $booking || 'confirmed' !== $booking->status ) {
        return false;
    }
    $window = kounselia_booking_join_window( $booking );
    $now    = current_time( 'timestamp' );
    return ( $now >= $window['opens_at'] && $now <= $window['closes_at'] );
}

/**
 * Cancel a booking. Either side of the appointment can do this — the
 * client, or the professional whose slot it was. Anyone else gets
 * rejected before any row is touched.
 */
function kounselia_cancel_booking( $booking_id, $acting_user_id, $reason = '' ) {
    global $wpdb;

    $booking = kounselia_get_booking_with_parties( $booking_id );

    if ( ! $booking ) {
        return new WP_Error( 'not_found', 'Booking not found.' );
    }
    if ( ! kounselia_user_is_booking_party( $booking, $acting_user_id ) ) {
        return new WP_Error( 'forbidden', 'You cannot cancel this booking.' );
    }
    if ( 'confirmed' !== $booking->status ) {
        return new WP_Error( 'invalid_state', 'This booking is no longer active.' );
    }

    $wpdb->update( $wpdb->prefix . 'kounselia_bookings', array(
        'status'        => 'cancelled',
        'cancelled_by'  => $acting_user_id,
        'cancel_reason' => $reason ? sanitize_textarea_field( $reason ) : null,
        'updated_at'    => current_time( 'mysql' ),
    ), array( 'id' => $booking_id ) );

    kounselia_notify_booking_cancelled( $booking_id, $acting_user_id );

    return true;
}

/* -------------------------------------------------------------------------
 * NOTIFICATIONS
 * ---------------------------------------------------------------------- */

function kounselia_notify_booking_created( $booking_id ) {
    if ( ! function_exists( 'kounselia_send_html_email' ) ) {
        return;
    }
    global $wpdb;
    $booking = $wpdb->get_row( $wpdb->prepare(
        "SELECT b.*, p.user_id AS professional_user_id, p.title AS pro_title
         FROM {$wpdb->prefix}kounselia_bookings b
         INNER JOIN {$wpdb->prefix}kounselia_professionals p ON p.id = b.professional_id
         WHERE b.id = %d",
        $booking_id
    ) );
    if ( ! $booking ) {
        return;
    }

    $professional_user = get_userdata( $booking->professional_user_id );
    $client_user        = get_userdata( $booking->client_user_id );
    $when                = date_i18n( 'l, F j, Y \a\t g:i A', strtotime( $booking->scheduled_start ) );

    if ( $professional_user ) {
        kounselia_send_html_email(
            $professional_user->user_email,
            'New session booked',
            'New booking',
            '<p>' . esc_html( $client_user ? $client_user->display_name : 'A client' ) . ' just booked a session with you for <strong>' . esc_html( $when ) . '</strong>.</p>',
            'View your bookings',
            rtrim( home_url(), '/' ) . '/pro-dashboard.php'
        );
    }
    if ( $client_user ) {
        kounselia_send_html_email(
            $client_user->user_email,
            'Your session is booked',
            'Booking confirmed',
            '<p>Your session is confirmed for <strong>' . esc_html( $when ) . '</strong>' . ( $professional_user ? ' with ' . esc_html( $professional_user->display_name ) : '' ) . '.</p>'
        );
    }
}

function kounselia_notify_booking_cancelled( $booking_id, $cancelled_by_user_id ) {
    if ( ! function_exists( 'kounselia_send_html_email' ) ) {
        return;
    }
    global $wpdb;
    $booking = $wpdb->get_row( $wpdb->prepare(
        "SELECT b.*, p.user_id AS professional_user_id
         FROM {$wpdb->prefix}kounselia_bookings b
         INNER JOIN {$wpdb->prefix}kounselia_professionals p ON p.id = b.professional_id
         WHERE b.id = %d",
        $booking_id
    ) );
    if ( ! $booking ) {
        return;
    }

    $when              = date_i18n( 'l, F j, Y \a\t g:i A', strtotime( $booking->scheduled_start ) );
    $cancelled_by_pro  = ( (int) $cancelled_by_user_id === (int) $booking->professional_user_id );
    $notify_user_id    = $cancelled_by_pro ? $booking->client_user_id : $booking->professional_user_id;
    $notify_user       = get_userdata( $notify_user_id );

    if ( $notify_user ) {
        kounselia_send_html_email(
            $notify_user->user_email,
            'A session was cancelled',
            'Booking cancelled',
            '<p>The session scheduled for <strong>' . esc_html( $when ) . '</strong> has been cancelled.</p>'
        );
    }
}

/* -------------------------------------------------------------------------
 * AJAX
 * ---------------------------------------------------------------------- */

function kounselia_ajax_save_availability() {
    kounselia_verify_nonce();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.' ), 401 );
    }

    $application = kounselia_get_professional_application( get_current_user_id() );
    if ( ! $application ) {
        wp_send_json_error( array( 'message' => 'You do not have a professional application on file.' ), 403 );
    }

    $raw   = isset( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : '[]';
    $rules = json_decode( $raw, true );
    if ( ! is_array( $rules ) ) {
        $rules = array();
    }
    if ( count( $rules ) > 30 ) {
        wp_send_json_error( array( 'message' => 'That is too many availability rules.' ), 400 );
    }

    kounselia_save_availability_rules( $application->id, $rules );
    wp_send_json_success( array( 'message' => 'Availability saved.' ) );
}
add_action( 'wp_ajax_kounselia_save_availability', 'kounselia_ajax_save_availability' );

function kounselia_ajax_get_professional_slots() {
    kounselia_verify_nonce();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.' ), 401 );
    }

    $professional_id = isset( $_POST['professional_id'] ) ? absint( $_POST['professional_id'] ) : 0;
    if ( ! $professional_id ) {
        wp_send_json_error( array( 'message' => 'Invalid request.' ), 400 );
    }

    $professional = kounselia_get_professional_by_id( $professional_id );
    if ( ! $professional || 'verified' !== $professional->status ) {
        wp_send_json_error( array( 'message' => 'That professional is not currently taking bookings.' ), 404 );
    }

    wp_send_json_success( array(
        'slots'           => kounselia_get_available_slots( $professional_id ),
        'session_minutes' => kounselia_session_length_minutes(),
    ) );
}
add_action( 'wp_ajax_kounselia_get_professional_slots', 'kounselia_ajax_get_professional_slots' );

function kounselia_ajax_create_booking() {
    kounselia_verify_nonce();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.' ), 401 );
    }
    if ( kounselia_rate_limited( 'create_booking', 10, 3600 ) ) {
        wp_send_json_error( array( 'message' => 'Too many attempts. Please try again later.' ), 429 );
    }

    $professional_id = isset( $_POST['professional_id'] ) ? absint( $_POST['professional_id'] ) : 0;
    $scheduled_start = isset( $_POST['scheduled_start'] ) ? sanitize_text_field( wp_unslash( $_POST['scheduled_start'] ) ) : '';
    $note            = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';

    if ( ! $professional_id || ! $scheduled_start ) {
        wp_send_json_error( array( 'message' => 'Please choose a time.' ), 400 );
    }

    $result = kounselia_create_booking( $professional_id, get_current_user_id(), $scheduled_start, $note );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }

    wp_send_json_success( array( 'message' => 'Session booked.', 'booking_id' => $result ) );
}
add_action( 'wp_ajax_kounselia_create_booking', 'kounselia_ajax_create_booking' );

function kounselia_ajax_cancel_booking() {
    kounselia_verify_nonce();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.' ), 401 );
    }

    $booking_id = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0;
    $reason     = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';

    if ( ! $booking_id ) {
        wp_send_json_error( array( 'message' => 'Invalid request.' ), 400 );
    }

    $result = kounselia_cancel_booking( $booking_id, get_current_user_id(), $reason );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }

    wp_send_json_success( array( 'message' => 'Booking cancelled.' ) );
}
add_action( 'wp_ajax_kounselia_cancel_booking', 'kounselia_ajax_cancel_booking' );

/* -------------------------------------------------------------------------
 * BOOKING MESSAGES — a private thread between the two people on one
 * booking. Not the AI counselor chat (kounselia_messages/kounselia_sessions)
 * — this is a person talking to another person about a specific session.
 * ---------------------------------------------------------------------- */

function kounselia_get_booking_messages( $booking_id ) {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT m.*, u.display_name AS sender_name
         FROM {$wpdb->prefix}kounselia_booking_messages m
         LEFT JOIN {$wpdb->users} u ON u.ID = m.sender_user_id
         WHERE m.booking_id = %d
         ORDER BY m.created_at ASC, m.id ASC",
        $booking_id
    ) );
}

function kounselia_send_booking_message( $booking_id, $sender_user_id, $content ) {
    global $wpdb;

    $content = trim( (string) $content );
    if ( '' === $content ) {
        return new WP_Error( 'empty_message', 'Please write a message first.' );
    }
    $content = substr( $content, 0, 2000 );

    $booking = kounselia_get_booking_with_parties( $booking_id );
    if ( ! $booking ) {
        return new WP_Error( 'not_found', 'Booking not found.' );
    }
    if ( ! kounselia_user_is_booking_party( $booking, $sender_user_id ) ) {
        return new WP_Error( 'forbidden', 'You are not part of this booking.' );
    }

    $wpdb->insert( $wpdb->prefix . 'kounselia_booking_messages', array(
        'booking_id'     => $booking_id,
        'sender_user_id' => $sender_user_id,
        'content'        => $content,
        'created_at'     => current_time( 'mysql' ),
    ) );

    $message_id = (int) $wpdb->insert_id;
    kounselia_notify_booking_message( $booking, $sender_user_id, $content );

    return $message_id;
}

/**
 * Mark every message the other party sent as read. Called whenever the
 * reader opens/refreshes the thread — cheap enough to just always run.
 */
function kounselia_mark_booking_messages_read( $booking_id, $reader_user_id ) {
    global $wpdb;
    $wpdb->query( $wpdb->prepare(
        "UPDATE {$wpdb->prefix}kounselia_booking_messages
         SET read_at = %s
         WHERE booking_id = %d AND sender_user_id != %d AND read_at IS NULL",
        current_time( 'mysql' ),
        $booking_id,
        $reader_user_id
    ) );
}

function kounselia_notify_booking_message( $booking, $sender_user_id, $content ) {
    if ( ! function_exists( 'kounselia_send_html_email' ) ) {
        return;
    }
    $recipient_user_id = ( (int) $booking->client_user_id === (int) $sender_user_id )
        ? $booking->professional_user_id
        : $booking->client_user_id;

    $recipient = get_userdata( $recipient_user_id );
    $sender    = get_userdata( $sender_user_id );
    if ( ! $recipient ) {
        return;
    }

    kounselia_send_html_email(
        $recipient->user_email,
        'New message about your upcoming session',
        'New message',
        '<p>' . esc_html( $sender ? $sender->display_name : 'The other person on your booking' ) . ' sent you a message: </p><blockquote style="margin:0;padding:12px 16px;border-left:3px solid #ccc;color:#444">' . esc_html( $content ) . '</blockquote>'
    );
}

function kounselia_ajax_get_booking_messages() {
    kounselia_verify_nonce();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.' ), 401 );
    }

    $booking_id = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0;
    $user_id    = get_current_user_id();

    $booking = $booking_id ? kounselia_get_booking_with_parties( $booking_id ) : null;
    if ( ! $booking || ! kounselia_user_is_booking_party( $booking, $user_id ) ) {
        wp_send_json_error( array( 'message' => 'You do not have access to this conversation.' ), 403 );
    }

    kounselia_mark_booking_messages_read( $booking_id, $user_id );

    $messages = array_map( function( $m ) use ( $user_id ) {
        return array(
            'id'          => (int) $m->id,
            'content'     => $m->content,
            'created_at'  => $m->created_at,
            'sender_name' => $m->sender_name,
            'is_mine'     => ( (int) $m->sender_user_id === (int) $user_id ),
        );
    }, kounselia_get_booking_messages( $booking_id ) );

    wp_send_json_success( array( 'messages' => $messages ) );
}
add_action( 'wp_ajax_kounselia_get_booking_messages', 'kounselia_ajax_get_booking_messages' );

function kounselia_ajax_send_booking_message() {
    kounselia_verify_nonce();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.' ), 401 );
    }
    if ( kounselia_rate_limited( 'send_booking_message', 60, 3600 ) ) {
        wp_send_json_error( array( 'message' => 'Too many messages. Please slow down.' ), 429 );
    }

    $booking_id = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0;
    $content    = isset( $_POST['content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['content'] ) ) : '';

    $result = kounselia_send_booking_message( $booking_id, get_current_user_id(), $content );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }

    wp_send_json_success( array( 'message_id' => $result ) );
}
add_action( 'wp_ajax_kounselia_send_booking_message', 'kounselia_ajax_send_booking_message' );

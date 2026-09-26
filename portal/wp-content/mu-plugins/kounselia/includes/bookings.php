<?php
/**
 * Kounselia Core — booking calendar: a professional's recurring weekly
 * availability, expanded into concrete open slots, and the sessions
 * clients book against them.
 *
 * A slot is only ever offered if it's inside the professional's own
 * availability window and not already taken. Booking one creates it as
 * 'pending_payment' — reserved, so nobody else can grab it — and holds
 * that reservation only long enough to complete checkout (see
 * kounselia_booking_reservation_seconds() and booking-payments.php,
 * which flips it to 'confirmed' once Paystack confirms the charge). An
 * abandoned checkout just lets the reservation expire on its own; no
 * cron job needed, since the slot generator simply stops counting a
 * stale pending_payment row as blocking. Cancelling a confirmed booking
 * (by either side) frees the slot back up the same way.
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

/**
 * How long a 'pending_payment' reservation blocks its slot before it's
 * treated as abandoned and the slot opens back up for someone else.
 */
function kounselia_booking_reservation_seconds() {
    return 15 * MINUTE_IN_SECONDS;
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

function kounselia_get_booked_slot_starts( $professional_id, $exclude_booking_id = 0 ) {
    global $wpdb;
    $reservation_cutoff = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - kounselia_booking_reservation_seconds() );
    $sql = "SELECT scheduled_start FROM {$wpdb->prefix}kounselia_bookings
            WHERE professional_id = %d AND scheduled_start >= %s
            AND ( status = 'confirmed' OR ( status = 'pending_payment' AND created_at >= %s ) )";
    $args = array( $professional_id, current_time( 'mysql' ), $reservation_cutoff );
    if ( $exclude_booking_id ) {
        $sql   .= ' AND id != %d';
        $args[] = $exclude_booking_id;
    }
    $rows = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
    return array_flip( $rows );
}

/**
 * Expand a professional's weekly rules into concrete open slots (as
 * 'Y-m-d H:i:s' strings) over the booking horizon, skipping anything
 * already booked or too soon to book.
 */
function kounselia_get_available_slots( $professional_id, $exclude_booking_id = 0 ) {
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

    $booked = kounselia_get_booked_slot_starts( $professional_id, $exclude_booking_id );

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
function kounselia_create_booking( $professional_id, $client_user_id, $scheduled_start_mysql, $note, $series_id = 0 ) {
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
    // same instant, or someone else's still-live reservation on it. Not
    // a hard DB constraint, but closes the gap enough for real-world
    // traffic on a booking flow like this one.
    $reservation_cutoff = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - kounselia_booking_reservation_seconds() );
    $already_taken = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_bookings
         WHERE professional_id = %d AND scheduled_start = %s
         AND ( status = 'confirmed' OR ( status = 'pending_payment' AND created_at >= %s ) )",
        $professional_id,
        $normalized_start,
        $reservation_cutoff
    ) );
    if ( $already_taken > 0 ) {
        return new WP_Error( 'invalid_slot', 'That time was just booked by someone else. Please pick another slot.' );
    }

    $end_ts = $start_ts + ( kounselia_session_length_minutes() * 60 );
    $now    = current_time( 'mysql' );

    // Reserved, not yet confirmed — kounselia_complete_booking_payment()
    // (booking-payments.php) flips this to 'confirmed' once Paystack
    // verifies the charge. Nothing here notifies anyone yet; a
    // reservation nobody paid for shouldn't look like a real booking to
    // either side.
    $wpdb->insert( $wpdb->prefix . 'kounselia_bookings', array(
        'professional_id' => $professional_id,
        'client_user_id'  => $client_user_id,
        'scheduled_start' => $normalized_start,
        'scheduled_end'   => date( 'Y-m-d H:i:s', $end_ts ),
        'status'          => 'pending_payment',
        'client_note'     => $note ? substr( $note, 0, 500 ) : null,
        'room_token'      => wp_generate_password( 40, false ),
        'series_id'       => $series_id ?: null,
        'created_at'      => $now,
        'updated_at'      => $now,
    ) );

    return (int) $wpdb->insert_id;
}

/**
 * Drop a reservation that never made it to payment — e.g. Paystack
 * checkout couldn't even be started. Only ever touches a row still in
 * 'pending_payment', so it can't accidentally delete a real booking.
 */
function kounselia_delete_unpaid_booking( $booking_id ) {
    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'kounselia_bookings', array( 'id' => $booking_id, 'status' => 'pending_payment' ) );
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
 * Sessions that have already happened, most recent first — this is
 * where a "rate this session" prompt comes from, and where a review
 * already left shows up alongside it.
 */
function kounselia_get_client_past_bookings( $client_user_id, $limit = 10 ) {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT b.*, p.title AS pro_title, u.display_name AS pro_name,
                r.id AS review_id, r.rating AS review_rating, r.comment AS review_comment
         FROM {$wpdb->prefix}kounselia_bookings b
         INNER JOIN {$wpdb->prefix}kounselia_professionals p ON p.id = b.professional_id
         INNER JOIN {$wpdb->users} u ON u.ID = p.user_id
         LEFT JOIN {$wpdb->prefix}kounselia_professional_reviews r ON r.booking_id = b.id
         WHERE b.client_user_id = %d AND b.status = 'confirmed' AND b.scheduled_end < %s
         ORDER BY b.scheduled_start DESC
         LIMIT %d",
        $client_user_id,
        current_time( 'mysql' ),
        $limit
    ) );
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
    $booking = kounselia_get_booking_with_parties( $booking_id );

    if ( ! $booking ) {
        return new WP_Error( 'not_found', 'Booking not found.' );
    }
    if ( ! kounselia_user_is_booking_party( $booking, $acting_user_id ) ) {
        return new WP_Error( 'forbidden', 'You cannot cancel this booking.' );
    }

    return kounselia_do_cancel_booking( $booking, $acting_user_id, $reason );
}

/**
 * Admin-initiated cancellation — for stepping into a dispute ("the
 * professional never showed up") from the admin Bookings page. Skips
 * the "are you one of the two people on this booking" check that
 * kounselia_cancel_booking() enforces for everyone else; the caller
 * (kounselia_ajax_admin_cancel_booking) is responsible for having
 * already confirmed the acting user is actually an admin.
 */
function kounselia_admin_cancel_booking( $booking_id, $admin_user_id, $reason = '' ) {
    $booking = kounselia_get_booking_with_parties( $booking_id );
    if ( ! $booking ) {
        return new WP_Error( 'not_found', 'Booking not found.' );
    }
    return kounselia_do_cancel_booking( $booking, $admin_user_id, $reason );
}

/**
 * The actual state change + refund + notification, shared by the
 * client/professional-facing cancel and the admin override above. Only
 * a 'confirmed' booking can be cancelled this way — kounselia_admin_cancel_booking()
 * relies on that to keep it from touching a 'payment_conflict' booking,
 * which needs a manual refund decision, not an automatic one.
 */
function kounselia_do_cancel_booking( $booking, $acting_user_id, $reason = '' ) {
    global $wpdb;

    if ( 'confirmed' !== $booking->status ) {
        return new WP_Error( 'invalid_state', 'This booking is no longer active.' );
    }

    $wpdb->update( $wpdb->prefix . 'kounselia_bookings', array(
        'status'        => 'cancelled',
        'cancelled_by'  => $acting_user_id,
        'cancel_reason' => $reason ? sanitize_textarea_field( $reason ) : null,
        'updated_at'    => current_time( 'mysql' ),
    ), array( 'id' => $booking->id ) );

    // A cancelled session shouldn't quietly stay "earned" for the
    // professional or "spent" for the client — if it was paid for,
    // refund it (unless that money has already been folded into a
    // payout, which needs a human, not an automatic reversal).
    if ( function_exists( 'kounselia_refund_booking_payment' ) ) {
        kounselia_refund_booking_payment( $booking->id );
    }

    kounselia_notify_booking_cancelled( $booking->id, $acting_user_id );

    return true;
}

/* -------------------------------------------------------------------------
 * RESCHEDULE — moving an already-paid booking to a new time, instead of
 * cancelling (which refunds) and rebooking (which charges again). Only
 * the two people on the booking can do this, and only while it's still
 * 'confirmed' and in the future.
 * ---------------------------------------------------------------------- */

function kounselia_reschedule_booking( $booking_id, $acting_user_id, $new_start_mysql ) {
    global $wpdb;

    $booking = kounselia_get_booking_with_parties( $booking_id );
    if ( ! $booking ) {
        return new WP_Error( 'not_found', 'Booking not found.' );
    }
    if ( ! kounselia_user_is_booking_party( $booking, $acting_user_id ) ) {
        return new WP_Error( 'forbidden', 'You cannot reschedule this booking.' );
    }
    if ( 'confirmed' !== $booking->status ) {
        return new WP_Error( 'invalid_state', 'This booking is no longer active.' );
    }
    if ( strtotime( $booking->scheduled_start ) <= current_time( 'timestamp' ) ) {
        return new WP_Error( 'too_late', 'This session has already started, so it can\'t be rescheduled.' );
    }

    $start_ts = strtotime( $new_start_mysql );
    if ( ! $start_ts ) {
        return new WP_Error( 'invalid_slot', 'That time is no longer available.' );
    }
    $normalized_start = date( 'Y-m-d H:i:s', $start_ts );

    $valid_slots = kounselia_get_available_slots( $booking->professional_id, $booking_id );
    if ( ! in_array( $normalized_start, $valid_slots, true ) ) {
        return new WP_Error( 'invalid_slot', 'That time is no longer available. Please pick another slot.' );
    }

    $old_start = $booking->scheduled_start;
    $end_ts    = $start_ts + ( kounselia_session_length_minutes() * 60 );

    $wpdb->update( $wpdb->prefix . 'kounselia_bookings', array(
        'scheduled_start'  => $normalized_start,
        'scheduled_end'    => date( 'Y-m-d H:i:s', $end_ts ),
        'reminder_sent_at' => null, // a moved session gets its own fresh reminder
        'updated_at'       => current_time( 'mysql' ),
    ), array( 'id' => $booking_id ) );

    kounselia_notify_booking_rescheduled( $booking_id, $acting_user_id, $old_start );

    return true;
}

function kounselia_ajax_reschedule_booking() {
    kounselia_verify_nonce();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.' ), 401 );
    }
    if ( kounselia_rate_limited( 'reschedule_booking', 10, 3600 ) ) {
        wp_send_json_error( array( 'message' => 'Too many attempts. Please try again later.' ), 429 );
    }

    $booking_id      = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0;
    $scheduled_start = isset( $_POST['scheduled_start'] ) ? sanitize_text_field( wp_unslash( $_POST['scheduled_start'] ) ) : '';

    if ( ! $booking_id || ! $scheduled_start ) {
        wp_send_json_error( array( 'message' => 'Please choose a new time.' ), 400 );
    }

    $result = kounselia_reschedule_booking( $booking_id, get_current_user_id(), $scheduled_start );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }

    wp_send_json_success( array( 'message' => 'Session rescheduled.' ) );
}
add_action( 'wp_ajax_kounselia_reschedule_booking', 'kounselia_ajax_reschedule_booking' );

/* -------------------------------------------------------------------------
 * NOTIFICATIONS
 * ---------------------------------------------------------------------- */

function kounselia_notify_booking_created( $booking_id ) {
    if ( ! function_exists( 'kounselia_notify_user' ) ) {
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
    $site_url            = rtrim( home_url(), '/' );

    if ( $professional_user ) {
        kounselia_notify_user(
            $booking->professional_user_id,
            'booking_created',
            'New session booked',
            ( $client_user ? $client_user->display_name : 'A client' ) . ' booked a session for ' . $when . '.',
            '/pro-dashboard.php',
            array(
                'subject'      => 'New session booked',
                'headline'     => 'New booking',
                'content_html' => '<p>' . esc_html( $client_user ? $client_user->display_name : 'A client' ) . ' just booked a session with you for <strong>' . esc_html( $when ) . '</strong>.</p>',
                'btn_text'     => 'View your bookings',
                'btn_url'      => $site_url . '/pro-dashboard.php',
            )
        );
    }
    if ( $client_user ) {
        kounselia_notify_user(
            $booking->client_user_id,
            'booking_created',
            'Your session is booked',
            'Confirmed for ' . $when . ( $professional_user ? ' with ' . $professional_user->display_name : '' ) . '.',
            '/dashboard.php#professionals',
            array(
                'subject'      => 'Your session is booked',
                'headline'     => 'Booking confirmed',
                'content_html' => '<p>Your session is confirmed for <strong>' . esc_html( $when ) . '</strong>' . ( $professional_user ? ' with ' . esc_html( $professional_user->display_name ) : '' ) . '.</p>',
            )
        );
    }
}

function kounselia_notify_booking_cancelled( $booking_id, $cancelled_by_user_id ) {
    if ( ! function_exists( 'kounselia_notify_user' ) ) {
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

    $when             = date_i18n( 'l, F j, Y \a\t g:i A', strtotime( $booking->scheduled_start ) );
    $cancelled_by_pro = ( (int) $cancelled_by_user_id === (int) $booking->professional_user_id );
    $cancelled_by_client = ( (int) $cancelled_by_user_id === (int) $booking->client_user_id );

    // Whoever cancelled already knows — notify the other side. If
    // neither (an admin stepping into a dispute), notify both.
    $notify_ids = array();
    if ( $cancelled_by_pro ) {
        $notify_ids[] = $booking->client_user_id;
    } elseif ( $cancelled_by_client ) {
        $notify_ids[] = $booking->professional_user_id;
    } else {
        $notify_ids = array( $booking->client_user_id, $booking->professional_user_id );
    }

    foreach ( $notify_ids as $notify_user_id ) {
        $notify_is_pro = ( (int) $notify_user_id === (int) $booking->professional_user_id );
        kounselia_notify_user(
            $notify_user_id,
            'booking_cancelled',
            'A session was cancelled',
            'The session scheduled for ' . $when . ' has been cancelled.',
            $notify_is_pro ? '/pro-dashboard.php#bookings' : '/dashboard.php#professionals',
            array(
                'subject'      => 'A session was cancelled',
                'headline'     => 'Booking cancelled',
                'content_html' => '<p>The session scheduled for <strong>' . esc_html( $when ) . '</strong> has been cancelled.</p>',
            )
        );
    }
}

/**
 * Notifies the other party (not whoever moved it) that a booking's time
 * changed, showing both the old and new time so it's unambiguous.
 */
function kounselia_notify_booking_rescheduled( $booking_id, $acting_user_id, $old_start_mysql ) {
    if ( ! function_exists( 'kounselia_notify_user' ) ) {
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

    $old_when = date_i18n( 'l, F j, Y \a\t g:i A', strtotime( $old_start_mysql ) );
    $new_when = date_i18n( 'l, F j, Y \a\t g:i A', strtotime( $booking->scheduled_start ) );

    $acted_by_pro   = ( (int) $acting_user_id === (int) $booking->professional_user_id );
    $notify_user_id = $acted_by_pro ? $booking->client_user_id : $booking->professional_user_id;

    kounselia_notify_user(
        $notify_user_id,
        'booking_rescheduled',
        'A session was rescheduled',
        'Moved from ' . $old_when . ' to ' . $new_when . '.',
        $acted_by_pro ? '/dashboard.php#professionals' : '/pro-dashboard.php#bookings',
        array(
            'subject'      => 'A session was rescheduled',
            'headline'     => 'Booking rescheduled',
            'content_html' => '<p>A session originally scheduled for <strong>' . esc_html( $old_when ) . '</strong> has been moved to <strong>' . esc_html( $new_when ) . '</strong>.</p>',
        )
    );
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

    $professional_id      = isset( $_POST['professional_id'] ) ? absint( $_POST['professional_id'] ) : 0;
    $reschedule_booking_id = isset( $_POST['reschedule_booking_id'] ) ? absint( $_POST['reschedule_booking_id'] ) : 0;
    if ( ! $professional_id ) {
        wp_send_json_error( array( 'message' => 'Invalid request.' ), 400 );
    }

    $professional = kounselia_get_professional_by_id( $professional_id );
    if ( ! $professional || 'verified' !== $professional->status ) {
        wp_send_json_error( array( 'message' => 'That professional is not currently taking bookings.' ), 404 );
    }

    // Rescheduling: exclude the booking's own current slot from "taken"
    // so it doesn't block itself, but only once we've confirmed the
    // requester is actually one of the two people on that booking.
    $exclude_booking_id = 0;
    if ( $reschedule_booking_id ) {
        $existing_booking = kounselia_get_booking_with_parties( $reschedule_booking_id );
        if ( $existing_booking && kounselia_user_is_booking_party( $existing_booking, get_current_user_id() )
            && (int) $existing_booking->professional_id === $professional_id ) {
            $exclude_booking_id = $reschedule_booking_id;
        }
    }

    $slots = kounselia_get_available_slots( $professional_id, $exclude_booking_id );
    wp_send_json_success( array(
        'slots'           => $slots,
        // The same slots in UTC, for the mobile app to show in the
        // member's own time zone (it sends back the `slots` form).
        'slots_utc'       => array_map( 'kounselia_app_utc', $slots ),
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
    $make_recurring  = ! empty( $_POST['make_recurring'] );

    if ( ! $professional_id || ! $scheduled_start ) {
        wp_send_json_error( array( 'message' => 'Please choose a time.' ), 400 );
    }

    $series_id = 0;
    if ( $make_recurring && function_exists( 'kounselia_create_booking_series' ) ) {
        $start_ts = strtotime( $scheduled_start );
        if ( $start_ts ) {
            $series_id = kounselia_create_booking_series( $professional_id, get_current_user_id(), (int) date( 'w', $start_ts ), date( 'H:i', $start_ts ) );
            if ( is_wp_error( $series_id ) ) {
                wp_send_json_error( array( 'message' => $series_id->get_error_message() ), 400 );
            }
        }
    }

    $booking_id = kounselia_create_booking( $professional_id, get_current_user_id(), $scheduled_start, $note, $series_id );
    if ( is_wp_error( $booking_id ) ) {
        if ( $series_id ) {
            kounselia_delete_booking_series( $series_id );
        }
        wp_send_json_error( array( 'message' => $booking_id->get_error_message() ), 400 );
    }

    if ( ! function_exists( 'kounselia_init_booking_payment' ) ) {
        kounselia_delete_unpaid_booking( $booking_id );
        wp_send_json_error( array( 'message' => 'Payments are not available right now.' ), 500 );
    }

    $checkout = kounselia_init_booking_payment( $booking_id );
    if ( is_wp_error( $checkout ) ) {
        // Don't leave a dead reservation holding the slot hostage just
        // because checkout itself couldn't be started.
        kounselia_delete_unpaid_booking( $booking_id );
        if ( $series_id ) {
            kounselia_delete_booking_series( $series_id );
        }
        wp_send_json_error( array( 'message' => $checkout->get_error_message() ), 502 );
    }

    wp_send_json_success( array( 'authorization_url' => $checkout, 'booking_id' => $booking_id ) );
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
 * ADMIN OVERSIGHT
 * ---------------------------------------------------------------------- */

/**
 * Every booking, most recent first, with enough joined-in context (who's
 * on it, whether it was paid, whether the message thread has anything
 * flagged) to render the admin Bookings list without N+1 queries.
 * $status_filter is a booking status ('confirmed', 'cancelled',
 * 'payment_conflict', ...) or 'all'.
 */
function kounselia_get_all_bookings_admin( $status_filter = 'all', $limit = 50, $offset = 0 ) {
    global $wpdb;

    $where = '';
    $args  = array();
    if ( 'all' !== $status_filter ) {
        $where  = 'WHERE b.status = %s';
        $args[] = $status_filter;
    }
    $args[] = $limit;
    $args[] = $offset;

    $sql = "SELECT b.*, u.display_name AS client_name, u.user_email AS client_email,
                   pu.display_name AS pro_name, p.title AS pro_title,
                   ( SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_booking_messages bm WHERE bm.booking_id = b.id ) AS message_count,
                   ( SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_booking_messages bm WHERE bm.booking_id = b.id AND bm.flagged_safety = 1 ) AS flagged_count,
                   bp.status AS payment_status
            FROM {$wpdb->prefix}kounselia_bookings b
            LEFT JOIN {$wpdb->users} u ON u.ID = b.client_user_id
            INNER JOIN {$wpdb->prefix}kounselia_professionals p ON p.id = b.professional_id
            LEFT JOIN {$wpdb->users} pu ON pu.ID = p.user_id
            LEFT JOIN {$wpdb->prefix}kounselia_booking_payments bp ON bp.booking_id = b.id
            {$where}
            ORDER BY b.scheduled_start DESC
            LIMIT %d OFFSET %d";

    return $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
}

function kounselia_count_all_bookings_admin( $status_filter = 'all' ) {
    global $wpdb;
    if ( 'all' === $status_filter ) {
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_bookings" );
    }
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_bookings WHERE status = %s",
        $status_filter
    ) );
}

function kounselia_ajax_admin_cancel_booking() {
    check_ajax_referer( 'kounselia_admin_nonce', 'nonce' );

    if ( ! kounselia_user_is_admin() ) {
        kounselia_send_pure_json_error( array( 'message' => 'Unauthorized' ), 403 );
    }

    $booking_id = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0;
    $reason     = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';

    if ( ! $booking_id ) {
        kounselia_send_pure_json_error( array( 'message' => 'Invalid request' ), 400 );
    }

    $result = kounselia_admin_cancel_booking( $booking_id, get_current_user_id(), $reason );
    if ( is_wp_error( $result ) ) {
        kounselia_send_pure_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }

    kounselia_admin_log( 'cancel_booking', 'booking', $booking_id );
    kounselia_send_pure_json_success( array( 'message' => 'Booking cancelled.' ) );
}
add_action( 'wp_ajax_kounselia_admin_cancel_booking', 'kounselia_ajax_admin_cancel_booking' );

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

    // Same first net the AI chat already runs every message through
    // (kounselia_message_matches_safety_keywords) — a human professional
    // hearing something concerning is not a smaller emergency than an AI
    // counselor hearing it, so this gets the same escalation path.
    $flagged_safety = 0;
    $flag_reason    = null;
    if ( function_exists( 'kounselia_message_matches_safety_keywords' ) ) {
        $matched = kounselia_message_matches_safety_keywords( $content );
        if ( $matched ) {
            $flagged_safety = 1;
            $flag_reason    = $matched;
        }
    }

    $wpdb->insert( $wpdb->prefix . 'kounselia_booking_messages', array(
        'booking_id'     => $booking_id,
        'sender_user_id' => $sender_user_id,
        'content'        => $content,
        'created_at'     => current_time( 'mysql' ),
        'flagged_safety' => $flagged_safety,
        'flag_reason'    => $flag_reason,
        // 0 = waiting for the background AI risk check (safety-ai-screening.php).
        'ai_screened'    => $flagged_safety ? 1 : 0,
    ) );

    $message_id = (int) $wpdb->insert_id;

    if ( $flagged_safety && function_exists( 'kounselia_record_booking_message_safety_escalation' ) ) {
        kounselia_record_booking_message_safety_escalation( $message_id, $booking_id, $flag_reason, $sender_user_id );
    }

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
    if ( ! function_exists( 'kounselia_notify_user' ) ) {
        return;
    }
    $recipient_user_id = ( (int) $booking->client_user_id === (int) $sender_user_id )
        ? $booking->professional_user_id
        : $booking->client_user_id;

    $sender = get_userdata( $sender_user_id );
    $sender_name = $sender ? $sender->display_name : 'The other person on your booking';
    $recipient_is_pro = ( (int) $recipient_user_id === (int) $booking->professional_user_id );

    kounselia_notify_user(
        $recipient_user_id,
        'booking_message',
        'New message from ' . $sender_name,
        wp_trim_words( $content, 20, '…' ),
        $recipient_is_pro ? '/pro-dashboard.php#bookings' : '/dashboard.php#professionals',
        array(
            'subject'      => 'New message about your upcoming session',
            'headline'     => 'New message',
            'content_html' => '<p>' . esc_html( $sender_name ) . ' sent you a message: </p><blockquote style="margin:0;padding:12px 16px;border-left:3px solid #ccc;color:#444">' . esc_html( $content ) . '</blockquote>',
        )
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

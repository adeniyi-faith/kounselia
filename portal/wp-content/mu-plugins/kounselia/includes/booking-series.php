<?php
/**
 * Kounselia Core — recurring weekly sessions with the same professional,
 * the way most real therapy actually works, instead of every session
 * being a one-off a client has to remember to rebook.
 *
 * A series (kounselia_booking_series) is just the rule: this
 * professional, this day of week, this time. Each actual session is
 * still an ordinary row in kounselia_bookings (linked back via
 * series_id) with its own payment, booking messages, video room, review
 * — nothing about "being recurring" changes what a session IS, only how
 * it gets created.
 *
 * The first occurrence is paid for exactly like a one-off booking: a
 * normal Paystack checkout redirect. What's different is that once that
 * payment verifies, the resulting card "authorization" — Paystack's
 * token for charging that same card again without the client re-entering
 * details — is saved on the series (see kounselia_maybe_save_series_authorization(),
 * called from kounselia_complete_booking_payment() in booking-payments.php).
 * A daily cron job then keeps a couple of weeks of future occurrences
 * booked and paid automatically using that saved authorization.
 *
 * If an automatic charge is ever declined, or the professional's
 * availability no longer has that slot, the series stops itself
 * (status 'payment_failed') rather than silently retrying or occupying
 * a slot nobody's actually confirmed to pay for — the client is emailed
 * either way, and can start a fresh series once they've sorted it out.
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * How many days ahead a series tries to keep booked. The renewal cron
 * only generates the next occurrence once the series has fewer days of
 * runway than this left, so it isn't hammering Paystack every single
 * day for a series that's already several weeks ahead.
 */
function kounselia_series_horizon_days() {
    return 14;
}

function kounselia_get_booking_series( $series_id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}kounselia_booking_series WHERE id = %d",
        $series_id
    ) );
}

function kounselia_user_is_series_party( $series, $user_id ) {
    if ( ! $series || ! $user_id ) {
        return false;
    }
    $professional = kounselia_get_professional_by_id( $series->professional_id );
    $pro_user_id  = $professional ? (int) $professional->user_id : 0;
    return ( (int) $series->client_user_id === (int) $user_id ) || ( $pro_user_id === (int) $user_id );
}

/**
 * Starts a new series. Doesn't touch payment or create the first
 * occurrence itself — the caller (kounselia_ajax_create_booking) creates
 * the first booking against this series id right after, going through
 * the exact same checkout as any other booking.
 */
function kounselia_create_booking_series( $professional_id, $client_user_id, $day_of_week, $start_time ) {
    global $wpdb;

    $professional = kounselia_get_professional_by_id( $professional_id );
    if ( ! $professional || 'verified' !== $professional->status ) {
        return new WP_Error( 'not_available', 'That professional is not currently taking bookings.' );
    }

    $now = current_time( 'mysql' );
    $wpdb->insert( $wpdb->prefix . 'kounselia_booking_series', array(
        'professional_id' => $professional_id,
        'client_user_id'  => $client_user_id,
        'day_of_week'     => $day_of_week,
        'start_time'      => $start_time . ':00',
        'status'          => 'active',
        'created_at'      => $now,
        'updated_at'      => $now,
    ) );

    return (int) $wpdb->insert_id;
}

/**
 * Cleans up a series that never got its first occurrence paid for —
 * mirrors kounselia_delete_unpaid_booking()'s "don't leave dead state
 * behind just because checkout failed to even start" rule.
 */
function kounselia_delete_booking_series( $series_id ) {
    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'kounselia_booking_series', array( 'id' => $series_id, 'status' => 'active' ) );
}

/**
 * Called from kounselia_complete_booking_payment() right after a
 * booking is confirmed. If that booking belongs to a series with no
 * saved authorization yet, and Paystack's response says this card can
 * be charged again later ("reusable"), save it — this is what makes
 * automatic renewal possible at all.
 */
function kounselia_maybe_save_series_authorization( $booking_id, $paystack_data ) {
    global $wpdb;

    $booking = $wpdb->get_row( $wpdb->prepare(
        "SELECT series_id, client_user_id FROM {$wpdb->prefix}kounselia_bookings WHERE id = %d",
        $booking_id
    ) );
    if ( ! $booking || ! $booking->series_id ) {
        return;
    }

    $series = kounselia_get_booking_series( $booking->series_id );
    if ( ! $series || $series->paystack_authorization_code ) {
        return;
    }

    $auth = isset( $paystack_data['authorization'] ) ? $paystack_data['authorization'] : array();
    if ( empty( $auth['reusable'] ) || empty( $auth['authorization_code'] ) ) {
        // Can't auto-renew this one — let the client know so it isn't a
        // silent surprise when next week never gets booked.
        $client = get_userdata( $booking->client_user_id );
        if ( $client && function_exists( 'kounselia_notify_user' ) ) {
            kounselia_notify_user(
                $booking->client_user_id,
                'series_needs_manual_renewal',
                'Weekly sessions need to be booked manually',
                'Your payment method can\'t be auto-charged for future weeks — please book each session yourself.',
                '/dashboard.php#professionals'
            );
        }
        $wpdb->update( $wpdb->prefix . 'kounselia_booking_series', array(
            'status'        => 'payment_failed',
            'cancel_reason' => 'Card is not reusable for automatic renewal.',
            'updated_at'    => current_time( 'mysql' ),
        ), array( 'id' => $series->id ) );
        return;
    }

    $client = get_userdata( $booking->client_user_id );
    $wpdb->update( $wpdb->prefix . 'kounselia_booking_series', array(
        'paystack_authorization_code' => $auth['authorization_code'],
        'paystack_email'              => $client ? $client->user_email : ( isset( $paystack_data['customer']['email'] ) ? $paystack_data['customer']['email'] : '' ),
        'updated_at'                  => current_time( 'mysql' ),
    ), array( 'id' => $series->id ) );
}

/**
 * Cancels a series (either party can). Stops future auto-renewal and
 * cancels/refunds any occurrence that's already scheduled but hasn't
 * happened yet — a session that's already in the past is left alone,
 * it already happened.
 */
function kounselia_cancel_series( $series_id, $acting_user_id, $reason = '' ) {
    global $wpdb;

    $series = kounselia_get_booking_series( $series_id );
    if ( ! $series ) {
        return new WP_Error( 'not_found', 'Series not found.' );
    }
    if ( ! kounselia_user_is_series_party( $series, $acting_user_id ) ) {
        return new WP_Error( 'forbidden', 'You cannot cancel this series.' );
    }

    $wpdb->update( $wpdb->prefix . 'kounselia_booking_series', array(
        'status'        => 'cancelled',
        'cancel_reason' => $reason ? sanitize_textarea_field( $reason ) : null,
        'updated_at'    => current_time( 'mysql' ),
    ), array( 'id' => $series_id ) );

    $future_bookings = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}kounselia_bookings
         WHERE series_id = %d AND status IN ('confirmed','pending_payment') AND scheduled_start > %s",
        $series_id,
        current_time( 'mysql' )
    ) );

    foreach ( $future_bookings as $booking ) {
        if ( 'confirmed' === $booking->status ) {
            kounselia_cancel_booking( $booking->id, $acting_user_id, 'Weekly series cancelled.' );
        } else {
            kounselia_delete_unpaid_booking( $booking->id );
        }
    }

    return true;
}

function kounselia_ajax_cancel_series() {
    kounselia_verify_nonce();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.' ), 401 );
    }

    $series_id = isset( $_POST['series_id'] ) ? absint( $_POST['series_id'] ) : 0;
    $reason    = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';

    if ( ! $series_id ) {
        wp_send_json_error( array( 'message' => 'Invalid request.' ), 400 );
    }

    $result = kounselia_cancel_series( $series_id, get_current_user_id(), $reason );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }

    wp_send_json_success( array( 'message' => 'Weekly series cancelled.' ) );
}
add_action( 'wp_ajax_kounselia_cancel_series', 'kounselia_ajax_cancel_series' );

/* -------------------------------------------------------------------------
 * RENEWAL — the daily cron that keeps active series topped up.
 * ---------------------------------------------------------------------- */

add_action( 'init', function() {
    if ( ! wp_next_scheduled( 'kounselia_process_recurring_series' ) ) {
        wp_schedule_event( time(), 'daily', 'kounselia_process_recurring_series' );
    }
} );

function kounselia_process_recurring_series() {
    global $wpdb;

    $series_list = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}kounselia_booking_series
         WHERE status = 'active' AND paystack_authorization_code IS NOT NULL"
    );

    foreach ( $series_list as $series ) {
        kounselia_process_one_series_renewal( $series );
    }
}
add_action( 'kounselia_process_recurring_series', 'kounselia_process_recurring_series' );

function kounselia_process_one_series_renewal( $series ) {
    global $wpdb;

    $horizon_seconds = kounselia_series_horizon_days() * DAY_IN_SECONDS;
    $now             = current_time( 'timestamp' );

    $latest = $wpdb->get_row( $wpdb->prepare(
        "SELECT scheduled_start FROM {$wpdb->prefix}kounselia_bookings
         WHERE series_id = %d AND status IN ('confirmed','pending_payment')
         ORDER BY scheduled_start DESC LIMIT 1",
        $series->id
    ) );

    // Already has runway — nothing to do this run.
    if ( $latest && strtotime( $latest->scheduled_start ) > ( $now + $horizon_seconds ) ) {
        return;
    }

    $base_ts   = $latest ? strtotime( $latest->scheduled_start ) : $now;
    $next_ts   = strtotime( '+7 days', $base_ts );
    $next_date = date( 'Y-m-d', $next_ts );
    $next_start_mysql = $next_date . ' ' . $series->start_time;

    $professional = kounselia_get_professional_by_id( $series->professional_id );
    if ( ! $professional || 'verified' !== $professional->status ) {
        kounselia_pause_series( $series, 'The professional is no longer available for bookings.' );
        return;
    }

    $valid_slots = kounselia_get_available_slots( $series->professional_id );
    if ( ! in_array( $next_start_mysql, $valid_slots, true ) ) {
        kounselia_pause_series( $series, 'The next weekly time is no longer available in the professional\'s schedule.' );
        return;
    }

    $booking_id = kounselia_create_booking( $series->professional_id, $series->client_user_id, $next_start_mysql, null, $series->id );
    if ( is_wp_error( $booking_id ) ) {
        kounselia_pause_series( $series, $booking_id->get_error_message() );
        return;
    }

    $charge_result = kounselia_charge_series_renewal( $series, $booking_id, $professional );
    if ( is_wp_error( $charge_result ) ) {
        kounselia_delete_unpaid_booking( $booking_id );
        kounselia_pause_series( $series, $charge_result->get_error_message() );
    }
}

/**
 * Charges the series' saved card for one occurrence via Paystack's
 * charge_authorization endpoint (no redirect — the client isn't present
 * for this), then confirms the booking exactly like a normal checkout
 * would, splitting commission the same way.
 */
function kounselia_charge_series_renewal( $series, $booking_id, $professional ) {
    global $wpdb;

    $amount = (float) $professional->rate_amount;
    if ( $amount <= 0 ) {
        return new WP_Error( 'invalid_amount', 'This professional has not set a rate.' );
    }

    $reference = 'KOUNSELIA-SERIES-' . $series->id . '-' . $booking_id . '-' . time();
    $result    = kounselia_paystack_request( 'POST', '/transaction/charge_authorization', array(
        'authorization_code' => $series->paystack_authorization_code,
        'email'              => $series->paystack_email,
        'amount'             => (int) round( $amount * 100 ),
        'reference'          => $reference,
    ) );

    if ( ! $result['ok'] || empty( $result['data']['status'] ) || 'success' !== $result['data']['status'] ) {
        return new WP_Error( 'charge_failed', $result['message'] ?: 'The saved card was declined for this week\'s session.' );
    }

    $commission_percent  = kounselia_booking_commission_percent();
    $platform_fee_amount = round( $amount * $commission_percent / 100, 2 );
    $professional_amount = round( $amount - $platform_fee_amount, 2 );
    $now                 = current_time( 'mysql' );

    $wpdb->insert( $wpdb->prefix . 'kounselia_booking_payments', array(
        'booking_id'          => $booking_id,
        'client_user_id'      => $series->client_user_id,
        'professional_id'     => $series->professional_id,
        'amount'              => $amount,
        'currency'            => 'NGN',
        'platform_fee_amount' => $platform_fee_amount,
        'professional_amount' => $professional_amount,
        'reference'           => $reference,
        'status'              => 'success',
        'gateway_response'    => wp_json_encode( $result['data'] ),
        'created_at'          => $now,
        'updated_at'          => $now,
    ) );

    $wpdb->update( $wpdb->prefix . 'kounselia_bookings', array(
        'status'     => 'confirmed',
        'updated_at' => $now,
    ), array( 'id' => $booking_id, 'status' => 'pending_payment' ) );

    if ( function_exists( 'kounselia_notify_booking_created' ) ) {
        kounselia_notify_booking_created( $booking_id );
    }

    return true;
}

function kounselia_pause_series( $series, $reason ) {
    global $wpdb;
    $wpdb->update( $wpdb->prefix . 'kounselia_booking_series', array(
        'status'        => 'payment_failed',
        'cancel_reason' => substr( $reason, 0, 500 ),
        'updated_at'    => current_time( 'mysql' ),
    ), array( 'id' => $series->id ) );

    if ( ! function_exists( 'kounselia_notify_user' ) ) {
        return;
    }

    $professional = kounselia_get_professional_by_id( $series->professional_id );

    kounselia_notify_user(
        $series->client_user_id,
        'series_paused',
        'Your weekly sessions have paused',
        $reason,
        '/dashboard.php#professionals',
        array(
            'subject'      => 'Your weekly sessions have paused',
            'headline'     => 'Weekly sessions paused',
            'content_html' => '<p>Your weekly sessions' . ( $professional ? ' with ' . esc_html( get_userdata( $professional->user_id )->display_name ?? '' ) : '' ) . ' have paused: ' . esc_html( $reason ) . '</p><p>You can book a fresh session (and set up a new weekly time) any time from your dashboard.</p>',
        )
    );

    if ( $professional && $professional->user_id ) {
        kounselia_notify_user(
            $professional->user_id,
            'series_paused',
            'A client\'s weekly sessions have paused',
            $reason,
            '/pro-dashboard.php#bookings'
        );
    }
}

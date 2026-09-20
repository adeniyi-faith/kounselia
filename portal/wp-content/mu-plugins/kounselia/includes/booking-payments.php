<?php
/**
 * Kounselia Core — money for the booking marketplace: charging a client
 * when they book a professional, and paying a professional their share
 * once they've earned it.
 *
 * Two separate flows, both built on the same Paystack account already
 * used for platform subscriptions (see payments.php for the low-level
 * kounselia_paystack_request() wrapper this file calls into):
 *
 *   1. CHARGE — kounselia_init_booking_payment() starts a Paystack
 *      checkout for the professional's rate the moment a client reserves
 *      a slot (see kounselia_create_booking() in bookings.php, which
 *      creates that reservation as 'pending_payment', not 'confirmed').
 *      Paystack redirects back to booking-payment-callback.php, which
 *      calls kounselia_complete_booking_payment() to verify the charge
 *      directly against Paystack's API and only then flips the booking
 *      to 'confirmed'. A charge that's never verified never confirms a
 *      booking, however the redirect itself claims things went.
 *
 *   2. PAYOUT — a professional's share of each paid session
 *      (kounselia_booking_payments.professional_amount, after the
 *      platform's commission) sits as an "available balance" until they
 *      add and verify a bank account and request a payout. That balance
 *      is transferred to their account via Paystack Transfers — real
 *      money leaving the platform, so every payout is recorded with the
 *      exact set of sessions it covers (see kounselia_request_payout()).
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function kounselia_booking_commission_percent() {
    return (float) get_option( 'kounselia_booking_commission_percent', 15 );
}

/* -------------------------------------------------------------------------
 * CHARGE — collecting payment for a booking
 * ---------------------------------------------------------------------- */

/**
 * Starts a Paystack checkout for a freshly reserved (pending_payment)
 * booking and records the pending charge. Returns the checkout URL to
 * redirect the client to, or a WP_Error if checkout couldn't even start
 * (in which case the caller should drop the reservation — see
 * kounselia_delete_unpaid_booking()).
 */
function kounselia_init_booking_payment( $booking_id ) {
    global $wpdb;

    $booking = kounselia_get_booking_with_parties( $booking_id );
    if ( ! $booking ) {
        return new WP_Error( 'not_found', 'Booking not found.' );
    }

    $professional = kounselia_get_professional_by_id( $booking->professional_id );
    $client       = get_userdata( $booking->client_user_id );
    if ( ! $professional || ! $client ) {
        return new WP_Error( 'not_found', 'Booking not found.' );
    }

    $amount = (float) $professional->rate_amount;
    if ( $amount <= 0 ) {
        return new WP_Error( 'invalid_amount', 'This professional has not set a rate yet.' );
    }

    $commission_percent  = kounselia_booking_commission_percent();
    $platform_fee_amount = round( $amount * $commission_percent / 100, 2 );
    $professional_amount = round( $amount - $platform_fee_amount, 2 );
    $currency            = $professional->rate_currency ? $professional->rate_currency : 'NGN';

    $reference = 'KOUNSELIA-BOOKING-' . $booking_id . '-' . time() . '-' . wp_generate_password( 6, false );

    $result = kounselia_paystack_request( 'POST', '/transaction/initialize', array(
        'email'        => $client->user_email,
        'amount'       => (int) round( $amount * 100 ), // Paystack expects kobo.
        'currency'     => $currency,
        'reference'    => $reference,
        'callback_url' => home_url( '/booking-payment-callback.php' ),
        'metadata'     => array( 'booking_id' => $booking_id ),
    ) );

    if ( ! $result['ok'] || empty( $result['data']['authorization_url'] ) ) {
        return new WP_Error( 'paystack_error', $result['message'] ?: 'Could not start checkout, please try again.' );
    }

    $now = current_time( 'mysql' );
    $wpdb->insert( $wpdb->prefix . 'kounselia_booking_payments', array(
        'booking_id'          => $booking_id,
        'client_user_id'      => $booking->client_user_id,
        'professional_id'     => $booking->professional_id,
        'amount'              => $amount,
        'currency'            => $currency,
        'platform_fee_amount' => $platform_fee_amount,
        'professional_amount' => $professional_amount,
        'reference'           => $reference,
        'status'              => 'pending',
        'created_at'          => $now,
        'updated_at'          => $now,
    ) );

    return $result['data']['authorization_url'];
}

/**
 * Verifies a Paystack reference against Paystack's own API (never the
 * redirect query string) and, if it really did succeed, confirms the
 * matching booking. Called from booking-payment-callback.php.
 */
function kounselia_complete_booking_payment( $reference ) {
    global $wpdb;
    $table = $wpdb->prefix . 'kounselia_booking_payments';

    $payment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE reference = %s", $reference ) );
    if ( ! $payment ) {
        return array( 'success' => false, 'message' => 'Unknown payment reference.' );
    }

    // Already processed (e.g. the user refreshed the callback page).
    if ( 'success' === $payment->status ) {
        return array( 'success' => true, 'message' => 'Payment already confirmed.', 'booking_id' => (int) $payment->booking_id );
    }

    $result = kounselia_paystack_request( 'GET', '/transaction/verify/' . rawurlencode( $reference ) );
    if ( ! $result['ok'] || empty( $result['data']['status'] ) || 'success' !== $result['data']['status'] ) {
        $wpdb->update( $table, array(
            'status'           => 'failed',
            'gateway_response' => wp_json_encode( $result['data'] ),
            'updated_at'       => current_time( 'mysql' ),
        ), array( 'id' => $payment->id ) );
        return array( 'success' => false, 'message' => $result['message'] ?: 'Payment was not successful.' );
    }

    // Verified amount must match what we asked for — guards against a
    // tampered client-side amount ever mattering.
    $verified_kobo = (int) ( $result['data']['amount'] ?? 0 );
    $expected_kobo = (int) round( (float) $payment->amount * 100 );
    if ( $verified_kobo !== $expected_kobo ) {
        $wpdb->update( $table, array(
            'status'           => 'failed',
            'gateway_response' => wp_json_encode( $result['data'] ),
            'updated_at'       => current_time( 'mysql' ),
        ), array( 'id' => $payment->id ) );
        return array( 'success' => false, 'message' => 'Payment amount mismatch.' );
    }

    $now = current_time( 'mysql' );
    $wpdb->update( $table, array(
        'status'           => 'success',
        'gateway_response' => wp_json_encode( $result['data'] ),
        'updated_at'       => $now,
    ), array( 'id' => $payment->id ) );

    $booking = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}kounselia_bookings WHERE id = %d",
        $payment->booking_id
    ) );

    // Rare, but possible: the reservation sat abandoned past its window,
    // someone else confirmed that exact slot, and only then did this
    // (stale) checkout link get completed. The charge is real and
    // already captured — it cannot be silently undone here — but the
    // slot is gone, so this booking is flagged for a human to sort out
    // (refund or reschedule) rather than double-booking the professional.
    if ( $booking && 'pending_payment' === $booking->status ) {
        $conflict = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_bookings
             WHERE professional_id = %d AND scheduled_start = %s AND status = 'confirmed' AND id != %d",
            $booking->professional_id,
            $booking->scheduled_start,
            $booking->id
        ) );

        if ( $conflict > 0 ) {
            $wpdb->update( $wpdb->prefix . 'kounselia_bookings', array(
                'status'     => 'payment_conflict',
                'updated_at' => $now,
            ), array( 'id' => $booking->id ) );

            kounselia_notify_admins_booking_conflict( $booking->id );

            return array(
                'success'    => false,
                'conflict'   => true,
                'message'    => 'That time was booked by someone else while your payment was processing. Your payment went through — our support team will be in touch to reschedule or refund you.',
                'booking_id' => (int) $payment->booking_id,
            );
        }

        $wpdb->update( $wpdb->prefix . 'kounselia_bookings', array(
            'status'     => 'confirmed',
            'updated_at' => $now,
        ), array( 'id' => $booking->id, 'status' => 'pending_payment' ) );

        if ( function_exists( 'kounselia_notify_booking_created' ) ) {
            kounselia_notify_booking_created( (int) $payment->booking_id );
        }
        if ( function_exists( 'kounselia_maybe_save_series_authorization' ) ) {
            kounselia_maybe_save_series_authorization( (int) $payment->booking_id, $result['data'] );
        }
    }

    return array( 'success' => true, 'message' => 'Payment confirmed.', 'booking_id' => (int) $payment->booking_id );
}

/**
 * Refunds a booking's payment when the booking itself is cancelled — a
 * cancelled session shouldn't silently stay "earned" for the
 * professional or "spent" for the client. Called from
 * kounselia_cancel_booking() in bookings.php. Does nothing if the
 * booking was never paid for (e.g. it never got past 'pending_payment').
 * If the money has already been swept into a payout, this can't reverse
 * that automatically — it flags the case for a human instead of ever
 * silently doing nothing or double-refunding.
 */
function kounselia_refund_booking_payment( $booking_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'kounselia_booking_payments';

    $payment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_id = %d", $booking_id ) );
    if ( ! $payment || 'success' !== $payment->status ) {
        return;
    }

    if ( null !== $payment->payout_id ) {
        kounselia_notify_admins_refund_needs_review( $booking_id, $payment->id );
        return;
    }

    $result = kounselia_paystack_request( 'POST', '/refund', array( 'transaction' => $payment->reference ) );
    $now    = current_time( 'mysql' );

    if ( $result['ok'] ) {
        $wpdb->update( $table, array(
            'status'           => 'refunded',
            'gateway_response' => wp_json_encode( $result['data'] ),
            'updated_at'       => $now,
        ), array( 'id' => $payment->id ) );
    } else {
        // Cancellation still goes through either way — a refund that
        // failed to start is a billing problem to chase down, not a
        // reason to block someone from cancelling their session.
        kounselia_notify_admins_refund_needs_review( $booking_id, $payment->id );
    }
}

function kounselia_notify_admins_refund_needs_review( $booking_id, $payment_id ) {
    if ( ! function_exists( 'kounselia_send_html_email' ) ) {
        return;
    }
    $admins = get_users( array( 'role__in' => array( 'administrator', 'kounselia_staff' ), 'fields' => array( 'user_email' ) ) );
    $emails = array_filter( array_unique( wp_list_pluck( $admins, 'user_email' ) ) );
    foreach ( $emails as $email ) {
        kounselia_send_html_email(
            $email,
            'A cancelled booking needs a manual refund review',
            'Refund needs review',
            '<p>Booking #' . (int) $booking_id . ' (payment #' . (int) $payment_id . ') was cancelled after payment, but could not be refunded automatically — please check it in Paystack and refund the client directly if appropriate.</p>'
        );
    }
}

function kounselia_notify_admins_booking_conflict( $booking_id ) {
    if ( ! function_exists( 'kounselia_send_html_email' ) ) {
        return;
    }
    $admins = get_users( array( 'role__in' => array( 'administrator', 'kounselia_staff' ), 'fields' => array( 'user_email' ) ) );
    $emails = array_filter( array_unique( wp_list_pluck( $admins, 'user_email' ) ) );
    foreach ( $emails as $email ) {
        kounselia_send_html_email(
            $email,
            'Booking payment conflict needs attention',
            'Payment conflict',
            '<p>A client\'s payment for booking #' . (int) $booking_id . ' was captured after that time slot had already been booked by someone else. The charge was not refunded automatically — please review and reschedule or refund the client.</p>'
        );
    }
}

/* -------------------------------------------------------------------------
 * PAYOUT ACCOUNT — a professional's verified bank account
 * ---------------------------------------------------------------------- */

function kounselia_paystack_list_banks() {
    $cached = get_transient( 'kounselia_paystack_banks' );
    if ( is_array( $cached ) ) {
        return $cached;
    }

    $result = kounselia_paystack_request( 'GET', '/bank?country=nigeria&currency=NGN' );
    if ( ! $result['ok'] || ! is_array( $result['data'] ) ) {
        return array();
    }

    $banks = array();
    foreach ( $result['data'] as $bank ) {
        if ( empty( $bank['code'] ) || empty( $bank['name'] ) ) {
            continue;
        }
        $banks[] = array( 'code' => $bank['code'], 'name' => $bank['name'] );
    }

    set_transient( 'kounselia_paystack_banks', $banks, DAY_IN_SECONDS );
    return $banks;
}

/**
 * Confirms an account number actually belongs to a real account at that
 * bank, and returns the name on file — shown back to the professional so
 * they can catch a typo before it's saved, never trusted blind.
 */
function kounselia_paystack_resolve_account( $account_number, $bank_code ) {
    $result = kounselia_paystack_request(
        'GET',
        '/bank/resolve?account_number=' . rawurlencode( $account_number ) . '&bank_code=' . rawurlencode( $bank_code )
    );
    if ( ! $result['ok'] || empty( $result['data']['account_name'] ) ) {
        return new WP_Error( 'resolve_failed', $result['message'] ?: 'Could not verify that account number.' );
    }
    return array( 'account_name' => $result['data']['account_name'] );
}

function kounselia_get_payout_account( $professional_id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}kounselia_professional_payout_accounts WHERE professional_id = %d",
        $professional_id
    ) );
}

/**
 * Verifies the account with Paystack, registers it as a "transfer
 * recipient" (the object Paystack actually pays out to), and saves it.
 * Replaces any existing payout account for this professional.
 */
function kounselia_save_payout_account( $professional_id, $bank_code, $bank_name, $account_number ) {
    $resolved = kounselia_paystack_resolve_account( $account_number, $bank_code );
    if ( is_wp_error( $resolved ) ) {
        return $resolved;
    }

    $recipient = kounselia_paystack_request( 'POST', '/transferrecipient', array(
        'type'           => 'nuban',
        'name'           => $resolved['account_name'],
        'account_number' => $account_number,
        'bank_code'      => $bank_code,
        'currency'       => 'NGN',
    ) );
    if ( ! $recipient['ok'] || empty( $recipient['data']['recipient_code'] ) ) {
        return new WP_Error( 'recipient_failed', $recipient['message'] ?: 'Could not save this payout account with our payment provider.' );
    }

    global $wpdb;
    $existing = kounselia_get_payout_account( $professional_id );
    $now      = current_time( 'mysql' );
    $data     = array(
        'professional_id'         => $professional_id,
        'bank_code'               => $bank_code,
        'bank_name'               => $bank_name,
        'account_number'          => $account_number,
        'account_name'            => $resolved['account_name'],
        'paystack_recipient_code' => $recipient['data']['recipient_code'],
        'updated_at'              => $now,
    );

    if ( $existing ) {
        $wpdb->update( $wpdb->prefix . 'kounselia_professional_payout_accounts', $data, array( 'id' => $existing->id ) );
    } else {
        $data['created_at'] = $now;
        $wpdb->insert( $wpdb->prefix . 'kounselia_professional_payout_accounts', $data );
    }

    return array( 'account_name' => $resolved['account_name'] );
}

/* -------------------------------------------------------------------------
 * BALANCE & PAYOUTS
 * ---------------------------------------------------------------------- */

function kounselia_get_unpaid_booking_payments( $professional_id ) {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT id, professional_amount FROM {$wpdb->prefix}kounselia_booking_payments
         WHERE professional_id = %d AND status = 'success' AND payout_id IS NULL",
        $professional_id
    ) );
}

function kounselia_get_professional_balance( $professional_id ) {
    global $wpdb;
    $available = (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(professional_amount),0) FROM {$wpdb->prefix}kounselia_booking_payments
         WHERE professional_id = %d AND status = 'success' AND payout_id IS NULL",
        $professional_id
    ) );
    $total_earned = (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(professional_amount),0) FROM {$wpdb->prefix}kounselia_booking_payments
         WHERE professional_id = %d AND status = 'success'",
        $professional_id
    ) );
    $paid_out = (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}kounselia_payouts
         WHERE professional_id = %d AND status IN ('success','pending')",
        $professional_id
    ) );
    return array( 'available' => $available, 'total_earned' => $total_earned, 'paid_out' => $paid_out );
}

function kounselia_get_payout_history( $professional_id ) {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}kounselia_payouts WHERE professional_id = %d ORDER BY created_at DESC",
        $professional_id
    ) );
}

/**
 * Pays out a professional's entire current available balance in one
 * transfer. The exact set of booking_payments rows behind that balance
 * is snapshotted first and stamped with the resulting payout's id, so
 * the same earnings can never be included in a second payout — even if
 * new sessions get paid for while this one is in flight.
 */
function kounselia_request_payout( $professional_id ) {
    global $wpdb;

    $account = kounselia_get_payout_account( $professional_id );
    if ( ! $account || ! $account->paystack_recipient_code ) {
        return new WP_Error( 'no_account', 'Please add and verify your payout bank account first.' );
    }

    $items = kounselia_get_unpaid_booking_payments( $professional_id );
    if ( empty( $items ) ) {
        return new WP_Error( 'no_balance', 'You have no available balance to pay out yet.' );
    }

    $total = 0.0;
    $ids   = array();
    foreach ( $items as $item ) {
        $total += (float) $item->professional_amount;
        $ids[]  = (int) $item->id;
    }
    if ( $total <= 0 ) {
        return new WP_Error( 'no_balance', 'You have no available balance to pay out yet.' );
    }

    $now = current_time( 'mysql' );
    $wpdb->insert( $wpdb->prefix . 'kounselia_payouts', array(
        'professional_id' => $professional_id,
        'amount'          => $total,
        'currency'        => 'NGN',
        'status'          => 'pending',
        'created_at'      => $now,
        'updated_at'      => $now,
    ) );
    $payout_id = (int) $wpdb->insert_id;
    $reference = 'KOUNSELIA-PAYOUT-' . $payout_id . '-' . time();

    $transfer = kounselia_paystack_request( 'POST', '/transfer', array(
        'source'    => 'balance',
        'amount'    => (int) round( $total * 100 ),
        'recipient' => $account->paystack_recipient_code,
        'reference' => $reference,
        'reason'    => 'Kounselia session earnings payout',
    ) );

    if ( ! $transfer['ok'] ) {
        $wpdb->update( $wpdb->prefix . 'kounselia_payouts', array(
            'status'           => 'failed',
            'failure_reason'   => $transfer['message'] ?: 'Transfer could not be started.',
            'gateway_response' => wp_json_encode( $transfer['data'] ),
            'updated_at'       => current_time( 'mysql' ),
        ), array( 'id' => $payout_id ) );
        return new WP_Error( 'transfer_failed', $transfer['message'] ?: 'Could not start the payout. Please try again shortly.' );
    }

    // Paystack can return this as immediately 'success', or as 'otp' /
    // 'pending' when the account's transfer settings require a one-time
    // code to finalize — that code goes to whoever owns the Paystack
    // account, not the professional, so finishing that step happens
    // outside this app. Either way, Paystack has now accepted the
    // request, so these earnings are spoken for either way.
    $transfer_status = isset( $transfer['data']['status'] ) ? $transfer['data']['status'] : 'pending';
    $wpdb->update( $wpdb->prefix . 'kounselia_payouts', array(
        'status'                 => ( 'success' === $transfer_status ) ? 'success' : 'pending',
        'paystack_transfer_code' => isset( $transfer['data']['transfer_code'] ) ? $transfer['data']['transfer_code'] : null,
        'paystack_reference'     => $reference,
        'gateway_response'       => wp_json_encode( $transfer['data'] ),
        'updated_at'             => current_time( 'mysql' ),
        'completed_at'           => ( 'success' === $transfer_status ) ? current_time( 'mysql' ) : null,
    ), array( 'id' => $payout_id ) );

    $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
    $wpdb->query( $wpdb->prepare(
        "UPDATE {$wpdb->prefix}kounselia_booking_payments SET payout_id = %d WHERE id IN ({$placeholders})",
        array_merge( array( $payout_id ), $ids )
    ) );

    return $payout_id;
}

/* -------------------------------------------------------------------------
 * AJAX
 * ---------------------------------------------------------------------- */

function kounselia_ajax_get_payout_banks() {
    kounselia_verify_nonce();
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.' ), 401 );
    }
    wp_send_json_success( array( 'banks' => kounselia_paystack_list_banks() ) );
}
add_action( 'wp_ajax_kounselia_get_payout_banks', 'kounselia_ajax_get_payout_banks' );

function kounselia_ajax_save_payout_account() {
    kounselia_verify_nonce();
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.' ), 401 );
    }
    if ( kounselia_rate_limited( 'save_payout_account', 10, 3600 ) ) {
        wp_send_json_error( array( 'message' => 'Too many attempts. Please try again later.' ), 429 );
    }

    $application = kounselia_get_professional_application( get_current_user_id() );
    if ( ! $application ) {
        wp_send_json_error( array( 'message' => 'You do not have a professional application on file.' ), 403 );
    }

    $bank_code      = isset( $_POST['bank_code'] ) ? sanitize_text_field( wp_unslash( $_POST['bank_code'] ) ) : '';
    $bank_name      = isset( $_POST['bank_name'] ) ? sanitize_text_field( wp_unslash( $_POST['bank_name'] ) ) : '';
    $account_number = isset( $_POST['account_number'] ) ? preg_replace( '/\D/', '', (string) wp_unslash( $_POST['account_number'] ) ) : '';

    if ( ! $bank_code || strlen( $account_number ) < 10 ) {
        wp_send_json_error( array( 'message' => 'Please choose a bank and enter a valid account number.' ), 400 );
    }

    $result = kounselia_save_payout_account( $application->id, $bank_code, $bank_name, $account_number );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }

    wp_send_json_success( array( 'message' => 'Payout account saved.', 'account_name' => $result['account_name'] ) );
}
add_action( 'wp_ajax_kounselia_save_payout_account', 'kounselia_ajax_save_payout_account' );

function kounselia_ajax_request_payout() {
    kounselia_verify_nonce();
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.' ), 401 );
    }
    if ( kounselia_rate_limited( 'request_payout', 5, 3600 ) ) {
        wp_send_json_error( array( 'message' => 'Too many attempts. Please try again later.' ), 429 );
    }

    $application = kounselia_get_professional_application( get_current_user_id() );
    if ( ! $application ) {
        wp_send_json_error( array( 'message' => 'You do not have a professional application on file.' ), 403 );
    }

    $result = kounselia_request_payout( $application->id );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }

    wp_send_json_success( array( 'message' => 'Payout requested.', 'payout_id' => $result ) );
}
add_action( 'wp_ajax_kounselia_request_payout', 'kounselia_ajax_request_payout' );

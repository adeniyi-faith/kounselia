<?php
/**
 * Kounselia Core — keeping our records in step with Paystack when the
 * browser redirect alone isn't enough.
 *
 *   1. WEBHOOK — Paystack calls /paystack-webhook.php server-to-server
 *      for every charge and transfer event. A payment made by someone who
 *      closed the tab before the redirect finished is confirmed here.
 *
 *   2. PENDING CHARGE SWEEP (hourly) — the same safety net for when the
 *      webhook URL hasn't been set up in Paystack, or a webhook delivery
 *      was missed: re-checks recent 'pending' charges directly.
 *
 *   3. PAYOUT FOLLOW-UP (every 15 min) — re-checks payouts Paystack left
 *      as pending/otp, settles them when Paystack does, returns the money
 *      to the professional's balance if the transfer failed, and emails
 *      admins when one is waiting on an OTP only a human can supply
 *      (finished from the Settings page with kounselia_finalize_payout_otp).
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * 1. WEBHOOK
 * ---------------------------------------------------------------------- */

function kounselia_paystack_webhook_url() {
    return home_url( '/paystack-webhook.php' );
}

/**
 * Returns the HTTP status to answer Paystack with. Anything other than
 * 200 makes Paystack retry, so only a bad signature gets a non-200.
 */
function kounselia_handle_paystack_webhook( $raw_body, $signature ) {
    $secret_key = kounselia_paystack_secret_key();
    if ( ! $secret_key || ! $signature ) {
        return 401;
    }

    $expected = hash_hmac( 'sha512', $raw_body, $secret_key );
    if ( ! hash_equals( $expected, (string) $signature ) ) {
        return 401;
    }

    $event = json_decode( $raw_body, true );
    if ( ! is_array( $event ) || empty( $event['event'] ) ) {
        return 200;
    }

    $data      = isset( $event['data'] ) && is_array( $event['data'] ) ? $event['data'] : array();
    $reference = isset( $data['reference'] ) ? sanitize_text_field( (string) $data['reference'] ) : '';

    switch ( $event['event'] ) {
        case 'charge.success':
            if ( $reference ) {
                kounselia_reconcile_charge_reference( $reference );
            }
            break;

        case 'transfer.success':
        case 'transfer.failed':
        case 'transfer.reversed':
            $payout = kounselia_get_payout_by_reference( $reference );
            if ( $payout ) {
                kounselia_apply_payout_transfer_status( $payout, isset( $data['status'] ) ? (string) $data['status'] : str_replace( 'transfer.', '', $event['event'] ), $data );
            }
            break;
    }

    return 200;
}

/**
 * Routes a charge reference to whichever flow created it. Both
 * completion functions re-verify with Paystack themselves and are safe
 * to call more than once for the same reference.
 */
function kounselia_reconcile_charge_reference( $reference ) {
    global $wpdb;

    $is_subscription = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_payments WHERE reference = %s", $reference
    ) );
    if ( $is_subscription ) {
        return kounselia_complete_subscription_payment( $reference );
    }

    $is_booking = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_booking_payments WHERE reference = %s", $reference
    ) );
    if ( $is_booking ) {
        return kounselia_complete_booking_payment( $reference );
    }

    return null;
}

/* -------------------------------------------------------------------------
 * 2. PENDING CHARGE SWEEP
 * ---------------------------------------------------------------------- */

add_action( 'init', function() {
    if ( ! wp_next_scheduled( 'kounselia_sweep_pending_charges' ) ) {
        wp_schedule_event( time() + 300, 'hourly', 'kounselia_sweep_pending_charges' );
    }
    if ( ! wp_next_scheduled( 'kounselia_follow_up_pending_payouts' ) ) {
        wp_schedule_event( time() + 600, 'kounselia_fifteen_minutes', 'kounselia_follow_up_pending_payouts' );
    }
} );

/**
 * Only ever completes a charge Paystack says succeeded — a checkout
 * that's still open or was abandoned is left alone rather than marked
 * failed, so a slow payer isn't penalised for this job running early.
 */
function kounselia_sweep_pending_charges() {
    if ( ! kounselia_paystack_secret_key() ) {
        return;
    }

    global $wpdb;
    $newest = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 10 * MINUTE_IN_SECONDS );
    $oldest = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - DAY_IN_SECONDS );

    $references = array_merge(
        $wpdb->get_col( $wpdb->prepare(
            "SELECT reference FROM {$wpdb->prefix}kounselia_payments
             WHERE status = 'pending' AND created_at BETWEEN %s AND %s ORDER BY id DESC LIMIT 25",
            $oldest, $newest
        ) ),
        $wpdb->get_col( $wpdb->prepare(
            "SELECT reference FROM {$wpdb->prefix}kounselia_booking_payments
             WHERE status = 'pending' AND created_at BETWEEN %s AND %s ORDER BY id DESC LIMIT 25",
            $oldest, $newest
        ) )
    );

    foreach ( $references as $reference ) {
        $check = kounselia_paystack_request( 'GET', '/transaction/verify/' . rawurlencode( $reference ) );
        if ( $check['ok'] && isset( $check['data']['status'] ) && 'success' === $check['data']['status'] ) {
            kounselia_reconcile_charge_reference( $reference );
        }
    }
}
add_action( 'kounselia_sweep_pending_charges', 'kounselia_sweep_pending_charges' );

/* -------------------------------------------------------------------------
 * 3. PAYOUT FOLLOW-UP
 * ---------------------------------------------------------------------- */

function kounselia_get_payout_by_reference( $reference ) {
    if ( ! $reference ) {
        return null;
    }
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}kounselia_payouts WHERE paystack_reference = %s", $reference
    ) );
}

function kounselia_get_awaiting_payouts() {
    global $wpdb;
    return $wpdb->get_results(
        "SELECT p.*, u.display_name AS professional_name
         FROM {$wpdb->prefix}kounselia_payouts p
         LEFT JOIN {$wpdb->prefix}kounselia_professionals pr ON pr.id = p.professional_id
         LEFT JOIN {$wpdb->users} u ON u.ID = pr.user_id
         WHERE p.status = 'pending'
         ORDER BY p.created_at ASC"
    );
}

/**
 * Moves a payout to wherever Paystack says its transfer now stands.
 * A failed/reversed transfer releases the sessions it covered back into
 * the professional's available balance so they can request it again —
 * the money never left, so it must not look "paid out".
 */
function kounselia_apply_payout_transfer_status( $payout, $transfer_status, $data = array() ) {
    global $wpdb;
    $table = $wpdb->prefix . 'kounselia_payouts';
    $now   = current_time( 'mysql' );

    if ( 'pending' !== $payout->status ) {
        return $payout->status;
    }

    $transfer_status = strtolower( (string) $transfer_status );

    if ( 'success' === $transfer_status ) {
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = 'success', completed_at = %s, updated_at = %s, gateway_response = %s WHERE id = %d AND status = 'pending'",
            $now, $now, wp_json_encode( $data ), $payout->id
        ) );
        if ( $updated ) {
            kounselia_notify_payout_result( $payout, 'success' );
        }
        return 'success';
    }

    if ( in_array( $transfer_status, array( 'failed', 'reversed', 'abandoned', 'blocked', 'rejected' ), true ) ) {
        $reason  = isset( $data['reason'] ) && $data['reason'] ? (string) $data['reason'] : 'Transfer ' . $transfer_status . ' by the payment provider.';
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = 'failed', failure_reason = %s, updated_at = %s, gateway_response = %s WHERE id = %d AND status = 'pending'",
            substr( $reason, 0, 500 ), $now, wp_json_encode( $data ), $payout->id
        ) );
        if ( $updated ) {
            $wpdb->update( $wpdb->prefix . 'kounselia_booking_payments', array( 'payout_id' => null ), array( 'payout_id' => $payout->id ) );
            kounselia_notify_payout_result( $payout, 'failed' );
        }
        return 'failed';
    }

    return 'pending';
}

function kounselia_notify_payout_result( $payout, $result ) {
    if ( ! function_exists( 'kounselia_notify_user' ) || ! function_exists( 'kounselia_get_professional_by_id' ) ) {
        return;
    }
    $professional = kounselia_get_professional_by_id( $payout->professional_id );
    if ( ! $professional || ! $professional->user_id ) {
        return;
    }

    $amount = kounselia_format_money( $payout->amount, $payout->currency );
    if ( 'success' === $result ) {
        kounselia_notify_user( $professional->user_id, 'payout_success', 'Your payout of ' . $amount . ' has been sent', 'It should reach your bank account shortly.', '/pro-dashboard.php#earnings' );
    } else {
        kounselia_notify_user( $professional->user_id, 'payout_failed', 'Your payout of ' . $amount . ' did not go through', 'The amount is back in your available balance — please check your bank details and request it again.', '/pro-dashboard.php#earnings' );
    }
}

function kounselia_follow_up_pending_payouts() {
    if ( ! kounselia_paystack_secret_key() ) {
        return;
    }

    global $wpdb;
    $table   = $wpdb->prefix . 'kounselia_payouts';
    $payouts = $wpdb->get_results(
        "SELECT * FROM {$table} WHERE status = 'pending' AND paystack_reference IS NOT NULL ORDER BY id ASC LIMIT 25"
    );

    $awaiting_otp = array();

    foreach ( $payouts as $payout ) {
        $check = kounselia_paystack_request( 'GET', '/transfer/verify/' . rawurlencode( $payout->paystack_reference ) );

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET check_attempts = check_attempts + 1, last_checked_at = %s WHERE id = %d",
            current_time( 'mysql' ), $payout->id
        ) );

        if ( ! $check['ok'] || empty( $check['data']['status'] ) ) {
            continue;
        }

        $status = kounselia_apply_payout_transfer_status( $payout, $check['data']['status'], $check['data'] );

        if ( 'pending' === $status && 'otp' === strtolower( (string) $check['data']['status'] ) ) {
            $awaiting_otp[] = $payout;
        }
    }

    kounselia_maybe_alert_admins_payout_otp( $awaiting_otp );
}
add_action( 'kounselia_follow_up_pending_payouts', 'kounselia_follow_up_pending_payouts' );

/**
 * One email per payout, not one every 15 minutes: each payout id is
 * remembered once admins have been told about it.
 */
function kounselia_maybe_alert_admins_payout_otp( $payouts ) {
    if ( empty( $payouts ) || ! function_exists( 'kounselia_send_html_email' ) ) {
        return;
    }

    $alerted = get_option( 'kounselia_payout_otp_alerted', array() );
    $alerted = is_array( $alerted ) ? $alerted : array();
    $new     = array();
    foreach ( $payouts as $payout ) {
        if ( ! in_array( (int) $payout->id, $alerted, true ) ) {
            $new[]     = $payout;
            $alerted[] = (int) $payout->id;
        }
    }
    if ( empty( $new ) ) {
        return;
    }
    update_option( 'kounselia_payout_otp_alerted', array_slice( $alerted, -500 ), false );

    $lines = '';
    foreach ( $new as $payout ) {
        $lines .= '<li>Payout #' . (int) $payout->id . ' — ' . esc_html( kounselia_format_money( $payout->amount, $payout->currency ) ) . '</li>';
    }

    $admins = get_users( array( 'role__in' => array( 'administrator' ), 'fields' => array( 'user_email' ) ) );
    foreach ( array_filter( array_unique( wp_list_pluck( $admins, 'user_email' ) ) ) as $email ) {
        kounselia_send_html_email(
            $email,
            'Professional payouts are waiting for your OTP',
            'Payouts need approval',
            '<p>Paystack is holding these payouts until the one-time code it sent to the Paystack account owner is entered:</p><ul>' . $lines . '</ul>'
            . '<p>Enter the code on the <a href="' . esc_url( home_url( '/portal/admin/pages/settings.php#payouts' ) ) . '">admin Settings page</a> (Payouts waiting for approval) to release them.</p>'
        );
    }
}

/**
 * Finishes a transfer Paystack is holding for an OTP. Returns the new
 * payout status, or a WP_Error with Paystack's message.
 */
function kounselia_finalize_payout_otp( $payout_id, $otp ) {
    global $wpdb;
    $payout = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kounselia_payouts WHERE id = %d", $payout_id ) );
    if ( ! $payout || 'pending' !== $payout->status || ! $payout->paystack_transfer_code ) {
        return new WP_Error( 'not_pending', 'That payout is not waiting for approval.' );
    }

    $result = kounselia_paystack_request( 'POST', '/transfer/finalize_transfer', array(
        'transfer_code' => $payout->paystack_transfer_code,
        'otp'           => $otp,
    ) );
    if ( ! $result['ok'] ) {
        return new WP_Error( 'finalize_failed', $result['message'] ?: 'Paystack did not accept that code.' );
    }

    return kounselia_apply_payout_transfer_status( $payout, isset( $result['data']['status'] ) ? $result['data']['status'] : 'pending', $result['data'] );
}

function kounselia_resend_payout_otp( $payout_id ) {
    global $wpdb;
    $payout = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kounselia_payouts WHERE id = %d", $payout_id ) );
    if ( ! $payout || 'pending' !== $payout->status || ! $payout->paystack_transfer_code ) {
        return new WP_Error( 'not_pending', 'That payout is not waiting for approval.' );
    }

    $result = kounselia_paystack_request( 'POST', '/transfer/resend_otp', array(
        'transfer_code' => $payout->paystack_transfer_code,
        'reason'        => 'transfer',
    ) );
    if ( ! $result['ok'] ) {
        return new WP_Error( 'resend_failed', $result['message'] ?: 'Could not resend the code.' );
    }
    return true;
}

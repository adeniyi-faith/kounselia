<?php
/**
 * Kounselia Core — keeping a subscription going, and letting members
 * manage it themselves.
 *
 * Auto-renewal: when a member pays by card, Paystack returns a reusable
 * "authorization" for that card. It's stored on the subscription, and an
 * hourly job charges it (Paystack's charge_authorization) shortly before
 * the period ends. A failed charge is retried up to 3 times, about a day
 * apart, and the member is emailed so they can pay another way. Access
 * never ends early: it lasts until current_period_end either way.
 *
 * Members can: turn auto-renew off (existing cancel in payments.php) and
 * back on, switch plan (takes effect at the next renewal, so nobody pays
 * twice for the same days), remove their saved card, and see their
 * billing history.
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'KOUNSELIA_RENEWAL_MAX_ATTEMPTS', 3 );

/**
 * Subscription columns to store from a Paystack "authorization" object,
 * or an empty array if the card can't be charged again.
 */
function kounselia_subscription_card_fields( $auth ) {
    if ( empty( $auth['authorization_code'] ) || empty( $auth['reusable'] ) ) {
        return array();
    }
    return array(
        'authorization_code' => sanitize_text_field( $auth['authorization_code'] ),
        'card_brand'         => isset( $auth['card_type'] ) ? mb_substr( ucfirst( trim( sanitize_text_field( $auth['card_type'] ) ) ), 0, 32 ) : ( isset( $auth['brand'] ) ? mb_substr( ucfirst( sanitize_text_field( $auth['brand'] ) ), 0, 32 ) : null ),
        'card_last4'         => isset( $auth['last4'] ) ? preg_replace( '/\D/', '', $auth['last4'] ) : null,
        'card_exp'           => ( isset( $auth['exp_month'], $auth['exp_year'] ) ) ? sprintf( '%02d/%s', (int) $auth['exp_month'], substr( (string) $auth['exp_year'], -2 ) ) : null,
    );
}

/**
 * A friendly summary of a member's subscription for the dashboard.
 * 'state' is one of: none, active, renewal_off, payment_problem, ended.
 */
function kounselia_subscription_summary( $user_id ) {
    $sub = kounselia_get_user_subscription( $user_id );
    if ( ! $sub ) {
        return array( 'state' => 'none', 'sub' => null );
    }
    $ends_ts = $sub->current_period_end ? strtotime( $sub->current_period_end ) : 0;
    $live    = $ends_ts > current_time( 'timestamp' );
    if ( ! $live ) {
        $state = 'ended';
    } elseif ( 'cancelled' === $sub->status ) {
        $state = 'renewal_off';
    } elseif ( $sub->renewal_attempts > 0 || 'past_due' === $sub->status ) {
        $state = 'payment_problem';
    } else {
        $state = 'active';
    }
    $next_plan = $sub->pending_plan_id ? kounselia_get_plan( $sub->pending_plan_id ) : null;
    $renew_plan = $next_plan ? $next_plan : kounselia_get_plan( $sub->plan_id );
    return array(
        'state'        => $state,
        'sub'          => $sub,
        'ends'         => $ends_ts,
        'auto_renews'  => 'active' === $sub->status && ! empty( $sub->authorization_code ),
        'has_card'     => ! empty( $sub->authorization_code ),
        'next_plan'    => $next_plan,
        'renew_amount' => ( $renew_plan && function_exists( 'kounselia_plan_price' ) ) ? kounselia_plan_price( $renew_plan, kounselia_subscription_currency( $sub ) ) : (float) $sub->amount,
        'currency'     => kounselia_subscription_currency( $sub ),
    );
}

/* -------------------------------------------------------------------------
 * Renewals
 * ---------------------------------------------------------------------- */

add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'kounselia_process_renewals' ) ) {
        wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', 'kounselia_process_renewals' );
    }
} );
add_action( 'kounselia_process_renewals', 'kounselia_process_renewals' );

/**
 * Charges every subscription that is due (period ends within 6 hours,
 * or already ended while retries remain). Returns how many were tried.
 */
function kounselia_process_renewals() {
    global $wpdb;
    $now = current_time( 'timestamp' );
    $due = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}kounselia_subscriptions
         WHERE status IN ('active','past_due') AND authorization_code IS NOT NULL AND authorization_code != ''
         AND current_period_end <= %s AND current_period_end >= %s
         AND renewal_attempts < %d
         AND (last_renewal_attempt_at IS NULL OR last_renewal_attempt_at <= %s)
         LIMIT 50",
        date( 'Y-m-d H:i:s', $now + 6 * HOUR_IN_SECONDS ),
        date( 'Y-m-d H:i:s', $now - 5 * DAY_IN_SECONDS ),
        KOUNSELIA_RENEWAL_MAX_ATTEMPTS,
        date( 'Y-m-d H:i:s', $now - 20 * HOUR_IN_SECONDS )
    ) );
    foreach ( $due as $sub ) {
        kounselia_renew_subscription( $sub );
    }
    return count( $due );
}

/**
 * The currency a subscription renews in: whatever the member first paid
 * in (naira or dollars — see currency.php), so a renewal never switches
 * currency on them.
 */
function kounselia_subscription_currency( $sub ) {
    $supported = function_exists( 'kounselia_supported_currencies' ) ? kounselia_supported_currencies() : array( 'NGN' );
    return in_array( strtoupper( (string) $sub->currency ), $supported, true ) ? strtoupper( $sub->currency ) : 'NGN';
}

/**
 * Tries to charge one subscription's saved card for the next period.
 * Returns true on success.
 */
function kounselia_renew_subscription( $sub ) {
    global $wpdb;
    $user = get_userdata( $sub->user_id );
    if ( ! $user ) {
        return false;
    }

    $plan_id = $sub->pending_plan_id ? $sub->pending_plan_id : $sub->plan_id;
    $plan    = kounselia_get_plan( $plan_id );
    if ( ! $plan || empty( $plan['is_active'] ) ) {
        // The plan they were switching to was retired: fall back to the current one.
        $plan_id = $sub->plan_id;
        $plan    = kounselia_get_plan( $plan_id );
    }
    $currency = kounselia_subscription_currency( $sub );
    $amount   = $plan ? ( function_exists( 'kounselia_plan_price' ) ? kounselia_plan_price( $plan, $currency ) : (float) $plan['price_amount'] ) : (float) $sub->amount;
    $name     = $plan ? $plan['name'] : $sub->plan_name;

    $now       = current_time( 'mysql' );
    $reference = 'KOUNSELIA-RENEW-' . $sub->user_id . '-' . time() . '-' . wp_generate_password( 6, false );
    $wpdb->insert( $wpdb->prefix . 'kounselia_payments', array(
        'user_id'    => $sub->user_id,
        'plan_id'    => $plan_id,
        'reference'  => $reference,
        'amount'     => $amount,
        'currency'   => $currency,
        'status'     => 'pending',
        'created_at' => $now,
        'updated_at' => $now,
    ) );
    $payment_id = (int) $wpdb->insert_id;

    // Mark the attempt before charging, so an overlapping run can't charge twice.
    $wpdb->update( $wpdb->prefix . 'kounselia_subscriptions', array( 'last_renewal_attempt_at' => $now ), array( 'id' => $sub->id ) );

    $result = kounselia_paystack_request( 'POST', '/transaction/charge_authorization', array(
        'authorization_code' => $sub->authorization_code,
        'email'              => $user->user_email,
        'amount'             => (int) round( $amount * 100 ),
        'currency'           => $currency,
        'reference'          => $reference,
        'metadata'           => array( 'user_id' => $sub->user_id, 'plan_id' => $plan_id, 'renewal' => 1 ),
    ) );

    $paid = $result['ok'] && isset( $result['data']['status'] ) && 'success' === $result['data']['status']
        && (int) ( $result['data']['amount'] ?? 0 ) === (int) round( $amount * 100 );

    if ( $paid ) {
        kounselia_apply_renewal_success( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kounselia_payments WHERE id = %d", $payment_id ) ), $result['data'] );
        return true;
    }

    // Still processing at Paystack (e.g. a bank needs a moment): leave the
    // payment pending — the webhook or the pending-charge sweep finishes it.
    if ( $result['ok'] && isset( $result['data']['status'] ) && in_array( $result['data']['status'], array( 'pending', 'ongoing', 'processing' ), true ) ) {
        return false;
    }

    $wpdb->update( $wpdb->prefix . 'kounselia_payments', array(
        'status'           => 'failed',
        'gateway_response' => wp_json_encode( $result['data'] ),
        'updated_at'       => current_time( 'mysql' ),
    ), array( 'id' => $payment_id ) );

    $attempts = (int) $sub->renewal_attempts + 1;
    $error    = mb_substr( $result['data']['gateway_response'] ?? ( $result['message'] ?: 'The card was declined.' ), 0, 250 );
    $wpdb->update( $wpdb->prefix . 'kounselia_subscriptions', array(
        'status'                  => strtotime( $sub->current_period_end ) <= current_time( 'timestamp' ) ? 'past_due' : $sub->status,
        'renewal_attempts'        => $attempts,
        'last_renewal_attempt_at' => current_time( 'mysql' ),
        'last_renewal_error'      => $error,
        'updated_at'              => current_time( 'mysql' ),
    ), array( 'id' => $sub->id ) );

    // Tell them once, on the first failure, and again when we've stopped trying.
    if ( 1 === $attempts || $attempts >= KOUNSELIA_RENEWAL_MAX_ATTEMPTS ) {
        $final = $attempts >= KOUNSELIA_RENEWAL_MAX_ATTEMPTS;
        kounselia_notify_user( $sub->user_id, 'subscription_payment_failed', "We couldn't renew your plan", $final ? 'Please renew from your dashboard to keep Pro.' : "We'll try again tomorrow.", '/dashboard.php?tab=upgrade', array(
            'subject'      => $final ? 'Your Kounselia Pro membership needs attention' : "We couldn't renew your Kounselia plan",
            'headline'     => "We couldn't take your payment",
            'content_html' => '<p style="margin-bottom:18px;">We tried to renew your ' . esc_html( $name ) . ' plan but the payment didn\'t go through' . ( $error ? ' (' . esc_html( $error ) . ')' : '' ) . '.</p><p>' . ( $final ? 'We won\'t try again. You keep Pro until ' . esc_html( date_i18n( 'F j, Y', strtotime( $sub->current_period_end ) ) ) . ' — renew from your dashboard with any card to continue.' : 'We\'ll try again in about a day. If your card has changed, you can renew with a new one from your dashboard.' ) . '</p>',
            'btn_text'     => 'Update payment',
            'btn_url'      => kounselia_site_url( '/dashboard.php?tab=upgrade' ),
        ) );
    }
    return false;
}

/**
 * Applies a successful renewal payment: extends the subscription by one
 * period from where it ended. Safe to call from several places (our own
 * charge response, Paystack's webhook, the pending-charge sweep): only
 * the first caller to flip the payment to 'success' extends anything.
 */
function kounselia_apply_renewal_success( $payment, $data ) {
    global $wpdb;
    if ( ! $payment ) {
        return false;
    }
    $claimed = $wpdb->query( $wpdb->prepare(
        "UPDATE {$wpdb->prefix}kounselia_payments SET status = 'success', gateway_response = %s, updated_at = %s WHERE id = %d AND status != 'success'",
        wp_json_encode( $data ), current_time( 'mysql' ), $payment->id
    ) );
    if ( ! $claimed ) {
        return false;
    }

    $sub  = kounselia_get_user_subscription( $payment->user_id );
    $plan = kounselia_get_plan( $payment->plan_id );
    if ( ! $sub ) {
        return false;
    }
    $interval = $plan ? $plan['interval'] : 'monthly';
    $name     = $plan ? $plan['name'] : $sub->plan_name;

    // The new period starts where the old one ends (or now, if it had already lapsed).
    $from = max( current_time( 'timestamp' ), strtotime( $sub->current_period_end ) );
    $end  = kounselia_subscription_period_end( $interval, $from );
    $wpdb->update( $wpdb->prefix . 'kounselia_subscriptions', array_merge( array(
        'plan_id'                 => $payment->plan_id,
        'plan_name'               => $name,
        'amount'                  => $payment->amount,
        'currency'                => $payment->currency,
        'status'                  => 'active',
        'paystack_reference'      => $payment->reference,
        'current_period_start'    => date( 'Y-m-d H:i:s', $from ),
        'current_period_end'      => $end,
        'pending_plan_id'         => null,
        'renewal_attempts'        => 0,
        'last_renewal_attempt_at' => current_time( 'mysql' ),
        'last_renewal_error'      => null,
        'updated_at'              => current_time( 'mysql' ),
    ), kounselia_subscription_card_fields( isset( $data['authorization'] ) ? $data['authorization'] : array() ) ), array( 'id' => $sub->id ) );

    kounselia_notify_user( $payment->user_id, 'subscription_renewed', 'Your ' . $name . ' plan renewed', 'Thank you — your membership continues until ' . date_i18n( 'F j, Y', strtotime( $end ) ) . '.', '/dashboard.php?tab=upgrade', array(
        'subject'      => 'Your Kounselia ' . $name . ' plan has renewed',
        'headline'     => 'Thank you for staying with us',
        'content_html' => '<p style="margin-bottom:18px;">We charged ' . esc_html( kounselia_money( $payment->amount, $payment->currency ) ) . ' to your ' . esc_html( trim( $sub->card_brand . ' card ending ' . $sub->card_last4 ) ) . '. Your ' . esc_html( $name ) . ' membership now runs until <strong>' . esc_html( date_i18n( 'F j, Y', strtotime( $end ) ) ) . '</strong>.</p><p>You can change plan, turn off auto-renew or remove your card any time from your dashboard.</p>',
        'btn_text'     => 'Manage my plan',
        'btn_url'      => kounselia_site_url( '/dashboard.php?tab=upgrade' ),
    ) );
    return true;
}

/**
 * Finishes a renewal reported by Paystack's webhook or found pending by
 * the sweep: verifies it with Paystack first (never trusting the report
 * alone), then applies it once.
 */
function kounselia_complete_renewal_reference( $reference ) {
    global $wpdb;
    $payment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kounselia_payments WHERE reference = %s", $reference ) );
    if ( ! $payment ) {
        return array( 'success' => false, 'message' => 'Unknown payment reference.' );
    }
    if ( 'success' === $payment->status ) {
        return array( 'success' => true, 'message' => 'Payment already confirmed.' );
    }
    $result = kounselia_paystack_request( 'GET', '/transaction/verify/' . rawurlencode( $reference ) );
    $ok     = $result['ok'] && 'success' === ( $result['data']['status'] ?? '' )
        && (int) ( $result['data']['amount'] ?? 0 ) === (int) round( (float) $payment->amount * 100 )
        && strtoupper( (string) ( $result['data']['currency'] ?? $payment->currency ) ) === strtoupper( $payment->currency );
    if ( ! $ok ) {
        return array( 'success' => false, 'message' => 'Renewal payment not confirmed.' );
    }
    kounselia_apply_renewal_success( $payment, $result['data'] );
    return array( 'success' => true, 'message' => 'Renewal confirmed.' );
}

function kounselia_money( $amount, $currency ) {
    if ( function_exists( 'kounselia_format_money' ) && in_array( $currency, array( 'NGN', 'USD' ), true ) ) {
        return kounselia_format_money( $amount, $currency );
    }
    $symbols = array( 'NGN' => '₦', 'USD' => '$', 'GBP' => '£', 'EUR' => '€', 'GHS' => 'GH₵', 'KES' => 'KSh ', 'ZAR' => 'R' );
    $symbol  = isset( $symbols[ $currency ] ) ? $symbols[ $currency ] : $currency . ' ';
    return $symbol . number_format_i18n( (float) $amount, ( (float) $amount == (int) $amount ) ? 0 : 2 );
}

/* -------------------------------------------------------------------------
 * Member actions (dashboard → My plan)
 * ---------------------------------------------------------------------- */

function kounselia_subscription_member_guard() {
    kounselia_verify_nonce();
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.' ), 401 );
    }
    $sub = kounselia_get_user_subscription( get_current_user_id() );
    if ( ! $sub ) {
        wp_send_json_error( array( 'message' => "You don't have a subscription yet." ), 400 );
    }
    return $sub;
}

/** Turn auto-renew back on. */
function kounselia_ajax_resume_subscription() {
    global $wpdb;
    $sub = kounselia_subscription_member_guard();
    if ( strtotime( $sub->current_period_end ) <= current_time( 'timestamp' ) ) {
        wp_send_json_error( array( 'message' => 'Your plan has already ended — choose a plan below to subscribe again.' ), 400 );
    }
    if ( empty( $sub->authorization_code ) ) {
        wp_send_json_error( array( 'message' => "We don't have a card saved to renew with. You can renew with any card when your current period ends." ), 400 );
    }
    $wpdb->update( $wpdb->prefix . 'kounselia_subscriptions', array(
        'status'           => 'active',
        'cancelled_at'     => null,
        'renewal_attempts' => 0,
        'updated_at'       => current_time( 'mysql' ),
    ), array( 'id' => $sub->id ) );
    wp_send_json_success( array( 'message' => 'Auto-renew is back on. Your plan will renew on ' . date_i18n( 'F j, Y', strtotime( $sub->current_period_end ) ) . '.' ) );
}
add_action( 'wp_ajax_kounselia_resume_subscription', 'kounselia_ajax_resume_subscription' );

/** Switch plan from the next renewal (no double charge for the current period). */
function kounselia_ajax_switch_subscription_plan() {
    global $wpdb;
    $sub     = kounselia_subscription_member_guard();
    $plan_id = isset( $_POST['plan_id'] ) ? sanitize_key( $_POST['plan_id'] ) : '';
    $plan    = kounselia_get_plan( $plan_id );
    if ( ! $plan || empty( $plan['is_active'] ) ) {
        wp_send_json_error( array( 'message' => 'That plan is not available.' ), 400 );
    }
    if ( strtotime( $sub->current_period_end ) <= current_time( 'timestamp' ) ) {
        wp_send_json_error( array( 'message' => 'Your plan has ended — subscribe to the new plan below instead.' ), 400 );
    }
    $pending = $plan_id === $sub->plan_id ? null : $plan_id;
    $wpdb->update( $wpdb->prefix . 'kounselia_subscriptions', array( 'pending_plan_id' => $pending, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $sub->id ) );
    $when = date_i18n( 'F j, Y', strtotime( $sub->current_period_end ) );
    wp_send_json_success( array( 'message' => $pending
        ? 'Done — you\'ll move to ' . $plan['name'] . ' (' . kounselia_money( $plan['price_amount'], $plan['currency'] ) . ') on ' . $when . '. Nothing changes until then.'
        : 'You\'ll stay on ' . $plan['name'] . '.' ) );
}
add_action( 'wp_ajax_kounselia_switch_subscription_plan', 'kounselia_ajax_switch_subscription_plan' );

/** Forget the saved card (which also turns auto-renew off). */
function kounselia_ajax_remove_subscription_card() {
    global $wpdb;
    $sub = kounselia_subscription_member_guard();
    $wpdb->update( $wpdb->prefix . 'kounselia_subscriptions', array(
        'authorization_code' => null,
        'card_brand'         => null,
        'card_last4'         => null,
        'card_exp'           => null,
        'status'             => 'cancelled',
        'cancelled_at'       => $sub->cancelled_at ? $sub->cancelled_at : current_time( 'mysql' ),
        'updated_at'         => current_time( 'mysql' ),
    ), array( 'id' => $sub->id ) );
    wp_send_json_success( array( 'message' => 'Card removed and auto-renew turned off. You keep Pro until ' . date_i18n( 'F j, Y', strtotime( $sub->current_period_end ) ) . '.' ) );
}
add_action( 'wp_ajax_kounselia_remove_subscription_card', 'kounselia_ajax_remove_subscription_card' );

/**
 * A member's recent payments, newest first (for Billing history).
 */
function kounselia_get_billing_history( $user_id, $limit = 24 ) {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}kounselia_payments WHERE user_id = %d AND status IN ('success','failed') ORDER BY created_at DESC LIMIT %d",
        $user_id,
        $limit
    ) );
}

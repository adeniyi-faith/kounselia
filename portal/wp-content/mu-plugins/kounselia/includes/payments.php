<?php
/**
 * Kounselia Core — Paystack subscriptions.
 *
 * Plans (name, price, features) are admin-configured content, stored as
 * a single wp_option ('kounselia_plans') the same way kounselia_counselors
 * is — there's no need for a database table just to hold a handful of
 * pricing tiers an admin edits from portal/admin/pages/plans.php.
 *
 * A user's subscription is different: it's a per-user relational fact
 * that changes based on real payment events, so it gets its own table
 * (kounselia_subscriptions, one row per user) plus a full transaction
 * log (kounselia_payments) — see schema.php.
 *
 * Checkout flow: kounselia_ajax_init_subscription_payment starts a
 * Paystack transaction and returns the checkout URL; the browser is
 * redirected there, Paystack redirects back to /subscription-callback.php
 * with a reference, and kounselia_complete_subscription_payment() verifies
 * that reference directly against the Paystack API (never trusting
 * anything the redirect URL itself claims) before activating the plan.
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * PLANS (admin-configured content)
 * ---------------------------------------------------------------------- */

function kounselia_default_plans() {
    return array(
        'pro-monthly' => array(
            'id'          => 'pro-monthly',
            'name'        => 'Pro',
            'price_amount'=> 4999,
            'currency'    => 'NGN',
            'interval'    => 'monthly',
            'features'    => array(
                'Deep session memory across visits',
                'Structured 30 day programs',
                'Priority access to all counselors',
                '15+ minute voice calls',
            ),
            'is_active'   => 1,
            'is_popular'  => 1,
            'sort_order'  => 1,
        ),
    );
}

/**
 * All configured plans, keyed by plan id, sorted for display.
 */
function kounselia_get_plans( $active_only = false ) {
    $plans = get_option( 'kounselia_plans', null );
    if ( ! is_array( $plans ) ) {
        $plans = kounselia_default_plans();
    }

    uasort( $plans, function ( $a, $b ) {
        return ( (int) ( $a['sort_order'] ?? 0 ) ) <=> ( (int) ( $b['sort_order'] ?? 0 ) );
    } );

    if ( $active_only ) {
        $plans = array_filter( $plans, function ( $p ) {
            return ! empty( $p['is_active'] );
        } );
    }

    return $plans;
}

function kounselia_get_plan( $plan_id ) {
    $plans = kounselia_get_plans();
    return isset( $plans[ $plan_id ] ) ? $plans[ $plan_id ] : null;
}

/* -------------------------------------------------------------------------
 * SUBSCRIPTION STATE
 * ---------------------------------------------------------------------- */

function kounselia_get_user_subscription( $user_id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}kounselia_subscriptions WHERE user_id = %d",
        $user_id
    ) );
}

/**
 * True if the user currently has paid access — a cancelled subscription
 * still counts until its paid-for period actually runs out.
 */
function kounselia_user_is_pro( $user_id ) {
    $sub = kounselia_get_user_subscription( $user_id );
    if ( ! $sub || ! $sub->current_period_end ) {
        return false;
    }
    return strtotime( $sub->current_period_end ) > current_time( 'timestamp' );
}

function kounselia_subscription_period_end( $interval, $from_timestamp ) {
    $modifier = ( 'yearly' === $interval ) ? '+1 year' : '+1 month';
    return date( 'Y-m-d H:i:s', strtotime( $modifier, $from_timestamp ) );
}

/* -------------------------------------------------------------------------
 * PAYSTACK API
 * ---------------------------------------------------------------------- */

/**
 * Paystack public key (pk_test_/pk_live_). Safe to show in the browser;
 * enables the pop-up checkout. Optional.
 */
function kounselia_paystack_public_key() {
    $key = get_option( 'kounselia_paystack_public_key', '' );
    if ( ! $key && defined( 'KOUNSELIA_PAYSTACK_PUBLIC_KEY' ) ) {
        $key = KOUNSELIA_PAYSTACK_PUBLIC_KEY;
    }
    return $key;
}

function kounselia_paystack_secret_key() {
    $key = get_option( 'kounselia_paystack_secret_key', '' );
    if ( $key ) {
        return $key;
    }
    return defined( 'KOUNSELIA_PAYSTACK_SECRET_KEY' ) ? KOUNSELIA_PAYSTACK_SECRET_KEY : '';
}

/**
 * Minimal wrapper around Paystack's REST API. $method is 'GET' or 'POST'.
 * Returns array( 'ok' => bool, 'data' => array|null, 'message' => string ).
 */
function kounselia_paystack_request( $method, $endpoint, $body = null ) {
    $secret_key = kounselia_paystack_secret_key();
    if ( ! $secret_key ) {
        return array( 'ok' => false, 'data' => null, 'message' => 'Payments are not configured yet.' );
    }

    $args = array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $secret_key,
            'Content-Type'  => 'application/json',
        ),
        'timeout' => 20,
    );

    $url = 'https://api.paystack.co' . $endpoint;

    if ( 'POST' === $method ) {
        $args['body'] = wp_json_encode( $body );
        $response     = wp_remote_post( $url, $args );
    } else {
        $response = wp_remote_get( $url, $args );
    }

    if ( is_wp_error( $response ) ) {
        return array( 'ok' => false, 'data' => null, 'message' => $response->get_error_message() );
    }

    $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
    $status  = wp_remote_retrieve_response_code( $response );

    if ( $status >= 200 && $status < 300 && ! empty( $decoded['status'] ) ) {
        return array( 'ok' => true, 'data' => isset( $decoded['data'] ) ? $decoded['data'] : null, 'message' => isset( $decoded['message'] ) ? $decoded['message'] : '' );
    }

    return array( 'ok' => false, 'data' => null, 'message' => isset( $decoded['message'] ) ? $decoded['message'] : 'Payment request failed.' );
}

/* -------------------------------------------------------------------------
 * CHECKOUT
 * ---------------------------------------------------------------------- */

function kounselia_ajax_init_subscription_payment() {
    kounselia_verify_nonce();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.' ), 401 );
    }

    if ( kounselia_rate_limited( 'init_subscription_payment', 10, 600 ) ) {
        wp_send_json_error( array( 'message' => 'Too many attempts. Please try again shortly.' ), 429 );
    }

    $plan_id = isset( $_POST['plan_id'] ) ? sanitize_key( $_POST['plan_id'] ) : '';
    $plan    = kounselia_get_plan( $plan_id );

    if ( ! $plan || empty( $plan['is_active'] ) ) {
        wp_send_json_error( array( 'message' => 'That plan is not available right now.' ), 400 );
    }

    $user      = wp_get_current_user();
    $amount    = (float) $plan['price_amount'];
    $reference = 'KOUNSELIA-' . $user->ID . '-' . time() . '-' . wp_generate_password( 6, false );

    $callback_url = home_url( '/subscription-callback.php' );

    // With a public key saved, checkout opens as a Paystack pop-up right on
    // the dashboard. The amount is still verified server-side against this
    // pending payment row afterwards, so the browser can't change the price.
    $public_key = kounselia_paystack_public_key();
    if ( $public_key && kounselia_paystack_secret_key() && empty( $_POST['redirect'] ) ) {
        global $wpdb;
        $now = current_time( 'mysql' );
        $wpdb->insert( $wpdb->prefix . 'kounselia_payments', array(
            'user_id'    => $user->ID,
            'plan_id'    => $plan_id,
            'reference'  => $reference,
            'amount'     => $amount,
            'currency'   => $plan['currency'],
            'status'     => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ) );
        wp_send_json_success( array(
            'mode'         => 'inline',
            'key'          => $public_key,
            'email'        => $user->user_email,
            'amount'       => (int) round( $amount * 100 ),
            'currency'     => $plan['currency'],
            'reference'    => $reference,
            'metadata'     => array( 'user_id' => $user->ID, 'plan_id' => $plan_id ),
            'callback_url' => $callback_url,
        ) );
    }

    $result = kounselia_paystack_request( 'POST', '/transaction/initialize', array(
        'email'        => $user->user_email,
        'amount'       => (int) round( $amount * 100 ), // Paystack expects kobo.
        'currency'     => $plan['currency'],
        'reference'    => $reference,
        'callback_url' => $callback_url,
        'metadata'     => array( 'user_id' => $user->ID, 'plan_id' => $plan_id ),
    ) );

    if ( ! $result['ok'] || empty( $result['data']['authorization_url'] ) ) {
        wp_send_json_error( array( 'message' => $result['message'] ?: 'Could not start checkout, please try again.' ), 502 );
    }

    global $wpdb;
    $now = current_time( 'mysql' );
    $wpdb->insert( $wpdb->prefix . 'kounselia_payments', array(
        'user_id'    => $user->ID,
        'plan_id'    => $plan_id,
        'reference'  => $reference,
        'amount'     => $amount,
        'currency'   => $plan['currency'],
        'status'     => 'pending',
        'created_at' => $now,
        'updated_at' => $now,
    ) );

    wp_send_json_success( array( 'authorization_url' => $result['data']['authorization_url'] ) );
}
add_action( 'wp_ajax_kounselia_init_subscription_payment', 'kounselia_ajax_init_subscription_payment' );

/**
 * Verifies a Paystack reference against Paystack's own API (the source
 * of truth — never the redirect query string) and, if it really did
 * succeed, activates the matching plan for the user who started it.
 * Called from /subscription-callback.php after Paystack redirects back.
 */
function kounselia_complete_subscription_payment( $reference ) {
    global $wpdb;
    $payments_table = $wpdb->prefix . 'kounselia_payments';

    $payment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$payments_table} WHERE reference = %s", $reference ) );
    if ( ! $payment ) {
        return array( 'success' => false, 'message' => 'Unknown payment reference.' );
    }

    // Already processed (e.g. the user refreshed the callback page) — treat as a repeat success, not an error.
    if ( 'success' === $payment->status ) {
        return array( 'success' => true, 'message' => 'Payment already confirmed.' );
    }

    $result = kounselia_paystack_request( 'GET', '/transaction/verify/' . rawurlencode( $reference ) );

    if ( ! $result['ok'] || empty( $result['data']['status'] ) || 'success' !== $result['data']['status'] ) {
        $wpdb->update( $payments_table, array(
            'status'           => 'failed',
            'gateway_response' => wp_json_encode( $result['data'] ),
            'updated_at'       => current_time( 'mysql' ),
        ), array( 'id' => $payment->id ) );
        return array( 'success' => false, 'message' => $result['message'] ?: 'Payment was not successful.' );
    }

    // Verified amount must match what we asked for — guards against a
    // tampered client-side amount ever mattering (we never trusted it
    // for the charge itself, but this keeps the receipt honest too).
    $verified_kobo = (int) ( $result['data']['amount'] ?? 0 );
    $expected_kobo = (int) round( (float) $payment->amount * 100 );
    if ( $verified_kobo !== $expected_kobo ) {
        $wpdb->update( $payments_table, array(
            'status'           => 'failed',
            'gateway_response' => wp_json_encode( $result['data'] ),
            'updated_at'       => current_time( 'mysql' ),
        ), array( 'id' => $payment->id ) );
        return array( 'success' => false, 'message' => 'Payment amount mismatch.' );
    }

    $plan = kounselia_get_plan( $payment->plan_id );
    $now  = current_time( 'mysql' );

    $wpdb->update( $payments_table, array(
        'status'           => 'success',
        'gateway_response' => wp_json_encode( $result['data'] ),
        'updated_at'       => $now,
    ), array( 'id' => $payment->id ) );

    $period_end = kounselia_subscription_period_end( $plan['interval'] ?? 'monthly', current_time( 'timestamp' ) );
    $sub_data   = array(
        'user_id'                => $payment->user_id,
        'plan_id'                => $payment->plan_id,
        'plan_name'              => $plan['name'] ?? $payment->plan_id,
        'status'                 => 'active',
        'amount'                 => $payment->amount,
        'currency'               => $payment->currency,
        'paystack_reference'     => $reference,
        'paystack_customer_code' => $result['data']['customer']['customer_code'] ?? null,
        'current_period_start'   => $now,
        'current_period_end'     => $period_end,
        'cancelled_at'           => null,
        'pending_plan_id'        => null,
        'renewal_attempts'       => 0,
        'last_renewal_error'     => null,
        'updated_at'             => $now,
    );
    // A reusable card lets the subscription renew by itself (see subscriptions.php).
    if ( function_exists( 'kounselia_subscription_card_fields' ) ) {
        $sub_data = array_merge( $sub_data, kounselia_subscription_card_fields( $result['data']['authorization'] ?? array() ) );
    }

    $existing = kounselia_get_user_subscription( $payment->user_id );
    if ( $existing ) {
        $wpdb->update( $wpdb->prefix . 'kounselia_subscriptions', $sub_data, array( 'user_id' => $payment->user_id ) );
    } else {
        $sub_data['created_at'] = $now;
        $wpdb->insert( $wpdb->prefix . 'kounselia_subscriptions', $sub_data );
    }

    $user = get_userdata( $payment->user_id );
    if ( $user && function_exists( 'kounselia_send_html_email' ) ) {
        kounselia_send_html_email(
            $user->user_email,
            'Your Kounselia ' . ( $plan['name'] ?? 'Pro' ) . ' subscription is active',
            'Payment confirmed',
            '<p>Thanks for subscribing to ' . esc_html( $plan['name'] ?? 'Pro' ) . '. Your access is active until ' . esc_html( date( 'F j, Y', strtotime( $period_end ) ) ) . '.</p>'
        );
    }

    return array( 'success' => true, 'message' => 'Payment confirmed.', 'plan' => $plan );
}

/**
 * Stops future renewal. Access is left in place until the period the
 * user already paid for actually ends — kounselia_user_is_pro() checks
 * current_period_end, not status, for exactly that reason.
 */
function kounselia_ajax_cancel_subscription() {
    kounselia_verify_nonce();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.' ), 401 );
    }

    $user_id = get_current_user_id();
    $sub     = kounselia_get_user_subscription( $user_id );

    if ( ! $sub || 'cancelled' === $sub->status ) {
        wp_send_json_error( array( 'message' => 'No active subscription to cancel.' ), 400 );
    }

    global $wpdb;
    $wpdb->update( $wpdb->prefix . 'kounselia_subscriptions', array(
        'status'       => 'cancelled',
        'cancelled_at' => current_time( 'mysql' ),
        'updated_at'   => current_time( 'mysql' ),
    ), array( 'user_id' => $user_id ) );

    wp_send_json_success( array( 'message' => 'Subscription cancelled. You will keep access until your current period ends.' ) );
}
add_action( 'wp_ajax_kounselia_cancel_subscription', 'kounselia_ajax_cancel_subscription' );

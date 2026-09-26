<?php
/**
 * Money that Paystack has taken must end up recorded here even when the
 * browser redirect never happens, payouts must not sit "pending" forever,
 * and visitors must be charged in the currency they were shown. Paystack
 * itself is faked with the pre_http_request filter.
 */
class Test_Payment_Reconciliation extends WP_UnitTestCase {

    private $paystack = array();

    public function set_up() {
        parent::set_up();
        update_option( 'kounselia_paystack_secret_key', 'sk_test_secret' );
        $this->paystack = array();
        add_filter( 'pre_http_request', array( $this, 'fake_paystack' ), 10, 3 );
    }

    public function tear_down() {
        remove_filter( 'pre_http_request', array( $this, 'fake_paystack' ), 10 );
        unset( $_SERVER['HTTP_CF_IPCOUNTRY'] );
        parent::tear_down();
    }

    /** Responds to any Paystack URL containing a registered fragment. */
    public function fake_paystack( $pre, $args, $url ) {
        if ( false === strpos( $url, 'api.paystack.co' ) ) {
            return $pre;
        }
        foreach ( $this->paystack as $fragment => $data ) {
            if ( false !== strpos( $url, $fragment ) ) {
                return array(
                    'headers'  => array(),
                    'body'     => wp_json_encode( array( 'status' => true, 'message' => 'ok', 'data' => $data ) ),
                    'response' => array( 'code' => 200, 'message' => 'OK' ),
                    'cookies'  => array(),
                    'filename' => null,
                );
            }
        }
        return new WP_Error( 'unexpected', 'Unexpected Paystack call: ' . $url );
    }

    private function pending_subscription_payment( $user_id, $reference, $amount = 4999, $currency = 'NGN' ) {
        global $wpdb;
        $now = current_time( 'mysql' );
        $wpdb->insert( $wpdb->prefix . 'kounselia_payments', array(
            'user_id' => $user_id, 'plan_id' => 'pro-monthly', 'reference' => $reference, 'amount' => $amount,
            'currency' => $currency, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
        ) );
    }

    private function signed_webhook( $event ) {
        $body = wp_json_encode( $event );
        return kounselia_handle_paystack_webhook( $body, hash_hmac( 'sha512', $body, 'sk_test_secret' ) );
    }

    function test_webhook_rejects_a_bad_signature() {
        $body = wp_json_encode( array( 'event' => 'charge.success', 'data' => array( 'reference' => 'x' ) ) );
        $this->assertSame( 401, kounselia_handle_paystack_webhook( $body, 'forged' ) );
        $this->assertSame( 401, kounselia_handle_paystack_webhook( $body, '' ) );
    }

    function test_webhook_activates_a_subscription_when_the_redirect_never_came() {
        $user = self::factory()->user->create();
        $this->pending_subscription_payment( $user, 'REF-SUB-1' );
        $this->paystack['/transaction/verify/REF-SUB-1'] = array( 'status' => 'success', 'amount' => 499900, 'currency' => 'NGN' );

        $this->assertSame( 200, $this->signed_webhook( array( 'event' => 'charge.success', 'data' => array( 'reference' => 'REF-SUB-1' ) ) ) );

        $this->assertTrue( kounselia_user_is_pro( $user ) );
        global $wpdb;
        $this->assertSame( 'success', $wpdb->get_var( "SELECT status FROM {$wpdb->prefix}kounselia_payments WHERE reference = 'REF-SUB-1'" ) );
    }

    function test_repeat_completion_does_not_process_twice() {
        $user = self::factory()->user->create();
        $this->pending_subscription_payment( $user, 'REF-SUB-2' );
        $this->paystack['/transaction/verify/REF-SUB-2'] = array( 'status' => 'success', 'amount' => 499900, 'currency' => 'NGN' );

        $first  = kounselia_complete_subscription_payment( 'REF-SUB-2' );
        $second = kounselia_complete_subscription_payment( 'REF-SUB-2' );

        $this->assertSame( 'Payment confirmed.', $first['message'] );
        $this->assertSame( 'Payment already confirmed.', $second['message'] );
    }

    function test_currency_mismatch_is_refused() {
        $user = self::factory()->user->create();
        $this->pending_subscription_payment( $user, 'REF-SUB-3', 4.99, 'USD' );
        $this->paystack['/transaction/verify/REF-SUB-3'] = array( 'status' => 'success', 'amount' => 499, 'currency' => 'NGN' );

        $result = kounselia_complete_subscription_payment( 'REF-SUB-3' );
        $this->assertFalse( $result['success'] );
        $this->assertFalse( kounselia_user_is_pro( $user ) );
    }

    private function pending_payout_with_items( $reference ) {
        global $wpdb;
        $now = current_time( 'mysql' );
        $wpdb->insert( $wpdb->prefix . 'kounselia_payouts', array(
            'professional_id' => 7, 'amount' => 8500, 'currency' => 'NGN', 'status' => 'pending',
            'paystack_transfer_code' => 'TRF_x', 'paystack_reference' => $reference, 'created_at' => $now, 'updated_at' => $now,
        ) );
        $payout_id = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'kounselia_booking_payments', array(
            'booking_id' => 900 + $payout_id, 'client_user_id' => 1, 'professional_id' => 7, 'amount' => 10000, 'currency' => 'NGN',
            'platform_fee_amount' => 1500, 'professional_amount' => 8500, 'reference' => 'REF-BP-' . $payout_id, 'status' => 'success',
            'payout_id' => $payout_id, 'created_at' => $now, 'updated_at' => $now,
        ) );
        return $payout_id;
    }

    function test_failed_transfer_returns_money_to_the_available_balance() {
        $payout_id = $this->pending_payout_with_items( 'PAYOUT-REF-1' );
        $this->assertSame( 0.0, kounselia_get_professional_balance( 7 )['available'] );

        $this->signed_webhook( array( 'event' => 'transfer.failed', 'data' => array( 'reference' => 'PAYOUT-REF-1', 'status' => 'failed', 'reason' => 'Account closed' ) ) );

        global $wpdb;
        $this->assertSame( 'failed', $wpdb->get_var( "SELECT status FROM {$wpdb->prefix}kounselia_payouts WHERE id = {$payout_id}" ) );
        $this->assertSame( 8500.0, kounselia_get_professional_balance( 7 )['available'] );
    }

    function test_follow_up_job_settles_a_pending_payout() {
        $payout_id = $this->pending_payout_with_items( 'PAYOUT-REF-2' );
        $this->paystack['/transfer/verify/PAYOUT-REF-2'] = array( 'status' => 'success' );

        kounselia_follow_up_pending_payouts();

        global $wpdb;
        $row = $wpdb->get_row( "SELECT status, completed_at, check_attempts FROM {$wpdb->prefix}kounselia_payouts WHERE id = {$payout_id}" );
        $this->assertSame( 'success', $row->status );
        $this->assertNotEmpty( $row->completed_at );
        $this->assertSame( '1', $row->check_attempts );
    }

    function test_follow_up_job_emails_admins_once_about_an_otp_payout() {
        $this->pending_payout_with_items( 'PAYOUT-REF-3' );
        $this->paystack['/transfer/verify/PAYOUT-REF-3'] = array( 'status' => 'otp' );
        self::factory()->user->create( array( 'role' => 'administrator' ) );
        reset_phpmailer_instance();

        kounselia_follow_up_pending_payouts();
        $this->assertNotEmpty( tests_retrieve_phpmailer_instance()->get_sent() );

        reset_phpmailer_instance();
        kounselia_follow_up_pending_payouts();
        $this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );
    }

    function test_currency_follows_the_visitor_country_only_when_the_header_is_trusted() {
        update_option( 'kounselia_geo_lookup_enabled', 0 );
        update_option( 'kounselia_currency_unknown_default', 'USD' );
        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'NG';

        update_option( 'kounselia_trust_country_header', 0 );
        $this->assertSame( 'USD', kounselia_viewer_currency() );

        update_option( 'kounselia_trust_country_header', 1 );
        $this->assertSame( 'NGN', kounselia_viewer_currency() );

        $_SERVER['HTTP_CF_IPCOUNTRY'] = 'GB';
        $this->assertSame( 'USD', kounselia_viewer_currency() );
    }

    function test_forced_currency_mode_and_plan_prices() {
        update_option( 'kounselia_currency_mode', 'USD' );
        update_option( 'kounselia_usd_ngn_rate', 1600 );
        $this->assertSame( 'USD', kounselia_viewer_currency() );

        $this->assertSame( 4.99, kounselia_plan_price( array( 'price_amount' => 4999, 'price_usd' => 4.99 ), 'USD' ) );
        $this->assertSame( 5.0, kounselia_plan_price( array( 'price_amount' => 8000, 'price_usd' => 0 ), 'USD' ) );
        $this->assertSame( 8000.0, kounselia_plan_price( array( 'price_amount' => 8000, 'price_usd' => 9 ), 'NGN' ) );
        $this->assertSame( '$12.50', kounselia_format_money( 12.5, 'USD' ) );
        $this->assertSame( '₦15,000', kounselia_format_money( 15000, 'NGN' ) );
    }

    function test_dollar_booking_charges_dollars_but_pays_the_professional_in_naira() {
        update_option( 'kounselia_currency_mode', 'USD' );
        update_option( 'kounselia_usd_ngn_rate', 1500 );
        update_option( 'kounselia_booking_commission_percent', 15 );

        global $wpdb;
        $now      = current_time( 'mysql' );
        $pro_user = self::factory()->user->create();
        $client   = self::factory()->user->create();
        $wpdb->insert( $wpdb->prefix . 'kounselia_professionals', array(
            'user_id' => $pro_user, 'title' => 'Therapist', 'rate_amount' => 30000, 'rate_currency' => 'NGN',
            'status' => 'verified', 'submitted_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ) );
        $pro_id = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'kounselia_bookings', array(
            'professional_id' => $pro_id, 'client_user_id' => $client, 'scheduled_start' => $now, 'scheduled_end' => $now,
            'status' => 'pending_payment', 'created_at' => $now, 'updated_at' => $now,
        ) );
        $booking_id = (int) $wpdb->insert_id;

        $this->paystack['/transaction/initialize'] = array( 'authorization_url' => 'https://checkout.paystack.com/x' );
        $this->assertSame( 'https://checkout.paystack.com/x', kounselia_init_booking_payment( $booking_id ) );

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kounselia_booking_payments WHERE booking_id = %d", $booking_id ) );
        $this->assertSame( 'USD', $row->currency );
        $this->assertEquals( 20.00, (float) $row->amount );
        $this->assertEquals( 25500, (float) $row->professional_amount );
        $this->assertSame( 'NGN', $row->payout_currency );
    }
}

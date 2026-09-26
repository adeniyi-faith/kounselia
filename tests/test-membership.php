<?php
/**
 * Pro benefits, subscription management and auto-renewal. Paystack is
 * never contacted: its API is faked with the pre_http_request filter.
 */
class Test_Membership extends WP_Ajax_UnitTestCase {

    private $paystack_calls = array();
    private $paystack_reply = null;

    public function set_up() {
        parent::set_up();
        reset_phpmailer_instance();
        update_option( 'kounselia_paystack_secret_key', 'sk_test_fake' );
        update_option( 'kounselia_currency_mode', 'NGN' ); // No visitor location in tests.
        delete_option( 'kounselia_paystack_public_key' );
        delete_option( 'kounselia_plan_benefits' );
        update_option( 'kounselia_plans', array(
            'pro-monthly' => array( 'id' => 'pro-monthly', 'name' => 'Pro', 'price_amount' => 5000, 'currency' => 'NGN', 'interval' => 'monthly', 'features' => array(), 'is_active' => 1, 'is_popular' => 1, 'sort_order' => 1 ),
            'pro-yearly'  => array( 'id' => 'pro-yearly', 'name' => 'Pro Yearly', 'price_amount' => 50000, 'currency' => 'NGN', 'interval' => 'yearly', 'features' => array(), 'is_active' => 1, 'is_popular' => 0, 'sort_order' => 2 ),
        ) );
        $this->paystack_calls = array();
        add_filter( 'pre_http_request', array( $this, 'fake_paystack' ), 10, 3 );
    }

    public function tear_down() {
        remove_filter( 'pre_http_request', array( $this, 'fake_paystack' ), 10 );
        parent::tear_down();
    }

    public function fake_paystack( $pre, $args, $url ) {
        if ( false === strpos( $url, 'api.paystack.co' ) ) {
            return $pre;
        }
        $body                   = isset( $args['body'] ) ? json_decode( $args['body'], true ) : null;
        $this->paystack_calls[] = array( 'url' => $url, 'body' => $body );
        $reply                  = $this->paystack_reply ? call_user_func( $this->paystack_reply, $url, $body ) : array( 'status' => true, 'data' => array() );
        return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( $reply ), 'headers' => array(), 'cookies' => array() );
    }

    private function subscription( $user_id, $overrides = array() ) {
        global $wpdb;
        $now = current_time( 'timestamp' );
        $wpdb->insert( $wpdb->prefix . 'kounselia_subscriptions', array_merge( array(
            'user_id'              => $user_id,
            'plan_id'              => 'pro-monthly',
            'plan_name'            => 'Pro',
            'status'               => 'active',
            'amount'               => 5000,
            'currency'             => 'NGN',
            'authorization_code'   => 'AUTH_abc',
            'card_brand'           => 'Visa',
            'card_last4'           => '4081',
            'current_period_start' => date( 'Y-m-d H:i:s', $now - 29 * DAY_IN_SECONDS ),
            'current_period_end'   => date( 'Y-m-d H:i:s', $now + HOUR_IN_SECONDS ),
            'created_at'           => date( 'Y-m-d H:i:s', $now ),
            'updated_at'           => date( 'Y-m-d H:i:s', $now ),
        ), $overrides ) );
        return kounselia_get_user_subscription( $user_id );
    }

    private function ajax( $action, $post = array() ) {
        $_POST = array_merge( array( 'action' => $action, 'nonce' => wp_create_nonce( 'kounselia_auth' ) ), $post );
        try {
            $this->_handleAjax( $action );
        } catch ( WPAjaxDieContinueException $e ) {
            // Expected.
        }
        $response            = json_decode( $this->_last_response, true );
        $this->_last_response = '';
        return $response;
    }

    function test_paying_subscriber_gets_pro_benefits() {
        $free = self::factory()->user->create();
        $paid = self::factory()->user->create();
        $gift = self::factory()->user->create();
        $this->subscription( $paid );
        update_user_meta( $gift, 'kounselia_plan', 'pro' );

        $this->assertFalse( kounselia_member_is_pro( $free ) );
        $this->assertTrue( kounselia_member_is_pro( $paid ), 'A paying subscriber must be Pro (this was broken before).' );
        $this->assertTrue( kounselia_member_is_pro( $gift ) );

        $this->assertSame( 5 * 60, kounselia_voice_allowed_seconds( $free ) );
        $this->assertSame( 15 * 60, kounselia_voice_allowed_seconds( $paid ) );
        $this->assertSame( 16, (int) kounselia_member_benefit( $free, 'memory_messages' ) );
        $this->assertSame( 40, (int) kounselia_member_benefit( $paid, 'memory_messages' ) );
    }

    function test_expired_subscription_is_not_pro() {
        $user = self::factory()->user->create();
        $this->subscription( $user, array( 'current_period_end' => date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - HOUR_IN_SECONDS ) ) );
        $this->assertFalse( kounselia_member_is_pro( $user ) );
    }

    function test_pro_discount_comes_out_of_commission_only() {
        $pro = self::factory()->user->create();
        update_user_meta( $pro, 'kounselia_plan', 'pro' );

        $p = kounselia_member_session_price( $pro, 10000, 15 );
        $this->assertEquals( 9000, $p['charged'] );
        $this->assertEquals( 500, $p['platform_fee'] );
        $this->assertEquals( 8500, $p['professional_amount'], 'The professional is paid the same as without a discount.' );

        $capped = kounselia_member_session_price( $pro, 10000, 5 );
        $this->assertEquals( 9500, $capped['charged'], 'Discount never exceeds the commission.' );
        $this->assertEquals( 9500, $capped['professional_amount'] );

        $free = kounselia_member_session_price( self::factory()->user->create(), 10000, 15 );
        $this->assertEquals( 10000, $free['charged'] );
    }

    function test_free_members_get_one_reflection_a_month() {
        $user = self::factory()->user->create();
        wp_set_current_user( $user );
        $this->assertSame( 1, kounselia_reflection_allowance( $user )['remaining'] );
        kounselia_reflection_record_use( $user );

        $response = $this->ajax( 'kounselia_generate_reflection' );
        $this->assertFalse( $response['success'] );
        $this->assertTrue( $response['data']['upgrade'] );

        update_user_meta( $user, 'kounselia_plan', 'pro' );
        $this->assertNull( kounselia_reflection_allowance( $user )['remaining'], 'Pro is unlimited.' );
    }

    function test_renewal_charges_saved_card_and_extends_from_period_end() {
        $user = self::factory()->user->create();
        $sub  = $this->subscription( $user, array( 'pending_plan_id' => 'pro-yearly' ) );
        $this->paystack_reply = function ( $url, $body ) {
            return array( 'status' => true, 'data' => array( 'status' => 'success', 'amount' => $body['amount'], 'reference' => $body['reference'], 'authorization' => array( 'authorization_code' => 'AUTH_abc', 'reusable' => true, 'last4' => '4081', 'card_type' => 'visa' ) ) );
        };

        $this->assertSame( 1, kounselia_process_renewals() );

        $call = end( $this->paystack_calls );
        $this->assertStringContainsString( '/transaction/charge_authorization', $call['url'] );
        $this->assertSame( 'AUTH_abc', $call['body']['authorization_code'] );
        $this->assertSame( 5000000, $call['body']['amount'], 'Renews on the plan the member switched to (yearly).' );

        $after = kounselia_get_user_subscription( $user );
        $this->assertSame( 'pro-yearly', $after->plan_id );
        $this->assertNull( $after->pending_plan_id );
        $this->assertSame( 0, (int) $after->renewal_attempts );
        $this->assertSame( date( 'Y-m-d', strtotime( '+1 year', strtotime( $sub->current_period_end ) ) ), substr( $after->current_period_end, 0, 10 ), 'New period starts where the old one ended.' );

        $this->assertSame( 0, kounselia_process_renewals(), 'Not charged twice.' );
    }

    function test_renewal_reported_by_webhook_is_applied_only_once() {
        $user = self::factory()->user->create();
        $sub  = $this->subscription( $user );
        $this->paystack_reply = function ( $url, $body ) {
            // The charge response and the later verify call both say "success".
            return array( 'status' => true, 'data' => array( 'status' => 'success', 'amount' => 500000, 'currency' => 'NGN', 'authorization' => array() ) );
        };
        kounselia_process_renewals();
        $after_charge = kounselia_get_user_subscription( $user )->current_period_end;

        global $wpdb;
        $reference = $wpdb->get_var( "SELECT reference FROM {$wpdb->prefix}kounselia_payments WHERE reference LIKE 'KOUNSELIA-RENEW-%' ORDER BY id DESC LIMIT 1" );
        $result    = kounselia_reconcile_charge_reference( $reference ); // What the Paystack webhook does.

        $this->assertTrue( $result['success'] );
        $this->assertSame( $after_charge, kounselia_get_user_subscription( $user )->current_period_end, 'The webhook must not extend the plan a second time.' );
        $this->assertSame( date( 'Y-m-d', strtotime( '+1 month', strtotime( $sub->current_period_end ) ) ), substr( $after_charge, 0, 10 ) );
    }

    function test_renewal_charges_in_the_currency_the_member_pays_in() {
        $user = self::factory()->user->create();
        $this->subscription( $user, array( 'currency' => 'USD', 'amount' => 4.99 ) );
        $plans = get_option( 'kounselia_plans' );
        $plans['pro-monthly']['price_usd'] = 4.99;
        update_option( 'kounselia_plans', $plans );
        $this->paystack_reply = function ( $url, $body ) {
            return array( 'status' => true, 'data' => array( 'status' => 'success', 'amount' => $body['amount'], 'authorization' => array() ) );
        };
        kounselia_process_renewals();
        $call = end( $this->paystack_calls );
        $this->assertSame( 'USD', $call['body']['currency'] );
        $this->assertSame( 499, $call['body']['amount'] );
    }

    function test_failed_renewal_is_recorded_and_member_is_emailed() {
        $user = self::factory()->user->create( array( 'user_email' => 'renew@example.org' ) );
        $this->subscription( $user );
        $this->paystack_reply = function () {
            return array( 'status' => true, 'data' => array( 'status' => 'failed', 'gateway_response' => 'Insufficient Funds' ) );
        };
        kounselia_process_renewals();

        $after = kounselia_get_user_subscription( $user );
        $this->assertSame( 1, (int) $after->renewal_attempts );
        $this->assertSame( 'Insufficient Funds', $after->last_renewal_error );
        $this->assertTrue( kounselia_member_is_pro( $user ), 'Access continues until the paid period ends.' );
        $this->assertSame( 'payment_problem', kounselia_subscription_summary( $user )['state'] );

        $mail = tests_retrieve_phpmailer_instance()->get_sent();
        $this->assertSame( 'renew@example.org', $mail->to[0][0] );

        $this->assertSame( 0, kounselia_process_renewals(), 'Retries wait about a day.' );
    }

    function test_cancelled_or_cardless_subscriptions_never_renew() {
        $this->subscription( self::factory()->user->create(), array( 'status' => 'cancelled' ) );
        $this->subscription( self::factory()->user->create(), array( 'authorization_code' => null ) );
        $this->assertSame( 0, kounselia_process_renewals() );
        $this->assertSame( array(), $this->paystack_calls );
    }

    function test_member_can_switch_plan_resume_and_remove_card() {
        $user = self::factory()->user->create();
        wp_set_current_user( $user );
        $this->subscription( $user, array( 'status' => 'cancelled' ) );

        $r = $this->ajax( 'kounselia_switch_subscription_plan', array( 'plan_id' => 'pro-yearly' ) );
        $this->assertTrue( $r['success'] );
        $this->assertSame( 'pro-yearly', kounselia_get_user_subscription( $user )->pending_plan_id );

        $r = $this->ajax( 'kounselia_resume_subscription' );
        $this->assertTrue( $r['success'] );
        $this->assertSame( 'active', kounselia_get_user_subscription( $user )->status );

        $r = $this->ajax( 'kounselia_remove_subscription_card' );
        $this->assertTrue( $r['success'] );
        $after = kounselia_get_user_subscription( $user );
        $this->assertNull( $after->authorization_code );
        $this->assertSame( 'cancelled', $after->status );

        $r = $this->ajax( 'kounselia_resume_subscription' );
        $this->assertFalse( $r['success'], "Can't turn auto-renew on without a card." );
    }

    function test_popup_checkout_when_public_key_is_set() {
        $user = self::factory()->user->create();
        wp_set_current_user( $user );
        update_option( 'kounselia_paystack_public_key', 'pk_test_fake' );

        $r = $this->ajax( 'kounselia_init_subscription_payment', array( 'plan_id' => 'pro-monthly' ) );
        $this->assertTrue( $r['success'] );
        $this->assertSame( 'inline', $r['data']['mode'] );
        $this->assertSame( 'pk_test_fake', $r['data']['key'] );
        $this->assertSame( 500000, $r['data']['amount'] );
        $this->assertSame( array(), $this->paystack_calls, 'Pop-up checkout needs no server call to Paystack up front.' );

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kounselia_payments WHERE reference = %s", $r['data']['reference'] ) );
        $this->assertSame( 'pending', $row->status );
        $this->assertEquals( 5000, $row->amount, 'The price is fixed server-side and checked again on verify.' );
    }

    function test_saved_card_only_if_reusable() {
        $this->assertSame( array(), kounselia_subscription_card_fields( array( 'authorization_code' => 'X', 'reusable' => false ) ) );
        $f = kounselia_subscription_card_fields( array( 'authorization_code' => 'AUTH_1', 'reusable' => true, 'last4' => '1234', 'card_type' => 'visa ', 'exp_month' => '9', 'exp_year' => '2030' ) );
        $this->assertSame( 'AUTH_1', $f['authorization_code'] );
        $this->assertSame( '09/30', $f['card_exp'] );
        $this->assertSame( 'Visa', $f['card_brand'] );
    }
}

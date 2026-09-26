<?php
/**
 * Email delivery routing: default mailer vs Brevo, the daily allowance,
 * the reserve for account emails, and falling back when Brevo fails.
 * Brevo's API is faked; nothing leaves the machine.
 */
class Test_Mail_Delivery extends WP_UnitTestCase {

    private $brevo_calls = array();
    private $brevo_code  = 201;

    public function set_up() {
        parent::set_up();
        reset_phpmailer_instance();
        $this->brevo_calls = array();
        $this->brevo_code  = 201;
        add_filter( 'pre_http_request', array( $this, 'fake_brevo' ), 10, 3 );
        update_option( 'kounselia_mail_settings', array(
            'account_provider'    => 'brevo',
            'newsletter_provider' => 'brevo',
            'brevo_api_key'       => 'xkeysib-test',
            'sender_email'        => 'hello@kounselia.test',
            'sender_name'         => 'Kounselia',
            'brevo_daily_limit'   => 3,
            'account_reserve'     => 1,
            'fallback_to_default' => 1,
        ) );
    }

    public function tear_down() {
        remove_filter( 'pre_http_request', array( $this, 'fake_brevo' ), 10 );
        parent::tear_down();
    }

    public function fake_brevo( $pre, $args, $url ) {
        if ( false === strpos( $url, 'api.brevo.com' ) ) {
            return $pre;
        }
        $this->brevo_calls[] = array( 'url' => $url, 'headers' => $args['headers'], 'body' => json_decode( $args['body'], true ) );
        return array( 'response' => array( 'code' => $this->brevo_code, 'message' => '' ), 'body' => wp_json_encode( 201 === $this->brevo_code ? array( 'messageId' => 'x' ) : array( 'message' => 'Server error' ) ), 'headers' => array(), 'cookies' => array() );
    }

    private function default_mailer_count() {
        return count( tests_retrieve_phpmailer_instance()->mock_sent );
    }

    function test_default_mailer_is_used_when_chosen() {
        update_option( 'kounselia_mail_settings', array_merge( kounselia_mail_settings(), array( 'account_provider' => 'wordpress' ) ) );
        $this->assertTrue( wp_mail( 'a@example.org', 'Hi', 'Body' ) );
        $this->assertSame( 1, $this->default_mailer_count() );
        $this->assertSame( array(), $this->brevo_calls );
        $this->assertSame( 1, kounselia_mail_sent_today( 'wordpress' ) );
    }

    function test_brevo_sends_html_with_unsubscribe_header_and_hides_channel_header() {
        $ok = kounselia_send_html_email( 'reader@example.org', 'News', 'Hello', '<p>Hi</p>', null, null, array(
            'headers' => array( 'X-Kounselia-Channel: newsletter', 'List-Unsubscribe: <https://kounselia.test/u>' ),
        ) );
        $this->assertTrue( $ok );
        $this->assertSame( 0, $this->default_mailer_count() );
        $call = $this->brevo_calls[0];
        $this->assertSame( 'xkeysib-test', $call['headers']['api-key'] );
        $this->assertSame( 'reader@example.org', $call['body']['to'][0]['email'] );
        $this->assertSame( 'hello@kounselia.test', $call['body']['sender']['email'] );
        $this->assertArrayHasKey( 'htmlContent', $call['body'] );
        $this->assertSame( '<https://kounselia.test/u>', $call['body']['headers']['List-Unsubscribe'] );
        $this->assertArrayNotHasKey( 'X-Kounselia-Channel', $call['body']['headers'] );
        $this->assertSame( array( 'kounselia-newsletter' ), $call['body']['tags'] );
    }

    function test_newsletters_stop_short_of_the_reserve_and_account_emails_fall_back() {
        // Limit 3, reserve 1: newsletters may use 2.
        $this->assertSame( 2, kounselia_mail_newsletter_capacity() );
        $news = array( 'headers' => array( 'X-Kounselia-Channel: newsletter' ) );
        kounselia_send_html_email( 'n1@example.org', 'N', 'N', '<p>x</p>', null, null, $news );
        kounselia_send_html_email( 'n2@example.org', 'N', 'N', '<p>x</p>', null, null, $news );
        $this->assertSame( 0, kounselia_mail_newsletter_capacity() );
        $this->assertFalse( kounselia_send_html_email( 'n3@example.org', 'N', 'N', '<p>x</p>', null, null, $news ), 'A third newsletter waits for tomorrow.' );

        // The reserved one is still there for an account email…
        $this->assertTrue( wp_mail( 'member@example.org', 'Your booking', 'Body' ) );
        $this->assertSame( 3, count( $this->brevo_calls ) );
        // …and once the day is used up, account emails go via the default mailer instead of being lost.
        $this->assertTrue( wp_mail( 'member@example.org', 'Password reset', 'Body' ) );
        $this->assertSame( 3, count( $this->brevo_calls ) );
        $this->assertSame( 1, $this->default_mailer_count() );
    }

    function test_newsletter_queue_pauses_when_allowance_is_used() {
        update_option( 'kounselia_mail_settings', array_merge( kounselia_mail_settings(), array( 'brevo_daily_limit' => 2, 'account_reserve' => 1 ) ) );
        foreach ( array( 'a', 'b', 'c' ) as $n ) {
            kounselia_newsletter_upsert( "{$n}@example.org", array() );
        }
        $cid = kounselia_newsletter_save_campaign( array( 'subject' => 'Hello', 'content' => '<p>x</p>' ) );
        kounselia_newsletter_start_campaign( $cid );
        kounselia_newsletter_process_queue( 10 );
        kounselia_newsletter_process_queue( 10 );

        $campaign = kounselia_newsletter_get_campaign( $cid );
        $this->assertSame( 1, (int) $campaign->sent_count, 'Only the newsletter share (limit 2 − reserve 1) is used.' );
        $this->assertSame( 0, (int) $campaign->failed_count, 'The rest wait instead of failing.' );
        $this->assertSame( 'sending', $campaign->status );
    }

    function test_account_email_falls_back_when_brevo_errors() {
        $this->brevo_code = 500;
        $this->assertTrue( wp_mail( 'member@example.org', 'Booking confirmed', 'Body' ) );
        $this->assertSame( 1, $this->default_mailer_count() );
        global $wpdb;
        $this->assertSame( 'failed', $wpdb->get_var( "SELECT status FROM {$wpdb->prefix}kounselia_mail_log WHERE provider = 'brevo' ORDER BY id DESC LIMIT 1" ) );
    }
}

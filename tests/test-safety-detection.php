<?php
/**
 * A person in crisis rarely uses the exact words on a keyword list. The
 * instant check must catch typos, stretched letters and indirect or slang
 * phrasings without flagging everyday figures of speech, and anything it
 * misses must still be read by the background AI risk check. The Gemini
 * API is faked with the pre_http_request filter.
 */
class Test_Safety_Detection extends WP_UnitTestCase {

    private $gemini_reply = null;

    public function set_up() {
        parent::set_up();
        update_option( 'kounselia_gemini_key', 'test-key' );
        delete_transient( 'kounselia_key_cooldowns' );
        delete_transient( 'kounselia_ai_screen_lock' );
        add_filter( 'pre_http_request', array( $this, 'fake_gemini' ), 10, 3 );
    }

    public function tear_down() {
        remove_filter( 'pre_http_request', array( $this, 'fake_gemini' ), 10 );
        parent::tear_down();
    }

    public function fake_gemini( $pre, $args, $url ) {
        if ( false === strpos( $url, 'generativelanguage.googleapis.com' ) ) {
            return $pre;
        }
        if ( null === $this->gemini_reply ) {
            return new WP_Error( 'down', 'AI unavailable' );
        }
        $body = json_decode( $args['body'], true );
        $items = json_decode( $body['contents'][0]['parts'][0]['text'], true );
        $results = array();
        foreach ( $items as $item ) {
            $results[] = array( 'id' => $item['id'], 'risk' => $this->gemini_reply, 'reason' => 'says goodbye indirectly' );
        }
        return array(
            'headers'  => array(),
            'body'     => wp_json_encode( array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => wp_json_encode( array( 'results' => $results ) ) ) ) ) ) ) ) ),
            'response' => array( 'code' => 200, 'message' => 'OK' ),
            'cookies'  => array(),
            'filename' => null,
        );
    }

    public function risky_messages() {
        return array(
            array( "I've been thinking about suicde lately" ),
            array( 'i just want to diiiie' ),
            array( 'Honestly everyone would be better off without me.' ),
            array( "I don't want to wake up tomorrow" ),
            array( 'thinking of how to unalive myself' ),
            array( "i'm gonna kms fr" ),
            array( 'I want to kill myslef' ),
            array( 'I can’t go on like this' ),
        );
    }

    /** @dataProvider risky_messages */
    function test_instant_check_catches_indirect_and_misspelled_risk( $message ) {
        $this->assertNotFalse( kounselia_message_matches_safety_keywords( $message ) );
    }

    public function everyday_messages() {
        return array(
            array( 'I will myself to get up every morning' ),
            array( 'My diet is killing me haha' ),
            array( 'The kids are driving me crazy' ),
            array( 'I feel stuck in my career' ),
        );
    }

    /** @dataProvider everyday_messages */
    function test_instant_check_ignores_everyday_speech( $message ) {
        $this->assertFalse( kounselia_message_matches_safety_keywords( $message ) );
    }

    function test_admin_keyword_stems_still_match_anywhere() {
        kounselia_update_safety_keywords( array_merge( kounselia_get_safety_keywords(), array( 'abus' ) ) );
        $this->assertSame( 'abus', kounselia_message_matches_safety_keywords( 'He has been abusive for years' ) );
    }

    private function new_session() {
        return kounselia_resolve_session( kounselia_counselor_slugs()[0], self::factory()->user->create(), '', 0 );
    }

    function test_ai_check_escalates_what_keywords_missed() {
        $session_id = $this->new_session();
        $message_id = kounselia_log_message( $session_id, 'user', "I've made my peace with everything. This is the last time you'll hear from me." );

        global $wpdb;
        $this->assertSame( '0', $wpdb->get_var( "SELECT ai_screened FROM {$wpdb->prefix}kounselia_messages WHERE id = {$message_id}" ) );

        $this->gemini_reply = 'critical';
        kounselia_run_ai_safety_screening();

        $row = $wpdb->get_row( "SELECT flagged_safety, flag_reason, ai_screened FROM {$wpdb->prefix}kounselia_messages WHERE id = {$message_id}" );
        $this->assertSame( '1', $row->flagged_safety );
        $this->assertSame( '1', $row->ai_screened );
        $this->assertStringStartsWith( 'AI risk check:', $row->flag_reason );

        $escalation = $wpdb->get_row( "SELECT severity, status FROM {$wpdb->prefix}kounselia_safety_escalations WHERE message_id = {$message_id}" );
        $this->assertSame( 'critical', $escalation->severity );
        $this->assertSame( 'open', $escalation->status );
    }

    function test_ai_check_marks_safe_messages_screened_without_flagging() {
        $message_id = kounselia_log_message( $this->new_session(), 'user', 'Work was long but I feel okay.' );

        $this->gemini_reply = 'none';
        kounselia_run_ai_safety_screening();

        global $wpdb;
        $row = $wpdb->get_row( "SELECT flagged_safety, ai_screened FROM {$wpdb->prefix}kounselia_messages WHERE id = {$message_id}" );
        $this->assertSame( '0', $row->flagged_safety );
        $this->assertSame( '1', $row->ai_screened );
    }

    function test_ai_outage_leaves_messages_for_the_next_run() {
        $message_id = kounselia_log_message( $this->new_session(), 'user', 'Not sure how much longer I can keep this up.' );

        $this->gemini_reply = null;
        kounselia_run_ai_safety_screening();

        global $wpdb;
        $this->assertSame( '0', $wpdb->get_var( "SELECT ai_screened FROM {$wpdb->prefix}kounselia_messages WHERE id = {$message_id}" ) );
    }

    function test_keyword_hits_and_counselor_replies_skip_the_ai_check() {
        $session_id = $this->new_session();
        $flagged    = kounselia_log_message( $session_id, 'user', 'I want to end my life' );
        $reply      = kounselia_log_message( $session_id, 'bot', 'I am really glad you told me.' );

        global $wpdb;
        $this->assertSame( '1', $wpdb->get_var( "SELECT ai_screened FROM {$wpdb->prefix}kounselia_messages WHERE id = {$flagged}" ) );
        $this->assertSame( '1', $wpdb->get_var( "SELECT ai_screened FROM {$wpdb->prefix}kounselia_messages WHERE id = {$reply}" ) );
    }
}

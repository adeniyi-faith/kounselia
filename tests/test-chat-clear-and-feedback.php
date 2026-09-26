<?php
/**
 * "Clear chat" must really stop a conversation coming back for the
 * member (while keeping it for staff safety review), and thumbs up/down
 * must be saved for the member's own messages only. Drives the real AJAX
 * handlers end to end.
 */
class Test_Chat_Clear_And_Feedback extends WP_Ajax_UnitTestCase {

    private $slug;

    public function set_up() {
        parent::set_up();
        $slugs      = kounselia_counselor_slugs();
        $this->slug = $slugs[0];
    }

    private function ajax( $action, $params ) {
        $_POST = array_merge( array( 'action' => $action, 'nonce' => wp_create_nonce( 'kounselia_auth' ) ), $params );
        try {
            $this->_handleAjax( $action );
        } catch ( WPAjaxDieContinueException $e ) {
            // Expected — wp_send_json_* dies by design under the ajax test harness.
        } catch ( WPAjaxDieStopException $e ) {
            // Also expected for error responses.
        }
        $response             = json_decode( $this->_last_response, true );
        $this->_last_response = '';
        return $response;
    }

    private function member_session_with_messages( $user_id ) {
        $session_id = kounselia_resolve_session( $this->slug, $user_id, '', 0 );
        kounselia_log_message( $session_id, 'user', 'Work has been a lot this week.' );
        $bot_id = kounselia_log_message( $session_id, 'bot', 'That sounds like a heavy week.' );
        kounselia_log_message( $session_id, 'peer_consult', wp_json_encode( array( 'peer_slug' => 'marcus', 'insight' => 'internal note' ) ) );
        return array( $session_id, $bot_id );
    }

    function test_cleared_chat_does_not_come_back_in_history() {
        $user = self::factory()->user->create();
        wp_set_current_user( $user );
        list( $session_id ) = $this->member_session_with_messages( $user );

        $before = $this->ajax( 'kounselia_get_history', array( 'counselor' => $this->slug ) );
        $this->assertSame( $session_id, $before['data']['session_id'] );

        $cleared = $this->ajax( 'kounselia_clear_chat', array( 'counselor' => $this->slug ) );
        $this->assertTrue( $cleared['success'] );

        $after = $this->ajax( 'kounselia_get_history', array( 'counselor' => $this->slug ) );
        $this->assertSame( 0, $after['data']['session_id'] );
        $this->assertSame( array(), $after['data']['messages'] );
    }

    function test_cleared_chat_is_kept_for_staff_and_cannot_be_resumed() {
        $user = self::factory()->user->create();
        wp_set_current_user( $user );
        list( $session_id ) = $this->member_session_with_messages( $user );

        $this->ajax( 'kounselia_clear_chat', array( 'counselor' => $this->slug ) );

        global $wpdb;
        $this->assertSame( 'cleared', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}kounselia_sessions WHERE id = %d", $session_id ) ) );
        $this->assertSame( '3', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_messages WHERE session_id = %d", $session_id ) ) );

        $this->assertNotSame( $session_id, kounselia_resolve_session( $this->slug, $user, '', $session_id ) );
    }

    function test_guest_can_clear_only_their_own_chat() {
        wp_set_current_user( 0 );
        $mine   = kounselia_resolve_session( $this->slug, 0, 'guest-a', 0 );
        $theirs = kounselia_resolve_session( $this->slug, 0, 'guest-b', 0 );

        $this->ajax( 'kounselia_clear_chat', array( 'counselor' => $this->slug, 'guest_token' => 'guest-a' ) );

        global $wpdb;
        $this->assertSame( 'cleared', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}kounselia_sessions WHERE id = %d", $mine ) ) );
        $this->assertSame( 'active', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}kounselia_sessions WHERE id = %d", $theirs ) ) );
    }

    function test_history_hides_internal_peer_consult_notes() {
        $user = self::factory()->user->create();
        wp_set_current_user( $user );
        $this->member_session_with_messages( $user );

        $history = $this->ajax( 'kounselia_get_history', array( 'counselor' => $this->slug ) );
        $senders = wp_list_pluck( $history['data']['messages'], 'sender' );
        $this->assertSame( array( 'user', 'bot' ), $senders );
    }

    function test_rating_is_saved_replaced_and_returned_with_history() {
        $user = self::factory()->user->create();
        wp_set_current_user( $user );
        list( , $bot_id ) = $this->member_session_with_messages( $user );

        $up = $this->ajax( 'kounselia_rate_message', array( 'message_id' => $bot_id, 'rating' => 'up' ) );
        $this->assertTrue( $up['success'] );
        $this->ajax( 'kounselia_rate_message', array( 'message_id' => $bot_id, 'rating' => 'down' ) );

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT rating FROM {$wpdb->prefix}kounselia_message_feedback WHERE message_id = %d", $bot_id ) );
        $this->assertCount( 1, $rows );
        $this->assertSame( 'down', $rows[0]->rating );

        $history = $this->ajax( 'kounselia_get_history', array( 'counselor' => $this->slug ) );
        $bot     = wp_list_filter( $history['data']['messages'], array( 'id' => $bot_id ) );
        $this->assertSame( 'down', reset( $bot )['rating'] );
    }

    function test_cannot_rate_someone_elses_message() {
        $owner    = self::factory()->user->create();
        $stranger = self::factory()->user->create();
        list( , $bot_id ) = $this->member_session_with_messages( $owner );

        wp_set_current_user( $stranger );
        $response = $this->ajax( 'kounselia_rate_message', array( 'message_id' => $bot_id, 'rating' => 'up' ) );
        $this->assertFalse( $response['success'] );

        global $wpdb;
        $this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_message_feedback WHERE message_id = %d", $bot_id ) ) );
    }
}

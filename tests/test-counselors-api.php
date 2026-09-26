<?php
/**
 * The counselor list the mobile app loads, and the send time now included
 * with each message in a conversation's history.
 */
class Test_Counselors_Api extends WP_Ajax_UnitTestCase {

    public function tear_down() {
        delete_option( 'kounselia_counselors_ui_meta' );
        parent::tear_down();
    }

    private function ajax( $action, $params = array() ) {
        $_POST = array_merge( array( 'action' => $action, 'nonce' => wp_create_nonce( 'kounselia_auth' ) ), $params );
        try {
            $this->_handleAjax( $action );
        } catch ( WPAjaxDieContinueException $e ) {
            // Expected.
        } catch ( WPAjaxDieStopException $e ) {
            // Expected for error responses.
        }
        $response             = json_decode( $this->_last_response, true );
        $this->_last_response = '';
        return $response;
    }

    private function by_slug( $counselors ) {
        return array_column( $counselors, null, 'slug' );
    }

    function test_lists_active_counselors_without_css_prefixes() {
        $res = $this->ajax( 'kounselia_get_counselors' );

        $this->assertTrue( $res['success'] );
        $list = $this->by_slug( $res['data']['counselors'] );
        $this->assertSame( 'Serena', $list['serena']['name'] );
        $this->assertSame( 'heart', $list['serena']['icon'] );
        $this->assertSame( 'rose', $list['serena']['color'] );
        $this->assertFalse( $list['serena']['voice_enabled'] );
        $this->assertTrue( $list['dr_lena']['voice_enabled'] );
        $this->assertArrayNotHasKey( 'system_prompt', $list['serena'] );
    }

    function test_switched_off_counselor_is_left_out() {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'kounselia_counselor_prompts', array( 'is_active' => 0 ), array( 'counselor_slug' => 'marcus' ) );

        $list = $this->by_slug( kounselia_public_counselors() );

        $this->assertArrayNotHasKey( 'marcus', $list );
        $this->assertArrayHasKey( 'serena', $list );
    }

    function test_admin_edits_show_up() {
        update_option( 'kounselia_counselors_ui_meta', array(
            'serena' => array( 'name' => 'Serena R.', 'spec' => 'Feelings', 'desc' => 'x', 'icon' => 'ti-sun', 'class' => 'ic-gold' ),
        ) );

        $list = $this->by_slug( kounselia_public_counselors() );

        $this->assertSame( 'Serena R.', $list['serena']['name'] );
        $this->assertSame( 'sun', $list['serena']['icon'] );
        $this->assertSame( 'gold', $list['serena']['color'] );
    }

    function test_history_messages_carry_their_send_time_in_utc() {
        update_option( 'timezone_string', 'Africa/Lagos' ); // UTC+1, no daylight saving.
        $user = self::factory()->user->create();
        wp_set_current_user( $user );
        $session_id = kounselia_resolve_session( 'serena', $user, '', 0 );
        $message_id = kounselia_log_message( $session_id, 'user', 'Hello' );

        global $wpdb;
        $local = $wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM {$wpdb->prefix}kounselia_messages WHERE id = %d", $message_id ) );
        $res   = $this->ajax( 'kounselia_get_history', array( 'counselor' => 'serena' ) );

        $this->assertTrue( $res['success'] );
        $this->assertSame(
            gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $local . ' UTC' ) - HOUR_IN_SECONDS ),
            $res['data']['messages'][0]['sent_at']
        );
        delete_option( 'timezone_string' );
    }
}

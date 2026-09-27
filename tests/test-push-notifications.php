<?php
/**
 * Push notifications to the mobile app, through Expo's push service.
 */
class Test_Push_Notifications extends WP_Ajax_UnitTestCase {

    private $sent = array();

    public function set_up() {
        parent::set_up();
        $this->sent = array();
        // Catch the request to Expo instead of sending it, and answer the
        // way Expo does: one ticket per message.
        add_filter( 'pre_http_request', array( $this, 'fake_expo' ), 10, 3 );
        unset( $_SERVER['HTTP_X_KOUNSELIA_CLIENT'], $_SERVER['HTTP_X_KOUNSELIA_TOKEN'] );
    }

    public function tear_down() {
        remove_filter( 'pre_http_request', array( $this, 'fake_expo' ), 10 );
        unset( $_SERVER['HTTP_X_KOUNSELIA_CLIENT'], $_SERVER['HTTP_X_KOUNSELIA_TOKEN'] );
        parent::tear_down();
    }

    public function fake_expo( $pre, $args, $url ) {
        if ( KOUNSELIA_EXPO_PUSH_URL !== $url ) {
            return $pre;
        }
        $messages     = json_decode( $args['body'], true );
        $this->sent[] = $messages;
        $tickets      = array();
        foreach ( $messages as $m ) {
            $tickets[] = false !== strpos( $m['to'], 'gone' )
                ? array( 'status' => 'error', 'details' => array( 'error' => 'DeviceNotRegistered' ) )
                : array( 'status' => 'ok', 'id' => 'ticket' );
        }
        return array(
            'headers'  => array(),
            'body'     => wp_json_encode( array( 'data' => $tickets ) ),
            'response' => array( 'code' => 200, 'message' => 'OK' ),
            'cookies'  => array(),
        );
    }

    private function app_ajax( $action, $post, $token ) {
        $_SERVER['HTTP_X_KOUNSELIA_CLIENT'] = 'app';
        $_SERVER['HTTP_X_KOUNSELIA_TOKEN']  = $token;
        $_POST                              = array_merge( array( 'action' => $action ), $post );
        $GLOBALS['current_user']            = null;
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

    private function tokens( $user_id ) {
        global $wpdb;
        return $wpdb->get_col( $wpdb->prepare( "SELECT token FROM {$wpdb->prefix}kounselia_push_tokens WHERE user_id = %d ORDER BY id", $user_id ) );
    }

    function test_nothing_is_sent_to_someone_without_the_app() {
        $user_id = self::factory()->user->create();

        $this->assertFalse( kounselia_send_push_to_user( $user_id, 'Hello', 'There' ) );
        $this->assertCount( 0, $this->sent );
    }

    function test_notify_user_pushes_to_every_registered_phone() {
        $user_id = self::factory()->user->create();
        $token   = kounselia_issue_app_token( $user_id );
        $this->app_ajax( 'kounselia_register_push_token', array( 'platform' => 'ios', 'token' => 'ExponentPushToken[one]' ), $token );
        $this->app_ajax( 'kounselia_register_push_token', array( 'platform' => 'android', 'token' => 'ExponentPushToken[two]' ), $token );

        kounselia_notify_user( $user_id, 'booking_reminder', 'Your session starts soon', 'In 15 minutes', '/dashboard.php#professionals' );

        $this->assertCount( 1, $this->sent );
        $this->assertSame( array( 'ExponentPushToken[one]', 'ExponentPushToken[two]' ), wp_list_pluck( $this->sent[0], 'to' ) );
        $this->assertSame( 'Your session starts soon', $this->sent[0][0]['title'] );
        $this->assertSame( '/dashboard.php#professionals', $this->sent[0][0]['data']['url'] );
    }

    function test_a_phone_the_app_was_removed_from_is_forgotten() {
        global $wpdb;
        $user_id = self::factory()->user->create();
        foreach ( array( 'ExponentPushToken[kept]', 'ExponentPushToken[gone]' ) as $t ) {
            $wpdb->insert( $wpdb->prefix . 'kounselia_push_tokens', array( 'user_id' => $user_id, 'platform' => 'ios', 'token' => $t, 'created_at' => current_time( 'mysql' ) ) );
        }

        kounselia_send_push_to_user( $user_id, 'Hi', 'There' );

        $this->assertSame( array( 'ExponentPushToken[kept]' ), $this->tokens( $user_id ) );
    }

    function test_a_phone_belongs_to_whoever_signed_in_on_it_last() {
        $ada   = self::factory()->user->create();
        $grace = self::factory()->user->create();

        $this->app_ajax( 'kounselia_register_push_token', array( 'platform' => 'ios', 'token' => 'ExponentPushToken[shared]' ), kounselia_issue_app_token( $ada ) );
        $this->app_ajax( 'kounselia_register_push_token', array( 'platform' => 'ios', 'token' => 'ExponentPushToken[shared]' ), kounselia_issue_app_token( $grace ) );

        $this->assertSame( array(), $this->tokens( $ada ) );
        $this->assertSame( array( 'ExponentPushToken[shared]' ), $this->tokens( $grace ) );
    }

    function test_a_phone_signed_out_by_a_password_change_stops_getting_notifications() {
        $user_id = self::factory()->user->create();
        $lost    = kounselia_issue_app_token( $user_id, 'lost phone' );
        $kept    = kounselia_issue_app_token( $user_id, 'new phone' );
        $this->app_ajax( 'kounselia_register_push_token', array( 'platform' => 'ios', 'token' => 'ExponentPushToken[lost]' ), $lost );
        $this->app_ajax( 'kounselia_register_push_token', array( 'platform' => 'ios', 'token' => 'ExponentPushToken[kept]' ), $kept );

        // Changing the password from the new phone signs out every other one.
        kounselia_revoke_app_tokens( $user_id, $kept );

        $this->assertSame( array( 'ExponentPushToken[kept]' ), $this->tokens( $user_id ) );
    }

    function test_turning_notifications_off_or_signing_out_stops_them() {
        $user_id = self::factory()->user->create();
        $token   = kounselia_issue_app_token( $user_id );
        $this->app_ajax( 'kounselia_register_push_token', array( 'platform' => 'ios', 'token' => 'ExponentPushToken[a]' ), $token );
        $this->app_ajax( 'kounselia_register_push_token', array( 'platform' => 'ios', 'token' => 'ExponentPushToken[b]' ), $token );

        $this->app_ajax( 'kounselia_unregister_push_token', array( 'token' => 'ExponentPushToken[a]' ), $token );
        $this->assertSame( array( 'ExponentPushToken[b]' ), $this->tokens( $user_id ) );

        $this->app_ajax( 'kounselia_app_logout', array( 'push_token' => 'ExponentPushToken[b]' ), $token );
        $this->assertSame( array(), $this->tokens( $user_id ) );
    }
}

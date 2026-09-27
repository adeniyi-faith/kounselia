<?php
/**
 * A member deleting their own account from the app, and what that erases.
 */
class Test_Account_Deletion extends WP_Ajax_UnitTestCase {

    public function set_up() {
        parent::set_up();
        unset( $_SERVER['HTTP_X_KOUNSELIA_CLIENT'], $_SERVER['HTTP_X_KOUNSELIA_TOKEN'] );
    }

    public function tear_down() {
        unset( $_SERVER['HTTP_X_KOUNSELIA_CLIENT'], $_SERVER['HTTP_X_KOUNSELIA_TOKEN'] );
        parent::tear_down();
    }

    // Sends an action the way the app does (see test-app-auth.php).
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

    private function member() {
        return self::factory()->user->create( array(
            'user_login' => 'grace@example.com',
            'user_email' => 'grace@example.com',
            'user_pass'  => 'correct-horse',
        ) );
    }

    private function count_rows( $table, $column, $user_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE {$column} = %d", $user_id ) );
    }

    // Gives the member one of each kind of private record.
    private function fill_in( $user_id ) {
        global $wpdb;
        $p   = $wpdb->prefix;
        $now = current_time( 'mysql' );

        $wpdb->insert( "{$p}kounselia_sessions", array( 'user_id' => $user_id, 'counselor_slug' => 'amara', 'started_at' => $now ) );
        $session_id = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}kounselia_messages", array( 'session_id' => $session_id, 'sender' => 'user', 'content' => 'A private thought', 'created_at' => $now ) );
        $wpdb->insert( "{$p}kounselia_mood_logs", array( 'user_id' => $user_id, 'mood' => 'low', 'log_date' => gmdate( 'Y-m-d' ), 'created_at' => $now ) );
        $wpdb->insert( "{$p}kounselia_journal_entries", array( 'user_id' => $user_id, 'content' => 'Dear diary', 'entry_date' => gmdate( 'Y-m-d' ), 'updated_at' => $now ) );
        $wpdb->insert( "{$p}kounselia_notifications", array( 'user_id' => $user_id, 'type' => 'test', 'title' => 'Hi', 'body' => 'Hi', 'created_at' => $now ) );
        $wpdb->insert( "{$p}kounselia_push_tokens", array( 'user_id' => $user_id, 'platform' => 'ios', 'token' => 'ExponentPushToken[abc]', 'created_at' => $now ) );
        $wpdb->insert( "{$p}kounselia_subscriptions", array( 'user_id' => $user_id, 'plan_id' => 'pro', 'plan_name' => 'Pro', 'amount' => 10, 'authorization_code' => 'AUTH_x', 'created_at' => $now, 'updated_at' => $now ) );

        return $session_id;
    }

    function test_member_deletes_their_account_and_everything_private_goes() {
        global $wpdb;
        $user_id    = $this->member();
        $session_id = $this->fill_in( $user_id );
        $token      = kounselia_issue_app_token( $user_id, 'phone' );

        $res = $this->app_ajax( 'kounselia_delete_account', array( 'password' => 'correct-horse' ), $token );

        $this->assertTrue( $res['success'] );
        $this->assertFalse( get_userdata( $user_id ) );
        $this->assertSame( 0, $this->count_rows( 'kounselia_sessions', 'user_id', $user_id ) );
        $this->assertSame( 0, $this->count_rows( 'kounselia_messages', 'session_id', $session_id ) );
        $this->assertSame( 0, $this->count_rows( 'kounselia_mood_logs', 'user_id', $user_id ) );
        $this->assertSame( 0, $this->count_rows( 'kounselia_journal_entries', 'user_id', $user_id ) );
        $this->assertSame( 0, $this->count_rows( 'kounselia_notifications', 'user_id', $user_id ) );
        $this->assertSame( 0, $this->count_rows( 'kounselia_push_tokens', 'user_id', $user_id ) );
        $this->assertSame( 0, $this->count_rows( 'kounselia_subscriptions', 'user_id', $user_id ) );
        $this->assertSame( 0, $this->count_rows( 'kounselia_app_tokens', 'user_id', $user_id ) );
        // The phone's sign-in no longer works.
        $this->assertSame( 0, kounselia_user_id_from_app_token( $token ) );
    }

    function test_wrong_password_deletes_nothing() {
        $user_id = $this->member();
        $this->fill_in( $user_id );
        $token = kounselia_issue_app_token( $user_id );

        $res = $this->app_ajax( 'kounselia_delete_account', array( 'password' => 'guess' ), $token );

        $this->assertFalse( $res['success'] );
        $this->assertNotFalse( get_userdata( $user_id ) );
        $this->assertSame( 1, $this->count_rows( 'kounselia_journal_entries', 'user_id', $user_id ) );
    }

    function test_an_upcoming_session_must_be_cancelled_first() {
        global $wpdb;
        $user_id = $this->member();
        $token   = kounselia_issue_app_token( $user_id );
        $start   = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + DAY_IN_SECONDS );
        $wpdb->insert( $wpdb->prefix . 'kounselia_bookings', array(
            'professional_id' => 1,
            'client_user_id'  => $user_id,
            'scheduled_start' => $start,
            'scheduled_end'   => $start,
            'status'          => 'confirmed',
            'created_at'      => current_time( 'mysql' ),
            'updated_at'      => current_time( 'mysql' ),
        ) );

        $res = $this->app_ajax( 'kounselia_delete_account', array( 'password' => 'correct-horse' ), $token );

        $this->assertFalse( $res['success'] );
        $this->assertStringContainsString( 'cancel', $res['data']['message'] );
        $this->assertNotFalse( get_userdata( $user_id ) );
    }

    function test_a_professional_is_asked_to_contact_us() {
        global $wpdb;
        $user_id = $this->member();
        $token   = kounselia_issue_app_token( $user_id );
        $wpdb->insert( $wpdb->prefix . 'kounselia_professionals', array(
            'user_id'      => $user_id,
            'title'        => 'Therapist',
            'submitted_at' => current_time( 'mysql' ),
            'created_at'   => current_time( 'mysql' ),
            'updated_at'   => current_time( 'mysql' ),
        ) );

        $res = $this->app_ajax( 'kounselia_delete_account', array( 'password' => 'correct-horse' ), $token );

        $this->assertFalse( $res['success'] );
        $this->assertStringContainsString( 'professional', $res['data']['message'] );
        $this->assertNotFalse( get_userdata( $user_id ) );
    }
}

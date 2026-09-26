<?php
/**
 * Mobile app sign-in: tokens instead of cookie + nonce.
 */
class Test_App_Auth extends WP_Ajax_UnitTestCase {

    public function set_up() {
        parent::set_up();
        unset( $_SERVER['HTTP_X_KOUNSELIA_CLIENT'], $_SERVER['HTTP_X_KOUNSELIA_TOKEN'], $_SERVER['HTTP_AUTHORIZATION'] );
    }

    public function tear_down() {
        unset( $_SERVER['HTTP_X_KOUNSELIA_CLIENT'], $_SERVER['HTTP_X_KOUNSELIA_TOKEN'], $_SERVER['HTTP_AUTHORIZATION'] );
        parent::tear_down();
    }

    // Sends an action the way the app does: app header, token if given, no nonce.
    private function app_ajax( $action, $post = array(), $token = '' ) {
        $_SERVER['HTTP_X_KOUNSELIA_CLIENT'] = 'app';
        if ( $token ) {
            $_SERVER['HTTP_X_KOUNSELIA_TOKEN'] = $token;
        } else {
            unset( $_SERVER['HTTP_X_KOUNSELIA_TOKEN'] );
        }
        $_POST = array_merge( array( 'action' => $action ), $post );
        // Forget who was signed in so WordPress works it out again from
        // this request, as it would on a real one.
        $GLOBALS['current_user'] = null;
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

    private function token_rows( $user_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kounselia_app_tokens WHERE user_id = %d", $user_id ) );
    }

    private function member( $password = 'correct-horse' ) {
        return self::factory()->user->create( array(
            'user_login'   => 'ada@example.com',
            'user_email'   => 'ada@example.com',
            'user_pass'    => $password,
            'display_name' => 'Ada',
        ) );
    }

    function test_login_returns_token_and_stores_only_its_hash() {
        $user_id = $this->member();

        $res = $this->app_ajax( 'kounselia_app_login', array( 'email' => 'ada@example.com', 'password' => 'correct-horse', 'device_name' => 'Ada’s iPhone' ) );

        $this->assertTrue( $res['success'] );
        $this->assertSame( 64, strlen( $res['data']['token'] ) );
        $this->assertSame( 'Ada', $res['data']['user']['name'] );
        $rows = $this->token_rows( $user_id );
        $this->assertCount( 1, $rows );
        $this->assertSame( hash( 'sha256', $res['data']['token'] ), $rows[0]->token_hash );
        $this->assertNotSame( $res['data']['token'], $rows[0]->token_hash );
    }

    function test_login_with_wrong_password_is_refused() {
        $user_id = $this->member();

        $res = $this->app_ajax( 'kounselia_app_login', array( 'email' => 'ada@example.com', 'password' => 'nope' ) );

        $this->assertFalse( $res['success'] );
        $this->assertCount( 0, $this->token_rows( $user_id ) );
    }

    function test_token_signs_the_member_in_without_a_nonce() {
        $user_id = $this->member();
        $token   = kounselia_issue_app_token( $user_id, 'test' );

        $res = $this->app_ajax( 'kounselia_app_me', array(), $token );

        $this->assertTrue( $res['success'] );
        $this->assertSame( $user_id, $res['data']['user']['id'] );
    }

    function test_bearer_header_also_works() {
        $user_id                       = $this->member();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . kounselia_issue_app_token( $user_id );

        $this->assertSame( $user_id, apply_filters( 'determine_current_user', 0 ) );
    }

    function test_unknown_token_means_signed_out() {
        $res = $this->app_ajax( 'kounselia_app_me', array(), str_repeat( 'a', 64 ) );

        $this->assertFalse( $res['success'] );
        $this->assertTrue( $res['data']['signed_out'] );
    }

    function test_app_requests_ignore_cookies_but_website_requests_keep_them() {
        $user_id = $this->member();

        // A website request: whoever the cookie check found stays signed in.
        $this->assertSame( $user_id, kounselia_determine_app_user( $user_id ) );

        // An app request without a token is signed out, cookie or not.
        $_SERVER['HTTP_X_KOUNSELIA_CLIENT'] = 'app';
        $this->assertSame( 0, kounselia_determine_app_user( $user_id ) );
    }

    function test_website_requests_still_need_a_nonce() {
        $user_id = $this->member();
        wp_set_current_user( $user_id );
        $_POST = array( 'action' => 'kounselia_app_me' );
        try {
            $this->_handleAjax( 'kounselia_app_me' );
        } catch ( WPAjaxDieContinueException $e ) {
            // Expected.
        }
        $res = json_decode( $this->_last_response, true );

        $this->assertFalse( $res['success'] );
        $this->assertStringContainsString( 'Security check failed', $res['data']['message'] );
    }

    function test_expired_token_is_refused_and_removed() {
        global $wpdb;
        $user_id = $this->member();
        $token   = kounselia_issue_app_token( $user_id );
        $wpdb->update( $wpdb->prefix . 'kounselia_app_tokens', array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'user_id' => $user_id ) );

        $this->assertSame( 0, kounselia_user_id_from_app_token( $token ) );
        $this->assertCount( 0, $this->token_rows( $user_id ) );
    }

    function test_logout_signs_out_only_this_phone() {
        $user_id = $this->member();
        $phone   = kounselia_issue_app_token( $user_id, 'phone' );
        $tablet  = kounselia_issue_app_token( $user_id, 'tablet' );

        $res = $this->app_ajax( 'kounselia_app_logout', array(), $phone );

        $this->assertTrue( $res['success'] );
        $this->assertSame( 0, kounselia_user_id_from_app_token( $phone ) );
        $this->assertSame( $user_id, kounselia_user_id_from_app_token( $tablet ) );
    }

    function test_password_change_signs_out_other_phones() {
        $user_id = $this->member();
        $phone   = kounselia_issue_app_token( $user_id, 'phone' );
        $tablet  = kounselia_issue_app_token( $user_id, 'tablet' );

        $res = $this->app_ajax( 'kounselia_update_password', array( 'current_password' => 'correct-horse', 'new_password' => 'a-new-password' ), $phone );

        $this->assertTrue( $res['success'] );
        $this->assertSame( $user_id, kounselia_user_id_from_app_token( $phone ) );
        $this->assertSame( 0, kounselia_user_id_from_app_token( $tablet ) );
    }

    function test_register_creates_member_and_signs_them_in() {
        $res = $this->app_ajax( 'kounselia_app_register', array( 'name' => 'Grace Hopper', 'email' => 'grace@example.com', 'password' => 'long-enough' ) );

        $this->assertTrue( $res['success'] );
        $user = get_user_by( 'email', 'grace@example.com' );
        $this->assertSame( 'Grace Hopper', $user->display_name );
        $this->assertEquals( 1, get_user_meta( $user->ID, 'kounselia_is_new_user', true ) );
        $this->assertSame( $user->ID, kounselia_user_id_from_app_token( $res['data']['token'] ) );
    }

    function test_register_refuses_an_email_already_in_use() {
        $this->member();

        $res = $this->app_ajax( 'kounselia_app_register', array( 'email' => 'ada@example.com', 'password' => 'long-enough' ) );

        $this->assertFalse( $res['success'] );
        $this->assertSame( 'That email is already registered.', $res['data']['message'] );
    }

    function test_banned_or_deleted_members_are_signed_out_of_the_app() {
        $banned  = $this->member();
        $token   = kounselia_issue_app_token( $banned );
        update_user_meta( $banned, 'kounselia_banned', 1 );
        $this->assertSame( 0, kounselia_user_id_from_app_token( $token ) );
        $this->assertCount( 0, $this->token_rows( $banned ) );

        $deleted = self::factory()->user->create();
        $token   = kounselia_issue_app_token( $deleted );
        update_user_meta( $deleted, 'kounselia_deleted_at', current_time( 'mysql' ) );
        $this->assertSame( 0, kounselia_user_id_from_app_token( $token ) );
    }

    function test_failed_sign_ins_are_limited_per_email_not_per_address() {
        $this->member();
        for ( $i = 0; $i < 5; $i++ ) {
            $this->app_ajax( 'kounselia_app_login', array( 'email' => 'ada@example.com', 'password' => 'nope' ) );
        }
        $locked = $this->app_ajax( 'kounselia_app_login', array( 'email' => 'ada@example.com', 'password' => 'correct-horse' ) );
        $this->assertFalse( $locked['success'] );

        // Someone else on the same (shared) internet address still gets in.
        self::factory()->user->create( array( 'user_login' => 'bola@example.com', 'user_email' => 'bola@example.com', 'user_pass' => 'another-pass' ) );
        $other = $this->app_ajax( 'kounselia_app_login', array( 'email' => 'bola@example.com', 'password' => 'another-pass' ) );
        $this->assertTrue( $other['success'] );
    }

    function test_deleting_a_member_removes_their_app_sign_ins() {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        $user_id = $this->member();
        kounselia_issue_app_token( $user_id );

        wp_delete_user( $user_id );

        $this->assertCount( 0, $this->token_rows( $user_id ) );
    }
}

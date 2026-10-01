<?php
/**
 * Who can get in, and what they can do once in: staff only reach the
 * admin areas they were given, the admin panel only takes sessions from
 * its own sign-in (where 2FA is asked), banned or trashed members are
 * locked out, and a session can't be cancelled (and refunded) after it
 * has started.
 */
class Test_Access_Control extends WP_Ajax_UnitTestCase {

    private function admin_ajax( $action, $post ) {
        $_POST = array_merge( array( 'action' => $action, 'nonce' => wp_create_nonce( 'kounselia_admin_nonce' ) ), $post );
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

    private function staff( $permissions ) {
        $id = self::factory()->user->create( array( 'role' => 'kounselia_staff' ) );
        update_user_meta( $id, 'kounselia_permissions', wp_json_encode( $permissions ) );
        return $id;
    }

    function test_staff_without_the_members_area_cannot_change_members() {
        $member = self::factory()->user->create();
        wp_set_current_user( $this->staff( array( 'blog' ) ) );

        $res = $this->admin_ajax( 'kounselia_admin_update_member', array( 'user_id' => $member, 'do_action' => 'ban' ) );

        $this->assertFalse( $res['success'] );
        $this->assertEmpty( get_user_meta( $member, 'kounselia_banned', true ) );
    }

    function test_staff_with_the_members_area_can_ban_a_member_but_not_a_super_admin() {
        $member = self::factory()->user->create();
        $super  = self::factory()->user->create( array( 'role' => 'administrator' ) );
        $staff  = $this->staff( array( 'members' ) );
        wp_set_current_user( $staff );

        $this->assertTrue( $this->admin_ajax( 'kounselia_admin_update_member', array( 'user_id' => $member, 'do_action' => 'ban' ) )['success'] );
        $this->assertNotEmpty( get_user_meta( $member, 'kounselia_banned', true ) );

        $this->assertFalse( $this->admin_ajax( 'kounselia_admin_update_member', array( 'user_id' => $super, 'do_action' => 'ban' ) )['success'] );
        $this->assertFalse( $this->admin_ajax( 'kounselia_admin_update_member', array( 'user_id' => $staff, 'do_action' => 'delete' ) )['success'] );
        $this->assertEmpty( get_user_meta( $super, 'kounselia_banned', true ) );
    }

    function test_banned_and_trashed_members_cannot_sign_in() {
        $banned = self::factory()->user->create( array( 'user_login' => 'ban@example.com', 'user_email' => 'ban@example.com', 'user_pass' => 'pass-1234' ) );
        update_user_meta( $banned, 'kounselia_banned', 1 );
        $trashed = self::factory()->user->create( array( 'user_login' => 'trash@example.com', 'user_email' => 'trash@example.com', 'user_pass' => 'pass-1234' ) );
        update_user_meta( $trashed, 'kounselia_deleted_at', current_time( 'mysql' ) );

        foreach ( array( 'ban@example.com', 'trash@example.com' ) as $email ) {
            $result = wp_authenticate( $email, 'pass-1234' );
            $this->assertWPError( $result );
            $this->assertSame( 'kounselia_account_locked', $result->get_error_code() );
        }

        // Still signed in somewhere from before the ban: treated as signed out.
        $this->assertSame( 0, kounselia_sign_out_locked_account( $banned ) );

        delete_user_meta( $banned, 'kounselia_banned' );
        $this->assertInstanceOf( 'WP_User', wp_authenticate( 'ban@example.com', 'pass-1234' ) );
    }

    function test_only_sessions_started_on_the_admin_sign_in_are_marked() {
        $admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
        $manager = WP_Session_Tokens::get_instance( $admin );

        $website_token = $manager->create( time() + HOUR_IN_SECONDS );
        $this->assertArrayNotHasKey( 'kounselia_admin', $manager->get( $website_token ) );

        $GLOBALS['kounselia_admin_signing_in'] = true;
        $admin_token = $manager->create( time() + HOUR_IN_SECONDS );
        unset( $GLOBALS['kounselia_admin_signing_in'] );
        $this->assertSame( 1, $manager->get( $admin_token )['kounselia_admin'] );
    }

    private function professional( $pro_user ) {
        global $wpdb;
        $now = current_time( 'mysql' );
        $wpdb->insert( $wpdb->prefix . 'kounselia_professionals', array(
            'user_id' => $pro_user, 'title' => 'Psychologist', 'status' => 'verified', 'rate_amount' => 20000,
            'submitted_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ) );
        return (int) $wpdb->insert_id;
    }

    private function booking( $client, $professional_id, $starts_in ) {
        global $wpdb;
        $now   = current_time( 'mysql' );
        $start = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $starts_in );
        $wpdb->insert( $wpdb->prefix . 'kounselia_bookings', array(
            'professional_id' => $professional_id, 'client_user_id' => $client, 'scheduled_start' => $start,
            'scheduled_end' => date( 'Y-m-d H:i:s', strtotime( $start ) + 3000 ), 'status' => 'confirmed',
            'room_token' => wp_generate_password( 20, false ), 'created_at' => $now, 'updated_at' => $now,
        ) );
        return (int) $wpdb->insert_id;
    }

    function test_a_session_cannot_be_cancelled_after_it_has_started() {
        $client = self::factory()->user->create();
        $pro    = self::factory()->user->create();
        $pro_id = $this->professional( $pro );

        $past = $this->booking( $client, $pro_id, -2 * HOUR_IN_SECONDS );
        $this->assertWPError( kounselia_cancel_booking( $past, $client ) );
        $this->assertWPError( kounselia_cancel_booking( $past, $pro ) );

        $future = $this->booking( $client, $pro_id, 2 * DAY_IN_SECONDS );
        $this->assertTrue( kounselia_cancel_booking( $future, $client ) );

        // An admin can still step in afterwards (e.g. a no-show).
        $this->assertTrue( kounselia_admin_cancel_booking( $past, self::factory()->user->create( array( 'role' => 'administrator' ) ) ) );
    }
}

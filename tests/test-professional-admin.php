<?php
/**
 * Admin control over professionals (hide, edit, suspend, reinstate) and
 * free consultations (first N sessions free per client, or every session).
 */
class Test_Professional_Admin extends WP_Ajax_UnitTestCase {

    private function professional( $name = 'Dr. Ada Obi', $extra = array() ) {
        global $wpdb;
        $user = self::factory()->user->create( array( 'display_name' => $name ) );
        $now  = current_time( 'mysql' );
        $wpdb->insert( $wpdb->prefix . 'kounselia_professionals', array_merge( array(
            'user_id' => $user, 'title' => 'Psychologist', 'status' => 'verified', 'rate_amount' => 20000,
            'submitted_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ), $extra ) );
        return kounselia_get_professional_by_id( (int) $wpdb->insert_id );
    }

    private function admin() {
        $id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $id );
        return $id;
    }

    private function booking( $pro, $client, $status = 'confirmed', $days = 2 ) {
        global $wpdb;
        $start = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $days * DAY_IN_SECONDS );
        $wpdb->insert( $wpdb->prefix . 'kounselia_bookings', array(
            'professional_id' => $pro->id, 'client_user_id' => $client, 'scheduled_start' => $start,
            'scheduled_end' => date( 'Y-m-d H:i:s', strtotime( $start ) + 3000 ), 'status' => $status,
            'room_token' => wp_generate_password( 20, false ), 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
        ) );
        return (int) $wpdb->insert_id;
    }

    function test_hiding_takes_a_professional_out_of_every_list() {
        $this->admin();
        $pro = $this->professional();
        $this->assertCount( 1, kounselia_get_verified_professionals() );
        kounselia_admin_professional_set_hidden( $pro->id, true );
        $this->assertCount( 0, kounselia_get_verified_professionals(), 'Gone from the dashboard and app lists.' );
        $this->assertCount( 0, kounselia_public_professionals(), 'Gone from the public directory.' );
        $this->assertFalse( kounselia_professional_is_public( $pro->user_id ) );
        $this->assertNull( kounselia_public_professional_by_slug( 'dr-ada-obi-' . $pro->id ), 'Their profile page stops working.' );
        kounselia_admin_professional_set_hidden( $pro->id, false );
        $this->assertCount( 1, kounselia_public_professionals() );
    }

    function test_admin_edits_a_profile() {
        $this->admin();
        $pro = $this->professional();
        $this->assertWPError( kounselia_admin_professional_update( $pro->id, array( 'title' => '  ' ) ) );
        kounselia_admin_professional_update( $pro->id, array( 'name' => 'Dr. Ada Obi-Eze', 'title' => 'Clinical Psychologist', 'bio' => '<b>Kind</b> and clear.', 'rate_amount' => '15000', 'free_sessions_per_client' => 2 ) );
        $pro = kounselia_get_professional_by_id( $pro->id );
        $this->assertSame( 'Dr. Ada Obi-Eze', $pro->display_name );
        $this->assertSame( 'Clinical Psychologist', $pro->title );
        $this->assertSame( 'Kind and clear.', $pro->bio );
        $this->assertSame( 15000.0, (float) $pro->rate_amount );
        $this->assertSame( 2, (int) $pro->free_sessions_per_client );
    }

    function test_suspending_stops_bookings_and_can_cancel_upcoming_sessions() {
        $this->admin();
        $pro    = $this->professional();
        $client = self::factory()->user->create();
        $b      = $this->booking( $pro, $client );

        $this->assertWPError( kounselia_admin_professional_suspend( $pro->id, '' ), 'A reason is needed.' );
        $result = kounselia_admin_professional_suspend( $pro->id, 'Complaints under review.', array( 'cancel_upcoming' => true ) );
        $this->assertSame( 1, $result['cancelled'] );
        $this->assertSame( 'cancelled', kounselia_get_booking_with_parties( $b )->status );

        $pro = kounselia_get_professional_by_id( $pro->id );
        $this->assertSame( 'suspended', $pro->status );
        $this->assertCount( 0, kounselia_public_professionals() );
        $this->assertWPError( kounselia_create_booking( $pro->id, $client, date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + 3 * DAY_IN_SECONDS ), '' ) );
        $this->assertFalse( kounselia_article_access( $pro )['allowed'], 'Cannot write while suspended.' );

        $this->assertTrue( kounselia_admin_professional_reinstate( $pro->id ) );
        $this->assertSame( 'verified', kounselia_get_professional_by_id( $pro->id )->status );
        $this->assertCount( 1, kounselia_public_professionals() );
    }

    function test_suspending_can_take_articles_down_and_reinstating_lets_them_write_again() {
        $this->admin();
        $pro = $this->professional( 'Trusted Pro', array( 'publishing' => 'trusted' ) );
        $app = kounselia_get_professional_application( $pro->user_id );
        kounselia_article_save( $app, array( 'title' => 'Hope', 'content' => '<p>' . str_repeat( 'word ', 300 ) . '</p>' ), 0, 'send' );
        $this->assertSame( 1, kounselia_admin_professional_suspend( $pro->id, 'Review', array( 'take_down' => true ) )['taken_down'] );
        $this->assertSame( 0, kounselia_blog_query( array( 'professional_id' => $pro->id ) )['total'] );
        kounselia_admin_professional_reinstate( $pro->id );
        $this->assertTrue( kounselia_article_access( kounselia_get_professional_application( $pro->user_id ) )['allowed'] );
    }

    function test_free_session_allowance_per_client() {
        $pro    = $this->professional( 'Free Pro', array( 'free_sessions_per_client' => 2 ) );
        $client = self::factory()->user->create();
        $this->assertSame( 2, kounselia_free_sessions_left( $pro, $client ) );
        $this->assertSame( 'Your next 2 sessions are free', kounselia_free_sessions_label( $pro, $client ) );

        $b = $this->booking( $pro, $client, 'pending_payment' );
        $this->assertTrue( kounselia_confirm_free_booking( $b ) );
        $booking = kounselia_get_booking_with_parties( $b );
        $this->assertSame( 'confirmed', $booking->status );
        $this->assertSame( 1, kounselia_free_sessions_left( $pro, $client ) );

        // Cancelling a free session doesn't give it back, and never tries a refund.
        kounselia_admin_cancel_booking( $b, $this->admin(), 'test' );
        $this->assertSame( 1, kounselia_free_sessions_left( $pro, $client ) );

        $this->booking( $pro, $client, 'pending_payment' );
        global $wpdb;
        kounselia_confirm_free_booking( (int) $wpdb->insert_id );
        $this->assertSame( 0, kounselia_free_sessions_left( $pro, $client ) );
        $this->assertSame( '', kounselia_free_sessions_label( $pro, $client ) );
        $this->assertSame( 2, kounselia_free_sessions_left( $pro, self::factory()->user->create() ), 'Each new client gets their own.' );
    }

    function test_every_session_free() {
        $pro    = $this->professional( 'Pro Bono', array( 'free_sessions_per_client' => KOUNSELIA_FREE_ALWAYS, 'rate_amount' => null ) );
        $client = self::factory()->user->create();
        $this->assertSame( KOUNSELIA_FREE_ALWAYS, kounselia_free_sessions_left( $pro, $client ) );
        $this->assertSame( 'Sessions are free', kounselia_free_sessions_label( $pro ) );
        $this->assertSame( KOUNSELIA_FREE_ALWAYS, kounselia_free_sessions_clean( 99999 ) );
        $this->assertSame( 20, kounselia_free_sessions_clean( 50 ) );
    }

    function test_staff_without_professionals_permission_cannot_suspend() {
        $pro   = $this->professional();
        $staff = self::factory()->user->create( array( 'role' => 'kounselia_staff' ) );
        update_user_meta( $staff, 'kounselia_permissions', wp_json_encode( array( 'blog' ) ) );
        wp_set_current_user( $staff );
        $_POST = array( 'action' => 'kounselia_admin_professional_suspend', 'nonce' => wp_create_nonce( 'kounselia_admin_nonce' ), 'professional_id' => $pro->id, 'reason' => 'x' );
        try {
            $this->_handleAjax( 'kounselia_admin_professional_suspend' );
        } catch ( WPAjaxDieContinueException $e ) {
            // Expected.
        }
        $this->assertFalse( json_decode( $this->_last_response, true )['success'] );
        $this->assertSame( 'verified', kounselia_get_professional_by_id( $pro->id )->status );
    }
}

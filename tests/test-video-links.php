<?php
/**
 * A professional's own video link: only trusted services, only when an
 * admin allows it, and only handed to the two people on a booking at
 * session time.
 */
class Test_Video_Links extends WP_Ajax_UnitTestCase {

    private function professional( $extra = array() ) {
        global $wpdb;
        $user = self::factory()->user->create( array( 'display_name' => 'Dr. Ada Obi' ) );
        $now  = current_time( 'mysql' );
        $wpdb->insert( $wpdb->prefix . 'kounselia_professionals', array_merge( array(
            'user_id' => $user, 'title' => 'Psychologist', 'status' => 'verified', 'rate_amount' => 20000,
            'submitted_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ), $extra ) );
        return kounselia_get_professional_by_id( (int) $wpdb->insert_id );
    }

    private function booking( $pro, $client, $minutes_from_now = 5, $extra = array() ) {
        global $wpdb;
        $start = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $minutes_from_now * MINUTE_IN_SECONDS );
        $wpdb->insert( $wpdb->prefix . 'kounselia_bookings', array_merge( array(
            'professional_id' => $pro->id, 'client_user_id' => $client, 'scheduled_start' => $start,
            'scheduled_end' => date( 'Y-m-d H:i:s', strtotime( $start ) + 3000 ), 'status' => 'confirmed',
            'room_token' => 'tok' . wp_generate_password( 12, false ), 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
        ), $extra ) );
        return kounselia_get_booking_with_parties( (int) $wpdb->insert_id );
    }

    function test_only_trusted_https_services_are_accepted() {
        $this->assertSame( 'Zoom', kounselia_video_provider_for( 'https://us02web.zoom.us/j/123?pwd=abc' ) );
        $this->assertSame( 'Google Meet', kounselia_video_provider_for( 'https://meet.google.com/abc-defg-hij' ) );
        $this->assertSame( 'Microsoft Teams', kounselia_video_provider_for( 'https://teams.microsoft.com/l/meetup-join/x' ) );
        $this->assertSame( 'Whereby', kounselia_video_provider_for( 'https://whereby.com/dr-ada' ) );
        $this->assertSame( '', kounselia_video_provider_for( 'http://zoom.us/j/123' ), 'Not https.' );
        $this->assertSame( '', kounselia_video_provider_for( 'https://zoom.us.evil.example/j/1' ), 'Look-alike host.' );
        $this->assertSame( '', kounselia_video_provider_for( 'https://evilzoom.us/j/1' ), 'Not a real subdomain.' );
        $this->assertSame( '', kounselia_video_provider_for( 'https://user:pass@zoom.us/j/1' ) );
        $this->assertSame( '', kounselia_video_provider_for( 'javascript:alert(1)' ) );
        $this->assertSame( 'https://meet.google.com/abc-defg-hij', kounselia_video_clean_link( 'meet.google.com/abc-defg-hij' ), 'https:// is added.' );
        $this->assertWPError( kounselia_video_clean_link( 'https://example.com/call' ) );
        $this->assertSame( '', kounselia_video_clean_link( '  ' ) );
    }

    function test_link_is_only_used_when_an_admin_allows_it() {
        $pro     = $this->professional( array( 'video_mode' => 'own', 'video_link' => 'https://zoom.us/j/111' ) );
        $booking = $this->booking( $pro, self::factory()->user->create() );
        $this->assertFalse( kounselia_booking_video( $booking )['external'], 'Not allowed yet: Kounselia room.' );

        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'kounselia_professionals', array( 'video_link_allowed' => 1 ), array( 'id' => $pro->id ) );
        $video = kounselia_booking_video( $booking );
        $this->assertTrue( $video['external'] );
        $this->assertSame( 'Zoom', $video['provider'] );
        $this->assertSame( 'Zoom', kounselia_professional_video_provider( kounselia_get_professional_by_id( $pro->id ) ) );

        // A link for one session wins over the usual one.
        $wpdb->update( $wpdb->prefix . 'kounselia_bookings', array( 'video_link' => 'https://meet.google.com/aaa-bbbb-ccc' ), array( 'id' => $booking->id ) );
        $this->assertSame( 'Google Meet', kounselia_booking_video( kounselia_get_booking_with_parties( $booking->id ) )['provider'] );

        // Admin stops it: straight back to Kounselia's room.
        $wpdb->update( $wpdb->prefix . 'kounselia_professionals', array( 'video_link_allowed' => 0 ), array( 'id' => $pro->id ) );
        $this->assertFalse( kounselia_booking_video( kounselia_get_booking_with_parties( $booking->id ) )['external'] );
    }

    function test_the_app_only_gets_the_link_for_the_two_people_at_session_time() {
        $pro    = $this->professional( array( 'video_link_allowed' => 1, 'video_mode' => 'own', 'video_link' => 'https://zoom.us/j/222' ) );
        $client = self::factory()->user->create();
        $now    = $this->booking( $pro, $client, 5 );
        $later  = $this->booking( $pro, $client, 60 * 24 );

        $ask = function ( $user, $booking_id ) {
            wp_set_current_user( $user );
            $_POST = array( 'action' => 'kounselia_get_booking_room', 'nonce' => wp_create_nonce( 'kounselia_auth' ), 'booking_id' => $booking_id );
            $this->_last_response = '';
            try {
                $this->_handleAjax( 'kounselia_get_booking_room' );
            } catch ( WPAjaxDieContinueException $e ) {
                // Expected.
            }
            return json_decode( $this->_last_response, true );
        };

        $res = $ask( $client, $now->id );
        $this->assertTrue( $res['success'] );
        $this->assertTrue( $res['data']['external'] );
        $this->assertSame( 'https://zoom.us/j/222', $res['data']['url'] );

        $this->assertFalse( $ask( $client, $later->id )['success'], 'Not before session time.' );
        $stranger = self::factory()->user->create();
        $this->assertFalse( $ask( $stranger, $now->id )['success'], 'Not for anyone else.' );
    }

    function test_professional_cannot_set_a_link_unless_allowed() {
        $pro = $this->professional();
        wp_set_current_user( $pro->user_id );
        $_POST = array( 'action' => 'kounselia_pro_video_settings', 'nonce' => wp_create_nonce( 'kounselia_auth' ), 'mode' => 'own', 'link' => 'https://zoom.us/j/333' );
        try {
            $this->_handleAjax( 'kounselia_pro_video_settings' );
        } catch ( WPAjaxDieContinueException $e ) {
            // Expected.
        }
        $this->assertFalse( json_decode( $this->_last_response, true )['success'] );
        $this->assertNull( kounselia_get_professional_by_id( $pro->id )->video_link );
    }
}

<?php
/**
 * The dashboard data the mobile app loads (includes/app-dashboard.php):
 * home, recent conversations, bookings and the video room link.
 */
class Test_App_Dashboard extends WP_Ajax_UnitTestCase {

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

    // A verified professional and a confirmed booking with `$client`,
    // starting `$starts_in` seconds from now.
    private function booking( $client, $starts_in ) {
        global $wpdb;
        $now      = current_time( 'mysql' );
        $pro_user = self::factory()->user->create( array( 'display_name' => 'Dr. Ada Obi' ) );
        $wpdb->insert( $wpdb->prefix . 'kounselia_professionals', array(
            'user_id' => $pro_user, 'title' => 'Therapist', 'rate_amount' => 20000, 'rate_currency' => 'NGN',
            'status' => 'verified', 'submitted_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ) );
        $pro_id = (int) $wpdb->insert_id;
        $start  = current_time( 'timestamp' ) + $starts_in;
        $wpdb->insert( $wpdb->prefix . 'kounselia_bookings', array(
            'professional_id' => $pro_id, 'client_user_id' => $client,
            'scheduled_start' => date( 'Y-m-d H:i:s', $start ), 'scheduled_end' => date( 'Y-m-d H:i:s', $start + HOUR_IN_SECONDS ),
            'status' => 'confirmed', 'room_token' => 'tok123', 'created_at' => $now, 'updated_at' => $now,
        ) );
        return (int) $wpdb->insert_id;
    }

    function test_recommendation_rules() {
        $active = array( 'serena', 'marcus', 'noa' );

        $this->assertSame( 'serena', kounselia_recommended_counselor( 'anxious', $active, array() )[0] ); // anxious → serena
        $this->assertSame( 'marcus', kounselia_recommended_counselor( null, $active, array( 'serena' ) )[0] ); // first not tried
        $this->assertSame( 'noa', kounselia_recommended_counselor( null, $active, array( 'noa', 'serena', 'marcus' ) )[0] ); // most recent
        $this->assertSame( 'serena', kounselia_recommended_counselor( null, $active, array() )[0] );
        // A mood whose counselor is switched off falls through to the next rule.
        $this->assertSame( 'marcus', kounselia_recommended_counselor( 'low', $active, array( 'serena' ) )[0] );
    }

    function test_home_has_mood_stats_recommendation_and_journal() {
        $user = self::factory()->user->create();
        wp_set_current_user( $user );
        $this->ajax( 'kounselia_save_mood', array( 'mood' => 'anxious' ) );
        $this->ajax( 'kounselia_save_journal', array( 'content' => 'A calmer day.' ) );

        $res = $this->ajax( 'kounselia_app_home' );

        $this->assertTrue( $res['success'] );
        $this->assertSame( 'anxious', $res['data']['mood']['today'] );
        $this->assertCount( 7, $res['data']['mood']['week'] );
        $this->assertSame( 'anxious', end( $res['data']['mood']['week'] )['mood'] );
        $this->assertSame( array( 'key' => 'calm', 'label' => 'Calm', 'icon' => 'mood-smile', 'color' => 'sage' ), $res['data']['mood']['options'][0] );
        $this->assertSame( 'serena', $res['data']['recommended']['slug'] );
        $this->assertSame( 'A calmer day.', $res['data']['journal'] );
        $this->assertSame( 0, $res['data']['stats']['conversations'] );
    }

    function test_signed_out_is_refused() {
        wp_set_current_user( 0 );
        $res = $this->ajax( 'kounselia_app_home' );
        $this->assertFalse( $res['success'] );
        $this->assertTrue( $res['data']['signed_out'] );
    }

    function test_sessions_list_recent_conversations() {
        $user = self::factory()->user->create();
        wp_set_current_user( $user );
        $session_id = kounselia_resolve_session( 'serena', $user, '', 0 );
        kounselia_log_message( $session_id, 'user', 'Hello' );
        kounselia_log_message( $session_id, 'bot', 'Hi there' );

        $res = $this->ajax( 'kounselia_get_sessions' );

        $this->assertTrue( $res['success'] );
        $this->assertSame( 'serena', $res['data']['sessions'][0]['counselor_slug'] );
        $this->assertSame( 2, $res['data']['sessions'][0]['message_count'] );
        $this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $res['data']['sessions'][0]['last_at'] );
    }

    function test_bookings_list_upcoming_and_professionals_with_price() {
        update_option( 'kounselia_currency_mode', 'NGN' );
        $client = self::factory()->user->create();
        wp_set_current_user( $client );
        $booking_id = $this->booking( $client, DAY_IN_SECONDS );

        $res = $this->ajax( 'kounselia_app_bookings' );

        $this->assertTrue( $res['success'] );
        $this->assertSame( $booking_id, $res['data']['upcoming'][0]['id'] );
        $this->assertSame( 'Dr. Ada Obi', $res['data']['upcoming'][0]['pro_name'] );
        $this->assertFalse( $res['data']['upcoming'][0]['joinable'] );
        $this->assertNotEmpty( $res['data']['upcoming'][0]['start_utc'] );
        $pros = array_column( $res['data']['professionals'], null, 'name' );
        $this->assertArrayHasKey( 'Dr. Ada Obi', $pros );
        $this->assertStringContainsString( '20,000', $pros['Dr. Ada Obi']['price'] );
        delete_option( 'kounselia_currency_mode' );
    }

    function test_room_link_only_for_the_client_inside_the_window() {
        $client     = self::factory()->user->create( array( 'display_name' => 'Tolu' ) );
        $stranger   = self::factory()->user->create();
        $now_id     = $this->booking( $client, 5 * MINUTE_IN_SECONDS ); // opens 10 min before
        $later_id   = $this->booking( $client, DAY_IN_SECONDS );

        wp_set_current_user( $client );
        $res = $this->ajax( 'kounselia_get_booking_room', array( 'booking_id' => $now_id ) );
        $this->assertTrue( $res['success'] );
        $this->assertStringStartsWith( 'https://meet.jit.si/kounselia-tok123#', $res['data']['url'] );
        $this->assertStringContainsString( rawurlencode( '"Tolu"' ), $res['data']['url'] );

        $res = $this->ajax( 'kounselia_get_booking_room', array( 'booking_id' => $later_id ) );
        $this->assertFalse( $res['success'] );

        wp_set_current_user( $stranger );
        $res = $this->ajax( 'kounselia_get_booking_room', array( 'booking_id' => $now_id ) );
        $this->assertFalse( $res['success'] );
        $this->assertArrayNotHasKey( 'url', (array) $res['data'] );
    }

    function test_home_has_the_check_in_and_the_care_team() {
        global $wpdb;
        $user = self::factory()->user->create();
        wp_set_current_user( $user );
        $wpdb->insert( $wpdb->prefix . 'kounselia_memory_upcoming_events', array(
            'user_id' => $user, 'event_text' => 'Your job interview', 'event_date' => current_time( 'Y-m-d' ),
            'status' => 'pending', 'created_at' => current_time( 'mysql' ),
        ) );
        $session_id = kounselia_resolve_session( 'marcus', $user, '', 0 );
        kounselia_log_message( $session_id, 'user', 'Big interview today' );

        $res = $this->ajax( 'kounselia_app_home' );

        $this->assertSame( 'Your job interview', $res['data']['checkin']['event_text'] );
        $this->assertSame( 'marcus', $res['data']['checkin']['counselor_slug'] ); // the counselor they last talked to
        $this->assertNull( $res['data']['care']['next'] );

        $booking_id = $this->booking( $user, DAY_IN_SECONDS );
        $res        = $this->ajax( 'kounselia_app_home' );
        $this->assertSame( $booking_id, $res['data']['care']['next']['id'] );
        $this->assertSame( 'Dr. Ada Obi', $res['data']['care']['next']['pro_name'] );
        $this->assertSame( 0, $res['data']['care']['next']['more_booked'] );
    }

    function test_no_check_in_when_nothing_is_due() {
        $user = self::factory()->user->create();
        wp_set_current_user( $user );
        $res = $this->ajax( 'kounselia_app_home' );
        $this->assertNull( $res['data']['checkin'] );
    }

    function test_a_session_that_has_started_is_still_joinable_from_the_app() {
        $client = self::factory()->user->create();
        wp_set_current_user( $client );
        $booking_id = $this->booking( $client, -5 * MINUTE_IN_SECONDS ); // started 5 minutes ago

        $res = $this->ajax( 'kounselia_app_bookings' );
        $this->assertSame( $booking_id, $res['data']['upcoming'][0]['id'] );
        $this->assertTrue( $res['data']['upcoming'][0]['joinable'] );

        $home = $this->ajax( 'kounselia_app_home' );
        $this->assertTrue( $home['data']['care']['next']['joinable'] );
    }

    function test_check_in_falls_back_when_that_counselor_is_switched_off() {
        global $wpdb;
        $user = self::factory()->user->create();
        wp_set_current_user( $user );
        $wpdb->insert( $wpdb->prefix . 'kounselia_memory_upcoming_events', array(
            'user_id' => $user, 'event_text' => 'The exam', 'event_date' => current_time( 'Y-m-d' ),
            'status' => 'pending', 'created_at' => current_time( 'mysql' ),
        ) );
        $session_id = kounselia_resolve_session( 'marcus', $user, '', 0 );
        kounselia_log_message( $session_id, 'user', 'Exam tomorrow' );
        $wpdb->update( $wpdb->prefix . 'kounselia_counselor_prompts', array( 'is_active' => 0 ), array( 'counselor_slug' => 'marcus' ) );

        $res = $this->ajax( 'kounselia_app_home' );
        $this->assertNotSame( 'marcus', $res['data']['checkin']['counselor_slug'] );
        $this->assertSame( $res['data']['recommended']['slug'], $res['data']['checkin']['counselor_slug'] );
    }

    function test_booking_status_is_only_for_the_member_who_booked() {
        $client   = self::factory()->user->create();
        $stranger = self::factory()->user->create();
        $booking_id = $this->booking( $client, DAY_IN_SECONDS );

        wp_set_current_user( $client );
        $res = $this->ajax( 'kounselia_get_booking_status', array( 'booking_id' => $booking_id ) );
        $this->assertSame( 'confirmed', $res['data']['status'] );

        wp_set_current_user( $stranger );
        $res = $this->ajax( 'kounselia_get_booking_status', array( 'booking_id' => $booking_id ) );
        $this->assertFalse( $res['success'] );
    }

    function test_slots_come_with_utc_times() {
        $this->assertSame( get_gmt_from_date( '2030-01-02 09:00:00', 'Y-m-d\TH:i:s\Z' ), kounselia_app_utc( '2030-01-02 09:00:00' ) );
        $this->assertNull( kounselia_app_utc( null ) );
    }
}

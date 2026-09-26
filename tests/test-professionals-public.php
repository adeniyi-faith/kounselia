<?php
/**
 * The public professionals directory must only ever show verified
 * professionals who haven't opted out, and must never expose licence
 * numbers or which clients wrote reviews.
 */
class Test_Professionals_Public extends WP_Ajax_UnitTestCase {

    private function professional( $name, $status = 'verified', $extra = array() ) {
        global $wpdb;
        $user = self::factory()->user->create( array( 'display_name' => $name ) );
        $now  = current_time( 'mysql' );
        $wpdb->insert( $wpdb->prefix . 'kounselia_professionals', array_merge( array(
            'user_id'        => $user,
            'title'          => 'Clinical Psychologist',
            'specialty'      => 'Anxiety, Trauma & Grief',
            'license_number' => 'LIC-12345',
            'rate_amount'    => 20000,
            'status'         => $status,
            'submitted_at'   => $now,
            'created_at'     => $now,
            'updated_at'     => $now,
        ), $extra ) );
        return array( $user, (int) $wpdb->insert_id );
    }

    function test_only_verified_and_visible_professionals_are_listed() {
        $this->professional( 'Ada Verified' );
        $this->professional( 'Bola Pending', 'pending' );
        list( $hidden_user ) = $this->professional( 'Chi Hidden' );
        update_user_meta( $hidden_user, 'kounselia_public_profile', '0' );

        $names = wp_list_pluck( kounselia_public_professionals(), 'display_name' );
        $this->assertSame( array( 'Ada Verified' ), $names );
    }

    function test_profile_address_and_lookup() {
        list( , $pid ) = $this->professional( 'Dr. Amara Nwosu' );
        $pro = kounselia_public_professional_by_slug( 'dr-amara-nwosu-' . $pid );
        $this->assertNotNull( $pro );
        $this->assertSame( '/professionals/dr-amara-nwosu-' . $pid, $pro->url );
        $this->assertSame( array( 'Anxiety', 'Trauma', 'Grief' ), $pro->specialties );

        list( , $pending ) = $this->professional( 'Not Yet', 'pending' );
        $this->assertNull( kounselia_public_professional_by_slug( 'not-yet-' . $pending ) );
    }

    function test_public_card_never_shows_licence_and_reviews_are_anonymous() {
        global $wpdb;
        list( , $pid ) = $this->professional( 'Ada Verified' );
        $client = self::factory()->user->create( array( 'display_name' => 'Secret Client Name' ) );
        $wpdb->insert( $wpdb->prefix . 'kounselia_professional_reviews', array(
            'booking_id' => 1, 'professional_id' => $pid, 'client_user_id' => $client, 'rating' => 5,
            'comment' => 'Very kind.', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
        ) );

        $html = kounselia_professional_card_html( kounselia_public_professionals()[0] );
        $this->assertStringNotContainsString( 'LIC-12345', $html );

        $reviews = kounselia_public_professional_reviews( $pid );
        $this->assertSame( 'Very kind.', $reviews[0]['comment'] );
        $this->assertStringNotContainsString( 'Secret Client Name', wp_json_encode( $reviews ) );
    }

    function test_professional_can_hide_their_profile() {
        list( $user ) = $this->professional( 'Ada Verified' );
        wp_set_current_user( $user );
        $_POST = array( 'action' => 'kounselia_set_public_profile', 'nonce' => wp_create_nonce( 'kounselia_auth' ), 'show' => '0' );
        try {
            $this->_handleAjax( 'kounselia_set_public_profile' );
        } catch ( WPAjaxDieContinueException $e ) {
            // Expected.
        }
        $this->assertTrue( json_decode( $this->_last_response, true )['success'] );
        $this->assertSame( array(), kounselia_public_professionals() );
    }

    function test_members_cannot_toggle_a_profile_they_dont_have() {
        wp_set_current_user( self::factory()->user->create() );
        $_POST = array( 'action' => 'kounselia_set_public_profile', 'nonce' => wp_create_nonce( 'kounselia_auth' ), 'show' => '0' );
        try {
            $this->_handleAjax( 'kounselia_set_public_profile' );
        } catch ( WPAjaxDieContinueException $e ) {
            // Expected.
        }
        $this->assertFalse( json_decode( $this->_last_response, true )['success'] );
    }
}

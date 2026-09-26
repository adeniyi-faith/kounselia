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

/**
 * Sites that already had their footer and "Our counselors" page seeded
 * before the professionals directory existed need those two links and
 * that note added retroactively — additively, once, without touching
 * anything an admin already customised.
 */
class Test_Professionals_Backfill extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        delete_option( 'kounselia_professionals_links_backfilled' );
        // 'init' seeds a default "our-counselors" page once, globally, before any
        // test runs — clear it so each test starts from a known page (or none).
        $existing = kounselia_get_page_by_slug( 'our-counselors', false );
        if ( $existing ) {
            kounselia_delete_page( $existing->id );
        }
    }

    private function old_style_footer() {
        return array(
            'tagline' => 'A global mental wellness initiative.',
            'columns' => array(
                array( 'title' => 'Platform', 'links' => array(
                    array( 'label' => 'Our counselors', 'page' => 'our-counselors', 'url' => '' ),
                    array( 'label' => 'Create account', 'page' => 'get-started', 'url' => '' ),
                ) ),
                array( 'title' => 'Organisation', 'links' => array(
                    array( 'label' => 'Our mission', 'page' => 'our-mission', 'url' => '' ),
                    array( 'label' => 'Press', 'page' => 'press', 'url' => '' ),
                ) ),
            ),
            'social' => array( 'email' => 'hello@kounselia.com' ),
            'show_newsletter' => 1, 'newsletter_heading' => 'x', 'newsletter_text' => 'y',
        );
    }

    private function links_flat( $footer ) {
        $out = array();
        foreach ( $footer['columns'] as $col ) {
            foreach ( $col['links'] as $link ) {
                $out[] = $link['url'];
            }
        }
        return $out;
    }

    function test_backfill_inserts_professionals_link_right_after_our_counselors() {
        update_option( 'kounselia_footer', $this->old_style_footer() );
        kounselia_footer_backfill_professionals_links();

        $footer = kounselia_footer_settings();
        $labels = wp_list_pluck( $footer['columns'][0]['links'], 'label' );
        $this->assertSame( array( 'Our counselors', 'Find a professional', 'Create account' ), $labels );
    }

    function test_backfill_adds_join_link_to_organisation_column() {
        update_option( 'kounselia_footer', $this->old_style_footer() );
        kounselia_footer_backfill_professionals_links();

        $footer = kounselia_footer_settings();
        $labels = wp_list_pluck( $footer['columns'][1]['links'], 'label' );
        $this->assertContains( 'For professionals', $labels );
    }

    function test_backfill_never_duplicates_links_and_leaves_other_customisation_alone() {
        update_option( 'kounselia_footer', $this->old_style_footer() );
        kounselia_footer_backfill_professionals_links();
        kounselia_footer_backfill_professionals_links(); // Run twice on purpose.

        $footer = kounselia_footer_settings();
        $urls   = $this->links_flat( $footer );
        $this->assertSame( 1, count( array_keys( $urls, '/professionals/' ) ) );
        $this->assertSame( 1, count( array_keys( $urls, '/professionals/join' ) ) );
        $this->assertSame( 'A global mental wellness initiative.', $footer['tagline'] );
        $this->assertSame( 'hello@kounselia.com', $footer['social']['email'] );
    }

    function test_backfill_skips_a_footer_that_already_has_the_links() {
        $footer = $this->old_style_footer();
        $footer['columns'][0]['links'][] = array( 'label' => 'Find a professional', 'page' => '', 'url' => '/professionals/' );
        update_option( 'kounselia_footer', $footer );
        kounselia_footer_backfill_professionals_links();

        $urls = $this->links_flat( kounselia_footer_settings() );
        $this->assertSame( 1, count( array_keys( $urls, '/professionals/' ) ), 'Must not add a second copy.' );
    }

    function test_backfill_appends_note_to_existing_counselors_page_once() {
        $id = kounselia_save_page( array( 'title' => 'Meet our counselors', 'slug' => 'our-counselors', 'content' => '<p>Meet our counselors.</p>', 'status' => 'published' ) );

        kounselia_counselors_page_backfill_professionals_note();
        $page = kounselia_get_page_by_slug( 'our-counselors', false );
        $this->assertStringContainsString( '/professionals/', $page->content );
        $this->assertStringContainsString( '<p>Meet our counselors.</p>', $page->content, 'Existing content is kept, not replaced.' );

        kounselia_counselors_page_backfill_professionals_note(); // Run twice on purpose.
        $page_again = kounselia_get_page_by_slug( 'our-counselors', false );
        $this->assertSame( 1, substr_count( $page_again->content, 'Want to talk to a licensed human' ) );
    }

    function test_backfill_skips_a_page_that_already_mentions_professionals() {
        kounselia_save_page( array( 'title' => 'Meet our counselors', 'slug' => 'our-counselors', 'content' => '<p>See our professionals too.</p>', 'status' => 'published' ) );
        kounselia_counselors_page_backfill_professionals_note();

        $page = kounselia_get_page_by_slug( 'our-counselors', false );
        $this->assertSame( '<p>See our professionals too.</p>', $page->content );
    }

    function test_full_backfill_runs_once_via_the_guard_option() {
        update_option( 'kounselia_footer', $this->old_style_footer() );
        kounselia_save_page( array( 'title' => 'Meet our counselors', 'slug' => 'our-counselors', 'content' => '<p>x</p>', 'status' => 'published' ) );

        kounselia_professionals_backfill_links();
        $this->assertSame( 1, (int) get_option( 'kounselia_professionals_links_backfilled' ) );

        // Tamper with the footer as if an admin removed the link again; a second call must be a no-op.
        $footer = kounselia_footer_settings();
        $footer['columns'][0]['links'] = array_values( array_filter( $footer['columns'][0]['links'], function ( $l ) {
            return '/professionals/' !== $l['url'];
        } ) );
        update_option( 'kounselia_footer', $footer );

        kounselia_professionals_backfill_links();
        $this->assertSame( array(), array_intersect( array( '/professionals/' ), $this->links_flat( kounselia_footer_settings() ) ), 'Once guarded, it must not re-add a link an admin removed.' );
    }
}

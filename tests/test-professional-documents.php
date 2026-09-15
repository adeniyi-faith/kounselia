<?php
/**
 * License/ID documents are private by design — see
 * portal/wp-content/mu-plugins/kounselia/includes/professionals.php.
 * These tests cover the actual access-control decision
 * (kounselia_user_can_view_professional_document) directly, and the
 * endpoint's early rejection paths (bad nonce, missing doc) via
 * wp_die(), which the WP test framework turns into a catchable
 * WPDieException instead of actually exiting.
 *
 * The success path (a valid, authorized request) is NOT driven through
 * the real AJAX handler here: it ends in readfile() + exit(), which
 * would kill the whole test process if actually reached. That's why
 * the authorization decision was factored out into its own pure
 * function in the first place — so it's fully covered without going
 * anywhere near the part of the endpoint that has to exit on purpose.
 */
class Test_Professional_Documents extends WP_UnitTestCase {

    function test_the_owner_can_view_their_own_document() {
        $owner = self::factory()->user->create();
        $this->assertTrue( kounselia_user_can_view_professional_document( $owner, $owner ) );
    }

    function test_an_admin_can_view_any_document() {
        $admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
        $owner = self::factory()->user->create();
        $this->assertTrue( kounselia_user_can_view_professional_document( $admin, $owner ) );
    }

    function test_a_stranger_cannot_view_someone_elses_document() {
        $owner    = self::factory()->user->create();
        $stranger = self::factory()->user->create();
        $this->assertFalse( kounselia_user_can_view_professional_document( $stranger, $owner ) );
    }

    function test_a_signed_out_visitor_cannot_view_anything() {
        $owner = self::factory()->user->create();
        $this->assertFalse( kounselia_user_can_view_professional_document( 0, $owner ) );
    }

    function test_endpoint_rejects_a_signed_out_request() {
        wp_set_current_user( 0 );
        $this->expectException( WPDieException::class );
        kounselia_ajax_view_professional_document();
    }

    function test_endpoint_rejects_a_missing_or_invalid_nonce() {
        $user = self::factory()->user->create();
        wp_set_current_user( $user );
        $_GET['doc_id'] = 999999;
        $_GET['nonce']  = 'not-a-real-nonce';
        $this->expectException( WPDieException::class );
        kounselia_ajax_view_professional_document();
    }

    function test_endpoint_rejects_a_valid_nonce_for_a_document_that_does_not_exist() {
        $user = self::factory()->user->create();
        wp_set_current_user( $user );
        $doc_id = 999999; // Nothing in kounselia_professional_documents has this id.
        $_GET['doc_id'] = $doc_id;
        $_GET['nonce']  = wp_create_nonce( 'kounselia_view_professional_doc_' . $doc_id );
        $this->expectException( WPDieException::class );
        kounselia_ajax_view_professional_document();
    }
}

<?php
/**
 * The professional side of the mobile app (app-professional.php).
 */
class Test_App_Professional extends WP_Ajax_UnitTestCase {

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

    private function details( $extra = array() ) {
        return array_merge( array(
            'title'            => 'Clinical Psychologist',
            'years_experience' => '6',
            'specialty'        => 'Anxiety',
            'bio'              => 'I help people with anxiety.',
            'rate_amount'      => '15000',
            'license_doc_b64'  => base64_encode( "%PDF-1.4\n%test\n" ),
            'license_doc_name' => 'licence.pdf',
        ), $extra );
    }

    function test_applying_signed_out_creates_the_account_and_a_pending_application() {
        $res = $this->app_ajax( 'kounselia_app_apply_professional', $this->details( array(
            'name'     => 'Ngozi Eze',
            'email'    => 'ngozi@example.com',
            'password' => 'long-enough',
        ) ) );

        $this->assertTrue( $res['success'], wp_json_encode( $res ) );
        $user = get_user_by( 'email', 'ngozi@example.com' );
        $this->assertInstanceOf( 'WP_User', $user );
        $this->assertSame( $user->ID, kounselia_user_id_from_app_token( $res['data']['token'] ) );

        $pro = kounselia_get_professional_application( $user->ID );
        $this->assertSame( 'pending', $pro->status );
        $this->assertSame( 'pending', $res['data']['user']['professional']['status'] );
        $this->assertCount( 1, kounselia_get_professional_documents( $pro->id ) );
    }

    function test_a_file_that_is_not_a_pdf_or_image_is_refused_before_any_account_is_made() {
        $res = $this->app_ajax( 'kounselia_app_apply_professional', $this->details( array(
            'name'            => 'Ngozi Eze',
            'email'           => 'ngozi@example.com',
            'password'        => 'long-enough',
            'license_doc_b64' => base64_encode( 'just some text' ),
        ) ) );

        $this->assertFalse( $res['success'] );
        $this->assertFalse( get_user_by( 'email', 'ngozi@example.com' ) );
    }

    function test_a_member_without_an_application_gets_no_dashboard() {
        $user_id = self::factory()->user->create();
        $token   = kounselia_issue_app_token( $user_id, 'Test phone' );

        $res = $this->app_ajax( 'kounselia_app_pro_dashboard', array(), $token );

        $this->assertFalse( $res['success'] );
        $this->assertTrue( $res['data']['no_application'] );
    }

    function test_a_professional_can_only_get_a_link_to_their_own_documents() {
        $this->app_ajax( 'kounselia_app_apply_professional', $this->details( array(
            'name'     => 'Ngozi Eze',
            'email'    => 'ngozi@example.com',
            'password' => 'long-enough',
        ) ) );
        $owner = get_user_by( 'email', 'ngozi@example.com' );
        $docs  = kounselia_get_professional_documents( kounselia_get_professional_application( $owner->ID )->id );
        $doc   = reset( $docs );

        $res = $this->app_ajax( 'kounselia_app_pro_dashboard', array(), kounselia_issue_app_token( $owner->ID, 'Phone' ) );
        $this->assertTrue( $res['success'] );

        $link = $this->app_ajax( 'kounselia_app_professional_document_link', array( 'doc_id' => $doc->id ), kounselia_issue_app_token( $owner->ID, 'Phone' ) );
        $this->assertTrue( $link['success'] );
        $this->assertStringContainsString( 'kounselia_app_view_professional_document', $link['data']['url'] );

        // Someone else with an application of their own can't reach it.
        $this->app_ajax( 'kounselia_app_apply_professional', $this->details( array(
            'name'     => 'Tunde Bello',
            'email'    => 'tunde@example.com',
            'password' => 'long-enough',
        ) ) );
        $other = get_user_by( 'email', 'tunde@example.com' );
        $res   = $this->app_ajax( 'kounselia_app_professional_document_link', array( 'doc_id' => $doc->id ), kounselia_issue_app_token( $other->ID, 'Phone' ) );
        $this->assertFalse( $res['success'] );
    }
}

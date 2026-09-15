<?php
/**
 * Approving/rejecting a professional application is the whole point of
 * the review queue — a bug here means either an unqualified person
 * gets the kounselia_professional role, or a legitimate one doesn't.
 * Drives the real AJAX handler (kounselia_ajax_admin_review_professional)
 * end to end, not a reimplementation of its logic.
 */
class Test_Professional_Verification extends WP_Ajax_UnitTestCase {

    private function create_pending_application( $user_id ) {
        global $wpdb;
        $now = current_time( 'mysql' );
        $wpdb->insert( $wpdb->prefix . 'kounselia_professionals', array(
            'user_id'      => $user_id,
            'title'        => 'Licensed Clinical Psychologist',
            'rate_amount'  => 15000,
            'rate_currency'=> 'NGN',
            'status'       => 'pending',
            'submitted_at' => $now,
            'created_at'   => $now,
            'updated_at'   => $now,
        ) );
        return (int) $wpdb->insert_id;
    }

    private function call_review( $professional_id, $decision, $reason = '' ) {
        $_POST['action']           = 'kounselia_admin_review_professional';
        $_POST['nonce']            = wp_create_nonce( 'kounselia_admin_nonce' );
        $_POST['professional_id']  = $professional_id;
        $_POST['decision']         = $decision;
        $_POST['reason']           = $reason;

        try {
            $this->_handleAjax( 'kounselia_admin_review_professional' );
        } catch ( WPAjaxDieContinueException $e ) {
            // Expected — kounselia_send_pure_json_* dies by design under the ajax test harness.
        }

        return json_decode( $this->_last_response, true );
    }

    function test_approve_grants_the_professional_role() {
        $admin     = self::factory()->user->create( array( 'role' => 'administrator' ) );
        $applicant = self::factory()->user->create( array( 'role' => 'subscriber' ) );
        $prof_id   = $this->create_pending_application( $applicant );

        wp_set_current_user( $admin );
        $response = $this->call_review( $prof_id, 'approve' );

        $this->assertTrue( $response['success'] );
        $this->assertEquals( 'verified', $response['data']['status'] );

        $applicant_user = get_userdata( $applicant );
        $this->assertContains( 'kounselia_professional', $applicant_user->roles );

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kounselia_professionals WHERE id = %d", $prof_id ) );
        $this->assertEquals( 'verified', $row->status );
        $this->assertEquals( $admin, (int) $row->reviewed_by );
    }

    function test_reject_records_reason_and_never_grants_the_role() {
        $admin     = self::factory()->user->create( array( 'role' => 'administrator' ) );
        $applicant = self::factory()->user->create( array( 'role' => 'subscriber' ) );
        $prof_id   = $this->create_pending_application( $applicant );

        wp_set_current_user( $admin );
        $response = $this->call_review( $prof_id, 'reject', 'License could not be verified.' );

        $this->assertTrue( $response['success'] );
        $this->assertEquals( 'rejected', $response['data']['status'] );

        $applicant_user = get_userdata( $applicant );
        $this->assertNotContains( 'kounselia_professional', $applicant_user->roles );

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kounselia_professionals WHERE id = %d", $prof_id ) );
        $this->assertEquals( 'rejected', $row->status );
        $this->assertEquals( 'License could not be verified.', $row->rejection_reason );
    }

    function test_a_non_admin_cannot_review_applications() {
        $applicant = self::factory()->user->create( array( 'role' => 'subscriber' ) );
        $bystander = self::factory()->user->create( array( 'role' => 'subscriber' ) );
        $prof_id   = $this->create_pending_application( $applicant );

        wp_set_current_user( $bystander );
        $response = $this->call_review( $prof_id, 'approve' );

        $this->assertFalse( $response['success'] );

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}kounselia_professionals WHERE id = %d", $prof_id ) );
        $this->assertEquals( 'pending', $row->status );

        $applicant_user = get_userdata( $applicant );
        $this->assertNotContains( 'kounselia_professional', $applicant_user->roles );
    }

    function test_approving_an_already_rejected_application_still_grants_the_role() {
        // A professional can fix their documents and reapply — the same
        // application row moves pending -> rejected -> (reapply resets
        // to pending, see kounselia_ajax_apply_professional) -> approved.
        // This just confirms approve() doesn't special-case a prior rejection.
        $admin     = self::factory()->user->create( array( 'role' => 'administrator' ) );
        $applicant = self::factory()->user->create( array( 'role' => 'subscriber' ) );
        $prof_id   = $this->create_pending_application( $applicant );

        wp_set_current_user( $admin );
        $this->call_review( $prof_id, 'reject', 'Not this time.' );
        $this->_last_response = null;
        $response = $this->call_review( $prof_id, 'approve' );

        $this->assertTrue( $response['success'] );
        $applicant_user = get_userdata( $applicant );
        $this->assertContains( 'kounselia_professional', $applicant_user->roles );
    }
}

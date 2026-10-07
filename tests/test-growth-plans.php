<?php
/**
 * Growth plans (Personal Development): a member picks an area, answers a
 * few questions and gets a 30 day plan. The AI is replaced here by the
 * 'kounselia_growth_plan_ai_response' filter, so these tests never call it.
 */
class Test_Growth_Plans extends WP_Ajax_UnitTestCase {

    private function fake_plan_json( $days = 30 ) {
        $list = array();
        for ( $i = 1; $i <= $days; $i++ ) {
            $list[] = array( 'day' => $i, 'title' => "Step $i", 'task' => "Do small thing $i.", 'minutes' => 10 );
        }
        return wp_json_encode( array( 'title' => 'Steady mornings', 'summary' => 'A calmer month.', 'days' => $list ) );
    }

    private function use_fake_ai( $json = null ) {
        $json = null === $json ? $this->fake_plan_json() : $json;
        add_filter( 'kounselia_growth_plan_ai_response', function () use ( $json ) {
            return $json;
        } );
    }

    private function answers() {
        return array( 'goal' => 'Wake up early', 'time' => '15' );
    }

    private function ajax( $action, $params = array() ) {
        $_POST = array_merge( array( 'action' => $action, 'nonce' => wp_create_nonce( 'kounselia_auth' ) ), $params );
        try {
            $this->_handleAjax( $action );
        } catch ( WPAjaxDieContinueException $e ) {
            // Expected: wp_send_json_* dies by design under the ajax test harness.
        } catch ( WPAjaxDieStopException $e ) {
            // Also expected for error responses.
        }
        $response             = json_decode( $this->_last_response, true );
        $this->_last_response = '';
        return $response;
    }

    function test_creating_a_plan_saves_thirty_days_and_starts_today() {
        $user = self::factory()->user->create();
        $this->use_fake_ai();

        $id = kounselia_growth_create_plan( $user, 'discipline', $this->answers() );
        $this->assertIsInt( $id );

        $plan = kounselia_growth_format_plan( kounselia_growth_active_plan( $user ) );
        $this->assertSame( 30, count( $plan['days'] ) );
        $this->assertSame( 1, $plan['current_day'] );
        $this->assertSame( 'Step 1', $plan['today']['title'] );
        $this->assertSame( 0, $plan['done_count'] );
    }

    function test_a_reply_with_too_few_days_is_not_saved() {
        $user = self::factory()->user->create();
        $this->use_fake_ai( $this->fake_plan_json( 12 ) );

        $result = kounselia_growth_create_plan( $user, 'discipline', $this->answers() );
        $this->assertWPError( $result );
        $this->assertNull( kounselia_growth_active_plan( $user ) );
    }

    function test_the_main_question_is_required() {
        $user = self::factory()->user->create();
        $this->use_fake_ai();

        $this->assertWPError( kounselia_growth_create_plan( $user, 'habits', array( 'time' => '5' ) ) );
        $this->assertWPError( kounselia_growth_create_plan( $user, 'not-an-area', $this->answers() ) );
    }

    function test_only_one_plan_runs_at_a_time() {
        $user = self::factory()->user->create();
        $this->use_fake_ai();

        kounselia_growth_create_plan( $user, 'discipline', $this->answers() );
        $second = kounselia_growth_create_plan( $user, 'habits', $this->answers() );
        $this->assertWPError( $second );
        $this->assertSame( 'has_plan', $second->get_error_code() );
    }

    function test_free_members_get_three_plans_and_pro_is_unlimited() {
        $this->use_fake_ai();

        $free = self::factory()->user->create();
        for ( $i = 0; $i < 3; $i++ ) {
            $id = kounselia_growth_create_plan( $free, 'discipline', $this->answers() );
            $this->assertIsInt( $id );
            // Finish it on a later day so it counts as used.
            global $wpdb;
            $wpdb->update( kounselia_growth_table(), array( 'status' => 'completed', 'start_date' => '2020-01-01', 'ended_at' => '2020-01-31 00:00:00' ), array( 'id' => $id ) );
        }
        $fourth = kounselia_growth_create_plan( $free, 'discipline', $this->answers() );
        $this->assertWPError( $fourth );
        $this->assertSame( 'limit', $fourth->get_error_code() );

        $pro = self::factory()->user->create();
        update_user_meta( $pro, 'kounselia_plan', 'pro' );
        $this->assertNull( kounselia_growth_allowance( $pro )['remaining'] );
    }

    function test_a_plan_ended_the_day_it_started_is_given_back() {
        $user = self::factory()->user->create();
        $this->use_fake_ai();

        $id = kounselia_growth_create_plan( $user, 'discipline', $this->answers() );
        $this->assertSame( 1, kounselia_growth_allowance( $user )['used'] );
        kounselia_growth_end_plan( $user, $id );
        $this->assertSame( 0, kounselia_growth_allowance( $user )['used'] );
    }

    function test_ticking_days_off_and_the_streak() {
        $user = self::factory()->user->create();
        $this->use_fake_ai();
        $id = kounselia_growth_create_plan( $user, 'discipline', $this->answers() );

        // Pretend it is day 4: the plan started three days ago.
        global $wpdb;
        $wpdb->update( kounselia_growth_table(), array( 'start_date' => gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) ) - 3 * DAY_IN_SECONDS ) ), array( 'id' => $id ) );

        $this->assertTrue( kounselia_growth_set_day_done( $user, $id, 2, true ) );
        $this->assertTrue( kounselia_growth_set_day_done( $user, $id, 3, true ) );
        $plan = kounselia_growth_format_plan( kounselia_growth_active_plan( $user ) );
        $this->assertSame( 4, $plan['current_day'] );
        $this->assertSame( 2, $plan['done_count'] );
        $this->assertSame( 2, $plan['streak'], 'Days 2 and 3 in a row; today not done yet does not break it.' );

        // A day that has not come yet cannot be ticked off.
        $this->assertWPError( kounselia_growth_set_day_done( $user, $id, 9, true ) );

        // Un-ticking works.
        kounselia_growth_set_day_done( $user, $id, 3, false );
        $this->assertSame( 1, kounselia_growth_format_plan( kounselia_growth_active_plan( $user ) )['done_count'] );
    }

    function test_a_plan_whose_thirty_days_have_passed_is_completed() {
        $user = self::factory()->user->create();
        $this->use_fake_ai();
        $id = kounselia_growth_create_plan( $user, 'discipline', $this->answers() );

        global $wpdb;
        $wpdb->update( kounselia_growth_table(), array( 'start_date' => '2020-01-01' ), array( 'id' => $id ) );

        $this->assertNull( kounselia_growth_active_plan( $user ) );
        $this->assertSame( 'completed', $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . kounselia_growth_table() . ' WHERE id = %d', $id ) ) );
    }

    function test_members_cannot_touch_each_others_plans() {
        $owner = self::factory()->user->create();
        $other = self::factory()->user->create();
        $this->use_fake_ai();
        $id = kounselia_growth_create_plan( $owner, 'discipline', $this->answers() );

        $this->assertWPError( kounselia_growth_set_day_done( $other, $id, 1, true ) );
        $this->assertFalse( kounselia_growth_end_plan( $other, $id ) );
        $this->assertNotNull( kounselia_growth_active_plan( $owner ) );
    }

    function test_the_ajax_actions_round_trip() {
        $user = self::factory()->user->create();
        wp_set_current_user( $user );
        $this->use_fake_ai();

        $created = $this->ajax( 'kounselia_growth_create', array( 'area' => 'confidence', 'answers' => wp_json_encode( $this->answers() ) ) );
        $this->assertTrue( $created['success'] );
        $plan_id = $created['data']['plan']['id'];

        $marked = $this->ajax( 'kounselia_growth_mark_day', array( 'plan_id' => $plan_id, 'day' => 1, 'done' => 1 ) );
        $this->assertTrue( $marked['success'] );
        $this->assertSame( 1, $marked['data']['plan']['done_count'] );

        $ended = $this->ajax( 'kounselia_growth_end', array( 'plan_id' => $plan_id ) );
        $this->assertTrue( $ended['success'] );
        $this->assertNull( $ended['data']['plan'] );
    }

    function test_the_plan_counselor_is_told_about_the_plan_in_chat() {
        $user = self::factory()->user->create();
        $this->use_fake_ai();
        kounselia_growth_create_plan( $user, 'discipline', $this->answers() );

        $clause = kounselia_growth_chat_clause( $user, kounselia_growth_counselor_slug() );
        $this->assertStringContainsString( 'Steady mornings', $clause );
        $this->assertStringContainsString( 'Do small thing 1.', $clause );
        $this->assertSame( '', kounselia_growth_chat_clause( $user, 'someone_else' ) );
    }
}

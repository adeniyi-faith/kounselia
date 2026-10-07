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

    private function make_plan_on_day( $user, $day ) {
        $this->use_fake_ai();
        $id = kounselia_growth_create_plan( $user, 'discipline', $this->answers() );
        global $wpdb;
        $wpdb->update( kounselia_growth_table(), array( 'start_date' => gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) ) - ( $day - 1 ) * DAY_IN_SECONDS ) ), array( 'id' => $id ) );
        return $id;
    }

    private function fake_review_json( $first_day, $level = 'easier', $count = 7 ) {
        $up = array();
        for ( $d = $first_day; $d < $first_day + $count; $d++ ) {
            $up[] = array( 'day' => $d, 'title' => "Gentler $d", 'task' => "A smaller step $d.", 'minutes' => 5 );
        }
        return wp_json_encode( array( 'note' => 'Good first week. Keep it light.', 'level' => $level, 'upcoming' => $up ) );
    }

    function test_reminder_defaults_to_nine_and_can_be_changed_or_turned_off() {
        $user = self::factory()->user->create();
        $this->use_fake_ai();
        $id = kounselia_growth_create_plan( $user, 'discipline', $this->answers() );

        $this->assertSame( 9, kounselia_growth_format_plan( kounselia_growth_active_plan( $user ), false )['remind_hour'] );
        $this->assertTrue( kounselia_growth_set_reminder( $user, $id, 18 ) );
        $this->assertSame( 18, kounselia_growth_format_plan( kounselia_growth_active_plan( $user ), false )['remind_hour'] );
        $this->assertTrue( kounselia_growth_set_reminder( $user, $id, -1 ) );
        $this->assertWPError( kounselia_growth_set_reminder( $user, $id, 40 ) );
    }

    function test_reminders_go_out_once_a_day_and_skip_done_days() {
        global $wpdb;
        $due   = self::factory()->user->create();
        $done  = self::factory()->user->create();
        $off   = self::factory()->user->create();
        $ids   = array();
        foreach ( array( 'due' => $due, 'done' => $done, 'off' => $off ) as $key => $user ) {
            $ids[ $key ] = $this->make_plan_on_day( $user, 3 );
            // Not reminded yet today, and the hour has already come.
            $wpdb->update( kounselia_growth_table(), array( 'last_reminded' => '2020-01-01', 'remind_hour' => 0 ), array( 'id' => $ids[ $key ] ) );
        }
        kounselia_growth_set_day_done( $done, $ids['done'], 3, true );
        kounselia_growth_set_reminder( $off, $ids['off'], -1 );

        kounselia_growth_send_reminders();
        kounselia_growth_send_reminders(); // A second run the same day sends nothing more.

        $count = function ( $user ) use ( $wpdb ) {
            return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_notifications WHERE user_id = %d AND type = 'growth_reminder'", $user ) );
        };
        $this->assertSame( 1, $count( $due ) );
        $this->assertSame( 0, $count( $done ) );
        $this->assertSame( 0, $count( $off ) );
    }

    function test_a_review_is_not_ready_until_the_week_is_over() {
        $user = self::factory()->user->create();
        $id   = $this->make_plan_on_day( $user, 5 );
        $this->assertNull( kounselia_growth_format_plan( kounselia_growth_active_plan( $user ), false )['review_ready'] );
        $this->assertWPError( kounselia_growth_review_week( $user, $id, 1 ) );
    }

    function test_the_weekly_review_is_saved_once_and_rewrites_the_next_days() {
        $user = self::factory()->user->create();
        $id   = $this->make_plan_on_day( $user, 7 );
        kounselia_growth_set_day_done( $user, $id, 7, true );
        $this->assertSame( 1, kounselia_growth_format_plan( kounselia_growth_active_plan( $user ), false )['review_ready'] );

        add_filter( 'kounselia_growth_review_ai_response', function () {
            return $this->fake_review_json( 8 );
        } );
        $this->assertTrue( kounselia_growth_review_week( $user, $id, 1 ) );

        $plan = kounselia_growth_format_plan( kounselia_growth_active_plan( $user ) );
        $this->assertNull( $plan['review_ready'] );
        $this->assertSame( 'Good first week. Keep it light.', $plan['reviews'][0]['note'] );
        $this->assertSame( 7, $plan['reviews'][0]['changed'] );
        $this->assertSame( 'A smaller step 8.', $plan['days'][7]['task'] );
        $this->assertSame( 'Do small thing 15.', $plan['days'][14]['task'], 'Days beyond the next seven are untouched.' );
        $this->assertSame( 'Do small thing 7.', $plan['days'][6]['task'], 'Days already passed are untouched.' );

        $this->assertWPError( kounselia_growth_review_week( $user, $id, 1 ) );
    }

    function test_a_review_with_the_wrong_days_keeps_the_note_but_changes_nothing() {
        $user = self::factory()->user->create();
        $id   = $this->make_plan_on_day( $user, 7 );
        add_filter( 'kounselia_growth_review_ai_response', function () {
            return $this->fake_review_json( 8, 'easier', 3 );
        } );

        $this->assertTrue( kounselia_growth_review_week( $user, $id, 1 ) );
        $plan = kounselia_growth_format_plan( kounselia_growth_active_plan( $user ) );
        $this->assertSame( 0, $plan['reviews'][0]['changed'] );
        $this->assertSame( 'Do small thing 8.', $plan['days'][7]['task'] );
    }

    function test_the_final_review_comes_on_day_thirty() {
        $user = self::factory()->user->create();
        $id   = $this->make_plan_on_day( $user, 30 );
        $done = array();
        // Weeks 1 to 4 reviewed already.
        global $wpdb;
        for ( $n = 1; $n <= 4; $n++ ) {
            $done[ $n ] = array( 'week' => $n, 'note' => 'ok', 'level' => 'same', 'changed' => 0, 'at' => '2020-01-01 00:00:00' );
        }
        $wpdb->update( kounselia_growth_table(), array( 'reviews' => wp_json_encode( $done ) ), array( 'id' => $id ) );

        $this->assertSame( 5, kounselia_growth_format_plan( kounselia_growth_active_plan( $user ), false )['review_ready'] );
    }

    function test_the_review_ajax_action_round_trips() {
        $user = self::factory()->user->create();
        wp_set_current_user( $user );
        $id = $this->make_plan_on_day( $user, 7 );
        add_filter( 'kounselia_growth_review_ai_response', function () {
            return $this->fake_review_json( 8, 'same', 7 );
        } );

        $res = $this->ajax( 'kounselia_growth_review', array( 'plan_id' => $id, 'week' => 1 ) );
        $this->assertTrue( $res['success'] );
        $this->assertCount( 1, $res['data']['plan']['reviews'] );

        $res = $this->ajax( 'kounselia_growth_set_reminder', array( 'plan_id' => $id, 'hour' => 7 ) );
        $this->assertTrue( $res['success'] );
        $this->assertSame( 7, $res['data']['plan']['remind_hour'] );
    }
}

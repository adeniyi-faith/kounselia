<?php
/**
 * Reading and Settings for the mobile app (includes/app-content.php):
 * the blog, past journal entries and the account summary.
 */
class Test_App_Content extends WP_Ajax_UnitTestCase {

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

    function test_blog_lists_only_live_posts_with_topics() {
        kounselia_save_blog_post( array( 'title' => 'Sleeping better', 'content' => '<p>Rest.</p>', 'tags' => 'Sleep, Anxiety', 'publish_mode' => 'now' ) );
        kounselia_save_blog_post( array( 'title' => 'Not ready', 'content' => '<p>x</p>', 'publish_mode' => 'draft' ) );

        $res = $this->ajax( 'kounselia_app_blog' );

        $this->assertTrue( $res['success'] );
        $this->assertSame( array( 'sleeping-better' ), array_column( $res['data']['posts'], 'slug' ) );
        $this->assertSame( 'Sleep', $res['data']['posts'][0]['tags'][0]['name'] );
        $this->assertContains( 'anxiety', array_column( $res['data']['tags'], 'slug' ) );
        $this->assertFalse( $res['data']['has_more'] );

        $res = $this->ajax( 'kounselia_app_blog', array( 'tag' => 'work' ) );
        $this->assertSame( array(), $res['data']['posts'] );
    }

    function test_blog_post_has_its_body_without_website_blocks() {
        kounselia_save_blog_post( array( 'title' => 'On grief', 'content' => "<p>Grief comes in waves.</p>\n<p>[kounselia_newsletter]</p>", 'publish_mode' => 'now' ) );

        $res = $this->ajax( 'kounselia_app_blog_post', array( 'slug' => 'on-grief' ) );

        $this->assertTrue( $res['success'] );
        $this->assertStringContainsString( 'Grief comes in waves.', $res['data']['html'] );
        $this->assertStringNotContainsString( 'kounselia_newsletter', $res['data']['html'] );
        $this->assertSame( 1, (int) kounselia_get_blog_post_by_slug( 'on-grief' )->views );

        $missing = $this->ajax( 'kounselia_app_blog_post', array( 'slug' => 'nope' ) );
        $this->assertFalse( $missing['success'] );
    }

    function test_journal_history_is_private_and_newest_first() {
        global $wpdb;
        $me    = self::factory()->user->create();
        $other = self::factory()->user->create();
        $table = $wpdb->prefix . 'kounselia_journal_entries';
        $now   = current_time( 'mysql' );
        $wpdb->insert( $table, array( 'user_id' => $me, 'content' => 'Older day', 'entry_date' => '2026-01-01', 'updated_at' => $now ) );
        $wpdb->insert( $table, array( 'user_id' => $me, 'content' => 'Newer day', 'entry_date' => '2026-01-05', 'updated_at' => $now ) );
        $wpdb->insert( $table, array( 'user_id' => $me, 'content' => '', 'entry_date' => '2026-01-06', 'updated_at' => $now ) );
        $wpdb->insert( $table, array( 'user_id' => $other, 'content' => 'Not yours', 'entry_date' => '2026-01-03', 'updated_at' => $now ) );

        wp_set_current_user( $me );
        $res = $this->ajax( 'kounselia_get_journal_entries' );

        $this->assertSame( array( 'Newer day', 'Older day' ), array_column( $res['data']['entries'], 'content' ) );
        $this->assertSame( '2026-01-05', $res['data']['entries'][0]['date'] );

        wp_set_current_user( 0 );
        $res = $this->ajax( 'kounselia_get_journal_entries' );
        $this->assertFalse( $res['success'] );
    }

    function test_account_has_profile_plan_emails_and_memory() {
        $user = self::factory()->user->create( array( 'display_name' => 'Ada', 'user_email' => 'ada@example.com' ) );
        wp_set_current_user( $user );

        $res = $this->ajax( 'kounselia_app_account' );
        $this->assertTrue( $res['success'] );
        $this->assertSame( 'Ada', $res['data']['user']['name'] );
        $this->assertNull( $res['data']['user']['avatar'] );
        $this->assertSame( 'Free', $res['data']['plan']['title'] );
        $this->assertFalse( $res['data']['emails']['newsletter'] );
        $this->assertNull( $res['data']['memory'] );
        $this->assertStringEndsWith( '/page/safety-resources', $res['data']['links']['safety'] );

        update_user_meta( $user, 'kounselia_plan', 'pro' );
        $this->ajax( 'kounselia_edit_memory', array( 'identity' => 'A nurse in Lagos', 'goals' => 'Sleep more, Run a 5k' ) );
        $res = $this->ajax( 'kounselia_app_account' );
        $this->assertSame( 'gifted', $res['data']['plan']['state'] );
        $this->assertSame( 'A nurse in Lagos', $res['data']['memory']['identity'] );
        $this->assertSame( array( 'Sleep more', 'Run a 5k' ), $res['data']['memory']['goals'] );
    }

    function test_avatar_upload_checks_what_the_file_really_is() {
        $user = self::factory()->user->create();
        wp_set_current_user( $user );

        $fake = $this->ajax( 'kounselia_app_upload_avatar', array( 'mime_type' => 'image/png', 'image_b64' => base64_encode( '<?php echo 1;' ) ) );
        $this->assertFalse( $fake['success'] );
        $this->assertEmpty( get_user_meta( $user, 'kounselia_avatar_id', true ) );

        // A real 1x1 PNG.
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
        $res = $this->ajax( 'kounselia_app_upload_avatar', array( 'mime_type' => 'image/png', 'image_b64' => $png ) );
        $this->assertTrue( $res['success'] );
        $this->assertNotEmpty( get_user_meta( $user, 'kounselia_avatar_id', true ) );
        $this->assertNotEmpty( $res['data']['avatar'] );
    }
}

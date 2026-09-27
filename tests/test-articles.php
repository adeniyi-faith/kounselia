<?php
/**
 * Articles by professionals: who may publish, the review route, held
 * edits to live articles, limits, cleaning, and editors' decisions.
 */
class Test_Articles extends WP_Ajax_UnitTestCase {

    public function set_up() {
        parent::set_up();
        delete_option( 'kounselia_article_settings' );
    }

    private function professional( $name = 'Dr. Ada Obi', $status = 'verified', $publishing = 'default' ) {
        global $wpdb;
        $user = self::factory()->user->create( array( 'display_name' => $name ) );
        $now  = current_time( 'mysql' );
        $wpdb->insert( $wpdb->prefix . 'kounselia_professionals', array(
            'user_id'      => $user,
            'title'        => 'Clinical Psychologist',
            'status'       => $status,
            'publishing'   => $publishing,
            'submitted_at' => $now,
            'created_at'   => $now,
            'updated_at'   => $now,
        ) );
        return kounselia_get_professional_application( $user );
    }

    private function settings( $changes ) {
        update_option( 'kounselia_article_settings', array_merge( kounselia_article_settings(), $changes ) );
    }

    private function body( $words = 300 ) {
        return '<p>' . trim( str_repeat( 'calm ', $words ) ) . '</p>';
    }

    private function article( $title = 'Sleep and worry', $words = 300 ) {
        return array( 'title' => $title, 'content' => $this->body( $words ), 'tags' => 'Sleep, Anxiety' );
    }

    function test_access_rules() {
        $pro = $this->professional();
        $this->assertSame( array( true, 'review' ), array( kounselia_article_access( $pro )['allowed'], kounselia_article_access( $pro )['mode'] ) );

        $this->assertFalse( kounselia_article_access( $this->professional( 'Pending Pro', 'pending' ) )['allowed'] );
        $this->assertFalse( kounselia_article_access( $this->professional( 'Blocked Pro', 'verified', 'blocked' ) )['allowed'] );
        $this->assertSame( 'trusted', kounselia_article_access( $this->professional( 'Trusted Pro', 'verified', 'trusted' ) )['mode'] );

        $this->settings( array( 'who' => 'chosen' ) );
        $this->assertFalse( kounselia_article_access( $pro )['allowed'], 'Only chosen professionals may write.' );
        $this->assertTrue( kounselia_article_access( $this->professional( 'Chosen Pro', 'verified', 'review' ) )['allowed'] );

        $this->settings( array( 'who' => 'all', 'enabled' => 0 ) );
        $this->assertFalse( kounselia_article_access( $this->professional( 'Trusted Two', 'verified', 'trusted' ) )['allowed'], 'Switching the feature off stops everyone.' );
    }

    function test_review_route_keeps_article_private_until_approved() {
        $pro   = $this->professional();
        $saved = kounselia_article_save( $pro, $this->article(), 0, 'draft' );
        $this->assertSame( 'draft', $saved['state'] );

        $sent = kounselia_article_save( $pro, $this->article(), $saved['id'], 'send' );
        $this->assertSame( 'pending', $sent['state'] );
        $this->assertSame( $saved['id'], $sent['id'], 'Sending the draft does not make a copy.' );
        $this->assertSame( 0, kounselia_blog_query()['total'] );
        $this->assertSame( 1, kounselia_article_pending_count() );

        $this->assertTrue( kounselia_article_review( $sent['id'], 'approve' ) );
        $post = kounselia_get_blog_post( $sent['id'] );
        $this->assertTrue( kounselia_blog_post_is_live( $post ) );
        $this->assertSame( 'professional', $post->author_type );
        $this->assertSame( 1, kounselia_blog_query( array( 'author_type' => 'professional' ) )['total'] );
        $this->assertSame( 0, kounselia_blog_query( array( 'author_type' => 'staff' ) )['total'] );
        $this->assertNotFalse( wp_next_scheduled( 'kounselia_notify_followers_of_article', array( (int) $post->id ) ) );

        $author = kounselia_blog_author( $post );
        $this->assertTrue( $author['is_professional'] );
        $this->assertSame( (int) $pro->id, $author['professional_id'] );
    }

    function test_trusted_professional_publishes_straight_away() {
        $pro  = $this->professional( 'Trusted Pro', 'verified', 'trusted' );
        $sent = kounselia_article_save( $pro, $this->article(), 0, 'send' );
        $this->assertSame( 'live', $sent['state'] );
        $this->assertTrue( kounselia_blog_post_is_live( kounselia_get_blog_post( $sent['id'] ) ) );
    }

    function test_too_short_is_refused_but_the_draft_is_kept() {
        $pro    = $this->professional();
        $result = kounselia_article_save( $pro, $this->article( 'Short one', 20 ), 0, 'send' );
        $this->assertWPError( $result );
        $this->assertSame( 'too_short', $result->get_error_code() );
        $data = $result->get_error_data();
        $post = kounselia_get_blog_post( $data['id'] );
        $this->assertSame( 'Short one', $post->title );
        $this->assertSame( 'draft', kounselia_article_state( $post ) );
    }

    function test_weekly_limit() {
        $this->settings( array( 'weekly_limit' => 1 ) );
        $pro   = $this->professional();
        $first = kounselia_article_save( $pro, $this->article( 'One' ), 0, 'send' );
        $this->assertSame( 'pending', $first['state'] );
        $second = kounselia_article_save( $pro, $this->article( 'Two' ), 0, 'send' );
        $this->assertWPError( $second );
        $this->assertSame( 'weekly_limit', $second->get_error_code() );

        // Resending the same article after changes are requested is not a new one.
        kounselia_article_review( $first['id'], 'changes', 'Please add a source.' );
        $again = kounselia_article_save( $pro, $this->article( 'One, with a source' ), $first['id'], 'send' );
        $this->assertSame( 'pending', $again['state'] );
    }

    function test_edits_to_a_live_article_are_held_for_review() {
        $pro  = $this->professional();
        $sent = kounselia_article_save( $pro, $this->article( 'Original title' ), 0, 'send' );
        kounselia_article_review( $sent['id'], 'approve' );
        $slug = kounselia_get_blog_post( $sent['id'] )->slug;

        $edit = kounselia_article_save( $pro, $this->article( 'New title' ), $sent['id'], 'send' );
        $this->assertSame( 'live_pending', $edit['state'] );
        $post = kounselia_get_blog_post( $sent['id'] );
        $this->assertSame( 'Original title', $post->title, 'Readers keep the approved version.' );
        $this->assertSame( 'New title', kounselia_article_editable_fields( $post )['title'], 'The professional keeps editing their newest version.' );

        kounselia_article_review( $sent['id'], 'approve' );
        $post = kounselia_get_blog_post( $sent['id'] );
        $this->assertSame( 'New title', $post->title );
        $this->assertSame( $slug, $post->slug, 'The address never changes once live.' );
        $this->assertNull( $post->pending_changes );
    }

    function test_changes_and_reject_need_a_note_and_remove_takes_down() {
        $pro  = $this->professional();
        $sent = kounselia_article_save( $pro, $this->article(), 0, 'send' );
        $this->assertWPError( kounselia_article_review( $sent['id'], 'changes', '' ) );
        $this->assertTrue( kounselia_article_review( $sent['id'], 'changes', 'Shorter intro please.' ) );
        $this->assertSame( 'changes', kounselia_article_state( kounselia_get_blog_post( $sent['id'] ) ) );

        kounselia_article_save( $pro, $this->article(), $sent['id'], 'send' );
        kounselia_article_review( $sent['id'], 'approve' );
        kounselia_article_review( $sent['id'], 'remove', 'Needs a medical review.' );
        $this->assertSame( 'removed', kounselia_article_state( kounselia_get_blog_post( $sent['id'] ) ) );
        $this->assertSame( 0, kounselia_blog_query()['total'] );
    }

    function test_professionals_cannot_touch_each_others_articles() {
        $ada  = $this->professional( 'Ada' );
        $bola = $this->professional( 'Bola' );
        $mine = kounselia_article_save( $ada, $this->article(), 0, 'draft' );
        $this->assertWPError( kounselia_article_save( $bola, $this->article( 'Hijack' ), $mine['id'], 'draft' ) );
        $this->assertWPError( kounselia_article_withdraw( $bola, $mine['id'] ) );
        $this->assertSame( 'Sleep and worry', kounselia_get_blog_post( $mine['id'] )->title );
    }

    function test_article_html_is_cleaned_for_professionals() {
        $this->settings( array( 'allow_images' => 0, 'allow_embeds' => 0 ) );
        $html = kounselia_article_clean_html(
            '<h1>Big</h1><p>[kounselia_plans]</p><p>Hi <img src="https://x.test/a.jpg"></p>'
            . '<iframe src="https://www.youtube.com/embed/abc"></iframe><script>alert(1)</script>'
        );
        $this->assertStringContainsString( '<h2>Big</h2>', $html );
        $this->assertStringNotContainsString( 'kounselia_plans', $html );
        $this->assertStringNotContainsString( '<img', $html );
        $this->assertStringNotContainsString( 'iframe', $html );
        $this->assertStringNotContainsString( 'script', $html );
    }

    function test_images_inside_articles_must_be_uploaded_here() {
        $ours = wp_get_upload_dir()['baseurl'] . '/2026/09/calm.jpg';
        $html = kounselia_article_clean_html( '<p><img src="' . $ours . '" alt=""></p><p><img src="https://tracker.example/pixel.gif"></p>' );
        $this->assertStringContainsString( 'calm.jpg', $html );
        $this->assertStringNotContainsString( 'tracker.example', $html );
    }

    function test_cover_must_be_uploaded_here() {
        $pro   = $this->professional();
        $data  = array_merge( $this->article(), array( 'cover_image' => 'https://elsewhere.example/tracker.jpg' ) );
        $saved = kounselia_article_save( $pro, $data, 0, 'draft' );
        $this->assertNull( kounselia_get_blog_post( $saved['id'] )->cover_image );
    }

    function test_editor_saving_in_admin_blog_keeps_the_professional_as_author() {
        $pro   = $this->professional();
        $sent  = kounselia_article_save( $pro, $this->article(), 0, 'send' );
        $admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin );
        kounselia_save_blog_post( array( 'title' => 'Edited by an editor', 'content' => 'x', 'author_id' => $admin, 'publish_mode' => 'now' ), $sent['id'] );
        $post = kounselia_get_blog_post( $sent['id'] );
        $this->assertSame( (int) $pro->user_id, (int) $post->author_id );
        $this->assertSame( 'approved', $post->review_status );
        $this->assertTrue( kounselia_blog_post_is_live( $post ) );
    }

    function test_blocking_with_take_down() {
        $pro  = $this->professional( 'Trusted Pro', 'verified', 'trusted' );
        $sent = kounselia_article_save( $pro, $this->article(), 0, 'send' );
        $this->assertSame( 1, kounselia_article_set_publishing( $pro->id, 'blocked', 'Complaints', true ) );
        $this->assertSame( 'removed', kounselia_article_state( kounselia_get_blog_post( $sent['id'] ) ) );
        $this->assertFalse( kounselia_article_access( kounselia_get_professional_application( $pro->user_id ) )['allowed'] );
    }

    function test_withdraw_and_unpublish_own_article() {
        $pro  = $this->professional( 'Trusted Pro', 'verified', 'trusted' );
        $sent = kounselia_article_save( $pro, $this->article(), 0, 'send' );
        $this->assertIsString( kounselia_article_withdraw( $pro, $sent['id'] ) );
        $this->assertSame( 'draft', kounselia_article_state( kounselia_get_blog_post( $sent['id'] ) ) );
    }

    function test_settings_are_kept_in_bounds() {
        $clean = kounselia_article_sanitize_settings( array( 'weekly_limit' => 999, 'min_words' => 900, 'max_words' => 10, 'who' => 'nonsense', 'enabled' => '0' ) );
        $this->assertSame( 50, $clean['weekly_limit'] );
        $this->assertSame( 950, $clean['max_words'] );
        $this->assertSame( 'all', $clean['who'] );
        $this->assertSame( 0, $clean['enabled'] );
    }

    function test_pro_save_over_ajax_needs_a_professional() {
        $member = self::factory()->user->create();
        wp_set_current_user( $member );
        $_POST = array( 'action' => 'kounselia_pro_article_save', 'nonce' => wp_create_nonce( 'kounselia_auth' ), 'title' => 'Sneaky', 'content' => 'x' );
        try {
            $this->_handleAjax( 'kounselia_pro_article_save' );
        } catch ( WPAjaxDieContinueException $e ) {
            // Expected.
        }
        $response = json_decode( $this->_last_response, true );
        $this->assertFalse( $response['success'] );
        $this->assertNull( kounselia_get_blog_post_by_slug( 'sneaky', false ) );
    }
}

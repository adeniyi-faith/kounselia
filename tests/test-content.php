<?php
/**
 * Pages and blog posts: friendly addresses, drafts/scheduling staying
 * private, cleaning of editor HTML, and the permission check that
 * stops staff without access from saving.
 */
class Test_Content extends WP_Ajax_UnitTestCase {

    function test_footer_pages_are_created_and_published() {
        foreach ( array( 'our-mission', 'research', 'partnerships', 'grant-enquiries', 'press', 'safety-resources', 'pro-plans' ) as $slug ) {
            $this->assertNotNull( kounselia_get_page_by_slug( $slug ), "Missing default page: {$slug}" );
        }
    }

    function test_slugs_are_friendly_unique_and_avoid_reserved_words() {
        $a = kounselia_save_blog_post( array( 'title' => 'Sleep & Anxiety: What Helps?', 'content' => 'x' ) );
        $b = kounselia_save_blog_post( array( 'title' => 'Sleep & Anxiety: What Helps?', 'content' => 'x' ) );
        $this->assertSame( 'sleep-anxiety-what-helps', kounselia_get_blog_post( $a )->slug );
        $this->assertSame( 'sleep-anxiety-what-helps-2', kounselia_get_blog_post( $b )->slug );
        $this->assertSame( 'feed-post', kounselia_content_unique_slug( 'posts', 'feed' ) );
    }

    function test_drafts_and_scheduled_posts_stay_private() {
        $draft = kounselia_save_blog_post( array( 'title' => 'Draft story', 'content' => 'x', 'publish_mode' => 'draft' ) );
        kounselia_save_blog_post( array( 'title' => 'Later story', 'content' => 'x', 'publish_mode' => 'schedule', 'publish_at' => date( 'Y-m-d H:i', current_time( 'timestamp' ) + HOUR_IN_SECONDS ) ) );
        kounselia_save_blog_post( array( 'title' => 'Live story', 'content' => 'x', 'publish_mode' => 'now' ) );

        $this->assertNull( kounselia_get_blog_post_by_slug( 'draft-story' ) );
        $this->assertNull( kounselia_get_blog_post_by_slug( 'later-story' ) );
        $this->assertNotNull( kounselia_get_blog_post_by_slug( 'live-story' ) );
        $this->assertNotNull( kounselia_get_blog_post_by_slug( 'draft-story', false ), 'Staff preview can still load drafts.' );

        $listed = wp_list_pluck( kounselia_blog_query()['items'], 'slug' );
        $this->assertSame( array( 'live-story' ), $listed );
    }

    function test_renamed_post_keeps_old_address_working() {
        $id = kounselia_save_blog_post( array( 'title' => 'First title', 'content' => 'x', 'publish_mode' => 'now' ) );
        kounselia_save_blog_post( array( 'title' => 'First title', 'slug' => 'better-title', 'content' => 'x', 'publish_mode' => 'now' ), $id );
        $this->assertSame( 'better-title', kounselia_blog_slug_redirect( 'first-title' ) );
    }

    function test_tags_and_topic_listing() {
        kounselia_save_blog_post( array( 'title' => 'One', 'content' => 'x', 'tags' => 'Grief, Self care', 'publish_mode' => 'now' ) );
        kounselia_save_blog_post( array( 'title' => 'Two', 'content' => 'x', 'tags' => 'grief', 'publish_mode' => 'now' ) );
        $this->assertSame( 2, kounselia_blog_query( array( 'tag' => 'grief' ) )['total'] );
        $this->assertSame( 1, kounselia_blog_query( array( 'tag' => 'self-care' ) )['total'] );
        $this->assertSame( 2, kounselia_blog_all_tags()['grief']['count'] );
    }

    function test_editor_html_is_cleaned() {
        $html = kounselia_content_kses( '<p onclick="x()">Hi</p><script>alert(1)</script>'
            . '<iframe src="https://www.youtube.com/embed/abc"></iframe><iframe src="https://evil.example/x"></iframe>' );
        $this->assertStringNotContainsString( 'script', $html );
        $this->assertStringNotContainsString( 'onclick', $html );
        $this->assertStringContainsString( 'youtube.com/embed/abc', $html );
        $this->assertStringNotContainsString( 'evil.example', $html );
    }

    function test_staff_without_blog_permission_cannot_save_posts() {
        $staff = self::factory()->user->create( array( 'role' => 'kounselia_staff' ) );
        update_user_meta( $staff, 'kounselia_permissions', wp_json_encode( array( 'members' ) ) );
        wp_set_current_user( $staff );

        $_POST = array( 'action' => 'kounselia_admin_save_post', 'nonce' => wp_create_nonce( 'kounselia_admin_nonce' ), 'title' => 'Sneaky', 'content' => 'x' );
        try {
            $this->_handleAjax( 'kounselia_admin_save_post' );
        } catch ( WPAjaxDieContinueException $e ) {
            // Expected.
        }
        $response = json_decode( $this->_last_response, true );
        $this->assertFalse( $response['success'] );
        $this->assertNull( kounselia_get_blog_post_by_slug( 'sneaky', false ) );
    }

    function test_staff_with_blog_permission_can_save_posts() {
        $staff = self::factory()->user->create( array( 'role' => 'kounselia_staff' ) );
        update_user_meta( $staff, 'kounselia_permissions', wp_json_encode( array( 'blog' ) ) );
        wp_set_current_user( $staff );

        $_POST = array( 'action' => 'kounselia_admin_save_post', 'nonce' => wp_create_nonce( 'kounselia_admin_nonce' ), 'title' => 'Allowed', 'content' => '<p>x</p>', 'publish_mode' => 'now' );
        try {
            $this->_handleAjax( 'kounselia_admin_save_post' );
        } catch ( WPAjaxDieContinueException $e ) {
            // Expected.
        }
        $response = json_decode( $this->_last_response, true );
        $this->assertTrue( $response['success'] );
        $this->assertSame( '/blog/allowed', $response['data']['url'] );
    }
}

<?php
/**
 * The community around the Journal: following, loves, comments,
 * privacy of names, the crisis check on comments, reports and
 * moderation, and telling followers about new articles.
 */
class Test_Community extends WP_Ajax_UnitTestCase {

    private $pro;
    private $post;

    public function set_up() {
        parent::set_up();
        delete_option( 'kounselia_article_settings' );
        global $wpdb;
        $user = self::factory()->user->create( array( 'display_name' => 'Dr. Ada Obi' ) );
        $now  = current_time( 'mysql' );
        $wpdb->insert( $wpdb->prefix . 'kounselia_professionals', array(
            'user_id' => $user, 'title' => 'Psychologist', 'status' => 'verified', 'publishing' => 'trusted',
            'submitted_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ) );
        $this->pro  = kounselia_get_professional_application( $user );
        $saved      = kounselia_article_save( $this->pro, array( 'title' => 'On grief', 'content' => '<p>' . str_repeat( 'word ', 300 ) . '</p>' ), 0, 'send' );
        $this->post = kounselia_get_blog_post( $saved['id'] );
    }

    private function member( $name = 'Chioma Adeyemi Private', $mode = 'first_name' ) {
        $id = self::factory()->user->create( array( 'display_name' => $name, 'user_email' => strtolower( str_replace( ' ', '', $name ) ) . '@example.com' ) );
        if ( $mode ) {
            kounselia_community_set_identity( $id, $mode, 'nickname' === $mode ? 'Quiet River' : '' );
        }
        return $id;
    }

    private function notifications( $user_id, $type ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_notifications WHERE user_id = %d AND type = %s", $user_id, $type ) );
    }

    function test_members_must_choose_a_name_and_full_names_are_never_shown() {
        $member = $this->member( 'Chioma Adeyemi Private', '' );
        $result = kounselia_comment_add( $member, $this->post->id, 'This helped me.' );
        $this->assertSame( 'need_identity', $result->get_error_code() );

        kounselia_community_set_identity( $member, 'first_name' );
        $result = kounselia_comment_add( $member, $this->post->id, 'This helped me.' );
        $view   = kounselia_comment_view( $result['comment'], $this->post, 0 );
        $this->assertSame( 'Chioma', $view['author']['name'] );
        $this->assertNull( $view['author']['avatar'] );
        $this->assertStringNotContainsString( 'Adeyemi', wp_json_encode( kounselia_comments_for_post( $this->post ) ) );
        $this->assertStringNotContainsString( '@example.com', wp_json_encode( kounselia_comments_for_post( $this->post ) ) );
    }

    function test_nickname_rules() {
        $member = $this->member( 'Tobi', '' );
        $this->assertWPError( kounselia_community_set_identity( $member, 'nickname', 'K' ) );
        $this->assertWPError( kounselia_community_set_identity( $member, 'nickname', 'Kounselia Admin' ) );
        $this->assertWPError( kounselia_community_set_identity( $member, 'nickname', 'The Therapist' ) );
        $this->assertWPError( kounselia_community_set_identity( $member, 'nickname', 'call me $$$' ) );
        $this->assertSame( 'Quiet River', kounselia_community_set_identity( $member, 'nickname', 'Quiet   River' )['name'] );
    }

    function test_comment_counts_and_author_is_told() {
        $member = $this->member();
        kounselia_comment_add( $member, $this->post->id, 'Thank you for this.' );
        $this->assertSame( 1, (int) kounselia_get_blog_post( $this->post->id )->comment_count );
        $this->assertSame( 1, $this->notifications( $this->pro->user_id, 'article_comment' ) );
    }

    function test_the_author_replies_with_their_professional_name_and_the_commenter_is_told() {
        $member = $this->member();
        $first  = kounselia_comment_add( $member, $this->post->id, 'A question about sleep?' );
        $reply  = kounselia_comment_add( (int) $this->pro->user_id, $this->post->id, 'Good question.', $first['comment']->id );
        $view   = kounselia_comment_view( $reply['comment'], $this->post, 0 );
        $this->assertTrue( $view['author']['is_author'] );
        $this->assertSame( 'Dr. Ada Obi', $view['author']['name'] );
        $this->assertSame( 1, $this->notifications( $member, 'comment_reply' ) );

        // A reply to a reply joins the same thread (one level only).
        $again = kounselia_comment_add( $member, $this->post->id, 'Thanks!', $reply['comment']->id );
        $this->assertSame( (int) $first['comment']->id, (int) $again['comment']->parent_id );
        $thread = kounselia_comments_for_post( $this->post )['comments'];
        $this->assertCount( 1, $thread );
        $this->assertCount( 2, $thread[0]['replies'] );
    }

    function test_worrying_comment_is_held_back_and_reaches_the_safety_page() {
        global $wpdb;
        $member = $this->member();
        $other  = $this->member( 'Someone Else' );
        $result = kounselia_comment_add( $member, $this->post->id, 'Honestly I want to kill myself tonight.' );
        $this->assertTrue( $result['safety'] );
        $this->assertSame( 'pending', $result['comment']->status );
        $this->assertSame( 0, (int) kounselia_get_blog_post( $this->post->id )->comment_count );

        $escalation = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}kounselia_safety_escalations WHERE source = 'article_comment'" );
        $this->assertNotNull( $escalation );
        $this->assertSame( (int) $result['comment']->id, (int) $escalation->comment_id );
        $this->assertNotNull( kounselia_safety_escalation_context( $escalation ) );

        $this->assertCount( 0, kounselia_comments_for_post( $this->post, $other )['comments'], 'Nobody else sees it.' );
        $this->assertCount( 1, kounselia_comments_for_post( $this->post, $member )['comments'], 'The writer still sees their own words.' );
        $this->assertSame( 0, $this->notifications( $this->pro->user_id, 'article_comment' ), 'The article author is not told.' );

        // The author cannot release it; only the care team can.
        $this->assertWPError( kounselia_comment_moderate( (int) $this->pro->user_id, $result['comment']->id, 'approve' ) );
    }

    function test_approve_first_mode_holds_comments_for_moderators() {
        update_option( 'kounselia_article_settings', array_merge( kounselia_article_settings(), array( 'comment_moderation' => 'approve_first' ) ) );
        $member = $this->member();
        $result = kounselia_comment_add( $member, $this->post->id, 'Lovely piece.' );
        $this->assertTrue( $result['held'] );
        $admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
        $this->assertTrue( kounselia_comment_moderate( $admin, $result['comment']->id, 'approve' ) );
        $this->assertSame( 1, (int) kounselia_get_blog_post( $this->post->id )->comment_count );
    }

    function test_reports_hide_a_comment_at_the_threshold() {
        update_option( 'kounselia_article_settings', array_merge( kounselia_article_settings(), array( 'report_threshold' => 2 ) ) );
        $writer = $this->member();
        $c      = kounselia_comment_add( $writer, $this->post->id, 'Buy my pills at spam.example' )['comment'];
        $this->assertWPError( kounselia_comment_report( $writer, $c->id, 'spam' ), 'You cannot report yourself.' );
        kounselia_comment_report( $this->member( 'R One' ), $c->id, 'spam' );
        $this->assertSame( 'visible', kounselia_get_comment( $c->id )->status );
        kounselia_comment_report( $this->member( 'R Two' ), $c->id, 'spam' );
        $this->assertSame( 'pending', kounselia_get_comment( $c->id )->status );
        $this->assertSame( 1, kounselia_community_attention_count() );
    }

    function test_author_can_pin_and_hide_but_not_delete() {
        $member = $this->member();
        $a      = kounselia_comment_add( $member, $this->post->id, 'First' )['comment'];
        $b      = kounselia_comment_add( $this->member( 'Other' ), $this->post->id, 'Second' )['comment'];
        $author = (int) $this->pro->user_id;
        $this->assertTrue( kounselia_comment_moderate( $author, $a->id, 'pin' ) );
        $this->assertSame( (int) $a->id, kounselia_comments_for_post( $this->post )['comments'][0]['id'], 'Pinned comes first.' );
        $this->assertTrue( kounselia_comment_moderate( $author, $b->id, 'hide' ) );
        $this->assertWPError( kounselia_comment_moderate( $author, $a->id, 'delete' ) );
        $this->assertWPError( kounselia_comment_moderate( $member, $b->id, 'approve' ), 'Members cannot moderate.' );
    }

    function test_deleting_a_comment_with_replies_leaves_a_placeholder() {
        $member = $this->member();
        $top    = kounselia_comment_add( $member, $this->post->id, 'Top' )['comment'];
        kounselia_comment_add( $this->member( 'Other' ), $this->post->id, 'Reply', $top->id );
        $this->assertTrue( kounselia_comment_delete_own( $member, $top->id ) );
        $thread = kounselia_comments_for_post( $this->post )['comments'];
        $this->assertSame( 'This comment was deleted.', $thread[0]['content'] );
        $this->assertCount( 1, $thread[0]['replies'] );
    }

    function test_rate_limit_and_duplicates() {
        update_option( 'kounselia_article_settings', array_merge( kounselia_article_settings(), array( 'comments_per_hour' => 2 ) ) );
        $member = $this->member();
        kounselia_comment_add( $member, $this->post->id, 'One' );
        $this->assertSame( 'duplicate', kounselia_comment_add( $member, $this->post->id, 'One' )->get_error_code() );
        kounselia_comment_add( $member, $this->post->id, 'Two' );
        $this->assertSame( 'slow_down', kounselia_comment_add( $member, $this->post->id, 'Three' )->get_error_code() );
    }

    function test_loves() {
        $member = $this->member();
        $this->assertSame( 1, kounselia_set_post_love( $member, $this->post->id, true )['count'] );
        $this->assertSame( 1, kounselia_set_post_love( $member, $this->post->id, true )['count'], 'Loving twice counts once.' );
        $this->assertSame( 0, kounselia_set_post_love( $member, $this->post->id, false )['count'] );

        $c = kounselia_comment_add( $member, $this->post->id, 'Hello' )['comment'];
        $this->assertSame( 1, kounselia_set_comment_love( $this->member( 'Fan' ), $c->id, true )['count'] );
    }

    function test_following_and_followers_hear_about_new_articles() {
        global $wpdb;
        $fan = $this->member();
        $this->assertWPError( kounselia_set_following( (int) $this->pro->user_id, $this->pro->id, true ), 'No following yourself.' );
        $this->assertSame( 1, kounselia_set_following( $fan, $this->pro->id, true )['followers'] );
        $this->assertTrue( kounselia_is_following( $fan, $this->pro->id ) );
        $this->assertSame( 1, $this->notifications( $this->pro->user_id, 'new_follower' ) );

        $second = kounselia_article_save( $this->pro, array( 'title' => 'On hope', 'content' => '<p>' . str_repeat( 'word ', 300 ) . '</p>' ), 0, 'send' );
        kounselia_notify_followers_of_article( $second['id'] );
        $this->assertSame( 1, $this->notifications( $fan, 'followed_article' ) );
        kounselia_notify_followers_of_article( $second['id'] );
        $this->assertSame( 1, $this->notifications( $fan, 'followed_article' ), 'Never told twice.' );

        $this->assertSame( 2, kounselia_blog_query( array( 'professional_ids' => kounselia_followed_professional_ids( $fan ) ) )['total'] );
        $this->assertSame( 0, kounselia_blog_query( array( 'professional_ids' => array() ) )['total'], 'Following nobody shows nothing.' );
    }

    function test_deleting_an_article_removes_its_conversation() {
        global $wpdb;
        $member = $this->member();
        kounselia_comment_add( $member, $this->post->id, 'Hi' );
        kounselia_set_post_love( $member, $this->post->id, true );
        kounselia_delete_blog_post( $this->post->id );
        $this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_post_comments" ) );
        $this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_post_loves" ) );
    }

    function test_comments_can_be_switched_off() {
        update_option( 'kounselia_article_settings', array_merge( kounselia_article_settings(), array( 'comments_enabled' => 0 ) ) );
        $this->assertSame( 'closed', kounselia_comment_add( $this->member(), $this->post->id, 'Hi' )->get_error_code() );
    }
}

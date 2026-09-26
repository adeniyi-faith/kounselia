<?php
/**
 * The newsletter decides who gets emailed. A bug here either spams
 * someone who unsubscribed (or a banned account), or emails someone
 * twice — so these drive the real audience, queue and tracking code.
 */
class Test_Newsletter extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        reset_phpmailer_instance();
        update_option( 'kounselia_newsletter_settings', array( 'double_optin' => 0, 'auto_subscribe_members' => 1, 'welcome_enabled' => 0 ) );
    }

    private function member( $email, $name, $meta = array() ) {
        $id = self::factory()->user->create( array( 'role' => 'subscriber', 'user_email' => $email, 'display_name' => $name, 'first_name' => explode( ' ', $name )[0] ) );
        foreach ( $meta as $k => $v ) {
            update_user_meta( $id, $k, $v );
        }
        return $id;
    }

    private function sent_to() {
        $to = array();
        foreach ( tests_retrieve_phpmailer_instance()->mock_sent as $mail ) {
            $to[] = $mail['to'][0][0];
        }
        sort( $to );
        return $to;
    }

    function test_new_members_become_contacts() {
        $id = $this->member( 'ada@example.org', 'Ada Obi' );
        $sub = kounselia_newsletter_get_by_user( $id );
        $this->assertNotNull( $sub );
        $this->assertSame( 'subscribed', $sub->status );
        $this->assertSame( 'Ada', kounselia_newsletter_first_name( $sub ) );
    }

    function test_audience_excludes_unsubscribed_and_banned() {
        $this->member( 'free@example.org', 'Free Member' );
        $this->member( 'pro@example.org', 'Pro Member', array( 'kounselia_plan' => 'pro' ) );
        $this->member( 'banned@example.org', 'Banned Member', array( 'kounselia_banned' => 1 ) );
        $quit = $this->member( 'quit@example.org', 'Quit Member' );
        kounselia_newsletter_set_preferences( kounselia_newsletter_get_by_user( $quit ), array() );
        kounselia_newsletter_subscribe_public( 'web@example.org', 'Web', 'footer' );

        $this->assertSame( 3, kounselia_newsletter_audience_count( array( 'audience' => 'all' ) ) );
        $this->assertSame( 2, kounselia_newsletter_audience_count( array( 'audience' => 'members' ) ) );
        $this->assertSame( 1, kounselia_newsletter_audience_count( array( 'audience' => 'non_members' ) ) );
        $this->assertSame( 1, kounselia_newsletter_audience_count( array( 'audience' => 'pro_members' ) ) );
        $this->assertSame( 1, kounselia_newsletter_audience_count( array( 'audience' => 'free_members' ) ) );
        $this->assertSame( 1, kounselia_newsletter_audience_count( array( 'source' => 'footer' ) ) );
    }

    function test_tag_filters() {
        kounselia_newsletter_upsert( 'a@example.org', array( 'tags' => 'VIP, Lagos' ) );
        kounselia_newsletter_upsert( 'b@example.org', array( 'tags' => 'lagos' ) );
        kounselia_newsletter_upsert( 'c@example.org', array() );

        $this->assertSame( 'vip,lagos', kounselia_newsletter_get_by_email( 'a@example.org' )->tags );
        $this->assertSame( 2, kounselia_newsletter_audience_count( array( 'tags_any' => array( 'lagos' ) ) ) );
        $this->assertSame( 2, kounselia_newsletter_audience_count( array( 'tags_none' => array( 'vip' ) ) ) );
    }

    function test_campaign_sends_to_each_person_exactly_once_in_batches() {
        foreach ( array( 'one', 'two', 'three' ) as $n ) {
            kounselia_newsletter_upsert( "{$n}@example.org", array( 'name' => ucfirst( $n ) ) );
        }
        $cid = kounselia_newsletter_save_campaign( array( 'subject' => 'Hi {first_name}', 'content' => '<p>Hello</p>', 'rules' => array( 'audience' => 'all' ) ) );
        kounselia_newsletter_start_campaign( $cid );

        kounselia_newsletter_process_queue( 2 );
        $this->assertCount( 2, tests_retrieve_phpmailer_instance()->mock_sent );
        kounselia_newsletter_process_queue( 2 );
        kounselia_newsletter_process_queue( 2 ); // Nothing left: must not resend.

        $this->assertSame( array( 'one@example.org', 'three@example.org', 'two@example.org' ), $this->sent_to() );
        $this->assertSame( 'sent', kounselia_newsletter_get_campaign( $cid )->status );
        $this->assertSame( 3, (int) kounselia_newsletter_get_campaign( $cid )->sent_count );

        $mail = tests_retrieve_phpmailer_instance()->mock_sent[0];
        $this->assertMatchesRegularExpression( '/^Hi (One|Two|Three)$/', $mail['subject'] );
        $this->assertStringContainsString( 'List-Unsubscribe-Post', $mail['header'] );
        $this->assertStringContainsString( '/newsletter/?a=unsubscribe', $mail['body'] );
    }

    function test_unsubscribing_mid_send_is_respected() {
        kounselia_newsletter_upsert( 'stay@example.org', array() );
        kounselia_newsletter_upsert( 'leave@example.org', array() );
        $cid = kounselia_newsletter_save_campaign( array( 'subject' => 'News', 'content' => '<p>x</p>' ) );
        kounselia_newsletter_start_campaign( $cid );

        kounselia_newsletter_set_preferences( kounselia_newsletter_get_by_email( 'leave@example.org' ), array() );
        kounselia_newsletter_process_queue( 10 );

        $this->assertSame( array( 'stay@example.org' ), $this->sent_to() );
    }

    function test_click_tracking_only_follows_signed_links() {
        kounselia_newsletter_upsert( 'reader@example.org', array() );
        $cid = kounselia_newsletter_save_campaign( array( 'subject' => 'Links', 'content' => '<p><a href="https://example.org/read">Read</a></p>' ) );
        kounselia_newsletter_start_campaign( $cid );
        kounselia_newsletter_process_queue( 10 );

        global $wpdb;
        $token = $wpdb->get_var( "SELECT token FROM {$wpdb->prefix}kounselia_campaign_recipients WHERE campaign_id = {$cid}" );
        $url   = 'https://example.org/read';

        $this->assertSame( $url, kounselia_newsletter_track_click( $token, $url, kounselia_newsletter_click_sig( $url ) ) );
        $this->assertStringNotContainsString( 'evil', kounselia_newsletter_track_click( $token, 'https://evil.example/', 'forged' ) );

        kounselia_newsletter_track_open( $token );
        $campaign = kounselia_newsletter_get_campaign( $cid );
        $this->assertSame( 1, (int) $campaign->open_count );
        $this->assertSame( 1, (int) $campaign->click_count );
    }

    function test_blog_post_is_emailed_once_and_only_to_blog_list() {
        kounselia_newsletter_upsert( 'blogfan@example.org', array() );
        kounselia_newsletter_upsert( 'newsonly@example.org', array( 'list_blog' => 0 ) );

        $post_id = kounselia_save_blog_post( array( 'title' => 'A new story', 'content' => '<p>Body</p>', 'publish_mode' => 'now' ) );
        $first   = kounselia_newsletter_queue_post_notification( $post_id );
        $second  = kounselia_newsletter_queue_post_notification( $post_id );
        kounselia_newsletter_process_queue( 10 );

        $this->assertSame( $first, $second );
        $this->assertSame( array( 'blogfan@example.org' ), $this->sent_to() );
    }

    function test_scheduled_post_email_waits_until_it_goes_live() {
        kounselia_newsletter_upsert( 'reader@example.org', array() );
        $post_id = kounselia_save_blog_post( array(
            'title'        => 'Tomorrow',
            'content'      => '<p>x</p>',
            'publish_mode' => 'schedule',
            'publish_at'   => date( 'Y-m-d H:i', current_time( 'timestamp' ) + DAY_IN_SECONDS ),
        ) );
        $cid = kounselia_newsletter_queue_post_notification( $post_id );
        kounselia_newsletter_process_queue( 10 );

        $this->assertSame( 'scheduled', kounselia_newsletter_get_campaign( $cid )->status );
        $this->assertSame( array(), $this->sent_to() );
    }

    function test_import_never_resubscribes_someone_who_left() {
        kounselia_newsletter_upsert( 'left@example.org', array( 'status' => 'unsubscribed' ) );
        $sub = kounselia_newsletter_upsert( 'left@example.org', array( 'source' => 'import', 'add_tags' => 'event' ) );
        $this->assertSame( 'unsubscribed', $sub->status );
    }
}

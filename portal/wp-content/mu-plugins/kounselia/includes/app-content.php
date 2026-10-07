<?php
/**
 * Kounselia Core — reading and account screens for the mobile app.
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 *
 *   kounselia_app_blog            the blog ("The Kounselia Journal"), a page at a time
 *                                 (from=professionals|following narrows it)
 *   kounselia_app_blog_post       one blog post, with its body, related posts,
 *                                 and loves / comments / follow state (community.php
 *                                 has the actions themselves)
 *   kounselia_get_journal_entries a member's past private journal entries
 *   kounselia_app_account         everything the app's Settings screen shows
 *   kounselia_app_upload_avatar   a new profile photo, sent as base64
 *
 * The blog is public, so its two actions work signed in or not. Everything
 * else is for the signed-in member only, and uses the same helpers as the
 * website so both always agree.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * BLOG
 * ---------------------------------------------------------------------- */

/**
 * A member's profile photo URL, or null. (kounselia_get_avatar_url() lives
 * in dashboard.php, which isn't loaded for AJAX requests.)
 */
function kounselia_app_avatar_url( $user_id ) {
    $attachment_id = (int) get_user_meta( $user_id, 'kounselia_avatar_id', true );
    $url           = $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) : false;
    return $url ? $url : null;
}

/**
 * One post as a card in the app's list.
 */
function kounselia_app_blog_card( $post ) {
    $author = kounselia_blog_author( $post );
    return array(
        'id'              => (int) $post->id,
        'slug'            => $post->slug,
        'title'           => $post->title,
        'summary'         => kounselia_blog_summary( $post, 30 ),
        'cover'           => $post->cover_image ? $post->cover_image : null,
        'author'          => array(
            'name'            => $author['name'],
            'avatar'          => $author['avatar'] ? $author['avatar'] : null,
            'is_professional' => ! empty( $author['is_professional'] ),
            'professional_id' => ! empty( $author['professional_id'] ) ? (int) $author['professional_id'] : null,
            'title'           => ! empty( $author['title'] ) ? $author['title'] : null,
        ),
        'love_count'      => isset( $post->love_count ) ? (int) $post->love_count : 0,
        'comment_count'   => isset( $post->comment_count ) ? (int) $post->comment_count : 0,
        'published_utc'   => kounselia_app_utc( $post->published_at ),
        'reading_minutes' => (int) $post->reading_minutes,
        'tags'            => kounselia_blog_post_tags( $post ),
        'url'             => kounselia_blog_url( $post->slug, true ),
    );
}

function kounselia_ajax_app_blog() {
    kounselia_verify_nonce();

    $page     = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;
    $tag      = isset( $_POST['tag'] ) ? sanitize_title( wp_unslash( $_POST['tag'] ) ) : '';
    $search   = isset( $_POST['q'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['q'] ) ), 0, 100 ) : '';
    $from     = isset( $_POST['from'] ) ? sanitize_key( $_POST['from'] ) : '';
    $per_page = 10;

    $args = array( 'page' => $page, 'per_page' => $per_page, 'tag' => $tag, 'search' => $search );
    if ( 'professionals' === $from ) {
        $args['author_type'] = 'professional';
    } elseif ( 'following' === $from ) {
        $args['professional_ids'] = is_user_logged_in() && function_exists( 'kounselia_followed_professional_ids' ) ? kounselia_followed_professional_ids( get_current_user_id() ) : array();
    }
    $found = kounselia_blog_query( $args );

    $data = array(
        'posts'    => array_map( 'kounselia_app_blog_card', $found['items'] ),
        'has_more' => $page * $per_page < $found['total'],
    );
    // The first page also brings the topics to filter by.
    if ( 1 === $page ) {
        $settings      = kounselia_blog_settings();
        $data['title'] = $settings['title'];
        $data['tagline'] = $settings['tagline'];
        $data['tags']  = array_values( array_map( function ( $t ) {
            return array( 'name' => $t['name'], 'slug' => $t['slug'] );
        }, array_slice( kounselia_blog_all_tags(), 0, 12 ) ) );
        // Which extra filters to offer: only once professionals have written.
        $settings = function_exists( 'kounselia_article_settings' ) ? kounselia_article_settings() : array();
        $has_pro  = kounselia_blog_query( array( 'author_type' => 'professional', 'per_page' => 1 ) )['total'] > 0;
        $data['filters'] = array(
            'professionals' => $has_pro ? ( isset( $settings['filter_label'] ) ? $settings['filter_label'] : 'From our professionals' ) : null,
            'following'     => $has_pro && ! empty( $settings['follows_enabled'] ),
        );
    }
    wp_send_json_success( $data );
}
add_action( 'wp_ajax_kounselia_app_blog', 'kounselia_ajax_app_blog' );
add_action( 'wp_ajax_nopriv_kounselia_app_blog', 'kounselia_ajax_app_blog' );

function kounselia_ajax_app_blog_post() {
    kounselia_verify_nonce();

    $slug = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
    $post = $slug ? kounselia_get_blog_post_by_slug( $slug ) : null;
    if ( ! $post ) {
        wp_send_json_error( array( 'message' => "That article isn't available any more." ), 404 );
    }
    kounselia_blog_count_view( $post->id );

    // The body was cleaned when it was saved. Content blocks such as the
    // newsletter form are website furniture, so they're left out here.
    $html = (string) $post->content;
    $html = preg_replace( '#<p>\s*\[kounselia_[a-z_]+\]\s*</p>#', '', $html );
    $html = preg_replace( '#\[kounselia_[a-z_]+\]#', '', $html );

    $author = kounselia_blog_author( $post );
    $data   = kounselia_app_blog_card( $post );
    $data['subtitle']      = $post->subtitle ? $post->subtitle : null;
    $data['cover_caption'] = $post->cover_caption ? $post->cover_caption : null;
    $data['author']['bio'] = $author['bio'];
    $data['html']          = $html;
    $data['related']       = array_map( 'kounselia_app_blog_card', kounselia_blog_related( $post, 3 ) );
    $data['community']     = kounselia_app_post_community( $post, $author );
    wp_send_json_success( $data );
}
add_action( 'wp_ajax_kounselia_app_blog_post', 'kounselia_ajax_app_blog_post' );
add_action( 'wp_ajax_nopriv_kounselia_app_blog_post', 'kounselia_ajax_app_blog_post' );

/**
 * What the reader can do around an article: love it, read and join the
 * conversation, follow and book its author. Everything here matches the
 * website's article page.
 */
function kounselia_app_post_community( $post, $author ) {
    $user_id  = get_current_user_id();
    $settings = function_exists( 'kounselia_article_settings' ) ? kounselia_article_settings() : array();
    $is_pro   = ! empty( $author['is_professional'] );
    $pro      = $is_pro ? kounselia_get_professional_by_id( $author['professional_id'] ) : null;
    $can_book = $pro && 'verified' === $pro->status && ! empty( $settings['show_book_button'] ) && (int) $pro->user_id !== $user_id;
    $follows  = $pro && function_exists( 'kounselia_follows_on' ) && kounselia_follows_on() && (int) $pro->user_id !== $user_id && 'verified' === $pro->status;
    return array(
        'loves_on'      => function_exists( 'kounselia_community_on' ) && kounselia_community_on( $post, 'loves' ),
        'loved'         => function_exists( 'kounselia_post_loved_by' ) && kounselia_post_loved_by( $post->id, $user_id ),
        'love_count'    => (int) $post->love_count,
        'comments_on'   => function_exists( 'kounselia_community_on' ) && ( kounselia_community_on( $post, 'comments' ) || (int) $post->comment_count > 0 ),
        'comment_count' => (int) $post->comment_count,
        'follows_on'    => (bool) $follows,
        'following'     => $follows && kounselia_is_following( $user_id, $pro->id ),
        'followers'     => $follows ? kounselia_follower_count( $pro->id ) : 0,
        'book_pro_id'   => $can_book ? (int) $pro->id : null,
        'disclaimer'    => $is_pro && ! empty( $settings['disclaimer_enabled'] ) ? $settings['disclaimer_text'] : null,
    );
}

/* -------------------------------------------------------------------------
 * PRIVATE JOURNAL: past entries
 * ---------------------------------------------------------------------- */

function kounselia_ajax_get_journal_entries() {
    $user_id  = kounselia_app_require_member();
    $page     = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;
    $per_page = 20;

    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT entry_date, content, updated_at FROM {$wpdb->prefix}kounselia_journal_entries
         WHERE user_id = %d AND content != '' ORDER BY entry_date DESC LIMIT %d OFFSET %d",
        $user_id, $per_page + 1, ( $page - 1 ) * $per_page
    ) );

    $entries = array();
    foreach ( array_slice( $rows, 0, $per_page ) as $row ) {
        $entries[] = array(
            'date'     => $row->entry_date, // the member's day, as the site counts days
            'content'  => $row->content,
            'is_today' => current_time( 'Y-m-d' ) === $row->entry_date,
        );
    }
    wp_send_json_success( array( 'entries' => $entries, 'has_more' => count( $rows ) > $per_page ) );
}
add_action( 'wp_ajax_kounselia_get_journal_entries', 'kounselia_ajax_get_journal_entries' );
add_action( 'wp_ajax_nopriv_kounselia_get_journal_entries', 'kounselia_ajax_get_journal_entries' );

/* -------------------------------------------------------------------------
 * SETTINGS
 * ---------------------------------------------------------------------- */

/**
 * Where the member stands with their plan, in words (the website's "My
 * plan" tab says the same things).
 */
function kounselia_app_plan_summary( $user_id ) {
    $is_pro  = function_exists( 'kounselia_member_is_pro' ) && kounselia_member_is_pro( $user_id );
    $summary = function_exists( 'kounselia_subscription_summary' ) ? kounselia_subscription_summary( $user_id ) : array( 'state' => 'none', 'sub' => null );
    $sub     = $summary['sub'];
    $date    = function ( $mysql ) {
        return date_i18n( 'F j, Y', strtotime( $mysql ) );
    };

    $state  = $summary['state'];
    $title  = $is_pro ? 'Pro' : 'Free';
    $detail = 'Free forever. Upgrade any time for more time with your counselors.';
    if ( $is_pro && ( ! $sub || 'ended' === $state ) ) {
        $state  = 'gifted';
        $detail = 'A gift from the Kounselia team.';
    } elseif ( $sub && 'active' === $state ) {
        $title  = $sub->plan_name;
        $detail = ( ! empty( $summary['auto_renews'] ) ? 'Renews on ' : 'Runs until ' ) . $date( $sub->current_period_end ) . '.';
    } elseif ( $sub && 'renewal_off' === $state ) {
        $title  = $sub->plan_name;
        $detail = 'Auto-renew is off. Pro until ' . $date( $sub->current_period_end ) . '.';
    } elseif ( $sub && 'payment_problem' === $state ) {
        $title  = $sub->plan_name;
        $detail = "We couldn't renew your plan. Please update your payment on the website.";
    } elseif ( $sub && 'ended' === $state ) {
        $detail = 'Your ' . $sub->plan_name . ' plan ended on ' . $date( $sub->current_period_end ) . '.';
    }
    return array( 'is_pro' => $is_pro, 'state' => $state, 'title' => $title, 'detail' => $detail );
}

/**
 * Website pages the app links to from Settings, only those that exist.
 */
function kounselia_app_links() {
    $wanted = array(
        'privacy' => array( 'privacy-policy', 'privacy' ),
        'terms'   => array( 'terms-of-service', 'terms-and-conditions', 'terms' ),
        'mission' => array( 'our-mission' ),
        'safety'  => array( 'safety-resources' ),
    );
    $links = array();
    foreach ( $wanted as $key => $slugs ) {
        foreach ( $slugs as $slug ) {
            if ( kounselia_get_page_by_slug( $slug ) ) {
                $links[ $key ] = kounselia_page_url( $slug, true );
                break;
            }
        }
    }
    $footer = function_exists( 'kounselia_footer_settings' ) ? kounselia_footer_settings() : array();
    $email  = isset( $footer['social']['email'] ) ? sanitize_email( $footer['social']['email'] ) : '';
    $links['email'] = $email ? $email : 'hello@kounselia.com';
    return $links;
}

function kounselia_ajax_app_account() {
    $user_id = kounselia_app_require_member();
    $user    = get_userdata( $user_id );

    // Email preferences, as kounselia_ajax_get_email_prefs() works them out.
    $sub = function_exists( 'kounselia_newsletter_get_by_user' ) ? kounselia_newsletter_get_by_user( $user_id ) : null;
    if ( ! $sub && function_exists( 'kounselia_newsletter_get_by_email' ) ) {
        $sub = kounselia_newsletter_get_by_email( $user->user_email );
    }
    $on = $sub && 'subscribed' === $sub->status;

    $memory = kounselia_memory_load_profile( $user_id );
    $list   = function ( $value ) {
        return array_values( array_filter( array_map( 'strval', is_array( $value ) ? $value : array() ) ) );
    };
    $mem = array(
        'identity' => (string) $memory['identity'],
        'career'   => (string) $memory['career'],
        'goals'    => $list( $memory['goals'] ),
        'values'   => $list( $memory['values'] ),
        'habits'   => $list( $memory['habits'] ),
        'triggers' => $list( $memory['triggers'] ),
    );
    $has_memory = '' !== trim( $mem['identity'] . $mem['career'] ) || $mem['goals'] || $mem['values'] || $mem['habits'] || $mem['triggers'];

    wp_send_json_success( array(
        'user'   => array_merge( kounselia_app_user_payload( $user ), array(
            'avatar'       => kounselia_app_avatar_url( $user_id ),
            'member_since' => date_i18n( 'F Y', strtotime( $user->user_registered ) ),
        ) ),
        'plan'   => kounselia_app_plan_summary( $user_id ),
        'emails' => array(
            'newsletter' => $on && 1 === (int) $sub->list_newsletter,
            'blog'       => $on && 1 === (int) $sub->list_blog,
        ),
        'memory' => $has_memory ? $mem : null,
        // The language the member chose (or their phone's, until they do).
        'language'  => function_exists( 'kounselia_current_language' ) ? kounselia_current_language( $user_id ) : 'en',
        'links'  => kounselia_app_links(),
    ) );
}
add_action( 'wp_ajax_kounselia_app_account', 'kounselia_ajax_app_account' );
add_action( 'wp_ajax_nopriv_kounselia_app_account', 'kounselia_ajax_app_account' );

/**
 * A new profile photo from the app. Phones hand us the picture as base64
 * (like voice messages for transcription); it's checked and stored exactly
 * as the website's upload is.
 */
function kounselia_ajax_app_upload_avatar() {
    $user_id = kounselia_app_require_member();

    $types = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' );
    $mime  = isset( $_POST['mime_type'] ) ? sanitize_text_field( wp_unslash( $_POST['mime_type'] ) ) : '';
    $b64   = isset( $_POST['image_b64'] ) ? (string) wp_unslash( $_POST['image_b64'] ) : '';
    if ( ! isset( $types[ $mime ] ) ) {
        wp_send_json_error( array( 'message' => 'Please choose a JPG, PNG or WEBP photo.' ), 400 );
    }
    $bytes = base64_decode( $b64, true );
    if ( ! $bytes ) {
        wp_send_json_error( array( 'message' => 'No photo was received, please try again.' ), 400 );
    }
    if ( strlen( $bytes ) > 4 * 1024 * 1024 ) {
        wp_send_json_error( array( 'message' => 'That photo is too large, please use one under 4MB.' ), 400 );
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $tmp = wp_tempnam( 'kounselia-avatar' );
    file_put_contents( $tmp, $bytes );
    // Don't trust the label the phone put on it: check what the file really is.
    $real = wp_get_image_mime( $tmp );
    if ( ! isset( $types[ $real ] ) ) {
        @unlink( $tmp );
        wp_send_json_error( array( 'message' => 'Please choose a JPG, PNG or WEBP photo.' ), 400 );
    }

    $file          = array( 'name' => 'avatar-' . $user_id . '.' . $types[ $real ], 'tmp_name' => $tmp );
    $attachment_id = media_handle_sideload( $file, 0 );
    if ( is_wp_error( $attachment_id ) ) {
        @unlink( $tmp );
        wp_send_json_error( array( 'message' => 'Could not save that photo, please try again.' ), 500 );
    }

    kounselia_compress_attachment( $attachment_id, 1024, 82 );

    $old = (int) get_user_meta( $user_id, 'kounselia_avatar_id', true );
    if ( $old && $old !== (int) $attachment_id ) {
        wp_delete_attachment( $old, true );
    }
    update_user_meta( $user_id, 'kounselia_avatar_id', $attachment_id );

    wp_send_json_success( array( 'avatar' => kounselia_app_avatar_url( $user_id ) ) );
}
add_action( 'wp_ajax_kounselia_app_upload_avatar', 'kounselia_ajax_app_upload_avatar' );
add_action( 'wp_ajax_nopriv_kounselia_app_upload_avatar', 'kounselia_ajax_app_upload_avatar' );

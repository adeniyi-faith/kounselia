<?php
/**
 * Kounselia Core — the community around the Journal.
 *
 *   Follow   members follow professionals and hear when they publish.
 *   Love     a heart on articles and on comments.
 *   Comment  on an article, with one level of replies.
 *   Report   anyone signed in can report a comment; enough reports hide
 *            it until a moderator looks.
 *
 * Privacy comes first. Being in therapy, or reading about depression,
 * is nobody else's business, so:
 *   - A commenter is shown by the first name or nickname they choose,
 *     never their full name, email or photo.
 *   - Who follows whom is never shown to anyone. A professional sees
 *     how many followers they have, not who they are.
 *   - Comments go through the same crisis check as the chat. A worrying
 *     comment is held back from the public, the writer is shown where to
 *     get help right now, and it lands on the admin Safety page.
 *
 * Every switch lives in the Articles settings (kounselia_article_settings
 * in articles.php). All actions here use kounselia_verify_nonce(), so
 * the website and the mobile app share them.
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * WHERE THE COMMUNITY IS SWITCHED ON
 * ---------------------------------------------------------------------- */

/**
 * Whether a feature ('comments' | 'loves') is on for this post.
 */
function kounselia_community_on( $post, $feature ) {
    if ( ! $post ) {
        return false;
    }
    $s      = kounselia_article_settings();
    $is_pro = kounselia_is_pro_article( $post );
    if ( ! $is_pro && empty( $s['comments_on_staff'] ) ) {
        return false;
    }
    if ( 'loves' === $feature ) {
        return ! empty( $s['loves_enabled'] );
    }
    if ( 'comments' === $feature ) {
        return ! empty( $s['comments_enabled'] ) && ! empty( $post->allow_comments );
    }
    return false;
}

function kounselia_follows_on() {
    $s = kounselia_article_settings();
    return ! empty( $s['follows_enabled'] );
}

/* -------------------------------------------------------------------------
 * HOW A MEMBER IS SHOWN
 * ---------------------------------------------------------------------- */

/**
 * @return array{mode:string, nickname:string, first_name:string, name:string}
 *   mode is '' until they have chosen.
 */
function kounselia_community_identity( $user_id ) {
    $user  = get_userdata( $user_id );
    $first = $user ? trim( (string) $user->first_name ) : '';
    if ( '' === $first && $user ) {
        $parts = preg_split( '/\s+/', trim( (string) $user->display_name ) );
        $first = $parts ? $parts[0] : '';
        // Never show an email address as a name.
        if ( false !== strpos( $first, '@' ) ) {
            $first = '';
        }
    }
    $first    = '' !== $first ? mb_substr( sanitize_text_field( $first ), 0, 24 ) : 'Member';
    $mode     = (string) get_user_meta( $user_id, 'kounselia_community_name_mode', true );
    $nickname = (string) get_user_meta( $user_id, 'kounselia_community_nickname', true );
    $mode     = in_array( $mode, array( 'first_name', 'nickname' ), true ) ? $mode : '';
    if ( 'nickname' === $mode && '' === $nickname ) {
        $mode = '';
    }
    return array(
        'mode'       => $mode,
        'nickname'   => $nickname,
        'first_name' => $first,
        'name'       => 'nickname' === $mode ? $nickname : $first,
    );
}

/** Words a nickname may not contain, so nobody poses as staff or a clinician. */
function kounselia_community_reserved_words() {
    return array( 'kounselia', 'admin', 'moderator', 'staff', 'support', 'official', 'therapist', 'counselor', 'counsellor', 'doctor', 'psychologist', 'psychiatrist' );
}

function kounselia_community_set_identity( $user_id, $mode, $nickname = '' ) {
    if ( ! in_array( $mode, array( 'first_name', 'nickname' ), true ) ) {
        return new WP_Error( 'bad_mode', 'Please choose how your name is shown.' );
    }
    if ( 'nickname' === $mode ) {
        $nickname = trim( preg_replace( '/\s+/', ' ', sanitize_text_field( $nickname ) ) );
        if ( mb_strlen( $nickname ) < 2 || mb_strlen( $nickname ) > 24 ) {
            return new WP_Error( 'bad_nickname', 'Nicknames are 2 to 24 characters.' );
        }
        if ( ! preg_match( '/^[\p{L}\p{N} ._\'-]+$/u', $nickname ) ) {
            return new WP_Error( 'bad_nickname', 'Nicknames can use letters, numbers, spaces, dots, dashes and underscores.' );
        }
        $squashed = strtolower( preg_replace( '/[^a-z]/i', '', $nickname ) );
        foreach ( kounselia_community_reserved_words() as $word ) {
            if ( false !== strpos( $squashed, $word ) || preg_match( '/^dr$/', $squashed ) ) {
                return new WP_Error( 'bad_nickname', 'Please choose a nickname that does not sound like staff or a professional title.' );
            }
        }
        update_user_meta( $user_id, 'kounselia_community_nickname', $nickname );
    }
    update_user_meta( $user_id, 'kounselia_community_name_mode', $mode );
    return kounselia_community_identity( $user_id );
}

/* -------------------------------------------------------------------------
 * FOLLOWING
 * ---------------------------------------------------------------------- */

function kounselia_is_following( $user_id, $professional_id ) {
    global $wpdb;
    if ( ! $user_id ) {
        return false;
    }
    return (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}kounselia_follows WHERE follower_user_id = %d AND professional_id = %d",
        $user_id, $professional_id
    ) );
}

function kounselia_follower_count( $professional_id ) {
    global $wpdb;
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_follows WHERE professional_id = %d",
        $professional_id
    ) );
}

/** Ids of the professionals someone follows. */
function kounselia_followed_professional_ids( $user_id ) {
    global $wpdb;
    return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
        "SELECT professional_id FROM {$wpdb->prefix}kounselia_follows WHERE follower_user_id = %d ORDER BY created_at DESC",
        $user_id
    ) ) );
}

/**
 * Follow or unfollow. Returns array( following, followers ) or WP_Error.
 */
function kounselia_set_following( $user_id, $professional_id, $follow ) {
    global $wpdb;
    if ( ! kounselia_follows_on() ) {
        return new WP_Error( 'off', 'Following is switched off right now.' );
    }
    $pro = kounselia_get_professional_by_id( $professional_id );
    if ( ! $pro || 'verified' !== $pro->status ) {
        return new WP_Error( 'not_found', 'That professional is not available.' );
    }
    if ( (int) $pro->user_id === (int) $user_id ) {
        return new WP_Error( 'self', 'You cannot follow yourself.' );
    }
    $table = $wpdb->prefix . 'kounselia_follows';
    if ( $follow && ! kounselia_is_following( $user_id, $pro->id ) ) {
        $wpdb->insert( $table, array( 'follower_user_id' => $user_id, 'professional_id' => $pro->id, 'created_at' => current_time( 'mysql' ) ) );
        // The professional hears that someone followed, never who: a
        // follower may be one of their clients. One note an hour at most.
        $key = 'kounselia_new_follower_' . $pro->id;
        if ( $wpdb->insert_id && ! get_transient( $key ) && function_exists( 'kounselia_notify_user' ) ) {
            set_transient( $key, 1, HOUR_IN_SECONDS );
            $lang = kounselia_mail_lang( (int) $pro->user_id );
            kounselia_notify_user( (int) $pro->user_id, 'new_follower', kounselia_t( 'mail.community.new_follower_title', array(), $lang ), kounselia_t( 'mail.community.new_follower_body', array(), $lang ), '/pro-dashboard.php?tab=articles' );
        }
    } elseif ( ! $follow ) {
        $wpdb->delete( $table, array( 'follower_user_id' => $user_id, 'professional_id' => $pro->id ) );
    }
    return array( 'following' => (bool) $follow, 'followers' => kounselia_follower_count( $pro->id ) );
}

/* -------------------------------------------------------------------------
 * LOVES
 * ---------------------------------------------------------------------- */

function kounselia_post_loved_by( $post_id, $user_id ) {
    global $wpdb;
    return $user_id && (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}kounselia_post_loves WHERE post_id = %d AND user_id = %d",
        $post_id, $user_id
    ) );
}

/** Love or un-love an article. Returns array( loved, count ) or WP_Error. */
function kounselia_set_post_love( $user_id, $post_id, $love ) {
    global $wpdb;
    $post = kounselia_get_blog_post( $post_id );
    if ( ! kounselia_blog_post_is_live( $post ) || ! kounselia_community_on( $post, 'loves' ) ) {
        return new WP_Error( 'off', 'Loves are not available on this article.' );
    }
    $table = $wpdb->prefix . 'kounselia_post_loves';
    if ( $love ) {
        $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (post_id, user_id, created_at) VALUES (%d, %d, %s)", $post_id, $user_id, current_time( 'mysql' ) ) );
    } else {
        $wpdb->delete( $table, array( 'post_id' => $post_id, 'user_id' => $user_id ) );
    }
    $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE post_id = %d", $post_id ) );
    $wpdb->update( $wpdb->prefix . 'kounselia_posts', array( 'love_count' => $count ), array( 'id' => $post_id ) );
    return array( 'loved' => (bool) $love, 'count' => $count );
}

function kounselia_set_comment_love( $user_id, $comment_id, $love ) {
    global $wpdb;
    $comment = kounselia_get_comment( $comment_id );
    $post    = $comment ? kounselia_get_blog_post( $comment->post_id ) : null;
    if ( ! $comment || 'visible' !== $comment->status || ! kounselia_community_on( $post, 'loves' ) ) {
        return new WP_Error( 'off', 'You cannot love this comment.' );
    }
    $table = $wpdb->prefix . 'kounselia_comment_loves';
    if ( $love ) {
        $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (comment_id, user_id, created_at) VALUES (%d, %d, %s)", $comment_id, $user_id, current_time( 'mysql' ) ) );
    } else {
        $wpdb->delete( $table, array( 'comment_id' => $comment_id, 'user_id' => $user_id ) );
    }
    $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE comment_id = %d", $comment_id ) );
    $wpdb->update( $wpdb->prefix . 'kounselia_post_comments', array( 'love_count' => $count ), array( 'id' => $comment_id ) );
    return array( 'loved' => (bool) $love, 'count' => $count );
}

/* -------------------------------------------------------------------------
 * COMMENTS
 * ---------------------------------------------------------------------- */

function kounselia_get_comment( $comment_id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kounselia_post_comments WHERE id = %d", $comment_id ) );
}

/** Keeps the post's comment_count equal to its visible comments. */
function kounselia_comment_recount( $post_id ) {
    global $wpdb;
    $count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_post_comments WHERE post_id = %d AND status = 'visible'",
        $post_id
    ) );
    $wpdb->update( $wpdb->prefix . 'kounselia_posts', array( 'comment_count' => $count ), array( 'id' => $post_id ) );
    return $count;
}

/**
 * Whether this person may comment on this post right now. Returns true
 * or WP_Error (code 'need_identity' when they must choose a name first).
 */
function kounselia_comment_can_post( $user_id, $post ) {
    if ( ! $user_id ) {
        return new WP_Error( 'signed_out', 'Please sign in to join the conversation.' );
    }
    if ( ! kounselia_blog_post_is_live( $post ) || ! kounselia_community_on( $post, 'comments' ) ) {
        return new WP_Error( 'closed', 'Comments are closed on this article.' );
    }
    if ( get_user_meta( $user_id, 'kounselia_community_banned', true ) ) {
        return new WP_Error( 'banned', 'Commenting has been turned off for your account.' );
    }
    if ( '' === kounselia_community_identity( $user_id )['mode'] && ! kounselia_comment_is_article_author( $user_id, $post ) ) {
        return new WP_Error( 'need_identity', 'Choose how your name is shown first.' );
    }
    return true;
}

function kounselia_comment_is_article_author( $user_id, $post ) {
    return $user_id && kounselia_is_pro_article( $post ) && (int) $post->author_id === (int) $user_id;
}

/**
 * Posts a comment or reply.
 *
 * @return array|WP_Error array( 'comment' => object, 'held' => bool, 'safety' => bool )
 */
function kounselia_comment_add( $user_id, $post_id, $content, $parent_id = 0 ) {
    global $wpdb;
    $table    = $wpdb->prefix . 'kounselia_post_comments';
    $settings = kounselia_article_settings();
    $post     = kounselia_get_blog_post( $post_id );

    $can = kounselia_comment_can_post( $user_id, $post );
    if ( is_wp_error( $can ) ) {
        return $can;
    }

    $content = trim( preg_replace( "/\n{3,}/", "\n\n", str_replace( "\r", '', sanitize_textarea_field( $content ) ) ) );
    if ( mb_strlen( $content ) < 2 ) {
        return new WP_Error( 'empty', 'Please write a little more.' );
    }
    if ( mb_strlen( $content ) > (int) $settings['comment_max_length'] ) {
        return new WP_Error( 'too_long', sprintf( 'Comments can be up to %s characters.', number_format_i18n( $settings['comment_max_length'] ) ) );
    }

    $parent = null;
    if ( $parent_id ) {
        $parent = kounselia_get_comment( $parent_id );
        if ( ! $parent || (int) $parent->post_id !== (int) $post->id || 'visible' !== $parent->status ) {
            return new WP_Error( 'no_parent', 'That comment is no longer there to reply to.' );
        }
        // One level of replies: replying to a reply joins the same thread.
        if ( $parent->parent_id ) {
            $parent = kounselia_get_comment( $parent->parent_id );
        }
    }

    $since = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - HOUR_IN_SECONDS );
    $recent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND created_at >= %s", $user_id, $since ) );
    if ( $recent >= (int) $settings['comments_per_hour'] ) {
        return new WP_Error( 'slow_down', 'You have commented a lot in the last hour. Please take a short break and try again later.' );
    }
    $dupe = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d AND post_id = %d AND content = %s AND created_at >= %s", $user_id, $post->id, $content, $since ) );
    if ( $dupe ) {
        return new WP_Error( 'duplicate', 'You have already posted that.' );
    }

    $is_author = kounselia_comment_is_article_author( $user_id, $post );
    $is_mod    = function_exists( 'kounselia_admin_can' ) && kounselia_admin_can( 'blog', $user_id );
    $flag      = function_exists( 'kounselia_message_matches_safety_keywords' ) ? kounselia_message_matches_safety_keywords( $content ) : false;

    $status = 'visible';
    $reason = null;
    if ( $flag ) {
        $status = 'pending';
        $reason = 'safety';
    } elseif ( 'approve_first' === $settings['comment_moderation'] && ! $is_author && ! $is_mod ) {
        $status = 'pending';
        $reason = 'approval';
    }

    $now = current_time( 'mysql' );
    $wpdb->insert( $table, array(
        'post_id'       => $post->id,
        'parent_id'     => $parent ? $parent->id : null,
        'user_id'       => $user_id,
        'content'       => $content,
        'status'        => $status,
        'hidden_reason' => $reason,
        'flag_reason'   => $flag ? mb_substr( $flag, 0, 255 ) : null,
        'is_author'     => $is_author ? 1 : 0,
        'created_at'    => $now,
        'updated_at'    => $now,
    ) );
    $comment_id = (int) $wpdb->insert_id;
    if ( ! $comment_id ) {
        return new WP_Error( 'failed', 'Your comment could not be saved. Please try again.' );
    }

    if ( $flag ) {
        kounselia_record_comment_safety_escalation( $comment_id, $flag, $user_id );
    }
    if ( 'visible' === $status ) {
        kounselia_comment_recount( $post->id );
        kounselia_comment_tell_people( kounselia_get_comment( $comment_id ), $post, $parent );
    }

    return array( 'comment' => kounselia_get_comment( $comment_id ), 'held' => 'visible' !== $status, 'safety' => (bool) $flag );
}

/**
 * A new visible comment: the article's author hears about comments, and
 * a commenter hears about replies to them. In-app and push only, never
 * email, so a busy thread doesn't flood anyone's inbox.
 */
function kounselia_comment_tell_people( $comment, $post, $parent = null ) {
    if ( ! function_exists( 'kounselia_notify_user' ) ) {
        return;
    }
    $who  = kounselia_comment_author_label( $comment, $post );
    $url  = kounselia_blog_url( $post->slug ) . '#comment-' . (int) $comment->id;
    $told = array( (int) $comment->user_id );

    if ( $parent && ! in_array( (int) $parent->user_id, $told, true ) ) {
        kounselia_notify_user( (int) $parent->user_id, 'comment_reply', kounselia_t( 'mail.community.reply_title', array( 'name' => $who['name'] ), kounselia_mail_lang( (int) $parent->user_id ) ), wp_trim_words( $comment->content, 18 ), $url );
        $told[] = (int) $parent->user_id;
    }
    if ( kounselia_is_pro_article( $post ) && ! in_array( (int) $post->author_id, $told, true ) ) {
        kounselia_notify_user( (int) $post->author_id, 'article_comment', kounselia_t( 'mail.community.comment_title', array( 'title' => wp_trim_words( $post->title, 8 ) ), kounselia_mail_lang( (int) $post->author_id ) ), $who['name'] . ': ' . wp_trim_words( $comment->content, 16 ), $url );
    }
}

/**
 * How a comment's writer is shown. The article's own author appears
 * under their professional name with a badge; everyone else by the
 * first name or nickname they chose.
 */
function kounselia_comment_author_label( $comment, $post ) {
    static $cache = array();
    $key = (int) $comment->user_id . ':' . (int) $comment->is_author;
    if ( isset( $cache[ $key ] ) ) {
        return $cache[ $key ];
    }
    if ( $comment->is_author && kounselia_is_pro_article( $post ) ) {
        $author = kounselia_blog_author( $post );
        $label  = array(
            'name'            => $author['name'],
            'initial'         => $author['initial'],
            'avatar'          => $author['avatar'] ? $author['avatar'] : null,
            'is_author'       => true,
            'profile_url'     => $author['profile_url'] ? $author['profile_url'] : null,
        );
    } else {
        $id    = kounselia_community_identity( $comment->user_id );
        $label = array(
            'name'        => $id['name'],
            'initial'     => mb_strtoupper( mb_substr( $id['name'], 0, 1 ) ),
            'avatar'      => null, // Never a member's photo.
            'is_author'   => false,
            'profile_url' => null,
        );
    }
    return $cache[ $key ] = $label;
}

/**
 * Who may moderate comments on this post: editors with Blog access, and
 * the professional who wrote it (if the admin allows).
 */
function kounselia_comment_can_moderate( $user_id, $post ) {
    if ( ! $user_id || ! $post ) {
        return false;
    }
    if ( function_exists( 'kounselia_admin_can' ) && kounselia_admin_can( 'blog', $user_id ) ) {
        return true;
    }
    $s = kounselia_article_settings();
    return ! empty( $s['authors_can_moderate'] ) && kounselia_comment_is_article_author( $user_id, $post );
}

/** One comment as the website and app show it. */
function kounselia_comment_view( $c, $post, $viewer_id, $loved_ids = array() ) {
    $removed = 'removed' === $c->status;
    return array(
        'id'           => (int) $c->id,
        'parent_id'    => $c->parent_id ? (int) $c->parent_id : null,
        'author'       => $removed ? array( 'name' => 'Deleted', 'initial' => '·', 'avatar' => null, 'is_author' => false, 'profile_url' => null ) : kounselia_comment_author_label( $c, $post ),
        'content'      => $removed ? 'This comment was deleted.' : $c->content,
        'status'       => $c->status,
        'held'         => 'pending' === $c->status,
        'pinned'       => (bool) $c->pinned,
        'love_count'   => (int) $c->love_count,
        'loved'        => in_array( (int) $c->id, $loved_ids, true ),
        'is_mine'      => $viewer_id && (int) $c->user_id === (int) $viewer_id && ! $removed,
        'created_utc'  => function_exists( 'kounselia_app_utc' ) ? kounselia_app_utc( $c->created_at ) : $c->created_at,
        'time_label'   => human_time_diff( strtotime( $c->created_at ), current_time( 'timestamp' ) ) . ' ago',
        'replies'      => array(),
    );
}

/**
 * The conversation under an article, a page of top-level comments at a
 * time (pinned first, then newest), each with its replies (oldest
 * first). The viewer also sees their own comments that are waiting.
 *
 * @return array{comments:array, has_more:bool, total:int}
 */
function kounselia_comments_for_post( $post, $viewer_id = 0, $page = 1, $per_page = 15 ) {
    global $wpdb;
    $table    = $wpdb->prefix . 'kounselia_post_comments';
    $page     = max( 1, (int) $page );
    $per_page = max( 1, min( 50, (int) $per_page ) );
    $mine     = $viewer_id ? $wpdb->prepare( ' OR (status = \'pending\' AND user_id = %d)', $viewer_id ) : '';
    // A deleted comment only stays (as "deleted") while it has replies.
    $seen     = "(status = 'visible' OR (status = 'removed' AND EXISTS (SELECT 1 FROM {$table} r WHERE r.parent_id = {$table}.id AND r.status = 'visible')){$mine})";

    $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND parent_id IS NULL AND {$seen}", $post->id ) );
    $top   = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE post_id = %d AND parent_id IS NULL AND {$seen} ORDER BY pinned DESC, created_at DESC, id DESC LIMIT %d OFFSET %d",
        $post->id, $per_page, ( $page - 1 ) * $per_page
    ) );
    if ( ! $top ) {
        return array( 'comments' => array(), 'has_more' => false, 'total' => $total );
    }

    $ids     = implode( ',', array_map( 'intval', wp_list_pluck( $top, 'id' ) ) );
    $replies = $wpdb->get_results( "SELECT * FROM {$table} WHERE parent_id IN ({$ids}) AND (status = 'visible'" . $mine . ') ORDER BY created_at ASC, id ASC' );

    $loved = array();
    if ( $viewer_id ) {
        $all_ids = implode( ',', array_map( 'intval', array_merge( wp_list_pluck( $top, 'id' ), wp_list_pluck( $replies, 'id' ) ) ) );
        $loved   = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT comment_id FROM {$wpdb->prefix}kounselia_comment_loves WHERE user_id = %d AND comment_id IN ({$all_ids})", $viewer_id ) ) );
    }

    $out = array();
    foreach ( $top as $c ) {
        $out[ (int) $c->id ] = kounselia_comment_view( $c, $post, $viewer_id, $loved );
    }
    foreach ( $replies as $r ) {
        if ( isset( $out[ (int) $r->parent_id ] ) && count( $out[ (int) $r->parent_id ]['replies'] ) < 100 ) {
            $out[ (int) $r->parent_id ]['replies'][] = kounselia_comment_view( $r, $post, $viewer_id, $loved );
        }
    }
    return array( 'comments' => array_values( $out ), 'has_more' => $page * $per_page < $total, 'total' => $total );
}

/** What the viewer can do in the comments, for the website and app. */
function kounselia_comment_viewer( $post, $viewer_id ) {
    $can = kounselia_comment_can_post( $viewer_id, $post );
    $s   = kounselia_article_settings();
    return array(
        'signed_in'     => (bool) $viewer_id,
        'can_comment'   => true === $can,
        'reason'        => is_wp_error( $can ) ? $can->get_error_code() : null,
        'message'       => is_wp_error( $can ) ? $can->get_error_message() : null,
        'identity'      => $viewer_id ? kounselia_community_identity( $viewer_id ) : null,
        'can_moderate'  => kounselia_comment_can_moderate( $viewer_id, $post ),
        'max_length'    => (int) $s['comment_max_length'],
        'held_first'    => 'approve_first' === $s['comment_moderation'],
    );
}

/** The writer deletes their own comment. */
function kounselia_comment_delete_own( $user_id, $comment_id ) {
    global $wpdb;
    $c = kounselia_get_comment( $comment_id );
    if ( ! $c || (int) $c->user_id !== (int) $user_id || 'removed' === $c->status ) {
        return new WP_Error( 'not_found', 'That comment is no longer there.' );
    }
    kounselia_comment_erase( $c );
    return true;
}

/**
 * Takes a comment away for good. If others have replied, it stays as
 * "This comment was deleted" so the thread still reads sensibly.
 */
function kounselia_comment_erase( $c ) {
    global $wpdb;
    $table       = $wpdb->prefix . 'kounselia_post_comments';
    $has_replies = ! $c->parent_id && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE parent_id = %d AND status = 'visible' LIMIT 1", $c->id ) );
    if ( $has_replies ) {
        $wpdb->update( $table, array( 'status' => 'removed', 'content' => '', 'pinned' => 0, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $c->id ) );
    } else {
        kounselia_comment_delete_rows( array( (int) $c->id ) );
        if ( ! $c->parent_id ) {
            // Its hidden or waiting replies go with it.
            $children = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE parent_id = %d", $c->id ) ) );
            kounselia_comment_delete_rows( $children );
        }
    }
    kounselia_comment_recount( $c->post_id );
}

function kounselia_comment_delete_rows( $ids ) {
    global $wpdb;
    $ids = array_filter( array_map( 'intval', (array) $ids ) );
    if ( ! $ids ) {
        return;
    }
    $in = implode( ',', $ids );
    $wpdb->query( "DELETE FROM {$wpdb->prefix}kounselia_post_comments WHERE id IN ({$in})" );
    $wpdb->query( "DELETE FROM {$wpdb->prefix}kounselia_comment_loves WHERE comment_id IN ({$in})" );
    $wpdb->query( "DELETE FROM {$wpdb->prefix}kounselia_comment_reports WHERE comment_id IN ({$in})" );
}

/**
 * A moderator acts on a comment.
 *
 * $action: approve | hide | pin | unpin | delete | dismiss (clear reports).
 * The article's author may hide, show, pin and unpin; only an editor can
 * approve a comment held by the safety check or delete one.
 */
function kounselia_comment_moderate( $actor_id, $comment_id, $action ) {
    global $wpdb;
    $table = $wpdb->prefix . 'kounselia_post_comments';
    $c     = kounselia_get_comment( $comment_id );
    $post  = $c ? kounselia_get_blog_post( $c->post_id ) : null;
    if ( ! $c || ! $post ) {
        return new WP_Error( 'not_found', 'That comment is no longer there.' );
    }
    $is_editor = function_exists( 'kounselia_admin_can' ) && kounselia_admin_can( 'blog', $actor_id );
    if ( ! kounselia_comment_can_moderate( $actor_id, $post ) ) {
        return new WP_Error( 'not_allowed', 'You cannot moderate this comment.' );
    }
    // Comments held by the safety check are for the care team only.
    if ( ! $is_editor && ( 'safety' === $c->hidden_reason || in_array( $action, array( 'delete', 'dismiss' ), true ) ) ) {
        return new WP_Error( 'not_allowed', 'Only the Kounselia team can do that.' );
    }
    if ( ! $is_editor && 'approve' === $action && 'hidden' !== $c->status ) {
        return new WP_Error( 'not_allowed', 'Only the Kounselia team can approve waiting comments.' );
    }

    $now  = current_time( 'mysql' );
    $base = array( 'moderated_by' => $actor_id, 'moderated_at' => $now, 'updated_at' => $now );
    switch ( $action ) {
        case 'approve':
            $was_visible = 'visible' === $c->status;
            $wpdb->update( $table, array_merge( $base, array( 'status' => 'visible', 'hidden_reason' => null ) ), array( 'id' => $c->id ) );
            kounselia_comment_resolve_reports( $c->id, $actor_id );
            if ( ! $was_visible && 'approval' === $c->hidden_reason ) {
                kounselia_comment_tell_people( kounselia_get_comment( $c->id ), $post, $c->parent_id ? kounselia_get_comment( $c->parent_id ) : null );
            }
            break;
        case 'hide':
            $wpdb->update( $table, array_merge( $base, array( 'status' => 'hidden', 'hidden_reason' => $is_editor ? 'moderator' : 'author', 'pinned' => 0 ) ), array( 'id' => $c->id ) );
            kounselia_comment_resolve_reports( $c->id, $actor_id );
            break;
        case 'pin':
        case 'unpin':
            if ( 'pin' === $action && ( 'visible' !== $c->status || $c->parent_id ) ) {
                return new WP_Error( 'cannot_pin', 'Only a visible, top-level comment can be pinned.' );
            }
            if ( 'pin' === $action ) {
                $wpdb->update( $table, array( 'pinned' => 0 ), array( 'post_id' => $c->post_id ) );
            }
            $wpdb->update( $table, array_merge( $base, array( 'pinned' => 'pin' === $action ? 1 : 0 ) ), array( 'id' => $c->id ) );
            break;
        case 'delete':
            kounselia_comment_erase( $c );
            break;
        case 'dismiss':
            kounselia_comment_resolve_reports( $c->id, $actor_id );
            if ( 'reports' === $c->hidden_reason ) {
                $wpdb->update( $table, array_merge( $base, array( 'status' => 'visible', 'hidden_reason' => null, 'report_count' => 0 ) ), array( 'id' => $c->id ) );
            } else {
                $wpdb->update( $table, array( 'report_count' => 0 ), array( 'id' => $c->id ) );
            }
            break;
        default:
            return new WP_Error( 'bad_action', 'Unknown action.' );
    }
    kounselia_comment_recount( $c->post_id );
    if ( $is_editor && function_exists( 'kounselia_admin_log' ) ) {
        kounselia_admin_log( 'comment_' . $action, 'comment', (int) $c->id );
    }
    return true;
}

function kounselia_comment_resolve_reports( $comment_id, $actor_id ) {
    global $wpdb;
    $wpdb->query( $wpdb->prepare(
        "UPDATE {$wpdb->prefix}kounselia_comment_reports SET status = 'resolved', resolved_by = %d, resolved_at = %s WHERE comment_id = %d AND status = 'open'",
        $actor_id, current_time( 'mysql' ), $comment_id
    ) );
}

function kounselia_comment_report_reasons() {
    return array(
        'unkind'       => 'Unkind or bullying',
        'harmful'      => 'Harmful or dangerous advice',
        'spam'         => 'Spam or advertising',
        'private_info' => 'Shares someone\'s private information',
        'other'        => 'Something else',
    );
}

/** Someone reports a comment. Enough reports hide it until a moderator looks. */
function kounselia_comment_report( $user_id, $comment_id, $reason, $note = '' ) {
    global $wpdb;
    $c = kounselia_get_comment( $comment_id );
    if ( ! $c || 'visible' !== $c->status ) {
        return new WP_Error( 'not_found', 'That comment is no longer there.' );
    }
    if ( (int) $c->user_id === (int) $user_id ) {
        return new WP_Error( 'own', 'You cannot report your own comment. You can delete it instead.' );
    }
    if ( ! array_key_exists( $reason, kounselia_comment_report_reasons() ) ) {
        $reason = 'other';
    }
    $done = $wpdb->query( $wpdb->prepare(
        "INSERT IGNORE INTO {$wpdb->prefix}kounselia_comment_reports (comment_id, user_id, reason, note, status, created_at) VALUES (%d, %d, %s, %s, 'open', %s)",
        $c->id, $user_id, $reason, mb_substr( sanitize_textarea_field( $note ), 0, 500 ), current_time( 'mysql' )
    ) );
    if ( $done ) {
        $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_comment_reports WHERE comment_id = %d AND status = 'open'", $c->id ) );
        $row   = array( 'report_count' => $count );
        $s     = kounselia_article_settings();
        if ( $count >= (int) $s['report_threshold'] ) {
            $row['status']        = 'pending';
            $row['hidden_reason'] = 'reports';
            $row['pinned']        = 0;
        }
        $wpdb->update( $wpdb->prefix . 'kounselia_post_comments', $row, array( 'id' => $c->id ) );
        kounselia_comment_recount( $c->post_id );
    }
    return true;
}

/** Comments waiting for someone on the team, and open reports. */
function kounselia_community_attention_count() {
    global $wpdb;
    $quiet = $wpdb->suppress_errors();
    $n     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_post_comments WHERE status = 'pending' AND hidden_reason IN ('approval','reports')" )
        + (int) $wpdb->get_var( "SELECT COUNT(DISTINCT r.comment_id) FROM {$wpdb->prefix}kounselia_comment_reports r INNER JOIN {$wpdb->prefix}kounselia_post_comments c ON c.id = r.comment_id WHERE r.status = 'open' AND c.status = 'visible'" );
    $wpdb->suppress_errors( $quiet );
    return $n;
}

/* -------------------------------------------------------------------------
 * SAFETY
 * ---------------------------------------------------------------------- */

/**
 * A comment matched the crisis check. It is already held back from the
 * public; this opens a case on the admin Safety page and, for acute
 * language, pages the team (same rules as the chat).
 */
function kounselia_record_comment_safety_escalation( $comment_id, $flag_reason, $user_id ) {
    global $wpdb;
    $severity = function_exists( 'kounselia_classify_safety_severity' ) ? kounselia_classify_safety_severity( $flag_reason ) : 'elevated';
    $wpdb->insert( $wpdb->prefix . 'kounselia_safety_escalations', array(
        'source'      => 'article_comment',
        'comment_id'  => $comment_id,
        'user_id'     => $user_id ?: null,
        'severity'    => $severity,
        'flag_reason' => $flag_reason,
        'status'      => 'open',
        'created_at'  => current_time( 'mysql' ),
    ) );
    $escalation_id = (int) $wpdb->insert_id;
    if ( 'critical' === $severity && $escalation_id && function_exists( 'kounselia_notify_safety_escalation' ) ) {
        kounselia_notify_safety_escalation( $escalation_id );
    }
    return $escalation_id;
}

/** Where the "you're not alone" message points people. */
function kounselia_community_support_link() {
    $page = function_exists( 'kounselia_get_page_by_slug' ) ? kounselia_get_page_by_slug( 'safety-resources' ) : null;
    return $page ? kounselia_page_url( 'safety-resources', true ) : kounselia_site_url( '/' );
}

/* -------------------------------------------------------------------------
 * TELLING FOLLOWERS ABOUT A NEW ARTICLE (WP-Cron)
 * ---------------------------------------------------------------------- */

/**
 * Runs shortly after a professional's article goes live (scheduled by
 * kounselia_article_went_live). Followers are told in batches so a
 * popular professional never makes one request run for minutes.
 */
function kounselia_notify_followers_of_article( $post_id, $after_id = 0 ) {
    global $wpdb;
    $post = kounselia_get_blog_post( $post_id );
    if ( ! kounselia_is_pro_article( $post ) || 'published' !== $post->status ) {
        return;
    }
    if ( ! kounselia_blog_post_is_live( $post ) ) {
        // Scheduled for later: try again when it's out.
        wp_schedule_single_event( (int) get_gmt_from_date( $post->published_at, 'U' ) + 30, 'kounselia_notify_followers_of_article', array( (int) $post->id ) );
        return;
    }
    if ( ! $after_id ) {
        if ( $post->followers_notified_at ) {
            return; // Already done: never twice for the same article.
        }
        $wpdb->update( $wpdb->prefix . 'kounselia_posts', array( 'followers_notified_at' => current_time( 'mysql' ) ), array( 'id' => $post->id ) );
    }

    $s      = kounselia_article_settings();
    $batch  = 100;
    $rows   = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, follower_user_id FROM {$wpdb->prefix}kounselia_follows WHERE professional_id = %d AND id > %d ORDER BY id ASC LIMIT %d",
        $post->professional_id, $after_id, $batch
    ) );
    $author = kounselia_blog_author( $post );
    $url    = kounselia_blog_url( $post->slug );
    foreach ( $rows as $row ) {
        $lang  = kounselia_mail_lang( (int) $row->follower_user_id );
        $title = kounselia_t( 'mail.community.followed_title', array( 'name' => $author['name'] ), $lang );
        $email = null;
        if ( ! empty( $s['notify_followers_email'] ) && '0' !== (string) get_user_meta( $row->follower_user_id, 'kounselia_follow_emails', true ) ) {
            $email = array(
                'subject'      => $title . ': ' . $post->title,
                'headline'     => $post->title,
                'content_html' => '<p>' . esc_html( kounselia_t( 'mail.community.followed_content', array( 'name' => $author['name'] ), $lang ) ) . '</p>'
                    . '<p style="color:#6b675d;">' . esc_html( kounselia_blog_summary( $post, 40 ) ) . '</p>'
                    . '<p style="font-size:12px;color:#8a867c;">' . esc_html( kounselia_t( 'mail.community.followed_footnote', array( 'name' => $author['name'] ), $lang ) ) . '</p>',
                'btn_text'     => kounselia_t( 'mail.community.read_article', array(), $lang ),
                'btn_url'      => kounselia_blog_url( $post->slug, true ),
            );
        }
        kounselia_notify_user( (int) $row->follower_user_id, 'followed_article', $title, $post->title, $url, $email );
    }
    if ( count( $rows ) === $batch ) {
        $last = end( $rows );
        wp_schedule_single_event( time() + 60, 'kounselia_notify_followers_of_article', array( (int) $post->id, (int) $last->id ) );
    }
}
add_action( 'kounselia_notify_followers_of_article', 'kounselia_notify_followers_of_article', 10, 2 );

/* -------------------------------------------------------------------------
 * CLEAN-UP
 * ---------------------------------------------------------------------- */

function kounselia_community_forget_post( $post_id ) {
    global $wpdb;
    $ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}kounselia_post_comments WHERE post_id = %d", $post_id ) ) );
    kounselia_comment_delete_rows( $ids );
    $wpdb->delete( $wpdb->prefix . 'kounselia_post_loves', array( 'post_id' => $post_id ) );
}
add_action( 'kounselia_blog_post_deleted', 'kounselia_community_forget_post' );

function kounselia_community_forget_user( $user_id ) {
    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'kounselia_follows', array( 'follower_user_id' => $user_id ) );
    $loved = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->prefix}kounselia_post_loves WHERE user_id = %d", $user_id ) );
    $wpdb->delete( $wpdb->prefix . 'kounselia_post_loves', array( 'user_id' => $user_id ) );
    foreach ( $loved as $post_id ) {
        $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_post_loves WHERE post_id = %d", $post_id ) );
        $wpdb->update( $wpdb->prefix . 'kounselia_posts', array( 'love_count' => $count ), array( 'id' => $post_id ) );
    }
    $wpdb->delete( $wpdb->prefix . 'kounselia_comment_loves', array( 'user_id' => $user_id ) );
    foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kounselia_post_comments WHERE user_id = %d", $user_id ) ) as $c ) {
        kounselia_comment_erase( $c );
    }
}
add_action( 'deleted_user', 'kounselia_community_forget_user' );

/* -------------------------------------------------------------------------
 * AJAX (website and app)
 * ---------------------------------------------------------------------- */

function kounselia_community_require_member() {
    kounselia_verify_nonce();
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.', 'signed_out' => true ), 401 );
    }
    return get_current_user_id();
}

function kounselia_community_send( $result ) {
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ), 400 );
    }
    wp_send_json_success( $result );
}

function kounselia_ajax_follow() {
    $user_id = kounselia_community_require_member();
    kounselia_community_send( kounselia_set_following( $user_id, isset( $_POST['professional_id'] ) ? absint( $_POST['professional_id'] ) : 0, ! isset( $_POST['follow'] ) || '0' !== (string) $_POST['follow'] ) );
}
add_action( 'wp_ajax_kounselia_follow', 'kounselia_ajax_follow' );

function kounselia_ajax_post_love() {
    $user_id = kounselia_community_require_member();
    kounselia_community_send( kounselia_set_post_love( $user_id, isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0, ! isset( $_POST['love'] ) || '0' !== (string) $_POST['love'] ) );
}
add_action( 'wp_ajax_kounselia_post_love', 'kounselia_ajax_post_love' );

function kounselia_ajax_comment_love() {
    $user_id = kounselia_community_require_member();
    kounselia_community_send( kounselia_set_comment_love( $user_id, isset( $_POST['comment_id'] ) ? absint( $_POST['comment_id'] ) : 0, ! isset( $_POST['love'] ) || '0' !== (string) $_POST['love'] ) );
}
add_action( 'wp_ajax_kounselia_comment_love', 'kounselia_ajax_comment_love' );

/** The conversation under an article. Public, like the article. */
function kounselia_ajax_comments() {
    kounselia_verify_nonce();
    $post = kounselia_get_blog_post( isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0 );
    if ( ! kounselia_blog_post_is_live( $post ) ) {
        wp_send_json_error( array( 'message' => "That article isn't available any more." ), 404 );
    }
    $viewer = get_current_user_id();
    $found  = kounselia_comments_for_post( $post, $viewer, isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1 );
    wp_send_json_success( array_merge( $found, array(
        'enabled' => kounselia_community_on( $post, 'comments' ),
        'viewer'  => kounselia_comment_viewer( $post, $viewer ),
    ) ) );
}
add_action( 'wp_ajax_kounselia_comments', 'kounselia_ajax_comments' );
add_action( 'wp_ajax_nopriv_kounselia_comments', 'kounselia_ajax_comments' );

function kounselia_ajax_comment_add() {
    $user_id = kounselia_community_require_member();
    $result  = kounselia_comment_add(
        $user_id,
        isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0,
        isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : '',
        isset( $_POST['parent_id'] ) ? absint( $_POST['parent_id'] ) : 0
    );
    if ( is_wp_error( $result ) ) {
        kounselia_community_send( $result );
    }
    $post    = kounselia_get_blog_post( $result['comment']->post_id );
    $message = 'Posted.';
    if ( $result['safety'] ) {
        $message = 'Thank you for sharing this. It sounds like you might be carrying something heavy right now. Your comment is private for now, and someone from our care team will look at it. If you are in danger or thinking about ending your life, please reach out for help right away.';
    } elseif ( $result['held'] ) {
        $message = 'Thanks! Your comment will appear once a moderator has read it.';
    }
    wp_send_json_success( array(
        'comment'     => kounselia_comment_view( $result['comment'], $post, $user_id ),
        'held'        => $result['held'],
        'safety'      => $result['safety'],
        'support_url' => $result['safety'] ? kounselia_community_support_link() : null,
        'message'     => $message,
        'count'       => (int) kounselia_get_blog_post( $post->id )->comment_count,
    ) );
}
add_action( 'wp_ajax_kounselia_comment_add', 'kounselia_ajax_comment_add' );

/** The article's visible comment count, sent back after a change so screens stay in step. */
function kounselia_comment_count_for( $comment_id ) {
    $c = kounselia_get_comment( $comment_id );
    return $c ? (int) kounselia_get_blog_post( $c->post_id )->comment_count : null;
}

function kounselia_ajax_comment_delete() {
    $user_id    = kounselia_community_require_member();
    $comment_id = isset( $_POST['comment_id'] ) ? absint( $_POST['comment_id'] ) : 0;
    $c          = kounselia_get_comment( $comment_id );
    $result     = kounselia_comment_delete_own( $user_id, $comment_id );
    kounselia_community_send( is_wp_error( $result ) ? $result : array(
        'message' => 'Comment deleted.',
        'count'   => $c ? (int) kounselia_get_blog_post( $c->post_id )->comment_count : null,
    ) );
}
add_action( 'wp_ajax_kounselia_comment_delete', 'kounselia_ajax_comment_delete' );

function kounselia_ajax_comment_moderate() {
    $user_id = kounselia_community_require_member();
    $act     = isset( $_POST['act'] ) ? sanitize_key( $_POST['act'] ) : '';
    $result  = kounselia_comment_moderate( $user_id, isset( $_POST['comment_id'] ) ? absint( $_POST['comment_id'] ) : 0, $act );
    $labels  = array( 'approve' => 'Comment is visible.', 'hide' => 'Comment hidden.', 'pin' => 'Pinned to the top.', 'unpin' => 'Unpinned.', 'delete' => 'Comment deleted.', 'dismiss' => 'Reports cleared.' );
    kounselia_community_send( is_wp_error( $result ) ? $result : array(
        'message' => isset( $labels[ $act ] ) ? $labels[ $act ] : 'Done.',
        'count'   => kounselia_comment_count_for( isset( $_POST['comment_id'] ) ? absint( $_POST['comment_id'] ) : 0 ),
    ) );
}
add_action( 'wp_ajax_kounselia_comment_moderate', 'kounselia_ajax_comment_moderate' );

function kounselia_ajax_comment_report() {
    $user_id = kounselia_community_require_member();
    $result  = kounselia_comment_report(
        $user_id,
        isset( $_POST['comment_id'] ) ? absint( $_POST['comment_id'] ) : 0,
        isset( $_POST['reason'] ) ? sanitize_key( $_POST['reason'] ) : 'other',
        isset( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : ''
    );
    kounselia_community_send( is_wp_error( $result ) ? $result : array( 'message' => 'Thank you. Our team will take a look.' ) );
}
add_action( 'wp_ajax_kounselia_comment_report', 'kounselia_ajax_comment_report' );

function kounselia_ajax_community_identity() {
    $user_id = kounselia_community_require_member();
    kounselia_community_send( kounselia_community_set_identity(
        $user_id,
        isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : '',
        isset( $_POST['nickname'] ) ? wp_unslash( $_POST['nickname'] ) : ''
    ) );
}
add_action( 'wp_ajax_kounselia_community_identity', 'kounselia_ajax_community_identity' );

/** On or off: emails when someone you follow publishes. */
function kounselia_ajax_follow_emails() {
    $user_id = kounselia_community_require_member();
    $on      = ! isset( $_POST['on'] ) || '0' !== (string) $_POST['on'];
    update_user_meta( $user_id, 'kounselia_follow_emails', $on ? '1' : '0' );
    wp_send_json_success( array( 'on' => $on ) );
}
add_action( 'wp_ajax_kounselia_follow_emails', 'kounselia_ajax_follow_emails' );

/* -------------------------------------------------------------------------
 * ADMIN AJAX (Admin → Articles → Community)
 * ---------------------------------------------------------------------- */

function kounselia_ajax_admin_comment_moderate() {
    kounselia_content_admin_guard( 'blog' );
    $act    = sanitize_key( kounselia_post_field( 'act' ) );
    $result = kounselia_comment_moderate( get_current_user_id(), (int) kounselia_post_field( 'comment_id', 0 ), $act );
    if ( is_wp_error( $result ) ) {
        kounselia_send_pure_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }
    kounselia_send_pure_json_success( array( 'message' => 'Done.' ) );
}
add_action( 'wp_ajax_kounselia_admin_comment_moderate', 'kounselia_ajax_admin_comment_moderate' );

/** Stops (or lets again) one member from commenting anywhere. */
function kounselia_ajax_admin_community_ban() {
    kounselia_content_admin_guard( 'blog' );
    $user_id = (int) kounselia_post_field( 'user_id', 0 );
    if ( ! $user_id || ! get_userdata( $user_id ) ) {
        kounselia_send_pure_json_error( array( 'message' => 'That member no longer exists.' ), 404 );
    }
    $ban = '1' === (string) kounselia_post_field( 'ban', '1' );
    if ( $ban ) {
        update_user_meta( $user_id, 'kounselia_community_banned', 1 );
    } else {
        delete_user_meta( $user_id, 'kounselia_community_banned' );
    }
    kounselia_admin_log( $ban ? 'community_ban' : 'community_unban', 'user', $user_id );
    kounselia_send_pure_json_success( array( 'message' => $ban ? 'This member can no longer comment.' : 'This member can comment again.' ) );
}
add_action( 'wp_ajax_kounselia_admin_community_ban', 'kounselia_ajax_admin_community_ban' );

<?php
/**
 * Kounselia Core — articles written by verified professionals.
 *
 * Professionals write into the same Journal (kounselia_posts) the team
 * uses, marked author_type = 'professional'. That way their articles
 * get the blog's topics, search, RSS, newsletter emails and the app's
 * reader without a second system.
 *
 * Who may publish is decided in three layers (kounselia_article_access):
 *   1. The whole feature on or off (Admin → Articles → Settings).
 *   2. Everyone verified, or only professionals an admin picked.
 *   3. Each professional's own setting on their row in
 *      kounselia_professionals.publishing:
 *        default  follow the site setting
 *        blocked  cannot write or edit
 *        review   every article (and every edit to a live one) waits
 *                 for an editor
 *        trusted  publishes straight away
 *
 * An article's life, in review_status (status stays 'draft' until it
 * is live, exactly like a staff post):
 *   NULL               a draft nobody has been asked to look at
 *   pending            waiting for an editor
 *   changes_requested  sent back with a note
 *   rejected           turned down with a note (can still be reworked)
 *   approved           live
 *   removed            was live, taken down by an editor
 *   edit_draft         live, with unsent edits saved for later
 * Edits to a live article by someone who needs review are held in
 * pending_changes, so readers keep seeing the approved version until an
 * editor approves the new one.
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * SETTINGS
 * ---------------------------------------------------------------------- */

function kounselia_article_setting_defaults() {
    return array(
        // Publishing
        'enabled'              => 1,
        'who'                  => 'all',      // 'all' verified professionals | 'chosen' ones only
        'default_mode'         => 'review',   // 'review' | 'trusted'
        'weekly_limit'         => 3,          // new articles a professional may send per 7 days (0 = no limit)
        'min_words'            => 250,
        'max_words'            => 6000,
        'max_tags'             => 5,
        'allow_images'         => 1,
        'allow_embeds'         => 1,
        'disclaimer_enabled'   => 1,
        'disclaimer_text'      => 'This article shares general information and a professional\'s perspective. It is not a diagnosis, and it is not a substitute for care from someone who knows your situation. If you are in crisis, please see our safety resources.',
        'show_book_button'     => 1,
        'notify_editors'       => 1,
        'filter_label'         => 'From our professionals',
        // Community
        'follows_enabled'      => 1,
        'loves_enabled'        => 1,
        'comments_enabled'     => 1,
        'comments_on_staff'    => 1,          // comments and loves on the team's own posts too
        'comment_moderation'   => 'open',     // 'open' (shown at once, after the safety check) | 'approve_first'
        'comment_max_length'   => 1500,
        'comments_per_hour'    => 10,
        'report_threshold'     => 3,          // reports that hide a comment until a moderator looks
        'authors_can_moderate' => 1,          // professionals can pin and hide comments on their own articles
        'notify_followers'     => 1,
        'notify_followers_email' => 1,
    );
}

function kounselia_article_settings() {
    $saved = get_option( 'kounselia_article_settings', array() );
    return wp_parse_args( is_array( $saved ) ? $saved : array(), kounselia_article_setting_defaults() );
}

/**
 * Cleans a settings array posted from the admin page, keeping only
 * known keys, each within sensible bounds.
 */
function kounselia_article_sanitize_settings( $raw ) {
    $d   = kounselia_article_setting_defaults();
    $out = array();
    $int = function ( $key, $min, $max ) use ( $raw, $d ) {
        $v = isset( $raw[ $key ] ) && '' !== $raw[ $key ] ? (int) $raw[ $key ] : $d[ $key ];
        return max( $min, min( $max, $v ) );
    };
    $bool = function ( $key ) use ( $raw ) {
        return ! empty( $raw[ $key ] ) && '0' !== (string) $raw[ $key ] ? 1 : 0;
    };

    foreach ( array( 'enabled', 'allow_images', 'allow_embeds', 'disclaimer_enabled', 'show_book_button', 'notify_editors', 'follows_enabled', 'loves_enabled', 'comments_enabled', 'comments_on_staff', 'authors_can_moderate', 'notify_followers', 'notify_followers_email' ) as $key ) {
        $out[ $key ] = $bool( $key );
    }
    $out['who']                = isset( $raw['who'] ) && 'chosen' === $raw['who'] ? 'chosen' : 'all';
    $out['default_mode']       = isset( $raw['default_mode'] ) && 'trusted' === $raw['default_mode'] ? 'trusted' : 'review';
    $out['comment_moderation'] = isset( $raw['comment_moderation'] ) && 'approve_first' === $raw['comment_moderation'] ? 'approve_first' : 'open';
    $out['weekly_limit']       = $int( 'weekly_limit', 0, 50 );
    $out['min_words']          = $int( 'min_words', 0, 5000 );
    $out['max_words']          = max( $out['min_words'] + 50, $int( 'max_words', 100, 30000 ) );
    $out['max_tags']           = $int( 'max_tags', 1, 8 );
    $out['comment_max_length'] = $int( 'comment_max_length', 100, 5000 );
    $out['comments_per_hour']  = $int( 'comments_per_hour', 1, 200 );
    $out['report_threshold']   = $int( 'report_threshold', 1, 50 );
    $out['disclaimer_text']    = isset( $raw['disclaimer_text'] ) && '' !== trim( $raw['disclaimer_text'] ) ? mb_substr( sanitize_textarea_field( $raw['disclaimer_text'] ), 0, 600 ) : $d['disclaimer_text'];
    $out['filter_label']       = isset( $raw['filter_label'] ) && '' !== trim( $raw['filter_label'] ) ? mb_substr( sanitize_text_field( $raw['filter_label'] ), 0, 40 ) : $d['filter_label'];
    return $out;
}

/* -------------------------------------------------------------------------
 * WHO MAY PUBLISH
 * ---------------------------------------------------------------------- */

function kounselia_article_publishing_options() {
    return array(
        'default' => 'Follow the site setting',
        'review'  => 'Needs review',
        'trusted' => 'Trusted (publishes straight away)',
        'blocked' => 'Blocked from publishing',
    );
}

/**
 * Whether this professional (a kounselia_professionals row) may write,
 * and if so whether their articles need review.
 *
 * @return array{allowed:bool, mode:string, message:string}
 *   mode is 'review' or 'trusted' when allowed.
 */
function kounselia_article_access( $pro ) {
    $settings = kounselia_article_settings();
    $no       = function ( $message ) {
        return array( 'allowed' => false, 'mode' => '', 'message' => $message );
    };

    if ( ! $pro ) {
        return $no( 'Only professionals can write articles.' );
    }
    if ( empty( $settings['enabled'] ) ) {
        return $no( 'Articles are switched off for everyone right now.' );
    }
    if ( 'verified' !== $pro->status ) {
        return $no( 'You can write articles once your account is verified.' );
    }

    $setting = isset( $pro->publishing ) && $pro->publishing ? $pro->publishing : 'default';
    if ( 'blocked' === $setting ) {
        return $no( 'Publishing is turned off for your account. If you think this is a mistake, please contact the Kounselia team.' );
    }
    if ( 'review' === $setting || 'trusted' === $setting ) {
        return array( 'allowed' => true, 'mode' => $setting, 'message' => '' );
    }
    if ( 'chosen' === $settings['who'] ) {
        return $no( 'Writing for the Journal is by invitation at the moment. Ask the Kounselia team if you would like to contribute.' );
    }
    return array( 'allowed' => true, 'mode' => $settings['default_mode'], 'message' => '' );
}

/* -------------------------------------------------------------------------
 * READING
 * ---------------------------------------------------------------------- */

function kounselia_is_pro_article( $post ) {
    return $post && isset( $post->author_type ) && 'professional' === $post->author_type;
}

/** The article belongs to this professional (a kounselia_professionals row). */
function kounselia_article_owned_by( $post, $pro ) {
    return kounselia_is_pro_article( $post ) && $pro && (int) $post->professional_id === (int) $pro->id;
}

/** All of a professional's articles, newest first. */
function kounselia_pro_articles( $professional_id ) {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}kounselia_posts WHERE author_type = 'professional' AND professional_id = %d ORDER BY COALESCE(published_at, updated_at) DESC, id DESC",
        $professional_id
    ) );
}

/**
 * One word for where an article stands, for badges and for deciding
 * what the editor offers: draft, pending, changes, rejected, removed,
 * live, live_pending (an edit is waiting for review), live_draft (an
 * edit saved for later), scheduled.
 */
function kounselia_article_state( $post ) {
    $live = kounselia_blog_post_is_live( $post );
    if ( $live ) {
        if ( 'pending' === $post->review_status && $post->pending_changes ) {
            return 'live_pending';
        }
        if ( $post->pending_changes ) {
            return 'live_draft';
        }
        return 'live';
    }
    if ( 'published' === $post->status ) {
        return 'scheduled';
    }
    switch ( (string) $post->review_status ) {
        case 'pending':
            return 'pending';
        case 'changes_requested':
            return 'changes';
        case 'rejected':
            return 'rejected';
        case 'removed':
            return 'removed';
    }
    return 'draft';
}

function kounselia_article_state_label( $state ) {
    $labels = array(
        'draft'        => 'Draft',
        'pending'      => 'Waiting for review',
        'changes'      => 'Changes requested',
        'rejected'     => 'Not approved',
        'removed'      => 'Taken down',
        'live'         => 'Live',
        'live_pending' => 'Live · edit in review',
        'live_draft'   => 'Live · unsent edits',
        'scheduled'    => 'Scheduled',
    );
    return isset( $labels[ $state ] ) ? $labels[ $state ] : ucfirst( $state );
}

/**
 * The version a professional should see in their editor: their held
 * edits when there are some, otherwise the article itself.
 */
function kounselia_article_editable_fields( $post ) {
    $fields = array(
        'title'          => (string) $post->title,
        'subtitle'       => (string) $post->subtitle,
        'excerpt'        => (string) $post->excerpt,
        'content'        => (string) $post->content,
        'cover_image'    => (string) $post->cover_image,
        'cover_caption'  => (string) $post->cover_caption,
        'tags'           => (string) $post->tags,
        'allow_comments' => (int) $post->allow_comments,
    );
    if ( $post->pending_changes ) {
        $held = json_decode( $post->pending_changes, true );
        if ( is_array( $held ) ) {
            $fields = array_merge( $fields, array_intersect_key( $held, $fields ) );
        }
    }
    return $fields;
}

/** Number of words in an article body. */
function kounselia_article_word_count( $html ) {
    $text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $html ) ) );
    return '' === $text ? 0 : count( preg_split( '/\s+/u', $text ) );
}

/* -------------------------------------------------------------------------
 * CLEANING WHAT A PROFESSIONAL SENDS
 * ---------------------------------------------------------------------- */

/**
 * Article body HTML from a professional: the same cleaning as the
 * team's posts, then without the site's content blocks (those are
 * for the team's own pages), and without images or embedded video
 * when the admin has turned those off. Headings start at h2, since the
 * title is the page's h1.
 */
function kounselia_article_clean_html( $html, $settings = null ) {
    $settings = $settings ? $settings : kounselia_article_settings();
    $html     = kounselia_content_kses( $html );
    $html     = preg_replace( '#<p[^>]*>\s*\[kounselia_[a-z_]+\]\s*</p>#i', '', $html );
    $html     = preg_replace( '#\[kounselia_[a-z_]+\]#i', '', $html );
    $html     = preg_replace( '#<(/?)h1(\s[^>]*)?>#i', '<$1h2>', $html );
    if ( empty( $settings['allow_images'] ) ) {
        $html = preg_replace( '#<figure\b[^>]*>\s*<img\b[^>]*>.*?</figure>#is', '', $html );
        $html = preg_replace( '#<img\b[^>]*>#i', '', $html );
    } else {
        // Only images uploaded here: a picture hosted elsewhere could let
        // another site see who reads the article.
        $html = preg_replace_callback( '#<img\b[^>]*>#i', function ( $m ) {
            return preg_match( '#\ssrc=["\']([^"\']+)["\']#i', $m[0], $src ) && kounselia_article_is_our_upload( html_entity_decode( $src[1] ) ) ? $m[0] : '';
        }, $html );
    }
    if ( empty( $settings['allow_embeds'] ) ) {
        $html = preg_replace( '#<iframe\b[^>]*>.*?</iframe>#is', '', $html );
    }
    return trim( $html );
}

/**
 * The fields a professional can set, cleaned. Returns an array ready to
 * store, or WP_Error.
 */
function kounselia_article_clean_fields( $data, $settings = null ) {
    $settings = $settings ? $settings : kounselia_article_settings();
    $title    = isset( $data['title'] ) ? trim( sanitize_text_field( $data['title'] ) ) : '';
    if ( '' === $title ) {
        return new WP_Error( 'missing_title', 'Please give your article a title.' );
    }
    $tags  = array_slice( kounselia_content_parse_tags( isset( $data['tags'] ) ? $data['tags'] : '' ), 0, (int) $settings['max_tags'] );
    $cover = ! empty( $data['cover_image'] ) ? esc_url_raw( $data['cover_image'] ) : '';
    if ( $cover && ! kounselia_article_is_our_upload( $cover ) ) {
        $cover = ''; // Covers must be uploaded here, never hot-linked from elsewhere.
    }
    return array(
        'title'          => mb_substr( $title, 0, 200 ),
        'subtitle'       => isset( $data['subtitle'] ) ? mb_substr( trim( sanitize_text_field( $data['subtitle'] ) ), 0, 300 ) : '',
        'excerpt'        => isset( $data['excerpt'] ) ? mb_substr( trim( sanitize_textarea_field( $data['excerpt'] ) ), 0, 500 ) : '',
        'content'        => kounselia_article_clean_html( isset( $data['content'] ) ? $data['content'] : '', $settings ),
        'cover_image'    => $cover,
        'cover_caption'  => isset( $data['cover_caption'] ) ? mb_substr( trim( sanitize_text_field( $data['cover_caption'] ) ), 0, 200 ) : '',
        'tags'           => implode( ', ', $tags ),
        'allow_comments' => isset( $data['allow_comments'] ) && '0' === (string) $data['allow_comments'] ? 0 : 1,
    );
}

/** True for a URL in this site's own uploads folder. */
function kounselia_article_is_our_upload( $url ) {
    $uploads = wp_get_upload_dir();
    $base    = preg_replace( '#^https?:#', '', untrailingslashit( $uploads['baseurl'] ) );
    return 0 === strpos( preg_replace( '#^https?:#', '', $url ), $base . '/' );
}

/**
 * Checks that must pass before an article goes to an editor or live
 * (a draft can be saved half-written).
 */
function kounselia_article_ready_check( $fields, $settings = null ) {
    $settings = $settings ? $settings : kounselia_article_settings();
    $words    = kounselia_article_word_count( $fields['content'] );
    if ( $settings['min_words'] && $words < (int) $settings['min_words'] ) {
        return new WP_Error( 'too_short', sprintf( 'Articles need at least %1$s words. This one has %2$s.', number_format_i18n( $settings['min_words'] ), number_format_i18n( $words ) ) );
    }
    if ( $settings['max_words'] && $words > (int) $settings['max_words'] ) {
        return new WP_Error( 'too_long', sprintf( 'Articles can be up to %1$s words. This one has %2$s. Could it be two articles?', number_format_i18n( $settings['max_words'] ), number_format_i18n( $words ) ) );
    }
    return true;
}

/**
 * When a professional sends a new article (not a resubmission), they
 * are held to the weekly limit. Returns true or WP_Error.
 */
function kounselia_article_weekly_check( $professional_id, $settings = null ) {
    global $wpdb;
    $settings = $settings ? $settings : kounselia_article_settings();
    $limit    = (int) $settings['weekly_limit'];
    if ( ! $limit ) {
        return true;
    }
    $since = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - WEEK_IN_SECONDS );
    $sent  = $wpdb->get_col( $wpdb->prepare(
        "SELECT first_submitted_at FROM {$wpdb->prefix}kounselia_posts WHERE professional_id = %d AND first_submitted_at >= %s ORDER BY first_submitted_at ASC",
        $professional_id, $since
    ) );
    if ( count( $sent ) < $limit ) {
        return true;
    }
    $next = strtotime( $sent[0] ) + WEEK_IN_SECONDS;
    return new WP_Error( 'weekly_limit', sprintf(
        'You can send up to %1$d new %2$s a week. You can send your next one on %3$s. Your draft is saved.',
        $limit, 1 === $limit ? 'article' : 'articles', date_i18n( 'l, F j', $next )
    ) );
}

/* -------------------------------------------------------------------------
 * SAVING
 * ---------------------------------------------------------------------- */

/**
 * The database columns for a set of cleaned fields. The address (slug)
 * follows the title until the article first goes live, then stays put
 * so shared links keep working.
 */
function kounselia_article_row_from_fields( $fields, $existing = null ) {
    $tag_names = kounselia_content_parse_tags( $fields['tags'] );
    $tag_slugs = array_map( 'sanitize_title', $tag_names );
    $row       = array(
        'title'            => $fields['title'],
        'subtitle'         => '' !== $fields['subtitle'] ? $fields['subtitle'] : null,
        'excerpt'          => '' !== $fields['excerpt'] ? $fields['excerpt'] : null,
        'content'          => $fields['content'],
        'cover_image'      => '' !== $fields['cover_image'] ? $fields['cover_image'] : null,
        'cover_caption'    => '' !== $fields['cover_caption'] ? $fields['cover_caption'] : null,
        'tags'             => $tag_names ? implode( ', ', $tag_names ) : null,
        'tag_slugs'        => $tag_slugs ? ',' . implode( ',', $tag_slugs ) . ',' : null,
        'meta_description' => '' !== $fields['excerpt'] ? mb_substr( $fields['excerpt'], 0, 320 ) : ( '' !== $fields['subtitle'] ? mb_substr( $fields['subtitle'], 0, 320 ) : null ),
        'reading_minutes'  => kounselia_content_reading_minutes( $fields['content'] ),
        'allow_comments'   => (int) $fields['allow_comments'],
    );
    $was_ever_live = $existing && $existing->published_at;
    if ( ! $was_ever_live ) {
        $row['slug'] = kounselia_content_unique_slug( 'posts', $fields['title'], $existing ? (int) $existing->id : 0 );
    }
    return $row;
}

/**
 * A professional saves an article.
 *
 * @param object $pro     Their kounselia_professionals row.
 * @param array  $data    Raw fields (title, subtitle, excerpt, content, cover_image, cover_caption, tags, allow_comments).
 * @param int    $id      0 for a new article.
 * @param string $intent  'draft' to save for later, 'send' to publish (trusted) or send for review.
 * @return array|WP_Error array( 'id' => int, 'state' => string, 'message' => string )
 */
function kounselia_article_save( $pro, $data, $id = 0, $intent = 'draft' ) {
    global $wpdb;
    $table    = $wpdb->prefix . 'kounselia_posts';
    $settings = kounselia_article_settings();
    $access   = kounselia_article_access( $pro );
    if ( ! $access['allowed'] ) {
        return new WP_Error( 'not_allowed', $access['message'] );
    }

    $existing = $id ? kounselia_get_blog_post( $id ) : null;
    if ( $id && ! kounselia_article_owned_by( $existing, $pro ) ) {
        return new WP_Error( 'not_found', 'That article no longer exists.' );
    }

    $fields = kounselia_article_clean_fields( $data, $settings );
    if ( is_wp_error( $fields ) ) {
        return $fields;
    }

    $now     = current_time( 'mysql' );
    $live    = $existing && kounselia_blog_post_is_live( $existing );
    $sending = 'send' === $intent;

    if ( $sending ) {
        $ready = kounselia_article_ready_check( $fields, $settings );
        if ( is_wp_error( $ready ) ) {
            if ( ! $live ) {
                // Keep their work even though it can't be sent yet.
                $saved = kounselia_article_save( $pro, $data, $id, 'draft' );
                $ready->add_data( array( 'id' => is_array( $saved ) ? $saved['id'] : $id ) );
            }
            return $ready;
        }
        if ( ! $existing || ! $existing->first_submitted_at ) {
            $weekly = kounselia_article_weekly_check( $pro->id, $settings );
            if ( is_wp_error( $weekly ) ) {
                $saved = kounselia_article_save( $pro, $data, $id, 'draft' );
                $weekly->add_data( array( 'id' => is_array( $saved ) ? $saved['id'] : $id ) );
                return $weekly;
            }
        }
    }

    /* ---- A live article: edits are either applied or held. ---- */
    if ( $live ) {
        if ( $sending && 'trusted' === $access['mode'] ) {
            $row = kounselia_article_row_from_fields( $fields, $existing );
            $row = array_merge( $row, array(
                'pending_changes' => null,
                'review_status'   => 'approved',
                'review_note'     => null,
                'submitted_at'    => $now,
                'updated_at'      => $now,
            ) );
            $wpdb->update( $table, $row, array( 'id' => $existing->id ) );
            return array( 'id' => (int) $existing->id, 'state' => 'live', 'message' => 'Your changes are live.' );
        }
        $wpdb->update( $table, array(
            'pending_changes' => wp_json_encode( $fields ),
            'review_status'   => $sending ? 'pending' : 'edit_draft',
            'submitted_at'    => $sending ? $now : $existing->submitted_at,
            'updated_at'      => $now,
        ), array( 'id' => $existing->id ) );
        if ( $sending ) {
            kounselia_article_notify_editors();
        }
        return array(
            'id'      => (int) $existing->id,
            'state'   => $sending ? 'live_pending' : 'live_draft',
            'message' => $sending ? 'Your changes have been sent for review. Readers see the current version until they are approved.' : 'Your changes are saved. Readers still see the live version.',
        );
    }

    /* ---- Not live: the article itself is updated. ---- */
    $row = kounselia_article_row_from_fields( $fields, $existing );
    $row['updated_at']      = $now;
    $row['pending_changes'] = null;

    if ( $sending ) {
        $row['submitted_at'] = $now;
        if ( ! $existing || ! $existing->first_submitted_at ) {
            $row['first_submitted_at'] = $now;
        }
        if ( 'trusted' === $access['mode'] ) {
            $row['status']        = 'published';
            $row['published_at']  = $now;
            $row['review_status'] = 'approved';
            $row['review_note']   = null;
        } else {
            $row['status']        = 'draft';
            $row['review_status'] = 'pending';
        }
    } else {
        $row['status'] = 'draft';
        // Editing something that was waiting takes it out of the queue;
        // a note from an editor stays until they send it again.
        if ( $existing && 'pending' === $existing->review_status ) {
            $row['review_status'] = null;
        }
    }

    if ( $existing ) {
        $wpdb->update( $table, $row, array( 'id' => $existing->id ) );
        $post_id = (int) $existing->id;
    } else {
        $row = array_merge( $row, array(
            'author_type'     => 'professional',
            'professional_id' => (int) $pro->id,
            'author_id'       => (int) $pro->user_id,
            'featured'        => 0,
            'created_at'      => $now,
        ) );
        $wpdb->insert( $table, $row );
        $post_id = (int) $wpdb->insert_id;
    }

    if ( $sending && 'trusted' === $access['mode'] ) {
        kounselia_article_went_live( $post_id );
        return array( 'id' => $post_id, 'state' => 'live', 'message' => 'Your article is live.' );
    }
    if ( $sending ) {
        kounselia_article_notify_editors();
        return array( 'id' => $post_id, 'state' => 'pending', 'message' => 'Sent for review. We will let you know as soon as an editor has read it.' );
    }
    return array( 'id' => $post_id, 'state' => kounselia_article_state( kounselia_get_blog_post( $post_id ) ), 'message' => 'Draft saved.' );
}

/**
 * A professional takes back an article or edit that is waiting for
 * review, or unpublishes their own live article.
 */
function kounselia_article_withdraw( $pro, $id ) {
    global $wpdb;
    $post = kounselia_get_blog_post( $id );
    if ( ! kounselia_article_owned_by( $post, $pro ) ) {
        return new WP_Error( 'not_found', 'That article no longer exists.' );
    }
    $table = $wpdb->prefix . 'kounselia_posts';
    $now   = current_time( 'mysql' );
    $state = kounselia_article_state( $post );

    if ( 'live_pending' === $state ) {
        $wpdb->update( $table, array( 'review_status' => 'edit_draft', 'updated_at' => $now ), array( 'id' => $post->id ) );
        return 'Your edit is no longer waiting for review. It is still saved.';
    }
    if ( 'pending' === $state ) {
        $wpdb->update( $table, array( 'review_status' => null, 'updated_at' => $now ), array( 'id' => $post->id ) );
        return 'Your article is back in your drafts.';
    }
    if ( in_array( $state, array( 'live', 'live_draft', 'scheduled' ), true ) ) {
        // Unpublishing their own work. It keeps its address, and goes
        // back through the usual route if they send it again.
        $wpdb->update( $table, array( 'status' => 'draft', 'review_status' => null, 'updated_at' => $now ), array( 'id' => $post->id ) );
        return 'Your article is no longer public. It is back in your drafts.';
    }
    return new WP_Error( 'nothing_to_do', 'That article is not waiting for review or live.' );
}

/* -------------------------------------------------------------------------
 * EDITORS' DECISIONS
 * ---------------------------------------------------------------------- */

/**
 * An editor decides on an article.
 *
 * @param string $decision approve | changes | reject | remove
 * @param string $note     Shown to the professional (required for changes and reject).
 * @return true|WP_Error
 */
function kounselia_article_review( $post_id, $decision, $note = '' ) {
    global $wpdb;
    $post = kounselia_get_blog_post( $post_id );
    if ( ! kounselia_is_pro_article( $post ) ) {
        return new WP_Error( 'not_found', 'That article no longer exists.' );
    }
    $table = $wpdb->prefix . 'kounselia_posts';
    $now   = current_time( 'mysql' );
    $note  = mb_substr( trim( sanitize_textarea_field( $note ) ), 0, 1000 );
    $live  = kounselia_blog_post_is_live( $post );
    $base  = array( 'reviewed_at' => $now, 'reviewed_by' => get_current_user_id() ?: null, 'updated_at' => $now, 'review_note' => '' !== $note ? $note : null );

    if ( in_array( $decision, array( 'changes', 'reject' ), true ) && '' === $note ) {
        return new WP_Error( 'note_needed', 'Please write a short note so the professional knows what to change.' );
    }

    switch ( $decision ) {
        case 'approve':
            $row = $base;
            if ( $post->pending_changes ) {
                $held = json_decode( $post->pending_changes, true );
                if ( is_array( $held ) ) {
                    $row = array_merge( kounselia_article_row_from_fields( array_merge( kounselia_article_editable_fields( $post ), $held ), $post ), $row );
                }
            }
            $row['pending_changes'] = null;
            $row['review_status']   = 'approved';
            if ( ! $live ) {
                $row['status']       = 'published';
                $row['published_at'] = $now;
                if ( ! $post->first_submitted_at ) {
                    $row['first_submitted_at'] = $now;
                }
            }
            $wpdb->update( $table, $row, array( 'id' => $post->id ) );
            kounselia_article_went_live( (int) $post->id );
            kounselia_article_tell_author( $post, $live ? 'edit_approved' : 'approved', $note );
            break;

        case 'changes':
        case 'reject':
            // For a live article this is about the held edit only; the
            // live version stays up.
            $row                  = $base;
            $row['review_status'] = 'changes' === $decision ? 'changes_requested' : 'rejected';
            $wpdb->update( $table, $row, array( 'id' => $post->id ) );
            kounselia_article_tell_author( $post, $row['review_status'] . ( $live ? '_edit' : '' ), $note );
            break;

        case 'remove':
            $row                  = $base;
            $row['status']        = 'draft';
            $row['review_status'] = 'removed';
            $wpdb->update( $table, $row, array( 'id' => $post->id ) );
            kounselia_article_tell_author( $post, 'removed', $note );
            break;

        default:
            return new WP_Error( 'bad_decision', 'Unknown decision.' );
    }

    if ( function_exists( 'kounselia_admin_log' ) ) {
        kounselia_admin_log( 'article_' . $decision, 'post', (int) $post->id );
    }
    return true;
}

/**
 * Sets how one professional may publish. With $take_down, blocking also
 * unpublishes everything they have live.
 */
function kounselia_article_set_publishing( $professional_id, $setting, $note = '', $take_down = false ) {
    global $wpdb;
    if ( ! array_key_exists( $setting, kounselia_article_publishing_options() ) ) {
        return new WP_Error( 'bad_setting', 'Unknown setting.' );
    }
    $pro = kounselia_get_professional_by_id( $professional_id );
    if ( ! $pro ) {
        return new WP_Error( 'not_found', 'That professional no longer exists.' );
    }
    $wpdb->update( $wpdb->prefix . 'kounselia_professionals', array(
        'publishing'      => $setting,
        'publishing_note' => '' !== trim( $note ) ? mb_substr( sanitize_textarea_field( $note ), 0, 500 ) : null,
        'updated_at'      => current_time( 'mysql' ),
    ), array( 'id' => $pro->id ) );

    $taken = 0;
    if ( 'blocked' === $setting && $take_down ) {
        $now   = current_time( 'mysql' );
        $taken = (int) $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}kounselia_posts SET status = 'draft', review_status = 'removed', review_note = %s, reviewed_at = %s, reviewed_by = %d, updated_at = %s
             WHERE author_type = 'professional' AND professional_id = %d AND status = 'published'",
            'Taken down when publishing was turned off for this account.', $now, get_current_user_id(), $now, $pro->id
        ) );
    }
    if ( function_exists( 'kounselia_admin_log' ) ) {
        kounselia_admin_log( 'set_publishing_' . $setting, 'professional', (int) $pro->id );
    }
    return $taken;
}

/* -------------------------------------------------------------------------
 * GOING LIVE, AND TELLING PEOPLE
 * ---------------------------------------------------------------------- */

/**
 * Called whenever an article is (or is scheduled to be) published.
 * For a professional's article, followers hear about it once — never
 * again for later edits.
 */
function kounselia_article_went_live( $post_id ) {
    $post = kounselia_get_blog_post( $post_id );
    if ( ! kounselia_is_pro_article( $post ) || 'published' !== $post->status || $post->followers_notified_at ) {
        return;
    }
    $settings = kounselia_article_settings();
    if ( empty( $settings['notify_followers'] ) || empty( $settings['follows_enabled'] ) ) {
        return;
    }
    $when = max( time(), (int) get_gmt_from_date( $post->published_at, 'U' ) ) + 30;
    if ( ! wp_next_scheduled( 'kounselia_notify_followers_of_article', array( (int) $post->id ) ) ) {
        wp_schedule_single_event( $when, 'kounselia_notify_followers_of_article', array( (int) $post->id ) );
    }
}

/**
 * Tells a professional what happened to their article: in their
 * notifications, by push, and by email.
 */
function kounselia_article_tell_author( $post, $what, $note = '' ) {
    $messages = array(
        'approved'                => array( 'Your article is live', 'Your article "%s" has been approved and is now live on the Journal.' ),
        'edit_approved'           => array( 'Your changes are live', 'Your changes to "%s" have been approved.' ),
        'changes_requested'       => array( 'An editor has suggestions', 'An editor has asked for a few changes to "%s" before it goes live.' ),
        'changes_requested_edit'  => array( 'An editor has suggestions', 'An editor has asked for a few changes to your edit of "%s". The live version is unchanged.' ),
        'rejected'                => array( 'Your article was not approved', '"%s" was not approved for the Journal.' ),
        'rejected_edit'           => array( 'Your edit was not approved', 'Your edit to "%s" was not approved. The live version is unchanged.' ),
        'removed'                 => array( 'Your article was taken down', '"%s" has been taken down from the Journal by an editor.' ),
    );
    if ( ! isset( $messages[ $what ] ) || ! function_exists( 'kounselia_notify_user' ) ) {
        return;
    }
    list( $title, $body ) = $messages[ $what ];
    $body = sprintf( $body, $post->title );
    $url  = '/pro-dashboard.php?tab=articles';
    $html = '<p>' . esc_html( $body ) . '</p>';
    if ( '' !== $note ) {
        $html .= '<p style="background:#F8F6F2;border-radius:12px;padding:14px 16px;"><strong>Note from the editor:</strong><br>' . nl2br( esc_html( $note ) ) . '</p>';
    }
    kounselia_notify_user( (int) $post->author_id, 'article_' . $what, $title, $body, $url, array(
        'subject'      => $title,
        'headline'     => $title,
        'content_html' => $html,
        'btn_text'     => in_array( $what, array( 'approved', 'edit_approved' ), true ) ? 'Read it on the Journal' : 'Open your articles',
        'btn_url'      => in_array( $what, array( 'approved', 'edit_approved' ), true ) ? kounselia_blog_url( $post->slug, true ) : kounselia_site_url( $url ),
    ) );
}

/** How many articles and edits are waiting for an editor. */
function kounselia_article_pending_count() {
    global $wpdb;
    $quiet = $wpdb->suppress_errors();
    $n     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_posts WHERE author_type = 'professional' AND review_status = 'pending'" );
    $wpdb->suppress_errors( $quiet );
    return $n;
}

/**
 * Emails the people who can review articles that something is waiting.
 * At most once an hour, however many arrive, so nobody's inbox fills up.
 */
function kounselia_article_notify_editors() {
    $settings = kounselia_article_settings();
    if ( empty( $settings['notify_editors'] ) || get_transient( 'kounselia_article_editors_pinged' ) ) {
        return;
    }
    set_transient( 'kounselia_article_editors_pinged', 1, HOUR_IN_SECONDS );

    $count  = kounselia_article_pending_count();
    $emails = array();
    foreach ( get_users( array( 'role__in' => array( 'administrator', 'kounselia_staff' ), 'fields' => array( 'ID', 'user_email' ) ) ) as $u ) {
        if ( function_exists( 'kounselia_admin_can' ) && kounselia_admin_can( 'blog', $u->ID ) ) {
            $emails[] = $u->user_email;
        }
    }
    if ( ! $emails || ! function_exists( 'kounselia_send_html_email' ) ) {
        return;
    }
    foreach ( array_unique( $emails ) as $email ) {
        kounselia_send_html_email(
            $email,
            'Articles waiting for review',
            $count > 1 ? $count . ' articles are waiting' : 'An article is waiting',
            '<p>A professional has sent an article (or changes to one) for the Journal. It will not go live until an editor approves it.</p>',
            'Open the review queue',
            kounselia_site_url( '/portal/admin/pages/articles.php' )
        );
    }
}

/* -------------------------------------------------------------------------
 * PROFESSIONAL AJAX (their dashboard and editor)
 * ---------------------------------------------------------------------- */

/** The signed-in professional's row, or a JSON error. */
function kounselia_article_require_pro() {
    kounselia_verify_nonce();
    $user_id = get_current_user_id();
    $pro     = $user_id ? kounselia_get_professional_application( $user_id ) : null;
    if ( ! $pro ) {
        wp_send_json_error( array( 'message' => 'Only professionals can do this.' ), 403 );
    }
    return $pro;
}

function kounselia_article_post_fields() {
    $out = array();
    foreach ( array( 'title', 'subtitle', 'excerpt', 'content', 'cover_image', 'cover_caption', 'tags', 'allow_comments' ) as $key ) {
        $out[ $key ] = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
    }
    return $out;
}

function kounselia_ajax_pro_article_save() {
    $pro    = kounselia_article_require_pro();
    $id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
    $intent = isset( $_POST['intent'] ) && 'send' === $_POST['intent'] ? 'send' : 'draft';

    $result = kounselia_article_save( $pro, kounselia_article_post_fields(), $id, $intent );
    if ( is_wp_error( $result ) ) {
        $extra = $result->get_error_data();
        wp_send_json_error( array_merge( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ), is_array( $extra ) ? $extra : array() ), 400 );
    }
    $post = kounselia_get_blog_post( $result['id'] );
    wp_send_json_success( array_merge( $result, array(
        'state_label' => kounselia_article_state_label( $result['state'] ),
        'url'         => kounselia_blog_url( $post->slug ),
    ) ) );
}
add_action( 'wp_ajax_kounselia_pro_article_save', 'kounselia_ajax_pro_article_save' );

function kounselia_ajax_pro_article_withdraw() {
    $pro    = kounselia_article_require_pro();
    $result = kounselia_article_withdraw( $pro, isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }
    wp_send_json_success( array( 'message' => $result ) );
}
add_action( 'wp_ajax_kounselia_pro_article_withdraw', 'kounselia_ajax_pro_article_withdraw' );

function kounselia_ajax_pro_article_delete() {
    $pro  = kounselia_article_require_pro();
    $post = kounselia_get_blog_post( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
    if ( ! kounselia_article_owned_by( $post, $pro ) ) {
        wp_send_json_error( array( 'message' => 'That article no longer exists.' ), 404 );
    }
    kounselia_delete_blog_post( $post->id );
    wp_send_json_success( array( 'message' => 'Article deleted.' ) );
}
add_action( 'wp_ajax_kounselia_pro_article_delete', 'kounselia_ajax_pro_article_delete' );

/** Images inside an article and its cover. */
function kounselia_ajax_pro_article_upload() {
    $pro    = kounselia_article_require_pro();
    $access = kounselia_article_access( $pro );
    if ( ! $access['allowed'] ) {
        wp_send_json_error( array( 'message' => $access['message'] ), 403 );
    }
    $settings = kounselia_article_settings();
    $is_cover = isset( $_POST['purpose'] ) && 'cover' === $_POST['purpose'];
    if ( ! $is_cover && empty( $settings['allow_images'] ) ) {
        wp_send_json_error( array( 'message' => 'Images inside articles are switched off.' ), 403 );
    }
    if ( kounselia_rate_limited( 'pro_article_upload_' . $pro->id, 40, HOUR_IN_SECONDS ) ) {
        wp_send_json_error( array( 'message' => 'That is a lot of images in a short time. Please try again in a little while.' ), 429 );
    }
    $url = kounselia_content_store_image( isset( $_FILES['file'] ) ? $_FILES['file'] : null, 5 );
    if ( is_wp_error( $url ) ) {
        wp_send_json_error( array( 'message' => $url->get_error_message() ), 400 );
    }
    // "location" is the key the rich editor expects.
    wp_send_json_success( array( 'url' => $url, 'location' => $url ) );
}
add_action( 'wp_ajax_kounselia_pro_article_upload', 'kounselia_ajax_pro_article_upload' );

/* -------------------------------------------------------------------------
 * ADMIN AJAX
 * ---------------------------------------------------------------------- */

function kounselia_ajax_admin_article_review() {
    kounselia_content_admin_guard( 'blog' );
    $result = kounselia_article_review(
        (int) kounselia_post_field( 'id', 0 ),
        sanitize_key( kounselia_post_field( 'decision' ) ),
        (string) kounselia_post_field( 'note' )
    );
    if ( is_wp_error( $result ) ) {
        kounselia_send_pure_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }
    $labels = array( 'approve' => 'Approved and live.', 'changes' => 'Sent back with your note.', 'reject' => 'Not approved. The professional has been told.', 'remove' => 'Taken down.' );
    kounselia_send_pure_json_success( array( 'message' => $labels[ sanitize_key( kounselia_post_field( 'decision' ) ) ] ) );
}
add_action( 'wp_ajax_kounselia_admin_article_review', 'kounselia_ajax_admin_article_review' );

function kounselia_ajax_admin_save_article_settings() {
    kounselia_content_admin_guard( 'blog' );
    $raw = json_decode( (string) kounselia_post_field( 'settings', '{}' ), true );
    update_option( 'kounselia_article_settings', kounselia_article_sanitize_settings( is_array( $raw ) ? $raw : array() ) );
    kounselia_admin_log( 'edited_article_settings', 'settings', 0 );
    kounselia_send_pure_json_success( array( 'message' => 'Settings saved.' ) );
}
add_action( 'wp_ajax_kounselia_admin_save_article_settings', 'kounselia_ajax_admin_save_article_settings' );

function kounselia_ajax_admin_set_pro_publishing() {
    kounselia_content_admin_guard( 'blog' );
    $result = kounselia_article_set_publishing(
        (int) kounselia_post_field( 'professional_id', 0 ),
        sanitize_key( kounselia_post_field( 'publishing' ) ),
        (string) kounselia_post_field( 'note' ),
        '1' === (string) kounselia_post_field( 'take_down', '0' )
    );
    if ( is_wp_error( $result ) ) {
        kounselia_send_pure_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }
    kounselia_send_pure_json_success( array( 'message' => 'Saved.' . ( $result ? ' ' . $result . ' live ' . ( 1 === $result ? 'article was' : 'articles were' ) . ' taken down.' : '' ) ) );
}
add_action( 'wp_ajax_kounselia_admin_set_pro_publishing', 'kounselia_ajax_admin_set_pro_publishing' );

<?php
/**
 * Kounselia Core — CMS: editable pages, the blog, and the site footer.
 *
 * Everything the public site shows at /page/<slug> and /blog/... is read
 * through the helpers here, and every admin save goes through the AJAX
 * handlers at the bottom. Content is rich HTML from the admin editor,
 * cleaned by kounselia_content_kses() before it is stored, so what's in
 * the database is always safe to print.
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * URLS
 * ---------------------------------------------------------------------- */

/**
 * The public site's base URL. WordPress itself lives in /portal, so
 * home_url() ends in /portal; the public pages live one level up.
 */
function kounselia_site_url( $path = '' ) {
    $base = get_option( 'kounselia_public_url' );
    if ( ! $base ) {
        $base = preg_replace( '#/portal/?$#', '', untrailingslashit( home_url() ) );
    }
    return untrailingslashit( $base ) . '/' . ltrim( $path, '/' );
}

function kounselia_page_url( $slug, $absolute = false ) {
    $path = '/page/' . rawurlencode( $slug );
    return $absolute ? kounselia_site_url( $path ) : $path;
}

function kounselia_blog_url( $slug = '', $absolute = false ) {
    $path = '/blog/' . ( $slug ? rawurlencode( $slug ) : '' );
    return $absolute ? kounselia_site_url( $path ) : $path;
}

function kounselia_blog_tag_url( $tag_slug, $absolute = false ) {
    $path = '/blog/tag/' . rawurlencode( $tag_slug );
    return $absolute ? kounselia_site_url( $path ) : $path;
}

/* -------------------------------------------------------------------------
 * SHARED HELPERS
 * ---------------------------------------------------------------------- */

/**
 * Slugs the blog router uses for its own sections, so a post can never
 * be called "tag" or "feed" and hide one of them.
 */
function kounselia_blog_reserved_slugs() {
    return array( 'tag', 'tags', 'feed', 'rss', 'search', 'page', 'author', 'index-php' );
}

/**
 * A URL-friendly, unique slug for a page/post. Pass the table's short
 * name ('pages' or 'posts'); $exclude_id is the row being edited.
 */
function kounselia_content_unique_slug( $kind, $wanted, $exclude_id = 0 ) {
    global $wpdb;
    $table = $wpdb->prefix . ( 'posts' === $kind ? 'kounselia_posts' : 'kounselia_pages' );

    $base = sanitize_title( $wanted );
    if ( '' === $base ) {
        $base = 'untitled';
    }
    $base = substr( $base, 0, 180 );
    if ( 'posts' === $kind && in_array( $base, kounselia_blog_reserved_slugs(), true ) ) {
        $base .= '-post';
    }

    $slug   = $base;
    $suffix = 2;
    while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s AND id != %d", $slug, (int) $exclude_id ) ) ) {
        $slug = $base . '-' . $suffix;
        $suffix++;
    }
    return $slug;
}

/**
 * The HTML allowed in page/post/newsletter bodies: everything WordPress
 * allows in a normal post, plus video/audio embeds from a short list of
 * trusted hosts (YouTube, Vimeo, Spotify, SoundCloud, Google Maps).
 */
function kounselia_content_kses( $html ) {
    $allowed = wp_kses_allowed_html( 'post' );
    $allowed['iframe'] = array(
        'src' => true, 'width' => true, 'height' => true, 'frameborder' => true,
        'allow' => true, 'allowfullscreen' => true, 'title' => true, 'loading' => true,
        'style' => true, 'class' => true,
    );
    $clean = wp_kses( (string) $html, $allowed );

    // Drop any iframe that doesn't point at a trusted embed host.
    return preg_replace_callback( '#<iframe\b[^>]*>.*?</iframe>#is', function ( $m ) {
        if ( preg_match( '#\ssrc=["\']https://(www\.)?(youtube\.com|youtube-nocookie\.com|player\.vimeo\.com|open\.spotify\.com|w\.soundcloud\.com|www\.google\.com/maps)/#i', $m[0] ) ) {
            return $m[0];
        }
        return '';
    }, $clean );
}

function kounselia_content_reading_minutes( $html ) {
    $words = str_word_count( wp_strip_all_tags( (string) $html ) );
    return max( 1, (int) ceil( $words / 220 ) );
}

/**
 * "Anxiety, Self care , anxiety" -> array( 'Anxiety', 'Self care' ).
 */
function kounselia_content_parse_tags( $raw ) {
    $names = array();
    $seen  = array();
    foreach ( explode( ',', (string) $raw ) as $name ) {
        $name = trim( sanitize_text_field( $name ) );
        $slug = sanitize_title( $name );
        if ( '' === $name || '' === $slug || isset( $seen[ $slug ] ) ) {
            continue;
        }
        $seen[ $slug ] = true;
        $names[]       = mb_substr( $name, 0, 40 );
    }
    return array_slice( $names, 0, 8 );
}

/**
 * A post's tags as array of array( 'name' => ..., 'slug' => ... ).
 */
function kounselia_blog_post_tags( $post ) {
    $out = array();
    foreach ( kounselia_content_parse_tags( $post->tags ) as $name ) {
        $out[] = array( 'name' => $name, 'slug' => sanitize_title( $name ) );
    }
    return $out;
}

/**
 * Pushes the old slug onto previous_slugs so links to the old URL
 * keep working (the public router 301s them to the new one).
 */
function kounselia_content_remember_old_slug( $previous, $old_slug, $new_slug ) {
    $list = array_filter( array_map( 'trim', explode( ',', (string) $previous ) ) );
    if ( $old_slug && $old_slug !== $new_slug && ! in_array( $old_slug, $list, true ) ) {
        $list[] = $old_slug;
    }
    $list = array_diff( $list, array( $new_slug ) );
    return $list ? implode( ',', array_slice( array_values( $list ), -10 ) ) : null;
}

/* -------------------------------------------------------------------------
 * PAGES
 * ---------------------------------------------------------------------- */

function kounselia_get_page( $id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kounselia_pages WHERE id = %d", $id ) );
}

/**
 * Looks a page up by its URL slug. When $published_only is false (admin
 * preview) drafts are returned too.
 */
function kounselia_get_page_by_slug( $slug, $published_only = true ) {
    global $wpdb;
    $sql = "SELECT * FROM {$wpdb->prefix}kounselia_pages WHERE slug = %s";
    if ( $published_only ) {
        $sql .= " AND status = 'published'";
    }
    return $wpdb->get_row( $wpdb->prepare( $sql, $slug ) );
}

/**
 * If $slug used to belong to a page, that page's current slug.
 */
function kounselia_page_slug_redirect( $slug ) {
    global $wpdb;
    return $wpdb->get_var( $wpdb->prepare(
        "SELECT slug FROM {$wpdb->prefix}kounselia_pages WHERE status = 'published' AND CONCAT(',', previous_slugs, ',') LIKE %s LIMIT 1",
        '%,' . $wpdb->esc_like( $slug ) . ',%'
    ) );
}

function kounselia_list_pages( $status = '' ) {
    global $wpdb;
    $table = $wpdb->prefix . 'kounselia_pages';
    if ( $status ) {
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY title ASC", $status ) );
    }
    return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY title ASC" );
}

/**
 * Create ($id = 0) or update a page. Returns the page id or WP_Error.
 */
function kounselia_save_page( $data, $id = 0 ) {
    global $wpdb;
    $table = $wpdb->prefix . 'kounselia_pages';
    $now   = current_time( 'mysql' );

    $title = isset( $data['title'] ) ? trim( sanitize_text_field( $data['title'] ) ) : '';
    if ( '' === $title ) {
        return new WP_Error( 'missing_title', 'Please give the page a title.' );
    }

    $existing = $id ? kounselia_get_page( $id ) : null;
    if ( $id && ! $existing ) {
        return new WP_Error( 'not_found', 'That page no longer exists.' );
    }

    $wanted_slug = ! empty( $data['slug'] ) ? $data['slug'] : $title;
    $slug        = kounselia_content_unique_slug( 'pages', $wanted_slug, $id );

    $row = array(
        'slug'             => $slug,
        'title'            => mb_substr( $title, 0, 255 ),
        'eyebrow'          => isset( $data['eyebrow'] ) ? mb_substr( sanitize_text_field( $data['eyebrow'] ), 0, 120 ) : null,
        'subtitle'         => isset( $data['subtitle'] ) ? sanitize_textarea_field( $data['subtitle'] ) : null,
        'content'          => isset( $data['content'] ) ? kounselia_content_kses( $data['content'] ) : '',
        'hero_image'       => ! empty( $data['hero_image'] ) ? esc_url_raw( $data['hero_image'] ) : null,
        'meta_description' => isset( $data['meta_description'] ) ? mb_substr( sanitize_text_field( $data['meta_description'] ), 0, 320 ) : null,
        'status'           => ( isset( $data['status'] ) && 'published' === $data['status'] ) ? 'published' : 'draft',
        'updated_by'       => get_current_user_id() ?: null,
        'updated_at'       => $now,
    );

    if ( $existing ) {
        $row['previous_slugs'] = kounselia_content_remember_old_slug( $existing->previous_slugs, $existing->slug, $slug );
        $wpdb->update( $table, $row, array( 'id' => $id ) );
        return (int) $id;
    }

    $row['created_at'] = $now;
    $wpdb->insert( $table, $row );
    return (int) $wpdb->insert_id;
}

function kounselia_delete_page( $id ) {
    global $wpdb;
    return (bool) $wpdb->delete( $wpdb->prefix . 'kounselia_pages', array( 'id' => (int) $id ) );
}

/* -------------------------------------------------------------------------
 * BLOG POSTS
 * ---------------------------------------------------------------------- */

function kounselia_get_blog_post( $id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kounselia_posts WHERE id = %d", $id ) );
}

/**
 * True if a post is visible to the public right now (published, and
 * its publish time — which may be in the future when scheduled — has
 * arrived).
 */
function kounselia_blog_post_is_live( $post ) {
    return $post && 'published' === $post->status && $post->published_at && $post->published_at <= current_time( 'mysql' );
}

function kounselia_get_blog_post_by_slug( $slug, $live_only = true ) {
    global $wpdb;
    $post = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}kounselia_posts WHERE slug = %s", $slug ) );
    if ( $live_only && ! kounselia_blog_post_is_live( $post ) ) {
        return null;
    }
    return $post;
}

function kounselia_blog_slug_redirect( $slug ) {
    global $wpdb;
    return $wpdb->get_var( $wpdb->prepare(
        "SELECT slug FROM {$wpdb->prefix}kounselia_posts WHERE status = 'published' AND published_at <= %s AND CONCAT(',', previous_slugs, ',') LIKE %s LIMIT 1",
        current_time( 'mysql' ),
        '%,' . $wpdb->esc_like( $slug ) . ',%'
    ) );
}

/**
 * Public post listing. $args: tag (slug), search, page (1-based),
 * per_page, exclude (array of ids), featured_only, orderby ('date'|'views').
 * Returns array( 'items' => [...], 'total' => int ).
 */
function kounselia_blog_query( $args = array() ) {
    global $wpdb;
    $table = $wpdb->prefix . 'kounselia_posts';
    $args  = wp_parse_args( $args, array(
        'tag'           => '',
        'search'        => '',
        'page'          => 1,
        'per_page'      => 10,
        'exclude'       => array(),
        'featured_only' => false,
        'orderby'       => 'date',
    ) );

    $where  = array( "status = 'published'", 'published_at <= %s' );
    $params = array( current_time( 'mysql' ) );

    if ( $args['tag'] ) {
        $where[]  = 'tag_slugs LIKE %s';
        $params[] = '%,' . $wpdb->esc_like( sanitize_title( $args['tag'] ) ) . ',%';
    }
    if ( '' !== trim( $args['search'] ) ) {
        $like     = '%' . $wpdb->esc_like( trim( $args['search'] ) ) . '%';
        $where[]  = '(title LIKE %s OR subtitle LIKE %s OR tags LIKE %s OR content LIKE %s)';
        array_push( $params, $like, $like, $like, $like );
    }
    if ( $args['featured_only'] ) {
        $where[] = 'featured = 1';
    }
    $exclude = array_filter( array_map( 'intval', (array) $args['exclude'] ) );
    if ( $exclude ) {
        $where[] = 'id NOT IN (' . implode( ',', $exclude ) . ')';
    }

    $where_sql = implode( ' AND ', $where );
    $order     = 'views' === $args['orderby'] ? 'views DESC, published_at DESC' : 'published_at DESC, id DESC';
    $per_page  = max( 1, min( 50, (int) $args['per_page'] ) );
    $offset    = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

    $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params ) );
    $items = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$order} LIMIT %d OFFSET %d",
        array_merge( $params, array( $per_page, $offset ) )
    ) );

    return array( 'items' => $items, 'total' => $total );
}

/**
 * Every tag used by a live post, with how many posts use it, most used first.
 */
function kounselia_blog_all_tags() {
    global $wpdb;
    $rows = $wpdb->get_col( $wpdb->prepare(
        "SELECT tags FROM {$wpdb->prefix}kounselia_posts WHERE status = 'published' AND published_at <= %s AND tags IS NOT NULL AND tags != ''",
        current_time( 'mysql' )
    ) );
    $tags = array();
    foreach ( $rows as $raw ) {
        foreach ( kounselia_content_parse_tags( $raw ) as $name ) {
            $slug = sanitize_title( $name );
            if ( ! isset( $tags[ $slug ] ) ) {
                $tags[ $slug ] = array( 'name' => $name, 'slug' => $slug, 'count' => 0 );
            }
            $tags[ $slug ]['count']++;
        }
    }
    uasort( $tags, function ( $a, $b ) {
        return $b['count'] <=> $a['count'];
    } );
    return $tags;
}

/**
 * Up to $limit live posts sharing a tag with $post, topped up with the
 * newest posts if there aren't enough related ones.
 */
function kounselia_blog_related( $post, $limit = 3 ) {
    $related = array();
    $exclude = array( (int) $post->id );
    foreach ( kounselia_blog_post_tags( $post ) as $tag ) {
        $found = kounselia_blog_query( array( 'tag' => $tag['slug'], 'per_page' => $limit, 'exclude' => $exclude ) );
        foreach ( $found['items'] as $p ) {
            $related[ $p->id ] = $p;
            $exclude[]         = (int) $p->id;
        }
        if ( count( $related ) >= $limit ) {
            break;
        }
    }
    if ( count( $related ) < $limit ) {
        $found = kounselia_blog_query( array( 'per_page' => $limit - count( $related ), 'exclude' => $exclude ) );
        foreach ( $found['items'] as $p ) {
            $related[ $p->id ] = $p;
        }
    }
    return array_slice( array_values( $related ), 0, $limit );
}

/**
 * The live posts published just before and just after $post.
 */
function kounselia_blog_adjacent( $post ) {
    global $wpdb;
    $table = $wpdb->prefix . 'kounselia_posts';
    $now   = current_time( 'mysql' );
    $older = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, slug, title FROM {$table} WHERE status = 'published' AND published_at <= %s AND (published_at < %s OR (published_at = %s AND id < %d)) ORDER BY published_at DESC, id DESC LIMIT 1",
        $now, $post->published_at, $post->published_at, $post->id
    ) );
    $newer = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, slug, title FROM {$table} WHERE status = 'published' AND published_at <= %s AND (published_at > %s OR (published_at = %s AND id > %d)) ORDER BY published_at ASC, id ASC LIMIT 1",
        $now, $post->published_at, $post->published_at, $post->id
    ) );
    return array( 'older' => $older, 'newer' => $newer );
}

function kounselia_blog_count_view( $post_id ) {
    global $wpdb;
    $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}kounselia_posts SET views = views + 1 WHERE id = %d", $post_id ) );
}

/**
 * Author name + avatar for a post byline.
 */
function kounselia_blog_author( $post ) {
    $user = $post->author_id ? get_userdata( $post->author_id ) : null;
    $name = $user && $user->display_name ? $user->display_name : 'Kounselia Team';
    $avatar = false;
    if ( $user && function_exists( 'kounselia_get_avatar_url' ) ) {
        $avatar = kounselia_get_avatar_url( $user->ID, 'thumbnail' );
    }
    $bio = $user ? (string) get_user_meta( $user->ID, 'description', true ) : '';
    return array(
        'name'    => $name,
        'avatar'  => $avatar,
        'initial' => mb_strtoupper( mb_substr( $name, 0, 1 ) ),
        'bio'     => $bio,
    );
}

/**
 * Short summary for cards/meta tags: the excerpt if set, else the start
 * of the body text.
 */
function kounselia_blog_summary( $post, $length = 32 ) {
    if ( ! empty( $post->excerpt ) ) {
        return $post->excerpt;
    }
    if ( ! empty( $post->subtitle ) ) {
        return $post->subtitle;
    }
    return wp_trim_words( wp_strip_all_tags( (string) $post->content ), $length );
}

/**
 * Create ($id = 0) or update a blog post. Returns the id or WP_Error.
 *
 * $data['publish_mode']: 'draft' | 'now' | 'schedule' (with
 * $data['publish_at'] as 'Y-m-d H:i' site time). An already-published
 * post keeps its original publish date when saved again with 'now'.
 */
function kounselia_save_blog_post( $data, $id = 0 ) {
    global $wpdb;
    $table = $wpdb->prefix . 'kounselia_posts';
    $now   = current_time( 'mysql' );

    $title = isset( $data['title'] ) ? trim( sanitize_text_field( $data['title'] ) ) : '';
    if ( '' === $title ) {
        return new WP_Error( 'missing_title', 'Please give the post a title.' );
    }

    $existing = $id ? kounselia_get_blog_post( $id ) : null;
    if ( $id && ! $existing ) {
        return new WP_Error( 'not_found', 'That post no longer exists.' );
    }

    $mode = isset( $data['publish_mode'] ) ? $data['publish_mode'] : 'draft';
    if ( 'schedule' === $mode ) {
        $ts = ! empty( $data['publish_at'] ) ? strtotime( $data['publish_at'] ) : false;
        if ( ! $ts ) {
            return new WP_Error( 'bad_date', 'Please choose when the post should go live.' );
        }
        $status       = 'published';
        $published_at = date( 'Y-m-d H:i:s', $ts );
    } elseif ( 'now' === $mode ) {
        $status       = 'published';
        $published_at = ( $existing && kounselia_blog_post_is_live( $existing ) ) ? $existing->published_at : $now;
    } else {
        $status       = 'draft';
        $published_at = $existing ? $existing->published_at : null;
    }

    $content   = isset( $data['content'] ) ? kounselia_content_kses( $data['content'] ) : '';
    $tag_names = kounselia_content_parse_tags( isset( $data['tags'] ) ? $data['tags'] : '' );
    $tag_slugs = array_map( 'sanitize_title', $tag_names );
    $author_id = ! empty( $data['author_id'] ) ? (int) $data['author_id'] : ( $existing ? (int) $existing->author_id : get_current_user_id() );

    $wanted_slug = ! empty( $data['slug'] ) ? $data['slug'] : $title;
    $slug        = kounselia_content_unique_slug( 'posts', $wanted_slug, $id );

    $row = array(
        'slug'             => $slug,
        'title'            => mb_substr( $title, 0, 255 ),
        'subtitle'         => isset( $data['subtitle'] ) ? mb_substr( sanitize_text_field( $data['subtitle'] ), 0, 500 ) : null,
        'excerpt'          => isset( $data['excerpt'] ) ? sanitize_textarea_field( $data['excerpt'] ) : null,
        'content'          => $content,
        'cover_image'      => ! empty( $data['cover_image'] ) ? esc_url_raw( $data['cover_image'] ) : null,
        'cover_caption'    => isset( $data['cover_caption'] ) ? mb_substr( sanitize_text_field( $data['cover_caption'] ), 0, 255 ) : null,
        'author_id'        => $author_id ?: null,
        'tags'             => $tag_names ? implode( ', ', $tag_names ) : null,
        'tag_slugs'        => $tag_slugs ? ',' . implode( ',', $tag_slugs ) . ',' : null,
        'status'           => $status,
        'featured'         => ! empty( $data['featured'] ) ? 1 : 0,
        'meta_description' => isset( $data['meta_description'] ) ? mb_substr( sanitize_text_field( $data['meta_description'] ), 0, 320 ) : null,
        'reading_minutes'  => kounselia_content_reading_minutes( $content ),
        'published_at'     => $published_at,
        'updated_at'       => $now,
    );

    if ( $existing ) {
        $row['previous_slugs'] = kounselia_content_remember_old_slug( $existing->previous_slugs, $existing->slug, $slug );
        $wpdb->update( $table, $row, array( 'id' => $id ) );
        return (int) $id;
    }

    $row['created_at'] = $now;
    $wpdb->insert( $table, $row );
    return (int) $wpdb->insert_id;
}

function kounselia_delete_blog_post( $id ) {
    global $wpdb;
    return (bool) $wpdb->delete( $wpdb->prefix . 'kounselia_posts', array( 'id' => (int) $id ) );
}

/* -------------------------------------------------------------------------
 * SETTINGS: FOOTER + BLOG
 * ---------------------------------------------------------------------- */

function kounselia_footer_defaults() {
    return array(
        'tagline'            => 'A global mental wellness initiative. Making support accessible to anyone, anywhere, at any time.',
        'columns'            => array(
            array(
                'title' => 'Platform',
                'links' => array(
                    array( 'label' => 'Our counselors', 'page' => 'our-counselors', 'url' => '' ),
                    array( 'label' => 'Create account', 'page' => 'get-started', 'url' => '' ),
                    array( 'label' => 'Pro plans', 'page' => 'pro-plans', 'url' => '' ),
                    array( 'label' => '30 day programs', 'page' => '30-day-programs', 'url' => '' ),
                    array( 'label' => 'Safety resources', 'page' => 'safety-resources', 'url' => '' ),
                ),
            ),
            array(
                'title' => 'Organisation',
                'links' => array(
                    array( 'label' => 'Our mission', 'page' => 'our-mission', 'url' => '' ),
                    array( 'label' => 'Research', 'page' => 'research', 'url' => '' ),
                    array( 'label' => 'Partnerships', 'page' => 'partnerships', 'url' => '' ),
                    array( 'label' => 'Grant enquiries', 'page' => 'grant-enquiries', 'url' => '' ),
                    array( 'label' => 'Press', 'page' => 'press', 'url' => '' ),
                ),
            ),
            array(
                'title' => 'Read',
                'links' => array(
                    array( 'label' => 'The Journal (blog)', 'page' => '', 'url' => '/blog/' ),
                ),
            ),
        ),
        'social'             => array( 'twitter' => '', 'linkedin' => '', 'instagram' => '', 'facebook' => '', 'youtube' => '', 'email' => 'hello@kounselia.com' ),
        'show_newsletter'    => 1,
        'newsletter_heading' => 'Gentle notes, once in a while',
        'newsletter_text'    => 'Practical ideas for looking after your mind, and news from Kounselia. No spam, unsubscribe any time.',
    );
}

function kounselia_footer_settings() {
    $saved = get_option( 'kounselia_footer', array() );
    $out   = wp_parse_args( is_array( $saved ) ? $saved : array(), kounselia_footer_defaults() );
    $out['social'] = wp_parse_args( (array) $out['social'], kounselia_footer_defaults()['social'] );
    return $out;
}

/**
 * Resolves one footer link to array( label, url ), or null if it points
 * at a page that doesn't exist or isn't published (so the footer never
 * shows a dead link).
 */
function kounselia_footer_resolve_link( $link ) {
    $label = isset( $link['label'] ) ? $link['label'] : '';
    if ( ! empty( $link['page'] ) ) {
        $page = kounselia_get_page_by_slug( $link['page'] );
        if ( ! $page ) {
            // The page may have been renamed since the footer was saved.
            $moved = kounselia_page_slug_redirect( $link['page'] );
            $page  = $moved ? kounselia_get_page_by_slug( $moved ) : null;
        }
        if ( ! $page ) {
            return null;
        }
        return array( 'label' => $label ? $label : $page->title, 'url' => kounselia_page_url( $page->slug ) );
    }
    if ( ! empty( $link['url'] ) && '' !== $label ) {
        return array( 'label' => $label, 'url' => $link['url'] );
    }
    return null;
}

function kounselia_blog_settings() {
    $defaults = array(
        'title'             => 'The Kounselia Journal',
        'tagline'           => 'Honest writing on feelings, relationships, work and healing — for anyone, anywhere.',
        'per_page'          => 10,
        'auto_notify'       => 1,
        'notify_segment_id' => 0,
    );
    $saved = get_option( 'kounselia_blog_settings', array() );
    return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

/* -------------------------------------------------------------------------
 * CONTENT BLOCKS
 * Admins can drop these tokens on their own line in any page:
 *   [kounselia_counselors]     the live counselor cards
 *   [kounselia_plans]          the live paid plans (from Plans & Pricing)
 *   [kounselia_newsletter]     a newsletter sign-up box
 *   [kounselia_latest_posts]   the three newest blog posts
 *   [kounselia_signup_button]  "Create your free account" button
 * ---------------------------------------------------------------------- */

function kounselia_content_blocks() {
    return array(
        'kounselia_counselors'     => 'kounselia_block_counselors',
        'kounselia_plans'          => 'kounselia_block_plans',
        'kounselia_newsletter'     => 'kounselia_block_newsletter',
        'kounselia_latest_posts'   => 'kounselia_block_latest_posts',
        'kounselia_signup_button'  => 'kounselia_block_signup_button',
    );
}

function kounselia_render_content( $html ) {
    foreach ( kounselia_content_blocks() as $token => $fn ) {
        if ( false === strpos( $html, '[' . $token . ']' ) ) {
            continue;
        }
        $block = call_user_func( $fn );
        // A token alone in a paragraph replaces the whole paragraph.
        $html = preg_replace( '#<p[^>]*>\s*\[' . $token . '\]\s*</p>#i', $block, $html );
        $html = str_replace( '[' . $token . ']', $block, $html );
    }
    return $html;
}

function kounselia_block_counselors() {
    $ui  = function_exists( 'kounselia_get_all_ui' ) ? kounselia_get_all_ui() : array();
    $out = '<div class="k-block-counselors">';
    foreach ( $ui as $slug => $c ) {
        if ( empty( $c['name'] ) ) {
            continue;
        }
        $out .= '<a class="k-counselor" href="/#' . esc_attr( $slug ) . '">'
            . '<span class="k-counselor-av ' . esc_attr( isset( $c['class'] ) ? $c['class'] : 'ic-blue' ) . '"><i class="ti ' . esc_attr( isset( $c['icon'] ) ? $c['icon'] : 'ti-heart' ) . '"></i></span>'
            . '<span class="k-counselor-spec">' . esc_html( isset( $c['spec'] ) ? $c['spec'] : '' ) . '</span>'
            . '<span class="k-counselor-name">' . esc_html( $c['name'] ) . '</span>'
            . '<span class="k-counselor-desc">' . esc_html( isset( $c['desc'] ) ? $c['desc'] : '' ) . '</span>'
            . '<span class="k-counselor-cta">Talk to ' . esc_html( $c['name'] ) . ' <i class="ti ti-arrow-right"></i></span>'
            . '</a>';
    }
    return $out . '</div>';
}

function kounselia_block_plans() {
    $plans = function_exists( 'kounselia_get_plans' ) ? kounselia_get_plans( true ) : array();
    $out   = '<div class="k-block-plans">';
    // The benefit lists come from the real plan limits (membership.php), so this page can't drift from what members actually get.
    $lines = function ( $tier ) {
        if ( ! function_exists( 'kounselia_plan_benefit_lines' ) ) {
            return '';
        }
        return implode( '', array_map( function ( $l ) {
            return '<li>' . esc_html( $l ) . '</li>';
        }, kounselia_plan_benefit_lines( $tier ) ) );
    };
    $out  .= '<div class="k-plan"><div class="k-plan-name">Free</div><div class="k-plan-price">0<span> / forever</span></div><ul>' . ( $lines( 'free' ) ?: '<li>Talk to every counselor</li><li>Private, encrypted conversations</li><li>Mood check-ins and journal</li>' ) . '</ul><a class="k-btn outline" href="/?auth=register">Start free</a></div>';
    foreach ( $plans as $plan ) {
        $symbol = 'NGN' === $plan['currency'] ? '₦' : ( 'USD' === $plan['currency'] ? '$' : ( 'GBP' === $plan['currency'] ? '£' : $plan['currency'] . ' ' ) );
        $out   .= '<div class="k-plan' . ( ! empty( $plan['is_popular'] ) ? ' popular' : '' ) . '">'
            . ( ! empty( $plan['is_popular'] ) ? '<div class="k-plan-badge">Most popular</div>' : '' )
            . '<div class="k-plan-name">' . esc_html( $plan['name'] ) . '</div>'
            . '<div class="k-plan-price">' . esc_html( $symbol . number_format_i18n( (float) $plan['price_amount'] ) ) . '<span> / ' . ( 'yearly' === $plan['interval'] ? 'year' : 'month' ) . '</span></div><ul>';
        $out .= $lines( 'pro' );
        foreach ( (array) $plan['features'] as $f ) {
            $out .= '<li>' . esc_html( $f ) . '</li>';
        }
        $out .= '</ul><a class="k-btn" href="/dashboard.php?tab=upgrade">Choose ' . esc_html( $plan['name'] ) . '</a></div>';
    }
    return $out . '</div>';
}

function kounselia_block_newsletter() {
    $f = kounselia_footer_settings();
    return '<div class="k-block-newsletter">'
        . '<div class="k-nl-copy"><h3>' . esc_html( $f['newsletter_heading'] ) . '</h3><p>' . esc_html( $f['newsletter_text'] ) . '</p></div>'
        . kounselia_newsletter_form_html( 'page' )
        . '</div>';
}

function kounselia_block_latest_posts() {
    $found = kounselia_blog_query( array( 'per_page' => 3 ) );
    if ( ! $found['items'] ) {
        return '';
    }
    $out = '<div class="k-block-posts">';
    foreach ( $found['items'] as $p ) {
        $out .= '<a class="k-mini-post" href="' . esc_url( kounselia_blog_url( $p->slug ) ) . '">'
            . ( $p->cover_image ? '<img src="' . esc_url( $p->cover_image ) . '" alt="" loading="lazy">' : '' )
            . '<span class="k-mini-title">' . esc_html( $p->title ) . '</span>'
            . '<span class="k-mini-meta">' . esc_html( $p->reading_minutes ) . ' min read</span></a>';
    }
    return $out . '</div>';
}

function kounselia_block_signup_button() {
    return '<p class="k-center"><a class="k-btn" href="/?auth=register">Create your free account</a></p>';
}

/**
 * The newsletter sign-up form (footer, blog sidebar, pages). Submitted
 * by /inc/kounselia-site-scripts.php to kounselia_newsletter_subscribe.
 */
function kounselia_newsletter_form_html( $source = 'website', $button = 'Subscribe' ) {
    return '<form class="k-nl-form" data-source="' . esc_attr( $source ) . '" novalidate>'
        . '<input type="text" name="website" class="k-hp" tabindex="-1" autocomplete="off" aria-hidden="true">'
        . '<input type="text" name="name" placeholder="First name (optional)" autocomplete="given-name" aria-label="First name">'
        . '<input type="email" name="email" placeholder="Your email address" autocomplete="email" required aria-label="Email address">'
        . '<button type="submit">' . esc_html( $button ) . '</button>'
        . '<div class="k-nl-msg" role="status" aria-live="polite"></div>'
        . '</form>';
}

/* -------------------------------------------------------------------------
 * RSS FEED (/blog/feed)
 * ---------------------------------------------------------------------- */

function kounselia_blog_render_feed() {
    $settings = kounselia_blog_settings();
    $found    = kounselia_blog_query( array( 'per_page' => 20 ) );
    header( 'Content-Type: application/rss+xml; charset=UTF-8' );
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>';
    echo '<title>' . esc_html( $settings['title'] ) . '</title>';
    echo '<link>' . esc_url( kounselia_blog_url( '', true ) ) . '</link>';
    echo '<atom:link href="' . esc_url( kounselia_site_url( '/blog/feed' ) ) . '" rel="self" type="application/rss+xml" />';
    echo '<description>' . esc_html( $settings['tagline'] ) . '</description>';
    echo '<language>en</language>';
    foreach ( $found['items'] as $p ) {
        $url = kounselia_blog_url( $p->slug, true );
        echo '<item>';
        echo '<title>' . esc_html( $p->title ) . '</title>';
        echo '<link>' . esc_url( $url ) . '</link>';
        echo '<guid isPermaLink="true">' . esc_url( $url ) . '</guid>';
        echo '<pubDate>' . esc_html( mysql2date( 'D, d M Y H:i:s O', $p->published_at, false ) ) . '</pubDate>';
        echo '<description>' . esc_html( kounselia_blog_summary( $p, 55 ) ) . '</description>';
        foreach ( kounselia_blog_post_tags( $p ) as $t ) {
            echo '<category>' . esc_html( $t['name'] ) . '</category>';
        }
        echo '</item>';
    }
    echo '</channel></rss>';
}

/* -------------------------------------------------------------------------
 * ADMIN AJAX
 * ---------------------------------------------------------------------- */

function kounselia_content_admin_guard( $permission ) {
    check_ajax_referer( 'kounselia_admin_nonce', 'nonce' );
    if ( ! kounselia_admin_can( $permission ) ) {
        kounselia_send_pure_json_error( array( 'message' => 'You do not have access to this area.' ), 403 );
    }
}

function kounselia_post_field( $key, $default = '' ) {
    return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default;
}

function kounselia_ajax_admin_save_page() {
    kounselia_content_admin_guard( 'pages' );
    $id     = (int) kounselia_post_field( 'id', 0 );
    $result = kounselia_save_page( array(
        'title'            => kounselia_post_field( 'title' ),
        'slug'             => kounselia_post_field( 'slug' ),
        'eyebrow'          => kounselia_post_field( 'eyebrow' ),
        'subtitle'         => kounselia_post_field( 'subtitle' ),
        'content'          => kounselia_post_field( 'content' ),
        'hero_image'       => kounselia_post_field( 'hero_image' ),
        'meta_description' => kounselia_post_field( 'meta_description' ),
        'status'           => kounselia_post_field( 'status' ),
    ), $id );

    if ( is_wp_error( $result ) ) {
        kounselia_send_pure_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }
    kounselia_admin_log( $id ? 'edited_page' : 'created_page', 'page', $result );
    $page = kounselia_get_page( $result );
    kounselia_send_pure_json_success( array(
        'message' => 'published' === $page->status ? 'Page saved and live.' : 'Draft saved.',
        'id'      => (int) $page->id,
        'slug'    => $page->slug,
        'url'     => kounselia_page_url( $page->slug ),
        'status'  => $page->status,
    ) );
}
add_action( 'wp_ajax_kounselia_admin_save_page', 'kounselia_ajax_admin_save_page' );

function kounselia_ajax_admin_delete_page() {
    kounselia_content_admin_guard( 'pages' );
    $id = (int) kounselia_post_field( 'id', 0 );
    kounselia_delete_page( $id );
    kounselia_admin_log( 'deleted_page', 'page', $id );
    kounselia_send_pure_json_success( array( 'message' => 'Page deleted.' ) );
}
add_action( 'wp_ajax_kounselia_admin_delete_page', 'kounselia_ajax_admin_delete_page' );

function kounselia_ajax_admin_save_footer() {
    kounselia_content_admin_guard( 'pages' );
    $raw     = json_decode( (string) kounselia_post_field( 'footer', '{}' ), true );
    $raw     = is_array( $raw ) ? $raw : array();
    $columns = array();
    foreach ( array_slice( (array) ( $raw['columns'] ?? array() ), 0, 4 ) as $col ) {
        $links = array();
        foreach ( array_slice( (array) ( $col['links'] ?? array() ), 0, 12 ) as $link ) {
            $page = sanitize_title( $link['page'] ?? '' );
            $url  = trim( (string) ( $link['url'] ?? '' ) );
            // Allow site-relative paths ("/blog/") as well as full URLs.
            $url  = ( '' !== $url && '/' === $url[0] && '/' !== ( $url[1] ?? '' ) ) ? esc_url_raw( kounselia_site_url( $url ) ) : esc_url_raw( $url );
            $url  = $url ? preg_replace( '#^' . preg_quote( untrailingslashit( kounselia_site_url() ), '#' ) . '#', '', $url ) : '';
            $label = sanitize_text_field( $link['label'] ?? '' );
            if ( '' === $page && '' === $url ) {
                continue;
            }
            $links[] = array( 'label' => $label, 'page' => $page, 'url' => $page ? '' : $url );
        }
        $columns[] = array( 'title' => sanitize_text_field( $col['title'] ?? '' ), 'links' => $links );
    }

    $social = array();
    foreach ( array_keys( kounselia_footer_defaults()['social'] ) as $key ) {
        $val            = trim( (string) ( $raw['social'][ $key ] ?? '' ) );
        $social[ $key ] = 'email' === $key ? sanitize_email( $val ) : esc_url_raw( $val );
    }

    update_option( 'kounselia_footer', array(
        'tagline'            => sanitize_textarea_field( $raw['tagline'] ?? '' ),
        'columns'            => $columns,
        'social'             => $social,
        'show_newsletter'    => ! empty( $raw['show_newsletter'] ) ? 1 : 0,
        'newsletter_heading' => sanitize_text_field( $raw['newsletter_heading'] ?? '' ),
        'newsletter_text'    => sanitize_textarea_field( $raw['newsletter_text'] ?? '' ),
    ) );
    kounselia_admin_log( 'edited_footer', 'settings', 0 );
    kounselia_send_pure_json_success( array( 'message' => 'Footer saved.' ) );
}
add_action( 'wp_ajax_kounselia_admin_save_footer', 'kounselia_ajax_admin_save_footer' );

function kounselia_ajax_admin_save_post() {
    kounselia_content_admin_guard( 'blog' );
    $id      = (int) kounselia_post_field( 'id', 0 );
    $before  = $id ? kounselia_get_blog_post( $id ) : null;
    $result  = kounselia_save_blog_post( array(
        'title'            => kounselia_post_field( 'title' ),
        'slug'             => kounselia_post_field( 'slug' ),
        'subtitle'         => kounselia_post_field( 'subtitle' ),
        'excerpt'          => kounselia_post_field( 'excerpt' ),
        'content'          => kounselia_post_field( 'content' ),
        'cover_image'      => kounselia_post_field( 'cover_image' ),
        'cover_caption'    => kounselia_post_field( 'cover_caption' ),
        'author_id'        => kounselia_post_field( 'author_id' ),
        'tags'             => kounselia_post_field( 'tags' ),
        'featured'         => kounselia_post_field( 'featured' ),
        'meta_description' => kounselia_post_field( 'meta_description' ),
        'publish_mode'     => kounselia_post_field( 'publish_mode', 'draft' ),
        'publish_at'       => kounselia_post_field( 'publish_at' ),
    ), $id );

    if ( is_wp_error( $result ) ) {
        kounselia_send_pure_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }

    $post    = kounselia_get_blog_post( $result );
    $message = 'draft' === $post->status ? 'Draft saved.' : ( kounselia_blog_post_is_live( $post ) ? 'Post published.' : 'Post scheduled for ' . mysql2date( 'M j, Y g:ia', $post->published_at ) . '.' );

    // Email readers about it, once per post, if asked to.
    $notify = '1' === (string) kounselia_post_field( 'notify', '0' );
    if ( $notify && 'published' === $post->status && ! $post->notify_campaign_id && function_exists( 'kounselia_newsletter_queue_post_notification' ) ) {
        $segment_id  = (int) kounselia_post_field( 'notify_segment_id', 0 );
        $campaign_id = kounselia_newsletter_queue_post_notification( $post->id, $segment_id );
        if ( $campaign_id && ! is_wp_error( $campaign_id ) ) {
            $message .= kounselia_blog_post_is_live( $post ) ? ' Subscribers are being emailed.' : ' Subscribers will be emailed when it goes live.';
        }
    }

    kounselia_admin_log( $before ? 'edited_post' : 'created_post', 'post', $result );
    $post = kounselia_get_blog_post( $result );
    kounselia_send_pure_json_success( array(
        'message'  => $message,
        'id'       => (int) $post->id,
        'slug'     => $post->slug,
        'url'      => kounselia_blog_url( $post->slug ),
        'status'   => $post->status,
        'notified' => (bool) $post->notify_campaign_id,
    ) );
}
add_action( 'wp_ajax_kounselia_admin_save_post', 'kounselia_ajax_admin_save_post' );

function kounselia_ajax_admin_delete_post() {
    kounselia_content_admin_guard( 'blog' );
    $id = (int) kounselia_post_field( 'id', 0 );
    kounselia_delete_blog_post( $id );
    kounselia_admin_log( 'deleted_post', 'post', $id );
    kounselia_send_pure_json_success( array( 'message' => 'Post deleted.' ) );
}
add_action( 'wp_ajax_kounselia_admin_delete_post', 'kounselia_ajax_admin_delete_post' );

function kounselia_ajax_admin_save_blog_settings() {
    kounselia_content_admin_guard( 'blog' );
    update_option( 'kounselia_blog_settings', array(
        'title'             => sanitize_text_field( kounselia_post_field( 'title' ) ) ?: 'The Kounselia Journal',
        'tagline'           => sanitize_textarea_field( kounselia_post_field( 'tagline' ) ),
        'per_page'          => max( 3, min( 30, (int) kounselia_post_field( 'per_page', 10 ) ) ),
        'auto_notify'       => '1' === (string) kounselia_post_field( 'auto_notify', '0' ) ? 1 : 0,
        'notify_segment_id' => (int) kounselia_post_field( 'notify_segment_id', 0 ),
    ) );
    kounselia_admin_log( 'edited_blog_settings', 'settings', 0 );
    kounselia_send_pure_json_success( array( 'message' => 'Blog settings saved.' ) );
}
add_action( 'wp_ajax_kounselia_admin_save_blog_settings', 'kounselia_ajax_admin_save_blog_settings' );

/**
 * Image upload for the rich editor and cover/hero pickers. Images go
 * into the normal WordPress uploads folder (public), resized so a
 * phone photo doesn't ship a 6MB file to every reader.
 */
function kounselia_ajax_admin_upload_media() {
    check_ajax_referer( 'kounselia_admin_nonce', 'nonce' );
    if ( ! kounselia_admin_can( 'pages' ) && ! kounselia_admin_can( 'blog' ) && ! kounselia_admin_can( 'broadcasts' ) ) {
        kounselia_send_pure_json_error( array( 'message' => 'You do not have access to upload.' ), 403 );
    }
    if ( empty( $_FILES['file'] ) || ! empty( $_FILES['file']['error'] ) ) {
        kounselia_send_pure_json_error( array( 'message' => 'No file received.' ), 400 );
    }
    if ( $_FILES['file']['size'] > 8 * MB_IN_BYTES ) {
        kounselia_send_pure_json_error( array( 'message' => 'Images must be under 8MB.' ), 400 );
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    $mimes  = array(
        'jpg|jpeg|jpe' => 'image/jpeg',
        'png'          => 'image/png',
        'gif'          => 'image/gif',
        'webp'         => 'image/webp',
    );
    $upload = wp_handle_upload( $_FILES['file'], array( 'test_form' => false, 'mimes' => $mimes ) );
    if ( isset( $upload['error'] ) ) {
        kounselia_send_pure_json_error( array( 'message' => $upload['error'] ), 400 );
    }

    if ( 'image/gif' !== $upload['type'] ) {
        $editor = wp_get_image_editor( $upload['file'] );
        if ( ! is_wp_error( $editor ) ) {
            $size = $editor->get_size();
            if ( $size['width'] > 2000 ) {
                $editor->resize( 2000, null, false );
                $editor->set_quality( 84 );
                $editor->save( $upload['file'] );
            }
        }
    }

    kounselia_admin_log( 'uploaded_media', 'media', 0 );
    // "location" is the key the rich editor expects.
    kounselia_send_pure_json_success( array( 'url' => $upload['url'], 'location' => $upload['url'] ) );
}
add_action( 'wp_ajax_kounselia_admin_upload_media', 'kounselia_ajax_admin_upload_media' );

/* -------------------------------------------------------------------------
 * DEFAULT CONTENT
 * The footer used to list links that went nowhere. The first time this
 * runs it creates a real, published page for each one, written in the
 * site's existing voice, so the footer works on day one. Admins can then
 * rewrite any of them in Admin → Pages. Never runs again once done, so
 * deleting a page is permanent.
 * ---------------------------------------------------------------------- */

function kounselia_content_seed_defaults() {
    if ( get_option( 'kounselia_content_seeded' ) ) {
        return;
    }
    update_option( 'kounselia_content_seeded', 1 );

    foreach ( kounselia_default_pages() as $page ) {
        if ( kounselia_get_page_by_slug( $page['slug'], false ) ) {
            continue;
        }
        $page['status'] = 'published';
        kounselia_save_page( $page );
    }
}

function kounselia_default_pages() {
    return array(
        array(
            'slug'     => 'our-counselors',
            'title'    => 'Meet our counselors',
            'eyebrow'  => 'Platform',
            'subtitle' => 'Eight specialists, each built around one area of life. Pick the one who feels right for what you are carrying today.',
            'meta_description' => 'Meet the Kounselia counselors: specialists in emotional healing, career, relationships, trauma, grief, burnout and more.',
            'content'  => '<p class="k-lead">Walking into a good clinic, you are not handed to whoever happens to be free. You are connected with the person who knows your situation best. Kounselia works the same way.</p>'
                . '<p>Each counselor focuses on one area, so the conversation starts where you actually are. You do not need an account to begin, and you can switch counselors at any time.</p>'
                . '<p>[kounselia_counselors]</p>'
                . '<h2>How a conversation works</h2>'
                . '<ul><li><strong>Start anytime.</strong> There is no appointment and no waiting list.</li><li><strong>Go at your own pace.</strong> Write as much or as little as you want. Nobody rushes you.</li><li><strong>Come back to where you left off.</strong> With a free account, your counselor remembers your story so you never have to start over.</li></ul>'
                . '<div class="k-callout"><p><strong>Kounselia is a supportive wellness service, not a replacement for clinical care.</strong> If you are in danger or thinking about ending your life, please see our <a href="/page/safety-resources">safety resources</a> for immediate help.</p></div>',
        ),
        array(
            'slug'     => 'get-started',
            'title'    => 'Create your free account',
            'eyebrow'  => 'Platform',
            'subtitle' => 'You can talk to a counselor without signing up. An account simply lets your support remember you.',
            'meta_description' => 'Create a free Kounselia account to save your conversations, track your mood, and pick up where you left off.',
            'content'  => '<p class="k-lead">An account takes less than a minute and is free forever. Here is what it gives you.</p>'
                . '<h2>What you get with a free account</h2>'
                . '<ul><li><strong>Conversations that continue.</strong> Your counselor remembers what you have shared, so every session picks up where the last one ended.</li><li><strong>A private dashboard.</strong> Daily mood check-ins, a personal journal, and your history in one calm place.</li><li><strong>Human professionals when you want them.</strong> Book time with verified professionals directly from your dashboard.</li></ul>'
                . '<p>[kounselia_signup_button]</p>'
                . '<h2>Your privacy</h2>'
                . '<p>Your conversations are private and encrypted. We never sell your data, and you can delete your account and everything in it at any time from your settings.</p>',
        ),
        array(
            'slug'     => 'pro-plans',
            'title'    => 'Plans that grow with you',
            'eyebrow'  => 'Pro plans',
            'subtitle' => 'The free tier never expires and never compromises on quality. Pro adds depth for people who want more structure.',
            'meta_description' => 'Compare Kounselia Free and Pro plans. The free tier never expires; Pro adds deep memory, structured programs and voice calls.',
            'content'  => '<p class="k-lead">We believe everyone deserves support, whatever their income or location. That is why the core of Kounselia is free.</p>'
                . '<p>[kounselia_plans]</p>'
                . '<h2>Common questions</h2>'
                . '<h3>Can I cancel anytime?</h3><p>Yes. You can cancel from your dashboard at any time, and you keep Pro access until the end of the period you have paid for.</p>'
                . '<h3>Is the free plan really free?</h3><p>Yes. No card needed, no trial that runs out. You can talk to every counselor on the free plan.</p>'
                . '<h3>How do payments work?</h3><p>Payments are handled securely by Paystack. Kounselia never sees or stores your card details.</p>',
        ),
        array(
            'slug'     => '30-day-programs',
            'title'    => '30 day programs',
            'eyebrow'  => 'Programs',
            'subtitle' => 'Small, steady steps over thirty days. Structured guidance for the things that are hard to change alone.',
            'meta_description' => 'Kounselia 30 day programs offer structured daily guidance for anxiety, burnout, grief, confidence and more.',
            'content'  => '<p class="k-lead">Real change rarely happens in one conversation. It happens in small moments, repeated. Our 30 day programs give those moments a shape.</p>'
                . '<h2>How programs work</h2>'
                . '<ul><li><strong>A short daily step.</strong> Five to fifteen minutes: a reflection, an exercise or a conversation prompt.</li><li><strong>Your counselor alongside you.</strong> Talk through how each day went with the counselor who fits the program.</li><li><strong>Progress you can see.</strong> Mood check-ins show how things are shifting over the month.</li></ul>'
                . '<h2>Programs we are building</h2>'
                . '<ul><li>Calmer days: working with anxiety and overthinking</li><li>Running on empty: recovering from burnout</li><li>Carrying loss: thirty days with grief</li><li>Steady ground: rebuilding confidence and self worth</li><li>Better conversations: repairing the relationships that matter</li></ul>'
                . '<p>Programs are part of the <a href="/page/pro-plans">Pro plan</a>. Subscribe to our newsletter below to hear when each one opens.</p>'
                . '<p>[kounselia_newsletter]</p>',
        ),
        array(
            'slug'     => 'safety-resources',
            'title'    => 'Safety resources',
            'eyebrow'  => 'Get help now',
            'subtitle' => 'If you are in danger or thinking about ending your life, please reach out to people who can help right now.',
            'meta_description' => 'Immediate crisis and emergency support resources. If you are in danger, contact your local emergency services.',
            'content'  => '<div class="k-callout"><p><strong>If you or someone else is in immediate danger, call your local emergency number now.</strong> In most of Europe and in Nigeria that is <strong>112</strong>. In the United States and Canada it is <strong>911</strong>. In the United Kingdom it is <strong>999</strong>.</p></div>'
                . '<p class="k-lead">Kounselia is a supportive wellness platform. It is not an emergency service and it cannot send help to you. When things are serious, trained people should be with you.</p>'
                . '<h2>Talk to a crisis line</h2>'
                . '<ul><li><strong>Find a helpline anywhere in the world:</strong> <a href="https://findahelpline.com" target="_blank" rel="noopener">findahelpline.com</a> lists free, confidential crisis lines by country.</li><li><strong>United States:</strong> call or text <strong>988</strong> (Suicide &amp; Crisis Lifeline).</li><li><strong>United Kingdom and Ireland:</strong> call Samaritans on <strong>116 123</strong>, free at any time.</li></ul>'
                . '<h2>If you are worried about someone</h2>'
                . '<ul><li>Ask them directly how they are. Asking about suicide does not put the idea in someone\'s head.</li><li>Listen without judging, and stay with them if you can.</li><li>Help them contact a crisis line or emergency services, and remove anything they could use to hurt themselves.</li></ul>'
                . '<h2>How Kounselia keeps you safe</h2>'
                . '<p>Our counselors are designed to notice signs of distress. When they do, they will pause the conversation to share crisis resources with you, and our safety team is alerted so a real person can follow up where appropriate.</p>',
        ),
        array(
            'slug'     => 'our-mission',
            'title'    => 'Our mission',
            'eyebrow'  => 'Why Kounselia exists',
            'subtitle' => 'Most people who need support never get it. We are building a world where talking to someone is never out of reach.',
            'meta_description' => 'Kounselia is a global mental wellness initiative making support accessible to anyone, anywhere, at any time.',
            'content'  => '<p class="k-lead">More than a billion people live with a mental health condition, and in lower income countries most receive no support at all. Not because they do not want help, but because therapy is expensive, waiting lists are long, and a trained professional is simply not nearby.</p>'
                . '<p>We built Kounselia because we believe that should change.</p>'
                . '<h2>What we believe</h2>'
                . '<ul><li><strong>Support is a right, not a luxury.</strong> The free tier never expires and never compromises on quality.</li><li><strong>The right person matters.</strong> Specialist counselors, each focused on one area of life, instead of a generic assistant.</li><li><strong>Safety comes first.</strong> Our platform is designed to recognise distress and connect people with real human help.</li><li><strong>Evidence, not guesswork.</strong> Every conversation is built on established approaches like CBT, motivational interviewing and trauma informed care.</li></ul>'
                . '<blockquote><p>You deserve someone to talk to. Anytime, anywhere, in your own words.</p></blockquote>'
                . '<h2>Where we are going</h2>'
                . '<p>We are working with clinicians, researchers and partners to reach the people traditional services miss, starting in communities where support is hardest to find.</p>'
                . '<p>[kounselia_signup_button]</p>',
        ),
        array(
            'slug'     => 'research',
            'title'    => 'Research',
            'eyebrow'  => 'Evidence informed',
            'subtitle' => 'We publish our approach openly and partner with researchers who study what actually works in digital mental health.',
            'meta_description' => 'How Kounselia uses evidence informed practice and partners with researchers studying digital mental health support.',
            'content'  => '<p class="k-lead">Good intentions are not enough in mental health. We want to know what helps, for whom, and why.</p>'
                . '<h2>Our approach</h2>'
                . '<p>Kounselia counselors are designed around established therapeutic frameworks, including cognitive behavioural therapy (CBT), motivational interviewing and trauma informed care. Each counselor\'s guidance is reviewed and refined by our clinical board.</p>'
                . '<h2>Privacy in research</h2>'
                . '<p>Any research we take part in uses anonymised, aggregated information only, and is subject to ethical review. Your conversations are never shared with researchers in a way that could identify you.</p>'
                . '<h2>Work with us</h2>'
                . '<p>If you are a researcher or institution studying digital mental health, access to care, or outcomes in underserved communities, we would love to talk. Email <a href="mailto:hello@kounselia.com">hello@kounselia.com</a> with a short outline of your work.</p>',
        ),
        array(
            'slug'     => 'partnerships',
            'title'    => 'Partnerships',
            'eyebrow'  => 'Partnerships and funding',
            'subtitle' => 'Kounselia is a social initiative as much as a platform. We are building relationships with people who share our values.',
            'meta_description' => 'Partner with Kounselia: foundations, health organisations, employers and impact investors working on mental health access.',
            'content'  => '<p class="k-lead">No single organisation can close the mental health gap alone. We partner with foundations, health organisations, employers and impact investors who believe access to support is a fundamental right.</p>'
                . '<h2>Ways to partner</h2>'
                . '<ul><li><strong>Health organisations and NGOs:</strong> offer Kounselia to the communities you already serve, with clear routes to your human services.</li><li><strong>Employers and universities:</strong> give your people private, round the clock support.</li><li><strong>Foundations and funders:</strong> help us keep the free tier free and reach more people.</li><li><strong>Clinicians and professionals:</strong> join our marketplace of verified professionals.</li></ul>'
                . '<div class="k-callout"><p>If you represent a foundation, research institution or public health body and would like to explore what we are building, email <a href="mailto:hello@kounselia.com">hello@kounselia.com</a>.</p></div>',
        ),
        array(
            'slug'     => 'grant-enquiries',
            'title'    => 'Grant enquiries',
            'eyebrow'  => 'Funding',
            'subtitle' => 'Your support keeps free, high quality mental health support available to anyone who needs it.',
            'meta_description' => 'Grant and funding enquiries for Kounselia, a global mental wellness initiative focused on access and equity.',
            'content'  => '<p class="k-lead">Grant funding lets us do the things a purely commercial product would not: keep the core service free, reach low income communities, and invest in safety and research.</p>'
                . '<h2>What funding supports</h2>'
                . '<ul><li>Keeping the free tier free for everyone, everywhere</li><li>Safety systems and human follow up for people in distress</li><li>Local languages and culturally grounded counselors</li><li>Independent evaluation of outcomes</li></ul>'
                . '<h2>Get in touch</h2>'
                . '<p>Please email <a href="mailto:hello@kounselia.com">hello@kounselia.com</a> with the subject line "Grant enquiry", the name of your organisation, and the programme you are considering. We will reply with our current impact summary and proposal materials.</p>',
        ),
        array(
            'slug'     => 'press',
            'title'    => 'Press',
            'eyebrow'  => 'Media',
            'subtitle' => 'News, resources and contacts for journalists writing about Kounselia and digital mental health.',
            'meta_description' => 'Press and media information for Kounselia, including contacts and brand resources.',
            'content'  => '<p class="k-lead">Kounselia is a global mental wellness initiative that connects people with specialist counselors anytime, anywhere, free at the core.</p>'
                . '<h2>Media enquiries</h2>'
                . '<p>For interviews, comment or information, email <a href="mailto:hello@kounselia.com">hello@kounselia.com</a> with "Press" in the subject line. We aim to reply within two working days.</p>'
                . '<h2>Reporting on mental health</h2>'
                . '<p>When writing about suicide or self harm, please follow safe reporting guidance and include information on where readers can find help, such as our <a href="/page/safety-resources">safety resources</a> page.</p>'
                . '<h2>Latest from our journal</h2>'
                . '<p>[kounselia_latest_posts]</p>',
        ),
    );
}

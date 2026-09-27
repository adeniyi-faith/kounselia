<?php
/**
 * Kounselia Core — shared image compression, used by every place a member
 * or professional uploads a picture: profile photos (account.php,
 * app-content.php) and post/article images (content.php, articles.php).
 *
 * A phone camera or screenshot routinely produces a multi-megabyte file at
 * near-100 JPEG quality, most of which is invisible to the eye. Re-saving
 * at a slightly lower quality and capping the dimensions nobody displays
 * a picture at cuts file size dramatically with no visible difference —
 * that's the "compress without losing quality" a photo can actually
 * deliver on (true lossless compression only shrinks a JPEG a few
 * percent; this is what every photo-sharing app does instead).
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Re-saves an image file in place: shrinks it if wider than $max_width,
 * and always re-encodes at $quality. Animated GIFs are left untouched
 * (resizing/re-encoding would collapse them to a single frame).
 *
 * Returns true on success, false if the file couldn't be processed (the
 * caller should just keep the original upload in that case, not fail it).
 */
function kounselia_compress_image_file( $path, $max_width = 2000, $quality = 82 ) {
    if ( ! file_exists( $path ) ) {
        return false;
    }

    $mime = wp_get_image_mime( $path );
    if ( 'image/gif' === $mime ) {
        return true;
    }

    $editor = wp_get_image_editor( $path );
    if ( is_wp_error( $editor ) ) {
        return false;
    }

    $size = $editor->get_size();
    if ( $size && $size['width'] > $max_width ) {
        $editor->resize( $max_width, null, false );
    }

    $editor->set_quality( $quality );
    $saved = $editor->save( $path );

    return ! is_wp_error( $saved );
}

/**
 * Same, but for a media library attachment: compresses its underlying
 * file, then regenerates the thumbnail/medium/large sizes from the
 * now-smaller original so every size benefits, not just "full".
 */
function kounselia_compress_attachment( $attachment_id, $max_width = 2000, $quality = 82 ) {
    $path = get_attached_file( $attachment_id );
    if ( ! $path ) {
        return false;
    }

    $ok = kounselia_compress_image_file( $path, $max_width, $quality );
    if ( $ok ) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata( $attachment_id, $path );
        if ( $metadata ) {
            wp_update_attachment_metadata( $attachment_id, $metadata );
        }
    }

    return $ok;
}

/**
 * Compresses a picture already sitting in the uploads folder, given the
 * public URL it's stored under (what kounselia_content_store_image()
 * returns and what a post's cover_image / inline <img> tags hold — those
 * aren't media library attachments, just files, so there's no attachment
 * id to hand kounselia_compress_attachment()). Returns false, without
 * touching anything, for a URL that isn't one of ours (an image someone
 * linked to from elsewhere, which we have no file to compress).
 */
function kounselia_compress_uploaded_image_url( $url, $max_width = 2000, $quality = 82 ) {
    if ( empty( $url ) ) {
        return false;
    }

    $upload_dir = wp_upload_dir();
    if ( 0 !== strpos( $url, $upload_dir['baseurl'] ) ) {
        return false;
    }

    $relative_path = ltrim( substr( $url, strlen( $upload_dir['baseurl'] ) ), '/' );
    $path          = trailingslashit( $upload_dir['basedir'] ) . $relative_path;

    return kounselia_compress_image_file( $path, $max_width, $quality );
}

/**
 * One-off maintenance pass over every picture already on the site —
 * everyone's profile photo, plus every post's cover image and any
 * pictures pasted into a post's body — for images uploaded before this
 * compression step existed, or from before it was deployed. New uploads
 * are compressed automatically as they come in; this is only for
 * catching up on the backlog. Triggered from Admin → Settings →
 * System Maintenance.
 */
function kounselia_backfill_compress_existing_images( $max_width = 2000, $quality = 82 ) {
    $counts = array( 'avatars' => 0, 'images' => 0 );

    $users = get_users( array( 'meta_key' => 'kounselia_avatar_id', 'fields' => array( 'ID' ) ) );
    foreach ( $users as $user ) {
        $attachment_id = (int) get_user_meta( $user->ID, 'kounselia_avatar_id', true );
        if ( $attachment_id && kounselia_compress_attachment( $attachment_id, 1024, 82 ) ) {
            $counts['avatars']++;
        }
    }

    global $wpdb;
    $posts = $wpdb->get_results( "SELECT cover_image, content FROM {$wpdb->prefix}kounselia_posts" );

    $urls = array();
    foreach ( $posts as $post ) {
        if ( $post->cover_image ) {
            $urls[] = $post->cover_image;
        }
        if ( $post->content && preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $post->content, $matches ) ) {
            $urls = array_merge( $urls, $matches[1] );
        }
    }

    $touched = array();
    foreach ( array_unique( $urls ) as $url ) {
        if ( isset( $touched[ $url ] ) ) {
            continue;
        }
        $touched[ $url ] = true;
        if ( kounselia_compress_uploaded_image_url( $url, $max_width, $quality ) ) {
            $counts['images']++;
        }
    }

    return $counts;
}

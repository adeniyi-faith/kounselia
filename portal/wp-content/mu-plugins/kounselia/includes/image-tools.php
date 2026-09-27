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

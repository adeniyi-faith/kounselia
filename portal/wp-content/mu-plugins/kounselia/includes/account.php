<?php
/**
 * Kounselia Core — avatar upload, profile update, change password AJAX endpoints
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * 7. ACCOUNT: AVATAR UPLOAD
 * ---------------------------------------------------------------------- */

function kounselia_ajax_upload_avatar() {
    kounselia_verify_nonce();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.' ), 401 );
    }

    if ( empty( $_FILES['avatar'] ) || empty( $_FILES['avatar']['tmp_name'] ) ) {
        wp_send_json_error( array( 'message' => 'No image was received, please choose a file and try again.' ), 400 );
    }

    $file = $_FILES['avatar'];

    $allowed_types = array( 'image/jpeg', 'image/png', 'image/webp' );
    if ( ! in_array( $file['type'], $allowed_types, true ) ) {
        wp_send_json_error( array( 'message' => 'Please upload a JPG, PNG, or WEBP image.' ), 400 );
    }

    $max_bytes = 4 * 1024 * 1024; // 4MB
    if ( $file['size'] > $max_bytes ) {
        wp_send_json_error( array( 'message' => 'That image is too large, please use one under 4MB.' ), 400 );
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $user_id = get_current_user_id();

    $attachment_id = media_handle_upload( 'avatar', 0 );

    if ( is_wp_error( $attachment_id ) ) {
        wp_send_json_error( array( 'message' => 'Could not upload that image, please try again.' ), 500 );
    }

    $old_attachment_id = get_user_meta( $user_id, 'kounselia_avatar_id', true );
    if ( $old_attachment_id && $old_attachment_id != $attachment_id ) {
        wp_delete_attachment( $old_attachment_id, true );
    }

    update_user_meta( $user_id, 'kounselia_avatar_id', $attachment_id );

    wp_send_json_success( array(
        'avatar_url' => wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
    ) );
}
add_action( 'wp_ajax_kounselia_upload_avatar', 'kounselia_ajax_upload_avatar' );


/* -------------------------------------------------------------------------
 * 8. ACCOUNT: UPDATE PROFILE (display name)
 * ---------------------------------------------------------------------- */

function kounselia_ajax_update_profile() {
    kounselia_verify_nonce();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.' ), 401 );
    }

    $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

    if ( empty( $name ) ) {
        wp_send_json_error( array( 'message' => 'Please enter a name.' ), 400 );
    }

    $user_id = get_current_user_id();

    $result = wp_update_user( array(
        'ID'           => $user_id,
        'display_name' => $name,
        'first_name'   => $name,
    ) );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }

    wp_send_json_success( array( 'name' => $name ) );
}
add_action( 'wp_ajax_kounselia_update_profile', 'kounselia_ajax_update_profile' );


/* -------------------------------------------------------------------------
 * 9. ACCOUNT: CHANGE PASSWORD
 * ---------------------------------------------------------------------- */

function kounselia_ajax_update_password() {
    kounselia_verify_nonce();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.' ), 401 );
    }

    if ( kounselia_rate_limited( 'password_change', 5, 600 ) ) {
        wp_send_json_error( array( 'message' => 'Too many attempts, please wait a few minutes and try again.' ), 429 );
    }

    $current  = isset( $_POST['current_password'] ) ? wp_unslash( (string) $_POST['current_password'] ) : '';
    $new_pass = isset( $_POST['new_password'] ) ? wp_unslash( (string) $_POST['new_password'] ) : '';

    if ( empty( $current ) || empty( $new_pass ) ) {
        wp_send_json_error( array( 'message' => 'Please fill in both password fields.' ), 400 );
    }

    if ( strlen( $new_pass ) < 8 ) {
        wp_send_json_error( array( 'message' => 'New password must be at least 8 characters.' ), 400 );
    }

    $user = wp_get_current_user();

    if ( ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
        wp_send_json_error( array( 'message' => 'Your current password is incorrect.' ), 401 );
    }

    wp_set_password( $new_pass, $user->ID );
    wp_set_current_user( $user->ID );
    wp_set_auth_cookie( $user->ID, true, is_ssl() );

    wp_send_json_success( array( 'message' => 'Password updated.' ) );
}
add_action( 'wp_ajax_kounselia_update_password', 'kounselia_ajax_update_password' );



<?php
/**
 * Kounselia Core — resuming a previous conversation on load
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * 14. RESUMING A CONVERSATION
 * ---------------------------------------------------------------------- */

function kounselia_find_active_session_id( $counselor_slug, $user_id, $guest_token ) {
    global $wpdb;
    $table = $wpdb->prefix . 'kounselia_sessions';

    if ( $user_id ) {
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE counselor_slug = %s AND user_id = %d AND status = 'active' ORDER BY id DESC LIMIT 1",
            $counselor_slug, $user_id
        ) );
    } else {
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE counselor_slug = %s AND guest_token = %s AND status = 'active' ORDER BY id DESC LIMIT 1",
            $counselor_slug, $guest_token
        ) );
    }
    return (int) $id;
}

function kounselia_ajax_get_history() {
    kounselia_verify_nonce();

    $counselor_slug = isset( $_POST['counselor'] ) ? sanitize_key( wp_unslash( $_POST['counselor'] ) ) : '';
    if ( ! in_array( $counselor_slug, kounselia_counselor_slugs(), true ) ) {
        wp_send_json_error( array( 'message' => 'Unknown counselor.' ), 400 );
    }

    $user_id     = is_user_logged_in() ? get_current_user_id() : 0;
    $guest_token = $user_id ? '' : ( isset( $_POST['guest_token'] ) ? sanitize_text_field( wp_unslash( $_POST['guest_token'] ) ) : '' );

    $session_id = kounselia_find_active_session_id( $counselor_slug, $user_id, $guest_token );

    if ( ! $session_id ) {
        wp_send_json_success( array( 'session_id' => 0, 'messages' => array() ) );
    }

    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, sender, content FROM {$wpdb->prefix}kounselia_messages WHERE session_id = %d ORDER BY id ASC LIMIT 200",
        $session_id
    ) );

    $messages = array_map( function( $row ) {
        return array(
            'id'      => (int) $row->id,
            'sender'  => $row->sender,
            'content' => $row->content,
        );
    }, $rows );

    wp_send_json_success( array(
        'session_id' => $session_id,
        'messages'   => $messages,
    ) );
}
add_action( 'wp_ajax_kounselia_get_history', 'kounselia_ajax_get_history' );
add_action( 'wp_ajax_nopriv_kounselia_get_history', 'kounselia_ajax_get_history' );



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
        "SELECT id, sender, content, created_at FROM {$wpdb->prefix}kounselia_messages WHERE session_id = %d AND sender IN ('user','bot') ORDER BY id ASC LIMIT 200",
        $session_id
    ) );

    $ratings = function_exists( 'kounselia_get_message_ratings' ) ? kounselia_get_message_ratings( wp_list_pluck( $rows, 'id' ) ) : array();

    $messages = array_map( function( $row ) use ( $ratings ) {
        return array(
            'id'      => (int) $row->id,
            'sender'  => $row->sender,
            'content' => $row->content,
            'rating'  => isset( $ratings[ (int) $row->id ] ) ? $ratings[ (int) $row->id ] : null,
            // Stored in the site's local time; sent as UTC so the app can
            // show it in the member's own time zone.
            'sent_at' => get_gmt_from_date( $row->created_at, 'Y-m-d\\TH:i:s\\Z' ),
        );
    }, $rows );

    wp_send_json_success( array(
        'session_id' => $session_id,
        'messages'   => $messages,
    ) );
}
add_action( 'wp_ajax_kounselia_get_history', 'kounselia_ajax_get_history' );
add_action( 'wp_ajax_nopriv_kounselia_get_history', 'kounselia_ajax_get_history' );

/*
 * "Clear chat" hides the conversation from the member for good (it no
 * longer comes back on reload, via Load History, or in the dashboard's
 * recent sessions), but the rows are kept: safety escalations and the
 * staff transcript view point at these messages, and a crisis transcript
 * must never disappear just because someone tapped Clear.
 */
function kounselia_ajax_clear_chat() {
    kounselia_verify_nonce();

    $counselor_slug = isset( $_POST['counselor'] ) ? sanitize_key( wp_unslash( $_POST['counselor'] ) ) : '';
    if ( ! in_array( $counselor_slug, kounselia_counselor_slugs(), true ) ) {
        wp_send_json_error( array( 'message' => 'Unknown counselor.' ), 400 );
    }

    $user_id     = is_user_logged_in() ? get_current_user_id() : 0;
    $guest_token = $user_id ? '' : ( isset( $_POST['guest_token'] ) ? sanitize_text_field( wp_unslash( $_POST['guest_token'] ) ) : '' );

    if ( ! $user_id && '' === $guest_token ) {
        wp_send_json_error( array( 'message' => 'Missing guest identity, please refresh the page and try again.' ), 400 );
    }

    global $wpdb;
    $table = $wpdb->prefix . 'kounselia_sessions';
    $now   = current_time( 'mysql' );

    if ( $user_id ) {
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = 'cleared', ended_at = %s WHERE user_id = %d AND counselor_slug = %s AND status = 'active'",
            $now, $user_id, $counselor_slug
        ) );
    } else {
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = 'cleared', ended_at = %s WHERE guest_token = %s AND counselor_slug = %s AND status = 'active'",
            $now, $guest_token, $counselor_slug
        ) );
    }

    wp_send_json_success( array( 'cleared' => true ) );
}
add_action( 'wp_ajax_kounselia_clear_chat', 'kounselia_ajax_clear_chat' );
add_action( 'wp_ajax_nopriv_kounselia_clear_chat', 'kounselia_ajax_clear_chat' );



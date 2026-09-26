<?php
/**
 * Kounselia Core — thumbs up/down on counselor replies.
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function kounselia_ajax_rate_message() {
    kounselia_verify_nonce();

    if ( kounselia_rate_limited( 'rate_message', 60, 60 ) ) {
        wp_send_json_error( array( 'message' => 'Please slow down a little.' ), 429 );
    }

    $message_id = isset( $_POST['message_id'] ) ? absint( $_POST['message_id'] ) : 0;
    $rating     = isset( $_POST['rating'] ) ? sanitize_key( wp_unslash( $_POST['rating'] ) ) : '';

    if ( ! $message_id || ! in_array( $rating, array( 'up', 'down' ), true ) ) {
        wp_send_json_error( array( 'message' => 'Missing feedback.' ), 400 );
    }

    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT m.session_id, s.counselor_slug, s.user_id, s.guest_token
         FROM {$wpdb->prefix}kounselia_messages m
         INNER JOIN {$wpdb->prefix}kounselia_sessions s ON s.id = m.session_id
         WHERE m.id = %d AND m.sender = 'bot' LIMIT 1",
        $message_id
    ) );

    if ( ! $row ) {
        wp_send_json_error( array( 'message' => 'That message could not be found.' ), 404 );
    }

    $user_id     = is_user_logged_in() ? get_current_user_id() : 0;
    $guest_token = '';
    if ( $row->user_id ) {
        if ( (int) $row->user_id !== $user_id ) {
            wp_send_json_error( array( 'message' => 'Not authorized.' ), 403 );
        }
    } else {
        $guest_token = isset( $_POST['guest_token'] ) ? sanitize_text_field( wp_unslash( $_POST['guest_token'] ) ) : '';
        if ( '' === $guest_token || $guest_token !== $row->guest_token ) {
            wp_send_json_error( array( 'message' => 'Not authorized.' ), 403 );
        }
    }

    $table = $wpdb->prefix . 'kounselia_message_feedback';
    $now   = current_time( 'mysql' );

    $existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE message_id = %d", $message_id ) );

    if ( $existing_id ) {
        $ok = $wpdb->update( $table, array( 'rating' => $rating, 'updated_at' => $now ), array( 'id' => $existing_id ) );
    } else {
        $ok = $wpdb->insert( $table, array(
            'message_id'     => $message_id,
            'session_id'     => (int) $row->session_id,
            'counselor_slug' => $row->counselor_slug,
            'user_id'        => $row->user_id ? (int) $row->user_id : null,
            'guest_token'    => $row->user_id ? null : $guest_token,
            'rating'         => $rating,
            'created_at'     => $now,
            'updated_at'     => $now,
        ) );
    }

    if ( false === $ok ) {
        wp_send_json_error( array( 'message' => 'Could not save your feedback, please try again.' ), 500 );
    }

    wp_send_json_success( array( 'rating' => $rating ) );
}
add_action( 'wp_ajax_kounselia_rate_message', 'kounselia_ajax_rate_message' );
add_action( 'wp_ajax_nopriv_kounselia_rate_message', 'kounselia_ajax_rate_message' );

/**
 * Ratings for a set of message ids, keyed by message id — used to show
 * saved thumbs when history reloads, and in the staff transcript view.
 */
function kounselia_get_message_ratings( array $message_ids ) {
    $message_ids = array_values( array_filter( array_map( 'absint', $message_ids ) ) );
    if ( empty( $message_ids ) ) {
        return array();
    }

    global $wpdb;
    $placeholders = implode( ',', array_fill( 0, count( $message_ids ), '%d' ) );
    $rows         = $wpdb->get_results( $wpdb->prepare(
        "SELECT message_id, rating FROM {$wpdb->prefix}kounselia_message_feedback WHERE message_id IN ({$placeholders})",
        $message_ids
    ) );

    $ratings = array();
    foreach ( $rows as $r ) {
        $ratings[ (int) $r->message_id ] = $r->rating;
    }
    return $ratings;
}

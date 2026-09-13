<?php
/**
 * Kounselia Core — private journal entries
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * 16. PRIVATE JOURNAL
 * ---------------------------------------------------------------------- */

function kounselia_get_today_journal( $user_id ) {
    global $wpdb;
    return $wpdb->get_var( $wpdb->prepare(
        "SELECT content FROM {$wpdb->prefix}kounselia_journal_entries WHERE user_id = %d AND entry_date = %s",
        $user_id, current_time( 'Y-m-d' )
    ) );
}

function kounselia_ajax_save_journal() {
    kounselia_verify_nonce();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.' ), 401 );
    }

    $content = isset( $_POST['content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['content'] ) ) : '';
    $content = mb_substr( $content, 0, 5000 );

    global $wpdb;
    $user_id = get_current_user_id();
    $today   = current_time( 'Y-m-d' );
    $table   = $wpdb->prefix . 'kounselia_journal_entries';

    $existing_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$table} WHERE user_id = %d AND entry_date = %s", $user_id, $today
    ) );

    if ( $existing_id ) {
        $wpdb->update( $table, array( 'content' => $content, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $existing_id ) );
    } else {
        $wpdb->insert( $table, array(
            'user_id'    => $user_id,
            'content'    => $content,
            'entry_date' => $today,
            'updated_at' => current_time( 'mysql' ),
        ) );
    }

    wp_send_json_success();
}
add_action( 'wp_ajax_kounselia_save_journal', 'kounselia_ajax_save_journal' );



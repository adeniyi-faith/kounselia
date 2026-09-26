<?php
/**
 * Kounselia Core — read-only helpers used by dashboard.php (stats, recent sessions, avatar URL)
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * 6. DASHBOARD HELPERS 
 * ---------------------------------------------------------------------- */

function kounselia_get_avatar_url( $user_id, $size = 'thumbnail' ) {
    $attachment_id = get_user_meta( $user_id, 'kounselia_avatar_id', true );
    if ( ! $attachment_id ) {
        return false;
    }
    $url = wp_get_attachment_image_url( $attachment_id, $size );
    return $url ? $url : false;
}

/**
 * The first word of a display name, for a "Welcome back, X" greeting —
 * skipping a leading title (Dr., Mr., Mrs., Ms., Prof.) so "Dr. Amara
 * Nwosu" greets "Amara", not "Dr.".
 */
function kounselia_greeting_first_name( $display_name ) {
    $words = preg_split( '/\s+/', trim( $display_name ) );
    $titles = array( 'dr', 'dr.', 'mr', 'mr.', 'mrs', 'mrs.', 'ms', 'ms.', 'prof', 'prof.' );
    if ( count( $words ) > 1 && in_array( strtolower( $words[0] ), $titles, true ) ) {
        return $words[1];
    }
    return $words[0];
}

function kounselia_get_dashboard_stats( $user_id ) {
    global $wpdb;
    $sessions_table = $wpdb->prefix . 'kounselia_sessions';
    $messages_table = $wpdb->prefix . 'kounselia_messages';

    $total_sessions = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$sessions_table} WHERE user_id = %d", $user_id
    ) );

    $week_start = gmdate( 'Y-m-d H:i:s', strtotime( 'monday this week' ) );
    $messages_this_week = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$messages_table} m
         INNER JOIN {$sessions_table} s ON s.id = m.session_id
         WHERE s.user_id = %d AND m.sender = 'user' AND m.created_at >= %s",
        $user_id, $week_start
    ) );

    $counselors_met = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(DISTINCT counselor_slug) FROM {$sessions_table} WHERE user_id = %d", $user_id
    ) );

    $user = get_userdata( $user_id );
    $member_since = $user ? $user->user_registered : current_time( 'mysql' );

    return array(
        'total_sessions'     => $total_sessions,
        'messages_this_week' => $messages_this_week,
        'counselors_met'     => $counselors_met,
        'member_since'       => $member_since,
    );
}

function kounselia_get_recent_sessions( $user_id, $limit = 5 ) {
    global $wpdb;
    $sessions_table = $wpdb->prefix . 'kounselia_sessions';
    $messages_table = $wpdb->prefix . 'kounselia_messages';

    $sql = $wpdb->prepare(
        "SELECT s.id, s.counselor_slug, s.started_at, s.ended_at, s.status,
                COUNT(m.id) AS message_count,
                MAX(m.created_at) AS last_message_at
         FROM {$sessions_table} s
         LEFT JOIN {$messages_table} m ON m.session_id = s.id
         WHERE s.user_id = %d AND s.status != 'cleared'
         GROUP BY s.id
         ORDER BY last_message_at DESC, s.started_at DESC
         LIMIT %d",
        $user_id, $limit
    );

    return $wpdb->get_results( $sql );
}



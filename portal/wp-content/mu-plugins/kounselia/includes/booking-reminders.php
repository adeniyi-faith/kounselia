<?php
/**
 * Kounselia Core — "your session starts soon" reminders.
 *
 * A booking used to generate exactly one notification: the confirmation
 * email sent the moment it was paid for. Nothing nudged either side as
 * the actual time approached. This runs a WP-Cron job every 15 minutes
 * that finds bookings starting in about an hour and hasn't reminded
 * about yet, and notifies both the client and the professional through
 * kounselia_notify_user() (email today; in-app and, once a mobile app
 * exists, push).
 *
 * WordPress's own cron only fires on a page load (there's no daemon
 * running in the background), so on a very quiet site a reminder could
 * land a few minutes later than the target hour-before mark — the
 * 20-minute matching window below is wide enough to absorb that without
 * ever double-sending (reminder_sent_at is set the moment one goes out).
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_filter( 'cron_schedules', function( $schedules ) {
    if ( ! isset( $schedules['kounselia_fifteen_minutes'] ) ) {
        $schedules['kounselia_fifteen_minutes'] = array(
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => 'Every 15 minutes (Kounselia)',
        );
    }
    return $schedules;
} );

add_action( 'init', function() {
    if ( ! wp_next_scheduled( 'kounselia_send_booking_reminders' ) ) {
        wp_schedule_event( time(), 'kounselia_fifteen_minutes', 'kounselia_send_booking_reminders' );
    }
} );

/**
 * How long before a session a reminder goes out, and how wide the
 * matching window is around that mark (see file header for why).
 */
function kounselia_reminder_lead_seconds() {
    return HOUR_IN_SECONDS;
}
function kounselia_reminder_window_seconds() {
    return 10 * MINUTE_IN_SECONDS;
}

function kounselia_send_booking_reminders() {
    global $wpdb;

    $now         = current_time( 'timestamp' );
    $lead        = kounselia_reminder_lead_seconds();
    $window      = kounselia_reminder_window_seconds();
    $window_start = date( 'Y-m-d H:i:s', $now + $lead - $window );
    $window_end   = date( 'Y-m-d H:i:s', $now + $lead + $window );

    $bookings = $wpdb->get_results( $wpdb->prepare(
        "SELECT b.*, p.user_id AS professional_user_id
         FROM {$wpdb->prefix}kounselia_bookings b
         INNER JOIN {$wpdb->prefix}kounselia_professionals p ON p.id = b.professional_id
         WHERE b.status = 'confirmed' AND b.reminder_sent_at IS NULL
         AND b.scheduled_start BETWEEN %s AND %s",
        $window_start,
        $window_end
    ) );

    foreach ( $bookings as $booking ) {
        kounselia_send_single_booking_reminder( $booking );

        $wpdb->update( $wpdb->prefix . 'kounselia_bookings', array(
            'reminder_sent_at' => current_time( 'mysql' ),
        ), array( 'id' => $booking->id ) );
    }
}
add_action( 'kounselia_send_booking_reminders', 'kounselia_send_booking_reminders' );

function kounselia_send_single_booking_reminder( $booking ) {
    if ( ! function_exists( 'kounselia_notify_user' ) ) {
        return;
    }

    $client_user       = get_userdata( $booking->client_user_id );
    $professional_user = get_userdata( $booking->professional_user_id );
    $when              = date_i18n( 'g:i A', strtotime( $booking->scheduled_start ) );
    $join_url          = rtrim( home_url(), '/' ) . '/video-call.php?booking_id=' . (int) $booking->id;

    if ( $client_user ) {
        kounselia_notify_user(
            $booking->client_user_id,
            'booking_reminder',
            'Your session starts in about an hour',
            'At ' . $when . ( $professional_user ? ' with ' . $professional_user->display_name : '' ) . '.',
            '/dashboard.php#professionals',
            array(
                'subject'      => 'Your session starts in about an hour',
                'headline'     => 'Coming up',
                'content_html' => '<p>Your session' . ( $professional_user ? ' with <strong>' . esc_html( $professional_user->display_name ) . '</strong>' : '' ) . ' starts at <strong>' . esc_html( $when ) . '</strong>. The video room opens 10 minutes before.</p>',
                'btn_text'     => 'Open session page',
                'btn_url'      => $join_url,
            )
        );
    }

    if ( $professional_user ) {
        kounselia_notify_user(
            $booking->professional_user_id,
            'booking_reminder',
            'Your session starts in about an hour',
            'At ' . $when . ( $client_user ? ' with ' . $client_user->display_name : '' ) . '.',
            '/pro-dashboard.php#bookings',
            array(
                'subject'      => 'Your session starts in about an hour',
                'headline'     => 'Coming up',
                'content_html' => '<p>Your session' . ( $client_user ? ' with <strong>' . esc_html( $client_user->display_name ) . '</strong>' : '' ) . ' starts at <strong>' . esc_html( $when ) . '</strong>. The video room opens 10 minutes before.</p>',
                'btn_text'     => 'Open session page',
                'btn_url'      => $join_url,
            )
        );
    }
}

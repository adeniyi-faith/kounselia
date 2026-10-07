<?php
/**
 * Kounselia Core — "your session starts soon" reminders.
 *
 * A booking used to generate exactly one notification: the confirmation
 * email sent the moment it was paid for. Nothing nudged either side as
 * the actual time approached. This runs a WP-Cron job every 15 minutes
 * that finds bookings starting in about an hour and hasn't reminded
 * about yet, and notifies both the client and the professional through
 * kounselia_notify_user() (email, in-app, and a push notification on
 * the mobile app).
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
    $start_ts          = strtotime( $booking->scheduled_start );
    $join_url          = rtrim( home_url(), '/' ) . '/video-call.php?booking_id=' . (int) $booking->id;

    // Each person is written to in their own language, with their own side's wording.
    $sides = array(
        array( $client_user, $booking->client_user_id, $professional_user, '/dashboard.php#professionals' ),
        array( $professional_user, $booking->professional_user_id, $client_user, '/pro-dashboard.php#bookings' ),
    );
    foreach ( $sides as $side ) {
        list( $me, $me_id, $other, $path ) = $side;
        if ( ! $me ) {
            continue;
        }
        $lang  = kounselia_mail_lang( $me );
        $when  = kounselia_mail_time( $start_ts, $lang );
        $title = kounselia_t( 'mail.reminder.title', array(), $lang );
        if ( $other ) {
            $body    = kounselia_t( 'mail.reminder.body_with', array( 'time' => $when, 'name' => $other->display_name ), $lang );
            $content = kounselia_t( 'mail.reminder.content_with', array( 'time' => esc_html( $when ), 'name' => esc_html( $other->display_name ) ), $lang );
        } else {
            $body    = kounselia_t( 'mail.reminder.body', array( 'time' => $when ), $lang );
            $content = kounselia_t( 'mail.reminder.content', array( 'time' => esc_html( $when ) ), $lang );
        }
        kounselia_notify_user(
            $me_id,
            'booking_reminder',
            $title,
            $body,
            $path,
            array(
                'subject'      => $title,
                'headline'     => kounselia_t( 'mail.reminder.headline', array(), $lang ),
                'content_html' => '<p>' . $content . '</p>',
                'btn_text'     => kounselia_t( 'mail.reminder.button', array(), $lang ),
                'btn_url'      => $join_url,
            )
        );
    }
}

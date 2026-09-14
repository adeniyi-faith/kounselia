<?php
/**
 * Kounselia Core — safety escalation
 *
 * Before this file, a message matching a safety keyword just got a flag
 * on its row (flagged_safety = 1) and waited for a staff member to
 * happen to open the admin Safety page. There was no urgency tiering, no
 * alert, and no record of whether anyone had actually looked at it.
 *
 * This file adds a real escalation path on top of that same keyword
 * match (see kounselia_message_matches_safety_keywords() in
 * chat-helpers.php, which still decides *whether* a message is flagged):
 *   1. Classify how acute the matched phrase is (kounselia_safety_critical_markers).
 *   2. Record an open "case" for it in kounselia_safety_escalations.
 *   3. For an acute match, email every admin/staff member immediately,
 *      with a direct link to the transcript — once per session within a
 *      cooldown window, so one distressed conversation doesn't flood
 *      inboxes with a separate email per message.
 *   4. Let a staff member acknowledge a case from the admin Safety page,
 *      so there's a visible open/handled state instead of a flag that
 *      never changes.
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Phrases that describe an acute, active risk to the person's life or
 * physical safety, as opposed to distress language in general. A match
 * against one of these fires an immediate staff alert; anything else a
 * site admin has added to the watch list still gets flagged and queued
 * for review, just without paging anyone.
 *
 * This list is intentionally separate from the admin-editable keyword
 * list (kounselia_get_safety_keywords) rather than a "severity" field
 * bolted onto it: the editable list is meant for admins to freely add
 * words worth a look, and treating everything they add as page-worthy
 * would make the alert meaningless within days.
 */
function kounselia_safety_critical_markers() {
    return array(
        'suicide',
        'suicidal',
        'kill myself',
        'killing myself',
        'end my life',
        'ending my life',
        'take my own life',
        'want to die',
        'wish i was dead',
        'wish i were dead',
        'better off dead',
        'not worth living',
        'no reason to live',
        'no reason to go on',
        "can't go on",
        'cant go on',
        'overdose',
        'self harm',
        'self-harm',
        'hurting myself',
        'hurt myself',
        'cutting myself',
    );
}

function kounselia_classify_safety_severity( $matched_keyword ) {
    $matched_keyword = strtolower( trim( (string) $matched_keyword ) );
    return in_array( $matched_keyword, kounselia_safety_critical_markers(), true ) ? 'critical' : 'elevated';
}

/**
 * How long to stay quiet about further critical matches in the same
 * session once staff has already been paged for it, so a single acute
 * conversation triggers one alert, not one per message.
 */
define( 'KOUNSELIA_SAFETY_NOTIFY_COOLDOWN', 30 * MINUTE_IN_SECONDS );

/**
 * Record an escalation case for a flagged message, and page staff
 * immediately if it's critical and not within the per-session cooldown.
 * Called right after kounselia_log_message() stores the flagged message.
 */
function kounselia_record_safety_escalation( $message_id, $session_id, $flag_reason ) {
    global $wpdb;

    $session = $wpdb->get_row( $wpdb->prepare(
        "SELECT user_id, guest_token FROM {$wpdb->prefix}kounselia_sessions WHERE id = %d",
        $session_id
    ) );

    $severity = kounselia_classify_safety_severity( $flag_reason );
    $now      = current_time( 'mysql' );

    $wpdb->insert( $wpdb->prefix . 'kounselia_safety_escalations', array(
        'message_id'  => $message_id,
        'session_id'  => $session_id,
        'user_id'     => $session && $session->user_id ? $session->user_id : null,
        'guest_token' => $session && $session->guest_token ? $session->guest_token : null,
        'severity'    => $severity,
        'flag_reason' => $flag_reason,
        'status'      => 'open',
        'created_at'  => $now,
    ) );
    $escalation_id = (int) $wpdb->insert_id;

    if ( 'critical' === $severity && ! kounselia_safety_session_recently_notified( $session_id ) ) {
        kounselia_notify_safety_escalation( $escalation_id );
    }

    return $escalation_id;
}

/**
 * Whether staff has already been paged for a critical match in this
 * session within the cooldown window.
 */
function kounselia_safety_session_recently_notified( $session_id ) {
    global $wpdb;

    $cutoff = gmdate( 'Y-m-d H:i:s', time() - KOUNSELIA_SAFETY_NOTIFY_COOLDOWN );

    $recent = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}kounselia_safety_escalations
         WHERE session_id = %d AND severity = 'critical' AND notified_at IS NOT NULL AND notified_at >= %s
         LIMIT 1",
        $session_id, $cutoff
    ) );

    return ! empty( $recent );
}

/**
 * Who gets paged for a critical safety escalation. Defaults to every
 * admin/staff account; an operator can narrow this to a specific
 * on-call list from Settings (kounselia_safety_alert_emails) once the
 * team is big enough that not everyone needs to be woken up for every
 * case.
 */
function kounselia_safety_alert_recipients() {
    $custom = get_option( 'kounselia_safety_alert_emails', '' );
    if ( ! empty( $custom ) ) {
        $emails = array_filter( array_map( 'trim', explode( ',', $custom ) ), 'is_email' );
        if ( ! empty( $emails ) ) {
            return array_values( array_unique( $emails ) );
        }
    }

    $recipients = get_users( array(
        'role__in' => array( 'administrator', 'kounselia_staff' ),
        'fields'   => array( 'user_email' ),
    ) );
    return array_values( array_filter( array_unique( wp_list_pluck( $recipients, 'user_email' ) ) ) );
}

/**
 * Email the configured recipients with a direct link to the transcript,
 * and mark the case as notified.
 */
function kounselia_notify_safety_escalation( $escalation_id ) {
    global $wpdb;

    $escalation = $wpdb->get_row( $wpdb->prepare(
        "SELECT e.*, m.content AS message_content, s.counselor_slug
         FROM {$wpdb->prefix}kounselia_safety_escalations e
         INNER JOIN {$wpdb->prefix}kounselia_messages m ON m.id = e.message_id
         INNER JOIN {$wpdb->prefix}kounselia_sessions s ON s.id = e.session_id
         WHERE e.id = %d",
        $escalation_id
    ) );

    if ( ! $escalation ) {
        return false;
    }

    $who = 'a guest';
    if ( $escalation->user_id ) {
        $user = get_userdata( $escalation->user_id );
        $who  = $user ? ( $user->display_name ?: $user->user_email ) : ( 'member #' . $escalation->user_id );
    }

    $emails = kounselia_safety_alert_recipients();

    if ( empty( $emails ) ) {
        return false;
    }

    $site_url    = function_exists( 'home_url' ) ? home_url() : ( 'https://' . $_SERVER['SERVER_NAME'] );
    $transcript_url = rtrim( $site_url, '/' ) . '/portal/admin/pages/session.php?id=' . (int) $escalation->session_id;
    $snippet     = wp_trim_words( $escalation->message_content, 40, '…' );
    $counselor   = function_exists( 'kounselia_admin_counselor_name' ) ? kounselia_admin_counselor_name( $escalation->counselor_slug ) : $escalation->counselor_slug;

    $content = '<p>A message from <strong>' . esc_html( $who ) . '</strong>, talking with <strong>' . esc_html( $counselor ) . '</strong>, matched language associated with an acute safety risk ("' . esc_html( $escalation->flag_reason ) . '").</p>'
        . '<p style="background:#F8F6F2;border-radius:12px;padding:16px 18px;font-style:italic;">' . esc_html( $snippet ) . '</p>'
        . '<p>This needs a look now, not at the next routine check of the Safety page.</p>';

    foreach ( $emails as $email ) {
        kounselia_send_html_email(
            $email,
            'Safety alert — immediate review needed',
            'Immediate review needed',
            $content,
            'View the conversation',
            $transcript_url
        );
    }

    $wpdb->update(
        $wpdb->prefix . 'kounselia_safety_escalations',
        array( 'notified_at' => current_time( 'mysql' ) ),
        array( 'id' => $escalation_id )
    );

    return true;
}

/**
 * Mark an escalation case acknowledged by the current staff member.
 * Handles the POST from the admin Safety page (same POST-redirect-GET
 * pattern the keyword add/remove form on that page already uses).
 */
function kounselia_handle_safety_acknowledge_post() {
    if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || ! isset( $_POST['kounselia_safety_action'] ) || 'acknowledge' !== $_POST['kounselia_safety_action'] ) {
        return;
    }

    if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'kounselia_safety_keywords' ) ) {
        wp_die( 'Security check failed, please go back and try again.' );
    }

    if ( ! kounselia_user_is_admin() ) {
        wp_die( 'Unauthorized.' );
    }

    $escalation_id = isset( $_POST['escalation_id'] ) ? absint( $_POST['escalation_id'] ) : 0;
    if ( ! $escalation_id ) {
        return;
    }

    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'kounselia_safety_escalations',
        array(
            'status'          => 'acknowledged',
            'acknowledged_by' => get_current_user_id(),
            'acknowledged_at' => current_time( 'mysql' ),
        ),
        array( 'id' => $escalation_id )
    );

    kounselia_admin_log( 'acknowledge_safety_escalation', 'safety_escalation', $escalation_id );

    wp_safe_redirect( '/portal/admin/pages/safety-flags.php' );
    exit;
}

<?php
/**
 * Kounselia Core — Smart Check-ins
 *
 * The idea: instead of a counselor always opening with a generic "how
 * are you?", have them open with "you mentioned your presentation was
 * today — how did it go?" when the memory engine already knows a dated
 * event happened recently.
 *
 * This rides entirely on the memory synthesizer that already runs after
 * every chat (see memory.php, kounselia_ajax_synthesize_memory): its
 * prompt now also asks for any specific upcoming event the user
 * mentioned, with a real date. Those go in their own table (see
 * schema.php, kounselia_memory_upcoming_events) rather than inside the
 * profile JSON, because unlike the rest of the profile, an event needs
 * a status (pending / checked_in / dismissed / expired) that survives
 * across synthesis runs instead of being wiped and rebuilt each time.
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Store any newly-extracted dated events from a synthesis pass.
 * Idempotent — an event already on record (same user/date/text) is left
 * untouched so a re-synthesis never resets its status back to pending.
 *
 * @param array $events Each item: array( 'event' => string, 'date' => 'YYYY-MM-DD' ).
 */
function kounselia_record_upcoming_events( $user_id, $events ) {
    if ( empty( $events ) || ! is_array( $events ) ) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'kounselia_memory_upcoming_events';

    // Only worth tracking within a reasonable window: a couple of days
    // in the past (still worth asking "how did it go?") through three
    // months out (further than that isn't a "check in soon" candidate).
    $earliest = gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS );
    $latest   = gmdate( 'Y-m-d', time() + 90 * DAY_IN_SECONDS );

    foreach ( $events as $item ) {
        if ( ! is_array( $item ) || empty( $item['event'] ) || empty( $item['date'] ) ) {
            continue;
        }

        $event_text = sanitize_text_field( (string) $item['event'] );
        $event_date = sanitize_text_field( (string) $item['date'] );

        // Must be a real, plausible calendar date in our tracking window.
        $timestamp = strtotime( $event_date );
        if ( false === $timestamp || '' === $event_text ) {
            continue;
        }
        $event_date = gmdate( 'Y-m-d', $timestamp );
        if ( $event_date < $earliest || $event_date > $latest ) {
            continue;
        }

        $event_text = mb_substr( $event_text, 0, 191 );

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id = %d AND event_date = %s AND event_text = %s",
            $user_id, $event_date, $event_text
        ) );

        if ( $existing ) {
            continue; // Already tracked — don't disturb its status.
        }

        $wpdb->insert( $table, array(
            'user_id'    => $user_id,
            'event_text' => $event_text,
            'event_date' => $event_date,
            'status'     => 'pending',
            'created_at' => current_time( 'mysql' ),
        ) );
    }
}

/**
 * The most relevant pending check-in for a user right now: an event
 * dated yesterday or today (so "how did it go?" makes sense), most
 * recent first. Only ever surfaces one at a time — the point is a
 * single natural opening line, not a backlog to work through.
 */
function kounselia_get_next_checkin( $user_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'kounselia_memory_upcoming_events';

    kounselia_expire_stale_checkins( $user_id );

    $today     = current_time( 'Y-m-d' );
    $yesterday = gmdate( 'Y-m-d', strtotime( $today ) - DAY_IN_SECONDS );

    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table}
         WHERE user_id = %d AND status = 'pending' AND event_date BETWEEN %s AND %s
         ORDER BY event_date DESC LIMIT 1",
        $user_id, $yesterday, $today
    ) );
}

/**
 * Housekeeping: a pending event more than a couple of days past its
 * date is no longer a natural "how did it go?" moment — mark it expired
 * so it stops being considered, rather than resurfacing something
 * weeks-stale.
 */
function kounselia_expire_stale_checkins( $user_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'kounselia_memory_upcoming_events';
    $cutoff = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) ) - 2 * DAY_IN_SECONDS );

    $wpdb->query( $wpdb->prepare(
        "UPDATE {$table} SET status = 'expired' WHERE user_id = %d AND status = 'pending' AND event_date < %s",
        $user_id, $cutoff
    ) );
}

/**
 * Turn a stored event into the actual opening line a counselor uses.
 */
function kounselia_build_checkin_question( $event_row, $first_name ) {
    $lead  = $first_name ? "Hi {$first_name} — before we start, " : 'Before we start, ';
    $event = trim( (string) $event_row->event_text );
    return $lead . "you mentioned " . lcfirst( rtrim( $event, '.' ) ) . ". How did it go?";
}

/**
 * AJAX: fetch (and mark checked-in) the question text for a specific
 * event, called from talk.php right before it opens the chat, so the
 * counselor's greeting can be this instead of the generic one.
 */
function kounselia_ajax_get_checkin() {
    kounselia_verify_nonce();
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ), 401 );
    }

    $user_id     = get_current_user_id();
    $checkin_id  = isset( $_POST['checkin_id'] ) ? absint( $_POST['checkin_id'] ) : 0;
    if ( ! $checkin_id ) {
        wp_send_json_error( array( 'message' => 'Missing check-in id' ), 400 );
    }

    global $wpdb;
    $table = $wpdb->prefix . 'kounselia_memory_upcoming_events';

    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE id = %d AND user_id = %d AND status = 'pending'",
        $checkin_id, $user_id
    ) );

    if ( ! $row ) {
        wp_send_json_error( array( 'message' => 'Check-in no longer available' ), 404 );
    }

    $wpdb->update( $table, array(
        'status'        => 'checked_in',
        'checked_in_at' => current_time( 'mysql' ),
    ), array( 'id' => $checkin_id ) );

    $user       = get_userdata( $user_id );
    $first_name = $user ? trim( explode( ' ', $user->display_name ?: $user->user_login )[0] ) : '';

    wp_send_json_success( array(
        'question' => kounselia_build_checkin_question( $row, $first_name ),
    ) );
}
add_action( 'wp_ajax_kounselia_get_checkin', 'kounselia_ajax_get_checkin' );

/**
 * AJAX: dismiss a check-in from the dashboard without opening a chat.
 */
function kounselia_ajax_dismiss_checkin() {
    kounselia_verify_nonce();
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ), 401 );
    }

    $user_id    = get_current_user_id();
    $checkin_id = isset( $_POST['checkin_id'] ) ? absint( $_POST['checkin_id'] ) : 0;
    if ( ! $checkin_id ) {
        wp_send_json_error( array( 'message' => 'Missing check-in id' ), 400 );
    }

    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'kounselia_memory_upcoming_events',
        array( 'status' => 'dismissed' ),
        array( 'id' => $checkin_id, 'user_id' => $user_id )
    );

    wp_send_json_success();
}
add_action( 'wp_ajax_kounselia_dismiss_checkin', 'kounselia_ajax_dismiss_checkin' );

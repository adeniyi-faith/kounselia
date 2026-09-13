<?php
/**
 * Kounselia Admin — shared helper functions.
 *
 * Required by any admin page that needs to display a counselor's name
 * or format a member/guest label consistently.
 */

/**
 * Counselor slug -> display name. Kept here because the database only
 * stores the slug (in kounselia_sessions.counselor_slug), the display
 * name only exists client-side in the `C` object inside
 * inc/kounselia-chat-engine.php. Keep this list in sync with that one
 * if a counselor is ever renamed or a new one added.
 */
function kounselia_admin_counselor_name( $slug ) {
    $names = array(
        'serena'  => 'Serena',
        'marcus'  => 'Marcus',
        'noa'     => 'Noa',
        'eli'     => 'Eli',
        'dr_lena' => 'Dr. Lena',
        'james'   => 'James',
        'theo'    => 'Theo',
        'priya'   => 'Priya',
    );
    return isset( $names[ $slug ] ) ? $names[ $slug ] : ucfirst( str_replace( '_', ' ', $slug ) );
}

/**
 * Counselor slug -> a consistent background color + initial, so each
 * counselor reads as the same "person" everywhere in the admin panel.
 * Colors are pulled from the shared palette in inc/admin-styles.php.
 */
function kounselia_admin_counselor_avatar( $slug ) {
    $palette = array(
        'serena'  => '#8B3A52', // rose
        'marcus'  => '#1E3A5F', // navy
        'noa'     => '#2E5C3E', // sage
        'eli'     => '#1E5C5C', // teal
        'dr_lena' => '#4A3070', // plum
        'james'   => '#7A3D1E', // sienna
        'theo'    => '#B07D3A', // gold
        'priya'   => '#5B574D', // neutral
    );
    $color  = isset( $palette[ $slug ] ) ? $palette[ $slug ] : '#5B574D';
    $name   = kounselia_admin_counselor_name( $slug );
    $letter = strtoupper( substr( $name, 0, 1 ) );
    return array( 'color' => $color, 'letter' => $letter );
}

/**
 * Given a session row (object with ->user_id and ->guest_token), return
 * a safe, human-readable label for who was in that conversation.
 * Never assumes a display name exists, WordPress lets it be blank.
 */
function kounselia_admin_session_who( $session ) {
    if ( ! empty( $session->user_id ) ) {
        $user = get_userdata( $session->user_id );
        if ( $user ) {
            return $user->display_name ? $user->display_name : $user->user_email;
        }
        return 'Member #' . (int) $session->user_id . ' (deleted account)';
    }
    if ( ! empty( $session->guest_token ) ) {
        return 'Guest ' . substr( $session->guest_token, 0, 8 ) . '…';
    }
    return 'Unknown';
}

/**
 * Clock-only time label (e.g. "3:26pm"), used inside the transcript
 * viewer where a day divider already shows the date, so repeating the
 * full date next to every single message would just be noise.
 */
function kounselia_admin_clock_label( $mysql_datetime ) {
    if ( empty( $mysql_datetime ) ) {
        return '';
    }
    $timestamp = strtotime( $mysql_datetime . ' UTC' ) + ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
    return date_i18n( 'g:ia', $timestamp );
}

/**
 * Relative-ish, human friendly time label, falling back to a full date
 * for anything more than a week old so old rows don't say "3 weeks ago"
 * forever without ever showing an actual date.
 */
function kounselia_admin_time_label( $mysql_datetime ) {
    if ( empty( $mysql_datetime ) ) {
        return '—';
    }
    $timestamp = strtotime( $mysql_datetime . ' UTC' );
    $now       = current_time( 'timestamp', true );
    $diff      = $now - $timestamp;

    if ( $diff < 7 * DAY_IN_SECONDS ) {
        return human_time_diff( $timestamp, $now ) . ' ago';
    }
    return date_i18n( 'M j, Y g:ia', $timestamp + ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
}

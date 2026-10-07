<?php
/**
 * Kounselia Core — what an admin can do with a professional after they
 * have been approved (Admin → Professionals → Manage).
 *
 *   Edit      their public profile: name, title, specialty, experience,
 *             bio, rate, free sessions (kounselia_admin_professional_update)
 *   Hide      take them out of every list members see (the public
 *             directory, the dashboard and the app) without stopping
 *             sessions already booked; their profile page stops working
 *             too (admin_hidden)
 *   Suspend   status 'suspended': hidden everywhere AND no new bookings,
 *             no writing. Optionally cancels (and refunds) their upcoming
 *             sessions and takes their articles down. They are told why.
 *   Reinstate back to 'verified', everything as it was.
 *
 * Every change is written to the admin audit log.
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin edits a professional's profile. $data keys (all optional):
 * name, title, specialty, years_experience, bio, rate_amount,
 * free_sessions_per_client. Returns true or WP_Error.
 */
function kounselia_admin_professional_update( $professional_id, $data ) {
    global $wpdb;
    $pro = kounselia_get_professional_by_id( $professional_id );
    if ( ! $pro ) {
        return new WP_Error( 'not_found', 'That professional no longer exists.' );
    }

    $row = array();
    if ( isset( $data['title'] ) ) {
        $title = trim( sanitize_text_field( $data['title'] ) );
        if ( '' === $title ) {
            return new WP_Error( 'missing_title', 'A professional title is needed, e.g. "Clinical Psychologist".' );
        }
        if ( function_exists( 'kounselia_title_is_bare_honorific' ) && kounselia_title_is_bare_honorific( $title ) ) {
            return new WP_Error( 'bare_title', 'That title is just an honorific ("' . $title . '"). Use their role or qualification instead, e.g. "Licensed Clinical Psychologist".' );
        }
        $row['title'] = mb_substr( $title, 0, 191 );
    }
    if ( isset( $data['specialty'] ) ) {
        $row['specialty'] = mb_substr( trim( sanitize_text_field( $data['specialty'] ) ), 0, 191 ) ?: null;
    }
    if ( isset( $data['years_experience'] ) ) {
        $years = '' === trim( (string) $data['years_experience'] ) ? null : max( 0, min( 60, (int) $data['years_experience'] ) );
        $row['years_experience'] = $years;
    }
    if ( isset( $data['bio'] ) ) {
        $row['bio'] = trim( sanitize_textarea_field( $data['bio'] ) ) ?: null;
    }
    if ( isset( $data['rate_amount'] ) ) {
        $rate = '' === trim( (string) $data['rate_amount'] ) ? null : round( max( 0, (float) $data['rate_amount'] ), 2 );
        $row['rate_amount'] = $rate;
    }
    if ( isset( $data['free_sessions_per_client'] ) ) {
        $row['free_sessions_per_client'] = kounselia_free_sessions_clean( $data['free_sessions_per_client'] );
    }

    if ( $row ) {
        $row['updated_at'] = current_time( 'mysql' );
        $wpdb->update( $wpdb->prefix . 'kounselia_professionals', $row, array( 'id' => $pro->id ) );
    }
    if ( isset( $data['name'] ) ) {
        $name = trim( sanitize_text_field( $data['name'] ) );
        if ( '' !== $name ) {
            wp_update_user( array( 'ID' => (int) $pro->user_id, 'display_name' => mb_substr( $name, 0, 100 ) ) );
        }
    }
    kounselia_admin_log( 'edited_professional', 'professional', (int) $pro->id );
    return true;
}

/** Hides (or shows again) a professional everywhere members look for one. */
function kounselia_admin_professional_set_hidden( $professional_id, $hidden ) {
    global $wpdb;
    $pro = kounselia_get_professional_by_id( $professional_id );
    if ( ! $pro ) {
        return new WP_Error( 'not_found', 'That professional no longer exists.' );
    }
    $wpdb->update( $wpdb->prefix . 'kounselia_professionals', array( 'admin_hidden' => $hidden ? 1 : 0, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $pro->id ) );
    kounselia_admin_log( $hidden ? 'hid_professional' : 'unhid_professional', 'professional', (int) $pro->id );
    return true;
}

/** Upcoming confirmed sessions for a professional. */
function kounselia_admin_professional_upcoming_ids( $professional_id ) {
    global $wpdb;
    return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}kounselia_bookings WHERE professional_id = %d AND status = 'confirmed' AND scheduled_start > %s ORDER BY scheduled_start ASC",
        $professional_id, current_time( 'mysql' )
    ) ) );
}

/**
 * Suspends a verified professional.
 *
 * @param string $reason Told to the professional in their email and dashboard.
 * @param array  $opts   cancel_upcoming (bool): cancel and refund their upcoming
 *                       sessions (each client is told); take_down (bool): take
 *                       their live articles down; notify (bool, default true).
 * @return array|WP_Error array( 'cancelled' => int, 'taken_down' => int )
 */
function kounselia_admin_professional_suspend( $professional_id, $reason, $opts = array() ) {
    global $wpdb;
    $opts = wp_parse_args( $opts, array( 'cancel_upcoming' => false, 'take_down' => false, 'notify' => true ) );
    $pro  = kounselia_get_professional_by_id( $professional_id );
    if ( ! $pro ) {
        return new WP_Error( 'not_found', 'That professional no longer exists.' );
    }
    if ( 'verified' !== $pro->status ) {
        return new WP_Error( 'not_verified', 'Only a verified professional can be suspended.' );
    }
    $reason = mb_substr( trim( sanitize_textarea_field( $reason ) ), 0, 500 );
    if ( '' === $reason ) {
        return new WP_Error( 'reason_needed', 'Please write a reason. The professional will see it.' );
    }

    $now = current_time( 'mysql' );
    $wpdb->update( $wpdb->prefix . 'kounselia_professionals', array(
        'status'           => 'suspended',
        'suspended_reason' => $reason,
        'suspended_at'     => $now,
        'reviewed_by'      => get_current_user_id() ?: null,
        'updated_at'       => $now,
    ), array( 'id' => $pro->id ) );

    $cancelled = 0;
    if ( $opts['cancel_upcoming'] && function_exists( 'kounselia_admin_cancel_booking' ) ) {
        foreach ( kounselia_admin_professional_upcoming_ids( $pro->id ) as $booking_id ) {
            $done = kounselia_admin_cancel_booking( $booking_id, get_current_user_id(), 'Your professional is no longer available on Kounselia. You have been refunded. We are sorry for the inconvenience.' );
            if ( ! is_wp_error( $done ) ) {
                $cancelled++;
            }
        }
    }
    $taken = 0;
    if ( $opts['take_down'] && function_exists( 'kounselia_article_set_publishing' ) ) {
        $taken = (int) kounselia_article_set_publishing( $pro->id, 'blocked', 'Account suspended: ' . $reason, true );
    }

    if ( $opts['notify'] && function_exists( 'kounselia_notify_user' ) ) {
        $lang  = kounselia_mail_lang( (int) $pro->user_id );
        $body  = kounselia_t( 'mail.pro.suspended_body', array(), $lang );
        $title = kounselia_t( 'mail.pro.suspended_title', array(), $lang );
        kounselia_notify_user( (int) $pro->user_id, 'professional_suspended', $title, $body, '/pro-dashboard.php', array(
            'subject'      => kounselia_t( 'mail.pro.suspended_subject', array(), $lang ),
            'headline'     => $title,
            'content_html' => '<p>' . esc_html( $body ) . '</p><p style="background:#F8F6F2;border-radius:12px;padding:14px 16px;"><strong>' . esc_html( kounselia_t( 'mail.pro.reason_label', array(), $lang ) ) . '</strong><br>' . nl2br( esc_html( $reason ) ) . '</p>'
                . ( $cancelled ? '<p>' . esc_html( kounselia_t( 1 === $cancelled ? 'mail.pro.cancelled_one' : 'mail.pro.cancelled_other', array( 'n' => (int) $cancelled ), $lang ) ) . '</p>' : '' )
                . '<p>' . esc_html( kounselia_t( 'mail.pro.suspended_mistake', array(), $lang ) ) . '</p>',
        ) );
    }
    kounselia_admin_log( 'suspended_professional', 'professional', (int) $pro->id );
    return array( 'cancelled' => $cancelled, 'taken_down' => $taken );
}

/** Lifts a suspension. Articles taken down stay down until published again. */
function kounselia_admin_professional_reinstate( $professional_id, $notify = true ) {
    global $wpdb;
    $pro = kounselia_get_professional_by_id( $professional_id );
    if ( ! $pro || 'suspended' !== $pro->status ) {
        return new WP_Error( 'not_suspended', 'That professional is not suspended.' );
    }
    $wpdb->update( $wpdb->prefix . 'kounselia_professionals', array(
        'status'           => 'verified',
        'suspended_reason' => null,
        'suspended_at'     => null,
        'updated_at'       => current_time( 'mysql' ),
    ), array( 'id' => $pro->id ) );
    // Blocked only because of the suspension: let them write again.
    if ( 'blocked' === $pro->publishing && 0 === strpos( (string) $pro->publishing_note, 'Account suspended' ) ) {
        $wpdb->update( $wpdb->prefix . 'kounselia_professionals', array( 'publishing' => 'default', 'publishing_note' => null ), array( 'id' => $pro->id ) );
    }
    if ( $notify && function_exists( 'kounselia_notify_user' ) ) {
        $lang = kounselia_mail_lang( (int) $pro->user_id );
        kounselia_notify_user( (int) $pro->user_id, 'professional_reinstated', kounselia_t( 'mail.pro.reinstated_title', array(), $lang ), kounselia_t( 'mail.pro.reinstated_body', array(), $lang ), '/pro-dashboard.php', array(
            'subject'      => kounselia_t( 'mail.pro.reinstated_subject', array(), $lang ),
            'headline'     => kounselia_t( 'mail.pro.reinstated_headline', array(), $lang ),
            'content_html' => '<p>' . esc_html( kounselia_t( 'mail.pro.reinstated_content', array(), $lang ) ) . '</p>',
            'btn_text'     => kounselia_t( 'mail.pro.open_dashboard', array(), $lang ),
            'btn_url'      => kounselia_site_url( '/pro-dashboard.php' ),
        ) );
    }
    kounselia_admin_log( 'reinstated_professional', 'professional', (int) $pro->id );
    return true;
}

/** Numbers for the admin's overview of one professional. */
function kounselia_admin_professional_stats( $pro ) {
    global $wpdb;
    $b   = $wpdb->prefix . 'kounselia_bookings';
    $now = current_time( 'mysql' );
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT
            SUM(status = 'confirmed' AND scheduled_start > %s) AS upcoming,
            SUM(status IN ('confirmed','completed') AND scheduled_start <= %s) AS held,
            SUM(status = 'cancelled') AS cancelled,
            SUM(is_free = 1 AND status IN ('confirmed','completed')) AS free_given,
            COUNT(DISTINCT CASE WHEN status IN ('confirmed','completed') THEN client_user_id END) AS clients
         FROM {$b} WHERE professional_id = %d",
        $now, $now, $pro->id
    ) );
    $earned = (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT COALESCE(SUM(professional_amount),0) FROM {$wpdb->prefix}kounselia_booking_payments WHERE professional_id = %d AND status = 'success'",
        $pro->id
    ) );
    $quiet    = $wpdb->suppress_errors();
    $articles = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_posts WHERE professional_id = %d AND status = 'published'", $pro->id ) );
    $wpdb->suppress_errors( $quiet );
    return array(
        'upcoming'   => (int) ( $row ? $row->upcoming : 0 ),
        'held'       => (int) ( $row ? $row->held : 0 ),
        'cancelled'  => (int) ( $row ? $row->cancelled : 0 ),
        'free_given' => (int) ( $row ? $row->free_given : 0 ),
        'clients'    => (int) ( $row ? $row->clients : 0 ),
        'earned'     => $earned,
        'rating'     => function_exists( 'kounselia_get_professional_rating_summary' ) ? kounselia_get_professional_rating_summary( $pro->id ) : array( 'count' => 0, 'average' => 0 ),
        'followers'  => function_exists( 'kounselia_follower_count' ) ? kounselia_follower_count( $pro->id ) : 0,
        'articles'   => $articles,
    );
}

/* -------------------------------------------------------------------------
 * ADMIN AJAX (Admin → Professionals → Manage)
 * ---------------------------------------------------------------------- */

function kounselia_ajax_admin_professional_save() {
    kounselia_content_admin_guard( 'professionals' );
    $data = array();
    foreach ( array( 'name', 'title', 'specialty', 'years_experience', 'bio', 'rate_amount', 'free_sessions_per_client' ) as $key ) {
        if ( isset( $_POST[ $key ] ) ) {
            $data[ $key ] = wp_unslash( $_POST[ $key ] );
        }
    }
    $result = kounselia_admin_professional_update( (int) kounselia_post_field( 'professional_id', 0 ), $data );
    if ( is_wp_error( $result ) ) {
        kounselia_send_pure_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }
    kounselia_send_pure_json_success( array( 'message' => 'Profile saved.' ) );
}
add_action( 'wp_ajax_kounselia_admin_professional_save', 'kounselia_ajax_admin_professional_save' );

function kounselia_ajax_admin_professional_visibility() {
    kounselia_content_admin_guard( 'professionals' );
    $hidden = '1' === (string) kounselia_post_field( 'hidden', '0' );
    $result = kounselia_admin_professional_set_hidden( (int) kounselia_post_field( 'professional_id', 0 ), $hidden );
    if ( is_wp_error( $result ) ) {
        kounselia_send_pure_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }
    kounselia_send_pure_json_success( array( 'message' => $hidden ? 'Hidden. Members can no longer find this professional.' : 'Visible again.' ) );
}
add_action( 'wp_ajax_kounselia_admin_professional_visibility', 'kounselia_ajax_admin_professional_visibility' );

function kounselia_ajax_admin_professional_suspend() {
    kounselia_content_admin_guard( 'professionals' );
    $result = kounselia_admin_professional_suspend(
        (int) kounselia_post_field( 'professional_id', 0 ),
        (string) kounselia_post_field( 'reason' ),
        array(
            'cancel_upcoming' => '1' === (string) kounselia_post_field( 'cancel_upcoming', '0' ),
            'take_down'       => '1' === (string) kounselia_post_field( 'take_down', '0' ),
        )
    );
    if ( is_wp_error( $result ) ) {
        kounselia_send_pure_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }
    $msg = 'Suspended.';
    if ( $result['cancelled'] ) {
        $msg .= ' ' . $result['cancelled'] . ' upcoming ' . ( 1 === $result['cancelled'] ? 'session' : 'sessions' ) . ' cancelled and refunded.';
    }
    if ( $result['taken_down'] ) {
        $msg .= ' ' . $result['taken_down'] . ' ' . ( 1 === $result['taken_down'] ? 'article' : 'articles' ) . ' taken down.';
    }
    kounselia_send_pure_json_success( array( 'message' => $msg ) );
}
add_action( 'wp_ajax_kounselia_admin_professional_suspend', 'kounselia_ajax_admin_professional_suspend' );

function kounselia_ajax_admin_professional_reinstate() {
    kounselia_content_admin_guard( 'professionals' );
    $result = kounselia_admin_professional_reinstate( (int) kounselia_post_field( 'professional_id', 0 ) );
    if ( is_wp_error( $result ) ) {
        kounselia_send_pure_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }
    kounselia_send_pure_json_success( array( 'message' => 'Reinstated. They can be found and booked again.' ) );
}
add_action( 'wp_ajax_kounselia_admin_professional_reinstate', 'kounselia_ajax_admin_professional_reinstate' );

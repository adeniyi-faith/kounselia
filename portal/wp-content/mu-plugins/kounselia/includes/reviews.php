<?php
/**
 * Kounselia Core — session reviews.
 *
 * Before this file, a professional's card showed a title, a specialty,
 * and a rate — nothing a client could use to judge whether this person
 * is actually good, which is normally the single biggest trust signal
 * in choosing a therapist. A client can rate a session (1-5 stars, an
 * optional comment) once it's actually happened; that's the whole
 * rule — no review before the session, no review on someone else's
 * booking, one review per booking.
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function kounselia_get_review_for_booking( $booking_id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}kounselia_professional_reviews WHERE booking_id = %d",
        $booking_id
    ) );
}

function kounselia_get_professional_rating_summary( $professional_id ) {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT COUNT(*) AS count, AVG(rating) AS average
         FROM {$wpdb->prefix}kounselia_professional_reviews WHERE professional_id = %d",
        $professional_id
    ) );
    return array(
        'count'   => $row ? (int) $row->count : 0,
        'average' => $row && $row->average ? round( (float) $row->average, 1 ) : 0,
    );
}

function kounselia_get_professional_reviews( $professional_id, $limit = 20 ) {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT r.*, u.display_name AS client_name
         FROM {$wpdb->prefix}kounselia_professional_reviews r
         LEFT JOIN {$wpdb->users} u ON u.ID = r.client_user_id
         WHERE r.professional_id = %d
         ORDER BY r.created_at DESC
         LIMIT %d",
        $professional_id,
        $limit
    ) );
}

/**
 * A booking is reviewable once its session time has actually passed —
 * reviewing something that hasn't happened yet isn't a review, and a
 * cancelled session was never a session at all.
 */
function kounselia_booking_is_reviewable( $booking ) {
    if ( ! $booking || 'confirmed' !== $booking->status ) {
        return false;
    }
    return strtotime( $booking->scheduled_end ) <= current_time( 'timestamp' );
}

/**
 * Submits (or edits) a client's review of one of their own past
 * sessions. Upserts on booking_id, so re-submitting just updates the
 * same review rather than creating a second one.
 */
function kounselia_submit_review( $booking_id, $client_user_id, $rating, $comment ) {
    global $wpdb;

    $rating = (int) $rating;
    if ( $rating < 1 || $rating > 5 ) {
        return new WP_Error( 'invalid_rating', 'Please choose a rating from 1 to 5.' );
    }

    $booking = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}kounselia_bookings WHERE id = %d",
        $booking_id
    ) );
    if ( ! $booking ) {
        return new WP_Error( 'not_found', 'Booking not found.' );
    }
    if ( (int) $booking->client_user_id !== (int) $client_user_id ) {
        return new WP_Error( 'forbidden', 'You can only review your own sessions.' );
    }
    if ( ! kounselia_booking_is_reviewable( $booking ) ) {
        return new WP_Error( 'not_reviewable', 'This session hasn\'t happened yet.' );
    }

    $comment = $comment ? sanitize_textarea_field( substr( $comment, 0, 1000 ) ) : null;
    $now     = current_time( 'mysql' );

    $existing = kounselia_get_review_for_booking( $booking_id );
    if ( $existing ) {
        $wpdb->update( $wpdb->prefix . 'kounselia_professional_reviews', array(
            'rating'     => $rating,
            'comment'    => $comment,
            'updated_at' => $now,
        ), array( 'id' => $existing->id ) );
        return (int) $existing->id;
    }

    $wpdb->insert( $wpdb->prefix . 'kounselia_professional_reviews', array(
        'booking_id'      => $booking_id,
        'professional_id' => $booking->professional_id,
        'client_user_id'  => $client_user_id,
        'rating'          => $rating,
        'comment'         => $comment,
        'created_at'      => $now,
        'updated_at'      => $now,
    ) );

    return (int) $wpdb->insert_id;
}

function kounselia_ajax_submit_review() {
    kounselia_verify_nonce();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.' ), 401 );
    }
    if ( kounselia_rate_limited( 'submit_review', 20, 3600 ) ) {
        wp_send_json_error( array( 'message' => 'Too many attempts. Please try again later.' ), 429 );
    }

    $booking_id = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0;
    $rating     = isset( $_POST['rating'] ) ? absint( $_POST['rating'] ) : 0;
    $comment    = isset( $_POST['comment'] ) ? wp_unslash( $_POST['comment'] ) : '';

    $result = kounselia_submit_review( $booking_id, get_current_user_id(), $rating, $comment );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
    }

    wp_send_json_success( array( 'message' => 'Thanks for the feedback.' ) );
}
add_action( 'wp_ajax_kounselia_submit_review', 'kounselia_ajax_submit_review' );

/**
 * Admin moderation: remove a review (e.g. abusive, identifying, or
 * off-platform-solicitation content that shouldn't be public). Rare
 * enough that a single AJAX action from the admin Bookings/Professionals
 * pages is enough — no dedicated review-moderation page.
 */
function kounselia_ajax_admin_delete_review() {
    check_ajax_referer( 'kounselia_admin_nonce', 'nonce' );

    if ( ! kounselia_user_is_admin() ) {
        kounselia_send_pure_json_error( array( 'message' => 'Unauthorized' ), 403 );
    }

    $review_id = isset( $_POST['review_id'] ) ? absint( $_POST['review_id'] ) : 0;
    if ( ! $review_id ) {
        kounselia_send_pure_json_error( array( 'message' => 'Invalid request' ), 400 );
    }

    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'kounselia_professional_reviews', array( 'id' => $review_id ) );
    kounselia_admin_log( 'delete_review', 'review', $review_id );

    kounselia_send_pure_json_success( array( 'message' => 'Review removed.' ) );
}
add_action( 'wp_ajax_kounselia_admin_delete_review', 'kounselia_ajax_admin_delete_review' );

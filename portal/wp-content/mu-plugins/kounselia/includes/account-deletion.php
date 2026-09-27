<?php
/**
 * Kounselia Core — a member deleting their own account.
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 *
 * App stores require any app you can sign up in to let you delete your
 * account from inside it too, and this app holds counseling conversations,
 * mood check-ins and a journal, so deleting really erases them. Unlike an
 * admin's "Delete" (a soft delete to the Trash that can be undone), this
 * is permanent the moment it runs.
 *
 * What goes: the WordPress account itself, every AI counseling
 * conversation, mood check-ins, journal entries, the memory profile and
 * check-ins, notifications, phone sign-ins and push tokens, the plan
 * (so the saved card is never charged again), messages the member wrote
 * to professionals, and their comments, loves and follows (see
 * kounselia_community_forget_user) and newsletter contact.
 *
 * What stays, with no name attached once the account is gone: records of
 * sessions booked with a professional and payments made, because the
 * professional and the accounts need those (they're the professional's
 * record of work done and money received), and star ratings left for a
 * professional.
 *
 * Professionals and staff can't delete themselves here — a professional
 * has clients, bookings and payouts that need closing properly, so they're
 * asked to contact us instead.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Why this user can't delete their own account right now, or '' if they can.
 */
function kounselia_account_deletion_blocker( $user_id ) {
    global $wpdb;

    if ( user_can( $user_id, 'kounselia_admin' ) || user_can( $user_id, 'manage_options' ) ) {
        return 'Staff accounts can’t be deleted from here. Please ask another administrator.';
    }

    if ( function_exists( 'kounselia_get_professional_application' ) && kounselia_get_professional_application( $user_id ) ) {
        $footer = function_exists( 'kounselia_footer_settings' ) ? kounselia_footer_settings() : array();
        $email  = ! empty( $footer['social']['email'] ) ? sanitize_email( $footer['social']['email'] ) : 'hello@kounselia.com';
        return 'This account is also a professional account, so it has clients, bookings or payouts that need closing properly. Please email us at ' . $email . ' and we’ll delete it for you.';
    }

    $upcoming = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_bookings
         WHERE client_user_id = %d AND status = 'confirmed' AND scheduled_start >= %s",
        $user_id,
        current_time( 'mysql' )
    ) );
    if ( $upcoming > 0 ) {
        return 1 === $upcoming
            ? 'You have a session booked with a professional that hasn’t happened yet. Please cancel it first (in Sessions), then delete your account.'
            : 'You have ' . $upcoming . ' sessions booked with professionals that haven’t happened yet. Please cancel them first (in Sessions), then delete your account.';
    }

    return '';
}

/**
 * Erases everything Kounselia keeps about a member, except the booking
 * and payment records described at the top of this file. Runs on
 * WordPress's deleted_user hook, so an admin's "Purge" erases the same
 * things as a member deleting themselves.
 */
function kounselia_erase_member_data( $user_id ) {
    global $wpdb;
    $user_id = (int) $user_id;
    $p       = $wpdb->prefix;

    // AI counseling conversations, and the thumbs up/down on them.
    $wpdb->query( $wpdb->prepare( "DELETE m FROM {$p}kounselia_messages m INNER JOIN {$p}kounselia_sessions s ON m.session_id = s.id WHERE s.user_id = %d", $user_id ) );
    $wpdb->delete( "{$p}kounselia_sessions", array( 'user_id' => $user_id ) );
    $wpdb->delete( "{$p}kounselia_message_feedback", array( 'user_id' => $user_id ) );

    // Safety alerts point at those (now deleted) messages.
    $wpdb->delete( "{$p}kounselia_safety_escalations", array( 'user_id' => $user_id ) );

    // Mood, journal, memory and the check-ins drawn from it.
    $wpdb->delete( "{$p}kounselia_mood_logs", array( 'user_id' => $user_id ) );
    $wpdb->delete( "{$p}kounselia_journal_entries", array( 'user_id' => $user_id ) );
    if ( function_exists( 'kounselia_memory_delete_profile' ) ) {
        kounselia_memory_delete_profile( $user_id );
    }
    $wpdb->delete( "{$p}kounselia_memory_upcoming_events", array( 'user_id' => $user_id ) );

    // Notifications, and every phone signed in or registered for push.
    $wpdb->delete( "{$p}kounselia_notifications", array( 'user_id' => $user_id ) );
    $wpdb->delete( "{$p}kounselia_push_tokens", array( 'user_id' => $user_id ) );
    $wpdb->delete( "{$p}kounselia_app_tokens", array( 'user_id' => $user_id ) );

    // The plan: without this row the renewal job has nothing to charge.
    $wpdb->delete( "{$p}kounselia_subscriptions", array( 'user_id' => $user_id ) );

    // What they wrote to professionals. The booking itself stays (above).
    $wpdb->delete( "{$p}kounselia_booking_messages", array( 'sender_user_id' => $user_id ) );
}
add_action( 'deleted_user', 'kounselia_erase_member_data' );

/**
 * The member deletes their own account (the app's Settings → Delete
 * account). They type their password again, so someone who picks up an
 * unlocked phone can't do it.
 */
function kounselia_ajax_delete_account() {
    kounselia_verify_nonce();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.', 'signed_out' => true ), 401 );
    }

    if ( kounselia_rate_limited( 'delete_account', 5, 600 ) ) {
        wp_send_json_error( array( 'message' => 'Too many attempts, please wait a few minutes and try again.' ), 429 );
    }

    $user     = wp_get_current_user();
    $password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';

    if ( '' === $password || ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
        wp_send_json_error( array( 'message' => 'That password isn’t right.' ), 401 );
    }

    $blocker = kounselia_account_deletion_blocker( $user->ID );
    if ( '' !== $blocker ) {
        wp_send_json_error( array( 'message' => $blocker ), 409 );
    }

    // Kept before the account is gone, for the goodbye email.
    $email = $user->user_email;
    $name  = $user->display_name ? $user->display_name : '';

    require_once ABSPATH . 'wp-admin/includes/user.php';
    if ( function_exists( 'kounselia_admin_log' ) ) {
        kounselia_admin_log( 'member_deleted_own_account', 'user', $user->ID );
    }
    // Everything else is erased by kounselia_erase_member_data and the
    // other deleted_user / delete_user hooks.
    wp_delete_user( $user->ID );
    wp_clear_auth_cookie();

    if ( function_exists( 'kounselia_send_html_email' ) ) {
        kounselia_send_html_email(
            $email,
            'Your Kounselia account has been deleted',
            'Your account has been deleted',
            '<p>' . ( $name ? 'Hi ' . esc_html( $name ) . ',' : 'Hi,' ) . '</p>'
            . '<p>As you asked, we’ve deleted your Kounselia account, along with your conversations, mood check-ins, journal and everything your counselors remembered about you. This can’t be undone.</p>'
            . '<p>If you didn’t ask for this, please reply to this email straight away.</p>'
            . '<p>We’re glad we could be part of your journey, and you’re always welcome back.</p>'
        );
    }

    wp_send_json_success( array( 'message' => 'Your account has been deleted.' ) );
}
add_action( 'wp_ajax_kounselia_delete_account', 'kounselia_ajax_delete_account' );

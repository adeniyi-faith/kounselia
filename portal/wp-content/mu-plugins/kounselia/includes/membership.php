<?php
/**
 * Kounselia Core — what a Pro membership actually unlocks.
 *
 * One place that answers "is this person Pro?" and "what do they get?",
 * so every feature checks the same thing. A member is Pro when they have
 * a paid subscription whose period hasn't ended, OR an admin has granted
 * Pro by hand (the 'kounselia_plan' = 'pro' flag in Members CRM).
 *
 * Benefits are admin-configurable (Admin → Plans & Pricing → What each
 * plan includes). Safety features and unlimited text conversations are
 * never limited on any plan.
 *
 *   voice_minutes          length of one voice call (stored in the
 *                          existing kounselia_voice_*_minutes options)
 *   memory_messages        how many recent messages the counselor re-reads
 *                          on every reply (longer = remembers more of
 *                          this conversation)
 *   recurring_patterns     counselor quietly notices patterns from the
 *                          member's longer history (Reflection Engine)
 *   reflections_per_month  on-demand Milestone Reflections (0 = unlimited)
 *   auto_reflection_days   how often the reflection refreshes itself
 *   booking_discount       % off sessions with human professionals
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function kounselia_plan_benefit_defaults() {
    return array(
        'free' => array(
            'memory_messages'       => 16,
            'recurring_patterns'    => 0,
            'reflections_per_month' => 1,
            'auto_reflection_days'  => 30,
            'booking_discount'      => 0,
        ),
        'pro'  => array(
            'memory_messages'       => 40,
            'recurring_patterns'    => 1,
            'reflections_per_month' => 0,
            'auto_reflection_days'  => 7,
            'booking_discount'      => 10,
        ),
    );
}

/**
 * Benefits for both tiers, merged with defaults. Voice minutes come
 * from the options the Settings page already uses, so there's one
 * source of truth for them.
 */
function kounselia_plan_benefits() {
    $defaults = kounselia_plan_benefit_defaults();
    $saved    = get_option( 'kounselia_plan_benefits', array() );
    $out      = array();
    foreach ( $defaults as $tier => $values ) {
        $out[ $tier ] = wp_parse_args( isset( $saved[ $tier ] ) && is_array( $saved[ $tier ] ) ? $saved[ $tier ] : array(), $values );
    }
    $out['free']['voice_minutes'] = (int) get_option( 'kounselia_voice_free_minutes', 5 );
    $out['pro']['voice_minutes']  = (int) get_option( 'kounselia_voice_pro_minutes', 15 );
    return $out;
}

function kounselia_save_plan_benefits( $input ) {
    $clean = array();
    foreach ( array( 'free', 'pro' ) as $tier ) {
        $t              = isset( $input[ $tier ] ) ? (array) $input[ $tier ] : array();
        $clean[ $tier ] = array(
            'memory_messages'       => max( 4, min( 100, (int) ( $t['memory_messages'] ?? 16 ) ) ),
            'recurring_patterns'    => ! empty( $t['recurring_patterns'] ) ? 1 : 0,
            'reflections_per_month' => max( 0, min( 100, (int) ( $t['reflections_per_month'] ?? 0 ) ) ),
            'auto_reflection_days'  => max( 0, min( 365, (int) ( $t['auto_reflection_days'] ?? 30 ) ) ),
            'booking_discount'      => max( 0, min( 50, (float) ( $t['booking_discount'] ?? 0 ) ) ),
        );
        if ( isset( $t['voice_minutes'] ) ) {
            update_option( 'kounselia_voice_' . $tier . '_minutes', max( 1, min( 120, (int) $t['voice_minutes'] ) ) );
        }
    }
    update_option( 'kounselia_plan_benefits', $clean );
}

/* -------------------------------------------------------------------------
 * Who is Pro
 * ---------------------------------------------------------------------- */

function kounselia_member_is_pro( $user_id ) {
    if ( ! $user_id ) {
        return false;
    }
    if ( 'pro' === get_user_meta( $user_id, 'kounselia_plan', true ) ) {
        return true; // Granted by an admin.
    }
    return function_exists( 'kounselia_user_is_pro' ) && kounselia_user_is_pro( $user_id );
}

function kounselia_member_tier( $user_id ) {
    return kounselia_member_is_pro( $user_id ) ? 'pro' : 'free';
}

function kounselia_member_benefit( $user_id, $key ) {
    $benefits = kounselia_plan_benefits();
    $tier     = kounselia_member_tier( $user_id );
    return isset( $benefits[ $tier ][ $key ] ) ? $benefits[ $tier ][ $key ] : null;
}

/**
 * Plain-English list of what a tier includes, for plan cards and the
 * dashboard. Always lists the things every plan gets first.
 */
function kounselia_plan_benefit_lines( $tier ) {
    $b     = kounselia_plan_benefits()[ $tier ];
    $lines = array( 'Unlimited private conversations with every counselor' );
    $lines[] = (int) $b['voice_minutes'] . ' minute voice calls';
    $lines[] = $b['memory_messages'] >= 30 ? 'Deeper memory within each conversation' : 'Your counselor remembers your story between visits';
    if ( $b['recurring_patterns'] ) {
        $lines[] = 'Counselors notice your recurring patterns across sessions';
    }
    $lines[] = $b['reflections_per_month'] ? (int) $b['reflections_per_month'] . ' Milestone Reflection' . ( 1 === (int) $b['reflections_per_month'] ? '' : 's' ) . ' a month' : 'Unlimited Milestone Reflections';
    if ( $b['booking_discount'] > 0 ) {
        $lines[] = rtrim( rtrim( number_format( (float) $b['booking_discount'], 1 ), '0' ), '.' ) . '% off sessions with licensed professionals';
    }
    $lines[] = 'Mood check-ins and a private journal';
    return $lines;
}

/* -------------------------------------------------------------------------
 * Milestone Reflections allowance
 * ---------------------------------------------------------------------- */

/**
 * array( 'limit' => int (0 = unlimited), 'used' => int, 'remaining' => int|null )
 */
function kounselia_reflection_allowance( $user_id ) {
    $limit = (int) kounselia_member_benefit( $user_id, 'reflections_per_month' );
    $used  = get_user_meta( $user_id, 'kounselia_reflections_used', true );
    $count = ( is_array( $used ) && isset( $used['month'] ) && current_time( 'Y-m' ) === $used['month'] ) ? (int) $used['count'] : 0;
    return array(
        'limit'     => $limit,
        'used'      => $count,
        'remaining' => $limit ? max( 0, $limit - $count ) : null,
    );
}

function kounselia_reflection_record_use( $user_id ) {
    $a = kounselia_reflection_allowance( $user_id );
    update_user_meta( $user_id, 'kounselia_reflections_used', array( 'month' => current_time( 'Y-m' ), 'count' => $a['used'] + 1 ) );
}

/* -------------------------------------------------------------------------
 * Professional session pricing
 * ---------------------------------------------------------------------- */

/**
 * What a member pays for a professional session, after their plan's
 * discount. The discount comes out of Kounselia's commission — never
 * the professional's share — so it's capped at the commission rate.
 * Returns array( 'charged', 'discount', 'discount_percent', 'platform_fee', 'professional_amount' ).
 */
function kounselia_member_session_price( $user_id, $rate, $commission_percent ) {
    $rate       = (float) $rate;
    $percent    = min( (float) kounselia_member_benefit( $user_id, 'booking_discount' ), (float) $commission_percent );
    $fee_full   = round( $rate * $commission_percent / 100, 2 );
    $discount   = round( $rate * max( 0, $percent ) / 100, 2 );
    return array(
        'charged'             => round( $rate - $discount, 2 ),
        'discount'            => $discount,
        'discount_percent'    => $percent,
        'platform_fee'        => round( $fee_full - $discount, 2 ),
        'professional_amount' => round( $rate - $fee_full, 2 ),
    );
}

/* -------------------------------------------------------------------------
 * Admin AJAX: save the benefits table (Plans & Pricing)
 * ---------------------------------------------------------------------- */

function kounselia_ajax_admin_save_plan_benefits() {
    check_ajax_referer( 'kounselia_admin_nonce', 'nonce' );
    if ( ! kounselia_admin_can( 'plans' ) ) {
        kounselia_send_pure_json_error( array( 'message' => 'You do not have access to plans.' ), 403 );
    }
    $raw = json_decode( (string) wp_unslash( $_POST['benefits'] ?? '{}' ), true );
    kounselia_save_plan_benefits( is_array( $raw ) ? $raw : array() );
    kounselia_admin_log( 'edited_plan_benefits', 'settings', 0 );
    kounselia_send_pure_json_success( array( 'message' => 'Plan benefits saved. They apply to members straight away.' ) );
}
add_action( 'wp_ajax_kounselia_admin_save_plan_benefits', 'kounselia_ajax_admin_save_plan_benefits' );

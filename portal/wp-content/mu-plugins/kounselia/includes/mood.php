<?php
/**
 * Kounselia Core — daily mood check-in
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * 15. MOOD CHECK-IN
 * ---------------------------------------------------------------------- */

function kounselia_mood_options() {
    return array(
        'calm'        => array( 'label' => 'Calm',        'icon' => 'ti-mood-smile',    'class' => 'ic-sage' ),
        'okay'        => array( 'label' => 'Okay',         'icon' => 'ti-mood-neutral',  'class' => 'ic-blue' ),
        'anxious'     => array( 'label' => 'Anxious',      'icon' => 'ti-mood-confuzed', 'class' => 'ic-gold' ),
        'low'         => array( 'label' => 'Low',          'icon' => 'ti-mood-sad',      'class' => 'ic-plum' ),
        'overwhelmed' => array( 'label' => 'Overwhelmed',  'icon' => 'ti-cloud-storm',   'class' => 'ic-sienna' ),
    );
}

function kounselia_mood_counselor_map() {
    return array(
        'calm'        => 'noa',
        'okay'        => 'eli',
        'anxious'     => 'serena',
        'low'         => 'theo',
        'overwhelmed' => 'priya',
    );
}

function kounselia_ajax_save_mood() {
    kounselia_verify_nonce();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.' ), 401 );
    }

    $mood = isset( $_POST['mood'] ) ? sanitize_key( wp_unslash( $_POST['mood'] ) ) : '';
    if ( ! array_key_exists( $mood, kounselia_mood_options() ) ) {
        wp_send_json_error( array( 'message' => 'Unknown mood.' ), 400 );
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $today   = current_time( 'Y-m-d' );
    $table   = $wpdb->prefix . 'kounselia_mood_logs';

    $existing_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$table} WHERE user_id = %d AND log_date = %s", $user_id, $today
    ) );

    if ( $existing_id ) {
        $wpdb->update( $table, array( 'mood' => $mood ), array( 'id' => $existing_id ) );
    } else {
        $wpdb->insert( $table, array(
            'user_id'    => $user_id,
            'mood'       => $mood,
            'log_date'   => $today,
            'created_at' => current_time( 'mysql' ),
        ) );
    }

    wp_send_json_success( array( 'mood' => $mood ) );
}
add_action( 'wp_ajax_kounselia_save_mood', 'kounselia_ajax_save_mood' );

function kounselia_get_recent_moods( $user_id, $days = 7 ) {
    global $wpdb;
    $table = $wpdb->prefix . 'kounselia_mood_logs';

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT mood, log_date FROM {$table} WHERE user_id = %d AND log_date >= %s ORDER BY log_date ASC",
        $user_id, gmdate( 'Y-m-d', strtotime( '-' . ( $days - 1 ) . ' days', current_time( 'timestamp' ) ) )
    ) );

    $by_date = array();
    foreach ( $rows as $row ) {
        $by_date[ $row->log_date ] = $row->mood;
    }

    $out = array();
    for ( $i = $days - 1; $i >= 0; $i-- ) {
        $date  = gmdate( 'Y-m-d', strtotime( "-{$i} days", current_time( 'timestamp' ) ) );
        $out[] = array( 'date' => $date, 'mood' => isset( $by_date[ $date ] ) ? $by_date[ $date ] : null );
    }
    return $out;
}

function kounselia_get_today_mood( $user_id ) {
    global $wpdb;
    return $wpdb->get_var( $wpdb->prepare(
        "SELECT mood FROM {$wpdb->prefix}kounselia_mood_logs WHERE user_id = %d AND log_date = %s",
        $user_id, current_time( 'Y-m-d' )
    ) );
}



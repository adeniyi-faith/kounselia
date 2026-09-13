<?php
/**
 * Kounselia Core — custom DB tables, install/upgrade, one-time backfills
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * 1. CUSTOM TABLES
 * ---------------------------------------------------------------------- */

/**
 * Create/upgrade custom tables. Hooked to admin_init and also runnable
 * directly. dbDelta is idempotent, safe to call on every load is wasteful
 * though, so we gate it behind a stored db version option.
 */
function kounselia_install_tables() {
    global $wpdb;

    $installed_version = get_option( 'kounselia_db_version', '0' );
    $current_version   = '1.8.0'; // Bumped version to trigger DB expansion for the sender column

    if ( $installed_version === $current_version ) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $prefix           = $wpdb->prefix;

    // Counseling sessions (a "conversation thread" with one counselor persona).
    $sql_sessions = "CREATE TABLE {$prefix}kounselia_sessions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NULL,
        guest_token VARCHAR(64) NULL,
        counselor_slug VARCHAR(64) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        started_at DATETIME NOT NULL,
        ended_at DATETIME NULL,
        PRIMARY KEY  (id),
        KEY user_id (user_id),
        KEY guest_token (guest_token)
    ) {$charset_collate};";

    // Individual chat messages within a session.
    // CRITICAL FIX: Expanded `sender` from VARCHAR(10) to VARCHAR(32) to accommodate 'peer_consult' (12 chars).
    $sql_messages = "CREATE TABLE {$prefix}kounselia_messages (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id BIGINT UNSIGNED NOT NULL,
        sender VARCHAR(32) NOT NULL,
        content LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL,
        flagged_safety TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
        flag_reason VARCHAR(255) NULL,
        PRIMARY KEY  (id),
        KEY session_id (session_id),
        KEY flagged_safety (flagged_safety)
    ) {$charset_collate};";

    // Server-side enforcement of the guest message limit
    $sql_guest_limits = "CREATE TABLE {$prefix}kounselia_guest_limits (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        guest_token VARCHAR(64) NOT NULL,
        message_count INT UNSIGNED NOT NULL DEFAULT 0,
        window_start DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY guest_token (guest_token)
    ) {$charset_collate};";

    // Per-counselor AI configuration
    $sql_prompts = "CREATE TABLE {$prefix}kounselia_counselor_prompts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        counselor_slug VARCHAR(32) NOT NULL,
        system_prompt LONGTEXT NOT NULL,
        ai_model VARCHAR(64) NOT NULL DEFAULT 'gemini-2.5-flash-lite',
        temperature DECIMAL(3,2) NOT NULL DEFAULT 0.80,
        max_tokens INT UNSIGNED NOT NULL DEFAULT 400,
        tts_voice VARCHAR(32) NOT NULL DEFAULT 'Kore',
        voice_enabled TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY  (id),
        UNIQUE KEY counselor_slug (counselor_slug)
    ) {$charset_collate};";

    // One mood check-in per member per day 
    $sql_moods = "CREATE TABLE {$prefix}kounselia_mood_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        mood VARCHAR(20) NOT NULL,
        log_date DATE NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY user_date (user_id, log_date)
    ) {$charset_collate};";

    // One private journal entry per member per day
    $sql_journal = "CREATE TABLE {$prefix}kounselia_journal_entries (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        content LONGTEXT NOT NULL,
        entry_date DATE NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY user_date (user_id, entry_date)
    ) {$charset_collate};";

    // Audit trail for the admin panel
    $sql_audit_log = "CREATE TABLE {$prefix}kounselia_admin_audit_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        admin_id BIGINT UNSIGNED NOT NULL,
        action VARCHAR(64) NOT NULL,
        target_type VARCHAR(32) NOT NULL,
        target_id BIGINT UNSIGNED NULL,
        ip_address VARCHAR(64) NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY admin_id (admin_id),
        KEY target (target_type, target_id)
    ) {$charset_collate};";

    dbDelta( $sql_sessions );
    dbDelta( $sql_messages );
    dbDelta( $sql_guest_limits );
    dbDelta( $sql_prompts );
    dbDelta( $sql_moods );
    dbDelta( $sql_journal );
    dbDelta( $sql_audit_log );

    update_option( 'kounselia_db_version', $current_version );

    kounselia_seed_counselor_prompts();
    kounselia_backfill_tts_voices();
    kounselia_cleanup_message_slashes();
}
add_action( 'init', 'kounselia_install_tables' );

/**
 * One-time seed of the counselor_prompts table from the hardcoded defaults
 */
function kounselia_seed_counselor_prompts() {
    global $wpdb;
    $table = $wpdb->prefix . 'kounselia_counselor_prompts';

    $existing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    if ( $existing > 0 ) {
        return;
    }

    foreach ( kounselia_default_prompts() as $slug => $p ) {
        $wpdb->insert( $table, array(
            'counselor_slug' => $slug,
            'system_prompt'  => $p['system_prompt'],
            'ai_model'       => $p['ai_model'],
            'temperature'    => $p['temperature'],
            'max_tokens'     => $p['max_tokens'],
            'tts_voice'      => $p['tts_voice'],
            'voice_enabled'  => $p['voice_enabled'],
            'is_active'      => 1,
        ) );
    }
}

/**
 * Sets each counselor's tts_voice to match the defaults
 */
function kounselia_backfill_tts_voices() {
    global $wpdb;
    $table = $wpdb->prefix . 'kounselia_counselor_prompts';

    foreach ( kounselia_default_prompts() as $slug => $p ) {
        $wpdb->update( $table, array(
            'tts_voice'     => $p['tts_voice'],
            'voice_enabled' => $p['voice_enabled'],
        ), array( 'counselor_slug' => $slug ) );
    }
}

/**
 * One-time cleanup for any chat messages stored before slash fix existed.
 */
function kounselia_cleanup_message_slashes() {
    global $wpdb;
    $table = $wpdb->prefix . 'kounselia_messages';

    $rows = $wpdb->get_results( "SELECT id, content FROM {$table}" );
    foreach ( $rows as $row ) {
        $clean = wp_unslash( $row->content );
        if ( $clean !== $row->content ) {
            $wpdb->update( $table, array( 'content' => $clean ), array( 'id' => $row->id ) );
        }
    }
}
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
    $current_version   = '1.13.0'; // Bumped version: Paystack subscriptions (kounselia_subscriptions, kounselia_payments)

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

    /*
     * ---------------------------------------------------------------------
     * MEMORY TABLES (v1.9.0)
     *
     * The structured memory profile used to live as one giant JSON blob in
     * wp_usermeta ('kounselia_core_memory'). Every read, and every write,
     * had to load and parse that entire blob even when a feature only
     * needed one piece of it (e.g. "what happened to this user recently").
     * These tables split it into its natural parts so future features can
     * query just the part they need directly in SQL. See memory-store.php
     * for the read/write layer built on top of these tables.
     * ---------------------------------------------------------------------
     */

    // One row per user: the single-value fields of the profile.
    $sql_memory_profile = "CREATE TABLE {$prefix}kounselia_memory_profile (
        user_id BIGINT UNSIGNED NOT NULL,
        identity TEXT NULL,
        career TEXT NULL,
        health TEXT NULL,
        communication_style VARCHAR(255) NULL,
        personality TEXT NULL,
        faith VARCHAR(255) NULL,
        temporary_context TEXT NULL,
        updated_at DATETIME NULL,
        PRIMARY KEY  (user_id)
    ) {$charset_collate};";

    // life_timeline: one row per remembered life event.
    $sql_memory_life_events = "CREATE TABLE {$prefix}kounselia_memory_life_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        event_year VARCHAR(16) NULL,
        event_text TEXT NOT NULL,
        impact TEXT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY user_id (user_id)
    ) {$charset_collate};";

    // emotional_map: how the user feels about a person/place/topic, one row per entity.
    $sql_memory_emotions = "CREATE TABLE {$prefix}kounselia_memory_emotions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        entity_name VARCHAR(191) NOT NULL,
        emotion VARCHAR(100) NULL,
        intensity VARCHAR(10) NULL,
        context TEXT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY user_entity (user_id, entity_name)
    ) {$charset_collate};";

    // relationships: one row per named person/group in the user's life.
    $sql_memory_relationships = "CREATE TABLE {$prefix}kounselia_memory_relationships (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        person_name VARCHAR(191) NOT NULL,
        context TEXT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY user_person (user_id, person_name)
    ) {$charset_collate};";

    // Generic list fields (goals, important_people, values, triggers, traumas,
    // current_challenges, wins, habits) — one row per item, tagged by list_type.
    $sql_memory_list_items = "CREATE TABLE {$prefix}kounselia_memory_list_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        list_type VARCHAR(32) NOT NULL,
        item_text TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY user_list (user_id, list_type)
    ) {$charset_collate};";

    // preferences: free-form key/value pairs.
    $sql_memory_preferences = "CREATE TABLE {$prefix}kounselia_memory_preferences (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        pref_key VARCHAR(191) NOT NULL,
        pref_value TEXT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY user_pref (user_id, pref_key)
    ) {$charset_collate};";

    /*
     * A safety keyword match used to just flip a flag on the message row
     * and wait for a staff member to happen to check the Safety page.
     * This table gives each match its own tracked case: how urgent it is,
     * whether it's been acted on, and by whom — so an acute-risk message
     * triggers an immediate alert instead of sitting in a queue.
     */
    $sql_safety_escalations = "CREATE TABLE {$prefix}kounselia_safety_escalations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        message_id BIGINT UNSIGNED NOT NULL,
        session_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NULL,
        guest_token VARCHAR(64) NULL,
        severity VARCHAR(10) NOT NULL DEFAULT 'elevated',
        flag_reason VARCHAR(255) NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'open',
        notified_at DATETIME NULL,
        acknowledged_by BIGINT UNSIGNED NULL,
        acknowledged_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY session_id (session_id),
        KEY status (status),
        KEY severity (severity)
    ) {$charset_collate};";

    /*
     * A dated event the user mentioned in conversation (e.g. "my
     * presentation is tomorrow"), extracted during memory synthesis.
     * Separate from the memory-profile tables above: this one tracks a
     * status (pending / checked_in / dismissed / expired) across
     * synthesis runs, which the wipe-and-reinsert profile tables don't.
     */
    $sql_memory_upcoming_events = "CREATE TABLE {$prefix}kounselia_memory_upcoming_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        event_text VARCHAR(191) NOT NULL,
        event_date DATE NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL,
        checked_in_at DATETIME NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY user_event (user_id, event_date, event_text),
        KEY user_status (user_id, status)
    ) {$charset_collate};";

    /*
     * A human professional's application to join the platform. Created
     * the moment they apply (status 'pending'); an admin reviews their
     * uploaded documents and either approves it (status 'verified',
     * which is what grants the kounselia_professional WP role) or
     * rejects it with a reason. Rate is the professional's own — they
     * set it, admin can only view/override it, never invent it for them.
     */
    $sql_professionals = "CREATE TABLE {$prefix}kounselia_professionals (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        title VARCHAR(191) NOT NULL,
        license_number VARCHAR(191) NULL,
        specialty VARCHAR(191) NULL,
        years_experience SMALLINT UNSIGNED NULL,
        bio TEXT NULL,
        rate_amount DECIMAL(10,2) NULL,
        rate_currency VARCHAR(8) NOT NULL DEFAULT 'NGN',
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        rejection_reason VARCHAR(500) NULL,
        submitted_at DATETIME NOT NULL,
        reviewed_at DATETIME NULL,
        reviewed_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY user_id (user_id),
        KEY status (status)
    ) {$charset_collate};";

    // Verification documents (license, government ID, certificates) tied
    // to an application. Files are stored outside the public media
    // library — see kounselia_professional_docs_dir() in professionals.php
    // — and only ever served through an authenticated endpoint, never a
    // direct public URL.
    $sql_professional_documents = "CREATE TABLE {$prefix}kounselia_professional_documents (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        professional_id BIGINT UNSIGNED NOT NULL,
        doc_type VARCHAR(32) NOT NULL,
        original_filename VARCHAR(255) NOT NULL,
        stored_filename VARCHAR(255) NOT NULL,
        uploaded_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY professional_id (professional_id)
    ) {$charset_collate};";

    // A user's current subscription. One row per user (unique user_id) —
    // history of individual charges lives in kounselia_payments instead,
    // this table only ever tracks "what plan is this user on right now."
    $sql_subscriptions = "CREATE TABLE {$prefix}kounselia_subscriptions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        plan_id VARCHAR(64) NOT NULL,
        plan_name VARCHAR(191) NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'active',
        amount DECIMAL(10,2) NOT NULL,
        currency VARCHAR(8) NOT NULL DEFAULT 'NGN',
        paystack_reference VARCHAR(100) NULL,
        paystack_customer_code VARCHAR(100) NULL,
        current_period_start DATETIME NULL,
        current_period_end DATETIME NULL,
        cancelled_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY user_id (user_id),
        KEY status (status)
    ) {$charset_collate};";

    // Every Paystack transaction attempt, one row per reference — created
    // as 'pending' the moment checkout is initialized, then flipped to
    // 'success'/'failed' once verified. Keeps a receipt trail independent
    // of whatever the current subscription row says.
    $sql_payments = "CREATE TABLE {$prefix}kounselia_payments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        plan_id VARCHAR(64) NOT NULL,
        reference VARCHAR(100) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        currency VARCHAR(8) NOT NULL DEFAULT 'NGN',
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        gateway_response TEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY reference (reference),
        KEY user_id (user_id)
    ) {$charset_collate};";

    dbDelta( $sql_sessions );
    dbDelta( $sql_messages );
    dbDelta( $sql_guest_limits );
    dbDelta( $sql_prompts );
    dbDelta( $sql_moods );
    dbDelta( $sql_journal );
    dbDelta( $sql_audit_log );
    dbDelta( $sql_memory_profile );
    dbDelta( $sql_memory_life_events );
    dbDelta( $sql_memory_emotions );
    dbDelta( $sql_memory_relationships );
    dbDelta( $sql_memory_list_items );
    dbDelta( $sql_memory_preferences );
    dbDelta( $sql_safety_escalations );
    dbDelta( $sql_memory_upcoming_events );
    dbDelta( $sql_professionals );
    dbDelta( $sql_professional_documents );
    dbDelta( $sql_subscriptions );
    dbDelta( $sql_payments );

    if ( function_exists( 'kounselia_ensure_professional_docs_dir' ) ) {
        kounselia_ensure_professional_docs_dir();
    }

    update_option( 'kounselia_db_version', $current_version );

    kounselia_seed_counselor_prompts();
    kounselia_backfill_tts_voices();
    kounselia_cleanup_message_slashes();
    kounselia_backfill_memory_tables();
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
 * One-time backfill: every user who already has a 'kounselia_core_memory'
 * JSON blob gets it split into the new normalized memory tables, via the
 * same save routine new writes use (see memory-store.php). Safe to run
 * more than once — kounselia_memory_save_profile() always replaces a
 * user's rows wholesale, so re-running just re-writes the same data.
 * The original usermeta blob is left in place; it becomes a generated
 * cache going forward (see memory-store.php) rather than dead data.
 */
function kounselia_backfill_memory_tables() {
    global $wpdb;

    if ( ! function_exists( 'kounselia_memory_save_profile' ) ) {
        return; // memory-store.php not loaded yet; nothing to do this pass.
    }

    $rows = $wpdb->get_results(
        "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'kounselia_core_memory'"
    );

    foreach ( $rows as $row ) {
        $decoded = json_decode( $row->meta_value, true );
        if ( is_array( $decoded ) ) {
            kounselia_memory_save_profile( (int) $row->user_id, $decoded, false ); // false = don't rewrite the meta cache, it's already correct
        }
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
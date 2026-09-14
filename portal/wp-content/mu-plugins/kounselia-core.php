<?php
/**
 * Plugin Name: Kounselia Core
 * Description: Custom tables and AJAX auth endpoints for the Kounselia front-end app.
 *              Auth itself rides on native WordPress users (wp_users / wp_usermeta).
 *              Everything app-specific (sessions, messages, guest limits) lives in
 *              dedicated custom tables, not postmeta/usermeta.
 *
 * Drop this file in: /portal/wp-content/mu-plugins/kounselia-core.php
 * (mu-plugins load automatically, no activation step, no risk of accidental deactivation)
 *
 * This file is a loader only. WordPress only auto-loads .php files that sit
 * directly in mu-plugins/, not in subdirectories — so this stays a single
 * top-level file, and everything else lives in ./kounselia/includes/ and is
 * pulled in below with require_once. Also copy the whole `kounselia/`
 * directory alongside this file when deploying.
 *
 * Load order matters only in that a module must be required before anything
 * that calls its functions at include time (nothing here does — every
 * module only registers hooks/functions, it doesn't call across modules
 * until WordPress actually fires those hooks). The order below just follows
 * the original file's section numbering (1 → 19) for easy comparison.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // No direct access.
}

define( 'KOUNSELIA_CORE_DIR', __DIR__ . '/kounselia' );

/* -------------------------------------------------------------------------
 * GLOBAL EMAIL OVERRIDES
 * Force WordPress to stop sending as "WordPress <wordpress@domain.com>"
 * ---------------------------------------------------------------------- */
add_filter( 'wp_mail_from_name', function( $original_name ) {
    return 'Kounselia';
} );

add_filter( 'wp_mail_from', function( $original_email ) {
    // Strip any www. to ensure a clean domain for the email address
    $domain = strtolower( $_SERVER['SERVER_NAME'] );
    if ( substr( $domain, 0, 4 ) === 'www.' ) {
        $domain = substr( $domain, 4 );
    }
    return 'hello@' . $domain; // Sends from hello@kounselia.com
} );

require_once KOUNSELIA_CORE_DIR . '/includes/memory-store.php';      // 0.  Normalized memory table read/write layer (used by schema.php's backfill)
require_once KOUNSELIA_CORE_DIR . '/includes/schema.php';            // 1.  Custom tables, install/upgrade, backfills
require_once KOUNSELIA_CORE_DIR . '/includes/security-helpers.php';  // 2.  Nonce / honeypot / rate limit
require_once KOUNSELIA_CORE_DIR . '/includes/personas.php';          // 2B. Counselor personas & default prompts
require_once KOUNSELIA_CORE_DIR . '/includes/emails.php';            // NEW: Branded HTML Email Engine
require_once KOUNSELIA_CORE_DIR . '/includes/auth.php';              // 3-5. Login, register, logout
require_once KOUNSELIA_CORE_DIR . '/includes/dashboard.php';         // 6.  Dashboard helpers
require_once KOUNSELIA_CORE_DIR . '/includes/account.php';           // 7-9. Avatar, profile, password
require_once KOUNSELIA_CORE_DIR . '/includes/chat-helpers.php';      // 10. Session/guest-limit/safety/Gemini-client helpers
require_once KOUNSELIA_CORE_DIR . '/includes/safety-escalation.php'; // 10B. Safety escalation: severity, staff alerts, acknowledgment
require_once KOUNSELIA_CORE_DIR . '/includes/chat-endpoint.php';     // 11. Main chat AJAX endpoint
require_once KOUNSELIA_CORE_DIR . '/includes/voice.php';             // 12-13. TTS + Gemini Live voice
require_once KOUNSELIA_CORE_DIR . '/includes/resume.php';            // 14. Resuming a conversation
require_once KOUNSELIA_CORE_DIR . '/includes/mood.php';              // 15. Mood check-in
require_once KOUNSELIA_CORE_DIR . '/includes/journal.php';           // 16. Private journal
require_once KOUNSELIA_CORE_DIR . '/includes/password-reset.php';    // 17. Forgot password
require_once KOUNSELIA_CORE_DIR . '/includes/memory.php';            // 18. Structured memory engine (import + delta synth)
require_once KOUNSELIA_CORE_DIR . '/includes/check-ins.php';         // 18B. Smart Check-ins: dated events extracted from memory synthesis
require_once KOUNSELIA_CORE_DIR . '/includes/admin-access.php';      // 19. Admin capability, staff role, audit log
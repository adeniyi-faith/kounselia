<?php
/**
 * STREAMING_CHUNK:Initializing admin settings and processing actions...
 * Kounselia Admin — Platform Settings.
 *
 * Platform-wide configuration, API keys, usage limits, and maintenance:
 *   1. Gemini API key rotation pool and connection testing.
 *   2. Safety keyword list management (single and bulk).
 *   3. Active cache maintenance (TTS audio transient purging).
 *   4. Security rate-limit resets.
 *   5. Database schema verification.
 *   6. Dynamic platform configuration limits.
 */
$kounselia_admin_active = 'settings';
require_once __DIR__ . '/../inc/admin-auth.php';
require_once __DIR__ . '/../inc/admin-helpers.php';

$kounselia_notice = '';
$kounselia_error  = '';

// -------------------------------------------------------------------------
// POST Action Handling & Nonce Verification
// -------------------------------------------------------------------------
if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['kounselia_action'] ) ) {

    $kounselia_action = sanitize_key( $_POST['kounselia_action'] );

    // 1. Save Gemini API Keys
    if ( 'save_gemini_keys' === $kounselia_action
        && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'kounselia_settings_gemini' ) ) {

        $raw  = isset( $_POST['gemini_keys'] ) ? (string) wp_unslash( $_POST['gemini_keys'] ) : '';
        $keys = array_filter( array_map( 'trim', explode( ',', str_replace( array( "\r", "\n" ), ',', $raw ) ) ) );
        $keys = array_values( array_unique( $keys ) );

        update_option( 'kounselia_gemini_key', implode( ',', $keys ) );
        kounselia_admin_log( 'update_settings', 'settings' );

        $kounselia_notice = count( $keys ) . ' Gemini API key' . ( 1 === count( $keys ) ? '' : 's' ) . ' saved in active rotation pool.';

    // 2. Test Gemini API Connectivity
    } elseif ( 'test_gemini' === $kounselia_action
        && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'kounselia_settings_gemini_test' ) ) {

        $kounselia_test_error = '';
        $kounselia_started    = microtime( true );

        $kounselia_reply = kounselia_call_gemini(
            'Reply with exactly one short sentence confirming you can hear this test message and state your system status as operational.',
            array( array(
                'role'  => 'user',
                'parts' => array( array( 'text' => 'Connection test from the Kounselia admin settings page.' ) ),
            ) ),
            'gemini-3.6-flash',
            0.3,
            60,
            $kounselia_test_error
        );
        $kounselia_elapsed_ms = round( ( microtime( true ) - $kounselia_started ) * 1000 );

        kounselia_admin_log( 'test_gemini_key', 'settings' );

        if ( null === $kounselia_reply ) {
            $kounselia_error = 'Connection test failed after ' . $kounselia_elapsed_ms . 'ms — ' . $kounselia_test_error;
        } else {
            $kounselia_notice = 'Connection test successful in ' . $kounselia_elapsed_ms . 'ms via gemini-3.6-flash. AI responded: "' . trim( $kounselia_reply ) . '"';
        }

    // 3. Single Safety Keyword Addition
    } elseif ( 'add_keyword' === $kounselia_action
        && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'kounselia_settings_keywords' ) ) {

        $kounselia_new_kw = isset( $_POST['new_keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['new_keyword'] ) ) : '';

        if ( '' !== trim( $kounselia_new_kw ) ) {
            $kounselia_keywords   = kounselia_get_safety_keywords();
            $kounselia_keywords[] = $kounselia_new_kw;
            kounselia_update_safety_keywords( $kounselia_keywords );
            kounselia_admin_log( 'add_safety_keyword', 'settings' );
            $kounselia_notice = 'Safety keyword added to moderation engine.';
        }

    // 4. Bulk Safety Keyword Import
    } elseif ( 'bulk_add_keywords' === $kounselia_action
        && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'kounselia_settings_keywords' ) ) {

        $raw_import = isset( $_POST['bulk_keywords'] ) ? (string) wp_unslash( $_POST['bulk_keywords'] ) : '';
        $new_terms  = array_filter( array_map( 'trim', explode( ',', str_replace( array( "\r", "\n" ), ',', $raw_import ) ) ) );

        if ( ! empty( $new_terms ) ) {
            $existing_keywords  = kounselia_get_safety_keywords();
            $merged_keywords    = array_merge( $existing_keywords, $new_terms );
            $updated_list       = kounselia_update_safety_keywords( $merged_keywords );
            $added_count        = count( $updated_list ) - count( $existing_keywords );
            
            kounselia_admin_log( 'bulk_import_safety_keywords', 'settings' );
            $kounselia_notice = 'Bulk import complete. Successfully merged ' . (int) $added_count . ' new unique keywords into the moderation list.';
        } else {
            $kounselia_error = 'No valid text was detected in the bulk import payload.';
        }

    // 5. Remove Safety Keyword
    } elseif ( 'remove_keyword' === $kounselia_action
        && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'kounselia_settings_keywords' ) ) {

        $kounselia_remove   = isset( $_POST['keyword'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) ) ) : '';
        $kounselia_keywords = array_filter( kounselia_get_safety_keywords(), function ( $kw ) use ( $kounselia_remove ) {
            return $kw !== $kounselia_remove;
        } );
        kounselia_update_safety_keywords( $kounselia_keywords );
        kounselia_admin_log( 'remove_safety_keyword', 'settings' );
        $kounselia_notice = 'Keyword removed from moderation list.';

    // 6. Reset Keywords
    } elseif ( 'reset_keywords' === $kounselia_action
        && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'kounselia_settings_keywords' ) ) {

        kounselia_update_safety_keywords( kounselia_default_safety_keywords() );
        kounselia_admin_log( 'reset_safety_keywords', 'settings' );
        $kounselia_notice = 'Safety keyword list reset to built-in clinical defaults.';

    // 7. Flush TTS Audio Cache
    } elseif ( 'flush_tts_cache' === $kounselia_action
        && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'kounselia_settings_maintenance' ) ) {

        global $wpdb;
        $deleted = $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_kounselia\_tts\_%' OR option_name LIKE '\_transient\_timeout\_kounselia\_tts\_%'" );
        kounselia_admin_log( 'flush_tts_cache', 'settings' );
        $kounselia_notice = 'TTS audio cache purged successfully. ' . (int) ( $deleted / 2 ) . ' cached voice messages cleared from database.';

    // 8. Reset Rate Limits
    } elseif ( 'reset_rate_limits' === $kounselia_action
        && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'kounselia_settings_maintenance' ) ) {

        global $wpdb;
        $deleted = $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_kounselia\_rl\_%' OR option_name LIKE '\_transient\_timeout\_kounselia\_rl\_%'" );
        kounselia_admin_log( 'reset_rate_limits', 'settings' );
        $kounselia_notice = 'Rate limits reset. Cleared ' . (int) ( $deleted / 2 ) . ' active IP/token temporary lockouts.';

    // 9. Force DB Upgrade
    } elseif ( 'force_db_upgrade' === $kounselia_action
        && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'kounselia_settings_maintenance' ) ) {

        if ( function_exists( 'kounselia_install_tables' ) ) {
            delete_option( 'kounselia_db_version' );
            kounselia_install_tables();
            kounselia_admin_log( 'force_db_schema_sync', 'settings' );
            $kounselia_notice = 'Database schema verification complete. All custom tables have been checked and synchronized.';
        } else {
            $kounselia_error = 'Core table installation function not found. Ensure kounselia-core.php is loaded.';
        }
        
    // 10. Save Safety Alert Recipients
    } elseif ( 'save_safety_alert_emails' === $kounselia_action
        && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'kounselia_settings_safety_alerts' ) ) {

        $raw    = isset( $_POST['safety_alert_emails'] ) ? (string) wp_unslash( $_POST['safety_alert_emails'] ) : '';
        $parts  = array_filter( array_map( 'trim', explode( ',', str_replace( array( "\r", "\n" ), ',', $raw ) ) ) );
        $emails = array_values( array_unique( array_filter( array_map( 'sanitize_email', $parts ), 'is_email' ) ) );

        update_option( 'kounselia_safety_alert_emails', implode( ',', $emails ) );
        kounselia_admin_log( 'update_safety_alert_emails', 'settings' );

        $kounselia_notice = empty( $emails )
            ? 'Safety alert recipients cleared — critical alerts will go to every admin/staff account again.'
            : count( $emails ) . ' safety alert recipient' . ( 1 === count( $emails ) ? '' : 's' ) . ' saved.';

    // 11. Save Platform Settings
    } elseif ( 'save_platform_settings' === $kounselia_action
        && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'kounselia_settings_platform' ) ) {
            
        update_option( 'kounselia_guest_session_limit', absint( $_POST['guest_session_limit'] ) );
        update_option( 'kounselia_guest_daily_cap', absint( $_POST['guest_daily_cap'] ) );
        update_option( 'kounselia_voice_free_minutes', absint( $_POST['voice_free_minutes'] ) );
        update_option( 'kounselia_voice_pro_minutes', absint( $_POST['voice_pro_minutes'] ) );
        update_option( 'kounselia_live_model', sanitize_text_field( wp_unslash( $_POST['live_model'] ) ) );
        
        kounselia_admin_log( 'update_platform_settings', 'settings' );
        $kounselia_notice = 'Platform configuration saved successfully.';
    }
}

// -------------------------------------------------------------------------
// Data Retrieval & State Preparation
// -------------------------------------------------------------------------
$kounselia_gemini_keys       = kounselia_get_gemini_keys();
$kounselia_gemini_key_count  = count( $kounselia_gemini_keys );
$kounselia_gemini_key_source = ( empty( get_option( 'kounselia_gemini_key', '' ) ) && defined( 'KOUNSELIA_GEMINI_KEY' ) && $kounselia_gemini_key_count )
    ? 'wp-config.php constant (KOUNSELIA_GEMINI_KEY)'
    : 'database option';

$kounselia_safety_keywords = kounselia_get_safety_keywords();
sort( $kounselia_safety_keywords );

$kounselia_safety_alert_emails = get_option( 'kounselia_safety_alert_emails', '' );

global $wpdb;
$active_tts_transients = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_kounselia\_tts\_%'" );
$active_rl_transients  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_kounselia\_rl\_%'" );
$current_db_version    = get_option( 'kounselia_db_version', 'Unknown' );

// Retrieve platform limits for the form
$opt_guest_session = (int) get_option('kounselia_guest_session_limit', 6);
$opt_guest_daily   = (int) get_option('kounselia_guest_daily_cap', 40);
$opt_voice_free    = (int) get_option('kounselia_voice_free_minutes', 5);
$opt_voice_pro     = (int) get_option('kounselia_voice_pro_minutes', 15);
$opt_live_model    = get_option('kounselia_live_model', 'gemini-3.1-flash-live-preview');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Platform Settings — Kounselia Admin</title>
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#1E3A5F">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<?php require __DIR__ . '/../inc/admin-styles.php'; ?>
<style>
  .op-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-top: 16px; }
  .op-card { background: var(--bg2, #F8FAFC); border: 1px solid var(--border); border-radius: 8px; padding: 14px; }
  .op-card .op-label { font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text3); font-weight: 600; margin-bottom: 6px; display: block; }
  .op-card .op-val-input { width: 100%; padding: 8px 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 14px; font-family: inherit; margin-bottom: 6px; background: var(--bg); color: var(--text1); transition: all 0.2s ease; }
  .op-card .op-val-input:focus { outline: none; border-color: var(--teal); box-shadow: 0 0 0 3px rgba(30,58,95,0.08); }
  .op-card .op-desc { font-size: 12px; color: var(--text2); margin-top: 4px; line-height: 1.4; }
  .maint-row { display: flex; align-items: center; justify-content: space-between; padding: 14px 0; border-bottom: 1px solid var(--border); gap: 16px; flex-wrap: wrap; }
  .maint-row:last-child { border-bottom: none; padding-bottom: 0; }
  .maint-info h4 { font-size: 14.5px; font-weight: 600; color: var(--text1); margin: 0 0 4px 0; }
  .maint-info p { font-size: 13px; color: var(--text2); margin: 0; max-width: 54ch; line-height: 1.45; }
  textarea.bulk-input { width: 100%; min-height: 80px; padding: 10px; border: 1px solid var(--border); border-radius: 6px; font-family: inherit; font-size: 13px; color: var(--text1); background: var(--bg); resize: vertical; margin-bottom: 10px; }
  textarea.bulk-input:focus { outline: none; border-color: var(--teal); box-shadow: 0 0 0 3px rgba(30,58,95,0.08); }
</style>
</head>
<body>
<?php require __DIR__ . '/../inc/admin-nav.php'; ?>

<div class="admin-body">
  <h1 class="admin-title">Platform Settings</h1>
  <p class="admin-subtitle">Manage AI models, API keys, platform usage limits, and maintenance tasks.</p>

  <?php if ( $kounselia_notice ) : ?>
    <div class="login-msg notice" style="margin-bottom:20px;"><?php echo esc_html( $kounselia_notice ); ?></div>
  <?php endif; ?>
  <?php if ( $kounselia_error ) : ?>
    <div class="login-msg error" style="margin-bottom:20px;"><?php echo esc_html( $kounselia_error ); ?></div>
  <?php endif; ?>

  <!-- ===============================================================
       1. AI PROVIDER & KEY MANAGEMENT
  ================================================---------------- -->
  <div class="panel">
    <div class="panel-title">
      Gemini API Key Rotation Pool
      <span style="font-weight:400;color:var(--text3);font-size:12px;">
        <?php echo (int) $kounselia_gemini_key_count; ?> key<?php echo 1 === $kounselia_gemini_key_count ? '' : 's'; ?> configured · <?php echo esc_html( $kounselia_gemini_key_source ); ?>
      </span>
    </div>

    <p style="color:var(--text2);font-size:13px;margin-bottom:16px;line-height:1.55;max-width:64ch;">
      Every text chat, TTS generation, and memory extraction call is routed through <code>kounselia_call_gemini()</code>. The engine picks one key at random per request to distribute API quotas across multiple Google Cloud projects. 
    </p>

    <form method="post">
      <?php wp_nonce_field( 'kounselia_settings_gemini' ); ?>
      <input type="hidden" name="kounselia_action" value="save_gemini_keys">
      <div class="login-field">
        <label for="gemini_keys">API Keys (comma or newline separated)</label>
        <div class="pw-wrap">
          <input
            type="password"
            id="gemini_keys"
            name="gemini_keys"
            autocomplete="off"
            spellcheck="false"
            style="font-family:'SF Mono',Menlo,Consolas,monospace;font-size:13px;"
            value="<?php echo esc_attr( implode( ',', $kounselia_gemini_keys ) ); ?>"
            placeholder="AIzaSy..., AIzaSy...">
          <button type="button" class="pw-toggle" id="geminiKeyToggle" aria-label="Show keys" aria-pressed="false">
            <svg id="geminiKeyIconOpen" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg id="geminiKeyIconClosed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a20.6 20.6 0 0 1 5.06-6.06M9.9 4.24A10.4 10.4 0 0 1 12 4c7 0 11 8 11 8a20.7 20.7 0 0 1-3.22 4.44M14.12 14.12a3 3 0 1 1-4.24-4.24"/><path d="M1 1l22 22"/></svg>
          </button>
        </div>
      </div>
      <button type="submit" class="login-submit" style="width:auto;padding:11px 22px;">Save Keys to Pool</button>
    </form>

    <form method="post" style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border);display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
      <?php wp_nonce_field( 'kounselia_settings_gemini_test' ); ?>
      <input type="hidden" name="kounselia_action" value="test_gemini">
      <button type="submit" class="login-submit" style="width:auto;padding:10px 20px;background:var(--teal);">Run AI Connection Test</button>
      <span style="font-size:12px;color:var(--text3);">Fires a live request through the model cascade and benchmarks round-trip network latency.</span>
    </form>
  </div>

  <!-- ===============================================================
       2. SAFETY GUARDRAILS & MODERATION ENGINE
  ================================================---------------- -->
  <div class="panel">
    <div class="panel-title">
      Safety Keyword Guardrail List
      <span style="font-weight:400;color:var(--text3);font-size:12px;"><?php echo count( $kounselia_safety_keywords ); ?> active triggers</span>
    </div>

    <p style="color:var(--text2);font-size:13px;margin-bottom:16px;line-height:1.55;max-width:64ch;">
      Every message typed by a user is evaluated against this list before database insertion. A match flags the row for clinical review on the Safety console. This is a fast first net; expect false positives when users quote external conversations.
    </p>

    <!-- Single Addition Form -->
    <form method="post" class="filters" style="margin-bottom:16px;">
      <?php wp_nonce_field( 'kounselia_settings_keywords' ); ?>
      <input type="hidden" name="kounselia_action" value="add_keyword">
      <input type="text" name="new_keyword" placeholder="Add a single keyword or phrase…" required style="flex:1;min-width:240px;">
      <button type="submit">Add Single Term</button>
    </form>

    <!-- Bulk Import Form -->
    <form method="post" style="margin-bottom:20px;padding:14px;background:var(--bg2, #F8FAFC);border:1px solid var(--border);border-radius:6px;">
      <?php wp_nonce_field( 'kounselia_settings_keywords' ); ?>
      <input type="hidden" name="kounselia_action" value="bulk_add_keywords">
      <label style="display:block;font-size:12px;font-weight:600;color:var(--text1);margin-bottom:6px;text-transform:uppercase;letter-spacing:0.04em;">Bulk Ingest Keywords</label>
      <textarea name="bulk_keywords" class="bulk-input" placeholder="Paste multiple terms separated by commas or newlines (e.g. self harm, hopeless, end it all)..."></textarea>
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <span style="font-size:11.5px;color:var(--text3);">Duplicates and empty lines are stripped automatically upon import.</span>
        <button type="submit" class="login-submit" style="width:auto;padding:8px 16px;font-size:12.5px;">Bulk Import Terms</button>
      </div>
    </form>

    <!-- Active Keyword Badges -->
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px;max-height:240px;overflow-y:auto;padding:4px;border:1px solid rgba(0,0,0,0.04);border-radius:6px;">
      <?php foreach ( $kounselia_safety_keywords as $kounselia_kw ) : ?>
        <form method="post" style="display:inline-flex;">
          <?php wp_nonce_field( 'kounselia_settings_keywords' ); ?>
          <input type="hidden" name="kounselia_action" value="remove_keyword">
          <input type="hidden" name="keyword" value="<?php echo esc_attr( $kounselia_kw ); ?>">
          <button
            type="submit"
            class="badge ended"
            title="Remove &ldquo;<?php echo esc_attr( $kounselia_kw ); ?>&rdquo;"
            style="border:none;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-family:inherit;background:#FFF;border:1px solid var(--border);padding:5px 10px;border-radius:4px;font-size:12.5px;">
            <?php echo esc_html( $kounselia_kw ); ?> <span style="color:var(--rose);font-weight:700;font-size:14px;">&times;</span>
          </button>
        </form>
      <?php endforeach; ?>
      <?php if ( empty( $kounselia_safety_keywords ) ) : ?>
        <div class="empty-state" style="padding:16px;width:100%;text-align:center;">No keywords configured — automatic safety flagging is currently disabled.</div>
      <?php endif; ?>
    </div>

    <!-- Reset Form -->
    <form method="post" onsubmit="return confirm('Reset the keyword list to built-in clinical defaults? This replaces any custom keywords added by your supervision team.');">
      <?php wp_nonce_field( 'kounselia_settings_keywords' ); ?>
      <input type="hidden" name="kounselia_action" value="reset_keywords">
      <button type="submit" class="clear" style="background:none;border:none;font-size:12.5px;cursor:pointer;font-family:inherit;padding:0;color:var(--rose);text-decoration:underline;">Reset list to built-in defaults</button>
    </form>
  </div>

  <!-- ===============================================================
       2B. SAFETY ALERT RECIPIENTS
  ================================================---------------- -->
  <div class="panel">
    <div class="panel-title">
      Safety Alert Recipients
      <span style="font-weight:400;color:var(--text3);font-size:12px;">
        <?php echo empty( $kounselia_safety_alert_emails ) ? 'currently: every admin/staff account' : count( array_filter( explode( ',', $kounselia_safety_alert_emails ) ) ) . ' custom recipient(s)'; ?>
      </span>
    </div>
    <p style="color:var(--text2);font-size:13px;margin-bottom:16px;line-height:1.55;max-width:64ch;">
      When a message matches a critical safety phrase, an alert email goes out immediately. By default that goes to every admin and staff account. Set a specific on-call list here instead — useful once the team grows and not everyone needs to be paged for every case. Leave blank to go back to alerting everyone.
    </p>
    <form method="post">
      <?php wp_nonce_field( 'kounselia_settings_safety_alerts' ); ?>
      <input type="hidden" name="kounselia_action" value="save_safety_alert_emails">
      <div class="login-field">
        <label for="safety_alert_emails">On-call emails (comma or newline separated)</label>
        <textarea id="safety_alert_emails" name="safety_alert_emails" class="bulk-input" placeholder="oncall@kounselia.com, clinical-lead@kounselia.com"><?php echo esc_textarea( implode( ",\n", array_filter( explode( ',', $kounselia_safety_alert_emails ) ) ) ); ?></textarea>
      </div>
      <button type="submit" class="login-submit" style="width:auto;padding:10px 20px;">Save Recipients</button>
    </form>
  </div>

  <!-- ===============================================================
       3. PLATFORM CONFIGURATION & LIMITS
  ================================================---------------- -->
  <div class="panel">
    <div class="panel-title">
      Platform Configuration
      <span style="font-weight:400;color:var(--text3);font-size:12px;">manage usage limits and models</span>
    </div>
    <p style="color:var(--text2);font-size:13px;margin-bottom:16px;line-height:1.55;max-width:64ch;">
      Adjust the global limits for guest users, voice session durations, and the active live streaming model.
    </p>

    <form method="post">
      <?php wp_nonce_field( 'kounselia_settings_platform' ); ?>
      <input type="hidden" name="kounselia_action" value="save_platform_settings">
      
      <div class="op-grid">
        <div class="op-card">
          <label class="op-label" for="guest_session_limit">Guest Message Cap / Session</label>
          <input type="number" id="guest_session_limit" name="guest_session_limit" class="op-val-input" value="<?php echo esc_attr( $opt_guest_session ); ?>">
          <div class="op-desc">Enforced per conversation thread before requiring account creation.</div>
        </div>
        <div class="op-card">
          <label class="op-label" for="guest_daily_cap">Guest Sitewide Daily Cap</label>
          <input type="number" id="guest_daily_cap" name="guest_daily_cap" class="op-val-input" value="<?php echo esc_attr( $opt_guest_daily ); ?>">
          <div class="op-desc">Max messages per 24h window per browser/IP.</div>
        </div>
        <div class="op-card">
          <label class="op-label" for="voice_free_minutes">Free Plan Voice Allocation</label>
          <input type="number" id="voice_free_minutes" name="voice_free_minutes" class="op-val-input" value="<?php echo esc_attr( $opt_voice_free ); ?>">
          <div class="op-desc">Continuous live voice session limit for standard members (minutes).</div>
        </div>
        <div class="op-card">
          <label class="op-label" for="voice_pro_minutes">Pro Plan Voice Allocation</label>
          <input type="number" id="voice_pro_minutes" name="voice_pro_minutes" class="op-val-input" value="<?php echo esc_attr( $opt_voice_pro ); ?>">
          <div class="op-desc">Extended live voice session cap for upgraded Pro members (minutes).</div>
        </div>
        <div class="op-card">
          <label class="op-label" for="live_model">Live Audio Streaming Model</label>
          <input type="text" id="live_model" name="live_model" class="op-val-input" value="<?php echo esc_attr( $opt_live_model ); ?>">
          <div class="op-desc">Client-to-server persistent WebSocket model for voice therapy.</div>
        </div>
      </div>
      <div style="margin-top: 20px;">
        <button type="submit" class="login-submit" style="width:auto;padding:10px 20px;">Save Configuration</button>
      </div>
    </form>
  </div>

  <!-- ===============================================================
       4. SYSTEM MAINTENANCE & CACHE OPERATIONS
  ================================================---------------- -->
  <div class="panel">
    <div class="panel-title">
      System Maintenance
      <span style="font-weight:400;color:var(--text3);font-size:12px;">cache purges &amp; lockouts</span>
    </div>
    <p style="color:var(--text2);font-size:13px;margin-bottom:8px;line-height:1.55;max-width:64ch;">
      Direct operational overrides for temporary storage layers and security turnstiles.
    </p>

    <!-- Maintenance Action: Flush TTS -->
    <div class="maint-row">
      <div class="maint-info">
        <h4>Purge Voice Reply Cache (TTS)</h4>
        <p>Clears stored base64 audio strings. Force this when changing a counselor's voice model so members immediately hear updated synthesized speech instead of cached audio.</p>
      </div>
      <form method="post">
        <?php wp_nonce_field( 'kounselia_settings_maintenance' ); ?>
        <input type="hidden" name="kounselia_action" value="flush_tts_cache">
        <button type="submit" class="login-submit" style="width:auto;padding:9px 18px;background:var(--text1);font-size:12.5px;">Purge Audio Cache (<?php echo (int) $active_tts_transients; ?> active)</button>
      </form>
    </div>

    <!-- Maintenance Action: Reset Rate Limits -->
    <div class="maint-row">
      <div class="maint-info">
        <h4>Reset Security Rate Limits</h4>
        <p>Resets all temporary IP and guest token lockouts. Use this immediately if an organization or school sharing a public IP address gets temporarily locked out during group usage.</p>
      </div>
      <form method="post" onsubmit="return confirm('Clear all active rate-limit turnstiles across the platform?');">
        <?php wp_nonce_field( 'kounselia_settings_maintenance' ); ?>
        <input type="hidden" name="kounselia_action" value="reset_rate_limits">
        <button type="submit" class="login-submit" style="width:auto;padding:9px 18px;background:#475569;font-size:12.5px;">Reset Turnstiles (<?php echo (int) $active_rl_transients; ?> locked)</button>
      </form>
    </div>

    <!-- Maintenance Action: Force DB Delta -->
    <div class="maint-row">
      <div class="maint-info">
        <h4>Verify &amp; Repair Database Schema</h4>
        <p>Forces WordPress to verify and synchronize all custom Kounselia tables. Use this after restoring database backups or migrating servers to ensure index integrity.</p>
      </div>
      <form method="post" onsubmit="return confirm('Re-run schema verification across all custom tables?');">
        <?php wp_nonce_field( 'kounselia_settings_maintenance' ); ?>
        <input type="hidden" name="kounselia_action" value="force_db_upgrade">
        <button type="submit" class="login-submit" style="width:auto;padding:9px 18px;background:#334155;font-size:12.5px;">Sync Schema (v<?php echo esc_html( $current_db_version ); ?>)</button>
      </form>
    </div>
  </div>

</div>

<script>
(function(){
  // Password reveal toggle for Gemini API key display
  var btn = document.getElementById('geminiKeyToggle');
  var input = document.getElementById('gemini_keys');
  var open = document.getElementById('geminiKeyIconOpen');
  var closed = document.getElementById('geminiKeyIconClosed');
  if(!btn || !input) return;
  btn.addEventListener('click', function(){
    var showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    open.style.display = showing ? '' : 'none';
    closed.style.display = showing ? 'none' : '';
    btn.setAttribute('aria-label', showing ? 'Show keys' : 'Hide keys');
    btn.setAttribute('aria-pressed', showing ? 'false' : 'true');
  });
})();
</script>
</body>
</html>
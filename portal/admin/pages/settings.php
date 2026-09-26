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

    // 10B. AI risk check on/off
    } elseif ( 'save_ai_safety_screening' === $kounselia_action
        && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'kounselia_settings_ai_safety' ) ) {

        update_option( 'kounselia_ai_safety_screening', ! empty( $_POST['ai_safety_screening'] ) ? 1 : 0 );
        kounselia_admin_log( 'update_ai_safety_screening', 'settings' );
        $kounselia_notice = ! empty( $_POST['ai_safety_screening'] ) ? 'AI risk check turned on.' : 'AI risk check turned off — only keyword matches will be flagged.';

    // 11B. Save Paystack Secret Key
    } elseif ( 'save_paystack_key' === $kounselia_action
        && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'kounselia_settings_paystack' ) ) {

        $secret_key = isset( $_POST['paystack_secret_key'] ) ? trim( (string) wp_unslash( $_POST['paystack_secret_key'] ) ) : '';
        $public_key = isset( $_POST['paystack_public_key'] ) ? trim( (string) wp_unslash( $_POST['paystack_public_key'] ) ) : '';

        if ( '' !== $secret_key && ! preg_match( '/^sk_(test|live)_\w+$/', $secret_key ) ) {
            $kounselia_error = 'That secret key doesn\'t look right — Paystack secret keys start with sk_test_ or sk_live_.';
        } elseif ( '' !== $public_key && ! preg_match( '/^pk_(test|live)_\w+$/', $public_key ) ) {
            $kounselia_error = 'That public key doesn\'t look right — Paystack public keys start with pk_test_ or pk_live_.';
        } else {
            update_option( 'kounselia_paystack_secret_key', sanitize_text_field( $secret_key ) );
            update_option( 'kounselia_paystack_public_key', sanitize_text_field( $public_key ) );
            kounselia_admin_log( 'update_settings', 'settings' );
            $kounselia_notice = $secret_key ? 'Paystack keys saved.' : 'Paystack secret key cleared — subscriptions are disabled until a key is set.';
            if ( $secret_key && $public_key && ( 0 === strpos( $secret_key, 'sk_live_' ) ) !== ( 0 === strpos( $public_key, 'pk_live_' ) ) ) {
                $kounselia_notice .= ' Warning: one key is a test key and the other is live — they must match.';
            }
        }

    // 11E. Currency & pricing
    } elseif ( 'save_currency_settings' === $kounselia_action
        && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'kounselia_settings_currency' ) ) {

        $mode = isset( $_POST['currency_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['currency_mode'] ) ) : 'auto';
        update_option( 'kounselia_currency_mode', in_array( $mode, array( 'auto', 'NGN', 'USD' ), true ) ? $mode : 'auto' );

        $countries = isset( $_POST['naira_countries'] ) ? strtoupper( (string) wp_unslash( $_POST['naira_countries'] ) ) : 'NG';
        $countries = array_values( array_unique( array_filter( array_map( 'trim', explode( ',', $countries ) ), function ( $c ) {
            return (bool) preg_match( '/^[A-Z]{2}$/', $c );
        } ) ) );
        update_option( 'kounselia_naira_countries', $countries ? implode( ',', $countries ) : 'NG' );

        $unknown = isset( $_POST['unknown_currency'] ) ? sanitize_text_field( wp_unslash( $_POST['unknown_currency'] ) ) : 'USD';
        update_option( 'kounselia_currency_unknown_default', in_array( $unknown, array( 'NGN', 'USD' ), true ) ? $unknown : 'USD' );

        $rate = isset( $_POST['usd_ngn_rate'] ) ? (float) $_POST['usd_ngn_rate'] : 0;
        if ( $rate > 0 ) {
            update_option( 'kounselia_usd_ngn_rate', $rate );
        }

        update_option( 'kounselia_geo_lookup_enabled', ! empty( $_POST['geo_lookup_enabled'] ) ? 1 : 0 );
        update_option( 'kounselia_trust_country_header', ! empty( $_POST['trust_country_header'] ) ? 1 : 0 );

        kounselia_admin_log( 'update_currency_settings', 'settings' );
        $kounselia_notice = $rate > 0 ? 'Currency settings saved.' : 'Currency settings saved (exchange rate unchanged — it must be greater than zero).';

    // 11C. Finish / resend OTP for a payout Paystack is holding
    } elseif ( in_array( $kounselia_action, array( 'finalize_payout_otp', 'resend_payout_otp' ), true )
        && current_user_can( 'administrator' )
        && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'kounselia_settings_payout_otp' ) ) {

        $payout_id = isset( $_POST['payout_id'] ) ? absint( $_POST['payout_id'] ) : 0;

        if ( 'finalize_payout_otp' === $kounselia_action ) {
            $otp    = isset( $_POST['otp'] ) ? preg_replace( '/\D/', '', (string) wp_unslash( $_POST['otp'] ) ) : '';
            $result = $otp ? kounselia_finalize_payout_otp( $payout_id, $otp ) : new WP_Error( 'missing', 'Please enter the code Paystack sent you.' );
            if ( is_wp_error( $result ) ) {
                $kounselia_error = $result->get_error_message();
            } else {
                kounselia_admin_log( 'finalize_payout', 'payout', $payout_id );
                $kounselia_notice = 'success' === $result
                    ? 'Payout #' . $payout_id . ' approved and sent.'
                    : 'Code accepted — Paystack is still processing payout #' . $payout_id . '. It will update automatically.';
            }
        } else {
            $result = kounselia_resend_payout_otp( $payout_id );
            if ( is_wp_error( $result ) ) {
                $kounselia_error = $result->get_error_message();
            } else {
                $kounselia_notice = 'A new code for payout #' . $payout_id . ' has been sent to the Paystack account owner.';
            }
        }

    // 11D. Check pending payouts with Paystack right now
    } elseif ( 'check_payouts_now' === $kounselia_action
        && current_user_can( 'administrator' )
        && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'kounselia_settings_payout_otp' ) ) {

        kounselia_follow_up_pending_payouts();
        $kounselia_notice = 'Pending payouts re-checked with Paystack.';

    // 11. Save Platform Settings
    } elseif ( 'save_platform_settings' === $kounselia_action
        && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'kounselia_settings_platform' ) ) {
            
        update_option( 'kounselia_guest_session_limit', absint( $_POST['guest_session_limit'] ) );
        update_option( 'kounselia_guest_daily_cap', absint( $_POST['guest_daily_cap'] ) );
        update_option( 'kounselia_voice_free_minutes', absint( $_POST['voice_free_minutes'] ) );
        update_option( 'kounselia_voice_pro_minutes', absint( $_POST['voice_pro_minutes'] ) );
        update_option( 'kounselia_live_model', sanitize_text_field( wp_unslash( $_POST['live_model'] ) ) );
        update_option( 'kounselia_booking_commission_percent', max( 0, min( 100, (float) $_POST['booking_commission_percent'] ) ) );

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

$kounselia_paystack_key = get_option( 'kounselia_paystack_secret_key', '' );
$kounselia_paystack_public_key = get_option( 'kounselia_paystack_public_key', '' );
$kounselia_awaiting_payouts = function_exists( 'kounselia_get_awaiting_payouts' ) ? kounselia_get_awaiting_payouts() : array();
$kounselia_paystack_mode = $kounselia_paystack_key
    ? ( 0 === strpos( $kounselia_paystack_key, 'sk_live_' ) ? 'Live mode' : 'Test mode' )
    : 'Not configured';

// Retrieve platform limits for the form
$opt_guest_session = (int) get_option('kounselia_guest_session_limit', 6);
$opt_guest_daily   = (int) get_option('kounselia_guest_daily_cap', 40);
$opt_voice_free    = (int) get_option('kounselia_voice_free_minutes', 5);
$opt_voice_pro     = (int) get_option('kounselia_voice_pro_minutes', 15);
$opt_live_model    = get_option('kounselia_live_model', 'gemini-3.1-flash-live-preview');
$opt_booking_commission_percent = (float) get_option( 'kounselia_booking_commission_percent', 15 );
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

    <form method="post" style="margin-top:18px;padding-top:16px;border-top:1px solid var(--border);">
      <?php wp_nonce_field( 'kounselia_settings_ai_safety' ); ?>
      <input type="hidden" name="kounselia_action" value="save_ai_safety_screening">
      <label style="display:flex;gap:8px;align-items:flex-start;font-size:13px;color:var(--text1);">
        <input type="checkbox" name="ai_safety_screening" value="1" <?php checked( function_exists( 'kounselia_ai_safety_screening_enabled' ) && kounselia_ai_safety_screening_enabled() ); ?> style="margin-top:3px;">
        <span><strong>AI risk check</strong> — every minute, member messages the keyword list didn't catch are read by the AI, which flags indirect or misspelled signs of risk. Critical ones alert the people above just like a keyword match. Runs in the background, so chats are not slowed down. Uses your Gemini API keys.</span>
      </label>
      <button type="submit" class="login-submit" style="width:auto;padding:10px 20px;margin-top:12px;">Save</button>
    </form>
  </div>

  <!-- ===============================================================
       2B-2. EMAIL DELIVERY (full settings on their own page)
  ================================================---------------- -->
  <?php if ( current_user_can( 'administrator' ) && function_exists( 'kounselia_mail_settings' ) ) :
      $kounselia_mail = kounselia_mail_settings(); ?>
  <div class="panel">
    <div class="panel-title">
      Email Delivery
      <span style="font-weight:400;color:var(--text3);font-size:12px;">Account emails: <?php echo 'brevo' === $kounselia_mail['account_provider'] ? 'Brevo' : 'default mail'; ?> · Newsletters: <?php echo 'brevo' === $kounselia_mail['newsletter_provider'] ? 'Brevo' : 'default mail'; ?></span>
    </div>
    <p style="color:var(--text2);font-size:13px;margin-bottom:16px;line-height:1.55;max-width:64ch;">
      Send emails through your server's default mailer or through Brevo, set Brevo's daily allowance (300/day on the free plan), send test emails and see the delivery log.
    </p>
    <a class="login-submit" style="display:inline-block;width:auto;padding:11px 22px;text-decoration:none;color:#fff;" href="/portal/admin/pages/email-delivery.php">Manage email delivery</a>
  </div>
  <?php endif; ?>

  <!-- ===============================================================
       2C. PAYSTACK PAYMENTS
  ================================================---------------- -->
  <div class="panel">
    <div class="panel-title">
      Paystack Payments
      <span style="font-weight:400;color:var(--text3);font-size:12px;"><?php echo esc_html( $kounselia_paystack_mode ); ?></span>
    </div>
    <p style="color:var(--text2);font-size:13px;margin-bottom:16px;line-height:1.55;max-width:64ch;">
      Powers the Pro plan checkout on the member dashboard. Paste your Paystack <strong>secret key</strong> here (starts with <code>sk_test_</code> or <code>sk_live_</code>) — find it under Settings → API Keys &amp; Webhooks in your Paystack dashboard. Manage what plans are for sale, their prices, and their features on the <a href="/portal/admin/pages/plans.php">Plans &amp; Pricing</a> page.
    </p>
    <form method="post">
      <?php wp_nonce_field( 'kounselia_settings_paystack' ); ?>
      <input type="hidden" name="kounselia_action" value="save_paystack_key">
      <div class="login-field">
        <label for="paystack_public_key">Paystack public key <span style="font-weight:400;color:var(--text3)">(optional)</span></label>
        <input
          type="text"
          id="paystack_public_key"
          name="paystack_public_key"
          autocomplete="off"
          spellcheck="false"
          style="width:100%;padding:11px 14px;border:1px solid var(--border);border-radius:6px;font-family:'SF Mono',Menlo,Consolas,monospace;font-size:13px;background:var(--bg);color:var(--text1);"
          value="<?php echo esc_attr( $kounselia_paystack_public_key ); ?>"
          placeholder="pk_test_...">
        <p style="font-size:12px;color:var(--text3);margin-top:6px;line-height:1.5;">With a public key, members pay in a secure Paystack pop-up without leaving their dashboard. Without one, they're sent to Paystack's page and back. The public key is safe to show in the browser; the secret key never is.</p>
      </div>
      <div class="login-field">
        <label for="paystack_secret_key">Paystack secret key</label>
        <input
          type="password"
          id="paystack_secret_key"
          name="paystack_secret_key"
          autocomplete="off"
          spellcheck="false"
          style="width:100%;padding:11px 14px;border:1px solid var(--border);border-radius:6px;font-family:'SF Mono',Menlo,Consolas,monospace;font-size:13px;background:var(--bg);color:var(--text1);"
          value="<?php echo esc_attr( $kounselia_paystack_key ); ?>"
          placeholder="sk_test_...">
      </div>
      <button type="submit" class="login-submit" style="width:auto;padding:11px 22px;">Save Keys</button>
    </form>

    <div style="margin-top:18px;padding-top:16px;border-top:1px solid var(--border);">
      <label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px;">Webhook URL</label>
      <p style="color:var(--text2);font-size:13px;margin-bottom:8px;line-height:1.55;max-width:64ch;">
        Paste this into the <strong>Webhook URL</strong> box under Settings → API Keys &amp; Webhooks in Paystack. It lets Paystack tell Kounselia about a payment even if the member closes their browser before being sent back. (An hourly background check also catches missed payments, but the webhook is instant.)
      </p>
      <input type="text" readonly onclick="this.select()" value="<?php echo esc_attr( function_exists( 'kounselia_paystack_webhook_url' ) ? kounselia_paystack_webhook_url() : '' ); ?>" style="width:100%;padding:11px 14px;border:1px solid var(--border);border-radius:6px;font-family:'SF Mono',Menlo,Consolas,monospace;font-size:13px;background:var(--bg);color:var(--text1);">
    </div>

    <div id="payouts" style="margin-top:18px;padding-top:16px;border-top:1px solid var(--border);">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:8px;">
        <label style="font-size:13px;font-weight:600;">Payouts waiting for approval</label>
        <?php if ( current_user_can( 'administrator' ) ) : ?>
          <form method="post">
            <?php wp_nonce_field( 'kounselia_settings_payout_otp' ); ?>
            <input type="hidden" name="kounselia_action" value="check_payouts_now">
            <button type="submit" class="login-submit" style="width:auto;padding:7px 14px;font-size:12px;">Check with Paystack now</button>
          </form>
        <?php endif; ?>
      </div>
      <p style="color:var(--text2);font-size:13px;margin-bottom:10px;line-height:1.55;max-width:64ch;">
        These are checked with Paystack automatically every 15 minutes. If your Paystack account requires a one-time code (OTP) for transfers, Paystack sends it to the account owner — enter it here to release the payout.
      </p>
      <?php if ( empty( $kounselia_awaiting_payouts ) ) : ?>
        <div style="color:var(--text3);font-size:13px;">No payouts are waiting right now.</div>
      <?php else : ?>
        <?php foreach ( $kounselia_awaiting_payouts as $kounselia_payout ) : ?>
          <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:10px 0;border-bottom:1px solid var(--border);">
            <div style="font-size:13px;">
              <strong>#<?php echo (int) $kounselia_payout->id; ?></strong>
              — <?php echo esc_html( $kounselia_payout->professional_name ?: 'Professional #' . $kounselia_payout->professional_id ); ?>
              — <?php echo esc_html( kounselia_format_money( $kounselia_payout->amount, $kounselia_payout->currency ) ); ?>
              <span style="color:var(--text3);">· requested <?php echo esc_html( $kounselia_payout->created_at ); ?><?php echo $kounselia_payout->last_checked_at ? ' · last checked ' . esc_html( $kounselia_payout->last_checked_at ) : ''; ?></span>
            </div>
            <?php if ( current_user_can( 'administrator' ) && $kounselia_payout->paystack_transfer_code ) : ?>
              <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <form method="post" style="display:flex;gap:6px;">
                  <?php wp_nonce_field( 'kounselia_settings_payout_otp' ); ?>
                  <input type="hidden" name="kounselia_action" value="finalize_payout_otp">
                  <input type="hidden" name="payout_id" value="<?php echo (int) $kounselia_payout->id; ?>">
                  <input type="text" name="otp" inputmode="numeric" autocomplete="one-time-code" placeholder="OTP code" style="width:110px;padding:7px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--text1);">
                  <button type="submit" class="login-submit" style="width:auto;padding:7px 14px;font-size:12px;">Approve</button>
                </form>
                <form method="post">
                  <?php wp_nonce_field( 'kounselia_settings_payout_otp' ); ?>
                  <input type="hidden" name="kounselia_action" value="resend_payout_otp">
                  <input type="hidden" name="payout_id" value="<?php echo (int) $kounselia_payout->id; ?>">
                  <button type="submit" style="padding:7px 12px;font-size:12px;border:1px solid var(--border);border-radius:6px;background:transparent;color:var(--text2);cursor:pointer;">Resend code</button>
                </form>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- ===============================================================
       2D. CURRENCY & PRICING
  ================================================---------------- -->
  <div class="panel" id="currency">
    <div class="panel-title">
      Currency &amp; Pricing
      <span style="font-weight:400;color:var(--text3);font-size:12px;">your currency right now: <?php echo esc_html( kounselia_viewer_currency() ); ?><?php $kounselia_my_country = kounselia_detect_country(); echo $kounselia_my_country ? ' (' . esc_html( $kounselia_my_country ) . ')' : ''; ?></span>
    </div>
    <p style="color:var(--text2);font-size:13px;margin-bottom:16px;line-height:1.55;max-width:64ch;">
      Visitors in the countries below see and pay in naira; everyone else sees and pays in US dollars. Professionals set their rates in naira and are always paid out in naira — an international client is shown the naira rate converted at the exchange rate below, and the professional still earns their naira rate minus commission. <strong>Dollar payments must be enabled on your Paystack account</strong> (Paystack → Settings → Preferences), or dollar checkouts will be refused.
    </p>
    <form method="post">
      <?php wp_nonce_field( 'kounselia_settings_currency' ); ?>
      <input type="hidden" name="kounselia_action" value="save_currency_settings">
      <div class="op-grid">
        <div class="op-card">
          <label class="op-label" for="currency_mode">Which currency to show</label>
          <select id="currency_mode" name="currency_mode" class="op-val-input">
            <option value="auto" <?php selected( kounselia_currency_mode(), 'auto' ); ?>>Automatic, by visitor location</option>
            <option value="NGN" <?php selected( kounselia_currency_mode(), 'NGN' ); ?>>Naira for everyone</option>
            <option value="USD" <?php selected( kounselia_currency_mode(), 'USD' ); ?>>Dollars for everyone</option>
          </select>
          <div class="op-desc">Override the automatic choice if you ever need one currency sitewide.</div>
        </div>
        <div class="op-card">
          <label class="op-label" for="naira_countries">Countries that see naira</label>
          <input type="text" id="naira_countries" name="naira_countries" class="op-val-input" value="<?php echo esc_attr( implode( ', ', kounselia_naira_countries() ) ); ?>" placeholder="NG">
          <div class="op-desc">Two-letter country codes, comma separated (NG = Nigeria).</div>
        </div>
        <div class="op-card">
          <label class="op-label" for="usd_ngn_rate">Exchange rate (₦ per $1)</label>
          <input type="number" id="usd_ngn_rate" name="usd_ngn_rate" class="op-val-input" min="1" step="0.01" value="<?php echo esc_attr( kounselia_usd_ngn_rate() ); ?>">
          <div class="op-desc">Used to show naira session rates (and plans without a dollar price) in dollars.</div>
        </div>
        <div class="op-card">
          <label class="op-label" for="unknown_currency">If location can't be found</label>
          <select id="unknown_currency" name="unknown_currency" class="op-val-input">
            <option value="USD" <?php selected( kounselia_unknown_location_currency(), 'USD' ); ?>>Show dollars</option>
            <option value="NGN" <?php selected( kounselia_unknown_location_currency(), 'NGN' ); ?>>Show naira</option>
          </select>
          <div class="op-desc">Rare — e.g. the location service is down.</div>
        </div>
        <div class="op-card">
          <label class="op-label"><input type="checkbox" name="geo_lookup_enabled" value="1" <?php checked( kounselia_geo_lookup_enabled() ); ?>> Look up visitor location</label>
          <div class="op-desc">Looks up the visitor's country from their IP address (via ipapi.co, cached for a week per visitor).</div>
        </div>
        <div class="op-card">
          <label class="op-label"><input type="checkbox" name="trust_country_header" value="1" <?php checked( kounselia_trust_country_header() ); ?>> Site is behind Cloudflare</label>
          <div class="op-desc">Only tick this if Cloudflare (or a similar CDN) sits in front of the site — it then uses the country Cloudflare reports. Otherwise visitors could fake their country.</div>
        </div>
      </div>
      <div style="margin-top: 20px;">
        <button type="submit" class="login-submit" style="width:auto;padding:10px 20px;">Save Currency Settings</button>
      </div>
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
        <div class="op-card">
          <label class="op-label" for="booking_commission_percent">Booking Platform Commission (%)</label>
          <input type="number" id="booking_commission_percent" name="booking_commission_percent" class="op-val-input" min="0" max="100" step="0.1" value="<?php echo esc_attr( $opt_booking_commission_percent ); ?>">
          <div class="op-desc">Kounselia's cut of each paid session. The rest is what a professional's payout balance accrues.</div>
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

  <!-- ===============================================================
       5. YOUR ACCOUNT SECURITY (2FA + active sessions)
  ================================================---------------- -->
  <?php
  $kounselia_me_id       = get_current_user_id();
  $kounselia_2fa_on      = kounselia_2fa_is_enabled( $kounselia_me_id );
  ?>
  <div class="panel">
    <div class="panel-title">
      Your Account Security
      <span style="font-weight:400;color:var(--text3);font-size:12px;">two-factor authentication &amp; active sessions — this account only</span>
    </div>

    <div class="maint-row">
      <div class="maint-info">
        <h4>Two-Factor Authentication</h4>
        <p id="tfa-status-text"><?php echo $kounselia_2fa_on ? 'Enabled — a code from your authenticator app is required to sign in.' : 'Off — anyone with your password alone can sign in. Turning this on is strongly recommended.'; ?></p>
      </div>
      <?php if ( $kounselia_2fa_on ) : ?>
        <button class="login-submit" style="width:auto;padding:9px 18px;background:var(--rose,#8B3A52);font-size:12.5px;" onclick="tfaOpenDisable()">Turn off 2FA</button>
      <?php else : ?>
        <button class="login-submit" style="width:auto;padding:9px 18px;font-size:12.5px;" onclick="tfaStartSetup()">Set up 2FA</button>
      <?php endif; ?>
    </div>

    <div class="maint-row">
      <div class="maint-info">
        <h4>Active Sessions</h4>
        <p>Every device currently signed in to this admin account.</p>
      </div>
      <button class="login-submit" style="width:auto;padding:9px 18px;background:#475569;font-size:12.5px;" onclick="revokeAllSessions()">Sign out all other sessions</button>
    </div>
    <div id="sessions-list" style="margin-top:4px;"></div>
  </div>

</div>

<!-- 2FA setup modal -->
<div id="tfa-setup-modal" style="display:none;position:fixed;inset:0;background:rgba(24,22,15,0.4);z-index:200;align-items:center;justify-content:center;">
  <div style="background:var(--surface);max-width:420px;width:92%;border-radius:12px;padding:28px;">
    <h3 style="font-family:'Cormorant Garamond',serif;font-size:22px;margin-bottom:14px;">Set up two-factor authentication</h3>
    <div id="tfa-step-qr">
      <p style="font-size:13px;color:var(--text2);margin-bottom:12px;line-height:1.5;">Scan this with Google Authenticator, 1Password, or any TOTP app — or enter the secret manually.</p>
      <div id="tfa-secret-box" style="font-family:monospace;font-size:13px;background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:10px;margin-bottom:14px;word-break:break-all;"></div>
      <label style="display:block;font-size:12.5px;font-weight:600;margin-bottom:6px;">Enter the 6-digit code to confirm</label>
      <input type="text" id="tfa-confirm-code" inputmode="numeric" maxlength="6" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:6px;font-size:16px;letter-spacing:2px;margin-bottom:14px;">
      <div id="tfa-setup-error" style="color:var(--rose,#8B3A52);font-size:12.5px;margin-bottom:10px;"></div>
      <div style="display:flex;gap:10px;">
        <button class="login-submit" style="width:auto;padding:9px 18px;font-size:12.5px;" onclick="tfaConfirmSetup()">Confirm &amp; enable</button>
        <button class="login-submit" style="width:auto;padding:9px 18px;background:var(--surface2);color:var(--text);font-size:12.5px;" onclick="tfaCloseModal()">Cancel</button>
      </div>
    </div>
    <div id="tfa-step-codes" style="display:none;">
      <p style="font-size:13px;color:var(--text2);margin-bottom:12px;line-height:1.5;">2FA is on. Save these one-time backup codes somewhere safe — each works once if you ever lose access to your authenticator app.</p>
      <div id="tfa-backup-codes" style="font-family:monospace;font-size:13px;background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:10px;margin-bottom:14px;display:grid;grid-template-columns:1fr 1fr;gap:6px;"></div>
      <button class="login-submit" style="width:auto;padding:9px 18px;font-size:12.5px;" onclick="tfaCloseModal(true)">Done</button>
    </div>
  </div>
</div>

<!-- 2FA disable modal -->
<div id="tfa-disable-modal" style="display:none;position:fixed;inset:0;background:rgba(24,22,15,0.4);z-index:200;align-items:center;justify-content:center;">
  <div style="background:var(--surface);max-width:380px;width:92%;border-radius:12px;padding:28px;">
    <h3 style="font-family:'Cormorant Garamond',serif;font-size:22px;margin-bottom:14px;">Turn off two-factor authentication</h3>
    <p style="font-size:13px;color:var(--text2);margin-bottom:12px;">Enter a current code from your authenticator app, or a backup code, to confirm.</p>
    <input type="text" id="tfa-disable-code" style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:6px;font-size:16px;margin-bottom:12px;">
    <div id="tfa-disable-error" style="color:var(--rose,#8B3A52);font-size:12.5px;margin-bottom:10px;"></div>
    <div style="display:flex;gap:10px;">
      <button class="login-submit" style="width:auto;padding:9px 18px;background:var(--rose,#8B3A52);font-size:12.5px;" onclick="tfaConfirmDisable()">Turn off</button>
      <button class="login-submit" style="width:auto;padding:9px 18px;background:var(--surface2);color:var(--text);font-size:12.5px;" onclick="document.getElementById('tfa-disable-modal').style.display='none'">Cancel</button>
    </div>
  </div>
</div>

<script>
const ADMIN_AJAX_URL = "<?php echo esc_js( set_url_scheme( admin_url( 'admin-ajax.php' ), is_ssl() ? 'https' : 'http' ) ); ?>";
const ADMIN_NONCE    = "<?php echo esc_js( wp_create_nonce( 'kounselia_admin_nonce' ) ); ?>";

function tfaStartSetup() {
    document.getElementById('tfa-setup-error').textContent = '';
    document.getElementById('tfa-step-qr').style.display = 'block';
    document.getElementById('tfa-step-codes').style.display = 'none';
    document.getElementById('tfa-setup-modal').style.display = 'flex';
    document.getElementById('tfa-secret-box').textContent = 'Loading…';

    fetch(ADMIN_AJAX_URL, {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'kounselia_admin_2fa_setup_init', nonce: ADMIN_NONCE })
    }).then(r => r.json()).then(data => {
        if (data.success) {
            document.getElementById('tfa-secret-box').innerHTML = 'Secret key: <strong>' + data.data.secret + '</strong>';
        } else {
            document.getElementById('tfa-setup-error').textContent = data.data.message || 'Could not start setup.';
        }
    });
}

function tfaConfirmSetup() {
    const code = document.getElementById('tfa-confirm-code').value.trim();
    fetch(ADMIN_AJAX_URL, {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'kounselia_admin_2fa_setup_confirm', nonce: ADMIN_NONCE, code: code })
    }).then(r => r.json()).then(data => {
        if (data.success) {
            document.getElementById('tfa-step-qr').style.display = 'none';
            document.getElementById('tfa-step-codes').style.display = 'block';
            document.getElementById('tfa-backup-codes').innerHTML = data.data.backup_codes.map(c => `<span>${c}</span>`).join('');
        } else {
            document.getElementById('tfa-setup-error').textContent = data.data.message || 'Incorrect code.';
        }
    });
}

function tfaCloseModal(reload) {
    document.getElementById('tfa-setup-modal').style.display = 'none';
    if (reload) window.location.reload();
}

function tfaOpenDisable() {
    document.getElementById('tfa-disable-error').textContent = '';
    document.getElementById('tfa-disable-code').value = '';
    document.getElementById('tfa-disable-modal').style.display = 'flex';
}

function tfaConfirmDisable() {
    const code = document.getElementById('tfa-disable-code').value.trim();
    fetch(ADMIN_AJAX_URL, {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'kounselia_admin_2fa_disable', nonce: ADMIN_NONCE, code: code })
    }).then(r => r.json()).then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            document.getElementById('tfa-disable-error').textContent = data.data.message || 'Incorrect code.';
        }
    });
}

function loadSessions() {
    fetch(ADMIN_AJAX_URL, {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'kounselia_admin_list_sessions', nonce: ADMIN_NONCE })
    }).then(r => r.json()).then(data => {
        if (!data.success) return;
        const list = document.getElementById('sessions-list');
        list.innerHTML = data.data.sessions.map(s => `
            <div class="maint-row">
              <div class="maint-info">
                <h4 style="font-size:13.5px;">${s.ip}${s.is_current ? ' <span style="color:var(--sage);font-weight:600;">(this device)</span>' : ''}</h4>
                <p>Signed in ${s.login} · expires ${s.expiration}</p>
              </div>
              ${s.is_current ? '' : `<button class="login-submit" style="width:auto;padding:7px 14px;background:var(--surface2);color:var(--text);font-size:12px;" onclick="revokeSession('${s.token}')">Sign out</button>`}
            </div>`).join('') || '<div class="empty-state">No other active sessions.</div>';
    });
}

function revokeSession(token) {
    fetch(ADMIN_AJAX_URL, {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'kounselia_admin_revoke_session', nonce: ADMIN_NONCE, token: token })
    }).then(r => r.json()).then(() => loadSessions());
}

function revokeAllSessions() {
    if (!confirm('Sign out every other device currently logged into your admin account?')) return;
    fetch(ADMIN_AJAX_URL, {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'kounselia_admin_revoke_session', nonce: ADMIN_NONCE, all: '1' })
    }).then(r => r.json()).then(() => loadSessions());
}

loadSessions();
</script>

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
<?php
/**
 * Kounselia Core — email delivery: the site's default mailer or Brevo.
 *
 * Every email the site sends goes through wp_mail(). This file sits in
 * front of it and picks a delivery provider per "channel":
 *
 *   account     booking confirmations, reminders, password resets,
 *               payment receipts … anything a member needs.
 *   newsletter  campaigns, blog-post emails, welcome/confirm emails
 *               (newsletter.php tags these with an X-Kounselia-Channel
 *               header, which is stripped before sending).
 *
 * Providers:
 *   wordpress   the host's normal mail (or any SMTP plugin installed).
 *   brevo       Brevo's transactional email API (api.brevo.com/v3).
 *
 * Brevo's free plan allows 300 emails a day. A daily limit and a
 * reserve for account emails are settings, so newsletters can never use
 * up the allowance a password reset needs. When the limit is reached,
 * newsletters pause until tomorrow and account emails fall back to the
 * default mailer (if allowed). If Brevo is unreachable, account emails
 * also fall back, so nobody is ever locked out waiting for an email.
 * Upgrading Brevo later = raise the limit (or set 0 for no limit).
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function kounselia_mail_settings() {
    $defaults = array(
        'account_provider'    => 'wordpress',
        'newsletter_provider' => 'wordpress',
        'brevo_api_key'       => '',
        'sender_email'        => '',
        'sender_name'         => 'Kounselia',
        'reply_to'            => '',
        'brevo_daily_limit'   => 300,
        'account_reserve'     => 50,
        'fallback_to_default' => 1,
    );
    $saved = get_option( 'kounselia_mail_settings', array() );
    return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

function kounselia_mail_brevo_ready( $settings = null ) {
    $settings = $settings ? $settings : kounselia_mail_settings();
    return '' !== trim( $settings['brevo_api_key'] ) && is_email( $settings['sender_email'] );
}

/* -------------------------------------------------------------------------
 * Daily allowance
 * ---------------------------------------------------------------------- */

function kounselia_mail_sent_today( $provider ) {
    global $wpdb;
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_mail_log WHERE provider = %s AND status = 'sent' AND created_at >= %s",
        $provider,
        current_time( 'Y-m-d' ) . ' 00:00:00'
    ) );
}

/**
 * How many more emails this channel may send through Brevo today.
 * PHP_INT_MAX when there's no limit. Newsletters stop short of the
 * reserve kept for account emails.
 */
function kounselia_mail_brevo_remaining( $channel ) {
    $s     = kounselia_mail_settings();
    $limit = (int) $s['brevo_daily_limit'];
    if ( $limit <= 0 ) {
        return PHP_INT_MAX;
    }
    $cap = 'newsletter' === $channel ? max( 0, $limit - max( 0, (int) $s['account_reserve'] ) ) : $limit;
    return max( 0, $cap - kounselia_mail_sent_today( 'brevo' ) );
}

/**
 * How many newsletter emails may go out right now — used by the
 * newsletter queue so a campaign pauses (instead of failing) when the
 * day's allowance is used up, and carries on tomorrow.
 */
function kounselia_mail_newsletter_capacity() {
    $s = kounselia_mail_settings();
    if ( 'brevo' !== $s['newsletter_provider'] || ! kounselia_mail_brevo_ready( $s ) ) {
        return PHP_INT_MAX;
    }
    return kounselia_mail_brevo_remaining( 'newsletter' );
}

/* -------------------------------------------------------------------------
 * Routing every wp_mail() call
 * ---------------------------------------------------------------------- */

$GLOBALS['kounselia_mail_channel']        = 'account';
$GLOBALS['kounselia_mail_force_provider'] = null;

/**
 * Runs first inside wp_mail(): note which channel this email belongs
 * to and remove our private header so it never reaches the recipient.
 */
function kounselia_mail_capture_channel( $atts ) {
    $channel = 'account';
    $headers = isset( $atts['headers'] ) ? $atts['headers'] : array();
    $list    = is_array( $headers ) ? $headers : preg_split( "/\r\n|\n/", (string) $headers );
    $kept    = array();
    foreach ( $list as $h ) {
        if ( preg_match( '/^X-Kounselia-Channel:\s*(\w+)/i', (string) $h, $m ) ) {
            $channel = 'newsletter' === strtolower( $m[1] ) ? 'newsletter' : 'account';
            continue;
        }
        if ( '' !== trim( (string) $h ) ) {
            $kept[] = $h;
        }
    }
    $atts['headers']                   = $kept;
    $GLOBALS['kounselia_mail_channel'] = $channel;
    return $atts;
}
add_filter( 'wp_mail', 'kounselia_mail_capture_channel', 1 );

/**
 * Decides who delivers this email. Returning null lets WordPress's own
 * mailer carry on as normal; returning true/false means Brevo handled it.
 */
function kounselia_mail_route( $return, $atts ) {
    if ( null !== $return ) {
        return $return; // Another plugin already handled it.
    }
    $s       = kounselia_mail_settings();
    $channel = $GLOBALS['kounselia_mail_channel'];
    $wanted  = $GLOBALS['kounselia_mail_force_provider'] ? $GLOBALS['kounselia_mail_force_provider'] : $s[ $channel . '_provider' ];

    if ( 'brevo' !== $wanted || ! kounselia_mail_brevo_ready( $s ) || ! empty( $atts['attachments'] ) ) {
        return null;
    }

    $forced = (bool) $GLOBALS['kounselia_mail_force_provider'];
    if ( ! $forced && kounselia_mail_brevo_remaining( $channel ) <= 0 ) {
        if ( 'account' === $channel && ! empty( $s['fallback_to_default'] ) ) {
            return null; // Today's Brevo allowance is used up: use the default mailer.
        }
        kounselia_mail_log( 'brevo', $channel, $atts['to'], $atts['subject'], 'skipped', 'Daily Brevo limit reached' );
        return false;
    }

    $result = kounselia_mail_send_brevo( $atts, $channel, $s );
    if ( true === $result ) {
        kounselia_mail_log( 'brevo', $channel, $atts['to'], $atts['subject'], 'sent' );
        return true;
    }

    kounselia_mail_log( 'brevo', $channel, $atts['to'], $atts['subject'], 'failed', $result );
    if ( ! $forced && 'account' === $channel && ! empty( $s['fallback_to_default'] ) ) {
        return null; // Brevo failed; don't leave a member without their email.
    }
    return false;
}
add_filter( 'pre_wp_mail', 'kounselia_mail_route', 10, 2 );

/**
 * Sends one wp_mail() message through Brevo. Returns true, or an error
 * message string.
 */
function kounselia_mail_send_brevo( $atts, $channel, $s ) {
    $to = array();
    foreach ( (array) ( is_array( $atts['to'] ) ? $atts['to'] : explode( ',', (string) $atts['to'] ) ) as $addr ) {
        $addr = trim( $addr );
        if ( preg_match( '/<([^>]+)>/', $addr, $m ) ) {
            $addr = $m[1];
        }
        if ( is_email( $addr ) ) {
            $to[] = array( 'email' => $addr );
        }
    }
    if ( ! $to ) {
        return 'No valid recipient';
    }

    $is_html = false;
    $extra   = array();
    $reply   = $s['reply_to'];
    foreach ( (array) $atts['headers'] as $h ) {
        if ( ! preg_match( '/^([\w-]+):\s*(.+)$/', trim( (string) $h ), $m ) ) {
            continue;
        }
        $name = strtolower( $m[1] );
        if ( 'content-type' === $name ) {
            $is_html = false !== stripos( $m[2], 'text/html' );
        } elseif ( 'reply-to' === $name ) {
            $reply = preg_match( '/<([^>]+)>/', $m[2], $r ) ? $r[1] : trim( $m[2] );
        } elseif ( in_array( $name, array( 'list-unsubscribe', 'list-unsubscribe-post' ), true ) ) {
            $extra[ $m[1] ] = $m[2];
        }
    }
    // Some code sets HTML through the wp_mail_content_type filter instead of a header.
    if ( ! $is_html && 'text/html' === apply_filters( 'wp_mail_content_type', 'text/plain' ) ) {
        $is_html = true;
    }

    $body = array(
        'sender'  => array( 'email' => $s['sender_email'], 'name' => $s['sender_name'] ? $s['sender_name'] : 'Kounselia' ),
        'to'      => $to,
        'subject' => (string) $atts['subject'],
        'tags'    => array( 'kounselia-' . $channel ),
    );
    $body[ $is_html ? 'htmlContent' : 'textContent' ] = (string) $atts['message'];
    if ( $reply && is_email( $reply ) ) {
        $body['replyTo'] = array( 'email' => $reply );
    }
    if ( $extra ) {
        $body['headers'] = $extra;
    }

    $response = wp_remote_post( 'https://api.brevo.com/v3/smtp/email', array(
        'timeout' => 15,
        'headers' => array(
            'api-key'      => trim( $s['brevo_api_key'] ),
            'content-type' => 'application/json',
            'accept'       => 'application/json',
        ),
        'body'    => wp_json_encode( $body ),
    ) );
    if ( is_wp_error( $response ) ) {
        return mb_substr( $response->get_error_message(), 0, 250 );
    }
    $code = (int) wp_remote_retrieve_response_code( $response );
    if ( $code >= 200 && $code < 300 ) {
        return true;
    }
    $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
    return mb_substr( 'Brevo ' . $code . ': ' . ( isset( $decoded['message'] ) ? $decoded['message'] : 'request rejected' ), 0, 250 );
}

/* -------------------------------------------------------------------------
 * Logging (default mailer results arrive through WordPress's own hooks)
 * ---------------------------------------------------------------------- */

function kounselia_mail_log( $provider, $channel, $to, $subject, $status, $error = null ) {
    global $wpdb;
    $to = is_array( $to ) ? implode( ', ', $to ) : (string) $to;
    $wpdb->insert( $wpdb->prefix . 'kounselia_mail_log', array(
        'provider'   => $provider,
        'channel'    => $channel,
        'recipient'  => mb_substr( $to, 0, 191 ),
        'subject'    => mb_substr( (string) $subject, 0, 255 ),
        'status'     => $status,
        'error'      => $error ? mb_substr( (string) $error, 0, 255 ) : null,
        'created_at' => current_time( 'mysql' ),
    ) );
}

add_action( 'wp_mail_succeeded', function ( $mail ) {
    kounselia_mail_log( 'wordpress', $GLOBALS['kounselia_mail_channel'], $mail['to'], $mail['subject'], 'sent' );
} );
add_action( 'wp_mail_failed', function ( $error ) {
    $data = $error->get_error_data();
    kounselia_mail_log( 'wordpress', $GLOBALS['kounselia_mail_channel'], isset( $data['to'] ) ? $data['to'] : '', isset( $data['subject'] ) ? $data['subject'] : '', 'failed', $error->get_error_message() );
} );

add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'kounselia_mail_log_prune' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'kounselia_mail_log_prune' );
    }
} );
add_action( 'kounselia_mail_log_prune', function () {
    global $wpdb;
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}kounselia_mail_log WHERE created_at < %s", date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS ) ) );
} );

/* -------------------------------------------------------------------------
 * Brevo account check
 * ---------------------------------------------------------------------- */

/**
 * Asks Brevo who this API key belongs to and what plan/credits it has.
 * Returns array( ok, message, plan, credits ).
 */
function kounselia_mail_brevo_account( $api_key ) {
    $response = wp_remote_get( 'https://api.brevo.com/v3/account', array(
        'timeout' => 12,
        'headers' => array( 'api-key' => trim( $api_key ), 'accept' => 'application/json' ),
    ) );
    if ( is_wp_error( $response ) ) {
        return array( 'ok' => false, 'message' => $response->get_error_message() );
    }
    $code    = (int) wp_remote_retrieve_response_code( $response );
    $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( 200 !== $code || ! is_array( $decoded ) ) {
        return array( 'ok' => false, 'message' => 401 === $code ? 'Brevo rejected this API key.' : 'Brevo returned an error (' . $code . ').' );
    }
    $plans = array();
    foreach ( (array) ( $decoded['plan'] ?? array() ) as $p ) {
        $plans[] = ucfirst( (string) ( $p['type'] ?? 'plan' ) ) . ( isset( $p['credits'] ) ? ' — ' . number_format_i18n( (float) $p['credits'] ) . ' ' . ( 'sendLimit' === ( $p['creditsType'] ?? '' ) ? 'emails/day' : 'credits' ) : '' );
    }
    return array(
        'ok'      => true,
        'message' => 'Connected to Brevo account ' . ( $decoded['email'] ?? '' ) . ( ! empty( $decoded['companyName'] ) ? ' (' . $decoded['companyName'] . ')' : '' ) . '.',
        'plan'    => implode( '; ', $plans ),
    );
}

/* -------------------------------------------------------------------------
 * Admin AJAX (super admins only — this holds an API key)
 * ---------------------------------------------------------------------- */

function kounselia_mail_admin_guard() {
    check_ajax_referer( 'kounselia_admin_nonce', 'nonce' );
    if ( ! current_user_can( 'administrator' ) ) {
        kounselia_send_pure_json_error( array( 'message' => 'Only super admins can change email delivery.' ), 403 );
    }
}

function kounselia_ajax_admin_mail_save() {
    kounselia_mail_admin_guard();
    $current = kounselia_mail_settings();
    $pick    = function ( $v ) {
        return 'brevo' === $v ? 'brevo' : 'wordpress';
    };
    $key = trim( (string) kounselia_post_field( 'brevo_api_key' ) );
    // The page never shows the saved key; an empty box means "keep it".
    if ( '' === $key ) {
        $key = $current['brevo_api_key'];
    }
    if ( '1' === kounselia_post_field( 'clear_key' ) ) {
        $key = '';
    }
    $new = array(
        'account_provider'    => $pick( kounselia_post_field( 'account_provider' ) ),
        'newsletter_provider' => $pick( kounselia_post_field( 'newsletter_provider' ) ),
        'brevo_api_key'       => sanitize_text_field( $key ),
        'sender_email'        => sanitize_email( kounselia_post_field( 'sender_email' ) ),
        'sender_name'         => sanitize_text_field( kounselia_post_field( 'sender_name' ) ) ?: 'Kounselia',
        'reply_to'            => sanitize_email( kounselia_post_field( 'reply_to' ) ),
        'brevo_daily_limit'   => max( 0, (int) kounselia_post_field( 'brevo_daily_limit', 300 ) ),
        'account_reserve'     => max( 0, (int) kounselia_post_field( 'account_reserve', 50 ) ),
        'fallback_to_default' => '1' === kounselia_post_field( 'fallback_to_default' ) ? 1 : 0,
    );
    if ( ( 'brevo' === $new['account_provider'] || 'brevo' === $new['newsletter_provider'] ) && ! kounselia_mail_brevo_ready( $new ) ) {
        kounselia_send_pure_json_error( array( 'message' => 'To use Brevo, add your Brevo API key and a sender email address (one you have verified in Brevo).' ), 400 );
    }
    update_option( 'kounselia_mail_settings', $new, false );
    kounselia_admin_log( 'edited_mail_delivery', 'settings', 0 );
    kounselia_send_pure_json_success( array( 'message' => 'Email delivery settings saved.' ) );
}
add_action( 'wp_ajax_kounselia_admin_mail_save', 'kounselia_ajax_admin_mail_save' );

function kounselia_ajax_admin_mail_check() {
    kounselia_mail_admin_guard();
    $key = trim( (string) kounselia_post_field( 'brevo_api_key' ) );
    if ( '' === $key ) {
        $key = kounselia_mail_settings()['brevo_api_key'];
    }
    if ( '' === $key ) {
        kounselia_send_pure_json_error( array( 'message' => 'Paste your Brevo API key first.' ), 400 );
    }
    $r = kounselia_mail_brevo_account( $key );
    if ( ! $r['ok'] ) {
        kounselia_send_pure_json_error( array( 'message' => $r['message'] ), 400 );
    }
    kounselia_send_pure_json_success( array( 'message' => $r['message'] . ( $r['plan'] ? ' Plan: ' . $r['plan'] . '.' : '' ) ) );
}
add_action( 'wp_ajax_kounselia_admin_mail_check', 'kounselia_ajax_admin_mail_check' );

function kounselia_ajax_admin_mail_test() {
    kounselia_mail_admin_guard();
    $provider = 'brevo' === kounselia_post_field( 'provider' ) ? 'brevo' : 'wordpress';
    $to       = sanitize_email( kounselia_post_field( 'to' ) );
    if ( ! is_email( $to ) ) {
        $to = wp_get_current_user()->user_email;
    }
    if ( 'brevo' === $provider && ! kounselia_mail_brevo_ready() ) {
        kounselia_send_pure_json_error( array( 'message' => 'Save a Brevo API key and sender email first.' ), 400 );
    }
    $GLOBALS['kounselia_mail_force_provider'] = $provider;
    $ok = kounselia_send_html_email( $to, 'Kounselia test email (' . ( 'brevo' === $provider ? 'Brevo' : 'default mail' ) . ')', 'It works', '<p>This test was delivered by <strong>' . ( 'brevo' === $provider ? 'Brevo' : "your server's default mailer" ) . '</strong>. If it landed in spam, check your domain\'s SPF and DKIM records.</p>' );
    $GLOBALS['kounselia_mail_force_provider'] = null;
    if ( ! $ok ) {
        global $wpdb;
        $err = $wpdb->get_var( "SELECT error FROM {$wpdb->prefix}kounselia_mail_log ORDER BY id DESC LIMIT 1" );
        kounselia_send_pure_json_error( array( 'message' => 'Not delivered. ' . ( $err ? $err : 'The mail server refused it.' ) ), 500 );
    }
    kounselia_send_pure_json_success( array( 'message' => 'Test email sent to ' . $to . '.' ) );
}
add_action( 'wp_ajax_kounselia_admin_mail_test', 'kounselia_ajax_admin_mail_test' );

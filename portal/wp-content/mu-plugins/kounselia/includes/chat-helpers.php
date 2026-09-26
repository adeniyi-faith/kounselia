<?php
/**
 * STREAMING_CHUNK:Setting up session resolution and rate limits...
 * Kounselia Core — session resolution, guest limits, message logging, safety keywords, Gemini client, history building
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * 10. CHAT: SESSION + GUEST LIMIT HELPERS
 * ---------------------------------------------------------------------- */

define( 'KOUNSELIA_GUEST_SESSION_LIMIT', (int) get_option( 'kounselia_guest_session_limit', 6 ) );
define( 'KOUNSELIA_GUEST_DAILY_CAP', (int) get_option( 'kounselia_guest_daily_cap', 40 ) );

function kounselia_resolve_session( $counselor_slug, $user_id, $guest_token, $requested_session_id = 0 ) {
    global $wpdb;
    $table = $wpdb->prefix . 'kounselia_sessions';

    if ( $requested_session_id > 0 ) {
        if ( $user_id ) {
            $owned = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE id = %d AND counselor_slug = %s AND user_id = %d AND status = 'active' LIMIT 1",
                $requested_session_id, $counselor_slug, $user_id
            ) );
        } else {
            $owned = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE id = %d AND counselor_slug = %s AND guest_token = %s AND status = 'active' LIMIT 1",
                $requested_session_id, $counselor_slug, $guest_token
            ) );
        }
        if ( $owned ) {
            return (int) $owned;
        }
    }

    $wpdb->insert( $table, array(
        'user_id'        => $user_id ?: null,
        'guest_token'    => $user_id ? null : $guest_token,
        'counselor_slug' => $counselor_slug,
        'status'         => 'active',
        'started_at'     => current_time( 'mysql' ),
    ) );

    return (int) $wpdb->insert_id;
}

function kounselia_session_user_message_count( $session_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'kounselia_messages';
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE session_id = %d AND sender = 'user'", $session_id
    ) );
}

function kounselia_bump_guest_daily_count( $guest_token ) {
    global $wpdb;
    $table = $wpdb->prefix . 'kounselia_guest_limits';

    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE guest_token = %s", $guest_token ) );
    $now = current_time( 'mysql' );

    if ( ! $row ) {
        $wpdb->insert( $table, array(
            'guest_token'   => $guest_token,
            'message_count' => 1,
            'window_start'  => $now,
        ) );
        return 1;
    }

    $window_age = time() - strtotime( $row->window_start );

    if ( $window_age > DAY_IN_SECONDS ) {
        $wpdb->update( $table, array( 'message_count' => 1, 'window_start' => $now ), array( 'guest_token' => $guest_token ) );
        return 1;
    }

    $new_count = $row->message_count + 1;
    $wpdb->update( $table, array( 'message_count' => $new_count ), array( 'guest_token' => $guest_token ) );
    return $new_count;
}

function kounselia_log_message( $session_id, $sender, $content ) {
    global $wpdb;

    $flagged_safety = 0;
    $flag_reason    = null;

    if ( 'user' === $sender ) {
        $matched = kounselia_message_matches_safety_keywords( $content );
        if ( $matched ) {
            $flagged_safety = 1;
            $flag_reason    = $matched;
        }
    }

    $wpdb->insert( $wpdb->prefix . 'kounselia_messages', array(
        'session_id'     => $session_id,
        'sender'         => $sender,
        'content'        => $content,
        'created_at'     => current_time( 'mysql' ),
        'flagged_safety' => $flagged_safety,
        'flag_reason'    => $flag_reason,
    ) );
    $message_id = (int) $wpdb->insert_id;

    if ( $flagged_safety && function_exists( 'kounselia_record_safety_escalation' ) ) {
        kounselia_record_safety_escalation( $message_id, $session_id, $flag_reason );
    }

    return $message_id;
}


/* -------------------------------------------------------------------------
 * STREAMING_CHUNK:Configuring safety keyword detection...
 * Safety keyword detection.
 * ---------------------------------------------------------------------- */

function kounselia_default_safety_keywords() {
    return array(
        'suicide',
        'suicidal',
        'kill myself',
        'killing myself',
        'end my life',
        'ending my life',
        'want to die',
        'wish i was dead',
        'wish i were dead',
        'better off dead',
        'no reason to live',
        'no reason to go on',
        "can't go on",
        'cant go on',
        'self harm',
        'self-harm',
        'hurting myself',
        'hurt myself',
        'cutting myself',
        'overdose',
        'take my own life',
        'not worth living',
    );
}

function kounselia_seed_safety_keywords() {
    if ( false === get_option( 'kounselia_safety_keywords' ) ) {
        update_option( 'kounselia_safety_keywords', kounselia_default_safety_keywords() );
    }
}
add_action( 'init', 'kounselia_seed_safety_keywords' );

function kounselia_get_safety_keywords() {
    $keywords = get_option( 'kounselia_safety_keywords', array() );
    return is_array( $keywords ) ? $keywords : array();
}

function kounselia_update_safety_keywords( $keywords ) {
    $clean = array();
    foreach ( (array) $keywords as $kw ) {
        $kw = strtolower( trim( sanitize_text_field( $kw ) ) );
        if ( '' !== $kw && ! in_array( $kw, $clean, true ) ) {
            $clean[] = $kw;
        }
    }
    update_option( 'kounselia_safety_keywords', $clean );
    return $clean;
}

function kounselia_message_matches_safety_keywords( $content ) {
    $content = strtolower( (string) $content );
    if ( '' === trim( $content ) ) {
        return false;
    }
    foreach ( kounselia_get_safety_keywords() as $kw ) {
        if ( '' !== $kw && false !== strpos( $content, $kw ) ) {
            return $kw;
        }
    }
    return false;
}

function kounselia_build_gemini_history( $session_id, $max_turns = 16 ) {
    global $wpdb;
    $table = $wpdb->prefix . 'kounselia_messages';

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT sender, content FROM {$table} WHERE session_id = %d ORDER BY id DESC LIMIT %d",
        $session_id, $max_turns
    ) );
    $rows = array_reverse( $rows );

    $contents = array();
    foreach ( $rows as $row ) {
        $contents[] = array(
            'role'  => ( 'bot' === $row->sender ) ? 'model' : 'user',
            'parts' => array( array( 'text' => $row->content ) ),
        );
    }
    return $contents;
}

function kounselia_user_context_clause( $user_id ) {
    $user = get_userdata( $user_id );
    if ( ! $user ) {
        return '';
    }

    $display_name = $user->display_name ? $user->display_name : $user->user_login;
    $first_name   = trim( explode( ' ', $display_name )[0] );

    if ( '' === $first_name ) {
        return '';
    }

    return "\n\nCONTEXT: You're speaking with a signed-in member named {$first_name}. You can use their first name naturally when it fits the moment, for example in a greeting or when checking in, but don't force it into every reply, that reads as scripted rather than warm.";
}

function kounselia_get_gemini_keys() {
    $raw = get_option( 'kounselia_gemini_key', '' );
    if ( empty( $raw ) && defined( 'KOUNSELIA_GEMINI_KEY' ) ) {
        $raw = KOUNSELIA_GEMINI_KEY;
    }
    $keys = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
    return array_values( $keys );
}

function kounselia_key_cooldown_hash( $api_key ) {
    return substr( md5( $api_key ), 0, 12 );
}

function kounselia_mark_key_throttled( $api_key ) {
    $cooldowns = get_transient( 'kounselia_key_cooldowns' );
    $cooldowns = is_array( $cooldowns ) ? $cooldowns : array();

    $now = time();
    foreach ( $cooldowns as $hash => $expiry ) {
        if ( $expiry < $now ) {
            unset( $cooldowns[ $hash ] );
        }
    }

    $cooldowns[ kounselia_key_cooldown_hash( $api_key ) ] = $now + 60; // 60s rest
    set_transient( 'kounselia_key_cooldowns', $cooldowns, 120 );
}

function kounselia_filter_available_keys( $keys ) {
    $cooldowns = get_transient( 'kounselia_key_cooldowns' );
    if ( empty( $cooldowns ) || ! is_array( $cooldowns ) ) {
        return $keys;
    }

    $now = time();
    $available = array_values( array_filter( $keys, function ( $key ) use ( $cooldowns, $now ) {
        $hash = kounselia_key_cooldown_hash( $key );
        return ! isset( $cooldowns[ $hash ] ) || $cooldowns[ $hash ] < $now;
    } ) );

    return empty( $available ) ? $keys : $available;
}

/**
 * STREAMING_CHUNK:Integrating Gemini API communication...
 * Call Gemini with model cascade and key rotation.
 */
function kounselia_call_gemini( $system_prompt, $contents, $primary_model, $temperature, $max_tokens, &$error_out, $response_mime_type = 'text/plain', $timeout = 25 ) {
    $keys = kounselia_get_gemini_keys();
    if ( empty( $keys ) ) {
        $error_out = 'AI is not configured yet, please set kounselia_gemini_key.';
        return null;
    }
    $keys = kounselia_filter_available_keys( $keys );
    shuffle( $keys );

    $max_keys_per_model = min( 4, count( $keys ) );

    $model_stack = array( $primary_model );
    
    // Model Cascade Logic (Hybrid Routing):
    if ( false !== strpos( $primary_model, 'pro' ) ) {
        // If the pro model hits quota issues on heavy tasks, fallback to the fast, standard flash tier
        $model_stack[] = 'gemini-3.6-flash'; 
    } elseif ( false !== strpos( $primary_model, 'flash' ) && false === strpos( $primary_model, 'lite' ) ) {
        // If the standard flash task fails, the lite version acts as safety net
        $model_stack[] = 'gemini-3.5-flash-lite';
    }

    $error_out = 'Empty response from AI.';

    foreach ( $model_stack as $model ) {
        for ( $i = 0; $i < $max_keys_per_model; $i++ ) {
            $api_key = $keys[ $i ];
            
            $url  = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $api_key;
            
            $generation_config = array(
                'temperature'     => (float) $temperature,
                'maxOutputTokens' => (int) $max_tokens,
                'responseMimeType'=> $response_mime_type,
            );

            // CRITICAL FIX: We have completely removed the thinkingConfig payload here. 
            // It was causing the 400 Bad Request errors for any model that did not contain "lite" in its name.

            $body = wp_json_encode( array(
                'system_instruction' => array( 'parts' => array( array( 'text' => $system_prompt ) ) ),
                'contents'           => $contents,
                'generationConfig'   => $generation_config,
            ) );

            $response = wp_remote_post( $url, array(
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body'    => $body,
                'timeout' => $timeout,
            ) );

            if ( is_wp_error( $response ) ) {
                $error_out = $response->get_error_message();
                continue; 
            }

            $status = wp_remote_retrieve_response_code( $response );
            $data   = json_decode( wp_remote_retrieve_body( $response ), true );

            $is_quota_error = isset( $data['error']['message'] ) &&
                ( false !== stripos( $data['error']['message'], 'quota' ) || false !== stripos( $data['error']['message'], 'limit' ) );

            if ( 429 === $status || $is_quota_error || 400 === $status || 404 === $status ) {
                // If it's a quota error, missing model (404), or payload structure rejection (400), mark and skip
                kounselia_mark_key_throttled( $api_key );
                $error_out = isset( $data['error']['message'] ) ? $data['error']['message'] : 'API request rejected.';
                continue; 
            }

            $reply = isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ? $data['candidates'][0]['content']['parts'][0]['text'] : null;
            if ( ! empty( $reply ) ) {
                return trim( $reply );
            }

            $error_out = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Unexpected response from AI.';
            break; 
        }
    }

    return null;
}
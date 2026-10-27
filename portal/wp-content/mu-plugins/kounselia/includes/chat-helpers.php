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
        // 0 = waiting for the background AI risk check (safety-ai-screening.php).
        'ai_screened'    => ( 'user' === $sender && ! $flagged_safety ) ? 0 : 1,
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

/**
 * Indirect, euphemistic and slang ways people say they're at risk, which
 * a plain keyword list misses. Always checked on top of the admin's own
 * list (and not stored in it, so existing sites get them too). Written
 * the way kounselia_normalize_safety_text() leaves text: lowercase
 * letters, digits and single spaces, no apostrophes.
 */
function kounselia_builtin_crisis_phrases() {
    return array(
        'kms',
        'kys',
        'unalive',
        'unaliving',
        'sewerslide',
        'end it all',
        'ending it all',
        'end things',
        'off myself',
        'dont want to be alive',
        'dont want to live',
        'dont want to be here anymore',
        'dont want to exist',
        'dont want to wake up',
        'wish i could disappear forever',
        'wish i was never born',
        'wish i had never been born',
        'better off without me',
        'no point in living',
        'no point living',
        'nothing to live for',
        'cant do this anymore',
        'want it all to end',
        'want it to be over',
        'going to jump',
        'jump off a bridge',
        'jump off the roof',
        'slit my wrists',
        'hang myself',
        'hanging myself',
        'shoot myself',
        'take all my pills',
        'took all my pills',
        'swallow all the pills',
        'saying goodbye to everyone',
        'writing a suicide note',
        'goodbye note',
        'hurt someone',
        'kill him',
        'kill her',
        'kill them',
        'he is going to kill me',
        'she is going to kill me',
        'afraid he will kill me',
        'not safe at home',
    );
}

/**
 * Lowercases, turns curly apostrophes and punctuation into spaces (and
 * drops apostrophes so "can't" and "cant" match the same), and squashes
 * letters stretched for emphasis ("diiiie" -> "die").
 */
function kounselia_normalize_safety_text( $text ) {
    $text = strtolower( (string) $text );
    $text = str_replace( array( "'", '’', '‘', '`' ), '', $text );
    $text = preg_replace( '/[^a-z0-9]+/u', ' ', $text );
    $text = preg_replace( '/([a-z])\1{2,}/', '$1', $text );
    return trim( preg_replace( '/\s+/', ' ', $text ) );
}

/**
 * True if a message word is the same as a keyword word, or a one-letter
 * typo of it ("suicde", "myslef"). Only words of five letters or more
 * get typo tolerance: short words are too easily one letter away from
 * something harmless ("kill" vs "will").
 */
function kounselia_safety_word_matches( $word, $keyword_word ) {
    if ( $word === $keyword_word ) {
        return true;
    }
    if ( strlen( $keyword_word ) < 5 || abs( strlen( $word ) - strlen( $keyword_word ) ) > 1 ) {
        return false;
    }
    return kounselia_typo_distance( $word, $keyword_word ) <= 1;
}

/**
 * Edit distance where swapping two neighbouring letters ("myslef") counts
 * as one typo, not two as it would with PHP's levenshtein().
 */
function kounselia_typo_distance( $a, $b ) {
    $la = strlen( $a );
    $lb = strlen( $b );
    $d  = array();
    for ( $i = 0; $i <= $la; $i++ ) {
        $d[ $i ][0] = $i;
    }
    for ( $j = 0; $j <= $lb; $j++ ) {
        $d[0][ $j ] = $j;
    }
    for ( $i = 1; $i <= $la; $i++ ) {
        for ( $j = 1; $j <= $lb; $j++ ) {
            $cost        = ( $a[ $i - 1 ] === $b[ $j - 1 ] ) ? 0 : 1;
            $d[ $i ][ $j ] = min( $d[ $i - 1 ][ $j ] + 1, $d[ $i ][ $j - 1 ] + 1, $d[ $i - 1 ][ $j - 1 ] + $cost );
            if ( $i > 1 && $j > 1 && $a[ $i - 1 ] === $b[ $j - 2 ] && $a[ $i - 2 ] === $b[ $j - 1 ] ) {
                $d[ $i ][ $j ] = min( $d[ $i ][ $j ], $d[ $i - 2 ][ $j - 2 ] + 1 );
            }
        }
    }
    return $d[ $la ][ $lb ];
}

/**
 * Returns the matching keyword/phrase (in its original form, so severity
 * classification and the admin Safety page show something readable), or
 * false. Checks the admin's keyword list first, then the built-in
 * indirect phrases, each as a whole phrase and then allowing typos.
 */
function kounselia_message_matches_safety_keywords( $content ) {
    $text = kounselia_normalize_safety_text( $content );
    if ( '' === $text ) {
        return false;
    }

    // The admin list has always matched anywhere in the text, so a stem
    // like "abus" still catches "abused" exactly as it did before.
    $lower = strtolower( (string) $content );
    foreach ( kounselia_get_safety_keywords() as $kw ) {
        if ( '' !== $kw && false !== strpos( $lower, $kw ) ) {
            return $kw;
        }
    }

    $padded = ' ' . $text . ' ';
    $words  = explode( ' ', $text );
    $lists  = array_merge( kounselia_get_safety_keywords(), kounselia_builtin_crisis_phrases() );

    foreach ( $lists as $kw ) {
        $normalized_kw = kounselia_normalize_safety_text( $kw );
        if ( '' === $normalized_kw ) {
            continue;
        }
        if ( false !== strpos( $padded, ' ' . $normalized_kw . ' ' ) ) {
            return $kw;
        }
    }

    foreach ( $lists as $kw ) {
        $kw_words = explode( ' ', kounselia_normalize_safety_text( $kw ) );
        $count    = count( $kw_words );
        if ( '' === $kw_words[0] || $count > count( $words ) ) {
            continue;
        }
        for ( $i = 0; $i + $count <= count( $words ); $i++ ) {
            $all = true;
            for ( $j = 0; $j < $count; $j++ ) {
                if ( ! kounselia_safety_word_matches( $words[ $i + $j ], $kw_words[ $j ] ) ) {
                    $all = false;
                    break;
                }
            }
            if ( $all ) {
                return $kw;
            }
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

function kounselia_parse_gemini_keys( $raw ) {
    $values = is_array( $raw ) ? $raw : preg_split( '/[\r\n,;]+/', (string) $raw );
    $keys   = array();

    foreach ( $values as $value ) {
        if ( is_string( $value ) || is_numeric( $value ) ) {
            $value = trim( (string) $value );
            if ( '' !== $value ) {
                $keys[] = $value;
            }
        }
    }

    return array_values( array_unique( $keys ) );
}

function kounselia_get_gemini_keys() {
    $raw = get_option( 'kounselia_gemini_key', false );
    if ( false === $raw && defined( 'KOUNSELIA_GEMINI_KEY' ) ) {
        $raw = KOUNSELIA_GEMINI_KEY;
    }
    return kounselia_parse_gemini_keys( $raw );
}

function kounselia_key_cooldown_hash( $api_key ) {
    return substr( md5( $api_key ), 0, 12 );
}

function kounselia_mark_key_throttled( $api_key, $seconds = 60 ) {
    $cooldowns = get_transient( 'kounselia_key_cooldowns' );
    $cooldowns = is_array( $cooldowns ) ? $cooldowns : array();

    $now = time();
    foreach ( $cooldowns as $hash => $expiry ) {
        if ( $expiry < $now ) {
            unset( $cooldowns[ $hash ] );
        }
    }

    $cooldowns[ kounselia_key_cooldown_hash( $api_key ) ] = $now + max( 60, (int) $seconds );
    $latest_expiry = max( $cooldowns );
    set_transient( 'kounselia_key_cooldowns', $cooldowns, max( 120, $latest_expiry - $now + 60 ) );
}

function kounselia_filter_available_keys( $keys ) {
    $keys = array_values( array_unique( $keys ) );
    $cooldowns = get_transient( 'kounselia_key_cooldowns' );
    if ( empty( $cooldowns ) || ! is_array( $cooldowns ) ) {
        return kounselia_rotate_gemini_key_order( $keys );
    }

    $now = time();
    $available = array_values( array_filter( $keys, function ( $key ) use ( $cooldowns, $now ) {
        $hash = kounselia_key_cooldown_hash( $key );
        return ! isset( $cooldowns[ $hash ] ) || $cooldowns[ $hash ] < $now;
    } ) );

    $cooling = array_values( array_diff( $keys, $available ) );
    $primary = empty( $available ) ? $keys : $available;
    $cursor  = count( $primary ) > 1 ? absint( get_option( 'kounselia_gemini_key_rotation_cursor', 0 ) ) % count( $primary ) : 0;

    if ( count( $primary ) > 1 ) {
        update_option( 'kounselia_gemini_key_rotation_cursor', ( $cursor + 1 ) % count( $primary ), false );
        $primary = array_merge( array_slice( $primary, $cursor ), array_slice( $primary, 0, $cursor ) );
    }

    // Cooldowns are a preference, not a hard exclusion: if the active keys
    // fail too, this request still gets a chance to use the rest of the pool.
    return empty( $available ) ? $primary : array_merge( $primary, $cooling );
}

function kounselia_rotate_gemini_key_order( $keys ) {
    $keys = array_values( $keys );
    $count = count( $keys );
    if ( $count < 2 ) {
        return $keys;
    }

    $cursor = absint( get_option( 'kounselia_gemini_key_rotation_cursor', 0 ) ) % $count;
    update_option( 'kounselia_gemini_key_rotation_cursor', ( $cursor + 1 ) % $count, false );

    return array_merge( array_slice( $keys, $cursor ), array_slice( $keys, 0, $cursor ) );
}

function kounselia_gemini_response_is_key_error( $status, $data ) {
    $error   = isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : array();
    $code    = isset( $error['status'] ) ? strtoupper( (string) $error['status'] ) : '';
    $message = isset( $error['message'] ) ? (string) $error['message'] : '';

    if ( 429 === (int) $status || in_array( $code, array( 'RESOURCE_EXHAUSTED', 'QUOTA_EXCEEDED', 'UNAUTHENTICATED', 'PERMISSION_DENIED', 'API_KEY_INVALID', 'INVALID_API_KEY' ), true ) ) {
        return true;
    }

    if ( in_array( (int) $status, array( 401, 403 ), true ) ) {
        return true;
    }

    return false !== stripos( $message, 'api key' ) || false !== stripos( $message, 'api_key' );
}

function kounselia_gemini_key_cooldown_seconds( $status, $data, $response ) {
    $error   = isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : array();
    $code    = isset( $error['status'] ) ? strtoupper( (string) $error['status'] ) : '';
    $message = isset( $error['message'] ) ? (string) $error['message'] : '';
    $quota   = 429 === (int) $status || in_array( $code, array( 'RESOURCE_EXHAUSTED', 'QUOTA_EXCEEDED' ), true )
        || ( false !== stripos( $message, 'quota' ) || false !== stripos( $message, 'rate limit' ) );

    if ( ! $quota ) {
        return HOUR_IN_SECONDS;
    }

    $retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
    if ( $retry_after < 1 && ! empty( $error['details'] ) && is_array( $error['details'] ) ) {
        foreach ( $error['details'] as $detail ) {
            if ( isset( $detail['retryDelay'] ) && preg_match( '/^(\d+(?:\.\d+)?)s$/', (string) $detail['retryDelay'], $matches ) ) {
                $retry_after = (int) ceil( (float) $matches[1] );
                break;
            }
        }
    }

    return max( 60, min( HOUR_IN_SECONDS, $retry_after ) );
}

/**
 * POST to a Gemini endpoint, moving through every configured key after a
 * key-specific quota/authentication failure. The API key stays server-side.
 */
function kounselia_gemini_post_with_key_rotation( $endpoint, $body, $timeout, &$error_out ) {
    $keys = kounselia_get_gemini_keys();
    if ( empty( $keys ) ) {
        $error_out = 'AI is not configured yet.';
        return null;
    }

    $keys = kounselia_filter_available_keys( $keys );

    foreach ( $keys as $api_key ) {
        $response = wp_remote_post( $endpoint, array(
            'headers' => array(
                'Content-Type'   => 'application/json',
                'x-goog-api-key' => $api_key,
            ),
            'body'    => $body,
            'timeout' => $timeout,
        ) );

        if ( is_wp_error( $response ) ) {
            $error_out = 'Could not reach the Gemini API.';
            return null;
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $data   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( kounselia_gemini_response_is_key_error( $status, is_array( $data ) ? $data : array() ) ) {
            kounselia_mark_key_throttled( $api_key, kounselia_gemini_key_cooldown_seconds( $status, $data, $response ) );
            continue;
        }

        if ( $status >= 200 && $status < 300 ) {
            return $response;
        }

        $message = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : 'Unexpected response from Gemini.';
        $error_out = 'Gemini API request failed (HTTP ' . $status . '): ' . $message;
        return $response;
    }

    $error_out = 'The AI service is temporarily unavailable. Please retry shortly.';
    return null;
}

/**
 * Call Gemini with a model cascade and failover across the entire key pool.
 */
function kounselia_call_gemini( $system_prompt, $contents, $primary_model, $temperature, $max_tokens, &$error_out, $response_mime_type = 'text/plain', $timeout = 25 ) {
    $model_stack = array( $primary_model );
    
    // Model Cascade Logic (Hybrid Routing):
    if ( false !== strpos( $primary_model, 'pro' ) ) {
        // If the pro model hits quota issues on heavy tasks, fallback to the fast, standard flash tier
        $model_stack[] = 'gemini-3.6-flash'; 
    } elseif ( false !== strpos( $primary_model, 'flash' ) && false === strpos( $primary_model, 'lite' ) ) {
        // If the standard flash task fails, the lite version acts as safety net
        $model_stack[] = 'gemini-3.5-flash-lite';
    }

    foreach ( $model_stack as $model ) {
        $generation_config = array(
            'temperature'     => (float) $temperature,
            'maxOutputTokens' => (int) $max_tokens,
            'responseMimeType'=> $response_mime_type,
        );

        $body = wp_json_encode( array(
            'system_instruction' => array( 'parts' => array( array( 'text' => $system_prompt ) ) ),
            'contents'           => $contents,
            'generationConfig'   => $generation_config,
        ) );

        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent';
        $response = kounselia_gemini_post_with_key_rotation( $endpoint, $body, $timeout, $error_out );
        if ( null === $response ) {
            return null;
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $data   = json_decode( wp_remote_retrieve_body( $response ), true );

        // An unavailable model can use the configured fallback model; malformed
        // prompts and other request-wide errors should not burn through keys.
        if ( 404 === $status ) {
            $error_out = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : 'The selected Gemini model is unavailable.';
            continue;
        }

        $reply = isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ? $data['candidates'][0]['content']['parts'][0]['text'] : null;
        if ( ! empty( $reply ) ) {
            return trim( $reply );
        }

        if ( empty( $error_out ) ) {
            $error_out = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : 'Unexpected response from AI.';
        }
        return null;
    }

    if ( empty( $error_out ) ) {
        $error_out = 'Empty response from AI.';
    }
    return null;
}

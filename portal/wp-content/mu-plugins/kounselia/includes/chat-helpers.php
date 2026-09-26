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
<?php
/**
 * STREAMING_CHUNK:Initializing voice API connections...
 * Kounselia Core — text-to-speech and continuous voice (Gemini Live API)
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * 12. VOICE: TEXT-TO-SPEECH
 * ---------------------------------------------------------------------- */

function kounselia_pcm_to_wav( $pcm, $sample_rate = 24000, $channels = 1, $bits_per_sample = 16 ) {
    $byte_rate   = $sample_rate * $channels * ( $bits_per_sample / 8 );
    $block_align = $channels * ( $bits_per_sample / 8 );
    $data_size   = strlen( $pcm );

    $header  = 'RIFF' . pack( 'V', 36 + $data_size ) . 'WAVE';
    $header .= 'fmt ' . pack( 'VvvVVvv', 16, 1, $channels, $sample_rate, $byte_rate, $block_align, $bits_per_sample );
    $header .= 'data' . pack( 'V', $data_size );

    return $header . $pcm;
}

function kounselia_call_gemini_tts( $text, $voice_name, &$error_out ) {
    $keys = kounselia_get_gemini_keys();
    if ( empty( $keys ) ) {
        $error_out = 'Voice is not configured yet, please set kounselia_gemini_key.';
        return null;
    }
    $api_key = $keys[ array_rand( $keys ) ];

    $model = 'gemini-2.5-flash-preview-tts';
    $url   = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $api_key;

    $body = wp_json_encode( array(
        'contents'         => array( array( 'parts' => array( array( 'text' => $text ) ) ) ),
        'generationConfig' => array(
            'responseModalities' => array( 'AUDIO' ),
            'speechConfig'       => array(
                'voiceConfig' => array(
                    'prebuiltVoiceConfig' => array( 'voiceName' => $voice_name ),
                ),
            ),
        ),
    ) );

    $response = wp_remote_post( $url, array(
        'headers' => array( 'Content-Type' => 'application/json' ),
        'body'    => $body,
        'timeout' => 30,
    ) );

    if ( is_wp_error( $response ) ) {
        $error_out = $response->get_error_message();
        return null;
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    $b64  = isset( $data['candidates'][0]['content']['parts'][0]['inlineData']['data'] )
        ? $data['candidates'][0]['content']['parts'][0]['inlineData']['data']
        : null;

    if ( ! $b64 ) {
        $error_out = isset( $data['error']['message'] ) ? $data['error']['message'] : 'No audio returned.';
        return null;
    }

    $pcm = base64_decode( $b64 );
    return ( false === $pcm ) ? null : $pcm;
}

function kounselia_ajax_tts() {
    kounselia_verify_nonce();

    if ( kounselia_rate_limited( 'tts', 30, 60 ) ) {
        wp_send_json_error( array( 'message' => 'Please slow down a little.' ), 429 );
    }

    $message_id = isset( $_POST['message_id'] ) ? absint( $_POST['message_id'] ) : 0;
    if ( ! $message_id ) {
        wp_send_json_error( array( 'message' => 'Missing message.' ), 400 );
    }

    global $wpdb;
    $messages_table = $wpdb->prefix . 'kounselia_messages';
    $sessions_table = $wpdb->prefix . 'kounselia_sessions';

    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT m.content, s.counselor_slug, s.user_id, s.guest_token
         FROM {$messages_table} m
         INNER JOIN {$sessions_table} s ON s.id = m.session_id
         WHERE m.id = %d AND m.sender = 'bot' LIMIT 1",
        $message_id
    ) );

    if ( ! $row ) {
        wp_send_json_error( array( 'message' => 'That message could not be found.' ), 404 );
    }

    $user_id = is_user_logged_in() ? get_current_user_id() : 0;
    if ( $row->user_id ) {
        if ( (int) $row->user_id !== $user_id ) {
            wp_send_json_error( array( 'message' => 'Not authorized.' ), 403 );
        }
    } else {
        $guest_token = isset( $_POST['guest_token'] ) ? sanitize_text_field( wp_unslash( $_POST['guest_token'] ) ) : '';
        if ( '' === $guest_token || $guest_token !== $row->guest_token ) {
            wp_send_json_error( array( 'message' => 'Not authorized.' ), 403 );
        }
    }

    $cache_key = 'kounselia_tts_' . $message_id;
    $cached    = get_transient( $cache_key );
    if ( $cached ) {
        wp_send_json_success( array( 'audio' => $cached ) );
    }

    $prompt = kounselia_get_counselor_prompt( $row->counselor_slug );
    $voice  = ( $prompt && ! empty( $prompt['tts_voice'] ) ) ? $prompt['tts_voice'] : 'Kore';

    $text = mb_substr( $row->content, 0, 3000 );

    $error = '';
    $pcm   = kounselia_call_gemini_tts( $text, $voice, $error );

    if ( null === $pcm ) {
        wp_send_json_error( array(
            'message' => "Voice isn't available right now, please try again in a moment.",
            'debug'   => WP_DEBUG ? $error : null,
        ), 502 );
    }

    $wav      = kounselia_pcm_to_wav( $pcm );
    $data_uri = 'data:audio/wav;base64,' . base64_encode( $wav );

    set_transient( $cache_key, $data_uri, HOUR_IN_SECONDS );

    wp_send_json_success( array( 'audio' => $data_uri ) );
}
add_action( 'wp_ajax_kounselia_tts', 'kounselia_ajax_tts' );
add_action( 'wp_ajax_nopriv_kounselia_tts', 'kounselia_ajax_tts' );


/* -------------------------------------------------------------------------
 * STREAMING_CHUNK:Configuring Continuous Voice Live connection...
 * 13. CONTINUOUS VOICE (Gemini Live API)
 * ---------------------------------------------------------------------- */

function kounselia_get_user_plan( $user_id ) {
    // Paid subscribers AND admin-granted Pro members (see membership.php).
    if ( function_exists( 'kounselia_member_tier' ) ) {
        return kounselia_member_tier( $user_id );
    }
    $plan = get_user_meta( $user_id, 'kounselia_plan', true );
    return ( 'pro' === $plan ) ? 'pro' : 'free';
}

function kounselia_voice_allowed_seconds( $user_id ) {
    // Dynamically fetch from options, fallback to defaults if not set
    $free_minutes = (int) get_option('kounselia_voice_free_minutes', 5);
    $pro_minutes  = (int) get_option('kounselia_voice_pro_minutes', 15);
    
    $minutes = ( 'pro' === kounselia_get_user_plan( $user_id ) ) ? $pro_minutes : $free_minutes;
    return $minutes * 60;
}

function kounselia_mint_live_token( $system_prompt, $voice_name, $manual_turn_control, &$error_out ) {
    $keys = kounselia_get_gemini_keys();
    if ( empty( $keys ) ) {
        $error_out = 'Voice is not configured yet, please set kounselia_gemini_key.';
        return null;
    }
    $api_key = $keys[ array_rand( $keys ) ];

    $now            = time();
    $expire_time    = gmdate( 'Y-m-d\TH:i:s\Z', $now + ( 30 * MINUTE_IN_SECONDS ) );
    $new_session_by = gmdate( 'Y-m-d\TH:i:s\Z', $now + MINUTE_IN_SECONDS );

    // Turn control: when the frontend hands control of "when I'm done
    // talking" to the user (a tap-to-stop / push-to-talk style control)
    // instead of letting Gemini's own voice-activity detector decide,
    // the Live session setup MUST say so.
    $realtime_input_config = $manual_turn_control
        ? array(
            'automaticActivityDetection' => array(
                'disabled' => true,
            ),
        )
        : array(
            'automaticActivityDetection' => array(
                'disabled' => false,
            ),
        );
        
    $live_model = get_option('kounselia_live_model', 'gemini-3.1-flash-live-preview');

    $body = wp_json_encode( array(
        'uses'                 => 1,
        'expireTime'           => $expire_time,
        'newSessionExpireTime' => $new_session_by,
        'bidiGenerateContentSetup' => array(
            'model'            => 'models/' . $live_model,
            'generationConfig' => array(
                'responseModalities' => array( 'AUDIO' ),
                'speechConfig'       => array(
                    'voiceConfig' => array(
                        'prebuiltVoiceConfig' => array( 'voiceName' => $voice_name ),
                    ),
                ),
            ),
            'realtimeInputConfig'       => $realtime_input_config,
            'systemInstruction'         => array( 'parts' => array( array( 'text' => $system_prompt ) ) ),
            'inputAudioTranscription'   => new stdClass(),
            'outputAudioTranscription'  => new stdClass(),
        ),
    ) );

    $response = wp_remote_post( 'https://generativelanguage.googleapis.com/v1alpha/auth_tokens', array(
        'headers' => array(
            'Content-Type'  => 'application/json',
            'x-goog-api-key' => $api_key,
        ),
        'body'    => $body,
        'timeout' => 20,
    ) );

    if ( is_wp_error( $response ) ) {
        $error_out = $response->get_error_message();
        return null;
    }

    $status     = wp_remote_retrieve_response_code( $response );
    $raw_body   = wp_remote_retrieve_body( $response );
    $data       = json_decode( $raw_body, true );

    if ( empty( $data['name'] ) ) {
        if ( isset( $data['error']['message'] ) ) {
            $error_out = 'HTTP ' . $status . ': ' . $data['error']['message'];
        } else {
            $error_out = 'HTTP ' . $status . ': ' . mb_substr( trim( $raw_body ), 0, 500 );
        }
        return null;
    }

    return array(
        'token'       => $data['name'],
        'expire_time' => $expire_time,
    );
}

function kounselia_ajax_voice_token() {
    kounselia_verify_nonce();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Voice conversations are available to signed-in members, please sign in first.' ), 401 );
    }

    if ( kounselia_rate_limited( 'voice_token', 10, 300 ) ) {
        wp_send_json_error( array( 'message' => 'Please wait a little before starting another voice session.' ), 429 );
    }

    $counselor_slug = isset( $_POST['counselor'] ) ? sanitize_key( wp_unslash( $_POST['counselor'] ) ) : '';
    if ( ! in_array( $counselor_slug, kounselia_counselor_slugs(), true ) ) {
        wp_send_json_error( array( 'message' => 'Unknown counselor.' ), 400 );
    }

    $prompt = kounselia_get_counselor_prompt( $counselor_slug );
    if ( ! $prompt || empty( $prompt['voice_enabled'] ) ) {
        wp_send_json_error( array( 'message' => "Voice conversation isn't available for this counselor yet." ), 400 );
    }

    $user_id     = get_current_user_id();
    $system_prompt = $prompt['system_prompt'] . kounselia_user_context_clause( $user_id ) . kounselia_imported_memory_clause( $user_id );

    $requested_sid = isset( $_POST['session_id'] ) ? absint( $_POST['session_id'] ) : 0;
    $session_id    = kounselia_resolve_session( $counselor_slug, $user_id, '', $requested_sid );

    $turn_mode           = isset( $_POST['turn_mode'] ) ? sanitize_key( wp_unslash( $_POST['turn_mode'] ) ) : 'auto';
    $manual_turn_control = ( 'manual' === $turn_mode );

    $error = '';
    $token = kounselia_mint_live_token( $system_prompt, $prompt['tts_voice'], $manual_turn_control, $error );

    if ( null === $token ) {
        wp_send_json_error( array(
            'message' => "Voice isn't available right now, please try again in a moment.",
            'debug'   => $error,
        ), 502 );
    }

    $live_model = get_option('kounselia_live_model', 'gemini-3.1-flash-live-preview');

    wp_send_json_success( array(
        'token'           => $token['token'],
        'expire_time'     => $token['expire_time'],
        'model'           => $live_model,
        'session_id'      => $session_id,
        'allowed_seconds' => kounselia_voice_allowed_seconds( $user_id ),
        'plan'            => kounselia_get_user_plan( $user_id ),
        'manual_turn_control' => $manual_turn_control,
    ) );
}
add_action( 'wp_ajax_kounselia_voice_token', 'kounselia_ajax_voice_token' );

function kounselia_ajax_log_voice_turn() {
    kounselia_verify_nonce();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Not authorized.' ), 401 );
    }

    $session_id = isset( $_POST['session_id'] ) ? absint( $_POST['session_id'] ) : 0;
    $sender     = ( isset( $_POST['sender'] ) && 'bot' === $_POST['sender'] ) ? 'bot' : 'user';
    $text       = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : '';

    if ( ! $session_id || '' === trim( $text ) ) {
        wp_send_json_error( array( 'message' => 'Missing data.' ), 400 );
    }

    global $wpdb;
    $owned = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}kounselia_sessions WHERE id = %d AND user_id = %d LIMIT 1",
        $session_id, get_current_user_id()
    ) );
    if ( ! $owned ) {
        wp_send_json_error( array( 'message' => 'Not authorized.' ), 403 );
    }

    kounselia_log_message( $session_id, $sender, mb_substr( $text, 0, 4000 ) );

    wp_send_json_success();
}
add_action( 'wp_ajax_kounselia_log_voice_turn', 'kounselia_ajax_log_voice_turn' );
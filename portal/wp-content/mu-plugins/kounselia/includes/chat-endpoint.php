<?php
/**
 * STREAMING_CHUNK:Initializing the chat endpoint...
 * Kounselia Core — the main kounselia_chat AJAX endpoint
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * 11. CHAT: MAIN AJAX ENDPOINT
 * ---------------------------------------------------------------------- */

function kounselia_ajax_chat() {
    kounselia_verify_nonce();

    // 1. IP Ban Check
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $banned_ips = get_option( 'kounselia_banned_ips', array() );
    if ( in_array( $ip, $banned_ips ) ) {
        wp_send_json_error( array( 'message' => 'Access denied from this network.' ), 403 );
    }

    // 2. User Ban Check
    if ( is_user_logged_in() && get_user_meta( get_current_user_id(), 'kounselia_banned', true ) ) {
        wp_send_json_error( array( 'message' => 'Your account has been suspended for violating our terms of service.' ), 403 );
    }

    if ( kounselia_rate_limited( 'chat', 40, 60 ) ) {
        wp_send_json_error( array( 'message' => 'You are sending messages a little too fast, please slow down.' ), 429 );
    }

    $counselor_slug = isset( $_POST['counselor'] ) ? sanitize_key( wp_unslash( $_POST['counselor'] ) ) : '';
    $message        = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
    $requested_sid  = isset( $_POST['session_id'] ) ? absint( $_POST['session_id'] ) : 0;

    if ( ! in_array( $counselor_slug, kounselia_counselor_slugs(), true ) ) {
        wp_send_json_error( array( 'message' => 'Unknown counselor.' ), 400 );
    }
    if ( '' === trim( $message ) ) {
        wp_send_json_error( array( 'message' => 'Please enter a message.' ), 400 );
    }
    if ( mb_strlen( $message ) > 4000 ) {
        wp_send_json_error( array( 'message' => 'That message is too long, please shorten it.' ), 400 );
    }

    $user_id     = is_user_logged_in() ? get_current_user_id() : 0;
    $guest_token = '';

    if ( ! $user_id ) {
        $guest_token = isset( $_POST['guest_token'] ) ? sanitize_text_field( wp_unslash( $_POST['guest_token'] ) ) : '';
        if ( '' === $guest_token ) {
            wp_send_json_error( array( 'message' => 'Missing guest identity, please refresh the page and try again.' ), 400 );
        }

        $daily_count = kounselia_bump_guest_daily_count( $guest_token );
        if ( $daily_count > KOUNSELIA_GUEST_DAILY_CAP ) {
            wp_send_json_error( array(
                'message'      => "You've reached today's guest limit. Create a free account to keep going.",
                'daily_limit'  => true,
            ), 429 );
        }
    }

    $session_id = kounselia_resolve_session( $counselor_slug, $user_id, $guest_token, $requested_sid );

    if ( ! $user_id ) {
        $used_so_far = kounselia_session_user_message_count( $session_id );
        if ( $used_so_far >= KOUNSELIA_GUEST_SESSION_LIMIT ) {
            wp_send_json_success( array(
                'limit_reached'      => true,
                'session_id'         => $session_id,
                'messages_remaining' => 0,
            ) );
        }
    }

    kounselia_log_message( $session_id, 'user', $message );

    $prompt = kounselia_get_counselor_prompt( $counselor_slug );
    if ( ! $prompt ) {
        wp_send_json_error( array( 'message' => 'This counselor is not available right now.' ), 500 );
    }

    $system_prompt = $prompt['system_prompt'];

    // GLOBAL COMMUNICATION PARADIGM (The "Friend" Override)
    $system_prompt .= "\n\nGLOBAL COMMUNICATION PARADIGM: You must completely drop the 'clinical therapist' persona. Speak to the user like a deeply empathetic, highly intelligent friend who has known them for years.
    1. TONE: Be warm, casual, and direct. NEVER use robotic validation ('I hear you saying...', 'It sounds like you feel...', 'That must be so hard'). 
    2. STRUCTURE: Do not write in structured essays or rigid paragraphs. Let the conversation flow naturally, like a messaging thread. 
    3. QUESTIONS: Do NOT end every single message with a question. That feels like an interrogation. Sometimes just share an observation, sit in the silence with them, or relate to what they said.
    4. LENGTH: Vary your length. Sometimes 1 or 2 sentences is far more powerful and human than a long explanation.";

    if ( $user_id ) {
        $system_prompt .= kounselia_user_context_clause( $user_id );
        $system_prompt .= kounselia_imported_memory_clause( $user_id );
    }

    $history = kounselia_build_gemini_history( $session_id, 16 );

    // --------------------------------------------------------------------
    // FEATURE: AI TEAM COLLABORATION (Smarter Context Triage)
    // --------------------------------------------------------------------
    $consulted_names = array();
    $consult_notes   = array();
    $collab_debug    = (bool) get_option( 'kounselia_collab_debug', false );

    $collab_trigger_length = (int) get_option( 'kounselia_collab_min_chars', 40 );

    if ( mb_strlen( $message ) > $collab_trigger_length ) {

        // Build brief context for the router to understand vague pronouns
        $triage_context = "";
        $context_turns = array_slice( $history, -4 ); 
        foreach ( $context_turns as $turn ) {
            $speaker = ( $turn['role'] === 'model' ) ? 'Counselor' : 'Client';
            $triage_context .= "{$speaker}: {$turn['parts'][0]['text']}\n";
        }

        $triage_prompt = "You are a clinical triage router. Evaluate the client's LATEST message, using the recent context to understand what they are referring to if they use vague terms.\n\n" .
        "RECENT CONTEXT:\n{$triage_context}\n\n" .
        "LATEST MESSAGE: \"{$message}\"\n\n" .
        "Does the LATEST message require specialized insight? Available specialists:\n" .
        "- dr_lena: Trauma, severe anxiety, PTSD, abuse, feeling unsafe\n" .
        "- marcus: Career, purpose, feeling lost, workplace issues\n" .
        "- eli: Marriage, breakups, relationships, profound loneliness\n" .
        "- theo: Grief, death, mourning, profound loss\n" .
        "- priya: Burnout, extreme exhaustion, overwhelm, life balance\n" .
        "- james: Men's mental health, toxic stoicism, male pressures\n\n" .
        "If it does NOT need a specialist, output exactly: {\"specialists\": []}\n" .
        "If it does, output JSON like: {\"specialists\": [{\"slug\": \"specialist_slug\", \"confidence\": 0.0-1.0}]}\n" .
        "You may include up to 2 specialists if the message genuinely spans two areas. Order by confidence, highest first.\n" .
        "Return ONLY valid JSON, nothing else.";

        $err = '';

        $triage_result = kounselia_call_gemini(
            "You are a strict routing system. Output ONLY valid JSON.",
            array( array( 'role' => 'user', 'parts' => array( array( 'text' => $triage_prompt ) ) ) ),
            'gemini-2.5-flash-lite',
            0.1,
            200,
            $err,
            'application/json',
            10
        );

        if ( $collab_debug ) {
            error_log( '[Kounselia Collab] router raw response: ' . var_export( $triage_result, true ) );
            if ( ! $triage_result ) {
                error_log( '[Kounselia Collab] router call FAILED. Error was: ' . $err );
            }
        }

        $target_peers = array();

        if ( $triage_result ) {
            $triage_result = trim( $triage_result );

            $start = strpos( $triage_result, '{' );
            $end   = strrpos( $triage_result, '}' );

            if ( $start !== false && $end !== false ) {
                $json_slice   = substr( $triage_result, $start, $end - $start + 1 );
                $triage_data  = json_decode( $json_slice, true );

                if ( is_array( $triage_data ) && ! empty( $triage_data['specialists'] ) && is_array( $triage_data['specialists'] ) ) {
                    foreach ( array_slice( $triage_data['specialists'], 0, 2 ) as $spec ) {
                        $slug = isset( $spec['slug'] ) ? strtolower( trim( $spec['slug'] ) ) : '';
                        if ( $slug && in_array( $slug, kounselia_counselor_slugs(), true ) && $slug !== $counselor_slug ) {
                            $target_peers[] = $slug;
                        }
                    }
                } elseif ( $collab_debug ) {
                    error_log( '[Kounselia Collab] router JSON did not contain a usable "specialists" array: ' . $json_slice );
                }
            } elseif ( $collab_debug ) {
                error_log( '[Kounselia Collab] could not find { } in router output.' );
            }
        }

        // Stage 2: actually consult each chosen colleague, in their own voice.
        foreach ( $target_peers as $target_peer ) {
            $peer_prompt_data = kounselia_get_counselor_prompt( $target_peer );
            if ( ! $peer_prompt_data ) {
                continue;
            }

            $peer_query = "A colleague is currently in session with this client and asked for your take, " .
                "as their own private clinical note, on this one message the client just sent:\n\n" .
                "\"{$message}\"\n\n" .
                "Reply with ONE sentence only, in your own natural voice, no greeting, no disclaimers, " .
                "just the insight itself as if scribbling a quick note to a colleague.";

            $peer_err     = '';
            $peer_insight = kounselia_call_gemini(
                $peer_prompt_data['system_prompt'],
                array( array( 'role' => 'user', 'parts' => array( array( 'text' => $peer_query ) ) ) ),
                'gemini-3.5-flash-lite',
                0.6,
                120,
                $peer_err,
                'text/plain',
                10
            );

            if ( $collab_debug ) {
                error_log( "[Kounselia Collab] peer call to {$target_peer}: " . var_export( $peer_insight, true ) );
            }

            if ( empty( $peer_insight ) ) {
                continue;
            }

            $insight        = trim( $peer_insight );
            $c_name_display = ucwords( str_replace( '_', ' ', $target_peer ) );

            $consult_notes[]   = "{$c_name_display} says: \"{$insight}\"";
            $consulted_names[] = $c_name_display;

            $log_id = kounselia_log_message( $session_id, 'peer_consult', wp_json_encode( array(
                'peer_slug' => $target_peer,
                'insight'   => $insight,
            ) ) );

            if ( $collab_debug ) {
                error_log( "[Kounselia Collab] logged peer_consult row, insert_id={$log_id}" );
            }
        }
    }

    if ( ! empty( $consult_notes ) ) {
        $system_prompt .= "\n\nAI TEAM COLLABORATION (Peer Consult): You asked your clinical team for a second opinion on this specific message. " . implode( " ", $consult_notes ) . " -> CRITICAL RULE: Use this insight to shape your *internal understanding* of what the user is going through, but DO NOT lecture them, diagnose them, or sound like a psychology textbook. Respond naturally, conversationally, and warmly. Speak to them like a friend who deeply understands, not a robot summarizing a theory.";
    }
    // --------------------------------------------------------------------

    $error = '';

    // Default to the highly performant 3.6 Flash for the main chat UI.
    $active_model = ! empty( $prompt['ai_model'] ) ? $prompt['ai_model'] : 'gemini-3.6-flash';
    $max_tokens   = ! empty( $prompt['max_tokens'] ) ? (int) $prompt['max_tokens'] : 800;

    $reply = kounselia_call_gemini(
        $system_prompt,
        $history,
        $active_model,
        $prompt['temperature'],
        $max_tokens,
        $error
    );

    if ( null === $reply ) {
        wp_send_json_error( array(
            'message'    => "I'm having trouble connecting right now. Please try again in a moment.",
            'session_id' => $session_id,
            'debug'      => WP_DEBUG ? $error : null,
        ), 502 );
    }

    $bot_message_id = kounselia_log_message( $session_id, 'bot', $reply );

    $response = array(
        'reply'      => $reply,
        'session_id' => $session_id,
        'message_id' => $bot_message_id,
    );

    // Pass the consulted team members back to the frontend UI
    if ( ! empty( $consulted_names ) ) {
        $response['consulted'] = $consulted_names;
    }

    if ( ! $user_id ) {
        $used_now = kounselia_session_user_message_count( $session_id );
        $remaining = max( 0, KOUNSELIA_GUEST_SESSION_LIMIT - $used_now );
        $response['messages_remaining'] = $remaining;
        $response['limit_reached']      = ( 0 === $remaining );
    }

    wp_send_json_success( $response );
}
add_action( 'wp_ajax_kounselia_chat', 'kounselia_ajax_chat' );
add_action( 'wp_ajax_nopriv_kounselia_chat', 'kounselia_ajax_chat' );

/* -------------------------------------------------------------------------
 * 11-B. MULTIMODAL AUDIO TRANSCRIPTION
 * Highly accurate Gemini flow replacing browser SpeechRecognition 
 * ---------------------------------------------------------------------- */

function kounselia_ajax_transcribe() {
    kounselia_verify_nonce();

    if ( function_exists('kounselia_rate_limited') && kounselia_rate_limited( 'transcribe', 30, 60 ) ) {
        wp_send_json_error( array( 'message' => 'Please wait a moment before dictating again.' ), 429 );
    }

    $audio_b64 = isset( $_POST['audio_b64'] ) ? wp_unslash( $_POST['audio_b64'] ) : '';
    $mime_type = isset( $_POST['mime_type'] ) ? sanitize_text_field( wp_unslash( $_POST['mime_type'] ) ) : 'audio/webm';
    
    // Clean up mime type to ensure API compatibility
    $mime_parts = explode( ';', $mime_type );
    $mime_type = trim( $mime_parts[0] );

    if ( empty( $audio_b64 ) ) {
        wp_send_json_error( array( 'message' => 'No audio received.' ), 400 );
    }
    
    // Enforce base64 encoding without spacing anomalies
    $audio_b64 = str_replace( ' ', '+', $audio_b64 );

    $contents = array(
        array(
            'role' => 'user',
            'parts' => array(
                array(
                    'inlineData' => array(
                        'mimeType' => $mime_type,
                        'data'     => $audio_b64,
                    )
                ),
                array(
                    'text' => "Transcribe the following audio accurately. Output ONLY the raw transcribed text. Do not include any conversational filler, markdown formatting, quotes, or conversational preamble."
                )
            )
        )
    );

    $error = '';
    
    // Use gemini-3.6-flash for multimodal audio transcription (extremely fast and accurate)
    $reply = kounselia_call_gemini(
        "You are a highly accurate audio transcription assistant. Your only job is to transcribe audio to text perfectly.",
        $contents,
        'gemini-3.6-flash',
        0.1, 
        2000,
        $error,
        'text/plain',
        25
    );

    if ( ! empty( $reply ) ) {
        wp_send_json_success( array( 'text' => trim( $reply ) ) );
    } else {
        wp_send_json_error( array( 'message' => "Transcription failed.", 'debug' => WP_DEBUG ? $error : null ), 502 );
    }
}
add_action( 'wp_ajax_kounselia_transcribe', 'kounselia_ajax_transcribe' );
add_action( 'wp_ajax_nopriv_kounselia_transcribe', 'kounselia_ajax_transcribe' );
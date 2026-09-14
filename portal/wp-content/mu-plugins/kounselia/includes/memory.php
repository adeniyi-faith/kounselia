<?php
/**
 * STREAMING_CHUNK:Initializing the Memory Engine...
 * Kounselia Core — structured JSON memory engine: import, delete, synthesis, and reflection
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * 18. STRUCTURED MEMORY ENGINE (The JSON Read/Write Pipeline)
 * ---------------------------------------------------------------------- */

/**
 * Append the user's structured JSON memory to a counselor system prompt.
 * Minifies the JSON by stripping empty fields before injection to save tokens.
 */
function kounselia_imported_memory_clause( $user_id ) {
    $memory_json = get_user_meta( $user_id, 'kounselia_core_memory', true );
    if ( empty( $memory_json ) ) {
        return '';
    }

    $memory_array = json_decode( $memory_json, true );
    if ( ! is_array( $memory_array ) ) {
        return '';
    }

    // Minify: Remove empty strings and empty arrays to save tokens
    $minified_memory = array_filter( $memory_array, function( $value ) {
        if ( is_array( $value ) ) return ! empty( $value );
        return $value !== '' && $value !== null;
    });

    if ( empty( $minified_memory ) ) {
        return '';
    }

    $json_to_inject = wp_json_encode( $minified_memory );

    $clause = "\n\nPERSONAL CONTEXT (Structured Memory Profile): The following is a validated JSON profile of the user. Use this information to deeply understand them, but DO NOT reference it explicitly (e.g., never say 'According to my notes' or 'I see in your profile'). Let this context naturally inform your empathy and the direction of the conversation.\n\n" . $json_to_inject;

    $clause .= kounselia_live_pattern_clause( $user_id );

    return $clause;
}

/**
 * The full Milestone Reflection has six sections (patterns, growth,
 * fears, blind spots, achievements, recommendations) — that's report
 * content, meant to be read once, not re-sent to the model on every
 * single message. Every chat turn only gets the couple of patterns most
 * worth knowing in the moment, each trimmed to one line, so a counselor
 * can notice a repeating theme without every request carrying the
 * weight of a full clinical report.
 */
function kounselia_live_pattern_clause( $user_id ) {
    $reflection_json = get_user_meta( $user_id, 'kounselia_latest_reflection', true );
    if ( empty( $reflection_json ) ) {
        return '';
    }

    $reflections = json_decode( $reflection_json, true );
    if ( ! is_array( $reflections ) || empty( $reflections['patterns'] ) || ! is_array( $reflections['patterns'] ) ) {
        return '';
    }

    $patterns = array_slice( array_filter( (array) $reflections['patterns'] ), 0, 2 );
    if ( empty( $patterns ) ) {
        return '';
    }

    $patterns = array_map( function( $p ) {
        return wp_trim_words( (string) $p, 30, '…' );
    }, $patterns );

    $clause  = "\n\nRECURRING PATTERN (from this person's longer history, not just this chat): \n- " . implode( "\n- ", $patterns );
    $clause .= "\nIf it's naturally relevant to what they're saying right now, you can notice it out loud, casually and warmly, like a friend noticing a habit — not like a diagnosis. Don't force it in if it doesn't fit this conversation.";

    return $clause;
}

/**
 * AJAX: receive the raw paste, extract a structured JSON summary via Gemini,
 * store only the schema-validated summary in user meta. 
 */
function kounselia_ajax_import_memory() {
    kounselia_verify_nonce();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.' ), 401 );
    }

    if ( kounselia_rate_limited( 'import_memory', 5, 600 ) ) {
        wp_send_json_error( array( 'message' => 'Please wait a few minutes before importing again.' ), 429 );
    }

    $raw = isset( $_POST['memory_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['memory_text'] ) ) : '';

    if ( mb_strlen( $raw ) < 50 ) {
        wp_send_json_error( array( 'message' => 'That looks too short to be a memory export. Paste the full response from the other AI.' ), 400 );
    }

    // Hard cap: we don't need more than ~8k chars to extract a useful summary
    $raw = mb_substr( $raw, 0, 8000 );

    $extraction_prompt = "You are a clinical data extraction assistant. You will receive a block of text that a person has copied from another AI assistant — it contains what that AI remembers or knows about this person.

Your job is to extract this information into a STRICT JSON format representing the user's psychological profile. 

Output a valid JSON object using EXACTLY these keys. If no data exists for a key, return an empty string \"\" or empty array [].
- identity: Core self-concept, age, demographics (string)
- life_timeline: Array of objects with 'year' (string), 'event' (string), and 'impact' (string)
- emotional_map: Object mapping entity names (e.g. 'Mother', 'Career') to an object containing 'emotion' (string), 'intensity' (High, Medium, or Low), and 'context' (string).
- goals: Array of strings
- relationships: Object mapping a person/group to context
- important_people: Array of names
- career: Current job, trajectory (string)
- health: Physical/mental health themes (string)
- values: Array of strings
- triggers: Array of strings
- traumas: Array of strings
- current_challenges: Array of strings
- wins: Array of strings
- preferences: Object mapping key to value
- communication_style: string
- personality: string
- faith: string
- habits: Array of strings
- temporary_context: Recent mood or fleeting issues (string)

Text to extract from:
---
{$raw}
---";

    $contents = array(
        array( 'role' => 'user', 'parts' => array( array( 'text' => $extraction_prompt ) ) ),
    );

    $error   = '';
    
    // We utilize 3.6 Flash here because parsing a large pasted block of memory text is ideal for its massive context window
    $summary = kounselia_call_gemini(
        'You extract structured personal context from AI memory exports into JSON. You are precise, concise, and clinically neutral. Escape all quotes inside strings.',
        $contents,
        'gemini-3.6-flash', 
        0.1, 
        4000, 
        $error,
        'application/json' 
    );

    if ( null === $summary ) {
        wp_send_json_error( array(
            'message' => 'Could not process that text right now. Please try again in a moment.',
            'debug'   => WP_DEBUG ? $error : null,
        ), 502 );
    }

    // Robust JSON extraction: Strip out any conversational fluff or markdown
    $summary = trim( $summary );
    $start   = strpos( $summary, '{' );
    $end     = strrpos( $summary, '}' );
    if ( $start !== false && $end !== false ) {
        $summary = substr( $summary, $start, $end - $start + 1 );
    }

    $decoded_json = json_decode( $summary, true );

    if ( ! is_array( $decoded_json ) || empty( $decoded_json ) ) {
        $json_err = json_last_error_msg();
        wp_send_json_error( array(
            'message' => "We couldn't parse the AI's response. Error: " . $json_err,
            'raw'     => substr( $summary, 0, 400 )
        ), 400 );
    }

    kounselia_memory_save_profile( get_current_user_id(), $decoded_json );
    update_user_meta( get_current_user_id(), 'kounselia_memory_imported_at', current_time( 'mysql' ) );

    wp_send_json_success( array(
        'summary'     => $decoded_json, 
        'imported_at' => current_time( 'mysql' ),
    ) );
}
add_action( 'wp_ajax_kounselia_import_memory', 'kounselia_ajax_import_memory' );

/**
 * AJAX: delete the stored memory summary. Clean wipe of both meta keys.
 */
function kounselia_ajax_delete_memory() {
    kounselia_verify_nonce();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in first.' ), 401 );
    }

    $user_id = get_current_user_id();
    kounselia_memory_delete_profile( $user_id );
    delete_user_meta( $user_id, 'kounselia_imported_memory' ); // Clean up legacy key just in case
    delete_user_meta( $user_id, 'kounselia_memory_imported_at' );
    delete_user_meta( $user_id, 'kounselia_latest_reflection' );
    delete_user_meta( $user_id, 'kounselia_reflection_date' );

    wp_send_json_success();
}
add_action( 'wp_ajax_kounselia_delete_memory', 'kounselia_ajax_delete_memory' );

/**
 * Allows the user to manually edit their extracted JSON profile.
 */
function kounselia_ajax_edit_memory() {
    kounselia_verify_nonce();
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ), 401 );
    }

    $user_id = get_current_user_id();
    $memory  = kounselia_memory_load_profile( $user_id );

    // Update String Fields
    if ( isset( $_POST['identity'] ) ) $memory['identity'] = sanitize_textarea_field( wp_unslash( $_POST['identity'] ) );
    if ( isset( $_POST['career'] ) ) $memory['career'] = sanitize_textarea_field( wp_unslash( $_POST['career'] ) );

    // Update Array Fields (Comma-separated from the frontend)
    $array_fields = array( 'goals', 'values', 'habits', 'triggers' );
    foreach ( $array_fields as $field ) {
        if ( isset( $_POST[$field] ) ) {
            $raw = wp_unslash( $_POST[$field] );
            $arr = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
            $memory[$field] = array_values( $arr );
        }
    }

    kounselia_memory_save_profile( $user_id, $memory );
    wp_send_json_success( array( 'memory' => $memory ) );
}
add_action( 'wp_ajax_kounselia_edit_memory', 'kounselia_ajax_edit_memory' );

/* -------------------------------------------------------------------------
 * STREAMING_CHUNK:Integrating the background synthesis mechanism...
 * 18B. STRUCTURED MEMORY ENGINE (The Delta Synthesizer)
 * This runs silently in the background every 5 messages to merge new 
 * conversation context into the JSON profile without paying for the
 * whole history context window.
 * ---------------------------------------------------------------------- */

function kounselia_ajax_synthesize_memory() {
    kounselia_verify_nonce();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ), 401 );
    }
    
    // Slight rate limit to prevent aggressive background looping bugs
    if ( kounselia_rate_limited( 'synthesize_memory', 10, 60 ) ) {
        wp_send_json_error( array( 'message' => 'Throttled' ), 429 );
    }

    $session_id = isset( $_POST['session_id'] ) ? absint( $_POST['session_id'] ) : 0;
    if ( ! $session_id ) {
        wp_send_json_error( array( 'message' => 'Missing session' ), 400 );
    }

    $user_id = get_current_user_id();

    // 1. Fetch current profile (from the normalized tables; empty schema if they never did an import)
    $current_profile_json = wp_json_encode( kounselia_memory_load_profile( $user_id ) );

    // 2. Fetch the Delta (last 12 messages of this specific session to capture the back-and-forth).
    $delta_history = kounselia_build_gemini_history( $session_id, 12 );
    if ( empty( $delta_history ) ) {
        wp_send_json_error( array( 'message' => 'No history to synthesize' ), 400 );
    }

    // Format the delta into a readable transcript for the prompt
    $transcript = "";
    foreach ( $delta_history as $turn ) {
        $speaker = $turn['role'] === 'model' ? 'Counselor' : 'User';
        $text = $turn['parts'][0]['text'];
        $transcript .= "{$speaker}: {$text}\n\n";
    }

    // 3. Prompt Gemini to merge
    $today_date = current_time( 'Y-m-d' );

    $synthesis_prompt = "You are a clinical data extraction assistant acting as an autonomous MEMORY MANAGER.

Below is the user's CURRENT JSON profile, followed by a TRANSCRIPT of their most recent chat messages (the delta).
Your job is to read the transcript and intelligently MERGE new context into the JSON profile.

Today's date is {$today_date}.

MEMORY MANAGER RULES:
1. PERMANENT VS TEMPORARY: If the user mentions a fleeting feeling ('I had a bad day today'), put it in `temporary_context`. If `temporary_context` contains old, resolved issues, DELETE THEM (Garbage Collection). Only put long-term facts in the main arrays.
2. EMOTIONAL MEMORY: Track emotions tied to entities (people, places, jobs) in the `emotional_map`. If their feelings change, update the emotion and intensity (High, Medium, Low).
3. LIFE TIMELINE: If they mention a major past event, extract it into the `life_timeline` chronologically.
4. UPDATE EXISTING: If a fact is new, add it. If it contradicts old data, update the old data.
5. PRESERVE EVERYTHING ELSE: You must return the COMPLETE profile, not just what changed. Any field the transcript does not touch must be copied over unchanged from the CURRENT JSON exactly as it was. Never drop, shorten, or blank out a field just because this transcript didn't mention it again.
6. Do NOT add conversational fluff. Maintain the exact same JSON schema keys.
7. UPCOMING EVENTS: If the user mentions a specific event tied to a real calendar date — something happening today, already happened very recently, or coming up (an interview, a presentation, a doctor's appointment, a hard conversation they're planning, a court date, a wedding) — add it to a top-level `upcoming_events` array (this is IN ADDITION to the normal profile keys, not a replacement for `life_timeline` or `current_challenges`). Each item: `{\"event\": \"short description in their words, e.g. 'your job interview'\", \"date\": \"YYYY-MM-DD\"}`. Resolve relative phrases ('tomorrow', 'next Tuesday', 'in two weeks') into an actual date using today's date above. Only include events with a genuine, resolvable date — never guess a date for something vague. If none are mentioned in this transcript, return an empty array. Do not re-list events from earlier syntheses; only what's newly mentioned in this transcript delta.

CURRENT JSON:
---
{$current_profile_json}
---

TRANSCRIPT DELTA:
---
{$transcript}
---";

    $contents = array(
        array( 'role' => 'user', 'parts' => array( array( 'text' => $synthesis_prompt ) ) ),
    );

    $error   = '';
    
    // Using 2.5 Flash Lite here intentionally: This is a high-frequency, background housekeeping task 
    // that the user never reads. It merges data reliably and saves massive compute costs.
    $updated_json = kounselia_call_gemini(
        'You are a precise JSON merging tool. You update clinical profiles based on conversational deltas. You act as a garbage collector for old context.',
        $contents,
        'gemini-2.5-flash-lite',
        0.1, 
        2500, 
        $error,
        'application/json' // Enforce JSON
    );

    if ( null === $updated_json ) {
        wp_send_json_error( array( 'message' => 'Synthesis failed' ), 502 );
    }

    // Robust JSON extraction: Strip out any conversational fluff or markdown
    $updated_json = trim( $updated_json );
    $start        = strpos( $updated_json, '{' );
    $end          = strrpos( $updated_json, '}' );
    if ( $start !== false && $end !== false ) {
        $updated_json = substr( $updated_json, $start, $end - $start + 1 );
    }
    
    $decoded_json = json_decode( $updated_json, true );

    // Safety check: ensure the LLM returned a valid structure, allowing full schema replacements safely
    if ( is_array( $decoded_json ) ) {
        if ( ! empty( $decoded_json['upcoming_events'] ) && function_exists( 'kounselia_record_upcoming_events' ) ) {
            kounselia_record_upcoming_events( $user_id, $decoded_json['upcoming_events'] );
        }
        unset( $decoded_json['upcoming_events'] ); // Tracked separately — not part of the profile schema itself.

        kounselia_memory_save_profile( $user_id, $decoded_json );

        if ( function_exists( 'kounselia_maybe_schedule_reflection_refresh' ) ) {
            kounselia_maybe_schedule_reflection_refresh( $user_id );
        }

        wp_send_json_success( array( 'synthesized' => true ) );
    }

    wp_send_json_error( array( 'message' => 'Invalid JSON returned' ), 500 );
}
add_action( 'wp_ajax_kounselia_synthesize_memory', 'kounselia_ajax_synthesize_memory' );

/* -------------------------------------------------------------------------
 * STREAMING_CHUNK:Finalizing the reflection and intake engines...
 * 18C. REFLECTION ENGINE (Deep Longitudinal Insights)
 * Analyzes the memory profile to generate patterns, growth, and blind spots.
 * ---------------------------------------------------------------------- */

function kounselia_ajax_generate_reflection() {
    kounselia_verify_nonce();

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ), 401 );
    }

    $result = kounselia_generate_reflection( get_current_user_id() );

    if ( ! $result['success'] ) {
        wp_send_json_error( array( 'message' => $result['message'] ), $result['status'] );
    }

    wp_send_json_success( array( 'reflection' => $result['reflection'], 'date' => $result['date'] ) );
}
add_action( 'wp_ajax_kounselia_generate_reflection', 'kounselia_ajax_generate_reflection' );

/**
 * The actual reflection generation, independent of being an AJAX request,
 * so a background refresh (see kounselia_run_reflection_refresh_cron
 * below) can call the exact same logic the manual "Generate Reflection"
 * button uses, instead of duplicating it.
 */
function kounselia_generate_reflection( $user_id ) {
    $memory_json = get_user_meta( $user_id, 'kounselia_core_memory', true );

    if ( empty( $memory_json ) || strlen( $memory_json ) < 50 ) {
        return array( 'success' => false, 'status' => 400, 'message' => 'Not enough memory data to generate a reflection yet. Have a few more conversations first!' );
    }

    $prompt = "You are a Senior Supervising Psychologist. Review the following structured memory profile of a user.
Your task is to analyze this data and generate a deep 'Milestone Reflection Report' for the user to read. 

Look for hidden connections between their timeline, their emotional map, and their goals. 
You must output strict, valid JSON matching this exact schema:
{
  \"patterns\": [\"Array of strings: 2-3 recurring themes or loops you notice in their behavior or emotions\"],
  \"growth\": [\"Array of strings: 2-3 areas where they have shown resilience, healing, or positive evolution\"],
  \"recurring_fears\": [\"Array of strings: 2-3 core anxieties driving their current challenges\"],
  \"blind_spots\": [\"Array of strings: 1-2 things they might be avoiding or failing to connect (say this gently)\"],
  \"achievements\": [\"Array of strings: Things they should be proud of surviving or accomplishing\"],
  \"recommendations\": [\"Array of strings: 2-3 therapeutic or practical next steps to focus on\"]
}

Rules:
- Write directly to the user (e.g., 'You have shown a pattern of...').
- Be incredibly empathetic, profound, and clinical.
- Return ONLY the JSON object. No markdown blocks.

USER PROFILE:
---
{$memory_json}
---";

    $contents = array(
        array( 'role' => 'user', 'parts' => array( array( 'text' => $prompt ) ) )
    );

    $error = '';
    
    // Crucial Switch to Pro: We employ gemini-2.5-pro here because extracting
    // deep therapeutic blind spots from a long-term JSON memory array demands
    // intense reasoning capabilities that the Flash models struggle with. 
    $reflection_json = kounselia_call_gemini(
        'You are an elite psychological analyst. You find deep patterns in human data.',
        $contents,
        'gemini-2.5-pro', 
        0.4, 
        2000, 
        $error,
        'application/json'
    );

    if ( null === $reflection_json ) {
        return array( 'success' => false, 'status' => 502, 'message' => 'Analysis failed. Try again later.' );
    }

    // Robust JSON extraction: Strip out any conversational fluff or markdown
    $reflection_json = trim( $reflection_json );
    $start           = strpos( $reflection_json, '{' );
    $end             = strrpos( $reflection_json, '}' );
    if ( $start !== false && $end !== false ) {
        $reflection_json = substr( $reflection_json, $start, $end - $start + 1 );
    }
    
    $decoded = json_decode( $reflection_json, true );

    if ( is_array( $decoded ) ) {
        // Fill missing keys with defaults so the dashboard UI doesn't crash on .map()
        $defaults = array(
            'patterns'        => array('Need more conversations to establish patterns.'),
            'growth'          => array('Keep talking to your counselors to chart your growth.'),
            'recurring_fears' => array('None identified yet.'),
            'blind_spots'     => array('None identified yet.'),
            'achievements'    => array('None identified yet.'),
            'recommendations' => array('Continue your sessions so we can learn more about you.')
        );

        foreach ( $defaults as $key => $default_val ) {
            if ( ! isset( $decoded[$key] ) || ! is_array( $decoded[$key] ) || empty( $decoded[$key] ) ) {
                $decoded[$key] = $default_val;
            }
        }

        update_user_meta( $user_id, 'kounselia_latest_reflection', wp_json_encode( $decoded ) );
        update_user_meta( $user_id, 'kounselia_reflection_date', current_time( 'mysql' ) );
        return array( 'success' => true, 'reflection' => $decoded, 'date' => current_time( 'mysql' ) );
    }

    // Output the exact parsing error for debugging if it still fails
    return array(
        'success' => false,
        'status'  => 500,
        'message' => 'Failed to parse insights. Error: ' . json_last_error_msg(),
    );
}

/* -------------------------------------------------------------------------
 * 18C-2. REFLECTION ENGINE — automatic background refresh
 *
 * The reflection above used to only ever get generated when a user
 * clicked "Generate Reflection" on the dashboard — most people never
 * would, so kounselia_latest_reflection stayed empty and the pattern
 * context injected into live chats (kounselia_imported_memory_clause)
 * never had anything to say. This keeps it current automatically,
 * without adding a slow Pro-model call to the fast, high-frequency
 * synthesis path: it schedules a one-off WP-Cron job instead, so it
 * runs decoupled from the user's request.
 * ---------------------------------------------------------------------- */

define( 'KOUNSELIA_REFLECTION_REFRESH_INTERVAL', 7 * DAY_IN_SECONDS );

/**
 * Called after a memory synthesis. Schedules a background reflection
 * refresh if this user's is missing or stale, and nothing is already
 * queued for them.
 */
function kounselia_maybe_schedule_reflection_refresh( $user_id ) {
    $last_date = get_user_meta( $user_id, 'kounselia_reflection_date', true );
    $is_stale  = empty( $last_date ) || ( strtotime( $last_date ) < time() - KOUNSELIA_REFLECTION_REFRESH_INTERVAL );

    if ( ! $is_stale ) {
        return;
    }

    if ( wp_next_scheduled( 'kounselia_run_reflection_refresh', array( $user_id ) ) ) {
        return; // Already queued, don't stack duplicate jobs.
    }

    wp_schedule_single_event( time() + 30, 'kounselia_run_reflection_refresh', array( $user_id ) );
}

function kounselia_run_reflection_refresh_cron( $user_id ) {
    kounselia_generate_reflection( (int) $user_id ); // Fire-and-forget; errors just mean it stays stale until the next attempt.
}
add_action( 'kounselia_run_reflection_refresh', 'kounselia_run_reflection_refresh_cron' );

/* -------------------------------------------------------------------------
 * 18-A2. INTAKE ENGINE (Initial Profile Setup)
 * ---------------------------------------------------------------------- */

function kounselia_ajax_submit_intake() {
    kounselia_verify_nonce();
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ), 401 );
    }

    $user_id = get_current_user_id();
    $q1 = isset( $_POST['q1'] ) ? sanitize_textarea_field( wp_unslash( $_POST['q1'] ) ) : '';
    $q2 = isset( $_POST['q2'] ) ? sanitize_textarea_field( wp_unslash( $_POST['q2'] ) ) : '';

    // Convert their answers into the starter JSON profile instantly
    $initial_memory = array(
        'identity' => 'New user. Just joined the platform.',
        'life_timeline' => array(),
        'emotional_map' => new stdClass(),
        'goals' => array( $q2 ),
        'relationships' => new stdClass(),
        'important_people' => array(),
        'career' => '',
        'health' => '',
        'values' => array(),
        'triggers' => array(),
        'traumas' => array(),
        'current_challenges' => array( $q1 ),
        'wins' => array(),
        'preferences' => new stdClass(),
        'communication_style' => '',
        'personality' => '',
        'faith' => '',
        'habits' => array(),
        'temporary_context' => "User just signed up. When asked what brought them here, they said: '{$q1}'. When asked about their goals, they said: '{$q2}'."
    );

    kounselia_memory_save_profile( $user_id, $initial_memory );
    delete_user_meta( $user_id, 'kounselia_is_new_user' );

    wp_send_json_success();
}
add_action( 'wp_ajax_kounselia_submit_intake', 'kounselia_ajax_submit_intake' );
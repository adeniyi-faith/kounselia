<?php
/**
 * Kounselia Core — languages.
 *
 * Kounselia is a global platform, so what a member reads and what the AI
 * writes follows their language. A member's choice is saved on their
 * account ('kounselia_language'); until they choose, the language of their
 * phone or browser is used.
 *
 *   - Words on screen live once in packages/core/src/locales/<code>.json.
 *     The website and the app read the same files, so each sentence is
 *     translated once. This file reads them for text the server writes
 *     itself (reminders, plan questions) with kounselia_t().
 *   - The AI counselors, plans and reviews write in the member's language
 *     (kounselia_language_clause()).
 *   - A missing translation falls back to English, never a blank.
 *
 * Adding a language: add it to kounselia_languages() here and to LANGUAGES
 * in packages/core/src/i18n.ts, then translate a copy of en.json.
 *
 * Part of the kounselia-core mu-plugin. Loaded by ../../kounselia-core.php,
 * never included directly.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Supported languages. 'name' is how it is written in that language (for
 * the picker); 'english' is what the AI is told to write in.
 */
function kounselia_languages() {
    return array(
        'en' => array( 'name' => 'English',   'english' => 'English',    'rtl' => false ),
        'fr' => array( 'name' => 'Français',  'english' => 'French',     'rtl' => false ),
        'es' => array( 'name' => 'Español',   'english' => 'Spanish',    'rtl' => false ),
        'pt' => array( 'name' => 'Português', 'english' => 'Portuguese', 'rtl' => false ),
        'ar' => array( 'name' => 'العربية',    'english' => 'Arabic',     'rtl' => true ),
    );
}

/**
 * "fr-CA", "pt_BR" or "FR" to a supported code; anything else is 'en'.
 */
function kounselia_language_normalize( $raw ) {
    $base = strtolower( preg_split( '/[-_]/', trim( (string) $raw ) )[0] );
    return isset( kounselia_languages()[ $base ] ) ? $base : 'en';
}

function kounselia_language_is_rtl( $code ) {
    $all = kounselia_languages();
    return isset( $all[ $code ] ) && $all[ $code ]['rtl'];
}

/**
 * The translation file for one language, as key => text (cached).
 */
function kounselia_i18n_dictionary( $lang ) {
    static $cache = array();
    $lang = kounselia_language_normalize( $lang );
    if ( ! isset( $cache[ $lang ] ) ) {
        $file = dirname( KOUNSELIA_CORE_DIR, 3 ) . '/packages/core/src/locales/' . $lang . '.json';
        $data = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
        $cache[ $lang ] = is_array( $data ) ? $data : array();
    }
    return $cache[ $lang ];
}

/**
 * Translated text for a key, with {name} placeholders filled from $vars.
 * Falls back to English, then to the key itself.
 */
function kounselia_t( $key, $vars = array(), $lang = null ) {
    $lang = null === $lang ? kounselia_current_language() : kounselia_language_normalize( $lang );
    $dict = kounselia_i18n_dictionary( $lang );
    $text = isset( $dict[ $key ] ) ? $dict[ $key ] : ( kounselia_i18n_dictionary( 'en' )[ $key ] ?? $key );
    foreach ( (array) $vars as $name => $value ) {
        $text = str_replace( '{' . $name . '}', (string) $value, $text );
    }
    return $text;
}

/**
 * Only the keys starting with $prefix, for printing into a page's script.
 */
function kounselia_i18n_subset( $lang, $prefix ) {
    $dict = array_merge( kounselia_i18n_dictionary( 'en' ), kounselia_i18n_dictionary( $lang ) );
    return array_filter( $dict, function ( $key ) use ( $prefix ) {
        return 0 === strpos( $key, $prefix );
    }, ARRAY_FILTER_USE_KEY );
}

/**
 * The language a member chose, or '' if they have not.
 */
function kounselia_user_language_preference( $user_id ) {
    $saved = $user_id ? get_user_meta( $user_id, 'kounselia_language', true ) : '';
    return ( $saved && isset( kounselia_languages()[ $saved ] ) ) ? $saved : '';
}

/**
 * The language to use for this request: the member's choice, else what
 * their app or browser reported, else the browser's Accept-Language
 * header, else English.
 */
function kounselia_current_language( $user_id = null ) {
    $user_id = null === $user_id ? get_current_user_id() : $user_id;
    $saved   = kounselia_user_language_preference( $user_id );
    if ( $saved ) {
        return $saved;
    }
    if ( ! empty( $_POST['language'] ) ) {
        return kounselia_language_normalize( sanitize_text_field( wp_unslash( $_POST['language'] ) ) );
    }
    if ( ! empty( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
        foreach ( explode( ',', (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) as $part ) {
            $code = strtolower( preg_split( '/[-_;]/', trim( $part ) )[0] );
            if ( isset( kounselia_languages()[ $code ] ) ) {
                return $code;
            }
        }
    }
    return 'en';
}

/**
 * Added to the AI's instructions so it answers in the member's language.
 * Everyone gets the "answer in the language they write in" rule (guests
 * included); a member who chose a language also gets that one named.
 */
function kounselia_language_clause( $user_id = 0 ) {
    $clause = "\n\nLANGUAGE: Always reply in the language the person is writing in, and keep to it unless they change it. Match their way of speaking, not a textbook version of the language.";
    $pref   = kounselia_user_language_preference( $user_id );
    if ( $pref && 'en' !== $pref ) {
        $english = kounselia_languages()[ $pref ]['english'];
        $clause .= " This person has chosen {$english} as their language, so when you start the conversation, or when their message is too short to tell, use {$english}.";
    }
    return $clause;
}

/**
 * What the AI is told when it writes something long for a member (a plan
 * or a review): which language to write the visible text in.
 */
function kounselia_language_instruction( $lang ) {
    $english = kounselia_languages()[ kounselia_language_normalize( $lang ) ]['english'];
    return "Write every piece of visible text (titles, tasks, summaries, notes) in {$english}. Keep the JSON field names exactly as shown, in English.";
}

/* -------------------------------------------------------------------------
 * AJAX: choosing a language (website and app)
 * ---------------------------------------------------------------------- */

function kounselia_ajax_set_language() {
    kounselia_verify_nonce();
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => 'Please sign in again.', 'signed_out' => true ), 401 );
    }
    $raw = isset( $_POST['language'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['language'] ) ) ) : '';
    if ( ! isset( kounselia_languages()[ $raw ] ) ) {
        wp_send_json_error( array( 'message' => 'That language is not available yet.' ), 400 );
    }
    update_user_meta( get_current_user_id(), 'kounselia_language', $raw );
    wp_send_json_success( array( 'language' => $raw, 'rtl' => kounselia_language_is_rtl( $raw ), 'message' => kounselia_t( 'lang.saved', array(), $raw ) ) );
}
add_action( 'wp_ajax_kounselia_set_language', 'kounselia_ajax_set_language' );
add_action( 'wp_ajax_nopriv_kounselia_set_language', 'kounselia_ajax_set_language' );

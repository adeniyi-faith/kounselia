<?php
/**
 * STREAMING_CHUNK:Injecting dynamic DB configurations into the frontend chat engine...
 * Kounselia conversation engine — shared by index.php (guest trial
 * funnel) and talk.php (signed-in members' dedicated chat page).
 *
 * UPGRADED UI VERSION: Integrates advanced conversational UX patterns
 * (Semantic Tinting, Decoupled Feedback, Dynamic Composer, Trust Indicators).
 * Includes the Delta Trigger for the Structured Memory Engine.
 *
 * This file used to be one ~1,400-line file with a giant inline
 * <style> block and a giant inline <script> block. It's now a loader:
 * the CSS, the JS, and the HTML markup each live in their own file
 * under kounselia-chat-engine/, and this file just stitches them back
 * together — SAME inline output as before (no separate asset URLs,
 * no enqueue, nothing for a webserver to 404 on), so no other file
 * needs to change.
 *
 * Keep including this file exactly like before:
 *   <?php require __DIR__ . '/inc/kounselia-chat-engine.php'; ?>
 *
 * It still expects the including page to have already set:
 *   $kounselia_is_logged_in   bool
 *   $kounselia_ajax_url       string, e.g. admin_url( 'admin-ajax.php' )
 *   $kounselia_nonce          string, e.g. wp_create_nonce( 'kounselia_auth' )
 *
 * There are now two chat UIs sharing this loader:
 *
 *   - The original hand-written HTML/CSS/JS under assets/ (default).
 *     index.php's guest trial funnel stays on this one: it also leans on
 *     this script's global openModal()/logout()/updateUserUI() functions
 *     for the marketing nav's sign-in UI, which the React rebuild below
 *     doesn't provide.
 *
 *   - A React + TypeScript rebuild under assets/react/ (source lives in
 *     the separate chat-app/ project; `npm run build` there produces
 *     these two files). It's a self-contained conversation screen only,
 *     no site nav wiring. Set $kounselia_use_react_chat = true before
 *     requiring this file to opt a page into it, as talk.php does.
 */

$kounselia_chat_engine_dir = __DIR__ . '/kounselia-chat-engine';
$kounselia_use_react_chat  = ! empty( $kounselia_use_react_chat );
$kounselia_assets_dir      = $kounselia_chat_engine_dir . '/assets' . ( $kounselia_use_react_chat ? '/react' : '' );
?>
<style>
<?php readfile( $kounselia_assets_dir . '/kounselia-chat.css' ); ?>
</style>

<?php if ( $kounselia_use_react_chat ) : ?>
<div id="kounselia-chat-root"></div>
<?php else : ?>
<?php require $kounselia_chat_engine_dir . '/templates/chat-markup.html'; ?>
<?php endif; ?>

<script>
// Handed off to the chat JS bundle below, which reads window.KOUNSELIA
// instead of having these values echoed directly into it.
window.KOUNSELIA = {
  ajaxUrl: <?php echo wp_json_encode( $kounselia_ajax_url ); ?>,
  nonce: <?php echo wp_json_encode( $kounselia_nonce ); ?>,
  loggedIn: <?php echo $kounselia_is_logged_in ? 'true' : 'false'; ?>
};

// Expose the dynamically managed Database UI values into the global C object.
// This allows the Chat Javascript UI to instantly respect changes made in the
// Admin Counselor Studio without needing any Javascript rewrites.
//
// kounselia_get_all_ui() only carries display fields (name/spec/icon/desc),
// never voice_enabled -- that flag lives in the separate
// kounselia_counselor_prompts DB table alongside the system prompt. Merge
// just that one flag in here (never the system prompt itself, which must
// stay server-side) so the "Start voice conversation" call icon actually
// reflects what's toggled in Counselor Studio.
<?php
$kounselia_ui_for_js = function_exists( 'kounselia_get_all_ui' ) ? kounselia_get_all_ui() : array();
if ( function_exists( 'kounselia_get_counselor_prompt' ) ) {
    foreach ( $kounselia_ui_for_js as $kounselia_slug => &$kounselia_ui_row ) {
        $kounselia_prompt_row = kounselia_get_counselor_prompt( $kounselia_slug );
        $kounselia_ui_row['voice_enabled'] = $kounselia_prompt_row ? (bool) $kounselia_prompt_row['voice_enabled'] : false;
    }
    unset( $kounselia_ui_row );
}
?>
window.C = <?php echo wp_json_encode( $kounselia_ui_for_js ); ?>;
</script>
<script>
<?php readfile( $kounselia_assets_dir . '/kounselia-chat.js' ); ?>
</script>

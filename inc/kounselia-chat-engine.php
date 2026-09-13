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
 */

$kounselia_chat_engine_dir = __DIR__ . '/kounselia-chat-engine';
?>
<style>
<?php readfile( $kounselia_chat_engine_dir . '/assets/kounselia-chat.css' ); ?>
</style>

<?php require $kounselia_chat_engine_dir . '/templates/chat-markup.html'; ?>

<script>
// Handed off to kounselia-chat-engine/assets/kounselia-chat.js, which reads
// window.KOUNSELIA instead of having these values echoed directly into it.
window.KOUNSELIA = {
  ajaxUrl: <?php echo wp_json_encode( $kounselia_ajax_url ); ?>,
  nonce: <?php echo wp_json_encode( $kounselia_nonce ); ?>,
  loggedIn: <?php echo $kounselia_is_logged_in ? 'true' : 'false'; ?>
};

// Expose the dynamically managed Database UI values into the global C object.
// This allows the Chat Javascript UI to instantly respect changes made in the 
// Admin Counselor Studio without needing any Javascript rewrites.
window.C = <?php echo wp_json_encode( function_exists('kounselia_get_all_ui') ? kounselia_get_all_ui() : array() ); ?>;
</script>
<script>
<?php readfile( $kounselia_chat_engine_dir . '/assets/kounselia-chat.js' ); ?>
</script>
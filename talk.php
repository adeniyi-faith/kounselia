<?php
/**
 * Kounselia — dedicated conversation page for signed-in members.
 *
 * Same boot pattern as index.php and dashboard.php: wp-load.php only,
 * no theme. This page is gated, guests are bounced to the homepage to
 * sign in first (there's no guest trial experience here, that lives on
 * index.php).
 *
 * This page renders the SAME chat engine as index.php (counselor data,
 * message screen, voice call, TTS playback) via the shared partial in
 * inc/kounselia-chat-engine.php. It does not duplicate that code, it
 * requires it. If you need to change how a conversation behaves, edit
 * inc/kounselia-chat-engine.php, not this file.
 *
 * Unlike index.php, this page has no marketing chrome and no guest
 * trial flow, it's just the conversation. A counselor slug is expected
 * in the URL hash (e.g. /talk.php#serena), normally arrived at from a
 * dashboard link. With no hash, there's nothing to show, so we send
 * the member to the dashboard's counselor picker instead.
 */
define( 'WP_USE_THEMES', false );
define( 'COOKIEPATH', '/' );
define( 'SITECOOKIEPATH', '/' );

$wp_load_path = __DIR__ . '/portal/wp-load.php';
if ( ! file_exists( $wp_load_path ) ) {
    die( '<div style="font-family:\'Outfit\',sans-serif;padding:60px 20px;text-align:center;background-color:#F8F6F2;min-height:100vh;box-sizing:border-box;">
            <h2 style="color:#1E3A5F;font-family:\'Cormorant Garamond\',serif;font-size:32px;font-weight:500;">Configuration error</h2>
            <p style="color:#5B574D;font-size:16px;line-height:1.6;max-width:500px;margin:10px auto;">Cannot locate the WordPress core engine. The system is looking for it at exactly this path:</p>
            <code style="background:#E8EEF6;padding:12px 16px;border-radius:8px;display:inline-block;margin-top:15px;color:#8B3A52;font-size:14px;word-break:break-all;border:1px solid #C8D8EC;">' . htmlspecialchars( $wp_load_path ) . '</code>
            <p style="color:#5B574D;font-size:15px;margin-top:25px;">Make sure a <strong>/portal</strong> folder sits next to this file, with WordPress\'s core files (wp-admin, wp-includes, wp-load.php) directly inside it, not nested in an extra subfolder.</p>
         </div>' );
}
require_once $wp_load_path;

// This page is for signed-in members only, no guest trial here.
if ( ! is_user_logged_in() ) {
    wp_safe_redirect( '/index.php' );
    exit;
}

$kounselia_current_user = wp_get_current_user();
$kounselia_is_logged_in = true;
$kounselia_display_name = $kounselia_current_user->display_name ? $kounselia_current_user->display_name : $kounselia_current_user->user_login;
$kounselia_avatar_url   = function_exists( 'kounselia_get_avatar_url' )
    ? kounselia_get_avatar_url( $kounselia_current_user->ID, 'thumbnail' )
    : false;
$kounselia_ajax_url     = set_url_scheme( admin_url( 'admin-ajax.php' ), is_ssl() ? 'https' : 'http' );
$kounselia_nonce        = wp_create_nonce( 'kounselia_auth' );
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Kounselia — Conversation</title>
<meta name="robots" content="noindex, nofollow">

<!-- PRELOAD CRITICAL BRANDING -->
<link rel="preload" as="image" href="https://kounselia.com/img/Kounselia_Logo_IconMark_MidnightNavy.png" fetchpriority="high">

<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
<?php require __DIR__ . '/inc/kounselia-styles.php'; ?>
<style>
/* Animated, branded full-screen loading spinner overlay */
.talk-loading {
  position: fixed; inset: 0; display: flex; align-items: center; justify-content: center;
  background: var(--bg); z-index: 9999; transition: opacity 0.4s ease;
}
.brand-loader-wrap {
  display: flex; flex-direction: column; align-items: center; gap: 20px;
}
.brand-loader-img {
  max-height: 44px; width: auto; object-fit: contain;
  animation: pulseLogo 2.5s ease-in-out infinite;
}
.loading-dots {
  display: flex; gap: 8px;
}
.loading-dots div {
  width: 8px; height: 8px; border-radius: 50%; background: var(--gold);
  animation: dotBounce 1.4s infinite ease-in-out both;
}
.loading-dots div:nth-child(1) { animation-delay: -0.32s; }
.loading-dots div:nth-child(2) { animation-delay: -0.16s; }

.loading-text {
  font-size: 14px; color: var(--text2); font-weight: 400; letter-spacing: 0.3px;
  margin-top: 8px; animation: fadeIn 1s ease 0.5s both;
}

@keyframes dotBounce {
  0%, 80%, 100% { transform: scale(0); opacity: 0.5; }
  40% { transform: scale(1); opacity: 1; }
}
@keyframes pulseLogo {
  0%, 100% { transform: scale(0.98); opacity: 0.8; }
  50% { transform: scale(1.02); opacity: 1; }
}
@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

/* #nav-right is required by the shared chat engine (logout/updateUserUI
   write to it). On talk.php we don't render a visible nav bar, so we
   keep a zero-size hidden node purely to satisfy that contract and
   prevent null-reference JS errors if the user signs out mid-session. */
#nav-right{display:none}
</style>
<?php wp_head(); ?>
</head>
<body>

<div id="nav-right"></div>

<div class="talk-loading" id="talk-loading">
  <div class="brand-loader-wrap">
    <img src="https://kounselia.com/img/Kounselia_Logo_IconMark_MidnightNavy.png" alt="Kounselia" class="brand-loader-img" fetchpriority="high">
    <div class="loading-dots">
      <div></div><div></div><div></div>
    </div>
    <div class="loading-text" id="loading-text">Preparing your space...</div>
  </div>
</div>

<?php require __DIR__ . '/inc/kounselia-chat-engine.php'; ?>

<script>
document.addEventListener('DOMContentLoaded',async function(){
  const slug=(location.hash||'').replace('#','');
  if(slug&&C[slug]){

    // Personalize the loader if counselor is known
    const loaderText = document.getElementById('loading-text');
    if(loaderText && C[slug].name) {
      loaderText.textContent = 'Connecting you with ' + C[slug].name + '...';
    }

    // Arrived from a dashboard "Smart Check-in" card: swap the usual
    // generic greeting for the actual check-in question, so the
    // counselor opens with it directly instead of "where should we start?"
    const checkinId = new URLSearchParams(location.search).get('checkin');
    if(checkinId){
      try{
        const res = await fetch(KOUNSELIA.ajaxUrl,{
          method:'POST',
          headers:{'Content-Type':'application/x-www-form-urlencoded'},
          body:new URLSearchParams({action:'kounselia_get_checkin',nonce:KOUNSELIA.nonce,checkin_id:checkinId})
        }).then(r=>r.json());
        if(res.success && res.data && res.data.question){
          C[slug].greeting = res.data.question;
        }
      }catch(e){ /* fall back silently to the normal greeting */ }
    }

    // Start the chat interface rendering logic under the hood immediately
    startChat(slug);
    
    // Intentionally delay removing the spinner for 1.5 seconds.
    // This allows the beautiful branded animation to play out and creates a
    // psychological sense of "establishing a real connection" for the user.
    const loading = document.getElementById('talk-loading');
    if(loading) {
      setTimeout(() => {
        loading.style.opacity = '0';
        setTimeout(() => loading.remove(), 400); // 400ms for CSS fade
      }, 1500); 
    }

  } else {
    // No counselor specified, nothing to show here, send the member
    // to the dashboard's "Talk to someone" picker instead.
    window.location.href='/dashboard.php';
    return;
  }
});

// On talk.php, after logout the reload in the base engine would hit the
// auth gate and show a blank redirect. Override logout() here to send
// the user straight to the homepage instead.
function logout(){
  fetch(KOUNSELIA.ajaxUrl,{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams({action:'kounselia_logout',nonce:KOUNSELIA.nonce})
  }).finally(()=>{ window.location.href='/index.php'; });
}
</script>
<?php wp_footer(); ?>
</body>
</html>
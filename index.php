<?php
/**
 * Kounselia front-end shell.
 *
 * Boots WordPress (for native auth, $wpdb, hooks) without ever loading a
 * theme. wp-load.php only sets up WP core, it does not run the template
 * loader, that only happens via wp-blog-header.php. So nothing extra is
 * needed to keep this "headless", we just never call that file.
 */
define( 'WP_USE_THEMES', false );
// WP_SITEURL points at /portal, which would otherwise scope auth cookies
// to /portal only, invisible to this root-level script. Force cookies to
// the whole site so is_user_logged_in() and nonces stay consistent
// everywhere, not just inside /portal.
define( 'COOKIEPATH', '/' );
define( 'SITECOOKIEPATH', '/' );

// Check the core engine actually exists before requiring it. Without
// this, a missing/misplaced /portal folder produces a raw PHP fatal
// error (and on some cPanel configs, an ugly server-level error page
// that hides the real cause entirely). This shows exactly which path
// was checked instead, so a misplaced WordPress install is obvious at
// a glance rather than a guessing game.
$wp_load_path = __DIR__ . '/portal/wp-load.php';
if ( ! file_exists( $wp_load_path ) ) {
    die( '<div style="font-family:\'Outfit\',sans-serif;padding:60px 20px;text-align:center;background-color:#F8F6F2;min-height:100vh;box-sizing:border-box;">
            <h2 style="color:#1E3A5F;font-family:\'Cormorant Garamond\',serif;font-size:32px;font-weight:500;">Configuration error</h2>
            <p style="color:#5B574D;font-size:16px;line-height:1.6;max-width:500px;margin:10px auto;">Cannot locate the WordPress core engine. The system is looking for it at exactly this path:</p>
            <code style="background:#E8EEF6;padding:12px 16px;border-radius:8px;display:inline-block;margin-top:15px;color:#8B3A52;font-size:14px;word-break:break-all;border:1px solid #C8D8EC;">' . htmlspecialchars( $wp_load_path ) . '</code>
            <p style="color:#5B574D;font-size:15px;margin-top:25px;">Make sure a <strong>/portal</strong> folder sits next to this index.php file, with WordPress\'s core files (wp-admin, wp-includes, wp-load.php) directly inside it, not nested in an extra subfolder.</p>
         </div>' );
}
require_once $wp_load_path;

$kounselia_current_user   = wp_get_current_user();
$kounselia_is_logged_in   = is_user_logged_in();
$kounselia_display_name   = $kounselia_is_logged_in ? $kounselia_current_user->display_name : '';
$kounselia_avatar_url     = ( $kounselia_is_logged_in && function_exists( 'kounselia_get_avatar_url' ) )
    ? kounselia_get_avatar_url( $kounselia_current_user->ID, 'thumbnail' )
    : false;
// set_url_scheme forces this to match whatever protocol the page actually
// loaded under (http or https), even if WP's own siteurl/home options
// say something different. This prevents the exact CORS/428 mismatch
// bug that happens when a visitor lands on a plain-http URL before any
// redirect rule kicks in.
$kounselia_ajax_url       = set_url_scheme( admin_url( 'admin-ajax.php' ), is_ssl() ? 'https' : 'http' );
$kounselia_nonce          = wp_create_nonce( 'kounselia_auth' );
// Words on this page come from the language files (see kounselia_t()).
$kl  = kounselia_current_language();
$st  = function ( $key, $vars = array() ) use ( $kl ) {
    return esc_html( kounselia_t( $key, $vars, $kl ) );
};
// Same, but lets the sentence hold a few trusted HTML pieces: $tokens = array( 'name' => '<b>..</b>' ).
$sth = function ( $key, $tokens ) use ( $kl ) {
    $vars = array();
    foreach ( $tokens as $n => $h ) {
        $vars[ $n ] = '@@' . $n . '@@';
    }
    $txt = esc_html( kounselia_t( $key, $vars, $kl ) );
    foreach ( $tokens as $n => $h ) {
        $txt = str_replace( '@@' . $n . '@@', $h, $txt );
    }
    return $txt;
};
$kounselia_title = kounselia_t( 's.home.title', array(), $kl );
$kounselia_desc  = kounselia_t( 's.home.meta_desc', array(), $kl );
?>
<!DOCTYPE html>
<html <?php echo function_exists( 'kounselia_html_attrs' ) ? kounselia_html_attrs() : 'lang="en"'; ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title><?php echo esc_html( $kounselia_title ); ?></title>
<meta name="description" content="<?php echo esc_attr( $kounselia_desc ); ?>">

<!-- Favicon -->
<link rel="icon" type="image/png" href="https://kounselia.com/img/fv.png">
<link rel="apple-touch-icon" href="https://kounselia.com/img/fv.png">

<!-- Open Graph / Social Media -->
<meta property="og:type" content="website">
<meta property="og:url" content="https://kounselia.com/">
<meta property="og:title" content="<?php echo esc_attr( $kounselia_title ); ?>">
<meta property="og:description" content="<?php echo esc_attr( $kounselia_desc ); ?>">
<meta property="og:image" content="https://kounselia.com/img/Kounselia_Banner_02_16_9.png">

<!-- Twitter -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:url" content="https://kounselia.com/">
<meta name="twitter:title" content="<?php echo esc_attr( $kounselia_title ); ?>">
<meta name="twitter:description" content="<?php echo esc_attr( $kounselia_desc ); ?>">
<meta name="twitter:image" content="https://kounselia.com/img/Kounselia_Banner_02_16_9.png">

<!-- PRELOAD CRITICAL BRANDING -->
<link rel="preload" as="image" href="https://kounselia.com/img/Kounselia_Logo_IconMark_MidnightNavy.png" fetchpriority="high">

<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
<?php require __DIR__ . '/inc/kounselia-styles.php'; ?>
<?php require __DIR__ . '/inc/kounselia-content-styles.php'; ?>
<?php wp_head(); ?>
</head>
<body>

<!-- LANDING -->
<div class="screen active" id="landing">

  <nav class="land-nav">
    <div class="container" style="display: flex; align-items: center; justify-content: space-between;">
      <a href="/" class="logo-link">
        <img src="https://kounselia.com/img/Kounselia_Logo_IconMark_MidnightNavy.png" alt="Kounselia" class="site-logo" fetchpriority="high">
      </a>
      <div class="nav-right" id="nav-right">
        <?php if ( $kounselia_is_logged_in ) : ?>
          <div class="user-menu">
            <a href="/dashboard.php" style="display:flex;align-items:center;gap:8px;text-decoration:none;color:inherit">
              <div class="user-av"><?php echo $kounselia_avatar_url ? '<img src="' . esc_url( $kounselia_avatar_url ) . '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%">' : esc_html( mb_strtoupper( mb_substr( $kounselia_display_name, 0, 1 ) ) ); ?></div>
              <span class="user-name"><?php echo esc_html( $kounselia_display_name ); ?></span>
            </a>
            <button class="btn-ghost" onclick="logout()" style="padding: 7px 16px;"><?php echo $st( 's.home.sign_out' ); ?></button>
          </div>
        <?php else : ?>
          <a class="btn-ghost k-nav-btn k-hide-xs" href="/blog/" style="border-color:transparent"><?php echo $st( 's.nav.journal' ); ?></a>
          <button class="btn-ghost" onclick="openModal('login')"><?php echo $st( 's.nav.sign_in' ); ?></button>
          <button class="btn-nav-primary" onclick="openModal('register')"><?php echo $st( 's.nav.start_free' ); ?></button>
        <?php endif; ?>
      </div>
    </div>
  </nav>

  <!-- HERO -->
  <div class="hero container">
    <div class="hero-tag"><i class="ti ti-lock" style="font-size:14px"></i> <?php echo $st( 's.home.hero_tag' ); ?></div>
    <h1><?php echo $sth( 's.home.hero_title', array( 'em' => '<em>' . $st( 's.home.hero_title_em' ) . '</em>' ) ); ?></h1>
    <p><?php echo $st( 's.home.hero_p' ); ?></p>
    <div class="hero-cta">
      <button class="btn-lg primary" onclick="smoothTo('counselors-anchor')"><?php echo $st( 's.home.hero_cta_talk' ); ?></button>
      <button class="btn-lg outline" onclick="openModal('register')"><?php echo $st( 's.home.hero_cta_account' ); ?></button>
    </div>
  </div>

  <div class="trust-row">
    <div class="container" style="display: flex; justify-content: center; flex-wrap: wrap; gap: 16px;">
      <div class="trust-item"><i class="ti ti-globe"></i><span><?php echo $st( 's.home.trust_worldwide' ); ?></span></div>
      <div class="trust-item"><i class="ti ti-lock"></i><span><?php echo $st( 's.home.trust_private' ); ?></span></div>
      <div class="trust-item"><i class="ti ti-clock"></i><span><?php echo $st( 's.home.trust_24h' ); ?></span></div>
      <div class="trust-item"><i class="ti ti-user-check"></i><span><?php echo $st( 's.home.trust_no_appt' ); ?></span></div>
    </div>
  </div>

  <!-- HOW IT FEELS -->
  <div class="feels-section">
    <div class="container">
      <div class="section-eyebrow"><?php echo $st( 's.home.feels_eyebrow' ); ?></div>
      <h2 class="section-title"><?php echo $st( 's.home.feels_title' ); ?></h2>
      <p class="section-body"><?php echo $st( 's.home.feels_body' ); ?></p>
      <div class="feels-cards">
        <div class="feels-card">
          <div class="feels-icon ic-rose"><i class="ti ti-user-heart"></i></div>
          <div>
            <h4><?php echo $st( 's.home.feels1_title' ); ?></h4>
            <p><?php echo $st( 's.home.feels1_body' ); ?></p>
          </div>
        </div>
        <div class="feels-card">
          <div class="feels-icon ic-sage"><i class="ti ti-history"></i></div>
          <div>
            <h4><?php echo $st( 's.home.feels2_title' ); ?></h4>
            <p><?php echo $st( 's.home.feels2_body' ); ?></p>
          </div>
        </div>
        <div class="feels-card">
          <div class="feels-icon ic-blue"><i class="ti ti-shield-heart"></i></div>
          <div>
            <h4><?php echo $st( 's.home.feels3_title' ); ?></h4>
            <p><?php echo $st( 's.home.feels3_body' ); ?></p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- COUNSELORS -->
  <div class="counselors-section" id="counselors-anchor">
    <div class="container">
      <div class="counselors-head">
        <div class="section-eyebrow"><?php echo $st( 's.home.counselors_eyebrow' ); ?></div>
        <h2 class="section-title"><?php echo $st( 's.home.counselors_title' ); ?></h2>
      </div>
      <p class="counselors-sub"><?php echo $st( 's.home.counselors_sub' ); ?></p>

      <div class="counselors-list">

        <div class="counselor-card" onclick="startChat('serena')">
          <div class="card-top">
            <div class="card-av ic-rose"><i class="ti ti-heart"></i></div>
            <div class="card-meta">
              <div class="card-spec spec-rose"><?php echo $st( 's.home.c_serena_spec' ); ?></div>
              <div class="card-name">Serena</div>
            </div>
          </div>
          <div class="card-desc"><?php echo $st( 's.home.c_serena_desc' ); ?></div>
          <button class="start-btn sb-rose"><?php echo $st( 's.home.talk_to', array( 'name' => 'Serena' ) ); ?></button>
        </div>

        <div class="counselor-card" onclick="startChat('marcus')">
          <div class="card-top">
            <div class="card-av ic-blue"><i class="ti ti-briefcase"></i></div>
            <div class="card-meta">
              <div class="card-spec spec-blue"><?php echo $st( 's.home.c_marcus_spec' ); ?></div>
              <div class="card-name">Marcus</div>
            </div>
          </div>
          <div class="card-desc"><?php echo $st( 's.home.c_marcus_desc' ); ?></div>
          <button class="start-btn sb-blue"><?php echo $st( 's.home.talk_to', array( 'name' => 'Marcus' ) ); ?></button>
        </div>

        <div class="counselor-card" onclick="startChat('noa')">
          <div class="card-top">
            <div class="card-av ic-sage"><i class="ti ti-leaf"></i></div>
            <div class="card-meta">
              <div class="card-spec spec-sage"><?php echo $st( 's.home.c_noa_spec' ); ?></div>
              <div class="card-name">Noa</div>
            </div>
          </div>
          <div class="card-desc"><?php echo $st( 's.home.c_noa_desc' ); ?></div>
          <button class="start-btn sb-sage"><?php echo $st( 's.home.talk_to', array( 'name' => 'Noa' ) ); ?></button>
        </div>

        <div class="counselor-card" onclick="startChat('eli')">
          <div class="card-top">
            <div class="card-av ic-gold"><i class="ti ti-users"></i></div>
            <div class="card-meta">
              <div class="card-spec spec-gold"><?php echo $st( 's.home.c_eli_spec' ); ?></div>
              <div class="card-name">Eli</div>
            </div>
          </div>
          <div class="card-desc"><?php echo $st( 's.home.c_eli_desc' ); ?></div>
          <button class="start-btn sb-gold"><?php echo $st( 's.home.talk_to', array( 'name' => 'Eli' ) ); ?></button>
        </div>

        <div class="counselor-card" onclick="startChat('dr_lena')">
          <div class="card-top">
            <div class="card-av ic-teal"><i class="ti ti-stethoscope"></i></div>
            <div class="card-meta">
              <div class="card-spec spec-teal"><?php echo $st( 's.home.c_dr_lena_spec' ); ?></div>
              <div class="card-name">Dr. Lena</div>
            </div>
          </div>
          <div class="card-desc"><?php echo $st( 's.home.c_dr_lena_desc' ); ?></div>
          <button class="start-btn sb-teal"><?php echo $st( 's.home.talk_to', array( 'name' => 'Dr. Lena' ) ); ?></button>
        </div>

        <div class="counselor-card" onclick="startChat('james')">
          <div class="card-top">
            <div class="card-av ic-navy"><i class="ti ti-shield"></i></div>
            <div class="card-meta">
              <div class="card-spec spec-navy"><?php echo $st( 's.home.c_james_spec' ); ?></div>
              <div class="card-name">James</div>
            </div>
          </div>
          <div class="card-desc"><?php echo $st( 's.home.c_james_desc' ); ?></div>
          <button class="start-btn sb-navy"><?php echo $st( 's.home.talk_to', array( 'name' => 'James' ) ); ?></button>
        </div>

        <div class="counselor-card" onclick="startChat('theo')">
          <div class="card-top">
            <div class="card-av ic-plum"><i class="ti ti-candle"></i></div>
            <div class="card-meta">
              <div class="card-spec spec-plum"><?php echo $st( 's.home.c_theo_spec' ); ?></div>
              <div class="card-name">Theo</div>
            </div>
          </div>
          <div class="card-desc"><?php echo $st( 's.home.c_theo_desc' ); ?></div>
          <button class="start-btn sb-plum"><?php echo $st( 's.home.talk_to', array( 'name' => 'Theo' ) ); ?></button>
        </div>

        <div class="counselor-card" onclick="startChat('priya')">
          <div class="card-top">
            <div class="card-av ic-sienna"><i class="ti ti-battery-charging"></i></div>
            <div class="card-meta">
              <div class="card-spec spec-sienna"><?php echo $st( 's.home.c_priya_spec' ); ?></div>
              <div class="card-name">Priya</div>
            </div>
          </div>
          <div class="card-desc"><?php echo $st( 's.home.c_priya_desc' ); ?></div>
          <button class="start-btn sb-sienna"><?php echo $st( 's.home.talk_to', array( 'name' => 'Priya' ) ); ?></button>
        </div>

      </div>
      <p class="guest-note" id="guest-note" <?php echo $kounselia_is_logged_in ? 'style="display:none"' : ''; ?>><?php echo $sth( 's.home.guest_note', array( 'link' => '<a onclick="openModal(\'register\')">' . $st( 's.home.guest_register' ) . '</a>' ) ); ?></p>
    </div>
  </div>

  <!-- LICENSED PROFESSIONALS (see /professionals/ and includes/professionals-public.php) -->
  <?php $kounselia_home_pros = function_exists( 'kounselia_public_professionals' ) ? kounselia_public_professionals( 3 ) : array(); ?>
  <div class="pros-section" id="professionals-anchor">
    <div class="container">
      <div class="pros-head">
        <div>
          <div class="section-eyebrow"><?php echo $st( 's.home.pros_eyebrow' ); ?></div>
          <h2 class="section-title"><?php echo $st( 's.home.pros_title' ); ?></h2>
          <p><?php echo $st( 's.home.pros_p' ); ?></p>
        </div>
      </div>
      <?php if ( $kounselia_home_pros ) : ?>
        <div class="k-pro-grid">
          <?php foreach ( $kounselia_home_pros as $kounselia_pro ) { echo kounselia_professional_card_html( $kounselia_pro ); } ?>
        </div>
      <?php else : ?>
        <div class="pros-empty">
          <div><i class="ti ti-discount-check"></i><b><?php echo $st( 's.home.pros_e1_title' ); ?></b><?php echo $st( 's.home.pros_e1_body' ); ?></div>
          <div><i class="ti ti-video"></i><b><?php echo $st( 's.home.pros_e2_title' ); ?></b><?php echo $st( 's.home.pros_e2_body' ); ?></div>
          <div><i class="ti ti-star"></i><b><?php echo $st( 's.home.pros_e3_title' ); ?></b><?php echo $st( 's.home.pros_e3_body' ); ?></div>
        </div>
      <?php endif; ?>
      <div class="pros-actions">
        <a class="k-btn" href="/professionals/"><?php echo $st( 's.home.pros_meet' ); ?></a>
        <a class="k-btn outline" href="/professionals/join"><?php echo $st( 's.home.pros_join' ); ?></a>
      </div>
    </div>
  </div>

  <!-- VOICES -->
  <div class="voices-section">
    <div class="container">
      <div class="voices-head">
        <div class="section-eyebrow"><?php echo $st( 's.home.voices_eyebrow' ); ?></div>
        <h2 class="section-title"><?php echo $st( 's.home.voices_title' ); ?></h2>
      </div>
      <div class="voices-list">
        <div class="voice-card">
          <div class="voice-quote">"<?php echo $st( 's.home.voice1_quote' ); ?>"</div>
          <div class="voice-meta">
            <div class="voice-av" style="background:var(--rose-light);color:var(--rose)">A</div>
            <div class="voice-info"><strong>Adaeze, 31</strong><?php echo $st( 's.home.voice1_meta' ); ?></div>
          </div>
        </div>
        <div class="voice-card">
          <div class="voice-quote">"<?php echo $st( 's.home.voice2_quote' ); ?>"</div>
          <div class="voice-meta">
            <div class="voice-av" style="background:var(--accent-light);color:var(--accent)">R</div>
            <div class="voice-info"><strong>Ravi, 28</strong><?php echo $st( 's.home.voice2_meta' ); ?></div>
          </div>
        </div>
        <div class="voice-card">
          <div class="voice-quote">"<?php echo $st( 's.home.voice3_quote' ); ?>"</div>
          <div class="voice-meta">
            <div class="voice-av" style="background:var(--navy-light);color:var(--navy)">T</div>
            <div class="voice-info"><strong>Thomas, 34</strong><?php echo $st( 's.home.voice3_meta' ); ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- WHY IT MATTERS -->
  <div class="why-section" style="display: block;">
    <div class="container" style="display: flex; flex-wrap: wrap; gap: 64px; align-items: center; justify-content: space-between;">
      <div class="why-content" style="flex: 1; min-width: 320px;">
        <div class="why-eyebrow"><?php echo $st( 's.home.why_eyebrow' ); ?></div>
        <h2 class="why-title"><?php echo $st( 's.home.why_title' ); ?></h2>
        <p class="why-body"><?php echo $sth( 's.home.why_body', array( 'strong' => '<strong>' . $st( 's.home.why_body_strong' ) . '</strong>' ) ); ?></p>
        <div class="why-stats">
          <div class="why-stat">
            <div class="why-stat-num">1B<sup>+</sup></div>
            <div class="why-stat-label"><?php echo $st( 's.home.why_stat1' ); ?></div>
          </div>
          <div class="why-stat">
            <div class="why-stat-num">75<sup>%</sup></div>
            <div class="why-stat-label"><?php echo $st( 's.home.why_stat2' ); ?></div>
          </div>
        </div>
      </div>
      <div class="why-pillars" style="flex: 1; min-width: 320px;">
        <div class="why-pillar">
          <i class="ti ti-certificate"></i>
          <div>
            <h4><?php echo $st( 's.home.pillar1_title' ); ?></h4>
            <p><?php echo $st( 's.home.pillar1_body' ); ?></p>
          </div>
        </div>
        <div class="why-pillar">
          <i class="ti ti-microscope"></i>
          <div>
            <h4><?php echo $st( 's.home.pillar2_title' ); ?></h4>
            <p><?php echo $st( 's.home.pillar2_body' ); ?></p>
          </div>
        </div>
        <div class="why-pillar">
          <i class="ti ti-heart-handshake"></i>
          <div>
            <h4><?php echo $st( 's.home.pillar3_title' ); ?></h4>
            <p><?php echo $st( 's.home.pillar3_body' ); ?></p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- PARTNERS -->
  <div class="partners-section" style="display: block;">
    <div class="container" style="display: flex; flex-wrap: wrap; gap: 64px; align-items: flex-start; justify-content: space-between;">
      <div class="partners-content" style="flex: 1; min-width: 320px;">
        <div class="section-eyebrow"><?php echo $st( 's.home.partners_eyebrow' ); ?></div>
        <h2 class="section-title"><?php echo $st( 's.home.partners_title' ); ?></h2>
        <p class="partners-body"><?php echo $sth( 's.home.partners_body', array( 'strong' => '<strong>' . $st( 's.home.partners_body_strong' ) . '</strong>' ) ); ?></p>
      </div>
      <div class="partners-grid-wrap" style="flex: 1; min-width: 320px;">
        <div class="partners-grid">
          <div class="partner-cell"><div class="org">WHO Foundation</div><div class="type"><?php echo $st( 's.home.partner_t1' ); ?></div></div>
          <div class="partner-cell"><div class="org">Gates Foundation</div><div class="type"><?php echo $st( 's.home.partner_t2' ); ?></div></div>
          <div class="partner-cell"><div class="org">Wellcome Trust</div><div class="type"><?php echo $st( 's.home.partner_t3' ); ?></div></div>
          <div class="partner-cell"><div class="org">Open Society</div><div class="type"><?php echo $st( 's.home.partner_t4' ); ?></div></div>
        </div>
        <div class="contact-cta" style="margin-top: 24px;">
          <p><?php echo $st( 's.home.contact_p' ); ?></p>
          <button onclick="openModal('register')"><?php echo $st( 's.home.contact_btn' ); ?></button>
        </div>
      </div>
    </div>
  </div>

  <!-- FOOTER (links, social profiles and newsletter box are edited in Admin → Pages → Footer) -->
  <?php require __DIR__ . '/inc/kounselia-footer.php'; ?>

</div>

<?php require __DIR__ . '/inc/kounselia-chat-engine.php'; ?>

<script>
document.addEventListener('DOMContentLoaded',function(){
  const slug=(location.hash||'').replace('#','');
  // Content pages (/page/..., /blog/...) link here with ?auth=login or
  // ?auth=register to open the sign-in / sign-up box.
  const auth=new URLSearchParams(location.search).get('auth');
  if(auth==='login'||auth==='register'){
    if(loggedIn){ window.location.href=kounseliaReturnPath()||'/dashboard.php'; return; }
    openModal(auth);
  } else if(slug&&C[slug]){
    startChat(slug);
  } else if(loggedIn && !new URLSearchParams(location.search).has('browse')){
    // A signed-in member landing on the bare homepage (no hash, no
    // explicit browse intent) doesn't need the marketing pitch again,
    // send them straight to their dashboard. Links that intentionally
    // want the counselor-picker (the dashboard's own "Talk to someone"
    // buttons) add ?browse=1 to opt out of this redirect.
    window.location.href='/dashboard.php';
  }
});
</script>
<?php wp_footer(); ?>
</body>
</html>
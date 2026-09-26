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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Kounselia — Someone to Talk To, Anytime</title>
<meta name="description" content="Kounselia gives you a private space to talk through what you're carrying. Real conversations with specialist counselors, available anytime, anywhere.">

<!-- Favicon -->
<link rel="icon" type="image/png" href="https://kounselia.com/img/fv.png">
<link rel="apple-touch-icon" href="https://kounselia.com/img/fv.png">

<!-- Open Graph / Social Media -->
<meta property="og:type" content="website">
<meta property="og:url" content="https://kounselia.com/">
<meta property="og:title" content="Kounselia — Someone to Talk To, Anytime">
<meta property="og:description" content="Kounselia gives you a private space to talk through what you're carrying. Real conversations with specialist counselors, available anytime, anywhere.">
<meta property="og:image" content="https://kounselia.com/img/Kounselia_Banner_02_16_9.png">

<!-- Twitter -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:url" content="https://kounselia.com/">
<meta name="twitter:title" content="Kounselia — Someone to Talk To, Anytime">
<meta name="twitter:description" content="Kounselia gives you a private space to talk through what you're carrying. Real conversations with specialist counselors, available anytime, anywhere.">
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
            <button class="btn-ghost" onclick="logout()" style="padding: 7px 16px;">Sign out</button>
          </div>
        <?php else : ?>
          <a class="btn-ghost k-nav-btn k-hide-xs" href="/blog/" style="border-color:transparent">Journal</a>
          <button class="btn-ghost" onclick="openModal('login')">Sign in</button>
          <button class="btn-nav-primary" onclick="openModal('register')">Start free</button>
        <?php endif; ?>
      </div>
    </div>
  </nav>

  <!-- HERO -->
  <div class="hero container">
    <div class="hero-tag"><i class="ti ti-lock" style="font-size:14px"></i> Private and confidential</div>
    <h1>You deserve someone <em>to talk to</em></h1>
    <p>Sometimes the hardest part is finding a safe place to say what you're actually feeling. Kounselia gives you that space, anytime you need it.</p>
    <div class="hero-cta">
      <button class="btn-lg primary" onclick="smoothTo('counselors-anchor')">Talk to someone now</button>
      <button class="btn-lg outline" onclick="openModal('register')">Create free account</button>
    </div>
  </div>

  <div class="trust-row">
    <div class="container" style="display: flex; justify-content: center; flex-wrap: wrap; gap: 16px;">
      <div class="trust-item"><i class="ti ti-globe"></i><span>Available worldwide</span></div>
      <div class="trust-item"><i class="ti ti-lock"></i><span>Fully private</span></div>
      <div class="trust-item"><i class="ti ti-clock"></i><span>24 hours a day</span></div>
      <div class="trust-item"><i class="ti ti-user-check"></i><span>No appointment</span></div>
    </div>
  </div>

  <!-- HOW IT FEELS -->
  <div class="feels-section">
    <div class="container">
      <div class="section-eyebrow">What to expect</div>
      <h2 class="section-title">A conversation worth having</h2>
      <p class="section-body">Think of it like walking into a private clinic and being connected with the right specialist for what you are going through. Not a general assistant. A real conversation with someone who understands your specific situation.</p>
      <div class="feels-cards">
        <div class="feels-card">
          <div class="feels-icon ic-rose"><i class="ti ti-user-heart"></i></div>
          <div>
            <h4>The right person for you</h4>
            <p>Each counselor is built around one area. You are not talking to a generic tool. You are talking to someone who has spent everything on understanding exactly what you are going through.</p>
          </div>
        </div>
        <div class="feels-card">
          <div class="feels-icon ic-sage"><i class="ti ti-history"></i></div>
          <div>
            <h4>They remember your story</h4>
            <p>Every time you come back, your counselor already knows where you are. No starting over. No repeating yourself. The conversation picks up like a good therapist who reviewed your notes before you arrived.</p>
          </div>
        </div>
        <div class="feels-card">
          <div class="feels-icon ic-blue"><i class="ti ti-shield-heart"></i></div>
          <div>
            <h4>Built with your safety in mind</h4>
            <p>If things get serious, you will never be left alone. The platform is designed to recognise distress and connect you with real human support when you need it most.</p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- COUNSELORS -->
  <div class="counselors-section" id="counselors-anchor">
    <div class="container">
      <div class="counselors-head">
        <div class="section-eyebrow">Our counselors</div>
        <h2 class="section-title">Who would you like to talk to?</h2>
      </div>
      <p class="counselors-sub">No account needed. Just pick the person who feels right for what you are carrying today.</p>

      <div class="counselors-list">

        <div class="counselor-card" onclick="startChat('serena')">
          <div class="card-top">
            <div class="card-av ic-rose"><i class="ti ti-heart"></i></div>
            <div class="card-meta">
              <div class="card-spec spec-rose">Emotional healing</div>
              <div class="card-name">Serena</div>
            </div>
          </div>
          <div class="card-desc">Serena is the person you talk to when the feeling is hard to name. She never rushes. She never judges. She just listens and helps you find your way through.</div>
          <button class="start-btn sb-rose">Talk to Serena</button>
        </div>

        <div class="counselor-card" onclick="startChat('marcus')">
          <div class="card-top">
            <div class="card-av ic-blue"><i class="ti ti-briefcase"></i></div>
            <div class="card-meta">
              <div class="card-spec spec-blue">Career and purpose</div>
              <div class="card-name">Marcus</div>
            </div>
          </div>
          <div class="card-desc">Marcus is for the person standing at a crossroads. If your career no longer feels right or you are searching for what your work is supposed to mean, he will help you think it through clearly.</div>
          <button class="start-btn sb-blue">Talk to Marcus</button>
        </div>

        <div class="counselor-card" onclick="startChat('noa')">
          <div class="card-top">
            <div class="card-av ic-sage"><i class="ti ti-leaf"></i></div>
            <div class="card-meta">
              <div class="card-spec spec-sage">Personal growth</div>
              <div class="card-name">Noa</div>
            </div>
          </div>
          <div class="card-desc">Noa is for the person who knows something needs to change but cannot quite name what. She helps you look at the patterns, the inherited beliefs, and the version of yourself that is trying to emerge.</div>
          <button class="start-btn sb-sage">Talk to Noa</button>
        </div>

        <div class="counselor-card" onclick="startChat('eli')">
          <div class="card-top">
            <div class="card-av ic-gold"><i class="ti ti-users"></i></div>
            <div class="card-meta">
              <div class="card-spec spec-gold">Relationships</div>
              <div class="card-name">Eli</div>
            </div>
          </div>
          <div class="card-desc">Eli is for when your relationships are hurting. Whether it is a pattern you keep repeating, a conversation you keep avoiding, or love that is causing more pain than joy, Eli helps you see it clearly.</div>
          <button class="start-btn sb-gold">Talk to Eli</button>
        </div>

        <div class="counselor-card" onclick="startChat('dr_lena')">
          <div class="card-top">
            <div class="card-av ic-teal"><i class="ti ti-stethoscope"></i></div>
            <div class="card-meta">
              <div class="card-spec spec-teal">Trauma and PTSD</div>
              <div class="card-name">Dr. Lena</div>
            </div>
          </div>
          <div class="card-desc">Dr. Lena is for those carrying the weight of things that happened in the past. She moves at your pace, never pushes, and understands that healing from trauma takes its own kind of time.</div>
          <button class="start-btn sb-teal">Talk to Dr. Lena</button>
        </div>

        <div class="counselor-card" onclick="startChat('james')">
          <div class="card-top">
            <div class="card-av ic-navy"><i class="ti ti-shield"></i></div>
            <div class="card-meta">
              <div class="card-spec spec-navy">Men's mental health</div>
              <div class="card-name">James</div>
            </div>
          </div>
          <div class="card-desc">James is for men who were never given real space to talk. No performance, no judgement, no pressure to have it together. Just an honest conversation with someone who gets it.</div>
          <button class="start-btn sb-navy">Talk to James</button>
        </div>

        <div class="counselor-card" onclick="startChat('theo')">
          <div class="card-top">
            <div class="card-av ic-plum"><i class="ti ti-candle"></i></div>
            <div class="card-meta">
              <div class="card-spec spec-plum">Grief and loss</div>
              <div class="card-name">Theo</div>
            </div>
          </div>
          <div class="card-desc">Theo is for anyone who has lost something that mattered. A person, a relationship, a version of your life you had imagined. He holds space for grief with no timeline and no rush to feel better.</div>
          <button class="start-btn sb-plum">Talk to Theo</button>
        </div>

        <div class="counselor-card" onclick="startChat('priya')">
          <div class="card-top">
            <div class="card-av ic-sienna"><i class="ti ti-battery-charging"></i></div>
            <div class="card-meta">
              <div class="card-spec spec-sienna">Burnout and balance</div>
              <div class="card-name">Priya</div>
            </div>
          </div>
          <div class="card-desc">Priya is for the person who has been running on empty for too long. If you are exhausted in a way that sleep does not fix and meaning has drained out of things that used to matter, talk to Priya.</div>
          <button class="start-btn sb-sienna">Talk to Priya</button>
        </div>

      </div>
      <p class="guest-note" id="guest-note" <?php echo $kounselia_is_logged_in ? 'style="display:none"' : ''; ?>>No account needed to start. <a onclick="openModal('register')">Register free</a> to save your sessions and return anytime.</p>
    </div>
  </div>

  <!-- LICENSED PROFESSIONALS (see /professionals/ and includes/professionals-public.php) -->
  <?php $kounselia_home_pros = function_exists( 'kounselia_public_professionals' ) ? kounselia_public_professionals( 3 ) : array(); ?>
  <div class="pros-section" id="professionals-anchor">
    <div class="container">
      <div class="pros-head">
        <div>
          <div class="section-eyebrow">Licensed professionals</div>
          <h2 class="section-title">When you want a person in the room</h2>
          <p>Our AI counselors are there any time. When you're ready for more, book a private video session with a licensed psychologist, counsellor or therapist — every one verified by our team.</p>
        </div>
      </div>
      <?php if ( $kounselia_home_pros ) : ?>
        <div class="k-pro-grid">
          <?php foreach ( $kounselia_home_pros as $kounselia_pro ) { echo kounselia_professional_card_html( $kounselia_pro ); } ?>
        </div>
      <?php else : ?>
        <div class="pros-empty">
          <div><i class="ti ti-rosette-discount-check"></i><b>Licences verified</b>We check every professional's credentials with the body that issued them.</div>
          <div><i class="ti ti-video"></i><b>Private video sessions</b>Book a time that suits you and meet from your dashboard.</div>
          <div><i class="ti ti-star"></i><b>Real reviews</b>Rated by clients after real sessions — anonymously.</div>
        </div>
      <?php endif; ?>
      <div class="pros-actions">
        <a class="k-btn" href="/professionals/">Meet our professionals</a>
        <a class="k-btn outline" href="/professionals/join">Are you a professional? Join us</a>
      </div>
    </div>
  </div>

  <!-- VOICES -->
  <div class="voices-section">
    <div class="container">
      <div class="voices-head">
        <div class="section-eyebrow">Real conversations</div>
        <h2 class="section-title">What people are saying</h2>
      </div>
      <div class="voices-list">
        <div class="voice-card">
          <div class="voice-quote">"I have known for two years that I needed to talk to someone. The cost, the waiting, the fear of being judged kept stopping me. This was the first time I actually said what I have been carrying."</div>
          <div class="voice-meta">
            <div class="voice-av" style="background:var(--rose-light);color:var(--rose)">A</div>
            <div class="voice-info"><strong>Adaeze, 31</strong>Lagos, Nigeria. Talked to Serena.</div>
          </div>
        </div>
        <div class="voice-card">
          <div class="voice-quote">"Marcus asked me one question that completely changed how I see my career. I have been stuck in the wrong conversation with myself for three years."</div>
          <div class="voice-meta">
            <div class="voice-av" style="background:var(--accent-light);color:var(--accent)">R</div>
            <div class="voice-info"><strong>Ravi, 28</strong>Bengaluru, India. Talked to Marcus.</div>
          </div>
        </div>
        <div class="voice-card">
          <div class="voice-quote">"I did not expect to cry. James did not make me feel like something was wrong with me. He just listened and asked the right things."</div>
          <div class="voice-meta">
            <div class="voice-av" style="background:var(--navy-light);color:var(--navy)">T</div>
            <div class="voice-info"><strong>Thomas, 34</strong>Manchester, UK. Talked to James.</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- WHY IT MATTERS -->
  <div class="why-section" style="display: block;">
    <div class="container" style="display: flex; flex-wrap: wrap; gap: 64px; align-items: center; justify-content: space-between;">
      <div class="why-content" style="flex: 1; min-width: 320px;">
        <div class="why-eyebrow">Why Kounselia exists</div>
        <h2 class="why-title">Most people who need support never get it</h2>
        <p class="why-body">Not because they do not want help. But because therapy is expensive, waiting lists are long, and for many people around the world, <strong>a trained professional is simply not accessible.</strong> We built Kounselia because we believe that should change.</p>
        <div class="why-stats">
          <div class="why-stat">
            <div class="why-stat-num">1B<sup>+</sup></div>
            <div class="why-stat-label">People living with a mental health condition worldwide</div>
          </div>
          <div class="why-stat">
            <div class="why-stat-num">75<sup>%</sup></div>
            <div class="why-stat-label">In lower income countries receive no support at all</div>
          </div>
        </div>
      </div>
      <div class="why-pillars" style="flex: 1; min-width: 320px;">
        <div class="why-pillar">
          <i class="ti ti-certificate"></i>
          <div>
            <h4>Evidence informed design</h4>
            <p>Every counselor conversation is built around established therapeutic frameworks including CBT, motivational interviewing, and trauma informed care.</p>
          </div>
        </div>
        <div class="why-pillar">
          <i class="ti ti-microscope"></i>
          <div>
            <h4>Backed by research</h4>
            <p>We publish our approach openly and partner with researchers who study what actually works in digital mental health support.</p>
          </div>
        </div>
        <div class="why-pillar">
          <i class="ti ti-heart-handshake"></i>
          <div>
            <h4>Free at the core</h4>
            <p>The free tier never expires and never compromises on quality. Everyone deserves access regardless of income or location.</p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- PARTNERS -->
  <div class="partners-section" style="display: block;">
    <div class="container" style="display: flex; flex-wrap: wrap; gap: 64px; align-items: flex-start; justify-content: space-between;">
      <div class="partners-content" style="flex: 1; min-width: 320px;">
        <div class="section-eyebrow">Partnerships and funding</div>
        <h2 class="section-title">Working with people who share our values</h2>
        <p class="partners-body">Kounselia is a <strong>social initiative</strong> as much as a platform. We are actively building relationships with foundations, health organisations, and impact investors who believe mental health access is a fundamental right.</p>
      </div>
      <div class="partners-grid-wrap" style="flex: 1; min-width: 320px;">
        <div class="partners-grid">
          <div class="partner-cell"><div class="org">WHO Foundation</div><div class="type">Prospective partner</div></div>
          <div class="partner-cell"><div class="org">Gates Foundation</div><div class="type">Aligned mission</div></div>
          <div class="partner-cell"><div class="org">Wellcome Trust</div><div class="type">Health equity</div></div>
          <div class="partner-cell"><div class="org">Open Society</div><div class="type">Access and inclusion</div></div>
        </div>
        <div class="contact-cta" style="margin-top: 24px;">
          <p>If you represent a foundation, research institution, or public health body and would like to explore what we are building, we would love to hear from you.</p>
          <button onclick="openModal('register')">Get in touch</button>
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
    if(loggedIn){ window.location.href='/dashboard.php'; return; }
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
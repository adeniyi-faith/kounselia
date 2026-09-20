<?php
/**
 * Kounselia — a professional's own home, from the moment they apply.
 *
 * Same boot pattern as dashboard.php/talk.php: wp-load.php only, no
 * theme. Gated to a signed-in user who has an application on file at
 * all — anyone with none gets sent to /apply.php. Someone who just
 * registered as a professional lands here, not on the client-facing
 * /dashboard.php (mood check-ins, journal, "talk to someone now") —
 * that page is built for a person seeking support, and showing it to
 * someone who just said "I'm a therapist" is confusing, not welcoming.
 * dashboard.php itself now redirects a professional back here unless
 * they explicitly switch to client view from this page.
 *
 * What changes by status is what's UNLOCKED on this same page, not
 * which page they see:
 *   - pending/rejected: can edit their draft profile & rate (so it's
 *     ready to go the moment they're approved), can't do anything that
 *     requires verification yet (nothing does today; the booking
 *     directory and earnings ledger land in later slices and will gate
 *     here the same way).
 *   - verified: same editor, plus whatever those later slices unlock.
 *
 * All the actual read/write logic lives in
 * portal/wp-content/mu-plugins/kounselia/includes/professionals.php
 * (kounselia_ajax_update_professional_profile). This page is just the form.
 */
define( 'WP_USE_THEMES', false );
define( 'COOKIEPATH', '/' );
define( 'SITECOOKIEPATH', '/' );
require_once __DIR__ . '/portal/wp-load.php';

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( '/index.php' );
    exit;
}

$user        = wp_get_current_user();
$application = kounselia_get_professional_application( $user->ID );

if ( ! $application ) {
    wp_safe_redirect( '/apply.php' );
    exit;
}

$is_verified  = ( 'verified' === $application->status );
$display_name = $user->display_name ? $user->display_name : $user->user_login;
$first_name   = explode( ' ', trim( $display_name ) )[0];
$avatar_url   = function_exists( 'kounselia_get_avatar_url' ) ? kounselia_get_avatar_url( $user->ID, 'thumbnail' ) : false;
$initial      = mb_strtoupper( mb_substr( $display_name, 0, 1 ) );

$ajax_url = set_url_scheme( admin_url( 'admin-ajax.php' ), is_ssl() ? 'https' : 'http' );
$nonce    = wp_create_nonce( 'kounselia_auth' );
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Your Professional Dashboard — Kounselia</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/png" href="https://kounselia.com/img/fv.png">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
<?php require __DIR__ . '/inc/kounselia-styles.php'; ?>
<style>
:root{ --topbar-h: 64px; --tabbar-h: 64px; }
html,body{height:100%;background:var(--bg)}
body{font-family:'Outfit',sans-serif;color:var(--text);-webkit-font-smoothing:antialiased}

.shell{display:flex;min-height:100vh}
.sidebar{width:248px;flex-shrink:0;background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;justify-content:space-between;padding:28px 20px;position:sticky;top:0;height:100vh}
.logo{padding:0 8px 28px;display:flex;align-items:center}
.site-logo{max-height:30px;width:auto;object-fit:contain;transition:transform .3s ease}
.logo a:hover .site-logo{transform:scale(1.03)}
.side-nav{display:flex;flex-direction:column;gap:4px}
.nav-link{display:flex;align-items:center;gap:12px;padding:11px 14px;border-radius:12px;color:var(--text2);text-decoration:none;font-size:14.5px;font-weight:500;cursor:pointer;transition:background .2s ease,color .2s ease;border:none;background:none;width:100%;text-align:left;font-family:inherit}
.nav-link i{font-size:18px;width:20px;text-align:center}
.nav-link:hover{background:var(--surface2);color:var(--text)}
.nav-link.active{background:var(--accent-light);color:var(--accent)}
.nav-divider{height:1px;background:var(--border);margin:16px 4px}
.switch-link{display:flex;align-items:center;gap:12px;padding:11px 14px;border-radius:12px;color:var(--gold);text-decoration:none;font-size:13.5px;font-weight:600;background:var(--gold-light)}
.switch-link:hover{opacity:.85}
.switch-link i{font-size:17px}
.side-foot{display:flex;flex-direction:column;gap:14px}
.side-user{display:flex;align-items:center;gap:10px;padding:10px;border-radius:14px;background:var(--surface2)}
.side-av{width:36px;height:36px;border-radius:50%;background:var(--accent-light);color:var(--accent);display:flex;align-items:center;justify-content:center;font-weight:600;font-size:14px;overflow:hidden;flex-shrink:0}
.side-av img{width:100%;height:100%;object-fit:cover}
.side-user-meta{overflow:hidden}
.side-user-name{font-size:13.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.side-user-plan{font-size:11.5px;color:var(--text3)}
.signout-btn{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:12px;border:1px solid var(--border);background:none;color:var(--text2);font-family:inherit;font-size:13.5px;font-weight:500;cursor:pointer;transition:all .2s ease}
.signout-btn:hover{border-color:var(--text3);color:var(--text)}

.mobile-topbar{display:none}
.mobile-tabbar{display:none}
.main{flex:1;min-width:0;padding:36px 44px 80px;max-width:1080px}

.view-panel{display:none;animation:panelFade .25s ease-out forwards}
.view-panel.active{display:block}
@keyframes panelFade{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}

.welcome{position:relative;overflow:hidden;border-radius:28px;background:linear-gradient(135deg,var(--accent) 0%,var(--navy) 100%);padding:40px 36px;color:#fff;margin-bottom:24px}
.welcome-orb{position:absolute;width:340px;height:340px;border-radius:50%;background:radial-gradient(circle,rgba(176,125,58,0.35) 0%,rgba(176,125,58,0) 70%);top:-120px;right:-80px;animation:breathe 7s ease-in-out infinite}
@keyframes breathe{0%,100%{transform:scale(1);opacity:.7}50%{transform:scale(1.18);opacity:1}}
@media (prefers-reduced-motion: reduce){.welcome-orb{animation:none}}
.welcome-eyebrow{font-size:11px;font-weight:600;letter-spacing:2.5px;text-transform:uppercase;color:rgba(255,255,255,.6);margin-bottom:10px;position:relative;display:flex;align-items:center;gap:8px}
.verified-pill{display:inline-flex;align-items:center;gap:5px;background:rgba(255,255,255,.16);padding:3px 10px;border-radius:50px;font-size:10.5px;letter-spacing:.06em;color:#fff}
.welcome h1{font-family:'Cormorant Garamond',serif;font-weight:400;font-size:36px;position:relative;max-width:560px;line-height:1.2}
.welcome h1 em{font-style:italic;color:#E8C896}
.welcome p{position:relative;color:rgba(255,255,255,.78);margin-top:10px;font-size:15px;max-width:520px;font-weight:300;line-height:1.6}
.welcome-actions{position:relative;display:flex;gap:12px;margin-top:24px;flex-wrap:wrap}
.btn-w{padding:13px 22px;border-radius:50px;font-family:'Outfit',sans-serif;font-size:14px;font-weight:500;cursor:pointer;border:none;transition:all .25s ease;text-decoration:none;display:inline-flex;align-items:center;gap:8px}
.btn-w.primary{background:#fff;color:var(--accent)}
.btn-w.primary:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(0,0,0,.18)}
.btn-w.ghost{background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.25)}
.btn-w.ghost:hover{background:rgba(255,255,255,.2)}

.status-banner{border-radius:16px;padding:16px 18px;margin-bottom:24px;font-size:14px;line-height:1.55}
.status-banner.pending{background:var(--gold-light);color:#6B4A1F}
.status-banner.verified{background:var(--sage-light);color:var(--sage)}
.status-banner.rejected{background:var(--rose-light);color:var(--rose)}
.status-banner strong{display:block;font-size:13px;text-transform:uppercase;letter-spacing:.03em;margin-bottom:4px}

.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px}
@media (max-width:720px){.stats-row{grid-template-columns:1fr 1fr}}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-sm);padding:22px;box-shadow:var(--shadow-sm)}
.stat-icon{width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:17px;margin-bottom:14px}
.stat-num{font-family:'Cormorant Garamond',serif;font-size:28px;font-weight:500;line-height:1}
.stat-label{font-size:13px;color:var(--text2);margin-top:6px}

.section{margin-bottom:40px}
.section-head{display:flex;align-items:baseline;justify-content:space-between;margin-bottom:18px}
.section-head h2{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:23px}
.section-sub{font-size:13px;color:var(--text3)}

.capability-list{display:grid;gap:8px}
.capability-row{display:flex;align-items:center;gap:10px;font-size:13.5px;padding:12px 16px;border-radius:12px;background:var(--surface);border:1px solid var(--border)}
.capability-row.locked{color:var(--text3)}
.capability-row.unlocked{color:var(--text)}
.capability-row i{font-size:16px;flex-shrink:0}
.capability-row.unlocked i{color:var(--sage)}
.capability-row.locked i{color:var(--text3)}
.capability-row .tag{margin-left:auto;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.03em}
.capability-row.locked .tag{color:var(--text3)}
.capability-row.unlocked .tag{color:var(--sage)}

.pro-card{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:32px}
.form-field textarea{width:100%;padding:12px 14px;border:1.5px solid var(--border);border-radius:12px;font-family:inherit;font-size:14.5px;color:var(--text);background:var(--bg);outline:none;resize:vertical;min-height:90px}
.form-field textarea:focus{border-color:var(--accent);background:var(--surface);box-shadow:0 0 0 4px var(--accent-light)}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media (max-width:520px){.form-row{grid-template-columns:1fr}}
.rate-prefix{display:flex;align-items:center;gap:8px}
.rate-prefix span{font-weight:600;color:var(--text2)}
.readonly-note{font-size:12.5px;color:var(--text3);background:var(--bg2,#F8FAFC);border-radius:10px;padding:10px 12px;margin-bottom:20px}
.pro-submit{width:100%;margin-top:20px;padding:14px;border:none;border-radius:14px;background:var(--accent);color:#fff;font-size:15px;font-weight:600;cursor:pointer;font-family:inherit}
.pro-submit:hover{background:var(--accent2)}
.pro-submit:disabled{opacity:.6;cursor:not-allowed}
.pro-msg{margin-top:14px;font-size:13.5px;padding:12px 14px;border-radius:10px;display:none}
.pro-msg.error{display:block;background:var(--rose-light);color:var(--rose)}
.pro-msg.notice{display:block;background:var(--sage-light);color:var(--sage)}

.coming-soon{background:var(--surface);border:1px dashed var(--border);border-radius:20px;padding:56px 32px;text-align:center}
.coming-soon-icon{width:56px;height:56px;border-radius:16px;background:var(--accent-light);color:var(--accent);display:flex;align-items:center;justify-content:center;font-size:24px;margin:0 auto 18px}
.coming-soon h3{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:22px;margin-bottom:8px}
.coming-soon p{color:var(--text2);font-size:14px;max-width:380px;margin:0 auto;line-height:1.6}

@media (max-width:900px){
  .shell{flex-direction:column}
  .sidebar{display:none}
  .main{padding:24px 18px calc(var(--tabbar-h) + 24px);max-width:100%}
  .mobile-topbar{display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:20;height:var(--topbar-h);padding:0 18px;background:var(--surface);border-bottom:1px solid var(--border)}
  .mobile-topbar-right{display:flex;align-items:center;gap:10px}
  .mobile-av{width:32px;height:32px;border-radius:50%;background:var(--accent-light);color:var(--accent);display:flex;align-items:center;justify-content:center;font-weight:600;font-size:13px;border:none;cursor:pointer;overflow:hidden}
  .mobile-av img{width:100%;height:100%;object-fit:cover}
  .mobile-signout{width:32px;height:32px;border-radius:50%;border:1px solid var(--border);background:none;color:var(--text2);cursor:pointer;display:flex;align-items:center;justify-content:center}
  .mobile-tabbar{display:flex;position:fixed;bottom:0;left:0;right:0;z-index:20;height:var(--tabbar-h);background:var(--surface);border-top:1px solid var(--border)}
  .mob-tab{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;background:none;border:none;color:var(--text3);font-size:10.5px;font-family:inherit;cursor:pointer}
  .mob-tab i{font-size:19px}
  .mob-tab.active{color:var(--accent)}

  .welcome{padding:28px 22px}
  .welcome h1{font-size:26px}
  .welcome-actions .btn-w{width:100%;justify-content:center}
}
</style>
</head>
<body>

<div class="shell">

  <aside class="sidebar">
    <div>
      <div class="logo">
        <a href="/pro-dashboard.php" class="logo-link" style="display:inline-block;outline:none;">
          <img src="https://kounselia.com/img/Kounselia_Logo_IconMark_MidnightNavy.png" alt="Kounselia" class="site-logo" fetchpriority="high">
        </a>
      </div>
      <nav class="side-nav">
        <button class="nav-link js-nav active" id="desk-tab-overview" onclick="switchTab('overview')"><i class="ti ti-layout-dashboard"></i><span>Overview</span></button>
        <button class="nav-link js-nav" id="desk-tab-profile" onclick="switchTab('profile')"><i class="ti ti-user-edit"></i><span>Profile &amp; Rate</span></button>
        <button class="nav-link js-nav" id="desk-tab-bookings" onclick="switchTab('bookings')"><i class="ti ti-calendar-event"></i><span>Bookings</span></button>
        <button class="nav-link js-nav" id="desk-tab-earnings" onclick="switchTab('earnings')"><i class="ti ti-cash"></i><span>Earnings</span></button>
      </nav>
      <div class="nav-divider"></div>
      <a class="switch-link" href="/dashboard.php?as=client"><i class="ti ti-switch-horizontal"></i><span>Switch to client view</span></a>
    </div>
    <div class="side-foot">
      <div class="side-user">
        <div class="side-av" id="side-av"><?php echo $avatar_url ? '<img src="' . esc_url( $avatar_url ) . '" alt="">' : esc_html( $initial ); ?></div>
        <div class="side-user-meta">
          <div class="side-user-name"><?php echo esc_html( $display_name ); ?></div>
          <div class="side-user-plan"><?php echo $is_verified ? 'Verified professional' : 'Application ' . esc_html( $application->status ); ?></div>
        </div>
      </div>
      <button class="signout-btn" onclick="signOut()"><i class="ti ti-logout"></i> Sign out</button>
    </div>
  </aside>

  <!-- MOBILE TOP BAR -->
  <header class="mobile-topbar">
    <a href="/pro-dashboard.php" class="mobile-topbar-logo" style="display:flex;align-items:center;outline:none;text-decoration:none;">
      <img src="https://kounselia.com/img/Kounselia_Logo_IconMark_MidnightNavy.png" alt="Kounselia" style="max-height:24px;width:auto;object-fit:contain;" fetchpriority="high">
    </a>
    <div class="mobile-topbar-right">
      <a class="mobile-signout" href="/dashboard.php?as=client" aria-label="Switch to client view"><i class="ti ti-switch-horizontal"></i></a>
      <button class="mobile-av" id="mobile-av"><?php echo $avatar_url ? '<img src="' . esc_url( $avatar_url ) . '" alt="">' : esc_html( $initial ); ?></button>
      <button class="mobile-signout" onclick="signOut()" aria-label="Sign out"><i class="ti ti-logout"></i></button>
    </div>
  </header>

  <main class="main">

  <!-- OVERVIEW -->
  <div class="view-panel active" id="view-overview">
    <section class="welcome">
      <div class="welcome-orb"></div>
      <div class="welcome-eyebrow">Your practice
        <?php if ( $is_verified ) : ?><span class="verified-pill"><i class="ti ti-check" style="font-size:11px;"></i> Verified</span><?php endif; ?>
      </div>
      <h1>Welcome back, <em><?php echo esc_html( $first_name ); ?></em>.</h1>
      <p><?php echo $is_verified
        ? 'This is what clients will see, and your rate is entirely yours to set — Kounselia never changes it for you.'
        : 'Get your profile ready while your application is reviewed. It stays private until you\'re verified.'; ?></p>
      <div class="welcome-actions">
        <button class="btn-w primary" onclick="switchTab('profile')"><i class="ti ti-user-edit"></i> Edit profile &amp; rate</button>
      </div>
    </section>

    <?php if ( 'pending' === $application->status ) : ?>
      <div class="status-banner pending"><strong>Under review</strong>We're checking your documents — we'll email you once there's a decision, usually within a few business days.</div>
    <?php elseif ( 'rejected' === $application->status ) : ?>
      <div class="status-banner rejected"><strong>Not approved yet</strong><?php echo $application->rejection_reason ? esc_html( $application->rejection_reason ) : 'Update your documents and reapply when you\'re ready.'; ?> — <a href="/apply.php" style="color:inherit;text-decoration:underline">reapply with new documents</a>.</div>
    <?php else : ?>
      <div class="status-banner verified"><strong>Verified</strong>Your profile is live for clients.</div>
    <?php endif; ?>

    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--accent-light);color:var(--accent);"><i class="ti ti-cash"></i></div>
        <div class="stat-num"><?php echo $application->rate_amount ? '₦' . esc_html( number_format( (float) $application->rate_amount ) ) : 'Not set'; ?></div>
        <div class="stat-label">Rate per session</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--gold-light);color:var(--gold);"><i class="ti ti-award"></i></div>
        <div class="stat-num"><?php echo $application->years_experience ? esc_html( $application->years_experience ) : '—'; ?></div>
        <div class="stat-label">Years of experience</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--sage-light);color:var(--sage);"><i class="ti ti-calendar-event"></i></div>
        <div class="stat-num">0</div>
        <div class="stat-label">Client bookings</div>
      </div>
    </div>

    <div class="section">
      <div class="section-head">
        <h2>What's unlocked</h2>
        <span class="section-sub">Updates automatically as your practice grows</span>
      </div>
      <div class="capability-list">
        <div class="capability-row unlocked"><i class="ti ti-user-edit"></i> Edit your profile &amp; rate<span class="tag">Available</span></div>
        <div class="capability-row unlocked"><i class="ti ti-heart-handshake"></i> Use Kounselia as a client too<span class="tag">Available</span></div>
        <div class="capability-row <?php echo $is_verified ? 'unlocked' : 'locked'; ?>"><i class="ti ti-calendar-event"></i> Receive client bookings<span class="tag"><?php echo $is_verified ? 'Coming soon' : 'Locked until verified'; ?></span></div>
        <div class="capability-row <?php echo $is_verified ? 'unlocked' : 'locked'; ?>"><i class="ti ti-cash"></i> Earnings &amp; payouts<span class="tag"><?php echo $is_verified ? 'Coming soon' : 'Locked until verified'; ?></span></div>
      </div>
    </div>
  </div>

  <!-- PROFILE & RATE -->
  <div class="view-panel" id="view-profile">
    <div class="section-head">
      <h2>Profile &amp; rate</h2>
      <span class="section-sub">This is what clients see once you're verified</span>
    </div>
    <div class="pro-card">
      <?php if ( $application->license_number ) : ?>
        <div class="readonly-note">Verified against license/registration <strong><?php echo esc_html( $application->license_number ); ?></strong>. To change your credentials, contact support — that requires re-verification.</div>
      <?php endif; ?>

      <form id="pro-form">
        <div class="form-field"><label>Professional title</label><input type="text" name="title" id="pro-title" value="<?php echo esc_attr( $application->title ); ?>"></div>
        <div class="form-row">
          <div class="form-field"><label>Specialty</label><input type="text" name="specialty" id="pro-specialty" value="<?php echo esc_attr( $application->specialty ); ?>"></div>
          <div class="form-field"><label>Years of experience</label><input type="number" name="years_experience" id="pro-years" min="0" max="60" value="<?php echo esc_attr( $application->years_experience ); ?>"></div>
        </div>
        <div class="form-field"><label>Bio</label><textarea name="bio" id="pro-bio"><?php echo esc_textarea( $application->bio ); ?></textarea></div>
        <div class="form-field">
          <label>Your rate per session</label>
          <div class="rate-prefix"><span>₦</span><input type="number" name="rate_amount" id="pro-rate" min="0" step="0.01" value="<?php echo esc_attr( $application->rate_amount ); ?>"></div>
        </div>

        <button type="submit" class="pro-submit" id="pro-save-btn">Save changes</button>
        <div class="pro-msg" id="pro-msg"></div>
      </form>
    </div>
  </div>

  <!-- BOOKINGS -->
  <div class="view-panel" id="view-bookings">
    <div class="section-head">
      <h2>Bookings</h2>
      <span class="section-sub">Client session requests</span>
    </div>
    <div class="coming-soon">
      <div class="coming-soon-icon"><i class="ti ti-calendar-event"></i></div>
      <h3>Bookings are coming soon</h3>
      <p>Once this launches, clients will be able to book sessions with you directly, and requests will show up right here.</p>
    </div>
  </div>

  <!-- EARNINGS -->
  <div class="view-panel" id="view-earnings">
    <div class="section-head">
      <h2>Earnings</h2>
      <span class="section-sub">Payouts and session history</span>
    </div>
    <div class="coming-soon">
      <div class="coming-soon-icon"><i class="ti ti-cash"></i></div>
      <h3>Earnings &amp; payouts are coming soon</h3>
      <p>Once bookings launch, you'll be able to track what you've earned and manage payouts from this tab.</p>
    </div>
  </div>

  </main>

  <!-- MOBILE TAB BAR -->
  <nav class="mobile-tabbar">
    <button class="mob-tab active" id="mob-tab-overview" onclick="switchTab('overview')"><i class="ti ti-layout-dashboard"></i>Overview</button>
    <button class="mob-tab" id="mob-tab-profile" onclick="switchTab('profile')"><i class="ti ti-user-edit"></i>Profile</button>
    <button class="mob-tab" id="mob-tab-bookings" onclick="switchTab('bookings')"><i class="ti ti-calendar-event"></i>Bookings</button>
    <button class="mob-tab" id="mob-tab-earnings" onclick="switchTab('earnings')"><i class="ti ti-cash"></i>Earnings</button>
  </nav>

</div>

<script>
const KOUNSELIA={ajaxUrl:<?php echo wp_json_encode( $ajax_url ); ?>,nonce:<?php echo wp_json_encode( $nonce ); ?>};

function switchTab(tab){
  document.querySelectorAll('.view-panel').forEach(p => p.classList.remove('active'));
  const target = document.getElementById('view-' + tab);
  if (target) {
    target.classList.add('active');
    if (window.innerWidth <= 900) { target.scrollTop = 0; }
    else { window.scrollTo({ top: 0, behavior: 'smooth' }); }
  }
  document.querySelectorAll('.nav-link.js-nav').forEach(b => b.classList.remove('active'));
  const deskActive = document.getElementById('desk-tab-' + tab);
  if (deskActive) deskActive.classList.add('active');
  document.querySelectorAll('.mob-tab').forEach(b => b.classList.remove('active'));
  const mobActive = document.getElementById('mob-tab-' + tab);
  if (mobActive) mobActive.classList.add('active');
}

function signOut(){
  fetch(KOUNSELIA.ajaxUrl,{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams({action:'kounselia_logout',nonce:KOUNSELIA.nonce})
  }).finally(()=>{ window.location.href='/index.php'; });
}

document.getElementById('pro-form').addEventListener('submit', function(e){
  e.preventDefault();
  const btn = document.getElementById('pro-save-btn');
  const msg = document.getElementById('pro-msg');
  msg.className = 'pro-msg';
  msg.textContent = '';
  btn.disabled = true;
  btn.textContent = 'Saving...';

  fetch(KOUNSELIA.ajaxUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      action: 'kounselia_update_professional_profile',
      nonce: KOUNSELIA.nonce,
      title: document.getElementById('pro-title').value,
      specialty: document.getElementById('pro-specialty').value,
      years_experience: document.getElementById('pro-years').value,
      bio: document.getElementById('pro-bio').value,
      rate_amount: document.getElementById('pro-rate').value
    })
  })
  .then(r => r.json())
  .then(res => {
    btn.disabled = false;
    btn.textContent = 'Save changes';
    if (res.success) {
      msg.classList.add('notice');
      msg.textContent = res.data.message || 'Saved.';
    } else {
      msg.classList.add('error');
      msg.textContent = (res.data && res.data.message) ? res.data.message : 'Something went wrong, please try again.';
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.textContent = 'Save changes';
    msg.classList.add('error');
    msg.textContent = 'Something went wrong, please check your connection and try again.';
  });
});
</script>

</body>
</html>

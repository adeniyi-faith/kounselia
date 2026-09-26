<?php
/**
 * Kounselia member dashboard.
 */
define( 'WP_USE_THEMES', false );
define( 'COOKIEPATH', '/' );
define( 'SITECOOKIEPATH', '/' );
require_once __DIR__ . '/portal/wp-load.php';

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( '/index.php' );
    exit;
}

$user            = wp_get_current_user();
$display_name    = $user->display_name ? $user->display_name : $user->user_login;
$first_name      = explode( ' ', trim( $display_name ) )[0];
$avatar_url      = function_exists( 'kounselia_get_avatar_url' ) ? kounselia_get_avatar_url( $user->ID, 'thumbnail' ) : false;
$initial         = mb_strtoupper( mb_substr( $display_name, 0, 1 ) );

$stats           = function_exists( 'kounselia_get_dashboard_stats' ) ? kounselia_get_dashboard_stats( $user->ID ) : array(
    'total_sessions' => 0, 'messages_this_week' => 0, 'counselors_met' => 0, 'member_since' => $user->user_registered,
);
$recent_sessions = function_exists( 'kounselia_get_recent_sessions' ) ? kounselia_get_recent_sessions( $user->ID, 5 ) : array();

$mood_options  = function_exists( 'kounselia_mood_options' ) ? kounselia_mood_options() : array();
$mood_map      = function_exists( 'kounselia_mood_counselor_map' ) ? kounselia_mood_counselor_map() : array();
$today_mood    = function_exists( 'kounselia_get_today_mood' ) ? kounselia_get_today_mood( $user->ID ) : null;
$recent_moods  = function_exists( 'kounselia_get_recent_moods' ) ? kounselia_get_recent_moods( $user->ID, 7 ) : array();
$today_journal = function_exists( 'kounselia_get_today_journal' ) ? kounselia_get_today_journal( $user->ID ) : '';
$next_checkin  = function_exists( 'kounselia_get_next_checkin' ) ? kounselia_get_next_checkin( $user->ID ) : null;
$pro_application = function_exists( 'kounselia_get_professional_application' ) ? kounselia_get_professional_application( $user->ID ) : null;

$verified_professionals = function_exists( 'kounselia_get_verified_professionals' ) ? kounselia_get_verified_professionals() : array();
$my_bookings            = function_exists( 'kounselia_get_client_bookings' ) ? kounselia_get_client_bookings( $user->ID ) : array();
$past_bookings          = function_exists( 'kounselia_get_client_past_bookings' ) ? kounselia_get_client_past_bookings( $user->ID ) : array();

// Professionals get their own dashboard by default — this page is for
// people seeking support, not for managing a practice. A professional
// who wants to use Kounselia as a client too can switch over explicitly
// (see the "Switch to client view" link on pro-dashboard.php), which
// lands here with ?as=client and skips this redirect.
if ( $pro_application && 'client' !== ( $_GET['as'] ?? '' ) ) {
    wp_safe_redirect( '/pro-dashboard.php' );
    exit;
}

$ajax_url = set_url_scheme( admin_url( 'admin-ajax.php' ), is_ssl() ? 'https' : 'http' );
$nonce    = wp_create_nonce( 'kounselia_auth' );

$available_plans     = function_exists( 'kounselia_get_plans' ) ? kounselia_get_plans( true ) : array();
$user_subscription   = function_exists( 'kounselia_get_user_subscription' ) ? kounselia_get_user_subscription( $user->ID ) : null;
$subscription_active = $user_subscription && strtotime( $user_subscription->current_period_end ) > current_time( 'timestamp' );
$viewer_currency     = function_exists( 'kounselia_viewer_currency' ) ? kounselia_viewer_currency() : 'NGN';

// Pro members' discount on professional sessions (capped at the commission; see membership.php).
$kounselia_session_discount = function_exists( 'kounselia_member_session_price' )
    ? kounselia_member_session_price( $user->ID, 100, kounselia_booking_commission_percent() )['discount_percent']
    : 0;
$kounselia_reflection_allowance = function_exists( 'kounselia_reflection_allowance' ) ? kounselia_reflection_allowance( $user->ID ) : null;

$imported_memory     = get_user_meta( $user->ID, 'kounselia_imported_memory', true );
$memory_imported_at  = get_user_meta( $user->ID, 'kounselia_memory_imported_at', true );

$is_new_user         = get_user_meta( $user->ID, 'kounselia_is_new_user', true ); // Intake flow flag

$core_memory_json    = get_user_meta( $user->ID, 'kounselia_core_memory', true );
$latest_reflection_json = get_user_meta( $user->ID, 'kounselia_latest_reflection', true );
$reflection_date        = get_user_meta( $user->ID, 'kounselia_reflection_date', true );

$hour = (int) current_time( 'G' );
if ( $hour < 5 )       { $greeting = 'Still up,'; }
elseif ( $hour < 12 )  { $greeting = 'Good morning,'; }
elseif ( $hour < 17 )  { $greeting = 'Good afternoon,'; }
else                   { $greeting = 'Good evening,'; }

$member_since_label = date_i18n( 'F Y', strtotime( $stats['member_since'] ) );

// Fetch ALL unified counselors, then filter to only the active ones
$all_ui = function_exists('kounselia_get_all_ui') ? kounselia_get_all_ui() : array();
$counselors = array();
foreach ( $all_ui as $slug => $c_data ) {
    $prompt_data = function_exists('kounselia_get_counselor_prompt') ? kounselia_get_counselor_prompt( $slug ) : null;
    if ( $prompt_data ) { // Returns null if inactive in DB
        $counselors[$slug] = $c_data;
    }
}

$tried_slugs = wp_list_pluck( $recent_sessions, 'counselor_slug' );
$not_tried   = array_diff( array_keys( $counselors ), $tried_slugs );

if ( $today_mood && isset( $mood_map[ $today_mood ] ) && isset( $counselors[ $mood_map[ $today_mood ] ] ) ) {
    $recommended_slug = $mood_map[ $today_mood ];
    $recommend_reason  = "Matched to how you said you're feeling today.";
} elseif ( ! empty( $not_tried ) ) {
    $recommended_slug = reset( $not_tried );
    $recommend_reason  = "Someone you haven't talked to yet.";
} elseif ( ! empty( $tried_slugs ) ) {
    $recommended_slug = $tried_slugs[0];
    $recommend_reason  = 'Pick up where you left off.';
} else {
    $recommended_slug = !empty($counselors) ? array_keys($counselors)[0] : 'serena';
    $recommend_reason  = 'A good place to start.';
}
$recommended = isset($counselors[$recommended_slug]) ? $counselors[$recommended_slug] : array('name' => 'Counselor', 'spec' => '', 'icon' => 'ti-heart', 'class' => 'ic-blue');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Your space — Kounselia</title>
<meta name="robots" content="noindex, nofollow">

<!-- Favicon -->
<link rel="icon" type="image/png" href="https://kounselia.com/img/fv.png">
<link rel="apple-touch-icon" href="https://kounselia.com/img/fv.png">

<!-- Open Graph fallback for protected pages -->
<meta property="og:title" content="Your space — Kounselia">
<meta property="og:image" content="https://kounselia.com/img/Kounselia_Banner_02_16_9.png">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="https://kounselia.com/img/Kounselia_Banner_02_16_9.png">

<!-- PRELOAD CRITICAL BRANDING -->
<link rel="preload" as="image" href="https://kounselia.com/img/Kounselia_Logo_IconMark_MidnightNavy.png" fetchpriority="high">

<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
<?php require __DIR__ . '/inc/kounselia-styles.php'; ?>
<style>
:root{
  --topbar-h: 64px;
  --tabbar-h: 64px;
}
html,body{height:100%;background:var(--bg)}
body{font-family:'Outfit',sans-serif;color:var(--text);-webkit-font-smoothing:antialiased}

.shell{display:flex;min-height:100vh}

.sidebar{
  width:248px;flex-shrink:0;background:var(--surface);border-right:1px solid var(--border);
  display:flex;flex-direction:column;justify-content:space-between;padding:28px 20px;
  position:sticky;top:0;height:100vh;
}
.logo{padding:0 8px 28px; display: flex; align-items: center;}
.site-logo{max-height:30px; width:auto; object-fit:contain; transition:transform 0.3s ease;}
.logo a:hover .site-logo{transform:scale(1.03);}

.side-nav{display:flex;flex-direction:column;gap:4px}
.nav-link{
  display:flex;align-items:center;gap:12px;padding:11px 14px;border-radius:12px;
  color:var(--text2);text-decoration:none;font-size:14.5px;font-weight:500;cursor:pointer;
  transition:background .2s ease,color .2s ease;border:none;background:none;width:100%;text-align:left;font-family:inherit;
}
.nav-link i{font-size:18px;width:20px;text-align:center}
.nav-link:hover{background:var(--surface2);color:var(--text)}
.nav-link.active{background:var(--accent-light);color:var(--accent)}
.side-foot{display:flex;flex-direction:column;gap:14px}
.side-user{display:flex;align-items:center;gap:10px;padding:10px;border-radius:14px;background:var(--surface2)}
.side-av{width:36px;height:36px;border-radius:50%;background:var(--accent-light);color:var(--accent);display:flex;align-items:center;justify-content:center;font-weight:600;font-size:14px;overflow:hidden;flex-shrink:0}
.side-av img{width:100%;height:100%;object-fit:cover}
.side-user-meta{overflow:hidden}
.side-user-name{font-size:13.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.side-user-plan{font-size:11.5px;color:var(--text3)}
.notif-dot{position:absolute;top:4px;right:4px;width:8px;height:8px;border-radius:50%;background:var(--rose)}
.notif-list{max-height:400px;overflow-y:auto;display:flex;flex-direction:column;gap:8px}
.notif-item{display:block;padding:14px 16px;border-radius:12px;background:var(--bg);border:1px solid var(--border);text-decoration:none;color:inherit}
.notif-item .notif-title{font-size:13.5px;font-weight:600;color:var(--text)}
.notif-item .notif-body{font-size:12.5px;color:var(--text2);margin-top:3px}
.notif-item .notif-time{font-size:11px;color:var(--text3);margin-top:6px}
.signout-btn{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:12px;border:1px solid var(--border);background:none;color:var(--text2);font-family:inherit;font-size:13.5px;font-weight:500;cursor:pointer;transition:all .2s ease}
.signout-btn:hover{border-color:var(--text3);color:var(--text)}

.mobile-topbar{display:none}
.mobile-tabbar{display:none}
.main{flex:1;min-width:0;padding:36px 44px 80px;max-width:1180px}

/* Unified Panel System (Replaces old mob-panel and desk-only) */
.view-panel {
  display: none;
  animation: panelFade .25s ease-out forwards;
}
.view-panel.active {
  display: block;
}
@keyframes panelFade {
  from { opacity: 0; transform: translateY(8px); }
  to { opacity: 1; transform: translateY(0); }
}

.welcome{
  position:relative;overflow:hidden;border-radius:28px;background:linear-gradient(135deg,var(--accent) 0%,var(--navy) 100%);
  padding:40px 36px;color:#fff;margin-bottom:24px;
}
.welcome-orb{
  position:absolute;width:340px;height:340px;border-radius:50%;
  background:radial-gradient(circle,rgba(176,125,58,0.35) 0%,rgba(176,125,58,0) 70%);
  top:-120px;right:-80px;animation:breathe 7s ease-in-out infinite;
}
@keyframes breathe{0%,100%{transform:scale(1);opacity:.7}50%{transform:scale(1.18);opacity:1}}
@media (prefers-reduced-motion: reduce){.welcome-orb{animation:none}}
.welcome-eyebrow{font-size:11px;font-weight:600;letter-spacing:2.5px;text-transform:uppercase;color:rgba(255,255,255,.6);margin-bottom:10px;position:relative}
.welcome h1{font-family:'Cormorant Garamond',serif;font-weight:400;font-size:36px;position:relative;max-width:560px;line-height:1.2}
.welcome h1 em{font-style:italic;color:#E8C896}
.welcome p{position:relative;color:rgba(255,255,255,.78);margin-top:10px;font-size:15px;max-width:480px;font-weight:300;line-height:1.6}
.welcome-actions{position:relative;display:flex;gap:12px;margin-top:24px;flex-wrap:wrap}
.btn-w{padding:13px 22px;border-radius:50px;font-family:'Outfit',sans-serif;font-size:14px;font-weight:500;cursor:pointer;border:none;transition:all .25s ease;text-decoration:none;display:inline-flex;align-items:center;gap:8px}
.btn-w.primary{background:#fff;color:var(--accent)}
.btn-w.primary:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(0,0,0,.18)}
.btn-w.ghost{background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.25)}
.btn-w.ghost:hover{background:rgba(255,255,255,.2)}

.mood-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:24px 26px;box-shadow:var(--shadow-sm);margin-bottom:24px}
.mood-head{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:18px}
.mood-head h3{font-family:'Cormorant Garamond',serif;font-size:19px;font-weight:500}
.mood-saved-tag{font-size:11px;font-weight:600;color:var(--sage);background:var(--sage-light);padding:4px 10px;border-radius:50px;letter-spacing:.3px;text-transform:uppercase;display:flex;align-items:center;gap:4px}
.mood-options{display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin-bottom:20px}
.mood-btn{display:flex;flex-direction:column;align-items:center;gap:7px;padding:13px 10px;border-radius:16px;border:1.5px solid var(--border);background:var(--bg);cursor:pointer;flex:0 1 92px;min-width:80px;max-width:112px;transition:border-color .2s ease,background-color .2s ease,transform .2s ease;}
@media (hover:hover){.mood-btn:hover{border-color:var(--text3);transform:translateY(-2px)}}
.mood-btn.selected{border-color:currentColor;box-shadow:0 0 0 3px rgba(0,0,0,0.04)}
.mood-btn.ic-sage.selected{color:var(--sage);background:var(--sage-light)}
.mood-btn.ic-blue.selected{color:var(--accent);background:var(--accent-light)}
.mood-btn.ic-gold.selected{color:var(--gold);background:var(--gold-light)}
.mood-btn.ic-plum.selected{color:var(--plum);background:var(--plum-light)}
.mood-btn.ic-sienna.selected{color:var(--sienna);background:var(--sienna-light)}
.mood-btn.selected span{color:inherit;font-weight:600}
.mood-rhythm{display:flex;justify-content:space-between;padding-top:16px;border-top:1px solid var(--border)}
.rhythm-day{display:flex;flex-direction:column;align-items:center;gap:7px}
.rhythm-dot{width:14px;height:14px;border-radius:50%;background:var(--surface3);border:1.5px solid var(--border)}
.rhythm-dot.filled{border-color:transparent}
.rhythm-dot.filled.ic-sage{background:var(--sage)}
.rhythm-dot.filled.ic-blue{background:var(--accent)}
.rhythm-dot.filled.ic-gold{background:var(--gold)}
.rhythm-dot.filled.ic-plum{background:var(--plum)}
.rhythm-dot.filled.ic-sienna{background:var(--sienna)}
.rhythm-day span{font-size:10.5px;color:var(--text3);font-weight:500}

.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-sm);padding:22px;box-shadow:var(--shadow-sm)}
.stat-icon{width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:17px;margin-bottom:14px}
.stat-num{font-family:'Cormorant Garamond',serif;font-size:32px;font-weight:500;line-height:1}
.stat-label{font-size:13px;color:var(--text2);margin-top:6px}

.section{margin-bottom:40px}
.section-head{display:flex;align-items:baseline;justify-content:space-between;margin-bottom:18px}
.section-head h2{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:23px}
.section-sub{font-size:13px;color:var(--text3)}

.rec-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:26px;display:flex;align-items:center;gap:20px;box-shadow:var(--shadow-sm);transition:box-shadow .3s ease}
.rec-card:hover{box-shadow:var(--shadow-hover)}
.rec-av{width:58px;height:58px;border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0}
.rec-meta{flex:1;min-width:0}
.rec-meta h3{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:21px;margin-bottom:2px}
.rec-meta p.spec{font-size:13px;color:var(--gold);font-weight:600;letter-spacing:.3px;text-transform:uppercase;margin-bottom:6px}
.rec-meta p.reason{font-size:13.5px;color:var(--text2)}
.btn-rec{padding:11px 20px;border-radius:50px;background:var(--accent);color:#fff;border:none;font-family:inherit;font-size:13.5px;font-weight:500;cursor:pointer;white-space:nowrap;transition:all .2s ease;text-decoration:none;display:inline-block;flex-shrink:0}
.btn-rec:hover{background:var(--accent2);transform:translateY(-1px)}
.btn-rec.secondary{background:none;border:1.5px solid var(--border);color:var(--text2)}
.btn-rec.secondary:hover{background:none;border-color:var(--text3);color:var(--text)}

.counselor-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.counselor-tile{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-sm);padding:18px 14px;text-align:center;text-decoration:none;color:inherit;box-shadow:var(--shadow-sm);transition:all .2s ease}
@media (hover:hover){.counselor-tile:hover{box-shadow:var(--shadow-hover);transform:translateY(-2px);border-color:var(--text3)}}
.tile-av{width:44px;height:44px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:19px;margin:0 auto 10px}
.tile-name{font-family:'Cormorant Garamond',serif;font-size:16px;font-weight:500;margin-bottom:2px}
.tile-spec{font-size:11px;color:var(--text3);line-height:1.3}

.journal-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:24px 26px;box-shadow:var(--shadow-sm)}
.journal-card textarea{width:100%;min-height:120px;border:1.5px solid var(--border);border-radius:14px;padding:14px 16px;font-family:inherit;font-size:14.5px;color:var(--text);background:var(--bg);resize:vertical;outline:none;line-height:1.6;transition:all .2s ease}
.journal-card textarea:focus{border-color:var(--accent);background:var(--surface);box-shadow:0 0 0 4px var(--accent-light)}
.journal-foot{display:flex;align-items:center;justify-content:space-between;margin-top:14px}

.session-row{display:flex;align-items:center;gap:16px;padding:16px 18px;background:var(--surface);border:1px solid var(--border);border-radius:14px;margin-bottom:10px}
.session-av{width:42px;height:42px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.session-meta{flex:1;min-width:0}
.session-meta h4{font-size:14.5px;font-weight:600}
.weekly-tag{display:inline-block;margin-left:8px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:var(--accent);background:var(--accent-light);border-radius:999px;padding:2px 8px;vertical-align:middle}
.session-meta p{font-size:12.5px;color:var(--text3);margin-top:2px}
.session-link{font-size:13px;color:var(--accent);font-weight:500;text-decoration:none;white-space:nowrap}
.session-link:hover{text-decoration:underline}
.booking-session-row .booking-actions{display:flex;align-items:center;gap:14px;flex-shrink:0}
.booking-session-row .booking-join{display:inline-flex;align-items:center;gap:5px;color:var(--sage)}
@media (max-width:480px){.booking-session-row{flex-wrap:wrap}.booking-session-row .booking-actions{flex-basis:100%;margin-top:10px;justify-content:flex-start}}

.chat-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);display:none;align-items:flex-end;justify-content:center;z-index:9999}
.chat-overlay.active{display:flex}
.chat-modal{background:var(--surface);width:100%;max-width:480px;border-radius:20px 20px 0 0;display:flex;flex-direction:column;max-height:80vh}
@media (min-width:640px){.chat-overlay{align-items:center}.chat-modal{border-radius:20px;height:600px}}
.chat-modal-head{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border);flex-shrink:0}
.chat-modal-head h3{font-family:'Cormorant Garamond',serif;font-size:19px;font-weight:500;margin:0}
.chat-modal-head button{background:none;border:none;font-size:20px;color:var(--text3);cursor:pointer;padding:4px}
.chat-messages{flex:1;overflow-y:auto;padding:16px 20px;display:flex;flex-direction:column;gap:10px}
.chat-bubble{max-width:78%;padding:10px 14px;border-radius:14px;font-size:13.5px;line-height:1.5}
.chat-bubble.mine{align-self:flex-end;background:var(--accent);color:#fff;border-bottom-right-radius:4px}
.chat-bubble.theirs{align-self:flex-start;background:var(--surface2);color:var(--text);border-bottom-left-radius:4px}
.chat-bubble-time{font-size:10.5px;opacity:.65;margin-top:4px}
.chat-input-row{display:flex;gap:8px;padding:12px 16px;border-top:1px solid var(--border);flex-shrink:0}
.chat-input-row input{flex:1;padding:11px 14px;border:1.5px solid var(--border);border-radius:24px;font-family:inherit;font-size:14px;background:var(--bg);outline:none}
.chat-input-row input:focus{border-color:var(--accent)}
.chat-input-row button{width:40px;height:40px;border-radius:50%;border:none;background:var(--accent);color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.empty-state{background:var(--surface2);border:1px dashed var(--border);border-radius:var(--r);padding:36px 24px;text-align:center}
.empty-state i{font-size:26px;color:var(--text3);margin-bottom:10px;display:block}
.empty-state p{font-size:14px;color:var(--text2);margin-bottom:16px}

.settings-grid{display:grid;grid-template-columns:280px 1fr;gap:28px}
.avatar-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:28px 22px;text-align:center;box-shadow:var(--shadow-sm);height:fit-content}
.avatar-wrap{position:relative;width:104px;height:104px;margin:0 auto 16px}
.avatar-img{width:104px;height:104px;border-radius:50%;background:var(--accent-light);color:var(--accent);display:flex;align-items:center;justify-content:center;font-size:38px;font-weight:600;overflow:hidden;border:3px solid var(--surface);box-shadow:var(--shadow-md)}
.avatar-img img{width:100%;height:100%;object-fit:cover}
.avatar-edit{position:absolute;bottom:0;right:0;width:32px;height:32px;border-radius:50%;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;border:3px solid var(--surface);cursor:pointer;font-size:14px}
.avatar-edit input{display:none}
.avatar-card h4{font-size:15px;font-weight:600;margin-bottom:2px}
.avatar-card p{font-size:12.5px;color:var(--text3)}
.settings-stack{display:flex;flex-direction:column;gap:20px}
.settings-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:24px;box-shadow:var(--shadow-sm)}
.settings-card h4{font-size:15px;font-weight:600;margin-bottom:16px}
.form-field{margin-bottom:14px}
.form-field label{display:block;font-size:12.5px;font-weight:500;color:var(--text2);margin-bottom:6px}
.form-field input{width:100%;padding:12px 14px;border:1.5px solid var(--border);border-radius:12px;font-family:inherit;font-size:14.5px;color:var(--text);background:var(--bg);outline:none;transition:all .2s ease}
.form-field input:focus{border-color:var(--accent);background:var(--surface);box-shadow:0 0 0 4px var(--accent-light)}
.btn-save{padding:11px 20px;border-radius:50px;background:var(--accent);color:#fff;border:none;font-family:inherit;font-size:13.5px;font-weight:500;cursor:pointer;transition:all .2s ease}
.btn-save:hover{background:var(--accent2)}
.btn-save:disabled{opacity:.6;cursor:default}
.inline-msg{font-size:12.5px;margin-top:10px;display:none}
.email-pref{display:flex;gap:12px;align-items:flex-start;padding:10px 0;cursor:pointer;font-size:14px}
.email-pref input{width:18px;height:18px;accent-color:var(--accent);margin-top:2px;flex-shrink:0}
.email-pref b{display:block;font-weight:500;color:var(--text)}
.email-pref small{display:block;color:var(--text3);font-size:12.5px;margin-top:2px;line-height:1.45}
.email-pref-note{font-size:12px;color:var(--text3);margin:6px 0 14px}
.inline-msg.ok{color:var(--sage)}
.inline-msg.err{color:var(--rose)}

.memory-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:24px;box-shadow:var(--shadow-sm)}
.memory-card h4{font-size:15px;font-weight:600;margin-bottom:6px}
.memory-card .sub{font-size:13px;color:var(--text2);line-height:1.6;margin-bottom:20px}
.memory-steps{display:flex;flex-direction:column;gap:20px}
.memory-step{display:flex;gap:14px}
.step-num{width:26px;height:26px;border-radius:50%;background:var(--accent-light);color:var(--accent);font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px}
.step-body{flex:1}
.step-body h5{font-size:13.5px;font-weight:600;margin-bottom:6px}
.step-body p{font-size:13px;color:var(--text2);line-height:1.6;margin-bottom:10px}
.prompt-box{background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:14px 16px;font-size:13px;color:var(--text2);line-height:1.65;font-style:italic}
.btn-copy{display:inline-flex;align-items:center;gap:6px;margin-top:10px;padding:8px 16px;border-radius:50px;border:1.5px solid var(--border);background:var(--surface);font-family:inherit;font-size:13px;font-weight:500;cursor:pointer;color:var(--text2);transition:all .2s ease}
.btn-copy:hover{border-color:var(--accent);color:var(--accent)}
.btn-copy.copied{border-color:var(--sage);color:var(--sage)}
.memory-paste{width:100%;min-height:110px;border:1.5px solid var(--border);border-radius:14px;padding:14px 16px;font-family:inherit;font-size:13.5px;color:var(--text);background:var(--bg);resize:vertical;outline:none;line-height:1.6;transition:all .2s ease}
.memory-paste:focus{border-color:var(--accent);background:var(--surface);box-shadow:0 0 0 4px var(--accent-light)}
.memory-paste-foot{display:flex;align-items:center;justify-content:space-between;margin-top:12px;flex-wrap:wrap;gap:10px}
.btn-process{padding:11px 22px;border-radius:50px;background:var(--accent);color:#fff;border:none;font-family:inherit;font-size:13.5px;font-weight:500;cursor:pointer;transition:all .2s ease;display:inline-flex;align-items:center;gap:8px}
.btn-process:hover{background:var(--accent2)}
.btn-process:disabled{opacity:.55;cursor:default}
.memory-processing{display:flex;align-items:center;gap:10px;font-size:13.5px;color:var(--text2);padding:14px 0}
.memory-processing i{font-size:18px;color:var(--accent);animation:memSpin 1s linear infinite}
@keyframes memSpin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}

.memory-preview{background:var(--surface2);border:1px solid var(--border);border-radius:14px;padding:0;margin-top:18px;overflow:hidden;}
.memory-preview-head{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border);background:var(--surface);}
.memory-preview-head h5{font-size:14px;font-weight:600;color:var(--text);text-transform:uppercase;letter-spacing:.8px;margin:0;}
.memory-preview-date{font-size:11.5px;color:var(--text3);}

.profile-layout { display: flex; flex-direction: column; }
@media (min-width: 768px) { .profile-layout { flex-direction: row; } }
.profile-sidebar { width: 100%; border-bottom: 1px solid var(--border); padding: 20px; background: var(--surface); }
@media (min-width: 768px) { .profile-sidebar { width: 280px; border-bottom: none; border-right: 1px solid var(--border); flex-shrink: 0; } }
.profile-main { flex: 1; padding: 20px; min-width: 0; }

.mem-block { margin-bottom: 20px; }
.mem-block:last-child { margin-bottom: 0; }
.mem-label { font-size: 11px; font-weight: 600; text-transform: uppercase; color: var(--text3); letter-spacing: 1px; margin-bottom: 8px; }
.mem-text { font-size: 14px; color: var(--text2); line-height: 1.6; word-wrap: break-word; }

/* Timeline UI */
.mem-timeline { position: relative; padding-left: 14px; border-left: 2px solid var(--border); margin-top: 10px; }
.timeline-item { position: relative; margin-bottom: 16px; }
.timeline-item:last-child { margin-bottom: 0; }
.timeline-item::before { content: ''; position: absolute; left: -21px; top: 4px; width: 10px; height: 10px; border-radius: 50%; background: var(--accent); border: 2px solid var(--surface); }
.tl-year { font-size: 12px; font-weight: 600; color: var(--accent); margin-bottom: 2px; }
.tl-event { font-size: 14px; color: var(--text); font-weight: 500; }
.tl-impact { font-size: 13px; color: var(--text2); line-height: 1.5; margin-top: 2px; }

/* Emotional Map UI */
.emotion-grid { display: grid; grid-template-columns: 1fr; gap: 12px; margin-top: 10px; }
@media (min-width: 600px) { .emotion-grid { grid-template-columns: 1fr 1fr; } }
.emotion-card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 14px; box-shadow: 0 2px 6px rgba(0,0,0,0.02); }
.emo-head { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 6px; }
.emo-entity { font-weight: 600; font-size: 14px; color: var(--text); }
.emo-badge { font-size: 10px; font-weight: 600; padding: 2px 8px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.5px; }
.badge-High { background: var(--rose-light); color: var(--rose); }
.badge-Medium { background: var(--gold-light); color: var(--gold); }
.badge-Low { background: var(--sage-light); color: var(--sage); }
.emo-feeling { font-size: 13px; color: var(--accent); font-weight: 500; margin-bottom: 4px; }
.emo-context { font-size: 13px; color: var(--text2); line-height: 1.5; }

/* Tags/Chips UI */
.chip-list { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
.chip { background: var(--bg); border: 1px solid var(--border); padding: 4px 10px; border-radius: 6px; font-size: 12.5px; color: var(--text2); }

.active-state-box { margin-top: 20px; padding: 14px; background: var(--accent-light); border-radius: 12px; border: 1px solid #C8D8EC; }
.active-state-box .mem-label { color: var(--accent); margin-bottom: 4px; display: flex; align-items: center; gap: 6px; }
.active-state-box .mem-text { color: var(--accent); font-weight: 500; }

.foot-actions { padding: 16px 20px; border-top: 1px solid var(--border); background: var(--surface); display: flex; justify-content: flex-end; }
.btn-delete-memory{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:50px;border:1.5px solid #E8C8C8;background:none;font-family:inherit;font-size:12.5px;font-weight:500;cursor:pointer;color:var(--rose);transition:all .2s ease}
.btn-delete-memory:hover{background:var(--rose-light)}

.insight-wrapper { display:none; margin-top:24px; animation: fadeIn 0.5s ease; }
.insight-header { text-align:center; margin-bottom:28px; }
.insight-header h3 { font-family:'Cormorant Garamond',serif; font-size:26px; color:var(--text); margin-bottom:6px; }
.insight-header p { font-size:14px; color:var(--text3); }
.insight-grid { display:grid; grid-template-columns:1fr; gap:16px; }
@media(min-width:768px) { .insight-grid { grid-template-columns:1fr 1fr; } }
.insight-box { background:var(--surface); border:1px solid var(--border); border-radius:16px; padding:20px; transition: transform 0.2s ease; box-shadow: var(--shadow-sm); }
.insight-box:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.insight-box h4 { font-size:13.5px; font-weight:600; text-transform:uppercase; letter-spacing:1px; margin-bottom:14px; display:flex; align-items:center; gap:8px; margin-top:0; }
.insight-box h4 i { font-size:18px; }
.insight-list { list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:12px; }
.insight-list li { font-size:14.5px; color:var(--text2); line-height:1.6; position:relative; padding-left:22px; }
.insight-list li::before { content:''; position:absolute; left:0; top:8px; width:6px; height:6px; border-radius:50%; }

.ib-patterns h4 { color:var(--accent); } .ib-patterns li::before { background:var(--accent); }
.ib-growth h4 { color:var(--sage); }     .ib-growth li::before { background:var(--sage); }
.ib-fears h4 { color:var(--rose); }      .ib-fears li::before { background:var(--rose); }
.ib-blind h4 { color:var(--plum); }      .ib-blind li::before { background:var(--plum); }
.ib-wins h4 { color:var(--gold); }       .ib-wins li::before { background:var(--gold); }
.ib-recs h4 { color:var(--teal); }       .ib-recs li::before { background:var(--teal); }

.generate-insight-btn { width:100%; padding:16px; border-radius:16px; border:2px dashed var(--border); background:var(--surface2); color:var(--text2); font-family:inherit; font-size:15px; font-weight:500; cursor:pointer; transition:all 0.2s ease; display:flex; flex-direction:column; align-items:center; gap:8px; }
.generate-insight-btn i { font-size:24px; color:var(--accent); }
.generate-insight-btn:hover { border-color:var(--accent); background:var(--surface); color:var(--accent); }
.generate-insight-btn:disabled { opacity:0.6; cursor:wait; }

.sub-status-card{display:flex;align-items:center;gap:16px;background:var(--sage-light);border:1px solid rgba(46,92,62,0.18);border-radius:var(--r);padding:20px 24px;margin-bottom:20px}
.sub-status-card.ending{background:var(--gold-light);border-color:rgba(176,125,58,0.25)}
.sub-status-icon{width:44px;height:44px;border-radius:50%;background:#fff;color:var(--sage);display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.sub-status-card.ending .sub-status-icon{color:var(--gold)}
.sub-status-meta{flex:1;min-width:0}
.sub-status-meta h4{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:18px;margin-bottom:2px}
.sub-status-meta p{font-size:13px;color:var(--text2)}
.sub-cancel-btn{padding:10px 18px;border-radius:50px;border:1.5px solid rgba(0,0,0,0.12);background:#fff;color:var(--text2);font-family:inherit;font-size:13px;font-weight:500;cursor:pointer;white-space:nowrap}
.sub-cancel-btn:hover{border-color:var(--text3);color:var(--text)}
.plans-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
/* Care team card (Home) */
.care-card{display:flex;align-items:center;gap:18px;background:linear-gradient(135deg,var(--surface) 0%,var(--gold-light) 140%);border:1px solid var(--border);border-radius:var(--r);padding:20px 22px;margin-bottom:20px;box-shadow:var(--shadow-sm)}
.care-card.has-next{background:linear-gradient(135deg,var(--accent-light) 0%,var(--surface) 70%);border-color:rgba(30,58,95,.14)}
.care-date{width:58px;height:62px;border-radius:16px;background:var(--surface);border:1px solid var(--border);display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0;box-shadow:var(--shadow-sm)}
.care-date-m{font-size:11px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:var(--rose)}
.care-date-d{font-family:'Cormorant Garamond',serif;font-size:28px;line-height:1;color:var(--text)}
.care-meta{flex:1;min-width:0}
.care-eyebrow{font-size:11px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--gold);display:flex;align-items:center;gap:5px;margin-bottom:4px}
.care-card h3{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:21px;line-height:1.2;margin:0 0 3px}
.care-card p{font-size:13.5px;color:var(--text2);line-height:1.5;margin:0}
.care-perk{color:var(--gold);font-weight:500}
.care-actions{display:flex;gap:8px;flex-shrink:0}
.care-stack{display:flex;flex-shrink:0}
.care-av{width:42px;height:42px;border-radius:50%;background:var(--accent-light);color:var(--accent);border:2.5px solid var(--surface);display:flex;align-items:center;justify-content:center;font-weight:600;font-size:15px;overflow:hidden;margin-left:-12px}
.care-av:first-child{margin-left:0}
.care-av img{width:100%;height:100%;object-fit:cover}
.care-av.icon{font-size:20px;background:var(--gold-light);color:var(--gold)}
.talk-human{width:100%;display:flex;align-items:center;gap:14px;text-align:left;background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:18px 20px;cursor:pointer;font-family:inherit;color:var(--text);box-shadow:var(--shadow-sm)}
.talk-human-icon{width:44px;height:44px;border-radius:14px;background:var(--gold-light);color:var(--gold);display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
.talk-human-copy{flex:1}
.talk-human-copy b{display:block;font-weight:600;font-size:15px}
.talk-human-copy small{display:block;font-size:13px;color:var(--text2);margin-top:2px}
.talk-human > .ti-chevron-right{color:var(--text3);font-size:20px}
.reflection-allowance{font-size:12.5px;color:var(--text3);text-align:center;margin-top:10px}
.reflection-allowance a{color:var(--gold);font-weight:500}
/* My plan */
.plan-hero{display:flex;align-items:center;gap:18px;border-radius:var(--r);padding:22px 24px;margin-bottom:18px;border:1px solid var(--border);background:var(--surface)}
.plan-hero.state-active,.plan-hero.state-gifted{background:linear-gradient(135deg,var(--gold-light),var(--surface) 80%);border-color:rgba(176,125,58,.28)}
.plan-hero.state-renewal_off{background:var(--surface2)}
.plan-hero.state-payment_problem{background:var(--rose-light);border-color:rgba(139,58,82,.25)}
.plan-hero-icon{width:50px;height:50px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;font-size:23px;color:var(--gold);flex-shrink:0;box-shadow:var(--shadow-sm)}
.state-none .plan-hero-icon{color:var(--sage)}
.state-payment_problem .plan-hero-icon{color:var(--rose)}
.plan-hero-meta{flex:1;min-width:0}
.plan-hero-meta h3{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:24px;line-height:1.2;margin:0 0 4px}
.plan-hero-meta p{font-size:14px;color:var(--text2);line-height:1.55;margin:0}
.plan-hero-actions{flex-shrink:0}
.billing-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
.billing-box{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-sm);padding:18px}
.billing-label{font-size:11px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:var(--text3);margin-bottom:8px}
.billing-card{display:flex;align-items:center;gap:8px;font-weight:500;font-size:15px;flex-wrap:wrap}
.billing-card i{font-size:20px;color:var(--accent)}
.billing-card span{font-size:12.5px;color:var(--text3);font-weight:400;width:100%;padding-left:28px}
.billing-card.muted,.billing-big.muted{color:var(--text3)}
.billing-big{font-family:'Cormorant Garamond',serif;font-size:28px;line-height:1.1}
.billing-sub{font-size:13px;color:var(--text3);margin-top:2px}
.link-btn{background:none;border:none;color:var(--rose);font-family:inherit;font-size:12.5px;cursor:pointer;padding:0;margin-top:10px;text-decoration:underline}
.switch-box{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-sm);padding:18px}
.switch-box label{display:block;font-size:13px;font-weight:500;margin-bottom:8px}
.switch-row{display:flex;gap:8px}
.switch-row select{flex:1;padding:11px 12px;border:1px solid var(--border);border-radius:12px;font-family:inherit;font-size:14px;background:var(--bg);min-width:0}
.switch-row .btn-plan{width:auto;padding:11px 20px;margin:0}
.switch-box p{font-size:12.5px;color:var(--text3);margin-top:8px}
.compare{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);overflow:hidden}
.compare-row{display:grid;grid-template-columns:1fr 110px 110px;align-items:center;border-bottom:1px solid var(--border);font-size:14px}
.compare-row:last-child{border-bottom:none}
.compare-row > div{padding:13px 14px;text-align:center;color:var(--text2)}
.compare-row > div.compare-label,.compare-row > div:first-child{text-align:left;color:var(--text)}
.compare-row .you{background:rgba(176,125,58,.07)}
.compare-head > div{font-weight:600;color:var(--text);font-size:14px}
.compare-head .pro{color:var(--gold)}
.compare-head small{display:block;font-size:10px;letter-spacing:1px;text-transform:uppercase;color:var(--gold);font-weight:600}
.compare .ti-check{color:var(--sage);font-size:18px}
.compare .no{color:var(--text3)}
.usage-note{font-size:13px;color:var(--text2);margin-top:12px;display:flex;gap:6px;align-items:flex-start;line-height:1.5}
.usage-note i{color:var(--gold);font-size:16px;margin-top:1px}
.bill-list{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);overflow:hidden}
.bill-row{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 18px;border-bottom:1px solid var(--border)}
.bill-row:last-child{border-bottom:none}
.bill-title{font-weight:500;font-size:14px}
.bill-sub{font-size:12px;color:var(--text3);margin-top:2px}
.bill-amt{font-weight:600;font-size:14.5px;text-align:right;display:flex;align-items:center;gap:10px}
.bill-status{font-size:10.5px;font-weight:600;padding:3px 9px;border-radius:50px;text-transform:uppercase;letter-spacing:.5px}
.bill-status.success{background:var(--sage-light);color:var(--sage)}
.bill-status.failed{background:var(--rose-light);color:var(--rose)}
@media (max-width:640px){
  .care-card{flex-wrap:wrap;padding:18px}
  .care-actions{width:100%}
  .care-actions .btn-rec{flex:1;text-align:center}
  .plan-hero{flex-wrap:wrap}
  .plan-hero-actions{width:100%}
  .plan-hero-actions button{width:100%}
  .billing-grid{grid-template-columns:1fr}
  .compare-row{grid-template-columns:1fr 84px 84px;font-size:13px}
  .compare-row > div{padding:11px 8px}
}
.plan-card{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--r);padding:26px}
.plan-card.pro{border-color:var(--gold);background:linear-gradient(180deg,var(--gold-light) 0%,var(--surface) 30%);position:relative}
.plan-badge{position:absolute;top:-11px;right:22px;background:var(--gold);color:#fff;font-size:10.5px;font-weight:600;letter-spacing:1px;text-transform:uppercase;padding:4px 12px;border-radius:50px}
.plan-card h3{font-family:'Cormorant Garamond',serif;font-size:22px;font-weight:500;margin-bottom:4px;margin-top:0}
.plan-price{font-size:13.5px;color:var(--text3);margin-bottom:18px}
.plan-list{list-style:none;display:flex;flex-direction:column;gap:10px;margin-bottom:22px;padding:0;}
.plan-list li{font-size:13.5px;color:var(--text2);display:flex;gap:8px;align-items:flex-start}
.plan-list li i{color:var(--sage);margin-top:2px;flex-shrink:0}
.btn-plan{width:100%;padding:12px;border-radius:50px;font-family:inherit;font-size:13.5px;font-weight:500;cursor:pointer;border:1.5px solid var(--border);background:none;color:var(--text2)}
.btn-plan.primary{background:var(--gold);color:#fff;border:none}
.btn-plan.primary:hover{background:#9a6a2f}
.btn-plan:disabled{cursor:default}

#toast-wrap{position:fixed;bottom:24px;right:24px;display:flex;flex-direction:column;gap:10px;z-index:999}
.toast{background:var(--text);color:#fff;padding:14px 18px;border-radius:14px;font-size:13.5px;box-shadow:var(--shadow-hover);max-width:300px;animation:toastIn .3s ease}
.toast.err{background:var(--rose)}
@keyframes toastIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
@media (max-width:900px){#toast-wrap{bottom:calc(var(--tabbar-h) + 16px);left:16px;right:16px}.toast{max-width:none}}

@media (max-width:900px){
  .shell{display:block}
  .sidebar{display:none}
  .main{padding:0;overflow:hidden}

  .mobile-topbar{
    display:flex;align-items:center;justify-content:space-between;
    position:fixed;top:0;left:0;right:0;
    height:var(--topbar-h);
    padding:0 18px;
    background:rgba(255,255,255,.95);
    backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);
    border-bottom:1px solid var(--border);z-index:100;
  }
  .mobile-topbar-logo{display:flex;align-items:center;text-decoration:none;outline:none;}
  .mobile-topbar-right{display:flex;align-items:center;gap:10px}
  .mobile-av{
    width:34px;height:34px;border-radius:50%;
    background:var(--accent-light);color:var(--accent);
    display:flex;align-items:center;justify-content:center;
    font-weight:600;font-size:13px;overflow:hidden;text-decoration:none;border:none;
  }
  .mobile-av img{width:100%;height:100%;object-fit:cover}
  .mobile-signout{
    width:34px;height:34px;border-radius:50%;
    border:1px solid var(--border);background:var(--surface2);
    color:var(--text2);display:flex;align-items:center;
    justify-content:center;font-size:16px;cursor:pointer;
  }

  .view-panel{
    position:fixed;
    top:var(--topbar-h);
    left:0;right:0;
    bottom:calc(var(--tabbar-h) + env(safe-area-inset-bottom));
    overflow-y:auto;
    -webkit-overflow-scrolling:touch;
    background:var(--bg);
    padding:20px 16px 24px;
  }

  .view-panel .section{margin-bottom:28px}
  .view-panel .welcome{margin-bottom:20px}
  .view-panel .mood-card{margin-bottom:20px}
  .view-panel .stats-row{margin-bottom:20px}

  .stats-row{grid-template-columns:1fr 1fr;gap:10px}
  .counselor-grid{grid-template-columns:repeat(2,1fr)}
  .settings-grid{grid-template-columns:1fr}
  .plans-grid{grid-template-columns:1fr}
  .welcome{padding:28px 22px}
  .welcome h1{font-size:26px}
  .welcome-actions .btn-w{width:100%;justify-content:center}
  .rec-card{flex-direction:column;align-items:flex-start;gap:14px}
  .rec-card .btn-rec{width:100%;text-align:center;white-space:normal}
  .mood-options{gap:8px}
  .mood-btn{flex:0 1 calc(33.333% - 6px);min-width:0;max-width:120px;padding:12px 6px}

  .mobile-tabbar{
    display:flex;align-items:center;justify-content:space-evenly;
    position:fixed;bottom:0;left:0;right:0;
    height:calc(var(--tabbar-h) + env(safe-area-inset-bottom));
    padding-bottom:env(safe-area-inset-bottom);
    background:rgba(255,255,255,.97);
    backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);
    border-top:1px solid var(--border);
    box-shadow:0 -4px 20px rgba(0,0,0,.05);
    z-index:100;
  }
  .mob-tab{
    display:flex;flex-direction:column;align-items:center;gap:3px;
    width:56px;padding:6px 0;border:none;background:none;
    color:var(--text3);cursor:pointer;font-family:inherit;
    transition:color .2s ease;
  }
  .mob-tab i{font-size:22px}
  .mob-tab span{font-size:10px;font-weight:500}
  .mob-tab.active{color:var(--accent)}
  .tabbar-fab{
    width:52px;height:52px;border-radius:50%;margin-top:-22px;
    background:linear-gradient(135deg,var(--accent) 0%,var(--accent2) 100%);
    color:#fff;display:flex;align-items:center;justify-content:center;
    font-size:22px;box-shadow:0 6px 18px rgba(30,58,95,.35);
    border:4px solid var(--bg);text-decoration:none;flex-shrink:0;
    cursor:pointer;font-family:inherit;
    transition:transform .2s ease;
  }
  .tabbar-fab:active{transform:scale(.94)}
}

/* INTAKE MODAL STYLES */
.intake-overlay{position:fixed;inset:0;background:rgba(24, 22, 15, 0.8);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);display:flex;align-items:center;justify-content:center;z-index:9999;opacity:0;pointer-events:none;transition:opacity 0.5s ease;}
.intake-overlay.active{opacity:1;pointer-events:auto;}
.intake-modal{background:var(--bg);width:100%;max-width:540px;border-radius:28px;padding:44px;box-shadow:0 20px 50px rgba(0,0,0,0.3);transform:translateY(30px);transition:transform 0.5s cubic-bezier(0.16,1,0.3,1);}
.intake-overlay.active .intake-modal{transform:translateY(0);}
.intake-step{display:none;flex-direction:column;animation:panelFade 0.5s ease;}
.intake-step.active{display:flex;}
.intake-step h2{font-family:'Cormorant Garamond',serif;font-size:36px;color:var(--text);margin-bottom:12px;line-height:1.2;font-weight:400;}
.intake-step p{font-size:16px;color:var(--text2);margin-bottom:28px;line-height:1.7;font-weight:300;}
.intake-step textarea{width:100%;min-height:140px;border:1.5px solid var(--border);border-radius:16px;padding:16px 20px;font-family:'Outfit',sans-serif;font-size:15px;color:var(--text);background:var(--surface);resize:none;outline:none;line-height:1.6;transition:all 0.3s ease;margin-bottom:28px;box-shadow:inset 0 2px 4px rgba(0,0,0,0.01);}
.intake-step textarea:focus{border-color:var(--accent);box-shadow:0 0 0 4px var(--accent-light);}
.intake-actions{display:flex;justify-content:flex-end;gap:12px;}
.intake-btn{padding:14px 28px;border-radius:50px;background:linear-gradient(135deg, var(--accent) 0%, var(--accent2) 100%);color:#fff;border:none;font-family:'Outfit',sans-serif;font-size:15px;font-weight:500;cursor:pointer;transition:all 0.3s ease;box-shadow:var(--shadow-btn);}
.intake-btn:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(30,58,95,0.35);}
.intake-btn:disabled{opacity:0.7;cursor:wait;}
.intake-btn.ghost{background:none;border:1.5px solid var(--border);color:var(--text);box-shadow:none;}
.intake-btn.ghost:hover{background:var(--surface);border-color:var(--text3);transform:translateY(-2px);}
.intake-progress{display:flex;gap:8px;margin-bottom:36px;justify-content:center;}
.intake-dot{width:10px;height:10px;border-radius:50%;background:var(--border);transition:background 0.3s ease;}
.intake-dot.active{background:var(--accent);}
@media (max-width:600px){
  .intake-modal{border-radius:28px 28px 0 0;align-items:flex-end;padding:36px 24px;max-height:92vh;overflow-y:auto;transform:translateY(100%);}
  .intake-overlay.active .intake-modal{transform:translateY(0);}
}
</style>
</head>
<body>

<div class="shell">

  <aside class="sidebar">
    <div>
      <div class="logo" style="display:flex;align-items:center;justify-content:space-between">
        <a href="/dashboard.php" class="logo-link" style="display:inline-block; outline:none;">
          <img src="https://kounselia.com/img/Kounselia_Logo_IconMark_MidnightNavy.png" alt="Kounselia" class="site-logo" fetchpriority="high">
        </a>
        <button id="notif-bell-desktop" onclick="openNotifications()" aria-label="Notifications" style="position:relative;background:none;border:none;cursor:pointer;color:var(--text2);padding:6px"><i class="ti ti-bell" style="font-size:19px"></i><span class="notif-dot" id="notif-dot-desktop" style="display:none"></span></button>
      </div>
      <nav class="side-nav">
        <button class="nav-link js-nav active" id="desk-tab-home" onclick="switchTab('home')"><i class="ti ti-home"></i><span>Home</span></button>
        <button class="nav-link js-nav" id="desk-tab-sessions" onclick="switchTab('sessions')"><i class="ti ti-history"></i><span>Sessions</span></button>
        <button class="nav-link js-nav" id="desk-tab-professionals" onclick="switchTab('professionals')"><i class="ti ti-calendar-event"></i><span>Book a professional</span></button>
        <button class="nav-link js-nav" id="desk-tab-memory" onclick="switchTab('memory')"><i class="ti ti-brain"></i><span>Memory Profile</span></button>
        <button class="nav-link js-nav" id="desk-tab-settings" onclick="switchTab('settings')"><i class="ti ti-settings"></i><span>Settings</span></button>
        <button class="nav-link js-nav" id="desk-tab-upgrade" onclick="switchTab('upgrade')"><i class="ti ti-sparkles"></i><span>My plan</span></button>
        <a class="nav-link" href="/talk.php" style="margin-top:16px;"><i class="ti ti-message-2-plus"></i><span>Talk to someone</span></a>
      </nav>
    </div>
    <div class="side-foot">
      <div class="side-user">
        <div class="side-av" id="side-av"><?php echo $avatar_url ? '<img src="' . esc_url( $avatar_url ) . '" alt="">' : esc_html( $initial ); ?></div>
        <div class="side-user-meta">
          <div class="side-user-name"><?php echo esc_html( $display_name ); ?></div>
          <div class="side-user-plan"><?php echo ( function_exists( 'kounselia_member_is_pro' ) && kounselia_member_is_pro( $user->ID ) ) ? 'Pro member' : 'Free plan'; ?></div>
        </div>
      </div>
      <button class="signout-btn" onclick="signOut()"><i class="ti ti-logout"></i> Sign out</button>
    </div>
  </aside>

  <!-- MOBILE TOP BAR -->
  <header class="mobile-topbar">
    <a href="/dashboard.php" class="mobile-topbar-logo" style="display:flex; align-items:center; outline:none; text-decoration:none;">
      <img src="https://kounselia.com/img/Kounselia_Logo_IconMark_MidnightNavy.png" alt="Kounselia" style="max-height:24px; width:auto; object-fit:contain;" fetchpriority="high">
    </a>
    <div class="mobile-topbar-right">
      <button class="mobile-signout" onclick="switchTab('professionals')" aria-label="Sessions with professionals" style="position:relative"><i class="ti ti-calendar-event"></i><?php if ( ! empty( $my_bookings ) ) : ?><span class="notif-dot" style="background:var(--gold)"></span><?php endif; ?></button>
      <button class="mobile-signout" id="notif-bell" onclick="openNotifications()" aria-label="Notifications" style="position:relative"><i class="ti ti-bell"></i><span class="notif-dot" id="notif-dot" style="display:none"></span></button>
      <button class="mobile-av" id="mobile-av" onclick="switchTab('settings')"><?php echo $avatar_url ? '<img src="' . esc_url( $avatar_url ) . '" alt="">' : esc_html( $initial ); ?></button>
      <button class="mobile-signout" onclick="signOut()" aria-label="Sign out"><i class="ti ti-logout"></i></button>
    </div>
  </header>

  <main class="main">

  <!-- HOME PANEL -->
  <div class="view-panel active" id="view-home">

    <section class="welcome">
      <div class="welcome-orb"></div>
      <div class="welcome-eyebrow">Your space</div>
      <h1><?php echo esc_html( $greeting ); ?> <em><?php echo esc_html( $first_name ); ?></em>.</h1>
      <p>This is where everything you bring to Kounselia lives, your conversations, your people, your pace. Nothing here is urgent. Come back whenever you need to.</p>
      <div class="welcome-actions">
        <a class="btn-w primary" href="/talk.php"><i class="ti ti-message-2-plus"></i> Talk to someone now</a>
        <button class="btn-w ghost" onclick="switchTab('sessions')"><i class="ti ti-history"></i> View your sessions</button>
      </div>
    </section>

    <?php if ( $pro_application ) : ?>
    <section class="rec-card" id="pro-status-card" style="margin-bottom:20px;">
      <div class="rec-av ic-gold"><i class="ti <?php
        echo 'verified' === $pro_application->status ? 'ti-check' : ( 'rejected' === $pro_application->status ? 'ti-x' : 'ti-clock' );
      ?>"></i></div>
      <div class="rec-meta">
        <h3>You're viewing your client dashboard</h3>
        <?php if ( 'pending' === $pro_application->status ) : ?>
          <p class="reason">Your professional application is under review — we'll email you once there's a decision.</p>
        <?php elseif ( 'verified' === $pro_application->status ) : ?>
          <p class="reason">Your professional profile is verified and live for clients.</p>
        <?php else : ?>
          <p class="reason">Your professional application wasn't approved<?php echo $pro_application->rejection_reason ? ' — ' . esc_html( $pro_application->rejection_reason ) : ''; ?>. You can update your documents and reapply.</p>
        <?php endif; ?>
      </div>
      <a class="btn-rec secondary" href="/pro-dashboard.php"><i class="ti ti-switch-horizontal" style="margin-right:6px;"></i>Switch back</a>
    </section>
    <?php endif; ?>

    <?php if ( $next_checkin ) :
      $checkin_slug      = ! empty( $tried_slugs ) ? $tried_slugs[0] : $recommended_slug;
      $checkin_counselor = isset( $counselors[ $checkin_slug ] ) ? $counselors[ $checkin_slug ] : $recommended;
    ?>
    <section class="rec-card" id="checkin-card" style="margin-bottom:20px;">
      <div class="rec-av <?php echo esc_attr( $checkin_counselor['class'] ); ?>"><i class="ti <?php echo esc_attr( $checkin_counselor['icon'] ); ?>"></i></div>
      <div class="rec-meta">
        <h3><?php echo esc_html( $checkin_counselor['name'] ); ?> wants to check in</h3>
        <p class="reason">You mentioned "<?php echo esc_html( $next_checkin->event_text ); ?>" — how did it go?</p>
      </div>
      <div style="display:flex;flex-direction:column;gap:8px;align-items:flex-end;">
        <a class="btn-rec" href="/talk.php?checkin=<?php echo (int) $next_checkin->id; ?>#<?php echo esc_attr( $checkin_slug ); ?>">Tell them</a>
        <button type="button" onclick="dismissCheckin(<?php echo (int) $next_checkin->id; ?>)" style="background:none;border:none;color:var(--text3,#8a8578);font-size:12px;cursor:pointer;font-family:inherit;padding:2px;">Not now</button>
      </div>
    </section>
    <?php endif; ?>

    <?php require __DIR__ . '/inc/dashboard-care-team.php'; ?>

    <section class="mood-card">
      <div class="mood-head">
        <h3>How are you feeling today?</h3>
        <span class="mood-saved-tag" id="mood-saved-tag" style="<?php echo $today_mood ? '' : 'display:none'; ?>"><i class="ti ti-check"></i> Saved</span>
      </div>
      <div class="mood-options" id="mood-options">
        <?php foreach ( $mood_options as $key => $m ) : ?>
        <button class="mood-btn <?php echo esc_attr( $m['class'] ); ?> <?php echo ( $today_mood === $key ) ? 'selected' : ''; ?>" data-mood="<?php echo esc_attr( $key ); ?>" onclick="saveMood('<?php echo esc_js( $key ); ?>')">
          <i class="ti <?php echo esc_attr( $m['icon'] ); ?>"></i>
          <span><?php echo esc_html( $m['label'] ); ?></span>
        </button>
        <?php endforeach; ?>
      </div>
      <div class="mood-rhythm" id="mood-rhythm">
        <?php foreach ( $recent_moods as $day ) :
            $is_filled  = ! empty( $day['mood'] ) && isset( $mood_options[ $day['mood'] ] );
            $dot_class  = $is_filled ? esc_attr( $mood_options[ $day['mood'] ]['class'] ) : '';
            $day_letter = mb_substr( date_i18n( 'D', strtotime( $day['date'] ) ), 0, 1 );
        ?>
        <div class="rhythm-day">
          <div class="rhythm-dot <?php echo $dot_class; ?> <?php echo $is_filled ? 'filled' : ''; ?>"></div>
          <span><?php echo esc_html( $day_letter ); ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="stats-row">
      <div class="stat-card">
        <div class="stat-icon ic-blue"><i class="ti ti-message-circle"></i></div>
        <div class="stat-num"><?php echo (int) $stats['total_sessions']; ?></div>
        <div class="stat-label">Conversations</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon ic-sage"><i class="ti ti-calendar-week"></i></div>
        <div class="stat-num"><?php echo (int) $stats['messages_this_week']; ?></div>
        <div class="stat-label">Messages this week</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon ic-gold"><i class="ti ti-users"></i></div>
        <div class="stat-num"><?php echo (int) $stats['counselors_met']; ?></div>
        <div class="stat-label">Counselors met</div>
      </div>
    </section>

    <section class="section">
      <div class="section-head">
        <h2>Recommended for you</h2>
        <span class="section-sub">Member since <?php echo esc_html( $member_since_label ); ?></span>
      </div>
      <div class="rec-card">
        <div class="rec-av <?php echo esc_attr( $recommended['class'] ); ?>"><i class="ti <?php echo esc_attr( $recommended['icon'] ); ?>"></i></div>
        <div class="rec-meta">
          <h3><?php echo esc_html( $recommended['name'] ); ?></h3>
          <p class="spec"><?php echo esc_html( $recommended['spec'] ); ?></p>
          <p class="reason"><?php echo esc_html( $recommend_reason ); ?></p>
        </div>
        <a class="btn-rec" href="/talk.php#<?php echo esc_attr( $recommended_slug ); ?>">Start talking</a>
      </div>
    </section>

    <section class="section">
      <div class="section-head">
        <h2>Today's reflection</h2>
        <span class="section-sub">Private, never shared</span>
      </div>
      <div class="journal-card">
        <textarea id="journal-text" placeholder="What's on your mind today? This stays just between you and this page."><?php echo esc_textarea( $today_journal ? $today_journal : '' ); ?></textarea>
        <div class="journal-foot">
          <span class="inline-msg" id="journal-msg"></span>
          <button class="btn-save" id="journal-save">Save reflection</button>
        </div>
      </div>
    </section>

    <section class="section" id="insights-sec">
      <div class="section-head">
        <h2>Reflection Engine</h2>
        <span class="section-sub">Your deep psychological patterns</span>
      </div>
      <div class="memory-card">
        <p class="sub" style="margin-bottom:16px;">Every 10 sessions, Kounselia's analytical engine reads your Memory Profile and looks for deeper patterns, blind spots, and growth.</p>
        
        <button class="generate-insight-btn" id="btn-gen-insight" onclick="generateInsights()">
          <i class="ti ti-bulb"></i>
          <span>Generate Milestone Reflection</span>
        </button>
        <?php if ( $kounselia_reflection_allowance && $kounselia_reflection_allowance['limit'] ) : ?>
          <p class="reflection-allowance" id="reflection-allowance"><?php echo (int) $kounselia_reflection_allowance['remaining']; ?> of <?php echo (int) $kounselia_reflection_allowance['limit']; ?> left this month · <a href="javascript:void(0)" onclick="switchTab('upgrade')">Unlimited with Pro</a></p>
        <?php endif; ?>

        <div id="insight-ui" class="insight-wrapper">
          <div class="insight-header">
            <h3>Your Milestone Report</h3>
            <p id="insight-date"></p>
          </div>
          <div class="insight-grid" id="insight-grid"></div>
        </div>
      </div>
    </section>

  </div><!-- /view-home -->

  <!-- SESSIONS PANEL -->
  <div class="view-panel" id="view-sessions">
    <section class="section">
      <div class="section-head"><h2>Recent sessions</h2></div>
      <?php if ( empty( $recent_sessions ) ) : ?>
        <div class="empty-state">
          <i class="ti ti-feather"></i>
          <p>Your story starts with one conversation. Nothing saved here yet.</p>
          <a class="btn-w primary" style="background:var(--accent);color:#fff;display:inline-flex;border:none;cursor:pointer" href="/talk.php"><i class="ti ti-message-2-plus"></i> Start session</a>
        </div>
      <?php else : ?>
        <?php foreach ( $recent_sessions as $s ) :
            $c = isset( $counselors[ $s->counselor_slug ] ) ? $counselors[ $s->counselor_slug ] : array( 'name' => ucfirst( $s->counselor_slug ), 'spec' => '', 'icon' => 'ti-message-circle', 'class' => 'ic-blue' );
        ?>
        <div class="session-row">
          <div class="session-av <?php echo esc_attr( $c['class'] ); ?>"><i class="ti <?php echo esc_attr( $c['icon'] ); ?>"></i></div>
          <div class="session-meta">
            <h4><?php echo esc_html( $c['name'] ); ?></h4>
            <p><?php echo (int) $s->message_count; ?> msgs · <?php echo esc_html( human_time_diff( strtotime( $s->last_message_at ? $s->last_message_at : $s->started_at ), current_time( 'timestamp' ) ) ); ?> ago</p>
          </div>
          <a class="session-link" href="/talk.php#<?php echo esc_attr( $s->counselor_slug ); ?>">Continue →</a>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
  </div><!-- /view-sessions -->

  <!-- PROFESSIONALS / BOOKINGS PANEL -->
  <div class="view-panel" id="view-professionals">
    <section class="section">
      <div class="section-head">
        <h2>Your upcoming sessions</h2>
      </div>
      <?php if ( empty( $my_bookings ) ) : ?>
        <div class="empty-state">
          <i class="ti ti-calendar-event"></i>
          <p>No sessions booked yet. Find a licensed professional below and pick a time that works for you.</p>
        </div>
      <?php else : ?>
        <div id="my-booking-list">
        <?php foreach ( $my_bookings as $booking ) : ?>
        <?php $can_join = function_exists( 'kounselia_booking_is_joinable' ) ? kounselia_booking_is_joinable( $booking ) : false; ?>
        <div class="session-row booking-session-row" data-booking-id="<?php echo (int) $booking->id; ?>">
          <div class="session-av ic-gold"><i class="ti ti-calendar-event"></i></div>
          <div class="session-meta">
            <h4><?php echo esc_html( $booking->pro_name ); ?><?php echo $booking->pro_title ? ' · ' . esc_html( $booking->pro_title ) : ''; ?><?php if ( $booking->series_id ) : ?><span class="weekly-tag">Weekly</span><?php endif; ?></h4>
            <p><?php echo esc_html( date_i18n( 'D, M j — g:i A', strtotime( $booking->scheduled_start ) ) ); ?></p>
          </div>
          <div class="booking-actions">
            <?php if ( $can_join ) : ?>
              <a class="session-link booking-join" href="/video-call.php?booking_id=<?php echo (int) $booking->id; ?>"><i class="ti ti-video"></i> Join</a>
            <?php endif; ?>
            <a class="session-link js-booking-chat" href="javascript:void(0)" data-booking-id="<?php echo (int) $booking->id; ?>" data-other-name="<?php echo esc_attr( $booking->pro_name ); ?>">Message</a>
            <a class="session-link js-reschedule" href="javascript:void(0)" data-booking-id="<?php echo (int) $booking->id; ?>" data-pro-id="<?php echo (int) $booking->professional_id; ?>" data-other-name="<?php echo esc_attr( $booking->pro_name ); ?>">Reschedule</a>
            <a class="session-link" href="javascript:void(0)" onclick="cancelMyBooking(<?php echo (int) $booking->id; ?>, this)">Cancel</a>
            <?php if ( $booking->series_id ) : ?>
              <a class="session-link" href="javascript:void(0)" onclick="cancelMySeries(<?php echo (int) $booking->series_id; ?>, this)" style="color:var(--rose)">Cancel weekly</a>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <?php if ( ! empty( $past_bookings ) ) : ?>
    <section class="section">
      <div class="section-head">
        <h2>Past sessions</h2>
      </div>
      <div id="past-booking-list">
        <?php foreach ( $past_bookings as $booking ) : ?>
        <div class="session-row" data-booking-id="<?php echo (int) $booking->id; ?>">
          <div class="session-av ic-blue"><i class="ti ti-check"></i></div>
          <div class="session-meta">
            <h4><?php echo esc_html( $booking->pro_name ); ?><?php echo $booking->pro_title ? ' · ' . esc_html( $booking->pro_title ) : ''; ?></h4>
            <p><?php echo esc_html( date_i18n( 'D, M j, Y', strtotime( $booking->scheduled_start ) ) ); ?></p>
          </div>
          <?php if ( $booking->review_id ) : ?>
            <span class="session-link" style="color:var(--gold)"><?php echo str_repeat( '★', (int) $booking->review_rating ) . str_repeat( '☆', 5 - (int) $booking->review_rating ); ?></span>
          <?php else : ?>
            <a class="session-link js-rate-session" href="javascript:void(0)" data-booking-id="<?php echo (int) $booking->id; ?>" data-other-name="<?php echo esc_attr( $booking->pro_name ); ?>">Rate this session</a>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <section class="section">
      <div class="section-head">
        <h2>Find a professional</h2>
        <span class="section-sub">Licensed and verified by Kounselia</span>
      </div>
      <?php if ( empty( $verified_professionals ) ) : ?>
        <div class="empty-state">
          <i class="ti ti-users"></i>
          <p>No verified professionals are available to book just yet. Check back soon.</p>
        </div>
      <?php else : ?>
        <div class="counselor-grid">
          <?php foreach ( $verified_professionals as $pro ) :
              $pro_avatar = function_exists( 'kounselia_get_avatar_url' ) ? kounselia_get_avatar_url( $pro->user_id, 'thumbnail' ) : false;
              $pro_initial = mb_strtoupper( mb_substr( $pro->display_name, 0, 1 ) );
              $pro_rating  = function_exists( 'kounselia_get_professional_rating_summary' ) ? kounselia_get_professional_rating_summary( $pro->id ) : array( 'average' => 0, 'count' => 0 );
          ?>
          <a class="counselor-tile js-book-pro" href="javascript:void(0)" data-pro-id="<?php echo (int) $pro->id; ?>" data-pro-name="<?php echo esc_attr( $pro->display_name . ( $pro->title ? ' · ' . $pro->title : '' ) ); ?>">
            <div class="tile-av ic-blue" style="overflow:hidden">
              <?php echo $pro_avatar ? '<img src="' . esc_url( $pro_avatar ) . '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%">' : esc_html( $pro_initial ); ?>
            </div>
            <div class="tile-name"><?php echo esc_html( $pro->display_name ); ?></div>
            <div class="tile-spec"><?php echo esc_html( $pro->title ); ?><?php echo $pro->specialty ? ' · ' . esc_html( $pro->specialty ) : ''; ?></div>
            <?php if ( $pro_rating['count'] > 0 ) : ?>
              <div class="tile-spec" style="margin-top:4px;color:var(--gold)">★ <?php echo esc_html( number_format( $pro_rating['average'], 1 ) ); ?> <span style="color:var(--text3)">(<?php echo (int) $pro_rating['count']; ?> review<?php echo 1 === $pro_rating['count'] ? '' : 's'; ?>)</span></div>
            <?php else : ?>
              <div class="tile-spec" style="margin-top:4px;color:var(--text3)">No reviews yet</div>
            <?php endif; ?>
            <div class="tile-spec" style="margin-top:4px;font-weight:600;color:var(--accent)"><?php
              if ( $pro->rate_amount ) {
                  // Shown in the viewer's currency, with the Pro discount (if any) applied.
                  $kounselia_full  = kounselia_convert_ngn( $pro->rate_amount, $viewer_currency );
                  $kounselia_price = round( $kounselia_full * ( 1 - (float) $kounselia_session_discount / 100 ), 2 );
                  echo esc_html( kounselia_format_money( $kounselia_price, $viewer_currency ) ) . ' / session';
                  if ( $kounselia_price < $kounselia_full ) {
                      echo ' <s style="color:var(--text3);font-weight:400">' . esc_html( kounselia_format_money( $kounselia_full, $viewer_currency ) ) . '</s> <span style="color:var(--gold);font-weight:500">Pro price</span>';
                  }
              }
            ?></div>
          </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div><!-- /view-professionals -->

  <!-- TALK PANEL (Mobile Only) -->
  <div class="view-panel" id="view-talk">
    <section class="section">
      <div class="section-head">
        <h2>Talk to someone</h2>
        <span class="section-sub">Pick whoever fits right now</span>
      </div>
      <div class="counselor-grid">
        <?php foreach ( $counselors as $slug => $c ) : ?>
        <a class="counselor-tile" href="/talk.php#<?php echo esc_attr( $slug ); ?>">
          <div class="tile-av <?php echo esc_attr( $c['class'] ); ?>"><i class="ti <?php echo esc_attr( $c['icon'] ); ?>"></i></div>
          <div class="tile-name"><?php echo esc_html( $c['name'] ); ?></div>
          <div class="tile-spec"><?php echo esc_html( $c['spec'] ); ?></div>
        </a>
        <?php endforeach; ?>
      </div>
    </section>
    <section class="section">
      <button type="button" class="talk-human" onclick="switchTab('professionals')">
        <span class="talk-human-icon"><i class="ti ti-user-heart"></i></span>
        <span class="talk-human-copy"><b>Prefer a real person?</b><small>Book a video session with a licensed professional<?php echo $kounselia_session_discount ? ' — ' . esc_html( rtrim( rtrim( number_format( (float) $kounselia_session_discount, 1 ), '0' ), '.' ) ) . '% off with Pro' : ''; ?>.</small></span>
        <i class="ti ti-chevron-right"></i>
      </button>
    </section>
  </div><!-- /view-talk -->

  <!-- SETTINGS PANEL -->
  <div class="view-panel" id="view-settings">
    <section class="section">
      <div class="section-head"><h2>Profile and settings</h2></div>
      <div class="settings-grid">
        <div class="avatar-card">
          <div class="avatar-wrap">
            <div class="avatar-img" id="avatar-img"><?php echo $avatar_url ? '<img src="' . esc_url( $avatar_url ) . '" alt="">' : esc_html( $initial ); ?></div>
            <label class="avatar-edit">
              <i class="ti ti-camera"></i>
              <input type="file" id="avatar-input" accept="image/png,image/jpeg,image/webp">
            </label>
          </div>
          <h4><?php echo esc_html( $display_name ); ?></h4>
          <p><?php echo esc_html( $user->user_email ); ?></p>
          <div class="inline-msg" id="avatar-msg"></div>
          
          <!-- Mobile-only fast link to Memory -->
          <button class="btn-w outline" onclick="switchTab('memory')" style="width:100%; justify-content:center; margin-top:24px; display:flex;">
            <i class="ti ti-brain"></i> Manage Memory Profile
          </button>
        </div>

        <div class="settings-stack">
          <div class="settings-card">
            <h4>Display name</h4>
            <div class="form-field">
              <label>Full name</label>
              <input type="text" id="name-input" value="<?php echo esc_attr( $display_name ); ?>">
            </div>
            <button class="btn-save" id="name-save">Save changes</button>
            <div class="inline-msg" id="name-msg"></div>
          </div>

          <div class="settings-card">
            <h4>Password</h4>
            <div class="form-field">
              <label>Current password</label>
              <input type="password" id="pw-current" autocomplete="current-password">
            </div>
            <div class="form-field">
              <label>New password</label>
              <input type="password" id="pw-new" autocomplete="new-password">
            </div>
            <div class="form-field">
              <label>Confirm new password</label>
              <input type="password" id="pw-confirm" autocomplete="new-password">
            </div>
            <button class="btn-save" id="pw-save">Update password</button>
            <div class="inline-msg" id="pw-msg"></div>
          </div>

          <div class="settings-card">
            <h4>Email preferences</h4>
            <label class="email-pref"><input type="checkbox" id="pref-newsletter"> <span><b>Newsletter</b><small>Occasional ideas for looking after your mind, and news from Kounselia.</small></span></label>
            <label class="email-pref"><input type="checkbox" id="pref-blog"> <span><b>New blog posts</b><small>A short email when a new story is published on the journal.</small></span></label>
            <p class="email-pref-note">Emails about your account, like booking confirmations and reminders, are always sent.</p>
            <button class="btn-save" id="pref-save">Save preferences</button>
            <div class="inline-msg" id="pref-msg"></div>
          </div>
        </div>
      </div>
    </section>
  </div><!-- /view-settings -->

  <!-- MEMORY PANEL -->
  <div class="view-panel" id="view-memory">
    <section class="section">
      <div class="section-head">
        <h2>Structured Memory Engine</h2>
        <span class="section-sub">Automatically maintained by AI across your sessions</span>
      </div>
      <div class="memory-card">
        
        <div id="memory-preview" class="memory-preview" style="<?php echo $core_memory_json ? 'display:block' : 'display:none'; ?>">
          <div class="memory-preview-head">
            <h5>Current Psychological Profile</h5>
            <span class="memory-preview-date" id="memory-preview-date">Updated dynamically</span>
          </div>
          
          <div id="memory-preview-grid"></div>
          
          <div class="foot-actions" style="justify-content: space-between;">
            <button class="btn-copy" onclick="openMemoryEdit()" style="margin:0; border:none; background:var(--surface2);"><i class="ti ti-pencil"></i> Edit Details</button>
            <button class="btn-delete-memory" onclick="deleteMemory()"><i class="ti ti-trash"></i> Delete Profile</button>
          </div>
        </div>

        <div id="memory-import-section" style="<?php echo $core_memory_json ? 'display:none' : 'display:block'; ?>">
          <h4>Import from another AI</h4>
          <p class="sub">Your counselors will use this context in every conversation going forward. The raw text you paste is processed and discarded — only a structured JSON profile is kept.</p>
          <div class="memory-steps">
            <div class="memory-step">
              <div class="step-num">1</div>
              <div class="step-body">
                <h5>Copy this prompt</h5>
                <p>Open ChatGPT or Gemini and paste this into a new message:</p>
                <div class="prompt-box" id="copy-prompt-text">Please summarize everything you know about me as a person. Include my life timeline (dates and events), my emotional patterns and feelings about specific things in my life, my relationships, my work situation and goals, and any mental health themes. Write it as a clear factual summary.</div>
                <button class="btn-copy" id="btn-copy-prompt" onclick="copyImportPrompt()"><i class="ti ti-copy"></i> Copy prompt</button>
              </div>
            </div>
            <div class="memory-step">
              <div class="step-num">2</div>
              <div class="step-body">
                <h5>Paste the response here</h5>
                <p>Copy the full response from the other AI and paste it below.</p>
                <textarea class="memory-paste" id="memory-paste" placeholder="Paste the AI response here..."></textarea>
                <div class="memory-paste-foot">
                  <span class="inline-msg" id="memory-import-msg"></span>
                  <button class="btn-process" id="btn-process-memory" onclick="processMemory()"><i class="ti ti-brain"></i> Extract Structured Profile</button>
                </div>
                <div class="memory-processing" id="memory-processing" style="display:none"><i class="ti ti-loader-2"></i> Analyzing your data and building profile...</div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </section>
  </div><!-- /view-memory -->

  <!-- MY PLAN PANEL (subscription, what's included, billing) -->
  <?php require __DIR__ . '/inc/dashboard-my-plan.php'; ?>

  </main>

  <!-- MOBILE BOTTOM TAB BAR -->
  <nav class="mobile-tabbar">
    <button class="mob-tab active" id="mob-tab-home" onclick="switchTab('home')"><i class="ti ti-home"></i><span>Home</span></button>
    <button class="mob-tab" id="mob-tab-sessions" onclick="switchTab('sessions')"><i class="ti ti-history"></i><span>Sessions</span></button>
    <button class="tabbar-fab" onclick="switchTab('talk')" aria-label="Talk to someone"><i class="ti ti-message-2-plus"></i></button>
    <button class="mob-tab" id="mob-tab-upgrade" onclick="switchTab('upgrade')"><i class="ti ti-sparkles"></i><span>My plan</span></button>
    <button class="mob-tab" id="mob-tab-settings" onclick="switchTab('settings')"><i class="ti ti-settings"></i><span>Settings</span></button>
  </nav>

</div>

<!-- INTAKE MODAL UI -->
<div class="intake-overlay" id="intake-overlay">
  <div class="intake-modal">
    <div class="intake-progress">
      <div class="intake-dot active" id="dot-1"></div>
      <div class="intake-dot" id="dot-2"></div>
      <div class="intake-dot" id="dot-3"></div>
    </div>
    
    <div class="intake-step active" id="step-1">
      <h2>Welcome to your space.</h2>
      <p>We are so glad you are here, <?php echo esc_html( $first_name ); ?>. Before you dive in, taking a moment to tell us where you're at helps your counselors understand you right away. You can skip this if you prefer.</p>
      <div class="intake-actions">
        <button class="intake-btn ghost" onclick="skipIntake()">Skip for now</button>
        <button class="intake-btn" onclick="nextIntake(2)">Let's begin</button>
      </div>
    </div>

    <div class="intake-step" id="step-2">
      <h2>What brings you here?</h2>
      <p>Is there a specific situation, feeling, or pattern on your mind lately?</p>
      <textarea id="intake-q1" placeholder="I've been feeling really overwhelmed with..."></textarea>
      <div class="intake-actions">
        <button class="intake-btn ghost" onclick="nextIntake(1)">Back</button>
        <button class="intake-btn" onclick="nextIntake(3)">Continue</button>
      </div>
    </div>

    <div class="intake-step" id="step-3">
      <h2>What are your goals?</h2>
      <p>What would a successful conversation look like for you today?</p>
      <textarea id="intake-q2" placeholder="I just need someone to listen, or I'm looking for clarity on..."></textarea>
      <div class="intake-actions">
        <button class="intake-btn ghost" onclick="nextIntake(2)">Back</button>
        <button class="intake-btn" id="intake-finish-btn" onclick="finishIntake()">Finish & Start</button>
      </div>
    </div>
  </div>
</div>

<!-- EDIT MEMORY MODAL UI -->
<div class="intake-overlay" id="edit-memory-overlay">
  <div class="intake-modal" style="max-width: 600px;">
    <div class="intake-step active">
      <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
        <h2 style="margin:0; font-size:28px;">Edit Profile</h2>
        <button onclick="closeMemoryEdit()" style="background:none; border:none; font-size:24px; cursor:pointer; color:var(--text3); padding:4px;"><i class="ti ti-x"></i></button>
      </div>
      <p style="margin-bottom: 24px;">Update your core psychological profile manually. Your counselors will use this updated context.</p>
      
      <div style="max-height: 50vh; overflow-y: auto; padding-right: 10px; margin-bottom: 24px; display:flex; flex-direction:column; gap:16px;">
          <div class="form-field" style="margin:0;">
            <label>Identity & Core Self</label>
            <textarea id="edit-mem-identity" style="width:100%; min-height:80px; border:1.5px solid var(--border); border-radius:12px; padding:12px 14px; font-family:inherit; font-size:14.5px; background:var(--bg); color:var(--text); resize:vertical; outline:none; transition:all 0.2s ease;" onfocus="this.style.borderColor='var(--accent)'; this.style.boxShadow='0 0 0 4px var(--accent-light)'" onblur="this.style.borderColor='var(--border)'; this.style.boxShadow='none'"></textarea>
          </div>
          <div class="form-field" style="margin:0;">
            <label>Career</label>
            <textarea id="edit-mem-career" style="width:100%; min-height:80px; border:1.5px solid var(--border); border-radius:12px; padding:12px 14px; font-family:inherit; font-size:14.5px; background:var(--bg); color:var(--text); resize:vertical; outline:none; transition:all 0.2s ease;" onfocus="this.style.borderColor='var(--accent)'; this.style.boxShadow='0 0 0 4px var(--accent-light)'" onblur="this.style.borderColor='var(--border)'; this.style.boxShadow='none'"></textarea>
          </div>
          <div class="form-field" style="margin:0;">
            <label>Goals (comma-separated)</label>
            <input type="text" id="edit-mem-goals">
          </div>
          <div class="form-field" style="margin:0;">
            <label>Core Values (comma-separated)</label>
            <input type="text" id="edit-mem-values">
          </div>
          <div class="form-field" style="margin:0;">
            <label>Habits & Patterns (comma-separated)</label>
            <input type="text" id="edit-mem-habits">
          </div>
          <div class="form-field" style="margin:0;">
            <label>Triggers (comma-separated)</label>
            <input type="text" id="edit-mem-triggers">
          </div>
      </div>

      <div class="intake-actions">
        <button class="intake-btn ghost" onclick="closeMemoryEdit()">Cancel</button>
        <button class="intake-btn" id="btn-save-memory-edit" onclick="saveMemoryEdit()">Save Changes</button>
      </div>
    </div>
  </div>
</div>

<!-- BOOK A SESSION MODAL -->
<div class="intake-overlay" id="book-overlay">
  <div class="intake-modal" style="max-width:560px">
    <div class="intake-step active">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;">
        <h2 style="margin:0;font-size:26px" id="book-modal-name">Book a session</h2>
        <button onclick="closeBooking()" style="background:none;border:none;font-size:24px;cursor:pointer;color:var(--text3);padding:4px;"><i class="ti ti-x"></i></button>
      </div>
      <p style="margin-bottom:20px">Pick an open time below. Sessions are <?php echo (int) ( function_exists( 'kounselia_session_length_minutes' ) ? kounselia_session_length_minutes() : 60 ); ?> minutes. You'll pay securely by card or transfer on the next screen — the slot is only reserved for a few minutes while you do.</p>

      <div id="book-slots" style="max-height:280px;overflow-y:auto;margin-bottom:20px">
        <p style="color:var(--text3);font-size:14px" id="book-slots-loading">Loading available times...</p>
      </div>

      <div class="form-field" id="book-note-field" style="display:none;margin:0 0 16px">
        <label>A short note for them (optional)</label>
        <textarea id="book-note" style="width:100%;min-height:70px;border:1.5px solid var(--border);border-radius:12px;padding:12px 14px;font-family:inherit;font-size:14.5px;background:var(--bg);color:var(--text);resize:vertical;outline:none"></textarea>
      </div>

      <label id="book-recurring-field" style="display:none;align-items:flex-start;gap:10px;margin-bottom:20px;cursor:pointer;font-size:13.5px;color:var(--text2);line-height:1.5">
        <input type="checkbox" id="book-recurring" style="margin-top:3px">
        <span>Make this a weekly session at the same time. You'll be charged automatically each week using this payment method — cancel anytime.</span>
      </label>

      <div class="intake-actions">
        <button class="intake-btn ghost" onclick="closeBooking()">Cancel</button>
        <button class="intake-btn" id="book-confirm-btn" onclick="confirmBooking()" style="display:none">Confirm booking</button>
      </div>
    </div>
  </div>
</div>

<!-- NOTIFICATIONS MODAL -->
<div class="intake-overlay" id="notif-overlay">
  <div class="intake-modal" style="max-width:480px">
    <div class="intake-step active">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;">
        <h2 style="margin:0;font-size:24px">Notifications</h2>
        <button onclick="closeNotifications()" style="background:none;border:none;font-size:24px;cursor:pointer;color:var(--text3);padding:4px;"><i class="ti ti-x"></i></button>
      </div>
      <div class="notif-list" id="notif-list"><p style="color:var(--text3);font-size:13px">Loading...</p></div>
    </div>
  </div>
</div>

<!-- RATE A SESSION MODAL -->
<div class="intake-overlay" id="rate-overlay">
  <div class="intake-modal" style="max-width:460px">
    <div class="intake-step active">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;">
        <h2 style="margin:0;font-size:24px" id="rate-modal-name">Rate this session</h2>
        <button onclick="closeRateModal()" style="background:none;border:none;font-size:24px;cursor:pointer;color:var(--text3);padding:4px;"><i class="ti ti-x"></i></button>
      </div>
      <div id="rate-stars" style="font-size:32px;letter-spacing:6px;color:var(--border);margin-bottom:16px;cursor:pointer">★★★★★</div>
      <div class="form-field" style="margin:0 0 20px">
        <label>A word about your experience (optional)</label>
        <textarea id="rate-comment" style="width:100%;min-height:70px;border:1.5px solid var(--border);border-radius:12px;padding:12px 14px;font-family:inherit;font-size:14.5px;background:var(--bg);color:var(--text);resize:vertical;outline:none"></textarea>
      </div>
      <div class="intake-actions">
        <button class="intake-btn ghost" onclick="closeRateModal()">Cancel</button>
        <button class="intake-btn" id="rate-submit-btn" onclick="submitReview()">Submit rating</button>
      </div>
    </div>
  </div>
</div>

<!-- BOOKING CHAT MODAL -->
<div class="chat-overlay" id="chat-overlay">
  <div class="chat-modal">
    <div class="chat-modal-head">
      <h3 id="chat-modal-name">Conversation</h3>
      <button type="button" onclick="closeBookingChat()"><i class="ti ti-x"></i></button>
    </div>
    <div class="chat-messages" id="chat-messages"></div>
    <form id="chat-form" class="chat-input-row">
      <input type="text" id="chat-input" placeholder="Write a message..." autocomplete="off">
      <button type="submit"><i class="ti ti-send"></i></button>
    </form>
  </div>
</div>

<div id="toast-wrap"></div>

<script>
const KOUNSELIA={ajaxUrl:<?php echo wp_json_encode( $ajax_url ); ?>,nonce:<?php echo wp_json_encode( $nonce ); ?>};

function toast(msg,isErr){
  const wrap=document.getElementById('toast-wrap');
  const t=document.createElement('div');
  t.className='toast'+(isErr?' err':'');
  t.textContent=msg;
  wrap.appendChild(t);
  setTimeout(()=>{t.style.opacity='0';t.style.transition='opacity .3s ease';setTimeout(()=>t.remove(),300);},3200);
}

function showInline(id,msg,ok){
  const el=document.getElementById(id);
  if(!el) return;
  el.textContent=msg;
  el.className='inline-msg '+(ok?'ok':'err');
  el.style.display='block';
}

// Unified Tab Switcher for Desktop & Mobile
function switchTab(tab) {
  // 1. Hide all panels
  document.querySelectorAll('.view-panel').forEach(p => p.classList.remove('active'));

  // 2. Show target panel
  const target = document.getElementById('view-' + tab);
  if (target) {
    target.classList.add('active');
    
    // Reset scroll based on device type
    if (window.innerWidth <= 900) {
      target.scrollTop = 0; // Mobile view uses panel scrolling
    } else {
      window.scrollTo({ top: 0, behavior: 'smooth' }); // Desktop uses window scrolling
    }
  }

  // 3. Update Mobile Tab Bar UI
  document.querySelectorAll('.mob-tab').forEach(b => b.classList.remove('active'));
  const mobActive = document.getElementById('mob-tab-' + tab);
  if (mobActive) mobActive.classList.add('active');

  // 4. Update Desktop Sidebar UI
  document.querySelectorAll('.side-nav .nav-link').forEach(b => b.classList.remove('active'));
  const deskActive = document.getElementById('desk-tab-' + tab);
  if (deskActive) deskActive.classList.add('active');
}

function signOut(){
  fetch(KOUNSELIA.ajaxUrl,{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams({action:'kounselia_logout',nonce:KOUNSELIA.nonce})
  }).finally(()=>{ window.location.href='/index.php'; });
}

function setupAvatarUpload(inputId, imgId, sideAvId, mobileAvId, msgId){
  const inp=document.getElementById(inputId);
  if(!inp) return;
  inp.addEventListener('change',function(e){
    const file=e.target.files[0];
    if(!file) return;
    const reader=new FileReader();
    reader.onload=ev=>{
      const html=`<img src="${ev.target.result}" alt="">`;
      [imgId,sideAvId,mobileAvId].forEach(id=>{ const el=document.getElementById(id); if(el) el.innerHTML=html; });
    };
    reader.readAsDataURL(file);
    const fd=new FormData();
    fd.append('action','kounselia_upload_avatar');
    fd.append('nonce',KOUNSELIA.nonce);
    fd.append('avatar',file);
    fetch(KOUNSELIA.ajaxUrl,{method:'POST',body:fd})
      .then(r=>r.json())
      .then(res=>{
        if(res.success){
          showInline(msgId,'Profile picture updated.',true);
          const html=`<img src="${res.data.avatar_url}" alt="">`;
          [imgId,sideAvId,mobileAvId].forEach(id=>{ const el=document.getElementById(id); if(el) el.innerHTML=html; });
        } else { showInline(msgId,res.data&&res.data.message?res.data.message:'Could not upload that image.',false); }
      })
      .catch(()=>showInline(msgId,'Something went wrong, please try again.',false));
  });
}
setupAvatarUpload('avatar-input','avatar-img','side-av','mobile-av','avatar-msg');

function setupNameSave(inputId, btnId, msgId){
  const btn=document.getElementById(btnId);
  if(!btn) return;
  btn.addEventListener('click',function(){
    const name=document.getElementById(inputId).value.trim();
    if(!name){ showInline(msgId,'Please enter a name.',false); return; }
    btn.disabled=true;
    fetch(KOUNSELIA.ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:new URLSearchParams({action:'kounselia_update_profile',nonce:KOUNSELIA.nonce,name})})
    .then(r=>r.json())
    .then(res=>{
      btn.disabled=false;
      if(res.success){
        showInline(msgId,'Saved.',true);
        const sideEl=document.querySelector('.side-user-name');
        if(sideEl) sideEl.textContent=res.data.name;
        document.querySelectorAll('.avatar-card h4').forEach(el=>el.textContent=res.data.name);
      } else { showInline(msgId,res.data&&res.data.message?res.data.message:'Could not save your name.',false); }
    })
    .catch(()=>{btn.disabled=false;showInline(msgId,'Something went wrong, please try again.',false);});
  });
}
setupNameSave('name-input','name-save','name-msg');

function setupPasswordSave(curId, newId, cfmId, btnId, msgId){
  const btn=document.getElementById(btnId);
  if(!btn) return;
  btn.addEventListener('click',function(){
    const cur=document.getElementById(curId).value;
    const next=document.getElementById(newId).value;
    const confirm=document.getElementById(cfmId).value;
    if(!cur||!next||!confirm){ showInline(msgId,'Please fill in all three fields.',false); return; }
    if(next!==confirm){ showInline(msgId,'New passwords do not match.',false); return; }
    if(next.length<8){ showInline(msgId,'New password must be at least 8 characters.',false); return; }
    btn.disabled=true;
    fetch(KOUNSELIA.ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:new URLSearchParams({action:'kounselia_update_password',nonce:KOUNSELIA.nonce,current_password:cur,new_password:next})})
    .then(r=>r.json())
    .then(res=>{
      btn.disabled=false;
      if(res.success){
        showInline(msgId,'Password updated.',true);
        [curId,newId,cfmId].forEach(id=>{ const el=document.getElementById(id); if(el) el.value=''; });
      } else { showInline(msgId,res.data&&res.data.message?res.data.message:'Could not update your password.',false); }
    })
    .catch(()=>{btn.disabled=false;showInline(msgId,'Something went wrong, please try again.',false);});
  });
}
setupPasswordSave('pw-current','pw-new','pw-confirm','pw-save','pw-msg');

// Email preferences (newsletter / new blog post emails).
(function(){
  const nl=document.getElementById('pref-newsletter'), blog=document.getElementById('pref-blog'), btn=document.getElementById('pref-save');
  if(!btn) return;
  const post=(data)=>fetch(KOUNSELIA.ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(Object.assign({nonce:KOUNSELIA.nonce},data))}).then(r=>r.json());
  post({action:'kounselia_get_email_prefs'}).then(res=>{ if(res.success){ nl.checked=!!res.data.newsletter; blog.checked=!!res.data.blog; } }).catch(()=>{});
  btn.addEventListener('click',function(){
    btn.disabled=true;
    post({action:'kounselia_save_email_prefs',newsletter:nl.checked?'1':'0',blog:blog.checked?'1':'0'})
      .then(res=>{ btn.disabled=false; showInline('pref-msg',res.data&&res.data.message?res.data.message:(res.success?'Saved.':'Could not save.'),!!res.success); })
      .catch(()=>{ btn.disabled=false; showInline('pref-msg','Something went wrong, please try again.',false); });
  });
})();

function dismissCheckin(checkinId){
  const card=document.getElementById('checkin-card');
  if(card){ card.style.opacity='0'; setTimeout(()=>card.remove(),200); }
  fetch(KOUNSELIA.ajaxUrl,{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams({action:'kounselia_dismiss_checkin',nonce:KOUNSELIA.nonce,checkin_id:checkinId})
  });
}

function saveMood(mood){
  document.querySelectorAll('.mood-btn').forEach(b=>b.classList.toggle('selected', b.dataset.mood===mood));
  fetch(KOUNSELIA.ajaxUrl,{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams({action:'kounselia_save_mood',nonce:KOUNSELIA.nonce,mood})
  })
  .then(r=>r.json())
  .then(res=>{
    if(res.success){
      const savedTags = document.querySelectorAll('.mood-saved-tag');
      savedTags.forEach(el => el.style.display='flex');
      
      document.querySelectorAll('.rhythm-day:last-child .rhythm-dot').forEach(today => {
        const colorClass=document.querySelector('.mood-btn[data-mood="'+mood+'"]').className.match(/ic-\w+/);
        today.className='rhythm-dot filled '+(colorClass?colorClass[0]:'');
      });
    } else {
      toast(res.data&&res.data.message?res.data.message:'Could not save your mood.',true);
    }
  })
  .catch(()=>toast('Something went wrong, please try again.',true));
}

let journalTimer=null;
const journalEl = document.getElementById('journal-text');
if (journalEl) {
  journalEl.addEventListener('input',function(){ 
    clearTimeout(journalTimer); 
    journalTimer=setTimeout(()=>saveJournal('journal-text','journal-msg'),1800); 
  });
}
const jsBtn = document.getElementById('journal-save');
if(jsBtn) jsBtn.addEventListener('click',()=>saveJournal('journal-text','journal-msg'));

function saveJournal(sourceId,msgId){
  const el=document.getElementById(sourceId);
  if(!el) return;
  const content=el.value;
  fetch(KOUNSELIA.ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:newSearchParams({action:'kounselia_save_journal',nonce:KOUNSELIA.nonce,content})})
  .then(r=>r.json())
  .then(res=>{ if(res.success) showInline(msgId,'Saved.',true); else showInline(msgId,'Could not save, please try again.',false); })
  .catch(()=>showInline(msgId,'Could not save, please try again.',false));
}

// Subscribe / cancel / manage-plan handlers live in inc/dashboard-my-plan.php.

(function(){
  const params = new URLSearchParams(window.location.search);
  // Links from the public pages (e.g. the Pro plans page) can open a tab directly: ?tab=upgrade
  const tabParam = params.get('tab');
  if(tabParam && /^[a-z]+$/.test(tabParam) && document.getElementById('view-' + tabParam)){ switchTab(tabParam); }
  // "Book a session" on a public profile lands here with ?book=<professional id>: open that professional's booking.
  const bookParam = params.get('book');
  if(bookParam && /^\d+$/.test(bookParam)){
    const tile = document.querySelector('.js-book-pro[data-pro-id="' + bookParam + '"]');
    if(tile){ switchTab('professionals'); setTimeout(()=>tile.click(), 350); }
  }
  const subResult = params.get('sub');
  if(subResult === 'success'){
    toast("Payment confirmed — you're all set.");
    switchTab('upgrade');
  } else if(subResult === 'failed'){
    toast('Payment was not completed. Please try again.', true);
    switchTab('upgrade');
  }

  const bookingResult = params.get('booking');
  if(bookingResult === 'success'){
    toast('Payment confirmed — your session is booked.');
    switchTab('professionals');
  } else if(bookingResult === 'failed'){
    toast('Payment was not completed, so that session was not booked. Please try again.', true);
    switchTab('professionals');
  }
})();

let currentMemoryData = null;

function renderMemoryUI(data) {
  if (!data) return;
  currentMemoryData = data;
  
  const buildChips = (arr) => {
    if(!arr || !arr.length) return '<span class="mem-text">None noted yet</span>';
    return `<div class="chip-list">${arr.map(t => `<span class="chip">${escHTML(t)}</span>`).join('')}</div>`;
  };

  const buildText = (str) => {
    return str ? `<div class="mem-text">${escHTML(str)}</div>` : '<span class="mem-text">Not established yet</span>';
  };
  
  const buildTimeline = (arr) => {
    if(!arr || !arr.length) return '<span class="mem-text">No timeline events recorded yet</span>';
    return `<div class="mem-timeline">
      ${arr.map(item => `
        <div class="timeline-item">
          <div class="tl-year">${escHTML(item.year)}</div>
          <div class="tl-event">${escHTML(item.event)}</div>
          ${item.impact ? `<div class="tl-impact">${escHTML(item.impact)}</div>` : ''}
        </div>
      `).join('')}
    </div>`;
  };

  const buildEmotions = (obj) => {
    if(!obj || Object.keys(obj).length === 0) return '<span class="mem-text">No emotional anchors recorded yet</span>';
    return `<div class="emotion-grid">
      ${Object.entries(obj).map(([entity, details]) => `
        <div class="emotion-card">
          <div class="emo-head">
            <span class="emo-entity">${escHTML(entity)}</span>
            <span class="emo-badge badge-${details.intensity}">${escHTML(details.intensity)}</span>
          </div>
          <div class="emo-feeling">${escHTML(details.emotion)}</div>
          ${details.context ? `<div class="emo-context">${escHTML(details.context)}</div>` : ''}
        </div>
      `).join('')}
    </div>`;
  };

  const html = `
    <div class="profile-layout">
      <div class="profile-sidebar">
        <div class="mem-block"><div class="mem-label">Identity</div>${buildText(data.identity)}</div>
        <div class="mem-block"><div class="mem-label">Career</div>${buildText(data.career)}</div>
        <div class="mem-block"><div class="mem-label">Goals</div>${buildChips(data.goals)}</div>
        <div class="mem-block"><div class="mem-label">Core Values</div>${buildChips(data.values)}</div>
        <div class="mem-block"><div class="mem-label">Habits & Patterns</div>${buildChips(data.habits)}</div>
        <div class="mem-block"><div class="mem-label">Triggers</div>${buildChips(data.triggers)}</div>
      </div>
      <div class="profile-main">
        <div class="mem-block">
          <div class="mem-label">Life Timeline</div>
          ${buildTimeline(data.life_timeline)}
        </div>
        <div class="mem-block" style="margin-top:28px;">
          <div class="mem-label">Emotional Memory Map</div>
          ${buildEmotions(data.emotional_map)}
        </div>
        ${data.temporary_context ? `
        <div class="active-state-box">
          <div class="mem-label"><i class="ti ti-activity"></i> Active State (AI Managed)</div>
          <div class="mem-text">${escHTML(data.temporary_context)}</div>
        </div>
        ` : ''}
      </div>
    </div>
  `;

  const gridEl = document.getElementById('memory-preview-grid');
  if(gridEl) {
    gridEl.innerHTML = html;
    document.getElementById('memory-preview').style.display = 'block';
    document.getElementById('memory-import-section').style.display = 'none';
  }
}

function openMemoryEdit() {
    if (!currentMemoryData) return;
    document.getElementById('edit-mem-identity').value = currentMemoryData.identity || '';
    document.getElementById('edit-mem-career').value = currentMemoryData.career || '';
    document.getElementById('edit-mem-goals').value = (currentMemoryData.goals || []).join(', ');
    document.getElementById('edit-mem-values').value = (currentMemoryData.values || []).join(', ');
    document.getElementById('edit-mem-habits').value = (currentMemoryData.habits || []).join(', ');
    document.getElementById('edit-mem-triggers').value = (currentMemoryData.triggers || []).join(', ');
    
    document.getElementById('edit-memory-overlay').classList.add('active');
}

function closeMemoryEdit() {
    document.getElementById('edit-memory-overlay').classList.remove('active');
}

function saveMemoryEdit() {
    const btn = document.getElementById('btn-save-memory-edit');
    btn.disabled = true;
    btn.innerHTML = '<i class="ti ti-loader-2" style="animation:spin 1s linear infinite"></i> Saving...';

    const params = new URLSearchParams({
        action: 'kounselia_edit_memory',
        nonce: KOUNSELIA.nonce,
        identity: document.getElementById('edit-mem-identity').value,
        career: document.getElementById('edit-mem-career').value,
        goals: document.getElementById('edit-mem-goals').value,
        values: document.getElementById('edit-mem-values').value,
        habits: document.getElementById('edit-mem-habits').value,
        triggers: document.getElementById('edit-mem-triggers').value
    });

    fetch(KOUNSELIA.ajaxUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: params
    })
    .then(r => r.json())
    .then(res => {
        btn.disabled = false;
        btn.innerHTML = 'Save Changes';
        if(res.success) {
            renderMemoryUI(res.data.memory);
            closeMemoryEdit();
            toast('Profile details successfully updated.', false);
        } else {
            toast('Failed to update profile. Please try again.', true);
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = 'Save Changes';
        toast('A network error occurred. Please check your connection.', true);
    });
}

const initialMemory = <?php echo $core_memory_json ? $core_memory_json : 'null'; ?>;
if (initialMemory) renderMemoryUI(initialMemory);

function doImportMemory(pasteId, btnId, procId, msgId){
  const text=document.getElementById(pasteId).value.trim();
  if(!text){showInline(msgId,'Please paste the AI response first.',false);return;}
  if(text.length<50){showInline(msgId,'That looks too short, paste the full response.',false);return;}
  
  const btn=document.getElementById(btnId);
  const proc=document.getElementById(procId);
  btn.disabled=true; proc.style.display='flex';
  document.getElementById(msgId).style.display='none';
  
  fetch(KOUNSELIA.ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams({action:'kounselia_import_memory',nonce:KOUNSELIA.nonce,memory_text:text})})
  .then(r=>r.json())
  .then(res=>{
    btn.disabled=false; proc.style.display='none';
    if(res.success){
      document.getElementById(pasteId).value='';
      renderMemoryUI(res.data.summary);
      toast('Memory imported successfully.');
    } else { showInline(msgId,res.data&&res.data.message?res.data.message:'Something went wrong, please try again.',false); }
  })
  .catch(()=>{ btn.disabled=false; proc.style.display='none'; showInline(msgId,'Something went wrong, please check your connection and try again.',false); });
}

function doDeleteMemory(){
  if(!confirm('Remove your Profile? Your counselors will no longer have this background context.')) return;
  fetch(KOUNSELIA.ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams({action:'kounselia_delete_memory',nonce:KOUNSELIA.nonce})})
  .then(r=>r.json())
  .then(res=>{
    if(res.success){
      const preview = document.getElementById('memory-preview');
      if (preview) preview.style.display = 'none';
      const importSec = document.getElementById('memory-import-section');
      if (importSec) importSec.style.display = 'block';
      toast('Memory removed.');
    } else { toast('Could not remove memory, please try again.',true); }
  })
  .catch(()=>toast('Something went wrong, please try again.',true));
}

function doCopyPrompt(sourceId, btnId){
  const text=document.getElementById(sourceId).textContent.trim();
  const btn=document.getElementById(btnId);
  const reset=()=>{ btn.classList.remove('copied'); btn.innerHTML='<i class="ti ti-copy"></i> Copy prompt'; };
  navigator.clipboard.writeText(text).then(()=>{
    btn.classList.add('copied'); btn.innerHTML='<i class="ti ti-check"></i> Copied';
    setTimeout(reset,2500);
  }).catch(()=>{
    const ta=document.createElement('textarea');
    ta.value=text; ta.style.position='fixed'; ta.style.opacity='0';
    document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
    btn.classList.add('copied'); btn.innerHTML='<i class="ti ti-check"></i> Copied';
    setTimeout(reset,2500);
  });
}

function copyImportPrompt(){ doCopyPrompt('copy-prompt-text','btn-copy-prompt'); }
function processMemory(){ doImportMemory('memory-paste','btn-process-memory','memory-processing','memory-import-msg'); }
function deleteMemory(){ doDeleteMemory(); }

function renderInsights(data, dateStr) {
  if(!data) return;
  const buildList = (arr) => arr.map(item => `<li>${escHTML(item)}</li>`).join('');
  
  const html = `
    <div class="insight-box ib-patterns"><h4><i class="ti ti-repeat"></i> Recurring Patterns</h4><ul class="insight-list">${buildList(data.patterns)}</ul></div>
    <div class="insight-box ib-growth"><h4><i class="ti ti-trending-up"></i> Growth & Healing</h4><ul class="insight-list">${buildList(data.growth)}</ul></div>
    <div class="insight-box ib-fears"><h4><i class="ti ti-ghost"></i> Core Fears</h4><ul class="insight-list">${buildList(data.recurring_fears)}</ul></div>
    <div class="insight-box ib-blind"><h4><i class="ti ti-eye-closed"></i> Blind Spots</h4><ul class="insight-list">${buildList(data.blind_spots)}</ul></div>
    <div class="insight-box ib-wins"><h4><i class="ti ti-award"></i> Achievements</h4><ul class="insight-list">${buildList(data.achievements)}</ul></div>
    <div class="insight-box ib-recs"><h4><i class="ti ti-compass"></i> Recommendations</h4><ul class="insight-list">${buildList(data.recommendations)}</ul></div>
  `;

  const wrapper = document.getElementById('insight-ui');
  const grid = document.getElementById('insight-grid');
  const dateEl = document.getElementById('insight-date');
  if(wrapper && grid) {
    grid.innerHTML = html;
    if(dateEl && dateStr) {
      const d = new Date(dateStr.replace(' ', 'T'));
      dateEl.textContent = 'Generated on ' + d.toLocaleDateString('en-US',{month:'long',day:'numeric',year:'numeric'});
    }
    wrapper.style.display = 'block';
    const genBtn = document.getElementById('btn-gen-insight');
    if(genBtn) genBtn.style.display = 'none';
  }
}

const initialReflection = <?php echo $latest_reflection_json ? $latest_reflection_json : 'null'; ?>;
const initialReflectionDate = <?php echo $reflection_date ? wp_json_encode($reflection_date) : 'null'; ?>;
if (initialReflection) renderInsights(initialReflection, initialReflectionDate);

function generateInsights() {
  const btn = document.getElementById('btn-gen-insight');
  if(btn) { btn.disabled = true; btn.innerHTML = '<i class="ti ti-loader-2" style="animation:spin 1s linear infinite"></i><span>Analyzing your psychological profile...</span>'; }
  
  fetch(KOUNSELIA.ajaxUrl, {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: new URLSearchParams({action: 'kounselia_generate_reflection', nonce: KOUNSELIA.nonce})
  })
  .then(r => r.json())
  .then(res => {
    if(res.success) {
      renderInsights(res.data.reflection, res.data.date);
      const allowanceEl = document.getElementById('reflection-allowance');
      if(allowanceEl && res.data.allowance && res.data.allowance.limit){ allowanceEl.firstChild.textContent = res.data.allowance.remaining + ' of ' + res.data.allowance.limit + ' left this month · '; }
      toast('Insights generated successfully!', false);
    } else {
      toast(res.data.message || 'Analysis failed. Have a few more conversations first!', true);
      if(res.data && res.data.upgrade){ setTimeout(()=>switchTab('upgrade'), 1200); }
      if(btn) { btn.disabled = false; btn.innerHTML = '<i class="ti ti-bulb"></i><span>Generate Milestone Reflection</span>'; }
    }
  })
  .catch(() => {
    toast('Connection error.', true);
    if(btn) { btn.disabled = false; btn.innerHTML = '<i class="ti ti-bulb"></i><span>Generate Milestone Reflection</span>'; }
  });
}

function escHTML(t){ if(!t)return''; return String(t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

/* INTAKE LOGIC */
const isNewUser = <?php echo $is_new_user ? 'true' : 'false'; ?>;
if(isNewUser) {
  setTimeout(() => {
    document.getElementById('intake-overlay').classList.add('active');
  }, 600);
}

function nextIntake(step) {
  document.querySelectorAll('.intake-step').forEach(el => el.classList.remove('active'));
  document.querySelectorAll('.intake-dot').forEach((el, index) => {
    el.classList.toggle('active', index < step);
  });
  document.getElementById('step-' + step).classList.add('active');
}

function skipIntake() {
  document.getElementById('intake-overlay').classList.remove('active');
  fetch(KOUNSELIA.ajaxUrl, {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams({action:'kounselia_submit_intake',nonce:KOUNSELIA.nonce,q1:'',q2:''})
  });
}

function finishIntake() {
  const btn = document.getElementById('intake-finish-btn');
  const q1 = document.getElementById('intake-q1').value.trim();
  const q2 = document.getElementById('intake-q2').value.trim();
  
  btn.innerHTML = '<i class="ti ti-loader-2" style="animation:spin 1s linear infinite"></i> Processing...';
  btn.disabled = true;

  fetch(KOUNSELIA.ajaxUrl, {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams({action:'kounselia_submit_intake',nonce:KOUNSELIA.nonce,q1,q2})
  }).then(() => {
    document.getElementById('intake-overlay').classList.remove('active');
    toast('Your private space is ready.', false);
    // Reload slightly delayed to fetch the newly created memory profile into the UI
    setTimeout(() => window.location.reload(), 1500);
  }).catch(() => {
    btn.innerHTML = 'Finish & Start';
    btn.disabled = false;
    toast('Something went wrong. Please try again.', true);
  });
}

/* ---------------- BOOK A PROFESSIONAL ---------------- */

let bookingProfessionalId = null;
let bookingSelectedSlot = null;
let bookingMode = 'book'; // 'book' | 'reschedule'
let rescheduleBookingId = null;

document.querySelectorAll('.js-book-pro').forEach(function(tile){
  tile.addEventListener('click', function(){
    openBooking(parseInt(tile.dataset.proId, 10), tile.dataset.proName);
  });
});

document.querySelectorAll('.js-reschedule').forEach(function(link){
  link.addEventListener('click', function(){
    openReschedule(parseInt(link.dataset.bookingId, 10), parseInt(link.dataset.proId, 10), link.dataset.otherName);
  });
});

function openBooking(professionalId, name){
  bookingMode = 'book';
  rescheduleBookingId = null;
  bookingProfessionalId = professionalId;
  bookingSelectedSlot = null;
  document.getElementById('book-modal-name').textContent = name;
  document.getElementById('book-note').value = '';
  document.getElementById('book-note-field').style.display = 'none';
  document.getElementById('book-recurring-field').style.display = 'flex';
  document.getElementById('book-recurring').checked = false;
  document.getElementById('book-confirm-btn').style.display = 'none';
  document.getElementById('book-confirm-btn').textContent = 'Confirm booking';
  document.getElementById('book-slots').innerHTML = '<p style="color:var(--text3);font-size:14px">Loading available times...</p>';
  document.getElementById('book-overlay').classList.add('active');
  loadBookingSlots(professionalId, 0);
}

function openReschedule(bookingId, professionalId, name){
  bookingMode = 'reschedule';
  rescheduleBookingId = bookingId;
  bookingProfessionalId = professionalId;
  bookingSelectedSlot = null;
  document.getElementById('book-modal-name').textContent = 'Reschedule with ' + name;
  document.getElementById('book-note-field').style.display = 'none';
  document.getElementById('book-recurring-field').style.display = 'none';
  document.getElementById('book-confirm-btn').style.display = 'none';
  document.getElementById('book-confirm-btn').textContent = 'Confirm new time';
  document.getElementById('book-slots').innerHTML = '<p style="color:var(--text3);font-size:14px">Loading available times...</p>';
  document.getElementById('book-overlay').classList.add('active');
  loadBookingSlots(professionalId, bookingId);
}

function loadBookingSlots(professionalId, rescheduleId){
  const body = { action: 'kounselia_get_professional_slots', nonce: KOUNSELIA.nonce, professional_id: professionalId };
  if (rescheduleId) body.reschedule_booking_id = rescheduleId;

  fetch(KOUNSELIA.ajaxUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(body)
  })
  .then(r => r.json())
  .then(res => {
    const wrap = document.getElementById('book-slots');
    if (!res.success) {
      wrap.innerHTML = '<p style="color:var(--rose);font-size:14px">' + ((res.data && res.data.message) || 'Could not load availability.') + '</p>';
      return;
    }
    const slots = res.data.slots || [];
    if (!slots.length) {
      wrap.innerHTML = '<p style="color:var(--text3);font-size:14px">This professional has no open times right now. Please check back soon.</p>';
      return;
    }

    const byDay = {};
    slots.forEach(function(slot){
      const d = new Date(slot.replace(' ', 'T'));
      const dayKey = d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
      (byDay[dayKey] = byDay[dayKey] || []).push({ raw: slot, time: d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) });
    });

    wrap.innerHTML = '';
    Object.keys(byDay).forEach(function(dayKey){
      const dayBlock = document.createElement('div');
      dayBlock.style.marginBottom = '14px';
      const label = document.createElement('div');
      label.style.cssText = 'font-size:12.5px;font-weight:600;color:var(--text3);margin-bottom:8px;text-transform:uppercase;letter-spacing:.03em';
      label.textContent = dayKey;
      dayBlock.appendChild(label);

      const row = document.createElement('div');
      row.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px';
      byDay[dayKey].forEach(function(s){
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = s.time;
        btn.dataset.slot = s.raw;
        btn.style.cssText = 'padding:9px 14px;border-radius:10px;border:1.5px solid var(--border);background:var(--bg);color:var(--text);font-family:inherit;font-size:13px;cursor:pointer;transition:all .15s ease';
        btn.onclick = function(){
          document.querySelectorAll('#book-slots button').forEach(function(b){ b.style.borderColor = 'var(--border)'; b.style.background = 'var(--bg)'; b.style.color = 'var(--text)'; });
          btn.style.borderColor = 'var(--accent)'; btn.style.background = 'var(--accent-light)'; btn.style.color = 'var(--accent)';
          bookingSelectedSlot = s.raw;
          if (bookingMode === 'book') document.getElementById('book-note-field').style.display = 'block';
          document.getElementById('book-confirm-btn').style.display = 'inline-flex';
        };
        row.appendChild(btn);
      });
      dayBlock.appendChild(row);
      wrap.appendChild(dayBlock);
    });
  })
  .catch(() => {
    document.getElementById('book-slots').innerHTML = '<p style="color:var(--rose);font-size:14px">Something went wrong. Please try again.</p>';
  });
}

function closeBooking(){
  document.getElementById('book-overlay').classList.remove('active');
  bookingProfessionalId = null;
  bookingSelectedSlot = null;
  rescheduleBookingId = null;
}

function confirmBooking(){
  if (!bookingProfessionalId || !bookingSelectedSlot) return;
  if (bookingMode === 'reschedule') { confirmReschedule(); return; }

  const btn = document.getElementById('book-confirm-btn');
  btn.disabled = true;
  btn.textContent = 'Taking you to payment...';

  fetch(KOUNSELIA.ajaxUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      action: 'kounselia_create_booking',
      nonce: KOUNSELIA.nonce,
      professional_id: bookingProfessionalId,
      scheduled_start: bookingSelectedSlot,
      note: document.getElementById('book-note').value,
      make_recurring: document.getElementById('book-recurring').checked ? '1' : ''
    })
  })
  .then(r => r.json())
  .then(res => {
    if (res.success && res.data.authorization_url) {
      // This slot is now reserved pending payment — leaving the page to
      // pay on Paystack is the point, not an error state to recover from.
      window.location.href = res.data.authorization_url;
    } else {
      btn.disabled = false;
      btn.textContent = 'Confirm booking';
      toast((res.data && res.data.message) || 'Could not book that slot.', true);
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.textContent = 'Confirm booking';
    toast('Something went wrong. Please try again.', true);
  });
}

function confirmReschedule(){
  const btn = document.getElementById('book-confirm-btn');
  btn.disabled = true;
  btn.textContent = 'Saving...';

  fetch(KOUNSELIA.ajaxUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      action: 'kounselia_reschedule_booking',
      nonce: KOUNSELIA.nonce,
      booking_id: rescheduleBookingId,
      scheduled_start: bookingSelectedSlot
    })
  })
  .then(r => r.json())
  .then(res => {
    btn.disabled = false;
    btn.textContent = 'Confirm new time';
    if (res.success) {
      closeBooking();
      toast('Session rescheduled.', false);
      setTimeout(() => window.location.reload(), 1000);
    } else {
      toast((res.data && res.data.message) || 'Could not reschedule that session.', true);
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.textContent = 'Confirm new time';
    toast('Something went wrong. Please try again.', true);
  });
}

function cancelMySeries(seriesId, linkEl){
  if (!confirm('Cancel your weekly sessions? Any already-booked future sessions will be cancelled and refunded.')) return;
  fetch(KOUNSELIA.ajaxUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'kounselia_cancel_series', nonce: KOUNSELIA.nonce, series_id: seriesId })
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      toast('Weekly sessions cancelled.', false);
      setTimeout(() => window.location.reload(), 1000);
    } else {
      toast((res.data && res.data.message) || 'Could not cancel that series.', true);
    }
  })
  .catch(() => toast('Something went wrong. Please try again.', true));
}

/* ---------------- RATE A SESSION ---------------- */

let ratingBookingId = null;
let ratingValue = 0;

document.querySelectorAll('.js-rate-session').forEach(function(link){
  link.addEventListener('click', function(){
    openRateModal(parseInt(link.dataset.bookingId, 10), link.dataset.otherName);
  });
});

function openRateModal(bookingId, name){
  ratingBookingId = bookingId;
  ratingValue = 0;
  document.getElementById('rate-modal-name').textContent = 'Rate your session with ' + name;
  document.getElementById('rate-comment').value = '';
  renderStars();
  document.getElementById('rate-overlay').classList.add('active');
}

function closeRateModal(){
  document.getElementById('rate-overlay').classList.remove('active');
  ratingBookingId = null;
}

function renderStars(){
  const el = document.getElementById('rate-stars');
  el.innerHTML = '';
  for (let i = 1; i <= 5; i++) {
    const span = document.createElement('span');
    span.textContent = '★';
    span.style.color = i <= ratingValue ? 'var(--gold)' : 'var(--border)';
    span.onclick = function(){ ratingValue = i; renderStars(); };
    el.appendChild(span);
  }
}
renderStars();

function submitReview(){
  if (!ratingBookingId || !ratingValue) {
    toast('Please pick a star rating first.', true);
    return;
  }
  const btn = document.getElementById('rate-submit-btn');
  btn.disabled = true;
  btn.textContent = 'Submitting...';

  fetch(KOUNSELIA.ajaxUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      action: 'kounselia_submit_review',
      nonce: KOUNSELIA.nonce,
      booking_id: ratingBookingId,
      rating: ratingValue,
      comment: document.getElementById('rate-comment').value
    })
  })
  .then(r => r.json())
  .then(res => {
    btn.disabled = false;
    btn.textContent = 'Submit rating';
    if (res.success) {
      closeRateModal();
      toast('Thanks for the feedback.', false);
      setTimeout(() => window.location.reload(), 1000);
    } else {
      toast((res.data && res.data.message) || 'Could not submit that rating.', true);
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.textContent = 'Submit rating';
    toast('Something went wrong. Please try again.', true);
  });
}

function cancelMyBooking(bookingId, linkEl){
  if (!confirm("Cancel this session? You'll be refunded, and the professional will be notified.")) return;
  fetch(KOUNSELIA.ajaxUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'kounselia_cancel_booking', nonce: KOUNSELIA.nonce, booking_id: bookingId })
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      const row = document.querySelector('#my-booking-list [data-booking-id="' + bookingId + '"]');
      if (row) row.remove();
      toast('Booking cancelled.', false);
    } else {
      toast((res.data && res.data.message) || 'Could not cancel that booking.', true);
    }
  })
  .catch(() => toast('Something went wrong. Please try again.', true));
}

/* ---------------- BOOKING CHAT ---------------- */

let chatBookingId = null;
let chatPollTimer = null;

document.querySelectorAll('.js-booking-chat').forEach(function(link){
  link.addEventListener('click', function(){
    openBookingChat(parseInt(link.dataset.bookingId, 10), link.dataset.otherName);
  });
});

function openBookingChat(bookingId, otherName){
  chatBookingId = bookingId;
  document.getElementById('chat-modal-name').textContent = otherName;
  document.getElementById('chat-messages').innerHTML = '<p style="text-align:center;color:var(--text3);font-size:13px">Loading...</p>';
  document.getElementById('chat-overlay').classList.add('active');
  loadBookingChat();
  clearInterval(chatPollTimer);
  chatPollTimer = setInterval(loadBookingChat, 4000);
}

function closeBookingChat(){
  document.getElementById('chat-overlay').classList.remove('active');
  clearInterval(chatPollTimer);
  chatPollTimer = null;
  chatBookingId = null;
}

function loadBookingChat(){
  if (!chatBookingId) return;
  fetch(KOUNSELIA.ajaxUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'kounselia_get_booking_messages', nonce: KOUNSELIA.nonce, booking_id: chatBookingId })
  })
  .then(r => r.json())
  .then(res => {
    if (!res.success) return;
    const wrap = document.getElementById('chat-messages');
    const wasAtBottom = (wrap.scrollTop + wrap.clientHeight) >= (wrap.scrollHeight - 20);
    wrap.innerHTML = '';
    if (!res.data.messages.length) {
      wrap.innerHTML = '<p style="text-align:center;color:var(--text3);font-size:13px">No messages yet. Say hello.</p>';
    } else {
      res.data.messages.forEach(function(m){
        const bubble = document.createElement('div');
        bubble.className = 'chat-bubble ' + (m.is_mine ? 'mine' : 'theirs');
        const text = document.createElement('div');
        text.textContent = m.content;
        bubble.appendChild(text);
        const time = document.createElement('div');
        time.className = 'chat-bubble-time';
        time.textContent = new Date(m.created_at.replace(' ', 'T')).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
        bubble.appendChild(time);
        wrap.appendChild(bubble);
      });
    }
    if (wasAtBottom) wrap.scrollTop = wrap.scrollHeight;
  })
  .catch(() => {});
}

document.getElementById('chat-form').addEventListener('submit', function(e){
  e.preventDefault();
  const input = document.getElementById('chat-input');
  const content = input.value.trim();
  if (!content || !chatBookingId) return;
  input.value = '';
  fetch(KOUNSELIA.ajaxUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'kounselia_send_booking_message', nonce: KOUNSELIA.nonce, booking_id: chatBookingId, content })
  })
  .then(r => r.json())
  .then(() => loadBookingChat())
  .catch(() => {});
});

/* ---------------- NOTIFICATIONS ---------------- */

function checkUnreadNotifications(){
  fetch(KOUNSELIA.ajaxUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'kounselia_get_unread_notification_count', nonce: KOUNSELIA.nonce })
  })
  .then(r => r.json())
  .then(res => {
    if (!res.success) return;
    const show = res.data.count > 0 ? 'block' : 'none';
    const dot1 = document.getElementById('notif-dot');
    const dot2 = document.getElementById('notif-dot-desktop');
    if (dot1) dot1.style.display = show;
    if (dot2) dot2.style.display = show;
  })
  .catch(() => {});
}
checkUnreadNotifications();

function openNotifications(){
  document.getElementById('notif-overlay').classList.add('active');
  document.getElementById('notif-list').innerHTML = '<p style="color:var(--text3);font-size:13px">Loading...</p>';

  fetch(KOUNSELIA.ajaxUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'kounselia_get_notifications', nonce: KOUNSELIA.nonce })
  })
  .then(r => r.json())
  .then(res => {
    const wrap = document.getElementById('notif-list');
    if (!res.success) {
      wrap.innerHTML = '<p style="color:var(--rose);font-size:13px">Could not load notifications.</p>';
      return;
    }
    const items = res.data.notifications || [];
    if (!items.length) {
      wrap.innerHTML = '<p style="color:var(--text3);font-size:13px">Nothing here yet.</p>';
      return;
    }
    wrap.innerHTML = '';
    items.forEach(function(n){
      const a = document.createElement('a');
      a.className = 'notif-item';
      a.href = n.url || 'javascript:void(0)';
      const time = new Date(n.created_at.replace(' ', 'T')).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
      a.innerHTML = '<div class="notif-title">' + escHTML(n.title) + '</div>' + (n.body ? '<div class="notif-body">' + escHTML(n.body) + '</div>' : '') + '<div class="notif-time">' + time + '</div>';
      wrap.appendChild(a);
    });
    const dot1 = document.getElementById('notif-dot');
    const dot2 = document.getElementById('notif-dot-desktop');
    if (dot1) dot1.style.display = 'none';
    if (dot2) dot2.style.display = 'none';
  })
  .catch(() => {
    document.getElementById('notif-list').innerHTML = '<p style="color:var(--rose);font-size:13px">Something went wrong.</p>';
  });
}

function closeNotifications(){
  document.getElementById('notif-overlay').classList.remove('active');
}
</script>
</body>
</html>
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

$documents          = function_exists( 'kounselia_get_professional_documents' ) ? kounselia_get_professional_documents( $application->id ) : array();
$availability_rules = function_exists( 'kounselia_get_availability_rules' ) ? kounselia_get_availability_rules( $application->id ) : array();
$upcoming_bookings  = function_exists( 'kounselia_get_professional_bookings' ) ? kounselia_get_professional_bookings( $application->id ) : array();
$rating_summary     = function_exists( 'kounselia_get_professional_rating_summary' ) ? kounselia_get_professional_rating_summary( $application->id ) : array( 'average' => 0, 'count' => 0 );
$reviews            = function_exists( 'kounselia_get_professional_reviews' ) ? kounselia_get_professional_reviews( $application->id ) : array();
$doc_type_labels    = array( 'license' => 'License / credential', 'id' => 'Government ID', 'certificate' => 'Certificate', 'other' => 'Other document' );
$day_labels         = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );

$balance         = function_exists( 'kounselia_get_professional_balance' ) ? kounselia_get_professional_balance( $application->id ) : array( 'available' => 0, 'total_earned' => 0, 'paid_out' => 0 );
$payout_account  = function_exists( 'kounselia_get_payout_account' ) ? kounselia_get_payout_account( $application->id ) : null;
$payout_history  = function_exists( 'kounselia_get_payout_history' ) ? kounselia_get_payout_history( $application->id ) : array();
$payout_banks    = function_exists( 'kounselia_paystack_list_banks' ) ? kounselia_paystack_list_banks() : array();
$payout_status_labels = array( 'pending' => 'Processing', 'success' => 'Paid', 'failed' => 'Failed' );

// Articles for the Journal (includes/articles.php + community.php).
$article_access   = function_exists( 'kounselia_article_access' ) ? kounselia_article_access( $application ) : array( 'allowed' => false, 'mode' => '', 'message' => '' );
$article_settings = function_exists( 'kounselia_article_settings' ) ? kounselia_article_settings() : array();
$articles         = function_exists( 'kounselia_pro_articles' ) ? kounselia_pro_articles( $application->id ) : array();
$article_followers = function_exists( 'kounselia_follower_count' ) ? kounselia_follower_count( $application->id ) : 0;
$article_totals   = array( 'views' => 0, 'loves' => 0, 'comments' => 0 );
foreach ( $articles as $a ) {
    $article_totals['views']    += (int) $a->views;
    $article_totals['loves']    += (int) $a->love_count;
    $article_totals['comments'] += (int) $a->comment_count;
}
$show_articles_tab = ! empty( $article_settings['enabled'] ) || $articles;

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
.notif-dot{position:absolute;top:4px;right:4px;width:8px;height:8px;border-radius:50%;background:var(--rose)}
.notif-item{display:block;padding:14px 16px;border-radius:12px;background:var(--bg);border:1px solid var(--border);text-decoration:none;color:inherit}
.notif-item .notif-title{font-size:13.5px;font-weight:600;color:var(--text)}
.notif-item .notif-body{font-size:12.5px;color:var(--text2);margin-top:3px}
.notif-item .notif-time{font-size:11px;color:var(--text3);margin-top:6px}
.reschedule-day{margin-bottom:14px}
.reschedule-day-label{font-size:12.5px;font-weight:600;color:var(--text3);margin-bottom:8px;text-transform:uppercase;letter-spacing:.03em}
.reschedule-slot-row{display:flex;flex-wrap:wrap;gap:8px}
.reschedule-slot-btn{padding:9px 14px;border-radius:10px;border:1.5px solid var(--border);background:var(--bg);color:var(--text);font-family:inherit;font-size:13px;cursor:pointer;transition:all .15s ease}
.reschedule-slot-btn.selected{border-color:var(--accent);background:var(--accent-light);color:var(--accent)}
.reschedule-confirm{width:100%;margin-top:14px;padding:12px;border:none;border-radius:12px;background:var(--accent);color:#fff;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit}
.reschedule-confirm:disabled{opacity:.5;cursor:not-allowed}
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
.status-banner.rejected,.status-banner.suspended{background:var(--rose-light);color:var(--rose)}
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

.avatar-card{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:28px 22px;text-align:center;box-shadow:var(--shadow-sm);margin-bottom:24px}
.avatar-wrap{position:relative;width:104px;height:104px;margin:0 auto 16px}
.avatar-img{width:104px;height:104px;border-radius:50%;background:var(--accent-light);color:var(--accent);display:flex;align-items:center;justify-content:center;font-size:38px;font-weight:600;overflow:hidden;border:3px solid var(--surface);box-shadow:var(--shadow-md)}
.avatar-img img{width:100%;height:100%;object-fit:cover}
.avatar-edit{position:absolute;bottom:0;right:0;width:32px;height:32px;border-radius:50%;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;border:3px solid var(--surface);cursor:pointer;font-size:14px}
.avatar-edit input{display:none}
.avatar-card h4{font-size:15px;font-weight:600;margin-bottom:2px}
.avatar-card p{font-size:12.5px;color:var(--text3)}

.pro-card{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:32px}
.form-field textarea{width:100%;padding:12px 14px;border:1.5px solid var(--border);border-radius:12px;font-family:inherit;font-size:14.5px;color:var(--text);background:var(--bg);outline:none;resize:vertical;min-height:90px}
.form-field textarea:focus{border-color:var(--accent);background:var(--surface);box-shadow:0 0 0 4px var(--accent-light)}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media (max-width:520px){.form-row{grid-template-columns:1fr}}
.rate-prefix{display:flex;align-items:center;gap:8px}
.rate-prefix span{font-weight:600;color:var(--text2)}
.readonly-note{font-size:12.5px;color:var(--text3);background:var(--bg2,#F8FAFC);border-radius:10px;padding:10px 12px;margin-bottom:20px}
.pro-card .pro-submit{width:100%;margin-top:20px;padding:14px;border:none;border-radius:14px;background:var(--accent);color:#fff;font-size:15px;font-weight:600;cursor:pointer;font-family:inherit}
.pro-card .pro-submit:hover{background:var(--accent2)}
.pro-submit:disabled{opacity:.6;cursor:not-allowed}
.pro-msg{margin-top:14px;font-size:13.5px;padding:12px 14px;border-radius:10px;display:none}
.pro-msg.error{display:block;background:var(--rose-light);color:var(--rose)}
.pro-msg.notice{display:block;background:var(--sage-light);color:var(--sage)}

.form-field select,.form-field input[type="time"],.avail-row input[type="time"]{width:100%;padding:12px 14px;border:1.5px solid var(--border);border-radius:12px;font-family:inherit;font-size:14.5px;color:var(--text);background:var(--bg);outline:none}
.form-field select:focus,.form-field input[type="time"]:focus,.avail-row input[type="time"]:focus{border-color:var(--accent);background:var(--surface);box-shadow:0 0 0 4px var(--accent-light)}
.form-field input[type="file"]{width:100%;padding:10px 14px;border:1.5px dashed var(--border);border-radius:12px;font-family:inherit;font-size:13px;color:var(--text2);background:var(--bg)}

.doc-list{display:grid;gap:10px}
.doc-row{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:12px;background:var(--bg);border:1px solid var(--border);font-size:13.5px}
.doc-row i.ti-file-text{font-size:18px;color:var(--accent);flex-shrink:0}
.doc-meta{flex:1;min-width:0}
.doc-name{font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.doc-type{font-size:12px;color:var(--text3)}
.doc-view{font-size:12.5px;font-weight:600;color:var(--accent);text-decoration:none;flex-shrink:0}
.doc-remove{border:none;background:none;color:var(--text3);cursor:pointer;padding:4px;flex-shrink:0}
.doc-remove:hover{color:var(--rose)}

.avail-grid{display:grid;gap:10px;margin-bottom:20px}
.avail-row{display:grid;grid-template-columns:150px 1fr auto 1fr;align-items:center;gap:10px}
@media (max-width:560px){.avail-row{grid-template-columns:1fr;gap:8px}}
.avail-toggle{display:flex;align-items:center;gap:10px;font-size:14px;font-weight:500;color:var(--text)}
.avail-toggle input{width:18px;height:18px;accent-color:var(--accent)}
.avail-to{font-size:12.5px;color:var(--text3);text-align:center}
.avail-row input[type="time"]:disabled{opacity:.45}

.booking-list{display:grid;gap:10px}
.booking-row{display:flex;align-items:center;gap:14px;padding:16px 18px;border-radius:16px;background:var(--surface);border:1px solid var(--border);box-shadow:var(--shadow-sm)}
.booking-icon{width:38px;height:38px;border-radius:11px;background:var(--sage-light);color:var(--sage);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.booking-meta{flex:1;min-width:0}
.booking-when{font-weight:600;font-size:14.5px;color:var(--text)}
.weekly-tag{display:inline-block;margin-left:8px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:var(--accent);background:var(--accent-light);border-radius:999px;padding:2px 8px;vertical-align:middle}
.booking-with{font-size:13px;color:var(--text2);margin-top:2px}
.booking-note{font-size:12.5px;color:var(--text3);margin-top:4px;font-style:italic}
.booking-actions{display:flex;flex-direction:column;gap:6px;flex-shrink:0}
.booking-join,.booking-message,.booking-cancel{border:1px solid var(--border);background:none;color:var(--text2);font-size:12.5px;font-weight:600;padding:8px 14px;border-radius:10px;cursor:pointer;font-family:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:6px;justify-content:center;white-space:nowrap}
.booking-join{border-color:var(--sage);color:var(--sage)}
.booking-join:hover{background:var(--sage-light)}
.booking-join[hidden]{display:none}
.booking-message:hover{border-color:var(--accent);color:var(--accent)}
.booking-cancel:hover{border-color:var(--rose);color:var(--rose)}
@media (max-width:480px){.booking-row{flex-wrap:wrap}.booking-actions{flex-direction:row;flex-basis:100%;margin-top:10px}.booking-join,.booking-message,.booking-cancel{flex:1}}

.chat-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);display:none;align-items:flex-end;justify-content:center;z-index:9999}
.chat-overlay.active{display:flex}
.chat-modal{background:var(--surface);width:100%;max-width:480px;border-radius:20px 20px 0 0;display:flex;flex-direction:column;max-height:80vh}
@media (min-width:640px){.chat-overlay{align-items:center}.chat-modal{border-radius:20px;height:600px}}
.chat-modal-head{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border);flex-shrink:0}
.chat-modal-head h3{font-family:'Cormorant Garamond',serif;font-size:19px;font-weight:500}
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

.art-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px}
.art-head h2{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:23px}
.art-new{display:inline-flex;align-items:center;gap:8px;padding:11px 18px;border-radius:50px;background:var(--accent);color:#fff;text-decoration:none;font-size:14px;font-weight:500}
.art-new:hover{background:var(--accent2)}
.art-mode{font-size:13.5px;line-height:1.55;border-radius:14px;padding:12px 16px;margin-bottom:18px;background:var(--surface);border:1px solid var(--border);color:var(--text2);display:flex;gap:10px;align-items:flex-start}
.art-mode i{font-size:18px;color:var(--accent);flex-shrink:0;margin-top:1px}
.art-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:22px}
@media (max-width:720px){.art-stats{grid-template-columns:1fr 1fr}}
.art-stats .stat-card{padding:16px}
.art-stats .stat-num{font-size:24px}
.art-list{display:grid;gap:12px}
.art-card{display:flex;gap:14px;padding:14px;border-radius:16px;background:var(--surface);border:1px solid var(--border);box-shadow:var(--shadow-sm)}
.art-thumb{width:84px;height:84px;border-radius:12px;object-fit:cover;flex-shrink:0;background:linear-gradient(135deg,var(--accent-light),var(--gold-light));display:flex;align-items:center;justify-content:center;color:var(--accent);font-size:22px}
.art-body{flex:1;min-width:0}
.art-title{font-weight:600;font-size:15px;color:var(--text);line-height:1.35;word-break:break-word}
.art-meta{display:flex;gap:10px;flex-wrap:wrap;align-items:center;font-size:12.5px;color:var(--text3);margin-top:6px}
.art-meta i{font-size:14px;vertical-align:-2px}
.art-pill{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:3px 9px;border-radius:999px;background:var(--surface2);color:var(--text2)}
.art-pill.live{background:var(--sage-light);color:var(--sage)}
.art-pill.pending,.art-pill.live_pending{background:var(--gold-light);color:#8a5a12}
.art-pill.changes,.art-pill.rejected,.art-pill.removed{background:var(--rose-light);color:var(--rose)}
.art-note{margin-top:10px;font-size:13px;line-height:1.5;background:var(--bg);border-radius:10px;padding:10px 12px;color:var(--text2)}
.art-note b{color:var(--text)}
.art-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.art-actions a,.art-actions button{border:1px solid var(--border);background:none;color:var(--text2);font-size:12.5px;font-weight:600;padding:8px 12px;border-radius:10px;cursor:pointer;font-family:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:6px;min-height:36px}
.art-actions a:hover,.art-actions button:hover{border-color:var(--accent);color:var(--accent)}
.art-actions .danger:hover{border-color:var(--rose);color:var(--rose)}
@media (max-width:480px){.art-card{flex-direction:column}img.art-thumb{width:100%;height:140px}div.art-thumb{display:none}.art-stats .stat-icon{display:none}.art-stats .stat-card{padding:14px}}

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
      <div class="logo" style="display:flex;align-items:center;justify-content:space-between">
        <a href="/pro-dashboard.php" class="logo-link" style="display:inline-block;outline:none;">
          <img src="https://kounselia.com/img/Kounselia_Logo_IconMark_MidnightNavy.png" alt="Kounselia" class="site-logo" fetchpriority="high">
        </a>
        <button id="notif-bell-desktop" onclick="openNotifications()" aria-label="Notifications" style="position:relative;background:none;border:none;cursor:pointer;color:var(--text2);padding:6px"><i class="ti ti-bell" style="font-size:19px"></i><span class="notif-dot" id="notif-dot-desktop" style="display:none"></span></button>
      </div>
      <nav class="side-nav">
        <button class="nav-link js-nav active" id="desk-tab-overview" onclick="switchTab('overview')"><i class="ti ti-layout-dashboard"></i><span>Overview</span></button>
        <button class="nav-link js-nav" id="desk-tab-profile" onclick="switchTab('profile')"><i class="ti ti-user-edit"></i><span>Profile &amp; Rate</span></button>
        <button class="nav-link js-nav" id="desk-tab-bookings" onclick="switchTab('bookings')"><i class="ti ti-calendar-event"></i><span>Bookings</span></button>
<?php if ( $show_articles_tab ) : ?>
        <button class="nav-link js-nav" id="desk-tab-articles" onclick="switchTab('articles')"><i class="ti ti-feather"></i><span>Articles</span></button>
<?php endif; ?>
        <button class="nav-link js-nav" id="desk-tab-earnings" onclick="switchTab('earnings')"><i class="ti ti-cash"></i><span>Earnings</span></button>
      </nav>
      <div class="nav-divider"></div>
      <a class="switch-link" href="/dashboard.php?as=client"><i class="ti ti-switch-horizontal"></i><span>Switch to client view</span></a>
    </div>
    <div class="side-foot">
      <div class="side-user">
        <div class="side-av" id="side-av"><?php echo $avatar_url ? '<img src="' . esc_url( $avatar_url ) . '" alt="">' : esc_html( $initial ); ?></div>
        <div class="side-user-meta">
          <div class="side-user-name" id="side-user-name"><?php echo esc_html( $display_name ); ?></div>
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
      <button class="mobile-signout" id="notif-bell" onclick="openNotifications()" aria-label="Notifications" style="position:relative"><i class="ti ti-bell"></i><span class="notif-dot" id="notif-dot" style="display:none"></span></button>
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
        <?php if ( $rating_summary['count'] > 0 ) : ?><span class="verified-pill">★ <?php echo esc_html( number_format( $rating_summary['average'], 1 ) ); ?> (<?php echo (int) $rating_summary['count']; ?>)</span><?php endif; ?>
      </div>
      <h1>Welcome back, <em id="welcome-first-name"><?php echo esc_html( $first_name ); ?></em>.</h1>
      <p><?php echo $is_verified
        ? 'This is what clients will see, and your rate is entirely yours to set — Kounselia never changes it for you.'
        : 'Get your profile ready while your application is reviewed. It stays private until you\'re verified.'; ?></p>
      <div class="welcome-actions">
        <button class="btn-w primary" onclick="switchTab('profile')"><i class="ti ti-user-edit"></i> Edit profile &amp; rate</button>
      </div>
    </section>

    <?php if ( 'pending' === $application->status ) : ?>
      <div class="status-banner pending"><strong>Under review</strong>We're checking your documents — we'll email you once there's a decision, usually within a few business days.</div>
    <?php elseif ( 'suspended' === $application->status ) : ?>
      <div class="status-banner suspended"><strong>Account suspended</strong><?php echo $application->suspended_reason ? esc_html( $application->suspended_reason ) : 'Your account is under review.'; ?><br>Clients can't find or book you while your account is suspended. Questions? Email <a href="mailto:hello@kounselia.com" style="color:inherit;text-decoration:underline">hello@kounselia.com</a>.</div>
    <?php elseif ( 'rejected' === $application->status ) : ?>
      <div class="status-banner rejected"><strong>Not approved yet</strong><?php echo $application->rejection_reason ? esc_html( $application->rejection_reason ) : 'Update your documents and reapply when you\'re ready.'; ?> — <a href="/apply.php" style="color:inherit;text-decoration:underline">reapply with new documents</a>.</div>
    <?php else : ?>
      <div class="status-banner verified"><strong>Verified</strong>Your profile is live for clients.</div>
    <?php endif; ?>

    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--accent-light);color:var(--accent);"><i class="ti ti-cash"></i></div>
        <div class="stat-num"><?php echo ( defined( 'KOUNSELIA_FREE_ALWAYS' ) && (int) $application->free_sessions_per_client >= KOUNSELIA_FREE_ALWAYS ) ? 'Free' : ( $application->rate_amount ? '₦' . esc_html( number_format( (float) $application->rate_amount ) ) : 'Not set' ); ?></div>
        <div class="stat-label">Rate per session</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--gold-light);color:var(--gold);"><i class="ti ti-award"></i></div>
        <div class="stat-num"><?php echo $application->years_experience ? esc_html( $application->years_experience ) : '—'; ?></div>
        <div class="stat-label">Years of experience</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--sage-light);color:var(--sage);"><i class="ti ti-calendar-event"></i></div>
        <div class="stat-num"><?php echo (int) count( $upcoming_bookings ); ?></div>
        <div class="stat-label">Upcoming bookings</div>
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
        <div class="capability-row <?php echo $is_verified ? 'unlocked' : 'locked'; ?>"><i class="ti ti-calendar-event"></i> Receive client bookings<span class="tag"><?php echo $is_verified ? 'Available' : 'Locked until verified'; ?></span></div>
        <div class="capability-row <?php echo $is_verified ? 'unlocked' : 'locked'; ?>"><i class="ti ti-cash"></i> Earnings &amp; payouts<span class="tag"><?php echo $is_verified ? 'Available' : 'Locked until verified'; ?></span></div>
        <?php if ( ! empty( $article_settings['enabled'] ) ) : ?>
          <div class="capability-row <?php echo $article_access['allowed'] ? 'unlocked' : 'locked'; ?>"><i class="ti ti-feather"></i> Write articles for the Journal<span class="tag"><?php echo $article_access['allowed'] ? 'Available' : ( $is_verified ? 'Not available' : 'Locked until verified' ); ?></span></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- PROFILE & RATE -->
  <div class="view-panel" id="view-profile">
    <div class="section-head">
      <h2>Profile &amp; rate</h2>
      <span class="section-sub">This is what clients see once you're verified</span>
    </div>

    <div class="avatar-card">
      <div class="avatar-wrap">
        <div class="avatar-img" id="pro-avatar-img"><?php echo $avatar_url ? '<img src="' . esc_url( $avatar_url ) . '" alt="">' : esc_html( $initial ); ?></div>
        <label class="avatar-edit">
          <i class="ti ti-camera"></i>
          <input type="file" id="pro-avatar-input" accept="image/png,image/jpeg,image/webp">
        </label>
      </div>
      <h4><?php echo esc_html( $display_name ); ?></h4>
      <p>Clients see this photo on your public profile and everywhere else you appear on Kounselia.</p>
      <div class="inline-msg" id="pro-avatar-msg"></div>
    </div>

    <div class="pro-card">
      <?php if ( $application->license_number ) : ?>
        <div class="readonly-note">Verified against license/registration <strong><?php echo esc_html( $application->license_number ); ?></strong>. To change your credentials, contact support — that requires re-verification.</div>
      <?php endif; ?>

      <form id="pro-form">
        <div class="form-field"><label>Full name</label><input type="text" name="full_name" id="pro-name" value="<?php echo esc_attr( $display_name ); ?>" placeholder="Your full name, as clients should see it"></div>
        <div class="form-field">
          <label>Professional title</label>
          <input type="text" name="title" id="pro-title" value="<?php echo esc_attr( $application->title ); ?>" placeholder="e.g. Licensed Clinical Psychologist">
          <div class="section-sub" style="margin-top:6px">Shown next to your name everywhere clients see you — your role or qualification, not just "Dr" (your name already covers that).</div>
        </div>
        <div class="form-row">
          <div class="form-field"><label>Specialty</label><input type="text" name="specialty" id="pro-specialty" value="<?php echo esc_attr( $application->specialty ); ?>"></div>
          <div class="form-field"><label>Years of experience</label><input type="number" name="years_experience" id="pro-years" min="0" max="60" value="<?php echo esc_attr( $application->years_experience ); ?>"></div>
        </div>
        <div class="form-field"><label>Bio</label><textarea name="bio" id="pro-bio"><?php echo esc_textarea( $application->bio ); ?></textarea></div>
        <div class="form-field">
          <label>Your rate per session</label>
          <div class="rate-prefix"><span>₦</span><input type="number" name="rate_amount" id="pro-rate" min="0" step="0.01" value="<?php echo esc_attr( $application->rate_amount ); ?>"></div>
          <?php if ( function_exists( 'kounselia_usd_ngn_rate' ) && $application->rate_amount ) : ?>
            <div class="section-sub" style="margin-top:6px">Clients outside Nigeria see about <?php echo esc_html( kounselia_format_money( kounselia_convert_ngn( $application->rate_amount, 'USD' ), 'USD' ) ); ?>. You are always paid in naira.</div>
          <?php endif; ?>
        </div>
        <?php if ( function_exists( 'kounselia_free_session_options' ) ) : ?>
        <div class="form-field">
          <label>Free sessions</label>
          <select name="free_sessions_per_client" id="pro-free">
            <?php foreach ( kounselia_free_session_options() as $kounselia_v => $kounselia_label ) : ?>
              <option value="<?php echo (int) $kounselia_v; ?>" <?php selected( (int) $application->free_sessions_per_client, (int) $kounselia_v ); ?>><?php echo esc_html( $kounselia_label ); ?></option>
            <?php endforeach; ?>
          </select>
          <div class="section-sub" style="margin-top:6px">A free first session is a gentle way for someone to see if you're the right fit. Each client gets the free sessions once; after that they pay your rate. Choose "every session" if you offer your time pro bono (your rate isn't needed then).</div>
        </div>
        <?php endif; ?>

        <button type="submit" class="pro-submit" id="pro-save-btn">Save changes</button>
        <div class="pro-msg" id="pro-msg"></div>
      </form>
    </div>

    <?php if ( $is_verified && function_exists( 'kounselia_video_own_allowed' ) ) :
        $kounselia_video_ok = kounselia_video_own_allowed( $application ); ?>
    <div class="section" style="margin-top:32px">
      <div class="section-head">
        <h2>Video calls</h2>
        <span class="section-sub">Where your sessions happen</span>
      </div>
      <div class="pro-card">
        <?php if ( ! $kounselia_video_ok ) : ?>
          <p style="font-size:14px;color:var(--text2);line-height:1.6">Your sessions use Kounselia's private video room: nothing to install, and it opens right from your dashboard. Prefer to use your own Zoom, Google Meet, Teams or Whereby link? Email <a href="mailto:hello@kounselia.com">hello@kounselia.com</a> and we'll switch it on for you.</p>
        <?php else : ?>
          <div class="form-field">
            <label class="avail-toggle" style="margin-bottom:10px"><input type="radio" name="video-mode" value="kounselia" <?php checked( 'own' !== $application->video_mode ); ?>> Kounselia's private video room (recommended)</label>
            <label class="avail-toggle"><input type="radio" name="video-mode" value="own" <?php checked( 'own', $application->video_mode ); ?>> My own meeting link</label>
          </div>
          <div class="form-field" id="video-link-field" style="<?php echo 'own' === $application->video_mode ? '' : 'display:none'; ?>">
            <label>Meeting link</label>
            <input type="url" id="video-link" value="<?php echo esc_attr( (string) $application->video_link ); ?>" placeholder="https://zoom.us/j/… or https://meet.google.com/…" inputmode="url" autocomplete="off" style="width:100%;padding:12px 14px;border:1.5px solid var(--border);border-radius:12px;font-family:inherit;font-size:15px;background:var(--bg)">
            <div class="section-sub" style="margin-top:6px;line-height:1.5">Zoom, Google Meet, Microsoft Teams or Whereby. Clients only get it by pressing Join on Kounselia at session time; it's never emailed. Please turn on a waiting room or passcode, or set a separate link for each session (from Bookings).</div>
          </div>
          <p class="section-sub" style="line-height:1.55;margin-bottom:6px">Bookings, payments and messages always stay on Kounselia. Kounselia's safety checks only cover what's said on Kounselia.</p>
          <button type="button" class="pro-submit" id="video-save-btn">Save video setting</button>
          <div class="pro-msg" id="video-msg"></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="section" style="margin-top:32px">
      <div class="section-head">
        <h2>Documents</h2>
        <span class="section-sub">Up to 10 files — add a certificate or a second ID any time</span>
      </div>
      <div class="pro-card">
        <div class="doc-list" id="doc-list">
          <?php foreach ( $documents as $doc ) : ?>
          <div class="doc-row" data-doc-id="<?php echo (int) $doc->id; ?>">
            <i class="ti ti-file-text"></i>
            <div class="doc-meta">
              <div class="doc-name"><?php echo esc_html( $doc->original_filename ); ?></div>
              <div class="doc-type"><?php echo esc_html( $doc_type_labels[ $doc->doc_type ] ?? ucfirst( $doc->doc_type ) ); ?></div>
            </div>
            <a class="doc-view" href="<?php echo esc_url( kounselia_professional_document_url( $doc->id ) ); ?>" target="_blank" rel="noopener">View</a>
            <button type="button" class="doc-remove" onclick="removeDocument(<?php echo (int) $doc->id; ?>, this)"><i class="ti ti-trash"></i></button>
          </div>
          <?php endforeach; ?>
          <?php if ( empty( $documents ) ) : ?>
          <p class="section-sub" id="doc-empty">No documents on file yet.</p>
          <?php endif; ?>
        </div>

        <form id="doc-form" style="margin-top:18px">
          <div class="form-row">
            <div class="form-field">
              <label>Document type</label>
              <select name="doc_type" id="doc-type">
                <option value="certificate">Certificate</option>
                <option value="id">Government ID</option>
                <option value="license">License / credential</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="form-field file-field">
              <label>File (PDF, JPG, or PNG, up to 8MB)</label>
              <input type="file" name="document" id="doc-file" accept=".pdf,.jpg,.jpeg,.png">
            </div>
          </div>
          <button type="submit" class="pro-submit" id="doc-save-btn">Add document</button>
          <div class="pro-msg" id="doc-msg"></div>
        </form>
      </div>
    </div>

    <div class="section" style="margin-top:32px">
      <div class="section-head">
        <h2>Client reviews</h2>
        <span class="section-sub"><?php echo $rating_summary['count'] > 0 ? '★ ' . esc_html( number_format( $rating_summary['average'], 1 ) ) . ' average across ' . (int) $rating_summary['count'] . ' review' . ( 1 === $rating_summary['count'] ? '' : 's' ) : 'No reviews yet'; ?></span>
      </div>
      <?php if ( empty( $reviews ) ) : ?>
        <div class="coming-soon">
          <div class="coming-soon-icon"><i class="ti ti-star"></i></div>
          <h3>No reviews yet</h3>
          <p>Clients can rate a session once it's happened. Reviews will show up here, and your average rating appears on your public profile.</p>
        </div>
      <?php else : ?>
        <div class="doc-list">
          <?php foreach ( $reviews as $review ) : ?>
          <div class="doc-row" style="align-items:flex-start">
            <i class="ti ti-star" style="color:var(--gold)"></i>
            <div class="doc-meta">
              <div class="doc-name"><?php echo str_repeat( '★', (int) $review->rating ) . str_repeat( '☆', 5 - (int) $review->rating ); ?> — <?php echo esc_html( $review->client_name ?: 'A client' ); ?></div>
              <?php if ( $review->comment ) : ?><div class="doc-type" style="white-space:normal"><?php echo esc_html( $review->comment ); ?></div><?php endif; ?>
              <div class="doc-type"><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $review->created_at ) ) ); ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if ( function_exists( 'kounselia_professional_is_public' ) ) :
        $kounselia_is_public = kounselia_professional_is_public( get_current_user_id() ); ?>
    <div class="pro-card" style="margin-top:20px">
      <label style="display:flex;gap:14px;align-items:flex-start;cursor:pointer">
        <input type="checkbox" id="pro-public-toggle" <?php checked( $kounselia_is_public ); ?> style="width:20px;height:20px;margin-top:3px;accent-color:var(--accent);flex-shrink:0">
        <span>
          <strong style="display:block;font-size:15px;margin-bottom:4px">Show my profile on the public website</strong>
          <span class="section-sub" style="display:block;line-height:1.55">Your name, photo, title, specialty, experience, bio, rate and reviews appear in the public directory at kounselia.com/professionals once you're verified, so new clients can find you. Your licence number is never shown, and reviews never show your clients' names. Turn this off any time — signed-in members can still book you.</span>
          <?php if ( 'verified' === $application->status ) : ?>
            <a href="<?php echo esc_url( kounselia_professional_url( (object) array( 'display_name' => $display_name, 'id' => $application->id ) ) ); ?>" target="_blank" style="display:inline-block;margin-top:8px;font-size:13px">View my public profile →</a>
          <?php endif; ?>
          <span class="inline-msg" id="pro-public-msg" style="display:block;margin-top:6px;font-size:13px"></span>
        </span>
      </label>
    </div>
    <script>
    document.getElementById('pro-public-toggle').addEventListener('change', function(){
      var box = this, msg = document.getElementById('pro-public-msg');
      fetch(KOUNSELIA.ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ action: 'kounselia_set_public_profile', nonce: KOUNSELIA.nonce, show: box.checked ? '1' : '0' }) })
        .then(function(r){ return r.json(); })
        .then(function(res){ msg.textContent = (res.data && res.data.message) || (res.success ? 'Saved.' : 'Could not save.'); msg.style.color = res.success ? 'var(--sage)' : 'var(--rose)'; if(!res.success){ box.checked = !box.checked; } })
        .catch(function(){ box.checked = !box.checked; msg.textContent = 'Connection problem, please try again.'; msg.style.color = 'var(--rose)'; });
    });
    </script>
    <?php endif; ?>
  </div>

  <!-- BOOKINGS -->
  <div class="view-panel" id="view-bookings">
    <div class="section-head">
      <h2>Bookings</h2>
      <span class="section-sub">Client session requests</span>
    </div>

    <div class="section">
      <div class="section-head">
        <h2 style="font-size:19px">Your weekly availability</h2>
        <span class="section-sub">Clients can only book inside these hours</span>
      </div>
      <div class="pro-card">
        <div class="avail-grid" id="avail-grid">
          <?php
          $rules_by_day = array();
          foreach ( $availability_rules as $rule ) {
              $rules_by_day[ (int) $rule->day_of_week ][] = $rule;
          }
          for ( $d = 0; $d <= 6; $d++ ) :
              $day_rule = $rules_by_day[ $d ][0] ?? null;
              $enabled  = (bool) $day_rule;
              $start    = $day_rule ? substr( $day_rule->start_time, 0, 5 ) : '09:00';
              $end      = $day_rule ? substr( $day_rule->end_time, 0, 5 ) : '17:00';
          ?>
          <div class="avail-row" data-day="<?php echo $d; ?>">
            <label class="avail-toggle">
              <input type="checkbox" class="avail-enabled" <?php checked( $enabled ); ?>>
              <span><?php echo esc_html( $day_labels[ $d ] ); ?></span>
            </label>
            <input type="time" class="avail-start" value="<?php echo esc_attr( $start ); ?>" <?php disabled( ! $enabled ); ?>>
            <span class="avail-to">to</span>
            <input type="time" class="avail-end" value="<?php echo esc_attr( $end ); ?>" <?php disabled( ! $enabled ); ?>>
          </div>
          <?php endfor; ?>
        </div>
        <button type="button" class="pro-submit" id="avail-save-btn" onclick="saveAvailability()">Save availability</button>
        <div class="pro-msg" id="avail-msg"></div>
      </div>
    </div>

    <div class="section">
      <div class="section-head">
        <h2 style="font-size:19px">Upcoming sessions</h2>
        <span class="section-sub"><?php echo (int) count( $upcoming_bookings ); ?> scheduled</span>
      </div>
      <?php if ( empty( $upcoming_bookings ) ) : ?>
      <div class="coming-soon">
        <div class="coming-soon-icon"><i class="ti ti-calendar-event"></i></div>
        <h3>No sessions booked yet</h3>
        <p>Once your availability is set and you're verified, clients booking an open slot will show up right here.</p>
      </div>
      <?php else : ?>
      <div class="booking-list" id="pro-booking-list">
        <?php foreach ( $upcoming_bookings as $booking ) : ?>
        <?php
        $can_join = kounselia_booking_is_joinable( $booking );
        // True (real, timezone-free) millisecond timestamps, for the
        // "Join" button's live countdown below — the page is loaded once
        // and often left open past session time, so without this the
        // button only ever reflects whichever moment the page was last
        // rendered and never appears on its own once it's time.
        $kounselia_join_opens_at  = ( (int) get_gmt_from_date( $booking->scheduled_start, 'U' ) - 10 * MINUTE_IN_SECONDS ) * 1000;
        $kounselia_join_closes_at = ( (int) get_gmt_from_date( $booking->scheduled_end, 'U' ) + 15 * MINUTE_IN_SECONDS ) * 1000;
        ?>
        <div class="booking-row" data-booking-id="<?php echo (int) $booking->id; ?>">
          <div class="booking-icon"><i class="ti ti-calendar-event"></i></div>
          <div class="booking-meta">
            <div class="booking-when"><?php echo esc_html( date_i18n( 'D, M j — g:i A', strtotime( $booking->scheduled_start ) ) ); ?><?php if ( $booking->series_id ) : ?><span class="weekly-tag">Weekly</span><?php endif; ?><?php if ( ! empty( $booking->is_free ) ) : ?><span class="weekly-tag" style="color:var(--sage);background:var(--sage-light)">Free</span><?php endif; ?></div>
            <div class="booking-with"><?php echo esc_html( $booking->client_name ?: $booking->client_email ); ?></div>
            <?php if ( $booking->client_note ) : ?><div class="booking-note"><?php echo esc_html( $booking->client_note ); ?></div><?php endif; ?>
            <?php $kounselia_where = function_exists( 'kounselia_booking_video' ) ? kounselia_booking_video( $booking, $application ) : null; ?>
            <?php if ( $kounselia_where && $kounselia_where['external'] ) : ?><div class="booking-note" style="font-style:normal"><i class="ti ti-video"></i> On <?php echo esc_html( $kounselia_where['provider'] ); ?><?php echo $booking->video_link ? ' (link for this session)' : ''; ?></div><?php endif; ?>
          </div>
          <div class="booking-actions">
            <a
              class="booking-join js-booking-join"
              href="/video-call.php?booking_id=<?php echo (int) $booking->id; ?>"
              data-opens-at="<?php echo (int) $kounselia_join_opens_at; ?>"
              data-closes-at="<?php echo (int) $kounselia_join_closes_at; ?>"
              <?php echo $can_join ? '' : 'hidden'; ?>
            ><i class="ti ti-video"></i> Join</a>
            <?php if ( function_exists( 'kounselia_video_own_allowed' ) && kounselia_video_own_allowed( $application ) ) : ?>
              <button type="button" class="booking-message" onclick="setSessionLink(<?php echo (int) $booking->id; ?>, <?php echo esc_attr( wp_json_encode( (string) $booking->video_link ) ); ?>)"><i class="ti ti-link"></i> Video link</button>
            <?php endif; ?>
            <button type="button" class="booking-message js-booking-chat" data-booking-id="<?php echo (int) $booking->id; ?>" data-other-name="<?php echo esc_attr( $booking->client_name ?: $booking->client_email ); ?>"><i class="ti ti-message-circle"></i> Message</button>
            <button type="button" class="booking-message js-reschedule" data-booking-id="<?php echo (int) $booking->id; ?>" data-other-name="<?php echo esc_attr( $booking->client_name ?: $booking->client_email ); ?>">Reschedule</button>
            <button type="button" class="booking-cancel" onclick="cancelBooking(<?php echo (int) $booking->id; ?>, this)">Cancel</button>
            <?php if ( $booking->series_id ) : ?>
              <button type="button" class="booking-cancel" onclick="cancelSeries(<?php echo (int) $booking->series_id; ?>, this)">Cancel weekly</button>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ARTICLES -->
<?php if ( $show_articles_tab ) :
    $state_icons = array( 'live' => 'circle-check', 'pending' => 'clock', 'live_pending' => 'clock', 'changes' => 'message-dots', 'rejected' => 'circle-x', 'removed' => 'eye-off', 'draft' => 'pencil', 'live_draft' => 'pencil', 'scheduled' => 'calendar' );
?>
  <div class="view-panel" id="view-articles">
    <div class="art-head">
      <h2>Your articles</h2>
      <?php if ( $article_access['allowed'] ) : ?>
        <a class="art-new" href="/pro-write.php"><i class="ti ti-pencil-plus"></i> Write an article</a>
      <?php endif; ?>
    </div>

    <?php if ( ! $article_access['allowed'] ) : ?>
      <div class="art-mode"><i class="ti ti-info-circle"></i><div><?php echo esc_html( $article_access['message'] ); ?></div></div>
    <?php elseif ( 'trusted' === $article_access['mode'] ) : ?>
      <div class="art-mode"><i class="ti ti-discount-check"></i><div><b>You're a trusted writer.</b> Your articles go live on the Journal as soon as you publish them.</div></div>
    <?php else : ?>
      <div class="art-mode"><i class="ti ti-eye-check"></i><div>An editor reads every article before it goes live, usually within two working days. You'll get a notification and an email either way.</div></div>
    <?php endif; ?>

    <div class="art-stats">
      <div class="stat-card"><div class="stat-icon" style="background:var(--accent-light);color:var(--accent);"><i class="ti ti-users"></i></div><div class="stat-num"><?php echo esc_html( number_format_i18n( $article_followers ) ); ?></div><div class="stat-label"><?php echo 1 === $article_followers ? 'Follower' : 'Followers'; ?></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:var(--sage-light);color:var(--sage);"><i class="ti ti-eye"></i></div><div class="stat-num"><?php echo esc_html( number_format_i18n( $article_totals['views'] ) ); ?></div><div class="stat-label">Reads</div></div>
      <div class="stat-card"><div class="stat-icon" style="background:var(--rose-light);color:var(--rose);"><i class="ti ti-heart"></i></div><div class="stat-num"><?php echo esc_html( number_format_i18n( $article_totals['loves'] ) ); ?></div><div class="stat-label">Loves</div></div>
      <div class="stat-card"><div class="stat-icon" style="background:var(--gold-light);color:var(--gold);"><i class="ti ti-message-circle"></i></div><div class="stat-num"><?php echo esc_html( number_format_i18n( $article_totals['comments'] ) ); ?></div><div class="stat-label">Comments</div></div>
    </div>

    <?php if ( ! $articles ) : ?>
      <div class="coming-soon">
        <div class="coming-soon-icon"><i class="ti ti-feather"></i></div>
        <h3>Share what you know</h3>
        <p>Articles help people understand what they're going through, and help them find you. Readers can follow you and hear whenever you publish.</p>
      </div>
    <?php else : ?>
      <div class="art-list">
        <?php foreach ( $articles as $a ) :
            $state = kounselia_article_state( $a );
            $live  = in_array( $state, array( 'live', 'live_pending', 'live_draft' ), true );
            $date  = $a->published_at ? $a->published_at : $a->updated_at;
            ?>
          <div class="art-card" data-article="<?php echo (int) $a->id; ?>">
            <?php if ( $a->cover_image ) : ?><img class="art-thumb" src="<?php echo esc_url( $a->cover_image ); ?>" alt="" loading="lazy"><?php else : ?><div class="art-thumb"><i class="ti ti-feather"></i></div><?php endif; ?>
            <div class="art-body">
              <div class="art-title"><?php echo esc_html( $a->title ); ?></div>
              <div class="art-meta">
                <span class="art-pill <?php echo esc_attr( $state ); ?>"><i class="ti ti-<?php echo esc_attr( $state_icons[ $state ] ?? 'point' ); ?>"></i> <?php echo esc_html( kounselia_article_state_label( $state ) ); ?></span>
                <span><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $date ) ) ); ?></span>
                <?php if ( $live ) : ?>
                  <span title="Reads"><i class="ti ti-eye"></i> <?php echo esc_html( number_format_i18n( $a->views ) ); ?></span>
                  <span title="Loves"><i class="ti ti-heart"></i> <?php echo esc_html( number_format_i18n( $a->love_count ) ); ?></span>
                  <span title="Comments"><i class="ti ti-message-circle"></i> <?php echo esc_html( number_format_i18n( $a->comment_count ) ); ?></span>
                <?php endif; ?>
              </div>
              <?php if ( $a->review_note && in_array( $state, array( 'changes', 'rejected', 'removed', 'live_draft' ), true ) && in_array( $a->review_status, array( 'changes_requested', 'rejected', 'removed' ), true ) ) : ?>
                <div class="art-note"><b>Note from the editor:</b> <?php echo nl2br( esc_html( $a->review_note ) ); ?></div>
              <?php endif; ?>
              <div class="art-actions">
                <?php if ( $article_access['allowed'] ) : ?>
                  <a href="/pro-write.php?id=<?php echo (int) $a->id; ?>"><i class="ti ti-pencil"></i> Edit</a>
                <?php endif; ?>
                <a href="<?php echo esc_url( kounselia_blog_url( $a->slug ) ); ?>" target="_blank" rel="noopener"><i class="ti ti-eye"></i> <?php echo $live ? 'View' : 'Preview'; ?></a>
                <?php if ( in_array( $state, array( 'pending', 'live_pending' ), true ) ) : ?>
                  <button type="button" onclick="articleAction('withdraw', <?php echo (int) $a->id; ?>, 'Take this back from the review queue? You can send it again any time.')"><i class="ti ti-arrow-back-up"></i> Withdraw</button>
                <?php elseif ( $live ) : ?>
                  <button type="button" onclick="articleAction('withdraw', <?php echo (int) $a->id; ?>, 'Unpublish this article? It will leave the Journal and go back to your drafts. Loves and comments are kept.')"><i class="ti ti-eye-off"></i> Unpublish</button>
                <?php endif; ?>
                <button type="button" class="danger" onclick="articleAction('delete', <?php echo (int) $a->id; ?>, 'Delete this article for good? Its comments and loves are deleted too. This cannot be undone.')"><i class="ti ti-trash"></i> Delete</button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

  <!-- EARNINGS -->
  <div class="view-panel" id="view-earnings">
    <div class="section-head">
      <h2>Earnings</h2>
      <span class="section-sub">Payouts and session history</span>
    </div>

    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--sage-light);color:var(--sage);"><i class="ti ti-wallet"></i></div>
        <div class="stat-num">₦<?php echo esc_html( number_format( $balance['available'] ) ); ?></div>
        <div class="stat-label">Available to pay out</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--accent-light);color:var(--accent);"><i class="ti ti-cash"></i></div>
        <div class="stat-num">₦<?php echo esc_html( number_format( $balance['total_earned'] ) ); ?></div>
        <div class="stat-label">Total earned</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--gold-light);color:var(--gold);"><i class="ti ti-check"></i></div>
        <div class="stat-num">₦<?php echo esc_html( number_format( $balance['paid_out'] ) ); ?></div>
        <div class="stat-label">Paid out so far</div>
      </div>
    </div>

    <div class="readonly-note">Kounselia's commission is <?php echo esc_html( rtrim( rtrim( number_format( kounselia_booking_commission_percent(), 1 ), '0' ), '.' ) ); ?>% of each session — the rest is yours. A session only counts here once the client's payment has gone through.</div>

    <div class="section">
      <div class="section-head">
        <h2 style="font-size:19px">Payout account</h2>
        <span class="section-sub">Where your money goes when you request a payout</span>
      </div>
      <div class="pro-card">
        <?php if ( $payout_account ) : ?>
          <div class="doc-row" style="margin-bottom:20px">
            <i class="ti ti-building-bank"></i>
            <div class="doc-meta">
              <div class="doc-name"><?php echo esc_html( $payout_account->account_name ); ?></div>
              <div class="doc-type"><?php echo esc_html( $payout_account->bank_name ); ?> — ••••<?php echo esc_html( substr( $payout_account->account_number, -4 ) ); ?></div>
            </div>
          </div>
          <div class="section-sub" style="margin-bottom:14px">Add a different account below to replace this one.</div>
        <?php endif; ?>

        <form id="payout-account-form">
          <div class="form-row">
            <div class="form-field">
              <label>Bank</label>
              <select name="bank_code" id="payout-bank">
                <option value="">Select your bank</option>
                <?php foreach ( $payout_banks as $bank ) : ?>
                  <option value="<?php echo esc_attr( $bank['code'] ); ?>"><?php echo esc_html( $bank['name'] ); ?></option>
                <?php endforeach; ?>
              </select>
              <?php if ( empty( $payout_banks ) ) : ?><div class="section-sub" style="margin-top:6px">Bank list unavailable right now — payments may not be configured yet.</div><?php endif; ?>
            </div>
            <div class="form-field">
              <label>Account number</label>
              <input type="text" name="account_number" id="payout-account-number" inputmode="numeric" maxlength="10" placeholder="0123456789">
            </div>
          </div>
          <button type="submit" class="pro-submit" id="payout-account-save-btn">Verify &amp; save account</button>
          <div class="pro-msg" id="payout-account-msg"></div>
        </form>
      </div>
    </div>

    <div class="section">
      <div class="section-head">
        <h2 style="font-size:19px">Request a payout</h2>
        <span class="section-sub">Sends your entire available balance</span>
      </div>
      <div class="pro-card">
        <button type="button" class="pro-submit" id="request-payout-btn" onclick="requestPayout()" <?php echo ( $balance['available'] <= 0 || ! $payout_account ) ? 'disabled' : ''; ?>>
          Request payout of ₦<?php echo esc_html( number_format( $balance['available'] ) ); ?>
        </button>
        <?php if ( ! $payout_account ) : ?>
          <div class="section-sub" style="margin-top:10px">Add a payout account above first.</div>
        <?php elseif ( $balance['available'] <= 0 ) : ?>
          <div class="section-sub" style="margin-top:10px">Nothing to pay out yet — this fills up as clients pay for booked sessions.</div>
        <?php endif; ?>
        <div class="pro-msg" id="payout-request-msg"></div>
      </div>
    </div>

    <?php if ( ! empty( $payout_history ) ) : ?>
    <div class="section">
      <div class="section-head">
        <h2 style="font-size:19px">Payout history</h2>
      </div>
      <div class="booking-list">
        <?php foreach ( $payout_history as $payout ) : ?>
        <div class="booking-row">
          <div class="booking-icon"><i class="ti ti-cash"></i></div>
          <div class="booking-meta">
            <div class="booking-when">₦<?php echo esc_html( number_format( (float) $payout->amount ) ); ?></div>
            <div class="booking-with"><?php echo esc_html( date_i18n( 'D, M j, Y', strtotime( $payout->created_at ) ) ); ?> — <?php echo esc_html( $payout_status_labels[ $payout->status ] ?? ucfirst( $payout->status ) ); ?></div>
            <?php if ( 'failed' === $payout->status && $payout->failure_reason ) : ?><div class="booking-note"><?php echo esc_html( $payout->failure_reason ); ?></div><?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  </main>

  <!-- MOBILE TAB BAR -->
  <nav class="mobile-tabbar">
    <button class="mob-tab active" id="mob-tab-overview" onclick="switchTab('overview')"><i class="ti ti-layout-dashboard"></i>Overview</button>
    <button class="mob-tab" id="mob-tab-profile" onclick="switchTab('profile')"><i class="ti ti-user-edit"></i>Profile</button>
    <button class="mob-tab" id="mob-tab-bookings" onclick="switchTab('bookings')"><i class="ti ti-calendar-event"></i>Bookings</button>
<?php if ( $show_articles_tab ) : ?>
    <button class="mob-tab" id="mob-tab-articles" onclick="switchTab('articles')"><i class="ti ti-feather"></i>Articles</button>
<?php endif; ?>
    <button class="mob-tab" id="mob-tab-earnings" onclick="switchTab('earnings')"><i class="ti ti-cash"></i>Earnings</button>
  </nav>

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

<!-- RESCHEDULE MODAL -->
<div class="chat-overlay" id="reschedule-overlay">
  <div class="chat-modal" style="height:auto;max-height:80vh">
    <div class="chat-modal-head">
      <h3 id="reschedule-modal-name">Reschedule</h3>
      <button type="button" onclick="closeReschedule()"><i class="ti ti-x"></i></button>
    </div>
    <div style="padding:16px 20px;overflow-y:auto" id="reschedule-slots">
      <p style="color:var(--text3);font-size:13px">Loading available times...</p>
    </div>
  </div>
</div>

<!-- NOTIFICATIONS MODAL -->
<div class="chat-overlay" id="notif-overlay">
  <div class="chat-modal" style="height:auto;max-height:80vh">
    <div class="chat-modal-head">
      <h3>Notifications</h3>
      <button type="button" onclick="closeNotifications()"><i class="ti ti-x"></i></button>
    </div>
    <div style="padding:16px 20px;overflow-y:auto;display:flex;flex-direction:column;gap:8px" id="notif-list">
      <p style="color:var(--text3);font-size:13px">Loading...</p>
    </div>
  </div>
</div>

<script>
const KOUNSELIA_PRO_ID = <?php echo (int) $application->id; ?>;
</script>

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

function showInline(id,msg,ok){
  const el=document.getElementById(id);
  if(!el) return;
  el.textContent=msg;
  el.className='inline-msg '+(ok?'ok':'err');
  el.style.display='block';
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
setupAvatarUpload('pro-avatar-input','pro-avatar-img','side-av','mobile-av','pro-avatar-msg');

document.getElementById('pro-form').addEventListener('submit', function(e){
  e.preventDefault();
  const btn = document.getElementById('pro-save-btn');
  const msg = document.getElementById('pro-msg');
  msg.className = 'pro-msg';
  msg.textContent = '';
  btn.disabled = true;
  btn.textContent = 'Saving...';

  const name = document.getElementById('pro-name').value.trim();

  const requests = [
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
        rate_amount: document.getElementById('pro-rate').value,
        free_sessions_per_client: document.getElementById('pro-free') ? document.getElementById('pro-free').value : ''
      })
    }).then(r => r.json())
  ];

  if (name) {
    requests.push(
      fetch(KOUNSELIA.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'kounselia_update_profile', nonce: KOUNSELIA.nonce, name })
      }).then(r => r.json())
    );
  }

  Promise.all(requests)
  .then(results => {
    btn.disabled = false;
    btn.textContent = 'Save changes';
    const failed = results.find(res => !res.success);
    if (!failed) {
      msg.classList.add('notice');
      msg.textContent = 'Saved.';
      if (name) {
        const firstName = name.split(' ')[0];
        const nameEl = document.getElementById('welcome-first-name');
        const sideEl = document.getElementById('side-user-name');
        if (nameEl) nameEl.textContent = firstName;
        if (sideEl) sideEl.textContent = name;
      }
    } else {
      msg.classList.add('error');
      msg.textContent = (failed.data && failed.data.message) ? failed.data.message : 'Something went wrong, please try again.';
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.textContent = 'Save changes';
    msg.classList.add('error');
    msg.textContent = 'Something went wrong, please check your connection and try again.';
  });
});

/* ---------------- DOCUMENTS ---------------- */

document.getElementById('doc-form').addEventListener('submit', function(e){
  e.preventDefault();
  const btn = document.getElementById('doc-save-btn');
  const msg = document.getElementById('doc-msg');
  const fileInput = document.getElementById('doc-file');
  msg.className = 'pro-msg';
  msg.textContent = '';

  if (!fileInput.files.length) {
    msg.classList.add('error');
    msg.textContent = 'Please choose a file to upload.';
    return;
  }

  const fd = new FormData();
  fd.append('action', 'kounselia_upload_professional_document');
  fd.append('nonce', KOUNSELIA.nonce);
  fd.append('doc_type', document.getElementById('doc-type').value);
  fd.append('document', fileInput.files[0]);

  btn.disabled = true;
  btn.textContent = 'Uploading...';

  fetch(KOUNSELIA.ajaxUrl, { method: 'POST', body: fd })
  .then(r => r.json())
  .then(res => {
    btn.disabled = false;
    btn.textContent = 'Add document';
    if (res.success) {
      msg.classList.add('notice');
      msg.textContent = res.data.message || 'Uploaded.';
      const doc = res.data.document;
      const empty = document.getElementById('doc-empty');
      if (empty) empty.remove();
      const row = document.createElement('div');
      row.className = 'doc-row';
      row.dataset.docId = doc.id;
      row.innerHTML = '<i class="ti ti-file-text"></i><div class="doc-meta"><div class="doc-name"></div><div class="doc-type"></div></div><a class="doc-view" target="_blank" rel="noopener">View</a><button type="button" class="doc-remove"><i class="ti ti-trash"></i></button>';
      row.querySelector('.doc-name').textContent = doc.original_filename;
      row.querySelector('.doc-type').textContent = doc.doc_type;
      row.querySelector('.doc-view').href = doc.url;
      row.querySelector('.doc-remove').onclick = function(){ removeDocument(doc.id, this); };
      document.getElementById('doc-list').appendChild(row);
      document.getElementById('doc-form').reset();
    } else {
      msg.classList.add('error');
      msg.textContent = (res.data && res.data.message) ? res.data.message : 'Something went wrong, please try again.';
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.textContent = 'Add document';
    msg.classList.add('error');
    msg.textContent = 'Something went wrong, please check your connection and try again.';
  });
});

function removeDocument(docId, btnEl){
  if (!confirm('Remove this document?')) return;
  btnEl.disabled = true;
  fetch(KOUNSELIA.ajaxUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'kounselia_delete_professional_document', nonce: KOUNSELIA.nonce, doc_id: docId })
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      const row = document.querySelector('.doc-row[data-doc-id="' + docId + '"]');
      if (row) row.remove();
    } else {
      btnEl.disabled = false;
      alert((res.data && res.data.message) ? res.data.message : 'Could not remove that document.');
    }
  })
  .catch(() => { btnEl.disabled = false; alert('Something went wrong, please check your connection and try again.'); });
}

/* ---------------- AVAILABILITY ---------------- */

document.querySelectorAll('.avail-row').forEach(function(row){
  const toggle = row.querySelector('.avail-enabled');
  const start = row.querySelector('.avail-start');
  const end = row.querySelector('.avail-end');
  toggle.addEventListener('change', function(){
    start.disabled = !toggle.checked;
    end.disabled = !toggle.checked;
  });
});

function saveAvailability(){
  const btn = document.getElementById('avail-save-btn');
  const msg = document.getElementById('avail-msg');
  msg.className = 'pro-msg';
  msg.textContent = '';

  const rules = [];
  document.querySelectorAll('.avail-row').forEach(function(row){
    const toggle = row.querySelector('.avail-enabled');
    if (!toggle.checked) return;
    const start = row.querySelector('.avail-start').value;
    const end = row.querySelector('.avail-end').value;
    if (!start || !end || start >= end) return;
    rules.push({ day: parseInt(row.dataset.day, 10), start, end });
  });

  btn.disabled = true;
  btn.textContent = 'Saving...';

  fetch(KOUNSELIA.ajaxUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'kounselia_save_availability', nonce: KOUNSELIA.nonce, rules: JSON.stringify(rules) })
  })
  .then(r => r.json())
  .then(res => {
    btn.disabled = false;
    btn.textContent = 'Save availability';
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
    btn.textContent = 'Save availability';
    msg.classList.add('error');
    msg.textContent = 'Something went wrong, please check your connection and try again.';
  });
}

/* ---------------- BOOKINGS ---------------- */

// This page is rendered once and often stays open past session time (a
// professional keeping the Bookings tab up in a background browser tab),
// so "Join" showing or not was frozen at whatever moment the page loaded
// and never appeared on its own once a session's time actually came.
// This keeps every Join button in sync with the clock without a reload.
function refreshJoinButtons(){
  var now = Date.now();
  document.querySelectorAll('.js-booking-join').forEach(function(el){
    var opensAt = Number(el.dataset.opensAt);
    var closesAt = Number(el.dataset.closesAt);
    el.hidden = !(now >= opensAt && now <= closesAt);
  });
}
refreshJoinButtons();
setInterval(refreshJoinButtons, 15000);

function cancelBooking(bookingId, btnEl){
  if (!confirm("Cancel this session? The client will be refunded and notified.")) return;
  btnEl.disabled = true;
  btnEl.textContent = 'Cancelling...';
  fetch(KOUNSELIA.ajaxUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'kounselia_cancel_booking', nonce: KOUNSELIA.nonce, booking_id: bookingId })
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      const row = document.querySelector('.booking-row[data-booking-id="' + bookingId + '"]');
      if (row) row.remove();
    } else {
      btnEl.disabled = false;
      btnEl.textContent = 'Cancel';
      alert((res.data && res.data.message) ? res.data.message : 'Could not cancel that booking.');
    }
  })
  .catch(() => {
    btnEl.disabled = false;
    btnEl.textContent = 'Cancel';
    alert('Something went wrong, please check your connection and try again.');
  });
}

/* ---------------- PAYOUTS ---------------- */

const payoutAccountForm = document.getElementById('payout-account-form');
if (payoutAccountForm) {
  payoutAccountForm.addEventListener('submit', function(e){
    e.preventDefault();
    const btn = document.getElementById('payout-account-save-btn');
    const msg = document.getElementById('payout-account-msg');
    const bankSelect = document.getElementById('payout-bank');
    msg.className = 'pro-msg';
    msg.textContent = '';

    const bankCode = bankSelect.value;
    const bankName = bankSelect.options[bankSelect.selectedIndex] ? bankSelect.options[bankSelect.selectedIndex].text : '';
    const accountNumber = document.getElementById('payout-account-number').value.trim();

    if (!bankCode || accountNumber.length < 10) {
      msg.classList.add('error');
      msg.textContent = 'Please choose a bank and enter a valid 10-digit account number.';
      return;
    }

    btn.disabled = true;
    btn.textContent = 'Verifying...';

    fetch(KOUNSELIA.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ action: 'kounselia_save_payout_account', nonce: KOUNSELIA.nonce, bank_code: bankCode, bank_name: bankName, account_number: accountNumber })
    })
    .then(r => r.json())
    .then(res => {
      btn.disabled = false;
      btn.textContent = 'Verify & save account';
      if (res.success) {
        msg.classList.add('notice');
        msg.textContent = 'Saved — verified as ' + res.data.account_name + '.';
        setTimeout(() => window.location.reload(), 1500);
      } else {
        msg.classList.add('error');
        msg.textContent = (res.data && res.data.message) ? res.data.message : 'Could not verify that account.';
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.textContent = 'Verify & save account';
      msg.classList.add('error');
      msg.textContent = 'Something went wrong, please check your connection and try again.';
    });
  });
}

function requestPayout(){
  if (!confirm('Request a payout of your full available balance?')) return;
  const btn = document.getElementById('request-payout-btn');
  const originalLabel = btn.textContent;
  const msg = document.getElementById('payout-request-msg');
  msg.className = 'pro-msg';
  msg.textContent = '';
  btn.disabled = true;
  btn.textContent = 'Requesting...';

  fetch(KOUNSELIA.ajaxUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'kounselia_request_payout', nonce: KOUNSELIA.nonce })
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      msg.classList.add('notice');
      msg.textContent = 'Payout requested.';
      setTimeout(() => window.location.reload(), 1500);
    } else {
      btn.disabled = false;
      btn.textContent = originalLabel;
      msg.classList.add('error');
      msg.textContent = (res.data && res.data.message) ? res.data.message : 'Could not request a payout right now.';
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.textContent = originalLabel;
    msg.classList.add('error');
    msg.textContent = 'Something went wrong, please check your connection and try again.';
  });
}

/* ---------------- BOOKING CHAT ---------------- */

let chatBookingId = null;
let chatPollTimer = null;

document.querySelectorAll('.js-booking-chat').forEach(function(btn){
  btn.addEventListener('click', function(){
    openBookingChat(parseInt(btn.dataset.bookingId, 10), btn.dataset.otherName);
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

/* ---------------- RESCHEDULE ---------------- */

let rescheduleBookingId = null;
let rescheduleSelectedSlot = null;

document.querySelectorAll('.js-reschedule').forEach(function(btn){
  btn.addEventListener('click', function(){
    openReschedule(parseInt(btn.dataset.bookingId, 10), btn.dataset.otherName);
  });
});

function openReschedule(bookingId, otherName){
  rescheduleBookingId = bookingId;
  rescheduleSelectedSlot = null;
  document.getElementById('reschedule-modal-name').textContent = 'Reschedule with ' + otherName;
  document.getElementById('reschedule-slots').innerHTML = '<p style="color:var(--text3);font-size:13px">Loading available times...</p>';
  document.getElementById('reschedule-overlay').classList.add('active');

  fetch(KOUNSELIA.ajaxUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'kounselia_get_professional_slots', nonce: KOUNSELIA.nonce, professional_id: KOUNSELIA_PRO_ID, reschedule_booking_id: bookingId })
  })
  .then(r => r.json())
  .then(res => {
    const wrap = document.getElementById('reschedule-slots');
    if (!res.success) {
      wrap.innerHTML = '<p style="color:var(--rose);font-size:13px">' + ((res.data && res.data.message) || 'Could not load availability.') + '</p>';
      return;
    }
    const slots = res.data.slots || [];
    if (!slots.length) {
      wrap.innerHTML = '<p style="color:var(--text3);font-size:13px">No open times right now.</p>';
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
      dayBlock.className = 'reschedule-day';
      const label = document.createElement('div');
      label.className = 'reschedule-day-label';
      label.textContent = dayKey;
      dayBlock.appendChild(label);

      const row = document.createElement('div');
      row.className = 'reschedule-slot-row';
      byDay[dayKey].forEach(function(s){
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'reschedule-slot-btn';
        btn.textContent = s.time;
        btn.onclick = function(){
          document.querySelectorAll('.reschedule-slot-btn').forEach(function(b){ b.classList.remove('selected'); });
          btn.classList.add('selected');
          rescheduleSelectedSlot = s.raw;
          let confirmBtn = document.getElementById('reschedule-confirm-btn');
          if (!confirmBtn) {
            confirmBtn = document.createElement('button');
            confirmBtn.type = 'button';
            confirmBtn.id = 'reschedule-confirm-btn';
            confirmBtn.className = 'reschedule-confirm';
            confirmBtn.textContent = 'Confirm new time';
            confirmBtn.onclick = submitReschedule;
            document.getElementById('reschedule-slots').appendChild(confirmBtn);
          }
        };
        row.appendChild(btn);
      });
      dayBlock.appendChild(row);
      wrap.appendChild(dayBlock);
    });
  })
  .catch(() => {
    document.getElementById('reschedule-slots').innerHTML = '<p style="color:var(--rose);font-size:13px">Something went wrong. Please try again.</p>';
  });
}

function closeReschedule(){
  document.getElementById('reschedule-overlay').classList.remove('active');
  rescheduleBookingId = null;
  rescheduleSelectedSlot = null;
}

function submitReschedule(){
  if (!rescheduleBookingId || !rescheduleSelectedSlot) return;
  const btn = document.getElementById('reschedule-confirm-btn');
  btn.disabled = true;
  btn.textContent = 'Saving...';

  fetch(KOUNSELIA.ajaxUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'kounselia_reschedule_booking', nonce: KOUNSELIA.nonce, booking_id: rescheduleBookingId, scheduled_start: rescheduleSelectedSlot })
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      closeReschedule();
      setTimeout(() => window.location.reload(), 800);
    } else {
      btn.disabled = false;
      btn.textContent = 'Confirm new time';
      alert((res.data && res.data.message) || 'Could not reschedule that session.');
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.textContent = 'Confirm new time';
    alert('Something went wrong, please check your connection and try again.');
  });
}

function cancelSeries(seriesId, btnEl){
  if (!confirm('Cancel this weekly series? Any already-booked future sessions will be cancelled and refunded.')) return;
  btnEl.disabled = true;
  fetch(KOUNSELIA.ajaxUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'kounselia_cancel_series', nonce: KOUNSELIA.nonce, series_id: seriesId })
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      window.location.reload();
    } else {
      btnEl.disabled = false;
      alert((res.data && res.data.message) || 'Could not cancel that series.');
    }
  })
  .catch(() => { btnEl.disabled = false; alert('Something went wrong, please check your connection and try again.'); });
}

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
  const wrap = document.getElementById('notif-list');
  wrap.innerHTML = '<p style="color:var(--text3);font-size:13px">Loading...</p>';

  fetch(KOUNSELIA.ajaxUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'kounselia_get_notifications', nonce: KOUNSELIA.nonce })
  })
  .then(r => r.json())
  .then(res => {
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
      const titleEl = document.createElement('div');
      titleEl.className = 'notif-title';
      titleEl.textContent = n.title;
      a.appendChild(titleEl);
      if (n.body) {
        const bodyEl = document.createElement('div');
        bodyEl.className = 'notif-body';
        bodyEl.textContent = n.body;
        a.appendChild(bodyEl);
      }
      const timeEl = document.createElement('div');
      timeEl.className = 'notif-time';
      timeEl.textContent = time;
      a.appendChild(timeEl);
      wrap.appendChild(a);
    });
    const dot1 = document.getElementById('notif-dot');
    const dot2 = document.getElementById('notif-dot-desktop');
    if (dot1) dot1.style.display = 'none';
    if (dot2) dot2.style.display = 'none';
  })
  .catch(() => {
    wrap.innerHTML = '<p style="color:var(--rose);font-size:13px">Something went wrong.</p>';
  });
}

function closeNotifications(){
  document.getElementById('notif-overlay').classList.remove('active');
}

/* ---------------- VIDEO CALLS ---------------- */

document.querySelectorAll('input[name="video-mode"]').forEach(function(r){
  r.addEventListener('change', function(){
    if (r.checked) document.getElementById('video-link-field').style.display = r.value === 'own' ? '' : 'none';
  });
});
if (document.getElementById('video-save-btn')) document.getElementById('video-save-btn').addEventListener('click', function(){
  const btn = this, msg = document.getElementById('video-msg');
  const mode = (document.querySelector('input[name="video-mode"]:checked') || {}).value || 'kounselia';
  msg.className = 'pro-msg'; btn.disabled = true;
  fetch(KOUNSELIA.ajaxUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'kounselia_pro_video_settings', nonce: KOUNSELIA.nonce, mode: mode, link: document.getElementById('video-link').value })
  }).then(r => r.json()).then(res => {
    msg.classList.add(res.success ? 'notice' : 'error');
    msg.textContent = (res.data && res.data.message) || (res.success ? 'Saved.' : 'Something went wrong, please try again.');
  }).catch(() => { msg.classList.add('error'); msg.textContent = 'Something went wrong, please check your connection and try again.'; })
    .finally(() => { btn.disabled = false; });
});

// A different meeting link for one session; empty goes back to the usual setting.
function setSessionLink(bookingId, current){
  const link = prompt('Meeting link for this session only (Zoom, Google Meet, Teams or Whereby).\nLeave empty to use your usual video setting.', current || '');
  if (link === null) return;
  fetch(KOUNSELIA.ajaxUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'kounselia_pro_booking_video_link', nonce: KOUNSELIA.nonce, booking_id: bookingId, link: link })
  }).then(r => r.json()).then(res => {
    alert((res.data && res.data.message) || (res.success ? 'Saved.' : 'Something went wrong, please try again.'));
    if (res.success) location.href = '/pro-dashboard.php?tab=bookings';
  }).catch(() => alert('Something went wrong, please check your connection and try again.'));
}

/* ---------------- ARTICLES ---------------- */

function articleAction(kind, id, question){
  if (!confirm(question)) return;
  fetch(KOUNSELIA.ajaxUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: kind === 'delete' ? 'kounselia_pro_article_delete' : 'kounselia_pro_article_withdraw', nonce: KOUNSELIA.nonce, id: id })
  }).then(r => r.json()).then(res => {
    if (!res.success) { alert((res.data && res.data.message) || 'Something went wrong, please try again.'); return; }
    location.href = '/pro-dashboard.php?tab=articles';
  }).catch(() => alert('Something went wrong, please check your connection and try again.'));
}

// Open a tab straight from a link or notification (?tab=articles).
(function(){
  const tab = new URLSearchParams(location.search).get('tab');
  if (tab && document.getElementById('view-' + tab)) switchTab(tab);
})();
</script>

</body>
</html>

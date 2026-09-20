<?php
/**
 * Kounselia — the video/voice room for one booked session.
 *
 * Same boot pattern as apply.php/pro-dashboard.php: wp-load.php only, no
 * theme. Gated to a signed-in user who is one of the two people on the
 * booking (the client, or the professional whose slot it was) — anyone
 * else is bounced before anything about the booking is revealed.
 *
 * The call itself runs on Jitsi Meet's free public server (meet.jit.si)
 * via their embeddable iframe API — this app has no media/signaling
 * server of its own. The room name is a random per-booking token
 * (kounselia_bookings.room_token), never the booking id itself, so it
 * can't be guessed or enumerated by anyone who isn't sent this link. For
 * a two-person call, Jitsi connects the participants directly
 * (peer-to-peer) rather than routing audio/video through its servers.
 *
 * Joining is only allowed in a window around the scheduled time (see
 * kounselia_booking_is_joinable() in bookings.php) — outside that window
 * this page shows the scheduled time instead of the call.
 */
define( 'WP_USE_THEMES', false );
define( 'COOKIEPATH', '/' );
define( 'SITECOOKIEPATH', '/' );
require_once __DIR__ . '/portal/wp-load.php';

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( '/index.php' );
    exit;
}

$user       = wp_get_current_user();
$booking_id = isset( $_GET['booking_id'] ) ? absint( $_GET['booking_id'] ) : 0;
$booking    = $booking_id ? kounselia_get_booking_with_parties( $booking_id ) : null;

if ( ! $booking || ! kounselia_user_is_booking_party( $booking, $user->ID ) ) {
    wp_die( 'You do not have access to this session.', 'Not found', array( 'response' => 404 ) );
}

$is_professional_side = ( (int) $booking->professional_user_id === (int) $user->ID );
$other_party_id        = $is_professional_side ? (int) $booking->client_user_id : (int) $booking->professional_user_id;
$other_party            = get_userdata( $other_party_id );
$other_party_name       = $other_party ? $other_party->display_name : 'the other person';

$display_name = $user->display_name ? $user->display_name : $user->user_login;
$can_join     = kounselia_booking_is_joinable( $booking );
$window       = kounselia_booking_join_window( $booking );

$dashboard_url = $is_professional_side ? '/pro-dashboard.php' : '/dashboard.php';
$room_name     = 'kounselia-' . $booking->room_token;

$ajax_url = set_url_scheme( admin_url( 'admin-ajax.php' ), is_ssl() ? 'https' : 'http' );
$nonce    = wp_create_nonce( 'kounselia_auth' );
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Session with <?php echo esc_html( $other_party_name ); ?> — Kounselia</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/png" href="https://kounselia.com/img/fv.png">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
<?php require __DIR__ . '/inc/kounselia-styles.php'; ?>
<style>
html,body{height:100%;background:var(--navy,#1F2937)}
body{font-family:'Outfit',sans-serif;color:#fff;margin:0;display:flex;flex-direction:column}
.call-topbar{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:rgba(0,0,0,.25)}
.call-topbar a{color:#fff;text-decoration:none;font-size:14px;display:flex;align-items:center;gap:6px}
.call-with{font-size:14.5px;font-weight:500}
#jitsi-container{flex:1;min-height:0}
.wait-screen{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:40px 20px}
.wait-screen i{font-size:40px;color:rgba(255,255,255,.5);margin-bottom:18px}
.wait-screen h1{font-family:'Cormorant Garamond',serif;font-weight:400;font-size:30px;margin-bottom:10px}
.wait-screen p{color:rgba(255,255,255,.7);font-size:15px;max-width:420px;line-height:1.6}
.wait-screen .btn-w{margin-top:24px;background:#fff;color:var(--navy,#1F2937);padding:13px 24px;border-radius:50px;text-decoration:none;font-size:14px;font-weight:500}
</style>
</head>
<body>

<div class="call-topbar">
  <a href="<?php echo esc_url( $dashboard_url ); ?>"><i class="ti ti-arrow-left"></i> Back to dashboard</a>
  <div class="call-with">Session with <?php echo esc_html( $other_party_name ); ?></div>
</div>

<?php if ( $can_join ) : ?>
  <div id="jitsi-container"></div>
  <script src="https://meet.jit.si/external_api.js"></script>
  <script>
    new JitsiMeetExternalAPI('meet.jit.si', {
      roomName: <?php echo wp_json_encode( $room_name ); ?>,
      parentNode: document.getElementById('jitsi-container'),
      userInfo: { displayName: <?php echo wp_json_encode( $display_name ); ?> },
      configOverwrite: { prejoinPageEnabled: true, disableDeepLinking: true },
      interfaceConfigOverwrite: { SHOW_JITSI_WATERMARK: false, SHOW_WATERMARK_FOR_GUESTS: false }
    });
  </script>
<?php else : ?>
  <div class="wait-screen">
    <i class="ti ti-clock"></i>
    <?php if ( current_time( 'timestamp' ) < $window['opens_at'] ) : ?>
      <h1>Not quite time yet</h1>
      <p>This room opens 10 minutes before your session, at <strong><?php echo esc_html( date_i18n( 'D, M j — g:i A', strtotime( $booking->scheduled_start ) ) ); ?></strong>. Come back then to join.</p>
    <?php else : ?>
      <h1>This session has ended</h1>
      <p>The window to join this session has closed. If you still need to connect, book a new session from the dashboard.</p>
    <?php endif; ?>
    <a class="btn-w" href="<?php echo esc_url( $dashboard_url ); ?>">Back to dashboard</a>
  </div>
<?php endif; ?>

</body>
</html>

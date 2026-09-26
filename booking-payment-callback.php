<?php
/**
 * Kounselia — Paystack checkout callback for a booking payment.
 *
 * Same pattern as subscription-callback.php: Paystack redirects the
 * browser here with ?reference=... once someone finishes (or abandons)
 * checkout, regardless of outcome — so the reference is verified
 * server-to-server against Paystack's own API (see
 * kounselia_complete_booking_payment) before the booking is ever marked
 * confirmed. The redirect itself is never trusted as proof of payment,
 * only as "go check this reference."
 */
define( 'WP_USE_THEMES', false );
define( 'COOKIEPATH', '/' );
define( 'SITECOOKIEPATH', '/' );
require_once __DIR__ . '/portal/wp-load.php';

$reference = isset( $_GET['reference'] ) ? sanitize_text_field( wp_unslash( $_GET['reference'] ) ) : '';

// Paid from the mobile app: the app's browser isn't signed in to the
// website, so there's no dashboard to send them to. Confirm the payment
// now (verified with Paystack, as below) and tell them to go back to the
// app, which is watching for the booking to be confirmed.
if ( ! is_user_logged_in() ) {
    $paid = $reference && function_exists( 'kounselia_complete_booking_payment' )
        && ! empty( kounselia_complete_booking_payment( $reference )['success'] );
    status_header( 200 );
    nocache_headers();
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?php echo $paid ? 'Payment received' : 'Payment not completed'; ?> — Kounselia</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400&family=Outfit:wght@300;400;500&display=swap" rel="stylesheet">
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#F8F6F2;font-family:'Outfit',sans-serif;color:#18160F;padding:24px;box-sizing:border-box;text-align:center}
main{max-width:360px}
.mark{width:64px;height:64px;border-radius:50%;margin:0 auto 20px;display:flex;align-items:center;justify-content:center;font-size:30px;background:<?php echo $paid ? '#EAF2EC;color:#2E5C3E' : '#F7EBF0;color:#8B3A52'; ?>}
h1{font-family:'Cormorant Garamond',serif;font-weight:400;font-size:32px;margin:0 0 10px}
p{font-weight:300;font-size:16px;line-height:1.6;color:#5B574D;margin:0}
</style>
</head>
<body>
<main>
  <div class="mark"><?php echo $paid ? '&#10003;' : '!'; ?></div>
  <?php if ( $paid ) : ?>
    <h1>Payment received</h1>
    <p>Your session is booked. You can close this page and go back to the Kounselia app.</p>
  <?php else : ?>
    <h1>Payment not completed</h1>
    <p>No money was taken for this session. Close this page to go back to the Kounselia app and try again.</p>
  <?php endif; ?>
</main>
</body>
</html>
<?php
    exit;
}

if ( ! $reference || ! function_exists( 'kounselia_complete_booking_payment' ) ) {
    wp_safe_redirect( '/dashboard.php?booking=failed#professionals' );
    exit;
}

$result = kounselia_complete_booking_payment( $reference );

wp_safe_redirect( '/dashboard.php?booking=' . ( $result['success'] ? 'success' : 'failed' ) . '#professionals' );
exit;

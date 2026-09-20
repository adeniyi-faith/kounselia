<?php
/**
 * Kounselia Admin — a single booking's private message thread.
 *
 * Same read-only transcript view as session.php (the AI chat viewer),
 * pointed at kounselia_booking_messages instead — this is what the
 * "View the conversation" link in a safety alert email opens when the
 * flagged message came from a booking thread rather than the AI chat,
 * and it's also how an admin looks into a dispute ("this conversation
 * is inappropriate") reported on a booking.
 */
require_once __DIR__ . '/../inc/admin-auth.php';
require_once __DIR__ . '/../inc/admin-helpers.php';
$kounselia_admin_active = 'bookings';

global $wpdb;

$kounselia_booking_id = isset( $_GET['booking_id'] ) ? (int) $_GET['booking_id'] : 0;

if ( ! $kounselia_booking_id ) {
    wp_die( 'No booking specified. <a href="/portal/admin/pages/bookings.php">Back to bookings</a>' );
}

$kounselia_booking = $wpdb->get_row( $wpdb->prepare(
    "SELECT b.*, u.display_name AS client_name, u.user_email AS client_email,
            pu.display_name AS pro_name, p.title AS pro_title
     FROM {$wpdb->prefix}kounselia_bookings b
     LEFT JOIN {$wpdb->users} u ON u.ID = b.client_user_id
     INNER JOIN {$wpdb->prefix}kounselia_professionals p ON p.id = b.professional_id
     LEFT JOIN {$wpdb->users} pu ON pu.ID = p.user_id
     WHERE b.id = %d",
    $kounselia_booking_id
) );

if ( ! $kounselia_booking ) {
    wp_die( 'That booking does not exist. <a href="/portal/admin/pages/bookings.php">Back to bookings</a>' );
}

// Record that this admin viewed this thread, before rendering it — this
// is a private conversation between two people, so every look at it
// leaves a trail, the same as the AI transcript viewer.
kounselia_admin_log( 'view_booking_messages', 'booking', $kounselia_booking_id );

$kounselia_messages = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}kounselia_booking_messages WHERE booking_id = %d ORDER BY created_at ASC",
    $kounselia_booking_id
) );

$kounselia_client_label = $kounselia_booking->client_name ? $kounselia_booking->client_name : ( $kounselia_booking->client_email ?: 'Unknown client' );
$kounselia_pro_label    = $kounselia_booking->pro_name ? $kounselia_booking->pro_name : 'Unknown professional';

/* -----------------------------------------------------------------------
 * Same day-divider / grouping precompute as session.php.
 * -------------------------------------------------------------------- */
$kounselia_rows        = array();
$kounselia_prev_day    = null;
$kounselia_prev_sender = null;

$kounselia_count = count( $kounselia_messages );
foreach ( $kounselia_messages as $i => $m ) {
    $day_key   = date( 'Y-m-d', strtotime( $m->created_at ) );
    $day_label = ( $day_key !== $kounselia_prev_day )
        ? date_i18n( 'l, F j', strtotime( $m->created_at ) )
        : null;

    $is_grouped = ( ! $day_label && $kounselia_prev_sender === (int) $m->sender_user_id );

    $next      = isset( $kounselia_messages[ $i + 1 ] ) ? $kounselia_messages[ $i + 1 ] : null;
    $next_day  = $next ? date( 'Y-m-d', strtotime( $next->created_at ) ) : null;
    $show_time = ( ! $next || (int) $next->sender_user_id !== (int) $m->sender_user_id || $next_day !== $day_key );

    $kounselia_rows[] = array(
        'msg'        => $m,
        'day_label'  => $day_label,
        'is_grouped' => $is_grouped,
        'show_time'  => $show_time,
    );

    $kounselia_prev_day    = $day_key;
    $kounselia_prev_sender = (int) $m->sender_user_id;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kounselia Admin — Booking Conversation</title>
<meta name="robots" content="noindex, nofollow">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<?php require __DIR__ . '/../inc/admin-styles.php'; ?>
<style>
.t-bubble.flagged{box-shadow:0 0 0 2px var(--rose) inset}
.flag-note{font-size:10.5px;color:var(--rose);font-weight:600;margin:2px 4px}
</style>
</head>
<body>

<?php require __DIR__ . '/../inc/admin-nav.php'; ?>

<div class="admin-body">
  <a class="back-link" href="/portal/admin/pages/bookings.php">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M11 18l-6-6 6-6"/></svg>
    Bookings
  </a>

  <div class="transcript-header">
    <div class="transcript-avatar" style="background:var(--accent)"><?php echo esc_html( mb_strtoupper( mb_substr( $kounselia_client_label, 0, 1 ) ) ); ?></div>
    <div class="transcript-info">
      <div class="t-name"><?php echo esc_html( $kounselia_client_label ); ?> <span class="with">messaging <?php echo esc_html( $kounselia_pro_label ); ?><?php echo $kounselia_booking->pro_title ? ' (' . esc_html( $kounselia_booking->pro_title ) . ')' : ''; ?></span></div>
      <div class="t-meta">
        <span class="badge <?php echo 'confirmed' === $kounselia_booking->status ? 'active' : 'ended'; ?>"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $kounselia_booking->status ) ) ); ?></span>
        <span>Session: <?php echo esc_html( date_i18n( 'M j, Y g:i A', strtotime( $kounselia_booking->scheduled_start ) ) ); ?></span>
        <span>·</span>
        <span><?php echo esc_html( number_format_i18n( $kounselia_count ) ); ?> messages</span>
      </div>
    </div>
  </div>

  <?php if ( empty( $kounselia_messages ) ) : ?>
    <div class="panel"><div class="empty-state">No messages have been sent in this booking's thread.</div></div>
  <?php else : ?>
    <div class="transcript-scroll">
      <?php foreach ( $kounselia_rows as $row ) :
        $m       = $row['msg'];
        $is_mine = ( (int) $m->sender_user_id === (int) $kounselia_booking->client_user_id );
      ?>
        <?php if ( $row['day_label'] ) : ?>
          <div class="t-day-divider"><?php echo esc_html( $row['day_label'] ); ?></div>
        <?php endif; ?>

        <div class="t-row <?php echo $is_mine ? 'mine' : ''; ?> <?php echo $row['is_grouped'] ? 'grouped' : ''; ?>">
          <div class="t-bubble <?php echo $is_mine ? 'mine' : 'bot'; ?> <?php echo $m->flagged_safety ? 'flagged' : ''; ?>"><?php echo nl2br( esc_html( $m->content ) ); ?></div>
        </div>
        <?php if ( $m->flagged_safety ) : ?>
          <div class="flag-note <?php echo $is_mine ? '' : 'left'; ?>" style="text-align:<?php echo $is_mine ? 'right' : 'left'; ?>">⚠ matched "<?php echo esc_html( $m->flag_reason ); ?>"</div>
        <?php endif; ?>
        <?php if ( $row['show_time'] ) : ?>
          <div class="t-time <?php echo $is_mine ? '' : 'left'; ?>"><?php echo esc_html( kounselia_admin_clock_label( $m->created_at ) ); ?></div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>
</body>
</html>

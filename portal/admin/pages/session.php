<?php
/**
 * Kounselia Admin — single session transcript.
 *
 * Redesigned to read like an actual messaging app: consecutive
 * messages from the same speaker are grouped tightly together with a
 * timestamp only on the last one in the run, and a day divider appears
 * whenever the conversation crosses into a new calendar day. Every
 * view of a transcript still writes a row to the audit trail first.
 */
require_once __DIR__ . '/../inc/admin-auth.php';
require_once __DIR__ . '/../inc/admin-helpers.php';
$kounselia_admin_active = 'conversations';

global $wpdb;

$kounselia_session_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

if ( ! $kounselia_session_id ) {
    wp_die( 'No session specified. <a href="/portal/admin/pages/conversations.php">Back to conversations</a>' );
}

$kounselia_sessions_table = $wpdb->prefix . 'kounselia_sessions';
$kounselia_messages_table = $wpdb->prefix . 'kounselia_messages';

$kounselia_session = $wpdb->get_row( $wpdb->prepare(
    "SELECT * FROM {$kounselia_sessions_table} WHERE id = %d",
    $kounselia_session_id
) );

if ( ! $kounselia_session ) {
    wp_die( 'That session does not exist. <a href="/portal/admin/pages/conversations.php">Back to conversations</a>' );
}

// Record that this admin viewed this transcript, before rendering it.
kounselia_admin_log( 'view_transcript', 'session', $kounselia_session_id );

$kounselia_messages = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM {$kounselia_messages_table} WHERE session_id = %d ORDER BY created_at ASC",
    $kounselia_session_id
) );

$kounselia_who     = kounselia_admin_session_who( $kounselia_session );
$kounselia_avatar  = kounselia_admin_counselor_avatar( $kounselia_session->counselor_slug );

/* -----------------------------------------------------------------------
 * Pre-compute, for every message: which day-divider (if any) comes
 * before it, whether it's visually "grouped" with the previous bubble,
 * and whether it should show its own timestamp (only the last message
 * in a consecutive run from the same sender shows one).
 * -------------------------------------------------------------------- */
$kounselia_rows   = array();
$kounselia_prev_day    = null;
$kounselia_prev_sender = null;

$kounselia_count = count( $kounselia_messages );
foreach ( $kounselia_messages as $i => $m ) {
    $day_key   = date( 'Y-m-d', strtotime( $m->created_at ) );
    $day_label = ( $day_key !== $kounselia_prev_day )
        ? date_i18n( 'l, F j', strtotime( $m->created_at ) )
        : null;

    $is_grouped = ( ! $day_label && $kounselia_prev_sender === $m->sender );

    $next        = isset( $kounselia_messages[ $i + 1 ] ) ? $kounselia_messages[ $i + 1 ] : null;
    $next_day    = $next ? date( 'Y-m-d', strtotime( $next->created_at ) ) : null;
    $show_time   = ( ! $next || $next->sender !== $m->sender || $next_day !== $day_key );

    $kounselia_rows[] = array(
        'msg'        => $m,
        'day_label'  => $day_label,
        'is_grouped' => $is_grouped,
        'show_time'  => $show_time,
    );

    $kounselia_prev_day    = $day_key;
    $kounselia_prev_sender = $m->sender;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kounselia Admin — Transcript</title>
<meta name="robots" content="noindex, nofollow">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<?php require __DIR__ . '/../inc/admin-styles.php'; ?>
</head>
<body>

<?php require __DIR__ . '/../inc/admin-nav.php'; ?>

<div class="admin-body">
  <a class="back-link" href="/portal/admin/pages/conversations.php">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M11 18l-6-6 6-6"/></svg>
    Conversations
  </a>

  <div class="transcript-header">
    <div class="transcript-avatar" style="background:<?php echo esc_attr( $kounselia_avatar['color'] ); ?>"><?php echo esc_html( $kounselia_avatar['letter'] ); ?></div>
    <div class="transcript-info">
      <div class="t-name"><?php echo esc_html( $kounselia_who ); ?> <span class="with">with <?php echo esc_html( kounselia_admin_counselor_name( $kounselia_session->counselor_slug ) ); ?></span></div>
      <div class="t-meta">
        <span class="badge <?php echo $kounselia_session->user_id ? 'member' : 'guest'; ?>"><?php echo $kounselia_session->user_id ? 'Member' : 'Guest'; ?></span>
        <?php if ( ! $kounselia_session->ended_at ) : ?>
          <span class="badge active">Active</span>
        <?php endif; ?>
        <span><?php echo esc_html( kounselia_admin_time_label( $kounselia_session->started_at ) ); ?></span>
        <span>·</span>
        <span><?php echo esc_html( number_format_i18n( $kounselia_count ) ); ?> messages</span>
      </div>
    </div>
  </div>

  <?php if ( empty( $kounselia_messages ) ) : ?>
    <div class="panel"><div class="empty-state">No messages were ever sent in this session.</div></div>
  <?php else : ?>
    <div class="transcript-scroll">
      <?php foreach ( $kounselia_rows as $row ) :
        $m       = $row['msg'];
        $is_mine = ( 'user' === $m->sender );
      ?>
        <?php if ( $row['day_label'] ) : ?>
          <div class="t-day-divider"><?php echo esc_html( $row['day_label'] ); ?></div>
        <?php endif; ?>

        <div class="t-row <?php echo $is_mine ? 'mine' : ''; ?> <?php echo $row['is_grouped'] ? 'grouped' : ''; ?>">
          <div class="t-bubble <?php echo $is_mine ? 'mine' : 'bot'; ?>"><?php echo nl2br( esc_html( $m->content ) ); ?></div>
        </div>
        <?php if ( $row['show_time'] ) : ?>
          <div class="t-time <?php echo $is_mine ? '' : 'left'; ?>"><?php echo esc_html( kounselia_admin_clock_label( $m->created_at ) ); ?></div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>
</body>
</html>

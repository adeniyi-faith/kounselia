<?php
/**
 * Kounselia Admin — dashboard.
 *
 * Stat cards (same as before) PLUS a real recent-activity feed: the
 * last 10 conversations across the whole platform, and the last 5
 * people who signed up. Every session row links straight to its full
 * transcript on conversations.php / session.php.
 */
require_once __DIR__ . '/../inc/admin-auth.php';
require_once __DIR__ . '/../inc/admin-helpers.php';
$kounselia_admin_active = 'dashboard';

global $wpdb;

$kounselia_today          = current_time( 'Y-m-d' );
$kounselia_sessions_table = $wpdb->prefix . 'kounselia_sessions';
$kounselia_messages_table = $wpdb->prefix . 'kounselia_messages';

/* ---- Stat cards (same queries as before) ------------------------------- */
$kounselia_user_counts   = count_users();
$kounselia_total_members = isset( $kounselia_user_counts['avail_roles']['subscriber'] )
    ? (int) $kounselia_user_counts['avail_roles']['subscriber']
    : 0;

$kounselia_member_sessions_today = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$kounselia_sessions_table} WHERE user_id IS NOT NULL AND DATE(started_at) = %s",
    $kounselia_today
) );
$kounselia_guest_sessions_today = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$kounselia_sessions_table} WHERE user_id IS NULL AND DATE(started_at) = %s",
    $kounselia_today
) );
$kounselia_member_messages_today = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$kounselia_messages_table} m INNER JOIN {$kounselia_sessions_table} s ON m.session_id = s.id
     WHERE s.user_id IS NOT NULL AND DATE(m.created_at) = %s",
    $kounselia_today
) );
$kounselia_guest_messages_today = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$kounselia_messages_table} m INNER JOIN {$kounselia_sessions_table} s ON m.session_id = s.id
     WHERE s.user_id IS NULL AND DATE(m.created_at) = %s",
    $kounselia_today
) );
$kounselia_sessions_today_total = $kounselia_member_sessions_today + $kounselia_guest_sessions_today;
$kounselia_messages_today_total = $kounselia_member_messages_today + $kounselia_guest_messages_today;

/* ---- Recent activity: last 10 sessions, with message counts ----------- */
$kounselia_recent_sessions = $wpdb->get_results(
    "SELECT s.id, s.user_id, s.guest_token, s.counselor_slug, s.status, s.started_at,
            ( SELECT COUNT(*) FROM {$kounselia_messages_table} m WHERE m.session_id = s.id ) AS message_count
     FROM {$kounselia_sessions_table} s
     ORDER BY s.started_at DESC
     LIMIT 10"
);

/* ---- Recent signups: last 5 registered members ------------------------- */
$kounselia_recent_signups = get_users( array(
    'role'    => 'subscriber',
    'orderby' => 'registered',
    'order'   => 'DESC',
    'number'  => 5,
) );
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kounselia Admin — Dashboard</title>
<meta name="robots" content="noindex, nofollow">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<?php require __DIR__ . '/../inc/admin-styles.php'; ?>
</head>
<body>

<?php require __DIR__ . '/../inc/admin-nav.php'; ?>

<div class="admin-body">
  <h1 class="admin-title">Dashboard</h1>
  <div class="admin-subtitle"><?php echo esc_html( date_i18n( 'l, F j, Y', current_time( 'timestamp' ) ) ); ?></div>

  <div class="grid">
    <div class="card">
      <div class="label">Total Members</div>
      <div class="num"><?php echo esc_html( number_format_i18n( $kounselia_total_members ) ); ?></div>
    </div>
    <div class="card">
      <div class="label">Sessions Today</div>
      <div class="num"><?php echo esc_html( number_format_i18n( $kounselia_sessions_today_total ) ); ?></div>
      <div class="split">
        <span>Members: <b><?php echo esc_html( number_format_i18n( $kounselia_member_sessions_today ) ); ?></b></span>
        <span>Guests: <b><?php echo esc_html( number_format_i18n( $kounselia_guest_sessions_today ) ); ?></b></span>
      </div>
    </div>
    <div class="card">
      <div class="label">Messages Today</div>
      <div class="num"><?php echo esc_html( number_format_i18n( $kounselia_messages_today_total ) ); ?></div>
      <div class="split">
        <span>Members: <b><?php echo esc_html( number_format_i18n( $kounselia_member_messages_today ) ); ?></b></span>
        <span>Guests: <b><?php echo esc_html( number_format_i18n( $kounselia_guest_messages_today ) ); ?></b></span>
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-title">
      Recent Conversations
      <a href="/portal/admin/pages/conversations.php" style="font-size:13px">View all →</a>
    </div>
    <?php if ( empty( $kounselia_recent_sessions ) ) : ?>
      <div class="empty-state">No conversations yet.</div>
    <?php else : ?>
      <table class="admin-table">
        <thead><tr><th>Who</th><th>Counselor</th><th>Messages</th><th>Status</th><th>Started</th><th></th></tr></thead>
        <tbody>
        <?php foreach ( $kounselia_recent_sessions as $s ) : ?>
          <tr>
            <td data-label="Who">
              <span class="cell-who"><?php echo esc_html( kounselia_admin_session_who( $s ) ); ?>
              <span class="badge <?php echo $s->user_id ? 'member' : 'guest'; ?>"><?php echo $s->user_id ? 'Member' : 'Guest'; ?></span></span>
            </td>
            <td data-label="Counselor"><?php echo esc_html( kounselia_admin_counselor_name( $s->counselor_slug ) ); ?></td>
            <td data-label="Messages"><?php echo esc_html( number_format_i18n( (int) $s->message_count ) ); ?></td>
            <td data-label="Status"><span class="badge <?php echo 'active' === $s->status ? 'active' : 'ended'; ?>"><?php echo esc_html( ucfirst( $s->status ) ); ?></span></td>
            <td data-label="Started"><?php echo esc_html( kounselia_admin_time_label( $s->started_at ) ); ?></td>
            <td data-label=""><a href="/portal/admin/pages/session.php?id=<?php echo (int) $s->id; ?>">View transcript →</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="panel">
    <div class="panel-title">Recent Signups</div>
    <?php if ( empty( $kounselia_recent_signups ) ) : ?>
      <div class="empty-state">No members yet.</div>
    <?php else : ?>
      <table class="admin-table">
        <thead><tr><th>Name</th><th>Email</th><th>Joined</th></tr></thead>
        <tbody>
        <?php foreach ( $kounselia_recent_signups as $u ) : ?>
          <tr>
            <td data-label="Name"><?php echo esc_html( $u->display_name ? $u->display_name : '—' ); ?></td>
            <td data-label="Email"><?php echo esc_html( $u->user_email ); ?></td>
            <td data-label="Joined"><?php echo esc_html( kounselia_admin_time_label( $u->user_registered ) ); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

</div>
</body>
</html>

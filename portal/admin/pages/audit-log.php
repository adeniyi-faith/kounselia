<?php
/**
 * Kounselia Admin — audit log.
 *
 * Read-only view of the kounselia_admin_audit_log table: every
 * transcript view (and, over time, every other logged admin action)
 * with who did it, when, and from which IP.
 */
require_once __DIR__ . '/../inc/admin-auth.php';
require_once __DIR__ . '/../inc/admin-helpers.php';
$kounselia_admin_active = 'audit-log';

global $wpdb;

$kounselia_audit_table = $wpdb->prefix . 'kounselia_admin_audit_log';
$kounselia_page        = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
$kounselia_per_page    = 40;
$kounselia_offset      = ( $kounselia_page - 1 ) * $kounselia_per_page;

$kounselia_total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$kounselia_audit_table}" );
$kounselia_total_pages = max( 1, (int) ceil( $kounselia_total / $kounselia_per_page ) );

$kounselia_rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM {$kounselia_audit_table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
    $kounselia_per_page,
    $kounselia_offset
) );

$kounselia_action_labels = array(
    'view_transcript'       => 'Viewed transcript',
    'view_member'           => 'Viewed member profile',
    'add_safety_keyword'    => 'Added safety keyword',
    'remove_safety_keyword' => 'Removed safety keyword',
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kounselia Admin — Audit Log</title>
<meta name="robots" content="noindex, nofollow">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<?php require __DIR__ . '/../inc/admin-styles.php'; ?>
</head>
<body>

<?php require __DIR__ . '/../inc/admin-nav.php'; ?>

<div class="admin-body">
  <h1 class="admin-title">Audit Log</h1>
  <div class="admin-subtitle"><?php echo esc_html( number_format_i18n( $kounselia_total ) ); ?> recorded actions · every transcript view is logged here</div>

  <div class="panel">
    <?php if ( empty( $kounselia_rows ) ) : ?>
      <div class="empty-state">Nothing logged yet.</div>
    <?php else : ?>
      <table class="admin-table">
        <thead><tr><th>Admin</th><th>Action</th><th>Target</th><th>IP</th><th>When</th></tr></thead>
        <tbody>
        <?php foreach ( $kounselia_rows as $row ) :
          $admin_user = get_userdata( $row->admin_id );
          if ( isset( $kounselia_action_labels[ $row->action ] ) ) {
              $label = $kounselia_action_labels[ $row->action ];
          } elseif ( strpos( $row->action, 'edit_counselor_' ) === 0 ) {
              $label = 'Edited counselor "' . kounselia_admin_counselor_name( substr( $row->action, strlen( 'edit_counselor_' ) ) ) . '"';
          } else {
              $label = $row->action;
          }
        ?>
          <tr>
            <td data-label="Admin"><?php echo esc_html( $admin_user ? $admin_user->display_name : 'Admin #' . (int) $row->admin_id ); ?></td>
            <td data-label="Action"><?php echo esc_html( $label ); ?></td>
            <td data-label="Target">
              <?php if ( 'session' === $row->target_type && $row->target_id ) : ?>
                <a href="/portal/admin/pages/session.php?id=<?php echo (int) $row->target_id; ?>">Session #<?php echo (int) $row->target_id; ?></a>
              <?php elseif ( 'member' === $row->target_type && $row->target_id ) :
                $member = get_userdata( $row->target_id );
              ?>
                <a href="/portal/admin/pages/member.php?id=<?php echo (int) $row->target_id; ?>"><?php echo esc_html( $member ? $member->display_name : 'Member #' . (int) $row->target_id ); ?></a>
              <?php else : ?>
                <?php echo esc_html( $row->target_type ? $row->target_type . ' #' . (int) $row->target_id : '—' ); ?>
              <?php endif; ?>
            </td>
            <td data-label="IP"><?php echo esc_html( $row->ip_address ? $row->ip_address : '—' ); ?></td>
            <td data-label="When"><?php echo esc_html( kounselia_admin_time_label( $row->created_at ) ); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <div class="pagination">
        <?php if ( $kounselia_page > 1 ) : ?>
          <a href="/portal/admin/pages/audit-log.php?paged=<?php echo (int) ( $kounselia_page - 1 ); ?>">← Prev</a>
        <?php endif; ?>
        <span class="current"><?php echo (int) $kounselia_page; ?></span>
        <span>of <?php echo (int) $kounselia_total_pages; ?></span>
        <?php if ( $kounselia_page < $kounselia_total_pages ) : ?>
          <a href="/portal/admin/pages/audit-log.php?paged=<?php echo (int) ( $kounselia_page + 1 ); ?>">Next →</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>

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

$kounselia_filter_admin  = isset( $_GET['admin'] ) ? (int) $_GET['admin'] : 0;
$kounselia_filter_action = isset( $_GET['action_filter'] ) ? sanitize_key( $_GET['action_filter'] ) : '';

$kounselia_where  = array( '1=1' );
$kounselia_params = array();
if ( $kounselia_filter_admin ) {
    $kounselia_where[]  = 'admin_id = %d';
    $kounselia_params[] = $kounselia_filter_admin;
}
if ( $kounselia_filter_action ) {
    $kounselia_where[]  = 'action = %s';
    $kounselia_params[] = $kounselia_filter_action;
}
$kounselia_where_sql = implode( ' AND ', $kounselia_where );

$kounselia_count_sql = "SELECT COUNT(*) FROM {$kounselia_audit_table} WHERE {$kounselia_where_sql}";
$kounselia_total     = $kounselia_params
    ? (int) $wpdb->get_var( $wpdb->prepare( $kounselia_count_sql, $kounselia_params ) )
    : (int) $wpdb->get_var( $kounselia_count_sql );
$kounselia_total_pages = max( 1, (int) ceil( $kounselia_total / $kounselia_per_page ) );

$kounselia_rows_sql = "SELECT * FROM {$kounselia_audit_table} WHERE {$kounselia_where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
$kounselia_rows     = $wpdb->get_results( $wpdb->prepare( $kounselia_rows_sql, array_merge( $kounselia_params, array( $kounselia_per_page, $kounselia_offset ) ) ) );

// Every admin who has ever logged an action, for the filter dropdown.
$kounselia_admin_ids = $wpdb->get_col( "SELECT DISTINCT admin_id FROM {$kounselia_audit_table} ORDER BY admin_id" );
// Every distinct action ever logged, for the other filter dropdown.
$kounselia_distinct_actions = $wpdb->get_col( "SELECT DISTINCT action FROM {$kounselia_audit_table} ORDER BY action" );

$kounselia_action_labels = array(
    'view_transcript'            => 'Viewed transcript',
    'view_member'                => 'Viewed member profile',
    'add_safety_keyword'         => 'Added safety keyword',
    'remove_safety_keyword'      => 'Removed safety keyword',
    'bulk_import_safety_keywords'=> 'Bulk-imported safety keywords',
    'reset_safety_keywords'      => 'Reset safety keywords to defaults',
    'acknowledge_safety_escalation' => 'Acknowledged a safety escalation',
    'created_staff'              => 'Created a staff account',
    'edited_staff'               => 'Edited a staff account',
    'deleted_staff'              => 'Removed a staff account',
    'sent_broadcast'             => 'Sent an email broadcast',
    'edited_counselor'           => 'Edited an AI counselor',
    'banned_user'                => 'Banned a member',
    'unbanned_user'              => 'Unbanned a member',
    'upgraded_user'              => 'Upgraded a member to Pro',
    'downgraded_user'            => 'Downgraded a member to Free',
    'soft_deleted_user'          => 'Moved a member to Trash',
    'restored_user'              => 'Restored a member from Trash',
    'purged_user'                => 'Permanently deleted a member',
    'bulk_ban_users'             => 'Bulk-banned members',
    'bulk_unban_users'           => 'Bulk-unbanned members',
    'bulk_upgrade_users'         => 'Bulk-upgraded members to Pro',
    'bulk_downgrade_users'       => 'Bulk-downgraded members to Free',
    'bulk_delete_users'          => 'Bulk-moved members to Trash',
    'bulk_restore_users'         => 'Bulk-restored members from Trash',
    'enabled_2fa'                => 'Enabled two-factor authentication',
    'disabled_2fa'               => 'Disabled two-factor authentication',
    'admin_login'                => 'Signed in',
    'revoked_session'            => 'Signed out one session',
    'revoked_own_sessions'       => 'Signed out their other sessions',
    'revoked_all_sessions'       => 'Forced a full sign-out on an account',
    'update_settings'            => 'Updated Gemini API keys',
    'test_gemini_key'            => 'Ran an AI connection test',
    'flush_tts_cache'            => 'Purged the voice (TTS) cache',
    'reset_rate_limits'          => 'Reset rate limits',
    'force_db_schema_sync'       => 'Re-synced the database schema',
    'update_safety_alert_emails' => 'Updated safety alert recipients',
    'update_platform_settings'   => 'Updated platform configuration',
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
  <div class="admin-subtitle"><?php echo esc_html( number_format_i18n( $kounselia_total ) ); ?> recorded actions</div>

  <form method="get" class="filters" style="margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
    <select name="admin" onchange="this.form.submit()" style="padding:8px 12px;border:1px solid var(--border);border-radius:6px;font-family:inherit;font-size:13px;">
      <option value="">All admins</option>
      <?php foreach ( $kounselia_admin_ids as $kounselia_aid ) :
        $kounselia_au = get_userdata( $kounselia_aid );
      ?>
        <option value="<?php echo (int) $kounselia_aid; ?>" <?php selected( $kounselia_filter_admin, (int) $kounselia_aid ); ?>>
          <?php echo esc_html( $kounselia_au ? $kounselia_au->display_name : 'Admin #' . (int) $kounselia_aid ); ?>
        </option>
      <?php endforeach; ?>
    </select>
    <select name="action_filter" onchange="this.form.submit()" style="padding:8px 12px;border:1px solid var(--border);border-radius:6px;font-family:inherit;font-size:13px;">
      <option value="">All actions</option>
      <?php foreach ( $kounselia_distinct_actions as $kounselia_a ) : ?>
        <option value="<?php echo esc_attr( $kounselia_a ); ?>" <?php selected( $kounselia_filter_action, $kounselia_a ); ?>>
          <?php echo esc_html( isset( $kounselia_action_labels[ $kounselia_a ] ) ? $kounselia_action_labels[ $kounselia_a ] : $kounselia_a ); ?>
        </option>
      <?php endforeach; ?>
    </select>
    <?php if ( $kounselia_filter_admin || $kounselia_filter_action ) : ?>
      <a href="/portal/admin/pages/audit-log.php" style="font-size:12.5px;color:var(--text3);">Clear filters</a>
    <?php endif; ?>
  </form>

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

      <?php
      $kounselia_qs_base = array();
      if ( $kounselia_filter_admin ) { $kounselia_qs_base['admin'] = $kounselia_filter_admin; }
      if ( $kounselia_filter_action ) { $kounselia_qs_base['action_filter'] = $kounselia_filter_action; }
      ?>
      <div class="pagination">
        <?php if ( $kounselia_page > 1 ) : ?>
          <a href="/portal/admin/pages/audit-log.php?<?php echo esc_attr( http_build_query( array_merge( $kounselia_qs_base, array( 'paged' => $kounselia_page - 1 ) ) ) ); ?>">← Prev</a>
        <?php endif; ?>
        <span class="current"><?php echo (int) $kounselia_page; ?></span>
        <span>of <?php echo (int) $kounselia_total_pages; ?></span>
        <?php if ( $kounselia_page < $kounselia_total_pages ) : ?>
          <a href="/portal/admin/pages/audit-log.php?<?php echo esc_attr( http_build_query( array_merge( $kounselia_qs_base, array( 'paged' => $kounselia_page + 1 ) ) ) ); ?>">Next →</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>

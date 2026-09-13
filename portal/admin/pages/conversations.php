<?php
/**
 * Kounselia Admin — conversations list.
 *
 * Every session across the whole platform, searchable by member name
 * or email, filterable by counselor and by member/guest, paginated.
 * Each row links to session.php for the full transcript.
 */
require_once __DIR__ . '/../inc/admin-auth.php';
require_once __DIR__ . '/../inc/admin-helpers.php';
$kounselia_admin_active = 'conversations';

global $wpdb;

$kounselia_sessions_table = $wpdb->prefix . 'kounselia_sessions';
$kounselia_messages_table = $wpdb->prefix . 'kounselia_messages';
$kounselia_users_table    = $wpdb->users;

/* ---- Read filters from the query string -------------------------------- */
$kounselia_q         = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
$kounselia_counselor  = isset( $_GET['counselor'] ) ? sanitize_key( $_GET['counselor'] ) : '';
$kounselia_type       = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : ''; // 'member' | 'guest' | ''
$kounselia_page       = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
$kounselia_per_page   = 25;
$kounselia_offset     = ( $kounselia_page - 1 ) * $kounselia_per_page;

/* ---- Build the WHERE clause safely, piece by piece --------------------- */
$kounselia_where  = array( '1=1' );
$kounselia_params = array();

if ( $kounselia_q !== '' ) {
    $like = '%' . $wpdb->esc_like( $kounselia_q ) . '%';
    $kounselia_where[]  = "( s.user_id IN ( SELECT ID FROM {$kounselia_users_table} WHERE display_name LIKE %s OR user_email LIKE %s ) OR s.guest_token LIKE %s )";
    $kounselia_params[] = $like;
    $kounselia_params[] = $like;
    $kounselia_params[] = $like;
}

if ( $kounselia_counselor !== '' && in_array( $kounselia_counselor, kounselia_counselor_slugs(), true ) ) {
    $kounselia_where[]  = 's.counselor_slug = %s';
    $kounselia_params[] = $kounselia_counselor;
}

if ( 'member' === $kounselia_type ) {
    $kounselia_where[] = 's.user_id IS NOT NULL';
} elseif ( 'guest' === $kounselia_type ) {
    $kounselia_where[] = 's.user_id IS NULL';
}

$kounselia_where_sql = implode( ' AND ', $kounselia_where );

/* ---- Total count, for pagination ---------------------------------------- */
$kounselia_count_sql = "SELECT COUNT(*) FROM {$kounselia_sessions_table} s WHERE {$kounselia_where_sql}";
$kounselia_total     = $kounselia_params
    ? (int) $wpdb->get_var( $wpdb->prepare( $kounselia_count_sql, $kounselia_params ) )
    : (int) $wpdb->get_var( $kounselia_count_sql );

$kounselia_total_pages = max( 1, (int) ceil( $kounselia_total / $kounselia_per_page ) );

/* ---- The actual page of results ----------------------------------------- */
$kounselia_list_sql = "SELECT s.id, s.user_id, s.guest_token, s.counselor_slug, s.status, s.started_at, s.ended_at,
        ( SELECT COUNT(*) FROM {$kounselia_messages_table} m WHERE m.session_id = s.id ) AS message_count
     FROM {$kounselia_sessions_table} s
     WHERE {$kounselia_where_sql}
     ORDER BY s.started_at DESC
     LIMIT %d OFFSET %d";

$kounselia_list_params   = array_merge( $kounselia_params, array( $kounselia_per_page, $kounselia_offset ) );
$kounselia_sessions_page = $wpdb->get_results( $wpdb->prepare( $kounselia_list_sql, $kounselia_list_params ) );

/* ---- Helper to rebuild the query string when changing page/filter ------- */
function kounselia_conv_url( $overrides = array() ) {
    $params = array_merge( $_GET, $overrides );
    foreach ( $params as $k => $v ) {
        if ( '' === $v || null === $v ) {
            unset( $params[ $k ] );
        }
    }
    return '/portal/admin/pages/conversations.php' . ( $params ? '?' . http_build_query( $params ) : '' );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kounselia Admin — Conversations</title>
<meta name="robots" content="noindex, nofollow">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<?php require __DIR__ . '/../inc/admin-styles.php'; ?>
</head>
<body>

<?php require __DIR__ . '/../inc/admin-nav.php'; ?>

<div class="admin-body">
  <h1 class="admin-title">Conversations</h1>
  <div class="admin-subtitle"><?php echo esc_html( number_format_i18n( $kounselia_total ) ); ?> total</div>

  <form method="get" class="filters">
    <input type="text" name="q" placeholder="Search name, email, or guest token…" value="<?php echo esc_attr( $kounselia_q ); ?>">
    <select name="counselor">
      <option value="">All counselors</option>
      <?php foreach ( kounselia_counselor_slugs() as $slug ) : ?>
        <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $kounselia_counselor, $slug ); ?>><?php echo esc_html( kounselia_admin_counselor_name( $slug ) ); ?></option>
      <?php endforeach; ?>
    </select>
    <select name="type">
      <option value="">Members and guests</option>
      <option value="member" <?php selected( $kounselia_type, 'member' ); ?>>Members only</option>
      <option value="guest" <?php selected( $kounselia_type, 'guest' ); ?>>Guests only</option>
    </select>
    <button type="submit">Filter</button>
    <?php if ( $kounselia_q || $kounselia_counselor || $kounselia_type ) : ?>
      <a class="clear" href="/portal/admin/pages/conversations.php">Clear filters</a>
    <?php endif; ?>
  </form>

  <div class="panel">
    <?php if ( empty( $kounselia_sessions_page ) ) : ?>
      <div class="empty-state">No conversations match these filters.</div>
    <?php else : ?>
      <table class="admin-table">
        <thead><tr><th>Who</th><th>Counselor</th><th>Messages</th><th>Status</th><th>Started</th><th>Ended</th><th></th></tr></thead>
        <tbody>
        <?php foreach ( $kounselia_sessions_page as $s ) : ?>
          <tr>
            <td data-label="Who">
              <span class="cell-who"><?php echo esc_html( kounselia_admin_session_who( $s ) ); ?>
              <span class="badge <?php echo $s->user_id ? 'member' : 'guest'; ?>"><?php echo $s->user_id ? 'Member' : 'Guest'; ?></span></span>
            </td>
            <td data-label="Counselor"><?php echo esc_html( kounselia_admin_counselor_name( $s->counselor_slug ) ); ?></td>
            <td data-label="Messages"><?php echo esc_html( number_format_i18n( (int) $s->message_count ) ); ?></td>
            <td data-label="Status"><span class="badge <?php echo 'active' === $s->status ? 'active' : 'ended'; ?>"><?php echo esc_html( ucfirst( $s->status ) ); ?></span></td>
            <td data-label="Started"><?php echo esc_html( kounselia_admin_time_label( $s->started_at ) ); ?></td>
            <td data-label="Ended"><?php echo $s->ended_at ? esc_html( kounselia_admin_time_label( $s->ended_at ) ) : '—'; ?></td>
            <td data-label=""><a href="/portal/admin/pages/session.php?id=<?php echo (int) $s->id; ?>">View transcript →</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <div class="pagination">
        <?php if ( $kounselia_page > 1 ) : ?>
          <a href="<?php echo esc_url( kounselia_conv_url( array( 'paged' => $kounselia_page - 1 ) ) ); ?>">← Prev</a>
        <?php endif; ?>
        <span class="current"><?php echo (int) $kounselia_page; ?></span>
        <span>of <?php echo (int) $kounselia_total_pages; ?></span>
        <?php if ( $kounselia_page < $kounselia_total_pages ) : ?>
          <a href="<?php echo esc_url( kounselia_conv_url( array( 'paged' => $kounselia_page + 1 ) ) ); ?>">Next →</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

</div>
</body>
</html>

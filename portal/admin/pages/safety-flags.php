<?php
/**
 * Kounselia Admin — safety.
 *
 * Two things on one page, because they're two sides of the same job:
 *   1. Every message that matched a safety keyword, most recent first,
 *      linking straight to its full transcript.
 *   2. The keyword list itself, editable right here, add a word or
 *      phrase and it applies to every new message from that moment on
 *      (older messages are not retroactively re-scanned).
 *
 * Reminder for whoever reads this later: keyword matching is a first
 * net, not a guarantee. It catches obvious phrasing and will miss
 * anything worded differently, and it can also flag something
 * harmless that happens to contain a matching phrase. Treat the list
 * below as "worth a look," not as a confirmed emergency list.
 */
require_once __DIR__ . '/../inc/admin-auth.php';
require_once __DIR__ . '/../inc/admin-helpers.php';
$kounselia_admin_active = 'safety';

global $wpdb;

$kounselia_sessions_table = $wpdb->prefix . 'kounselia_sessions';
$kounselia_messages_table = $wpdb->prefix . 'kounselia_messages';

/* -------------------------------------------------------------------------
 * Handle keyword add/remove first (POST-redirect-GET, so a page reload
 * never resubmits the form).
 * -------------------------------------------------------------------- */
if ( 'POST' === $_SERVER['REQUEST_METHOD'] && in_array( $_POST['kounselia_safety_action'] ?? '', array( 'add', 'remove' ), true ) ) {

    if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'kounselia_safety_keywords' ) ) {
        wp_die( 'Security check failed, please go back and try again.' );
    }

    $kounselia_action   = sanitize_key( $_POST['kounselia_safety_action'] );
    $kounselia_keywords = kounselia_get_safety_keywords();

    if ( 'add' === $kounselia_action && ! empty( $_POST['keyword'] ) ) {
        $new_kw = strtolower( trim( sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) ) );
        if ( '' !== $new_kw && ! in_array( $new_kw, $kounselia_keywords, true ) ) {
            $kounselia_keywords[] = $new_kw;
            kounselia_update_safety_keywords( $kounselia_keywords );
            kounselia_admin_log( 'add_safety_keyword', 'keyword', 0 );
        }
    } elseif ( 'remove' === $kounselia_action && isset( $_POST['keyword'] ) ) {
        $remove_kw = strtolower( trim( sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) ) );
        $kounselia_keywords = array_values( array_filter( $kounselia_keywords, function( $kw ) use ( $remove_kw ) {
            return $kw !== $remove_kw;
        } ) );
        kounselia_update_safety_keywords( $kounselia_keywords );
        kounselia_admin_log( 'remove_safety_keyword', 'keyword', 0 );
    }

    wp_safe_redirect( '/portal/admin/pages/safety-flags.php' );
    exit;
}

// Acknowledge action (own handler, since it also needs to write the escalation row).
kounselia_handle_safety_acknowledge_post();

$kounselia_keywords = kounselia_get_safety_keywords();
sort( $kounselia_keywords );

/* -------------------------------------------------------------------------
 * Flagged messages from both conversation surfaces — the AI chat and a
 * private booking thread — merged into one most-recent-first list. Two
 * separate queries (their source rows don't share a shape) rather than
 * a UNION, sorted and paged together in PHP; flagged-message volume is
 * never large enough for that to matter. Each row carries the
 * escalation case joined in: severity (was staff paged immediately?)
 * and whether anyone's acknowledged it yet.
 * -------------------------------------------------------------------- */
$kounselia_escalations_table = $wpdb->prefix . 'kounselia_safety_escalations';
$kounselia_booking_msgs_table = $wpdb->prefix . 'kounselia_booking_messages';

$kounselia_page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
$kounselia_per_page = 20;
$kounselia_offset   = ( $kounselia_page - 1 ) * $kounselia_per_page;

$kounselia_ai_flagged = $wpdb->get_results(
    "SELECT 'ai_chat' AS source, m.id AS message_id, m.session_id, NULL AS booking_id, m.content, m.flag_reason, m.created_at,
            s.user_id, s.guest_token, s.counselor_slug,
            e.id AS escalation_id, e.severity, e.status, e.acknowledged_by, e.acknowledged_at
     FROM {$kounselia_messages_table} m
     INNER JOIN {$kounselia_sessions_table} s ON m.session_id = s.id
     LEFT JOIN {$kounselia_escalations_table} e ON e.message_id = m.id AND e.source = 'ai_chat'
     WHERE m.flagged_safety = 1"
);

$kounselia_booking_flagged = $wpdb->get_results(
    "SELECT 'booking_message' AS source, bm.id AS message_id, NULL AS session_id, bm.booking_id, bm.content, bm.flag_reason, bm.created_at,
            bm.sender_user_id AS user_id, NULL AS guest_token, NULL AS counselor_slug, pu.display_name AS pro_name,
            e.id AS escalation_id, e.severity, e.status, e.acknowledged_by, e.acknowledged_at
     FROM {$kounselia_booking_msgs_table} bm
     INNER JOIN {$wpdb->prefix}kounselia_bookings b ON b.id = bm.booking_id
     INNER JOIN {$wpdb->prefix}kounselia_professionals p ON p.id = b.professional_id
     LEFT JOIN {$wpdb->users} pu ON pu.ID = p.user_id
     LEFT JOIN {$kounselia_escalations_table} e ON e.booking_message_id = bm.id AND e.source = 'booking_message'
     WHERE bm.flagged_safety = 1"
);

$kounselia_all_flagged = array_merge( $kounselia_ai_flagged, $kounselia_booking_flagged );
usort( $kounselia_all_flagged, function( $a, $b ) {
    return strtotime( $b->created_at ) <=> strtotime( $a->created_at );
} );

$kounselia_total = count( $kounselia_all_flagged );
$kounselia_total_pages = max( 1, (int) ceil( $kounselia_total / $kounselia_per_page ) );
$kounselia_flagged = array_slice( $kounselia_all_flagged, $kounselia_offset, $kounselia_per_page );

$kounselia_open_critical = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$kounselia_escalations_table} WHERE severity = 'critical' AND status = 'open'"
);

$kounselia_nonce = wp_create_nonce( 'kounselia_safety_keywords' );
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kounselia Admin — Safety</title>
<meta name="robots" content="noindex, nofollow">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<?php require __DIR__ . '/../inc/admin-styles.php'; ?>
<style>
.kw-chip{display:inline-flex;align-items:center;gap:6px;background:var(--rose-light);color:var(--rose);border-radius:var(--r-full);padding:5px 8px 5px 12px;font-size:12.5px;font-weight:500;margin:0 6px 6px 0}
.kw-chip button{background:none;border:none;color:var(--rose);cursor:pointer;padding:2px;display:flex;opacity:.7}
.kw-chip button:hover{opacity:1}
.kw-chip svg{width:13px;height:13px}
.kw-list{margin-bottom:16px}
.kw-add{display:flex;gap:8px;flex-wrap:wrap}
.kw-add input[type=text]{
  flex:1;min-width:180px;padding:10px 14px;border:1px solid var(--border);border-radius:var(--r-sm);
  font-size:13.5px;font-family:inherit;background:var(--bg);color:var(--text);
}
.kw-add input[type=text]:focus{outline:none;border-color:var(--accent);background:var(--surface)}
.kw-add button[type=submit]{
  padding:10px 18px;border:none;border-radius:var(--r-sm);background:var(--accent);color:#fff;
  font-size:13.5px;font-weight:500;cursor:pointer;font-family:inherit;white-space:nowrap;
  transition:background .15s var(--ease);
}
.kw-add button[type=submit]:hover{background:var(--accent2)}
@media (max-width:480px){ .kw-add input[type=text]{width:100%;flex:1 1 100%} .kw-add button[type=submit]{flex:1} }
.flag-snippet{font-size:13px;color:var(--text2);max-width:360px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-block;vertical-align:middle}
.flag-reason{font-size:11px;color:var(--rose);font-weight:600}
.note{background:var(--gold-light);border:1px solid #EBD9BC;color:#6B4A1F;border-radius:var(--r-md);padding:12px 16px;font-size:13px;margin-bottom:20px;line-height:1.5}
.alert-banner{background:#FBE4E4;border:1px solid #E8A9A9;color:#7A1F1F;border-radius:var(--r-md);padding:14px 16px;font-size:13.5px;font-weight:600;margin-bottom:20px}
.sev-badge{display:inline-block;border-radius:var(--r-full);padding:3px 10px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.02em}
.sev-critical{background:#FBE4E4;color:#A31F1F}
.sev-elevated{background:var(--gold-light);color:#6B4A1F}
.status-open{color:var(--rose);font-weight:600;font-size:12px}
.status-acknowledged{color:var(--text3);font-size:12px}
.ack-btn{background:none;border:1px solid var(--border);border-radius:var(--r-sm);padding:5px 10px;font-size:12px;cursor:pointer;font-family:inherit;color:var(--text2)}
.ack-btn:hover{background:var(--accent);color:#fff;border-color:var(--accent)}
</style>
</head>
<body>

<?php require __DIR__ . '/../inc/admin-nav.php'; ?>

<div class="admin-body">
  <h1 class="admin-title">Safety</h1>
  <div class="admin-subtitle"><?php echo esc_html( number_format_i18n( $kounselia_total ) ); ?> flagged messages, all time</div>

  <?php if ( $kounselia_open_critical > 0 ) : ?>
    <div class="alert-banner">⚠ <?php echo (int) $kounselia_open_critical; ?> critical case<?php echo $kounselia_open_critical === 1 ? '' : 's'; ?> still open — staff was already alerted by email, this needs a look now.</div>
  <?php endif; ?>

  <div class="note">
    This covers every place someone could say something concerning — the AI counselor chat, and a private message thread with a human professional. Keyword matching catches obvious phrasing quickly, but it can miss things worded differently and can occasionally flag something harmless. A <span class="sev-badge sev-critical">Critical</span> match pages every admin/staff member by email the moment it happens; an <span class="sev-badge sev-elevated">Elevated</span> match is queued here for review without an alert. Treat both as "worth a look," and keep an eye on new conversations directly from time to time too.
  </div>

  <div class="panel">
    <div class="panel-title">Keyword List <span style="font-weight:400;color:var(--text3);font-size:12.5px"><?php echo count( $kounselia_keywords ); ?> keywords</span></div>

    <div class="kw-list">
      <?php if ( empty( $kounselia_keywords ) ) : ?>
        <div class="empty-state" style="padding:12px 0">No keywords set.</div>
      <?php else : foreach ( $kounselia_keywords as $kw ) : ?>
        <span class="kw-chip">
          <?php echo esc_html( $kw ); ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $kounselia_nonce ); ?>">
            <input type="hidden" name="kounselia_safety_action" value="remove">
            <input type="hidden" name="keyword" value="<?php echo esc_attr( $kw ); ?>">
            <button type="submit" title="Remove" aria-label="Remove &quot;<?php echo esc_attr( $kw ); ?>&quot;">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
            </button>
          </form>
        </span>
      <?php endforeach; endif; ?>
    </div>

    <form method="post" class="kw-add">
      <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $kounselia_nonce ); ?>">
      <input type="hidden" name="kounselia_safety_action" value="add">
      <input type="text" name="keyword" placeholder="Add a word or phrase to watch for…" required>
      <button type="submit">Add keyword</button>
    </form>
  </div>

  <div class="panel">
    <div class="panel-title">Flagged Conversations</div>
    <?php if ( empty( $kounselia_flagged ) ) : ?>
      <div class="empty-state">No flagged messages yet.</div>
    <?php else : ?>
      <table class="admin-table">
        <thead><tr><th>Who</th><th>Where</th><th>Severity</th><th>Matched</th><th>Message</th><th>Status</th><th>When</th><th></th></tr></thead>
        <tbody>
        <?php foreach ( $kounselia_flagged as $row ) :
          $is_booking_row = ( 'booking_message' === $row->source );
        ?>
          <tr>
            <td data-label="Who">
              <span class="cell-who"><?php echo esc_html( kounselia_admin_session_who( $row ) ); ?>
              <span class="badge <?php echo $row->user_id ? 'member' : 'guest'; ?>"><?php echo $row->user_id ? 'Member' : 'Guest'; ?></span></span>
            </td>
            <td data-label="Where"><?php
              echo $is_booking_row
                ? 'Messaging ' . esc_html( $row->pro_name ?: 'a professional' )
                : esc_html( kounselia_admin_counselor_name( $row->counselor_slug ) );
            ?></td>
            <td data-label="Severity">
              <?php if ( $row->severity ) : ?>
                <span class="sev-badge sev-<?php echo esc_attr( $row->severity ); ?>"><?php echo esc_html( ucfirst( $row->severity ) ); ?></span>
              <?php else : ?>
                <span class="sev-badge sev-elevated">Elevated</span>
              <?php endif; ?>
            </td>
            <td data-label="Matched"><span class="flag-reason">"<?php echo esc_html( $row->flag_reason ); ?>"</span></td>
            <td data-label="Message"><span class="flag-snippet" title="<?php echo esc_attr( $row->content ); ?>"><?php echo esc_html( $row->content ); ?></span></td>
            <td data-label="Status">
              <?php if ( $row->status === 'acknowledged' ) : ?>
                <span class="status-acknowledged">✓ Acknowledged<?php
                  if ( $row->acknowledged_by ) {
                    $ack_user = get_userdata( $row->acknowledged_by );
                    if ( $ack_user ) {
                      echo ' by ' . esc_html( $ack_user->display_name ?: $ack_user->user_email );
                    }
                  }
                ?></span>
              <?php elseif ( $row->escalation_id ) : ?>
                <span class="status-open">Open</span>
                <form method="post" style="display:inline;margin-left:8px">
                  <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $kounselia_nonce ); ?>">
                  <input type="hidden" name="kounselia_safety_action" value="acknowledge">
                  <input type="hidden" name="escalation_id" value="<?php echo (int) $row->escalation_id; ?>">
                  <button type="submit" class="ack-btn">Acknowledge</button>
                </form>
              <?php else : ?>
                <span class="status-open">—</span>
              <?php endif; ?>
            </td>
            <td data-label="When"><?php echo esc_html( kounselia_admin_time_label( $row->created_at ) ); ?></td>
            <td data-label=""><?php if ( $is_booking_row ) : ?>
              <a href="/portal/admin/pages/booking-messages.php?booking_id=<?php echo (int) $row->booking_id; ?>">View conversation →</a>
            <?php else : ?>
              <a href="/portal/admin/pages/session.php?id=<?php echo (int) $row->session_id; ?>">View transcript →</a>
            <?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <div class="pagination">
        <?php if ( $kounselia_page > 1 ) : ?>
          <a href="/portal/admin/pages/safety-flags.php?paged=<?php echo (int) ( $kounselia_page - 1 ); ?>">← Prev</a>
        <?php endif; ?>
        <span class="current"><?php echo (int) $kounselia_page; ?></span>
        <span>of <?php echo (int) $kounselia_total_pages; ?></span>
        <?php if ( $kounselia_page < $kounselia_total_pages ) : ?>
          <a href="/portal/admin/pages/safety-flags.php?paged=<?php echo (int) ( $kounselia_page + 1 ); ?>">Next →</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

</div>
</body>
</html>

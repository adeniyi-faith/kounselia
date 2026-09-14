<?php
/**
 * Kounselia Admin — professional applications.
 *
 * Every application submitted via /apply.php lands here. An admin reads
 * the professional's details, opens their uploaded documents (served
 * through the authenticated viewer in professionals.php, never a public
 * URL), and approves or rejects. Approving grants the
 * kounselia_professional WP role; rejecting records a reason the
 * applicant sees by email and can act on if they reapply.
 */
require_once __DIR__ . '/../inc/admin-auth.php';
require_once __DIR__ . '/../inc/admin-helpers.php';
$kounselia_admin_active = 'professionals';

global $wpdb;
$nonce = wp_create_nonce( 'kounselia_admin_nonce' );

$status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'pending';
if ( ! in_array( $status_filter, array( 'pending', 'verified', 'rejected', 'all' ), true ) ) {
    $status_filter = 'pending';
}

$table = $wpdb->prefix . 'kounselia_professionals';

$where = '';
if ( 'all' !== $status_filter ) {
    $where = $wpdb->prepare( 'WHERE status = %s', $status_filter );
}

$applications = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY submitted_at DESC" );

$counts = array();
foreach ( array( 'pending', 'verified', 'rejected' ) as $s ) {
    $counts[ $s ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $s ) );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kounselia Admin — Professionals</title>
<meta name="robots" content="noindex, nofollow">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<?php require __DIR__ . '/../inc/admin-styles.php'; ?>
<style>
.tab-row{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap}
.tab-link{padding:7px 14px;border-radius:var(--r-full);font-size:13px;font-weight:500;color:var(--text2);border:1px solid var(--border);text-decoration:none}
.tab-link.active{background:var(--accent);color:#fff;border-color:var(--accent)}
.app-card{border:1px solid var(--border);border-radius:var(--r-md);padding:16px 18px;margin-bottom:14px}
.app-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:10px}
.app-name{font-size:15px;font-weight:600;color:var(--text)}
.app-title{font-size:13px;color:var(--text2)}
.app-meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;font-size:12.5px;color:var(--text2);margin:12px 0}
.app-meta strong{display:block;color:var(--text3);font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px}
.app-bio{font-size:13px;color:var(--text2);line-height:1.5;margin:10px 0;padding:10px 12px;background:var(--bg2,#F8FAFC);border-radius:8px}
.doc-links{display:flex;gap:10px;flex-wrap:wrap;margin:10px 0}
.doc-links a{font-size:12.5px;font-weight:500;color:var(--accent);display:inline-flex;align-items:center;gap:4px}
.app-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;align-items:flex-start}
.app-actions textarea{flex:1;min-width:220px;padding:8px 10px;border:1px solid var(--border);border-radius:8px;font-family:inherit;font-size:12.5px;resize:vertical;min-height:38px}
.btn-approve{background:var(--sage,#2E5C3E);color:#fff;border:none;border-radius:var(--r-sm);padding:9px 16px;font-size:12.5px;font-weight:600;cursor:pointer;font-family:inherit}
.btn-reject{background:none;color:var(--rose);border:1px solid var(--rose);border-radius:var(--r-sm);padding:9px 16px;font-size:12.5px;font-weight:600;cursor:pointer;font-family:inherit}
.btn-approve:disabled,.btn-reject:disabled{opacity:.5;cursor:not-allowed}
.rejected-reason{font-size:12.5px;color:var(--rose);background:var(--rose-light);border-radius:8px;padding:8px 10px;margin-top:8px}
</style>
</head>
<body>

<?php require __DIR__ . '/../inc/admin-nav.php'; ?>

<div class="admin-body">
  <h1 class="admin-title">Professionals</h1>
  <div class="admin-subtitle"><?php echo (int) $counts['pending']; ?> pending &middot; <?php echo (int) $counts['verified']; ?> verified &middot; <?php echo (int) $counts['rejected']; ?> rejected</div>

  <div class="tab-row">
    <a class="tab-link <?php echo 'pending' === $status_filter ? 'active' : ''; ?>" href="?status=pending">Pending (<?php echo (int) $counts['pending']; ?>)</a>
    <a class="tab-link <?php echo 'verified' === $status_filter ? 'active' : ''; ?>" href="?status=verified">Verified (<?php echo (int) $counts['verified']; ?>)</a>
    <a class="tab-link <?php echo 'rejected' === $status_filter ? 'active' : ''; ?>" href="?status=rejected">Rejected (<?php echo (int) $counts['rejected']; ?>)</a>
    <a class="tab-link <?php echo 'all' === $status_filter ? 'active' : ''; ?>" href="?status=all">All</a>
  </div>

  <div class="panel">
    <?php if ( empty( $applications ) ) : ?>
      <div class="empty-state">No applications here.</div>
    <?php else : foreach ( $applications as $app ) :
      $user = get_userdata( $app->user_id );
      $docs = kounselia_get_professional_documents( $app->id );
    ?>
      <div class="app-card" id="app-<?php echo (int) $app->id; ?>">
        <div class="app-head">
          <div>
            <div class="app-name"><?php echo esc_html( $user ? ( $user->display_name ?: $user->user_email ) : 'Unknown user' ); ?></div>
            <div class="app-title"><?php echo esc_html( $app->title ); ?><?php echo $app->specialty ? ' — ' . esc_html( $app->specialty ) : ''; ?></div>
          </div>
          <span class="badge <?php echo esc_attr( $app->status ); ?>" style="background:<?php
            echo 'pending' === $app->status ? 'var(--gold-light);color:#8a5a12' : ( 'verified' === $app->status ? 'var(--sage-light,#EAF2EC);color:var(--sage,#2E5C3E)' : 'var(--rose-light);color:var(--rose)' );
          ?>"><?php echo esc_html( ucfirst( $app->status ) ); ?></span>
        </div>

        <div class="app-meta">
          <div><strong>Email</strong><?php echo esc_html( $user ? $user->user_email : '—' ); ?></div>
          <div><strong>License #</strong><?php echo esc_html( $app->license_number ?: '—' ); ?></div>
          <div><strong>Experience</strong><?php echo $app->years_experience ? (int) $app->years_experience . ' years' : '—'; ?></div>
          <div><strong>Rate</strong><?php echo $app->rate_amount ? esc_html( $app->rate_currency . ' ' . number_format( (float) $app->rate_amount, 2 ) ) . ' / session' : '—'; ?></div>
          <div><strong>Submitted</strong><?php echo esc_html( kounselia_admin_time_label( $app->submitted_at ) ); ?></div>
        </div>

        <?php if ( $app->bio ) : ?>
          <div class="app-bio"><?php echo esc_html( $app->bio ); ?></div>
        <?php endif; ?>

        <div class="doc-links">
          <?php if ( empty( $docs ) ) : ?>
            <span style="font-size:12.5px;color:var(--text3)">No documents uploaded.</span>
          <?php else : foreach ( $docs as $doc ) : ?>
            <a href="<?php echo esc_url( kounselia_professional_document_url( $doc->id ) ); ?>" target="_blank" rel="noopener">
              <i class="ti ti-file-text"></i> <?php echo esc_html( ucfirst( $doc->doc_type ) . ' document' ); ?>
            </a>
          <?php endforeach; endif; ?>
        </div>

        <?php if ( 'rejected' === $app->status && $app->rejection_reason ) : ?>
          <div class="rejected-reason">Rejected: <?php echo esc_html( $app->rejection_reason ); ?></div>
        <?php endif; ?>

        <?php if ( 'pending' === $app->status ) : ?>
          <div class="app-actions">
            <button class="btn-approve" onclick="reviewApp(<?php echo (int) $app->id; ?>,'approve')">Approve</button>
            <textarea id="reason-<?php echo (int) $app->id; ?>" placeholder="Reason for rejection (sent to the applicant)"></textarea>
            <button class="btn-reject" onclick="reviewApp(<?php echo (int) $app->id; ?>,'reject')">Reject</button>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<script>
const KOUNSELIA_ADMIN_NONCE = <?php echo wp_json_encode( $nonce ); ?>;

function reviewApp(id, decision) {
  const card = document.getElementById('app-' + id);
  const buttons = card.querySelectorAll('button');
  buttons.forEach(b => b.disabled = true);

  const reasonEl = document.getElementById('reason-' + id);
  const reason = reasonEl ? reasonEl.value : '';

  if (decision === 'reject' && !reason.trim()) {
    if (!confirm('Reject without a reason? The applicant won\'t know why.')) {
      buttons.forEach(b => b.disabled = false);
      return;
    }
  }

  fetch('/portal/wp-admin/admin-ajax.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      action: 'kounselia_admin_review_professional',
      nonce: KOUNSELIA_ADMIN_NONCE,
      professional_id: id,
      decision: decision,
      reason: reason
    })
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      window.location.reload();
    } else {
      alert(res.data && res.data.message ? res.data.message : 'Something went wrong.');
      buttons.forEach(b => b.disabled = false);
    }
  })
  .catch(() => {
    alert('Something went wrong, please check your connection.');
    buttons.forEach(b => b.disabled = false);
  });
}
</script>
</body>
</html>

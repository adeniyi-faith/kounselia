<?php
/**
 * Kounselia Admin — bookings.
 *
 * Until this page existed, nothing in the admin panel showed a booking
 * ever happened: no calendar, no way to see a booking's private message
 * thread, no way to step into a dispute ("the professional never showed
 * up," "this conversation is inappropriate"). This is that — every
 * booking, filterable by status, with a link into its conversation
 * (booking-messages.php), an admin-override cancel that refunds the
 * client the same way a normal cancellation does (see
 * kounselia_admin_cancel_booking in bookings.php), and a "Book a
 * session" form for slotting one in by hand — any professional, any
 * client, any time, skipping the working-hours/2-hour-notice rules the
 * self-serve flow holds everyone else to (see
 * kounselia_ajax_admin_create_booking in bookings.php).
 *
 * 'payment_conflict' bookings need particular attention: that status
 * means a client's payment was captured for a slot that had, in the
 * seconds since, already been confirmed for someone else — Kounselia
 * has their money and owes them either a rescheduled session or a
 * refund, and nothing automatic resolves that. This page is where that
 * gets looked at and closed out.
 */
require_once __DIR__ . '/../inc/admin-auth.php';
require_once __DIR__ . '/../inc/admin-helpers.php';
$kounselia_admin_active = 'bookings';

global $wpdb;
$nonce = wp_create_nonce( 'kounselia_admin_nonce' );

$status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
$valid_statuses = array( 'all', 'confirmed', 'pending_payment', 'cancelled', 'payment_conflict' );
if ( ! in_array( $status_filter, $valid_statuses, true ) ) {
    $status_filter = 'all';
}

$page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
$per_page = 25;
$offset   = ( $page - 1 ) * $per_page;

$bookings     = kounselia_get_all_bookings_admin( $status_filter, $per_page, $offset );
$total        = kounselia_count_all_bookings_admin( $status_filter );
$total_pages  = max( 1, (int) ceil( $total / $per_page ) );

$conflict_count = kounselia_count_all_bookings_admin( 'payment_conflict' );
$professionals  = kounselia_get_verified_professionals();

$status_labels = array(
    'confirmed'        => 'Confirmed',
    'pending_payment'  => 'Awaiting payment',
    'cancelled'        => 'Cancelled',
    'payment_conflict' => 'Payment conflict',
);
$payment_labels = array( 'pending' => 'Pending', 'success' => 'Paid', 'failed' => 'Failed', 'refunded' => 'Refunded', 'free' => 'Free session' );
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kounselia Admin — Bookings</title>
<meta name="robots" content="noindex, nofollow">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<?php require __DIR__ . '/../inc/admin-styles.php'; ?>
<style>
.tab-row{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap}
.tab-link{padding:7px 14px;border-radius:var(--r-full);font-size:13px;font-weight:500;color:var(--text2);border:1px solid var(--border);text-decoration:none}
.tab-link.active{background:var(--accent);color:#fff;border-color:var(--accent)}
.status-pill{display:inline-block;border-radius:var(--r-full);padding:3px 10px;font-size:11px;font-weight:600}
.status-confirmed{background:var(--sage-light);color:var(--sage)}
.status-pending_payment{background:var(--gold-light);color:#8a5a12}
.status-cancelled{background:var(--surface2);color:var(--text3)}
.status-payment_conflict{background:#FBE4E4;color:#A31F1F}
.flag-pill{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;color:var(--rose)}
.cancel-btn{background:none;border:1px solid var(--rose);color:var(--rose);border-radius:var(--r-sm);padding:6px 12px;font-size:12px;cursor:pointer;font-family:inherit}
.cancel-btn:hover{background:var(--rose);color:#fff}
.cancel-btn:disabled{opacity:.5;cursor:not-allowed}
.book-toggle{display:inline-flex;align-items:center;gap:6px;background:var(--accent);color:#fff;border:none;border-radius:var(--r-sm);padding:9px 16px;font-size:13.5px;font-weight:600;cursor:pointer;font-family:inherit;margin-bottom:20px}
.book-panel{display:none;background:var(--surface2);border:1px solid var(--border);border-radius:var(--r-md);padding:18px 20px;margin-bottom:20px}
.book-panel.open{display:block}
.book-panel h2{font-size:15px;margin:0 0 4px}
.book-panel .hint{font-size:12.5px;color:var(--text3);margin:0 0 16px}
.book-row{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px}
.book-field{flex:1;min-width:180px;display:flex;flex-direction:column;gap:5px}
.book-field label{font-size:12px;font-weight:600;color:var(--text2)}
.book-field select,.book-field input,.book-field textarea{border:1px solid var(--border);border-radius:var(--r-sm);padding:8px 10px;font-size:13.5px;font-family:inherit;background:#fff}
.book-now-btn{background:none;border:1px solid var(--border);border-radius:var(--r-sm);padding:8px 10px;font-size:12.5px;cursor:pointer;font-family:inherit;white-space:nowrap;align-self:flex-end}
.book-submit{background:var(--accent);color:#fff;border:none;border-radius:var(--r-sm);padding:9px 18px;font-size:13.5px;font-weight:600;cursor:pointer;font-family:inherit}
.book-submit:disabled{opacity:.5;cursor:not-allowed}
.book-msg{font-size:13px;margin-top:10px}
.book-msg.error{color:var(--rose)}
.book-msg.success{color:var(--sage)}
</style>
</head>
<body>

<?php require __DIR__ . '/../inc/admin-nav.php'; ?>

<div class="admin-body">
  <h1 class="admin-title">Bookings</h1>
  <div class="admin-subtitle"><?php echo esc_html( number_format_i18n( $total ) ); ?> total</div>

  <?php if ( $conflict_count > 0 ) : ?>
    <div class="alert-banner" style="background:#FBE4E4;border:1px solid #E8A9A9;color:#7A1F1F;border-radius:var(--r-md);padding:14px 16px;font-size:13.5px;font-weight:600;margin-bottom:20px">
      ⚠ <?php echo (int) $conflict_count; ?> booking<?php echo 1 === $conflict_count ? '' : 's'; ?> with a payment conflict — a client was charged for a slot someone else ended up with. Refund or reschedule them directly in Paystack, then cancel the booking here to close it out.
    </div>
  <?php endif; ?>

  <button type="button" class="book-toggle" onclick="document.getElementById('book-panel').classList.toggle('open')">+ Book a session</button>

  <div id="book-panel" class="book-panel">
    <h2>Book a session</h2>
    <p class="hint">For arranging a session by hand — a phone booking, an emergency session, covering for a broken payment. Unlike the normal booking flow, this isn't limited to the professional's own working hours or the usual 2-hour notice period, and it's confirmed immediately with no payment step.</p>
    <div class="book-row">
      <div class="book-field">
        <label for="book-pro">Professional</label>
        <select id="book-pro">
          <option value="">Choose one…</option>
          <?php foreach ( $professionals as $p ) : ?>
            <option value="<?php echo (int) $p->id; ?>"><?php echo esc_html( $p->display_name ); ?><?php echo $p->title ? ' — ' . esc_html( $p->title ) : ''; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="book-field">
        <label for="book-client-email">Client's email</label>
        <input type="email" id="book-client-email" placeholder="member@example.com">
      </div>
    </div>
    <div class="book-row">
      <div class="book-field">
        <label for="book-time">Date &amp; time</label>
        <input type="datetime-local" id="book-time">
      </div>
      <button type="button" class="book-now-btn" onclick="adminFillBookingNow()">Fill in "now"</button>
    </div>
    <div class="book-row">
      <div class="book-field">
        <label for="book-note">Note (optional)</label>
        <textarea id="book-note" rows="2" placeholder="Anything the professional should know first"></textarea>
      </div>
    </div>
    <button type="button" class="book-submit" id="book-submit-btn" onclick="adminCreateBooking()">Book session</button>
    <div id="book-msg"></div>
  </div>

  <div class="tab-row">
    <a class="tab-link <?php echo 'all' === $status_filter ? 'active' : ''; ?>" href="?status=all">All</a>
    <a class="tab-link <?php echo 'confirmed' === $status_filter ? 'active' : ''; ?>" href="?status=confirmed">Confirmed</a>
    <a class="tab-link <?php echo 'pending_payment' === $status_filter ? 'active' : ''; ?>" href="?status=pending_payment">Awaiting payment</a>
    <a class="tab-link <?php echo 'cancelled' === $status_filter ? 'active' : ''; ?>" href="?status=cancelled">Cancelled</a>
    <a class="tab-link <?php echo 'payment_conflict' === $status_filter ? 'active' : ''; ?>" href="?status=payment_conflict">Payment conflicts<?php echo $conflict_count ? ' (' . (int) $conflict_count . ')' : ''; ?></a>
  </div>

  <div class="panel">
    <?php if ( empty( $bookings ) ) : ?>
      <div class="empty-state">No bookings here.</div>
    <?php else : ?>
      <table class="admin-table">
        <thead><tr><th>When</th><th>Client</th><th>Professional</th><th>Status</th><th>Payment</th><th>Messages</th><th></th></tr></thead>
        <tbody>
        <?php foreach ( $bookings as $b ) : ?>
          <tr id="booking-<?php echo (int) $b->id; ?>">
            <td data-label="When"><?php echo esc_html( date_i18n( 'M j, Y g:i A', strtotime( $b->scheduled_start ) ) ); ?></td>
            <td data-label="Client"><?php echo esc_html( $b->client_name ?: ( $b->client_email ?: 'Unknown' ) ); ?></td>
            <td data-label="Professional"><?php echo esc_html( $b->pro_name ?: 'Unknown' ); ?><?php echo $b->pro_title ? ' — ' . esc_html( $b->pro_title ) : ''; ?></td>
            <td data-label="Status"><span class="status-pill status-<?php echo esc_attr( $b->status ); ?>"><?php echo esc_html( $status_labels[ $b->status ] ?? ucfirst( $b->status ) ); ?></span></td>
            <td data-label="Payment"><?php echo $b->payment_status ? esc_html( $payment_labels[ $b->payment_status ] ?? ucfirst( $b->payment_status ) ) : '—'; ?></td>
            <td data-label="Messages">
              <?php if ( (int) $b->message_count > 0 ) : ?>
                <a href="/portal/admin/pages/booking-messages.php?booking_id=<?php echo (int) $b->id; ?>"><?php echo (int) $b->message_count; ?> message<?php echo 1 === (int) $b->message_count ? '' : 's'; ?></a>
                <?php if ( (int) $b->flagged_count > 0 ) : ?>
                  <div class="flag-pill">⚠ <?php echo (int) $b->flagged_count; ?> flagged</div>
                <?php endif; ?>
              <?php else : ?>
                <span style="color:var(--text3);font-size:12.5px">None</span>
              <?php endif; ?>
            </td>
            <td data-label="">
              <?php if ( 'confirmed' === $b->status ) : ?>
                <button class="cancel-btn" onclick="adminCancelBooking(<?php echo (int) $b->id; ?>, this)">Cancel</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <div class="pagination">
        <?php if ( $page > 1 ) : ?>
          <a href="?status=<?php echo esc_attr( $status_filter ); ?>&paged=<?php echo (int) ( $page - 1 ); ?>">← Prev</a>
        <?php endif; ?>
        <span class="current"><?php echo (int) $page; ?></span>
        <span>of <?php echo (int) $total_pages; ?></span>
        <?php if ( $page < $total_pages ) : ?>
          <a href="?status=<?php echo esc_attr( $status_filter ); ?>&paged=<?php echo (int) ( $page + 1 ); ?>">Next →</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
const KOUNSELIA_ADMIN_NONCE = <?php echo wp_json_encode( $nonce ); ?>;

function adminCancelBooking(id, btn) {
  const reason = prompt('Reason for cancelling this booking (the client and professional will both be notified, and the client refunded if they paid):', '');
  if (reason === null) return;

  btn.disabled = true;
  btn.textContent = 'Cancelling...';

  fetch('/portal/wp-admin/admin-ajax.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      action: 'kounselia_admin_cancel_booking',
      nonce: KOUNSELIA_ADMIN_NONCE,
      booking_id: id,
      reason: reason
    })
  })
  .then(r => r.json())
  .then(res => {
    if (res.success) {
      window.location.reload();
    } else {
      alert(res.data && res.data.message ? res.data.message : 'Something went wrong.');
      btn.disabled = false;
      btn.textContent = 'Cancel';
    }
  })
  .catch(() => {
    alert('Something went wrong, please check your connection.');
    btn.disabled = false;
    btn.textContent = 'Cancel';
  });
}

// Local time, formatted for a <input type="datetime-local"> value
// (YYYY-MM-DDTHH:MM) — Date#toISOString() would shift it to UTC instead.
function adminLocalDatetimeValue(date) {
  const pad = (n) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function adminFillBookingNow() {
  document.getElementById('book-time').value = adminLocalDatetimeValue(new Date());
}

function adminCreateBooking() {
  const msg = document.getElementById('book-msg');
  const btn = document.getElementById('book-submit-btn');
  const professionalId = document.getElementById('book-pro').value;
  const clientEmail = document.getElementById('book-client-email').value.trim();
  const time = document.getElementById('book-time').value;
  const note = document.getElementById('book-note').value.trim();

  msg.className = 'book-msg';
  msg.textContent = '';

  if (!professionalId || !clientEmail || !time) {
    msg.className = 'book-msg error';
    msg.textContent = 'Please choose a professional, a client, and a time.';
    return;
  }

  btn.disabled = true;
  btn.textContent = 'Booking…';

  fetch('/portal/wp-admin/admin-ajax.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      action: 'kounselia_admin_create_booking',
      nonce: KOUNSELIA_ADMIN_NONCE,
      professional_id: professionalId,
      client_email: clientEmail,
      scheduled_start: time.replace('T', ' ') + ':00',
      note: note
    })
  })
  .then(r => r.json())
  .then(res => {
    btn.disabled = false;
    btn.textContent = 'Book session';
    if (res.success) {
      msg.className = 'book-msg success';
      msg.textContent = res.data.message;
      setTimeout(() => window.location.reload(), 1200);
    } else {
      msg.className = 'book-msg error';
      msg.textContent = (res.data && res.data.message) || 'Something went wrong.';
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.textContent = 'Book session';
    msg.className = 'book-msg error';
    msg.textContent = 'Something went wrong, please check your connection.';
  });
}
</script>
</body>
</html>

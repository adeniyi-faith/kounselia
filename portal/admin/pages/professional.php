<?php
/**
 * Kounselia Admin — one professional, everything an admin can do.
 *
 *   /portal/admin/pages/professional.php?id=12
 *
 * Overview numbers, status and visibility (hide, suspend, reinstate),
 * their profile (editable, including rate and free sessions), articles
 * setting, documents and recent sessions. Logic: includes/professionals-admin.php.
 */
require_once __DIR__ . '/../inc/admin-auth.php';
require_once __DIR__ . '/../inc/admin-helpers.php';
$kounselia_admin_active = 'professionals';

if ( ! kounselia_admin_can( 'professionals' ) ) {
    wp_die( 'You do not have permission to manage professionals.' );
}

global $wpdb;
$pro = isset( $_GET['id'] ) ? kounselia_get_professional_by_id( absint( $_GET['id'] ) ) : null;
if ( ! $pro ) {
    wp_safe_redirect( '/portal/admin/pages/professionals.php?status=verified' );
    exit;
}
$user      = get_userdata( $pro->user_id );
$stats     = kounselia_admin_professional_stats( $pro );
$docs      = kounselia_get_professional_documents( $pro->id );
$avatar    = function_exists( 'kounselia_get_avatar_url' ) ? kounselia_get_avatar_url( $pro->user_id, 'thumbnail' ) : false;
$self_hide = '0' === (string) get_user_meta( $pro->user_id, 'kounselia_public_profile', true );
$is_public = 'verified' === $pro->status && ! $pro->admin_hidden && ! $self_hide;
$upcoming  = $wpdb->get_results( $wpdb->prepare(
    "SELECT b.*, u.display_name AS client_name FROM {$wpdb->prefix}kounselia_bookings b LEFT JOIN {$wpdb->users} u ON u.ID = b.client_user_id
     WHERE b.professional_id = %d ORDER BY b.scheduled_start DESC LIMIT 12",
    $pro->id
) );
$history = $wpdb->get_results( $wpdb->prepare(
    "SELECT l.*, u.display_name AS admin_name FROM {$wpdb->prefix}kounselia_admin_audit_log l LEFT JOIN {$wpdb->users} u ON u.ID = l.admin_id
     WHERE l.target_type = 'professional' AND l.target_id = %d ORDER BY l.id DESC LIMIT 10",
    $pro->id
) );
$status_pill = array( 'verified' => array( 'green', 'Verified' ), 'pending' => array( 'gold', 'Pending review' ), 'rejected' => array( 'rose', 'Rejected' ), 'suspended' => array( 'rose', 'Suspended' ) );
$pill        = $status_pill[ $pro->status ] ?? array( 'grey', ucfirst( $pro->status ) );
$free_opts   = kounselia_free_session_options();
$history_labels = array(
    'approve_professional' => 'Approved', 'reject_professional' => 'Rejected', 'edited_professional' => 'Profile edited',
    'hid_professional' => 'Hidden from members', 'unhid_professional' => 'Made visible', 'suspended_professional' => 'Suspended',
    'reinstated_professional' => 'Reinstated', 'allowed_own_video' => 'Allowed own video link', 'stopped_own_video' => 'Stopped own video link',
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kounselia Admin — <?php echo esc_html( $pro->display_name ); ?></title>
<meta name="robots" content="noindex, nofollow">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<?php require __DIR__ . '/../inc/admin-styles.php'; ?>
<?php require __DIR__ . '/../inc/admin-cms.php'; ?>
<style>
.pro-top{display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:18px}
.pro-av{width:64px;height:64px;border-radius:18px;background:var(--accent-light);color:var(--accent);display:flex;align-items:center;justify-content:center;font-family:'Cormorant Garamond',serif;font-size:30px;overflow:hidden;flex-shrink:0}
.pro-av img{width:100%;height:100%;object-fit:cover}
.pro-top h1{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:32px;line-height:1.1;color:var(--text)}
.pro-top .sub{font-size:13.5px;color:var(--text2);margin-top:4px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:20px}
.stat{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-md);padding:14px 16px}
.stat b{display:block;font-family:'Cormorant Garamond',serif;font-size:26px;font-weight:500;color:var(--text);line-height:1.1}
.stat span{font-size:12px;color:var(--text3)}
.two{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(0,1fr);gap:20px;align-items:start}
@media (max-width:980px){.two{grid-template-columns:1fr}}
.panel h2{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:22px;color:var(--accent);margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;gap:10px}
.state-box{border-radius:var(--r-md);padding:14px 16px;margin-bottom:14px;font-size:13.5px;line-height:1.55}
.state-box.ok{background:var(--sage-light);color:var(--sage)}
.state-box.warn{background:var(--gold-light);color:#6B4A1F}
.state-box.bad{background:var(--rose-light);color:var(--rose)}
.state-box b{display:block;margin-bottom:2px}
.act{border:1px solid var(--border);border-radius:var(--r-md);padding:14px 16px;margin-bottom:12px}
.act h3{font-size:14px;font-weight:600;color:var(--text);margin-bottom:4px}
.act p{font-size:12.5px;color:var(--text2);line-height:1.5;margin-bottom:10px}
.mini-table{width:100%;border-collapse:collapse;font-size:13px}
.mini-table td{padding:9px 6px;border-bottom:1px solid var(--border);vertical-align:top}
.mini-table tr:last-child td{border-bottom:none}
.muted{color:var(--text3);font-size:12.5px}
</style>
</head>
<body>
<?php require __DIR__ . '/../inc/admin-nav.php'; ?>

<div class="admin-body">
  <a class="back-link" href="/portal/admin/pages/professionals.php?status=<?php echo esc_attr( $pro->status ); ?>"><i class="ti ti-arrow-left"></i> Professionals</a>

  <div class="pro-top">
    <div class="pro-av"><?php echo $avatar ? '<img src="' . esc_url( $avatar ) . '" alt="">' : esc_html( mb_strtoupper( mb_substr( $pro->display_name, 0, 1 ) ) ); ?></div>
    <div>
      <h1><?php echo esc_html( $pro->display_name ); ?></h1>
      <div class="sub">
        <span class="pill <?php echo esc_attr( $pill[0] ); ?>"><?php echo esc_html( $pill[1] ); ?></span>
        <?php if ( $pro->admin_hidden ) : ?><span class="pill grey"><i class="ti ti-eye-off"></i> Hidden by admin</span><?php endif; ?>
        <?php if ( $self_hide ) : ?><span class="pill grey">Hid their own public profile</span><?php endif; ?>
        <span><?php echo esc_html( $pro->title ); ?></span>
        <span class="muted"><?php echo esc_html( $user ? $user->user_email : '' ); ?></span>
      </div>
    </div>
    <div class="btn-row" style="margin-left:auto">
      <?php if ( $is_public ) : ?><a class="btn btn-light btn-sm" href="<?php echo esc_url( kounselia_professional_url( $pro ) ); ?>" target="_blank"><i class="ti ti-external-link"></i> Public profile</a><?php endif; ?>
      <a class="btn btn-light btn-sm" href="/portal/admin/pages/member-profile.php?id=<?php echo (int) $pro->user_id; ?>"><i class="ti ti-user"></i> Member record</a>
    </div>
  </div>

  <div class="stat-grid">
    <div class="stat"><b><?php echo (int) $stats['upcoming']; ?></b><span>Upcoming sessions</span></div>
    <div class="stat"><b><?php echo (int) $stats['held']; ?></b><span>Sessions held</span></div>
    <div class="stat"><b><?php echo (int) $stats['clients']; ?></b><span>Clients</span></div>
    <div class="stat"><b>₦<?php echo esc_html( number_format( $stats['earned'] ) ); ?></b><span>Earned (their share)</span></div>
    <div class="stat"><b><?php echo $stats['rating']['count'] ? esc_html( number_format( $stats['rating']['average'], 1 ) ) . '★' : '—'; ?></b><span><?php echo (int) $stats['rating']['count']; ?> reviews</span></div>
    <div class="stat"><b><?php echo (int) $stats['free_given']; ?></b><span>Free sessions given</span></div>
    <div class="stat"><b><?php echo (int) $stats['followers']; ?></b><span>Followers</span></div>
    <div class="stat"><b><?php echo (int) $stats['articles']; ?></b><span>Live articles</span></div>
  </div>

  <div class="two">
    <div>
      <div class="panel">
        <h2>Profile <span class="muted" style="font-family:Outfit,sans-serif;font-size:12px">What members see</span></h2>
        <div class="field-row">
          <div class="field"><label for="p-name">Name</label><input type="text" id="p-name" value="<?php echo esc_attr( $pro->display_name ); ?>"></div>
          <div class="field"><label for="p-title">Professional title</label><input type="text" id="p-title" value="<?php echo esc_attr( $pro->title ); ?>" placeholder="e.g. Licensed Clinical Psychologist"></div>
        </div>
        <div class="field-row">
          <div class="field"><label for="p-spec">Specialties</label><input type="text" id="p-spec" value="<?php echo esc_attr( (string) $pro->specialty ); ?>" placeholder="e.g. Anxiety, Grief"></div>
          <div class="field"><label for="p-years">Years of experience</label><input type="number" id="p-years" min="0" max="60" value="<?php echo esc_attr( (string) $pro->years_experience ); ?>"></div>
        </div>
        <div class="field"><label for="p-bio">Bio</label><textarea id="p-bio" rows="5"><?php echo esc_textarea( (string) $pro->bio ); ?></textarea></div>
        <div class="field-row">
          <div class="field"><label for="p-rate">Rate per session (₦)</label><input type="number" id="p-rate" min="0" step="100" value="<?php echo esc_attr( (string) $pro->rate_amount ); ?>"><div class="hint">Normally set by the professional. Tell them if you change it.</div></div>
          <div class="field"><label for="p-free">Free sessions</label>
            <select id="p-free"><?php foreach ( $free_opts as $v => $label ) : ?><option value="<?php echo (int) $v; ?>" <?php selected( (int) $pro->free_sessions_per_client, (int) $v ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
          </div>
        </div>
        <?php if ( $pro->license_number ) : ?><p class="muted" style="margin-bottom:12px">Licence / registration: <b><?php echo esc_html( $pro->license_number ); ?></b> (only staff see this)</p><?php endif; ?>
        <button class="btn btn-primary" id="p-save"><i class="ti ti-device-floppy"></i> Save profile</button>
      </div>

      <div class="panel">
        <h2>Recent sessions</h2>
        <?php if ( ! $upcoming ) : ?><div class="empty-state">No sessions yet.</div><?php else : ?>
        <table class="mini-table">
          <?php foreach ( $upcoming as $b ) : ?>
            <tr>
              <td><?php echo esc_html( mysql2date( 'D, M j, Y g:ia', $b->scheduled_start ) ); ?></td>
              <td><?php echo esc_html( $b->client_name ?: 'Deleted account' ); ?></td>
              <td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $b->status ) ) ); ?><?php echo $b->is_free ? ' <span class="pill blue">Free</span>' : ''; ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
        <p style="margin-top:10px"><a href="/portal/admin/pages/bookings.php">All bookings →</a></p>
        <?php endif; ?>
      </div>
    </div>

    <div>
      <div class="panel">
        <h2>Status &amp; visibility</h2>
        <?php if ( 'suspended' === $pro->status ) : ?>
          <div class="state-box bad"><b>Suspended <?php echo $pro->suspended_at ? esc_html( kounselia_admin_time_label( get_gmt_from_date( $pro->suspended_at ) ) ) : ''; ?></b><?php echo esc_html( (string) $pro->suspended_reason ); ?><br>Hidden everywhere, no new bookings, can't write articles. They can still sign in and see their dashboard.</div>
          <div class="act">
            <h3>Reinstate</h3>
            <p>Makes them verified again: listed and bookable as before. They get an email.</p>
            <button class="btn btn-primary btn-sm" id="a-reinstate"><i class="ti ti-player-play"></i> Reinstate</button>
          </div>
        <?php elseif ( 'verified' === $pro->status ) : ?>
          <div class="state-box <?php echo $is_public ? 'ok' : 'warn'; ?>">
            <b><?php echo $is_public ? 'Live and bookable' : ( $pro->admin_hidden ? 'Hidden by an admin' : 'Hidden by their own choice' ); ?></b>
            <?php echo $is_public ? 'Shown in the directory, the members\' dashboard and the app.' : ( $pro->admin_hidden ? 'Not shown anywhere members look. Sessions already booked still happen.' : 'Not on the public website, but signed-in members can still book them.' ); ?>
          </div>
          <div class="act">
            <h3><?php echo $pro->admin_hidden ? 'Show them again' : 'Hide from members'; ?></h3>
            <p><?php echo $pro->admin_hidden ? 'Puts them back in the directory, the dashboard and the app.' : 'Takes them out of the public directory, the members\' dashboard and the app, and turns off their profile page. Nothing is cancelled and they are not told. Good for a pause or while you look into something.'; ?></p>
            <button class="btn btn-light btn-sm" id="a-hide" data-hidden="<?php echo $pro->admin_hidden ? '0' : '1'; ?>"><i class="ti ti-<?php echo $pro->admin_hidden ? 'eye' : 'eye-off'; ?>"></i> <?php echo $pro->admin_hidden ? 'Show again' : 'Hide'; ?></button>
          </div>
          <div class="act" style="border-color:rgba(139,58,82,.3)">
            <h3>Suspend</h3>
            <p>Stops them being found or booked, and stops them writing. They get an email with your reason.</p>
            <div class="field"><textarea id="s-reason" rows="2" placeholder="Reason (the professional will see this)"></textarea></div>
            <label class="check"><input type="checkbox" id="s-cancel"> <span>Cancel and refund their <?php echo (int) $stats['upcoming']; ?> upcoming <?php echo 1 === $stats['upcoming'] ? 'session' : 'sessions'; ?><small>Each client is told and refunded.</small></span></label>
            <label class="check"><input type="checkbox" id="s-articles"> <span>Take down their <?php echo (int) $stats['articles']; ?> live <?php echo 1 === $stats['articles'] ? 'article' : 'articles'; ?></span></label>
            <button class="btn btn-danger btn-sm" id="a-suspend"><i class="ti ti-ban"></i> Suspend</button>
          </div>
        <?php else : ?>
          <div class="state-box warn"><b><?php echo esc_html( $pill[1] ); ?></b>Decide on their application on the <a href="/portal/admin/pages/professionals.php?status=<?php echo esc_attr( $pro->status ); ?>">Professionals</a> page.</div>
        <?php endif; ?>
      </div>

      <?php if ( function_exists( 'kounselia_professional_video_provider' ) ) :
          $kounselia_vprov = kounselia_professional_video_provider( $pro ); ?>
      <div class="panel">
        <h2>Video calls</h2>
        <p style="font-size:13.5px;color:var(--text2);margin-bottom:10px;line-height:1.55">
          <?php if ( ! $pro->video_link_allowed ) : ?>
            Sessions use Kounselia's private video room.
          <?php elseif ( $kounselia_vprov ) : ?>
            Allowed. Their sessions are on <b><?php echo esc_html( $kounselia_vprov ); ?></b>: <span class="muted" style="word-break:break-all"><?php echo esc_html( $pro->video_link ); ?></span>
          <?php else : ?>
            Allowed, but they still use Kounselia's video room.
          <?php endif; ?>
        </p>
        <label class="check"><input type="checkbox" id="v-allow" <?php checked( ! empty( $pro->video_link_allowed ) ); ?>> <span><b>Allow their own video link</b><small>Zoom, Google Meet, Microsoft Teams or Whereby. Booking, payment and messages stay on Kounselia, and clients still join through Kounselia. Turning this off moves every session back to Kounselia's room at once.</small></span></label>
      </div>
      <?php endif; ?>

      <div class="panel">
        <h2>Articles</h2>
        <p style="font-size:13.5px;color:var(--text2);margin-bottom:10px">Publishing: <b><?php echo esc_html( kounselia_article_publishing_options()[ $pro->publishing ? $pro->publishing : 'default' ] ?? 'Follow the site setting' ); ?></b></p>
        <a class="btn btn-light btn-sm" href="/portal/admin/pages/articles.php?tab=writers">Change in Articles</a>
      </div>

      <div class="panel">
        <h2>Documents</h2>
        <?php if ( ! $docs ) : ?><div class="muted">No documents uploaded.</div><?php endif; ?>
        <?php foreach ( $docs as $doc ) : ?>
          <div style="margin-bottom:6px"><a href="<?php echo esc_url( kounselia_professional_document_url( $doc->id ) ); ?>" target="_blank" rel="noopener"><i class="ti ti-file-text"></i> <?php echo esc_html( $doc->original_filename ); ?></a> <span class="muted"><?php echo esc_html( $doc->doc_type ); ?></span></div>
        <?php endforeach; ?>
      </div>

      <?php if ( $history ) : ?>
        <div class="panel">
          <h2>History</h2>
          <table class="mini-table">
            <?php foreach ( $history as $h ) : ?>
              <tr><td><?php echo esc_html( $history_labels[ $h->action ] ?? ucfirst( str_replace( '_', ' ', $h->action ) ) ); ?></td><td class="muted"><?php echo esc_html( ( $h->admin_name ?: 'Someone' ) . ' · ' . kounselia_admin_time_label( get_gmt_from_date( $h->created_at ) ) ); ?></td></tr>
            <?php endforeach; ?>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
(function(){
  var id = <?php echo (int) $pro->id; ?>;
  var $ = function(i){ return document.getElementById(i); };
  function done(d){ KAdmin.toast(d.message); setTimeout(function(){ location.reload(); }, 900); }
  function fail(e){ KAdmin.toast(e.message, true); }
  $('p-save').addEventListener('click', function(){
    KAdmin.post('kounselia_admin_professional_save', {
      professional_id: id, name: $('p-name').value, title: $('p-title').value, specialty: $('p-spec').value,
      years_experience: $('p-years').value, bio: $('p-bio').value, rate_amount: $('p-rate').value, free_sessions_per_client: $('p-free').value
    }).then(function(d){ KAdmin.toast(d.message); }).catch(fail);
  });
  if ($('v-allow')) $('v-allow').addEventListener('change', function(){
    var box = this;
    if (!box.checked && !confirm('Stop their own video link? All their sessions will use Kounselia\'s video room again, and they will be told.')) { box.checked = true; return; }
    KAdmin.post('kounselia_admin_professional_video', { professional_id: id, allow: box.checked ? 1 : 0 }).then(done).catch(function(e){ box.checked = !box.checked; fail(e); });
  });
  if ($('a-hide')) $('a-hide').addEventListener('click', function(){
    var hide = this.dataset.hidden === '1';
    if (hide && !confirm('Hide this professional from members? Nothing is cancelled and they are not told.')) return;
    KAdmin.post('kounselia_admin_professional_visibility', { professional_id: id, hidden: hide ? 1 : 0 }).then(done).catch(fail);
  });
  if ($('a-suspend')) $('a-suspend').addEventListener('click', function(){
    if (!$('s-reason').value.trim()) { KAdmin.toast('Please write a reason. The professional will see it.', true); $('s-reason').focus(); return; }
    var extra = [];
    if ($('s-cancel').checked) extra.push('cancel and refund their upcoming sessions');
    if ($('s-articles').checked) extra.push('take down their articles');
    if (!confirm('Suspend this professional' + (extra.length ? ' and ' + extra.join(' and ') : '') + '?')) return;
    KAdmin.post('kounselia_admin_professional_suspend', { professional_id: id, reason: $('s-reason').value, cancel_upcoming: $('s-cancel').checked ? 1 : 0, take_down: $('s-articles').checked ? 1 : 0 }).then(done).catch(fail);
  });
  if ($('a-reinstate')) $('a-reinstate').addEventListener('click', function(){
    if (!confirm('Reinstate this professional? They will be listed and bookable again.')) return;
    KAdmin.post('kounselia_admin_professional_reinstate', { professional_id: id }).then(done).catch(fail);
  });
})();
</script>
</body>
</html>

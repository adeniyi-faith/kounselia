<?php
/**
 * Kounselia Admin — Newsletter: campaigns list + composer.
 *
 * A campaign is one email to one audience (a saved segment, or custom
 * rules). Sending is queued and delivered in batches — see
 * includes/newsletter.php — and this screen shows progress, opens and
 * clicks. Automatic "new blog post" emails appear here too.
 */
require_once __DIR__ . '/../inc/admin-auth.php';
require_once __DIR__ . '/../inc/admin-helpers.php';
$kounselia_admin_active = 'broadcasts';
$kounselia_nl_tab       = 'campaigns';

if ( ! kounselia_admin_can( 'broadcasts' ) ) {
    wp_die( 'You do not have permission to use the newsletter.' );
}

global $wpdb;
$compose  = isset( $_GET['compose'] ) ? sanitize_key( $_GET['compose'] ) : '';
$campaign = ( $compose && 'new' !== $compose ) ? kounselia_newsletter_get_campaign( (int) $compose ) : null;
if ( $compose && 'new' !== $compose && ( ! $campaign || ! in_array( $campaign->status, array( 'draft', 'scheduled' ), true ) ) ) {
    wp_safe_redirect( '/portal/admin/pages/newsletter.php' );
    exit;
}
$segments = kounselia_newsletter_list_segments();
$seg_js   = array_map( function ( $s ) { return array( 'id' => (int) $s->id, 'name' => $s->name ); }, $segments );
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kounselia Admin — Newsletter</title>
<meta name="robots" content="noindex, nofollow">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<?php require __DIR__ . '/../inc/admin-styles.php'; ?>
<?php require __DIR__ . '/../inc/admin-cms.php'; ?>
<style>
.progress{height:6px;background:var(--surface2);border-radius:6px;overflow:hidden;margin-top:6px;min-width:90px}
.progress i{display:block;height:100%;background:linear-gradient(90deg,var(--accent),var(--gold));border-radius:6px;transition:width .4s}
.rate{font-weight:600;color:var(--text);font-variant-numeric:tabular-nums}
.rate small{display:block;font-weight:400;color:var(--text3);font-size:11px}
.preview-frame{width:100%;height:70vh;border:none;border-radius:var(--r-md);background:#F8F6F2}
.merge-tags{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}
.merge-tags button{font-size:11.5px;padding:3px 9px;border-radius:20px;border:1px solid var(--border);background:var(--surface);cursor:pointer;color:var(--plum);font-family:ui-monospace,monospace}
.merge-tags button:hover{border-color:var(--plum)}
.subject-count{float:right;font-weight:400;color:var(--text3)}
</style>
</head>
<body>
<?php require __DIR__ . '/../inc/admin-nav.php'; ?>
<div class="admin-body">
<?php require __DIR__ . '/../inc/newsletter-ui.php'; ?>

<?php if ( $compose ) :
    $rules = $campaign ? json_decode( (string) $campaign->rules, true ) : array( 'audience' => 'all' );
    ?>
  <div class="editor-layout">
    <div class="editor-main">
      <div class="field">
        <label for="c-subject">Subject line <span class="subject-count" id="c-subject-count"></span></label>
        <input type="text" id="c-subject" maxlength="255" value="<?php echo esc_attr( $campaign ? $campaign->subject : '' ); ?>" placeholder="What will make them open it?">
      </div>
      <div class="field">
        <label for="c-preheader">Preview text</label>
        <input type="text" id="c-preheader" maxlength="255" value="<?php echo esc_attr( $campaign ? $campaign->preheader : '' ); ?>" placeholder="The grey line inboxes show after the subject">
      </div>
      <div class="field">
        <label for="c-headline">Headline inside the email</label>
        <input type="text" id="c-headline" value="<?php echo esc_attr( $campaign ? $campaign->headline : 'Hi {first_name},' ); ?>" placeholder="Hi {first_name},">
        <div class="merge-tags"><span class="hint" style="margin:0">Personalise:</span><button type="button" data-tag="{first_name}">{first_name}</button><button type="button" data-tag="{email}">{email}</button></div>
      </div>
      <textarea id="c-content"><?php echo esc_textarea( (string) ( $campaign ? $campaign->content : '' ) ); ?></textarea>
      <div class="field-row" style="margin-top:16px">
        <div class="field"><label for="c-btn-text">Button text (optional)</label><input type="text" id="c-btn-text" value="<?php echo esc_attr( $campaign ? $campaign->btn_text : '' ); ?>" placeholder="e.g. Talk to someone now"></div>
        <div class="field"><label for="c-btn-url">Button link</label><input type="url" id="c-btn-url" value="<?php echo esc_attr( $campaign ? $campaign->btn_url : '' ); ?>" placeholder="https://kounselia.com/…"></div>
      </div>
    </div>

    <aside class="editor-side">
      <div class="side-box">
        <h3>Audience</h3>
        <div class="seg">
          <label><input type="radio" name="c-list" value="newsletter" <?php checked( ! $campaign || 'newsletter' === $campaign->list_key ); ?>> Newsletter list</label>
          <label><input type="radio" name="c-list" value="blog" <?php checked( $campaign && 'blog' === $campaign->list_key ); ?>> Blog list</label>
        </div>
        <div id="c-audience"></div>
      </div>

      <div class="side-box">
        <h3>Send</h3>
        <div class="field">
          <label for="c-test-to">Send a test to</label>
          <div style="display:flex;gap:6px"><input type="email" id="c-test-to" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>"><button class="btn btn-light btn-sm" id="c-test">Send test</button></div>
        </div>
        <button class="btn btn-light" id="c-preview" style="width:100%;margin-bottom:12px"><i class="ti ti-eye"></i> Preview email</button>
        <div class="seg">
          <label><input type="radio" name="c-when" value="now" <?php checked( ! $campaign || 'scheduled' !== $campaign->status ); ?>> Send now</label>
          <label><input type="radio" name="c-when" value="later" <?php checked( $campaign && 'scheduled' === $campaign->status ); ?>> Schedule</label>
        </div>
        <div class="field" id="c-when-wrap" style="display:none">
          <input type="datetime-local" id="c-when-at" value="<?php echo esc_attr( $campaign && $campaign->scheduled_at ? mysql2date( 'Y-m-d\TH:i', $campaign->scheduled_at ) : date( 'Y-m-d\TH:i', current_time( 'timestamp' ) + DAY_IN_SECONDS ) ); ?>">
          <div class="hint">Site time. The audience is worked out at the moment it sends.</div>
        </div>
        <button class="btn btn-gold" id="c-send" style="width:100%"><i class="ti ti-send"></i> <span>Send now</span></button>
        <button class="btn btn-light" id="c-save" style="width:100%;margin-top:8px"><i class="ti ti-device-floppy"></i> <?php echo $campaign && 'scheduled' === $campaign->status ? 'Save changes' : 'Save draft'; ?></button>
        <div class="save-state" id="c-state"><?php echo $campaign && 'scheduled' === $campaign->status ? 'Scheduled for ' . esc_html( mysql2date( 'M j, Y g:ia', $campaign->scheduled_at ) ) . ' — saving changes keeps it scheduled.' : ''; ?></div>
      </div>

      <div class="side-box">
        <h3>Internal name</h3>
        <div class="field"><input type="text" id="c-name" value="<?php echo esc_attr( $campaign ? $campaign->name : '' ); ?>" placeholder="Only staff see this (defaults to the subject)"></div>
      </div>
    </aside>
  </div>

  <div class="modal-bg" id="preview-modal"><div class="modal" style="max-width:680px">
    <h2>Email preview</h2>
    <p class="hint" style="margin:-8px 0 12px">Shown with your own name filled in. Links are not tracked in previews.</p>
    <iframe class="preview-frame" id="preview-frame" title="Email preview"></iframe>
    <div class="btn-row"><button class="btn btn-light" onclick="document.getElementById('preview-modal').classList.remove('open')">Close</button></div>
  </div></div>

  <script>
  (function(){
    var id = <?php echo (int) ( $campaign ? $campaign->id : 0 ); ?>;
    var wasScheduled = <?php echo $campaign && 'scheduled' === $campaign->status ? 'true' : 'false'; ?>;
    var $ = function(s){ return document.getElementById(s); };
    var ed, dirty = false, lastField = $('c-headline');
    function markDirty(){ dirty = true; }
    KAdmin.editor('#c-content', { blocks: false, height: 520, onChange: markDirty }).then(function(e){ ed = e; });
    var aud = KAudience.mount($('c-audience'), {
      rules: <?php echo wp_json_encode( kounselia_newsletter_normalize_rules( $rules ) ); ?>,
      segmentId: <?php echo (int) ( $campaign ? $campaign->segment_id : ( isset( $_GET['segment'] ) ? $_GET['segment'] : 0 ) ); ?>,
      listKey: <?php echo wp_json_encode( $campaign ? $campaign->list_key : 'newsletter' ); ?>,
      segments: <?php echo wp_json_encode( $seg_js ); ?>, allowSegments: true
    });
    document.querySelectorAll('[name=c-list]').forEach(function(r){ r.addEventListener('change', function(){ aud.setList(r.value); markDirty(); }); });
    ['c-subject','c-preheader','c-headline','c-btn-text','c-btn-url','c-name'].forEach(function(f){ $(f).addEventListener('input', markDirty); $(f).addEventListener('focus', function(){ lastField = $(f); }); });
    window.addEventListener('beforeunload', function(e){ if(dirty){ e.preventDefault(); e.returnValue = ''; } });

    function subjCount(){ var n = $('c-subject').value.length; $('c-subject-count').textContent = n + ' characters' + (n > 60 ? ' — may be cut off on phones' : ''); }
    $('c-subject').addEventListener('input', subjCount); subjCount();

    document.querySelectorAll('.merge-tags button').forEach(function(b){
      b.addEventListener('click', function(){
        var el = lastField, tag = b.dataset.tag;
        if(el && el.setRangeText){ el.setRangeText(tag, el.selectionStart, el.selectionEnd, 'end'); el.focus(); markDirty(); }
      });
    });

    function whenMode(){ return document.querySelector('[name=c-when]:checked').value; }
    function syncWhen(){
      $('c-when-wrap').style.display = whenMode() === 'later' ? '' : 'none';
      $('c-send').querySelector('span').textContent = whenMode() === 'later' ? 'Schedule' : 'Send now';
    }
    document.querySelectorAll('[name=c-when]').forEach(function(r){ r.addEventListener('change', syncWhen); });
    syncWhen();

    function payload(){
      return {
        id: id, name: $('c-name').value, subject: $('c-subject').value, preheader: $('c-preheader').value,
        headline: $('c-headline').value, content: ed ? ed.getContent() : $('c-content').value,
        btn_text: $('c-btn-text').value, btn_url: $('c-btn-url').value,
        list_key: document.querySelector('[name=c-list]:checked').value,
        segment_id: aud.segmentId(), rules: JSON.stringify(aud.rules())
      };
    }
    function busy(btn, on){ btn.disabled = on; }

    $('c-save').addEventListener('click', function(){
      var b = this; busy(b, true);
      // Saving never sends: a scheduled campaign keeps its time (use Schedule to change it).
      KAdmin.post('kounselia_admin_nl_save_campaign', payload()).then(function(d){
        dirty = false; id = d.id; history.replaceState(null, '', '?compose=' + d.id);
        $('c-state').textContent = wasScheduled ? 'Saved — still scheduled.' : 'Saved just now';
        KAdmin.toast(wasScheduled ? 'Changes saved. It is still scheduled.' : 'Draft saved.');
      }).catch(function(e){ KAdmin.toast(e.message, true); }).finally(function(){ busy(b, false); });
    });

    $('c-test').addEventListener('click', function(){
      var b = this; busy(b, true);
      var data = payload(); data.to = $('c-test-to').value;
      KAdmin.post('kounselia_admin_nl_send_test', data).then(function(d){ KAdmin.toast(d.message); })
        .catch(function(e){ KAdmin.toast(e.message, true); }).finally(function(){ busy(b, false); });
    });

    $('c-preview').addEventListener('click', function(){
      KAdmin.post('kounselia_admin_nl_preview', payload()).then(function(d){
        $('preview-frame').srcdoc = d.html; $('preview-modal').classList.add('open');
      }).catch(function(e){ KAdmin.toast(e.message, true); });
    });

    $('c-send').addEventListener('click', function(){
      var later = whenMode() === 'later';
      var n = document.querySelector('#c-audience [data-f=n]').textContent;
      if(!$('c-subject').value.trim()){ KAdmin.toast('Please write a subject line.', true); return; }
      if(!later && !confirm('Send this email now to ' + n + '? This cannot be undone.')) return;
      var b = this; busy(b, true);
      var data = payload();
      if(later) data.schedule_at = $('c-when-at').value.replace('T', ' ');
      KAdmin.post('kounselia_admin_nl_send', data).then(function(d){
        dirty = false; KAdmin.toast(d.message);
        setTimeout(function(){ location.href = '/portal/admin/pages/newsletter.php'; }, 900);
      }).catch(function(e){ KAdmin.toast(e.message, true); busy(b, false); });
    });
  })();
  </script>

<?php else :
    $campaigns = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}kounselia_campaigns ORDER BY COALESCE(started_at, scheduled_at, created_at) DESC, id DESC LIMIT 100" );
    $stats     = $wpdb->get_row( $wpdb->prepare(
        "SELECT COALESCE(SUM(sent_count),0) AS sent, COALESCE(SUM(open_count),0) AS opens, COALESCE(SUM(click_count),0) AS clicks FROM {$wpdb->prefix}kounselia_campaigns WHERE started_at >= %s",
        date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS )
    ) );
    $new_30 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_subscribers WHERE created_at >= %s AND status = 'subscribed'", date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS ) ) );
    $pct    = function ( $part, $whole ) { return $whole ? round( 100 * $part / $whole ) . '%' : '—'; };
    $status_pills = array(
        'draft' => 'grey', 'scheduled' => 'gold', 'sending' => 'blue', 'sent' => 'green', 'cancelled' => 'rose',
    );
    ?>
  <div class="grid">
    <div class="card"><div class="label">Subscribed contacts</div><div class="num"><?php echo number_format_i18n( (int) $kounselia_nl_counts->active ); ?></div><div class="split"><span><b>+<?php echo number_format_i18n( $new_30 ); ?></b> in 30 days</span></div></div>
    <div class="card"><div class="label">Emails sent · 30 days</div><div class="num"><?php echo number_format_i18n( (int) $stats->sent ); ?></div></div>
    <div class="card"><div class="label">Open rate · 30 days</div><div class="num"><?php echo esc_html( $pct( $stats->opens, $stats->sent ) ); ?></div><div class="split"><span>Click rate <b><?php echo esc_html( $pct( $stats->clicks, $stats->sent ) ); ?></b></span></div></div>
  </div>

  <div class="panel">
    <?php if ( ! $campaigns ) : ?>
      <div class="empty-state">No campaigns yet. <a href="?compose=new">Write your first newsletter</a> — or publish a blog post with “Email this post to subscribers” ticked.</div>
    <?php else : ?>
      <table class="admin-table" id="camp-table">
        <thead><tr><th>Campaign</th><th>Status</th><th>Audience</th><th>Delivered</th><th>Opened</th><th>Clicked</th><th></th></tr></thead>
        <tbody>
        <?php foreach ( $campaigns as $c ) :
            $done  = (int) $c->sent_count + (int) $c->failed_count;
            $share = $c->recipients_total ? min( 100, round( 100 * $done / $c->recipients_total ) ) : 0;
            $aud   = $c->segment_id && ( $seg = kounselia_newsletter_get_segment( $c->segment_id ) ) ? 'Segment: ' . $seg->name : kounselia_newsletter_describe_rules( $c->rules );
            $when  = 'scheduled' === $c->status ? 'Sends ' . mysql2date( 'M j, g:ia', $c->scheduled_at ) : ( $c->started_at ? mysql2date( 'M j, Y g:ia', $c->started_at ) : 'Edited ' . mysql2date( 'M j', $c->updated_at ) );
            ?>
          <tr data-id="<?php echo (int) $c->id; ?>" data-status="<?php echo esc_attr( $c->status ); ?>">
            <td data-label="Campaign">
              <div class="row-title"><?php echo esc_html( $c->name ); ?></div>
              <div class="row-sub"><?php echo 'blog' === $c->type ? '<span class="pill plum" style="margin-right:4px">Blog post</span>' : ''; ?><?php echo esc_html( $when ); ?></div>
            </td>
            <td data-label="Status">
              <span class="pill <?php echo esc_attr( $status_pills[ $c->status ] ?? 'grey' ); ?>" data-f="status"><?php echo esc_html( ucfirst( $c->status ) ); ?></span>
              <?php if ( 'sending' === $c->status ) : ?><div class="progress"><i data-f="bar" style="width:<?php echo (int) $share; ?>%"></i></div><?php endif; ?>
            </td>
            <td data-label="Audience"><span class="row-sub" style="word-break:normal"><?php echo esc_html( $aud ); ?> · <?php echo 'blog' === $c->list_key ? 'blog list' : 'newsletter list'; ?></span></td>
            <td data-label="Delivered"><span class="rate" data-f="sent"><?php echo number_format_i18n( (int) $c->sent_count ); ?><small>of <?php echo number_format_i18n( (int) $c->recipients_total ); ?><?php echo $c->failed_count ? ' · ' . (int) $c->failed_count . ' failed' : ''; ?></small></span></td>
            <td data-label="Opened"><span class="rate" data-f="open"><?php echo esc_html( $pct( $c->open_count, $c->sent_count ) ); ?><small><?php echo number_format_i18n( (int) $c->open_count ); ?> people</small></span></td>
            <td data-label="Clicked"><span class="rate" data-f="click"><?php echo esc_html( $pct( $c->click_count, $c->sent_count ) ); ?><small><?php echo number_format_i18n( (int) $c->click_count ); ?> people</small></span></td>
            <td data-label=""><div class="row-actions">
              <?php if ( in_array( $c->status, array( 'draft', 'scheduled' ), true ) && 'blog' !== $c->type ) : ?><a class="btn btn-light btn-sm" href="?compose=<?php echo (int) $c->id; ?>"><i class="ti ti-pencil"></i> Edit</a><?php endif; ?>
              <?php if ( in_array( $c->status, array( 'scheduled', 'sending' ), true ) ) : ?><button class="btn btn-danger btn-sm" data-do="cancel">Stop</button><?php endif; ?>
              <?php if ( 'newsletter' === $c->type ) : ?><button class="btn btn-light btn-sm" data-do="duplicate" title="Duplicate"><i class="ti ti-copy"></i></button><?php endif; ?>
              <?php if ( ! in_array( $c->status, array( 'scheduled', 'sending' ), true ) ) : ?><button class="btn btn-light btn-sm" data-do="delete" title="Delete"><i class="ti ti-trash"></i></button><?php endif; ?>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
  <p class="hint">Opens are counted when an email app loads images, so the real number is usually a little higher. Emails are delivered in batches of <?php echo (int) kounselia_newsletter_settings()['batch_size']; ?> about every minute; keeping this page open speeds it up.</p>

  <script>
  (function(){
    var table = document.getElementById('camp-table');
    if(!table) return;
    table.addEventListener('click', function(e){
      var b = e.target.closest('[data-do]'); if(!b) return;
      var row = b.closest('tr'), what = b.dataset.do;
      var ask = { cancel: 'Stop this campaign? Anyone not yet emailed will not receive it.', delete: 'Delete this campaign and its statistics?' }[what];
      if(ask && !confirm(ask)) return;
      KAdmin.post('kounselia_admin_nl_campaign_action', { id: row.dataset.id, do: what }).then(function(d){
        KAdmin.toast(d.message);
        if(what === 'duplicate'){ location.href = '?compose=' + d.id; } else { setTimeout(function(){ location.reload(); }, 600); }
      }).catch(function(err){ KAdmin.toast(err.message, true); });
    });

    function poll(){
      var rows = table.querySelectorAll('tr[data-status=sending], tr[data-status=scheduled]');
      if(!rows.length) return;
      var ids = Array.prototype.map.call(rows, function(r){ return r.dataset.id; }).join(',');
      KAdmin.post('kounselia_admin_nl_progress', { ids: ids }).then(function(d){
        var reload = false;
        d.campaigns.forEach(function(c){
          var row = table.querySelector('tr[data-id="' + c.id + '"]'); if(!row) return;
          if(c.status !== row.dataset.status){ reload = true; return; }
          var bar = row.querySelector('[data-f=bar]');
          var done = (+c.sent_count) + (+c.failed_count);
          if(bar && +c.recipients_total) bar.style.width = Math.min(100, Math.round(100 * done / c.recipients_total)) + '%';
          row.querySelector('[data-f=sent]').firstChild.textContent = (+c.sent_count).toLocaleString();
        });
        if(reload){ location.reload(); } else { setTimeout(poll, 4000); }
      }).catch(function(){ setTimeout(poll, 10000); });
    }
    setTimeout(poll, 1500);
  })();
  </script>
<?php endif; ?>

</div>
</body>
</html>

<?php
/**
 * Kounselia Admin — Email delivery.
 *
 * Choose who delivers the site's emails — the server's default mailer
 * or Brevo — separately for account emails and newsletters, set the
 * daily allowance (Brevo free = 300/day), test delivery, and see the
 * recent delivery log. Logic: includes/mail-delivery.php.
 */
require_once __DIR__ . '/../inc/admin-auth.php';
require_once __DIR__ . '/../inc/admin-helpers.php';
$kounselia_admin_active = 'settings';

if ( ! current_user_can( 'administrator' ) ) {
    wp_die( 'Only super admins can manage email delivery.' );
}

global $wpdb;
$s          = kounselia_mail_settings();
$has_key    = '' !== trim( $s['brevo_api_key'] );
$brevo_today = kounselia_mail_sent_today( 'brevo' );
$wp_today    = kounselia_mail_sent_today( 'wordpress' );
$limit       = (int) $s['brevo_daily_limit'];
$log         = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}kounselia_mail_log ORDER BY id DESC LIMIT 60" );
$failed_24h  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_mail_log WHERE status = 'failed' AND created_at >= %s", date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - DAY_IN_SECONDS ) ) );
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kounselia Admin — Email delivery</title>
<meta name="robots" content="noindex, nofollow">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<?php require __DIR__ . '/../inc/admin-styles.php'; ?>
<?php require __DIR__ . '/../inc/admin-cms.php'; ?>
<style>
.prov{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:6px}
@media (max-width:560px){ .prov{grid-template-columns:1fr} }
.prov label{border:1.5px solid var(--border);border-radius:var(--r-md);padding:14px;cursor:pointer;display:flex;gap:10px;align-items:flex-start;background:var(--surface);transition:border-color .15s}
.prov label:has(input:checked){border-color:var(--accent);background:var(--accent-light)}
.prov input{margin-top:3px;accent-color:var(--accent)}
.prov b{display:block;font-size:14px;color:var(--text)}
.prov small{display:block;font-size:12px;color:var(--text3);margin-top:2px;line-height:1.4}
.meter{height:8px;background:var(--surface2);border-radius:8px;overflow:hidden;margin:8px 0 4px}
.meter i{display:block;height:100%;background:linear-gradient(90deg,var(--sage),var(--gold));border-radius:8px}
.key-state{font-size:12.5px;margin-top:6px}
.steps{font-size:13px;color:var(--text2);line-height:1.6;padding-left:18px}
.steps li{margin-bottom:4px}
</style>
</head>
<body>
<?php require __DIR__ . '/../inc/admin-nav.php'; ?>
<div class="admin-body">
  <a class="back-link" href="/portal/admin/pages/settings.php"><i class="ti ti-arrow-left"></i> Settings</a>
  <div class="cms-head">
    <div>
      <h1 class="admin-title">Email delivery</h1>
      <div class="admin-subtitle">Choose who delivers Kounselia's emails. You can switch at any time — nothing else needs to change.</div>
    </div>
  </div>

  <div class="grid">
    <div class="card"><div class="label">Sent via Brevo today</div><div class="num"><?php echo number_format_i18n( $brevo_today ); ?><?php echo $limit ? '<span style="font-size:15px;color:var(--text3);font-weight:400"> / ' . number_format_i18n( $limit ) . '</span>' : ''; ?></div>
      <?php if ( $limit ) : ?><div class="meter"><i style="width:<?php echo (int) min( 100, round( 100 * $brevo_today / max( 1, $limit ) ) ); ?>%"></i></div><div class="hint">Resets at midnight (site time).</div><?php endif; ?></div>
    <div class="card"><div class="label">Sent via default mail today</div><div class="num"><?php echo number_format_i18n( $wp_today ); ?></div></div>
    <div class="card"><div class="label">Failed · last 24 hours</div><div class="num" style="<?php echo $failed_24h ? 'color:var(--rose)' : ''; ?>"><?php echo number_format_i18n( $failed_24h ); ?></div></div>
  </div>

  <div class="field-row" style="align-items:start">
    <div>
      <div class="panel">
        <div class="panel-title">Who sends what</div>
        <div class="field-label">Account emails — booking confirmations, reminders, receipts, password resets</div>
        <div class="prov">
          <label><input type="radio" name="account_provider" value="wordpress" <?php checked( 'wordpress', $s['account_provider'] ); ?>><span><b>Default mail</b><small>Your server's mailer, or any SMTP plugin you've installed.</small></span></label>
          <label><input type="radio" name="account_provider" value="brevo" <?php checked( 'brevo', $s['account_provider'] ); ?>><span><b>Brevo</b><small>Better inbox delivery and a sending history in Brevo.</small></span></label>
        </div>
        <div class="field-label" style="margin-top:16px">Newsletters — campaigns, new blog post emails, welcome emails</div>
        <div class="prov">
          <label><input type="radio" name="newsletter_provider" value="wordpress" <?php checked( 'wordpress', $s['newsletter_provider'] ); ?>><span><b>Default mail</b><small>Fine for small lists. Many hosts cap emails per hour.</small></span></label>
          <label><input type="radio" name="newsletter_provider" value="brevo" <?php checked( 'brevo', $s['newsletter_provider'] ); ?>><span><b>Brevo</b><small>Recommended for newsletters.</small></span></label>
        </div>
      </div>

      <div class="panel">
        <div class="panel-title">Daily allowance</div>
        <div class="field-row">
          <div class="field"><label for="m-limit">Brevo emails per day</label><input type="number" id="m-limit" min="0" value="<?php echo (int) $s['brevo_daily_limit']; ?>"><div class="hint">Brevo's free plan allows <b>300</b>. When you upgrade, raise this — or set <b>0</b> for no limit.</div></div>
          <div class="field"><label for="m-reserve">Keep back for account emails</label><input type="number" id="m-reserve" min="0" value="<?php echo (int) $s['account_reserve']; ?>"><div class="hint">Newsletters stop this many short of the limit, so password resets and booking emails always have room.</div></div>
        </div>
        <label class="check"><input type="checkbox" id="m-fallback" <?php checked( ! empty( $s['fallback_to_default'] ) ); ?>> <span><b>Fall back to default mail for account emails</b><small>If the daily limit is reached or Brevo can't be reached, important member emails still go out through your server instead of waiting.</small></span></label>
        <p class="hint" style="font-size:12.5px">When the newsletter share of the allowance is used up, campaigns pause and carry on automatically the next day. Nobody is emailed twice.</p>
      </div>
    </div>

    <div>
      <div class="panel">
        <div class="panel-title">Brevo connection <?php echo kounselia_mail_brevo_ready( $s ) ? '<span class="pill green">Set up</span>' : '<span class="pill grey">Not set up</span>'; ?></div>
        <div class="field">
          <label for="m-key">API key</label>
          <input type="text" id="m-key" autocomplete="off" spellcheck="false" placeholder="<?php echo $has_key ? 'Saved ••••' . esc_attr( substr( $s['brevo_api_key'], -4 ) ) . ' — paste a new key to replace it' : 'xkeysib-…'; ?>" style="font-family:ui-monospace,monospace;font-size:13px">
          <?php if ( $has_key ) : ?><label class="check key-state"><input type="checkbox" id="m-clear"> Remove the saved key</label><?php endif; ?>
        </div>
        <div class="field-row">
          <div class="field"><label for="m-from">Sender email</label><input type="email" id="m-from" value="<?php echo esc_attr( $s['sender_email'] ); ?>" placeholder="hello@kounselia.com"></div>
          <div class="field"><label for="m-name">Sender name</label><input type="text" id="m-name" value="<?php echo esc_attr( $s['sender_name'] ); ?>"></div>
        </div>
        <div class="field"><label for="m-reply">Replies go to (optional)</label><input type="email" id="m-reply" value="<?php echo esc_attr( $s['reply_to'] ); ?>" placeholder="support@kounselia.com"></div>
        <button class="btn btn-light btn-sm" id="m-check"><i class="ti ti-plug-connected"></i> Check connection</button>
        <details style="margin-top:14px"><summary style="cursor:pointer;font-size:13px;color:var(--accent)">How to get a Brevo API key</summary>
          <ol class="steps" style="margin-top:8px">
            <li>Create a free account at brevo.com.</li>
            <li>Under <b>Senders, Domains &amp; Dedicated IPs</b>, add and verify your sender email — ideally authenticate kounselia.com (Brevo shows the DNS records).</li>
            <li>Go to <b>SMTP &amp; API → API Keys</b>, create a key, and paste it here.</li>
          </ol>
        </details>
      </div>

      <div class="panel">
        <div class="panel-title">Send a test</div>
        <div class="field"><input type="email" id="m-test-to" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>"></div>
        <div class="btn-row">
          <button class="btn btn-light btn-sm" data-test="wordpress">Test default mail</button>
          <button class="btn btn-light btn-sm" data-test="brevo">Test Brevo</button>
        </div>
        <div class="hint">Save your settings before testing Brevo.</div>
      </div>
    </div>
  </div>
  <button class="btn btn-primary" id="m-save"><i class="ti ti-device-floppy"></i> Save email delivery settings</button>

  <div class="panel" style="margin-top:24px">
    <div class="panel-title">Recent deliveries <span class="hint" style="margin:0;font-weight:400">last 60 · kept for 30 days</span></div>
    <?php if ( ! $log ) : ?>
      <div class="empty-state">Nothing sent yet.</div>
    <?php else : ?>
      <table class="admin-table">
        <thead><tr><th>When</th><th>To</th><th>Subject</th><th>Type</th><th>Sent by</th><th>Result</th></tr></thead>
        <tbody>
        <?php foreach ( $log as $row ) : ?>
          <tr>
            <td data-label="When"><span class="row-sub"><?php echo esc_html( mysql2date( 'M j, g:ia', $row->created_at ) ); ?></span></td>
            <td data-label="To"><?php echo esc_html( $row->recipient ); ?></td>
            <td data-label="Subject"><span class="row-sub" style="word-break:normal"><?php echo esc_html( $row->subject ); ?></span></td>
            <td data-label="Type"><span class="pill <?php echo 'newsletter' === $row->channel ? 'plum' : 'blue'; ?>"><?php echo 'newsletter' === $row->channel ? 'Newsletter' : 'Account'; ?></span></td>
            <td data-label="Sent by"><?php echo 'brevo' === $row->provider ? 'Brevo' : 'Default mail'; ?></td>
            <td data-label="Result"><?php
              if ( 'sent' === $row->status ) {
                  echo '<span class="pill green">Sent</span>';
              } else {
                  echo '<span class="pill ' . ( 'skipped' === $row->status ? 'gold' : 'rose' ) . '">' . esc_html( ucfirst( $row->status ) ) . '</span>';
                  echo $row->error ? '<div class="row-sub" style="word-break:normal">' . esc_html( $row->error ) . '</div>' : '';
              }
            ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<script>
(function(){
  var $ = function(id){ return document.getElementById(id); };
  var val = function(name){ return document.querySelector('[name=' + name + ']:checked').value; };
  $('m-save').addEventListener('click', function(){
    KAdmin.post('kounselia_admin_mail_save', {
      account_provider: val('account_provider'), newsletter_provider: val('newsletter_provider'),
      brevo_api_key: $('m-key').value, clear_key: $('m-clear') && $('m-clear').checked ? 1 : 0,
      sender_email: $('m-from').value, sender_name: $('m-name').value, reply_to: $('m-reply').value,
      brevo_daily_limit: $('m-limit').value, account_reserve: $('m-reserve').value,
      fallback_to_default: $('m-fallback').checked ? 1 : 0
    }).then(function(d){ KAdmin.toast(d.message); setTimeout(function(){ location.reload(); }, 900); })
      .catch(function(e){ KAdmin.toast(e.message, true); });
  });
  $('m-check').addEventListener('click', function(){
    var b = this; b.disabled = true;
    KAdmin.post('kounselia_admin_mail_check', { brevo_api_key: $('m-key').value })
      .then(function(d){ KAdmin.toast(d.message); }).catch(function(e){ KAdmin.toast(e.message, true); })
      .finally(function(){ b.disabled = false; });
  });
  document.querySelectorAll('[data-test]').forEach(function(b){
    b.addEventListener('click', function(){
      b.disabled = true;
      KAdmin.post('kounselia_admin_mail_test', { provider: b.dataset.test, to: $('m-test-to').value })
        .then(function(d){ KAdmin.toast(d.message); setTimeout(function(){ location.reload(); }, 1500); })
        .catch(function(e){ KAdmin.toast(e.message, true); }).finally(function(){ b.disabled = false; });
    });
  });
})();
</script>
</body>
</html>

<?php
/**
 * Kounselia Admin — Newsletter settings: sign-up behaviour, welcome
 * email, tracking and sending speed.
 */
require_once __DIR__ . '/../inc/admin-auth.php';
require_once __DIR__ . '/../inc/admin-helpers.php';
$kounselia_admin_active = 'broadcasts';
$kounselia_nl_tab       = 'settings';

if ( ! kounselia_admin_can( 'broadcasts' ) ) {
    wp_die( 'You do not have permission to use the newsletter.' );
}
$s = kounselia_newsletter_settings();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kounselia Admin — Newsletter settings</title>
<meta name="robots" content="noindex, nofollow">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<?php require __DIR__ . '/../inc/admin-styles.php'; ?>
<?php require __DIR__ . '/../inc/admin-cms.php'; ?>
</head>
<body>
<?php require __DIR__ . '/../inc/admin-nav.php'; ?>
<div class="admin-body">
<?php require __DIR__ . '/../inc/newsletter-ui.php'; ?>

  <div class="field-row" style="align-items:start">
    <div class="panel">
      <div class="panel-title">Sign-ups</div>
      <label class="check"><input type="checkbox" id="s-double" <?php checked( $s['double_optin'] ); ?>> <span><b>Ask website sign-ups to confirm by email</b><small>“Double opt-in”: they're only added once they click the link we email them. Recommended for GDPR, and it keeps fake addresses off your list.</small></span></label>
      <label class="check"><input type="checkbox" id="s-auto" <?php checked( $s['auto_subscribe_members'] ); ?>> <span><b>Add new members to the mailing list</b><small>When someone creates an account they're added as a contact. They can switch emails off any time in their dashboard settings or from any email.</small></span></label>
      <div class="field"><label for="s-address">Postal address in the email footer (optional)</label><input type="text" id="s-address" value="<?php echo esc_attr( $s['footer_address'] ); ?>" placeholder="e.g. Kounselia, 12 Example Street, Lagos"><div class="hint">Some countries' email laws expect a physical address in marketing emails.</div></div>
    </div>

    <div class="panel">
      <div class="panel-title">Welcome email</div>
      <label class="check"><input type="checkbox" id="s-welcome" <?php checked( $s['welcome_enabled'] ); ?>> <span>Send a welcome email when someone subscribes on the website</span></label>
      <div class="field"><label for="s-wsubj">Subject</label><input type="text" id="s-wsubj" value="<?php echo esc_attr( $s['welcome_subject'] ); ?>"></div>
      <div class="field"><label for="s-wbody">Message</label><textarea id="s-wbody" rows="6"><?php echo esc_textarea( (string) ( $s['welcome_body'] ) ); ?></textarea><div class="hint">Use <code>{first_name}</code> for their name. Blank lines start a new paragraph.</div></div>
    </div>

    <div class="panel">
      <div class="panel-title">Tracking & delivery</div>
      <label class="check"><input type="checkbox" id="s-opens" <?php checked( $s['track_opens'] ); ?>> <span><b>Track opens</b><small>Adds an invisible image to each email.</small></span></label>
      <label class="check"><input type="checkbox" id="s-clicks" <?php checked( $s['track_clicks'] ); ?>> <span><b>Track link clicks</b><small>Links pass briefly through kounselia.com so we can count them.</small></span></label>
      <div class="field"><label for="s-batch">Emails per batch</label><input type="number" id="s-batch" min="5" max="500" value="<?php echo (int) $s['batch_size']; ?>"><div class="hint">About one batch goes out every minute. Many shared hosts limit emails per hour — check yours before raising this.</div></div>
      <?php $kounselia_mail = function_exists( 'kounselia_mail_settings' ) ? kounselia_mail_settings() : null; ?>
      <div class="hint" style="font-size:12.5px;background:var(--accent-light);padding:10px 12px;border-radius:var(--r-sm);color:var(--accent)">
        Newsletters are currently sent by <b><?php echo $kounselia_mail && 'brevo' === $kounselia_mail['newsletter_provider'] ? 'Brevo' : 'your default mail'; ?></b>.
        <?php if ( current_user_can( 'administrator' ) ) : ?><a href="/portal/admin/pages/email-delivery.php">Change email delivery</a><?php else : ?>A super admin can change this in Settings → Email delivery.<?php endif; ?>
      </div>
    </div>
  </div>
  <button class="btn btn-primary" id="s-save"><i class="ti ti-device-floppy"></i> Save settings</button>

<script>
document.getElementById('s-save').addEventListener('click', function(){
  var v = function(id){ return document.getElementById(id); };
  KAdmin.post('kounselia_admin_nl_save_settings', {
    double_optin: v('s-double').checked ? 1 : 0, auto_subscribe_members: v('s-auto').checked ? 1 : 0,
    footer_address: v('s-address').value, welcome_enabled: v('s-welcome').checked ? 1 : 0,
    welcome_subject: v('s-wsubj').value, welcome_body: v('s-wbody').value,
    track_opens: v('s-opens').checked ? 1 : 0, track_clicks: v('s-clicks').checked ? 1 : 0, batch_size: v('s-batch').value
  }).then(function(d){ KAdmin.toast(d.message); }).catch(function(e){ KAdmin.toast(e.message, true); });
});
</script>
</div>
</body>
</html>

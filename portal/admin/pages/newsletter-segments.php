<?php
/**
 * Kounselia Admin — Newsletter segments.
 *
 * A segment is a saved, named audience ("Pro members who haven't
 * chatted in 30 days"). Its rules are re-checked every time it's used,
 * so it always reflects who matches right now.
 */
require_once __DIR__ . '/../inc/admin-auth.php';
require_once __DIR__ . '/../inc/admin-helpers.php';
$kounselia_admin_active = 'broadcasts';
$kounselia_nl_tab       = 'segments';

if ( ! kounselia_admin_can( 'broadcasts' ) ) {
    wp_die( 'You do not have permission to use the newsletter.' );
}

$segments = kounselia_newsletter_list_segments();
$edit_id  = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
$segment  = $edit_id ? kounselia_newsletter_get_segment( $edit_id ) : null;
$editing  = $segment || isset( $_GET['new'] );
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kounselia Admin — Segments</title>
<meta name="robots" content="noindex, nofollow">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<?php require __DIR__ . '/../inc/admin-styles.php'; ?>
<?php require __DIR__ . '/../inc/admin-cms.php'; ?>
<style>
.seg-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
.seg-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:18px;box-shadow:var(--sh-sm);display:flex;flex-direction:column;gap:8px}
.seg-card h3{font-size:15px;font-weight:600;color:var(--text)}
.seg-card .desc{font-size:12.5px;color:var(--text2);line-height:1.5}
.seg-card .n{font-size:24px;font-weight:600;color:var(--accent);font-variant-numeric:tabular-nums}
.seg-card .n small{font-size:12px;font-weight:400;color:var(--text3)}
.ideas{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
</style>
</head>
<body>
<?php require __DIR__ . '/../inc/admin-nav.php'; ?>
<div class="admin-body">
<?php require __DIR__ . '/../inc/newsletter-ui.php'; ?>

<?php if ( $editing ) : ?>
  <a class="back-link" href="/portal/admin/pages/newsletter-segments.php"><i class="ti ti-arrow-left"></i> All segments</a>
  <div class="editor-layout" style="grid-template-columns:minmax(0,1fr) 360px">
    <div class="editor-main">
      <div class="field"><label for="s-name">Segment name</label><input type="text" id="s-name" value="<?php echo esc_attr( $segment ? $segment->name : '' ); ?>" placeholder="e.g. Quiet members (30+ days)"></div>
      <div class="field"><label for="s-desc">Notes for the team (optional)</label><input type="text" id="s-desc" value="<?php echo esc_attr( $segment ? $segment->description : '' ); ?>" placeholder="What is this audience for?"></div>
      <div class="field-label">Count people on the</div>
      <div class="seg" style="max-width:320px">
        <label><input type="radio" name="s-list" value="newsletter" checked> Newsletter list</label>
        <label><input type="radio" name="s-list" value="blog"> Blog list</label>
      </div>
      <div id="s-aud"></div>
    </div>
    <aside class="editor-side">
      <div class="side-box">
        <h3>How segments work</h3>
        <p class="hint" style="font-size:13px;margin:0 0 14px">Every filter narrows the audience further. People are matched again each time the segment is used, so it's always up to date. Unsubscribed people, and banned or deleted accounts, are never included.</p>
        <button class="btn btn-primary" id="s-save" style="width:100%"><i class="ti ti-device-floppy"></i> Save segment</button>
        <?php if ( $segment ) : ?><button class="btn btn-danger" id="s-del" style="width:100%;margin-top:8px"><i class="ti ti-trash"></i> Delete</button><?php endif; ?>
      </div>
    </aside>
  </div>
  <script>
  (function(){
    var id = <?php echo (int) ( $segment ? $segment->id : 0 ); ?>;
    var aud = KAudience.mount(document.getElementById('s-aud'), { rules: <?php echo wp_json_encode( kounselia_newsletter_normalize_rules( $segment ? $segment->rules : array() ) ); ?> });
    document.querySelectorAll('[name=s-list]').forEach(function(r){ r.addEventListener('change', function(){ aud.setList(r.value); }); });
    document.getElementById('s-save').addEventListener('click', function(){
      KAdmin.post('kounselia_admin_nl_save_segment', { id: id, name: document.getElementById('s-name').value, description: document.getElementById('s-desc').value, rules: JSON.stringify(aud.rules()) })
        .then(function(d){ KAdmin.toast(d.message); id = d.id; history.replaceState(null, '', '?edit=' + d.id); })
        .catch(function(e){ KAdmin.toast(e.message, true); });
    });
    var del = document.getElementById('s-del');
    if(del) del.addEventListener('click', function(){
      if(!confirm('Delete this segment?')) return;
      KAdmin.post('kounselia_admin_nl_delete_segment', { id: id }).then(function(){ location.href = '?'; }).catch(function(e){ KAdmin.toast(e.message, true); });
    });
  })();
  </script>

<?php else : ?>
  <div class="btn-row" style="margin-bottom:16px"><a class="btn btn-primary btn-sm" href="?new=1"><i class="ti ti-plus"></i> New segment</a></div>
  <?php if ( ! $segments ) : ?>
    <div class="panel">
      <div class="empty-state" style="padding-bottom:12px">No segments yet. Segments let you send the right email to the right people. Some ideas:</div>
      <div class="ideas" style="justify-content:center;padding-bottom:18px">
        <span class="pill blue">Pro members</span><span class="pill blue">Members who haven't chatted in 30 days</span><span class="pill blue">New in the last 7 days</span><span class="pill blue">Website subscribers without an account</span><span class="pill blue">Verified professionals</span>
      </div>
    </div>
  <?php else : ?>
    <div class="seg-grid">
      <?php foreach ( $segments as $s ) :
          $n = kounselia_newsletter_audience_count( $s->rules, 'newsletter' ); ?>
        <div class="seg-card">
          <h3><?php echo esc_html( $s->name ); ?></h3>
          <div class="n"><?php echo number_format_i18n( $n ); ?> <small>on the newsletter list</small></div>
          <div class="desc"><?php echo esc_html( kounselia_newsletter_describe_rules( $s->rules ) ); ?></div>
          <?php if ( $s->description ) : ?><div class="desc" style="color:var(--text3)"><?php echo esc_html( $s->description ); ?></div><?php endif; ?>
          <div class="btn-row" style="margin-top:auto;padding-top:8px"><a class="btn btn-light btn-sm" href="?edit=<?php echo (int) $s->id; ?>"><i class="ti ti-pencil"></i> Edit</a><a class="btn btn-light btn-sm" href="/portal/admin/pages/newsletter.php?compose=new&segment=<?php echo (int) $s->id; ?>"><i class="ti ti-send"></i> Email them</a></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>

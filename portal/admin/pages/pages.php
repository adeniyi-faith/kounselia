<?php
/**
 * Kounselia Admin — Pages & Footer.
 *
 * Create and edit the site's content pages (shown at /page/<slug>)
 * with the rich editor, and arrange the footer: its link columns,
 * social profiles and newsletter box. Data helpers and AJAX handlers
 * live in mu-plugins/kounselia/includes/content.php.
 */
require_once __DIR__ . '/../inc/admin-auth.php';
require_once __DIR__ . '/../inc/admin-helpers.php';
$kounselia_admin_active = 'pages';

if ( ! kounselia_admin_can( 'pages' ) ) {
    wp_die( 'You do not have permission to manage pages.' );
}

$view    = isset( $_GET['tab'] ) && 'footer' === $_GET['tab'] ? 'footer' : 'pages';
$edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
$is_new  = isset( $_GET['new'] );
$page    = $edit_id ? kounselia_get_page( $edit_id ) : null;
if ( $edit_id && ! $page ) {
    wp_safe_redirect( '/portal/admin/pages/pages.php' );
    exit;
}
$editing = $page || $is_new;
$pages   = kounselia_list_pages();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kounselia Admin — Pages</title>
<meta name="robots" content="noindex, nofollow">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<?php require __DIR__ . '/../inc/admin-styles.php'; ?>
<?php require __DIR__ . '/../inc/admin-cms.php'; ?>
<style>
.foot-col{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:16px;box-shadow:var(--sh-sm)}
.foot-cols{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;margin-bottom:18px}
.foot-col-head{display:flex;gap:8px;margin-bottom:12px}
.foot-col-head input{flex:1;font-weight:600}
.foot-link{display:grid;grid-template-columns:1fr auto;gap:6px;padding:10px;background:var(--bg);border-radius:var(--r-md);margin-bottom:8px}
.foot-link .l-fields{display:flex;flex-direction:column;gap:6px}
.foot-link input,.foot-link select,.foot-col-head input{padding:8px 10px;border:1px solid var(--border);border-radius:var(--r-sm);font-family:inherit;font-size:13px;background:var(--surface);width:100%}
.foot-link .l-tools{display:flex;flex-direction:column;gap:4px}
.icon-btn{width:28px;height:28px;border-radius:8px;border:1px solid var(--border);background:var(--surface);cursor:pointer;color:var(--text2);display:inline-flex;align-items:center;justify-content:center;font-size:15px}
.icon-btn:hover{color:var(--accent);border-color:var(--accent)}
.icon-btn.del:hover{color:var(--rose);border-color:var(--rose)}
</style>
</head>
<body>
<?php require __DIR__ . '/../inc/admin-nav.php'; ?>

<div class="admin-body">

<?php if ( $editing ) : ?>
  <a class="back-link" href="/portal/admin/pages/pages.php"><i class="ti ti-arrow-left"></i> All pages</a>
  <div class="editor-layout">
    <div class="editor-main">
      <input class="title-input" id="p-title" placeholder="Page title" value="<?php echo esc_attr( $page ? $page->title : '' ); ?>">
      <input class="subtitle-input" id="p-subtitle" placeholder="A short introduction shown under the title (optional)" value="<?php echo esc_attr( $page ? $page->subtitle : '' ); ?>">
      <textarea id="p-content"><?php echo esc_textarea( (string) ( $page ? $page->content : '' ) ); ?></textarea>
      <p class="hint" style="margin-top:10px">Tip: use <b>Insert block</b> to add live content like the counselor cards, plan prices or a newsletter sign-up box. <b>Styles</b> turns a paragraph into a lead intro or callout, or a link into a button.</p>
    </div>

    <aside class="editor-side">
      <div class="side-box">
        <h3>Publish</h3>
        <div class="seg">
          <label><input type="radio" name="p-status" value="draft" <?php checked( ! $page || 'draft' === $page->status ); ?>> Draft</label>
          <label><input type="radio" name="p-status" value="published" <?php checked( $page && 'published' === $page->status ); ?>> Published</label>
        </div>
        <div class="btn-row">
          <button class="btn btn-primary" id="p-save" style="flex:1"><i class="ti ti-device-floppy"></i> Save</button>
          <a class="btn btn-light" id="p-view" href="<?php echo $page ? esc_url( kounselia_page_url( $page->slug ) ) : '#'; ?>" target="_blank" <?php echo $page ? '' : 'style="display:none"'; ?>><i class="ti ti-external-link"></i> View</a>
        </div>
        <div class="save-state" id="p-state"><?php echo $page ? 'Last saved ' . esc_html( kounselia_admin_time_label( get_gmt_from_date( $page->updated_at ) ) ) : 'Not saved yet'; ?></div>
      </div>

      <div class="side-box">
        <h3>Page address</h3>
        <div class="field">
          <label for="p-slug">URL</label>
          <input type="text" id="p-slug" value="<?php echo esc_attr( $page ? $page->slug : '' ); ?>" placeholder="made from the title">
          <div class="hint" id="p-url-hint"><?php echo esc_html( kounselia_site_url( '/page/' ) ); ?><b><?php echo esc_html( $page ? $page->slug : '…' ); ?></b><br>Changing this keeps the old address working (it redirects).</div>
        </div>
      </div>

      <div class="side-box">
        <h3>Header</h3>
        <div class="field">
          <label for="p-eyebrow">Small label above the title</label>
          <input type="text" id="p-eyebrow" value="<?php echo esc_attr( $page ? $page->eyebrow : '' ); ?>" placeholder="e.g. Why Kounselia exists">
        </div>
        <div class="field">
          <span class="field-label">Wide image under the header (optional)</span>
          <input type="hidden" id="p-hero" value="<?php echo esc_attr( $page ? $page->hero_image : '' ); ?>">
          <div class="img-field" data-input="#p-hero"></div>
        </div>
      </div>

      <div class="side-box">
        <h3>Search & sharing</h3>
        <div class="field">
          <label for="p-meta">Description</label>
          <textarea id="p-meta" maxlength="320" placeholder="One or two sentences shown by Google and when the page is shared."><?php echo esc_textarea( (string) ( $page ? $page->meta_description : '' ) ); ?></textarea>
        </div>
      </div>

      <?php if ( $page ) : ?>
        <button class="btn btn-danger" id="p-delete"><i class="ti ti-trash"></i> Delete page</button>
      <?php endif; ?>
    </aside>
  </div>

  <script>
  (function(){
    var id = <?php echo (int) ( $page ? $page->id : 0 ); ?>;
    var dirty = false, ed;
    function markDirty(){ dirty = true; document.getElementById('p-state').textContent = 'Unsaved changes'; }
    KAdmin.editor('#p-content', { onChange: markDirty }).then(function(e){ ed = e; });
    ['p-title','p-subtitle','p-slug','p-eyebrow','p-meta','p-hero'].forEach(function(f){ document.getElementById(f).addEventListener('input', markDirty); document.getElementById(f).addEventListener('change', markDirty); });
    document.querySelectorAll('[name=p-status]').forEach(function(r){ r.addEventListener('change', markDirty); });
    window.addEventListener('beforeunload', function(e){ if(dirty){ e.preventDefault(); e.returnValue = ''; } });

    function save(){
      var btn = document.getElementById('p-save');
      btn.disabled = true;
      KAdmin.post('kounselia_admin_save_page', {
        id: id,
        title: document.getElementById('p-title').value,
        subtitle: document.getElementById('p-subtitle').value,
        slug: document.getElementById('p-slug').value,
        eyebrow: document.getElementById('p-eyebrow').value,
        hero_image: document.getElementById('p-hero').value,
        meta_description: document.getElementById('p-meta').value,
        status: document.querySelector('[name=p-status]:checked').value,
        content: ed ? ed.getContent() : document.getElementById('p-content').value
      }).then(function(d){
        dirty = false;
        id = d.id;
        history.replaceState(null, '', '?edit=' + d.id);
        document.getElementById('p-slug').value = d.slug;
        document.getElementById('p-url-hint').querySelector('b').textContent = d.slug;
        var v = document.getElementById('p-view'); v.href = d.url; v.style.display = '';
        document.getElementById('p-state').textContent = 'Saved just now';
        KAdmin.toast(d.message);
      }).catch(function(e){ KAdmin.toast(e.message, true); })
        .finally(function(){ btn.disabled = false; });
    }
    document.getElementById('p-save').addEventListener('click', save);
    document.addEventListener('keydown', function(e){ if((e.metaKey || e.ctrlKey) && e.key === 's'){ e.preventDefault(); save(); } });
    var del = document.getElementById('p-delete');
    if(del) del.addEventListener('click', function(){
      if(!confirm('Delete this page permanently? Any footer link to it will disappear.')) return;
      KAdmin.post('kounselia_admin_delete_page', { id: id }).then(function(){ dirty = false; location.href = '/portal/admin/pages/pages.php'; }).catch(function(e){ KAdmin.toast(e.message, true); });
    });
  })();
  </script>

<?php else : ?>
  <div class="cms-head">
    <div>
      <h1 class="admin-title">Pages</h1>
      <div class="admin-subtitle">The content pages linked from your footer and around the site.</div>
    </div>
    <a class="btn btn-primary" href="?new=1"><i class="ti ti-plus"></i> New page</a>
  </div>

  <div class="cms-tabs">
    <a href="?" class="<?php echo 'pages' === $view ? 'active' : ''; ?>">All pages <span class="count"><?php echo count( $pages ); ?></span></a>
    <a href="?tab=footer" class="<?php echo 'footer' === $view ? 'active' : ''; ?>">Footer</a>
  </div>

  <?php if ( 'pages' === $view ) : ?>
    <div class="panel">
      <?php if ( ! $pages ) : ?>
        <div class="empty-state">No pages yet. <a href="?new=1">Create your first page</a>.</div>
      <?php else : ?>
        <table class="admin-table">
          <thead><tr><th>Page</th><th>Status</th><th>Last updated</th><th></th></tr></thead>
          <tbody>
          <?php foreach ( $pages as $p ) : ?>
            <tr>
              <td data-label="Page"><a href="?edit=<?php echo (int) $p->id; ?>"><div class="row-title"><?php echo esc_html( $p->title ); ?></div></a><div class="row-sub">/page/<?php echo esc_html( $p->slug ); ?></div></td>
              <td data-label="Status"><?php echo 'published' === $p->status ? '<span class="pill green">Published</span>' : '<span class="pill grey">Draft</span>'; ?></td>
              <td data-label="Updated"><?php echo esc_html( kounselia_admin_time_label( get_gmt_from_date( $p->updated_at ) ) ); ?></td>
              <td data-label=""><div class="row-actions">
                <a class="btn btn-light btn-sm" href="?edit=<?php echo (int) $p->id; ?>"><i class="ti ti-pencil"></i> Edit</a>
                <a class="btn btn-light btn-sm" href="<?php echo esc_url( kounselia_page_url( $p->slug ) ); ?>" target="_blank"><i class="ti ti-external-link"></i> View</a>
              </div></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

  <?php else :
      $footer = kounselia_footer_settings();
      $page_options = array();
      foreach ( $pages as $p ) {
          $page_options[] = array( 'slug' => $p->slug, 'title' => $p->title, 'status' => $p->status );
      }
      ?>
    <p class="admin-subtitle" style="margin-top:-8px">Arrange the link columns at the bottom of every page. Links to draft pages are hidden until the page is published.</p>
    <div class="foot-cols" id="foot-cols"></div>
    <div class="btn-row" style="margin-bottom:24px"><button class="btn btn-light" id="add-col"><i class="ti ti-columns"></i> Add column</button></div>

    <div class="field-row">
      <div class="panel">
        <div class="panel-title">Brand & social</div>
        <div class="field"><label for="f-tagline">Tagline under the logo</label><textarea id="f-tagline"><?php echo esc_textarea( (string) ( $footer['tagline'] ) ); ?></textarea></div>
        <?php foreach ( array( 'twitter' => 'X (Twitter) URL', 'linkedin' => 'LinkedIn URL', 'instagram' => 'Instagram URL', 'facebook' => 'Facebook URL', 'youtube' => 'YouTube URL', 'email' => 'Contact email' ) as $k => $label ) : ?>
          <div class="field"><label for="f-social-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></label><input type="<?php echo 'email' === $k ? 'email' : 'url'; ?>" id="f-social-<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $footer['social'][ $k ] ); ?>" placeholder="<?php echo 'email' === $k ? 'hello@kounselia.com' : 'https://…'; ?>"></div>
        <?php endforeach; ?>
        <div class="hint">Leave a field empty to hide that icon.</div>
      </div>
      <div class="panel">
        <div class="panel-title">Newsletter box</div>
        <label class="check"><input type="checkbox" id="f-nl" <?php checked( ! empty( $footer['show_newsletter'] ) ); ?>> <span>Show a newsletter sign-up box in the footer</span></label>
        <div class="field"><label for="f-nl-h">Heading</label><input type="text" id="f-nl-h" value="<?php echo esc_attr( $footer['newsletter_heading'] ); ?>"></div>
        <div class="field"><label for="f-nl-t">Text</label><textarea id="f-nl-t"><?php echo esc_textarea( (string) ( $footer['newsletter_text'] ) ); ?></textarea></div>
        <div class="hint">Sign-ups land in Newsletter → Contacts with the source “footer”.</div>
      </div>
    </div>
    <div class="btn-row"><button class="btn btn-primary" id="f-save"><i class="ti ti-device-floppy"></i> Save footer</button></div>

    <script>
    (function(){
      var PAGES = <?php echo wp_json_encode( $page_options ); ?>;
      var cols = <?php echo wp_json_encode( array_values( $footer['columns'] ) ); ?>;
      var wrap = document.getElementById('foot-cols');
      var esc = KAdmin.esc;

      function pageSelect(val){
        var h = '<option value="">Custom link…</option>';
        PAGES.forEach(function(p){ h += '<option value="' + esc(p.slug) + '"' + (p.slug === val ? ' selected' : '') + '>' + esc(p.title) + (p.status !== 'published' ? ' (draft)' : '') + '</option>'; });
        return '<select data-k="page">' + h + '</select>';
      }
      function render(){
        wrap.innerHTML = '';
        cols.forEach(function(col, ci){
          var box = document.createElement('div');
          box.className = 'foot-col';
          var h = '<div class="foot-col-head"><input data-col="title" value="' + esc(col.title) + '" placeholder="Column title">'
            + '<button class="icon-btn del" data-act="del-col" title="Remove column"><i class="ti ti-trash"></i></button></div>';
          (col.links || []).forEach(function(l, li){
            h += '<div class="foot-link" data-li="' + li + '"><div class="l-fields">'
              + '<input data-k="label" value="' + esc(l.label) + '" placeholder="Link text (defaults to page title)">'
              + pageSelect(l.page)
              + '<input data-k="url" value="' + esc(l.url) + '" placeholder="/blog/ or https://…" style="' + (l.page ? 'display:none' : '') + '">'
              + '</div><div class="l-tools">'
              + '<button class="icon-btn" data-act="up" title="Move up"><i class="ti ti-chevron-up"></i></button>'
              + '<button class="icon-btn" data-act="down" title="Move down"><i class="ti ti-chevron-down"></i></button>'
              + '<button class="icon-btn del" data-act="del" title="Remove link"><i class="ti ti-x"></i></button></div></div>';
          });
          h += '<button class="btn btn-light btn-sm" data-act="add-link"><i class="ti ti-plus"></i> Add link</button>';
          box.innerHTML = h;
          function onEdit(e){
            var t = e.target;
            if(t.dataset.col){ col.title = t.value; return; }
            var row = t.closest('.foot-link'); if(!row || !t.dataset.k) return;
            var link = col.links[+row.dataset.li];
            link[t.dataset.k] = t.value;
            if(t.dataset.k === 'page'){ row.querySelector('[data-k=url]').style.display = t.value ? 'none' : ''; if(t.value) link.url = ''; }
          }
          box.addEventListener('input', onEdit);
          box.addEventListener('change', onEdit);
          box.addEventListener('click', function(e){
            var b = e.target.closest('[data-act]'); if(!b) return;
            var row = b.closest('.foot-link'), li = row ? +row.dataset.li : -1;
            if(b.dataset.act === 'add-link'){ col.links = col.links || []; col.links.push({ label: '', page: '', url: '' }); }
            if(b.dataset.act === 'del'){ col.links.splice(li, 1); }
            if(b.dataset.act === 'up' && li > 0){ col.links.splice(li - 1, 0, col.links.splice(li, 1)[0]); }
            if(b.dataset.act === 'down' && li < col.links.length - 1){ col.links.splice(li + 1, 0, col.links.splice(li, 1)[0]); }
            if(b.dataset.act === 'del-col'){ if(!confirm('Remove this whole column?')) return; cols.splice(ci, 1); }
            render();
          });
          wrap.appendChild(box);
        });
      }
      document.getElementById('add-col').addEventListener('click', function(){
        if(cols.length >= 4){ KAdmin.toast('The footer has room for up to 4 columns.', true); return; }
        cols.push({ title: 'New column', links: [] }); render();
      });
      document.getElementById('f-save').addEventListener('click', function(){
        var social = {};
        ['twitter','linkedin','instagram','facebook','youtube','email'].forEach(function(k){ social[k] = document.getElementById('f-social-' + k).value; });
        KAdmin.post('kounselia_admin_save_footer', { footer: JSON.stringify({
          columns: cols, social: social,
          tagline: document.getElementById('f-tagline').value,
          show_newsletter: document.getElementById('f-nl').checked ? 1 : 0,
          newsletter_heading: document.getElementById('f-nl-h').value,
          newsletter_text: document.getElementById('f-nl-t').value
        }) }).then(function(d){ KAdmin.toast(d.message); }).catch(function(e){ KAdmin.toast(e.message, true); });
      });
      render();
    })();
    </script>
  <?php endif; ?>
<?php endif; ?>

</div>
</body>
</html>

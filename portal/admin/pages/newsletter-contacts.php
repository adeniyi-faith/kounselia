<?php
/**
 * Kounselia Admin — Newsletter contacts (the email CRM list).
 *
 * Everyone we may email: members (linked to their account) and website
 * sign-ups. Filter, tag, add, import and export them here. Audiences for
 * campaigns are built from these contacts in Segments / the composer.
 */
require_once __DIR__ . '/../inc/admin-auth.php';
require_once __DIR__ . '/../inc/admin-helpers.php';
$kounselia_admin_active = 'broadcasts';
$kounselia_nl_tab       = 'contacts';

if ( ! kounselia_admin_can( 'broadcasts' ) ) {
    wp_die( 'You do not have permission to use the newsletter.' );
}

global $wpdb;
$table = $wpdb->prefix . 'kounselia_subscribers';

$f = array(
    'q'      => isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '',
    'status' => isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '',
    'kind'   => isset( $_GET['kind'] ) ? sanitize_key( $_GET['kind'] ) : '',
    'tag'    => isset( $_GET['tag'] ) ? sanitize_title( wp_unslash( $_GET['tag'] ) ) : '',
    'source' => isset( $_GET['source'] ) ? sanitize_key( $_GET['source'] ) : '',
);
$where  = array( '1=1' );
$params = array();
if ( '' !== $f['q'] ) {
    $where[]  = '(email LIKE %s OR name LIKE %s)';
    $like     = '%' . $wpdb->esc_like( $f['q'] ) . '%';
    $params[] = $like;
    $params[] = $like;
}
if ( in_array( $f['status'], array( 'subscribed', 'unsubscribed', 'pending' ), true ) ) {
    $where[]  = 'status = %s';
    $params[] = $f['status'];
}
if ( 'members' === $f['kind'] ) {
    $where[] = 'user_id IS NOT NULL';
} elseif ( 'website' === $f['kind'] ) {
    $where[] = 'user_id IS NULL';
}
if ( $f['tag'] ) {
    $where[]  = "CONCAT(',', IFNULL(tags,''), ',') LIKE %s";
    $params[] = '%,' . $wpdb->esc_like( $f['tag'] ) . ',%';
}
if ( $f['source'] ) {
    $where[]  = 'source = %s';
    $params[] = $f['source'];
}
$where_sql = implode( ' AND ', $where );
$per_page  = 50;
$page_num  = isset( $_GET['p'] ) ? max( 1, (int) $_GET['p'] ) : 1;
$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params ) : "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" );
$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, ( $page_num - 1 ) * $per_page ) ) ) );
$pages     = max( 1, (int) ceil( $total / $per_page ) );

// Every tag in use, for the filter dropdown.
$all_tags = array();
foreach ( $wpdb->get_col( "SELECT tags FROM {$table} WHERE tags IS NOT NULL AND tags != ''" ) as $t ) {
    foreach ( explode( ',', $t ) as $one ) {
        if ( '' !== $one ) {
            $all_tags[ $one ] = ( $all_tags[ $one ] ?? 0 ) + 1;
        }
    }
}
arsort( $all_tags );
$sources = $wpdb->get_col( "SELECT DISTINCT source FROM {$table} ORDER BY source" );
$qs      = function ( $extra ) use ( $f ) {
    return '?' . http_build_query( array_filter( array_merge( $f, $extra ) ) );
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kounselia Admin — Contacts</title>
<meta name="robots" content="noindex, nofollow">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<?php require __DIR__ . '/../inc/admin-styles.php'; ?>
<?php require __DIR__ . '/../inc/admin-cms.php'; ?>
<style>
.bulk-bar{display:none;align-items:center;gap:8px;flex-wrap:wrap;background:var(--accent);color:#fff;padding:10px 14px;border-radius:var(--r-md);margin-bottom:12px;font-size:13px}
.bulk-bar.on{display:flex}
.bulk-bar input{padding:7px 10px;border-radius:var(--r-sm);border:none;font-family:inherit;font-size:13px;width:140px}
.bulk-bar .btn{background:rgba(255,255,255,.14);color:#fff;border-color:transparent}
.bulk-bar .btn:hover{background:rgba(255,255,255,.25);color:#fff}
.tagchip{display:inline-block;font-size:11px;background:var(--surface2);color:var(--text2);padding:2px 8px;border-radius:20px;margin:1px 2px 1px 0}
td.sel{width:32px}
td.sel input,th.sel input{width:16px;height:16px;accent-color:var(--accent)}
tr.clickable{cursor:pointer}
</style>
</head>
<body>
<?php require __DIR__ . '/../inc/admin-nav.php'; ?>
<div class="admin-body">
<?php require __DIR__ . '/../inc/newsletter-ui.php'; ?>

  <form class="filters" method="get">
    <input type="text" name="q" value="<?php echo esc_attr( $f['q'] ); ?>" placeholder="Search name or email">
    <select name="status">
      <option value="">Any status</option>
      <?php foreach ( array( 'subscribed' => 'Subscribed', 'unsubscribed' => 'Unsubscribed', 'pending' => 'Awaiting confirmation' ) as $k => $v ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['status'], $k ); ?>><?php echo esc_html( $v ); ?></option><?php endforeach; ?>
    </select>
    <select name="kind">
      <option value="">Members & website</option>
      <option value="members" <?php selected( $f['kind'], 'members' ); ?>>Members only</option>
      <option value="website" <?php selected( $f['kind'], 'website' ); ?>>Website sign-ups only</option>
    </select>
    <select name="tag">
      <option value="">Any tag</option>
      <?php foreach ( $all_tags as $t => $n ) : ?><option value="<?php echo esc_attr( $t ); ?>" <?php selected( $f['tag'], $t ); ?>><?php echo esc_html( $t . ' (' . $n . ')' ); ?></option><?php endforeach; ?>
    </select>
    <select name="source">
      <option value="">Any source</option>
      <?php foreach ( $sources as $s ) : ?><option value="<?php echo esc_attr( $s ); ?>" <?php selected( $f['source'], $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option><?php endforeach; ?>
    </select>
    <button type="submit">Filter</button>
    <?php if ( array_filter( $f ) ) : ?><a class="clear" href="?">Clear</a><?php endif; ?>
  </form>

  <div class="btn-row" style="margin-bottom:14px">
    <button class="btn btn-primary btn-sm" id="open-add"><i class="ti ti-user-plus"></i> Add contact</button>
    <button class="btn btn-light btn-sm" id="open-import"><i class="ti ti-upload"></i> Import CSV</button>
    <a class="btn btn-light btn-sm" href="<?php echo esc_url( add_query_arg( array( 'action' => 'kounselia_admin_nl_export', 'nonce' => wp_create_nonce( 'kounselia_admin_nonce' ), 'status' => $f['status'] ), admin_url( 'admin-ajax.php' ) ) ); ?>"><i class="ti ti-download"></i> Export CSV</a>
    <span style="margin-left:auto;font-size:12.5px;color:var(--text3);align-self:center"><?php echo number_format_i18n( $total ); ?> contact<?php echo 1 === $total ? '' : 's'; ?></span>
  </div>

  <div class="bulk-bar" id="bulk">
    <b id="bulk-n">0 selected</b>
    <input type="text" id="bulk-tag" placeholder="tag name">
    <button class="btn btn-sm" data-bulk="tag"><i class="ti ti-tag"></i> Add tag</button>
    <button class="btn btn-sm" data-bulk="untag">Remove tag</button>
    <button class="btn btn-sm" data-bulk="unsubscribe">Unsubscribe</button>
    <button class="btn btn-sm" data-bulk="resubscribe">Resubscribe</button>
    <button class="btn btn-sm" data-bulk="delete"><i class="ti ti-trash"></i> Delete</button>
  </div>

  <div class="panel">
    <?php if ( ! $rows ) : ?>
      <div class="empty-state"><?php echo $total || array_filter( $f ) ? 'No contacts match these filters.' : 'No contacts yet. They appear here as people subscribe on the website or create an account.'; ?></div>
    <?php else : ?>
      <table class="admin-table" id="contacts">
        <thead><tr><th class="sel"><input type="checkbox" id="sel-all" aria-label="Select all"></th><th>Contact</th><th>Status</th><th>Receives</th><th>Tags</th><th>Source</th><th>Joined</th><th>Last emailed</th></tr></thead>
        <tbody>
        <?php foreach ( $rows as $r ) :
            $tags = array_filter( explode( ',', (string) $r->tags ) ); ?>
          <tr class="clickable" data-c="<?php echo esc_attr( wp_json_encode( array( 'id' => (int) $r->id, 'email' => $r->email, 'name' => $r->name, 'status' => $r->status, 'list_newsletter' => (int) $r->list_newsletter, 'list_blog' => (int) $r->list_blog, 'tags' => implode( ', ', $tags ) ) ) ); ?>">
            <td class="sel" data-label=""><input type="checkbox" class="sel-one" value="<?php echo (int) $r->id; ?>"></td>
            <td data-label="Contact">
              <div class="row-title"><?php echo esc_html( $r->name ? $r->name : $r->email ); ?> <?php if ( $r->user_id ) : ?><a class="pill blue" href="/portal/admin/pages/member-profile.php?id=<?php echo (int) $r->user_id; ?>" onclick="event.stopPropagation()">Member</a><?php endif; ?></div>
              <?php if ( $r->name ) : ?><div class="row-sub"><?php echo esc_html( $r->email ); ?></div><?php endif; ?>
            </td>
            <td data-label="Status"><?php echo 'subscribed' === $r->status ? '<span class="pill green">Subscribed</span>' : ( 'pending' === $r->status ? '<span class="pill gold">Pending</span>' : '<span class="pill rose">Unsubscribed</span>' ); ?></td>
            <td data-label="Receives"><span class="row-sub" style="word-break:normal"><?php echo esc_html( implode( ', ', array_filter( array( $r->list_newsletter ? 'Newsletter' : '', $r->list_blog ? 'Blog' : '' ) ) ) ?: '—' ); ?></span></td>
            <td data-label="Tags"><?php foreach ( $tags as $t ) { echo '<span class="tagchip">' . esc_html( $t ) . '</span>'; } ?></td>
            <td data-label="Source"><span class="row-sub"><?php echo esc_html( ucfirst( $r->source ) ); ?></span></td>
            <td data-label="Joined"><span class="row-sub"><?php echo esc_html( mysql2date( 'M j, Y', $r->created_at ) ); ?></span></td>
            <td data-label="Last emailed"><span class="row-sub"><?php echo $r->last_emailed_at ? esc_html( mysql2date( 'M j, Y', $r->last_emailed_at ) ) : '—'; ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <?php if ( $pages > 1 ) : ?>
    <div class="pagination">
      <?php for ( $i = max( 1, $page_num - 3 ); $i <= min( $pages, $page_num + 3 ); $i++ ) : ?>
        <?php if ( $i === $page_num ) : ?><span class="current"><?php echo $i; ?></span><?php else : ?><a href="<?php echo esc_url( $qs( array( 'p' => $i ) ) ); ?>"><?php echo $i; ?></a><?php endif; ?>
      <?php endfor; ?>
      <span style="border:none">of <?php echo $pages; ?></span>
    </div>
  <?php endif; ?>
</div>

<div class="modal-bg" id="m-contact"><div class="modal">
  <h2 id="m-contact-title">Add contact</h2>
  <input type="hidden" id="mc-id">
  <div class="field"><label for="mc-email">Email</label><input type="email" id="mc-email"></div>
  <div class="field"><label for="mc-name">Name</label><input type="text" id="mc-name"></div>
  <div class="field"><label for="mc-status">Status</label><select id="mc-status"><option value="subscribed">Subscribed</option><option value="unsubscribed">Unsubscribed</option></select>
    <div class="hint">Only add people who have agreed to hear from you.</div></div>
  <label class="check"><input type="checkbox" id="mc-nl" checked> Newsletter</label>
  <label class="check"><input type="checkbox" id="mc-blog" checked> New blog post emails</label>
  <div class="field"><label for="mc-tags">Tags</label><input type="text" id="mc-tags" placeholder="e.g. vip, lagos, partner"></div>
  <div class="btn-row"><button class="btn btn-light" data-close>Cancel</button><button class="btn btn-primary" id="mc-save">Save contact</button></div>
</div></div>

<div class="modal-bg" id="m-import"><div class="modal">
  <h2>Import contacts</h2>
  <p class="hint" style="margin:-6px 0 14px">Upload a .csv file with an <code>email</code> column (and optionally <code>name</code> and <code>tags</code>), or paste emails below, one per line. People who previously unsubscribed stay unsubscribed.</p>
  <div class="field"><label for="mi-file">CSV file</label><input type="file" id="mi-file" accept=".csv,text/csv"></div>
  <div class="field"><label for="mi-csv">…or paste</label><textarea id="mi-csv" rows="6" placeholder="email,name&#10;ada@example.com,Ada"></textarea></div>
  <div class="field"><label for="mi-tags">Tag everyone in this import</label><input type="text" id="mi-tags" placeholder="e.g. event-2026"></div>
  <div class="btn-row"><button class="btn btn-light" data-close>Cancel</button><button class="btn btn-primary" id="mi-go"><i class="ti ti-upload"></i> Import</button></div>
</div></div>

<script>
(function(){
  var $ = function(s){ return document.getElementById(s); };
  function open(m){ $(m).classList.add('open'); }
  document.querySelectorAll('[data-close]').forEach(function(b){ b.addEventListener('click', function(){ b.closest('.modal-bg').classList.remove('open'); }); });

  function fill(c){
    $('m-contact-title').textContent = c ? 'Edit contact' : 'Add contact';
    $('mc-id').value = c ? c.id : '';
    $('mc-email').value = c ? c.email : ''; $('mc-email').disabled = !!c;
    $('mc-name').value = c ? (c.name || '') : '';
    $('mc-status').value = c ? (c.status === 'unsubscribed' ? 'unsubscribed' : 'subscribed') : 'subscribed';
    $('mc-nl').checked = c ? !!c.list_newsletter : true;
    $('mc-blog').checked = c ? !!c.list_blog : true;
    $('mc-tags').value = c ? c.tags : '';
    open('m-contact');
  }
  $('open-add').addEventListener('click', function(){ fill(null); });
  $('open-import').addEventListener('click', function(){ open('m-import'); });

  var table = $('contacts');
  if(table){
    table.addEventListener('click', function(e){
      if(e.target.closest('.sel') || e.target.closest('a')) return;
      var tr = e.target.closest('tr[data-c]'); if(tr) fill(JSON.parse(tr.dataset.c));
    });
    var boxes = table.querySelectorAll('.sel-one');
    function sync(){
      var n = table.querySelectorAll('.sel-one:checked').length;
      $('bulk').classList.toggle('on', n > 0);
      $('bulk-n').textContent = n + ' selected';
    }
    boxes.forEach(function(b){ b.addEventListener('change', sync); });
    $('sel-all').addEventListener('change', function(){ var on = this.checked; boxes.forEach(function(b){ b.checked = on; }); sync(); });
  }

  document.querySelectorAll('[data-bulk]').forEach(function(b){
    b.addEventListener('click', function(){
      var ids = Array.prototype.map.call(document.querySelectorAll('.sel-one:checked'), function(x){ return x.value; });
      var what = b.dataset.bulk, tag = $('bulk-tag').value.trim();
      if((what === 'tag' || what === 'untag') && !tag){ KAdmin.toast('Type a tag name first.', true); return; }
      if(what === 'delete' && !confirm('Permanently delete ' + ids.length + ' contact(s)? Deleting a member here only removes their email preferences, not their account.')) return;
      KAdmin.post('kounselia_admin_nl_bulk_subscribers', { ids: ids.join(','), do: what, tag: tag })
        .then(function(d){ KAdmin.toast(d.message); setTimeout(function(){ location.reload(); }, 600); })
        .catch(function(e){ KAdmin.toast(e.message, true); });
    });
  });

  $('mc-save').addEventListener('click', function(){
    KAdmin.post('kounselia_admin_nl_save_subscriber', {
      id: $('mc-id').value, email: $('mc-email').value, name: $('mc-name').value, status: $('mc-status').value,
      list_newsletter: $('mc-nl').checked ? 1 : 0, list_blog: $('mc-blog').checked ? 1 : 0, tags: $('mc-tags').value
    }).then(function(d){ KAdmin.toast(d.message); setTimeout(function(){ location.reload(); }, 500); })
      .catch(function(e){ KAdmin.toast(e.message, true); });
  });

  $('mi-go').addEventListener('click', function(){
    var fd = new FormData();
    if($('mi-file').files[0]) fd.append('file', $('mi-file').files[0]);
    fd.append('csv', $('mi-csv').value);
    fd.append('tags', $('mi-tags').value);
    var b = this; b.disabled = true;
    KAdmin.post('kounselia_admin_nl_import', fd).then(function(d){ KAdmin.toast(d.message); setTimeout(function(){ location.reload(); }, 1200); })
      .catch(function(e){ KAdmin.toast(e.message, true); }).finally(function(){ b.disabled = false; });
  });
})();
</script>
</body>
</html>

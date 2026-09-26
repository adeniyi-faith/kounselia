<?php
/**
 * Kounselia Admin — Blog.
 *
 * Write, schedule and publish posts for /blog/, and optionally email
 * each new post to subscribers (via the newsletter queue, once per
 * post). Data helpers and AJAX handlers: includes/content.php; the
 * email itself: kounselia_newsletter_queue_post_notification() in
 * includes/newsletter.php.
 */
require_once __DIR__ . '/../inc/admin-auth.php';
require_once __DIR__ . '/../inc/admin-helpers.php';
$kounselia_admin_active = 'blog';

if ( ! kounselia_admin_can( 'blog' ) ) {
    wp_die( 'You do not have permission to manage the blog.' );
}

global $wpdb;
$posts_table = $wpdb->prefix . 'kounselia_posts';
$settings    = kounselia_blog_settings();
$segments    = kounselia_newsletter_list_segments();
$tab         = isset( $_GET['tab'] ) && 'settings' === $_GET['tab'] ? 'settings' : 'posts';
$edit_id     = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
$is_new      = isset( $_GET['new'] );
$post        = $edit_id ? kounselia_get_blog_post( $edit_id ) : null;
if ( $edit_id && ! $post ) {
    wp_safe_redirect( '/portal/admin/pages/blog.php' );
    exit;
}
$editing = $post || $is_new;
$now     = current_time( 'mysql' );

function kounselia_admin_post_status_pill( $p, $now ) {
    if ( 'draft' === $p->status ) {
        return '<span class="pill grey">Draft</span>';
    }
    if ( $p->published_at > $now ) {
        return '<span class="pill gold"><i class="ti ti-clock"></i> Scheduled</span>';
    }
    return '<span class="pill green">Published</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kounselia Admin — Blog</title>
<meta name="robots" content="noindex, nofollow">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<?php require __DIR__ . '/../inc/admin-styles.php'; ?>
<?php require __DIR__ . '/../inc/admin-cms.php'; ?>
<style>
.notify-box{background:var(--gold-light);border:1px solid rgba(176,125,58,.2);border-radius:var(--r-md);padding:12px;margin-top:12px}
.notify-done{font-size:13px;color:var(--text2);line-height:1.5}
.notify-done b{color:var(--text)}
.post-cell{display:flex;gap:12px;align-items:center}
</style>
</head>
<body>
<?php require __DIR__ . '/../inc/admin-nav.php'; ?>

<div class="admin-body">

<?php if ( $editing ) :
    $staff = get_users( array( 'role__in' => array( 'administrator', 'kounselia_staff' ), 'fields' => array( 'ID', 'display_name' ) ) );
    $is_live      = $post && kounselia_blog_post_is_live( $post );
    $is_scheduled = $post && 'published' === $post->status && ! $is_live;
    $mode         = $is_scheduled ? 'schedule' : ( $is_live ? 'now' : 'draft' );
    $campaign     = ( $post && $post->notify_campaign_id ) ? kounselia_newsletter_get_campaign( $post->notify_campaign_id ) : null;
    $sched_value  = $is_scheduled ? mysql2date( 'Y-m-d\TH:i', $post->published_at ) : date( 'Y-m-d\TH:i', current_time( 'timestamp' ) + DAY_IN_SECONDS );
    ?>
  <a class="back-link" href="/portal/admin/pages/blog.php"><i class="ti ti-arrow-left"></i> All posts</a>
  <div class="editor-layout">
    <div class="editor-main">
      <input class="title-input" id="b-title" placeholder="Title" value="<?php echo esc_attr( $post ? $post->title : '' ); ?>">
      <input class="subtitle-input" id="b-subtitle" placeholder="Subtitle — one line that makes people want to read on" value="<?php echo esc_attr( $post ? $post->subtitle : '' ); ?>">
      <textarea id="b-content"><?php echo esc_textarea( (string) ( $post ? $post->content : '' ) ); ?></textarea>
      <div class="hint" id="b-words" style="margin-top:8px"></div>
    </div>

    <aside class="editor-side">
      <div class="side-box">
        <h3>Publish <?php echo $post ? kounselia_admin_post_status_pill( $post, $now ) : ''; ?></h3>
        <div class="seg">
          <label><input type="radio" name="b-mode" value="draft" <?php checked( 'draft', $mode ); ?>> Draft</label>
          <label><input type="radio" name="b-mode" value="now" <?php checked( 'now', $mode ); ?>> <?php echo $is_live ? 'Live' : 'Publish'; ?></label>
          <label><input type="radio" name="b-mode" value="schedule" <?php checked( 'schedule', $mode ); ?>> Schedule</label>
        </div>
        <div class="field" id="b-when-wrap" style="<?php echo 'schedule' === $mode ? '' : 'display:none'; ?>">
          <label for="b-when">Go live at (site time)</label>
          <input type="datetime-local" id="b-when" value="<?php echo esc_attr( $sched_value ); ?>">
        </div>

        <?php if ( $campaign ) : ?>
          <div class="notify-box notify-done">
            <i class="ti ti-mail-check"></i> <b>Subscribers <?php echo 'scheduled' === $campaign->status ? 'will be emailed when it goes live' : ( 'cancelled' === $campaign->status ? 'email was cancelled' : 'were emailed' ); ?>.</b><br>
            <?php if ( in_array( $campaign->status, array( 'sending', 'sent' ), true ) ) : ?>
              <?php echo number_format_i18n( $campaign->sent_count ); ?> sent · <?php echo number_format_i18n( $campaign->open_count ); ?> opened · <?php echo number_format_i18n( $campaign->click_count ); ?> clicked<br>
            <?php endif; ?>
            <a href="/portal/admin/pages/newsletter.php">See it in Newsletter</a>
          </div>
        <?php else : ?>
          <div class="notify-box">
            <label class="check" style="margin-bottom:8px"><input type="checkbox" id="b-notify" <?php checked( ! empty( $settings['auto_notify'] ) && ! $is_live ); // Never pre-tick for a post that's already live. ?>> <span><b>Email this post to subscribers</b><small>Sent once, when the post goes live, to people who opted in to blog emails.</small></span></label>
            <div class="field" style="margin-bottom:0">
              <select id="b-notify-seg">
                <option value="0">Everyone subscribed to blog emails</option>
                <?php foreach ( $segments as $s ) : ?>
                  <option value="<?php echo (int) $s->id; ?>" <?php selected( (int) $settings['notify_segment_id'], (int) $s->id ); ?>>Segment: <?php echo esc_html( $s->name ); ?></option>
                <?php endforeach; ?>
              </select>
              <div class="hint" id="b-notify-count"></div>
            </div>
          </div>
        <?php endif; ?>

        <div class="btn-row" style="margin-top:14px">
          <button class="btn btn-primary" id="b-save" style="flex:1"><i class="ti ti-device-floppy"></i> <span>Save</span></button>
          <a class="btn btn-light" id="b-view" href="<?php echo $post ? esc_url( kounselia_blog_url( $post->slug ) ) : '#'; ?>" target="_blank" <?php echo $post ? '' : 'style="display:none"'; ?>><i class="ti ti-eye"></i> <?php echo $is_live ? 'View' : 'Preview'; ?></a>
        </div>
        <div class="save-state" id="b-state"><?php echo $post ? 'Last saved ' . esc_html( kounselia_admin_time_label( get_gmt_from_date( $post->updated_at ) ) ) : 'Not saved yet'; ?></div>
      </div>

      <div class="side-box">
        <h3>Cover image</h3>
        <input type="hidden" id="b-cover" value="<?php echo esc_attr( $post ? $post->cover_image : '' ); ?>">
        <div class="img-field" data-input="#b-cover"></div>
        <div class="field" style="margin-top:10px"><input type="text" id="b-caption" placeholder="Caption / photo credit (optional)" value="<?php echo esc_attr( $post ? $post->cover_caption : '' ); ?>"></div>
      </div>

      <div class="side-box">
        <h3>Story details</h3>
        <div class="field">
          <label for="b-tags">Topics</label>
          <input type="text" id="b-tags" list="b-tag-list" value="<?php echo esc_attr( $post ? $post->tags : '' ); ?>" placeholder="e.g. Anxiety, Self care">
          <datalist id="b-tag-list"><?php foreach ( kounselia_blog_all_tags() as $t ) { echo '<option value="' . esc_attr( $t['name'] ) . '">'; } ?></datalist>
          <div class="hint">Separate with commas. The first topic is shown above the title.</div>
        </div>
        <div class="field">
          <label for="b-excerpt">Short summary</label>
          <textarea id="b-excerpt" placeholder="Shown on the blog list and in emails. Leave empty to use the subtitle."><?php echo esc_textarea( (string) ( $post ? $post->excerpt : '' ) ); ?></textarea>
        </div>
        <div class="field">
          <label for="b-author">Author</label>
          <select id="b-author">
            <?php $current_author = $post ? (int) $post->author_id : get_current_user_id(); ?>
            <?php foreach ( $staff as $u ) : ?>
              <option value="<?php echo (int) $u->ID; ?>" <?php selected( $current_author, (int) $u->ID ); ?>><?php echo esc_html( $u->display_name ); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <label class="check"><input type="checkbox" id="b-featured" <?php checked( $post && $post->featured ); ?>> <span>Feature at the top of the blog</span></label>
      </div>

      <div class="side-box">
        <h3>Address & search</h3>
        <div class="field">
          <label for="b-slug">URL</label>
          <input type="text" id="b-slug" value="<?php echo esc_attr( $post ? $post->slug : '' ); ?>" placeholder="made from the title">
          <div class="hint" id="b-url-hint"><?php echo esc_html( kounselia_site_url( '/blog/' ) ); ?><b><?php echo esc_html( $post ? $post->slug : '…' ); ?></b></div>
        </div>
        <div class="field">
          <label for="b-meta">Search description</label>
          <textarea id="b-meta" maxlength="320" placeholder="Optional. Defaults to the summary."><?php echo esc_textarea( (string) ( $post ? $post->meta_description : '' ) ); ?></textarea>
        </div>
      </div>

      <?php if ( $post ) : ?>
        <button class="btn btn-danger" id="b-delete"><i class="ti ti-trash"></i> Delete post</button>
      <?php endif; ?>
    </aside>
  </div>

  <script>
  (function(){
    var id = <?php echo (int) ( $post ? $post->id : 0 ); ?>;
    var notified = <?php echo $campaign ? 'true' : 'false'; ?>;
    var dirty = false, ed;
    var $ = function(s){ return document.getElementById(s); };
    function words(){
      if(!ed) return;
      var n = (ed.plugins && ed.plugins.wordcount) ? ed.plugins.wordcount.body.getWordCount() : (ed.getContent().replace(/<[^>]+>/g, ' ').match(/\S+/g) || []).length;
      $('b-words').textContent = n + ' words · about ' + Math.max(1, Math.ceil(n / 220)) + ' min read';
    }
    function markDirty(){ dirty = true; $('b-state').textContent = 'Unsaved changes'; words(); }
    KAdmin.editor('#b-content', { onChange: markDirty }).then(function(e){ ed = e; words(); });
    ['b-title','b-subtitle','b-slug','b-tags','b-excerpt','b-meta','b-caption','b-cover','b-author','b-featured','b-when'].forEach(function(f){ $(f).addEventListener('input', markDirty); $(f).addEventListener('change', markDirty); });
    window.addEventListener('beforeunload', function(e){ if(dirty){ e.preventDefault(); e.returnValue = ''; } });

    function mode(){ return document.querySelector('[name=b-mode]:checked').value; }
    function syncMode(){
      $('b-when-wrap').style.display = mode() === 'schedule' ? '' : 'none';
      var label = { draft: 'Save draft', now: <?php echo wp_json_encode( $is_live ? 'Update' : 'Publish now' ); ?>, schedule: 'Schedule' }[mode()];
      $('b-save').querySelector('span').textContent = label;
    }
    document.querySelectorAll('[name=b-mode]').forEach(function(r){ r.addEventListener('change', function(){ syncMode(); markDirty(); }); });
    syncMode();

    function refreshCount(){
      if(!$('b-notify-seg')) return;
      KAdmin.post('kounselia_admin_nl_count', { segment_id: $('b-notify-seg').value, list_key: 'blog', rules: '{}' })
        .then(function(d){ $('b-notify-count').textContent = d.count.toLocaleString() + ' ' + (d.count === 1 ? 'person' : 'people') + ' will get this email.'; })
        .catch(function(){ $('b-notify-count').textContent = ''; });
    }
    if($('b-notify-seg')){ $('b-notify-seg').addEventListener('change', refreshCount); refreshCount(); }

    function save(){
      var m = mode();
      var notify = !notified && $('b-notify') && $('b-notify').checked && m !== 'draft';
      if(notify && m === 'now' && !confirm('Publish now and email this post to subscribers? The email can only be sent once.')) return;
      var btn = $('b-save'); btn.disabled = true;
      KAdmin.post('kounselia_admin_save_post', {
        id: id,
        title: $('b-title').value, subtitle: $('b-subtitle').value, slug: $('b-slug').value,
        content: ed ? ed.getContent() : $('b-content').value,
        cover_image: $('b-cover').value, cover_caption: $('b-caption').value,
        tags: $('b-tags').value, excerpt: $('b-excerpt').value, author_id: $('b-author').value,
        featured: $('b-featured').checked ? 1 : 0, meta_description: $('b-meta').value,
        publish_mode: m, publish_at: $('b-when').value.replace('T', ' '),
        notify: notify ? 1 : 0, notify_segment_id: $('b-notify-seg') ? $('b-notify-seg').value : 0
      }).then(function(d){
        dirty = false;
        KAdmin.toast(d.message);
        if(!id || d.notified !== notified){ location.href = '?edit=' + d.id; return; }
        $('b-slug').value = d.slug;
        $('b-url-hint').querySelector('b').textContent = d.slug;
        $('b-view').href = d.url; $('b-view').style.display = '';
        $('b-state').textContent = 'Saved just now';
      }).catch(function(e){ KAdmin.toast(e.message, true); })
        .finally(function(){ btn.disabled = false; });
    }
    $('b-save').addEventListener('click', save);
    document.addEventListener('keydown', function(e){ if((e.metaKey || e.ctrlKey) && e.key === 's'){ e.preventDefault(); save(); } });
    if($('b-delete')) $('b-delete').addEventListener('click', function(){
      if(!confirm('Delete this post permanently?')) return;
      KAdmin.post('kounselia_admin_delete_post', { id: id }).then(function(){ dirty = false; location.href = '/portal/admin/pages/blog.php'; }).catch(function(e){ KAdmin.toast(e.message, true); });
    });
  })();
  </script>

<?php else :
    $filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
    $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
    $where  = array( '1=1' );
    $params = array();
    if ( 'draft' === $filter ) {
        $where[] = "status = 'draft'";
    } elseif ( 'scheduled' === $filter ) {
        $where[] = "status = 'published' AND published_at > %s";
        $params[] = $now;
    } elseif ( 'published' === $filter ) {
        $where[] = "status = 'published' AND published_at <= %s";
        $params[] = $now;
    }
    if ( '' !== $search ) {
        $where[]  = 'title LIKE %s';
        $params[] = '%' . $wpdb->esc_like( $search ) . '%';
    }
    $sql   = "SELECT id, slug, title, status, published_at, updated_at, author_id, views, cover_image, featured, notify_campaign_id, tags FROM {$posts_table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY COALESCE(published_at, updated_at) DESC LIMIT 200';
    $posts = $wpdb->get_results( $params ? $wpdb->prepare( $sql, $params ) : $sql );
    $counts = $wpdb->get_row( $wpdb->prepare(
        "SELECT COUNT(*) AS total, SUM(status='draft') AS drafts, SUM(status='published' AND published_at > %s) AS scheduled, SUM(status='published' AND published_at <= %s) AS published, COALESCE(SUM(views),0) AS views FROM {$posts_table}",
        $now, $now
    ) );
    ?>
  <div class="cms-head">
    <div>
      <h1 class="admin-title">Blog</h1>
      <div class="admin-subtitle">Stories for <a href="/blog/" target="_blank"><?php echo esc_html( $settings['title'] ); ?></a>, and the emails that tell readers about them.</div>
    </div>
    <a class="btn btn-primary" href="?new=1"><i class="ti ti-pencil-plus"></i> Write a post</a>
  </div>

  <div class="cms-tabs">
    <a href="?" class="<?php echo 'posts' === $tab && 'all' === $filter ? 'active' : ''; ?>">All <span class="count"><?php echo (int) $counts->total; ?></span></a>
    <a href="?status=published" class="<?php echo 'published' === $filter ? 'active' : ''; ?>">Published <span class="count"><?php echo (int) $counts->published; ?></span></a>
    <a href="?status=scheduled" class="<?php echo 'scheduled' === $filter ? 'active' : ''; ?>">Scheduled <span class="count"><?php echo (int) $counts->scheduled; ?></span></a>
    <a href="?status=draft" class="<?php echo 'draft' === $filter ? 'active' : ''; ?>">Drafts <span class="count"><?php echo (int) $counts->drafts; ?></span></a>
    <a href="?tab=settings" class="<?php echo 'settings' === $tab ? 'active' : ''; ?>"><i class="ti ti-settings"></i> Settings</a>
  </div>

  <?php if ( 'settings' === $tab ) : ?>
    <div class="panel" style="max-width:640px">
      <div class="field"><label for="s-title">Blog name</label><input type="text" id="s-title" value="<?php echo esc_attr( $settings['title'] ); ?>"></div>
      <div class="field"><label for="s-tagline">Tagline</label><textarea id="s-tagline"><?php echo esc_textarea( (string) ( $settings['tagline'] ) ); ?></textarea></div>
      <div class="field"><label for="s-per">Posts per page</label><input type="number" id="s-per" min="3" max="30" value="<?php echo (int) $settings['per_page']; ?>"></div>
      <label class="check"><input type="checkbox" id="s-auto" <?php checked( ! empty( $settings['auto_notify'] ) ); ?>> <span><b>Email new posts to subscribers by default</b><small>Pre-ticks “Email this post to subscribers” on every new post. You can still untick it per post.</small></span></label>
      <div class="field"><label for="s-seg">Default audience for new-post emails</label>
        <select id="s-seg">
          <option value="0">Everyone subscribed to blog emails</option>
          <?php foreach ( $segments as $s ) : ?><option value="<?php echo (int) $s->id; ?>" <?php selected( (int) $settings['notify_segment_id'], (int) $s->id ); ?>><?php echo esc_html( $s->name ); ?></option><?php endforeach; ?>
        </select>
        <div class="hint">Build audiences in <a href="/portal/admin/pages/newsletter-segments.php">Newsletter → Segments</a>.</div>
      </div>
      <button class="btn btn-primary" id="s-save"><i class="ti ti-device-floppy"></i> Save settings</button>
    </div>
    <script>
    document.getElementById('s-save').addEventListener('click', function(){
      KAdmin.post('kounselia_admin_save_blog_settings', {
        title: document.getElementById('s-title').value, tagline: document.getElementById('s-tagline').value,
        per_page: document.getElementById('s-per').value, auto_notify: document.getElementById('s-auto').checked ? 1 : 0,
        notify_segment_id: document.getElementById('s-seg').value
      }).then(function(d){ KAdmin.toast(d.message); }).catch(function(e){ KAdmin.toast(e.message, true); });
    });
    </script>

  <?php else : ?>
    <form class="filters" method="get">
      <?php if ( 'all' !== $filter ) : ?><input type="hidden" name="status" value="<?php echo esc_attr( $filter ); ?>"><?php endif; ?>
      <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search post titles">
      <button type="submit">Search</button>
      <?php if ( '' !== $search ) : ?><a class="clear" href="?<?php echo 'all' !== $filter ? 'status=' . esc_attr( $filter ) : ''; ?>">Clear</a><?php endif; ?>
      <span style="margin-left:auto;font-size:12.5px;color:var(--text3)"><?php echo number_format_i18n( (int) $counts->views ); ?> total reads</span>
    </form>

    <div class="panel">
      <?php if ( ! $posts ) : ?>
        <div class="empty-state"><?php echo $counts->total ? 'No posts match.' : 'No posts yet. <a href="?new=1">Write your first story</a>.'; ?></div>
      <?php else : ?>
        <table class="admin-table">
          <thead><tr><th>Post</th><th>Status</th><th>Date</th><th>Reads</th><th>Emailed</th><th></th></tr></thead>
          <tbody>
          <?php foreach ( $posts as $p ) :
              $author = $p->author_id ? get_userdata( $p->author_id ) : null; ?>
            <tr>
              <td data-label="Post"><div class="post-cell">
                <?php if ( $p->cover_image ) : ?><img class="thumb" src="<?php echo esc_url( $p->cover_image ); ?>" alt=""><?php else : ?><span class="thumb"></span><?php endif; ?>
                <div><a href="?edit=<?php echo (int) $p->id; ?>" class="row-title"><?php echo esc_html( $p->title ); ?></a><?php echo $p->featured ? ' <span class="pill blue">Featured</span>' : ''; ?>
                <div class="row-sub"><?php echo esc_html( $author ? $author->display_name : '—' ); ?><?php echo $p->tags ? ' · ' . esc_html( $p->tags ) : ''; ?></div></div>
              </div></td>
              <td data-label="Status"><?php echo kounselia_admin_post_status_pill( $p, $now ); ?></td>
              <td data-label="Date"><?php echo esc_html( $p->published_at ? mysql2date( 'M j, Y', $p->published_at ) : '—' ); ?></td>
              <td data-label="Reads"><?php echo number_format_i18n( (int) $p->views ); ?></td>
              <td data-label="Emailed"><?php echo $p->notify_campaign_id ? '<span class="pill green"><i class="ti ti-mail-check"></i> Yes</span>' : '<span class="pill grey">No</span>'; ?></td>
              <td data-label=""><div class="row-actions">
                <a class="btn btn-light btn-sm" href="?edit=<?php echo (int) $p->id; ?>"><i class="ti ti-pencil"></i> Edit</a>
                <a class="btn btn-light btn-sm" href="<?php echo esc_url( kounselia_blog_url( $p->slug ) ); ?>" target="_blank"><i class="ti ti-eye"></i></a>
              </div></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

</div>
</body>
</html>

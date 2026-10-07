<?php
/**
 * Kounselia — a professional writes an article for the Journal.
 *
 *   /pro-write.php          a new article
 *   /pro-write.php?id=12    one of their own
 *
 * Built for a phone first: one column, a sticky bar with Save and Send,
 * the rich editor with a short toolbar, and details (topics, summary,
 * comments on/off) underneath. What "Send" does depends on the admin's
 * settings for this professional: publish straight away (trusted) or
 * send to an editor (review). A copy of unsaved work is kept on the
 * device, so a dropped connection or a closed tab never loses writing.
 *
 * All saving goes through includes/articles.php.
 */
define( 'WP_USE_THEMES', false );
define( 'COOKIEPATH', '/' );
define( 'SITECOOKIEPATH', '/' );
require_once __DIR__ . '/portal/wp-load.php';

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( '/index.php' );
    exit;
}

$user = wp_get_current_user();
$pro  = kounselia_get_professional_application( $user->ID );
if ( ! $pro ) {
    wp_safe_redirect( '/apply.php' );
    exit;
}
$access = kounselia_article_access( $pro );
if ( ! $access['allowed'] ) {
    wp_safe_redirect( '/pro-dashboard.php?tab=articles' );
    exit;
}

$settings = kounselia_article_settings();
$post_id  = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
$post     = $post_id ? kounselia_get_blog_post( $post_id ) : null;
if ( $post_id && ! kounselia_article_owned_by( $post, $pro ) ) {
    wp_safe_redirect( '/pro-dashboard.php?tab=articles' );
    exit;
}

$fields = $post ? kounselia_article_editable_fields( $post ) : array(
    'title' => '', 'subtitle' => '', 'excerpt' => '', 'content' => '', 'cover_image' => '', 'cover_caption' => '', 'tags' => '', 'allow_comments' => 1,
);
$state    = $post ? kounselia_article_state( $post ) : 'draft';
$is_live  = in_array( $state, array( 'live', 'live_pending', 'live_draft' ), true );
$trusted  = 'trusted' === $access['mode'];
$send_txt = $trusted ? ( $is_live ? 'Update' : 'Publish' ) : ( $is_live ? 'Send changes' : 'Send for review' );
$all_tags = array_slice( array_values( kounselia_blog_all_tags() ), 0, 16 );

$ajax_url = set_url_scheme( admin_url( 'admin-ajax.php' ), is_ssl() ? 'https' : 'http' );
$nonce    = wp_create_nonce( 'kounselia_auth' );

$plugins = 'autolink link lists wordcount autoresize quickbars';
$toolbar = 'blocks | bold italic | link blockquote bullist numlist';
if ( ! empty( $settings['allow_images'] ) ) {
    $plugins .= ' image';
    $toolbar .= ' | image';
}
if ( ! empty( $settings['allow_embeds'] ) ) {
    $plugins .= ' media';
    $toolbar .= ( ! empty( $settings['allow_images'] ) ? ' media' : ' | media' );
}
$toolbar .= ' | undo redo';
?>
<!DOCTYPE html>
<html <?php echo function_exists( 'kounselia_html_attrs' ) ? kounselia_html_attrs() : 'lang="en"'; ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?php echo $post ? 'Edit article' : 'Write an article'; ?> — Kounselia</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/png" href="https://kounselia.com/img/fv.png">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.5/tinymce.min.js" referrerpolicy="origin"></script>
<?php require __DIR__ . '/inc/kounselia-styles.php'; ?>
<style>
html,body{background:var(--bg)}
body{font-family:'Outfit',sans-serif;color:var(--text);-webkit-font-smoothing:antialiased;padding-bottom:env(safe-area-inset-bottom)}
.w-bar{position:sticky;top:0;z-index:30;background:rgba(255,255,255,.94);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border-bottom:1px solid var(--border);padding:10px 16px;padding-top:calc(10px + env(safe-area-inset-top))}
.w-bar-in{max-width:760px;margin:0 auto;display:flex;align-items:center;gap:10px}
.w-back{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:var(--text2);text-decoration:none;flex-shrink:0}
.w-back:hover{background:var(--surface2)}
.w-back i{font-size:20px}
.w-state{flex:1;min-width:0;font-size:12.5px;color:var(--text3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.w-state b{display:block;font-size:13.5px;color:var(--text);font-weight:600}
.w-btn{border:none;border-radius:50px;font-family:inherit;font-size:14px;font-weight:500;padding:10px 16px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;min-height:40px}
.w-btn.ghost{background:none;color:var(--text2);border:1px solid var(--border)}
.w-btn.ghost:hover{border-color:var(--accent);color:var(--accent)}
.w-btn.primary{background:var(--accent);color:#fff}
.w-btn.primary:hover{background:var(--accent2)}
.w-btn:disabled{opacity:.55;cursor:not-allowed}
@media (max-width:420px){.w-btn .lbl-long{display:none}.w-btn{padding:10px 13px}}

.w-main{max-width:760px;margin:0 auto;padding:20px 16px 60px}
.w-banner{border-radius:14px;padding:12px 14px;font-size:13.5px;line-height:1.55;margin-bottom:16px;display:flex;gap:10px;align-items:flex-start}
.w-banner i{font-size:18px;flex-shrink:0;margin-top:1px}
.w-banner.info{background:var(--accent-light);color:var(--accent)}
.w-banner.warn{background:var(--gold-light);color:#6B4A1F}
.w-banner.bad{background:var(--rose-light);color:var(--rose)}
.w-banner button{margin-left:auto;border:none;background:rgba(255,255,255,.7);border-radius:8px;padding:6px 10px;font-family:inherit;font-weight:600;font-size:12.5px;color:inherit;cursor:pointer;flex-shrink:0}

.w-cover{position:relative;border:1.5px dashed var(--border);border-radius:18px;background:var(--surface);min-height:120px;display:flex;align-items:center;justify-content:center;cursor:pointer;overflow:hidden;margin-bottom:18px;color:var(--text3);font-size:13.5px;text-align:center}
.w-cover:hover{border-color:var(--accent)}
.w-cover img{width:100%;max-height:320px;object-fit:cover;display:block}
.w-cover .ph i{font-size:26px;display:block;margin-bottom:6px;color:var(--gold)}
.w-cover .rm{position:absolute;top:10px;right:10px;width:34px;height:34px;border-radius:50%;border:none;background:rgba(24,22,15,.7);color:#fff;cursor:pointer;display:none;align-items:center;justify-content:center;font-size:16px}
.w-cover.has-img{border-style:solid}
.w-cover.has-img .rm{display:flex}
.w-cover.busy::after{content:'Uploading…';position:absolute;inset:0;background:rgba(255,255,255,.85);display:flex;align-items:center;justify-content:center;color:var(--accent);font-size:14px}

.w-title{width:100%;border:none;background:transparent;font-family:'Cormorant Garamond',serif;font-size:36px;font-weight:500;line-height:1.15;color:var(--text);outline:none;resize:none;overflow:hidden;padding:0}
.w-sub{width:100%;border:none;background:transparent;font-size:17px;font-weight:300;color:var(--text2);outline:none;padding:8px 0 14px;font-family:inherit}
.w-title::placeholder,.w-sub::placeholder{color:#C9C4B8}
@media (max-width:520px){.w-title{font-size:30px}}
.w-editor{background:var(--surface);border:1px solid var(--border);border-radius:18px;overflow:hidden}
.w-editor .tox-tinymce{border:none!important;border-radius:0!important}
.w-editor textarea{width:100%;min-height:360px;border:none;padding:16px;font-family:inherit;font-size:16px}
.w-words{font-size:12.5px;color:var(--text3);margin-top:8px;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}
.w-words .bad{color:var(--rose);font-weight:600}

.w-section{background:var(--surface);border:1px solid var(--border);border-radius:18px;padding:18px;margin-top:18px}
.w-section h2{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:var(--text3);font-weight:600;margin-bottom:14px}
.w-field{margin-bottom:16px}
.w-field:last-child{margin-bottom:0}
.w-field label{display:block;font-size:13px;font-weight:500;color:var(--text2);margin-bottom:6px}
.w-field input[type=text],.w-field textarea{width:100%;padding:12px 14px;border:1.5px solid var(--border);border-radius:12px;font-family:inherit;font-size:16px;color:var(--text);background:var(--bg);outline:none}
.w-field textarea{min-height:88px;resize:vertical;line-height:1.5}
.w-field input:focus,.w-field textarea:focus{border-color:var(--accent);background:var(--surface);box-shadow:0 0 0 4px var(--accent-light)}
.w-hint{font-size:12px;color:var(--text3);margin-top:6px;line-height:1.45}
.w-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.w-chips button{border:1px solid var(--border);background:var(--bg);border-radius:999px;padding:6px 11px;font-family:inherit;font-size:12.5px;color:var(--text2);cursor:pointer;min-height:32px}
.w-chips button.on{background:var(--accent-light);color:var(--accent);border-color:transparent}
.w-toggle{display:flex;gap:12px;align-items:flex-start;cursor:pointer;font-size:14px;line-height:1.45}
.w-toggle input{width:20px;height:20px;accent-color:var(--accent);flex-shrink:0;margin-top:1px}
.w-toggle small{display:block;color:var(--text3);font-size:12.5px}
.w-guide summary{cursor:pointer;font-weight:600;font-size:14px;color:var(--accent);list-style:none;display:flex;align-items:center;gap:8px}
.w-guide summary::-webkit-details-marker{display:none}
.w-guide ul{margin:12px 0 0 18px;font-size:13.5px;line-height:1.6;color:var(--text2)}
.w-guide li{margin-bottom:6px}
.w-danger{margin-top:22px;text-align:center}
.w-danger button{border:none;background:none;color:var(--rose);font-family:inherit;font-size:13.5px;cursor:pointer;padding:10px}

.w-toast{position:fixed;left:50%;bottom:calc(20px + env(safe-area-inset-bottom));transform:translateX(-50%);background:var(--text);color:#fff;padding:12px 18px;border-radius:14px;font-size:14px;max-width:calc(100vw - 32px);box-shadow:var(--shadow-md);z-index:60;line-height:1.45;display:none}
.w-toast.error{background:var(--rose)}
.w-toast.show{display:block;animation:wToast .25s ease-out}
@keyframes wToast{from{opacity:0;transform:translate(-50%,10px)}to{opacity:1;transform:translate(-50%,0)}}
</style>
</head>
<body>

<header class="w-bar">
  <div class="w-bar-in">
    <a class="w-back" href="/pro-dashboard.php?tab=articles" aria-label="Back to your articles"><i class="ti ti-arrow-left"></i></a>
    <div class="w-state" id="w-state"><b><?php echo esc_html( kounselia_article_state_label( $state ) ); ?></b><span id="w-saved"><?php echo $post ? 'Saved' : 'Not saved yet'; ?></span></div>
    <button class="w-btn ghost" id="w-save" type="button"><i class="ti ti-device-floppy"></i><span class="lbl-long"><?php echo $is_live ? 'Save for later' : 'Save draft'; ?></span></button>
    <button class="w-btn primary" id="w-send" type="button"><i class="ti ti-<?php echo $trusted ? 'send' : 'eye-check'; ?>"></i> <?php echo esc_html( $send_txt ); ?></button>
  </div>
</header>

<main class="w-main">
  <?php if ( $post && $post->review_note && in_array( $post->review_status, array( 'changes_requested', 'rejected', 'removed' ), true ) ) : ?>
    <div class="w-banner <?php echo 'changes_requested' === $post->review_status ? 'warn' : 'bad'; ?>"><i class="ti ti-message-dots"></i><div><b>Note from the editor:</b> <?php echo nl2br( esc_html( $post->review_note ) ); ?></div></div>
  <?php endif; ?>
  <?php if ( 'pending' === $state ) : ?>
    <div class="w-banner info"><i class="ti ti-clock"></i><div>This article is waiting for an editor. Saving a draft takes it out of the queue until you send it again.</div></div>
  <?php elseif ( $is_live && ! $trusted ) : ?>
    <div class="w-banner info"><i class="ti ti-world"></i><div>This article is live. Changes you send go to an editor first. Readers keep seeing the current version until they're approved.</div></div>
  <?php elseif ( $is_live ) : ?>
    <div class="w-banner info"><i class="ti ti-world"></i><div>This article is live. Updates go live straight away.</div></div>
  <?php endif; ?>
  <div class="w-banner warn" id="w-restore" style="display:none"><i class="ti ti-history"></i><div>You have unsaved writing from earlier on this device.</div><button type="button" id="w-restore-btn">Restore it</button></div>

  <input type="hidden" id="w-cover-url" value="<?php echo esc_attr( $fields['cover_image'] ); ?>">
  <div class="w-cover" id="w-cover" role="button" tabindex="0" aria-label="Add a cover image"></div>

  <textarea class="w-title" id="w-title" rows="1" maxlength="200" placeholder="Title"><?php echo esc_textarea( $fields['title'] ); ?></textarea>
  <input class="w-sub" id="w-subtitle" maxlength="300" placeholder="A line that makes people want to read on" value="<?php echo esc_attr( $fields['subtitle'] ); ?>">

  <div class="w-editor"><textarea id="w-content"><?php echo esc_textarea( $fields['content'] ); ?></textarea></div>
  <div class="w-words"><span id="w-words"></span><span><?php echo $settings['min_words'] ? esc_html( number_format_i18n( $settings['min_words'] ) . ' to ' . number_format_i18n( $settings['max_words'] ) . ' words' ) : 'Up to ' . esc_html( number_format_i18n( $settings['max_words'] ) ) . ' words'; ?></span></div>

  <section class="w-section">
    <h2>Details</h2>
    <div class="w-field">
      <label for="w-tags">Topics</label>
      <input type="text" id="w-tags" value="<?php echo esc_attr( $fields['tags'] ); ?>" placeholder="e.g. Anxiety, Sleep" autocomplete="off">
      <div class="w-hint">Up to <?php echo (int) $settings['max_tags']; ?>, separated by commas. The first one shows above your title.</div>
      <?php if ( $all_tags ) : ?>
        <div class="w-chips" id="w-tag-chips">
          <?php foreach ( $all_tags as $t ) : ?><button type="button" data-tag="<?php echo esc_attr( $t['name'] ); ?>"><?php echo esc_html( $t['name'] ); ?></button><?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <div class="w-field">
      <label for="w-excerpt">Short summary</label>
      <textarea id="w-excerpt" maxlength="500" placeholder="Two sentences for the Journal list, search engines and followers' emails."><?php echo esc_textarea( $fields['excerpt'] ); ?></textarea>
      <div class="w-hint"><span id="w-excerpt-count">0</span>/500. Leave empty to use your subtitle.</div>
    </div>
    <div class="w-field">
      <label for="w-caption">Cover caption or photo credit</label>
      <input type="text" id="w-caption" maxlength="200" value="<?php echo esc_attr( $fields['cover_caption'] ); ?>" placeholder="Optional">
    </div>
    <?php if ( ! empty( $settings['comments_enabled'] ) ) : ?>
      <div class="w-field">
        <label class="w-toggle"><input type="checkbox" id="w-comments" <?php checked( ! empty( $fields['allow_comments'] ) ); ?>><span>Allow comments<small>Readers can respond and ask questions. You can pin or hide comments on your articles.</small></span></label>
      </div>
    <?php endif; ?>
  </section>

  <section class="w-section">
    <details class="w-guide">
      <summary><i class="ti ti-book-2"></i> Writing for the Kounselia Journal</summary>
      <ul>
        <li><b>Never share anything that could identify a client</b>, even with details changed, unless you have their written consent.</li>
        <li>Write for someone who is struggling: warm, plain words, short paragraphs, and no jargon without an explanation.</li>
        <li>Share general guidance, not a diagnosis. Encourage people to seek personal care where it matters.</li>
        <li>Link to sources for facts and figures.</li>
        <li>No advertising for outside services or products. Readers can book you on Kounselia from every article you write.</li>
        <?php if ( ! empty( $settings['disclaimer_enabled'] ) ) : ?><li>A short note that articles are not a substitute for personal care is added to the end of every article for you.</li><?php endif; ?>
      </ul>
    </details>
  </section>

  <?php if ( $post ) : ?>
    <div class="w-danger"><button type="button" id="w-delete"><i class="ti ti-trash"></i> Delete this article</button></div>
  <?php endif; ?>
</main>

<div class="w-toast" id="w-toast" role="status" aria-live="polite"></div>

<script>
(function(){
  var AJAX = <?php echo wp_json_encode( $ajax_url ); ?>;
  var NONCE = <?php echo wp_json_encode( $nonce ); ?>;
  var id = <?php echo (int) ( $post ? $post->id : 0 ); ?>;
  var MIN = <?php echo (int) $settings['min_words']; ?>, MAX = <?php echo (int) $settings['max_words']; ?>, MAX_TAGS = <?php echo (int) $settings['max_tags']; ?>;
  var TRUSTED = <?php echo $trusted ? 'true' : 'false'; ?>, LIVE = <?php echo $is_live ? 'true' : 'false'; ?>;
  var $ = function(i){ return document.getElementById(i); };
  var ed = null, dirty = false, busy = false;
  var backupKey = 'kounselia-article-' + (id || 'new');

  function toast(msg, isError){
    var t = $('w-toast');
    t.textContent = msg; t.className = 'w-toast show' + (isError ? ' error' : '');
    clearTimeout(t._h); t._h = setTimeout(function(){ t.className = 'w-toast'; }, isError ? 7000 : 4000);
  }

  function post(action, data){
    var body = data instanceof FormData ? data : new URLSearchParams(data || {});
    body.append('action', action); body.append('nonce', NONCE);
    return fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function(r){ return r.json().catch(function(){ throw new Error('The server returned an unexpected response.'); }); })
      .then(function(res){
        if(!res || !res.success){ var e = new Error((res && res.data && res.data.message) || 'Something went wrong. Please try again.'); e.data = res && res.data; throw e; }
        return res.data;
      });
  }

  function upload(file, purpose){
    var fd = new FormData(); fd.append('file', file); fd.append('purpose', purpose || 'inline');
    return post('kounselia_pro_article_upload', fd).then(function(d){ return d.url; });
  }

  /* ---- Cover ---- */
  var cover = $('w-cover'), coverUrl = $('w-cover-url');
  var picker = document.createElement('input'); picker.type = 'file'; picker.accept = 'image/jpeg,image/png,image/webp'; picker.hidden = true; document.body.appendChild(picker);
  function renderCover(){
    cover.innerHTML = '';
    cover.classList.toggle('has-img', !!coverUrl.value);
    if(coverUrl.value){ var img = document.createElement('img'); img.src = coverUrl.value; img.alt = ''; cover.appendChild(img); }
    else { cover.innerHTML = '<div class="ph"><i class="ti ti-photo-plus"></i>Add a cover image<br><small>JPG, PNG or WebP, up to 5MB</small></div>'; }
    var rm = document.createElement('button'); rm.type = 'button'; rm.className = 'rm'; rm.setAttribute('aria-label', 'Remove cover'); rm.innerHTML = '<i class="ti ti-x"></i>';
    rm.addEventListener('click', function(e){ e.stopPropagation(); coverUrl.value = ''; renderCover(); changed(); });
    cover.appendChild(rm);
  }
  cover.addEventListener('click', function(){ picker.click(); });
  cover.addEventListener('keydown', function(e){ if(e.key === 'Enter' || e.key === ' '){ e.preventDefault(); picker.click(); } });
  picker.addEventListener('change', function(){
    if(!picker.files[0]) return;
    cover.classList.add('busy');
    upload(picker.files[0], 'cover').then(function(url){ coverUrl.value = url; renderCover(); changed(); })
      .catch(function(e){ toast(e.message, true); })
      .finally(function(){ cover.classList.remove('busy'); picker.value = ''; });
  });
  renderCover();

  /* ---- Title grows with its text ---- */
  function grow(){ var t = $('w-title'); t.style.height = 'auto'; t.style.height = t.scrollHeight + 'px'; }
  $('w-title').addEventListener('input', grow); window.addEventListener('resize', grow); grow();
  $('w-title').addEventListener('keydown', function(e){ if(e.key === 'Enter'){ e.preventDefault(); $('w-subtitle').focus(); } });

  /* ---- Words ---- */
  function content(){ return ed ? ed.getContent() : $('w-content').value; }
  function words(){
    var text = ed ? ed.getContent({ format: 'text' }) : content().replace(/<[^>]+>/g, ' ');
    var n = (text.match(/\S+/g) || []).length;
    var el = $('w-words');
    el.textContent = n.toLocaleString() + ' words · about ' + Math.max(1, Math.ceil(n / 220)) + ' min read';
    el.className = (MIN && n < MIN) || (MAX && n > MAX) ? 'bad' : '';
  }

  /* ---- Topics ---- */
  function tagList(){ return $('w-tags').value.split(',').map(function(t){ return t.trim(); }).filter(Boolean); }
  function syncChips(){
    var have = tagList().map(function(t){ return t.toLowerCase(); });
    document.querySelectorAll('#w-tag-chips button').forEach(function(b){ b.classList.toggle('on', have.indexOf(b.dataset.tag.toLowerCase()) !== -1); });
  }
  document.querySelectorAll('#w-tag-chips button').forEach(function(b){
    b.addEventListener('click', function(){
      var list = tagList(), low = list.map(function(t){ return t.toLowerCase(); }), i = low.indexOf(b.dataset.tag.toLowerCase());
      if(i !== -1){ list.splice(i, 1); }
      else if(list.length >= MAX_TAGS){ toast('You can choose up to ' + MAX_TAGS + ' topics.', true); return; }
      else { list.push(b.dataset.tag); }
      $('w-tags').value = list.join(', '); syncChips(); changed();
    });
  });
  $('w-tags').addEventListener('input', syncChips); syncChips();

  function countExcerpt(){ $('w-excerpt-count').textContent = $('w-excerpt').value.length; }
  $('w-excerpt').addEventListener('input', countExcerpt); countExcerpt();

  /* ---- What gets sent ---- */
  function fields(){
    return {
      title: $('w-title').value, subtitle: $('w-subtitle').value, content: content(),
      cover_image: coverUrl.value, cover_caption: $('w-caption').value, tags: $('w-tags').value,
      excerpt: $('w-excerpt').value, allow_comments: $('w-comments') ? ($('w-comments').checked ? 1 : 0) : 1
    };
  }

  /* ---- Unsaved work is kept on this device ---- */
  function backup(){ try { localStorage.setItem(backupKey, JSON.stringify({ at: Date.now(), fields: fields() })); } catch(e){} }
  function clearBackup(){ try { localStorage.removeItem(backupKey); localStorage.removeItem('kounselia-article-new'); } catch(e){} }
  function changed(){ dirty = true; $('w-saved').textContent = 'Unsaved changes'; words(); clearTimeout(changed._t); changed._t = setTimeout(backup, 800); }
  ['w-title','w-subtitle','w-tags','w-excerpt','w-caption','w-comments'].forEach(function(f){ if($(f)){ $(f).addEventListener('input', changed); $(f).addEventListener('change', changed); } });
  window.addEventListener('beforeunload', function(e){ if(dirty){ e.preventDefault(); e.returnValue = ''; } });

  function offerRestore(){
    var saved; try { saved = JSON.parse(localStorage.getItem(backupKey) || 'null'); } catch(e){ saved = null; }
    if(!saved || !saved.fields) return;
    var now = fields();
    if(saved.fields.content === now.content && saved.fields.title === now.title) { clearBackup(); return; }
    $('w-restore').style.display = '';
    $('w-restore-btn').addEventListener('click', function(){
      var f = saved.fields;
      $('w-title').value = f.title || ''; $('w-subtitle').value = f.subtitle || ''; $('w-tags').value = f.tags || '';
      $('w-excerpt').value = f.excerpt || ''; $('w-caption').value = f.cover_caption || ''; coverUrl.value = f.cover_image || '';
      if($('w-comments')) $('w-comments').checked = String(f.allow_comments) !== '0';
      if(ed) ed.setContent(f.content || ''); else $('w-content').value = f.content || '';
      renderCover(); grow(); syncChips(); countExcerpt(); changed();
      $('w-restore').style.display = 'none';
    });
  }

  /* ---- Saving ---- */
  function save(intent){
    if(busy) return;
    var f = fields();
    if(!f.title.trim()){ toast('Please give your article a title first.', true); $('w-title').focus(); return; }
    if(intent === 'send'){
      var q = TRUSTED ? (LIVE ? 'Update the live article now?' : 'Publish this article now? It goes live on the Journal and your followers will be told.')
                      : (LIVE ? 'Send your changes to an editor? Readers keep seeing the current version until they are approved.' : 'Send this article to an editor for review?');
      if(!confirm(q)) return;
    }
    busy = true; $('w-save').disabled = $('w-send').disabled = true;
    f.id = id; f.intent = intent;
    post('kounselia_pro_article_save', f).then(function(d){
      dirty = false; clearBackup();
      var first = !id; id = d.id; backupKey = 'kounselia-article-' + id;
      if(intent === 'send'){
        toast(d.message);
        setTimeout(function(){ location.href = '/pro-dashboard.php?tab=articles'; }, 1200);
        return;
      }
      $('w-state').querySelector('b').textContent = d.state_label;
      $('w-saved').textContent = 'Saved just now';
      toast(d.message);
      if(first){ history.replaceState(null, '', '/pro-write.php?id=' + id); }
    }).catch(function(e){
      // A draft may have been kept even though sending failed.
      if(e.data && e.data.id && !id){ id = e.data.id; backupKey = 'kounselia-article-' + id; history.replaceState(null, '', '/pro-write.php?id=' + id); }
      toast(e.message, true);
    }).finally(function(){ busy = false; $('w-save').disabled = $('w-send').disabled = false; });
  }
  $('w-save').addEventListener('click', function(){ save('draft'); });
  $('w-send').addEventListener('click', function(){ save('send'); });
  document.addEventListener('keydown', function(e){ if((e.metaKey || e.ctrlKey) && e.key === 's'){ e.preventDefault(); save('draft'); } });

  if($('w-delete')) $('w-delete').addEventListener('click', function(){
    if(!confirm('Delete this article for good? Its comments and loves are deleted too. This cannot be undone.')) return;
    post('kounselia_pro_article_delete', { id: id }).then(function(){ dirty = false; clearBackup(); location.href = '/pro-dashboard.php?tab=articles'; })
      .catch(function(e){ toast(e.message, true); });
  });

  /* ---- The editor ---- */
  function ready(){ words(); offerRestore(); }
  if(!window.tinymce){
    // Editor could not load (offline or blocked): a plain box still works.
    $('w-content').addEventListener('input', changed);
    toast('The editor could not load, so you are writing in a plain box. Check your connection and reload.', true);
    ready();
    return;
  }
  var small = window.innerWidth < 640;
  tinymce.init({
    selector: '#w-content',
    menubar: false, promotion: false, branding: false, statusbar: false,
    browser_spellcheck: true, contextmenu: false,
    plugins: <?php echo wp_json_encode( $plugins ); ?>,
    toolbar: <?php echo wp_json_encode( $toolbar ); ?>,
    toolbar_mode: small ? 'sliding' : 'wrap',
    toolbar_sticky: true, toolbar_sticky_offset: 61,
    min_height: small ? 360 : 460, autoresize_bottom_margin: 30,
    placeholder: 'Start writing. Tip: select text to make a heading or a quote.',
    block_formats: 'Paragraph=p; Heading=h2; Subheading=h3',
    quickbars_selection_toolbar: 'bold italic | h2 h3 blockquote | quicklink',
    quickbars_insert_toolbar: false,
    link_default_target: '_blank', link_assume_external_targets: 'https', link_title: false,
    convert_urls: true, relative_urls: false, remove_script_host: false,
    image_caption: true, image_dimensions: false, automatic_uploads: true, paste_data_images: <?php echo ! empty( $settings['allow_images'] ) ? 'true' : 'false'; ?>,
    media_alt_source: false, media_poster: false, media_dimensions: false,
    images_upload_handler: function(blob){ return upload(blob.blob(), 'inline'); },
    file_picker_types: 'image',
    file_picker_callback: function(cb){
      var f = document.createElement('input'); f.type = 'file'; f.accept = 'image/*';
      f.onchange = function(){ if(f.files[0]) upload(f.files[0], 'inline').then(function(url){ cb(url, { alt: '' }); }).catch(function(e){ toast(e.message, true); }); };
      f.click();
    },
    content_style: "body{font-family:'Outfit',-apple-system,sans-serif;font-size:17px;line-height:1.75;color:#3a372f;margin:16px}"
      + "h2{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:28px;color:#1E3A5F;margin:1.3em 0 .4em;line-height:1.2}"
      + "h3{font-size:18px;font-weight:600;color:#18160F;margin:1.2em 0 .4em}a{color:#8B3A52}"
      + "blockquote{margin:1.4em 0;padding-left:18px;border-left:3px solid #B07D3A;font-family:'Cormorant Garamond',Georgia,serif;font-size:23px;font-style:italic;color:#1E3A5F}"
      + "img{max-width:100%;height:auto;border-radius:12px}iframe{max-width:100%}"
      + ".mce-content-body[data-mce-placeholder]:not(.mce-visualblocks)::before{color:#B8B3A7}",
    setup: function(e){
      e.on('input change undo redo', changed);
      e.on('init', function(){ ed = e; ready(); });
    }
  });
})();
</script>
</body>
</html>

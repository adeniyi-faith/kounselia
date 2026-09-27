<?php
/**
 * The community pieces the public site shares (blog/ and professionals/):
 * the Follow button, the Love button, and the conversation under an
 * article. Server logic lives in
 * portal/wp-content/mu-plugins/kounselia/includes/community.php; this
 * file is markup, styles and the small script that talks to it.
 *
 * Usage, after kounselia_public_head():
 *   echo kounselia_follow_button_html( $professional_id );
 *   echo kounselia_love_button_html( $post );
 *   kounselia_comments_section( $post );          // an article's conversation
 *   kounselia_community_assets();                 // once, near </body>
 *
 * Signed-out visitors can read everything. Anything that needs an
 * account sends them to sign in and brings them back to the same place.
 */

/** Where "sign in" goes from this page, coming back to $anchor after. */
function kounselia_community_login_url( $anchor = '' ) {
    $path = (string) parse_url( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH );
    return '/?auth=login&return=' . rawurlencode( $path . ( $anchor ? '#' . $anchor : '' ) );
}

function kounselia_follow_button_html( $professional_id, $opts = array() ) {
    if ( ! function_exists( 'kounselia_follows_on' ) || ! kounselia_follows_on() ) {
        return '';
    }
    $opts      = wp_parse_args( $opts, array( 'count' => false, 'class' => '' ) );
    $user_id   = get_current_user_id();
    $pro       = kounselia_get_professional_by_id( $professional_id );
    if ( ! $pro || 'verified' !== $pro->status || (int) $pro->user_id === (int) $user_id ) {
        return '';
    }
    $following = kounselia_is_following( $user_id, $pro->id );
    $count     = $opts['count'] ? kounselia_follower_count( $pro->id ) : null;
    $label     = $following ? 'Following' : 'Follow';
    $attrs     = 'class="k-follow' . ( $following ? ' on' : '' ) . ( $opts['class'] ? ' ' . esc_attr( $opts['class'] ) : '' ) . '" data-pro="' . (int) $pro->id . '" aria-pressed="' . ( $following ? 'true' : 'false' ) . '"';
    $inner     = '<i class="ti ti-' . ( $following ? 'check' : 'plus' ) . '"></i><span>' . $label . '</span>';
    $html      = $user_id
        ? '<button type="button" ' . $attrs . '>' . $inner . '</button>'
        : '<a href="' . esc_url( kounselia_community_login_url() ) . '" ' . $attrs . '>' . $inner . '</a>';
    if ( null !== $count ) {
        $html .= '<span class="k-follow-count" data-pro-count="' . (int) $pro->id . '">' . esc_html( number_format_i18n( $count ) ) . ' ' . ( 1 === $count ? 'follower' : 'followers' ) . '</span>';
    }
    return $html;
}

function kounselia_love_button_html( $post ) {
    if ( ! function_exists( 'kounselia_community_on' ) || ! kounselia_community_on( $post, 'loves' ) ) {
        return '';
    }
    $user_id = get_current_user_id();
    $loved   = kounselia_post_loved_by( $post->id, $user_id );
    $inner   = '<i class="ti ti-heart' . ( $loved ? '-filled' : '' ) . '"></i><span class="n">' . esc_html( number_format_i18n( (int) $post->love_count ) ) . '</span>';
    $attrs   = 'class="k-love' . ( $loved ? ' on' : '' ) . '" data-post="' . (int) $post->id . '" aria-pressed="' . ( $loved ? 'true' : 'false' ) . '" aria-label="Love this article"';
    return $user_id
        ? '<button type="button" ' . $attrs . '>' . $inner . '</button>'
        : '<a href="' . esc_url( kounselia_community_login_url() ) . '" ' . $attrs . '>' . $inner . '</a>';
}

/**
 * The conversation under an article. The first page is sent with the
 * page itself (no extra request, and readable without JavaScript).
 */
function kounselia_comments_section( $post ) {
    if ( ! function_exists( 'kounselia_community_on' ) ) {
        return;
    }
    $enabled = kounselia_community_on( $post, 'comments' );
    $user_id = get_current_user_id();
    $found   = kounselia_comments_for_post( $post, $user_id );
    if ( ! $enabled && ! $found['total'] ) {
        return;
    }
    $data = array(
        'post_id'  => (int) $post->id,
        'enabled'  => $enabled,
        'comments' => $found['comments'],
        'has_more' => $found['has_more'],
        'total'    => (int) $post->comment_count,
        'viewer'   => kounselia_comment_viewer( $post, $user_id ),
        'reasons'  => kounselia_comment_report_reasons(),
        'login'    => kounselia_community_login_url( 'comments' ),
        'is_pro'   => kounselia_is_pro_article( $post ),
    );
    ?>
    <section class="k-comments" id="comments" aria-label="Conversation">
      <h2 class="k-comments-title">Conversation <span class="k-comments-count" id="k-c-count"><?php echo (int) $post->comment_count ? (int) $post->comment_count : ''; ?></span></h2>
      <p class="k-comments-note"><i class="ti ti-heart-handshake"></i> Be kind: people here may be going through something hard. Comments show only a first name or nickname<?php echo kounselia_is_pro_article( $post ) ? ', and are not a substitute for a session' : ''; ?>.</p>
      <div id="k-c-compose"></div>
      <div id="k-c-list" class="k-c-list">
        <?php foreach ( $found['comments'] as $c ) : // Readable before (or without) the script. ?>
          <article class="k-c" id="comment-<?php echo (int) $c['id']; ?>"><div class="k-c-av"><?php echo esc_html( $c['author']['initial'] ); ?></div><div class="k-c-main"><div class="k-c-head"><b><?php echo esc_html( $c['author']['name'] ); ?></b> <span><?php echo esc_html( $c['time_label'] ); ?></span></div><div class="k-c-text"><?php echo nl2br( esc_html( $c['content'] ) ); ?></div></div></article>
        <?php endforeach; ?>
      </div>
      <div class="k-c-more"><button type="button" id="k-c-more" hidden>Show more comments</button></div>
      <script type="application/json" id="k-c-data"><?php echo wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>
    </section>
    <?php
}

/** Styles and script, printed once per page. */
function kounselia_community_assets() {
    static $done = false;
    if ( $done ) {
        return;
    }
    $done = true;
    $ctx  = function_exists( 'kounselia_public_context' ) ? kounselia_public_context() : array( 'ajax_url' => admin_url( 'admin-ajax.php' ), 'logged_in' => is_user_logged_in() );
    ?>
<style>
.k-follow{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;min-height:38px;border-radius:50px;border:1px solid var(--accent);background:var(--accent);color:#fff;font-family:'Outfit',sans-serif;font-size:14px;font-weight:500;cursor:pointer;text-decoration:none;transition:all .2s;white-space:nowrap}
.k-follow:hover{filter:brightness(1.08)}
.k-follow.on{background:var(--surface);color:var(--accent);border-color:var(--border)}
.k-follow.on:hover{border-color:var(--rose);color:var(--rose)}
.k-follow i{font-size:16px}
.k-follow[disabled]{opacity:.6}
.k-follow-count{font-size:13.5px;color:var(--text3);margin-left:10px}
.k-love{display:inline-flex;align-items:center;gap:7px;padding:8px 16px;min-height:42px;border-radius:50px;border:1px solid var(--border);background:var(--surface);color:var(--text2);font-family:'Outfit',sans-serif;font-size:15px;font-weight:500;cursor:pointer;text-decoration:none;transition:all .2s}
.k-love i{font-size:20px;transition:transform .25s cubic-bezier(.3,1.6,.5,1)}
.k-love:hover{border-color:var(--rose);color:var(--rose)}
.k-love.on{color:var(--rose);border-color:rgba(139,58,82,.3);background:var(--rose-light)}
.k-love.pop i{transform:scale(1.35)}
.k-react{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.k-react-link{display:inline-flex;align-items:center;gap:7px;padding:8px 16px;min-height:42px;border-radius:50px;border:1px solid var(--border);background:var(--surface);color:var(--text2);font-size:15px;font-weight:500;text-decoration:none}
.k-react-link:hover{border-color:var(--accent);color:var(--accent)}
.k-react-link i{font-size:19px}

.k-comments{max-width:680px;margin:44px auto 0;padding:0 0 10px;scroll-margin-top:90px}
.k-c{scroll-margin-top:100px}
.k-comments-title{font-family:'Cormorant Garamond',serif;font-weight:400;font-size:34px;color:var(--text);display:flex;align-items:baseline;gap:10px}
.k-comments-count{font-family:'Outfit',sans-serif;font-size:16px;color:var(--text3)}
.k-comments-note{font-size:14px;color:var(--text2);line-height:1.55;margin:8px 0 20px;display:flex;gap:8px;align-items:flex-start}
.k-comments-note i{color:var(--gold);font-size:18px;flex-shrink:0;margin-top:1px}
.k-compose{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:14px;margin-bottom:24px;box-shadow:var(--shadow-sm)}
.k-compose textarea{width:100%;border:none;outline:none;resize:none;font-family:'Outfit',sans-serif;font-size:16px;line-height:1.55;color:var(--text);background:transparent;min-height:52px;max-height:320px}
.k-compose textarea::placeholder{color:var(--text3)}
.k-compose-foot{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:8px;flex-wrap:wrap}
.k-compose-who{font-size:12.5px;color:var(--text3)}
.k-compose-who button{border:none;background:none;color:var(--accent);font:inherit;cursor:pointer;padding:0;text-decoration:underline}
.k-compose-right{display:flex;align-items:center;gap:10px;margin-left:auto}
.k-compose-count{font-size:12px;color:var(--text3)}
.k-compose-count.bad{color:var(--rose)}
.k-post-btn{border:none;border-radius:50px;background:var(--accent);color:#fff;font-family:'Outfit',sans-serif;font-size:14px;font-weight:500;padding:9px 18px;min-height:38px;cursor:pointer}
.k-post-btn:disabled{opacity:.45;cursor:default}
.k-post-btn.ghost{background:none;color:var(--text2)}
.k-signin{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;background:var(--surface);border:1px dashed var(--border);border-radius:20px;padding:16px 18px;margin-bottom:24px;font-size:15px;color:var(--text2)}
.k-signin .k-btn{padding:10px 20px;font-size:14px}
.k-closed{font-size:14px;color:var(--text3);background:var(--surface2);border-radius:14px;padding:12px 16px;margin-bottom:22px}
.k-ident{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:18px;margin-bottom:24px}
.k-ident h3{font-size:17px;font-weight:600;color:var(--text);margin-bottom:4px}
.k-ident p{font-size:14px;color:var(--text2);line-height:1.55;margin-bottom:12px}
.k-ident label{display:flex;gap:10px;align-items:center;padding:12px 14px;border:1.5px solid var(--border);border-radius:14px;margin-bottom:8px;cursor:pointer;font-size:15px}
.k-ident label:has(input:checked){border-color:var(--accent);background:var(--accent-light)}
.k-ident input[type=radio]{accent-color:var(--accent);width:18px;height:18px;flex-shrink:0}
.k-ident input[type=text]{flex:1;min-width:0;border:1px solid var(--border);border-radius:10px;padding:8px 10px;font-family:inherit;font-size:16px}
.k-ident .k-ident-err{color:var(--rose);font-size:13px;min-height:18px;margin:4px 0}
.k-c-list{display:flex;flex-direction:column}
.k-c{display:flex;gap:12px;padding:18px 0;border-top:1px solid var(--border)}
.k-c:first-child{border-top:none}
.k-c-av{width:38px;height:38px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:15px;color:var(--accent);background:var(--accent-light);overflow:hidden}
.k-c-av img{width:100%;height:100%;object-fit:cover}
.k-c-av.t1{background:var(--gold-light);color:var(--gold)}.k-c-av.t2{background:var(--sage-light);color:var(--sage)}.k-c-av.t3{background:var(--plum-light);color:var(--plum)}.k-c-av.t4{background:var(--rose-light);color:var(--rose)}.k-c-av.t5{background:var(--teal-light);color:var(--teal)}
.k-c-main{flex:1;min-width:0}
.k-c-head{display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:13px;color:var(--text3)}
.k-c-head b{font-size:14.5px;color:var(--text);font-weight:600}
.k-c-head a{color:inherit;text-decoration:none}
.k-c-badge{display:inline-flex;align-items:center;gap:3px;font-size:11px;font-weight:600;color:var(--teal);background:var(--teal-light);border-radius:50px;padding:2px 8px}
.k-c-pin{display:inline-flex;align-items:center;gap:3px;font-size:11px;font-weight:600;color:var(--gold)}
.k-c-text{font-size:15.5px;line-height:1.6;color:var(--text);margin-top:4px;white-space:pre-wrap;word-break:break-word}
.k-c.removed .k-c-text{color:var(--text3);font-style:italic}
.k-c.held{opacity:.75}
.k-c-held{font-size:12.5px;color:#8a5a12;background:var(--gold-light);border-radius:8px;padding:6px 10px;margin-top:8px;display:inline-block}
.k-c-actions{display:flex;align-items:center;gap:4px;margin:6px 0 0 -8px}
.k-c-actions button{border:none;background:none;color:var(--text3);font-family:'Outfit',sans-serif;font-size:13px;font-weight:500;cursor:pointer;padding:6px 8px;min-height:34px;border-radius:8px;display:inline-flex;align-items:center;gap:5px}
.k-c-actions button:hover{color:var(--accent);background:var(--surface2)}
.k-c-actions button.on{color:var(--rose)}
.k-c-actions i{font-size:16px}
.k-c-replies{margin-top:4px}
.k-c-replies .k-c{padding:14px 0 4px}
.k-c-replies .k-c:first-child{border-top:none}
.k-c-replies .k-c-av{width:30px;height:30px;font-size:13px}
.k-c-reply{margin-top:10px}
.k-c-reply .k-compose{margin-bottom:6px;padding:10px 12px}
.k-c-more{text-align:center;margin-top:10px}
.k-c-more button{border:1px solid var(--border);background:var(--surface);border-radius:50px;padding:10px 20px;font-family:inherit;font-size:14px;color:var(--text2);cursor:pointer}
.k-care{background:var(--sage-light);border:1px solid rgba(46,92,62,.18);border-radius:18px;padding:16px 18px;margin-bottom:24px;color:#1f3d2a;font-size:15px;line-height:1.6}
.k-care b{display:block;font-size:16px;margin-bottom:4px}
.k-care a{color:#1f3d2a;font-weight:600}
.k-sheet-bg{position:fixed;inset:0;background:rgba(24,22,15,.45);z-index:200;display:none;align-items:flex-end;justify-content:center}
.k-sheet-bg.open{display:flex}
.k-sheet{background:var(--surface);width:100%;max-width:480px;border-radius:22px 22px 0 0;padding:10px 18px calc(18px + env(safe-area-inset-bottom));max-height:85vh;overflow:auto;animation:kSheet .22s ease-out}
@media (min-width:640px){.k-sheet-bg{align-items:center}.k-sheet{border-radius:22px}}
@keyframes kSheet{from{transform:translateY(24px);opacity:0}to{transform:none;opacity:1}}
.k-sheet-grab{width:40px;height:4px;border-radius:4px;background:var(--border);margin:0 auto 12px}
.k-sheet h3{font-size:17px;font-weight:600;margin-bottom:10px;color:var(--text)}
.k-sheet-item{display:flex;align-items:center;gap:12px;width:100%;border:none;background:none;padding:14px 6px;font-family:inherit;font-size:15.5px;color:var(--text);cursor:pointer;border-radius:12px;text-align:left}
.k-sheet-item:hover{background:var(--surface2)}
.k-sheet-item i{font-size:20px;color:var(--text3)}
.k-sheet-item.danger,.k-sheet-item.danger i{color:var(--rose)}
.k-sheet label.k-sheet-item input{accent-color:var(--accent);width:18px;height:18px}
.k-sheet textarea{width:100%;border:1px solid var(--border);border-radius:12px;padding:10px 12px;font-family:inherit;font-size:16px;margin:6px 0 12px;min-height:70px}
.k-sheet-actions{display:flex;gap:10px;justify-content:flex-end}
.k-toast2{position:fixed;left:50%;bottom:calc(20px + env(safe-area-inset-bottom));transform:translateX(-50%);background:var(--text);color:#fff;padding:12px 18px;border-radius:14px;font-size:14px;max-width:calc(100vw - 32px);z-index:210;display:none;line-height:1.45}
.k-toast2.show{display:block}
.k-toast2.error{background:var(--rose)}
</style>
<script>
(function(){
  var CFG = { ajax: <?php echo wp_json_encode( $ctx['ajax_url'] ); ?>, nonce: <?php echo wp_json_encode( wp_create_nonce( 'kounselia_auth' ) ); ?>, loggedIn: <?php echo ! empty( $ctx['logged_in'] ) ? 'true' : 'false'; ?> };

  function call(action, data){
    var body = new URLSearchParams(data || {});
    body.append('action', action); body.append('nonce', CFG.nonce);
    return fetch(CFG.ajax, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function(r){ return r.json().catch(function(){ throw new Error('Something went wrong. Please try again.'); }); })
      .then(function(res){
        if(!res || !res.success){ var e = new Error((res && res.data && res.data.message) || 'Something went wrong. Please try again.'); e.data = res && res.data; throw e; }
        return res.data;
      });
  }
  function toast(msg, isError){
    var t = document.getElementById('k-toast2');
    if(!t){ t = document.createElement('div'); t.id = 'k-toast2'; t.className = 'k-toast2'; t.setAttribute('role', 'status'); document.body.appendChild(t); }
    t.textContent = msg; t.className = 'k-toast2 show' + (isError ? ' error' : '');
    clearTimeout(t._h); t._h = setTimeout(function(){ t.className = 'k-toast2'; }, isError ? 6000 : 3200);
  }
  function el(tag, cls, text){ var e = document.createElement(tag); if(cls) e.className = cls; if(text != null) e.textContent = text; return e; }
  function icon(name){ var i = document.createElement('i'); i.className = 'ti ti-' + name; return i; }
  function plural(n, one, many){ return n.toLocaleString() + ' ' + (n === 1 ? one : many); }
  window.KCommunity = { call: call, toast: toast };

  /* ---------- Follow ---------- */
  document.querySelectorAll('button.k-follow').forEach(function(btn){
    btn.addEventListener('click', function(){
      var on = btn.classList.contains('on');
      if(on && !confirm('Stop following? You will no longer hear when they publish.')) return;
      btn.disabled = true;
      call('kounselia_follow', { professional_id: btn.dataset.pro, follow: on ? 0 : 1 }).then(function(d){
        document.querySelectorAll('button.k-follow[data-pro="' + btn.dataset.pro + '"]').forEach(function(b){
          b.classList.toggle('on', d.following); b.setAttribute('aria-pressed', d.following ? 'true' : 'false');
          b.querySelector('span').textContent = d.following ? 'Following' : 'Follow';
          b.querySelector('i').className = 'ti ti-' + (d.following ? 'check' : 'plus');
        });
        document.querySelectorAll('[data-pro-count="' + btn.dataset.pro + '"]').forEach(function(c){ c.textContent = plural(d.followers, 'follower', 'followers'); });
        if(d.following) toast('You will hear when they publish something new.');
      }).catch(function(e){ toast(e.message, true); }).finally(function(){ btn.disabled = false; });
    });
  });

  /* ---------- Love an article ---------- */
  document.querySelectorAll('button.k-love').forEach(function(btn){
    btn.addEventListener('click', function(){
      var on = !btn.classList.contains('on');
      btn.classList.toggle('on', on); btn.classList.add('pop'); setTimeout(function(){ btn.classList.remove('pop'); }, 250);
      btn.querySelector('i').className = 'ti ti-heart' + (on ? '-filled' : '');
      call('kounselia_post_love', { post_id: btn.dataset.post, love: on ? 1 : 0 }).then(function(d){
        document.querySelectorAll('button.k-love[data-post="' + btn.dataset.post + '"]').forEach(function(b){
          b.classList.toggle('on', d.loved); b.querySelector('i').className = 'ti ti-heart' + (d.loved ? '-filled' : ''); b.querySelector('.n').textContent = d.count.toLocaleString();
        });
      }).catch(function(e){ btn.classList.toggle('on', !on); btn.querySelector('i').className = 'ti ti-heart' + (!on ? '-filled' : ''); toast(e.message, true); });
    });
  });

  /* ---------- Bottom sheet (menus, report) ---------- */
  var sheetBg;
  function sheet(build){
    if(!sheetBg){
      sheetBg = el('div', 'k-sheet-bg'); sheetBg.setAttribute('role', 'dialog'); sheetBg.setAttribute('aria-modal', 'true');
      sheetBg.addEventListener('click', function(e){ if(e.target === sheetBg) closeSheet(); });
      document.addEventListener('keydown', function(e){ if(e.key === 'Escape') closeSheet(); });
      document.body.appendChild(sheetBg);
    }
    sheetBg.innerHTML = '';
    var s = el('div', 'k-sheet'); s.appendChild(el('div', 'k-sheet-grab'));
    build(s); sheetBg.appendChild(s); sheetBg.classList.add('open');
    var f = s.querySelector('button, input, textarea'); if(f) f.focus();
  }
  function closeSheet(){ if(sheetBg) sheetBg.classList.remove('open'); }
  function sheetItem(s, iconName, label, fn, danger){
    var b = el('button', 'k-sheet-item' + (danger ? ' danger' : '')); b.type = 'button';
    b.appendChild(icon(iconName)); b.appendChild(document.createTextNode(label));
    b.addEventListener('click', function(){ closeSheet(); fn(); }); s.appendChild(b);
  }

  /* ---------- The conversation ---------- */
  var dataEl = document.getElementById('k-c-data');
  if(!dataEl) return;
  var D = JSON.parse(dataEl.textContent);
  var list = document.getElementById('k-c-list'), compose = document.getElementById('k-c-compose'), moreBtn = document.getElementById('k-c-more');
  var page = 1;

  function setCount(n){ D.total = n; document.getElementById('k-c-count').textContent = n ? n.toLocaleString() : ''; document.querySelectorAll('[data-comment-count]').forEach(function(e){ e.textContent = n.toLocaleString(); }); }
  function tone(name){ var h = 0; for(var i = 0; i < name.length; i++){ h = (h * 31 + name.charCodeAt(i)) >>> 0; } return 't' + (h % 6); }

  function composer(parentId, onDone){
    var box = el('div', 'k-compose');
    var ta = el('textarea'); ta.rows = parentId ? 2 : 3; ta.maxLength = D.viewer.max_length;
    ta.placeholder = parentId ? 'Write a reply…' : (D.is_pro ? 'Share your thoughts, or ask a question…' : 'Share your thoughts…');
    ta.setAttribute('aria-label', parentId ? 'Your reply' : 'Your comment');
    var foot = el('div', 'k-compose-foot');
    var who = el('div', 'k-compose-who');
    if(D.viewer.identity && D.viewer.identity.mode){
      who.appendChild(document.createTextNode('Posting as ' + D.viewer.identity.name + ' · '));
      var ch = el('button', '', 'change'); ch.type = 'button'; ch.addEventListener('click', function(){ renderIdentity(true); }); who.appendChild(ch);
    }
    var right = el('div', 'k-compose-right');
    var count = el('span', 'k-compose-count');
    var post = el('button', 'k-post-btn', parentId ? 'Reply' : 'Post'); post.type = 'button'; post.disabled = true;
    if(parentId){ var cancel = el('button', 'k-post-btn ghost', 'Cancel'); cancel.type = 'button'; cancel.addEventListener('click', function(){ box.parentNode.remove(); }); right.appendChild(cancel); }
    right.appendChild(count); right.appendChild(post);
    foot.appendChild(who); foot.appendChild(right);
    box.appendChild(ta); box.appendChild(foot);
    ta.addEventListener('input', function(){
      ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 320) + 'px';
      var left = D.viewer.max_length - ta.value.length;
      count.textContent = left < 200 ? left : ''; count.className = 'k-compose-count' + (left < 0 ? ' bad' : '');
      post.disabled = ta.value.trim().length < 2;
    });
    post.addEventListener('click', function(){
      post.disabled = true; post.textContent = 'Posting…';
      call('kounselia_comment_add', { post_id: D.post_id, content: ta.value, parent_id: parentId || 0 }).then(function(d){
        ta.value = ''; ta.style.height = '';
        setCount(d.count);
        onDone(d.comment);
        if(d.safety){ showCare(d.message, d.support_url); }
        else if(d.held){ toast(d.message); }
      }).catch(function(e){
        if(e.data && e.data.code === 'need_identity'){ renderIdentity(true); }
        toast(e.message, true);
      }).finally(function(){ post.textContent = parentId ? 'Reply' : 'Post'; post.disabled = ta.value.trim().length < 2; });
    });
    return { box: box, focus: function(){ ta.focus(); } };
  }

  function showCare(message, url){
    var old = document.getElementById('k-care'); if(old) old.remove();
    var c = el('div', 'k-care'); c.id = 'k-care'; c.setAttribute('role', 'alert');
    c.appendChild(el('b', '', 'You are not alone.'));
    c.appendChild(document.createTextNode(message + ' '));
    var a = el('a', '', 'See where to get help now'); a.href = url; c.appendChild(a);
    compose.parentNode.insertBefore(c, compose);
    c.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function renderIdentity(changing){
    compose.innerHTML = '';
    var id = D.viewer.identity || {};
    var box = el('div', 'k-ident');
    box.appendChild(el('h3', '', changing && id.mode ? 'Change how your name is shown' : 'How should your name appear?'));
    box.appendChild(el('p', '', 'To keep this space safe, we never show your full name, email or photo on comments. Choose what people see.'));
    var l1 = el('label'); var r1 = el('input'); r1.type = 'radio'; r1.name = 'k-ident'; r1.value = 'first_name'; r1.checked = id.mode !== 'nickname';
    l1.appendChild(r1); l1.appendChild(document.createTextNode('My first name: ' + (id.first_name || 'Member')));
    var l2 = el('label'); var r2 = el('input'); r2.type = 'radio'; r2.name = 'k-ident'; r2.value = 'nickname'; r2.checked = id.mode === 'nickname';
    var nick = el('input'); nick.type = 'text'; nick.maxLength = 24; nick.placeholder = 'A nickname'; nick.value = id.nickname || ''; nick.setAttribute('aria-label', 'Nickname');
    nick.addEventListener('focus', function(){ r2.checked = true; });
    l2.appendChild(r2); l2.appendChild(document.createTextNode('A nickname: ')); l2.appendChild(nick);
    var err = el('div', 'k-ident-err');
    var save = el('button', 'k-post-btn', 'Save and continue'); save.type = 'button';
    save.addEventListener('click', function(){
      var mode = r2.checked ? 'nickname' : 'first_name';
      save.disabled = true; err.textContent = '';
      call('kounselia_community_identity', { mode: mode, nickname: nick.value }).then(function(d){
        D.viewer.identity = d; D.viewer.can_comment = true; D.viewer.reason = null; renderCompose();
        toast('Thanks, ' + d.name + '. You are all set.');
      }).catch(function(e){ err.textContent = e.message; }).finally(function(){ save.disabled = false; });
    });
    box.appendChild(l1); box.appendChild(l2); box.appendChild(err); box.appendChild(save);
    if(changing && id.mode){ var c = el('button', 'k-post-btn ghost', 'Cancel'); c.type = 'button'; c.addEventListener('click', renderCompose); box.appendChild(c); }
    compose.appendChild(box);
  }

  function renderCompose(){
    compose.innerHTML = '';
    var v = D.viewer;
    if(!D.enabled){ compose.appendChild(el('div', 'k-closed', 'Comments are closed on this article.')); return; }
    if(!v.signed_in){
      var s = el('div', 'k-signin'); s.appendChild(el('span', '', 'Sign in to join the conversation.'));
      var a = el('a', 'k-btn', 'Sign in'); a.href = D.login; s.appendChild(a); compose.appendChild(s); return;
    }
    if(v.reason === 'need_identity'){ renderIdentity(false); return; }
    if(!v.can_comment){ compose.appendChild(el('div', 'k-closed', v.message || 'You cannot comment here.')); return; }
    var c = composer(0, function(comment){ list.insertBefore(renderComment(comment, false), list.firstChild); });
    compose.appendChild(c.box);
  }

  function renderComment(c, isReply){
    var art = el('article', 'k-c' + (c.status === 'removed' ? ' removed' : '') + (c.held ? ' held' : '')); art.id = 'comment-' + c.id;
    var av = el('div', 'k-c-av ' + (c.author.is_author ? '' : tone(c.author.name)));
    if(c.author.avatar){ var img = el('img'); img.src = c.author.avatar; img.alt = ''; av.appendChild(img); } else { av.textContent = c.author.initial; }
    var main = el('div', 'k-c-main');
    var head = el('div', 'k-c-head');
    var name = el('b', '', c.author.name);
    if(c.author.profile_url){ var nl = el('a'); nl.href = c.author.profile_url; nl.appendChild(name); head.appendChild(nl); } else { head.appendChild(name); }
    if(c.author.is_author){ var badge = el('span', 'k-c-badge'); badge.appendChild(icon('discount-check-filled')); badge.appendChild(document.createTextNode(' Author')); head.appendChild(badge); }
    head.appendChild(el('span', '', '· ' + c.time_label));
    if(c.pinned){ var pin = el('span', 'k-c-pin'); pin.appendChild(icon('pin-filled')); pin.appendChild(document.createTextNode(' Pinned')); head.appendChild(pin); }
    main.appendChild(head);
    main.appendChild(el('div', 'k-c-text', c.content));
    if(c.held){ main.appendChild(el('div', 'k-c-held', 'Only you can see this until it has been reviewed.')); }

    if(c.status !== 'removed' && !c.held){
      var acts = el('div', 'k-c-actions');
      var love = el('button'); love.type = 'button'; love.className = c.loved ? 'on' : ''; love.setAttribute('aria-label', 'Love this comment');
      love.appendChild(icon(c.loved ? 'heart-filled' : 'heart')); var n = el('span', '', c.love_count ? c.love_count : ''); love.appendChild(n);
      love.addEventListener('click', function(){
        if(!D.viewer.signed_in){ location.href = D.login; return; }
        var on = !love.classList.contains('on');
        call('kounselia_comment_love', { comment_id: c.id, love: on ? 1 : 0 }).then(function(d){
          love.classList.toggle('on', d.loved); love.querySelector('i').className = 'ti ti-heart' + (d.loved ? '-filled' : ''); n.textContent = d.count ? d.count : '';
        }).catch(function(e){ toast(e.message, true); });
      });
      acts.appendChild(love);
      if(D.enabled){
        var rep = el('button'); rep.type = 'button'; rep.appendChild(icon('arrow-back-up')); rep.appendChild(document.createTextNode('Reply'));
        rep.addEventListener('click', function(){
          if(!D.viewer.signed_in){ location.href = D.login; return; }
          if(!D.viewer.can_comment){ if(D.viewer.reason === 'need_identity'){ renderIdentity(false); compose.scrollIntoView({ behavior: 'smooth' }); } else { toast(D.viewer.message || 'You cannot comment here.', true); } return; }
          var root = isReply ? art.parentNode.closest('.k-c') : art;
          var holder = root.querySelector(':scope > .k-c-main > .k-c-reply');
          if(holder){ holder.querySelector('textarea').focus(); return; }
          holder = el('div', 'k-c-reply');
          var parentId = isReply ? parseInt(root.id.replace('comment-', ''), 10) : c.id;
          var cc = composer(parentId, function(reply){ root.querySelector(':scope > .k-c-main > .k-c-replies').appendChild(renderComment(reply, true)); holder.remove(); });
          holder.appendChild(cc.box); root.querySelector(':scope > .k-c-main').appendChild(holder); cc.focus();
        });
        acts.appendChild(rep);
      }
      var more = el('button'); more.type = 'button'; more.setAttribute('aria-label', 'More options'); more.appendChild(icon('dots'));
      more.addEventListener('click', function(){ menu(c, art, isReply); });
      acts.appendChild(more);
      main.appendChild(acts);
    } else if(c.is_mine){
      var a2 = el('div', 'k-c-actions'); var del = el('button'); del.type = 'button'; del.appendChild(icon('trash')); del.appendChild(document.createTextNode('Delete'));
      del.addEventListener('click', function(){ removeOwn(c, art); }); a2.appendChild(del); main.appendChild(a2);
    }
    if(!isReply){
      var replies = el('div', 'k-c-replies');
      (c.replies || []).forEach(function(r){ replies.appendChild(renderComment(r, true)); });
      main.appendChild(replies);
    }
    art.appendChild(av); art.appendChild(main);
    return art;
  }

  function removeOwn(c, art){
    if(!confirm('Delete your comment?')) return;
    call('kounselia_comment_delete', { comment_id: c.id }).then(function(){ art.remove(); setCount(Math.max(0, D.total - 1)); toast('Comment deleted.'); })
      .catch(function(e){ toast(e.message, true); });
  }

  function moderate(c, act, art){
    call('kounselia_comment_moderate', { comment_id: c.id, act: act }).then(function(d){
      toast(d.message);
      if(act === 'hide'){ art.remove(); setCount(Math.max(0, D.total - 1)); }
      else { reload(); }
    }).catch(function(e){ toast(e.message, true); });
  }

  function menu(c, art, isReply){
    sheet(function(s){
      if(c.is_mine){ sheetItem(s, 'trash', 'Delete my comment', function(){ removeOwn(c, art); }, true); }
      if(D.viewer.can_moderate){
        if(!isReply){ sheetItem(s, c.pinned ? 'pinned-off' : 'pin', c.pinned ? 'Unpin' : 'Pin to the top', function(){ moderate(c, c.pinned ? 'unpin' : 'pin', art); }); }
        if(!c.is_mine){ sheetItem(s, 'eye-off', 'Hide this comment', function(){ if(confirm('Hide this comment from everyone?')) moderate(c, 'hide', art); }); }
      }
      if(!c.is_mine){ sheetItem(s, 'flag', 'Report', function(){ report(c); }); }
      sheetItem(s, 'link', 'Copy link', function(){
        var url = location.origin + location.pathname + '#comment-' + c.id;
        if(navigator.clipboard){ navigator.clipboard.writeText(url).then(function(){ toast('Link copied.'); }); } else { prompt('Copy this link', url); }
      });
    });
  }

  function report(c){
    if(!D.viewer.signed_in){ location.href = D.login; return; }
    sheet(function(s){
      s.appendChild(el('h3', '', 'What is wrong with this comment?'));
      var first = true;
      Object.keys(D.reasons).forEach(function(k){
        var l = el('label', 'k-sheet-item'); var r = el('input'); r.type = 'radio'; r.name = 'k-reason'; r.value = k; r.checked = first; first = false;
        l.appendChild(r); l.appendChild(document.createTextNode(D.reasons[k])); s.appendChild(l);
      });
      var note = el('textarea'); note.placeholder = 'Anything else we should know? (optional)'; note.maxLength = 500; s.appendChild(note);
      var row = el('div', 'k-sheet-actions');
      var cancel = el('button', 'k-post-btn ghost', 'Cancel'); cancel.type = 'button'; cancel.addEventListener('click', closeSheet);
      var send = el('button', 'k-post-btn', 'Send report'); send.type = 'button';
      send.addEventListener('click', function(){
        var reason = s.querySelector('input[name=k-reason]:checked').value;
        call('kounselia_comment_report', { comment_id: c.id, reason: reason, note: note.value }).then(function(d){ closeSheet(); toast(d.message); })
          .catch(function(e){ toast(e.message, true); });
      });
      row.appendChild(cancel); row.appendChild(send); s.appendChild(row);
    });
  }

  function paint(comments, append){
    if(!append) list.innerHTML = '';
    comments.forEach(function(c){ list.appendChild(renderComment(c, false)); });
    moreBtn.hidden = !D.has_more;
  }
  function reload(){
    page = 1;
    call('kounselia_comments', { post_id: D.post_id, page: 1 }).then(function(d){ D.has_more = d.has_more; D.viewer = d.viewer; paint(d.comments, false); });
  }
  moreBtn.addEventListener('click', function(){
    moreBtn.disabled = true;
    call('kounselia_comments', { post_id: D.post_id, page: page + 1 }).then(function(d){ page++; D.has_more = d.has_more; paint(d.comments, true); })
      .catch(function(e){ toast(e.message, true); }).finally(function(){ moreBtn.disabled = false; });
  });

  renderCompose();
  paint(D.comments, false);
  if(location.hash && location.hash.indexOf('#comment') === 0){
    var target = document.getElementById(location.hash.slice(1));
    if(target){ setTimeout(function(){ target.scrollIntoView({ block: 'center' }); target.style.transition = 'background .6s'; target.style.background = 'var(--gold-light)'; setTimeout(function(){ target.style.background = ''; }, 1800); }, 200); }
  }
})();
</script>
    <?php
}

<?php
/**
 * Kounselia Admin — shared UI for the content screens (Pages, Blog,
 * Newsletter): form styles, the two-column editor layout, tabs, toasts,
 * a tiny AJAX helper, image pickers, and the rich text editor.
 *
 * Require it inside <head>, after admin-styles.php. It defines:
 *   KAdmin.post(action, data)      -> Promise of the JSON "data" (throws on error)
 *   KAdmin.toast(msg, isError)
 *   KAdmin.upload(file)            -> Promise of the uploaded image URL
 *   KAdmin.editor(selector, opts)  -> Promise of the TinyMCE editor
 *   <div class="img-field" data-input="#id"> image picker (auto-wired)
 *
 * The rich editor is TinyMCE 6 (MIT licensed, no API key), loaded from
 * jsDelivr. Its "Styles" menu offers Kounselia's own content classes
 * (lead paragraph, callout box, button link) which the public site and
 * the email template both know how to display.
 */
$kounselia_cms_ajax  = set_url_scheme( admin_url( 'admin-ajax.php' ), is_ssl() ? 'https' : 'http' );
$kounselia_cms_nonce = wp_create_nonce( 'kounselia_admin_nonce' );
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.44.0/tabler-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/tinymce@6.8.5/tinymce.min.js" referrerpolicy="origin"></script>
<style>
.cms-head{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:18px}
.cms-head .admin-subtitle{margin-bottom:0}
.cms-tabs{display:flex;gap:4px;border-bottom:1px solid var(--border);margin-bottom:22px;overflow-x:auto}
.cms-tabs a{padding:10px 16px;font-size:13.5px;color:var(--text2);border-bottom:2px solid transparent;margin-bottom:-1px;white-space:nowrap;display:inline-flex;align-items:center;gap:6px}
.cms-tabs a:hover{color:var(--accent)}
.cms-tabs a.active{color:var(--accent);border-bottom-color:var(--accent);font-weight:500}
.cms-tabs .count{font-size:11px;background:var(--surface2);border-radius:20px;padding:1px 7px;color:var(--text3)}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:10px 18px;border-radius:var(--r-full);border:1px solid transparent;font-family:inherit;font-size:13.5px;font-weight:500;cursor:pointer;transition:all .15s var(--ease);text-decoration:none;white-space:nowrap;line-height:1.2}
.btn i{font-size:16px}
.btn-primary{background:var(--accent);color:#fff}
.btn-primary:hover{background:var(--accent2);color:#fff}
.btn-gold{background:var(--gold);color:#fff}
.btn-gold:hover{filter:brightness(1.06);color:#fff}
.btn-light{background:var(--surface);border-color:var(--border);color:var(--text)}
.btn-light:hover{border-color:var(--accent);color:var(--accent)}
.btn-danger{background:var(--surface);border-color:var(--border);color:var(--rose)}
.btn-danger:hover{background:var(--rose-light);border-color:var(--rose)}
.btn-sm{padding:6px 12px;font-size:12.5px}
.btn:disabled{opacity:.55;cursor:not-allowed}
.btn-row{display:flex;gap:8px;flex-wrap:wrap}

.field{margin-bottom:16px}
.field > label,.field-label{display:block;font-size:12.5px;font-weight:500;color:var(--text2);margin-bottom:6px}
.field input[type=text],.field input[type=email],.field input[type=url],.field input[type=number],.field input[type=datetime-local],.field input[type=search],.field select,.field textarea{
  width:100%;padding:10px 13px;border:1px solid var(--border);border-radius:var(--r-sm);font-family:inherit;font-size:14px;color:var(--text);background:var(--surface);transition:border-color .15s,box-shadow .15s}
.field textarea{resize:vertical;min-height:80px;line-height:1.55}
.field input:focus,.field select:focus,.field textarea:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-light)}
.hint{font-size:11.5px;color:var(--text3);margin-top:5px;line-height:1.45}
.hint code{background:var(--surface2);padding:1px 5px;border-radius:4px;font-size:11px}
.field-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media (max-width:640px){ .field-row{grid-template-columns:1fr} }
.check{display:flex;gap:10px;align-items:flex-start;font-size:13.5px;color:var(--text);cursor:pointer;margin-bottom:10px;line-height:1.4}
.check input{width:16px;height:16px;accent-color:var(--accent);margin-top:2px;flex-shrink:0}
.check small{display:block;color:var(--text3);font-size:12px;margin-top:2px}

.title-input{width:100%;border:none;background:transparent;font-family:'Cormorant Garamond',serif;font-size:38px;font-weight:500;color:var(--text);padding:4px 0;outline:none;line-height:1.15}
.title-input::placeholder{color:#CFCABE}
.subtitle-input{width:100%;border:none;background:transparent;font-size:17px;color:var(--text2);padding:4px 0 14px;outline:none;font-family:inherit;font-weight:300}
.subtitle-input::placeholder{color:#CFCABE}
@media (max-width:640px){ .title-input{font-size:28px} }

.editor-layout{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:24px;align-items:start}
@media (max-width:1000px){ .editor-layout{grid-template-columns:1fr} }
.editor-main{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:22px 26px 26px;box-shadow:var(--sh-sm);min-width:0}
@media (max-width:640px){ .editor-main{padding:16px} }
.editor-side{display:flex;flex-direction:column;gap:16px;position:sticky;top:76px}
@media (max-width:1000px){ .editor-side{position:static} }
.side-box{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:18px;box-shadow:var(--sh-sm)}
.side-box h3{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:var(--text3);font-weight:600;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center}
.side-box .field:last-child{margin-bottom:0}
.seg{display:flex;background:var(--surface2);border-radius:var(--r-full);padding:3px;gap:2px;margin-bottom:12px}
.seg label{flex:1;text-align:center;font-size:12.5px;padding:7px 6px;border-radius:var(--r-full);cursor:pointer;color:var(--text2);transition:all .15s}
.seg input{display:none}
.seg label:has(input:checked){background:var(--surface);color:var(--accent);font-weight:500;box-shadow:var(--sh-sm)}
.save-state{font-size:12px;color:var(--text3);margin-top:10px;min-height:16px}

.tox-tinymce{border-radius:var(--r-md)!important;border-color:var(--border)!important}
.editor-fallback{width:100%;min-height:420px;padding:14px;border:1px solid var(--border);border-radius:var(--r-md);font-family:ui-monospace,monospace;font-size:13px}

.img-field{border:1.5px dashed var(--border);border-radius:var(--r-md);background:var(--bg);position:relative;overflow:hidden;min-height:120px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:border-color .15s}
.img-field:hover{border-color:var(--accent)}
.img-field img{width:100%;height:160px;object-fit:cover;display:block}
.img-field .ph{text-align:center;color:var(--text3);font-size:12.5px;padding:16px}
.img-field .ph i{font-size:26px;display:block;margin-bottom:6px;color:var(--gold)}
.img-field .rm{position:absolute;top:8px;right:8px;background:rgba(24,22,15,.7);color:#fff;border:none;border-radius:50%;width:28px;height:28px;cursor:pointer;display:none;align-items:center;justify-content:center}
.img-field.has-img .rm{display:flex}
.img-field.busy::after{content:'Uploading…';position:absolute;inset:0;background:rgba(255,255,255,.85);display:flex;align-items:center;justify-content:center;font-size:13px;color:var(--accent)}

.pill{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;padding:3px 10px;border-radius:var(--r-full);white-space:nowrap}
.pill.green{background:var(--sage-light);color:var(--sage)}
.pill.grey{background:var(--surface2);color:var(--text3)}
.pill.gold{background:var(--gold-light);color:var(--gold)}
.pill.blue{background:var(--accent-light);color:var(--accent)}
.pill.rose{background:var(--rose-light);color:var(--rose)}
.pill.plum{background:var(--plum-light);color:var(--plum)}

.row-title{font-weight:500;color:var(--text);font-size:14px}
.row-sub{font-size:12px;color:var(--text3);margin-top:2px;word-break:break-all}
.row-actions{display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap}
.thumb{width:52px;height:38px;border-radius:8px;object-fit:cover;background:linear-gradient(135deg,var(--accent-light),var(--gold-light));flex-shrink:0;display:block}

#k-toasts{position:fixed;bottom:22px;right:22px;z-index:200;display:flex;flex-direction:column;gap:8px;max-width:calc(100vw - 44px)}
.k-toast{background:var(--text);color:#fff;padding:12px 18px;border-radius:var(--r-md);font-size:13.5px;box-shadow:var(--sh-lg);animation:kToastIn .25s var(--ease)}
.k-toast.error{background:var(--rose)}
@keyframes kToastIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}

.modal-bg{position:fixed;inset:0;background:rgba(24,22,15,.45);z-index:150;display:none;align-items:flex-start;justify-content:center;padding:6vh 16px;overflow-y:auto}
.modal-bg.open{display:flex}
.modal{background:var(--surface);border-radius:var(--r-lg);padding:24px;width:100%;max-width:520px;box-shadow:var(--sh-lg)}
.modal h2{font-family:'Cormorant Garamond',serif;font-size:26px;font-weight:500;color:var(--accent);margin-bottom:16px}
.modal .btn-row{justify-content:flex-end;margin-top:8px}
</style>
<script>
window.KAdmin = (function(){
  var AJAX = <?php echo wp_json_encode( $kounselia_cms_ajax ); ?>;
  var NONCE = <?php echo wp_json_encode( $kounselia_cms_nonce ); ?>;

  function toast(msg, isError){
    var box = document.getElementById('k-toasts');
    if(!box){ box = document.createElement('div'); box.id = 'k-toasts'; document.body.appendChild(box); }
    var t = document.createElement('div');
    t.className = 'k-toast' + (isError ? ' error' : '');
    t.textContent = msg;
    box.appendChild(t);
    setTimeout(function(){ t.style.transition = 'opacity .3s'; t.style.opacity = '0'; setTimeout(function(){ t.remove(); }, 300); }, isError ? 6000 : 3800);
  }

  function post(action, data){
    var body = data instanceof FormData ? data : new URLSearchParams();
    body.append('action', action);
    body.append('nonce', NONCE);
    if(!(data instanceof FormData)){
      Object.keys(data || {}).forEach(function(k){ body.append(k, data[k] == null ? '' : data[k]); });
    }
    return fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function(r){ return r.json().catch(function(){ throw new Error('The server returned an unexpected response (' + r.status + ').'); }); })
      .then(function(res){
        if(!res || !res.success){ throw new Error((res && res.data && res.data.message) || 'Something went wrong.'); }
        return res.data || {};
      });
  }

  function upload(file){
    var fd = new FormData();
    fd.append('file', file);
    return post('kounselia_admin_upload_media', fd).then(function(d){ return d.url; });
  }

  function wireImageField(box){
    if(box.dataset.wired) return;
    box.dataset.wired = '1';
    var input = document.querySelector(box.dataset.input);
    var file = document.createElement('input');
    file.type = 'file'; file.accept = 'image/jpeg,image/png,image/webp,image/gif'; file.style.display = 'none';
    box.appendChild(file);
    function render(){
      var url = input.value;
      box.classList.toggle('has-img', !!url);
      var old = box.querySelector('img, .ph'); if(old) old.remove();
      var el;
      if(url){ el = document.createElement('img'); el.src = url; el.alt = ''; }
      else { el = document.createElement('div'); el.className = 'ph'; el.innerHTML = '<i class="ti ti-photo-up"></i>Click to upload an image<br><small>JPG, PNG or WebP, up to 8MB</small>'; }
      box.insertBefore(el, box.firstChild);
    }
    var rm = document.createElement('button');
    rm.type = 'button'; rm.className = 'rm'; rm.innerHTML = '&times;'; rm.title = 'Remove image';
    rm.addEventListener('click', function(e){ e.stopPropagation(); input.value = ''; render(); input.dispatchEvent(new Event('change')); });
    box.appendChild(rm);
    box.addEventListener('click', function(){ file.click(); });
    file.addEventListener('change', function(){
      if(!file.files[0]) return;
      box.classList.add('busy');
      upload(file.files[0]).then(function(url){ input.value = url; render(); input.dispatchEvent(new Event('change')); })
        .catch(function(err){ toast(err.message, true); })
        .finally(function(){ box.classList.remove('busy'); file.value = ''; });
    });
    render();
  }

  var CONTENT_CSS = "body{font-family:'Outfit',-apple-system,sans-serif;font-size:17px;line-height:1.75;color:#3a372f;max-width:720px;margin:18px auto;padding:0 12px}"
    + "h2{font-family:'Cormorant Garamond',Georgia,serif;font-weight:500;font-size:32px;color:#1E3A5F;margin:1.4em 0 .4em;line-height:1.2}"
    + "h3{font-size:19px;font-weight:600;color:#18160F;margin:1.3em 0 .4em}h4{font-size:16px;font-weight:600}"
    + "a{color:#8B3A52}blockquote{margin:1.5em 0;padding-left:22px;border-left:3px solid #B07D3A;font-family:'Cormorant Garamond',Georgia,serif;font-size:26px;font-style:italic;color:#1E3A5F}"
    + "img{max-width:100%;height:auto;border-radius:14px}ul li::marker{color:#B07D3A}"
    + ".k-lead{font-size:20px;color:#18160F}.k-callout{background:#FBF5EA;border:1px solid #efe0c6;border-radius:16px;padding:16px 20px;margin:1.4em 0}"
    + ".k-btn{display:inline-block;background:#1E3A5F;color:#fff!important;padding:12px 26px;border-radius:50px;text-decoration:none;font-weight:500}"
    + "p.k-token,p:has(> .k-tok){background:#EEE9F8;color:#4A3070;border-radius:10px;padding:10px 14px;font-size:13px;font-family:monospace}"
    + "table{border-collapse:collapse;width:100%}td,th{border:1px solid #E8E4DB;padding:8px}iframe{max-width:100%}";

  function editor(selector, opts){
    opts = opts || {};
    var el = document.querySelector(selector);
    if(!window.tinymce){
      // CDN blocked or offline: fall back to a plain HTML box so work is never lost.
      el.classList.add('editor-fallback');
      toast('The rich editor could not load, so you are editing raw HTML. Check your connection and reload.', true);
      return Promise.resolve({ getContent: function(){ return el.value; }, setContent: function(v){ el.value = v; }, isDirty: function(){ return false; }, on: function(){} });
    }
    var blocks = opts.blocks === false ? [] : [
      { text: 'Our counselors (live cards)', value: 'kounselia_counselors' },
      { text: 'Plans & prices (live)', value: 'kounselia_plans' },
      { text: 'Newsletter sign-up box', value: 'kounselia_newsletter' },
      { text: 'Latest 3 blog posts', value: 'kounselia_latest_posts' },
      { text: '"Create free account" button', value: 'kounselia_signup_button' }
    ];
    return tinymce.init({
      selector: selector,
      height: opts.height || 620,
      menubar: false,
      promotion: false,
      branding: false,
      browser_spellcheck: true,
      contextmenu: false,
      convert_urls: true,
      relative_urls: false,
      remove_script_host: false,
      image_caption: true,
      image_advtab: false,
      automatic_uploads: true,
      paste_data_images: true,
      link_default_target: '',
      link_assume_external_targets: 'https',
      plugins: 'autolink link lists image media table code fullscreen wordcount quickbars autoresize charmap',
      autoresize_bottom_margin: 40,
      min_height: 460,
      max_height: opts.maxHeight || 1400,
      toolbar_sticky: true,
      toolbar_sticky_offset: 64,
      toolbar: 'blocks styles | bold italic underline | link image media' + (blocks.length ? ' kblocks' : '') + ' | bullist numlist blockquote | alignleft aligncenter | table hr | removeformat code fullscreen',
      toolbar_mode: 'wrap',
      quickbars_selection_toolbar: 'bold italic | h2 h3 blockquote | quicklink',
      quickbars_insert_toolbar: false,
      block_formats: 'Paragraph=p; Heading=h2; Subheading=h3; Small heading=h4',
      style_formats: [
        { title: 'Lead paragraph (larger intro)', selector: 'p', classes: 'k-lead' },
        { title: 'Callout box (highlighted note)', block: 'div', classes: 'k-callout', wrapper: true },
        { title: 'Button (select a link first)', selector: 'a', classes: 'k-btn' },
        { title: 'Centered paragraph', selector: 'p', classes: 'k-center' }
      ],
      table_default_attributes: {},
      content_style: CONTENT_CSS,
      images_upload_handler: function(blobInfo){
        return upload(blobInfo.blob());
      },
      file_picker_types: 'image',
      file_picker_callback: function(cb){
        var f = document.createElement('input');
        f.type = 'file'; f.accept = 'image/*';
        f.onchange = function(){ if(f.files[0]) upload(f.files[0]).then(function(url){ cb(url, { alt: '' }); }).catch(function(e){ toast(e.message, true); }); };
        f.click();
      },
      setup: function(ed){
        if(blocks.length){
          ed.ui.registry.addMenuButton('kblocks', {
            text: 'Insert block',
            icon: 'plus',
            fetch: function(cb){
              cb(blocks.map(function(b){
                return { type: 'menuitem', text: b.text, onAction: function(){ ed.insertContent('<p>[' + b.value + ']</p>'); } };
              }));
            }
          });
        }
        if(opts.onChange){ ed.on('input change undo redo', opts.onChange); }
      }
    }).then(function(eds){ return eds[0]; });
  }

  document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('.img-field[data-input]').forEach(wireImageField);
  });

  function esc(s){ var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

  return { post: post, toast: toast, upload: upload, editor: editor, wireImageField: wireImageField, esc: esc, ajaxUrl: AJAX, nonce: NONCE };
})();
</script>

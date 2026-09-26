<?php
/**
 * Kounselia Admin — shared bits for the Newsletter screens: the
 * sub-navigation, and the audience builder (the "who receives this"
 * rule form with a live count), used by Compose and Segments.
 *
 * Require inside <body> after admin-cms.php. Set $kounselia_nl_tab to
 * 'campaigns' | 'contacts' | 'segments' | 'settings' first.
 */
$kounselia_nl_tab = isset( $kounselia_nl_tab ) ? $kounselia_nl_tab : 'campaigns';
global $wpdb;
$kounselia_nl_counts = $wpdb->get_row( "SELECT COUNT(*) AS total, SUM(status = 'subscribed') AS active FROM {$wpdb->prefix}kounselia_subscribers" );
$kounselia_nl_seg_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}kounselia_segments" );
?>
<style>
.aud{display:flex;flex-direction:column;gap:10px}
.aud-row{display:grid;grid-template-columns:1fr 90px;gap:6px}
.aud select,.aud input{width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:var(--r-sm);font-family:inherit;font-size:13px;background:var(--surface);color:var(--text)}
.aud label{font-size:12px;font-weight:500;color:var(--text2);display:block;margin-bottom:4px}
.aud-custom{display:flex;flex-direction:column;gap:10px}
.aud-count{background:var(--accent-light);border-radius:var(--r-md);padding:12px 14px}
.aud-count .n{font-size:22px;font-weight:600;color:var(--accent);font-variant-numeric:tabular-nums}
.aud-count .d{font-size:12px;color:var(--text2);margin-top:2px;line-height:1.45}
.aud-count .s{font-size:11.5px;color:var(--text3);margin-top:6px;line-height:1.5;word-break:break-all}
.aud-more{font-size:12.5px;background:none;border:none;color:var(--rose);cursor:pointer;padding:0;text-align:left;font-family:inherit}
</style>

<div class="cms-head">
  <div>
    <h1 class="admin-title">Newsletter</h1>
    <div class="admin-subtitle"><?php echo number_format_i18n( (int) $kounselia_nl_counts->active ); ?> subscribed contacts · members and website sign-ups in one list, with unsubscribes always respected.</div>
  </div>
  <a class="btn btn-primary" href="/portal/admin/pages/newsletter.php?compose=new"><i class="ti ti-mail-plus"></i> New campaign</a>
</div>
<div class="cms-tabs">
  <a href="/portal/admin/pages/newsletter.php" class="<?php echo 'campaigns' === $kounselia_nl_tab ? 'active' : ''; ?>"><i class="ti ti-send"></i> Campaigns</a>
  <a href="/portal/admin/pages/newsletter-contacts.php" class="<?php echo 'contacts' === $kounselia_nl_tab ? 'active' : ''; ?>"><i class="ti ti-address-book"></i> Contacts <span class="count"><?php echo number_format_i18n( (int) $kounselia_nl_counts->total ); ?></span></a>
  <a href="/portal/admin/pages/newsletter-segments.php" class="<?php echo 'segments' === $kounselia_nl_tab ? 'active' : ''; ?>"><i class="ti ti-filter"></i> Segments <span class="count"><?php echo $kounselia_nl_seg_count; ?></span></a>
  <a href="/portal/admin/pages/newsletter-settings.php" class="<?php echo 'settings' === $kounselia_nl_tab ? 'active' : ''; ?>"><i class="ti ti-settings"></i> Settings</a>
</div>

<script>
/**
 * KAudience.mount(el, { rules, segmentId, listKey, segments, allowSegments })
 * -> { rules(), segmentId(), setList(key) }
 */
window.KAudience = (function(){
  var AUDIENCES = [
    ['all', 'Everyone subscribed'], ['members', 'Members (have an account)'], ['non_members', 'Website subscribers (no account)'],
    ['pro_members', 'Pro members'], ['free_members', 'Free members'], ['professionals', 'Verified professionals']
  ];
  var SOURCES = [['', 'Any'], ['member', 'Signed up for an account'], ['footer', 'Footer form'], ['blog', 'Blog sidebar'], ['post', 'End of a blog post'], ['page', 'A page'], ['import', 'CSV import'], ['admin', 'Added by staff']];
  function opts(list, val){ return list.map(function(o){ return '<option value="' + o[0] + '"' + (String(o[0]) === String(val) ? ' selected' : '') + '>' + KAdmin.esc(o[1]) + '</option>'; }).join(''); }

  function mount(el, o){
    o = o || {};
    var r = Object.assign({ audience: 'all', subscribed_within: 0, subscribed_before: 0, active_within: 0, inactive_for: 0, has_booking: 'any', tags_any: [], tags_none: [], source: '', email_domain: '' }, o.rules || {});
    var listKey = o.listKey || 'newsletter';
    var joined = r.subscribed_within ? 'within' : (r.subscribed_before ? 'before' : '');
    var active = r.active_within ? 'within' : (r.inactive_for ? 'inactive' : '');
    var advanced = !!(joined || active || r.has_booking !== 'any' || (r.tags_any || []).length || (r.tags_none || []).length || r.source || r.email_domain);

    var h = '';
    if(o.allowSegments){
      h += '<div><label>Send to</label><select data-f="segment"><option value="0">A custom audience…</option>'
        + (o.segments || []).map(function(s){ return '<option value="' + s.id + '"' + (+o.segmentId === +s.id ? ' selected' : '') + '>Saved segment: ' + KAdmin.esc(s.name) + '</option>'; }).join('') + '</select></div>';
    }
    h += '<div class="aud-custom">'
      + '<div><label>Who</label><select data-f="audience">' + opts(AUDIENCES, r.audience) + '</select></div>'
      + '<button type="button" class="aud-more" data-f="more">' + (advanced ? 'Hide filters' : '+ Narrow it down (join date, activity, tags…)') + '</button>'
      + '<div data-f="adv" style="display:' + (advanced ? 'flex' : 'none') + ';flex-direction:column;gap:10px">'
      +   '<div><label>Joined</label><div class="aud-row"><select data-f="joined">' + opts([['', 'Any time'], ['within', 'In the last … days'], ['before', 'More than … days ago']], joined) + '</select><input type="number" min="1" data-f="joined_days" value="' + (r.subscribed_within || r.subscribed_before || 30) + '"></div></div>'
      +   '<div><label>Chat activity (members)</label><div class="aud-row"><select data-f="active">' + opts([['', 'Any'], ['within', 'Chatted in the last … days'], ['inactive', 'No chat for … days or more']], active) + '</select><input type="number" min="1" data-f="active_days" value="' + (r.active_within || r.inactive_for || 30) + '"></div></div>'
      +   '<div><label>Booked a professional</label><select data-f="has_booking">' + opts([['any', 'Doesn’t matter'], ['yes', 'Yes, at least once'], ['no', 'Never']], r.has_booking) + '</select></div>'
      +   '<div><label>Has any of these tags</label><input data-f="tags_any" placeholder="e.g. vip, lagos" value="' + KAdmin.esc((r.tags_any || []).join(', ')) + '"></div>'
      +   '<div><label>Leave out anyone tagged</label><input data-f="tags_none" placeholder="e.g. no-promos" value="' + KAdmin.esc((r.tags_none || []).join(', ')) + '"></div>'
      +   '<div><label>Where they signed up</label><select data-f="source">' + opts(SOURCES, r.source) + '</select></div>'
      +   '<div><label>Email address ends with</label><input data-f="email_domain" placeholder="e.g. company.com" value="' + KAdmin.esc(r.email_domain) + '"></div>'
      + '</div></div>'
      + '<div class="aud-count"><div class="n" data-f="n">…</div><div class="d" data-f="d"></div><div class="s" data-f="s"></div></div>';
    el.classList.add('aud');
    el.innerHTML = h;
    var q = function(f){ return el.querySelector('[data-f="' + f + '"]'); };

    function rules(){
      var jd = +q('joined_days').value || 0, ad = +q('active_days').value || 0;
      var tags = function(v){ return v.split(',').map(function(s){ return s.trim(); }).filter(Boolean); };
      return {
        audience: q('audience').value,
        subscribed_within: q('joined').value === 'within' ? jd : 0,
        subscribed_before: q('joined').value === 'before' ? jd : 0,
        active_within: q('active').value === 'within' ? ad : 0,
        inactive_for: q('active').value === 'inactive' ? ad : 0,
        has_booking: q('has_booking').value,
        tags_any: tags(q('tags_any').value), tags_none: tags(q('tags_none').value),
        source: q('source').value, email_domain: q('email_domain').value.trim()
      };
    }
    function segmentId(){ return q('segment') ? +q('segment').value : 0; }
    function syncSeg(){ el.querySelector('.aud-custom').style.display = segmentId() ? 'none' : 'flex'; }

    var timer;
    function count(){
      clearTimeout(timer);
      timer = setTimeout(function(){
        q('n').textContent = '…';
        KAdmin.post('kounselia_admin_nl_count', { rules: JSON.stringify(rules()), segment_id: segmentId(), list_key: listKey })
          .then(function(d){
            q('n').textContent = d.count.toLocaleString() + (d.count === 1 ? ' person' : ' people');
            q('d').textContent = d.description + ' · on the ' + (listKey === 'blog' ? 'blog emails' : 'newsletter') + ' list';
            q('s').textContent = d.sample.length ? 'e.g. ' + d.sample.join(', ') : '';
          })
          .catch(function(e){ q('n').textContent = '—'; q('d').textContent = e.message; });
      }, 300);
    }
    el.addEventListener('input', count);
    el.addEventListener('change', function(){ syncSeg(); count(); });
    q('more').addEventListener('click', function(){
      var adv = q('adv'), open = adv.style.display === 'none';
      adv.style.display = open ? 'flex' : 'none';
      q('more').textContent = open ? 'Hide filters' : '+ Narrow it down (join date, activity, tags…)';
    });
    syncSeg(); count();
    return { rules: rules, segmentId: segmentId, setList: function(k){ listKey = k; count(); } };
  }
  return { mount: mount };
})();
</script>

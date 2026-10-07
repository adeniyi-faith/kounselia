<?php
/**
 * Member dashboard: "Growth plan" tab (Personal Development).
 *
 * Shows today's task and progress for the member's running 30 day plan,
 * or lets them pick an area, answer a few questions and get a plan. All
 * data comes from the kounselia_growth_* actions (includes/growth-plans.php),
 * the same ones the mobile app uses.
 *
 * Expects from dashboard.php: $user.
 */
if ( ! function_exists( 'kounselia_growth_overview' ) ) {
    return;
}
$gp_lang = function_exists( 'kounselia_current_language' ) ? kounselia_current_language( $user->ID ) : 'en';
$gp_dict = function_exists( 'kounselia_i18n_subset' ) ? kounselia_i18n_subset( $gp_lang, 'growth.' ) : array();
?>
<style>
.gp-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r,18px);padding:22px;margin-bottom:16px}
.gp-areas{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px}
.gp-area{display:flex;flex-direction:column;gap:6px;text-align:left;padding:16px;border:1.5px solid var(--border);border-radius:16px;background:var(--surface);cursor:pointer;font-family:inherit;color:var(--text);transition:all .2s ease}
.gp-area:hover{border-color:var(--accent);background:var(--accent-light)}
.gp-area i{font-size:22px;color:var(--accent)}
.gp-area b{font-size:15px;font-weight:600}
.gp-area span{font-size:13px;color:var(--text2);line-height:1.5}
.gp-q{margin-bottom:18px}
.gp-q label{display:block;font-size:14.5px;font-weight:500;margin-bottom:4px}
.gp-q small{display:block;font-size:12.5px;color:var(--text3);margin-bottom:8px}
.gp-q textarea{width:100%;min-height:70px;border:1.5px solid var(--border);border-radius:12px;padding:12px 14px;font-family:inherit;font-size:14.5px;background:var(--bg);color:var(--text);resize:vertical;outline:none}
.gp-q textarea:focus{border-color:var(--accent)}
.gp-choices{display:flex;flex-wrap:wrap;gap:8px}
.gp-choice{padding:10px 16px;border:1.5px solid var(--border);border-radius:50px;background:var(--surface);cursor:pointer;font-family:inherit;font-size:14px;color:var(--text)}
.gp-choice.on{border-color:var(--accent);background:var(--accent-light);color:var(--accent);font-weight:500}
.gp-bar{height:10px;border-radius:50px;background:var(--surface2);overflow:hidden;margin:12px 0 6px}
.gp-bar>div{height:100%;background:linear-gradient(90deg,var(--accent),var(--accent2));border-radius:50px;transition:width .4s ease}
.gp-meta{display:flex;justify-content:space-between;font-size:13px;color:var(--text2);flex-wrap:wrap;gap:6px}
.gp-today h3{font-family:'Cormorant Garamond',serif;font-size:26px;font-weight:500;margin:4px 0 6px}
.gp-today p{font-size:15px;line-height:1.65;color:var(--text2);margin-bottom:16px}
.gp-eyebrow{font-size:11.5px;font-weight:600;letter-spacing:.6px;text-transform:uppercase;color:var(--text3)}
.gp-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.gp-days{display:grid;grid-template-columns:repeat(auto-fill,minmax(44px,1fr));gap:8px;margin-top:12px}
.gp-day{height:44px;border-radius:12px;border:1.5px solid var(--border);background:var(--surface);font-family:inherit;font-size:13px;color:var(--text2);cursor:pointer}
.gp-day.done{background:var(--sage-light);border-color:var(--sage);color:var(--sage);font-weight:600}
.gp-day.today{border-color:var(--accent);color:var(--accent);font-weight:600}
.gp-day.upcoming{opacity:.5;cursor:default}
.gp-day-detail{margin-top:14px;padding:14px 16px;border-radius:14px;background:var(--surface2);font-size:14px;line-height:1.6;color:var(--text2)}
.gp-note{font-size:13px;color:var(--text3);margin-top:10px;line-height:1.5}
.gp-prev{display:flex;justify-content:space-between;gap:10px;padding:12px 0;border-top:1px solid var(--border);font-size:14px}
.gp-prev:first-of-type{border-top:none}
.gp-review{background:var(--accent-light);border:1px solid #C8D8EC}
.gp-review p{font-size:14.5px;line-height:1.65;color:var(--text2);margin:6px 0 12px}
.gp-sel{padding:8px 12px;border:1.5px solid var(--border);border-radius:10px;background:var(--bg);font-family:inherit;font-size:14px;color:var(--text)}
.gp-link{background:none;border:none;color:var(--text3);font-size:13px;cursor:pointer;font-family:inherit;text-decoration:underline;padding:4px}
</style>

<div class="view-panel" id="view-growth">
  <section class="section">
    <div class="section-head"><h2><?php echo esc_html( kounselia_t( 'growth.title', array(), $gp_lang ) ); ?></h2><span class="section-sub"><?php echo esc_html( kounselia_t( 'growth.subtitle', array(), $gp_lang ) ); ?></span></div>
    <div id="gp-root"><p style="color:var(--text3);font-size:14px"><?php echo esc_html( kounselia_t( 'growth.loading', array(), $gp_lang ) ); ?></p></div>
  </section>
</div>

<script>
(function(){
  var root = document.getElementById('gp-root');
  if(!root) return;
  // Words on this screen, in the member's language (packages/core/src/locales).
  var DICT = <?php echo wp_json_encode( $gp_dict ); ?>;
  function tr(key, vars){
    var text = DICT[key] != null ? DICT[key] : key;
    return text.replace(/\{(\w+)\}/g, function(m, name){ return vars && vars[name] != null ? vars[name] : m; });
  }
  var state = { data: null, picked: null, answers: {}, busy: false, openDay: null, loaded: false };

  function post(data){
    return fetch(KOUNSELIA.ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(Object.assign({ nonce: KOUNSELIA.nonce }, data)) }).then(function(r){ return r.json(); });
  }
  var TZ = (function(){ try { return Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch(e) { return ''; } })();
  function esc(s){ var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

  function load(){
    return post({ action: 'kounselia_growth_get', timezone: TZ }).then(function(res){
      if(res.success){ state.data = res.data; state.loaded = true; render(); }
      else { root.innerHTML = '<p style="color:var(--text3)">' + esc((res.data && res.data.message) || tr('growth.load_failed')) + '</p>'; }
    }).catch(function(){ root.innerHTML = '<p style="color:var(--text3)">' + esc(tr('growth.conn_problem')) + '</p>'; });
  }

  function allowanceLine(a){
    if(!a.limit) return '';
    return '<p class="gp-note">' + esc(tr('growth.plans_left', { remaining: a.remaining, limit: a.limit })) + (state.data.is_pro ? '' : ' · <a href="javascript:void(0)" onclick="switchTab(\'upgrade\')">' + esc(tr('growth.unlimited_pro')) + '</a>') + '</p>';
  }

  function render(){
    var d = state.data;
    root.setAttribute('dir', d.rtl ? 'rtl' : 'ltr');
    if(d.plan){ renderPlan(d); }
    else if(state.picked){ renderQuestions(d); }
    else { renderPicker(d); }
  }

  function renderPicker(d){
    var h = '<div class="gp-card"><p style="font-size:15px;line-height:1.65;color:var(--text2);margin-bottom:18px">' + esc(tr('growth.intro')) + '</p><div class="gp-areas">';
    d.areas.forEach(function(a){
      h += '<button type="button" class="gp-area" data-area="' + esc(a.key) + '"><i class="ti ti-' + esc(a.icon) + '"></i><b>' + esc(a.label) + '</b><span>' + esc(a.blurb) + '</span></button>';
    });
    h += '</div>' + allowanceLine(d.allowance) + '</div>';
    if(d.allowance.limit && d.allowance.remaining < 1){
      h = '<div class="gp-card"><h3 style="font-family:\'Cormorant Garamond\',serif;font-size:24px;font-weight:500;margin-bottom:8px">' + esc(tr('growth.used_all_title')) + '</h3><p style="font-size:15px;line-height:1.65;color:var(--text2);margin-bottom:16px">' + esc(tr('growth.used_all_body')) + '</p><button class="btn-w primary" style="border:none;cursor:pointer" onclick="switchTab(\'upgrade\')">' + esc(tr('growth.see_pro')) + '</button></div>';
    }
    h += renderPrevious(d);
    root.innerHTML = h;
    root.querySelectorAll('.gp-area').forEach(function(b){
      b.addEventListener('click', function(){ state.picked = b.dataset.area; state.answers = {}; render(); });
    });
  }

  function renderQuestions(d){
    var area = d.areas.filter(function(a){ return a.key === state.picked; })[0];
    var h = '<div class="gp-card"><div class="gp-eyebrow">' + esc(area.label) + '</div><h3 style="font-family:\'Cormorant Garamond\',serif;font-size:26px;font-weight:500;margin:4px 0 18px">' + esc(tr('growth.quick_questions')) + '</h3>';
    area.questions.forEach(function(q){
      h += '<div class="gp-q"><label>' + esc(q.label) + (q.required ? '' : ' <span style="color:var(--text3);font-weight:400">' + esc(tr('growth.optional')) + '</span>') + '</label>' + (q.hint ? '<small>' + esc(q.hint) + '</small>' : '');
      if(q.type === 'choice'){
        h += '<div class="gp-choices">' + q.choices.map(function(c){ return '<button type="button" class="gp-choice' + (state.answers[q.key] === c.key ? ' on' : '') + '" data-q="' + esc(q.key) + '" data-v="' + esc(c.key) + '">' + esc(c.label) + '</button>'; }).join('') + '</div>';
      } else {
        h += '<textarea data-q="' + esc(q.key) + '" maxlength="600">' + esc(state.answers[q.key] || '') + '</textarea>';
      }
      h += '</div>';
    });
    h += '<div class="gp-actions"><button type="button" class="intake-btn ghost" id="gp-back">' + esc(tr('growth.back')) + '</button><button type="button" class="intake-btn" id="gp-make">' + esc(state.busy ? tr('growth.creating') : tr('growth.create')) + '</button></div><p class="gp-note" id="gp-err"></p></div>';
    root.innerHTML = h;
    root.querySelectorAll('textarea[data-q]').forEach(function(t){ t.addEventListener('input', function(){ state.answers[t.dataset.q] = t.value; }); });
    root.querySelectorAll('.gp-choice').forEach(function(b){
      b.addEventListener('click', function(){
        state.answers[b.dataset.q] = b.dataset.v;
        root.querySelectorAll('.gp-choice[data-q="' + b.dataset.q + '"]').forEach(function(x){ x.classList.toggle('on', x === b); });
      });
    });
    document.getElementById('gp-back').addEventListener('click', function(){ state.picked = null; render(); });
    document.getElementById('gp-make').addEventListener('click', function(){
      if(state.busy) return;
      var missing = area.questions.filter(function(q){ return q.required && !(state.answers[q.key] || '').trim(); })[0];
      if(missing){ document.getElementById('gp-err').textContent = tr('growth.answer_required', { question: missing.label }); return; }
      state.busy = true; document.getElementById('gp-make').disabled = true; document.getElementById('gp-make').textContent = tr('growth.creating') + ' ' + tr('growth.creating_wait');
      post({ action: 'kounselia_growth_create', area: state.picked, answers: JSON.stringify(state.answers), timezone: TZ }).then(function(res){
        state.busy = false;
        if(res.success){ state.data = res.data; state.picked = null; state.answers = {}; toast(tr('growth.plan_ready')); render(); }
        else { render(); document.getElementById('gp-err').textContent = (res.data && res.data.message) || tr('growth.conn_problem'); }
      }).catch(function(){ state.busy = false; render(); document.getElementById('gp-err').textContent = tr('growth.conn_problem'); });
    });
  }

  function renderPlan(d){
    var p = d.plan, t = p.today;
    var pct = Math.round(p.done_count / p.total_days * 100);
    var h = '<div class="gp-card gp-today"><div class="gp-eyebrow">' + esc(p.title) + ' · ' + esc(tr('growth.day_of', { day: p.current_day, total: p.total_days })) + '</div>';
    if(t){
      h += '<h3>' + esc(t.title || tr('growth.today')) + '</h3><p>' + esc(t.task) + (t.minutes ? ' <span style="color:var(--text3)">(' + esc(tr('growth.minutes', { minutes: t.minutes })) + ')</span>' : '') + '</p>';
      h += '<div class="gp-actions"><button type="button" class="intake-btn' + (t.done ? ' ghost' : '') + '" id="gp-today-btn">' + esc(t.done ? tr('growth.done_undo') : tr('growth.mark_done')) + '</button><a class="btn-rec secondary" href="/talk.php#' + esc(p.counselor_slug) + '">' + esc(tr('growth.talk')) + '</a></div>';
    }
    h += '<div class="gp-bar"><div style="width:' + pct + '%"></div></div><div class="gp-meta"><span>' + esc(tr('growth.days_done', { done: p.done_count, total: p.total_days })) + '</span><span>' + esc(p.streak ? tr('growth.streak', { n: p.streak }) : tr('growth.streak_start')) + '</span></div></div>';

    if(p.review_ready){
      h += '<div class="gp-card gp-review"><div class="gp-eyebrow">' + esc(tr('growth.review_week', { week: p.review_ready })) + '</div><p>' + esc(tr('growth.review_prompt')) + '</p><button type="button" class="intake-btn" id="gp-review-btn" data-week="' + p.review_ready + '">' + esc(tr('growth.review_button')) + '</button></div>';
    }
    (p.reviews || []).slice().reverse().forEach(function(r){
      h += '<div class="gp-card"><div class="gp-eyebrow">' + esc(r.week === 5 ? tr('growth.review_final') : tr('growth.review_week', { week: r.week })) + '</div><p style="font-size:14.5px;line-height:1.65;color:var(--text2);margin-top:6px">' + esc(r.note) + '</p>' + (r.changed ? '<p class="gp-note">' + esc(tr('growth.review_changed', { n: r.changed, level: tr('growth.level_' + r.level) })) + '</p>' : '') + '</div>';
    });
    h += '<div class="gp-card"><div class="gp-eyebrow">' + esc(tr('growth.all_days')) + '</div><div class="gp-days">';
    p.days.forEach(function(x){
      h += '<button type="button" class="gp-day ' + (x.done ? 'done ' : '') + x.state + '" data-day="' + x.day + '" ' + (x.state === 'upcoming' ? 'aria-disabled="true"' : '') + '>' + (x.done ? '<i class="ti ti-check"></i>' : x.day) + '</button>';
    });
    h += '</div>';
    var open = p.days.filter(function(x){ return x.day === state.openDay; })[0];
    if(open){
      h += '<div class="gp-day-detail"><b>' + esc(tr('growth.day_label', { day: open.day })) + (open.title ? ': ' + esc(open.title) : '') + '</b><br>' + esc(open.task) + (open.state !== 'upcoming' ? '<br><button type="button" class="gp-link" id="gp-day-toggle">' + esc(open.done ? tr('growth.mark_not_done') : tr('growth.mark_done')) + '</button>' : '') + '</div>';
    }
    h += '<p class="gp-note">' + esc(tr('growth.missed_note')) + '</p></div>';
    h += '<div class="gp-card"><p style="font-size:14px;line-height:1.6;color:var(--text2)">' + esc(p.summary) + '</p><div style="margin:12px 0;font-size:14px;color:var(--text2)"><label for="gp-hour">' + esc(tr('growth.reminder')) + ' </label><select class="gp-sel" id="gp-hour"><option value="-1">' + esc(tr('growth.reminder_off')) + '</option>' + hourOptions(p.remind_hour) + '</select><div class="gp-note">' + esc(tr('growth.reminder_note')) + '</div></div><button type="button" class="gp-link" id="gp-end">' + esc(tr('growth.end_plan')) + '</button></div>';
    h += renderPrevious(d);
    root.innerHTML = h;

    function mark(day, done){
      post({ action: 'kounselia_growth_mark_day', plan_id: p.id, day: day, done: done ? 1 : 0 }).then(function(res){
        if(res.success && res.data.plan){ state.data.plan = res.data.plan; render(); }
        else { toast((res.data && res.data.message) || tr('growth.save_failed'), true); load(); }
      }).catch(function(){ toast('Connection problem, please try again.', true); });
    }
    var tb = document.getElementById('gp-today-btn');
    if(tb) tb.addEventListener('click', function(){ mark(t.day, !t.done); });
    root.querySelectorAll('.gp-day').forEach(function(b){
      b.addEventListener('click', function(){ state.openDay = parseInt(b.dataset.day, 10); render(); });
    });
    var dt = document.getElementById('gp-day-toggle');
    if(dt) dt.addEventListener('click', function(){ mark(open.day, !open.done); });
    var rb = document.getElementById('gp-review-btn');
    if(rb) rb.addEventListener('click', function(){
      rb.disabled = true; rb.textContent = tr('growth.reviewing');
      post({ action: 'kounselia_growth_review', plan_id: p.id, week: rb.dataset.week }).then(function(res){
        if(res.success && res.data.plan){ state.data.plan = res.data.plan; render(); }
        else { rb.disabled = false; rb.textContent = tr('growth.review_button'); toast((res.data && res.data.message) || tr('growth.conn_problem'), true); }
      }).catch(function(){ rb.disabled = false; rb.textContent = tr('growth.review_button'); toast(tr('growth.conn_problem'), true); });
    });
    var hs = document.getElementById('gp-hour');
    if(hs){
      hs.value = String(p.remind_hour);
      hs.addEventListener('change', function(){
        post({ action: 'kounselia_growth_set_reminder', plan_id: p.id, hour: hs.value }).then(function(res){
          if(res.success && res.data.plan){ state.data.plan = res.data.plan; toast(hs.value === '-1' ? tr('growth.reminder_off_toast') : tr('growth.reminder_saved')); }
          else { toast((res.data && res.data.message) || tr('growth.save_failed'), true); }
        });
      });
    }
    document.getElementById('gp-end').addEventListener('click', function(){
      if(!confirm(tr('growth.end_title') + ' ' + tr('growth.end_body'))) return;
      post({ action: 'kounselia_growth_end', plan_id: p.id }).then(function(res){
        if(res.success){ state.data = res.data; state.openDay = null; render(); }
        else { toast((res.data && res.data.message) || tr('growth.conn_problem'), true); }
      });
    });
  }

  function hourOptions(current){
    var out = '';
    for(var h = 0; h < 24; h++){
      var label = (h % 12 === 0 ? 12 : h % 12) + ':00 ' + (h < 12 ? 'am' : 'pm');
      out += '<option value="' + h + '">' + label + '</option>';
    }
    return out;
  }

  function renderPrevious(d){
    if(!d.previous.length) return '';
    var h = '<div class="gp-card"><div class="gp-eyebrow" style="margin-bottom:8px">' + esc(tr('growth.earlier')) + '</div>';
    d.previous.forEach(function(x){
      h += '<div class="gp-prev"><span>' + esc(x.title) + ' <span style="color:var(--text3)">· ' + esc(x.area_label) + '</span></span><span style="color:var(--text3)">' + esc(tr('growth.days_count', { done: x.done_count, total: x.total_days })) + (x.status === 'ended' ? ' · ' + esc(tr('growth.ended')) : '') + '</span></div>';
    });
    return h + '</div>';
  }

  // switchTab() in dashboard.php calls this whenever the Growth tab is opened.
  window.kounseliaGrowthOpen = load;
})();
</script>

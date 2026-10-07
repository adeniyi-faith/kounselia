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
.gp-link{background:none;border:none;color:var(--text3);font-size:13px;cursor:pointer;font-family:inherit;text-decoration:underline;padding:4px}
</style>

<div class="view-panel" id="view-growth">
  <section class="section">
    <div class="section-head"><h2>Growth plan</h2><span class="section-sub">Small daily steps over 30 days</span></div>
    <div id="gp-root"><p style="color:var(--text3);font-size:14px">Loading...</p></div>
  </section>
</div>

<script>
(function(){
  var root = document.getElementById('gp-root');
  if(!root) return;
  var state = { data: null, picked: null, answers: {}, busy: false, openDay: null, loaded: false };

  function post(data){
    return fetch(KOUNSELIA.ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(Object.assign({ nonce: KOUNSELIA.nonce }, data)) }).then(function(r){ return r.json(); });
  }
  function esc(s){ var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

  function load(){
    return post({ action: 'kounselia_growth_get' }).then(function(res){
      if(res.success){ state.data = res.data; state.loaded = true; render(); }
      else { root.innerHTML = '<p style="color:var(--text3)">' + esc((res.data && res.data.message) || 'Could not load your plan.') + '</p>'; }
    }).catch(function(){ root.innerHTML = '<p style="color:var(--text3)">Connection problem. Please try again.</p>'; });
  }

  function allowanceLine(a){
    if(!a.limit) return '';
    return '<p class="gp-note">' + a.remaining + ' of ' + a.limit + ' plans left on your plan' + (state.data.is_pro ? '' : ' · <a href="javascript:void(0)" onclick="switchTab(\'upgrade\')">Unlimited with Pro</a>') + '</p>';
  }

  function render(){
    var d = state.data;
    if(d.plan){ renderPlan(d); }
    else if(state.picked){ renderQuestions(d); }
    else { renderPicker(d); }
  }

  function renderPicker(d){
    var h = '<div class="gp-card"><p style="font-size:15px;line-height:1.65;color:var(--text2);margin-bottom:18px">Pick one thing to work on. Answer a few short questions and Kounselia writes you a 30 day plan with one small task a day. Noa, your Personal Development counselor, is there whenever you want to talk it through.</p><div class="gp-areas">';
    d.areas.forEach(function(a){
      h += '<button type="button" class="gp-area" data-area="' + esc(a.key) + '"><i class="ti ti-' + esc(a.icon) + '"></i><b>' + esc(a.label) + '</b><span>' + esc(a.blurb) + '</span></button>';
    });
    h += '</div>' + allowanceLine(d.allowance) + '</div>';
    if(d.allowance.limit && d.allowance.remaining < 1){
      h = '<div class="gp-card"><h3 style="font-family:\'Cormorant Garamond\',serif;font-size:24px;font-weight:500;margin-bottom:8px">You have used your free plans</h3><p style="font-size:15px;line-height:1.65;color:var(--text2);margin-bottom:16px">Upgrade to Pro for unlimited growth plans.</p><button class="btn-w primary" style="border:none;cursor:pointer" onclick="switchTab(\'upgrade\')">See Pro</button></div>';
    }
    h += renderPrevious(d);
    root.innerHTML = h;
    root.querySelectorAll('.gp-area').forEach(function(b){
      b.addEventListener('click', function(){ state.picked = b.dataset.area; state.answers = {}; render(); });
    });
  }

  function renderQuestions(d){
    var area = d.areas.filter(function(a){ return a.key === state.picked; })[0];
    var h = '<div class="gp-card"><div class="gp-eyebrow">' + esc(area.label) + '</div><h3 style="font-family:\'Cormorant Garamond\',serif;font-size:26px;font-weight:500;margin:4px 0 18px">A few quick questions</h3>';
    area.questions.forEach(function(q){
      h += '<div class="gp-q"><label>' + esc(q.label) + (q.required ? '' : ' <span style="color:var(--text3);font-weight:400">(optional)</span>') + '</label>' + (q.hint ? '<small>' + esc(q.hint) + '</small>' : '');
      if(q.type === 'choice'){
        h += '<div class="gp-choices">' + q.choices.map(function(c){ return '<button type="button" class="gp-choice' + (state.answers[q.key] === c.key ? ' on' : '') + '" data-q="' + esc(q.key) + '" data-v="' + esc(c.key) + '">' + esc(c.label) + '</button>'; }).join('') + '</div>';
      } else {
        h += '<textarea data-q="' + esc(q.key) + '" maxlength="600">' + esc(state.answers[q.key] || '') + '</textarea>';
      }
      h += '</div>';
    });
    h += '<div class="gp-actions"><button type="button" class="intake-btn ghost" id="gp-back">Back</button><button type="button" class="intake-btn" id="gp-make">' + (state.busy ? 'Writing your plan…' : 'Create my plan') + '</button></div><p class="gp-note" id="gp-err"></p></div>';
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
      if(missing){ document.getElementById('gp-err').textContent = 'Please answer: ' + missing.label; return; }
      state.busy = true; document.getElementById('gp-make').disabled = true; document.getElementById('gp-make').textContent = 'Writing your plan… this can take up to a minute';
      post({ action: 'kounselia_growth_create', area: state.picked, answers: JSON.stringify(state.answers) }).then(function(res){
        state.busy = false;
        if(res.success){ state.data = res.data; state.picked = null; state.answers = {}; toast('Your plan is ready.'); render(); }
        else { render(); document.getElementById('gp-err').textContent = (res.data && res.data.message) || 'Could not make your plan.'; }
      }).catch(function(){ state.busy = false; render(); document.getElementById('gp-err').textContent = 'Connection problem. Please try again.'; });
    });
  }

  function renderPlan(d){
    var p = d.plan, t = p.today;
    var pct = Math.round(p.done_count / p.total_days * 100);
    var h = '<div class="gp-card gp-today"><div class="gp-eyebrow">' + esc(p.title) + ' · Day ' + p.current_day + ' of ' + p.total_days + '</div>';
    if(t){
      h += '<h3>' + esc(t.title || 'Today') + '</h3><p>' + esc(t.task) + (t.minutes ? ' <span style="color:var(--text3)">(about ' + t.minutes + ' min)</span>' : '') + '</p>';
      h += '<div class="gp-actions"><button type="button" class="intake-btn' + (t.done ? ' ghost' : '') + '" id="gp-today-btn">' + (t.done ? 'Done · undo' : 'Mark as done') + '</button><a class="btn-rec secondary" href="/talk.php#' + esc(p.counselor_slug) + '">Talk it through</a></div>';
    }
    h += '<div class="gp-bar"><div style="width:' + pct + '%"></div></div><div class="gp-meta"><span>' + p.done_count + ' of ' + p.total_days + ' days done</span><span>' + (p.streak ? p.streak + '-day streak' : 'Start your streak today') + '</span></div></div>';

    h += '<div class="gp-card"><div class="gp-eyebrow">All 30 days</div><div class="gp-days">';
    p.days.forEach(function(x){
      h += '<button type="button" class="gp-day ' + (x.done ? 'done ' : '') + x.state + '" data-day="' + x.day + '" ' + (x.state === 'upcoming' ? 'aria-disabled="true"' : '') + '>' + (x.done ? '<i class="ti ti-check"></i>' : x.day) + '</button>';
    });
    h += '</div>';
    var open = p.days.filter(function(x){ return x.day === state.openDay; })[0];
    if(open){
      h += '<div class="gp-day-detail"><b>Day ' + open.day + (open.title ? ': ' + esc(open.title) : '') + '</b><br>' + esc(open.task) + (open.state !== 'upcoming' ? '<br><button type="button" class="gp-link" id="gp-day-toggle">' + (open.done ? 'Mark as not done' : 'Mark as done') + '</button>' : '') + '</div>';
    }
    h += '<p class="gp-note">Missed a day? That\'s fine. Your plan keeps going, and you can tick off any earlier day later.</p></div>';
    h += '<div class="gp-card"><p style="font-size:14px;line-height:1.6;color:var(--text2)">' + esc(p.summary) + '</p><button type="button" class="gp-link" id="gp-end">End this plan</button></div>';
    h += renderPrevious(d);
    root.innerHTML = h;

    function mark(day, done){
      post({ action: 'kounselia_growth_mark_day', plan_id: p.id, day: day, done: done ? 1 : 0 }).then(function(res){
        if(res.success && res.data.plan){ state.data.plan = res.data.plan; render(); }
        else { toast((res.data && res.data.message) || 'Could not save that.', true); load(); }
      }).catch(function(){ toast('Connection problem, please try again.', true); });
    }
    var tb = document.getElementById('gp-today-btn');
    if(tb) tb.addEventListener('click', function(){ mark(t.day, !t.done); });
    root.querySelectorAll('.gp-day').forEach(function(b){
      b.addEventListener('click', function(){ state.openDay = parseInt(b.dataset.day, 10); render(); });
    });
    var dt = document.getElementById('gp-day-toggle');
    if(dt) dt.addEventListener('click', function(){ mark(open.day, !open.done); });
    document.getElementById('gp-end').addEventListener('click', function(){
      if(!confirm('End this plan? Your progress is kept in your history, but you cannot pick it back up.')) return;
      post({ action: 'kounselia_growth_end', plan_id: p.id }).then(function(res){
        if(res.success){ state.data = res.data; state.openDay = null; render(); }
        else { toast((res.data && res.data.message) || 'Could not end the plan.', true); }
      });
    });
  }

  function renderPrevious(d){
    if(!d.previous.length) return '';
    var h = '<div class="gp-card"><div class="gp-eyebrow" style="margin-bottom:8px">Earlier plans</div>';
    d.previous.forEach(function(x){
      h += '<div class="gp-prev"><span>' + esc(x.title) + ' <span style="color:var(--text3)">· ' + esc(x.area_label) + '</span></span><span style="color:var(--text3)">' + x.done_count + '/' + x.total_days + ' days' + (x.status === 'ended' ? ' · ended' : '') + '</span></div>';
    });
    return h + '</div>';
  }

  // switchTab() in dashboard.php calls this whenever the Growth tab is opened.
  window.kounseliaGrowthOpen = load;
})();
</script>

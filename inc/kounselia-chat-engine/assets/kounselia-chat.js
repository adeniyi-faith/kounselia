// Removed SpeechRecognition fallback; establishing MediaRecorder state
let listening = false;
let dictationRecorder = null;
let dictationChunks = [];
let dictationStream = null;
let isTranscribing = false;

// Words on screen, in the member's language. inc/kounselia-chat-engine.php
// prints them (KOUNSELIA.i18n); a missing sentence shows its key.
function kT(key,vars){
  const d=(window.KOUNSELIA&&KOUNSELIA.i18n)||{};
  let t=Object.prototype.hasOwnProperty.call(d,key)?d[key]:key;
  if(vars) t=t.replace(/\{(\w+)\}/g,(m,n)=>(n in vars?String(vars[n]):m));
  return t;
}

// Same, escaped for putting into HTML.
function kH(key,vars){return esc(kT(key,vars));}

// Inherit dynamic database counselors from the PHP loader, or fallback to defaults
const fallbackC={
  serena:{name:'Serena',spec:kT('c.fallback.serena.spec'),av:'ic-rose',icon:'ti-heart',
    greeting:kT('c.fallback.serena.greeting')},
  marcus:{name:'Marcus',spec:kT('c.fallback.marcus.spec'),av:'ic-blue',icon:'ti-briefcase',
    greeting:kT('c.fallback.marcus.greeting')},
  noa:{name:'Noa',spec:kT('c.fallback.noa.spec'),av:'ic-sage',icon:'ti-leaf',
    greeting:kT('c.fallback.noa.greeting')},
  eli:{name:'Eli',spec:kT('c.fallback.eli.spec'),av:'ic-gold',icon:'ti-users',
    greeting:kT('c.fallback.eli.greeting')},
  dr_lena:{name:'Dr. Lena',spec:kT('c.fallback.dr_lena.spec'),av:'ic-teal',icon:'ti-stethoscope',voice:true,
    greeting:kT('c.fallback.dr_lena.greeting')},
  james:{name:'James',spec:kT('c.fallback.james.spec'),av:'ic-navy',icon:'ti-shield',voice:true,
    greeting:kT('c.fallback.james.greeting')},
  theo:{name:'Theo',spec:kT('c.fallback.theo.spec'),av:'ic-plum',icon:'ti-candle',
    greeting:kT('c.fallback.theo.greeting')},
  priya:{name:'Priya',spec:kT('c.fallback.priya.spec'),av:'ic-sienna',icon:'ti-battery-charging',
    greeting:kT('c.fallback.priya.greeting')}
};
let C = (window.C && Object.keys(window.C).length > 0) ? window.C : fallbackC;

// KOUNSELIA (ajaxUrl, nonce, loggedIn) is set by an inline <script> block
// in inc/kounselia-chat-engine.php, printed just before this file loads.
let cur=null,curSlug=null,curSessionId=0,msgCount=0,loggedIn=KOUNSELIA.loggedIn,typing=false;
let modalCloseTimer=null;
const LIMIT=6;

// Memory Delta Sync Counter
let userMessagesSinceSync = 0;

// Dynamic Collaboration UI Timer
let consultTimer = null;

if(loggedIn){document.addEventListener('DOMContentLoaded',function(){unlockChat();});}

function getGuestToken(){
  let t=localStorage.getItem('kounselia_guest_token');
  if(!t){
    t=(window.crypto&&crypto.randomUUID)?crypto.randomUUID():('g_'+Date.now()+'_'+Math.random().toString(36).slice(2));
    localStorage.setItem('kounselia_guest_token',t);
  }
  return t;
}

function smoothTo(id){document.getElementById(id).scrollIntoView({behavior:'smooth'});}

function handleInputStyling(el) {
  autoResize(el);
  const icon = document.getElementById('dynamic-fab-icon');
  const fab = document.getElementById('dynamic-fab');
  if(!icon || !fab) return;
  
  if (typeof isTranscribing !== 'undefined' && isTranscribing) {
    return; // UI is locked into loading mode
  }

  if (listening) {
    icon.className = 'ti ti-player-stop-filled';
    fab.classList.remove('is-typing');
    return;
  }
  if(el.value.trim().length > 0) {
    icon.className = 'ti ti-send';
    fab.classList.add('is-typing');
    fab.style.background = ''; // Allow CSS to handle send state
  } else {
    icon.className = 'ti ti-microphone';
    fab.classList.remove('is-typing');
    fab.style.background = '#00A884';
  }
}

function handleDynamicAction() {
  const inp = document.getElementById('chat-input');
  
  if (isTranscribing) return; // Wait for the network trip to finish
  
  if (listening) {
    toggleVoiceInput();
    return;
  }
  if(inp.value.trim().length > 0) {
    sendMessage();
  } else {
    toggleVoiceInput();
  }
}

function toggleChatMenu(e) {
  if(e) e.stopPropagation();
  const menu = document.getElementById('chat-dropdown');
  if(menu) menu.classList.toggle('show');
}

function closeChatMenu() {
  const menu = document.getElementById('chat-dropdown');
  if(menu && menu.classList.contains('show')) menu.classList.remove('show');
}

async function clearCurrentChat(e) {
  if(e) e.stopPropagation();
  closeChatMenu();
  if(!confirm(kT('c.chat.confirm_clear'))) return;
  stopVoiceActivity(true); // Force abort any active recording

  // Only wipe the screen once the server has actually cleared it, otherwise
  // the "cleared" conversation would quietly come back on the next reload.
  const params = new URLSearchParams({action:'kounselia_clear_chat', nonce:KOUNSELIA.nonce, counselor:curSlug});
  if(!loggedIn) params.set('guest_token', getGuestToken());
  try {
    const r = await fetch(KOUNSELIA.ajaxUrl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:params});
    const json = await r.json();
    if(!json.success) throw new Error('clear failed');
  } catch(err) {
    showChatToast(kT('c.chat.clear_failed'));
    return;
  }

  showChatToast(kT('c.chat.cleared'));
  document.getElementById('messages').innerHTML = '';
  msgCount = 0;
  curSessionId = 0;
  userMessagesSinceSync = 0;
  const greeting = cur ? (cur.greeting || kT('c.chat.greeting_default',{name:cur.name})) : kT('c.chat.hello');
  setTimeout(() => aiMsg(greeting), 300); 
}

function exportChat(e) {
  if(e) e.stopPropagation();
  closeChatMenu();
  
  const messages = document.querySelectorAll('.msg .msg-bubble');
  if (messages.length === 0) {
    showChatToast(kT('c.chat.no_export'));
    return;
  }
  
  let text = kT('c.export.title') + "\n\n";
  document.querySelectorAll('.msg').forEach(m => {
    const isAi = m.classList.contains('ai');
    const sender = isAi ? (cur ? cur.name : kT('c.export.counselor')) : kT('c.export.me');
    const content = m.querySelector('.msg-bubble').innerText.trim();
    text += sender + ":\n" + content + "\n\n";
  });
  
  const blob = new Blob([text], { type: 'text/plain' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `Session_Export_${cur ? cur.name : 'Counselor'}.txt`;
  a.click();
  URL.revokeObjectURL(url);
}

function goToUpgrade(e) {
  if(e) e.stopPropagation();
  closeChatMenu();
  if (loggedIn) {
    window.location.href = '/dashboard.php#upgrade';
  } else {
    openModal('register');
  }
}

function showChatToast(msg) {
  let toast = document.getElementById('chat-toast');
  if(!toast) {
    toast = document.createElement('div');
    toast.id = 'chat-toast';
    document.body.appendChild(toast);
  }
  toast.textContent = msg;
  toast.classList.remove('show');
  void toast.offsetWidth; 
  toast.classList.add('show');
  setTimeout(() => toast.classList.remove('show'), 2500);
}

async function fetchAndDisplayHistory(e) {
  if(e) e.stopPropagation();
  closeChatMenu(); 
  
  if(!loggedIn) { openModal('register'); return; }

  const btn = e.currentTarget;
  const icon = btn.querySelector('i');
  icon.className = 'ti ti-loader-2 action-pulse'; 

  const params = new URLSearchParams({action:'kounselia_get_history', nonce:KOUNSELIA.nonce, counselor:curSlug});
  
  try {
    const r = await fetch(KOUNSELIA.ajaxUrl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:params});
    const json = await r.json();
    icon.className = 'ti ti-history'; 

    if(json.success && json.data.messages && json.data.messages.length > 0) {
      curSessionId = json.data.session_id;
      document.getElementById('messages').innerHTML = '';
      msgCount = 0;

      json.data.messages.forEach(m => {
        if(m.sender === 'user') { renderUserBubble(m.content); msgCount++; }
        else { renderAiBubble(m.content, m.id, null, m.rating); }
      });
      scrollBot();
    } else {
      showChatToast(kT('c.chat.history_empty'));
    }
  } catch(err) {
    icon.className = 'ti ti-history';
    showChatToast(kT('c.chat.history_error'));
  }
}

function copyAiMsg(btn) {
  const b64 = btn.getAttribute('data-raw');
  if(!b64) return;
  const text = decodeURIComponent(escape(atob(b64))); 
  navigator.clipboard.writeText(text).then(() => {
    const icon = btn.querySelector('i');
    icon.className = 'ti ti-check action-pulse';
    showChatToast(kT('c.chat.copied'));
    setTimeout(() => icon.className = 'ti ti-copy', 2000);
  }).catch(() => showChatToast(kT('c.msg.copy_failed_plain')));
}

function applyRatingUI(bar, type) {
  const buttons = bar.querySelectorAll('.msg-fb-btn');
  const up = buttons[1], down = buttons[2];
  up.querySelector('i').className = type === 'up' ? 'ti ti-thumb-up-filled' : 'ti ti-thumb-up';
  up.style.color = type === 'up' ? '#2E5C3E' : '';
  down.querySelector('i').className = type === 'down' ? 'ti ti-thumb-down-filled' : 'ti ti-thumb-down';
  down.style.color = type === 'down' ? '#8B3A52' : '';
  bar.dataset.rating = type || '';
}

async function rateAiMsg(btn, msgId, type) {
  const bar = btn.parentElement;
  if(!msgId) { showChatToast(kT('c.chat.cant_rate')); return; }
  if(bar.dataset.saving === '1' || bar.dataset.rating === type) return;

  const previous = bar.dataset.rating || null;
  applyRatingUI(bar, type);
  bar.dataset.saving = '1';

  const params = new URLSearchParams({action:'kounselia_rate_message', nonce:KOUNSELIA.nonce, message_id:msgId, rating:type});
  if(!loggedIn) params.set('guest_token', getGuestToken());
  try {
    const r = await fetch(KOUNSELIA.ajaxUrl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:params});
    const json = await r.json();
    if(!json.success) throw new Error('rate failed');
    showChatToast(type === 'up' ? kT('c.msg.thanks') : kT('c.msg.recorded'));
  } catch(err) {
    applyRatingUI(bar, previous);
    showChatToast(kT('c.msg.feedback_failed'));
  } finally {
    bar.dataset.saving = '0';
  }
}

async function startChat(id){
  cur=C[id];curSlug=id;curSessionId=0;msgCount=0;typing=false;userMessagesSinceSync=0;
  stopVoiceActivity(true); // Force abort any active recording
  if(typeof liveCallActive!=='undefined'&&(liveCallActive||liveCallConnecting)) endVoiceCall();
  
  document.getElementById('chat-name').textContent=cur.name;
  document.getElementById('chat-spec').textContent=cur.spec;
  const av=document.getElementById('chat-avatar');
  av.className='chat-av-sm '+cur.av;
  av.innerHTML=`<i class="ti ${cur.icon}"></i>`;
  document.getElementById('messages').innerHTML='';
  document.getElementById('limit-area').innerHTML='';
  
  const inp=document.getElementById('chat-input');
  inp.value='';inp.disabled=false;inp.style.height='auto';
  handleInputStyling(inp);
  document.getElementById('dynamic-fab').disabled=false;
  
  const voiceBtn=document.getElementById('voice-call-btn');
  const canVoice = (cur.voice || parseInt(cur.voice_enabled) === 1 || cur.voice_enabled === true);
  if(voiceBtn) voiceBtn.style.display=(canVoice&&loggedIn)?'flex':'none';
  showScreen('chat');
  if(location.hash!=='#'+id) history.replaceState(null,'','#'+id);

  const greeting = cur.greeting || kT('c.chat.greeting_default',{name:cur.name});
  setTimeout(()=>aiMsg(greeting),600);
}

function stopVoiceActivity(abort = true){
  if(listening && dictationRecorder && dictationRecorder.state === 'recording'){
      if(abort) dictationRecorder.aborting = true;
      try{ dictationRecorder.stop(); }catch(e){}
  }
  listening = false;
  if(dictationStream) {
      dictationStream.getTracks().forEach(t=>t.stop());
      dictationStream = null;
  }
  if(currentAudio){ currentAudio.pause(); currentAudio=null; }
}

function showScreen(id){
  document.querySelectorAll('.screen').forEach(s=>s.classList.remove('active'));
  document.getElementById(id).classList.add('active');
  if(id!=='landing')document.getElementById(id).scrollTop=0;
}

function goBack(){
  if(typeof liveCallActive!=='undefined'&&(liveCallActive||liveCallConnecting)) endVoiceCall();
  stopVoiceActivity(true);
  if(location.hash) history.replaceState(null,'',location.pathname+location.search);
  if(document.getElementById('landing')){
    showScreen('landing');
  } else {
    window.location.href='/dashboard.php';
  }
}

function aiMsg(text){
  text = text || kT('c.chat.hello');
  typing=true;
  document.getElementById('dynamic-fab').disabled=true;
  
  showTyping();
  
  const delay=Math.min(900+text.length*4,1800);
  setTimeout(()=>{
    hideTyping();
    renderAiBubble(text);
    typing=false;
    if(!document.getElementById('chat-input').disabled)
      document.getElementById('dynamic-fab').disabled=false;
  },delay);
}

function renderUserBubble(text){
  const wrap=document.getElementById('messages');
  const m=document.createElement('div');m.className='msg user';
  m.innerHTML=`
    <div>
      <div class="msg-bubble">${esc(text)}</div>
      <div class="msg-time" style="text-align:end; margin-top:6px; margin-inline-end:4px;">${now()}</div>
    </div>
    <div class="msg-av" style="background:var(--accent-light);color:var(--accent);font-size:12px;font-weight:600">${loggedIn?'U':'G'}</div>`;
  wrap.appendChild(m);
}

function renderAiBubble(text, messageId, consulted, rating){
  const wrap=document.getElementById('messages');
  const m=document.createElement('div');
  m.className='msg ai';
  
  const safe=esc(text);
  let html = safe.replace(/\n\n/g,'</p><p style="margin-top:12px">').replace(/\n/g,'<br>');
  html = html.replace(/\*\*(.*?)\*\*/g, '<h4>$1</h4>'); 

  // Display the Team Collaboration Badge if the AI consulted its peers
  let consultHtml = '';
  if (consulted && consulted.length > 0) {
    consultHtml = `<div style="font-size: 10px; color: var(--gold); font-weight: 700; margin-bottom: 6px; letter-spacing: 0.5px; text-transform: uppercase; display: flex; align-items: center; gap: 4px;"><i class="ti ti-users"></i> ${esc(kT('c.msg.consulted',{names:consulted.join(' & ')}))}</div>`;
  }

  const b64Text = btoa(unescape(encodeURIComponent(text)));
  const playBtn=messageId?`<button class="voice-play-btn" onclick="playVoice(${messageId},this)" aria-label="${kT('c.msg.listen')}" title="${kT('c.msg.listen')}"><i class="ti ti-volume"></i></button>`:'';
  
  m.innerHTML=`
    <div class="msg-av ${cur.av}"><i class="ti ${cur.icon}" style="font-size:13px"></i></div>
    <div>
      ${consultHtml}
      <div class="msg-bubble"><p>${html}</p></div>
      <div style="display:flex; justify-content:space-between; align-items:flex-start;">
        <div class="msg-time" style="margin-top:6px; margin-inline-start:4px;">${now()}${playBtn}</div>
      </div>
      <div class="msg-feedback-bar">
        <button class="msg-fb-btn" data-raw="${b64Text}" onclick="copyAiMsg(this)" title="${kT('c.msg.copy')}"><i class="ti ti-copy"></i></button>
        <button class="msg-fb-btn" onclick="rateAiMsg(this, ${messageId || 0}, 'up')" title="${kT('c.msg.helpful')}"><i class="ti ti-thumb-up"></i></button>
        <button class="msg-fb-btn" onclick="rateAiMsg(this, ${messageId || 0}, 'down')" title="${kT('c.msg.not_helpful')}"><i class="ti ti-thumb-down"></i></button>
      </div>
    </div>`;
  wrap.appendChild(m);
  if(rating === 'up' || rating === 'down') applyRatingUI(m.querySelector('.msg-feedback-bar'), rating);
  scrollBot();
}

function showTyping(){
  const wrap=document.getElementById('messages');
  const t=document.createElement('div');
  t.className='msg ai';t.id='t-ind';
  t.innerHTML=`<div class="msg-av ${cur.av}"><i class="ti ${cur.icon}" style="font-size:13px"></i></div>
    <div class="msg-bubble" style="display:flex;gap:5px;align-items:center;min-width:60px;height:34px;"><div class="dot"></div><div class="dot"></div><div class="dot"></div></div>`;
  wrap.appendChild(t);
  scrollBot();

  // If the backend takes longer than 3.5 seconds, morph the dots into a "Consulting Team..." badge
  clearTimeout(consultTimer);
  consultTimer = setTimeout(() => {
    const ind = document.getElementById('t-ind');
    if (ind) {
      const bubble = ind.querySelector('.msg-bubble');
      if (bubble) {
        bubble.innerHTML = `<div style="display:flex;gap:6px;align-items:center;font-size:11px;font-weight:700;color:var(--gold);text-transform:uppercase;letter-spacing:0.5px;"><i class="ti ti-users"></i> ${esc(kT('c.typing.consulting'))}<span class="dot" style="margin-inline-start:2px;background:var(--gold)"></span><span class="dot" style="background:var(--gold)"></span><span class="dot" style="background:var(--gold)"></span></div>`;
      }
    }
  }, 3500); 
}

function hideTyping(){
  clearTimeout(consultTimer);
  const ind=document.getElementById('t-ind');
  if(ind)ind.remove();
}

async function sendMessage(){
  if(typing)return;
  if(isTranscribing)return;
  const inp=document.getElementById('chat-input');
  const txt=inp.value.trim();if(!txt)return;
  if(!loggedIn&&msgCount>=LIMIT){showBlock();return;}

  if (listening && dictationRecorder) {
    dictationRecorder.aborting = true;
    try { dictationRecorder.stop(); } catch(e) {}
    listening = false;
  }

  renderUserBubble(txt);
  inp.value='';
  handleInputStyling(inp); 
  scrollBot();
  
  msgCount++;
  typing=true;
  document.getElementById('dynamic-fab').disabled=true;
  
  showTyping();

  const params=new URLSearchParams({
    action:'kounselia_chat',
    nonce:KOUNSELIA.nonce,
    counselor:curSlug,
    message:txt,
    session_id:curSessionId||0
  });
  if(!loggedIn) params.set('guest_token',getGuestToken());

  try{
    const res=await fetch(KOUNSELIA.ajaxUrl,{
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:params
    });
    const json=await res.json();
    
    hideTyping();
    typing=false;

    if(json.success){
      const data=json.data;
      if(data.session_id) curSessionId=data.session_id;

      if(data.limit_reached && !data.reply){
        showBlock();
        return;
      }

      renderAiBubble(data.reply, data.message_id, data.consulted);
      document.getElementById('dynamic-fab').disabled=false;

      if(!loggedIn && typeof data.messages_remaining==='number'){
        if(data.limit_reached) showBlock();
        else if(data.messages_remaining===1) softNudge();
      }

      if (loggedIn) {
        userMessagesSinceSync++;
        if (userMessagesSinceSync >= 5 && curSessionId) {
          triggerMemorySynthesis().then(ok => {
            if (ok) userMessagesSinceSync = 0;
          });
        }
      }

    } else {
      const data=json.data||{};
      if(data.session_id) curSessionId=data.session_id;
      if(data.daily_limit){
        document.getElementById('limit-area').innerHTML=`<div class="limit-banner">
          <i class="ti ti-lock"></i>
          <span>${limitSentence(kT('c.limit.daily_cta',{message:data.message||kT('c.limit.daily_default'),link:'\u0001'}),kT('c.limit.hard_link'))}</span></div>`;
        document.getElementById('chat-input').disabled=true;
        document.getElementById('dynamic-fab').disabled=true;
      } else {
        renderAiBubble(data.message||kT('c.chat.trouble'));
        document.getElementById('dynamic-fab').disabled=false;
      }
    }
  }catch(err){
    hideTyping();
    typing=false;
    renderAiBubble(kT('c.chat.trouble_network'));
    document.getElementById('dynamic-fab').disabled=false;
  }
}

// Background Delta Synthesizer
function triggerMemorySynthesis() {
  const syncParams = new URLSearchParams({
    action: 'kounselia_synthesize_memory',
    nonce: KOUNSELIA.nonce,
    session_id: curSessionId
  });
  return fetch(KOUNSELIA.ajaxUrl, {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: syncParams
  })
    .then(r => r.json())
    .then(json => !!json.success)
    .catch(e => false);
}

// A sentence holding a link marker, with the sign-up link put where the language wants it.
function limitSentence(text,label){
  const i=text.indexOf('\u0001');
  if(i<0) return esc(text);
  return esc(text.slice(0,i))+'<a onclick="openModal(\'register\')">'+esc(label)+'</a>'+esc(text.slice(i+1));
}

function softNudge(){
  document.getElementById('limit-area').innerHTML=`<div class="limit-banner">
    <i class="ti ti-info-circle"></i>
    <span>${limitSentence(kT('c.limit.soft',{link:'\u0001'}),kT('c.limit.soft_link'))}</span></div>`;
}

function showBlock(){
  document.getElementById('limit-area').innerHTML=`<div class="limit-banner">
    <i class="ti ti-lock"></i>
    <span>${limitSentence(kT('c.limit.hard_plain',{link:'\u0001'}),kT('c.limit.hard_link'))}</span></div>`;
  document.getElementById('chat-input').disabled=true;
  document.getElementById('dynamic-fab').disabled=true;
}

// ==========================================
// MEDIA RECORDER FLOW FOR DICTATION
// ==========================================

function toggleVoiceInput() {
  if (listening) {
    listening = false;
    if (dictationRecorder && dictationRecorder.state === 'recording') {
      try { dictationRecorder.stop(); } catch(e) {}
    }
    return;
  }
  
  if (isTranscribing) {
    return; // Block double-taps while we are waiting on a transcription network request
  }

  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    alert(kT('c.dictation.unsupported'));
    return;
  }

  navigator.mediaDevices.getUserMedia({ audio: true })
    .then(stream => {
      dictationStream = stream;
      
      let options = {};
      if (MediaRecorder.isTypeSupported('audio/webm')) {
          options = { mimeType: 'audio/webm' };
      } else if (MediaRecorder.isTypeSupported('audio/mp4')) {
          options = { mimeType: 'audio/mp4' };
      }

      dictationRecorder = new MediaRecorder(stream, options);
      dictationChunks = [];

      dictationRecorder.ondataavailable = e => {
        if (e.data.size > 0) dictationChunks.push(e.data);
      };

      dictationRecorder.onstop = () => {
        // Cut the hardware mic feed immediately to turn off the OS indicator light
        if (dictationStream) {
          dictationStream.getTracks().forEach(track => track.stop());
          dictationStream = null;
        }

        if (dictationRecorder.aborting) {
            dictationChunks = [];
            dictationRecorder = null;
            updateMicUI();
            return;
        }

        const mimeType = dictationRecorder.mimeType || 'audio/webm';
        const blob = new Blob(dictationChunks, { type: mimeType });
        dictationChunks = [];
        dictationRecorder = null;

        transcribeDictation(blob, mimeType);
      };

      dictationRecorder.start();
      listening = true;
      updateMicUI();
    })
    .catch(err => {
      console.error("Mic error: ", err);
      listening = false;
      updateMicUI();
      alert(kT('c.dictation.denied'));
    });
}

async function transcribeDictation(blob, mimeType) {
  isTranscribing = true;
  updateMicUI();

  const reader = new FileReader();
  reader.readAsDataURL(blob);
  reader.onloadend = async () => {
    const base64data = reader.result.split(',')[1];
    const params = new URLSearchParams({
      action: 'kounselia_transcribe',
      nonce: KOUNSELIA.nonce,
      audio_b64: base64data,
      mime_type: mimeType
    });

    try {
      const res = await fetch(KOUNSELIA.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params
      });
      const json = await res.json();
      if (json.success && json.data.text) {
        const inp = document.getElementById('chat-input');
        const currentVal = inp.value.trim();
        inp.value = currentVal ? currentVal + ' ' + json.data.text : json.data.text;
        handleInputStyling(inp);
        inp.focus();
      } else {
        showChatToast(json.data && json.data.message ? json.data.message : kT('c.dictation.transcribe_failed'));
      }
    } catch (err) {
      showChatToast(kT('c.dictation.network'));
    } finally {
      isTranscribing = false;
      updateMicUI();
    }
  };
}

function updateMicUI(){
  const icon=document.getElementById('dynamic-fab-icon');
  const fab=document.getElementById('dynamic-fab');
  const inp=document.getElementById('chat-input');
  
  if(!icon || !fab) return;
  
  if (!document.getElementById('kounselia-spin-css')) {
    const style = document.createElement('style');
    style.id = 'kounselia-spin-css';
    style.innerHTML = '@keyframes fabIconSpin { 100% { transform: rotate(360deg); } } .fab-icon-spin { animation: fabIconSpin 1s linear infinite !important; }';
    document.head.appendChild(style);
  }
  
  if (typeof isTranscribing !== 'undefined' && isTranscribing) {
    icon.className = 'ti ti-loader-2 fab-icon-spin';
    fab.style.animation = 'none';
    fab.style.background = '#00A884'; // Keep the button active/green
    fab.style.opacity = '0.7'; // Dim slightly to communicate "processing"
    return;
  }
  
  // Reset opacity when returning to a normal state
  fab.style.opacity = '1';
  
  if(listening) {
    icon.className = 'ti ti-player-stop-filled';
    fab.style.animation = 'micPulse 1.2s ease-in-out infinite';
    fab.style.background = 'var(--rose)';
    fab.classList.remove('is-typing');
  } else {
    fab.style.animation = 'none';
    handleInputStyling(inp); // Fallback to our existing text styling logic
  }
}

// ==========================================

let currentAudio=null;
function playVoice(messageId,btnEl){
  if(currentAudio){
    currentAudio.pause();
    currentAudio=null;
    document.querySelectorAll('.voice-play-btn.playing').forEach(b=>{
      b.classList.remove('playing');
      b.innerHTML='<i class="ti ti-volume"></i>';
    });
    if(btnEl && btnEl.dataset.wasPlaying==='1'){
      btnEl.dataset.wasPlaying='0';
      return; 
    }
  }

  btnEl.classList.add('loading');
  btnEl.innerHTML='<i class="ti ti-loader-2"></i>';

  const params=new URLSearchParams({
    action:'kounselia_tts',
    nonce:KOUNSELIA.nonce,
    message_id:messageId
  });
  if(!loggedIn) params.set('guest_token',getGuestToken());

  fetch(KOUNSELIA.ajaxUrl,{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:params
  })
  .then(r=>r.json())
  .then(json=>{
    btnEl.classList.remove('loading');
    if(json.success && json.data.audio){
      const audio=new Audio(json.data.audio);
      currentAudio=audio;
      btnEl.classList.add('playing');
      btnEl.dataset.wasPlaying='1';
      btnEl.innerHTML='<i class="ti ti-player-stop-filled"></i>';
      const resetBtn=()=>{
        btnEl.classList.remove('playing');
        btnEl.dataset.wasPlaying='0';
        btnEl.innerHTML='<i class="ti ti-volume"></i>';
        if(currentAudio===audio) currentAudio=null;
      };
      audio.onended=resetBtn;
      audio.onerror=resetBtn;
      audio.play().catch(()=>{ resetBtn(); showChatToast(kT('c.msg.play_failed_short')); });
    } else {
      btnEl.innerHTML='<i class="ti ti-volume"></i>';
      showChatToast((json.data && json.data.message) || kT('c.msg.play_failed_retry'));
    }
  })
  .catch(()=>{
    btnEl.classList.remove('loading');
    btnEl.innerHTML='<i class="ti ti-volume"></i>';
    showChatToast(kT('c.msg.play_failed'));
  });
}

function handleKey(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendMessage();}}
function autoResize(el){el.style.height='auto';el.style.height=Math.min(el.scrollHeight,120)+'px';}
function scrollBot(){const w=document.getElementById('messages');w.scrollTop=w.scrollHeight;}
function now(){return new Date().toLocaleTimeString((window.KOUNSELIA&&KOUNSELIA.language)||[],{hour:'2-digit',minute:'2-digit'});}
function esc(t){return t.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

// A sentence with a {link} marker, put into HTML with the link where the language wants it.
function modalLinkSentence(key,onclick,labelKey){
  const text=kT(key,{link:'\u0001'});
  const i=text.indexOf('\u0001');
  if(i<0) return esc(text);
  return esc(text.slice(0,i))+'<a onclick="'+onclick+'">'+kH(labelKey)+'</a>'+esc(text.slice(i+1));
}

function openModal(type){
  const overlay = document.getElementById('modal-overlay');
  clearTimeout(modalCloseTimer);
  overlay.style.display = 'flex';
  setTimeout(() => overlay.classList.add('open'), 10);
  
  const c=document.getElementById('modal-content');
  if(type==='login'){
    c.innerHTML=`<h2>${kH('c.auth.login_title')}</h2>
      <p class="sub">${kH('c.auth.login_sub')}</p>
      <div class="form-field"><label>${kH('c.auth.email')}</label><input type="email" id="l-e" placeholder="you@example.com" autocomplete="email"></div>
      <div class="form-field">
        <label>${kH('c.auth.password')}</label>
        <div class="pw-wrap">
          <input type="password" id="l-p" class="pw-input" placeholder="••••••••" autocomplete="current-password">
          <button type="button" class="pw-toggle" aria-label="${kH('c.auth.show_password')}" onclick="togglePw('l-p', this)"><i class="ti ti-eye"></i></button>
        </div>
        <p style="text-align:end;margin-top:8px"><a onclick="openModal('forgot')" style="font-size:13px;color:var(--accent);cursor:pointer;text-decoration:none;font-weight:500">${kH('c.auth.forgot_link')}</a></p>
      </div>
      <input type="text" id="l-hp" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0">
      <button class="modal-btn" onclick="doLogin()">${kH('c.auth.sign_in')}</button>
      <p class="modal-switch">${modalLinkSentence('c.auth.no_account',"openModal('register')",'c.auth.create_free_link')}</p>`;
  } else if(type==='forgot'){
    c.innerHTML=`<h2>${kH('c.auth.reset_title')}</h2>
      <p class="sub">${kH('c.auth.reset_sub')}</p>
      <div class="form-field"><label>${kH('c.auth.email')}</label><input type="email" id="f-e" placeholder="you@example.com" autocomplete="email"></div>
      <input type="text" id="f-hp" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0">
      <button class="modal-btn" onclick="doForgotPassword()">${kH('c.auth.send_reset')}</button>
      <p class="modal-switch"><a onclick="openModal('login')">${kH('c.auth.back_to_signin')}</a></p>`;
  } else if(type==='register') {
    c.innerHTML=`<h2>${kH('c.auth.create_title')}</h2>
      <p class="sub">${kH('c.auth.create_sub')}</p>
      <div class="form-field"><label>${kH('c.auth.full_name')}</label><input type="text" id="r-n" placeholder="${kH('c.auth.your_name')}" autocomplete="name"></div>
      <div class="form-field"><label>${kH('c.auth.email')}</label><input type="email" id="r-e" placeholder="you@example.com" autocomplete="email"></div>
      <div class="form-field">
        <label>${kH('c.auth.password')}</label>
        <div class="pw-wrap">
          <input type="password" id="r-p" class="pw-input" placeholder="${kH('c.auth.create_password')}" autocomplete="new-password">
          <button type="button" class="pw-toggle" aria-label="${kH('c.auth.show_password')}" onclick="togglePw('r-p', this)"><i class="ti ti-eye"></i></button>
        </div>
      </div>
      <input type="text" id="r-hp" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0">
      <button class="modal-btn" onclick="doRegister()">${kH('c.auth.create_btn')}</button>
      <p class="modal-switch">${modalLinkSentence('c.auth.have_account',"openModal('login')",'c.auth.sign_in')}</p>
      <a class="apply-pro-card" href="/apply.php">
        <span class="apply-pro-icon"><i class="ti ti-briefcase"></i></span>
        <span class="apply-pro-text"><strong>${kH('c.auth.apply_q')}</strong><span>${kH('c.auth.apply_card')}</span></span>
        <span class="apply-pro-arrow"><i class="ti ti-arrow-right"></i></span>
      </a>`;
  }
}

function togglePw(id, btn) {
  const inp = document.getElementById(id);
  const icon = btn.querySelector('i');
  if (inp.type === 'password') {
    inp.type = 'text';
    icon.className = 'ti ti-eye-off';
    btn.setAttribute('aria-label', kT('c.auth.hide_password'));
  } else {
    inp.type = 'password';
    icon.className = 'ti ti-eye';
    btn.setAttribute('aria-label', kT('c.auth.show_password'));
  }
}

function doLogin(){
  const e=document.getElementById('l-e').value.trim();
  const p=document.getElementById('l-p').value;
  const hp=document.getElementById('l-hp').value;
  if(!e||!p){alert(kT('c.auth.enter_credentials'));return;}
  const btn=document.querySelector('#modal-content .modal-btn');
  if(btn){btn.disabled=true;btn.textContent=kT('c.auth.signing_in');}
  fetch(KOUNSELIA.ajaxUrl,{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams({action:'kounselia_login',nonce:KOUNSELIA.nonce,email:e,password:p,website:hp})
  })
  .then(r=>r.json())
  .then(res=>{
    if(res.success){
      loggedIn=true;
      if(res.data.nonce) KOUNSELIA.nonce=res.data.nonce;
      unlockChat();
      authSuccess(res.data.name?kT('c.auth.welcome_back',{name:res.data.name}):kT('c.auth.welcome_back_anon'),true);
      updateUserUI(res.data.name);
    } else {
      alert(res.data && res.data.message ? res.data.message : kT('c.auth.signin_failed'));
      if(btn){btn.disabled=false;btn.textContent=kT('c.auth.sign_in');}
    }
  })
  .catch(()=>{
    alert(kT('c.auth.network_error'));
    if(btn){btn.disabled=false;btn.textContent=kT('c.auth.sign_in');}
  });
}

function doRegister(){
  const n=document.getElementById('r-n').value.trim();
  const e=document.getElementById('r-e').value.trim();
  const p=document.getElementById('r-p').value;
  const hp=document.getElementById('r-hp').value;
  if(!n||!e||!p){alert(kT('c.auth.fill_all'));return;}
  const btn=document.querySelector('#modal-content .modal-btn');
  if(btn){btn.disabled=true;btn.textContent=kT('c.auth.creating');}
  fetch(KOUNSELIA.ajaxUrl,{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams({action:'kounselia_register',nonce:KOUNSELIA.nonce,name:n,email:e,password:p,website:hp})
  })
  .then(r=>r.json())
  .then(res=>{
    if(res.success){
      loggedIn=true;
      if(res.data.nonce) KOUNSELIA.nonce=res.data.nonce;
      unlockChat();
      const fname=res.data.name.split(' ')[0]||res.data.name;
      authSuccess(fname?kT('c.auth.welcome_new',{name:fname}):kT('c.auth.welcome_new_anon'),false);
      updateUserUI(fname);
    } else {
      alert(res.data && res.data.message ? res.data.message : kT('c.auth.register_failed'));
      if(btn){btn.disabled=false;btn.textContent=kT('c.auth.create_btn');}
    }
  })
  .catch(()=>{
    alert(kT('c.auth.network_error'));
    if(btn){btn.disabled=false;btn.textContent=kT('c.auth.create_btn');}
  });
}

function doForgotPassword(){
  const e=document.getElementById('f-e').value.trim();
  const hp=document.getElementById('f-hp').value;
  if(!e){alert(kT('c.auth.enter_email'));return;}
  const btn=document.querySelector('#modal-content .modal-btn');
  if(btn){btn.disabled=true;btn.textContent=kT('c.auth.sending');}
  fetch(KOUNSELIA.ajaxUrl,{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams({action:'kounselia_forgot_password',nonce:KOUNSELIA.nonce,email:e,website:hp})
  })
  .then(r=>r.json())
  .then(res=>{
    if(res.success){
      const c=document.getElementById('modal-content');
      c.innerHTML=`<h2>${kH('c.auth.check_email')}</h2>
        <p class="sub">${kH('c.auth.reset_sent',{email:e})}</p>
        <button class="modal-btn" onclick="openModal('login')">${kH('c.auth.back_to_signin')}</button>`;
    } else {
      if(btn){btn.disabled=false;btn.textContent=kT('c.auth.send_reset');}
      alert(res.data && res.data.message ? res.data.message : kT('c.auth.reset_failed'));
    }
  })
  .catch(()=>{
    if(btn){btn.disabled=false;btn.textContent=kT('c.auth.send_reset');}
    alert(kT('c.auth.network_error'));
  });
}

function updateUserUI(name) {
  const init = name.charAt(0).toUpperCase();
  const navRight = document.getElementById('nav-right');
  if(navRight) {
    navRight.innerHTML = `
      <div class="user-menu">
        <div class="user-av">${esc(init)}</div>
        <span class="user-name">${esc(name)}</span>
        <button class="btn-ghost" onclick="logout()" style="padding: 7px 16px;">${kH('c.nav.sign_out')}</button>
      </div>
    `;
  }
  const gn = document.getElementById('guest-note');
  if(gn) gn.style.display = 'none';
}

function logout() {
  fetch(KOUNSELIA.ajaxUrl,{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams({action:'kounselia_logout',nonce:KOUNSELIA.nonce})
  }).finally(()=>{
    loggedIn = false;
    const navRight = document.getElementById('nav-right');
    if(navRight) {
      navRight.innerHTML = `
        <button class="btn-ghost" onclick="openModal('login')">${kH('c.auth.sign_in')}</button>
        <button class="btn-nav-primary" onclick="openModal('register')">${kH('c.nav.start_free')}</button>
      `;
    }
    const gn = document.getElementById('guest-note');
    if(gn) gn.style.display = 'block';
    location.reload();
  });
}

function unlockChat(){
  const inp=document.getElementById('chat-input');
  if(inp){inp.disabled=false;document.getElementById('limit-area').innerHTML='';
    if(!typing)document.getElementById('dynamic-fab').disabled=false;}
}

// Content pages send people here with ?return=/blog/... so that after
// signing in they land back on what they were reading.
function kounseliaReturnPath(){
  const r=new URLSearchParams(location.search).get('return')||'';
  return /^\/[^\/\\]/.test(r)?r:'';
}

function authSuccess(msg,pro){
  const back=kounseliaReturnPath();
  if(back){ window.location.href=back; return; }
  const isChatActive = (curSlug !== null && curSlug !== '');
  const btnAction = isChatActive ? "closeModal()" : "window.location.href='/dashboard.php'";
  const btnText = isChatActive ? kT('c.auth.continue') : kT('c.auth.dashboard');

  document.getElementById('modal-content').innerHTML=`
    <div class="success-wrap">
      <div class="success-icon"><i class="ti ti-check"></i></div>
      <h2 style="font-family:'Cormorant Garamond',serif;font-size:26px;font-weight:500;margin-bottom:10px">${esc(msg)}</h2>
      <p style="font-size:15px;color:var(--text2);font-weight:400;line-height:1.65">${kH('c.auth.saved')}</p>
      ${pro?`<div class="pro-card"><h4>${kH('c.auth.pro_title')}</h4>
        <p>${kH('c.auth.pro_body')}</p>
        <button onclick="${btnAction}">${kH('c.auth.see_pro')}</button></div>`:''}
      <button class="modal-btn" style="margin-top:24px" onclick="${btnAction}">${esc(btnText)}</button>
      ${isChatActive ? `<p class="modal-switch"><a href="/dashboard.php">${kH('c.auth.dashboard_arrow')}</a></p>` : ''}
    </div>`;
}

function closeModal(){
  const overlay = document.getElementById('modal-overlay');
  overlay.classList.remove('open');
  modalCloseTimer = setTimeout(() => overlay.style.display = 'none', 400); 
}
function outsideClose(e){if(e.target===document.getElementById('modal-overlay'))closeModal();}

let liveWs=null,liveAudioCtx=null,livePlaybackCtx=null,liveWorkletNode=null,liveMicStream=null;
let liveSessionId=0,liveAllowedSeconds=0,liveSecondsLeft=0,liveTimerHandle=null;
let livePlaybackQueue=[],liveNextStartTime=0,liveMuted=false,liveCallActive=false,liveCallConnecting=false;
let liveUserUtterance='',liveBotUtterance='',liveUserFlushed=true;

function fmtTimer(s){
  s=Math.max(0,Math.floor(s));
  const m=Math.floor(s/60),sec=s%60;
  return String(m).padStart(2,'0')+':'+String(sec).padStart(2,'0');
}

// Must run straight from the member's tap: browsers (Safari on iPhone
// and iPad especially) only let sound play, and the microphone feed flow,
// for audio set up during a tap. Setting the audio up later, after waiting
// on the network, left those calls silent both ways.
async function startVoiceCall(){
  if(!cur||!(cur.voice||parseInt(cur.voice_enabled)===1||cur.voice_enabled===true)){return;}
  if(!loggedIn){openModal('login');return;}
  if(liveCallActive||liveCallConnecting) return;
  if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia||!window.AudioWorklet||!window.WebSocket){
    alert(kT('c.call.unsupported'));
    return;
  }
  liveCallConnecting=true;
  stopVoiceActivity(true);

  liveAudioCtx=new (window.AudioContext||window.webkitAudioContext)();
  livePlaybackCtx=new (window.AudioContext||window.webkitAudioContext)();
  liveAudioCtx.resume().catch(()=>{});
  livePlaybackCtx.resume().catch(()=>{});
  liveNextStartTime=livePlaybackCtx.currentTime;
  liveUserUtterance='';liveBotUtterance='';liveUserFlushed=true;

  const overlay=document.getElementById('call-overlay');
  overlay.classList.add('active');
  document.getElementById('call-status').textContent=kT('c.call.connecting');
  document.getElementById('call-name').textContent=cur.name;
  document.getElementById('call-avatar').innerHTML=`<i class="ti ${cur.icon}"></i>`;
  document.getElementById('call-avatar').className='call-avatar '+cur.av;
  document.getElementById('call-caption').textContent='';
  document.getElementById('call-timer').textContent='00:00';
  document.getElementById('call-plan-note').textContent='';
  liveMuted=false;
  updateMuteUI();

  // The member hung up while we were waiting on something.
  const hungUp=()=>!liveCallConnecting;
  // Shows why the call couldn't go ahead, then closes the call screen.
  const giveUp=(text,delay)=>{
    releaseVoiceCall();
    document.getElementById('call-status').textContent=text;
    setTimeout(endVoiceCall,delay||2200);
  };

  let stream;
  try{
    stream=await navigator.mediaDevices.getUserMedia({audio:{channelCount:1,echoCancellation:true,noiseSuppression:true,autoGainControl:true}});
  }catch(err){
    if(!hungUp()) giveUp(kT('c.call.mic_blocked'),3000);
    return;
  }
  if(hungUp()){ stream.getTracks().forEach(t=>t.stop()); return; }
  liveMicStream=stream;

  try{
    await startMicCapture();
  }catch(err){
    if(!hungUp()) giveUp(kT('c.call.mic_failed'));
    return;
  }
  if(hungUp()) return;

  const params=new URLSearchParams({action:'kounselia_voice_token',nonce:KOUNSELIA.nonce,counselor:curSlug,session_id:curSessionId||0});
  let tokenRes;
  try{
    const r=await fetch(KOUNSELIA.ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:params});
    tokenRes=await r.json();
  }catch(err){
    if(!hungUp()) giveUp(kT('c.call.connect_failed'),1800);
    return;
  }
  if(hungUp()) return;
  if(!tokenRes.success){
    giveUp((tokenRes.data&&tokenRes.data.message)||kT('c.call.unavailable'));
    return;
  }

  const data=tokenRes.data;
  liveSessionId=data.session_id||curSessionId;
  curSessionId=liveSessionId;
  liveAllowedSeconds=data.allowed_seconds||300;
  liveSecondsLeft=liveAllowedSeconds;
  document.getElementById('call-timer').textContent=fmtTimer(liveSecondsLeft);
  if(data.plan==='free'){
    (function(){
      const note=kT('c.call.free_note',{minutes:Math.round(liveAllowedSeconds/60),link:'\u0001'});
      const i=note.indexOf('\u0001');
      document.getElementById('call-plan-note').innerHTML=i<0?esc(note):esc(note.slice(0,i))+'<a href="/dashboard.php#upgrade">'+kH('c.call.upgrade_link')+'</a>'+esc(note.slice(i+1));
    })();
  }

  try{
    await connectLiveSession(data.token,data.model);
    liveCallConnecting=false;
  }catch(err){
    if(!hungUp()) giveUp(kT('c.call.start_failed'),1800);
  }
}

function connectLiveSession(token,model){
  return new Promise((resolve,reject)=>{
    const url='wss://generativelanguage.googleapis.com/ws/google.ai.generativelanguage.v1alpha.GenerativeService.BidiGenerateContentConstrained?access_token='+encodeURIComponent(token);
    const ws=new WebSocket(url);
    liveWs=ws;

    ws.onopen=function(){
      ws.send(JSON.stringify({setup:{model:'models/'+model}}));
    };

    ws.onmessage=async function(evt){
      if(liveWs!==ws) return; // From a call that already ended.
      let msg;
      try{
        const raw=(evt.data instanceof Blob)?await evt.data.text():evt.data;
        msg=JSON.parse(raw);
      }catch(e){return;}

      if(msg.setupComplete){
        liveCallActive=true;
        document.getElementById('call-status').textContent=kT('c.call.listening');
        startVoiceTimer();
        resolve();
        return;
      }

      if(msg.serverContent){
        const sc=msg.serverContent;
        if(sc.interrupted){
          stopAllPlayback();
          flushBotTranscript();
        }

        // Each message carries the next chunk of speech, not the whole
        // thing said so far, so the chunks must be joined. Otherwise only
        // the last few words are kept, the caption flickers word by word,
        // and the chat history gets fragments instead of what was said.
        if(sc.inputTranscription&&typeof sc.inputTranscription.text==='string'){
          liveUserUtterance+=sc.inputTranscription.text;
          liveUserFlushed=false;
        }
        if(sc.outputTranscription&&typeof sc.outputTranscription.text==='string'){
          if(!liveUserFlushed){flushUserTranscript();}
          liveBotUtterance+=sc.outputTranscription.text;
          document.getElementById('call-status').textContent=kT('c.call.speaking');
          document.getElementById('call-ring').classList.add('speaking');
          document.getElementById('call-caption').textContent=liveBotUtterance;
        }

        if(sc.modelTurn&&sc.modelTurn.parts){
          if(!liveUserFlushed){flushUserTranscript();}
          sc.modelTurn.parts.forEach(p=>{
            if(p.inlineData&&p.inlineData.data){
              schedulePlayback(p.inlineData.data);
            }
          });
        }

        if(sc.turnComplete){
          flushBotTranscript();
          document.getElementById('call-status').textContent=kT('c.call.listening');
          document.getElementById('call-ring').classList.remove('speaking');
        }
      }

      if(msg.goAway){
        document.getElementById('call-status').textContent=kT('c.call.ending');
        setTimeout(()=>{ if(liveWs===ws) endVoiceCall(); },1200);
      }
    };

    ws.onerror=function(){reject(new Error('ws error'));};
    ws.onclose=function(){
      if(liveWs!==ws) return; // We closed it ourselves.
      if(liveCallActive){endVoiceCall();}
      // Closed before the call started (e.g. the pass was refused):
      // give up rather than stay on "Connecting…" forever.
      else reject(new Error('closed'));
    };
  });
}

// Wires the microphone into a converter that turns its sound (usually
// 44.1 or 48 kHz) into the 16 kHz pieces the service expects. Each output
// sample is the average of the input samples it covers rather than just
// one of them, which keeps speech clearer for the speech recognition.
// Pieces are only sent once the call is live (and not muted).
async function startMicCapture(){
  const ctx=liveAudioCtx;
  const workletCode=`
    class PCMCaptureProcessor extends AudioWorkletProcessor {
      constructor(){
        super();
        this.ratio=sampleRate/16000;
        this.buf=[];
        this.out=[];
      }
      process(inputs){
        const ch=inputs[0]&&inputs[0][0];
        if(ch){
          for(let i=0;i<ch.length;i++) this.buf.push(ch[i]);
          const outLen=Math.floor(this.buf.length/this.ratio);
          for(let i=0;i<outLen;i++){
            const from=Math.floor(i*this.ratio);
            const to=Math.max(from+1,Math.floor((i+1)*this.ratio));
            let s=0;
            for(let j=from;j<to;j++) s+=this.buf[j];
            s=Math.max(-1,Math.min(1,s/(to-from)));
            this.out.push(s<0?s*0x8000:s*0x7FFF);
          }
          this.buf=this.buf.slice(Math.floor(outLen*this.ratio));
          // Send in 0.1 s pieces rather than hundreds of tiny ones a second.
          if(this.out.length>=1600){
            const out=Int16Array.from(this.out);
            this.out=[];
            this.port.postMessage(out.buffer,[out.buffer]);
          }
        }
        return true;
      }
    }
    registerProcessor('pcm-capture-processor',PCMCaptureProcessor);
  `;
  const blobUrl=URL.createObjectURL(new Blob([workletCode],{type:'application/javascript'}));
  try{ await ctx.audioWorklet.addModule(blobUrl); }
  finally{ URL.revokeObjectURL(blobUrl); }
  if(liveAudioCtx!==ctx) return; // Call ended meanwhile.

  const src=ctx.createMediaStreamSource(liveMicStream);
  liveWorkletNode=new AudioWorkletNode(ctx,'pcm-capture-processor');
  liveWorkletNode.port.onmessage=function(e){
    if(!liveCallActive||liveMuted||!liveWs||liveWs.readyState!==WebSocket.OPEN)return;
    const b64=arrayBufferToBase64(e.data);
    liveWs.send(JSON.stringify({realtimeInput:{audio:{data:b64,mimeType:'audio/pcm;rate=16000'}}}));
  };
  src.connect(liveWorkletNode);
}

function arrayBufferToBase64(buf){
  let binary='';
  const bytes=new Uint8Array(buf);
  for(let i=0;i<bytes.length;i++) binary+=String.fromCharCode(bytes[i]);
  return btoa(binary);
}

function schedulePlayback(base64Pcm){
  const ctx=livePlaybackCtx;
  if(!ctx) return; // Call ended meanwhile.
  if(ctx.state==='suspended') ctx.resume().catch(()=>{});
  const binary=atob(base64Pcm);
  const bytes=new Uint8Array(binary.length);
  for(let i=0;i<binary.length;i++) bytes[i]=binary.charCodeAt(i);
  const int16=new Int16Array(bytes.buffer,0,bytes.length>>1);
  const float32=new Float32Array(int16.length);
  for(let i=0;i<int16.length;i++) float32[i]=int16[i]/(int16[i]<0?0x8000:0x7FFF);
  if(!float32.length) return;

  const buffer=ctx.createBuffer(1,float32.length,24000);
  buffer.getChannelData(0).set(float32);

  const source=ctx.createBufferSource();
  source.buffer=buffer;
  source.connect(ctx.destination);

  // Queue each piece right after the previous one so speech is smooth.
  const startAt=Math.max(ctx.currentTime,liveNextStartTime);
  source.start(startAt);
  liveNextStartTime=startAt+buffer.duration;
  livePlaybackQueue.push(source);
  source.onended=function(){
    livePlaybackQueue=livePlaybackQueue.filter(s=>s!==source);
  };
}

function stopAllPlayback(){
  livePlaybackQueue.forEach(s=>{try{s.stop();}catch(e){}});
  livePlaybackQueue=[];
  if(livePlaybackCtx) liveNextStartTime=livePlaybackCtx.currentTime;
}

function flushUserTranscript(){
  if(liveUserUtterance.trim()){
    logVoiceTurn('user',liveUserUtterance);
  }
  liveUserUtterance='';
  liveUserFlushed=true;
}

function flushBotTranscript(){
  if(liveBotUtterance.trim()){
    logVoiceTurn('bot',liveBotUtterance);
  }
  liveBotUtterance='';
}

function logVoiceTurn(sender,text){
  if(!liveSessionId)return;
  const params=new URLSearchParams({action:'kounselia_log_voice_turn',nonce:KOUNSELIA.nonce,session_id:liveSessionId,sender:sender,text:text});
  fetch(KOUNSELIA.ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:params}).catch(()=>{});
}

function startVoiceTimer(){
  clearInterval(liveTimerHandle);
  liveTimerHandle=setInterval(()=>{
    liveSecondsLeft--;
    document.getElementById('call-timer').textContent=fmtTimer(liveSecondsLeft);
    if(liveSecondsLeft<=0){
      document.getElementById('call-status').textContent=kT('c.call.times_up');
      releaseVoiceCall();
      setTimeout(endVoiceCall,900);
    }
  },1000);
}

function toggleVoiceMute(){
  liveMuted=!liveMuted;
  updateMuteUI();
}

function updateMuteUI(){
  const btn=document.getElementById('call-mute-btn');
  if(!btn)return;
  btn.classList.toggle('muted',liveMuted);
  btn.innerHTML=liveMuted?'<i class="ti ti-microphone-off"></i>':'<i class="ti ti-microphone"></i>';
}

// Stops everything the call holds (connection, microphone, sound) but
// leaves the call screen up. Safe to call more than once.
function releaseVoiceCall(){
  liveCallActive=false;
  liveCallConnecting=false;
  clearInterval(liveTimerHandle);
  flushUserTranscript();
  flushBotTranscript();
  stopAllPlayback();

  // Cleared before closing so the socket's onclose knows it's stale.
  if(liveWs){ const ws=liveWs; liveWs=null; try{ws.close();}catch(e){} }
  if(liveWorkletNode){ try{liveWorkletNode.port.onmessage=null;liveWorkletNode.disconnect();}catch(e){} liveWorkletNode=null; }
  if(liveAudioCtx){ liveAudioCtx.close().catch(()=>{}); liveAudioCtx=null; }
  if(livePlaybackCtx){ livePlaybackCtx.close().catch(()=>{}); livePlaybackCtx=null; }
  if(liveMicStream){ liveMicStream.getTracks().forEach(t=>t.stop()); liveMicStream=null; }
  liveNextStartTime=0;
}

function endVoiceCall(){
  releaseVoiceCall();
  document.getElementById('call-overlay').classList.remove('active');
  document.getElementById('call-ring').classList.remove('speaking');
}

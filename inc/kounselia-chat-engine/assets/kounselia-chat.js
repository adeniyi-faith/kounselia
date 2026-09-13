// Removed SpeechRecognition fallback; establishing MediaRecorder state
let listening = false;
let dictationRecorder = null;
let dictationChunks = [];
let dictationStream = null;
let isTranscribing = false;

// Inherit dynamic database counselors from the PHP loader, or fallback to defaults
const fallbackC={
  serena:{name:'Serena',spec:'Emotional Healing',av:'ic-rose',icon:'ti-heart',
    greeting:"Hello. I am really glad you are here.\n\nThis is your space. There is no agenda, no clock, and nothing you say here will be judged. Whatever you have been carrying, you do not have to carry it alone right now.\n\nTake your time. When you are ready, what has been sitting with you lately?"},
  marcus:{name:'Marcus',spec:'Career and Purpose',av:'ic-blue',icon:'ti-briefcase',
    greeting:"Good to have you here. I am Marcus.\n\nI work with people who are at crossroads. Careers that no longer fit, purposes they cannot locate, next steps that feel both necessary and terrifying.\n\nLet us get into it. What is the career or purpose question taking up the most space in your head right now?"},
  noa:{name:'Noa',spec:'Personal Growth',av:'ic-sage',icon:'ti-leaf',
    greeting:"Hey. I am Noa and I am genuinely glad you are here.\n\nI work with people who are in the middle of becoming. Sometimes that looks like reinvention. Sometimes it is understanding why certain patterns keep repeating. Sometimes it is just a quiet feeling that the current version of you is not the whole story.\n\nSo tell me. Who are you right now and who do you think you are becoming?"},
  eli:{name:'Eli',spec:'Relationships',av:'ic-gold',icon:'ti-users',
    greeting:"Hi, I am Eli. Welcome.\n\nRelationships are where most of our deepest joy and most of our real pain come from. They are also where we are most likely to repeat patterns we have not fully understood yet.\n\nI am here to help you see those patterns more clearly.\n\nWhat is the relationship situation you have been turning over in your mind?"},
  dr_lena:{name:'Dr. Lena',spec:'Trauma and PTSD',av:'ic-teal',icon:'ti-stethoscope',voice:true,
    greeting:"Hello. I am Dr. Lena and I am glad you are here.\n\nI want to be clear from the start. This space moves at your pace, entirely. There is nothing you are required to share and no sequence you have to follow.\n\nTrauma is not a character flaw. It is what happens when something genuinely overwhelming meets a person who was doing their best. My role is simply to be alongside you as you begin to understand it.\n\nWhere would you like to start today?"},
  james:{name:'James',spec:"Men's Mental Health",av:'ic-navy',icon:'ti-shield',voice:true,
    greeting:"Hey. I am James.\n\nA lot of men who end up here took a while to click that button. There is something that tells us we should handle things on our own. That talking about it makes it more real or makes us look weak.\n\nNone of that is true. And none of it applies here.\n\nThis is just a conversation. No performance required. What is going on?"},
  theo:{name:'Theo',spec:'Grief and Loss',av:'ic-plum',icon:'ti-candle',
    greeting:"Hello. I am Theo.\n\nGrief is one of the most isolating experiences a person can have. Partly because the world often expects us to move through it faster than we are able to. Partly because the people around us sometimes do not know how to hold it with us.\n\nI am not in a hurry. There is no timeline here.\n\nWould you like to tell me about who or what you have lost?"},
  priya:{name:'Priya',spec:'Burnout and Balance',av:'ic-sienna',icon:'ti-battery-charging',
    greeting:"Hi, I am Priya. I am glad you are here, even if getting here took more energy than you felt you had.\n\nBurnout tends to hit the people who cared the most, worked the hardest, and gave the most of themselves. It is not a sign of weakness. It is a sign that something has been out of balance for a long time.\n\nTell me. What does your exhaustion actually feel like right now?"}
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

function clearCurrentChat(e) {
  if(e) e.stopPropagation();
  closeChatMenu();
  stopVoiceActivity(true); // Force abort any active recording
  document.getElementById('messages').innerHTML = '';
  msgCount = 0;
  curSessionId = 0;
  userMessagesSinceSync = 0;
  const greeting = cur ? (cur.greeting || `Hello. I am ${cur.name}. Where would you like to start today?`) : "Hello.";
  setTimeout(() => aiMsg(greeting), 300); 
}

function exportChat(e) {
  if(e) e.stopPropagation();
  closeChatMenu();
  
  const messages = document.querySelectorAll('.msg .msg-bubble');
  if (messages.length === 0) {
    showChatToast('No messages to export.');
    return;
  }
  
  let text = "Kounselia Session Export\n\n";
  document.querySelectorAll('.msg').forEach(m => {
    const isAi = m.classList.contains('ai');
    const sender = isAi ? (cur ? cur.name : 'Counselor') : 'Me';
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
        else { renderAiBubble(m.content, m.id); }
      });
      scrollBot();
    } else {
      showChatToast('No previous history found.');
    }
  } catch(err) {
    icon.className = 'ti ti-history';
    showChatToast('Failed to load history.');
  }
}

function copyAiMsg(btn) {
  const b64 = btn.getAttribute('data-raw');
  if(!b64) return;
  const text = decodeURIComponent(escape(atob(b64))); 
  navigator.clipboard.writeText(text).then(() => {
    const icon = btn.querySelector('i');
    icon.className = 'ti ti-check action-pulse';
    showChatToast('Message copied to clipboard');
    setTimeout(() => icon.className = 'ti ti-copy', 2000);
  }).catch(() => showChatToast('Failed to copy text'));
}

function rateAiMsg(btn, msgId, type) {
  const icon = btn.querySelector('i');
  icon.classList.add('action-pulse');
  const parent = btn.parentElement;
  const buttons = parent.querySelectorAll('.msg-fb-btn');
  
  if(type === 'up') {
    icon.className = 'ti ti-thumb-up-filled';
    btn.style.color = '#2E5C3E'; 
    buttons[2].querySelector('i').className = 'ti ti-thumb-down';
    buttons[2].style.color = '';
    showChatToast('Thanks for the feedback!');
  } else {
    icon.className = 'ti ti-thumb-down-filled';
    btn.style.color = '#8B3A52';
    buttons[1].querySelector('i').className = 'ti ti-thumb-up';
    buttons[1].style.color = '';
    showChatToast('Feedback recorded.');
  }
}

async function startChat(id){
  cur=C[id];curSlug=id;curSessionId=0;msgCount=0;typing=false;userMessagesSinceSync=0;
  stopVoiceActivity(true); // Force abort any active recording
  if(typeof liveCallActive!=='undefined'&&liveCallActive) endVoiceCall();
  
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

  const greeting = cur.greeting || `Hello. I am ${cur.name}. Where would you like to start today?`;
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
  if(typeof liveCallActive!=='undefined'&&liveCallActive) endVoiceCall();
  stopVoiceActivity(true);
  if(location.hash) history.replaceState(null,'',location.pathname+location.search);
  if(document.getElementById('landing')){
    showScreen('landing');
  } else {
    window.location.href='/dashboard.php';
  }
}

function aiMsg(text){
  text = text || "Hello.";
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
      <div class="msg-time" style="text-align:right; margin-top:6px; margin-right:4px;">${now()}</div>
    </div>
    <div class="msg-av" style="background:var(--accent-light);color:var(--accent);font-size:12px;font-weight:600">${loggedIn?'U':'G'}</div>`;
  wrap.appendChild(m);
}

function renderAiBubble(text, messageId, consulted){
  const wrap=document.getElementById('messages');
  const m=document.createElement('div');
  m.className='msg ai';
  
  const safe=esc(text);
  let html = safe.replace(/\n\n/g,'</p><p style="margin-top:12px">').replace(/\n/g,'<br>');
  html = html.replace(/\*\*(.*?)\*\*/g, '<h4>$1</h4>'); 

  // Display the Team Collaboration Badge if the AI consulted its peers
  let consultHtml = '';
  if (consulted && consulted.length > 0) {
    consultHtml = `<div style="font-size: 10px; color: var(--gold); font-weight: 700; margin-bottom: 6px; letter-spacing: 0.5px; text-transform: uppercase; display: flex; align-items: center; gap: 4px;"><i class="ti ti-users"></i> Consulted ${consulted.join(' & ')}</div>`;
  }

  const b64Text = btoa(unescape(encodeURIComponent(text)));
  const playBtn=messageId?`<button class="voice-play-btn" onclick="playVoice(${messageId},this)" aria-label="Listen" title="Listen"><i class="ti ti-volume"></i></button>`:'';
  
  m.innerHTML=`
    <div class="msg-av ${cur.av}"><i class="ti ${cur.icon}" style="font-size:13px"></i></div>
    <div>
      ${consultHtml}
      <div class="msg-bubble"><p>${html}</p></div>
      <div style="display:flex; justify-content:space-between; align-items:flex-start;">
        <div class="msg-time" style="margin-top:6px; margin-left:4px;">${now()}${playBtn}</div>
      </div>
      <div class="msg-feedback-bar">
        <button class="msg-fb-btn" data-raw="${b64Text}" onclick="copyAiMsg(this)" title="Copy"><i class="ti ti-copy"></i></button>
        <button class="msg-fb-btn" onclick="rateAiMsg(this, ${messageId || 0}, 'up')" title="Helpful"><i class="ti ti-thumb-up"></i></button>
        <button class="msg-fb-btn" onclick="rateAiMsg(this, ${messageId || 0}, 'down')" title="Not Helpful"><i class="ti ti-thumb-down"></i></button>
      </div>
    </div>`;
  wrap.appendChild(m);
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
        bubble.innerHTML = `<div style="display:flex;gap:6px;align-items:center;font-size:11px;font-weight:700;color:var(--gold);text-transform:uppercase;letter-spacing:0.5px;"><i class="ti ti-users"></i> Consulting Team<span class="dot" style="margin-left:2px;background:var(--gold)"></span><span class="dot" style="background:var(--gold)"></span><span class="dot" style="background:var(--gold)"></span></div>`;
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
          <span>${esc(data.message||"You've reached today's guest limit.")} <a onclick="openModal('register')">Sign up free</a> to keep going.</span></div>`;
        document.getElementById('chat-input').disabled=true;
        document.getElementById('dynamic-fab').disabled=true;
      } else {
        renderAiBubble(data.message||"I'm having trouble connecting right now. Please try again in a moment.");
        document.getElementById('dynamic-fab').disabled=false;
      }
    }
  }catch(err){
    hideTyping();
    typing=false;
    renderAiBubble("I'm having trouble connecting right now. Please check your connection and try again.");
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

function softNudge(){
  document.getElementById('limit-area').innerHTML=`<div class="limit-banner">
    <i class="ti ti-info-circle"></i>
    <span>One message left as a guest. <a onclick="openModal('register')">Create a free account</a> to keep going and save this session.</span></div>`;
}

function showBlock(){
  document.getElementById('limit-area').innerHTML=`<div class="limit-banner">
    <i class="ti ti-lock"></i>
    <span>You have reached the guest limit. <a onclick="openModal('register')">Sign up free</a> to continue and save your progress.</span></div>`;
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
    alert("Voice input isn't supported in this browser yet. Try Chrome, Edge, or Safari.");
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
      alert("Microphone access denied or unavailable.");
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
        showChatToast(json.data && json.data.message ? json.data.message : "Could not transcribe audio.");
      }
    } catch (err) {
      showChatToast("Network error during transcription.");
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
      audio.play();
      audio.onended=()=>{
        btnEl.classList.remove('playing');
        btnEl.dataset.wasPlaying='0';
        btnEl.innerHTML='<i class="ti ti-volume"></i>';
        if(currentAudio===audio) currentAudio=null;
      };
    } else {
      btnEl.innerHTML='<i class="ti ti-volume"></i>';
    }
  })
  .catch(()=>{
    btnEl.classList.remove('loading');
    btnEl.innerHTML='<i class="ti ti-volume"></i>';
  });
}

function handleKey(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendMessage();}}
function autoResize(el){el.style.height='auto';el.style.height=Math.min(el.scrollHeight,120)+'px';}
function scrollBot(){const w=document.getElementById('messages');w.scrollTop=w.scrollHeight;}
function now(){return new Date().toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'});}
function esc(t){return t.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

function openModal(type){
  const overlay = document.getElementById('modal-overlay');
  clearTimeout(modalCloseTimer);
  overlay.style.display = 'flex';
  setTimeout(() => overlay.classList.add('open'), 10);
  
  const c=document.getElementById('modal-content');
  if(type==='login'){
    c.innerHTML=`<h2>Welcome back</h2>
      <p class="sub">Your sessions and progress, right where you left off.</p>
      <div class="form-field"><label>Email address</label><input type="email" id="l-e" placeholder="you@example.com" autocomplete="email"></div>
      <div class="form-field">
        <label>Password</label>
        <div class="pw-wrap">
          <input type="password" id="l-p" class="pw-input" placeholder="••••••••" autocomplete="current-password">
          <button type="button" class="pw-toggle" onclick="togglePw('l-p', this)"><i class="ti ti-eye"></i></button>
        </div>
        <p style="text-align:right;margin-top:8px"><a onclick="openModal('forgot')" style="font-size:13px;color:var(--accent);cursor:pointer;text-decoration:none;font-weight:500">Forgot your password?</a></p>
      </div>
      <input type="text" id="l-hp" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0">
      <button class="modal-btn" onclick="doLogin()">Sign in</button>
      <p class="modal-switch">No account? <a onclick="openModal('register')">Create one free</a></p>`;
  } else if(type==='forgot'){
    c.innerHTML=`<h2>Reset your password</h2>
      <p class="sub">Enter the email on your account and we will send you a link to set a new password.</p>
      <div class="form-field"><label>Email address</label><input type="email" id="f-e" placeholder="you@example.com" autocomplete="email"></div>
      <input type="text" id="f-hp" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0">
      <button class="modal-btn" onclick="doForgotPassword()">Send reset link</button>
      <p class="modal-switch"><a onclick="openModal('login')">Back to sign in</a></p>`;
  } else if(type==='register') {
    c.innerHTML=`<h2>Create your account</h2>
      <p class="sub">Free to start. Save sessions, track your journey, never start over.</p>
      <div class="form-field"><label>Full name</label><input type="text" id="r-n" placeholder="Your name" autocomplete="name"></div>
      <div class="form-field"><label>Email address</label><input type="email" id="r-e" placeholder="you@example.com" autocomplete="email"></div>
      <div class="form-field">
        <label>Password</label>
        <div class="pw-wrap">
          <input type="password" id="r-p" class="pw-input" placeholder="Create a password" autocomplete="new-password">
          <button type="button" class="pw-toggle" onclick="togglePw('r-p', this)"><i class="ti ti-eye"></i></button>
        </div>
      </div>
      <input type="text" id="r-hp" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0">
      <button class="modal-btn" onclick="doRegister()">Create free account</button>
      <p class="modal-switch">Already have an account? <a onclick="openModal('login')">Sign in</a></p>`;
  }
}

function togglePw(id, btn) {
  const inp = document.getElementById(id);
  const icon = btn.querySelector('i');
  if (inp.type === 'password') {
    inp.type = 'text';
    icon.className = 'ti ti-eye-off';
  } else {
    inp.type = 'password';
    icon.className = 'ti ti-eye';
  }
}

function doLogin(){
  const e=document.getElementById('l-e').value.trim();
  const p=document.getElementById('l-p').value;
  const hp=document.getElementById('l-hp').value;
  if(!e||!p){alert('Please enter your credentials.');return;}
  const btn=document.querySelector('#modal-content .modal-btn');
  if(btn){btn.disabled=true;btn.textContent='Signing in...';}
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
      authSuccess('Welcome back, '+res.data.name+'.',true);
      updateUserUI(res.data.name);
    } else {
      alert(res.data && res.data.message ? res.data.message : 'Sign in failed, please try again.');
      if(btn){btn.disabled=false;btn.textContent='Sign in';}
    }
  })
  .catch(()=>{
    alert('Something went wrong, please check your connection and try again.');
    if(btn){btn.disabled=false;btn.textContent='Sign in';}
  });
}

function doRegister(){
  const n=document.getElementById('r-n').value.trim();
  const e=document.getElementById('r-e').value.trim();
  const p=document.getElementById('r-p').value;
  const hp=document.getElementById('r-hp').value;
  if(!n||!e||!p){alert('Please fill in all fields.');return;}
  const btn=document.querySelector('#modal-content .modal-btn');
  if(btn){btn.disabled=true;btn.textContent='Creating account...';}
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
      authSuccess('Welcome to Kounselia, '+fname+'.',false);
      updateUserUI(fname);
    } else {
      alert(res.data && res.data.message ? res.data.message : 'Could not create your account, please try again.');
      if(btn){btn.disabled=false;btn.textContent='Create free account';}
    }
  })
  .catch(()=>{
    alert('Something went wrong, please check your connection and try again.');
    if(btn){btn.disabled=false;btn.textContent='Create free account';}
  });
}

function doForgotPassword(){
  const e=document.getElementById('f-e').value.trim();
  const hp=document.getElementById('f-hp').value;
  if(!e){alert('Please enter your email address.');return;}
  const btn=document.querySelector('#modal-content .modal-btn');
  if(btn){btn.disabled=true;btn.textContent='Sending...';}
  fetch(KOUNSELIA.ajaxUrl,{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams({action:'kounselia_forgot_password',nonce:KOUNSELIA.nonce,email:e,website:hp})
  })
  .then(r=>r.json())
  .then(res=>{
    if(res.success){
      const c=document.getElementById('modal-content');
      c.innerHTML=`<h2>Check your email</h2>
        <p class="sub">If an account exists for ${esc(e)}, a password reset link is on its way. It can take a few minutes to arrive.</p>
        <button class="modal-btn" onclick="openModal('login')">Back to sign in</button>`;
    } else {
      if(btn){btn.disabled=false;btn.textContent='Send reset link';}
      alert(res.data && res.data.message ? res.data.message : 'Could not send the reset link, please try again.');
    }
  })
  .catch(()=>{
    if(btn){btn.disabled=false;btn.textContent='Send reset link';}
    alert('Something went wrong, please check your connection and try again.');
  });
}

function updateUserUI(name) {
  const init = name.charAt(0).toUpperCase();
  const navRight = document.getElementById('nav-right');
  if(navRight) {
    navRight.innerHTML = `
      <div class="user-menu">
        <div class="user-av">${init}</div>
        <span class="user-name">${name}</span>
        <button class="btn-ghost" onclick="logout()" style="padding: 7px 16px;">Sign out</button>
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
        <button class="btn-ghost" onclick="openModal('login')">Sign in</button>
        <button class="btn-nav-primary" onclick="openModal('register')">Start free</button>
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

function authSuccess(msg,pro){
  const isChatActive = (curSlug !== null && curSlug !== '');
  const btnAction = isChatActive ? "closeModal()" : "window.location.href='/dashboard.php'";
  const btnText = isChatActive ? "Continue my session" : "Go to your dashboard";

  document.getElementById('modal-content').innerHTML=`
    <div class="success-wrap">
      <div class="success-icon"><i class="ti ti-check"></i></div>
      <h2 style="font-family:'Cormorant Garamond',serif;font-size:26px;font-weight:500;margin-bottom:10px">${msg}</h2>
      <p style="font-size:15px;color:var(--text2);font-weight:400;line-height:1.65">Your sessions are now saved. Unlimited conversations on the free plan.</p>
      ${pro?`<div class="pro-card"><h4>Upgrade to Pro</h4>
        <p>Deep session memory, structured 30 day programs, and priority access to all counselors.</p>
        <button onclick="${btnAction}">See Pro plans</button></div>`:''}
      <button class="modal-btn" style="margin-top:24px" onclick="${btnAction}">${btnText}</button>
      ${isChatActive ? `<p class="modal-switch"><a href="/dashboard.php">Go to your dashboard →</a></p>` : ''}
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

async function startVoiceCall(){
  if(!cur||!cur.voice){return;}
  if(!loggedIn){openModal('login');return;}
  if(liveCallActive||liveCallConnecting) return;
  liveCallConnecting=true;
  if(!('mediaDevices' in navigator)||!window.AudioWorklet||!window.WebSocket){
    alert("Voice calls need a modern browser (Chrome, Edge, or Safari) with microphone support.");
    liveCallConnecting=false;
    return;
  }
  stopVoiceActivity(true);

  const overlay=document.getElementById('call-overlay');
  overlay.classList.add('active');
  document.getElementById('call-status').textContent='Connecting…';
  document.getElementById('call-name').textContent=cur.name;
  document.getElementById('call-avatar').innerHTML=`<i class="ti ${cur.icon}"></i>`;
  document.getElementById('call-avatar').className='call-avatar '+cur.av;
  document.getElementById('call-caption').textContent='';
  document.getElementById('call-timer').textContent='00:00';
  document.getElementById('call-plan-note').textContent='';
  liveMuted=false;
  updateMuteUI();

  try{
    liveMicStream=await navigator.mediaDevices.getUserMedia({audio:{channelCount:1,echoCancellation:true,noiseSuppression:true}});
  }catch(err){
    document.getElementById('call-status').textContent='Microphone access was denied.';
    liveCallConnecting=false;
    setTimeout(endVoiceCall,1800);
    return;
  }

  const params=new URLSearchParams({action:'kounselia_voice_token',nonce:KOUNSELIA.nonce,counselor:curSlug,session_id:curSessionId||0});
  let tokenRes;
  try{
    const r=await fetch(KOUNSELIA.ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:params});
    tokenRes=await r.json();
  }catch(err){
    document.getElementById('call-status').textContent="Couldn't connect, please try again.";
    liveCallConnecting=false;
    setTimeout(endVoiceCall,1800);
    return;
  }
  if(!tokenRes.success){
    document.getElementById('call-status').textContent=(tokenRes.data&&tokenRes.data.message)||"Voice isn't available right now.";
    liveCallConnecting=false;
    setTimeout(endVoiceCall,2200);
    return;
  }

  const data=tokenRes.data;
  liveSessionId=data.session_id||curSessionId;
  curSessionId=liveSessionId;
  liveAllowedSeconds=data.allowed_seconds||300;
  liveSecondsLeft=liveAllowedSeconds;
  document.getElementById('call-timer').textContent=fmtTimer(liveSecondsLeft);
  if(data.plan==='free'){
    document.getElementById('call-plan-note').innerHTML='Free members get '+Math.round(liveAllowedSeconds/60)+' minutes per call. <a href="/dashboard.php#upgrade">Upgrade to Pro</a> for longer sessions.';
  }

  try{
    await connectLiveSession(data.token,data.model);
    liveCallConnecting=false;
  }catch(err){
    document.getElementById('call-status').textContent="Couldn't start the call, please try again.";
    liveCallConnecting=false;
    setTimeout(endVoiceCall,1800);
  }
}

function connectLiveSession(token,model){
  return new Promise((resolve,reject)=>{
    const url='wss://generativelanguage.googleapis.com/ws/google.ai.generativelanguage.v1alpha.GenerativeService.BidiGenerateContentConstrained?access_token='+encodeURIComponent(token);
    liveWs=new WebSocket(url);

    liveWs.onopen=function(){
      liveWs.send(JSON.stringify({setup:{model:'models/'+model}}));
    };

    liveWs.onmessage=async function(evt){
      let msg;
      try{
        const raw=(evt.data instanceof Blob)?await evt.data.text():evt.data;
        msg=JSON.parse(raw);
      }catch(e){return;}

      if(msg.setupComplete){
        liveCallActive=true;
        document.getElementById('call-status').textContent='Listening…';
        startVoiceTimer();
        startMicCapture();
        resolve();
        return;
      }

      if(msg.serverContent){
        const sc=msg.serverContent;
        if(sc.interrupted){
          stopAllPlayback();
          flushBotTranscript();
        }

        if(sc.inputTranscription&&typeof sc.inputTranscription.text==='string'){
          liveUserUtterance=sc.inputTranscription.text;
          liveUserFlushed=false;
        }
        if(sc.outputTranscription&&typeof sc.outputTranscription.text==='string'){
          if(!liveUserFlushed){flushUserTranscript();}
          liveBotUtterance=sc.outputTranscription.text;
          document.getElementById('call-status').textContent='Speaking…';
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
          document.getElementById('call-status').textContent='Listening…';
          document.getElementById('call-ring').classList.remove('speaking');
        }
      }

      if(msg.goAway){
        document.getElementById('call-status').textContent='Call ending…';
        setTimeout(endVoiceCall,1200);
      }
    };

    liveWs.onerror=function(){reject(new Error('ws error'));};
    liveWs.onclose=function(){
      if(liveCallActive){endVoiceCall();}
    };
  });
}

async function startMicCapture(){
  liveAudioCtx=new (window.AudioContext||window.webkitAudioContext)();
  const src=liveAudioCtx.createMediaStreamSource(liveMicStream);

  const workletCode=`
    class PCMCaptureProcessor extends AudioWorkletProcessor {
      constructor(){
        super();
        this.targetRate=16000;
        this.ratio=sampleRate/this.targetRate;
        this.buf=[];
      }
      process(inputs){
        const ch=inputs[0]&&inputs[0][0];
        if(ch){
          for(let i=0;i<ch.length;i++) this.buf.push(ch[i]);
          const outLen=Math.floor(this.buf.length/this.ratio);
          if(outLen>0){
            const out=new Int16Array(outLen);
            for(let i=0;i<outLen;i++){
              let s=this.buf[Math.floor(i*this.ratio)];
              s=Math.max(-1,Math.min(1,s));
              out[i]=s<0?s*0x8000:s*0x7FFF;
            }
            this.buf=this.buf.slice(Math.floor(outLen*this.ratio));
            this.port.postMessage(out.buffer,[out.buffer]);
          }
        }
        return true;
      }
    }
    registerProcessor('pcm-capture-processor',PCMCaptureProcessor);
  `;
  const blobUrl=URL.createObjectURL(new Blob([workletCode],{type:'application/javascript'}));
  await liveAudioCtx.audioWorklet.addModule(blobUrl);

  liveWorkletNode=new AudioWorkletNode(liveAudioCtx,'pcm-capture-processor');
  liveWorkletNode.port.onmessage=function(e){
    if(liveMuted||!liveWs||liveWs.readyState!==WebSocket.OPEN)return;
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
  if(!livePlaybackCtx){
    livePlaybackCtx=new (window.AudioContext||window.webkitAudioContext)();
    liveNextStartTime=livePlaybackCtx.currentTime;
  }
  const binary=atob(base64Pcm);
  const bytes=new Uint8Array(binary.length);
  for(let i=0;i<binary.length;i++) bytes[i]=binary.charCodeAt(i);
  const int16=new Int16Array(bytes.buffer);
  const float32=new Float32Array(int16.length);
  for(let i=0;i<int16.length;i++) float32[i]=int16[i]/(int16[i]<0?0x8000:0x7FFF);

  const buffer=livePlaybackCtx.createBuffer(1,float32.length,24000);
  buffer.copyToChannel(float32,0);

  const source=livePlaybackCtx.createBufferSource();
  source.buffer=buffer;
  source.connect(livePlaybackCtx.destination);

  const startAt=Math.max(livePlaybackCtx.currentTime,liveNextStartTime);
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
      document.getElementById('call-status').textContent="Time's up";
      endVoiceCall();
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

function endVoiceCall(){
  liveCallActive=false;
  liveCallConnecting=false;
  clearInterval(liveTimerHandle);
  flushUserTranscript();
  flushBotTranscript();
  stopAllPlayback();

  if(liveWs){ try{liveWs.close();}catch(e){} liveWs=null; }
  if(liveWorkletNode){ try{liveWorkletNode.disconnect();}catch(e){} liveWorkletNode=null; }
  if(liveAudioCtx){ try{liveAudioCtx.close();}catch(e){} liveAudioCtx=null; }
  if(livePlaybackCtx){ try{livePlaybackCtx.close();}catch(e){} livePlaybackCtx=null; }
  if(liveMicStream){ liveMicStream.getTracks().forEach(t=>t.stop()); liveMicStream=null; }
  liveNextStartTime=0;

  document.getElementById('call-overlay').classList.remove('active');
  document.getElementById('call-ring').classList.remove('speaking');
}
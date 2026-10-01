import { useCallback, useEffect, useRef, useState } from 'react';
import { fetchVoiceToken, logVoiceTurn } from '@kounselia/core';
import type { KounseliaConfig } from '@kounselia/core';

// Talks directly to Gemini's realtime voice websocket from the browser,
// using a short-lived token the backend hands out. This whole hook is
// Web Audio / WebSocket plumbing, so it's browser-only — the mobile app
// has its own version (mobile/src/audio/useVoiceCall.ts) and both reuse
// core/voiceCall.ts (the token fetch + turn logging) unchanged.

const MIC_RATE = 16000; // what the live voice service expects from us
const VOICE_RATE = 24000; // what it sends back

// Turns the microphone's sound (usually 44.1 or 48 kHz) into 16 kHz
// pieces. Each output sample is the average of the input samples it
// covers rather than just one of them, which keeps speech clearer for
// the service's speech recognition (picking single samples adds a hiss
// of "folded back" high frequencies).
const PCM_CAPTURE_WORKLET = `
class PCMCaptureProcessor extends AudioWorkletProcessor {
  constructor(){
    super();
    this.ratio=sampleRate/${MIC_RATE};
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

function arrayBufferToBase64(buf: ArrayBuffer): string {
  let binary = '';
  const bytes = new Uint8Array(buf);
  for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
  return btoa(binary);
}

function formatTimer(seconds: number): string {
  const s = Math.max(0, Math.floor(seconds));
  const m = Math.floor(s / 60);
  const sec = s % 60;
  return `${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')}`;
}

function newAudioContext(): AudioContext {
  return new (window.AudioContext || (window as any).webkitAudioContext)();
}

export type CallStatus = 'idle' | 'connecting' | 'listening' | 'speaking' | 'ended';

interface UseVoiceCallOptions {
  config: KounseliaConfig;
  counselorSlug: string;
  getSessionId: () => number;
  onSessionId: (id: number) => void;
}

export function useVoiceCall({ config, counselorSlug, getSessionId, onSessionId }: UseVoiceCallOptions) {
  const [status, setStatus] = useState<CallStatus>('idle');
  const [statusText, setStatusText] = useState('');
  const [timerText, setTimerText] = useState('00:00');
  const [caption, setCaption] = useState('');
  const [muted, setMuted] = useState(false);
  const [freeCallMinutes, setFreeCallMinutes] = useState<number | null>(null);

  const wsRef = useRef<WebSocket | null>(null);
  const micStreamRef = useRef<MediaStream | null>(null);
  const audioCtxRef = useRef<AudioContext | null>(null);
  const playbackCtxRef = useRef<AudioContext | null>(null);
  const workletNodeRef = useRef<AudioWorkletNode | null>(null);
  const playbackQueueRef = useRef<AudioBufferSourceNode[]>([]);
  const nextStartTimeRef = useRef(0);
  const mutedRef = useRef(false);
  const sessionIdRef = useRef(0);
  const secondsLeftRef = useRef(0);
  const timerHandleRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const userUtteranceRef = useRef('');
  const botUtteranceRef = useRef('');
  const userFlushedRef = useRef(true);
  const activeRef = useRef(false);
  const connectingRef = useRef(false);
  const latest = useRef({ config, counselorSlug, getSessionId, onSessionId });
  useEffect(() => {
    latest.current = { config, counselorSlug, getSessionId, onSessionId };
  });

  const stopAllPlayback = useCallback(() => {
    playbackQueueRef.current.forEach((s) => {
      try {
        s.stop();
      } catch {
        // already stopped
      }
    });
    playbackQueueRef.current = [];
    if (playbackCtxRef.current) nextStartTimeRef.current = playbackCtxRef.current.currentTime;
  }, []);

  const flushUserTranscript = useCallback(() => {
    if (userUtteranceRef.current.trim()) {
      logVoiceTurn(latest.current.config, sessionIdRef.current, 'user', userUtteranceRef.current);
    }
    userUtteranceRef.current = '';
    userFlushedRef.current = true;
  }, []);

  const flushBotTranscript = useCallback(() => {
    if (botUtteranceRef.current.trim()) {
      logVoiceTurn(latest.current.config, sessionIdRef.current, 'bot', botUtteranceRef.current);
    }
    botUtteranceRef.current = '';
  }, []);

  // Stops everything the call holds (connection, microphone, sound).
  // Safe to call more than once.
  const teardown = useCallback(() => {
    activeRef.current = false;
    connectingRef.current = false;
    if (timerHandleRef.current) clearInterval(timerHandleRef.current);
    timerHandleRef.current = null;
    flushUserTranscript();
    flushBotTranscript();
    stopAllPlayback();

    if (wsRef.current) {
      // Cleared before closing so this socket's onclose knows it's stale.
      const ws = wsRef.current;
      wsRef.current = null;
      try {
        ws.close();
      } catch {
        // ignore
      }
    }
    if (workletNodeRef.current) {
      try {
        workletNodeRef.current.port.onmessage = null;
        workletNodeRef.current.disconnect();
      } catch {
        // ignore
      }
      workletNodeRef.current = null;
    }
    if (audioCtxRef.current) {
      audioCtxRef.current.close().catch(() => undefined);
      audioCtxRef.current = null;
    }
    if (playbackCtxRef.current) {
      playbackCtxRef.current.close().catch(() => undefined);
      playbackCtxRef.current = null;
    }
    if (micStreamRef.current) {
      micStreamRef.current.getTracks().forEach((t) => t.stop());
      micStreamRef.current = null;
    }
    nextStartTimeRef.current = 0;
  }, [flushBotTranscript, flushUserTranscript, stopAllPlayback]);

  const endCall = useCallback(() => {
    teardown();
    setStatus('ended');
  }, [teardown]);

  const schedulePlayback = useCallback((base64Pcm: string) => {
    const ctx = playbackCtxRef.current;
    if (!ctx) return; // Call ended meanwhile.
    if (ctx.state === 'suspended') ctx.resume().catch(() => undefined);
    const binary = atob(base64Pcm);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
    const int16 = new Int16Array(bytes.buffer, 0, bytes.length >> 1);
    const float32 = new Float32Array(int16.length);
    for (let i = 0; i < int16.length; i++) float32[i] = int16[i] / (int16[i] < 0 ? 0x8000 : 0x7fff);
    if (!float32.length) return;

    const buffer = ctx.createBuffer(1, float32.length, VOICE_RATE);
    buffer.getChannelData(0).set(float32);

    const source = ctx.createBufferSource();
    source.buffer = buffer;
    source.connect(ctx.destination);

    // Queue each piece right after the previous one so speech is smooth.
    const startAt = Math.max(ctx.currentTime, nextStartTimeRef.current);
    source.start(startAt);
    nextStartTimeRef.current = startAt + buffer.duration;
    playbackQueueRef.current.push(source);
    source.onended = () => {
      playbackQueueRef.current = playbackQueueRef.current.filter((s) => s !== source);
    };
  }, []);

  // Wires the microphone into the 16 kHz converter. Pieces are only sent
  // once the call is live (and not muted).
  const startMicCapture = useCallback(async (ctx: AudioContext, stream: MediaStream) => {
    const blobUrl = URL.createObjectURL(new Blob([PCM_CAPTURE_WORKLET], { type: 'application/javascript' }));
    try {
      await ctx.audioWorklet.addModule(blobUrl);
    } finally {
      URL.revokeObjectURL(blobUrl);
    }
    if (audioCtxRef.current !== ctx) return; // Call ended meanwhile.

    const src = ctx.createMediaStreamSource(stream);
    const worklet = new AudioWorkletNode(ctx, 'pcm-capture-processor');
    worklet.port.onmessage = (e) => {
      const ws = wsRef.current;
      if (!activeRef.current || mutedRef.current || !ws || ws.readyState !== WebSocket.OPEN) return;
      const b64 = arrayBufferToBase64(e.data);
      ws.send(JSON.stringify({ realtimeInput: { audio: { data: b64, mimeType: `audio/pcm;rate=${MIC_RATE}` } } }));
    };
    src.connect(worklet);
    workletNodeRef.current = worklet;
  }, []);

  const startTimer = useCallback(() => {
    if (timerHandleRef.current) clearInterval(timerHandleRef.current);
    timerHandleRef.current = setInterval(() => {
      secondsLeftRef.current -= 1;
      setTimerText(formatTimer(secondsLeftRef.current));
      if (secondsLeftRef.current <= 0) {
        setStatusText("Time's up");
        endCall();
      }
    }, 1000);
  }, [endCall]);

  const connectLiveSession = useCallback(
    (token: string, model: string) =>
      new Promise<void>((resolve, reject) => {
        const url = `wss://generativelanguage.googleapis.com/ws/google.ai.generativelanguage.v1alpha.GenerativeService.BidiGenerateContentConstrained?access_token=${encodeURIComponent(token)}`;
        const ws = new WebSocket(url);
        wsRef.current = ws;

        ws.onopen = () => {
          ws.send(JSON.stringify({ setup: { model: `models/${model}` } }));
        };

        ws.onmessage = async (evt) => {
          if (wsRef.current !== ws) return; // From a call that already ended.
          let msg: any;
          try {
            const raw = evt.data instanceof Blob ? await evt.data.text() : evt.data;
            msg = JSON.parse(raw);
          } catch {
            return;
          }

          if (msg.setupComplete) {
            activeRef.current = true;
            setStatus('listening');
            setStatusText('Listening…');
            startTimer();
            resolve();
            return;
          }

          const sc = msg.serverContent;
          if (sc) {
            if (sc.interrupted) {
              // The member started talking over the counselor.
              stopAllPlayback();
              flushBotTranscript();
            }
            if (typeof sc.inputTranscription?.text === 'string') {
              // Each message carries the next chunk of speech, not the
              // whole thing said so far, so the chunks must be joined.
              // Otherwise only the last few words are kept, the caption
              // flickers word by word, and the chat history gets
              // fragments instead of what was actually said.
              userUtteranceRef.current += sc.inputTranscription.text;
              userFlushedRef.current = false;
            }
            if (typeof sc.outputTranscription?.text === 'string') {
              if (!userFlushedRef.current) flushUserTranscript();
              botUtteranceRef.current += sc.outputTranscription.text;
              setStatus('speaking');
              setStatusText('Speaking…');
              setCaption(botUtteranceRef.current);
            }
            if (sc.modelTurn?.parts) {
              if (!userFlushedRef.current) flushUserTranscript();
              sc.modelTurn.parts.forEach((p: any) => {
                if (p.inlineData?.data) schedulePlayback(p.inlineData.data);
              });
            }
            if (sc.turnComplete) {
              flushBotTranscript();
              setStatus('listening');
              setStatusText('Listening…');
            }
          }

          if (msg.goAway) {
            setStatusText('Call ending…');
            setTimeout(() => {
              if (wsRef.current === ws) endCall();
            }, 1200);
          }
        };

        ws.onerror = () => reject(new Error('ws error'));
        ws.onclose = () => {
          if (wsRef.current !== ws) return; // We closed it ourselves.
          if (activeRef.current) endCall();
          // Closed before the call started (e.g. the pass was refused):
          // give up rather than stay on "Connecting…" forever.
          else reject(new Error('closed'));
        };
      }),
    [endCall, flushBotTranscript, flushUserTranscript, schedulePlayback, startTimer, stopAllPlayback],
  );

  // Must be called straight from the member's tap: browsers (Safari on
  // iPhone and iPad especially) only let sound start, and the microphone
  // feed flow, for audio set up during a tap. Setting the audio up later,
  // after waiting on the network, left those calls silent both ways.
  const startCall = useCallback(async () => {
    if (activeRef.current || connectingRef.current) return;
    setCaption('');
    setTimerText('00:00');
    setFreeCallMinutes(null);
    setMuted(false);
    mutedRef.current = false;
    userUtteranceRef.current = '';
    botUtteranceRef.current = '';
    userFlushedRef.current = true;

    if (!navigator.mediaDevices?.getUserMedia || !window.AudioWorklet || !window.WebSocket) {
      setStatus('connecting');
      setStatusText('Voice calls need a modern browser (Chrome, Edge, or Safari) with microphone support.');
      setTimeout(endCall, 3000);
      return;
    }

    connectingRef.current = true;
    setStatus('connecting');
    setStatusText('Connecting…');

    const micCtx = newAudioContext();
    const playbackCtx = newAudioContext();
    micCtx.resume().catch(() => undefined);
    playbackCtx.resume().catch(() => undefined);
    audioCtxRef.current = micCtx;
    playbackCtxRef.current = playbackCtx;
    nextStartTimeRef.current = playbackCtx.currentTime;

    // Shows why the call couldn't go ahead, then closes the call screen.
    const giveUp = (text: string, delay = 2200) => {
      teardown();
      setStatusText(text);
      setTimeout(endCall, delay);
    };
    // The member hung up while we were waiting on something.
    const hungUp = () => !connectingRef.current;

    let stream: MediaStream;
    try {
      stream = await navigator.mediaDevices.getUserMedia({
        audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true, autoGainControl: true },
      });
    } catch {
      if (!hungUp()) giveUp('Microphone access is blocked. Allow it in your browser’s site settings, then try again.', 3000);
      return;
    }
    if (hungUp()) {
      stream.getTracks().forEach((t) => t.stop());
      return;
    }
    micStreamRef.current = stream;

    try {
      await startMicCapture(micCtx, stream);
    } catch {
      if (!hungUp()) giveUp("Couldn't use your microphone, please try again.");
      return;
    }
    if (hungUp()) return;

    const { config: cfg, counselorSlug: slug, getSessionId: getId, onSessionId: setId } = latest.current;
    const tokenRes = await fetchVoiceToken(cfg, slug, getId());
    if (hungUp()) return;
    if (!tokenRes.success || !tokenRes.token || !tokenRes.model) {
      giveUp(tokenRes.message || "Voice isn't available right now.");
      return;
    }

    sessionIdRef.current = tokenRes.sessionId || getId();
    setId(sessionIdRef.current);
    secondsLeftRef.current = tokenRes.allowedSeconds || 300;
    setTimerText(formatTimer(secondsLeftRef.current));
    if (tokenRes.plan === 'free') {
      setFreeCallMinutes(Math.round(secondsLeftRef.current / 60));
    }

    try {
      await connectLiveSession(tokenRes.token, tokenRes.model);
      connectingRef.current = false;
    } catch {
      if (!hungUp()) giveUp("Couldn't start the call, please try again.", 1800);
    }
  }, [connectLiveSession, endCall, startMicCapture, teardown]);

  const toggleMute = useCallback(() => {
    setMuted((m) => {
      mutedRef.current = !m;
      return !m;
    });
  }, []);

  // Leaving the conversation hangs up, so the microphone never stays on
  // with no call screen showing.
  useEffect(() => () => teardown(), [teardown]);

  return { status, statusText, timerText, caption, muted, freeCallMinutes, startCall, endCall, toggleMute };
}

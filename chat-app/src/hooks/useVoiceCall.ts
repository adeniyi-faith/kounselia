import { useCallback, useRef, useState } from 'react';
import { fetchVoiceToken, logVoiceTurn } from '@kounselia/core';
import type { KounseliaConfig } from '@kounselia/core';

// Talks directly to Gemini's realtime voice websocket from the browser,
// using a short-lived token the backend hands out. This whole hook is
// Web Audio / WebSocket plumbing, so it's browser-only — a React Native
// build would need its own version using its own audio APIs, but would
// reuse core/voiceCall.ts (the token fetch + turn logging) unchanged.

const PCM_CAPTURE_WORKLET = `
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
  const allowedSecondsRef = useRef(0);
  const secondsLeftRef = useRef(0);
  const timerHandleRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const userUtteranceRef = useRef('');
  const botUtteranceRef = useRef('');
  const userFlushedRef = useRef(true);
  const activeRef = useRef(false);
  const connectingRef = useRef(false);

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
      logVoiceTurn(config, sessionIdRef.current, 'user', userUtteranceRef.current);
    }
    userUtteranceRef.current = '';
    userFlushedRef.current = true;
  }, [config]);

  const flushBotTranscript = useCallback(() => {
    if (botUtteranceRef.current.trim()) {
      logVoiceTurn(config, sessionIdRef.current, 'bot', botUtteranceRef.current);
    }
    botUtteranceRef.current = '';
  }, [config]);

  const endCall = useCallback(() => {
    activeRef.current = false;
    connectingRef.current = false;
    if (timerHandleRef.current) clearInterval(timerHandleRef.current);
    flushUserTranscript();
    flushBotTranscript();
    stopAllPlayback();

    if (wsRef.current) {
      try {
        wsRef.current.close();
      } catch {
        // ignore
      }
      wsRef.current = null;
    }
    if (workletNodeRef.current) {
      try {
        workletNodeRef.current.disconnect();
      } catch {
        // ignore
      }
      workletNodeRef.current = null;
    }
    if (audioCtxRef.current) {
      try {
        audioCtxRef.current.close();
      } catch {
        // ignore
      }
      audioCtxRef.current = null;
    }
    if (playbackCtxRef.current) {
      try {
        playbackCtxRef.current.close();
      } catch {
        // ignore
      }
      playbackCtxRef.current = null;
    }
    if (micStreamRef.current) {
      micStreamRef.current.getTracks().forEach((t) => t.stop());
      micStreamRef.current = null;
    }
    nextStartTimeRef.current = 0;
    setStatus('ended');
  }, [flushBotTranscript, flushUserTranscript, stopAllPlayback]);

  const schedulePlayback = useCallback((base64Pcm: string) => {
    if (!playbackCtxRef.current) {
      playbackCtxRef.current = new (window.AudioContext || (window as any).webkitAudioContext)();
      nextStartTimeRef.current = playbackCtxRef.current.currentTime;
    }
    const ctx = playbackCtxRef.current;
    const binary = atob(base64Pcm);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
    const int16 = new Int16Array(bytes.buffer);
    const float32 = new Float32Array(int16.length);
    for (let i = 0; i < int16.length; i++) float32[i] = int16[i] / (int16[i] < 0 ? 0x8000 : 0x7fff);

    const buffer = ctx.createBuffer(1, float32.length, 24000);
    buffer.copyToChannel(float32, 0);

    const source = ctx.createBufferSource();
    source.buffer = buffer;
    source.connect(ctx.destination);

    const startAt = Math.max(ctx.currentTime, nextStartTimeRef.current);
    source.start(startAt);
    nextStartTimeRef.current = startAt + buffer.duration;
    playbackQueueRef.current.push(source);
    source.onended = () => {
      playbackQueueRef.current = playbackQueueRef.current.filter((s) => s !== source);
    };
  }, []);

  const startMicCapture = useCallback(async () => {
    const ctx = new (window.AudioContext || (window as any).webkitAudioContext)();
    audioCtxRef.current = ctx;
    const src = ctx.createMediaStreamSource(micStreamRef.current!);

    const blobUrl = URL.createObjectURL(new Blob([PCM_CAPTURE_WORKLET], { type: 'application/javascript' }));
    await ctx.audioWorklet.addModule(blobUrl);

    const worklet = new AudioWorkletNode(ctx, 'pcm-capture-processor');
    worklet.port.onmessage = (e) => {
      if (mutedRef.current || !wsRef.current || wsRef.current.readyState !== WebSocket.OPEN) return;
      const b64 = arrayBufferToBase64(e.data);
      wsRef.current.send(JSON.stringify({ realtimeInput: { audio: { data: b64, mimeType: 'audio/pcm;rate=16000' } } }));
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
            startMicCapture();
            resolve();
            return;
          }

          if (msg.serverContent) {
            const sc = msg.serverContent;
            if (sc.interrupted) {
              stopAllPlayback();
              flushBotTranscript();
            }
            if (sc.inputTranscription && typeof sc.inputTranscription.text === 'string') {
              userUtteranceRef.current = sc.inputTranscription.text;
              userFlushedRef.current = false;
            }
            if (sc.outputTranscription && typeof sc.outputTranscription.text === 'string') {
              if (!userFlushedRef.current) flushUserTranscript();
              botUtteranceRef.current = sc.outputTranscription.text;
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
            setTimeout(endCall, 1200);
          }
        };

        ws.onerror = () => reject(new Error('ws error'));
        ws.onclose = () => {
          if (activeRef.current) endCall();
        };
      }),
    [endCall, flushBotTranscript, flushUserTranscript, schedulePlayback, startMicCapture, startTimer, stopAllPlayback],
  );

  const startCall = useCallback(async () => {
    if (activeRef.current || connectingRef.current) return;
    connectingRef.current = true;
    setStatus('connecting');
    setStatusText('Connecting…');
    setCaption('');
    setTimerText('00:00');
    setFreeCallMinutes(null);
    setMuted(false);
    mutedRef.current = false;

    if (!('mediaDevices' in navigator) || !window.AudioWorklet || !window.WebSocket) {
      setStatusText('Voice calls need a modern browser (Chrome, Edge, or Safari) with microphone support.');
      connectingRef.current = false;
      return;
    }

    try {
      micStreamRef.current = await navigator.mediaDevices.getUserMedia({
        audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true },
      });
    } catch {
      setStatusText('Microphone access was denied.');
      connectingRef.current = false;
      setTimeout(endCall, 1800);
      return;
    }

    const tokenRes = await fetchVoiceToken(config, counselorSlug, getSessionId());
    if (!tokenRes.success || !tokenRes.token || !tokenRes.model) {
      setStatusText(tokenRes.message || "Voice isn't available right now.");
      connectingRef.current = false;
      setTimeout(endCall, 2200);
      return;
    }

    sessionIdRef.current = tokenRes.sessionId || getSessionId();
    onSessionId(sessionIdRef.current);
    allowedSecondsRef.current = tokenRes.allowedSeconds || 300;
    secondsLeftRef.current = allowedSecondsRef.current;
    setTimerText(formatTimer(secondsLeftRef.current));
    if (tokenRes.plan === 'free') {
      setFreeCallMinutes(Math.round(allowedSecondsRef.current / 60));
    }

    try {
      await connectLiveSession(tokenRes.token, tokenRes.model);
      connectingRef.current = false;
    } catch {
      setStatusText("Couldn't start the call, please try again.");
      connectingRef.current = false;
      setTimeout(endCall, 1800);
    }
  }, [config, connectLiveSession, counselorSlug, endCall, getSessionId, onSessionId]);

  const toggleMute = useCallback(() => {
    setMuted((m) => {
      mutedRef.current = !m;
      return !m;
    });
  }, []);

  return { status, statusText, timerText, caption, muted, freeCallMinutes, startCall, endCall, toggleMute };
}

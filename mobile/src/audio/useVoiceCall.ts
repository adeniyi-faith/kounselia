// A live voice conversation with a counselor, ported from the website's
// chat-app/src/hooks/useVoiceCall.ts. The server hands out a short-lived
// pass (kounselia_voice_token) for Google's live voice service; the app
// then talks to that service directly over a WebSocket (a connection that
// stays open both ways): the microphone goes up in small pieces of 16 kHz
// sound, the counselor's voice comes back in pieces of 24 kHz sound and is
// played as it arrives. What each side said is saved to the conversation
// (kounselia_log_voice_turn) so it shows up in the chat history too.
import { fetchVoiceToken, logVoiceTurn, type KounseliaConfig } from '@kounselia/core';
import { useCallback, useEffect, useRef, useState } from 'react';
import { AudioContext, AudioRecorder, type AudioBufferSourceNode } from 'react-native-audio-api';
import { base64ToBytes, floatToPcm16Base64, utf8Decode } from './bytes';
import { micAllowed, releaseSound, setSoundMode } from './session';

export type CallStatus = 'idle' | 'connecting' | 'listening' | 'speaking' | 'ended';

const MIC_RATE = 16000; // what the live voice service expects from us
const VOICE_RATE = 24000; // what it sends back

function formatTimer(seconds: number): string {
  const s = Math.max(0, Math.floor(seconds));
  return `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;
}

interface Options {
  config: KounseliaConfig;
  counselorSlug: string;
  getSessionId: () => number;
  onSessionId: (id: number) => void;
}

export function useVoiceCall({ config, counselorSlug, getSessionId, onSessionId }: Options) {
  const [status, setStatus] = useState<CallStatus>('idle');
  const [statusText, setStatusText] = useState('');
  const [timerText, setTimerText] = useState('00:00');
  const [caption, setCaption] = useState('');
  const [muted, setMuted] = useState(false);
  const [freeCallMinutes, setFreeCallMinutes] = useState<number | null>(null);

  const ws = useRef<WebSocket | null>(null);
  const recorder = useRef<AudioRecorder | null>(null);
  const playback = useRef<AudioContext | null>(null);
  const playing = useRef<AudioBufferSourceNode[]>([]);
  const nextStart = useRef(0);
  const mutedRef = useRef(false);
  const sessionId = useRef(0);
  const secondsLeft = useRef(0);
  const timer = useRef<ReturnType<typeof setInterval> | null>(null);
  const userSaid = useRef('');
  const botSaid = useRef('');
  const userFlushed = useRef(true);
  const active = useRef(false);
  const connecting = useRef(false);
  const latest = useRef({ config, counselorSlug, getSessionId, onSessionId });
  latest.current = { config, counselorSlug, getSessionId, onSessionId };

  const stopPlayback = useCallback(() => {
    playing.current.forEach((node) => {
      try {
        node.stop();
      } catch {
        // Already finished.
      }
    });
    playing.current = [];
    if (playback.current) nextStart.current = playback.current.currentTime;
  }, []);

  const flushUser = useCallback(() => {
    if (userSaid.current.trim()) logVoiceTurn(latest.current.config, sessionId.current, 'user', userSaid.current);
    userSaid.current = '';
    userFlushed.current = true;
  }, []);

  const flushBot = useCallback(() => {
    if (botSaid.current.trim()) logVoiceTurn(latest.current.config, sessionId.current, 'bot', botSaid.current);
    botSaid.current = '';
  }, []);

  const endCall = useCallback(() => {
    const wasLive = active.current || connecting.current || ws.current !== null;
    active.current = false;
    connecting.current = false;
    if (timer.current) clearInterval(timer.current);
    timer.current = null;
    flushUser();
    flushBot();
    stopPlayback();
    if (ws.current) {
      const socket = ws.current;
      ws.current = null;
      try {
        socket.close();
      } catch {
        // Already closed.
      }
    }
    if (recorder.current) {
      const rec = recorder.current;
      recorder.current = null;
      rec.clearOnAudioReady();
      rec.stop().catch(() => undefined);
    }
    if (playback.current) {
      playback.current.close().catch(() => undefined);
      playback.current = null;
    }
    nextStart.current = 0;
    if (wasLive) releaseSound();
    setStatus('ended');
  }, [flushBot, flushUser, stopPlayback]);

  const play = useCallback(async (base64Pcm: string) => {
    if (!playback.current) {
      playback.current = new AudioContext({ sampleRate: VOICE_RATE });
      nextStart.current = playback.current.currentTime;
    }
    const ctx = playback.current;
    try {
      const buffer = await ctx.decodePCMInBase64(base64Pcm, VOICE_RATE, 1);
      if (playback.current !== ctx) return; // Call ended meanwhile.
      const node = ctx.createBufferSource();
      node.buffer = buffer;
      node.connect(ctx.destination);
      // Queue each piece right after the previous one so speech is smooth.
      const startAt = Math.max(ctx.currentTime, nextStart.current);
      node.start(startAt);
      nextStart.current = startAt + buffer.duration;
      playing.current.push(node);
      node.onEnded = () => {
        playing.current = playing.current.filter((n) => n !== node);
      };
    } catch {
      // Skip a piece that can't be decoded rather than ending the call.
    }
  }, []);

  const startMicrophone = useCallback(() => {
    const rec = new AudioRecorder();
    rec.onAudioReady({ sampleRate: MIC_RATE, bufferLength: MIC_RATE / 10, channelCount: 1 }, ({ buffer }) => {
      const socket = ws.current;
      if (mutedRef.current || !socket || socket.readyState !== WebSocket.OPEN) return;
      // The phone may not give exactly 16 kHz; convert if it doesn't.
      const data = floatToPcm16Base64(buffer.getChannelData(0), buffer.sampleRate, MIC_RATE);
      socket.send(JSON.stringify({ realtimeInput: { audio: { data, mimeType: `audio/pcm;rate=${MIC_RATE}` } } }));
    });
    recorder.current = rec;
    return rec.start();
  }, []);

  const startTimer = useCallback(() => {
    if (timer.current) clearInterval(timer.current);
    timer.current = setInterval(() => {
      secondsLeft.current -= 1;
      setTimerText(formatTimer(secondsLeft.current));
      if (secondsLeft.current <= 0) {
        setStatusText("Time's up");
        endCall();
      }
    }, 1000);
  }, [endCall]);

  const connect = useCallback(
    (token: string, model: string) =>
      new Promise<void>((resolve, reject) => {
        const url = `wss://generativelanguage.googleapis.com/ws/google.ai.generativelanguage.v1alpha.GenerativeService.BidiGenerateContentConstrained?access_token=${encodeURIComponent(token)}`;
        const socket = new WebSocket(url);
        socket.binaryType = 'arraybuffer';
        ws.current = socket;

        socket.onopen = () => socket.send(JSON.stringify({ setup: { model: `models/${model}` } }));

        socket.onmessage = async (event) => {
          let msg: any;
          try {
            const raw = typeof event.data === 'string' ? event.data : utf8Decode(new Uint8Array(event.data as ArrayBuffer));
            msg = JSON.parse(raw);
          } catch {
            return;
          }

          if (msg.setupComplete) {
            const started = await startMicrophone();
            if (started.status !== 'success') {
              reject(new Error('microphone'));
              return;
            }
            active.current = true;
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
              stopPlayback();
              flushBot();
            }
            if (typeof sc.inputTranscription?.text === 'string') {
              userSaid.current = sc.inputTranscription.text;
              userFlushed.current = false;
            }
            if (typeof sc.outputTranscription?.text === 'string') {
              if (!userFlushed.current) flushUser();
              botSaid.current = sc.outputTranscription.text;
              setStatus('speaking');
              setStatusText('Speaking…');
              setCaption(botSaid.current);
            }
            if (sc.modelTurn?.parts) {
              if (!userFlushed.current) flushUser();
              for (const part of sc.modelTurn.parts) {
                if (part.inlineData?.data) play(part.inlineData.data);
              }
            }
            if (sc.turnComplete) {
              flushBot();
              setStatus('listening');
              setStatusText('Listening…');
            }
          }

          if (msg.goAway) {
            setStatusText('Call ending…');
            setTimeout(endCall, 1200);
          }
        };

        socket.onerror = () => reject(new Error('connection'));
        socket.onclose = () => {
          if (active.current) endCall();
        };
      }),
    [endCall, flushBot, flushUser, play, startMicrophone, startTimer, stopPlayback],
  );

  const startCall = useCallback(async () => {
    if (active.current || connecting.current) return;
    connecting.current = true;
    setStatus('connecting');
    setStatusText('Connecting…');
    setCaption('');
    setTimerText('00:00');
    setFreeCallMinutes(null);
    setMuted(false);
    mutedRef.current = false;

    const giveUp = (text: string) => {
      setStatusText(text);
      connecting.current = false;
      setTimeout(endCall, 2200);
    };

    if (!(await micAllowed())) {
      giveUp('Microphone access is off. You can allow it in your phone’s Settings.');
      return;
    }

    const { config: cfg, counselorSlug: slug, getSessionId: getId, onSessionId: setId } = latest.current;
    const pass = await fetchVoiceToken(cfg, slug, getId());
    if (!pass.success || !pass.token || !pass.model) {
      giveUp(pass.message || "Voice isn't available right now.");
      return;
    }
    if (!connecting.current) return; // Hung up while connecting.

    sessionId.current = pass.sessionId || getId();
    setId(sessionId.current);
    secondsLeft.current = pass.allowedSeconds || 300;
    setTimerText(formatTimer(secondsLeft.current));
    if (pass.plan === 'free') setFreeCallMinutes(Math.round(secondsLeft.current / 60));

    try {
      await setSoundMode('call');
      await connect(pass.token, pass.model);
      connecting.current = false;
    } catch {
      giveUp("Couldn't start the call, please try again.");
    }
  }, [connect, endCall]);

  const toggleMute = useCallback(() => {
    setMuted((m) => {
      mutedRef.current = !m;
      return !m;
    });
  }, []);

  // Leaving the screen hangs up.
  useEffect(() => () => endCall(), [endCall]);

  return { status, statusText, timerText, caption, muted, freeCallMinutes, startCall, endCall, toggleMute };
}

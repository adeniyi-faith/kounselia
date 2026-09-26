// "Listen to this reply": asks the server to read a counselor reply aloud
// in the counselor's own voice (kounselia_tts — it returns a WAV file) and
// plays it. Only one reply plays at a time; tapping again stops it.
import { fetchVoiceAudio, type KounseliaConfig } from '@kounselia/core';
import { useCallback, useEffect, useRef, useState } from 'react';
import { AudioContext, type AudioBufferSourceNode } from 'react-native-audio-api';
import { base64ToBytes } from './bytes';
import { releaseSound, setSoundMode } from './session';

export type PlayState = { messageId: number; phase: 'loading' | 'playing' } | null;

export function useReplyPlayer(config: KounseliaConfig, onError: (message: string) => void) {
  const [state, setState] = useState<PlayState>(null);
  const context = useRef<AudioContext | null>(null);
  const source = useRef<AudioBufferSourceNode | null>(null);
  const request = useRef(0);
  const latest = useRef({ config, onError });
  useEffect(() => {
    latest.current = { config, onError };
  });

  const stop = useCallback(() => {
    request.current += 1;
    try {
      source.current?.stop();
    } catch {
      // Already finished.
    }
    source.current = null;
    setState(null);
  }, []);

  const toggle = useCallback(
    async (messageId: number) => {
      const wasThis = state?.messageId === messageId;
      stop();
      if (wasThis) return;

      const mine = request.current;
      setState({ messageId, phase: 'loading' });
      const dataUri = await fetchVoiceAudio(latest.current.config, messageId);
      if (mine !== request.current) return; // Tapped something else meanwhile.
      if (!dataUri) {
        setState(null);
        latest.current.onError("Couldn't play this message. Please check your connection and try again.");
        return;
      }
      try {
        await setSoundMode('listen');
        if (!context.current) context.current = new AudioContext();
        const ctx = context.current;
        const bytes = base64ToBytes(dataUri.slice(dataUri.indexOf(',') + 1));
        const buffer = await ctx.decodeAudioData(bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength) as ArrayBuffer);
        if (mine !== request.current) return;
        const node = ctx.createBufferSource();
        node.buffer = buffer;
        node.connect(ctx.destination);
        node.onEnded = () => {
          if (source.current === node) {
            source.current = null;
            setState(null);
            releaseSound();
          }
        };
        source.current = node;
        node.start();
        setState({ messageId, phase: 'playing' });
      } catch {
        setState(null);
        latest.current.onError("Couldn't play this message.");
      }
    },
    [state, stop],
  );

  useEffect(
    () => () => {
      stop();
      context.current?.close();
      context.current = null;
    },
    [stop],
  );

  return { state, toggle, stop };
}

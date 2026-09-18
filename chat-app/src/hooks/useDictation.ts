import { useCallback, useRef, useState } from 'react';
import { transcribeAudio } from '../core/transcription';
import type { KounseliaConfig } from '../core/types';

export type DictationState = 'idle' | 'recording' | 'transcribing';

// Wraps the browser's microphone recording API. This is inherently
// browser-specific (React Native records audio a different way), so
// unlike src/core/ this hook stays in the web app.
export function useDictation(config: KounseliaConfig, onTranscript: (text: string) => void) {
  const [state, setState] = useState<DictationState>('idle');
  const recorderRef = useRef<MediaRecorder | null>(null);
  const chunksRef = useRef<Blob[]>([]);
  const streamRef = useRef<MediaStream | null>(null);
  const abortingRef = useRef(false);

  const stop = useCallback((abort: boolean) => {
    abortingRef.current = abort;
    if (recorderRef.current && recorderRef.current.state === 'recording') {
      try {
        recorderRef.current.stop();
      } catch {
        // recorder already stopped
      }
    }
    setState((s) => (s === 'recording' ? 'idle' : s));
  }, []);

  const start = useCallback(async () => {
    if (!navigator.mediaDevices?.getUserMedia) {
      alert("Voice input isn't supported in this browser yet. Try Chrome, Edge, or Safari.");
      return;
    }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      streamRef.current = stream;
      const mimeType = MediaRecorder.isTypeSupported('audio/webm')
        ? 'audio/webm'
        : MediaRecorder.isTypeSupported('audio/mp4')
          ? 'audio/mp4'
          : '';
      const recorder = new MediaRecorder(stream, mimeType ? { mimeType } : undefined);
      chunksRef.current = [];
      abortingRef.current = false;

      recorder.ondataavailable = (e) => {
        if (e.data.size > 0) chunksRef.current.push(e.data);
      };

      recorder.onstop = async () => {
        streamRef.current?.getTracks().forEach((t) => t.stop());
        streamRef.current = null;

        if (abortingRef.current) {
          chunksRef.current = [];
          recorderRef.current = null;
          setState('idle');
          return;
        }

        const usedMimeType = recorder.mimeType || 'audio/webm';
        const blob = new Blob(chunksRef.current, { type: usedMimeType });
        chunksRef.current = [];
        recorderRef.current = null;

        setState('transcribing');
        const reader = new FileReader();
        reader.readAsDataURL(blob);
        reader.onloadend = async () => {
          const base64 = (reader.result as string).split(',')[1] ?? '';
          const result = await transcribeAudio(config, base64, usedMimeType);
          setState('idle');
          if (result.text) onTranscript(result.text);
        };
      };

      recorderRef.current = recorder;
      recorder.start();
      setState('recording');
    } catch (err) {
      console.error('Mic error:', err);
      setState('idle');
      alert('Microphone access denied or unavailable.');
    }
  }, [config, onTranscript]);

  const toggle = useCallback(() => {
    if (state === 'transcribing') return;
    if (state === 'recording') {
      stop(false);
    } else {
      start();
    }
  }, [state, start, stop]);

  return { state, toggle, abort: () => stop(true) };
}

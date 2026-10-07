// "Speak instead of typing": records a short voice note, sends it to the
// server (kounselia_transcribe, the same action the website uses) and
// hands back the words. The recording is a small compressed M4A file,
// deleted as soon as it has been sent.
import { transcribeAudio, type KounseliaConfig } from '@kounselia/core';
import { File } from 'expo-file-system';
import { useCallback, useEffect, useRef, useState } from 'react';
import { AudioRecorder, FileFormat, FilePreset } from 'react-native-audio-api';
import { useT } from '@/language';
import { micAllowed, releaseSound, setSoundMode } from './session';

export type DictationState = 'idle' | 'recording' | 'transcribing';

// Long voice notes take the server a long time to transcribe; three
// minutes is plenty for a chat message.
const MAX_SECONDS = 180;

export function useDictation(config: KounseliaConfig, onText: (text: string) => void, onError: (message: string) => void) {
  const [state, setState] = useState<DictationState>('idle');
  const t = useT();
  const tRef = useRef(t);
  useEffect(() => {
    tRef.current = t;
  });
  const recorder = useRef<AudioRecorder | null>(null);
  const starting = useRef(false);
  const closed = useRef(false);
  const limitTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  // Kept in refs so `finish` never changes: the clean-up below must only
  // run when the screen closes, not every time the chat redraws.
  const latest = useRef({ config, onText, onError });
  useEffect(() => {
    latest.current = { config, onText, onError };
  });

  const finish = useCallback(
    async (send: boolean) => {
      if (limitTimer.current) clearTimeout(limitTimer.current);
      const rec = recorder.current;
      recorder.current = null;
      if (!rec) return;
      const result = await rec.stop();
      await releaseSound();
      const path = result.status === 'success' ? result.paths[0] : undefined;
      if (!path) {
        setState('idle');
        if (send) latest.current.onError(tRef.current('m.b.voice.record_failed'));
        return;
      }
      const file = new File(path.startsWith('file://') ? path : `file://${path}`);
      if (!send) {
        file.delete();
        setState('idle');
        return;
      }
      setState('transcribing');
      try {
        const audio = await file.base64();
        const res = await transcribeAudio(latest.current.config, audio, 'audio/mp4');
        if (res.text) latest.current.onText(res.text);
        else latest.current.onError(res.error || tRef.current('m.b.voice.no_words'));
      } catch {
        latest.current.onError(tRef.current('m.b.voice.send_failed'));
      } finally {
        try {
          file.delete();
        } catch {
          // Already gone.
        }
        setState('idle');
      }
    },
    [],
  );

  const begin = useCallback(async () => {
    if (!(await micAllowed())) {
      latest.current.onError(tRef.current('m.b.voice.mic_needed'));
      return;
    }
    await setSoundMode('record');
    const rec = new AudioRecorder();
    rec.enableFileOutput({ format: FileFormat.M4A, preset: FilePreset.Low, channelCount: 1 });
    const started = await rec.start();
    if (started.status !== 'success') {
      await releaseSound();
      latest.current.onError(tRef.current('m.b.voice.start_failed'));
      return;
    }
    if (closed.current) {
      // The screen closed while the microphone was starting.
      await rec.stop();
      await releaseSound();
      return;
    }
    recorder.current = rec;
    setState('recording');
    limitTimer.current = setTimeout(() => finish(true), MAX_SECONDS * 1000);
  }, [finish]);

  const start = useCallback(async () => {
    // A quick double tap mustn't start two recorders.
    if (starting.current || recorder.current) return;
    starting.current = true;
    try {
      await begin();
    } finally {
      starting.current = false;
    }
  }, [begin]);


  const toggle = useCallback(() => {
    if (state === 'idle') start();
    else if (state === 'recording') finish(true);
  }, [finish, start, state]);

  // Leaving the screen mid-recording throws the recording away.
  useEffect(
    () => () => {
      closed.current = true;
      void finish(false);
    },
    [finish],
  );

  return { state, toggle, cancel: () => finish(false) };
}

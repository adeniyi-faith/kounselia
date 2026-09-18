import type { KounseliaConfig } from './types';

export async function transcribeAudio(
  config: KounseliaConfig,
  audioBase64: string,
  mimeType: string,
): Promise<{ text?: string; error?: string }> {
  const body = new URLSearchParams({
    action: 'kounselia_transcribe',
    nonce: config.nonce,
    audio_b64: audioBase64,
    mime_type: mimeType,
  });
  try {
    const res = await fetch(config.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
    });
    const json = await res.json();
    if (json.success && json.data?.text) return { text: json.data.text as string };
    return { error: json.data?.message || 'Could not transcribe audio.' };
  } catch {
    return { error: 'Network error during transcription.' };
  }
}

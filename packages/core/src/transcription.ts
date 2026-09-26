import { postAction } from './http';
import type { KounseliaConfig } from './types';

export async function transcribeAudio(
  config: KounseliaConfig,
  audioBase64: string,
  mimeType: string,
): Promise<{ text?: string; error?: string }> {
  try {
    const json = await postAction(config, 'kounselia_transcribe', { audio_b64: audioBase64, mime_type: mimeType });
    if (json.success && json.data?.text) return { text: json.data.text as string };
    return { error: json.data?.message || 'Could not transcribe audio.' };
  } catch {
    return { error: 'Network error during transcription.' };
  }
}

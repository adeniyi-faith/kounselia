import { postAction } from './http';
import type { KounseliaConfig } from './types';

export interface VoiceTokenResult {
  success: boolean;
  sessionId?: number;
  allowedSeconds?: number;
  token?: string;
  model?: string;
  plan?: string;
  message?: string;
}

export async function fetchVoiceToken(
  config: KounseliaConfig,
  counselor: string,
  sessionId: number,
): Promise<VoiceTokenResult> {
  try {
    const json = await postAction(config, 'kounselia_voice_token', { counselor, session_id: sessionId || 0 });
    if (!json.success) return { success: false, message: json.data?.message };
    const data = json.data ?? {};
    return {
      success: true,
      sessionId: data.session_id,
      allowedSeconds: data.allowed_seconds || 300,
      token: data.token,
      model: data.model,
      plan: data.plan,
    };
  } catch {
    return { success: false, message: "Couldn't connect, please try again." };
  }
}

export function logVoiceTurn(config: KounseliaConfig, sessionId: number, sender: 'user' | 'bot', text: string) {
  if (!sessionId) return;
  postAction(config, 'kounselia_log_voice_turn', { session_id: sessionId, sender, text }).catch(() => undefined);
}

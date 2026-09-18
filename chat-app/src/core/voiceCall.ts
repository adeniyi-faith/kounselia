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
  const body = new URLSearchParams({
    action: 'kounselia_voice_token',
    nonce: config.nonce,
    counselor,
    session_id: String(sessionId || 0),
  });
  try {
    const res = await fetch(config.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
    });
    const json = await res.json();
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
  const body = new URLSearchParams({
    action: 'kounselia_log_voice_turn',
    nonce: config.nonce,
    session_id: String(sessionId),
    sender,
    text,
  });
  fetch(config.ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body }).catch(
    () => undefined,
  );
}

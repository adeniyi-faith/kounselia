// All network calls the conversation screen makes. This talks to the
// same WordPress admin-ajax.php endpoints the old script used. Kept
// separate from any UI code so it can be reused as-is in the React
// Native app later.
//
// Every function here resolves (never throws) so a dropped connection
// can't leave a spinner running forever in the UI that awaited it.
import type { HistoryMessage, KounseliaConfig, SendMessageResult } from './types';

// Just above the server's 90s max_execution_time, so a slow-but-valid AI reply is never cut off.
const REQUEST_TIMEOUT_MS = 95000;

export const CONNECTION_ERROR_MESSAGE =
  "I couldn't reach Kounselia just now. Please check your internet connection and try again.";

async function postToWordpress(
  config: KounseliaConfig,
  action: string,
  params: Record<string, string | number>,
): Promise<any> {
  const body = new URLSearchParams({ action, nonce: config.nonce });
  for (const [key, value] of Object.entries(params)) {
    body.set(key, String(value));
  }
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
  try {
    const res = await fetch(config.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
      signal: controller.signal,
    });
    return await res.json();
  } finally {
    clearTimeout(timer);
  }
}

export async function sendChatMessage(
  config: KounseliaConfig,
  params: { counselor: string; message: string; sessionId: number; guestToken?: string },
): Promise<SendMessageResult> {
  let json: any;
  try {
    json = await postToWordpress(config, 'kounselia_chat', {
      counselor: params.counselor,
      message: params.message,
      session_id: params.sessionId || 0,
      ...(params.guestToken ? { guest_token: params.guestToken } : {}),
    });
  } catch {
    return { errorMessage: CONNECTION_ERROR_MESSAGE, networkError: true };
  }

  if (json?.success) {
    const data = json.data ?? {};
    return {
      reply: data.reply,
      messageId: data.message_id,
      consulted: data.consulted,
      sessionId: data.session_id,
      limitReached: !!data.limit_reached,
      messagesRemaining: data.messages_remaining,
    };
  }

  const data = json?.data ?? {};
  return {
    sessionId: data.session_id,
    dailyLimit: !!data.daily_limit,
    errorMessage: data.message,
  };
}

export type HistoryResult =
  | { status: 'ok'; sessionId: number; messages: HistoryMessage[] }
  | { status: 'empty' }
  | { status: 'error' };

export async function fetchHistory(config: KounseliaConfig, counselor: string): Promise<HistoryResult> {
  try {
    const json = await postToWordpress(config, 'kounselia_get_history', { counselor });
    if (!json?.success) return { status: 'error' };
    if (json.data?.messages?.length) {
      return { status: 'ok', sessionId: json.data.session_id, messages: json.data.messages };
    }
    return { status: 'empty' };
  } catch {
    return { status: 'error' };
  }
}

export async function clearChatOnServer(
  config: KounseliaConfig,
  counselor: string,
  guestToken?: string,
): Promise<boolean> {
  try {
    const json = await postToWordpress(config, 'kounselia_clear_chat', {
      counselor,
      ...(guestToken ? { guest_token: guestToken } : {}),
    });
    return !!json?.success;
  } catch {
    return false;
  }
}

export async function rateMessage(
  config: KounseliaConfig,
  messageId: number,
  rating: 'up' | 'down',
  guestToken?: string,
): Promise<boolean> {
  try {
    const json = await postToWordpress(config, 'kounselia_rate_message', {
      message_id: messageId,
      rating,
      ...(guestToken ? { guest_token: guestToken } : {}),
    });
    return !!json?.success;
  } catch {
    return false;
  }
}

export async function synthesizeMemory(config: KounseliaConfig, sessionId: number): Promise<boolean> {
  try {
    const json = await postToWordpress(config, 'kounselia_synthesize_memory', { session_id: sessionId });
    return !!json?.success;
  } catch {
    return false;
  }
}

export async function fetchVoiceAudio(
  config: KounseliaConfig,
  messageId: number,
  guestToken?: string,
): Promise<string | null> {
  try {
    const json = await postToWordpress(config, 'kounselia_tts', {
      message_id: messageId,
      ...(guestToken ? { guest_token: guestToken } : {}),
    });
    return json?.success && json.data?.audio ? json.data.audio : null;
  } catch {
    return null;
  }
}

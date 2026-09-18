// All network calls the conversation screen makes. This talks to the
// same WordPress admin-ajax.php endpoints the old script used, so the
// PHP backend needs no changes. Kept separate from any UI code so it
// can be reused as-is in the React Native app later.
import type { HistoryMessage, KounseliaConfig, SendMessageResult } from './types';

async function postToWordpress(
  config: KounseliaConfig,
  action: string,
  params: Record<string, string | number>,
): Promise<any> {
  const body = new URLSearchParams({ action, nonce: config.nonce });
  for (const [key, value] of Object.entries(params)) {
    body.set(key, String(value));
  }
  const res = await fetch(config.ajaxUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body,
  });
  return res.json();
}

export async function sendChatMessage(
  config: KounseliaConfig,
  params: { counselor: string; message: string; sessionId: number; guestToken?: string },
): Promise<SendMessageResult> {
  const json = await postToWordpress(config, 'kounselia_chat', {
    counselor: params.counselor,
    message: params.message,
    session_id: params.sessionId || 0,
    ...(params.guestToken ? { guest_token: params.guestToken } : {}),
  });

  if (json.success) {
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

  const data = json.data ?? {};
  return {
    sessionId: data.session_id,
    dailyLimit: !!data.daily_limit,
    errorMessage: data.message,
  };
}

export async function fetchHistory(
  config: KounseliaConfig,
  counselor: string,
): Promise<{ sessionId: number; messages: HistoryMessage[] } | null> {
  const json = await postToWordpress(config, 'kounselia_get_history', { counselor });
  if (json.success && json.data?.messages?.length) {
    return { sessionId: json.data.session_id, messages: json.data.messages };
  }
  return null;
}

export async function synthesizeMemory(config: KounseliaConfig, sessionId: number): Promise<boolean> {
  try {
    const json = await postToWordpress(config, 'kounselia_synthesize_memory', { session_id: sessionId });
    return !!json.success;
  } catch {
    return false;
  }
}

export async function fetchVoiceAudio(
  config: KounseliaConfig,
  messageId: number,
  guestToken?: string,
): Promise<string | null> {
  const json = await postToWordpress(config, 'kounselia_tts', {
    message_id: messageId,
    ...(guestToken ? { guest_token: guestToken } : {}),
  });
  return json.success && json.data?.audio ? json.data.audio : null;
}

// All network calls the conversation screen makes. This talks to the
// same WordPress admin-ajax.php endpoints the old script used. Kept
// separate from any UI code so the web chat and the mobile app share it.
//
// Every function here resolves (never throws) so a dropped connection
// can't leave a spinner running forever in the UI that awaited it.
import { postAction } from './http';
import type { CounselorSummary, HistoryMessage, KounseliaConfig, SendMessageResult } from './types';

export const CONNECTION_ERROR_MESSAGE =
  "I couldn't reach Kounselia just now. Please check your internet connection and try again.";

export async function sendChatMessage(
  config: KounseliaConfig,
  params: { counselor: string; message: string; sessionId: number; guestToken?: string },
): Promise<SendMessageResult> {
  let json: any;
  try {
    json = await postAction(config, 'kounselia_chat', {
      counselor: params.counselor,
      message: params.message,
      session_id: params.sessionId || 0,
      guest_token: params.guestToken || undefined,
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
    const json = await postAction(config, 'kounselia_get_history', { counselor });
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
    const json = await postAction(config, 'kounselia_clear_chat', {
      counselor,
      guest_token: guestToken || undefined,
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
    const json = await postAction(config, 'kounselia_rate_message', {
      message_id: messageId,
      rating,
      guest_token: guestToken || undefined,
    });
    return !!json?.success;
  } catch {
    return false;
  }
}

export async function synthesizeMemory(config: KounseliaConfig, sessionId: number): Promise<boolean> {
  try {
    const json = await postAction(config, 'kounselia_synthesize_memory', { session_id: sessionId });
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
    const json = await postAction(config, 'kounselia_tts', {
      message_id: messageId,
      guest_token: guestToken || undefined,
    });
    return json?.success && json.data?.audio ? json.data.audio : null;
  } catch {
    return null;
  }
}

export type CounselorsResult = { status: 'ok'; counselors: CounselorSummary[] } | { status: 'error' };

export async function fetchCounselors(config: KounseliaConfig): Promise<CounselorsResult> {
  try {
    const json = await postAction(config, 'kounselia_get_counselors');
    if (json?.success && Array.isArray(json.data?.counselors)) {
      return { status: 'ok', counselors: json.data.counselors };
    }
    return { status: 'error' };
  } catch {
    return { status: 'error' };
  }
}

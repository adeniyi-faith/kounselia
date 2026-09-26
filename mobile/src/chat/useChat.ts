// One conversation with one counselor: loading earlier messages, sending,
// retrying, rating and clearing. Same server calls and rules as the web
// chat's hook (chat-app/src/hooks/useChat.ts), minus the guest handling —
// the app is always signed in.
import {
  clearChatOnServer,
  fetchHistory,
  rateMessage as rateMessageOnServer,
  sendChatMessage,
  synthesizeMemory,
  type ChatMessage,
  type CounselorSummary,
  type KounseliaConfig,
} from '@kounselia/core';
import { useCallback, useRef, useState } from 'react';

// Every 5 counselor replies, ask the server to update what it remembers
// about the member (their "memory"), as the website does.
const MEMORY_SYNC_EVERY = 5;

export interface AppMessage extends ChatMessage {
  // Your message didn't reach the server (no signal); tap to resend.
  failed?: boolean;
}

let idCounter = 0;
function nextId(): string {
  idCounter += 1;
  return `m${idCounter}`;
}

function greetingFor(counselor: CounselorSummary): AppMessage {
  return {
    id: nextId(),
    sender: 'ai',
    text: `Hello. I am ${counselor.name}. Where would you like to start today?`,
    createdAt: Date.now(),
  };
}

export function useChat(config: KounseliaConfig, counselor: CounselorSummary) {
  const [messages, setMessages] = useState<AppMessage[]>([]);
  const [phase, setPhase] = useState<'loading' | 'ready'>('loading');
  const [typing, setTyping] = useState(false);
  const sessionId = useRef(0);
  const sinceMemorySync = useRef(0);

  // Opens the conversation where the member left off, or with the
  // counselor's greeting if there's nothing yet. Resolves false if the
  // earlier messages couldn't be fetched (the greeting is shown anyway).
  const load = useCallback(async (): Promise<boolean> => {
    const history = await fetchHistory(config, counselor.slug);
    if (history.status === 'ok') {
      sessionId.current = history.sessionId;
      setMessages(
        history.messages.map((m) => ({
          id: nextId(),
          sender: m.sender === 'user' ? 'user' : 'ai',
          text: m.content,
          messageId: m.id,
          rating: m.rating ?? null,
          createdAt: m.sent_at ? Date.parse(m.sent_at) : Date.now(),
        })),
      );
    } else {
      setMessages([greetingFor(counselor)]);
    }
    setPhase('ready');
    return history.status !== 'error';
  }, [config, counselor]);

  const deliver = useCallback(
    async (localId: string, text: string) => {
      setTyping(true);
      let result;
      try {
        result = await sendChatMessage(config, { counselor: counselor.slug, message: text, sessionId: sessionId.current });
      } finally {
        setTyping(false);
      }
      if (result.sessionId) sessionId.current = result.sessionId;

      if (result.networkError) {
        setMessages((prev) => prev.map((m) => (m.id === localId ? { ...m, failed: true } : m)));
        return;
      }

      const reply: AppMessage = result.reply
        ? { id: nextId(), sender: 'ai', text: result.reply, messageId: result.messageId, consulted: result.consulted, createdAt: Date.now() }
        : {
            id: nextId(),
            sender: 'ai',
            text: result.errorMessage || "I'm having trouble connecting right now. Please try again in a moment.",
            createdAt: Date.now(),
          };
      setMessages((prev) => [...prev, reply]);

      if (result.reply) {
        sinceMemorySync.current += 1;
        if (sinceMemorySync.current >= MEMORY_SYNC_EVERY && sessionId.current) {
          synthesizeMemory(config, sessionId.current).then((ok) => {
            if (ok) sinceMemorySync.current = 0;
          });
        }
      }
    },
    [config, counselor.slug],
  );

  const send = useCallback(
    async (text: string) => {
      const trimmed = text.trim();
      if (!trimmed || typing) return;
      const id = nextId();
      setMessages((prev) => [...prev, { id, sender: 'user', text: trimmed, createdAt: Date.now() }]);
      await deliver(id, trimmed);
    },
    [deliver, typing],
  );

  const retry = useCallback(
    async (message: AppMessage) => {
      if (typing || !message.failed) return;
      // Move it to the bottom, as it's being sent again now.
      setMessages((prev) => [...prev.filter((m) => m.id !== message.id), { ...message, failed: false, createdAt: Date.now() }]);
      await deliver(message.id, message.text);
    },
    [deliver, typing],
  );

  const rate = useCallback(
    async (message: AppMessage, rating: 'up' | 'down'): Promise<boolean> => {
      if (!message.messageId) return false;
      const previous = message.rating ?? null;
      const setRating = (r: 'up' | 'down' | null) =>
        setMessages((prev) => prev.map((m) => (m.id === message.id ? { ...m, rating: r } : m)));
      setRating(rating);
      const ok = await rateMessageOnServer(config, message.messageId, rating);
      if (!ok) setRating(previous);
      return ok;
    },
    [config],
  );

  // Resolves false (leaving the conversation on screen) if the server
  // couldn't be reached, so a "cleared" chat never quietly comes back.
  const clear = useCallback(async (): Promise<boolean> => {
    const ok = await clearChatOnServer(config, counselor.slug);
    if (!ok) return false;
    sessionId.current = 0;
    sinceMemorySync.current = 0;
    setMessages([greetingFor(counselor)]);
    return true;
  }, [config, counselor]);

  // For the voice call, which continues the same conversation.
  const getSessionId = useCallback(() => sessionId.current, []);
  const setSessionId = useCallback((id: number) => {
    sessionId.current = id;
  }, []);

  return { messages, phase, typing, load, send, retry, rate, clear, getSessionId, setSessionId };
}

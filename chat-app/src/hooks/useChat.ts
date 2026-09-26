import { useCallback, useRef, useState } from 'react';
import {
  clearChatOnServer,
  fetchHistory,
  fetchVoiceAudio,
  rateMessage as rateMessageOnServer,
  sendChatMessage,
  synthesizeMemory,
} from '@kounselia/core';
import { getGuestToken } from '../guestToken';
import type { ChatMessage, Counselor, KounseliaConfig } from '@kounselia/core';

const GUEST_MESSAGE_LIMIT = 6;
const MEMORY_SYNC_EVERY = 5;

let idCounter = 0;
function nextId(): string {
  idCounter += 1;
  return `m${idCounter}`;
}

interface UseChatOptions {
  config: KounseliaConfig;
  counselorSlug: string;
  counselor: Counselor;
}

export function useChat({ config, counselorSlug, counselor }: UseChatOptions) {
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [typing, setTyping] = useState(false);
  const [limitBanner, setLimitBanner] = useState<'soft' | 'hard' | null>(null);
  const [inputDisabled, setInputDisabled] = useState(false);

  const sessionId = useRef(0);
  const guestMessageCount = useRef(0);
  const messagesSinceMemorySync = useRef(0);
  const loggedIn = config.loggedIn;

  const appendGreeting = useCallback(() => {
    const greeting = counselor.greeting || `Hello. I am ${counselor.name}. Where would you like to start today?`;
    setMessages([{ id: nextId(), sender: 'ai', text: greeting, createdAt: Date.now() }]);
  }, [counselor]);

  const sendMessage = useCallback(
    async (text: string) => {
      const trimmed = text.trim();
      if (!trimmed || typing) return;
      if (!loggedIn && guestMessageCount.current >= GUEST_MESSAGE_LIMIT) {
        setLimitBanner('hard');
        setInputDisabled(true);
        return;
      }

      setMessages((prev) => [...prev, { id: nextId(), sender: 'user', text: trimmed, createdAt: Date.now() }]);
      setTyping(true);

      let result;
      try {
        result = await sendChatMessage(config, {
          counselor: counselorSlug,
          message: trimmed,
          sessionId: sessionId.current,
          guestToken: loggedIn ? undefined : getGuestToken(),
        });
      } finally {
        setTyping(false);
      }

      // A message that never reached the server shouldn't use up a guest's free messages.
      if (!result.networkError) guestMessageCount.current += 1;
      if (result.sessionId) sessionId.current = result.sessionId;

      if (result.reply) {
        setMessages((prev) => [
          ...prev,
          {
            id: nextId(),
            sender: 'ai',
            text: result.reply!,
            messageId: result.messageId,
            consulted: result.consulted,
            createdAt: Date.now(),
          },
        ]);

        if (!loggedIn && typeof result.messagesRemaining === 'number') {
          if (result.limitReached) {
            setLimitBanner('hard');
            setInputDisabled(true);
          } else if (result.messagesRemaining === 1) {
            setLimitBanner('soft');
          }
        }

        if (loggedIn) {
          messagesSinceMemorySync.current += 1;
          if (messagesSinceMemorySync.current >= MEMORY_SYNC_EVERY && sessionId.current) {
            synthesizeMemory(config, sessionId.current).then((ok) => {
              if (ok) messagesSinceMemorySync.current = 0;
            });
          }
        }
      } else if (result.limitReached) {
        setLimitBanner('hard');
        setInputDisabled(true);
      } else if (result.dailyLimit) {
        setLimitBanner('hard');
        setInputDisabled(true);
      } else {
        setMessages((prev) => [
          ...prev,
          {
            id: nextId(),
            sender: 'ai',
            text: result.errorMessage || "I'm having trouble connecting right now. Please try again in a moment.",
            createdAt: Date.now(),
          },
        ]);
      }
    },
    [config, counselorSlug, loggedIn, typing],
  );

  const loadHistory = useCallback(async (): Promise<'ok' | 'empty' | 'error'> => {
    const history = await fetchHistory(config, counselorSlug);
    if (history.status !== 'ok') return history.status;
    sessionId.current = history.sessionId;
    guestMessageCount.current = 0;
    setMessages(
      history.messages.map((m) => ({
        id: nextId(),
        sender: m.sender === 'user' ? 'user' : 'ai',
        text: m.content,
        messageId: m.id,
        rating: m.rating ?? null,
        createdAt: Date.now(),
      })),
    );
    return 'ok';
  }, [config, counselorSlug]);

  // Resolves false (and leaves the conversation on screen) if the server
  // couldn't be reached, so a "cleared" chat never quietly comes back later.
  const clearChat = useCallback(async (): Promise<boolean> => {
    const ok = await clearChatOnServer(config, counselorSlug, loggedIn ? undefined : getGuestToken());
    if (!ok) return false;
    sessionId.current = 0;
    guestMessageCount.current = 0;
    messagesSinceMemorySync.current = 0;
    setLimitBanner(null);
    setInputDisabled(false);
    setMessages([]);
    setTimeout(appendGreeting, 300);
    return true;
  }, [appendGreeting, config, counselorSlug, loggedIn]);

  const rateMessage = useCallback(
    (messageId: number, rating: 'up' | 'down') =>
      rateMessageOnServer(config, messageId, rating, loggedIn ? undefined : getGuestToken()),
    [config, loggedIn],
  );

  const playVoice = useCallback(
    (messageId: number) => fetchVoiceAudio(config, messageId, loggedIn ? undefined : getGuestToken()),
    [config, loggedIn],
  );

  return {
    messages,
    typing,
    limitBanner,
    inputDisabled,
    appendGreeting,
    sendMessage,
    loadHistory,
    clearChat,
    rateMessage,
    playVoice,
    sessionId,
  };
}

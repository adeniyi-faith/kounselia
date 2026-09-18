import { useCallback, useRef, useState } from 'react';
import { fetchHistory, fetchVoiceAudio, sendChatMessage, synthesizeMemory } from '../core/api';
import { getGuestToken } from '../core/guestToken';
import type { ChatMessage, Counselor, KounseliaConfig } from '../core/types';

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
      guestMessageCount.current += 1;
      setTyping(true);

      const result = await sendChatMessage(config, {
        counselor: counselorSlug,
        message: trimmed,
        sessionId: sessionId.current,
        guestToken: loggedIn ? undefined : getGuestToken(),
      });

      setTyping(false);
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

  const loadHistory = useCallback(async () => {
    const history = await fetchHistory(config, counselorSlug);
    if (!history) return false;
    sessionId.current = history.sessionId;
    guestMessageCount.current = 0;
    setMessages(
      history.messages.map((m) => ({
        id: nextId(),
        sender: m.sender,
        text: m.content,
        messageId: m.id,
        createdAt: Date.now(),
      })),
    );
    return true;
  }, [config, counselorSlug]);

  const clearChat = useCallback(() => {
    sessionId.current = 0;
    guestMessageCount.current = 0;
    messagesSinceMemorySync.current = 0;
    setLimitBanner(null);
    setInputDisabled(false);
    setMessages([]);
    setTimeout(appendGreeting, 300);
  }, [appendGreeting]);

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
    playVoice,
    sessionId,
  };
}

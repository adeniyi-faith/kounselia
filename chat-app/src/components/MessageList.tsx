import { useEffect, useRef } from 'react';
import type { ChatMessage, Counselor } from '@kounselia/core';
import { MessageBubble } from './MessageBubble';
import { TypingIndicator } from './TypingIndicator';

interface Props {
  messages: ChatMessage[];
  typing: boolean;
  counselor: Counselor;
  loggedIn: boolean;
  onPlayVoice: (messageId: number) => Promise<string | null>;
  onRate: (messageId: number, rating: 'up' | 'down') => Promise<boolean>;
  onNotify: (message: string) => void;
}

export function MessageList({ messages, typing, counselor, loggedIn, onPlayVoice, onRate, onNotify }: Props) {
  const bottomRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: 'smooth', block: 'end' });
  }, [messages, typing]);

  return (
    <div className="messages">
      {messages.map((message) => (
        <MessageBubble
          key={message.id}
          message={message}
          counselor={counselor}
          loggedIn={loggedIn}
          onPlayVoice={onPlayVoice}
          onRate={onRate}
          onNotify={onNotify}
        />
      ))}
      {typing && <TypingIndicator counselor={counselor} />}
      <div ref={bottomRef} />
    </div>
  );
}

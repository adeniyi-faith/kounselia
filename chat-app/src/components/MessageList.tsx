import { useEffect, useRef } from 'react';
import type { ChatMessage, Counselor } from '../core/types';
import { MessageBubble } from './MessageBubble';
import { TypingIndicator } from './TypingIndicator';

interface Props {
  messages: ChatMessage[];
  typing: boolean;
  counselor: Counselor;
  loggedIn: boolean;
  onPlayVoice: (messageId: number) => Promise<string | null>;
}

export function MessageList({ messages, typing, counselor, loggedIn, onPlayVoice }: Props) {
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
        />
      ))}
      {typing && <TypingIndicator counselor={counselor} />}
      <div ref={bottomRef} />
    </div>
  );
}

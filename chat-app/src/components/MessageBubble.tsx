import { useState } from 'react';
import type { ChatMessage, Counselor } from '../core/types';

interface Props {
  message: ChatMessage;
  counselor: Counselor;
  loggedIn: boolean;
  onPlayVoice: (messageId: number) => Promise<string | null>;
  onRate: (messageId: number, rating: 'up' | 'down') => Promise<boolean>;
  onNotify: (message: string) => void;
}

function formatTime(ts: number): string {
  return new Date(ts).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

// Turns "**Heading**" into a heading and blank lines into paragraph
// breaks, matching how the old script formatted a counselor's reply.
function renderFormattedText(text: string) {
  const paragraphs = text.split(/\n\n/);
  return paragraphs.map((para, i) => {
    const headingMatch = para.match(/^\*\*(.*?)\*\*$/);
    if (headingMatch) {
      return <h4 key={i}>{headingMatch[1]}</h4>;
    }
    const lines = para.split('\n');
    return (
      <p key={i} style={i > 0 ? { marginTop: 12 } : undefined}>
        {lines.map((line, j) => (
          <span key={j}>
            {line}
            {j < lines.length - 1 && <br />}
          </span>
        ))}
      </p>
    );
  });
}

export function MessageBubble({ message, counselor, loggedIn, onPlayVoice, onRate, onNotify }: Props) {
  const [playState, setPlayState] = useState<'idle' | 'loading' | 'playing'>('idle');
  const [copied, setCopied] = useState(false);
  const [feedback, setFeedback] = useState<'up' | 'down' | null>(message.rating ?? null);
  const [feedbackSaving, setFeedbackSaving] = useState(false);

  if (message.sender === 'user') {
    return (
      <div className="msg user">
        <div>
          <div className="msg-bubble">{message.text}</div>
          <div className="msg-time" style={{ textAlign: 'right', marginTop: 6, marginRight: 4 }}>
            {formatTime(message.createdAt)}
          </div>
        </div>
        <div
          className="msg-av"
          style={{ background: 'var(--accent-light)', color: 'var(--accent)', fontSize: 12, fontWeight: 600 }}
        >
          {loggedIn ? 'U' : 'G'}
        </div>
      </div>
    );
  }

  const handlePlay = async () => {
    if (playState === 'playing' || !message.messageId) return;
    setPlayState('loading');
    const audioUrl = await onPlayVoice(message.messageId);
    if (!audioUrl) {
      setPlayState('idle');
      onNotify("Couldn't play this message. Please check your connection and try again.");
      return;
    }
    const audio = new Audio(audioUrl);
    audio.onended = () => setPlayState('idle');
    audio.onerror = () => setPlayState('idle');
    setPlayState('playing');
    audio.play().catch(() => {
      setPlayState('idle');
      onNotify("Couldn't play this message.");
    });
  };

  const handleCopy = () => {
    navigator.clipboard
      .writeText(message.text)
      .then(() => {
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
      })
      .catch(() => onNotify('Failed to copy text.'));
  };

  const handleRate = async (rating: 'up' | 'down') => {
    if (!message.messageId || feedbackSaving || feedback === rating) return;
    const previous = feedback;
    setFeedback(rating);
    setFeedbackSaving(true);
    const ok = await onRate(message.messageId, rating);
    setFeedbackSaving(false);
    if (ok) {
      onNotify(rating === 'up' ? 'Thanks for the feedback!' : 'Feedback recorded.');
    } else {
      setFeedback(previous);
      onNotify("Couldn't save your feedback. Please try again.");
    }
  };

  return (
    <div className="msg ai">
      <div className={`msg-av ${counselor.av}`}>
        <i className={`ti ${counselor.icon}`} style={{ fontSize: 13 }} />
      </div>
      <div>
        {message.consulted && message.consulted.length > 0 && (
          <div
            style={{
              fontSize: 10,
              color: 'var(--gold)',
              fontWeight: 700,
              marginBottom: 6,
              letterSpacing: 0.5,
              textTransform: 'uppercase',
              display: 'flex',
              alignItems: 'center',
              gap: 4,
            }}
          >
            <i className="ti ti-users" /> Consulted {message.consulted.join(' & ')}
          </div>
        )}
        <div className="msg-bubble">{renderFormattedText(message.text)}</div>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
          <div className="msg-time" style={{ marginTop: 6, marginLeft: 4 }}>
            {formatTime(message.createdAt)}
            {message.messageId && (
              <button className="voice-play-btn" onClick={handlePlay} aria-label="Listen" title="Listen">
                <i
                  className={`ti ${
                    playState === 'loading' ? 'ti-loader-2' : playState === 'playing' ? 'ti-player-stop-filled' : 'ti-volume'
                  }`}
                />
              </button>
            )}
          </div>
        </div>
        <div className="msg-feedback-bar">
          <button className="msg-fb-btn" onClick={handleCopy} title="Copy">
            <i className={`ti ${copied ? 'ti-check action-pulse' : 'ti-copy'}`} />
          </button>
          <button
            className="msg-fb-btn"
            onClick={() => handleRate('up')}
            disabled={!message.messageId}
            title="Helpful"
            style={feedback === 'up' ? { color: '#2E5C3E' } : undefined}
          >
            <i className={`ti ${feedback === 'up' ? 'ti-thumb-up-filled' : 'ti-thumb-up'}`} />
          </button>
          <button
            className="msg-fb-btn"
            onClick={() => handleRate('down')}
            disabled={!message.messageId}
            title="Not Helpful"
            style={feedback === 'down' ? { color: '#8B3A52' } : undefined}
          >
            <i className={`ti ${feedback === 'down' ? 'ti-thumb-down-filled' : 'ti-thumb-down'}`} />
          </button>
        </div>
      </div>
    </div>
  );
}

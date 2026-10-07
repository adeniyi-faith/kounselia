import { useState } from 'react';
import type { ChatMessage, Counselor } from '@kounselia/core';
import { useI18n } from '../i18n';

interface Props {
  message: ChatMessage;
  counselor: Counselor;
  loggedIn: boolean;
  onPlayVoice: (messageId: number) => Promise<string | null>;
  onRate: (messageId: number, rating: 'up' | 'down') => Promise<boolean>;
  onNotify: (message: string) => void;
}

function formatTime(ts: number, language: string): string {
  return new Date(ts).toLocaleTimeString(language, { hour: '2-digit', minute: '2-digit' });
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
  const { t, language } = useI18n();
  const [playState, setPlayState] = useState<'idle' | 'loading' | 'playing'>('idle');
  const [copied, setCopied] = useState(false);
  const [feedback, setFeedback] = useState<'up' | 'down' | null>(message.rating ?? null);
  const [feedbackSaving, setFeedbackSaving] = useState(false);

  if (message.sender === 'user') {
    return (
      <div className="msg user">
        <div>
          <div className="msg-bubble">{message.text}</div>
          <div className="msg-time" style={{ textAlign: 'end', marginTop: 6, marginInlineEnd: 4 }}>
            {formatTime(message.createdAt, language)}
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
      onNotify(t('c.msg.play_failed'));
      return;
    }
    const audio = new Audio(audioUrl);
    audio.onended = () => setPlayState('idle');
    audio.onerror = () => setPlayState('idle');
    setPlayState('playing');
    audio.play().catch(() => {
      setPlayState('idle');
      onNotify(t('c.msg.play_failed_short'));
    });
  };

  const handleCopy = () => {
    navigator.clipboard
      .writeText(message.text)
      .then(() => {
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
      })
      .catch(() => onNotify(t('c.msg.copy_failed')));
  };

  const handleRate = async (rating: 'up' | 'down') => {
    if (!message.messageId || feedbackSaving || feedback === rating) return;
    const previous = feedback;
    setFeedback(rating);
    setFeedbackSaving(true);
    const ok = await onRate(message.messageId, rating);
    setFeedbackSaving(false);
    if (ok) {
      onNotify(rating === 'up' ? t('c.msg.thanks') : t('c.msg.recorded'));
    } else {
      setFeedback(previous);
      onNotify(t('c.msg.feedback_failed'));
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
            <i className="ti ti-users" /> {t('c.msg.consulted', { names: message.consulted.join(' & ') })}
          </div>
        )}
        <div className="msg-bubble">{renderFormattedText(message.text)}</div>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
          <div className="msg-time" style={{ marginTop: 6, marginInlineStart: 4 }}>
            {formatTime(message.createdAt, language)}
            {message.messageId && (
              <button className="voice-play-btn" onClick={handlePlay} aria-label={t('c.msg.listen')} title={t('c.msg.listen')}>
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
          <button className="msg-fb-btn" onClick={handleCopy} title={t('c.msg.copy')}>
            <i className={`ti ${copied ? 'ti-check action-pulse' : 'ti-copy'}`} />
          </button>
          <button
            className="msg-fb-btn"
            onClick={() => handleRate('up')}
            disabled={!message.messageId}
            title={t('c.msg.helpful')}
            style={feedback === 'up' ? { color: '#2E5C3E' } : undefined}
          >
            <i className={`ti ${feedback === 'up' ? 'ti-thumb-up-filled' : 'ti-thumb-up'}`} />
          </button>
          <button
            className="msg-fb-btn"
            onClick={() => handleRate('down')}
            disabled={!message.messageId}
            title={t('c.msg.not_helpful')}
            style={feedback === 'down' ? { color: '#8B3A52' } : undefined}
          >
            <i className={`ti ${feedback === 'down' ? 'ti-thumb-down-filled' : 'ti-thumb-down'}`} />
          </button>
        </div>
      </div>
    </div>
  );
}

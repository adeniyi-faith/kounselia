import { useRef, useState } from 'react';
import { useDictation } from '../hooks/useDictation';
import type { KounseliaConfig } from '@kounselia/core';

interface Props {
  config: KounseliaConfig;
  disabled: boolean;
  onSend: (text: string) => void;
}

// The text box plus the round button beside it. The button shows a
// microphone when the box is empty, a send arrow once you start typing,
// and switches into recording / transcribing state when you tap the mic.
export function Composer({ config, disabled, onSend }: Props) {
  const [value, setValue] = useState('');
  const textareaRef = useRef<HTMLTextAreaElement>(null);

  const dictation = useDictation(config, (text) => {
    setValue((prev) => (prev.trim() ? `${prev.trim()} ${text}` : text));
    textareaRef.current?.focus();
  });

  const autoResize = () => {
    const el = textareaRef.current;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 120)}px`;
  };

  const handleSend = () => {
    const trimmed = value.trim();
    if (!trimmed || disabled) return;
    if (dictation.state === 'recording') dictation.abort();
    onSend(trimmed);
    setValue('');
    requestAnimationFrame(autoResize);
  };

  const hasText = value.trim().length > 0;

  const handleFabClick = () => {
    if (dictation.state === 'transcribing') return;
    if (dictation.state === 'recording') {
      dictation.toggle();
      return;
    }
    if (hasText) {
      handleSend();
    } else {
      dictation.toggle();
    }
  };

  const fabIcon =
    dictation.state === 'transcribing'
      ? 'ti-loader-2 fab-icon-spin'
      : dictation.state === 'recording'
        ? 'ti-player-stop-filled'
        : hasText
          ? 'ti-send'
          : 'ti-microphone';

  const fabBackground = dictation.state === 'recording' ? 'var(--rose)' : hasText ? undefined : '#00A884';

  return (
    <div className="upgraded-input-area">
      <div className="input-pill">
        <button className="pill-icon" title="Expressions" type="button">
          <i className="ti ti-mood-smile" />
        </button>
        <textarea
          ref={textareaRef}
          className="chat-input"
          rows={1}
          placeholder="Message"
          disabled={disabled}
          value={value}
          onChange={(e) => {
            setValue(e.target.value);
            autoResize();
          }}
          onKeyDown={(e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
              e.preventDefault();
              handleSend();
            }
          }}
        />
      </div>
      <button
        className={`dynamic-fab${hasText ? ' is-typing' : ''}`}
        onClick={handleFabClick}
        disabled={disabled}
        aria-label="Voice or Send"
        style={{
          background: fabBackground,
          animation: dictation.state === 'recording' ? 'micPulse 1.2s ease-in-out infinite' : undefined,
          opacity: dictation.state === 'transcribing' ? 0.7 : 1,
        }}
      >
        <i className={`ti ${fabIcon}`} />
      </button>
    </div>
  );
}

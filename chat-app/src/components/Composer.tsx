import { useRef, useState } from 'react';

interface Props {
  disabled: boolean;
  onSend: (text: string) => void;
}

// The text box plus the round button beside it. The button shows a
// microphone when the box is empty and a send arrow once you start
// typing — voice dictation itself is wired up in a follow-up pass.
export function Composer({ disabled, onSend }: Props) {
  const [value, setValue] = useState('');
  const textareaRef = useRef<HTMLTextAreaElement>(null);

  const autoResize = () => {
    const el = textareaRef.current;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 120)}px`;
  };

  const handleSend = () => {
    const trimmed = value.trim();
    if (!trimmed || disabled) return;
    onSend(trimmed);
    setValue('');
    requestAnimationFrame(autoResize);
  };

  const hasText = value.trim().length > 0;

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
        onClick={hasText ? handleSend : undefined}
        disabled={disabled}
        aria-label="Voice or Send"
        style={{ background: hasText ? undefined : '#00A884' }}
      >
        <i className={`ti ${hasText ? 'ti-send' : 'ti-microphone'}`} />
      </button>
    </div>
  );
}

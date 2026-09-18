import { useEffect } from 'react';
import { useChat } from '../hooks/useChat';
import type { Counselor, KounseliaConfig } from '../core/types';
import { Composer } from './Composer';
import { LimitBanner } from './LimitBanner';
import { MessageList } from './MessageList';

interface Props {
  config: KounseliaConfig;
  counselorSlug: string;
  counselor: Counselor;
  onBack: () => void;
  onRequestSignUp: () => void;
}

export function ChatScreen({ config, counselorSlug, counselor, onBack, onRequestSignUp }: Props) {
  const { messages, typing, limitBanner, inputDisabled, appendGreeting, sendMessage, playVoice } = useChat({
    config,
    counselorSlug,
    counselor,
  });

  useEffect(() => {
    appendGreeting();
    // Re-runs only when the counselor changes, matching the old
    // behaviour of greeting once per conversation, not per render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [counselorSlug]);

  return (
    <div className="screen active" id="chat">
      <div className="chat-nav">
        <button className="back-btn" onClick={onBack} aria-label="Go back">
          <i className="ti ti-arrow-left" />
        </button>
        <div className={`chat-av-sm ${counselor.av}`}>
          <i className={`ti ${counselor.icon}`} />
        </div>
        <div className="chat-info">
          <h3>
            <span>{counselor.name}</span>
            <span className="trust-badge" title="Verified Counselor">
              <i className="ti ti-check" />
            </span>
          </h3>
          <p>{counselor.spec}</p>
        </div>
      </div>

      <MessageList
        messages={messages}
        typing={typing}
        counselor={counselor}
        loggedIn={config.loggedIn}
        onPlayVoice={playVoice}
      />

      <div className="chat-footer">
        <div id="limit-area">
          {limitBanner && <LimitBanner kind={limitBanner} onSignUp={onRequestSignUp} />}
        </div>
        <Composer disabled={inputDisabled} onSend={sendMessage} />
      </div>
    </div>
  );
}

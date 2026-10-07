import { useEffect, useRef, useState } from 'react';
import { useT } from '../i18n';
import { useChat } from '../hooks/useChat';
import { useVoiceCall } from '../hooks/useVoiceCall';
import type { Counselor, KounseliaConfig } from '@kounselia/core';
import { exportChatAsFile } from '../exportChat';
import { CallOverlay } from './CallOverlay';
import { ChatMenu } from './ChatMenu';
import { Composer } from './Composer';
import { LimitBanner } from './LimitBanner';
import { MessageList } from './MessageList';
import { Toast } from './Toast';

interface Props {
  config: KounseliaConfig;
  counselorSlug: string;
  counselor: Counselor;
  onBack: () => void;
  onRequestSignUp: () => void;
  onRequestSignIn: () => void;
}

function canVoiceCall(counselor: Counselor): boolean {
  return !!(counselor.voice || Number(counselor.voice_enabled) === 1 || counselor.voice_enabled === true);
}

export function ChatScreen({ config, counselorSlug, counselor, onBack, onRequestSignUp, onRequestSignIn }: Props) {
  const t = useT();
  const {
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
  } = useChat({ config, counselorSlug, counselor });

  const call = useVoiceCall({
    config,
    counselorSlug,
    getSessionId: () => sessionId.current,
    onSessionId: (id) => {
      sessionId.current = id;
    },
  });

  const [menuOpen, setMenuOpen] = useState(false);
  const [callOpen, setCallOpen] = useState(false);
  const [toast, setToast] = useState<string | null>(null);
  const [historyLoading, setHistoryLoading] = useState(false);

  const showToast = (msg: string) => {
    setToast(msg);
    setTimeout(() => setToast(null), 2500);
  };

  useEffect(() => {
    appendGreeting();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [counselorSlug]);

  // When a call finishes, close the call screen (after a moment, so a
  // last message like "Time's up" can be read) and, if the call got going,
  // reload the conversation so what was said on it appears in the chat.
  const callWasLive = useRef(false);
  useEffect(() => {
    if (call.status === 'listening' || call.status === 'speaking') callWasLive.current = true;
    if (call.status !== 'ended') return;
    const t = setTimeout(() => {
      setCallOpen(false);
      if (callWasLive.current) {
        callWasLive.current = false;
        loadHistory();
      }
    }, 900);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [call.status]);

  const handleHistory = async () => {
    if (!config.loggedIn) {
      onRequestSignUp();
      return;
    }
    if (historyLoading) return;
    setHistoryLoading(true);
    const result = await loadHistory();
    setHistoryLoading(false);
    if (result === 'empty') showToast(t('c.chat.history_empty'));
    if (result === 'error') showToast(t('c.chat.history_error'));
  };

  const handleExport = () => {
    setMenuOpen(false);
    if (!exportChatAsFile(messages, counselor, t)) showToast(t('c.chat.no_export'));
  };

  const [clearing, setClearing] = useState(false);

  const handleClear = async () => {
    setMenuOpen(false);
    if (clearing) return;
    if (!window.confirm(t('c.chat.confirm_clear'))) {
      return;
    }
    setClearing(true);
    const ok = await clearChat();
    setClearing(false);
    showToast(ok ? t('c.chat.cleared') : t('c.chat.clear_failed'));
  };

  const handleUpgrade = () => {
    setMenuOpen(false);
    if (config.loggedIn) {
      window.location.href = '/dashboard.php#upgrade';
    } else {
      onRequestSignUp();
    }
  };

  const handleStartCall = () => {
    if (!canVoiceCall(counselor)) return;
    if (!config.loggedIn) {
      onRequestSignIn();
      return;
    }
    setCallOpen(true);
    call.startCall();
  };

  const handleEndCall = () => {
    call.endCall();
    setCallOpen(false);
  };

  return (
    <div className="screen active" id="chat" onClick={() => menuOpen && setMenuOpen(false)}>
      <div className="chat-nav">
        <button className="back-btn" onClick={onBack} aria-label={t('c.chat.go_back')}>
          <i className="ti ti-arrow-left" />
        </button>
        <div className={`chat-av-sm ${counselor.av}`}>
          <i className={`ti ${counselor.icon}`} />
        </div>
        <div className="chat-info">
          <h3>
            <span>{counselor.name}</span>
            <span className="trust-badge" title={t('c.chat.verified')}>
              <i className="ti ti-check" />
            </span>
          </h3>
          <p>{counselor.spec}</p>
        </div>

        <div className="nav-actions">
          {canVoiceCall(counselor) && (
            <button
              className="icon-btn action-call"
              onClick={(e) => {
                e.stopPropagation();
                handleStartCall();
              }}
              title={t('c.chat.start_voice')}
            >
              <i className="ti ti-phone" />
            </button>
          )}
          <button
            className="icon-btn"
            title={t('c.chat.load_history')}
            onClick={(e) => {
              e.stopPropagation();
              handleHistory();
            }}
          >
            <i className={`ti ${historyLoading ? 'ti-loader-2 action-pulse' : 'ti-history'}`} />
          </button>
          <button
            className="icon-btn"
            title={t('c.chat.more_options')}
            onClick={(e) => {
              e.stopPropagation();
              setMenuOpen((o) => !o);
            }}
          >
            <i className="ti ti-dots-vertical" />
          </button>
        </div>
      </div>

      <ChatMenu open={menuOpen} onExport={handleExport} onClear={handleClear} onUpgrade={handleUpgrade} />

      <MessageList
        messages={messages}
        typing={typing}
        counselor={counselor}
        loggedIn={config.loggedIn}
        onPlayVoice={playVoice}
        onRate={rateMessage}
        onNotify={showToast}
      />

      <div className="chat-footer">
        <div id="limit-area">
          {limitBanner && <LimitBanner kind={limitBanner} onSignUp={onRequestSignUp} />}
        </div>
        <Composer config={config} disabled={inputDisabled} onSend={sendMessage} />
      </div>

      <CallOverlay
        active={callOpen}
        counselor={counselor}
        status={call.status}
        statusText={call.statusText}
        timerText={call.timerText}
        caption={call.caption}
        muted={call.muted}
        freeCallMinutes={call.freeCallMinutes}
        onEnd={handleEndCall}
        onToggleMute={call.toggleMute}
      />

      {toast && <Toast message={toast} />}
    </div>
  );
}

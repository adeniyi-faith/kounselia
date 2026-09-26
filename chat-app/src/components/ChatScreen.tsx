import { useEffect, useState } from 'react';
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

  useEffect(() => {
    if (call.status === 'ended') setCallOpen(false);
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
    if (result === 'empty') showToast('No previous history found.');
    if (result === 'error') showToast("Couldn't load your history. Please check your connection and try again.");
  };

  const handleExport = () => {
    setMenuOpen(false);
    if (!exportChatAsFile(messages, counselor)) showToast('No messages to export.');
  };

  const [clearing, setClearing] = useState(false);

  const handleClear = async () => {
    setMenuOpen(false);
    if (clearing) return;
    if (!window.confirm('Clear this conversation? It will be removed from your chat history and cannot be brought back.')) {
      return;
    }
    setClearing(true);
    const ok = await clearChat();
    setClearing(false);
    showToast(ok ? 'Conversation cleared.' : "Couldn't clear the chat. Please check your connection and try again.");
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

        <div className="nav-actions">
          {canVoiceCall(counselor) && (
            <button
              className="icon-btn action-call"
              onClick={(e) => {
                e.stopPropagation();
                handleStartCall();
              }}
              title="Start voice conversation"
            >
              <i className="ti ti-phone" />
            </button>
          )}
          <button
            className="icon-btn"
            title="Load Chat History"
            onClick={(e) => {
              e.stopPropagation();
              handleHistory();
            }}
          >
            <i className={`ti ${historyLoading ? 'ti-loader-2 action-pulse' : 'ti-history'}`} />
          </button>
          <button
            className="icon-btn"
            title="More options"
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

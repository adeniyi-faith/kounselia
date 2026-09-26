import type { Counselor } from '@kounselia/core';
import type { CallStatus } from '../hooks/useVoiceCall';

interface Props {
  active: boolean;
  counselor: Counselor;
  status: CallStatus;
  statusText: string;
  timerText: string;
  caption: string;
  muted: boolean;
  freeCallMinutes: number | null;
  onEnd: () => void;
  onToggleMute: () => void;
}

export function CallOverlay({
  active,
  counselor,
  statusText,
  timerText,
  caption,
  muted,
  freeCallMinutes,
  status,
  onEnd,
  onToggleMute,
}: Props) {
  return (
    <div className={`call-overlay${active ? ' active' : ''}`}>
      <button className="call-close" onClick={onEnd} aria-label="End call">
        <i className="ti ti-x" />
      </button>
      <div className="call-status">{statusText}</div>
      <div className="call-avatar-wrap">
        <div className={`call-avatar ${counselor.av}`}>
          <i className={`ti ${counselor.icon}`} />
        </div>
        <div className={`call-ring${status === 'speaking' ? ' speaking' : ''}`} />
      </div>
      <div className="call-name">{counselor.name}</div>
      <div className="call-timer">{timerText}</div>
      <div className="call-caption">{caption}</div>
      <div className="call-controls">
        <button
          className={`call-ctrl-btn${muted ? ' muted' : ''}`}
          onClick={onToggleMute}
          aria-label="Mute"
        >
          <i className={`ti ${muted ? 'ti-microphone-off' : 'ti-microphone'}`} />
        </button>
        <button className="call-ctrl-btn end" onClick={onEnd} aria-label="End call">
          <i className="ti ti-phone-x" />
        </button>
      </div>
      {freeCallMinutes !== null && (
        <p className="call-plan-note">
          Free members get {freeCallMinutes} minutes per call. <a href="/dashboard.php#upgrade">Upgrade to Pro</a> for
          longer sessions.
        </p>
      )}
    </div>
  );
}

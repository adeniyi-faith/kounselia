import { LINK_MARK, splitAtLink, useT } from '../i18n';
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
  const t = useT();
  const [noteBefore, noteAfter] = splitAtLink(
    t('c.call.free_note', { minutes: freeCallMinutes ?? 0, link: LINK_MARK }),
  );
  return (
    <div className={`call-overlay${active ? ' active' : ''}`}>
      <button className="call-close" onClick={onEnd} aria-label={t('c.call.end')}>
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
          aria-label={t('c.call.mute')}
        >
          <i className={`ti ${muted ? 'ti-microphone-off' : 'ti-microphone'}`} />
        </button>
        <button className="call-ctrl-btn end" onClick={onEnd} aria-label={t('c.call.end')}>
          <i className="ti ti-phone-x" />
        </button>
      </div>
      {freeCallMinutes !== null && (
        <p className="call-plan-note">
          {noteBefore}
          <a href="/dashboard.php#upgrade">{t('c.call.upgrade_link')}</a>
          {noteAfter}
        </p>
      )}
    </div>
  );
}

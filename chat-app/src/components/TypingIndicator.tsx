import { useEffect, useState } from 'react';
import type { Counselor } from '@kounselia/core';

// After 3.5s of waiting, we tell the user their message may be
// getting reviewed by more than one counselor, instead of just
// leaving three dots bouncing with no explanation.
export function TypingIndicator({ counselor }: { counselor: Counselor }) {
  const [consulting, setConsulting] = useState(false);

  useEffect(() => {
    const timer = setTimeout(() => setConsulting(true), 3500);
    return () => clearTimeout(timer);
  }, []);

  return (
    <div className="msg ai">
      <div className={`msg-av ${counselor.av}`}>
        <i className={`ti ${counselor.icon}`} style={{ fontSize: 13 }} />
      </div>
      <div
        className="msg-bubble"
        style={{ display: 'flex', gap: 5, alignItems: 'center', minWidth: 60, height: 34 }}
      >
        {consulting ? (
          <div
            style={{
              display: 'flex',
              gap: 6,
              alignItems: 'center',
              fontSize: 11,
              fontWeight: 700,
              color: 'var(--gold)',
              textTransform: 'uppercase',
              letterSpacing: 0.5,
            }}
          >
            <i className="ti ti-users" /> Consulting Team
            <span className="dot" style={{ marginLeft: 2, background: 'var(--gold)' }} />
            <span className="dot" style={{ background: 'var(--gold)' }} />
            <span className="dot" style={{ background: 'var(--gold)' }} />
          </div>
        ) : (
          <>
            <div className="dot" />
            <div className="dot" />
            <div className="dot" />
          </>
        )}
      </div>
    </div>
  );
}

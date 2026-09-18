import { useEffect, useState } from 'react';
import { forgotPassword, login, register } from '../core/auth';
import type { KounseliaConfig } from '../core/types';

export type AuthModalView = 'login' | 'register' | 'forgot' | null;

interface Props {
  config: KounseliaConfig;
  view: AuthModalView;
  onClose: () => void;
  onChangeView: (view: AuthModalView) => void;
  onAuthenticated: (name: string, nonce: string | undefined, isNewAccount: boolean) => void;
}

// A honeypot field real users never see or fill in; bots that auto-fill
// every input on a form do, so the server can quietly reject those.
function Honeypot({ id }: { id: string }) {
  return (
    <input
      type="text"
      id={id}
      name="website"
      tabIndex={-1}
      autoComplete="off"
      style={{ position: 'absolute', left: -9999, width: 1, height: 1, opacity: 0 }}
    />
  );
}

function PasswordField({ id, label, autoComplete }: { id: string; label: string; autoComplete: string }) {
  const [visible, setVisible] = useState(false);
  return (
    <div className="form-field">
      <label>{label}</label>
      <div className="pw-wrap">
        <input
          type={visible ? 'text' : 'password'}
          id={id}
          className="pw-input"
          placeholder={label === 'Password' ? '••••••••' : undefined}
          autoComplete={autoComplete}
        />
        <button type="button" className="pw-toggle" onClick={() => setVisible((v) => !v)}>
          <i className={`ti ${visible ? 'ti-eye-off' : 'ti-eye'}`} />
        </button>
      </div>
    </div>
  );
}

export function AuthModal({ config, view, onClose, onChangeView, onAuthenticated }: Props) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [resetSentTo, setResetSentTo] = useState<string | null>(null);
  const [successName, setSuccessName] = useState<string | null>(null);

  useEffect(() => {
    if (view) {
      setSuccessName(null);
      setError(null);
      setResetSentTo(null);
    }
  }, [view]);

  if (!view) return null;

  const fieldValue = (id: string) => (document.getElementById(id) as HTMLInputElement | null)?.value.trim() ?? '';
  const fieldRaw = (id: string) => (document.getElementById(id) as HTMLInputElement | null)?.value ?? '';

  const handleLogin = async () => {
    const email = fieldValue('l-e');
    const password = fieldRaw('l-p');
    const website = fieldRaw('l-hp');
    if (!email || !password) {
      setError('Please enter your credentials.');
      return;
    }
    setBusy(true);
    setError(null);
    const result = await login(config, email, password, website);
    setBusy(false);
    if (result.success) {
      onAuthenticated(result.name ?? '', result.nonce, false);
      setSuccessName(result.name ?? 'there');
    } else {
      setError(result.message || 'Sign in failed, please try again.');
    }
  };

  const handleRegister = async () => {
    const name = fieldValue('r-n');
    const email = fieldValue('r-e');
    const password = fieldRaw('r-p');
    const website = fieldRaw('r-hp');
    if (!name || !email || !password) {
      setError('Please fill in all fields.');
      return;
    }
    setBusy(true);
    setError(null);
    const result = await register(config, name, email, password, website);
    setBusy(false);
    if (result.success) {
      onAuthenticated(result.name ?? '', result.nonce, true);
      setSuccessName((result.name ?? '').split(' ')[0] || 'there');
    } else {
      setError(result.message || 'Could not create your account, please try again.');
    }
  };

  const handleForgot = async () => {
    const email = fieldValue('f-e');
    const website = fieldRaw('f-hp');
    if (!email) {
      setError('Please enter your email address.');
      return;
    }
    setBusy(true);
    setError(null);
    const result = await forgotPassword(config, email, website);
    setBusy(false);
    if (result.success) {
      setResetSentTo(email);
    } else {
      setError(result.message || 'Could not send the reset link, please try again.');
    }
  };

  return (
    <div className="modal-overlay open" style={{ display: 'flex' }} onClick={(e) => e.target === e.currentTarget && onClose()}>
      <div className="modal">
        <div className="modal-handle" />
        <button className="modal-close" onClick={onClose}>
          <i className="ti ti-x" />
        </button>
        <div>
          {successName ? (
            <div className="success-wrap">
              <div className="success-icon">
                <i className="ti ti-check" />
              </div>
              <h2 style={{ fontFamily: "'Cormorant Garamond',serif", fontSize: 26, fontWeight: 500, marginBottom: 10 }}>
                {view === 'register' ? `Welcome to Kounselia, ${successName}.` : `Welcome back, ${successName}.`}
              </h2>
              <p style={{ fontSize: 15, color: 'var(--text2)', fontWeight: 400, lineHeight: 1.65 }}>
                Your sessions are now saved. Unlimited conversations on the free plan.
              </p>
              <button className="modal-btn" style={{ marginTop: 24 }} onClick={onClose}>
                Continue my session
              </button>
            </div>
          ) : view === 'login' && (
            <>
              <h2>Welcome back</h2>
              <p className="sub">Your sessions and progress, right where you left off.</p>
              <div className="form-field">
                <label>Email address</label>
                <input type="email" id="l-e" placeholder="you@example.com" autoComplete="email" />
              </div>
              <PasswordField id="l-p" label="Password" autoComplete="current-password" />
              <p style={{ textAlign: 'right', marginTop: 8 }}>
                <a
                  onClick={() => onChangeView('forgot')}
                  style={{ fontSize: 13, color: 'var(--accent)', cursor: 'pointer', fontWeight: 500 }}
                >
                  Forgot your password?
                </a>
              </p>
              <Honeypot id="l-hp" />
              {error && <p style={{ color: 'var(--rose)', fontSize: 13, marginTop: 10 }}>{error}</p>}
              <button className="modal-btn" onClick={handleLogin} disabled={busy}>
                {busy ? 'Signing in...' : 'Sign in'}
              </button>
              <p className="modal-switch">
                No account? <a onClick={() => onChangeView('register')}>Create one free</a>
              </p>
            </>
          )}

          {!successName && view === 'forgot' &&
            (resetSentTo ? (
              <>
                <h2>Check your email</h2>
                <p className="sub">
                  If an account exists for {resetSentTo}, a password reset link is on its way. It can take a few
                  minutes to arrive.
                </p>
                <button className="modal-btn" onClick={() => onChangeView('login')}>
                  Back to sign in
                </button>
              </>
            ) : (
              <>
                <h2>Reset your password</h2>
                <p className="sub">Enter the email on your account and we will send you a link to set a new password.</p>
                <div className="form-field">
                  <label>Email address</label>
                  <input type="email" id="f-e" placeholder="you@example.com" autoComplete="email" />
                </div>
                <Honeypot id="f-hp" />
                {error && <p style={{ color: 'var(--rose)', fontSize: 13, marginTop: 10 }}>{error}</p>}
                <button className="modal-btn" onClick={handleForgot} disabled={busy}>
                  {busy ? 'Sending...' : 'Send reset link'}
                </button>
                <p className="modal-switch">
                  <a onClick={() => onChangeView('login')}>Back to sign in</a>
                </p>
              </>
            ))}

          {!successName && view === 'register' && (
            <>
              <h2>Create your account</h2>
              <p className="sub">Free to start. Save sessions, track your journey, never start over.</p>
              <div className="form-field">
                <label>Full name</label>
                <input type="text" id="r-n" placeholder="Your name" autoComplete="name" />
              </div>
              <div className="form-field">
                <label>Email address</label>
                <input type="email" id="r-e" placeholder="you@example.com" autoComplete="email" />
              </div>
              <PasswordField id="r-p" label="Password" autoComplete="new-password" />
              <Honeypot id="r-hp" />
              {error && <p style={{ color: 'var(--rose)', fontSize: 13, marginTop: 10 }}>{error}</p>}
              <button className="modal-btn" onClick={handleRegister} disabled={busy}>
                {busy ? 'Creating account...' : 'Create free account'}
              </button>
              <p className="modal-switch">
                Already have an account? <a onClick={() => onChangeView('login')}>Sign in</a>
              </p>
              <p className="modal-switch">
                Licensed therapist or counselor? <a href="/apply.php">Apply as a professional</a>
              </p>
            </>
          )}
        </div>
      </div>
    </div>
  );
}

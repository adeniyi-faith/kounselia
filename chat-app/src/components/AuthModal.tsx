import { useEffect, useState } from 'react';
import { forgotPassword, login, register } from '@kounselia/core';
import { LINK_MARK, splitAtLink, useT } from '../i18n';
import type { KounseliaConfig } from '@kounselia/core';

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

function PasswordField({ id, label, autoComplete, placeholder }: { id: string; label: string; autoComplete: string; placeholder?: string }) {
  const t = useT();
  const [visible, setVisible] = useState(false);
  return (
    <div className="form-field">
      <label>{label}</label>
      <div className="pw-wrap">
        <input
          type={visible ? 'text' : 'password'}
          id={id}
          className="pw-input"
          placeholder={placeholder}
          autoComplete={autoComplete}
        />
        <button
          type="button"
          className="pw-toggle"
          aria-label={visible ? t('c.auth.hide_password') : t('c.auth.show_password')}
          onClick={() => setVisible((v) => !v)}
        >
          <i className={`ti ${visible ? 'ti-eye-off' : 'ti-eye'}`} />
        </button>
      </div>
    </div>
  );
}

export function AuthModal({ config, view, onClose, onChangeView, onAuthenticated }: Props) {
  const t = useT();
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

  const name = successName?.trim() ?? '';
  const [noAccountBefore, noAccountAfter] = splitAtLink(t('c.auth.no_account', { link: LINK_MARK }));
  const [haveBefore, haveAfter] = splitAtLink(t('c.auth.have_account', { link: LINK_MARK }));
  const [applyBefore, applyAfter] = splitAtLink(t('c.auth.apply_line', { link: LINK_MARK }));

  const fieldValue = (id: string) => (document.getElementById(id) as HTMLInputElement | null)?.value.trim() ?? '';
  const fieldRaw = (id: string) => (document.getElementById(id) as HTMLInputElement | null)?.value ?? '';

  const handleLogin = async () => {
    const email = fieldValue('l-e');
    const password = fieldRaw('l-p');
    const website = fieldRaw('l-hp');
    if (!email || !password) {
      setError(t('c.auth.enter_credentials'));
      return;
    }
    setBusy(true);
    setError(null);
    const result = await login(config, email, password, website);
    setBusy(false);
    if (result.success) {
      onAuthenticated(result.name ?? '', result.nonce, false);
      setSuccessName(result.name || ' ');
    } else {
      setError(result.message || t('c.auth.signin_failed'));
    }
  };

  const handleRegister = async () => {
    const name = fieldValue('r-n');
    const email = fieldValue('r-e');
    const password = fieldRaw('r-p');
    const website = fieldRaw('r-hp');
    if (!name || !email || !password) {
      setError(t('c.auth.fill_all'));
      return;
    }
    setBusy(true);
    setError(null);
    const result = await register(config, name, email, password, website);
    setBusy(false);
    if (result.success) {
      onAuthenticated(result.name ?? '', result.nonce, true);
      setSuccessName((result.name ?? '').split(' ')[0] || ' ');
    } else {
      setError(result.message || t('c.auth.register_failed'));
    }
  };

  const handleForgot = async () => {
    const email = fieldValue('f-e');
    const website = fieldRaw('f-hp');
    if (!email) {
      setError(t('c.auth.enter_email'));
      return;
    }
    setBusy(true);
    setError(null);
    const result = await forgotPassword(config, email, website);
    setBusy(false);
    if (result.success) {
      setResetSentTo(email);
    } else {
      setError(result.message || t('c.auth.reset_failed'));
    }
  };

  return (
    <div className="modal-overlay open" style={{ display: 'flex' }} onClick={(e) => e.target === e.currentTarget && onClose()}>
      <div className="modal">
        <div className="modal-handle" />
        <button className="modal-close" onClick={onClose} aria-label={t('c.auth.close')}>
          <i className="ti ti-x" />
        </button>
        <div>
          {successName ? (
            <div className="success-wrap">
              <div className="success-icon">
                <i className="ti ti-check" />
              </div>
              <h2 style={{ fontFamily: "'Cormorant Garamond',serif", fontSize: 26, fontWeight: 500, marginBottom: 10 }}>
                {view === 'register'
                  ? name
                    ? t('c.auth.welcome_new', { name })
                    : t('c.auth.welcome_new_anon')
                  : name
                    ? t('c.auth.welcome_back', { name })
                    : t('c.auth.welcome_back_anon')}
              </h2>
              <p style={{ fontSize: 15, color: 'var(--text2)', fontWeight: 400, lineHeight: 1.65 }}>
                {t('c.auth.saved')}
              </p>
              <button className="modal-btn" style={{ marginTop: 24 }} onClick={onClose}>
                {t('c.auth.continue')}
              </button>
            </div>
          ) : view === 'login' && (
            <>
              <h2>{t('c.auth.login_title')}</h2>
              <p className="sub">{t('c.auth.login_sub')}</p>
              <div className="form-field">
                <label>{t('c.auth.email')}</label>
                <input type="email" id="l-e" placeholder="you@example.com" autoComplete="email" />
              </div>
              <PasswordField id="l-p" label={t('c.auth.password')} autoComplete="current-password" placeholder="••••••••" />
              <p style={{ textAlign: 'end', marginTop: 8 }}>
                <a
                  onClick={() => onChangeView('forgot')}
                  style={{ fontSize: 13, color: 'var(--accent)', cursor: 'pointer', fontWeight: 500 }}
                >
                  {t('c.auth.forgot_link')}
                </a>
              </p>
              <Honeypot id="l-hp" />
              {error && <p style={{ color: 'var(--rose)', fontSize: 13, marginTop: 10 }}>{error}</p>}
              <button className="modal-btn" onClick={handleLogin} disabled={busy}>
                {busy ? t('c.auth.signing_in') : t('c.auth.sign_in')}
              </button>
              <p className="modal-switch">
                {noAccountBefore}
                <a onClick={() => onChangeView('register')}>{t('c.auth.create_free_link')}</a>
                {noAccountAfter}
              </p>
            </>
          )}

          {!successName && view === 'forgot' &&
            (resetSentTo ? (
              <>
                <h2>{t('c.auth.check_email')}</h2>
                <p className="sub">
                  {t('c.auth.reset_sent', { email: resetSentTo })}
                </p>
                <button className="modal-btn" onClick={() => onChangeView('login')}>
                  {t('c.auth.back_to_signin')}
                </button>
              </>
            ) : (
              <>
                <h2>{t('c.auth.reset_title')}</h2>
                <p className="sub">{t('c.auth.reset_sub')}</p>
                <div className="form-field">
                  <label>{t('c.auth.email')}</label>
                  <input type="email" id="f-e" placeholder="you@example.com" autoComplete="email" />
                </div>
                <Honeypot id="f-hp" />
                {error && <p style={{ color: 'var(--rose)', fontSize: 13, marginTop: 10 }}>{error}</p>}
                <button className="modal-btn" onClick={handleForgot} disabled={busy}>
                  {busy ? t('c.auth.sending') : t('c.auth.send_reset')}
                </button>
                <p className="modal-switch">
                  <a onClick={() => onChangeView('login')}>{t('c.auth.back_to_signin')}</a>
                </p>
              </>
            ))}

          {!successName && view === 'register' && (
            <>
              <h2>{t('c.auth.create_title')}</h2>
              <p className="sub">{t('c.auth.create_sub')}</p>
              <div className="form-field">
                <label>{t('c.auth.full_name')}</label>
                <input type="text" id="r-n" placeholder={t('c.auth.your_name')} autoComplete="name" />
              </div>
              <div className="form-field">
                <label>{t('c.auth.email')}</label>
                <input type="email" id="r-e" placeholder="you@example.com" autoComplete="email" />
              </div>
              <PasswordField id="r-p" label={t('c.auth.password')} autoComplete="new-password" />
              <Honeypot id="r-hp" />
              {error && <p style={{ color: 'var(--rose)', fontSize: 13, marginTop: 10 }}>{error}</p>}
              <button className="modal-btn" onClick={handleRegister} disabled={busy}>
                {busy ? t('c.auth.creating') : t('c.auth.create_btn')}
              </button>
              <p className="modal-switch">
                {haveBefore}
                <a onClick={() => onChangeView('login')}>{t('c.auth.sign_in')}</a>
                {haveAfter}
              </p>
              <p className="modal-switch">
                {applyBefore}
                <a href="/apply.php">{t('c.auth.apply_link')}</a>
                {applyAfter}
              </p>
            </>
          )}
        </div>
      </div>
    </div>
  );
}

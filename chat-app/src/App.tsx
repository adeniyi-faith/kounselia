import { useEffect, useState } from 'react';
import { AuthModal, type AuthModalView } from './components/AuthModal';
import { ChatScreen } from './components/ChatScreen';
import type { CounselorMap, KounseliaConfig } from '@kounselia/core';

interface Props {
  config: KounseliaConfig;
  counselors: CounselorMap;
}

function slugFromHash(): string {
  return window.location.hash.replace(/^#/, '');
}

export function App({ config: initialConfig, counselors }: Props) {
  const [slug, setSlug] = useState(slugFromHash);
  const [config, setConfig] = useState(initialConfig);
  const [authView, setAuthView] = useState<AuthModalView>(null);

  useEffect(() => {
    const onHashChange = () => setSlug(slugFromHash());
    window.addEventListener('hashchange', onHashChange);
    return () => window.removeEventListener('hashchange', onHashChange);
  }, []);

  const counselor = counselors[slug];

  const handleAuthenticated = (_name: string, nonce: string | undefined) => {
    setConfig((prev) => ({ ...prev, loggedIn: true, nonce: nonce ?? prev.nonce }));
  };

  return (
    <>
      {!counselor ? (
        <div className="screen active" style={{ padding: 40, textAlign: 'center' }}>
          <p>Choose a counselor from your dashboard to start a conversation.</p>
          <a href="/dashboard.php">Go to dashboard</a>
        </div>
      ) : (
        <ChatScreen
          config={config}
          counselorSlug={slug}
          counselor={counselor}
          onBack={() => {
            window.location.href = '/dashboard.php';
          }}
          onRequestSignUp={() => setAuthView('register')}
          onRequestSignIn={() => setAuthView('login')}
        />
      )}

      <AuthModal
        config={config}
        view={authView}
        onClose={() => setAuthView(null)}
        onChangeView={setAuthView}
        onAuthenticated={handleAuthenticated}
      />
    </>
  );
}

import { useEffect, useState } from 'react';
import { ChatScreen } from './components/ChatScreen';
import type { CounselorMap, KounseliaConfig } from './core/types';

interface Props {
  config: KounseliaConfig;
  counselors: CounselorMap;
}

function slugFromHash(): string {
  return window.location.hash.replace(/^#/, '');
}

export function App({ config, counselors }: Props) {
  const [slug, setSlug] = useState(slugFromHash);

  useEffect(() => {
    const onHashChange = () => setSlug(slugFromHash());
    window.addEventListener('hashchange', onHashChange);
    return () => window.removeEventListener('hashchange', onHashChange);
  }, []);

  const counselor = counselors[slug];

  if (!counselor) {
    return (
      <div className="screen active" style={{ padding: 40, textAlign: 'center' }}>
        <p>Choose a counselor from your dashboard to start a conversation.</p>
        <a href="/dashboard.php">Go to dashboard</a>
      </div>
    );
  }

  return (
    <ChatScreen
      config={config}
      counselorSlug={slug}
      counselor={counselor}
      onBack={() => {
        window.location.href = '/dashboard.php';
      }}
      onRequestSignUp={() => {
        // The sign-up / sign-in modal is ported in a follow-up pass;
        // for now this sends guests to the dashboard where they can sign up.
        window.location.href = '/dashboard.php';
      }}
    />
  );
}

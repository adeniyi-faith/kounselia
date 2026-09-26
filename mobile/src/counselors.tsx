// The list of counselors, fetched once from the server and shared by the
// Talk tab and the chat screen. Admins can add, edit or switch off
// counselors at any time, so it's never built into the app itself.
import { fetchCounselors, type CounselorSummary } from '@kounselia/core';
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { useSession } from './session';

interface Counselors {
  status: 'loading' | 'ok' | 'error';
  counselors: CounselorSummary[];
  bySlug(slug: string): CounselorSummary | undefined;
  reload(): Promise<void>;
}

const CounselorsContext = createContext<Counselors | null>(null);

export function CounselorsProvider({ children }: { children: ReactNode }) {
  const { config } = useSession();
  const [status, setStatus] = useState<Counselors['status']>('loading');
  const [counselors, setCounselors] = useState<CounselorSummary[]>([]);

  const reload = useCallback(async () => {
    const result = await fetchCounselors(config);
    if (result.status === 'ok') {
      setCounselors(result.counselors);
      setStatus('ok');
    } else {
      // Keep showing a list we already have if a refresh fails.
      setStatus((prev) => (prev === 'ok' ? 'ok' : 'error'));
    }
  }, [config]);

  useEffect(() => {
    // Loading from the server once signed in is what this effect is for.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    reload();
  }, [reload]);

  const value = useMemo<Counselors>(
    () => ({ status, counselors, reload, bySlug: (slug) => counselors.find((c) => c.slug === slug) }),
    [status, counselors, reload],
  );

  return <CounselorsContext.Provider value={value}>{children}</CounselorsContext.Provider>;
}

export function useCounselors(): Counselors {
  const value = useContext(CounselorsContext);
  if (!value) throw new Error('useCounselors must be used inside <CounselorsProvider>');
  return value;
}

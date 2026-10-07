// The professional's home data (the website's pro-dashboard.php), loaded
// once for all of their tabs and reloaded after anything changes, so the
// Overview's numbers, Bookings, Articles and Earnings always agree.
import { fetchProDashboard, type ProDashboard } from '@kounselia/core';
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { AppState } from 'react-native';
import { useSession } from '@/session';

interface ProData {
  data: ProDashboard | null;
  // Set when it couldn't be loaded (no data yet to show instead).
  failed: boolean;
  reload(): Promise<void>;
}

const ProContext = createContext<ProData | null>(null);

export function ProDashboardProvider({ children }: { children: ReactNode }) {
  const { config, user, updateUser, setViewMode } = useSession();
  const [data, setData] = useState<ProDashboard | null>(null);
  const [failed, setFailed] = useState(false);
  // Kept in refs so a change to the saved sign-in (which reload itself
  // can cause) doesn't start another load.
  const userRef = useRef(user);
  const actions = useRef({ updateUser, setViewMode });
  useEffect(() => {
    userRef.current = user;
    actions.current = { updateUser, setViewMode };
  }, [user, updateUser, setViewMode]);

  const reload = useCallback(async () => {
    const res = await fetchProDashboard(config);
    if (res.ok) {
      setData(res.data);
      setFailed(false);
      // An admin may have approved (or suspended) them since the app last
      // looked; keep the saved sign-in in step.
      const known = userRef.current?.professional;
      if (known && known.status !== res.data.application.status) {
        actions.current.updateUser({ professional: { id: res.data.application.id, status: res.data.application.status } });
      }
    } else if (!res.offline && /no professional application/i.test(res.message)) {
      // Their application is gone (e.g. removed by an admin): back to the client side.
      actions.current.updateUser({ professional: null });
      actions.current.setViewMode('client');
    } else {
      setFailed(true);
    }
  }, [config]);

  useEffect(() => {
    // Loading from the server when the professional home opens is what this effect is for.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    reload();
    // Fresh numbers (a new booking, a payout) whenever the app comes back.
    const sub = AppState.addEventListener('change', (state) => {
      if (state === 'active') reload();
    });
    return () => sub.remove();
  }, [reload]);

  const value = useMemo(() => ({ data, failed, reload }), [data, failed, reload]);
  return <ProContext.Provider value={value}>{children}</ProContext.Provider>;
}

export function usePro(): ProData {
  const ctx = useContext(ProContext);
  if (!ctx) throw new Error('usePro must be used inside <ProDashboardProvider>');
  return ctx;
}

/** ₦12,500 */
export function naira(amount: number | null | undefined): string {
  return `₦${Math.round(amount ?? 0).toLocaleString('en-NG')}`;
}

// Who is signed in, for the whole app.
//
// Signing in gets a token from the server (see packages/core/src/appAuth.ts).
// It's kept in the phone's secure storage (the iOS Keychain / Android
// Keystore), so the member stays signed in between launches, and it's
// attached to every request through `config`.
import {
  appLogin,
  appLogout,
  appRegister,
  applyAsProfessional,
  deleteAccount as deleteAccountOnServer,
  fetchAppUser,
  type AppAuthResult,
  type AppUser,
  type ApplicationDetails,
  type KounseliaConfig,
  type PickedDocument,
} from '@kounselia/core';
import * as Device from 'expo-device';
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { clearLockSetting } from './appLockSetting';
import { showDialog } from './components/Dialog';
import { AJAX_URL } from './config';
import { forgetPush, savedPushToken } from './notifications';
import { deleteSecure, readSecure, writeSecure } from './secureStorage';

const TOKEN_KEY = 'kounselia_app_token';
const USER_KEY = 'kounselia_app_user';
// 'client' once a professional has switched to the client side of the app.
const VIEW_KEY = 'kounselia_app_view';

type Status = 'loading' | 'signed-in' | 'signed-out';

// Which side of the app a professional sees: their own professional home
// (as the website's pro-dashboard.php), or the client side, which every
// professional can use too ("Switch to client view" on the website).
export type ViewMode = 'professional' | 'client';

interface Session {
  status: Status;
  user: AppUser | null;
  // Pass this to every @kounselia/core call.
  config: KounseliaConfig;
  signIn(email: string, password: string): Promise<AppAuthResult>;
  signUp(name: string, email: string, password: string): Promise<AppAuthResult>;
  signOut(): Promise<void>;
  // Permanently deletes the account on the server, then signs out here.
  // Resolves to the server's reason if it refused, or null when done.
  deleteAccount(password: string): Promise<string | null>;
  // After the member changes their details in Settings.
  updateUser(changes: Partial<AppUser>): void;
  // 'professional' for anyone who has applied as one, unless they switched
  // to the client side; always 'client' for everyone else.
  viewMode: ViewMode;
  setViewMode(mode: ViewMode): void;
  // Applies as a professional (apply.php on the website). Signed out,
  // `account` creates the account in the same step and signs in with it.
  // Resolves to the server's reason if it refused, or null when done.
  applyAsProfessional(
    details: ApplicationDetails,
    licenseDoc: PickedDocument,
    idDoc: PickedDocument | null,
    account?: { name: string; email: string; password: string },
  ): Promise<string | null>;
}

const SessionContext = createContext<Session | null>(null);

function parseUser(json: string | null): AppUser | null {
  try {
    return json ? (JSON.parse(json) as AppUser) : null;
  } catch {
    return null;
  }
}

function makeConfig(token: string | null, onSignedOut?: () => void): KounseliaConfig {
  return { ajaxUrl: AJAX_URL, client: 'app', authToken: token, loggedIn: !!token, onSignedOut };
}

export function SessionProvider({ children }: { children: ReactNode }) {
  const [status, setStatus] = useState<Status>('loading');
  const [token, setToken] = useState<string | null>(null);
  const [user, setUser] = useState<AppUser | null>(null);
  const [chosenView, setChosenView] = useState<ViewMode | null>(null);

  // On launch: use the saved sign-in straight away (so the app opens
  // instantly, even offline), then check with the server that it still
  // works — it won't if the member changed their password elsewhere.
  useEffect(() => {
    (async () => {
      const [savedToken, savedUser, savedView] = await Promise.all([
        readSecure(TOKEN_KEY),
        readSecure(USER_KEY),
        readSecure(VIEW_KEY),
      ]);
      setChosenView(savedView === 'client' ? 'client' : null);
      if (!savedToken) {
        setStatus('signed-out');
        return;
      }
      setToken(savedToken);
      setUser(parseUser(savedUser));
      setStatus('signed-in');

      const check = await fetchAppUser(makeConfig(savedToken));
      if (check.status === 'signed-in') {
        setUser(check.user);
        writeSecure(USER_KEY, JSON.stringify(check.user));
      } else if (check.status === 'signed-out') {
        await forget();
      }
      // 'offline': keep the saved sign-in; the next request will tell.
    })();
  }, []);

  async function forget() {
    setToken(null);
    setUser(null);
    setChosenView(null);
    setStatus('signed-out');
    // The app lock and notification choice belong to whoever was signed
    // in; the next person starts fresh.
    await Promise.all([
      deleteSecure(TOKEN_KEY),
      deleteSecure(USER_KEY),
      deleteSecure(VIEW_KEY),
      clearLockSetting(),
      forgetPush(),
    ]);
  }

  const remember = useCallback(async (result: AppAuthResult) => {
    if (result.success) {
      await Promise.all([
        writeSecure(TOKEN_KEY, result.token),
        writeSecure(USER_KEY, JSON.stringify(result.user)),
      ]);
      expired.current = false;
      setToken(result.token);
      setUser(result.user);
      setStatus('signed-in');
    }
    return result;
  }, []);

  // The server can end a sign-in at any time (password changed on the
  // website, signed out by an admin). Any request that finds out sends the
  // member back to the welcome screen, with one explanation.
  const expired = useRef(false);
  //
  // WordPress gives the same bare "0" reply for "you're signed out" and for
  // an action it doesn't know (e.g. an app newer than the website), so we
  // double-check with the server before signing anyone out.
  const tokenRef = useRef<string | null>(null);
  useEffect(() => {
    tokenRef.current = token;
  }, [token]);
  const onSignedOut = useCallback(async () => {
    if (expired.current || !tokenRef.current) return;
    expired.current = true;
    const check = await fetchAppUser(makeConfig(tokenRef.current));
    if (check.status !== 'signed-out') {
      expired.current = false; // Still signed in; that request just failed.
      return;
    }
    forget();
    showDialog({ title: 'Please sign in again', message: 'You were signed out of Kounselia on this phone. This happens if your password was changed.', icon: 'lock' });
  }, []);

  const config = useMemo(() => makeConfig(token, onSignedOut), [token, onSignedOut]);
  const viewMode: ViewMode = user?.professional && chosenView !== 'client' ? 'professional' : 'client';
  const setViewMode = useCallback((mode: ViewMode) => {
    setChosenView(mode);
    writeSecure(VIEW_KEY, mode);
  }, []);

  const value = useMemo<Session>(
    () => ({
      status,
      user,
      config,
      signIn: async (email, password) =>
        remember(await appLogin(makeConfig(null), email, password, Device.deviceName ?? '')),
      signUp: async (name, email, password) =>
        remember(await appRegister(makeConfig(null), name, email, password, Device.deviceName ?? '')),
      signOut: async () => {
        // Forget locally first, so signing out works even with no signal.
        const old = config;
        const pushToken = await savedPushToken();
        await forget();
        await appLogout(old, pushToken);
      },
      deleteAccount: async (password) => {
        const res = await deleteAccountOnServer(config, password);
        if (!res.ok) return res.message;
        // The server has already signed this phone out and erased its push token.
        expired.current = true;
        await forget();
        return null;
      },
      updateUser: (changes) => {
        if (!user) return;
        const next = { ...user, ...changes };
        setUser(next);
        writeSecure(USER_KEY, JSON.stringify(next));
      },
      viewMode,
      setViewMode,
      applyAsProfessional: async (details, licenseDoc, idDoc, account) => {
        const signedIn = !!token;
        const res = await applyAsProfessional(
          signedIn ? config : makeConfig(null),
          details,
          licenseDoc,
          idDoc,
          signedIn || !account ? undefined : { ...account, deviceName: Device.deviceName ?? '' },
        );
        if (!res.success) return res.message;
        // A fresh application opens their professional home.
        setViewMode('professional');
        if (res.token) {
          await remember({ success: true, token: res.token, user: res.user });
        } else if (user) {
          const next = { ...user, ...res.user };
          setUser(next);
          writeSecure(USER_KEY, JSON.stringify(next));
        }
        return null;
      },
    }),
    [status, user, config, remember, token, viewMode, setViewMode],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession(): Session {
  const session = useContext(SessionContext);
  if (!session) throw new Error('useSession must be used inside <SessionProvider>');
  return session;
}

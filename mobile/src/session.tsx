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
  fetchAppUser,
  type AppAuthResult,
  type AppUser,
  type KounseliaConfig,
} from '@kounselia/core';
import * as Device from 'expo-device';
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { AJAX_URL } from './config';
import { deleteSecure, readSecure, writeSecure } from './secureStorage';

const TOKEN_KEY = 'kounselia_app_token';
const USER_KEY = 'kounselia_app_user';

type Status = 'loading' | 'signed-in' | 'signed-out';

interface Session {
  status: Status;
  user: AppUser | null;
  // Pass this to every @kounselia/core call.
  config: KounseliaConfig;
  signIn(email: string, password: string): Promise<AppAuthResult>;
  signUp(name: string, email: string, password: string): Promise<AppAuthResult>;
  signOut(): Promise<void>;
}

const SessionContext = createContext<Session | null>(null);

function parseUser(json: string | null): AppUser | null {
  try {
    return json ? (JSON.parse(json) as AppUser) : null;
  } catch {
    return null;
  }
}

function makeConfig(token: string | null): KounseliaConfig {
  return { ajaxUrl: AJAX_URL, client: 'app', authToken: token, loggedIn: !!token };
}

export function SessionProvider({ children }: { children: ReactNode }) {
  const [status, setStatus] = useState<Status>('loading');
  const [token, setToken] = useState<string | null>(null);
  const [user, setUser] = useState<AppUser | null>(null);

  // On launch: use the saved sign-in straight away (so the app opens
  // instantly, even offline), then check with the server that it still
  // works — it won't if the member changed their password elsewhere.
  useEffect(() => {
    (async () => {
      const [savedToken, savedUser] = await Promise.all([
        readSecure(TOKEN_KEY),
        readSecure(USER_KEY),
      ]);
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
    setStatus('signed-out');
    await Promise.all([
      deleteSecure(TOKEN_KEY),
      deleteSecure(USER_KEY),
    ]);
  }

  const remember = useCallback(async (result: AppAuthResult) => {
    if (result.success) {
      await Promise.all([
        writeSecure(TOKEN_KEY, result.token),
        writeSecure(USER_KEY, JSON.stringify(result.user)),
      ]);
      setToken(result.token);
      setUser(result.user);
      setStatus('signed-in');
    }
    return result;
  }, []);

  const config = useMemo(() => makeConfig(token), [token]);

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
        await forget();
        await appLogout(old);
      },
    }),
    [status, user, config, remember],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession(): Session {
  const session = useContext(SessionContext);
  if (!session) throw new Error('useSession must be used inside <SessionProvider>');
  return session;
}

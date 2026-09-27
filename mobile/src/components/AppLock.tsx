// The app lock: when it's on (Settings → App lock), Kounselia asks for the
// phone's Face ID, fingerprint or passcode when it opens, and again when
// the member comes back to it after being away (straight away, or after 1
// or 5 minutes — their choice). It also hides the screen in the phone's
// app switcher, so private conversations don't show there.
//
// Off by default. Only matters while someone is signed in.
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { AppState, Modal, Platform, StyleSheet, Text, View } from 'react-native';
import { confirmOwner, lockPaused, readLockSetting, unlockMethodName, writeLockSetting, type LockAfter } from '@/appLockSetting';
import { useSession } from '@/session';
import { fonts, makeStyles, useColors } from '@/theme';
import { Button } from './Button';
import { showDialog } from './Dialog';
import { TablerIcon } from './TablerIcon';

interface AppLock {
  // null = off; undefined = not read yet.
  after: LockAfter | null | undefined;
  setAfter(after: LockAfter | null): Promise<void>;
}

const AppLockContext = createContext<AppLock | null>(null);

export function useAppLock(): AppLock {
  const lock = useContext(AppLockContext);
  if (!lock) throw new Error('useAppLock must be used inside <AppLockProvider>');
  return lock;
}

export function AppLockProvider({ children }: { children: ReactNode }) {
  const { status } = useSession();
  const [after, setAfterState] = useState<LockAfter | null | undefined>(undefined);
  const [locked, setLocked] = useState(false);
  const [away, setAway] = useState(AppState.currentState === 'background' || AppState.currentState === 'inactive');
  const leftAt = useRef<number | null>(null);
  const afterRef = useRef(after);
  useEffect(() => {
    afterRef.current = after;
  }, [after]);

  const statusRef = useRef(status);
  useEffect(() => {
    statusRef.current = status;
  }, [status]);

  // Opening the app: locked from the start if the lock is on. (Signing in
  // later needs the password anyway, so that never locks.)
  useEffect(() => {
    readLockSetting().then((saved) => {
      const signedOut = statusRef.current === 'signed-out';
      setAfterState(signedOut ? null : saved);
      if (saved !== null && !signedOut) setLocked(true);
    });
  }, []);

  // Signing out turns the lock off (session.tsx clears the saved setting).
  const [seenStatus, setSeenStatus] = useState(status);
  if (status !== seenStatus) {
    setSeenStatus(status);
    if (status === 'signed-out') {
      setLocked(false);
      setAfterState(null);
    }
  }

  useEffect(() => {
    const sub = AppState.addEventListener('change', (next) => {
      setAway(next === 'background' || next === 'inactive');
      if (next === 'background') {
        if (!lockPaused()) leftAt.current = Date.now();
      } else if (next === 'active' && leftAt.current !== null) {
        const gone = (Date.now() - leftAt.current) / 1000;
        leftAt.current = null;
        const limit = afterRef.current;
        if (limit !== null && limit !== undefined && gone >= limit) setLocked(true);
      }
    });
    return () => sub.remove();
  }, []);

  const setAfter = useCallback(async (next: LockAfter | null) => {
    await writeLockSetting(next);
    setAfterState(next);
  }, []);

  const value = useMemo(() => ({ after, setAfter }), [after, setAfter]);
  const signedIn = status === 'signed-in';
  const on = after !== null && after !== undefined;
  // Still reading the setting: keep the screen covered rather than risk a
  // glimpse of it before the lock appears.
  const cover = signedIn && (after === undefined || (on && (away || locked)));

  return (
    <AppLockContext.Provider value={value}>
      {children}
      {cover && !locked ? <Cover /> : null}
      {signedIn && locked ? <LockScreen onUnlocked={() => setLocked(false)} /> : null}
    </AppLockContext.Provider>
  );
}

// Plain Kounselia colours over everything, for the app switcher's snapshot.
function Cover() {
  const colors = useColors();
  return (
    <View style={[StyleSheet.absoluteFill, { backgroundColor: colors.bg, alignItems: 'center', justifyContent: 'center' }]} pointerEvents="none">
      <TablerIcon name="lock" size={40} color={colors.text3} />
    </View>
  );
}

function LockScreen({ onUnlocked }: { onUnlocked: () => void }) {
  const styles = useStyles();
  const colors = useColors();
  const { signOut } = useSession();
  const [method, setMethod] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [note, setNote] = useState('');
  const trying = useRef(false);

  const unlock = useCallback(async () => {
    if (trying.current) return;
    trying.current = true;
    setBusy(true);
    setNote('');
    const res = await confirmOwner('Unlock Kounselia');
    trying.current = false;
    setBusy(false);
    if (res.ok) {
      onUnlocked();
      return;
    }
    if (res.error === 'not_enrolled' || res.error === 'passcode_not_set' || res.error === 'not_available') {
      setNote('Your phone no longer has a screen lock, so Kounselia can’t check it’s you. Sign out and back in with your password.');
    } else if (res.error === 'lockout') {
      setNote('Too many tries. Unlock your phone with its passcode first, then try again.');
    }
  }, [onUnlocked]);

  useEffect(() => {
    unlockMethodName().then(setMethod);
  }, []);

  // Ask straight away, and each time the member comes back to the app
  // while it's still locked (not while it's in the background: the phone
  // would refuse, or ask at the wrong moment).
  useEffect(() => {
    // A moment's pause first, so the lock screen is showing before the
    // phone's prompt appears over it.
    const first = setTimeout(() => {
      if (AppState.currentState === 'active') unlock();
    }, 300);
    const sub = AppState.addEventListener('change', (next) => {
      if (next === 'active') unlock();
    });
    return () => {
      clearTimeout(first);
      sub.remove();
    };
  }, [unlock]);

  function confirmSignOut() {
    showDialog({
      title: 'Sign out?',
      message: 'You can sign back in with your email and password. The app lock will be turned off.',
      icon: 'logout',
      buttons: [
        { text: 'Cancel', style: 'cancel' },
        { text: 'Sign out', style: 'destructive', onPress: () => signOut() },
      ],
    });
  }

  return (
    <Modal visible transparent={false} animationType="none" statusBarTranslucent navigationBarTranslucent onRequestClose={() => undefined}>
      <View style={styles.screen} accessibilityViewIsModal>
        <View style={styles.badge}>
          <TablerIcon name="lock" size={34} color={colors.accentText} />
        </View>
        <Text style={styles.title} accessibilityRole="header">
          Kounselia is locked
        </Text>
        <Text style={styles.text}>
          {method ? `Use ${method} to open your conversations and journal.` : 'Unlock to open your conversations and journal.'}
        </Text>
        {note ? <Text style={[styles.text, { color: colors.rose }]}>{note}</Text> : null}
        <Button title="Unlock" onPress={unlock} busy={busy} style={styles.button} />
        <Button title="Sign out instead" variant="ghost" onPress={confirmSignOut} style={styles.button} />
      </View>
    </Modal>
  );
}

const useStyles = makeStyles((colors) => ({
  screen: {
    flex: 1,
    backgroundColor: colors.bg,
    alignItems: 'center',
    justifyContent: 'center',
    padding: 32,
    paddingTop: Platform.OS === 'android' ? 48 : 32,
  },
  badge: {
    width: 76,
    height: 76,
    borderRadius: 38,
    backgroundColor: colors.accentLight,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 20,
  },
  title: { fontFamily: fonts.serifMedium, fontSize: 28, color: colors.text, textAlign: 'center' },
  text: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 22, color: colors.text2, textAlign: 'center', marginTop: 10, maxWidth: 340 },
  button: { alignSelf: 'stretch', marginTop: 16, maxWidth: 360, width: '100%' },
}));

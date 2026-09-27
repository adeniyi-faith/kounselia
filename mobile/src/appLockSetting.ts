// The app lock setting, kept on this phone only (in secure storage).
// Kept apart from components/AppLock.tsx so signing out (session.tsx) can
// clear it without the two files importing each other.
import * as LocalAuthentication from 'expo-local-authentication';
import { Platform } from 'react-native';
import { deleteSecure, readSecure, writeSecure } from './secureStorage';

const KEY = 'kounselia_app_lock';

// How long Kounselia can be in the background before it asks again.
export type LockAfter = 0 | 60 | 300; // seconds
export const LOCK_AFTER_CHOICES: { value: LockAfter; label: string }[] = [
  { value: 0, label: 'Immediately' },
  { value: 60, label: 'After 1 min' },
  { value: 300, label: 'After 5 min' },
];

/** null when the lock is off. */
export async function readLockSetting(): Promise<LockAfter | null> {
  const saved = await readSecure(KEY);
  if (saved === null) return null;
  const n = Number(saved);
  return n === 0 || n === 60 || n === 300 ? n : 60;
}

export function writeLockSetting(after: LockAfter | null): Promise<void> {
  return after === null ? deleteSecure(KEY) : writeSecure(KEY, String(after));
}

/** Off again, for the next person to sign in on this phone. */
export function clearLockSetting(): Promise<void> {
  return deleteSecure(KEY);
}

/**
 * What the phone can unlock with, in words ("Face ID", "fingerprint"), or
 * null if it has no screen lock at all — then the app lock can't be used.
 * Even without a face or fingerprint saved, the phone's own passcode,
 * PIN or pattern works.
 */
export async function unlockMethodName(): Promise<string | null> {
  if (Platform.OS === 'web') return null;
  try {
    const level = await LocalAuthentication.getEnrolledLevelAsync();
    if (level === LocalAuthentication.SecurityLevel.NONE) return null;
    if (level === LocalAuthentication.SecurityLevel.SECRET) return Platform.OS === 'ios' ? 'your passcode' : 'your screen lock';
    const types = await LocalAuthentication.supportedAuthenticationTypesAsync();
    const face = types.includes(LocalAuthentication.AuthenticationType.FACIAL_RECOGNITION);
    const finger = types.includes(LocalAuthentication.AuthenticationType.FINGERPRINT);
    // An iPhone has one or the other.
    if (Platform.OS === 'ios') return face ? 'Face ID' : finger ? 'Touch ID' : 'your passcode';
    // Many Android phones have both, and the fingerprint is usually the one
    // people use, so it comes first.
    if (finger && face) return 'your fingerprint or face';
    if (finger) return 'your fingerprint';
    if (face) return 'face unlock';
    return 'your screen lock';
  } catch {
    return null;
  }
}

/** Shows the phone's own Face ID / fingerprint / passcode prompt. */
export async function confirmOwner(reason: string): Promise<{ ok: true } | { ok: false; error: string }> {
  try {
    const res = await LocalAuthentication.authenticateAsync({
      promptMessage: reason,
      cancelLabel: 'Cancel',
      // Lets the phone's passcode / PIN / pattern be used too, e.g. after
      // a few failed face or fingerprint tries.
      disableDeviceFallback: false,
    });
    return res.success ? { ok: true } : { ok: false, error: res.error };
  } catch {
    return { ok: false, error: 'unknown' };
  }
}

// The image picker, the notification permission prompt and the like take
// the member out of the app for a moment; that shouldn't count as leaving.
let paused = 0;
export async function withoutLocking<T>(task: () => Promise<T>): Promise<T> {
  paused += 1;
  try {
    return await task();
  } finally {
    // Returning to the app is reported just after the task finishes.
    setTimeout(() => {
      paused -= 1;
    }, 1500);
  }
}
export function lockPaused(): boolean {
  return paused > 0;
}

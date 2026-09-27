// Push notifications: session reminders, booking changes, replies to the
// member's comments — whatever the server sends through
// kounselia_notify_user() (portal/.../includes/notifications.php).
//
// How it works: with the member's permission, the phone gets an "Expo push
// token" (an address for this app on this phone) from Expo's push service.
// We send that to our server, which sends notifications to it through Expo;
// Expo passes them on to Apple or Google, who deliver them.
//
// Nothing here asks for permission on its own at launch. The member is
// asked when they book a session ("Remind me before it?") or when they
// switch notifications on in Settings.
//
// Needs the app linked to an Expo project (`eas init` puts its id in
// app.json). Until then pushState() says 'unavailable' and Settings hides
// the switch.
import { registerPushToken, unregisterPushToken, type KounseliaConfig } from '@kounselia/core';
import Constants from 'expo-constants';
import * as Device from 'expo-device';
import * as Notifications from 'expo-notifications';
import { router, type Href } from 'expo-router';
import { useEffect, useRef } from 'react';
import { Platform } from 'react-native';
import { withoutLocking } from './appLockSetting';
import { showDialog } from './components/Dialog';
import { deleteSecure, readSecure, writeSecure } from './secureStorage';

const TOKEN_KEY = 'kounselia_push_token';
// 'on' or 'off' once the member has chosen; nothing before that.
const CHOICE_KEY = 'kounselia_push_choice';

// Show notifications that arrive while the app is open too (as a banner),
// not only when it's in the background.
if (Platform.OS !== 'web') {
  Notifications.setNotificationHandler({
    handleNotification: async () => ({
      shouldShowBanner: true,
      shouldShowList: true,
      shouldPlaySound: true,
      shouldSetBadge: false,
    }),
  });
}

function projectId(): string | undefined {
  return Constants.expoConfig?.extra?.eas?.projectId ?? Constants.easConfig?.projectId;
}

export type PushState =
  | 'on'
  | 'off'
  | 'blocked' // said no in the phone's own prompt; only the phone's Settings can undo that
  | 'unavailable'; // web, a simulator without push, or the app isn't linked to Expo yet

async function androidChannel() {
  // Android 13+ only shows the permission prompt once a channel exists.
  if (Platform.OS !== 'android') return;
  await Notifications.setNotificationChannelAsync('default', {
    name: 'Reminders and updates',
    importance: Notifications.AndroidImportance.HIGH,
  }).catch(() => undefined);
}

async function currentToken(): Promise<string | null> {
  const id = projectId();
  if (!id) return null;
  try {
    return (await Notifications.getExpoPushTokenAsync({ projectId: id })).data;
  } catch {
    return null;
  }
}

async function send(config: KounseliaConfig, token: string) {
  const platform = Platform.OS === 'ios' ? 'ios' : 'android';
  if (await registerPushToken(config, platform, token)) await writeSecure(TOKEN_KEY, token);
}

export async function pushState(): Promise<PushState> {
  if (Platform.OS === 'web' || !Device.isDevice || !projectId()) return 'unavailable';
  const [perm, choice] = await Promise.all([Notifications.getPermissionsAsync().catch(() => null), readSecure(CHOICE_KEY)]);
  if (!perm) return 'unavailable';
  if (perm.status === 'granted') return choice === 'off' ? 'off' : 'on';
  return perm.canAskAgain ? 'off' : 'blocked';
}

/** Asks the phone for permission if needed, then starts sending here. */
export async function turnOnPush(config: KounseliaConfig): Promise<PushState> {
  if ((await pushState()) === 'unavailable') return 'unavailable';
  await androidChannel();
  let perm = await Notifications.getPermissionsAsync();
  if (perm.status !== 'granted' && perm.canAskAgain) perm = await Notifications.requestPermissionsAsync();
  if (perm.status !== 'granted') {
    await writeSecure(CHOICE_KEY, 'off');
    return perm.canAskAgain ? 'off' : 'blocked';
  }
  await writeSecure(CHOICE_KEY, 'on');
  const token = await currentToken();
  if (token) await send(config, token);
  return 'on';
}

/** Stops notifications to this phone (the member can turn them back on). */
export async function turnOffPush(config: KounseliaConfig): Promise<void> {
  await writeSecure(CHOICE_KEY, 'off');
  const token = await readSecure(TOKEN_KEY);
  if (token) await unregisterPushToken(config, token);
  await deleteSecure(TOKEN_KEY);
}

/** This phone's push token, if it has registered one (sent when signing out). */
export function savedPushToken(): Promise<string | null> {
  return readSecure(TOKEN_KEY);
}

/** Forgets this phone's choice, for the next person to sign in on it. */
export async function forgetPush(): Promise<void> {
  await Promise.all([deleteSecure(TOKEN_KEY), deleteSecure(CHOICE_KEY)]);
}

/**
 * After booking: offers to turn on reminders, if the member hasn't said
 * yes or no before and the app can send them. Otherwise just shows the
 * "You're booked" message as it is.
 */
export async function showBookedDialog(config: KounseliaConfig, title: string, message: string) {
  const [state, choice] = await Promise.all([pushState(), readSecure(CHOICE_KEY)]);
  if (state !== 'off' || choice) {
    showDialog({ title, message, icon: 'calendar-check', tone: 'success' });
    return;
  }
  showDialog({
    title,
    message: `${message}\n\nWould you like a notification on this phone before it starts?`,
    icon: 'calendar-check',
    tone: 'success',
    buttons: [
      { text: 'Not now', style: 'cancel', onPress: () => writeSecure(CHOICE_KEY, 'off') },
      { text: 'Remind me', onPress: () => withoutLocking(() => turnOnPush(config)) },
    ],
  });
}

// Where tapping a notification goes. The server sends website links (the
// same notification appears on the website's bell), so they're matched to
// the app's own screens here; anything else opens Home.
export function routeForLink(url: string): Href {
  const path = url.replace(/^https?:\/\/[^/]+/, '');
  const article = /^\/blog\/([^/?#]+)/.exec(path);
  if (article && article[1] !== 'tag') return { pathname: '/articles/[slug]', params: { slug: decodeURIComponent(article[1]) } };
  if (path.includes('tab=upgrade')) return '/plan';
  if (path.startsWith('/dashboard.php#professionals') || path.includes('booking')) return '/sessions';
  return '/';
}

/**
 * For the signed-in part of the app: keeps this phone's push token current
 * with the server (it can change), and opens the right screen when a
 * notification is tapped — including one that launched the app.
 */
export function usePushNotifications(config: KounseliaConfig) {
  const configRef = useRef(config);
  useEffect(() => {
    configRef.current = config;
  }, [config]);

  useEffect(() => {
    if (Platform.OS === 'web') return;
    let cancelled = false;
    (async () => {
      if ((await pushState()) !== 'on' || cancelled) return;
      await androidChannel();
      const token = await currentToken();
      if (token && !cancelled) await send(configRef.current, token);
    })();
    const sub = Notifications.addPushTokenListener(async () => {
      if ((await pushState()) !== 'on') return;
      const token = await currentToken();
      if (token) await send(configRef.current, token);
    });
    return () => {
      cancelled = true;
      sub.remove();
    };
  }, []);

  const response = Notifications.useLastNotificationResponse();
  const handled = useRef<string | null>(null);
  useEffect(() => {
    if (!response || response.actionIdentifier !== Notifications.DEFAULT_ACTION_IDENTIFIER) return;
    const id = response.notification.request.identifier;
    if (handled.current === id) return;
    handled.current = id;
    const url = response.notification.request.content.data?.url;
    router.push(routeForLink(typeof url === 'string' ? url : ''));
    Notifications.clearLastNotificationResponseAsync().catch(() => undefined);
  }, [response]);
}

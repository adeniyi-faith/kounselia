import { deviceLanguage, fetchBookingRoom, makeT, type KounseliaConfig, type Translate } from '@kounselia/core';
import * as Linking from 'expo-linking';
import { openVideoCall } from '@/browser/VideoCallProvider';

// Opens a booked session's video room inside the app itself (see
// VideoCallProvider) — no browser, no address bar. When the professional
// holds sessions on Zoom, Google Meet, Teams or Whereby instead, their
// link opens in that app instead (or the browser, if it isn't installed):
// those still need a real browser/app, since they aren't ours to embed.
// Resolves with an error message to show, or null if it opened.
export async function joinSession(config: KounseliaConfig, bookingId: number, t: Translate = makeT(deviceLanguage())): Promise<string | null> {
  const res = await fetchBookingRoom(config, bookingId);
  if (!res.ok) return res.message;
  if (res.data.external) {
    try {
      await Linking.openURL(res.data.url);
      return null;
    } catch {
      return t('m.join.cant_open', { provider: res.data.provider });
    }
  }
  await openVideoCall(res.data.url);
  return null;
}

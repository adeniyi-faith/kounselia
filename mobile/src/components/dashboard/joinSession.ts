import { fetchBookingRoom, type KounseliaConfig } from '@kounselia/core';
import * as Linking from 'expo-linking';
import { openVideoCall } from '@/browser/VideoCallProvider';

// Opens a booked session's video room inside the app itself (see
// VideoCallProvider) — no browser, no address bar. When the professional
// holds sessions on Zoom, Google Meet, Teams or Whereby instead, their
// link opens in that app instead (or the browser, if it isn't installed):
// those still need a real browser/app, since they aren't ours to embed.
// Resolves with an error message to show, or null if it opened.
export async function joinSession(config: KounseliaConfig, bookingId: number): Promise<string | null> {
  const res = await fetchBookingRoom(config, bookingId);
  if (!res.ok) return res.message;
  if (res.data.external) {
    try {
      await Linking.openURL(res.data.url);
      return null;
    } catch {
      return `We couldn't open ${res.data.provider}. Please try again, or message your professional.`;
    }
  }
  await openVideoCall(res.data.url);
  return null;
}

import { fetchBookingRoom, type KounseliaConfig } from '@kounselia/core';
import * as Linking from 'expo-linking';
import * as WebBrowser from 'expo-web-browser';

// The video room's browser bar: the brand's deep navy in light and dark.
const NAVY = '#162B4A';

// Opens a booked session's video room in the phone's secure browser. When
// the professional holds sessions on Zoom, Google Meet, Teams or Whereby,
// their link opens in that app instead (or the browser, if it isn't
// installed). Resolves with an error message to show, or null if it opened.
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
  await WebBrowser.openBrowserAsync(res.data.url, {
    toolbarColor: NAVY,
    controlsColor: '#fff',
    dismissButtonStyle: 'done',
    presentationStyle: WebBrowser.WebBrowserPresentationStyle.FULL_SCREEN,
  });
  return null;
}

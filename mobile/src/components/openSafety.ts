import * as WebBrowser from 'expo-web-browser';
import { SAFETY_URL } from '@/config';
import { colors } from '@/theme';

// The website's Safety resources page: emergency numbers and crisis lines.
export function openSafetyResources() {
  WebBrowser.openBrowserAsync(SAFETY_URL, { toolbarColor: colors.surface, controlsColor: colors.accent, dismissButtonStyle: 'done' }).catch(() => undefined);
}

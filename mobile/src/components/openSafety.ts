import { openInAppBrowser } from '@/browser/BrowserProvider';
import { SAFETY_URL } from '@/config';

// The website's Safety resources page: emergency numbers and crisis lines.
export function openSafetyResources() {
  openInAppBrowser(SAFETY_URL, { title: 'Safety resources' });
}

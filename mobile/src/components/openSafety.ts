import { deviceLanguage, makeT, normalizeLanguage } from '@kounselia/core';
import { openInAppBrowser } from '@/browser/BrowserProvider';
import { SAFETY_URL } from '@/config';
import { readSecure } from '@/secureStorage';

// The website's Safety resources page: emergency numbers and crisis lines.
export async function openSafetyResources() {
  // Not a component, so it reads the member's chosen language (see language.tsx) itself.
  const saved = await readSecure('kounselia_app_language').catch(() => null);
  const t = makeT(saved ? normalizeLanguage(saved) : deviceLanguage());
  openInAppBrowser(SAFETY_URL, { title: t('m.b.safety.title') });
}

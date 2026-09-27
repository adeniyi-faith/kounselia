// Crash and error reports, through Sentry (sentry.io): when the app
// crashes or a screen fails for a member, the details (which screen, the
// error, the phone model and app version) land on Sentry's dashboard, so
// problems are found there instead of from members' messages.
//
// Private by design, because of what people share here: no names or
// emails (only the member's account number), no screenshots, no record of
// what was typed or tapped, and no request contents — conversations,
// journal entries and moods never leave in a report.
//
// Switched on by the EXPO_PUBLIC_SENTRY_DSN build setting (the address
// Sentry gives a project; see README.md). Without it, nothing is sent.
// Never sends from a development build.
import * as Sentry from '@sentry/react-native';

const DSN = process.env.EXPO_PUBLIC_SENTRY_DSN;
export const monitoringOn = !!DSN && !__DEV__;

// Breadcrumbs are the trail of what happened before an error. Keep only
// screen changes and whether requests worked; drop the rest, which can
// carry what the member typed or the text of what they tapped.
const KEEP_BREADCRUMBS = new Set(['navigation', 'fetch', 'xhr', 'app.lifecycle', 'device.orientation']);

if (monitoringOn) {
  Sentry.init({
    dsn: DSN,
    sendDefaultPii: false,
    attachScreenshot: false,
    attachViewHierarchy: false,
    // Errors only; no performance tracing.
    tracesSampleRate: 0,
    beforeBreadcrumb(crumb) {
      if (!crumb.category || !KEEP_BREADCRUMBS.has(crumb.category)) return null;
      // Keep where a request went and how it ended, never what it said.
      if (crumb.data) {
        const { url, method, status_code } = crumb.data as Record<string, unknown>;
        crumb.data = { url, method, status_code };
      }
      return crumb;
    },
    beforeSend(event) {
      delete event.request;
      if (event.user) event.user = { id: event.user.id };
      return event;
    },
  });
}

/** Tags reports with the signed-in member's account number (or clears it). */
export function identify(userId: number | null) {
  if (!monitoringOn) return;
  Sentry.setUser(userId ? { id: String(userId) } : null);
}

/** Sends an error that was caught (and handled) but shouldn't have happened. */
export function report(error: unknown) {
  if (monitoringOn) Sentry.captureException(error);
}

export const wrapRoot = Sentry.wrap;

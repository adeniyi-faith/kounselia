// The one place that actually talks to WordPress. Every call goes to the
// same admin-ajax.php endpoint as a form post: `action` picks the PHP
// handler, the rest are its fields.
//
// How a request proves who's asking depends on where it comes from:
//   - website: the browser sends the sign-in cookie itself, and the page
//     hands us a nonce (a one-time security code) to include;
//   - mobile app: no cookie and no nonce. It marks the request as coming
//     from the app and sends the sign-in token it got from appLogin().
//     See portal/wp-content/mu-plugins/kounselia/includes/app-auth.php.
import type { KounseliaConfig } from './types';

/**
 * The server answered, but not with the JSON reply our PHP sends — for
 * example the hosting company's firewall turned the request away with an
 * error page before WordPress ever saw it. Kept apart from a dropped
 * connection so the member isn't told to check an internet connection
 * that's working fine.
 */
export class ServerReplyError extends Error {
  readonly status: number;
  constructor(status: number) {
    super(`The server replied with HTTP ${status} instead of JSON`);
    this.name = 'ServerReplyError';
    this.status = status;
  }
}

// Just above the server's 90s max_execution_time, so a slow-but-valid AI reply is never cut off.
export const DEFAULT_TIMEOUT_MS = 95000;

// Built by hand rather than with URLSearchParams, which React Native only
// partly supports.
function formEncode(fields: Record<string, string>): string {
  return Object.entries(fields)
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(value)}`)
    .join('&');
}

/**
 * Posts one admin-ajax action and returns the decoded JSON reply
 * (`{ success, data }`). Rejects on a network failure or timeout, so
 * callers decide what the member sees in that case.
 */
export async function postAction(
  config: KounseliaConfig,
  action: string,
  params: Record<string, string | number | undefined> = {},
  timeoutMs = DEFAULT_TIMEOUT_MS,
): Promise<any> {
  const fields: Record<string, string> = { action };
  if (config.nonce) fields.nonce = config.nonce;
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined) fields[key] = String(value);
  }

  const headers: Record<string, string> = { 'Content-Type': 'application/x-www-form-urlencoded' };
  if (config.client === 'app') {
    headers['X-Kounselia-Client'] = 'app';
    if (config.authToken) headers['X-Kounselia-Token'] = config.authToken;
    // The web host's firewall refuses (HTTP 406) any POST to WordPress that
    // doesn't say which site it came from. Browsers always say so; apps
    // don't unless told to. This isn't a security check — the sign-in
    // token is — it only keeps the firewall from mistaking the app for a bot.
    const site = /^https?:\/\/[^/]+/.exec(config.ajaxUrl)?.[0];
    if (site) {
      headers.Origin = site;
      headers.Referer = `${site}/`;
    }
  }

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const res = await fetch(config.ajaxUrl, {
      method: 'POST',
      headers,
      body: formEncode(fields),
      signal: controller.signal,
    });
    const text = await res.text();
    let json: any;
    try {
      json = JSON.parse(text);
    } catch {
      throw new ServerReplyError(res.status);
    }
    // A signed-in app request the server treats as signed out: either our
    // own "signed_out" answer, or WordPress's bare 0 for an action that
    // only exists for signed-in members.
    if (config.client === 'app' && config.authToken && (json === 0 || json === '0' || json?.data?.signed_out)) {
      config.onSignedOut?.();
    }
    return json;
  } finally {
    clearTimeout(timer);
  }
}

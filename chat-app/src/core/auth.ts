// Sign in, sign up, password reset, sign out. Pure network calls, no
// DOM — reusable in the React Native app as-is.
import type { KounseliaConfig } from './types';

interface AuthResult {
  success: boolean;
  name?: string;
  nonce?: string;
  message?: string;
}

async function postAuth(
  config: KounseliaConfig,
  action: string,
  params: Record<string, string>,
): Promise<AuthResult> {
  const body = new URLSearchParams({ action, nonce: config.nonce, ...params });
  const res = await fetch(config.ajaxUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body,
  });
  const json = await res.json();
  if (json.success) {
    return { success: true, name: json.data?.name, nonce: json.data?.nonce };
  }
  return { success: false, message: json.data?.message };
}

// `website` is an invisible honeypot field: real users never fill it in,
// bots that auto-fill every field do, so the server can quietly drop those.
export function login(config: KounseliaConfig, email: string, password: string, website: string) {
  return postAuth(config, 'kounselia_login', { email, password, website });
}

export function register(config: KounseliaConfig, name: string, email: string, password: string, website: string) {
  return postAuth(config, 'kounselia_register', { name, email, password, website });
}

export function forgotPassword(config: KounseliaConfig, email: string, website: string) {
  return postAuth(config, 'kounselia_forgot_password', { email, website });
}

export function logout(config: KounseliaConfig) {
  return fetch(config.ajaxUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: 'kounselia_logout', nonce: config.nonce }),
  }).catch(() => undefined);
}

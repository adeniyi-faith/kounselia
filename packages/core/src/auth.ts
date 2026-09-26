// Sign in, sign up, password reset, sign out for the website, where the
// browser keeps the sign-in cookie. The mobile app uses appAuth.ts instead.
import { postAction } from './http';
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
  const json = await postAction(config, action, params);
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
  return postAction(config, 'kounselia_logout').catch(() => undefined);
}

// Sign in for the mobile app. Instead of a cookie, the server hands back
// a token; the app saves it in the phone's secure storage and puts it in
// its KounseliaConfig (`authToken`) so every later call carries it.
import { postAction } from './http';
import type { AppUser, KounseliaConfig } from './types';

export type AppAuthResult =
  | { success: true; token: string; user: AppUser }
  | { success: false; message: string };

const CONNECTION_MESSAGE = "Couldn't connect. Please check your internet connection and try again.";

async function postAppAuth(
  config: KounseliaConfig,
  action: string,
  params: Record<string, string>,
): Promise<AppAuthResult> {
  try {
    const json = await postAction(config, action, params);
    if (json?.success && json.data?.token) {
      return { success: true, token: json.data.token, user: json.data.user };
    }
    return { success: false, message: json?.data?.message || 'Something went wrong. Please try again.' };
  } catch {
    return { success: false, message: CONNECTION_MESSAGE };
  }
}

// `deviceName` (e.g. "Ada's iPhone") is stored with the sign-in so a
// member could later see which phones are signed in.
export function appLogin(config: KounseliaConfig, email: string, password: string, deviceName = '') {
  return postAppAuth(config, 'kounselia_app_login', { email, password, device_name: deviceName });
}

export function appRegister(
  config: KounseliaConfig,
  name: string,
  email: string,
  password: string,
  deviceName = '',
) {
  return postAppAuth(config, 'kounselia_app_register', { name, email, password, device_name: deviceName });
}

export type AppMeResult =
  | { status: 'signed-in'; user: AppUser }
  | { status: 'signed-out' } // the saved token no longer works; ask them to sign in again
  | { status: 'offline' }; // couldn't reach the server; keep the saved sign-in and try later

// Checks, when the app opens, whether its saved token still works.
export async function fetchAppUser(config: KounseliaConfig): Promise<AppMeResult> {
  try {
    const json = await postAction(config, 'kounselia_app_me');
    if (json?.success) return { status: 'signed-in', user: json.data.user };
    return json?.data?.signed_out ? { status: 'signed-out' } : { status: 'offline' };
  } catch {
    return { status: 'offline' };
  }
}

// Signs out this phone only. Resolves either way: the app forgets the
// token regardless, so a dropped connection can't keep someone signed in.
export function appLogout(config: KounseliaConfig): Promise<void> {
  return postAction(config, 'kounselia_app_logout').then(
    () => undefined,
    () => undefined,
  );
}

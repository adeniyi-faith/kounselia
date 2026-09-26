// Guests (not signed in) are tracked by a random id saved on the device,
// so their free-message limit survives a page reload. Signed-in users
// don't need this at all — the server already knows who they are.
const STORAGE_KEY = 'kounselia_guest_token';

export function getGuestToken(): string {
  let token = localStorage.getItem(STORAGE_KEY);
  if (!token) {
    token =
      window.crypto && 'randomUUID' in window.crypto
        ? window.crypto.randomUUID()
        : `g_${Date.now()}_${Math.random().toString(36).slice(2)}`;
    localStorage.setItem(STORAGE_KEY, token);
  }
  return token;
}

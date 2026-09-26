// The phone's secure storage (iOS Keychain / Android Keystore), wrapped
// so a failure — a locked keychain, or running where it doesn't exist —
// never stops the app. At worst the member has to sign in again next
// launch.
import * as SecureStore from 'expo-secure-store';

export async function readSecure(key: string): Promise<string | null> {
  try {
    return await SecureStore.getItemAsync(key);
  } catch {
    return null;
  }
}

export async function writeSecure(key: string, value: string): Promise<void> {
  try {
    await SecureStore.setItemAsync(key, value);
  } catch {
    // Keep going: the sign-in still works until the app is closed.
  }
}

export async function deleteSecure(key: string): Promise<void> {
  try {
    await SecureStore.deleteItemAsync(key);
  } catch {
    // Nothing more we can do.
  }
}

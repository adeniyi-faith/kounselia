import { useEffect, useState } from 'react';
import { Keyboard, Platform } from 'react-native';

// Whether the on-screen keyboard is showing. Screens with a message box at
// the bottom use it: the home-bar gap under the box is only needed while
// the keyboard is closed; with it open, the box sits right on the keyboard.
export function useKeyboardOpen() {
  const [open, setOpen] = useState(false);
  useEffect(() => {
    const show = Keyboard.addListener(Platform.OS === 'ios' ? 'keyboardWillShow' : 'keyboardDidShow', () => setOpen(true));
    const hide = Keyboard.addListener(Platform.OS === 'ios' ? 'keyboardWillHide' : 'keyboardDidHide', () => setOpen(false));
    return () => {
      show.remove();
      hide.remove();
    };
  }, []);
  return open;
}

// A short note that fades in over the screen ("Copied",
// "Thanks for the feedback!") and fades out by itself.
import { useEffect, useRef, useState } from 'react';
import { Animated, StyleSheet, Text } from 'react-native';
import { colors, fonts } from '@/theme';

export function useToast() {
  // The text stays after the note hides, so it can fade out with it.
  const [note, setNote] = useState<{ text: string; visible: boolean } | null>(null);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const show = (text: string) => {
    if (timer.current) clearTimeout(timer.current);
    setNote({ text, visible: true });
    timer.current = setTimeout(() => setNote((n) => (n ? { ...n, visible: false } : n)), 2200);
  };
  useEffect(() => () => {
    if (timer.current) clearTimeout(timer.current);
  }, []);
  return { message: note?.visible ? note.text : null, note, show };
}

export function Toast({ note }: { note: { text: string; visible: boolean } | null }) {
  const [opacity] = useState(() => new Animated.Value(0));
  const visible = !!note?.visible;

  useEffect(() => {
    Animated.timing(opacity, { toValue: visible ? 1 : 0, duration: 180, useNativeDriver: true }).start();
  }, [visible, opacity]);

  if (!note) return null;
  return (
    <Animated.View pointerEvents="none" style={[styles.toast, { opacity }]} accessibilityLiveRegion="polite">
      <Text style={styles.text}>{note.text}</Text>
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  toast: {
    position: 'absolute',
    alignSelf: 'center',
    top: 110, // under the header, clear of the newest messages
    backgroundColor: colors.text,
    paddingHorizontal: 18,
    paddingVertical: 10,
    borderRadius: 50,
  },
  text: { fontFamily: fonts.medium, fontSize: 14, color: '#fff' },
});

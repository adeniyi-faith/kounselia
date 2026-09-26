// A short note that fades in over the conversation ("Copied",
// "Thanks for the feedback!") and fades out by itself.
import { useEffect, useRef, useState } from 'react';
import { Animated, StyleSheet, Text } from 'react-native';
import { colors, fonts } from '@/theme';

export function useToast() {
  const [message, setMessage] = useState<string | null>(null);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const show = (text: string) => {
    if (timer.current) clearTimeout(timer.current);
    setMessage(text);
    timer.current = setTimeout(() => setMessage(null), 2200);
  };
  useEffect(() => () => {
    if (timer.current) clearTimeout(timer.current);
  }, []);
  return { message, show };
}

export function Toast({ message }: { message: string | null }) {
  const opacity = useRef(new Animated.Value(0)).current;
  const [shown, setShown] = useState(message);

  useEffect(() => {
    if (message) setShown(message);
    Animated.timing(opacity, { toValue: message ? 1 : 0, duration: 180, useNativeDriver: true }).start();
  }, [message, opacity]);

  if (!shown) return null;
  return (
    <Animated.View pointerEvents="none" style={[styles.toast, { opacity }]} accessibilityLiveRegion="polite">
      <Text style={styles.text}>{shown}</Text>
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  toast: {
    position: 'absolute',
    alignSelf: 'center',
    bottom: 96,
    backgroundColor: colors.text,
    paddingHorizontal: 18,
    paddingVertical: 10,
    borderRadius: 50,
  },
  text: { fontFamily: fonts.medium, fontSize: 14, color: '#fff' },
});

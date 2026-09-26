// The dashboard's welcome banner (.welcome): navy gradient, a softly
// breathing gold glow, "Your space", the greeting with the first name in
// italic gold, and the two buttons.
import { LinearGradient } from 'expo-linear-gradient';
import { useEffect, useRef } from 'react';
import { AccessibilityInfo, Animated, Easing, Pressable, StyleSheet, Text, View } from 'react-native';
import { colors, fonts } from '@/theme';
import { TablerIcon } from '../TablerIcon';

function greeting(): string {
  const hour = new Date().getHours();
  if (hour < 12) return 'Good morning,';
  if (hour < 17) return 'Good afternoon,';
  return 'Good evening,';
}

const GLOW = [300, 250, 200, 150, 100, 60];

interface Props {
  firstName?: string;
  onTalk: () => void;
  onSessions: () => void;
}

export function WelcomeBanner({ firstName, onTalk, onSessions }: Props) {
  const breathe = useRef(new Animated.Value(0)).current;
  useEffect(() => {
    let loop: Animated.CompositeAnimation | undefined;
    // Respect "reduce motion", as the website does.
    AccessibilityInfo.isReduceMotionEnabled().then((reduce) => {
      if (reduce) return;
      loop = Animated.loop(
        Animated.sequence([
          Animated.timing(breathe, { toValue: 1, duration: 3500, easing: Easing.inOut(Easing.ease), useNativeDriver: true }),
          Animated.timing(breathe, { toValue: 0, duration: 3500, easing: Easing.inOut(Easing.ease), useNativeDriver: true }),
        ]),
      );
      loop.start();
    });
    return () => loop?.stop();
  }, [breathe]);
  const scale = breathe.interpolate({ inputRange: [0, 1], outputRange: [1, 1.18] });
  const opacity = breathe.interpolate({ inputRange: [0, 1], outputRange: [0.7, 1] });

  return (
    <LinearGradient colors={[colors.accent, colors.navy]} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.banner}>
      <Animated.View pointerEvents="none" style={[styles.orb, { opacity, transform: [{ scale }] }]}>
        {/* A soft gold glow (the website's radial gradient), built from
            stacked see-through circles that fade towards the edge. */}
        {GLOW.map((size) => (
          <View key={size} style={[styles.glow, { width: size, height: size, borderRadius: size / 2 }]} />
        ))}
      </Animated.View>
      <Text style={styles.eyebrow}>Your space</Text>
      <Text style={styles.h1} accessibilityRole="header">
        {greeting()} {firstName ? <Text style={styles.name}>{firstName}</Text> : null}.
      </Text>
      <Text style={styles.body}>
        This is where everything you bring to Kounselia lives, your conversations, your people, your pace. Nothing here is urgent.
      </Text>
      <View style={styles.actions}>
        <Pressable onPress={onTalk} accessibilityRole="button" style={({ pressed }) => [styles.btn, styles.btnPrimary, pressed && styles.pressed]}>
          <TablerIcon name="message-2-plus" size={16} color={colors.navy} />
          <Text style={[styles.btnText, { color: colors.navy }]}>Talk to someone now</Text>
        </Pressable>
        <Pressable onPress={onSessions} accessibilityRole="button" style={({ pressed }) => [styles.btn, styles.btnGhost, pressed && styles.pressed]}>
          <TablerIcon name="history" size={16} color="#fff" />
          <Text style={[styles.btnText, { color: '#fff' }]}>Your sessions</Text>
        </Pressable>
      </View>
    </LinearGradient>
  );
}

const styles = StyleSheet.create({
  banner: { borderRadius: 28, padding: 26, overflow: 'hidden' },
  orb: { position: 'absolute', width: 300, height: 300, top: -130, right: -90, alignItems: 'center', justifyContent: 'center' },
  glow: { position: 'absolute', backgroundColor: 'rgba(176,125,58,0.07)' },
  eyebrow: { fontFamily: fonts.semibold, fontSize: 11, letterSpacing: 2.5, textTransform: 'uppercase', color: 'rgba(255,255,255,0.6)', marginBottom: 10 },
  h1: { fontFamily: fonts.serif, fontSize: 32, lineHeight: 38, color: '#fff' },
  name: { fontFamily: fonts.serif, fontStyle: 'italic', color: '#E8C896' },
  body: { fontFamily: fonts.light, fontSize: 15, lineHeight: 23, color: 'rgba(255,255,255,0.78)', marginTop: 10 },
  actions: { flexDirection: 'row', flexWrap: 'wrap', gap: 10, marginTop: 20 },
  btn: { flexDirection: 'row', alignItems: 'center', gap: 7, paddingVertical: 11, paddingHorizontal: 16, borderRadius: 50 },
  btnPrimary: { backgroundColor: '#fff' },
  btnGhost: { borderWidth: 1, borderColor: 'rgba(255,255,255,0.35)' },
  btnText: { fontFamily: fonts.medium, fontSize: 14 },
  pressed: { transform: [{ scale: 0.97 }] },
});

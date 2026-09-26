// The website's call screen (.call-overlay): navy gradient, the
// counselor's icon in a large circle with a pulsing ring while they speak,
// a countdown, live captions, mute and hang-up buttons.
import type { CounselorSummary } from '@kounselia/core';
import { LinearGradient } from 'expo-linear-gradient';
import { useEffect, useRef } from 'react';
import { Animated, Easing, Modal, Pressable, StyleSheet, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import type { CallStatus } from '@/audio/useVoiceCall';
import { colors, fonts } from '@/theme';
import { TablerIcon } from '../TablerIcon';

interface Props {
  visible: boolean;
  counselor: CounselorSummary;
  status: CallStatus;
  statusText: string;
  timerText: string;
  caption: string;
  muted: boolean;
  freeCallMinutes: number | null;
  onEnd: () => void;
  onToggleMute: () => void;
}

function Ring({ active }: { active: boolean }) {
  const pulse = useRef(new Animated.Value(0)).current;
  useEffect(() => {
    if (!active) {
      pulse.stopAnimation();
      pulse.setValue(0);
      return;
    }
    const loop = Animated.loop(Animated.timing(pulse, { toValue: 1, duration: 1600, easing: Easing.out(Easing.ease), useNativeDriver: true }));
    loop.start();
    return () => loop.stop();
  }, [active, pulse]);
  const scale = pulse.interpolate({ inputRange: [0, 0.7, 1], outputRange: [0.95, 1.25, 1.25] });
  const opacity = pulse.interpolate({ inputRange: [0, 0.7, 1], outputRange: [active ? 0.7 : 0, 0, 0] });
  return <Animated.View style={[styles.ring, { opacity, transform: [{ scale }] }]} />;
}

export function CallOverlay(props: Props) {
  const { visible, counselor, status, statusText, timerText, caption, muted, freeCallMinutes, onEnd, onToggleMute } = props;
  const insets = useSafeAreaInsets();

  return (
    <Modal visible={visible} animationType="slide" presentationStyle="fullScreen" onRequestClose={onEnd} statusBarTranslucent>
      <LinearGradient colors={[colors.navy, colors.accent]} style={[styles.screen, { paddingTop: insets.top + 40, paddingBottom: insets.bottom + 40 }]}>
        <Pressable onPress={onEnd} accessibilityRole="button" accessibilityLabel="End call" style={[styles.close, { top: insets.top + 16 }]}>
          <TablerIcon name="x" size={20} color="#fff" />
        </Pressable>
        <Text style={styles.status} accessibilityLiveRegion="polite">
          {statusText}
        </Text>
        <View style={styles.avatarWrap}>
          <Ring active={status === 'speaking'} />
          <View style={styles.avatar}>
            <TablerIcon name={counselor.icon} size={52} color="#fff" />
          </View>
        </View>
        <Text style={styles.name}>{counselor.name}</Text>
        <Text style={styles.timer} accessibilityLabel={`Time left ${timerText}`}>
          {timerText}
        </Text>
        <Text style={styles.caption} numberOfLines={4}>
          {caption}
        </Text>
        <View style={styles.controls}>
          <Pressable
            onPress={onToggleMute}
            accessibilityRole="button"
            accessibilityLabel={muted ? 'Unmute' : 'Mute'}
            accessibilityState={{ selected: muted }}
            style={[styles.ctrl, muted && styles.ctrlMuted]}
          >
            <TablerIcon name={muted ? 'microphone-off' : 'microphone'} size={22} color={muted ? colors.navy : '#fff'} />
          </Pressable>
          <Pressable onPress={onEnd} accessibilityRole="button" accessibilityLabel="Hang up" style={[styles.ctrl, styles.end]}>
            <TablerIcon name="phone-x" size={26} color="#fff" />
          </Pressable>
        </View>
        {freeCallMinutes !== null && (
          <Text style={styles.note}>Free members get {freeCallMinutes} minutes per call. Upgrade to Pro for longer sessions.</Text>
        )}
      </LinearGradient>
    </Modal>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 24 },
  close: {
    position: 'absolute',
    right: 16,
    width: 38,
    height: 38,
    borderRadius: 19,
    backgroundColor: 'rgba(255,255,255,0.12)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  status: { fontFamily: fonts.semibold, fontSize: 12, letterSpacing: 2, textTransform: 'uppercase', color: 'rgba(255,255,255,0.6)', marginBottom: 28 },
  avatarWrap: { width: 140, height: 140, marginBottom: 22, alignItems: 'center', justifyContent: 'center' },
  ring: { position: 'absolute', width: 168, height: 168, borderRadius: 84, borderWidth: 2, borderColor: 'rgba(255,255,255,0.25)' },
  avatar: { width: 140, height: 140, borderRadius: 70, backgroundColor: 'rgba(255,255,255,0.12)', alignItems: 'center', justifyContent: 'center' },
  name: { fontFamily: fonts.serifMedium, fontSize: 28, color: '#fff', marginBottom: 6 },
  timer: { fontFamily: fonts.regular, fontSize: 14, color: 'rgba(255,255,255,0.7)', marginBottom: 22, fontVariant: ['tabular-nums'] },
  caption: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 22, color: 'rgba(255,255,255,0.85)', minHeight: 44, maxWidth: 340, textAlign: 'center', marginBottom: 30 },
  controls: { flexDirection: 'row', alignItems: 'center', gap: 18 },
  ctrl: { width: 56, height: 56, borderRadius: 28, backgroundColor: 'rgba(255,255,255,0.14)', alignItems: 'center', justifyContent: 'center' },
  ctrlMuted: { backgroundColor: '#fff' },
  end: { width: 64, height: 64, borderRadius: 32, backgroundColor: colors.rose },
  note: { fontFamily: fonts.regular, fontSize: 12, color: 'rgba(255,255,255,0.55)', maxWidth: 300, textAlign: 'center', marginTop: 22 },
});

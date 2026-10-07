// Three bouncing dots while the counselor replies. After 3.5 seconds it
// explains the wait ("Consulting team"), as the web chat does, since a
// reply may be checked with other counselors first.
import type { CounselorSummary } from '@kounselia/core';
import { useEffect, useState } from 'react';
import { Animated, Easing, Text, View } from 'react-native';
import { useT } from '@/language';
import { fonts, makeStyles, useColors } from '@/theme';
import { CounselorAvatar } from '../CounselorAvatar';
import { TablerIcon } from '../TablerIcon';

function Dot({ delay, color }: { delay: number; color: string }) {
  const styles = useStyles();
  const [lift] = useState(() => new Animated.Value(0));
  useEffect(() => {
    const loop = Animated.loop(
      Animated.sequence([
        Animated.delay(delay),
        Animated.timing(lift, { toValue: 1, duration: 300, easing: Easing.out(Easing.quad), useNativeDriver: true }),
        Animated.timing(lift, { toValue: 0, duration: 300, easing: Easing.in(Easing.quad), useNativeDriver: true }),
        Animated.delay(600 - delay),
      ]),
    );
    loop.start();
    return () => loop.stop();
  }, [delay, lift]);
  const translateY = lift.interpolate({ inputRange: [0, 1], outputRange: [0, -4] });
  const opacity = lift.interpolate({ inputRange: [0, 1], outputRange: [0.4, 1] });
  return <Animated.View style={[styles.dot, { backgroundColor: color, opacity, transform: [{ translateY }] }]} />;
}

export function TypingIndicator({ counselor }: { counselor: CounselorSummary }) {
  const styles = useStyles();
  const colors = useColors();
  const t = useT();
  const [consulting, setConsulting] = useState(false);
  useEffect(() => {
    const timer = setTimeout(() => setConsulting(true), 3500);
    return () => clearTimeout(timer);
  }, []);

  const dotColor = consulting ? colors.gold : colors.text3;
  return (
    <View style={styles.row} accessibilityLiveRegion="polite" accessibilityLabel={t('m.b.typing.typing', { name: counselor.name })}>
      <CounselorAvatar icon={counselor.icon} color={counselor.color} size={32} />
      <View style={styles.bubble}>
        {consulting && (
          <>
            <TablerIcon name="users" size={12} color={colors.gold} />
            <Text style={styles.consulting}>{t('m.b.typing.consulting')}</Text>
          </>
        )}
        <Dot delay={0} color={dotColor} />
        <Dot delay={150} color={dotColor} />
        <Dot delay={300} color={dotColor} />
      </View>
    </View>
  );
}

const useStyles = makeStyles((colors) => ({
  row: { flexDirection: 'row', alignItems: 'flex-end', gap: 10 },
  bubble: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 5,
    height: 38,
    paddingHorizontal: 16,
    borderRadius: 20,
    borderBottomLeftRadius: 4,
    backgroundColor: colors.chatBubble,
    borderWidth: 1,
    borderColor: colors.chatBubbleBorder,
  },
  dot: { width: 7, height: 7, borderRadius: 3.5 },
  consulting: { fontFamily: fonts.semibold, fontSize: 11, letterSpacing: 0.5, textTransform: 'uppercase', color: colors.gold, marginRight: 2 },
}));

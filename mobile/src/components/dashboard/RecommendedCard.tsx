import type { CounselorSummary } from '@kounselia/core';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { colors, fonts } from '@/theme';
import { CounselorAvatar } from '../CounselorAvatar';
import { Card } from './Card';

// "Recommended for you" (.rec-card).
export function RecommendedCard({ counselor, reason, onStart }: { counselor: CounselorSummary; reason: string; onStart: () => void }) {
  return (
    <Card style={styles.card}>
      <View style={styles.top}>
        <CounselorAvatar icon={counselor.icon} color={counselor.color} size={56} />
        <View style={styles.meta}>
          <Text style={styles.name}>{counselor.name}</Text>
          <Text style={styles.spec}>{counselor.spec}</Text>
        </View>
      </View>
      <Text style={styles.reason}>{reason}</Text>
      <Pressable onPress={onStart} accessibilityRole="button" accessibilityLabel={`Start talking with ${counselor.name}`} style={({ pressed }) => [styles.btn, pressed && { backgroundColor: colors.accent2 }]}>
        <Text style={styles.btnText}>Start talking</Text>
      </Pressable>
    </Card>
  );
}

const styles = StyleSheet.create({
  card: { gap: 12 },
  top: { flexDirection: 'row', alignItems: 'center', gap: 16 },
  meta: { flex: 1 },
  name: { fontFamily: fonts.serifMedium, fontSize: 22, color: colors.text },
  spec: { fontFamily: fonts.semibold, fontSize: 12, letterSpacing: 0.3, textTransform: 'uppercase', color: colors.gold, marginTop: 2 },
  reason: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 20, color: colors.text2 },
  btn: { alignSelf: 'flex-start', paddingVertical: 11, paddingHorizontal: 20, borderRadius: 50, backgroundColor: colors.accent },
  btnText: { fontFamily: fonts.medium, fontSize: 14, color: '#fff' },
});

import type { ReactNode } from 'react';
import { StyleSheet, Text, View, type ViewStyle } from 'react-native';
import { colors, fonts, radius, shadows } from '@/theme';

// The dashboard's white rounded cards (.mood-card, .journal-card, .rec-card).
export function Card({ children, style }: { children: ReactNode; style?: ViewStyle }) {
  return <View style={[styles.card, style]}>{children}</View>;
}

// A section title with the small grey note on the right (.section-head).
export function SectionHead({ title, note }: { title: string; note?: string }) {
  return (
    <View style={styles.head}>
      <Text style={styles.title} accessibilityRole="header">
        {title}
      </Text>
      {note ? <Text style={styles.note}>{note}</Text> : null}
    </View>
  );
}

// The dashed "nothing here yet" box (.empty-state).
export function EmptyState({ children }: { children: ReactNode }) {
  return <View style={styles.empty}>{children}</View>;
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.r,
    padding: 20,
    ...shadows.soft,
  },
  head: { flexDirection: 'row', alignItems: 'baseline', justifyContent: 'space-between', gap: 12, marginTop: 28, marginBottom: 14 },
  title: { fontFamily: fonts.serifMedium, fontSize: 23, color: colors.text, flexShrink: 1 },
  note: { fontFamily: fonts.regular, fontSize: 13, color: colors.text3 },
  empty: {
    backgroundColor: colors.surface2,
    borderWidth: 1,
    borderStyle: 'dashed',
    borderColor: colors.border,
    borderRadius: radius.r,
    paddingVertical: 32,
    paddingHorizontal: 24,
    alignItems: 'center',
    gap: 12,
  },
});

import type { HomeData } from '@kounselia/core';
import { Text, View } from 'react-native';
import { counselorColors, fonts, makeStyles, radius, shadows, useColors } from '@/theme';
import { TablerIcon } from '../TablerIcon';

// The three numbers under the mood card (.stats-row).
export function StatsRow({ stats }: { stats: HomeData['stats'] }) {
  const styles = useStyles();
  const colors = useColors();
  const items = [
    { icon: 'message-circle', color: 'blue', num: stats.conversations, label: 'Conversations' },
    { icon: 'calendar-week', color: 'sage', num: stats.messages_this_week, label: 'Messages this week' },
    { icon: 'users', color: 'gold', num: stats.counselors_met, label: 'Counselors met' },
  ];
  return (
    <View style={styles.row}>
      {items.map((s) => {
        const { fg, bg } = counselorColors(s.color, colors);
        return (
          <View key={s.label} style={styles.card} accessible accessibilityLabel={`${s.num} ${s.label}`}>
            <View style={[styles.icon, { backgroundColor: bg }]}>
              <TablerIcon name={s.icon} size={17} color={fg} />
            </View>
            <Text style={styles.num}>{s.num}</Text>
            <Text style={styles.label} numberOfLines={2} adjustsFontSizeToFit minimumFontScale={0.85}>
              {s.label}
            </Text>
          </View>
        );
      })}
    </View>
  );
}

const useStyles = makeStyles((colors) => ({
  row: { flexDirection: 'row', gap: 10, marginTop: 16 },
  card: { flex: 1, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: radius.sm, padding: 12, ...shadows.soft },
  icon: { width: 34, height: 34, borderRadius: 10, alignItems: 'center', justifyContent: 'center', marginBottom: 12 },
  // Outfit rather than the heading font, whose old-style "1" reads as "I".
  num: { fontFamily: fonts.semibold, fontSize: 24, lineHeight: 30, color: colors.text, fontVariant: ['tabular-nums'] },
  label: { fontFamily: fonts.regular, fontSize: 12, lineHeight: 16, color: colors.text2, marginTop: 4 },
}));

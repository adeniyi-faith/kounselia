// "How are you feeling today?" (.mood-card): five moods to pick from and
// a row of dots for the last seven days, coloured by that day's mood.
import type { HomeData } from '@kounselia/core';
import * as Haptics from 'expo-haptics';
import { Pressable, ScrollView, Text, View } from 'react-native';
import { useLanguage } from '@/language';
import { counselorColors, fonts, makeStyles, useColors } from '@/theme';
import { TablerIcon } from '../TablerIcon';
import { Card } from './Card';

interface Props {
  mood: HomeData['mood'];
  saving: string | null; // the mood being saved right now, if any
  onPick: (key: string) => void;
}

function dayLetter(date: string, language: string): string {
  // Dates are the site's calendar days ("2026-09-26"); read them as such.
  const [y, m, d] = date.split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString(language, { weekday: 'narrow' });
}

export function MoodCard({ mood, saving, onPick }: Props) {
  const styles = useStyles();
  const colors = useColors();
  const { language, t } = useLanguage();
  const colorOf = (key: string | null) => mood.options.find((o) => o.key === key)?.color;
  const shown = saving ?? mood.today;

  return (
    <Card>
      <View style={styles.head}>
        <Text style={styles.title}>{t('m.mood.title')}</Text>
        {mood.today && !saving ? (
          <View style={styles.saved}>
            <TablerIcon name="check" size={11} color={colors.sage} />
            <Text style={styles.savedText}>{t('m.common.saved')}</Text>
          </View>
        ) : null}
      </View>
      {/* Tiles wide enough for a full label ("Overwhelmed"); on narrow phones
          the row scrolls sideways a little rather than shrinking the words. */}
      <ScrollView
        horizontal
        showsHorizontalScrollIndicator={false}
        style={styles.optionsScroll}
        contentContainerStyle={styles.options}
        accessibilityRole="radiogroup"
      >
        {mood.options.map((o) => {
          const selected = shown === o.key;
          const { fg, bg } = counselorColors(o.color, colors);
          return (
            <Pressable
              key={o.key}
              onPress={() => {
                Haptics.selectionAsync().catch(() => undefined);
                onPick(o.key);
              }}
              accessibilityRole="radio"
              accessibilityState={{ selected }}
              accessibilityLabel={o.label}
              style={({ pressed }) => [
                styles.option,
                selected && { borderColor: fg, backgroundColor: bg },
                pressed && { transform: [{ scale: 0.96 }] },
              ]}
            >
              <TablerIcon name={o.icon} size={24} color={selected ? fg : colors.text2} />
              <Text style={[styles.optionLabel, selected && { color: fg, fontFamily: fonts.semibold }]} numberOfLines={1}>
                {o.label}
              </Text>
            </Pressable>
          );
        })}
      </ScrollView>
      <View style={styles.rhythm} accessible accessibilityLabel={t('m.mood.week_a11y')}>
        {mood.week.map((day) => {
          const color = colorOf(day.mood);
          return (
            <View key={day.date} style={styles.day}>
              <View style={[styles.dot, color ? { backgroundColor: counselorColors(color, colors).fg, borderColor: 'transparent' } : null]} />
              <Text style={styles.dayLetter}>{dayLetter(day.date, language)}</Text>
            </View>
          );
        })}
      </View>
    </Card>
  );
}

const useStyles = makeStyles((colors) => ({
  head: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12, marginBottom: 16 },
  title: { fontFamily: fonts.serifMedium, fontSize: 20, color: colors.text, flexShrink: 1 },
  saved: { flexDirection: 'row', alignItems: 'center', gap: 4, backgroundColor: colors.sageLight, paddingVertical: 4, paddingHorizontal: 10, borderRadius: 50 },
  savedText: { fontFamily: fonts.semibold, fontSize: 11, letterSpacing: 0.3, textTransform: 'uppercase', color: colors.sage },
  // Bleeds to the card's edges so tiles scroll right up to them.
  optionsScroll: { marginHorizontal: -20, marginBottom: 18 },
  options: { flexDirection: 'row', gap: 8, paddingHorizontal: 20, flexGrow: 1 },
  option: {
    flexGrow: 1,
    minWidth: 76,
    alignItems: 'center',
    gap: 6,
    paddingVertical: 12,
    paddingHorizontal: 6,
    borderRadius: 16,
    borderWidth: 1.5,
    borderColor: colors.border,
    backgroundColor: colors.bg,
  },
  optionLabel: { fontFamily: fonts.medium, fontSize: 12, color: colors.text2 },
  rhythm: { flexDirection: 'row', justifyContent: 'space-between', paddingTop: 16, borderTopWidth: 1, borderTopColor: colors.border },
  day: { alignItems: 'center', gap: 7 },
  dot: { width: 14, height: 14, borderRadius: 7, backgroundColor: colors.surface3, borderWidth: 1.5, borderColor: colors.border },
  dayLetter: { fontFamily: fonts.medium, fontSize: 11, color: colors.text3 },
}));

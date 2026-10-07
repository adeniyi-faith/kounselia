// What clients said about a professional, on the "Book a session" screen:
// a one-line star summary under their name (shown at once, from what the
// professional list already knows), and below their bio a row of review
// cards to swipe through, loaded separately so they never hold up the
// booking times. Reviews are anonymous: stars, words and when, no names.
import { fetchProfessionalReviews, type KounseliaConfig, type ProfessionalReviews } from '@kounselia/core';
import { useEffect, useState } from 'react';
import { Pressable, ScrollView, Text, useWindowDimensions, View } from 'react-native';
import { useLanguage } from '@/language';
import { fonts, makeStyles, radius, useColors } from '@/theme';
import { showDialog } from '../Dialog';
import { TablerIcon } from '../TablerIcon';

function Stars({ rating, size = 14 }: { rating: number; size?: number }) {
  const colors = useColors();
  const { t } = useLanguage();
  return (
    <View style={{ flexDirection: 'row', gap: 2 }} accessibilityLabel={t('m.rate.stars_of_5', { n: rating })}>
      {[1, 2, 3, 4, 5].map((n) => (
        <TablerIcon key={n} name={n <= Math.round(rating) ? 'star-filled' : 'star'} size={size} color={n <= Math.round(rating) ? colors.gold : colors.text3} />
      ))}
    </View>
  );
}

/** "★ 4.8 · 12 reviews", or "New to Kounselia" before anyone has rated them. */
export function RatingLine({ average, count }: { average: number; count: number }) {
  const styles = useStyles();
  const colors = useColors();
  const { t } = useLanguage();
  if (count === 0) {
    return (
      <View style={styles.ratingLine}>
        <TablerIcon name="sparkles" size={14} color={colors.sage} />
        <Text style={[styles.ratingText, { color: colors.sage }]}>{t('m.reviews.new')}</Text>
      </View>
    );
  }
  return (
    <View style={styles.ratingLine} accessibilityLabel={t(count === 1 ? 'm.reviews.rated_one' : 'm.reviews.rated_other', { avg: average.toFixed(1), count })}>
      <TablerIcon name="star-filled" size={14} color={colors.gold} />
      <Text style={styles.ratingText}>
        {average.toFixed(1)} <Text style={styles.ratingCount}>· {t(count === 1 ? 'm.reviews.count_one' : 'm.reviews.count_other', { count })}</Text>
      </Text>
    </View>
  );
}

function when(utc: string | null, language: string) {
  if (!utc) return '';
  const d = new Date(utc);
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleDateString(language, { month: 'short', year: 'numeric' });
}

export function ReviewsSection({ config, professionalId }: { config: KounseliaConfig; professionalId: number }) {
  const styles = useStyles();
  const { width } = useWindowDimensions();
  const { language, t } = useLanguage();
  const [data, setData] = useState<ProfessionalReviews | null>(null);

  useEffect(() => {
    let cancelled = false;
    fetchProfessionalReviews(config, professionalId).then((res) => {
      if (!cancelled && res.ok) setData(res.data);
    });
    return () => {
      cancelled = true;
    };
  }, [config, professionalId]);

  // Nothing written yet (or still loading): the star line above is enough.
  if (!data || data.reviews.length === 0) return null;

  // One card fills most of the width, with the next one peeking in, so
  // it's clear there's more to swipe to.
  const cardWidth = data.reviews.length === 1 ? width - 40 : Math.min(width * 0.78, 340);

  return (
    <View style={styles.section}>
      <View style={styles.head}>
        <Text style={styles.title} accessibilityRole="header">
          {t('m.reviews.title')}
        </Text>
        <View style={styles.summary}>
          <Stars rating={data.average} size={13} />
          <Text style={styles.summaryText}>
            {data.average.toFixed(1)} · {data.count}
          </Text>
        </View>
      </View>
      <ScrollView
        horizontal
        showsHorizontalScrollIndicator={false}
        snapToInterval={cardWidth + 10}
        decelerationRate="fast"
        contentContainerStyle={styles.cards}
        style={styles.scroller}
      >
        {data.reviews.map((r, i) => (
          <Pressable
            key={i}
            onPress={() => showDialog({ title: t('m.rate.stars_of_5', { n: r.rating }), message: `“${r.comment}”${when(r.date_utc, language) ? `\n\n${when(r.date_utc, language)}` : ''}`, icon: 'star-filled' })}
            accessibilityRole="button"
            accessibilityLabel={`${t('m.rate.stars_of_5', { n: r.rating })}. ${r.comment}`}
            accessibilityHint={t('m.reviews.open_hint')}
            style={({ pressed }) => [styles.card, { width: cardWidth }, pressed && { opacity: 0.85 }]}
          >
            <View style={styles.cardTop}>
              <Stars rating={r.rating} />
              <Text style={styles.date}>{when(r.date_utc, language)}</Text>
            </View>
            <Text style={styles.comment} numberOfLines={4}>
              “{r.comment}”
            </Text>
          </Pressable>
        ))}
      </ScrollView>
      <Text style={styles.note}>{t('m.reviews.note')}</Text>
    </View>
  );
}

const useStyles = makeStyles((colors) => ({
  ratingLine: { flexDirection: 'row', alignItems: 'center', gap: 5, marginTop: 4 },
  ratingText: { fontFamily: fonts.semibold, fontSize: 13, color: colors.text },
  ratingCount: { fontFamily: fonts.regular, color: colors.text2 },
  section: { marginTop: 22 },
  head: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10 },
  title: { fontFamily: fonts.medium, fontSize: 13, color: colors.text2 },
  summary: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  summaryText: { fontFamily: fonts.medium, fontSize: 12, color: colors.text2 },
  // Lets the cards run to the screen's edges while lining up with the page.
  scroller: { marginHorizontal: -20 },
  cards: { gap: 10, paddingHorizontal: 20 },
  card: {
    padding: 14,
    borderRadius: radius.field,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
    minHeight: 110,
  },
  cardTop: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 8 },
  date: { fontFamily: fonts.regular, fontSize: 12, color: colors.text3 },
  comment: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 20, color: colors.text },
  note: { fontFamily: fonts.regular, fontSize: 12, color: colors.text3, marginTop: 8 },
}));

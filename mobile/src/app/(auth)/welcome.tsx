import * as Haptics from 'expo-haptics';
import { router } from 'expo-router';
import { useState } from 'react';
import { FlatList, Image, Text, useWindowDimensions, View, type ImageSourcePropType, type NativeScrollEvent, type NativeSyntheticEvent } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button } from '@/components/Button';
import { useT } from '@/language';
import { fonts, makeStyles, radius, shadows } from '@/theme';

// The campaign posters, shown whole: each is sized to the largest it can
// be inside the space above the buttons, so nothing is ever cropped on
// any phone. Their headline is part of the picture, so it's repeated as
// the label screen readers announce.
const SLIDES: { key: string; image: ImageSourcePropType; label: string }[] = [
  {
    key: 'no-waiting',
    image: require('../../../assets/onboarding/1-no-waiting.webp'),
    label: 'm.welcome.slide1',
  },
  {
    key: 'privacy',
    image: require('../../../assets/onboarding/2-privacy.webp'),
    label: 'm.welcome.slide2',
  },
  {
    key: 'not-alone',
    image: require('../../../assets/onboarding/3-not-alone.webp'),
    label: 'm.welcome.slide3',
  },
];

// Width ÷ height of the poster images (1200 × 1553).
const POSTER_RATIO = 1200 / 1553;
const SIDE_GAP = 24;

export default function Welcome() {
  const styles = useStyles();
  const t = useT();
  const { width: screenWidth } = useWindowDimensions();
  const [areaHeight, setAreaHeight] = useState(0);
  const [page, setPage] = useState(0);

  // Largest poster that fits both the screen width and the height left
  // over for it, keeping its shape.
  const posterWidth = Math.min(screenWidth - SIDE_GAP * 2, areaHeight * POSTER_RATIO);
  const posterHeight = posterWidth / POSTER_RATIO;

  // Updates the dots as soon as a slide is more than halfway in.
  function onScroll(e: NativeSyntheticEvent<NativeScrollEvent>) {
    const next = Math.round(e.nativeEvent.contentOffset.x / screenWidth);
    if (next !== page) {
      setPage(next);
      Haptics.selectionAsync().catch(() => undefined);
    }
  }

  return (
    <SafeAreaView style={styles.safe}>
      <View style={styles.slides} onLayout={(e) => setAreaHeight(e.nativeEvent.layout.height)}>
        {areaHeight > 0 && (
          <FlatList
            data={SLIDES}
            keyExtractor={(s) => s.key}
            horizontal
            pagingEnabled
            showsHorizontalScrollIndicator={false}
            onScroll={onScroll}
            scrollEventThrottle={32}
            getItemLayout={(_, index) => ({ length: screenWidth, offset: screenWidth * index, index })}
            renderItem={({ item }) => (
              <View style={[styles.slide, { width: screenWidth, height: areaHeight }]}>
                <View style={[styles.posterFrame, { width: posterWidth, height: posterHeight }]}>
                  <Image
                    source={item.image}
                    style={styles.poster}
                    resizeMode="contain"
                    accessible
                    accessibilityRole="image"
                    accessibilityLabel={t(item.label)}
                  />
                </View>
              </View>
            )}
          />
        )}
      </View>

      <View style={styles.dots} accessibilityRole="adjustable" accessibilityLabel={t('m.welcome.slide_of', { n: page + 1, total: SLIDES.length })}>
        {SLIDES.map((s, i) => (
          <View key={s.key} style={[styles.dot, i === page && styles.dotActive]} />
        ))}
      </View>

      <View style={styles.actions}>
        <Button title={t('m.welcome.create_free')} onPress={() => router.push('/sign-up')} />
        <Button title={t('m.welcome.have_account')} variant="ghost" onPress={() => router.push('/sign-in')} />
        {/* Professionals sign in with "I already have an account" too; this
            is for one who hasn't joined yet (the website's apply.php). */}
        <Text style={styles.pro}>
          {t('m.welcome.pro_prompt')}{' '}
          <Text accessibilityRole="link" style={styles.proLink} onPress={() => router.push('/apply')}>
            {t('m.welcome.pro_join')}
          </Text>
        </Text>
      </View>
    </SafeAreaView>
  );
}

const useStyles = makeStyles((colors) => ({
  safe: { flex: 1, backgroundColor: colors.bg },
  slides: { flex: 1, marginTop: 12 },
  slide: { alignItems: 'center', justifyContent: 'center' },
  posterFrame: { borderRadius: radius.r, backgroundColor: colors.surface2, ...shadows.card },
  poster: { width: '100%', height: '100%', borderRadius: radius.r },
  dots: { flexDirection: 'row', justifyContent: 'center', gap: 8, paddingVertical: 16 },
  dot: { width: 8, height: 8, borderRadius: 4, backgroundColor: colors.border },
  dotActive: { width: 22, backgroundColor: colors.brandNavy },
  actions: { gap: 12, paddingHorizontal: SIDE_GAP, paddingBottom: 16 },
  pro: { textAlign: 'center', marginTop: 4, fontFamily: fonts.regular, fontSize: 14, color: colors.text2 },
  proLink: { color: colors.accentText, fontFamily: fonts.medium },
}));

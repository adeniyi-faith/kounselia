import * as Haptics from 'expo-haptics';
import { router } from 'expo-router';
import { useState } from 'react';
import {
  FlatList,
  Image,
  StyleSheet,
  useWindowDimensions,
  View,
  type ImageSourcePropType,
  type NativeScrollEvent,
  type NativeSyntheticEvent,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button } from '@/components/Button';
import { colors, radius, shadows } from '@/theme';

// The campaign posters, shown whole: each is sized to the largest it can
// be inside the space above the buttons, so nothing is ever cropped on
// any phone. Their headline is part of the picture, so it's repeated as
// the label screen readers announce.
const SLIDES: { key: string; image: ImageSourcePropType; label: string }[] = [
  {
    key: 'no-waiting',
    image: require('../../../assets/onboarding/1-no-waiting.jpg'),
    label: 'No appointments. No waiting. Just support.',
  },
  {
    key: 'privacy',
    image: require('../../../assets/onboarding/2-privacy.jpg'),
    label: "We blurred this on purpose. Your privacy isn't a feature. It's a promise.",
  },
  {
    key: 'not-alone',
    image: require('../../../assets/onboarding/3-not-alone.jpg'),
    label: "You don't have to carry every thought alone.",
  },
];

// Width ÷ height of the poster images (1200 × 1553).
const POSTER_RATIO = 1200 / 1553;
const SIDE_GAP = 24;

export default function Welcome() {
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
                    accessibilityLabel={item.label}
                  />
                </View>
              </View>
            )}
          />
        )}
      </View>

      <View style={styles.dots} accessibilityRole="adjustable" accessibilityLabel={`Slide ${page + 1} of ${SLIDES.length}`}>
        {SLIDES.map((s, i) => (
          <View key={s.key} style={[styles.dot, i === page && styles.dotActive]} />
        ))}
      </View>

      <View style={styles.actions}>
        <Button title="Create a free account" onPress={() => router.push('/sign-up')} />
        <Button title="I already have an account" variant="ghost" onPress={() => router.push('/sign-in')} />
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.bg },
  slides: { flex: 1, marginTop: 12 },
  slide: { alignItems: 'center', justifyContent: 'center' },
  posterFrame: { borderRadius: radius.r, backgroundColor: colors.surface2, ...shadows.card },
  poster: { width: '100%', height: '100%', borderRadius: radius.r },
  dots: { flexDirection: 'row', justifyContent: 'center', gap: 8, paddingVertical: 16 },
  dot: { width: 8, height: 8, borderRadius: 4, backgroundColor: colors.border },
  dotActive: { width: 22, backgroundColor: colors.brandNavy },
  actions: { gap: 12, paddingHorizontal: SIDE_GAP, paddingBottom: 16 },
});

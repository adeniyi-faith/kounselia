import type { CounselorSummary } from '@kounselia/core';
import { router } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button } from '@/components/Button';
import { CounselorAvatar } from '@/components/CounselorAvatar';
import { useCounselors } from '@/counselors';
import { colors, fonts, radius, shadows } from '@/theme';

// The dashboard's "Talk to someone" grid (.counselor-grid): two tiles per
// row, each opening a conversation with that counselor.
export default function Talk() {
  const { status, counselors, reload } = useCounselors();
  const [refreshing, setRefreshing] = useState(false);

  async function refresh() {
    setRefreshing(true);
    await reload();
    setRefreshing(false);
  }

  const header = (
    <View style={styles.head}>
      <Text style={styles.title} accessibilityRole="header">
        Talk to someone
      </Text>
      <Text style={styles.sub}>Pick whoever fits right now</Text>
    </View>
  );

  if (status === 'loading') {
    return (
      <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
        {header}
        <ActivityIndicator color={colors.accent} style={styles.center} />
      </SafeAreaView>
    );
  }

  if (status === 'error') {
    return (
      <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
        {header}
        <View style={styles.center}>
          <Text style={styles.errorText}>We couldn't load the counselors. Please check your internet connection.</Text>
          <Button title="Try again" variant="ghost" onPress={refresh} busy={refreshing} />
        </View>
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      <FlatList
        data={counselors}
        keyExtractor={(c) => c.slug}
        numColumns={2}
        ListHeaderComponent={header}
        columnWrapperStyle={styles.row}
        contentContainerStyle={styles.list}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.accent} />}
        renderItem={({ item }) => <CounselorTile counselor={item} />}
      />
    </SafeAreaView>
  );
}

function CounselorTile({ counselor }: { counselor: CounselorSummary }) {
  return (
    <Pressable
      onPress={() => router.push({ pathname: '/chat/[slug]', params: { slug: counselor.slug } })}
      accessibilityRole="button"
      accessibilityLabel={`${counselor.name}, ${counselor.spec}`}
      accessibilityHint="Opens a conversation"
      style={({ pressed }) => [styles.tile, pressed && styles.tilePressed]}
    >
      <CounselorAvatar icon={counselor.icon} color={counselor.color} size={44} />
      <Text style={styles.tileName}>{counselor.name}</Text>
      <Text style={styles.tileSpec}>{counselor.spec}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.bg },
  list: { paddingHorizontal: 20, paddingBottom: 32 },
  head: { paddingTop: 20, paddingBottom: 18 },
  // .section-head h2 / .section-sub
  title: { fontFamily: fonts.serifMedium, fontSize: 28, color: colors.text },
  sub: { fontFamily: fonts.regular, fontSize: 13, color: colors.text3, marginTop: 2 },
  row: { gap: 12, marginBottom: 12 },
  // .counselor-tile
  tile: {
    flex: 1,
    alignItems: 'center',
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.sm,
    paddingVertical: 18,
    paddingHorizontal: 14,
    ...shadows.soft,
  },
  tilePressed: { transform: [{ scale: 0.97 }], borderColor: colors.text3 },
  tileName: { fontFamily: fonts.serifMedium, fontSize: 18, color: colors.text, marginTop: 10, marginBottom: 2 },
  tileSpec: { fontFamily: fonts.regular, fontSize: 12, lineHeight: 16, color: colors.text3, textAlign: 'center' },
  center: { flex: 1, justifyContent: 'center', gap: 16, paddingHorizontal: 20 },
  errorText: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 22, color: colors.text2, textAlign: 'center' },
});

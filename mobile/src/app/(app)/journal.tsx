// My journal: today's private reflection at the top (the same box as on
// Home), then every earlier day's entry, newest first. Tap a day to read
// all of it. Only today's entry can be changed, as on the website.
import { fetchJournalEntries, type JournalEntry } from '@kounselia/core';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button } from '@/components/Button';
import { SectionHead } from '@/components/dashboard/Card';
import { JournalCard } from '@/components/dashboard/JournalCard';
import { ScreenHeader } from '@/components/ScreenHeader';
import { TablerIcon } from '@/components/TablerIcon';
import { useSession } from '@/session';
import { fonts, makeStyles, radius, shadows, useColors } from '@/theme';

// "2026-09-26" is a calendar day, not a moment, so it's read as local noon
// to never slip into the day before or after.
function dayLabel(date: string) {
  const d = new Date(`${date}T12:00:00`);
  const sameYear = d.getFullYear() === new Date().getFullYear();
  return d.toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'long', ...(sameYear ? {} : { year: 'numeric' }) });
}

export default function Journal() {
  const styles = useStyles();
  const colors = useColors();
  const { config } = useSession();
  const [entries, setEntries] = useState<JournalEntry[] | null>(null);
  const [today, setToday] = useState('');
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [failed, setFailed] = useState(false);
  const [open, setOpen] = useState<string | null>(null);

  const load = useCallback(async () => {
    const res = await fetchJournalEntries(config, 1);
    if (!res.ok) {
      setFailed(true);
      return;
    }
    setFailed(false);
    setToday(res.data.entries.find((e) => e.is_today)?.content ?? '');
    setEntries(res.data.entries.filter((e) => !e.is_today));
    setHasMore(res.data.has_more);
    setPage(1);
  }, [config]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
  }, [load]);

  async function more() {
    if (!hasMore || loadingMore) return;
    setLoadingMore(true);
    const res = await fetchJournalEntries(config, page + 1);
    setLoadingMore(false);
    if (!res.ok) return;
    setEntries((list) => [...(list ?? []), ...res.data.entries.filter((e) => !e.is_today)]);
    setHasMore(res.data.has_more);
    setPage(page + 1);
  }

  async function refresh() {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  }

  if (!entries) {
    return (
      <SafeAreaView style={styles.safe}>
        <ScreenHeader title="My journal" />
        <View style={styles.center}>
          {failed ? (
            <>
              <Text style={styles.notice}>We couldn’t load your journal. Please check your internet connection.</Text>
              <Button title="Try again" variant="ghost" onPress={refresh} busy={refreshing} />
            </>
          ) : (
            <ActivityIndicator color={colors.accentText} />
          )}
        </View>
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      <ScreenHeader title="My journal" />
      <FlatList
        data={entries}
        keyExtractor={(e) => e.date}
        contentContainerStyle={styles.content}
        keyboardShouldPersistTaps="handled"
        keyboardDismissMode="interactive"
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.accentText} />}
        onEndReached={more}
        onEndReachedThreshold={0.4}
        ListHeaderComponent={
          <>
            <View style={styles.intro}>
              <TablerIcon name="lock" size={15} color={colors.sage} />
              <Text style={styles.introText}>Only you can see your journal. Your counselors and the Kounselia team can’t read it.</Text>
            </View>
            <SectionHead title="Today" note={new Date().toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'long' })} />
            <JournalCard config={config} initial={today} />
            <SectionHead title="Earlier days" />
          </>
        }
        ListEmptyComponent={
          <View style={styles.empty}>
            <TablerIcon name="notebook" size={28} color={colors.text3} />
            <Text style={styles.emptyText}>Your earlier entries will appear here. Write a little each day, it adds up.</Text>
          </View>
        }
        ListFooterComponent={loadingMore ? <ActivityIndicator color={colors.accentText} style={{ marginTop: 16 }} /> : null}
        ItemSeparatorComponent={() => <View style={{ height: 10 }} />}
        renderItem={({ item }) => {
          const expanded = open === item.date;
          return (
            <Pressable
              onPress={() => setOpen(expanded ? null : item.date)}
              accessibilityRole="button"
              accessibilityState={{ expanded }}
              accessibilityHint={expanded ? 'Shows less' : 'Shows the whole entry'}
              style={({ pressed }) => [styles.entry, pressed && { opacity: 0.9 }]}
            >
              <View style={styles.entryHead}>
                <Text style={styles.entryDate}>{dayLabel(item.date)}</Text>
                <TablerIcon name={expanded ? 'chevron-up' : 'chevron-down'} size={16} color={colors.text3} />
              </View>
              <Text style={styles.entryText} numberOfLines={expanded ? undefined : 3} selectable={expanded}>
                {item.content}
              </Text>
            </Pressable>
          );
        }}
      />
    </SafeAreaView>
  );
}

const useStyles = makeStyles((colors) => ({
  safe: { flex: 1, backgroundColor: colors.bg },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 16, padding: 24 },
  notice: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 22, color: colors.text2, textAlign: 'center' },
  content: { paddingHorizontal: 16, paddingBottom: 40 },
  intro: {
    flexDirection: 'row',
    gap: 10,
    alignItems: 'center',
    backgroundColor: colors.sageLight,
    borderRadius: radius.sm,
    paddingVertical: 12,
    paddingHorizontal: 14,
    marginTop: 4,
  },
  introText: { flex: 1, fontFamily: fonts.regular, fontSize: 13, lineHeight: 19, color: colors.sage },
  entry: {
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.sm + 4,
    padding: 18,
    ...shadows.soft,
  },
  entryHead: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 8 },
  entryDate: { fontFamily: fonts.semibold, fontSize: 13, color: colors.accentText },
  entryText: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 23, color: colors.text },
  empty: { alignItems: 'center', gap: 10, paddingVertical: 28, paddingHorizontal: 24 },
  emptyText: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 20, color: colors.text2, textAlign: 'center' },
}));

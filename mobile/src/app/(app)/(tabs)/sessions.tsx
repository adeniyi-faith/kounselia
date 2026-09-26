import { fetchSessions, type SessionSummary } from '@kounselia/core';
import { router, useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button } from '@/components/Button';
import { CounselorAvatar } from '@/components/CounselorAvatar';
import { EmptyState } from '@/components/dashboard/Card';
import { TablerIcon } from '@/components/TablerIcon';
import { useCounselors } from '@/counselors';
import { useSession } from '@/session';
import { colors, fonts } from '@/theme';

// "3 hours ago", "2 days ago" — like the website's session list.
function ago(iso: string | null): string {
  if (!iso) return '';
  const seconds = Math.max(0, (Date.now() - Date.parse(iso)) / 1000);
  const units: [number, string][] = [[31536000, 'year'], [2592000, 'month'], [604800, 'week'], [86400, 'day'], [3600, 'hour'], [60, 'min']];
  for (const [size, name] of units) {
    const n = Math.floor(seconds / size);
    if (n >= 1) return `${n} ${name}${n > 1 ? 's' : ''} ago`;
  }
  return 'just now';
}

// The dashboard's Sessions tab: recent conversations, most recent first.
export default function Sessions() {
  const { config } = useSession();
  const { bySlug } = useCounselors();
  const [sessions, setSessions] = useState<SessionSummary[] | null>(null);
  const [failed, setFailed] = useState(false);
  const [refreshing, setRefreshing] = useState(false);

  const load = useCallback(async () => {
    const res = await fetchSessions(config);
    if (res.ok) {
      setSessions(res.data.sessions);
      setFailed(false);
    } else {
      setFailed(true);
    }
  }, [config]);

  useFocusEffect(
    useCallback(() => {
      load();
    }, [load]),
  );

  async function refresh() {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  }

  const header = (
    <Text style={styles.title} accessibilityRole="header">
      Recent sessions
    </Text>
  );

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      {sessions === null ? (
        <View style={styles.list}>
          {header}
          {failed ? (
            <View style={styles.center}>
              <Text style={styles.notice}>We couldn’t load your sessions. Please check your internet connection.</Text>
              <Button title="Try again" variant="ghost" onPress={refresh} busy={refreshing} />
            </View>
          ) : (
            <ActivityIndicator color={colors.accent} style={{ marginTop: 40 }} />
          )}
        </View>
      ) : (
        <FlatList
          data={sessions}
          keyExtractor={(s) => String(s.id)}
          contentContainerStyle={styles.list}
          ListHeaderComponent={header}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.accent} />}
          ListEmptyComponent={
            <EmptyState>
              <TablerIcon name="feather" size={28} color={colors.text3} />
              <Text style={styles.notice}>Your story starts with one conversation. Nothing saved here yet.</Text>
              <Button title="Start a session" onPress={() => router.navigate('/talk')} />
            </EmptyState>
          }
          renderItem={({ item }) => {
            const c = bySlug(item.counselor_slug);
            const name = c?.name ?? item.counselor_slug;
            return (
              <Pressable
                onPress={() => router.push({ pathname: '/chat/[slug]', params: { slug: item.counselor_slug } })}
                accessibilityRole="button"
                accessibilityLabel={`${name}, ${item.message_count} messages, ${ago(item.last_at)}. Continue`}
                style={({ pressed }) => [styles.row, pressed && { backgroundColor: colors.surface2 }]}
              >
                <CounselorAvatar icon={c?.icon ?? 'message-circle'} color={c?.color ?? 'blue'} size={42} />
                <View style={styles.meta}>
                  <Text style={styles.name}>{name}</Text>
                  <Text style={styles.sub}>
                    {item.message_count} msgs · {ago(item.last_at)}
                  </Text>
                </View>
                <Text style={styles.link}>Continue →</Text>
              </Pressable>
            );
          }}
        />
      )}
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.bg },
  list: { padding: 16, paddingBottom: 40 },
  title: { fontFamily: fonts.serifMedium, fontSize: 28, color: colors.text, marginTop: 8, marginBottom: 16 },
  // .session-row
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 14,
    paddingVertical: 14,
    paddingHorizontal: 16,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 14,
    marginBottom: 10,
  },
  meta: { flex: 1, minWidth: 0 },
  name: { fontFamily: fonts.semibold, fontSize: 15, color: colors.text },
  sub: { fontFamily: fonts.regular, fontSize: 13, color: colors.text3, marginTop: 2 },
  link: { fontFamily: fonts.medium, fontSize: 13, color: colors.accent },
  center: { marginTop: 24, gap: 16 },
  notice: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 22, color: colors.text2, textAlign: 'center' },
});

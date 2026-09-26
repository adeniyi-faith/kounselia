// The blog, "The Kounselia Journal": newest articles first, with search
// and topics to narrow them down. More load as you scroll.
import { fetchBlog, type BlogCard, type BlogTag } from '@kounselia/core';
import { router } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, ScrollView, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { ArticleCard } from '@/components/articles/ArticleCard';
import { Button } from '@/components/Button';
import { ScreenHeader } from '@/components/ScreenHeader';
import { TablerIcon } from '@/components/TablerIcon';
import { useSession } from '@/session';
import { fonts, makeStyles, radius, useColors } from '@/theme';

export default function Articles() {
  const styles = useStyles();
  const colors = useColors();
  const { config } = useSession();
  const [posts, setPosts] = useState<BlogCard[] | null>(null);
  const [title, setTitle] = useState('The Kounselia Journal');
  const [tagline, setTagline] = useState('');
  const [tags, setTags] = useState<BlogTag[]>([]);
  const [tag, setTag] = useState('');
  const [query, setQuery] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [failed, setFailed] = useState(false);
  const latestRequest = useRef(0);

  const load = useCallback(async () => {
    const ticket = ++latestRequest.current;
    const res = await fetchBlog(config, { page: 1, tag, q: search });
    if (ticket !== latestRequest.current) return; // A newer search is on its way.
    if (!res.ok) {
      setFailed(true);
      return;
    }
    setFailed(false);
    setPosts(res.data.posts);
    setHasMore(res.data.has_more);
    setPage(1);
    if (res.data.title) setTitle(res.data.title);
    if (res.data.tagline) setTagline(res.data.tagline);
    // Keep the full list of topics while one of them is picked.
    if (res.data.tags && !tag && !search) setTags(res.data.tags);
  }, [config, tag, search]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
  }, [load]);

  // Search a moment after they stop typing.
  useEffect(() => {
    const t = setTimeout(() => setSearch(query.trim()), 400);
    return () => clearTimeout(t);
  }, [query]);

  async function more() {
    if (!hasMore || loadingMore || !posts) return;
    setLoadingMore(true);
    const res = await fetchBlog(config, { page: page + 1, tag, q: search });
    setLoadingMore(false);
    if (!res.ok) return;
    setPosts([...posts, ...res.data.posts]);
    setHasMore(res.data.has_more);
    setPage(page + 1);
  }

  async function refresh() {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  }

  const header = (
    <View>
      <Text style={styles.title} accessibilityRole="header">
        {title}
      </Text>
      {tagline ? <Text style={styles.tagline}>{tagline}</Text> : null}
      <View style={styles.searchBox}>
        <TablerIcon name="search" size={18} color={colors.text3} />
        <TextInput
          value={query}
          onChangeText={setQuery}
          placeholder="Search articles"
          placeholderTextColor={colors.text3}
          returnKeyType="search"
          accessibilityLabel="Search articles"
          style={styles.searchInput}
        />
        {query ? (
          <Pressable onPress={() => setQuery('')} accessibilityRole="button" accessibilityLabel="Clear search" hitSlop={8}>
            <TablerIcon name="x" size={16} color={colors.text3} />
          </Pressable>
        ) : null}
      </View>
      {tags.length > 0 && (
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips} style={styles.chipRow}>
          <Chip label="All" active={!tag} onPress={() => setTag('')} />
          {tags.map((t) => (
            <Chip key={t.slug} label={t.name} active={tag === t.slug} onPress={() => setTag(tag === t.slug ? '' : t.slug)} />
          ))}
        </ScrollView>
      )}
      {tags.length === 0 && <View style={{ height: 18 }} />}
    </View>
  );

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      <ScreenHeader title="Articles" />
      {!posts ? (
        <View style={styles.center}>
          {failed ? (
            <>
              <Text style={styles.notice}>We couldn’t load the articles. Please check your internet connection.</Text>
              <Button title="Try again" variant="ghost" onPress={refresh} busy={refreshing} />
            </>
          ) : (
            <ActivityIndicator color={colors.accentText} />
          )}
        </View>
      ) : (
        <FlatList
          data={posts}
          keyExtractor={(p) => String(p.id)}
          contentContainerStyle={styles.content}
          keyboardShouldPersistTaps="handled"
          keyboardDismissMode="on-drag"
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.accentText} />}
          onEndReached={more}
          onEndReachedThreshold={0.5}
          ListHeaderComponent={header}
          ItemSeparatorComponent={() => <View style={{ height: 16 }} />}
          ListEmptyComponent={
            <View style={styles.empty}>
              <TablerIcon name="news" size={28} color={colors.text3} />
              <Text style={styles.notice}>{search || tag ? 'No articles match that. Try another word or topic.' : 'No articles yet. Check back soon.'}</Text>
            </View>
          }
          ListFooterComponent={loadingMore ? <ActivityIndicator color={colors.accentText} style={{ marginTop: 20 }} /> : null}
          renderItem={({ item }) => (
            <ArticleCard post={item} onPress={() => router.push({ pathname: '/articles/[slug]', params: { slug: item.slug } })} />
          )}
        />
      )}
    </SafeAreaView>
  );
}

function Chip({ label, active, onPress }: { label: string; active: boolean; onPress: () => void }) {
  const styles = useStyles();
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityState={{ selected: active }}
      style={({ pressed }) => [styles.chip, active && styles.chipActive, pressed && { opacity: 0.85 }]}
    >
      <Text style={[styles.chipText, active && styles.chipTextActive]}>{label}</Text>
    </Pressable>
  );
}

const useStyles = makeStyles((colors) => ({
  safe: { flex: 1, backgroundColor: colors.bg },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 16, padding: 24 },
  notice: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 22, color: colors.text2, textAlign: 'center' },
  content: { paddingHorizontal: 16, paddingBottom: 40 },
  title: { fontFamily: fonts.serifMedium, fontSize: 32, lineHeight: 36, color: colors.text, marginTop: 6 },
  tagline: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 21, color: colors.text2, marginTop: 6 },
  searchBox: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    marginTop: 18,
    paddingHorizontal: 14,
    height: 46,
    borderRadius: radius.field,
    borderWidth: 1.5,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  searchInput: { flex: 1, fontFamily: fonts.regular, fontSize: 15, color: colors.text, paddingVertical: 0 },
  chipRow: { marginHorizontal: -16, marginTop: 14, marginBottom: 18 },
  chips: { gap: 8, paddingHorizontal: 16 },
  chip: {
    paddingVertical: 8,
    paddingHorizontal: 14,
    borderRadius: radius.pill,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  chipActive: { backgroundColor: colors.accent, borderColor: colors.accent },
  chipText: { fontFamily: fonts.medium, fontSize: 13, color: colors.text2 },
  chipTextActive: { color: '#fff' },
  empty: { alignItems: 'center', gap: 12, paddingVertical: 40, paddingHorizontal: 24 },
}));

// One blog article in a list: cover picture, topic, title, a line or two of
// summary, and who wrote it. `compact` is the narrower card used in the
// sideways row on Home.
import type { BlogCard } from '@kounselia/core';
import { Image } from 'expo-image';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { TablerIcon } from '@/components/TablerIcon';
import { colors, fonts, radius, shadows } from '@/theme';

export function articleDate(iso: string | null) {
  if (!iso) return '';
  const d = new Date(iso);
  const sameYear = d.getFullYear() === new Date().getFullYear();
  return d.toLocaleDateString([], { day: 'numeric', month: 'short', ...(sameYear ? {} : { year: 'numeric' }) });
}

export function ArticleCard({ post, onPress, compact }: { post: BlogCard; onPress: () => void; compact?: boolean }) {
  const topic = post.tags[0]?.name;
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="link"
      accessibilityLabel={`${post.title}. ${post.reading_minutes} minute read`}
      style={({ pressed }) => [styles.card, compact && styles.compact, pressed && { opacity: 0.92 }]}
    >
      {post.cover ? (
        <Image source={{ uri: post.cover }} style={[styles.cover, compact && styles.coverCompact]} contentFit="cover" transition={200} />
      ) : (
        <View style={[styles.cover, compact && styles.coverCompact, styles.placeholder]}>
          <TablerIcon name="feather" size={compact ? 26 : 32} color={colors.gold} />
        </View>
      )}
      <View style={styles.body}>
        {topic ? <Text style={styles.topic}>{topic.toUpperCase()}</Text> : null}
        <Text style={[styles.title, compact && styles.titleCompact]} numberOfLines={compact ? 2 : 3}>
          {post.title}
        </Text>
        {!compact && post.summary ? (
          <Text style={styles.summary} numberOfLines={2}>
            {post.summary}
          </Text>
        ) : null}
        <View style={styles.meta}>
          {!compact && <Text style={styles.metaText}>{post.author.name}</Text>}
          {!compact && <View style={styles.dot} />}
          <Text style={styles.metaText}>{articleDate(post.published_utc)}</Text>
          <View style={styles.dot} />
          <Text style={styles.metaText}>{post.reading_minutes} min read</Text>
        </View>
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.r,
    overflow: 'hidden',
    ...shadows.soft,
  },
  compact: { width: 240 },
  cover: { width: '100%', aspectRatio: 16 / 9, backgroundColor: colors.surface2 },
  coverCompact: { aspectRatio: 16 / 10 },
  placeholder: { alignItems: 'center', justifyContent: 'center', backgroundColor: colors.goldLight },
  body: { padding: 16, gap: 6 },
  topic: { fontFamily: fonts.semibold, fontSize: 11, letterSpacing: 1, color: colors.gold },
  title: { fontFamily: fonts.serifMedium, fontSize: 22, lineHeight: 26, color: colors.text },
  titleCompact: { fontSize: 19, lineHeight: 23 },
  summary: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 20, color: colors.text2 },
  meta: { flexDirection: 'row', alignItems: 'center', flexWrap: 'wrap', gap: 6, marginTop: 4 },
  metaText: { fontFamily: fonts.regular, fontSize: 12, color: colors.text3 },
  dot: { width: 3, height: 3, borderRadius: 2, backgroundColor: colors.text3 },
});

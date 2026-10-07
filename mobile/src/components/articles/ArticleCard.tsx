// One blog article in a list: cover picture, topic, title, a line or two of
// summary, who wrote it (with a tick for a verified professional), and how
// many loves and comments it has. `compact` is the narrower card used in
// the sideways row on Home.
import type { BlogCard } from '@kounselia/core';
import { Image } from 'expo-image';
import { Pressable, Text, View } from 'react-native';
import { TablerIcon } from '@/components/TablerIcon';
import { useLanguage } from '@/language';
import { fonts, makeStyles, radius, shadows, useColors } from '@/theme';

export function articleDate(iso: string | null, language?: string) {
  if (!iso) return '';
  const d = new Date(iso);
  const sameYear = d.getFullYear() === new Date().getFullYear();
  return d.toLocaleDateString(language ?? [], { day: 'numeric', month: 'short', ...(sameYear ? {} : { year: 'numeric' }) });
}

export function ArticleCard({ post, onPress, compact }: { post: BlogCard; onPress: () => void; compact?: boolean }) {
  const styles = useStyles();
  const colors = useColors();
  const { language, t } = useLanguage();
  const topic = post.tags[0]?.name;
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="link"
      accessibilityLabel={t('m.b.card.label', { title: post.title, minutes: post.reading_minutes })}
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
          {!compact && post.author.is_professional ? <TablerIcon name="discount-check-filled" size={14} color={colors.sage} /> : null}
          {!compact && <View style={styles.dot} />}
          <Text style={styles.metaText}>{articleDate(post.published_utc, language)}</Text>
          <View style={styles.dot} />
          <Text style={styles.metaText}>{t('m.b.card.min_read', { minutes: post.reading_minutes })}</Text>
          {!compact && post.love_count > 0 ? (
            <View style={styles.count} accessibilityLabel={post.love_count === 1 ? t('m.b.actions.loves_one') : t('m.b.actions.loves_other', { n: post.love_count })}>
              <TablerIcon name="heart" size={13} color={colors.text3} />
              <Text style={styles.metaText}>{post.love_count}</Text>
            </View>
          ) : null}
          {!compact && post.comment_count > 0 ? (
            <View style={styles.count} accessibilityLabel={post.comment_count === 1 ? t('m.b.actions.comments_one') : t('m.b.actions.comments_other', { n: post.comment_count })}>
              <TablerIcon name="message-circle" size={13} color={colors.text3} />
              <Text style={styles.metaText}>{post.comment_count}</Text>
            </View>
          ) : null}
        </View>
      </View>
    </Pressable>
  );
}

const useStyles = makeStyles((colors) => ({
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
  count: { flexDirection: 'row', alignItems: 'center', gap: 3, marginLeft: 4 },
}));

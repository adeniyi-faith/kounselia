// The bar under an article: love it, open the conversation, and, when a
// verified professional wrote it, follow them or book a session. Mirrors
// the buttons at the end of an article on the website.
import { setFollowing, setPostLove, type BlogPost, type PostCommunity } from '@kounselia/core';
import * as Haptics from 'expo-haptics';
import { router } from 'expo-router';
import { useEffect, useState } from 'react';
import { Pressable, ScrollView, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { showDialog } from '@/components/Dialog';
import { TablerIcon } from '@/components/TablerIcon';
import { useSession } from '@/session';
import { fonts, makeStyles, radius, useColors } from '@/theme';

// The conversation screen tells the article it came from how many
// comments there are now, so the count here is right on the way back.
const countListeners = new Map<number, (count: number) => void>();
export function reportCommentCount(postId: number, count: number) {
  countListeners.get(postId)?.(count);
}

export function ArticleActions({ post, onNotify }: { post: BlogPost; onNotify: (text: string) => void }) {
  const styles = useStyles();
  const colors = useColors();
  const insets = useSafeAreaInsets();
  const { config } = useSession();
  const [c, setC] = useState<PostCommunity>(post.community);

  useEffect(() => {
    countListeners.set(post.id, (n) => setC((prev) => ({ ...prev, comment_count: n })));
    return () => {
      countListeners.delete(post.id);
    };
  }, [post.id]);

  if (!c || (!c.loves_on && !c.comments_on && !c.follows_on && !c.book_pro_id)) return null;

  async function love() {
    const next = !c.loved;
    Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light).catch(() => undefined);
    setC({ ...c, loved: next, love_count: Math.max(0, c.love_count + (next ? 1 : -1)) }); // Right away; corrected below.
    const res = await setPostLove(config, post.id, next);
    if (res.ok) setC((prev) => ({ ...prev, loved: res.data.loved, love_count: res.data.count }));
    else {
      setC((prev) => ({ ...prev, loved: !next, love_count: Math.max(0, prev.love_count + (next ? -1 : 1)) }));
      onNotify(res.message);
    }
  }

  async function changeFollow(next: boolean) {
    const id = post.author.professional_id;
    if (!id) return;
    const res = await setFollowing(config, id, next);
    if (!res.ok) {
      onNotify(res.message);
      return;
    }
    setC((prev) => ({ ...prev, following: res.data.following, followers: res.data.followers }));
    if (res.data.following) onNotify(`You'll hear when ${post.author.name} publishes something new.`);
  }

  function follow() {
    if (!c.following) {
      changeFollow(true);
      return;
    }
    showDialog({
      title: `Unfollow ${post.author.name}?`,
      message: "You won't hear about their new articles any more.",
      icon: 'user-minus',
      buttons: [{ text: 'Keep following', style: 'cancel' }, { text: 'Unfollow', style: 'destructive', onPress: () => changeFollow(false) }],
    });
  }

  return (
    <View style={[styles.bar, { paddingBottom: Math.max(insets.bottom, 10) }]}>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.row}>
        {c.loves_on && (
          <Pressable
            onPress={love}
            accessibilityRole="button"
            accessibilityState={{ selected: c.loved }}
            accessibilityLabel={`${c.loved ? 'Loved' : 'Love this article'}, ${c.love_count} ${c.love_count === 1 ? 'love' : 'loves'}`}
            style={({ pressed }) => [styles.pill, c.loved && styles.loved, pressed && styles.pressed]}
          >
            <TablerIcon name={c.loved ? 'heart-filled' : 'heart'} size={19} color={c.loved ? colors.rose : colors.text2} />
            <Text style={[styles.count, c.loved && { color: colors.rose }]}>{c.love_count}</Text>
          </Pressable>
        )}
        {c.comments_on && (
          <Pressable
            onPress={() => router.push({ pathname: '/comments/[postId]', params: { postId: String(post.id), title: post.title, count: String(c.comment_count) } })}
            accessibilityRole="button"
            accessibilityLabel={`Conversation, ${c.comment_count} ${c.comment_count === 1 ? 'comment' : 'comments'}`}
            style={({ pressed }) => [styles.pill, pressed && styles.pressed]}
          >
            <TablerIcon name="message-circle" size={19} color={colors.text2} />
            <Text style={styles.count}>{c.comment_count}</Text>
          </Pressable>
        )}
        <View style={styles.spacer} />
        {c.follows_on && (
          <Pressable
            onPress={follow}
            accessibilityRole="button"
            accessibilityState={{ selected: c.following }}
            accessibilityLabel={c.following ? `Following ${post.author.name}` : `Follow ${post.author.name}`}
            style={({ pressed }) => [styles.pill, c.following ? null : styles.follow, pressed && styles.pressed]}
          >
            <TablerIcon name={c.following ? 'check' : 'user-plus'} size={17} color={c.following ? colors.accentText : '#fff'} />
            <Text style={[styles.label, !c.following && { color: '#fff' }]} numberOfLines={1}>
              {c.following ? 'Following' : 'Follow'}
            </Text>
          </Pressable>
        )}
        {c.book_pro_id ? (
          <Pressable
            onPress={() => router.push({ pathname: '/book/[proId]', params: { proId: String(c.book_pro_id) } })}
            accessibilityRole="button"
            accessibilityLabel={`Book a session with ${post.author.name}`}
            style={({ pressed }) => [styles.pill, styles.book, pressed && styles.pressed]}
          >
            <TablerIcon name="video" size={17} color={colors.gold} />
            <Text style={[styles.label, { color: colors.gold }]} numberOfLines={1}>
              Book
            </Text>
          </Pressable>
        ) : null}
      </ScrollView>
    </View>
  );
}

const useStyles = makeStyles((colors) => ({
  bar: {
    paddingHorizontal: 14,
    paddingTop: 10,
    borderTopWidth: 1,
    borderTopColor: colors.border,
    backgroundColor: colors.surface,
  },
  // flexGrow makes this at least as wide as the bar when everything fits,
  // so the spacer below still pushes Follow/Book to the right edge as
  // before. On a narrow phone, where it doesn't all fit, this scrolls
  // sideways instead of wrapping Book onto its own line or clipping it.
  row: { flexDirection: 'row', alignItems: 'center', gap: 8, flexGrow: 1 },
  spacer: { flex: 1, minWidth: 8 },
  pill: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    minHeight: 42,
    paddingHorizontal: 14,
    borderRadius: radius.pill,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  pressed: { opacity: 0.85, transform: [{ scale: 0.97 }] },
  loved: { backgroundColor: colors.roseLight, borderColor: colors.roseLight },
  follow: { backgroundColor: colors.accent, borderColor: colors.accent },
  book: { backgroundColor: colors.goldLight, borderColor: colors.goldLight },
  count: { fontFamily: fonts.medium, fontSize: 14, color: colors.text2, minWidth: 8 },
  label: { fontFamily: fonts.medium, fontSize: 14, color: colors.accentText },
}));

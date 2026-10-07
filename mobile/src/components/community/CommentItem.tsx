// One comment in an article's conversation, with its replies under it.
// Members appear by the first name or nickname they chose (never a photo);
// the article's own author appears under their name with an "Author" tag.
import type { Comment } from '@kounselia/core';
import { Image } from 'expo-image';
import { Pressable, Text, View } from 'react-native';
import { TablerIcon } from '@/components/TablerIcon';
import { useT } from '@/language';
import { fonts, makeStyles, useColors, type Palette } from '@/theme';

// A soft colour per name, so a thread is easy to follow at a glance.
function tone(name: string, colors: Palette) {
  const tones = [
    { fg: colors.accentText, bg: colors.accentLight },
    { fg: colors.gold, bg: colors.goldLight },
    { fg: colors.sage, bg: colors.sageLight },
    { fg: colors.rose, bg: colors.roseLight },
  ];
  let h = 0;
  for (let i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) >>> 0;
  return tones[h % tones.length];
}

interface Props {
  comment: Comment;
  reply?: boolean;
  canReply: boolean;
  onLove: (c: Comment) => void;
  onReply: (c: Comment) => void;
  onMore: (c: Comment, isReply: boolean) => void;
}

export function CommentItem({ comment: c, reply = false, canReply, onLove, onReply, onMore }: Props) {
  const styles = useStyles();
  const colors = useColors();
  const t = useT();
  const removed = c.status === 'removed';
  const look = c.author.is_author ? { fg: colors.accentText, bg: colors.accentLight } : tone(c.author.name, colors);

  return (
    <View style={[styles.row, reply && styles.replyRow, c.held && styles.held]}>
      <View style={[styles.av, reply && styles.avSmall, { backgroundColor: look.bg }]}>
        {c.author.avatar ? (
          <Image source={{ uri: c.author.avatar }} style={styles.avImg} contentFit="cover" />
        ) : (
          <Text style={[styles.avText, { color: look.fg }]}>{c.author.initial}</Text>
        )}
      </View>
      <View style={styles.main}>
        <View style={styles.head}>
          <Text style={styles.name}>{c.author.name}</Text>
          {c.author.is_author && (
            <View style={styles.badge}>
              <TablerIcon name="discount-check-filled" size={12} color={colors.sage} />
              <Text style={styles.badgeText}>{t('m.b.item.author')}</Text>
            </View>
          )}
          <Text style={styles.time}>· {c.time_label}</Text>
          {c.pinned && (
            <View style={styles.pinned}>
              <TablerIcon name="pin" size={12} color={colors.gold} />
              <Text style={[styles.time, { color: colors.gold }]}>{t('m.b.item.pinned')}</Text>
            </View>
          )}
        </View>
        <Text style={[styles.text, removed && styles.removed]} selectable={!removed}>
          {c.content}
        </Text>
        {c.held && <Text style={styles.heldNote}>{t('m.b.item.held')}</Text>}

        {!removed && (
          <View style={styles.actions}>
            {!c.held && (
              <Pressable
                onPress={() => onLove(c)}
                accessibilityRole="button"
                accessibilityState={{ selected: c.loved }}
                accessibilityLabel={`${c.loved ? t('m.b.item.loved') : t('m.b.item.love')}, ${c.love_count}`}
                hitSlop={6}
                style={styles.action}
              >
                <TablerIcon name={c.loved ? 'heart-filled' : 'heart'} size={16} color={c.loved ? colors.rose : colors.text3} />
                {c.love_count > 0 && <Text style={[styles.actionText, c.loved && { color: colors.rose }]}>{c.love_count}</Text>}
              </Pressable>
            )}
            {!c.held && canReply && (
              <Pressable onPress={() => onReply(c)} accessibilityRole="button" accessibilityLabel={t('m.b.item.reply_to', { name: c.author.name })} hitSlop={6} style={styles.action}>
                <TablerIcon name="message-circle" size={16} color={colors.text3} />
                <Text style={styles.actionText}>{t('m.b.item.reply')}</Text>
              </Pressable>
            )}
            <Pressable onPress={() => onMore(c, reply)} accessibilityRole="button" accessibilityLabel={t('m.b.chath.more')} hitSlop={6} style={styles.action}>
              <TablerIcon name="dots-vertical" size={16} color={colors.text3} />
            </Pressable>
          </View>
        )}

        {!reply &&
          c.replies.map((r) => <CommentItem key={r.id} comment={r} reply canReply={canReply} onLove={onLove} onReply={onReply} onMore={onMore} />)}
      </View>
    </View>
  );
}

const useStyles = makeStyles((colors) => ({
  row: { flexDirection: 'row', gap: 12, paddingVertical: 14 },
  replyRow: { paddingVertical: 10, paddingBottom: 2 },
  held: { opacity: 0.75 },
  av: { width: 36, height: 36, borderRadius: 18, alignItems: 'center', justifyContent: 'center', overflow: 'hidden' },
  avSmall: { width: 28, height: 28, borderRadius: 14 },
  avImg: { width: '100%', height: '100%' },
  avText: { fontFamily: fonts.semibold, fontSize: 14 },
  main: { flex: 1, minWidth: 0 },
  head: { flexDirection: 'row', alignItems: 'center', flexWrap: 'wrap', gap: 5 },
  name: { fontFamily: fonts.semibold, fontSize: 14, color: colors.text },
  badge: { flexDirection: 'row', alignItems: 'center', gap: 3, backgroundColor: colors.sageLight, borderRadius: 20, paddingHorizontal: 7, paddingVertical: 2 },
  badgeText: { fontFamily: fonts.semibold, fontSize: 11, color: colors.sage },
  pinned: { flexDirection: 'row', alignItems: 'center', gap: 2 },
  time: { fontFamily: fonts.regular, fontSize: 12, color: colors.text3 },
  text: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 22, color: colors.text, marginTop: 3 },
  removed: { fontStyle: 'italic', color: colors.text3 },
  heldNote: {
    alignSelf: 'flex-start',
    fontFamily: fonts.regular,
    fontSize: 12,
    color: colors.gold,
    backgroundColor: colors.goldLight,
    borderRadius: 8,
    paddingHorizontal: 8,
    paddingVertical: 4,
    marginTop: 6,
    overflow: 'hidden',
  },
  actions: { flexDirection: 'row', alignItems: 'center', gap: 14, marginTop: 6 },
  action: { flexDirection: 'row', alignItems: 'center', gap: 4, minHeight: 28 },
  actionText: { fontFamily: fonts.medium, fontSize: 12.5, color: colors.text3 },
}));

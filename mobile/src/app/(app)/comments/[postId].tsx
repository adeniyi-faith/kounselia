// The conversation under an article: comments with one level of replies,
// loves, and a box at the bottom to join in. The first time someone
// comments they choose how their name is shown (first name or a
// nickname; never their full name or photo). A comment that sounds like
// someone is in crisis stays private and shows them where to get help.
// Server side: includes/community.php, shared with the website.
import {
  addComment,
  deleteComment,
  fetchComments,
  moderateComment,
  REPORT_REASONS,
  reportComment,
  saveCommunityIdentity,
  setCommentLove,
  type Comment,
  type CommentViewer,
} from '@kounselia/core';
import * as Clipboard from 'expo-clipboard';
import * as Haptics from 'expo-haptics';
import { useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';
import { FlatList, KeyboardAvoidingView, Pressable, RefreshControl, Text, TextInput, View } from 'react-native';
import { SafeAreaView, useSafeAreaInsets } from 'react-native-safe-area-context';
import { reportCommentCount } from '@/components/articles/ArticleActions';
import { Button } from '@/components/Button';
import { Toast, useToast } from '@/components/chat/Toast';
import { CommentItem } from '@/components/community/CommentItem';
import { showDialog } from '@/components/Dialog';
import { openSafetyResources } from '@/components/openSafety';
import { ScreenHeader } from '@/components/ScreenHeader';
import { Sheet } from '@/components/Sheet';
import { ArticlesSkeleton } from '@/components/Skeleton';
import { TablerIcon } from '@/components/TablerIcon';
import { useKeyboardOpen } from '@/components/useKeyboardOpen';
import { useT } from '@/language';
import { useSession } from '@/session';
import { fonts, makeStyles, radius, useColors } from '@/theme';

// Puts a comment (or reply) into the list, or updates one already there.
function upsert(list: Comment[], c: Comment): Comment[] {
  if (!c.parent_id) return [c, ...list.filter((x) => x.id !== c.id)];
  return list.map((x) => (x.id === c.parent_id ? { ...x, replies: [...x.replies.filter((r) => r.id !== c.id), c] } : x));
}

function mapComment(list: Comment[], id: number, fn: (c: Comment) => Comment): Comment[] {
  return list.map((x) => (x.id === id ? fn(x) : { ...x, replies: x.replies.map((r) => (r.id === id ? fn(r) : r)) }));
}

export default function Conversation() {
  const styles = useStyles();
  const colors = useColors();
  const t = useT();
  const insets = useSafeAreaInsets();
  const keyboardOpen = useKeyboardOpen();
  const toast = useToast();
  const { config } = useSession();
  const { postId: postIdParam, title, count } = useLocalSearchParams<{ postId: string; title?: string; count?: string }>();
  const postId = Number(postIdParam);

  const [comments, setComments] = useState<Comment[] | null>(null);
  const [viewer, setViewer] = useState<CommentViewer | null>(null);
  const [enabled, setEnabled] = useState(true);
  const [total, setTotal] = useState(Number(count) || 0);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [failed, setFailed] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);

  const [text, setText] = useState('');
  const [posting, setPosting] = useState(false);
  const [replyTo, setReplyTo] = useState<Comment | null>(null);
  const [care, setCare] = useState<string | null>(null);
  const input = useRef<TextInput>(null);

  const [menu, setMenu] = useState<{ c: Comment; isReply: boolean } | null>(null);
  const [reporting, setReporting] = useState<Comment | null>(null);
  const [reason, setReason] = useState(REPORT_REASONS[0].key);
  const [reportNote, setReportNote] = useState('');
  const [naming, setNaming] = useState(false);
  const [nameMode, setNameMode] = useState<'first_name' | 'nickname'>('first_name');
  const [nickname, setNickname] = useState('');
  const [nameError, setNameError] = useState('');
  const [savingName, setSavingName] = useState(false);

  const load = useCallback(async () => {
    const res = await fetchComments(config, postId, 1);
    if (!res.ok) {
      setFailed(res.offline ? t('m.b.comments.load_failed') : res.message);
      return;
    }
    setFailed(null);
    setComments(res.data.comments);
    setViewer(res.data.viewer);
    setEnabled(res.data.enabled);
    setHasMore(res.data.has_more);
    setPage(1);
  }, [config, postId, t]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
  }, [load]);

  // The article screen's counter follows along.
  function setCount(n: number | null | undefined) {
    if (typeof n !== 'number') return;
    setTotal(n);
    reportCommentCount(postId, n);
  }

  async function refresh() {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  }

  async function more() {
    if (!hasMore || loadingMore || !comments) return;
    setLoadingMore(true);
    const res = await fetchComments(config, postId, page + 1);
    setLoadingMore(false);
    if (!res.ok) return;
    const seen = new Set(comments.map((c) => c.id));
    setComments([...comments, ...res.data.comments.filter((c) => !seen.has(c.id))]);
    setHasMore(res.data.has_more);
    setPage(page + 1);
  }

  async function post() {
    const content = text.trim();
    if (content.length < 2 || posting) return;
    setPosting(true);
    const res = await addComment(config, postId, content, replyTo?.id);
    setPosting(false);
    if (!res.ok) {
      toast.show(res.message);
      return;
    }
    Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined);
    setText('');
    setReplyTo(null);
    setComments((list) => upsert(list ?? [], res.data.comment));
    setCount(res.data.count);
    if (res.data.safety) setCare(res.data.message);
    else if (res.data.held) toast.show(res.data.message);
  }

  async function love(c: Comment) {
    const next = !c.loved;
    setComments((list) => list && mapComment(list, c.id, (x) => ({ ...x, loved: next, love_count: Math.max(0, x.love_count + (next ? 1 : -1)) })));
    const res = await setCommentLove(config, c.id, next);
    if (res.ok) setComments((list) => list && mapComment(list, c.id, (x) => ({ ...x, loved: res.data.loved, love_count: res.data.count })));
    else {
      setComments((list) => list && mapComment(list, c.id, (x) => ({ ...x, loved: !next, love_count: c.love_count })));
      toast.show(res.message);
    }
  }

  function reply(c: Comment) {
    if (!viewer?.can_comment) {
      if (viewer?.reason === 'need_identity') setNaming(true);
      else toast.show(viewer?.message ?? t('m.b.comments.cannot'));
      return;
    }
    // One level of replies: answering a reply joins the same thread.
    const root = c.parent_id ? (comments ?? []).find((x) => x.id === c.parent_id) ?? c : c;
    setReplyTo(root);
    setTimeout(() => input.current?.focus(), 50);
  }

  async function afterChange(res: { ok: true; data: { message: string; count: number | null } } | { ok: false; message: string }) {
    if (!res.ok) {
      toast.show(res.message);
      return;
    }
    toast.show(res.data.message);
    setCount(res.data.count);
    await load();
  }

  function remove(c: Comment) {
    showDialog({
      title: t('m.b.comments.delete_title'),
      message: t('m.b.comments.delete_body'),
      icon: 'trash',
      tone: 'danger',
      buttons: [
        { text: t('m.b.common.cancel'), style: 'cancel' },
        {
          text: t('m.b.common.delete'),
          style: 'destructive',
          onPress: async () => afterChange(await deleteComment(config, c.id)),
        },
      ],
    });
  }

  function moderate(c: Comment, act: 'hide' | 'pin' | 'unpin') {
    const go = async () => afterChange(await moderateComment(config, c.id, act));
    if (act !== 'hide') {
      go();
      return;
    }
    showDialog({
      title: t('m.b.comments.hide_title'),
      message: t('m.b.comments.hide_body'),
      icon: 'eye-off',
      buttons: [{ text: t('m.b.common.cancel'), style: 'cancel' }, { text: t('m.b.comments.hide'), style: 'destructive', onPress: go }],
    });
  }

  async function sendReport() {
    if (!reporting) return;
    const res = await reportComment(config, reporting.id, reason, reportNote);
    setReporting(null);
    setReportNote('');
    toast.show(res.ok ? res.data.message : res.message);
  }

  async function saveName() {
    setSavingName(true);
    setNameError('');
    const res = await saveCommunityIdentity(config, nameMode, nickname);
    setSavingName(false);
    if (!res.ok) {
      setNameError(res.message);
      return;
    }
    setViewer((v) => (v ? { ...v, identity: res.data, can_comment: true, reason: null, message: null } : v));
    setNaming(false);
    toast.show(t('m.b.comments.thanks_name', { name: res.data.name }));
    setTimeout(() => input.current?.focus(), 300);
  }

  // The reasons come from the shared core list in English; show them in the member's language.
  function reasonLabel(key: string, fallback: string) {
    switch (key) {
      case 'unkind':
        return t('m.b.comments.reason_unkind');
      case 'harmful':
        return t('m.b.comments.reason_harmful');
      case 'spam':
        return t('m.b.comments.reason_spam');
      case 'private_info':
        return t('m.b.comments.reason_private');
      case 'other':
        return t('m.b.comments.reason_other');
      default:
        return fallback;
    }
  }

  function openNaming() {
    const id = viewer?.identity;
    setNameMode(id?.mode === 'nickname' ? 'nickname' : 'first_name');
    setNickname(id?.nickname ?? '');
    setNameError('');
    setNaming(true);
  }

  const header = (
    <View style={styles.headerBlock}>
      {title ? (
        <Text style={styles.articleTitle} numberOfLines={2}>
          {title}
        </Text>
      ) : null}
      <View style={styles.note}>
        <TablerIcon name="heart-handshake" size={18} color={colors.gold} />
        <Text style={styles.noteText}>{t('m.b.comments.be_kind')}</Text>
      </View>
      {care && (
        <View style={styles.care} accessibilityRole="alert">
          <Text style={styles.careTitle}>{t('m.b.comments.not_alone')}</Text>
          <Text style={styles.careText}>{care}</Text>
          <Button title={t('m.b.comments.get_help')} onPress={openSafetyResources} style={{ marginTop: 12 }} />
        </View>
      )}
    </View>
  );

  function composer() {
    if (!viewer) return null;
    if (!enabled) return <Text style={styles.closed}>{t('m.b.comments.closed')}</Text>;
    if (viewer.reason === 'need_identity') {
      return (
        <Pressable onPress={openNaming} accessibilityRole="button" style={({ pressed }) => [styles.joinBtn, pressed && { opacity: 0.85 }]}>
          <TablerIcon name="message-circle" size={18} color="#fff" />
          <Text style={styles.joinText}>{t('m.b.comments.join')}</Text>
        </Pressable>
      );
    }
    if (!viewer.can_comment) return <Text style={styles.closed}>{viewer.message}</Text>;
    const left = viewer.max_length - text.length;
    return (
      <View>
        {replyTo ? (
          <View style={styles.replying}>
            <Text style={styles.replyingText} numberOfLines={1}>
              {t('m.b.comments.replying_to', { name: replyTo.author.name })}
            </Text>
            <Pressable onPress={() => setReplyTo(null)} accessibilityRole="button" accessibilityLabel={t('m.b.comments.cancel_reply')} hitSlop={8}>
              <TablerIcon name="x" size={16} color={colors.text3} />
            </Pressable>
          </View>
        ) : null}
        <View style={styles.inputRow}>
          <TextInput
            ref={input}
            value={text}
            onChangeText={setText}
            placeholder={replyTo ? t('m.b.comments.write_reply') : t('m.b.comments.write_comment')}
            placeholderTextColor={colors.text3}
            multiline
            maxLength={viewer.max_length}
            style={styles.input}
            accessibilityLabel={replyTo ? t('m.b.comments.your_reply') : t('m.b.comments.your_comment')}
          />
          <Pressable
            onPress={post}
            disabled={text.trim().length < 2 || posting}
            accessibilityRole="button"
            accessibilityLabel={t('m.b.comments.post')}
            style={({ pressed }) => [styles.send, (text.trim().length < 2 || posting) && { opacity: 0.4 }, pressed && { opacity: 0.8 }]}
          >
            <TablerIcon name="send" size={18} color="#fff" />
          </Pressable>
        </View>
        <View style={styles.underInput}>
          <Pressable onPress={openNaming} accessibilityRole="button" hitSlop={6}>
            <Text style={styles.postingAs}>
              {t('m.b.comments.posting_as', { name: viewer.identity?.name ?? t('m.b.comments.you') })} · <Text style={styles.link}>{t('m.b.comments.change')}</Text>
            </Text>
          </Pressable>
          {left < 200 ? <Text style={[styles.postingAs, left < 20 && { color: colors.rose }]}>{left}</Text> : null}
        </View>
      </View>
    );
  }

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      <ScreenHeader title={total ? t('m.b.comments.title_count', { n: total }) : t('m.b.comments.title')} />
      <KeyboardAvoidingView style={styles.flex} behavior="padding">
        {!comments ? (
          failed ? (
            <View style={styles.center}>
              <Text style={styles.empty}>{failed}</Text>
              <Button title={t('m.b.common.try_again')} variant="ghost" onPress={refresh} busy={refreshing} />
            </View>
          ) : (
            <View style={{ padding: 16 }}>
              <ArticlesSkeleton cards={1} />
            </View>
          )
        ) : (
          <FlatList
            data={comments}
            keyExtractor={(c) => String(c.id)}
            ListHeaderComponent={header}
            contentContainerStyle={styles.list}
            ItemSeparatorComponent={() => <View style={styles.separator} />}
            keyboardShouldPersistTaps="handled"
            keyboardDismissMode="interactive"
            refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.accentText} />}
            onEndReached={more}
            onEndReachedThreshold={0.4}
            ListEmptyComponent={
              <View style={styles.emptyBox}>
                <TablerIcon name="message-circle" size={28} color={colors.text3} />
                <Text style={styles.empty}>{enabled ? t('m.b.comments.empty') : t('m.b.comments.none')}</Text>
              </View>
            }
            renderItem={({ item }) => (
              <CommentItem
                comment={item}
                canReply={enabled && !!viewer?.signed_in}
                onLove={love}
                onReply={reply}
                onMore={(c, isReply) => setMenu({ c, isReply })}
              />
            )}
          />
        )}
        <View style={[styles.footer, { paddingBottom: keyboardOpen ? 8 : Math.max(insets.bottom, 10) }]}>{composer()}</View>
      </KeyboardAvoidingView>

      <Sheet visible={!!menu} title={t('m.b.comments.comment')} onClose={() => setMenu(null)}>
        {menu && (
          <View style={styles.menu}>
            <MenuItem
              icon="copy"
              label={t('m.b.comments.copy')}
              onPress={() => {
                Clipboard.setStringAsync(menu.c.content).catch(() => undefined);
                setMenu(null);
                toast.show(t('m.b.comments.copied'));
              }}
            />
            {viewer?.can_moderate && !menu.isReply && !menu.c.held && (
              <MenuItem icon="pin" label={menu.c.pinned ? t('m.b.comments.unpin') : t('m.b.comments.pin')} onPress={() => { const c = menu.c; setMenu(null); moderate(c, c.pinned ? 'unpin' : 'pin'); }} />
            )}
            {viewer?.can_moderate && !menu.c.is_mine && (
              <MenuItem icon="eye-off" label={t('m.b.comments.hide_title')} onPress={() => { const c = menu.c; setMenu(null); moderate(c, 'hide'); }} />
            )}
            {!menu.c.is_mine && !menu.c.held && (
              <MenuItem icon="flag" label={t('m.b.comments.report')} onPress={() => { setReporting(menu.c); setReason(REPORT_REASONS[0].key); setMenu(null); }} />
            )}
            {menu.c.is_mine && <MenuItem icon="trash" label={t('m.b.comments.delete_mine')} danger onPress={() => { const c = menu.c; setMenu(null); remove(c); }} />}
          </View>
        )}
      </Sheet>

      <Sheet visible={!!reporting} title={t('m.b.comments.report_title')} onClose={() => setReporting(null)}>
        <Text style={styles.sheetText}>{t('m.b.comments.report_body')}</Text>
        {REPORT_REASONS.map((r) => (
          <Pressable key={r.key} onPress={() => setReason(r.key)} accessibilityRole="radio" accessibilityState={{ checked: reason === r.key }} style={[styles.option, reason === r.key && styles.optionOn]}>
            <TablerIcon name={reason === r.key ? 'circle-check-filled' : 'circle'} size={20} color={reason === r.key ? colors.accentText : colors.text3} />
            <Text style={styles.optionText}>{reasonLabel(r.key, r.label)}</Text>
          </Pressable>
        ))}
        <TextInput
          value={reportNote}
          onChangeText={setReportNote}
          placeholder={t('m.b.comments.report_note')}
          placeholderTextColor={colors.text3}
          multiline
          maxLength={500}
          style={styles.noteInput}
        />
        <Button title={t('m.b.comments.send_report')} onPress={sendReport} />
      </Sheet>

      <Sheet visible={naming} title={t('m.b.comments.name_title')} onClose={() => setNaming(false)}>
        <Text style={styles.sheetText}>{t('m.b.comments.name_body')}</Text>
        <Pressable onPress={() => setNameMode('first_name')} accessibilityRole="radio" accessibilityState={{ checked: nameMode === 'first_name' }} style={[styles.option, nameMode === 'first_name' && styles.optionOn]}>
          <TablerIcon name={nameMode === 'first_name' ? 'circle-check-filled' : 'circle'} size={20} color={nameMode === 'first_name' ? colors.accentText : colors.text3} />
          <Text style={styles.optionText}>{t('m.b.comments.first_name', { name: viewer?.identity?.first_name ?? t('m.b.comments.member') })}</Text>
        </Pressable>
        <Pressable onPress={() => setNameMode('nickname')} accessibilityRole="radio" accessibilityState={{ checked: nameMode === 'nickname' }} style={[styles.option, nameMode === 'nickname' && styles.optionOn]}>
          <TablerIcon name={nameMode === 'nickname' ? 'circle-check-filled' : 'circle'} size={20} color={nameMode === 'nickname' ? colors.accentText : colors.text3} />
          <Text style={styles.optionText}>{t('m.b.comments.nickname')}</Text>
        </Pressable>
        {nameMode === 'nickname' && (
          <TextInput
            value={nickname}
            onChangeText={setNickname}
            placeholder={t('m.b.comments.nick_example')}
            placeholderTextColor={colors.text3}
            maxLength={24}
            autoFocus
            style={styles.nickInput}
            accessibilityLabel={t('m.b.comments.nickname_label')}
          />
        )}
        {nameError ? <Text style={styles.error}>{nameError}</Text> : null}
        <Button title={t('m.b.comments.save')} onPress={saveName} busy={savingName} style={{ marginTop: 8 }} />
      </Sheet>

      <Toast note={toast.note} />
    </SafeAreaView>
  );
}

function MenuItem({ icon, label, onPress, danger }: { icon: string; label: string; onPress: () => void; danger?: boolean }) {
  const styles = useStyles();
  const colors = useColors();
  return (
    <Pressable onPress={onPress} accessibilityRole="button" style={({ pressed }) => [styles.menuItem, pressed && { backgroundColor: colors.surface2 }]}>
      <TablerIcon name={icon} size={20} color={danger ? colors.rose : colors.text2} />
      <Text style={[styles.menuText, danger && { color: colors.rose }]}>{label}</Text>
    </Pressable>
  );
}

const useStyles = makeStyles((colors) => ({
  safe: { flex: 1, backgroundColor: colors.bg },
  flex: { flex: 1 },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 16, padding: 24 },
  list: { paddingHorizontal: 16, paddingBottom: 24 },
  separator: { height: 1, backgroundColor: colors.border },
  headerBlock: { paddingTop: 4, paddingBottom: 6 },
  articleTitle: { fontFamily: fonts.serifMedium, fontSize: 22, lineHeight: 27, color: colors.text, marginBottom: 10 },
  note: { flexDirection: 'row', gap: 8, alignItems: 'flex-start', paddingBottom: 8 },
  noteText: { flex: 1, fontFamily: fonts.regular, fontSize: 13, lineHeight: 19, color: colors.text2 },
  care: { backgroundColor: colors.sageLight, borderRadius: radius.r, padding: 16, marginVertical: 8 },
  careTitle: { fontFamily: fonts.semibold, fontSize: 16, color: colors.text, marginBottom: 4 },
  careText: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 21, color: colors.text },
  emptyBox: { alignItems: 'center', gap: 10, paddingVertical: 40, paddingHorizontal: 24 },
  empty: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 22, color: colors.text2, textAlign: 'center' },
  footer: { borderTopWidth: 1, borderTopColor: colors.border, backgroundColor: colors.surface, paddingHorizontal: 12, paddingTop: 10 },
  closed: { fontFamily: fonts.regular, fontSize: 14, color: colors.text3, textAlign: 'center', paddingVertical: 8 },
  joinBtn: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, backgroundColor: colors.accent, borderRadius: radius.pill, minHeight: 46 },
  joinText: { fontFamily: fonts.medium, fontSize: 15, color: '#fff' },
  replying: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 8, paddingHorizontal: 6, paddingBottom: 8 },
  replyingText: { flex: 1, fontFamily: fonts.medium, fontSize: 13, color: colors.text2 },
  inputRow: { flexDirection: 'row', alignItems: 'flex-end', gap: 8 },
  input: {
    flex: 1,
    minHeight: 44,
    maxHeight: 140,
    borderRadius: 22,
    borderWidth: 1.5,
    borderColor: colors.border,
    backgroundColor: colors.bg,
    paddingHorizontal: 16,
    paddingTop: 11,
    paddingBottom: 11,
    fontFamily: fonts.regular,
    fontSize: 15,
    color: colors.text,
  },
  send: { width: 44, height: 44, borderRadius: 22, backgroundColor: colors.accent, alignItems: 'center', justifyContent: 'center' },
  underInput: { flexDirection: 'row', justifyContent: 'space-between', paddingHorizontal: 8, paddingTop: 6 },
  postingAs: { fontFamily: fonts.regular, fontSize: 12, color: colors.text3 },
  link: { color: colors.accentText, textDecorationLine: 'underline' },
  menu: { paddingBottom: 6 },
  menuItem: { flexDirection: 'row', alignItems: 'center', gap: 14, paddingVertical: 14, paddingHorizontal: 6, borderRadius: 12 },
  menuText: { fontFamily: fonts.regular, fontSize: 16, color: colors.text },
  sheetText: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 21, color: colors.text2, marginBottom: 12 },
  option: { flexDirection: 'row', alignItems: 'center', gap: 12, padding: 14, borderRadius: 14, borderWidth: 1.5, borderColor: colors.border, marginBottom: 8 },
  optionOn: { borderColor: colors.accentText, backgroundColor: colors.accentLight },
  optionText: { flex: 1, fontFamily: fonts.regular, fontSize: 15, color: colors.text },
  noteInput: {
    minHeight: 70,
    borderRadius: 14,
    borderWidth: 1.5,
    borderColor: colors.border,
    padding: 12,
    fontFamily: fonts.regular,
    fontSize: 15,
    color: colors.text,
    marginVertical: 8,
    textAlignVertical: 'top',
  },
  nickInput: {
    height: 48,
    borderRadius: 14,
    borderWidth: 1.5,
    borderColor: colors.border,
    paddingHorizontal: 14,
    fontFamily: fonts.regular,
    fontSize: 16,
    color: colors.text,
    marginBottom: 8,
  },
  error: { fontFamily: fonts.regular, fontSize: 13, color: colors.rose, marginBottom: 6 },
}));

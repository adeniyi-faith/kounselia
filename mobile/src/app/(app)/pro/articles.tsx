import { deleteArticle, withdrawArticle, type ProArticle } from '@kounselia/core';
import { Image } from 'expo-image';
import { router } from 'expo-router';
import { useState } from 'react';
import { RefreshControl, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useBrowser } from '@/browser/BrowserProvider';
import { Button } from '@/components/Button';
import { Toast, useToast } from '@/components/chat/Toast';
import { EmptyState } from '@/components/dashboard/Card';
import { sessionWhen } from '@/components/dashboard/when';
import { showDialog } from '@/components/Dialog';
import { ListSkeleton } from '@/components/Skeleton';
import { TablerIcon } from '@/components/TablerIcon';
import { Pill, SmallButton, StatGrid } from '@/components/pro/ui';
import { SITE_URL } from '@/config';
import { usePro } from '@/professional/ProDashboard';
import { useSession } from '@/session';
import { fonts, makeStyles, radius, shadows, useColors } from '@/theme';

const STATE_TONES: Record<string, string> = {
  live: 'sage',
  pending: 'gold',
  live_pending: 'gold',
  changes: 'rose',
  rejected: 'rose',
  removed: 'rose',
  scheduled: 'blue',
};

// Articles (the website's pro dashboard "Articles"): what they've written
// for the Journal, how it's doing, the editor's notes, and the writing
// page itself. Writing uses the website's own editor (pro-write.php),
// which is built for a phone, inside the app: it already signs in, keeps
// unsaved work on the phone, and brings them back here when they send.
export default function ProArticles() {
  const styles = useStyles();
  const colors = useColors();
  const { config } = useSession();
  const { data, failed, reload } = usePro();
  const { openInApp } = useBrowser();
  const [refreshing, setRefreshing] = useState(false);
  const toast = useToast();

  async function refresh() {
    setRefreshing(true);
    await reload();
    setRefreshing(false);
  }

  async function write(id?: number) {
    await openInApp(`${SITE_URL}/pro-write.php${id ? `?id=${id}` : ''}`, {
      title: id ? 'Edit article' : 'Write an article',
      // Sending (or deleting) takes the writer back to the dashboard page;
      // here that means back to this screen.
      closeWhen: (url) => url.includes('/pro-dashboard.php'),
    });
    reload();
  }

  function view(a: ProArticle) {
    if (a.live) router.push({ pathname: '/articles/[slug]', params: { slug: a.slug } });
    // Not on the Journal yet: the website shows its author a preview.
    else openInApp(a.url, { title: 'Preview', web: true });
  }

  function act(kind: 'withdraw' | 'delete', a: ProArticle) {
    const question =
      kind === 'delete'
        ? 'Delete this article for good? Its comments and loves are deleted too. This cannot be undone.'
        : a.in_review
          ? 'Take this back from the review queue? You can send it again any time.'
          : 'Unpublish this article? It will leave the Journal and go back to your drafts. Loves and comments are kept.';
    showDialog({
      title: kind === 'delete' ? 'Delete article?' : a.in_review ? 'Withdraw from review?' : 'Unpublish?',
      message: question,
      icon: kind === 'delete' ? 'trash' : 'arrow-back-up',
      buttons: [
        { text: 'Keep it', style: 'cancel' },
        {
          text: kind === 'delete' ? 'Delete' : a.in_review ? 'Withdraw' : 'Unpublish',
          style: 'destructive',
          onPress: async () => {
            const res = kind === 'delete' ? await deleteArticle(config, a.id) : await withdrawArticle(config, a.id);
            toast.show(res.ok ? res.data.message || 'Done.' : res.message);
            reload();
          },
        },
      ],
    });
  }

  const articles = data?.articles;
  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.accentText} />}
      >
        <Text style={styles.title} accessibilityRole="header">
          Your articles
        </Text>
        {!articles ? (
          failed ? (
            <View style={styles.center}>
              <Text style={styles.notice}>We couldn’t load your articles. Please check your internet connection.</Text>
              <Button title="Try again" variant="ghost" onPress={refresh} busy={refreshing} />
            </View>
          ) : (
            <View style={{ marginTop: 16 }}>
              <ListSkeleton rows={3} square />
            </View>
          )
        ) : (
          <>
            {articles.access.allowed && <Button title="Write an article" onPress={() => write()} style={{ marginTop: 16 }} />}
            <View style={styles.mode}>
              <TablerIcon
                name={!articles.access.allowed ? 'info-circle' : articles.access.mode === 'trusted' ? 'discount-check' : 'eye-check'}
                size={18}
                color={colors.accentText}
              />
              <Text style={styles.modeText}>
                {!articles.access.allowed
                  ? articles.access.message
                  : articles.access.mode === 'trusted'
                    ? 'You’re a trusted writer. Your articles go live on the Journal as soon as you publish them.'
                    : 'An editor reads every article before it goes live, usually within two working days. You’ll get a notification and an email either way.'}
              </Text>
            </View>

            <StatGrid
              items={[
                { icon: 'users', color: 'blue', num: articles.followers.toLocaleString(), label: articles.followers === 1 ? 'Follower' : 'Followers' },
                { icon: 'eye', color: 'sage', num: articles.totals.views.toLocaleString(), label: 'Reads' },
                { icon: 'heart', color: 'rose', num: articles.totals.loves.toLocaleString(), label: 'Loves' },
                { icon: 'message-circle', color: 'gold', num: articles.totals.comments.toLocaleString(), label: 'Comments' },
              ]}
            />

            {articles.items.length === 0 ? (
              <View style={{ marginTop: 20 }}>
                <EmptyState>
                  <TablerIcon name="feather" size={28} color={colors.accentText} />
                  <Text style={styles.emptyTitle}>Share what you know</Text>
                  <Text style={styles.notice}>
                    Articles help people understand what they’re going through, and help them find you. Readers can follow you and hear whenever you publish.
                  </Text>
                </EmptyState>
              </View>
            ) : (
              <View style={{ marginTop: 20, gap: 12 }}>
                {articles.items.map((a) => (
                  <View key={a.id} style={styles.card}>
                    {a.cover ? (
                      <Image source={{ uri: a.cover }} style={styles.cover} contentFit="cover" transition={200} />
                    ) : null}
                    <Text style={styles.cardTitle}>{a.title || 'Untitled'}</Text>
                    <View style={styles.meta}>
                      <Pill label={a.state_label} tone={STATE_TONES[a.state] ?? 'navy'} />
                      <Text style={styles.metaText}>{sessionWhen(a.date_utc, true)}</Text>
                    </View>
                    {a.live && (
                      <View style={styles.meta}>
                        <Stat icon="eye" num={a.views} label="Reads" />
                        <Stat icon="heart" num={a.loves} label="Loves" />
                        <Stat icon="message-circle" num={a.comments} label="Comments" />
                      </View>
                    )}
                    {a.review_note ? (
                      <View style={styles.editorNote}>
                        <Text style={styles.editorNoteText}>
                          <Text style={{ fontFamily: fonts.semibold, color: colors.text }}>Note from the editor: </Text>
                          {a.review_note}
                        </Text>
                      </View>
                    ) : null}
                    <View style={styles.actions}>
                      {articles.access.allowed && <SmallButton icon="pencil" label="Edit" onPress={() => write(a.id)} />}
                      <SmallButton icon="eye" label={a.live ? 'View' : 'Preview'} onPress={() => view(a)} />
                      {a.in_review ? (
                        <SmallButton icon="arrow-back-up" label="Withdraw" onPress={() => act('withdraw', a)} />
                      ) : a.live ? (
                        <SmallButton icon="eye-off" label="Unpublish" onPress={() => act('withdraw', a)} />
                      ) : null}
                      <SmallButton icon="trash" label="Delete" tone="danger" onPress={() => act('delete', a)} />
                    </View>
                  </View>
                ))}
              </View>
            )}
          </>
        )}
      </ScrollView>
      <Toast note={toast.note} />
    </SafeAreaView>
  );
}

function Stat({ icon, num, label }: { icon: string; num: number; label: string }) {
  const styles = useStyles();
  const colors = useColors();
  return (
    <View style={styles.stat} accessible accessibilityLabel={`${num} ${label}`}>
      <TablerIcon name={icon} size={14} color={colors.text3} />
      <Text style={styles.metaText}>{num.toLocaleString()}</Text>
    </View>
  );
}

const useStyles = makeStyles((colors) => ({
  safe: { flex: 1, backgroundColor: colors.bg },
  content: { padding: 16, paddingBottom: 40 },
  title: { fontFamily: fonts.serifMedium, fontSize: 28, color: colors.text, marginTop: 8 },
  center: { marginTop: 24, gap: 16 },
  notice: { fontFamily: fonts.regular, fontSize: 14.5, lineHeight: 22, color: colors.text2, textAlign: 'center' },
  emptyTitle: { fontFamily: fonts.serifMedium, fontSize: 21, color: colors.text },
  mode: {
    flexDirection: 'row',
    gap: 10,
    alignItems: 'flex-start',
    marginTop: 16,
    padding: 14,
    borderRadius: 14,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
  },
  modeText: { flex: 1, fontFamily: fonts.regular, fontSize: 13.5, lineHeight: 20, color: colors.text2 },
  card: { padding: 14, borderRadius: radius.sm, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, ...shadows.soft },
  cover: { width: '100%', height: 140, borderRadius: 12, marginBottom: 12, backgroundColor: colors.surface2 },
  cardTitle: { fontFamily: fonts.semibold, fontSize: 15.5, lineHeight: 21, color: colors.text },
  meta: { flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', gap: 12, marginTop: 8 },
  metaText: { fontFamily: fonts.regular, fontSize: 12.5, color: colors.text3 },
  stat: { flexDirection: 'row', alignItems: 'center', gap: 4 },
  editorNote: { marginTop: 10, padding: 12, borderRadius: 10, backgroundColor: colors.bg },
  editorNoteText: { fontFamily: fonts.regular, fontSize: 13, lineHeight: 19, color: colors.text2 },
  actions: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 12 },
}));

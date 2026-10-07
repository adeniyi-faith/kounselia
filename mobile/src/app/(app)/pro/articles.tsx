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
import { useLanguage } from '@/language';
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
  const { language, t } = useLanguage();
  const [refreshing, setRefreshing] = useState(false);
  const toast = useToast();

  async function refresh() {
    setRefreshing(true);
    await reload();
    setRefreshing(false);
  }

  async function write(id?: number) {
    await openInApp(`${SITE_URL}/pro-write.php${id ? `?id=${id}` : ''}`, {
      title: id ? t('m.pro.edit_article') : t('m.pro.write_article'),
      // Sending (or deleting) takes the writer back to the dashboard page;
      // here that means back to this screen.
      closeWhen: (url) => url.includes('/pro-dashboard.php'),
    });
    reload();
  }

  function view(a: ProArticle) {
    if (a.live) router.push({ pathname: '/articles/[slug]', params: { slug: a.slug } });
    // Not on the Journal yet: the website shows its author a preview.
    else openInApp(a.url, { title: t('m.pro.preview'), web: true });
  }

  function act(kind: 'withdraw' | 'delete', a: ProArticle) {
    const question =
      kind === 'delete'
        ? t('m.pro.article_delete_body')
        : a.in_review
          ? t('m.pro.article_withdraw_body')
          : t('m.pro.article_unpublish_body');
    showDialog({
      title: kind === 'delete' ? t('m.pro.article_delete_q') : a.in_review ? t('m.pro.article_withdraw_q') : t('m.pro.article_unpublish_q'),
      message: question,
      icon: kind === 'delete' ? 'trash' : 'arrow-back-up',
      buttons: [
        { text: t('m.pro.keep_it'), style: 'cancel' },
        {
          text: kind === 'delete' ? t('m.pro.delete') : a.in_review ? t('m.pro.withdraw') : t('m.pro.unpublish'),
          style: 'destructive',
          onPress: async () => {
            const res = kind === 'delete' ? await deleteArticle(config, a.id) : await withdrawArticle(config, a.id);
            toast.show(res.ok ? res.data.message || t('m.pro.done') : res.message);
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
          {t('m.pro.your_articles')}
        </Text>
        {!articles ? (
          failed ? (
            <View style={styles.center}>
              <Text style={styles.notice}>{t('m.pro.articles_load_failed')}</Text>
              <Button title={t('m.pro.try_again')} variant="ghost" onPress={refresh} busy={refreshing} />
            </View>
          ) : (
            <View style={{ marginTop: 16 }}>
              <ListSkeleton rows={3} square />
            </View>
          )
        ) : (
          <>
            {articles.access.allowed && <Button title={t('m.pro.write_article')} onPress={() => write()} style={{ marginTop: 16 }} />}
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
                    ? t('m.pro.articles_trusted')
                    : t('m.pro.articles_reviewed')}
              </Text>
            </View>

            <StatGrid
              items={[
                { icon: 'users', color: 'blue', num: articles.followers.toLocaleString(), label: articles.followers === 1 ? t('m.pro.follower') : t('m.pro.followers') },
                { icon: 'eye', color: 'sage', num: articles.totals.views.toLocaleString(), label: t('m.pro.reads') },
                { icon: 'heart', color: 'rose', num: articles.totals.loves.toLocaleString(), label: t('m.pro.loves') },
                { icon: 'message-circle', color: 'gold', num: articles.totals.comments.toLocaleString(), label: t('m.pro.comments') },
              ]}
            />

            {articles.items.length === 0 ? (
              <View style={{ marginTop: 20 }}>
                <EmptyState>
                  <TablerIcon name="feather" size={28} color={colors.accentText} />
                  <Text style={styles.emptyTitle}>{t('m.pro.articles_empty_title')}</Text>
                  <Text style={styles.notice}>{t('m.pro.articles_empty_body')}</Text>
                </EmptyState>
              </View>
            ) : (
              <View style={{ marginTop: 20, gap: 12 }}>
                {articles.items.map((a) => (
                  <View key={a.id} style={styles.card}>
                    {a.cover ? (
                      <Image source={{ uri: a.cover }} style={styles.cover} contentFit="cover" transition={200} />
                    ) : null}
                    <Text style={styles.cardTitle}>{a.title || t('m.pro.untitled')}</Text>
                    <View style={styles.meta}>
                      <Pill label={a.state_label} tone={STATE_TONES[a.state] ?? 'navy'} />
                      <Text style={styles.metaText}>{sessionWhen(a.date_utc, true, language)}</Text>
                    </View>
                    {a.live && (
                      <View style={styles.meta}>
                        <Stat icon="eye" num={a.views} label={t('m.pro.reads')} />
                        <Stat icon="heart" num={a.loves} label={t('m.pro.loves')} />
                        <Stat icon="message-circle" num={a.comments} label={t('m.pro.comments')} />
                      </View>
                    )}
                    {a.review_note ? (
                      <View style={styles.editorNote}>
                        <Text style={styles.editorNoteText}>
                          <Text style={{ fontFamily: fonts.semibold, color: colors.text }}>{t('m.pro.editor_note')} </Text>
                          {a.review_note}
                        </Text>
                      </View>
                    ) : null}
                    <View style={styles.actions}>
                      {articles.access.allowed && <SmallButton icon="pencil" label={t('m.pro.edit')} onPress={() => write(a.id)} />}
                      <SmallButton icon="eye" label={a.live ? t('m.pro.view') : t('m.pro.preview')} onPress={() => view(a)} />
                      {a.in_review ? (
                        <SmallButton icon="arrow-back-up" label={t('m.pro.withdraw')} onPress={() => act('withdraw', a)} />
                      ) : a.live ? (
                        <SmallButton icon="eye-off" label={t('m.pro.unpublish')} onPress={() => act('withdraw', a)} />
                      ) : null}
                      <SmallButton icon="trash" label={t('m.pro.delete')} tone="danger" onPress={() => act('delete', a)} />
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

// Reading one article. Links inside it open in the in-app browser, except
// links to other Kounselia articles, which open right here in the reader.
// Underneath: love it, open the conversation, and follow or book the
// professional who wrote it.
import { fetchBlogPost, type BlogPost } from '@kounselia/core';
import { useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { Platform, Share, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useBrowser } from '@/browser/BrowserProvider';
import { WebFrame } from '@/browser/WebFrame';
import { ArticleActions } from '@/components/articles/ArticleActions';
import { articleHtml } from '@/components/articles/articleHtml';
import { Toast, useToast } from '@/components/chat/Toast';
import { Button } from '@/components/Button';
import { HeaderButton, ScreenHeader } from '@/components/ScreenHeader';
import { SITE_URL } from '@/config';
import { useLanguage } from '@/language';
import { useSession } from '@/session';
import { fonts, makeStyles, useColors, useTheme } from '@/theme';
import { ArticleSkeleton } from '@/components/Skeleton';

export default function Article() {
  const styles = useStyles();
  const colors = useColors();
  const { language, t } = useLanguage();
  const { scheme } = useTheme();
  const { slug } = useLocalSearchParams<{ slug: string }>();
  const { config } = useSession();
  const { openInApp } = useBrowser();
  const [post, setPost] = useState<BlogPost | null>(null);
  const [failed, setFailed] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const toast = useToast();

  const load = useCallback(async () => {
    setBusy(true);
    const res = await fetchBlogPost(config, slug);
    setBusy(false);
    if (res.ok) {
      setPost(res.data);
      setFailed(null);
    } else {
      setFailed(res.offline ? t('m.b.articles.load_one_failed') : res.message);
    }
  }, [config, slug, t]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
  }, [load]);

  const baseUrl = `${SITE_URL}/blog/${slug}`;
  const html = useMemo(() => (post ? articleHtml(post, colors, scheme === 'dark', t, language) : ''), [post, colors, scheme, t, language]);

  function shouldLoad(url: string, isTopFrame: boolean) {
    // The article itself, jumps within it, and videos embedded in it.
    if (!isTopFrame || url === baseUrl || url.startsWith(`${baseUrl}#`) || /^(about|data):/.test(url)) return true;
    // Another article, or a professional's profile, opens its own native
    // screen instead of the browser — openInApp knows how to tell (see
    // nativeRoute in BrowserProvider).
    openInApp(url);
    return false;
  }

  function share() {
    if (!post) return;
    Share.share(Platform.OS === 'ios' ? { url: post.url, message: post.title } : { message: `${post.title}\n${post.url}` }).catch(() => undefined);
  }

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      <ScreenHeader title={post?.tags[0]?.name ?? t('m.b.articles.article')} right={post ? <HeaderButton icon="share" label={t('m.b.articles.share')} onPress={share} /> : null} />
      {post ? (
        <>
          <WebFrame source={{ html, baseUrl }} style={styles.web} shouldLoad={shouldLoad} />
          <ArticleActions key={post.id} post={post} onNotify={toast.show} />
        </>
      ) : failed ? (
        <View style={styles.center}>
          <Text style={styles.notice}>{failed}</Text>
          <Button title={t('m.b.common.try_again')} variant="ghost" onPress={load} busy={busy} />
        </View>
      ) : (
        <ArticleSkeleton />
      )}
      <Toast note={toast.note} />
    </SafeAreaView>
  );
}

const useStyles = makeStyles((colors) => ({
  safe: { flex: 1, backgroundColor: colors.bg },
  web: { flex: 1, backgroundColor: colors.bg },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 16, padding: 24 },
  notice: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 22, color: colors.text2, textAlign: 'center' },
}));

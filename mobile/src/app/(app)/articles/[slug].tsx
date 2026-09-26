// Reading one article. Links inside it open in the in-app browser, except
// links to other Kounselia articles, which open right here in the reader.
import { fetchBlogPost, type BlogPost } from '@kounselia/core';
import { router, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { Platform, Share, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useBrowser } from '@/browser/BrowserProvider';
import { WebFrame } from '@/browser/WebFrame';
import { articleHtml } from '@/components/articles/articleHtml';
import { Button } from '@/components/Button';
import { HeaderButton, ScreenHeader } from '@/components/ScreenHeader';
import { SITE_URL } from '@/config';
import { useSession } from '@/session';
import { fonts, makeStyles, useColors, useTheme } from '@/theme';
import { ArticleSkeleton } from '@/components/Skeleton';

const ARTICLE_LINK = new RegExp(`^${SITE_URL.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&')}/blog/([a-z0-9-]+)/?(?:[?#].*)?$`, 'i');
const NOT_ARTICLES = ['tag', 'tags', 'feed', 'rss', 'search', 'page', 'author'];

export default function Article() {
  const styles = useStyles();
  const colors = useColors();
  const { scheme } = useTheme();
  const { slug } = useLocalSearchParams<{ slug: string }>();
  const { config } = useSession();
  const { openInApp } = useBrowser();
  const [post, setPost] = useState<BlogPost | null>(null);
  const [failed, setFailed] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    setBusy(true);
    const res = await fetchBlogPost(config, slug);
    setBusy(false);
    if (res.ok) {
      setPost(res.data);
      setFailed(null);
    } else {
      setFailed(res.offline ? "We couldn't load this article. Please check your internet connection." : res.message);
    }
  }, [config, slug]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
  }, [load]);

  const baseUrl = `${SITE_URL}/blog/${slug}`;
  const html = useMemo(() => (post ? articleHtml(post, colors, scheme === 'dark') : ''), [post, colors, scheme]);

  function shouldLoad(url: string, isTopFrame: boolean) {
    // The article itself, jumps within it, and videos embedded in it.
    if (!isTopFrame || url === baseUrl || url.startsWith(`${baseUrl}#`) || /^(about|data):/.test(url)) return true;
    const m = ARTICLE_LINK.exec(url);
    if (m && !NOT_ARTICLES.includes(m[1])) router.push({ pathname: '/articles/[slug]', params: { slug: m[1] } });
    else openInApp(url);
    return false;
  }

  function share() {
    if (!post) return;
    Share.share(Platform.OS === 'ios' ? { url: post.url, message: post.title } : { message: `${post.title}\n${post.url}` }).catch(() => undefined);
  }

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      <ScreenHeader title={post?.tags[0]?.name ?? 'Article'} right={post ? <HeaderButton icon="share" label="Share article" onPress={share} /> : null} />
      {post ? (
        <WebFrame source={{ html, baseUrl }} style={styles.web} shouldLoad={shouldLoad} />
      ) : failed ? (
        <View style={styles.center}>
          <Text style={styles.notice}>{failed}</Text>
          <Button title="Try again" variant="ghost" onPress={load} busy={busy} />
        </View>
      ) : (
        <ArticleSkeleton />
      )}
    </SafeAreaView>
  );
}

const useStyles = makeStyles((colors) => ({
  safe: { flex: 1, backgroundColor: colors.bg },
  web: { flex: 1, backgroundColor: colors.bg },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 16, padding: 24 },
  notice: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 22, color: colors.text2, textAlign: 'center' },
}));

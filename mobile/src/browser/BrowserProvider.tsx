// The in-app browser: web pages (safety resources, help pages, payment,
// links in articles) open in a full-screen sheet inside Kounselia instead
// of sending the member off to another app. Close it with ✕, or with the
// phone's back button once there's no page to go back to.
//
// Anywhere in the app:  const { openInApp } = useBrowser();  openInApp(url)
//
// The one exception is a video session: its video room (Jitsi) only works
// properly in the phone's own browser, so joinSession() keeps using that.
import { fetchWebSsoCode, type KounseliaConfig } from '@kounselia/core';
import * as Clipboard from 'expo-clipboard';
import * as Haptics from 'expo-haptics';
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { Linking, Modal, Platform, Pressable, Share, Text, View } from 'react-native';
import { SafeAreaProvider, SafeAreaView } from 'react-native-safe-area-context';
import { TablerIcon } from '@/components/TablerIcon';
import { fonts, makeStyles, useColors } from '@/theme';
import { PageSkeleton } from '@/components/Skeleton';
import { SITE_URL } from '@/config';
import { useSession } from '@/session';
import { WebFrame, type NavState, type WebFrameHandle } from './WebFrame';

interface OpenOptions {
  // Shown until the page has loaded its own title.
  title?: string;
  // Closes the browser by itself once a page matching this has loaded
  // (e.g. the "payment received" page Paystack sends people back to).
  closeWhen?: (url: string) => boolean;
}

interface Browser {
  // Resolves when the member closes it (or it closes itself).
  openInApp(url: string, options?: OpenOptions): Promise<void>;
}

const BrowserContext = createContext<Browser | null>(null);

// For plain functions outside React components (e.g. openSafetyResources).
let mounted: Browser | null = null;
export function openInAppBrowser(url: string, options?: OpenOptions): Promise<void> {
  if (mounted) return mounted.openInApp(url, options);
  return Linking.openURL(url).catch(() => undefined);
}

// Links a web page can't show itself: email, phone calls, WhatsApp, app stores.
function isExternal(url: string) {
  return !/^(https?|about|data|blob):/i.test(url);
}

export function hostOf(url: string) {
  const m = /^https?:\/\/([^/?#]+)/i.exec(url);
  return m ? m[1].replace(/^www\./, '') : '';
}

function pathOf(url: string): string {
  const m = /^https?:\/\/[^/?#]+(.*)$/i.exec(url);
  return m && m[1] ? m[1] : '/';
}

// The app is signed in with its own token (see appAuth.ts); the WebView
// this opens has never signed in on the website itself and has no way to
// carry that token, so a page that needs a member signed in would always
// show the website's own sign-in screen. For a page on our own site,
// while the member is signed in, this trades a one-time code for a link
// that signs the browser in too before landing on the page — see
// app-sso.php. Left unchanged for anything else (a payment page on
// Paystack's own site, or when signed out — there's nothing to carry
// over then).
async function resolveUrl(config: KounseliaConfig, url: string): Promise<string> {
  if (config.client !== 'app' || !config.loggedIn || hostOf(url) !== hostOf(SITE_URL)) return url;
  const code = await fetchWebSsoCode(config);
  return code ? `${SITE_URL}/app-sso.php?code=${encodeURIComponent(code)}&to=${encodeURIComponent(pathOf(url))}` : url;
}

export function BrowserProvider({ children }: { children: ReactNode }) {
  const { config } = useSession();
  const [page, setPage] = useState<{ url: string; options: OpenOptions; key: number } | null>(null);
  const resolver = useRef<(() => void) | null>(null);

  const close = useCallback(() => {
    setPage(null);
    resolver.current?.();
    resolver.current = null;
  }, []);

  const openInApp = useCallback(
    (url: string, options: OpenOptions = {}) => {
      if (isExternal(url)) {
        Linking.openURL(url).catch(() => undefined);
        return Promise.resolve();
      }
      resolver.current?.(); // Only one at a time.
      return new Promise<void>((resolve) => {
        resolver.current = resolve;
        setPage({ url, options, key: Date.now() });
      });
    },
    [],
  );

  const value = useMemo(() => ({ openInApp }), [openInApp]);
  useEffect(() => {
    mounted = value;
    return () => {
      mounted = null;
    };
  }, [value]);
  const back = useRef<() => boolean>(() => false);

  return (
    <BrowserContext.Provider value={value}>
      {children}
      <Modal
        visible={!!page}
        animationType="slide"
        presentationStyle="fullScreen"
        onRequestClose={() => {
          // Android back button: go back a page, or close.
          if (!back.current()) close();
        }}
      >
        {/* A Modal is its own window, so it needs its own safe-area measurements. */}
        <SafeAreaProvider>
          {page && <InAppBrowser key={page.key} url={page.url} config={config} options={page.options} onClose={close} backRef={back} />}
        </SafeAreaProvider>
      </Modal>
    </BrowserContext.Provider>
  );
}

export function useBrowser() {
  const ctx = useContext(BrowserContext);
  if (!ctx) throw new Error('useBrowser must be used inside <BrowserProvider>');
  return ctx;
}

function InAppBrowser({
  url,
  config,
  options,
  onClose,
  backRef,
}: {
  url: string;
  config: KounseliaConfig;
  options: OpenOptions;
  onClose: () => void;
  backRef: { current: () => boolean };
}) {
  const styles = useStyles();
  const colors = useColors();
  const frame = useRef<WebFrameHandle | null>(null);
  const [nav, setNav] = useState<NavState>({ url, title: '', canGoBack: false, canGoForward: false });
  const [progress, setProgress] = useState(0);
  const [failed, setFailed] = useState(false);
  const [copied, setCopied] = useState(false);
  const closing = useRef(false);
  // Resolved before the page is actually requested, so a signed-in member
  // never briefly sees the website's own sign-in page flash up first.
  const [target, setTarget] = useState<string | null>(null);
  useEffect(() => {
    let cancelled = false;
    resolveUrl(config, url).then((resolved) => {
      if (!cancelled) setTarget(resolved);
    });
    return () => {
      cancelled = true;
    };
  }, [config, url]);

  useEffect(() => {
    backRef.current = () => {
      if (nav.canGoBack) {
        frame.current?.goBack();
        return true;
      }
      return false;
    };
  }, [backRef, nav.canGoBack]);

  const secure = nav.url.startsWith('https://');
  // Website pages end their titles with the site name ("Safety resources — Kounselia");
  // the address under the title already says where the page is from.
  const pageTitle = nav.title.replace(/\s+[—–|-]\s+Kounselia\s*$/i, '').trim();
  const title = pageTitle && !/^https?:\/\//.test(pageTitle) ? pageTitle : options.title || hostOf(nav.url) || 'Loading…';

  function onLoaded(loadedUrl: string) {
    setProgress(1);
    if (!closing.current && options.closeWhen?.(loadedUrl)) {
      closing.current = true;
      // Leave their "payment received" page up for a moment so it's seen.
      setTimeout(onClose, 1500);
    }
  }

  async function copy() {
    await Clipboard.setStringAsync(nav.url);
    Haptics.selectionAsync().catch(() => undefined);
    setCopied(true);
    setTimeout(() => setCopied(false), 1500);
  }

  return (
    <View style={styles.wrap}>
      <SafeAreaView edges={['top', 'left', 'right']} style={styles.header}>
        <View style={styles.headRow}>
          <Pressable onPress={onClose} accessibilityRole="button" accessibilityLabel="Close" hitSlop={8} style={styles.iconBtn}>
            <TablerIcon name="x" size={22} color={colors.text} />
          </Pressable>
          <View style={styles.titleCol}>
            <Text style={styles.title} numberOfLines={1}>
              {title}
            </Text>
            <View style={styles.hostRow}>
              {secure && <TablerIcon name="lock" size={11} color={colors.sage} />}
              <Text style={styles.host} numberOfLines={1}>
                {copied ? 'Link copied' : hostOf(nav.url)}
              </Text>
            </View>
          </View>
          <Pressable
            onPress={() => {
              setFailed(false);
              frame.current?.reload();
            }}
            accessibilityRole="button"
            accessibilityLabel="Reload page"
            hitSlop={8}
            style={styles.iconBtn}
          >
            <TablerIcon name="refresh" size={20} color={colors.text2} />
          </Pressable>
        </View>
        <View style={styles.progressTrack}>
          {progress < 1 && <View style={[styles.progressBar, { width: `${Math.max(8, progress * 100)}%` }]} />}
        </View>
      </SafeAreaView>

      <View style={styles.body}>
        {target && (
          <WebFrame
            ref={frame}
            source={{ uri: target }}
            style={styles.web}
            shouldLoad={(next) => {
              if (isExternal(next)) {
                Linking.openURL(next).catch(() => undefined);
                return false;
              }
              return true;
            }}
            onNav={(s) => {
              setNav(s);
              setFailed(false);
            }}
            onProgress={setProgress}
            onLoaded={onLoaded}
            onFail={() => setFailed(true)}
          />
        )}
        {(!target || progress === 0) && !failed && (
          <View style={styles.pageCover} pointerEvents="none">
            <PageSkeleton />
          </View>
        )}
        {failed && (
          <View style={styles.cover}>
            <TablerIcon name="wifi-off" size={32} color={colors.text3} />
            <Text style={styles.failTitle}>This page didn’t load</Text>
            <Text style={styles.failText}>Please check your internet connection and try again.</Text>
            <Pressable
              onPress={() => {
                setFailed(false);
                frame.current?.reload();
              }}
              accessibilityRole="button"
              style={({ pressed }) => [styles.retry, pressed && { opacity: 0.85 }]}
            >
              <Text style={styles.retryText}>Try again</Text>
            </Pressable>
          </View>
        )}
      </View>

      <SafeAreaView edges={['bottom', 'left', 'right']} style={styles.toolbar}>
        <View style={styles.toolRow}>
          <ToolButton icon="chevron-left" label="Back" disabled={!nav.canGoBack} onPress={() => frame.current?.goBack()} />
          <ToolButton icon="chevron-right" label="Forward" disabled={!nav.canGoForward} onPress={() => frame.current?.goForward()} />
          <ToolButton icon="copy" label="Copy link" onPress={copy} />
          <ToolButton
            icon="share"
            label="Share"
            onPress={() => Share.share(Platform.OS === 'ios' ? { url: nav.url } : { message: nav.url }).catch(() => undefined)}
          />
          <ToolButton icon="external-link" label="Open in your browser" onPress={() => Linking.openURL(nav.url).catch(() => undefined)} />
        </View>
      </SafeAreaView>
    </View>
  );
}

function ToolButton({ icon, label, onPress, disabled }: { icon: string; label: string; onPress: () => void; disabled?: boolean }) {
  const styles = useStyles();
  const colors = useColors();
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ disabled }}
      hitSlop={6}
      style={({ pressed }) => [styles.tool, pressed && { backgroundColor: colors.surface2 }]}
    >
      <TablerIcon name={icon} size={22} color={disabled ? colors.border : colors.text2} />
    </Pressable>
  );
}

const useStyles = makeStyles((colors) => ({
  wrap: { flex: 1, backgroundColor: colors.surface },
  header: { backgroundColor: colors.surface, borderBottomWidth: 1, borderBottomColor: colors.border },
  headRow: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 10, paddingVertical: 8 },
  iconBtn: { width: 40, height: 40, borderRadius: 12, alignItems: 'center', justifyContent: 'center' },
  titleCol: { flex: 1, minWidth: 0, alignItems: 'center' },
  title: { fontFamily: fonts.medium, fontSize: 15, color: colors.text },
  hostRow: { flexDirection: 'row', alignItems: 'center', gap: 4, marginTop: 1 },
  host: { fontFamily: fonts.regular, fontSize: 12, color: colors.text3 },
  progressTrack: { height: 2 },
  progressBar: { height: 2, backgroundColor: colors.accent, borderRadius: 1 },
  body: { flex: 1 },
  web: { flex: 1, backgroundColor: colors.surface },
  pageCover: { position: 'absolute', top: 0, left: 0, right: 0, bottom: 0, backgroundColor: colors.surface },
  cover: {
    position: 'absolute',
    top: 0,
    left: 0,
    right: 0,
    bottom: 0,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 10,
    padding: 32,
    backgroundColor: colors.surface,
  },
  failTitle: { fontFamily: fonts.serifMedium, fontSize: 22, color: colors.text, marginTop: 6 },
  failText: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 20, color: colors.text2, textAlign: 'center' },
  retry: { marginTop: 8, paddingVertical: 10, paddingHorizontal: 22, borderRadius: 50, backgroundColor: colors.accent },
  retryText: { fontFamily: fonts.medium, fontSize: 14, color: '#fff' },
  toolbar: { backgroundColor: colors.surface, borderTopWidth: 1, borderTopColor: colors.border },
  toolRow: { flexDirection: 'row', justifyContent: 'space-around', paddingVertical: 6 },
  tool: { width: 48, height: 40, borderRadius: 12, alignItems: 'center', justifyContent: 'center' },
}));

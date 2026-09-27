// The Kounselia video room (Jitsi Meet) shown inside the app's own
// full-screen modal instead of the phone's system browser. Unlike
// BrowserProvider, this never shows an address bar at all — there's no
// browser chrome here to show one in the first place, just the call
// itself and a small "leave" button.
//
// Camera and microphone access for the page comes from the app's own
// iOS/Android permissions (see app.json) — the WebView hands the page's
// getUserMedia request straight through to those once they're granted.
//
// Anywhere in the app: import { openVideoCall } from './VideoCallProvider';
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { ActivityIndicator, BackHandler, Modal, Pressable, Text, View } from 'react-native';
import { SafeAreaProvider, SafeAreaView } from 'react-native-safe-area-context';
import { WebView } from 'react-native-webview';
import { TablerIcon } from '@/components/TablerIcon';
import { showDialog } from '@/components/Dialog';
import { fonts, makeStyles, useColors } from '@/theme';

interface VideoCall {
  openVideoCall(url: string): Promise<void>;
}

const VideoCallContext = createContext<VideoCall | null>(null);

// For plain functions outside React components (e.g. joinSession.ts).
let mounted: VideoCall | null = null;
export function openVideoCall(url: string): Promise<void> {
  if (mounted) return mounted.openVideoCall(url);
  return Promise.resolve();
}

// Only Jitsi's own domain ever needs to load here — nothing else has a
// reason to, and blocking everything else keeps a stray link inside the
// call from quietly opening some other site inside this same, chrome-less
// window.
const ALLOWED = [/^https:\/\/meet\.jit\.si\//i, /^https:\/\/8x8\.vc\//i];

export function VideoCallProvider({ children }: { children: ReactNode }) {
  const [url, setUrl] = useState<string | null>(null);
  const resolver = useRef<(() => void) | null>(null);

  const close = useCallback(() => {
    setUrl(null);
    resolver.current?.();
    resolver.current = null;
  }, []);

  const value = useMemo<VideoCall>(
    () => ({
      openVideoCall(next) {
        resolver.current?.(); // Only one call at a time.
        return new Promise((resolve) => {
          resolver.current = resolve;
          setUrl(next);
        });
      },
    }),
    [],
  );

  useEffect(() => {
    mounted = value;
    return () => {
      mounted = null;
    };
  }, [value]);

  return (
    <VideoCallContext.Provider value={value}>
      {children}
      <Modal visible={!!url} animationType="slide" presentationStyle="fullScreen" onRequestClose={close}>
        <SafeAreaProvider>{url && <VideoCallRoom url={url} onClose={close} />}</SafeAreaProvider>
      </Modal>
    </VideoCallContext.Provider>
  );
}

export function useVideoCall() {
  const ctx = useContext(VideoCallContext);
  if (!ctx) throw new Error('useVideoCall must be used inside <VideoCallProvider>');
  return ctx;
}

function VideoCallRoom({ url, onClose }: { url: string; onClose: () => void }) {
  const styles = useStyles();
  const colors = useColors();
  const [loaded, setLoaded] = useState(false);
  const [failed, setFailed] = useState(false);

  const confirmLeave = useCallback(() => {
    showDialog({
      title: 'Leave this session?',
      message: 'You can rejoin from your dashboard while the session window is still open.',
      icon: 'phone-off',
      buttons: [{ text: 'Stay', style: 'cancel' }, { text: 'Leave', style: 'destructive', onPress: onClose }],
    });
  }, [onClose]);

  useEffect(() => {
    const sub = BackHandler.addEventListener('hardwareBackPress', () => {
      confirmLeave();
      return true;
    });
    return () => sub.remove();
  }, [confirmLeave]);

  return (
    <View style={styles.wrap}>
      <WebView
        source={{ uri: url }}
        style={styles.web}
        // Jitsi's page asks for the camera/mic itself; these just make
        // sure the WebView doesn't block or delay that request.
        allowsInlineMediaPlayback
        mediaPlaybackRequiresUserAction={false}
        mediaCapturePermissionGrantType="grant"
        javaScriptEnabled
        domStorageEnabled
        onShouldStartLoadWithRequest={(req) => ALLOWED.some((re) => re.test(req.url))}
        onLoadEnd={() => setLoaded(true)}
        onError={() => setFailed(true)}
        onHttpError={(e) => {
          if (e.nativeEvent.statusCode >= 500) setFailed(true);
        }}
      />
      {!loaded && !failed && (
        <View style={styles.cover} pointerEvents="none">
          <ActivityIndicator color={colors.accent} />
        </View>
      )}
      {failed && (
        <View style={styles.cover}>
          <TablerIcon name="wifi-off" size={32} color={colors.text3} />
          <Text style={styles.failTitle}>This session didn’t load</Text>
          <Text style={styles.failText}>Please check your internet connection and try again.</Text>
          <Pressable
            onPress={onClose}
            accessibilityRole="button"
            style={({ pressed }) => [styles.retry, pressed && { opacity: 0.85 }]}
          >
            <Text style={styles.retryText}>Close</Text>
          </Pressable>
        </View>
      )}
      <SafeAreaView edges={['top']} style={styles.topBar} pointerEvents="box-none">
        <Pressable onPress={confirmLeave} accessibilityRole="button" accessibilityLabel="Leave session" hitSlop={10} style={styles.closeCircle}>
          <TablerIcon name="x" size={20} color="#fff" />
        </Pressable>
      </SafeAreaView>
    </View>
  );
}

const useStyles = makeStyles((colors) => ({
  wrap: { flex: 1, backgroundColor: '#1F2937' },
  web: { flex: 1, backgroundColor: '#1F2937' },
  topBar: { position: 'absolute', top: 0, left: 0, right: 0, alignItems: 'flex-end', paddingHorizontal: 12, paddingTop: 4 },
  closeCircle: {
    width: 36,
    height: 36,
    borderRadius: 18,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(0,0,0,0.4)',
  },
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
    backgroundColor: '#1F2937',
  },
  failTitle: { fontFamily: fonts.serifMedium, fontSize: 22, color: '#fff', marginTop: 6 },
  failText: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 20, color: 'rgba(255,255,255,0.7)', textAlign: 'center' },
  retry: { marginTop: 8, paddingVertical: 10, paddingHorizontal: 22, borderRadius: 50, backgroundColor: colors.accent },
  retryText: { fontFamily: fonts.medium, fontSize: 14, color: '#fff' },
}));

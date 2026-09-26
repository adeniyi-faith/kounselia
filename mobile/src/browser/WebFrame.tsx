// A web page inside the app (react-native-webview). WebFrame.web.tsx is the
// stand-in used when the app is previewed in a desktop browser.
import type { Ref } from 'react';
import type { StyleProp, ViewStyle } from 'react-native';
import { WebView } from 'react-native-webview';

export interface WebFrameHandle {
  goBack(): void;
  goForward(): void;
  reload(): void;
}

export interface NavState {
  url: string;
  title: string;
  canGoBack: boolean;
  canGoForward: boolean;
}

export interface WebFrameProps {
  source: { uri: string } | { html: string; baseUrl: string };
  ref?: Ref<WebFrameHandle>;
  // Return false to stop a page from loading (e.g. to open it elsewhere).
  // isTopFrame is false for something embedded in the page, like a video.
  shouldLoad?: (url: string, isTopFrame: boolean) => boolean;
  onNav?: (state: NavState) => void;
  onProgress?: (progress: number) => void;
  onLoaded?: (url: string) => void;
  onFail?: () => void;
  javaScript?: boolean;
  style?: StyleProp<ViewStyle>;
}

export function WebFrame({ source, ref, shouldLoad, onNav, onProgress, onLoaded, onFail, javaScript = true, style }: WebFrameProps) {
  return (
    <WebView
      ref={ref as Ref<WebView>}
      source={source}
      style={style}
      javaScriptEnabled={javaScript}
      originWhitelist={['http://*', 'https://*', 'about:*', 'data:*']}
      onShouldStartLoadWithRequest={(req) => (shouldLoad ? shouldLoad(req.url, req.isTopFrame !== false) : true)}
      onNavigationStateChange={(s) => onNav?.({ url: s.url, title: s.title, canGoBack: s.canGoBack, canGoForward: s.canGoForward })}
      onLoadProgress={(e) => onProgress?.(e.nativeEvent.progress)}
      onLoadEnd={(e) => onLoaded?.(e.nativeEvent.url)}
      onError={() => onFail?.()}
      onHttpError={(e) => {
        // Only a broken top page counts; a missing image doesn't.
        if (e.nativeEvent.statusCode >= 500) onFail?.();
      }}
      // No decelerationRate here: the words "normal"/"fast" are only turned
      // into a number on iPhone, and Android crashes when handed the word
      // (it was the cause of the article and safety-page crashes).
      allowsBackForwardNavigationGestures
      pullToRefreshEnabled
      setSupportMultipleWindows={false}
      startInLoadingState={false}
    />
  );
}

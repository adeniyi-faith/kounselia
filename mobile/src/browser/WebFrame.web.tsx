// Desktop-browser preview only: a plain <iframe>. The phone app uses
// WebFrame.tsx. An iframe can't report where it navigates, so back and
// forward do nothing here.
import { useEffect, useImperativeHandle, useRef } from 'react';
import type { WebFrameProps } from './WebFrame';

export type { NavState, WebFrameHandle, WebFrameProps } from './WebFrame';

export function WebFrame({ source, ref, onNav, onProgress, onLoaded, style }: WebFrameProps) {
  const frame = useRef<HTMLIFrameElement | null>(null);
  const url = 'uri' in source ? source.uri : source.baseUrl;

  useImperativeHandle(ref, () => ({
    goBack() {},
    goForward() {},
    reload() {
      if (frame.current) frame.current.src = frame.current.src;
    },
  }));

  useEffect(() => {
    onNav?.({ url, title: '', canGoBack: false, canGoForward: false });
    onProgress?.(0.1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [url]);

  return (
    <iframe
      ref={frame}
      title="page"
      src={'uri' in source ? source.uri : undefined}
      srcDoc={'html' in source ? source.html : undefined}
      onLoad={() => {
        onProgress?.(1);
        onLoaded?.(url);
      }}
      style={{ flex: 1, border: 0, width: '100%', height: '100%', ...(style as object) }}
    />
  );
}

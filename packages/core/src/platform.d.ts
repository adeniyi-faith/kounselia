// The only runtime features @kounselia/core may use: the ones browsers
// and React Native both have. Declared by hand (instead of pulling in the
// DOM typings) so that reaching for something browser-only — window,
// localStorage, document — fails the typecheck here rather than crashing
// the mobile app at runtime.
//
// Only this package's own typecheck sees this file; the web and mobile
// apps use their platform's real typings when they compile it.

interface AbortSignal {
  readonly aborted: boolean;
}

declare class AbortController {
  readonly signal: AbortSignal;
  abort(): void;
}

interface FetchResponse {
  readonly ok: boolean;
  readonly status: number;
  json(): Promise<any>;
}

declare function fetch(
  url: string,
  init?: { method?: string; headers?: Record<string, string>; body?: string; signal?: AbortSignal },
): Promise<FetchResponse>;

declare function setTimeout(callback: () => void, ms?: number): any;
declare function clearTimeout(id: any): void;

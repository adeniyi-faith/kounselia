import { createContext, createElement, useContext, useMemo } from 'react';
import type { ReactNode } from 'react';
import { isRtl, makeT, normalizeLanguage } from '@kounselia/core';
import type { Translate } from '@kounselia/core';

interface I18n {
  t: Translate;
  language: string;
  rtl: boolean;
}

const fallback: I18n = { t: makeT('en'), language: 'en', rtl: false };
const I18nContext = createContext<I18n>(fallback);

// The page also hands over the chat's own sentences (window.KOUNSELIA.i18n,
// already in the member's language). They win over the bundled copy, so a
// sentence added on the server shows up without rebuilding this bundle.
function withServerWords(base: Translate, words: Record<string, string> | undefined): Translate {
  if (!words) return base;
  return (key, vars) => {
    const text = words[key];
    if (typeof text !== 'string') return base(key, vars);
    return vars ? text.replace(/\{(\w+)\}/g, (_, n: string) => (n in vars ? String(vars[n]) : `{${n}}`)) : text;
  };
}

export function I18nProvider({
  language,
  words,
  children,
}: {
  language: string | undefined;
  words?: Record<string, string>;
  children: ReactNode;
}) {
  const value = useMemo<I18n>(() => {
    const code = normalizeLanguage(language);
    return { t: withServerWords(makeT(code), words), language: code, rtl: isRtl(code) };
  }, [language, words]);
  return createElement(I18nContext.Provider, { value }, children);
}

export function useI18n(): I18n {
  return useContext(I18nContext);
}

export function useT(): Translate {
  return useContext(I18nContext).t;
}

// Fills a sentence that holds a {link} marker with a clickable piece, so the
// whole sentence stays one translatable string and the link sits where the
// language puts it.
const MARK = '\u0001';
export function splitAtLink(text: string): [string, string] {
  const i = text.indexOf(MARK);
  return i < 0 ? [text, ''] : [text.slice(0, i), text.slice(i + 1)];
}
export const LINK_MARK = MARK;

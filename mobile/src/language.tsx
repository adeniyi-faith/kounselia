// The language the app is shown in, for every screen.
//
// It starts as the phone's language, and the member's choice in Settings is
// remembered on the phone (and also saved on their account, so counselors
// reply in the same language). Screens call `useT()` for their words, which
// live in packages/core/src/locales/<code>.json.
import { deviceLanguage, isRtl, makeT, normalizeLanguage, type Translate } from '@kounselia/core';
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { readSecure, writeSecure } from './secureStorage';

const LANGUAGE_KEY = 'kounselia_app_language';

interface LanguageState {
  language: string;
  rtl: boolean;
  t: Translate;
  // Switch the app's language now and remember it.
  setLanguage(code: string): void;
}

const LanguageContext = createContext<LanguageState | null>(null);

export function LanguageProvider({ children }: { children: ReactNode }) {
  const [language, setCode] = useState(deviceLanguage());

  useEffect(() => {
    readSecure(LANGUAGE_KEY).then((saved) => {
      if (saved) setCode(normalizeLanguage(saved));
    });
  }, []);

  const setLanguage = useCallback((code: string) => {
    const clean = normalizeLanguage(code);
    setCode(clean);
    writeSecure(LANGUAGE_KEY, clean);
  }, []);

  const value = useMemo(() => ({ language, rtl: isRtl(language), t: makeT(language), setLanguage }), [language, setLanguage]);
  return <LanguageContext.Provider value={value}>{children}</LanguageContext.Provider>;
}

export function useLanguage(): LanguageState {
  const ctx = useContext(LanguageContext);
  if (!ctx) throw new Error('useLanguage must be used inside LanguageProvider');
  return ctx;
}

/** The translate function for the current language: const t = useT(); t('growth.title'). */
export function useT(): Translate {
  return useLanguage().t;
}

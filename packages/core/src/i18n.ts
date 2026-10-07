// Words on screen, in the member's language. Every sentence lives once in
// src/locales/<code>.json (the server reads the same files for what it
// writes itself, like reminders), and a missing translation falls back to
// English so a screen is never blank.
//
// To add a language: add its code and names to LANGUAGES below and in
// includes/i18n.php, then copy en.json to <code>.json and translate it.
import ar from './locales/ar.json';
import en from './locales/en.json';
import es from './locales/es.json';
import fr from './locales/fr.json';
import pt from './locales/pt.json';

export interface Language {
  code: string;
  name: string; // in its own language, for the picker
  rtl: boolean; // written right to left
}

export const LANGUAGES: Language[] = [
  { code: 'en', name: 'English', rtl: false },
  { code: 'fr', name: 'Français', rtl: false },
  { code: 'es', name: 'Español', rtl: false },
  { code: 'pt', name: 'Português', rtl: false },
  { code: 'ar', name: 'العربية', rtl: true },
];

const DICTIONARIES: Record<string, Record<string, string>> = { en, fr, es, pt, ar };

export type Translate = (key: string, vars?: Record<string, string | number>) => string;

/** A supported language code for anything like "fr-CA" or "pt_BR", else 'en'. */
export function normalizeLanguage(raw: string | null | undefined): string {
  const base = (raw ?? '').toLowerCase().split(/[-_]/)[0];
  return LANGUAGES.some((l) => l.code === base) ? base : 'en';
}

export function isRtl(code: string): boolean {
  return LANGUAGES.find((l) => l.code === code)?.rtl ?? false;
}

/** The language of the phone or browser (it only suggests; the member can change it). */
export function deviceLanguage(): string {
  try {
    return normalizeLanguage(Intl.DateTimeFormat().resolvedOptions().locale);
  } catch {
    return 'en';
  }
}

/** A translate function for one language: t('growth.day_of', { day: 3, total: 30 }). */
export function makeT(code: string): Translate {
  const dict = DICTIONARIES[normalizeLanguage(code)] ?? en;
  return (key, vars) => {
    const text = dict[key] ?? (en as Record<string, string>)[key] ?? key;
    return vars ? text.replace(/\{(\w+)\}/g, (_, name: string) => (name in vars ? String(vars[name]) : `{${name}}`)) : text;
  };
}

// The website's look, as values React Native can use, in a light and a
// dark version. The light colours are copied from the :root block in
// inc/kounselia-styles.php — keep the two in step. The website has no dark
// version yet; the dark one here keeps the same warm, calm feel on a deep
// navy-charcoal, with every text colour checked for easy reading.
//
// Screens don't import colours directly. They use:
//   const useStyles = makeStyles((colors) => ({ ...styles... }));
//   ...inside the component:  const styles = useStyles();  const colors = useColors();
// so everything redraws in the right colours when the member switches
// between Light, Dark and their phone's setting (Settings → Appearance).
import { createContext, createElement, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { Platform, StyleSheet, useColorScheme } from 'react-native';
import { readSecure, writeSecure } from './secureStorage';

const light = {
  bg: '#F8F6F2',
  surface: '#FFFFFF',
  surface2: '#F2EFE9',
  surface3: '#EDEAE3',
  text: '#18160F',
  text2: '#5B574D',
  text3: '#A8A49A',
  border: '#E8E4DB',
  // Filled buttons and selected things (white text sits on it).
  accent: '#1E3A5F',
  accent2: '#284B7A',
  // Links, icons and text in the accent colour.
  accentText: '#1E3A5F',
  accentLight: '#E8EEF6',
  accentBorder: 'rgba(30,58,95,0.14)',
  gold: '#B07D3A',
  goldLight: '#FBF5EA',
  rose: '#8B3A52',
  roseLight: '#F7EBF0',
  roseBorder: 'rgba(139,58,82,0.18)',
  sage: '#2E5C3E',
  sageLight: '#EAF2EC',
  teal: '#1E5C5C',
  tealLight: '#E6F2F2',
  plum: '#4A3070',
  plumLight: '#EEE9F8',
  sienna: '#7A3D1E',
  siennaLight: '#F5EBE5',
  navy: '#162B4A',
  navyLight: '#E6EBF2',
  // Solid fills behind white text or icons (buttons, the welcome banner,
  // the call screen). They stay deep in dark mode so white stays readable.
  navyFill: '#162B4A',
  sageFill: '#2E5C3E',
  roseFill: '#8B3A52',
  // The navy of the campaign posters and the splash screen.
  brandNavy: '#26446F',
  // Chat (the website's chat-app colours).
  chatBubble: '#F0F4F8',
  chatBubbleBorder: '#DCE4EC',
  chatText: '#1E293B',
  chatFooter: '#EFEAE2',
  // Frosted bars (tab bar, chat header) and the dimmed layer behind sheets.
  glass: 'rgba(255,255,255,0.97)',
  backdrop: 'rgba(24,22,15,0.35)',
  // Small dark pop-up messages.
  toast: '#18160F',
};

export type Palette = typeof light;

const dark: Palette = {
  bg: '#121519',
  surface: '#1B1F26',
  surface2: '#232832',
  surface3: '#2C323D',
  text: '#F1EEE8',
  text2: '#B8B3A8',
  text3: '#8A867D',
  border: '#2E343F',
  accent: '#3F6FB0',
  accent2: '#4A7BBE',
  accentText: '#7EA6DC',
  accentLight: '#1E2B3E',
  accentBorder: 'rgba(126,166,220,0.22)',
  gold: '#D9A963',
  goldLight: '#2D2418',
  rose: '#E08BA3',
  roseLight: '#35202A',
  roseBorder: 'rgba(224,139,163,0.25)',
  sage: '#86C49B',
  sageLight: '#1C2C22',
  teal: '#7CC6C6',
  tealLight: '#172B2B',
  plum: '#B9A3E0',
  plumLight: '#282238',
  sienna: '#E0976F',
  siennaLight: '#32231B',
  navy: '#A9BDDC',
  navyLight: '#1F2735',
  navyFill: '#172B47',
  sageFill: '#3B7A50',
  roseFill: '#A94866',
  brandNavy: '#26446F',
  chatBubble: '#1F2631',
  chatBubbleBorder: '#2C3542',
  chatText: '#E6EAF0',
  chatFooter: '#171B21',
  glass: 'rgba(27,31,38,0.97)',
  backdrop: 'rgba(0,0,0,0.6)',
  toast: '#2E343F',
};

export const palettes = { light, dark };

// The counselor colour classes (.ic-rose etc. in inc/kounselia-styles.php):
// a strong colour for the icon on a pale version of it.
export function counselorColors(name: string, colors: Palette) {
  const pairs: Record<string, { fg: string; bg: string }> = {
    blue: { fg: colors.accentText, bg: colors.accentLight },
    gold: { fg: colors.gold, bg: colors.goldLight },
    rose: { fg: colors.rose, bg: colors.roseLight },
    sage: { fg: colors.sage, bg: colors.sageLight },
    teal: { fg: colors.teal, bg: colors.tealLight },
    plum: { fg: colors.plum, bg: colors.plumLight },
    sienna: { fg: colors.sienna, bg: colors.siennaLight },
    navy: { fg: colors.navy, bg: colors.navyLight },
  };
  return pairs[name] ?? pairs.blue;
}

export const radius = {
  r: 24,
  sm: 16,
  field: 14,
  pill: 50,
};

// Outfit for everything, Cormorant Garamond for headings — as on the site.
// Loaded in app/_layout.tsx.
export const fonts = {
  light: 'Outfit_300Light',
  regular: 'Outfit_400Regular',
  medium: 'Outfit_500Medium',
  semibold: 'Outfit_600SemiBold',
  serif: 'CormorantGaramond_400Regular',
  serifMedium: 'CormorantGaramond_500Medium',
};

// --shadow-btn / --shadow-md. iOS draws real shadows; Android only has
// "elevation", so it gets the nearest equivalent.
export const shadows = {
  // --shadow-sm
  soft: Platform.select({
    ios: { shadowColor: '#18160F', shadowOpacity: 0.04, shadowRadius: 6, shadowOffset: { width: 0, height: 4 } },
    default: { elevation: 1 },
  }),
  button: Platform.select({
    ios: { shadowColor: '#1E3A5F', shadowOpacity: 0.25, shadowRadius: 7, shadowOffset: { width: 0, height: 4 } },
    default: { elevation: 4 },
  }),
  card: Platform.select({
    ios: { shadowColor: '#18160F', shadowOpacity: 0.06, shadowRadius: 12, shadowOffset: { width: 0, height: 8 } },
    default: { elevation: 2 },
  }),
};

// ---- Light, dark, or whatever the phone is set to ------------------------------

export type Appearance = 'light' | 'dark' | 'system';
type Scheme = 'light' | 'dark';

interface Theme {
  scheme: Scheme; // what's showing now
  appearance: Appearance; // what the member chose
  setAppearance(choice: Appearance): void;
  colors: Palette;
}

const APPEARANCE_KEY = 'kounselia_appearance';
const ThemeContext = createContext<Theme>({ scheme: 'light', appearance: 'system', setAppearance: () => undefined, colors: light });

export function ThemeProvider({ children }: { children: ReactNode }) {
  const device = useColorScheme();
  const [appearance, setChoice] = useState<Appearance | null>(null);

  // The saved choice loads before anything is drawn (the splash screen
  // stays up meanwhile), so the app never flashes the wrong colours.
  useEffect(() => {
    readSecure(APPEARANCE_KEY).then((saved) => setChoice(saved === 'light' || saved === 'dark' ? saved : 'system'));
  }, []);

  const setAppearance = useCallback((choice: Appearance) => {
    setChoice(choice);
    writeSecure(APPEARANCE_KEY, choice);
  }, []);

  const chosen = appearance ?? 'system';
  const scheme: Scheme = chosen === 'system' ? (device === 'dark' ? 'dark' : 'light') : chosen;
  const value = useMemo(() => ({ scheme, appearance: chosen, setAppearance, colors: palettes[scheme] }), [scheme, chosen, setAppearance]);

  if (appearance === null) return null;
  return createElement(ThemeContext.Provider, { value }, children);
}

export function useTheme() {
  return useContext(ThemeContext);
}

export function useColors() {
  return useContext(ThemeContext).colors;
}

/**
 * Styles that follow the theme. Each version (light and dark) is built
 * once, the first time it's needed, and then reused.
 */
export function makeStyles<T extends StyleSheet.NamedStyles<T>>(build: (colors: Palette) => T) {
  const built: Partial<Record<Scheme, T>> = {};
  return function useStyles(): T {
    const { scheme } = useContext(ThemeContext);
    return (built[scheme] ??= StyleSheet.create(build(palettes[scheme])));
  };
}

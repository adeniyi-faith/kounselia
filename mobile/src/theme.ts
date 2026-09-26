// The website's look, as values React Native can use. Copied from the
// :root block in inc/kounselia-styles.php — keep the two in step.
import { Platform } from 'react-native';

export const colors = {
  bg: '#F8F6F2',
  surface: '#FFFFFF',
  surface2: '#F2EFE9',
  surface3: '#EDEAE3',
  text: '#18160F',
  text2: '#5B574D',
  text3: '#A8A49A',
  border: '#E8E4DB',
  accent: '#1E3A5F',
  accent2: '#284B7A',
  accentLight: '#E8EEF6',
  gold: '#B07D3A',
  goldLight: '#FBF5EA',
  rose: '#8B3A52',
  roseLight: '#F7EBF0',
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
};

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
  button: Platform.select({
    ios: { shadowColor: '#1E3A5F', shadowOpacity: 0.25, shadowRadius: 7, shadowOffset: { width: 0, height: 4 } },
    default: { elevation: 4 },
  }),
  card: Platform.select({
    ios: { shadowColor: '#18160F', shadowOpacity: 0.06, shadowRadius: 12, shadowOffset: { width: 0, height: 8 } },
    default: { elevation: 2 },
  }),
};

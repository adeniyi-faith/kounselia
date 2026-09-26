// The website's icon set (Tabler Icons 2.44.0, the "ti ti-…" classes), so
// counselor icons chosen in the admin Counselor Studio show up the same
// in the app. Font and name list come from scripts/build-tabler-icons.mjs.
import createIconSet from '@expo/vector-icons/createIconSet';
import type { StyleProp, TextStyle } from 'react-native';
import glyphs from '@/icons/tabler-glyphs.json';

const glyphMap = glyphs as Record<string, number>;

const Tabler = createIconSet(glyphMap, 'tabler-icons', require('../../assets/fonts/tabler-icons.ttf'));

// Loaded with the other fonts in app/_layout.tsx.
export const tablerFont = Tabler.font;

interface Props {
  name: string; // without the "ti-" prefix, e.g. "heart"
  size?: number;
  color?: string;
  style?: StyleProp<TextStyle>;
}

export function TablerIcon({ name, size = 20, color, style }: Props) {
  // An icon name typed by an admin that doesn't exist falls back to a
  // plain speech bubble rather than showing nothing.
  const known = name in glyphMap ? name : 'message-circle';
  // Exactly `size` tall, with none of the extra space Android adds above
  // and below text by default, so icons line up with what's beside them.
  return <Tabler name={known} size={size} color={color} style={[{ lineHeight: size, includeFontPadding: false, textAlignVertical: 'center' }, style]} />;
}

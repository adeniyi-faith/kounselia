import { StyleSheet, View } from 'react-native';
import { counselorColors } from '@/theme';
import { TablerIcon } from './TablerIcon';

// The coloured rounded square with the counselor's icon (.tile-av,
// .chat-av-sm and .msg-av on the website — same look, different sizes).
export function CounselorAvatar({ icon, color, size }: { icon: string; color: string; size: number }) {
  const { fg, bg } = counselorColors(color);
  return (
    <View
      style={[styles.box, { width: size, height: size, borderRadius: Math.round(size * 0.3), backgroundColor: bg }]}
      accessibilityElementsHidden
      importantForAccessibility="no-hide-descendants"
    >
      <TablerIcon name={icon} size={Math.round(size * 0.44)} color={fg} />
    </View>
  );
}

const styles = StyleSheet.create({
  box: { alignItems: 'center', justifyContent: 'center' },
});

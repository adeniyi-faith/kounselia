// The site's pill buttons: .modal-btn (navy gradient) and .btn-ghost
// (outlined), plus a solid rose one for something that can't be undone. Pressing gives a light tap of haptic feedback and a small
// press-down, where the website has a hover lift.
import * as Haptics from 'expo-haptics';
import { LinearGradient } from 'expo-linear-gradient';
import { ActivityIndicator, Pressable, StyleSheet, Text, type ViewStyle } from 'react-native';
import { fonts, makeStyles, radius, shadows, useColors } from '@/theme';

interface Props {
  title: string;
  onPress: () => void;
  variant?: 'primary' | 'ghost' | 'danger';
  busy?: boolean;
  disabled?: boolean;
  style?: ViewStyle;
}

export function Button({ title, onPress, variant = 'primary', busy = false, disabled = false, style }: Props) {
  const styles = useStyles();
  const colors = useColors();
  const inactive = busy || disabled;
  const primary = variant === 'primary';
  const filled = variant !== 'ghost';

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ disabled: inactive, busy }}
      disabled={inactive}
      onPress={() => {
        Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light).catch(() => undefined);
        onPress();
      }}
      style={({ pressed }) => [
        styles.base,
        filled ? shadows.button : styles.ghost,
        variant === 'danger' && { backgroundColor: colors.roseFill },
        { opacity: inactive ? 0.6 : 1, transform: [{ scale: pressed ? 0.98 : 1 }] },
        style,
      ]}
    >
      {primary && (
        <LinearGradient
          colors={[colors.accent, colors.accent2]}
          start={{ x: 0, y: 0 }}
          end={{ x: 1, y: 1 }}
          style={[StyleSheet.absoluteFill, styles.gradient]}
        />
      )}
      {busy ? (
        <ActivityIndicator color={filled ? '#fff' : colors.accent} />
      ) : (
        <Text style={[styles.label, { color: filled ? '#fff' : colors.text }]}>{title}</Text>
      )}
    </Pressable>
  );
}

const useStyles = makeStyles((colors) => ({
  base: {
    minHeight: 54,
    borderRadius: radius.pill,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 24,
  },
  gradient: { borderRadius: radius.pill },
  ghost: { borderWidth: 1, borderColor: colors.border, backgroundColor: colors.surface },
  label: { fontFamily: fonts.medium, fontSize: 15 },
}));

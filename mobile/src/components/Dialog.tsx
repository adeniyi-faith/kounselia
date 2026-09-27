// Kounselia's own pop-up for questions and notices ("Sign out?", "You're
// booked"), drawn in the app's colours in light and dark, instead of the
// phone's plain system box. It fades the screen behind it and eases the
// card in.
//
// Use it like React Native's Alert.alert, from anywhere:
//   showDialog({ title: 'Sign out?', message: '…', icon: 'logout',
//     buttons: [{ text: 'Cancel', style: 'cancel' }, { text: 'Sign out', style: 'destructive', onPress }] });
//
// <DialogHost /> is placed once, in app/_layout.tsx.
import * as Haptics from 'expo-haptics';
import { LinearGradient } from 'expo-linear-gradient';
import { useEffect, useState } from 'react';
import { Animated, Easing, Modal, Pressable, StyleSheet, Text, View } from 'react-native';
import { fonts, makeStyles, radius, useColors, type Palette } from '@/theme';
import { TablerIcon } from './TablerIcon';

export interface DialogButton {
  text: string;
  // 'cancel' is the quiet way out; 'destructive' is for something that
  // can't be undone (shown in rose instead of navy).
  style?: 'default' | 'cancel' | 'destructive';
  onPress?: () => void;
}

export interface DialogOptions {
  title: string;
  message?: string;
  // A Tabler icon name shown above the title.
  icon?: string;
  tone?: 'default' | 'danger' | 'success';
  // Defaults to a single "OK".
  buttons?: DialogButton[];
}

let show: ((options: DialogOptions) => void) | null = null;

export function showDialog(options: DialogOptions) {
  show?.(options);
}

export function DialogHost() {
  const [current, setCurrent] = useState<DialogOptions | null>(null);
  const [open, setOpen] = useState(false);
  const [fade] = useState(() => new Animated.Value(0));

  useEffect(() => {
    show = (options) => {
      setCurrent(options);
      setOpen(true);
      if (options.tone === 'danger' || options.buttons?.some((b) => b.style === 'destructive')) {
        Haptics.notificationAsync(Haptics.NotificationFeedbackType.Warning).catch(() => undefined);
      }
    };
    return () => {
      show = null;
    };
  }, []);

  useEffect(() => {
    if (!open) return;
    fade.setValue(0);
    Animated.timing(fade, { toValue: 1, duration: 220, easing: Easing.out(Easing.cubic), useNativeDriver: true }).start();
  }, [open, current, fade]);

  function close(then?: () => void) {
    Animated.timing(fade, { toValue: 0, duration: 160, easing: Easing.in(Easing.quad), useNativeDriver: true }).start(() => {
      setOpen(false);
      setCurrent(null);
      // After the pop-up has gone, so a follow-up pop-up can open cleanly.
      then?.();
    });
  }

  if (!current) return null;
  const buttons = current.buttons?.length ? current.buttons : [{ text: 'OK' }];
  const cancel = buttons.find((b) => b.style === 'cancel');
  // Tapping outside or the phone's back button means "no" when there is a
  // way out, and "OK" when it's only a notice.
  const dismiss = () => close((cancel ?? (buttons.length === 1 ? buttons[0] : undefined))?.onPress);
  const dismissable = !!cancel || buttons.length === 1;

  return (
    <Modal visible={open} transparent animationType="none" statusBarTranslucent navigationBarTranslucent onRequestClose={() => dismissable && dismiss()}>
      <Card options={current} buttons={buttons} fade={fade} onChoose={(b) => close(b.onPress)} onDismiss={dismissable ? dismiss : undefined} />
    </Modal>
  );
}

function Card({
  options,
  buttons,
  fade,
  onChoose,
  onDismiss,
}: {
  options: DialogOptions;
  buttons: DialogButton[];
  fade: Animated.Value;
  onChoose: (b: DialogButton) => void;
  onDismiss?: () => void;
}) {
  const styles = useStyles();
  const colors = useColors();
  const destructive = buttons.some((b) => b.style === 'destructive');
  const tone = options.tone ?? (destructive ? 'danger' : 'default');
  const iconColors = toneColors(tone, colors);
  // Two short choices sit side by side; anything more stacks.
  const sideBySide = buttons.length === 2 && buttons.every((b) => b.text.length <= 16);
  // The way out goes last when stacked, and first (on the left) side by side.
  const ordered = sideBySide
    ? [...buttons].sort((a, b) => Number(b.style === 'cancel') - Number(a.style === 'cancel'))
    : [...buttons].sort((a, b) => Number(a.style === 'cancel') - Number(b.style === 'cancel'));

  return (
    <View style={styles.fill}>
      <Animated.View style={[StyleSheet.absoluteFill, styles.backdrop, { opacity: fade }]}>
        <Pressable style={StyleSheet.absoluteFill} onPress={onDismiss} accessibilityLabel="Close" disabled={!onDismiss} />
      </Animated.View>
      <Animated.View
        accessibilityViewIsModal
        accessibilityRole="alert"
        style={[
          styles.card,
          {
            opacity: fade,
            transform: [
              { scale: fade.interpolate({ inputRange: [0, 1], outputRange: [0.94, 1] }) },
              { translateY: fade.interpolate({ inputRange: [0, 1], outputRange: [12, 0] }) },
            ],
          },
        ]}
      >
        {options.icon ? (
          <View style={[styles.iconWrap, { backgroundColor: iconColors.bg }]}>
            <TablerIcon name={options.icon} size={26} color={iconColors.fg} />
          </View>
        ) : null}
        <Text style={styles.title} accessibilityRole="header">
          {options.title}
        </Text>
        {options.message ? <Text style={styles.message}>{options.message}</Text> : null}
        <View style={[styles.buttons, sideBySide && styles.buttonsRow]}>
          {ordered.map((b) => (
            <DialogChoice key={b.text} button={b} grow={sideBySide} onPress={() => onChoose(b)} />
          ))}
        </View>
      </Animated.View>
    </View>
  );
}

function DialogChoice({ button, grow, onPress }: { button: DialogButton; grow: boolean; onPress: () => void }) {
  const styles = useStyles();
  const colors = useColors();
  const quiet = button.style === 'cancel';
  const danger = button.style === 'destructive';
  return (
    <Pressable
      onPress={() => {
        Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light).catch(() => undefined);
        onPress();
      }}
      accessibilityRole="button"
      style={({ pressed }) => [styles.choice, grow && styles.grow, quiet && styles.quiet, { transform: [{ scale: pressed ? 0.97 : 1 }] }]}
    >
      {!quiet && (
        <LinearGradient
          colors={danger ? [colors.roseFill, colors.roseFill] : [colors.accent, colors.accent2]}
          start={{ x: 0, y: 0 }}
          end={{ x: 1, y: 1 }}
          style={[StyleSheet.absoluteFill, styles.choiceFill]}
        />
      )}
      <Text style={[styles.choiceText, { color: quiet ? colors.text : '#fff' }]} numberOfLines={1}>
        {button.text}
      </Text>
    </Pressable>
  );
}

function toneColors(tone: NonNullable<DialogOptions['tone']>, colors: Palette) {
  if (tone === 'danger') return { fg: colors.rose, bg: colors.roseLight };
  if (tone === 'success') return { fg: colors.sage, bg: colors.sageLight };
  return { fg: colors.accentText, bg: colors.accentLight };
}

const useStyles = makeStyles((colors) => ({
  fill: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24 },
  backdrop: { backgroundColor: colors.backdrop },
  card: {
    width: '100%',
    maxWidth: 400,
    backgroundColor: colors.surface,
    borderRadius: 28,
    borderWidth: 1,
    borderColor: colors.border,
    paddingHorizontal: 24,
    paddingTop: 28,
    paddingBottom: 20,
    alignItems: 'center',
    shadowColor: '#000',
    shadowOpacity: 0.18,
    shadowRadius: 24,
    shadowOffset: { width: 0, height: 12 },
    elevation: 12,
  },
  iconWrap: { width: 56, height: 56, borderRadius: 18, alignItems: 'center', justifyContent: 'center', marginBottom: 14 },
  title: { fontFamily: fonts.serifMedium, fontSize: 26, lineHeight: 30, color: colors.text, textAlign: 'center' },
  message: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 22, color: colors.text2, textAlign: 'center', marginTop: 8 },
  buttons: { alignSelf: 'stretch', gap: 10, marginTop: 22 },
  buttonsRow: { flexDirection: 'row' },
  choice: { minHeight: 50, borderRadius: radius.pill, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 18, overflow: 'hidden' },
  grow: { flex: 1 },
  quiet: { backgroundColor: colors.surface2, borderWidth: 1, borderColor: colors.border },
  choiceFill: { borderRadius: radius.pill },
  choiceText: { fontFamily: fonts.medium, fontSize: 15 },
}));

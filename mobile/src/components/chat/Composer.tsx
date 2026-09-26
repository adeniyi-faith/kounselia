// The message box (.input-pill) and round send button (.dynamic-fab).
// The web chat's microphone for speaking a message arrives with voice
// support; until then the button is send-only and greyed out when empty.
import * as Haptics from 'expo-haptics';
import { useState } from 'react';
import { Pressable, StyleSheet, TextInput, View } from 'react-native';
import { colors, fonts, shadows } from '@/theme';
import { TablerIcon } from '../TablerIcon';

export function Composer({ busy, onSend }: { busy: boolean; onSend: (text: string) => void }) {
  const [value, setValue] = useState('');
  const [focused, setFocused] = useState(false);
  const canSend = value.trim().length > 0 && !busy;

  function send() {
    if (!canSend) return;
    Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light).catch(() => undefined);
    onSend(value);
    setValue('');
  }

  return (
    <View style={styles.area}>
      <View style={[styles.pill, focused && styles.pillFocused]}>
        <TextInput
          value={value}
          onChangeText={setValue}
          onFocus={() => setFocused(true)}
          onBlur={() => setFocused(false)}
          placeholder="Message"
          placeholderTextColor="#94A3B8"
          multiline
          maxLength={4000}
          style={styles.input}
          accessibilityLabel="Message"
        />
      </View>
      <Pressable
        onPress={send}
        disabled={!canSend}
        accessibilityRole="button"
        accessibilityLabel="Send"
        accessibilityState={{ disabled: !canSend }}
        style={({ pressed }) => [
          styles.fab,
          canSend ? [styles.fabOn, shadows.button] : styles.fabOff,
          pressed && { transform: [{ scale: 0.94 }] },
        ]}
      >
        <TablerIcon name="send" size={22} color={canSend ? '#fff' : colors.text3} />
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  area: { flexDirection: 'row', alignItems: 'flex-end', gap: 12, paddingHorizontal: 16, paddingTop: 10, paddingBottom: 12 },
  pill: {
    flex: 1,
    minHeight: 48,
    justifyContent: 'center',
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 24,
    paddingHorizontal: 16,
    paddingVertical: 6,
  },
  pillFocused: { borderColor: colors.accent },
  input: {
    fontFamily: fonts.regular,
    fontSize: 16,
    lineHeight: 22,
    color: '#1E293B',
    maxHeight: 120,
    paddingTop: 6,
    paddingBottom: 6,
  },
  fab: { width: 48, height: 48, borderRadius: 24, alignItems: 'center', justifyContent: 'center' },
  fabOn: { backgroundColor: colors.accent },
  fabOff: { backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
});

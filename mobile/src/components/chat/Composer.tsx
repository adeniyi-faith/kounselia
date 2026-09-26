// The message box (.input-pill) and the round button beside it
// (.dynamic-fab), which works like the website's: a green microphone
// while the box is empty (tap to speak your message, tap again to stop),
// red while recording, and navy "send" once there's text.
import type { KounseliaConfig } from '@kounselia/core';
import * as Haptics from 'expo-haptics';
import { useCallback, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, TextInput, View } from 'react-native';
import { useDictation } from '@/audio/useDictation';
import { colors, fonts, shadows } from '@/theme';
import { TablerIcon } from '../TablerIcon';

const GREEN = '#00A884';

interface Props {
  config: KounseliaConfig;
  busy: boolean;
  onSend: (text: string) => void;
  onNotify: (text: string) => void;
}

export function Composer({ config, busy, onSend, onNotify }: Props) {
  const [value, setValue] = useState('');
  const [focused, setFocused] = useState(false);

  const addSpoken = useCallback((text: string) => {
    setValue((prev) => (prev.trim() ? `${prev.trimEnd()} ${text}` : text));
  }, []);
  const dictation = useDictation(config, addSpoken, onNotify);

  const hasText = value.trim().length > 0;
  const recording = dictation.state === 'recording';
  const transcribing = dictation.state === 'transcribing';

  function press() {
    Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light).catch(() => undefined);
    if (recording || (!hasText && !transcribing)) {
      dictation.toggle();
      return;
    }
    if (hasText && !busy) {
      onSend(value);
      setValue('');
    }
  }

  const disabled = transcribing || (hasText && busy);
  const background = recording ? colors.rose : hasText ? colors.accent : GREEN;
  const label = recording ? 'Stop recording' : hasText ? 'Send' : 'Speak your message';

  return (
    <View style={styles.area}>
      <View style={[styles.pill, focused && styles.pillFocused]}>
        <TextInput
          value={value}
          onChangeText={setValue}
          onFocus={() => setFocused(true)}
          onBlur={() => setFocused(false)}
          placeholder={recording ? 'Listening… tap the red button when you’re done' : transcribing ? 'Writing down what you said…' : 'Message'}
          placeholderTextColor="#94A3B8"
          multiline
          maxLength={4000}
          editable={!recording}
          style={styles.input}
          accessibilityLabel="Message"
        />
      </View>
      <Pressable
        onPress={press}
        disabled={disabled}
        accessibilityRole="button"
        accessibilityLabel={label}
        accessibilityState={{ disabled, busy: transcribing }}
        style={({ pressed }) => [
          styles.fab,
          { backgroundColor: background, opacity: disabled ? 0.6 : 1 },
          shadows.button,
          pressed && { transform: [{ scale: 0.94 }] },
        ]}
      >
        {transcribing ? (
          <ActivityIndicator color="#fff" />
        ) : (
          <TablerIcon name={recording ? 'player-stop-filled' : hasText ? 'send' : 'microphone'} size={22} color="#fff" />
        )}
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
});

// "Today's reflection" (.journal-card): one private entry per day. Saves
// itself a moment after you stop typing, like the website, and has a
// Save button for anyone who'd rather be sure.
import { saveJournal, type KounseliaConfig } from '@kounselia/core';
import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { colors, fonts } from '@/theme';
import { Card } from './Card';

const AUTOSAVE_MS = 1800;

export function JournalCard({ config, initial }: { config: KounseliaConfig; initial: string }) {
  const [text, setText] = useState(initial);
  const [focused, setFocused] = useState(false);
  const [status, setStatus] = useState<'idle' | 'saving' | 'saved' | 'error'>('idle');
  const saved = useRef(initial);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  // A refresh brings in what's on the server, unless they're mid-edit.
  useEffect(() => {
    if (text === saved.current) {
      setText(initial);
      saved.current = initial;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [initial]);

  async function save(value: string) {
    if (timer.current) clearTimeout(timer.current);
    if (value === saved.current) return;
    setStatus('saving');
    const res = await saveJournal(config, value);
    if (res.ok) {
      saved.current = value;
      setStatus('saved');
    } else {
      setStatus('error');
    }
  }

  function onChange(value: string) {
    setText(value);
    setStatus('idle');
    if (timer.current) clearTimeout(timer.current);
    timer.current = setTimeout(() => save(value), AUTOSAVE_MS);
  }

  // Save anything unsaved when leaving the screen.
  const latest = useRef(text);
  latest.current = text;
  useEffect(
    () => () => {
      if (timer.current) clearTimeout(timer.current);
      if (latest.current !== saved.current) saveJournal(config, latest.current);
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [],
  );

  return (
    <Card>
      <TextInput
        value={text}
        onChangeText={onChange}
        onFocus={() => setFocused(true)}
        onBlur={() => {
          setFocused(false);
          save(text);
        }}
        placeholder="What's on your mind today? This stays just between you and this page."
        placeholderTextColor={colors.text3}
        multiline
        maxLength={5000}
        textAlignVertical="top"
        accessibilityLabel="Today's reflection"
        style={[styles.input, focused && styles.inputFocused]}
      />
      <View style={styles.foot}>
        <Text style={[styles.status, status === 'error' && { color: colors.rose }]}>
          {status === 'saved' ? 'Saved' : status === 'error' ? "Couldn't save. Check your connection." : ''}
        </Text>
        <Pressable
          onPress={() => save(text)}
          accessibilityRole="button"
          disabled={status === 'saving'}
          style={({ pressed }) => [styles.btn, pressed && { opacity: 0.85 }]}
        >
          {status === 'saving' ? <ActivityIndicator color="#fff" size="small" /> : <Text style={styles.btnText}>Save reflection</Text>}
        </Pressable>
      </View>
    </Card>
  );
}

const styles = StyleSheet.create({
  input: {
    minHeight: 120,
    borderWidth: 1.5,
    borderColor: colors.border,
    borderRadius: 14,
    paddingHorizontal: 16,
    paddingTop: 14,
    paddingBottom: 14,
    backgroundColor: colors.bg,
    fontFamily: fonts.regular,
    fontSize: 15,
    lineHeight: 23,
    color: colors.text,
  },
  inputFocused: { borderColor: colors.accent, backgroundColor: colors.surface },
  foot: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 14, gap: 12 },
  status: { fontFamily: fonts.medium, fontSize: 13, color: colors.sage, flexShrink: 1 },
  btn: { minWidth: 140, alignItems: 'center', paddingVertical: 10, paddingHorizontal: 18, borderRadius: 50, backgroundColor: colors.accent },
  btnText: { fontFamily: fonts.medium, fontSize: 14, color: '#fff' },
});

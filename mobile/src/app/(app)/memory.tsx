// Memory profile: what your counselors remember about you (who you are,
// work, goals, values, habits, triggers). You can read it, correct it, or
// delete it. With nothing saved yet, you can build it from what another AI
// (ChatGPT, Gemini) already knows about you — the website's "Import from
// another AI", step by step.
import { deleteMemory, fetchAccount, importMemory, saveMemory, type MemoryProfile } from '@kounselia/core';
import * as Clipboard from 'expo-clipboard';
import * as Haptics from 'expo-haptics';
import { router } from 'expo-router';
import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { KeyboardAvoidingView, Platform, Pressable, ScrollView, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button } from '@/components/Button';
import { Toast, useToast } from '@/components/chat/Toast';
import { FormMessage } from '@/components/FormMessage';
import { HeaderButton, ScreenHeader } from '@/components/ScreenHeader';
import { TablerIcon } from '@/components/TablerIcon';
import { useSession } from '@/session';
import { fonts, makeStyles, radius, shadows, useColors } from '@/theme';
import { showDialog } from '@/components/Dialog';
import { DetailSkeleton } from '@/components/Skeleton';

const IMPORT_PROMPT =
  'Please summarize everything you know about me as a person. Include my life timeline (dates and events), my emotional patterns and feelings about specific things in my life, my relationships, my work situation and goals, and any mental health themes. Write it as a clear factual summary.';

const TEXT_FIELDS = [
  { key: 'identity', label: 'Who you are', icon: 'user' },
  { key: 'career', label: 'Work and study', icon: 'briefcase' },
] as const;

const LIST_FIELDS = [
  { key: 'goals', label: 'Goals', icon: 'target' },
  { key: 'values', label: 'What matters to you', icon: 'heart' },
  { key: 'habits', label: 'Habits and patterns', icon: 'repeat' },
  { key: 'triggers', label: 'Things that are hard for you', icon: 'alert-circle' },
] as const;

type Draft = Record<'identity' | 'career' | 'goals' | 'values' | 'habits' | 'triggers', string>;

function toDraft(m: MemoryProfile): Draft {
  return {
    identity: m.identity,
    career: m.career,
    goals: m.goals.join(', '),
    values: m.values.join(', '),
    habits: m.habits.join(', '),
    triggers: m.triggers.join(', '),
  };
}

function splitList(text: string) {
  return text
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean);
}

export default function Memory() {
  const styles = useStyles();
  const colors = useColors();
  const { config } = useSession();
  const toast = useToast();
  const [memory, setMemory] = useState<MemoryProfile | null | undefined>(undefined);
  const [failed, setFailed] = useState(false);
  const [editing, setEditing] = useState<Draft | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [pasted, setPasted] = useState('');
  const [copied, setCopied] = useState(false);

  const load = useCallback(async () => {
    const res = await fetchAccount(config);
    if (res.ok) {
      setMemory(res.data.memory);
      setFailed(false);
    } else {
      setFailed(true);
    }
  }, [config]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
  }, [load]);

  async function save() {
    if (!editing) return;
    const next: MemoryProfile = {
      identity: editing.identity.trim(),
      career: editing.career.trim(),
      goals: splitList(editing.goals),
      values: splitList(editing.values),
      habits: splitList(editing.habits),
      triggers: splitList(editing.triggers),
    };
    setBusy(true);
    const res = await saveMemory(config, next);
    setBusy(false);
    if (!res.ok) {
      setError(res.message);
      return;
    }
    setMemory(next);
    setEditing(null);
    setError('');
    toast.show('Saved. Your counselors will use this from now on.');
  }

  function confirmDelete() {
    showDialog({
      title: 'Delete your memory profile?',
      message: 'Your counselors will no longer remember these details. Your conversations stay as they are.',
      icon: 'trash',
      buttons: [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Delete',
          style: 'destructive',
          onPress: async () => {
            const res = await deleteMemory(config);
            if (!res.ok) {
              toast.show(res.message);
              return;
            }
            setMemory(null);
            setEditing(null);
            toast.show('Memory profile deleted');
          },
        },
      ],
    });
  }

  async function copyPrompt() {
    await Clipboard.setStringAsync(IMPORT_PROMPT);
    Haptics.selectionAsync().catch(() => undefined);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  }

  async function runImport() {
    if (pasted.trim().length < 50) {
      setError('That looks too short. Paste the full reply from the other AI.');
      return;
    }
    setBusy(true);
    setError('');
    const res = await importMemory(config, pasted.trim());
    setBusy(false);
    if (!res.ok) {
      setError(res.message);
      return;
    }
    Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined);
    setPasted('');
    toast.show('Your memory profile is ready');
    load();
  }

  const header = (
    <ScreenHeader
      title="Memory profile"
      right={memory && !editing ? <HeaderButton icon="pencil" label="Edit memory profile" onPress={() => setEditing(toDraft(memory))} /> : null}
    />
  );

  if (memory === undefined) {
    return (
      <SafeAreaView style={styles.safe}>
        {header}
        {failed ? (
          <View style={styles.center}>
            <Text style={styles.notice}>We couldn’t load your memory profile. Please check your internet connection.</Text>
            <Button title="Try again" variant="ghost" onPress={load} />
          </View>
        ) : (
          <View style={styles.skeleton}>
            <DetailSkeleton />
          </View>
        )}
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      {header}
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled" keyboardDismissMode="interactive">
          <View style={styles.intro}>
            <TablerIcon name="brain" size={18} color={colors.plum} />
            <Text style={styles.introText}>
              Your counselors use this to understand you without you repeating yourself. It’s private to you and updates as you talk.
            </Text>
          </View>

          {editing ? (
            <>
              {TEXT_FIELDS.map((f) => (
                <Field key={f.key} label={f.label} value={editing[f.key]} onChange={(v) => setEditing({ ...editing, [f.key]: v })} multiline />
              ))}
              {LIST_FIELDS.map((f) => (
                <Field
                  key={f.key}
                  label={`${f.label} (separate with commas)`}
                  value={editing[f.key]}
                  onChange={(v) => setEditing({ ...editing, [f.key]: v })}
                />
              ))}
              {error ? <FormMessage tone="error" text={error} /> : null}
              <View style={styles.actions}>
                <Button title="Cancel" variant="ghost" onPress={() => setEditing(null)} style={{ flex: 1 }} />
                <Button title="Save changes" onPress={save} busy={busy} style={{ flex: 1 }} />
              </View>
            </>
          ) : memory ? (
            <>
              {TEXT_FIELDS.map((f) =>
                memory[f.key] ? (
                  <Section key={f.key} icon={f.icon} label={f.label}>
                    <Text style={styles.body}>{memory[f.key]}</Text>
                  </Section>
                ) : null,
              )}
              {LIST_FIELDS.map((f) =>
                memory[f.key].length ? (
                  <Section key={f.key} icon={f.icon} label={f.label}>
                    <View style={styles.chips}>
                      {memory[f.key].map((item) => (
                        <View key={item} style={styles.chip}>
                          <Text style={styles.chipText}>{item}</Text>
                        </View>
                      ))}
                    </View>
                  </Section>
                ) : null,
              )}
              <Button title="Edit details" variant="ghost" onPress={() => setEditing(toDraft(memory))} style={{ marginTop: 20 }} />
              <Pressable onPress={confirmDelete} accessibilityRole="button" style={styles.delete} hitSlop={6}>
                <TablerIcon name="trash" size={16} color={colors.rose} />
                <Text style={styles.deleteText}>Delete memory profile</Text>
              </Pressable>
            </>
          ) : (
            <>
              <Text style={styles.h2}>Bring what another AI knows</Text>
              <Text style={styles.p}>
                If you’ve talked to ChatGPT or Gemini about your life, they can share what they know with your counselors here. Only a short
                summary is kept; what you paste is not stored.
              </Text>

              <Step n={1} title="Copy this message">
                <Text style={styles.prompt}>{IMPORT_PROMPT}</Text>
                <Pressable onPress={copyPrompt} accessibilityRole="button" style={({ pressed }) => [styles.copy, pressed && { opacity: 0.85 }]}>
                  <TablerIcon name={copied ? 'check' : 'copy'} size={16} color={colors.accentText} />
                  <Text style={styles.copyText}>{copied ? 'Copied' : 'Copy message'}</Text>
                </Pressable>
              </Step>
              <Step n={2} title="Send it in ChatGPT or Gemini">
                <Text style={styles.p}>Open the other app, start a new chat, paste the message and send it.</Text>
              </Step>
              <Step n={3} title="Paste its reply here">
                <TextInput
                  value={pasted}
                  onChangeText={setPasted}
                  placeholder="Paste the whole reply…"
                  placeholderTextColor={colors.text3}
                  multiline
                  textAlignVertical="top"
                  accessibilityLabel="The other AI's reply"
                  style={styles.paste}
                />
              </Step>
              {error ? <FormMessage tone="error" text={error} /> : null}
              <Button title={busy ? 'Reading it… this takes a moment' : 'Build my memory profile'} onPress={runImport} busy={busy} style={{ marginTop: 8 }} />
              <Pressable onPress={() => router.back()} accessibilityRole="button" style={styles.skip}>
                <Text style={styles.skipText}>Not now. My counselors will learn as we talk.</Text>
              </Pressable>
            </>
          )}
        </ScrollView>
      </KeyboardAvoidingView>
      <Toast note={toast.note} />
    </SafeAreaView>
  );
}

function Section({ icon, label, children }: { icon: string; label: string; children: ReactNode }) {
  const styles = useStyles();
  const colors = useColors();
  return (
    <View style={styles.section}>
      <View style={styles.sectionHead}>
        <TablerIcon name={icon} size={16} color={colors.plum} />
        <Text style={styles.sectionLabel}>{label}</Text>
      </View>
      {children}
    </View>
  );
}

function Step({ n, title, children }: { n: number; title: string; children: ReactNode }) {
  const styles = useStyles();
  return (
    <View style={styles.step}>
      <View style={styles.stepNum}>
        <Text style={styles.stepNumText}>{n}</Text>
      </View>
      <View style={{ flex: 1, gap: 8 }}>
        <Text style={styles.stepTitle}>{title}</Text>
        {children}
      </View>
    </View>
  );
}

function Field({ label, value, onChange, multiline }: { label: string; value: string; onChange: (v: string) => void; multiline?: boolean }) {
  const styles = useStyles();
  const [focused, setFocused] = useState(false);
  return (
    <View style={{ marginTop: 16 }}>
      <Text style={styles.fieldLabel}>{label}</Text>
      <TextInput
        value={value}
        onChangeText={onChange}
        onFocus={() => setFocused(true)}
        onBlur={() => setFocused(false)}
        multiline={multiline}
        textAlignVertical={multiline ? 'top' : 'center'}
        accessibilityLabel={label}
        style={[styles.field, multiline && { minHeight: 90 }, focused && styles.fieldFocused]}
      />
    </View>
  );
}

const useStyles = makeStyles((colors) => ({
  safe: { flex: 1, backgroundColor: colors.bg },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 16, padding: 24 },
  skeleton: { padding: 16 },
  notice: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 22, color: colors.text2, textAlign: 'center' },
  content: { padding: 16, paddingBottom: 48 },
  intro: { flexDirection: 'row', gap: 10, backgroundColor: colors.plumLight, borderRadius: radius.sm, padding: 14 },
  introText: { flex: 1, fontFamily: fonts.regular, fontSize: 13, lineHeight: 19, color: colors.plum },
  section: {
    marginTop: 14,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.sm + 4,
    padding: 16,
    ...shadows.soft,
  },
  sectionHead: { flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 8 },
  sectionLabel: { fontFamily: fonts.semibold, fontSize: 13, color: colors.plum },
  body: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 22, color: colors.text },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  chip: { backgroundColor: colors.surface2, borderRadius: radius.pill, paddingVertical: 6, paddingHorizontal: 12 },
  chipText: { fontFamily: fonts.regular, fontSize: 14, color: colors.text },
  actions: { flexDirection: 'row', gap: 12, marginTop: 20 },
  delete: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, marginTop: 20, padding: 8 },
  deleteText: { fontFamily: fonts.medium, fontSize: 14, color: colors.rose },
  h2: { fontFamily: fonts.serifMedium, fontSize: 26, color: colors.text, marginTop: 22 },
  p: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 21, color: colors.text2, marginTop: 6 },
  step: { flexDirection: 'row', gap: 12, marginTop: 20 },
  stepNum: { width: 28, height: 28, borderRadius: 14, backgroundColor: colors.accent, alignItems: 'center', justifyContent: 'center' },
  stepNumText: { fontFamily: fonts.semibold, fontSize: 13, color: '#fff' },
  stepTitle: { fontFamily: fonts.semibold, fontSize: 15, color: colors.text, marginTop: 4 },
  prompt: {
    fontFamily: fonts.regular,
    fontSize: 13,
    lineHeight: 19,
    color: colors.text2,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 14,
    padding: 12,
  },
  copy: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    alignSelf: 'flex-start',
    paddingVertical: 8,
    paddingHorizontal: 14,
    borderRadius: radius.pill,
    backgroundColor: colors.accentLight,
  },
  copyText: { fontFamily: fonts.medium, fontSize: 13, color: colors.accentText },
  paste: {
    minHeight: 140,
    borderWidth: 1.5,
    borderColor: colors.border,
    borderRadius: 14,
    padding: 12,
    backgroundColor: colors.surface,
    fontFamily: fonts.regular,
    fontSize: 14,
    lineHeight: 20,
    color: colors.text,
  },
  skip: { alignItems: 'center', padding: 14, marginTop: 4 },
  skipText: { fontFamily: fonts.regular, fontSize: 13, color: colors.text3 },
  fieldLabel: { fontFamily: fonts.medium, fontSize: 13, color: colors.text2, marginBottom: 6 },
  field: {
    borderWidth: 1.5,
    borderColor: colors.border,
    borderRadius: radius.field,
    paddingHorizontal: 14,
    paddingVertical: 12,
    backgroundColor: colors.bg,
    fontFamily: fonts.regular,
    fontSize: 15,
    lineHeight: 21,
    color: colors.text,
  },
  fieldFocused: { borderColor: colors.accent, backgroundColor: colors.surface },
}));

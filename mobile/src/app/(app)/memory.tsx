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
import { useT } from '@/language';
import { useSession } from '@/session';
import { fonts, makeStyles, radius, shadows, useColors } from '@/theme';
import { showDialog } from '@/components/Dialog';
import { DetailSkeleton } from '@/components/Skeleton';

type FieldKey = 'identity' | 'career' | 'goals' | 'values' | 'habits' | 'triggers';

type Draft = Record<FieldKey, string>;

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
  const t = useT();
  const IMPORT_PROMPT = t('m.b.memory.import_prompt');
  const TEXT_FIELDS: { key: 'identity' | 'career'; label: string; icon: string }[] = [
    { key: 'identity', label: t('m.b.memory.identity'), icon: 'user' },
    { key: 'career', label: t('m.b.memory.career'), icon: 'briefcase' },
  ];
  const LIST_FIELDS: { key: 'goals' | 'values' | 'habits' | 'triggers'; label: string; icon: string }[] = [
    { key: 'goals', label: t('m.b.memory.goals'), icon: 'target' },
    { key: 'values', label: t('m.b.memory.values'), icon: 'heart' },
    { key: 'habits', label: t('m.b.memory.habits'), icon: 'repeat' },
    { key: 'triggers', label: t('m.b.memory.triggers'), icon: 'alert-circle' },
  ];
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
    toast.show(t('m.b.memory.saved'));
  }

  function confirmDelete() {
    showDialog({
      title: t('m.b.memory.delete_title'),
      message: t('m.b.memory.delete_body'),
      icon: 'trash',
      buttons: [
        { text: t('m.b.common.cancel'), style: 'cancel' },
        {
          text: t('m.b.common.delete'),
          style: 'destructive',
          onPress: async () => {
            const res = await deleteMemory(config);
            if (!res.ok) {
              toast.show(res.message);
              return;
            }
            setMemory(null);
            setEditing(null);
            toast.show(t('m.b.memory.deleted'));
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
      setError(t('m.b.memory.too_short'));
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
    toast.show(t('m.b.memory.ready'));
    load();
  }

  const header = (
    <ScreenHeader
      title={t('m.b.memory.title')}
      right={memory && !editing ? <HeaderButton icon="pencil" label={t('m.b.memory.edit')} onPress={() => setEditing(toDraft(memory))} /> : null}
    />
  );

  if (memory === undefined) {
    return (
      <SafeAreaView style={styles.safe}>
        {header}
        {failed ? (
          <View style={styles.center}>
            <Text style={styles.notice}>{t('m.b.memory.load_failed')}</Text>
            <Button title={t('m.b.common.try_again')} variant="ghost" onPress={load} />
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
              {t('m.b.memory.intro')}
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
                  label={t('m.b.memory.separate', { label: f.label })}
                  value={editing[f.key]}
                  onChange={(v) => setEditing({ ...editing, [f.key]: v })}
                />
              ))}
              {error ? <FormMessage tone="error" text={error} /> : null}
              <View style={styles.actions}>
                <Button title={t('m.b.common.cancel')} variant="ghost" onPress={() => setEditing(null)} style={{ flex: 1 }} />
                <Button title={t('m.b.memory.save')} onPress={save} busy={busy} style={{ flex: 1 }} />
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
              <Button title={t('m.b.memory.edit_details')} variant="ghost" onPress={() => setEditing(toDraft(memory))} style={{ marginTop: 20 }} />
              <Pressable onPress={confirmDelete} accessibilityRole="button" style={styles.delete} hitSlop={6}>
                <TablerIcon name="trash" size={16} color={colors.rose} />
                <Text style={styles.deleteText}>{t('m.b.memory.delete')}</Text>
              </Pressable>
            </>
          ) : (
            <>
              <Text style={styles.h2}>{t('m.b.memory.bring')}</Text>
              <Text style={styles.p}>
                {t('m.b.memory.bring_body')}
              </Text>

              <Step n={1} title={t('m.b.memory.step1')}>
                <Text style={styles.prompt}>{IMPORT_PROMPT}</Text>
                <Pressable onPress={copyPrompt} accessibilityRole="button" style={({ pressed }) => [styles.copy, pressed && { opacity: 0.85 }]}>
                  <TablerIcon name={copied ? 'check' : 'copy'} size={16} color={colors.accentText} />
                  <Text style={styles.copyText}>{copied ? t('m.b.memory.copied') : t('m.b.memory.copy')}</Text>
                </Pressable>
              </Step>
              <Step n={2} title={t('m.b.memory.step2')}>
                <Text style={styles.p}>{t('m.b.memory.step2_body')}</Text>
              </Step>
              <Step n={3} title={t('m.b.memory.step3')}>
                <TextInput
                  value={pasted}
                  onChangeText={setPasted}
                  placeholder={t('m.b.memory.paste_placeholder')}
                  placeholderTextColor={colors.text3}
                  multiline
                  textAlignVertical="top"
                  accessibilityLabel={t('m.b.memory.paste_label')}
                  style={styles.paste}
                />
              </Step>
              {error ? <FormMessage tone="error" text={error} /> : null}
              <Button title={busy ? t('m.b.memory.reading') : t('m.b.memory.build')} onPress={runImport} busy={busy} style={{ marginTop: 8 }} />
              <Pressable onPress={() => router.back()} accessibilityRole="button" style={styles.skip}>
                <Text style={styles.skipText}>{t('m.b.memory.not_now')}</Text>
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

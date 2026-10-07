// Growth plan (Personal Development): pick something to work on, answer a
// few questions, and follow a 30 day plan of one small task a day. The
// phone version of the website's "Growth plan" tab; both use the same
// server actions.
import { createGrowthPlan, deviceLanguage, endGrowthPlan, fetchGrowth, isRtl, makeT, markGrowthDay, reviewGrowthWeek, setGrowthReminder, type GrowthArea, type GrowthOverview, type GrowthPlan } from '@kounselia/core';
import * as Haptics from 'expo-haptics';
import { router } from 'expo-router';
import { useCallback, useEffect, useState } from 'react';
import { Pressable, ScrollView, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button } from '@/components/Button';
import { Toast, useToast } from '@/components/chat/Toast';
import { Card, SectionHead } from '@/components/dashboard/Card';
import { showDialog } from '@/components/Dialog';
import { ScreenHeader } from '@/components/ScreenHeader';
import { DetailSkeleton } from '@/components/Skeleton';
import { TablerIcon } from '@/components/TablerIcon';
import { useSession } from '@/session';
import { fonts, makeStyles, useColors } from '@/theme';

// The reminder times offered; the server accepts any hour, 0 to 23.
const REMINDER_CHOICES = [
  { hour: -1, label: '' }, // shown as "Off" in the member's language
  { hour: 7, label: '7 am' },
  { hour: 9, label: '9 am' },
  { hour: 12, label: '12 pm' },
  { hour: 18, label: '6 pm' },
  { hour: 21, label: '9 pm' },
];

export default function Growth() {
  const styles = useStyles();
  const colors = useColors();
  const { config } = useSession();
  const toast = useToast();
  const [data, setData] = useState<GrowthOverview | null>(null);
  const [failed, setFailed] = useState(false);
  const [picked, setPicked] = useState<GrowthArea | null>(null);
  const [answers, setAnswers] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [openDay, setOpenDay] = useState<number | null>(null);
  // The member's language, from the server once it answers (their phone's until then).
  const lang = data?.language ?? deviceLanguage();
  const t = makeT(lang);
  const rtl = isRtl(lang);

  const load = useCallback(async () => {
    const res = await fetchGrowth(config);
    if (res.ok) {
      setData(res.data);
      setFailed(false);
    } else {
      setFailed(true);
    }
  }, [config]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
  }, [load]);

  async function create() {
    if (!picked) return;
    const missing = picked.questions.find((q) => q.required && !(answers[q.key] ?? '').trim());
    if (missing) {
      toast.show(t('growth.answer_required', { question: missing.label }));
      return;
    }
    setBusy(true);
    const res = await createGrowthPlan(config, picked.key, answers);
    setBusy(false);
    if (!res.ok) {
      toast.show(res.message);
      return;
    }
    Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined);
    setData(res.data);
    setPicked(null);
    setAnswers({});
  }

  async function mark(plan: GrowthPlan, day: number, done: boolean) {
    const res = await markGrowthDay(config, plan.id, day, done);
    if (!res.ok) {
      toast.show(res.message);
      load();
      return;
    }
    if (done) Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined);
    setData((d) => (d ? { ...d, plan: res.data.plan } : d));
  }

  async function review(plan: GrowthPlan, week: number) {
    setBusy(true);
    const res = await reviewGrowthWeek(config, plan.id, week);
    setBusy(false);
    if (!res.ok) {
      toast.show(res.message);
      return;
    }
    Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined);
    setData((d) => (d ? { ...d, plan: res.data.plan } : d));
  }

  async function changeReminder(plan: GrowthPlan, hour: number) {
    const res = await setGrowthReminder(config, plan.id, hour);
    if (!res.ok) {
      toast.show(res.message);
      return;
    }
    setData((d) => (d ? { ...d, plan: res.data.plan } : d));
    toast.show(hour < 0 ? t('growth.reminder_off_toast') : t('growth.reminder_saved'));
  }

  function end(plan: GrowthPlan) {
    showDialog({
      title: t('growth.end_title'),
      message: t('growth.end_body'),
      icon: 'flag',
      tone: 'danger',
      buttons: [
        { text: t('growth.end_keep'), style: 'cancel' },
        {
          text: t('growth.end_confirm'),
          style: 'destructive',
          onPress: async () => {
            const res = await endGrowthPlan(config, plan.id);
            if (res.ok) {
              setData(res.data);
              setOpenDay(null);
            } else {
              toast.show(res.message);
            }
          },
        },
      ],
    });
  }

  function body() {
    if (!data) {
      return failed ? (
        <View style={styles.center}>
          <Text style={styles.notice}>{t('growth.load_failed')}</Text>
          <Button title={t('growth.try_again')} variant="ghost" onPress={load} />
        </View>
      ) : (
        <DetailSkeleton />
      );
    }

    if (data.plan) return planView(data.plan, data);

    if (picked) {
      return (
        <>
          <Text style={styles.eyebrow}>{picked.label}</Text>
          <Text style={styles.h2}>{t('growth.quick_questions')}</Text>
          {picked.questions.map((q) => (
            <View key={q.key} style={styles.q}>
              <Text style={styles.qLabel}>
                {q.label}
                {!q.required && <Text style={styles.optional}> {t('growth.optional')}</Text>}
              </Text>
              {!!q.hint && <Text style={styles.hint}>{q.hint}</Text>}
              {q.type === 'choice' ? (
                <View style={styles.choices}>
                  {q.choices?.map((c) => {
                    const on = answers[q.key] === c.key;
                    return (
                      <Pressable key={c.key} onPress={() => setAnswers((a) => ({ ...a, [q.key]: c.key }))} accessibilityRole="button" accessibilityState={{ selected: on }} style={[styles.choice, on && styles.choiceOn]}>
                        <Text style={[styles.choiceText, on && { color: colors.accentText, fontFamily: fonts.medium }]}>{c.label}</Text>
                      </Pressable>
                    );
                  })}
                </View>
              ) : (
                <TextInput
                  value={answers[q.key] ?? ''}
                  onChangeText={(t) => setAnswers((a) => ({ ...a, [q.key]: t }))}
                  multiline
                  maxLength={600}
                  style={styles.input}
                  placeholderTextColor={colors.text3}
                  accessibilityLabel={q.label}
                />
              )}
            </View>
          ))}
          <Button title={busy ? t('growth.creating') : t('growth.create')} onPress={create} busy={busy} />
          {busy && <Text style={styles.note}>{t('growth.creating_wait')}</Text>}
          <Button title={t('growth.back')} variant="ghost" onPress={() => setPicked(null)} disabled={busy} style={{ marginTop: 10 }} />
        </>
      );
    }

    const outOfPlans = data.allowance.limit > 0 && (data.allowance.remaining ?? 0) < 1;
    return (
      <>
        <Text style={styles.intro}>{t('growth.intro')}</Text>
        {outOfPlans ? (
          <Card style={{ gap: 12 }}>
            <Text style={styles.h2}>{t('growth.used_all_title')}</Text>
            <Text style={styles.intro}>{t('growth.used_all_body')}</Text>
            <Button title={t('growth.see_pro')} onPress={() => router.push('/plan')} />
          </Card>
        ) : (
          <>
            {data.areas.map((a) => (
              <Pressable key={a.key} onPress={() => { setPicked(a); setAnswers({}); }} accessibilityRole="button" style={({ pressed }) => [styles.area, pressed && { opacity: 0.9 }]}>
                <TablerIcon name={a.icon} size={24} color={colors.accentText} />
                <View style={{ flex: 1 }}>
                  <Text style={styles.areaTitle}>{a.label}</Text>
                  <Text style={styles.areaBlurb}>{a.blurb}</Text>
                </View>
                <TablerIcon name="chevron-right" size={16} color={colors.text3} />
              </Pressable>
            ))}
            {data.allowance.limit > 0 && (
              <Text style={styles.note}>{t('growth.plans_left', { remaining: data.allowance.remaining ?? 0, limit: data.allowance.limit })}</Text>
            )}
          </>
        )}
        {data.previous.length > 0 && (
          <>
            <SectionHead title={t('growth.earlier')} />
            {data.previous.map((p) => (
              <View key={p.id} style={styles.prev}>
                <Text style={styles.prevTitle}>
                  {p.title} <Text style={styles.prevMeta}>· {p.area_label}</Text>
                </Text>
                <Text style={styles.prevMeta}>
                  {t('growth.days_count', { done: p.done_count, total: p.total_days })}
                  {p.status === 'ended' ? ` · ${t('growth.ended')}` : ''}
                </Text>
              </View>
            ))}
          </>
        )}
      </>
    );
  }

  function planView(plan: GrowthPlan, overview: GrowthOverview) {
    const today = plan.today;
    const pct = Math.round((plan.done_count / plan.total_days) * 100);
    const open = plan.days.find((d) => d.day === openDay);
    return (
      <>
        <Card style={{ gap: 12 }}>
          <Text style={styles.eyebrow}>
            {plan.title} · {t('growth.day_of', { day: plan.current_day, total: plan.total_days })}
          </Text>
          {today && (
            <>
              <Text style={styles.h2}>{today.title || t('growth.today')}</Text>
              <Text style={styles.intro}>
                {today.task}
                {today.minutes ? ` (${t('growth.minutes', { minutes: today.minutes })})` : ''}
              </Text>
              <Button title={today.done ? t('growth.done_undo') : t('growth.mark_done')} variant={today.done ? 'ghost' : 'primary'} onPress={() => mark(plan, today.day, !today.done)} />
              <Button title={t('growth.talk')} variant="ghost" onPress={() => router.push({ pathname: '/chat/[slug]', params: { slug: plan.counselor_slug } })} />
            </>
          )}
          <View style={styles.bar}>
            <View style={[styles.barFill, { width: `${pct}%` }]} />
          </View>
          <View style={styles.row}>
            <Text style={styles.prevMeta}>{t('growth.days_done', { done: plan.done_count, total: plan.total_days })}</Text>
            <Text style={styles.prevMeta}>{plan.streak ? t('growth.streak', { n: plan.streak }) : t('growth.streak_start')}</Text>
          </View>
        </Card>

        {plan.review_ready != null && (
          <Card style={{ ...styles.reviewCard, gap: 10 }}>
            <Text style={styles.eyebrow}>{plan.review_ready === 5 ? t('growth.review_final') : t('growth.review_week', { week: plan.review_ready })}</Text>
            <Text style={styles.detailText}>{t('growth.review_prompt')}</Text>
            <Button title={busy ? t('growth.reviewing') : t('growth.review_button')} onPress={() => review(plan, plan.review_ready!)} busy={busy} />
          </Card>
        )}
        {[...plan.reviews].reverse().map((r) => (
          <Card key={r.week} style={{ gap: 8, marginTop: 12 }}>
            <Text style={styles.eyebrow}>{r.week === 5 ? t('growth.review_final') : t('growth.review_week', { week: r.week })}</Text>
            <Text style={styles.detailText}>{r.note}</Text>
            {r.changed > 0 && <Text style={styles.prevMeta}>{t('growth.review_changed', { n: r.changed, level: t(`growth.level_${r.level}`) })}</Text>}
          </Card>
        ))}

        <SectionHead title={t('growth.all_days')} />
        <View style={styles.grid}>
          {plan.days.map((d) => {
            const upcoming = d.state === 'upcoming';
            return (
              <Pressable
                key={d.day}
                onPress={() => setOpenDay(d.day)}
                accessibilityRole="button"
                accessibilityLabel={t('growth.day_label', { day: d.day })}
                style={[styles.dayBtn, d.done && styles.dayDone, d.state === 'today' && !d.done && styles.dayToday, upcoming && { opacity: 0.5 }]}
              >
                {d.done ? <TablerIcon name="check" size={16} color={colors.sage} /> : <Text style={[styles.dayText, d.state === 'today' && { color: colors.accentText }]}>{d.day}</Text>}
              </Pressable>
            );
          })}
        </View>
        {open && (
          <View style={styles.detail}>
            <Text style={styles.detailTitle}>
              {t('growth.day_label', { day: open.day })}
              {open.title ? `: ${open.title}` : ''}
            </Text>
            <Text style={styles.detailText}>{open.task}</Text>
            {open.state !== 'upcoming' && (
              <Pressable onPress={() => mark(plan, open.day, !open.done)} accessibilityRole="button" hitSlop={8}>
                <Text style={styles.link}>{open.done ? t('growth.mark_not_done') : t('growth.mark_done')}</Text>
              </Pressable>
            )}
          </View>
        )}
        <Text style={styles.note}>{t('growth.missed_note')}</Text>

        <Card style={{ gap: 10, marginTop: 16 }}>
          <Text style={styles.detailText}>{plan.summary}</Text>
          <Text style={styles.qLabel}>{t('growth.reminder')}</Text>
          <View style={styles.choices}>
            {REMINDER_CHOICES.map((c) => {
              const on = plan.remind_hour === c.hour;
              return (
                <Pressable key={c.hour} onPress={() => changeReminder(plan, c.hour)} accessibilityRole="button" accessibilityState={{ selected: on }} style={[styles.choice, on && styles.choiceOn]}>
                  <Text style={[styles.choiceText, on && { color: colors.accentText, fontFamily: fonts.medium }]}>{c.hour < 0 ? t('growth.reminder_off') : c.label}</Text>
                </Pressable>
              );
            })}
          </View>
          <Text style={styles.prevMeta}>{t('growth.reminder_note')}</Text>
          <Pressable onPress={() => end(plan)} accessibilityRole="button" hitSlop={8}>
            <Text style={styles.link}>{t('growth.end_plan')}</Text>
          </Pressable>
        </Card>
        {overview.previous.length > 0 && (
          <>
            <SectionHead title={t('growth.earlier')} />
            {overview.previous.map((p) => (
              <View key={p.id} style={styles.prev}>
                <Text style={styles.prevTitle}>{p.title}</Text>
                <Text style={styles.prevMeta}>{t('growth.days_count', { done: p.done_count, total: p.total_days })}</Text>
              </View>
            ))}
          </>
        )}
      </>
    );
  }

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      <ScreenHeader title={t('growth.title')} />
      <ScrollView contentContainerStyle={[styles.content, rtl && { direction: 'rtl' }]} keyboardShouldPersistTaps="handled" keyboardDismissMode="interactive">
        {body()}
      </ScrollView>
      <Toast note={toast.note} />
    </SafeAreaView>
  );
}

const useStyles = makeStyles((colors) => ({
  safe: { flex: 1, backgroundColor: colors.bg },
  content: { padding: 16, paddingBottom: 48 },
  center: { marginTop: 32, gap: 16 },
  notice: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 22, color: colors.text2, textAlign: 'center' },
  intro: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 23, color: colors.text2, marginBottom: 14 },
  eyebrow: { fontFamily: fonts.medium, fontSize: 12, letterSpacing: 0.6, textTransform: 'uppercase', color: colors.text3 },
  h2: { fontFamily: fonts.serifMedium, fontSize: 26, color: colors.text, marginBottom: 6 },
  note: { fontFamily: fonts.regular, fontSize: 13, lineHeight: 19, color: colors.text3, marginTop: 10 },
  q: { marginBottom: 18 },
  qLabel: { fontFamily: fonts.medium, fontSize: 15, lineHeight: 21, color: colors.text, marginBottom: 4 },
  optional: { fontFamily: fonts.regular, color: colors.text3 },
  hint: { fontFamily: fonts.regular, fontSize: 13, color: colors.text3, marginBottom: 8 },
  input: {
    minHeight: 80,
    borderWidth: 1.5,
    borderColor: colors.border,
    borderRadius: 12,
    padding: 12,
    fontFamily: fonts.regular,
    fontSize: 15,
    color: colors.text,
    backgroundColor: colors.surface,
    textAlignVertical: 'top',
  },
  choices: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  choice: { paddingVertical: 10, paddingHorizontal: 16, borderRadius: 50, borderWidth: 1.5, borderColor: colors.border, backgroundColor: colors.surface },
  choiceOn: { borderColor: colors.accent, backgroundColor: colors.accentLight },
  choiceText: { fontFamily: fonts.regular, fontSize: 14, color: colors.text },
  area: { flexDirection: 'row', alignItems: 'center', gap: 14, padding: 16, marginBottom: 10, borderRadius: 16, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.surface },
  areaTitle: { fontFamily: fonts.medium, fontSize: 16, color: colors.text },
  areaBlurb: { fontFamily: fonts.regular, fontSize: 13, lineHeight: 19, color: colors.text2, marginTop: 2 },
  bar: { height: 10, borderRadius: 50, backgroundColor: colors.surface2, overflow: 'hidden' },
  barFill: { height: '100%', borderRadius: 50, backgroundColor: colors.accent },
  row: { flexDirection: 'row', justifyContent: 'space-between', gap: 8 },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  dayBtn: { width: 46, height: 46, borderRadius: 12, borderWidth: 1.5, borderColor: colors.border, backgroundColor: colors.surface, alignItems: 'center', justifyContent: 'center' },
  dayDone: { backgroundColor: colors.sageLight, borderColor: colors.sage },
  dayToday: { borderColor: colors.accent },
  dayText: { fontFamily: fonts.regular, fontSize: 14, color: colors.text2 },
  reviewCard: { marginTop: 16, backgroundColor: colors.accentLight },
  detail: { marginTop: 14, padding: 16, borderRadius: 14, backgroundColor: colors.surface2, gap: 6 },
  detailTitle: { fontFamily: fonts.medium, fontSize: 15, color: colors.text },
  detailText: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 21, color: colors.text2 },
  link: { fontFamily: fonts.medium, fontSize: 14, color: colors.accentText, marginTop: 4 },
  prev: { flexDirection: 'row', justifyContent: 'space-between', gap: 10, paddingVertical: 10, borderTopWidth: 1, borderTopColor: colors.border },
  prevTitle: { flex: 1, fontFamily: fonts.regular, fontSize: 14, color: colors.text },
  prevMeta: { fontFamily: fonts.regular, fontSize: 13, color: colors.text3 },
}));

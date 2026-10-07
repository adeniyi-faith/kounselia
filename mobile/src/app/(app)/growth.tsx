// Growth plan (Personal Development): pick something to work on, answer a
// few questions, and follow a 30 day plan of one small task a day. The
// phone version of the website's "Growth plan" tab; both use the same
// server actions.
import { createGrowthPlan, endGrowthPlan, fetchGrowth, markGrowthDay, reviewGrowthWeek, setGrowthReminder, type GrowthArea, type GrowthOverview, type GrowthPlan } from '@kounselia/core';
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
  { hour: -1, label: 'Off' },
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
      toast.show(`Please answer: ${missing.label}`);
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
    toast.show(hour < 0 ? 'Reminder turned off.' : 'Reminder saved.');
  }

  function end(plan: GrowthPlan) {
    showDialog({
      title: 'End this plan?',
      message: 'Your progress is kept in your history, but you cannot pick this plan back up.',
      icon: 'flag',
      tone: 'danger',
      buttons: [
        { text: 'Keep going', style: 'cancel' },
        {
          text: 'End plan',
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
          <Text style={styles.notice}>We couldn’t load your growth plan. Please check your internet connection.</Text>
          <Button title="Try again" variant="ghost" onPress={load} />
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
          <Text style={styles.h2}>A few quick questions</Text>
          {picked.questions.map((q) => (
            <View key={q.key} style={styles.q}>
              <Text style={styles.qLabel}>
                {q.label}
                {!q.required && <Text style={styles.optional}> (optional)</Text>}
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
          <Button title={busy ? 'Writing your plan…' : 'Create my plan'} onPress={create} busy={busy} />
          {busy && <Text style={styles.note}>This can take up to a minute.</Text>}
          <Button title="Back" variant="ghost" onPress={() => setPicked(null)} disabled={busy} style={{ marginTop: 10 }} />
        </>
      );
    }

    const outOfPlans = data.allowance.limit > 0 && (data.allowance.remaining ?? 0) < 1;
    return (
      <>
        <Text style={styles.intro}>
          Pick one thing to work on. Answer a few short questions and Kounselia writes you a 30 day plan with one small task a day. Noa, your Personal Development counselor, is there whenever you want to talk it through.
        </Text>
        {outOfPlans ? (
          <Card style={{ gap: 12 }}>
            <Text style={styles.h2}>You have used your free plans</Text>
            <Text style={styles.intro}>Upgrade to Pro for unlimited growth plans.</Text>
            <Button title="See Pro" onPress={() => router.push('/plan')} />
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
              <Text style={styles.note}>
                {data.allowance.remaining} of {data.allowance.limit} plans left on your plan.
              </Text>
            )}
          </>
        )}
        {data.previous.length > 0 && (
          <>
            <SectionHead title="Earlier plans" />
            {data.previous.map((p) => (
              <View key={p.id} style={styles.prev}>
                <Text style={styles.prevTitle}>
                  {p.title} <Text style={styles.prevMeta}>· {p.area_label}</Text>
                </Text>
                <Text style={styles.prevMeta}>
                  {p.done_count}/{p.total_days} days{p.status === 'ended' ? ' · ended' : ''}
                </Text>
              </View>
            ))}
          </>
        )}
      </>
    );
  }

  function planView(plan: GrowthPlan, overview: GrowthOverview) {
    const t = plan.today;
    const pct = Math.round((plan.done_count / plan.total_days) * 100);
    const open = plan.days.find((d) => d.day === openDay);
    return (
      <>
        <Card style={{ gap: 12 }}>
          <Text style={styles.eyebrow}>
            {plan.title} · Day {plan.current_day} of {plan.total_days}
          </Text>
          {t && (
            <>
              <Text style={styles.h2}>{t.title || 'Today'}</Text>
              <Text style={styles.intro}>
                {t.task}
                {t.minutes ? ` (about ${t.minutes} min)` : ''}
              </Text>
              <Button title={t.done ? 'Done · undo' : 'Mark as done'} variant={t.done ? 'ghost' : 'primary'} onPress={() => mark(plan, t.day, !t.done)} />
              <Button title="Talk it through" variant="ghost" onPress={() => router.push({ pathname: '/chat/[slug]', params: { slug: plan.counselor_slug } })} />
            </>
          )}
          <View style={styles.bar}>
            <View style={[styles.barFill, { width: `${pct}%` }]} />
          </View>
          <View style={styles.row}>
            <Text style={styles.prevMeta}>
              {plan.done_count} of {plan.total_days} days done
            </Text>
            <Text style={styles.prevMeta}>{plan.streak ? `${plan.streak}-day streak` : 'Start your streak today'}</Text>
          </View>
        </Card>

        {plan.review_ready != null && (
          <Card style={{ ...styles.reviewCard, gap: 10 }}>
            <Text style={styles.eyebrow}>{plan.review_ready === 5 ? 'Final review' : `Week ${plan.review_ready} review`}</Text>
            <Text style={styles.detailText}>You have finished a week. Want a short look back at how it went? Kounselia can also make your next days a little easier or harder to fit you.</Text>
            <Button title={busy ? 'Writing your review…' : 'Review my week'} onPress={() => review(plan, plan.review_ready!)} busy={busy} />
          </Card>
        )}
        {[...plan.reviews].reverse().map((r) => (
          <Card key={r.week} style={{ gap: 8, marginTop: 12 }}>
            <Text style={styles.eyebrow}>{r.week === 5 ? 'Final review' : `Week ${r.week} review`}</Text>
            <Text style={styles.detailText}>{r.note}</Text>
            {r.changed > 0 && <Text style={styles.prevMeta}>Your next {r.changed} days were made {r.level}.</Text>}
          </Card>
        ))}

        <SectionHead title="All 30 days" />
        <View style={styles.grid}>
          {plan.days.map((d) => {
            const upcoming = d.state === 'upcoming';
            return (
              <Pressable
                key={d.day}
                onPress={() => setOpenDay(d.day)}
                accessibilityRole="button"
                accessibilityLabel={`Day ${d.day}${d.done ? ', done' : ''}`}
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
              Day {open.day}
              {open.title ? `: ${open.title}` : ''}
            </Text>
            <Text style={styles.detailText}>{open.task}</Text>
            {open.state !== 'upcoming' && (
              <Pressable onPress={() => mark(plan, open.day, !open.done)} accessibilityRole="button" hitSlop={8}>
                <Text style={styles.link}>{open.done ? 'Mark as not done' : 'Mark as done'}</Text>
              </Pressable>
            )}
          </View>
        )}
        <Text style={styles.note}>Missed a day? That’s fine. Your plan keeps going, and you can tick off any earlier day later.</Text>

        <Card style={{ gap: 10, marginTop: 16 }}>
          <Text style={styles.detailText}>{plan.summary}</Text>
          <Text style={styles.qLabel}>Daily reminder</Text>
          <View style={styles.choices}>
            {REMINDER_CHOICES.map((c) => {
              const on = plan.remind_hour === c.hour;
              return (
                <Pressable key={c.hour} onPress={() => changeReminder(plan, c.hour)} accessibilityRole="button" accessibilityState={{ selected: on }} style={[styles.choice, on && styles.choiceOn]}>
                  <Text style={[styles.choiceText, on && { color: colors.accentText, fontFamily: fonts.medium }]}>{c.label}</Text>
                </Pressable>
              );
            })}
          </View>
          <Text style={styles.prevMeta}>Sent as a notification at that time in our server time zone.</Text>
          <Pressable onPress={() => end(plan)} accessibilityRole="button" hitSlop={8}>
            <Text style={styles.link}>End this plan</Text>
          </Pressable>
        </Card>
        {overview.previous.length > 0 && (
          <>
            <SectionHead title="Earlier plans" />
            {overview.previous.map((p) => (
              <View key={p.id} style={styles.prev}>
                <Text style={styles.prevTitle}>{p.title}</Text>
                <Text style={styles.prevMeta}>
                  {p.done_count}/{p.total_days} days
                </Text>
              </View>
            ))}
          </>
        )}
      </>
    );
  }

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      <ScreenHeader title="Growth plan" />
      <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled" keyboardDismissMode="interactive">
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

import { createBooking, fetchBookings, fetchBookingStatus, fetchSlots, rescheduleBooking, type Professional, type Slots } from '@kounselia/core';
import * as Haptics from 'expo-haptics';
import { router, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, AppState, Pressable, ScrollView, Switch, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useBrowser } from '@/browser/BrowserProvider';
import { Button } from '@/components/Button';
import { ProfessionalAvatar } from '@/components/dashboard/ProfessionalAvatar';
import { FormMessage } from '@/components/FormMessage';
import { TablerIcon } from '@/components/TablerIcon';
import { useSession } from '@/session';
import { fonts, makeStyles, radius, useColors } from '@/theme';
import { showDialog } from '@/components/Dialog';
import { DetailSkeleton } from '@/components/Skeleton';
import { showBookedDialog } from '@/notifications';

interface Slot {
  value: string; // site time, sent back to the server
  at: Date; // shown in the member's time zone
}

function dayKey(d: Date) {
  return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;
}

// Pick a time with a professional, then pay — or, with ?reschedule=<id>,
// move an existing booking to a new time (no payment).
export default function BookProfessional() {
  const styles = useStyles();
  const colors = useColors();
  const { proId, reschedule, pro: proParam } = useLocalSearchParams<{ proId: string; reschedule?: string; pro?: string }>();
  const professionalId = Number(proId);
  const rescheduleId = reschedule ? Number(reschedule) : undefined;
  const { config } = useSession();
  const { openInApp } = useBrowser();

  // The Book tab passes the professional along; only fetch it if not.
  const [pro, setPro] = useState<Professional | null>(() => {
    try {
      return proParam ? (JSON.parse(proParam) as Professional) : null;
    } catch {
      return null;
    }
  });
  const [slots, setSlots] = useState<Slots | null>(null);
  const [failed, setFailed] = useState<string | null>(null);
  const [day, setDay] = useState<string | null>(null);
  const [picked, setPicked] = useState<Slot | null>(null);
  const [note, setNote] = useState('');
  const [weekly, setWeekly] = useState(false);
  // This member still has free sessions with them: no payment, and booked
  // one at a time (a weekly series needs a card on file).
  const isFree = !rescheduleId && !!pro?.free_label;
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // After checkout opens: the booking we're waiting on and its payment page.
  const [waiting, setWaiting] = useState<{ bookingId: number; url: string } | null>(null);
  const [checking, setChecking] = useState(false);
  const done = useRef(false);
  const checkRef = useRef<() => void>(() => undefined);

  useEffect(() => {
    (async () => {
      const times = await fetchSlots(config, professionalId, rescheduleId);
      if (!times.ok) {
        setFailed(times.message);
        return;
      }
      setSlots(times.data);
      if (!proParam) {
        const bookings = await fetchBookings(config);
        if (bookings.ok) setPro(bookings.data.professionals.find((p) => p.id === professionalId) ?? null);
      }
    })();
  }, [config, professionalId, rescheduleId, proParam]);

  // Group the free slots by day, in the member's time zone.
  const days = useMemo(() => {
    const groups = new Map<string, { label: string; slots: Slot[] }>();
    (slots?.slots ?? []).forEach((value, i) => {
      const utc = slots?.slots_utc?.[i];
      if (!utc) return;
      const at = new Date(utc);
      const key = dayKey(at);
      if (!groups.has(key)) {
        groups.set(key, { label: at.toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' }), slots: [] });
      }
      groups.get(key)!.slots.push({ value, at });
    });
    return [...groups.entries()].map(([key, g]) => ({ key, ...g }));
  }, [slots]);

  const currentDay = days.find((d) => d.key === day) ?? days[0];

  async function confirm() {
    if (!picked) return;
    setBusy(true);
    setError(null);

    if (rescheduleId) {
      const res = await rescheduleBooking(config, rescheduleId, picked.value);
      setBusy(false);
      if (!res.ok) {
        setError(res.message);
        return;
      }
      Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined);
      showDialog({
        title: 'Session moved',
        message: `Your session is now on ${picked.at.toLocaleString([], { weekday: 'long', day: 'numeric', month: 'long', hour: 'numeric', minute: '2-digit' })}.`,
        icon: 'calendar-check',
        tone: 'success',
      });
      router.back();
      return;
    }

    const res = await createBooking(config, professionalId, picked.value, note.trim(), weekly && !isFree);
    setBusy(false);
    if (!res.ok) {
      setError(res.message);
      return;
    }
    if (res.data.free) {
      // The professional's free-session offer: no payment, booked at once.
      Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined);
      showBookedDialog(
        config,
        "You're booked in",
        `Your free session is on ${picked.at.toLocaleString([], { weekday: 'long', day: 'numeric', month: 'long', hour: 'numeric', minute: '2-digit' })}.`,
      );
      router.back();
      return;
    }
    // Pay on Paystack's page. Paystack tells the server directly when the
    // payment goes through; we keep asking the server until it has.
    setWaiting({ bookingId: res.data.booking_id, url: res.data.authorization_url });
    openPayment(res.data.authorization_url);
  }

  // Paystack's page opens inside the app. When Paystack sends them back to
  // our "payment received" page, it closes by itself and we check at once.
  async function openPayment(url: string) {
    await openInApp(url, { title: 'Secure payment', closeWhen: (u) => u.includes('booking-payment-callback') });
    checkRef.current();
  }

  const checkPayment = useCallback(
    async (fromButton = false) => {
      if (!waiting || done.current) return;
      if (fromButton) setChecking(true);
      const res = await fetchBookingStatus(config, waiting.bookingId);
      if (fromButton) setChecking(false);
      if (done.current) return;
      if (res.ok && res.data.status === 'confirmed') {
        done.current = true;
        Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined);
        showBookedDialog(
          config,
          "You're booked",
          `Your session with ${pro?.name ?? 'your professional'} is confirmed. You'll find it under Book, with a Join button 10 minutes before it starts.`,
        );
        router.back();
      } else if (!res.ok && !res.offline) {
        // The hold ran out before payment arrived; the time was released.
        done.current = true;
        setWaiting(null);
        setError('This booking expired before the payment came through, so no money was taken. Please choose a time again.');
      } else if (fromButton) {
        setError("We haven't received the payment yet. If you've just paid, give it a moment and check again.");
      }
    },
    [config, pro, waiting],
  );
  useEffect(() => {
    checkRef.current = () => checkPayment();
  }, [checkPayment]);

  // While waiting: check every 4 seconds, and whenever the app comes back
  // to the front (e.g. after closing the payment page). Stops after 20 minutes.
  useEffect(() => {
    if (!waiting) return;
    const started = Date.now();
    const timer = setInterval(() => {
      if (Date.now() - started > 20 * 60 * 1000) clearInterval(timer);
      else checkPayment();
    }, 4000);
    const sub = AppState.addEventListener('change', (state) => {
      if (state === 'active') checkPayment();
    });
    return () => {
      clearInterval(timer);
      sub.remove();
    };
  }, [waiting, checkPayment]);

  if (failed !== null) {
    return (
      <SafeAreaView style={[styles.safe, styles.center]}>
        <Text style={styles.notice}>{failed || "We couldn't load this professional's times. Please check your internet connection."}</Text>
        <Button title="Back" variant="ghost" onPress={() => router.back()} />
      </SafeAreaView>
    );
  }

  if (!slots) {
    return (
      <SafeAreaView style={styles.safe}>
        <View style={{ padding: 16 }}>
          <DetailSkeleton />
        </View>
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right', 'bottom']}>
      <View style={styles.nav}>
        <Pressable onPress={() => router.back()} accessibilityRole="button" accessibilityLabel="Back" style={styles.back} hitSlop={6}>
          <TablerIcon name="arrow-left" size={20} color={colors.text} />
        </Pressable>
        <Text style={styles.navTitle}>{rescheduleId ? 'Choose a new time' : 'Book a session'}</Text>
      </View>
      <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled" keyboardDismissMode="interactive">
        {pro && (
          <>
            <View style={styles.proRow}>
              <ProfessionalAvatar pro={pro} size={56} />
              <View style={{ flex: 1 }}>
                <Text style={styles.proName}>{pro.name}</Text>
                <Text style={styles.proSpec}>
                  {pro.title}
                  {pro.specialty ? ` · ${pro.specialty}` : ''}
                </Text>
                {pro.video_provider && !rescheduleId ? (
                  <Text style={styles.proSpec}>Sessions are on {pro.video_provider}. You join from here when it&apos;s time.</Text>
                ) : null}
                {pro.price && !rescheduleId ? (
                  <Text style={styles.price}>
                    {pro.price} / {slots.session_minutes}-minute session
                  </Text>
                ) : null}
              </View>
            </View>
            {isFree ? (
              <View style={styles.freeBadge}>
                <TablerIcon name="gift" size={16} color={colors.sage} />
                <Text style={styles.freeBadgeText}>{pro.free_label}</Text>
              </View>
            ) : null}
            {pro.bio && !rescheduleId ? <Text style={styles.bio}>{pro.bio}</Text> : null}
          </>
        )}

        {days.length === 0 ? (
          <Text style={[styles.notice, { marginTop: 24 }]}>No free times in the next few weeks. Please check back soon, or choose another professional.</Text>
        ) : (
          <>
            <Text style={styles.label}>Day</Text>
            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.days}>
              {days.map((d) => {
                const on = d.key === currentDay?.key;
                return (
                  <Pressable
                    key={d.key}
                    onPress={() => {
                      Haptics.selectionAsync().catch(() => undefined);
                      setDay(d.key);
                      setPicked(null);
                    }}
                    accessibilityRole="button"
                    accessibilityState={{ selected: on }}
                    style={[styles.chip, on && styles.chipOn]}
                  >
                    <Text style={[styles.chipText, on && styles.chipTextOn]}>{d.label}</Text>
                  </Pressable>
                );
              })}
            </ScrollView>

            <Text style={styles.label}>Time</Text>
            <View style={styles.times}>
              {currentDay?.slots.map((s) => {
                const on = picked?.value === s.value;
                return (
                  <Pressable
                    key={s.value}
                    onPress={() => {
                      Haptics.selectionAsync().catch(() => undefined);
                      setPicked(s);
                    }}
                    accessibilityRole="button"
                    accessibilityState={{ selected: on }}
                    style={[styles.time, on && styles.chipOn]}
                  >
                    <Text style={[styles.chipText, on && styles.chipTextOn]}>{s.at.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}</Text>
                  </Pressable>
                );
              })}
            </View>

            {!rescheduleId && (
              <>
                <Text style={styles.label}>Anything they should know first? (optional)</Text>
                <TextInput
                  value={note}
                  onChangeText={setNote}
                  multiline
                  maxLength={500}
                  placeholder="A line or two about what you'd like to talk about."
                  placeholderTextColor={colors.text3}
                  style={styles.note}
                  textAlignVertical="top"
                />
                {!isFree && (
                  <View style={styles.weeklyRow}>
                    <View style={{ flex: 1 }}>
                      <Text style={styles.weeklyTitle}>Make it weekly</Text>
                      <Text style={styles.weeklySub}>Same day and time every week. You pay for each session as it comes.</Text>
                    </View>
                    <Switch value={weekly} onValueChange={setWeekly} trackColor={{ true: colors.accent, false: colors.border }} accessibilityLabel="Make it weekly" />
                  </View>
                )}
              </>
            )}
          </>
        )}
        {error ? <View style={{ marginTop: 16 }}><FormMessage tone="error" text={error} /></View> : null}
      </ScrollView>
      {waiting ? (
        <View style={[styles.footer, { gap: 10 }]}>
          <View style={styles.waitRow} accessibilityLiveRegion="polite">
            <ActivityIndicator color={colors.accentText} />
            <Text style={styles.waitText}>Waiting for your payment… This updates by itself once Paystack confirms it.</Text>
          </View>
          <Button title="I've paid. Check now" onPress={() => checkPayment(true)} busy={checking} />
          <Button title="Open the payment page again" variant="ghost" onPress={() => openPayment(waiting.url)} />
        </View>
      ) : days.length > 0 ? (
        <View style={styles.footer}>
          <Button
            title={rescheduleId ? 'Move my session' : isFree ? 'Book my free session' : 'Continue to payment'}
            onPress={confirm}
            busy={busy}
            disabled={!picked}
          />
        </View>
      ) : null}
    </SafeAreaView>
  );
}

const useStyles = makeStyles((colors) => ({
  safe: { flex: 1, backgroundColor: colors.bg },
  center: { alignItems: 'center', justifyContent: 'center', gap: 16, padding: 24 },
  notice: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 22, color: colors.text2, textAlign: 'center' },
  nav: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 16, paddingVertical: 10 },
  back: {
    width: 40,
    height: 40,
    borderRadius: 12,
    backgroundColor: colors.surface2,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
  },
  navTitle: { fontFamily: fonts.medium, fontSize: 16, color: colors.text },
  content: { padding: 20, paddingBottom: 32 },
  proRow: { flexDirection: 'row', alignItems: 'center', gap: 16 },
  proName: { fontFamily: fonts.serifMedium, fontSize: 24, color: colors.text },
  proSpec: { fontFamily: fonts.regular, fontSize: 13, color: colors.text2, marginTop: 2 },
  price: { fontFamily: fonts.semibold, fontSize: 13, color: colors.accentText, marginTop: 4 },
  freeBadge: {
    flexDirection: 'row',
    alignSelf: 'flex-start',
    alignItems: 'center',
    gap: 6,
    backgroundColor: colors.sageLight,
    borderRadius: radius.pill,
    paddingVertical: 6,
    paddingHorizontal: 12,
    marginTop: 14,
  },
  freeBadgeText: { fontFamily: fonts.semibold, fontSize: 13, color: colors.sage },
  bio: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 21, color: colors.text2, marginTop: 14 },
  label: { fontFamily: fonts.medium, fontSize: 13, color: colors.text2, marginTop: 24, marginBottom: 10 },
  days: { gap: 8, paddingRight: 20 },
  chip: { paddingVertical: 10, paddingHorizontal: 14, borderRadius: 50, borderWidth: 1.5, borderColor: colors.border, backgroundColor: colors.surface },
  chipOn: { borderColor: colors.accent, backgroundColor: colors.accent },
  chipText: { fontFamily: fonts.medium, fontSize: 14, color: colors.text },
  chipTextOn: { color: '#fff' },
  times: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  time: {
    width: '31%',
    flexGrow: 1,
    alignItems: 'center',
    paddingVertical: 12,
    borderRadius: radius.field,
    borderWidth: 1.5,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  note: {
    minHeight: 80,
    borderWidth: 1.5,
    borderColor: colors.border,
    borderRadius: radius.field,
    padding: 14,
    backgroundColor: colors.surface,
    fontFamily: fonts.regular,
    fontSize: 15,
    color: colors.text,
  },
  weeklyRow: { flexDirection: 'row', alignItems: 'center', gap: 12, marginTop: 20 },
  weeklyTitle: { fontFamily: fonts.medium, fontSize: 15, color: colors.text },
  weeklySub: { fontFamily: fonts.regular, fontSize: 13, lineHeight: 18, color: colors.text3, marginTop: 2 },
  waitRow: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 4 },
  waitText: { flex: 1, fontFamily: fonts.regular, fontSize: 14, lineHeight: 20, color: colors.text2 },
  footer: { paddingHorizontal: 20, paddingTop: 10, paddingBottom: 6, borderTopWidth: 1, borderTopColor: colors.border, backgroundColor: colors.bg },
}));

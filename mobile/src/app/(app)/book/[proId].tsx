import { createBooking, fetchBookings, fetchBookingStatus, fetchSlots, rescheduleBooking, type Professional, type Slots } from '@kounselia/core';
import * as Haptics from 'expo-haptics';
import { router, useLocalSearchParams } from 'expo-router';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, AppState, Pressable, ScrollView, Switch, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useBrowser } from '@/browser/BrowserProvider';
import { Button } from '@/components/Button';
import { ProfessionalAvatar } from '@/components/dashboard/ProfessionalAvatar';
import { RatingLine, ReviewsSection } from '@/components/dashboard/ProfessionalReviews';
import { FormMessage } from '@/components/FormMessage';
import { TablerIcon } from '@/components/TablerIcon';
import { useLanguage } from '@/language';
import { useSession } from '@/session';
import { fonts, makeStyles, radius, useColors } from '@/theme';
import { showDialog } from '@/components/Dialog';
import { DetailSkeleton } from '@/components/Skeleton';
import { showBookedDialog } from '@/notifications';

interface Slot {
  value: string; // site time, sent back to the server
  at: Date; // shown in the member's time zone
}

// "Dr" isn't worth repeating beside "Dr. Michele Blessing"; a title that
// says more ("Clinical Psychologist") still shows.
function titleBesideName(name: string, title: string) {
  const words = (t: string) => t.toLowerCase().split(/\s+/).map((w) => w.replace(/[^a-z]/g, '')).filter(Boolean);
  const t = words(title);
  const start = words(name).slice(0, t.length);
  return t.length && t.join(' ') === start.join(' ') ? '' : title;
}

function dayKey(d: Date) {
  return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;
}

// Pick a time with a professional, then pay — or, with ?reschedule=<id>,
// move an existing booking to a new time (no payment).
export default function BookProfessional() {
  const styles = useStyles();
  const colors = useColors();
  const { language, t } = useLanguage();
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

  // Group the free slots by day, in the member's time zone. Today gets its
  // own entry even with no slots left in it (see `today_note`) — otherwise
  // a day that's only just run out of bookable notice (see
  // kounselia_booking_lead_seconds() server-side) would silently vanish
  // from the row instead of explaining why there's nothing left today.
  const days = useMemo(() => {
    const groups = new Map<string, { date: Date; label: string; slots: Slot[]; note?: string }>();
    const dayGroup = (at: Date) => {
      const key = dayKey(at);
      if (!groups.has(key)) {
        groups.set(key, { date: at, label: at.toLocaleDateString(language, { weekday: 'short', day: 'numeric', month: 'short' }), slots: [] });
      }
      return groups.get(key)!;
    };
    (slots?.slots ?? []).forEach((value, i) => {
      const utc = slots?.slots_utc?.[i];
      if (!utc) return;
      dayGroup(new Date(utc)).slots.push({ value, at: new Date(utc) });
    });
    if (slots?.today_note) {
      dayGroup(new Date()).note = slots.today_note;
    }
    return [...groups.values()]
      .sort((a, b) => a.date.getTime() - b.date.getTime())
      .map(({ date, ...g }) => ({ key: dayKey(date), ...g }));
  }, [slots, language]);

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
        title: t('m.b.book.session_moved'),
        message: t('m.b.book.session_moved_body', {
          when: picked.at.toLocaleString(language, { weekday: 'long', day: 'numeric', month: 'long', hour: 'numeric', minute: '2-digit' }),
        }),
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
        t('m.b.book.booked_in'),
        t('m.b.book.free_booked_body', {
          when: picked.at.toLocaleString(language, { weekday: 'long', day: 'numeric', month: 'long', hour: 'numeric', minute: '2-digit' }),
        }),
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
    await openInApp(url, { title: t('m.b.book.secure_payment'), closeWhen: (u) => u.includes('booking-payment-callback') });
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
          t('m.b.book.booked'),
          t('m.b.book.confirmed_body', { name: pro?.name ?? t('m.b.book.your_professional') }),
        );
        router.back();
      } else if (!res.ok && !res.offline) {
        // The hold ran out before payment arrived; the time was released.
        done.current = true;
        setWaiting(null);
        setError(t('m.b.book.expired'));
      } else if (fromButton) {
        setError(t('m.b.book.not_received'));
      }
    },
    [config, pro, waiting, t],
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
        <Text style={styles.notice}>{failed || t('m.b.book.load_failed')}</Text>
        <Button title={t('m.b.common.back')} variant="ghost" onPress={() => router.back()} />
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
        <Pressable onPress={() => router.back()} accessibilityRole="button" accessibilityLabel={t('m.b.common.back')} style={styles.back} hitSlop={6}>
          <TablerIcon name="arrow-left" size={20} color={colors.text} />
        </Pressable>
        <Text style={styles.navTitle}>{rescheduleId ? t('m.b.book.choose_new_time') : t('m.b.book.book_a_session')}</Text>
      </View>
      <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled" keyboardDismissMode="interactive">
        {pro && (
          <>
            <View style={styles.proRow}>
              <ProfessionalAvatar pro={pro} size={56} />
              <View style={{ flex: 1 }}>
                <Text style={styles.proName}>{pro.name}</Text>
                {/* The title, unless the name already starts with it ("Dr" beside "Dr. Michele"). */}
                <Text style={styles.proSpec}>
                  {[titleBesideName(pro.name, pro.title), pro.specialty].filter(Boolean).join(' · ')}
                </Text>
                {!rescheduleId ? <RatingLine average={pro.rating} count={pro.review_count} /> : null}
                {pro.video_provider && !rescheduleId ? (
                  <Text style={styles.proSpec}>{t('m.b.book.sessions_on', { provider: pro.video_provider })}</Text>
                ) : null}
                {pro.price && !rescheduleId ? (
                  <Text style={styles.price}>
                    {t('m.b.book.price_line', { price: pro.price, minutes: slots.session_minutes })}
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
            {!rescheduleId && pro.review_count > 0 ? <ReviewsSection config={config} professionalId={professionalId} /> : null}
          </>
        )}

        {days.length === 0 ? (
          <Text style={[styles.notice, { marginTop: 24 }]}>{t('m.b.book.no_times')}</Text>
        ) : (
          <>
            <Text style={styles.label}>{t('m.b.book.day')}</Text>
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

            <Text style={styles.label}>{t('m.b.book.time')}</Text>
            {currentDay?.note && currentDay.slots.length === 0 ? (
              <Text style={styles.notice}>{currentDay.note}</Text>
            ) : (
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
                      <Text style={[styles.chipText, on && styles.chipTextOn]}>{s.at.toLocaleTimeString(language, { hour: 'numeric', minute: '2-digit' })}</Text>
                    </Pressable>
                  );
                })}
              </View>
            )}

            {!rescheduleId && (
              <>
                <Text style={styles.label}>{t('m.b.book.note_label')}</Text>
                <TextInput
                  value={note}
                  onChangeText={setNote}
                  multiline
                  maxLength={500}
                  placeholder={t('m.b.book.note_placeholder')}
                  placeholderTextColor={colors.text3}
                  style={styles.note}
                  textAlignVertical="top"
                />
                {!isFree && (
                  <View style={styles.weeklyRow}>
                    <View style={{ flex: 1 }}>
                      <Text style={styles.weeklyTitle}>{t('m.b.book.weekly')}</Text>
                      <Text style={styles.weeklySub}>{t('m.b.book.weekly_sub')}</Text>
                    </View>
                    <Switch value={weekly} onValueChange={setWeekly} trackColor={{ true: colors.accent, false: colors.border }} accessibilityLabel={t('m.b.book.weekly')} />
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
            <Text style={styles.waitText}>{t('m.b.book.waiting')}</Text>
          </View>
          <Button title={t('m.b.book.paid_check')} onPress={() => checkPayment(true)} busy={checking} />
          <Button title={t('m.b.book.open_again')} variant="ghost" onPress={() => openPayment(waiting.url)} />
        </View>
      ) : days.length > 0 ? (
        <View style={styles.footer}>
          <Button
            title={rescheduleId ? t('m.b.book.move') : isFree ? t('m.b.book.book_free') : t('m.b.book.continue_payment')}
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

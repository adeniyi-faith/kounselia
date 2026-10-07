// Moving a booked session to another of the professional's own open
// times (the website's "Reschedule" on a booking). The client is told by
// the server, as on the website.
import { fetchSlots, rescheduleBooking, type KounseliaConfig, type ProBooking } from '@kounselia/core';
import * as Haptics from 'expo-haptics';
import { useEffect, useMemo, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, Text, View } from 'react-native';
import { Button } from '@/components/Button';
import { FormMessage } from '@/components/FormMessage';
import { Sheet } from '@/components/Sheet';
import { useLanguage } from '@/language';
import { fonts, makeStyles, useColors } from '@/theme';

interface Slot {
  value: string; // site time, sent back to the server
  at: Date;
}

export function RescheduleSheet({
  config,
  professionalId,
  booking,
  onClose,
}: {
  config: KounseliaConfig;
  professionalId: number;
  booking: ProBooking | null;
  // `message` once it's moved, so the screen can say so and reload.
  onClose: (message?: string) => void;
}) {
  const styles = useStyles();
  const colors = useColors();
  const { language, t } = useLanguage();
  const [slots, setSlots] = useState<Slot[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [day, setDay] = useState<string | null>(null);
  const [picked, setPicked] = useState<Slot | null>(null);
  const [busy, setBusy] = useState(false);
  // Start fresh for each session it's opened for.
  const [shownFor, setShownFor] = useState<number | null>(null);
  if (booking && shownFor !== booking.id) {
    setShownFor(booking.id);
    setSlots(null);
    setPicked(null);
    setDay(null);
    setError(null);
  }

  useEffect(() => {
    if (!booking) return;
    let live = true;
    fetchSlots(config, professionalId, booking.id).then((res) => {
      if (!live) return;
      if (!res.ok) {
        setError(res.message);
        setSlots([]);
        return;
      }
      const list: Slot[] = [];
      res.data.slots.forEach((value, i) => {
        const utc = res.data.slots_utc?.[i];
        if (utc) list.push({ value, at: new Date(utc) });
      });
      setSlots(list);
    });
    return () => {
      live = false;
    };
  }, [booking, config, professionalId]);

  // Grouped by day, in the professional's own time zone.
  const days = useMemo(() => {
    const groups = new Map<string, { label: string; slots: Slot[] }>();
    for (const s of slots ?? []) {
      const key = s.at.toDateString();
      if (!groups.has(key)) groups.set(key, { label: s.at.toLocaleDateString(language, { weekday: 'short', day: 'numeric', month: 'short' }), slots: [] });
      groups.get(key)!.slots.push(s);
    }
    return [...groups.entries()].map(([key, g]) => ({ key, ...g }));
  }, [slots, language]);
  const current = days.find((d) => d.key === day) ?? days[0];

  async function confirm() {
    if (!booking || !picked) return;
    setBusy(true);
    setError(null);
    const res = await rescheduleBooking(config, booking.id, picked.value);
    setBusy(false);
    if (!res.ok) {
      setError(res.message);
      return;
    }
    Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined);
    onClose(res.data.message || t('m.pro.rescheduled'));
  }

  return (
    <Sheet visible={!!booking} title={t('m.pro.reschedule')} onClose={() => onClose()}>
      {booking ? <Text style={styles.sub}>{t('m.pro.reschedule_body', { name: booking.client_name })}</Text> : null}
      {slots === null ? (
        <ActivityIndicator color={colors.accentText} style={{ marginVertical: 24 }} />
      ) : days.length === 0 ? (
        <Text style={styles.empty}>{error ? '' : t('m.pro.reschedule_none')}</Text>
      ) : (
        <>
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.row}>
            {days.map((d) => {
              const on = d.key === current?.key;
              return (
                <Pressable key={d.key} onPress={() => setDay(d.key)} accessibilityRole="button" accessibilityState={{ selected: on }} style={[styles.chip, on && styles.chipOn]}>
                  <Text style={[styles.chipText, on && styles.chipTextOn]}>{d.label}</Text>
                </Pressable>
              );
            })}
          </ScrollView>
          <View style={styles.times}>
            {current?.slots.map((s) => {
              const on = picked?.value === s.value;
              return (
                <Pressable key={s.value} onPress={() => setPicked(s)} accessibilityRole="button" accessibilityState={{ selected: on }} style={[styles.chip, on && styles.chipOn]}>
                  <Text style={[styles.chipText, on && styles.chipTextOn]}>{s.at.toLocaleTimeString(language, { hour: 'numeric', minute: '2-digit' })}</Text>
                </Pressable>
              );
            })}
          </View>
        </>
      )}
      {error ? <FormMessage tone="error" text={error} /> : null}
      <Button title={t('m.pro.reschedule_confirm')} onPress={confirm} busy={busy} disabled={!picked} style={{ marginBottom: 8 }} />
    </Sheet>
  );
}

const useStyles = makeStyles((colors) => ({
  sub: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 21, color: colors.text2, marginBottom: 14 },
  empty: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 21, color: colors.text3, textAlign: 'center', marginVertical: 20 },
  row: { gap: 8, paddingBottom: 12 },
  times: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 18 },
  chip: { paddingVertical: 9, paddingHorizontal: 14, borderRadius: 10, borderWidth: 1.5, borderColor: colors.border, backgroundColor: colors.bg },
  chipOn: { borderColor: colors.accent, backgroundColor: colors.accentLight },
  chipText: { fontFamily: fonts.medium, fontSize: 13, color: colors.text },
  chipTextOn: { color: colors.accentText },
}));

import { cancelBooking, cancelSeries, saveAvailability, setSessionVideoLink, type ProBooking } from '@kounselia/core';
import * as Haptics from 'expo-haptics';
import { router } from 'expo-router';
import { useEffect, useMemo, useState } from 'react';
import { ActivityIndicator, Pressable, RefreshControl, ScrollView, Switch, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button } from '@/components/Button';
import { Toast, useToast } from '@/components/chat/Toast';
import { EmptyState, SectionHead } from '@/components/dashboard/Card';
import { joinSession } from '@/components/dashboard/joinSession';
import { sessionWhen } from '@/components/dashboard/when';
import { showDialog } from '@/components/Dialog';
import { FormMessage } from '@/components/FormMessage';
import { Sheet } from '@/components/Sheet';
import { ListSkeleton } from '@/components/Skeleton';
import { TablerIcon } from '@/components/TablerIcon';
import { TextField } from '@/components/TextField';
import { RescheduleSheet } from '@/components/pro/RescheduleSheet';
import { ChoiceSheet, Panel, Pill, SmallButton } from '@/components/pro/ui';
import { usePro } from '@/professional/ProDashboard';
import { useSession } from '@/session';
import { fonts, makeStyles, useColors } from '@/theme';

const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

// Every half hour, as the website's time boxes allow.
const TIMES = Array.from({ length: 48 }, (_, i) => `${String(Math.floor(i / 2)).padStart(2, '0')}:${i % 2 ? '30' : '00'}`);

function timeLabel(hhmm: string): string {
  const [h, m] = hhmm.split(':').map(Number);
  const d = new Date(2000, 0, 1, h, m);
  return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
}

interface DayRow {
  on: boolean;
  start: string;
  end: string;
}

// Bookings (the website's pro dashboard "Bookings"): the weekly hours
// clients can book, and every upcoming session with Join, Message,
// Reschedule, a session's own video link, and Cancel.
export default function ProBookings() {
  const styles = useStyles();
  const colors = useColors();
  const { data, failed, reload } = usePro();
  const [refreshing, setRefreshing] = useState(false);
  const toast = useToast();

  async function refresh() {
    setRefreshing(true);
    await reload();
    setRefreshing(false);
  }

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.accentText} />}
      >
        <Text style={styles.title} accessibilityRole="header">
          Bookings
        </Text>
        <Text style={styles.sub}>Client session requests</Text>
        {!data ? (
          failed ? (
            <View style={styles.center}>
              <Text style={styles.notice}>We couldn’t load your bookings. Please check your internet connection.</Text>
              <Button title="Try again" variant="ghost" onPress={refresh} busy={refreshing} />
            </View>
          ) : (
            <View style={{ marginTop: 16 }}>
              <ListSkeleton rows={4} square />
            </View>
          )
        ) : (
          <>
            <Availability
              key={JSON.stringify(data.availability)}
              initial={data.availability}
              onSaved={(message) => {
                toast.show(message);
                reload();
              }}
            />
            <SectionHead title="Upcoming sessions" note={`${data.bookings.length} scheduled`} />
            {data.bookings.length === 0 ? (
              <EmptyState>
                <TablerIcon name="calendar-event" size={28} color={colors.text3} />
                <Text style={styles.notice}>
                  No sessions booked yet. Once your availability is set and you’re verified, clients booking an open slot will show up right here.
                </Text>
              </EmptyState>
            ) : (
              <Sessions bookings={data.bookings} professionalId={data.application.id} videoAllowed={data.video.allowed} toast={toast.show} reload={reload} />
            )}
          </>
        )}
      </ScrollView>
      <Toast note={toast.note} />
    </SafeAreaView>
  );
}

// ---- Weekly availability ------------------------------------------------------

function Availability({ initial, onSaved }: { initial: { day: number; start: string; end: string }[]; onSaved: (message: string) => void }) {
  const styles = useStyles();
  const colors = useColors();
  const { config } = useSession();
  const [rows, setRows] = useState<DayRow[]>(() =>
    DAYS.map((_, d) => {
      const rule = initial.find((r) => r.day === d);
      return rule ? { on: true, start: rule.start, end: rule.end } : { on: false, start: '09:00', end: '17:00' };
    }),
  );
  const [picking, setPicking] = useState<{ day: number; which: 'start' | 'end' } | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function change(day: number, patch: Partial<DayRow>) {
    setRows((rs) => rs.map((r, i) => (i === day ? { ...r, ...patch } : r)));
  }

  async function save() {
    const bad = rows.findIndex((r) => r.on && r.end <= r.start);
    if (bad !== -1) {
      setError(`${DAYS[bad]}: the end time needs to be after the start time.`);
      return;
    }
    setBusy(true);
    setError(null);
    const rules = rows.flatMap((r, day) => (r.on ? [{ day, start: r.start, end: r.end }] : []));
    const res = await saveAvailability(config, rules);
    setBusy(false);
    if (!res.ok) {
      setError(res.message);
      return;
    }
    Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined);
    onSaved(res.data.message || 'Availability saved.');
  }

  const pickingRow = picking ? rows[picking.day] : null;
  return (
    <Panel title="Your weekly availability" note="Clients can only book inside these hours, the same hours as on the website.">
      {rows.map((r, day) => (
        <View key={DAYS[day]} style={styles.dayRow}>
          <Switch
            value={r.on}
            onValueChange={(on) => change(day, { on })}
            trackColor={{ true: colors.accent, false: colors.surface3 }}
            accessibilityLabel={`Available on ${DAYS[day]}`}
          />
          <Text style={[styles.dayName, !r.on && { color: colors.text3 }]}>{DAYS[day].slice(0, 3)}</Text>
          {r.on ? (
            <View style={styles.dayTimes}>
              <Pressable onPress={() => setPicking({ day, which: 'start' })} accessibilityRole="button" accessibilityLabel={`${DAYS[day]} starts at ${timeLabel(r.start)}`} style={styles.time}>
                <Text style={styles.timeText}>{timeLabel(r.start)}</Text>
              </Pressable>
              <Text style={styles.to}>to</Text>
              <Pressable onPress={() => setPicking({ day, which: 'end' })} accessibilityRole="button" accessibilityLabel={`${DAYS[day]} ends at ${timeLabel(r.end)}`} style={styles.time}>
                <Text style={styles.timeText}>{timeLabel(r.end)}</Text>
              </Pressable>
            </View>
          ) : (
            <Text style={styles.off}>Not available</Text>
          )}
        </View>
      ))}
      {error ? <FormMessage tone="error" text={error} /> : null}
      <Button title="Save availability" onPress={save} busy={busy} style={{ marginTop: 8 }} />
      <ChoiceSheet
        visible={!!picking}
        title={picking ? `${DAYS[picking.day]} ${picking.which === 'start' ? 'from' : 'until'}` : ''}
        choices={TIMES.map((t) => ({ value: t, label: timeLabel(t) }))}
        selected={pickingRow && picking ? pickingRow[picking.which] : null}
        onPick={(t) => picking && change(picking.day, { [picking.which]: t })}
        onClose={() => setPicking(null)}
      />
    </Panel>
  );
}

// ---- Upcoming sessions --------------------------------------------------------

function Sessions({
  bookings,
  professionalId,
  videoAllowed,
  toast,
  reload,
}: {
  bookings: ProBooking[];
  professionalId: number;
  videoAllowed: boolean;
  toast: (text: string) => void;
  reload: () => Promise<void>;
}) {
  const styles = useStyles();
  const colors = useColors();
  const { config } = useSession();
  const [joining, setJoining] = useState<number | null>(null);
  const [rescheduling, setRescheduling] = useState<ProBooking | null>(null);
  const [linkFor, setLinkFor] = useState<ProBooking | null>(null);
  // The screen is often left open up to session time: Join appears and
  // disappears on its own, without a reload.
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 30000);
    return () => clearInterval(timer);
  }, []);
  const canJoin = useMemo(
    () => (b: ProBooking) => {
      if (!b.join_opens_utc || !b.join_closes_utc) return b.joinable;
      return now >= Date.parse(b.join_opens_utc) && now <= Date.parse(b.join_closes_utc);
    },
    [now],
  );

  async function join(b: ProBooking) {
    setJoining(b.id);
    const problem = await joinSession(config, b.id);
    setJoining(null);
    if (problem) toast(problem);
  }

  function confirmCancel(b: ProBooking, wholeSeries: boolean) {
    showDialog({
      title: wholeSeries ? 'Cancel the weekly sessions?' : 'Cancel this session?',
      message: wholeSeries
        ? `This cancels every upcoming weekly session with ${b.client_name}. They’ll be told.`
        : `Your session with ${b.client_name} on ${sessionWhen(b.start_utc)} will be cancelled, and they’ll be told.`,
      icon: 'calendar-x',
      buttons: [
        { text: 'Keep it', style: 'cancel' },
        {
          text: wholeSeries ? 'Cancel weekly' : 'Cancel session',
          style: 'destructive',
          onPress: async () => {
            const res = wholeSeries ? await cancelSeries(config, b.series_id) : await cancelBooking(config, b.id);
            toast(res.ok ? res.data.message || 'Cancelled.' : res.message);
            reload();
          },
        },
      ],
    });
  }

  return (
    <>
      {bookings.map((b) => (
        <View key={b.id} style={styles.session}>
          <View style={styles.sessionTop}>
            <View style={styles.sessionIcon}>
              <TablerIcon name="calendar-event" size={18} color={colors.sage} />
            </View>
            <View style={{ flex: 1, minWidth: 0 }}>
              <Text style={styles.when}>{sessionWhen(b.start_utc)}</Text>
              <Text style={styles.with}>{b.client_name}</Text>
            </View>
          </View>
          {b.series_id || b.is_free ? (
            <View style={styles.tags}>
              {b.series_id ? <Pill label="Weekly" /> : null}
              {b.is_free ? <Pill label="Free" tone="sage" /> : null}
            </View>
          ) : null}
          {b.client_note ? <Text style={styles.note}>“{b.client_note}”</Text> : null}
          {b.video_provider ? (
            <Text style={styles.videoNote}>
              On {b.video_provider}
              {b.video_link ? ' (link for this session)' : ''}
            </Text>
          ) : null}
          <View style={styles.actions}>
            {canJoin(b) && (
              <Pressable onPress={() => join(b)} accessibilityRole="button" style={styles.joinBtn}>
                {joining === b.id ? <ActivityIndicator size="small" color="#fff" /> : <TablerIcon name="video" size={16} color="#fff" />}
                <Text style={styles.joinText}>Join</Text>
              </Pressable>
            )}
            <SmallButton
              icon="message-circle"
              label="Message"
              onPress={() => router.push({ pathname: '/booking/[id]', params: { id: String(b.id), name: b.client_name } })}
            />
            <SmallButton icon="calendar-cog" label="Reschedule" onPress={() => setRescheduling(b)} />
            {videoAllowed && <SmallButton icon="link" label="Video link" onPress={() => setLinkFor(b)} />}
            <SmallButton label="Cancel" tone="danger" onPress={() => confirmCancel(b, false)} />
            {b.series_id ? <SmallButton label="Cancel weekly" tone="danger" onPress={() => confirmCancel(b, true)} /> : null}
          </View>
        </View>
      ))}
      <RescheduleSheet
        config={config}
        professionalId={professionalId}
        booking={rescheduling}
        onClose={(message) => {
          setRescheduling(null);
          if (message) {
            toast(message);
            reload();
          }
        }}
      />
      <SessionLinkSheet
        booking={linkFor}
        onClose={(message) => {
          setLinkFor(null);
          if (message) {
            toast(message);
            reload();
          }
        }}
      />
    </>
  );
}

// A different meeting link for just one session (website: "Video link").
function SessionLinkSheet({ booking, onClose }: { booking: ProBooking | null; onClose: (message?: string) => void }) {
  const styles = useStyles();
  const { config } = useSession();
  const [link, setLink] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [shownFor, setShownFor] = useState<number | null>(null);
  if (booking && shownFor !== booking.id) {
    setShownFor(booking.id);
    setLink(booking.video_link);
    setError(null);
  }

  async function save(value: string) {
    if (!booking) return;
    setBusy(true);
    setError(null);
    const res = await setSessionVideoLink(config, booking.id, value.trim());
    setBusy(false);
    if (!res.ok) {
      setError(res.message);
      return;
    }
    onClose(res.data.message);
  }

  return (
    <Sheet visible={!!booking} title="Video link" onClose={() => onClose()}>
      <Text style={styles.sheetText}>
        A Zoom, Google Meet, Teams or Whereby link for just this session. Your client only gets it by pressing Join at session time. Leave it empty to use your
        usual setting.
      </Text>
      <TextField label="Meeting link" value={link} onChangeText={setLink} placeholder="https://zoom.us/j/…" autoCapitalize="none" keyboardType="url" autoCorrect={false} />
      {error ? <FormMessage tone="error" text={error} /> : null}
      <Button title="Save link" onPress={() => save(link)} busy={busy} />
      {booking?.video_link ? <Button title="Use my usual setting" variant="ghost" onPress={() => save('')} style={{ marginTop: 10 }} /> : null}
      <View style={{ height: 8 }} />
    </Sheet>
  );
}

const useStyles = makeStyles((colors) => ({
  safe: { flex: 1, backgroundColor: colors.bg },
  content: { padding: 16, paddingBottom: 40 },
  title: { fontFamily: fonts.serifMedium, fontSize: 28, color: colors.text, marginTop: 8 },
  sub: { fontFamily: fonts.regular, fontSize: 13, color: colors.text3, marginTop: 2 },
  center: { marginTop: 24, gap: 16 },
  notice: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 22, color: colors.text2, textAlign: 'center' },
  dayRow: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 8, borderBottomWidth: 1, borderBottomColor: colors.border },
  dayName: { width: 40, fontFamily: fonts.medium, fontSize: 14, color: colors.text },
  dayTimes: { flex: 1, flexDirection: 'row', alignItems: 'center', gap: 6, justifyContent: 'flex-end' },
  time: { paddingVertical: 8, paddingHorizontal: 10, borderRadius: 10, borderWidth: 1.5, borderColor: colors.border, backgroundColor: colors.bg },
  timeText: { fontFamily: fonts.medium, fontSize: 13, color: colors.text },
  to: { fontFamily: fonts.regular, fontSize: 12, color: colors.text3 },
  off: { flex: 1, textAlign: 'right', fontFamily: fonts.regular, fontSize: 13, color: colors.text3 },
  session: { padding: 16, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: 16, marginBottom: 10 },
  sessionTop: { flexDirection: 'row', alignItems: 'center', gap: 14 },
  sessionIcon: { width: 40, height: 40, borderRadius: 12, backgroundColor: colors.sageLight, alignItems: 'center', justifyContent: 'center' },
  when: { fontFamily: fonts.semibold, fontSize: 15, color: colors.text },
  with: { fontFamily: fonts.regular, fontSize: 13, color: colors.text2, marginTop: 2 },
  tags: { flexDirection: 'row', gap: 6, marginTop: 10 },
  note: { fontFamily: fonts.regular, fontStyle: 'italic', fontSize: 13, lineHeight: 19, color: colors.text3, marginTop: 10 },
  videoNote: { fontFamily: fonts.regular, fontSize: 13, color: colors.text2, marginTop: 8 },
  actions: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 14 },
  joinBtn: { flexDirection: 'row', alignItems: 'center', gap: 6, paddingVertical: 8, paddingHorizontal: 14, borderRadius: 50, backgroundColor: colors.sageFill },
  joinText: { fontFamily: fonts.medium, fontSize: 13, color: '#fff' },
  sheetText: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 21, color: colors.text2, marginBottom: 16 },
}));

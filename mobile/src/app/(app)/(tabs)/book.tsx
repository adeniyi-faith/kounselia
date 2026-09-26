import { cancelBooking, cancelSeries, fetchBookingRoom, fetchBookings, type BookingsData, type Professional, type UpcomingBooking } from '@kounselia/core';
import { router, useFocusEffect } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { useCallback, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button } from '@/components/Button';
import { Toast, useToast } from '@/components/chat/Toast';
import { EmptyState, SectionHead } from '@/components/dashboard/Card';
import { ProfessionalAvatar } from '@/components/dashboard/ProfessionalAvatar';
import { RateSheet } from '@/components/dashboard/RateSheet';
import { sessionWhen } from '@/components/dashboard/when';
import { TablerIcon } from '@/components/TablerIcon';
import { useSession } from '@/session';
import { colors, fonts, radius, shadows } from '@/theme';

// Sessions with licensed professionals — the website dashboard's
// "Your upcoming sessions", "Past sessions" and "Find a professional".
export default function Book() {
  const { config } = useSession();
  const [data, setData] = useState<BookingsData | null>(null);
  const [failed, setFailed] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [rating, setRating] = useState<{ id: number; pro_name: string } | null>(null);
  const [joining, setJoining] = useState<number | null>(null);
  const toast = useToast();

  const load = useCallback(async () => {
    const res = await fetchBookings(config);
    if (res.ok) {
      setData(res.data);
      setFailed(false);
    } else {
      setFailed(true);
    }
  }, [config]);

  useFocusEffect(
    useCallback(() => {
      load();
    }, [load]),
  );

  async function refresh() {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  }

  async function join(b: UpcomingBooking) {
    setJoining(b.id);
    const res = await fetchBookingRoom(config, b.id);
    setJoining(null);
    if (!res.ok) {
      toast.show(res.message);
      return;
    }
    await WebBrowser.openBrowserAsync(res.data.url, {
      toolbarColor: colors.navy,
      controlsColor: '#fff',
      dismissButtonStyle: 'done',
      presentationStyle: WebBrowser.WebBrowserPresentationStyle.FULL_SCREEN,
    });
  }

  function confirmCancel(b: UpcomingBooking, wholeSeries: boolean) {
    Alert.alert(
      wholeSeries ? 'Cancel your weekly sessions?' : 'Cancel this session?',
      wholeSeries
        ? `This cancels every upcoming weekly session with ${b.pro_name}.`
        : `Your session with ${b.pro_name} on ${sessionWhen(b.start_utc)} will be cancelled.`,
      [
        { text: 'Keep it', style: 'cancel' },
        {
          text: wholeSeries ? 'Cancel weekly' : 'Cancel session',
          style: 'destructive',
          onPress: async () => {
            const res = wholeSeries ? await cancelSeries(config, b.series_id) : await cancelBooking(config, b.id);
            toast.show(res.ok ? res.data.message || 'Cancelled.' : res.message);
            load();
          },
        },
      ],
    );
  }

  function moreFor(b: UpcomingBooking) {
    const buttons: { text: string; style?: 'cancel' | 'destructive'; onPress?: () => void }[] = [
      { text: 'Reschedule', onPress: () => router.push({ pathname: '/book/[proId]', params: { proId: String(b.professional_id), reschedule: String(b.id) } }) },
      { text: 'Cancel session', style: 'destructive', onPress: () => confirmCancel(b, false) },
    ];
    if (b.series_id) buttons.push({ text: 'Cancel weekly sessions', style: 'destructive', onPress: () => confirmCancel(b, true) });
    buttons.push({ text: 'Close', style: 'cancel' });
    Alert.alert(`${b.pro_name}`, sessionWhen(b.start_utc), buttons);
  }

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.accent} />}
      >
        <Text style={styles.title} accessibilityRole="header">
          Sessions with professionals
        </Text>
        {!data ? (
          failed ? (
            <View style={styles.center}>
              <Text style={styles.notice}>We couldn't load your sessions. Please check your internet connection.</Text>
              <Button title="Try again" variant="ghost" onPress={refresh} busy={refreshing} />
            </View>
          ) : (
            <ActivityIndicator color={colors.accent} style={{ marginTop: 40 }} />
          )
        ) : (
          <>
            <SectionHead title="Your upcoming sessions" />
            {data.upcoming.length === 0 ? (
              <EmptyState>
                <TablerIcon name="calendar-event" size={28} color={colors.text3} />
                <Text style={styles.notice}>No sessions booked yet. Find a licensed professional below and pick a time that works for you.</Text>
              </EmptyState>
            ) : (
              data.upcoming.map((b) => (
                <View key={b.id} style={styles.row}>
                  <View style={styles.rowTop}>
                    <View style={[styles.av, { backgroundColor: colors.goldLight }]}>
                      <TablerIcon name="calendar-event" size={18} color={colors.gold} />
                    </View>
                    <View style={styles.meta}>
                      <Text style={styles.name}>
                        {b.pro_name}
                        {b.pro_title ? ` · ${b.pro_title}` : ''}
                      </Text>
                      <Text style={styles.sub}>{sessionWhen(b.start_utc)}</Text>
                    </View>
                    {b.series_id ? <Text style={styles.weekly}>Weekly</Text> : null}
                  </View>
                  <View style={styles.actions}>
                    {b.joinable && (
                      <Pressable onPress={() => join(b)} accessibilityRole="button" style={[styles.action, styles.joinAction]}>
                        {joining === b.id ? <ActivityIndicator size="small" color="#fff" /> : <TablerIcon name="video" size={16} color="#fff" />}
                        <Text style={[styles.actionText, { color: '#fff' }]}>Join</Text>
                      </Pressable>
                    )}
                    <Pressable
                      onPress={() => router.push({ pathname: '/booking/[id]', params: { id: String(b.id), name: b.pro_name } })}
                      accessibilityRole="button"
                      style={styles.action}
                    >
                      <TablerIcon name="message" size={16} color={colors.accent} />
                      <Text style={styles.actionText}>Message</Text>
                    </Pressable>
                    <Pressable onPress={() => moreFor(b)} accessibilityRole="button" accessibilityLabel="Reschedule or cancel" style={styles.action}>
                      <TablerIcon name="calendar-cog" size={16} color={colors.accent} />
                      <Text style={styles.actionText}>Change</Text>
                    </Pressable>
                  </View>
                </View>
              ))
            )}

            {data.past.length > 0 && (
              <>
                <SectionHead title="Past sessions" />
                {data.past.map((b) => (
                  <View key={b.id} style={[styles.row, styles.rowTop]}>
                    <View style={[styles.av, { backgroundColor: colors.accentLight }]}>
                      <TablerIcon name="check" size={18} color={colors.accent} />
                    </View>
                    <View style={styles.meta}>
                      <Text style={styles.name}>
                        {b.pro_name}
                        {b.pro_title ? ` · ${b.pro_title}` : ''}
                      </Text>
                      <Text style={styles.sub}>{sessionWhen(b.start_utc, true)}</Text>
                    </View>
                    {b.review_rating ? (
                      <Text style={styles.stars} accessibilityLabel={`You rated it ${b.review_rating} out of 5`}>
                        {'★'.repeat(b.review_rating)}
                        {'☆'.repeat(5 - b.review_rating)}
                      </Text>
                    ) : (
                      <Pressable onPress={() => setRating({ id: b.id, pro_name: b.pro_name })} accessibilityRole="button" hitSlop={6}>
                        <Text style={styles.link}>Rate</Text>
                      </Pressable>
                    )}
                  </View>
                ))}
              </>
            )}

            <SectionHead title="Find a professional" note="Licensed and verified" />
            {data.professionals.length === 0 ? (
              <EmptyState>
                <TablerIcon name="users" size={28} color={colors.text3} />
                <Text style={styles.notice}>No verified professionals are available to book just yet. Check back soon.</Text>
              </EmptyState>
            ) : (
              <View style={styles.grid}>
                {data.professionals.map((p) => (
                  <ProfessionalTile key={p.id} pro={p} />
                ))}
              </View>
            )}
          </>
        )}
      </ScrollView>
      <RateSheet
        config={config}
        booking={rating}
        onClose={(rated) => {
          setRating(null);
          if (rated) {
            toast.show('Thanks for the feedback.');
            load();
          }
        }}
      />
      <Toast message={toast.message} />
    </SafeAreaView>
  );
}

function ProfessionalTile({ pro }: { pro: Professional }) {
  return (
    <Pressable
      onPress={() => router.push({ pathname: '/book/[proId]', params: { proId: String(pro.id) } })}
      accessibilityRole="button"
      accessibilityLabel={`${pro.name}, ${pro.title}. ${pro.price ? `${pro.price} per session.` : ''} Book a session`}
      style={({ pressed }) => [styles.tile, pressed && { transform: [{ scale: 0.97 }] }]}
    >
      <ProfessionalAvatar pro={pro} size={48} />
      <Text style={styles.tileName} numberOfLines={2}>
        {pro.name}
      </Text>
      <Text style={styles.tileSpec} numberOfLines={2}>
        {pro.title}
        {pro.specialty ? ` · ${pro.specialty}` : ''}
      </Text>
      <Text style={[styles.tileSpec, { color: pro.review_count ? colors.gold : colors.text3, marginTop: 4 }]}>
        {pro.review_count ? `★ ${pro.rating.toFixed(1)} (${pro.review_count})` : 'No reviews yet'}
      </Text>
      {pro.price ? <Text style={styles.price}>{pro.price} / session</Text> : null}
      {pro.full_price ? (
        // Pro members pay less; show what it would have been, as the website does.
        <Text style={styles.proPrice}>
          <Text style={styles.fullPrice}>{pro.full_price}</Text> Pro price
        </Text>
      ) : null}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.bg },
  content: { padding: 16, paddingBottom: 40 },
  title: { fontFamily: fonts.serifMedium, fontSize: 28, color: colors.text, marginTop: 8 },
  center: { marginTop: 24, gap: 16 },
  notice: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 22, color: colors.text2, textAlign: 'center' },
  row: { padding: 16, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: 14, marginBottom: 10 },
  rowTop: { flexDirection: 'row', alignItems: 'center', gap: 14 },
  av: { width: 42, height: 42, borderRadius: 13, alignItems: 'center', justifyContent: 'center' },
  meta: { flex: 1, minWidth: 0 },
  name: { fontFamily: fonts.semibold, fontSize: 15, color: colors.text },
  sub: { fontFamily: fonts.regular, fontSize: 13, color: colors.text3, marginTop: 2 },
  weekly: {
    fontFamily: fonts.semibold,
    fontSize: 10,
    letterSpacing: 0.3,
    textTransform: 'uppercase',
    color: colors.accent,
    backgroundColor: colors.accentLight,
    borderRadius: 999,
    paddingVertical: 2,
    paddingHorizontal: 8,
    overflow: 'hidden',
  },
  actions: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 14 },
  action: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    paddingVertical: 8,
    paddingHorizontal: 14,
    borderRadius: 50,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.bg,
  },
  joinAction: { backgroundColor: colors.sage, borderColor: colors.sage },
  actionText: { fontFamily: fonts.medium, fontSize: 13, color: colors.accent },
  stars: { fontSize: 14, color: colors.gold, letterSpacing: 1 },
  link: { fontFamily: fonts.medium, fontSize: 14, color: colors.accent },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: 12 },
  tile: {
    width: '47.5%',
    flexGrow: 1,
    alignItems: 'center',
    padding: 16,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius.sm,
    ...shadows.soft,
  },
  tileName: { fontFamily: fonts.serifMedium, fontSize: 17, color: colors.text, marginTop: 10, textAlign: 'center' },
  tileSpec: { fontFamily: fonts.regular, fontSize: 12, lineHeight: 16, color: colors.text3, textAlign: 'center', marginTop: 2 },
  price: { fontFamily: fonts.semibold, fontSize: 13, color: colors.accent, marginTop: 6, textAlign: 'center' },
  proPrice: { fontFamily: fonts.medium, fontSize: 12, color: colors.gold, marginTop: 2, textAlign: 'center' },
  fullPrice: { fontFamily: fonts.regular, color: colors.text3, textDecorationLine: 'line-through' },
});

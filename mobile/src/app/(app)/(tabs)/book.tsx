import { cancelBooking, cancelSeries, fetchBookings, type BookingsData, type Professional, type UpcomingBooking } from '@kounselia/core';
import { router, useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { ActivityIndicator, Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button } from '@/components/Button';
import { Toast, useToast } from '@/components/chat/Toast';
import { EmptyState, SectionHead } from '@/components/dashboard/Card';
import { joinSession } from '@/components/dashboard/joinSession';
import { ProfessionalAvatar } from '@/components/dashboard/ProfessionalAvatar';
import { RateSheet } from '@/components/dashboard/RateSheet';
import { sessionWhen } from '@/components/dashboard/when';
import { TablerIcon } from '@/components/TablerIcon';
import { useLanguage, useT } from '@/language';
import { useSession } from '@/session';
import { fonts, makeStyles, radius, shadows, useColors } from '@/theme';
import { showDialog } from '@/components/Dialog';
import { ListSkeleton } from '@/components/Skeleton';

// Sessions with licensed professionals — the website dashboard's
// "Your upcoming sessions", "Past sessions" and "Find a professional".
export default function Book() {
  const styles = useStyles();
  const colors = useColors();
  const { language, t } = useLanguage();
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
    const problem = await joinSession(config, b.id, t);
    setJoining(null);
    if (problem) toast.show(problem);
  }

  function confirmCancel(b: UpcomingBooking, wholeSeries: boolean) {
    showDialog({
      title: wholeSeries ? t('m.book.cancel_weekly_q') : t('m.book.cancel_this_q'),
      message: wholeSeries
        ? t('m.book.cancel_weekly_body', { name: b.pro_name })
        : t('m.book.cancel_one_body', { name: b.pro_name, when: sessionWhen(b.start_utc, false, language) }),
      icon: 'calendar-x',
      buttons: [
        { text: t('m.book.keep'), style: 'cancel' },
        {
          text: wholeSeries ? t('m.book.cancel_weekly') : t('m.book.cancel_session'),
          style: 'destructive',
          onPress: async () => {
            const res = wholeSeries ? await cancelSeries(config, b.series_id) : await cancelBooking(config, b.id);
            toast.show(res.ok ? res.data.message || t('m.book.cancelled') : res.message);
            load();
          },
        },
      ],
    });
  }

  function moreFor(b: UpcomingBooking) {
    const buttons: { text: string; style?: 'cancel' | 'destructive'; onPress?: () => void }[] = [
      { text: t('m.book.reschedule'), onPress: () => router.push({ pathname: '/book/[proId]', params: { proId: String(b.professional_id), reschedule: String(b.id) } }) },
      { text: t('m.book.cancel_session'), style: 'destructive', onPress: () => confirmCancel(b, false) },
    ];
    if (b.series_id) buttons.push({ text: t('m.book.cancel_weekly_sessions'), style: 'destructive', onPress: () => confirmCancel(b, true) });
    buttons.push({ text: t('m.common.close'), style: 'cancel' });
    showDialog({ title: b.pro_name, message: sessionWhen(b.start_utc, false, language), icon: 'calendar-event', buttons });
  }

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.accentText} />}
      >
        <Text style={styles.title} accessibilityRole="header">
          {t('m.book.title')}
        </Text>
        {!data ? (
          failed ? (
            <View style={styles.center}>
              <Text style={styles.notice}>{t('m.sessions.load_failed')}</Text>
              <Button title={t('growth.try_again')} variant="ghost" onPress={refresh} busy={refreshing} />
            </View>
          ) : (
            <View style={{ marginTop: 16 }}>
              <ListSkeleton rows={4} square />
            </View>
          )
        ) : (
          <>
            <SectionHead title={t('m.book.upcoming')} />
            {data.upcoming.length === 0 ? (
              <EmptyState>
                <TablerIcon name="calendar-event" size={28} color={colors.text3} />
                <Text style={styles.notice}>{t('m.book.no_sessions')}</Text>
              </EmptyState>
            ) : (
              data.upcoming.map((b) => (
                <View key={b.id} style={styles.row}>
                  <View style={styles.rowTop}>
                    <View style={[styles.av, { backgroundColor: colors.goldLight }]}>
                      <TablerIcon name="calendar-event" size={18} color={colors.gold} />
                    </View>
                    <View style={styles.meta}>
                      <Text style={styles.name}>{b.pro_name}</Text>
                      <Text style={styles.sub}>{sessionWhen(b.start_utc, false, language)}</Text>
                    </View>
                    {b.series_id ? <Text style={styles.weekly}>{t('m.book.weekly')}</Text> : null}
                  </View>
                  <View style={styles.actions}>
                    {b.joinable && (
                      <Pressable onPress={() => join(b)} accessibilityRole="button" style={[styles.action, styles.joinAction]}>
                        {joining === b.id ? <ActivityIndicator size="small" color="#fff" /> : <TablerIcon name="video" size={16} color="#fff" />}
                        <Text style={[styles.actionText, { color: '#fff' }]}>{t('m.book.join')}</Text>
                      </Pressable>
                    )}
                    <Pressable
                      onPress={() => router.push({ pathname: '/booking/[id]', params: { id: String(b.id), name: b.pro_name } })}
                      accessibilityRole="button"
                      style={styles.action}
                    >
                      <TablerIcon name="message" size={16} color={colors.accentText} />
                      <Text style={styles.actionText}>{t('m.book.message')}</Text>
                    </Pressable>
                    <Pressable onPress={() => moreFor(b)} accessibilityRole="button" accessibilityLabel={t('m.book.change_a11y')} style={styles.action}>
                      <TablerIcon name="calendar-cog" size={16} color={colors.accentText} />
                      <Text style={styles.actionText}>{t('m.book.change')}</Text>
                    </Pressable>
                  </View>
                </View>
              ))
            )}

            {data.past.length > 0 && (
              <>
                <SectionHead title={t('m.book.past')} />
                {data.past.map((b) => (
                  <View key={b.id} style={[styles.row, styles.rowTop]}>
                    <View style={[styles.av, { backgroundColor: colors.accentLight }]}>
                      <TablerIcon name="check" size={18} color={colors.accentText} />
                    </View>
                    <View style={styles.meta}>
                      <Text style={styles.name}>{b.pro_name}</Text>
                      <Text style={styles.sub}>{sessionWhen(b.start_utc, true, language)}</Text>
                    </View>
                    {b.review_rating ? (
                      <Text style={styles.stars} accessibilityLabel={t('m.book.rated_a11y', { n: b.review_rating })}>
                        {'★'.repeat(b.review_rating)}
                        {'☆'.repeat(5 - b.review_rating)}
                      </Text>
                    ) : (
                      <Pressable onPress={() => setRating({ id: b.id, pro_name: b.pro_name })} accessibilityRole="button" hitSlop={6}>
                        <Text style={styles.link}>{t('m.book.rate')}</Text>
                      </Pressable>
                    )}
                  </View>
                ))}
              </>
            )}

            <SectionHead title={t('m.book.find')} note={t('m.book.verified')} />
            {data.professionals.length === 0 ? (
              <EmptyState>
                <TablerIcon name="users" size={28} color={colors.text3} />
                <Text style={styles.notice}>{t('m.book.none_available')}</Text>
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
            toast.show(t('m.book.thanks'));
            load();
          }
        }}
      />
      <Toast note={toast.note} />
    </SafeAreaView>
  );
}

function ProfessionalTile({ pro }: { pro: Professional }) {
  const styles = useStyles();
  const colors = useColors();
  const t = useT();
  return (
    <Pressable
      onPress={() => router.push({ pathname: '/book/[proId]', params: { proId: String(pro.id), pro: JSON.stringify(pro) } })}
      accessibilityRole="button"
      accessibilityLabel={[`${pro.name}, ${pro.title}.`, pro.free_label ? `${pro.free_label}.` : '', pro.price ? t('m.book.price_per_a11y', { price: pro.price }) : '', t('m.book.book_a_session')].filter(Boolean).join(' ')}
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
        {pro.review_count ? `★ ${pro.rating.toFixed(1)} (${pro.review_count})` : t('m.book.no_reviews')}
      </Text>
      {pro.free_label ? <Text style={styles.free}>{pro.free_label}</Text> : null}
      {pro.video_provider ? <Text style={styles.video}>{t('m.book.sessions_on', { provider: pro.video_provider })}</Text> : null}
      {pro.price ? <Text style={styles.price}>{t('m.book.price_per_session', { price: pro.price })}</Text> : null}
      {pro.full_price ? (
        // Pro members pay less; show what it would have been, as the website does.
        <Text style={styles.proPrice}>
          <Text style={styles.fullPrice}>{pro.full_price}</Text> {t('m.book.pro_price')}
        </Text>
      ) : null}
    </Pressable>
  );
}

const useStyles = makeStyles((colors) => ({
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
    color: colors.accentText,
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
  joinAction: { backgroundColor: colors.sageFill, borderColor: colors.sageFill },
  actionText: { fontFamily: fonts.medium, fontSize: 13, color: colors.accentText },
  stars: { fontSize: 14, color: colors.gold, letterSpacing: 1 },
  link: { fontFamily: fonts.medium, fontSize: 14, color: colors.accentText },
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
  price: { fontFamily: fonts.semibold, fontSize: 13, color: colors.accentText, marginTop: 6, textAlign: 'center' },
  free: { fontFamily: fonts.semibold, fontSize: 12.5, color: colors.sage, marginTop: 4 },
  video: { fontFamily: fonts.regular, fontSize: 12.5, color: colors.text3, marginTop: 2 },
  proPrice: { fontFamily: fonts.medium, fontSize: 12, color: colors.gold, marginTop: 2, textAlign: 'center' },
  fullPrice: { fontFamily: fonts.regular, color: colors.text3, textDecorationLine: 'line-through' },
}));

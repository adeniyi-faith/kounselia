import { router } from 'expo-router';
import { LinearGradient } from 'expo-linear-gradient';
import { useState } from 'react';
import { ActivityIndicator, Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button } from '@/components/Button';
import { Toast, useToast } from '@/components/chat/Toast';
import { SectionHead } from '@/components/dashboard/Card';
import { joinSession } from '@/components/dashboard/joinSession';
import { sessionWhen } from '@/components/dashboard/when';
import { ListSkeleton } from '@/components/Skeleton';
import { TablerIcon } from '@/components/TablerIcon';
import { NotificationBell } from '@/components/pro/NotificationsSheet';
import { Banner, StatGrid } from '@/components/pro/ui';
import { naira, usePro } from '@/professional/ProDashboard';
import { useSession } from '@/session';
import { fonts, makeStyles, radius, useColors } from '@/theme';

// The professional's Overview (the website's pro dashboard "Overview"):
// a welcome, where their application stands, their numbers, the next
// session, and what being verified unlocks.
export default function ProOverview() {
  const styles = useStyles();
  const colors = useColors();
  const { config, setViewMode } = useSession();
  const { data, failed, reload } = usePro();
  const [refreshing, setRefreshing] = useState(false);
  const [joining, setJoining] = useState(false);
  const toast = useToast();

  async function refresh() {
    setRefreshing(true);
    await reload();
    setRefreshing(false);
  }

  const app = data?.application;
  const verified = app?.status === 'verified';
  const next = data?.bookings[0];

  async function join() {
    if (!next) return;
    setJoining(true);
    const problem = await joinSession(config, next.id);
    setJoining(false);
    if (problem) toast.show(problem);
  }

  const unlocked: { icon: string; label: string; on: boolean; tag: string }[] = data
    ? [
        { icon: 'user-edit', label: 'Edit your profile and rate', on: true, tag: 'Available' },
        { icon: 'heart-handshake', label: 'Use Kounselia as a client too', on: true, tag: 'Available' },
        { icon: 'calendar-event', label: 'Receive client bookings', on: verified, tag: verified ? 'Available' : 'Locked until verified' },
        { icon: 'cash', label: 'Earnings and payouts', on: verified, tag: verified ? 'Available' : 'Locked until verified' },
        ...(data.articles.enabled
          ? [
              {
                icon: 'feather',
                label: 'Write articles for the Journal',
                on: data.articles.access.allowed,
                tag: data.articles.access.allowed ? 'Available' : verified ? 'Not available' : 'Locked until verified',
              },
            ]
          : []),
      ]
    : [];

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      <View style={styles.top}>
        <Text style={styles.brand}>Your practice</Text>
        <NotificationBell config={config} />
        <Pressable
          onPress={() => setViewMode('client')}
          accessibilityRole="button"
          accessibilityLabel="Switch to client view"
          style={styles.iconBtn}
          hitSlop={6}
        >
          <TablerIcon name="switch-horizontal" size={19} color={colors.gold} />
        </Pressable>
      </View>
      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.accentText} />}
      >
        {!data || !app ? (
          failed ? (
            <View style={styles.center}>
              <Text style={styles.notice}>We couldn’t load your practice. Please check your internet connection.</Text>
              <Button title="Try again" variant="ghost" onPress={refresh} busy={refreshing} />
            </View>
          ) : (
            <ListSkeleton rows={4} square />
          )
        ) : (
          <>
            <LinearGradient colors={[colors.accent, colors.navyFill]} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.welcome}>
              <View style={styles.eyebrowRow}>
                <Text style={styles.eyebrow}>Your practice</Text>
                {verified && (
                  <View style={styles.whitePill}>
                    <TablerIcon name="check" size={11} color="#fff" />
                    <Text style={styles.whitePillText}>Verified</Text>
                  </View>
                )}
                {data.rating.count > 0 && (
                  <View style={styles.whitePill}>
                    <Text style={styles.whitePillText}>
                      ★ {data.rating.average.toFixed(1)} ({data.rating.count})
                    </Text>
                  </View>
                )}
              </View>
              <Text style={styles.h1} accessibilityRole="header">
                Welcome back, <Text style={styles.h1Name}>{data.user.first_name}</Text>.
              </Text>
              <Text style={styles.welcomeBody}>
                {verified
                  ? 'This is what clients will see, and your rate is entirely yours to set. Kounselia never changes it for you.'
                  : 'Get your profile ready while your application is reviewed. It stays private until you’re verified.'}
              </Text>
              <Pressable onPress={() => router.push('/pro/profile')} accessibilityRole="button" style={styles.welcomeBtn}>
                <TablerIcon name="user-edit" size={16} color={colors.accent} />
                <Text style={styles.welcomeBtnText}>Edit profile and rate</Text>
              </Pressable>
            </LinearGradient>

            {app.status === 'pending' ? (
              <Banner tone="gold" title="Under review">
                We’re checking your documents. We’ll email you once there’s a decision, usually within a few business days.
              </Banner>
            ) : app.status === 'suspended' ? (
              <Banner tone="rose" title="Account suspended">
                {app.suspended_reason || 'Your account is under review.'} Clients can’t find or book you while your account is suspended. Questions?
                Email hello@kounselia.com.
              </Banner>
            ) : app.status === 'rejected' ? (
              <View>
                <Banner tone="rose" title="Not approved yet">
                  {app.rejection_reason || 'Update your documents and reapply when you’re ready.'}
                </Banner>
                <Button title="Reapply with new documents" variant="ghost" onPress={() => router.push('/apply')} style={{ marginTop: 12 }} />
              </View>
            ) : (
              <Banner tone="sage" title="Verified">
                Your profile is live for clients.
              </Banner>
            )}

            <StatGrid
              items={[
                { icon: 'cash', color: 'blue', num: app.all_free ? 'Free' : app.rate_amount ? naira(app.rate_amount) : 'Not set', label: 'Rate per session' },
                { icon: 'award', color: 'gold', num: app.years_experience ? String(app.years_experience) : '—', label: 'Years of experience' },
                { icon: 'calendar-event', color: 'sage', num: String(data.bookings.length), label: 'Upcoming bookings' },
                { icon: 'wallet', color: 'teal', num: naira(data.earnings.available), label: 'Available to pay out' },
              ]}
            />

            {next && (
              <>
                <SectionHead title="Next session" action={{ label: 'All bookings', onPress: () => router.push('/pro/bookings') }} />
                <View style={styles.next}>
                  <View style={styles.nextIcon}>
                    <TablerIcon name="calendar-event" size={18} color={colors.sage} />
                  </View>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.nextName}>{next.client_name}</Text>
                    <Text style={styles.nextWhen}>{sessionWhen(next.start_utc)}</Text>
                  </View>
                  {next.joinable && (
                    <Pressable onPress={join} accessibilityRole="button" style={styles.join}>
                      {joining ? <ActivityIndicator size="small" color="#fff" /> : <TablerIcon name="video" size={16} color="#fff" />}
                      <Text style={styles.joinText}>Join</Text>
                    </Pressable>
                  )}
                </View>
              </>
            )}

            <SectionHead title="What’s unlocked" />
            <View style={{ gap: 8 }}>
              {unlocked.map((u) => (
                <View key={u.label} style={styles.cap}>
                  <TablerIcon name={u.icon} size={16} color={u.on ? colors.sage : colors.text3} />
                  <Text style={[styles.capText, !u.on && { color: colors.text3 }]}>{u.label}</Text>
                  <Text style={[styles.capTag, { color: u.on ? colors.sage : colors.text3 }]}>{u.tag}</Text>
                </View>
              ))}
            </View>

            <Pressable onPress={() => setViewMode('client')} accessibilityRole="button" style={styles.switch}>
              <TablerIcon name="switch-horizontal" size={17} color={colors.gold} />
              <Text style={styles.switchText}>Switch to client view</Text>
            </Pressable>
          </>
        )}
      </ScrollView>
      <Toast note={toast.note} />
    </SafeAreaView>
  );
}

const useStyles = makeStyles((colors) => ({
  safe: { flex: 1, backgroundColor: colors.bg },
  top: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingHorizontal: 16, paddingTop: 8, paddingBottom: 4 },
  brand: { flex: 1, fontFamily: fonts.serifMedium, fontSize: 26, color: colors.text },
  iconBtn: {
    width: 40,
    height: 40,
    borderRadius: 12,
    backgroundColor: colors.goldLight,
    alignItems: 'center',
    justifyContent: 'center',
  },
  content: { padding: 16, paddingBottom: 40 },
  center: { marginTop: 24, gap: 16 },
  notice: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 22, color: colors.text2, textAlign: 'center' },
  welcome: { borderRadius: 28, padding: 24, overflow: 'hidden' },
  eyebrowRow: { flexDirection: 'row', alignItems: 'center', gap: 8, flexWrap: 'wrap', marginBottom: 10 },
  eyebrow: { fontFamily: fonts.semibold, fontSize: 11, letterSpacing: 2.5, textTransform: 'uppercase', color: 'rgba(255,255,255,0.6)' },
  whitePill: { flexDirection: 'row', alignItems: 'center', gap: 4, backgroundColor: 'rgba(255,255,255,0.16)', borderRadius: 50, paddingVertical: 3, paddingHorizontal: 10 },
  whitePillText: { fontFamily: fonts.medium, fontSize: 11, color: '#fff' },
  h1: { fontFamily: fonts.serif, fontSize: 28, lineHeight: 34, color: '#fff' },
  h1Name: { fontFamily: fonts.serif, fontStyle: 'italic', color: '#E8C896' },
  welcomeBody: { fontFamily: fonts.light, fontSize: 14.5, lineHeight: 22, color: 'rgba(255,255,255,0.8)', marginTop: 10 },
  welcomeBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 8,
    backgroundColor: '#fff',
    borderRadius: 50,
    paddingVertical: 13,
    marginTop: 20,
  },
  welcomeBtnText: { fontFamily: fonts.medium, fontSize: 14, color: colors.accent },
  next: { flexDirection: 'row', alignItems: 'center', gap: 14, padding: 16, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: 16 },
  nextIcon: { width: 40, height: 40, borderRadius: 12, backgroundColor: colors.sageLight, alignItems: 'center', justifyContent: 'center' },
  nextName: { fontFamily: fonts.semibold, fontSize: 15, color: colors.text },
  nextWhen: { fontFamily: fonts.regular, fontSize: 13, color: colors.text3, marginTop: 2 },
  join: { flexDirection: 'row', alignItems: 'center', gap: 6, backgroundColor: colors.sageFill, borderRadius: 50, paddingVertical: 8, paddingHorizontal: 14 },
  joinText: { fontFamily: fonts.medium, fontSize: 13, color: '#fff' },
  cap: { flexDirection: 'row', alignItems: 'center', gap: 10, padding: 14, borderRadius: 12, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  capText: { flex: 1, fontFamily: fonts.regular, fontSize: 14, color: colors.text },
  capTag: { fontFamily: fonts.semibold, fontSize: 10.5, letterSpacing: 0.3, textTransform: 'uppercase', maxWidth: 110, textAlign: 'right' },
  switch: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 10,
    marginTop: 24,
    paddingVertical: 14,
    borderRadius: radius.sm,
    backgroundColor: colors.goldLight,
  },
  switchText: { fontFamily: fonts.semibold, fontSize: 14, color: colors.gold },
}));

import { dismissCheckin, fetchHome, saveMood, type HomeData } from '@kounselia/core';
import { router, useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { ActivityIndicator, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button } from '@/components/Button';
import { SectionHead } from '@/components/dashboard/Card';
import { CareTeamCard } from '@/components/dashboard/CareTeamCard';
import { CheckInCard } from '@/components/dashboard/CheckInCard';
import { joinSession } from '@/components/dashboard/joinSession';
import { JournalCard } from '@/components/dashboard/JournalCard';
import { MoodCard } from '@/components/dashboard/MoodCard';
import { RecommendedCard } from '@/components/dashboard/RecommendedCard';
import { StatsRow } from '@/components/dashboard/StatsRow';
import { WelcomeBanner } from '@/components/dashboard/WelcomeBanner';
import { Toast, useToast } from '@/components/chat/Toast';
import { useCounselors } from '@/counselors';
import { useSession } from '@/session';
import { colors, fonts } from '@/theme';

// The website dashboard's Home, in the same order: welcome, mood,
// numbers, a suggested counselor, and today's private reflection.
export default function Home() {
  const { user, config } = useSession();
  const { bySlug } = useCounselors();
  const [home, setHome] = useState<HomeData | null>(null);
  const [failed, setFailed] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [savingMood, setSavingMood] = useState<string | null>(null);
  const [joining, setJoining] = useState(false);
  const toast = useToast();

  const load = useCallback(async () => {
    const res = await fetchHome(config);
    if (res.ok) {
      setHome(res.data);
      setFailed(false);
    } else {
      setFailed(true);
    }
  }, [config]);

  // Reload whenever Home comes back into view (e.g. after a chat).
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

  async function pickMood(key: string) {
    setSavingMood(key);
    const res = await saveMood(config, key);
    setSavingMood(null);
    if (!res.ok) {
      toast.show(res.message);
      return;
    }
    // Save first, then refresh: today's dot and the suggestion both change.
    await load();
  }

  async function laterCheckin(id: number) {
    // Hide it straight away; the server remembers the choice.
    setHome((h) => (h ? { ...h, checkin: null } : h));
    dismissCheckin(config, id);
  }

  async function join(bookingId: number) {
    setJoining(true);
    const problem = await joinSession(config, bookingId);
    setJoining(false);
    if (problem) toast.show(problem);
  }

  const firstName = user?.name?.split(' ')[0];
  const checkinCounselor = home?.checkin ? bySlug(home.checkin.counselor_slug) : undefined;
  const recommended = home ? bySlug(home.recommended.slug) : undefined;

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.accent} />}
        keyboardShouldPersistTaps="handled"
        keyboardDismissMode="interactive"
      >
        <WelcomeBanner firstName={firstName} onTalk={() => router.navigate('/talk')} onSessions={() => router.navigate('/sessions')} />

        {!home ? (
          failed ? (
            <View style={styles.center}>
              <Text style={styles.notice}>We couldn’t load your dashboard. Please check your internet connection.</Text>
              <Button title="Try again" variant="ghost" onPress={refresh} busy={refreshing} />
            </View>
          ) : (
            <ActivityIndicator color={colors.accent} style={styles.loading} />
          )
        ) : (
          <>
            <View style={styles.gap} />
            {home.checkin && checkinCounselor && (
              <CheckInCard
                checkin={home.checkin}
                counselor={checkinCounselor}
                onTell={() => {
                  const id = home.checkin!.id;
                  setHome((h) => (h ? { ...h, checkin: null } : h));
                  router.push({ pathname: '/chat/[slug]', params: { slug: checkinCounselor.slug, checkin: String(id) } });
                }}
                onLater={() => laterCheckin(home.checkin!.id)}
              />
            )}
            <CareTeamCard care={home.care} joining={joining} onJoin={join} onManage={() => router.navigate('/book')} />
            <MoodCard mood={home.mood} saving={savingMood} onPick={pickMood} />
            <StatsRow stats={home.stats} />

            {recommended && (
              <>
                <SectionHead title="Recommended for you" />
                <RecommendedCard
                  counselor={recommended}
                  reason={home.recommended.reason}
                  onStart={() => router.push({ pathname: '/chat/[slug]', params: { slug: recommended.slug } })}
                />
              </>
            )}

            <SectionHead title="Today's reflection" note="Private, never shared" />
            <JournalCard config={config} initial={home.journal} />
          </>
        )}
      </ScrollView>
      <Toast note={toast.note} />
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.bg },
  content: { padding: 16, paddingBottom: 40 },
  gap: { height: 20 },
  loading: { marginTop: 40 },
  center: { marginTop: 32, gap: 16 },
  notice: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 22, color: colors.text2, textAlign: 'center' },
});

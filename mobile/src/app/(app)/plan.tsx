// My plan: where the member stands (Free / Pro / renewing / renewal off /
// payment problem / ended), and the way to upgrade — the phone version of
// the website's "My plan" tab, minus billing history and card management,
// which stay on the website.
import { cancelSubscription, fetchAccount, fetchPlans, resumeSubscription, startSubscriptionPayment, type Account, type Plan } from '@kounselia/core';
import * as Haptics from 'expo-haptics';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useBrowser } from '@/browser/BrowserProvider';
import { Button } from '@/components/Button';
import { Toast, useToast } from '@/components/chat/Toast';
import { DetailSkeleton } from '@/components/Skeleton';
import { ScreenHeader } from '@/components/ScreenHeader';
import { TablerIcon } from '@/components/TablerIcon';
import { useSession } from '@/session';
import { fonts, makeStyles, radius, shadows, useColors } from '@/theme';

export default function MyPlan() {
  const styles = useStyles();
  const colors = useColors();
  const { config } = useSession();
  const { openInApp } = useBrowser();
  const toast = useToast();
  const [account, setAccount] = useState<Account | null>(null);
  const [plans, setPlans] = useState<Plan[] | null>(null);
  const [busyPlan, setBusyPlan] = useState<string | null>(null);
  const [busyAction, setBusyAction] = useState(false);
  const closing = useRef(false);

  const load = useCallback(async () => {
    const [accountRes, plansRes] = await Promise.all([fetchAccount(config), fetchPlans(config)]);
    if (accountRes.ok) setAccount(accountRes.data);
    if (plansRes.ok) setPlans(plansRes.data.plans);
  }, [config]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
  }, [load]);

  async function subscribe(plan: Plan) {
    setBusyPlan(plan.id);
    const res = await startSubscriptionPayment(config, plan.id);
    setBusyPlan(null);
    if (!res.ok) {
      toast.show(res.message);
      return;
    }
    closing.current = false;
    await openInApp(res.data.authorization_url, {
      title: 'Secure payment',
      closeWhen: (u) => u.includes('subscription-callback'),
    });
    // Paystack's page confirmed the payment with our server directly, so
    // by the time this browser closes the plan is already up to date.
    Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined);
    await load();
  }

  async function toggleAutoRenew(turnOn: boolean) {
    setBusyAction(true);
    const res = turnOn ? await resumeSubscription(config) : await cancelSubscription(config);
    setBusyAction(false);
    toast.show(res.ok ? res.data.message : res.message);
    if (res.ok) {
      Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined);
      await load();
    }
  }

  const plan = account?.plan;

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      <ScreenHeader title="My plan" />
      {!plan || !plans ? (
        <DetailSkeleton />
      ) : (
        <ScrollView contentContainerStyle={styles.content}>
          <View style={[styles.hero, plan.is_pro ? styles.heroPro : styles.heroFree]}>
            <View style={[styles.heroIcon, { backgroundColor: plan.is_pro ? colors.goldLight : colors.sageLight }]}>
              <TablerIcon name={plan.is_pro ? 'sparkles' : 'leaf'} size={22} color={plan.is_pro ? colors.gold : colors.sage} />
            </View>
            <Text style={styles.heroTitle}>{plan.title}</Text>
            <Text style={styles.heroDetail}>{plan.detail}</Text>
            {plan.is_pro && plan.state === 'active' ? (
              <Button
                title="Turn off auto-renew"
                variant="ghost"
                busy={busyAction}
                onPress={() => toggleAutoRenew(false)}
                style={styles.heroButton}
              />
            ) : null}
            {plan.state === 'renewal_off' ? (
              <Button
                title="Turn auto-renew back on"
                busy={busyAction}
                onPress={() => toggleAutoRenew(true)}
                style={styles.heroButton}
              />
            ) : null}
          </View>

          {!plan.is_pro || plan.state === 'ended' || plan.state === 'payment_problem' ? (
            <View style={styles.plans}>
              <Text style={styles.sectionTitle}>{plan.state === 'ended' ? 'Come back to Pro' : 'Choose a plan'}</Text>
              {plans.map((p) => (
                <View key={p.id} style={styles.planCard}>
                  {p.is_popular ? (
                    <View style={styles.badge}>
                      <Text style={styles.badgeText}>Popular</Text>
                    </View>
                  ) : null}
                  <Text style={styles.planName}>{p.name}</Text>
                  <Text style={styles.planPrice}>
                    {p.price} <Text style={styles.planInterval}>/ {p.interval}</Text>
                  </Text>
                  {p.features.map((f) => (
                    <View key={f} style={styles.featureRow}>
                      <TablerIcon name="check" size={16} color={colors.sage} />
                      <Text style={styles.featureText}>{f}</Text>
                    </View>
                  ))}
                  <Button title={`Get ${p.name}`} busy={busyPlan === p.id} onPress={() => subscribe(p)} style={styles.planButton} />
                </View>
              ))}
              <View style={styles.secureRow}>
                <TablerIcon name="lock" size={14} color={colors.text3} />
                <Text style={styles.secureText}>Your card is handled by Paystack — Kounselia never sees or stores your card number.</Text>
              </View>
            </View>
          ) : null}
        </ScrollView>
      )}
      <Toast note={toast.note} />
    </SafeAreaView>
  );
}

const useStyles = makeStyles((colors) => ({
  safe: { flex: 1, backgroundColor: colors.bg },
  content: { padding: 16, paddingBottom: 48, gap: 24 },
  hero: {
    alignItems: 'center',
    padding: 24,
    borderRadius: radius.r,
    borderWidth: 1,
    ...shadows.soft,
  },
  heroFree: { backgroundColor: colors.surface, borderColor: colors.border },
  heroPro: { backgroundColor: colors.surface, borderColor: colors.goldLight },
  heroIcon: { width: 52, height: 52, borderRadius: 26, alignItems: 'center', justifyContent: 'center', marginBottom: 12 },
  heroTitle: { fontFamily: fonts.serifMedium, fontSize: 24, color: colors.text },
  heroDetail: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 20, color: colors.text2, textAlign: 'center', marginTop: 6 },
  heroButton: { marginTop: 16, alignSelf: 'stretch' },
  plans: { gap: 14 },
  sectionTitle: { fontFamily: fonts.semibold, fontSize: 13, letterSpacing: 1, color: colors.text3, textTransform: 'uppercase' },
  planCard: {
    backgroundColor: colors.surface,
    borderRadius: radius.r,
    borderWidth: 1,
    borderColor: colors.border,
    padding: 20,
    ...shadows.soft,
  },
  badge: {
    alignSelf: 'flex-start',
    backgroundColor: colors.goldLight,
    borderRadius: radius.pill,
    paddingVertical: 3,
    paddingHorizontal: 10,
    marginBottom: 8,
  },
  badgeText: { fontFamily: fonts.semibold, fontSize: 11, color: colors.gold },
  planName: { fontFamily: fonts.serifMedium, fontSize: 20, color: colors.text },
  planPrice: { fontFamily: fonts.semibold, fontSize: 22, color: colors.text, marginTop: 4, marginBottom: 12 },
  planInterval: { fontFamily: fonts.regular, fontSize: 14, color: colors.text3 },
  featureRow: { flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 8 },
  featureText: { fontFamily: fonts.regular, fontSize: 14, color: colors.text2, flex: 1 },
  planButton: { marginTop: 8 },
  secureRow: { flexDirection: 'row', alignItems: 'center', gap: 6, paddingHorizontal: 4 },
  secureText: { fontFamily: fonts.regular, fontSize: 12, lineHeight: 17, color: colors.text3, flex: 1 },
}));

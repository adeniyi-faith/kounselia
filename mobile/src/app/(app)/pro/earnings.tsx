import { fetchPayoutBanks, requestPayout, savePayoutAccount } from '@kounselia/core';
import * as Haptics from 'expo-haptics';
import { useState } from 'react';
import { RefreshControl, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button } from '@/components/Button';
import { Toast, useToast } from '@/components/chat/Toast';
import { SectionHead } from '@/components/dashboard/Card';
import { sessionWhen } from '@/components/dashboard/when';
import { showDialog } from '@/components/Dialog';
import { FormMessage } from '@/components/FormMessage';
import { ListSkeleton } from '@/components/Skeleton';
import { TablerIcon } from '@/components/TablerIcon';
import { TextField } from '@/components/TextField';
import { ChoiceSheet, Panel, SelectField, StatGrid } from '@/components/pro/ui';
import { naira, usePro } from '@/professional/ProDashboard';
import { useSession } from '@/session';
import { fonts, makeStyles, useColors } from '@/theme';

// Earnings (the website's pro dashboard "Earnings"): what they've earned,
// the bank account payouts go to, asking for a payout, and past payouts.
export default function ProEarnings() {
  const styles = useStyles();
  const colors = useColors();
  const { config } = useSession();
  const { data, failed, reload } = usePro();
  const [refreshing, setRefreshing] = useState(false);
  const [paying, setPaying] = useState(false);
  const [payMessage, setPayMessage] = useState<{ tone: 'error' | 'success'; text: string } | null>(null);
  const toast = useToast();

  async function refresh() {
    setRefreshing(true);
    await reload();
    setRefreshing(false);
  }

  const earnings = data?.earnings;

  function askPayout() {
    if (!earnings) return;
    showDialog({
      title: `Pay out ${naira(earnings.available)}?`,
      message: earnings.account ? `It goes to ${earnings.account.bank_name} ••••${earnings.account.last4}.` : undefined,
      icon: 'cash',
      buttons: [
        { text: 'Not now', style: 'cancel' },
        {
          text: 'Request payout',
          onPress: async () => {
            setPaying(true);
            setPayMessage(null);
            const res = await requestPayout(config);
            setPaying(false);
            if (!res.ok) {
              setPayMessage({ tone: 'error', text: res.message });
              return;
            }
            Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined);
            setPayMessage({ tone: 'success', text: res.data.message || 'Payout requested.' });
            reload();
          },
        },
      ],
    });
  }

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      <ScrollView
        contentContainerStyle={styles.content}
        keyboardShouldPersistTaps="handled"
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.accentText} />}
      >
        <Text style={styles.title} accessibilityRole="header">
          Earnings
        </Text>
        <Text style={styles.sub}>Payouts and session history</Text>
        {!earnings ? (
          failed ? (
            <View style={styles.center}>
              <Text style={styles.notice}>We couldn’t load your earnings. Please check your internet connection.</Text>
              <Button title="Try again" variant="ghost" onPress={refresh} busy={refreshing} />
            </View>
          ) : (
            <View style={{ marginTop: 16 }}>
              <ListSkeleton rows={3} square />
            </View>
          )
        ) : (
          <>
            <StatGrid
              items={[
                { icon: 'wallet', color: 'sage', num: naira(earnings.available), label: 'Available to pay out' },
                { icon: 'cash', color: 'blue', num: naira(earnings.total_earned), label: 'Total earned' },
                { icon: 'check', color: 'gold', num: naira(earnings.paid_out), label: 'Paid out so far' },
              ]}
            />
            <View style={styles.note}>
              <Text style={styles.noteText}>
                Kounselia’s commission is {Number(earnings.commission_percent.toFixed(1))}% of each session. The rest is yours. A session only counts here once
                the client’s payment has gone through.
              </Text>
            </View>

            <PayoutAccount
              key={earnings.account ? earnings.account.last4 + earnings.account.bank_name : 'none'}
              account={earnings.account}
              onSaved={(message) => {
                toast.show(message);
                reload();
              }}
            />

            <Panel title="Request a payout" note="Sends your entire available balance.">
              <Button
                title={`Request payout of ${naira(earnings.available)}`}
                onPress={askPayout}
                busy={paying}
                disabled={earnings.available <= 0 || !earnings.account}
              />
              {!earnings.account ? (
                <Text style={styles.hint}>Add a payout account above first.</Text>
              ) : earnings.available <= 0 ? (
                <Text style={styles.hint}>Nothing to pay out yet. This fills up as clients pay for booked sessions.</Text>
              ) : null}
              {payMessage ? (
                <View style={{ marginTop: 14 }}>
                  <FormMessage tone={payMessage.tone} text={payMessage.text} />
                </View>
              ) : null}
            </Panel>

            {earnings.history.length > 0 && (
              <>
                <SectionHead title="Payout history" />
                {earnings.history.map((p) => (
                  <View key={p.id} style={styles.payout}>
                    <View style={styles.payoutIcon}>
                      <TablerIcon name="cash" size={18} color={colors.sage} />
                    </View>
                    <View style={{ flex: 1 }}>
                      <Text style={styles.payoutAmount}>{naira(p.amount)}</Text>
                      <Text style={styles.payoutSub}>
                        {sessionWhen(p.date_utc, true)} · {p.status_label}
                      </Text>
                      {p.failure_reason ? <Text style={styles.payoutFail}>{p.failure_reason}</Text> : null}
                    </View>
                  </View>
                ))}
              </>
            )}
          </>
        )}
      </ScrollView>
      <Toast note={toast.note} />
    </SafeAreaView>
  );
}

function PayoutAccount({
  account,
  onSaved,
}: {
  account: { account_name: string; bank_name: string; last4: string } | null;
  onSaved: (message: string) => void;
}) {
  const styles = useStyles();
  const colors = useColors();
  const { config } = useSession();
  const [banks, setBanks] = useState<{ code: string; name: string }[] | null>(null);
  const [banksFailed, setBanksFailed] = useState(false);
  const [choosing, setChoosing] = useState(false);
  const [bank, setBank] = useState<{ code: string; name: string } | null>(null);
  const [number, setNumber] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function openBanks() {
    setChoosing(true);
    if (banks) return;
    const res = await fetchPayoutBanks(config);
    if (res.ok && res.data.banks.length) setBanks(res.data.banks);
    else setBanksFailed(true);
  }

  async function save() {
    if (!bank || number.replace(/\D/g, '').length < 10) {
      setError('Please choose a bank and enter your 10-digit account number.');
      return;
    }
    setBusy(true);
    setError(null);
    const res = await savePayoutAccount(config, bank.code, bank.name, number);
    setBusy(false);
    if (!res.ok) {
      setError(res.message);
      return;
    }
    Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => undefined);
    onSaved(res.data.account_name ? `Saved: ${res.data.account_name}` : res.data.message);
  }

  return (
    <Panel title="Payout account" note="Where your money goes when you request a payout.">
      {account && (
        <View style={styles.account}>
          <TablerIcon name="building-bank" size={20} color={colors.accentText} />
          <View style={{ flex: 1 }}>
            <Text style={styles.accountName}>{account.account_name}</Text>
            <Text style={styles.accountSub}>
              {account.bank_name} ••••{account.last4}
            </Text>
          </View>
        </View>
      )}
      {account ? <Text style={[styles.hint, { marginTop: 0, marginBottom: 14 }]}>Add a different account below to replace this one.</Text> : null}
      <SelectField label="Bank" value={bank?.name ?? null} placeholder="Select your bank" onPress={openBanks} />
      <TextField label="Account number" value={number} onChangeText={(t) => setNumber(t.replace(/\D/g, '').slice(0, 10))} keyboardType="number-pad" placeholder="0123456789" maxLength={10} />
      {error ? <FormMessage tone="error" text={error} /> : null}
      <Button title="Verify and save account" onPress={save} busy={busy} />
      <ChoiceSheet
        visible={choosing && !!banks}
        title="Your bank"
        choices={(banks ?? []).map((b) => ({ value: b.code, label: b.name }))}
        selected={bank?.code ?? null}
        onPick={(code) => setBank(banks?.find((b) => b.code === code) ?? null)}
        onClose={() => setChoosing(false)}
      />
      {choosing && banksFailed ? <FormMessage tone="error" text="The bank list isn’t available right now. Please try again in a little while." /> : null}
    </Panel>
  );
}

const useStyles = makeStyles((colors) => ({
  safe: { flex: 1, backgroundColor: colors.bg },
  content: { padding: 16, paddingBottom: 40 },
  title: { fontFamily: fonts.serifMedium, fontSize: 28, color: colors.text, marginTop: 8 },
  sub: { fontFamily: fonts.regular, fontSize: 13, color: colors.text3, marginTop: 2 },
  center: { marginTop: 24, gap: 16 },
  notice: { fontFamily: fonts.regular, fontSize: 15, lineHeight: 22, color: colors.text2, textAlign: 'center' },
  note: { marginTop: 16, padding: 12, borderRadius: 10, backgroundColor: colors.surface2 },
  noteText: { fontFamily: fonts.regular, fontSize: 12.5, lineHeight: 19, color: colors.text3 },
  hint: { fontFamily: fonts.regular, fontSize: 13, lineHeight: 19, color: colors.text3, marginTop: 10 },
  account: { flexDirection: 'row', alignItems: 'center', gap: 12, padding: 14, borderRadius: 12, backgroundColor: colors.bg, borderWidth: 1, borderColor: colors.border, marginBottom: 10 },
  accountName: { fontFamily: fonts.semibold, fontSize: 14, color: colors.text },
  accountSub: { fontFamily: fonts.regular, fontSize: 12.5, color: colors.text3, marginTop: 2 },
  payout: { flexDirection: 'row', alignItems: 'center', gap: 14, padding: 16, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: 16, marginBottom: 10 },
  payoutIcon: { width: 40, height: 40, borderRadius: 12, backgroundColor: colors.sageLight, alignItems: 'center', justifyContent: 'center' },
  payoutAmount: { fontFamily: fonts.semibold, fontSize: 15, color: colors.text },
  payoutSub: { fontFamily: fonts.regular, fontSize: 13, color: colors.text2, marginTop: 2 },
  payoutFail: { fontFamily: fonts.regular, fontStyle: 'italic', fontSize: 12.5, color: colors.rose, marginTop: 4 },
}));

// "Your care team" (inc/dashboard-care-team.php): the member's next
// session with a professional up front, with Join now when it's time —
// or, with nothing booked, a few professionals' faces and an invitation.
import type { CareTeam } from '@kounselia/core';
import { LinearGradient } from 'expo-linear-gradient';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { colors, fonts, radius, shadows } from '@/theme';
import { TablerIcon } from '../TablerIcon';
import { ProfessionalAvatar } from './ProfessionalAvatar';

interface Props {
  care: CareTeam;
  joining: boolean;
  onJoin: (bookingId: number) => void;
  onManage: () => void;
}

// "Today", "Tomorrow", or the weekday — like the website.
function dayWord(d: Date): string {
  const start = new Date();
  start.setHours(0, 0, 0, 0);
  const that = new Date(d);
  that.setHours(0, 0, 0, 0);
  const days = Math.round((that.getTime() - start.getTime()) / 86400000);
  if (days === 0) return 'Today';
  if (days === 1) return 'Tomorrow';
  return d.toLocaleDateString([], { weekday: 'long' });
}

export function CareTeamCard({ care, joining, onJoin, onManage }: Props) {
  const next = care.next;
  const discount = care.discount_percent ? `${Number(care.discount_percent.toFixed(1))}%` : null;

  if (next) {
    const at = next.start_utc ? new Date(next.start_utc) : null;
    const details = [
      at ? `${dayWord(at)} · ${at.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}` : '',
      next.pro_title,
      next.more_booked > 0 ? `+${next.more_booked} more booked` : '',
    ].filter(Boolean);
    return (
      <LinearGradient colors={[colors.accentLight, colors.surface]} start={{ x: 0, y: 0 }} end={{ x: 0.9, y: 0.9 }} style={[styles.card, styles.hasNext]}>
        <View style={styles.row}>
          {at && (
            <View style={styles.date} accessible accessibilityLabel={at.toLocaleDateString([], { day: 'numeric', month: 'long' })}>
              <Text style={styles.dateM}>{at.toLocaleDateString([], { month: 'short' })}</Text>
              <Text style={styles.dateD}>{at.getDate()}</Text>
            </View>
          )}
          <View style={styles.meta}>
            <View style={styles.eyebrowRow}>
              <TablerIcon name="calendar-event" size={12} color={colors.gold} />
              <Text style={styles.eyebrow}>Your next session</Text>
            </View>
            <Text style={styles.title}>{next.pro_name}</Text>
            <Text style={styles.body}>{details.join(' · ')}</Text>
          </View>
        </View>
        <View style={styles.actions}>
          {next.joinable && (
            <Pressable onPress={() => onJoin(next.id)} accessibilityRole="button" style={[styles.btn, styles.btnFill]}>
              {joining ? <ActivityIndicator size="small" color="#fff" /> : <TablerIcon name="video" size={16} color="#fff" />}
              <Text style={[styles.btnText, { color: '#fff' }]}>Join now</Text>
            </Pressable>
          )}
          <Pressable onPress={onManage} accessibilityRole="button" style={[styles.btn, next.joinable ? styles.btnGhost : styles.btnFill]}>
            <Text style={[styles.btnText, { color: next.joinable ? colors.text2 : '#fff' }]}>Manage</Text>
          </Pressable>
        </View>
      </LinearGradient>
    );
  }

  return (
    <LinearGradient colors={[colors.surface, colors.goldLight]} start={{ x: 0, y: 0 }} end={{ x: 1.4, y: 1.4 }} style={styles.card}>
      <View style={styles.row}>
        <View style={styles.stack} accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
          {care.professionals.length > 0 ? (
            care.professionals.map((p, i) => (
              <View key={`${p.name}-${i}`} style={[styles.face, i > 0 && { marginLeft: -12 }]}>
                <ProfessionalAvatar pro={p} size={38} />
              </View>
            ))
          ) : (
            <View style={[styles.face, styles.faceIcon]}>
              <TablerIcon name="stethoscope" size={20} color={colors.gold} />
            </View>
          )}
        </View>
        <View style={styles.meta}>
          <View style={styles.eyebrowRow}>
            <TablerIcon name="user-heart" size={12} color={colors.gold} />
            <Text style={styles.eyebrow}>Your care team</Text>
          </View>
          <Text style={styles.title}>Sometimes you want a person in the room</Text>
        </View>
      </View>
      <Text style={styles.body}>
        Book a video session with a licensed, verified professional — on your schedule.
        {discount ? <Text style={styles.perk}> You save {discount} with Pro.</Text> : null}
      </Text>
      <Pressable onPress={onManage} accessibilityRole="button" style={[styles.btnWide, styles.btnFill]}>
        <Text style={[styles.btnText, { color: '#fff' }]}>{care.professionals.length ? 'Browse professionals' : 'See professionals'}</Text>
      </Pressable>
    </LinearGradient>
  );
}

const styles = StyleSheet.create({
  card: { borderRadius: radius.r, borderWidth: 1, borderColor: colors.border, padding: 18, gap: 14, marginBottom: 16, ...shadows.soft },
  hasNext: { borderColor: 'rgba(30,58,95,0.14)' },
  row: { flexDirection: 'row', alignItems: 'center', gap: 16 },
  date: {
    width: 58,
    height: 62,
    borderRadius: 16,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
  },
  dateM: { fontFamily: fonts.semibold, fontSize: 11, letterSpacing: 1, textTransform: 'uppercase', color: colors.rose },
  dateD: { fontFamily: fonts.serif, fontSize: 28, lineHeight: 30, color: colors.text },
  meta: { flex: 1, minWidth: 0 },
  eyebrowRow: { flexDirection: 'row', alignItems: 'center', gap: 5, marginBottom: 4 },
  eyebrow: { fontFamily: fonts.semibold, fontSize: 11, letterSpacing: 1.2, textTransform: 'uppercase', color: colors.gold },
  title: { fontFamily: fonts.serifMedium, fontSize: 21, lineHeight: 25, color: colors.text },
  body: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 21, color: colors.text2 },
  perk: { fontFamily: fonts.medium, color: colors.gold },
  actions: { flexDirection: 'row', gap: 8 },
  btn: { flex: 1, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, paddingVertical: 11, borderRadius: 50 },
  btnWide: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', paddingVertical: 12, borderRadius: 50 },
  btnFill: { backgroundColor: colors.accent },
  btnGhost: { borderWidth: 1.5, borderColor: colors.border },
  btnText: { fontFamily: fonts.medium, fontSize: 14 },
  stack: { flexDirection: 'row' },
  face: { borderRadius: 21, borderWidth: 2.5, borderColor: colors.surface },
  faceIcon: { width: 42, height: 42, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.goldLight },
});

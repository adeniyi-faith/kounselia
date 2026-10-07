import type { CheckIn, CounselorSummary } from '@kounselia/core';
import { Pressable, Text, View } from 'react-native';
import { useT } from '@/language';
import { fonts, makeStyles, useColors } from '@/theme';
import { CounselorAvatar } from '../CounselorAvatar';
import { Card } from './Card';

// "<Counselor> wants to check in" (the website dashboard's check-in
// card): something the member mentioned was coming up. "Tell them"
// opens the chat with the question as the counselor's opening line.
export function CheckInCard(props: { checkin: CheckIn; counselor: CounselorSummary; onTell: () => void; onLater: () => void }) {
  const styles = useStyles();
  const colors = useColors();
  const t = useT();
  const { checkin, counselor, onTell, onLater } = props;
  return (
    <Card style={styles.card}>
      <View style={styles.top}>
        <CounselorAvatar icon={counselor.icon} color={counselor.color} size={52} />
        <View style={styles.meta}>
          <Text style={styles.title}>{t('m.checkin.title', { name: counselor.name })}</Text>
          <Text style={styles.reason}>{t('m.checkin.reason', { event: checkin.event_text })}</Text>
        </View>
      </View>
      <View style={styles.actions}>
        <Pressable onPress={onTell} accessibilityRole="button" style={({ pressed }) => [styles.btn, pressed && { backgroundColor: colors.accent2 }]}>
          <Text style={styles.btnText}>{t('m.checkin.tell')}</Text>
        </Pressable>
        <Pressable onPress={onLater} accessibilityRole="button" hitSlop={8} style={styles.later}>
          <Text style={styles.laterText}>{t('m.common.not_now')}</Text>
        </Pressable>
      </View>
    </Card>
  );
}

const useStyles = makeStyles((colors) => ({
  card: { gap: 14, marginBottom: 16 },
  top: { flexDirection: 'row', alignItems: 'center', gap: 14 },
  meta: { flex: 1 },
  title: { fontFamily: fonts.serifMedium, fontSize: 21, color: colors.text },
  reason: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 20, color: colors.text2, marginTop: 4 },
  actions: { flexDirection: 'row', alignItems: 'center', gap: 16 },
  btn: { paddingVertical: 11, paddingHorizontal: 22, borderRadius: 50, backgroundColor: colors.accent },
  btnText: { fontFamily: fonts.medium, fontSize: 14, color: '#fff' },
  later: { paddingVertical: 8 },
  laterText: { fontFamily: fonts.regular, fontSize: 13, color: colors.text3 },
}));

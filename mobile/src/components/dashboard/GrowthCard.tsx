import type { GrowthPlanSummary } from '@kounselia/core';
import { Pressable, Text, View } from 'react-native';
import { fonts, makeStyles, useColors } from '@/theme';
import { TablerIcon } from '../TablerIcon';
import { Card } from './Card';

// "Grow with a 30 day plan" on Home: today's step when a plan is running,
// otherwise an invitation to start one. Opens the Growth plan screen.
export function GrowthCard({ plan, onOpen }: { plan: GrowthPlanSummary | null; onOpen: () => void }) {
  const styles = useStyles();
  const colors = useColors();
  const today = plan?.today;
  return (
    <Card style={styles.card}>
      <View style={styles.top}>
        <View style={styles.icon}>
          <TablerIcon name="plant-2" size={24} color={colors.sage} />
        </View>
        <View style={styles.meta}>
          <Text style={styles.title}>{plan ? `Day ${plan.current_day} of ${plan.total_days}: ${plan.title}` : 'Grow with a 30 day plan'}</Text>
          <Text style={styles.reason} numberOfLines={3}>
            {plan
              ? today?.done
                ? 'Today’s step is done. Nice work.'
                : (today?.task ?? '')
              : 'Pick something to work on, like discipline, habits or confidence, and get one small step a day.'}
          </Text>
        </View>
      </View>
      <Pressable onPress={onOpen} accessibilityRole="button" style={({ pressed }) => [styles.btn, pressed && { backgroundColor: colors.accent2 }]}>
        <Text style={styles.btnText}>{plan ? 'Open plan' : 'Start a plan'}</Text>
      </Pressable>
    </Card>
  );
}

const useStyles = makeStyles((colors) => ({
  card: { gap: 14, marginBottom: 16 },
  top: { flexDirection: 'row', alignItems: 'center', gap: 14 },
  icon: { width: 52, height: 52, borderRadius: 26, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.sageLight },
  meta: { flex: 1 },
  title: { fontFamily: fonts.serifMedium, fontSize: 20, color: colors.text },
  reason: { fontFamily: fonts.regular, fontSize: 14, lineHeight: 20, color: colors.text2, marginTop: 4 },
  btn: { alignSelf: 'flex-start', paddingVertical: 11, paddingHorizontal: 22, borderRadius: 50, backgroundColor: colors.accent },
  btnText: { fontFamily: fonts.medium, fontSize: 14, color: '#fff' },
}));

import { router } from 'expo-router';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button } from '@/components/Button';
import { CounselorAvatar } from '@/components/CounselorAvatar';
import { useCounselors } from '@/counselors';
import { useSession } from '@/session';
import { colors, fonts, radius, shadows } from '@/theme';

function greeting(): string {
  const hour = new Date().getHours();
  if (hour < 12) return 'Good morning';
  if (hour < 17) return 'Good afternoon';
  return 'Good evening';
}

export default function Home() {
  const { user } = useSession();
  const { counselors } = useCounselors();
  const firstName = user?.name?.split(' ')[0];

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      <ScrollView contentContainerStyle={styles.content}>
        <Text style={styles.greeting} accessibilityRole="header">
          {firstName ? `${greeting()}, ${firstName}` : greeting()}
        </Text>
        <Text style={styles.sub}>This is your space. Take whatever you need from it today.</Text>

        <View style={styles.card}>
          <Text style={styles.cardTitle}>Want to talk something through?</Text>
          <Text style={styles.cardText}>No appointments, no waiting. Pick a counselor and start whenever you're ready.</Text>
          <Button title="Talk to someone now" onPress={() => router.navigate('/talk')} />
        </View>

        {counselors.length > 0 && (
          <>
            <Text style={styles.section}>Your counselors</Text>
            <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.row}>
              {counselors.map((c) => (
                <Pressable
                  key={c.slug}
                  onPress={() => router.push({ pathname: '/chat/[slug]', params: { slug: c.slug } })}
                  accessibilityRole="button"
                  accessibilityLabel={`Talk to ${c.name}`}
                  style={({ pressed }) => [styles.chip, pressed && { transform: [{ scale: 0.96 }] }]}
                >
                  <CounselorAvatar icon={c.icon} color={c.color} size={40} />
                  <Text style={styles.chipName} numberOfLines={1}>
                    {c.name}
                  </Text>
                </Pressable>
              ))}
            </ScrollView>
          </>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.bg },
  content: { padding: 20, paddingBottom: 32 },
  greeting: { fontFamily: fonts.serif, fontSize: 34, color: colors.text, marginTop: 12 },
  sub: { fontFamily: fonts.light, fontSize: 15, lineHeight: 22, color: colors.text2, marginTop: 6, marginBottom: 24 },
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.r,
    borderWidth: 1,
    borderColor: colors.border,
    padding: 20,
    gap: 8,
    ...shadows.card,
  },
  cardTitle: { fontFamily: fonts.serifMedium, fontSize: 22, color: colors.text },
  cardText: { fontFamily: fonts.light, fontSize: 15, lineHeight: 22, color: colors.text2, marginBottom: 10 },
  section: { fontFamily: fonts.serifMedium, fontSize: 22, color: colors.text, marginTop: 32, marginBottom: 14 },
  row: { gap: 12, paddingRight: 20 },
  chip: {
    width: 84,
    alignItems: 'center',
    gap: 8,
    paddingVertical: 14,
    backgroundColor: colors.surface,
    borderRadius: radius.sm,
    borderWidth: 1,
    borderColor: colors.border,
  },
  chipName: { fontFamily: fonts.medium, fontSize: 13, color: colors.text, maxWidth: 72 },
});

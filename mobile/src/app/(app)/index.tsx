import { StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button } from '@/components/Button';
import { useSession } from '@/session';
import { colors, fonts, radius, shadows } from '@/theme';

// First signed-in screen. For now it confirms who is signed in; the
// counselor list and chat come next.
export default function Home() {
  const { user, signOut } = useSession();
  const firstName = user?.name?.split(' ')[0];

  return (
    <SafeAreaView style={styles.safe}>
      <Text style={styles.greeting} accessibilityRole="header">
        {firstName ? `Welcome, ${firstName}` : 'Welcome'}
      </Text>
      <View style={styles.card}>
        <Text style={styles.cardText}>You're signed in{user?.email ? ` as ${user.email}` : ''}.</Text>
        <Text style={styles.cardSub}>Your counselors and conversations will appear here.</Text>
      </View>
      <Button title="Sign out" variant="ghost" onPress={signOut} style={styles.signOut} />
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.bg, padding: 24 },
  greeting: { fontFamily: fonts.serif, fontSize: 34, color: colors.text, marginTop: 24, marginBottom: 20 },
  card: { backgroundColor: colors.surface, borderRadius: radius.r, padding: 20, borderWidth: 1, borderColor: colors.border, ...shadows.card },
  cardText: { fontFamily: fonts.medium, fontSize: 16, color: colors.text, marginBottom: 6 },
  cardSub: { fontFamily: fonts.light, fontSize: 15, lineHeight: 22, color: colors.text2 },
  signOut: { marginTop: 'auto' },
});

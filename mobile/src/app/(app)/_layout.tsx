import { Stack } from 'expo-router';
import { colors } from '@/theme';

// Signed-in screens. This becomes a bottom tab bar (Talk, Journal, Mood,
// Sessions, Account) as those screens are added.
export default function AppLayout() {
  return <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: colors.bg } }} />;
}
